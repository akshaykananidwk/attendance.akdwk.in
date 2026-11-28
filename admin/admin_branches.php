<?php
session_start();
require_once '../db.php';
check_access(['admin']); // Only Super Admin can manage branches

$company_id = (int) get_company_id();
$message = '';
$hasStatus = db_column_exists($conn, 'branches', 'status');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF validation failed.');
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));
    $late = max(0, (int) ($_POST['late_threshold'] ?? 15));
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if (isset($_POST['add_branch']) && $name !== '') {
        $stmt = $conn->prepare('INSERT INTO branches (company_id, name, address, late_threshold_mins) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('issi', $company_id, $name, $address, $late);
        $stmt->execute();
        write_audit_log($conn, 'branch.add', "name=$name", 'branch', (string) $stmt->insert_id);
        $stmt->close();
        header('Location: admin_branches.php?msg=added');
        exit;
    }
    if (isset($_POST['edit_branch']) && $name !== '') {
        $id = (int) ($_POST['branch_id'] ?? 0);
        if ($hasStatus) {
            $stmt = $conn->prepare('UPDATE branches SET name = ?, address = ?, late_threshold_mins = ?, status = ? WHERE id = ? AND company_id = ?');
            $stmt->bind_param('ssisii', $name, $address, $late, $status, $id, $company_id);
        } else {
            $stmt = $conn->prepare('UPDATE branches SET name = ?, address = ?, late_threshold_mins = ? WHERE id = ? AND company_id = ?');
            $stmt->bind_param('ssiii', $name, $address, $late, $id, $company_id);
        }
        $stmt->execute();
        $stmt->close();
        write_audit_log($conn, 'branch.edit', "id=$id name=$name status=$status", 'branch', (string) $id);
        header('Location: admin_branches.php?msg=updated');
        exit;
    }
    if (isset($_POST['delete_branch'])) {
        $id = (int) ($_POST['branch_id'] ?? 0);
        $stmt = $conn->prepare('SELECT COUNT(*) FROM users WHERE branch_id = ? AND company_id = ?');
        $stmt->bind_param('ii', $id, $company_id);
        $stmt->execute();
        $used = (int) $stmt->get_result()->fetch_row()[0];
        $stmt->close();
        if ($used > 0) {
            header('Location: admin_branches.php?msg=inuse&n=' . $used);
            exit;
        }
        $stmt = $conn->prepare('DELETE FROM branches WHERE id = ? AND company_id = ?');
        $stmt->bind_param('ii', $id, $company_id);
        $stmt->execute();
        $stmt->close();
        write_audit_log($conn, 'branch.delete', "id=$id", 'branch', (string) $id);
        header('Location: admin_branches.php?msg=deleted');
        exit;
    }
}

$msgs = [
    'added' => ['success', 'Branch added successfully.'],
    'updated' => ['success', 'Branch updated.'],
    'deleted' => ['success', 'Branch deleted.'],
    'inuse' => ['warning', 'This branch still has ' . (int) ($_GET['n'] ?? 0) . ' staff member(s). Move them to another branch first, or mark the branch Inactive.'],
];
if (isset($msgs[$_GET['msg'] ?? ''])) {
    [$t, $m] = $msgs[$_GET['msg']];
    $message = "<div class='alert alert-$t fw-semibold'>" . htmlspecialchars($m) . '</div>';
}

$branches = $conn->query(
    "SELECT b.*, (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id AND u.role = 'staff'" . staff_sql_active_filter('u') . ") AS staff_count
     FROM branches b WHERE b.company_id = $company_id ORDER BY b.id"
)->fetch_all(MYSQLI_ASSOC);
$total = count($branches);
$active = count(array_filter($branches, static fn ($b) => ($b['status'] ?? 'active') !== 'inactive'));
$staffTotal = array_sum(array_map(static fn ($b) => (int) $b['staff_count'], $branches));
$csrf = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Management | <?php echo htmlspecialchars(app_brand()); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/saas_theme.css?v=<?php echo app_asset_version(); ?>">
    <?php echo app_pwa_head(); ?>
    <style>.branch-grid{display:none}.branch-grid.on{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1rem}.branch-card{border:1px solid var(--ui-border);border-radius:14px;padding:1rem}</style>
