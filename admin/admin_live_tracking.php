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

$selected_date = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['date'])) ? $_GET['date'] : date('Y-m-d');
$company_id = (int) get_company_id();

$stmt = $conn->prepare("SELECT u.id, u.name, u.phone_number, a.punch_in, a.lunch_out, a.lunch_in, a.punch_out, a.is_leave, a.leave_reason, a.total_worked_minutes
        FROM users u
        LEFT JOIN attendance_logs a ON u.id = a.user_id AND a.log_date = ?
        WHERE u.role = 'staff' AND u.company_id = ?" . staff_sql_active_filter('u') . ' ORDER BY a.punch_in IS NULL, a.punch_in ASC, u.name ASC');
$stmt->bind_param('si', $selected_date, $company_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$st = ['total' => count($rows), 'working' => 0, 'ended' => 0, 'break' => 0, 'notin' => 0, 'leave' => 0];
foreach ($rows as &$r) {
    if (isset($r['is_leave']) && (float) $r['is_leave'] >= 1) {
        $r['_status'] = 'leave';
        $st['leave']++;
    } elseif (empty($r['punch_in'])) {
        $r['_status'] = 'notin';
        $st['notin']++;
    } elseif (!empty($r['punch_out'])) {
        $r['_status'] = 'ended';
        $st['ended']++;
    } elseif (!empty($r['lunch_out']) && empty($r['lunch_in'])) {
        $r['_status'] = 'break';
        $st['break']++;
    } else {
        $r['_status'] = 'working';
        $st['working']++;
    }
}
unset($r);
$t = static fn ($v) => $v ? date('h:i A', strtotime((string) $v)) : '';
$earlyMap = [];
if (early_out_ready($conn)) {
    $st2 = $conn->prepare("SELECT user_id, requested_time FROM early_out_requests WHERE company_id = ? AND log_date = ? AND status = 'pending'");
    $st2->bind_param('is', $company_id, $selected_date);
    $st2->execute();
    foreach ($st2->get_result()->fetch_all(MYSQLI_ASSOC) as $e) {
        $earlyMap[(int) $e['user_id']] = $e['requested_time'];
    }
    $st2->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Tracking | <?php echo htmlspecialchars(app_brand()); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/saas_theme.css?v=<?php echo app_asset_version(); ?>">
    <?php echo app_pwa_head(); ?>
</head>
<body>
<div class="saas-sidebar-backdrop" id="saasSidebarBackdrop" aria-hidden="true"></div>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php require_once __DIR__ . '/../includes/saas_sidebar_admin.php'; ?>
        <div class="col-md-10 main-content">
            <?php echo ui_hero('Live Tracking', 'Track staff attendance, work hours and real-time in/out activity.', 'bi-geo-alt-fill', 'pin'); ?>

            <div class="ui-stats">
                <?php
                echo ui_stat($st['total'], 'Total Staff', 'bi-people-fill', 'teal');
                echo ui_stat($st['working'], 'Working', 'bi-check-lg', 'green');
                echo ui_stat($st['ended'], 'Shift Ended', 'bi-hourglass-bottom', 'blue');
                echo ui_stat($st['break'], 'On Break', 'bi-cup-hot', 'orange');
                echo ui_stat($st['notin'], 'Not In', 'bi-box-arrow-right', 'red');
                if ($st['leave'] > 0) {
                    echo ui_stat($st['leave'], 'On Leave', 'bi-calendar2-week', 'purple');
                }
                ?>
            </div>

            <div class="ui-panel mb-3 d-print-none">
                <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
                    <label class="fw-bold"><i class="bi bi-calendar3 me-1"></i> Select Date:</label>
                    <input type="date" name="date" class="form-control" style="width:auto" value="<?php echo ui_e($selected_date); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                    <button type="submit" class="btn btn-primary fw-bold px-4"><i class="bi bi-search"></i> View Status</button>
                    <?php if ($selected_date !== date('Y-m-d')): ?><a class="btn btn-outline-secondary" href="admin_live_tracking.php">Today</a><?php endif; ?>
                </form>
            </div>

            <div class="ui-panel">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h5 class="ui-panel-title"><i class="bi bi-people-fill"></i> Staff Live Tracking <span class="text-muted fw-normal small ms-1"><?php echo date('d M Y', strtotime($selected_date)); ?></span></h5>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($selected_date === date('Y-m-d')): ?>
                        <select class="form-select form-select-sm fw-semibold" id="autoRefresh" style="width:auto">
                            <option value="30">● Auto Refresh: 30s</option><option value="60">● Auto Refresh: 60s</option><option value="0">Auto Refresh: Off</option>
                        </select>
                        <?php endif; ?>
                        <button class="ui-icon-btn" type="button" onclick="location.reload()" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="ui-table text-center ui-cards-sm">
                        <thead><tr><th>#</th><th class="text-start">Staff Member</th><th>In</th><th>Lunch Out</th><th>Lunch In</th><th>Out</th><th>Total Work</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php if (!$rows): ?><tr><td colspan="9" class="text-muted py-4">No active staff.</td></tr><?php endif; ?>
                        <?php foreach ($rows as $i => $row): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td class="text-start"><div class="d-flex align-items-center gap-2"><?php echo ui_avatar((string) $row['name']); ?>
                                    <div><b><?php echo ui_e($row['name']); ?></b><div class="small text-muted"><i class="bi bi-whatsapp text-success"></i> <?php echo ui_e($row['phone_number']); ?></div></div></div></td>
                                <?php if ($row['_status'] === 'leave'): ?>
                                    <td colspan="5"><span class="ui-pill amber"><i class="bi bi-calendar2-week"></i> Leave: <?php echo ui_e($row['leave_reason'] ?? ''); ?></span></td>
                                    <td><span class="ui-pill solid-amber">On Leave</span></td>
                                <?php else: ?>
                                    <td><?php echo $row['punch_in'] ? '<span class="ui-pill green"><i class="bi bi-box-arrow-in-right"></i> ' . $t($row['punch_in']) . '</span>' : '<span class="text-muted">—</span>'; ?></td>
                                    <td><?php echo $row['lunch_out'] ? '<span class="ui-pill gray"><i class="bi bi-cup-straw"></i> ' . $t($row['lunch_out']) . '</span>' : '<span class="text-muted">—</span>'; ?></td>
                                    <td><?php echo $row['lunch_in'] ? '<span class="ui-pill gray"><i class="bi bi-cup-straw"></i> ' . $t($row['lunch_in']) . '</span>' : '<span class="text-muted">—</span>'; ?></td>
                                    <td><?php echo $row['punch_out'] ? '<span class="ui-pill red"><i class="bi bi-box-arrow-right"></i> ' . $t($row['punch_out']) . '</span>' : '<span class="text-muted">—</span>'; ?></td>
                                    <td><?php
                                        $calc = attendance_worked_minutes_from_row($row);
                                        if (empty($row['punch_in'])) {
                                            echo '<span class="text-muted">—</span>';
                                        } elseif (empty($row['punch_out'])) {
                                            echo '<span class="ui-pill amber"><i class="bi bi-stopwatch"></i> No OUT</span>';
                                        } else {
                                            echo '<span class="ui-pill blue"><i class="bi bi-stopwatch"></i> ' . ui_e(format_attendance_duration($calc)) . '</span><div class="small text-muted mt-1">' . round($calc / 60, 2) . ' hrs</div>';
                                            if ((int) ($row['total_worked_minutes'] ?? 0) !== $calc) {
                                                echo '<div class="small text-danger">⚠ DB mismatch</div>';
                                            }
                                        }
                                    ?></td>
                                    <td><?php echo [
                                        'working' => isset($earlyMap[(int) $row['id']]) ? '<a href="admin_leaves.php" class="ui-pill solid-amber text-decoration-none">⏳ Early out ' . $t($earlyMap[(int) $row['id']]) . ' — approve</a>' : '<span class="ui-pill solid-green">● Working</span>',
                                        'ended' => '<span class="ui-pill solid-blue">● Shift Ended</span>',
                                        'break' => '<span class="ui-pill solid-amber">● On Break</span>',
                                        'notin' => '<span class="ui-pill gray">Not In</span>',
                                    ][$row['_status']]; ?></td>
                                <?php endif; ?>
                                <td><a class="ui-act view" title="Edit time" href="admin_manual_time.php?user_id=<?php echo (int) $row['id']; ?>&date=<?php echo ui_e($selected_date); ?>"><i class="bi bi-eye"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var sel = document.getElementById('autoRefresh'); if (!sel) return;
    var KEY = 'akdwk_live_refresh', timer = null;
    try { sel.value = localStorage.getItem(KEY) || '30'; } catch (e) {}
    function arm() { clearTimeout(timer); var s = parseInt(sel.value, 10); if (s > 0) timer = setTimeout(function () { location.reload(); }, s * 1000); }
    sel.addEventListener('change', function () { try { localStorage.setItem(KEY, sel.value); } catch (e) {} arm(); });
    arm();
})();
</script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
