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

// Weekly trend: staff present per day (last 7 days)
$trend_labels = [];
$trend_values = [];
$stmt = $conn->prepare("SELECT a.log_date, COUNT(DISTINCT a.user_id) AS n FROM attendance_logs a JOIN users u ON u.id = a.user_id
    WHERE u.company_id = ? AND u.role = 'staff' AND a.log_date BETWEEN ? AND ? AND a.punch_in IS NOT NULL AND COALESCE(a.is_leave,0) < 1
    GROUP BY a.log_date");
$from7 = date('Y-m-d', strtotime('-6 days'));
$to7 = date('Y-m-d');
$stmt->bind_param('iss', $company_id, $from7, $to7);
$stmt->execute();
$tr = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $tr[$row['log_date']] = (int) $row['n'];
}
$stmt->close();
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trend_labels[] = date('d M', strtotime($d));
    $trend_values[] = $tr[$d] ?? 0;
}
$branches_list = [];
$br = $conn->query("SELECT id, name FROM branches WHERE company_id = " . (int) $company_id . " ORDER BY name");
while ($br && ($b = $br->fetch_assoc())) {
    $branches_list[] = $b;
}
$pct = static fn (int $n) => $total_staff > 0 ? round($n * 100 / $total_staff) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | <?php echo htmlspecialchars(app_brand()); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="../assets/saas_theme.css?v=<?php echo app_asset_version(); ?>">
    <style>
        .dash-legend li { display:flex; align-items:center; justify-content:space-between; padding:.42rem 0; font-weight:600; font-size:.92rem; }
        .dash-legend .dot { width:11px; height:11px; border-radius:50%; display:inline-block; margin-right:.6rem; }
        .donut-wrap { position:relative; width:190px; height:190px; flex:0 0 190px; }
        .donut-center { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; }
        .donut-center b { font-size:1.9rem; font-weight:800; line-height:1; } .donut-center span { color:var(--ui-muted); font-weight:600; }
    </style>
