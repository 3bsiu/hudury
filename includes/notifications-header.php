<?php

if (!isset($notifications)) {
    
    if (!function_exists('getTimeAgo')) {
        function getTimeAgo($datetime) {
            $now = new DateTime();
            $diff = $now->diff($datetime);
            
            if ($diff->y > 0) {
                return $diff->y . ' ' . ($diff->y == 1 ? 'year' : 'years') . ' ago';
            } elseif ($diff->m > 0) {
                return $diff->m . ' ' . ($diff->m == 1 ? 'month' : 'months') . ' ago';
            } elseif ($diff->d > 0) {
                return $diff->d . ' ' . ($diff->d == 1 ? 'day' : 'days') . ' ago';
            } elseif ($diff->h > 0) {
                return $diff->h . ' ' . ($diff->h == 1 ? 'hour' : 'hours') . ' ago';
            } elseif ($diff->i > 0) {
                return $diff->i . ' ' . ($diff->i == 1 ? 'minute' : 'minutes') . ' ago';
            } else {
                return 'Just now';
            }
        }
    }

    $currentUserType = isset($_SESSION['user_type']) ? $_SESSION['user_type'] : null;
    $currentUserId = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;

    $notifications = [];
    
    if ($currentUserType && $currentUserId) {
        try {
            $conditions = ["Target_Role = 'All'"];
            $params = [];
            
            if ($currentUserType === 'student') {
                $conditions[] = "Target_Role = 'Student'";

                $stmt = $pdo->prepare("SELECT Class_ID FROM student WHERE Student_ID = ?");
                $stmt->execute([$currentUserId]);
                $studentData = $stmt->fetch(PDO::FETCH_ASSOC);
                $currentStudentClassId = $studentData['Class_ID'] ?? null;
                
                if ($currentUserId) {
                    $conditions[] = "(Target_Role = 'Student' AND Target_Student_ID = ?)";
                    $params[] = $currentUserId;
                }
                if ($currentStudentClassId) {
                    $conditions[] = "(Target_Role = 'Student' AND Target_Class_ID = ?)";
                    $params[] = $currentStudentClassId;
                }
            } elseif ($currentUserType === 'parent') {
                $conditions[] = "Target_Role = 'Parent'";

                $stmt = $pdo->prepare("SELECT Student_ID FROM parent_student_relationship WHERE Parent_ID = ?");
                $stmt->execute([$currentUserId]);
                $linkedStudentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (!empty($linkedStudentIds)) {
                    $placeholders = implode(',', array_fill(0, count($linkedStudentIds), '?'));
                    $conditions[] = "(Target_Role = 'Student' AND Target_Student_ID IN ($placeholders))";
                    $params = array_merge($params, $linkedStudentIds);
                }
            } elseif ($currentUserType === 'teacher') {
                $conditions[] = "Target_Role = 'Teacher'";
            }
            
            $query = "
                SELECT Notif_ID, Title, Content, Date_Sent, Sender_Type, Target_Role, Target_Class_ID, Target_Student_ID
                FROM notification
                WHERE (" . implode(' OR ', $conditions) . ")
                ORDER BY Date_Sent DESC
                LIMIT 20
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching notifications: " . $e->getMessage());
            $notifications = [];
        }
    }
}
?>

<button class="notification-btn" onclick="toggleNotificationsDropdown()" title="Notifications">
    <i class="fas fa-bell"></i>
    <span data-en="Notifications" data-ar="الإشعارات">Notifications</span>
    <span class="notification-badge-count" id="notificationCount"><?php echo count($notifications); ?></span>
</button>
<div class="notifications-dropdown" id="notificationsDropdown">
    <div class="notifications-dropdown-header">
        <h3 data-en="Notifications" data-ar="الإشعارات">Notifications</h3>
        <button onclick="markAllAsRead()" data-en="Mark all as read" data-ar="تعليم الكل كمقروء">Mark all as read</button>
    </div>
    <div class="notifications-dropdown-content" id="notificationsContent">
        <?php if (empty($notifications)): ?>
            <div style="text-align: center; padding: 2rem; color: #666;">
                <div style="font-size: 2rem; margin-bottom: 0.5rem;">🔔</div>
                <div data-en="No notifications" data-ar="لا توجد إشعارات">No notifications</div>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $notif): ?>
                <?php
                $dateSent = new DateTime($notif['Date_Sent']);
                $timeAgo = getTimeAgo($dateSent);
                $icon = '🔔'; 
                if (stripos($notif['Title'], 'assignment') !== false || stripos($notif['Title'], 'واجب') !== false) {
                    $icon = '📝';
                } elseif (stripos($notif['Title'], 'grade') !== false || stripos($notif['Title'], 'درجة') !== false) {
                    $icon = '⭐';
                } elseif (stripos($notif['Title'], 'exam') !== false || stripos($notif['Title'], 'امتحان') !== false) {
                    $icon = '📅';
                } elseif (stripos($notif['Title'], 'message') !== false || stripos($notif['Title'], 'رسالة') !== false) {
                    $icon = '💬';
                }
                ?>
                <div class="notification-dropdown-item unread" onclick="handleNotificationClick(this)">
                    <div class="notification-dropdown-icon"><?php echo $icon; ?></div>
                    <div class="notification-dropdown-content-text">
                        <div class="notification-dropdown-title"><?php echo htmlspecialchars($notif['Title']); ?></div>
                        <div class="notification-dropdown-message"><?php echo htmlspecialchars(substr($notif['Content'], 0, 100)) . (strlen($notif['Content']) > 100 ? '...' : ''); ?></div>
                        <div class="notification-dropdown-time"><?php echo $timeAgo; ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
    function toggleNotificationsDropdown() {
        const dropdown = document.getElementById('notificationsDropdown');
        if (dropdown) {
            dropdown.classList.toggle('active');
        }
    }
    
    function handleNotificationClick(element) {
        if (element) {
            element.classList.remove('unread');
            updateNotificationBadge();
        }
    }
    
    function markAllAsRead() {
        document.querySelectorAll('.notification-dropdown-item').forEach(item => {
            item.classList.remove('unread');
        });
        updateNotificationBadge();
    }
    
    function updateNotificationBadge() {
        const unreadCount = document.querySelectorAll('.notification-dropdown-item.unread').length;
        const badge = document.getElementById('notificationCount');
        if (badge) {
            if (unreadCount > 0) {
                badge.textContent = unreadCount;
                badge.style.display = 'inline-block';
            } else {
                badge.style.display = 'none';
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateNotificationBadge();

        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('notificationsDropdown');
            const btn = event.target.closest('.notification-btn');
            if (dropdown && !dropdown.contains(event.target) && !btn) {
                dropdown.classList.remove('active');
            }
        });
    });
</script>

