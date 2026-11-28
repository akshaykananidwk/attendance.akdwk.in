<?php
session_start();
require_once '../db.php';
check_access(['admin', 'manager', 'hr']);

$company_id = (int) get_company_id();
staff_ensure_activity_table($conn);

$filterUser = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$filterArchived = !empty($_GET['archived_only']);

$where = 'l.company_id = ' . $company_id;
if ($filterUser > 0) {
    $where .= ' AND l.user_id = ' . $filterUser;
}
if ($filterArchived) {
    $where .= " AND l.account_status IN ('inactive','disabled')";
}

$staffList = $conn->query(
    "SELECT id, name, account_status, last_login_at, last_activity_at FROM users
     WHERE role = 'staff' AND company_id = $company_id ORDER BY name"
);

$logs = $conn->query(
    "SELECT l.* FROM staff_activity_log l
     WHERE $where
     ORDER BY l.id DESC
     LIMIT 500"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Activity | Admin</title>
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
            <h3 class="fw-bold mb-3"><i class="bi bi-clock-history"></i> Staff login &amp; activity</h3>
            <p class="text-muted small">Tracks logins and page views for inactive/disabled (archived) employees.</p>

            <form method="get" class="row g-2 mb-4 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-bold small">Employee</label>
                    <select name="user_id" class="form-select">
                        <option value="0">All staff</option>
                        <?php if ($staffList): while ($s = $staffList->fetch_assoc()): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $filterUser === (int) $s['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['name']); ?>
                            (<?php echo htmlspecialchars($s['account_status'] ?? 'active'); ?>)
                        </option>
                        <?php endwhile;
                        endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="archived_only" id="archived_only" value="1" <?php echo $filterArchived ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="archived_only">Archived staff only</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100 fw-bold">Filter</button>
                </div>
                <div class="col-md-2">
                    <a href="admin_staff.php" class="btn btn-outline-secondary w-100">Back to staff</a>
                </div>
            </form>

            <?php if ($filterUser > 0 && employee_lifecycle_ready($conn)):
                $u = $conn->query("SELECT name, account_status, last_login_at, last_activity_at, status_note FROM users WHERE id = $filterUser LIMIT 1")->fetch_assoc();
                if ($u): ?>
            <div class="alert alert-light border mb-3">
                <strong><?php echo htmlspecialchars($u['name']); ?></strong> —
                Status: <span class="badge <?php echo staff_status_badge_class($u['account_status'] ?? 'active'); ?>"><?php echo staff_status_label($u['account_status'] ?? 'active'); ?></span>
                <?php if (!empty($u['last_login_at'])): ?> | Last login: <?php echo date('d M Y H:i', strtotime($u['last_login_at'])); ?><?php endif; ?>
                <?php if (!empty($u['last_activity_at'])): ?> | Last activity: <?php echo date('d M Y H:i', strtotime($u['last_activity_at'])); ?><?php endif; ?>
                <?php if (!empty($u['status_note'])): ?> | Note: <?php echo htmlspecialchars($u['status_note']); ?><?php endif; ?>
            </div>
            <?php endif;
            endif; ?>

            <div class="table-responsive card p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>When</th>
                            <th>Employee</th>
                            <th>Status</th>
                            <th>Action</th>
                            <th>Page</th>
                            <th>Details</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($logs && $logs->num_rows > 0): while ($r = $logs->fetch_assoc()): ?>
                        <tr class="<?php echo in_array($r['account_status'] ?? '', ['inactive', 'disabled'], true) ? 'table-warning' : ''; ?>">
                            <td class="text-nowrap small"><?php echo date('d M Y H:i:s', strtotime($r['created_at'])); ?></td>
                            <td class="fw-bold"><?php echo htmlspecialchars($r['user_name'] ?? ''); ?></td>
                            <td><span class="badge <?php echo staff_status_badge_class($r['account_status'] ?? 'active'); ?>"><?php echo htmlspecialchars($r['account_status'] ?? ''); ?></span></td>
                            <td><code><?php echo htmlspecialchars($r['action']); ?></code></td>
                            <td class="small"><?php echo htmlspecialchars($r['page_url'] ?? ''); ?></td>
                            <td class="small"><?php echo htmlspecialchars($r['details'] ?? ''); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars($r['ip_address'] ?? ''); ?></td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No activity logged yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
