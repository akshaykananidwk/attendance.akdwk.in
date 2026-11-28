<?php
session_start();
require_once '../db.php';
check_access(['admin', 'auditor']);

$company_id = (int) (get_company_id() ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 40;
$offset = ($page - 1) * $per_page;

$action_filter = isset($_GET['action']) ? trim((string) $_GET['action']) : '';
$where = 'company_id = ' . $company_id;
if ($action_filter !== '') {
    $where .= " AND action = '" . $conn->real_escape_string($action_filter) . "'";
}

$total = 0;
$count_res = $conn->query("SELECT COUNT(*) AS c FROM audit_logs WHERE $where");
if ($count_res) {
    $total = (int) $count_res->fetch_assoc()['c'];
}
$total_pages = max(1, (int) ceil($total / $per_page));

$rows = $conn->query(
    "SELECT * FROM audit_logs WHERE $where ORDER BY id DESC LIMIT " . (int) $per_page . " OFFSET " . (int) $offset
);
?>
<!DOCTYPE html>
<html lang="gu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit log | <?php echo htmlspecialchars(app_brand()); ?></title>
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
            <div class="dashboard-header">
                <div>
                    <button class="mobile-toggle me-2" onclick="toggleSidebar()"><i class="bi bi-list fs-4"></i></button>
                    <span class="fs-4 fw-bold text-dark"><i class="bi bi-journal-text text-primary"></i> Audit log</span>
                </div>
            </div>

            <div class="card p-4 mb-3">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Action</label>
                        <input type="text" name="action" class="form-control" placeholder="e.g. attendance.delete" value="<?php echo htmlspecialchars($action_filter); ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100 fw-bold">Filter</button>
                    </div>
                    <div class="col-md-6 text-muted small">
                        કોણે શું કર્યું — વપરાશકર્તા, સમય અને IP. ટેબલ ન હોય તો <code>db_update_audit.php</code> એક વાર ચલાવો.
                    </div>
                </form>
            </div>

            <div class="card p-4">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>When</th>
                                <th>User</th>
                                <th>Role</th>
                                <th>Action</th>
                                <th>Entity</th>
                                <th>Details</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ($rows && $rows->num_rows > 0) {
                                while ($r = $rows->fetch_assoc()) {
                                    echo '<tr>';
                                    echo '<td class="text-nowrap small">' . htmlspecialchars($r['created_at']) . '</td>';
                                    echo '<td>' . htmlspecialchars($r['user_name']) . ' <span class="text-muted">#' . (int) $r['user_id'] . '</span></td>';
                                    echo '<td><span class="badge bg-secondary">' . htmlspecialchars($r['role']) . '</span></td>';
                                    echo '<td class="fw-bold">' . htmlspecialchars($r['action']) . '</td>';
                                    echo '<td class="small">' . htmlspecialchars((string) ($r['entity_type'] ?? '')) . ' ' . htmlspecialchars((string) ($r['entity_id'] ?? '')) . '</td>';
                                    echo '<td class="small text-break" style="max-width:280px;">' . htmlspecialchars((string) ($r['details'] ?? '')) . '</td>';
                                    echo '<td class="small text-nowrap">' . htmlspecialchars($r['ip_address']) . '</td>';
                                    echo '</tr>';
                                }
                            } else {
                                echo '<tr><td colspan="7" class="text-muted text-center py-4">No audit entries yet for this company.</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <nav class="mt-3">
                    <ul class="pagination pagination-sm mb-0">
                        <?php
                        $q = $action_filter !== '' ? '&action=' . urlencode($action_filter) : '';
                        for ($p = 1; $p <= $total_pages; $p++) {
                            $active = $p === $page ? ' active' : '';
                            echo '<li class="page-item' . $active . '"><a class="page-link" href="?page=' . $p . $q . '">' . $p . '</a></li>';
                        }
                        ?>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
