<?php
session_start();
require_once 'db.php';
require_once __DIR__ . '/includes/staff_portal_guard.php';

$user_id = (int) $_SESSION['user_id'];

// Get Selected Month and Year (Default to Current)
$current_month = isset($_GET['month']) ? str_pad($_GET['month'], 2, '0', STR_PAD_LEFT) : date('m');
$current_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

$start_date_of_month = "$current_year-$current_month-01";
$month_year_format = "$current_year-$current_month";

// Opening balance: includes/salary_engine.php (via db.php)

// Fetch Staff Info
$stmt = $conn->prepare("SELECT name, phone_number, salary_type, salary_amount, join_date, company_id, branch_id FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

$company_id = (int)($user_data['company_id'] ?? 0);
$branch_id = (int)($user_data['branch_id'] ?? 0);

attendance_run_pending_automation($conn, $company_id > 0 ? $company_id : null);

// All salary numbers come from the single payroll engine (includes/payroll.php)
$P = payroll_slip($conn, $user_id, (int) $current_year, (int) $current_month);
$final_rate = $P['rate'];
$rate_badge = $P['rate_source'] !== 'Base rate' ? "<span class='badge bg-primary ms-2'>" . htmlspecialchars($P['rate_source']) . '</span>' : '';
$month_mins = $P['minutes'];
$days_present = $P['days_present'];
$stats = ['month_mins' => $P['minutes'], 'days_present' => $P['days_present'], 'leave_days' => $P['leave_days']];
$month_upad = $P['month_upad'];
$month_jama = $P['month_jama'];
$opening_balance = $P['opening_balance'];
$end_of_selected_month = date('Y-m-t', strtotime("$current_year-$current_month-01"));
$subsequent_upad = $P['subsequent_upad'];
$subsequent_jama = $P['subsequent_jama'];
$gross_salary = $P['gross'];
$penalty_days = $P['penalty_days'];
$penalty_amount = $P['penalty_amount'];
$resignation_withhold = $P['resignation_withhold'];
$net_payable = $P['net'];

// Attendance Logs (Filtered by Selected Month)
$stmt_logs = $conn->prepare("SELECT * FROM attendance_logs WHERE user_id = ? AND MONTH(log_date) = ? AND YEAR(log_date) = ? ORDER BY log_date DESC");
$stmt_logs->bind_param("iss", $user_id, $current_month, $current_year);
$stmt_logs->execute();
$logs_result = $stmt_logs->get_result();

// Latest Announcements
$announcements = $conn->query("SELECT * FROM announcements WHERE company_id = $company_id AND (branch_id = $branch_id OR branch_id IS NULL) AND (visibility = 'all' OR visibility = 'staff') ORDER BY id DESC LIMIT 3");

// Active Tasks
$tasks_count = $conn->query("SELECT COUNT(*) as total FROM tasks t JOIN task_assignments ta ON t.id = ta.task_id WHERE ta.user_id = $user_id AND t.status != 'Completed'")->fetch_assoc()['total'];
$latest_task = $conn->query("SELECT t.* FROM tasks t JOIN task_assignments ta ON t.id = ta.task_id WHERE ta.user_id = $user_id AND t.status != 'Completed' ORDER BY t.priority='High' DESC, t.deadline ASC LIMIT 1")->fetch_assoc();

// Advance/Credit History
$stmt_adv = $conn->prepare("SELECT * FROM advance_payments WHERE user_id = ? AND MONTH(date) = ? AND YEAR(date) = ? ORDER BY date DESC");
$stmt_adv->bind_param("iss", $user_id, $current_month, $current_year);
$stmt_adv->execute();
$adv_logs = $stmt_adv->get_result();

// TODAY'S ATTENDANCE STATUS
$today_date = date('Y-m-d');
$stmt_today = $conn->prepare("SELECT * FROM attendance_logs WHERE user_id = ? AND log_date = ?");
$stmt_today->bind_param("is", $user_id, $today_date);
$stmt_today->execute();
$today_att = $stmt_today->get_result()->fetch_assoc();
$stmt_today->close();

$csrf_token = generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard | <?php echo htmlspecialchars(app_brand()); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/saas_theme.css?v=<?php echo app_asset_version(); ?>">
</head>
<body>
<div class="saas-sidebar-backdrop" id="saasSidebarBackdrop" aria-hidden="true"></div>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php require_once __DIR__ . '/includes/saas_sidebar_staff.php'; ?>

        <div class="col-md-10 main-content">

            <?php if (!empty($_SESSION['impersonator_id'])): ?>
            <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center mb-3">
                <span><i class="bi bi-person-badge"></i> Admin view: logged in as <strong><?php echo htmlspecialchars($user_data['name']); ?></strong></span>
                <a href="admin/staff_access.php?stop=1" class="btn btn-sm btn-dark fw-bold">Return to admin</a>
            </div>
            <?php endif; ?>

            <?php if (staff_is_readonly_session()): ?>
            <div class="alert alert-secondary mb-3">
                <i class="bi bi-archive"></i> <strong>Archive mode</strong> — view your history only. Punch and new leave are disabled.
            </div>
            <?php endif; ?>

            <?php if (!empty($_GET['readonly'])): ?>
            <div class="alert alert-warning">This action is not available in archive mode.</div>
            <?php endif; ?>

            <div class="dashboard-header">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <button type="button" class="mobile-toggle me-1" onclick="toggleSidebar()" aria-label="Open menu"><i class="bi bi-list fs-4"></i></button>
                    <span class="fs-4 fw-bold text-dark">Welcome, <?php echo htmlspecialchars($user_data['name']); ?></span>
                </div>
                <button onclick="window.print()" class="btn btn-danger btn-sm fw-bold d-print-none">
                    <i class="bi bi-file-earmark-pdf-fill"></i> Download Report
                </button>
            </div>

            <?php if (!staff_is_readonly_session()): ?>
            <div class="card p-4 mb-4 border-0 shadow-sm text-center d-print-none" style="background: linear-gradient(135deg, #e0f7fa 0%, #e8f5e9 100%);">
                <h4 class="fw-bold text-dark mb-3"><i class="bi bi-clock-history"></i> Today's Attendance</h4>
                <div class="d-flex justify-content-center gap-3 flex-wrap">
                    
                    <?php if (!$today_att): ?>
                        <form action="attendance_action.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="punch_in">
                            <button type="submit" class="btn btn-success btn-lg fw-bold shadow-sm">
                                <i class="bi bi-box-arrow-in-right"></i> Punch In
                            </button>
                        </form>
                    
                    <?php elseif ($today_att['punch_in'] && !$today_att['lunch_out'] && !$today_att['punch_out']): ?>
                        <form action="attendance_action.php" method="POST" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="lunch_out">
                            <button type="submit" class="btn btn-warning btn-lg fw-bold shadow-sm">
                                <i class="bi bi-cup-hot"></i> Lunch Out
                            </button>
                        </form>
                        <form action="attendance_action.php" method="POST" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="punch_out">
                            <button type="submit" class="btn btn-danger btn-lg fw-bold shadow-sm">
                                <i class="bi bi-box-arrow-left"></i> Punch Out
                            </button>
                        </form>

                    <?php elseif ($today_att['lunch_out'] && !$today_att['lunch_in']): ?>
                        <form action="attendance_action.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="lunch_in">
                            <button type="submit" class="btn btn-info btn-lg text-white fw-bold shadow-sm">
                                <i class="bi bi-arrow-return-right"></i> Lunch In
                            </button>
                        </form>

                    <?php elseif ($today_att['lunch_in'] && !$today_att['punch_out']): ?>
                        <form action="attendance_action.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="punch_out">
                            <button type="submit" class="btn btn-danger btn-lg fw-bold shadow-sm">
                                <i class="bi bi-box-arrow-left"></i> Punch Out
                            </button>
                        </form>

                    <?php elseif ($today_att['punch_out']): ?>
                        <div class="alert alert-success m-0 fw-bold w-100">
                            <i class="bi bi-check-circle-fill"></i> Your shift is completed for today. Total Time Calculated!
                        </div>
                    <?php endif; ?>

                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-light border mb-4 d-print-none text-center">
                <i class="bi bi-calendar3"></i> Use the month report below to view your past attendance and salary history.
            </div>
            <?php endif; ?>

            <div class="row d-print-none">
                <div class="col-md-6 mb-4">
                    <div class="card p-3 h-100 border-start border-4 border-warning">
                        <h6 class="fw-bold text-warning mb-2"><i class="bi bi-megaphone"></i> Latest Announcements</h6>
                        <?php if($announcements->num_rows > 0): ?>
                            <?php while($a = $announcements->fetch_assoc()): ?>
                                <div class="mb-2 pb-2 border-bottom">
                                    <div class="fw-bold small"><?php echo htmlspecialchars($a['title']); ?></div>
                                    <div class="text-muted" style="font-size: 0.8rem;"><?php echo substr(htmlspecialchars($a['content']), 0, 80); ?>...</div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p class="small text-muted m-0">No new announcements.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-6 mb-4">
                    <div class="card p-3 h-100 border-start border-4 border-info">
                        <div class="d-flex justify-content-between">
                            <h6 class="fw-bold text-info mb-2"><i class="bi bi-list-task"></i> Active Tasks</h6>
                            <span class="badge bg-info"><?php echo $tasks_count; ?> Pending</span>
                        </div>
                        <?php if($latest_task): ?>
                            <div class="fw-bold small"><?php echo htmlspecialchars($latest_task['title']); ?></div>
                            <div class="text-danger small fw-bold">Deadline: <?php echo date("d M", strtotime($latest_task['deadline'])); ?></div>
                            <a href="staff_tasks.php" class="btn btn-sm btn-outline-info mt-2 py-1">View All Tasks</a>
                        <?php else: ?>
                            <p class="small text-muted m-0">All clear! No pending tasks.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="card p-3 mb-4 filter-section bg-light border">
                <form method="GET" class="row align-items-end g-2">
                    <div class="col-md-3 col-6">
                        <label class="form-label fw-bold text-muted mb-1"><i class="bi bi-calendar-month"></i> Select Month</label>
                        <select name="month" class="form-select fw-bold">
                            <?php for ($m=1; $m<=12; $m++) {
                                $mv = str_pad($m, 2, '0', STR_PAD_LEFT);
                                $sel = ($mv == $current_month) ? 'selected' : '';
                                echo "<option value='$mv' $sel>".date("F", mktime(0,0,0,$m,1))."</option>";
                            } ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label fw-bold text-muted mb-1">Year</label>
                        <select name="year" class="form-select fw-bold">
                            <?php for ($y=date('Y')-2; $y<=date('Y')+1; $y++) {
                                $sel = ($y == $current_year) ? 'selected' : '';
                                echo "<option value='$y' $sel>$y</option>";
                            } ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-12">
                        <button type="submit" class="btn btn-primary w-100 fw-bold"><i class="bi bi-search"></i> View Record</button>
                    </div>
                </form>
            </div>

            <div class="card p-4 mb-4 border-start border-4 border-primary">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold m-0"><i class="bi bi-calculator text-primary"></i> Salary Breakdown (<?php echo date("M Y", strtotime($start_date_of_month)); ?>)</h5>
                    <div class="fw-bold fs-6">
                        Active Rate: <span class="text-dark">₹<?php echo $final_rate; ?> / <?php echo ucfirst($user_data['salary_type']); ?></span>
                        <?php echo $rate_badge; ?>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-bordered text-center align-middle mb-0">
                        <tr class="table-light">
                            <th class="py-3"><i class="bi bi-clock-history text-secondary"></i> Prev. Balance</th>
                            <th class="py-3"><i class="bi bi-wallet2 text-primary"></i> Earned (This Month)</th>
                            <th class="py-3"><i class="bi bi-bank text-danger"></i> Upad/Jama</th>
                            <th class="py-3"><i class="bi bi-exclamation-octagon text-danger"></i> Penalties</th>
                            <th class="bg-success text-white py-3">Total Net Payable</th>
                        </tr>
                        <tr>
                            <td class="fs-5 fw-bold <?php echo ($opening_balance < 0) ? 'text-danger' : (($opening_balance > 0) ? 'text-success' : 'text-muted'); ?>">
                                <?php echo ($opening_balance >= 0) ? '+' : ''; ?>₹<?php echo number_format($opening_balance, 2); ?>
                            </td>
                            <td class="fs-5 fw-bold text-primary">₹<?php echo number_format($gross_salary, 2); ?></td>
                            <td class="fs-6 fw-bold">
                                <?php if($month_jama > 0) echo "<span class='text-success'>+₹" . number_format($month_jama, 2) . " (Jama)</span><br>"; ?>
                                <?php if($month_upad > 0) echo "<span class='text-danger'>-₹" . number_format($month_upad, 2) . " (Upad)</span><br>"; ?>
                                <?php if($subsequent_jama > 0) echo "<span class='text-success'>+₹" . number_format($subsequent_jama, 2) . " (Subsequent Jama)</span><br>"; ?>
                                <?php if($subsequent_upad > 0) echo "<span class='text-danger'>-₹" . number_format($subsequent_upad, 2) . " (Subsequent Upad)</span>"; ?>
                                <?php if($month_jama == 0 && $month_upad == 0 && $subsequent_jama == 0 && $subsequent_upad == 0) echo "<span class='text-muted'>0.00</span>"; ?>
                            </td>
                            <td class="fs-6 fw-bold">
                                <?php if ($resignation_withhold): ?>
                                    <span class="text-danger">Rule 13 (No notice)</span><br>
                                    <span class="text-danger">-₹<?php echo number_format($penalty_amount, 2); ?></span>
                                <?php elseif ($penalty_days > 0): ?>
                                    <span class="text-danger"><?php echo (float)$penalty_days; ?> day(s)</span><br>
                                    <span class="text-danger">-₹<?php echo number_format($penalty_amount, 2); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">0.00</span>
                                <?php endif; ?>
                            </td>
                            <td class="fs-4 fw-bold <?php echo ($net_payable < 0) ? 'text-danger bg-light' : 'text-success bg-light'; ?>">
                                ₹<?php echo number_format($net_payable, 2); ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-8 mb-4">
                    <div class="card p-4 h-100">
                        <h5 class="fw-bold mb-3 text-primary border-bottom pb-2"><i class="bi bi-calendar-check"></i> Attendance Record</h5>
                        <div class="table-responsive">
                            <table class="table table-hover table-bordered text-center align-middle mb-0" style="font-size: 0.95rem;">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Date</th>
                                        <th>IN</th>
                                        <th>Lunch Out</th>
                                        <th>Lunch In</th>
                                        <th>OUT</th>
                                        <th>Total Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if($logs_result->num_rows > 0): ?>
                                        <?php while($row = $logs_result->fetch_assoc()): ?>
                                            <tr>
                                                <td><strong><?php echo date("d M", strtotime($row['log_date'])); ?></strong></td>
                                                <?php if ($row['is_leave'] == 1): ?>
                                                    <td colspan="4" class="text-danger fw-bold bg-light">ON LEAVE (Approved)</td>
                                                    <td>-</td>
                                                <?php else: ?>
                                                    <td class="text-success fw-bold"><?php echo $row['punch_in'] ? date("h:i A", strtotime($row['punch_in'])) : '-'; ?></td>
                                                    <td><?php echo $row['lunch_out'] ? date("h:i A", strtotime($row['lunch_out'])) : '-'; ?></td>
                                                    <td><?php echo $row['lunch_in'] ? date("h:i A", strtotime($row['lunch_in'])) : '-'; ?></td>
                                                    <td class="text-danger fw-bold"><?php echo $row['punch_out'] ? date("h:i A", strtotime($row['punch_out'])) : '-'; ?></td>
                                                    <?php 
                                                        $h = floor($row['total_worked_minutes'] / 60);
                                                        $m = $row['total_worked_minutes'] % 60;
                                                    ?>
                                                    <td class="bg-light fw-bold"><?php echo ($h > 0 || $m > 0) ? "{$h}h {$m}m" : "-"; ?></td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="text-muted py-4">No attendance records found for this month.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 mb-4">
                    <div class="card p-4 h-100">
                        <h5 class="fw-bold mb-3 text-warning border-bottom pb-2"><i class="bi bi-cash-stack"></i> Upad & Jama History</h5>
                        <div class="table-responsive">
                            <table class="table table-bordered text-center align-middle mb-0">
                                <thead class="table-secondary">
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if($adv_logs->num_rows > 0): ?>
                                        <?php while($adv = $adv_logs->fetch_assoc()): ?>
                                            <?php 
                                                $is_jama = (isset($adv['transaction_type']) && $adv['transaction_type'] == 'Jama');
                                                $color = $is_jama ? 'text-success' : 'text-danger';
                                                $sign = $is_jama ? '+' : '-';
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold"><?php echo date("d M", strtotime($adv['date'])); ?></div>
                                                    <small class="text-muted" style="font-size: 0.75rem;"><?php echo htmlspecialchars($adv['description']); ?></small>
                                                </td>
                                                <td class="<?php echo $color; ?> fw-bold">
                                                    <?php echo $sign; ?>₹<?php echo number_format($adv['amount'], 2); ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="2" class="text-muted py-4">No transactions this month.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/includes/saas_sidebar_toggle.php'; ?>
</body>
</html>