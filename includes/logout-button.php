<?php

$currentPath = $_SERVER['PHP_SELF'] ?? '';
$logoutPath = 'logout.php'; 

if (strpos($currentPath, '/Admin/') !== false || 
    strpos($currentPath, '/Student/') !== false || 
    strpos($currentPath, '/Parent/') !== false || 
    strpos($currentPath, '/Teacher/') !== false) {
    $logoutPath = '../logout.php';
} elseif (strpos($currentPath, '/includes/') !== false) {
    $logoutPath = '../logout.php';
}
?>
<style>
    .logout-btn {
        background: rgba(255, 255, 255, 0.15);
        color: white;
        border: 2px solid rgba(255, 255, 255, 0.3);
        padding: 0.6rem 1.2rem;
        border-radius: 25px;
        cursor: pointer;
        font-weight: 600;
        font-size: 0.9rem;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        transition: all 0.3s;
        text-decoration: none;
        font-family: 'Nunito', sans-serif;
        margin-left: 1rem;
        white-space: nowrap;
    }
    
    .logout-btn:hover {
        background: rgba(255, 255, 255, 0.25);
        border-color: rgba(255, 255, 255, 0.5);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }
    
    .logout-btn:active {
        transform: translateY(0);
    }
    
    .logout-btn i {
        font-size: 1rem;
    }

    @media (max-width: 768px) {
        .logout-btn {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            margin-left: 0.5rem;
        }
        
        .logout-btn span {
            display: none;
        }
        
        .logout-btn i {
            font-size: 1.1rem;
        }
    }

    .header-content {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }
</style>
<a href="<?php echo htmlspecialchars($logoutPath); ?>" 
   class="logout-btn" 
   onclick="return confirmLogout(event)"
   title="Logout"
   data-en="Logout" 
   data-ar="تسجيل الخروج">
    <i class="fas fa-sign-out-alt"></i>
    <span data-en="Logout" data-ar="تسجيل الخروج">Logout</span>
</a>
<script>
    function confirmLogout(event) {
        const confirmed = confirm(
            currentLanguage === 'en' 
                ? 'Are you sure you want to logout?' 
                : 'هل أنت متأكد أنك تريد تسجيل الخروج؟'
        );
        if (!confirmed) {
            event.preventDefault();
            return false;
        }
        return true;
    }
</script>

