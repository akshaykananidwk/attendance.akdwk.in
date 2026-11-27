<?php
/**
 * Replaces the old one-time scripts db_update_v6_office_rules.php, db_update_v7_employee_lifecycle.php
 * and db_update_v8.php. Idempotent: only adds what is missing.
 */
return function (mysqli $conn, MigrationHelper $m): void {
    $cols = [
        'attendance_logs' => [
            'company_id' => 'INT NULL',
            'branch_id' => 'INT NULL',
            'is_half_day' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'attendance_invalid' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'policy_violation' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'double_deduction_days' => 'DECIMAL(5,2) NOT NULL DEFAULT 0',
            'medical_cert_path' => 'VARCHAR(255) NULL',
        ],
        'settings' => [
            'office_start_time' => "TIME NULL DEFAULT '09:30:00'",
            'leave_advance_days' => 'INT NOT NULL DEFAULT 2',
        ],
        'users' => [
            'account_status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
            'login_allowed' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'archived_at' => 'DATETIME NULL',
            'status_changed_at' => 'DATETIME NULL',
            'status_changed_by' => 'INT NULL',
            'status_note' => 'VARCHAR(255) NULL',
            'last_login_at' => 'DATETIME NULL',
            'last_activity_at' => 'DATETIME NULL',
            'shift_start_time' => "TIME DEFAULT '09:30:00'",
        ],
        'leave_requests' => [
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
        ],
    ];
    foreach ($cols as $table => $defs) {
        foreach ($defs as $col => $def) {
            $m->addColumn($table, $col, $def);
        }
    }
    $m->addIndex('attendance_logs', 'idx_att_user_date', 'user_id, log_date');
    $m->exec('UPDATE attendance_logs a INNER JOIN users u ON u.id = a.user_id
              SET a.company_id = u.company_id, a.branch_id = u.branch_id
              WHERE a.company_id IS NULL AND u.company_id IS NOT NULL');
};
