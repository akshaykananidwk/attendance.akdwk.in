<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
deny_auditor_to_reports();
if (!in_array($_SESSION['role'], ['admin', 'manager', 'hr'], true)) {
    header("Location: ../login.php");
    exit();
}
$message = "";

// સેટિંગ્સ અપડેટ કરવાનું લોજીક
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_settings'])) {
    // CSRF Verification
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        die("CSRF Token validation failed.");
    }
    
    $monthly_office_rent = $_POST['monthly_office_rent'] ?? '0.00';
    $group_id = $_POST['group_id'];
    $api_key = $_POST['api_key'];
    $session_id = $_POST['session_id'];
    $webhook_url = $_POST['webhook_url'];
    $admin_mobile = $_POST['admin_mobile']; 
    
    $msg_in = $_POST['msg_in'];
    $msg_out = $_POST['msg_out'];
    $msg_lunch_out = $_POST['msg_lunch_out'];
    $msg_lunch_in = $_POST['msg_lunch_in'];
    $msg_leave = $_POST['msg_leave'];
    $msg_client_task = $_POST['msg_client_task'];

    $check = $conn->query("SELECT id FROM settings LIMIT 1");
    if ($check->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE settings SET 
                        monthly_office_rent=?, group_id=?, api_key=?, session_id=?, webhook_url=?, admin_mobile=?,
                        msg_in=?, msg_out=?, msg_lunch_out=?, msg_lunch_in=?, msg_leave=?, msg_client_task=? WHERE id=1");
        $stmt->bind_param("ssssssssssss", $monthly_office_rent, $group_id, $api_key, $session_id, $webhook_url, $admin_mobile, $msg_in, $msg_out, $msg_lunch_out, $msg_lunch_in, $msg_leave, $msg_client_task);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO settings (monthly_office_rent, group_id, api_key, session_id, webhook_url, admin_mobile, msg_in, msg_out, msg_lunch_out, msg_lunch_in, msg_leave, msg_client_task) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssssssss", $monthly_office_rent, $group_id, $api_key, $session_id, $webhook_url, $admin_mobile, $msg_in, $msg_out, $msg_lunch_out, $msg_lunch_in, $msg_leave, $msg_client_task);
        $stmt->execute();
        $stmt->close();
    }
    
    $message = "<div class='alert alert-success alert-dismissible fade show fw-bold'>
                    <i class='bi bi-check-circle-fill'></i> Settings updated successfully!
                    <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                </div>";
}

// WhatsApp Test Message Logic
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['test_wa'])) {
    // CSRF Verification
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        die("CSRF Token validation failed.");
    }
    
    $test_res = sendWhatsAppGroupNotification("🧪 *Smart Attendance System*\nThis is a test message to verify your WhatsApp Group configuration. ✅");
    
    if ($test_res) {
        $message = "<div class='alert alert-info alert-dismissible fade show fw-bold'>
                        <i class='bi bi-whatsapp'></i> Test message sent! Response: $test_res
                        <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                    </div>";
    } else {
        $message = "<div class='alert alert-danger alert-dismissible fade show fw-bold'>
                        <i class='bi bi-x-circle-fill'></i> Failed to send test message. Check your API settings and logs.
                        <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                    </div>";
    }
}

