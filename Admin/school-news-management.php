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
        if ($_POST['action'] === 'createNews') {
            $title = trim($_POST['newsTitle'] ?? '');
            $content = trim($_POST['newsContent'] ?? '');
            $imagePath = trim($_POST['newsImage'] ?? '');
            $category = trim($_POST['newsCategory'] ?? 'general');
            $status = trim($_POST['newsStatus'] ?? 'published');
            $publishedAt = $_POST['newsDate'] ?? date('Y-m-d');

            if (empty($title)) {
                throw new Exception('Title is required.');
            }
            
            if (empty($content)) {
                throw new Exception('Content is required.');
            }
            
            $allowedCategories = ['announcement', 'event', 'achievement', 'general'];
            if (!in_array($category, $allowedCategories)) {
                $category = 'general';
            }
            
            $allowedStatuses = ['draft', 'published', 'archived'];
            if (!in_array($status, $allowedStatuses)) {
                $status = 'published';
            }

            $stmt = $pdo->prepare("
                INSERT INTO school_news (Title, Content, Image_Path, Category, Published_By, Published_At, Status, Views)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0)
            ");
            $stmt->execute([$title, $content, $imagePath ?: null, $category, $adminId, $publishedAt . ' ' . date('H:i:s'), $status]);
            
            $successMessage = 'News post created successfully!';
            header("Location: school-news-management.php?success=1&message=" . urlencode($successMessage));
            exit();
            
        } elseif ($_POST['action'] === 'updateNews') {
            $newsId = intval($_POST['newsId'] ?? 0);
            $title = trim($_POST['newsTitle'] ?? '');
            $content = trim($_POST['newsContent'] ?? '');
            $imagePath = trim($_POST['newsImage'] ?? '');
            $category = trim($_POST['newsCategory'] ?? 'general');
            $status = trim($_POST['newsStatus'] ?? 'published');
            
            if ($newsId <= 0) {
                throw new Exception('Invalid news ID.');
            }
            
            if (empty($title)) {
                throw new Exception('Title is required.');
            }
            
            if (empty($content)) {
                throw new Exception('Content is required.');
            }
            
            $allowedCategories = ['announcement', 'event', 'achievement', 'general'];
            if (!in_array($category, $allowedCategories)) {
                $category = 'general';
            }
            
            $allowedStatuses = ['draft', 'published', 'archived'];
            if (!in_array($status, $allowedStatuses)) {
                $status = 'published';
            }

            $stmt = $pdo->prepare("
                UPDATE school_news 
                SET Title = ?, Content = ?, Image_Path = ?, Category = ?, Status = ?
                WHERE News_ID = ?
            ");
            $stmt->execute([$title, $content, $imagePath ?: null, $category, $status, $newsId]);
            
            $successMessage = 'News post updated successfully!';
            header("Location: school-news-management.php?success=1&message=" . urlencode($successMessage));
            exit();
            
        } elseif ($_POST['action'] === 'deleteNews') {
            $newsId = intval($_POST['newsId'] ?? 0);
            
            if ($newsId <= 0) {
                throw new Exception('Invalid news ID.');
            }

            $stmt = $pdo->prepare("DELETE FROM school_news WHERE News_ID = ?");
            $stmt->execute([$newsId]);
            
            $successMessage = 'News post deleted successfully!';
            header("Location: school-news-management.php?success=1&message=" . urlencode($successMessage));
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

$allNews = [];
try {
    $stmt = $pdo->prepare("
        SELECT News_ID, Title, Content, Image_Path, Category, Published_By, Published_At, Status, Views
        FROM school_news
        ORDER BY Published_At DESC
    ");
    $stmt->execute();
    $allNews = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching news: " . $e->getMessage());
    $errorMessage = 'Error loading news: ' . $e->getMessage();
    $allNews = [];
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School News Management - HUDURY</title>
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
                <span class="page-icon">📰</span>
                <span data-en="School News Management" data-ar="إدارة أخبار المدرسة">School News Management</span>
            </h1>
            <p class="page-subtitle" data-en="Manage and publish school news posts" data-ar="إدارة ونشر منشورات أخبار المدرسة">Manage and publish school news posts</p>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">➕</span>
                    <span data-en="Create New Post" data-ar="إنشاء منشور جديد">Create New Post</span>
                </h2>
            </div>
            <form method="POST" action="school-news-management.php" onsubmit="return validateNewsForm(event)">
                <input type="hidden" name="action" value="createNews">
                <div class="form-group">
                    <label data-en="Title" data-ar="العنوان">Title <span style="color: red;">*</span></label>
                    <input type="text" id="newsTitle" name="newsTitle" required>
                </div>
                <div class="form-group">
                    <label data-en="Category" data-ar="الفئة">Category</label>
                    <select id="newsCategory" name="newsCategory">
                        <option value="general" data-en="General" data-ar="عام">General</option>
                        <option value="announcement" data-en="Announcement" data-ar="إعلان">Announcement</option>
                        <option value="event" data-en="Event" data-ar="حدث">Event</option>
                        <option value="achievement" data-en="Achievement" data-ar="إنجاز">Achievement</option>
                    </select>
                </div>
                <div class="form-group">
                    <label data-en="Image URL (Optional)" data-ar="رابط الصورة (اختياري)">Image URL (Optional)</label>
                    <input type="url" id="newsImage" name="newsImage" placeholder="https://example.com/image.jpg">
                </div>
                <div class="form-group">
                    <label data-en="Content" data-ar="المحتوى">Content <span style="color: red;">*</span></label>
                    <textarea id="newsContent" name="newsContent" rows="6" required></textarea>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <div class="form-group">
                        <label data-en="Publish Date" data-ar="تاريخ النشر">Publish Date</label>
                        <input type="date" id="newsDate" name="newsDate" required>
                    </div>
                    <div class="form-group">
                        <label data-en="Status" data-ar="الحالة">Status</label>
                        <select id="newsStatus" name="newsStatus" required>
                            <option value="published" data-en="Published" data-ar="منشور">Published</option>
                            <option value="draft" data-en="Draft" data-ar="مسودة">Draft</option>
                            <option value="archived" data-en="Archived" data-ar="مؤرشف">Archived</option>
                        </select>
                    </div>
                </div>
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" data-en="Publish Post" data-ar="نشر المنشور">Publish Post</button>
                    <button type="button" class="btn btn-secondary" onclick="document.querySelector('form').reset(); document.getElementById('newsDate').value = new Date().toISOString().split('T')[0];" data-en="Clear" data-ar="مسح">Clear</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📋</span>
                    <span data-en="Published News" data-ar="الأخبار المنشورة">Published News</span>
                </h2>
                <select class="filter-select" id="newsFilter" onchange="filterNews()" style="margin: 0;">
                    <option value="all" data-en="All Posts" data-ar="جميع المنشورات">All Posts</option>
                    <option value="published" data-en="Published" data-ar="منشور">Published</option>
                    <option value="draft" data-en="Draft" data-ar="مسودة">Draft</option>
                </select>
            </div>
            <div id="newsList" class="user-list">
                <?php if (empty($allNews)): ?>
                    <div style="text-align: center; padding: 3rem; color: #666;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">📰</div>
                        <div data-en="No news posts found" data-ar="لا توجد منشورات أخبار">No news posts found</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($allNews as $news): ?>
                        <?php
                        $publishedDate = new DateTime($news['Published_At']);
                        $formattedDate = $publishedDate->format('M d, Y');
                        $statusClass = 'status-pending';
                        $statusText = 'Draft';
                        if ($news['Status'] === 'published') {
                            $statusClass = 'status-active';
                            $statusText = 'Published';
                        } elseif ($news['Status'] === 'archived') {
                            $statusClass = 'status-inactive';
                            $statusText = 'Archived';
                        }
                        $categoryLabels = [
                            'general' => ['en' => 'General', 'ar' => 'عام'],
                            'announcement' => ['en' => 'Announcement', 'ar' => 'إعلان'],
                            'event' => ['en' => 'Event', 'ar' => 'حدث'],
                            'achievement' => ['en' => 'Achievement', 'ar' => 'إنجاز']
                        ];
                        $categoryLabel = $categoryLabels[$news['Category']] ?? ['en' => $news['Category'], 'ar' => $news['Category']];
                        ?>
                        <div class="user-item" 
                             data-news-id="<?php echo $news['News_ID']; ?>"
                             data-status="<?php echo $news['Status']; ?>"
                             data-title="<?php echo htmlspecialchars(strtolower($news['Title'])); ?>"
                             data-content="<?php echo htmlspecialchars(strtolower($news['Content'])); ?>">
                            <div class="user-info-item" style="flex: 1;">
                                <?php if ($news['Image_Path']): ?>
                                    <img src="<?php echo htmlspecialchars($news['Image_Path']); ?>" 
                                         style="width: 80px; height: 80px; object-fit: cover; border-radius: 10px; margin-right: 1rem;" 
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="user-avatar-item" style="display: none;">📰</div>
                                <?php else: ?>
                                    <div class="user-avatar-item">📰</div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight: 700;"><?php echo htmlspecialchars($news['Title']); ?></div>
                                    <div style="font-size: 0.85rem; color: #999; margin-top: 0.2rem;">
                                        <span style="background: #FFE5E5; padding: 0.2rem 0.5rem; border-radius: 10px; font-size: 0.8rem; margin-right: 0.5rem;" data-en="<?php echo $categoryLabel['en']; ?>" data-ar="<?php echo $categoryLabel['ar']; ?>"><?php echo $categoryLabel['en']; ?></span>
                                        <?php echo $formattedDate; ?> • <?php echo $news['Views']; ?> views
                                    </div>
                                    <div style="font-size: 0.9rem; color: #666; margin-top: 0.3rem;">
                                        <?php echo htmlspecialchars(substr($news['Content'], 0, 100)) . (strlen($news['Content']) > 100 ? '...' : ''); ?>
                                    </div>
                                </div>
                            </div>
                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                <span class="status-badge <?php echo $statusClass; ?>">
                                    <?php echo $statusText; ?>
                                </span>
                                <button class="btn btn-secondary btn-small" onclick="editNews(<?php echo $news['News_ID']; ?>)" data-en="Edit" data-ar="تعديل">Edit</button>
                                <button class="btn btn-danger btn-small" onclick="deleteNews(<?php echo $news['News_ID']; ?>)" data-en="Delete" data-ar="حذف">Delete</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal" id="editNewsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" data-en="Edit News Post" data-ar="تعديل منشور الأخبار">Edit News Post</h2>
                <button class="modal-close" onclick="closeModal('editNewsModal')">&times;</button>
            </div>
            <form method="POST" action="school-news-management.php" onsubmit="return validateNewsForm(event)">
                <input type="hidden" name="action" value="updateNews">
                <input type="hidden" id="editNewsId" name="newsId">
                <div class="form-group">
                    <label data-en="Title" data-ar="العنوان">Title <span style="color: red;">*</span></label>
                    <input type="text" id="editNewsTitle" name="newsTitle" required>
                </div>
                <div class="form-group">
                    <label data-en="Category" data-ar="الفئة">Category</label>
                    <select id="editNewsCategory" name="newsCategory">
                        <option value="general" data-en="General" data-ar="عام">General</option>
                        <option value="announcement" data-en="Announcement" data-ar="إعلان">Announcement</option>
                        <option value="event" data-en="Event" data-ar="حدث">Event</option>
                        <option value="achievement" data-en="Achievement" data-ar="إنجاز">Achievement</option>
                    </select>
                </div>
                <div class="form-group">
                    <label data-en="Image URL" data-ar="رابط الصورة">Image URL</label>
                    <input type="url" id="editNewsImage" name="newsImage">
                </div>
                <div class="form-group">
                    <label data-en="Content" data-ar="المحتوى">Content <span style="color: red;">*</span></label>
                    <textarea id="editNewsContent" name="newsContent" rows="6" required></textarea>
                </div>
                <div class="form-group">
                    <label data-en="Status" data-ar="الحالة">Status</label>
                    <select id="editNewsStatus" name="newsStatus" required>
                        <option value="published" data-en="Published" data-ar="منشور">Published</option>
                        <option value="draft" data-en="Draft" data-ar="مسودة">Draft</option>
                        <option value="archived" data-en="Archived" data-ar="مؤرشف">Archived</option>
                    </select>
                </div>
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" data-en="Update Post" data-ar="تحديث المنشور">Update Post</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editNewsModal')" data-en="Cancel" data-ar="إلغاء">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        
        const allNews = <?php echo json_encode($allNews, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        let newsDataMap = {};
        allNews.forEach(news => {
            newsDataMap[news.News_ID] = news;
        });

        function formatDate(dateString) {
            const date = new Date(dateString);
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            return months[date.getMonth()] + ' ' + date.getDate() + ', ' + date.getFullYear();
        }

        function validateNewsForm(event) {
            const title = event.target.querySelector('[name="newsTitle"]').value.trim();
            const content = event.target.querySelector('[name="newsContent"]').value.trim();
            
            if (!title) {
                alert((typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'يرجى إدخال العنوان.' : 'Please enter a title.');
                event.preventDefault();
                return false;
            }
            
            if (!content) {
                alert((typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'يرجى إدخال المحتوى.' : 'Please enter content.');
                event.preventDefault();
                return false;
            }
            
            return true;
        }

        function editNews(newsId) {
            const news = newsDataMap[newsId];
            if (!news) {
                alert('News post not found.');
                return;
            }
            
            document.getElementById('editNewsId').value = news.News_ID;
            document.getElementById('editNewsTitle').value = news.Title;
            document.getElementById('editNewsContent').value = news.Content;
            document.getElementById('editNewsImage').value = news.Image_Path || '';
            document.getElementById('editNewsCategory').value = news.Category || 'general';
            document.getElementById('editNewsStatus').value = news.Status || 'published';
            
            openModal('editNewsModal');
        }

        function deleteNews(newsId) {
            const lang = (typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'ar' : 'en';
            const confirmMsg = lang === 'en' ? 'Are you sure you want to delete this news post?' : 'هل أنت متأكد من حذف منشور الأخبار هذا؟';
            
            if (!confirm(confirmMsg)) {
                return;
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'school-news-management.php';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'deleteNews';
            form.appendChild(actionInput);
            
            const newsIdInput = document.createElement('input');
            newsIdInput.type = 'hidden';
            newsIdInput.name = 'newsId';
            newsIdInput.value = newsId;
            form.appendChild(newsIdInput);
            
            document.body.appendChild(form);
            form.submit();
        }

        function filterNews() {
            const filter = document.getElementById('newsFilter').value;
            const newsItems = document.querySelectorAll('#newsList .user-item');
            
            newsItems.forEach(item => {
                const status = item.getAttribute('data-status');
                const matchesFilter = filter === 'all' || status === filter;
                
                if (matchesFilter) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const newsDateInput = document.getElementById('newsDate');
            if (newsDateInput && !newsDateInput.value) {
                newsDateInput.value = new Date().toISOString().split('T')[0];
            }
        });
    </script>
</body>
</html>

