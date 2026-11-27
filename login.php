<?php
session_start();
require_once 'db.php';

// જો યુઝર પહેલેથી લોગિન હોય, તો તેને તેના ડેશબોર્ડ પર રીડાયરેક્ટ કરો
if (isset($_SESSION['user_id'])) {
    if (($_SESSION['role'] ?? '') === 'staff' && empty($_SESSION['impersonator_id']) && employee_lifecycle_ready($conn)) {
        $lc = staff_fetch_lifecycle($conn, (int) $_SESSION['user_id']);
        if (!staff_can_login($conn, $lc)) {
            $_SESSION = [];
            session_destroy();
            header('Location: login.php?blocked=1');
            exit();
        }
    }
    if ($_SESSION['role'] === 'auditor') {
        header("Location: admin/admin_reports.php");
    } elseif ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'manager' || $_SESSION['role'] === 'hr') {
        header("Location: admin/admin_dashboard.php");
    } else {
        header("Location: staff_dashboard.php");
    }
    exit();
}

$error = isset($_GET['blocked']) ? 'Your account is disabled or login is not allowed. Contact admin.' : '';
// લોગિન લોજિક
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 1. CSRF Verification
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        die("CSRF Token validation failed. Please refresh and try again.");
    }

    $phone = $_POST['phone'];
    $password = $_POST['password'];

    // Use Prepared Statement
    $cols = 'id, name, password, role, company_id, branch_id';
    if (employee_lifecycle_ready($conn)) {
        $cols .= ', account_status, login_allowed';
    }
    $stmt = $conn->prepare("SELECT {$cols} FROM users WHERE phone_number = ? LIMIT 1");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        $stored = (string) ($user['password'] ?? '');
        $is_valid = false;
        if (substr($stored, 0, 4) === '$2y$' || substr($stored, 0, 4) === '$2a$') {
            $is_valid = password_verify($password, $stored);
        } elseif ($stored !== '') {
            // Legacy plain-text password in DB — allow login until admin runs hash_passwords.php
            $is_valid = hash_equals($stored, $password);
        }

        if ($is_valid) {
            if ($user['role'] === 'staff' && !staff_can_login($conn, $user)) {
                $error = 'Your account login is restricted. Please contact the office admin.';
            } else {
            session_regenerate_on_login();
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['company_id'] = $user['company_id'];
            $_SESSION['branch_id'] = $user['branch_id'];

            if ($user['role'] === 'staff') {
                staff_apply_session_lifecycle($user);
                staff_record_login(
                    $conn,
                    (int) $user['id'],
                    (string) $user['name'],
                    (int) ($user['company_id'] ?? 0),
                    (string) ($user['account_status'] ?? STAFF_STATUS_ACTIVE)
                );
                write_audit_log(
                    $conn,
                    'staff.login',
                    'status=' . ($user['account_status'] ?? STAFF_STATUS_ACTIVE),
                    'user',
                    (string) $user['id']
                );
            }

            if ($user['role'] === 'auditor') {
                header("Location: admin/admin_reports.php");
            } elseif ($user['role'] === 'admin' || $user['role'] === 'manager' || $user['role'] === 'hr') {
                header("Location: admin/admin_dashboard.php");
            } else {
                header("Location: staff_dashboard.php");
            }
            exit();
            }
        } else {
            if ($stored !== '' && substr($stored, 0, 4) !== '$2y$' && substr($stored, 0, 4) !== '$2a$') {
                $error = 'Invalid password. If this continues, ask admin to reset your password from Staff Management.';
            } else {
                $error = 'Invalid phone number or password.';
            }
        }
    } else {
        $error = 'Invalid phone number or password.';
    }
    $stmt->close();
}

$csrf_token = generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Login | Smart Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        :root { --primary-color: #128c7e; --secondary-color: #075e54; }
        body { background: linear-gradient(135deg, #f4f7f6 0%, #e9f2ef 100%); font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; height: 100vh; display: flex; align-items: center; }
        
        .login-card { border: none; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); overflow: hidden; background: #fff; width: 100%; max-width: 400px; margin: auto; transition: transform 0.3s ease; }
        .login-card:hover { transform: translateY(-5px); }
        
        .card-header-custom { background-color: var(--primary-color); padding: 30px; text-align: center; color: white; }
        .card-header-custom i { font-size: 3rem; margin-bottom: 10px; }
        
        .form-control { border-radius: 10px; padding: 12px 15px; background: #f8f9fa; border: 1px solid #eee; }
        .form-control:focus { box-shadow: 0 0 0 0.25rem rgba(18, 140, 126, 0.25); border-color: var(--primary-color); background: #fff; }
        
        .btn-login { background-color: var(--primary-color); color: white; border: none; border-radius: 10px; padding: 12px; font-weight: 600; width: 100%; transition: 0.3s; }
        .btn-login:hover { background-color: var(--secondary-color); color: white; box-shadow: 0 5px 15px rgba(18, 140, 126, 0.3); }
        
        .features-badge { background: rgba(18, 140, 126, 0.1); color: var(--primary-color); padding: 5px 15px; border-radius: 50px; font-size: 0.8rem; font-weight: 600; }
        
        /* Mobile responsive adjustments */
        @media (max-width: 576px) {
            body { padding: 20px; align-items: flex-start; padding-top: 50px; }
            .login-card { box-shadow: 0 10px 20px rgba(0,0,0,0.05); }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="login-card animated fadeIn">
        <div class="card-header-custom">
            <i class="bi bi-whatsapp"></i>
            <h3 class="fw-bold mb-0">Smart SaaS</h3>
            <p class="small opacity-75 mt-1">Attendance & Payroll System</p>
        </div>
        
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <span class="features-badge">SECURE LOGIN</span>
            </div>

            <?php if($error != ""): ?>
                <div class="alert alert-danger py-2 small text-center fw-bold"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">WhatsApp Number / LID</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-0"><i class="bi bi-telephone text-muted"></i></span>
                        <input type="text" name="phone" class="form-control" placeholder="e.g. 918160563366" required>
                    </div>
                </div>
                
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-bold text-muted mb-0">Password</label>
                        <a href="forgot_password.php" class="small text-decoration-none fw-bold" style="color: var(--primary-color);">Forgot Password?</a>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-0"><i class="bi bi-lock text-muted"></i></span>
                        <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-login mb-3">
                    LOG IN TO PANEL <i class="bi bi-arrow-right-short"></i>
                </button>
            </form>
            
            <div class="text-center mt-3">
                <p class="text-muted small">Integrated with WhatsApp Cloud API<br>© 2026 Smart Attendance System</p>
            </div>
        </div>
    </div>
    
    <div class="row mt-5 d-none d-md-flex text-center justify-content-center">
        <div class="col-md-3">
            <i class="bi bi-lightning-charge-fill text-warning fs-4"></i>
            <p class="small fw-bold">Real-time Tracking</p>
        </div>
        <div class="col-md-3">
            <i class="bi bi-shield-check text-success fs-4"></i>
            <p class="small fw-bold">Secure Access</p>
        </div>
        <div class="col-md-3">
            <i class="bi bi-phone-vibrate text-primary fs-4"></i>
            <p class="small fw-bold">WhatsApp Reply</p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>