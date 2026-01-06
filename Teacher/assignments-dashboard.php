<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-teacher.php';

requireUserType('teacher');

$currentTeacherId = getCurrentUserId();
$currentTeacher = getCurrentUserData($pdo);
$teacherName = $_SESSION['user_name'] ?? 'Teacher';

$classSubjectStats = [];

try {
    
    $stmt = $pdo->prepare("
        SELECT DISTINCT 
            c.Class_ID, 
            c.Name as Class_Name, 
            c.Grade_Level, 
            c.Section,
            co.Course_ID, 
            co.Course_Name,
            COUNT(DISTINCT a.Assignment_ID) as Assignment_Count,
            COUNT(DISTINCT s.Student_ID) as Students_Submitted,
            AVG(CASE WHEN s.Grade IS NOT NULL THEN s.Grade ELSE NULL END) as Average_Score
        FROM teacher_class_course tcc
        JOIN class c ON tcc.Class_ID = c.Class_ID
        JOIN course co ON tcc.Course_ID = co.Course_ID
        LEFT JOIN assignment a ON a.Class_ID = c.Class_ID 
            AND a.Course_ID = co.Course_ID 
            AND a.Teacher_ID = ? 
            AND a.Status != 'cancelled'
        LEFT JOIN submission s ON s.Assignment_ID = a.Assignment_ID
        WHERE tcc.Teacher_ID = ?
        GROUP BY c.Class_ID, co.Course_ID
        ORDER BY c.Grade_Level, c.Section, co.Course_Name
    ");
    $stmt->execute([$currentTeacherId, $currentTeacherId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($results as $row) {
        $classId = $row['Class_ID'];
        $courseId = $row['Course_ID'];
        
        if (!isset($classSubjectStats[$classId])) {
            $classSubjectStats[$classId] = [
                'Class_ID' => $classId,
                'Class_Name' => $row['Class_Name'],
                'Grade_Level' => $row['Grade_Level'],
                'Section' => $row['Section'],
                'subjects' => []
            ];
        }
        
        $classSubjectStats[$classId]['subjects'][$courseId] = [
            'Course_ID' => $courseId,
            'Course_Name' => $row['Course_Name'],
            'Assignment_Count' => intval($row['Assignment_Count']),
            'Students_Submitted' => intval($row['Students_Submitted']),
            'Average_Score' => $row['Average_Score'] !== null ? round(floatval($row['Average_Score']), 1) : null
        ];
    }
    
} catch (PDOException $e) {
    error_log("Error fetching class subject stats: " . $e->getMessage());
    $classSubjectStats = [];
}

$assignmentsByClassSubject = [];

try {
    $stmt = $pdo->prepare("
        SELECT a.*, c.Name as Class_Name, co.Course_Name,
               (SELECT COUNT(*) FROM submission s WHERE s.Assignment_ID = a.Assignment_ID) as Submission_Count
        FROM assignment a
        JOIN class c ON a.Class_ID = c.Class_ID
        JOIN course co ON a.Course_ID = co.Course_ID
        WHERE a.Teacher_ID = ? AND a.Status != 'cancelled'
        ORDER BY c.Grade_Level, c.Section, co.Course_Name, a.Upload_Date DESC
    ");
    $stmt->execute([$currentTeacherId]);
    $allAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allAssignments as $assignment) {
        $classId = $assignment['Class_ID'];
        $courseId = $assignment['Course_ID'];
        
        if (!isset($assignmentsByClassSubject[$classId])) {
            $assignmentsByClassSubject[$classId] = [];
        }
        if (!isset($assignmentsByClassSubject[$classId][$courseId])) {
            $assignmentsByClassSubject[$classId][$courseId] = [];
        }
        
        $assignmentsByClassSubject[$classId][$courseId][] = $assignment;
    }
    
} catch (PDOException $e) {
    error_log("Error fetching assignments: " . $e->getMessage());
    $assignmentsByClassSubject = [];
}

$overallStats = [
    'total_assignments' => 0,
    'total_submissions' => 0,
    'average_score' => null
];

try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT a.Assignment_ID) as total_assignments,
            COUNT(DISTINCT s.Submission_ID) as total_submissions,
            AVG(CASE WHEN s.Grade IS NOT NULL THEN s.Grade ELSE NULL END) as average_score
        FROM assignment a
        LEFT JOIN submission s ON s.Assignment_ID = a.Assignment_ID
        WHERE a.Teacher_ID = ? AND a.Status != 'cancelled'
    ");
    $stmt->execute([$currentTeacherId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($stats) {
        $overallStats['total_assignments'] = intval($stats['total_assignments']);
        $overallStats['total_submissions'] = intval($stats['total_submissions']);
        $overallStats['average_score'] = $stats['average_score'] !== null ? round(floatval($stats['average_score']), 1) : null;
    }
} catch (PDOException $e) {
    error_log("Error calculating overall stats: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignments Dashboard - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .stat-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        .stat-label {
            color: #666;
            font-weight: 600;
            font-size: 1rem;
        }
        .class-subject-section {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .class-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--bg-light);
        }
        .class-icon {
            font-size: 2rem;
        }
        .class-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
        }
        .subject-card {
            background: var(--bg-light);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary-color);
        }
        .subject-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .subject-name {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-dark);
        }
        .subject-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .stat-item {
            text-align: center;
            padding: 0.8rem;
            background: white;
            border-radius: 10px;
        }
        .stat-item-label {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 0.3rem;
        }
        .stat-item-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        .assignments-list {
            margin-top: 1rem;
        }
        .assignment-item {
            background: white;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .assignment-item-info {
            flex: 1;
            min-width: 200px;
        }
        .assignment-item-title {
            font-weight: 700;
            margin-bottom: 0.3rem;
        }
        .assignment-item-meta {
            font-size: 0.85rem;
            color: #666;
        }
        .assignment-item-badge {
            padding: 0.3rem 0.8rem;
            background: var(--bg-blue);
            border-radius: 15px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .filter-section {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .filter-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .subject-stats {
                grid-template-columns: 1fr;
            }
            .assignment-item {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>
    
    <div class="main-content">
    
    <div id="notificationContainer"></div>

    <div class="dashboard-container">
        
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">📈</span>
                <span data-en="Assignments Dashboard" data-ar="لوحة تحكم الواجبات">Assignments Dashboard</span>
            </h1>
            <p class="page-subtitle" data-en="View statistics and analytics for your assignments" data-ar="عرض الإحصائيات والتحليلات للواجبات الخاصة بك">View statistics and analytics for your assignments</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">📝</div>
                <div class="stat-value"><?php echo $overallStats['total_assignments']; ?></div>
                <div class="stat-label" data-en="Total Assignments" data-ar="إجمالي الواجبات">Total Assignments</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-value"><?php echo $overallStats['total_submissions']; ?></div>
                <div class="stat-label" data-en="Total Submissions" data-ar="إجمالي التقديمات">Total Submissions</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⭐</div>
                <div class="stat-value"><?php echo $overallStats['average_score'] !== null ? $overallStats['average_score'] : 'N/A'; ?></div>
                <div class="stat-label" data-en="Average Score" data-ar="متوسط الدرجات">Average Score</div>
            </div>
        </div>

        <div class="filter-section">
            <h3 style="margin-bottom: 1rem;" data-en="Filter by Class" data-ar="تصفية حسب الفصل">Filter by Class</h3>
            <div class="filter-group">
                <div class="form-group" style="margin-bottom: 0;">
                    <label data-en="Select Class" data-ar="اختر الفصل">Select Class</label>
                    <select id="classFilter" onchange="filterAssignments()">
                        <option value="all" data-en="All Classes" data-ar="جميع الفصول">All Classes</option>
                        <?php foreach ($classSubjectStats as $classId => $classData): ?>
                            <option value="<?php echo $classId; ?>">
                                <?php echo htmlspecialchars($classData['Class_Name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div id="classSubjectContainer">
            <?php if (empty($classSubjectStats)): ?>
                <div class="card">
                    <div style="text-align: center; padding: 3rem; color: #999;">
                        <i class="fas fa-chart-line" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                        <div data-en="No classes or subjects assigned yet" data-ar="لا توجد فصول أو مواد معينة بعد">No classes or subjects assigned yet</div>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($classSubjectStats as $classId => $classData): ?>
                    <div class="class-subject-section" data-class-id="<?php echo $classId; ?>">
                        <div class="class-header">
                            <div class="class-icon">👥</div>
                            <div class="class-title"><?php echo htmlspecialchars($classData['Class_Name']); ?></div>
                        </div>
                        
                        <?php if (empty($classData['subjects'])): ?>
                            <div style="text-align: center; padding: 2rem; color: #999;">
                                <div data-en="No subjects assigned for this class" data-ar="لا توجد مواد معينة لهذا الفصل">No subjects assigned for this class</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($classData['subjects'] as $courseId => $subjectData): ?>
                                <div class="subject-card" data-course-id="<?php echo $courseId; ?>">
                                    <div class="subject-header">
                                        <div class="subject-name">
                                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($subjectData['Course_Name']); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="subject-stats">
                                        <div class="stat-item">
                                            <div class="stat-item-label" data-en="Assignments" data-ar="الواجبات">Assignments</div>
                                            <div class="stat-item-value"><?php echo $subjectData['Assignment_Count']; ?></div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-item-label" data-en="Students Submitted" data-ar="الطلاب المقدمين">Students Submitted</div>
                                            <div class="stat-item-value"><?php echo $subjectData['Students_Submitted']; ?></div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-item-label" data-en="Average Score" data-ar="متوسط الدرجات">Average Score</div>
                                            <div class="stat-item-value"><?php echo $subjectData['Average_Score'] !== null ? $subjectData['Average_Score'] : 'N/A'; ?></div>
                                        </div>
                                    </div>
                                    
                                    <?php if (isset($assignmentsByClassSubject[$classId][$courseId]) && !empty($assignmentsByClassSubject[$classId][$courseId])): ?>
                                        <div class="assignments-list">
                                            <h4 style="margin-bottom: 1rem; font-size: 1rem; color: #666;" data-en="Assignments List" data-ar="قائمة الواجبات">Assignments List</h4>
                                            <?php foreach ($assignmentsByClassSubject[$classId][$courseId] as $assignment): ?>
                                                <div class="assignment-item">
                                                    <div class="assignment-item-info">
                                                        <div class="assignment-item-title"><?php echo htmlspecialchars($assignment['Title']); ?></div>
                                                        <div class="assignment-item-meta">
                                                            <i class="fas fa-calendar-alt"></i> 
                                                            <span data-en="Due:" data-ar="تاريخ الاستحقاق:">Due:</span> 
                                                            <?php echo date('M d, Y H:i', strtotime($assignment['Due_Date'])); ?>
                                                        </div>
                                                    </div>
                                                    <div class="assignment-item-badge">
                                                        <i class="fas fa-paper-plane"></i> 
                                                        <?php echo $assignment['Submission_Count']; ?> 
                                                        <span data-en="submission(s)" data-ar="تقديم(ات)">submission(s)</span>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        function filterAssignments() {
            const selectedClass = document.getElementById('classFilter').value;
            const sections = document.querySelectorAll('.class-subject-section');
            
            sections.forEach(section => {
                const classId = section.getAttribute('data-class-id');
                if (selectedClass === 'all' || selectedClass === classId) {
                    section.style.display = 'block';
                } else {
                    section.style.display = 'none';
                }
            });
        }
        
        function openProfileSettings() {
            window.location.href = 'notifications-and-settings.php#profile';
        }

    </script>
</body>
</html>

