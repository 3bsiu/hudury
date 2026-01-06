<?php

if (!function_exists('getTimeAgo')) {
    function getTimeAgo($datetime) {
        if (!($datetime instanceof DateTime)) {
            try {
                $datetime = new DateTime($datetime);
            } catch (Exception $e) {
                return 'Recently';
            }
        }
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
}

if (!isset($notifications)) {
    $notifications = [];

    $currentUserId = getCurrentUserId();
    $currentUserType = getCurrentUserType();
    
    if ($currentUserId && $currentUserType) {
        try {
            if ($currentUserType === 'teacher' && file_exists(__DIR__ . '/notifications-teacher.php')) {
                require_once __DIR__ . '/notifications-teacher.php';
            } elseif ($currentUserType === 'student' && file_exists(__DIR__ . '/notifications-student.php')) {
                require_once __DIR__ . '/notifications-student.php';
            } elseif ($currentUserType === 'parent' && file_exists(__DIR__ . '/notifications-parent.php')) {
                require_once __DIR__ . '/notifications-parent.php';
            } elseif ($currentUserType === 'admin' && file_exists(__DIR__ . '/notifications-admin.php')) {
                require_once __DIR__ . '/notifications-admin.php';
            }
        } catch (Exception $e) {
            error_log("Error loading notifications in unified header: " . $e->getMessage());
        }
    }

    if (!isset($notifications)) {
        $notifications = [];
    }
}

$currentUserId = getCurrentUserId();
$currentUserType = getCurrentUserType();
$currentUser = getCurrentUserData($pdo);

$accountName = '';
$accountInfo = '';
$accountIcon = '';
$dashboardUrl = '';

$readNotificationIds = [];
try {
    $stmt = $pdo->prepare("SELECT Notif_ID FROM notification_read WHERE User_Type = ? AND User_ID = ?");
    $stmt->execute([$currentUserType, $currentUserId]);
    $readNotificationIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Error checking notification_read table: " . $e->getMessage());
}

$unreadNotifications = array_filter($notifications, function($notif) use ($readNotificationIds) {
    return !in_array($notif['Notif_ID'], $readNotificationIds);
});
$unreadCount = count($unreadNotifications);

switch ($currentUserType) {
    case 'student':
        $accountName = $currentUser['NameEn'] ?? $currentUser['Name'] ?? 'Student';
        $accountIcon = '👨‍🎓';

        $studentClass = null;
        if ($currentUser && isset($currentUser['Class_ID'])) {
            try {
                $stmt = $pdo->prepare("SELECT Name, Grade_Level, Section FROM class WHERE Class_ID = ?");
                $stmt->execute([$currentUser['Class_ID']]);
                $studentClass = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log("Error fetching student class: " . $e->getMessage());
            }
        }
        
        if ($studentClass) {
            $accountInfo = $studentClass['Name'] ?? ('Grade ' . ($studentClass['Grade_Level'] ?? 'N/A') . ' - Section ' . strtoupper($studentClass['Section'] ?? 'N/A'));
        } else {
            $accountInfo = 'No Class Assigned';
        }
        
        $dashboardUrl = 'student-dashboard.php';
        break;
        
    case 'parent':
        $accountName = $currentUser['NameEn'] ?? $currentUser['NameAr'] ?? 'Parent';
        $accountIcon = '👨‍👩‍👧‍👦';

        $linkedStudents = [];
        if ($currentUserId) {
            try {
                $stmt = $pdo->prepare("
                    SELECT s.Student_ID, s.NameEn, s.NameAr, c.Name as Class_Name
                    FROM parent_student_relationship psr
                    INNER JOIN student s ON psr.Student_ID = s.Student_ID
                    LEFT JOIN class c ON s.Class_ID = c.Class_ID
                    WHERE psr.Parent_ID = ?
                    ORDER BY psr.Is_Primary DESC, s.NameEn ASC
                    LIMIT 3
                ");
                $stmt->execute([$currentUserId]);
                $linkedStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log("Error fetching linked students: " . $e->getMessage());
            }
        }
        
        if (!empty($linkedStudents)) {
            $studentNames = array_map(function($s) {
                return ($s['NameEn'] ?? $s['NameAr'] ?? 'Student') . ($s['Class_Name'] ? ' (' . $s['Class_Name'] . ')' : '');
            }, $linkedStudents);
            $accountInfo = implode(', ', $studentNames);
            if (count($linkedStudents) > 3) {
                $accountInfo .= ' +' . (count($linkedStudents) - 3) . ' more';
            }
        } else {
            $accountInfo = 'No Students Linked';
        }
        
        $dashboardUrl = 'parent-dashboard.php';
        break;
        
    case 'teacher':
        $accountName = $currentUser['NameEn'] ?? $currentUser['Name'] ?? 'Teacher';
        $accountIcon = '👨‍🏫';

        $teacherInfo = [];
        $teacherInfo[] = 'Teacher'; 

        $teacherSubjects = [];
        if ($currentUserId) {
            try {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT co.Course_Name
                    FROM teacher_class_course tcc
                    INNER JOIN course co ON tcc.Course_ID = co.Course_ID
                    WHERE tcc.Teacher_ID = ?
                    ORDER BY co.Course_Name
                    LIMIT 5
                ");
                $stmt->execute([$currentUserId]);
                $teacherSubjects = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (PDOException $e) {
                error_log("Error fetching teacher subjects: " . $e->getMessage());
            }
        }
        
        if (!empty($teacherSubjects)) {
            $accountInfo = implode(', ', $teacherSubjects);
            if (count($teacherSubjects) > 5) {
                $accountInfo .= ' +' . (count($teacherSubjects) - 5) . ' more';
            }
        } else {
            $accountInfo = 'No Subjects Assigned';
        }
        
        $dashboardUrl = 'teacher-dashboard.php';
        break;
        
    case 'admin':
        $accountName = $currentUser['NameEn'] ?? $currentUser['Name'] ?? 'Admin';
        $accountIcon = '👨‍💼';
        $accountInfo = 'System Administrator';
        $dashboardUrl = 'admin-dashboard.php';
        break;
        
    default:
        $accountName = 'User';
        $accountInfo = '';
        $accountIcon = '👤';
        $dashboardUrl = '../homepage.php';
}

$isDashboard = false;
$currentPage = basename($_SERVER['PHP_SELF']);
$dashboardPages = ['student-dashboard.php', 'parent-dashboard.php', 'teacher-dashboard.php', 'admin-dashboard.php'];
if (in_array($currentPage, $dashboardPages)) {
    $isDashboard = true;
}

$currentLang = $_SESSION['language'] ?? 'en';
?>

<header class="unified-header" id="unifiedHeader">
    
    <div class="dropdown-overlay" id="dropdownOverlay" onclick="closeAllDropdowns()"></div>
    <div class="header-container">
        
        <div class="header-account-info">
            <div class="account-icon"><?php echo $accountIcon; ?></div>
            <div class="account-details">
                <div class="account-name"><?php echo htmlspecialchars($accountName); ?></div>
                <div class="account-meta"><?php echo htmlspecialchars($accountInfo); ?></div>
            </div>
        </div>

        <div class="header-actions">
            
            <button class="header-btn quick-menu-btn" onclick="toggleQuickMenu()" title="Quick Menu">
                <i class="fas fa-bars"></i>
                <span class="btn-label" data-en="Menu" data-ar="القائمة">Menu</span>
            </button>

            <?php if ($currentUserType !== 'admin'): ?>
            <div style="position: relative;">
                <button class="header-btn notification-btn" onclick="toggleNotificationsDropdown()" title="Notifications">
                    <i class="fas fa-bell"></i>
                    <span class="btn-label" data-en="Notifications" data-ar="الإشعارات">Notifications</span>
                    <?php if ($unreadCount > 0): ?>
                        <span class="notification-badge" id="notificationBadge"><?php echo $unreadCount; ?></span>
                    <?php endif; ?>
                </button>
                
                <div class="notifications-dropdown" id="notificationsDropdown">
                    <div class="notifications-dropdown-header">
                        <h3 data-en="Notifications" data-ar="الإشعارات">Notifications</h3>
                        <?php if ($unreadCount > 0): ?>
                            <button onclick="markAllNotificationsAsRead()" class="mark-all-read-btn" data-en="Mark all as read" data-ar="تعليم الكل كمقروء">Mark all as read</button>
                        <?php endif; ?>
                    </div>
                    <div class="notifications-dropdown-content" id="notificationsContent">
                        <?php if (empty($notifications)): ?>
                            <div class="no-notifications">
                                <div class="no-notifications-icon">🔔</div>
                                <div data-en="No notifications" data-ar="لا توجد إشعارات">No notifications</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($notifications as $notif): ?>
                                <?php
                                $isRead = in_array($notif['Notif_ID'], $readNotificationIds);
                                $dateSent = new DateTime($notif['Date_Sent']);
                                $timeAgo = getTimeAgo($dateSent);
                                $icon = '🔔';
                                if (stripos($notif['Title'], 'assignment') !== false || stripos($notif['Title'], 'واجب') !== false) {
                                    $icon = '📝';
                                } elseif (stripos($notif['Title'], 'grade') !== false || stripos($notif['Title'], 'درجة') !== false) {
                                    $icon = '⭐';
                                } elseif (stripos($notif['Title'], 'exam') !== false || stripos($notif['Title'], 'امتحان') !== false) {
                                    $icon = '📅';
                                } elseif (stripos($notif['Title'], 'message') !== false || stripos($notif['Title'], 'رسالة') !== false) {
                                    $icon = '💬';
                                }
                                ?>
                                <div class="notification-item <?php echo $isRead ? 'read' : 'unread'; ?>" 
                                     data-notif-id="<?php echo $notif['Notif_ID']; ?>"
                                     onclick="handleNotificationClick(this, <?php echo $notif['Notif_ID']; ?>)">
                                    <div class="notification-icon"><?php echo $icon; ?></div>
                                    <div class="notification-content">
                                        <div class="notification-title"><?php echo htmlspecialchars($notif['Title']); ?></div>
                                        <div class="notification-message"><?php echo htmlspecialchars(substr($notif['Content'], 0, 100)) . (strlen($notif['Content']) > 100 ? '...' : ''); ?></div>
                                        <div class="notification-time"><?php echo $timeAgo; ?></div>
                                    </div>
                                    <?php if (!$isRead): ?>
                                        <div class="notification-unread-indicator"></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($currentUserType !== 'admin'): ?>
            <button class="header-btn profile-btn" onclick="openProfile()" title="Profile">
                <i class="fas fa-user-circle"></i>
                <span class="btn-label" data-en="Profile" data-ar="الملف الشخصي">Profile</span>
            </button>
            <?php endif; ?>

            <?php if (!$isDashboard): ?>
                <a href="<?php echo $dashboardUrl; ?>" class="header-btn dashboard-btn" title="Back to Dashboard">
                    <i class="fas fa-home"></i>
                    <span class="btn-label" data-en="Dashboard" data-ar="لوحة التحكم">Dashboard</span>
                </a>
            <?php endif; ?>

            <button class="header-btn language-btn" onclick="toggleLanguage()" title="Switch Language">
                <i class="fas fa-language"></i>
                <span class="btn-label" data-en="<?php echo $currentLang === 'en' ? 'العربية' : 'English'; ?>" data-ar="<?php echo $currentLang === 'en' ? 'العربية' : 'English'; ?>">
                    <?php echo $currentLang === 'en' ? 'العربية' : 'English'; ?>
                </span>
            </button>
        </div>
    </div>

    <div class="quick-menu-dropdown" id="quickMenuDropdown">
        <div class="quick-menu-content">
            <?php
            
            if ($currentUserType === 'teacher') {
                
                echo '<a href="teacher-dashboard.php" class="quick-menu-item"><i class="fas fa-home"></i> <span data-en="Dashboard" data-ar="لوحة التحكم">Dashboard</span></a>';
                
                echo '<a href="assignments.php" class="quick-menu-item"><i class="fas fa-tasks"></i> <span data-en="Student Submissions" data-ar="تقديمات الطلاب">Student Submissions</span></a>';
                echo '<a href="assignment-management.php" class="quick-menu-item"><i class="fas fa-clipboard-list"></i> <span data-en="Assignment Management" data-ar="إدارة الواجبات">Assignment Management</span></a>';
                echo '<a href="assignments-dashboard.php" class="quick-menu-item"><i class="fas fa-chart-bar"></i> <span data-en="Assignments Dashboard" data-ar="لوحة تحكم الواجبات">Assignments Dashboard</span></a>';
                
                echo '<a href="grade-management.php" class="quick-menu-item"><i class="fas fa-graduation-cap"></i> <span data-en="Grade Management" data-ar="إدارة الدرجات">Grade Management</span></a>';
                
                echo '<a href="attendance-management.php" class="quick-menu-item"><i class="fas fa-calendar-check"></i> <span data-en="Attendance Management" data-ar="إدارة الحضور">Attendance Management</span></a>';
                
                echo '<a href="class-supervision.php" class="quick-menu-item"><i class="fas fa-users"></i> <span data-en="Class Supervision" data-ar="إشراف الفصل">Class Supervision</span></a>';
                
                echo '<a href="parent-chat.php" class="quick-menu-item"><i class="fas fa-comments"></i> <span data-en="Parent Chat" data-ar="دردشة أولياء الأمور">Parent Chat</span></a>';
                
                echo '<a href="send-notifications.php" class="quick-menu-item"><i class="fas fa-bell"></i> <span data-en="Send Notifications" data-ar="إرسال إشعارات">Send Notifications</span></a>';
                
                echo '<a href="quick-reports.php" class="quick-menu-item"><i class="fas fa-chart-line"></i> <span data-en="Quick Reports" data-ar="التقارير السريعة">Quick Reports</span></a>';
                
                echo '<a href="notifications-and-settings.php" class="quick-menu-item"><i class="fas fa-cog"></i> <span data-en="Settings" data-ar="الإعدادات">Settings</span></a>';
            } elseif ($currentUserType === 'student') {
                
                echo '<a href="student-dashboard.php" class="quick-menu-item"><i class="fas fa-home"></i> <span data-en="Dashboard" data-ar="لوحة التحكم">Dashboard</span></a>';
                
                echo '<a href="my-assignments.php" class="quick-menu-item"><i class="fas fa-tasks"></i> <span data-en="My Assignments" data-ar="واجباتي">My Assignments</span></a>';
                
                echo '<a href="academic-performance.php" class="quick-menu-item"><i class="fas fa-chart-line"></i> <span data-en="Academic Performance" data-ar="الأداء الأكاديمي">Academic Performance</span></a>';
                echo '<a href="upcoming-exam-dates.php" class="quick-menu-item"><i class="fas fa-calendar-alt"></i> <span data-en="Upcoming Exams" data-ar="الامتحانات القادمة">Upcoming Exams</span></a>';
                
                echo '<a href="attendance-record.php" class="quick-menu-item"><i class="fas fa-calendar-check"></i> <span data-en="Attendance Record" data-ar="سجل الحضور">Attendance Record</span></a>';
                
                echo '<a href="class-schedule.php" class="quick-menu-item"><i class="fas fa-calendar"></i> <span data-en="Class Schedule" data-ar="جدول الحصص">Class Schedule</span></a>';
                
                echo '<a href="chat-with-teachers.php" class="quick-menu-item"><i class="fas fa-comments"></i> <span data-en="Chat with Teachers" data-ar="التحدث مع المعلمين">Chat with Teachers</span></a>';
                
                echo '<a href="notifications-and-settings.php" class="quick-menu-item"><i class="fas fa-cog"></i> <span data-en="Settings" data-ar="الإعدادات">Settings</span></a>';
            } elseif ($currentUserType === 'parent') {
                
                echo '<a href="parent-dashboard.php" class="quick-menu-item"><i class="fas fa-home"></i> <span data-en="Dashboard" data-ar="لوحة التحكم">Dashboard</span></a>';
                
                echo '<a href="academic-performance.php" class="quick-menu-item"><i class="fas fa-chart-line"></i> <span data-en="Academic Performance" data-ar="الأداء الأكاديمي">Academic Performance</span></a>';
                
                echo '<a href="attendance-record.php" class="quick-menu-item"><i class="fas fa-calendar-check"></i> <span data-en="Attendance Record" data-ar="سجل الحضور">Attendance Record</span></a>';
                
                echo '<a href="class-schedule.php" class="quick-menu-item"><i class="fas fa-calendar"></i> <span data-en="Class Schedule" data-ar="جدول الحصص">Class Schedule</span></a>';
                
                echo '<a href="chat-with-teachers.php" class="quick-menu-item"><i class="fas fa-comments"></i> <span data-en="Chat with Teachers" data-ar="التحدث مع المعلمين">Chat with Teachers</span></a>';
                
                echo '<a href="medical-records.php" class="quick-menu-item"><i class="fas fa-heartbeat"></i> <span data-en="Medical Records" data-ar="السجلات الطبية">Medical Records</span></a>';
                
                echo '<a href="request-leave.php" class="quick-menu-item"><i class="fas fa-calendar-times"></i> <span data-en="Request Leave" data-ar="طلب إجازة">Request Leave</span></a>';
                
                echo '<a href="installments.php" class="quick-menu-item"><i class="fas fa-money-bill-wave"></i> <span data-en="Installments" data-ar="الأقساط">Installments</span></a>';
                
                echo '<a href="notifications-and-settings.php" class="quick-menu-item"><i class="fas fa-cog"></i> <span data-en="Settings" data-ar="الإعدادات">Settings</span></a>';
            } elseif ($currentUserType === 'admin') {
                
                echo '<a href="admin-dashboard.php" class="quick-menu-item"><i class="fas fa-home"></i> <span data-en="Dashboard" data-ar="لوحة التحكم">Dashboard</span></a>';
                
                echo '<a href="user-management.php" class="quick-menu-item"><i class="fas fa-users"></i> <span data-en="User Management" data-ar="إدارة المستخدمين">User Management</span></a>';
                
                echo '<a href="exam-management.php" class="quick-menu-item"><i class="fas fa-clipboard-list"></i> <span data-en="Exam Management" data-ar="إدارة الامتحانات">Exam Management</span></a>';
                echo '<a href="academic-status-management.php" class="quick-menu-item"><i class="fas fa-graduation-cap"></i> <span data-en="Academic Status" data-ar="الحالة الأكاديمية">Academic Status</span></a>';
                
                echo '<a href="classes-management.php" class="quick-menu-item"><i class="fas fa-school"></i> <span data-en="Classes Management" data-ar="إدارة الفصول">Classes Management</span></a>';
                echo '<a href="courses-management.php" class="quick-menu-item"><i class="fas fa-book"></i> <span data-en="Courses Management" data-ar="إدارة المقررات">Courses Management</span></a>';
                echo '<a href="class-schedule-management.php" class="quick-menu-item"><i class="fas fa-calendar-alt"></i> <span data-en="Class Schedules" data-ar="جداول الفصول">Class Schedules</span></a>';
                
                echo '<a href="attendance-management.php" class="quick-menu-item"><i class="fas fa-calendar-check"></i> <span data-en="Attendance" data-ar="الحضور">Attendance</span></a>';
                echo '<a href="medical-records.php" class="quick-menu-item"><i class="fas fa-heartbeat"></i> <span data-en="Medical Records" data-ar="السجلات الطبية">Medical Records</span></a>';
                
                echo '<a href="school-events-management.php" class="quick-menu-item"><i class="fas fa-calendar-day"></i> <span data-en="School Events" data-ar="أحداث المدرسة">School Events</span></a>';
                echo '<a href="school-news-management.php" class="quick-menu-item"><i class="fas fa-newspaper"></i> <span data-en="School News" data-ar="أخبار المدرسة">School News</span></a>';
                
                echo '<a href="notifications-management.php" class="quick-menu-item"><i class="fas fa-bell"></i> <span data-en="Notifications" data-ar="الإشعارات">Notifications</span></a>';
                echo '<a href="leave-requests.php" class="quick-menu-item"><i class="fas fa-calendar-times"></i> <span data-en="Leave Requests" data-ar="طلبات الإجازة">Leave Requests</span></a>';
                
                echo '<a href="installments-management.php" class="quick-menu-item"><i class="fas fa-money-bill-wave"></i> <span data-en="Installments" data-ar="الأقساط">Installments</span></a>';
                
                echo '<a href="reports-analytics.php" class="quick-menu-item"><i class="fas fa-chart-bar"></i> <span data-en="Reports & Analytics" data-ar="التقارير والتحليلات">Reports & Analytics</span></a>';
                echo '<a href="recent-activity.php" class="quick-menu-item"><i class="fas fa-history"></i> <span data-en="Recent Activity" data-ar="النشاط الأخير">Recent Activity</span></a>';

                echo '<a href="contact-form-submissions.php" class="quick-menu-item"><i class="fas fa-envelope"></i> <span data-en="Contact Submissions" data-ar="طلبات الاتصال">Contact Submissions</span></a>';
                echo '<a href="anonymous-feedback.php" class="quick-menu-item"><i class="fas fa-comment-dots"></i> <span data-en="Anonymous Feedback" data-ar="التعليقات المجهولة">Anonymous Feedback</span></a>';
            }
            
            echo '<a href="../logout.php" class="quick-menu-item logout-item"><i class="fas fa-sign-out-alt"></i> <span data-en="Logout" data-ar="تسجيل الخروج">Logout</span></a>';
            ?>
        </div>
    </div>
</header>

<?php
$cssPath = '../includes/unified-header.css';
$currentPath = $_SERVER['PHP_SELF'];
if (strpos($currentPath, '/Teacher/') === false && strpos($currentPath, '/Student/') === false && strpos($currentPath, '/Parent/') === false && strpos($currentPath, '/Admin/') === false) {
    $cssPath = 'includes/unified-header.css';
}
?>
<link rel="stylesheet" href="<?php echo $cssPath; ?>">

<?php
$jsPath = '../includes/unified-header.js';
if (strpos($currentPath, '/Teacher/') === false && strpos($currentPath, '/Student/') === false && strpos($currentPath, '/Parent/') === false && strpos($currentPath, '/Admin/') === false) {
    $jsPath = 'includes/unified-header.js';
}
?>
<script src="<?php echo $jsPath; ?>"></script>

