<?php
session_start();
require_once '../db.php';
check_access(['admin']);

$company_id = (int) get_company_id();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rules'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF failed.');
    }
    $from = (string) ($_POST['payroll_v2_from'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $from)) {
        $from = date('Y-m');
    }
    $abs = max(0, min(5, (float) ($_POST['penalty_absent_days'] ?? 2)));
    $late = max(0, min(5, (float) ($_POST['penalty_late_days'] ?? 0.5)));
    $hrs = max(1, min(24, (float) ($_POST['hourly_day_hours'] ?? 8)));
    $grace = max(0, min(600, (int) ($_POST['late_grace_minutes'] ?? 60)));
    $stmt = $conn->prepare('UPDATE settings SET payroll_v2_from = ?, penalty_absent_days = ?, penalty_late_days = ?, hourly_day_hours = ?, late_grace_minutes = ?');
    $stmt->bind_param('sdddi', $from, $abs, $late, $hrs, $grace);
    $stmt->execute();
    $stmt->close();
    write_audit_log($conn, 'payroll.rules_update', "v2_from=$from absent=$abs late=$late day_hours=$hrs grace=$grace", 'settings', '1');
    header('Location: admin_payroll_rules.php?saved=1&month=' . urlencode((string) ($_POST['month'] ?? date('Y-m'))));
    exit;
}
if (isset($_GET['saved'])) {
    $message = '<div class="alert alert-success">Payroll rules saved.</div>';
}

$set = payroll_settings($conn);
$month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
[$y, $m] = array_map('intval', explode('-', $month));

$staff = [];
$r = $conn->query("SELECT id FROM users WHERE role = 'staff' AND name <> 'Office' AND company_id = $company_id" . staff_sql_active_filter('') . ' ORDER BY name');
while ($r && ($row = $r->fetch_row())) {
    $id = (int) $row[0];
    $GLOBALS['__payroll_force_v2_from'] = '9999-12';            // everything with OLD rules
    $old = payroll_slip($conn, $id, $y, $m);
    $GLOBALS['__payroll_force_v2_from'] = $set['payroll_v2_from']; // current configuration
    $new = payroll_slip($conn, $id, $y, $m);
    unset($GLOBALS['__payroll_force_v2_from']);
    $staff[] = ['old' => $old, 'new' => $new];
}

