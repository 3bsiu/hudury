<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/activity-logger.php';

requireUserType('admin');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    if ($action === 'addCourse' || $action === 'updateCourse') {
        $courseName = trim($_POST['courseName'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $gradeLevel = !empty($_POST['gradeLevel']) ? intval($_POST['gradeLevel']) : null;
        $courseId = !empty($_POST['courseId']) ? intval($_POST['courseId']) : null;

        if (empty($courseName)) {
            throw new Exception('Course name is required');
        }
        
        if (!$gradeLevel || $gradeLevel <= 0) {
            throw new Exception('Please select a valid grade level');
        }
        
        $isUpdate = ($action === 'updateCourse' && $courseId > 0);

        $pdo->beginTransaction();
        
        try {
            if ($isUpdate) {
                
                $stmt = $pdo->prepare("SELECT Course_ID FROM course WHERE Course_Name = ? AND Grade_Level = ? AND Course_ID != ? LIMIT 1");
                $stmt->execute([$courseName, $gradeLevel, $courseId]);
                if ($stmt->fetch()) {
                    throw new Exception('A course with this name already exists for Grade ' . $gradeLevel);
                }

                $stmt = $pdo->prepare("
                    UPDATE course 
                    SET Course_Name = ?, Description = ?, Grade_Level = ?
                    WHERE Course_ID = ?
                ");
                $stmt->execute([$courseName, $description ?: null, $gradeLevel, $courseId]);

                $stmt = $pdo->prepare("DELETE FROM course_class WHERE Course_ID = ?");
                $stmt->execute([$courseId]);
                
                $message = 'Course updated successfully!';
            } else {
                
                $stmt = $pdo->prepare("SELECT Course_ID FROM course WHERE Course_Name = ? AND Grade_Level = ? LIMIT 1");
                $stmt->execute([$courseName, $gradeLevel]);
                if ($stmt->fetch()) {
                    throw new Exception('A course with this name already exists for Grade ' . $gradeLevel);
                }

                $stmt = $pdo->prepare("
                    INSERT INTO course (Course_Name, Description, Grade_Level, Created_At) 
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([$courseName, $description ?: null, $gradeLevel]);
                $courseId = $pdo->lastInsertId();
                
                $message = 'Course added successfully!';
            }

            $stmt = $pdo->prepare("SELECT Class_ID FROM class WHERE Grade_Level = ?");
            $stmt->execute([$gradeLevel]);
            $classes = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $assignedCount = 0;
            if (!empty($classes)) {
                
                $stmt = $pdo->prepare("INSERT IGNORE INTO course_class (Course_ID, Class_ID) VALUES (?, ?)");
                foreach ($classes as $classId) {
                    $stmt->execute([$courseId, $classId]);
                    $assignedCount++;
                }
            }
            
            $pdo->commit();

            $details = "Grade: {$gradeLevel}, Assigned to {$assignedCount} class(es)";
            logCourseAction($pdo, $isUpdate ? 'update' : 'create', $courseId, $courseName, $details);

            $stmt = $pdo->prepare("
                SELECT c.Course_ID, c.Course_Name, c.Description, c.Grade_Level, c.Created_At,
                       GROUP_CONCAT(cl.Name ORDER BY cl.Section SEPARATOR ', ') as Class_Names,
                       COUNT(cc.Class_ID) as Class_Count
                FROM course c
                LEFT JOIN course_class cc ON c.Course_ID = cc.Course_ID
                LEFT JOIN class cl ON cc.Class_ID = cl.Class_ID
                WHERE c.Course_ID = ?
                GROUP BY c.Course_ID
            ");
            $stmt->execute([$courseId]);
            $courseData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $message .= $assignedCount > 0 
                ? " Automatically assigned to {$assignedCount} class(es)." 
                : " No classes found for Grade {$gradeLevel} yet. Course will be assigned automatically when classes are created.";
            
            echo json_encode([
                'success' => true,
                'message' => $message,
                'action' => $isUpdate ? 'update' : 'add',
                'course' => $courseData
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        
    } elseif ($action === 'getCourse') {
        $courseId = intval($_POST['courseId'] ?? 0);
        if ($courseId <= 0) {
            throw new Exception('Invalid course ID');
        }
        
        $stmt = $pdo->prepare("SELECT Course_ID, Course_Name, Description, Grade_Level FROM course WHERE Course_ID = ?");
        $stmt->execute([$courseId]);
        $course = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$course) {
            throw new Exception('Course not found');
        }
        
        echo json_encode([
            'success' => true,
            'course' => $course
        ]);
        
    } elseif ($action === 'getClassesByGrade') {
        $gradeLevel = intval($_POST['gradeLevel'] ?? 0);
        if ($gradeLevel <= 0) {
            echo json_encode([
                'success' => true,
                'classes' => [],
                'count' => 0
            ]);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT Class_ID, Name, Section, Academic_Year 
            FROM class 
            WHERE Grade_Level = ? 
            ORDER BY Section
        ");
        $stmt->execute([$gradeLevel]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'classes' => $classes,
            'count' => count($classes)
        ]);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