// હાલના સેટિંગ્સ ડેટાબેઝમાંથી લાવો
$settings = [
    'monthly_office_rent' => '0.00',
    'group_id' => '', 'api_key' => '', 'session_id' => '', 'webhook_url' => '', 'admin_mobile' => '',
    'msg_in' => 'Hello {name}, your IN time ({time}) is recorded.',
    'msg_out' => 'Hello {name}, your OUT time ({time}) is recorded.',
    'msg_lunch_out' => 'Hello {name}, your LUNCH OUT time is {time}.',
    'msg_lunch_in' => 'Hello {name}, your LUNCH IN time is {time}. Welcome back!',
    'msg_leave' => 'Hello {name}, your LEAVE for today has been approved.',
    'msg_client_task' => 'Your work has been received. Your work will be completed within 24 hours.'
];
$set_result = $conn->query("SELECT * FROM settings LIMIT 1");
if ($set_result->num_rows > 0) {
    $settings = array_merge($settings, $set_result->fetch_assoc());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings | Smart Attendance</title>
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
                    <span class="fs-4 fw-bold text-dark"><i class="bi bi-gear text-primary"></i> System Configuration</span>
                </div>
                <div class="text-muted fw-bold hide-mobile">
                    <i class="bi bi-calendar3"></i> <?php echo date('d F, Y'); ?>
                </div>
            </div>

            <?php if($message != "") echo $message; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <div class="row">
                    <div class="col-lg-5 mb-4">
                        <div class="card h-100">
                            <div class="card-header text-primary">
                                <i class="bi bi-link-45deg"></i> API & Webhook Configuration
                            </div>
                            <div class="card-body p-4">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Monthly Office Rent (₹)</label>
                                    <input type="number" step="0.01" name="monthly_office_rent" class="form-control border-success bg-light fw-bold text-success fs-5" value="<?php echo htmlspecialchars($settings['monthly_office_rent']); ?>" placeholder="e.g. 12000">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">WhatsApp Group ID</label>
                                    <input type="text" name="group_id" class="form-control bg-light" value="<?php echo htmlspecialchars($settings['group_id']); ?>" placeholder="123456789@g.us" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">API Key (Access Token)</label>
                                    <input type="text" name="api_key" class="form-control bg-light" value="<?php echo htmlspecialchars($settings['api_key']); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Session ID (Instance ID)</label>
                                    <input type="text" name="session_id" class="form-control bg-light" value="<?php echo htmlspecialchars($settings['session_id']); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold text-danger"><i class="bi bi-bell-fill"></i> Admin Mobile for Notifications</label>
                                    <input type="text" name="admin_mobile" class="form-control bg-light border-danger" value="<?php echo htmlspecialchars($settings['admin_mobile']); ?>" placeholder="91XXXXXXXXXX" required>
                                    <small class="text-muted">You will receive a WhatsApp message when staff applies for leave.</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Webhook URL</label>
                                    <input type="text" name="webhook_url" class="form-control bg-light" value="<?php echo htmlspecialchars($settings['webhook_url']); ?>" readonly>
                                    <small class="text-muted">Copy this URL to your WhatsApp Gateway settings.</small>
                                </div>
                                <div class="mt-4 pt-3 border-top">
                                    <button type="submit" name="test_wa" class="btn btn-outline-success w-100 fw-bold">
                                        <i class="bi bi-whatsapp"></i> Test WhatsApp Group Message
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7 mb-4">
                        <div class="card h-100">
                            <div class="card-header text-success">
                                <i class="bi bi-chat-left-text-fill"></i> WhatsApp Message Templates
                            </div>
                            <div class="card-body p-4">
                                <div class="alert alert-info py-2 mb-4" role="alert">
                                    <i class="bi bi-info-circle-fill"></i> <strong>Variables:</strong> <br>
                                    - Attendance: <code>{name}</code>, <code>{time}</code> <br>
                                    - Client Task: <code>{unit_id}</code>, <code>{title}</code>, <code>{category}</code>, <code>{description}</code>, <code>{staff_details}</code>
                                </div>
                                
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-success">Office IN Message</label>
                                        <textarea name="msg_in" class="form-control bg-light" rows="3" required><?php echo htmlspecialchars($settings['msg_in']); ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-danger">Office OUT Message</label>
                                        <textarea name="msg_out" class="form-control bg-light" rows="3" required><?php echo htmlspecialchars($settings['msg_out']); ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-warning">Lunch OUT Message</label>
                                        <textarea name="msg_lunch_out" class="form-control bg-light" rows="3" required><?php echo htmlspecialchars($settings['msg_lunch_out']); ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-info">Lunch IN Message</label>
                                        <textarea name="msg_lunch_in" class="form-control bg-light" rows="3" required><?php echo htmlspecialchars($settings['msg_lunch_in']); ?></textarea>
                                    </div>
                                    <div class="col-md-12">
                                        <label class="form-label fw-bold text-secondary">Leave / Raja Message</label>
                                        <textarea name="msg_leave" class="form-control bg-light" rows="2" required><?php echo htmlspecialchars($settings['msg_leave']); ?></textarea>
                                    </div>
                                    <div class="col-md-12">
                                        <label class="form-label fw-bold text-primary">Client Task Received Message</label>
                                        <textarea name="msg_client_task" class="form-control bg-light" rows="2" required><?php echo htmlspecialchars($settings['msg_client_task']); ?></textarea>
                                        <small class="text-muted">This message will be sent to the client when a new task is assigned.</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-end mt-2">
                    <button type="submit" name="update_settings" class="btn btn-primary btn-lg fw-bold px-5 shadow-sm">
                        <i class="bi bi-save2-fill"></i> Save All Settings
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>