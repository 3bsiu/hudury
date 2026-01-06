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

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `exam` (
          `Exam_ID` int(11) NOT NULL AUTO_INCREMENT,
          `Title` varchar(255) NOT NULL,
          `Subject` varchar(100) NOT NULL,
          `Course_ID` int(11) DEFAULT NULL,
          `Exam_Date` date NOT NULL,
          `Exam_Time` time NOT NULL,
          `Duration` int(11) NOT NULL COMMENT 'Duration in minutes',
          `Total_Marks` decimal(5,2) NOT NULL,
          `Description` text DEFAULT NULL,
          `Created_By` int(11) DEFAULT NULL COMMENT 'Admin_ID',
          `Created_At` datetime DEFAULT current_timestamp(),
          PRIMARY KEY (`Exam_ID`),
          KEY `Course_ID` (`Course_ID`),
          KEY `Created_By` (`Created_By`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `exam_class` (
          `Exam_ID` int(11) NOT NULL,
          `Class_ID` int(11) NOT NULL,
          PRIMARY KEY (`Exam_ID`,`Class_ID`),
          KEY `Class_ID` (`Class_ID`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    try {
        
        $indexes = $pdo->query("SHOW INDEX FROM exam_class")->fetchAll(PDO::FETCH_ASSOC);
        $hasWrongUnique = false;
        
        foreach ($indexes as $index) {
            
            $isWrongUnique = ($index['Key_name'] === 'Class_ID_2') || 
                           ($index['Column_name'] === 'Class_ID' && $index['Non_unique'] == 0 && $index['Key_name'] !== 'PRIMARY');
            
            if ($isWrongUnique) {
                $hasWrongUnique = true;
                
                try {
                    $pdo->exec("ALTER TABLE exam_class DROP INDEX `{$index['Key_name']}`");
                    error_log("Removed UNIQUE constraint '{$index['Key_name']}' on Class_ID - multiple exams per class now allowed");
                } catch (PDOException $e) {
                    error_log("Error removing constraint '{$index['Key_name']}': " . $e->getMessage());
                }
            }
        }
    } catch (PDOException $e) {
        
        error_log("Note: Index check (table may not exist yet): " . $e->getMessage());
    }

    try {
        $indexes = $pdo->query("SHOW INDEX FROM exam_class WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
        $primaryColumns = array_column($indexes, 'Column_name');

        if (!in_array('Exam_ID', $primaryColumns) || !in_array('Class_ID', $primaryColumns)) {
            
            try {
                $pdo->exec("ALTER TABLE exam_class DROP PRIMARY KEY");
            } catch (PDOException $e) {
                
            }
            
            $pdo->exec("ALTER TABLE exam_class ADD PRIMARY KEY (Exam_ID, Class_ID)");
            error_log("Fixed primary key: Added composite PRIMARY KEY (Exam_ID, Class_ID)");
        }
    } catch (PDOException $e) {
        error_log("Note: Primary key check: " . $e->getMessage());
    }
} catch (PDOException $e) {
    error_log("Note: exam tables check: " . $e->getMessage());
}

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'createExam' || $action === 'updateExam') {
        try {
            $pdo->beginTransaction();
            
            $examId = isset($_POST['examId']) && $_POST['examId'] !== '' ? intval($_POST['examId']) : null;
            $title = trim($_POST['examTitle'] ?? '');
            $subject = trim($_POST['examSubject'] ?? '');
            $courseId = isset($_POST['courseId']) && $_POST['courseId'] !== '' ? intval($_POST['courseId']) : null;
            $examDate = $_POST['examDate'] ?? '';
            $examTime = $_POST['examTime'] ?? '';
            $duration = intval($_POST['examDuration'] ?? 0);
            $totalMarks = floatval($_POST['examMarks'] ?? 0);
            $description = trim($_POST['examDescription'] ?? '');
            $classIds = isset($_POST['classIds']) && is_array($_POST['classIds']) ? array_map('intval', $_POST['classIds']) : [];

            if (empty($title)) {
                throw new Exception('Exam title is required');
            }
            if (empty($subject)) {
                throw new Exception('Subject is required');
            }
            if (empty($examDate)) {
                throw new Exception('Exam date is required');
            }
            if (empty($examTime)) {
                throw new Exception('Exam time is required');
            }
            if ($duration <= 0) {
                throw new Exception('Duration must be greater than 0');
            }
            if ($totalMarks <= 0) {
                throw new Exception('Total marks must be greater than 0');
            }
            if (empty($classIds)) {
                throw new Exception('Please select at least one class');
            }
            
            if ($examId) {
                
                $stmt = $pdo->prepare("
                    UPDATE exam 
                    SET Title = ?, Subject = ?, Course_ID = ?, Exam_Date = ?, Exam_Time = ?, 
                        Duration = ?, Total_Marks = ?, Description = ?
                    WHERE Exam_ID = ? AND Created_By = ?
                ");
                $stmt->execute([
                    $title, $subject, $courseId, $examDate, $examTime,
                    $duration, $totalMarks, $description ?: null,
                    $examId, $currentAdminId
                ]);

                $stmt = $pdo->prepare("DELETE FROM exam_class WHERE Exam_ID = ?");
                $stmt->execute([$examId]);
            } else {
                
                $stmt = $pdo->prepare("
                    INSERT INTO exam (Title, Subject, Course_ID, Exam_Date, Exam_Time, Duration, Total_Marks, Description, Created_By)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $title, $subject, $courseId, $examDate, $examTime,
                    $duration, $totalMarks, $description ?: null, $currentAdminId
                ]);
                $examId = $pdo->lastInsertId();
            }

            $stmt = $pdo->prepare("INSERT INTO exam_class (Exam_ID, Class_ID) VALUES (?, ?)");
            foreach ($classIds as $classId) {
                $result = $stmt->execute([$examId, $classId]);
                if (!$result) {
                    error_log("Failed to insert exam_class relationship: Exam_ID=$examId, Class_ID=$classId");
                } else {
                    error_log("Successfully linked exam $examId to class $classId");
                }
            }
            
            $pdo->commit();

            $classCount = count($classIds);
            $details = "Subject: {$subject}, Date: {$examDate}, Classes: {$classCount}";
            logExamAction($pdo, $examId && isset($_POST['examId']) ? 'update' : 'create', $examId, $title, $details);
            
            $successMessage = $examId && isset($_POST['examId']) ? 'Exam updated successfully!' : 'Exam created successfully!';
            header("Location: exam-management.php?success=1");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error saving exam: " . $e->getMessage());
            $errorMessage = $e->getMessage();
        }
    } elseif ($action === 'deleteExam') {
        try {
            $pdo->beginTransaction();
            
            $examId = intval($_POST['examId'] ?? 0);
            
            if ($examId <= 0) {
                throw new Exception('Invalid exam ID');
            }

            $stmt = $pdo->prepare("SELECT Title, Subject FROM exam WHERE Exam_ID = ? AND Created_By = ?");
            $stmt->execute([$examId, $currentAdminId]);
            $examData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$examData) {
                throw new Exception('Exam not found or access denied');
            }

            $stmt = $pdo->prepare("DELETE FROM exam WHERE Exam_ID = ? AND Created_By = ?");
            $stmt->execute([$examId, $currentAdminId]);
            
            $pdo->commit();

            logExamAction($pdo, 'delete', $examId, $examData['Title'], "Subject: {$examData['Subject']}");
            
            $successMessage = 'Exam deleted successfully!';
            header("Location: exam-management.php?success=1");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error deleting exam: " . $e->getMessage());
            $errorMessage = $e->getMessage();
        }
    }
}

if (isset($_GET['success'])) {
    $successMessage = 'Operation completed successfully!';
}

$classes = [];
try {
    $stmt = $pdo->query("SELECT Class_ID, Grade_Level, Section, Name FROM class ORDER BY Grade_Level, Section, Name");
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    error_log("Exam Management: Fetched " . count($classes) . " classes from database");
    if (count($classes) === 0) {
        error_log("WARNING: No classes found in database. Please create classes first.");
    }
} catch (PDOException $e) {
    error_log("Error fetching classes: " . $e->getMessage());
    error_log("SQL Error Info: " . print_r($e->errorInfo, true));
    
    if (!isset($errorMessage)) {
        $errorMessage = "Error loading classes from database: " . $e->getMessage();
    }
}

$courses = [];
try {
    $stmt = $pdo->query("SELECT Course_ID, Course_Name FROM course ORDER BY Course_Name");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching courses: " . $e->getMessage());
}

$exams = [];
try {
    $stmt = $pdo->prepare("
        SELECT e.*, 
               GROUP_CONCAT(c.Name ORDER BY c.Grade_Level, c.Section SEPARATOR ', ') as Class_Names,
               GROUP_CONCAT(c.Class_ID ORDER BY c.Grade_Level, c.Section SEPARATOR ',') as Class_IDs
        FROM exam e
        LEFT JOIN exam_class ec ON e.Exam_ID = ec.Exam_ID
        LEFT JOIN class c ON ec.Class_ID = c.Class_ID
        WHERE e.Created_By = ?
        GROUP BY e.Exam_ID
        ORDER BY e.Exam_Date DESC, e.Exam_Time DESC
    ");
    $stmt->execute([$currentAdminId]);
    $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching exams: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Management - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">📝</span>
                <span data-en="Exam Management" data-ar="إدارة الامتحانات">Exam Management</span>
            </h1>
            <p class="page-subtitle" data-en="Create and manage exams for classes" data-ar="إنشاء وإدارة الامتحانات للفصول">Create and manage exams for classes</p>
        </div>

        <?php if ($successMessage): ?>
            <div style="background: #6BCB77; color: white; padding: 1rem; border-radius: 15px; margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($successMessage); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div style="background: #FF6B9D; color: white; padding: 1rem; border-radius: 15px; margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($errorMessage); ?></span>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">➕</span>
                    <span id="formTitle" data-en="Create New Exam" data-ar="إنشاء امتحان جديد">Create New Exam</span>
                </h2>
            </div>
            <form id="examForm" method="POST" action="exam-management.php" onsubmit="return validateExamForm(event)">
                <input type="hidden" name="action" id="formAction" value="createExam">
                <input type="hidden" name="examId" id="examId" value="">
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
                    <div class="form-group">
                        <label data-en="Exam Title" data-ar="عنوان الامتحان">Exam Title <span style="color: red;">*</span></label>
                        <input type="text" id="examTitle" name="examTitle" required>
                    </div>
                    <div class="form-group">
                        <label data-en="Subject (Course)" data-ar="المادة (المقرر)">Subject (Course) <span style="color: red;">*</span></label>
                        <select id="examSubject" name="examSubject" required onchange="updateCourseId()">
                            <option value="">Select Subject</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?php echo htmlspecialchars($course['Course_Name']); ?>" data-course-id="<?php echo $course['Course_ID']; ?>">
                                    <?php echo htmlspecialchars($course['Course_Name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" id="courseId" name="courseId" value="">
                    </div>
                    <div class="form-group">
                        <label data-en="Exam Date" data-ar="تاريخ الامتحان">Exam Date <span style="color: red;">*</span></label>
                        <input type="date" id="examDate" name="examDate" required>
                    </div>
                    <div class="form-group">
                        <label data-en="Exam Time" data-ar="وقت الامتحان">Exam Time <span style="color: red;">*</span></label>
                        <input type="time" id="examTime" name="examTime" required>
                    </div>
                    <div class="form-group">
                        <label data-en="Duration (minutes)" data-ar="المدة (بالدقائق)">Duration (minutes) <span style="color: red;">*</span></label>
                        <input type="number" id="examDuration" name="examDuration" min="30" max="180" required>
                    </div>
                    <div class="form-group">
                        <label data-en="Total Marks" data-ar="الدرجة الكلية">Total Marks <span style="color: red;">*</span></label>
                        <input type="number" id="examMarks" name="examMarks" min="10" max="100" step="0.01" required>
                    </div>
                </div>
                <div class="form-group">
                    <label data-en="Select Classes" data-ar="اختر الفصول">Select Classes <span style="color: red;">*</span></label>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem; max-height: 300px; overflow-y: auto; padding: 1rem; background: #FFF9F5; border-radius: 15px; border: 2px solid #FFE5E5;">
                        <?php if (empty($classes)): ?>
                            <div style="color: #FF6B9D; padding: 1.5rem; text-align: center; font-weight: 600; background: #FFE5E5; border-radius: 10px;">
                                <i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 0.5rem; display: block;"></i>
                                <div style="margin-bottom: 0.5rem;" data-en="No classes found in database" data-ar="لم يتم العثور على فصول في قاعدة البيانات">No classes found in database</div>
                                <div style="font-size: 0.9rem; color: #666; font-weight: normal;" data-en="Please create classes first in User Management page." data-ar="يرجى إنشاء الفصول أولاً في صفحة إدارة المستخدمين.">
                                    Please create classes first in <a href="user-management.php" style="color: #FF6B9D; text-decoration: underline;">User Management</a> page.
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($classes as $class): ?>
                                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; padding: 0.5rem; border-radius: 10px; transition: all 0.3s;" onmouseover="this.style.background='#FFE5E5';" onmouseout="this.style.background='transparent';">
                                    <input type="checkbox" name="classIds[]" value="<?php echo $class['Class_ID']; ?>" class="class-checkbox">
                                    <span>
                                        <?php 
                                        
                                        if (!empty($class['Name'])) {
                                            echo htmlspecialchars($class['Name']);
                                        } else {
                                            echo 'Grade ' . htmlspecialchars($class['Grade_Level'] ?? 'N/A');
                                            if (!empty($class['Section'])) {
                                                echo ' - Section ' . htmlspecialchars($class['Section']);
                                            }
                                        }
                                        ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label data-en="Description" data-ar="الوصف">Description</label>
                    <textarea id="examDescription" name="examDescription" rows="4"></textarea>
                </div>
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" id="submitBtn" data-en="Create Exam" data-ar="إنشاء الامتحان">Create Exam</button>
                    <button type="button" class="btn btn-secondary" onclick="resetForm()" data-en="Clear" data-ar="مسح">Clear</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📋</span>
                    <span data-en="Scheduled Exams" data-ar="الامتحانات المجدولة">Scheduled Exams</span>
                </h2>
                <div class="search-filter-bar" style="margin: 0;">
                    <div class="search-box" style="margin: 0;">
                        <i class="fas fa-search"></i>
                        <input type="text" id="examSearch" placeholder="Search exams..." oninput="filterExams()">
                    </div>
                    <select class="filter-select" id="examFilter" onchange="filterExams()">
                        <option value="all" data-en="All Exams" data-ar="جميع الامتحانات">All Exams</option>
                        <option value="upcoming" data-en="Upcoming" data-ar="قادمة">Upcoming</option>
                        <option value="past" data-en="Past" data-ar="سابقة">Past</option>
                    </select>
                </div>
            </div>
            <div id="examsList" class="user-list">
                <?php if (empty($exams)): ?>
                    <div style="text-align: center; padding: 2rem; color: #666;">
                        <div style="font-size: 2rem; margin-bottom: 0.5rem;">📝</div>
                        <div data-en="No exams scheduled yet" data-ar="لا توجد امتحانات مجدولة بعد">No exams scheduled yet</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($exams as $exam): ?>
                        <?php
                        $examDate = new DateTime($exam['Exam_Date']);
                        $isUpcoming = $examDate >= new DateTime();
                        $formattedDate = $examDate->format('M d, Y');
                        $formattedTime = date('g:i A', strtotime($exam['Exam_Time']));
                        $classIds = explode(',', $exam['Class_IDs'] ?? '');
                        ?>
                        <div class="user-item" data-exam-id="<?php echo $exam['Exam_ID']; ?>" data-date="<?php echo $exam['Exam_Date']; ?>">
                            <div class="user-info-item" style="flex: 1;">
                                <div class="user-avatar-item">📝</div>
                                <div>
                                    <div style="font-weight: 700;"><?php echo htmlspecialchars($exam['Title']); ?></div>
                                    <div style="font-size: 0.9rem; color: #666;">
                                        <?php echo htmlspecialchars($exam['Subject']); ?> • 
                                        <?php echo $formattedDate; ?> at <?php echo $formattedTime; ?> • 
                                        <?php echo $exam['Duration']; ?> min • 
                                        <?php echo number_format($exam['Total_Marks'], 1); ?> marks
                                    </div>
                                    <div style="font-size: 0.85rem; color: #999; margin-top: 0.3rem;">
                                        Classes: <?php echo htmlspecialchars($exam['Class_Names'] ?? 'N/A'); ?>
                                    </div>
                                    <?php if ($exam['Description']): ?>
                                        <div style="font-size: 0.85rem; color: #666; margin-top: 0.3rem; font-style: italic;">
                                            <?php echo htmlspecialchars(substr($exam['Description'], 0, 100)) . (strlen($exam['Description']) > 100 ? '...' : ''); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="display: flex; gap: 0.5rem;">
                                <button class="btn btn-secondary btn-small" onclick="editExam(<?php echo $exam['Exam_ID']; ?>)" data-en="Edit" data-ar="تعديل">Edit</button>
                                <button class="btn btn-danger btn-small" onclick="deleteExam(<?php echo $exam['Exam_ID']; ?>)" data-en="Delete" data-ar="حذف">Delete</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        const allExams = <?php echo json_encode($exams, JSON_UNESCAPED_UNICODE); ?>;
        const allClasses = <?php echo json_encode($classes, JSON_UNESCAPED_UNICODE); ?>;
        const allCourses = <?php echo json_encode($courses, JSON_UNESCAPED_UNICODE); ?>;

        function updateCourseId() {
            const subjectSelect = document.getElementById('examSubject');
            const courseIdInput = document.getElementById('courseId');
            const selectedOption = subjectSelect.options[subjectSelect.selectedIndex];
            const courseId = selectedOption.getAttribute('data-course-id');
            courseIdInput.value = courseId || '';
        }

        function validateExamForm(event) {
            const classCheckboxes = document.querySelectorAll('input[name="classIds[]"]:checked');
            const lang = (typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'ar' : 'en';
            
            if (classCheckboxes.length === 0) {
                const errorMsg = lang === 'en' ? 'Please select at least one class!' : 'يرجى اختيار فصل واحد على الأقل!';
                alert(errorMsg);
                event.preventDefault();
                return false;
            }

            updateCourseId();
            
            return true;
        }

        function resetForm() {
            document.getElementById('examForm').reset();
            document.getElementById('formAction').value = 'createExam';
            document.getElementById('examId').value = '';
            document.getElementById('formTitle').textContent = (typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'إنشاء امتحان جديد' : 'Create New Exam';
            document.getElementById('submitBtn').textContent = (typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'إنشاء الامتحان' : 'Create Exam';
            document.querySelectorAll('.class-checkbox').forEach(cb => cb.checked = false);
        }

        function editExam(examId) {
            const exam = allExams.find(e => e.Exam_ID == examId);
            if (!exam) {
                alert((typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'الامتحان غير موجود' : 'Exam not found');
                return;
            }

            document.getElementById('examId').value = exam.Exam_ID;
            document.getElementById('examTitle').value = exam.Title;
            document.getElementById('examSubject').value = exam.Subject;
            document.getElementById('examDate').value = exam.Exam_Date;
            document.getElementById('examTime').value = exam.Exam_Time;
            document.getElementById('examDuration').value = exam.Duration;
            document.getElementById('examMarks').value = exam.Total_Marks;
            document.getElementById('examDescription').value = exam.Description || '';
            document.getElementById('formAction').value = 'updateExam';

            updateCourseId();

            const classIds = exam.Class_IDs ? exam.Class_IDs.split(',') : [];
            document.querySelectorAll('.class-checkbox').forEach(cb => {
                cb.checked = classIds.includes(cb.value);
            });

            document.getElementById('formTitle').textContent = (typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'تعديل الامتحان' : 'Edit Exam';
            document.getElementById('submitBtn').textContent = (typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'تحديث الامتحان' : 'Update Exam';

            document.querySelector('form').scrollIntoView({ behavior: 'smooth' });
        }

        function deleteExam(examId) {
            const lang = (typeof currentLanguage !== 'undefined' && currentLanguage === 'ar') ? 'ar' : 'en';
            const confirmMsg = lang === 'en' ? 'Are you sure you want to delete this exam?' : 'هل أنت متأكد من حذف هذا الامتحان؟';
            
            if (!confirm(confirmMsg)) {
                return;
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'exam-management.php';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'deleteExam';
            form.appendChild(actionInput);
            
            const examIdInput = document.createElement('input');
            examIdInput.type = 'hidden';
            examIdInput.name = 'examId';
            examIdInput.value = examId;
            form.appendChild(examIdInput);
            
            document.body.appendChild(form);
            form.submit();
        }

        function filterExams() {
            const searchTerm = document.getElementById('examSearch').value.toLowerCase();
            const filter = document.getElementById('examFilter').value;
            const items = document.querySelectorAll('.user-item[data-exam-id]');
            
            items.forEach(item => {
                const examId = item.getAttribute('data-exam-id');
                const exam = allExams.find(e => e.Exam_ID == examId);
                if (!exam) {
                    item.style.display = 'none';
                    return;
                }
                
                const matchesSearch = exam.Title.toLowerCase().includes(searchTerm) || 
                                    exam.Subject.toLowerCase().includes(searchTerm);
                
                const examDate = new Date(exam.Exam_Date);
                const isUpcoming = examDate >= new Date();
                const matchesFilter = filter === 'all' || 
                                    (filter === 'upcoming' && isUpcoming) ||
                                    (filter === 'past' && !isUpcoming);
                
                if (matchesSearch && matchesFilter) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
