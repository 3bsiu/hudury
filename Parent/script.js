let currentLanguage = 'en';

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

        function handleLeaveRequest(event) {
            event.preventDefault();
            alert(currentLanguage === 'en' ? 'Leave request submitted successfully!' : 'تم إرسال طلب الإجازة بنجاح!');
        }

        let currentTeacher = 'sarah';

        function selectTeacher(teacherId) {
            
            currentTeacher = teacherId;

            document.querySelectorAll('.teacher-item').forEach(item => {
                item.classList.remove('active');
            });
            document.querySelector(`.teacher-item[data-teacher="${teacherId}"]`).classList.add('active');

            document.querySelectorAll('.chat-messages').forEach(chat => {
                chat.style.display = 'none';
            });

            const activeChat = document.getElementById(`chatMessages${teacherId.charAt(0).toUpperCase() + teacherId.slice(1)}`);
            if (activeChat) {
                activeChat.style.display = 'block';
            }

            const teacherItem = document.querySelector(`.teacher-item[data-teacher="${teacherId}"]`);
            const teacherName = teacherItem.querySelector('.teacher-name').textContent;
            const teacherSubject = teacherItem.querySelector('.teacher-subject').textContent;
            const teacherAvatar = teacherItem.querySelector('.teacher-avatar').textContent;
            
            document.getElementById('activeTeacherName').textContent = teacherName;
            document.getElementById('activeTeacherSubject').textContent = teacherSubject;
            document.querySelector('.active-teacher-avatar').textContent = teacherAvatar;

            if (activeChat) {
                activeChat.scrollTop = activeChat.scrollHeight;
            }

            document.getElementById('chatInput').value = '';
        }

        function sendMessage() {
            const input = document.getElementById('chatInput');
            if (input.value.trim()) {
                const teacherId = currentTeacher;
                const messagesDiv = document.getElementById(`chatMessages${teacherId.charAt(0).toUpperCase() + teacherId.slice(1)}`);
                
                if (messagesDiv) {
                    const messageDiv = document.createElement('div');
                    messageDiv.className = 'message sent';
                    messageDiv.innerHTML = `
                        <div>${input.value}</div>
                        <div style="font-size: 0.8rem; margin-top: 0.5rem; opacity: 0.7;">Just now</div>
                    `;
                    messagesDiv.appendChild(messageDiv);
                    messagesDiv.scrollTop = messagesDiv.scrollHeight;

                    const teacherItem = document.querySelector(`.teacher-item[data-teacher="${teacherId}"]`);
                    const badge = teacherItem.querySelector('.teacher-badge');
                    if (badge && parseInt(badge.textContent) > 0) {
                        const count = parseInt(badge.textContent) - 1;
                        if (count > 0) {
                            badge.textContent = count;
                        } else {
                            badge.style.display = 'none';
                        }
                    }
                }
                
                input.value = '';
            }
        }

        function handleChatKeyPress(event) {
            if (event.key === 'Enter') {
                sendMessage();
            }
        }

        window.addEventListener('load', () => {
            document.querySelectorAll('.progress-fill').forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = width;
                }, 100);
            });
        });

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
            const btn = document.querySelector('.notification-btn');
            if (dropdown && btn && !dropdown.contains(event.target) && !btn.contains(event.target)) {
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
            document.querySelectorAll('.theme-color').forEach(c => c.classList.remove('active'));
            element.classList.add('active');
        }

        function saveSettings() {
            alert(currentLanguage === 'en' ? 'Settings saved successfully!' : 'تم حفظ الإعدادات بنجاح!');
            closeSettings();
        }

        function openFeedback() {
            document.getElementById('feedbackModal').style.display = 'flex';
        }

        function closeFeedback() {
            document.getElementById('feedbackModal').style.display = 'none';
        }

        function handleFeedbackSubmit(event) {
            event.preventDefault();
            const category = document.getElementById('feedbackCategory').value;
            const text = document.getElementById('feedbackText').value;
            
            alert(currentLanguage === 'en' ? 'Thank you for your feedback! It has been submitted anonymously.' : 'شكراً لملاحظاتك! تم إرسالها بشكل مجهول.');
            document.getElementById('feedbackText').value = '';
            document.getElementById('feedbackCategory').value = '';
            closeFeedback();
        }

        function exportGrades() {
            
            alert(currentLanguage === 'en' ? 'Grades exported successfully!' : 'تم تصدير الدرجات بنجاح!');
        }

        function viewFullAttendance() {
            alert(currentLanguage === 'en' ? 'Opening full attendance report...' : 'فتح تقرير الحضور الكامل...');
        }

        window.onclick = function(event) {
            const modals = ['profileModal', 'settingsModal', 'feedbackModal', 'absenceNoteModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    if (modalId === 'profileModal') closeProfileSettings();
                    if (modalId === 'settingsModal') closeSettings();
                    if (modalId === 'feedbackModal') closeFeedback();
                    if (modalId === 'absenceNoteModal') closeAbsenceNoteModal();
                }
            });
        }

        updateNotificationCount();

        function openAbsenceNoteModal(date, dayName) {
            const modal = document.getElementById('absenceNoteModal');
            const dateInput = document.getElementById('absenceDate');
            const dateDisplay = document.getElementById('absenceDateDisplay');
            
            dateInput.value = date;
            const dayNames = {
                'Sunday': { en: 'Sunday', ar: 'الأحد' },
                'Monday': { en: 'Monday', ar: 'الإثنين' },
                'Tuesday': { en: 'Tuesday', ar: 'الثلاثاء' },
                'Wednesday': { en: 'Wednesday', ar: 'الأربعاء' },
                'Thursday': { en: 'Thursday', ar: 'الخميس' },
                'Friday': { en: 'Friday', ar: 'الجمعة' }
            };
            
            const dayText = currentLanguage === 'en' ? dayNames[dayName].en : dayNames[dayName].ar;
            dateDisplay.textContent = `${dayText}, ${date}`;
            
            modal.style.display = 'flex';
        }

        function closeAbsenceNoteModal() {
            document.getElementById('absenceNoteModal').style.display = 'none';
            document.getElementById('absenceNoteForm').reset();
            document.getElementById('uploadFileInfo').style.display = 'none';
        }

        function handleFileSelect(event) {
            const file = event.target.files[0];
            const fileInfo = document.getElementById('uploadFileInfo');
            
            if (file) {
                const fileSize = (file.size / 1024).toFixed(2);
                fileInfo.style.display = 'block';
                fileInfo.innerHTML = `<i class="fas fa-check-circle"></i> ${file.name} (${fileSize} KB)`;
            } else {
                fileInfo.style.display = 'none';
            }
        }

        function handleAbsenceNoteSubmit(event) {
            event.preventDefault();
            
            const date = document.getElementById('absenceDate').value;
            const reason = document.getElementById('absenceReason').value;
            const notes = document.getElementById('absenceNotes').value;
            const file = document.getElementById('absenceFile').files[0];

            const dayCard = document.querySelector(`.attendance-day-card[data-date="${date}"]`);
            if (dayCard) {
                dayCard.classList.add('has-note');
                const noteDiv = dayCard.querySelector('.absence-note-prompt');
                if (noteDiv) {
                    noteDiv.style.display = 'none';
                }

                let noteDisplay = dayCard.querySelector('.day-note');
                if (!noteDisplay) {
                    noteDisplay = document.createElement('div');
                    noteDisplay.className = 'day-note';
                    dayCard.appendChild(noteDisplay);
                }
                
                const reasonTexts = {
                    'medical': currentLanguage === 'en' ? 'Medical Appointment / Illness' : 'موعد طبي / مرض',
                    'family': currentLanguage === 'en' ? 'Family Emergency' : 'طوارئ عائلية',
                    'personal': currentLanguage === 'en' ? 'Personal Reasons' : 'أسباب شخصية',
                    'other': currentLanguage === 'en' ? 'Other' : 'أخرى'
                };
                
                let noteContent = `<i class="fas fa-file-alt"></i> <span>${reasonTexts[reason]}`;
                if (notes) {
                    noteContent += ` - ${notes}`;
                }
                if (file) {
                    noteContent += ` (${currentLanguage === 'en' ? 'Document uploaded' : 'تم رفع المستند'})`;
                }
                noteContent += '</span>';
                noteDisplay.innerHTML = noteContent;
            }

            alert(currentLanguage === 'en' ? 'Absence note submitted successfully!' : 'تم إرسال ملاحظة الغياب بنجاح!');
            closeAbsenceNoteModal();
        }

        function filterAttendanceByMonth() {
            const selectedMonth = document.getElementById('monthFilter').value;
            const monthSections = document.querySelectorAll('.attendance-month-section');
            
            monthSections.forEach(section => {
                if (selectedMonth === 'all' || section.dataset.month === selectedMonth) {
                    section.style.display = 'block';
                    
                    section.style.opacity = '0';
                    section.style.transform = 'translateY(20px)';
                    setTimeout(() => {
                        section.style.transition = 'all 0.4s ease';
                        section.style.opacity = '1';
                        section.style.transform = 'translateY(0)';
                    }, 50);
                } else {
                    section.style.display = 'none';
                }
            });
        }

        if (window.innerWidth <= 768) {
            
            document.querySelectorAll('.attendance-day-card').forEach(card => {
                card.addEventListener('touchstart', function(e) {
                    const ripple = document.createElement('div');
                    ripple.style.cssText = `
                        position: absolute;
                        border-radius: 50%;
                        background: rgba(255,255,255,0.6);
                        width: 20px;
                        height: 20px;
                        left: ${e.touches[0].clientX - card.getBoundingClientRect().left - 10}px;
                        top: ${e.touches[0].clientY - card.getBoundingClientRect().top - 10}px;
                        pointer-events: none;
                        animation: ripple 0.6s ease-out;
                    `;
                    card.style.position = 'relative';
                    card.appendChild(ripple);
                    
                    setTimeout(() => ripple.remove(), 600);
                });
            });
        }

        if (!document.getElementById('mobile-attendance-styles')) {
            const style = document.createElement('style');
            style.id = 'mobile-attendance-styles';
            style.textContent = `
                @keyframes ripple {
                    to {
                        transform: scale(4);
                        opacity: 0;
                    }
                }
            `;
            document.head.appendChild(style);
        }

        function scrollToMonth(month) {
            const section = document.querySelector(`.attendance-month-section[data-month="${month}"]`);
            if (section) {
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        function openInstallmentsPage() {
            window.location.href = 'installments.php';
        }

        function toggleSideMenu() {
            const sideMenu = document.getElementById('sideMenuMobile');
            const overlay = document.getElementById('sideMenuOverlay');
            const body = document.body;
            
            if (sideMenu && overlay) {
                sideMenu.classList.toggle('active');
                overlay.classList.toggle('active');
                
                if (sideMenu.classList.contains('active')) {
                    body.style.overflow = 'hidden';
                } else {
                    body.style.overflow = '';
                }
            }
        }

        function handleMenuButtonClick() {
            
            if (window.innerWidth <= 768) {
                
                window.location.href = 'installments.php';
            }
        }

        document.addEventListener('click', function(event) {
            const sideMenu = document.getElementById('sideMenuMobile');
            const sideMenuButton = document.querySelector('.side-menu-button');
            const overlay = document.getElementById('sideMenuOverlay');
            
            if (sideMenu && sideMenu.classList.contains('active')) {
                if (!sideMenu.contains(event.target) && !sideMenuButton.contains(event.target)) {
                    toggleSideMenu();
                }
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const sideMenu = document.getElementById('sideMenuMobile');
                if (sideMenu && sideMenu.classList.contains('active')) {
                    toggleSideMenu();
                }
            }
        });