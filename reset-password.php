<?php
session_start();
require_once 'db.php';
require_once 'includes/otp-handler.php';

$error = '';
$success = '';

if (!isset($_SESSION['password_reset_user_id']) || !isset($_SESSION['password_reset_user_type']) || !isset($_SESSION['password_reset_otp_verified']) || $_SESSION['password_reset_otp_verified'] !== true) {
    
    header("Location: forgot-password.php");
    exit();
}

$userId = $_SESSION['password_reset_user_id'];
$userType = $_SESSION['password_reset_user_type'];
$userEmail = $_SESSION['password_reset_user_email'] ?? '';
$userName = $_SESSION['password_reset_user_name'] ?? 'User';

$userId = (int)$userId;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($newPassword) || empty($confirmPassword)) {
        $error = "Please fill in all fields.";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "Passwords do not match. Please try again.";
    } elseif (strlen($newPassword) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        try {
            
            $table = '';
            $idField = '';
            $passwordField = '';
            
            switch($userType) {
                case 'student':
                    $table = 'student';
                    $idField = 'Student_ID';
                    $passwordField = 'Password';
                    break;
                case 'teacher':
                    $table = 'teacher';
                    $idField = 'Teacher_ID';
                    $passwordField = 'Password_Hash';
                    break;
                case 'parent':
                    $table = 'parent';
                    $idField = 'Parent_ID';
                    $passwordField = 'Password_Hash';
                    break;
            }

            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            $query = "UPDATE $table SET $passwordField = ? WHERE $idField = ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$hashedPassword, $userId]);
            
            if ($stmt->rowCount() > 0) {

                invalidateAllUserOTPs($pdo, $userId, $userType);

                unset($_SESSION['password_reset_user_id']);
                unset($_SESSION['password_reset_user_type']);
                unset($_SESSION['password_reset_user_email']);
                unset($_SESSION['password_reset_user_name']);
                unset($_SESSION['password_reset_otp_verified']);
                unset($_SESSION['password_reset_otp_sent']);

                header("Location: signin.php?password_reset=success");
                exit();
            } else {
                $error = "Failed to update password. Please try again.";
            }
        } catch (PDOException $e) {
            error_log("Password reset error: " . $e->getMessage());
            $error = "An error occurred while updating your password. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="signIn.css">
    <style>
        .password-strength {
            margin-top: 5px;
            font-size: 12px;
        }
        .password-strength.weak {
            color: #C44569;
        }
        .password-strength.medium {
            color: #FFD93D;
        }
        .password-strength.strong {
            color: #6BCB77;
        }
        .password-requirements {
            background-color: #f0f0f0;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #666;
        }
        .password-requirements ul {
            margin: 5px 0 0 20px;
            padding: 0;
        }
        .password-requirements li {
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class="floating-emoji">🔐</div>
    <div class="floating-emoji">🔑</div>
    <div class="floating-emoji">✨</div>
    <div class="floating-emoji">🔒</div>

    <div class="login-container">
        <div class="login-header">
            <div class="logo">HUDURY</div>
            <h1>Reset Your Password 🔐</h1>
            <p>Create a new secure password for your account</p>
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

        <div class="password-requirements">
            <strong><i class="fas fa-info-circle"></i> Password Requirements:</strong>
            <ul>
                <li>At least 6 characters long</li>
                <li>Use a combination of letters, numbers, and symbols for better security</li>
            </ul>
        </div>

        <form method="POST" action="reset-password.php" id="resetForm">
            <div class="form-group">
                <label for="new_password">
                    <i class="fas fa-lock"></i> New Password
                </label>
                <input 
                    type="password" 
                    id="new_password" 
                    name="new_password" 
                    placeholder="Enter your new password"
                    required
                    autocomplete="new-password"
                    minlength="6"
                >
                <div id="password-strength" class="password-strength"></div>
            </div>

            <div class="form-group">
                <label for="confirm_password">
                    <i class="fas fa-lock"></i> Confirm New Password
                </label>
                <input 
                    type="password" 
                    id="confirm_password" 
                    name="confirm_password" 
                    placeholder="Confirm your new password"
                    required
                    autocomplete="new-password"
                    minlength="6"
                >
                <div id="password-match" style="margin-top: 5px; font-size: 12px;"></div>
            </div>

            <button type="submit" class="submit-btn">
                Reset Password 🔐
            </button>
        </form>

        <div style="text-align: center; margin-top: 1.5rem;">
            <a href="signin.php" style="color: #FF6B9D; text-decoration: none; font-weight: 600;">
                <i class="fas fa-arrow-left"></i> Back to Sign In
            </a>
        </div>
    </div>

    <script>
        const newPasswordInput = document.getElementById('new_password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const passwordStrength = document.getElementById('password-strength');
        const passwordMatch = document.getElementById('password-match');
        const resetForm = document.getElementById('resetForm');

        function checkPasswordStrength(password) {
            if (password.length === 0) {
                passwordStrength.textContent = '';
                passwordStrength.className = 'password-strength';
                return;
            }

            let strength = 0;
            if (password.length >= 6) strength++;
            if (password.length >= 8) strength++;
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
            if (/\d/.test(password)) strength++;
            if (/[^a-zA-Z\d]/.test(password)) strength++;

            if (strength <= 2) {
                passwordStrength.textContent = 'Weak password';
                passwordStrength.className = 'password-strength weak';
            } else if (strength <= 3) {
                passwordStrength.textContent = 'Medium password';
                passwordStrength.className = 'password-strength medium';
            } else {
                passwordStrength.textContent = 'Strong password';
                passwordStrength.className = 'password-strength strong';
            }
        }

        function checkPasswordMatch() {
            const newPassword = newPasswordInput.value;
            const confirmPassword = confirmPasswordInput.value;

            if (confirmPassword.length === 0) {
                passwordMatch.textContent = '';
                passwordMatch.style.color = '';
                return;
            }

            if (newPassword === confirmPassword) {
                passwordMatch.innerHTML = '<i class="fas fa-check-circle"></i> Passwords match';
                passwordMatch.style.color = '#6BCB77';
            } else {
                passwordMatch.innerHTML = '<i class="fas fa-times-circle"></i> Passwords do not match';
                passwordMatch.style.color = '#C44569';
            }
        }

        newPasswordInput.addEventListener('input', function() {
            checkPasswordStrength(this.value);
            checkPasswordMatch();
        });

        confirmPasswordInput.addEventListener('input', function() {
            checkPasswordMatch();
        });

        resetForm.addEventListener('submit', function(e) {
            const newPassword = newPasswordInput.value;
            const confirmPassword = confirmPasswordInput.value;

            if (newPassword.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters long.');
                return false;
            }

            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match. Please try again.');
                return false;
            }
        });
    </script>
</body>
</html>



