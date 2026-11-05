<?php
require_once 'config/config.php';
require_once 'includes/functions.php';
if (isLoggedIn()) {
    logActivity($conn, 'logout', 'user', $_SESSION['user_id']);
}
$_SESSION = [];
session_destroy();
session_start();
setFlash('success', 'ออกจากระบบเรียบร้อยแล้ว');
header('Location: login.php');
exit;
