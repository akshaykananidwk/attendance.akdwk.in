<?php
/**
 * The code supports a read-only "auditor" role (db.php: is_auditor) but the users.role ENUM did not allow it.
 */
return function (mysqli $conn, MigrationHelper $m): void {
    $r = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
    $col = $r ? $r->fetch_assoc() : null;
    if ($col && str_starts_with(strtolower((string) $col['Type']), 'enum(') && !str_contains((string) $col['Type'], "'auditor'")) {
        $m->exec("ALTER TABLE users MODIFY role ENUM('admin','manager','hr','staff','auditor') DEFAULT 'staff'");
    }
};
