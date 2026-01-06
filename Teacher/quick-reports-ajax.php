<?php

ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('teacher');

header('Content-Type: application/json');

$currentTeacherId = getCurrentUserId();

if (!$currentTeacherId) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    ob_end_flush();
    exit();
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_classes':
            
            $stmt = $pdo->prepare("
                SELECT DISTINCT c.Class_ID, c.Name as Class_Name, c.Grade_Level, c.Section
                FROM teacher_class_course tcc
                JOIN class c ON tcc.Class_ID = c.Class_ID
                WHERE tcc.Teacher_ID = ?
                ORDER BY c.Grade_Level, c.Section
            ");
            $stmt->execute([$currentTeacherId]);
            $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'classes' => $classes
            ]);
            ob_end_flush();
            break;
            
        case 'get_grades_report':
            $classId = isset($_GET['class_id']) && $_GET['class_id'] !== 'all' ? intval($_GET['class_id']) : null;

            $conditions = ["tcc.Teacher_ID = ?"];
            $params = [$currentTeacherId];
            
            if ($classId) {
                
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM teacher_class_course 
                    WHERE Teacher_ID = ? AND Class_ID = ?
                ");
                $stmt->execute([$currentTeacherId, $classId]);
                $hasAccess = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
                
                if (!$hasAccess) {
                    throw new Exception('Unauthorized: Teacher does not have access to this class');
                }
                
                $conditions[] = "c.Class_ID = ?";
                $params[] = $classId;
            }

            $query = "
                SELECT DISTINCT 
                    c.Class_ID,
                    c.Name as Class_Name,
                    c.Grade_Level,
                    c.Section,
                    s.Student_ID,
                    COALESCE(NULLIF(s.NameEn, ''), NULLIF(s.NameAr, ''), 'Unknown') as Student_Name,
                    co.Course_ID,
                    co.Course_Name
                FROM teacher_class_course tcc
                JOIN class c ON tcc.Class_ID = c.Class_ID
                JOIN course co ON tcc.Course_ID = co.Course_ID
                JOIN student s ON s.Class_ID = c.Class_ID
                WHERE " . implode(' AND ', $conditions) . "
                ORDER BY c.Grade_Level, c.Section, s.NameEn, co.Course_Name
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $studentIds = array_unique(array_column($results, 'Student_ID'));
            $courseIds = array_unique(array_column($results, 'Course_ID'));
            $gradesByStudentCourse = [];
            
            if (!empty($studentIds) && !empty($courseIds)) {
                $studentPlaceholders = implode(',', array_fill(0, count($studentIds), '?'));
                $coursePlaceholders = implode(',', array_fill(0, count($courseIds), '?'));

                $gradeQuery = "
                    SELECT g1.Student_ID, g1.Course_ID, g1.Type, g1.Value
                    FROM grade g1
                    INNER JOIN (
                        SELECT Student_ID, Course_ID, Type, MAX(Date_Recorded) as MaxDate
                        FROM grade
                        WHERE Student_ID IN ($studentPlaceholders)
                        AND Course_ID IN ($coursePlaceholders)
                        AND Teacher_ID = ?
                        GROUP BY Student_ID, Course_ID, Type
                    ) g2 ON g1.Student_ID = g2.Student_ID 
                        AND g1.Course_ID = g2.Course_ID 
                        AND g1.Type = g2.Type 
                        AND g1.Date_Recorded = g2.MaxDate
                    WHERE g1.Teacher_ID = ?
                ";
                
                $gradeParams = array_merge($studentIds, $courseIds, [$currentTeacherId, $currentTeacherId]);
                $gradeStmt = $pdo->prepare($gradeQuery);
                $gradeStmt->execute($gradeParams);
                $allGrades = $gradeStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($allGrades as $grade) {
                    $key = $grade['Student_ID'] . '_' . $grade['Course_ID'] . '_' . $grade['Type'];
                    $gradesByStudentCourse[$key] = floatval($grade['Value']);
                }
            }

            $reportData = [];
            foreach ($results as $row) {
                $classId = $row['Class_ID'];
                $studentId = $row['Student_ID'];
                $courseId = $row['Course_ID'];
                
                if (!isset($reportData[$classId])) {
                    $reportData[$classId] = [
                        'class_id' => $classId,
                        'class_name' => $row['Class_Name'],
                        'grade_level' => $row['Grade_Level'],
                        'section' => $row['Section'],
                        'students' => []
                    ];
                }
                
                if (!isset($reportData[$classId]['students'][$studentId])) {
                    $reportData[$classId]['students'][$studentId] = [
                        'student_id' => $studentId,
                        'student_name' => $row['Student_Name'],
                        'subjects' => []
                    ];
                }

                $midtermKey = $studentId . '_' . $courseId . '_Midterm';
                $finalKey = $studentId . '_' . $courseId . '_Final';
                $quizKey = $studentId . '_' . $courseId . '_Quiz';
                $assignmentKey = $studentId . '_' . $courseId . '_Assignment';
                $projectKey = $studentId . '_' . $courseId . '_Project';

                $midterm = min(30, floatval($gradesByStudentCourse[$midtermKey] ?? 0));
                $final = min(40, floatval($gradesByStudentCourse[$finalKey] ?? 0));
                $quiz = min(10, floatval($gradesByStudentCourse[$quizKey] ?? 0));
                $assignment = min(10, floatval($gradesByStudentCourse[$assignmentKey] ?? 0));
                $project = min(10, floatval($gradesByStudentCourse[$projectKey] ?? 0));
                $total = round($midterm + $final + $quiz + $assignment + $project, 1);
                
                $reportData[$classId]['students'][$studentId]['subjects'][] = [
                    'course_id' => $courseId,
                    'course_name' => $row['Course_Name'],
                    'midterm' => $midterm,
                    'final' => $final,
                    'quiz' => $quiz,
                    'assignment' => $assignment,
                    'project' => $project,
                    'total' => $total
                ];
            }

            $reportData = array_values(array_map(function($class) {
                $class['students'] = array_values($class['students']);
                return $class;
            }, $reportData));
            
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'data' => $reportData
            ]);
            ob_end_flush();
            break;
            
        case 'get_attendance_report':
            $classId = isset($_GET['class_id']) && $_GET['class_id'] !== 'all' ? intval($_GET['class_id']) : null;

            $conditions = ["tcc.Teacher_ID = ?"];
            $params = [$currentTeacherId];
            
            if ($classId) {
                
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM teacher_class_course 
                    WHERE Teacher_ID = ? AND Class_ID = ?
                ");
                $stmt->execute([$currentTeacherId, $classId]);
                $hasAccess = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
                
                if (!$hasAccess) {
                    throw new Exception('Unauthorized: Teacher does not have access to this class');
                }
                
                $conditions[] = "c.Class_ID = ?";
                $params[] = $classId;
            }

            $query = "
                SELECT DISTINCT 
                    c.Class_ID,
                    c.Name as Class_Name,
                    c.Grade_Level,
                    c.Section,
                    s.Student_ID,
                    COALESCE(NULLIF(s.NameEn, ''), NULLIF(s.NameAr, ''), 'Unknown') as Student_Name,
                    SUM(CASE WHEN a.Status = 'Present' THEN 1 ELSE 0 END) as Total_Present,
                    SUM(CASE WHEN a.Status = 'Absent' THEN 1 ELSE 0 END) as Total_Absent,
                    SUM(CASE WHEN a.Status = 'Late' THEN 1 ELSE 0 END) as Total_Late,
                    COUNT(a.Attendance_ID) as Total_Records
                FROM teacher_class_course tcc
                JOIN class c ON tcc.Class_ID = c.Class_ID
                JOIN student s ON s.Class_ID = c.Class_ID
                LEFT JOIN attendance a ON a.Student_ID = s.Student_ID AND a.Class_ID = c.Class_ID
                WHERE " . implode(' AND ', $conditions) . "
                GROUP BY c.Class_ID, s.Student_ID
                ORDER BY c.Grade_Level, c.Section, s.NameEn
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $reportData = [];
            foreach ($results as $row) {
                $classId = $row['Class_ID'];
                $studentId = $row['Student_ID'];
                
                if (!isset($reportData[$classId])) {
                    $reportData[$classId] = [
                        'class_id' => $classId,
                        'class_name' => $row['Class_Name'],
                        'grade_level' => $row['Grade_Level'],
                        'section' => $row['Section'],
                        'students' => []
                    ];
                }
                
                $totalPresent = intval($row['Total_Present'] ?? 0);
                $totalAbsent = intval($row['Total_Absent'] ?? 0);
                $totalLate = intval($row['Total_Late'] ?? 0);
                $totalRecords = intval($row['Total_Records'] ?? 0);

                $attendancePercentage = 0;
                if ($totalRecords > 0) {
                    $attendancePercentage = round((($totalPresent + $totalLate) / $totalRecords) * 100, 1);
                }
                
                $reportData[$classId]['students'][] = [
                    'student_id' => $studentId,
                    'student_name' => $row['Student_Name'],
                    'total_present' => $totalPresent,
                    'total_absent' => $totalAbsent,
                    'total_late' => $totalLate,
                    'attendance_percentage' => $attendancePercentage
                ];
            }

            $reportData = array_values($reportData);
            
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'data' => $reportData
            ]);
            ob_end_flush();
            break;
            
        case 'get_assignments_report':
            $classId = isset($_GET['class_id']) && $_GET['class_id'] !== 'all' ? intval($_GET['class_id']) : null;

            $conditions = ["a.Teacher_ID = ?", "a.Status != 'cancelled'"];
            $params = [$currentTeacherId];
            
            if ($classId) {
                
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM teacher_class_course 
                    WHERE Teacher_ID = ? AND Class_ID = ?
                ");
                $stmt->execute([$currentTeacherId, $classId]);
                $hasAccess = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
                
                if (!$hasAccess) {
                    throw new Exception('Unauthorized: Teacher does not have access to this class');
                }
                
                $conditions[] = "a.Class_ID = ?";
                $params[] = $classId;
            }

            $query = "
                SELECT DISTINCT
                    a.Assignment_ID,
                    a.Title,
                    a.Due_Date,
                    a.Class_ID,
                    c.Name as Class_Name,
                    c.Grade_Level,
                    c.Section,
                    co.Course_ID,
                    co.Course_Name
                FROM assignment a
                JOIN class c ON a.Class_ID = c.Class_ID
                JOIN course co ON a.Course_ID = co.Course_ID
                WHERE " . implode(' AND ', $conditions) . "
                ORDER BY c.Grade_Level, c.Section, a.Due_Date DESC, a.Title
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $classIds = array_unique(array_column($assignments, 'Class_ID'));
            $studentsByClass = [];
            
            if (!empty($classIds)) {
                $placeholders = implode(',', array_fill(0, count($classIds), '?'));
                $stmt = $pdo->prepare("
                    SELECT Student_ID, Class_ID, 
                           COALESCE(NULLIF(NameEn, ''), NULLIF(NameAr, ''), 'Unknown') as Student_Name
                    FROM student
                    WHERE Class_ID IN ($placeholders)
                    ORDER BY NameEn
                ");
                $stmt->execute($classIds);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($students as $student) {
                    $classId = $student['Class_ID'];
                    if (!isset($studentsByClass[$classId])) {
                        $studentsByClass[$classId] = [];
                    }
                    $studentsByClass[$classId][] = $student;
                }
            }

            $assignmentIds = array_column($assignments, 'Assignment_ID');
            $submissionsByAssignment = [];
            
            if (!empty($assignmentIds)) {
                $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));
                $stmt = $pdo->prepare("
                    SELECT Submission_ID, Student_ID, Assignment_ID, Status, Submission_Date
                    FROM submission
                    WHERE Assignment_ID IN ($placeholders)
                ");
                $stmt->execute($assignmentIds);
                $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($submissions as $sub) {
                    $assignmentId = $sub['Assignment_ID'];
                    $studentId = $sub['Student_ID'];
                    if (!isset($submissionsByAssignment[$assignmentId])) {
                        $submissionsByAssignment[$assignmentId] = [];
                    }
                    $submissionsByAssignment[$assignmentId][$studentId] = $sub;
                }
            }

            $reportData = [];
            foreach ($assignments as $assignment) {
                $classId = $assignment['Class_ID'];
                $assignmentId = $assignment['Assignment_ID'];
                
                if (!isset($reportData[$classId])) {
                    $reportData[$classId] = [
                        'class_id' => $classId,
                        'class_name' => $assignment['Class_Name'],
                        'grade_level' => $assignment['Grade_Level'],
                        'section' => $assignment['Section'],
                        'assignments' => []
                    ];
                }

                $students = $studentsByClass[$classId] ?? [];
                $studentSubmissions = [];
                
                foreach ($students as $student) {
                    $studentId = $student['Student_ID'];
                    $submission = $submissionsByAssignment[$assignmentId][$studentId] ?? null;
                    
                    $status = 'Not Submitted';
                    $submissionDate = null;
                    
                    if ($submission) {
                        $status = ucfirst(strtolower($submission['Status']));
                        if ($status === 'Late') {
                            $status = 'Late';
                        } elseif ($status === 'Submitted') {
                            $status = 'Submitted';
                        }
                        $submissionDate = $submission['Submission_Date'];
                    }
                    
                    $studentSubmissions[] = [
                        'student_id' => $studentId,
                        'student_name' => $student['Student_Name'],
                        'submission_status' => $status,
                        'submission_date' => $submissionDate
                    ];
                }
                
                $reportData[$classId]['assignments'][] = [
                    'assignment_id' => $assignmentId,
                    'title' => $assignment['Title'],
                    'subject' => $assignment['Course_Name'],
                    'deadline' => $assignment['Due_Date'],
                    'students' => $studentSubmissions
                ];
            }

            $reportData = array_values($reportData);
            
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'data' => $reportData
            ]);
            ob_end_flush();
            break;
            
        default:
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
            ob_end_flush();
            break;
    }
} catch (Exception $e) {
    error_log("Error in quick-reports-ajax: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage()
    ]);
    ob_end_flush();
} catch (Error $e) {
    error_log("Fatal error in quick-reports-ajax: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Fatal error: ' . $e->getMessage(),
        'error' => $e->getMessage()
    ]);
    ob_end_flush();
}
ob_end_flush();
?>

