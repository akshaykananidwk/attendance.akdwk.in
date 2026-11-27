<?php
/**
 * Replaces hash_passwords.php: any password still stored as plain text is converted to bcrypt.
 * Users keep the same password; login.php already verifies bcrypt hashes.
 */
return function (mysqli $conn, MigrationHelper $m): void {
    if (!$m->columnExists('users', 'password')) {
        return;
    }
    $res = $conn->query('SELECT id, password FROM users WHERE password <> \'\' AND password NOT LIKE \'$2y$%\' AND password NOT LIKE \'$2a$%\' AND password NOT LIKE \'$2b$%\' AND password NOT LIKE \'$argon2%\'');
    $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
    while ($res && ($row = $res->fetch_assoc())) {
        $hash = password_hash((string) $row['password'], PASSWORD_DEFAULT);
        $id = (int) $row['id'];
        $stmt->bind_param('si', $hash, $id);
        $stmt->execute();
    }
    $stmt->close();
};
