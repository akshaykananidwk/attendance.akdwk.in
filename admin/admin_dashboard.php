<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
deny_auditor_to_reports();
if (!in_array($_SESSION['role'], ['admin', 'manager', 'hr'], true)) {
    header('Location: ../login.php');
    exit();
}

// --- AUTO ADD DAILY OFFICE RENT LOGIC ---
$company_id = get_company_id();
$branch_id = get_branch_id();

$set_q = $conn->query("SELECT monthly_office_rent FROM settings LIMIT 1"); // Settings might need to be multi-tenant later, for now global
if ($set_q && $set_q->num_rows > 0) {
    $settings_data = $set_q->fetch_assoc();
    $monthly_rent = floatval($settings_data['monthly_office_rent']);

    if ($monthly_rent > 0) {
        $today_rent_date = date('Y-m-d');
        
        $stmt_check = $conn->prepare("SELECT id FROM office_expenses WHERE exp_date = ? AND description = 'Daily Office Rent' AND company_id = ?");
        $stmt_check->bind_param("si", $today_rent_date, $company_id);
        $stmt_check->execute();
        $check_rent = $stmt_check->get_result();

        if ($check_rent->num_rows == 0) {
            $total_days_in_month = date('t'); 
            $daily_rent = round($monthly_rent / $total_days_in_month, 2);

            $stmt_ins = $conn->prepare("INSERT INTO office_expenses (exp_date, amount, description, company_id, branch_id) VALUES (?, ?, 'Daily Office Rent', ?, ?)");
            $stmt_ins->bind_param("sdii", $today_rent_date, $daily_rent, $company_id, $branch_id);
            $stmt_ins->execute();
            $stmt_ins->close();
        }
        $stmt_check->close();
    }
}
// ----------------------------------------

$company_id = (int) get_company_id();

$activeStaffSql = staff_sql_active_filter('');
$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM users WHERE role = 'staff' AND company_id = ?{$activeStaffSql}");
$stmt->bind_param('i', $company_id);
$stmt->execute();
$total_staff = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

$stmt = $conn->prepare('SELECT COUNT(*) AS total FROM branches WHERE company_id = ?');
$stmt->bind_param('i', $company_id);
$stmt->execute();
$total_branches = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM tasks WHERE company_id = ? AND status != 'Completed'");
$stmt->bind_param('i', $company_id);
$stmt->execute();
$pending_tasks = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

attendance_run_pending_automation($conn, $company_id);

$todayStats = get_today_attendance_stats($conn, $company_id);
$present_staff = $todayStats['present'];
$leave_staff = $todayStats['on_leave'];
$half_day_staff = $todayStats['half_day'];
$late_staff = $todayStats['late'];
$absent_staff = $todayStats['absent'];

