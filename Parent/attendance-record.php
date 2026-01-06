<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-parent.php';

requireUserType('parent');

$currentParentId = getCurrentUserId();
$currentParent = getCurrentUserData($pdo);
$parentName = $_SESSION['user_name'] ?? 'Parent';

$linkedStudentIds = [];
$linkedStudentsData = [];
if ($currentParentId) {
    try {
        $stmt = $pdo->prepare("
            SELECT psr.Student_ID, psr.Relationship_Type, psr.Is_Primary,
                   s.Student_ID, s.Student_Code, s.NameEn, s.NameAr, s.Class_ID,
                   c.Name as Class_Name
            FROM parent_student_relationship psr
            INNER JOIN student s ON psr.Student_ID = s.Student_ID
            LEFT JOIN class c ON s.Class_ID = c.Class_ID
            WHERE psr.Parent_ID = ?
            ORDER BY psr.Is_Primary DESC, s.NameEn ASC
        ");
        $stmt->execute([$currentParentId]);
        $linkedStudentsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $linkedStudentIds = array_column($linkedStudentsData, 'Student_ID');
    } catch (PDOException $e) {
        error_log("Error fetching linked students: " . $e->getMessage());
    }
}

$selectedStudentId = isset($_GET['studentId']) ? intval($_GET['studentId']) : (!empty($linkedStudentIds) ? $linkedStudentIds[0] : null);

$attendanceRecords = [];
$attendanceStats = [
    'total_present' => 0,
    'total_absent' => 0,
    'total_late' => 0,
    'total_excused' => 0,
    'attendance_rate' => 0
];

if ($selectedStudentId && in_array($selectedStudentId, $linkedStudentIds)) {
    try {
        
        $stmt = $pdo->prepare("
            SELECT 
                a.Attendance_ID,
                a.Date,
                a.Status,
                a.Notes,
                a.Excuse_Submitted,
                a.Excuse_Approved,
                a.Excuse_File_Path,
                a.Created_At
            FROM attendance a
            WHERE a.Student_ID = ?
            ORDER BY a.Date DESC
        ");
        $stmt->execute([$selectedStudentId]);
        $attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalRecords = count($attendanceRecords);
        foreach ($attendanceRecords as $record) {
            $status = strtolower($record['Status']);
            switch ($status) {
                case 'present':
                    $attendanceStats['total_present']++;
                    break;
                case 'absent':
                    $attendanceStats['total_absent']++;
                    break;
                case 'late':
                    $attendanceStats['total_late']++;
                    break;
                case 'excused':
                    $attendanceStats['total_excused']++;
                    break;
            }
        }

        if ($totalRecords > 0) {
            $attended = $attendanceStats['total_present'] + $attendanceStats['total_excused'];
            $attendanceStats['attendance_rate'] = round(($attended / $totalRecords) * 100, 1);
        }
    } catch (PDOException $e) {
        error_log("Error fetching attendance records: " . $e->getMessage());
    }
}

$attendanceByMonth = [];
foreach ($attendanceRecords as $record) {
    $date = new DateTime($record['Date']);
    $monthKey = $date->format('Y-m');
    $monthName = $date->format('F Y');
    
    if (!isset($attendanceByMonth[$monthKey])) {
        $attendanceByMonth[$monthKey] = [
            'name' => $monthName,
            'key' => strtolower($date->format('F')),
            'records' => [],
            'stats' => ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0]
        ];
    }
    
    $attendanceByMonth[$monthKey]['records'][] = $record;
    $status = strtolower($record['Status']);
    if (isset($attendanceByMonth[$monthKey]['stats'][$status])) {
        $attendanceByMonth[$monthKey]['stats'][$status]++;
    }
}

krsort($attendanceByMonth);
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Record - HUDURY</title>
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
                <span data-en="Attendance Record" data-ar="سجل الحضور">Attendance Record</span>
            </h1>
            <p class="page-subtitle" data-en="Track your child's daily attendance" data-ar="تتبع حضور طفلك اليومي">Track your child's daily attendance</p>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📅</span>
                    <span data-en="Attendance Record" data-ar="سجل الحضور">Attendance Record</span>
                </h2>
                <div class="attendance-filters" style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                    <?php if (count($linkedStudentsData) > 1): ?>
                        <select class="month-filter" id="studentFilter" onchange="window.location.href='?studentId=' + this.value" style="padding: 0.5rem; border-radius: 8px; border: 2px solid #FFE5E5;">
                            <?php foreach ($linkedStudentsData as $student): ?>
                                <option value="<?php echo $student['Student_ID']; ?>" <?php echo ($selectedStudentId == $student['Student_ID']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($student['NameEn'] ?? $student['NameAr'] ?? 'Student'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <select class="month-filter" id="monthFilter" onchange="filterAttendanceByMonth()">
                        <option value="all" data-en="All Months" data-ar="جميع الأشهر">All Months</option>
                        <?php foreach ($attendanceByMonth as $monthKey => $monthData): ?>
                            <option value="<?php echo htmlspecialchars($monthData['key']); ?>" data-month="<?php echo htmlspecialchars($monthKey); ?>">
                                <?php echo htmlspecialchars($monthData['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if (empty($linkedStudentIds)): ?>
                <div style="text-align: center; padding: 3rem; color: #666;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">📚</div>
                    <div style="font-size: 1.2rem; font-weight: 700; margin-bottom: 0.5rem;" data-en="No Students Linked" data-ar="لا يوجد طلاب مرتبطين">No Students Linked</div>
                    <div style="font-size: 0.9rem;" data-en="Please contact the administrator to link your child(ren) to your account." data-ar="يرجى الاتصال بالإدارة لربط طفلك (أطفالك) بحسابك.">
                        Please contact the administrator to link your child(ren) to your account.
                    </div>
                </div>
            <?php elseif (empty($attendanceRecords)): ?>
                <div style="text-align: center; padding: 3rem; color: #666;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">📅</div>
                    <div style="font-size: 1.2rem; font-weight: 700; margin-bottom: 0.5rem;" data-en="No Attendance Records" data-ar="لا توجد سجلات حضور">No Attendance Records</div>
                    <div style="font-size: 0.9rem;" data-en="Attendance records will appear here once they are recorded by teachers." data-ar="ستظهر سجلات الحضور هنا بمجرد تسجيلها من قبل المعلمين.">
                        Attendance records will appear here once they are recorded by teachers.
                    </div>
                </div>
            <?php else: ?>
                <div class="attendance-calendar-view">
                    <?php 
                    $dayNamesEn = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                    $dayNamesAr = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
                    
                    foreach ($attendanceByMonth as $monthKey => $monthData): 
                        $monthDate = new DateTime($monthKey . '-01');
                    ?>
                        <div class="attendance-month-section" data-month="<?php echo htmlspecialchars($monthData['key']); ?>">
                            <div class="month-header">
                                <h3 class="attendance-month-title">
                                    <span><?php echo htmlspecialchars($monthData['name']); ?></span>
                                    <span class="month-stats">
                                        <span class="stat-present" data-en="<?php echo $monthData['stats']['present']; ?> Present" data-ar="<?php echo $monthData['stats']['present']; ?> حاضر">
                                            <?php echo $monthData['stats']['present']; ?> Present
                                        </span>
                                        <span class="stat-absent" data-en="<?php echo $monthData['stats']['absent']; ?> Absent" data-ar="<?php echo $monthData['stats']['absent']; ?> غائب">
                                            <?php echo $monthData['stats']['absent']; ?> Absent
                                        </span>
                                        <?php if ($monthData['stats']['late'] > 0): ?>
                                            <span class="stat-late" data-en="<?php echo $monthData['stats']['late']; ?> Late" data-ar="<?php echo $monthData['stats']['late']; ?> متأخر">
                                                <?php echo $monthData['stats']['late']; ?> Late
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </h3>
                            </div>
                            <div class="attendance-days-grid">
                                <?php 
                                
                                usort($monthData['records'], function($a, $b) {
                                    return strtotime($b['Date']) - strtotime($a['Date']);
                                });
                                
                                foreach ($monthData['records'] as $record): 
                                    $recordDate = new DateTime($record['Date']);
                                    $dayOfWeek = $recordDate->format('w'); 
                                    $dayNameEn = $dayNamesEn[$dayOfWeek];
                                    $dayNameAr = $dayNamesAr[$dayOfWeek];
                                    $status = strtolower($record['Status']);
                                    $hasNote = !empty($record['Notes']) || $record['Excuse_Submitted'] == 1;
                                    $dateStr = $record['Date'];
                                ?>
                                    <div class="attendance-day-card status-<?php echo $status; ?> <?php echo $hasNote ? 'has-note' : ''; ?>" 
                                         data-date="<?php echo htmlspecialchars($dateStr); ?>" 
                                         data-day="<?php echo htmlspecialchars($dayNameEn); ?>">
                                        <div class="day-header">
                                            <div class="day-name" data-en="<?php echo htmlspecialchars($dayNameEn); ?>" data-ar="<?php echo htmlspecialchars($dayNameAr); ?>">
                                                <?php echo htmlspecialchars($dayNameEn); ?>
                                            </div>
                                            <div class="day-date"><?php echo $recordDate->format('d'); ?></div>
                                        </div>
                                        <div class="day-status">
                                            <?php
                                            $icons = [
                                                'present' => '✅',
                                                'absent' => '❌',
                                                'late' => '⏰',
                                                'excused' => '📋'
                                            ];
                                            $labels = [
                                                'present' => ['en' => 'Present', 'ar' => 'حاضر'],
                                                'absent' => ['en' => 'Absent', 'ar' => 'غائب'],
                                                'late' => ['en' => 'Late', 'ar' => 'متأخر'],
                                                'excused' => ['en' => 'Excused', 'ar' => 'معذور']
                                            ];
                                            $icon = $icons[$status] ?? '📋';
                                            $label = $labels[$status] ?? ['en' => ucfirst($status), 'ar' => ucfirst($status)];
                                            ?>
                                            <span class="status-icon"><?php echo $icon; ?></span>
                                            <span class="status-text" data-en="<?php echo $label['en']; ?>" data-ar="<?php echo $label['ar']; ?>">
                                                <?php echo $label['en']; ?>
                                            </span>
                                        </div>
                                        <?php if ($hasNote): ?>
                                            <div class="day-note">
                                                <i class="fas fa-file-alt"></i>
                                                <span>
                                                    <?php 
                                                    if ($record['Excuse_Submitted'] == 1 && $record['Excuse_Approved'] == 1) {
                                                        echo htmlspecialchars($record['Notes'] ?: 'Excuse approved');
                                                    } elseif ($record['Excuse_Submitted'] == 1) {
                                                        echo htmlspecialchars($record['Notes'] ?: 'Excuse submitted');
                                                    } else {
                                                        echo htmlspecialchars($record['Notes']);
                                                    }
                                                    ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📊</span>
                    <span data-en="Attendance Summary" data-ar="ملخص الحضور">Attendance Summary</span>
                </h2>
            </div>
            <div class="attendance-summary-grid">
                <div class="summary-item">
                    <div class="summary-icon">✅</div>
                    <div class="summary-content">
                        <div class="summary-label" data-en="Total Present Days" data-ar="إجمالي أيام الحضور">Total Present Days</div>
                        <div class="summary-value present"><?php echo $attendanceStats['total_present']; ?></div>
                    </div>
                </div>
                <div class="summary-item">
                    <div class="summary-icon">❌</div>
                    <div class="summary-content">
                        <div class="summary-label" data-en="Total Absent Days" data-ar="إجمالي أيام الغياب">Total Absent Days</div>
                        <div class="summary-value absent"><?php echo $attendanceStats['total_absent']; ?></div>
                    </div>
                </div>
                <div class="summary-item">
                    <div class="summary-icon">⏰</div>
                    <div class="summary-content">
                        <div class="summary-label" data-en="Late Arrivals" data-ar="التأخيرات">Late Arrivals</div>
                        <div class="summary-value late"><?php echo $attendanceStats['total_late']; ?></div>
                    </div>
                </div>
                <div class="summary-item">
                    <div class="summary-icon">📈</div>
                    <div class="summary-content">
                        <div class="summary-label" data-en="Attendance Rate" data-ar="معدل الحضور">Attendance Rate</div>
                        <div class="summary-value rate"><?php echo $attendanceStats['attendance_rate']; ?>%</div>
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
    <script>
        function filterAttendanceByMonth() {
            const selectedMonth = document.getElementById('monthFilter').value;
            const monthSections = document.querySelectorAll('.attendance-month-section');
            
            monthSections.forEach(section => {
                if (selectedMonth === 'all') {
                    section.style.display = 'block';
                } else {
                    const sectionMonth = section.getAttribute('data-month');
                    section.style.display = (sectionMonth === selectedMonth) ? 'block' : 'none';
                }
            });
        }
    </script>
</body>
</html>

