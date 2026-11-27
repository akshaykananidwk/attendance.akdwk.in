<?php
/**
 * Health endpoint used by the auto-updater after an update (and by uptime monitors).
 *   /health.php                 → {"status":"ok"} or 503 {"status":"fail"}
 *   /health.php?token=<secret>  → detailed checks + installed version/commit (token = HMAC of storage/app.key)
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex');

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/includes/updater/UpdateManager.php';
    $report = HealthCheck::internal($conn);
    $authorised = isset($_GET['token']) && hash_equals(app_health_token(), (string) $_GET['token']);
    http_response_code($report['status'] === 'ok' ? 200 : 503);
    if (!$authorised) {
        echo json_encode(['status' => $report['status']]);
        exit;
    }
    $v = app_version_info();
    echo json_encode($report + ['version' => $v['version'], 'commit' => $v['commit'], 'time' => date('c')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(503);
    error_log('health.php: ' . $e->getMessage());
    echo json_encode(['status' => 'fail']);
}
