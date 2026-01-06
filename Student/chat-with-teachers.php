<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-student.php';

requireUserType('student');

$currentStudentId = getCurrentUserId();
$currentStudent = getCurrentUserData($pdo);
$studentName = $_SESSION['user_name'] ?? 'Student';
$studentClassId = $currentStudent['Class_ID'] ?? null;

$assignedTeachers = [];
if ($studentClassId) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT t.Teacher_ID, t.NameEn, t.NameAr, t.Subject,
                   co.Course_Name, co.Course_ID
            FROM teacher_class_course tcc
            INNER JOIN teacher t ON tcc.Teacher_ID = t.Teacher_ID
            INNER JOIN course co ON tcc.Course_ID = co.Course_ID
            WHERE tcc.Class_ID = ?
            ORDER BY t.NameEn ASC
        ");
        $stmt->execute([$studentClassId]);
        $assignedTeachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching assigned teachers: " . $e->getMessage());
        $assignedTeachers = [];
    }
}

$unreadCounts = [];
if (!empty($assignedTeachers)) {
    $teacherIds = array_column($assignedTeachers, 'Teacher_ID');
    $placeholders = implode(',', array_fill(0, count($teacherIds), '?'));
    
    try {
        $stmt = $pdo->prepare("
            SELECT Receiver_ID as Teacher_ID, COUNT(*) as unread_count
            FROM message
            WHERE Sender_Type = 'teacher'
            AND Sender_ID IN ($placeholders)
            AND Receiver_Type = 'student'
            AND Receiver_ID = ?
            AND Is_Read = 0
            GROUP BY Receiver_ID
        ");
        $params = array_merge($teacherIds, [$currentStudentId]);
        $stmt->execute($params);
        $unreadData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($unreadData as $row) {
            $unreadCounts[$row['Teacher_ID']] = intval($row['unread_count']);
        }
    } catch (PDOException $e) {
        error_log("Error fetching unread counts: " . $e->getMessage());
    }
}

if (!isset($notifications)) {
    $notifications = [];
    try {
        if ($currentStudentId && $studentClassId) {

            $query = "
                SELECT Notif_ID, Title, Content, Date_Sent, Sender_Type, Target_Role, Target_Class_ID, Target_Student_ID
                FROM notification
                WHERE (
                    Target_Role = 'All'
                    OR (Target_Role = 'Student' AND Target_Student_ID = ?)
                    OR (Target_Role = 'Student' AND Target_Class_ID = ? AND Target_Student_ID IS NULL)
                )
                ORDER BY Date_Sent DESC
                LIMIT 20
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute([$currentStudentId, $studentClassId]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Error fetching notifications for students: " . $e->getMessage());
        $notifications = [];
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
    <style>
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
            margin-bottom: 1rem;
        }
        .back-button:hover {
            background: rgba(255,255,255,0.3);
            transform: translateX(-5px);
        }
        .chat-page-container {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 2rem;
            margin-top: 2rem;
        }
        .teachers-sidebar {
            background: white;
            border-radius: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            height: calc(100vh - 300px);
            min-height: 600px;
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
        .teachers-list-full {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
        }
        .teacher-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.5rem;
            background: #FFF9F5;
            border-radius: 15px;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            border: 3px solid transparent;
        }
        .teacher-item:hover {
            background: #FFE5E5;
            transform: translateX(5px);
        }
        .teacher-item.active {
            background: linear-gradient(135deg, #FFE5E5, #E5F3FF);
            border-color: #FF6B9D;
        }
        .teacher-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #FF6B9D, #6BCB77);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        .teacher-info {
            flex: 1;
            min-width: 0;
        }
        .teacher-name {
            font-weight: 700;
            margin-bottom: 0.3rem;
            color: var(--text-dark);
        }
        .teacher-subject {
            font-size: 0.85rem;
            color: #666;
        }
        .teacher-badge {
            background: #FF6B9D;
            color: white;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .chat-main-area {
            background: white;
            border-radius: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            height: calc(100vh - 300px);
            min-height: 600px;
        }
        .chat-header-full {
            background: linear-gradient(135deg, #FF6B9D, #C44569);
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .active-teacher-info {
            display: flex;
            align-items: center;
            gap: 1rem;
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
            word-wrap: break-word;
            animation: fadeIn 0.3s;
        }
        .message.sent {
            background: linear-gradient(135deg, #FF6B9D, #C44569);
            color: white;
            align-self: flex-end;
            border-bottom-right-radius: 5px;
        }
        .message.received {
            background: white;
            color: var(--text-dark);
            align-self: flex-start;
            border-bottom-left-radius: 5px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
            opacity: 0.8;
        }
        .message-content {
            line-height: 1.6;
        }
        .message-time {
            font-size: 0.8rem;
            margin-top: 0.3rem;
            opacity: 0.7;
        }
        .chat-input-full {
            padding: 1.5rem;
            background: white;
            border-top: 2px solid #FFE5E5;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .chat-input {
            flex: 1;
            padding: 1rem 1.5rem;
            border: 3px solid #FFE5E5;
            border-radius: 25px;
            font-family: 'Nunito', sans-serif;
            font-size: 1rem;
        }
        .chat-input:focus {
            outline: none;
            border-color: #FF6B9D;
        }
        .chat-attach-btn, .chat-send-btn {
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
        }
        .chat-attach-btn:hover, .chat-send-btn:hover {
            transform: scale(1.1) rotate(5deg);
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
        }
        @media (max-width: 1024px) {
            .chat-page-container {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .teachers-sidebar {
                position: fixed;
                left: -400px;
                top: 0;
                width: 380px;
                max-width: 85vw;
                height: 100vh;
                z-index: 2000;
                transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            }
            .teachers-sidebar.mobile-active {
                left: 0;
            }
            .chat-header-full {
                padding-left: 4rem;
            }
            .message {
                max-width: 85%;
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }
            .chat-input-full {
                padding: 1rem;
            }
            .chat-input {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }
        }
        
        @media (max-width: 480px) {
            .chat-page-container {
                margin-top: 1rem;
            }
            .teachers-sidebar {
                width: 100vw;
            }
            .message {
                max-width: 90%;
                padding: 0.6rem 0.8rem;
                font-size: 0.85rem;
            }
            .message-header {
                font-size: 0.75rem;
            }
            .chat-input-full {
                padding: 0.75rem;
            }
            .chat-send-btn {
                width: 40px;
                height: 40px;
                font-size: 1rem;
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
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>
    
    <div class="main-content">

    <div class="dashboard-container">
        
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">💬</span>
                <span data-en="Chat with Teachers" data-ar="الدردشة مع المعلمين">Chat with Teachers</span>
            </h1>
            <p class="page-subtitle" data-en="Communicate with your teachers about assignments, grades, and questions" data-ar="تواصل مع معلميك حول الواجبات والدرجات والأسئلة">Communicate with your teachers about assignments, grades, and questions</p>
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
                            <div data-en="No teachers assigned" data-ar="لا يوجد معلمون معينون">No teachers assigned</div>
                            <div style="font-size: 0.9rem; margin-top: 0.5rem;" data-en="Teachers will appear here once assigned to your class" data-ar="سيظهر المعلمون هنا بمجرد تعيينهم لفصلك">Teachers will appear here once assigned to your class</div>
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
                                 onclick="selectTeacher(<?php echo $teacherId; ?>)" 
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
                <?php if (!empty($assignedTeachers)): ?>
                <div class="chat-header-full">
                    <div class="active-teacher-info">
                        <button class="close-sidebar-btn" onclick="toggleTeachersSidebar()" id="openSidebarBtn" style="display: none; background: rgba(255,255,255,0.2);">
                            <i class="fas fa-bars"></i>
                        </button>
                        <div class="active-teacher-avatar" id="activeTeacherAvatar">👨‍🏫</div>
                        <div>
                            <div style="font-weight: 700; font-size: 1.2rem;" id="activeTeacherName">
                                <?php echo htmlspecialchars($assignedTeachers[0]['NameEn'] ?? $assignedTeachers[0]['NameAr'] ?? 'Teacher'); ?>
                            </div>
                            <div style="font-size: 0.9rem; opacity: 0.9;" id="activeTeacherSubject">
                                <?php echo htmlspecialchars($assignedTeachers[0]['Course_Name'] ?? $assignedTeachers[0]['Subject'] ?? 'Subject'); ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="chat-messages-full" id="chatMessagesContainer">
                    <div style="text-align: center; padding: 2rem; color: #999;">
                        <div style="font-size: 2rem; margin-bottom: 0.5rem;">💬</div>
                        <div data-en="Loading messages..." data-ar="جاري تحميل الرسائل...">Loading messages...</div>
                    </div>
                </div>
                <?php else: ?>
                <div style="display: flex; align-items: center; justify-content: center; height: 100%; text-align: center; color: #666;">
                    <div>
                        <div style="font-size: 4rem; margin-bottom: 1rem;">💬</div>
                        <div style="font-size: 1.2rem; font-weight: 700; margin-bottom: 0.5rem;" data-en="No Teachers Available" data-ar="لا يوجد معلمون متاحون">No Teachers Available</div>
                        <div data-en="Teachers will appear here once assigned to your class" data-ar="سيظهر المعلمون هنا بمجرد تعيينهم لفصلك">Teachers will appear here once assigned to your class</div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($assignedTeachers)): ?>
                <div class="chat-input-full">
                    <input type="text" placeholder="Type your message..." id="chatInput" onkeypress="handleChatKeyPress(event)" data-placeholder-en="Type your message..." data-placeholder-ar="اكتب رسالتك...">
                    <button class="chat-send-btn" onclick="sendMessage()" title="Send">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        
        let currentTeacherId = null;
        const teachersData = <?php echo json_encode($assignedTeachers); ?>;
        const currentStudentId = <?php echo $currentStudentId; ?>;

        <?php if (!empty($assignedTeachers)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            selectTeacher(<?php echo $assignedTeachers[0]['Teacher_ID']; ?>);
        });
        <?php endif; ?>
        
        function selectTeacher(teacherId) {
            currentTeacherId = teacherId;

            const teacher = teachersData.find(t => t.Teacher_ID == teacherId);
            if (!teacher) return;

            document.querySelectorAll('.teacher-item').forEach(item => {
                item.classList.remove('active');
            });
            const teacherItem = document.querySelector(`.teacher-item[data-teacher-id="${teacherId}"]`);
            if (teacherItem) {
                teacherItem.classList.add('active');
            }

            document.getElementById('activeTeacherName').textContent = teacher.NameEn || teacher.NameAr || 'Teacher';
            document.getElementById('activeTeacherSubject').textContent = teacher.Course_Name || teacher.Subject || 'Subject';

            loadMessages(teacherId);

            document.getElementById('chatInput').value = '';

            if (window.innerWidth <= 1024) {
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
                        container.innerHTML = '<div style="text-align: center; padding: 2rem; color: #999;"><div style="font-size: 2rem; margin-bottom: 0.5rem;">💬</div><div>No messages yet. Start the conversation!</div></div>';
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
                const isSent = msg.Sender_Type === 'student' && msg.Sender_ID == currentStudentId;
                const senderName = isSent ? (currentLanguage === 'en' ? 'You' : 'أنت') : teacherName;
                const msgDate = new Date(msg.Created_At);
                const timeStr = formatMessageTime(msgDate);
                
                return `
                    <div class="message ${isSent ? 'sent' : 'received'}">
                        <div class="message-header">
                            <div class="message-sender" style="font-weight: 700;">${senderName}</div>
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
                    <div class="message-sender" style="font-weight: 700;">${currentLanguage === 'en' ? 'You' : 'أنت'}</div>
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

        function toggleTeachersSidebar() {
            const sidebar = document.getElementById('teachersSidebar');
            if (window.innerWidth <= 1024) {
                sidebar.classList.toggle('mobile-active');
            }
        }

        function handleNotificationClick(element) {
            element.classList.remove('unread');
            updateNotificationBadge();
        }

        function markAllAsRead() {
            document.querySelectorAll('.notification-dropdown-item').forEach(item => {
                item.classList.remove('unread');
            });
            updateNotificationBadge();
        }

        function updateNotificationBadge() {
            const unreadCount = document.querySelectorAll('.notification-dropdown-item.unread').length;
            const badge = document.getElementById('notificationCount');
            const badgeMobile = document.getElementById('notificationCountMobile');
            if (unreadCount > 0) {
                if (badge) badge.textContent = unreadCount;
                if (badgeMobile) badgeMobile.textContent = unreadCount;
            } else {
                if (badge) badge.style.display = 'none';
                if (badgeMobile) badgeMobile.style.display = 'none';
            }
        }

        function openProfileSettings() {
            window.location.href = 'notifications-and-settings.php#profile';
        }

        function openSettings() {
            window.location.href = 'notifications-and-settings.php';
        }

        updateNotificationBadge();

        window.addEventListener('resize', function() {
            const openBtn = document.getElementById('openSidebarBtn');
            const closeBtn = document.getElementById('closeSidebarBtn');
            if (window.innerWidth <= 1024) {
                openBtn.style.display = 'flex';
            } else {
                openBtn.style.display = 'none';
                closeBtn.style.display = 'none';
            }
        });

        if (window.innerWidth <= 1024) {
            document.getElementById('openSidebarBtn').style.display = 'flex';
        }

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
</body>
</html>

