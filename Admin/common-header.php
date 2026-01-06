
<header class="header">
    <div class="header-content">
        <a href="../homepage.php" class="logo">HUDURY 💻</a>
        <?php require_once __DIR__ . '/../includes/logout-button.php'; ?>
        <a href="admin-dashboard.php" class="btn-back" data-en="← Back to Dashboard" data-ar="← العودة للوحة التحكم">
            <i class="fas fa-arrow-left"></i>
            <span data-en="Back to Dashboard" data-ar="العودة للوحة التحكم">Back to Dashboard</span>
        </a>
        <div class="user-info">
            <div class="header-actions">
                <button class="header-btn quick-menu-btn-desktop" onclick="toggleSideMenu()" title="Quick Menu">
                    <i class="fas fa-th-large"></i>
                    <span data-en="Quick Menu" data-ar="القائمة السريعة">Quick Menu</span>
                </button>
            </div>
            <button class="quick-menu-btn-header" onclick="toggleSideMenu()" title="Quick Menu">
                <i class="fas fa-th-large"></i>
            </button>
            <div class="user-avatar">👨‍💼</div>
            <button class="lang-toggle" onclick="toggleLanguage()">EN / AR</button>
        </div>
    </div>
</header>

<div class="side-menu-overlay" id="sideMenuOverlay" onclick="toggleSideMenu()"></div>

