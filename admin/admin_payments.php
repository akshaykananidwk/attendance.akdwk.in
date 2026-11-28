<?php
session_start();
require_once '../db.php';
check_access(['admin', 'hr']);

$company_id = (int)get_company_id();
$branch_id = get_branch_id();
$current_month = date('m');
$current_year = date('Y');
$message = "";

// 1. RECORD PAYMENT
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['pay_salary'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) die("CSRF failed.");
    
    $u_id = $_POST['user_id'];
    $amount = $_POST['amount'];
    $method = $_POST['method'];
    $ref = $_POST['ref'];
    $remarks = $_POST['remarks'];
    $p_date = date('Y-m-d');

    $conn->begin_transaction();
    try {
        // Record in salary_payments
        $stmt = $conn->prepare("INSERT INTO salary_payments (user_id, company_id, amount, payment_date, method, transaction_ref, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iidssss", $u_id, $company_id, $amount, $p_date, $method, $ref, $remarks);
        $stmt->execute();

        // Salary paid must reduce the staff balance — the balance is calculated from Upad/Jama,
        // so the payment is also written as an Upad entry (unless the admin unticks it).
        if (!empty($_POST['deduct_balance'])) {
            $note = 'Salary payment (' . $method . ($ref !== '' ? ' ' . $ref : '') . ')' . ($remarks !== '' ? ' - ' . $remarks : '');
            $stmt2 = $conn->prepare("INSERT INTO advance_payments (user_id, company_id, amount, date, transaction_type, description) VALUES (?, ?, ?, ?, 'Upad', ?)");
            if ($stmt2) {
                $stmt2->bind_param('iidss', $u_id, $company_id, $amount, $p_date, $note);
                $stmt2->execute();
                $stmt2->close();
            }
        }
        
        $conn->commit();
        $message = "<div class='alert alert-success fw-bold'>Payment of ₹$amount recorded successfully!</div>";
    } catch (Exception $e) {
        $conn->rollback();
        $message = "<div class='alert alert-danger fw-bold'>Error: " . $e->getMessage() . "</div>";
    }
}

// Reuse logic from staff_dashboard (Simplified for admin overview)
function getNetPayable($conn, $u_id, $m, $y) {
    // Opening Balance (Earned + Jama - Upad) up to last month
    // For simplicity in this overview, we'll just show the cumulative balance
    // In a real scenario, this would be a complex query.
    return 0; // Placeholder for now, will implement properly in the report
}

$staff = $conn->query("SELECT u.id, u.name, u.salary_type, u.salary_amount, b.name as branch_name 
                       FROM users u JOIN branches b ON u.branch_id = b.id 
                       WHERE u.role='staff' AND u.company_id = $company_id");

$payments = $conn->query("SELECT p.*, u.name FROM salary_payments p JOIN users u ON p.user_id = u.id WHERE p.company_id = $company_id ORDER BY p.id DESC LIMIT 10");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salary Payments | <?php echo htmlspecialchars(app_brand()); ?></title>
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
            <span class="fs-4 fw-bold text-dark"><i class="bi bi-currency-rupee text-primary"></i> Salary Payments</span>
        </div>
    </div>

    <?php if($message != "") echo $message; ?>

    <div class="row">
        <div class="col-md-4">
            <div class="card p-4">
                <h5 class="fw-bold mb-3 border-bottom pb-2 text-primary">Record Payout</h5>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Staff</label>
                        <select name="user_id" class="form-select" required>
                            <?php while($s = $staff->fetch_assoc()): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo $s['name']; ?> (<?php echo $s['branch_name']; ?>)</option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Amount (₹)</label>
                        <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Method</label>
                        <select name="method" class="form-select">
                            <option value="UPI">UPI</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Cash">Cash</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Transaction Ref / Details</label>
                        <input type="text" name="ref" class="form-control" placeholder="UTR / UPI ID / Receipt No">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Remarks</label>
                        <textarea name="remarks" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="deduct_balance" value="1" id="dedBal" checked><label class="form-check-label small" for="dedBal">Also deduct from staff balance (adds an Upad entry). Untick only if you already entered this payment as Upad.</label></div>
                    <button type="submit" name="pay_salary" class="btn btn-primary w-100 fw-bold">Record Salary Payment</button>
                </form>
            </div>
        </div>

        <div class="col-md-8">
            <h5 class="fw-bold mb-3"><i class="bi bi-clock-history"></i> Recent Payout History</h5>
            <div class="card p-0 overflow-hidden">
                <table class="ui-cards-sm table table-hover align-middle m-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th>
                            <th>Staff</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Ref</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($p = $payments->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo date("d M Y", strtotime($p['payment_date'])); ?></td>
                            <td class="fw-bold"><?php echo $p['name']; ?></td>
                            <td class="text-success fw-bold">₹<?php echo $p['amount']; ?></td>
                            <td><span class="badge bg-light text-dark border"><?php echo $p['method']; ?></span></td>
                            <td class="small text-muted"><?php echo $p['transaction_ref']; ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
