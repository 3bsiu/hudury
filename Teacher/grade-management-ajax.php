<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('teacher');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$currentTeacherId = getCurrentUserId();

$action = $_POST['action'] ?? '';

try {
    if ($action === 'saveGrades') {
        $gradesJson = $_POST['grades'] ?? '[]';
        $grades = json_decode($gradesJson, true);
        
        if (!is_array($grades) || empty($grades)) {
            throw new Exception('No grades provided');
        }
        
        $pdo->beginTransaction();
        
        try {
            foreach ($grades as $gradeData) {
                $studentId = intval($gradeData['studentId'] ?? 0);
                $courseId = intval($gradeData['courseId'] ?? 0);
                $type = trim($gradeData['type'] ?? '');
                $value = floatval($gradeData['value'] ?? 0);

                if ($studentId <= 0 || $courseId <= 0 || empty($type)) {
                    continue;
                }
                
                if ($value < 0 || $value > 100) {
                    continue;
                }

                $validTypes = ['Midterm', 'Final', 'Assignment', 'Quiz', 'Project'];
                if (!in_array($type, $validTypes)) {
                    continue;
                }

                $stmt = $pdo->prepare("
                    SELECT tcc.Teacher_ID, s.Class_ID
                    FROM teacher_class_course tcc
                    JOIN student s ON tcc.Class_ID = s.Class_ID
                    WHERE tcc.Teacher_ID = ? AND tcc.Course_ID = ? AND s.Student_ID = ?
                    LIMIT 1
                ");
                $stmt->execute([$currentTeacherId, $courseId, $studentId]);
                $access = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$access) {
                    
                    continue;
                }

                $stmt = $pdo->prepare("
                    SELECT Grade_ID FROM grade
                    WHERE Student_ID = ? AND Course_ID = ? AND Type = ? AND Teacher_ID = ?
                    ORDER BY Date_Recorded DESC
                    LIMIT 1
                ");
                $stmt->execute([$studentId, $courseId, $type, $currentTeacherId]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    
                    $stmt = $pdo->prepare("
                        UPDATE grade
                        SET Value = ?, Date_Recorded = CURDATE(), Remarks = 'Updated by teacher'
                        WHERE Grade_ID = ?
                    ");
                    $stmt->execute([$value, $existing['Grade_ID']]);
                } else {
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO grade (Value, Type, Date_Recorded, Student_ID, Course_ID, Teacher_ID, Created_At)
                        VALUES (?, ?, CURDATE(), ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$value, $type, $studentId, $courseId, $currentTeacherId]);
                }
            }
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Grades saved successfully'
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

