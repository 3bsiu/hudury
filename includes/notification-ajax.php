<?php

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$currentUserId = getCurrentUserId();
$currentUserType = getCurrentUserType();

if (!$currentUserId || !$currentUserType) {
    echo json_encode(['success' => false, 'message' => 'Invalid user']);
    exit();
}

$action = $_POST['action'] ?? '';

if ($action === 'markAsRead') {
    $notifId = intval($_POST['notif_id'] ?? 0);
    
    if ($notifId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
        exit();
    }
    
    try {
        
        $stmt = $pdo->prepare("SELECT Notif_ID FROM notification WHERE Notif_ID = ?");
        $stmt->execute([$notifId]);
        $notification = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$notification) {
            echo json_encode(['success' => false, 'message' => 'Notification not found']);
            exit();
        }

        $stmt = $pdo->prepare("
            INSERT IGNORE INTO notification_read (Notif_ID, User_Type, User_ID, Read_At)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$notifId, $currentUserType, $currentUserId]);
        
        echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
        
    } catch (PDOException $e) {
        error_log("Error marking notification as read: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    
} elseif ($action === 'markAllAsRead') {
    $notifIds = $_POST['notif_ids'] ?? '';
    
    if (empty($notifIds)) {
        echo json_encode(['success' => false, 'message' => 'No notification IDs provided']);
        exit();
    }
    
    $notifIdArray = array_filter(array_map('intval', explode(',', $notifIds)));
    
    if (empty($notifIdArray)) {
        echo json_encode(['success' => false, 'message' => 'Invalid notification IDs']);
        exit();
    }
    
    try {
        $placeholders = implode(',', array_fill(0, count($notifIdArray), '?'));

        $stmt = $pdo->prepare("
            INSERT IGNORE INTO notification_read (Notif_ID, User_Type, User_ID, Read_At)
            SELECT Notif_ID, ?, ?, NOW()
            FROM notification
            WHERE Notif_ID IN ($placeholders)
        ");
        $params = array_merge([$currentUserType, $currentUserId], $notifIdArray);
        $stmt->execute($params);
        
        echo json_encode(['success' => true, 'message' => 'All notifications marked as read', 'count' => $stmt->rowCount()]);
        
    } catch (PDOException $e) {
        error_log("Error marking all notifications as read: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>

