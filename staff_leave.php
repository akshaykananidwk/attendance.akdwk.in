<?php
session_start();
require_once 'db.php';
require_once __DIR__ . '/includes/staff_portal_guard.php';

$user_id = (int) $_SESSION['user_id'];
$user_name = $_SESSION['name'];
$message = '';
$advance_days = leave_advance_days($conn);
$min_leave_date = leave_earliest_selectable_date($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_leave'])) {
    staff_require_can_mutate();
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        die('CSRF Token validation failed.');
    }

    $leave_type = $_POST['leave_type'] ?? 'full';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = ($leave_type === 'half') ? $start_date : ($_POST['end_date'] ?? $start_date);
    $leave_reason = trim($_POST['leave_reason'] ?? '');
    $emergency = !empty($_POST['emergency_leave']);

    $dateError = leave_validate_request_dates($conn, $start_date, $end_date);
    if ($dateError !== null) {
        $message = "<div class='alert alert-danger fw-bold'>" . htmlspecialchars($dateError) . '</div>';
    } else {
        $tenant = attendance_user_tenant($conn, $user_id);
        $company_id = $tenant['company_id'];
        $branch_id = $tenant['branch_id'];

        $medical_path = null;
        if ($emergency && !empty($_FILES['medical_cert']['name']) && $_FILES['medical_cert']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
            $ext = strtolower(pathinfo($_FILES['medical_cert']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed, true) && $_FILES['medical_cert']['size'] <= 5 * 1024 * 1024) {
                $dir = __DIR__ . '/uploads/leaves';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $medical_path = 'uploads/leaves/' . $user_id . '_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['medical_cert']['tmp_name'], __DIR__ . '/' . $medical_path);
            }
        }

        $applied_at = date('Y-m-d H:i:s');
        $late_application = 0;
        $missing_medical = ($emergency && $medical_path === null) ? 1 : 0;
        $policy_violation = 0;

        $final_reason = ($leave_type === 'half') ? '[Half Day] ' . $leave_reason : $leave_reason;

        $begin = new DateTime($start_date);
        $end = new DateTime($end_date);
        $end->modify('+1 day');
        $daterange = new DatePeriod($begin, new DateInterval('P1D'), $end);

        $inserted_count = iterator_count($daterange);

        if (salary_table_exists($conn, 'leave_requests') && $inserted_count > 0) {
            $stmt_lr = $conn->prepare(
                'INSERT INTO leave_requests (user_id, company_id, leave_type, start_date, end_date, reason, medical_cert_path, applied_at, late_application, policy_violation, missing_medical_cert, double_deduction_days, emergency_leave, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, "pending")'
            );
            $double_total = $missing_medical ? (($leave_type === 'half') ? 1.0 : (float) $inserted_count * 2.0) : 0.0;
            $em = $emergency ? 1 : 0;
            $stmt_lr->bind_param(
                'iissssssidi',
                $user_id,
                $company_id,
                $leave_type,
                $start_date,
                $end_date,
                $leave_reason,
                $medical_path,
                $applied_at,
                $missing_medical,
                $double_total,
                $em
            );
            @$stmt_lr->execute();
            $stmt_lr->close();
        }

        if ($inserted_count > 0) {
            $date_str = ($start_date === $end_date)
                ? date('d-M-Y', strtotime($start_date))
                : date('d-M-Y', strtotime($start_date)) . ' – ' . date('d-M-Y', strtotime($end_date));
            sendWhatsAppGroupNotification("🌴 *Leave Request* • $user_name • $date_str • $leave_reason");
            $message = "<div class='alert alert-success fw-bold'><i class='bi bi-check-circle-fill'></i> Leave request submitted ($inserted_count day(s)). Pending admin approval.</div>";
        }
    }
}

$csrf_token = generate_csrf_token();
$min_leave_display = date('d M Y', strtotime($min_leave_date));

