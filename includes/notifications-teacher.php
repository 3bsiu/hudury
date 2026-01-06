<?php

if (!function_exists('getTimeAgo')) {
    function getTimeAgo($datetime) {
        $now = new DateTime();
        $diff = $now->diff($datetime);
        
        if ($diff->y > 0) {
            return $diff->y . ' ' . ($diff->y == 1 ? 'year' : 'years') . ' ago';
        } elseif ($diff->m > 0) {
            return $diff->m . ' ' . ($diff->m == 1 ? 'month' : 'months') . ' ago';
        } elseif ($diff->d > 0) {
            return $diff->d . ' ' . ($diff->d == 1 ? 'day' : 'days') . ' ago';
        } elseif ($diff->h > 0) {
            return $diff->h . ' ' . ($diff->h == 1 ? 'hour' : 'hours') . ' ago';
        } elseif ($diff->i > 0) {
            return $diff->i . ' ' . ($diff->i == 1 ? 'minute' : 'minutes') . ' ago';
        } else {
            return 'Just now';
        }
    }
}

$currentTeacherId = isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'teacher' ? intval($_SESSION['user_id']) : null;

$notifications = [];
try {
    $query = "
        SELECT Notif_ID, Title, Content, Date_Sent, Sender_Type, Target_Role, Target_Class_ID, Target_Student_ID
        FROM notification
        WHERE (Target_Role = 'All' OR Target_Role = 'Teacher')
        ORDER BY Date_Sent DESC
        LIMIT 20
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching notifications for teachers: " . $e->getMessage());
    $notifications = [];
}
?>

