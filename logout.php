<?php
session_start();
require_once __DIR__ . '/db.php';
remember_forget($conn);
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $pr = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $pr['path'], $pr['domain'], $pr['secure'], $pr['httponly']);
}
session_destroy();
header('Location: login.php');
exit();
