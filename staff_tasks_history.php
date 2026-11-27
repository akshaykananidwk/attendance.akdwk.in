<?php
session_start();
require_once 'db.php';
require_once __DIR__ . '/includes/staff_portal_guard.php';

$user_id = (int) $_SESSION['user_id'];
$company_id = get_company_id();

// Fetch Completed Tasks for this staff
$completed_tasks = $conn->query("SELECT t.* FROM tasks t 
                                 JOIN task_assignments ta ON t.id = ta.task_id 
                                 WHERE ta.user_id = $user_id AND t.status = 'Completed' 
                                 ORDER BY t.updated_at DESC");

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Work History | Smart Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/saas_theme.css?v=<?php echo app_asset_version(); ?>">
</head>
<body>
<div class="saas-sidebar-backdrop" id="saasSidebarBackdrop" aria-hidden="true"></div>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php require_once __DIR__ . '/includes/saas_sidebar_staff.php'; ?>
        <div class="col-md-10 main-content">
    <div class="d-flex align-items-center gap-2 mb-4">
        <button type="button" class="mobile-toggle" onclick="toggleSidebar()" aria-label="Menu"><i class="bi bi-list fs-4"></i></button>
        <h2 class="fw-bold mb-0">Completed Work History</h2>
    </div>

    <div class="row">
        <div class="col-lg-12">
            <?php if($completed_tasks->num_rows > 0): ?>
                <?php while($t = $completed_tasks->fetch_assoc()): ?>
                    <div class="card history-card p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <span class="badge bg-info text-dark me-2"><?php echo $t['task_unit_id']; ?></span>
                                <span class="badge bg-success">Completed</span>
                            </div>
                            <small class="text-muted"><?php echo date("d M Y", strtotime($t['updated_at'])); ?></small>
                        </div>
                        <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($t['title']); ?></h5>
                        <div class="text-primary small fw-bold mb-2"><i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($t['client_name']); ?> | <?php echo htmlspecialchars($t['client_company']); ?></div>
                        
                        <div class="bg-light p-2 rounded mb-2 small">
                            <strong>My Notes:</strong> <?php echo nl2br(htmlspecialchars($t['completion_notes'])); ?>
                        </div>

                        <?php if($t['photo_after']): ?>
                            <div>
                                <a href="uploads/tasks/<?php echo $t['photo_after']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-image"></i> View My Photo
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-journal-x fs-1 text-muted"></i>
                    <p class="text-muted mt-2">No completed tasks found in your history.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
