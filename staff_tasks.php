<?php
session_start();
require_once 'db.php';
require_once __DIR__ . '/includes/staff_portal_guard.php';

$user_id = (int) $_SESSION['user_id'];
$company_id = get_company_id();
$message = "";

// Handle Task Actions (Start/Complete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['task_action'])) {
    staff_require_can_mutate();
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die('CSRF validation failed.');
    }
    
    $task_id = $_POST['task_id'];
    $action = $_POST['action_type']; // 'start' or 'complete'
    $notes = $_POST['notes'] ?? '';
    $pending_parts = $_POST['pending_parts'] ?? '';
    
    $upload_dir = 'uploads/tasks/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    
    if ($action == 'start') {
        $photo_name = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $photo_name = 'before_' . $task_id . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $photo_name);
        }
        
        $stmt = $conn->prepare("UPDATE tasks SET status = 'In Progress', photo_before = ? WHERE id = ?");
        $stmt->bind_param("si", $photo_name, $task_id);
        $stmt->execute();
        $stmt->close();
        $message = "<div class='alert alert-primary fw-bold'>Task started! Photo uploaded.</div>";
    } else {
        $photo_name = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $photo_name = 'after_' . $task_id . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $photo_name);
        }
        
        $new_status = ($pending_parts !== '') ? 'Waiting for Parts' : 'Completed';
        
        $stmt = $conn->prepare("UPDATE tasks SET status = ?, photo_after = ?, completion_notes = ?, pending_parts = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $new_status, $photo_name, $notes, $pending_parts, $task_id);
        $stmt->execute();
        $stmt->close();
        
        // WhatsApp to Client on Completion
        if ($new_status == 'Completed') {
            $task_res = $conn->query("SELECT client_phone, client_name, task_unit_id, title FROM tasks WHERE id = $task_id");
            $task_data = $task_res->fetch_assoc();
            
            if (!empty($task_data['client_phone'])) {
                $comp_msg = "✅ *Work Completed Successfully* ✅\n\n"
                          . "🆔 *Task ID:* " . $task_data['task_unit_id'] . "\n"
                          . "📌 *Work:* " . $task_data['title'] . "\n\n"
                          . "──────────────────\n"
                          . "Your service request has been completed. If you need anything further, please contact our office:\n\n"
                          . "📞 *Office:* 9978123146\n"
                          . "──────────────────\n\n"
                          . "🙏 Thank you for your business!";
                sendWhatsAppDirectMessage($task_data['client_phone'], $comp_msg);
            }
        }

        $msg_text = ($new_status == 'Waiting for Parts') ? "Task updated: Waiting for parts." : "Task completed! Well done.";
        $message = "<div class='alert alert-success fw-bold'>$msg_text</div>";
    }
}

