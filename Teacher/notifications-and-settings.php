<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-teacher.php';

requireUserType('teacher');

$currentTeacher = getCurrentUserData($pdo);
$teacherName = '';
$teacherEmail = '';
$teacherPhone = '';
$teacherPosition = '';
$teacherSubject = '';
$teacherAddress1 = '';
$teacherAddress2 = '';
$teacherDOB = '';
$teacherNationalId = '';

if ($currentTeacher) {
    $teacherName = $currentTeacher['NameAr'] ?? $currentTeacher['NameEn'] ?? 'Teacher';
    $teacherEmail = $currentTeacher['Email'] ?? '';
    $teacherPhone = $currentTeacher['Phone'] ?? '';
    $teacherPosition = $currentTeacher['Position'] ?? '';
    $teacherSubject = $currentTeacher['Subject'] ?? '';
    $teacherAddress1 = $currentTeacher['Address1'] ?? '';
    $teacherAddress2 = $currentTeacher['Address2'] ?? '';
    $teacherDOB = $currentTeacher['Date_Of_Birth'] ?? '';
    $teacherNationalId = $currentTeacher['National_ID'] ?? '';
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
        .dashboard-preview {
            background: #FFF9F5;
            border-radius: 20px;
            padding: 2rem;
            margin: 1.5rem 0;
        }
        .preview-quick-menu {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .preview-menu-item {
            background: white;
            padding: 1rem;
            border-radius: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            border: 3px solid transparent;
        }
        .preview-menu-item:hover {
            border-color: #FF6B9D;
            transform: translateY(-3px);
        }
        .preview-menu-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
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
                    <input type="text" value="<?php echo htmlspecialchars($teacherName); ?>" readonly style="background: #f5f5f5; cursor: not-allowed;">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label data-en="Position" data-ar="المنصب">Position</label>
                        <input type="text" value="<?php echo htmlspecialchars($teacherPosition); ?>" readonly style="background: #f5f5f5; cursor: not-allowed;">
                    </div>
                    <div class="form-group">
                        <label data-en="Subject" data-ar="المادة">Subject</label>
                        <input type="text" value="<?php echo htmlspecialchars($teacherSubject); ?>" readonly style="background: #f5f5f5; cursor: not-allowed;">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label data-en="Date of Birth" data-ar="تاريخ الميلاد">Date of Birth</label>
                        <input type="text" value="<?php echo $teacherDOB ? date('Y-m-d', strtotime($teacherDOB)) : ''; ?>" readonly style="background: #f5f5f5; cursor: not-allowed;">
                    </div>
                    <div class="form-group">
                        <label data-en="National ID" data-ar="رقم الهوية">National ID</label>
                        <input type="text" value="<?php echo htmlspecialchars($teacherNationalId); ?>" readonly style="background: #f5f5f5; cursor: not-allowed;">
                    </div>
                </div>
                <div class="form-group">
                    <label data-en="Address Line 1" data-ar="العنوان السطر الأول">Address Line 1</label>
                    <input type="text" value="<?php echo htmlspecialchars($teacherAddress1); ?>" readonly style="background: #f5f5f5; cursor: not-allowed;">
                </div>
                <div class="form-group">
                    <label data-en="Address Line 2" data-ar="العنوان السطر الثاني">Address Line 2</label>
                    <input type="text" value="<?php echo htmlspecialchars($teacherAddress2); ?>" readonly style="background: #f5f5f5; cursor: not-allowed;">
                </div>

                <div style="margin-top: 2rem; padding-top: 2rem; border-top: 2px solid #FFE5E5;">
                    <h3 style="margin-bottom: 1.5rem; color: var(--primary-color);" data-en="Contact Information" data-ar="معلومات الاتصال">Contact Information</h3>
                    <form onsubmit="updateContactInfo(event)">
                        <div class="form-row">
                            <div class="form-group">
                                <label data-en="Email" data-ar="البريد الإلكتروني">Email</label>
                                <input type="email" id="profileEmail" value="<?php echo htmlspecialchars($teacherEmail); ?>" required>
                            </div>
                            <div class="form-group">
                                <label data-en="Phone Number" data-ar="رقم الهاتف">Phone Number</label>
                                <input type="tel" id="profilePhone" value="<?php echo htmlspecialchars($teacherPhone); ?>" required>
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
        
        const mockTemplates = [
            { id: 1, name: 'Grade Update', type: 'push', title: 'Grade Updated', message: 'Your child\'s grade has been updated for {subject}.' },
            { id: 2, name: 'Assignment Due', type: 'email', title: 'Assignment Due Reminder', message: 'Reminder: {assignment} is due on {date}.' },
            { id: 3, name: 'Attendance Alert', type: 'sms', title: 'Attendance Alert', message: 'Your child was marked {status} today.' }
        ];

        const quickMenuItems = [
            { id: 'installments', icon: '💰', title: { en: 'Installments', ar: 'الأقساط' }, enabled: true },
            { id: 'academic', icon: '📈', title: { en: 'Academic Performance', ar: 'الأداء الأكاديمي' }, enabled: true },
            { id: 'schedule', icon: '📅', title: { en: 'Class Schedule', ar: 'جدول الحصص' }, enabled: true },
            { id: 'attendance', icon: '📋', title: { en: 'Attendance', ar: 'الحضور' }, enabled: true },
            { id: 'medical', icon: '🏥', title: { en: 'Medical Records', ar: 'السجلات الطبية' }, enabled: true },
            { id: 'chat', icon: '💬', title: { en: 'Chat with Teachers', ar: 'الدردشة مع المعلمين' }, enabled: true }
        ];

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
            
            if (tabName === 'dashboard-preview') {
                renderQuickMenuPreview();
            } else if (tabName === 'notifications') {
                loadTemplates();
            }
        }

        function loadTemplates() {
            const container = document.getElementById('templatesList');
            container.innerHTML = mockTemplates.map(template => `
                <div class="template-item">
                    <div class="template-info">
                        <div class="template-name">${template.name}</div>
                        <div class="template-type">${template.type.toUpperCase()} - ${template.title}</div>
                    </div>
                    <div class="action-buttons">
                        <button class="btn btn-small btn-secondary" onclick="editTemplate(${template.id})" data-en="Edit" data-ar="تعديل">Edit</button>
                        <button class="btn btn-small btn-secondary" onclick="previewTemplate(${template.id})" data-en="Preview" data-ar="معاينة">Preview</button>
                        <button class="btn btn-small btn-secondary" onclick="deleteTemplate(${template.id})" style="background: #FF6B9D; color: white;" data-en="Delete" data-ar="حذف">Delete</button>
                    </div>
                </div>
            `).join('');
        }

        function createNewTemplate() {
            currentTemplateId = null;
            document.getElementById('templateEditor').style.display = 'block';
            document.getElementById('templateName').value = '';
            document.getElementById('templateType').value = 'push';
            document.getElementById('templateTitle').value = '';
            document.getElementById('templateMessage').value = '';
            updatePreview();
        }

        function editTemplate(id) {
            const template = mockTemplates.find(t => t.id === id);
            if (!template) return;
            
            currentTemplateId = id;
            document.getElementById('templateEditor').style.display = 'block';
            document.getElementById('templateName').value = template.name;
            document.getElementById('templateType').value = template.type;
            document.getElementById('templateTitle').value = template.title;
            document.getElementById('templateMessage').value = template.message;
            updatePreview();
        }

        function saveTemplate(event) {
            event.preventDefault();
            const name = document.getElementById('templateName').value;
            const type = document.getElementById('templateType').value;
            const title = document.getElementById('templateTitle').value;
            const message = document.getElementById('templateMessage').value;
            
            if (currentTemplateId) {
                
                const template = mockTemplates.find(t => t.id === currentTemplateId);
                if (template) {
                    template.name = name;
                    template.type = type;
                    template.title = title;
                    template.message = message;
                }
            } else {
                
                mockTemplates.push({
                    id: mockTemplates.length + 1,
                    name, type, title, message
                });
            }

            loadTemplates();
            document.getElementById('templateEditor').style.display = 'none';
            showNotification(currentLanguage === 'en' ? 'Template saved successfully!' : 'تم حفظ القالب بنجاح!', 'success');
        }

        function cancelTemplateEdit() {
            document.getElementById('templateEditor').style.display = 'none';
            currentTemplateId = null;
        }

        function deleteTemplate(id) {
            if (confirm(currentLanguage === 'en' ? 'Delete this template?' : 'حذف هذا القالب؟')) {
                const index = mockTemplates.findIndex(t => t.id === id);
                if (index > -1) {
                    mockTemplates.splice(index, 1);

                    loadTemplates();
                    showNotification(currentLanguage === 'en' ? 'Template deleted!' : 'تم حذف القالب!', 'success');
                }
            }
        }

        function previewTemplate(id) {
            const template = mockTemplates.find(t => t.id === id);
            if (!template) return;
            
            document.getElementById('previewIcon').textContent = template.type === 'push' ? '🔔' : template.type === 'email' ? '📧' : '📱';
            document.getElementById('previewTitle').textContent = template.title;
            document.getElementById('previewType').textContent = template.type.toUpperCase();
            document.getElementById('previewContent').textContent = template.message;
            
            const preview = document.getElementById('templatePreview');
            preview.className = 'template-preview ' + template.type;
            preview.scrollIntoView({ behavior: 'smooth' });
        }

        function updatePreview() {
            const type = document.getElementById('templateType').value;
            const title = document.getElementById('templateTitle').value || 'Template Preview';
            const message = document.getElementById('templateMessage').value || 'Your message will appear here...';
            
            document.getElementById('previewIcon').textContent = type === 'push' ? '🔔' : type === 'email' ? '📧' : '📱';
            document.getElementById('previewTitle').textContent = title;
            document.getElementById('previewType').textContent = type === 'push' ? 'PUSH NOTIFICATION' : type === 'email' ? 'EMAIL' : 'SMS';
            document.getElementById('previewContent').textContent = message;
            
            const preview = document.getElementById('templatePreview');
            preview.className = 'template-preview ' + type;
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
                    const message = currentLanguage === 'en' ? result.message : (result.message_ar || result.message);
                    showNotification(message, 'success');
                    
                    document.getElementById('currentPassword').value = '';
                    document.getElementById('newPassword').value = '';
                    document.getElementById('confirmPassword').value = '';
                } else {
                    const message = currentLanguage === 'en' ? result.message : (result.message_ar || result.message || 'فشل تغيير كلمة السر');
                    showNotification(message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(currentLanguage === 'en' ? 'An error occurred. Please try again.' : 'حدث خطأ. يرجى المحاولة مرة أخرى.', 'error');
            });
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

        function togglePreference(element, type) {
            element.classList.toggle('active');

        }

        function changeLanguage() {
            const lang = document.getElementById('prefLanguage').value;
            if (lang !== currentLanguage) {
                toggleLanguage();
            }
        }

        function renderQuickMenuPreview() {
            const container = document.getElementById('quickMenuPreview');
            container.innerHTML = quickMenuItems.map(item => `
                <div class="preview-menu-item" onclick="toggleQuickMenuItem('${item.id}')" style="${!item.enabled ? 'opacity: 0.5;' : ''}">
                    <div class="preview-menu-icon">${item.icon}</div>
                    <div style="font-weight: 700; font-size: 0.9rem;">${currentLanguage === 'en' ? item.title.en : item.title.ar}</div>
                    <div style="margin-top: 0.5rem;">
                        <div class="toggle-switch ${item.enabled ? 'active' : ''}" onclick="event.stopPropagation(); toggleQuickMenuItem('${item.id}')"></div>
                    </div>
                </div>
            `).join('');
        }

        function toggleQuickMenuItem(id) {
            const item = quickMenuItems.find(i => i.id === id);
            if (item) {
                item.enabled = !item.enabled;

                renderQuickMenuPreview();
            }
        }

        const mockNotifications = [
            { id: 1, icon: '⚙️', title: 'Settings Updated', message: 'Your notification preferences were updated', time: '2 hours ago', read: false },
            { id: 2, icon: '📧', title: 'Template Created', message: 'New notification template created', time: '5 hours ago', read: false },
            { id: 3, icon: '👤', title: 'Profile Updated', message: 'Your profile information was updated', time: '1 day ago', read: false }
        ];

        function toggleNotifications() {
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

        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('notificationsDropdown');
            const btn = event.target.closest('.header-nav-btn');
            if (dropdown && !dropdown.contains(event.target) && !btn) {
                dropdown.classList.remove('active');
            }
        });

        window.addEventListener('DOMContentLoaded', function() {
            loadTemplates();
            updateNotificationBadge();

            const hash = window.location.hash.substring(1); 
            if (hash) {
                const validTabs = ['notifications', 'profile', 'preferences', 'dashboard-preview'];
                if (validTabs.includes(hash)) {
                    switchTab(hash);
                }
            }
        });

        window.addEventListener('hashchange', function() {
            const hash = window.location.hash.substring(1);
            const validTabs = ['notifications', 'profile', 'preferences', 'dashboard-preview'];
            if (validTabs.includes(hash)) {
                switchTab(hash);
            }
        });
    </script>
</body>
</html>

