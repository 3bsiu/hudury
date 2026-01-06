<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('admin');

$currentAdminId = getCurrentUserId();
$currentAdmin = getCurrentUserData($pdo);
$adminName = $_SESSION['user_name'] ?? 'Admin';

$systemStats = [
    'serverStatus' => 'Online',
    'databaseStatus' => 'Connected',
    'activeUsers' => 0,
    'storageUsed' => 0,
    'totalUsers' => 0,
    'totalStudents' => 0,
    'totalTeachers' => 0,
    'totalParents' => 0
];

$dbSizeMB = 0;

$pendingActions = [
    'leaveRequests' => 0,
    'newFeedback' => 0,
    'newContactSubmissions' => 0
];

try {
    
    if ($pdo) {
        $systemStats['databaseStatus'] = 'Connected';
        $systemStats['serverStatus'] = 'Online';
    } else {
        $systemStats['databaseStatus'] = 'Disconnected';
        $systemStats['serverStatus'] = 'Offline';
    }

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM student");
    $systemStats['totalStudents'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM teacher");
    $systemStats['totalTeachers'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM parent");
    $systemStats['totalParents'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    $systemStats['totalUsers'] = $systemStats['totalStudents'] + $systemStats['totalTeachers'] + $systemStats['totalParents'];

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM student WHERE Status = 'active' OR Status IS NULL");
    $activeStudents = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM teacher");
    $activeTeachers = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM parent");
    $activeParents = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    $systemStats['activeUsers'] = $activeStudents + $activeTeachers + $activeParents;

    $stmt = $pdo->query("
        SELECT 
            ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'size_mb'
        FROM information_schema.tables 
        WHERE table_schema = DATABASE()
    ");
    $dbSize = $stmt->fetch(PDO::FETCH_ASSOC);
    $dbSizeMB = $dbSize['size_mb'] ?? 0;

    $totalStorageMB = 100; 
    $systemStats['storageUsed'] = $totalStorageMB > 0 ? round(($dbSizeMB / $totalStorageMB) * 100) : 0;
    $systemStats['storageUsed'] = min(100, max(0, $systemStats['storageUsed'])); 

    $pendingActions = [
        'leaveRequests' => 0,
        'newFeedback' => 0,
        'newContactSubmissions' => 0
    ];

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM leave_request WHERE Status = 'pending'");
    $pendingActions['leaveRequests'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM anonymous_feedback WHERE (Is_Read = 0 OR Status = 'new')");
    $pendingActions['newFeedback'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM contact_submission WHERE Status = 'new'");
    $pendingActions['newContactSubmissions'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

    $recentActivities = [];
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(User_Name, 'Admin') as user_name,
                Action as action,
                COALESCE(Description, '') as description,
                Created_At as created_at
            FROM activity_log
            WHERE User_Type = 'admin'
            ORDER BY Created_At DESC
            LIMIT 3
        ");
        $stmt->execute();
        $recentActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching recent activities: " . $e->getMessage());
        $recentActivities = [];
    }
    
} catch (PDOException $e) {
    error_log("Error fetching system stats: " . $e->getMessage());
    $systemStats['databaseStatus'] = 'Error';
    $systemStats['serverStatus'] = 'Error';
    $pendingActions = [
        'leaveRequests' => 0,
        'newFeedback' => 0,
        'newContactSubmissions' => 0
    ];
    $recentActivities = [];
}
?>

<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>
    
    <div class="main-content">

    <div class="dashboard-container">
        
        <div class="welcome-section">
            <p data-en="Manage users, generate reports, monitor system performance, and oversee all administrative functions." data-ar="إدارة المستخدمين، إنشاء التقارير، مراقبة أداء النظام، والإشراف على جميع الوظائف الإدارية.">Manage users, generate reports, monitor system performance, and oversee all administrative functions.</p>
        </div>

        <div class="quick-stats">
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-value"><?php echo number_format($systemStats['totalUsers']); ?></div>
                <div class="stat-label" data-en="Total Users" data-ar="إجمالي المستخدمين">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">👨‍🎓</div>
                <div class="stat-value"><?php echo number_format($systemStats['totalStudents']); ?></div>
                <div class="stat-label" data-en="Students" data-ar="الطلاب">Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">👩‍🏫</div>
                <div class="stat-value"><?php echo number_format($systemStats['totalTeachers']); ?></div>
                <div class="stat-label" data-en="Teachers" data-ar="المعلمون">Teachers</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">👨‍👩‍👧</div>
                <div class="stat-value"><?php echo number_format($systemStats['totalParents']); ?></div>
                <div class="stat-label" data-en="Parents" data-ar="أولياء الأمور">Parents</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📊</div>
                <div class="stat-value"><?php echo $systemStats['storageUsed']; ?>%</div>
                <div class="stat-label" data-en="Storage Used" data-ar="المساحة المستخدمة">Storage Used</div>
            </div>
        </div>

        <div class="content-grid">
            
            <div>
                
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">👥</span>
                            <span data-en="User Management" data-ar="إدارة المستخدمين">User Management</span>
                        </h2>
                        <a href="user-management.php" class="btn btn-primary" data-en="Manage Users" data-ar="إدارة المستخدمين">Manage Users</a>
                    </div>
                    <div class="user-list">
                        <div class="user-item" onclick="window.location.href='user-management.php'">
                            <div class="user-info-item">
                                <div class="user-avatar-item">👨‍🎓</div>
                                <div>
                                    <div style="font-weight: 700;">Ahmed Ali</div>
                                    <div style="font-size: 0.9rem; color: #666;">Student - Grade 5</div>
                                </div>
                            </div>
                            <div>→</div>
                        </div>
                        <div class="user-item" onclick="window.location.href='user-management.php'">
                            <div class="user-info-item">
                                <div class="user-avatar-item">👩‍🏫</div>
                                <div>
                                    <div style="font-weight: 700;">Ms. Sarah</div>
                                    <div style="font-size: 0.9rem; color: #666;">Teacher - Mathematics</div>
                                </div>
                            </div>
                            <div>→</div>
                        </div>
                        <div class="user-item" onclick="window.location.href='user-management.php'">
                            <div class="user-info-item">
                                <div class="user-avatar-item">👨</div>
                                <div>
                                    <div style="font-weight: 700;">Mr. Ali</div>
                                    <div style="font-size: 0.9rem; color: #666;">Parent</div>
                                </div>
                            </div>
                            <div>→</div>
                        </div>
                    </div>
                    <div class="action-buttons">
                        <a href="user-management.php" class="btn btn-primary" data-en="View All Users" data-ar="عرض جميع المستخدمين">View All Users</a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">⚡</span>
                            <span data-en="Quick Access" data-ar="الوصول السريع">Quick Access</span>
                        </h2>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <a href="classes-management.php" class="btn btn-primary" style="text-align: center; text-decoration: none;" data-en="Classes Management" data-ar="إدارة الفصول">Classes Management</a>
                        <a href="courses-management.php" class="btn btn-primary" style="text-align: center; text-decoration: none;" data-en="Courses Management" data-ar="إدارة المقررات">Courses Management</a>
                        <a href="exam-management.php" class="btn btn-primary" style="text-align: center; text-decoration: none;" data-en="Exam Management" data-ar="إدارة الامتحانات">Exam Management</a>
                        <a href="school-events-management.php" class="btn btn-primary" style="text-align: center; text-decoration: none;" data-en="School Events" data-ar="أحداث المدرسة">School Events</a>
                        <a href="class-schedule-management.php" class="btn btn-primary" style="text-align: center; text-decoration: none;" data-en="Class Schedules" data-ar="جداول الفصول">Class Schedules</a>
                        <a href="notifications-management.php" class="btn btn-primary" style="text-align: center; text-decoration: none;" data-en="Notifications" data-ar="الإشعارات">Notifications</a>
                        <a href="attendance-management.php" class="btn btn-primary" style="text-align: center; text-decoration: none;" data-en="Attendance" data-ar="الحضور">Attendance</a>
                        <a href="installments-management.php" class="btn btn-primary" style="text-align: center; text-decoration: none;" data-en="Installments" data-ar="الأقساط">Installments</a>
                        <a href="school-news-management.php" class="btn btn-primary" style="text-align: center; text-decoration: none;" data-en="School News" data-ar="أخبار المدرسة">School News</a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📊</span>
                            <span data-en="Reports & Analytics" data-ar="التقارير والتحليلات">Reports & Analytics</span>
                        </h2>
                        <a href="reports-analytics.php" class="btn btn-primary" data-en="View Reports" data-ar="عرض التقارير">View Reports</a>
                    </div>
                    <div class="action-buttons">
                        <a href="reports-analytics.php#usage" class="btn btn-secondary" data-en="Financial Report" data-ar="تقرير الاستخدام">Financial Report</a>
                        <a href="reports-analytics.php#attendance" class="btn btn-secondary" data-en="Attendance Report" data-ar="تقرير الحضور">Attendance Report</a>

                        <a href="reports-analytics.php#academic" class="btn btn-secondary" data-en="Academic Status" data-ar="الحالة الأكاديمية">Academic Status</a>
                    </div>
                </div>
            </div>

            <div>
                
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">💻</span>
                            <span data-en="System Status" data-ar="حالة النظام">System Status</span>
                        </h2>
                    </div>
                    <div style="margin-bottom: 1rem;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span data-en="Server Status" data-ar="حالة الخادم">Server Status</span>
                            <span style="color: <?php echo $systemStats['serverStatus'] === 'Online' ? '#6BCB77' : ($systemStats['serverStatus'] === 'Error' ? '#FF6B9D' : '#FFD93D'); ?>; font-weight: 700;">
                                ● <?php echo htmlspecialchars($systemStats['serverStatus']); ?>
                            </span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span data-en="Database" data-ar="قاعدة البيانات">Database</span>
                            <span style="color: <?php echo $systemStats['databaseStatus'] === 'Connected' ? '#6BCB77' : ($systemStats['databaseStatus'] === 'Error' ? '#FF6B9D' : '#FFD93D'); ?>; font-weight: 700;">
                                ● <?php echo htmlspecialchars($systemStats['databaseStatus']); ?>
                            </span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span data-en="Active Users" data-ar="المستخدمون النشطون">Active Users</span>
                            <span style="font-weight: 700;"><?php echo number_format($systemStats['activeUsers']); ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span data-en="Database Size" data-ar="حجم قاعدة البيانات">Database Size</span>
                            <span style="font-weight: 700;"><?php echo number_format($dbSizeMB ?? 0, 2); ?> MB</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span data-en="Storage Used" data-ar="المساحة المستخدمة">Storage Used</span>
                            <span style="font-weight: 700; color: <?php echo $systemStats['storageUsed'] > 80 ? '#FF6B9D' : ($systemStats['storageUsed'] > 60 ? '#FFD93D' : '#6BCB77'); ?>;">
                                <?php echo $systemStats['storageUsed']; ?>%
                            </span>
                        </div>
                        <?php if (isset($dbSizeMB) && $dbSizeMB > 0): ?>
                        <div style="margin-top: 0.5rem; background: #FFF9F5; border-radius: 10px; padding: 0.5rem;">
                            <div style="background: #FFE5E5; border-radius: 5px; height: 8px; overflow: hidden;">
                                <div style="background: linear-gradient(135deg, #FF6B9D, #6BCB77); height: 100%; width: <?php echo $systemStats['storageUsed']; ?>%; transition: width 0.3s;"></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📝</span>
                            <span data-en="Recent Activity" data-ar="النشاط الأخير">Recent Activity</span>
                        </h2>
                        <a href="recent-activity.php" class="btn btn-primary btn-small" data-en="View All" data-ar="عرض الكل">View All</a>
                    </div>
                    <div class="user-list">
                        <?php
                        if (!empty($recentActivities)) {
                            foreach ($recentActivities as $index => $activity) {
                                
                                $createdAt = new DateTime($activity['created_at']);
                                $now = new DateTime();
                                $diff = $now->diff($createdAt);
                                
                                $timeAgo = '';
                                if ($diff->days > 0) {
                                    $timeAgo = $diff->days == 1 ? '1 day ago' : $diff->days . ' days ago';
                                } elseif ($diff->h > 0) {
                                    $timeAgo = $diff->h == 1 ? '1 hour ago' : $diff->h . ' hours ago';
                                } elseif ($diff->i > 0) {
                                    $timeAgo = $diff->i == 1 ? '1 minute ago' : $diff->i . ' minutes ago';
                                } else {
                                    $timeAgo = 'Just now';
                                }

                                $actionText = ucfirst(str_replace('_', ' ', $activity['action']));
                                $description = !empty($activity['description']) ? $activity['description'] : $activity['user_name'];

                                $isLast = ($index === count($recentActivities) - 1);
                                $marginBottom = $isLast ? '' : 'margin-bottom: 0.5rem;';
                                
                                echo '<div style="padding: 1rem; background: #FFF9F5; border-radius: 10px; ' . $marginBottom . '">';
                                echo '<div style="font-weight: 700; margin-bottom: 0.3rem;">' . htmlspecialchars($actionText) . '</div>';
                                echo '<div style="font-size: 0.9rem; color: #666;">' . htmlspecialchars($description) . ' - ' . $timeAgo . '</div>';
                                echo '</div>';
                            }
                        } else {
                            
                            echo '<div style="padding: 1rem; background: #FFF9F5; border-radius: 10px; text-align: center; color: #666;">';
                            echo '<div data-en="No recent activity" data-ar="لا يوجد نشاط حديث">No recent activity</div>';
                            echo '</div>';
                        }
                        ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">⏰</span>
                            <span data-en="Pending Actions" data-ar="الإجراءات المعلقة">Pending Actions</span>
                        </h2>
                    </div>
                    <div class="user-list">
                        <div class="user-item" onclick="window.location.href='leave-requests.php'">
                            <div class="user-info-item">
                                <div class="user-avatar-item">📋</div>
                                <div>
                                    <div style="font-weight: 700;" data-en="Leave Requests" data-ar="طلبات الإجازة">Leave Requests</div>
                                    <div style="font-size: 0.9rem; color: #666;">
                                        <span data-en="<?php echo $pendingActions['leaveRequests']; ?> pending" data-ar="<?php echo $pendingActions['leaveRequests']; ?> معلقة"><?php echo $pendingActions['leaveRequests']; ?> pending</span>
                                    </div>
                                </div>
                            </div>
                            <div>→</div>
                        </div>
                        <div class="user-item" onclick="window.location.href='anonymous-feedback.php'">
                            <div class="user-info-item">
                                <div class="user-avatar-item">💬</div>
                                <div>
                                    <div style="font-weight: 700;" data-en="New Feedback" data-ar="تعليقات جديدة">New Feedback</div>
                                    <div style="font-size: 0.9rem; color: #666;">
                                        <span data-en="<?php echo $pendingActions['newFeedback']; ?> new messages" data-ar="<?php echo $pendingActions['newFeedback']; ?> رسائل جديدة"><?php echo $pendingActions['newFeedback']; ?> new messages</span>
                                    </div>
                                </div>
                            </div>
                            <div>→</div>
                        </div>
                        <div class="user-item" onclick="window.location.href='contact-form-submissions.php'">
                            <div class="user-info-item">
                                <div class="user-avatar-item">📧</div>
                                <div>
                                    <div style="font-weight: 700;" data-en="Contact Forms" data-ar="نماذج الاتصال">Contact Forms</div>
                                    <div style="font-size: 0.9rem; color: #666;">
                                        <span data-en="<?php echo $pendingActions['newContactSubmissions']; ?> new submissions" data-ar="<?php echo $pendingActions['newContactSubmissions']; ?> تقديمات جديدة"><?php echo $pendingActions['newContactSubmissions']; ?> new submissions</span>
                                    </div>
                                </div>
                            </div>
                            <div>→</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
</body>
</html>

