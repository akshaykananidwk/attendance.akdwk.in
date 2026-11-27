<?php
session_start();
require_once '../db.php';
check_access(['admin', 'hr', 'auditor']);

$company_id = (int)get_company_id();
$message = "";

// CSV EXPORT LOGIC
if (isset($_GET['type'])) {
    $type = $_GET['type'];
    $filename = $type . "_export_" . date('Y-m-d') . ".csv";
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    if ($type == 'attendance') {
        fputcsv($output, ['Log Date', 'Staff Name', 'Branch', 'Punch In', 'Lunch Out', 'Lunch In', 'Punch Out', 'Minutes Worked', 'Is Leave']);
        $query = "SELECT l.*, u.name as staff_name, b.name as branch_name FROM attendance_logs l 
                  JOIN users u ON l.user_id = u.id 
                  LEFT JOIN branches b ON l.branch_id = b.id 
                  WHERE u.company_id = $company_id ORDER BY l.log_date DESC";
        $res = $conn->query($query);
        while($row = $res->fetch_assoc()) {
            fputcsv($output, [$row['log_date'], $row['staff_name'], $row['branch_name'], $row['punch_in'], $row['lunch_out'], $row['lunch_in'], $row['punch_out'], $row['total_worked_minutes'], $row['is_leave']]);
        }
    } elseif ($type == 'tasks') {
        fputcsv($output, ['Task Title', 'Assigned Staff', 'Priority', 'Status', 'Deadline', 'Created At']);
        $query = "SELECT t.*, GROUP_CONCAT(u.name SEPARATOR ', ') as staff_names 
                  FROM tasks t 
                  LEFT JOIN task_assignments ta ON t.id = ta.task_id 
                  LEFT JOIN users u ON ta.user_id = u.id 
                  WHERE t.company_id = $company_id 
                  GROUP BY t.id 
                  ORDER BY t.id DESC";
        $res = $conn->query($query);
        while($row = $res->fetch_assoc()) {
            fputcsv($output, [$row['title'], $row['staff_names'], $row['priority'], $row['status'], $row['deadline'], $row['created_at']]);
        }
    } elseif ($type == 'payments') {
        fputcsv($output, ['Payment Date', 'Staff Name', 'Amount', 'Method', 'Ref', 'Remarks']);
        $query = "SELECT p.*, u.name as staff_name FROM salary_payments p JOIN users u ON p.user_id = u.id WHERE p.company_id = $company_id ORDER BY p.id DESC";
        $res = $conn->query($query);
        while($row = $res->fetch_assoc()) {
            fputcsv($output, [$row['payment_date'], $row['staff_name'], $row['amount'], $row['method'], $row['transaction_ref'], $row['remarks']]);
        }
    }
    
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Export | Smart Attendance</title>
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
            <span class="fs-4 fw-bold text-dark"><i class="bi bi-file-earmark-spreadsheet text-primary"></i> Data Exports</span>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4 mb-4">
            <div class="card p-4 text-center">
                <i class="bi bi-calendar-check text-primary fs-1 mb-3"></i>
                <h5 class="fw-bold">Attendance Records</h5>
                <p class="text-muted small">Download all logs including punch timings and branches.</p>
                <a href="?type=attendance" class="btn btn-primary fw-bold">Export Attendance</a>
            </div>
        </div>

        <div class="col-md-4 mb-4">
            <div class="card p-4 text-center">
                <i class="bi bi-list-task text-warning fs-1 mb-3"></i>
                <h5 class="fw-bold">Task Productivity</h5>
                <p class="text-muted small">Download task status, priorities, and deadlines report.</p>
                <a href="?type=tasks" class="btn btn-warning fw-bold">Export Tasks</a>
            </div>
        </div>

        <div class="col-md-4 mb-4">
            <div class="card p-4 text-center">
                <i class="bi bi-cash-coin text-success fs-1 mb-3"></i>
                <h5 class="fw-bold">Salary Payouts</h5>
                <p class="text-muted small">Download history of all recorded salary payments.</p>
                <a href="?type=payments" class="btn btn-success fw-bold text-white">Export Payments</a>
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
