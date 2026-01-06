<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('admin');

$currentAdminId = getCurrentUserId();
$currentAdmin = getCurrentUserData($pdo);
$adminName = $_SESSION['user_name'] ?? 'Admin';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$adminId = isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin' ? intval($_SESSION['user_id']) : 1;

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'markAsRead') {
            $feedbackId = intval($_POST['feedbackId'] ?? 0);
            
            if ($feedbackId <= 0) {
                throw new Exception('Invalid feedback ID.');
            }
            
            $stmt = $pdo->prepare("
                UPDATE anonymous_feedback 
                SET Is_Read = 1, 
                    Read_At = NOW(), 
                    Read_By = ?,
                    Status = 'reviewed'
                WHERE Feedback_ID = ?
            ");
            $stmt->execute([$adminId, $feedbackId]);
            
            $successMessage = 'Feedback marked as read!';
            header("Location: anonymous-feedback.php?success=1&message=" . urlencode($successMessage));
            exit();
            
        } elseif ($_POST['action'] === 'markAllAsRead') {
            $stmt = $pdo->prepare("
                UPDATE anonymous_feedback 
                SET Is_Read = 1, 
                    Read_At = NOW(), 
                    Read_By = ?,
                    Status = 'reviewed'
                WHERE Is_Read = 0
            ");
            $stmt->execute([$adminId]);
            
            $successMessage = 'All feedback marked as read!';
            header("Location: anonymous-feedback.php?success=1&message=" . urlencode($successMessage));
            exit();
        }
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        $errorMessage = 'Database error: ' . $e->getMessage();
    } catch (Exception $e) {
        error_log("Error: " . $e->getMessage());
        $errorMessage = $e->getMessage();
    }
}

