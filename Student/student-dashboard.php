<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-student.php';

requireUserType('student');

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

$currentStudentId = getCurrentUserId();
$currentStudent = getCurrentUserData($pdo);
$currentStudentClassId = null;
$studentName = $_SESSION['user_name'] ?? 'Student';
$studentEmail = $_SESSION['user_email'] ?? '';

$studentClass = null;
$academicStatus = [
    'currentGrade' => 'N/A',
    'classSection' => 'N/A',
    'className' => 'N/A',
    'overallAverage' => 0,
    'academicStatus' => 'Not Available'
];

if ($currentStudentId && $currentStudent) {
    $currentStudentClassId = $currentStudent['Class_ID'] ?? null;
    $studentName = $currentStudent['NameEn'] ?? $currentStudent['Name'] ?? $studentName;
    $studentEmail = $currentStudent['Email'] ?? $studentEmail;

    if ($currentStudentClassId) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM class WHERE Class_ID = ?");
            $stmt->execute([$currentStudentClassId]);
            $studentClass = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($studentClass) {
                $academicStatus['currentGrade'] = $studentClass['Grade_Level'] ?? 'N/A';
                $academicStatus['classSection'] = $studentClass['Section'] ?? 'N/A';
                $academicStatus['className'] = $studentClass['Name'] ?? ($studentClass['Grade_Level'] && $studentClass['Section'] ? 'Grade ' . $studentClass['Grade_Level'] . ' - Section ' . strtoupper($studentClass['Section']) : 'N/A');
            }
        } catch (PDOException $e) {
            error_log("Error fetching student class: " . $e->getMessage());
        }
    }

    try {
        $stmt = $pdo->prepare("
            SELECT AVG(Value) as average, COUNT(*) as grade_count
            FROM grade
            WHERE Student_ID = ?
        ");
        $stmt->execute([$currentStudentId]);
        $gradeData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($gradeData && $gradeData['grade_count'] > 0 && $gradeData['average'] !== null) {
            $academicStatus['overallAverage'] = round(floatval($gradeData['average']), 2);

            if ($academicStatus['overallAverage'] >= 90) {
                $academicStatus['academicStatus'] = 'Excellent';
            } elseif ($academicStatus['overallAverage'] >= 80) {
                $academicStatus['academicStatus'] = 'Very Good';
            } elseif ($academicStatus['overallAverage'] >= 70) {
                $academicStatus['academicStatus'] = 'Good';
            } elseif ($academicStatus['overallAverage'] >= 60) {
                $academicStatus['academicStatus'] = 'Satisfactory';
            } else {
                $academicStatus['academicStatus'] = 'Needs Improvement';
            }
        } else {
            $academicStatus['overallAverage'] = 0;
            $academicStatus['academicStatus'] = 'No Grades Yet';
        }
    } catch (PDOException $e) {
        error_log("Error calculating overall average: " . $e->getMessage());
        $academicStatus['overallAverage'] = 0;
        $academicStatus['academicStatus'] = 'Not Available';
    }
}

