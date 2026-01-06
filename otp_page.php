<?php
session_start();
require_once 'db.php';
require_once 'includes/otp-handler.php';
require_once 'includes/activity-logger.php';

$error = '';
$success = '';

if (isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'] === true) {
    
    $userType = $_SESSION['user_type'];
    $redirectMap = [
        'admin' => 'Admin/admin-dashboard.php',
        'teacher' => 'Teacher/teacher-dashboard.php',
        'parent' => 'Parent/parent-dashboard.php',
        'student' => 'Student/student-dashboard.php'
    ];
    $redirect = $redirectMap[$userType] ?? 'signin.php';
    header("Location: $redirect");
    exit();
}

if (!isset($_SESSION['pending_user_id']) || !isset($_SESSION['pending_user_type'])) {
    
    header("Location: signin.php");
    exit();
}

$userId = $_SESSION['pending_user_id'];
$userType = $_SESSION['pending_user_type'];
$userEmail = $_SESSION['pending_user_email'] ?? '';
$userName = $_SESSION['pending_user_name'] ?? 'User';
$lastLoginExpired = $_SESSION['last_login_expired'] ?? false;
$daysSinceLogin = $_SESSION['days_since_login'] ?? 0;

$userId = (int)$userId;

error_log("OTP Page - User_ID: $userId (type: " . gettype($userId) . "), User_Type: $userType, Last_Login_Expired: " . ($lastLoginExpired ? 'YES' : 'NO'));

$lastLoginCheck = checkLastLoginExpiry($pdo, $userId, $userType);
if ($lastLoginCheck['expired'] && !$lastLoginExpired) {
    
    invalidateAllUserOTPs($pdo, $userId, $userType);
    $lastLoginExpired = true;
    $daysSinceLogin = $lastLoginCheck['days_since'];
    $_SESSION['last_login_expired'] = true;
    $_SESSION['days_since_login'] = $daysSinceLogin;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $otp = trim($_POST['otp'] ?? '');
    
    if (empty($otp)) {
        $error = "Please enter the OTP code.";
    } elseif (strlen($otp) !== 6 || !ctype_digit($otp)) {
        $error = "OTP must be a 6-digit number.";
    } else {
        
        $validationResult = validateOTP($pdo, $userId, $userType, $otp);
        
        if ($validationResult['valid']) {
            
            $_SESSION['user_id'] = $_SESSION['pending_user_id'];
            $_SESSION['user_type'] = $_SESSION['pending_user_type'];
            $_SESSION['user_name'] = $_SESSION['pending_user_name'];
            $_SESSION['user_email'] = $_SESSION['pending_user_email'];
            $_SESSION['otp_verified'] = true; 

            if (isset($_SESSION['pending_user_national_id'])) {
                $_SESSION['user_national_id'] = $_SESSION['pending_user_national_id'];
            }
            if (isset($_SESSION['pending_user_student_code'])) {
                $_SESSION['user_student_code'] = $_SESSION['pending_user_student_code'];
            }

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
            }
            
            if ($table && $idField) {
                try {
                    $updateStmt = $pdo->prepare("UPDATE $table SET Last_Login = NOW() WHERE $idField = ?");
                    $updateStmt->execute([$userId]);
                } catch (PDOException $e) {
                    
                    error_log("Error updating last login: " . $e->getMessage());
                }
            }

            if ($userType === 'admin') {
                logAuthAction($pdo, 'login', "User: {$userName} (ID: {$userId}) - OTP verified");
            }

            $redirect = $_SESSION['pending_redirect'] ?? 'signin.php';

            unset($_SESSION['pending_user_id']);
            unset($_SESSION['pending_user_type']);
            unset($_SESSION['pending_user_name']);
            unset($_SESSION['pending_user_email']);
            unset($_SESSION['pending_redirect']);
            unset($_SESSION['pending_user_national_id']);
            unset($_SESSION['pending_user_student_code']);

            header("Location: $redirect");
            exit();
        } else {
            $error = $validationResult['error'] ?? 'Invalid OTP code. Please try again.';
        }
    }
}

