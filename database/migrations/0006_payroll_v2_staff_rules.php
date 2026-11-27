<?php
/**
 * Payroll v2: per-staff attendance mode (self punch / auto present / manual by admin),
 * weekly off days (e.g. Sunday), explicit optional rate increment (replaces hidden +₹1/month),
 * and configurable penalty values. New rules apply from the current month; older months keep
 * the legacy calculation so settled balances do not change.
 */
return function (mysqli $conn, MigrationHelper $m): void {
    $m->addColumn('users', 'attendance_mode', "VARCHAR(10) NOT NULL DEFAULT 'self'");
    $m->addColumn('users', 'weekly_off', "VARCHAR(20) NOT NULL DEFAULT ''");
    $m->addColumn('users', 'rate_increment', 'DECIMAL(10,2) NOT NULL DEFAULT 0');
    $m->addColumn('users', 'auto_attendance_from', 'DATE NULL');

    $m->addColumn('settings', 'payroll_v2_from', 'VARCHAR(7) NULL');
    $m->addColumn('settings', 'penalty_absent_days', 'DECIMAL(5,2) NOT NULL DEFAULT 2.00');
    $m->addColumn('settings', 'penalty_late_days', 'DECIMAL(5,2) NOT NULL DEFAULT 0.50');
    $m->addColumn('settings', 'hourly_day_hours', 'DECIMAL(4,2) NOT NULL DEFAULT 8.00');
    $m->addColumn('settings', 'late_grace_minutes', 'INT NOT NULL DEFAULT 60');

    $ym = date('Y-m');
    $stmt = $conn->prepare("UPDATE settings SET payroll_v2_from = ? WHERE payroll_v2_from IS NULL OR payroll_v2_from = ''");
    $stmt->bind_param('s', $ym);
    $stmt->execute();
    $stmt->close();
};
