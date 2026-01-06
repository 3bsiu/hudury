<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-teacher.php';

requireUserType('teacher');

$currentTeacherId = getCurrentUserId();
$currentTeacher = getCurrentUserData($pdo);
$teacherName = $_SESSION['user_name'] ?? 'Teacher';

$assignedStudents = [];
$assignedParents = [];

try {
    
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.Class_ID
        FROM teacher_class_course tcc
        INNER JOIN class c ON tcc.Class_ID = c.Class_ID
        WHERE tcc.Teacher_ID = ?
    ");
    $stmt->execute([$currentTeacherId]);
    $classIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($classIds)) {
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));

        $stmt = $pdo->prepare("
            SELECT DISTINCT s.Student_ID, s.NameEn, s.NameAr, s.Student_Code,
                   c.Name as Class_Name, c.Grade_Level, c.Section
            FROM student s
            INNER JOIN class c ON s.Class_ID = c.Class_ID
            WHERE s.Class_ID IN ($placeholders)
            ORDER BY s.NameEn ASC
        ");
        $stmt->execute($classIds);
        $assignedStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($assignedStudents)) {
            $studentIds = array_column($assignedStudents, 'Student_ID');
            $studentPlaceholders = implode(',', array_fill(0, count($studentIds), '?'));
            
            $stmt = $pdo->prepare("
                SELECT DISTINCT p.Parent_ID, p.NameEn, p.NameAr, p.Email, p.Phone,
                       psr.Student_ID, psr.Is_Primary,
                       s.NameEn as Student_Name, s.NameAr as Student_NameAr, s.Student_Code
                FROM parent_student_relationship psr
                INNER JOIN parent p ON psr.Parent_ID = p.Parent_ID
                INNER JOIN student s ON psr.Student_ID = s.Student_ID
                WHERE psr.Student_ID IN ($studentPlaceholders)
                ORDER BY p.NameEn ASC
            ");
            $stmt->execute($studentIds);
            $assignedParents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching assigned students/parents: " . $e->getMessage());
    $assignedStudents = [];
    $assignedParents = [];
}

$unreadCounts = [];
$allUserIds = array_merge(
    array_map(function($s) { return ['type' => 'student', 'id' => $s['Student_ID']]; }, $assignedStudents),
    array_map(function($p) { return ['type' => 'parent', 'id' => $p['Parent_ID']]; }, $assignedParents)
);

if (!empty($allUserIds)) {
    try {
        foreach ($allUserIds as $user) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as unread_count
                FROM message m
                INNER JOIN conversation conv ON m.Conversation_ID = conv.Conversation_ID
                WHERE m.Sender_Type = ? AND m.Sender_ID = ?
                AND m.Receiver_Type = 'teacher' AND m.Receiver_ID = ?
                AND m.Is_Read = 0
            ");
            $stmt->execute([$user['type'], $user['id'], $currentTeacherId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && $result['unread_count'] > 0) {
                $unreadCounts[$user['type'] . '_' . $user['id']] = intval($result['unread_count']);
            }
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
    <title>Parent Chat - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        .chat-container {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 2rem;
            height: calc(100vh - 250px);
            min-height: 600px;
        }
        .chat-list {
            background: white;
            border-radius: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .chat-list-header {
            background: linear-gradient(135deg, #FF6B9D, #C44569);
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .chat-list-content {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
        }
        .chat-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: #FFF9F5;
            border-radius: 15px;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.3s;
            border: 3px solid transparent;
        }
        .chat-item:hover {
            background: #FFE5E5;
            transform: translateX(5px);
        }
        .chat-item.active {
            background: linear-gradient(135deg, #FFE5E5, #E5F3FF);
            border-color: #FF6B9D;
        }
        .chat-avatar {
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
        .chat-info {
            flex: 1;
            min-width: 0;
        }
        .chat-name {
            font-weight: 700;
            margin-bottom: 0.2rem;
        }
        .chat-preview {
            font-size: 0.85rem;
            color: #666;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .chat-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.3rem;
        }
        .chat-time {
            font-size: 0.8rem;
            color: #999;
        }
        .chat-badge {
            background: #FF6B9D;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 700;
        }
        .chat-main {
            background: white;
            border-radius: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .chat-header {
            background: linear-gradient(135deg, #FF6B9D, #C44569);
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .chat-header-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .chat-messages {
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
        }
        .read-receipt {
            font-size: 0.7rem;
            margin-top: 0.3rem;
            opacity: 0.7;
        }
        .chat-input-area {
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
        .chat-actions {
            display: flex;
            gap: 0.5rem;
        }
        .chat-action-btn {
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
        .chat-action-btn:hover {
            transform: scale(1.1) rotate(5deg);
        }
        .quick-replies {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .quick-reply-btn {
            padding: 0.5rem 1rem;
            background: #E5F3FF;
            border: 2px solid #FFE5E5;
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.3s;
        }
        .quick-reply-btn:hover {
            background: #FFE5E5;
            border-color: #FF6B9D;
        }
        .attachment-preview {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            flex-wrap: wrap;
        }
        .attachment-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            background: #FFF9F5;
            border-radius: 10px;
            font-size: 0.85rem;
        }
        .chat-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1999;
        }
        
        .chat-overlay.active {
            display: block;
        }
        
        @media (max-width: 1024px) {
            .chat-container {
                grid-template-columns: 1fr;
                gap: 1rem;
                height: calc(100vh - 200px);
            }
            .chat-list {
                display: none;
                position: fixed;
                left: -100%;
                top: 0;
                width: 85%;
                max-width: 350px;
                height: 100vh;
                z-index: 2000;
                transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            }
            .chat-list.mobile-active {
                display: flex;
                left: 0;
            }
            .chat-overlay.active {
                display: block;
            }
            .chat-main {
                position: relative;
            }
            .message {
                max-width: 85%;
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }
            .chat-input-area {
                padding: 1rem;
            }
            .chat-input {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }
            .chat-header {
                padding: 1rem;
            }
            .chat-messages {
                padding: 1rem;
            }
        }
        
        @media (max-width: 480px) {
            .chat-container {
                height: calc(100vh - 180px);
            }
            .chat-list.mobile-active {
                width: 100vw;
                max-width: 100vw;
            }
            .message {
                max-width: 90%;
                padding: 0.6rem 0.8rem;
                font-size: 0.85rem;
            }
            .message-header {
                font-size: 0.75rem;
            }
            .chat-input-area {
                padding: 0.75rem;
                flex-wrap: wrap;
            }
            .chat-action-btn {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }
            .chat-item {
                padding: 0.75rem;
            }
            .chat-list-header {
                padding: 1rem;
            }
            .chat-list-content {
                padding: 0.5rem;
            }
        }

        .mobile-chat-toggle {
            display: none;
        }
        
        @media (max-width: 1024px) {
            .mobile-chat-toggle {
                display: block;
                position: fixed;
                bottom: 20px;
                right: 20px;
                background: linear-gradient(135deg, #FF6B9D, #C44569);
                color: white;
                border: none;
                width: 60px;
                height: 60px;
                border-radius: 50%;
                font-size: 1.5rem;
                cursor: pointer;
                z-index: 1999;
                box-shadow: 0 4px 15px rgba(0,0,0,0.3);
                transition: all 0.3s;
            }
            .mobile-chat-toggle:hover {
                transform: scale(1.1);
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
        
        @media (max-width: 1024px) {
            .need-help-box {
                bottom: 90px; 
                left: 15px;
                padding: 0.8rem 1.2rem;
                font-size: 0.9rem;
            }
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

    <div id="notificationContainer"></div>

    <div class="dashboard-container">
        
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">💬</span>
                <span data-en="Chat with Students & Parents" data-ar="الدردشة مع الطلاب وأولياء الأمور">Chat with Students & Parents</span>
            </h1>
            <p class="page-subtitle" data-en="Communicate with students and parents from your assigned classes" data-ar="التواصل مع الطلاب وأولياء الأمور من فصولك المعينة">Communicate with students and parents from your assigned classes</p>
        </div>

        <div class="chat-container">
            
            <div class="chat-overlay" id="chatOverlay" onclick="toggleChatList()"></div>

            <div class="chat-list" id="chatList">
                <div class="chat-list-header">
                    <h3 data-en="Conversations" data-ar="المحادثات">Conversations</h3>
                    <button class="close-sidebar-btn" onclick="toggleChatList()" id="closeChatListBtn" style="display: none; background: rgba(255,255,255,0.2); color: white; border: none; width: 35px; height: 35px; border-radius: 50%; cursor: pointer;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div style="padding: 1rem; border-bottom: 2px solid #FFE5E5;">
                    <select id="roleFilter" onchange="filterChats()" style="width: 100%; padding: 0.75rem; border: 2px solid #FFE5E5; border-radius: 10px; font-weight: 600;">
                        <option value="all" data-en="All (Students & Parents)" data-ar="الكل (طلاب وأولياء أمور)">All (Students & Parents)</option>
                        <option value="student" data-en="Students Only" data-ar="الطلاب فقط">Students Only</option>
                        <option value="parent" data-en="Parents Only" data-ar="أولياء الأمور فقط">Parents Only</option>
                    </select>
                </div>
                <div class="search-filter-bar" style="padding: 1rem; margin: 0;">
                    <div class="search-box" style="margin: 0;">
                        <i class="fas fa-search"></i>
                        <input type="text" id="chatSearch" placeholder="Search..." data-placeholder-en="Search..." data-placeholder-ar="بحث..." oninput="filterChats()">
                    </div>
                </div>
                <div class="chat-list-content" id="chatListContent">
                    <?php if (empty($assignedStudents) && empty($assignedParents)): ?>
                        <div style="text-align: center; padding: 2rem; color: #666;">
                            <div style="font-size: 3rem; margin-bottom: 1rem;">💬</div>
                            <div data-en="No conversations available" data-ar="لا توجد محادثات متاحة">No conversations available</div>
                            <div style="font-size: 0.9rem; margin-top: 0.5rem;" data-en="Students and parents will appear here once assigned to your classes" data-ar="سيظهر الطلاب وأولياء الأمور هنا بمجرد تعيينهم لفصولك">Students and parents will appear here once assigned to your classes</div>
                        </div>
                    <?php else: ?>
                        
                        <?php if (!empty($assignedStudents)): ?>
                            <div style="padding: 0.75rem 1rem; background: #FFF9F5; font-weight: 700; color: #FF6B9D; border-bottom: 2px solid #FFE5E5;" data-en="Students" data-ar="الطلاب">Students</div>
                            <?php foreach ($assignedStudents as $student): 
                                $unreadCount = $unreadCounts['student_' . $student['Student_ID']] ?? 0;
                            ?>
                                <div class="chat-item" data-role="student" data-user-id="<?php echo $student['Student_ID']; ?>" data-name="<?php echo strtolower($student['NameEn'] ?? $student['NameAr'] ?? ''); ?>" onclick="selectChat('student', <?php echo $student['Student_ID']; ?>, '<?php echo htmlspecialchars($student['NameEn'] ?? $student['NameAr'] ?? 'Student'); ?>', null, this)">
                                    <div class="chat-avatar">👨‍🎓</div>
                                    <div class="chat-info">
                                        <div class="chat-name"><?php echo htmlspecialchars($student['NameEn'] ?? $student['NameAr'] ?? 'Student'); ?></div>
                                        <div class="chat-preview"><?php echo htmlspecialchars($student['Student_Code']); ?>
                                            <?php if ($student['Class_Name']): ?>
                                                • <?php echo htmlspecialchars($student['Class_Name']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($unreadCount > 0): ?>
                                        <div class="chat-badge"><?php echo $unreadCount; ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($assignedParents)): ?>
                            <div style="padding: 0.75rem 1rem; background: #FFF9F5; font-weight: 700; color: #FF6B9D; border-bottom: 2px solid #FFE5E5; margin-top: 1rem;" data-en="Parents" data-ar="أولياء الأمور">Parents</div>
                            <?php 
                            
                            $uniqueParents = [];
                            foreach ($assignedParents as $parent) {
                                $parentId = $parent['Parent_ID'];
                                if (!isset($uniqueParents[$parentId])) {
                                    $uniqueParents[$parentId] = $parent;
                                    $uniqueParents[$parentId]['students'] = [];
                                }
                                $uniqueParents[$parentId]['students'][] = $parent['Student_Name'] ?? $parent['Student_NameAr'] ?? 'Student';
                            }
                            foreach ($uniqueParents as $parent): 
                                $unreadCount = $unreadCounts['parent_' . $parent['Parent_ID']] ?? 0;
                                $studentsList = implode(', ', array_unique($parent['students']));
                            ?>
                                <div class="chat-item" data-role="parent" data-user-id="<?php echo $parent['Parent_ID']; ?>" data-name="<?php echo strtolower($parent['NameEn'] ?? $parent['NameAr'] ?? ''); ?>" onclick="selectChat('parent', <?php echo $parent['Parent_ID']; ?>, '<?php echo htmlspecialchars($parent['NameEn'] ?? $parent['NameAr'] ?? 'Parent'); ?>', '<?php echo htmlspecialchars($studentsList); ?>', this)">
                                    <div class="chat-avatar">👨‍👩‍👧</div>
                                    <div class="chat-info">
                                        <div class="chat-name"><?php echo htmlspecialchars($parent['NameEn'] ?? $parent['NameAr'] ?? 'Parent'); ?></div>
                                        <div class="chat-preview"><?php echo htmlspecialchars($studentsList); ?></div>
                                    </div>
                                    <?php if ($unreadCount > 0): ?>
                                        <div class="chat-badge"><?php echo $unreadCount; ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="chat-main">
                <?php if (empty($assignedStudents) && empty($assignedParents)): ?>
                    <div style="display: flex; align-items: center; justify-content: center; height: 100%; text-align: center; color: #666;">
                        <div>
                            <div style="font-size: 4rem; margin-bottom: 1rem;">💬</div>
                            <div style="font-size: 1.2rem; font-weight: 700; margin-bottom: 0.5rem;" data-en="No Conversations Available" data-ar="لا توجد محادثات متاحة">No Conversations Available</div>
                            <div data-en="Select a student or parent from the sidebar to start chatting" data-ar="اختر طالب أو ولي أمر من الشريط الجانبي لبدء الدردشة">Select a student or parent from the sidebar to start chatting</div>
                        </div>
                    </div>
                <?php else: ?>
                <div class="chat-header">
                    <div class="chat-header-info" id="activeChatInfo">
                        <button class="mobile-chat-toggle" onclick="toggleChatList()" id="openChatListBtn" style="display: none; background: rgba(255,255,255,0.2); color: white; border: none; width: 35px; height: 35px; border-radius: 50%; cursor: pointer; margin-right: 0.5rem;">
                            <i class="fas fa-bars"></i>
                        </button>
                        <div class="chat-avatar" id="activeChatAvatar">👨</div>
                        <div>
                            <div style="font-weight: 700; font-size: 1.2rem;" id="activeChatName" data-en="Select a conversation" data-ar="اختر محادثة">Select a conversation</div>
                            <div style="font-size: 0.9rem; opacity: 0.9;" id="activeChatStudent">-</div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 0.5rem;">
                        <button class="chat-action-btn" onclick="showQuickReplies()" title="Quick Replies">
                            <i class="fas fa-comment-dots"></i>
                        </button>
                    </div>
                </div>
                <div class="chat-messages" id="chatMessages">
                    <div style="text-align: center; color: #999; padding: 2rem;" data-en="Select a conversation to start chatting" data-ar="اختر محادثة لبدء الدردشة">Select a conversation to start chatting</div>
                </div>
                <div class="chat-input-area" id="chatInputArea" style="display: none;">
                    <div id="quickRepliesContainer" class="quick-replies" style="display: none;">
                        
                    </div>
                    <div id="attachmentPreview" class="attachment-preview"></div>
                    <input type="file" id="fileInput" multiple style="display: none;" onchange="handleFileSelect(event)">
                    <button class="chat-action-btn" onclick="document.getElementById('fileInput').click()" title="Attach File">
                        <i class="fas fa-paperclip"></i>
                    </button>
                    <input type="text" class="chat-input" id="messageInput" placeholder="Type your message..." data-placeholder-en="Type your message..." data-placeholder-ar="اكتب رسالتك..." onkeypress="handleMessageKeyPress(event)">
                    <button class="chat-action-btn" onclick="sendMessage()" title="Send">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal" id="newChatModal" role="dialog">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2 class="modal-title" data-en="Start New Conversation" data-ar="بدء محادثة جديدة">Start New Conversation</h2>
                <button class="modal-close" onclick="closeModal('newChatModal')">&times;</button>
            </div>
            <form onsubmit="startNewChat(event)">
                <div class="form-group">
                    <label data-en="Recipient" data-ar="المستلم">Recipient</label>
                    <select id="recipientType" onchange="updateRecipientOptions()" required>
                        <option value="single" data-en="Single Parent" data-ar="ولي أمر واحد">Single Parent</option>
                        <option value="all" data-en="All Parents in Class" data-ar="جميع أولياء الأمور في الفصل">All Parents in Class</option>
                    </select>
                </div>
                <div class="form-group" id="parentSelectGroup">
                    <label data-en="Select Parent" data-ar="اختر ولي الأمر">Select Parent</label>
                    <select id="parentSelect">
                        
                    </select>
                </div>
                <div class="form-group" id="classSelectGroup" style="display: none;">
                    <label data-en="Select Class" data-ar="اختر الفصل">Select Class</label>
                    <select id="classSelect">
                        <option value="5a" data-en="Grade 5 - Class A" data-ar="الصف الخامس - الفصل أ">Grade 5 - Class A</option>
                        <option value="5b" data-en="Grade 5 - Class B" data-ar="الصف الخامس - الفصل ب">Grade 5 - Class B</option>
                    </select>
                </div>
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" data-en="Start Chat" data-ar="بدء الدردشة">Start Chat</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('newChatModal')" data-en="Cancel" data-ar="إلغاء">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        const currentTeacherId = <?php echo $currentTeacherId; ?>;
        let currentChatRole = null; 
        let currentChatId = null;
        let currentChatName = null;
        let currentChatStudent = null;
        let attachments = [];
        
        const quickReplyTemplates = [
            { en: 'Thank you for your message!', ar: 'شكراً على رسالتك!' },
            { en: 'I will check and get back to you soon.', ar: 'سأتحقق وأعود إليك قريباً.' },
            { en: 'Your child is doing excellent work!', ar: 'طفلك يقوم بعمل ممتاز!' },
            { en: 'Please remind your child to bring their homework.', ar: 'يرجى تذكير طفلك بإحضار الواجب.' },
            { en: 'I would like to schedule a meeting to discuss progress.', ar: 'أود تحديد موعد لمناقشة التقدم.' }
        ];
        
        function filterChats() {
            const roleFilter = document.getElementById('roleFilter').value;
            const searchTerm = document.getElementById('chatSearch').value.toLowerCase();
            const items = document.querySelectorAll('.chat-item');
            
            items.forEach(item => {
                const role = item.getAttribute('data-role');
                const name = item.getAttribute('data-name') || '';
                
                const matchesRole = roleFilter === 'all' || role === roleFilter;
                const matchesSearch = !searchTerm || name.includes(searchTerm);
                
                item.style.display = (matchesRole && matchesSearch) ? '' : 'none';
            });
        }
        
        function selectChat(role, userId, userName, studentName = null, clickedElement = null) {
            currentChatRole = role;
            currentChatId = userId;
            currentChatName = userName;
            currentChatStudent = studentName;

            document.querySelectorAll('.chat-item').forEach(item => {
                item.classList.remove('active');
            });

            if (clickedElement) {
                clickedElement.classList.add('active');
            } else {
                
                const item = document.querySelector(`.chat-item[data-role="${role}"][data-user-id="${userId}"]`);
                if (item) item.classList.add('active');
            }

            const activeChatNameEl = document.getElementById('activeChatName');
            const activeChatStudentEl = document.getElementById('activeChatStudent');
            const activeChatAvatarEl = document.getElementById('activeChatAvatar');
            
            if (activeChatNameEl) activeChatNameEl.textContent = userName;
            if (activeChatStudentEl) activeChatStudentEl.textContent = studentName || '-';
            if (activeChatAvatarEl) activeChatAvatarEl.textContent = role === 'student' ? '👨‍🎓' : '👨‍👩‍👧';
            
            const chatInputArea = document.getElementById('chatInputArea');
            if (chatInputArea) {
                chatInputArea.style.display = 'flex';
            }

            loadMessages(role, userId);

            markMessagesAsRead(role, userId);

            if (window.innerWidth <= 1024) {
                const chatList = document.getElementById('chatList');
                if (chatList) chatList.classList.remove('mobile-active');
            }
        }
        
        function loadMessages(role, userId) {
            const container = document.getElementById('chatMessages');
            container.innerHTML = '<div style="text-align: center; padding: 2rem; color: #999;">Loading messages...</div>';
            
            fetch(`chat-ajax.php?action=getMessages&role=${role}&user_id=${userId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderMessages(data.messages);
                    } else {
                        container.innerHTML = '<div style="text-align: center; padding: 2rem; color: #999;"><div data-en="No messages yet. Start the conversation!" data-ar="لا توجد رسائل بعد. ابدأ المحادثة!">No messages yet. Start the conversation!</div></div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading messages:', error);
                    container.innerHTML = '<div style="text-align: center; padding: 2rem; color: #FF6B9D;">Error loading messages. Please try again.</div>';
                });
        }
        
        function renderMessages(messages) {
            const container = document.getElementById('chatMessages');
            
            if (messages.length === 0) {
                container.innerHTML = '<div style="text-align: center; padding: 2rem; color: #999;"><div data-en="No messages yet. Start the conversation!" data-ar="لا توجد رسائل بعد. ابدأ المحادثة!">No messages yet. Start the conversation!</div></div>';
                return;
            }
            
            container.innerHTML = messages.map(msg => {
                const isSent = msg.Sender_Type === 'teacher' && msg.Sender_ID == currentTeacherId;
                const senderName = isSent ? (currentLanguage === 'en' ? 'You' : 'أنت') : currentChatName;
                const msgDate = new Date(msg.Created_At);
                const timeStr = formatMessageTime(msgDate);
                
                return `
                    <div class="message ${isSent ? 'sent' : 'received'}">
                        <div class="message-header">
                            <span>${senderName}</span>
                            <span class="message-time">${timeStr}</span>
                        </div>
                        <div class="message-content">${escapeHtml(msg.Message_Text)}</div>
                        ${isSent && msg.Is_Read ? `<div class="read-receipt">✓ Read</div>` : ''}
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
            if (!currentChatRole || !currentChatId) return;
            
            const input = document.getElementById('messageInput');
            const content = input.value.trim();
            if (!content) return;

            input.disabled = true;
            const sendBtn = document.querySelector('.chat-action-btn[onclick="sendMessage()"]');
            if (sendBtn) sendBtn.disabled = true;

            const container = document.getElementById('chatMessages');
            const messageDiv = document.createElement('div');
            messageDiv.className = 'message sent';
            messageDiv.innerHTML = `
                <div class="message-header">
                    <span>${currentLanguage === 'en' ? 'You' : 'أنت'}</span>
                    <span class="message-time">${currentLanguage === 'en' ? 'Sending...' : 'جاري الإرسال...'}</span>
                </div>
                <div class="message-content">${escapeHtml(content)}</div>
            `;
            container.appendChild(messageDiv);
            container.scrollTop = container.scrollHeight;

            const formData = new FormData();
            formData.append('action', 'sendMessage');
            formData.append('role', currentChatRole);
            formData.append('user_id', currentChatId);
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
                    
                    setTimeout(() => loadMessages(currentChatRole, currentChatId), 500);
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
        
        function markMessagesAsRead(role, userId) {
            fetch('chat-ajax.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=markAsRead&role=${role}&user_id=${userId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    
                    const chatItem = document.querySelector(`.chat-item[data-role="${role}"][onclick*="${userId}"]`);
                    if (chatItem) {
                        const badge = chatItem.querySelector('.chat-badge');
                        if (badge) badge.style.display = 'none';
                    }
                }
            })
            .catch(error => console.error('Error marking as read:', error));
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

        function toggleChatList() {
            const chatList = document.getElementById('chatList');
            const overlay = document.getElementById('chatOverlay');
            if (chatList) {
                const isActive = chatList.classList.toggle('mobile-active');
                if (overlay) {
                    overlay.classList.toggle('active', isActive);
                }
            }
        }

        function updateMobileButtons() {
            const openBtn = document.getElementById('openChatListBtn');
            const closeBtn = document.getElementById('closeChatListBtn');
            if (window.innerWidth <= 1024) {
                if (openBtn) openBtn.style.display = 'flex';
                if (closeBtn) closeBtn.style.display = 'flex';
            } else {
                if (openBtn) openBtn.style.display = 'none';
                if (closeBtn) closeBtn.style.display = 'none';
            }
        }

        window.addEventListener('load', updateMobileButtons);
        window.addEventListener('resize', updateMobileButtons);

        setInterval(() => {
            if (currentChatRole && currentChatId) {
                loadMessages(currentChatRole, currentChatId);
            }
        }, 5000);

        function handleMessageKeyPress(event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                sendMessage();
            }
        }

        function handleFileSelect(event) {
            const files = Array.from(event.target.files);
            attachments = [...attachments, ...files];
            renderAttachments();
        }

        function renderAttachments() {
            const container = document.getElementById('attachmentPreview');
            container.innerHTML = attachments.map((file, index) => `
                <div class="attachment-item">
                    <i class="fas fa-file"></i>
                    <span>${file.name}</span>
                    <button onclick="removeAttachment(${index})" style="background: none; border: none; color: #FF6B9D; cursor: pointer;">&times;</button>
                </div>
            `).join('');
        }

        function removeAttachment(index) {
            attachments.splice(index, 1);
            renderAttachments();
        }

        function openNewChatModal() {
            updateRecipientOptions();
            openModal('newChatModal');
        }

        function updateRecipientOptions() {
            const type = document.getElementById('recipientType').value;
            const parentGroup = document.getElementById('parentSelectGroup');
            const classGroup = document.getElementById('classSelectGroup');
            
            if (type === 'single') {
                parentGroup.style.display = 'block';
                classGroup.style.display = 'none';
                
                const parentSelect = document.getElementById('parentSelect');
                parentSelect.innerHTML = mockChats.map(chat => 
                    `<option value="${chat.id}">${chat.parentName} (${chat.studentName})</option>`
                ).join('');
            } else {
                parentGroup.style.display = 'none';
                classGroup.style.display = 'block';
            }
        }

        function startNewChat(event) {
            event.preventDefault();
            const type = document.getElementById('recipientType').value;

            if (type === 'single') {
                const parentId = document.getElementById('parentSelect').value;
                const chat = mockChats.find(c => c.id === parseInt(parentId));
                if (chat) {
                    selectChat(chat.id);
                }
            } else {
                showNotification(currentLanguage === 'en' ? 'Group chat started!' : 'تم بدء محادثة جماعية!', 'success');
            }
            
            closeModal('newChatModal');
        }

        function showQuickReplies() {
            const container = document.getElementById('quickRepliesContainer');
            if (container.style.display === 'none' || !container.style.display) {
                container.style.display = 'flex';
                container.innerHTML = quickReplyTemplates.map((template, index) => `
                    <button type="button" class="quick-reply-btn" onclick="useQuickReply(${index})">
                        ${currentLanguage === 'en' ? template.en : template.ar}
                    </button>
                `).join('');
            } else {
                container.style.display = 'none';
            }
        }

        function useQuickReply(index) {
            const template = quickReplyTemplates[index];
            document.getElementById('messageInput').value = currentLanguage === 'en' ? template.en : template.ar;
            document.getElementById('quickRepliesContainer').style.display = 'none';
            document.getElementById('messageInput').focus();
        }

        function filterChats() {
            const roleFilter = document.getElementById('roleFilter').value;
            const searchTerm = document.getElementById('chatSearch').value.toLowerCase();
            const items = document.querySelectorAll('.chat-item');
            
            items.forEach(item => {
                const role = item.getAttribute('data-role');
                const name = item.getAttribute('data-name') || '';
                
                const matchesRole = roleFilter === 'all' || role === roleFilter;
                const matchesSearch = !searchTerm || name.includes(searchTerm);
                
                item.style.display = (matchesRole && matchesSearch) ? '' : 'none';
            });

            const sections = document.querySelectorAll('[data-en="Students"], [data-en="Parents"]');
            sections.forEach(section => {
                const sectionRole = section.textContent.includes('Students') ? 'student' : 'parent';
                const hasVisibleItems = Array.from(items).some(item => {
                    if (item.getAttribute('data-role') !== sectionRole) return false;
                    return item.style.display !== 'none';
                });
                section.style.display = (roleFilter === 'all' || roleFilter === sectionRole) && hasVisibleItems ? '' : 'none';
            });
        }

        const mockNotifications = [
            { id: 1, icon: '💬', title: 'New Message', message: 'Ahmed\'s parent sent a message', time: '2 hours ago', read: false },
            { id: 2, icon: '💬', title: 'New Message', message: 'Sara\'s parent replied', time: '5 hours ago', read: false },
            { id: 3, icon: '📝', title: 'Assignment Submitted', message: 'Ahmed submitted Mathematics homework', time: '1 day ago', read: false }
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
            window.location.href = 'notifications-and-settings.php#profile';
        }

        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('notificationsDropdown');
            const btn = event.target.closest('.header-nav-btn');
            if (dropdown && !dropdown.contains(event.target) && !btn) {
                dropdown.classList.remove('active');
            }
        });

        loadChats();
        updateNotificationBadge();

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

