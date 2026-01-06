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

$currentStudentId = isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student' ? intval($_SESSION['user_id']) : null;
$currentStudentClassId = null;

if ($currentStudentId) {
    try {
        $stmt = $pdo->prepare("SELECT Class_ID FROM student WHERE Student_ID = ?");
        $stmt->execute([$currentStudentId]);
        $studentData = $stmt->fetch(PDO::FETCH_ASSOC);
        $currentStudentClassId = $studentData['Class_ID'] ?? null;
    } catch (PDOException $e) {
        error_log("Error fetching student class: " . $e->getMessage());
    }
}

$notifications = [];
try {
    if ($currentStudentId) {

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
        $stmt->execute([$currentStudentId, $currentStudentClassId]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching notifications for students: " . $e->getMessage());
    $notifications = [];
}
?>

