<?php
session_start();
require_once 'db.php';

$error = '';
$success = '';
$step = 1; // 1: Request OTP, 2: Verify OTP & Reset, 3: Success

if (isset($_SESSION['reset_otp']) && isset($_SESSION['reset_phone'])) {
    if (time() < $_SESSION['reset_otp_expiry']) {
        $step = 2;
    } else {
        unset($_SESSION['reset_otp'], $_SESSION['reset_phone'], $_SESSION['reset_otp_expiry']);
        $error = "Your previous OTP expired. Please request a new one.";
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        die("CSRF Token validation failed.");
    }

    if (isset($_POST['request_otp'])) {
        $phone = trim($_POST['phone']);
        $stmt = $conn->prepare("SELECT id, name FROM users WHERE phone_number = ? AND role = 'staff' LIMIT 1");
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res->num_rows > 0) {
            $user = $res->fetch_assoc();
            $otp = rand(100000, 999999);
            $_SESSION['reset_otp'] = (string)$otp;
            $_SESSION['reset_phone'] = $phone;
            $_SESSION['reset_otp_expiry'] = time() + 300; // 5 mins

            $message = "Your Smart SaaS password reset OTP is *$otp*. It is valid for 5 minutes. Do not share it with anyone.";
            sendWhatsAppDirectMessage($phone, $message);
            
            $success = "An OTP has been sent to your WhatsApp number.";
            $step = 2;
        } else {
            $error = "This number is not registered as a staff account.";
        }
        $stmt->close();
    } elseif (isset($_POST['reset_password'])) {
        $otp_input = trim($_POST['otp']);
        $new_password = $_POST['new_password'];

        if ($step === 2 && isset($_SESSION['reset_otp'])) {
            if ($otp_input === $_SESSION['reset_otp']) {
                if (time() > $_SESSION['reset_otp_expiry']) {
                    $error = "OTP has expired. Please go back and request a new one.";
                    $step = 1;
                    unset($_SESSION['reset_otp'], $_SESSION['reset_phone'], $_SESSION['reset_otp_expiry']);
                } else {
                    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                    $phone = $_SESSION['reset_phone'];

                    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE phone_number = ? AND role = 'staff'");
                    $stmt->bind_param("ss", $hashed, $phone);
                    if ($stmt->execute()) {
                        $success = "Password successfully reset! You can now login.";
                        $step = 3;
                        unset($_SESSION['reset_otp'], $_SESSION['reset_phone'], $_SESSION['reset_otp_expiry']);
                    } else {
                        $error = "Failed to update password. Please try again.";
                    }
                    $stmt->close();
                }
            } else {
                $error = "Invalid OTP. Please check the WhatsApp message and try again.";
            }
        } else {
            $error = "Session expired. Please request a new OTP.";
            $step = 1;
        }
    } elseif (isset($_POST['cancel_reset'])) {
        unset($_SESSION['reset_otp'], $_SESSION['reset_phone'], $_SESSION['reset_otp_expiry']);
        header("Location: login.php");
        exit();
    }
}

$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | <?php echo htmlspecialchars(app_brand()); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        :root { --primary-color: #128c7e; --secondary-color: #075e54; }
        body { background: linear-gradient(135deg, #f4f7f6 0%, #e9f2ef 100%); font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; height: 100vh; display: flex; align-items: center; }
        
        .login-card { border: none; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); overflow: hidden; background: #fff; width: 100%; max-width: 400px; margin: auto; transition: transform 0.3s ease; }
        .card-header-custom { background-color: var(--primary-color); padding: 30px; text-align: center; color: white; }
        .card-header-custom i { font-size: 3rem; margin-bottom: 10px; }
        
        .form-control { border-radius: 10px; padding: 12px 15px; background: #f8f9fa; border: 1px solid #eee; }
        .form-control:focus { box-shadow: 0 0 0 0.25rem rgba(18, 140, 126, 0.25); border-color: var(--primary-color); background: #fff; }
        
        .btn-login { background-color: var(--primary-color); color: white; border: none; border-radius: 10px; padding: 12px; font-weight: 600; width: 100%; transition: 0.3s; }
        .btn-login:hover { background-color: var(--secondary-color); color: white; box-shadow: 0 5px 15px rgba(18, 140, 126, 0.3); }
        .btn-outline-secondary { border-radius: 10px; padding: 12px; font-weight: 600; width: 100%; }
        
        .features-badge { background: rgba(18, 140, 126, 0.1); color: var(--primary-color); padding: 5px 15px; border-radius: 50px; font-size: 0.8rem; font-weight: 600; }
        
        @media (max-width: 576px) {
            body { padding: 20px; align-items: flex-start; padding-top: 50px; }
        }
    </style>
    <?php echo app_pwa_head(); ?>
</head>
<body>

<div class="container">
    <div class="login-card animated fadeIn">
        <div class="card-header-custom">
            <i class="bi bi-shield-lock"></i>
            <h3 class="fw-bold mb-0">Reset Password</h3>
            <p class="small opacity-75 mt-1">Smart SaaS</p>
        </div>
        
        <div class="card-body p-4">
            <?php if($error != ""): ?>
                <div class="alert alert-danger py-2 small text-center fw-bold"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if($success != ""): ?>
                <div class="alert alert-success py-2 small text-center fw-bold"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if ($step === 1): ?>
            <!-- STEP 1: Request OTP -->
            <div class="text-center mb-4">
                <p class="text-muted small">Enter your registered WhatsApp number to receive an OTP.</p>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="mb-4">
                    <label class="form-label small fw-bold text-muted">WhatsApp Number</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-0"><i class="bi bi-telephone text-muted"></i></span>
                        <input type="text" name="phone" class="form-control" placeholder="e.g. 918160563366" required autofocus>
                    </div>
                </div>
                <button type="submit" name="request_otp" class="btn btn-login mb-3">
                    SEND OTP <i class="bi bi-whatsapp"></i>
                </button>
                <a href="login.php" class="btn btn-outline-secondary">BACK TO LOGIN</a>
            </form>
            
            <?php elseif ($step === 2): ?>
            <!-- STEP 2: Verify OTP and Set Password -->
            <div class="text-center mb-4">
                <p class="text-muted small">OTP sent to <strong><?php echo htmlspecialchars($_SESSION['reset_phone']); ?></strong>.<br>Enter it below along with your new password.</p>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Enter 6-Digit OTP</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-0"><i class="bi bi-chat-dots text-muted"></i></span>
                        <input type="text" name="otp" class="form-control" placeholder="123456" required autofocus maxlength="6">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-bold text-muted">New Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-0"><i class="bi bi-lock text-muted"></i></span>
                        <input type="text" name="new_password" class="form-control" placeholder="New Password" required>
                    </div>
                </div>
                <button type="submit" name="reset_password" class="btn btn-login mb-3">
                    RESET PASSWORD <i class="bi bi-check-circle"></i>
                </button>
                <button type="submit" name="cancel_reset" class="btn btn-outline-secondary" formnovalidate>CANCEL</button>
            </form>

            <?php elseif ($step === 3): ?>
            <!-- STEP 3: Success -->
            <div class="text-center mt-4">
                <a href="login.php" class="btn btn-login">
                    GO TO LOGIN <i class="bi bi-box-arrow-in-right"></i>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
