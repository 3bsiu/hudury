<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('teacher');

header('Content-Type: application/json');

$currentTeacherId = getCurrentUserId();

if (!$currentTeacherId) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'getStudents') {
    try {
        $classId = isset($_GET['class_id']) && $_GET['class_id'] !== 'all' ? intval($_GET['class_id']) : null;
        $sectionId = isset($_GET['section_id']) && $_GET['section_id'] !== 'all' ? trim($_GET['section_id']) : null;

        $conditions = ["tcc.Teacher_ID = ?"];
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
            $conditions[] = "s.Class_ID = ?";
            $params[] = $classId;
        }

        $stmt = $pdo->prepare("
            SELECT DISTINCT c.Class_ID, c.Grade_Level, c.Section, c.Name as Class_Name
            FROM teacher_class_course tcc
            JOIN class c ON tcc.Class_ID = c.Class_ID
            WHERE tcc.Teacher_ID = ?
            ORDER BY c.Grade_Level, c.Section
        ");
        $stmt->execute([$currentTeacherId]);
        $supervisedClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($supervisedClasses)) {
            echo json_encode([
                'success' => true,
                'students' => [],
                'classes' => []
            ]);
            exit();
        }
        
        $classIds = array_column($supervisedClasses, 'Class_ID');
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));

        $studentQuery = "
            SELECT DISTINCT
                s.Student_ID as id,
                s.Student_Code as studentId,
                COALESCE(NULLIF(s.NameEn, ''), NULLIF(s.NameAr, ''), 'Unknown') as name,
                s.NameEn,
                s.NameAr,
                s.Class_ID,
                c.Grade_Level as grade,
                c.Section as section,
                c.Name as className,
                COALESCE(as_status.Status, 'active') as academicStatus
            FROM student s
            INNER JOIN class c ON s.Class_ID = c.Class_ID
            LEFT JOIN academic_status as_status ON s.Student_ID = as_status.Student_ID
            WHERE s.Class_ID IN ($placeholders) AND s.Status = 'active'
        ";
        
        $studentParams = $classIds;
        
        if ($sectionId) {
            $studentQuery .= " AND c.Section = ?";
            $studentParams[] = $sectionId;
        }
        
        $studentQuery .= " ORDER BY c.Grade_Level, c.Section, s.NameEn, s.NameAr";
        
        $stmt = $pdo->prepare($studentQuery);
        $stmt->execute($studentParams);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'students' => $students,
            'classes' => $supervisedClasses
        ]);
        
    } catch (PDOException $e) {
        error_log("Error fetching students: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} elseif ($action === 'getNotes') {
    try {
        $studentId = intval($_GET['student_id'] ?? 0);
        
        if (!$studentId) {
            echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM student s
            INNER JOIN teacher_class_course tcc ON s.Class_ID = tcc.Class_ID
            WHERE s.Student_ID = ? AND tcc.Teacher_ID = ?
        ");
        $stmt->execute([$studentId, $currentTeacherId]);
        $check = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$check || $check['count'] == 0) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: You do not supervise this student']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT 
                asn.id,
                asn.student_id,
                asn.teacher_id,
                asn.class_id,
                asn.section_id,
                asn.note_text,
                asn.behavior_level,
                asn.created_at,
                asn.updated_at,
                COALESCE(NULLIF(t.NameEn, ''), NULLIF(t.NameAr, ''), 'Unknown') as teacher_name,
                c.Name as class_name
            FROM academic_status_notes asn
            JOIN teacher t ON asn.teacher_id = t.Teacher_ID
            JOIN class c ON asn.class_id = c.Class_ID
            WHERE asn.student_id = ?
            ORDER BY asn.created_at DESC
        ");
        $stmt->execute([$studentId]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'notes' => $notes
        ]);
        
    } catch (PDOException $e) {
        error_log("Error fetching notes: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} elseif ($action === 'addNote') {
    try {
        $studentId = intval($_POST['student_id'] ?? 0);
        $classId = intval($_POST['class_id'] ?? 0);
        $sectionId = isset($_POST['section_id']) ? trim($_POST['section_id']) : null;
        $noteText = trim($_POST['note_text'] ?? '');
        $behaviorLevel = trim($_POST['behavior_level'] ?? 'Good');
        
        if (!$studentId || !$classId || !$noteText) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit();
        }

        $validLevels = ['Excellent', 'Good', 'Average', 'Needs Attention'];
        if (!in_array($behaviorLevel, $validLevels)) {
            $behaviorLevel = 'Good';
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM student s
            INNER JOIN teacher_class_course tcc ON s.Class_ID = tcc.Class_ID
            WHERE s.Student_ID = ? AND s.Class_ID = ? AND tcc.Teacher_ID = ?
        ");
        $stmt->execute([$studentId, $classId, $currentTeacherId]);
        $check = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$check || $check['count'] == 0) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: You do not supervise this student']);
            exit();
        }

        $stmt = $pdo->prepare("
            INSERT INTO academic_status_notes 
            (student_id, teacher_id, class_id, section_id, note_text, behavior_level)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$studentId, $currentTeacherId, $classId, $sectionId, $noteText, $behaviorLevel]);
        
        $noteId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("
            SELECT 
                asn.id,
                asn.student_id,
                asn.teacher_id,
                asn.class_id,
                asn.section_id,
                asn.note_text,
                asn.behavior_level,
                asn.created_at,
                asn.updated_at,
                COALESCE(NULLIF(t.NameEn, ''), NULLIF(t.NameAr, ''), 'Unknown') as teacher_name,
                c.Name as class_name
            FROM academic_status_notes asn
            JOIN teacher t ON asn.teacher_id = t.Teacher_ID
            JOIN class c ON asn.class_id = c.Class_ID
            WHERE asn.id = ?
        ");
        $stmt->execute([$noteId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'message' => 'Note added successfully',
            'note' => $note
        ]);
        
    } catch (PDOException $e) {
        error_log("Error adding note: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} elseif ($action === 'updateNote') {
    try {
        $noteId = intval($_POST['note_id'] ?? 0);
        $noteText = trim($_POST['note_text'] ?? '');
        $behaviorLevel = trim($_POST['behavior_level'] ?? 'Good');
        
        if (!$noteId || !$noteText) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit();
        }

        $validLevels = ['Excellent', 'Good', 'Average', 'Needs Attention'];
        if (!in_array($behaviorLevel, $validLevels)) {
            $behaviorLevel = 'Good';
        }

        $stmt = $pdo->prepare("
            SELECT teacher_id FROM academic_status_notes WHERE id = ?
        ");
        $stmt->execute([$noteId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$note) {
            echo json_encode(['success' => false, 'message' => 'Note not found']);
            exit();
        }
        
        if ($note['teacher_id'] != $currentTeacherId) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: You can only edit your own notes']);
            exit();
        }

        $stmt = $pdo->prepare("
            UPDATE academic_status_notes
            SET note_text = ?, behavior_level = ?, updated_at = NOW()
            WHERE id = ? AND teacher_id = ?
        ");
        $stmt->execute([$noteText, $behaviorLevel, $noteId, $currentTeacherId]);

        $stmt = $pdo->prepare("
            SELECT 
                asn.id,
                asn.student_id,
                asn.teacher_id,
                asn.class_id,
                asn.section_id,
                asn.note_text,
                asn.behavior_level,
                asn.created_at,
                asn.updated_at,
                COALESCE(NULLIF(t.NameEn, ''), NULLIF(t.NameAr, ''), 'Unknown') as teacher_name,
                c.Name as class_name
            FROM academic_status_notes asn
            JOIN teacher t ON asn.teacher_id = t.Teacher_ID
            JOIN class c ON asn.class_id = c.Class_ID
            WHERE asn.id = ?
        ");
        $stmt->execute([$noteId]);
        $updatedNote = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'message' => 'Note updated successfully',
            'note' => $updatedNote
        ]);
        
    } catch (PDOException $e) {
        error_log("Error updating note: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} elseif ($action === 'deleteNote') {
    try {
        $noteId = intval($_POST['note_id'] ?? 0);
        
        if (!$noteId) {
            echo json_encode(['success' => false, 'message' => 'Invalid note ID']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT teacher_id, student_id FROM academic_status_notes WHERE id = ?
        ");
        $stmt->execute([$noteId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$note) {
            echo json_encode(['success' => false, 'message' => 'Note not found']);
            exit();
        }
        
        if ($note['teacher_id'] != $currentTeacherId) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: You can only delete your own notes']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM student s
            INNER JOIN teacher_class_course tcc ON s.Class_ID = tcc.Class_ID
            WHERE s.Student_ID = ? AND tcc.Teacher_ID = ?
        ");
        $stmt->execute([$note['student_id'], $currentTeacherId]);
        $check = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$check || $check['count'] == 0) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: You do not supervise this student']);
            exit();
        }

        $stmt = $pdo->prepare("
            DELETE FROM academic_status_notes
            WHERE id = ? AND teacher_id = ?
        ");
        $stmt->execute([$noteId, $currentTeacherId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Note deleted successfully'
        ]);
        
    } catch (PDOException $e) {
        error_log("Error deleting note: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>

