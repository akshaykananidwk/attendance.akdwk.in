<?php
/**
 * Backup Processor Script (Updated for structured ZIP)
 * Handles database export, file zipping, and cleanup.
 */

// --- CONFIGURATION ---
define('BACKUP_DIR', '_backups/');
define('MAX_BACKUPS', 5);
require_once __DIR__ . '/includes/app_config.php';
define('AUTH_TOKEN', (string) (app_config()['backup_token'] ?? '')); // set 'backup_token' in config.local.php to enable

// --- SECURITY CHECK ---
if (AUTH_TOKEN === '' || !isset($_GET['token']) || !hash_equals(AUTH_TOKEN, (string) $_GET['token'])) {
    header('HTTP/1.0 403 Forbidden');
    die("Unauthorized access.");
}

// --- INCREASE SERVER LIMITS ---
set_time_limit(600); // 10 minutes
ini_set('memory_limit', '512M');

// --- AUTO-DETECT DB CREDENTIALS ---
$db_config_file = 'db.php';
if (!file_exists($db_config_file)) {
    die("Database configuration file ($db_config_file) not found.");
}

// Include the config file to get credentials
require_once __DIR__ . '/db.php';

// Map variables from db.php (Assumed based on previous inspection)
$db_host = $dbConfig['db_host'] ?? 'localhost';
$db_user = $dbConfig['db_user'] ?? '';
$db_pass = $dbConfig['db_pass'] ?? '';
$db_name = $dbConfig['db_name'] ?? '';

if (empty($db_user) || empty($db_name)) {
    die("Could not detect database credentials from $db_config_file");
}

// --- DEFINE STRUCTURE ---
$timestamp = date('Y-m-d_H-i');
$folder_name = "backup_$timestamp";
$zip_file_name = "$folder_name.zip";
$zip_path = BACKUP_DIR . $zip_file_name;

if (!is_dir(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0755, true);
}

// --- START ZIP PROCESS ---
$root_path = realpath('.');
$zip = new ZipArchive();
if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    die("Could not create ZIP archive.");
}

// 1. Add Database to ZIP (Inside database/ subfolder)
// Instead of creating real folders on disk first, we can just specify the internal path in the ZIP
$temp_sql_path = BACKUP_DIR . "temp_db_dump.sql";
$command = "mysqldump --host=$db_host --user=$db_user --password=$db_pass $db_name > $temp_sql_path 2>&1";
exec($command, $output, $return_var);

if ($return_var === 0 && file_exists($temp_sql_path)) {
    $zip->addFile($temp_sql_path, "$folder_name/database/database.sql");
} else {
    $zip->close();
    unlink($zip_path);
    die("Error exporting database: " . implode("\n", $output));
}

// 2. Add Website Files to ZIP (Inside website_files/ subfolder)
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root_path),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($files as $name => $file) {
    if (!$file->isDir()) {
        $file_path = $file->getRealPath();
        $relative_path = substr($file_path, strlen($root_path) + 1);

        // --- EXCLUSIONS ---
        // Don't zip the backup folder itself
        if (strpos($relative_path, BACKUP_DIR) === 0) continue;
        // Don't zip version control or temp files
        if (strpos($relative_path, '.git') !== false) continue;
        // Don't zip the processor scripts themselves if they are in the root (optional but cleaner)
        if ($relative_path == basename(__FILE__)) continue;

        // Add to zip with the required structure: backup_xxx/website_files/xxx
        $zip->addFile($file_path, "$folder_name/website_files/$relative_path");
    }
}

$zip->close();

// Cleanup temp SQL
if (file_exists($temp_sql_path)) {
    unlink($temp_sql_path);
}

// 3. Auto-Cleanup Old Backups
$backups = glob(BACKUP_DIR . "backup_*.zip");
if (count($backups) > MAX_BACKUPS) {
    usort($backups, function($a, $b) {
        return filemtime($a) - filemtime($b);
    });
    
    $to_delete = count($backups) - MAX_BACKUPS;
    for ($i = 0; $i < $to_delete; $i++) {
        unlink($backups[$i]);
    }
}

echo json_encode([
    'status' => 'success',
    'file' => $zip_file_name,
    'path' => $zip_path,
    'size' => round(filesize($zip_path) / 1024 / 1024, 2) . ' MB'
]);
?>
