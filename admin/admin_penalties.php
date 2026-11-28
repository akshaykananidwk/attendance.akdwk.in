<?php
session_start();
require_once '../db.php';
check_access(['admin', 'manager', 'hr']);

$company_id = (int) get_company_id();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_penalty'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF failed.');
    }
    $user_id = (int) ($_POST['user_id'] ?? 0);
    $penalty_date = $_POST['penalty_date'] ?? date('Y-m-d');
    $penalty_type = $_POST['penalty_type'] ?? 'half_day';
    $deduction_days = (float) ($_POST['deduction_days'] ?? 0.5);
    $reason = trim($_POST['reason'] ?? '');

    $map = [
        'charger_switch' => 0.5,
        'social_media' => 0.5,
        'half_day' => 0.5,
        'double_absence' => 2.0,
        'custom' => $deduction_days,
    ];
    $deduction_days = $map[$penalty_type] ?? 0.5;

    if ($user_id > 0 && $reason !== '' && salary_table_exists($conn, 'staff_penalties')) {
        $stmt = $conn->prepare(
            'INSERT INTO staff_penalties (user_id, company_id, penalty_date, penalty_type, deduction_days, reason, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $admin_id = (int) $_SESSION['user_id'];
        $stmt->bind_param('iissdsi', $user_id, $company_id, $penalty_date, $penalty_type, $deduction_days, $reason, $admin_id);
        if ($stmt->execute()) {
            write_audit_log($conn, 'penalty.add', "user=$user_id days=$deduction_days type=$penalty_type");
            $message = "<div class='alert alert-success'>Penalty recorded.</div>";
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_penalty'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF failed.');
    }
    $delete_id = (int) ($_POST['delete_id'] ?? 0);
    if ($delete_id > 0 && salary_table_exists($conn, 'staff_penalties')) {
        $stmt = $conn->prepare('DELETE FROM staff_penalties WHERE id = ? AND company_id = ?');
        $stmt->bind_param('ii', $delete_id, $company_id);
        if ($stmt->execute()) {
            write_audit_log($conn, 'penalty.delete', "deleted id=$delete_id");
            $message = "<div class='alert alert-success'>Penalty deleted successfully.</div>";
        }
        $stmt->close();
    }
}

$staff_list = [];
$activeF = staff_sql_active_filter('');
$stmt = $conn->prepare("SELECT id, name FROM users WHERE role = 'staff' AND company_id = ?{$activeF} ORDER BY name");
$stmt->bind_param('i', $company_id);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $staff_list[] = $r;
}
$stmt->close();

$penalties = [];
if (salary_table_exists($conn, 'staff_penalties')) {
    $stmt = $conn->prepare(
        'SELECT p.*, u.name FROM staff_penalties p JOIN users u ON u.id = p.user_id
         WHERE p.company_id = ? ORDER BY p.penalty_date DESC, p.id DESC LIMIT 50'
    );
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $penalties = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$csrf = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Penalties | <?php echo htmlspecialchars(app_brand()); ?></title>
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
            <h3 class="fw-bold mb-3"><i class="bi bi-exclamation-octagon text-danger"></i> Manual penalties</h3>
            <p class="text-muted small">Rules 4–5 (charger switch), 8–9 (discipline): record half-day or custom deductions. Applied automatically in salary reports.</p>
            <?php echo $message; ?>
            <?php if (!salary_table_exists($conn, 'staff_penalties')): ?>
                <div class="alert alert-warning">Run <a href="../db_update_v6_office_rules.php">db_update_v6_office_rules.php</a> once to enable penalties.</div>
            <?php else: ?>
            <div class="card p-4 mb-4">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Staff</label>
                            <select name="user_id" class="form-select" required>
                                <option value="">Select…</option>
                                <?php foreach ($staff_list as $s): ?>
                                    <option value="<?php echo (int) $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Date</label>
                            <input type="date" name="penalty_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Type</label>
                            <select name="penalty_type" class="form-select">
                                <option value="charger_switch">Charger switch not OFF (½ day)</option>
                                <option value="social_media">Games / social media (½ day)</option>
                                <option value="half_day">Half day (½ day)</option>
                                <option value="double_absence">Double absence (2 days)</option>
                                <option value="custom">Custom amount</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Custom days</label>
                            <input type="number" step="0.5" name="deduction_days" class="form-control" value="0.5">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Reason</label>
                            <textarea name="reason" class="form-control" rows="2" required></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" name="add_penalty" class="btn btn-danger fw-bold">Save penalty</button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="card p-4">
                <h5 class="fw-bold">Recent penalties</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light"><tr><th>Date</th><th>Staff</th><th>Type</th><th>Days</th><th>Reason</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($penalties as $p): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($p['penalty_date']); ?></td>
                                <td><?php echo htmlspecialchars($p['name']); ?></td>
                                <td><?php echo htmlspecialchars($p['penalty_type']); ?></td>
                                <td><?php echo htmlspecialchars((string) $p['deduction_days']); ?></td>
                                <td><?php echo htmlspecialchars($p['reason']); ?></td>
                                <td>
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this penalty? (આ પેનલ્ટી ડીલીટ કરવી છે?)');" class="m-0">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="delete_id" value="<?php echo (int) $p['id']; ?>">
                                        <button type="submit" name="delete_penalty" class="btn btn-sm btn-outline-danger border-0"><i class="bi bi-trash fs-5"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$penalties): ?><tr><td colspan="6" class="text-muted">No penalties yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
