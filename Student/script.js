let currentLanguage = 'en';
let chatOpen = false;
let assignments = [];

document.addEventListener('DOMContentLoaded', () => {
    const assignmentCards = document.querySelectorAll('.assignment-card');
    assignmentCards.forEach(card => {
        assignments.push({
            element: card,
            material: card.dataset.material,
            status: card.dataset.status,
            date: card.dataset.date
        });
    });
});

function toggleLanguage() {
    currentLanguage = currentLanguage === 'en' ? 'ar' : 'en';
    document.documentElement.lang = currentLanguage;
    document.documentElement.dir = currentLanguage === 'ar' ? 'rtl' : 'ltr';
    
    document.querySelectorAll('[data-en][data-ar]').forEach(element => {
        const text = element.getAttribute(`data-${currentLanguage}`);
        if (text) {
            element.textContent = text;
        }
    });
}

function toggleMobileMenu() {
    const mobileMenu = document.getElementById('mobileMenu');
    const menuToggle = document.querySelector('.menu-toggle i');
    mobileMenu.classList.toggle('active');
    if (mobileMenu.classList.contains('active')) {
        menuToggle.classList.remove('fa-bars');
        menuToggle.classList.add('fa-times');
        document.body.style.overflow = 'hidden';
    } else {
        menuToggle.classList.remove('fa-times');
        menuToggle.classList.add('fa-bars');
        document.body.style.overflow = '';
    }
}

document.addEventListener('click', function(event) {
    const mobileMenu = document.getElementById('mobileMenu');
    const menuToggle = document.querySelector('.menu-toggle');
    if (mobileMenu && menuToggle && mobileMenu.classList.contains('active') && !mobileMenu.querySelector('.mobile-menu-content').contains(event.target) && !menuToggle.contains(event.target)) {
        toggleMobileMenu();
    }
});

function toggleNotificationsDropdown() {
    const dropdown = document.getElementById('notificationsDropdown');
    dropdown.classList.toggle('active');
}

function handleNotificationClick(element) {
    element.classList.remove('unread');
    updateNotificationCount();
}

function markAllAsRead() {
    const unreadItems = document.querySelectorAll('.notification-dropdown-item.unread');
    unreadItems.forEach(item => {
        item.classList.remove('unread');
    });
    updateNotificationCount();
}

function updateNotificationCount() {
    const unreadCount = document.querySelectorAll('.notification-dropdown-item.unread').length;
    const countBadge = document.getElementById('notificationCount');
    const countBadgeMobile = document.getElementById('notificationCountMobile');
    
    if (unreadCount > 0) {
        if (countBadge) {
            countBadge.textContent = unreadCount;
            countBadge.style.display = 'flex';
        }
        if (countBadgeMobile) {
            countBadgeMobile.textContent = unreadCount;
            countBadgeMobile.style.display = 'flex';
        }
    } else {
        if (countBadge) countBadge.style.display = 'none';
        if (countBadgeMobile) countBadgeMobile.style.display = 'none';
    }
}

document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('notificationsDropdown');
    const notificationBtn = document.querySelector('.notification-btn');
    if (dropdown && notificationBtn && !dropdown.contains(event.target) && !notificationBtn.contains(event.target)) {
        dropdown.classList.remove('active');
    }
});

        function openProfileSettings() {
            window.location.href = 'notifications-and-settings.php#profile';
        }

function closeProfileSettings() {
    document.getElementById('profileModal').style.display = 'none';
}

function handleProfileUpdate(event) {
    event.preventDefault();
    const phone = document.getElementById('profilePhone').value;
    const email = document.getElementById('profileEmail').value;
    const newPassword = document.getElementById('newPassword').value;
    const confirmPassword = document.getElementById('confirmPassword').value;

    if (newPassword !== confirmPassword) {
        alert(currentLanguage === 'en' ? 'Passwords do not match!' : 'كلمات المرور غير متطابقة!');
        return;
    }

    alert(currentLanguage === 'en' ? 'Profile updated successfully!' : 'تم تحديث الملف الشخصي بنجاح!');
    closeProfileSettings();
}

        function openSettings() {
            window.location.href = 'notifications-and-settings.php';
        }

function closeSettings() {
    document.getElementById('settingsModal').style.display = 'none';
}

function toggleSetting(element) {
    element.classList.toggle('active');
}

function changeTheme(color, element) {
    document.documentElement.style.setProperty('--primary-color', color);
    document.querySelectorAll('.theme-color').forEach(el => el.classList.remove('active'));
    element.classList.add('active');
}

function saveSettings() {
    alert(currentLanguage === 'en' ? 'Settings saved successfully!' : 'تم حفظ الإعدادات بنجاح!');
    closeSettings();
}

