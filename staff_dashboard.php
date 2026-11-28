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
    <?php echo app_pwa_head(); ?>
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

            <?php
            $isAuto = ($P['user']['attendance_mode'] ?? 'self') === 'auto';
            $tf = static fn ($v) => $v ? date('h:i A', strtotime((string) $v)) : '';
            $todayState = !$today_att ? 'none' : (!empty($today_att['punch_out']) ? 'done' : ((!empty($today_att['lunch_out']) && empty($today_att['lunch_in'])) ? 'lunch' : 'working'));
            $stateLabel = ['none' => ['Not punched in yet', 'gray'], 'working' => ['Working', 'solid-green'], 'lunch' => ['On lunch break', 'solid-amber'], 'done' => ['Shift completed', 'solid-blue']][$todayState];
            $monthLabel = date('F Y', strtotime($start_date_of_month));
            $netCls = $net_payable < 0 ? 'red' : 'green';
            ?>
            <?php echo ui_hero(
                'Welcome, ' . ui_e($user_data['name']) . ' 👋',
                $isAuto ? 'Your attendance is marked automatically every working day.' : "Here's your attendance and salary summary.",
                '',
                'temple',
                '<button onclick="window.print()" class="btn btn-danger fw-bold d-print-none"><i class="bi bi-file-earmark-pdf-fill"></i> Download Report</button>'
            ); ?>

            <?php if (!staff_is_readonly_session()): ?>
            <div class="ui-panel mb-3 d-print-none">
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <div class="flex-grow-1">
                        <h5 class="ui-panel-title mb-2"><i class="bi bi-clock-history"></i> Today's Attendance <span class="ui-pill <?php echo $stateLabel[1]; ?> ms-1"><?php echo $stateLabel[0]; ?></span></h5>
                        <div class="d-flex flex-wrap gap-2">
                            <span class="ui-pill <?php echo !empty($today_att['punch_in']) ? 'green' : 'gray'; ?>"><i class="bi bi-box-arrow-in-right"></i> In <?php echo $tf($today_att['punch_in'] ?? null) ?: '—'; ?></span>
                            <span class="ui-pill gray"><i class="bi bi-cup-hot"></i> Lunch <?php echo $tf($today_att['lunch_out'] ?? null) ?: '—'; ?> – <?php echo $tf($today_att['lunch_in'] ?? null) ?: '—'; ?></span>
                            <span class="ui-pill <?php echo !empty($today_att['punch_out']) ? 'red' : 'gray'; ?>"><i class="bi bi-box-arrow-right"></i> Out <?php echo $tf($today_att['punch_out'] ?? null) ?: '—'; ?></span>
                            <?php if ($todayState === 'working' || $todayState === 'lunch'): ?><span class="ui-pill blue"><i class="bi bi-stopwatch"></i> <span id="liveTimer" data-in="<?php echo ui_e(date('Y-m-d') . ' ' . $today_att['punch_in']); ?>">…</span></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <?php
                        $btn = static function (string $action, string $cls, string $icon, string $label) use ($csrf_token): string {
                            return '<form action="attendance_action.php" method="POST" class="d-inline punch-form"><input type="hidden" name="csrf_token" value="' . ui_e($csrf_token) . '"><input type="hidden" name="action" value="' . $action . '">'
                                . '<button type="submit" class="btn ' . $cls . ' btn-lg fw-bold px-4 shadow-sm"><i class="bi ' . $icon . '"></i> ' . $label . '</button></form>';
                        };
                        if ($isAuto && $todayState !== 'working' && $todayState !== 'lunch') {
                            echo '<span class="ui-pill purple"><i class="bi bi-magic"></i> Automatic attendance</span>';
                        } elseif ($todayState === 'none') {
                            echo $btn('punch_in', 'btn-success', 'bi-box-arrow-in-right', 'Punch In');
                        } elseif ($todayState === 'working' && empty($today_att['lunch_out'])) {
                            echo $btn('lunch_out', 'btn-warning', 'bi-cup-hot', 'Lunch Out') . $btn('punch_out', 'btn-danger', 'bi-box-arrow-left', 'Punch Out');
                        } elseif ($todayState === 'lunch') {
                            echo $btn('lunch_in', 'btn-info text-white', 'bi-arrow-return-right', 'Lunch In');
                        } elseif ($todayState === 'working') {
                            echo $btn('punch_out', 'btn-danger', 'bi-box-arrow-left', 'Punch Out');
                        } else {
                            echo '<span class="ui-pill solid-green"><i class="bi bi-check-circle-fill"></i> Shift completed for today</span>';
                        }
                        ?>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-light border mb-3 d-print-none text-center"><i class="bi bi-calendar3"></i> Use the month report below to view your past attendance and salary history.</div>
            <?php endif; ?>

            <div class="ui-stats">
                <?php
                echo ui_stat($P['days_present'] . ($P['paid_off_days'] ? ' + ' . $P['paid_off_days'] : ''), 'Days present' . ($P['paid_off_days'] ? ' + paid off' : ''), 'bi-calendar-check', 'green');
                echo ui_stat(format_attendance_duration((int) $P['minutes']), 'Hours worked', 'bi-stopwatch', 'blue');
                echo ui_stat('₹' . number_format($gross_salary, 0), 'Earned (' . date('M', strtotime($start_date_of_month)) . ')', 'bi-wallet2', 'cyan');
                echo ui_stat((float) $penalty_days, 'Penalty days', 'bi-exclamation-octagon', $penalty_days > 0 ? 'red' : 'purple');
                echo ui_stat('₹' . number_format($net_payable, 0), 'Net payable', 'bi-cash-coin', $net_payable < 0 ? 'red' : 'teal');
                ?>
            </div>

            <div class="ui-panel mb-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h5 class="ui-panel-title"><i class="bi bi-calculator"></i> Salary Breakdown <span class="text-muted fw-normal small">(<?php echo $monthLabel; ?>)</span></h5>
                    <form method="GET" class="d-flex gap-2 d-print-none">
                        <select name="month" class="form-select form-select-sm fw-semibold" style="width:auto">
                            <?php for ($m = 1; $m <= 12; $m++) {
                                $mv = str_pad((string) $m, 2, '0', STR_PAD_LEFT);
                                echo "<option value='$mv'" . ($mv == $current_month ? ' selected' : '') . '>' . date('F', mktime(0, 0, 0, $m, 1)) . '</option>';
                            } ?>
                        </select>
                        <select name="year" class="form-select form-select-sm fw-semibold" style="width:auto">
                            <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++) {
                                echo "<option value='$y'" . ($y == $current_year ? ' selected' : '') . ">$y</option>";
                            } ?>
                        </select>
                        <button class="btn btn-primary btn-sm fw-bold px-3"><i class="bi bi-search"></i> View</button>
                    </form>
                </div>
                <div class="small text-muted mb-3">Active rate: <b class="text-body">₹<?php echo number_format((float) $final_rate, 2); ?> / <?php echo ucfirst((string) $user_data['salary_type']); ?></b> <?php echo $rate_badge; ?></div>
                <div class="row g-2 text-center">
                    <div class="col-6 col-md"><div class="p-3 rounded-4 border h-100"><div class="small text-muted fw-semibold"><i class="bi bi-clock-history"></i> Prev. balance</div><div class="fs-5 fw-bold <?php echo $opening_balance < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo ($opening_balance >= 0 ? '+' : '') . '₹' . number_format($opening_balance, 2); ?></div></div></div>
                    <div class="col-6 col-md"><div class="p-3 rounded-4 border h-100"><div class="small text-muted fw-semibold"><i class="bi bi-wallet2"></i> Earned</div><div class="fs-5 fw-bold text-primary">₹<?php echo number_format($gross_salary, 2); ?></div></div></div>
                    <div class="col-6 col-md"><div class="p-3 rounded-4 border h-100"><div class="small text-muted fw-semibold"><i class="bi bi-bank"></i> Upad / Jama</div>
                        <div class="fw-bold">
                            <?php if ($month_jama > 0) echo "<div class='text-success'>+₹" . number_format($month_jama, 2) . ' Jama</div>'; ?>
                            <?php if ($month_upad > 0) echo "<div class='text-danger'>−₹" . number_format($month_upad, 2) . ' Upad</div>'; ?>
                            <?php if ($subsequent_jama > 0) echo "<div class='text-success small'>+₹" . number_format($subsequent_jama, 2) . ' later Jama</div>'; ?>
                            <?php if ($subsequent_upad > 0) echo "<div class='text-danger small'>−₹" . number_format($subsequent_upad, 2) . ' later Upad</div>'; ?>
                            <?php if ($month_jama == 0 && $month_upad == 0 && $subsequent_jama == 0 && $subsequent_upad == 0) echo "<span class='text-muted'>₹0.00</span>"; ?>
                        </div></div></div>
                    <div class="col-6 col-md"><div class="p-3 rounded-4 border h-100"><div class="small text-muted fw-semibold"><i class="bi bi-exclamation-octagon"></i> Penalties</div>
                        <div class="fw-bold <?php echo $penalty_amount > 0 ? 'text-danger' : 'text-muted'; ?>">
                            <?php if ($resignation_withhold): ?>Rule 13 (no notice)<br>−₹<?php echo number_format($penalty_amount, 2); ?>
                            <?php elseif ($penalty_days > 0): ?><?php echo (float) $penalty_days; ?> day(s)<br>−₹<?php echo number_format($penalty_amount, 2); ?>
                            <?php else: ?>₹0.00<?php endif; ?>
                        </div></div></div>
                    <div class="col-12 col-md"><div class="p-3 rounded-4 h-100 ui-c-<?php echo $net_payable < 0 ? 'red' : 'green'; ?>"><div class="small fw-bold">Total net payable</div><div class="fs-3 fw-bold <?php echo $net_payable < 0 ? 'text-danger' : 'text-success'; ?>">₹<?php echo number_format($net_payable, 2); ?></div></div></div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="ui-panel h-100">
                        <h5 class="ui-panel-title mb-3"><i class="bi bi-calendar-check"></i> Attendance Record <span class="text-muted fw-normal small">(<?php echo $monthLabel; ?>)</span></h5>
                        <div class="table-responsive">
                            <table class="ui-cards-sm ui-table text-center">
                                <thead><tr><th>Date</th><th>In</th><th>Lunch Out</th><th>Lunch In</th><th>Out</th><th>Total Time</th></tr></thead>
                                <tbody>
                                <?php if ($logs_result->num_rows > 0): while ($row = $logs_result->fetch_assoc()): ?>
                                    <tr>
                                        <td class="text-nowrap"><b><?php echo date('d M', strtotime($row['log_date'])); ?></b> <span class="text-muted small"><?php echo date('D', strtotime($row['log_date'])); ?></span></td>
                                        <?php if ((float) $row['is_leave'] >= 1): ?>
                                            <td colspan="4"><span class="ui-pill amber"><i class="bi bi-calendar2-week"></i> On leave (approved)</span></td><td>—</td>
                                        <?php elseif (empty($row['punch_in'])): ?>
                                            <td colspan="4"><span class="ui-pill red"><i class="bi bi-x-circle"></i> Absent<?php echo !empty($row['leave_reason']) ? ' · ' . ui_e(mb_substr((string) $row['leave_reason'], 0, 60)) : ''; ?></span></td><td>—</td>
                                        <?php else: $mins = (int) $row['total_worked_minutes']; ?>
                                            <td><span class="ui-pill green"><?php echo $tf($row['punch_in']); ?></span></td>
                                            <td><?php echo $row['lunch_out'] ? '<span class="ui-pill gray">' . $tf($row['lunch_out']) . '</span>' : '<span class="text-muted">—</span>'; ?></td>
                                            <td><?php echo $row['lunch_in'] ? '<span class="ui-pill gray">' . $tf($row['lunch_in']) . '</span>' : '<span class="text-muted">—</span>'; ?></td>
                                            <td><?php echo $row['punch_out'] ? '<span class="ui-pill red">' . $tf($row['punch_out']) . '</span>' : '<span class="ui-pill amber">No OUT</span>'; ?></td>
                                            <td><?php echo $mins > 0 ? '<span class="ui-pill blue">' . intdiv($mins, 60) . 'h ' . ($mins % 60) . 'm</span>' : '<span class="text-muted">—</span>'; ?><?php echo str_contains((string) $row['leave_reason'], '[Auto present]') ? '<div class="small text-muted">auto</div>' : ''; ?></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endwhile; else: ?>
                                    <tr><td colspan="6" class="text-muted py-4">No attendance records for this month.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 d-flex flex-column gap-3">
                    <div class="ui-panel">
                        <h5 class="ui-panel-title mb-3"><i class="bi bi-cash-stack"></i> Upad &amp; Jama</h5>
                        <?php if ($adv_logs->num_rows > 0): while ($adv = $adv_logs->fetch_assoc()): $isJ = ($adv['transaction_type'] ?? '') === 'Jama'; ?>
                            <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                <div><b><?php echo date('d M', strtotime($adv['date'])); ?></b><div class="small text-muted"><?php echo ui_e($adv['description'] ?? ''); ?></div></div>
                                <span class="fw-bold <?php echo $isJ ? 'text-success' : 'text-danger'; ?>"><?php echo ($isJ ? '+' : '−') . '₹' . number_format((float) $adv['amount'], 2); ?></span>
                            </div>
                        <?php endwhile; else: ?><p class="text-muted small mb-0">No transactions this month.</p><?php endif; ?>
                    </div>
                    <div class="ui-panel d-print-none">
                        <div class="d-flex justify-content-between align-items-center mb-2"><h5 class="ui-panel-title"><i class="bi bi-list-check"></i> Active Tasks</h5><span class="ui-pill <?php echo $tasks_count > 0 ? 'blue' : 'gray'; ?>"><?php echo (int) $tasks_count; ?> pending</span></div>
                        <?php if ($latest_task): ?>
                            <div class="fw-bold small"><?php echo ui_e($latest_task['title']); ?></div>
                            <div class="text-danger small fw-bold">Deadline: <?php echo date('d M', strtotime($latest_task['deadline'])); ?></div>
                            <a href="staff_tasks.php" class="btn btn-sm btn-outline-primary mt-2">View all tasks</a>
                        <?php else: ?><p class="small text-muted mb-0">All clear! No pending tasks. 🎉</p><?php endif; ?>
                    </div>
                    <div class="ui-panel d-print-none">
                        <h5 class="ui-panel-title mb-2"><i class="bi bi-megaphone"></i> Announcements</h5>
                        <?php if ($announcements && $announcements->num_rows > 0): while ($an = $announcements->fetch_assoc()): ?>
                            <div class="py-2 border-bottom"><div class="fw-bold small"><?php echo ui_e($an['title']); ?></div><div class="text-muted small"><?php echo ui_e(mb_substr((string) $an['content'], 0, 100)); ?></div></div>
                        <?php endwhile; else: ?><p class="small text-muted mb-0">No new announcements.</p><?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
(function () {
    var t = document.getElementById('liveTimer'); if (t) {
        var start = new Date(t.dataset.in.replace(' ', 'T'));
        var tick = function () { var s = Math.max(0, Math.floor((Date.now() - start) / 1000)); t.textContent = Math.floor(s / 3600) + 'h ' + String(Math.floor(s % 3600 / 60)).padStart(2, '0') + 'm since punch in'; };
        tick(); setInterval(tick, 30000);
    }
    document.querySelectorAll('.punch-form').forEach(function (f) { f.addEventListener('submit', function () { var b = f.querySelector('button'); b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving…'; }); });
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/includes/saas_sidebar_toggle.php'; ?>
</body>
</html>