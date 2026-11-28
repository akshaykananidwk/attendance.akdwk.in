<?php
// 1. Set Indian Timezone globally
date_default_timezone_set('Asia/Kolkata'); 

// 2. Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate a CSRF token and store it in the session if it doesn't exist.
 */
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a CSRF token from a request.
 */
function verify_csrf_token($token) {
    if (!isset($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Validate password complexity: Min 8 chars, must include letters and numbers.
 */
function validate_password_complexity($password) {
    if (strlen($password) < 8) return false;
    if (!preg_match('/[A-Za-z]/', $password)) return false;
    if (!preg_match('/[0-9]/', $password)) return false;
    return true;
}

/**
 * RBAC: Check if user has required role
 */
function check_access($allowed_roles = []) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit();
    }
    if (!empty($allowed_roles) && !in_array($_SESSION['role'], $allowed_roles, true)) {
        if (($_SESSION['role'] ?? '') === 'auditor') {
            deny_auditor_to_reports();
        }
        die("<h3 style='color:red;text-align:center;margin-top:50px;'>Access Denied! You do not have permission to view this module.</h3>");
    }
}

function is_auditor(): bool {
    return ($_SESSION['role'] ?? '') === 'auditor';
}

/**
 * If the current user is an auditor, send them to salary reports (read-only hub).
 * Call from admin pages that must not be editable by auditors.
 */
function deny_auditor_to_reports(): void {
    if (!is_auditor()) {
        return;
    }
    $self = $_SERVER['PHP_SELF'] ?? '';
    if (preg_match('#[/\\\\]admin[/\\\\]#', $self)) {
        header('Location: admin_reports.php');
    } else {
        header('Location: admin/admin_reports.php');
    }
    exit();
}

function get_request_ip(): string {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $v = (string) $_SERVER[$k];
            if ($k === 'HTTP_X_FORWARDED_FOR') {
                $v = trim(explode(',', $v)[0]);
            }
            return substr($v, 0, 45);
        }
    }
    return '';
}

/**
 * Append one row to audit_logs (silently no-ops if the table is missing).
 */
function write_audit_log(mysqli $conn, string $action, ?string $details = null, ?string $entity_type = null, ?string $entity_id = null): void {
    if (!isset($_SESSION['user_id'])) {
        return;
    }
    $user_id = (int) $_SESSION['user_id'];
    $user_name = (string) ($_SESSION['name'] ?? '');
    $role = (string) ($_SESSION['role'] ?? '');
    $cid = get_company_id();
    $company_id = ($cid === null || $cid === '') ? 0 : (int) $cid;
    $ip = get_request_ip();
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : '';

    $stmt = $conn->prepare(
        'INSERT INTO audit_logs (company_id, user_id, user_name, role, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param(
        'iissssssss',
        $company_id,
        $user_id,
        $user_name,
        $role,
        $action,
        $entity_type,
        $entity_id,
        $details,
        $ip,
        $ua
    );
    @$stmt->execute();
    $stmt->close();
}

/**
 * Net worked minutes from one attendance_logs row (lunch gap + night shift).
 * Matches manual entry / punch save logic.
 */
function attendance_worked_minutes_from_row(array $row): int {
    $final_in = $row['punch_in'] ?? null;
    $final_l_out = $row['lunch_out'] ?? null;
    $final_l_in = $row['lunch_in'] ?? null;
    $final_out = $row['punch_out'] ?? null;
    if (empty($final_in) || empty($final_out)) {
        return 0;
    }
    $lunch_sec = 0;
    if ($final_l_out && $final_l_in) {
        $l_out_time = strtotime($final_l_out);
        $l_in_time = strtotime($final_l_in);
        if ($l_in_time < $l_out_time) {
            $l_in_time += 86400;
        }
        $lunch_sec = $l_in_time - $l_out_time;
    }
    $w_in_time = strtotime($final_in);
    $w_out_time = strtotime($final_out);
    if ($w_out_time < $w_in_time) {
        $w_out_time += 86400;
    }
    $work_sec = $w_out_time - $w_in_time;
    $total_minutes = (int) round(($work_sec - $lunch_sec) / 60);

    return max(0, $total_minutes);
}

function format_attendance_duration(int $mins): string {
    if ($mins <= 0) {
        return '—';
    }
    $h = intdiv($mins, 60);
    $m = $mins % 60;

    return $h . 'h ' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . 'm';
}

