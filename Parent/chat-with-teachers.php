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
            SELECT psr.Student_ID
            FROM parent_student_relationship psr
            WHERE psr.Parent_ID = ?
        ");
        $stmt->execute([$currentParentId]);
        $linkedStudentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($linkedStudentIds)) {
            $placeholders = implode(',', array_fill(0, count($linkedStudentIds), '?'));
            $stmt = $pdo->prepare("
                SELECT s.Student_ID, s.Class_ID, c.Name as Class_Name
                FROM student s
                LEFT JOIN class c ON s.Class_ID = c.Class_ID
                WHERE s.Student_ID IN ($placeholders)
            ");
            $stmt->execute($linkedStudentIds);
            $linkedStudentsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Error fetching linked students: " . $e->getMessage());
        $linkedStudentIds = [];
    }
}

$assignedTeachers = [];
if (!empty($linkedStudentIds) && !empty($linkedStudentsData)) {
    $classIds = array_filter(array_column($linkedStudentsData, 'Class_ID'));
    if (!empty($classIds)) {
        $classPlaceholders = implode(',', array_fill(0, count($classIds), '?'));
        try {
            $stmt = $pdo->prepare("
                SELECT DISTINCT t.Teacher_ID, t.NameEn, t.NameAr, t.Subject, t.Email,
                       co.Course_Name, co.Course_ID
                FROM teacher_class_course tcc
                INNER JOIN teacher t ON tcc.Teacher_ID = t.Teacher_ID
                INNER JOIN course co ON tcc.Course_ID = co.Course_ID
                WHERE tcc.Class_ID IN ($classPlaceholders)
                ORDER BY t.NameEn ASC
            ");
            $stmt->execute($classIds);
            $assignedTeachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching assigned teachers: " . $e->getMessage());
            $assignedTeachers = [];
        }
    }
}

$unreadCounts = [];
if (!empty($assignedTeachers)) {
    $teacherIds = array_column($assignedTeachers, 'Teacher_ID');
    $placeholders = implode(',', array_fill(0, count($teacherIds), '?'));
    
    try {
        $stmt = $pdo->prepare("
            SELECT Sender_ID as Teacher_ID, COUNT(*) as unread_count
            FROM message
            WHERE Sender_Type = 'teacher'
            AND Sender_ID IN ($placeholders)
            AND Receiver_Type = 'parent'
            AND Receiver_ID = ?
            AND Is_Read = 0
            GROUP BY Sender_ID
        ");
        $params = array_merge($teacherIds, [$currentParentId]);
        $stmt->execute($params);
        $unreadData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($unreadData as $row) {
            $unreadCounts[$row['Teacher_ID']] = intval($row['unread_count']);
        }
    } catch (PDOException $e) {
        error_log("Error fetching unread counts: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat with Teachers - HUDURY</title>
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
                <span class="page-icon">💬</span>
                <span data-en="Chat with Teachers" data-ar="الدردشة مع المعلمين">Chat with Teachers</span>
            </h1>
            <p class="page-subtitle" data-en="Communicate with your child's teachers" data-ar="تواصل مع معلمي طفلك">Communicate with your child's teachers</p>
        </div>

        <div class="chat-page-container">
            
            <div class="teachers-sidebar" id="teachersSidebar">
                <div class="teachers-sidebar-header">
                    <h3 data-en="Teachers" data-ar="المعلمون">Teachers</h3>
                    <button class="close-sidebar-btn" onclick="toggleTeachersSidebar()" id="closeSidebarBtn" style="display: none;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="teachers-list-full">
                    <?php if (empty($assignedTeachers)): ?>
                        <div style="text-align: center; padding: 2rem; color: #666;">
                            <div style="font-size: 3rem; margin-bottom: 1rem;">👨‍🏫</div>
                            <div data-en="No teachers available" data-ar="لا يوجد معلمون متاحون">No teachers available</div>
                            <div style="font-size: 0.9rem; margin-top: 0.5rem;" data-en="Teachers will appear here once assigned to your child's classes" data-ar="سيظهر المعلمون هنا بمجرد تعيينهم لفصول طفلك">Teachers will appear here once assigned to your child's classes</div>
                        </div>
                    <?php else: ?>
                        <?php 
                        $firstTeacher = true;
                        foreach ($assignedTeachers as $teacher): 
                            $teacherId = $teacher['Teacher_ID'];
                            $unreadCount = $unreadCounts[$teacherId] ?? 0;
                            $teacherName = $teacher['NameEn'] ?? $teacher['NameAr'] ?? 'Teacher';
                            $courseName = $teacher['Course_Name'] ?? $teacher['Subject'] ?? 'Subject';
                        ?>
                            <div class="teacher-item <?php echo $firstTeacher ? 'active' : ''; ?>" 
                                 onclick="selectTeacher(<?php echo $teacherId; ?>, this)" 
                                 data-teacher-id="<?php echo $teacherId; ?>">
                                <div class="teacher-avatar">👨‍🏫</div>
                                <div class="teacher-info">
                                    <div class="teacher-name"><?php echo htmlspecialchars($teacherName); ?></div>
                                    <div class="teacher-subject"><?php echo htmlspecialchars($courseName); ?></div>
                                </div>
                                <?php if ($unreadCount > 0): ?>
                                    <div class="teacher-badge"><?php echo $unreadCount; ?></div>
                                <?php else: ?>
                                    <div class="teacher-badge" style="display: none;">0</div>
                                <?php endif; ?>
                            </div>
                        <?php 
                            $firstTeacher = false;
                        endforeach; 
                        ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="chat-main-area">
                <div class="chat-header-full">
                    <button class="mobile-teacher-list-btn" onclick="toggleTeachersSidebar()" id="mobileTeacherListBtn">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="active-teacher-info-full">
                        <div class="active-teacher-avatar" id="activeTeacherAvatar">👨‍🏫</div>
                        <div>
                            <div class="active-teacher-name" id="activeTeacherName">
                                <?php echo !empty($assignedTeachers) ? htmlspecialchars($assignedTeachers[0]['NameEn'] ?? $assignedTeachers[0]['NameAr'] ?? 'Teacher') : 'Select a teacher'; ?>
                            </div>
                            <div class="active-teacher-subject" id="activeTeacherSubject">
                                <?php echo !empty($assignedTeachers) ? htmlspecialchars($assignedTeachers[0]['Course_Name'] ?? $assignedTeachers[0]['Subject'] ?? 'Subject') : ''; ?>
                            </div>
                        </div>
                    </div>
                    <div class="chat-header-actions">
                        <button class="chat-action-btn" onclick="showTeacherInfo()" title="Teacher Info">
                            <i class="fas fa-info-circle"></i>
                        </button>
                    </div>
                </div>

                <div class="chat-messages-full" id="chatMessagesContainer">
                    <?php if (empty($assignedTeachers)): ?>
                        <div style="text-align: center; padding: 3rem; color: #999;">
                            <div style="font-size: 3rem; margin-bottom: 1rem;">💬</div>
                            <div data-en="No teachers available" data-ar="لا يوجد معلمون متاحون">No teachers available</div>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 2rem; color: #999;">
                            <div style="font-size: 2rem; margin-bottom: 0.5rem;">💬</div>
                            <div data-en="Loading messages..." data-ar="جاري تحميل الرسائل...">Loading messages...</div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="chat-input-full">
                    <button class="chat-attach-btn" onclick="attachFile()" title="Attach File">
                        <i class="fas fa-paperclip"></i>
                    </button>
                    <input type="text" placeholder="Type your message..." id="chatInput" onkeypress="handleChatKeyPress(event)" data-placeholder-en="Type your message..." data-placeholder-ar="اكتب رسالتك...">
                    <button class="chat-send-btn" onclick="sendMessage()" title="Send">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="teacherInfoModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <span onclick="closeTeacherInfo()" style="float: right; font-size: 32px; cursor: pointer; color: #FF6B9D; font-weight: bold;">&times;</span>
            <h2 style="margin-bottom: 1.5rem; color: #FF6B9D; font-family: 'Fredoka', sans-serif; font-size: 2rem;" id="teacherInfoName">Ms. Sarah</h2>
            <div class="teacher-info-details">
                <div class="info-detail-item">
                    <div class="info-detail-label" data-en="Subject" data-ar="المادة">Subject</div>
                    <div class="info-detail-value" id="teacherInfoSubject" data-en="Mathematics" data-ar="الرياضيات">Mathematics</div>
                </div>
                <div class="info-detail-item">
                    <div class="info-detail-label" data-en="Email" data-ar="البريد الإلكتروني">Email</div>
                    <div class="info-detail-value" id="teacherInfoEmail">sarah.teacher@school.edu</div>
                </div>
                <div class="info-detail-item">
                    <div class="info-detail-label" data-en="Office Hours" data-ar="ساعات العمل">Office Hours</div>
                    <div class="info-detail-value" data-en="Monday - Friday, 2:00 PM - 4:00 PM" data-ar="الإثنين - الجمعة، 2:00 مساءً - 4:00 مساءً">Monday - Friday, 2:00 PM - 4:00 PM</div>
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
        
        let currentTeacherId = null;
        const teachersData = <?php echo json_encode($assignedTeachers); ?>;
        const currentParentId = <?php echo $currentParentId; ?>;

        <?php if (!empty($assignedTeachers)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            selectTeacher(<?php echo $assignedTeachers[0]['Teacher_ID']; ?>, document.querySelector('.teacher-item.active'));
        });
        <?php endif; ?>
        
        function selectTeacher(teacherId, clickedElement = null) {
            currentTeacherId = teacherId;

            const teacher = teachersData.find(t => t.Teacher_ID == teacherId);
            if (!teacher) return;

            document.querySelectorAll('.teacher-item').forEach(item => {
                item.classList.remove('active');
            });
            if (clickedElement) {
                clickedElement.classList.add('active');
            } else {
                const teacherItem = document.querySelector(`.teacher-item[data-teacher-id="${teacherId}"]`);
                if (teacherItem) teacherItem.classList.add('active');
            }

            const teacherName = teacher.NameEn || teacher.NameAr || 'Teacher';
            const courseName = teacher.Course_Name || teacher.Subject || 'Subject';
            document.getElementById('activeTeacherName').textContent = teacherName;
            document.getElementById('activeTeacherSubject').textContent = courseName;
            document.getElementById('activeTeacherAvatar').textContent = '👨‍🏫';

            loadMessages(teacherId);

            document.getElementById('chatInput').value = '';

            if (window.innerWidth <= 768) {
                toggleTeachersSidebar();
            }
        }
        
        function loadMessages(teacherId) {
            const container = document.getElementById('chatMessagesContainer');
            container.innerHTML = '<div style="text-align: center; padding: 2rem; color: #999;"><div style="font-size: 2rem; margin-bottom: 0.5rem;">💬</div><div>Loading messages...</div></div>';
            
            fetch(`chat-ajax.php?action=getMessages&teacher_id=${teacherId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderMessages(data.messages, teacherId);
                        
                        markMessagesAsRead(teacherId);
                    } else {
                        container.innerHTML = '<div style="text-align: center; padding: 2rem; color: #999;"><div style="font-size: 2rem; margin-bottom: 0.5rem;">💬</div><div data-en="No messages yet. Start the conversation!" data-ar="لا توجد رسائل بعد. ابدأ المحادثة!">No messages yet. Start the conversation!</div></div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading messages:', error);
                    container.innerHTML = '<div style="text-align: center; padding: 2rem; color: #FF6B9D;"><div>Error loading messages. Please try again.</div></div>';
                });
        }
        
        function renderMessages(messages, teacherId) {
            const container = document.getElementById('chatMessagesContainer');
            const teacher = teachersData.find(t => t.Teacher_ID == teacherId);
            const teacherName = teacher ? (teacher.NameEn || teacher.NameAr || 'Teacher') : 'Teacher';
            
            if (messages.length === 0) {
                container.innerHTML = '<div style="text-align: center; padding: 2rem; color: #999;"><div style="font-size: 2rem; margin-bottom: 0.5rem;">💬</div><div data-en="No messages yet. Start the conversation!" data-ar="لا توجد رسائل بعد. ابدأ المحادثة!">No messages yet. Start the conversation!</div></div>';
                return;
            }
            
            container.innerHTML = messages.map(msg => {
                const isSent = msg.Sender_Type === 'parent' && msg.Sender_ID == currentParentId;
                const senderName = isSent ? (currentLanguage === 'en' ? 'You' : 'أنت') : teacherName;
                const msgDate = new Date(msg.Created_At);
                const timeStr = formatMessageTime(msgDate);
                
                return `
                    <div class="message ${isSent ? 'sent' : 'received'}">
                        <div class="message-header">
                            ${!isSent ? `<div class="message-sender" style="font-weight: 700;">${senderName}</div>` : ''}
                            <div class="message-time">${timeStr}</div>
                        </div>
                        <div class="message-content">${escapeHtml(msg.Message_Text)}</div>
                    </div>
                `;
            }).join('');

            setTimeout(() => {
                container.scrollTop = container.scrollHeight;
            }, 100);
        }
        
        function formatMessageTime(date) {
            const now = new Date();
            const diff = now - date;
            const minutes = Math.floor(diff / 60000);
            const hours = Math.floor(diff / 3600000);
            const days = Math.floor(diff / 86400000);
            
            if (minutes < 1) return currentLanguage === 'en' ? 'Just now' : 'الآن';
            if (minutes < 60) return `${minutes} ${currentLanguage === 'en' ? 'min ago' : 'دقيقة'}`;
            if (hours < 24) return `${hours} ${currentLanguage === 'en' ? 'hour' : 'ساعة'}${hours > 1 ? (currentLanguage === 'en' ? 's' : '') : ''} ago`;
            if (days < 7) return `${days} ${currentLanguage === 'en' ? 'day' : 'يوم'}${days > 1 ? (currentLanguage === 'en' ? 's' : '') : ''} ago`;
            return date.toLocaleDateString();
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function sendMessage() {
            if (!currentTeacherId) return;
            
            const input = document.getElementById('chatInput');
            const content = input.value.trim();
            if (!content) return;

            input.disabled = true;
            const sendBtn = document.querySelector('.chat-send-btn');
            if (sendBtn) sendBtn.disabled = true;

            const container = document.getElementById('chatMessagesContainer');
            const messageDiv = document.createElement('div');
            messageDiv.className = 'message sent';
            messageDiv.innerHTML = `
                <div class="message-header">
                    <div class="message-time">${currentLanguage === 'en' ? 'Sending...' : 'جاري الإرسال...'}</div>
                </div>
                <div class="message-content">${escapeHtml(content)}</div>
            `;
            container.appendChild(messageDiv);
            container.scrollTop = container.scrollHeight;

            const formData = new FormData();
            formData.append('action', 'sendMessage');
            formData.append('teacher_id', currentTeacherId);
            formData.append('message', content);
            
            fetch('chat-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                input.disabled = false;
                if (sendBtn) sendBtn.disabled = false;
                
                if (data.success) {
                    
                    const timeStr = formatMessageTime(new Date());
                    messageDiv.querySelector('.message-time').textContent = timeStr;
                    input.value = '';
                    
                    setTimeout(() => loadMessages(currentTeacherId), 1000);
                } else {
                    showNotification(data.message || (currentLanguage === 'en' ? 'Error sending message' : 'خطأ في إرسال الرسالة'), 'error');
                    messageDiv.remove();
                }
            })
            .catch(error => {
                console.error('Error sending message:', error);
                input.disabled = false;
                if (sendBtn) sendBtn.disabled = false;
                showNotification(currentLanguage === 'en' ? 'Error sending message' : 'خطأ في إرسال الرسالة', 'error');
                messageDiv.remove();
            });
        }
        
        function markMessagesAsRead(teacherId) {
            fetch('chat-ajax.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=markAsRead&teacher_id=${teacherId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    
                    const teacherItem = document.querySelector(`.teacher-item[data-teacher-id="${teacherId}"]`);
                    if (teacherItem) {
                        const badge = teacherItem.querySelector('.teacher-badge');
                        if (badge) badge.style.display = 'none';
                    }
                }
            })
            .catch(error => console.error('Error marking as read:', error));
        }
        
        function handleChatKeyPress(event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                sendMessage();
            }
        }
        
        function toggleTeachersSidebar() {
            const sidebar = document.getElementById('teachersSidebar');
            const closeBtn = document.getElementById('closeSidebarBtn');
            const mobileBtn = document.getElementById('mobileTeacherListBtn');
            
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle('mobile-active');
                if (sidebar.classList.contains('mobile-active')) {
                    document.body.style.overflow = 'hidden';
                } else {
                    document.body.style.overflow = '';
                }
            }
        }
        
        function showTeacherInfo() {
            if (!currentTeacherId) return;
            const teacher = teachersData.find(t => t.Teacher_ID == currentTeacherId);
            if (!teacher) return;
            
            document.getElementById('teacherInfoName').textContent = teacher.NameEn || teacher.NameAr || 'Teacher';
            document.getElementById('teacherInfoSubject').textContent = teacher.Course_Name || teacher.Subject || 'Subject';
            document.getElementById('teacherInfoEmail').textContent = teacher.Email || 'N/A';
            document.getElementById('teacherInfoModal').style.display = 'flex';
        }
        
        function closeTeacherInfo() {
            document.getElementById('teacherInfoModal').style.display = 'none';
        }
        
        function attachFile() {
            alert(currentLanguage === 'en' ? 'File attachment feature coming soon!' : 'ميزة إرفاق الملفات قريباً!');
        }
        
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 1rem 1.5rem;
                background: ${type === 'success' ? '#6BCB77' : '#FF6B9D'};
                color: white;
                border-radius: 10px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.2);
                z-index: 10000;
                animation: slideIn 0.3s;
            `;
            notification.textContent = message;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }

        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);

        setInterval(() => {
            if (currentTeacherId) {
                loadMessages(currentTeacherId);
            }
        }, 5000);

        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('teachersSidebar');
            const mobileBtn = document.getElementById('mobileTeacherListBtn');
            if (window.innerWidth <= 768 && sidebar && sidebar.classList.contains('mobile-active')) {
                if (!sidebar.contains(event.target) && (!mobileBtn || !mobileBtn.contains(event.target))) {
                    toggleTeachersSidebar();
                }
            }
        });

        function openWhatsAppHelp() {
            const phoneNumber = '0797020622';
            const message = encodeURIComponent('Hello, I need help.');
            const whatsappUrl = `https://wa.me/${phoneNumber}?text=${message}`;
            window.open(whatsappUrl, '_blank');
        }
    </script>

    <div class="need-help-box" onclick="openWhatsAppHelp()">
        <i class="fab fa-whatsapp"></i>
        <span data-en="Need Help?" data-ar="تحتاج مساعدة؟">Need Help?</span>
    </div>
    <style>
        .chat-page-container {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 2rem;
            height: calc(100vh - 250px);
            min-height: 600px;
        }

        .teachers-sidebar {
            background: white;
            border-radius: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .teachers-sidebar-header {
            background: linear-gradient(135deg, #FF6B9D, #C44569);
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .teachers-sidebar-header h3 {
            font-family: 'Fredoka', sans-serif;
            font-size: 1.5rem;
            margin: 0;
        }

        .close-sidebar-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .close-sidebar-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: rotate(90deg);
        }

        .teachers-list-full {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
        }

        .chat-main-area {
            background: white;
            border-radius: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .chat-header-full {
            background: linear-gradient(135deg, #FF6B9D, #C44569);
            color: white;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .mobile-teacher-list-btn {
            display: none;
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.2rem;
            transition: all 0.3s;
        }

        .mobile-teacher-list-btn:hover {
            background: rgba(255,255,255,0.3);
        }

        .active-teacher-info-full {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex: 1;
        }

        .active-teacher-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .active-teacher-name {
            font-weight: 700;
            font-size: 1.2rem;
            margin-bottom: 0.2rem;
        }

        .active-teacher-subject {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .chat-header-actions {
            display: flex;
            gap: 0.5rem;
        }

        .chat-action-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .chat-action-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: scale(1.1);
        }

        .chat-messages-full {
            flex: 1;
            overflow-y: auto;
            padding: 2rem;
            background: #FFF9F5;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .message {
            padding: 1rem 1.5rem;
            border-radius: 20px;
            max-width: 75%;
            animation: fadeInMessage 0.3s ease;
            word-wrap: break-word;
        }

        .message.received {
            background: white;
            color: var(--text-dark);
            border-bottom-left-radius: 5px;
            align-self: flex-start;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .message.sent {
            background: linear-gradient(135deg, #FF6B9D, #C44569);
            color: white;
            border-bottom-right-radius: 5px;
            align-self: flex-end;
            box-shadow: 0 2px 10px rgba(255, 107, 157, 0.3);
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
            opacity: 0.8;
        }

        .message-sender {
            font-weight: 700;
        }

        .message-time {
            font-size: 0.8rem;
        }

        .message-content {
            line-height: 1.6;
        }

        .chat-input-full {
            padding: 1.5rem;
            background: white;
            border-top: 2px solid #FFE5E5;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .chat-attach-btn,
        .chat-send-btn {
            background: linear-gradient(135deg, #FF6B9D, #6BCB77);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            transition: all 0.3s;
            flex-shrink: 0;
        }

        .chat-attach-btn:hover,
        .chat-send-btn:hover {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 5px 15px rgba(255, 107, 157, 0.4);
        }

        .chat-input-full input {
            flex: 1;
            padding: 1rem 1.5rem;
            border: 3px solid #FFE5E5;
            border-radius: 25px;
            font-family: 'Nunito', sans-serif;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .chat-input-full input:focus {
            outline: none;
            border-color: #FF6B9D;
            box-shadow: 0 0 0 3px rgba(255, 107, 157, 0.1);
        }

        .teacher-info-details {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .info-detail-item {
            padding: 1rem;
            background: #FFF9F5;
            border-radius: 15px;
            border-left: 4px solid #FF6B9D;
        }

        .info-detail-label {
            font-weight: 700;
            color: #666;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .info-detail-value {
            color: var(--text-dark);
            font-size: 1.1rem;
        }

        @media (max-width: 768px) {
            .chat-page-container {
                grid-template-columns: 1fr;
                height: calc(100vh - 200px);
                min-height: 500px;
                gap: 0;
            }

            .teachers-sidebar {
                position: fixed;
                left: -100%;
                top: 0;
                width: 85%;
                max-width: 350px;
                height: 100vh;
                z-index: 2000;
                border-radius: 0;
                transition: left 0.3s ease;
                box-shadow: 5px 0 30px rgba(0,0,0,0.3);
            }

            .teachers-sidebar.mobile-active {
                left: 0;
            }

            .mobile-teacher-list-btn {
                display: flex;
            }

            .close-sidebar-btn {
                display: flex !important;
            }

            .chat-main-area {
                border-radius: 0;
                height: 100%;
            }

            .chat-header-full {
                padding: 1rem 1.5rem;
            }

            .chat-messages-full {
                padding: 1.5rem 1rem;
            }

            .message {
                max-width: 85%;
                padding: 0.8rem 1.2rem;
            }

            .chat-input-full {
                padding: 1rem;
            }

            .chat-attach-btn,
            .chat-send-btn {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }

            .chat-input-full input {
                padding: 0.8rem 1.2rem;
                font-size: 16px; 
            }
        }

        @keyframes fadeInMessage {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .need-help-box {
            position: fixed;
            bottom: 20px;
            left: 20px;
            background: linear-gradient(135deg, #25D366, #128C7E);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 25px;
            box-shadow: 0 4px 15px rgba(37, 211, 102, 0.4);
            cursor: pointer;
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s;
            font-family: 'Nunito', sans-serif;
        }
        
        .need-help-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(37, 211, 102, 0.5);
        }
        
        .need-help-box i {
            font-size: 1.2rem;
        }
        
        @media (max-width: 768px) {
            .need-help-box {
                bottom: 15px;
                left: 15px;
                padding: 0.8rem 1.2rem;
                font-size: 0.9rem;
            }
        }
        
        @media (max-width: 480px) {
            .need-help-box {
                bottom: 10px;
                left: 10px;
                padding: 0.7rem 1rem;
                font-size: 0.85rem;
            }
        }
    </style>
</body>
</html>

