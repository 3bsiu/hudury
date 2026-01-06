<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-parent.php';

requireUserType('parent');

function getTimeAgo($datetime) {
    $now = new DateTime();
    $diff = $now->diff($datetime);
    
    if ($diff->y > 0) {
        return $diff->y . ' ' . ($diff->y == 1 ? 'year' : 'years') . ' ago';
    } elseif ($diff->m > 0) {
        return $diff->m . ' ' . ($diff->m == 1 ? 'month' : 'months') . ' ago';
    } elseif ($diff->d > 0) {
        return $diff->d . ' ' . ($diff->d == 1 ? 'day' : 'days') . ' ago';
    } elseif ($diff->h > 0) {
        return $diff->h . ' ' . ($diff->h == 1 ? 'hour' : 'hours') . ' ago';
    } elseif ($diff->i > 0) {
        return $diff->i . ' ' . ($diff->i == 1 ? 'minute' : 'minutes') . ' ago';
    } else {
        return 'Just now';
    }
}

$currentParentId = getCurrentUserId();
$currentParent = getCurrentUserData($pdo);

if (!$currentParentId || !is_numeric($currentParentId)) {
    error_log("Error: Invalid parent ID: " . $currentParentId);
    header("Location: ../homepage.php?error=invalid_parent");
    exit();
}

$parentFullData = null;
$parentName = 'Parent';
$parentNameEn = '';
$parentNameAr = '';
$parentEmail = '';

try {
    $stmt = $pdo->prepare("SELECT Parent_ID, NameEn, NameAr, Email, Phone, Address, Status FROM parent WHERE Parent_ID = ?");
    $stmt->execute([$currentParentId]);
    $parentFullData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($parentFullData) {
        $parentNameEn = $parentFullData['NameEn'] ?? '';
        $parentNameAr = $parentFullData['NameAr'] ?? '';
        $parentName = $parentNameEn ?: $parentNameAr ?: $_SESSION['user_name'] ?? 'Parent';
        $parentEmail = $parentFullData['Email'] ?? $_SESSION['user_email'] ?? '';
    } else {
        error_log("Warning: Parent ID $currentParentId not found in database");
        $parentName = $_SESSION['user_name'] ?? 'Parent';
        $parentEmail = $_SESSION['user_email'] ?? '';
    }
} catch (PDOException $e) {
    error_log("Error fetching parent data: " . $e->getMessage());
    $parentName = $_SESSION['user_name'] ?? 'Parent';
    $parentEmail = $_SESSION['user_email'] ?? '';
}

$linkedStudentIds = [];