// Penalties this month that fall on a weekly off / before joining / for non-self staff (likely wrong)
$suspicious = [];
$start = "$month-01";
$end = date('Y-m-t', strtotime($start));
$stmt = $conn->prepare("SELECT p.*, u.name FROM staff_penalties p JOIN users u ON u.id = p.user_id WHERE p.company_id = ? AND p.penalty_date BETWEEN ? AND ? ORDER BY p.penalty_date");
$stmt->bind_param('iss', $company_id, $start, $end);
$stmt->execute();
$pres = $stmt->get_result();
$sameDay = [];
while ($p = $pres->fetch_assoc()) {
    $u = payroll_user($conn, (int) $p['user_id']);
    $why = [];
    if ($u && payroll_is_weekly_off($u, $p['penalty_date'])) {
        $why[] = 'weekly off day';
    }
    if ($u && !empty($u['join_date']) && $p['penalty_date'] < $u['join_date']) {
        $why[] = 'before joining date';
    }
    if ($u && ($u['attendance_mode'] ?? 'self') !== 'self' && (int) $p['created_by'] === 0) {
        $why[] = 'auto penalty for ' . $u['attendance_mode'] . ' mode staff';
    }
    $key = $p['user_id'] . '|' . $p['penalty_date'];
    $sameDay[$key][] = $p;
    if ($why) {
        $suspicious[] = $p + ['why' => implode(', ', $why)];
    }
}
foreach ($sameDay as $list) {
    if (count($list) > 1) {
        foreach ($list as $p) {
            $suspicious[] = $p + ['why' => count($list) . ' penalties on the same day'];
        }
    }
}
$csrf = generate_csrf_token();
function money($v): string
{
    return '₹' . number_format((float) $v, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Rules | <?php echo htmlspecialchars(app_brand()); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/saas_theme.css?v=<?php echo app_asset_version(); ?>">
    <?php echo app_pwa_head(); ?>
    <style>.diff{background:#fff7ed}.small-num{font-size:.8rem}</style>
</head>
<body>
<div class="saas-sidebar-backdrop" id="saasSidebarBackdrop" aria-hidden="true"></div>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php require_once __DIR__ . '/../includes/saas_sidebar_admin.php'; ?>
        <div class="col-md-10 main-content p-3 p-md-4">
            <h3><i class="bi bi-calculator text-primary"></i> Payroll rules &amp; hisab check</h3>
            <p class="text-muted small">One calculation is used everywhere (salary slip, salary report, staff dashboard, opening balance).</p>
            <?php echo $message; ?>

            <div class="card shadow-sm mb-3"><div class="card-body">
                <form method="post" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="month" value="<?php echo htmlspecialchars($month); ?>">
                    <div class="col-md-3"><label class="form-label small fw-bold">New rules apply from month</label>
                        <input type="month" name="payroll_v2_from" class="form-control" value="<?php echo htmlspecialchars($set['payroll_v2_from']); ?>">
                        <div class="form-text">Earlier months keep old rules (balances already settled do not move).</div></div>
                    <div class="col-md-2"><label class="form-label small fw-bold">Absent (no punch by 12 PM)</label>
                        <div class="input-group"><input type="number" step="0.5" min="0" name="penalty_absent_days" class="form-control" value="<?php echo (float) $set['penalty_absent_days']; ?>"><span class="input-group-text">days</span></div></div>
                    <div class="col-md-2"><label class="form-label small fw-bold">Late penalty</label>
                        <div class="input-group"><input type="number" step="0.25" min="0" name="penalty_late_days" class="form-control" value="<?php echo (float) $set['penalty_late_days']; ?>"><span class="input-group-text">days</span></div></div>
                    <div class="col-md-2"><label class="form-label small fw-bold">Late after shift +</label>
                        <div class="input-group"><input type="number" min="0" name="late_grace_minutes" class="form-control" value="<?php echo (int) $set['late_grace_minutes']; ?>"><span class="input-group-text">min</span></div></div>
                    <div class="col-md-2"><label class="form-label small fw-bold">Hourly staff: 1 day =</label>
                        <div class="input-group"><input type="number" step="0.5" min="1" name="hourly_day_hours" class="form-control" value="<?php echo (float) $set['hourly_day_hours']; ?>"><span class="input-group-text">hrs</span></div></div>
                    <div class="col-md-1"><button name="save_rules" class="btn btn-primary w-100">Save</button></div>
                </form>
                <ul class="small text-muted mt-3 mb-0">
                    <li><b>Monthly salary</b> = rate ÷ days in month × (present days + paid weekly-off days). Full month present = full salary.</li>
                    <li><b>Hourly salary</b> = worked hours × rate. Weekly off is unpaid but never penalised.</li>
                    <li><b>Rate</b> = custom month rate (Staff → custom rate) → otherwise base rate + optional "auto increment per month" (default ₹0).</li>
                    <li><b>Auto penalties</b> only for "Self punch" staff, never on their weekly off or before joining date; an absence replaces that day's late penalty (no double penalty).</li>
                    <li>Penalties stay deducted in later months' opening balance (old system gave them back next month).</li>
                </ul>
            </div></div>

            <div class="card shadow-sm mb-3"><div class="card-body">
                <form class="d-flex gap-2 align-items-end mb-3" method="get">
                    <div><label class="form-label small fw-bold">Compare month</label><input type="month" name="month" class="form-control" value="<?php echo htmlspecialchars($month); ?>"></div>
                    <button class="btn btn-outline-primary">Show</button>
                </form>
                <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle small">
                    <thead class="table-dark"><tr>
                        <th>Staff</th><th>Rules</th><th>Rate</th><th>Days / Hours</th><th>Gross</th><th>Penalty</th><th>Prev. balance</th><th>Upad / Jama</th><th>Net payable</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($staff as $row): foreach (['old' => 'Old system', 'new' => 'Now'] as $k => $label): $p = $row[$k]; $o = $row['old'];
                        $changed = $k === 'new' && abs($p['net'] - $o['net']) > 0.009; ?>
                        <tr class="<?php echo $changed ? 'diff' : ''; ?>">
                            <?php if ($k === 'old'): ?><td rowspan="2" class="fw-bold"><?php echo htmlspecialchars($p['user']['name']); ?><br>
                                <span class="text-muted small-num"><?php echo ucfirst($p['salary_type']); ?> · <?php echo htmlspecialchars(payroll_weekly_off_label($p['user'])); ?> · <?php echo htmlspecialchars($p['user']['attendance_mode'] ?? 'self'); ?></span></td><?php endif; ?>
                            <td><?php echo $label; ?><?php echo $k === 'new' ? ' <span class="badge bg-' . ($p['rules'] === 'v2' ? 'success' : 'secondary') . '">' . $p['rules'] . '</span>' : ''; ?></td>
                            <td><?php echo money($p['rate']); ?><div class="text-muted small-num"><?php echo htmlspecialchars($p['rate_source']); ?></div></td>
                            <td><?php echo (int) $p['days_present']; ?> present<?php echo $p['paid_off_days'] ? ' + ' . (int) $p['paid_off_days'] . ' off' : ''; ?><div class="text-muted small-num"><?php echo $p['hours']; ?> h</div></td>
                            <td><?php echo money($p['gross']); ?></td>
                            <td class="text-danger"><?php echo $p['penalty_amount'] > 0 ? '−' . money($p['penalty_amount']) . '<div class="small-num">' . (float) $p['penalty_days'] . ' day × ' . money($p['day_value']) . '</div>' : '—'; ?></td>
                            <td><?php echo money($p['opening_balance']); ?></td>
                            <td class="small-num"><?php echo '−' . money($p['month_upad'] + $p['subsequent_upad']) . ' / +' . money($p['month_jama'] + $p['subsequent_jama']); ?></td>
                            <td class="fw-bold"><?php echo money($p['net']); ?><?php if ($changed): ?><div class="small-num text-warning-emphasis">diff <?php echo ($p['net'] - $o['net'] >= 0 ? '+' : '') . number_format($p['net'] - $o['net'], 2); ?></div><?php endif; ?></td>
                        </tr>
                    <?php endforeach; endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div></div>

            <div class="card shadow-sm"><div class="card-body">
                <h6><i class="bi bi-exclamation-triangle text-warning"></i> Penalties to review in <?php echo htmlspecialchars($month); ?></h6>
                <?php if (!$suspicious): ?><p class="text-success small mb-0">No suspicious penalties found.</p><?php else: ?>
                <table class="table table-sm small"><thead><tr><th>Date</th><th>Staff</th><th>Type</th><th>Days</th><th>Reason</th><th>Why flagged</th></tr></thead><tbody>
                <?php foreach ($suspicious as $p): ?>
                    <tr><td><?php echo htmlspecialchars($p['penalty_date']); ?> (<?php echo date('D', strtotime($p['penalty_date'])); ?>)</td><td><?php echo htmlspecialchars($p['name']); ?></td><td><?php echo htmlspecialchars($p['penalty_type']); ?></td><td><?php echo (float) $p['deduction_days']; ?></td><td><?php echo htmlspecialchars((string) $p['reason']); ?></td><td class="text-danger"><?php echo htmlspecialchars($p['why']); ?></td></tr>
                <?php endforeach; ?></tbody></table>
                <a href="admin_penalties.php" class="btn btn-sm btn-outline-danger">Open penalties to remove wrong ones</a>
                <?php endif; ?>
            </div></div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