$stmt = $conn->prepare(
    "SELECT u.*, b.name AS branch_name FROM users u
     LEFT JOIN branches b ON u.branch_id = b.id
     WHERE u.role = 'staff' AND u.company_id = ?" . staff_sql_active_filter('u') . '
     ORDER BY u.id DESC'
);
$stmt->bind_param('i', $company_id);
$stmt->execute();
$staff_result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Smart Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../assets/saas_theme.css">
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
                    <span class="fs-4 fw-bold text-dark">Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
                </div>
                <div class="text-muted fw-bold hide-mobile">
                    <i class="bi bi-calendar3"></i> <?php echo date('d F, Y'); ?> &nbsp;|&nbsp; <i class="bi bi-clock"></i> <span id="clock"></span>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-5 mb-4">
                    <div class="card h-100 p-4">
                        <h5 class="fw-bold text-center mb-3"><i class="bi bi-pie-chart-fill text-primary"></i> Today's Attendance Status</h5>
                        <div style="height: 250px; margin: 0 auto; width: 100%;">
                            <canvas id="attendanceChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7 mb-4">
                    <div class="row g-3 h-100">
                        <div class="col-md-6">
                            <div class="card p-4 h-100 d-flex flex-column justify-content-center align-items-center" style="border-left: 5px solid #0d6efd;">
                                <h2 class="fw-bold text-primary mb-0"><?php echo $total_branches; ?></h2>
                                <span class="text-muted fw-bold">Active Branches</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card p-4 h-100 d-flex flex-column justify-content-center align-items-center" style="border-left: 5px solid #6610f2;">
                                <h2 class="fw-bold text-indigo mb-0"><?php echo $pending_tasks; ?></h2>
                                <span class="text-muted fw-bold">Pending Tasks</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card p-4 h-100 d-flex flex-column justify-content-center align-items-center" style="border-left: 5px solid #25D366;">
                                <h2 class="fw-bold text-success mb-0"><?php echo $present_staff; ?></h2>
                                <span class="text-muted fw-bold small">Present</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card p-4 h-100 d-flex flex-column justify-content-center align-items-center" style="border-left: 5px solid #ffc107;">
                                <h2 class="fw-bold text-warning mb-0"><?php echo $leave_staff; ?></h2>
                                <span class="text-muted fw-bold small">On Leave</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card p-4 h-100 d-flex flex-column justify-content-center align-items-center" style="border-left: 5px solid #dc3545;">
                                <h2 class="fw-bold text-danger mb-0"><?php echo $absent_staff; ?></h2>
                                <span class="text-muted fw-bold small">Absent</span>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="card p-3 h-100 d-flex flex-column justify-content-center align-items-center" style="border-left: 5px solid #0dcaf0;">
                                <h2 class="fw-bold text-info mb-0"><?php echo $late_staff; ?></h2>
                                <span class="text-muted fw-bold small">Late</span>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="card p-3 h-100 d-flex flex-column justify-content-center align-items-center" style="border-left: 5px solid #6f42c1;">
                                <h2 class="fw-bold mb-0" style="color:#6f42c1;"><?php echo $half_day_staff; ?></h2>
                                <span class="text-muted fw-bold small">Half Day</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0"><i class="bi bi-people-fill text-primary"></i> Registered Staff Overview</h5>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover table-bordered align-middle text-center border">
                        <thead class="table-dark">
                            <tr>
                                <th>Staff Name</th>
                                <th>Branch</th>
                                <th>WhatsApp Number</th>
                                <th>Salary Base</th>
                                <th>Message Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ($staff_result && $staff_result->num_rows > 0) {
                                while($row = $staff_result->fetch_assoc()) {
                                    $msg_badge = ($row['msg_active'] == 1) ? "<span class='badge bg-success'><i class='bi bi-check-circle'></i> Active</span>" : "<span class='badge bg-danger'><i class='bi bi-x-circle'></i> Disabled</span>";
                                    echo "<tr>
                                            <td class='fw-bold text-start ps-3'><i class='bi bi-person-circle text-secondary'></i> {$row['name']}</td>
                                            <td><span class='badge bg-info'>{$row['branch_name']}</span></td>
                                            <td>{$row['phone_number']}</td>
                                            <td><span class='badge bg-secondary'>" . ucfirst($row['salary_type']) . "</span><br>₹{$row['salary_amount']}</td>
                                            <td>{$msg_badge}</td>
                                          </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='4' class='text-muted py-4'>No staff members found.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Live Clock
    function updateClock() {
        var now = new Date();
        document.getElementById('clock').innerText = now.toLocaleTimeString();
    }
    setInterval(updateClock, 1000);
    updateClock();

    // Chart.js Setup for Today's Attendance
    const ctx = document.getElementById('attendanceChart').getContext('2d');
    const attendanceChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Present', 'On Leave', 'Late', 'Half Day', 'Absent'],
            datasets: [{
                data: [<?php echo $present_staff; ?>, <?php echo $leave_staff; ?>, <?php echo $late_staff; ?>, <?php echo $half_day_staff; ?>, <?php echo $absent_staff; ?>],
                backgroundColor: ['#25D366', '#ffc107', '#0dcaf0', '#6f42c1', '#dc3545'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
</script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>