if (isset($_GET['resend']) && $_GET['resend'] == '1') {
    
    $lastLoginCheck = checkLastLoginExpiry($pdo, $userId, $userType);
    if ($lastLoginCheck['expired']) {
        
        invalidateAllUserOTPs($pdo, $userId, $userType);
        $lastLoginExpired = true;
        $daysSinceLogin = $lastLoginCheck['days_since'];
        $_SESSION['last_login_expired'] = true;
        $_SESSION['days_since_login'] = $daysSinceLogin;
    }

    $otp = generateOTP();

    if (storeOTP($pdo, $userId, $userType, $userEmail, $otp)) {
        
        $emailResult = sendOTPEmail($userEmail, $otp, $userName);
        
        if ($emailResult['success']) {
            if ($lastLoginExpired) {
                $success = "It's been more than " . LAST_LOGIN_EXPIRY_DAYS . " days since your last login. A new verification code has been sent to your email.";
            } else {
                $success = "A new verification code has been sent to your email.";
            }
        } else {
            $error = "Failed to resend verification code: " . ($emailResult['error'] ?? 'Unknown error');
        }
    } else {
        $error = "Failed to generate new verification code. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Identity - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="signIn.css">
    <style>
        .otp-container {
            max-width: 500px;
            margin: 50px auto;
            padding: 40px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .otp-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .otp-header h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .otp-header p {
            color: #666;
            font-size: 14px;
        }
        .otp-icon {
            font-size: 64px;
            margin-bottom: 20px;
            color: #4CAF50;
        }
        .email-display {
            background-color: #f0f0f0;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 30px;
            font-size: 14px;
            color: #666;
        }
        .email-display strong {
            color: #333;
        }
        .otp-input-group {
            margin-bottom: 25px;
        }
        .otp-input-group label {
            display: block;
            margin-bottom: 10px;
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }
        .otp-input {
            width: 100%;
            padding: 15px;
            font-size: 24px;
            text-align: center;
            letter-spacing: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-weight: bold;
            transition: all 0.3s;
        }
        .otp-input:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }
        .submit-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 15px;
        }
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(76, 175, 80, 0.3);
        }
        .resend-link {
            text-align: center;
            margin-top: 20px;
        }
        .resend-link a {
            color: #4CAF50;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }
        .resend-link a:hover {
            text-decoration: underline;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #666;
            text-decoration: none;
            margin-bottom: 20px;
            font-size: 14px;
            transition: color 0.3s;
        }
        .back-link:hover {
            color: #4CAF50;
        }
        .expiry-info {
            text-align: center;
            color: #999;
            font-size: 12px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="floating-emoji">🔐</div>
    <div class="floating-emoji">✉️</div>
    <div class="floating-emoji">🔒</div>

    <div class="otp-container">
        <a href="signin.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            <span>Back to Sign In</span>
        </a>

        <div class="otp-header">
            <div class="otp-icon">🔐</div>
            <h1>Verify Your Identity</h1>
            <p>We've sent a verification code to your email</p>
        </div>

        <div class="email-display">
            <i class="fas fa-envelope"></i> Code sent to: <strong><?php echo htmlspecialchars($userEmail); ?></strong>
        </div>

        <?php if ($lastLoginExpired): ?>
            <div class="info-message" style="background-color: #e3f2fd; border-left: 4px solid #2196F3; padding: 15px; margin-bottom: 20px; border-radius: 5px;">
                <i class="fas fa-info-circle"></i> 
                <strong>Security Notice:</strong> It's been more than <?php echo LAST_LOGIN_EXPIRY_DAYS; ?> days since your last login (<?php echo $daysSinceLogin; ?> days ago). 
                A new verification code has been sent to your email for security purposes.
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="otp_page.php" id="otpForm">
            <div class="otp-input-group">
                <label for="otp">
                    <i class="fas fa-key"></i> Enter Verification Code
                </label>
                <input 
                    type="text" 
                    id="otp" 
                    name="otp" 
                    class="otp-input"
                    placeholder="000000"
                    maxlength="6"
                    pattern="[0-9]{6}"
                    required
                    autocomplete="off"
                    autofocus
                >
                <div class="expiry-info">
                    <i class="fas fa-clock"></i> Code expires in 5 minutes
                </div>
            </div>

            <button type="submit" class="submit-btn">
                Verify & Continue 🚀
            </button>
        </form>

        <div class="resend-link">
            <p>Didn't receive the code?</p>
            <a href="otp_page.php?resend=1">
                <i class="fas fa-redo"></i> Resend Verification Code
            </a>
        </div>
    </div>

    <script>
        
        const otpInput = document.getElementById('otp');
        
        otpInput.addEventListener('input', function(e) {
            
            this.value = this.value.replace(/[^0-9]/g, '');

            if (this.value.length > 6) {
                this.value = this.value.slice(0, 6);
            }
        });

        otpInput.addEventListener('paste', function(e) {
            e.preventDefault();
            const pastedText = (e.clipboardData || window.clipboardData).getData('text');
            const numericOnly = pastedText.replace(/[^0-9]/g, '').slice(0, 6);
            this.value = numericOnly;
        });

        otpInput.addEventListener('input', function(e) {
            if (this.value.length === 6) {
                
                setTimeout(() => {
                    document.getElementById('otpForm').submit();
                }, 300);
            }
        });

        document.getElementById('otpForm').addEventListener('submit', function(e) {
            const otp = otpInput.value.trim();
            
            if (otp.length !== 6 || !/^\d{6}$/.test(otp)) {
                e.preventDefault();
                alert('Please enter a valid 6-digit verification code.');
                return false;
            }
        });
    </script>
</body>
</html>

