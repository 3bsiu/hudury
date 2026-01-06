<?php
session_start();
require_once 'db.php';
require_once 'includes/otp-handler.php';

$error = '';
$success = '';

if (!isset($_SESSION['password_reset_user_id']) || !isset($_SESSION['password_reset_user_type'])) {
    
    header("Location: forgot-password.php");
    exit();
}

$userId = $_SESSION['password_reset_user_id'];
$userType = $_SESSION['password_reset_user_type'];
$userEmail = $_SESSION['password_reset_user_email'] ?? '';
$userName = $_SESSION['password_reset_user_name'] ?? 'User';

$userId = (int)$userId;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $otp = trim($_POST['otp'] ?? '');
    
    if (empty($otp)) {
        $error = "Please enter the OTP code.";
    } elseif (strlen($otp) !== 6 || !ctype_digit($otp)) {
        $error = "OTP must be a 6-digit number.";
    } else {
        
        $validationResult = validatePasswordResetOTP($pdo, $userId, $userType, $otp);
        
        if ($validationResult['valid']) {
            
            $_SESSION['password_reset_otp_verified'] = true;

            header("Location: reset-password.php");
            exit();
        } else {
            $error = $validationResult['error'] ?? 'Invalid OTP code. Please try again.';
        }
    }
}

if (isset($_GET['resend']) && $_GET['resend'] == '1') {
    
    $otp = generateOTP();

    if (storeOTP($pdo, $userId, $userType, $userEmail, $otp)) {
        
        $emailResult = sendPasswordResetOTPEmail($userEmail, $otp, $userName);
        
        if ($emailResult['success']) {
            $success = "A new verification code has been sent to your email.";
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
    <title>Verify OTP - Password Reset - HUDURY</title>
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
            color: #FF6B9D;
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
            border-color: #FF6B9D;
            box-shadow: 0 0 0 3px rgba(255, 107, 157, 0.1);
        }
        .submit-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #FF6B9D, #6BCB77);
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
            box-shadow: 0 5px 15px rgba(255, 107, 157, 0.3);
        }
        .resend-link {
            text-align: center;
            margin-top: 20px;
        }
        .resend-link a {
            color: #FF6B9D;
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
            color: #FF6B9D;
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
        <a href="forgot-password.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            <span>Back</span>
        </a>

        <div class="otp-header">
            <div class="otp-icon">🔐</div>
            <h1>Verify Your Identity</h1>
            <p>Enter the verification code sent to your email</p>
        </div>

        <div class="email-display">
            <i class="fas fa-envelope"></i> Code sent to: <strong><?php echo htmlspecialchars($userEmail); ?></strong>
        </div>

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

        <form method="POST" action="reset-password-otp.php" id="otpForm">
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
                    <i class="fas fa-clock"></i> Code expires in <?php echo OTP_EXPIRY_MINUTES; ?> minutes
                </div>
            </div>

            <button type="submit" class="submit-btn">
                Verify & Continue 🚀
            </button>
        </form>

        <div class="resend-link">
            <p>Didn't receive the code?</p>
            <a href="reset-password-otp.php?resend=1">
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



