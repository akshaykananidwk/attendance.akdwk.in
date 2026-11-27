<?php

declare(strict_types=1);

/**
 * Automatic attendance / absence / penalty workflows (no manual admin step).
 */

const ATTENDANCE_NOON_DEADLINE = '12:00:00';
const ATTENDANCE_AUTO_ABSENCE_PENALTY_DAYS = 2.0;

function attendance_automation_ensure_tables(mysqli $conn): void
{
    $conn->query(
        'CREATE TABLE IF NOT EXISTS automation_runs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            run_key VARCHAR(64) NOT NULL,
            run_date DATE NOT NULL,
            company_id INT NOT NULL DEFAULT 0,
            executed_at DATETIME NOT NULL,
            details TEXT NULL,
            UNIQUE KEY uq_run (run_key, run_date, company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    
    if (!db_column_exists($conn, 'users', 'shift_start_time')) {
        @$conn->query("ALTER TABLE `users` ADD `shift_start_time` TIME NOT NULL DEFAULT '09:30:00'");
    }
    if (salary_table_exists($conn, 'leave_requests') && !db_column_exists($conn, 'leave_requests', 'status')) {
        @$conn->query("ALTER TABLE `leave_requests` ADD `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending'");
    }
}

function attendance_automation_already_ran(mysqli $conn, string $runKey, string $date, int $companyId): bool
{
    attendance_automation_ensure_tables($conn);
    $stmt = $conn->prepare(
        'SELECT id FROM automation_runs WHERE run_key = ? AND run_date = ? AND company_id = ? LIMIT 1'
    );
    $stmt->bind_param('ssi', $runKey, $date, $companyId);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $ok;
}

function attendance_automation_mark_ran(mysqli $conn, string $runKey, string $date, int $companyId, string $details = ''): void
{
    attendance_automation_ensure_tables($conn);
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        'INSERT INTO automation_runs (run_key, run_date, company_id, executed_at, details) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE executed_at = VALUES(executed_at), details = VALUES(details)'
    );
    $stmt->bind_param('ssiss', $runKey, $date, $companyId, $now, $details);
    $stmt->execute();
    $stmt->close();
}

/** Staff on approved leave for date. */
function attendance_staff_on_leave(mysqli $conn, int $userId, string $date): bool
{
    $stmt = $conn->prepare(
        'SELECT id FROM attendance_logs WHERE user_id = ? AND log_date = ? AND COALESCE(is_leave, 0) >= 1 LIMIT 1'
    );
    $stmt->bind_param('is', $userId, $date);
    $stmt->execute();
    $yes = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $yes;
}

/** Punched in before noon deadline. */
function attendance_staff_punched_before_noon(mysqli $conn, int $userId, string $date): bool
{
    $stmt = $conn->prepare(
        'SELECT punch_in FROM attendance_logs WHERE user_id = ? AND log_date = ? AND punch_in IS NOT NULL AND punch_in != \'\' LIMIT 1'
    );
    $stmt->bind_param('is', $userId, $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || empty($row['punch_in'])) {
        return false;
    }
    $punch = (string) $row['punch_in'];
    if (strlen($punch) === 5) {
        $punch .= ':00';
    }

    return $punch < ATTENDANCE_NOON_DEADLINE;
}

function attendance_apply_auto_absence_penalty(
    mysqli $conn,
    int $userId,
    int $companyId,
    int $branchId,
    string $date,
    string $reason
): void {
    $deduct = (float) payroll_settings($conn)['penalty_absent_days'];
    $reasonFull = '[Auto absence] ' . $reason;

    $stmt = $conn->prepare('SELECT id FROM attendance_logs WHERE user_id = ? AND log_date = ? LIMIT 1');
    $stmt->bind_param('is', $userId, $date);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $logId = (int) $existing['id'];
        if (db_column_exists($conn, 'attendance_logs', 'policy_violation')) {
            $stmt = $conn->prepare(
                "UPDATE attendance_logs SET leave_reason = ?, policy_violation = 1
                 WHERE id = ? AND (punch_in IS NULL OR punch_in = '')"
            );
            $stmt->bind_param('si', $reasonFull, $logId);
        } else {
            $stmt = $conn->prepare(
                "UPDATE attendance_logs SET leave_reason = ? WHERE id = ? AND (punch_in IS NULL OR punch_in = '')"
            );
            $stmt->bind_param('si', $reasonFull, $logId);
        }
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO attendance_logs (user_id, company_id, branch_id, log_date, is_leave, leave_reason)
             VALUES (?, ?, ?, ?, 0, ?)'
        );
        $stmt->bind_param('iiiss', $userId, $companyId, $branchId, $date, $reasonFull);
        $stmt->execute();
        $stmt->close();
    }

    if (function_exists('salary_table_exists') && salary_table_exists($conn, 'staff_penalties')) {
        $del = $conn->prepare("DELETE FROM staff_penalties WHERE user_id = ? AND penalty_date = ? AND penalty_type = 'late_punch' AND created_by = 0");
        $del->bind_param('is', $userId, $date);
        $del->execute();
        $del->close();
        $chk = $conn->prepare(
            'SELECT id FROM staff_penalties WHERE user_id = ? AND penalty_date = ? AND penalty_type = ? LIMIT 1'
        );
        $ptype = 'auto_absence_noon';
        $chk->bind_param('iss', $userId, $date, $ptype);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            $chk->close();
            $stmt = $conn->prepare(
                'INSERT INTO staff_penalties (user_id, company_id, penalty_date, penalty_type, deduction_days, reason, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, 0)'
            );
            $stmt->bind_param('iissds', $userId, $companyId, $date, $ptype, $deduct, $reasonFull);
            $stmt->execute();
            $stmt->close();
        } else {
            $chk->close();
        }
    }
}

