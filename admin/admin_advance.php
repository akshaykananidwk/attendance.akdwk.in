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
$message = "";

// Month & Year Filter
$current_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$current_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// 1. ADD ADVANCE/CREDIT PAYMENT LOGIC
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_advance'])) {
    // CSRF Verification
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        die("CSRF Token validation failed.");
    }

    $user_id = $_POST['user_id'];
    $amount = $_POST['amount'];
    $adv_date = $_POST['adv_date'];
    $description = $_POST['description'];
    $transaction_type = isset($_POST['transaction_type']) ? $_POST['transaction_type'] : 'Upad';

    $stmt = $conn->prepare("INSERT INTO advance_payments (user_id, company_id, branch_id, amount, date, description, transaction_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iiidsss", $user_id, $company_id, $branch_id, $amount, $adv_date, $description, $transaction_type);
    
    if ($stmt->execute()) {
        $msg_text = ($transaction_type == 'Jama') ? "Credit (Jama) recorded successfully!" : "Expense/Advance (Upad) recorded successfully!";
        $message = "<div class='alert alert-success alert-dismissible fade show fw-bold'>$msg_text<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        
        // Group Notification
        $staff_q = $conn->prepare("SELECT name FROM users WHERE id = ?");
        $staff_q->bind_param("i", $user_id);
        $staff_q->execute();
        $staff_data = $staff_q->get_result()->fetch_assoc();
        $staff_name = $staff_data['name'] ?? 'Unknown';
        $staff_q->close();
        
        $type_icon = ($transaction_type == 'Jama') ? "💰" : "📉";
        $type_label = ($transaction_type == 'Jama') ? "CREDIT (JAMA)" : "EXPENSE/ADVANCE (UPAD)";
        $notif = "$type_icon *Advance Entry: $type_label*\n"
               . "• *Staff:* $staff_name\n"
               . "• *Amount:* ₹" . number_format($amount, 2) . "\n"
               . "• *Reason:* $description";
        sendWhatsAppGroupNotification($notif);
    } else {
        $message = "<div class='alert alert-danger alert-dismissible fade show fw-bold'>Error recording entry.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
    $stmt->close();
}

// 2. DELETE LOGIC
if (isset($_GET['delete_id'])) {
    $id = $_GET['delete_id'];
    $stmt = $conn->prepare("DELETE FROM advance_payments WHERE id = ? AND company_id = ?");
    $stmt->bind_param("ii", $id, $company_id);
    if ($stmt->execute()) {
        $stmt->close();
        header("Location: admin_advance.php?msg=deleted");
        exit();
    }
    $stmt->close();
}

// Fetch Staff for Dropdown
$staff_dropdown = $conn->query("SELECT id, name FROM users WHERE role='staff' AND company_id = $company_id" . staff_sql_active_filter('') . ' ORDER BY name ASC');

// Filter Logic
$filter_user = isset($_GET['filter_user']) ? $_GET['filter_user'] : '';

