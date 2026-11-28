<?php
session_start();
require_once '../db.php';
check_access(['admin', 'manager', 'hr']);

$company_id = (int) get_company_id();
$message = '';

// Early punch-out approvals
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['early_action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF validation failed.');
    }
    $res = early_out_decide($conn, (int) ($_POST['request_id'] ?? 0), $company_id, $_POST['early_action'] === 'approve', (int) $_SESSION['user_id'], (string) ($_POST['admin_note'] ?? ''));
    header('Location: admin_leaves.php?eo=' . ($res['ok'] ? 'ok' : 'err') . '&m=' . rawurlencode($res['msg']));
    exit;
}
if (isset($_GET['eo'])) {
    $message .= "<div class='alert alert-" . ($_GET['eo'] === 'ok' ? 'success' : 'warning') . " fw-semibold'>" . htmlspecialchars((string) ($_GET['m'] ?? '')) . '</div>';
}
$early_pending = [];
$early_done = [];
if (early_out_ready($conn)) {
    $er = $conn->query("SELECT r.*, u.name, u.phone_number, a.punch_in FROM early_out_requests r JOIN users u ON u.id = r.user_id LEFT JOIN attendance_logs a ON a.id = r.attendance_id WHERE r.company_id = $company_id AND r.status = 'pending' ORDER BY r.id DESC");
    $early_pending = $er ? $er->fetch_all(MYSQLI_ASSOC) : [];
    $er = $conn->query("SELECT r.*, u.name FROM early_out_requests r JOIN users u ON u.id = r.user_id WHERE r.company_id = $company_id AND r.status <> 'pending' ORDER BY r.decided_at DESC LIMIT 15");
    $early_done = $er ? $er->fetch_all(MYSQLI_ASSOC) : [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF validation failed.');
    }
    
    $leave_id = (int) $_POST['leave_id'];
    $action = $_POST['action']; // 'approve' or 'reject'
    
    // Fetch leave details
    $stmt = $conn->prepare("SELECT lr.*, u.name as staff_name, u.phone_number, u.branch_id FROM leave_requests lr JOIN users u ON u.id = lr.user_id WHERE lr.id = ? AND lr.company_id = ?");
    $stmt->bind_param("ii", $leave_id, $company_id);
    $stmt->execute();
    $lr = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($lr && $lr['status'] === 'pending') {
        $staff_name = $lr['staff_name'];
        $user_id = $lr['user_id'];
        $branch_id = $lr['branch_id'];
        
        $sDate = date('d M Y', strtotime($lr['start_date']));
        $eDate = date('d M Y', strtotime($lr['end_date']));
        $dates = ($sDate === $eDate) ? $sDate : "$sDate to $eDate";
        
        if ($action === 'approve') {
            // Update status
            $stmt_u = $conn->prepare("UPDATE leave_requests SET status = 'approved' WHERE id = ?");
            $stmt_u->bind_param("i", $leave_id);
            $stmt_u->execute();
            $stmt_u->close();
            
            // Insert attendance_logs and double deduction if needed
            $begin = new DateTime($lr['start_date']);
            $end = new DateTime($lr['end_date']);
            $end->modify('+1 day');
            $daterange = new DatePeriod($begin, new DateInterval('P1D'), $end);
            
            $inserted = 0;
            foreach ($daterange as $date) {
                $dt = $date->format('Y-m-d');
                $stmt_check = $conn->prepare("SELECT id FROM attendance_logs WHERE user_id = ? AND log_date = ?");
                $stmt_check->bind_param("is", $user_id, $dt);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows === 0) {
                    $is_leave = 1;
                    $reason = ($lr['leave_type'] === 'half' ? '[Half Day] ' : '') . $lr['reason'];
                    $stmt_ins = $conn->prepare("INSERT INTO attendance_logs (user_id, company_id, branch_id, log_date, is_leave, leave_reason) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt_ins->bind_param("iiisis", $user_id, $company_id, $branch_id, $dt, $is_leave, $reason);
                    $stmt_ins->execute();
                    $stmt_ins->close();
                    $inserted++;
                }
                $stmt_check->close();
            }
            
            // Missing Medical Certificate Penalty
            if ($lr['missing_medical_cert'] && $inserted > 0) {
                foreach ($daterange as $date) {
                    $dt = $date->format('Y-m-d');
                    $deduct = ($lr['leave_type'] === 'half') ? 1.0 : 2.0;
                    $ptype = 'double_absence';
                    $preason = 'Emergency leave without medical certificate';
                    $stmt_p = $conn->prepare("INSERT INTO staff_penalties (user_id, company_id, penalty_date, penalty_type, deduction_days, reason, created_by) VALUES (?, ?, ?, ?, ?, ?, 0)");
                    $stmt_p->bind_param("iissds", $user_id, $company_id, $dt, $ptype, $deduct, $preason);
                    @$stmt_p->execute();
                    $stmt_p->close();
                }
            }
            
            // Notify staff
            if (!empty($lr['phone_number'])) {
                $msg = "✅ *Leave Approved*\nHello $staff_name,\nYour leave request for $dates has been APPROVED.";
                sendWhatsAppDirectMessage($lr['phone_number'], $msg);
            }
            write_audit_log($conn, 'leave.approve', "leave_id=$leave_id");
            $message = "<div class='alert alert-success'>Leave approved and attendance generated.</div>";
            
        } elseif ($action === 'reject') {
            $stmt_u = $conn->prepare("UPDATE leave_requests SET status = 'rejected' WHERE id = ?");
            $stmt_u->bind_param("i", $leave_id);
            $stmt_u->execute();
            $stmt_u->close();
            
            // Notify staff
            if (!empty($lr['phone_number'])) {
                $msg = "❌ *Leave Rejected*\nHello $staff_name,\nYour leave request for $dates has been REJECTED.";
                sendWhatsAppDirectMessage($lr['phone_number'], $msg);
            }
            write_audit_log($conn, 'leave.reject', "leave_id=$leave_id");
            $message = "<div class='alert alert-warning'>Leave rejected.</div>";
        }
    }
}

