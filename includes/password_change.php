<?php
/**
 * "Change my password" — shared by admin/admin_password.php and staff_password.php.
 * Requires the current password; new password: min 8 chars with letters and numbers.
 * Returns an HTML alert (or '').
 */
function password_change_handle(mysqli $conn): string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['change_password'])) {
        return '';
    }
    if (!empty($_SESSION['impersonator_id'])) {
        return '<div class="alert alert-warning">You are viewing as this staff member — the password cannot be changed here.</div>';
    }
    if (!verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        return '<div class="alert alert-danger">Security token expired — reload the page.</div>';
    }
    // simple brute-force brake: max 5 wrong attempts per 15 minutes per session
    $_SESSION['pw_fail'] = array_values(array_filter($_SESSION['pw_fail'] ?? [], static fn ($t) => $t > time() - 900));
    if (count($_SESSION['pw_fail']) >= 5) {
        return '<div class="alert alert-danger">Too many wrong attempts. Try again after 15 minutes.</div>';
    }
    $uid = (int) $_SESSION['user_id'];
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $stmt = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $stored = (string) ($stmt->get_result()->fetch_assoc()['password'] ?? '');
    $stmt->close();
    $ok = (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2a$') || str_starts_with($stored, '$argon2'))
        ? password_verify($current, $stored)
        : ($stored !== '' && hash_equals($stored, $current));
    if (!$ok) {
        $_SESSION['pw_fail'][] = time();

        return '<div class="alert alert-danger">Current password is wrong.</div>';
    }
    if (!validate_password_complexity($new)) {
        return '<div class="alert alert-danger">New password must be at least 8 characters and contain letters and numbers.</div>';
    }
    if ($new !== $confirm) {
        return '<div class="alert alert-danger">New password and confirm password do not match.</div>';
    }
    if (hash_equals($current, $new)) {
        return '<div class="alert alert-warning">New password is the same as the current one.</div>';
    }
    $hash = password_hash($new, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
    $stmt->bind_param('si', $hash, $uid);
    $stmt->execute();
    $stmt->close();
    session_regenerate_id(true);
    unset($_SESSION['pw_fail']);
    write_audit_log($conn, 'account.password_changed', 'User changed own password', 'user', (string) $uid);

    return '<div class="alert alert-success fw-bold"><i class="bi bi-check-circle-fill"></i> Password changed successfully. Use the new password from next login.</div>';
}

function password_change_form(string $csrf): string
{
    $c = htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8');
    $field = static fn (string $name, string $label, string $auto) => '<div class="mb-3"><label class="form-label fw-bold">' . $label . '</label>'
        . '<div class="input-group"><input type="password" name="' . $name . '" id="' . $name . '" class="form-control" required autocomplete="' . $auto . '">'
        . '<button class="btn btn-outline-secondary" type="button" onclick="var f=document.getElementById(\'' . $name . '\');f.type=f.type===\'password\'?\'text\':\'password\';"><i class="bi bi-eye"></i></button></div></div>';

    return '<div class="card shadow-sm" style="max-width:480px"><div class="card-body p-4">'
        . '<form method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="' . $c . '">'
        . $field('current_password', 'Current password', 'current-password')
        . $field('new_password', 'New password', 'new-password')
        . $field('confirm_password', 'Confirm new password', 'new-password')
        . '<div class="form-text mb-3">Minimum 8 characters, with letters and numbers (e.g. Shop2026ak).</div>'
        . '<button name="change_password" class="btn btn-primary w-100 fw-bold"><i class="bi bi-key"></i> Change password</button></form></div></div>';
}
