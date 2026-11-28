<?php
session_start();
require_once '../db.php';
check_access(['admin', 'manager', 'hr', 'auditor']);

$company_id = (int) get_company_id();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && is_auditor()) {
    header('Location: admin_reports.php');
    exit;
}

// --- Update client ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_client'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF validation failed.');
    }
    $cid = (int) ($_POST['client_id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $client_company = trim((string) ($_POST['client_company'] ?? ''));

    $qret = urlencode($_GET['q'] ?? '');
    if ($cid <= 0 || $name === '' || $phone === '') {
        if ($cid > 0) {
            header('Location: admin_clients.php?view_id=' . $cid . '&q=' . $qret . '&err=invalid');
        } else {
            header('Location: admin_clients.php?q=' . $qret . '&err=invalid');
        }
        exit;
    }
    $dup = $conn->prepare('SELECT id FROM clients WHERE company_id = ? AND phone = ? AND id != ?');
    $dup->bind_param('isi', $company_id, $phone, $cid);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) {
        $dup->close();
        header('Location: admin_clients.php?view_id=' . $cid . '&q=' . $qret . '&err=duplicate_phone');
        exit;
    }
    $dup->close();
    $stmt = $conn->prepare('UPDATE clients SET name = ?, phone = ?, client_company = ? WHERE id = ? AND company_id = ?');
    $stmt->bind_param('sssii', $name, $phone, $client_company, $cid, $company_id);
    if ($stmt->execute()) {
        write_audit_log($conn, 'client.update', "id={$cid} name={$name}", 'clients', (string) $cid);
    }
    $stmt->close();
    header('Location: admin_clients.php?view_id=' . $cid . '&q=' . $qret);
    exit;
}

// --- Delete client ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_client'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF validation failed.');
    }
    $cid = (int) ($_POST['client_id'] ?? 0);
    if ($cid > 0) {
        $stmt = $conn->prepare('DELETE FROM clients WHERE id = ? AND company_id = ?');
        $stmt->bind_param('ii', $cid, $company_id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            write_audit_log($conn, 'client.delete', "deleted id={$cid}", 'clients', (string) $cid);
        }
        $stmt->close();
    }
    header('Location: admin_clients.php?q=' . urlencode($_GET['q'] ?? ''));
    exit;
}

$search = $_GET['q'] ?? '';
$where = "company_id = $company_id";
if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (name LIKE '%$s%' OR phone LIKE '%$s%' OR client_company LIKE '%$s%')";
}

$clients = $conn->query("SELECT * FROM clients WHERE $where ORDER BY name ASC");

$view_id = $_GET['view_id'] ?? null;
$client_history = null;
$client_details = null;

