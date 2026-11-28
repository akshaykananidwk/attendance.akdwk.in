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
<html lang="gu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0b1d4a">
    <title>Login | <?php echo htmlspecialchars(app_brand()); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        :root { --navy: #0b1d4a; --navy2: #101c33; --blue: #1d6cf2; --muted: #64748b; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; font-family: "Inter", "Segoe UI", system-ui, -apple-system, sans-serif; color: #0f172a;
               background: radial-gradient(1200px 600px at 10% -10%, #1e3a8a 0%, transparent 60%), linear-gradient(160deg, #0b1220 0%, #0b1d4a 55%, #0e2763 100%);
               display: flex; align-items: center; justify-content: center; padding: 24px 16px; }
        .lg-card { width: 100%; max-width: 440px; border-radius: 28px; overflow: hidden; background: #fff; box-shadow: 0 30px 80px rgba(0,0,0,.45); }
        .lg-top { background: linear-gradient(180deg, #111c33 0%, #0e1a30 100%); color: #fff; padding: 30px 30px 34px; position: relative; }
        .lg-brand { display: flex; align-items: center; gap: 14px; }
        .lg-logo { width: 56px; height: 56px; border-radius: 16px; background: linear-gradient(135deg, #2f80ff, #1d4ed8); display: flex; align-items: center; justify-content: center; box-shadow: 0 8px 20px rgba(29,108,242,.45); flex: 0 0 56px; }
        .lg-name { font-size: 1.6rem; font-weight: 800; line-height: 1.1; letter-spacing: -.01em; }
        .lg-sub { color: #a8b5cf; font-size: .95rem; margin-top: 2px; }
        .lg-shield { width: 86px; height: 86px; margin: 30px auto 18px; border-radius: 50%; background: rgba(255,255,255,.07); display: flex; align-items: center; justify-content: center; font-size: 2.3rem; color: #4f8dff; }
        .lg-top h1 { text-align: center; font-size: 1.9rem; font-weight: 800; margin: 0; }
        .lg-top p { text-align: center; color: #a8b5cf; margin: 8px 0 0; }
        .lg-body { padding: 26px 30px 22px; }
        .lg-badge { display: inline-block; background: #dcfce7; color: #15803d; font-weight: 700; font-size: .78rem; letter-spacing: .05em; padding: 6px 14px; border-radius: 999px; margin-bottom: 18px; }
        .lg-label { font-weight: 700; font-size: 1.02rem; margin-bottom: 8px; display: block; }
        .lg-field { position: relative; margin-bottom: 18px; }
        .lg-field > i.lead { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 1.1rem; }
        .lg-field input { width: 100%; height: 56px; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 0 50px 0 46px; font-size: 1rem; outline: none; transition: border-color .15s, box-shadow .15s; background: #fff; }
        .lg-field input:focus { border-color: var(--blue); box-shadow: 0 0 0 4px rgba(29,108,242,.15); }
        .lg-eye { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); border: 0; background: transparent; color: #475569; font-size: 1.25rem; padding: 8px; }
        .lg-forgot { display: block; text-align: right; font-weight: 700; color: var(--blue); text-decoration: none; margin: -6px 0 18px; }
        .lg-btn { width: 100%; height: 58px; border: 0; border-radius: 14px; background: linear-gradient(90deg, #1d6cf2, #2f80ff); color: #fff; font-size: 1.15rem; font-weight: 700; box-shadow: 0 10px 24px rgba(29,108,242,.35); transition: transform .1s, filter .15s; }
        .lg-btn:hover { filter: brightness(1.05); } .lg-btn:active { transform: scale(.99); }
        .lg-btn[disabled] { opacity: .75; }
        .lg-wa { text-align: center; color: #475569; margin: 18px 0 16px; font-size: .95rem; }
        .lg-wa i { color: #16a34a; margin-right: 6px; }
        .lg-foot { border-top: 1px solid #e2e8f0; padding-top: 14px; text-align: center; color: #94a3b8; font-size: .88rem; }
        .lg-alert { border-radius: 12px; padding: 10px 14px; font-weight: 600; font-size: .92rem; background: #fee2e2; color: #b91c1c; margin-bottom: 16px; display: flex; gap: 8px; align-items: flex-start; }
        @media (max-width: 480px) { body { padding: 0; align-items: stretch; } .lg-card { border-radius: 0; max-width: none; min-height: 100vh; box-shadow: none; } .lg-top { padding: 28px 22px 30px; } .lg-body { padding: 24px 22px; } }
    </style>
    <?php echo app_pwa_head(); ?>
</head>
<body>
<main class="lg-card">
    <section class="lg-top">
        <div class="lg-brand">
            <div class="lg-logo"><svg width="30" height="30" viewBox="0 0 40 40" aria-hidden="true"><path d="M20 4 36 12 20 20 4 12z" fill="#fff"/><path d="M4 20 20 28l16-8" fill="none" stroke="#dbeafe" stroke-width="3.2" stroke-linejoin="round"/><path d="M4 27.5 20 35.5l16-8" fill="none" stroke="#bfdbfe" stroke-width="3.2" stroke-linejoin="round"/></svg></div>
            <div><div class="lg-name"><?php echo htmlspecialchars(app_brand()); ?></div><div class="lg-sub">Attendance &amp; Payroll System</div></div>
        </div>
        <div class="lg-shield"><i class="bi bi-shield-check"></i></div>
        <h1>Welcome Back!</h1>
        <p>Sign in to your secure workspace</p>
    </section>
    <section class="lg-body">
        <span class="lg-badge">SECURE LOGIN</span>
        <?php if ($error !== ''): ?>
            <div class="lg-alert" role="alert"><i class="bi bi-exclamation-circle-fill"></i><span><?php echo htmlspecialchars($error); ?></span></div>
        <?php endif; ?>
        <form method="POST" action="" id="loginForm" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <label class="lg-label" for="phone">WhatsApp Number / LID</label>
            <div class="lg-field">
                <i class="bi bi-telephone lead"></i>
                <input type="text" id="phone" name="phone" inputmode="tel" autocomplete="username" placeholder="Enter WhatsApp Number or LID" required
                       value="<?php echo htmlspecialchars((string) ($_POST['phone'] ?? '')); ?>">
            </div>
            <label class="lg-label" for="password">Password</label>
            <div class="lg-field">
                <i class="bi bi-lock lead"></i>
                <input type="password" id="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
                <button type="button" class="lg-eye" id="eyeBtn" aria-label="Show password"><i class="bi bi-eye"></i></button>
            </div>
            <a class="lg-forgot" href="forgot_password.php">Forgot Password?</a>
            <button type="submit" class="lg-btn" id="loginBtn">Sign In Securely <i class="bi bi-arrow-right"></i></button>
        </form>
        <div class="lg-wa"><i class="bi bi-whatsapp"></i>Integrated with WhatsApp Cloud API</div>
        <div class="lg-foot">© <?php echo date('Y'); ?> <?php echo htmlspecialchars(app_brand()); ?> System</div>
    </section>
</main>
<script>
    document.getElementById('eyeBtn').addEventListener('click', function () {
        var p = document.getElementById('password'), show = p.type === 'password';
        p.type = show ? 'text' : 'password';
        this.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
        this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
    document.getElementById('loginForm').addEventListener('submit', function () {
        var b = document.getElementById('loginBtn'); b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Signing in…';
    });
</script>
</body>
</html>
