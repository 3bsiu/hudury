<?php

function handleContactFormSubmission() {
    require_once __DIR__ . '/../db.php';
    
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (empty($name) || empty($email) || empty($subject) || empty($message)) {
        return false;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    $phone = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');
    $subject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
    $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    
    try {
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            return false;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO contact_submission 
            (Name, Email, Phone, Subject, Message, Status, Created_At) 
            VALUES (?, ?, ?, ?, ?, 'new', NOW())
        ");
        
        $result = $stmt->execute([$name, $email, $phone, $subject, $message]);
        
        if ($result) {
            $submissionId = $pdo->lastInsertId();
            return $submissionId > 0;
        }
        
        return false;
    } catch (PDOException $e) {
        error_log("Contact form error: " . $e->getMessage());
        return false;
    } catch (Exception $e) {
        error_log("Contact form error: " . $e->getMessage());
        return false;
    }
}
?>