<div class="side-menu-mobile" id="sideMenuMobile">
    <div class="side-menu-header">
        <h3 data-en="Quick Menu" data-ar="القائمة السريعة">Quick Menu</h3>
        <button class="side-menu-close" onclick="toggleSideMenu()"><i class="fas fa-times"></i></button>
    </div>
    <div class="side-menu-content">
        <a href="admin-dashboard.php" class="side-menu-item" onclick="toggleSideMenu();"><div class="side-menu-icon">🏠</div><div class="side-menu-text"><div class="side-menu-title" data-en="Dashboard" data-ar="لوحة التحكم">Dashboard</div><div class="side-menu-subtitle" data-en="Main dashboard" data-ar="لوحة التحكم الرئيسية">Main dashboard</div></div><i class="fas fa-chevron-right side-menu-arrow"></i></a>
        <a href="user-management.php" class="side-menu-item" onclick="toggleSideMenu();"><div class="side-menu-icon">👥</div><div class="side-menu-text"><div class="side-menu-title" data-en="User Management" data-ar="إدارة المستخدمين">User Management</div><div class="side-menu-subtitle" data-en="Manage all accounts" data-ar="إدارة جميع الحسابات">Manage all accounts</div></div><i class="fas fa-chevron-right side-menu-arrow"></i></a>
        <a href="exam-management.php" class="side-menu-item" onclick="toggleSideMenu();"><div class="side-menu-icon">📝</div><div class="side-menu-text"><div class="side-menu-title" data-en="Exam Management" data-ar="إدارة الامتحانات">Exam Management</div><div class="side-menu-subtitle" data-en="Add and manage exams" data-ar="إضافة وإدارة الامتحانات">Add and manage exams</div></div><i class="fas fa-chevron-right side-menu-arrow"></i></a>
        <a href="school-events-management.php" class="side-menu-item" onclick="toggleSideMenu();"><div class="side-menu-icon">📅</div><div class="side-menu-text"><div class="side-menu-title" data-en="School Events" data-ar="أحداث المدرسة">School Events</div><div class="side-menu-subtitle" data-en="Manage school events" data-ar="إدارة أحداث المدرسة">Manage school events</div></div><i class="fas fa-chevron-right side-menu-arrow"></i></a>
        <a href="classes-management.php" class="side-menu-item" onclick="toggleSideMenu();"><div class="side-menu-icon">🏫</div><div class="side-menu-text"><div class="side-menu-title" data-en="Classes Management" data-ar="إدارة الفصول">Classes Management</div><div class="side-menu-subtitle" data-en="Manage school classes" data-ar="إدارة فصول المدرسة">Manage school classes</div></div><i class="fas fa-chevron-right side-menu-arrow"></i></a>
        <a href="courses-management.php" class="side-menu-item" onclick="toggleSideMenu();"><div class="side-menu-icon">📖</div><div class="side-menu-text"><div class="side-menu-title" data-en="Courses Management" data-ar="إدارة المقررات">Courses Management</div><div class="side-menu-subtitle" data-en="Manage courses" data-ar="إدارة المقررات">Manage courses</div></div><i class="fas fa-chevron-right side-menu-arrow"></i></a>
        <a href="class-schedule-management.php" class="side-menu-item" onclick="toggleSideMenu();"><div class="side-menu-icon">📋</div><div class="side-menu-text"><div class="side-menu-title" data-en="Class Schedules" data-ar="جداول الفصول">Class Schedules</div><div class="side-menu-subtitle" data-en="Organize schedules" data-ar="تنظيم الجداول">Organize schedules</div></div><i class="fas fa-chevron-right side-menu-arrow"></i></a>
        <a href="notifications-management.php" class="side-menu-item" onclick="toggleSideMenu();"><div class="side-menu-icon">🔔</div><div class="side-menu-text"><div class="side-menu-title" data-en="Notifications" data-ar="الإشعارات">Notifications</div><div class="side-menu-subtitle" data-en="Manage notifications" data-ar="إدارة الإشعارات">Manage notifications</div></div><i class="fas fa-chevron-right side-menu-arrow"></i></a>
        <a href="academic-status-management.php" class="side-menu-item" onclick="toggleSideMenu();"><div class="side-menu-icon">📊</div><div class="side-menu-text"><div class="side-menu-title" data-en="Academic Status" data-ar="الحالة الأكاديمية">Academic Status</div><div class="side-menu-subtitle" data-en="Manage student status" data-ar="إدارة حالة الطلاب">Manage student status</div></div><i class="fas fa-chevron-right side-menu-arrow"></i></a>
        <a href="anonymous-feedback.php" class="side-menu-item" onclick="toggleSideMenu();"><div class="side-menu-icon">💬</div><div class="side-menu-text"><div class="side-menu-title" data-en="Anonymous Feedback" data-ar="التعليقات المجهولة">Anonymous Feedback</div><div class="side-menu-subtitle" data-en="View parent feedback" data-ar="عرض تعليقات أولياء الأمور">View parent feedback</div></div><i class="fas fa-chevron-right side-menu-arrow"></i></a>
        <a href="leave-requests.php" class="side-menu-item" onclick="toggleSideMenu();"><div class="side-menu-icon">📋</div><div class="side-menu-text"><div class="side-menu-title" data-en="Leave Requests" data-ar="طلبات الإجازة">Leave Requests</div><div class="side-menu-subtitle" data-en="Manage leave requests" data-ar="إدارة طلبات الإجازة">Manage leave requests</div></div><i class="fas fa-chevron-right side-menu-arrow"></i></a>
        <a href="medical-records.php" class="side-menu-item" onclick="toggleSideMenu();"><div class="side-menu-icon">🏥</div><div class="side-menu-text"><div class="side-menu-title" data-en="Medical Records" data-ar="السجلات الطبية">Medical Records</div><div class="side-menu-subtitle" data-en="Edit medical data" data-ar="تعديل البيانات الطبية">Edit medical data</div></div><i class="fas fa-chevron-right side-menu-arrow"></i></a>
        <a href="attendance-management.php" class="side-menu-item" onclick="toggleSideMenu();"><div class="side-menu-icon">✅</div><div class="side-menu-text"><div class="side-menu-title" data-en="Attendance" data-ar="الحضور">Attendance</div><div class="side-menu-subtitle" data-en="Manage attendance" data-ar="إدارة الحضور">Manage attendance</div></div><i class="fas fa-chevron-right side-menu-arrow"></i></a>
        <a href="installments-management.php" class="side-menu-item" onclick="toggleSideMenu();"><div class="side-menu-icon">💰</div><div class="side-menu-text"><div class="side-menu-title" data-en="Installments" data-ar="الأقساط">Installments</div><div class="side-menu-subtitle" data-en="Manage payments" data-ar="إدارة المدفوعات">Manage payments</div></div><i class="fas fa-chevron-right side-menu-arrow"></i></a>
        <a href="school-news-management.php" class="side-menu-item" onclick="toggleSideMenu();"><div class="side-menu-icon">📰</div><div class="side-menu-text"><div class="side-menu-title" data-en="School News" data-ar="أخبار المدرسة">School News</div><div class="side-menu-subtitle" data-en="Manage news posts" data-ar="إدارة منشورات الأخبار">Manage news posts</div></div><i class="fas fa-chevron-right side-menu-arrow"></i></a>
        <a href="reports-analytics.php" class="side-menu-item" onclick="toggleSideMenu();"><div class="side-menu-icon">📈</div><div class="side-menu-text"><div class="side-menu-title" data-en="Reports & Analytics" data-ar="التقارير والتحليلات">Reports & Analytics</div><div class="side-menu-subtitle" data-en="View reports" data-ar="عرض التقارير">View reports</div></div><i class="fas fa-chevron-right side-menu-arrow"></i></a>
        <a href="recent-activity.php" class="side-menu-item" onclick="toggleSideMenu();"><div class="side-menu-icon">📝</div><div class="side-menu-text"><div class="side-menu-title" data-en="Recent Activity" data-ar="النشاط الأخير">Recent Activity</div><div class="side-menu-subtitle" data-en="View activity logs" data-ar="عرض سجلات النشاط">View activity logs</div></div><i class="fas fa-chevron-right side-menu-arrow"></i></a>
        <a href="contact-form-submissions.php" class="side-menu-item" onclick="toggleSideMenu();"><div class="side-menu-icon">📧</div><div class="side-menu-text"><div class="side-menu-title" data-en="Contact Submissions" data-ar="طلبات الاتصال">Contact Submissions</div><div class="side-menu-subtitle" data-en="View contact forms" data-ar="عرض نماذج الاتصال">View contact forms</div></div><i class="fas fa-chevron-right side-menu-arrow"></i></a>
        <a href="../homepage.php" class="side-menu-item" onclick="toggleSideMenu();"><div class="side-menu-icon">🏠</div><div class="side-menu-text"><div class="side-menu-title" data-en="Home" data-ar="الرئيسية">Home</div><div class="side-menu-subtitle" data-en="Go to homepage" data-ar="الذهاب إلى الصفحة الرئيسية">Go to homepage</div></div><i class="fas fa-chevron-right side-menu-arrow"></i></a>
    </div>
</div>