</head>
<body>
<div class="saas-sidebar-backdrop" id="saasSidebarBackdrop" aria-hidden="true"></div>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php require_once __DIR__ . '/../includes/saas_sidebar_admin.php'; ?>

        <div class="col-md-10 main-content">
            <?php echo ui_hero(
                'Welcome back, ' . ui_e($_SESSION['name'] ?? '') . ' 👋',
                "Here's what's happening in your organization today.",
                '',
                'temple',
                '<span class="ui-chip ui-hide-sm"><i class="bi bi-calendar3"></i>' . date('l, d F, Y') . '</span><span class="ui-chip"><i class="bi bi-clock"></i><span id="clock">' . date('h:i:s A') . '</span></span>'
            ); ?>

            <div class="ui-stats">
                <?php
                echo ui_stat($total_branches, 'Active Branches', 'bi-building', 'blue', 'admin_branches.php');
                echo ui_stat($present_staff, 'Present', 'bi-people-fill', 'green', 'admin_live_tracking.php');
                echo ui_stat($absent_staff, 'Absent', 'bi-person-x', 'red', 'admin_live_tracking.php');
                echo ui_stat($leave_staff, 'On Leave', 'bi-calendar2-week', 'orange', 'admin_leaves.php');
                echo ui_stat($half_day_staff, 'Half Day', 'bi-hourglass-split', 'purple', 'admin_live_tracking.php');
                echo ui_stat($late_staff, 'Late', 'bi-clock', 'cyan', 'admin_live_tracking.php');
                echo ui_stat($pending_tasks, 'Pending Tasks', 'bi-check2-square', 'violet', 'admin_tasks.php');
                ?>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-xl-4 col-lg-6">
                    <div class="ui-panel h-100">
                        <h5 class="ui-panel-title mb-3"><i class="bi bi-people-fill"></i> Today's Attendance Status</h5>
                        <div class="d-flex align-items-center gap-4 flex-wrap">
                            <div class="donut-wrap"><canvas id="attendanceChart"></canvas>
                                <div class="donut-center"><b><?php echo $present_staff; ?> / <?php echo $total_staff; ?></b><span>Present</span></div></div>
                            <ul class="list-unstyled dash-legend flex-grow-1 mb-0">
                                <?php foreach ([['Present', $present_staff, '#22c55e'], ['On Leave', $leave_staff, '#f59e0b'], ['Late', $late_staff, '#0ea5e9'], ['Half Day', $half_day_staff, '#7c3aed'], ['Absent', $absent_staff, '#e11d48']] as [$l, $n, $c]): ?>
                                    <li><span><span class="dot" style="background:<?php echo $c; ?>"></span><?php echo $l; ?></span><span><?php echo $n; ?> <span class="text-muted">(<?php echo $pct($n); ?>%)</span></span></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-lg-6">
                    <div class="ui-panel h-100">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h5 class="ui-panel-title"><i class="bi bi-graph-up-arrow"></i> Weekly Attendance Trend</h5>
                            <span class="ui-pill gray">Last 7 days</span>
                        </div>
                        <div style="height:220px"><canvas id="trendChart"></canvas></div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="ui-panel h-100">
                        <h5 class="ui-panel-title mb-3"><i class="bi bi-lightning-charge-fill"></i> Quick Actions</h5>
                        <div class="ui-quick">
                            <a href="admin_staff.php?add=1" class="ui-c-blue"><i class="bi bi-person-plus text-primary"></i>Add Staff</a>
                            <a href="admin_manual_time.php" class="ui-c-green"><i class="bi bi-calendar-check text-success"></i>Mark Attendance</a>
                            <a href="admin_tasks.php" class="ui-c-purple"><i class="bi bi-check2-square" style="color:#7c3aed"></i>Create Task</a>
                            <a href="admin_announcements.php" class="ui-c-orange"><i class="bi bi-megaphone" style="color:#f59e0b"></i>Add Announcement</a>
                            <a href="admin_salary_slip.php" class="ui-c-red"><i class="bi bi-currency-rupee" style="color:#e11d48"></i>Generate Salary</a>
                            <a href="admin_reports.php" class="ui-c-cyan"><i class="bi bi-bar-chart-line" style="color:#0284c7"></i>View Reports</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ui-panel">
                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
                    <h5 class="ui-panel-title"><i class="bi bi-people-fill"></i> Registered Staff Overview</h5>
                    <div class="d-flex gap-2 flex-wrap">
                        <div class="ui-search" style="flex-basis:220px"><i class="bi bi-search"></i><input type="search" id="staffFilter" placeholder="Search staff…" style="padding-right:.8rem"></div>
                        <select class="form-select" id="branchFilter" style="width:auto">
                            <option value="">All Branches</option>
                            <?php foreach ($branches_list as $b): ?><option><?php echo ui_e($b['name']); ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn btn-outline-primary fw-semibold" id="exportCsv" type="button"><i class="bi bi-download"></i> Export</button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="ui-table" id="staffTable">
                        <thead><tr><th>#</th><th>Staff Name</th><th>Branch</th><th><i class="bi bi-whatsapp"></i> WhatsApp Number</th><th>Salary Base</th><th>Message Status</th><th class="text-center">Actions</th></tr></thead>
                        <tbody>
                        <?php $i = 0; if ($staff_result && $staff_result->num_rows > 0): while ($row = $staff_result->fetch_assoc()): $i++; ?>
                            <tr data-branch="<?php echo ui_e($row['branch_name'] ?? ''); ?>">
                                <td><?php echo $i; ?></td>
                                <td><div class="d-flex align-items-center gap-2"><?php echo ui_avatar((string) $row['name'], 'sm'); ?><b><?php echo ui_e($row['name']); ?></b></div></td>
                                <td><span class="ui-branch-tag"><i class="bi bi-shop"></i><?php echo ui_e($row['branch_name'] ?? '—'); ?></span></td>
                                <td><i class="bi bi-whatsapp text-success me-1"></i><?php echo ui_e($row['phone_number']); ?></td>
                                <td><span class="ui-pill gray me-1"><?php echo ui_e(ucfirst((string) $row['salary_type'])); ?></span>₹<?php echo number_format((float) $row['salary_amount'], 2); ?></td>
                                <td><?php echo (int) $row['msg_active'] === 1 ? '<span class="ui-pill solid-green"><i class="bi bi-check-lg"></i> Active</span>' : '<span class="ui-pill solid-red"><i class="bi bi-x-lg"></i> Off</span>'; ?></td>
                                <td class="text-center text-nowrap">
                                    <a class="ui-act view" title="Salary slip" href="admin_salary_slip.php?user_id=<?php echo (int) $row['id']; ?>"><i class="bi bi-eye"></i></a>
                                    <a class="ui-act edit" title="Edit in Staff" href="admin_staff.php?filter=all&q=<?php echo rawurlencode((string) $row['name']); ?>"><i class="bi bi-pencil-square"></i></a>
                                    <a class="ui-act del" title="Deactivate (staff are never deleted)" href="admin_staff.php?filter=all&q=<?php echo rawurlencode((string) $row['name']); ?>"><i class="bi bi-person-dash"></i></a>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">No staff members found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    setInterval(function () { var c = document.getElementById('clock'); if (c) c.textContent = new Date().toLocaleTimeString('en-IN', {hour: '2-digit', minute: '2-digit', second: '2-digit'}); }, 1000);
    if (window.Chart) {
        new Chart(document.getElementById('attendanceChart'), {
            type: 'doughnut',
            data: { labels: ['Present', 'On Leave', 'Late', 'Half Day', 'Absent'],
                datasets: [{ data: [<?php echo (int) $present_staff; ?>, <?php echo (int) $leave_staff; ?>, <?php echo (int) $late_staff; ?>, <?php echo (int) $half_day_staff; ?>, <?php echo (int) $absent_staff; ?>],
                    backgroundColor: ['#22c55e', '#f59e0b', '#0ea5e9', '#7c3aed', '#e11d48'], borderWidth: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '74%', plugins: { legend: { display: false } } }
        });
        var tctx = document.getElementById('trendChart').getContext('2d');
        var grad = tctx.createLinearGradient(0, 0, 0, 220); grad.addColorStop(0, 'rgba(34,197,94,.35)'); grad.addColorStop(1, 'rgba(34,197,94,0)');
        new Chart(tctx, {
            type: 'line',
            data: { labels: <?php echo json_encode($trend_labels); ?>, datasets: [{ data: <?php echo json_encode($trend_values); ?>, borderColor: '#22c55e', backgroundColor: grad, fill: true, tension: .4, pointBackgroundColor: '#fff', pointBorderColor: '#22c55e', pointBorderWidth: 2, pointRadius: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, suggestedMax: <?php echo max(1, (int) $total_staff); ?>, ticks: { precision: 0 }, grid: { color: 'rgba(148,163,184,.15)' } }, x: { grid: { display: false } } } }
        });
    }
    (function () {
        var q = document.getElementById('staffFilter'), b = document.getElementById('branchFilter'), rows = document.querySelectorAll('#staffTable tbody tr[data-branch]');
        function f() { var t = q.value.toLowerCase(), br = b.value; rows.forEach(function (r) { r.style.display = (r.textContent.toLowerCase().indexOf(t) !== -1 && (!br || r.dataset.branch === br)) ? '' : 'none'; }); }
        q.addEventListener('input', f); b.addEventListener('change', f);
        document.getElementById('exportCsv').addEventListener('click', function () {
            var out = [['Staff', 'Branch', 'WhatsApp', 'Salary type', 'Salary', 'Messages']];
            rows.forEach(function (r) { if (r.style.display === 'none') return; var c = r.querySelectorAll('td');
                out.push([c[1].innerText.trim(), c[2].innerText.trim(), c[3].innerText.trim(), c[4].querySelector('.ui-pill').innerText.trim(), c[4].innerText.replace(c[4].querySelector('.ui-pill').innerText, '').trim(), c[5].innerText.trim()]); });
            var csv = out.map(function (r) { return r.map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(','); }).join('\n');
            var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], {type: 'text/csv'})); a.download = 'staff.csv'; a.click();
        });
    })();
</script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
