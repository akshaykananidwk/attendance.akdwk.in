<?php

declare(strict_types=1);

/** Include after session + db.php on staff pages. */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/employee_lifecycle.php';

employee_lifecycle_ready($conn);
staff_enforce_portal_access($conn);
$page = basename($_SERVER['PHP_SELF'] ?? 'staff');
staff_track_page_view($conn, $page);
