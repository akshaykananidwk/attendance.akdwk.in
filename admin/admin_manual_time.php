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
$company_id = (int)get_company_id();
$branch_id = (int)get_branch_id();

// Month & Year Filter
$current_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$current_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// --- டிલીட் કરવાનું લોજીક (DELETE ENTRY) ---
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    
    $stmt = $conn->prepare("DELETE a FROM attendance_logs a JOIN users u ON a.user_id = u.id WHERE a.id = ? AND u.company_id = ?");
    $stmt->bind_param("ii", $delete_id, $company_id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            write_audit_log($conn, 'attendance.delete', "attendance_log_id={$delete_id}", 'attendance_logs', (string) $delete_id);
        }
        $stmt->close();
        echo "<script>
                alert('હાજરીનો રેકોર્ડ સફળતાપૂર્વક ડીલીટ થઈ ગયો છે!');
                window.location.href = 'admin_manual_time.php';
              </script>";
        exit();
    }
    $stmt->close();
}

// જો એડમિન મેન્યુઅલ એન્ટ્રી ફોર્મ સબમિટ કરે (INSERT / UPDATE)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['manual_entry'])) {
    // CSRF Verification
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        die("CSRF Token validation failed.");
    }
    
    $user_id = $_POST['user_id'];
    $log_date = $_POST['log_date'];
    
    // નવી DATETIME સિસ્ટમ મુજબ તારીખ અને સમય ભેગા કરો (Combine Date & Time)
    $final_in = (isset($_POST['punch_in']) && trim($_POST['punch_in']) !== '') ? ($log_date . ' ' . $_POST['punch_in'] . ':00') : null;
    $final_l_out = (isset($_POST['lunch_out']) && trim($_POST['lunch_out']) !== '') ? ($log_date . ' ' . $_POST['lunch_out'] . ':00') : null;
    $final_l_in = (isset($_POST['lunch_in']) && trim($_POST['lunch_in']) !== '') ? ($log_date . ' ' . $_POST['lunch_in'] . ':00') : null;
    $final_out = (isset($_POST['punch_out']) && trim($_POST['punch_out']) !== '') ? ($log_date . ' ' . $_POST['punch_out'] . ':00') : null;

    $stmt_check = $conn->prepare("SELECT a.* FROM attendance_logs a JOIN users u ON a.user_id = u.id WHERE a.user_id=? AND a.log_date=? AND u.company_id = ?");
    $stmt_check->bind_param("isi", $user_id, $log_date, $company_id);
    $stmt_check->execute();
    $check_query = $stmt_check->get_result();
    
    $total_minutes = 0;
    $lunch_sec = 0;

    // --- લંચની ગણતરી (જો Lunch OUT અને Lunch IN બંને હોય) ---
    if ($final_l_out && $final_l_in) {
        $l_out_time = strtotime($final_l_out ?? '');
        $l_in_time = strtotime($final_l_in ?? '');
        
        // જો લંચમાં રાતના 12 વાગી જાય (Cross midnight)
        if ($l_in_time < $l_out_time) { $l_in_time += 86400; } 
        
        $lunch_sec = $l_in_time - $l_out_time;
    }

    // --- કુલ કામની ગણતરી (જો IN અને OUT બંને હોય) ---
    if ($final_in && $final_out) {
        $w_in_time = strtotime($final_in ?? '');
        $w_out_time = strtotime($final_out ?? '');
        
        // નાઈટ શિફ્ટ લોજીક (જો OUT સમય IN કરતા નાનો હોય)
        if ($w_out_time < $w_in_time) { $w_out_time += 86400; }
        
        $work_sec = $w_out_time - $w_in_time;
        $total_minutes = round(($work_sec - $lunch_sec) / 60);
        if ($total_minutes < 0) $total_minutes = 0;
    }

    $existing = null;
    if ($check_query->num_rows > 0) {
        // ૧. જૂનો રેકોર્ડ UPDATE કરો
        $existing = $check_query->fetch_assoc();
        $stmt_upd = $conn->prepare("UPDATE attendance_logs SET punch_in=?, lunch_out=?, lunch_in=?, punch_out=?, total_worked_minutes=? WHERE id=?");
        $stmt_upd->bind_param("ssssii", $final_in, $final_l_out, $final_l_in, $final_out, $total_minutes, $existing['id']);
        $stmt_upd->execute();
        $stmt_upd->close();
        $action_label = "Updated (Admin)";
    } else {
        // ૨. નવો રેકોર્ડ (INSERT) કરો
        $stmt_ins = $conn->prepare("INSERT INTO attendance_logs (user_id, log_date, punch_in, lunch_out, lunch_in, punch_out, total_worked_minutes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt_ins->bind_param("isssssi", $user_id, $log_date, $final_in, $final_l_out, $final_l_in, $final_out, $total_minutes);
        $stmt_ins->execute();
        $stmt_ins->close();
        $action_label = "Added (Admin)";
    }
    $stmt_check->close();

    // Group Notification
    $staff_q = $conn->prepare("SELECT name FROM users WHERE id = ?");
    $staff_q->bind_param("i", $user_id);
    $staff_q->execute();
    $staff_name = $staff_q->get_result()->fetch_assoc()['name'] ?? 'Unknown';
    $staff_q->close();

    $date_fmt = date('d-M-Y', strtotime($log_date));
    $notif = "⚙️ *Manual Entry: $action_label*\n"
           . "• *Staff:* $staff_name\n"
           . "• *Date:* $date_fmt\n"
           . "• *Punch:* ".($final_in ? date('h:i A', strtotime($final_in)) : 'N/A')." - ".($final_out ? date('h:i A', strtotime($final_out)) : 'N/A');
    sendWhatsAppGroupNotification($notif);

    $log_entity_id = ($existing !== null && isset($existing['id'])) ? (string) $existing['id'] : "u{$user_id}:{$log_date}";
    write_audit_log($conn, 'attendance.manual_save', "{$action_label} staff={$staff_name} date={$log_date}", 'attendance_logs', $log_entity_id);

    echo "<script>
            alert('સ્ટાફની હાજરી, લંચ અને કામની મિનિટો 100% સચોટ રીતે સેવ થઈ ગઈ છે!');
            window.location.href = 'admin_manual_time.php';
          </script>";
    exit();
}

