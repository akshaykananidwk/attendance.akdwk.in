<?php
/**
 * JSON API for the System Update page. Admin only, CSRF-protected for every state-changing action.
 */
session_start();
require_once '../db.php';
require_once __DIR__ . '/../includes/updater/UpdateManager.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function api_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    api_out(['ok' => false, 'error' => 'Admin login required.'], 403);
}
$userId = (int) $_SESSION['user_id'];
$userName = (string) ($_SESSION['name'] ?? 'admin');
$action = (string) ($_REQUEST['action'] ?? '');
$readOnly = in_array($action, ['status', 'history', 'log'], true);
if (!$readOnly) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        api_out(['ok' => false, 'error' => 'Security token expired — reload the page.'], 419);
    }
}
// Release the session lock so status polling keeps working during a long update.
session_write_close();

try {
    $um = new UpdateManager($conn);
    switch ($action) {
        case 'status':
            api_out(['ok' => true] + $um->status());
        case 'history':
            api_out(['ok' => true, 'history' => $um->history()]);
        case 'log':
            api_out(['ok' => true, 'log' => $um->historyLog((int) ($_GET['id'] ?? 0))]);
        case 'save_settings':
            $repoIn = trim((string) ($_POST['repo'] ?? ''));
            if (preg_match('#github\.com[/:]([^/]+)/([^/\s]+?)(?:\.git)?/?$#i', $repoIn, $m)) {
                $repoIn = $m[1] . '/' . $m[2];
            }
            [$owner, $repo] = array_pad(explode('/', $repoIn, 2), 2, '');
            $token = (string) ($_POST['token'] ?? '');
            $res = $um->saveSettings(
                $owner,
                $repo,
                (string) ($_POST['branch'] ?? 'main'),
                $token === '' ? null : $token,
                (string) ($_POST['app_url'] ?? ''),
                (string) ($_POST['protected_paths'] ?? ''),
                (int) ($_POST['keep_backups'] ?? 5),
                !empty($_POST['strict_health']),
                $userId
            );
            write_audit_log($conn, 'updater_settings_saved', 'Repository ' . $res['repo'] . ' / ' . ($_POST['branch'] ?? ''), 'update_settings', '1');
            api_out(['ok' => true, 'message' => 'Settings saved — connected to ' . $res['repo'] . ' (latest commit ' . $res['latest']['short'] . ').', 'settings' => $um->settings()]);
        case 'check':
            api_out(['ok' => true, 'result' => $um->check()]);
        case 'update':
            if ($um->isRunning()) {
                api_out(['ok' => false, 'error' => 'An update is already running.'], 409);
            }
            write_audit_log($conn, 'update_started', 'Auto-update started', 'update_history', null);
            $res = $um->update($userId, $userName);
            write_audit_log($conn, $res['ok'] ? 'update_success' : 'update_failed', $res['ok'] ? ('Updated to ' . $res['version']) : ($res['error'] ?? ''), 'update_history', (string) ($res['history_id'] ?? ''));
            api_out($res);
        case 'recover':
            api_out($um->recover($userId, $userName));
        case 'restore':
            $res = $um->restoreBackup((int) ($_POST['id'] ?? 0), $userId, $userName);
            write_audit_log($conn, 'update_restore', 'Restored backup of update #' . (int) ($_POST['id'] ?? 0), 'update_history', (string) ($res['history_id'] ?? ''));
            api_out($res);
        default:
            api_out(['ok' => false, 'error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    error_log('admin_updates_api: ' . $e->getMessage());
    api_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
