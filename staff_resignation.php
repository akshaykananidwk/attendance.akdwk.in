<?php
session_start();
require_once 'db.php';
require_once __DIR__ . '/includes/staff_portal_guard.php';

$user_id = (int) $_SESSION['user_id'];
$company_id = (int) get_company_id();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_resignation'])) {
    staff_require_can_mutate();
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF failed.');
    }
    $notice_date = $_POST['notice_date'] ?? date('Y-m-d');
    $last_day = $_POST['last_working_day'] ?? '';
    $without_notice = !empty($_POST['without_notice']) ? 1 : 0;
    $reason = trim($_POST['reason'] ?? '');

    $min_notice = date('Y-m-d', strtotime('+1 month'));
    if (!$without_notice && strtotime($last_day) < strtotime($min_notice)) {
        $message = "<div class='alert alert-danger'>Rule 12: minimum 1 month notice required (last working day on or after " . date('d M Y', strtotime($min_notice)) . ").</div>";
    } elseif (salary_table_exists($conn, 'resignation_requests')) {
        $stmt = $conn->prepare(
            'INSERT INTO resignation_requests (user_id, company_id, notice_date, last_working_day, without_notice, reason, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $status = 'pending';
        $stmt->bind_param('iississ', $user_id, $company_id, $notice_date, $last_day, $without_notice, $reason, $status);
        if ($stmt->execute()) {
            $message = "<div class='alert alert-success'>Resignation submitted. HR will review.</div>";
            if ($without_notice) {
                $message .= "<div class='alert alert-warning'>Without notice: last month salary may be withheld per policy (Rule 13).</div>";
            }
        }
        $stmt->close();
    } else {
        $message = "<div class='alert alert-warning'>Run db_update_v6_office_rules.php migration first.</div>";
    }
}

$csrf = generate_csrf_token();
$min_last_day = date('Y-m-d', strtotime('+1 month'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resignation | <?php echo htmlspecialchars(app_brand()); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/saas_theme.css?v=<?php echo app_asset_version(); ?>">
    <?php echo app_pwa_head(); ?>
</head>
<body>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php require_once __DIR__ . '/includes/saas_sidebar_staff.php'; ?>
        <div class="col-md-10 main-content p-4">
            <h3 class="fw-bold">Resignation request</h3>
            <p class="text-muted small">Minimum 1 month notice (Rule 12). Resigning without notice may forfeit last month salary (Rule 13).</p>
            <?php echo $message; ?>
            <div class="card p-4">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Notice date</label>
                        <input type="date" name="notice_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Last working day</label>
                        <input type="date" name="last_working_day" class="form-control" min="<?php echo $min_last_day; ?>" required>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" name="without_notice" id="wn" value="1">
                        <label class="form-check-label" for="wn">Resigning without prior notice (salary impact)</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Reason</label>
                        <textarea name="reason" class="form-control" rows="3" required></textarea>
                    </div>
                    <button type="submit" name="submit_resignation" class="btn btn-primary fw-bold">Submit</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
