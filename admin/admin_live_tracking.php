<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
if (!in_array($_SESSION['role'], ['admin', 'manager', 'hr', 'auditor'], true)) {
    header("Location: ../login.php");
    exit();
}

$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// SQL માં leave_reason ઉમેર્યું છે
$stmt = $conn->prepare("SELECT u.name, u.phone_number, a.punch_in, a.lunch_out, a.lunch_in, a.punch_out, a.is_leave, a.leave_reason, a.total_worked_minutes 
        FROM users u 
        LEFT JOIN attendance_logs a ON u.id = a.user_id AND a.log_date = ?
        WHERE u.role = 'staff'" . staff_sql_active_filter('u') . ' ORDER BY a.punch_in DESC, u.name ASC');
$stmt->bind_param("s", $selected_date);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Tracking | Smart Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
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
                    <span class="fs-4 fw-bold text-dark">Live Tracking</span>
                </div>
                <div class="text-muted fw-bold d-none d-md-block">Date: <?php echo date("d M, Y", strtotime($selected_date)); ?></div>
            </div>

            <div class="card p-3 mb-4 d-print-none">
                <form method="GET" action="" class="row g-3 align-items-center">
                    <div class="col-auto"><label class="fw-bold">Select Date:</label></div>
                    <div class="col-auto"><input type="date" name="date" class="form-control" value="<?php echo $selected_date; ?>" required></div>
                    <div class="col-auto"><button type="submit" class="btn btn-primary fw-bold px-4">View Status</button></div>
                </form>
            </div>

            <div class="card p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle text-center border">
                        <thead class="table-dark">
                            <tr>
                                <th class="text-start ps-4">Staff Member</th>
                                <th>In</th>
                                <th>Lunch Out</th>
                                <th>Lunch In</th>
                                <th>Out</th>
                                <th class="text-nowrap">Total work</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td class='text-start ps-4 fw-bold'><?php echo $row['name']; ?><br><small class='text-muted'><?php echo $row['phone_number']; ?></small></td>
                                <?php if (isset($row['is_leave']) && $row['is_leave'] == 1): ?>
                                    <td colspan="6" class="text-danger fw-bold bg-light">
                                        <i class="bi bi-info-circle"></i> LEAVE: <?php echo htmlspecialchars($row['leave_reason'] ?? ''); ?>
                                        <span class='status-badge bg-danger text-white ms-2'>On Leave</span>
                                    </td>
                                <?php else: ?>
                                    <td class='text-success fw-bold'><?php echo ($row['punch_in'] ? date("h:i A", strtotime($row['punch_in'])) : "-"); ?></td>
                                    <td><?php echo ($row['lunch_out'] ? date("h:i A", strtotime($row['lunch_out'])) : "-"); ?></td>
                                    <td><?php echo ($row['lunch_in'] ? date("h:i A", strtotime($row['lunch_in'])) : "-"); ?></td>
                                    <td class='text-danger fw-bold'><?php echo ($row['punch_out'] ? date("h:i A", strtotime($row['punch_out'])) : "-"); ?></td>
                                    <td class="small">
                                        <?php
                                        $calc_mins = attendance_worked_minutes_from_row($row);
                                        $stored_mins = isset($row['total_worked_minutes']) ? (int) $row['total_worked_minutes'] : 0;
                                        if (empty($row['punch_in'])) {
                                            echo '<span class="text-muted">—</span>';
                                        } elseif (empty($row['punch_out'])) {
                                            echo '<span class="badge bg-warning text-dark">No OUT</span>';
                                        } else {
                                            echo '<span class="fw-bold text-primary">' . htmlspecialchars(format_attendance_duration($calc_mins)) . '</span>';
                                            echo '<div class="text-muted" style="font-size:0.72rem;">' . round($calc_mins / 60, 2) . ' hrs</div>';
                                            if ($stored_mins !== $calc_mins) {
                                                echo '<div class="text-danger" style="font-size:0.72rem;">⚠ DB mismatch</div>';
                                            }
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $status = 'Not Arrived';
                                        $cls = 'bg-secondary';
                                        if (!empty($row['punch_in'])) {
                                            $cls = 'bg-success';
                                            $status = 'Working';
                                            if (!empty($row['punch_out'])) {
                                                $status = 'Shift Ended';
                                                $cls = 'bg-primary';
                                            } elseif (!empty($row['lunch_out']) && empty($row['lunch_in'])) {
                                                $status = 'On Lunch';
                                                $cls = 'bg-warning text-dark';
                                            }
                                        }
                                        echo "<span class='status-badge $cls'>$status</span>";
                                        ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>