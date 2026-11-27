<?php
session_start();
require_once '../db.php';
check_access(['admin']); // Only Super Admin can manage branches

$company_id = (int)get_company_id();
$message = "";

// 1. ADD BRANCH
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_branch'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) die("CSRF validation failed.");
    
    $name = $_POST['name'];
    $address = $_POST['address'];
    $late_threshold = $_POST['late_threshold'];

    $stmt = $conn->prepare("INSERT INTO branches (company_id, name, address, late_threshold_mins) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("issi", $company_id, $name, $address, $late_threshold);
    if ($stmt->execute()) {
        $message = "<div class='alert alert-success fw-bold'>Branch added successfully!</div>";
    }
    $stmt->close();
}

// 2. DELETE BRANCH
if (isset($_GET['delete_id'])) {
    $id = $_GET['delete_id'];
    $stmt = $conn->prepare("DELETE FROM branches WHERE id = ? AND company_id = ?");
    $stmt->bind_param("ii", $id, $company_id);
    $stmt->execute();
    header("Location: admin_branches.php?msg=deleted");
    exit();
}

$branches = $conn->query("SELECT * FROM branches WHERE company_id = $company_id ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Branches | Smart Attendance</title>
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
            <span class="fs-4 fw-bold text-dark"><i class="bi bi-building text-primary"></i> Branch Management</span>
        </div>
        <button class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addBranchModal">
            <i class="bi bi-plus-circle-fill"></i> Add New Branch
        </button>
    </div>

    <?php if($message != "") echo $message; ?>

    <div class="card p-4">
        <table class="table table-hover text-center align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Branch Name</th>
                    <th>Address</th>
                    <th>Late Threshold</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $branches->fetch_assoc()): ?>
                <tr>
                    <td class="fw-bold"><?php echo $row['name']; ?></td>
                    <td><?php echo $row['address']; ?></td>
                    <td><?php echo $row['late_threshold_mins']; ?> mins</td>
                    <td>
                        <a href="?delete_id=<?php echo $row['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this branch?')"><i class="bi bi-trash"></i></a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addBranchModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Add New Branch</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <div class="mb-3">
                    <label class="form-label fw-bold">Branch Name</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Address</label>
                    <textarea name="address" class="form-control" rows="2"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Late Threshold (Minutes)</label>
                    <input type="number" name="late_threshold" class="form-control" value="15" required>
                </div>
                <button type="submit" name="add_branch" class="btn btn-primary w-100 fw-bold">Save Branch</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
