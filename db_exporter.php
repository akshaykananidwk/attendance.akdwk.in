<?php
/**
 * Database Exporter (Remote Trigger for Python Backup Software)
 * PURE PHP VERSION (No exec() required)
 */

// --- CONFIGURATION ---
define('BACKUP_DIR', '_backups/');
require_once __DIR__ . '/includes/app_config.php';
define('AUTH_TOKEN', (string) (app_config()['backup_token'] ?? '')); // set 'backup_token' in config.local.php to enable

// --- SECURITY CHECK ---
if (AUTH_TOKEN === '' || !isset($_GET['token']) || !hash_equals(AUTH_TOKEN, (string) $_GET['token'])) {
    header('HTTP/1.0 403 Forbidden');
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized access.']));
}

$db_config_file = 'db.php';
if (!file_exists($db_config_file)) {
    die(json_encode(['status' => 'error', 'message' => 'DB config not found.']));
}

require_once __DIR__ . '/db.php';

$db_host = $dbConfig['db_host'] ?? 'localhost';
$db_user = $dbConfig['db_user'] ?? '';
$db_pass = $dbConfig['db_pass'] ?? '';
$db_name = $dbConfig['db_name'] ?? '';

if (!is_dir(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0755, true);
}

$temp_sql_file = "remote_db_dump.sql";
$sql_path = BACKUP_DIR . $temp_sql_file;

// --- PURE PHP DUMP LOGIC ---
try {
    $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($mysqli->connect_error) {
        throw new Exception("Connection failed: " . $mysqli->connect_error);
    }

    $mysqli->set_charset("utf8mb4");

    $tables = [];
    $result = $mysqli->query("SHOW TABLES");
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }

    $sql_output = "-- Database Dump: $db_name\n";
    $sql_output .= "-- Generated: " . date("Y-m-d H:i:s") . "\n\n";

    foreach ($tables as $table) {
        $result = $mysqli->query("SELECT * FROM $table");
        $num_fields = $result->field_count;

        $sql_output .= "DROP TABLE IF EXISTS `$table`;\n";
        $row2 = $mysqli->query("SHOW CREATE TABLE `$table`")->fetch_row();
        $sql_output .= $row2[1] . ";\n\n";

        while ($row = $result->fetch_assoc()) {
            $sql_output .= "INSERT INTO `$table` VALUES(";
            $vals = [];
            foreach ($row as $val) {
                if (isset($val)) {
                    $vals[] = "'" . $mysqli->real_escape_string($val) . "'";
                } else {
                    $vals[] = "NULL";
                }
            }
            $sql_output .= implode(",", $vals) . ");\n";
        }
        $sql_output .= "\n\n";
    }

    // Save output
    file_put_contents($sql_path, $sql_output);

    echo json_encode([
        'status' => 'success',
        'file_path' => $sql_path,
        'file_name' => $temp_sql_file,
        'message' => 'Database dump (PHP) created successfully.'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Pure PHP Export Error: ' . $e->getMessage()
    ]);
}
?>