$linkedStudentClassIds = [];
$linkedStudentsData = [];
if ($currentParentId) {
    try {
        
        $stmt = $pdo->prepare("
            SELECT psr.Student_ID, psr.Relationship_Type, psr.Is_Primary
            FROM parent_student_relationship psr
            WHERE psr.Parent_ID = ?
            ORDER BY psr.Is_Primary DESC, psr.Created_At ASC
        ");
        $stmt->execute([$currentParentId]);
        $relationships = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $linkedStudentIds = array_column($relationships, 'Student_ID');

        if (!empty($linkedStudentIds)) {
            $placeholders = implode(',', array_fill(0, count($linkedStudentIds), '?'));
            $stmt = $pdo->prepare("
                SELECT s.Student_ID, s.Student_Code, s.NameEn, s.NameAr, s.Class_ID, 
                       c.Grade_Level, c.Section, c.Name as Class_Name,
                       psr.Relationship_Type, psr.Is_Primary
                FROM student s
                LEFT JOIN class c ON s.Class_ID = c.Class_ID
                LEFT JOIN parent_student_relationship psr ON s.Student_ID = psr.Student_ID AND psr.Parent_ID = ?
                WHERE s.Student_ID IN ($placeholders)
                ORDER BY psr.Is_Primary DESC, s.NameEn ASC
            ");
            $params = array_merge([$currentParentId], $linkedStudentIds);
            $stmt->execute($params);
            $linkedStudentsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($linkedStudentsData as $student) {
                if ($student['Class_ID']) {
                    $linkedStudentClassIds[] = $student['Class_ID'];
                }
            }
            $linkedStudentClassIds = array_unique($linkedStudentClassIds);
        }
    } catch (PDOException $e) {
        error_log("Error fetching linked students: " . $e->getMessage());
    }
}

$upcomingExams = [];
if (!empty($linkedStudentClassIds)) {
    try {
        $today = date('Y-m-d');
        error_log("Parent Dashboard: Parent ID=$currentParentId, Linked Class_IDs: " . implode(', ', $linkedStudentClassIds) . ", Fetching exams with Date >= $today");

        $placeholders = implode(',', array_fill(0, count($linkedStudentClassIds), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT Exam_ID) as count FROM exam_class WHERE Class_ID IN ($placeholders)");
        $stmt->execute($linkedStudentClassIds);
        $totalExamsForClasses = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        error_log("Parent Dashboard: Total exams linked to these classes: $totalExamsForClasses");

        $placeholders = implode(',', array_fill(0, count($linkedStudentClassIds), '?'));
        $stmt = $pdo->prepare("
            SELECT e.*, c.Course_Name, GROUP_CONCAT(DISTINCT cl.Name ORDER BY cl.Grade_Level, cl.Section SEPARATOR ', ') as Class_Names
            FROM exam e
            INNER JOIN exam_class ec ON e.Exam_ID = ec.Exam_ID
            LEFT JOIN course c ON e.Course_ID = c.Course_ID
            LEFT JOIN class cl ON ec.Class_ID = cl.Class_ID
            WHERE ec.Class_ID IN ($placeholders) AND e.Exam_Date >= ?
            GROUP BY e.Exam_ID
            ORDER BY e.Exam_Date ASC, e.Exam_Time ASC
            LIMIT 10
        ");
        $params = array_merge($linkedStudentClassIds, [$today]);
        $stmt->execute($params);
        $upcomingExams = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Parent Dashboard: Found " . count($upcomingExams) . " upcoming exams for parent");
        if (count($upcomingExams) > 0) {
            error_log("First exam: " . print_r($upcomingExams[0], true));
        } elseif ($totalExamsForClasses > 0) {
            
            error_log("WARNING: Found $totalExamsForClasses exams for these classes, but none with Exam_Date >= $today");
        }
    } catch (PDOException $e) {
        error_log("Error fetching upcoming exams for parent: " . $e->getMessage());
        error_log("SQL Error Info: " . print_r($e->errorInfo, true));
    }
} else {
    error_log("Parent Dashboard: No linked student class IDs found for parent ID: " . $currentParentId);
    
    if ($currentParentId) {
        try {
            $stmt = $pdo->prepare("
                SELECT psr.Student_ID, s.Class_ID, s.NameEn 
                FROM parent_student_relationship psr
                LEFT JOIN student s ON psr.Student_ID = s.Student_ID
                WHERE psr.Parent_ID = ?
            ");
            $stmt->execute([$currentParentId]);
            $linkedStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("Parent has " . count($linkedStudents) . " linked students: " . print_r($linkedStudents, true));
        } catch (PDOException $e) {
            error_log("Error checking parent-student relationships: " . $e->getMessage());
        }
    }
}

$scheduleData = [];
if (!empty($linkedStudentClassIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($linkedStudentClassIds), '?'));
        $stmt = $pdo->prepare("
            SELECT s.Schedule_ID, s.Day_Of_Week, s.Start_Time, s.End_Time, s.Subject, s.Room,
                   s.Course_ID, s.Teacher_ID, c.Course_Name, t.NameEn as Teacher_Name
            FROM schedule s
            LEFT JOIN course c ON s.Course_ID = c.Course_ID
            LEFT JOIN teacher t ON s.Teacher_ID = t.Teacher_ID
            WHERE s.Class_ID IN ($placeholders) AND s.Type = 'Class'
            ORDER BY 
                FIELD(s.Day_Of_Week, 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'),
                s.Start_Time
        ");
        $stmt->execute($linkedStudentClassIds);
        $scheduleData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching schedule for parent: " . $e->getMessage());
    }
}

$scheduleByDay = [
    'Sunday' => [],
    'Monday' => [],
    'Tuesday' => [],
    'Wednesday' => [],
    'Thursday' => [],
    'Friday' => [],
    'Saturday' => []
];

foreach ($scheduleData as $period) {
    $day = $period['Day_Of_Week'];
    if (isset($scheduleByDay[$day])) {
        $scheduleByDay[$day][] = $period;
    }
}

$notifications = [];
try {
    if ($currentParentId) {
        
        $linkedStudentClassIds = [];
        if (!empty($linkedStudentIds)) {
            $placeholders = implode(',', array_fill(0, count($linkedStudentIds), '?'));
            $stmt = $pdo->prepare("
                SELECT DISTINCT Class_ID 
                FROM student 
                WHERE Student_ID IN ($placeholders) AND Class_ID IS NOT NULL
            ");
            $stmt->execute($linkedStudentIds);
            $linkedStudentClassIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $conditions = ["Target_Role = 'All'"];
        $params = [];

        if (!empty($linkedStudentClassIds)) {
            $classPlaceholders = implode(',', array_fill(0, count($linkedStudentClassIds), '?'));
            $conditions[] = "(Target_Role = 'Parent' AND Target_Class_ID IN ($classPlaceholders))";
            $params = array_merge($params, $linkedStudentClassIds);
        }

        if (!empty($linkedStudentIds)) {
            $studentPlaceholders = implode(',', array_fill(0, count($linkedStudentIds), '?'));
            $conditions[] = "(Target_Role = 'Student' AND Target_Student_ID IN ($studentPlaceholders))";
            $params = array_merge($params, $linkedStudentIds);
        }
        
        if (count($conditions) > 1) {
            $query = "
                SELECT Notif_ID, Title, Content, Date_Sent, Sender_Type, Target_Role, Target_Class_ID, Target_Student_ID
                FROM notification
                WHERE (" . implode(' OR ', $conditions) . ")
                ORDER BY Date_Sent DESC
                LIMIT 20
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching notifications for parents: " . $e->getMessage());
    $notifications = [];
}

$academicStatusData = [];
if (!empty($linkedStudentIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($linkedStudentIds), '?'));

        $stmt = $pdo->prepare("
            SELECT 
                s.Student_ID,
                s.NameEn,
                s.NameAr,
                s.Class_ID,
                c.Name as Class_Name,
                COALESCE(a.Status, 'active') as Status,
                a.Sponsoring_Entity,
                a.Enrollment_Date,
                a.Academic_Year,
                a.Notes,
                a.Updated_At
            FROM student s
            LEFT JOIN class c ON s.Class_ID = c.Class_ID
            LEFT JOIN academic_status a ON s.Student_ID = a.Student_ID
            WHERE s.Student_ID IN ($placeholders)
            ORDER BY s.NameEn ASC
        ");
        $stmt->execute($linkedStudentIds);
        $academicStatusData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($academicStatusData as &$studentData) {
            $studentId = $studentData['Student_ID'];

            try {
                
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as total,
                           SUM(CASE WHEN Status = 'Present' THEN 1 ELSE 0 END) as present,
                           SUM(CASE WHEN Status = 'Excused' THEN 1 ELSE 0 END) as excused
                    FROM attendance
                    WHERE Student_ID = ?
                ");
                $stmt->execute([$studentId]);
                $attendanceStats = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $totalDays = intval($attendanceStats['total'] ?? 0);
                $presentDays = intval($attendanceStats['present'] ?? 0);
                $excusedDays = intval($attendanceStats['excused'] ?? 0);
                $attendedDays = $presentDays + $excusedDays;
                $attendanceRatio = $totalDays > 0 ? round(($attendedDays / $totalDays) * 100, 1) : 0;
                
                $studentData['attendance_total'] = $totalDays;
                $studentData['attendance_present'] = $presentDays;
                $studentData['attendance_excused'] = $excusedDays;
                $studentData['attendance_ratio'] = $attendanceRatio;
            } catch (PDOException $e) {
                error_log("Error calculating attendance for student $studentId: " . $e->getMessage());
                $studentData['attendance_total'] = 0;
                $studentData['attendance_present'] = 0;
                $studentData['attendance_ratio'] = 0;
            }

            try {
                $stmt = $pdo->prepare("
                    SELECT 
                        asn.id,
                        asn.note_text,
                        asn.behavior_level,
                        asn.created_at,
                        COALESCE(NULLIF(t.NameEn, ''), NULLIF(t.NameAr, ''), 'Unknown') as teacher_name,
                        c.Name as class_name
                    FROM academic_status_notes asn
                    JOIN teacher t ON asn.teacher_id = t.Teacher_ID
                    JOIN class c ON asn.class_id = c.Class_ID
                    WHERE asn.student_id = ?
                    ORDER BY asn.created_at DESC
                ");
                $stmt->execute([$studentId]);
                $studentData['academic_notes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log("Error fetching academic notes for student $studentId: " . $e->getMessage());
                $studentData['academic_notes'] = [];
            }
        }
        unset($studentData); 
        
    } catch (PDOException $e) {
        error_log("Error fetching academic status: " . $e->getMessage());
        $academicStatusData = [];
    }
}

$medicalRecordsData = [];
if (!empty($linkedStudentIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($linkedStudentIds), '?'));

        $stmt = $pdo->prepare("
            SELECT 
                mr.*,
                s.Student_ID,
                s.NameEn,
                s.NameAr,
                s.Class_ID,
                c.Name as Class_Name
            FROM medical_record mr
            INNER JOIN student s ON mr.Student_ID = s.Student_ID
            LEFT JOIN class c ON s.Class_ID = c.Class_ID
            WHERE mr.Student_ID IN ($placeholders)
            ORDER BY s.NameEn ASC
        ");
        $stmt->execute($linkedStudentIds);
        $medicalRecordsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Error fetching medical records: " . $e->getMessage());
        $medicalRecordsData = [];
    }
}

$upcomingEvents = [];
$allUpcomingEvents = [];
$totalEventsCount = 0;
try {
    $today = date('Y-m-d');
    
    $stmt = $pdo->prepare("
        SELECT Event_ID, Title, Description, Date, Time, Location, Type, Target_Audience
        FROM event
        WHERE Date >= ?
        AND (LOWER(TRIM(Target_Audience)) = 'all' OR LOWER(TRIM(Target_Audience)) = 'parents')
        ORDER BY Date ASC, Time ASC
        LIMIT 4
    ");
    $stmt->execute([$today]);
    $upcomingEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT Event_ID, Title, Description, Date, Time, Location, Type, Target_Audience
        FROM event
        WHERE Date >= ?
        AND (LOWER(TRIM(Target_Audience)) = 'all' OR LOWER(TRIM(Target_Audience)) = 'parents')
        ORDER BY Date ASC, Time ASC
    ");
    $stmt->execute([$today]);
    $allUpcomingEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalEventsCount = count($allUpcomingEvents);
} catch (PDOException $e) {
    error_log("Error fetching upcoming events for parents: " . $e->getMessage());
    $upcomingEvents = [];
    $allUpcomingEvents = [];
    $totalEventsCount = 0;
}

$quickStats = [
    'overallAverage' => 0,
    'attendanceRate' => 0,
    'pendingAssignments' => 0,
    'newMessages' => 0
];

if (!empty($linkedStudentIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($linkedStudentIds), '?'));
        $stmt = $pdo->prepare("
            SELECT AVG(Value) as average, COUNT(*) as grade_count
            FROM grade
            WHERE Student_ID IN ($placeholders)
        ");
        $stmt->execute($linkedStudentIds);
        $gradeData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($gradeData && $gradeData['grade_count'] > 0 && $gradeData['average'] !== null) {
            $quickStats['overallAverage'] = round(floatval($gradeData['average']), 1);
        }
    } catch (PDOException $e) {
        error_log("Error calculating overall average: " . $e->getMessage());
    }
}

if (!empty($academicStatusData)) {
    $totalAttendanceRatio = 0;
    $studentsWithAttendance = 0;
    foreach ($academicStatusData as $student) {
        if (isset($student['attendance_ratio']) && $student['attendance_total'] > 0) {
            $totalAttendanceRatio += $student['attendance_ratio'];
            $studentsWithAttendance++;
        }
    }
    if ($studentsWithAttendance > 0) {
        $quickStats['attendanceRate'] = round($totalAttendanceRatio / $studentsWithAttendance, 1);
    }
}

if (!empty($linkedStudentIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($linkedStudentIds), '?'));

        $stmt = $pdo->prepare("
            SELECT a.Assignment_ID, s.Student_ID
            FROM assignment a
            INNER JOIN student s ON a.Class_ID = s.Class_ID
            WHERE s.Student_ID IN ($placeholders)
            AND a.Status = 'active'
            AND (a.Due_Date >= CURDATE() OR a.Due_Date IS NULL)
        ");
        $stmt->execute($linkedStudentIds);
        $allAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pendingCount = 0;
        foreach ($allAssignments as $assignment) {
            
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM submission
                WHERE Assignment_ID = ? 
                AND Student_ID = ?
            ");
            $stmt->execute([$assignment['Assignment_ID'], $assignment['Student_ID']]);
            $submissionCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            
            if ($submissionCount == 0) {
                $pendingCount++;
            }
        }
        
        $quickStats['pendingAssignments'] = $pendingCount;
    } catch (PDOException $e) {
        error_log("Error calculating pending assignments: " . $e->getMessage());
    }
}

if ($currentParentId) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM message
            WHERE Receiver_Type = 'parent'
            AND Receiver_ID = ?
            AND Is_Read = 0
        ");
        $stmt->execute([$currentParentId]);
        $messageData = $stmt->fetch(PDO::FETCH_ASSOC);
        $quickStats['newMessages'] = intval($messageData['count'] ?? 0);
    } catch (PDOException $e) {
        error_log("Error calculating new messages: " . $e->getMessage());
    }
}

$feedbackSuccess = false;
$feedbackError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submitFeedback') {
    try {
        
        ini_set('display_errors', 1);
        error_reporting(E_ALL);

        error_log("Feedback submission - POST data: " . print_r($_POST, true));
        
        $message = trim($_POST['feedbackText'] ?? '');
        $category = trim($_POST['feedbackCategory'] ?? '');

        if (empty($message)) {
            throw new Exception('Please enter your feedback message.');
        }
        
        if (empty($category)) {
            throw new Exception('Please select a feedback category.');
        }
        
        $allowedCategories = ['compliment', 'suggestion', 'complaint'];
        if (!in_array($category, $allowedCategories)) {
            throw new Exception('Invalid feedback category.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO anonymous_feedback (Message, Category, Is_Read, Status, Created_At)
            VALUES (?, ?, 0, 'new', NOW())
        ");
        
        $result = $stmt->execute([$message, $category]);
        
        if (!$result) {
            throw new Exception('Failed to save feedback to database.');
        }
        
        $feedbackId = $pdo->lastInsertId();
        error_log("Feedback saved successfully with ID: " . $feedbackId);

        header("Location: parent-dashboard.php?feedback=success&message=" . urlencode('Feedback submitted successfully! Thank you for your input.'));
        exit();
        
    } catch (PDOException $e) {
        error_log("Database error submitting feedback: " . $e->getMessage());
        $feedbackError = 'Database error: ' . $e->getMessage();
        
        header("Location: parent-dashboard.php?feedback=error&message=" . urlencode($feedbackError));
        exit();
    } catch (Exception $e) {
        error_log("Error submitting feedback: " . $e->getMessage());
        $feedbackError = $e->getMessage();
        
        header("Location: parent-dashboard.php?feedback=error&message=" . urlencode($feedbackError));
        exit();
    }
}

if (isset($_GET['feedback'])) {
    if ($_GET['feedback'] === 'success') {
        $feedbackSuccess = true;
        $successMessage = isset($_GET['message']) ? urldecode($_GET['message']) : 'Feedback submitted successfully! Thank you for your input.';
    } elseif ($_GET['feedback'] === 'error') {
        $feedbackError = isset($_GET['message']) ? urldecode($_GET['message']) : 'An error occurred while submitting feedback.';
    }
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Dashboard - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        
        .parent-info-card {
            background: linear-gradient(135deg, #FFE5E5 0%, #E5F3FF 100%);
            margin-bottom: 2rem;
            padding: 2rem;
            border-radius: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        
        .parent-info-content {
            display: flex;
            align-items: flex-start;
            gap: 2rem;
            flex-wrap: wrap;
        }
        
        .parent-info-section {
            flex: 1;
            min-width: 250px;
        }
        
        .parent-name-display {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        
        .parent-name-value {
            font-family: 'Fredoka', sans-serif;
            font-size: 2rem;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }
        
        .parent-email {
            color: #666;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .students-list-container {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .student-card {
            padding: 1rem;
            background: rgba(255,255,255,0.8);
            border-radius: 15px;
            border: 2px solid rgba(255,107,157,0.3);
            transition: all 0.3s;
        }
        
        .student-card:hover {
            border-color: #FF6B9D;
            transform: translateX(5px);
            box-shadow: 0 4px 15px rgba(255,107,157,0.2);
        }
        
        .student-card-content {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .student-avatar-large {
            font-size: 2rem;
            flex-shrink: 0;
        }
        
        .student-info {
            flex: 1;
        }
        
        .student-name {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--text-dark);
            margin-bottom: 0.3rem;
        }
        
        .student-class {
            font-size: 0.85rem;
            color: #666;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        
        .student-relationship {
            font-size: 0.8rem;
            color: #999;
            margin-top: 0.3rem;
        }
        
        .primary-badge {
            background: #6BCB77;
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 10px;
            font-size: 0.75rem;
            margin-left: 0.5rem;
        }
        
        .no-students-message {
            padding: 1rem;
            background: rgba(255,255,255,0.7);
            border-radius: 15px;
            border: 2px dashed #FF6B9D;
        }
        
        .no-students-icon {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #FF6B9D;
            margin-bottom: 0.5rem;
        }
        
        .no-students-help {
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.5rem;
        }

        .student-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            border-bottom: 2px solid #FFE5E5;
            padding-bottom: 1rem;
        }
        
        .student-tab-btn {
            padding: 0.75rem 1.5rem;
            background: #FFF9F5;
            border: 2px solid #FFE5E5;
            border-radius: 15px;
            cursor: pointer;
            font-weight: 600;
            color: #666;
            transition: all 0.3s;
            font-size: 0.9rem;
        }
        
        .student-tab-btn:hover {
            background: #FFE5E5;
            transform: translateY(-2px);
        }
        
        .student-tab-btn.active {
            background: linear-gradient(135deg, #FF6B9D, #6BCB77);
            color: white;
            border-color: transparent;
        }
        
        .student-academic-status {
            animation: fadeIn 0.3s;
        }
        
        .academic-status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .status-item {
            padding: 1rem;
            background: #FFF9F5;
            border-radius: 15px;
            border: 2px solid #FFE5E5;
        }
        
        .status-label {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        
        .status-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-dark);
        }
        
        .status-progress {
            height: 8px;
            background: #FFE5E5;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 0.5rem;
        }
        
        .status-progress-bar {
            height: 100%;
            border-radius: 10px;
            transition: width 0.5s;
        }
        
        .status-notes {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 2px solid #FFE5E5;
        }
        
        .note-item {
            padding: 1rem;
            background: #FFF9F5;
            border-radius: 10px;
            margin-bottom: 0.75rem;
            border-left: 4px solid #FF6B9D;
        }
        
        .note-date {
            font-size: 0.8rem;
            color: #999;
            margin-bottom: 0.5rem;
        }
        
        .note-content {
            color: #666;
            line-height: 1.6;
        }

        .academic-notes-section {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 2px solid #FFE5E5;
        }
        
        .academic-notes-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .academic-note-card {
            background: #FFF9F5;
            border-radius: 10px;
            padding: 1rem;
            border-left: 4px solid #FF6B9D;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .academic-note-card:hover {
            transform: translateX(3px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        @media (max-width: 768px) {
            .academic-note-card {
                padding: 0.75rem;
            }
            
            .academic-notes-list {
                gap: 0.75rem;
            }
        }

        .medical-record-summary {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .medical-record-item {
            padding: 1rem;
            background: #FFF9F3;
            border-radius: 10px;
            border-left: 3px solid #FF6B9D;
        }

        .grades-table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: -1rem;
            padding: 1rem;
        }
        
        .grades-table-wrapper::-webkit-scrollbar {
            height: 8px;
        }
        
        .grades-table-wrapper::-webkit-scrollbar-track {
            background: #FFE5E5;
            border-radius: 10px;
        }
        
        .grades-table-wrapper::-webkit-scrollbar-thumb {
            background: #FF6B9D;
            border-radius: 10px;
        }
        
        .grades-table-wrapper::-webkit-scrollbar-thumb:hover {
            background: #C44569;
        }

        @media (max-width: 1024px) {
            .main-layout-container {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
            
            .content-grid {
                grid-template-columns: 1fr !important;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 1rem;
            }
            
            .parent-info-card {
                padding: 1.5rem;
                margin-bottom: 1.5rem;
            }
            
            .parent-info-content {
                flex-direction: column;
                gap: 1.5rem;
            }
            
            .parent-info-section {
                min-width: 100%;
            }
            
            .parent-name-value {
                font-size: 1.5rem;
            }
            
            .student-card {
                padding: 0.75rem;
            }
            
            .student-name {
                font-size: 1rem;
            }
            
            .academic-status-grid {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
            
            .status-item {
                padding: 0.75rem;
            }
            
            .student-tabs {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .student-tab-btn {
                width: 100%;
                text-align: center;
                padding: 0.5rem 1rem;
                font-size: 0.85rem;
            }
            
            .medical-record-summary {
                gap: 0.75rem;
            }
            
            .medical-record-item {
                padding: 0.75rem;
            }
            
            .card {
                padding: 1.5rem;
                margin-bottom: 1.5rem;
            }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .card-title {
                font-size: 1.4rem;
            }
            
            .welcome-section {
                padding: 2rem 1.5rem;
            }
            
            .welcome-section h1 {
                font-size: 1.8rem;
            }
            
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
            
            .stat-card {
                padding: 1.5rem;
            }
            
            .stat-value {
                font-size: 1.8rem;
            }

            .grades-table-wrapper {
                margin: -1.5rem;
                padding: 1.5rem;
            }
            
            .grades-table {
                font-size: 0.8rem;
                min-width: 700px;
            }
            
            .grades-table th,
            .grades-table td {
                padding: 0.6rem 0.4rem;
                font-size: 0.75rem;
            }
            
            .grades-table th {
                white-space: nowrap;
            }
            
            .grade-cell {
                font-size: 0.9rem;
            }
            
            .total-grade {
                font-size: 1rem;
            }

            .schedule-grid {
                grid-template-columns: 1fr !important;
                gap: 1rem;
            }
            
            .schedule-day {
                padding: 1rem;
            }
            
            .schedule-day-name {
                font-size: 1rem;
                padding: 0.75rem;
            }
            
            .schedule-period {
                padding: 0.75rem;
                font-size: 0.85rem;
            }

            .exam-dates-list {
                gap: 1rem;
            }
            
            .exam-date-item {
                flex-direction: column;
                padding: 1rem;
                gap: 0.75rem;
            }
            
            .exam-date-info {
                width: 100%;
            }
            
            .exam-date-badge {
                margin-top: 0.75rem;
                margin-left: 0;
                align-self: flex-start;
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
            }
            
            .exam-date-title {
                font-size: 1rem;
            }
            
            .exam-date-subject {
                font-size: 0.85rem;
            }

            .progress-item {
                padding: 0.75rem 0;
            }
            
            .progress-label {
                font-size: 0.9rem;
            }

            .event-item {
                padding: 0.75rem;
            }
        }
        
        @media (max-width: 480px) {
            .dashboard-container {
                padding: 0.75rem;
            }
            
            .parent-info-card {
                padding: 1rem;
                border-radius: 20px;
            }
            
            .parent-name-value {
                font-size: 1.3rem;
            }
            
            .card {
                padding: 1rem;
                border-radius: 20px;
            }
            
            .card-title {
                font-size: 1.2rem;
            }
            
            .quick-stats {
                grid-template-columns: 1fr;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .welcome-section {
                padding: 1.5rem 1rem;
            }
            
            .welcome-section h1 {
                font-size: 1.5rem;
            }
            
            .grades-table {
                min-width: 600px;
                font-size: 0.7rem;
            }
            
            .grades-table th,
            .grades-table td {
                padding: 0.5rem 0.3rem;
                font-size: 0.7rem;
            }
            
            .grade-cell {
                font-size: 0.8rem;
            }
            
            .status-item {
                padding: 0.5rem;
            }
            
            .status-value {
                font-size: 1rem;
            }
            
            .schedule-day-name {
                font-size: 0.9rem;
            }
            
            .schedule-period {
                font-size: 0.8rem;
                padding: 0.5rem;
            }
            
            .exam-date-item {
                padding: 0.75rem;
            }
            
            .card-header h2 {
                font-size: 1.1rem;
            }
        }

        @media (max-width: 768px) {
            .btn, button {
                min-height: 44px; 
                padding: 0.75rem 1rem;
            }
            
            .student-tab-btn {
                min-height: 44px;
            }

            .card {
                margin-bottom: 1.5rem;
            }

            p, span, div {
                line-height: 1.6;
            }

            .grades-table-wrapper {
                position: relative;
            }
            
            .grades-table-wrapper::after {
                content: '← Scroll →';
                position: absolute;
                bottom: 5px;
                right: 10px;
                font-size: 0.7rem;
                color: #999;
                pointer-events: none;
            }
        }
    </style>
    
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>
    
    <div class="main-content">

    <div class="dashboard-container">
        
        <div class="card parent-info-card">
            <div class="parent-info-content">
                <div class="parent-info-section">
                    <div class="parent-name-display" data-en="Parent Account" data-ar="حساب ولي الأمر">Parent Account</div>
                    <h2 class="parent-name-value">
                        <?php echo htmlspecialchars($parentName); ?>
                    </h2>
                    <?php if ($parentEmail): ?>
                        <div class="parent-email">
                            <i class="fas fa-envelope"></i>
                            <?php echo htmlspecialchars($parentEmail); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="parent-info-section">
                    <div class="parent-name-display" data-en="Responsible for:" data-ar="مسؤول عن:">Responsible for:</div>
                    <?php if (empty($linkedStudentsData)): ?>
                        <div class="no-students-message">
                            <div class="no-students-icon">
                                <i class="fas fa-info-circle"></i>
                                <span data-en="No students linked to this account." data-ar="لا يوجد طلاب مرتبطين بهذا الحساب.">No students linked to this account.</span>
                            </div>
                            <div class="no-students-help" data-en="Please contact the administrator to link your child(ren) to your account." data-ar="يرجى الاتصال بالإدارة لربط طفلك (أطفالك) بحسابك.">
                                Please contact the administrator to link your child(ren) to your account.
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="students-list-container">
                            <?php foreach ($linkedStudentsData as $student): ?>
                                <div class="student-card">
                                    <div class="student-card-content">
                                        <div class="student-avatar-large">
                                            <?php echo (strpos($student['NameEn'], ' ') !== false && strtolower(substr($student['NameEn'], 0, 1)) === 's') ? '👧' : '👦'; ?>
                                        </div>
                                        <div class="student-info">
                                            <div class="student-name">
                                                <?php echo htmlspecialchars($student['NameEn']); ?>
                                            </div>
                                            <?php if ($student['Class_Name']): ?>
                                                <div class="student-class">
                                                    <i class="fas fa-graduation-cap"></i>
                                                    <?php echo htmlspecialchars($student['Class_Name']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($student['Relationship_Type']): ?>
                                                <div class="student-relationship">
                                                    <?php
                                                    $relationshipLabels = [
                                                        'father' => ['en' => 'Father', 'ar' => 'أب'],
                                                        'mother' => ['en' => 'Mother', 'ar' => 'أم'],
                                                        'brother' => ['en' => 'Brother', 'ar' => 'أخ'],
                                                        'sister' => ['en' => 'Sister', 'ar' => 'أخت'],
                                                        'uncle' => ['en' => 'Uncle', 'ar' => 'عم'],
                                                        'aunt' => ['en' => 'Aunt', 'ar' => 'عمة'],
                                                        'grandfather' => ['en' => 'Grandfather', 'ar' => 'جد'],
                                                        'grandmother' => ['en' => 'Grandmother', 'ar' => 'جدة'],
                                                        'guardian' => ['en' => 'Guardian', 'ar' => 'وصي'],
                                                        'other' => ['en' => 'Other', 'ar' => 'آخر']
                                                    ];
                                                    $relType = $relationshipLabels[$student['Relationship_Type']] ?? ['en' => ucfirst($student['Relationship_Type']), 'ar' => $student['Relationship_Type']];
                                                    ?>
                                                    <span data-en="<?php echo $relType['en']; ?>" data-ar="<?php echo $relType['ar']; ?>"><?php echo $relType['en']; ?></span>
                                                    <?php if ($student['Is_Primary']): ?>
                                                        <span class="primary-badge" data-en="Primary" data-ar="رئيسي">Primary</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="welcome-section">
            <h1 data-en="Welcome to Your Parent Dashboard! 👋" data-ar="مرحباً بك في لوحة تحكم ولي الأمر! 👋">Welcome to Your Parent Dashboard! 👋</h1>
            <p data-en="Stay connected with your child's academic journey. Monitor attendance, grades, and communicate with teachers." data-ar="ابق على اتصال مع رحلة طفلك الأكاديمية. راقب الحضور والدرجات وتواصل مع المعلمين.">Stay connected with your child's academic journey. Monitor attendance, grades, and communicate with teachers.</p>
        </div>

        <div class="quick-stats">
            <div class="stat-card">
                <div class="stat-icon">📊</div>
                <div class="stat-value"><?php echo $quickStats['overallAverage'] > 0 ? $quickStats['overallAverage'] . '%' : 'N/A'; ?></div>
                <div class="stat-label" data-en="Overall Average" data-ar="المعدل العام">Overall Average</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">✅</div>
                <div class="stat-value"><?php echo $quickStats['attendanceRate'] > 0 ? $quickStats['attendanceRate'] . '%' : 'N/A'; ?></div>
                <div class="stat-label" data-en="Attendance Rate" data-ar="معدل الحضور">Attendance Rate</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📝</div>
                <div class="stat-value"><?php echo $quickStats['pendingAssignments']; ?></div>
                <div class="stat-label" data-en="Pending Assignments" data-ar="الواجبات المعلقة">Pending Assignments</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">💬</div>
                <div class="stat-value"><?php echo $quickStats['newMessages']; ?></div>
                <div class="stat-label" data-en="New Messages" data-ar="رسائل جديدة">New Messages</div>
            </div>
        </div>

        <div class="main-layout-container">
            
            <div class="main-content-area">
                
                <div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📋</span>
                            <span data-en="Academic Status" data-ar="الحالة الأكاديمية">Academic Status</span>
                        </h2>
                    </div>
                    
                    <?php if (empty($academicStatusData)): ?>
                        
                        <div style="text-align: center; padding: 3rem; color: #666;">
                            <div style="font-size: 3rem; margin-bottom: 1rem;">📚</div>
                            <div style="font-size: 1.2rem; font-weight: 700; margin-bottom: 0.5rem;" data-en="No Academic Data Available" data-ar="لا توجد بيانات أكاديمية متاحة">No Academic Data Available</div>
                            <div style="font-size: 0.9rem;" data-en="Academic status information will appear here once available." data-ar="ستظهر معلومات الحالة الأكاديمية هنا بمجرد توفرها.">
                                Academic status information will appear here once available.
                            </div>
                        </div>
                    <?php else: ?>
                        
                        <?php if (count($academicStatusData) > 1): ?>
                            <div class="student-tabs">
                                <?php foreach ($academicStatusData as $index => $student): ?>
                                    <button class="student-tab-btn <?php echo $index === 0 ? 'active' : ''; ?>" 
                                            onclick="switchStudentTab(<?php echo $index; ?>)"
                                            data-student-id="<?php echo $student['Student_ID']; ?>">
                                        <?php echo htmlspecialchars($student['NameEn']); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php foreach ($academicStatusData as $index => $student): ?>
                            <div class="student-academic-status <?php echo $index === 0 ? 'active' : ''; ?>" 
                                 data-student-id="<?php echo $student['Student_ID']; ?>"
                                 style="<?php echo $index > 0 ? 'display: none;' : ''; ?>">
                                
                                <?php if (count($academicStatusData) > 1): ?>
                                    <div style="margin-bottom: 1rem; padding: 0.75rem; background: #FFF9F5; border-radius: 10px; border-left: 4px solid #FF6B9D;">
                                        <div style="font-weight: 700; color: var(--text-dark);">
                                            <?php echo htmlspecialchars($student['NameEn']); ?>
                                        </div>
                                        <?php if ($student['Class_Name']): ?>
                                            <div style="font-size: 0.85rem; color: #666; margin-top: 0.3rem;">
                                                <i class="fas fa-graduation-cap" style="margin-right: 0.3rem;"></i>
                                                <?php echo htmlspecialchars($student['Class_Name']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="academic-status-grid">
                                    <div class="status-item">
                                        <div class="status-label" data-en="Student Status" data-ar="حالة الطالب">Student Status</div>
                                        <?php
                                        $status = strtolower($student['Status'] ?? 'active');
                                        $statusLabels = [
                                            'active' => ['en' => 'Active', 'ar' => 'نشط', 'color' => '#6BCB77'],
                                            'inactive' => ['en' => 'Inactive', 'ar' => 'غير نشط', 'color' => '#FFD93D'],
                                            'graduated' => ['en' => 'Graduated', 'ar' => 'متخرج', 'color' => '#4A90E2'],
                                            'transferred' => ['en' => 'Transferred', 'ar' => 'منقول', 'color' => '#9B59B6'],
                                            'suspended' => ['en' => 'Suspended', 'ar' => 'معلق', 'color' => '#FF6B9D']
                                        ];
                                        $statusInfo = $statusLabels[$status] ?? $statusLabels['active'];
                                        ?>
                                        <div class="status-value" style="color: <?php echo $statusInfo['color']; ?>; font-weight: 700;">
                                            <span data-en="<?php echo $statusInfo['en']; ?>" data-ar="<?php echo $statusInfo['ar']; ?>"><?php echo $statusInfo['en']; ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="status-item">
                                        <div class="status-label" data-en="Attendance Ratio" data-ar="نسبة الحضور">Attendance Ratio</div>
                                        <?php
                                        $attendanceTotal = $student['attendance_total'] ?? 0;
                                        $attendancePresent = $student['attendance_present'] ?? 0;
                                        $attendanceExcused = $student['attendance_excused'] ?? 0;
                                        $attendedTotal = $attendancePresent + $attendanceExcused;
                                        $attendanceRatio = $student['attendance_ratio'] ?? 0;
                                        ?>
                                        <?php if ($attendanceTotal > 0): ?>
                                            <div class="status-value">
                                                <?php echo $attendedTotal; ?> 
                                                <span style="font-size: 0.8em; color: #666;" data-en="out of" data-ar="من">out of</span> 
                                                <?php echo $attendanceTotal; ?>
                                                <?php if ($attendanceExcused > 0): ?>
                                                    <span style="font-size: 0.75em; color: #999; margin-left: 0.3rem;">
                                                        (<span data-en="<?php echo $attendancePresent; ?> present, <?php echo $attendanceExcused; ?> excused" data-ar="<?php echo $attendancePresent; ?> حاضر، <?php echo $attendanceExcused; ?> معذور"><?php echo $attendancePresent; ?> present, <?php echo $attendanceExcused; ?> excused</span>)
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="status-progress">
                                                <div class="status-progress-bar" style="width: <?php echo min(100, $attendanceRatio); ?>%; background: <?php echo $attendanceRatio >= 80 ? 'linear-gradient(90deg, #6BCB77, #4A90E2)' : ($attendanceRatio >= 60 ? 'linear-gradient(90deg, #FFD93D, #FFC107)' : 'linear-gradient(90deg, #FF6B9D, #C44569)'); ?>;"></div>
                                            </div>
                                            <div style="font-size: 0.85rem; color: #666; margin-top: 0.3rem;">
                                                <?php echo $attendanceRatio; ?>% 
                                                <span data-en="attendance rate" data-ar="معدل الحضور">attendance rate</span>
                                            </div>
                                        <?php else: ?>
                                            <div class="status-value" style="color: #999;">
                                                <span data-en="No attendance records yet" data-ar="لا توجد سجلات حضور بعد">No attendance records yet</span>
                                            </div>
                                            <div class="status-progress">
                                                <div class="status-progress-bar" style="width: 0%;"></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="status-item">
                                        <div class="status-label" data-en="Funding Source" data-ar="مصدر التمويل">Funding Source</div>
                                        <div class="status-value status-funding">
                                            <?php 
                                            $sponsoringEntity = $student['Sponsoring_Entity'] ?? 'Parents';
                                            $fundingLabels = [
                                                'Parents' => ['en' => 'Parents', 'ar' => 'الوالدين'],
                                                'Scholarship' => ['en' => 'Scholarship', 'ar' => 'منحة'],
                                                'Sponsor' => ['en' => 'Sponsor', 'ar' => 'راعي'],
                                                'Government' => ['en' => 'Government', 'ar' => 'حكومي']
                                            ];
                                            $fundingLabel = $fundingLabels[$sponsoringEntity] ?? ['en' => $sponsoringEntity, 'ar' => $sponsoringEntity];
                                            ?>
                                            <span data-en="<?php echo $fundingLabel['en']; ?>" data-ar="<?php echo $fundingLabel['ar']; ?>"><?php echo $fundingLabel['en']; ?></span>
                                        </div>
                                    </div>
                                    
                                    <?php if ($student['Enrollment_Date']): ?>
                                    <div class="status-item">
                                        <div class="status-label" data-en="Enrollment Date" data-ar="تاريخ التسجيل">Enrollment Date</div>
                                        <div class="status-value" style="font-size: 0.9rem;">
                                            <?php echo date('M d, Y', strtotime($student['Enrollment_Date'])); ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($student['Academic_Year']): ?>
                                    <div class="status-item">
                                        <div class="status-label" data-en="Academic Year" data-ar="السنة الأكاديمية">Academic Year</div>
                                        <div class="status-value" style="font-size: 0.9rem;">
                                            <?php echo htmlspecialchars($student['Academic_Year']); ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($student['Notes']): ?>
                                <div class="status-notes">
                                    <div class="status-label" style="margin-bottom: 1rem;" data-en="Notes" data-ar="ملاحظات">Notes</div>
                                    <div class="note-item">
                                        <?php if ($student['Updated_At']): ?>
                                            <div class="note-date"><?php echo date('M d, Y', strtotime($student['Updated_At'])); ?></div>
                                        <?php endif; ?>
                                        <div class="note-content"><?php echo nl2br(htmlspecialchars($student['Notes'])); ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <div class="academic-notes-section" style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 2px solid #FFE5E5;">
                                    <button class="btn btn-secondary" onclick="toggleAcademicNotes(<?php echo $student['Student_ID']; ?>)" 
                                            id="showMoreBtn_<?php echo $student['Student_ID']; ?>"
                                            style="width: 100%; margin-bottom: 1rem;">
                                        <i class="fas fa-chevron-down" id="chevron_<?php echo $student['Student_ID']; ?>"></i>
                                        <span data-en="Show More" data-ar="عرض المزيد">Show More</span>
                                    </button>
                                    
                                    <div id="academicNotes_<?php echo $student['Student_ID']; ?>" style="display: none;">
                                        <?php 
                                        $notes = $student['academic_notes'] ?? [];
                                        if (empty($notes)): 
                                        ?>
                                            <div style="text-align: center; padding: 2rem; color: #999;">
                                                <div style="font-size: 0.9rem;" data-en="No academic notes yet" data-ar="لا توجد ملاحظات أكاديمية بعد">No academic notes yet</div>
                                            </div>
                                        <?php else: ?>
                                            <div class="academic-notes-list" style="display: flex; flex-direction: column; gap: 1rem;">
                                                <?php foreach ($notes as $note): ?>
                                                    <div class="academic-note-card" style="background: #FFF9F5; border-radius: 10px; padding: 1rem; border-left: 4px solid #FF6B9D;">
                                                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
                                                            <div style="flex: 1; min-width: 200px;">
                                                                <div style="font-weight: 700; color: var(--text-dark); margin-bottom: 0.3rem;">
                                                                    <i class="fas fa-user" style="margin-right: 0.5rem; color: #FF6B9D;"></i>
                                                                    <?php echo htmlspecialchars($note['teacher_name']); ?>
                                                                </div>
                                                                <div style="font-size: 0.85rem; color: #666;">
                                                                    <span style="color: <?php 
                                                                        $colors = ['Excellent' => '#6BCB77', 'Good' => '#4A90E2', 'Average' => '#FFD93D', 'Needs Attention' => '#FF6B9D'];
                                                                        echo $colors[$note['behavior_level']] ?? '#666';
                                                                    ?>; font-weight: 600;">
                                                                        <?php echo htmlspecialchars($note['behavior_level']); ?>
                                                                    </span>
                                                                    <?php if ($note['class_name']): ?>
                                                                        <span style="margin-left: 0.5rem;">• <?php echo htmlspecialchars($note['class_name']); ?></span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                            <?php if ($note['created_at']): ?>
                                                                <div style="font-size: 0.8rem; color: #999; white-space: nowrap;">
                                                                    <?php echo date('M d, Y', strtotime($note['created_at'])); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div style="color: #333; line-height: 1.6; white-space: pre-wrap; margin-top: 0.5rem;">
                                                            <?php echo htmlspecialchars($note['note_text']); ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📅</span>
                            <span data-en="Class Schedule" data-ar="جدول الحصص">Class Schedule</span>
                        </h2>
                    </div>
                    <div class="schedule-grid">
                        <?php
                        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday'];
                        $daysAr = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس'];
                        foreach ($days as $dayIndex => $day):
                        ?>
                            <div class="schedule-day">
                                <div class="schedule-day-name" data-en="<?php echo $day; ?>" data-ar="<?php echo $daysAr[$dayIndex]; ?>"><?php echo $day; ?></div>
                                <?php if (isset($scheduleByDay[$day]) && !empty($scheduleByDay[$day])): ?>
                                    <?php foreach ($scheduleByDay[$day] as $period): ?>
                                        <div class="schedule-period">
                                            <div class="schedule-time">
                                                <?php echo date('g:i A', strtotime($period['Start_Time'])); ?> - 
                                                <?php echo date('g:i A', strtotime($period['End_Time'])); ?>
                                            </div>
                                            <div style="font-weight: 700;"><?php echo htmlspecialchars($period['Subject']); ?></div>
                                            <?php if ($period['Room'] || $period['Teacher_Name']): ?>
                                                <div style="font-size: 0.85rem; color: #666; margin-top: 0.3rem;">
                                                    <?php if ($period['Room']): ?>
                                                        <?php echo htmlspecialchars($period['Room']); ?>
                                                        <?php if ($period['Teacher_Name']): ?> - <?php endif; ?>
                                                    <?php endif; ?>
                                                    <?php if ($period['Teacher_Name']): ?>
                                                        <?php echo htmlspecialchars($period['Teacher_Name']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="text-align: center; padding: 1rem; color: #999; font-size: 0.9rem;" data-en="No classes scheduled" data-ar="لا توجد حصص مجدولة">No classes scheduled</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📋</span>
                            <span data-en="Upcoming Exam Dates" data-ar="مواعيد الامتحانات القادمة">Upcoming Exam Dates</span>
                        </h2>
                    </div>
                    <div class="exam-dates-list">
                        <?php if (empty($upcomingExams)): ?>
                            <div style="text-align: center; padding: 2rem; color: #666;">
                                <div style="font-size: 2rem; margin-bottom: 0.5rem;">📋</div>
                                <div data-en="No upcoming exams" data-ar="لا توجد امتحانات قادمة">No upcoming exams</div>
                                <?php if (empty($linkedStudentClassIds)): ?>
                                    <div style="font-size: 0.85rem; color: #FF6B9D; margin-top: 0.5rem;" data-en="Note: Your children are not assigned to classes yet." data-ar="ملاحظة: لم يتم تعيين أطفالك إلى فصول بعد.">
                                        Note: Your children are not assigned to classes yet.
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php foreach ($upcomingExams as $exam): ?>
                                <?php
                                $examDate = new DateTime($exam['Exam_Date']);
                                $formattedDate = $examDate->format('M d, Y');
                                $formattedTime = date('g:i A', strtotime($exam['Exam_Time']));
                                $subject = $exam['Course_Name'] ?? $exam['Subject'];
                                ?>
                                <div class="exam-date-item">
                                    <div class="exam-date-info">
                                        <div class="exam-date-title"><?php echo htmlspecialchars($exam['Title']); ?></div>
                                        <div class="exam-date-subject"><?php echo htmlspecialchars($subject); ?></div>
                                        <?php if ($exam['Class_Names']): ?>
                                            <div style="font-size: 0.85rem; color: #999; margin-top: 0.3rem;">
                                                <i class="fas fa-users" style="margin-right: 0.3rem;"></i>
                                                Classes: <?php echo htmlspecialchars($exam['Class_Names']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div style="font-size: 0.85rem; color: #666; margin-top: 0.3rem;">
                                            <i class="fas fa-clock" style="margin-right: 0.3rem;"></i>
                                            <?php echo $formattedTime; ?> • 
                                            <i class="fas fa-hourglass-half" style="margin-left: 0.5rem; margin-right: 0.3rem;"></i>
                                            <?php echo $exam['Duration']; ?> min • 
                                            <i class="fas fa-star" style="margin-left: 0.5rem; margin-right: 0.3rem;"></i>
                                            <?php echo number_format($exam['Total_Marks'], 1); ?> marks
                                        </div>
                                        <?php if ($exam['Description']): ?>
                                            <div style="font-size: 0.85rem; color: #666; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #FFE5E5;">
                                                <?php echo htmlspecialchars(substr($exam['Description'], 0, 100)) . (strlen($exam['Description']) > 100 ? '...' : ''); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="exam-date-badge"><?php echo $formattedDate; ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php
                
                $studentsGrades = [];
                if (!empty($linkedStudentIds)) {
                    try {
                        
                        $placeholders = implode(',', array_fill(0, count($linkedStudentIds), '?'));
                        $stmt = $pdo->prepare("
                            SELECT DISTINCT c.Course_ID, c.Course_Name, s.Student_ID, s.NameEn, s.NameAr, s.Student_Code
                            FROM course c
                            INNER JOIN course_class cc ON c.Course_ID = cc.Course_ID
                            INNER JOIN student s ON cc.Class_ID = s.Class_ID
                            WHERE s.Student_ID IN ($placeholders)
                            ORDER BY s.Student_ID, c.Course_Name
                        ");
                        $stmt->execute($linkedStudentIds);
                        $coursesForStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($coursesForStudents as $courseData) {
                            $studentId = $courseData['Student_ID'];
                            $courseId = $courseData['Course_ID'];

                            $stmt = $pdo->prepare("
                                SELECT Type, Value
                                FROM grade
                                WHERE Student_ID = ? AND Course_ID = ?
                                ORDER BY Date_Recorded DESC
                            ");
                            $stmt->execute([$studentId, $courseId]);
                            $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

                            $gradeByType = [];
                            foreach ($grades as $grade) {
                                if (!isset($gradeByType[$grade['Type']])) {
                                    $gradeByType[$grade['Type']] = floatval($grade['Value']);
                                }
                            }

                            $midterm = min(30, floatval($gradeByType['Midterm'] ?? 0));
                            $final = min(40, floatval($gradeByType['Final'] ?? 0));
                            $assignment = min(10, floatval($gradeByType['Assignment'] ?? 0));
                            $quiz = min(10, floatval($gradeByType['Quiz'] ?? 0));
                            $project = min(10, floatval($gradeByType['Project'] ?? 0));

                            $total = round($midterm + $final + $assignment + $quiz + $project, 1);

                            $letterGrade = 'N/A';
                            if ($total >= 97) {
                                $letterGrade = 'A+';
                            } elseif ($total >= 93) {
                                $letterGrade = 'A';
                            } elseif ($total >= 90) {
                                $letterGrade = 'A-';
                            } elseif ($total >= 87) {
                                $letterGrade = 'B+';
                            } elseif ($total >= 83) {
                                $letterGrade = 'B';
                            } elseif ($total >= 80) {
                                $letterGrade = 'B-';
                            } elseif ($total >= 77) {
                                $letterGrade = 'C+';
                            } elseif ($total >= 73) {
                                $letterGrade = 'C';
                            } elseif ($total >= 70) {
                                $letterGrade = 'C-';
                            } elseif ($total > 0) {
                                $letterGrade = 'F';
                            }
                            
                            if (!isset($studentsGrades[$studentId])) {
                                $studentsGrades[$studentId] = [
                                    'Student_ID' => $studentId,
                                    'NameEn' => $courseData['NameEn'],
                                    'NameAr' => $courseData['NameAr'],
                                    'Student_Code' => $courseData['Student_Code'],
                                    'Courses' => []
                                ];
                            }
                            
                            $studentsGrades[$studentId]['Courses'][] = [
                                'Course_ID' => $courseId,
                                'Course_Name' => $courseData['Course_Name'],
                                'Midterm' => $midterm,
                                'Final' => $final,
                                'Assignment' => $assignment,
                                'Quiz' => $quiz,
                                'Project' => $project,
                                'Total' => $total,
                                'LetterGrade' => $letterGrade
                            ];
                        }
                    } catch (PDOException $e) {
                        error_log("Error fetching grades for parent dashboard: " . $e->getMessage());
                    }
                }

                $selectedStudentId = isset($_GET['studentId']) ? intval($_GET['studentId']) : (!empty($linkedStudentIds) ? $linkedStudentIds[0] : null);
                $selectedStudentGrades = isset($studentsGrades[$selectedStudentId]) ? $studentsGrades[$selectedStudentId] : null;
                ?>
                <div class="card">
                    <div class="card-header">
                        <div style="display: flex; align-items: center; gap: 1rem; flex: 1;">
                            <h2 class="card-title">
                                <span class="card-icon">📈</span>
                                <span data-en="Academic Performance" data-ar="الأداء الأكاديمي">Academic Performance</span>
                            </h2>
                            <button onclick="toggleInstructions()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #6BCB77;" title="Instructions">
                                <i class="fas fa-info-circle"></i>
                            </button>
                        </div>
                        <?php if (count($linkedStudentIds) > 1): ?>
                            <select id="studentSelector" onchange="window.location.href='?studentId=' + this.value" style="padding: 0.5rem; border-radius: 10px; border: 2px solid #FFE5E5;">
                                <?php foreach ($linkedStudentsData as $student): ?>
                                    <option value="<?php echo $student['Student_ID']; ?>" <?php echo ($selectedStudentId == $student['Student_ID']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(($student['NameEn'] ?? $student['NameAr'] ?? 'N/A') . ' (' . ($student['Student_Code'] ?? 'N/A') . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div id="instructionsBox" style="display: none; background: #E5F3FF; padding: 1rem; border-radius: 10px; margin-bottom: 1rem; border-left: 4px solid #6BCB77;">
                        <h4 style="margin: 0 0 0.5rem 0; color: #2c3e50;" data-en="Grade Information" data-ar="معلومات الدرجات">Grade Information</h4>
                        <ul style="margin: 0; padding-left: 1.5rem; color: #555; font-size: 0.9rem;">
                            <li data-en="Grades are displayed for all courses your child is enrolled in" data-ar="يتم عرض الدرجات لجميع المقررات التي التحق بها طفلك">Grades are displayed for all courses your child is enrolled in</li>
                            <li data-en="Total is calculated as: Midterm (max 30) + Final (max 40) + Assignment (max 10) + Quiz (max 10) + Project (max 10)" data-ar="يتم حساب المجموع كالتالي: نصفي (حد أقصى 30) + نهائي (حد أقصى 40) + واجب (حد أقصى 10) + اختبار (حد أقصى 10) + مشروع (حد أقصى 10)">Total is calculated as: Midterm (max 30) + Final (max 40) + Assignment (max 10) + Quiz (max 10) + Project (max 10)</li>
                            <li data-en="Maximum total is 100 points" data-ar="الحد الأقصى للمجموع هو 100 نقطة">Maximum total is 100 points</li>
                            <li data-en="Grades update automatically when teachers add or modify them" data-ar="تتم تحديث الدرجات تلقائياً عند إضافة أو تعديل المعلمين لها">Grades update automatically when teachers add or modify them</li>
                        </ul>
                    </div>
                    <div class="grades-table-wrapper">
                        <?php if (empty($selectedStudentGrades) || empty($selectedStudentGrades['Courses'])): ?>
                            <div style="text-align: center; padding: 2rem; color: #999;">
                                <div style="font-size: 3rem; margin-bottom: 1rem;">📚</div>
                                <div data-en="No grades available yet" data-ar="لا توجد درجات متاحة بعد">No grades available yet</div>
                            </div>
                        <?php else: ?>
                            <table class="grades-table">
                                <thead>
                                    <tr>
                                        <th data-en="Subject" data-ar="المادة">Subject</th>
                                        <th data-en="Midterm (30)" data-ar="نصفي (30)">Midterm (30)</th>
                                        <th data-en="Final (40)" data-ar="نهائي (40)">Final (40)</th>
                                        <th data-en="Assignment (10)" data-ar="واجب (10)">Assignment (10)</th>
                                        <th data-en="Quiz (10)" data-ar="اختبار (10)">Quiz (10)</th>
                                        <th data-en="Project (10)" data-ar="مشروع (10)">Project (10)</th>
                                        <th data-en="Total" data-ar="المجموع">Total</th>
                                        <th data-en="Grade" data-ar="الدرجة">Grade</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($selectedStudentGrades['Courses'] as $course): ?>
                                        <tr>
                                            <td style="font-weight: 700; color: #2c3e50; white-space: nowrap;"><?php echo htmlspecialchars($course['Course_Name']); ?></td>
                                            <td class="grade-cell" style="background: #FFF9E5; text-align: center;"><?php echo number_format($course['Midterm'], 1); ?></td>
                                            <td class="grade-cell" style="background: #FFE5E5; text-align: center;"><?php echo number_format($course['Final'], 1); ?></td>
                                            <td class="grade-cell" style="background: #E5F3FF; text-align: center;"><?php echo number_format($course['Assignment'], 1); ?></td>
                                            <td class="grade-cell" style="background: #E5FFE5; text-align: center;"><?php echo number_format($course['Quiz'], 1); ?></td>
                                            <td class="grade-cell" style="background: #F5E5FF; text-align: center;"><?php echo number_format($course['Project'], 1); ?></td>
                                            <td class="grade-cell total-grade" style="font-weight: 700; font-size: 1.1rem; background: #E5F3FF; text-align: center;"><?php echo number_format($course['Total'], 1); ?></td>
                                            <td class="grade-cell" style="font-weight: 700; color: <?php echo $course['Total'] >= 90 ? '#6BCB77' : ($course['Total'] >= 80 ? '#FFD93D' : '#FF6B9D'); ?>; text-align: center;"><?php echo $course['LetterGrade']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                        
                <div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">🏥</span>
                            <span data-en="Medical Records" data-ar="السجلات الطبية">Medical Records</span>
                        </h2>
                    </div>
                    
                    <?php if (empty($linkedStudentsData)): ?>
                        
                        <div style="text-align: center; padding: 2rem; color: #666;">
                            <div style="font-size: 2rem; margin-bottom: 0.5rem;">🏥</div>
                            <div data-en="No students linked to view medical records" data-ar="لا يوجد طلاب مرتبطين لعرض السجلات الطبية">No students linked to view medical records</div>
                        </div>
                    <?php elseif (empty($medicalRecordsData)): ?>
                        
                        <div style="text-align: center; padding: 2rem; color: #666;">
                            <div style="font-size: 2rem; margin-bottom: 0.5rem;">🏥</div>
                            <div style="font-weight: 700; margin-bottom: 0.5rem;" data-en="No Medical Records Available" data-ar="لا توجد سجلات طبية متاحة">No Medical Records Available</div>
                            <div style="font-size: 0.9rem;" data-en="Medical records will appear here once they are added by the school administration." data-ar="ستظهر السجلات الطبية هنا بمجرد إضافتها من قبل إدارة المدرسة.">
                                Medical records will appear here once they are added by the school administration.
                            </div>
                        </div>
                        <a href="medical-records.php" class="btn btn-primary" style="width: 100%; margin-top: 1rem; text-align: center; text-decoration: none; display: block;" data-en="View Medical Records Page" data-ar="عرض صفحة السجلات الطبية">View Medical Records Page</a>
                    <?php else: ?>
                        
                        <?php foreach ($medicalRecordsData as $index => $medical): ?>
                            <?php
                            
                            $studentInfo = null;
                            foreach ($linkedStudentsData as $student) {
                                if ($student['Student_ID'] == $medical['Student_ID']) {
                                    $studentInfo = $student;
                                    break;
                                }
                            }
                            ?>
                            <?php if ($index > 0): ?>
                                <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 2px solid #FFE5E5;"></div>
                            <?php endif; ?>
                            
                            <?php if (count($medicalRecordsData) > 1 && $studentInfo): ?>
                                <div style="margin-bottom: 1rem; padding: 0.75rem; background: #FFF9F5; border-radius: 10px; border-left: 4px solid #FF6B9D;">
                                    <div style="font-weight: 700; color: var(--text-dark);">
                                        <?php echo htmlspecialchars($studentInfo['NameEn']); ?>
                                    </div>
                                    <?php if ($studentInfo['Class_Name']): ?>
                                        <div style="font-size: 0.85rem; color: #666; margin-top: 0.3rem;">
                                            <i class="fas fa-graduation-cap" style="margin-right: 0.3rem;"></i>
                                            <?php echo htmlspecialchars($studentInfo['Class_Name']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="medical-record-summary">
                                <div class="medical-record-item">
                                    <div style="font-weight: 700; margin-bottom: 0.5rem; color: var(--primary-color);" data-en="Allergies" data-ar="الحساسية">Allergies</div>
                                    <div style="color: #666;">
                                        <?php if (!empty($medical['Allergies'])): ?>
                                            <?php echo nl2br(htmlspecialchars($medical['Allergies'])); ?>
                                        <?php else: ?>
                                            <span data-en="No known allergies" data-ar="لا توجد حساسية معروفة">No known allergies</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <?php if (!empty($medical['Blood_Type'])): ?>
                                <div class="medical-record-item">
                                    <div style="font-weight: 700; margin-bottom: 0.5rem; color: var(--primary-color);" data-en="Blood Type" data-ar="فصيلة الدم">Blood Type</div>
                                    <div style="color: #666;"><?php echo htmlspecialchars($medical['Blood_Type']); ?></div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($medical['Emergency_Contact'])): ?>
                                <div class="medical-record-item">
                                    <div style="font-weight: 700; margin-bottom: 0.5rem; color: var(--primary-color);" data-en="Emergency Contact" data-ar="جهة الاتصال في حالات الطوارئ">Emergency Contact</div>
                                    <div style="color: #666;"><?php echo htmlspecialchars($medical['Emergency_Contact']); ?></div>
                                </div>
                                <?php endif; ?>
                            
                            </div>
                            
                            <a href="medical-records.php?studentId=<?php echo $medical['Student_ID']; ?>" class="btn btn-primary" style="width: 100%; margin-top: 1rem; text-align: center; text-decoration: none; display: block;" data-en="View Full Medical Record" data-ar="عرض السجل الطبي الكامل">View Full Medical Record</a>
                        <?php endforeach; ?>
                        
                        <?php if (count($medicalRecordsData) < count($linkedStudentsData)): ?>
                            <div style="margin-top: 1.5rem; padding: 1rem; background: #FFF9E5; border-radius: 10px; border-left: 4px solid #FFD93D;">
                                <div style="font-size: 0.9rem; color: #666;">
                                    <i class="fas fa-info-circle" style="margin-right: 0.5rem;"></i>
                                    <span data-en="Some students may not have medical records yet." data-ar="قد لا يكون لدى بعض الطلاب سجلات طبية بعد.">Some students may not have medical records yet.</span>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📅</span>
                            <span data-en="Upcoming Events" data-ar="الأحداث القادمة">Upcoming Events</span>
                        </h2>
                    </div>
                    <div class="upcoming-events-list">
                        <?php if (empty($upcomingEvents)): ?>
                            <div style="text-align: center; padding: 2rem; color: #666;">
                                <div style="font-size: 2rem; margin-bottom: 0.5rem;">📅</div>
                                <div data-en="No upcoming events" data-ar="لا توجد أحداث قادمة">No upcoming events</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($upcomingEvents as $event): ?>
                                <?php
                                $eventDate = new DateTime($event['Date']);
                                $formattedDate = $eventDate->format('M d, Y');
                                $timeStr = $event['Time'] ? date('g:i A', strtotime($event['Time'])) : '';
                                $locationStr = $event['Location'] ? htmlspecialchars($event['Location']) : 'Location TBA';
                                $description = $event['Description'] ? htmlspecialchars(substr($event['Description'], 0, 100)) . (strlen($event['Description']) > 100 ? '...' : '') : 'No description available';
                                
                                $typeLabels = [
                                    'academic' => ['en' => 'Academic', 'ar' => 'أكاديمي'],
                                    'sports' => ['en' => 'Sports', 'ar' => 'رياضي'],
                                    'cultural' => ['en' => 'Cultural', 'ar' => 'ثقافي'],
                                    'meeting' => ['en' => 'Meeting', 'ar' => 'اجتماع'],
                                    'other' => ['en' => 'Other', 'ar' => 'أخرى']
                                ];
                                $typeLabel = $typeLabels[$event['Type']] ?? ['en' => $event['Type'], 'ar' => $event['Type']];
                                ?>
                                <div class="event-item" style="padding: 1rem; border-bottom: 2px solid #FFE5E5; transition: all 0.3s; cursor: pointer;" onmouseover="this.style.background='#FFF9F5'; this.style.transform='translateX(5px)';" onmouseout="this.style.background='transparent'; this.style.transform='translateX(0)';">
                                    <div style="display: flex; align-items: start; gap: 1rem;">
                                        <div style="font-size: 2rem; flex-shrink: 0;">📅</div>
                                        <div style="flex: 1;">
                                            <div style="font-weight: 700; font-size: 1.1rem; margin-bottom: 0.3rem; color: var(--text-dark);">
                                                <?php echo htmlspecialchars($event['Title']); ?>
                                            </div>
                                            <div style="font-size: 0.9rem; color: #666; margin-bottom: 0.5rem;">
                                                <span style="display: inline-block; background: #FFE5E5; padding: 0.2rem 0.5rem; border-radius: 10px; font-size: 0.8rem; margin-right: 0.5rem;" data-en="<?php echo $typeLabel['en']; ?>" data-ar="<?php echo $typeLabel['ar']; ?>"><?php echo $typeLabel['en']; ?></span>
                                                <i class="fas fa-calendar-alt" style="margin-right: 0.3rem; color: #FF6B9D;"></i>
                                                <?php echo $formattedDate; ?>
                                                <?php if ($timeStr): ?>
                                                    <i class="fas fa-clock" style="margin-left: 0.5rem; margin-right: 0.3rem; color: #FF6B9D;"></i>
                                                    <?php echo $timeStr; ?>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size: 0.85rem; color: #999; margin-bottom: 0.3rem;">
                                                <i class="fas fa-map-marker-alt" style="margin-right: 0.3rem; color: #6BCB77;"></i>
                                                <?php echo $locationStr; ?>
                                            </div>
                                            <?php if ($description && $description !== 'No description available'): ?>
                                            <div style="font-size: 0.85rem; color: #666; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #FFE5E5;">
                                                <?php echo $description; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($totalEventsCount > 4): ?>
                            <div style="text-align: center; margin-top: 1rem; padding-top: 1rem; border-top: 2px solid #FFE5E5;">
                                <button class="btn btn-secondary" onclick="viewAllEvents()" style="width: 100%;">
                                    <i class="fas fa-chevron-down" style="margin-right: 0.5rem;"></i>
                                    <span data-en="View More Events" data-ar="عرض المزيد من الأحداث">View More Events</span>
                                    <span style="margin-left: 0.5rem; opacity: 0.7;" data-en="(<?php echo $totalEventsCount - 4; ?> more)" data-ar="(<?php echo $totalEventsCount - 4; ?> المزيد)">(<?php echo $totalEventsCount - 4; ?> more)</span>
                                </button>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">💬</span>
                            <span data-en="Chat with Teachers" data-ar="الدردشة مع المعلمين">Chat with Teachers</span>
                        </h2>
                    </div>
                    <div style="text-align: center; padding: 1rem;">
                        <p style="color: #666; margin-bottom: 1.5rem;" data-en="Communicate with your child's teachers" data-ar="تواصل مع معلمي طفلك">Communicate with your child's teachers</p>
                        <a href="chat-with-teachers.php" class="btn btn-primary" style="width: 100%; text-decoration: none; display: block;" data-en="Open Chat" data-ar="فتح الدردشة">Open Chat</a>
                    </div>
                </div>
            </div>
        </div>

    <div id="profileModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <span onclick="closeProfileSettings()" style="float: right; font-size: 32px; cursor: pointer; color: #FF6B9D; font-weight: bold;">&times;</span>
            <h2 style="margin-bottom: 1.5rem; color: #FF6B9D; font-family: 'Fredoka', sans-serif; font-size: 2rem;" data-en="Profile Settings" data-ar="إعدادات الملف الشخصي">Profile Settings</h2>
            <form onsubmit="handleProfileUpdate(event)">
                <div class="form-group">
                    <label data-en="Phone Number" data-ar="رقم الهاتف">Phone Number</label>
                    <input type="tel" id="profilePhone" value="+962 7 1234 5678" required>
                </div>
                <div class="form-group">
                    <label data-en="Email Address" data-ar="عنوان البريد الإلكتروني">Email Address</label>
                    <input type="email" id="profileEmail" value="parent@example.com" required>
                </div>
                <div class="form-group">
                    <label data-en="Address" data-ar="العنوان">Address</label>
                    <textarea id="profileAddress" rows="3" required>Amman, Jordan</textarea>
                </div>
                <div class="form-group">
                    <label data-en="Current Password" data-ar="كلمة المرور الحالية">Current Password</label>
                    <input type="password" id="currentPassword" required>
                </div>
                <div class="form-group">
                    <label data-en="New Password" data-ar="كلمة المرور الجديدة">New Password</label>
                    <input type="password" id="newPassword" required>
                </div>
                <div class="form-group">
                    <label data-en="Confirm New Password" data-ar="تأكيد كلمة المرور الجديدة">Confirm New Password</label>
                    <input type="password" id="confirmPassword" required>
                </div>
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" style="width: 100%;" data-en="Save Changes" data-ar="حفظ التغييرات">Save Changes</button>
                    <button type="button" class="btn btn-secondary" style="width: 100%;" onclick="closeProfileSettings()" data-en="Cancel" data-ar="إلغاء">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="settingsModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <span onclick="closeSettings()" style="float: right; font-size: 32px; cursor: pointer; color: #FF6B9D; font-weight: bold;">&times;</span>
            <h2 style="margin-bottom: 1.5rem; color: #FF6B9D; font-family: 'Fredoka', sans-serif; font-size: 2rem;" data-en="Program Settings" data-ar="إعدادات البرنامج">Program Settings</h2>
            
            <div class="settings-section">
                <h3 data-en="Notifications" data-ar="الإشعارات">Notifications</h3>
                <div class="setting-item">
                    <div>
                        <div style="font-weight: 700;" data-en="Email Notifications" data-ar="إشعارات البريد الإلكتروني">Email Notifications</div>
                        <div style="font-size: 0.9rem; color: #666;" data-en="Receive notifications via email" data-ar="تلقي الإشعارات عبر البريد الإلكتروني">Receive notifications via email</div>
                    </div>
                    <div class="toggle-switch active" onclick="toggleSetting(this)"></div>
                </div>
                <div class="setting-item">
                    <div>
                        <div style="font-weight: 700;" data-en="Grade Updates" data-ar="تحديثات الدرجات">Grade Updates</div>
                        <div style="font-size: 0.9rem; color: #666;" data-en="Notify when grades are updated" data-ar="الإشعار عند تحديث الدرجات">Notify when grades are updated</div>
                    </div>
                    <div class="toggle-switch active" onclick="toggleSetting(this)"></div>
                </div>
                <div class="setting-item">
                    <div>
                        <div style="font-weight: 700;" data-en="Attendance Alerts" data-ar="تنبيهات الحضور">Attendance Alerts</div>
                        <div style="font-size: 0.9rem; color: #666;" data-en="Get notified about attendance changes" data-ar="الإشعار بتغييرات الحضور">Get notified about attendance changes</div>
                    </div>
                    <div class="toggle-switch active" onclick="toggleSetting(this)"></div>
                </div>
                <div class="setting-item">
                    <div>
                        <div style="font-weight: 700;" data-en="Teacher Messages" data-ar="رسائل المعلم">Teacher Messages</div>
                        <div style="font-size: 0.9rem; color: #666;" data-en="Get notified about teacher messages" data-ar="الإشعار برسائل المعلم">Get notified about teacher messages</div>
                    </div>
                    <div class="toggle-switch active" onclick="toggleSetting(this)"></div>
                </div>
            </div>

            <div class="settings-section">
                <h3 data-en="Appearance" data-ar="المظهر">Appearance</h3>
                <div class="setting-item">
                    <div>
                        <div style="font-weight: 700;" data-en="Theme Color" data-ar="لون المظهر">Theme Color</div>
                        <div style="font-size: 0.9rem; color: #666;" data-en="Choose your preferred theme color" data-ar="اختر لون المظهر المفضل">Choose your preferred theme color</div>
                    </div>
                </div>
                <div class="theme-option">
                    <div class="theme-color active" style="background: #FF6B9D;" onclick="changeTheme('#FF6B9D', this)"></div>
                    <div class="theme-color" style="background: #6BCB77;" onclick="changeTheme('#6BCB77', this)"></div>
                    <div class="theme-color" style="background: #4A90E2;" onclick="changeTheme('#4A90E2', this)"></div>
                    <div class="theme-color" style="background: #9B59B6;" onclick="changeTheme('#9B59B6', this)"></div>
                    <div class="theme-color" style="background: #E67E22;" onclick="changeTheme('#E67E22', this)"></div>
                </div>
                <div class="setting-item" style="margin-top: 1rem;">
                    <div>
                        <div style="font-weight: 700;" data-en="Dark Mode" data-ar="الوضع الداكن">Dark Mode</div>
                        <div style="font-size: 0.9rem; color: #666;" data-en="Switch to dark theme" data-ar="التبديل إلى المظهر الداكن">Switch to dark theme</div>
                    </div>
                    <div class="toggle-switch" onclick="toggleSetting(this)"></div>
                </div>
            </div>

            <div class="action-buttons" style="margin-top: 2rem;">
                <button type="button" class="btn btn-primary" style="width: 100%;" onclick="saveSettings()" data-en="Save Settings" data-ar="حفظ الإعدادات">Save Settings</button>
                <button type="button" class="btn btn-secondary" style="width: 100%;" onclick="closeSettings()" data-en="Cancel" data-ar="إلغاء">Cancel</button>
            </div>
        </div>
    </div>

    <div id="feedbackModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <span onclick="closeFeedback()" style="float: right; font-size: 32px; cursor: pointer; color: #FF6B9D; font-weight: bold;">&times;</span>
            <h2 style="margin-bottom: 1.5rem; color: #FF6B9D; font-family: 'Fredoka', sans-serif; font-size: 2rem;" data-en="Anonymous Feedback" data-ar="ملاحظات مجهولة">Anonymous Feedback</h2>
            <p style="margin-bottom: 1.5rem; color: #666; font-size: 0.9rem;" data-en="Your feedback is completely anonymous. Help us improve by sharing your thoughts, concerns, or suggestions." data-ar="ملاحظاتك مجهولة تماماً. ساعدنا على التحسين من خلال مشاركة أفكارك أو مخاوفك أو اقتراحاتك.">Your feedback is completely anonymous. Help us improve by sharing your thoughts, concerns, or suggestions.</p>
            <?php if ($feedbackSuccess): ?>
                <div style="background: #6BCB77; color: white; padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-check-circle" style="font-size: 1.2rem;"></i>
                    <span data-en="Feedback submitted successfully! Thank you for your input." data-ar="تم إرسال الملاحظات بنجاح! شكراً لمساهمتك.">
                        <?php echo isset($successMessage) ? htmlspecialchars($successMessage) : 'Feedback submitted successfully! Thank you for your input.'; ?>
                    </span>
                </div>
            <?php endif; ?>
            <?php if ($feedbackError): ?>
                <div style="background: #FF6B9D; color: white; padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-exclamation-circle" style="font-size: 1.2rem;"></i>
                    <span><?php echo htmlspecialchars($feedbackError); ?></span>
                </div>
            <?php endif; ?>
            <form method="POST" action="parent-dashboard.php" onsubmit="return validateFeedbackForm(event)">
                <input type="hidden" name="action" value="submitFeedback">
                <div class="form-group">
                    <label data-en="Feedback Category" data-ar="فئة الملاحظات">Feedback Category <span style="color: red;">*</span></label>
                    <select id="feedbackCategory" name="feedbackCategory" required>
                        <option value="" data-en="Select category" data-ar="اختر الفئة">Select category</option>
                        <option value="compliment" data-en="Compliment" data-ar="إشادة">Compliment</option>
                        <option value="suggestion" data-en="Suggestion" data-ar="اقتراح">Suggestion</option>
                        <option value="complaint" data-en="Complaint" data-ar="شكوى">Complaint</option>
                    </select>
                </div>
                <div class="form-group">
                    <label data-en="Your Feedback" data-ar="ملاحظاتك">Your Feedback <span style="color: red;">*</span></label>
                    <textarea id="feedbackText" name="feedbackText" rows="6" placeholder="Share your thoughts..." required></textarea>
                </div>
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" style="width: 100%;" data-en="Submit Feedback" data-ar="إرسال الملاحظات">Submit Feedback</button>
                    <button type="button" class="btn btn-secondary" style="width: 100%;" onclick="closeFeedback()" data-en="Cancel" data-ar="إلغاء">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="absenceNoteModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <span onclick="closeAbsenceNoteModal()" style="float: right; font-size: 32px; cursor: pointer; color: #FF6B9D; font-weight: bold;">&times;</span>
            <h2 style="margin-bottom: 1rem; color: #FF6B9D; font-family: 'Fredoka', sans-serif; font-size: 2rem;" data-en="Upload Absence Note" data-ar="رفع ملاحظة الغياب">Upload Absence Note</h2>
            <div class="absence-date-info" style="background: #FFE5E5; padding: 1rem; border-radius: 15px; margin-bottom: 1.5rem;">
                <div style="font-weight: 700; color: var(--primary-color);" id="absenceDateDisplay"></div>
            </div>
            <form onsubmit="handleAbsenceNoteSubmit(event)" id="absenceNoteForm">
                <input type="hidden" id="absenceDate" value="">
                <div class="form-group">
                    <label data-en="Reason for Absence" data-ar="سبب الغياب">Reason for Absence</label>
                    <select id="absenceReason" required>
                        <option value="" data-en="Select reason" data-ar="اختر السبب">Select reason</option>
                        <option value="medical" data-en="Medical Appointment / Illness" data-ar="موعد طبي / مرض">Medical Appointment / Illness</option>
                        <option value="family" data-en="Family Emergency" data-ar="طوارئ عائلية">Family Emergency</option>
                        <option value="personal" data-en="Personal Reasons" data-ar="أسباب شخصية">Personal Reasons</option>
                        <option value="other" data-en="Other" data-ar="أخرى">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label data-en="Additional Notes" data-ar="ملاحظات إضافية">Additional Notes</label>
                    <textarea id="absenceNotes" rows="4" placeholder="Please provide additional details..." data-placeholder-en="Please provide additional details..." data-placeholder-ar="يرجى تقديم تفاصيل إضافية..."></textarea>
                </div>
                <div class="form-group">
                    <label data-en="Upload Supporting Document (Optional)" data-ar="رفع مستند داعم (اختياري)">Upload Supporting Document (Optional)</label>
                    <div class="upload-area-absence" onclick="document.getElementById('absenceFile').click()">
                        <div class="upload-icon-absence">
                            <i class="fas fa-cloud-upload-alt"></i>
                        </div>
                        <div data-en="Click to upload file or drag and drop" data-ar="انقر للرفع أو اسحب وأفلت">Click to upload file or drag and drop</div>
                        <div class="upload-file-info" id="uploadFileInfo" style="display: none; margin-top: 0.5rem; color: #666; font-size: 0.9rem;"></div>
                        <input type="file" id="absenceFile" style="display: none;" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" onchange="handleFileSelect(event)">
                    </div>
                </div>
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" style="width: 100%;" data-en="Submit Note" data-ar="إرسال الملاحظة">Submit Note</button>
                    <button type="button" class="btn btn-secondary" style="width: 100%;" onclick="closeAbsenceNoteModal()" data-en="Cancel" data-ar="إلغاء">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div class="side-menu-overlay" id="sideMenuOverlay" onclick="toggleSideMenu()"></div>
    <div class="side-menu-mobile" id="sideMenuMobile">
        <div class="side-menu-header">
            <h3 data-en="Quick Menu" data-ar="القائمة السريعة">Quick Menu</h3>
            <button class="side-menu-close" onclick="toggleSideMenu()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="side-menu-content">
            <a href="installments.php" class="side-menu-item" onclick="toggleSideMenu();">
                <div class="side-menu-icon">💰</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="Installments" data-ar="الأقساط">Installments</div>
                    <div class="side-menu-subtitle" data-en="View payment history" data-ar="عرض سجل الدفع">View payment history</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </a>
            <a href="academic-performance.php" class="side-menu-item" onclick="toggleSideMenu();">
                <div class="side-menu-icon">📈</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="Academic Performance" data-ar="الأداء الأكاديمي">Academic Performance</div>
                    <div class="side-menu-subtitle" data-en="View grades and progress" data-ar="عرض الدرجات والتقدم">View grades and progress</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </a>
            <a href="class-schedule.php" class="side-menu-item" onclick="toggleSideMenu();">
                <div class="side-menu-icon">📅</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="Class Schedule" data-ar="جدول الحصص">Class Schedule</div>
                    <div class="side-menu-subtitle" data-en="View weekly schedule" data-ar="عرض الجدول الأسبوعي">View weekly schedule</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </a>
            <a href="attendance-record.php" class="side-menu-item" onclick="toggleSideMenu();">
                <div class="side-menu-icon">📋</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="Attendance Record" data-ar="سجل الحضور">Attendance Record</div>
                    <div class="side-menu-subtitle" data-en="Track attendance history" data-ar="تتبع سجل الحضور">Track attendance history</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </a>
            <a href="request-leave.php" class="side-menu-item" onclick="toggleSideMenu();">
                <div class="side-menu-icon">📋</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="Request Leave" data-ar="طلب إجازة">Request Leave</div>
                    <div class="side-menu-subtitle" data-en="Submit leave requests" data-ar="تقديم طلبات الإجازة">Submit leave requests</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </a>
            <a href="medical-records.php" class="side-menu-item" onclick="toggleSideMenu();">
                <div class="side-menu-icon">🏥</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="Medical Records" data-ar="السجلات الطبية">Medical Records</div>
                    <div class="side-menu-subtitle" data-en="View medical information" data-ar="عرض المعلومات الطبية">View medical information</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </a>
            <a href="chat-with-teachers.php" class="side-menu-item" onclick="toggleSideMenu();">
                <div class="side-menu-icon">💬</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="Chat with Teachers" data-ar="الدردشة مع المعلمين">Chat with Teachers</div>
                    <div class="side-menu-subtitle" data-en="Communicate with teachers" data-ar="تواصل مع المعلمين">Communicate with teachers</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </a>
            <a href="../homepage.php" class="side-menu-item" onclick="toggleSideMenu();">
                <div class="side-menu-icon">🏠</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="Home" data-ar="الرئيسية">Home</div>
                    <div class="side-menu-subtitle" data-en="Go to homepage" data-ar="الذهاب إلى الصفحة الرئيسية">Go to homepage</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </a>
            <div class="side-menu-item" onclick="openProfileSettings(); toggleSideMenu();">
                <div class="side-menu-icon">👤</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="Profile Settings" data-ar="إعدادات الملف الشخصي">Profile Settings</div>
                    <div class="side-menu-subtitle" data-en="Edit your profile" data-ar="تعديل ملفك الشخصي">Edit your profile</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </div>
            <div class="side-menu-item" onclick="openSettings(); toggleSideMenu();">
                <div class="side-menu-icon">⚙️</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="Settings" data-ar="الإعدادات">Settings</div>
                    <div class="side-menu-subtitle" data-en="Customize preferences" data-ar="تخصيص التفضيلات">Customize preferences</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </div>
            <div class="side-menu-item" onclick="openFeedback(); toggleSideMenu();">
                <div class="side-menu-icon">💬</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="Feedback" data-ar="ملاحظات">Feedback</div>
                    <div class="side-menu-subtitle" data-en="Share your thoughts" data-ar="شارك أفكارك">Share your thoughts</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </div>
        </div>
    </div>

    <div id="allEventsModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 800px; max-height: 90vh; overflow-y: auto;">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #FFE5E5;">
                <h2 style="margin: 0; color: var(--text-dark);">
                    <span class="card-icon">📅</span>
                    <span data-en="All Upcoming Events" data-ar="جميع الأحداث القادمة">All Upcoming Events</span>
                </h2>
                <button onclick="closeAllEventsModal()" style="background: none; border: none; font-size: 1.5rem; color: #999; cursor: pointer; padding: 0.5rem;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="allEventsList" class="upcoming-events-list">
                
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        
        const allEventsData = <?php echo json_encode($allUpcomingEvents, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function viewAllEvents() {
            const modal = document.getElementById('allEventsModal');
            const eventsList = document.getElementById('allEventsList');
            
            if (!allEventsData || allEventsData.length === 0) {
                eventsList.innerHTML = '<div style="text-align: center; padding: 2rem; color: #666;">' + 
                    (currentLanguage === 'en' ? 'No events found' : 'لا توجد أحداث') + '</div>';
                modal.style.display = 'flex';
                return;
            }
            
            const typeLabels = {
                'academic': { en: 'Academic', ar: 'أكاديمي' },
                'sports': { en: 'Sports', ar: 'رياضي' },
                'cultural': { en: 'Cultural', ar: 'ثقافي' },
                'meeting': { en: 'Meeting', ar: 'اجتماع' },
                'other': { en: 'Other', ar: 'أخرى' }
            };
            
            eventsList.innerHTML = allEventsData.map(event => {
                const eventDate = new Date(event.Date);
                const formattedDate = eventDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                const timeStr = event.Time ? new Date('1970-01-01T' + event.Time).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true }) : '';
                const locationStr = event.Location || 'Location TBA';
                const description = event.Description ? (event.Description.length > 150 ? event.Description.substring(0, 150) + '...' : event.Description) : 'No description available';
                const typeLabel = typeLabels[event.Type] || { en: event.Type, ar: event.Type };
                
                return `
                    <div class="event-item" style="padding: 1rem; border-bottom: 2px solid #FFE5E5; transition: all 0.3s;">
                        <div style="display: flex; align-items: start; gap: 1rem;">
                            <div style="font-size: 2rem; flex-shrink: 0;">📅</div>
                            <div style="flex: 1;">
                                <div style="font-weight: 700; font-size: 1.1rem; margin-bottom: 0.3rem; color: var(--text-dark);">
                                    ${escapeHtml(event.Title)}
                                </div>
                                <div style="font-size: 0.9rem; color: #666; margin-bottom: 0.5rem;">
                                    <span style="display: inline-block; background: #FFE5E5; padding: 0.2rem 0.5rem; border-radius: 10px; font-size: 0.8rem; margin-right: 0.5rem;" data-en="${typeLabel.en}" data-ar="${typeLabel.ar}">${typeLabel.en}</span>
                                    <i class="fas fa-calendar-alt" style="margin-right: 0.3rem; color: #FF6B9D;"></i>
                                    ${formattedDate}
                                    ${timeStr ? `<i class="fas fa-clock" style="margin-left: 0.5rem; margin-right: 0.3rem; color: #FF6B9D;"></i>${timeStr}` : ''}
                                </div>
                                <div style="font-size: 0.85rem; color: #999; margin-bottom: 0.3rem;">
                                    <i class="fas fa-map-marker-alt" style="margin-right: 0.3rem; color: #6BCB77;"></i>
                                    ${escapeHtml(locationStr)}
                                </div>
                                ${description && description !== 'No description available' ? `
                                <div style="font-size: 0.85rem; color: #666; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #FFE5E5;">
                                    ${escapeHtml(description)}
                                </div>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
            
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeAllEventsModal() {
            const modal = document.getElementById('allEventsModal');
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }

        window.onclick = function(event) {
            const modal = document.getElementById('allEventsModal');
            if (event.target === modal) {
                closeAllEventsModal();
            }
        }

        function updateNotificationBadge() {
            const unreadCount = document.querySelectorAll('.notification-dropdown-item.unread').length;
            const badge = document.getElementById('notificationCount');
            const badgeMobile = document.getElementById('notificationCountMobile');
            if (unreadCount > 0) {
                if (badge) badge.textContent = unreadCount;
                if (badgeMobile) badgeMobile.textContent = unreadCount;
            } else {
                if (badge) badge.style.display = 'none';
                if (badgeMobile) badgeMobile.style.display = 'none';
            }
        }
        
        function handleNotificationClick(element) {
            element.classList.remove('unread');
            updateNotificationBadge();
        }
        
        function markAllAsRead() {
            document.querySelectorAll('.notification-dropdown-item').forEach(item => {
                item.classList.remove('unread');
            });
            updateNotificationBadge();
        }

        document.addEventListener('DOMContentLoaded', function() {
            updateNotificationBadge();
        });

        function openFeedback() {
            const modal = document.getElementById('feedbackModal');
            if (modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
                
                const successMsg = modal.querySelector('div[style*="background: #6BCB77"]');
                const errorMsg = modal.querySelector('div[style*="background: #FF6B9D"]');
                if (successMsg) successMsg.remove();
                if (errorMsg) errorMsg.remove();
            }
        }
        
        function closeFeedback() {
            const modal = document.getElementById('feedbackModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
                
                const form = modal.querySelector('form');
                if (form) form.reset();
            }
        }
        
        function validateFeedbackForm(event) {
            const category = document.getElementById('feedbackCategory').value;
            const message = document.getElementById('feedbackText').value.trim();
            const lang = (typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'ar' : 'en';

            const errorDiv = document.querySelector('#feedbackModal div[style*="background: #FF6B9D"]');
            if (errorDiv) errorDiv.remove();
            
            if (!category) {
                const errorMsg = lang === 'en' ? 'Please select a feedback category.' : 'يرجى اختيار فئة الملاحظات.';
                showFeedbackError(errorMsg);
                event.preventDefault();
                return false;
            }
            
            if (!message) {
                const errorMsg = lang === 'en' ? 'Please enter your feedback message.' : 'يرجى إدخال رسالة الملاحظات.';
                showFeedbackError(errorMsg);
                event.preventDefault();
                return false;
            }

            const submitBtn = event.target.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                const loadingText = lang === 'en' ? 'Submitting...' : 'جاري الإرسال...';
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + loadingText;
            }

            return true;
        }
        
        function showFeedbackError(message) {
            const modal = document.getElementById('feedbackModal');
            if (!modal) return;
            
            const form = modal.querySelector('form');
            if (!form) return;

            const existingError = modal.querySelector('div[style*="background: #FF6B9D"]');
            if (existingError) existingError.remove();

            const errorDiv = document.createElement('div');
            errorDiv.style.cssText = 'background: #FF6B9D; color: white; padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;';
            errorDiv.innerHTML = '<i class="fas fa-exclamation-circle" style="font-size: 1.2rem;"></i><span>' + message + '</span>';

            form.parentNode.insertBefore(errorDiv, form);

            errorDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        window.onclick = function(event) {
            const feedbackModal = document.getElementById('feedbackModal');
            if (event.target === feedbackModal) {
                closeFeedback();
            }
        }

        <?php if ($feedbackSuccess): ?>
        document.addEventListener('DOMContentLoaded', function() {
            
            openFeedback();
            
            setTimeout(function() {
                closeFeedback();
                
                if (window.history && window.history.replaceState) {
                    window.history.replaceState({}, document.title, window.location.pathname);
                }
            }, 5000);
        });
        <?php endif; ?>

        <?php if ($feedbackError && !$feedbackSuccess): ?>
        document.addEventListener('DOMContentLoaded', function() {
            
            openFeedback();
        });
        <?php endif; ?>

        function switchStudentTab(index) {
            
            document.querySelectorAll('.student-academic-status').forEach(section => {
                section.style.display = 'none';
            });

            document.querySelectorAll('.student-tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            const sections = document.querySelectorAll('.student-academic-status');
            if (sections[index]) {
                sections[index].style.display = 'block';
            }

            const tabs = document.querySelectorAll('.student-tab-btn');
            if (tabs[index]) {
                tabs[index].classList.add('active');
            }
        }
        
        function toggleInstructions() {
            const box = document.getElementById('instructionsBox');
            if (box) {
                box.style.display = box.style.display === 'none' ? 'block' : 'none';
            }
        }
        
        function toggleAcademicNotes(studentId) {
            const notesDiv = document.getElementById('academicNotes_' + studentId);
            const btn = document.getElementById('showMoreBtn_' + studentId);
            const chevron = document.getElementById('chevron_' + studentId);
            
            if (notesDiv && btn && chevron) {
                const isHidden = notesDiv.style.display === 'none' || !notesDiv.style.display;
                
                if (isHidden) {
                    notesDiv.style.display = 'block';
                    chevron.classList.remove('fa-chevron-down');
                    chevron.classList.add('fa-chevron-up');
                    const span = btn.querySelector('span');
                    if (span) {
                        span.setAttribute('data-en', 'Show Less');
                        span.setAttribute('data-ar', 'عرض أقل');
                        span.textContent = (typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'عرض أقل' : 'Show Less';
                    }
                } else {
                    notesDiv.style.display = 'none';
                    chevron.classList.remove('fa-chevron-up');
                    chevron.classList.add('fa-chevron-down');
                    const span = btn.querySelector('span');
                    if (span) {
                        span.setAttribute('data-en', 'Show More');
                        span.setAttribute('data-ar', 'عرض المزيد');
                        span.textContent = (typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'عرض المزيد' : 'Show More';
                    }
                }
            }
        }
    </script>
    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: white;
            padding: 2rem;
            border-radius: 25px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            position: relative;
        }

        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                max-width: 95%;
                padding: 1.5rem;
            }
        }
    </style>
</body>
</html>