/**
 * After 12:00 PM: staff with no leave and no punch before noon → auto absence + 2-day deduction.
 *
 * @return array{processed:int,penalized:int}
 */
function attendance_process_noon_absence(mysqli $conn, string $date, ?int $companyId = null): array
{
    date_default_timezone_set('Asia/Kolkata');
    $runKey = 'noon_absence';

    if ($date === date('Y-m-d') && date('H:i:s') < ATTENDANCE_NOON_DEADLINE) {
        return ['processed' => 0, 'penalized' => 0];
    }

    $companies = [];
    if ($companyId !== null && $companyId > 0) {
        $companies = [$companyId];
    } else {
        $r = $conn->query(
            "SELECT DISTINCT company_id FROM users WHERE role = 'staff' AND company_id IS NOT NULL" . staff_sql_active_filter('')
        );
        while ($row = $r->fetch_assoc()) {
            $cid = (int) $row['company_id'];
            if ($cid > 0) {
                $companies[] = $cid;
            }
        }
    }

    $totalPenalized = 0;
    $totalProcessed = 0;

    foreach ($companies as $cid) {
        if (attendance_automation_already_ran($conn, $runKey, $date, $cid)) {
            continue;
        }

        $activeFilter = staff_sql_active_filter('');
        $stmt = $conn->prepare(
            "SELECT * FROM users WHERE role = 'staff' AND company_id = ?{$activeFilter}"
        );
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $res = $stmt->get_result();
        $penalized = 0;
        $processed = 0;

        while ($u = $res->fetch_assoc()) {
            ++$processed;
            $uid = (int) $u['id'];
            $bid = (int) ($u['branch_id'] ?? 0);

            if (!attendance_penalty_applies($u, $date)) {
                continue;
            }
            if (attendance_staff_on_leave($conn, $uid, $date)) {
                continue;
            }
            if (attendance_staff_punched_before_noon($conn, $uid, $date)) {
                continue;
            }

            attendance_apply_auto_absence_penalty(
                $conn,
                $uid,
                $cid,
                $bid,
                $date,
                'No approved leave and no office punch before 12:00 PM'
            );
            ++$penalized;
        }
        $stmt->close();

        attendance_automation_mark_ran(
            $conn,
            $runKey,
            $date,
            $cid,
            "processed={$processed} penalized={$penalized}"
        );
        $totalProcessed += $processed;
        $totalPenalized += $penalized;
    }

    return ['processed' => $totalProcessed, 'penalized' => $totalPenalized];
}

