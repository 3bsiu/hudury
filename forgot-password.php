<?php
session_start();
require_once 'db.php';
require_once 'includes/otp-handler.php';

$error = '';
$success = '';

unset($_SESSION['password_reset_user_id']);
unset($_SESSION['password_reset_user_type']);
unset($_SESSION['password_reset_user_email']);
unset($_SESSION['password_reset_user_name']);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_type = trim($_POST['user_type'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $national_id = trim($_POST['national_id'] ?? '');

    if (empty($user_type) || empty($email) || empty($national_id)) {
        $error = "Please fill in all fields.";
    } elseif (!in_array($user_type, ['student', 'teacher', 'parent'])) {
        $error = "Invalid account type selected.";
    } else {
        try {
            
            $table = '';
            $idField = '';
            $nationalIdField = '';
            
            switch($user_type) {
                case 'student':
                    $table = 'student';
                    $idField = 'Student_ID';
                    $nationalIdField = 'National_ID';
                    break;
                case 'teacher':
                    $table = 'teacher';
                    $idField = 'Teacher_ID';
                    $nationalIdField = 'National_ID';
                    break;
                case 'parent':
                    $table = 'parent';
                    $idField = 'Parent_ID';
                    $nationalIdField = 'National_ID';
                    break;
            }

            $query = "SELECT * FROM $table WHERE Email = ? AND $nationalIdField = ? LIMIT 1";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$email, $national_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $error = "The information provided does not match our records. Please check your details and try again.";
            } else {
                
                $userId = (int)$user[$idField];
                $userEmail = $user['Email'];
                $userName = $user['NameEn'] ?? $user['NameAr'] ?? 'User';

                invalidateAllUserOTPs($pdo, $userId, $user_type);

                $otp = generateOTP();

                if (!storeOTP($pdo, $userId, $user_type, $userEmail, $otp)) {
                    $error = "Failed to generate verification code. Please try again.";
                } else {
                    
                    $emailResult = sendPasswordResetOTPEmail($userEmail, $otp, $userName);
                    
                    if (!$emailResult['success']) {
                        $error = "Failed to send verification email: " . ($emailResult['error'] ?? 'Unknown error') . ". Please try again later.";
                    } else {
                        
                        $_SESSION['password_reset_user_id'] = $userId;
                        $_SESSION['password_reset_user_type'] = $user_type;
                        $_SESSION['password_reset_user_email'] = $userEmail;
                        $_SESSION['password_reset_user_name'] = $userName;
                        $_SESSION['password_reset_otp_sent'] = true;

                        header("Location: reset-password-otp.php");
                        exit();
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Password reset error: " . $e->getMessage());
            $error = "An error occurred. Please try again later.";
        } catch (Exception $e) {
            error_log("OTP system error: " . $e->getMessage());
            $error = "System error. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="signIn.css">
</head>
<body>
    <div class="floating-emoji">🔐</div>
    <div class="floating-emoji">🔑</div>
    <div class="floating-emoji">✉️</div>
    <div class="floating-emoji">🔒</div>

    <div class="login-container">
        <a href="signin.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            <span>Back to Sign In</span>
        </a>

        <div class="login-header">
            <div class="logo">HUDURY</div>
            <h1>Forgot Password? 🔐</h1>
            <p>Verify your identity to reset your password</p>
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

        <form method="POST" action="forgot-password.php">
            <div class="form-group">
                <label for="user_type">Account Type:</label>
                <div class="user-type-selector">
                    <label class="user-type-option">
                        <input type="radio" name="user_type" value="student" required>
                        <div class="user-type-label">
                            <div class="user-type-icon">👨‍🎓</div>
                            <div class="user-type-text">Student</div>
                        </div>
                    </label>
                    <label class="user-type-option">
                        <input type="radio" name="user_type" value="teacher" required>
                        <div class="user-type-label">
                            <div class="user-type-icon">👩‍🏫</div>
                            <div class="user-type-text">Teacher</div>
                        </div>
                    </label>
                    <label class="user-type-option">
                        <input type="radio" name="user_type" value="parent" required>
                        <div class="user-type-label">
                            <div class="user-type-icon">👨‍👩‍👧</div>
                            <div class="user-type-text">Parent</div>
                        </div>
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label for="email">
                    <i class="fas fa-envelope"></i> Email Address
                </label>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    placeholder="Enter your email address"
                    required
                    autocomplete="email"
                >
            </div>

            <div class="form-group">
                <label for="national_id">
                    <i class="fas fa-id-card"></i> National ID
                </label>
                <input 
                    type="text" 
                    id="national_id" 
                    name="national_id" 
                    placeholder="Enter your national ID"
                    required
                    autocomplete="off"
                >
            </div>

            <button type="submit" class="submit-btn">
                Verify & Send Code 🔐
            </button>
        </form>

        <div style="text-align: center; margin-top: 1.5rem; color: #666; font-size: 0.9rem;">
            <p>We'll send a verification code to your email to reset your password.</p>
        </div>
    </div>

    <script>
        
        document.querySelectorAll('.user-type-option').forEach(option => {
            option.addEventListener('click', function() {
                document.querySelectorAll('.user-type-option').forEach(opt => {
                    opt.style.transform = 'scale(1)';
                });
                this.style.transform = 'scale(1.05)';
            });
        });

        document.querySelector('form').addEventListener('submit', function(e) {
            const userType = document.querySelector('input[name="user_type"]:checked');
            const email = document.getElementById('email').value.trim();
            const nationalId = document.getElementById('national_id').value.trim();

            if (!userType || !email || !nationalId) {
                e.preventDefault();
                alert('Please fill in all fields.');
                return false;
            }
        });
    </script>
</body>
</html>

