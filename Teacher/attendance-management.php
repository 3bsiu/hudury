<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-teacher.php';
require_once __DIR__ . '/../includes/notification-email-helper.php';

requireUserType('teacher');

$currentTeacherId = getCurrentUserId();
$currentTeacher = getCurrentUserData($pdo);
$teacherName = $_SESSION['user_name'] ?? 'Teacher';

if (!$currentTeacherId || !is_numeric($currentTeacherId)) {
    error_log("Error: Invalid teacher ID: " . $currentTeacherId);
    header("Location: teacher-dashboard.php?error=invalid_teacher");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    $action = $_GET['action'];
    $input = json_decode(file_get_contents('php://input'), true);
    
    try {
        switch ($action) {
            case 'save_attendance':
                
                if (!isset($input['date']) || !isset($input['classId']) || !isset($input['attendance'])) {
                    throw new Exception('Missing required parameters');
                }
                
                $date = $input['date'];
                $classId = intval($input['classId']);
                $attendanceData = $input['attendance'];

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
                
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT NameEn, NameAr FROM teacher WHERE Teacher_ID = ?");
                $stmt->execute([$currentTeacherId]);
                $teacherData = $stmt->fetch(PDO::FETCH_ASSOC);
                $teacherName = $teacherData['NameEn'] ?? $teacherData['NameAr'] ?? 'Teacher';

                $absenceNotifications = [];
                
                foreach ($attendanceData as $att) {
                    $studentId = intval($att['studentId']);
                    $status = ucfirst(strtolower($att['status'])); 
                    $notes = $att['note'] ?? null;
                    $previousStatus = null;

                    if (!in_array($status, ['Present', 'Absent', 'Late', 'Excused'])) {
                        throw new Exception("Invalid status: " . $status);
                    }

                    $stmt = $pdo->prepare("
                        SELECT Attendance_ID, Status
                        FROM attendance 
                        WHERE Student_ID = ? AND Class_ID = ? AND Date = ?
                    ");
                    $stmt->execute([$studentId, $classId, $date]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        $previousStatus = $existing['Status'];
                        
                        $stmt = $pdo->prepare("
                            UPDATE attendance 
                            SET Status = ?, Notes = ?, Recorded_By = ?, Created_At = NOW()
                            WHERE Attendance_ID = ?
                        ");
                        $stmt->execute([$status, $notes, $currentTeacherId, $existing['Attendance_ID']]);
                    } else {
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO attendance (Date, Status, Notes, Student_ID, Class_ID, Recorded_By, Created_At)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([$date, $status, $notes, $studentId, $classId, $currentTeacherId]);
                    }

                    if ($status === 'Absent' && ($previousStatus !== 'Absent' || !$existing)) {
                        $absenceNotifications[] = [
                            'studentId' => $studentId,
                            'date' => $date,
                            'classId' => $classId
                        ];
                    }
                }

                $pdo->commit();

                $notificationSummary = [
                    'notifications_sent' => 0,
                    'emails_sent' => 0,
                    'errors' => []
                ];
                
                foreach ($absenceNotifications as $notifData) {
                    error_log("Sending absence notification for student ID: {$notifData['studentId']}, date: {$notifData['date']}, class: {$notifData['classId']}");
                    
                    $notificationResult = notifyParentOfAbsence($pdo, $notifData['studentId'], $notifData['date'], $notifData['classId'], $currentTeacherId, $teacherName);

                    if ($notificationResult) {
                        $notificationSummary['notifications_sent'] += $notificationResult['notifications_sent'] ?? 0;
                        $notificationSummary['emails_sent'] += $notificationResult['emails_sent'] ?? 0;
                        if (!empty($notificationResult['errors'])) {
                            $notificationSummary['errors'] = array_merge($notificationSummary['errors'], $notificationResult['errors']);
                        }
                    }
                }

                if (count($absenceNotifications) > 0) {
                    error_log("Absence notification summary: " . json_encode($notificationSummary));
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Attendance saved successfully',
                    'notifications' => $notificationSummary
                ]);
                exit();
                
            case 'update_status':

                if (!isset($input['studentId']) || !isset($input['date']) || !isset($input['status']) || !isset($input['classId'])) {
                    throw new Exception('Missing required parameters');
                }
                
                $studentId = intval($input['studentId']);
                $date = $input['date'];
                $status = ucfirst(strtolower($input['status']));
                $classId = intval($input['classId']);

                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM teacher_class_course 
                    WHERE Teacher_ID = ? AND Class_ID = ?
                ");
                $stmt->execute([$currentTeacherId, $classId]);
                $hasAccess = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
                
                if (!$hasAccess) {
                    throw new Exception('Unauthorized');
                }

                $stmt = $pdo->prepare("
                    SELECT Attendance_ID, Status
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
                    $stmt->execute([$status, $currentTeacherId, $existing['Attendance_ID']]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO attendance (Date, Status, Student_ID, Class_ID, Recorded_By, Created_At)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$date, $status, $studentId, $classId, $currentTeacherId]);
                }

                echo json_encode(['success' => true]);
                exit();
                
            case 'update_note':
                if (!isset($input['studentId']) || !isset($input['date']) || !isset($input['note']) || !isset($input['classId'])) {
                    throw new Exception('Missing required parameters');
                }
                
                $studentId = intval($input['studentId']);
                $date = $input['date'];
                $note = $input['note'];
                $classId = intval($input['classId']);

                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM teacher_class_course 
                    WHERE Teacher_ID = ? AND Class_ID = ?
                ");
                $stmt->execute([$currentTeacherId, $classId]);
                $hasAccess = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
                
                if (!$hasAccess) {
                    throw new Exception('Unauthorized');
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
                        SET Notes = ?
                        WHERE Attendance_ID = ?
                    ");
                    $stmt->execute([$note, $existing['Attendance_ID']]);
                } else {
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO attendance (Date, Status, Notes, Student_ID, Class_ID, Recorded_By, Created_At)
                        VALUES (?, 'Present', ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$date, $note, $studentId, $classId, $currentTeacherId]);
                }
                
                echo json_encode(['success' => true]);
                exit();
                
            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Attendance API Error: " . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    $action = $_GET['action'];
    
    try {
        switch ($action) {
            case 'get_attendance':
                $date = $_GET['date'] ?? date('Y-m-d');
                $classId = intval($_GET['classId'] ?? 0);
                
                if (!$classId) {
                    throw new Exception('Class ID is required');
                }

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

                $stmt = $pdo->prepare("
                    SELECT s.Student_ID, s.Student_Code, s.NameEn, s.NameAr, s.Class_ID
                    FROM student s
                    WHERE s.Class_ID = ? AND (s.Status = 'active' OR s.Status IS NULL)
                    ORDER BY s.NameEn ASC
                ");
                $stmt->execute([$classId]);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $stmt = $pdo->prepare("
                    SELECT a.*, s.Student_Code, s.NameEn
                    FROM attendance a
                    INNER JOIN student s ON a.Student_ID = s.Student_ID
                    WHERE a.Class_ID = ? AND a.Date = ?
                ");
                $stmt->execute([$classId, $date]);
                $attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $attendanceMap = [];
                foreach ($attendanceRecords as $att) {
                    $attendanceMap[$att['Student_ID']] = $att;
                }

                $result = [];
                foreach ($students as $student) {
                    $att = $attendanceMap[$student['Student_ID']] ?? null;
                    $result[] = [
                        'id' => $student['Student_ID'],
                        'studentId' => $student['Student_Code'],
                        'name' => $student['NameEn'],
                        'status' => strtolower($att['Status'] ?? 'present'),
                        'time' => $att ? ($att['Created_At'] ? date('H:i', strtotime($att['Created_At'])) : '-') : '-',
                        'note' => $att['Notes'] ?? ''
                    ];
                }
                
                echo json_encode(['success' => true, 'data' => $result]);
                exit();
                
            case 'get_history':
                $classId = intval($_GET['classId'] ?? 0);
                $limit = intval($_GET['limit'] ?? 50);
                
                if (!$classId) {
                    throw new Exception('Class ID is required');
                }

                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM teacher_class_course 
                    WHERE Teacher_ID = ? AND Class_ID = ?
                ");
                $stmt->execute([$currentTeacherId, $classId]);
                $hasAccess = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
                
                if (!$hasAccess) {
                    throw new Exception('Unauthorized');
                }

                $stmt = $pdo->prepare("
                    SELECT a.*, s.NameEn, s.Student_Code,
                           t.NameEn as Teacher_Name
                    FROM attendance a
                    INNER JOIN student s ON a.Student_ID = s.Student_ID
                    LEFT JOIN teacher t ON a.Recorded_By = t.Teacher_ID
                    WHERE a.Class_ID = ?
                    ORDER BY a.Created_At DESC
                    LIMIT ?
                ");
                $stmt->execute([$classId, $limit]);
                $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $result = [];
                foreach ($history as $h) {
                    $result[] = [
                        'date' => $h['Date'],
                        'time' => $h['Created_At'] ? date('H:i:s', strtotime($h['Created_At'])) : '',
                        'student' => $h['NameEn'],
                        'status' => strtolower($h['Status']),
                        'changedBy' => $h['Teacher_Name'] ?? 'System',
                        'note' => $h['Notes'] ?? ''
                    ];
                }
                
                echo json_encode(['success' => true, 'data' => $result]);
                exit();
                
            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        error_log("Attendance GET Error: " . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

$teacherClasses = [];
$teacherCourses = [];

try {
    
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.Class_ID, c.Name, c.Grade_Level, c.Section, c.Academic_Year,
               GROUP_CONCAT(DISTINCT co.Course_Name ORDER BY co.Course_Name SEPARATOR ', ') as Courses
        FROM teacher_class_course tcc
        JOIN class c ON tcc.Class_ID = c.Class_ID
        LEFT JOIN course co ON tcc.Course_ID = co.Course_ID
        WHERE tcc.Teacher_ID = ?
        GROUP BY c.Class_ID, c.Name, c.Grade_Level, c.Section, c.Academic_Year
        ORDER BY c.Grade_Level ASC, c.Section ASC
    ");
    $stmt->execute([$currentTeacherId]);
    $teacherClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT DISTINCT co.*
        FROM teacher_class_course tcc
        JOIN course co ON tcc.Course_ID = co.Course_ID
        WHERE tcc.Teacher_ID = ?
        ORDER BY co.Course_Name ASC
    ");
    $stmt->execute([$currentTeacherId]);
    $teacherCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching teacher classes: " . $e->getMessage());
    $teacherClasses = [];
    $teacherCourses = [];
}

$teacherDisplayName = $currentTeacher['NameEn'] ?? $teacherName;
$teacherSubject = !empty($teacherCourses) ? $teacherCourses[0]['Course_Name'] ?? 'Teacher' : 'Teacher';
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Management - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        .attendance-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .summary-card {
            background: linear-gradient(135deg, #FFE5E5, #E5F3FF);
            padding: 1.5rem;
            border-radius: 20px;
            text-align: center;
        }
        .summary-value {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--primary-color);
            margin: 0.5rem 0;
        }
        .summary-label {
            color: #666;
            font-weight: 600;
        }
        .progress-chart {
            height: 20px;
            background: #FFE5E5;
            border-radius: 20px;
            overflow: hidden;
            margin-top: 1rem;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #FF6B9D, #6BCB77);
            border-radius: 20px;
            transition: width 1s;
        }
        .attendance-note {
            font-size: 0.85rem;
            color: #666;
            font-style: italic;
            margin-top: 0.3rem;
        }
        .note-input {
            width: 100%;
            padding: 0.5rem;
            border: 2px solid #FFE5E5;
            border-radius: 10px;
            font-size: 0.85rem;
            margin-top: 0.5rem;
        }
        .back-button {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            text-decoration: none;
        }
        .back-button:hover {
            background: rgba(255,255,255,0.3);
            transform: translateX(-5px);
        }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #666;
        }
        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        .table-container {
            overflow-x: auto;
        }
        @media (max-width: 768px) {
            .attendance-summary {
                grid-template-columns: repeat(2, 1fr);
            }
            .table-container {
                font-size: 0.85rem;
            }
            .data-table th,
            .data-table td {
                padding: 0.5rem;
            }
        }
    </style>
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>
    
    <div class="main-content">

    <div id="notificationContainer"></div>

    <div class="dashboard-container">
        
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">📅</span>
                <span data-en="Attendance Management" data-ar="إدارة الحضور">Attendance Management</span>
            </h1>
            <p class="page-subtitle" data-en="Track and manage student attendance" data-ar="تتبع وإدارة حضور الطلاب">Track and manage student attendance</p>
        </div>

        <?php if (empty($teacherClasses)): ?>
            
            <div class="card">
                <div class="empty-state">
                    <div class="empty-state-icon">📚</div>
                    <h2 data-en="No Classes Assigned" data-ar="لا توجد فصول معينة">No Classes Assigned</h2>
                    <p data-en="You don't have any classes assigned yet. Please contact the administrator to assign classes to your account." data-ar="ليس لديك أي فصول معينة بعد. يرجى الاتصال بالإدارة لتعيين الفصول لحسابك.">
                        You don't have any classes assigned yet. Please contact the administrator to assign classes to your account.
                    </p>
                </div>
            </div>
        <?php else: ?>
            
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <span class="card-icon">📊</span>
                        <span data-en="Attendance Summary" data-ar="ملخص الحضور">Attendance Summary</span>
                    </h2>
                    <div>
                        <input type="date" id="attendanceDate" value="<?php echo date('Y-m-d'); ?>" onchange="loadAttendance()" style="padding: 0.5rem; border: 2px solid #FFE5E5; border-radius: 10px;">
                    </div>
                </div>
                <div class="attendance-summary">
                    <div class="summary-card">
                        <div class="summary-label" data-en="Present" data-ar="حاضر">Present</div>
                        <div class="summary-value" id="presentCount">0</div>
                        <div class="progress-chart">
                            <div class="progress-fill" id="presentProgress" style="width: 0%"></div>
                        </div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label" data-en="Absent" data-ar="غائب">Absent</div>
                        <div class="summary-value" id="absentCount">0</div>
                        <div class="progress-chart">
                            <div class="progress-fill" id="absentProgress" style="width: 0%; background: linear-gradient(90deg, #FF6B9D, #C44569);"></div>
                        </div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label" data-en="Late" data-ar="متأخر">Late</div>
                        <div class="summary-value" id="lateCount">0</div>
                        <div class="progress-chart">
                            <div class="progress-fill" id="lateProgress" style="width: 0%; background: linear-gradient(90deg, #FFD93D, #FFC107);"></div>
                        </div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label" data-en="Excused" data-ar="معذور">Excused</div>
                        <div class="summary-value" id="excusedCount">0</div>
                        <div class="progress-chart">
                            <div class="progress-fill" id="excusedProgress" style="width: 0%; background: linear-gradient(90deg, #A8E6CF, #6BCB77);"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="action-bar">
                    <div class="form-row" style="flex: 1;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label data-en="Class" data-ar="الفصل">Class</label>
                            <select id="classFilter" onchange="loadAttendance()">
                                <option value="" data-en="Select a class" data-ar="اختر فصلاً">Select a class</option>
                                <?php foreach ($teacherClasses as $class): ?>
                                    <option value="<?php echo $class['Class_ID']; ?>" data-courses="<?php echo htmlspecialchars($class['Courses'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($class['Name']); ?>
                                        <?php if (!empty($class['Courses'])): ?>
                                            (<?php echo htmlspecialchars($class['Courses']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label data-en="Filter by Status" data-ar="تصفية حسب الحالة">Filter by Status</label>
                            <select id="statusFilter" onchange="filterAttendance()">
                                <option value="all" data-en="All" data-ar="الكل">All</option>
                                <option value="present" data-en="Present" data-ar="حاضر">Present</option>
                                <option value="absent" data-en="Absent" data-ar="غائب">Absent</option>
                                <option value="late" data-en="Late" data-ar="متأخر">Late</option>
                                <option value="excused" data-en="Excused" data-ar="معذور">Excused</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="search-filter-bar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="studentSearch" placeholder="Search students..." data-placeholder-en="Search students..." data-placeholder-ar="البحث عن الطلاب..." oninput="filterAttendance()">
                    </div>
                    <button class="btn btn-primary" onclick="bulkMarkPresent()">
                        <i class="fas fa-check-double"></i>
                        <span data-en="Mark All Present" data-ar="تسجيل الكل حاضر">Mark All Present</span>
                    </button>
                    <button class="btn btn-secondary" onclick="undoLastChange()">
                        <i class="fas fa-undo"></i>
                        <span data-en="Undo" data-ar="تراجع">Undo</span>
                    </button>
                    <button class="btn btn-secondary" onclick="saveAttendance()">
                        <i class="fas fa-save"></i>
                        <span data-en="Save" data-ar="حفظ">Save</span>
                    </button>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <span class="card-icon">📋</span>
                        <span data-en="Student Attendance" data-ar="حضور الطلاب">Student Attendance</span>
                    </h2>
                </div>
                <div class="table-container">
                    <table class="data-table" role="table" aria-label="Student Attendance">
                        <thead>
                            <tr>
                                <th data-en="Student Name" data-ar="اسم الطالب">Student Name</th>
                                <th data-en="Student ID" data-ar="رقم الطالب">Student ID</th>
                                <th data-en="Status" data-ar="الحالة">Status</th>
                                <th data-en="Time" data-ar="الوقت">Time</th>
                                <th data-en="Note" data-ar="ملاحظة">Note</th>
                                <th data-en="Actions" data-ar="الإجراءات">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="attendanceTableBody">
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 2rem; color: #666;">
                                    <div data-en="Please select a class to view attendance" data-ar="يرجى اختيار فصل لعرض الحضور">Please select a class to view attendance</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <span class="card-icon">📜</span>
                        <span data-en="Attendance History" data-ar="سجل الحضور">Attendance History</span>
                    </h2>
                </div>
                <div class="table-container">
                    <table class="data-table" role="table" aria-label="Attendance History">
                        <thead>
                            <tr>
                                <th data-en="Date & Time" data-ar="التاريخ والوقت">Date & Time</th>
                                <th data-en="Student" data-ar="الطالب">Student</th>
                                <th data-en="Status" data-ar="الحالة">Status</th>
                                <th data-en="Changed By" data-ar="تم التغيير بواسطة">Changed By</th>
                                <th data-en="Note" data-ar="ملاحظة">Note</th>
                            </tr>
                        </thead>
                        <tbody id="historyTableBody">
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 2rem; color: #666;">
                                    <div data-en="No history available" data-ar="لا يوجد سجل متاح">No history available</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="script.js"></script>
    <script>
        
        let currentAttendance = [];
        let originalAttendance = [];
        let changeHistory = [];
        let currentClassId = null;
        let currentDate = null;

        async function loadAttendance() {
            const dateInput = document.getElementById('attendanceDate');
            const classSelect = document.getElementById('classFilter');
            
            if (!dateInput || !classSelect) return;
            
            currentDate = dateInput.value || new Date().toISOString().split('T')[0];
            currentClassId = classSelect.value;
            
            if (!currentClassId) {
                document.getElementById('attendanceTableBody').innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 2rem; color: #666;">
                            <div data-en="Please select a class to view attendance" data-ar="يرجى اختيار فصل لعرض الحضور">Please select a class to view attendance</div>
                        </td>
                    </tr>
                `;
                return;
            }
            
            try {
                const response = await fetch(`attendance-management.php?action=get_attendance&date=${currentDate}&classId=${currentClassId}`);
                const result = await response.json();
                
                if (result.success) {
                    currentAttendance = result.data;
                    originalAttendance = JSON.parse(JSON.stringify(currentAttendance));
                    renderAttendance();
                    updateSummary();
                    loadHistory();
                } else {
                    showNotification(result.error || 'Failed to load attendance', 'error');
                }
            } catch (error) {
                console.error('Error loading attendance:', error);
                showNotification('Error loading attendance data', 'error');
            }
        }

        async function loadHistory() {
            if (!currentClassId) return;
            
            try {
                const response = await fetch(`attendance-management.php?action=get_history&classId=${currentClassId}&limit=50`);
                const result = await response.json();
                
                if (result.success) {
                    renderHistory(result.data);
                }
            } catch (error) {
                console.error('Error loading history:', error);
            }
        }

        function renderAttendance() {
            const tbody = document.getElementById('attendanceTableBody');
            const searchTerm = document.getElementById('studentSearch')?.value.toLowerCase() || '';
            const statusFilter = document.getElementById('statusFilter')?.value || 'all';
            
            if (!currentAttendance || currentAttendance.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 2rem; color: #666;">
                            <div data-en="No students found" data-ar="لا يوجد طلاب">No students found</div>
                        </td>
                    </tr>
                `;
                return;
            }
            
            const filtered = currentAttendance.filter(att => {
                const matchesSearch = att.name.toLowerCase().includes(searchTerm) || att.studentId.includes(searchTerm);
                const matchesStatus = statusFilter === 'all' || att.status === statusFilter;
                return matchesSearch && matchesStatus;
            });
            
            if (filtered.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 2rem; color: #666;">
                            <div data-en="No students match the filter criteria" data-ar="لا يوجد طلاب يطابقون معايير التصفية">No students match the filter criteria</div>
                        </td>
                    </tr>
                `;
                return;
            }
            
            tbody.innerHTML = filtered.map(att => `
                <tr>
                    <td>${escapeHtml(att.name)}</td>
                    <td>${escapeHtml(att.studentId)}</td>
                    <td>
                        <span class="status-badge status-${att.status}" onclick="toggleStatus(${att.id})" role="button" tabindex="0" onkeypress="if(event.key==='Enter') toggleStatus(${att.id})" aria-label="Toggle status for ${escapeHtml(att.name)}">
                            ${getStatusLabel(att.status)}
                        </span>
                    </td>
                    <td>${att.time || '-'}</td>
                    <td>
                        ${att.note ? `<div class="attendance-note">${escapeHtml(att.note)}</div>` : ''}
                        <input type="text" 
                               class="note-input" 
                               placeholder="${currentLanguage === 'en' ? 'Add note...' : 'إضافة ملاحظة...'}"
                               value="${escapeHtml(att.note || '')}"
                               data-student-id="${att.id}"
                               onchange="updateNote(${att.id}, this.value)"
                               data-placeholder-en="Add note..." 
                               data-placeholder-ar="إضافة ملاحظة...">
                    </td>
                    <td>
                        <button class="btn btn-small btn-secondary" onclick="viewStudentHistory(${att.id})" data-en="History" data-ar="السجل">History</button>
                    </td>
                </tr>
            `).join('');
        }

        function getStatusLabel(status) {
            const labels = {
                present: { en: 'Present', ar: 'حاضر' },
                absent: { en: 'Absent', ar: 'غائب' },
                late: { en: 'Late', ar: 'متأخر' },
                excused: { en: 'Excused', ar: 'معذور' }
            };
            return labels[status] ? labels[status][currentLanguage] : status;
        }

        function toggleStatus(studentId) {
            if (!currentClassId || !currentDate) {
                showNotification(currentLanguage === 'en' ? 'Please select a class and date first' : 'يرجى اختيار فصل وتاريخ أولاً', 'warning');
                return;
            }
            
            const attendance = currentAttendance.find(a => a.id === studentId);
            if (!attendance) return;
            
            const statuses = ['present', 'absent', 'late', 'excused'];
            const currentIndex = statuses.indexOf(attendance.status);
            const nextIndex = (currentIndex + 1) % statuses.length;
            const newStatus = statuses[nextIndex];

            changeHistory.push({
                studentId: studentId,
                oldStatus: attendance.status,
                newStatus: newStatus,
                timestamp: new Date().toISOString()
            });

            attendance.status = newStatus;
            attendance.time = newStatus === 'absent' ? '-' : new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });

            renderAttendance();
            updateSummary();
        }

        function updateNote(studentId, note) {
            if (!currentClassId || !currentDate) return;
            
            const attendance = currentAttendance.find(a => a.id === studentId);
            if (attendance) {
                attendance.note = note;
            }
            
        }

        function updateSummary() {
            if (!currentAttendance || currentAttendance.length === 0) {
                document.getElementById('presentCount').textContent = '0';
                document.getElementById('absentCount').textContent = '0';
                document.getElementById('lateCount').textContent = '0';
                document.getElementById('excusedCount').textContent = '0';
                return;
            }
            
            const present = currentAttendance.filter(a => a.status === 'present').length;
            const absent = currentAttendance.filter(a => a.status === 'absent').length;
            const late = currentAttendance.filter(a => a.status === 'late').length;
            const excused = currentAttendance.filter(a => a.status === 'excused').length;
            const total = currentAttendance.length;
            
            document.getElementById('presentCount').textContent = present;
            document.getElementById('absentCount').textContent = absent;
            document.getElementById('lateCount').textContent = late;
            document.getElementById('excusedCount').textContent = excused;
            
            if (total > 0) {
                document.getElementById('presentProgress').style.width = `${(present / total) * 100}%`;
                document.getElementById('absentProgress').style.width = `${(absent / total) * 100}%`;
                document.getElementById('lateProgress').style.width = `${(late / total) * 100}%`;
                document.getElementById('excusedProgress').style.width = `${(excused / total) * 100}%`;
            } else {
                document.getElementById('presentProgress').style.width = '0%';
                document.getElementById('absentProgress').style.width = '0%';
                document.getElementById('lateProgress').style.width = '0%';
                document.getElementById('excusedProgress').style.width = '0%';
            }
        }

        function renderHistory(history) {
            const tbody = document.getElementById('historyTableBody');
            
            if (!history || history.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 2rem; color: #666;">
                            <div data-en="No history available" data-ar="لا يوجد سجل متاح">No history available</div>
                        </td>
                    </tr>
                `;
                return;
            }
            
            tbody.innerHTML = history.map(entry => `
                <tr>
                    <td>${formatDate(entry.date)} ${entry.time || ''}</td>
                    <td>${escapeHtml(entry.student)}</td>
                    <td>${getStatusLabel(entry.status)}</td>
                    <td>${escapeHtml(entry.changedBy)}</td>
                    <td>${escapeHtml(entry.note || '-')}</td>
                </tr>
            `).join('');
        }

        function filterAttendance() {
            renderAttendance();
        }

        async function bulkMarkPresent() {
            if (!currentClassId || !currentDate) {
                showNotification(currentLanguage === 'en' ? 'Please select a class and date first' : 'يرجى اختيار فصل وتاريخ أولاً', 'warning');
                return;
            }
            
            if (confirm(currentLanguage === 'en' ? 'Mark all students as present?' : 'تسجيل جميع الطلاب كحاضرين؟')) {
                const updates = [];
                
                currentAttendance.forEach(att => {
                    if (att.status !== 'present') {
                        changeHistory.push({
                            studentId: att.id,
                            oldStatus: att.status,
                            newStatus: 'present',
                            timestamp: new Date().toISOString()
                        });
                        att.status = 'present';
                        att.time = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
                        updates.push({
                            studentId: att.id,
                            status: 'present'
                        });
                    }
                });

                try {
                    const response = await fetch('attendance-management.php?action=save_attendance', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            date: currentDate,
                            classId: currentClassId,
                            attendance: currentAttendance.map(a => ({
                                studentId: a.id,
                                status: a.status,
                                note: a.note || ''
                            }))
                        })
                    });
                    
                    const result = await response.json();
                    if (result.success) {
                        renderAttendance();
                        updateSummary();
                        loadHistory();
                        showNotification(currentLanguage === 'en' ? 'All students marked as present!' : 'تم تسجيل جميع الطلاب كحاضرين!', 'success');
                    } else {
                        throw new Error(result.error || 'Save failed');
                    }
                } catch (error) {
                    console.error('Error saving attendance:', error);
                    showNotification('Error saving attendance', 'error');
                }
            }
        }

        function undoLastChange() {
            if (changeHistory.length === 0) {
                showNotification(currentLanguage === 'en' ? 'No changes to undo' : 'لا توجد تغييرات للتراجع', 'warning');
                return;
            }
            
            const lastChange = changeHistory.pop();
            const attendance = currentAttendance.find(a => a.id === lastChange.studentId);
            if (attendance) {
                attendance.status = lastChange.oldStatus;
                renderAttendance();
                updateSummary();
                showNotification(currentLanguage === 'en' ? 'Last change undone' : 'تم التراجع عن آخر تغيير', 'info');
            }
        }

        async function saveAttendance() {
            if (!currentClassId || !currentDate) {
                showNotification(currentLanguage === 'en' ? 'Please select a class and date first' : 'يرجى اختيار فصل وتاريخ أولاً', 'warning');
                return;
            }
            
            try {
                const response = await fetch('attendance-management.php?action=save_attendance', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        date: currentDate,
                        classId: currentClassId,
                        attendance: currentAttendance.map(a => ({
                            studentId: a.id,
                            status: a.status,
                            note: a.note || ''
                        }))
                    })
                });
                
                const result = await response.json();
                if (result.success) {
                    originalAttendance = JSON.parse(JSON.stringify(currentAttendance));
                    changeHistory = [];
                    showNotification(currentLanguage === 'en' ? 'Attendance saved successfully!' : 'تم حفظ الحضور بنجاح!', 'success');
                    loadHistory();
                } else {
                    throw new Error(result.error || 'Save failed');
                }
            } catch (error) {
                console.error('Error saving attendance:', error);
                showNotification('Error saving attendance', 'error');
            }
        }

        function viewStudentHistory(studentId) {
            const student = currentAttendance.find(a => a.id === studentId);
            if (student) {
                
                alert(currentLanguage === 'en' ? `History for ${student.name}` : `سجل ${student.name}`);
            }
        }

        function openProfileSettings() {
            window.location.href = 'notifications-and-settings.php#profile';
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
        }

        document.addEventListener('DOMContentLoaded', function() {
            
            const classSelect = document.getElementById('classFilter');
            if (classSelect && classSelect.options.length > 1) {
                classSelect.selectedIndex = 1;
                loadAttendance();
            }
        });
    </script>
</body>
</html>
