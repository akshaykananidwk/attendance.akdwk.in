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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <?php echo app_pwa_head(); ?>
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; }
        body { min-height: 100vh; min-height: 100dvh; font-family: "Inter", "Segoe UI", system-ui, -apple-system, sans-serif; color: #fff; overflow-x: hidden;
               background: linear-gradient(180deg, #071a5c 0%, #1b2a8f 26%, #4a2f9a 46%, #a7489a 60%, #f07a3c 70%, #2a1d5e 71%, #121552 100%);
               display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 16px 14px 46px; position: relative; }
        .bg { position: fixed; inset: 0; z-index: 0; pointer-events: none; }
        .bg .shore { position: absolute; left: 0; bottom: 0; width: 46vw; max-width: 560px; height: auto; }
        .bg .temple { position: absolute; right: 0; bottom: 0; height: 78vh; max-height: 720px; width: auto; }
        .bg .wave { position: absolute; left: 0; right: 0; bottom: 0; width: 100%; height: 90px; }
        .bg .glowline { position: absolute; left: -10%; right: -10%; top: 18%; height: 220px; border-top: 2px solid rgba(120,160,255,.25); border-radius: 50%; transform: rotate(-6deg); }
        .birds { position: absolute; right: 6%; top: 24%; width: 90px; opacity: .8; }
        .wrap { position: relative; z-index: 1; width: 100%; max-width: 430px; }
        .brand { text-align: center; margin-bottom: 14px; }
        .brand .app { width: 64px; height: 64px; border-radius: 18px; margin: 0 auto 8px; background: linear-gradient(145deg, #2f7bff, #1442c9); display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 30px rgba(37,99,235,.55), inset 0 1px 0 rgba(255,255,255,.25); }
        .brand .title { display: inline-flex; align-items: center; gap: 6px; font-size: 1.85rem; font-weight: 800; letter-spacing: -.01em; line-height: 1.1; }
        .brand .title span { color: #5ec2ff; }
        .brand .sub { display: flex; align-items: center; justify-content: center; gap: 8px; font-size: .9rem; color: #dbe4ff; margin-top: 4px; }
        .brand .sub i { display: inline-block; width: 34px; height: 2px; background: linear-gradient(90deg, transparent, #f5c06b); position: relative; }
        .brand .sub i.r { background: linear-gradient(90deg, #f5c06b, transparent); }
        .feather { width: 34px; height: 54px; margin-right: -2px; }
        .flute { width: 64px; height: 26px; }
        .card { background: linear-gradient(160deg, rgba(255,255,255,.17), rgba(255,255,255,.07)); border: 1px solid rgba(255,255,255,.28); border-radius: 24px; padding: 20px 20px 14px;
                backdrop-filter: blur(16px) saturate(140%); -webkit-backdrop-filter: blur(16px) saturate(140%); box-shadow: 0 20px 60px rgba(5,10,40,.45); }
        .card h1 { margin: 0; text-align: center; font-size: 1.5rem; font-weight: 800; }
        .card .lead { text-align: center; color: #c9d4ff; font-size: .88rem; margin: 3px 0 10px; }
        .badge { display: table; margin: 0 auto 12px; padding: 5px 13px; border-radius: 999px; background: rgba(16,185,129,.18); border: 1px solid rgba(52,211,153,.45); color: #6ee7b7; font-weight: 800; font-size: .72rem; letter-spacing: .06em; }
        .badge i { margin-right: 5px; }
        label.l { display: block; font-weight: 700; font-size: .86rem; margin: 0 0 5px; }
        .f { position: relative; margin-bottom: 11px; }
        .f > i.lead-i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); font-size: 1.05rem; color: #c9d4ff; }
        .f > i.wa { color: #4ade80; }
        .f input { width: 100%; height: 46px; border-radius: 12px; border: 1px solid rgba(255,255,255,.32); background: rgba(10,20,70,.35); color: #fff; font-size: .95rem; padding: 0 46px 0 42px; outline: none; }
        .f input::placeholder { color: rgba(220,228,255,.7); }
        .f input:focus { border-color: #60a5fa; box-shadow: 0 0 0 3px rgba(96,165,250,.3); background: rgba(10,20,70,.5); }
        .eye { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); background: none; border: 0; color: #dbe4ff; font-size: 1.1rem; padding: 8px; cursor: pointer; }
        .row2 { display: flex; justify-content: space-between; align-items: center; margin: 2px 0 12px; font-size: .86rem; }
        .rem { display: flex; align-items: center; gap: 7px; cursor: pointer; user-select: none; }
        .rem input { width: 17px; height: 17px; accent-color: #2563eb; margin: 0; }
        .row2 a { color: #7cc4ff; font-weight: 700; text-decoration: none; }
        .btn { width: 100%; height: 48px; border: 0; border-radius: 14px; color: #fff; font-size: 1.02rem; font-weight: 800; cursor: pointer;
               background: linear-gradient(90deg, #1e6bff, #2f8cff 50%, #1d4ed8); box-shadow: 0 10px 26px rgba(37,99,235,.55), inset 0 1px 0 rgba(255,255,255,.3); display: flex; align-items: center; justify-content: center; gap: 9px; }
        .btn:hover { filter: brightness(1.07); } .btn[disabled] { opacity: .8; }
        .wa-line { text-align: center; font-size: .82rem; color: #dbe4ff; margin: 11px 0 10px; } .wa-line i { color: #4ade80; margin-right: 5px; }
        .feat { display: grid; grid-template-columns: repeat(3, 1fr); border-top: 1px solid rgba(255,255,255,.16); padding-top: 10px; }
        .feat div { text-align: center; font-size: .74rem; color: #e5ecff; line-height: 1.25; padding: 0 4px; }
        .feat div + div { border-left: 1px solid rgba(255,255,255,.16); }
        .feat i { display: block; font-size: 1.25rem; margin-bottom: 3px; color: #fff; }
        .alert { background: rgba(239,68,68,.22); border: 1px solid rgba(248,113,113,.55); color: #fecaca; border-radius: 12px; padding: 8px 12px; font-size: .84rem; font-weight: 600; margin-bottom: 10px; display: flex; gap: 7px; }
        .foot { position: relative; z-index: 1; margin-top: 14px; font-size: .78rem; color: #c9d4ff; text-align: center; }
        @media (max-width: 480px) {
            body { justify-content: flex-start; padding-top: 14px; }
            .brand .app { width: 50px; height: 50px; border-radius: 14px; margin-bottom: 6px; }
            .brand .app svg { width: 28px; height: 28px; }
            .brand .title { font-size: 1.45rem; } .feather { width: 26px; height: 42px; } .flute { width: 48px; }
            .brand .sub { font-size: .8rem; }
            .bg .shore { width: 80vw; opacity: .9; } .bg .temple { height: 52vh; opacity: .95; }
            .card { padding: 16px 15px 12px; border-radius: 20px; background: linear-gradient(160deg, rgba(20,30,90,.55), rgba(20,30,90,.35)); }
            .card h1 { font-size: 1.3rem; }
        }
        @media (max-height: 640px) { .feat { display: none; } .brand .sub { display: none; } .brand .app { display: none; } .brand { margin-bottom: 8px; } .card .lead { margin-bottom: 6px; } .badge { margin-bottom: 8px; } .f { margin-bottom: 8px; } .foot { margin-top: 8px; } body { padding-top: 10px; padding-bottom: 20px; } }
    </style>
</head>
<body>
<div class="bg" aria-hidden="true">
    <div class="glowline"></div>
    <svg class="birds" viewBox="0 0 90 30" fill="none" stroke="#1b1530" stroke-width="1.6"><path d="M2 12l7 5 7-5M30 4l6 4 6-4M56 14l7 5 7-5M74 6l5 3 5-3"/></svg>
    <img class="shore" src="assets/login/shore.svg" alt="">
    <img class="temple" src="assets/login/temple.svg" alt="">
    <svg class="wave" viewBox="0 0 1440 90" preserveAspectRatio="none"><path d="M0 50 C240 10 420 80 720 46 S1200 10 1440 40 V90 H0z" fill="#1d4ed8" fill-opacity=".55"/><path d="M0 66 C300 36 520 96 820 64 S1240 40 1440 60 V90 H0z" fill="#2563eb" fill-opacity=".7"/></svg>
</div>

<main class="wrap">
    <div class="brand">
        <div class="app"><svg width="36" height="36" viewBox="0 0 48 48" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="9" width="36" height="32" rx="6"/><path d="M6 18h36M16 5v8M32 5v8"/><path d="M14 27l4 4 7-7" stroke-width="3.2"/><circle cx="33" cy="30" r="3.2" fill="#fff" stroke="none"/><path d="M27.5 38c1-3 3-4.5 5.5-4.5s4.5 1.5 5.5 4.5" stroke-width="2.6"/></svg></div>
        <div class="title">
            <svg class="feather" viewBox="0 0 40 64" aria-hidden="true"><path d="M30 6C14 12 6 28 8 46l-4 14" stroke="#6b7f2a" stroke-width="2" fill="none"/><path d="M30 6c6 10 2 30-14 40C10 34 14 14 30 6z" fill="#1f8a4c"/><ellipse cx="22" cy="24" rx="8" ry="10" fill="#0ea5a3"/><ellipse cx="22" cy="24" rx="5.5" ry="7" fill="#f5c542"/><ellipse cx="22" cy="24" rx="3.6" ry="4.8" fill="#1e3a8a"/><ellipse cx="22" cy="23.5" rx="1.6" ry="2.2" fill="#0b1d4a"/></svg>
            Krishna <span>Attendance</span>
        </div>
        <div class="sub"><i></i>Attendance &amp; Payroll System<i class="r"></i>
            <svg class="flute" viewBox="0 0 80 30" aria-hidden="true"><rect x="2" y="9" width="74" height="7" rx="3.5" fill="#d9a441" stroke="#8a5a1a"/><g fill="#5a3a10"><circle cx="22" cy="12.5" r="1.3"/><circle cx="30" cy="12.5" r="1.3"/><circle cx="38" cy="12.5" r="1.3"/><circle cx="46" cy="12.5" r="1.3"/></g><path d="M64 16v6M62 22l2 7 2-7" stroke="#dc2626" stroke-width="2" fill="#dc2626"/></svg>
        </div>
    </div>

    <section class="card">
        <h1>Welcome Back! 👋</h1>
        <p class="lead">Sign in to your secure workspace</p>
        <span class="badge"><i class="bi bi-shield-fill-check"></i>SECURE LOGIN</span>
        <?php if ($error !== ''): ?>
            <div class="alert" role="alert"><i class="bi bi-exclamation-circle-fill"></i><span><?php echo htmlspecialchars($error); ?></span></div>
        <?php endif; ?>
        <form method="POST" action="" id="loginForm" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <label class="l" for="phone">WhatsApp Number / LID</label>
            <div class="f"><i class="bi bi-whatsapp lead-i wa"></i>
                <input type="text" id="phone" name="phone" inputmode="tel" autocomplete="username" placeholder="Enter WhatsApp Number or LID" required value="<?php echo htmlspecialchars((string) ($_POST['phone'] ?? '')); ?>">
            </div>
            <label class="l" for="password">Password</label>
            <div class="f"><i class="bi bi-lock lead-i"></i>
                <input type="password" id="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
                <button type="button" class="eye" id="eyeBtn" aria-label="Show password"><i class="bi bi-eye"></i></button>
            </div>
            <div class="row2">
                <label class="rem"><input type="checkbox" name="remember" value="1" <?php echo ($_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($_POST['remember'])) ? 'checked' : ''; ?>> Remember Me</label>
                <a href="forgot_password.php">Forgot Password?</a>
            </div>
            <button type="submit" class="btn" id="loginBtn"><i class="bi bi-box-arrow-in-right"></i> Sign In Securely</button>
        </form>
        <div class="wa-line"><i class="bi bi-whatsapp"></i>Integrated with WhatsApp Cloud API</div>
        <div class="feat">
            <div><i class="bi bi-calendar-check"></i>Attendance<br>Tracking</div>
            <div><i class="bi bi-database"></i>Payroll<br>Management</div>
            <div><i class="bi bi-people"></i>Staff<br>Portal</div>
        </div>
    </section>
</main>
<div class="foot">© <?php echo date('Y'); ?> <?php echo htmlspecialchars(app_brand()); ?>. All Rights Reserved.</div>

<script>
    document.getElementById('eyeBtn').addEventListener('click', function () {
        var p = document.getElementById('password'), show = p.type === 'password';
        p.type = show ? 'text' : 'password';
        this.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
    });
    document.getElementById('loginForm').addEventListener('submit', function () {
        var b = document.getElementById('loginBtn'); b.disabled = true; b.innerHTML = 'Signing in…';
    });
</script>
</body>
</html>
