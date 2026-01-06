<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('student');

header('Content-Type: application/json');

$currentStudentId = getCurrentUserId();

if (!$currentStudentId) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'getMessages') {
    try {
        $teacherId = intval($_GET['teacher_id'] ?? 0);
        
        if (!$teacherId) {
            echo json_encode(['success' => false, 'message' => 'Invalid teacher ID']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM teacher_class_course tcc
            INNER JOIN student s ON tcc.Class_ID = s.Class_ID
            WHERE tcc.Teacher_ID = ? AND s.Student_ID = ?
        ");
        $stmt->execute([$teacherId, $currentStudentId]);
        $relationship = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$relationship || $relationship['count'] == 0) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: Teacher not assigned to your class']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT Conversation_ID
            FROM conversation
            WHERE ((Participant_1_Type = 'student' AND Participant_1_ID = ? AND Participant_2_Type = 'teacher' AND Participant_2_ID = ?)
                OR (Participant_1_Type = 'teacher' AND Participant_1_ID = ? AND Participant_2_Type = 'student' AND Participant_2_ID = ?))
        ");
        $stmt->execute([$currentStudentId, $teacherId, $teacherId, $currentStudentId]);
        $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $conversationId = null;
        if ($conversation) {
            $conversationId = $conversation['Conversation_ID'];
        } else {
            
            $stmt = $pdo->prepare("
                INSERT INTO conversation (Participant_1_Type, Participant_1_ID, Participant_2_Type, Participant_2_ID, Created_At)
                VALUES ('student', ?, 'teacher', ?, NOW())
            ");
            $stmt->execute([$currentStudentId, $teacherId]);
            $conversationId = $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare("
            SELECT m.*, 
                   t.NameEn as Teacher_Name, t.NameAr as Teacher_NameAr,
                   s.NameEn as Student_Name, s.NameAr as Student_NameAr
            FROM message m
            LEFT JOIN teacher t ON (m.Sender_Type = 'teacher' AND m.Sender_ID = t.Teacher_ID) OR (m.Receiver_Type = 'teacher' AND m.Receiver_ID = t.Teacher_ID)
            LEFT JOIN student s ON (m.Sender_Type = 'student' AND m.Sender_ID = s.Student_ID) OR (m.Receiver_Type = 'student' AND m.Receiver_ID = s.Student_ID)
            WHERE m.Conversation_ID = ?
            ORDER BY m.Created_At ASC
        ");
        $stmt->execute([$conversationId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'messages' => $messages,
            'conversation_id' => $conversationId
        ]);
        
    } catch (PDOException $e) {
        error_log("Error fetching messages: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} elseif ($action === 'sendMessage') {
    try {
        $teacherId = intval($_POST['teacher_id'] ?? 0);
        $messageText = trim($_POST['message'] ?? '');
        
        if (!$teacherId || !$messageText) {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM teacher_class_course tcc
            INNER JOIN student s ON tcc.Class_ID = s.Class_ID
            WHERE tcc.Teacher_ID = ? AND s.Student_ID = ?
        ");
        $stmt->execute([$teacherId, $currentStudentId]);
        $relationship = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$relationship || $relationship['count'] == 0) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: Teacher not assigned to your class']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT Conversation_ID
            FROM conversation
            WHERE ((Participant_1_Type = 'student' AND Participant_1_ID = ? AND Participant_2_Type = 'teacher' AND Participant_2_ID = ?)
                OR (Participant_1_Type = 'teacher' AND Participant_1_ID = ? AND Participant_2_Type = 'student' AND Participant_2_ID = ?))
        ");
        $stmt->execute([$currentStudentId, $teacherId, $teacherId, $currentStudentId]);
        $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $conversationId = null;
        if ($conversation) {
            $conversationId = $conversation['Conversation_ID'];
        } else {
            
            $stmt = $pdo->prepare("
                INSERT INTO conversation (Participant_1_Type, Participant_1_ID, Participant_2_Type, Participant_2_ID, Created_At)
                VALUES ('student', ?, 'teacher', ?, NOW())
            ");
            $stmt->execute([$currentStudentId, $teacherId]);
            $conversationId = $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare("
            INSERT INTO message (Conversation_ID, Sender_Type, Sender_ID, Receiver_Type, Receiver_ID, Message_Text, Is_Read, Created_At)
            VALUES (?, 'student', ?, 'teacher', ?, ?, 0, NOW())
        ");
        $stmt->execute([$conversationId, $currentStudentId, $teacherId, $messageText]);

        $stmt = $pdo->prepare("
            UPDATE conversation SET Last_Message_At = NOW() WHERE Conversation_ID = ?
        ");
        $stmt->execute([$conversationId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Message sent successfully'
        ]);
        
    } catch (PDOException $e) {
        error_log("Error sending message: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} elseif ($action === 'markAsRead') {
    try {
        $teacherId = intval($_POST['teacher_id'] ?? 0);
        
        if (!$teacherId) {
            echo json_encode(['success' => false, 'message' => 'Invalid teacher ID']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT Conversation_ID
            FROM conversation
            WHERE ((Participant_1_Type = 'student' AND Participant_1_ID = ? AND Participant_2_Type = 'teacher' AND Participant_2_ID = ?)
                OR (Participant_1_Type = 'teacher' AND Participant_1_ID = ? AND Participant_2_Type = 'student' AND Participant_2_ID = ?))
        ");
        $stmt->execute([$currentStudentId, $teacherId, $teacherId, $currentStudentId]);
        $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($conversation) {
            
            $stmt = $pdo->prepare("
                UPDATE message 
                SET Is_Read = 1, Read_At = NOW()
                WHERE Conversation_ID = ? 
                AND Sender_Type = 'teacher' 
                AND Sender_ID = ?
                AND Receiver_Type = 'student'
                AND Receiver_ID = ?
                AND Is_Read = 0
            ");
            $stmt->execute([$conversation['Conversation_ID'], $teacherId, $currentStudentId]);
        }
        
        echo json_encode(['success' => true]);
        
    } catch (PDOException $e) {
        error_log("Error marking as read: " . $e->getMessage());
        echo json_encode(['success' => false]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>