function attendance_process_late_penalty(mysqli $conn, string $date, ?int $companyId = null): void
{
    if (!employee_lifecycle_ready($conn)) return;
    $now = date('H:i:s');
    
    $cid_filter = $companyId ? " AND company_id = " . (int)$companyId : "";
    $grace = sprintf('%02d:%02d:00', intdiv((int) payroll_settings($conn)['late_grace_minutes'], 60), (int) payroll_settings($conn)['late_grace_minutes'] % 60);
    $stmt = $conn->prepare("SELECT * FROM users WHERE role='staff' AND account_status='active' AND ADDTIME(shift_start_time, '$grace') <= ? $cid_filter");
    if (!$stmt) return;
    
    $stmt->bind_param("s", $now);
    $stmt->execute();
    $res = $stmt->get_result();
    $users = [];
    while ($row = $res->fetch_assoc()) $users[] = $row;
    $stmt->close();

    foreach ($users as $u) {
        $uid = (int) $u['id'];
        $cid = (int) $u['company_id'];
        
        if (!attendance_penalty_applies($u, $date)) continue;
        if (attendance_staff_on_leave($conn, $uid, $date)) continue;
        
        $stmt_chk = $conn->prepare("SELECT id FROM attendance_logs WHERE user_id=? AND log_date=? AND is_leave=0");
        $stmt_chk->bind_param("is", $uid, $date);
        $stmt_chk->execute();
        $punched = $stmt_chk->get_result()->num_rows > 0;
        $stmt_chk->close();
        if ($punched) continue;
        
        $stmt_pchk = $conn->prepare("SELECT id FROM staff_penalties WHERE user_id=? AND penalty_date=? AND penalty_type='late_punch'");
        $stmt_pchk->bind_param("is", $uid, $date);
        $stmt_pchk->execute();
        $penalized = $stmt_pchk->get_result()->num_rows > 0;
        $stmt_pchk->close();
        if ($penalized) continue;
        
        $ptype = 'late_punch';
        $preason = 'Late Punch - No arrival within ' . (int) payroll_settings($conn)['late_grace_minutes'] . ' min of shift time (' . date('h:i A', strtotime($u['shift_start_time'])) . ')';
        $pdays = (float) payroll_settings($conn)['penalty_late_days'];
        if ($pdays <= 0) continue;
        $stmt_ins = $conn->prepare("INSERT INTO staff_penalties (user_id, company_id, penalty_date, penalty_type, deduction_days, reason, created_by) VALUES (?, ?, ?, ?, ?, ?, 0)");
        $stmt_ins->bind_param("iissds", $uid, $cid, $date, $ptype, $pdays, $preason);
        if ($stmt_ins->execute()) {
            if (!empty($u['phone_number'])) {
                $msg = "⚠️ *Penalty Notice* ⚠️\n\n"
                     . "Hello {$u['name']},\n"
                     . "તમે તમારા નિર્ધારિત સમય (" . date('h:i A', strtotime($u['shift_start_time'])) . ") ના 1 કલાક પછી પણ પંચિંગ કર્યું નથી.\n"
                     . "તેથી તમને *Half Day* ની પેનલ્ટી લગાવવામાં આવી છે.\n\n"
                     . "જો કોઈ કારણ હોય તો એડમિનનો સંપર્ક કરો.";
                sendWhatsAppDirectMessage($u['phone_number'], $msg);
            }
        }
        $stmt_ins->close();
    }
}

/**
 * Automatic absence / late penalties only for staff who punch themselves ("self" mode),
 * never on their weekly off day and never before their joining date.
 */