// Fetch leave history
$history = [];
if (salary_table_exists($conn, 'leave_requests')) {
    $stmt_h = $conn->prepare("SELECT * FROM leave_requests WHERE user_id = ? ORDER BY id DESC");
    $stmt_h->bind_param("i", $user_id);
    $stmt_h->execute();
    $res_h = $stmt_h->get_result();
    while ($r = $res_h->fetch_assoc()) {
        $history[] = $r;
    }
    $stmt_h->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Leave | <?php echo htmlspecialchars(app_brand()); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/saas_theme.css?v=<?php echo app_asset_version(); ?>">
    <?php echo app_pwa_head(); ?>
</head>
<body>
<div class="saas-sidebar-backdrop" id="saasSidebarBackdrop" aria-hidden="true"></div>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php require_once __DIR__ . '/includes/saas_sidebar_staff.php'; ?>
        <div class="col-md-10 main-content">
            <?php if (!empty($_SESSION['impersonator_id'])): ?>
            <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center mb-3">
                <span><i class="bi bi-person-badge"></i> Admin view</span>
                <a href="admin/staff_access.php?stop=1" class="btn btn-sm btn-dark fw-bold">Return to admin</a>
            </div>
            <?php endif; ?>
            <div class="d-flex align-items-center gap-2 mb-4">
                <button type="button" class="mobile-toggle" onclick="toggleSidebar()" aria-label="Menu"><i class="bi bi-list fs-4"></i></button>
                <h3 class="fw-bold mb-0">Apply for Leave</h3>
            </div>
            <?php echo $message; ?>
            <?php if (staff_is_readonly_session()): ?>
            <div class="alert alert-secondary">Archive mode: you can view past leave on your dashboard report. New leave cannot be submitted.</div>
            <?php else: ?>
            <div class="alert alert-info small">
                <strong>Policy:</strong> You can only select leave from <strong><?php echo htmlspecialchars($min_leave_display); ?></strong> onward
                (<?php echo (int) $advance_days; ?> days after today). Earlier dates are blocked in the form.
            </div>
            <div class="card p-4 bg-white leave-request-card">
                <form method="POST" action="" enctype="multipart/form-data" id="leaveForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Leave Type</label>
                        <select name="leave_type" id="leave_type" class="form-select" onchange="calculateDays()">
                            <option value="full">Full Day</option>
                            <option value="half">Half Day</option>
                        </select>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Start Date</label>
                            <input type="date" name="start_date" id="start_date" class="form-control"
                                   value="<?php echo htmlspecialchars($min_leave_date); ?>"
                                   min="<?php echo htmlspecialchars($min_leave_date); ?>" required onchange="calculateDays()">
                        </div>
                        <div class="col-md-6" id="end_date_wrapper">
                            <label class="form-label fw-bold">End Date</label>
                            <input type="date" name="end_date" id="end_date" class="form-control"
                                   value="<?php echo htmlspecialchars($min_leave_date); ?>"
                                   min="<?php echo htmlspecialchars($min_leave_date); ?>" required onchange="calculateDays()">
                        </div>
                    </div>
                    <div class="mb-3">
                        <span class="text-muted fw-bold">Total:</span>
                        <span id="total_days_display" class="fw-bold text-success">1 day</span>
                    </div>
                    <div class="mb-3 form-check">
                        <input class="form-check-input" type="checkbox" name="emergency_leave" id="emergency_leave" value="1" onchange="toggleMedical()">
                        <label class="form-check-label" for="emergency_leave">Emergency / medical leave (certificate required)</label>
                    </div>
                    <div class="mb-3 d-none" id="medical_wrap">
                        <label class="form-label fw-bold">Medical certificate (PDF / JPG / PNG)</label>
                        <input type="file" name="medical_cert" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-bold">Reason</label>
                        <textarea name="leave_reason" class="form-control" rows="3" required></textarea>
                    </div>
                    <button type="submit" name="request_leave" class="btn btn-success btn-lg w-100 fw-bold">Submit &amp; notify admin</button>
                </form>
            </div>
            </div>
            <?php endif; ?>
            
            <div class="card p-4 bg-white mt-4">
                <h5 class="fw-bold mb-3"><i class="bi bi-clock-history"></i> Leave Application History</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Applied On</th>
                                <th>Dates</th>
                                <th>Type</th>
                                <th>Reason</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($history)): ?>
                            <tr><td colspan="5" class="text-center text-muted">No leave applications found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($history as $h): 
                                    $sDate = date('d M Y', strtotime($h['start_date']));
                                    $eDate = date('d M Y', strtotime($h['end_date']));
                                    $dates = ($sDate === $eDate) ? $sDate : "$sDate - $eDate";
                                    $lType = ucfirst($h['leave_type']) . ' Day';
                                    $status = $h['status'] ?? 'pending';
                                    
                                    $badge = 'bg-warning text-dark';
                                    if ($status === 'approved') $badge = 'bg-success';
                                    if ($status === 'rejected') $badge = 'bg-danger';
                                ?>
                                <tr>
                                    <td><?php echo date('d M Y', strtotime($h['applied_at'])); ?></td>
                                    <td><strong><?php echo $dates; ?></strong></td>
                                    <td><span class="badge bg-secondary"><?php echo $lType; ?></span></td>
                                    <td><?php echo htmlspecialchars($h['reason']); ?></td>
                                    <td><span class="badge <?php echo $badge; ?>"><?php echo ucfirst($status); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!staff_is_readonly_session()): ?>
