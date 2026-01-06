<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/activity-logger.php';

requireUserType('admin');

ini_set('display_errors', 1);
error_reporting(E_ALL);

$classes = [];
try {
    $stmt = $pdo->query("SELECT Class_ID, Name, Grade_Level, Section FROM class ORDER BY Grade_Level, Section");
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching classes: " . $e->getMessage());
}

$courses = [];
try {
    $stmt = $pdo->query("SELECT Course_ID, Course_Name FROM course ORDER BY Course_Name");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching courses: " . $e->getMessage());
}

$teachers = [];
try {
    $stmt = $pdo->query("SELECT Teacher_ID, NameEn, NameAr FROM teacher WHERE Status = 'active' ORDER BY NameEn");
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching teachers: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST request received. POST data: " . print_r($_POST, true));
    $action = $_POST['action'] ?? '';
    error_log("Action: " . $action);
    
    if ($action === 'savePeriod') {
        try {
            $pdo->beginTransaction();
            
            $classId = intval($_POST['classId'] ?? 0);
            $dayOfWeek = $_POST['dayOfWeek'] ?? '';
            $startTime = $_POST['startTime'] ?? '';
            $endTime = $_POST['endTime'] ?? '';
            $courseId = intval($_POST['courseId'] ?? 0);
            $teacherId = intval($_POST['teacherId'] ?? 0);
            $room = trim($_POST['room'] ?? '');
            $scheduleId = isset($_POST['scheduleId']) && $_POST['scheduleId'] !== '' ? intval($_POST['scheduleId']) : null;
            
            error_log("Parsed values - ClassID: $classId, DayOfWeek: $dayOfWeek, StartTime: $startTime, EndTime: $endTime, CourseID: $courseId, TeacherID: $teacherId, Room: $room, ScheduleID: " . ($scheduleId ?? 'null'));

            if (!$classId || !$dayOfWeek || !$startTime || !$endTime || !$courseId) {
                throw new Exception('Missing required fields');
            }

            if ($startTime >= $endTime) {
                throw new Exception('End time must be after start time');
            }

            if ($teacherId > 0) {
                $conflictQuery = "
                    SELECT Schedule_ID, Day_Of_Week, Start_Time, End_Time, c.Course_Name, cl.Name as Class_Name
                    FROM schedule s
                    LEFT JOIN course c ON s.Course_ID = c.Course_ID
                    LEFT JOIN class cl ON s.Class_ID = cl.Class_ID
                    WHERE Teacher_ID = ?
                    AND Day_Of_Week = ?
                    AND Schedule_ID != COALESCE(?, 0)
                    AND (
                        (Start_Time <= ? AND End_Time > ?) OR
                        (Start_Time < ? AND End_Time >= ?) OR
                        (Start_Time >= ? AND End_Time <= ?)
                    )
                ";
                $conflictStmt = $pdo->prepare($conflictQuery);
                $conflictStmt->execute([
                    $teacherId,
                    $dayOfWeek,
                    $scheduleId,
                    $startTime, $startTime,
                    $endTime, $endTime,
                    $startTime, $endTime
                ]);
                $conflict = $conflictStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($conflict) {
                    throw new Exception("Teacher conflict: This teacher is already assigned to '{$conflict['Course_Name']}' in {$conflict['Class_Name']} at {$conflict['Start_Time']}-{$conflict['End_Time']} on {$conflict['Day_Of_Week']}");
                }
            }

            $courseStmt = $pdo->prepare("SELECT Course_Name FROM course WHERE Course_ID = ?");
            $courseStmt->execute([$courseId]);
            $courseData = $courseStmt->fetch(PDO::FETCH_ASSOC);
            $subject = $courseData['Course_Name'] ?? '';
            
            if ($scheduleId) {
                
                $updateStmt = $pdo->prepare("
                    UPDATE schedule 
                    SET Day_Of_Week = ?, Start_Time = ?, End_Time = ?, 
                        Subject = ?, Room = ?, Course_ID = ?, Teacher_ID = ?
                    WHERE Schedule_ID = ? AND Class_ID = ?
                ");
                $updateStmt->execute([
                    $dayOfWeek, $startTime, $endTime,
                    $subject, $room ?: null, $courseId, $teacherId ?: null,
                    $scheduleId, $classId
                ]);
            } else {
                
                $insertStmt = $pdo->prepare("
                    INSERT INTO schedule (Type, Day_Of_Week, Start_Time, End_Time, Subject, Room, Class_ID, Course_ID, Teacher_ID)
                    VALUES ('Class', ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insertStmt->execute([
                    $dayOfWeek, $startTime, $endTime,
                    $subject, $room ?: null, $classId, $courseId, $teacherId ?: null
                ]);
            }
            
            $pdo->commit();

            $stmt = $pdo->prepare("SELECT Name FROM class WHERE Class_ID = ?");
            $stmt->execute([$classId]);
            $classData = $stmt->fetch(PDO::FETCH_ASSOC);
            $className = $classData ? $classData['Name'] : "Class ID: {$classId}";

            $details = "Day: {$dayOfWeek}, Time: {$startTime}-{$endTime}, Subject: {$subject}";
            logAdminAction($pdo, $scheduleId ? 'update' : 'create', 'schedule', $scheduleId ?? $pdo->lastInsertId(), "Schedule for {$className}: {$details}", 'schedule', null);
            
            error_log("Period saved successfully. Schedule ID: " . ($scheduleId ?? 'new'));
            header("Location: class-schedule-management.php?success=1&classId=" . $classId);
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error saving period: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            header("Location: class-schedule-management.php?error=" . urlencode($e->getMessage()) . "&classId=" . ($_POST['classId'] ?? ''));
            exit();
        }
    } elseif ($action === 'deletePeriod') {
        try {
            $scheduleId = intval($_POST['scheduleId'] ?? 0);
            $classId = intval($_POST['classId'] ?? 0);
            
            if ($scheduleId > 0) {
                
                $stmt = $pdo->prepare("SELECT s.*, c.Name as Class_Name FROM schedule s LEFT JOIN class c ON s.Class_ID = c.Class_ID WHERE s.Schedule_ID = ?");
                $stmt->execute([$scheduleId]);
                $scheduleData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $deleteStmt = $pdo->prepare("DELETE FROM schedule WHERE Schedule_ID = ?");
                $deleteStmt->execute([$scheduleId]);

                if ($scheduleData) {
                    $details = "Day: {$scheduleData['Day_Of_Week']}, Time: {$scheduleData['Start_Time']}-{$scheduleData['End_Time']}, Subject: {$scheduleData['Subject']}";
                    logAdminAction($pdo, 'delete', 'schedule', $scheduleId, "Schedule deleted for {$scheduleData['Class_Name']}: {$details}", 'schedule', null);
                }
            }
            
            header("Location: class-schedule-management.php?success=1&classId=" . $classId);
            exit();
        } catch (Exception $e) {
            error_log("Error deleting period: " . $e->getMessage());
            header("Location: class-schedule-management.php?error=" . urlencode($e->getMessage()) . "&classId=" . ($_POST['classId'] ?? ''));
            exit();
        }
    }
}

$selectedClassId = isset($_GET['classId']) ? intval($_GET['classId']) : (isset($classes[0]['Class_ID']) ? $classes[0]['Class_ID'] : null);

$scheduleData = [];
if ($selectedClassId) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.Schedule_ID, s.Day_Of_Week, s.Start_Time, s.End_Time, s.Subject, s.Room,
                   s.Course_ID, s.Teacher_ID, c.Course_Name, t.NameEn as Teacher_Name
            FROM schedule s
            LEFT JOIN course c ON s.Course_ID = c.Course_ID
            LEFT JOIN teacher t ON s.Teacher_ID = t.Teacher_ID
            WHERE s.Class_ID = ? AND s.Type = 'Class'
            ORDER BY 
                FIELD(s.Day_Of_Week, 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'),
                s.Start_Time
        ");
        $stmt->execute([$selectedClassId]);
        $scheduleData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching schedule: " . $e->getMessage());
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
    <title>Class Schedule Management - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
        .schedule-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        .schedule-day {
            background: #FFF9F5;
            padding: 1.5rem;
            border-radius: 20px;
            border: 3px solid #FFE5E5;
            transition: all 0.3s;
        }
        .schedule-day:hover {
            border-color: #FF6B9D;
            transform: translateY(-5px);
        }
        .schedule-day-name {
            font-weight: 700;
            font-size: 1.2rem;
            margin-bottom: 1rem;
            color: var(--primary-color);
            text-align: center;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #FFE5E5;
        }
        .schedule-period {
            background: white;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 0.8rem;
            border-left: 4px solid #6BCB77;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }
        .schedule-period:hover {
            transform: translateX(5px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        .schedule-period.editable {
            border-left-color: #FF6B9D;
        }
        .schedule-time {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 0.5rem;
        }
        .schedule-period-actions {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            display: flex;
            gap: 0.3rem;
        }
        .schedule-period-actions button {
            background: rgba(255, 107, 157, 0.1);
            border: none;
            border-radius: 5px;
            padding: 0.3rem 0.5rem;
            cursor: pointer;
            font-size: 0.8rem;
            color: #FF6B9D;
        }
        .schedule-period-actions button:hover {
            background: rgba(255, 107, 157, 0.2);
        }
        .add-period-btn {
            width: 100%;
            padding: 1rem;
            background: #E5F3FF;
            border: 2px dashed #6BCB77;
            border-radius: 10px;
            cursor: pointer;
            text-align: center;
            color: #6BCB77;
            font-weight: 700;
            transition: all 0.3s;
        }
        .add-period-btn:hover {
            background: #6BCB77;
            color: white;
        }
        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
        }
        .alert-success {
            background: #6BCB77;
            color: white;
        }
        .alert-error {
            background: #FF6B9D;
            color: white;
        }
    </style>
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">📋</span>
                <span data-en="Class Schedule Management" data-ar="إدارة جداول الفصول">Class Schedule Management</span>
            </h1>
            <p class="page-subtitle" data-en="Organize and modify class schedules for all grades" data-ar="تنظيم وتعديل جداول الفصول لجميع الصفوف">Organize and modify class schedules for all grades</p>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <span data-en="Schedule updated successfully!" data-ar="تم تحديث الجدول بنجاح!">Schedule updated successfully!</span>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> 
                <?php echo htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">🎯</span>
                    <span data-en="Select Class" data-ar="اختر الفصل">Select Class</span>
                </h2>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <select class="filter-select" id="classSelect" onchange="loadSchedule()">
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['Class_ID']; ?>" <?php echo ($selectedClassId == $class['Class_ID']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($class['Name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if ($selectedClassId): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📅</span>
                    <span data-en="Weekly Schedule" data-ar="الجدول الأسبوعي">Weekly Schedule</span>
                </h2>
            </div>
            <div class="schedule-grid" id="scheduleGrid">
                <?php
                $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday'];
                $daysAr = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس'];
                foreach ($days as $dayIndex => $day):
                ?>
                    <div class="schedule-day">
                        <div class="schedule-day-name" data-en="<?php echo $day; ?>" data-ar="<?php echo $daysAr[$dayIndex]; ?>"><?php echo $day; ?></div>
                        <?php if (isset($scheduleByDay[$day])): ?>
                            <?php foreach ($scheduleByDay[$day] as $period): ?>
                                <div class="schedule-period editable" onclick="editPeriod(<?php echo $period['Schedule_ID']; ?>, '<?php echo htmlspecialchars($period['Day_Of_Week'], ENT_QUOTES); ?>', '<?php echo $period['Start_Time']; ?>', '<?php echo $period['End_Time']; ?>', <?php echo $period['Course_ID']; ?>, <?php echo $period['Teacher_ID'] ?: 'null'; ?>, '<?php echo htmlspecialchars($period['Room'] ?? '', ENT_QUOTES); ?>')">
                                    <div class="schedule-period-actions">
                                        <button onclick="event.stopPropagation(); deletePeriod(<?php echo $period['Schedule_ID']; ?>)" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                    <div class="schedule-time">
                                        <?php echo date('g:i A', strtotime($period['Start_Time'])); ?> - 
                                        <?php echo date('g:i A', strtotime($period['End_Time'])); ?>
                                    </div>
                                    <div style="font-weight: 700;"><?php echo htmlspecialchars($period['Subject']); ?></div>
                                    <div style="font-size: 0.85rem; color: #666; margin-top: 0.3rem;">
                                        <?php if ($period['Room']): ?>
                                            <?php echo htmlspecialchars($period['Room']); ?>
                                            <?php if ($period['Teacher_Name']): ?> - <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($period['Teacher_Name']): ?>
                                            <?php echo htmlspecialchars($period['Teacher_Name']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="add-period-btn" onclick="addPeriod('<?php echo $day; ?>')" data-en="+ Add Period" data-ar="+ إضافة حصة">+ Add Period</div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
            <div class="card">
                <div style="text-align: center; padding: 2rem; color: #666;">
                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">📋</div>
                    <div data-en="Please select a class to view or edit its schedule" data-ar="يرجى اختيار فصل لعرض أو تعديل جدوله">Please select a class to view or edit its schedule</div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="modal" id="periodModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="periodModalTitle" data-en="Add Period" data-ar="إضافة حصة">Add Period</h2>
                <button class="modal-close" onclick="closeModal('periodModal')">&times;</button>
            </div>
            <form id="periodForm" method="POST" action="class-schedule-management.php" onsubmit="savePeriod(event)">
                <input type="hidden" id="scheduleId" name="scheduleId" value="">
                <input type="hidden" id="periodDay" name="dayOfWeek" value="">
                <input type="hidden" id="classId" name="classId" value="<?php echo $selectedClassId ?? ''; ?>">
                <input type="hidden" name="action" value="savePeriod">
                
                <div class="form-group">
                    <label data-en="Day of Week" data-ar="يوم الأسبوع">Day of Week <span style="color: red;">*</span></label>
                    <select id="dayOfWeekSelect" name="dayOfWeekSelect" required onchange="document.getElementById('periodDay').value = this.value;">
                        <option value="">Select Day</option>
                        <option value="Sunday" data-en="Sunday" data-ar="الأحد">Sunday</option>
                        <option value="Monday" data-en="Monday" data-ar="الإثنين">Monday</option>
                        <option value="Tuesday" data-en="Tuesday" data-ar="الثلاثاء">Tuesday</option>
                        <option value="Wednesday" data-en="Wednesday" data-ar="الأربعاء">Wednesday</option>
                        <option value="Thursday" data-en="Thursday" data-ar="الخميس">Thursday</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label data-en="Time" data-ar="الوقت">Time</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <input type="time" id="periodStartTime" name="startTime" required>
                        <input type="time" id="periodEndTime" name="endTime" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label data-en="Subject (Course)" data-ar="المادة (المقرر)">Subject (Course) <span style="color: red;">*</span></label>
                    <select id="periodCourse" name="courseId" required>
                        <option value="">Select Course</option>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?php echo $course['Course_ID']; ?>">
                                <?php echo htmlspecialchars($course['Course_Name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label data-en="Teacher" data-ar="المعلم">Teacher</label>
                    <select id="periodTeacher" name="teacherId">
                        <option value="">No Teacher Assigned</option>
                        <?php foreach ($teachers as $teacher): ?>
                            <option value="<?php echo $teacher['Teacher_ID']; ?>">
                                <?php echo htmlspecialchars($teacher['NameEn']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label data-en="Room" data-ar="القاعة">Room</label>
                    <input type="text" id="periodRoom" name="room" placeholder="e.g., Room 201">
                </div>
                
                <div id="conflictWarning" style="display: none; background: #FFE5E5; padding: 1rem; border-radius: 10px; margin-bottom: 1rem; color: #C44569; font-weight: 600;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span id="conflictMessage"></span>
                </div>
                
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" data-en="Save" data-ar="حفظ">Save</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('periodModal')" data-en="Cancel" data-ar="إلغاء">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script src="../script.js"></script>
    <script>
        const courses = <?php echo json_encode($courses, JSON_UNESCAPED_UNICODE); ?>;
        const teachers = <?php echo json_encode($teachers, JSON_UNESCAPED_UNICODE); ?>;
        const selectedClassId = <?php echo $selectedClassId ?? 'null'; ?>;

        function loadSchedule() {
            const classId = document.getElementById('classSelect').value;
            if (classId) {
                window.location.href = 'class-schedule-management.php?classId=' + classId;
            }
        }

        function addPeriod(day) {
            document.getElementById('periodModalTitle').textContent = (typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'إضافة حصة' : 'Add Period';
            document.getElementById('scheduleId').value = '';
            document.getElementById('periodDay').value = day;
            document.getElementById('dayOfWeekSelect').value = day;
            document.getElementById('periodStartTime').value = '';
            document.getElementById('periodEndTime').value = '';
            document.getElementById('periodCourse').value = '';
            document.getElementById('periodTeacher').value = '';
            document.getElementById('periodRoom').value = '';
            document.getElementById('conflictWarning').style.display = 'none';
            document.getElementById('classId').value = selectedClassId;

            const submitBtn = document.querySelector('#periodForm button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = (typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'حفظ' : 'Save';
            }
            
            openModal('periodModal');
        }

        function editPeriod(scheduleId, dayOfWeek, startTime, endTime, courseId, teacherId, room) {
            document.getElementById('periodModalTitle').textContent = (typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'تعديل الحصة' : 'Edit Period';
            document.getElementById('scheduleId').value = scheduleId;
            document.getElementById('periodDay').value = dayOfWeek;
            document.getElementById('dayOfWeekSelect').value = dayOfWeek;
            document.getElementById('periodStartTime').value = startTime;
            document.getElementById('periodEndTime').value = endTime;
            document.getElementById('periodCourse').value = courseId;
            document.getElementById('periodTeacher').value = teacherId || '';
            document.getElementById('periodRoom').value = room || '';
            document.getElementById('classId').value = selectedClassId;
            document.getElementById('conflictWarning').style.display = 'none';

            const submitBtn = document.querySelector('#periodForm button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = (typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'حفظ' : 'Save';
            }
            
            openModal('periodModal');
        }

        function deletePeriod(scheduleId) {
            if (confirm((typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'هل أنت متأكد من حذف هذه الحصة؟' : 'Are you sure you want to delete this period?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'class-schedule-management.php';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'deletePeriod';
                form.appendChild(actionInput);
                
                const scheduleIdInput = document.createElement('input');
                scheduleIdInput.type = 'hidden';
                scheduleIdInput.name = 'scheduleId';
                scheduleIdInput.value = scheduleId;
                form.appendChild(scheduleIdInput);
                
                const classIdInput = document.createElement('input');
                classIdInput.type = 'hidden';
                classIdInput.name = 'classId';
                classIdInput.value = selectedClassId;
                form.appendChild(classIdInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        function savePeriod(event) {
            console.log('savePeriod called');
            
            const form = event.target;

            const dayOfWeekSelect = document.getElementById('dayOfWeekSelect');
            const periodDayInput = document.getElementById('periodDay');
            if (dayOfWeekSelect && periodDayInput) {
                periodDayInput.value = dayOfWeekSelect.value;
                console.log('Day of week set to:', periodDayInput.value);
            }

            const startTime = document.getElementById('periodStartTime').value;
            const endTime = document.getElementById('periodEndTime').value;
            const courseId = document.getElementById('periodCourse').value;
            const classId = document.getElementById('classId').value;
            const dayOfWeek = document.getElementById('periodDay').value;
            
            console.log('Form values:', {
                startTime, endTime, courseId, classId, dayOfWeek
            });

            if (!dayOfWeek) {
                event.preventDefault();
                alert((typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'يرجى اختيار يوم الأسبوع' : 'Please select a day of week');
                return false;
            }
            
            if (!startTime || !endTime) {
                event.preventDefault();
                alert((typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'يرجى إدخال وقت البداية والانتهاء' : 'Please enter start and end time');
                return false;
            }
            
            if (startTime >= endTime) {
                event.preventDefault();
                alert((typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'وقت الانتهاء يجب أن يكون بعد وقت البداية' : 'End time must be after start time');
                return false;
            }
            
            if (!courseId) {
                event.preventDefault();
                alert((typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'يرجى اختيار المادة' : 'Please select a course');
                return false;
            }
            
            if (!classId) {
                event.preventDefault();
                alert((typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'يرجى اختيار الفصل' : 'Please select a class');
                return false;
            }

            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = (typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'جاري الحفظ...' : 'Saving...';
            }
            
            console.log('Form validation passed. Submitting...');
            console.log('Form action:', form.action);
            console.log('Form method:', form.method);

            return true;
        }

        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('active');
                modal.style.display = 'flex';
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>
