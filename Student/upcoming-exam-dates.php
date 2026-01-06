<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-student.php';

requireUserType('student');

$currentStudentId = getCurrentUserId();
$currentStudent = getCurrentUserData($pdo);
$studentName = $_SESSION['user_name'] ?? 'Student';
$currentStudentClassId = null;

if ($currentStudentId && $currentStudent) {
    $currentStudentClassId = $currentStudent['Class_ID'] ?? null;
    $studentName = $currentStudent['NameEn'] ?? $currentStudent['Name'] ?? $studentName;
}

$upcomingExams = [];
if ($currentStudentClassId) {
    try {
        $today = date('Y-m-d');
        error_log("Upcoming Exam Dates: Student ID=$currentStudentId, Class_ID=$currentStudentClassId, Fetching exams with Date >= $today");

        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM exam_class WHERE Class_ID = ?");
        $stmt->execute([$currentStudentClassId]);
        $totalExamsForClass = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        error_log("Upcoming Exam Dates: Total exams linked to Class_ID $currentStudentClassId: $totalExamsForClass");

        $stmt = $pdo->prepare("
            SELECT e.*, c.Course_Name
            FROM exam e
            INNER JOIN exam_class ec ON e.Exam_ID = ec.Exam_ID
            LEFT JOIN course c ON e.Course_ID = c.Course_ID
            WHERE ec.Class_ID = ? AND e.Exam_Date >= ?
            ORDER BY e.Exam_Date ASC, e.Exam_Time ASC
        ");
        $stmt->execute([$currentStudentClassId, $today]);
        $upcomingExams = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Upcoming Exam Dates: Found " . count($upcomingExams) . " upcoming exams for student");
        if (count($upcomingExams) > 0) {
            error_log("First exam: " . print_r($upcomingExams[0], true));
        } elseif ($totalExamsForClass > 0) {
            
            error_log("WARNING: Found $totalExamsForClass exams for this class, but none with Exam_Date >= $today");
        }
    } catch (PDOException $e) {
        error_log("Error fetching upcoming exams for student: " . $e->getMessage());
        error_log("SQL Error Info: " . print_r($e->errorInfo, true));
    }
} else {
    error_log("Upcoming Exam Dates: No Class_ID found for student ID: " . $currentStudentId);
    
    if ($currentStudentId) {
        try {
            $stmt = $pdo->prepare("SELECT Student_ID, NameEn, Class_ID FROM student WHERE Student_ID = ?");
            $stmt->execute([$currentStudentId]);
            $studentData = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($studentData) {
                error_log("Student data: " . print_r($studentData, true));
                if (empty($studentData['Class_ID'])) {
                    error_log("WARNING: Student has no Class_ID assigned! Exams will not be visible.");
                }
            }
        } catch (PDOException $e) {
            error_log("Error fetching student data: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upcoming Exam Dates - HUDURY</title>
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
        .exam-dates-list {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        .exam-date-item {
            background: #FFF9F5;
            padding: 2rem;
            border-radius: 20px;
            border-left: 5px solid var(--primary-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        }
        .exam-date-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .exam-date-info {
            flex: 1;
        }
        .exam-date-title {
            font-weight: 700;
            font-size: 1.3rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }
        .exam-date-subject {
            font-size: 1rem;
            color: #666;
            margin-bottom: 0.5rem;
        }
        .exam-date-details {
            display: flex;
            gap: 2rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }
        .exam-detail-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            color: #666;
        }
        .exam-date-badge {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1rem 2rem;
            border-radius: 15px;
            font-weight: 700;
            font-size: 1.1rem;
            text-align: center;
            min-width: 150px;
            box-shadow: 0 3px 10px rgba(255, 107, 157, 0.3);
        }
        .exam-date-badge.urgent {
            background: linear-gradient(135deg, #FF6B6B, #EE5A6F);
            animation: pulse 2s infinite;
        }
        .exam-date-badge.upcoming {
            background: linear-gradient(135deg, #FFD93D, #FFB84D);
        }
        .filter-controls {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        .filter-select {
            padding: 0.8rem 1.5rem;
            border: 2px solid #FFE5E5;
            border-radius: 15px;
            background: white;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        .filter-select:hover {
            border-color: var(--primary-color);
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
    </style>
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>
    
    <div class="main-content">

    <div class="dashboard-container">
        
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">📋</span>
                <span data-en="Upcoming Exam Dates" data-ar="مواعيد الامتحانات القادمة">Upcoming Exam Dates</span>
            </h1>
            <p class="page-subtitle" data-en="View all your upcoming exams and prepare accordingly" data-ar="عرض جميع امتحاناتك القادمة والاستعداد لها">View all your upcoming exams and prepare accordingly</p>
        </div>

        <div class="filter-controls">
            <select class="filter-select" id="filterSubject" onchange="filterExams()">
                <option value="all" data-en="All Subjects" data-ar="جميع المواد">All Subjects</option>
                <option value="math" data-en="Mathematics" data-ar="الرياضيات">Mathematics</option>
                <option value="science" data-en="Science" data-ar="العلوم">Science</option>
                <option value="english" data-en="English" data-ar="الإنجليزية">English</option>
                <option value="arabic" data-en="Arabic" data-ar="العربية">Arabic</option>
                <option value="social" data-en="Social Studies" data-ar="الدراسات الاجتماعية">Social Studies</option>
            </select>
            <select class="filter-select" id="filterType" onchange="filterExams()">
                <option value="all" data-en="All Types" data-ar="جميع الأنواع">All Types</option>
                <option value="midterm" data-en="Midterm" data-ar="منتصف الفصل">Midterm</option>
                <option value="final" data-en="Final" data-ar="نهائي">Final</option>
                <option value="oral" data-en="Oral" data-ar="شفوي">Oral</option>
                <option value="written" data-en="Written" data-ar="كتابي">Written</option>
            </select>
            <select class="filter-select" id="sortExams" onchange="sortExams()">
                <option value="date" data-en="Sort by Date" data-ar="ترتيب حسب التاريخ">Sort by Date</option>
                <option value="subject" data-en="Sort by Subject" data-ar="ترتيب حسب المادة">Sort by Subject</option>
            </select>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📅</span>
                    <span data-en="Exams Schedule" data-ar="جدول الامتحانات">Exams Schedule</span>
                </h2>
            </div>
            <div class="exam-dates-list" id="examDatesList">
                <?php if (empty($upcomingExams)): ?>
                    <div style="text-align: center; padding: 2rem; color: #666;">
                        <div style="font-size: 2rem; margin-bottom: 0.5rem;">📋</div>
                        <div data-en="No upcoming exams" data-ar="لا توجد امتحانات قادمة">No upcoming exams</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($upcomingExams as $exam): ?>
                        <?php
                        $examDate = new DateTime($exam['Exam_Date']);
                        $formattedDate = $examDate->format('M d, Y');
                        $formattedTime = date('g:i A', strtotime($exam['Exam_Time']));
                        $endTime = date('g:i A', strtotime($exam['Exam_Time'] . ' +' . $exam['Duration'] . ' minutes'));
                        $subject = $exam['Course_Name'] ?? $exam['Subject'];
                        $daysUntil = (int)((new DateTime($exam['Exam_Date']))->diff(new DateTime())->days);
                        $badgeClass = $daysUntil <= 7 ? 'urgent' : ($daysUntil <= 30 ? 'upcoming' : '');
                        ?>
                        <div class="exam-date-item" data-subject="<?php echo strtolower($subject); ?>" data-date="<?php echo $exam['Exam_Date']; ?>">
                            <div class="exam-date-info">
                                <div class="exam-date-title"><?php echo htmlspecialchars($exam['Title']); ?></div>
                                <div class="exam-date-subject"><?php echo htmlspecialchars($subject); ?></div>
                                <div class="exam-date-details">
                                    <div class="exam-detail-item">
                                        <i class="fas fa-clock"></i>
                                        <span data-en="Time: <?php echo $formattedTime; ?> - <?php echo $endTime; ?>" data-ar="الوقت: <?php echo $formattedTime; ?> - <?php echo $endTime; ?>">
                                            Time: <?php echo $formattedTime; ?> - <?php echo $endTime; ?>
                                        </span>
                                    </div>
                                    <div class="exam-detail-item">
                                        <i class="fas fa-hourglass-half"></i>
                                        <span data-en="Duration: <?php echo $exam['Duration']; ?> minutes" data-ar="المدة: <?php echo $exam['Duration']; ?> دقيقة">
                                            Duration: <?php echo $exam['Duration']; ?> minutes
                                        </span>
                                    </div>
                                    <div class="exam-detail-item">
                                        <i class="fas fa-star"></i>
                                        <span data-en="Marks: <?php echo number_format($exam['Total_Marks'], 1); ?>" data-ar="الدرجة: <?php echo number_format($exam['Total_Marks'], 1); ?>">
                                            Marks: <?php echo number_format($exam['Total_Marks'], 1); ?>
                                        </span>
                                    </div>
                                </div>
                                <?php if ($exam['Description']): ?>
                                    <div style="font-size: 0.85rem; color: #666; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #FFE5E5;">
                                        <?php echo htmlspecialchars($exam['Description']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="exam-date-badge <?php echo $badgeClass; ?>"><?php echo $formattedDate; ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        function filterExams() {
            const subjectFilter = document.getElementById('filterSubject').value;
            const typeFilter = document.getElementById('filterType').value;
            const items = document.querySelectorAll('.exam-date-item');
            
            items.forEach(item => {
                const subject = item.dataset.subject || '';
                const type = item.dataset.type || '';
                
                const showSubject = subjectFilter === 'all' || subject.includes(subjectFilter);
                const showType = typeFilter === 'all' || type === typeFilter;
                
                if (showSubject && showType) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        function sortExams() {
            const sortBy = document.getElementById('sortExams').value;
            const container = document.getElementById('examDatesList');
            const items = Array.from(container.querySelectorAll('.exam-date-item'));
            
            items.sort((a, b) => {
                if (sortBy === 'date') {
                    return new Date(a.dataset.date) - new Date(b.dataset.date);
                } else if (sortBy === 'subject') {
                    return a.dataset.subject.localeCompare(b.dataset.subject);
                }
                return 0;
            });
            
            items.forEach(item => container.appendChild(item));
        }

        function updateExamBadges() {
            const today = new Date();
            const items = document.querySelectorAll('.exam-date-item');
            
            items.forEach(item => {
                const examDate = new Date(item.dataset.date);
                const daysUntil = Math.ceil((examDate - today) / (1000 * 60 * 60 * 24));
                const badge = item.querySelector('.exam-date-badge');
                
                badge.classList.remove('urgent', 'upcoming');
                
                if (daysUntil <= 7 && daysUntil >= 0) {
                    badge.classList.add('urgent');
                } else if (daysUntil <= 30 && daysUntil > 7) {
                    badge.classList.add('upcoming');
                }
            });
        }

        updateExamBadges();
    </script>
</body>
</html>

