<?php

function logAdminAction($pdo, $action, $category, $recordId = null, $description = '', $tableName = null, $changes = null) {
    try {
        
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
            
            return false;
        }
        
        $adminId = intval($_SESSION['user_id']);
        $adminName = $_SESSION['user_name'] ?? 'Admin';

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }

        if ($changes && is_array($changes) && count($changes) > 0) {
            $changeDetails = [];
            foreach ($changes as $field => $change) {
                if (isset($change['old']) && isset($change['new'])) {
                    $changeDetails[] = "$field: '{$change['old']}' → '{$change['new']}'";
                }
            }
            if (count($changeDetails) > 0) {
                $description .= ' | Changes: ' . implode(', ', $changeDetails);
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO activity_log 
            (User_Type, User_ID, User_Name, Action, Category, Description, Table_Name, Record_ID, IP_Address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            'admin',
            $adminId,
            $adminName,
            $action,
            $category,
            $description,
            $tableName,
            $recordId,
            $ipAddress
        ]);
        
        return $result;
    } catch (PDOException $e) {
        
        error_log("Activity logging failed: " . $e->getMessage());
        return false;
    }
}

function logUserAction($pdo, $action, $userType, $userId, $userName, $details = '') {
    $description = ucfirst($action) . " {$userType}: {$userName}";
    if ($details) {
        $description .= " ({$details})";
    }
    
    return logAdminAction(
        $pdo,
        $action,
        'user',
        $userId,
        $description,
        $userType,
        null
    );
}

function logExamAction($pdo, $action, $examId, $examTitle, $details = '') {
    $description = ucfirst($action) . " exam: {$examTitle}";
    if ($details) {
        $description .= " ({$details})";
    }
    
    return logAdminAction(
        $pdo,
        $action,
        'exam',
        $examId,
        $description,
        'exam',
        null
    );
}

function logClassAction($pdo, $action, $classId, $className, $details = '') {
    $description = ucfirst($action) . " class: {$className}";
    if ($details) {
        $description .= " ({$details})";
    }
    
    return logAdminAction(
        $pdo,
        $action,
        'class',
        $classId,
        $description,
        'class',
        null
    );
}

function logCourseAction($pdo, $action, $courseId, $courseName, $details = '') {
    $description = ucfirst($action) . " course: {$courseName}";
    if ($details) {
        $description .= " ({$details})";
    }
    
    return logAdminAction(
        $pdo,
        $action,
        'course',
        $courseId,
        $description,
        'course',
        null
    );
}

function logAttendanceAction($pdo, $action, $details = '') {
    $description = ucfirst($action) . " attendance";
    if ($details) {
        $description .= ": {$details}";
    }
    
    return logAdminAction(
        $pdo,
        $action,
        'attendance',
        null,
        $description,
        'attendance',
        null
    );
}

function logSettingsAction($pdo, $action, $settingName, $details = '') {
    $description = ucfirst($action) . " setting: {$settingName}";
    if ($details) {
        $description .= " ({$details})";
    }
    
    return logAdminAction(
        $pdo,
        $action,
        'settings',
        null,
        $description,
        null,
        null
    );
}

function logAuthAction($pdo, $action, $details = '') {
    $description = ucfirst($action);
    if ($details) {
        $description .= ": {$details}";
    }
    
    return logAdminAction(
        $pdo,
        $action,
        'auth',
        null,
        $description,
        null,
        null
    );
}

function logMedicalAction($pdo, $action, $studentId, $studentName, $details = '') {
    $description = ucfirst($action) . " medical record for student: {$studentName}";
    if ($details) {
        $description .= " ({$details})";
    }
    
    return logAdminAction(
        $pdo,
        $action,
        'medical',
        $studentId,
        $description,
        'medical_record',
        null
    );
}

function logAcademicStatusAction($pdo, $action, $studentId, $studentName, $status, $details = '') {
    $description = ucfirst($action) . " academic status for student: {$studentName} → {$status}";
    if ($details) {
        $description .= " ({$details})";
    }
    
    return logAdminAction(
        $pdo,
        $action,
        'academic',
        $studentId,
        $description,
        'academic_status',
        null
    );
}