$upcomingExams = [];
if ($currentStudentClassId) {
    try {
        $today = date('Y-m-d');
        error_log("Student Dashboard: Student ID=$currentStudentId, Class_ID=$currentStudentClassId, Fetching exams with Date >= $today");

        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM exam_class WHERE Class_ID = ?");
        $stmt->execute([$currentStudentClassId]);
        $totalExamsForClass = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        error_log("Student Dashboard: Total exams linked to Class_ID $currentStudentClassId: $totalExamsForClass");

        $stmt = $pdo->prepare("
            SELECT e.*, c.Course_Name
            FROM exam e
            INNER JOIN exam_class ec ON e.Exam_ID = ec.Exam_ID
            LEFT JOIN course c ON e.Course_ID = c.Course_ID
            WHERE ec.Class_ID = ? AND e.Exam_Date >= ?
            ORDER BY e.Exam_Date ASC, e.Exam_Time ASC
            LIMIT 5
        ");
        $stmt->execute([$currentStudentClassId, $today]);
        $upcomingExams = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Student Dashboard: Found " . count($upcomingExams) . " upcoming exams for student");
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
    error_log("Student Dashboard: No Class_ID found for student ID: " . $currentStudentId);
    
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

$scheduleData = [];
if ($currentStudentClassId) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.Schedule_ID, s.Day_Of_Week, s.Start_Time, s.End_Time, s.Subject, s.Room,
                   s.Course_ID, s.Teacher_ID, c.Course_Name, t.NameEn as Teacher_Name
            FROM schedule s
            LEFT JOIN course c ON s.Course_ID = c.Course_ID
            LEFT JOIN teacher t ON s.Teacher_ID = t.Teacher_ID
            WHERE s.Class_ID = ? AND s.Type = 'Class'
            ORDER BY 
                FIELD(s.Day_Of_Week, 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'),
                s.Start_Time
        ");
        $stmt->execute([$currentStudentClassId]);
        $scheduleData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching schedule for student: " . $e->getMessage());
    }
}

$scheduleByDay = [
    'Sunday' => [],
    'Monday' => [],
    'Tuesday' => [],
    'Wednesday' => [],
    'Thursday' => [],
    'Friday' => [],
    'Saturday' => []
];

foreach ($scheduleData as $period) {
    $day = $period['Day_Of_Week'];
    if (isset($scheduleByDay[$day])) {
        $scheduleByDay[$day][] = $period;
    }
}

$notifications = [];
try {
    if ($currentStudentId && $currentStudentClassId) {

        $query = "
            SELECT Notif_ID, Title, Content, Date_Sent, Sender_Type, Target_Role, Target_Class_ID, Target_Student_ID
            FROM notification
            WHERE (
                Target_Role = 'All'
                OR (Target_Role = 'Student' AND Target_Student_ID = ?)
                OR (Target_Role = 'Student' AND Target_Class_ID = ? AND Target_Student_ID IS NULL)
            )
            ORDER BY Date_Sent DESC
            LIMIT 20
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$currentStudentId, $currentStudentClassId]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching notifications for students: " . $e->getMessage());
    $notifications = [];
}

$upcomingEvents = [];
$allUpcomingEvents = [];
$totalEventsCount = 0;
try {
    $today = date('Y-m-d');
    
    $stmt = $pdo->prepare("
        SELECT Event_ID, Title, Description, Date, Time, Location, Type, Target_Audience
        FROM event
        WHERE Date >= ?
        AND (LOWER(TRIM(Target_Audience)) = 'all' OR LOWER(TRIM(Target_Audience)) = 'students')
        ORDER BY Date ASC, Time ASC
        LIMIT 4
    ");
    $stmt->execute([$today]);
    $upcomingEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT Event_ID, Title, Description, Date, Time, Location, Type, Target_Audience
        FROM event
        WHERE Date >= ?
        AND (LOWER(TRIM(Target_Audience)) = 'all' OR LOWER(TRIM(Target_Audience)) = 'students')
        ORDER BY Date ASC, Time ASC
    ");
    $stmt->execute([$today]);
    $allUpcomingEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalEventsCount = count($allUpcomingEvents);
} catch (PDOException $e) {
    error_log("Error fetching upcoming events for students: " . $e->getMessage());
    $upcomingEvents = [];
    $allUpcomingEvents = [];
    $totalEventsCount = 0;
}

$dashboardAssignments = [];
$dashboardSubmissions = [];

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
                ORDER BY a.Upload_Date DESC
                LIMIT 3
            ");
            $params = array_merge([$currentStudentClassId], $studentCourseIds);
            $stmt->execute($params);
            $dashboardAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($dashboardAssignments)) {
                $assignmentIds = array_column($dashboardAssignments, 'Assignment_ID');
                $assignmentPlaceholders = implode(',', array_fill(0, count($assignmentIds), '?'));
                
                $stmt = $pdo->prepare("
                    SELECT * FROM submission
                    WHERE Student_ID = ? AND Assignment_ID IN ($assignmentPlaceholders)
                ");
                $params = array_merge([$currentStudentId], $assignmentIds);
                $stmt->execute($params);
                $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($submissions as $sub) {
                    $dashboardSubmissions[$sub['Assignment_ID']] = $sub;
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching dashboard assignments: " . $e->getMessage());
        $dashboardAssignments = [];
    }
}

$dashboardCourses = [];
$studentGradesDetailed = [];
if ($currentStudentClassId) {
    try {
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.Course_ID, c.Course_Name
            FROM course c
            INNER JOIN course_class cc ON c.Course_ID = cc.Course_ID
            WHERE cc.Class_ID = ?
            ORDER BY c.Course_Name
        ");
        $stmt->execute([$currentStudentClassId]);
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($courses as $course) {
            
            $stmt = $pdo->prepare("
                SELECT Type, Value
                FROM grade
                WHERE Student_ID = ? AND Course_ID = ?
                ORDER BY Date_Recorded DESC
            ");
            $stmt->execute([$currentStudentId, $course['Course_ID']]);
            $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $gradeByType = [];
            foreach ($grades as $grade) {
                if (!isset($gradeByType[$grade['Type']])) {
                    $gradeByType[$grade['Type']] = floatval($grade['Value']);
                }
            }

            $midterm = min(30, floatval($gradeByType['Midterm'] ?? 0));
            $final = min(40, floatval($gradeByType['Final'] ?? 0));
            $assignment = min(10, floatval($gradeByType['Assignment'] ?? 0));
            $quiz = min(10, floatval($gradeByType['Quiz'] ?? 0));
            $project = min(10, floatval($gradeByType['Project'] ?? 0));

            $total = round($midterm + $final + $assignment + $quiz + $project, 1);

            $letterGrade = 'N/A';
            if ($total >= 97) {
                $letterGrade = 'A+';
            } elseif ($total >= 93) {
                $letterGrade = 'A';
            } elseif ($total >= 90) {
                $letterGrade = 'A-';
            } elseif ($total >= 87) {
                $letterGrade = 'B+';
            } elseif ($total >= 83) {
                $letterGrade = 'B';
            } elseif ($total >= 80) {
                $letterGrade = 'B-';
            } elseif ($total >= 77) {
                $letterGrade = 'C+';
            } elseif ($total >= 73) {
                $letterGrade = 'C';
            } elseif ($total >= 70) {
                $letterGrade = 'C-';
            } elseif ($total > 0) {
                $letterGrade = 'F';
            }
            
            $studentGradesDetailed[] = [
                'Course_ID' => $course['Course_ID'],
                'Course_Name' => $course['Course_Name'],
                'Midterm' => $midterm,
                'Final' => $final,
                'Assignment' => $assignment,
                'Quiz' => $quiz,
                'Project' => $project,
                'Total' => $total,
                'LetterGrade' => $letterGrade
            ];

            if (count($dashboardCourses) < 5) {
                $dashboardCourses[] = [
                    'Course_Name' => $course['Course_Name'],
                    'Total' => $total,
                    'LetterGrade' => $letterGrade
                ];
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching courses for dashboard: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>
    
    <div class="main-content">

    <div class="dashboard-container">
        
        <div class="welcome-section">
            <h1 data-en="Welcome to Your Student Dashboard! 👋" data-ar="مرحباً بك في لوحة تحكم الطالب! 👋">Welcome to Your Student Dashboard! 👋</h1>
            <p data-en="View your assignments, track your grades, and stay updated with your academic progress." data-ar="عرض واجباتك، تتبع درجاتك، وابق محدثاً بتقدمك الأكاديمي.">View your assignments, track your grades, and stay updated with your academic progress.</p>
        </div>

        <div class="quick-stats">
            <div class="stat-card">
                <div class="stat-icon">📊</div>
                <div class="stat-value"><?php echo $academicStatus['overallAverage'] > 0 ? number_format($academicStatus['overallAverage'], 1) . '%' : 'N/A'; ?></div>
                <div class="stat-label" data-en="Overall Average" data-ar="المعدل العام">Overall Average</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📝</div>
                <div class="stat-value"><?php 
                    $pendingCount = 0;
                    foreach ($dashboardAssignments as $a) {
                        $sub = $dashboardSubmissions[$a['Assignment_ID']] ?? null;
                        if (!$sub) $pendingCount++;
                    }
                    echo $pendingCount;
                ?></div>
                <div class="stat-label" data-en="Pending Assignments" data-ar="الواجبات المعلقة">Pending Assignments</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">✅</div>
                <div class="stat-value"><?php 
                    $completedCount = 0;
                    foreach ($dashboardAssignments as $a) {
                        $sub = $dashboardSubmissions[$a['Assignment_ID']] ?? null;
                        if ($sub && $sub['Status'] === 'graded') $completedCount++;
                    }
                    echo $completedCount;
                ?></div>
                <div class="stat-label" data-en="Completed Assignments" data-ar="الواجبات المكتملة">Completed Assignments</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🔔</div>
                <div class="stat-value"><?php echo count($notifications); ?></div>
                <div class="stat-label" data-en="New Notifications" data-ar="إشعارات جديدة">New Notifications</div>
            </div>
        </div>

        <div class="content-grid">
            
            <div>
                
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📝</span>
                            <span data-en="My Assignments" data-ar="واجباتي">My Assignments</span>
                        </h2>
                        <a href="my-assignments.php" class="btn btn-primary" data-en="View All" data-ar="عرض الكل">View All</a>
                    </div>
                    <div class="assignment-list" id="assignmentList">
                        <?php if (empty($dashboardAssignments)): ?>
                            <div style="text-align: center; padding: 2rem; color: #999;">
                                <div style="font-size: 2rem; margin-bottom: 0.5rem;">📝</div>
                                <div data-en="No assignments available" data-ar="لا توجد واجبات متاحة">No assignments available</div>
                                <?php if (!$currentStudentClassId): ?>
                                    <div style="font-size: 0.85rem; margin-top: 0.5rem; color: #FF6B9D;" data-en="Note: You are not assigned to a class yet." data-ar="ملاحظة: لم يتم تعيينك إلى فصل بعد.">
                                        Note: You are not assigned to a class yet.
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php foreach ($dashboardAssignments as $assignment): 
                                $dueDate = new DateTime($assignment['Due_Date']);
                                $now = new DateTime();
                                $isOverdue = $dueDate < $now;
                                $submission = $dashboardSubmissions[$assignment['Assignment_ID']] ?? null;
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
                                     onclick="window.location.href='my-assignments.php';">
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
                                    <?php if (!$isSubmitted || $isOverdue): ?>
                                        <button class="btn btn-primary btn-small" 
                                                onclick="event.stopPropagation(); window.location.href='my-assignments.php';" 
                                                data-en="<?php echo $isOverdue ? 'Submit Now' : 'Submit Assignment'; ?>" 
                                                data-ar="<?php echo $isOverdue ? 'تقديم الآن' : 'تقديم الواجب'; ?>">
                                            <?php echo $isOverdue ? 'Submit Now' : 'Submit Assignment'; ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card" style="cursor: pointer;" onclick="window.location.href='academic-performance.php';">
                    <div class="card-header">
                        <div style="display: flex; align-items: center; gap: 1rem; flex: 1;">
                            <h2 class="card-title">
                                <span class="card-icon">📈</span>
                                <span data-en="Academic Performance" data-ar="الأداء الأكاديمي">Academic Performance</span>
                            </h2>
                            <button onclick="event.stopPropagation(); toggleInstructions();" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #6BCB77;" title="Instructions">
                                <i class="fas fa-info-circle"></i>
                            </button>
                        </div>
                        <a href="academic-performance.php" class="btn btn-primary" data-en="View Details" data-ar="عرض التفاصيل">View Details</a>
                    </div>
                    <div id="instructionsBox" style="display: none; background: #E5F3FF; padding: 1rem; border-radius: 10px; margin-bottom: 1rem; border-left: 4px solid #6BCB77;">
                        <h4 style="margin: 0 0 0.5rem 0; color: #2c3e50;" data-en="Grade Information" data-ar="معلومات الدرجات">Grade Information</h4>
                        <ul style="margin: 0; padding-left: 1.5rem; color: #555; font-size: 0.9rem;">
                            <li data-en="Total is calculated as: Midterm (max 30) + Final (max 40) + Assignment (max 10) + Quiz (max 10) + Project (max 10)" data-ar="يتم حساب المجموع كالتالي: نصفي (حد أقصى 30) + نهائي (حد أقصى 40) + واجب (حد أقصى 10) + اختبار (حد أقصى 10) + مشروع (حد أقصى 10)">Total is calculated as: Midterm (max 30) + Final (max 40) + Assignment (max 10) + Quiz (max 10) + Project (max 10)</li>
                            <li data-en="Maximum total is 100 points" data-ar="الحد الأقصى للمجموع هو 100 نقطة">Maximum total is 100 points</li>
                            <li data-en="Grades update automatically when teachers add or modify them" data-ar="تتم تحديث الدرجات تلقائياً عند إضافة أو تعديل المعلمين لها">Grades update automatically when teachers add or modify them</li>
                        </ul>
                    </div>
                    <div style="overflow-x: auto;">
                        <?php if (empty($studentGradesDetailed)): ?>
                            <div style="text-align: center; padding: 2rem; color: #999;">
                                <div style="font-size: 2rem; margin-bottom: 0.5rem;">📚</div>
                                <div data-en="No courses assigned yet" data-ar="لم يتم تعيين مقررات بعد">No courses assigned yet</div>
                            </div>
                        <?php else: ?>
                            <table class="grades-table" style="min-width: 700px;">
                                <thead>
                                    <tr>
                                        <th data-en="Subject" data-ar="المادة">Subject</th>
                                        <th data-en="Midterm (30)" data-ar="نصفي (30)">Midterm (30)</th>
                                        <th data-en="Final (40)" data-ar="نهائي (40)">Final (40)</th>
                                        <th data-en="Assignment (10)" data-ar="واجب (10)">Assignment (10)</th>
                                        <th data-en="Quiz (10)" data-ar="اختبار (10)">Quiz (10)</th>
                                        <th data-en="Project (10)" data-ar="مشروع (10)">Project (10)</th>
                                        <th data-en="Total" data-ar="المجموع">Total</th>
                                        <th data-en="Grade" data-ar="الدرجة">Grade</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($studentGradesDetailed, 0, 5) as $course): 
                                        $total = $course['Total'];

                                        $gradeClass = 'grade-cell';
                                        if ($total >= 90) {
                                            $gradeClass .= ' grade-excellent';
                                        } elseif ($total >= 80) {
                                            $gradeClass .= ' grade-good';
                                        } elseif ($total >= 70) {
                                            $gradeClass .= ' grade-average';
                                        } else {
                                            $gradeClass .= ' grade-poor';
                                        }
                                    ?>
                                        <tr>
                                            <td style="font-weight: 700; color: #2c3e50;"><?php echo htmlspecialchars($course['Course_Name']); ?></td>
                                            <td class="grade-cell" style="background: #FFF9E5;"><?php echo number_format($course['Midterm'], 1); ?></td>
                                            <td class="grade-cell" style="background: #FFE5E5;"><?php echo number_format($course['Final'], 1); ?></td>
                                            <td class="grade-cell" style="background: #E5F3FF;"><?php echo number_format($course['Assignment'], 1); ?></td>
                                            <td class="grade-cell" style="background: #E5FFE5;"><?php echo number_format($course['Quiz'], 1); ?></td>
                                            <td class="grade-cell" style="background: #F5E5FF;"><?php echo number_format($course['Project'], 1); ?></td>
                                            <td class="grade-cell total-grade <?php echo $gradeClass; ?>" style="font-weight: 700; font-size: 1.1rem;"><?php echo number_format($total, 1); ?></td>
                                            <td class="grade-cell <?php echo $gradeClass; ?>" style="font-weight: 700; color: <?php echo $total >= 90 ? '#6BCB77' : ($total >= 80 ? '#FFD93D' : '#FF6B9D'); ?>;"><?php echo $course['LetterGrade']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (count($studentGradesDetailed) > 5): ?>
                                <div style="text-align: center; padding: 1rem; margin-top: 1rem;">
                                    <a href="academic-performance.php" class="btn btn-secondary" data-en="View All Courses" data-ar="عرض جميع المقررات">View All Courses (<?php echo count($studentGradesDetailed); ?>)</a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card" style="cursor: pointer;" onclick="window.location.href='class-schedule.php';">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📅</span>
                            <span data-en="Class Schedule" data-ar="جدول الحصص">Class Schedule</span>
                        </h2>
                        <a href="class-schedule.php" class="btn btn-primary" data-en="View Full Schedule" data-ar="عرض الجدول الكامل">View Full Schedule</a>
                    </div>
                    <div class="schedule-grid">
                        <?php
                        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday'];
                        $daysAr = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس'];
                        foreach ($days as $dayIndex => $day):
                        ?>
                            <div class="schedule-day">
                                <div class="schedule-day-name" data-en="<?php echo $day; ?>" data-ar="<?php echo $daysAr[$dayIndex]; ?>"><?php echo $day; ?></div>
                                <?php if (isset($scheduleByDay[$day]) && !empty($scheduleByDay[$day])): ?>
                                    <?php foreach ($scheduleByDay[$day] as $period): ?>
                                        <div class="schedule-period">
                                            <div class="schedule-time">
                                                <?php echo date('g:i A', strtotime($period['Start_Time'])); ?> - 
                                                <?php echo date('g:i A', strtotime($period['End_Time'])); ?>
                                            </div>
                                            <div style="font-weight: 700;"><?php echo htmlspecialchars($period['Subject']); ?></div>
                                            <?php if ($period['Room'] || $period['Teacher_Name']): ?>
                                                <div style="font-size: 0.85rem; color: #666; margin-top: 0.3rem;">
                                                    <?php if ($period['Room']): ?>
                                                        <?php echo htmlspecialchars($period['Room']); ?>
                                                        <?php if ($period['Teacher_Name']): ?> - <?php endif; ?>
                                                    <?php endif; ?>
                                                    <?php if ($period['Teacher_Name']): ?>
                                                        <?php echo htmlspecialchars($period['Teacher_Name']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="text-align: center; padding: 1rem; color: #999; font-size: 0.9rem;" data-en="No classes scheduled" data-ar="لا توجد حصص مجدولة">No classes scheduled</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card" style="cursor: pointer;" onclick="window.location.href='upcoming-exam-dates.php';">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📋</span>
                            <span data-en="Upcoming Exam Dates" data-ar="مواعيد الامتحانات القادمة">Upcoming Exam Dates</span>
                        </h2>
                        <a href="upcoming-exam-dates.php" class="btn btn-primary" data-en="View All Exams" data-ar="عرض جميع الامتحانات">View All Exams</a>
                    </div>
                    <div class="exam-dates-list">
                        <?php if (empty($upcomingExams)): ?>
                            <div style="text-align: center; padding: 2rem; color: #666;">
                                <div style="font-size: 2rem; margin-bottom: 0.5rem;">📋</div>
                                <div data-en="No upcoming exams" data-ar="لا توجد امتحانات قادمة">No upcoming exams</div>
                                <?php if (!$currentStudentClassId): ?>
                                    <div style="font-size: 0.85rem; color: #FF6B9D; margin-top: 0.5rem;" data-en="Note: You are not assigned to a class yet." data-ar="ملاحظة: لم يتم تعيينك إلى فصل بعد.">
                                        Note: You are not assigned to a class yet.
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php foreach ($upcomingExams as $exam): ?>
                                <?php
                                $examDate = new DateTime($exam['Exam_Date']);
                                $formattedDate = $examDate->format('M d, Y');
                                $formattedTime = date('g:i A', strtotime($exam['Exam_Time']));
                                $subject = $exam['Course_Name'] ?? $exam['Subject'];
                                ?>
                                <div class="exam-date-item">
                                    <div class="exam-date-info">
                                        <div class="exam-date-title"><?php echo htmlspecialchars($exam['Title']); ?></div>
                                        <div class="exam-date-subject"><?php echo htmlspecialchars($subject); ?></div>
                                        <?php if ($exam['Description']): ?>
                                            <div style="font-size: 0.85rem; color: #999; margin-top: 0.3rem;">
                                                <?php echo htmlspecialchars(substr($exam['Description'], 0, 80)) . (strlen($exam['Description']) > 80 ? '...' : ''); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="exam-date-badge"><?php echo $formattedDate; ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📋</span>
                            <span data-en="Academic Status" data-ar="الحالة الأكاديمية">Academic Status</span>
                        </h2>
                    </div>
                    <div style="padding: 1.5rem; background: #FFF9F5; border-radius: 15px; margin-bottom: 1rem;">
                        <div style="font-weight: 700; margin-bottom: 0.5rem;" data-en="Current Grade" data-ar="الصف الحالي">Current Grade</div>
                        <div style="font-size: 1.5rem; color: var(--primary-color); font-weight: 800;">
                            <?php 
                            if ($academicStatus['currentGrade'] !== 'N/A') {
                                echo 'Grade ' . htmlspecialchars($academicStatus['currentGrade']);
                            } else {
                                echo '<span style="color: #999; font-size: 1rem;" data-en="Not Assigned" data-ar="غير معين">Not Assigned</span>';
                            }
                            ?>
                        </div>
                    </div>
                    <div style="padding: 1.5rem; background: #FFF9F5; border-radius: 15px; margin-bottom: 1rem;">
                        <div style="font-weight: 700; margin-bottom: 0.5rem;" data-en="Class" data-ar="الفصل">Class</div>
                        <div style="font-size: 1.5rem; color: var(--primary-color); font-weight: 800;">
                            <?php 
                            if ($academicStatus['className'] !== 'N/A') {
                                echo htmlspecialchars($academicStatus['className']);
                            } else {
                                echo '<span style="color: #999; font-size: 1rem;" data-en="Not Assigned" data-ar="غير معين">Not Assigned</span>';
                            }
                            ?>
                        </div>
                    </div>
                    <div style="padding: 1.5rem; background: #FFF9F5; border-radius: 15px; margin-bottom: 1rem;">
                        <div style="font-weight: 700; margin-bottom: 0.5rem;" data-en="Overall Average" data-ar="المعدل العام">Overall Average</div>
                        <div style="font-size: 1.5rem; color: var(--primary-color); font-weight: 800;">
                            <?php 
                            if ($academicStatus['overallAverage'] > 0) {
                                $avgColor = '#6BCB77'; 
                                if ($academicStatus['overallAverage'] < 60) {
                                    $avgColor = '#FF6B9D'; 
                                } elseif ($academicStatus['overallAverage'] < 70) {
                                    $avgColor = '#FFD93D'; 
                                }
                                echo '<span style="color: ' . $avgColor . ';">' . number_format($academicStatus['overallAverage'], 2) . '%</span>';
                            } else {
                                echo '<span style="color: #999; font-size: 1rem;" data-en="No Grades Yet" data-ar="لا توجد درجات بعد">No Grades Yet</span>';
                            }
                            ?>
                        </div>
                    </div>
                    <div style="padding: 1.5rem; background: #FFF9F5; border-radius: 15px;">
                        <div style="font-weight: 700; margin-bottom: 0.5rem;" data-en="Academic Status" data-ar="الحالة الأكاديمية">Academic Status</div>
                        <div style="font-size: 1.2rem; font-weight: 800;">
                            <?php
                            $statusColor = '#666';
                            $statusBg = '#FFF9F5';
                            switch($academicStatus['academicStatus']) {
                                case 'Excellent':
                                    $statusColor = '#6BCB77';
                                    $statusBg = '#E5F3FF';
                                    break;
                                case 'Very Good':
                                    $statusColor = '#4A90E2';
                                    $statusBg = '#E5F3FF';
                                    break;
                                case 'Good':
                                    $statusColor = '#FFD93D';
                                    $statusBg = '#FFF9E5';
                                    break;
                                case 'Satisfactory':
                                    $statusColor = '#FF8B94';
                                    $statusBg = '#FFE5E5';
                                    break;
                                case 'Needs Improvement':
                                    $statusColor = '#FF6B9D';
                                    $statusBg = '#FFE5E5';
                                    break;
                                default:
                                    $statusColor = '#999';
                                    $statusBg = '#FFF9F5';
                            }
                            ?>
                            <span style="display: inline-block; padding: 0.5rem 1rem; border-radius: 20px; background: <?php echo $statusBg; ?>; color: <?php echo $statusColor; ?>; font-weight: 700;">
                                <?php 
                                $statusLabels = [
                                    'Excellent' => ['en' => 'Excellent', 'ar' => 'ممتاز'],
                                    'Very Good' => ['en' => 'Very Good', 'ar' => 'جيد جداً'],
                                    'Good' => ['en' => 'Good', 'ar' => 'جيد'],
                                    'Satisfactory' => ['en' => 'Satisfactory', 'ar' => 'مقبول'],
                                    'Needs Improvement' => ['en' => 'Needs Improvement', 'ar' => 'يحتاج تحسين'],
                                    'No Grades Yet' => ['en' => 'No Grades Yet', 'ar' => 'لا توجد درجات بعد'],
                                    'Not Available' => ['en' => 'Not Available', 'ar' => 'غير متاح']
                                ];
                                $statusLabel = $statusLabels[$academicStatus['academicStatus']] ?? ['en' => $academicStatus['academicStatus'], 'ar' => $academicStatus['academicStatus']];
                                echo '<span data-en="' . htmlspecialchars($statusLabel['en']) . '" data-ar="' . htmlspecialchars($statusLabel['ar']) . '">' . htmlspecialchars($statusLabel['en']) . '</span>';
                                ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📅</span>
                            <span data-en="Upcoming Events" data-ar="الأحداث القادمة">Upcoming Events</span>
                        </h2>
                    </div>
                    <div class="upcoming-events-list">
                        <?php if (empty($upcomingEvents)): ?>
                            <div style="text-align: center; padding: 2rem; color: #666;">
                                <div style="font-size: 2rem; margin-bottom: 0.5rem;">📅</div>
                                <div data-en="No upcoming events" data-ar="لا توجد أحداث قادمة">No upcoming events</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($upcomingEvents as $event): ?>
                                <?php
                                $eventDate = new DateTime($event['Date']);
                                $formattedDate = $eventDate->format('M d, Y');
                                $timeStr = $event['Time'] ? date('g:i A', strtotime($event['Time'])) : '';
                                $locationStr = $event['Location'] ? htmlspecialchars($event['Location']) : 'Location TBA';
                                $description = $event['Description'] ? htmlspecialchars(substr($event['Description'], 0, 100)) . (strlen($event['Description']) > 100 ? '...' : '') : 'No description available';
                                
                                $typeLabels = [
                                    'academic' => ['en' => 'Academic', 'ar' => 'أكاديمي'],
                                    'sports' => ['en' => 'Sports', 'ar' => 'رياضي'],
                                    'cultural' => ['en' => 'Cultural', 'ar' => 'ثقافي'],
                                    'meeting' => ['en' => 'Meeting', 'ar' => 'اجتماع'],
                                    'other' => ['en' => 'Other', 'ar' => 'أخرى']
                                ];
                                $typeLabel = $typeLabels[$event['Type']] ?? ['en' => $event['Type'], 'ar' => $event['Type']];
                                ?>
                                <div class="event-item" style="padding: 1rem; border-bottom: 2px solid #FFE5E5; transition: all 0.3s; cursor: pointer;" onmouseover="this.style.background='#FFF9F5'; this.style.transform='translateX(5px)';" onmouseout="this.style.background='transparent'; this.style.transform='translateX(0)';">
                                    <div style="display: flex; align-items: start; gap: 1rem;">
                                        <div style="font-size: 2rem; flex-shrink: 0;">📅</div>
                                        <div style="flex: 1;">
                                            <div style="font-weight: 700; font-size: 1.1rem; margin-bottom: 0.3rem; color: var(--text-dark);">
                                                <?php echo htmlspecialchars($event['Title']); ?>
                                            </div>
                                            <div style="font-size: 0.9rem; color: #666; margin-bottom: 0.5rem;">
                                                <span style="display: inline-block; background: #FFE5E5; padding: 0.2rem 0.5rem; border-radius: 10px; font-size: 0.8rem; margin-right: 0.5rem;" data-en="<?php echo $typeLabel['en']; ?>" data-ar="<?php echo $typeLabel['ar']; ?>"><?php echo $typeLabel['en']; ?></span>
                                                <i class="fas fa-calendar-alt" style="margin-right: 0.3rem; color: #FF6B9D;"></i>
                                                <?php echo $formattedDate; ?>
                                                <?php if ($timeStr): ?>
                                                    <i class="fas fa-clock" style="margin-left: 0.5rem; margin-right: 0.3rem; color: #FF6B9D;"></i>
                                                    <?php echo $timeStr; ?>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size: 0.85rem; color: #999; margin-bottom: 0.3rem;">
                                                <i class="fas fa-map-marker-alt" style="margin-right: 0.3rem; color: #6BCB77;"></i>
                                                <?php echo $locationStr; ?>
                                            </div>
                                            <?php if ($description && $description !== 'No description available'): ?>
                                            <div style="font-size: 0.85rem; color: #666; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #FFE5E5;">
                                                <?php echo $description; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($totalEventsCount > 4): ?>
                            <div style="text-align: center; margin-top: 1rem; padding-top: 1rem; border-top: 2px solid #FFE5E5;">
                                <button class="btn btn-secondary" onclick="viewAllEvents()" style="width: 100%;">
                                    <i class="fas fa-chevron-down" style="margin-right: 0.5rem;"></i>
                                    <span data-en="View More Events" data-ar="عرض المزيد من الأحداث">View More Events</span>
                                    <span style="margin-left: 0.5rem; opacity: 0.7;" data-en="(<?php echo $totalEventsCount - 4; ?> more)" data-ar="(<?php echo $totalEventsCount - 4; ?> المزيد)">(<?php echo $totalEventsCount - 4; ?> more)</span>
                                </button>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="allEventsModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 800px; max-height: 90vh; overflow-y: auto;">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #FFE5E5;">
                <h2 style="margin: 0; color: var(--text-dark);">
                    <span class="card-icon">📅</span>
                    <span data-en="All Upcoming Events" data-ar="جميع الأحداث القادمة">All Upcoming Events</span>
                </h2>
                <button onclick="closeAllEventsModal()" style="background: none; border: none; font-size: 1.5rem; color: #999; cursor: pointer; padding: 0.5rem;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="allEventsList" class="upcoming-events-list">
                
            </div>
        </div>
    </div>

    <div class="chat-widget">
        <button class="chat-toggle" onclick="window.location.href='chat-with-teachers.php'">💬</button>
        <div class="chat-window" id="chatWindow">
            <div class="chat-header">
                <h3 data-en="Chat with Teachers" data-ar="الدردشة مع المعلمين">Chat with Teachers</h3>
                <button class="chat-close" onclick="toggleChat()">&times;</button>
            </div>
            <div class="chat-messages" id="chatMessages">
                <div class="chat-message">
                    <div class="chat-avatar">👩‍🏫</div>
                    <div class="chat-bubble">
                        <div style="font-weight: 700; margin-bottom: 0.3rem;">Ms. Sarah</div>
                        <div data-en="Hello! How can I help you today?" data-ar="مرحباً! كيف يمكنني مساعدتك اليوم؟">Hello! How can I help you today?</div>
                    </div>
                </div>
            </div>
            <div class="chat-input-area">
                <input type="text" class="chat-input" id="chatInput" placeholder="Type your message..." onkeypress="handleChatKeyPress(event)">
                <button class="chat-send" onclick="sendChatMessage()">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>

    <div id="profileModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <span onclick="closeProfileSettings()" style="float: right; font-size: 32px; cursor: pointer; color: #FF6B9D; font-weight: bold;">&times;</span>
            <h2 style="margin-bottom: 1.5rem; color: #FF6B9D; font-family: 'Fredoka', sans-serif; font-size: 2rem;" data-en="Profile Settings" data-ar="إعدادات الملف الشخصي">Profile Settings</h2>
            <form onsubmit="handleProfileUpdate(event)">
                <div class="form-group">
                    <label data-en="Phone Number" data-ar="رقم الهاتف">Phone Number</label>
                    <input type="tel" id="profilePhone" value="+1 234 567 8900" required>
                </div>
                <div class="form-group">
                    <label data-en="Email Address" data-ar="عنوان البريد الإلكتروني">Email Address</label>
                    <input type="email" id="profileEmail" value="ahmed.ali@school.com" required>
                </div>
                <div class="form-group">
                    <label data-en="Current Password" data-ar="كلمة المرور الحالية">Current Password</label>
                    <input type="password" id="currentPassword" required>
                </div>
                <div class="form-group">
                    <label data-en="New Password" data-ar="كلمة المرور الجديدة">New Password</label>
                    <input type="password" id="newPassword" required>
                </div>
                <div class="form-group">
                    <label data-en="Confirm New Password" data-ar="تأكيد كلمة المرور الجديدة">Confirm New Password</label>
                    <input type="password" id="confirmPassword" required>
                </div>
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" style="width: 100%;" data-en="Save Changes" data-ar="حفظ التغييرات">Save Changes</button>
                    <button type="button" class="btn btn-secondary" style="width: 100%;" onclick="closeProfileSettings()" data-en="Cancel" data-ar="إلغاء">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="settingsModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <span onclick="closeSettings()" style="float: right; font-size: 32px; cursor: pointer; color: #FF6B9D; font-weight: bold;">&times;</span>
            <h2 style="margin-bottom: 1.5rem; color: #FF6B9D; font-family: 'Fredoka', sans-serif; font-size: 2rem;" data-en="Program Settings" data-ar="إعدادات البرنامج">Program Settings</h2>
            
            <div class="settings-section">
                <h3 data-en="Notifications" data-ar="الإشعارات">Notifications</h3>
                <div class="setting-item">
                    <div>
                        <div style="font-weight: 700;" data-en="Email Notifications" data-ar="إشعارات البريد الإلكتروني">Email Notifications</div>
                        <div style="font-size: 0.9rem; color: #666;" data-en="Receive notifications via email" data-ar="تلقي الإشعارات عبر البريد الإلكتروني">Receive notifications via email</div>
                    </div>
                    <div class="toggle-switch active" onclick="toggleSetting(this)"></div>
                </div>
                <div class="setting-item">
                    <div>
                        <div style="font-weight: 700;" data-en="Assignment Reminders" data-ar="تذكيرات الواجبات">Assignment Reminders</div>
                        <div style="font-size: 0.9rem; color: #666;" data-en="Get reminded about upcoming assignments" data-ar="الحصول على تذكير بالواجبات القادمة">Get reminded about upcoming assignments</div>
                    </div>
                    <div class="toggle-switch active" onclick="toggleSetting(this)"></div>
                </div>
                <div class="setting-item">
                    <div>
                        <div style="font-weight: 700;" data-en="Grade Updates" data-ar="تحديثات الدرجات">Grade Updates</div>
                        <div style="font-size: 0.9rem; color: #666;" data-en="Notify when grades are updated" data-ar="الإشعار عند تحديث الدرجات">Notify when grades are updated</div>
                    </div>
                    <div class="toggle-switch active" onclick="toggleSetting(this)"></div>
                </div>
                <div class="setting-item">
                    <div>
                        <div style="font-weight: 700;" data-en="Teacher Messages" data-ar="رسائل المعلم">Teacher Messages</div>
                        <div style="font-size: 0.9rem; color: #666;" data-en="Get notified about teacher messages" data-ar="الإشعار برسائل المعلم">Get notified about teacher messages</div>
                    </div>
                    <div class="toggle-switch active" onclick="toggleSetting(this)"></div>
                </div>
            </div>

            <div class="settings-section">
                <h3 data-en="Appearance" data-ar="المظهر">Appearance</h3>
                <div class="setting-item">
                    <div>
                        <div style="font-weight: 700;" data-en="Theme Color" data-ar="لون المظهر">Theme Color</div>
                        <div style="font-size: 0.9rem; color: #666;" data-en="Choose your preferred theme color" data-ar="اختر لون المظهر المفضل">Choose your preferred theme color</div>
                    </div>
                </div>
                <div class="theme-option">
                    <div class="theme-color active" style="background: #FF6B9D;" onclick="changeTheme('#FF6B9D', this)"></div>
                    <div class="theme-color" style="background: #6BCB77;" onclick="changeTheme('#6BCB77', this)"></div>
                    <div class="theme-color" style="background: #4A90E2;" onclick="changeTheme('#4A90E2', this)"></div>
                    <div class="theme-color" style="background: #9B59B6;" onclick="changeTheme('#9B59B6', this)"></div>
                    <div class="theme-color" style="background: #E67E22;" onclick="changeTheme('#E67E22', this)"></div>
                </div>
                <div class="setting-item" style="margin-top: 1rem;">
                    <div>
                        <div style="font-weight: 700;" data-en="Dark Mode" data-ar="الوضع الداكن">Dark Mode</div>
                        <div style="font-size: 0.9rem; color: #666;" data-en="Switch to dark theme" data-ar="التبديل إلى المظهر الداكن">Switch to dark theme</div>
                    </div>
                    <div class="toggle-switch" onclick="toggleSetting(this)"></div>
                </div>
            </div>

            <div class="action-buttons" style="margin-top: 2rem;">
                <button type="button" class="btn btn-primary" style="width: 100%;" onclick="saveSettings()" data-en="Save Settings" data-ar="حفظ الإعدادات">Save Settings</button>
                <button type="button" class="btn btn-secondary" style="width: 100%;" onclick="closeSettings()" data-en="Cancel" data-ar="إلغاء">Cancel</button>
            </div>
        </div>
    </div>
            </div>
        </div>
    </div>

    <div class="side-menu-overlay" id="sideMenuOverlay" onclick="toggleSideMenu()"></div>

    <div class="side-menu-mobile" id="sideMenuMobile">
        <div class="side-menu-header">
            <h3 data-en="Quick Menu" data-ar="القائمة السريعة">Quick Menu</h3>
            <button class="side-menu-close" onclick="toggleSideMenu()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="side-menu-content">
            <a href="my-assignments.php" class="side-menu-item" onclick="toggleSideMenu();">
                <div class="side-menu-icon">📝</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="My Assignments" data-ar="واجباتي">My Assignments</div>
                    <div class="side-menu-subtitle" data-en="View and submit assignments" data-ar="عرض وتقديم الواجبات">View and submit assignments</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </a>
            <a href="academic-performance.php" class="side-menu-item" onclick="toggleSideMenu();">
                <div class="side-menu-icon">📈</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="Academic Performance" data-ar="الأداء الأكاديمي">Academic Performance</div>
                    <div class="side-menu-subtitle" data-en="View grades and progress" data-ar="عرض الدرجات والتقدم">View grades and progress</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </a>
            <a href="class-schedule.php" class="side-menu-item" onclick="toggleSideMenu();">
                <div class="side-menu-icon">📅</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="Class Schedule" data-ar="جدول الحصص">Class Schedule</div>
                    <div class="side-menu-subtitle" data-en="View weekly schedule" data-ar="عرض الجدول الأسبوعي">View weekly schedule</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </a>
            <a href="upcoming-exam-dates.php" class="side-menu-item" onclick="toggleSideMenu();">
                <div class="side-menu-icon">📋</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="Upcoming Exams" data-ar="الامتحانات القادمة">Upcoming Exams</div>
                    <div class="side-menu-subtitle" data-en="View exam dates and details" data-ar="عرض مواعيد الامتحانات والتفاصيل">View exam dates and details</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </a>
            <a href="attendance-record.php" class="side-menu-item" onclick="toggleSideMenu();">
                <div class="side-menu-icon">📊</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="Attendance Record" data-ar="سجل الحضور">Attendance Record</div>
                    <div class="side-menu-subtitle" data-en="Track attendance history" data-ar="تتبع سجل الحضور">Track attendance history</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </a>
            <a href="chat-with-teachers.php" class="side-menu-item" onclick="toggleSideMenu();">
                <div class="side-menu-icon">💬</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="Chat with Teachers" data-ar="الدردشة مع المعلمين">Chat with Teachers</div>
                    <div class="side-menu-subtitle" data-en="Communicate with teachers" data-ar="تواصل مع المعلمين">Communicate with teachers</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </a>
            <a href="../homepage.php" class="side-menu-item" onclick="toggleSideMenu();">
                <div class="side-menu-icon">🏠</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="Home" data-ar="الرئيسية">Home</div>
                    <div class="side-menu-subtitle" data-en="Go to homepage" data-ar="الذهاب إلى الصفحة الرئيسية">Go to homepage</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </a>
            <div class="side-menu-item" onclick="openProfileSettings(); toggleSideMenu();">
                <div class="side-menu-icon">👤</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="Profile Settings" data-ar="إعدادات الملف الشخصي">Profile Settings</div>
                    <div class="side-menu-subtitle" data-en="Edit your profile" data-ar="تعديل ملفك الشخصي">Edit your profile</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </div>
            <div class="side-menu-item" onclick="openSettings(); toggleSideMenu();">
                <div class="side-menu-icon">⚙️</div>
                <div class="side-menu-text">
                    <div class="side-menu-title" data-en="Settings" data-ar="الإعدادات">Settings</div>
                    <div class="side-menu-subtitle" data-en="Customize preferences" data-ar="تخصيص التفضيلات">Customize preferences</div>
                </div>
                <i class="fas fa-chevron-right side-menu-arrow"></i>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        
        const allEventsData = <?php echo json_encode($allUpcomingEvents, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function viewAllEvents() {
            const modal = document.getElementById('allEventsModal');
            const eventsList = document.getElementById('allEventsList');
            
            if (!allEventsData || allEventsData.length === 0) {
                eventsList.innerHTML = '<div style="text-align: center; padding: 2rem; color: #666;">' + 
                    (currentLanguage === 'en' ? 'No events found' : 'لا توجد أحداث') + '</div>';
                modal.style.display = 'flex';
                return;
            }
            
            const typeLabels = {
                'academic': { en: 'Academic', ar: 'أكاديمي' },
                'sports': { en: 'Sports', ar: 'رياضي' },
                'cultural': { en: 'Cultural', ar: 'ثقافي' },
                'meeting': { en: 'Meeting', ar: 'اجتماع' },
                'other': { en: 'Other', ar: 'أخرى' }
            };
            
            eventsList.innerHTML = allEventsData.map(event => {
                const eventDate = new Date(event.Date);
                const formattedDate = eventDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                const timeStr = event.Time ? new Date('1970-01-01T' + event.Time).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true }) : '';
                const locationStr = event.Location || 'Location TBA';
                const description = event.Description ? (event.Description.length > 150 ? event.Description.substring(0, 150) + '...' : event.Description) : 'No description available';
                const typeLabel = typeLabels[event.Type] || { en: event.Type, ar: event.Type };
                
                return `
                    <div class="event-item" style="padding: 1rem; border-bottom: 2px solid #FFE5E5; transition: all 0.3s;">
                        <div style="display: flex; align-items: start; gap: 1rem;">
                            <div style="font-size: 2rem; flex-shrink: 0;">📅</div>
                            <div style="flex: 1;">
                                <div style="font-weight: 700; font-size: 1.1rem; margin-bottom: 0.3rem; color: var(--text-dark);">
                                    ${escapeHtml(event.Title)}
                                </div>
                                <div style="font-size: 0.9rem; color: #666; margin-bottom: 0.5rem;">
                                    <span style="display: inline-block; background: #FFE5E5; padding: 0.2rem 0.5rem; border-radius: 10px; font-size: 0.8rem; margin-right: 0.5rem;" data-en="${typeLabel.en}" data-ar="${typeLabel.ar}">${typeLabel.en}</span>
                                    <i class="fas fa-calendar-alt" style="margin-right: 0.3rem; color: #FF6B9D;"></i>
                                    ${formattedDate}
                                    ${timeStr ? `<i class="fas fa-clock" style="margin-left: 0.5rem; margin-right: 0.3rem; color: #FF6B9D;"></i>${timeStr}` : ''}
                                </div>
                                <div style="font-size: 0.85rem; color: #999; margin-bottom: 0.3rem;">
                                    <i class="fas fa-map-marker-alt" style="margin-right: 0.3rem; color: #6BCB77;"></i>
                                    ${escapeHtml(locationStr)}
                                </div>
                                ${description && description !== 'No description available' ? `
                                <div style="font-size: 0.85rem; color: #666; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #FFE5E5;">
                                    ${escapeHtml(description)}
                                </div>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
            
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeAllEventsModal() {
            const modal = document.getElementById('allEventsModal');
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }

        window.onclick = function(event) {
            const modal = document.getElementById('allEventsModal');
            if (event.target === modal) {
                closeAllEventsModal();
            }
        }

        function updateNotificationBadge() {
            const unreadCount = document.querySelectorAll('.notification-dropdown-item.unread').length;
            const badge = document.getElementById('notificationCount');
            const badgeMobile = document.getElementById('notificationCountMobile');
            if (unreadCount > 0) {
                if (badge) badge.textContent = unreadCount;
                if (badgeMobile) badgeMobile.textContent = unreadCount;
            } else {
                if (badge) badge.style.display = 'none';
                if (badgeMobile) badgeMobile.style.display = 'none';
            }
        }
        
        function handleNotificationClick(element) {
            element.classList.remove('unread');
            updateNotificationBadge();
        }
        
        function markAllAsRead() {
            document.querySelectorAll('.notification-dropdown-item').forEach(item => {
                item.classList.remove('unread');
            });
            updateNotificationBadge();
        }

        document.addEventListener('DOMContentLoaded', function() {
            updateNotificationBadge();
        });
        
        function toggleInstructions() {
            const box = document.getElementById('instructionsBox');
            if (box) {
                box.style.display = box.style.display === 'none' ? 'block' : 'none';
            }
        }
    </script>
    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: white;
            padding: 2rem;
            border-radius: 25px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            position: relative;
        }

        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                max-width: 95%;
                padding: 1.5rem;
            }
        }
    </style>
</body>
</html>

