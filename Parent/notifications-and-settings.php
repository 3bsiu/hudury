<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-parent.php';

requireUserType('parent');

$currentParent = getCurrentUserData($pdo);
$parentName = '';
$parentEmail = '';
$parentPhone = '';
$parentAddress = '';
$parentNationalId = '';

if ($currentParent) {
    $parentName = $currentParent['NameAr'] ?? $currentParent['NameEn'] ?? 'Parent';
    $parentEmail = $currentParent['Email'] ?? '';
    $parentPhone = $currentParent['Phone'] ?? '';
    $parentAddress = $currentParent['Address'] ?? '';
    $parentNationalId = $currentParent['National_ID'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications & Settings - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        .settings-tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 3px solid #FFE5E5;
            flex-wrap: wrap;
        }
        .settings-tab {
            padding: 1rem 2rem;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-weight: 700;
            font-size: 1.1rem;
            color: #666;
            transition: all 0.3s;
            margin-bottom: -3px;
        }
        .settings-tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        .settings-tab:hover {
            color: var(--primary-color);
        }
        .settings-content {
            display: none;
        }
        .settings-content.active {
            display: block;
        }
        .template-preview {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin: 1.5rem 0;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .template-preview.push {
            border-left: 5px solid #FF6B9D;
        }
        .template-preview.email {
            border-left: 5px solid #6BCB77;
        }
        .template-preview.sms {
            border-left: 5px solid #FFD93D;
        }
        .preview-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #FFE5E5;
        }
        .preview-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #FF6B9D, #6BCB77);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        .preview-content {
            line-height: 1.8;
        }
        .avatar-upload {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .avatar-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: linear-gradient(135deg, #FF6B9D, #6BCB77);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
            margin: 0 auto 1rem;
            cursor: pointer;
            transition: all 0.3s;
            border: 5px solid white;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .avatar-preview:hover {
            transform: scale(1.05);
        }
        .toggle-switch {
            width: 50px;
            height: 26px;
            background: #ccc;
            border-radius: 13px;
            position: relative;
            cursor: pointer;
            transition: all 0.3s;
        }
        .toggle-switch.active {
            background: #6BCB77;
        }
        .toggle-switch::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            background: white;
            border-radius: 50%;
            top: 3px;
            left: 3px;
            transition: all 0.3s;
        }
        .toggle-switch.active::after {
            left: 27px;
        }
        .template-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .template-item {
            background: #FFF9F5;
            padding: 1.5rem;
            border-radius: 15px;
            border-left: 5px solid #FF6B9D;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .template-info {
            flex: 1;
        }
        .template-name {
            font-weight: 700;
            margin-bottom: 0.3rem;
        }
        .template-type {
            font-size: 0.85rem;
            color: #666;
        }
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
    
    <div id="notificationContainer"></div>

    <div class="dashboard-container">
        
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">👤</span>
                <span data-en="Profile Settings" data-ar="إعدادات الملف الشخصي">Profile Settings</span>
            </h1>
            <p class="page-subtitle" data-en="Manage your profile information and password" data-ar="إدارة معلومات ملفك الشخصي وكلمة السر">Manage your profile information and password</p>
        </div>

        <div class="card">
            
            <div class="settings-content active" id="profileTab">
                <div class="card-header">
                    <h2 class="card-title">
                        <span class="card-icon">👤</span>
                        <span data-en="Profile Information" data-ar="معلومات الملف الشخصي">Profile Information</span>
                    </h2>
                </div>

                <div class="form-group">
                    <label data-en="Full Name" data-ar="الاسم الكامل">Full Name</label>
                    <input type="text" value="<?php echo htmlspecialchars($parentName); ?>" readonly style="background: #f5f5f5; cursor: not-allowed;">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label data-en="National ID" data-ar="رقم الهوية">National ID</label>
                        <input type="text" value="<?php echo htmlspecialchars($parentNationalId); ?>" readonly style="background: #f5f5f5; cursor: not-allowed;">
                    </div>
                    <div class="form-group">
                        <label data-en="Address" data-ar="العنوان">Address</label>
                        <input type="text" value="<?php echo htmlspecialchars($parentAddress); ?>" readonly style="background: #f5f5f5; cursor: not-allowed;">
                    </div>
                </div>

                <div style="margin-top: 2rem; padding-top: 2rem; border-top: 2px solid #FFE5E5;">
                    <h3 style="margin-bottom: 1.5rem; color: var(--primary-color);" data-en="Contact Information" data-ar="معلومات الاتصال">Contact Information</h3>
                    <form onsubmit="updateContactInfo(event)">
                        <div class="form-row">
                            <div class="form-group">
                                <label data-en="Email" data-ar="البريد الإلكتروني">Email</label>
                                <input type="email" id="profileEmail" value="<?php echo htmlspecialchars($parentEmail); ?>" required>
                            </div>
                            <div class="form-group">
                                <label data-en="Phone Number" data-ar="رقم الهاتف">Phone Number</label>
                                <input type="tel" id="profilePhone" value="<?php echo htmlspecialchars($parentPhone); ?>" required>
                            </div>
                        </div>
                        <div class="action-buttons">
                            <button type="submit" class="btn btn-primary" data-en="Update Contact Info" data-ar="تحديث معلومات الاتصال">Update Contact Info</button>
                        </div>
                    </form>
                </div>

                <div style="margin-top: 2rem; padding-top: 2rem; border-top: 2px solid #FFE5E5;">
                    <h3 style="margin-bottom: 1.5rem; color: var(--primary-color);" data-en="Change Password" data-ar="تغيير كلمة السر">Change Password</h3>
                    <form onsubmit="changePassword(event)">
                        <div class="form-group">
                            <label data-en="Current Password" data-ar="كلمة السر الحالية">Current Password</label>
                            <input type="password" id="currentPassword" required autocomplete="current-password">
                        </div>
                        <div class="form-group">
                            <label data-en="New Password" data-ar="كلمة السر الجديدة">New Password</label>
                            <input type="password" id="newPassword" required autocomplete="new-password" minlength="6">
                            <small style="color: #666; font-size: 0.85rem; margin-top: 0.5rem; display: block;" data-en="Password must be at least 6 characters" data-ar="كلمة السر يجب أن تكون 6 أحرف على الأقل">Password must be at least 6 characters</small>
                        </div>
                        <div class="form-group">
                            <label data-en="Confirm New Password" data-ar="تأكيد كلمة السر الجديدة">Confirm New Password</label>
                            <input type="password" id="confirmPassword" required autocomplete="new-password" minlength="6">
                        </div>
                        <div class="action-buttons">
                            <button type="submit" class="btn btn-primary" data-en="Change Password" data-ar="تغيير كلمة السر">Change Password</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        let currentTemplateId = null;

        function switchTab(tabName, tabElement) {
            document.querySelectorAll('.settings-tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.settings-content').forEach(content => content.classList.remove('active'));
            
            if (tabElement) {
                tabElement.classList.add('active');
            } else {
                
                document.querySelectorAll('.settings-tab').forEach(tab => {
                    if (tab.getAttribute('onclick') && tab.getAttribute('onclick').includes(tabName)) {
                        tab.classList.add('active');
                    }
                });
            }
            document.getElementById(tabName + 'Tab').classList.add('active');

            if (window.location.hash !== '#' + tabName) {
                window.history.replaceState(null, null, '#' + tabName);
            }
        }

        function updateContactInfo(event) {
            event.preventDefault();
            const email = document.getElementById('profileEmail').value;
            const phone = document.getElementById('profilePhone').value;

            if (!email || !email.includes('@')) {
                showNotification(currentLanguage === 'en' ? 'Please enter a valid email address' : 'يرجى إدخال عنوان بريد إلكتروني صحيح', 'error');
                return;
            }

            const data = {
                email: email,
                phone: phone
            };

            fetch('update-profile-ajax.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    const message = currentLanguage === 'en' ? result.message : (result.message_ar || result.message);
                    showNotification(message, 'success');
                } else {
                    const message = currentLanguage === 'en' ? result.message : (result.message_ar || result.message || 'فشل تحديث معلومات الاتصال');
                    showNotification(message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(currentLanguage === 'en' ? 'An error occurred. Please try again.' : 'حدث خطأ. يرجى المحاولة مرة أخرى.', 'error');
            });
        }

        function changePassword(event) {
            event.preventDefault();
            const currentPassword = document.getElementById('currentPassword').value;
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;

            if (newPassword.length < 6) {
                showNotification(currentLanguage === 'en' ? 'Password must be at least 6 characters' : 'كلمة السر يجب أن تكون 6 أحرف على الأقل', 'error');
                return;
            }

            if (newPassword !== confirmPassword) {
                showNotification(currentLanguage === 'en' ? 'New passwords do not match' : 'كلمات السر الجديدة غير متطابقة', 'error');
                return;
            }

            if (currentPassword === newPassword) {
                showNotification(currentLanguage === 'en' ? 'New password must be different from current password' : 'كلمة السر الجديدة يجب أن تكون مختلفة عن الحالية', 'error');
                return;
            }

            const data = {
                currentPassword: currentPassword,
                newPassword: newPassword
            };

            fetch('change-password-ajax.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showNotification(currentLanguage === 'en' ? 'Password changed successfully!' : 'تم تغيير كلمة السر بنجاح!', 'success');
                    
                    document.getElementById('currentPassword').value = '';
                    document.getElementById('newPassword').value = '';
                    document.getElementById('confirmPassword').value = '';
                } else {
                    showNotification(result.message || (currentLanguage === 'en' ? 'Failed to change password' : 'فشل تغيير كلمة السر'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(currentLanguage === 'en' ? 'An error occurred. Please try again.' : 'حدث خطأ. يرجى المحاولة مرة أخرى.', 'error');
            });
        }

        function togglePreference(element, type) {
            element.classList.toggle('active');

        }

        function changeLanguage() {
            const lang = document.getElementById('prefLanguage').value;
            if (lang !== currentLanguage) {
                toggleLanguage();
            }
        }

        const mockNotifications = [
            { id: 1, icon: '⚙️', title: 'Settings Updated', message: 'Your notification preferences were updated', time: '2 hours ago', read: false },
            { id: 2, icon: '👤', title: 'Profile Updated', message: 'Your profile information was updated', time: '1 day ago', read: false },
            { id: 3, icon: '🔔', title: 'New Notification', message: 'You have a new notification', time: '3 hours ago', read: false }
        ];

        function toggleNotificationsDropdown() {
            const dropdown = document.getElementById('notificationsDropdown');
            dropdown.classList.toggle('active');
            renderNotifications();
        }

        function renderNotifications() {
            const container = document.getElementById('notificationsContent');
            container.innerHTML = mockNotifications.map(notif => `
                <div class="notification-dropdown-item ${!notif.read ? 'unread' : ''}" onclick="handleNotificationClick(${notif.id})">
                    <div class="notification-dropdown-icon">${notif.icon}</div>
                    <div class="notification-dropdown-content-text">
                        <div class="notification-dropdown-title">${notif.title}</div>
                        <div class="notification-dropdown-message">${notif.message}</div>
                        <div class="notification-dropdown-time">${notif.time}</div>
                    </div>
                </div>
            `).join('');
        }

        function handleNotificationClick(id) {
            const notif = mockNotifications.find(n => n.id === id);
            if (notif) {
                notif.read = true;
                updateNotificationBadge();
                renderNotifications();
            }
        }

        function markAllAsRead() {
            mockNotifications.forEach(n => n.read = true);
            updateNotificationBadge();
            renderNotifications();
        }

        function updateNotificationBadge() {
            const unreadCount = mockNotifications.filter(n => !n.read).length;
            const badge = document.getElementById('notificationBadge');
            if (unreadCount > 0) {
                badge.textContent = unreadCount;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        }

        function openProfileSettings() {
            
            window.location.hash = '#profile';
            switchTab('profile');
        }

        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.setAttribute('role', 'alert');
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer; font-size: 1.2rem;">&times;</button>
            `;
            
            const container = document.getElementById('notificationContainer') || document.body;
            container.appendChild(notification);
            
            setTimeout(() => {
                notification.style.opacity = '0';
                setTimeout(() => notification.remove(), 300);
            }, 5000);
        }

        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('notificationsDropdown');
            const btn = event.target.closest('.notification-btn');
            if (dropdown && !dropdown.contains(event.target) && !btn) {
                dropdown.classList.remove('active');
            }
        });

        window.addEventListener('DOMContentLoaded', function() {
            updateNotificationBadge();

            const hash = window.location.hash.substring(1); 
            if (hash) {
                const validTabs = ['notifications', 'profile', 'preferences'];
                if (validTabs.includes(hash)) {
                    switchTab(hash);
                }
            }
        });

        window.addEventListener('hashchange', function() {
            const hash = window.location.hash.substring(1);
            const validTabs = ['notifications', 'profile', 'preferences'];
            if (validTabs.includes(hash)) {
                switchTab(hash);
            }
        });
    </script>
</body>
</html>

