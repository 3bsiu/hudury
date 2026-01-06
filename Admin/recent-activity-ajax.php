<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('admin');

header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("
        SELECT 
            Log_ID,
            User_Type,
            User_ID,
            COALESCE(User_Name, 'Admin') as user_name,
            Action as action,
            COALESCE(Category, 'other') as category,
            Description as description,
            Table_Name,
            Record_ID,
            IP_Address,
            Created_At as created_at
        FROM activity_log
        WHERE User_Type = 'admin'
        ORDER BY Created_At DESC
        LIMIT 500
    ");
    
    $stmt->execute();
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'activities' => $activities
    ]);
    
} catch (PDOException $e) {
    error_log("Error fetching activities: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Error: ' . $e->getMessage()
    ]);
}
?>

