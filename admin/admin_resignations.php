<?php
session_start();
require_once '../db.php';
check_access(['admin', 'manager', 'hr']);

$company_id = (int) get_company_id();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF failed.');
    }
    $id = (int) ($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? 'approved';
    if ($id > 0 && salary_table_exists($conn, 'resignation_requests')) {
        $stmt = $conn->prepare('UPDATE resignation_requests SET status = ? WHERE id = ? AND company_id = ?');
        $stmt->bind_param('sii', $status, $id, $company_id);
        $stmt->execute();
        $stmt->close();
        $message = "<div class='alert alert-success'>Updated.</div>";
    }
}

$rows = [];
if (salary_table_exists($conn, 'resignation_requests')) {
    $stmt = $conn->prepare(
        'SELECT r.*, u.name FROM resignation_requests r JOIN users u ON u.id = r.user_id
         WHERE r.company_id = ? ORDER BY r.id DESC LIMIT 100'
    );
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$csrf = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resignations | <?php echo htmlspecialchars(app_brand()); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/saas_theme.css?v=<?php echo app_asset_version(); ?>">
    <?php echo app_pwa_head(); ?>
</head>
<body>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php require_once __DIR__ . '/../includes/saas_sidebar_admin.php'; ?>
        <div class="col-md-10 main-content p-4">
            <h3 class="fw-bold">Resignation requests</h3>
            <p class="text-muted small">Approved <strong>without notice</strong> blocks last-month salary in reports (Rule 13). Pay salary between 1st–10th (Rule 14).</p>
            <?php echo $message; ?>
            <?php if (!salary_table_exists($conn, 'resignation_requests')): ?>
                <div class="alert alert-warning">Run <a href="../db_update_v6_office_rules.php">migration v6</a> first.</div>
            <?php else: ?>
            <div class="table-responsive card p-3">
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr><th>Staff</th><th>Notice</th><th>Last day</th><th>No notice</th><th>Status</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['name']); ?></td>
                            <td><?php echo htmlspecialchars($r['notice_date']); ?></td>
                            <td><?php echo htmlspecialchars($r['last_working_day']); ?></td>
                            <td><?php echo $r['without_notice'] ? 'Yes' : 'No'; ?></td>
                            <td><?php echo htmlspecialchars($r['status']); ?></td>
                            <td>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                    <select name="status" class="form-select form-select-sm d-inline-block w-auto">
                                        <option value="pending">pending</option>
                                        <option value="approved">approved</option>
                                        <option value="completed">completed</option>
                                        <option value="rejected">rejected</option>
                                    </select>
                                    <button type="submit" name="update_status" class="btn btn-sm btn-primary">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="6">None yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
