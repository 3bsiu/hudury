<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('admin');

$currentAdminId = getCurrentUserId();
$currentAdmin = getCurrentUserData($pdo);
$adminName = $_SESSION['user_name'] ?? 'Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'markAsRead' && isset($_POST['submission_id'])) {
    $submissionId = intval($_POST['submission_id']);
    try {
        $stmt = $pdo->prepare("
            UPDATE contact_submission 
            SET Status = 'read', Read_At = NOW() 
            WHERE Submission_ID = ? AND Status = 'new'
        ");
        $stmt->execute([$submissionId]);
        echo json_encode(['success' => true]);
        exit();
    } catch (PDOException $e) {
        error_log("Error updating submission status: " . $e->getMessage());
        echo json_encode(['success' => false]);
        exit();
    }
}

$submissions = [];
try {
    $stmt = $pdo->prepare("
        SELECT Submission_ID, Name, Email, Phone, Subject, Message, Status, Created_At, Read_At 
        FROM contact_submission 
        ORDER BY Created_At DESC
    ");
    $stmt->execute();
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching contact submissions: " . $e->getMessage());
    $submissions = [];
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Form Submissions - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">📧</span>
                <span data-en="Contact Form Submissions" data-ar="طلبات الاتصال">Contact Form Submissions</span>
            </h1>
            <p class="page-subtitle" data-en="View and manage contact form submissions from the homepage" data-ar="عرض وإدارة طلبات الاتصال من الصفحة الرئيسية">View and manage contact form submissions from the homepage</p>
        </div>

        <div class="search-filter-bar">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="contactSearch" placeholder="Search submissions..." oninput="filterSubmissions()">
            </div>
            <select class="filter-select" id="statusFilter" onchange="filterSubmissions()">
                <option value="all" data-en="All Submissions" data-ar="جميع الطلبات">All Submissions</option>
                <option value="new" data-en="New" data-ar="جديد">New</option>
                <option value="read" data-en="Read" data-ar="مقروء">Read</option>
                <option value="replied" data-en="Replied" data-ar="تم الرد">Replied</option>
            </select>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📋</span>
                    <span data-en="Contact Submissions" data-ar="طلبات الاتصال">Contact Submissions</span>
                </h2>
                <button class="btn btn-secondary btn-small" onclick="markAllAsRead()" data-en="Mark All as Read" data-ar="تعليم الكل كمقروء">Mark All as Read</button>
            </div>
            <div id="submissionsList" class="user-list">
                
            </div>
        </div>
    </div>

    <div class="modal" id="submissionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" data-en="Contact Submission Details" data-ar="تفاصيل طلب الاتصال">Contact Submission Details</h2>
                <button class="modal-close" onclick="closeModal('submissionModal')">&times;</button>
            </div>
            <div id="submissionDetails">
                
            </div>
            <div class="action-buttons" style="margin-top: 1.5rem;">
                <button class="btn btn-secondary" onclick="closeModal('submissionModal')" data-en="Close" data-ar="إغلاق">Close</button>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        
        const submissions = <?php echo json_encode($submissions, JSON_UNESCAPED_UNICODE); ?>;

        const mockSubmissions = submissions.map(sub => ({
            id: sub.Submission_ID,
            name: sub.Name,
            email: sub.Email,
            phone: sub.Phone || '',
            subject: sub.Subject,
            message: sub.Message,
            status: sub.Status || 'new',
            submittedAt: sub.Created_At,
            readAt: sub.Read_At || null
        }));

        let currentSubmissionId = null;

        function loadSubmissions() {
            renderSubmissions(mockSubmissions);
        }

        function renderSubmissions(submissions) {
            const container = document.getElementById('submissionsList');
            container.innerHTML = submissions.map(submission => `
                <div class="user-item ${submission.status === 'new' ? 'unread' : ''}" onclick="viewSubmission(${submission.id})" style="cursor: pointer;">
                    <div class="user-info-item" style="flex: 1;">
                        <div class="user-avatar-item">📧</div>
                        <div>
                            <div style="font-weight: 700; display: flex; align-items: center; gap: 0.5rem;">
                                ${submission.name}
                                ${submission.status === 'new' ? '<span style="background: #FF6B9D; color: white; padding: 0.2rem 0.5rem; border-radius: 10px; font-size: 0.7rem; font-weight: 700;">NEW</span>' : ''}
                            </div>
                            <div style="font-size: 0.9rem; color: #666; margin-top: 0.3rem;">
                                ${submission.subject}
                            </div>
                            <div style="font-size: 0.85rem; color: #999; margin-top: 0.3rem;">
                                ${submission.email} • ${formatDate(submission.submittedAt)} at ${formatTime(submission.submittedAt)}
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <span class="status-badge ${submission.status === 'replied' ? 'status-active' : submission.status === 'read' ? 'status-pending' : 'status-inactive'}">
                            ${submission.status === 'replied' ? (currentLanguage === 'en' ? 'Replied' : 'تم الرد') : 
                              submission.status === 'read' ? (currentLanguage === 'en' ? 'Read' : 'مقروء') : 
                              (currentLanguage === 'en' ? 'New' : 'جديد')}
                        </span>
                    </div>
                </div>
            `).join('');
        }

        function filterSubmissions() {
            const searchTerm = document.getElementById('contactSearch').value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter').value;
            
            let filtered = mockSubmissions.filter(submission => {
                const matchesSearch = submission.name.toLowerCase().includes(searchTerm) || 
                                    submission.email.toLowerCase().includes(searchTerm) ||
                                    submission.subject.toLowerCase().includes(searchTerm);
                const matchesStatus = statusFilter === 'all' || submission.status === statusFilter;
                return matchesSearch && matchesStatus;
            });
            
            renderSubmissions(filtered);
        }

        function viewSubmission(submissionId) {
            const submission = mockSubmissions.find(s => s.id === submissionId);
            if (!submission) return;
            
            currentSubmissionId = submissionId;

            if (submission.status === 'new') {
                const formData = new FormData();
                formData.append('action', 'markAsRead');
                formData.append('submission_id', submissionId);
                
                fetch('contact-form-submissions.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        submission.status = 'read';
                        submission.readAt = new Date().toISOString().slice(0, 19).replace('T', ' ');
                        loadSubmissions();
                    }
                })
                .catch(error => {
                    console.error('Error marking as read:', error);
                });
            }
            
            const readTimeDisplay = submission.readAt ? 
                `<div style="margin-top: 0.5rem; font-size: 0.9rem; color: #666;">
                    <span data-en="Read at" data-ar="تم القراءة في">Read at</span>: ${formatDate(submission.readAt)} at ${formatTime(submission.readAt)}
                </div>` : '';
            
            document.getElementById('submissionDetails').innerHTML = `
                <div style="margin-bottom: 1.5rem;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            <div style="font-weight: 700; color: var(--primary-color); margin-bottom: 0.3rem;" data-en="Name" data-ar="الاسم">Name</div>
                            <div>${submission.name}</div>
                        </div>
                        <div>
                            <div style="font-weight: 700; color: var(--primary-color); margin-bottom: 0.3rem;" data-en="Email" data-ar="البريد الإلكتروني">Email</div>
                            <div>${submission.email}</div>
                        </div>
                        <div>
                            <div style="font-weight: 700; color: var(--primary-color); margin-bottom: 0.3rem;" data-en="Phone" data-ar="الهاتف">Phone</div>
                            <div>${submission.phone || 'N/A'}</div>
                        </div>
                        <div>
                            <div style="font-weight: 700; color: var(--primary-color); margin-bottom: 0.3rem;" data-en="Submitted" data-ar="تم الإرسال">Submitted</div>
                            <div>${formatDate(submission.submittedAt)} at ${formatTime(submission.submittedAt)}</div>
                            ${readTimeDisplay}
                        </div>
                    </div>
                    <div style="margin-bottom: 1rem;">
                        <div style="font-weight: 700; color: var(--primary-color); margin-bottom: 0.5rem;" data-en="Subject" data-ar="الموضوع">Subject</div>
                        <div style="font-size: 1.1rem;">${submission.subject}</div>
                    </div>
                    <div style="background: #FFF9F5; padding: 1.5rem; border-radius: 15px; border-left: 4px solid #FF6B9D;">
                        <div style="font-weight: 700; margin-bottom: 0.5rem;" data-en="Message" data-ar="الرسالة">Message</div>
                        <div style="line-height: 1.8; color: var(--text-dark);">${submission.message}</div>
                    </div>
                </div>
            `;
            
            openModal('submissionModal');
        }

        function markAllAsRead() {
            mockSubmissions.forEach(s => {
                if (s.status === 'new') s.status = 'read';
            });
            showNotification(currentLanguage === 'en' ? 'All submissions marked as read!' : 'تم تعليم جميع الطلبات كمقروءة!', 'success');
            loadSubmissions();
        }

        loadSubmissions();
    </script>
</body>
</html>

