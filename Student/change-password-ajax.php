<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('student');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $currentPassword = $input['currentPassword'] ?? '';
    $newPassword = $input['newPassword'] ?? '';

    if (empty($currentPassword) || empty($newPassword)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Please fill in all fields',
            'message_ar' => 'يرجى ملء جميع الحقول'
        ]);
        exit;
    }
    
    if (strlen($newPassword) < 6) {
        echo json_encode([
            'success' => false, 
            'message' => 'Password must be at least 6 characters',
            'message_ar' => 'كلمة السر يجب أن تكون 6 أحرف على الأقل'
        ]);
        exit;
    }

    $studentId = getCurrentUserId();
    if (!$studentId) {
        echo json_encode([
            'success' => false, 
            'message' => 'User not authenticated',
            'message_ar' => 'المستخدم غير مصادق عليه'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT Password FROM student WHERE Student_ID = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        echo json_encode([
            'success' => false, 
            'message' => 'User not found',
            'message_ar' => 'المستخدم غير موجود'
        ]);
        exit;
    }

    $passwordField = 'Password';
    $passwordMatch = false;
    
    if (isset($student[$passwordField])) {
        
        if (password_verify($currentPassword, $student[$passwordField])) {
            $passwordMatch = true;
        }
        
        elseif ($student[$passwordField] === $currentPassword) {
            $passwordMatch = true;
        }
    }
    
    if (!$passwordMatch) {
        echo json_encode([
            'success' => false, 
            'message' => 'Current password is incorrect',
            'message_ar' => 'كلمة السر الحالية غير صحيحة'
        ]);
        exit;
    }

    if ($currentPassword === $newPassword) {
        echo json_encode([
            'success' => false, 
            'message' => 'New password must be different from current password',
            'message_ar' => 'كلمة السر الجديدة يجب أن تكون مختلفة عن الحالية'
        ]);
        exit;
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE student SET Password = ? WHERE Student_ID = ?");
    $stmt->execute([$hashedPassword, $studentId]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Password changed successfully',
        'message_ar' => 'تم تغيير كلمة السر بنجاح'
    ]);
    
} catch (PDOException $e) {
    error_log("Error changing student password: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Error changing student password: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>

