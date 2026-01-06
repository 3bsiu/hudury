<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('admin');
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recent Activity - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">📝</span>
                <span data-en="Recent Activity" data-ar="النشاط الأخير">Recent Activity</span>
            </h1>
            <p class="page-subtitle" data-en="View all admin account activity logs" data-ar="عرض جميع سجلات نشاط حساب المدير">View all admin account activity logs</p>
        </div>

        <div class="search-filter-bar">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="activitySearch" placeholder="Search activities..." oninput="filterActivities()">
            </div>
            <select class="filter-select" id="activityFilter" onchange="filterActivities()">
                <option value="all" data-en="All Activities" data-ar="جميع الأنشطة">All Activities</option>
                <option value="user" data-en="User Management" data-ar="إدارة المستخدمين">User Management</option>
                <option value="exam" data-en="Exam Management" data-ar="إدارة الامتحانات">Exam Management</option>
                <option value="attendance" data-en="Attendance" data-ar="الحضور">Attendance</option>
                <option value="class" data-en="Classes" data-ar="الفصول">Classes</option>
                <option value="course" data-en="Courses" data-ar="المقررات">Courses</option>
                <option value="medical" data-en="Medical Records" data-ar="السجلات الطبية">Medical Records</option>
                <option value="academic" data-en="Academic Status" data-ar="الحالة الأكاديمية">Academic Status</option>
                <option value="auth" data-en="Login/Logout" data-ar="تسجيل الدخول/الخروج">Login/Logout</option>
                <option value="settings" data-en="Settings" data-ar="الإعدادات">Settings</option>
            </select>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📋</span>
                    <span data-en="Activity Logs" data-ar="سجلات النشاط">Activity Logs</span>
                </h2>
            </div>
            <div id="activityList" class="user-list">
                
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        let allActivities = [];

        function loadActivities() {
            fetch('recent-activity-ajax.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        allActivities = data.activities;
                        renderActivities(allActivities);
                    } else {
                        console.error('Error loading activities:', data.error);
                        document.getElementById('activityList').innerHTML = 
                            '<div style="padding: 2rem; text-align: center; color: #999;">Error loading activities. Please refresh the page.</div>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('activityList').innerHTML = 
                        '<div style="padding: 2rem; text-align: center; color: #999;">Error loading activities. Please refresh the page.</div>';
                });
        }

        function renderActivities(activities) {
            const container = document.getElementById('activityList');
            
            if (activities.length === 0) {
                container.innerHTML = '<div style="padding: 2rem; text-align: center; color: #999;">No activities found.</div>';
                return;
            }
            
            container.innerHTML = activities.map(activity => `
                <div class="user-item">
                    <div class="user-info-item" style="flex: 1;">
                        <div class="user-avatar-item">${getActivityIcon(activity.category)}</div>
                        <div>
                            <div style="font-weight: 700;">${escapeHtml(activity.action)}</div>
                            <div style="font-size: 0.9rem; color: #666; margin-top: 0.3rem;">${escapeHtml(activity.description || '')}</div>
                            <div style="font-size: 0.85rem; color: #999; margin-top: 0.3rem;">
                                ${escapeHtml(activity.user_name || 'Admin')} • ${formatDate(activity.created_at)} at ${formatTime(activity.created_at)}
                            </div>
                        </div>
                    </div>
                </div>
            `).join('');
        }

        function getActivityIcon(category) {
            const icons = {
                user: '👥',
                exam: '📝',
                attendance: '✅',
                settings: '⚙️',
                class: '🏫',
                course: '📚',
                medical: '🏥',
                academic: '📊',
                auth: '🔐',
                event: '📅',
                notification: '🔔',
                grade: '📈',
                assignment: '📋'
            };
            return icons[category] || '📋';
        }

        function filterActivities() {
            const searchTerm = document.getElementById('activitySearch').value.toLowerCase();
            const categoryFilter = document.getElementById('activityFilter').value;
            
            let filtered = allActivities.filter(activity => {
                const matchesSearch = (activity.action && activity.action.toLowerCase().includes(searchTerm)) || 
                                    (activity.description && activity.description.toLowerCase().includes(searchTerm));
                const matchesCategory = categoryFilter === 'all' || activity.category === categoryFilter;
                return matchesSearch && matchesCategory;
            });
            
            renderActivities(filtered);
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        loadActivities();

        setInterval(loadActivities, 30000);
    </script>
</body>
</html>

