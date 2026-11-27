<?php
/**
 * Secure Backup Downloader
 */

define('BACKUP_DIR', '_backups/');
require_once __DIR__ . '/includes/app_config.php';
define('AUTH_TOKEN', (string) (app_config()['backup_token'] ?? '')); // set 'backup_token' in config.local.php to enable

// SECURITY CHECK
if (AUTH_TOKEN === '' || !isset($_GET['token']) || !hash_equals(AUTH_TOKEN, (string) $_GET['token'])) {
    header('HTTP/1.0 403 Forbidden');
    die("Unauthorized access.");
}

if (!isset($_GET['file'])) {
    die("No file specified.");
}

$file = basename($_GET['file']);
$file_path = BACKUP_DIR . $file;

if (file_exists($file_path) && strpos($file, 'backup_') === 0 && substr($file, -4) === '.zip') {
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file_path));
    
    // Clear output buffer to avoid corruption
    ob_clean();
    flush();
    
    readfile($file_path);
    exit;
} else {
    die("File not found or invalid access.");
}
?>
