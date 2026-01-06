<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notification-email-helper.php';

requireUserType('teacher');

header('Content-Type: application/json');

$currentTeacherId = getCurrentUserId();

if (!$currentTeacherId) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'testSubmissions') {
    try {
        
        $testQuery = "
            SELECT COUNT(*) as count
            FROM submission s
            INNER JOIN assignment a ON s.Assignment_ID = a.Assignment_ID
            WHERE a.Teacher_ID = ?
        ";
        $testStmt = $pdo->prepare($testQuery);
        $testStmt->execute([$currentTeacherId]);
        $testResult = $testStmt->fetch(PDO::FETCH_ASSOC);

        $sampleQuery = "
            SELECT s.*, a.Title as Assignment_Title, a.Teacher_ID
            FROM submission s
            INNER JOIN assignment a ON s.Assignment_ID = a.Assignment_ID
            WHERE a.Teacher_ID = ?
            LIMIT 1
        ";
        $sampleStmt = $pdo->prepare($sampleQuery);
        $sampleStmt->execute([$currentTeacherId]);
        $sampleResult = $sampleStmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'total_submissions' => intval($testResult['count'] ?? 0),
            'sample_submission' => $sampleResult,
            'teacher_id' => $currentTeacherId
        ]);
        exit();
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
        exit();
    }
}

