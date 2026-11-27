<?php
/**
 * db_backup.php
 * A utility script to backup the 'attendance1' database.
 */

require_once 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized access.");
}

$backup_file = 'database_backup_' . date('Y-m-d_H-i-s') . '.sql';
$backup_path = '../database/' . $backup_file;

// Ensure database directory exists
if (!is_dir('../database')) {
    mkdir('../database', 0755, true);
}

// Method 1: Try mysqldump via system()
$command = "mysqldump -u " . escapeshellarg($username) . " -p" . escapeshellarg($password) . " " . escapeshellarg($dbname) . " > " . escapeshellarg($backup_path);

echo "<h1>Database Backup Utility</h1>";
echo "Attempting to create backup: <b>$backup_file</b>...<br>";

system($command, $return_var);

if ($return_var === 0) {
    echo "<p style='color:green; font-weight:bold;'>SUCCESS: Backup created at $backup_path</p>";
} else {
    echo "<p style='color:red;'>Failed to create backup using mysqldump. Error code: $return_var</p>";
    echo "<p>Please use your hosting control panel (aaPanel/hPanel) to take a manual backup before proceeding with the password migration.</p>";
}

echo "<br><br><a href='admin_dashboard.php'>Back to Dashboard</a>";
?>
