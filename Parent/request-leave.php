<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-parent.php';

requireUserType('parent');

$currentParentId = getCurrentUserId();
$currentParent = getCurrentUserData($pdo);
$parentName = $_SESSION['user_name'] ?? 'Parent';

$linkedStudents = [];
if ($currentParentId) {
    try {
        $stmt = $pdo->prepare("
            SELECT psr.Student_ID, s.NameEn, s.NameAr, s.Student_Code, s.Class_ID,
                   c.Name as ClassName, c.Grade_Level, c.Section
            FROM parent_student_relationship psr
            INNER JOIN student s ON psr.Student_ID = s.Student_ID
            LEFT JOIN class c ON s.Class_ID = c.Class_ID
            WHERE psr.Parent_ID = ?
            ORDER BY psr.Is_Primary DESC, s.NameEn ASC
        ");
        $stmt->execute([$currentParentId]);
        $linkedStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching linked students: " . $e->getMessage());
        $linkedStudents = [];
    }
}

$leaveRequests = [];
if (!empty($linkedStudents)) {
    try {
        $studentIds = array_column($linkedStudents, 'Student_ID');
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        
        $stmt = $pdo->prepare("
            SELECT lr.*, 
                   (CASE WHEN lr.Document_Data IS NOT NULL THEN 1 ELSE 0 END) as Has_Document_Data,
                   s.NameEn as Student_Name, s.NameAr as Student_NameAr, s.Student_Code,
                   p.NameEn as Parent_Name, p.NameAr as Parent_NameAr,
                   a.NameEn as Reviewed_By_Name
            FROM leave_request lr
            INNER JOIN student s ON lr.Student_ID = s.Student_ID
            INNER JOIN parent p ON lr.Parent_ID = p.Parent_ID
            LEFT JOIN admin a ON lr.Reviewed_By = a.Admin_ID
            WHERE lr.Student_ID IN ($placeholders) AND lr.Parent_ID = ?
            ORDER BY lr.Submitted_At DESC
        ");
        $params = array_merge($studentIds, [$currentParentId]);
        $stmt->execute($params);
        $leaveRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching leave requests: " . $e->getMessage());
        $leaveRequests = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Leave - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>
    
    <div class="main-content">

    <div class="dashboard-container">
        
        <div class="page-header-section">
            <button class="btn-back" onclick="window.location.href='parent-dashboard.php'" title="Back to Dashboard">
                <i class="fas fa-arrow-left"></i>
                <span data-en="Back to Dashboard" data-ar="العودة إلى لوحة التحكم">Back to Dashboard</span>
            </button>
            <h1 class="page-title">
                <span class="page-icon">📋</span>
                <span data-en="Request Leave" data-ar="طلب إجازة">Request Leave</span>
            </h1>
            <p class="page-subtitle" data-en="Submit a leave request for your child" data-ar="تقديم طلب إجازة لطفلك">Submit a leave request for your child</p>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📋</span>
                    <span data-en="New Leave Request" data-ar="طلب إجازة جديد">New Leave Request</span>
                </h2>
            </div>
            <?php if (empty($linkedStudents)): ?>
                <div style="text-align: center; padding: 2rem; color: #666;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">👨‍👩‍👧</div>
                    <div style="font-weight: 700; margin-bottom: 0.5rem;" data-en="No Students Linked" data-ar="لا يوجد طلاب مرتبطين">No Students Linked</div>
                    <div style="font-size: 0.9rem;" data-en="You don't have any students linked to your account. Please contact the administration." data-ar="ليس لديك أي طلاب مرتبطين بحسابك. يرجى الاتصال بالإدارة.">You don't have any students linked to your account. Please contact the administration.</div>
                </div>
            <?php else: ?>
            <form onsubmit="handleLeaveRequest(event)" id="leaveRequestForm" enctype="multipart/form-data">
                <div class="form-group">
                    <label data-en="Student" data-ar="الطالب">Student <span style="color: red;">*</span></label>
                    <select id="studentId" name="student_id" required>
                        <option value="" data-en="Select student" data-ar="اختر الطالب">Select student</option>
                        <?php foreach ($linkedStudents as $student): ?>
                            <option value="<?php echo $student['Student_ID']; ?>">
                                <?php echo htmlspecialchars($student['NameEn']); ?> 
                                (<?php echo htmlspecialchars($student['Student_Code']); ?>)
                                <?php if ($student['ClassName']): ?>
                                    - <?php echo htmlspecialchars($student['ClassName']); ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label data-en="Leave Date" data-ar="تاريخ الإجازة">Leave Date <span style="color: red;">*</span></label>
                    <input type="date" id="leaveDate" name="leave_date" required min="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label data-en="End Date (Optional)" data-ar="تاريخ الانتهاء (اختياري)">End Date (Optional)</label>
                    <input type="date" id="endDate" name="end_date">
                    <small style="color: #666; font-size: 0.85rem; margin-top: 0.5rem; display: block;" data-en="Leave blank if requesting a single day" data-ar="اتركه فارغاً إذا كنت تطلب يوم واحد">Leave blank if requesting a single day</small>
                </div>
                <div class="form-group">
                    <label data-en="Reason" data-ar="السبب">Reason <span style="color: red;">*</span></label>
                    <select id="leaveReason" name="reason" required>
                        <option value="" data-en="Select reason" data-ar="اختر السبب">Select reason</option>
                        <option value="medical" data-en="Medical Appointment / Illness" data-ar="موعد طبي / مرض">Medical Appointment / Illness</option>
                        <option value="family" data-en="Family Emergency" data-ar="طوارئ عائلية">Family Emergency</option>
                        <option value="personal" data-en="Personal Reasons" data-ar="أسباب شخصية">Personal Reasons</option>
                        <option value="vacation" data-en="Family Vacation" data-ar="عطلة عائلية">Family Vacation</option>
                        <option value="other" data-en="Other" data-ar="أخرى">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label data-en="Notes" data-ar="ملاحظات">Notes</label>
                    <textarea id="leaveNotes" name="notes" rows="4" placeholder="Additional information..." data-placeholder-en="Additional information..." data-placeholder-ar="معلومات إضافية..."></textarea>
                </div>
                <div class="form-group">
                    <label data-en="Upload Supporting Document (Optional)" data-ar="رفع مستند داعم (اختياري)">Upload Supporting Document (Optional)</label>
                    <div class="upload-area-absence" onclick="document.getElementById('leaveFile').click()">
                        <div class="upload-icon-absence">
                            <i class="fas fa-cloud-upload-alt"></i>
                        </div>
                        <div data-en="Click to upload file or drag and drop" data-ar="انقر للرفع أو اسحب وأفلت">Click to upload file or drag and drop</div>
                        <div class="upload-file-info" id="leaveFileInfo" style="display: none; margin-top: 0.5rem; color: #666; font-size: 0.9rem;"></div>
                        <input type="file" id="leaveFile" name="document" style="display: none;" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" onchange="handleLeaveFileSelect(event)">
                    </div>
                </div>
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" id="submitBtn" data-en="Submit Request" data-ar="إرسال الطلب">Submit Request</button>
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('leaveRequestForm').reset(); document.getElementById('leaveFileInfo').style.display='none';" data-en="Clear Form" data-ar="مسح النموذج">Clear Form</button>
                </div>
            </form>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📜</span>
                    <span data-en="Leave Request History" data-ar="سجل طلبات الإجازة">Leave Request History</span>
                </h2>
            </div>
            <div class="leave-history-list">
                <?php if (empty($leaveRequests)): ?>
                    <div style="text-align: center; padding: 3rem; color: #999;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">📋</div>
                        <div data-en="No leave requests yet" data-ar="لا توجد طلبات إجازة بعد">No leave requests yet</div>
                        <div style="font-size: 0.9rem; margin-top: 0.5rem;" data-en="Submit your first leave request using the form above" data-ar="قدم أول طلب إجازة باستخدام النموذج أعلاه">Submit your first leave request using the form above</div>
                    </div>
                <?php else: ?>
                    <?php 
                    $reasonLabels = [
                        'medical' => ['en' => 'Medical Appointment / Illness', 'ar' => 'موعد طبي / مرض'],
                        'family' => ['en' => 'Family Emergency', 'ar' => 'طوارئ عائلية'],
                        'personal' => ['en' => 'Personal Reasons', 'ar' => 'أسباب شخصية'],
                        'vacation' => ['en' => 'Family Vacation', 'ar' => 'عطلة عائلية'],
                        'other' => ['en' => 'Other', 'ar' => 'أخرى']
                    ];
                    $statusLabels = [
                        'pending' => ['en' => 'Pending', 'ar' => 'قيد المراجعة'],
                        'approved' => ['en' => 'Approved', 'ar' => 'موافق عليه'],
                        'rejected' => ['en' => 'Rejected', 'ar' => 'مرفوض']
                    ];
                    foreach ($leaveRequests as $request): 
                        $status = strtolower($request['Status']);
                        $reasonLabel = $reasonLabels[$request['Reason']] ?? ['en' => $request['Reason'], 'ar' => $request['Reason']];
                        $statusLabel = $statusLabels[$status] ?? ['en' => ucfirst($status), 'ar' => ucfirst($status)];
                        $leaveDate = new DateTime($request['Leave_Date']);
                        $endDate = $request['End_Date'] ? new DateTime($request['End_Date']) : null;
                    ?>
                        <div class="leave-request-item <?php echo $status; ?>">
                            <div class="leave-request-header">
                                <div class="leave-request-info">
                                    <div class="leave-request-student"><?php echo htmlspecialchars($request['Student_Name']); ?></div>
                                    <div class="leave-request-date">
                                        <?php echo $leaveDate->format('M d, Y'); ?>
                                        <?php if ($endDate): ?>
                                            - <?php echo $endDate->format('M d, Y'); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="leave-request-status status-<?php echo $status; ?>">
                                    <span data-en="<?php echo $statusLabel['en']; ?>" data-ar="<?php echo $statusLabel['ar']; ?>"><?php echo $statusLabel['en']; ?></span>
                                </div>
                            </div>
                            <div class="leave-request-details">
                                <div class="leave-request-reason">
                                    <strong data-en="Reason:" data-ar="السبب:">Reason:</strong>
                                    <span data-en="<?php echo $reasonLabel['en']; ?>" data-ar="<?php echo $reasonLabel['ar']; ?>"><?php echo $reasonLabel['en']; ?></span>
                                </div>
                                <?php if ($request['Notes']): ?>
                                    <div class="leave-request-notes"><?php echo htmlspecialchars($request['Notes']); ?></div>
                                <?php endif; ?>
                                <?php if ($request['Review_Notes'] && $status !== 'pending'): ?>
                                    <div style="margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #FFE5E5; font-size: 0.85rem; color: #666;">
                                        <strong data-en="Admin Notes:" data-ar="ملاحظات الإدارة:">Admin Notes:</strong>
                                        <?php echo htmlspecialchars($request['Review_Notes']); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($request['Has_Document_Data'] || $request['Document_Path']): ?>
                                    <div style="margin-top: 0.5rem;">
                                        <a href="<?php echo $request['Has_Document_Data'] ? '../includes/file-serve.php?type=leave_document&id=' . $request['Leave_Request_ID'] : '../' . htmlspecialchars($request['Document_Path']); ?>" target="_blank" style="color: #FF6B9D; text-decoration: none;">
                                            <i class="fas fa-file"></i> <span data-en="View Document" data-ar="عرض المستند">View Document</span>
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <?php if ($request['Reviewed_By_Name']): ?>
                                    <div style="margin-top: 0.5rem; font-size: 0.8rem; color: #999;">
                                        <span data-en="Reviewed by:" data-ar="تمت المراجعة بواسطة:">Reviewed by:</span> <?php echo htmlspecialchars($request['Reviewed_By_Name']); ?>
                                        <?php if ($request['Reviewed_At']): ?>
                                            on <?php echo date('M d, Y', strtotime($request['Reviewed_At'])); ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">ℹ️</span>
                    <span data-en="Leave Request Information" data-ar="معلومات طلب الإجازة">Leave Request Information</span>
                </h2>
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-icon">⏰</div>
                    <div class="info-content">
                        <div class="info-label" data-en="Processing Time" data-ar="وقت المعالجة">Processing Time</div>
                        <div class="info-value" data-en="1-2 business days" data-ar="1-2 يوم عمل">1-2 business days</div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon">📧</div>
                    <div class="info-content">
                        <div class="info-label" data-en="Notification" data-ar="الإشعار">Notification</div>
                        <div class="info-value" data-en="You'll be notified via email" data-ar="سيتم إشعارك عبر البريد الإلكتروني">You'll be notified via email</div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon">📄</div>
                    <div class="info-content">
                        <div class="info-label" data-en="Documentation" data-ar="التوثيق">Documentation</div>
                        <div class="info-value" data-en="Medical notes recommended" data-ar="ملاحظات طبية موصى بها">Medical notes recommended</div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon">📞</div>
                    <div class="info-content">
                        <div class="info-label" data-en="Contact" data-ar="الاتصال">Contact</div>
                        <div class="info-value" data-en="For urgent requests, call office" data-ar="للطلبات العاجلة، اتصل بالمكتب">For urgent requests, call office</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="profileModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <span onclick="closeProfileSettings()" style="float: right; font-size: 32px; cursor: pointer; color: #FF6B9D; font-weight: bold;">&times;</span>
            <h2 data-en="Profile Settings" data-ar="إعدادات الملف الشخصي">Profile Settings</h2>
            <form onsubmit="handleProfileUpdate(event)">
                <div class="form-group">
                    <label data-en="Phone Number" data-ar="رقم الهاتف">Phone Number</label>
                    <input type="tel" value="+962 7XX XXX XXX" required>
                </div>
                <div class="form-group">
                    <label data-en="Email" data-ar="البريد الإلكتروني">Email</label>
                    <input type="email" value="parent@example.com" required>
                </div>
                <div class="form-group">
                    <label data-en="Address" data-ar="العنوان">Address</label>
                    <textarea rows="3">Amman, Jordan</textarea>
                </div>
                <div class="form-group">
                    <label data-en="Change Password" data-ar="تغيير كلمة المرور">Change Password</label>
                    <input type="password" placeholder="Enter new password">
                </div>
                <button type="submit" class="btn btn-primary" data-en="Save Changes" data-ar="حفظ التغييرات">Save Changes</button>
            </form>
        </div>
    </div>

    <div id="settingsModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <span onclick="closeSettings()" style="float: right; font-size: 32px; cursor: pointer; color: #FF6B9D; font-weight: bold;">&times;</span>
            <h2 data-en="Program Settings" data-ar="إعدادات البرنامج">Program Settings</h2>
            <div class="settings-section">
                <h3 data-en="Notifications" data-ar="الإشعارات">Notifications</h3>
                <div class="setting-item">
                    <label>
                        <input type="checkbox" checked onchange="toggleSetting('email')">
                        <span data-en="Email Notifications" data-ar="إشعارات البريد الإلكتروني">Email Notifications</span>
                    </label>
                </div>
                <div class="setting-item">
                    <label>
                        <input type="checkbox" checked onchange="toggleSetting('assignments')">
                        <span data-en="Assignment Reminders" data-ar="تذكيرات الواجبات">Assignment Reminders</span>
                    </label>
                </div>
                <div class="setting-item">
                    <label>
                        <input type="checkbox" checked onchange="toggleSetting('grades')">
                        <span data-en="Grade Updates" data-ar="تحديثات الدرجات">Grade Updates</span>
                    </label>
                </div>
                <div class="setting-item">
                    <label>
                        <input type="checkbox" checked onchange="toggleSetting('messages')">
                        <span data-en="Teacher Messages" data-ar="رسائل المعلم">Teacher Messages</span>
                    </label>
                </div>
            </div>
            <div class="settings-section">
                <h3 data-en="Appearance" data-ar="المظهر">Appearance</h3>
                <div class="setting-item">
                    <label data-en="Theme Color" data-ar="لون المظهر">Theme Color</label>
                    <input type="color" value="#FF6B9D" onchange="changeTheme(this.value)">
                </div>
                <div class="setting-item">
                    <label>
                        <input type="checkbox" onchange="toggleSetting('darkMode')">
                        <span data-en="Dark Mode" data-ar="الوضع الداكن">Dark Mode</span>
                    </label>
                </div>
            </div>
            <button onclick="saveSettings()" class="btn btn-primary" data-en="Save Settings" data-ar="حفظ الإعدادات">Save Settings</button>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        
        function handleLeaveFileSelect(event) {
            const file = event.target.files[0];
            const fileInfo = document.getElementById('leaveFileInfo');
            
            if (file) {
                const fileSize = (file.size / 1024).toFixed(2);
                fileInfo.style.display = 'block';
                fileInfo.innerHTML = `<i class="fas fa-check-circle"></i> ${file.name} (${fileSize} KB)`;
            } else {
                fileInfo.style.display = 'none';
            }
        }

        function handleLeaveRequest(event) {
            event.preventDefault();
            const form = event.target;
            const submitBtn = document.getElementById('submitBtn');
            const originalText = submitBtn.innerHTML;

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + (currentLanguage === 'en' ? 'Submitting...' : 'جاري الإرسال...');
            
            const formData = new FormData(form);
            formData.append('action', 'submitLeaveRequest');
            
            fetch('request-leave-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message || (currentLanguage === 'en' ? 'Leave request submitted successfully!' : 'تم إرسال طلب الإجازة بنجاح!'), 'success');
                    form.reset();
                    document.getElementById('leaveFileInfo').style.display = 'none';
                    
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showNotification(data.message || (currentLanguage === 'en' ? 'Error submitting request' : 'خطأ في إرسال الطلب'), 'error');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(currentLanguage === 'en' ? 'An error occurred. Please try again.' : 'حدث خطأ. يرجى المحاولة مرة أخرى.', 'error');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        }

        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 1rem 1.5rem;
                background: ${type === 'success' ? '#6BCB77' : '#FF6B9D'};
                color: white;
                border-radius: 10px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.2);
                z-index: 10000;
                animation: slideIn 0.3s;
            `;
            notification.textContent = message;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }

        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    </script>
    <style>
        .leave-history-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .leave-request-item {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            border: 3px solid transparent;
            transition: all 0.3s;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        }

        .leave-request-item.approved {
            border-left: 5px solid #6BCB77;
            background: linear-gradient(135deg, rgba(108,203,119,0.05), rgba(76,175,80,0.02));
        }

        .leave-request-item.pending {
            border-left: 5px solid #FFD93D;
            background: linear-gradient(135deg, rgba(255,217,61,0.05), rgba(255,193,7,0.02));
        }

        .leave-request-item.rejected {
            border-left: 5px solid #FF6B9D;
            background: linear-gradient(135deg, rgba(255,107,157,0.05), rgba(196,69,105,0.02));
        }

        .leave-request-item:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .leave-request-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .leave-request-info {
            flex: 1;
        }

        .leave-request-student {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--text-dark);
            margin-bottom: 0.3rem;
        }

        .leave-request-date {
            font-size: 0.9rem;
            color: #666;
        }

        .leave-request-status {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.9rem;
        }

        .status-approved {
            background: linear-gradient(135deg, #6BCB77, #4CAF50);
            color: white;
        }

        .status-pending {
            background: linear-gradient(135deg, #FFD93D, #FFC107);
            color: var(--text-dark);
        }

        .status-rejected {
            background: linear-gradient(135deg, #FF6B9D, #C44569);
            color: white;
        }

        .leave-request-details {
            padding-top: 1rem;
            border-top: 2px solid #FFE5E5;
        }

        .leave-request-reason {
            margin-bottom: 0.5rem;
            color: #666;
        }

        .leave-request-notes {
            font-size: 0.9rem;
            color: #999;
            font-style: italic;
        }
    </style>
</body>
</html>

