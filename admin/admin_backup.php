<?php
session_start();
require_once '../db.php';
check_access(['admin']); // Only Super Admin

$company_id = (int)get_company_id();
$message = "";

// 1. EXPORT DATABASE (Simple PHP implementation)
if (isset($_GET['action']) && $_GET['action'] == 'export') {
    $tables = array();
    $result = $conn->query("SHOW TABLES");
    while($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }
    
    $return = "";
    foreach($tables as $table) {
        $result = $conn->query("SELECT * FROM $table");
        $num_fields = $result->field_count;
        
        $return .= "DROP TABLE IF EXISTS `$table`;";
        $row2 = $conn->query("SHOW CREATE TABLE `$table`")->fetch_row();
        $return .= "\n\n" . $row2[1] . ";\n\n";
        
        for ($i = 0; $i < $num_fields; $i++) {
            while($row = $result->fetch_row()) {
                $return .= "INSERT INTO `$table` VALUES(";
                for($j=0; $j<$num_fields; $j++) {
                    if (is_null($row[$j])) {
                        $return .= 'NULL';
                    } else {
                        $val = addslashes($row[$j]);
                        $val = str_replace("\n", "\\n", $val);
                        $return .= '"' . $val . '"';
                    }
                    if ($j < ($num_fields - 1)) { $return .= ','; }
                }
                $return .= ");\n";
            }
        }
        $return .= "\n\n\n";
    }
    
    // Save file and download
    $filename = 'db-backup-'.time().'.sql';
    header('Content-Type: application/octet-stream');
    header("Content-Transfer-Encoding: Binary");
    header("Content-disposition: attachment; filename=\"$filename\"");
    echo $return;
    exit;
}

// 2. RESTORE DATABASE (Simple Implementation)
if (isset($_POST['restore'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) die("CSRF failed.");
    
    $file = $_FILES['backup_file']['tmp_name'];
    if (file_exists($file)) {
        $sql = file_get_contents($file);
        // Execute multi-query (Careful with this!)
        if ($conn->multi_query($sql)) {
            do {
                if ($result = $conn->store_result()) { $result->free(); }
            } while ($conn->next_result());
            write_audit_log($conn, 'database.restore', 'Full database restored from uploaded SQL file', 'database', null);
            $message = "<div class='alert alert-success fw-bold'>Database restored successfully!</div>";
        } else {
            $message = "<div class='alert alert-danger fw-bold'>Error restoring: " . $conn->error . "</div>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup & Restore | Smart Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/saas_theme.css?v=<?php echo app_asset_version(); ?>">
</head>
<body>
<div class="saas-sidebar-backdrop" id="saasSidebarBackdrop" aria-hidden="true"></div>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php require_once __DIR__ . '/../includes/saas_sidebar_admin.php'; ?>

        <div class="col-md-10 main-content">
            <div class="dashboard-header">
                <div>
                    <button class="mobile-toggle me-2" onclick="toggleSidebar()"><i class="bi bi-list fs-4"></i></button>
                    <span class="fs-4 fw-bold text-dark"><i class="bi bi-cloud-arrow-up text-primary"></i> System Maintenance</span>
                </div>
            </div>

            <?php if($message != "") echo $message; ?>

            <div class="row">
                <div class="col-md-6">
                    <div class="card p-4 h-100">
                        <h5 class="fw-bold text-primary mb-3"><i class="bi bi-download"></i> Database Backup</h5>
                        <p class="text-muted">Generate a full backup of your attendance, staff, and financial data. We recommend doing this weekly.</p>
                        <div class="mt-4">
                            <a href="?action=export" class="btn btn-primary w-100 fw-bold py-2"><i class="bi bi-file-earmark-arrow-down"></i> Download SQL Backup</a>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card p-4 h-100">
                        <h5 class="fw-bold text-danger mb-3"><i class="bi bi-upload"></i> Restore Database</h5>
                        <p class="text-muted">Restore your system from a previously downloaded .sql backup file. <strong>Warning: This will overwrite all current data!</strong></p>
                        <form method="POST" enctype="multipart/form-data" class="mt-3">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <div class="mb-3">
                                <input type="file" name="backup_file" class="form-control" accept=".sql" required>
                            </div>
                            <button type="submit" name="restore" class="btn btn-danger w-100 fw-bold py-2" onclick="return confirm('Are you absolutely sure? This will delete your current data!')"><i class="bi bi-arrow-counterclockwise"></i> Start Restore Process</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
