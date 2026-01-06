<?php
session_start();
require_once 'db.php';
require_once 'includes/otp-handler.php';
require_once 'includes/activity-logger.php';

$error = '';
$success = '';

if (isset($_GET['password_reset']) && $_GET['password_reset'] === 'success') {
    $success = "Your password has been reset successfully! Please sign in with your new password.";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email_or_id = trim($_POST['email_or_id'] ?? '');
    $password = $_POST['password'] ?? '';
    $user_type = $_POST['user_type'] ?? 'student';

    if (!empty($email_or_id) && !empty($password)) {
        try {
            
            $table = '';
            $redirect = '';
            
            switch($user_type) {
                case 'admin':
                    $table = 'admin';
                    $redirect = 'Admin/admin-dashboard.php';
                    break;
                case 'teacher':
                    $table = 'teacher';
                    $redirect = 'Teacher/teacher-dashboard.php';
                    break;
                case 'parent':
                    $table = 'parent';
                    $redirect = 'Parent/parent-dashboard.php';
                    break;
                case 'student':
                default:
                    $table = 'student';
                    $redirect = 'Student/student-dashboard.php';
                    break;
            }

            $user = null;
            $passwordMatch = false;

            $query = "SELECT * FROM $table WHERE Email = ?";
            $params = [$email_or_id];

            if (in_array($user_type, ['parent', 'student'])) {
                $query .= " OR National_ID = ?";
                $params[] = $email_or_id;
            }
            
            $query .= " LIMIT 1";
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {

                $passwordField = ($user_type === 'student') ? 'Password' : 'Password_Hash';
                
                if (isset($user[$passwordField])) {
                    
                    if (password_verify($password, $user[$passwordField])) {
                        $passwordMatch = true;
                    }
                    
                    elseif ($user[$passwordField] === $password) {
                        $passwordMatch = true;
                    }
                }
            }

            if ($user && $passwordMatch) {
                
                $id_field = '';
                switch($user_type) {
                    case 'admin':
                        $id_field = 'Admin_ID';
                        break;
                    case 'teacher':
                        $id_field = 'Teacher_ID';
                        break;
                    case 'parent':
                        $id_field = 'Parent_ID';
                        break;
                    case 'student':
                        $id_field = 'Student_ID';
                        break;
                }
                
                $userId = $user[$id_field];
                $userEmail = $user['Email'] ?? '';
                $userName = $user['Name'] ?? 'User';

                $userId = (int)$userId;

                error_log("Signin - Checking login for User_ID: $userId (type: " . gettype($userId) . "), User_Type: $user_type");

                $lastLoginCheck = checkLastLoginExpiry($pdo, $userId, $user_type);
                $lastLoginExpired = $lastLoginCheck['expired'];
                $daysSince = $lastLoginCheck['days_since'];

                if (!$lastLoginExpired) {
                    
                    error_log("Last login was $daysSince days ago (less than 5 days). Skipping OTP for User_ID: $userId");

                    $_SESSION['user_id'] = $userId;
                    $_SESSION['user_type'] = $user_type;
                    $_SESSION['user_name'] = $userName;
                    $_SESSION['user_email'] = $userEmail;
                    $_SESSION['otp_verified'] = true; 

                    if ($user_type === 'student') {
                        $_SESSION['user_national_id'] = $user['National_ID'] ?? '';
                        $_SESSION['user_student_code'] = $user['Student_Code'] ?? '';
                    } elseif ($user_type === 'parent') {
                        $_SESSION['user_national_id'] = $user['National_ID'] ?? '';
                    }

                    try {
                        $updateStmt = $pdo->prepare("UPDATE $table SET Last_Login = NOW() WHERE $id_field = ?");
                        $updateStmt->execute([$userId]);
                    } catch (PDOException $e) {
                        error_log("Error updating last login: " . $e->getMessage());
                    }

                    if ($user_type === 'admin') {
                        logAuthAction($pdo, 'login', "User: {$userName} (ID: {$userId})");
                    }

                    header("Location: $redirect");
                    exit();
                }

                invalidateAllUserOTPs($pdo, $userId, $user_type);
                error_log("Last_Login expired for User_ID: $userId - $daysSince days since last login. Requiring OTP verification.");

                $otp = generateOTP();

                if (!storeOTP($pdo, $userId, $user_type, $userEmail, $otp)) {
                    $error = "Failed to generate verification code. Please try again.";
                } else {
                    
                    $emailResult = sendOTPEmail($userEmail, $otp, $userName);
                    
                    if (!$emailResult['success']) {
                        $error = "Failed to send verification email: " . ($emailResult['error'] ?? 'Unknown error') . ". Please try again later.";
                    } else {

                        $_SESSION['pending_user_id'] = $userId;
                        $_SESSION['pending_user_type'] = $user_type;
                        $_SESSION['pending_user_name'] = $userName;
                        $_SESSION['pending_user_email'] = $userEmail;
                        $_SESSION['pending_redirect'] = $redirect;
                        $_SESSION['last_login_expired'] = $lastLoginExpired; 
                        $_SESSION['days_since_login'] = $daysSince; 

                        if ($user_type === 'student') {
                            $_SESSION['pending_user_national_id'] = $user['National_ID'] ?? '';
                            $_SESSION['pending_user_student_code'] = $user['Student_Code'] ?? '';
                        } elseif ($user_type === 'parent') {
                            $_SESSION['pending_user_national_id'] = $user['National_ID'] ?? '';
                        }

                        header("Location: otp_page.php");
                        exit();
                    }
                }
            } else {
                $error = "Invalid email/ID or password. Please try again.";
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $error = "Login failed. Please try again later.";
        } catch (Exception $e) {
            error_log("OTP system error: " . $e->getMessage());
            $error = "System error. Please try again later.";
        }
    } else {
        $error = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="signIn.css">
</head>
<body>
    <div class="floating-emoji">🎒</div>
    <div class="floating-emoji">📚</div>
    <div class="floating-emoji">✏️</div>
    <div class="floating-emoji">🎨</div>

    <div class="login-container">
        <a href="homepage.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            <span>Back to Home</span>
        </a>

        <div class="login-header">
            <div class="logo">HUDURY</div>
            <h1>Welcome Back! 👋</h1>
            <p>Sign in to access your dashboard</p>
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

        <form method="POST" action="signin.php">
            <div class="form-group">
                <label for="user_type">I am a:</label>
                <div class="user-type-selector">
                    <label class="user-type-option">
                        <input type="radio" name="user_type" value="student" checked>
                        <div class="user-type-label">
                            <div class="user-type-icon">👨‍🎓</div>
                            <div class="user-type-text">Student</div>
                        </div>
                    </label>
                    <label class="user-type-option">
                        <input type="radio" name="user_type" value="teacher">
                        <div class="user-type-label">
                            <div class="user-type-icon">👩‍🏫</div>
                            <div class="user-type-text">Teacher</div>
                        </div>
                    </label>
                    <label class="user-type-option">
                        <input type="radio" name="user_type" value="parent">
                        <div class="user-type-label">
                            <div class="user-type-icon">👨‍👩‍👧</div>
                            <div class="user-type-text">Parent</div>
                        </div>
                    </label>
                    <label class="user-type-option">
                        <input type="radio" name="user_type" value="admin">
                        <div class="user-type-label">
                            <div class="user-type-icon">👨‍💼</div>
                            <div class="user-type-text">Admin</div>
                        </div>
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label for="email_or_id" id="email_label">
                    <i class="fas fa-user"></i> <span id="email_label_text">Email / National ID</span>
                </label>
                <input 
                    type="text" 
                    id="email_or_id" 
                    name="email_or_id" 
                    placeholder="Enter your email or national ID"
                    required
                    autocomplete="username"
                >
            </div>

            <div class="form-group">
                <label for="password" id="password_label">
                    <i class="fas fa-lock"></i> <span id="password_label_text">Password</span>
                </label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    placeholder="Enter your password"
                    required
                    autocomplete="current-password"
                >
            </div>

            <button type="submit" class="submit-btn">
                Sign In 🚀
            </button>
        </form>

        <div class="forgot-password">
            <a href="forgot-password.php">Forgot your password?</a>
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
            const emailOrId = document.getElementById('email_or_id').value.trim();
            const password = document.getElementById('password').value;

            if (!emailOrId || !password) {
                e.preventDefault();
                alert('Please fill in all fields.');
                return false;
            }
        });
    </script>
</body>
</html>

