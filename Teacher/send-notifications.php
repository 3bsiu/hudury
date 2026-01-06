<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-teacher.php';

requireUserType('teacher');

$currentTeacherId = getCurrentUserId();
$currentTeacher = getCurrentUserData($pdo);
$teacherName = $_SESSION['user_name'] ?? 'Teacher';

$teacherClasses = [];

try {
    
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.Class_ID, c.Name as Class_Name, c.Grade_Level, c.Section
        FROM teacher_class_course tcc
        JOIN class c ON tcc.Class_ID = c.Class_ID
        WHERE tcc.Teacher_ID = ?
        ORDER BY c.Grade_Level, c.Section
    ");
    $stmt->execute([$currentTeacherId]);
    $teacherClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching teacher classes: " . $e->getMessage());
    $teacherClasses = [];
}

$teacherDisplayName = $currentTeacher['NameEn'] ?? $currentTeacher['NameAr'] ?? $teacherName;

$notifications = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM notification 
        WHERE (Target_Role = 'Teacher' OR Target_Role = 'All')
        AND (Target_Class_ID IS NULL OR Target_Class_ID IN (
            SELECT DISTINCT Class_ID FROM teacher_class_course WHERE Teacher_ID = ?
        ))
        ORDER BY Date_Sent DESC 
        LIMIT 10
    ");
    $stmt->execute([$currentTeacherId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching notifications: " . $e->getMessage());
    $notifications = [];
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Notifications - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        .notification-form-container {
            max-width: 900px;
            margin: 2rem auto;
            padding: 2rem;
        }
        .form-section {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            clear: both;
            overflow: hidden;
        }
        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .section-title i {
            font-size: 1.3rem;
        }
        .target-type-selector {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .target-type-option {
            flex: 1;
            min-width: 150px;
            padding: 1rem;
            border: 3px solid #FFE5E5;
            border-radius: 15px;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }
        .target-type-option:hover {
            border-color: var(--primary-color);
            background: #FFF9F5;
        }
        .target-type-option.active {
            border-color: var(--primary-color);
            background: linear-gradient(135deg, #FF6B9D, #6BCB77);
            color: white;
        }
        .target-type-option input[type="radio"] {
            display: none;
        }
        .target-type-option label {
            cursor: pointer;
            font-weight: 600;
            display: block;
        }
        .selection-flow {
            display: none;
        }
        .selection-flow.active {
            display: block;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #333;
        }
        .form-group select,
        .form-group input[type="text"],
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #FFE5E5;
            border-radius: 10px;
            font-size: 1rem;
            font-family: inherit;
            transition: border-color 0.3s;
        }
        .form-group select:focus,
        .form-group input[type="text"]:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        .form-group textarea {
            min-height: 150px;
            resize: vertical;
        }
        .students-container {
            max-height: 400px;
            overflow-y: auto;
            border: 2px solid #FFE5E5;
            border-radius: 10px;
            padding: 1rem;
            background: #FFF9F5;
            margin-top: 1rem;
        }
        .student-checkbox-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            background: white;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .student-checkbox-item:hover {
            background: #FFE5E5;
        }
        .student-checkbox-item input[type="checkbox"] {
            margin-right: 0.75rem;
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        .student-checkbox-item label {
            flex: 1;
            cursor: pointer;
            margin: 0;
            font-weight: 500;
        }
        .select-all-container {
            padding: 1rem;
            background: white;
            border-bottom: 2px solid #FFE5E5;
            margin-bottom: 1rem;
            border-radius: 8px 8px 0 0;
        }
        .select-all-container label {
            font-weight: 700;
            color: var(--primary-color);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .parents-info {
            background: #E8F5E9;
            border-left: 4px solid #6BCB77;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
        }
        .parents-info i {
            color: #6BCB77;
            margin-right: 0.5rem;
        }
        .submit-btn {
            background: linear-gradient(135deg, #FF6B9D, #6BCB77);
            color: white;
            border: none;
            padding: 1rem 3rem;
            border-radius: 15px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            margin-top: 0;
        }
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(255, 107, 157, 0.4);
        }
        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #999;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        .notification-preview {
            background: #FFF9F5;
            border-left: 4px solid var(--primary-color);
            padding: 1.5rem;
            border-radius: 10px;
            margin-top: 1rem;
        }
        .notification-preview h4 {
            margin-bottom: 0.5rem;
            color: var(--primary-color);
        }
        .notification-preview p {
            color: #666;
            line-height: 1.6;
        }
        @media (max-width: 768px) {
            .notification-form-container {
                padding: 1rem;
            }
            .form-section {
                padding: 1.5rem;
            }
            .target-type-selector {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <?php
    
    $notifications = [];
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM notification 
            WHERE (Target_Role = 'Teacher' OR Target_Role = 'All')
            AND (Target_Class_ID IS NULL OR Target_Class_ID IN (
                SELECT DISTINCT Class_ID FROM teacher_class_course WHERE Teacher_ID = ?
            ))
            ORDER BY Date_Sent DESC 
            LIMIT 10
        ");
        $stmt->execute([$currentTeacherId]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching notifications: " . $e->getMessage());
        $notifications = [];
    }
    ?>

    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>
    
    <div class="main-content">
        <div class="notification-form-container">
            <h1 style="text-align: center; margin-bottom: 2rem; color: var(--primary-color);">
                <i class="fas fa-bell"></i> <span data-en="Send Notifications" data-ar="إرسال الإشعارات">Send Notifications</span>
            </h1>
            
            <form id="notificationForm">
                
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-users"></i>
                        <span data-en="Select Recipients" data-ar="اختر المستلمين">Select Recipients</span>
                    </div>
                    
                    <div class="target-type-selector">
                        <div class="target-type-option active" onclick="selectTargetType('students')">
                            <input type="radio" name="targetType" id="targetStudents" value="students" checked>
                            <label for="targetStudents">
                                <i class="fas fa-user-graduate"></i><br>
                                <span data-en="Students Only" data-ar="الطلاب فقط">Students Only</span>
                            </label>
                        </div>
                        <div class="target-type-option" onclick="selectTargetType('parents')">
                            <input type="radio" name="targetType" id="targetParents" value="parents">
                            <label for="targetParents">
                                <i class="fas fa-user-friends"></i><br>
                                <span data-en="Parents Only" data-ar="الآباء فقط">Parents Only</span>
                            </label>
                        </div>
                        <div class="target-type-option" onclick="selectTargetType('both')">
                            <input type="radio" name="targetType" id="targetBoth" value="both">
                            <label for="targetBoth">
                                <i class="fas fa-users"></i><br>
                                <span data-en="Students + Parents" data-ar="الطلاب والآباء">Students + Parents</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-chalkboard"></i>
                        <span data-en="Select Class" data-ar="اختر الفصل">Select Class</span>
                    </div>
                    
                    <div class="form-group">
                        <label for="classSelect" data-en="Class" data-ar="الفصل">Class</label>
                        <select id="classSelect" name="classId" required onchange="loadStudents()">
                            <option value="" data-en="Select a class..." data-ar="اختر فصلاً...">Select a class...</option>
                            <option value="all" data-en="All Classes" data-ar="جميع الفصول">All Classes</option>
                            <?php foreach ($teacherClasses as $class): ?>
                                <option value="<?php echo htmlspecialchars($class['Class_ID']); ?>">
                                    <?php echo htmlspecialchars($class['Class_Name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-section selection-flow active" id="studentsSection">
                    <div class="section-title">
                        <i class="fas fa-user-graduate"></i>
                        <span data-en="Select Students" data-ar="اختر الطلاب">Select Students</span>
                    </div>
                    
                    <div id="studentsContainer" class="empty-state">
                        <i class="fas fa-info-circle"></i>
                        <div data-en="Please select a class first" data-ar="يرجى اختيار فصل أولاً">Please select a class first</div>
                    </div>
                </div>

                <div class="form-section selection-flow" id="parentsSection" style="display: none;">
                    <div class="section-title">
                        <i class="fas fa-user-friends"></i>
                        <span data-en="Parents Information" data-ar="معلومات الآباء">Parents Information</span>
                    </div>
                    
                    <div id="parentsInfo" class="parents-info">
                        <i class="fas fa-info-circle"></i>
                        <span data-en="Parents will be automatically selected based on the students you choose." data-ar="سيتم اختيار الآباء تلقائياً بناءً على الطلاب الذين تختارهم.">
                            Parents will be automatically selected based on the students you choose.
                        </span>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-envelope"></i>
                        <span data-en="Notification Content" data-ar="محتوى الإشعار">Notification Content</span>
                    </div>
                    
                    <div class="form-group">
                        <label for="notificationTitle" data-en="Title" data-ar="العنوان">Title *</label>
                        <input type="text" id="notificationTitle" name="title" required 
                               placeholder="Enter notification title..." 
                               data-placeholder-en="Enter notification title..." 
                               data-placeholder-ar="أدخل عنوان الإشعار...">
                    </div>
                    
                    <div class="form-group">
                        <label for="notificationMessage" data-en="Message" data-ar="الرسالة">Message *</label>
                        <textarea id="notificationMessage" name="message" required 
                                  placeholder="Enter notification message..." 
                                  data-placeholder-en="Enter notification message..." 
                                  data-placeholder-ar="أدخل رسالة الإشعار..."></textarea>
                    </div>
                    
                    <div class="notification-preview" id="notificationPreview" style="display: none;">
                        <h4 data-en="Preview" data-ar="معاينة">Preview</h4>
                        <p id="previewContent"></p>
                    </div>
                </div>

                <div class="form-section">
                    <button type="submit" class="submit-btn" id="submitBtn">
                        <i class="fas fa-paper-plane"></i> 
                        <span data-en="Send Notification" data-ar="إرسال الإشعار">Send Notification</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="script.js"></script>
    <script>
        let selectedStudents = [];
        let currentTargetType = 'students';
        
        function selectTargetType(type) {
            currentTargetType = type;

            document.getElementById('target' + type.charAt(0).toUpperCase() + type.slice(1)).checked = true;

            document.querySelectorAll('.target-type-option').forEach(opt => {
                opt.classList.remove('active');
            });
            event.currentTarget.classList.add('active');

            const studentsSection = document.getElementById('studentsSection');
            const parentsSection = document.getElementById('parentsSection');
            
            if (type === 'students') {
                studentsSection.style.display = 'block';
                parentsSection.style.display = 'none';
            } else if (type === 'parents') {
                studentsSection.style.display = 'block';
                parentsSection.style.display = 'block';
            } else if (type === 'both') {
                studentsSection.style.display = 'block';
                parentsSection.style.display = 'block';
            }
            
            updateParentsInfo();
        }
        
        function loadStudents() {
            const classId = document.getElementById('classSelect').value;
            const container = document.getElementById('studentsContainer');
            
            if (!classId) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-info-circle"></i>
                        <div data-en="Please select a class first" data-ar="يرجى اختيار فصل أولاً">Please select a class first</div>
                    </div>
                `;
                selectedStudents = [];
                return;
            }
            
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-spinner fa-spin"></i>
                    <div data-en="Loading students..." data-ar="جاري تحميل الطلاب...">Loading students...</div>
                </div>
            `;
            
            const url = classId === 'all' 
                ? 'send-notifications-ajax.php?action=getAllStudents'
                : 'send-notifications-ajax.php?action=getStudents&class_id=' + classId;
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderStudents(data.students || []);
                    } else {
                        container.innerHTML = `
                            <div class="empty-state">
                                <i class="fas fa-exclamation-circle"></i>
                                <div>${data.message || 'Error loading students'}</div>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    container.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-exclamation-circle"></i>
                            <div data-en="Error loading students" data-ar="خطأ في تحميل الطلاب">Error loading students</div>
                        </div>
                    `;
                });
        }
        
        function renderStudents(students) {
            const container = document.getElementById('studentsContainer');
            selectedStudents = [];
            
            if (students.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-user-slash"></i>
                        <div data-en="No students found in this class" data-ar="لا يوجد طلاب في هذا الفصل">No students found in this class</div>
                    </div>
                `;
                updateParentsInfo();
                return;
            }
            
            let html = `
                <div class="select-all-container">
                    <label>
                        <input type="checkbox" id="selectAllStudents" onchange="toggleSelectAll()">
                        <span data-en="Select All Students" data-ar="اختر جميع الطلاب">Select All Students</span>
                    </label>
                </div>
                <div class="students-container">
            `;
            
            students.forEach(student => {
                const studentName = student.NameEn || student.NameAr || 'Unknown';
                const className = student.Class_Name || '';
                html += `
                    <div class="student-checkbox-item">
                        <input type="checkbox" 
                               id="student_${student.Student_ID}" 
                               value="${student.Student_ID}"
                               onchange="updateSelectedStudents()">
                        <label for="student_${student.Student_ID}">
                            ${studentName} <small style="color: #999;">(${student.Student_Code || ''})</small>
                            ${className ? '<br><small style="color: #999; font-size: 0.8rem;">' + className + '</small>' : ''}
                        </label>
                    </div>
                `;
            });
            
            html += `</div>`;
            container.innerHTML = html;
            updateParentsInfo();
        }
        
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAllStudents');
            const checkboxes = document.querySelectorAll('#studentsContainer input[type="checkbox"]:not(#selectAllStudents)');
            
            checkboxes.forEach(cb => {
                cb.checked = selectAll.checked;
            });
            
            updateSelectedStudents();
        }
        
        function updateSelectedStudents() {
            const checkboxes = document.querySelectorAll('#studentsContainer input[type="checkbox"]:not(#selectAllStudents)');
            selectedStudents = Array.from(checkboxes)
                .filter(cb => cb.checked)
                .map(cb => parseInt(cb.value));

            const selectAll = document.getElementById('selectAllStudents');
            if (selectAll) {
                selectAll.checked = checkboxes.length > 0 && selectedStudents.length === checkboxes.length;
            }
            
            updateParentsInfo();
        }
        
        function updateParentsInfo() {
            const parentsInfo = document.getElementById('parentsInfo');
            const parentsSection = document.getElementById('parentsSection');
            
            if (currentTargetType === 'students') {
                parentsSection.style.display = 'none';
                return;
            }
            
            if (selectedStudents.length === 0) {
                parentsInfo.innerHTML = `
                    <i class="fas fa-info-circle"></i>
                    <span data-en="Select students to see their parents" data-ar="اختر الطلاب لرؤية آبائهم">Select students to see their parents</span>
                `;
            } else {
                
                fetch('send-notifications-ajax.php?action=getParentsCount&student_ids=' + selectedStudents.join(','))
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const count = data.count || 0;
                            parentsInfo.innerHTML = `
                                <i class="fas fa-check-circle"></i>
                                <span data-en="Notification will be sent to ${count} parent(s) linked to the selected ${selectedStudents.length} student(s)" 
                                      data-ar="سيتم إرسال الإشعار إلى ${count} من الآباء المرتبطين بـ ${selectedStudents.length} طالب/طالبة">
                                    Notification will be sent to ${count} parent(s) linked to the selected ${selectedStudents.length} student(s)
                                </span>
                            `;
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                    });
            }
        }

        document.getElementById('notificationTitle').addEventListener('input', updatePreview);
        document.getElementById('notificationMessage').addEventListener('input', updatePreview);
        
        function updatePreview() {
            const title = document.getElementById('notificationTitle').value;
            const message = document.getElementById('notificationMessage').value;
            const preview = document.getElementById('notificationPreview');
            const previewContent = document.getElementById('previewContent');
            
            if (title || message) {
                preview.style.display = 'block';
                previewContent.innerHTML = `
                    <strong>${title || '(No title)'}</strong><br>
                    ${message || '(No message)'}
                `;
            } else {
                preview.style.display = 'none';
            }
        }

        document.getElementById('notificationForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const title = document.getElementById('notificationTitle').value.trim();
            const message = document.getElementById('notificationMessage').value.trim();
            const classId = document.getElementById('classSelect').value;
            const targetType = document.querySelector('input[name="targetType"]:checked').value;

            if (!title || !message) {
                showNotification(currentLanguage === 'en' ? 'Please fill in title and message' : 'يرجى ملء العنوان والرسالة', 'error');
                return;
            }
            
            if (!classId || classId === '') {
                showNotification(currentLanguage === 'en' ? 'Please select a class or All Classes' : 'يرجى اختيار فصل أو جميع الفصول', 'error');
                return;
            }
            
            if (selectedStudents.length === 0) {
                showNotification(currentLanguage === 'en' ? 'Please select at least one student' : 'يرجى اختيار طالب واحد على الأقل', 'error');
                return;
            }

            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span data-en="Sending..." data-ar="جاري الإرسال...">Sending...</span>';

            const formData = new FormData();
            formData.append('action', 'sendNotification');
            formData.append('title', title);
            formData.append('message', message);
            formData.append('classId', classId);
            formData.append('targetType', targetType);
            formData.append('studentIds', JSON.stringify(selectedStudents));

            fetch('send-notifications-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message || (currentLanguage === 'en' ? 'Notification sent successfully!' : 'تم إرسال الإشعار بنجاح!'), 'success');

                    document.getElementById('notificationForm').reset();
                    document.getElementById('studentsContainer').innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-info-circle"></i>
                            <div data-en="Please select a class first" data-ar="يرجى اختيار فصل أولاً">Please select a class first</div>
                        </div>
                    `;
                    selectedStudents = [];
                    document.getElementById('notificationPreview').style.display = 'none';
                    selectTargetType('students');
                } else {
                    showNotification(data.message || (currentLanguage === 'en' ? 'Error sending notification' : 'خطأ في إرسال الإشعار'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(currentLanguage === 'en' ? 'Error sending notification' : 'خطأ في إرسال الإشعار', 'error');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> <span data-en="Send Notification" data-ar="إرسال الإشعار">Send Notification</span>';
            });
        });

        updatePreview();
        
        function openProfileSettings() {
            window.location.href = 'notifications-and-settings.php#profile';
        }
        
        function toggleRightMenu() {
            const menu = document.getElementById('rightSideMenu');
            const overlay = document.getElementById('menuOverlay');
            menu.classList.toggle('active');
            overlay.classList.toggle('active');
        }
        
        function closeRightMenu() {
            const menu = document.getElementById('rightSideMenu');
            const overlay = document.getElementById('menuOverlay');
            menu.classList.remove('active');
            overlay.classList.remove('active');
        }
        
        function toggleNotificationsDropdown() {
            const dropdown = document.getElementById('notificationsDropdown');
            if (dropdown) {
                dropdown.classList.toggle('active');
            }
        }

        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('notificationsDropdown');
            const button = event.target.closest('.header-nav-btn');
            if (dropdown && !dropdown.contains(event.target) && !button) {
                dropdown.classList.remove('active');
            }
        });
    </script>
</body>
</html>

