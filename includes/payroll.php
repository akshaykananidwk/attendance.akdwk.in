<?php
/**
 * Payroll engine — ONE place for every salary number (salary slip, salary report,
 * staff dashboard, opening balance). Before this, each page had its own formula and
 * they disagreed.
 *
 * Rules (months >= settings.payroll_v2_from, "v2"):
 *  - Rate: custom month rate (staff_monthly_rates) → else base + (rate_increment × months since joining).
 *    rate_increment is 0 by default — no hidden ₹1/month increase any more.
 *  - Monthly salary: rate ÷ days-in-month × (present days + paid weekly-off days), max = full rate.
 *    Weekly-off days (users.weekly_off, e.g. Sunday) are paid for monthly staff from joining date up to today.
 *    A weekly off is paid only when the staff worked at least one day within 6 days before/after it
 *    (so a whole month absent does not earn paid Sundays).
 *  - Hourly salary: worked minutes ÷ 60 × rate (weekly off = unpaid, but never a penalty).
 *  - Penalty day value: monthly = rate ÷ days-in-month; hourly = rate × settings.hourly_day_hours.
 *  - Penalties are part of the month's earnings, so they also stay deducted in later opening balances.
 *  - "[Invalid:" marked rows are never paid (same rule everywhere).
 *
 * Months before payroll_v2_from use the exact legacy formula so already-settled balances do not move.
 */

const PAYROLL_WEEKDAYS = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
const ATT_MODE_SELF = 'self';
const ATT_MODE_AUTO = 'auto';
const ATT_MODE_MANUAL = 'manual';

function payroll_settings(mysqli $conn): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $s = ['payroll_v2_from' => '9999-12', 'penalty_absent_days' => 2.0, 'penalty_late_days' => 0.5, 'hourly_day_hours' => 8.0, 'late_grace_minutes' => 60];
    $r = @$conn->query('SELECT * FROM settings LIMIT 1');
    $row = $r ? $r->fetch_assoc() : null;
    if ($row) {
        foreach ($s as $k => $def) {
            if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
                $s[$k] = is_float($def) ? (float) $row[$k] : (is_int($def) ? (int) $row[$k] : (string) $row[$k]);
            }
        }
    }

    return $cache = $s;
}

function payroll_reset_cache(): void
{
    // used by tests / settings page after saving
    $GLOBALS['__payroll_users'] = [];
}

function payroll_user(mysqli $conn, int $userId): ?array
{
    $GLOBALS['__payroll_users'] ??= [];
    if (isset($GLOBALS['__payroll_users'][$userId])) {
        return $GLOBALS['__payroll_users'][$userId];
    }
    $stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($u) {
        $u['attendance_mode'] = $u['attendance_mode'] ?? ATT_MODE_SELF;
        $u['weekly_off'] = (string) ($u['weekly_off'] ?? '');
        $u['rate_increment'] = (float) ($u['rate_increment'] ?? 0);
    }

    return $GLOBALS['__payroll_users'][$userId] = $u;
}

/** @return int[] weekday numbers (0 = Sunday) */
function payroll_weekly_off_days(array $user): array
{
    $out = [];
    foreach (explode(',', (string) ($user['weekly_off'] ?? '')) as $d) {
        $d = trim($d);
        if ($d !== '' && ctype_digit($d) && (int) $d <= 6) {
            $out[] = (int) $d;
        }
    }

    return array_values(array_unique($out));
}

function payroll_is_weekly_off(array $user, string $date): bool
{
    return in_array((int) date('w', strtotime($date)), payroll_weekly_off_days($user), true);
}

function payroll_weekly_off_label(array $user): string
{
    $days = payroll_weekly_off_days($user);

    return $days ? implode(', ', array_map(static fn ($d) => PAYROLL_WEEKDAYS[$d], $days)) : 'No weekly off';
}

