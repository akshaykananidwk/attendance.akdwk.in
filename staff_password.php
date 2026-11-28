<?php
session_start();
require_once 'db.php';
require_once __DIR__ . '/includes/staff_portal_guard.php';
require_once __DIR__ . '/includes/password_change.php';
$message = password_change_handle($conn);
$csrf = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | <?php echo htmlspecialchars(app_brand()); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/saas_theme.css?v=<?php echo app_asset_version(); ?>">
    <?php echo app_pwa_head(); ?>
</head>
<body>
<div class="saas-sidebar-backdrop" id="saasSidebarBackdrop" aria-hidden="true"></div>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php require_once __DIR__ . '/includes/saas_sidebar_staff.php'; ?>
        <div class="col-md-10 main-content p-3 p-md-4">
            <h3 class="mb-3"><i class="bi bi-key-fill text-primary"></i> Change my password</h3>
            <?php echo $message; ?>
            <?php echo password_change_form($csrf); ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
