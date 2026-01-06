<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('teacher');

header('Content-Type: application/json');

$currentTeacherId = getCurrentUserId();

if (!$currentTeacherId || !is_numeric($currentTeacherId)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'getStudents') {
    try {
        $classId = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
        
        if (!$classId) {
            echo json_encode(['success' => false, 'message' => 'Invalid class ID']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM teacher_class_course 
            WHERE Teacher_ID = ? AND Class_ID = ?
        ");
        $stmt->execute([$currentTeacherId, $classId]);
        $classCheck = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$classCheck || $classCheck['count'] == 0) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: You are not assigned to this class']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT s.Student_ID, s.Student_Code, 
                   COALESCE(NULLIF(s.NameEn, ''), s.NameAr, 'Unknown') as NameEn,
                   s.NameAr
            FROM student s
            WHERE s.Class_ID = ? AND s.Status = 'active'
            ORDER BY COALESCE(NULLIF(s.NameEn, ''), s.NameAr)
        ");
        $stmt->execute([$classId]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'students' => $students
        ]);
        
    } catch (PDOException $e) {
        error_log("Error fetching students: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}

elseif ($action === 'getAllStudents') {
    try {
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT s.Student_ID, s.Student_Code, 
                   COALESCE(NULLIF(s.NameEn, ''), s.NameAr, 'Unknown') as NameEn,
                   s.NameAr,
                   c.Name as Class_Name
            FROM student s
            INNER JOIN class c ON s.Class_ID = c.Class_ID
            INNER JOIN teacher_class_course tcc ON s.Class_ID = tcc.Class_ID
            WHERE tcc.Teacher_ID = ? AND s.Status = 'active'
            ORDER BY c.Grade_Level, c.Section, COALESCE(NULLIF(s.NameEn, ''), s.NameAr)
        ");
        $stmt->execute([$currentTeacherId]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'students' => $students
        ]);
        
    } catch (PDOException $e) {
        error_log("Error fetching all students: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}

elseif ($action === 'getParentsCount') {
    try {
        $studentIds = isset($_GET['student_ids']) ? $_GET['student_ids'] : '';
        
        if (empty($studentIds)) {
            echo json_encode(['success' => true, 'count' => 0]);
            exit();
        }
        
        $studentIdArray = array_filter(array_map('intval', explode(',', $studentIds)));
        
        if (empty($studentIdArray)) {
            echo json_encode(['success' => true, 'count' => 0]);
            exit();
        }

        $placeholders = implode(',', array_fill(0, count($studentIdArray), '?'));
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT s.Student_ID) as count
            FROM student s
            INNER JOIN teacher_class_course tcc ON s.Class_ID = tcc.Class_ID
            WHERE s.Student_ID IN ($placeholders) 
            AND tcc.Teacher_ID = ?
        ");
        $params = array_merge($studentIdArray, [$currentTeacherId]);
        $stmt->execute($params);
        $studentCheck = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$studentCheck || $studentCheck['count'] != count($studentIdArray)) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: Some students are not in your classes']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT psr.Parent_ID) as count
            FROM parent_student_relationship psr
            WHERE psr.Student_ID IN ($placeholders)
        ");
        $stmt->execute($studentIdArray);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'count' => intval($result['count'] ?? 0)
        ]);
        
    } catch (PDOException $e) {
        error_log("Error getting parents count: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}

elseif ($action === 'sendNotification') {
    try {
        $title = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $classIdInput = $_POST['classId'] ?? '';
        $targetType = $_POST['targetType'] ?? 'students';
        $studentIdsJson = $_POST['studentIds'] ?? '[]';

        if (empty($title) || empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Title and message are required']);
            exit();
        }
        
        if (empty($classIdInput)) {
            echo json_encode(['success' => false, 'message' => 'Class is required']);
            exit();
        }
        
        $isAllClasses = ($classIdInput === 'all');
        $classId = $isAllClasses ? null : intval($classIdInput);

        if (!$isAllClasses) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM teacher_class_course 
                WHERE Teacher_ID = ? AND Class_ID = ?
            ");
            $stmt->execute([$currentTeacherId, $classId]);
            $classCheck = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$classCheck || $classCheck['count'] == 0) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized: You are not assigned to this class']);
                exit();
            }
        }
        
        $studentIds = json_decode($studentIdsJson, true);
        if (!is_array($studentIds) || empty($studentIds)) {
            echo json_encode(['success' => false, 'message' => 'Please select at least one student']);
            exit();
        }

        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT s.Student_ID) as count
            FROM student s
            INNER JOIN teacher_class_course tcc ON s.Class_ID = tcc.Class_ID
            WHERE s.Student_ID IN ($placeholders) 
            AND tcc.Teacher_ID = ?
        ");
        $params = array_merge($studentIds, [$currentTeacherId]);
        $stmt->execute($params);
        $studentCheck = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$studentCheck || $studentCheck['count'] != count($studentIds)) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: Some students are not in your classes']);
            exit();
        }
        
        $notificationsCreated = 0;
        $errors = [];

        if ($targetType === 'students' || $targetType === 'both') {
            foreach ($studentIds as $studentId) {
                try {
                    
                    $stmt = $pdo->prepare("SELECT Class_ID FROM student WHERE Student_ID = ?");
                    $stmt->execute([$studentId]);
                    $studentData = $stmt->fetch(PDO::FETCH_ASSOC);
                    $studentClassId = $studentData['Class_ID'] ?? $classId;
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO notification (Title, Content, Date_Sent, Sender_Type, Sender_ID, Target_Role, Target_Class_ID, Target_Student_ID)
                        VALUES (?, ?, NOW(), 'Teacher', ?, 'Student', ?, ?)
                    ");
                    $stmt->execute([$title, $message, $currentTeacherId, $studentClassId, $studentId]);
                    $notificationsCreated++;
                } catch (PDOException $e) {
                    error_log("Error creating student notification: " . $e->getMessage());
                    $errors[] = "Student ID $studentId: " . $e->getMessage();
                }
            }
        }

        if ($targetType === 'parents' || $targetType === 'both') {
            
            $stmt = $pdo->prepare("
                SELECT DISTINCT psr.Parent_ID, s.Class_ID
                FROM parent_student_relationship psr
                INNER JOIN student s ON psr.Student_ID = s.Student_ID
                WHERE psr.Student_ID IN ($placeholders)
            ");
            $stmt->execute($studentIds);
            $parents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $parentClassMap = [];
            foreach ($parents as $parent) {
                $parentId = $parent['Parent_ID'];
                $parentClassId = $parent['Class_ID'];
                if (!isset($parentClassMap[$parentId])) {
                    $parentClassMap[$parentId] = [];
                }
                if (!in_array($parentClassId, $parentClassMap[$parentId])) {
                    $parentClassMap[$parentId][] = $parentClassId;
                }
            }
            
            foreach ($parentClassMap as $parentId => $classIds) {
                foreach ($classIds as $parentClassId) {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO notification (Title, Content, Date_Sent, Sender_Type, Sender_ID, Target_Role, Target_Class_ID, Target_Student_ID)
                            VALUES (?, ?, NOW(), 'Teacher', ?, 'Parent', ?, NULL)
                        ");
                        $stmt->execute([$title, $message, $currentTeacherId, $parentClassId]);
                        $notificationsCreated++;
                    } catch (PDOException $e) {
                        error_log("Error creating parent notification: " . $e->getMessage());
                        $errors[] = "Parent ID $parentId: " . $e->getMessage();
                    }
                }
            }
        }
        
        if ($notificationsCreated > 0) {
            $message = "Notification sent successfully to $notificationsCreated recipient(s)!";
            if (!empty($errors)) {
                $message .= " (Some errors occurred: " . count($errors) . ")";
            }
            echo json_encode([
                'success' => true,
                'message' => $message,
                'notifications_created' => $notificationsCreated,
                'errors' => $errors
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to send notifications. ' . implode(', ', $errors)
            ]);
        }
        
    } catch (PDOException $e) {
        error_log("Error sending notification: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        error_log("Error sending notification: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

