<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('parent');

header('Content-Type: application/json');

$currentParentId = getCurrentUserId();

if (!$currentParentId) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$action = $_POST['action'] ?? '';

if ($action === 'submitLeaveRequest') {
    try {
        $studentId = intval($_POST['student_id'] ?? 0);
        $leaveDate = $_POST['leave_date'] ?? '';
        $endDate = $_POST['end_date'] ?? null;
        $reason = $_POST['reason'] ?? '';
        $notes = $_POST['notes'] ?? '';

        if (!$studentId || !$leaveDate || !$reason) {
            echo json_encode(['success' => false, 'message' => 'Please fill in all required fields']);
            exit();
        }

        if (strtotime($leaveDate) < strtotime('today')) {
            echo json_encode(['success' => false, 'message' => 'Leave date cannot be in the past']);
            exit();
        }

        if ($endDate && strtotime($endDate) < strtotime($leaveDate)) {
            echo json_encode(['success' => false, 'message' => 'End date must be after leave date']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM parent_student_relationship
            WHERE Parent_ID = ? AND Student_ID = ?
        ");
        $stmt->execute([$currentParentId, $studentId]);
        $relationship = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$relationship || $relationship['count'] == 0) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: Student not linked to your account']);
            exit();
        }

        $documentData = null;
        $documentMimeType = null;
        if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $fileExtension = pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION);
            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
            
            if (!in_array(strtolower($fileExtension), $allowedExtensions)) {
                echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: PDF, JPG, PNG, DOC, DOCX']);
                exit();
            }

            $documentData = file_get_contents($_FILES['document']['tmp_name']);
            if ($documentData === false) {
                echo json_encode(['success' => false, 'message' => 'Error reading file']);
                exit();
            }
            
            $documentMimeType = $_FILES['document']['type'] ?? 'application/octet-stream';
        }

        $stmt = $pdo->prepare("
            INSERT INTO leave_request (Student_ID, Parent_ID, Leave_Date, End_Date, Reason, Notes, Document_Data, Document_MIME_Type, Status, Submitted_At)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        
        $stmt->execute([
            $studentId,
            $currentParentId,
            $leaveDate,
            $endDate ?: null,
            $reason,
            $notes ?: null,
            $documentData,
            $documentMimeType
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Leave request submitted successfully!'
        ]);
        
    } catch (PDOException $e) {
        error_log("Error submitting leave request: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        error_log("Error submitting leave request: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>

