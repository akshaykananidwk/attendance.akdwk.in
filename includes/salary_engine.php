<?php

declare(strict_types=1);

/**
 * Shared opening balance + payroll adjustments (penalties, policy deductions, resignation).
 */

/** Opening balance — delegates to the single payroll engine (includes/payroll.php). */
function calculateOpeningBalance(mysqli $conn, int $user_id, string $target_month, string $target_year): float
{
    return payroll_opening_balance($conn, $user_id, (int) $target_year, (int) $target_month);
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
