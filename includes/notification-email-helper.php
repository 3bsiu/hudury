<?php

require_once __DIR__ . '/brevo-config.php';

function sendEmailViaBrevo($toEmail, $toName, $subject, $htmlContent) {
    
    $apiKey = defined('BREVO_API_KEY') ? BREVO_API_KEY : '';
    $senderEmail = defined('BREVO_SENDER_EMAIL') ? BREVO_SENDER_EMAIL : '';
    $senderName = defined('BREVO_SENDER_NAME') ? BREVO_SENDER_NAME : 'HUDURY School';

    if (empty($apiKey)) {
        error_log("Brevo API Error: API key constant is not defined");
        return ['success' => false, 'error' => 'Email service configuration error'];
    }
    
    if (empty($senderEmail)) {
        error_log("Brevo API Error: Sender email constant is not defined");
        return ['success' => false, 'error' => 'Email service configuration error'];
    }

    $apiKey = trim($apiKey);

    $url = 'https://api.brevo.com/v3/smtp/email';

    $data = [
        'sender' => [
            'name' => $senderName,
            'email' => $senderEmail
        ],
        'to' => [
            [
                'email' => $toEmail,
                'name' => $toName
            ]
        ],
        'subject' => $subject,
        'htmlContent' => $htmlContent
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $headers = [
        'Accept: application/json',
        'api-key: ' . $apiKey,
        'Content-Type: application/json'
    ];
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($curlError || $curlErrno) {
        error_log("Brevo API cURL Error (Code: $curlErrno): " . $curlError);
        return ['success' => false, 'error' => 'Failed to connect to email service'];
    }

    if ($httpCode !== 201 && $httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMessage = $errorData['message'] ?? 'Unknown error';
        error_log("Brevo API Error (HTTP $httpCode): " . $errorMessage);
        return ['success' => false, 'error' => $errorMessage];
    }
    
    return ['success' => true, 'error' => null];
}

function createNotification($pdo, $title, $content, $senderType, $senderId, $targetRole, $targetClassId = null, $targetStudentId = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notification (Title, Content, Date_Sent, Sender_Type, Sender_ID, Target_Role, Target_Class_ID, Target_Student_ID)
            VALUES (?, ?, NOW(), ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $title,
            $content,
            $senderType,
            $senderId,
            $targetRole,
            $targetClassId,
            $targetStudentId
        ]);
        
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Error creating notification: " . $e->getMessage());
        return false;
    }
}