</head>
<body>
<div class="saas-sidebar-backdrop" id="saasSidebarBackdrop" aria-hidden="true"></div>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php require_once __DIR__ . '/../includes/saas_sidebar_admin.php'; ?>
        <div class="col-md-10 main-content">
            <?php echo ui_hero('Branch Management', 'Manage all your organization branches from one place.', 'bi-building', 'store'); ?>
            <?php echo $message; ?>

            <div class="ui-stats">
                <?php
                echo ui_stat($total, 'Total Branches', 'bi-building', 'blue');
                echo ui_stat($active, 'Active Branches', 'bi-check-circle-fill', 'green');
                echo ui_stat($total - $active, 'Inactive Branches', 'bi-pause-circle-fill', 'orange');
                echo ui_stat($staffTotal, 'Total Staff', 'bi-people-fill', 'purple', 'admin_staff.php');
                ?>
            </div>

            <div class="ui-panel">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h5 class="ui-panel-title"><i class="bi bi-list-ul"></i> Branch List</h5>
                    <button class="btn btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#branchModal" onclick="openBranch(null)"><i class="bi bi-plus-circle-fill"></i> Add New Branch</button>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <div class="ui-search" style="flex:1 1 280px;max-width:460px"><i class="bi bi-search"></i><input type="search" id="bSearch" placeholder="Search branch by name, address…" value="<?php echo ui_e($_GET['q'] ?? ''); ?>" style="padding-right:.8rem"></div>
                    <select class="form-select ms-auto" id="bStatus" style="width:auto"><option value="">All Status</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
                    <div class="btn-group"><button class="btn btn-primary" id="vList" type="button" title="List"><i class="bi bi-list-ul"></i></button><button class="btn btn-outline-primary" id="vGrid" type="button" title="Grid"><i class="bi bi-grid"></i></button></div>
                </div>

                <div class="table-responsive" id="branchList">
                    <table class="ui-table">
                        <thead><tr><th>#</th><th>Branch Name</th><th>Address</th><th>Late Threshold</th><th>Staff</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
                        <tbody>
                        <?php if (!$branches): ?><tr><td colspan="7" class="text-center text-muted py-4">No branches yet — add your first branch.</td></tr><?php endif; ?>
                        <?php foreach ($branches as $i => $b): $st = ($b['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active'; ?>
                            <tr class="b-item" data-status="<?php echo $st; ?>" data-text="<?php echo ui_e(strtolower($b['name'] . ' ' . $b['address'])); ?>">
                                <td><?php echo $i + 1; ?></td>
                                <td><div class="d-flex align-items-center gap-2"><span class="ui-stat-icon" style="width:40px;height:40px;flex-basis:40px;border-radius:10px;background:#eaf1ff;color:#1d6cf2;display:inline-flex;align-items:center;justify-content:center"><i class="bi bi-shop"></i></span><b><?php echo ui_e($b['name']); ?></b></div></td>
                                <td><i class="bi bi-geo-alt text-muted me-1"></i><?php echo ui_e($b['address'] ?: '—'); ?></td>
                                <td><span class="ui-pill gray"><i class="bi bi-clock"></i> <?php echo (int) $b['late_threshold_mins']; ?> mins</span></td>
                                <td><span class="ui-pill gray"><i class="bi bi-people"></i> <?php echo (int) $b['staff_count']; ?></span></td>
                                <td><?php echo $st === 'active' ? '<span class="ui-pill green">● Active</span>' : '<span class="ui-pill amber">● Inactive</span>'; ?></td>
                                <td class="text-center text-nowrap">
                                    <a class="ui-act view" title="View staff" href="admin_staff.php?filter=all&q=<?php echo rawurlencode((string) $b['name']); ?>"><i class="bi bi-eye"></i></a>
                                    <button class="ui-act edit" type="button" title="Edit" data-bs-toggle="modal" data-bs-target="#branchModal" onclick='openBranch(<?php echo json_encode(['id' => (int) $b['id'], 'name' => $b['name'], 'address' => $b['address'], 'late' => (int) $b['late_threshold_mins'], 'status' => $st], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)'><i class="bi bi-pencil"></i></button>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete branch <?php echo ui_e(addslashes((string) $b['name'])); ?>?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo ui_e($csrf); ?>"><input type="hidden" name="branch_id" value="<?php echo (int) $b['id']; ?>">
                                        <button class="ui-act del" name="delete_branch" value="1" title="Delete"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="branch-grid" id="branchGrid">
                    <?php foreach ($branches as $b): $st = ($b['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active'; ?>
                        <div class="branch-card b-item" data-status="<?php echo $st; ?>" data-text="<?php echo ui_e(strtolower($b['name'] . ' ' . $b['address'])); ?>">
                            <div class="d-flex justify-content-between"><b><i class="bi bi-shop text-primary me-1"></i><?php echo ui_e($b['name']); ?></b><?php echo $st === 'active' ? '<span class="ui-pill green">Active</span>' : '<span class="ui-pill amber">Inactive</span>'; ?></div>
                            <div class="small text-muted my-2"><i class="bi bi-geo-alt"></i> <?php echo ui_e($b['address'] ?: '—'); ?></div>
                            <span class="ui-pill gray me-1"><i class="bi bi-clock"></i> <?php echo (int) $b['late_threshold_mins']; ?> mins</span><span class="ui-pill gray"><i class="bi bi-people"></i> <?php echo (int) $b['staff_count']; ?> staff</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="branchModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title" id="bmTitle">Add New Branch</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-4">
                <input type="hidden" name="csrf_token" value="<?php echo ui_e($csrf); ?>">
                <input type="hidden" name="branch_id" id="bmId">
                <div class="mb-3"><label class="form-label fw-bold">Branch Name</label><input type="text" name="name" id="bmName" class="form-control" required></div>
                <div class="mb-3"><label class="form-label fw-bold">Address</label><textarea name="address" id="bmAddr" class="form-control" rows="2"></textarea></div>
                <div class="row">
                    <div class="col-6 mb-3"><label class="form-label fw-bold">Late Threshold (min)</label><input type="number" min="0" name="late_threshold" id="bmLate" class="form-control" value="15" required></div>
                    <div class="col-6 mb-3" id="bmStatusWrap"><label class="form-label fw-bold">Status</label><select name="status" id="bmStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                </div>
                <button type="submit" name="add_branch" value="1" id="bmSubmit" class="btn btn-primary w-100 fw-bold">Save Branch</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openBranch(b) {
    var edit = !!b;
    document.getElementById('bmTitle').textContent = edit ? 'Edit Branch' : 'Add New Branch';
    document.getElementById('bmId').value = edit ? b.id : '';
    document.getElementById('bmName').value = edit ? b.name : '';
    document.getElementById('bmAddr').value = edit ? (b.address || '') : '';
    document.getElementById('bmLate').value = edit ? b.late : 15;
    document.getElementById('bmStatus').value = edit ? b.status : 'active';
    document.getElementById('bmStatusWrap').style.display = edit && <?php echo $hasStatus ? 'true' : 'false'; ?> ? '' : 'none';
    var s = document.getElementById('bmSubmit'); s.name = edit ? 'edit_branch' : 'add_branch'; s.textContent = edit ? 'Update Branch' : 'Save Branch';
}
(function () {
    var q = document.getElementById('bSearch'), st = document.getElementById('bStatus');
    function f() { var t = q.value.toLowerCase().trim(), s = st.value; document.querySelectorAll('.b-item').forEach(function (el) { el.style.display = (el.dataset.text.indexOf(t) !== -1 && (!s || el.dataset.status === s)) ? '' : 'none'; }); }
    q.addEventListener('input', f); st.addEventListener('change', f); f();
    var L = document.getElementById('vList'), G = document.getElementById('vGrid'), list = document.getElementById('branchList'), grid = document.getElementById('branchGrid');
    function view(g) { list.style.display = g ? 'none' : ''; grid.classList.toggle('on', g); L.className = g ? 'btn btn-outline-primary' : 'btn btn-primary'; G.className = g ? 'btn btn-primary' : 'btn btn-outline-primary'; try { localStorage.setItem('akdwk_branch_view', g ? 'grid' : 'list'); } catch (e) {} }
    L.onclick = function () { view(false); }; G.onclick = function () { view(true); };
    try { if (localStorage.getItem('akdwk_branch_view') === 'grid') view(true); } catch (e) {}
})();
</script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
