<?php

declare(strict_types=1);

/** Whether a column exists on a table (cached per request). */
function db_column_exists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $r = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");

    return $cache[$key] = ($r && $r->num_rows > 0);
}

/**
 * Office start time for "late" (HH:MM:SS). Default 09:30 until settings column exists.
 */
function attendance_office_start_time(mysqli $conn): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = '09:30:00';
    if (db_column_exists($conn, 'settings', 'office_start_time')) {
        $r = $conn->query('SELECT office_start_time FROM settings LIMIT 1');
        if ($r && ($row = $r->fetch_assoc()) && !empty($row['office_start_time'])) {
            $t = trim((string) $row['office_start_time']);
            if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $t)) {
                $cached = strlen($t) === 5 ? $t . ':00' : $t;
            }
        }
    }

    return $cached;
}

/**
 * Today's attendance breakdown for admin dashboard (per staff user, company-scoped).
 *
 * @return array{
 *   total_staff:int,present:int,on_leave:int,half_day:int,late:int,absent:int
 * }
 */
function get_today_attendance_stats(mysqli $conn, int $company_id, ?string $today = null): array
{
    $today = $today ?? date('Y-m-d');
    $officeStart = attendance_office_start_time($conn);

    $sql = "
        SELECT
            COUNT(DISTINCT u.id) AS total_staff,
            COUNT(DISTINCT CASE
                WHEN a.id IS NOT NULL AND COALESCE(a.is_leave, 0) < 1
                     AND a.punch_in IS NOT NULL AND a.punch_in != ''
                THEN u.id END) AS present,
            COUNT(DISTINCT CASE
                WHEN a.id IS NOT NULL AND COALESCE(a.is_leave, 0) >= 1
                     AND (a.leave_reason IS NULL OR a.leave_reason NOT LIKE '[Half Day]%')
                THEN u.id END) AS on_leave,
            COUNT(DISTINCT CASE
                WHEN a.id IS NOT NULL AND COALESCE(a.is_leave, 0) >= 1
                     AND a.leave_reason LIKE '[Half Day]%'
                THEN u.id END) AS half_day,
            COUNT(DISTINCT CASE
                WHEN a.id IS NOT NULL AND COALESCE(a.is_leave, 0) < 1
                     AND a.punch_in IS NOT NULL AND a.punch_in != ''
                     AND a.punch_in > ?
                THEN u.id END) AS late
        FROM users u
        LEFT JOIN attendance_logs a ON a.user_id = u.id AND a.log_date = ?
        WHERE u.role = 'staff' AND u.company_id = ?
        " . staff_sql_active_filter('u') . '
    ';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssi', $officeStart, $today, $company_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $total = (int) ($row['total_staff'] ?? 0);
    $present = (int) ($row['present'] ?? 0);
    $onLeave = (int) ($row['on_leave'] ?? 0);
    $halfDay = (int) ($row['half_day'] ?? 0);
    $late = (int) ($row['late'] ?? 0);

    // Absent = no punch today and not marked on full leave
    $absent = max(0, $total - $present - $onLeave - $halfDay);

    return [
        'total_staff' => $total,
        'present' => $present,
        'on_leave' => $onLeave,
        'half_day' => $halfDay,
        'late' => $late,
        'absent' => $absent,
    ];
}

/** Resolve company_id / branch_id for attendance row from user id. */
function attendance_user_tenant(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare('SELECT company_id, branch_id FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'company_id' => (int) ($u['company_id'] ?? 0),
        'branch_id' => (int) ($u['branch_id'] ?? 0),
    ];
}

/**
 * Staff must complete all assigned non-completed tasks before punch-out counts.
 */
function staff_has_incomplete_tasks(mysqli $conn, int $userId, int $companyId): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM tasks t
         INNER JOIN task_assignments ta ON ta.task_id = t.id
         WHERE ta.user_id = ? AND t.company_id = ? AND t.status != 'Completed'"
    );
    $stmt->bind_param('ii', $userId, $companyId);
    $stmt->execute();
    $c = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    return $c > 0;
}

/**
 * Mark today's attendance invalid when punching out with open tasks (Rule 7).
 */
function attendance_mark_invalid_if_open_tasks(mysqli $conn, int $userId, string $today): void
{
    $tenant = attendance_user_tenant($conn, $userId);
    if ($tenant['company_id'] <= 0 || !staff_has_incomplete_tasks($conn, $userId, $tenant['company_id'])) {
        return;
    }
    $stmt = $conn->prepare(
        "UPDATE attendance_logs SET leave_reason = CONCAT(COALESCE(leave_reason, ''), ' [Invalid: incomplete tasks]')
         WHERE user_id = ? AND log_date = ? LIMIT 1"
    );
    $stmt->bind_param('is', $userId, $today);
    $stmt->execute();
    $stmt->close();
}
