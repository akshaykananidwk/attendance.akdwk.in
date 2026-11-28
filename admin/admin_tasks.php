<?php
session_start();
require_once '../db.php';
check_access(['admin', 'manager', 'hr']);

$company_id = (int)get_company_id();
$branch_id = (int)get_branch_id();
$message = "";

// 1. ADD / EDIT TASK
if ($_SERVER["REQUEST_METHOD"] == "POST" && (isset($_POST['add_task']) || isset($_POST['edit_task']))) {
    if (!verify_csrf_token($_POST['csrf_token'])) die("CSRF validation failed.");
    
    $title = $_POST['title'];
    $desc = $_POST['description'];
    $priority = $_POST['priority'];
    $category = $_POST['category'] ?? 'General';
    $deadline = $_POST['deadline'];
    $created_by = $_SESSION['user_id'];
    $b_id = $_POST['branch_id'] ?? $branch_id;
    $assigned_staff = $_POST['assigned_staff'] ?? []; 
    $notify_client = isset($_POST['notify_client']);

    $client_name = $_POST['client_name'] ?? NULL;
    $client_phone = $_POST['client_phone'] ?? NULL;
    $client_company = $_POST['client_company'] ?? NULL;

    // Save client if not exists
    if ($client_phone) {
        $stmt_chk = $conn->prepare('SELECT id FROM clients WHERE phone = ? AND company_id = ?');
        $stmt_chk->bind_param('si', $client_phone, $company_id);
        $stmt_chk->execute();
        $check_client = $stmt_chk->get_result();
        if ($check_client->num_rows == 0) {
            $stmt_cl = $conn->prepare("INSERT INTO clients (company_id, name, phone, client_company) VALUES (?, ?, ?, ?)");
            $stmt_cl->bind_param("isss", $company_id, $client_name, $client_phone, $client_company);
            $stmt_cl->execute();
            $stmt_cl->close();
        }
        $stmt_chk->close();
    }

    if (isset($_POST['edit_task'])) {
        $task_id = $_POST['task_id'];
        $stmt = $conn->prepare("UPDATE tasks SET title=?, description=?, priority=?, category=?, deadline=?, branch_id=?, client_name=?, client_phone=?, client_company=? WHERE id=? AND company_id=?");
        $stmt->bind_param("sssssisssii", $title, $desc, $priority, $category, $deadline, $b_id, $client_name, $client_phone, $client_company, $task_id, $company_id);
        $stmt->execute();
        $stmt->close();
        
        $conn->query("DELETE FROM task_assignments WHERE task_id = $task_id");
        foreach ($assigned_staff as $uid) {
            $stmt = $conn->prepare("INSERT INTO task_assignments (task_id, user_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $task_id, $uid);
            $stmt->execute();
            $stmt->close();
        }
        $message = "<div class='alert alert-success fw-bold'>Task updated successfully!</div>";
    } else {
        $stmt = $conn->prepare("INSERT INTO tasks (company_id, branch_id, title, description, priority, category, client_name, client_phone, client_company, created_by, deadline) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iisssssssis", $company_id, $b_id, $title, $desc, $priority, $category, $client_name, $client_phone, $client_company, $created_by, $deadline);
        if ($stmt->execute()) {
            $task_id = $conn->insert_id;
            
            // Generate Unit ID
            $unit_id = 'akdwk-' . str_pad($task_id, 5, '0', STR_PAD_LEFT);
            $conn->query("UPDATE tasks SET task_unit_id = '$unit_id' WHERE id = $task_id");

            foreach ($assigned_staff as $uid) {
                $stmt_ass = $conn->prepare("INSERT INTO task_assignments (task_id, user_id) VALUES (?, ?)");
                $stmt_ass->bind_param("ii", $task_id, $uid);
                $stmt_ass->execute();
                $stmt_ass->close();
                
                // Notify Staff via WhatsApp
                $staff_res = $conn->query("SELECT phone_number, name FROM users WHERE id = $uid");
                $staff_data = $staff_res->fetch_assoc();
                if ($staff_data && !empty($staff_data['phone_number'])) {
                    $staff_msg = "🆕 *New Task Assigned* 🆕\n\n"
                               . "🆔 *ID:* $unit_id\n"
                               . "📌 *Work:* $title\n"
                               . "🏷️ *Category:* $category\n\n"
                               . "👤 *Client:* $client_name ($client_phone)\n"
                               . "🏢 *Company:* $client_company\n\n"
                               . "📝 *Instructions:* $desc\n\n"
                               . "✅ Please check your dashboard for details.";
                    sendWhatsAppDirectMessage($staff_data['phone_number'], $staff_msg);
                }
            }
            $message = "<div class='alert alert-success fw-bold'>Task assigned successfully!</div>";
            
            // Send WhatsApp to Client
            if ($notify_client && $client_phone) {
                $sett_res = $conn->query("SELECT msg_client_task FROM settings LIMIT 1");
                $sett = $sett_res->fetch_assoc();
                $client_msg_template = $sett['msg_client_task'] ?? "Your work has been received. {unit_id}";
                
                // Fetch staff details for the message
                $staff_info_list = [];
                if (!empty($assigned_staff)) {
                    $uids = implode(',', array_map('intval', $assigned_staff));
                    $staff_res = $conn->query("SELECT name, phone_number FROM users WHERE id IN ($uids)");
                    while ($s_row = $staff_res->fetch_assoc()) {
                        $staff_info_list[] = "• " . $s_row['name'] . " (" . $s_row['phone_number'] . ")";
                    }
                }
                $staff_details_str = implode("\n", $staff_info_list);

                // Format the message
                $search_tags = ['{unit_id}', '{title}', '{category}', '{description}', '{staff_details}'];
                $replace_tags = [$unit_id, $title, $category, $desc, $staff_details_str];
                
                // If template is default or doesn't have tags, use a rich format
                if (strpos($client_msg_template, '{unit_id}') === false) {
                    $client_msg = "✨ *Service Request Received* ✨\n\n"
                                . "🆔 *Task ID:* $unit_id\n\n"
                                . "📌 *Work:* $title\n\n"
                                . "🏷️ *Category:* $category\n\n"
                                . "📝 *Instructions:* $desc\n\n"
                                . "──────────────────\n"
                                . "👷 *Assigned Team:*\n$staff_details_str\n"
                                . "──────────────────\n\n"
                                . "✅ Our team will contact you shortly.\n"
                                . "🙏 Thank you for choosing us!";
                } else {
                    $client_msg = str_replace($search_tags, $replace_tags, $client_msg_template);
                }
                
                sendWhatsAppDirectMessage($client_phone, $client_msg);
            }
        }
        $stmt->close();
    }
}

// 2. DELETE TASK
if (isset($_GET['delete_id'])) {
    $id = (int)$_GET['delete_id'];
    $conn->query("DELETE FROM tasks WHERE id=$id AND company_id=$company_id");
    $conn->query("DELETE FROM task_assignments WHERE task_id=$id");
    header("Location: admin_tasks.php?msg=deleted");
    exit();
}

// 3. COMPLETE TASK
if (isset($_GET['complete_id'])) {
    $id = (int)$_GET['complete_id'];
    $conn->query("UPDATE tasks SET status='Completed' WHERE id=$id AND company_id=$company_id");
    header("Location: admin_tasks.php");
    exit();
}

$staff_list = $conn->query("SELECT id, name FROM users WHERE company_id = $company_id AND role='staff'" . staff_sql_active_filter('') . ' ORDER BY name ASC');
$branches_list = $conn->query("SELECT id, name FROM branches WHERE company_id = $company_id");

// 4. Filtering Logic
$f_staff = $_GET['f_staff'] ?? '';
$f_cat = $_GET['f_cat'] ?? '';
$f_status = $_GET['f_status'] ?? '';

$where = "t.company_id = $company_id";
if ($f_staff) {
    $where .= " AND t.id IN (SELECT task_id FROM task_assignments WHERE user_id = " . (int)$f_staff . ")";
}
if ($f_cat) {
    $where .= " AND t.category = '" . $conn->real_escape_string($f_cat) . "'";
}
if ($f_status) {
    $where .= " AND t.status = '" . $conn->real_escape_string($f_status) . "'";
}

$tasks = $conn->query("SELECT t.*, b.name as branch_name, 
                       (SELECT GROUP_CONCAT(u.name SEPARATOR ', ') FROM task_assignments ta JOIN users u ON ta.user_id = u.id WHERE ta.task_id = t.id) as staff_names 
                       FROM tasks t 
                       JOIN branches b ON t.branch_id = b.id
                       WHERE $where ORDER BY t.id DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Tasks | <?php echo htmlspecialchars(app_brand()); ?></title>
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
            <span class="fs-4 fw-bold text-dark"><i class="bi bi-list-task text-primary"></i> Task Management</span>
        </div>
        <button class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addTaskModal">
            <i class="bi bi-plus-circle-fill"></i> Assign New Task
        </button>
    </div>

    <!-- Filters -->
    <div class="card p-3 mb-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-bold">Filter by Staff</label>
                <select name="f_staff" class="form-select form-select-sm">
                    <option value="">All Staff</option>
                    <?php 
                    $staff_list->data_seek(0);
                    while($s = $staff_list->fetch_assoc()): ?>
                        <option value="<?php echo $s['id']; ?>" <?php echo ($f_staff == $s['id']) ? 'selected' : ''; ?>><?php echo $s['name']; ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Work Category</label>
                <select name="f_cat" class="form-select form-select-sm">
                    <option value="">All Categories</option>
                    <option value="Camera Work" <?php echo ($f_cat == 'Camera Work') ? 'selected' : ''; ?>>Camera Work</option>
                    <option value="Computer Work" <?php echo ($f_cat == 'Computer Work') ? 'selected' : ''; ?>>Computer Work</option>
                    <option value="WiFi Internet" <?php echo ($f_cat == 'WiFi Internet') ? 'selected' : ''; ?>>WiFi Internet</option>
                    <option value="Other" <?php echo ($f_cat == 'Other') ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Status</label>
                <select name="f_status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="Pending" <?php echo ($f_status == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                    <option value="In Progress" <?php echo ($f_status == 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                    <option value="Completed" <?php echo ($f_status == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-sm btn-dark w-100 fw-bold">Apply Filters</button>
            </div>
        </form>
    </div>

    <?php if($message != "") echo $message; ?>

    <div class="row">
        <?php while($t = $tasks->fetch_assoc()): ?>
        <div class="col-md-4 mb-4">
            <div class="card p-3 h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="badge badge-priority-<?php echo $t['priority']; ?>"><?php echo $t['priority']; ?></span>
                    <span class="badge bg-info text-dark fw-bold"><?php echo $t['task_unit_id']; ?></span>
                    <span class="badge bg-dark"><?php echo htmlspecialchars($t['category']); ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="badge <?php 
                        if($t['status'] == 'Completed') echo 'bg-success';
                        elseif($t['status'] == 'Waiting for Parts') echo 'bg-danger animate-pulse';
                        else echo 'bg-secondary'; 
                    ?>"><?php echo $t['status']; ?></span>
                </div>
                <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($t['title']); ?></h5>
                <?php if($t['pending_parts']): ?>
                    <div class="alert alert-danger py-1 px-2 small mb-2 fw-bold">
                        <i class="bi bi-tools"></i> Pending Parts: <?php echo htmlspecialchars($t['pending_parts']); ?>
                    </div>
                <?php endif; ?>
                <?php if($t['client_name']): ?>
                    <div class="small text-primary fw-bold mb-1"><i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($t['client_name']); ?> (<?php echo htmlspecialchars($t['client_phone']); ?>)</div>
                    <div class="small text-muted mb-2"><i class="bi bi-building"></i> <?php echo htmlspecialchars($t['client_company']); ?></div>
                <?php endif; ?>

                <?php if($t['completion_notes']): ?>
                    <div class="bg-success bg-opacity-10 p-2 rounded small mb-2 border-start border-3 border-success">
                        <div class="fw-bold text-success small"><i class="bi bi-chat-left-text"></i> Staff Notes:</div>
                        <div class="text-dark"><?php echo htmlspecialchars($t['completion_notes']); ?></div>
                    </div>
                <?php endif; ?>
                <p class="text-dark small mb-3"><?php echo nl2br(htmlspecialchars($t['description'])); ?></p>
                <div class="mt-auto pt-3 border-top">
                    <div class="small mb-1 text-muted">Assigned: <strong><?php echo $t['staff_names']; ?></strong></div>
                    <div class="small mb-2 text-muted">Deadline: <span class="text-danger fw-bold"><?php echo date("d M Y", strtotime($t['deadline'])); ?></span></div>
                    
                    <?php if($t['photo_before'] || $t['photo_after']): ?>
                    <div class="d-flex gap-2 mb-3">
                        <?php if($t['photo_before']): ?>
                            <div class="text-center">
                                <small class="d-block text-muted">Before</small>
                                <img src="../uploads/tasks/<?php echo $t['photo_before']; ?>" class="img-thumbnail" style="width: 60px; height: 60px; object-fit: cover; cursor: pointer;" onclick="viewImage('../uploads/tasks/<?php echo $t['photo_before']; ?>')">
                            </div>
                        <?php endif; ?>
                        <?php if($t['photo_after']): ?>
                            <div class="text-center">
                                <small class="d-block text-muted">After</small>
                                <img src="../uploads/tasks/<?php echo $t['photo_after']; ?>" class="img-thumbnail" style="width: 60px; height: 60px; object-fit: cover; cursor: pointer;" onclick="viewImage('../uploads/tasks/<?php echo $t['photo_after']; ?>')">
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex gap-2">
                        <?php if($t['status'] != 'Completed'): ?>
                            <a href="?complete_id=<?php echo $t['id']; ?>" class="btn btn-sm btn-success flex-grow-1">Complete</a>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline-primary" onclick='editTask(<?php echo json_encode($t); ?>)'><i class="bi bi-pencil"></i></button>
                        <a href="?delete_id=<?php echo $t['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this task?')"><i class="bi bi-trash"></i></a>
                    </div>
                </div>
            </div>
        </div>
        <?php endwhile; ?>
    </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addTaskModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalTitleTask">Assign New Task</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="task_id" id="edit_task_id">
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Task Title</label>
                        <div class="input-group">
                            <input type="text" name="title" id="edit_title" class="form-control" placeholder="What needs to be done?" required>
                            <button type="button" class="btn btn-outline-secondary voice-btn" data-target="edit_title" title="Speak Gujarati">
                                <i class="bi bi-mic-fill text-danger"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Work Category</label>
                        <select name="category" id="edit_category" class="form-select">
                            <option value="Camera Work">Camera Work</option>
                            <option value="Computer Work">Computer Work</option>
                            <option value="WiFi Internet">WiFi Internet</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Description / Instructions</label>
                    <div class="input-group">
                        <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
                        <button type="button" class="btn btn-outline-secondary voice-btn" data-target="edit_description" title="Speak Gujarati">
                            <i class="bi bi-mic-fill text-danger"></i>
                        </button>
                    </div>
                </div>

                <div class="card bg-light p-3 mb-3 border-0">
                    <h6 class="fw-bold text-primary mb-3 border-bottom pb-2">Client Information</h6>
                    <div class="row">
                        <div class="col-md-4 mb-2">
                            <label class="form-label small fw-bold">Client Mobile</label>
                            <input type="text" name="client_phone" id="edit_client_phone" class="form-control form-control-sm" placeholder="Search by number..." onkeyup="searchClient(this.value)">
                            <div id="client_suggestions" class="list-group position-absolute shadow-sm w-auto" style="z-index: 1001; max-height: 200px; overflow-y: auto;"></div>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="form-label small fw-bold">Client Name</label>
                            <input type="text" name="client_name" id="edit_client_name" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="form-label small fw-bold">Client Company</label>
                            <input type="text" name="client_company" id="edit_client_company" class="form-control form-control-sm">
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Priority</label>
                        <select name="priority" id="edit_priority" class="form-select">
                            <option value="Low">Low</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="High">High</option>
                        </select>
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Deadline</label>
                        <input type="date" name="deadline" id="edit_deadline" class="form-control" required>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Assign Staff (Multiple)</label>
                        <div class="border p-2 rounded bg-white" style="max-height: 120px; overflow-y: auto;">
                            <?php 
                            $staff_list->data_seek(0);
                            while($s = $staff_list->fetch_assoc()): ?>
                                <div class="form-check">
                                    <input class="form-check-input staff-checkbox" type="checkbox" name="assigned_staff[]" value="<?php echo $s['id']; ?>" id="staff_<?php echo $s['id']; ?>">
                                    <label class="form-check-label" for="staff_<?php echo $s['id']; ?>"><?php echo $s['name']; ?></label>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Branch</label>
                        <select name="branch_id" id="edit_branch_id" class="form-select" required>
                            <?php 
                            $branches_list->data_seek(0);
                            while($b = $branches_list->fetch_assoc()): ?>
                                <option value="<?php echo $b['id']; ?>"><?php echo $b['name']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                
                <div class="mb-3 form-check">
                    <input type="checkbox" name="notify_client" class="form-check-input" id="notifyClient" checked>
                    <label class="form-check-label fw-bold text-success" for="notifyClient">
                        <i class="bi bi-whatsapp"></i> Notify Client via WhatsApp
                    </label>
                </div>

                <button type="submit" name="add_task" id="submit_btn" class="btn btn-primary w-100 fw-bold py-2 mt-2">Create & Assign Task</button>
            </div>
        </form>
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

    // Client Auto-suggestion
    async function searchClient(query) {
        if (query.length < 3) {
            document.getElementById('client_suggestions').innerHTML = '';
            return;
        }
        const res = await fetch(`get_clients.php?q=${query}`);
        const clients = await res.json();
        
        let html = '';
        clients.forEach(c => {
            html += `<button type="button" class="list-group-item list-group-item-action" onclick='selectClient(${JSON.stringify(c)})'>
                        ${c.name} (${c.phone}) - ${c.client_company}
                    </button>`;
        });
        document.getElementById('client_suggestions').innerHTML = html;
    }

    function selectClient(c) {
        document.getElementById('edit_client_phone').value = c.phone;
        document.getElementById('edit_client_name').value = c.name;
        document.getElementById('edit_client_company').value = c.client_company;
        document.getElementById('client_suggestions').innerHTML = '';
    }

    function editTask(task) {
        document.getElementById('modalTitleTask').innerText = "Edit Task Details";
        document.getElementById('edit_task_id').value = task.id;
        document.getElementById('edit_title').value = task.title;
        document.getElementById('edit_description').value = task.description;
        document.getElementById('edit_priority').value = task.priority;
        document.getElementById('edit_category').value = task.category;
        document.getElementById('edit_deadline').value = task.deadline;
        document.getElementById('edit_branch_id').value = task.branch_id;
        document.getElementById('edit_client_name').value = task.client_name || '';
        document.getElementById('edit_client_phone').value = task.client_phone || '';
        document.getElementById('edit_client_company').value = task.client_company || '';
        document.getElementById('submit_btn').name = 'edit_task';
        document.getElementById('submit_btn').innerText = 'Update Task';
        
        document.querySelectorAll('.staff-checkbox').forEach(cb => cb.checked = false);
        
        let modal = new bootstrap.Modal(document.getElementById('addTaskModal'));
        modal.show();
    }

    document.querySelector('[data-bs-target="#addTaskModal"]').addEventListener('click', function() {
        document.getElementById('modalTitleTask').innerText = "Assign New Task";
        document.getElementById('edit_task_id').value = "";
        document.getElementById('edit_title').value = "";
        document.getElementById('edit_description').value = "";
        document.getElementById('edit_priority').value = "Medium";
        document.getElementById('edit_category').value = "Camera Work";
        document.getElementById('edit_client_name').value = "";
        document.getElementById('edit_client_phone').value = "";
        document.getElementById('edit_client_company').value = "";
        document.getElementById('submit_btn').name = 'add_task';
        document.getElementById('submit_btn').innerText = 'Create & Assign Task';
    });

    function viewImage(url) { window.open(url, '_blank'); }
</script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