function attendance_penalty_applies(array $u, string $date): bool
{
    if (($u['attendance_mode'] ?? ATT_MODE_SELF) !== ATT_MODE_SELF) {
        return false;
    }
    if (!empty($u['join_date']) && $date < $u['join_date']) {
        return false;
    }

    return !payroll_is_weekly_off($u, $date);
}

/**
 * "Auto" attendance mode (fixed-salary staff): every working day is marked present automatically
 * (shift start → standard hours), except weekly off, approved leave or a day that already has a record.
 * Back-fills missed days (max 62 days) so it works even if nobody opened the site for a while.
 */
function attendance_auto_present(mysqli $conn, ?int $companyId = null): int
{
    if (!db_column_exists($conn, 'users', 'attendance_mode')) {
        return 0;
    }
    $sql = "SELECT * FROM users WHERE role = 'staff' AND attendance_mode = 'auto'" . staff_sql_active_filter('');
    if ($companyId) {
        $sql .= ' AND company_id = ' . (int) $companyId;
    }
    $res = $conn->query($sql);
    $hours = (float) payroll_settings($conn)['hourly_day_hours'];
    $added = 0;
    $now = date('H:i:s');
    while ($res && ($u = $res->fetch_assoc())) {
        $uid = (int) $u['id'];
        $shift = substr((string) ($u['shift_start_time'] ?: '09:30:00'), 0, 8);
        $from = max(
            date('Y-m-d', strtotime('-62 days')),
            (string) ($u['auto_attendance_from'] ?: date('Y-m-d')),
            (string) ($u['join_date'] ?: '2000-01-01')
        );
        $to = $now >= $shift ? date('Y-m-d') : date('Y-m-d', strtotime('-1 day'));
        $out = date('H:i:s', strtotime($shift) + (int) round($hours * 3600));
        $mins = (int) round($hours * 60);
        $reason = '[Auto present]';
        for ($d = strtotime($from); $d <= strtotime($to); $d = strtotime('+1 day', $d)) {
            $ds = date('Y-m-d', $d);
            if (payroll_is_weekly_off($u, $ds)) {
                continue;
            }
            $chk = $conn->prepare('SELECT id FROM attendance_logs WHERE user_id = ? AND log_date = ? LIMIT 1');
            $chk->bind_param('is', $uid, $ds);
            $chk->execute();
            $exists = $chk->get_result()->num_rows > 0;
            $chk->close();
            if ($exists) {
                continue;
            }
            $cid = (int) $u['company_id'];
            $bid = (int) $u['branch_id'];
            $ins = $conn->prepare('INSERT INTO attendance_logs (user_id, company_id, branch_id, log_date, punch_in, punch_out, total_worked_minutes, is_leave, leave_reason) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)');
            $ins->bind_param('iiisssis', $uid, $cid, $bid, $ds, $shift, $out, $mins, $reason);
            if ($ins->execute()) {
                $added++;
            }
            $ins->close();
        }
    }

    return $added;
}

/** Run today's noon job when due (safe to call on admin dashboard load). */
function attendance_run_pending_automation(mysqli $conn, ?int $companyId = null): void
{
    date_default_timezone_set('Asia/Kolkata');
    $today = date('Y-m-d');
    try {
        attendance_auto_present($conn, $companyId);
    } catch (Throwable $e) {
        error_log('attendance_auto_present: ' . $e->getMessage());
    }
    
    try {
        attendance_process_late_penalty($conn, $today, $companyId);
    } catch (mysqli_sql_exception $e) {
        error_log('attendance_process_late_penalty error: ' . $e->getMessage());
    }
    
    if (date('H:i:s') < ATTENDANCE_NOON_DEADLINE) {
        return;
    }
    try {
        attendance_process_noon_absence($conn, $today, $companyId);
    } catch (mysqli_sql_exception $e) {
        error_log('attendance_run_pending_automation: ' . $e->getMessage());
    }
}
