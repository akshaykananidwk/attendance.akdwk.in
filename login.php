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
            if (!empty($_POST['remember'])) {
                remember_issue($conn, (int) $user['id']);
            }

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
<html lang="gu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Login | <?php echo htmlspecialchars(app_brand()); ?></title>
    <link rel="preload" as="image" href="assets/images/krishna-login-bg.webp" type="image/webp">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/login.css?v=<?php echo app_asset_version('assets/css/login.css'); ?>">
    <?php echo app_pwa_head(); ?>
</head>
<body>
<div class="login-page">
    <main class="login-main" aria-labelledby="loginTitle">
        <header class="lg-brand">
            <div class="lg-logo" aria-hidden="true">
                <svg width="34" height="34" viewBox="0 0 48 48" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="9" width="36" height="32" rx="6"/><path d="M6 18h36M16 5v8M32 5v8"/><path d="M14 27l4 4 7-7" stroke-width="3.2"/><circle cx="33" cy="30" r="3.2" fill="#fff" stroke="none"/><path d="M27.5 38c1-3 3-4.5 5.5-4.5s4.5 1.5 5.5 4.5" stroke-width="2.6"/></svg>
            </div>
            <h1 class="lg-title">
                <svg class="lg-feather" viewBox="0 0 40 64" aria-hidden="true"><path d="M30 6C14 12 6 28 8 46l-4 14" stroke="#7a8f2a" stroke-width="2" fill="none"/><path d="M30 6c6 10 2 30-14 40C10 34 14 14 30 6z" fill="#1f8a4c"/><ellipse cx="22" cy="24" rx="8" ry="10" fill="#0ea5a3"/><ellipse cx="22" cy="24" rx="5.5" ry="7" fill="#f5c542"/><ellipse cx="22" cy="24" rx="3.6" ry="4.8" fill="#1e3a8a"/><ellipse cx="22" cy="23.5" rx="1.6" ry="2.2" fill="#0b1d4a"/></svg>
                Krishna <span>Attendance</span>
            </h1>
            <p class="lg-sub">Attendance &amp; Payroll System</p>
        </header>

        <section class="lg-card">
            <h2 id="loginTitle">Welcome Back! 👋</h2>
            <p class="lg-lead">Sign in to your secure workspace</p>
            <span class="lg-badge"><i class="bi bi-shield-fill-check" aria-hidden="true"></i>SECURE LOGIN</span>
            <?php if ($error !== ''): ?>
                <div class="lg-alert" role="alert"><i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i><span><?php echo htmlspecialchars($error); ?></span></div>
            <?php endif; ?>
            <form method="POST" action="" id="loginForm" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <label class="lg-label" for="phone">WhatsApp Number / LID</label>
                <div class="lg-field">
                    <i class="bi bi-whatsapp lg-icon wa" aria-hidden="true"></i>
                    <input type="text" id="phone" name="phone" inputmode="tel" autocomplete="username" placeholder="Enter WhatsApp Number or LID" required
                           value="<?php echo htmlspecialchars((string) ($_POST['phone'] ?? '')); ?>">
                </div>
                <label class="lg-label" for="password">Password</label>
                <div class="lg-field">
                    <i class="bi bi-lock lg-icon" aria-hidden="true"></i>
                    <input type="password" id="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
                    <button type="button" class="lg-eye" id="eyeBtn" aria-label="Show password" aria-pressed="false" aria-controls="password"><i class="bi bi-eye" aria-hidden="true"></i></button>
                </div>
                <div class="lg-row">
                    <label class="lg-remember"><input type="checkbox" name="remember" value="1" <?php echo ($_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($_POST['remember'])) ? 'checked' : ''; ?>> Remember Me</label>
                    <a href="forgot_password.php">Forgot Password?</a>
                </div>
                <button type="submit" class="lg-btn" id="loginBtn"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Sign In Securely</button>
            </form>
            <p class="lg-wa"><i class="bi bi-whatsapp" aria-hidden="true"></i>Integrated with WhatsApp Cloud API</p>
        </section>
    </main>
    <footer class="login-footer">© <?php echo date('Y'); ?> <?php echo htmlspecialchars(app_brand()); ?>. All Rights Reserved.</footer>
</div>
<script src="assets/js/login.js?v=<?php echo app_asset_version('assets/js/login.js'); ?>" defer></script>
</body>
</html>
