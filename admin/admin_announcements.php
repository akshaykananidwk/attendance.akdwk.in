<?php
session_start();
require_once '../db.php';
check_access(['admin', 'manager', 'hr']);

$company_id = (int)get_company_id();
$branch_id = (int)get_branch_id();
$message = "";

// 1. ADD ANNOUNCEMENT
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_announcement'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) die("CSRF validation failed.");
    
    $title = $_POST['title'];
    $content = $_POST['content'];
    $visibility = $_POST['visibility'];
    $created_by = $_SESSION['user_id'];
    $b_id = $_POST['branch_id'] ?: NULL;

    $stmt = $conn->prepare("INSERT INTO announcements (company_id, branch_id, title, content, visibility, created_by) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssi", $company_id, $b_id, $title, $content, $visibility, $created_by);
    if ($stmt->execute()) {
        $message = "<div class='alert alert-success fw-bold'>Announcement posted successfully!</div>";
        if (isset($_POST['notify_whatsapp'])) {
            sendWhatsAppGroupNotification("📢 *NEW ANNOUNCEMENT*\n\n*$title*\n\n$content\n\n_Check the dashboard for details._");
        }
    }
    $stmt->close();
}

// 2. EDIT ANNOUNCEMENT
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_announcement'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) die("CSRF validation failed.");
    
    $id = (int)$_POST['announcement_id'];
    $title = $_POST['title'];
    $content = $_POST['content'];
    $visibility = $_POST['visibility'];
    $b_id = $_POST['branch_id'] ?: NULL;

    $stmt = $conn->prepare("UPDATE announcements SET branch_id=?, title=?, content=?, visibility=? WHERE id=? AND company_id=?");
    $stmt->bind_param("isssii", $b_id, $title, $content, $visibility, $id, $company_id);
    if ($stmt->execute()) {
        $message = "<div class='alert alert-success fw-bold'>Announcement updated successfully!</div>";
    }
    $stmt->close();
}

// 3. DELETE ANNOUNCEMENT
if (isset($_GET['delete_id'])) {
    $id = (int)$_GET['delete_id'];
    $conn->query("DELETE FROM announcements WHERE id=$id AND company_id=$company_id");
    header("Location: admin_announcements.php?msg=deleted");
    exit();
}

$announcements = $conn->query("SELECT a.*, u.name as author FROM announcements a 
                               JOIN users u ON a.created_by = u.id 
                               WHERE a.company_id = $company_id ORDER BY a.id DESC");
$branches = $conn->query("SELECT id, name FROM branches WHERE company_id = $company_id");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements | Smart Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/saas_theme.css">
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
            <span class="fs-4 fw-bold text-dark"><i class="bi bi-megaphone text-primary"></i> Announcement Board</span>
        </div>
        <button class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addNoticeModal">
            <i class="bi bi-plus-circle-fill"></i> Post New Notice
        </button>
    </div>

    <?php if($message != "") echo $message; ?>

    <div class="row">
        <?php while($a = $announcements->fetch_assoc()): ?>
        <div class="col-md-6 mb-4">
            <div class="card p-4">
                <div class="d-flex justify-content-between mb-2">
                    <span class="badge bg-secondary">For: <?php echo ucfirst($a['visibility']); ?></span>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light border" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#" onclick='editNotice(<?php echo json_encode($a); ?>)'><i class="bi bi-pencil text-primary me-2"></i> Edit</a></li>
                            <li><a class="dropdown-item text-danger" href="?delete_id=<?php echo $a['id']; ?>" onclick="return confirm('Delete this announcement?')"><i class="bi bi-trash me-2"></i> Delete</a></li>
                        </ul>
                    </div>
                </div>
                <h4 class="fw-bold mb-2 text-primary"><?php echo htmlspecialchars($a['title']); ?></h4>
                <p class="text-dark"><?php echo nl2br(htmlspecialchars($a['content'])); ?></p>
                <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top small text-muted">
                    <span>Posted by: <strong><?php echo $a['author']; ?></strong></span>
                    <span><?php echo date("d M, Y", strtotime($a['created_at'])); ?></span>
                </div>
            </div>
        </div>
        <?php endwhile; ?>
    </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addNoticeModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalTitle">Post New Notice</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="announcement_id" id="edit_announcement_id">
                <div class="mb-3">
                    <label class="form-label fw-bold">Title</label>
                    <input type="text" name="title" id="edit_title" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Content</label>
                    <textarea name="content" id="edit_content" class="form-control" rows="5" required></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Visibility</label>
                    <select name="visibility" id="edit_visibility" class="form-select">
                        <option value="all">All Users</option>
                        <option value="staff">Staff Only</option>
                        <option value="manager">Managers & HR Only</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Select Branch (Optional)</label>
                    <select name="branch_id" id="edit_branch_id" class="form-select">
                        <option value="">All Branches</option>
                        <?php 
                        $branches->data_seek(0);
                        while($b = $branches->fetch_assoc()): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo $b['name']; ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="mb-3 form-check" id="notifyContainer">
                    <input type="checkbox" name="notify_whatsapp" class="form-check-input" id="notifyWA">
                    <label class="form-check-label fw-bold text-success" for="notifyWA">Notify via WhatsApp Group</label>
                </div>
                <button type="submit" name="add_announcement" id="submitBtn" class="btn btn-primary w-100 fw-bold">Post Announcement</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function editNotice(notice) {
        document.getElementById('modalTitle').innerText = "Edit Announcement";
        document.getElementById('edit_announcement_id').value = notice.id;
        document.getElementById('edit_title').value = notice.title;
        document.getElementById('edit_content').value = notice.content;
        document.getElementById('edit_visibility').value = notice.visibility;
        document.getElementById('edit_branch_id').value = notice.branch_id || "";
        document.getElementById('notifyContainer').style.display = "none";
        document.getElementById('submitBtn').name = "edit_announcement";
        document.getElementById('submitBtn').innerText = "Update Announcement";
        
        let modal = new bootstrap.Modal(document.getElementById('addNoticeModal'));
        modal.show();
    }
    
    // Reset modal when opened via "Post New Notice" button
    document.querySelector('[data-bs-target="#addNoticeModal"]').addEventListener('click', function() {
        document.getElementById('modalTitle').innerText = "Post New Notice";
        document.getElementById('edit_announcement_id').value = "";
        document.getElementById('edit_title').value = "";
        document.getElementById('edit_content').value = "";
        document.getElementById('edit_visibility').value = "all";
        document.getElementById('edit_branch_id').value = "";
        document.getElementById('notifyContainer').style.display = "block";
        document.getElementById('submitBtn').name = "add_announcement";
        document.getElementById('submitBtn').innerText = "Post Announcement";
    });
</script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