// Fetch all leaves
$leaves = [];
if (salary_table_exists($conn, 'leave_requests')) {
    $stmt = $conn->prepare("SELECT lr.*, u.name as staff_name FROM leave_requests lr JOIN users u ON u.id = lr.user_id WHERE lr.company_id = ? ORDER BY lr.id DESC");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $leaves[] = $r;
    }
    $stmt->close();
}

$csrf = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Leaves | <?php echo htmlspecialchars(app_brand()); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/saas_theme.css?v=<?php echo app_asset_version(); ?>">
    <?php echo app_pwa_head(); ?>
</head>
<body>
<div class="saas-sidebar-backdrop" id="saasSidebarBackdrop"></div>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php require_once __DIR__ . '/../includes/saas_sidebar_admin.php'; ?>
        <div class="col-md-10 main-content p-4">
            <?php echo ui_hero('Approvals', 'Early punch-out requests and leave requests from staff.', 'bi-calendar2-check', 'clipboard'); ?>
            <?php echo $message; ?>

            <div class="ui-panel mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="ui-panel-title"><i class="bi bi-box-arrow-right"></i> Early punch-out requests</h5>
                    <span class="ui-pill <?php echo $early_pending ? 'solid-amber' : 'gray'; ?>"><?php echo count($early_pending); ?> pending</span>
                </div>
                <p class="small text-muted mb-2">Staff tried to punch out before their shift end time. Approve to record the punch-out at the requested time.</p>
                <?php if (!$early_pending): ?>
                    <div class="text-muted small py-2">No pending early punch-out requests. ✅</div>
                <?php else: ?>
                <div class="table-responsive"><table class="ui-table ui-cards-sm">
                    <thead><tr><th>Staff</th><th>Date</th><th>In</th><th>Wants out at</th><th>Shift ends</th><th>Reason</th><th class="text-center">Decision</th></tr></thead>
                    <tbody>
                    <?php foreach ($early_pending as $r): ?>
                        <tr>
                            <td><div class="d-flex align-items-center gap-2"><?php echo ui_avatar((string) $r['name'], 'sm'); ?><b><?php echo ui_e($r['name']); ?></b></div></td>
                            <td><?php echo date('d M Y', strtotime($r['log_date'])); ?></td>
                            <td><span class="ui-pill green"><?php echo $r['punch_in'] ? date('h:i A', strtotime($r['punch_in'])) : '—'; ?></span></td>
                            <td><span class="ui-pill amber"><?php echo date('h:i A', strtotime($r['requested_time'])); ?></span></td>
                            <td><?php echo $r['shift_end_time'] ? date('h:i A', strtotime($r['shift_end_time'])) : '—'; ?></td>
                            <td><?php echo ui_e($r['reason'] ?: '—'); ?></td>
                            <td class="text-center">
                                <form method="post" class="d-flex gap-1 justify-content-center flex-wrap">
                                    <input type="hidden" name="csrf_token" value="<?php echo ui_e(generate_csrf_token()); ?>">
                                    <input type="hidden" name="request_id" value="<?php echo (int) $r['id']; ?>">
                                    <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="Note (optional)" style="max-width:160px">
                                    <button name="early_action" value="approve" class="btn btn-sm btn-success fw-bold"><i class="bi bi-check-lg"></i> Approve</button>
                                    <button name="early_action" value="reject" class="btn btn-sm btn-outline-danger fw-bold"><i class="bi bi-x-lg"></i> Reject</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php endif; ?>
                <?php if ($early_done): ?>
                <details class="mt-2"><summary class="small fw-semibold">Recent decisions</summary>
                    <div class="table-responsive mt-2"><table class="ui-table ui-cards-sm">
                        <thead><tr><th>Staff</th><th>Date</th><th>Out at</th><th>Status</th><th>Note</th></tr></thead><tbody>
                        <?php foreach ($early_done as $r): ?>
                            <tr><td><?php echo ui_e($r['name']); ?></td><td><?php echo date('d M', strtotime($r['log_date'])); ?></td><td><?php echo date('h:i A', strtotime($r['requested_time'])); ?></td>
                                <td><?php echo $r['status'] === 'approved' ? '<span class="ui-pill green">Approved</span>' : '<span class="ui-pill red">Rejected</span>'; ?></td><td><?php echo ui_e($r['admin_note'] ?: '—'); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                </details>
                <?php endif; ?>
            </div>
            
            <div class="card p-4 border-0 shadow-sm">
                <h5 class="fw-bold mb-3">Pending Requests</h5>
                <div class="table-responsive">
                    <table class="ui-cards-sm table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Applied At</th>
                                <th>Staff</th>
                                <th>Dates</th>
                                <th>Type</th>
                                <th>Reason</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $pending = array_filter($leaves, fn($l) => $l['status'] === 'pending');
                            if (empty($pending)): ?>
                                <tr><td colspan="6" class="text-center text-muted">No pending leave requests.</td></tr>
                            <?php else: foreach ($pending as $l): ?>
                                <tr>
                                    <td><?php echo date('d M Y', strtotime($l['applied_at'])); ?></td>
                                    <td><strong><?php echo htmlspecialchars($l['staff_name']); ?></strong></td>
                                    <td>
                                        <?php 
                                        $s = date('d M', strtotime($l['start_date']));
                                        $e = date('d M', strtotime($l['end_date']));
                                        echo ($s === $e) ? $s : "$s to $e";
                                        ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo ucfirst($l['leave_type']); ?> Day</span>
                                        <?php if ($l['emergency_leave']): ?>
                                            <span class="badge bg-danger">Emergency</span>
                                        <?php endif; ?>
                                        <?php if ($l['medical_cert_path']): ?>
                                            <a href="../<?php echo $l['medical_cert_path']; ?>" target="_blank" class="badge bg-info text-decoration-none">Medical Cert</a>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($l['reason']); ?></td>
                                    <td>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="leave_id" value="<?php echo $l['id']; ?>">
                                            <button type="submit" name="action" value="approve" class="btn btn-sm btn-success fw-bold"><i class="bi bi-check2"></i> Approve</button>
                                            <button type="submit" name="action" value="reject" class="btn btn-sm btn-danger fw-bold"><i class="bi bi-x-lg"></i> Reject</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="card p-4 border-0 shadow-sm mt-4">
                <h5 class="fw-bold mb-3">Processed Requests</h5>
                <div class="table-responsive">
                    <table class="ui-cards-sm table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Staff</th>
                                <th>Dates</th>
                                <th>Type</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $processed = array_filter($leaves, fn($l) => $l['status'] !== 'pending');
                            if (empty($processed)): ?>
                                <tr><td colspan="4" class="text-center text-muted">No processed requests yet.</td></tr>
                            <?php else: foreach ($processed as $l): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($l['staff_name']); ?></strong></td>
                                    <td>
                                        <?php 
                                        $s = date('d M', strtotime($l['start_date']));
                                        $e = date('d M', strtotime($l['end_date']));
                                        echo ($s === $e) ? $s : "$s to $e";
                                        ?>
                                    </td>
                                    <td><span class="badge bg-secondary"><?php echo ucfirst($l['leave_type']); ?> Day</span></td>
                                    <td>
                                        <?php if ($l['status'] === 'approved'): ?>
                                            <span class="badge bg-success">Approved</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
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
