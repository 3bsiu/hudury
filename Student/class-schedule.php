<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-student.php';

requireUserType('student');

$currentStudentId = getCurrentUserId();
if (!$currentStudentId) {
    header('Location: ../signin.php');
    exit();
}

$currentStudent = getCurrentUserData($pdo);
if (!$currentStudent) {
    header('Location: ../signin.php');
    exit();
}

if ($currentStudent['Student_ID'] != $currentStudentId) {
    error_log("Security violation: Student ID mismatch for user ID: $currentStudentId");
    header('Location: ../signin.php');
    exit();
}

$currentStudentClassId = $currentStudent['Class_ID'] ?? null;
$studentName = $currentStudent['NameEn'] ?? $currentStudent['Name'] ?? $_SESSION['user_name'] ?? 'Student';
$studentClassInfo = null;

if ($currentStudentClassId) {
    try {
        $stmt = $pdo->prepare("SELECT Class_ID, Name, Grade_Level, Section FROM class WHERE Class_ID = ?");
        $stmt->execute([$currentStudentClassId]);
        $studentClassInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching class info: " . $e->getMessage());
    }
}

$scheduleData = [];
if ($currentStudentClassId) {
    try {
        
        $stmt = $pdo->prepare("
            SELECT s.Schedule_ID, s.Day_Of_Week, s.Start_Time, s.End_Time, s.Subject, s.Room,
                   s.Course_ID, s.Teacher_ID, c.Course_Name, t.NameEn as Teacher_Name, cl.Name as ClassName
            FROM schedule s
            INNER JOIN student st ON s.Class_ID = st.Class_ID AND st.Student_ID = ?
            LEFT JOIN course c ON s.Course_ID = c.Course_ID
            LEFT JOIN teacher t ON s.Teacher_ID = t.Teacher_ID
            LEFT JOIN class cl ON s.Class_ID = cl.Class_ID
            WHERE s.Class_ID = ? AND s.Type = 'Class'
            ORDER BY 
                FIELD(s.Day_Of_Week, 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'),
                s.Start_Time
        ");
        $stmt->execute([$currentStudentId, $currentStudentClassId]);
        $scheduleData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching schedule for student: " . $e->getMessage());
        $scheduleData = [];
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
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Schedule - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
        .back-button {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
            padding: 0.8rem 1.5rem;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 700;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            margin-bottom: 1rem;
        }
        .back-button:hover {
            background: rgba(255,255,255,0.3);
            transform: translateX(-5px);
        }
    </style>
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>
    
    <div class="main-content">

    <div class="dashboard-container">
        
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">📅</span>
                <span data-en="Class Schedule" data-ar="جدول الحصص">Class Schedule</span>
            </h1>
            <p class="page-subtitle" data-en="View your weekly class schedule and timings" data-ar="عرض جدول الحصص الأسبوعي والأوقات">View your weekly class schedule and timings</p>
        </div>

        <?php if ($studentClassInfo): ?>
        <div class="card" style="margin-bottom: 1.5rem; background: linear-gradient(135deg, #FFE5E5 0%, #E5F3FF 100%);">
            <div style="padding: 1.5rem;">
                <div style="font-weight: 700; font-size: 1.2rem; margin-bottom: 0.5rem; color: var(--text-dark);" data-en="Your Class" data-ar="فصلك">Your Class</div>
                <div style="font-size: 1.5rem; color: var(--primary-color); font-weight: 800;">
                    <?php echo htmlspecialchars($studentClassInfo['Name']); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📅</span>
                    <span data-en="Weekly Schedule" data-ar="الجدول الأسبوعي">Weekly Schedule</span>
                </h2>
            </div>
            <?php if (!$currentStudentClassId): ?>
                <div style="text-align: center; padding: 3rem; color: #666;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">📅</div>
                    <div style="font-weight: 700; margin-bottom: 0.5rem;" data-en="No Class Assigned" data-ar="لا يوجد فصل معين">No Class Assigned</div>
                    <div style="font-size: 0.9rem;" data-en="Please contact the administration to assign you to a class." data-ar="يرجى الاتصال بالإدارة لتعيينك إلى فصل.">Please contact the administration to assign you to a class.</div>
                </div>
            <?php elseif (empty($scheduleData)): ?>
                <div style="text-align: center; padding: 3rem; color: #666;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">📅</div>
                    <div style="font-weight: 700; margin-bottom: 0.5rem;" data-en="No Schedule Available" data-ar="لا يوجد جدول متاح">No Schedule Available</div>
                    <div style="font-size: 0.9rem;" data-en="The schedule for your class has not been set up yet." data-ar="لم يتم إعداد جدول فصلك بعد.">The schedule for your class has not been set up yet.</div>
                </div>
            <?php else: ?>
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
            <?php endif; ?>
        </div>
    </div>

    <script src="script.js"></script>
</body>
</html>

