<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
        return false;
    }

    if (!isset($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true) {
        return false;
    }
    
    return true;
}

function getCurrentUserId() {
    return isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
}

function getCurrentUserType() {
    return $_SESSION['user_type'] ?? null;
}

function requireLogin() {
    if (!isLoggedIn()) {
        
        if (isset($_SESSION['pending_user_id']) && isset($_SESSION['pending_user_type'])) {
            header("Location: ../otp_page.php");
            exit();
        }
        
        header("Location: ../signin.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
        exit();
    }
}

function requireUserType($requiredType) {
    requireLogin();
    $currentType = getCurrentUserType();
    
    if ($currentType !== $requiredType) {
        
        $redirectMap = [
            'admin' => '../Admin/admin-dashboard.php',
            'teacher' => '../Teacher/teacher-dashboard.php',
            'parent' => '../Parent/parent-dashboard.php',
            'student' => '../Student/student-dashboard.php'
        ];
        
        $redirect = $redirectMap[$currentType] ?? '../signin.php';
        header("Location: $redirect?error=" . urlencode("Access denied. This page is only for " . ucfirst($requiredType) . " users."));
        exit();
    }
}

function getCurrentUserData($pdo) {
    if (!isLoggedIn()) {
        return null;
    }
    
    $userId = getCurrentUserId();
    $userType = getCurrentUserType();
    
    if (!$userId || !$userType) {
        return null;
    }
    
    try {
        $table = '';
        $idField = '';
        
        switch($userType) {
            case 'admin':
                $table = 'admin';
                $idField = 'Admin_ID';
                break;
            case 'teacher':
                $table = 'teacher';
                $idField = 'Teacher_ID';
                break;
            case 'parent':
                $table = 'parent';
                $idField = 'Parent_ID';
                break;
            case 'student':
                $table = 'student';
                $idField = 'Student_ID';
                break;
            default:
                return null;
        }
        
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE $idField = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching user data: " . $e->getMessage());
        return null;
    }
}

function hasPermission($resource, $action = 'view') {
    $userType = getCurrentUserType();

    if ($userType === 'admin') {
        return true;
    }

    $permissions = [
        'student' => [
            'view_own_data' => true,
            'view_own_schedule' => true,
            'view_own_grades' => true,
            'view_own_assignments' => true,
        ],
        'parent' => [
            'view_children_data' => true,
            'view_children_schedule' => true,
            'view_children_grades' => true,
            'submit_feedback' => true,
        ],
        'teacher' => [
            'view_assigned_classes' => true,
            'manage_grades' => true,
            'manage_attendance' => true,
            'send_notifications' => true,
        ],
    ];
    
    return isset($permissions[$userType][$resource]) && $permissions[$userType][$resource] === true;
}

function logout() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (isset($_SESSION['user_id']) && isset($_SESSION['user_type'])) {
        $userId = $_SESSION['user_id'];
        $userType = $_SESSION['user_type'];
        $userName = $_SESSION['user_name'] ?? 'User';

        if ($userType === 'admin') {
            require_once __DIR__ . '/../db.php';
            require_once __DIR__ . '/activity-logger.php';
            logAuthAction($pdo, 'logout', "User: {$userName} (ID: {$userId})");
        }
        
        error_log("User logout: User_ID=$userId, Type=$userType");
    }

    unset($_SESSION['otp_verified']);
    unset($_SESSION['pending_user_id']);
    unset($_SESSION['pending_user_type']);
    unset($_SESSION['pending_user_name']);
    unset($_SESSION['pending_user_email']);
    unset($_SESSION['pending_redirect']);
    unset($_SESSION['pending_user_national_id']);
    unset($_SESSION['pending_user_student_code']);

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

    header("Location: ../signin.php?logged_out=1");
    exit();
}