/**
 * Multi-tenant Helpers
 */
function get_company_id() {
    return $_SESSION['company_id'] ?? null;
}

function get_branch_id() {
    return $_SESSION['branch_id'] ?? null;
}

/**
 * Fire-and-forget WhatsApp (does not block page redirect).
 */
function sendWhatsAppAsync(string $number, string $message): void
{
    $number = preg_replace('/[^0-9]/', '', $number);
    $payload = json_encode(['n' => $number, 'm' => $message]);
    if ($payload === false) {
        return;
    }
    register_shutdown_function(static function () use ($payload): void {
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return;
        }
        sendWhatsAppDirectMessageSync((string) ($data['n'] ?? ''), (string) ($data['m'] ?? ''));
    });
}

function sendWhatsAppGroupNotification($message)
{
    global $conn;
    $res = $conn->query('SELECT group_id FROM settings LIMIT 1');
    if (!$res || !($settings = $res->fetch_assoc()) || empty($settings['group_id'])) {
        return false;
    }
    sendWhatsAppAsync((string) $settings['group_id'], $message);

    return true;
}

function sendWhatsAppDirectMessage($number, $message)
{
    sendWhatsAppAsync($number, $message);

    return true;
}

/** @internal Used by shutdown handler */
function sendWhatsAppDirectMessageSync($number, $message)
{
    global $conn;
    $number = preg_replace('/[^0-9]/', '', $number);
    if (strlen($number) === 10) {
        $number = '91' . $number;
    }
    $res = $conn->query('SELECT api_key, session_id FROM settings LIMIT 1');
    if (!$res || !($settings = $res->fetch_assoc())) {
        return false;
    }
    $api_key = $settings['api_key'] ?? '';
    $session_id = $settings['session_id'] ?? '';
    if ($api_key === '' || $session_id === '') {
        return false;
    }
    $encoded_msg = rawurlencode($message);
    $api_url = 'https://bulk.akdwk.in/api.php?number=' . rawurlencode($number)
        . '&message=' . $encoded_msg
        . '&session_id=' . rawurlencode($session_id)
        . '&api_key=' . rawurlencode($api_key);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $api_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    return $response;
}

function session_regenerate_on_login(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

require_once __DIR__ . '/includes/app_config.php';

// Maintenance mode (only while the auto-updater swaps files). Updater/health endpoints stay reachable.
if (PHP_SAPI !== 'cli' && app_maintenance_active()) {
    $saasSelf = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    if (!in_array($saasSelf, ['health.php', 'admin_updates.php', 'admin_updates_api.php'], true)) {
        http_response_code(503);
        header('Retry-After: 60');
        echo '<!doctype html><meta charset="utf-8"><title>Updating…</title><div style="font-family:sans-serif;text-align:center;margin-top:15vh"><h2>System update in progress</h2><p>Please try again in a minute.</p></div>';
        exit;
    }
}

// Not configured yet → send the browser to the one-click installer.
if (!app_is_configured() || (!app_is_installed() && PHP_SAPI !== 'cli' && !defined('APP_INSTALLER'))) {
    if (PHP_SAPI === 'cli' || defined('APP_INSTALLER')) {
        if (!app_is_configured()) {
            throw new RuntimeException('Application is not configured (config.local.php missing).');
        }
    } else {
        header('Location: ' . app_relative_root() . 'install/');
        exit;
    }
}

$dbConfig = app_config();
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli(
    $dbConfig['db_host'],
    $dbConfig['db_user'],
    $dbConfig['db_pass'],
    $dbConfig['db_name'],
    (int) $dbConfig['db_port']
);
if ($conn->connect_error) {
    error_log('DB connection failed: ' . $conn->connect_error);
    if (PHP_SAPI === 'cli') {
        throw new RuntimeException('DB connection failed: ' . $conn->connect_error);
    }
    http_response_code(500);
    die('Connection failed. Check config.local.php (or re-run /install).');
}
$conn->set_charset('utf8mb4');

require_once __DIR__ . '/includes/attendance_helpers.php';
require_once __DIR__ . '/includes/employee_lifecycle.php';
require_once __DIR__ . '/includes/salary_engine.php';
require_once __DIR__ . '/includes/payroll.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/leave_policy.php';
require_once __DIR__ . '/includes/attendance_automation.php';
require_once __DIR__ . '/includes/early_out.php';