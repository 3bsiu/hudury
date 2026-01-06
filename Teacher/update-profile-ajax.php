<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('teacher');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $email = trim($input['email'] ?? '');
    $phone = trim($input['phone'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Please enter a valid email address',
            'message_ar' => 'يرجى إدخال عنوان بريد إلكتروني صحيح'
        ]);
        exit;
    }

    $teacherId = getCurrentUserId();
    if (!$teacherId) {
        echo json_encode([
            'success' => false, 
            'message' => 'User not authenticated',
            'message_ar' => 'المستخدم غير مصادق عليه'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT Teacher_ID FROM teacher WHERE Email = ? AND Teacher_ID != ?");
    $stmt->execute([$email, $teacherId]);
    if ($stmt->fetch()) {
        echo json_encode([
            'success' => false, 
            'message' => 'This email is already in use',
            'message_ar' => 'هذا البريد الإلكتروني مستخدم بالفعل'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE teacher SET Email = ?, Phone = ? WHERE Teacher_ID = ?");
    $stmt->execute([$email, $phone, $teacherId]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Profile updated successfully',
        'message_ar' => 'تم تحديث الملف الشخصي بنجاح'
    ]);
    
} catch (PDOException $e) {
    error_log("Error updating teacher profile: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Error updating teacher profile: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>