function notifyParentOfAbsence($pdo, $studentId, $date, $classId, $teacherId, $teacherName) {
    $result = [
        'notifications_sent' => 0,
        'emails_sent' => 0,
        'errors' => []
    ];
    
    try {
        
        $stmt = $pdo->prepare("
            SELECT s.Student_ID, s.NameEn, s.NameAr, s.Student_Code,
                   c.Name as Class_Name
            FROM student s
            LEFT JOIN class c ON s.Class_ID = c.Class_ID
            WHERE s.Student_ID = ?
        ");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            $result['errors'][] = "Student not found (ID: $studentId)";
            return $result;
        }
        
        $studentName = $student['NameEn'] ?? $student['NameAr'] ?? 'Student';
        $className = $student['Class_Name'] ?? 'Unknown Class';
        $formattedDate = date('F j, Y', strtotime($date));

        $stmt = $pdo->prepare("
            SELECT p.Parent_ID, p.NameEn, p.NameAr, p.Email
            FROM parent_student_relationship psr
            INNER JOIN parent p ON psr.Parent_ID = p.Parent_ID
            WHERE psr.Student_ID = ?
        ");
        $stmt->execute([$studentId]);
        $parents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($parents)) {
            $result['errors'][] = "No parents found for student (ID: $studentId)";
            return $result;
        }

        foreach ($parents as $parent) {
            $parentId = $parent['Parent_ID'];
            $parentName = $parent['NameEn'] ?? $parent['NameAr'] ?? 'Parent';
            $parentEmail = $parent['Email'] ?? '';

            if (!empty($parentEmail) && filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
                $emailSubject = "Student Absence Notification - {$studentName}";
                $emailBody = "
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset='UTF-8'>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #FF6B9D; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                        .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 10px 10px; }
                        .info-row { margin: 10px 0; }
                        .label { font-weight: bold; color: #555; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h2>Student Absence Notification</h2>
                        </div>
                        <div class='content'>
                            <p>Dear {$parentName},</p>
                            <p>This is to inform you that your child has been marked as absent.</p>
                            <div class='info-row'>
                                <span class='label'>Student Name:</span> {$studentName}
                            </div>
                            <div class='info-row'>
                                <span class='label'>Date:</span> {$formattedDate}
                            </div>
                            <div class='info-row'>
                                <span class='label'>Class:</span> {$className}
                            </div>
                            <div class='info-row'>
                                <span class='label'>Recorded by:</span> {$teacherName}
                            </div>
                            <p style='margin-top: 20px;'>Please contact the school if you have any questions.</p>
                            <p>Best regards,<br>HUDURY School</p>
                        </div>
                    </div>
                </body>
                </html>
                ";
                
                $emailResult = sendEmailViaBrevo($parentEmail, $parentName, $emailSubject, $emailBody);
                if ($emailResult['success']) {
                    $result['emails_sent']++;
                } else {
                    $result['errors'][] = "Failed to send email to {$parentEmail}: " . ($emailResult['error'] ?? 'Unknown error');
                }
            }
        }
        
    } catch (PDOException $e) {
        error_log("Error in notifyParentOfAbsence: " . $e->getMessage());
        $result['errors'][] = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        error_log("Error in notifyParentOfAbsence: " . $e->getMessage());
        $result['errors'][] = "Error: " . $e->getMessage();
    }
    
    return $result;
}

function notifyClassOfAssignment($pdo, $assignmentId, $classId, $teacherId, $teacherName) {
    $result = [
        'notifications_sent' => 0,
        'emails_sent' => 0,
        'errors' => []
    ];
    
    try {
        
        $stmt = $pdo->prepare("
            SELECT a.Assignment_ID, a.Title, a.Description, a.Due_Date, a.Total_Marks,
                   c.Name as Class_Name,
                   co.Course_Name
            FROM assignment a
            LEFT JOIN class c ON a.Class_ID = c.Class_ID
            LEFT JOIN course co ON a.Course_ID = co.Course_ID
            WHERE a.Assignment_ID = ?
        ");
        $stmt->execute([$assignmentId]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$assignment) {
            $result['errors'][] = "Assignment not found (ID: $assignmentId)";
            return $result;
        }
        
        $assignmentTitle = $assignment['Title'];
        $courseName = $assignment['Course_Name'] ?? 'Unknown Subject';
        $className = $assignment['Class_Name'] ?? 'Unknown Class';
        $dueDate = date('F j, Y g:i A', strtotime($assignment['Due_Date']));
        $totalMarks = $assignment['Total_Marks'] ? " (Total: {$assignment['Total_Marks']} marks)" : "";

        $stmt = $pdo->prepare("
            SELECT s.Student_ID, s.NameEn, s.NameAr, s.Student_Code, s.Email
            FROM student s
            WHERE s.Class_ID = ? AND (s.Status = 'active' OR s.Status IS NULL)
        ");
        $stmt->execute([$classId]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($students)) {
            $result['errors'][] = "No students found in class (ID: $classId)";
            return $result;
        }

        $notificationTitle = "New Assignment: {$assignmentTitle}";
        $notificationContent = "A new assignment '{$assignmentTitle}' has been posted for {$courseName} in {$className}. Due date: {$dueDate}{$totalMarks}.";

        foreach ($students as $student) {
            $studentId = $student['Student_ID'];
            $studentName = $student['NameEn'] ?? $student['NameAr'] ?? 'Student';
            $studentEmail = $student['Email'] ?? '';

            $notifId = createNotification(
                $pdo,
                $notificationTitle,
                $notificationContent,
                'Teacher',
                $teacherId,
                'Student',
                $classId,
                $studentId
            );
            
            if ($notifId) {
                $result['notifications_sent']++;
            } else {
                $result['errors'][] = "Failed to create notification for student ID: $studentId";
            }

            if (!empty($studentEmail) && filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
                $emailSubject = "New Assignment: {$assignmentTitle}";
                $emailBody = "
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset='UTF-8'>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #6BCB77; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                        .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 10px 10px; }
                        .info-row { margin: 10px 0; }
                        .label { font-weight: bold; color: #555; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h2>New Assignment Posted</h2>
                        </div>
                        <div class='content'>
                            <p>Dear {$studentName},</p>
                            <p>A new assignment has been posted for you.</p>
                            <div class='info-row'>
                                <span class='label'>Assignment:</span> {$assignmentTitle}
                            </div>
                            <div class='info-row'>
                                <span class='label'>Subject:</span> {$courseName}
                            </div>
                            <div class='info-row'>
                                <span class='label'>Class:</span> {$className}
                            </div>
                            <div class='info-row'>
                                <span class='label'>Due Date:</span> {$dueDate}
                            </div>
                            " . ($assignment['Total_Marks'] ? "<div class='info-row'><span class='label'>Total Marks:</span> {$assignment['Total_Marks']}</div>" : "") . "
                            <div class='info-row'>
                                <span class='label'>Teacher:</span> {$teacherName}
                            </div>
                            " . (!empty($assignment['Description']) ? "<p style='margin-top: 15px;'><strong>Description:</strong><br>" . htmlspecialchars($assignment['Description']) . "</p>" : "") . "
                            <p style='margin-top: 20px;'>Please log in to your account to view the full assignment details and submit your work.</p>
                            <p>Best regards,<br>HUDURY School</p>
                        </div>
                    </div>
                </body>
                </html>
                ";
                
                $emailResult = sendEmailViaBrevo($studentEmail, $studentName, $emailSubject, $emailBody);
                if ($emailResult['success']) {
                    $result['emails_sent']++;
                } else {
                    $result['errors'][] = "Failed to send email to student {$studentEmail}: " . ($emailResult['error'] ?? 'Unknown error');
                }
            }
        }

        $stmt = $pdo->prepare("
            SELECT Notif_ID 
            FROM notification 
            WHERE Title = ? 
            AND Target_Role = 'Parent' 
            AND Target_Class_ID = ? 
            AND Sender_Type = 'Teacher' 
            AND Sender_ID = ?
            AND Date_Sent >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
            ORDER BY Date_Sent DESC
            LIMIT 1
        ");
        $stmt->execute([$notificationTitle, $classId, $teacherId]);
        $existingNotif = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingNotif) {
            
            $notifId = $existingNotif['Notif_ID'];
            $result['notifications_sent'] = 0; 
        } else {
            
            $notifId = createNotification(
                $pdo,
                $notificationTitle,
                $notificationContent,
                'Teacher',
                $teacherId,
                'Parent',
                $classId,
                null
            );
            
            if ($notifId) {
                $result['notifications_sent'] = 1; 
            } else {
                $result['errors'][] = "Failed to create notification for parents in class ID: $classId";
            }
        }

        $stmt = $pdo->prepare("
            SELECT DISTINCT p.Parent_ID, p.NameEn, p.NameAr, p.Email
            FROM parent_student_relationship psr
            INNER JOIN parent p ON psr.Parent_ID = p.Parent_ID
            INNER JOIN student s ON psr.Student_ID = s.Student_ID
            WHERE s.Class_ID = ?
        ");
        $stmt->execute([$classId]);
        $parents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($parents as $parent) {
            $parentId = $parent['Parent_ID'];
            $parentName = $parent['NameEn'] ?? $parent['NameAr'] ?? 'Parent';
            $parentEmail = $parent['Email'] ?? '';

            if (!empty($parentEmail) && filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
                $emailSubject = "New Assignment Posted for Your Child - {$assignmentTitle}";
                $emailBody = "
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset='UTF-8'>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #4A90E2; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                        .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 10px 10px; }
                        .info-row { margin: 10px 0; }
                        .label { font-weight: bold; color: #555; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h2>New Assignment Posted</h2>
                        </div>
                        <div class='content'>
                            <p>Dear {$parentName},</p>
                            <p>A new assignment has been posted for your child's class.</p>
                            <div class='info-row'>
                                <span class='label'>Assignment:</span> {$assignmentTitle}
                            </div>
                            <div class='info-row'>
                                <span class='label'>Subject:</span> {$courseName}
                            </div>
                            <div class='info-row'>
                                <span class='label'>Class:</span> {$className}
                            </div>
                            <div class='info-row'>
                                <span class='label'>Due Date:</span> {$dueDate}
                            </div>
                            " . ($assignment['Total_Marks'] ? "<div class='info-row'><span class='label'>Total Marks:</span> {$assignment['Total_Marks']}</div>" : "") . "
                            <div class='info-row'>
                                <span class='label'>Teacher:</span> {$teacherName}
                            </div>
                            " . (!empty($assignment['Description']) ? "<p style='margin-top: 15px;'><strong>Description:</strong><br>" . htmlspecialchars($assignment['Description']) . "</p>" : "") . "
                            <p style='margin-top: 20px;'>Please remind your child to complete and submit the assignment before the due date.</p>
                            <p>Best regards,<br>HUDURY School</p>
                        </div>
                    </div>
                </body>
                </html>
                ";
                
                $emailResult = sendEmailViaBrevo($parentEmail, $parentName, $emailSubject, $emailBody);
                if ($emailResult['success']) {
                    $result['emails_sent']++;
                } else {
                    $result['errors'][] = "Failed to send email to parent {$parentEmail}: " . ($emailResult['error'] ?? 'Unknown error');
                }
            }
        }
        
    } catch (PDOException $e) {
        error_log("Error in notifyClassOfAssignment: " . $e->getMessage());
        $result['errors'][] = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        error_log("Error in notifyClassOfAssignment: " . $e->getMessage());
        $result['errors'][] = "Error: " . $e->getMessage();
    }
    
    return $result;
}

