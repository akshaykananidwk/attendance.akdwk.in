<?php
/**
 * Remember me: cookie "akdwk_rm" = selector:validator. DB keeps sha256(validator) only.
 * Token rotates on every auto-login; logout or a password change revokes it.
 */
const REMEMBER_COOKIE = 'akdwk_rm';
const REMEMBER_DAYS = 30;

function remember_ready(mysqli $conn): bool
{
    static $ok = null;

    return $ok ??= salary_table_exists($conn, 'remember_tokens');
}

function remember_cookie_set(string $value, int $expires): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $path = rtrim(dirname(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $path = preg_replace('#/admin$#', '', $path) ?: '/';
    setcookie(REMEMBER_COOKIE, $value, ['expires' => $expires, 'path' => $path === '' ? '/' : $path, 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
}

function remember_issue(mysqli $conn, int $userId): void
{
    if (!remember_ready($conn)) {
        return;
    }
    $sel = bin2hex(random_bytes(12));
    $val = bin2hex(random_bytes(32));
    $hash = hash('sha256', $val);
    $exp = time() + REMEMBER_DAYS * 86400;
    $expAt = date('Y-m-d H:i:s', $exp);
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190);
    $stmt = $conn->prepare('INSERT INTO remember_tokens (user_id, selector, token_hash, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('issss', $userId, $sel, $hash, $ua, $expAt);
    $stmt->execute();
    $stmt->close();
    remember_cookie_set("$sel:$val", $exp);
}

function remember_forget(mysqli $conn): void
{
    $c = (string) ($_COOKIE[REMEMBER_COOKIE] ?? '');
    if ($c !== '' && remember_ready($conn) && str_contains($c, ':')) {
        $sel = explode(':', $c, 2)[0];
        $stmt = $conn->prepare('DELETE FROM remember_tokens WHERE selector = ?');
        $stmt->bind_param('s', $sel);
        $stmt->execute();
        $stmt->close();
    }
    remember_cookie_set('', time() - 3600);
}

function remember_revoke_user(mysqli $conn, int $userId): void
{
    if (remember_ready($conn)) {
        $conn->query('DELETE FROM remember_tokens WHERE user_id = ' . (int) $userId);
    }
}

/** Called from db.php: when there is no session but a valid remember cookie, log the user back in. */
function remember_auto_login(mysqli $conn): void
{
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE || !empty($_SESSION['user_id'])) {
        return;
    }
    $c = (string) ($_COOKIE[REMEMBER_COOKIE] ?? '');
    if ($c === '' || !str_contains($c, ':') || !remember_ready($conn)) {
        return;
    }
    [$sel, $val] = explode(':', $c, 2);
    if (!preg_match('/^[0-9a-f]{24}$/', $sel) || !preg_match('/^[0-9a-f]{64}$/', $val)) {
        remember_cookie_set('', time() - 3600);

        return;
    }
    $stmt = $conn->prepare('SELECT * FROM remember_tokens WHERE selector = ? AND expires_at > NOW() LIMIT 1');
    $stmt->bind_param('s', $sel);
    $stmt->execute();
    $tok = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$tok || !hash_equals((string) $tok['token_hash'], hash('sha256', $val))) {
        remember_cookie_set('', time() - 3600);

        return;
    }
    $conn->query('DELETE FROM remember_tokens WHERE id = ' . (int) $tok['id']); // rotate
    $stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $tok['user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || (int) ($user['login_allowed'] ?? 1) !== 1 || in_array(($user['account_status'] ?? 'active'), ['disabled'], true)) {
        remember_cookie_set('', time() - 3600);

        return;
    }
    if ($user['role'] === 'staff' && function_exists('staff_can_login') && !staff_can_login($conn, $user)) {
        remember_cookie_set('', time() - 3600);

        return;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['company_id'] = $user['company_id'];
    $_SESSION['branch_id'] = $user['branch_id'];
    if ($user['role'] === 'staff' && function_exists('staff_apply_session_lifecycle')) {
        staff_apply_session_lifecycle($user);
    }
    remember_issue($conn, (int) $user['id']);
}