<script>
const LEAVE_MIN_DATE = <?php echo json_encode($min_leave_date); ?>;

function toggleMedical() {
    document.getElementById('medical_wrap').classList.toggle('d-none', !document.getElementById('emergency_leave').checked);
}

function clampDateInput(input) {
    if (!input || !input.value) return;
    if (input.value < LEAVE_MIN_DATE) {
        input.value = LEAVE_MIN_DATE;
    }
}

function calculateDays() {
    const type = document.getElementById('leave_type').value;
    const startEl = document.getElementById('start_date');
    const endInput = document.getElementById('end_date');
    const endWrapper = document.getElementById('end_date_wrapper');
    const totalDisplay = document.getElementById('total_days_display');

    startEl.min = LEAVE_MIN_DATE;
    endInput.min = LEAVE_MIN_DATE;
    clampDateInput(startEl);

    const start = startEl.value;
    endInput.min = start && start >= LEAVE_MIN_DATE ? start : LEAVE_MIN_DATE;
    clampDateInput(endInput);

    if (type === 'half') {
        endWrapper.style.display = 'none';
        endInput.value = start;
        totalDisplay.textContent = '0.5 day';
    } else {
        endWrapper.style.display = 'block';
        if (start && endInput.value) {
            let d2 = endInput.value;
            if (d2 < start) {
                endInput.value = start;
                d2 = start;
            }
            const d1 = new Date(start + 'T00:00:00');
            const d2o = new Date(d2 + 'T00:00:00');
            const diff = Math.round((d2o - d1) / 86400000) + 1;
            totalDisplay.textContent = diff + ' day(s)';
        }
    }
}

document.getElementById('leaveForm').addEventListener('submit', function(e) {
    clampDateInput(document.getElementById('start_date'));
    clampDateInput(document.getElementById('end_date'));
    const s = document.getElementById('start_date').value;
    const en = document.getElementById('end_date').value;
    if (s < LEAVE_MIN_DATE || en < LEAVE_MIN_DATE || en < s) {
        e.preventDefault();
        alert('Please choose valid leave dates (' + LEAVE_MIN_DATE + ' or later).');
    }
});

window.addEventListener('DOMContentLoaded', calculateDays);
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/saas_sidebar_toggle.php'; ?>
</body>
</html>

</html>