$allFeedback = [];
try {
    $stmt = $pdo->prepare("
        SELECT Feedback_ID, Message, Category, Is_Read, Read_At, Read_By, Status, Created_At
        FROM anonymous_feedback
        ORDER BY Created_At DESC
    ");
    $stmt->execute();
    $allFeedback = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching feedback: " . $e->getMessage());
    $errorMessage = 'Error loading feedback: ' . $e->getMessage();
    $allFeedback = [];
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anonymous Feedback - HUDURY</title>
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
        <?php if ($errorMessage): ?>
            <div style="background: #FF6B9D; color: white; padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; font-weight: 600;">
                <?php echo htmlspecialchars($errorMessage); ?>
            </div>
        <?php endif; ?>
        
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">💬</span>
                <span data-en="Anonymous Feedback" data-ar="التعليقات المجهولة">Anonymous Feedback</span>
            </h1>
            <p class="page-subtitle" data-en="View anonymous feedback submitted by parents" data-ar="عرض التعليقات المجهولة المقدمة من أولياء الأمور">View anonymous feedback submitted by parents</p>
        </div>

        <div class="search-filter-bar">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="feedbackSearch" placeholder="Search feedback..." oninput="filterFeedback()">
            </div>
            <select class="filter-select" id="feedbackFilter" onchange="filterFeedback()">
                <option value="all" data-en="All Feedback" data-ar="جميع التعليقات">All Feedback</option>
                <option value="new" data-en="New" data-ar="جديد">New</option>
                <option value="read" data-en="Read" data-ar="مقروء">Read</option>
            </select>
            <select class="filter-select" id="categoryFilter" onchange="filterFeedback()">
                <option value="all" data-en="All Categories" data-ar="جميع الفئات">All Categories</option>
                <option value="compliment" data-en="Compliment" data-ar="إشادة">Compliment</option>
                <option value="suggestion" data-en="Suggestion" data-ar="اقتراح">Suggestion</option>
                <option value="complaint" data-en="Complaint" data-ar="شكوى">Complaint</option>
            </select>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📋</span>
                    <span data-en="Parent Feedback" data-ar="تعليقات أولياء الأمور">Parent Feedback</span>
                </h2>
                <button class="btn btn-secondary btn-small" onclick="markAllAsRead()" data-en="Mark All as Read" data-ar="تعليم الكل كمقروء">Mark All as Read</button>
            </div>
            <div id="feedbackList" class="user-list">
                <?php if (empty($allFeedback)): ?>
                    <div style="text-align: center; padding: 3rem; color: #666;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">💬</div>
                        <div data-en="No feedback found" data-ar="لا توجد تعليقات">No feedback found</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($allFeedback as $feedback): ?>
                        <?php
                        $isRead = (bool)$feedback['Is_Read'];
                        $createdAt = new DateTime($feedback['Created_At']);
                        $formattedDate = $createdAt->format('M d, Y');
                        $formattedTime = $createdAt->format('H:i');
                        $categoryIcon = $feedback['Category'] === 'compliment' ? '👍' : ($feedback['Category'] === 'suggestion' ? '💡' : '⚠️');
                        $categoryColor = $feedback['Category'] === 'compliment' ? '#6BCB77' : ($feedback['Category'] === 'suggestion' ? '#FFD93D' : '#FF6B9D');
                        $categoryLabel = $feedback['Category'] === 'compliment' ? 'Compliment' : ($feedback['Category'] === 'suggestion' ? 'Suggestion' : 'Complaint');
                        $categoryLabelAr = $feedback['Category'] === 'compliment' ? 'إشادة' : ($feedback['Category'] === 'suggestion' ? 'اقتراح' : 'شكوى');
                        ?>
                        <div class="user-item <?php echo !$isRead ? 'unread' : ''; ?>" 
                             onclick="viewFeedback(<?php echo $feedback['Feedback_ID']; ?>)" 
                             style="cursor: pointer;"
                             data-feedback-id="<?php echo $feedback['Feedback_ID']; ?>"
                             data-is-read="<?php echo $isRead ? '1' : '0'; ?>"
                             data-category="<?php echo htmlspecialchars($feedback['Category']); ?>"
                             data-message="<?php echo htmlspecialchars(strtolower($feedback['Message'])); ?>">
                            <div class="user-info-item" style="flex: 1;">
                                <div class="user-avatar-item">💬</div>
                                <div>
                                    <div style="font-weight: 700; display: flex; align-items: center; gap: 0.5rem;">
                                        <?php echo $categoryIcon; ?>
                                        <span class="status-badge" style="background: <?php echo $categoryColor; ?>;">
                                            <span data-en="<?php echo $categoryLabel; ?>" data-ar="<?php echo $categoryLabelAr; ?>"><?php echo $categoryLabel; ?></span>
                                        </span>
                                        <?php if (!$isRead): ?>
                                            <span style="background: #FF6B9D; color: white; padding: 0.2rem 0.5rem; border-radius: 10px; font-size: 0.7rem; font-weight: 700;">NEW</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 0.9rem; color: #666; margin-top: 0.5rem;">
                                        <?php echo htmlspecialchars(substr($feedback['Message'], 0, 100)) . (strlen($feedback['Message']) > 100 ? '...' : ''); ?>
                                    </div>
                                    <div style="font-size: 0.85rem; color: #999; margin-top: 0.3rem;">
                                        <?php echo $formattedDate; ?> • <?php echo $formattedTime; ?>
                                    </div>
                                </div>
                            </div>
                            <div>→</div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal" id="feedbackModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" data-en="Feedback Details" data-ar="تفاصيل التعليق">Feedback Details</h2>
                <button class="modal-close" onclick="closeModal('feedbackModal')">&times;</button>
            </div>
            <div id="feedbackDetails">
                
            </div>
            <div class="action-buttons" style="margin-top: 1.5rem;">
                <button class="btn btn-primary" onclick="markAsRead()" id="markReadBtn" data-en="Mark as Read" data-ar="تعليم كمقروء">Mark as Read</button>
                <button class="btn btn-secondary" onclick="closeModal('feedbackModal')" data-en="Close" data-ar="إغلاق">Close</button>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        
        const allFeedback = <?php echo json_encode($allFeedback, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        
        let currentFeedbackId = null;

        function getCategoryColor(category) {
            const colors = { compliment: '#6BCB77', suggestion: '#FFD93D', complaint: '#FF6B9D' };
            return colors[category] || '#999';
        }

        function getCategoryLabel(category) {
            const labels = {
                compliment: { en: 'Compliment', ar: 'إشادة' },
                suggestion: { en: 'Suggestion', ar: 'اقتراح' },
                complaint: { en: 'Complaint', ar: 'شكوى' }
            };
            const label = labels[category] || { en: category, ar: category };
            return currentLanguage === 'en' ? label.en : label.ar;
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            return months[date.getMonth()] + ' ' + date.getDate() + ', ' + date.getFullYear();
        }

        function formatTime(dateString) {
            const date = new Date(dateString);
            return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        }

        function filterFeedback() {
            const searchTerm = document.getElementById('feedbackSearch').value.toLowerCase();
            const filter = document.getElementById('feedbackFilter').value;
            const categoryFilter = document.getElementById('categoryFilter').value;
            
            const feedbackItems = document.querySelectorAll('#feedbackList .user-item');
            
            feedbackItems.forEach(item => {
                const isRead = item.getAttribute('data-is-read') === '1';
                const message = item.getAttribute('data-message') || '';
                const category = item.getAttribute('data-category') || '';
                
                const matchesSearch = message.includes(searchTerm);
                const matchesFilter = filter === 'all' || 
                                    (filter === 'new' && !isRead) ||
                                    (filter === 'read' && isRead);
                const matchesCategory = categoryFilter === 'all' || category === categoryFilter;
                
                if (matchesSearch && matchesFilter && matchesCategory) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        function viewFeedback(feedbackId) {
            const feedback = allFeedback.find(f => f.Feedback_ID == feedbackId);
            if (!feedback) {
                alert('Feedback not found.');
                return;
            }
            
            currentFeedbackId = feedbackId;
            const isRead = feedback.Is_Read == 1;
            const createdAt = new Date(feedback.Created_At);
            
            document.getElementById('feedbackDetails').innerHTML = `
                <div style="margin-bottom: 1rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <span class="status-badge" style="background: ${getCategoryColor(feedback.Category)};">
                            ${getCategoryLabel(feedback.Category)}
                        </span>
                        <span style="color: #666; font-size: 0.9rem;">${formatDate(feedback.Created_At)} at ${formatTime(feedback.Created_At)}</span>
                    </div>
                    <div style="background: #FFF9F5; padding: 1.5rem; border-radius: 15px; border-left: 4px solid ${getCategoryColor(feedback.Category)};">
                        <p style="line-height: 1.8; color: var(--text-dark);">${escapeHtml(feedback.Message)}</p>
                    </div>
                </div>
            `;
            
            document.getElementById('markReadBtn').style.display = isRead ? 'none' : 'inline-block';
            openModal('feedbackModal');
        }

        function markAsRead() {
            if (!currentFeedbackId) return;
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'anonymous-feedback.php';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'markAsRead';
            form.appendChild(actionInput);
            
            const feedbackIdInput = document.createElement('input');
            feedbackIdInput.type = 'hidden';
            feedbackIdInput.name = 'feedbackId';
            feedbackIdInput.value = currentFeedbackId;
            form.appendChild(feedbackIdInput);
            
            document.body.appendChild(form);
            form.submit();
        }

        function markAllAsRead() {
            if (!confirm(currentLanguage === 'en' ? 'Are you sure you want to mark all feedback as read?' : 'هل أنت متأكد من تعليم جميع التعليقات كمقروءة؟')) {
                return;
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'anonymous-feedback.php';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'markAllAsRead';
            form.appendChild(actionInput);
            
            document.body.appendChild(form);
            form.submit();
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>

