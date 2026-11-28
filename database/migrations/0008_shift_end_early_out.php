<?php
/**
 * Shift end time per staff + early punch-out approval requests.
 * Punch-out before the shift end time goes to the admin for approval instead of being recorded.
 */
return function (mysqli $conn, MigrationHelper $m): void {
    $m->addColumn('users', 'shift_end_time', 'TIME NULL');
    $m->exec("CREATE TABLE IF NOT EXISTS early_out_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        company_id INT NOT NULL DEFAULT 0,
        attendance_id INT NOT NULL,
        log_date DATE NOT NULL,
        requested_time TIME NOT NULL,
        shift_end_time TIME NULL,
        reason VARCHAR(255) NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'pending',
        decided_by INT NULL,
        decided_at DATETIME NULL,
        admin_note VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status (company_id, status),
        KEY idx_user_date (user_id, log_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
