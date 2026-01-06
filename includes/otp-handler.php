<?php

require_once __DIR__ . '/../db.php';

if (!defined('OTP_EXPIRY_MINUTES')) {
    define('OTP_EXPIRY_MINUTES', 5);
}
if (!defined('LAST_LOGIN_EXPIRY_DAYS')) {
    define('LAST_LOGIN_EXPIRY_DAYS', 5); 
}
if (!defined('SKIP_OTP_RECENT_LOGIN_MINUTES')) {
    define('SKIP_OTP_RECENT_LOGIN_MINUTES', 5); 
}

if (file_exists(__DIR__ . '/brevo-config.php')) {
    require_once __DIR__ . '/brevo-config.php';
} else {
    
    if (!defined('BREVO_API_KEY')) {
        define('BREVO_API_KEY', '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');
    }
    if (!defined('BREVO_SENDER_EMAIL')) {
        define('BREVO_SENDER_EMAIL', 'mohammedabsi3@gmail.com');
    }
    if (!defined('BREVO_SENDER_NAME')) {
        define('BREVO_SENDER_NAME', 'HUDURY School');
    }
}

function checkRecentLogin($pdo, $userId, $userType) {
    try {
        $table = '';
        $idField = '';
        
        switch($userType) {
            case 'admin':
                $table = 'admin';
                $idField = 'Admin_ID';
                break;
            case 'teacher':
                $table = 'teacher';
                $idField = 'Teacher_ID';
                break;
            case 'parent':
                $table = 'parent';
                $idField = 'Parent_ID';
                break;
            case 'student':
                $table = 'student';
                $idField = 'Student_ID';
                break;
            default:
                return ['is_recent' => false, 'minutes_since' => 999, 'last_login' => null];
        }
        
        $stmt = $pdo->prepare("
            SELECT Last_Login, 
                   TIMESTAMPDIFF(MINUTE, Last_Login, NOW()) as minutes_since
            FROM $table 
            WHERE $idField = ?
        ");
        $stmt->bindValue(1, (int)$userId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result || $result['Last_Login'] === null) {
            
            return ['is_recent' => false, 'minutes_since' => 999, 'last_login' => null];
        }
        
        $minutesSince = (int)($result['minutes_since'] ?? 999);
        $isRecent = $minutesSince < SKIP_OTP_RECENT_LOGIN_MINUTES;
        
        return [
            'is_recent' => $isRecent,
            'minutes_since' => $minutesSince,
            'last_login' => $result['Last_Login']
        ];
        
    } catch (PDOException $e) {
        error_log("Error checking recent login: " . $e->getMessage());
        
        return ['is_recent' => false, 'minutes_since' => 999, 'last_login' => null];
    }
}

function checkLastLoginExpiry($pdo, $userId, $userType) {
    try {
        $table = '';
        $idField = '';
        
        switch($userType) {
            case 'admin':
                $table = 'admin';
                $idField = 'Admin_ID';
                break;
            case 'teacher':
                $table = 'teacher';
                $idField = 'Teacher_ID';
                break;
            case 'parent':
                $table = 'parent';
                $idField = 'Parent_ID';
                break;
            case 'student':
                $table = 'student';
                $idField = 'Student_ID';
                break;
            default:
                return ['expired' => false, 'days_since' => 0, 'last_login' => null];
        }
        
        $stmt = $pdo->prepare("
            SELECT Last_Login, 
                   DATEDIFF(NOW(), Last_Login) as days_since
            FROM $table 
            WHERE $idField = ?
        ");
        $stmt->bindValue(1, (int)$userId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result || $result['Last_Login'] === null) {
            
            return ['expired' => true, 'days_since' => 999, 'last_login' => null];
        }
        
        $daysSince = (int)($result['days_since'] ?? 0);
        $isExpired = $daysSince >= LAST_LOGIN_EXPIRY_DAYS;
        
        return [
            'expired' => $isExpired,
            'days_since' => $daysSince,
            'last_login' => $result['Last_Login']
        ];
        
    } catch (PDOException $e) {
        error_log("Error checking Last_Login: " . $e->getMessage());
        
        return ['expired' => false, 'days_since' => 0, 'last_login' => null];
    }
}

function invalidateAllUserOTPs($pdo, $userId, $userType) {
    try {
        $userId = (int)$userId;
        $userType = trim($userType);
        
        $stmt = $pdo->prepare("
            UPDATE otp_verification 
            SET Is_Used = 1 
            WHERE User_ID = ? AND User_Type = ? AND Is_Used = 0
        ");
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $userType, PDO::PARAM_STR);
        $stmt->execute();
        
        $invalidatedCount = $stmt->rowCount();
        if ($invalidatedCount > 0) {
            error_log("Invalidated $invalidatedCount OTP(s) due to Last_Login expiry for User_ID: $userId, User_Type: $userType");
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Error invalidating all OTPs: " . $e->getMessage());
        return false;
    }
}

function generateOTP() {
    return str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

function storeOTP($pdo, $userId, $userType, $email, $otp) {

    $otpHash = password_hash($otp, PASSWORD_DEFAULT);

    try {
        
        $userId = (int)$userId;
        $userType = trim($userType);
        
        $invalidateStmt = $pdo->prepare("
            UPDATE otp_verification 
            SET Is_Used = 1 
            WHERE User_ID = ? AND User_Type = ? AND Is_Used = 0 AND Expires_At > NOW()
        ");
        $invalidateStmt->bindValue(1, $userId, PDO::PARAM_INT);
        $invalidateStmt->bindValue(2, $userType, PDO::PARAM_STR);
        $invalidateStmt->execute();
        
        $invalidatedCount = $invalidateStmt->rowCount();
        if ($invalidatedCount > 0) {
            error_log("Invalidated $invalidatedCount old OTP(s) for User_ID: $userId, User_Type: $userType");
        }
    } catch (PDOException $e) {
        error_log("Error invalidating old OTPs: " . $e->getMessage());
    }

    try {
        
        $userId = (int)$userId;
        $userType = trim($userType);

        $stmt = $pdo->prepare("
            INSERT INTO otp_verification (User_ID, User_Type, OTP_Hash, Email, Expires_At)
            VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))
        ");
        
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $userType, PDO::PARAM_STR);
        $stmt->bindValue(3, $otpHash, PDO::PARAM_STR);
        $stmt->bindValue(4, $email, PDO::PARAM_STR);
        $stmt->bindValue(5, OTP_EXPIRY_MINUTES, PDO::PARAM_INT);
        $stmt->execute();

        $otpId = $pdo->lastInsertId();
        $expireCheck = $pdo->prepare("SELECT Expires_At FROM otp_verification WHERE OTP_ID = ?");
        $expireCheck->execute([$otpId]);
        $expireResult = $expireCheck->fetch(PDO::FETCH_ASSOC);
        $actualExpires = $expireResult['Expires_At'] ?? 'N/A';
        error_log("OTP stored - OTP_ID: $otpId, User_ID: $userId, User_Type: $userType, Expires: $actualExpires");
        
        return true;
    } catch (PDOException $e) {
        error_log("Error storing OTP: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}

function verifyBrevoAPIKey($apiKey, $returnDetails = false) {
    $url = 'https://api.brevo.com/v3/account';
    
    $apiKey = trim($apiKey);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'api-key: ' . $apiKey,
        'Accept: application/json',
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);
    
    if ($returnDetails) {
        $responseData = json_decode($response, true);
        return [
            'valid' => $httpCode === 200,
            'http_code' => $httpCode,
            'response' => $response,
            'response_data' => $responseData,
            'curl_error' => $curlError,
            'curl_errno' => $curlErrno
        ];
    }
    
    return $httpCode === 200;
}

function sendOTPEmail($email, $otp, $userName = 'User') {
    
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

    if (strlen($apiKey) < 20) {
        error_log("Brevo API Error: API key appears to be invalid (too short)");
        return ['success' => false, 'error' => 'Invalid API key format'];
    }

    if (!preg_match('/^x(keysib|smtpsib)-/', $apiKey)) {
        error_log("Brevo API Error: API key format is invalid (should start with 'xkeysib-' or 'xsmtpsib-')");
        return ['success' => false, 'error' => 'Invalid API key format. Brevo API keys should start with "xkeysib-" or "xsmtpsib-"'];
    }
    
    $emailSubject = "Your HUDURY Login Verification Code";
    $emailBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #4CAF50; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
            .content { background-color: #f9f9f9; padding: 30px; border-radius: 0 0 5px 5px; }
            .otp-code { background-color: #fff; border: 2px dashed #4CAF50; padding: 20px; text-align: center; margin: 20px 0; font-size: 32px; font-weight: bold; color: #4CAF50; letter-spacing: 5px; }
            .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
            .warning { background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🔐 HUDURY Login Verification</h1>
            </div>
            <div class='content'>
                <p>Hello <strong>{$userName}</strong>,</p>
                <p>You have requested to log in to your HUDURY account. Please use the following One-Time Password (OTP) to complete your login:</p>
                
                <div class='otp-code'>{$otp}</div>
                
                <div class='warning'>
                    <strong>⚠️ Important:</strong> This code will expire in " . OTP_EXPIRY_MINUTES . " minutes. Do not share this code with anyone.
                </div>
                
                <p>If you did not attempt to log in, please ignore this email or contact support immediately.</p>
                
                <p>Best regards,<br><strong>HUDURY School System</strong></p>
            </div>
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";

    $url = 'https://api.brevo.com/v3/smtp/email';

    $data = [
        'sender' => [
            'name' => $senderName,
            'email' => $senderEmail
        ],
        'to' => [
            [
                'email' => $email,
                'name' => $userName
            ]
        ],
        'subject' => $emailSubject,
        'htmlContent' => $emailBody
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
        return ['success' => false, 'error' => 'Failed to connect to email service. Please check your internet connection.'];
    }

    $responsePreview = substr($response, 0, 500);
    error_log("Brevo API Response (HTTP $httpCode): " . $responsePreview);

    if ($httpCode !== 201 && $httpCode !== 200) {
        $errorData = json_decode($response, true);

        $errorMessage = 'Unknown error';
        if (isset($errorData['message'])) {
            $errorMessage = $errorData['message'];
        } elseif (isset($errorData['error'])) {
            $errorMessage = is_array($errorData['error']) ? json_encode($errorData['error']) : $errorData['error'];
        } elseif (isset($errorData['code'])) {
            $errorMessage = 'Error code: ' . $errorData['code'];
        } elseif (is_string($response) && !empty($response)) {
            $errorMessage = $response;
        }

        error_log("Brevo API Error Details:");
        error_log("  HTTP Code: $httpCode");
        error_log("  Error Message: " . $errorMessage);
        error_log("  Full Response: " . $response);
        error_log("  API Key (first 20 chars): " . substr($apiKey, 0, 20) . "...");

        if ($httpCode === 401 || $httpCode === 403) {
            return ['success' => false, 'error' => 'API key authentication failed. Please verify your Brevo API key is correct and has SMTP sending permissions.'];
        }
        
        if (strpos(strtolower($errorMessage), 'key') !== false || 
            strpos(strtolower($errorMessage), 'unauthorized') !== false ||
            strpos(strtolower($errorMessage), 'forbidden') !== false) {
            return ['success' => false, 'error' => 'API key is invalid or expired. Please check your Brevo account settings.'];
        }

        if (strpos(strtolower($errorMessage), 'sender') !== false || 
            strpos(strtolower($errorMessage), 'from') !== false) {
            return ['success' => false, 'error' => 'Sender email not verified. Please verify ' . $senderEmail . ' in your Brevo account.'];
        }
        
        return ['success' => false, 'error' => 'Failed to send email: ' . $errorMessage];
    }
    
    return ['success' => true, 'message' => 'OTP sent successfully'];
}

function sendPasswordResetOTPEmail($email, $otp, $userName = 'User') {
    
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

    if (strlen($apiKey) < 20) {
        error_log("Brevo API Error: API key appears to be invalid (too short)");
        return ['success' => false, 'error' => 'Invalid API key format'];
    }
    
    if (!preg_match('/^x(keysib|smtpsib)-/', $apiKey)) {
        error_log("Brevo API Error: API key format is invalid");
        return ['success' => false, 'error' => 'Invalid API key format'];
    }
    
    $emailSubject = "HUDURY Password Reset Verification Code";
    $emailBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #FF6B9D; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
            .content { background-color: #f9f9f9; padding: 30px; border-radius: 0 0 5px 5px; }
            .otp-code { background-color: #fff; border: 2px dashed #FF6B9D; padding: 20px; text-align: center; margin: 20px 0; font-size: 32px; font-weight: bold; color: #FF6B9D; letter-spacing: 5px; }
            .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
            .warning { background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🔐 HUDURY Password Reset</h1>
            </div>
            <div class='content'>
                <p>Hello <strong>{$userName}</strong>,</p>
                <p>You have requested to reset your password. Please use the following One-Time Password (OTP) to verify your identity:</p>
                
                <div class='otp-code'>{$otp}</div>
                
                <div class='warning'>
                    <strong>⚠️ Important:</strong> This code will expire in " . OTP_EXPIRY_MINUTES . " minutes. Do not share this code with anyone.
                </div>
                
                <p>If you did not request a password reset, please ignore this email or contact support immediately.</p>
                
                <p>Best regards,<br><strong>HUDURY School System</strong></p>
            </div>
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";

    $url = 'https://api.brevo.com/v3/smtp/email';

    $data = [
        'sender' => [
            'name' => $senderName,
            'email' => $senderEmail
        ],
        'to' => [
            [
                'email' => $email,
                'name' => $userName
            ]
        ],
        'subject' => $emailSubject,
        'htmlContent' => $emailBody
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
        return ['success' => false, 'error' => 'Failed to connect to email service. Please check your internet connection.'];
    }

    if ($httpCode !== 201 && $httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMessage = 'Unknown error';
        if (isset($errorData['message'])) {
            $errorMessage = $errorData['message'];
        } elseif (isset($errorData['error'])) {
            $errorMessage = is_array($errorData['error']) ? json_encode($errorData['error']) : $errorData['error'];
        }
        
        error_log("Brevo API Error - Password Reset OTP: HTTP $httpCode - $errorMessage");
        return ['success' => false, 'error' => 'Failed to send email: ' . $errorMessage];
    }
    
    return ['success' => true, 'message' => 'Password reset OTP sent successfully'];
}

function validatePasswordResetOTP($pdo, $userId, $userType, $otp) {
    try {
        
        $userId = (int)$userId;
        $userType = trim($userType);
        $otp = trim($otp);

        error_log("Password Reset OTP Validation - User_ID: $userId, User_Type: $userType");

        $stmt = $pdo->prepare("
            SELECT OTP_ID, OTP_Hash, Expires_At, Created_At, Email, User_ID, User_Type
            FROM otp_verification 
            WHERE User_ID = ? 
            AND User_Type = ? 
            AND Is_Used = 0 
            AND Expires_At > NOW()
            ORDER BY Created_At DESC 
            LIMIT 1
        ");
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $userType, PDO::PARAM_STR);
        $stmt->execute();
        $otpRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$otpRecord) {
            
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) as total,
                       SUM(CASE WHEN Is_Used = 1 THEN 1 ELSE 0 END) as used_count,
                       SUM(CASE WHEN Expires_At <= NOW() THEN 1 ELSE 0 END) as expired_count
                FROM otp_verification 
                WHERE User_ID = ? AND User_Type = ?
            ");
            $checkStmt->bindValue(1, $userId, PDO::PARAM_INT);
            $checkStmt->bindValue(2, $userType, PDO::PARAM_STR);
            $checkStmt->execute();
            $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            $errorMsg = 'No valid OTP found. ';
            if ($checkResult && $checkResult['total'] > 0) {
                if ($checkResult['used_count'] > 0) {
                    $errorMsg .= 'The OTP code has already been used. ';
                }
                if ($checkResult['expired_count'] > 0) {
                    $errorMsg .= 'The OTP code has expired. ';
                }
                $errorMsg .= 'Please request a new password reset.';
            } else {
                $errorMsg .= 'Please request a new password reset.';
            }
            
            return ['valid' => false, 'error' => $errorMsg];
        }

        if (!password_verify($otp, $otpRecord['OTP_Hash'])) {
            error_log("Password Reset OTP verification failed");
            return ['valid' => false, 'error' => 'Invalid OTP code. Please try again.'];
        }

        $updateStmt = $pdo->prepare("
            UPDATE otp_verification 
            SET Is_Used = 1, Used_At = NOW() 
            WHERE OTP_ID = ?
        ");
        $updateStmt->execute([$otpRecord['OTP_ID']]);
        
        error_log("Password Reset OTP verified successfully for User_ID: $userId");
        return ['valid' => true, 'message' => 'OTP verified successfully'];
        
    } catch (PDOException $e) {
        error_log("Error validating password reset OTP: " . $e->getMessage());
        return ['valid' => false, 'error' => 'System error during OTP validation'];
    }
}

function validateOTP($pdo, $userId, $userType, $otp) {
    try {
        
        $userId = (int)$userId;
        $userType = trim($userType);
        $otp = trim($otp);

        error_log("OTP Validation - User_ID: $userId, User_Type: $userType, OTP: " . substr($otp, 0, 2) . "****");

        $lastLoginCheck = checkLastLoginExpiry($pdo, $userId, $userType);
        if ($lastLoginCheck['expired']) {
            
            invalidateAllUserOTPs($pdo, $userId, $userType);
            return [
                'valid' => false, 
                'error' => 'Your last login was more than ' . LAST_LOGIN_EXPIRY_DAYS . ' days ago. Please request a new verification code.',
                'last_login_expired' => true
            ];
        }

        $stmt = $pdo->prepare("
            SELECT OTP_ID, OTP_Hash, Expires_At, Created_At, Email, User_ID, User_Type
            FROM otp_verification 
            WHERE User_ID = ? 
            AND User_Type = ? 
            AND Is_Used = 0 
            AND Expires_At > NOW()
            ORDER BY Created_At DESC 
            LIMIT 1
        ");
        
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $userType, PDO::PARAM_STR);
        $stmt->execute();
        $otpRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($otpRecord) {
            error_log("OTP Record found - OTP_ID: " . $otpRecord['OTP_ID'] . ", Expires: " . $otpRecord['Expires_At']);
        } else {
            
            $debugStmt = $pdo->prepare("
                SELECT OTP_ID, User_ID, User_Type, Is_Used, Expires_At, Created_At
                FROM otp_verification 
                WHERE User_ID = ? AND User_Type = ?
                ORDER BY Created_At DESC 
                LIMIT 5
            ");
            $debugStmt->execute([$userId, $userType]);
            $debugRecords = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("Debug - Found " . count($debugRecords) . " OTP records for User_ID: $userId (type: " . gettype($userId) . "), User_Type: $userType");
            if (count($debugRecords) > 0) {
                foreach ($debugRecords as $rec) {
                    $isExpired = strtotime($rec['Expires_At']) < time();
                    error_log("  - OTP_ID: " . $rec['OTP_ID'] . ", User_ID: " . $rec['User_ID'] . " (type: " . gettype($rec['User_ID']) . "), Is_Used: " . $rec['Is_Used'] . ", Expired: " . ($isExpired ? 'YES' : 'NO') . ", Expires: " . $rec['Expires_At'] . ", Created: " . $rec['Created_At']);
                }
            } else {
                
                $debugStmt2 = $pdo->prepare("
                    SELECT OTP_ID, User_ID, User_Type, Is_Used, Expires_At, Created_At
                    FROM otp_verification 
                    WHERE User_Type = ?
                    ORDER BY Created_At DESC 
                    LIMIT 10
                ");
                $debugStmt2->execute([$userType]);
                $allRecords = $debugStmt2->fetchAll(PDO::FETCH_ASSOC);
                error_log("Debug - Found " . count($allRecords) . " total OTP records for User_Type: $userType");
            }
        }
        
        if (!$otpRecord) {
            
            $errorMsg = 'No valid OTP found. ';

            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) as total,
                       SUM(CASE WHEN Is_Used = 1 THEN 1 ELSE 0 END) as used_count,
                       SUM(CASE WHEN Expires_At <= NOW() THEN 1 ELSE 0 END) as expired_count
                FROM otp_verification 
                WHERE User_ID = ? AND User_Type = ?
            ");
            $checkStmt->bindValue(1, $userId, PDO::PARAM_INT);
            $checkStmt->bindValue(2, $userType, PDO::PARAM_STR);
            $checkStmt->execute();
            $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($checkResult && $checkResult['total'] > 0) {
                if ($checkResult['used_count'] > 0) {
                    $errorMsg .= 'The OTP code has already been used. ';
                }
                if ($checkResult['expired_count'] > 0) {
                    $errorMsg .= 'The OTP code has expired. ';
                }
                $errorMsg .= 'Please request a new login to receive a fresh code.';
            } else {
                $errorMsg .= 'Please request a new login.';
            }
            
            return ['valid' => false, 'error' => $errorMsg];
        }

        if (!password_verify($otp, $otpRecord['OTP_Hash'])) {
            error_log("OTP verification failed - password_verify returned false");
            return ['valid' => false, 'error' => 'Invalid OTP code. Please try again.'];
        }

        $updateStmt = $pdo->prepare("
            UPDATE otp_verification 
            SET Is_Used = 1, Used_At = NOW() 
            WHERE OTP_ID = ?
        ");
        $updateStmt->execute([$otpRecord['OTP_ID']]);
        
        error_log("OTP verified successfully for User_ID: $userId");
        return ['valid' => true, 'message' => 'OTP verified successfully'];
        
    } catch (PDOException $e) {
        error_log("Error validating OTP: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return ['valid' => false, 'error' => 'System error during OTP validation'];
    }
}

function cleanupExpiredOTPs($pdo) {
    try {
        $stmt = $pdo->prepare("
            DELETE FROM otp_verification 
            WHERE Expires_At < NOW() AND Is_Used = 0
        ");
        $stmt->execute();
        return true;
    } catch (PDOException $e) {
        error_log("Error cleaning up expired OTPs: " . $e->getMessage());
        return false;
    }
}

