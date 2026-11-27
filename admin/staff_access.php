<?php
/**
 * Admin: open staff portal as a staff user (impersonation) or return to admin.
 */
session_start();
require_once __DIR__ . '/../db.php';

$isStop = isset($_GET['stop']) || isset($_POST['stop_impersonate']);

if ($isStop) {
    if (!empty($_SESSION['impersonator_id'])) {
        $_SESSION['user_id'] = $_SESSION['impersonator_id'];
        $_SESSION['name'] = $_SESSION['impersonator_name'] ?? 'Admin';
        $_SESSION['role'] = $_SESSION['impersonator_role'] ?? 'admin';
        $_SESSION['company_id'] = $_SESSION['impersonator_company_id'] ?? null;
        $_SESSION['branch_id'] = $_SESSION['impersonator_branch_id'] ?? null;
        unset(
            $_SESSION['impersonator_id'],
            $_SESSION['impersonator_name'],
            $_SESSION['impersonator_role'],
            $_SESSION['impersonator_company_id'],
            $_SESSION['impersonator_branch_id']
        );
        header('Location: admin_staff.php');
        exit();
    }
    header('Location: ../login.php');
    exit();
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'hr'], true)) {
    header('Location: ../login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['staff_id'])) {
    header('Location: admin_staff.php');
    exit();
}

$token = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($token)) {
    die('CSRF validation failed.');
}

$staffId = (int) $_POST['staff_id'];
$companyId = (int) get_company_id();

$stmt = $conn->prepare(
    "SELECT id, name, role, company_id, branch_id FROM users WHERE id = ? AND company_id = ? AND role = 'staff' LIMIT 1"
);
$stmt->bind_param('ii', $staffId, $companyId);
$stmt->execute();
$staff = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$staff) {
    header('Location: admin_staff.php?err=staff');
    exit();
}

if (!staff_is_active_for_operations($conn, $staffId)) {
    header('Location: admin_staff.php?err=inactive');
    exit();
}

if (empty($_SESSION['impersonator_id'])) {
    $_SESSION['impersonator_id'] = $_SESSION['user_id'];
    $_SESSION['impersonator_name'] = $_SESSION['name'];
    $_SESSION['impersonator_role'] = $_SESSION['role'];
    $_SESSION['impersonator_company_id'] = $_SESSION['company_id'] ?? null;
    $_SESSION['impersonator_branch_id'] = $_SESSION['branch_id'] ?? null;
}

$_SESSION['user_id'] = (int) $staff['id'];
$_SESSION['name'] = $staff['name'];
$_SESSION['role'] = 'staff';
$_SESSION['company_id'] = $staff['company_id'];
$_SESSION['branch_id'] = $staff['branch_id'];

write_audit_log($conn, 'admin.impersonate_staff', 'staff_id=' . $staffId, 'user', (string) $staffId);

header('Location: ../staff_dashboard.php');
exit();
