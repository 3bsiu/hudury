<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('student');

header('Content-Type: application/json');

$currentStudentId = getCurrentUserId();

if (!$currentStudentId) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$action = $_POST['action'] ?? '';

if ($action === 'submitAssignment') {
    try {
        $assignmentId = intval($_POST['assignment_id'] ?? 0);
        
        if (!$assignmentId) {
            echo json_encode(['success' => false, 'message' => 'Invalid assignment ID']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT a.*, s.Class_ID as Student_Class_ID
            FROM assignment a
            INNER JOIN student s ON s.Student_ID = ?
            WHERE a.Assignment_ID = ? 
            AND a.Class_ID = s.Class_ID 
            AND a.Status = 'active'
            AND EXISTS (
                SELECT 1 FROM course_class cc 
                WHERE cc.Course_ID = a.Course_ID 
                AND cc.Class_ID = s.Class_ID
            )
        ");
        $stmt->execute([$currentStudentId, $assignmentId]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$assignment) {
            echo json_encode(['success' => false, 'message' => 'Assignment not found or not available for your class/subject']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT Submission_ID FROM submission
            WHERE Student_ID = ? AND Assignment_ID = ?
        ");
        $stmt->execute([$currentStudentId, $assignmentId]);
        $existingSubmission = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingSubmission) {
            echo json_encode(['success' => false, 'message' => 'You have already submitted this assignment']);
            exit();
        }

        $dueDate = new DateTime($assignment['Due_Date']);
        $now = new DateTime();
        $isLate = $now > $dueDate;

        if (!isset($_FILES['submission_file']) || $_FILES['submission_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Please upload a file']);
            exit();
        }

        $fileContent = file_get_contents($_FILES['submission_file']['tmp_name']);
        if ($fileContent === false) {
            echo json_encode(['success' => false, 'message' => 'Error reading file']);
            exit();
        }

        $originalFileName = $_FILES['submission_file']['name'];
        $fileMimeType = $_FILES['submission_file']['type'] ?? 'application/octet-stream';

        $status = $isLate ? 'late' : 'submitted';

        $stmt = $pdo->prepare("
            INSERT INTO submission (Student_ID, Assignment_ID, File_Name, File_Data, File_MIME_Type, Status, Submission_Date)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$currentStudentId, $assignmentId, $originalFileName, $fileContent, $fileMimeType, $status]);
        
        echo json_encode([
            'success' => true,
            'message' => $isLate ? 
                'Assignment submitted successfully (marked as late)' : 
                'Assignment submitted successfully'
        ]);
        
    } catch (PDOException $e) {
        error_log("Error submitting assignment: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>