function filterAssignments() {
    const materialFilter = document.getElementById('filterMaterial').value;
    const statusFilter = document.getElementById('filterStatus').value;
    const assignmentList = document.getElementById('assignmentList');
    const cards = assignmentList.querySelectorAll('.assignment-card');

    cards.forEach(card => {
        const material = card.dataset.material;
        const status = card.dataset.status;
        let show = true;

        if (materialFilter !== 'all' && material !== materialFilter) {
            show = false;
        }
        if (statusFilter !== 'all' && status !== statusFilter) {
            show = false;
        }

        card.style.display = show ? 'block' : 'none';
    });
}

function sortAssignments() {
    const sortBy = document.getElementById('sortAssignments').value;
    const assignmentList = document.getElementById('assignmentList');
    const cards = Array.from(assignmentList.querySelectorAll('.assignment-card'));

    cards.sort((a, b) => {
        if (sortBy === 'date') {
            return new Date(a.dataset.date) - new Date(b.dataset.date);
        } else if (sortBy === 'material') {
            return a.dataset.material.localeCompare(b.dataset.material);
        } else if (sortBy === 'status') {
            return a.dataset.status.localeCompare(b.dataset.status);
        }
        return 0;
    });

    cards.forEach(card => assignmentList.appendChild(card));
}

function toggleChat() {
    const chatWindow = document.getElementById('chatWindow');
    chatOpen = !chatOpen;
    if (chatOpen) {
        chatWindow.classList.add('active');
    } else {
        chatWindow.classList.remove('active');
    }
}

function sendChatMessage() {
    const input = document.getElementById('chatInput');
    const message = input.value.trim();
    if (!message) return;

    const messagesContainer = document.getElementById('chatMessages');
    const messageDiv = document.createElement('div');
    messageDiv.className = 'chat-message sent';
    messageDiv.innerHTML = `
        <div class="chat-avatar">👦</div>
        <div class="chat-bubble">
            <div>${message}</div>
        </div>
    `;
    messagesContainer.appendChild(messageDiv);
    input.value = '';
    messagesContainer.scrollTop = messagesContainer.scrollHeight;

    setTimeout(() => {
        const responseDiv = document.createElement('div');
        responseDiv.className = 'chat-message';
        responseDiv.innerHTML = `
            <div class="chat-avatar">👩‍🏫</div>
            <div class="chat-bubble">
                <div style="font-weight: 700; margin-bottom: 0.3rem;">Ms. Sarah</div>
                <div>${currentLanguage === 'en' ? 'Thank you for your message! I will get back to you soon.' : 'شكراً لرسالتك! سأعود إليك قريباً.'}</div>
            </div>
        `;
        messagesContainer.appendChild(responseDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }, 1000);
}

function handleChatKeyPress(event) {
    if (event.key === 'Enter') {
        sendChatMessage();
    }
}

function openSubmitModal(assignmentId) {
    const modal = document.getElementById('submitModal');
    const titleInput = document.getElementById('assignmentTitle');
    
    const titles = {
        'math1': currentLanguage === 'en' ? 'Mathematics Homework - Chapter 5' : 'واجب الرياضيات - الفصل 5',
        'english1': currentLanguage === 'en' ? 'English Essay - My Hero' : 'مقالة إنجليزية - بطلي',
        'arabic1': currentLanguage === 'en' ? 'Arabic Reading - Chapter 3' : 'قراءة عربية - الفصل 3'
    };
    
    titleInput.value = titles[assignmentId] || '';
    modal.style.display = 'flex';
}

function closeSubmitModal() {
    document.getElementById('submitModal').style.display = 'none';
}

function handleAssignmentSubmit(event) {
    event.preventDefault();
    alert(currentLanguage === 'en' ? 'Assignment submitted successfully!' : 'تم تقديم الواجب بنجاح!');
    closeSubmitModal();
}

function viewAllAssignments() {
    window.location.href = 'my-assignments.php';
}

function toggleSideMenu() {
    const menu = document.getElementById('sideMenuMobile');
    const overlay = document.getElementById('sideMenuOverlay');
    
    if (menu && overlay) {
        menu.classList.toggle('active');
        overlay.classList.toggle('active');
        
        if (menu.classList.contains('active')) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    }
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const menu = document.getElementById('sideMenuMobile');
        if (menu && menu.classList.contains('active')) {
            toggleSideMenu();
        }
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const menuItems = document.querySelectorAll('.side-menu-item');
    menuItems.forEach(item => {
        item.addEventListener('click', function(e) {

            if (!this.tagName === 'A') {
                e.stopPropagation();
            }
        });
    });
});

window.addEventListener('load', () => {
    document.querySelectorAll('.progress-fill').forEach(bar => {
        const width = bar.style.width;
        bar.style.width = '0%';
        setTimeout(() => {
            bar.style.width = width;
        }, 100);
    });
    
    updateNotificationCount();
});

window.onclick = function(event) {
    const modals = ['submitModal', 'profileModal', 'settingsModal'];
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (event.target === modal) {
            if (modalId === 'submitModal') closeSubmitModal();
            if (modalId === 'profileModal') closeProfileSettings();
            if (modalId === 'settingsModal') closeSettings();
        }
    });
}