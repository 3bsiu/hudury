<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-teacher.php';

requireUserType('teacher');

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

$currentTeacherId = getCurrentUserId();
$currentTeacher = getCurrentUserData($pdo);
$teacherName = $_SESSION['user_name'] ?? 'Teacher';
$teacherEmail = $_SESSION['user_email'] ?? '';

$teacherClasses = [];
$teacherCourses = [];
$todayAttendance = [];
$attendanceStats = [
    'total' => 0,
    'present' => 0,
    'absent' => 0,
    'late' => 0,
    'excused' => 0
];
$totalStudents = 0;
$newMessagesCount = 0;

if ($currentTeacherId) {
    try {
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.*, tcc.Course_ID
            FROM teacher_class_course tcc
            JOIN class c ON tcc.Class_ID = c.Class_ID
            WHERE tcc.Teacher_ID = ?
        ");
        $stmt->execute([$currentTeacherId]);
        $teacherClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT DISTINCT co.*
            FROM teacher_class_course tcc
            JOIN course co ON tcc.Course_ID = co.Course_ID
            WHERE tcc.Teacher_ID = ?
        ");
        $stmt->execute([$currentTeacherId]);
        $teacherCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($teacherClasses)) {
            $classIds = array_column($teacherClasses, 'Class_ID');
            $placeholders = str_repeat('?,', count($classIds) - 1) . '?';
            
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT s.Student_ID) as total
                FROM student s
                WHERE s.Class_ID IN ($placeholders)
            ");
            $stmt->execute($classIds);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalStudents = $result ? intval($result['total']) : 0;

            $today = date('Y-m-d');

            $stmt = $pdo->prepare("
                SELECT a.Status
                FROM attendance a
                INNER JOIN student s ON a.Student_ID = s.Student_ID
                WHERE a.Class_ID IN ($placeholders) AND a.Date = ?
            ");
            $params = array_merge($classIds, [$today]);
            $stmt->execute($params);
            $allTodayAttendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $attendanceStats['total'] = count($allTodayAttendance);
            foreach ($allTodayAttendance as $att) {
                $status = strtolower($att['Status']);
                if ($status === 'present') {
                    $attendanceStats['present']++;
                } elseif ($status === 'absent') {
                    $attendanceStats['absent']++;
                } elseif ($status === 'late') {
                    $attendanceStats['late']++;
                } elseif ($status === 'excused') {
                    $attendanceStats['excused']++;
                }
            }

            $stmt = $pdo->prepare("
                SELECT a.*, s.Student_Code, s.NameEn, s.NameAr, c.Name as ClassName
                FROM attendance a
                INNER JOIN student s ON a.Student_ID = s.Student_ID
                INNER JOIN class c ON a.Class_ID = c.Class_ID
                WHERE a.Class_ID IN ($placeholders) AND a.Date = ?
                ORDER BY s.NameEn ASC
                LIMIT 5
            ");
            $stmt->execute($params);
            $todayAttendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Error fetching teacher classes/attendance: " . $e->getMessage());
    }
}

$notifications = [];
try {
    $query = "
        SELECT Notif_ID, Title, Content, Date_Sent, Sender_Type, Target_Role, Target_Class_ID, Target_Student_ID
        FROM notification
        WHERE (Target_Role = 'All' OR Target_Role = 'Teacher')
        ORDER BY Date_Sent DESC
        LIMIT 20
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching notifications for teachers: " . $e->getMessage());
    $notifications = [];
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
        AND (LOWER(TRIM(Target_Audience)) = 'all' OR LOWER(TRIM(Target_Audience)) = 'teachers')
        ORDER BY Date ASC, Time ASC
        LIMIT 4
    ");
    $stmt->execute([$today]);
    $upcomingEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT Event_ID, Title, Description, Date, Time, Location, Type, Target_Audience
        FROM event
        WHERE Date >= ?
        AND (LOWER(TRIM(Target_Audience)) = 'all' OR LOWER(TRIM(Target_Audience)) = 'teachers')
        ORDER BY Date ASC, Time ASC
    ");
    $stmt->execute([$today]);
    $allUpcomingEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalEventsCount = count($allUpcomingEvents);
} catch (PDOException $e) {
    error_log("Error fetching upcoming events for teachers: " . $e->getMessage());
    $upcomingEvents = [];
    $allUpcomingEvents = [];
    $totalEventsCount = 0;
}

$recentParentChats = [];
if ($currentTeacherId) {
    try {
        
        $stmt = $pdo->prepare("
            SELECT 
                conv.Conversation_ID,
                conv.Last_Message_At,
                CASE 
                    WHEN conv.Participant_1_Type = 'parent' THEN conv.Participant_1_ID
                    WHEN conv.Participant_2_Type = 'parent' THEN conv.Participant_2_ID
                END as Parent_ID
            FROM conversation conv
            WHERE (
                (conv.Participant_1_Type = 'teacher' AND conv.Participant_1_ID = ?) OR
                (conv.Participant_2_Type = 'teacher' AND conv.Participant_2_ID = ?)
            )
            AND (
                (conv.Participant_1_Type = 'parent') OR (conv.Participant_2_Type = 'parent')
            )
            AND EXISTS (
                SELECT 1 FROM teacher_class_course tcc
                INNER JOIN student st ON tcc.Class_ID = st.Class_ID
                INNER JOIN parent_student_relationship psr ON st.Student_ID = psr.Student_ID
                WHERE tcc.Teacher_ID = ?
                AND psr.Parent_ID = CASE 
                    WHEN conv.Participant_1_Type = 'parent' THEN conv.Participant_1_ID
                    WHEN conv.Participant_2_Type = 'parent' THEN conv.Participant_2_ID
                END
            )
            ORDER BY conv.Last_Message_At DESC
            LIMIT 2
        ");
        $stmt->execute([$currentTeacherId, $currentTeacherId, $currentTeacherId]);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($conversations as $conv) {
            $parentId = $conv['Parent_ID'];
            $conversationId = $conv['Conversation_ID'];

            $stmt = $pdo->prepare("
                SELECT Parent_ID, 
                       COALESCE(NULLIF(NameEn, ''), NULLIF(NameAr, ''), 'Parent') as Parent_Name,
                       NameAr as Parent_NameAr
                FROM parent
                WHERE Parent_ID = ?
            ");
            $stmt->execute([$parentId]);
            $parent = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                SELECT Message_Text, Created_At
                FROM message
                WHERE Conversation_ID = ?
                ORDER BY Created_At DESC
                LIMIT 1
            ");
            $stmt->execute([$conversationId]);
            $lastMessage = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                SELECT s.NameEn, s.NameAr
                FROM parent_student_relationship psr
                INNER JOIN student s ON psr.Student_ID = s.Student_ID
                INNER JOIN teacher_class_course tcc ON s.Class_ID = tcc.Class_ID
                WHERE psr.Parent_ID = ? AND tcc.Teacher_ID = ?
                LIMIT 1
            ");
            $stmt->execute([$parentId, $currentTeacherId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($parent) {
                $recentParentChats[] = [
                    'Parent_ID' => $parentId,
                    'Parent_Name' => $parent['Parent_Name'],
                    'Parent_NameAr' => $parent['Parent_NameAr'],
                    'Last_Message' => $lastMessage ? $lastMessage['Message_Text'] : null,
                    'Last_Message_Time' => $lastMessage ? $lastMessage['Created_At'] : null,
                    'Student_Name' => $student ? ($student['NameEn'] ?: $student['NameAr']) : null
                ];
            }
        }

        $stmt = $pdo->prepare("
            SELECT DISTINCT
                CASE 
                    WHEN conv.Participant_1_Type = 'parent' THEN conv.Participant_1_ID
                    WHEN conv.Participant_2_Type = 'parent' THEN conv.Participant_2_ID
                END as Parent_ID
            FROM conversation conv
            WHERE (
                (conv.Participant_1_Type = 'teacher' AND conv.Participant_1_ID = ?) OR
                (conv.Participant_2_Type = 'teacher' AND conv.Participant_2_ID = ?)
            )
            AND (
                (conv.Participant_1_Type = 'parent') OR (conv.Participant_2_Type = 'parent')
            )
            AND EXISTS (
                SELECT 1 FROM teacher_class_course tcc
                INNER JOIN student st ON tcc.Class_ID = st.Class_ID
                INNER JOIN parent_student_relationship psr ON st.Student_ID = psr.Student_ID
                WHERE tcc.Teacher_ID = ?
                AND psr.Parent_ID = CASE 
                    WHEN conv.Participant_1_Type = 'parent' THEN conv.Participant_1_ID
                    WHEN conv.Participant_2_Type = 'parent' THEN conv.Participant_2_ID
                END
            )
        ");
        $stmt->execute([$currentTeacherId, $currentTeacherId, $currentTeacherId]);
        $parentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($parentIds)) {
            $parentPlaceholders = str_repeat('?,', count($parentIds) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as unread_count
                FROM message m
                INNER JOIN conversation conv ON m.Conversation_ID = conv.Conversation_ID
                WHERE m.Sender_Type = 'parent'
                AND m.Sender_ID IN ($parentPlaceholders)
                AND (
                    (conv.Participant_1_Type = 'teacher' AND conv.Participant_1_ID = ?) OR
                    (conv.Participant_2_Type = 'teacher' AND conv.Participant_2_ID = ?)
                )
                AND m.Is_Read = 0
            ");
            $params = array_merge($parentIds, [$currentTeacherId, $currentTeacherId]);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $newMessagesCount = $result ? intval($result['unread_count']) : 0;
        }
    } catch (PDOException $e) {
        error_log("Error fetching recent parent chats: " . $e->getMessage());
        $recentParentChats = [];
    }
}

$todaySchedule = [];
if ($currentTeacherId && !empty($teacherClasses)) {
    try {
        $dayOfWeek = date('l'); 
        $classIds = array_column($teacherClasses, 'Class_ID');
        $placeholders = str_repeat('?,', count($classIds) - 1) . '?';
        
        $stmt = $pdo->prepare("
            SELECT s.Schedule_ID, s.Start_Time, s.End_Time, s.Subject, s.Room,
                   c.Name as Class_Name, co.Course_Name
            FROM schedule s
            INNER JOIN class c ON s.Class_ID = c.Class_ID
            LEFT JOIN course co ON s.Course_ID = co.Course_ID
            WHERE s.Class_ID IN ($placeholders)
            AND s.Type = 'Class'
            AND s.Day_Of_Week = ?
            AND s.Teacher_ID = ?
            ORDER BY s.Start_Time ASC
        ");
        $params = array_merge($classIds, [$dayOfWeek, $currentTeacherId]);
        $stmt->execute($params);
        $todaySchedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching today's schedule: " . $e->getMessage());
        $todaySchedule = [];
    }
}

$recentGrades = [];
if ($currentTeacherId && !empty($teacherClasses)) {
    try {
        $classIds = array_column($teacherClasses, 'Class_ID');
        $placeholders = str_repeat('?,', count($classIds) - 1) . '?';
        
        $stmt = $pdo->prepare("
            SELECT g.Grade_ID, g.Value, g.Type, g.Date_Recorded,
                   s.Student_ID, s.NameEn as Student_Name, s.NameAr as Student_NameAr,
                   co.Course_ID, co.Course_Name
            FROM grade g
            INNER JOIN student s ON g.Student_ID = s.Student_ID
            INNER JOIN course co ON g.Course_ID = co.Course_ID
            WHERE s.Class_ID IN ($placeholders)
            AND g.Teacher_ID = ?
            ORDER BY g.Date_Recorded DESC, g.Created_At DESC
            LIMIT 3
        ");
        $params = array_merge($classIds, [$currentTeacherId]);
        $stmt->execute($params);
        $recentGrades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching recent grades: " . $e->getMessage());
        $recentGrades = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        .welcome-section {
            background: linear-gradient(135deg, #FFE5E5 0%, #E5F3FF 100%);
            padding: 3rem;
            border-radius: 30px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .welcome-section h1 {
            font-family: 'Fredoka', sans-serif;
            font-size: 2.5rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #FF6B9D, #6BCB77);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 2rem;
            border-radius: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            transition: all 0.4s;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 107, 157, 0.1) 0%, transparent 70%);
            transition: transform 0.6s;
        }

        .stat-card:hover::before {
            transform: scale(1.5);
        }

        .stat-card:hover {
            transform: translateY(-10px) rotate(2deg);
            box-shadow: 0 15px 40px rgba(255, 107, 157, 0.3);
        }

        .stat-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #666;
            font-weight: 600;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .student-list {
            display: grid;
            gap: 1rem;
        }

        .student-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            background: #FFF9F5;
            border-radius: 15px;
            transition: all 0.3s;
            cursor: pointer;
        }

        .student-item:hover {
            background: #FFE5E5;
            transform: translateX(10px);
        }

        .student-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .student-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #FF6B9D, #6BCB77);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .upload-area {
            border: 3px dashed #FFE5E5;
            border-radius: 20px;
            padding: 3rem;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
        }

        .upload-area:hover {
            border-color: #FF6B9D;
            background: #FFF9F5;
        }

        .upload-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .quick-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>
    
    <div class="main-content">
    
    <div id="notificationContainer"></div>

    <div class="dashboard-container">
        
        <div class="welcome-section">
            <h1 data-en="Welcome to Your Teacher Dashboard! 👋" data-ar="مرحباً بك في لوحة تحكم المعلم! 👋">Welcome to Your Teacher Dashboard! 👋</h1>
            <p data-en="Manage your classes, track attendance, record grades, and communicate with parents all in one place." data-ar="إدارة فصولك، تتبع الحضور، تسجيل الدرجات، والتواصل مع أولياء الأمور في مكان واحد.">Manage your classes, track attendance, record grades, and communicate with parents all in one place.</p>
        </div>

        <div class="quick-stats">
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-value"><?php echo $totalStudents; ?></div>
                <div class="stat-label" data-en="Total Students" data-ar="إجمالي الطلاب">Total Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">✅</div>
                <div class="stat-value"><?php echo $attendanceStats['present']; ?></div>
                <div class="stat-label" data-en="Present Today" data-ar="حاضرون اليوم">Present Today</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">💬</div>
                <div class="stat-value"><?php echo $newMessagesCount; ?></div>
                <div class="stat-label" data-en="New Messages" data-ar="رسائل جديدة">New Messages</div>
            </div>
        </div>

        <div class="content-grid">
            
            <div>
                
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📅</span>
                            <span data-en="Today's Attendance" data-ar="حضور اليوم">Today's Attendance</span>
                        </h2>
                        <a href="attendance-management.php" class="btn btn-primary btn-small" data-en="Manage Attendance" data-ar="إدارة الحضور">Manage Attendance</a>
                    </div>
                    <?php if (empty($teacherClasses)): ?>
                        <div style="text-align: center; padding: 2rem; color: #666;">
                            <div style="font-size: 2rem; margin-bottom: 0.5rem;">📅</div>
                            <div data-en="No classes assigned" data-ar="لا توجد فصول معينة">No classes assigned</div>
                            <div style="font-size: 0.85rem; margin-top: 0.5rem; color: #999;" data-en="Please contact admin to assign classes" data-ar="يرجى الاتصال بالإدارة لتعيين الفصول">Please contact admin to assign classes</div>
                        </div>
                    <?php elseif (empty($todayAttendance)): ?>
                        <div style="text-align: center; padding: 2rem; color: #666;">
                            <div style="font-size: 2rem; margin-bottom: 0.5rem;">📅</div>
                            <div data-en="No attendance recorded for today" data-ar="لا يوجد حضور مسجل لليوم">No attendance recorded for today</div>
                            <a href="attendance-management.php" class="btn btn-primary" style="margin-top: 1rem; display: inline-block;" data-en="Mark Attendance" data-ar="تسجيل الحضور">Mark Attendance</a>
                        </div>
                    <?php else: ?>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; padding: 1rem; background: #FFF9F5; border-radius: 15px;">
                            <div style="text-align: center;">
                                <div style="font-size: 1.8rem; font-weight: 800; color: var(--primary-color);"><?php echo $attendanceStats['total']; ?></div>
                                <div style="font-size: 0.85rem; color: #666;" data-en="Total" data-ar="الإجمالي">Total</div>
                            </div>
                            <div style="text-align: center;">
                                <div style="font-size: 1.8rem; font-weight: 800; color: #6BCB77;"><?php echo $attendanceStats['present']; ?></div>
                                <div style="font-size: 0.85rem; color: #666;" data-en="Present" data-ar="حاضر">Present</div>
                            </div>
                            <div style="text-align: center;">
                                <div style="font-size: 1.8rem; font-weight: 800; color: #FF6B9D;"><?php echo $attendanceStats['absent']; ?></div>
                                <div style="font-size: 0.85rem; color: #666;" data-en="Absent" data-ar="غائب">Absent</div>
                            </div>
                            <div style="text-align: center;">
                                <div style="font-size: 1.8rem; font-weight: 800; color: #FFD93D;"><?php echo $attendanceStats['late']; ?></div>
                                <div style="font-size: 0.85rem; color: #666;" data-en="Late" data-ar="متأخر">Late</div>
                            </div>
                            <?php if ($attendanceStats['excused'] > 0): ?>
                            <div style="text-align: center;">
                                <div style="font-size: 1.8rem; font-weight: 800; color: #4A90E2;"><?php echo $attendanceStats['excused']; ?></div>
                                <div style="font-size: 0.85rem; color: #666;" data-en="Excused" data-ar="معذور">Excused</div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <table class="data-table" role="table" aria-label="Today's Attendance">
                            <thead>
                                <tr>
                                    <th data-en="Student Name" data-ar="اسم الطالب">Student Name</th>
                                    <th data-en="Class" data-ar="الفصل">Class</th>
                                    <th data-en="Status" data-ar="الحالة">Status</th>
                                    <th data-en="Time" data-ar="الوقت">Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($todayAttendance as $att): ?>
                                    <?php
                                    $status = strtolower($att['Status']);
                                    $statusClass = 'status-pending';
                                    if ($status === 'present') {
                                        $statusClass = 'status-active';
                                    } elseif ($status === 'absent') {
                                        $statusClass = 'status-inactive';
                                    } elseif ($status === 'late') {
                                        $statusClass = 'status-pending';
                                    } elseif ($status === 'excused') {
                                        $statusClass = 'status-approved';
                                    }
                                    
                                    $statusLabels = [
                                        'present' => ['en' => 'Present', 'ar' => 'حاضر'],
                                        'absent' => ['en' => 'Absent', 'ar' => 'غائب'],
                                        'late' => ['en' => 'Late', 'ar' => 'متأخر'],
                                        'excused' => ['en' => 'Excused', 'ar' => 'معذور']
                                    ];
                                    $statusLabel = $statusLabels[$status] ?? ['en' => $att['Status'], 'ar' => $att['Status']];
                                    
                                    $createdAt = $att['Created_At'] ? new DateTime($att['Created_At']) : null;
                                    $timeStr = $createdAt ? $createdAt->format('g:i A') : '-';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="student-info">
                                                <div class="student-avatar"><?php echo (strpos($att['NameEn'], ' ') !== false && strtolower(substr($att['NameEn'], 0, 1)) === 's') ? '👧' : '👦'; ?></div>
                                                <div>
                                                    <div style="font-weight: 700;"><?php echo htmlspecialchars($att['NameEn']); ?></div>
                                                    <div style="font-size: 0.9rem; color: #666;">ID: <?php echo htmlspecialchars($att['Student_Code']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-size: 0.9rem; color: #666;"><?php echo htmlspecialchars($att['ClassName']); ?></div>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $statusClass; ?>">
                                                <span data-en="<?php echo $statusLabel['en']; ?>" data-ar="<?php echo $statusLabel['ar']; ?>"><?php echo $statusLabel['en']; ?></span>
                                            </span>
                                        </td>
                                        <td><?php echo $timeStr; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if (count($todayAttendance) >= 5): ?>
                            <div style="text-align: center; margin-top: 1rem;">
                                <a href="attendance-management.php" class="btn btn-secondary" data-en="View All Attendance" data-ar="عرض جميع الحضور">View All Attendance</a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📊</span>
                            <span data-en="Grade Management" data-ar="إدارة الدرجات">Grade Management</span>
                        </h2>
                        <a href="grade-management.php" class="btn btn-primary" data-en="Manage Grades" data-ar="إدارة الدرجات">Manage Grades</a>
                    </div>
                    <div class="student-list">
                        <?php if (empty($recentGrades)): ?>
                            <div style="text-align: center; padding: 2rem; color: #666;">
                                <div style="font-size: 2rem; margin-bottom: 0.5rem;">📊</div>
                                <div data-en="No recent grades" data-ar="لا توجد درجات حديثة">No recent grades</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recentGrades as $grade): ?>
                                <?php
                                $studentName = htmlspecialchars($grade['Student_Name'] ?: $grade['Student_NameAr'] ?: 'Student');
                                $courseName = htmlspecialchars($grade['Course_Name'] ?? 'Course');
                                $gradeValue = floatval($grade['Value']);
                                $gradeType = htmlspecialchars($grade['Type'] ?? '');

                                $avatar = '👦';
                                if (stripos($studentName, 'sara') !== false || stripos($studentName, 'سارة') !== false) {
                                    $avatar = '👧';
                                }

                                $typeLabels = [
                                    'Midterm' => ['en' => 'Midterm', 'ar' => 'منتصف الفصل'],
                                    'Final' => ['en' => 'Final', 'ar' => 'نهائي'],
                                    'Assignment' => ['en' => 'Assignment', 'ar' => 'واجب'],
                                    'Quiz' => ['en' => 'Quiz', 'ar' => 'اختبار قصير'],
                                    'Project' => ['en' => 'Project', 'ar' => 'مشروع']
                                ];
                                $typeLabel = $typeLabels[$gradeType] ?? ['en' => $gradeType, 'ar' => $gradeType];
                                ?>
                                <div class="student-item">
                                    <div class="student-info">
                                        <div class="student-avatar"><?php echo $avatar; ?></div>
                                        <div>
                                            <div style="font-weight: 700;"><?php echo $studentName; ?></div>
                                            <div style="font-size: 0.9rem; color: #666;">
                                                <span data-en="<?php echo $typeLabel['en']; ?>" data-ar="<?php echo $typeLabel['ar']; ?>"><?php echo $typeLabel['en']; ?></span>: <?php echo $courseName; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="font-size: 1.5rem; font-weight: 800; color: var(--primary-color);"><?php echo number_format($gradeValue, 1); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📅</span>
                            <span data-en="Today's Schedule" data-ar="جدول اليوم">Today's Schedule</span>
                        </h2>
                    </div>
                    <div class="student-list">
                        <?php if (empty($todaySchedule)): ?>
                            <div style="text-align: center; padding: 2rem; color: #666;">
                                <div style="font-size: 2rem; margin-bottom: 0.5rem;">📅</div>
                                <div data-en="No classes scheduled for today" data-ar="لا توجد فصول مجدولة لليوم">No classes scheduled for today</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($todaySchedule as $index => $period): ?>
                                <?php
                                $startTime = $period['Start_Time'] ? date('g:i A', strtotime($period['Start_Time'])) : '';
                                $endTime = $period['End_Time'] ? date('g:i A', strtotime($period['End_Time'])) : '';
                                $timeRange = $startTime && $endTime ? "$startTime - $endTime" : '';
                                $className = htmlspecialchars($period['Class_Name'] ?? '');
                                $subject = htmlspecialchars($period['Subject'] ?? $period['Course_Name'] ?? '');
                                $room = htmlspecialchars($period['Room'] ?? '');
                                ?>
                                <div style="padding: 1rem; background: #FFF9F5; border-radius: 10px; margin-bottom: <?php echo $index < count($todaySchedule) - 1 ? '0.5rem' : '0'; ?>;">
                                    <div style="font-weight: 700;"><?php echo $timeRange; ?></div>
                                    <div style="color: #666;">
                                        <?php echo $className; ?>
                                        <?php if ($subject): ?>
                                            <span style="color: #999;"> • <?php echo $subject; ?></span>
                                        <?php endif; ?>
                                        <?php if ($room): ?>
                                            <span style="color: #999;"> • <?php echo $room; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <div>
                
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📈</span>
                            <span data-en="Quick Reports" data-ar="تقارير سريعة">Quick Reports</span>
                        </h2>
                        <a href="quick-reports.php" class="btn btn-primary" data-en="View All Reports" data-ar="عرض جميع التقارير">View All Reports</a>
                    </div>
                    <div class="action-buttons">
                        <a href="quick-reports.php#attendance" class="btn btn-primary" style="width: 100%; margin-bottom: 0.5rem; text-align: center; text-decoration: none;" data-en="Attendance Report" data-ar="تقرير الحضور">Attendance Report</a>
                        <a href="quick-reports.php#grades" class="btn btn-primary" style="width: 100%; margin-bottom: 0.5rem; text-align: center; text-decoration: none;" data-en="Grades Report" data-ar="تقرير الدرجات">Grades Report</a>
                        <a href="quick-reports.php#performance" class="btn btn-primary" style="width: 100%; text-align: center; text-decoration: none;" data-en="Performance Report" data-ar="تقرير الأداء">Performance Report</a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">💬</span>
                            <span data-en="Parent Chat" data-ar="دردشة أولياء الأمور">Parent Chat</span>
                        </h2>
                        <a href="parent-chat.php" class="btn btn-primary" data-en="Open Chat" data-ar="فتح الدردشة">Open Chat</a>
                    </div>
                    <div class="student-list">
                        <?php if (empty($recentParentChats)): ?>
                            <div style="text-align: center; padding: 2rem; color: #666;">
                                <div style="font-size: 2rem; margin-bottom: 0.5rem;">💬</div>
                                <div data-en="No recent chats" data-ar="لا توجد محادثات حديثة">No recent chats</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recentParentChats as $chat): ?>
                                <?php
                                $parentName = htmlspecialchars($chat['Parent_Name']);
                                $studentName = htmlspecialchars($chat['Student_Name'] ?? 'Student');
                                $lastMessage = htmlspecialchars(substr($chat['Last_Message'] ?? '', 0, 50));
                                if (strlen($chat['Last_Message'] ?? '') > 50) {
                                    $lastMessage .= '...';
                                }
                                
                                $lastMessageTime = $chat['Last_Message_Time'] ? new DateTime($chat['Last_Message_Time']) : null;
                                $timeAgo = $lastMessageTime ? getTimeAgo($lastMessageTime) : '';

                                $avatar = '👨';
                                if (stripos($parentName, 'sara') !== false || stripos($parentName, 'سارة') !== false) {
                                    $avatar = '👩';
                                }
                                ?>
                                <div class="student-item" onclick="window.location.href='parent-chat.php'">
                                    <div class="student-info">
                                        <div class="student-avatar"><?php echo $avatar; ?></div>
                                        <div>
                                            <div style="font-weight: 700;"><?php echo $parentName; ?></div>
                                            <div style="font-size: 0.9rem; color: #666;">
                                                <?php echo $lastMessage ?: 'No messages yet'; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if ($timeAgo): ?>
                                        <div style="font-size: 0.8rem; color: #999;"><?php echo $timeAgo; ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <a href="parent-chat.php" class="btn btn-secondary" style="width: 100%; margin-top: 1rem; text-align: center;" data-en="View All Messages" data-ar="عرض جميع الرسائل">View All Messages</a>
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
        
        const notificationsCount = <?php echo count($notifications); ?>;

        const allEventsData = <?php echo json_encode($allUpcomingEvents, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

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

        function toggleSideMenu() {
            const menu = document.getElementById('sideMenuMobile');
            const overlay = document.getElementById('sideMenuOverlay');
            if (menu && overlay) {
                menu.classList.toggle('active');
                overlay.classList.toggle('active');
                if (menu.classList.contains('active')) {
                    document.body.style.overflow = 'hidden';
                } else {
                    document.body.style.overflow = '';
                }
            }
        }

        function toggleMobileMenu() {
            toggleSideMenu();
        }

        function openProfileSettings() {
            window.location.href = 'notifications-and-settings.php#profile';
        }

        function openSettings() {
            window.location.href = 'notifications-and-settings.php';
        }

        function generateReport(type) {
            showNotification(currentLanguage === 'en' ? `${type} report generated!` : `تم إنشاء تقرير ${type}!`, 'success');
        }

        updateNotificationBadge();

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