if ($filter_user !== '') {
    $stmt = $conn->prepare("SELECT a.*, u.name as staff_name FROM advance_payments a JOIN users u ON a.user_id = u.id 
                           WHERE a.user_id = ? AND a.company_id = ? 
                           AND MONTH(a.date) = ? AND YEAR(a.date) = ? 
                           ORDER BY a.date DESC, a.id DESC");
    $stmt->bind_param("iiii", $filter_user, $company_id, $current_month, $current_year);
    $stmt->execute();
    $adv_result = $stmt->get_result();
    $stmt->close();
} else {
    $stmt = $conn->prepare("SELECT a.*, u.name as staff_name FROM advance_payments a JOIN users u ON a.user_id = u.id 
                           WHERE a.company_id = ? 
                           AND MONTH(a.date) = ? AND YEAR(a.date) = ? 
                           ORDER BY a.date DESC, a.id DESC");
    $stmt->bind_param("iii", $company_id, $current_month, $current_year);
    $stmt->execute();
    $adv_result = $stmt->get_result();
    $stmt->close();
}

$csrf_token = generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advance & Expenses | Smart Attendance</title>
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
            <div class="dashboard-header d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <button class="mobile-toggle me-2" onclick="toggleSidebar()"><i class="bi bi-list fs-4"></i></button>
                    <span class="fs-4 fw-bold text-dark">Upad & Jama Log</span>
                </div>
                <button class="btn btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#addAdvanceModal">
                    <i class="bi bi-plus-circle-fill"></i> Add Entry
                </button>
            </div>
            <div class="alert alert-info small mb-3">
                <strong>Policy (Rule 10–11):</strong> No routine personal advance salary. Use <em>Jama</em> for repayments only.
                Medical emergencies: record as office expense / hospital payment (manual), not standard Upad.
            </div>
            <?php if($message != "") echo $message; ?>
            <?php if(isset($_GET['msg']) && $_GET['msg']=='deleted') echo "<div class='alert alert-danger fw-bold'>Record deleted successfully!</div>"; ?>

            <div class="card p-3 mb-4 bg-light border shadow-sm">
                <form method="GET" action="" class="row align-items-center g-2">
                    <div class="col-md-4 col-12">
                        <label class="form-label fw-bold text-primary mb-1"><i class="bi bi-funnel-fill"></i> Filter by Staff / Office</label>
                        <select name="filter_user" class="form-select fw-bold border-primary" onchange="this.form.submit()">
                            <option value="">All Records (બધા રેકોર્ડ)</option>
                            <?php 
                            $staff_dropdown->data_seek(0); // Reset pointer
                            while($st = $staff_dropdown->fetch_assoc()): 
                                $sel = ($filter_user == $st['id']) ? 'selected' : '';
                            ?>
                                <option value="<?php echo $st['id']; ?>" <?php echo $sel; ?>><?php echo $st['name']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="card p-4">
                <h5 class="fw-bold mb-3"><i class="bi bi-history text-warning"></i> Transaction History</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle text-center border">
                        <thead class="table-dark">
                            <tr>
                                <th>Date</th>
                                <th>Name / Office</th>
                                <th>Type</th>
                                <th>Amount (₹)</th>
                                <th>Reason / Description</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($adv_result && $adv_result->num_rows > 0): ?>
                                <?php while($row = $adv_result->fetch_assoc()): ?>
                                    <?php 
                                        // Logic to decide color and sign based on Type
                                        $is_jama = (isset($row['transaction_type']) && $row['transaction_type'] == 'Jama');
                                        $color = $is_jama ? 'text-success' : 'text-danger';
                                        $sign = $is_jama ? '+' : '-';
                                        $badge = $is_jama ? '<span class="badge bg-success">જમા (Jama)</span>' : '<span class="badge bg-danger">ઉપાડ / ખર્ચ (Upad)</span>';
                                    ?>
                                <tr>
                                    <td><strong><?php echo date("d-m-Y", strtotime($row['date'])); ?></strong></td>
                                    <td class="text-start ps-3 fw-bold text-primary">
                                        <i class="bi bi-person-circle text-secondary"></i> <?php echo $row['staff_name']; ?>
                                    </td>
                                    <td><?php echo $badge; ?></td>
                                    <td class="fw-bold fs-6 <?php echo $color; ?>"><?php echo $sign; ?> ₹<?php echo number_format($row['amount'], 2); ?></td>
                                    <td class="text-muted italic small"><?php echo htmlspecialchars($row['description']); ?></td>
                                    <td>
                                        <a href="?delete_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this record?')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-muted py-4">No records found for the selected filter.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addAdvanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content card">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-wallet2"></i> Add Entry (Upad / Jama)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">Select Staff / Office</label>
                    <select name="user_id" class="form-select bg-light border-dark" required>
                        <option value="">-- Choose Name --</option>
                        <?php 
                        $staff_dropdown->data_seek(0); // Reset pointer again for modal
                        while($st = $staff_dropdown->fetch_assoc()) echo "<option value='{$st['id']}'>{$st['name']}</option>"; 
                        ?>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Transaction Type</label>
                    <select name="transaction_type" class="form-select fw-bold bg-light border-dark" required>
                        <option value="Upad" class="text-danger">ઉપાડ / ખર્ચ (Advance/Expense) [-]</option>
                        <option value="Jama" class="text-success">જમા (Credit/Deposit) [+]</option>
                    </select>
                </div>

                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Amount (₹)</label>
                        <input type="number" step="0.01" name="amount" class="form-control bg-light" placeholder="e.g. 21000" required>
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Date</label>
                        <input type="date" name="adv_date" class="form-control bg-light" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Reason / Note</label>
                    <textarea name="description" class="form-control bg-light" placeholder="e.g. Office Rent March" rows="2" required></textarea>
                </div>
                <button type="submit" name="add_advance" class="btn btn-warning w-100 fw-bold text-dark fs-5 shadow-sm"><i class="bi bi-save2-fill"></i> Save Record</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>