<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-student.php';

requireUserType('student');

$currentStudentId = getCurrentUserId();
$currentStudent = getCurrentUserData($pdo);
$studentName = $_SESSION['user_name'] ?? 'Student';

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

$currentStudentId = isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student' ? intval($_SESSION['user_id']) : null;
$currentStudentClassId = null;

if ($currentStudentId) {
    try {
        $stmt = $pdo->prepare("SELECT Class_ID FROM student WHERE Student_ID = ?");
        $stmt->execute([$currentStudentId]);
        $studentData = $stmt->fetch(PDO::FETCH_ASSOC);
        $currentStudentClassId = $studentData['Class_ID'] ?? null;
    } catch (PDOException $e) {
        error_log("Error fetching student class: " . $e->getMessage());
    }
}

$studentAssignments = [];
$studentSubmissions = [];

if ($currentStudentId && $currentStudentClassId) {
    try {
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.Course_ID
            FROM course c
            INNER JOIN course_class cc ON c.Course_ID = cc.Course_ID
            WHERE cc.Class_ID = ?
        ");
        $stmt->execute([$currentStudentClassId]);
        $studentCourseIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($studentCourseIds)) {
            $coursePlaceholders = implode(',', array_fill(0, count($studentCourseIds), '?'));

            $stmt = $pdo->prepare("
                SELECT a.*, 
                       co.Course_Name, co.Course_ID,
                       t.NameEn as Teacher_Name, t.NameAr as Teacher_NameAr,
                       c.Name as Class_Name
                FROM assignment a
                INNER JOIN class c ON a.Class_ID = c.Class_ID
                INNER JOIN course co ON a.Course_ID = co.Course_ID
                LEFT JOIN teacher t ON a.Teacher_ID = t.Teacher_ID
                WHERE a.Class_ID = ? 
                AND a.Course_ID IN ($coursePlaceholders)
                AND a.Status = 'active'
                ORDER BY a.Upload_Date DESC, a.Due_Date ASC
            ");
            $params = array_merge([$currentStudentClassId], $studentCourseIds);
            $stmt->execute($params);
            $studentAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $studentAssignments = [];
        }

        if (!empty($studentAssignments)) {
            $assignmentIds = array_column($studentAssignments, 'Assignment_ID');
            $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));
            
            $stmt = $pdo->prepare("
                SELECT * FROM submission
                WHERE Student_ID = ? AND Assignment_ID IN ($placeholders)
            ");
            $params = array_merge([$currentStudentId], $assignmentIds);
            $stmt->execute($params);
            $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($submissions as $sub) {
                $studentSubmissions[$sub['Assignment_ID']] = $sub;
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching student assignments: " . $e->getMessage());
        $studentAssignments = [];
    }
}

$notifications = [];
try {
    $conditions = ["Target_Role = 'All'", "Target_Role = 'Student'"];
    $params = [];
    
    if ($currentStudentId) {
        $conditions[] = "(Target_Role = 'Student' AND Target_Student_ID = ?)";
        $params[] = $currentStudentId;
    }
    if ($currentStudentClassId) {
        $conditions[] = "(Target_Role = 'Student' AND Target_Class_ID = ?)";
        $params[] = $currentStudentClassId;
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
    error_log("Error fetching notifications for students: " . $e->getMessage());
    $notifications = [];
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Assignments - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
        .back-button {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
            padding: 0.8rem 1.5rem;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 700;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            margin-bottom: 1rem;
        }
        .back-button:hover {
            background: rgba(255,255,255,0.3);
            transform: translateX(-5px);
        }
    </style>
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>
    
    <div class="main-content">

    <div class="dashboard-container">
        
        <div class="page-header-section">
            <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                <div style="font-size: 2.5rem;">👨‍🎓</div>
                <div>
                    <h1 class="page-title" style="margin: 0; font-size: 1.8rem;">
                        <span data-en="My Assignments" data-ar="واجباتي">My Assignments</span>
                    </h1>
                    <div style="display: flex; gap: 1.5rem; margin-top: 0.5rem; font-size: 1rem; color: #666;">
                        <div>
                            <strong data-en="Student:" data-ar="الطالب:">Student:</strong> 
                            <span><?php echo htmlspecialchars($studentName); ?></span>
                        </div>
                        <?php if ($currentStudentClassId): 
                            try {
                                $stmt = $pdo->prepare("SELECT Name FROM class WHERE Class_ID = ?");
                                $stmt->execute([$currentStudentClassId]);
                                $classData = $stmt->fetch(PDO::FETCH_ASSOC);
                                $className = $classData['Name'] ?? 'N/A';
                            } catch (PDOException $e) {
                                $className = 'N/A';
                            }
                        ?>
                            <div>
                                <strong data-en="Class:" data-ar="الفصل:">Class:</strong> 
                                <span><?php echo htmlspecialchars($className); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom: 1.5rem;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; padding: 1.5rem;">
                <div style="text-align: center; padding: 1rem; background: #FFF9F5; border-radius: 15px;">
                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">📝</div>
                    <div style="font-size: 2rem; font-weight: 800; color: var(--primary-color);">
                        <?php echo count($studentAssignments); ?>
                    </div>
                    <div style="color: #666; font-weight: 600;" data-en="Total Assignments" data-ar="إجمالي الواجبات">Total Assignments</div>
                </div>
                <div style="text-align: center; padding: 1rem; background: #E5FFE5; border-radius: 15px;">
                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">✅</div>
                    <div style="font-size: 2rem; font-weight: 800; color: #6BCB77;">
                        <?php 
                        $solvedCount = 0;
                        foreach ($studentAssignments as $assignment) {
                            $sub = $studentSubmissions[$assignment['Assignment_ID']] ?? null;
                            if ($sub && $sub['Status'] === 'graded') {
                                $solvedCount++;
                            }
                        }
                        echo $solvedCount;
                        ?>
                    </div>
                    <div style="color: #666; font-weight: 600;" data-en="Solved Assignments" data-ar="الواجبات المحلولة">Solved Assignments</div>
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="filter-controls" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                
                <div class="form-group" style="margin-bottom: 0;">
                    <label data-en="Sort by Date" data-ar="ترتيب حسب التاريخ">Sort by Date</label>
                    <select class="filter-select" id="sortByDate" onchange="sortAssignments()">
                        <option value="newest" data-en="Newest First" data-ar="الأحدث أولاً">Newest First</option>
                        <option value="oldest" data-en="Oldest First" data-ar="الأقدم أولاً">Oldest First</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label data-en="Filter by Subject" data-ar="تصفية حسب المادة">Filter by Subject</label>
                    <select class="filter-select" id="filterBySubject" onchange="filterAssignments()">
                        <option value="all" data-en="All Subjects" data-ar="جميع المواد">All Subjects</option>
                        <?php 
                        
                        $uniqueCourses = [];
                        foreach ($studentAssignments as $assignment) {
                            $courseId = $assignment['Course_ID'];
                            $courseName = $assignment['Course_Name'];
                            if (!isset($uniqueCourses[$courseId])) {
                                $uniqueCourses[$courseId] = $courseName;
                            }
                        }
                        foreach ($uniqueCourses as $courseId => $courseName): 
                        ?>
                            <option value="<?php echo htmlspecialchars(strtolower($courseName)); ?>">
                                <?php echo htmlspecialchars($courseName); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label data-en="Filter by Status" data-ar="تصفية حسب الحالة">Filter by Status</label>
                    <select class="filter-select" id="filterByStatus" onchange="filterAssignments()">
                        <option value="all" data-en="All" data-ar="الكل">All</option>
                        <option value="solved" data-en="Solved" data-ar="محلول">Solved</option>
                        <option value="notsolved" data-en="Not Solved" data-ar="غير محلول">Not Solved</option>
                        <option value="late" data-en="Late" data-ar="متأخر">Late</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📋</span>
                    <span data-en="Assignments List" data-ar="قائمة الواجبات">Assignments List</span>
                </h2>
            </div>
            <div class="assignment-list" id="assignmentList">
                <?php if (empty($studentAssignments)): ?>
                    <div style="text-align: center; padding: 3rem; color: #999;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">📝</div>
                        <div data-en="No assignments available" data-ar="لا توجد واجبات متاحة">No assignments available</div>
                        <?php if (!$currentStudentClassId): ?>
                            <div style="font-size: 0.9rem; margin-top: 0.5rem; color: #FF6B9D;" data-en="Note: You are not assigned to a class yet." data-ar="ملاحظة: لم يتم تعيينك إلى فصل بعد.">
                                Note: You are not assigned to a class yet.
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($studentAssignments as $assignment): 
                        $dueDate = new DateTime($assignment['Due_Date']);
                        $now = new DateTime();
                        $isOverdue = $dueDate < $now;
                        $submission = $studentSubmissions[$assignment['Assignment_ID']] ?? null;
                        $isSubmitted = $submission !== null;
                        $isGraded = $submission && $submission['Status'] === 'graded';

                        $status = 'pending';
                        $statusClass = 'status-pending';
                        $statusText = 'Pending';
                        if ($isGraded) {
                            $status = 'completed';
                            $statusClass = 'status-completed';
                            $statusText = 'Graded';
                        } elseif ($isSubmitted) {
                            $status = 'submitted';
                            $statusClass = 'status-pending';
                            $statusText = 'Submitted';
                        } elseif ($isOverdue) {
                            $status = 'overdue';
                            $statusClass = 'status-overdue';
                            $statusText = 'Overdue';
                        }
                        
                        $courseName = $assignment['Course_Name'] ?? 'Unknown';
                        $teacherName = $assignment['Teacher_Name'] ?? $assignment['Teacher_NameAr'] ?? 'Teacher';
                        $totalMarks = $assignment['Total_Marks'] ?? 100;
                    ?>
                        <div class="assignment-card <?php echo $status; ?>" 
                             data-material="<?php echo strtolower($courseName); ?>" 
                             data-status="<?php echo $status; ?>" 
                             data-date="<?php echo $dueDate->format('Y-m-d'); ?>"
                             data-assignment-id="<?php echo $assignment['Assignment_ID']; ?>">
                            <div class="assignment-header">
                                <div class="assignment-title"><?php echo htmlspecialchars($assignment['Title']); ?></div>
                                <span class="assignment-status <?php echo $statusClass; ?>" 
                                      data-en="<?php echo $statusText; ?>" 
                                      data-ar="<?php echo $statusText === 'Pending' ? 'معلق' : ($statusText === 'Graded' ? 'مصحح' : ($statusText === 'Submitted' ? 'تم التقديم' : 'متأخر')); ?>">
                                    <?php echo $statusText; ?>
                                </span>
                            </div>
                            <div class="assignment-info">
                                <div class="assignment-info-item">
                                    <span><?php echo $isOverdue ? '⚠️' : '📅'; ?></span>
                                    <span data-en="Due: <?php echo $dueDate->format('M d, Y'); ?>" 
                                          data-ar="الاستحقاق: <?php echo $dueDate->format('Y-m-d'); ?>">
                                        Due: <?php echo $dueDate->format('M d, Y'); ?>
                                    </span>
                                </div>
                                <div class="assignment-info-item">
                                    <span>👩‍🏫</span>
                                    <span><?php echo htmlspecialchars($teacherName); ?></span>
                                </div>
                                <div class="assignment-info-item">
                                    <span><?php echo $isGraded ? '⭐' : '📊'; ?></span>
                                    <span>
                                        <?php if ($isGraded && $submission['Grade'] !== null): ?>
                                            <span data-en="Grade: <?php echo number_format($submission['Grade'], 1); ?>/<?php echo $totalMarks; ?>" 
                                                  data-ar="الدرجة: <?php echo number_format($submission['Grade'], 1); ?>/<?php echo $totalMarks; ?>">
                                                Grade: <?php echo number_format($submission['Grade'], 1); ?>/<?php echo $totalMarks; ?>
                                            </span>
                                        <?php else: ?>
                                            <span data-en="<?php echo $totalMarks; ?> points" data-ar="<?php echo $totalMarks; ?> نقطة">
                                                <?php echo $totalMarks; ?> points
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                            <?php if ($assignment['Description']): ?>
                            <div class="assignment-description" style="margin-top: 1rem; padding-top: 1rem; border-top: 2px solid #FFE5E5;">
                                <p><?php echo htmlspecialchars($assignment['Description']); ?></p>
                            </div>
                            <?php endif; ?>
                            <?php if ($isGraded && $submission['Feedback']): ?>
                            <div style="background: #FFF9F5; padding: 1rem; border-radius: 10px; margin-top: 1rem;">
                                <strong data-en="Feedback:" data-ar="التعليقات:">Feedback:</strong>
                                <div style="margin-top: 0.5rem;"><?php echo htmlspecialchars($submission['Feedback']); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!$isSubmitted || $isOverdue): ?>
                                <button class="btn btn-primary btn-small" 
                                        onclick="event.stopPropagation(); openSubmitModal(<?php echo $assignment['Assignment_ID']; ?>, '<?php echo htmlspecialchars($assignment['Title'], ENT_QUOTES); ?>')" 
                                        data-en="<?php echo $isOverdue ? 'Submit Now' : 'Submit Assignment'; ?>" 
                                        data-ar="<?php echo $isOverdue ? 'تقديم الآن' : 'تقديم الواجب'; ?>">
                                    <?php echo $isOverdue ? 'Submit Now' : 'Submit Assignment'; ?>
                                </button>
                            <?php elseif ($isSubmitted && !$isGraded): ?>
                                <div style="padding: 0.5rem; background: #E5F3FF; border-radius: 10px; margin-top: 1rem; text-align: center;">
                                    <span data-en="Submitted on <?php echo (new DateTime($submission['Submission_Date']))->format('M d, Y'); ?>" 
                                          data-ar="تم التقديم في <?php echo (new DateTime($submission['Submission_Date']))->format('Y-m-d'); ?>">
                                        Submitted on <?php echo (new DateTime($submission['Submission_Date']))->format('M d, Y'); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="submitModal" class="modal">
        <div class="modal-content">
            <span onclick="closeSubmitModal()" style="float: right; font-size: 32px; cursor: pointer; color: #FF6B9D; font-weight: bold;">&times;</span>
            <h2 style="margin-bottom: 1.5rem; color: #FF6B9D; font-family: 'Fredoka', sans-serif; font-size: 2rem;" data-en="Submit Assignment" data-ar="تقديم الواجب">Submit Assignment</h2>
            <form id="submitAssignmentForm" onsubmit="handleAssignmentSubmit(event)">
                <input type="hidden" id="assignmentId" value="">
                <div class="form-group">
                    <label data-en="Assignment Title" data-ar="عنوان الواجب">Assignment Title</label>
                    <input type="text" id="assignmentTitle" readonly>
                </div>
                <div class="form-group">
                    <label data-en="Upload File *" data-ar="رفع الملف *">Upload File *</label>
                    <div class="upload-area" onclick="document.getElementById('assignmentFile').click()">
                        <div class="upload-icon">📎</div>
                        <div data-en="Click to upload file or drag and drop" data-ar="انقر للرفع أو اسحب وأفلت">Click to upload file or drag and drop</div>
                        <input type="file" id="assignmentFile" style="display: none;" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png" required>
                    </div>
                    <div id="fileList" style="margin-top: 1rem;"></div>
                </div>
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" style="width: 100%;" data-en="Submit Assignment" data-ar="تقديم الواجب">Submit Assignment</button>
                    <button type="button" class="btn btn-secondary" style="width: 100%;" onclick="closeSubmitModal()" data-en="Cancel" data-ar="إلغاء">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        function toggleNotificationsDropdown() {
            const dropdown = document.getElementById('notificationsDropdown');
            if (dropdown) dropdown.classList.toggle('active');
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
        
        function openSubmitModal(assignmentId, assignmentTitle) {
            document.getElementById('assignmentId').value = assignmentId;
            document.getElementById('assignmentTitle').value = assignmentTitle;
            document.getElementById('submitModal').style.display = 'flex';
        }

        function closeSubmitModal() {
            document.getElementById('submitModal').style.display = 'none';
            document.getElementById('submitAssignmentForm').reset();
            document.getElementById('fileList').innerHTML = '';
        }

        function handleAssignmentSubmit(event) {
            event.preventDefault();
            
            const assignmentId = document.getElementById('assignmentId').value;
            const fileInput = document.getElementById('assignmentFile');
            
            if (!fileInput.files || fileInput.files.length === 0) {
                alert(currentLanguage === 'en' ? 'Please select a file to upload' : 'يرجى اختيار ملف للرفع');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'submitAssignment');
            formData.append('assignment_id', assignmentId);
            formData.append('submission_file', fileInput.files[0]);

            const submitBtn = event.target.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = currentLanguage === 'en' ? 'Submitting...' : 'جاري التقديم...';
            
            fetch('submit-assignment-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.textContent = currentLanguage === 'en' ? 'Submit Assignment' : 'تقديم الواجب';
                
                if (data.success) {
                    alert(data.message || (currentLanguage === 'en' ? 'Assignment submitted successfully!' : 'تم تقديم الواجب بنجاح!'));
                    closeSubmitModal();
                    location.reload();
                } else {
                    alert(data.message || (currentLanguage === 'en' ? 'Error submitting assignment' : 'خطأ في تقديم الواجب'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                submitBtn.disabled = false;
                submitBtn.textContent = currentLanguage === 'en' ? 'Submit Assignment' : 'تقديم الواجب';
                alert(currentLanguage === 'en' ? 'Error submitting assignment' : 'خطأ في تقديم الواجب');
            });
        }

        function filterAssignments() {
            const subjectFilter = document.getElementById('filterBySubject').value.toLowerCase();
            const statusFilter = document.getElementById('filterByStatus').value;
            const items = document.querySelectorAll('.assignment-card');
            
            items.forEach(item => {
                const material = (item.dataset.material || '').toLowerCase();
                const status = item.dataset.status || '';

                const showSubject = subjectFilter === 'all' || material === subjectFilter;

                let showStatus = true;
                if (statusFilter !== 'all') {
                    if (statusFilter === 'solved') {
                        showStatus = (status === 'completed'); 
                    } else if (statusFilter === 'notsolved') {
                        showStatus = (status === 'pending' || status === 'submitted');
                    } else if (statusFilter === 'late') {
                        showStatus = (status === 'overdue');
                    }
                }
                
                if (showSubject && showStatus) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        function sortAssignments() {
            const sortBy = document.getElementById('sortByDate').value;
            const container = document.getElementById('assignmentList');
            const items = Array.from(container.querySelectorAll('.assignment-card'));
            
            items.sort((a, b) => {
                if (sortBy === 'newest') {
                    return new Date(b.dataset.date) - new Date(a.dataset.date); 
                } else if (sortBy === 'oldest') {
                    return new Date(a.dataset.date) - new Date(b.dataset.date); 
                }
                return 0;
            });
            
            items.forEach(item => container.appendChild(item));
        }

        document.getElementById('assignmentFile')?.addEventListener('change', function(e) {
            const fileList = document.getElementById('fileList');
            fileList.innerHTML = '';
            
            if (e.target.files && e.target.files.length > 0) {
                const file = e.target.files[0];
                const fileItem = document.createElement('div');
                fileItem.style.cssText = 'padding: 0.5rem; background: #FFF9F5; border-radius: 10px; margin-bottom: 0.5rem; display: flex; justify-content: space-between; align-items: center;';
                fileItem.innerHTML = `
                    <span>📄 ${file.name} (${(file.size / 1024).toFixed(2)} KB)</span>
                    <button type="button" onclick="this.parentElement.remove(); document.getElementById('assignmentFile').value='';" style="background: #FF6B9D; color: white; border: none; padding: 0.3rem 0.8rem; border-radius: 5px; cursor: pointer;">×</button>
                `;
                fileList.appendChild(fileItem);
            }
        });

        window.onclick = function(event) {
            const modal = document.getElementById('submitModal');
            if (event.target === modal) {
                closeSubmitModal();
            }
        }
    </script>
</body>
</html>