if ($view_id) {
    $client_details = $conn->query('SELECT * FROM clients WHERE id = ' . (int) $view_id . " AND company_id = $company_id")->fetch_assoc();
    if ($client_details) {
        $ph = $conn->real_escape_string($client_details['phone']);
        $nm = $conn->real_escape_string($client_details['name']);
        
        // Extract last 10 digits for robust matching
        $ph_digits = preg_replace('/[^0-9]/', '', $ph);
        $ph_last10 = substr($ph_digits, -10);
        if (strlen($ph_last10) == 10) {
            $ph_cond = "t.client_phone LIKE '%$ph_last10%'";
        } else {
            $ph_cond = "t.client_phone = '$ph'";
        }

        $client_history = $conn->query("SELECT t.*, 
                                       (SELECT GROUP_CONCAT(u.name SEPARATOR ', ') FROM task_assignments ta JOIN users u ON ta.user_id = u.id WHERE ta.task_id = t.id) as staff_names 
                                       FROM tasks t 
                                       WHERE ($ph_cond OR t.client_name = '$nm') 
                                       AND t.company_id = $company_id 
                                       ORDER BY t.id DESC");
    }
}

$csrf = generate_csrf_token();
$can_mutate = !is_auditor();
$err = $_GET['err'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client History | <?php echo htmlspecialchars(app_brand()); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/saas_theme.css?v=<?php echo app_asset_version(); ?>">
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
            <span class="fs-4 fw-bold text-dark"><i class="bi bi-person-lines-fill text-primary"></i> Client Database</span>
        </div>
        <form method="GET" class="d-flex gap-2">
            <input type="text" name="q" class="form-control" placeholder="Search client..." value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
        </form>
    </div>

    <?php if ($err === 'duplicate_phone'): ?>
        <div class="alert alert-warning fw-bold">આ ફોન નંબર આ કંપનીમાં બીજા ક્લાયન્ટ પર પહેલેથી છે.</div>
    <?php elseif ($err === 'invalid'): ?>
        <div class="alert alert-danger fw-bold">નામ અને ફોન જરૂરી છે.</div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-4">
            <div class="card p-3">
                <h6 class="fw-bold mb-3 border-bottom pb-2">Client List</h6>
                <div class="list-group list-group-flush" style="max-height: 600px; overflow-y: auto;">
                    <?php if ($clients->num_rows > 0): ?>
                        <?php while ($c = $clients->fetch_assoc()): ?>
                            <a href="?view_id=<?php echo (int) $c['id']; ?>&q=<?php echo urlencode($search); ?>" class="list-group-item list-group-item-action <?php echo ($view_id == $c['id']) ? 'active' : ''; ?>">
                                <div class="fw-bold"><?php echo htmlspecialchars($c['name']); ?></div>
                                <div class="small <?php echo ($view_id == $c['id']) ? 'text-white' : 'text-muted'; ?>"><?php echo htmlspecialchars($c['phone']); ?></div>
                                <div class="small <?php echo ($view_id == $c['id']) ? 'text-white' : 'text-muted'; ?>"><?php echo htmlspecialchars($c['client_company'] ?? ''); ?></div>
                            </a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p class="text-center text-muted p-3">No clients found.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <?php if ($client_details): ?>
                <div class="card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                        <h4 class="fw-bold text-primary m-0"><i class="bi bi-journal-text"></i> History for <?php echo htmlspecialchars($client_details['name']); ?></h4>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php if ($can_mutate): ?>
                                <button type="button" class="btn btn-outline-primary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#editClientModal">
                                    <i class="bi bi-pencil-square"></i> Edit client
                                </button>
                                <form method="post" class="d-inline" onsubmit="return confirm('આ ક્લાયન્ટ ડિલીટ કરવો? ટાસ્ક ઇતિહાસ ડેટાબેઝમાં રહેશે.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="client_id" value="<?php echo (int) $client_details['id']; ?>">
                                    <button type="submit" name="delete_client" class="btn btn-outline-danger btn-sm fw-bold">
                                        <i class="bi bi-trash"></i> Delete
                                    </button>
                                </form>
                            <?php endif; ?>
                            <a href="https://wa.me/<?php echo str_replace('+', '', $client_details['phone']); ?>" target="_blank" rel="noopener" class="btn btn-success btn-sm fw-bold">
                                <i class="bi bi-whatsapp"></i> Chat
                            </a>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded shadow-sm">
                                <small class="text-muted d-block">Company</small>
                                <span class="fw-bold"><?php echo htmlspecialchars($client_details['client_company'] ?: 'N/A'); ?></span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded shadow-sm">
                                <small class="text-muted d-block">Phone</small>
                                <span class="fw-bold"><?php echo htmlspecialchars($client_details['phone']); ?></span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded shadow-sm">
                                <small class="text-muted d-block">Client Since</small>
                                <span class="fw-bold"><?php echo date('d M, Y', strtotime($client_details['created_at'])); ?></span>
                            </div>
                        </div>
                    </div>

                    <h6 class="fw-bold mb-3">Previous Tasks & Work Done</h6>
                    <?php if ($client_history && $client_history->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle border">
                                <thead class="table-light">
                                    <tr>
                                        <th>Unit ID</th>
                                        <th>Date</th>
                                        <th>Category</th>
                                        <th>Staff</th>
                                        <th>Status</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($t = $client_history->fetch_assoc()): ?>
                                        <tr>
                                            <td class="fw-bold text-info"><?php echo htmlspecialchars((string) $t['task_unit_id']); ?></td>
                                            <td class="small"><?php echo date('d M Y', strtotime($t['created_at'])); ?></td>
                                            <td><span class="badge bg-dark"><?php echo htmlspecialchars($t['category']); ?></span></td>
                                            <td class="small fw-bold text-primary"><?php echo htmlspecialchars((string) ($t['staff_names'] ?? '')); ?></td>
                                            <td>
                                                <span class="badge <?php
                                                    if ($t['status'] === 'Completed') {
                                                        echo 'bg-success';
                                                    } elseif ($t['status'] === 'Waiting for Parts') {
                                                        echo 'bg-danger';
                                                    } else {
                                                        echo 'bg-warning';
                                                    }
                                                ?>"><?php echo htmlspecialchars($t['status']); ?></span>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-primary" onclick='showTaskDetails(<?php echo json_encode($t); ?>)'>View</button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">No previous tasks found for this client.</div>
                    <?php endif; ?>
                </div>

                <?php if ($can_mutate): ?>
                <div class="modal fade" id="editClientModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <form method="post" class="modal-content">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title fw-bold">Edit client</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body p-4">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="client_id" value="<?php echo (int) $client_details['id']; ?>">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Name</label>
                                    <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($client_details['name']); ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Phone</label>
                                    <input type="text" name="phone" class="form-control" required value="<?php echo htmlspecialchars($client_details['phone']); ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Company</label>
                                    <input type="text" name="client_company" class="form-control" value="<?php echo htmlspecialchars($client_details['client_company'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="modal-footer bg-light">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" name="update_client" class="btn btn-primary fw-bold">Save</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="card p-5 text-center shadow-sm">
                    <i class="bi bi-search text-muted fs-1 mb-3"></i>
                    <h5 class="fw-bold text-muted">Select a client to view history</h5>
                    <p class="text-muted">Search by name, mobile, or company to see all past work records.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
        </div>
    </div>
</div>

<div class="modal fade" id="taskDetailModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Task Record Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div id="modalBody" class="modal-body p-4"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function showTaskDetails(t) {
        let photos = '';
        if (t.photo_before) photos += `<div class="mb-2"><small class="text-muted">Before:</small><br><img src="../uploads/tasks/${t.photo_before}" class="img-fluid rounded border" alt=""></div>`;
        if (t.photo_after) photos += `<div class="mb-2"><small class="text-muted">After:</small><br><img src="../uploads/tasks/${t.photo_after}" class="img-fluid rounded border" alt=""></div>`;

        let parts = t.pending_parts ? `<div class="alert alert-danger py-1 px-2 small mb-2 fw-bold"><i class="bi bi-tools"></i> Pending Parts: ${t.pending_parts}</div>` : '';

        document.getElementById('modalBody').innerHTML = `
            <div class="d-flex justify-content-between">
                <h5 class="fw-bold">${t.title}</h5>
                <span class="badge bg-info text-dark">${t.task_unit_id}</span>
            </div>
            <p class="text-muted small">${t.description}</p>
            ${parts}
            <hr>
            <div class="mb-2"><strong>Category:</strong> ${t.category}</div>
            <div class="mb-2"><strong>Staff:</strong> ${t.staff_names || ''}</div>
            <div class="mb-2"><strong>Completed:</strong> ${t.status}</div>
            <div class="mb-3"><strong>Notes:</strong> ${t.completion_notes || 'None'}</div>
            ${photos}
        `;
        new bootstrap.Modal(document.getElementById('taskDetailModal')).show();
    }
</script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
