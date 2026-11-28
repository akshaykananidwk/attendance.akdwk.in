<?php
session_start();
require_once '../db.php';
check_access(['admin', 'manager', 'hr']);

$company_id = (int)get_company_id();
$current_month = date('Y-m');
$month_year = isset($_GET['month_year']) ? $_GET['month_year'] : $current_month;
$message = "";

// SAVE MANUAL RATING
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_rating'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) die("CSRF failed.");
    
    $u_id = $_POST['user_id'];
    $m_rating = $_POST['manual_rating'];
    $remarks = $_POST['remarks'];

    // Calculate total score logic here or just save
    $stmt = $conn->prepare("INSERT INTO performance_ratings (user_id, month_year, manual_rating, remarks) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE manual_rating = ?, remarks = ?");
    $stmt->bind_param("isdsds", $u_id, $month_year, $m_rating, $remarks, $m_rating, $remarks);
    if ($stmt->execute()) {
        $message = "<div class='alert alert-success fw-bold'>Rating saved!</div>";
    }
    $stmt->close();
}

$staff = $conn->query("SELECT id, name FROM users WHERE company_id = $company_id AND role='staff'" . staff_sql_active_filter(''));
$ratings_data = [];

while($s = $staff->fetch_assoc()) {
    $u_id = $s['id'];
    
    // 1. Attendance Stats
    $att = $conn->query("SELECT COUNT(*) as present FROM attendance_logs WHERE user_id=$u_id AND log_date LIKE '$month_year%' AND is_leave=0")->fetch_assoc();
    $present_days = $att['present'];
    $att_score = min(5, ($present_days / 24) * 5); // Assuming 24 working days

    // 2. Task Stats
    $task_q = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) as done FROM tasks WHERE assigned_to=$u_id AND created_at LIKE '$month_year%'")->fetch_assoc();
    $total_tasks = $task_q['total'];
    $done_tasks = $task_q['done'];
    $task_score = ($total_tasks > 0) ? ($done_tasks / $total_tasks) * 5 : 0;

    // 3. Manual Rating
    $manual = $conn->query("SELECT manual_rating, remarks FROM performance_ratings WHERE user_id=$u_id AND month_year='$month_year'")->fetch_assoc();
    $m_rating = $manual['manual_rating'] ?? 0;
    $remarks = $manual['remarks'] ?? '';

    // Final Weighted Score
    $total_score = ($att_score * 0.4) + ($task_score * 0.4) + ($m_rating * 0.2);

    $ratings_data[] = [
        'id' => $u_id,
        'name' => $s['name'],
        'att_score' => round($att_score, 1),
        'task_score' => round($task_score, 1),
        'manual_rating' => $m_rating,
        'total_score' => round($total_score, 1),
        'remarks' => $remarks
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Performance | <?php echo htmlspecialchars(app_brand()); ?></title>
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
    <div class="dashboard-header">
        <div>
            <button class="mobile-toggle me-2" onclick="toggleSidebar()"><i class="bi bi-list fs-4"></i></button>
            <span class="fs-4 fw-bold text-dark"><i class="bi bi-star-fill text-primary"></i> Employee Performance</span>
        </div>
        <form method="GET" class="d-flex gap-2">
            <input type="month" name="month_year" class="form-control" value="<?php echo $month_year; ?>">
            <button type="submit" class="btn btn-dark fw-bold">Filter</button>
        </form>
    </div>

    <?php if($message != "") echo $message; ?>

    <div class="card p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle text-center">
                <thead class="table-dark">
                    <tr>
                        <th>Staff Name</th>
                        <th>Attendance (40%)</th>
                        <th>Tasks (40%)</th>
                        <th>Manual (20%)</th>
                        <th>Total Score</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($ratings_data as $r): ?>
                    <tr>
                        <td class="fw-bold text-start ps-3"><?php echo $r['name']; ?></td>
                        <td><span class="badge bg-light text-dark border"><?php echo $r['att_score']; ?> / 5</span></td>
                        <td><span class="badge bg-light text-dark border"><?php echo $r['task_score']; ?> / 5</span></td>
                        <td>
                            <div class="star-rating">
                                <?php for($i=1;$i<=5;$i++) echo ($i <= $r['manual_rating']) ? '★' : '☆'; ?>
                            </div>
                        </td>
                        <td>
                            <h5 class="fw-bold m-0 text-primary"><?php echo $r['total_score']; ?> / 5</h5>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick="openRatingModal('<?php echo $r['id']; ?>', '<?php echo $r['name']; ?>', '<?php echo $r['manual_rating']; ?>', '<?php echo addslashes($r['remarks'] ?? ''); ?>')">
                                <i class="bi bi-star"></i> Rate
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
        </div>
    </div>
</div>

<div class="modal fade" id="ratingModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Manual Performance Rating</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="user_id" id="r_user_id">
                <div class="mb-3">
                    <label class="form-label fw-bold">Staff: <span id="r_staff_name" class="text-primary"></span></label>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Star Rating (1-5)</label>
                    <select name="manual_rating" id="r_manual_rating" class="form-select">
                        <option value="1">1 Star - Poor</option>
                        <option value="2">2 Stars - Fair</option>
                        <option value="3">3 Stars - Good</option>
                        <option value="4">4 Stars - Excellent</option>
                        <option value="5">5 Stars - Outstanding</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Remarks / Feedback</label>
                    <textarea name="remarks" id="r_remarks" class="form-control" rows="3"></textarea>
                </div>
                <button type="submit" name="save_rating" class="btn btn-dark w-100 fw-bold">Save Performance Rating</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function openRatingModal(id, name, rating, remarks) {
        document.getElementById('r_user_id').value = id;
        document.getElementById('r_staff_name').innerText = name;
        document.getElementById('r_manual_rating').value = rating && rating !== '0' ? rating : '3';
        document.getElementById('r_remarks').value = remarks || '';
        new bootstrap.Modal(document.getElementById('ratingModal')).show();
    }
</script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
