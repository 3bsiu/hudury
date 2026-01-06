<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id']) && isset($_SESSION['user_type'])) {
    $userId = $_SESSION['user_id'];
    $userType = $_SESSION['user_type'];
    $userName = $_SESSION['user_name'] ?? 'User';

    if ($userType === 'admin') {
        require_once 'db.php';
        require_once 'includes/activity-logger.php';
        logAuthAction($pdo, 'logout', "User: {$userName} (ID: {$userId})");
    }
    
    error_log("User logout: User_ID=$userId, Type=$userType");
}

$_SESSION = array();

if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
    setcookie(session_name(), '', time() - 3600, '/', '', true, true); 
}

session_destroy();

if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
}

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

header("Location: signin.php?logged_out=1");
exit();
?>

