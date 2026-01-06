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
    if ($action === 'addClass' || $action === 'updateClass') {
        $className = trim($_POST['className'] ?? '');
        $gradeLevel = !empty($_POST['gradeLevel']) ? intval($_POST['gradeLevel']) : null;
        $section = !empty($_POST['section']) ? strtoupper(trim($_POST['section'])) : null;
        $academicYear = trim($_POST['academicYear'] ?? '');
        $classId = !empty($_POST['classId']) ? intval($_POST['classId']) : null;

        if (empty($className)) {
            throw new Exception('Class name is required');
        }
        
        if (!$gradeLevel || $gradeLevel <= 0 || $gradeLevel > 12) {
            throw new Exception('Please enter a valid grade level (1-12)');
        }
        
        $isUpdate = ($action === 'updateClass' && $classId > 0);
        
        if ($isUpdate) {
            
            $stmt = $pdo->prepare("SELECT Class_ID FROM class WHERE Name = ? AND Class_ID != ? LIMIT 1");
            $stmt->execute([$className, $classId]);
            if ($stmt->fetch()) {
                throw new Exception('A class with this name already exists');
            }

            $stmt = $pdo->prepare("
                UPDATE class 
                SET Name = ?, Grade_Level = ?, Section = ?, Academic_Year = ?
                WHERE Class_ID = ?
            ");
            $stmt->execute([$className, $gradeLevel, $section, $academicYear ?: null, $classId]);
            
            $message = 'Class updated successfully!';
        } else {
            
            $stmt = $pdo->prepare("SELECT Class_ID FROM class WHERE Name = ? LIMIT 1");
            $stmt->execute([$className]);
            if ($stmt->fetch()) {
                throw new Exception('A class with this name already exists');
            }

            $stmt = $pdo->prepare("
                INSERT INTO class (Name, Grade_Level, Section, Academic_Year, Created_At) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$className, $gradeLevel, $section, $academicYear ?: null]);
            $classId = $pdo->lastInsertId();

            $stmt = $pdo->prepare("SELECT Course_ID FROM course WHERE Grade_Level = ?");
            $stmt->execute([$gradeLevel]);
            $courses = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($courses)) {

                $stmt = $pdo->prepare("INSERT IGNORE INTO course_class (Course_ID, Class_ID) VALUES (?, ?)");
                foreach ($courses as $courseId) {
                    $stmt->execute([$courseId, $classId]);
                }
            }
            
            $message = 'Class added successfully!';
        }

        $details = "Grade: {$gradeLevel}, Section: {$section}, Year: {$academicYear}";
        logClassAction($pdo, $isUpdate ? 'update' : 'create', $classId, $className, $details);

        $stmt = $pdo->prepare("SELECT Class_ID, Name, Grade_Level, Section, Academic_Year, Created_At FROM class WHERE Class_ID = ?");
        $stmt->execute([$classId]);
        $classData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'action' => $isUpdate ? 'update' : 'add',
            'class' => $classData
        ]);
        
    } elseif ($action === 'getClass') {
        $classId = intval($_POST['classId'] ?? 0);
        if ($classId <= 0) {
            throw new Exception('Invalid class ID');
        }
        
        $stmt = $pdo->prepare("SELECT Class_ID, Name, Grade_Level, Section, Academic_Year FROM class WHERE Class_ID = ?");
        $stmt->execute([$classId]);
        $class = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$class) {
            throw new Exception('Class not found');
        }
        
        echo json_encode([
            'success' => true,
            'class' => $class
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

