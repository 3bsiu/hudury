
window.toggleQuickMenu = function() {
    const dropdown = document.getElementById('quickMenuDropdown');
    const notifications = document.getElementById('notificationsDropdown');
    const overlay = document.getElementById('dropdownOverlay');
    const header = document.getElementById('unifiedHeader');
    
    if (dropdown) {
        const isActive = dropdown.classList.toggle('active');

        if (window.innerWidth <= 768) {
            if (overlay) {
                overlay.classList.toggle('active', isActive);
            }
            if (isActive) {
                document.body.style.overflow = 'hidden';
                
                if (header) {
                    const headerHeight = header.offsetHeight;
                    dropdown.style.top = (headerHeight + 8) + 'px';
                }
            } else {
                document.body.style.overflow = '';
            }
        }
    }

    if (notifications) {
        notifications.classList.remove('active');
        if (overlay && window.innerWidth <= 768) {
            overlay.classList.remove('active');
        }
    }
};

window.toggleNotificationsDropdown = function() {
    const dropdown = document.getElementById('notificationsDropdown');
    const quickMenu = document.getElementById('quickMenuDropdown');
    const overlay = document.getElementById('dropdownOverlay');
    const header = document.getElementById('unifiedHeader');
    
    if (dropdown) {
        const isActive = dropdown.classList.toggle('active');

        if (window.innerWidth <= 768) {
            if (overlay) {
                overlay.classList.toggle('active', isActive);
            }
            if (isActive) {
                document.body.style.overflow = 'hidden';
                
                if (header) {
                    const headerHeight = header.offsetHeight;
                    dropdown.style.top = (headerHeight + 8) + 'px';
                }
            } else {
                document.body.style.overflow = '';
            }
        }
    }

    if (quickMenu) {
        quickMenu.classList.remove('active');
        if (overlay && window.innerWidth <= 768) {
            overlay.classList.remove('active');
        }
    }
};

window.closeAllDropdowns = function() {
    const dropdown = document.getElementById('notificationsDropdown');
    const quickMenu = document.getElementById('quickMenuDropdown');
    const overlay = document.getElementById('dropdownOverlay');
    
    if (dropdown) dropdown.classList.remove('active');
    if (quickMenu) quickMenu.classList.remove('active');
    if (overlay) overlay.classList.remove('active');
    document.body.style.overflow = '';
}

function handleNotificationClick(element, notifId) {
    if (!element || !notifId) return;

    element.classList.remove('unread');
    element.classList.add('read');

    const indicator = element.querySelector('.notification-unread-indicator');
    if (indicator) {
        indicator.remove();
    }

    markNotificationAsRead(notifId);

    updateNotificationBadge();
}

function markNotificationAsRead(notifId) {
    if (!notifId) return;

    const currentPath = window.location.pathname;
    let ajaxPath = '../includes/notification-ajax.php';

    if (currentPath.includes('/Teacher/') || currentPath.includes('/Student/') || currentPath.includes('/Parent/')) {
        ajaxPath = '../includes/notification-ajax.php';
    } else {
        ajaxPath = 'includes/notification-ajax.php';
    }
    
    fetch(ajaxPath, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=markAsRead&notif_id=${notifId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Notification marked as read');
        } else {
            console.error('Error marking notification as read:', data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

function markAllNotificationsAsRead() {
    const unreadItems = document.querySelectorAll('.notification-item.unread');
    
    if (unreadItems.length === 0) return;

    const unreadIds = Array.from(unreadItems).map(item => {
        return item.getAttribute('data-notif-id');
    });

    unreadItems.forEach(item => {
        item.classList.remove('unread');
        item.classList.add('read');
        const indicator = item.querySelector('.notification-unread-indicator');
        if (indicator) {
            indicator.remove();
        }
    });

    const currentPath = window.location.pathname;
    let ajaxPath = '../includes/notification-ajax.php';

    if (currentPath.includes('/Teacher/') || currentPath.includes('/Student/') || currentPath.includes('/Parent/')) {
        ajaxPath = '../includes/notification-ajax.php';
    } else {
        ajaxPath = 'includes/notification-ajax.php';
    }

    fetch(ajaxPath, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=markAllAsRead&notif_ids=${unreadIds.join(',')}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('All notifications marked as read');
            updateNotificationBadge();

            const markAllBtn = document.querySelector('.mark-all-read-btn');
            if (markAllBtn) {
                markAllBtn.style.display = 'none';
            }
        } else {
            console.error('Error marking all notifications as read:', data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

function updateNotificationBadge() {
    const unreadCount = document.querySelectorAll('.notification-item.unread').length;
    const badge = document.getElementById('notificationBadge');
    
    if (badge) {
        if (unreadCount > 0) {
            badge.textContent = unreadCount;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    }
};

window.openProfile = function() {
    
    const path = window.location.pathname;
    let profileUrl = '';
    
    if (path.includes('/Teacher/')) {
        profileUrl = 'notifications-and-settings.php';
    } else if (path.includes('/Student/')) {
        profileUrl = 'notifications-and-settings.php';
    } else if (path.includes('/Parent/')) {
        profileUrl = 'notifications-and-settings.php';
    } else if (path.includes('/Admin/')) {
        
        profileUrl = 'admin-dashboard.php';
    }
    
    if (profileUrl) {
        window.location.href = profileUrl;
    }
};

function toggleLanguage() {
    const currentLang = document.documentElement.lang || 'en';
    const newLang = currentLang === 'en' ? 'ar' : 'en';

    const currentPath = window.location.pathname;
    let ajaxPath = '../includes/language-ajax.php';

    if (currentPath.includes('/Teacher/') || currentPath.includes('/Student/') || currentPath.includes('/Parent/') || currentPath.includes('/Admin/')) {
        ajaxPath = '../includes/language-ajax.php';
    } else {
        ajaxPath = 'includes/language-ajax.php';
    }

    fetch(ajaxPath, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `language=${newLang}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            
            window.location.reload();
        }
    })
    .catch(error => {
        console.error('Error changing language:', error);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    
    updateNotificationBadge();

    document.addEventListener('click', function(event) {
        const header = document.getElementById('unifiedHeader');
        if (!header) return;
        
        const dropdown = document.getElementById('notificationsDropdown');
        const quickMenu = document.getElementById('quickMenuDropdown');
        const notificationBtn = event.target.closest('.notification-btn');
        const quickMenuBtn = event.target.closest('.quick-menu-btn');
        
        if (window.innerWidth <= 768) {
            
            const overlay = document.getElementById('dropdownOverlay');
            if (overlay && event.target === overlay) {
                closeAllDropdowns();
            }
        } else {
            
            if (dropdown && !dropdown.contains(event.target) && !notificationBtn) {
                dropdown.classList.remove('active');
            }
            if (quickMenu && !quickMenu.contains(event.target) && !quickMenuBtn) {
                quickMenu.classList.remove('active');
            }
        }
    });

    const currentLang = document.documentElement.lang || 'en';
    const isRTL = currentLang === 'ar';
    document.documentElement.dir = isRTL ? 'rtl' : 'ltr';

    const langElements = document.querySelectorAll('[data-en][data-ar]');
    langElements.forEach(element => {
        if (isRTL) {
            element.textContent = element.getAttribute('data-ar');
        } else {
            element.textContent = element.getAttribute('data-en');
        }
    });
});