// Fetch Staff for Dropdown
$staff_dropdown = $conn->query("SELECT id, name FROM users WHERE role='staff' AND company_id = $company_id" . staff_sql_active_filter('') . ' ORDER BY name ASC');

// Fetch Recent Logs
$recent_logs = $conn->query("SELECT u.name, a.* FROM attendance_logs a 
                            JOIN users u ON a.user_id = u.id 
                            WHERE u.company_id = $company_id 
                            AND MONTH(a.log_date) = $current_month 
                            AND YEAR(a.log_date) = $current_year 
                            ORDER BY a.log_date DESC, a.id DESC LIMIT 200");

$csrf_token = generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Entry | <?php echo htmlspecialchars(app_brand()); ?></title>
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
            
            <div class="dashboard-header d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <button class="mobile-toggle me-2" onclick="toggleSidebar()"><i class="bi bi-list fs-4"></i></button>
                    <span class="fs-4 fw-bold text-dark">Manual Attendance Log</span>
                </div>
                <form method="GET" class="d-flex gap-2">
                    <select name="month" class="form-select form-select-sm" style="width: 120px;">
                        <?php for($m=1; $m<=12; $m++): ?>
                            <option value="<?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>" <?php echo ($current_month == str_pad($m, 2, '0', STR_PAD_LEFT)) ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <select name="year" class="form-select form-select-sm" style="width: 100px;">
                        <?php for($y=date('Y'); $y>=2024; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo ($current_year == $y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-dark">Filter</button>
                </form>
                <button class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#attendanceModal" onclick="clearForm()">
                    <i class="bi bi-plus-circle-fill"></i> Add New Record
                </button>
            </div>

            <div class="card p-4">
                <h5 class="fw-bold mb-3"><i class="bi bi-table text-primary"></i> Recent Attendance Records</h5>
                <div class="table-responsive">
                    <table class="ui-cards-sm table table-hover table-bordered text-center align-middle border">
                        <thead class="table-dark">
                            <tr>
                                <th>Date</th>
                                <th>Staff Name</th>
                                <th>In</th>
                                <th>Lunch Out</th>
                                <th>Lunch In</th>
                                <th>Out</th>
                                <th class="text-nowrap">Total work</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_logs && $recent_logs->num_rows > 0): ?>
                                <?php while($row = $recent_logs->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?php echo date("d-m-Y", strtotime($row['log_date'])); ?></strong></td>
                                    <td class="fw-bold text-start ps-3"><i class="bi bi-person-circle text-secondary"></i> <?php echo $row['name']; ?></td>
                                    <td class="text-success fw-bold"><?php echo $row['punch_in'] ? date("h:i A", strtotime($row['punch_in'])) : '-'; ?></td>
                                    <td class="text-warning fw-bold"><?php echo $row['lunch_out'] ? date("h:i A", strtotime($row['lunch_out'])) : '-'; ?></td>
                                    <td class="text-info fw-bold"><?php echo $row['lunch_in'] ? date("h:i A", strtotime($row['lunch_in'])) : '-'; ?></td>
                                    <td class="text-danger fw-bold"><?php echo $row['punch_out'] ? date("h:i A", strtotime($row['punch_out'])) : '-'; ?></td>
                                    <td class="small">
                                        <?php
                                        $calc_mins = attendance_worked_minutes_from_row($row);
                                        $stored_mins = isset($row['total_worked_minutes']) ? (int) $row['total_worked_minutes'] : 0;
                                        if (empty($row['punch_in'])) {
                                            echo '<span class="text-muted">—</span>';
                                        } elseif (empty($row['punch_out'])) {
                                            echo '<span class="badge bg-warning text-dark">No OUT</span>';
                                            echo '<div class="text-muted mt-1" style="font-size:0.75rem;">Punch OUT દાખલ કરો ત્યારે કુલ કલાક ગણાશે</div>';
                                        } else {
                                            echo '<span class="fw-bold text-primary">' . htmlspecialchars(format_attendance_duration($calc_mins)) . '</span>';
                                            echo '<div class="text-muted" style="font-size:0.75rem;">' . round($calc_mins / 60, 2) . ' decimal hrs</div>';
                                            if ($stored_mins !== $calc_mins) {
                                                echo '<div class="text-danger mt-1" style="font-size:0.75rem;" title="DB value does not match punches — Edit &amp; Save to sync">⚠ DB: ' . htmlspecialchars(format_attendance_duration($stored_mins)) . '</div>';
                                            }
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                            // Extract Time (HH:MM) from DATETIME for Javascript Modal
                                            $js_in = $row['punch_in'] ? date("H:i", strtotime($row['punch_in'])) : '';
                                            $js_l_out = $row['lunch_out'] ? date("H:i", strtotime($row['lunch_out'])) : '';
                                            $js_l_in = $row['lunch_in'] ? date("H:i", strtotime($row['lunch_in'])) : '';
                                            $js_out = $row['punch_out'] ? date("H:i", strtotime($row['punch_out'])) : '';
                                        ?>
                                        <button class="btn btn-sm btn-primary shadow-sm fw-bold me-1" 
                                            onclick="openEditModal('<?php echo $row['user_id']; ?>', '<?php echo $row['log_date']; ?>', '<?php echo $js_in; ?>', '<?php echo $js_l_out; ?>', '<?php echo $js_l_in; ?>', '<?php echo $js_out; ?>')">
                                            <i class="bi bi-pencil-square"></i> Edit
                                        </button>
                                        <a href="?delete_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-danger shadow-sm fw-bold" onclick="return confirm('Are you sure you want to delete this record?');">
                                            <i class="bi bi-trash"></i> Delete
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="text-muted py-4">No records found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="attendanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content card border-0">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold" id="modalTitle"><i class="bi bi-clock"></i> Attendance Entry</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Select Staff Member</label>
                            <select name="user_id" id="m_user_id" class="form-select bg-light" required>
                                <option value="">-- Choose Staff --</option>
                                <?php 
                                $staff_dropdown->data_seek(0);
                                while($st = $staff_dropdown->fetch_assoc()) echo "<option value='{$st['id']}'>{$st['name']}</option>"; 
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Select Date</label>
                            <input type="date" name="log_date" id="m_date" class="form-control bg-light" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="col-md-3 mt-4">
                            <label class="form-label text-success fw-bold">Punch IN</label>
                            <input type="time" name="punch_in" id="m_in" class="form-control bg-light border-success">
                        </div>
                        <div class="col-md-3 mt-4">
                            <label class="form-label text-warning fw-bold">Lunch OUT</label>
                            <input type="time" name="lunch_out" id="m_lunch_out" class="form-control bg-light border-warning">
                        </div>
                        <div class="col-md-3 mt-4">
                            <label class="form-label text-info fw-bold">Lunch IN</label>
                            <input type="time" name="lunch_in" id="m_lunch_in" class="form-control bg-light border-info">
                        </div>
                        <div class="col-md-3 mt-4">
                            <label class="form-label text-danger fw-bold">Punch OUT</label>
                            <input type="time" name="punch_out" id="m_out" class="form-control bg-light border-danger">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="manual_entry" class="btn btn-primary px-4 fw-bold"><i class="bi bi-save2-fill"></i> Save Attendance</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function openEditModal(uid, date, pin, lout, lin, pout) {
        document.getElementById('m_user_id').value = uid;
        document.getElementById('m_date').value = date;
        // હવે ડાયરેક્ટ વેલ્યૂ સેટ થશે કારણ કે PHP માંથી જ HH:MM ફોર્મેટ આવે છે 
        document.getElementById('m_in').value = pin;
        document.getElementById('m_lunch_out').value = lout;
        document.getElementById('m_lunch_in').value = lin;
        document.getElementById('m_out').value = pout;
        document.getElementById('modalTitle').innerHTML = "<i class='bi bi-pencil-square'></i> Edit Staff Attendance";
        new bootstrap.Modal(document.getElementById('attendanceModal')).show();
    }

    function clearForm() {
        document.getElementById('modalTitle').innerHTML = "<i class='bi bi-plus-circle'></i> Add New Attendance";
        document.getElementById('m_user_id').value = '';
        document.getElementById('m_in').value = '';
        document.getElementById('m_lunch_out').value = '';
        document.getElementById('m_lunch_in').value = '';
        document.getElementById('m_out').value = '';
    }
</script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>