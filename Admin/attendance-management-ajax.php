<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/activity-logger.php';

requireUserType('admin');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$action = $_POST['action'] ?? '';
$currentAdminId = getCurrentUserId();

if (!$currentAdminId) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    if ($action === 'loadClasses') {
        
        $date = $_POST['date'] ?? date('Y-m-d');
        $grade = $_POST['grade'] ?? 'all';
        $section = $_POST['section'] ?? 'all';
        $statusFilter = $_POST['statusFilter'] ?? 'all';

        $classWhere = '';
        $classParams = [];
        
        if ($grade !== 'all' || $section !== 'all') {
            $classConditions = [];
            if ($grade !== 'all') {
                $classConditions[] = 'c.Grade_Level = ?';
                $classParams[] = $grade;
            }
            if ($section !== 'all') {
                $classConditions[] = 'UPPER(c.Section) = UPPER(?)';
                $classParams[] = $section;
            }
            $classWhere = ' WHERE ' . implode(' AND ', $classConditions);
        }

        $query = "
            SELECT 
                c.Class_ID,
                c.Name as Class_Name,
                c.Grade_Level,
                c.Section,
                COUNT(DISTINCT s.Student_ID) as total_students,
                COUNT(DISTINCT CASE WHEN a.Status = 'Present' THEN s.Student_ID END) as present_count,
                COUNT(DISTINCT CASE WHEN a.Status = 'Absent' THEN s.Student_ID END) as absent_count,
                COUNT(DISTINCT CASE WHEN a.Status = 'Late' THEN s.Student_ID END) as late_count,
                COUNT(DISTINCT CASE WHEN a.Status = 'Excused' THEN s.Student_ID END) as excused_count,
                COUNT(DISTINCT CASE WHEN a.Attendance_ID IS NOT NULL THEN s.Student_ID END) as recorded_count
            FROM class c
            LEFT JOIN student s ON c.Class_ID = s.Class_ID AND s.Status = 'active'
            LEFT JOIN attendance a ON s.Student_ID = a.Student_ID 
                AND a.Class_ID = c.Class_ID 
                AND a.Date = ?
            " . ($classWhere ?: '') . "
            GROUP BY c.Class_ID, c.Name, c.Grade_Level, c.Section
            ORDER BY c.Grade_Level ASC, c.Section ASC
        ";
        
        $params = array_merge([$date], $classParams);
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($statusFilter !== 'all') {
            $filteredClasses = [];
            foreach ($classes as $class) {
                $statusField = strtolower($statusFilter) . '_count';
                if (isset($class[$statusField]) && $class[$statusField] > 0) {
                    $filteredClasses[] = $class;
                }
            }
            $classes = $filteredClasses;
        }
        
        echo json_encode(['success' => true, 'classes' => $classes]);
        
    } elseif ($action === 'loadStudents') {
        
        $classId = intval($_POST['classId'] ?? 0);
        $date = $_POST['date'] ?? date('Y-m-d');
        
        if ($classId <= 0) {
            throw new Exception('Invalid class ID');
        }
        
        $query = "
            SELECT 
                s.Student_ID,
                s.Student_Code,
                s.NameEn,
                s.NameAr,
                s.Class_ID,
                a.Attendance_ID,
                a.Status,
                a.Notes,
                a.Created_At as Last_Updated,
                CASE 
                    WHEN a.Attendance_ID IS NOT NULL THEN 1 
                    ELSE 0 
                END as has_record
            FROM student s
            LEFT JOIN attendance a ON s.Student_ID = a.Student_ID 
                AND a.Class_ID = s.Class_ID 
                AND a.Date = ?
            WHERE s.Class_ID = ? AND s.Status = 'active'
            ORDER BY s.Student_Code ASC, s.NameEn ASC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$date, $classId]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'students' => $students]);
        
    } elseif ($action === 'saveAttendance') {
        
        $studentId = intval($_POST['studentId'] ?? 0);
        $classId = intval($_POST['classId'] ?? 0);
        $date = $_POST['date'] ?? date('Y-m-d');
        $status = ucfirst(strtolower($_POST['status'] ?? 'Present'));
        $notes = trim($_POST['notes'] ?? '');

        if ($studentId <= 0 || $classId <= 0) {
            throw new Exception('Invalid student or class ID');
        }
        
        $allowedStatuses = ['Present', 'Absent', 'Late', 'Excused'];
        if (!in_array($status, $allowedStatuses)) {
            throw new Exception('Invalid attendance status');
        }

        $stmt = $pdo->prepare("SELECT Student_ID FROM student WHERE Student_ID = ? AND Class_ID = ?");
        $stmt->execute([$studentId, $classId]);
        if (!$stmt->fetch()) {
            throw new Exception('Student does not belong to this class');
        }

        $stmt = $pdo->prepare("
            SELECT Attendance_ID 
            FROM attendance 
            WHERE Student_ID = ? AND Class_ID = ? AND Date = ?
        ");
        $stmt->execute([$studentId, $classId, $date]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            
            $stmt = $pdo->prepare("
                UPDATE attendance 
                SET Status = ?, Notes = ?, Recorded_By = ?, Created_At = NOW()
                WHERE Attendance_ID = ?
            ");
            $stmt->execute([$status, $notes ?: null, $currentAdminId, $existing['Attendance_ID']]);
            $attendanceId = $existing['Attendance_ID'];
        } else {
            
            $stmt = $pdo->prepare("
                INSERT INTO attendance (Date, Status, Notes, Student_ID, Class_ID, Recorded_By, Created_At)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$date, $status, $notes ?: null, $studentId, $classId, $currentAdminId]);
            $attendanceId = $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare("
            SELECT 
                Attendance_ID,
                Status,
                Notes,
                Created_At as Last_Updated
            FROM attendance 
            WHERE Attendance_ID = ?
        ");
        $stmt->execute([$attendanceId]);
        $updated = $stmt->fetch(PDO::FETCH_ASSOC);

        $studentName = '';
        $stmt = $pdo->prepare("SELECT NameEn, NameAr FROM student WHERE Student_ID = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($student) {
            $studentName = $student['NameEn'] . ($student['NameAr'] ? ' (' . $student['NameAr'] . ')' : '');
        }
        
        logAdminAction(
            $pdo,
            $existing ? 'update' : 'create',
            'attendance',
            $attendanceId,
            "Attendance {$status} for student: {$studentName} on {$date}",
            'attendance',
            $existing ? ['status' => ['old' => $existing['Status'] ?? 'N/A', 'new' => $status]] : null
        );
        
        echo json_encode([
            'success' => true, 
            'message' => 'Attendance saved successfully',
            'attendance' => $updated
        ]);
        
    } elseif ($action === 'bulkMarkAttendance') {
        
        $classId = intval($_POST['classId'] ?? 0);
        $date = $_POST['date'] ?? date('Y-m-d');
        $status = ucfirst(strtolower($_POST['status'] ?? 'Present'));
        
        if ($classId <= 0) {
            throw new Exception('Invalid class ID');
        }
        
        $allowedStatuses = ['Present', 'Absent', 'Late', 'Excused'];
        if (!in_array($status, $allowedStatuses)) {
            throw new Exception('Invalid attendance status');
        }

        $stmt = $pdo->prepare("
            SELECT Student_ID 
            FROM student 
            WHERE Class_ID = ? AND Status = 'active'
        ");
        $stmt->execute([$classId]);
        $students = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($students)) {
            throw new Exception('No active students found in this class');
        }
        
        $pdo->beginTransaction();
        
        try {
            $updated = 0;
            $inserted = 0;
            
            foreach ($students as $studentId) {
                
                $stmt = $pdo->prepare("
                    SELECT Attendance_ID 
                    FROM attendance 
                    WHERE Student_ID = ? AND Class_ID = ? AND Date = ?
                ");
                $stmt->execute([$studentId, $classId, $date]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    $stmt = $pdo->prepare("
                        UPDATE attendance 
                        SET Status = ?, Recorded_By = ?, Created_At = NOW()
                        WHERE Attendance_ID = ?
                    ");
                    $stmt->execute([$status, $currentAdminId, $existing['Attendance_ID']]);
                    $updated++;
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO attendance (Date, Status, Student_ID, Class_ID, Recorded_By, Created_At)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$date, $status, $studentId, $classId, $currentAdminId]);
                    $inserted++;
                }
            }
            
            $pdo->commit();

            $stmt = $pdo->prepare("SELECT Name FROM class WHERE Class_ID = ?");
            $stmt->execute([$classId]);
            $class = $stmt->fetch(PDO::FETCH_ASSOC);
            $className = $class['Name'] ?? "Class ID: {$classId}";
            
            logAdminAction(
                $pdo,
                'bulk_update',
                'attendance',
                null,
                "Bulk marked {$status} for {$className} on {$date} ({$inserted} new, {$updated} updated)",
                'attendance'
            );
            
            echo json_encode([
                'success' => true,
                'message' => "Bulk attendance saved: {$inserted} new records, {$updated} updated",
                'inserted' => $inserted,
                'updated' => $updated
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        
    } elseif ($action === 'getDailyReport') {
        
        $date = $_POST['date'] ?? date('Y-m-d');
        $grade = $_POST['grade'] ?? 'all';
        $section = $_POST['section'] ?? 'all';

        $classWhere = '';
        $classParams = [];
        
        if ($grade !== 'all' || $section !== 'all') {
            $classConditions = [];
            if ($grade !== 'all') {
                $classConditions[] = 'c.Grade_Level = ?';
                $classParams[] = $grade;
            }
            if ($section !== 'all') {
                $classConditions[] = 'UPPER(c.Section) = UPPER(?)';
                $classParams[] = $section;
            }
            $classWhere = ' AND ' . implode(' AND ', $classConditions);
        }
        
        $query = "
            SELECT 
                c.Class_ID,
                c.Name as Class_Name,
                c.Grade_Level,
                c.Section,
                COUNT(DISTINCT s.Student_ID) as total_students,
                COUNT(DISTINCT CASE WHEN a.Status = 'Present' THEN s.Student_ID END) as present,
                COUNT(DISTINCT CASE WHEN a.Status = 'Absent' THEN s.Student_ID END) as absent,
                COUNT(DISTINCT CASE WHEN a.Status = 'Late' THEN s.Student_ID END) as late,
                COUNT(DISTINCT CASE WHEN a.Status = 'Excused' THEN s.Student_ID END) as excused,
                COUNT(DISTINCT a.Attendance_ID) as recorded_count
            FROM class c
            LEFT JOIN student s ON c.Class_ID = s.Class_ID AND s.Status = 'active'
            LEFT JOIN attendance a ON s.Student_ID = a.Student_ID 
                AND a.Class_ID = c.Class_ID 
                AND a.Date = ?
            WHERE 1=1 " . $classWhere . "
            GROUP BY c.Class_ID, c.Name, c.Grade_Level, c.Section
            ORDER BY c.Grade_Level ASC, c.Section ASC
        ";
        
        $params = array_merge([$date], $classParams);
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $report = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totals = [
            'total_students' => 0,
            'present' => 0,
            'absent' => 0,
            'late' => 0,
            'excused' => 0,
            'recorded_count' => 0
        ];
        
        foreach ($report as $row) {
            $totals['total_students'] += intval($row['total_students']);
            $totals['present'] += intval($row['present']);
            $totals['absent'] += intval($row['absent']);
            $totals['late'] += intval($row['late']);
            $totals['excused'] += intval($row['excused']);
            $totals['recorded_count'] += intval($row['recorded_count']);
        }
        
        echo json_encode([
            'success' => true,
            'date' => $date,
            'report' => $report,
            'totals' => $totals
        ]);
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (PDOException $e) {
    error_log("Database error in attendance-management-ajax.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error in attendance-management-ajax.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

