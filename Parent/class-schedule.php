<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-parent.php';

requireUserType('parent');

$currentParentId = getCurrentUserId();
if (!$currentParentId) {
    header('Location: ../signin.php');
    exit();
}

$currentParent = getCurrentUserData($pdo);
if (!$currentParent) {
    header('Location: ../signin.php');
    exit();
}

if ($currentParent['Parent_ID'] != $currentParentId) {
    error_log("Security violation: Parent ID mismatch for user ID: $currentParentId");
    header('Location: ../signin.php');
    exit();
}

$parentName = $currentParent['NameEn'] ?? $currentParent['Name'] ?? $_SESSION['user_name'] ?? 'Parent';

$linkedStudents = [];
$selectedStudentId = isset($_GET['student_id']) ? intval($_GET['student_id']) : null;

if ($currentParentId) {
    try {
        
        $stmt = $pdo->prepare("
            SELECT psr.Student_ID, s.NameEn, s.NameAr, s.Student_Code, s.Class_ID, c.Name as ClassName, c.Grade_Level, c.Section
            FROM parent_student_relationship psr
            INNER JOIN student s ON psr.Student_ID = s.Student_ID
            LEFT JOIN class c ON s.Class_ID = c.Class_ID
            WHERE psr.Parent_ID = ?
            ORDER BY s.NameEn ASC
        ");
        $stmt->execute([$currentParentId]);
        $linkedStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($selectedStudentId) {
            $validStudentIds = array_column($linkedStudents, 'Student_ID');
            if (!in_array($selectedStudentId, $validStudentIds)) {
                error_log("Security violation: Parent $currentParentId attempted to access schedule for student $selectedStudentId");
                $selectedStudentId = null; 
            }
        }

        if (!$selectedStudentId && !empty($linkedStudents)) {
            $selectedStudentId = $linkedStudents[0]['Student_ID'];
        }
    } catch (PDOException $e) {
        error_log("Error fetching linked students: " . $e->getMessage());
        $linkedStudents = [];
    }
}

$scheduleData = [];
$selectedStudentInfo = null;

if ($selectedStudentId) {
    
    foreach ($linkedStudents as $student) {
        if ($student['Student_ID'] == $selectedStudentId) {
            $selectedStudentInfo = $student;
            break;
        }
    }
    
    if ($selectedStudentInfo && $selectedStudentInfo['Class_ID']) {
        try {
            
            $stmt = $pdo->prepare("
                SELECT s.Schedule_ID, s.Day_Of_Week, s.Start_Time, s.End_Time, s.Subject, s.Room,
                       s.Course_ID, s.Teacher_ID, c.Course_Name, t.NameEn as Teacher_Name, cl.Name as ClassName
                FROM schedule s
                INNER JOIN student st ON s.Class_ID = st.Class_ID AND st.Student_ID = ?
                INNER JOIN parent_student_relationship psr ON st.Student_ID = psr.Student_ID AND psr.Parent_ID = ?
                LEFT JOIN course c ON s.Course_ID = c.Course_ID
                LEFT JOIN teacher t ON s.Teacher_ID = t.Teacher_ID
                LEFT JOIN class cl ON s.Class_ID = cl.Class_ID
                WHERE s.Class_ID = ? AND s.Type = 'Class'
                ORDER BY 
                    FIELD(s.Day_Of_Week, 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'),
                    s.Start_Time
            ");
            $stmt->execute([$selectedStudentId, $currentParentId, $selectedStudentInfo['Class_ID']]);
            $scheduleData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching schedule for parent: " . $e->getMessage());
            $scheduleData = [];
        }
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
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>
    
    <div class="main-content">

    <div class="dashboard-container">
        
        <div class="page-header-section">
            <button class="btn-back" onclick="window.location.href='parent-dashboard.php'" title="Back to Dashboard">
                <i class="fas fa-arrow-left"></i>
                <span data-en="Back to Dashboard" data-ar="العودة إلى لوحة التحكم">Back to Dashboard</span>
            </button>
            <h1 class="page-title">
                <span class="page-icon">📅</span>
                <span data-en="Class Schedule" data-ar="جدول الحصص">Class Schedule</span>
            </h1>
            <p class="page-subtitle" data-en="View your child's weekly class schedule and timings" data-ar="عرض جدول الحصص الأسبوعي وأوقات طفلك">View your child's weekly class schedule and timings</p>
        </div>

        <?php if (count($linkedStudents) > 1): ?>
        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">👨‍🎓</span>
                    <span data-en="Select Student" data-ar="اختر الطالب">Select Student</span>
                </h2>
            </div>
            <div style="padding: 1rem;">
                <select class="form-group" style="width: 100%; padding: 1rem; border: 3px solid #FFE5E5; border-radius: 15px; font-size: 1rem; font-weight: 600;" onchange="window.location.href='?student_id=' + this.value;">
                    <?php foreach ($linkedStudents as $student): ?>
                        <option value="<?php echo $student['Student_ID']; ?>" <?php echo ($selectedStudentId == $student['Student_ID']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($student['NameEn']); ?> 
                            <?php if ($student['ClassName']): ?>
                                - <?php echo htmlspecialchars($student['ClassName']); ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($selectedStudentInfo): ?>
        <div class="card" style="margin-bottom: 1.5rem; background: linear-gradient(135deg, #FFE5E5 0%, #E5F3FF 100%);">
            <div style="padding: 1.5rem;">
                <div style="font-weight: 700; font-size: 1.2rem; margin-bottom: 0.5rem; color: var(--text-dark);" data-en="Student Information" data-ar="معلومات الطالب">Student Information</div>
                <div style="font-size: 1.5rem; color: var(--primary-color); font-weight: 800; margin-bottom: 0.5rem;">
                    <?php echo htmlspecialchars($selectedStudentInfo['NameEn']); ?>
                </div>
                <?php if ($selectedStudentInfo['ClassName']): ?>
                <div style="font-size: 1.1rem; color: #666;">
                    <?php echo htmlspecialchars($selectedStudentInfo['ClassName']); ?>
                </div>
                <?php elseif ($selectedStudentInfo['Class_ID']): ?>
                <div style="font-size: 1.1rem; color: #999;" data-en="Class information not available" data-ar="معلومات الفصل غير متاحة">Class information not available</div>
                <?php else: ?>
                <div style="font-size: 1.1rem; color: #FF6B9D;" data-en="No class assigned" data-ar="لا يوجد فصل معين">No class assigned</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📅</span>
                    <span data-en="Class Schedule" data-ar="جدول الحصص">Class Schedule</span>
                </h2>
            </div>
            <?php if (empty($linkedStudents)): ?>
                <div style="text-align: center; padding: 3rem; color: #666;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">👨‍👩‍👧</div>
                    <div style="font-weight: 700; margin-bottom: 0.5rem;" data-en="No Students Linked" data-ar="لا يوجد طلاب مرتبطين">No Students Linked</div>
                    <div style="font-size: 0.9rem;" data-en="You don't have any students linked to your account. Please contact the administration." data-ar="ليس لديك أي طلاب مرتبطين بحسابك. يرجى الاتصال بالإدارة.">You don't have any students linked to your account. Please contact the administration.</div>
                </div>
            <?php elseif (!$selectedStudentId): ?>
                <div style="text-align: center; padding: 3rem; color: #666;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">📅</div>
                    <div style="font-weight: 700; margin-bottom: 0.5rem;" data-en="No Student Selected" data-ar="لم يتم اختيار طالب">No Student Selected</div>
                    <div style="font-size: 0.9rem;" data-en="Please select a student to view their schedule." data-ar="يرجى اختيار طالب لعرض جدوله.">Please select a student to view their schedule.</div>
                </div>
            <?php elseif (!$selectedStudentInfo || !$selectedStudentInfo['Class_ID']): ?>
                <div style="text-align: center; padding: 3rem; color: #666;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">📅</div>
                    <div style="font-weight: 700; margin-bottom: 0.5rem;" data-en="No Class Assigned" data-ar="لا يوجد فصل معين">No Class Assigned</div>
                    <div style="font-size: 0.9rem;" data-en="This student has not been assigned to a class yet." data-ar="لم يتم تعيين هذا الطالب إلى فصل بعد.">This student has not been assigned to a class yet.</div>
                </div>
            <?php elseif (empty($scheduleData)): ?>
                <div style="text-align: center; padding: 3rem; color: #666;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">📅</div>
                    <div style="font-weight: 700; margin-bottom: 0.5rem;" data-en="No Schedule Available" data-ar="لا يوجد جدول متاح">No Schedule Available</div>
                    <div style="font-size: 0.9rem;" data-en="The schedule for this student's class has not been set up yet." data-ar="لم يتم إعداد جدول فصل هذا الطالب بعد.">The schedule for this student's class has not been set up yet.</div>
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

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">ℹ️</span>
                    <span data-en="Schedule Information" data-ar="معلومات الجدول">Schedule Information</span>
                </h2>
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-icon">🕐</div>
                    <div class="info-content">
                        <div class="info-label" data-en="School Hours" data-ar="ساعات المدرسة">School Hours</div>
                        <div class="info-value" data-en="8:00 AM - 1:00 PM" data-ar="8:00 صباحاً - 1:00 ظهراً">8:00 AM - 1:00 PM</div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon">☕</div>
                    <div class="info-content">
                        <div class="info-label" data-en="Break Time" data-ar="وقت الاستراحة">Break Time</div>
                        <div class="info-value" data-en="11:00 AM - 11:30 AM" data-ar="11:00 صباحاً - 11:30 صباحاً">11:00 AM - 11:30 AM</div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon">📚</div>
                    <div class="info-content">
                        <div class="info-label" data-en="Total Periods" data-ar="إجمالي الحصص">Total Periods</div>
                        <div class="info-value" data-en="5 periods per day" data-ar="5 حصص يومياً">5 periods per day</div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon">⏱️</div>
                    <div class="info-content">
                        <div class="info-label" data-en="Period Duration" data-ar="مدة الحصة">Period Duration</div>
                        <div class="info-value" data-en="45 minutes" data-ar="45 دقيقة">45 minutes</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="profileModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <span onclick="closeProfileSettings()" style="float: right; font-size: 32px; cursor: pointer; color: #FF6B9D; font-weight: bold;">&times;</span>
            <h2 data-en="Profile Settings" data-ar="إعدادات الملف الشخصي">Profile Settings</h2>
            <form onsubmit="handleProfileUpdate(event)">
                <div class="form-group">
                    <label data-en="Phone Number" data-ar="رقم الهاتف">Phone Number</label>
                    <input type="tel" value="+962 7XX XXX XXX" required>
                </div>
                <div class="form-group">
                    <label data-en="Email" data-ar="البريد الإلكتروني">Email</label>
                    <input type="email" value="parent@example.com" required>
                </div>
                <div class="form-group">
                    <label data-en="Address" data-ar="العنوان">Address</label>
                    <textarea rows="3">Amman, Jordan</textarea>
                </div>
                <div class="form-group">
                    <label data-en="Change Password" data-ar="تغيير كلمة المرور">Change Password</label>
                    <input type="password" placeholder="Enter new password">
                </div>
                <button type="submit" class="btn btn-primary" data-en="Save Changes" data-ar="حفظ التغييرات">Save Changes</button>
            </form>
        </div>
    </div>

    <div id="settingsModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <span onclick="closeSettings()" style="float: right; font-size: 32px; cursor: pointer; color: #FF6B9D; font-weight: bold;">&times;</span>
            <h2 data-en="Program Settings" data-ar="إعدادات البرنامج">Program Settings</h2>
            <div class="settings-section">
                <h3 data-en="Notifications" data-ar="الإشعارات">Notifications</h3>
                <div class="setting-item">
                    <label>
                        <input type="checkbox" checked onchange="toggleSetting('email')">
                        <span data-en="Email Notifications" data-ar="إشعارات البريد الإلكتروني">Email Notifications</span>
                    </label>
                </div>
                <div class="setting-item">
                    <label>
                        <input type="checkbox" checked onchange="toggleSetting('assignments')">
                        <span data-en="Assignment Reminders" data-ar="تذكيرات الواجبات">Assignment Reminders</span>
                    </label>
                </div>
                <div class="setting-item">
                    <label>
                        <input type="checkbox" checked onchange="toggleSetting('grades')">
                        <span data-en="Grade Updates" data-ar="تحديثات الدرجات">Grade Updates</span>
                    </label>
                </div>
                <div class="setting-item">
                    <label>
                        <input type="checkbox" checked onchange="toggleSetting('messages')">
                        <span data-en="Teacher Messages" data-ar="رسائل المعلم">Teacher Messages</span>
                    </label>
                </div>
            </div>
            <div class="settings-section">
                <h3 data-en="Appearance" data-ar="المظهر">Appearance</h3>
                <div class="setting-item">
                    <label data-en="Theme Color" data-ar="لون المظهر">Theme Color</label>
                    <input type="color" value="#FF6B9D" onchange="changeTheme(this.value)">
                </div>
                <div class="setting-item">
                    <label>
                        <input type="checkbox" onchange="toggleSetting('darkMode')">
                        <span data-en="Dark Mode" data-ar="الوضع الداكن">Dark Mode</span>
                    </label>
                </div>
            </div>
            <button onclick="saveSettings()" class="btn btn-primary" data-en="Save Settings" data-ar="حفظ الإعدادات">Save Settings</button>
        </div>
    </div>

    <script src="script.js"></script>
</body>
</html>