// Fetch Tasks via Junction Table
$pending_tasks = $conn->query("SELECT t.* FROM tasks t 
                               JOIN task_assignments ta ON t.id = ta.task_id 
                               WHERE ta.user_id = $user_id AND t.status != 'Completed' 
                               ORDER BY t.priority='High' DESC, t.deadline ASC");

$completed_tasks = $conn->query("SELECT t.* FROM tasks t 
                                 JOIN task_assignments ta ON t.id = ta.task_id 
                                 WHERE ta.user_id = $user_id AND t.status = 'Completed' 
                                 ORDER BY t.id DESC LIMIT 10");

$csrf_token = generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tasks | <?php echo htmlspecialchars(app_brand()); ?></title>
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
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="mobile-toggle" onclick="toggleSidebar()" aria-label="Menu"><i class="bi bi-list fs-4"></i></button>
            <h2 class="fw-bold text-dark m-0">My Assignments</h2>
        </div>
        <span class="badge bg-white text-dark shadow-sm px-3 py-2 rounded-pill"><?php echo date('d M, Y'); ?></span>
    </div>

    <?php if($message != "") echo $message; ?>

    <div class="row">
        <div class="col-lg-8">
            <h5 class="fw-bold mb-3 text-primary"><i class="bi bi-clock-history"></i> Current Active Tasks</h5>
            <?php if($pending_tasks->num_rows > 0): ?>
                <?php while($t = $pending_tasks->fetch_assoc()): ?>
                    <div class="card task-card p-4 priority-<?php echo $t['priority']; ?>">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h4 class="fw-bold mb-1"><?php echo htmlspecialchars($t['title']); ?></h4>
                                <div class="d-flex gap-2">
                                    <span class="badge bg-info text-dark"><?php echo $t['task_unit_id']; ?></span>
                                    <span class="badge <?php 
                                        if($t['status'] == 'Completed') echo 'bg-success';
                                        elseif($t['status'] == 'Waiting for Parts') echo 'bg-danger';
                                        elseif($t['status'] == 'In Progress') echo 'bg-info';
                                        else echo 'bg-secondary';
                                    ?> px-3"><?php echo $t['status']; ?></span>
                                    <span class="badge bg-dark px-3"><?php echo htmlspecialchars($t['category']); ?></span>
                                </div>
                            </div>
                            <div class="text-end">
                                <small class="text-muted d-block">Deadline</small>
                                <span class="text-danger fw-bold"><?php echo date("d M Y", strtotime($t['deadline'])); ?></span>
                            </div>
                        </div>

                        <?php if($t['client_name']): ?>
                        <div class="bg-primary bg-opacity-10 p-2 rounded mb-3 small d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold text-primary"><i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($t['client_name']); ?></div>
                                <div class="text-muted"><i class="bi bi-telephone-fill"></i> <?php echo htmlspecialchars($t['client_phone']); ?></div>
                                <div class="text-muted"><i class="bi bi-building"></i> <?php echo htmlspecialchars($t['client_company']); ?></div>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="tel:<?php echo $t['client_phone']; ?>" class="btn btn-sm btn-primary rounded-circle"><i class="bi bi-telephone"></i></a>
                                <a href="https://wa.me/<?php echo '91' . preg_replace('/[^0-9]/', '', $t['client_phone']); ?>" target="_blank" class="btn btn-sm btn-success rounded-circle"><i class="bi bi-whatsapp"></i></a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="bg-warning bg-opacity-10 p-3 rounded-3 border-start border-4 border-warning mb-4">
                            <small class="text-warning fw-bold d-block mb-1"><i class="bi bi-info-circle"></i> Admin Instructions:</small>
                            <div class="text-dark fw-semibold"><?php echo nl2br(htmlspecialchars($t['description'])); ?></div>
                        </div>
                        
                        <div class="bg-light p-3 rounded-3 mb-3">
                            <form action="" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="task_id" value="<?php echo $t['id']; ?>">
                                <input type="hidden" name="task_action" value="1">
                                
                                <?php if($t['status'] == 'Pending'): ?>
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold">Upload "Before" Photo (Starting Work)</label>
                                        <input type="file" name="photo" class="form-control form-control-sm" accept="image/*" required>
                                    </div>
                                    <input type="hidden" name="action_type" value="start">
                                    <button type="submit" class="btn btn-start w-100 shadow-sm"><i class="bi bi-play-fill"></i> Start This Task</button>
                                <?php else: ?>
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold">Upload "After" Photo (Completed Work)</label>
                                        <input type="file" name="photo" class="form-control form-control-sm" accept="image/*" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold">Work Summary / Notes</label>
                                        <div class="input-group">
                                            <textarea name="notes" id="notes_<?php echo $t['id']; ?>" class="form-control form-control-sm" rows="2" placeholder="Describe what you did..."></textarea>
                                            <button type="button" class="btn btn-outline-secondary voice-btn" data-target="notes_<?php echo $t['id']; ?>">
                                                <i class="bi bi-mic-fill text-danger"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold">Pending Parts / Items Needed? (Optional)</label>
                                        <textarea name="pending_parts" class="form-control form-control-sm" rows="1" placeholder="e.g. Need 2MP Camera, DC Pin..."></textarea>
                                        <small class="text-muted">If you fill this, the task will show as "Waiting for Parts".</small>
                                    </div>
                                    <input type="hidden" name="action_type" value="complete">
                                    <button type="submit" class="btn btn-success w-100 shadow-sm fw-bold"><i class="bi bi-check2-circle"></i> Submit Completion / Update Status</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="card p-5 text-center shadow-sm border-0 rounded-4">
                    <i class="bi bi-emoji-smile text-success fs-1 mb-3"></i>
                    <h5 class="fw-bold">All clear!</h5>
                    <p class="text-muted">No pending tasks for you right now.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <h5 class="fw-bold mb-3 text-success"><i class="bi bi-patch-check"></i> Recent Achievements</h5>
            <div class="card border-0 shadow-sm rounded-4 p-3">
                <?php if($completed_tasks->num_rows > 0): ?>
                    <div class="list-group list-group-flush">
                        <?php while($t = $completed_tasks->fetch_assoc()): ?>
                            <div class="list-group-item px-0 border-bottom">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($t['title']); ?></div>
                                    <i class="bi bi-check-circle-fill text-success"></i>
                                </div>
                                <div class="small text-muted mt-1">Done on <?php echo date("d M, h:i A", strtotime($t['updated_at'])); ?></div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted small text-center my-3">Finish your first task to see it here!</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Voice to Text (Gujarati)
    const voiceBtns = document.querySelectorAll('.voice-btn');
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    if (SpeechRecognition) {
        const recognition = new SpeechRecognition();
        recognition.lang = 'gu-IN'; // Gujarati
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;

        voiceBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const targetId = btn.getAttribute('data-target');
                const targetInput = document.getElementById(targetId);
                
                btn.innerHTML = '<i class="bi bi-record-circle-fill text-danger animate-pulse"></i>';
                recognition.start();

                recognition.onresult = (event) => {
                    const transcript = event.results[0][0].transcript;
                    targetInput.value += (targetInput.value ? ' ' : '') + transcript;
                    btn.innerHTML = '<i class="bi bi-mic-fill text-danger"></i>';
                };

                recognition.onspeechend = () => {
                    recognition.stop();
                    btn.innerHTML = '<i class="bi bi-mic-fill text-danger"></i>';
                };

                recognition.onerror = () => {
                    btn.innerHTML = '<i class="bi bi-mic-fill text-danger"></i>';
                };
            });
        });
    } else {
        voiceBtns.forEach(btn => btn.style.display = 'none');
    }
</script>
<?php require_once __DIR__ . '/includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
