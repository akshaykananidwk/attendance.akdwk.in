<?php

declare(strict_types=1);

/**
 * Shared opening balance + payroll adjustments (penalties, policy deductions, resignation).
 */

function calculateOpeningBalance(mysqli $conn, int $user_id, string $target_month, string $target_year): float
{

    $stmt = $conn->prepare('SELECT join_date, salary_amount, salary_type FROM users WHERE id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) {
        return 0.0;
    }

    $end_date = date('Y-m-d', strtotime("$target_year-$target_month-01 -1 day"));

    $stmt1 = $conn->prepare('SELECT MIN(log_date) AS min_date FROM attendance_logs WHERE user_id = ?');
    $stmt1->bind_param('i', $user_id);
    $stmt1->execute();
    $min_att = $stmt1->get_result()->fetch_assoc()['min_date'] ?? null;
    $stmt1->close();

    $stmt2 = $conn->prepare('SELECT MIN(date) AS min_date FROM advance_payments WHERE user_id = ?');
    $stmt2->bind_param('i', $user_id);
    $stmt2->execute();
    $min_adv = $stmt2->get_result()->fetch_assoc()['min_date'] ?? null;
    $stmt2->close();

    $start_date = !empty($user['join_date']) ? $user['join_date'] : '2026-01-01';
    if ($min_att && strtotime($min_att) < strtotime($start_date)) {
        $start_date = $min_att;
    }
    if ($min_adv && strtotime($min_adv) < strtotime($start_date)) {
        $start_date = $min_adv;
    }

    $total_earned = 0.0;
    if (strtotime($start_date) <= strtotime($end_date)) {
        $curr = strtotime(date('Y-m-01', strtotime($start_date)));
        $last = strtotime(date('Y-m-01', strtotime($end_date)));

        while ($curr <= $last) {
            $m = date('m', $curr);
            $y = date('Y', $curr);
            $my = "$y-$m";

            $stmt = $conn->prepare('SELECT custom_hourly_rate FROM staff_monthly_rates WHERE user_id = ? AND month_year = ?');
            $stmt->bind_param('is', $user_id, $my);
            $stmt->execute();
            $custom = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($custom) {
                $current_rate = (float) $custom['custom_hourly_rate'];
            } else {
                $base_rate = (float) $user['salary_amount'];
                $j_date = strtotime(!empty($user['join_date']) ? $user['join_date'] : '2026-01-01');
                $m_diff = (($y - (int) date('Y', $j_date)) * 12) + ((int) $m - (int) date('m', $j_date));
                $current_rate = $base_rate + ($m_diff > 0 ? $m_diff : 0);
            }

            $monthStart = "$y-$m-01";
            $monthEnd = date('Y-m-t', strtotime($monthStart));
            $invalidMark = '%[Invalid:%';
            $stmt = $conn->prepare(
                'SELECT
                    SUM(CASE WHEN punch_in IS NOT NULL AND COALESCE(is_leave, 0) < 1
                        AND (leave_reason IS NULL OR leave_reason NOT LIKE ?)
                        THEN COALESCE(total_worked_minutes, 0) ELSE 0 END) AS mins,
                    COUNT(DISTINCT CASE WHEN punch_in IS NOT NULL AND COALESCE(is_leave, 0) < 1
                        AND (leave_reason IS NULL OR leave_reason NOT LIKE ?)
                        THEN log_date END) AS days
                 FROM attendance_logs WHERE user_id = ? AND log_date >= ? AND log_date <= ?'
            );
            $stmt->bind_param('ssiss', $invalidMark, $invalidMark, $user_id, $monthStart, $monthEnd);
            $stmt->execute();
            $log = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $mins = (int) ($log['mins'] ?? 0);
            $p_days = (int) ($log['days'] ?? 0);

            if ($user['salary_type'] === 'hourly') {
                $total_earned += ($mins / 60) * $current_rate;
            } else {
                $total_earned += ($current_rate / 30) * $p_days;
            }

            $curr = strtotime('+1 month', $curr);
        }
    }

    $stmt = $conn->prepare(
        "SELECT SUM(CASE WHEN transaction_type = 'Jama' THEN amount ELSE -amount END) AS adv_bal
         FROM advance_payments WHERE user_id = ? AND date <= ?"
    );
    $stmt->bind_param('is', $user_id, $end_date);
    $stmt->execute();
    $adv = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $total_adv_bal = (float) ($adv['adv_bal'] ?? 0);

    $balance = $total_earned + $total_adv_bal;

    return $balance;
}

/** Penalty day-equivalents for a calendar month (admin penalties + auto leave policy). */
function salary_penalty_day_deductions(mysqli $conn, int $userId, string $month, string $year): float
{
    $month = str_pad($month, 2, '0', STR_PAD_LEFT);
    $start = "$year-$month-01";
    $end = date('Y-m-t', strtotime($start));
    $days = 0.0;

    if (salary_table_exists($conn, 'staff_penalties')) {
        $stmt = $conn->prepare(
            'SELECT COALESCE(SUM(deduction_days), 0) AS d FROM staff_penalties
             WHERE user_id = ? AND penalty_date >= ? AND penalty_date <= ?'
        );
        $stmt->bind_param('iss', $userId, $start, $end);
        $stmt->execute();
        $days += (float) ($stmt->get_result()->fetch_assoc()['d'] ?? 0);
        $stmt->close();
    }

    if (salary_table_exists($conn, 'leave_requests')) {
        $stmt = $conn->prepare(
            'SELECT COALESCE(SUM(double_deduction_days), 0) AS d FROM leave_requests
             WHERE user_id = ? AND start_date >= ? AND end_date <= ?
               AND policy_violation = 1 AND missing_medical_cert = 1'
        );
        $stmt->bind_param('iss', $userId, $start, $end);
        $stmt->execute();
        $days += (float) ($stmt->get_result()->fetch_assoc()['d'] ?? 0);
        $stmt->close();
    }

    return $days;
}

/** Active resignation without notice → withhold last month (Rule 13). */
function salary_resignation_withhold(mysqli $conn, int $userId, string $month, string $year): bool
{
    if (!salary_table_exists($conn, 'resignation_requests')) {
        return false;
    }
    $monthStart = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . '-01';
    $stmt = $conn->prepare(
        "SELECT id FROM resignation_requests
         WHERE user_id = ? AND without_notice = 1 AND status IN ('approved','completed')
           AND DATE_FORMAT(last_working_day, '%Y-%m') = DATE_FORMAT(?, '%Y-%m')
         LIMIT 1"
    );
    $stmt->bind_param('is', $userId, $monthStart);
    $stmt->execute();
    $hit = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $hit;
}

function salary_table_exists(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $t = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '$t'");

    return $cache[$table] = ($r && $r->num_rows > 0);
}

/**
 * Apply penalty days to gross (daily rate = monthly rate / 30 for monthly staff).
 */
function salary_apply_penalties(
    mysqli $conn,
    int $userId,
    float $gross,
    string $salaryType,
    float $rate,
    string $month,
    string $year
): float {
    if (salary_resignation_withhold($conn, $userId, $month, $year)) {
        return 0.0;
    }
    $penaltyDays = salary_penalty_day_deductions($conn, $userId, $month, $year);
    if ($penaltyDays <= 0) {
        return $gross;
    }
    $daily = $salaryType === 'hourly' ? ($rate * 8) : ($rate / 30);

    return max(0.0, $gross - ($penaltyDays * $daily));
}