/** Present (paid attendance) on any day within ±6 days of $date? */
function payroll_worked_near(mysqli $conn, int $userId, string $date): bool
{
    $from = date('Y-m-d', strtotime($date . ' -6 days'));
    $to = date('Y-m-d', strtotime($date . ' +6 days'));
    $invalid = '%[Invalid:%';
    $stmt = $conn->prepare("SELECT 1 FROM attendance_logs WHERE user_id = ? AND log_date BETWEEN ? AND ? AND log_date <> ?
        AND punch_in IS NOT NULL AND COALESCE(is_leave,0) < 1 AND (leave_reason IS NULL OR leave_reason NOT LIKE ?) LIMIT 1");
    $stmt->bind_param('issss', $userId, $from, $to, $date, $invalid);
    $stmt->execute();
    $yes = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $yes;
}

/** Rate used for a month + where it came from. */
function payroll_rate(mysqli $conn, array $user, int $year, int $month, bool $legacy): array
{
    $ym = sprintf('%04d-%02d', $year, $month);
    $stmt = $conn->prepare('SELECT custom_hourly_rate FROM staff_monthly_rates WHERE user_id = ? AND month_year = ? LIMIT 1');
    $uid = (int) $user['id'];
    $stmt->bind_param('is', $uid, $ym);
    $stmt->execute();
    $c = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($c && (float) $c['custom_hourly_rate'] > 0) {
        return [(float) $c['custom_hourly_rate'], 'Custom rate for this month'];
    }
    $base = (float) $user['salary_amount'];
    $join = strtotime(!empty($user['join_date']) ? $user['join_date'] : '2026-01-01');
    $mDiff = max(0, (($year - (int) date('Y', $join)) * 12) + ($month - (int) date('n', $join)));
    $inc = $legacy ? 1.0 : (float) $user['rate_increment'];
    if ($inc > 0 && $mDiff > 0) {
        return [$base + $inc * $mDiff, 'Base ₹' . rtrim(rtrim(number_format($base, 2, '.', ''), '0'), '.') . ' + ₹' . rtrim(rtrim(number_format($inc, 2, '.', ''), '0'), '.') . ' × ' . $mDiff . ' month(s) increment'];
    }

    return [$base, 'Base rate'];
}

/**
 * Full salary calculation of one staff member for one month.
 * @return array<string,mixed>
 */
function payroll_month(mysqli $conn, int $userId, int $year, int $month): array
{
    $user = payroll_user($conn, $userId);
    if (!$user) {
        return [];
    }
    $set = payroll_settings($conn);
    $ym = sprintf('%04d-%02d', $year, $month);
    $legacy = $ym < (string) ($GLOBALS['__payroll_force_v2_from'] ?? $set['payroll_v2_from']);
    $start = "$ym-01";
    $end = date('Y-m-t', strtotime($start));
    $dim = (int) date('t', strtotime($start));
    [$rate, $rateSource] = payroll_rate($conn, $user, $year, $month, $legacy);

    $invalid = '%[Invalid:%';
    $stmt = $conn->prepare(
        "SELECT
            SUM(CASE WHEN punch_in IS NOT NULL AND COALESCE(is_leave,0) < 1 AND (leave_reason IS NULL OR leave_reason NOT LIKE ?) THEN COALESCE(total_worked_minutes,0) ELSE 0 END) AS mins,
            COUNT(DISTINCT CASE WHEN punch_in IS NOT NULL AND COALESCE(is_leave,0) < 1 AND (leave_reason IS NULL OR leave_reason NOT LIKE ?) THEN log_date END) AS days,
            COUNT(CASE WHEN is_leave >= 1 THEN 1 END) AS leaves,
            GROUP_CONCAT(DISTINCT CASE WHEN punch_in IS NOT NULL AND COALESCE(is_leave,0) < 1 AND (leave_reason IS NULL OR leave_reason NOT LIKE ?) THEN log_date END) AS present_dates
         FROM attendance_logs WHERE user_id = ? AND log_date BETWEEN ? AND ?"
    );
    $stmt->bind_param('sssiss', $invalid, $invalid, $invalid, $userId, $start, $end);
    $stmt->execute();
    $a = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $mins = (int) ($a['mins'] ?? 0);
    $present = (int) ($a['days'] ?? 0);
    $presentDates = array_flip(array_filter(explode(',', (string) ($a['present_dates'] ?? ''))));

    // Paid weekly-off days (v2, monthly staff only)
    $paidOff = 0;
    $offDates = [];
    if (!$legacy && payroll_weekly_off_days($user)) {
        $from = max($start, !empty($user['join_date']) ? $user['join_date'] : $start);
        $to = min($end, date('Y-m-d'));
        if (!empty($user['archived_at'])) {
            $to = min($to, date('Y-m-d', strtotime($user['archived_at'])));
        }
        for ($d = strtotime($from); $from <= $to && $d <= strtotime($to); $d = strtotime('+1 day', $d)) {
            $ds = date('Y-m-d', $d);
            if (payroll_is_weekly_off($user, $ds) && !isset($presentDates[$ds]) && payroll_worked_near($conn, $userId, $ds)) {
                $offDates[] = $ds;
            }
        }
        if ($user['salary_type'] !== 'hourly') {
            $paidOff = count($offDates);
        }
    }

    if ($user['salary_type'] === 'hourly') {
        $gross = ($mins / 60) * $rate;
        $dayValue = $rate * ($legacy ? 8.0 : (float) $set['hourly_day_hours']);
    } elseif ($legacy) {
        $gross = ($rate / 30) * $present;
        $dayValue = $rate / 30;
    } else {
        $gross = min($rate, ($rate / $dim) * ($present + $paidOff));
        $dayValue = $rate / $dim;
    }

    $penaltyDays = salary_penalty_day_deductions($conn, $userId, sprintf('%02d', $month), (string) $year);
    $withhold = salary_resignation_withhold($conn, $userId, sprintf('%02d', $month), (string) $year);
    $penaltyAmount = $withhold ? $gross : min($gross, $penaltyDays * $dayValue);

    return [
        'user' => $user,
        'month' => $ym,
        'rules' => $legacy ? 'legacy' : 'v2',
        'rate' => round($rate, 2),
        'rate_source' => $rateSource,
        'salary_type' => $user['salary_type'],
        'minutes' => $mins,
        'hours' => round($mins / 60, 2),
        'days_present' => $present,
        'weekly_off_days' => count($offDates),
        'paid_off_days' => $paidOff,
        'paid_days' => $present + $paidOff,
        'days_in_month' => $dim,
        'leave_days' => (int) ($a['leaves'] ?? 0),
        'gross' => round($gross, 2),
        'day_value' => round($dayValue, 2),
        'penalty_days' => $penaltyDays,
        'penalty_amount' => round($penaltyAmount, 2),
        'resignation_withhold' => $withhold,
        // Legacy opening balances never deducted penalties — kept identical for old months.
        'earned' => round($gross - ($legacy ? 0.0 : $penaltyAmount), 2),
        'earned_display' => round($gross - $penaltyAmount, 2),
    ];
}

/** Upad / Jama totals between two dates (inclusive). */
function payroll_advances(mysqli $conn, int $userId, ?string $from, ?string $to): array
{
    $sql = "SELECT COALESCE(SUM(CASE WHEN transaction_type='Upad' THEN amount END),0) AS upad,
                   COALESCE(SUM(CASE WHEN transaction_type='Jama' THEN amount END),0) AS jama
            FROM advance_payments WHERE user_id = ?";
    $types = 'i';
    $args = [$userId];
    if ($from !== null) {
        $sql .= ' AND date >= ?';
        $types .= 's';
        $args[] = $from;
    }
    if ($to !== null) {
        $sql .= ' AND date <= ?';
        $types .= 's';
        $args[] = $to;
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ['upad' => (float) $r['upad'], 'jama' => (float) $r['jama']];
}

/** Balance carried into the given month: all earnings of earlier months + Jama − Upad before the month. */
function payroll_opening_balance(mysqli $conn, int $userId, int $year, int $month): float
{
    $user = payroll_user($conn, $userId);
    if (!$user) {
        return 0.0;
    }
    $endPrev = date('Y-m-d', strtotime(sprintf('%04d-%02d-01', $year, $month) . ' -1 day'));
    $start = !empty($user['join_date']) ? $user['join_date'] : '2026-01-01';
    foreach (['SELECT MIN(log_date) FROM attendance_logs WHERE user_id = ?', 'SELECT MIN(date) FROM advance_payments WHERE user_id = ?'] as $q) {
        $stmt = $conn->prepare($q);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $min = $stmt->get_result()->fetch_row()[0] ?? null;
        $stmt->close();
        if ($min && $min < $start) {
            $start = $min;
        }
    }
    $earned = 0.0;
    $cur = strtotime(date('Y-m-01', strtotime($start)));
    $last = strtotime(date('Y-m-01', strtotime($endPrev)));
    while ($cur <= $last && strtotime($start) <= strtotime($endPrev)) {
        $p = payroll_month($conn, $userId, (int) date('Y', $cur), (int) date('n', $cur));
        $earned += (float) ($p['earned'] ?? 0);
        $cur = strtotime('+1 month', $cur);
    }
    $adv = payroll_advances($conn, $userId, null, $endPrev);

    return round($earned + $adv['jama'] - $adv['upad'], 2);
}

/**
 * Everything a salary slip / report row needs.
 * Net payable = opening balance + this month's earnings (after penalty) + Jama − Upad of this month and later.
 */
function payroll_slip(mysqli $conn, int $userId, int $year, int $month): array
{
    $p = payroll_month($conn, $userId, $year, $month);
    if (!$p) {
        return [];
    }
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    $cur = payroll_advances($conn, $userId, $start, $end);
    $later = payroll_advances($conn, $userId, date('Y-m-d', strtotime($end . ' +1 day')), null);
    $opening = payroll_opening_balance($conn, $userId, $year, $month);
    $p['opening_balance'] = $opening;
    $p['month_upad'] = $cur['upad'];
    $p['month_jama'] = $cur['jama'];
    $p['subsequent_upad'] = $later['upad'];
    $p['subsequent_jama'] = $later['jama'];
    $p['net'] = round($opening + $p['earned_display'] + $cur['jama'] - $cur['upad'] + $later['jama'] - $later['upad'], 2);

    return $p;
}
