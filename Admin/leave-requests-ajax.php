<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('admin');

header('Content-Type: application/json');

$currentAdminId = getCurrentUserId();

if (!$currentAdminId) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$action = $_POST['action'] ?? '';

if ($action === 'approveRequest' || $action === 'rejectRequest') {
    try {
        $requestId = intval($_POST['request_id'] ?? 0);
        $reviewNotes = $_POST['review_notes'] ?? '';
        
        if (!$requestId) {
            echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
            exit();
        }

        $stmt = $pdo->prepare("SELECT * FROM leave_request WHERE Leave_Request_ID = ?");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            echo json_encode(['success' => false, 'message' => 'Leave request not found']);
            exit();
        }
        
        if ($request['Status'] !== 'pending') {
            echo json_encode(['success' => false, 'message' => 'Request has already been processed']);
            exit();
        }

        $newStatus = $action === 'approveRequest' ? 'approved' : 'rejected';
        
        $stmt = $pdo->prepare("
            UPDATE leave_request 
            SET Status = ?, 
                Reviewed_By = ?, 
                Reviewed_At = NOW(), 
                Review_Notes = ?
            WHERE Leave_Request_ID = ?
        ");
        
        $stmt->execute([
            $newStatus,
            $currentAdminId,
            $reviewNotes ?: null,
            $requestId
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => $action === 'approveRequest' ? 'Request approved successfully!' : 'Request rejected.'
        ]);
        
    } catch (PDOException $e) {
        error_log("Error processing leave request: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        error_log("Error processing leave request: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>