if ($action === 'createAssignment') {
    try {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $courseId = intval($_POST['course_id'] ?? 0);
        $classId = intval($_POST['class_id'] ?? 0);
        $dueDate = $_POST['due_date'] ?? '';
        $totalMarks = !empty($_POST['total_marks']) ? floatval($_POST['total_marks']) : null;
        
        if (!$title || !$courseId || !$classId || !$dueDate) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM teacher_class_course
            WHERE Teacher_ID = ? AND Class_ID = ? AND Course_ID = ?
        ");
        $stmt->execute([$currentTeacherId, $classId, $courseId]);
        $relationship = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$relationship || $relationship['count'] == 0) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: You are not assigned to this class/course']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM course_class
            WHERE Course_ID = ? AND Class_ID = ?
        ");
        $stmt->execute([$courseId, $classId]);
        $courseClassRelation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$courseClassRelation || $courseClassRelation['count'] == 0) {
            echo json_encode(['success' => false, 'message' => 'Error: This subject is not assigned to this class']);
            exit();
        }

        $filePath = null;
        $fileName = null;
        if (isset($_FILES['assignment_file']) && $_FILES['assignment_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../uploads/assignments/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileExtension = pathinfo($_FILES['assignment_file']['name'], PATHINFO_EXTENSION);
            $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['assignment_file']['name']);
            $targetPath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['assignment_file']['tmp_name'], $targetPath)) {
                $filePath = 'uploads/assignments/' . $fileName;
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO assignment (Title, Description, Due_Date, Teacher_ID, Class_ID, Course_ID, Total_Marks, Status, Upload_Date)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())
        ");
        $stmt->execute([$title, $description, $dueDate, $currentTeacherId, $classId, $courseId, $totalMarks]);
        
        $assignmentId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("SELECT NameEn, NameAr FROM teacher WHERE Teacher_ID = ?");
        $stmt->execute([$currentTeacherId]);
        $teacherData = $stmt->fetch(PDO::FETCH_ASSOC);
        $teacherName = $teacherData['NameEn'] ?? $teacherData['NameAr'] ?? 'Teacher';

        $notificationResult = notifyClassOfAssignment($pdo, $assignmentId, $classId, $currentTeacherId, $teacherName);

        if (!empty($notificationResult['errors'])) {
            error_log("Assignment notification errors for assignment $assignmentId: " . implode(', ', $notificationResult['errors']));
        }

        echo json_encode([
            'success' => true,
            'message' => 'Assignment created successfully',
            'assignment_id' => $assignmentId
        ]);
        
    } catch (PDOException $e) {
        error_log("Error creating assignment: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} elseif ($action === 'getSubmissions') {
    try {
        
        if (!$currentTeacherId || !is_numeric($currentTeacherId)) {
            echo json_encode(['success' => false, 'message' => 'Invalid teacher ID']);
            exit();
        }

        $classId = isset($_GET['class_id']) && $_GET['class_id'] !== 'all' ? intval($_GET['class_id']) : null;
        $courseId = isset($_GET['course_id']) && $_GET['course_id'] !== 'all' ? intval($_GET['course_id']) : null;
        $assignmentId = isset($_GET['assignment_id']) && $_GET['assignment_id'] !== 'all' ? intval($_GET['assignment_id']) : null;
        $studentName = isset($_GET['student_name']) ? trim($_GET['student_name']) : null;

        if ($classId !== null && $classId <= 0) $classId = null;
        if ($courseId !== null && $courseId <= 0) $courseId = null;
        if ($assignmentId !== null && $assignmentId <= 0) $assignmentId = null;

        $conditions = ["a.Teacher_ID = ?"];
        $params = [$currentTeacherId];
        
        if ($classId) {
            
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count FROM teacher_class_course 
                WHERE Teacher_ID = ? AND Class_ID = ?
            ");
            $stmt->execute([$currentTeacherId, $classId]);
            $classCheck = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$classCheck || $classCheck['count'] == 0) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized: You are not assigned to this class']);
                exit();
            }
            $conditions[] = "a.Class_ID = ?";
            $params[] = $classId;
        }
        
        if ($courseId) {
            
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count FROM teacher_class_course 
                WHERE Teacher_ID = ? AND Course_ID = ?
            ");
            $stmt->execute([$currentTeacherId, $courseId]);
            $courseCheck = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$courseCheck || $courseCheck['count'] == 0) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized: You do not teach this subject']);
                exit();
            }
            $conditions[] = "a.Course_ID = ?";
            $params[] = $courseId;
        }
        
        if ($assignmentId) {
            
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count FROM assignment 
                WHERE Assignment_ID = ? AND Teacher_ID = ?
            ");
            $stmt->execute([$assignmentId, $currentTeacherId]);
            $assignmentCheck = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$assignmentCheck || $assignmentCheck['count'] == 0) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized: You do not own this assignment']);
                exit();
            }
            $conditions[] = "a.Assignment_ID = ?";
            $params[] = $assignmentId;
        }
        
        if ($studentName) {

            $conditions[] = "(COALESCE(st.NameEn, '') LIKE ? OR COALESCE(st.NameAr, '') LIKE ?)";
            $searchTerm = '%' . $studentName . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $debugQuery = "
            SELECT COUNT(*) as total_submissions
            FROM submission s
            INNER JOIN assignment a ON s.Assignment_ID = a.Assignment_ID
            WHERE a.Teacher_ID = ?
        ";
        $debugStmt = $pdo->prepare($debugQuery);
        $debugStmt->execute([$currentTeacherId]);
        $debugResult = $debugStmt->fetch(PDO::FETCH_ASSOC);
        error_log("DEBUG: Total submissions for teacher {$currentTeacherId}: " . ($debugResult['total_submissions'] ?? 0));

        $conditionCount = count($conditions);
        $paramCount = count($params);
        
        if ($conditionCount === 0) {
            throw new Exception("No query conditions specified - security violation");
        }
        
        if ($paramCount === 0) {
            throw new Exception("No query parameters specified");
        }

        $whereClause = implode(' AND ', $conditions);
        if (empty(trim($whereClause))) {
            throw new Exception("WHERE clause is empty - security violation");
        }

        $query = "
            SELECT s.Submission_ID,
                   s.Submission_Date,
                   s.File_Path,
                   s.File_Name,
                   s.Student_ID,
                   s.Assignment_ID,
                   s.Status,
                   s.Grade,
                   s.Feedback,
                   a.Title as Assignment_Title, 
                   a.Total_Marks, 
                   a.Due_Date, 
                   a.Class_ID, 
                   a.Course_ID,
                   a.Teacher_ID,
                   COALESCE(st.Student_Code, '') as Student_Code, 
                   COALESCE(NULLIF(st.NameEn, ''), NULLIF(st.NameAr, ''), 'Unknown') as Student_Name,
                   COALESCE(st.NameAr, '') as Student_NameAr,
                   COALESCE(c.Name, 'Unknown') as Class_Name, 
                   COALESCE(co.Course_Name, 'Unknown') as Course_Name
            FROM submission s
            INNER JOIN assignment a ON s.Assignment_ID = a.Assignment_ID
            INNER JOIN student st ON s.Student_ID = st.Student_ID
            LEFT JOIN class c ON a.Class_ID = c.Class_ID
            LEFT JOIN course co ON a.Course_ID = co.Course_ID
            WHERE " . $whereClause . "
            ORDER BY s.Submission_Date DESC
        ";

        $placeholderCount = substr_count($query, '?');
        if ($placeholderCount !== $paramCount) {
            error_log("WARNING: Placeholder count ({$placeholderCount}) doesn't match param count ({$paramCount})");
        }

        error_log("DEBUG getSubmissions Query: " . $query);
        error_log("DEBUG getSubmissions Params: " . print_r($params, true));
        error_log("DEBUG: Condition count: {$conditionCount}, Param count: {$paramCount}, Placeholder count: {$placeholderCount}");

        error_log("DEBUG: Executing query with {$conditionCount} conditions and {$paramCount} parameters");
        
        try {
            $stmt = $pdo->prepare($query);
        } catch (PDOException $prepError) {
            error_log("DEBUG: Query preparation failed: " . $prepError->getMessage());
            error_log("DEBUG: Query was: " . $query);
            throw new PDOException("Failed to prepare query: " . $prepError->getMessage(), 0, $prepError);
        }
        
        if (!$stmt) {
            $errorInfo = $pdo->errorInfo();
            error_log("DEBUG: Query preparation returned false. Error info: " . print_r($errorInfo, true));
            throw new PDOException("Failed to prepare query: " . ($errorInfo[2] ?? 'Unknown error'));
        }
        
        try {
            $execResult = $stmt->execute($params);
        } catch (PDOException $execError) {
            error_log("DEBUG: Query execution exception: " . $execError->getMessage());
            error_log("DEBUG: Query was: " . $query);
            error_log("DEBUG: Params were: " . print_r($params, true));
            throw $execError;
        }
        
        if (!$execResult) {
            $errorInfo = $stmt->errorInfo();
            error_log("DEBUG: Query execution returned false. Error info: " . print_r($errorInfo, true));
            error_log("DEBUG: Query was: " . $query);
            error_log("DEBUG: Params were: " . print_r($params, true));
            throw new PDOException("Query execution failed: " . ($errorInfo[2] ?? 'Unknown error'));
        }
        
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        error_log("DEBUG: Query returned " . count($submissions) . " submissions");
        if (count($submissions) > 0) {
            error_log("DEBUG: First submission: " . print_r($submissions[0], true));
        }
        
        echo json_encode([
            'success' => true,
            'submissions' => $submissions,
            'debug' => [
                'total_found' => count($submissions),
                'teacher_id' => $currentTeacherId
            ]
        ]);
        
    } catch (PDOException $e) {
        $errorMessage = $e->getMessage();
        $errorCode = $e->getCode();
        $errorInfo = $e->errorInfo ?? [];
        
        error_log("CRITICAL Database Error in getSubmissions: " . $errorMessage);
        error_log("SQL Error Code: " . $errorCode);
        error_log("SQL State: " . ($errorInfo[0] ?? 'N/A'));
        error_log("Full Error Info: " . print_r($errorInfo, true));
        if (isset($query)) {
            error_log("Failed Query: " . $query);
        }
        if (isset($params)) {
            error_log("Failed Query Params: " . print_r($params, true));
        }

        echo json_encode([
            'success' => false, 
            'submissions' => [],
            'message' => 'Database error: ' . $errorMessage,
            'error' => $errorMessage,
            'error_code' => $errorCode,
            'sql_state' => $errorInfo[0] ?? null,
            'sql_error' => $errorInfo[2] ?? null,
            'debug' => [
                'error_code' => $errorCode,
                'error_info' => $errorInfo,
                'query' => $query ?? 'Not set',
                'params_count' => isset($params) ? count($params) : 0
            ]
        ]);
    } catch (Exception $e) {
        error_log("CRITICAL Error in getSubmissions: " . $e->getMessage());
        error_log("Exception trace: " . $e->getTraceAsString());
        
        echo json_encode([
            'success' => false, 
            'submissions' => [],
            'message' => 'Error: ' . $e->getMessage(),
            'error' => $e->getMessage(),
            'error_type' => get_class($e)
        ]);
    }
} elseif ($action === 'getSubmissionDetails') {
    try {
        
        if (!$currentTeacherId || !is_numeric($currentTeacherId)) {
            echo json_encode(['success' => false, 'message' => 'Invalid teacher ID']);
            exit();
        }
        
        $submissionId = intval($_GET['submission_id'] ?? 0);
        
        if (!$submissionId || $submissionId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid submission ID']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT s.Submission_ID,
                   s.Submission_Date,
                   s.File_Path,
                   s.File_Name,
                   s.Student_ID,
                   s.Assignment_ID,
                   s.Status,
                   s.Grade,
                   s.Feedback,
                   a.Title as Assignment_Title, 
                   a.Total_Marks, 
                   a.Due_Date, 
                   a.Teacher_ID, 
                   a.Course_ID,
                   COALESCE(st.Student_Code, '') as Student_Code, 
                   COALESCE(NULLIF(st.NameEn, ''), NULLIF(st.NameAr, ''), 'Unknown') as Student_Name,
                   COALESCE(st.NameAr, '') as Student_NameAr,
                   COALESCE(c.Name, 'Unknown') as Class_Name,
                   COALESCE(co.Course_Name, 'Unknown') as Course_Name
            FROM submission s
            INNER JOIN assignment a ON s.Assignment_ID = a.Assignment_ID
            INNER JOIN student st ON s.Student_ID = st.Student_ID
            LEFT JOIN class c ON a.Class_ID = c.Class_ID
            LEFT JOIN course co ON a.Course_ID = co.Course_ID
            WHERE s.Submission_ID = ? AND a.Teacher_ID = ?
        ");
        
        if (!$stmt) {
            throw new PDOException("Failed to prepare query");
        }
        
        $execResult = $stmt->execute([$submissionId, $currentTeacherId]);
        if (!$execResult) {
            $errorInfo = $stmt->errorInfo();
            throw new PDOException("Query execution failed: " . ($errorInfo[2] ?? 'Unknown error'));
        }
        
        $submission = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$submission) {
            echo json_encode(['success' => false, 'message' => 'Submission not found or unauthorized']);
            exit();
        }
        
        echo json_encode([
            'success' => true,
            'submission' => $submission
        ]);
        
    } catch (PDOException $e) {
        error_log("CRITICAL Database Error in getSubmissionDetails: " . $e->getMessage());
        error_log("SQL Error Code: " . $e->getCode());
        error_log("SQL State: " . ($e->errorInfo[0] ?? 'N/A'));
        echo json_encode([
            'success' => false, 
            'message' => 'Error loading submission details'
        ]);
    } catch (Exception $e) {
        error_log("CRITICAL Error in getSubmissionDetails: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Error loading submission details'
        ]);
    }
} elseif ($action === 'saveGrade') {
    try {
        $submissionId = intval($_POST['submission_id'] ?? 0);

        $gradeInput = $_POST['grade'] ?? null;
        if ($gradeInput !== null && $gradeInput !== '') {
            $grade = floatval($gradeInput);
        } else {
            $grade = null;
        }
        
        $feedback = trim($_POST['feedback'] ?? '');
        
        if (!$submissionId) {
            echo json_encode(['success' => false, 'message' => 'Invalid submission ID']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT a.Teacher_ID, a.Total_Marks
            FROM submission s
            INNER JOIN assignment a ON s.Assignment_ID = a.Assignment_ID
            WHERE s.Submission_ID = ?
        ");
        $stmt->execute([$submissionId]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$assignment || $assignment['Teacher_ID'] != $currentTeacherId) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit();
        }

        if ($grade !== null) {
            
            if ($grade < 0) {
                echo json_encode(['success' => false, 'message' => 'Grade cannot be negative']);
                exit();
            }

            if ($assignment['Total_Marks'] && $grade > $assignment['Total_Marks']) {
                echo json_encode(['success' => false, 'message' => 'Grade cannot exceed total marks']);
                exit();
            }
        }

        $stmt = $pdo->prepare("
            UPDATE submission
            SET Grade = ?, Feedback = ?, Status = 'graded'
            WHERE Submission_ID = ?
        ");
        $stmt->execute([$grade, $feedback, $submissionId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Grade saved successfully'
        ]);
        
    } catch (PDOException $e) {
        error_log("Error saving grade: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} elseif ($action === 'getAssignmentDetails') {
    try {
        $assignmentId = intval($_GET['assignment_id'] ?? 0);
        
        if (!$assignmentId) {
            echo json_encode(['success' => false, 'message' => 'Invalid assignment ID']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT a.*, c.Name as Class_Name, co.Course_Name
            FROM assignment a
            JOIN class c ON a.Class_ID = c.Class_ID
            JOIN course co ON a.Course_ID = co.Course_ID
            WHERE a.Assignment_ID = ? AND a.Teacher_ID = ?
        ");
        $stmt->execute([$assignmentId, $currentTeacherId]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$assignment) {
            echo json_encode(['success' => false, 'message' => 'Assignment not found or unauthorized']);
            exit();
        }
        
        echo json_encode([
            'success' => true,
            'assignment' => $assignment
        ]);
        
    } catch (PDOException $e) {
        error_log("Error fetching assignment details: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} elseif ($action === 'updateAssignment') {
    try {
        $assignmentId = intval($_POST['assignment_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $courseId = intval($_POST['course_id'] ?? 0);
        $classId = intval($_POST['class_id'] ?? 0);
        $dueDate = $_POST['due_date'] ?? '';
        $totalMarks = !empty($_POST['total_marks']) ? floatval($_POST['total_marks']) : null;
        
        if (!$assignmentId || !$title || !$courseId || !$classId || !$dueDate) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT Teacher_ID FROM assignment WHERE Assignment_ID = ?
        ");
        $stmt->execute([$assignmentId]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$assignment || $assignment['Teacher_ID'] != $currentTeacherId) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: You do not own this assignment']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM teacher_class_course
            WHERE Teacher_ID = ? AND Class_ID = ? AND Course_ID = ?
        ");
        $stmt->execute([$currentTeacherId, $classId, $courseId]);
        $relationship = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$relationship || $relationship['count'] == 0) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: You are not assigned to this class/course']);
            exit();
        }

        $stmt = $pdo->prepare("
            UPDATE assignment
            SET Title = ?, Description = ?, Due_Date = ?, Class_ID = ?, Course_ID = ?, Total_Marks = ?
            WHERE Assignment_ID = ? AND Teacher_ID = ?
        ");
        $stmt->execute([$title, $description, $dueDate, $classId, $courseId, $totalMarks, $assignmentId, $currentTeacherId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Assignment updated successfully'
        ]);
        
    } catch (PDOException $e) {
        error_log("Error updating assignment: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} elseif ($action === 'deleteAssignment') {
    try {
        $assignmentId = intval($_POST['assignment_id'] ?? 0);
        
        if (!$assignmentId) {
            echo json_encode(['success' => false, 'message' => 'Invalid assignment ID']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT Teacher_ID, 
                   (SELECT COUNT(*) FROM submission WHERE Assignment_ID = ?) as Submission_Count
            FROM assignment 
            WHERE Assignment_ID = ?
        ");
        $stmt->execute([$assignmentId, $assignmentId]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$assignment || $assignment['Teacher_ID'] != $currentTeacherId) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: You do not own this assignment']);
            exit();
        }

        $stmt = $pdo->prepare("
            UPDATE assignment
            SET Status = 'cancelled'
            WHERE Assignment_ID = ? AND Teacher_ID = ?
        ");
        $stmt->execute([$assignmentId, $currentTeacherId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Assignment deleted successfully (marked as cancelled). Student submissions are preserved.'
        ]);
        
    } catch (PDOException $e) {
        error_log("Error deleting assignment: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>

