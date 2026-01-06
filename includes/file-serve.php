<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';

$type = $_GET['type'] ?? '';
$id = intval($_GET['id'] ?? 0);

if (!$type || !$id) {
    http_response_code(400);
    die('Invalid request');
}

try {
    
    requireLogin();
    
    $currentUserId = getCurrentUserId();
    $userType = $_SESSION['user_type'] ?? '';
    
    if ($type === 'submission') {
        
        if (!in_array($userType, ['student', 'teacher', 'admin'])) {
            http_response_code(403);
            die('Access denied');
        }

        if ($userType === 'student') {
            
            $stmt = $pdo->prepare("
                SELECT File_Data, File_MIME_Type, File_Name, Student_ID
                FROM submission
                WHERE Submission_ID = ? AND Student_ID = ?
            ");
            $stmt->execute([$id, $currentUserId]);
        } else {
            
            if ($userType === 'teacher') {
                $stmt = $pdo->prepare("
                    SELECT s.File_Data, s.File_MIME_Type, s.File_Name, s.Student_ID, a.Teacher_ID
                    FROM submission s
                    INNER JOIN assignment a ON s.Assignment_ID = a.Assignment_ID
                    WHERE s.Submission_ID = ? AND a.Teacher_ID = ?
                ");
                $stmt->execute([$id, $currentUserId]);
            } else {
                
                $stmt = $pdo->prepare("
                    SELECT File_Data, File_MIME_Type, File_Name, Student_ID
                    FROM submission
                    WHERE Submission_ID = ?
                ");
                $stmt->execute([$id]);
            }
        }
        
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$file || !$file['File_Data']) {
            http_response_code(404);
            die('File not found');
        }

        header('Content-Type: ' . ($file['File_MIME_Type'] ?? 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . htmlspecialchars($file['File_Name'] ?? 'file') . '"');
        header('Content-Length: ' . strlen($file['File_Data']));
        echo $file['File_Data'];
        
    } elseif ($type === 'leave_document') {
        
        if (!in_array($userType, ['parent', 'admin'])) {
            http_response_code(403);
            die('Access denied');
        }

        if ($userType === 'parent') {
            
            $stmt = $pdo->prepare("
                SELECT Document_Data, Document_MIME_Type, Leave_Request_ID, Parent_ID
                FROM leave_request
                WHERE Leave_Request_ID = ? AND Parent_ID = ?
            ");
            $stmt->execute([$id, $currentUserId]);
        } else {
            
            $stmt = $pdo->prepare("
                SELECT Document_Data, Document_MIME_Type, Leave_Request_ID, Parent_ID
                FROM leave_request
                WHERE Leave_Request_ID = ?
            ");
            $stmt->execute([$id]);
        }
        
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$document || !$document['Document_Data']) {
            http_response_code(404);
            die('Document not found');
        }

        header('Content-Type: ' . ($document['Document_MIME_Type'] ?? 'application/octet-stream'));
        header('Content-Disposition: inline; filename="leave_document_' . $id . '"');
        header('Content-Length: ' . strlen($document['Document_Data']));
        echo $document['Document_Data'];
        
    } else {
        http_response_code(400);
        die('Invalid file type');
    }
    
} catch (Exception $e) {
    error_log("Error serving file: " . $e->getMessage());
    http_response_code(500);
    die('Error serving file');
}

