<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('admin');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$action = $_GET['action'] ?? '';

if ($action === 'getInstallments') {
    try {
        $studentId = intval($_GET['studentId'] ?? 0);
        
        if ($studentId <= 0) {
            throw new Exception('Invalid student ID');
        }

        $stmt = $pdo->prepare("
            SELECT i.*,
                   COALESCE(SUM(ph.Amount), 0) as paid_amount
            FROM installment i
            LEFT JOIN payment_history ph ON i.Installment_ID = ph.Installment_ID
            WHERE i.Student_ID = ?
            GROUP BY i.Installment_ID, i.Installment_Number, i.Amount, i.Due_Date, i.Status, i.Notes, i.Student_ID
            ORDER BY i.Due_Date ASC, i.Installment_Number ASC
        ");
        $stmt->execute([$studentId]);
        $installments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'installments' => $installments
        ]);
    } catch (Exception $e) {
        error_log("Error fetching installments: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>



