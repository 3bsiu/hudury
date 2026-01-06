<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/activity-logger.php';

requireUserType('admin');

$currentAdminId = getCurrentUserId();
$currentAdmin = getCurrentUserData($pdo);
$adminName = $_SESSION['user_name'] ?? 'Admin';

ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'createNotification') {
    try {
        
        $title = trim($_POST['notifTitle'] ?? '');
        $content = trim($_POST['notifMessage'] ?? '');
        $targetRole = $_POST['targetRole'] ?? 'All';
        $targetClassId = !empty($_POST['targetClassId']) ? intval($_POST['targetClassId']) : null;
        $targetStudentId = !empty($_POST['targetStudentId']) ? intval($_POST['targetStudentId']) : null;
        $senderId = 1; 
        $senderType = 'Admin';

        if (empty($title) || empty($content)) {
            header("Location: notifications-management.php?error=1&message=" . urlencode('Please fill in all required fields (Title and Message).'));
            exit();
        }

        $validRoles = ['Parent', 'Student', 'Teacher', 'All'];
        $targetRole = ucfirst(strtolower(trim($targetRole)));
        if (!in_array($targetRole, $validRoles)) {
            $targetRole = 'All';
        }

        $stmt = $pdo->prepare("
            INSERT INTO notification (Title, Content, Date_Sent, Sender_Type, Sender_ID, Target_Role, Target_Class_ID, Target_Student_ID)
            VALUES (?, ?, NOW(), ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $title,
            $content,
            $senderType,
            $senderId,
            $targetRole,
            $targetClassId,
            $targetStudentId
        ]);
        
        $notifId = $pdo->lastInsertId();

        $details = "Target: {$targetRole}";
        if ($targetClassId) {
            $stmt = $pdo->prepare("SELECT Name FROM class WHERE Class_ID = ?");
            $stmt->execute([$targetClassId]);
            $classData = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($classData) {
                $details .= ", Class: {$classData['Name']}";
            }
        }
        logAdminAction($pdo, 'create', 'notification', $notifId, "Notification: {$title} ({$details})", 'notification', null);

        header("Location: notifications-management.php?success=1&message=" . urlencode('Notification sent successfully!'));
        exit();
        
    } catch (PDOException $e) {
        error_log("Error creating notification: " . $e->getMessage());
        header("Location: notifications-management.php?error=1&message=" . urlencode('Database error: ' . $e->getMessage()));
        exit();
    } catch (Exception $e) {
        error_log("Error creating notification: " . $e->getMessage());
        header("Location: notifications-management.php?error=1&message=" . urlencode('Error: ' . $e->getMessage()));
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'deleteNotification') {
    try {
        $notifId = intval($_POST['notifId'] ?? 0);
        
        if ($notifId <= 0) {
            header("Location: notifications-management.php?error=1&message=" . urlencode('Invalid notification ID.'));
            exit();
        }

        $stmt = $pdo->prepare("SELECT Title, Target_Role FROM notification WHERE Notif_ID = ?");
        $stmt->execute([$notifId]);
        $notifData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("DELETE FROM notification WHERE Notif_ID = ?");
        $stmt->execute([$notifId]);

        if ($notifData) {
            logAdminAction($pdo, 'delete', 'notification', $notifId, "Notification deleted: {$notifData['Title']} (Target: {$notifData['Target_Role']})", 'notification', null);
        }
        
        header("Location: notifications-management.php?success=1&message=" . urlencode('Notification deleted successfully!'));
        exit();
        
    } catch (PDOException $e) {
        error_log("Error deleting notification: " . $e->getMessage());
        header("Location: notifications-management.php?error=1&message=" . urlencode('Database error: ' . $e->getMessage()));
        exit();
    }
}

$notifications = [];
try {
    $stmt = $pdo->prepare("
        SELECT Notif_ID, Title, Content, Date_Sent, Sender_Type, Sender_ID, Target_Role, Target_Class_ID, Target_Student_ID
        FROM notification
        ORDER BY Date_Sent DESC
        LIMIT 50
    ");
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching notifications: " . $e->getMessage());
    $notifications = [];
}

$classes = [];
try {
    $stmt = $pdo->prepare("
        SELECT Class_ID, Name, Grade_Level, Section
        FROM class
        ORDER BY Grade_Level, Section
    ");
    $stmt->execute();
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching classes: " . $e->getMessage());
    $classes = [];
}

$students = [];
try {
    $stmt = $pdo->prepare("
        SELECT Student_ID, Student_Code, NameEn, NameAr, Class_ID
        FROM student
        ORDER BY NameEn
        LIMIT 500
    ");
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching students: " . $e->getMessage());
    $students = [];
}

$teachers = [];
try {
    $stmt = $pdo->prepare("
        SELECT Teacher_ID, NameEn, NameAr
        FROM teacher
        ORDER BY NameEn
        LIMIT 500
    ");
    $stmt->execute();
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching teachers: " . $e->getMessage());
    $teachers = [];
}

$parents = [];
try {
    $stmt = $pdo->prepare("
        SELECT Parent_ID, NameEn, NameAr, Email
        FROM parent
        ORDER BY NameEn
        LIMIT 500
    ");
    $stmt->execute();
    $parents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching parents: " . $e->getMessage());
    $parents = [];
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications Management - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>

    <div class="dashboard-container">
        
        <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
            <div style="background: #6BCB77; color: white; padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; font-weight: 600;">
                <?php echo htmlspecialchars($_GET['message'] ?? 'Operation completed successfully!'); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['error']) && $_GET['error'] == '1'): ?>
            <div style="background: #FF6B9D; color: white; padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; font-weight: 600;">
                <?php echo htmlspecialchars($_GET['message'] ?? 'An error occurred.'); ?>
            </div>
        <?php endif; ?>
        
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">🔔</span>
                <span data-en="Notifications Management" data-ar="إدارة الإشعارات">Notifications Management</span>
            </h1>
            <p class="page-subtitle" data-en="Create and manage system notifications and templates" data-ar="إنشاء وإدارة إشعارات النظام والقوالب">Create and manage system notifications and templates</p>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">➕</span>
                    <span data-en="Send Notification" data-ar="إرسال إشعار">Send Notification</span>
                </h2>
            </div>
            <form method="POST" action="notifications-management.php" onsubmit="return validateNotificationForm(event)">
                <input type="hidden" name="action" value="createNotification">
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
                    <div class="form-group">
                        <label data-en="Target Audience" data-ar="الجمهور المستهدف">Target Audience <span style="color: red;">*</span></label>
                        <select id="targetRole" name="targetRole" required onchange="updateTargetFields()">
                            <option value="All" data-en="All Users" data-ar="جميع المستخدمين">All Users</option>
                            <option value="Student" data-en="Students Only" data-ar="الطلاب فقط">Students Only</option>
                            <option value="Parent" data-en="Parents Only" data-ar="أولياء الأمور فقط">Parents Only</option>
                            <option value="Teacher" data-en="Teachers Only" data-ar="المعلمون فقط">Teachers Only</option>
                        </select>
                    </div>

                    <div class="form-group" id="targetClassGroup" style="display: none;">
                        <label data-en="Target Class (Optional)" data-ar="الفصل المستهدف (اختياري)">Target Class (Optional)</label>
                        <select id="targetClassId" name="targetClassId">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['Class_ID']; ?>">
                                    <?php echo htmlspecialchars($class['Name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" id="targetStudentGroup" style="display: none;">
                        <label data-en="Target Student (Optional)" data-ar="الطالب المستهدف (اختياري)">Target Student (Optional)</label>
                        <select id="targetStudentId" name="targetStudentId">
                            <option value="">All Students</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['Student_ID']; ?>" data-class="<?php echo $student['Class_ID']; ?>">
                                    <?php echo htmlspecialchars($student['NameEn'] . ' (' . $student['Student_Code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label data-en="Title" data-ar="العنوان">Title <span style="color: red;">*</span></label>
                    <input type="text" id="notifTitle" name="notifTitle" required>
                </div>
                <div class="form-group">
                    <label data-en="Message" data-ar="الرسالة">Message <span style="color: red;">*</span></label>
                    <textarea id="notifMessage" name="notifMessage" rows="5" required></textarea>
                </div>
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" data-en="Send Notification" data-ar="إرسال الإشعار">Send Notification</button>
                    <button type="button" class="btn btn-secondary" onclick="document.querySelector('form').reset(); updateTargetFields();" data-en="Clear" data-ar="مسح">Clear</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📜</span>
                    <span data-en="Recent Notifications" data-ar="الإشعارات الأخيرة">Recent Notifications</span>
                </h2>
            </div>
            <div id="notificationsList" class="user-list">
                <?php if (empty($notifications)): ?>
                    <div style="text-align: center; padding: 2rem; color: #666;">
                        <div style="font-size: 2rem; margin-bottom: 0.5rem;">🔔</div>
                        <div data-en="No notifications found" data-ar="لا توجد إشعارات">No notifications found</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications as $notif): ?>
                        <?php
                        $dateSent = new DateTime($notif['Date_Sent']);
                        $formattedDate = $dateSent->format('M d, Y H:i');
                        $targetInfo = '';
                        if ($notif['Target_Role'] === 'Student' && $notif['Target_Student_ID']) {
                            $targetInfo = ' (Specific Student)';
                        } elseif ($notif['Target_Role'] === 'Student' && $notif['Target_Class_ID']) {
                            $targetInfo = ' (Specific Class)';
                        }
                        ?>
                        <div class="user-item">
                            <div class="user-info-item" style="flex: 1;">
                                <div class="user-avatar-item">🔔</div>
                                <div>
                                    <div style="font-weight: 700;"><?php echo htmlspecialchars($notif['Title']); ?></div>
                                    <div style="font-size: 0.9rem; color: #666; margin-top: 0.3rem;">
                                        <?php echo htmlspecialchars(substr($notif['Content'], 0, 150)) . (strlen($notif['Content']) > 150 ? '...' : ''); ?>
                                    </div>
                                    <div style="font-size: 0.85rem; color: #999; margin-top: 0.5rem;">
                                        <span data-en="Target" data-ar="المستهدف">Target:</span> <?php echo htmlspecialchars($notif['Target_Role']); ?><?php echo $targetInfo; ?>
                                        • <span data-en="Sent" data-ar="تم الإرسال">Sent:</span> <?php echo $formattedDate; ?>
                                        • <span data-en="By" data-ar="بواسطة">By:</span> <?php echo htmlspecialchars($notif['Sender_Type']); ?>
                                    </div>
                                </div>
                            </div>
                            <div style="display: flex; gap: 0.5rem;">
                                <button class="btn btn-danger btn-small" onclick="deleteNotification(<?php echo $notif['Notif_ID']; ?>)" data-en="Delete" data-ar="حذف">Delete</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal" id="templateModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="templateModalTitle" data-en="Create Template" data-ar="إنشاء قالب">Create Template</h2>
                <button class="modal-close" onclick="closeModal('templateModal')">&times;</button>
            </div>
            <form onsubmit="saveTemplate(event)">
                <div class="form-group">
                    <label data-en="Template Name" data-ar="اسم القالب">Template Name</label>
                    <input type="text" id="templateName" required>
                </div>
                <div class="form-group">
                    <label data-en="Title" data-ar="العنوان">Title</label>
                    <input type="text" id="templateTitle" required>
                </div>
                <div class="form-group">
                    <label data-en="Message" data-ar="الرسالة">Message</label>
                    <textarea id="templateMessage" rows="5" required></textarea>
                </div>
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" data-en="Save Template" data-ar="حفظ القالب">Save Template</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('templateModal')" data-en="Cancel" data-ar="إلغاء">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        
        const allStudents = <?php echo json_encode($students, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const allClasses = <?php echo json_encode($classes, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        
        function updateTargetFields() {
            const targetRole = document.getElementById('targetRole').value;
            const targetClassGroup = document.getElementById('targetClassGroup');
            const targetStudentGroup = document.getElementById('targetStudentGroup');
            const targetClassId = document.getElementById('targetClassId');
            const targetStudentId = document.getElementById('targetStudentId');

            targetClassGroup.style.display = 'none';
            targetStudentGroup.style.display = 'none';
            if (targetClassId) targetClassId.required = false;
            if (targetStudentId) targetStudentId.required = false;

            if (targetRole === 'Student') {
                targetClassGroup.style.display = 'block';
                targetStudentGroup.style.display = 'block';
            }

            if (targetClassId && targetStudentId) {
                
                const newTargetClassId = targetClassId.cloneNode(true);
                targetClassId.parentNode.replaceChild(newTargetClassId, targetClassId);
                document.getElementById('targetClassId').addEventListener('change', function() {
                    filterStudentsByClass(this.value);
                });
            }
        }
        
        function filterStudentsByClass(classId) {
            const targetStudentId = document.getElementById('targetStudentId');
            const currentValue = targetStudentId.value;

            targetStudentId.innerHTML = '<option value="">All Students</option>';
            
            if (classId) {
                
                const filteredStudents = allStudents.filter(student => student.Class_ID == classId);
                filteredStudents.forEach(student => {
                    const option = document.createElement('option');
                    option.value = student.Student_ID;
                    option.textContent = student.NameEn + ' (' + student.Student_Code + ')';
                    targetStudentId.appendChild(option);
                });
            } else {
                
                allStudents.forEach(student => {
                    const option = document.createElement('option');
                    option.value = student.Student_ID;
                    option.textContent = student.NameEn + ' (' + student.Student_Code + ')';
                    targetStudentId.appendChild(option);
                });
            }

            if (currentValue) {
                targetStudentId.value = currentValue;
            }
        }
        
        function validateNotificationForm(event) {
            const title = document.getElementById('notifTitle').value.trim();
            const message = document.getElementById('notifMessage').value.trim();
            const targetRole = document.getElementById('targetRole').value;
            const targetClassId = document.getElementById('targetClassId') ? document.getElementById('targetClassId').value : '';
            const targetStudentId = document.getElementById('targetStudentId') ? document.getElementById('targetStudentId').value : '';

            if (!title) {
                alert(currentLanguage === 'en' ? 'Please enter notification title.' : 'يرجى إدخال عنوان الإشعار.');
                event.preventDefault();
                return false;
            }
            
            if (!message) {
                alert(currentLanguage === 'en' ? 'Please enter notification message.' : 'يرجى إدخال رسالة الإشعار.');
                event.preventDefault();
                return false;
            }
            
            if (!targetRole) {
                alert(currentLanguage === 'en' ? 'Please select target audience.' : 'يرجى اختيار الجمهور المستهدف.');
                event.preventDefault();
                return false;
            }

            let targetInfo = targetRole;
            if (targetRole === 'Student') {
                if (targetStudentId) {
                    const studentSelect = document.getElementById('targetStudentId');
                    const selectedOption = studentSelect.options[studentSelect.selectedIndex];
                    targetInfo += ' - ' + selectedOption.text;
                } else if (targetClassId) {
                    const classSelect = document.getElementById('targetClassId');
                    const selectedOption = classSelect.options[classSelect.selectedIndex];
                    targetInfo += ' - ' + selectedOption.text;
                } else {
                    targetInfo += ' - All Classes';
                }
            }

            const confirmMsg = currentLanguage === 'en' 
                ? `Are you sure you want to send this notification to ${targetInfo}?`
                : `هل أنت متأكد من إرسال هذا الإشعار إلى ${targetInfo}؟`;
            
            if (!confirm(confirmMsg)) {
                event.preventDefault();
                return false;
            }

            const form = event.target;
            if (!form.querySelector('input[name="action"]')) {
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'createNotification';
                form.appendChild(actionInput);
            }
            
            return true;
        }
        
        function deleteNotification(notifId) {
            if (confirm(currentLanguage === 'en' ? 'Are you sure you want to delete this notification?' : 'هل أنت متأكد من حذف هذا الإشعار؟')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'notifications-management.php';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'deleteNotification';
                form.appendChild(actionInput);
                
                const notifIdInput = document.createElement('input');
                notifIdInput.type = 'hidden';
                notifIdInput.name = 'notifId';
                notifIdInput.value = notifId;
                form.appendChild(notifIdInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            updateTargetFields();
        });
    </script>
</body>
</html>

