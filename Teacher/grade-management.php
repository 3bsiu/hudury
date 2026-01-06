<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-teacher.php';

requireUserType('teacher');

$currentTeacherId = getCurrentUserId();
$currentTeacher = getCurrentUserData($pdo);
$teacherName = $_SESSION['user_name'] ?? 'Teacher';

$teacherClasses = [];
$teacherCourses = [];
$teacherClassCourseMap = []; 

try {
    
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.Class_ID, c.Name as Class_Name, c.Grade_Level, c.Section, c.Academic_Year,
               co.Course_ID, co.Course_Name, co.Description
        FROM teacher_class_course tcc
        JOIN class c ON tcc.Class_ID = c.Class_ID
        JOIN course co ON tcc.Course_ID = co.Course_ID
        WHERE tcc.Teacher_ID = ?
        ORDER BY c.Grade_Level, c.Section, co.Course_Name
    ");
    $stmt->execute([$currentTeacherId]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($assignments as $assignment) {
        $classId = $assignment['Class_ID'];
        $courseId = $assignment['Course_ID'];

        if (!isset($teacherClasses[$classId])) {
            $teacherClasses[$classId] = [
                'Class_ID' => $classId,
                'Class_Name' => $assignment['Class_Name'],
                'Grade_Level' => $assignment['Grade_Level'],
                'Section' => $assignment['Section'],
                'Academic_Year' => $assignment['Academic_Year']
            ];
        }

        if (!isset($teacherCourses[$courseId])) {
            $teacherCourses[$courseId] = [
                'Course_ID' => $courseId,
                'Course_Name' => $assignment['Course_Name'],
                'Description' => $assignment['Description']
            ];
        }

        if (!isset($teacherClassCourseMap[$classId])) {
            $teacherClassCourseMap[$classId] = [];
        }
        if (!in_array($courseId, $teacherClassCourseMap[$classId])) {
            $teacherClassCourseMap[$classId][] = $courseId;
        }
    }
    
    $teacherClasses = array_values($teacherClasses);
    $teacherCourses = array_values($teacherCourses);
    
} catch (PDOException $e) {
    error_log("Error fetching teacher classes: " . $e->getMessage());
    $teacherClasses = [];
    $teacherCourses = [];
}

$selectedClassId = isset($_GET['classId']) ? intval($_GET['classId']) : (!empty($teacherClasses) ? $teacherClasses[0]['Class_ID'] : null);
$selectedCourseId = isset($_GET['courseId']) ? intval($_GET['courseId']) : null;

$students = [];
if ($selectedClassId) {
    try {
        $stmt = $pdo->prepare("
            SELECT Student_ID, Student_Code, NameEn, NameAr, Class_ID
            FROM student
            WHERE Class_ID = ?
            ORDER BY NameEn, NameAr
        ");
        $stmt->execute([$selectedClassId]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching students: " . $e->getMessage());
    }
}

$gradesData = []; 
if ($selectedClassId && $selectedCourseId) {
    try {
        $studentIds = array_column($students, 'Student_ID');
        if (!empty($studentIds)) {
            $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
            $stmt = $pdo->prepare("
                SELECT Student_ID, Course_ID, Type, Value, Date_Recorded, Remarks
                FROM grade
                WHERE Student_ID IN ($placeholders) AND Course_ID = ? AND Teacher_ID = ?
                ORDER BY Student_ID, Type, Date_Recorded DESC
            ");
            $params = array_merge($studentIds, [$selectedCourseId, $currentTeacherId]);
            $stmt->execute($params);
            $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($grades as $grade) {
                $studentId = $grade['Student_ID'];
                $courseId = $grade['Course_ID'];
                $type = $grade['Type'];
                
                if (!isset($gradesData[$studentId])) {
                    $gradesData[$studentId] = [];
                }
                if (!isset($gradesData[$studentId][$courseId])) {
                    $gradesData[$studentId][$courseId] = [];
                }

                if (!isset($gradesData[$studentId][$courseId][$type])) {
                    $gradesData[$studentId][$courseId][$type] = floatval($grade['Value']);
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching grades: " . $e->getMessage());
    }
}

$teacherDisplayName = $currentTeacher['NameEn'] ?? $teacherName;
$teacherSubject = !empty($teacherCourses) ? $teacherCourses[0]['Course_Name'] ?? 'Teacher' : 'Teacher';
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grade Management - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        .grade-cell {
            text-align: center;
            font-weight: 600;
            padding: 0.75rem;
        }
        .grade-cell.total-grade {
            background: #E5F3FF;
            font-size: 1.1rem;
            color: #2c3e50;
        }
        .grade-cell[data-type="Midterm"] {
            background: #FFF9E5;
        }
        .grade-cell[data-type="Final"] {
            background: #FFE5E5;
        }
        .grade-cell[data-type="Assignment"] {
            background: #E5F3FF;
        }
        .grade-cell[data-type="Quiz"] {
            background: #E5FFE5;
        }
        .grade-cell[data-type="Project"] {
            background: #F5E5FF;
        }
        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(400px);
                opacity: 0;
            }
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 0;
            max-width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 2px solid #FFE5E5;
        }
        .modal-title {
            margin: 0;
            font-size: 1.5rem;
            color: #2c3e50;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 2rem;
            color: #999;
            cursor: pointer;
            padding: 0;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s;
        }
        .modal-close:hover {
            background: #FFE5E5;
            color: #FF6B9D;
        }
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #FFE5E5;
            border-radius: 10px;
            font-size: 1rem;
            font-family: 'Nunito', sans-serif;
            transition: all 0.3s;
        }
        .form-control:focus {
            outline: none;
            border-color: #6BCB77;
            box-shadow: 0 0 0 3px rgba(107, 203, 119, 0.1);
        }
        @media (max-width: 768px) {
            .table-container {
                overflow-x: auto;
            }
            .data-table {
                font-size: 0.9rem;
            }
            .grade-cell {
                padding: 0.5rem;
                font-size: 0.85rem;
            }
            .modal-content {
                max-width: 95%;
                margin: 1rem;
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
                <span class="page-icon">📊</span>
                <span data-en="Grade Management" data-ar="إدارة الدرجات">Grade Management</span>
            </h1>
            <p class="page-subtitle" data-en="Add and edit student grades for multiple terms" data-ar="إضافة وتعديل درجات الطلاب لعدة فصول">Add and edit student grades for multiple terms</p>
        </div>

        <div class="card" style="background: linear-gradient(135deg, #E5F3FF, #FFF9E5); border-left: 4px solid #6BCB77;">
            <div style="display: flex; align-items: start; gap: 1rem;">
                <div style="font-size: 2rem;">ℹ️</div>
                <div style="flex: 1;">
                    <h3 style="margin: 0 0 0.5rem 0; color: #2c3e50;" data-en="How to Enter Grades" data-ar="كيفية إدخال الدرجات">How to Enter Grades</h3>
                    <ol style="margin: 0; padding-left: 1.5rem; color: #555;">
                        <li data-en="Select a class and subject from the filters above" data-ar="اختر الفصل والمادة من المرشحات أعلاه">Select a class and subject from the filters above</li>
                        <li data-en="Click 'Add/Edit Grade' button or 'Edit' button next to a student" data-ar="انقر على زر 'إضافة/تعديل درجة' أو زر 'تعديل' بجانب الطالب">Click 'Add/Edit Grade' button or 'Edit' button next to a student</li>
                        <li data-en="Enter grades for each component: Midterm (max 30), Final (max 40), Assignment (max 10), Quiz (max 10), Project (max 10)" data-ar="أدخل الدرجات لكل مكون: نصفي (حد أقصى 30)، نهائي (حد أقصى 40)، واجب (حد أقصى 10)، اختبار (حد أقصى 10)، مشروع (حد أقصى 10)">Enter grades for each component: Midterm (max 30), Final (max 40), Assignment (max 10), Quiz (max 10), Project (max 10)</li>
                        <li data-en="Total is automatically calculated as the sum of all components (max 100)" data-ar="يتم حساب المجموع تلقائياً كمجموع جميع المكونات (حد أقصى 100)">Total is automatically calculated as the sum of all components (max 100)</li>
                        <li data-en="Click 'Save Grade' to save to database immediately" data-ar="انقر على 'حفظ الدرجة' للحفظ في قاعدة البيانات فوراً">Click 'Save Grade' to save to database immediately</li>
                    </ol>
                </div>
                <button onclick="this.parentElement.parentElement.style.display='none'" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #999;">&times;</button>
            </div>
        </div>

        <div class="card">
            <div class="action-bar">
                <div class="form-row" style="flex: 1; display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label data-en="Class" data-ar="الفصل">Class <span style="color: red;">*</span></label>
                        <select id="classSelector" onchange="loadGrades()" required>
                            <option value="">-- Select Class --</option>
                            <?php foreach ($teacherClasses as $class): ?>
                                <option value="<?php echo $class['Class_ID']; ?>" <?php echo ($selectedClassId == $class['Class_ID']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['Class_Name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label data-en="Subject" data-ar="المادة">Subject <span style="color: red;">*</span></label>
                        <select id="courseSelector" onchange="loadGrades()" required>
                            <option value="">-- Select Subject --</option>
                            <?php if ($selectedClassId && isset($teacherClassCourseMap[$selectedClassId])): ?>
                                <?php foreach ($teacherClassCourseMap[$selectedClassId] as $courseId): ?>
                                    <?php 
                                    $course = array_filter($teacherCourses, function($c) use ($courseId) { return $c['Course_ID'] == $courseId; });
                                    $course = reset($course);
                                    if ($course):
                                    ?>
                                        <option value="<?php echo $course['Course_ID']; ?>" <?php echo ($selectedCourseId == $course['Course_ID']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($course['Course_Name']); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="search-filter-bar">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="studentSearch" placeholder="Search students..." data-placeholder-en="Search students..." data-placeholder-ar="البحث عن الطلاب..." oninput="filterStudents()">
                </div>
                <button class="btn btn-primary" onclick="saveAllGrades()">
                    <i class="fas fa-save"></i>
                    <span data-en="Save All Grades" data-ar="حفظ جميع الدرجات">Save All Grades</span>
                </button>
            </div>
        </div>

        <?php if ($selectedClassId && $selectedCourseId && !empty($students)): 
            
            $totalStudents = count($students);
            $studentsWithGrades = 0;
            $totalSum = 0;
            $totals = [];

            $maxTotal = 100; 
            
            foreach ($students as $student) {
                $studentId = $student['Student_ID'];
                $midterm = isset($gradesData[$studentId][$selectedCourseId]['Midterm']) ? min(30, floatval($gradesData[$studentId][$selectedCourseId]['Midterm'])) : 0;
                $final = isset($gradesData[$studentId][$selectedCourseId]['Final']) ? min(40, floatval($gradesData[$studentId][$selectedCourseId]['Final'])) : 0;
                $assignment = isset($gradesData[$studentId][$selectedCourseId]['Assignment']) ? min(10, floatval($gradesData[$studentId][$selectedCourseId]['Assignment'])) : 0;
                $quiz = isset($gradesData[$studentId][$selectedCourseId]['Quiz']) ? min(10, floatval($gradesData[$studentId][$selectedCourseId]['Quiz'])) : 0;
                $project = isset($gradesData[$studentId][$selectedCourseId]['Project']) ? min(10, floatval($gradesData[$studentId][$selectedCourseId]['Project'])) : 0;

                $total = $midterm + $final + $assignment + $quiz + $project;
                
                if ($total > 0) {
                    $totals[] = $total;
                    $totalSum += $total;
                    $studentsWithGrades++;
                }
            }
            
            $classAverage = $studentsWithGrades > 0 ? round($totalSum / $studentsWithGrades, 1) : 0;
            $selectedCourse = array_filter($teacherCourses, function($c) use ($selectedCourseId) { return $c['Course_ID'] == $selectedCourseId; });
            $selectedCourse = reset($selectedCourse);
        ?>
            <div class="card" style="background: linear-gradient(135deg, #6BCB77, #4ECDC4); color: white; margin-bottom: 1.5rem;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 2rem; padding: 1.5rem;">
                    <div style="text-align: center;">
                        <div style="font-size: 0.9rem; opacity: 0.9; margin-bottom: 0.5rem;" data-en="Subject" data-ar="المادة">Subject</div>
                        <div style="font-size: 1.5rem; font-weight: 700;"><?php echo htmlspecialchars($selectedCourse['Course_Name'] ?? 'N/A'); ?></div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 0.9rem; opacity: 0.9; margin-bottom: 0.5rem;" data-en="Total Students" data-ar="إجمالي الطلاب">Total Students</div>
                        <div style="font-size: 1.5rem; font-weight: 700;"><?php echo $totalStudents; ?></div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 0.9rem; opacity: 0.9; margin-bottom: 0.5rem;" data-en="Class Average Total" data-ar="متوسط المجموع للفصل">Class Average Total</div>
                        <div style="font-size: 1.5rem; font-weight: 700;"><?php echo number_format($classAverage, 1); ?> / <?php echo $maxTotal; ?></div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 0.9rem; opacity: 0.9; margin-bottom: 0.5rem;" data-en="Graded Students" data-ar="الطلاب المصححين">Graded Students</div>
                        <div style="font-size: 1.5rem; font-weight: 700;"><?php echo $studentsWithGrades; ?> / <?php echo $totalStudents; ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($selectedClassId && $selectedCourseId): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📋</span>
                    <span data-en="Student Grades" data-ar="درجات الطلاب">Student Grades</span>
                </h2>
                    <button class="btn btn-primary" onclick="openAddGradeModal()">
                        <i class="fas fa-plus"></i>
                        <span data-en="Add/Edit Grade" data-ar="إضافة/تعديل درجة">Add/Edit Grade</span>
                    </button>
                </div>
                <div class="table-container" style="overflow-x: auto;">
                    <table class="data-table" role="table" aria-label="Student Grades" style="min-width: 900px;">
                    <thead>
                        <tr>
                                <th data-en="Student" data-ar="الطالب">Student</th>
                                <th data-en="Subject" data-ar="المادة">Subject</th>
                                <th data-en="Midterm (30)" data-ar="نصفي (30)">Midterm (30)</th>
                                <th data-en="Final (40)" data-ar="نهائي (40)">Final (40)</th>
                                <th data-en="Assignment (10)" data-ar="واجب (10)">Assignment (10)</th>
                                <th data-en="Quiz (10)" data-ar="اختبار (10)">Quiz (10)</th>
                                <th data-en="Project (10)" data-ar="مشروع (10)">Project (10)</th>
                                <th data-en="Total" data-ar="المجموع">Total</th>
                            <th data-en="Grade" data-ar="الدرجة">Grade</th>
                            <th data-en="Actions" data-ar="الإجراءات">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="gradesTableBody">
                            <?php if (empty($students)): ?>
                                <tr>
                                    <td colspan="10" style="text-align: center; padding: 2rem; color: #999;">
                                        <div data-en="No students found in this class" data-ar="لم يتم العثور على طلاب في هذا الفصل">No students found in this class</div>
                                    </td>
                                </tr>
                            <?php else: 
                                $selectedCourse = array_filter($teacherCourses, function($c) use ($selectedCourseId) { return $c['Course_ID'] == $selectedCourseId; });
                                $selectedCourse = reset($selectedCourse);
                                $courseName = htmlspecialchars($selectedCourse['Course_Name'] ?? 'N/A');
                            ?>
                                <?php foreach ($students as $student): 
                                    $studentId = $student['Student_ID'];
                                    
                                    $midterm = isset($gradesData[$studentId][$selectedCourseId]['Midterm']) ? min(30, floatval($gradesData[$studentId][$selectedCourseId]['Midterm'])) : 0;
                                    $final = isset($gradesData[$studentId][$selectedCourseId]['Final']) ? min(40, floatval($gradesData[$studentId][$selectedCourseId]['Final'])) : 0;
                                    $assignment = isset($gradesData[$studentId][$selectedCourseId]['Assignment']) ? min(10, floatval($gradesData[$studentId][$selectedCourseId]['Assignment'])) : 0;
                                    $quiz = isset($gradesData[$studentId][$selectedCourseId]['Quiz']) ? min(10, floatval($gradesData[$studentId][$selectedCourseId]['Quiz'])) : 0;
                                    $project = isset($gradesData[$studentId][$selectedCourseId]['Project']) ? min(10, floatval($gradesData[$studentId][$selectedCourseId]['Project'])) : 0;

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
                                    
                                    $studentName = htmlspecialchars($student['NameEn'] ?? $student['NameAr'] ?? 'N/A');
                                    $studentCode = htmlspecialchars($student['Student_Code'] ?? 'N/A');
                                ?>
                                    <tr data-student-id="<?php echo $studentId; ?>" data-course-id="<?php echo $selectedCourseId; ?>" data-student-name="<?php echo $studentName; ?>" data-student-code="<?php echo $studentCode; ?>">
                                        <td style="font-weight: 700; color: #2c3e50;">
                                            <div><?php echo $studentName; ?></div>
                                            <div style="font-size: 0.85rem; color: #666; font-weight: normal;"><?php echo $studentCode; ?></div>
                                        </td>
                                        <td style="font-weight: 600; color: #6BCB77;"><?php echo $courseName; ?></td>
                                        <td class="grade-cell" data-type="Midterm" data-value="<?php echo $midterm; ?>" data-max="30"><?php echo number_format($midterm, 1); ?></td>
                                        <td class="grade-cell" data-type="Final" data-value="<?php echo $final; ?>" data-max="40"><?php echo number_format($final, 1); ?></td>
                                        <td class="grade-cell" data-type="Assignment" data-value="<?php echo $assignment; ?>" data-max="10"><?php echo number_format($assignment, 1); ?></td>
                                        <td class="grade-cell" data-type="Quiz" data-value="<?php echo $quiz; ?>" data-max="10"><?php echo number_format($quiz, 1); ?></td>
                                        <td class="grade-cell" data-type="Project" data-value="<?php echo $project; ?>" data-max="10"><?php echo number_format($project, 1); ?></td>
                                        <td class="grade-cell total-grade" style="font-weight: 700; font-size: 1.1rem;" data-total="<?php echo $total; ?>"><?php echo number_format($total, 1); ?></td>
                                        <td class="grade-cell" style="font-weight: 700; color: <?php echo $total >= 90 ? '#6BCB77' : ($total >= 80 ? '#FFD93D' : '#FF6B9D'); ?>;"><?php echo $letterGrade; ?></td>
                                        <td>
                                            <button class="btn btn-small btn-primary" onclick="openEditGradeModal(<?php echo $studentId; ?>, '<?php echo $studentName; ?>')" title="Edit Grades">
                                                <i class="fas fa-edit"></i>
                                                <span data-en="Edit" data-ar="تعديل">Edit</span>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
                <div style="text-align: center; padding: 3rem; color: #999;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">📚</div>
                    <div data-en="Please select a class and subject to view grades" data-ar="يرجى اختيار فصل ومادة لعرض الدرجات">Please select a class and subject to view grades</div>
            </div>
            </div>
        <?php endif; ?>

        <div class="modal" id="gradeModal" role="dialog" aria-labelledby="gradeModalTitle" style="display: none;">
            <div class="modal-content" style="max-width: 600px;">
                <div class="modal-header">
                    <h2 class="modal-title" id="gradeModalTitle" data-en="Add/Edit Grade" data-ar="إضافة/تعديل درجة">Add/Edit Grade</h2>
                    <button class="modal-close" onclick="closeGradeModal()" aria-label="Close">&times;</button>
        </div>
                <form id="gradeForm" onsubmit="saveGrade(event)" style="padding: 1.5rem;">
                    <input type="hidden" id="modalStudentId" value="">
                    <input type="hidden" id="modalStudentName" value="">
                    
                    <div class="form-group">
                        <label data-en="Student" data-ar="الطالب">Student <span style="color: red;">*</span></label>
                        <div id="modalStudentContainer">
                            <input type="text" id="modalStudentDisplay" class="form-control" readonly style="background: #f5f5f5;">
            </div>
            </div>
                    
                    <div class="form-group">
                        <label data-en="Subject" data-ar="المادة">Subject</label>
                        <input type="text" id="modalSubjectDisplay" class="form-control" value="<?php echo htmlspecialchars($selectedCourse['Course_Name'] ?? 'N/A'); ?>" readonly style="background: #f5f5f5;">
    </div>

                    <div style="padding: 1.5rem;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin: 1.5rem 0;">
                            <div class="form-group">
                                <label data-en="Midterm" data-ar="نصفي">Midterm (Max: 30)</label>
                                <input type="number" id="modalMidterm" class="form-control" min="0" max="30" step="0.1" value="0">
            </div>
                <div class="form-group">
                                <label data-en="Final" data-ar="نهائي">Final (Max: 40)</label>
                                <input type="number" id="modalFinal" class="form-control" min="0" max="40" step="0.1" value="0">
                </div>
                <div class="form-group">
                                <label data-en="Assignment" data-ar="واجب">Assignment (Max: 10)</label>
                                <input type="number" id="modalAssignment" class="form-control" min="0" max="10" step="0.1" value="0">
                </div>
                            <div class="form-group">
                                <label data-en="Quiz" data-ar="اختبار">Quiz (Max: 10)</label>
                                <input type="number" id="modalQuiz" class="form-control" min="0" max="10" step="0.1" value="0">
                </div>
                            <div class="form-group">
                                <label data-en="Project" data-ar="مشروع">Project (Max: 10)</label>
                                <input type="number" id="modalProject" class="form-control" min="0" max="10" step="0.1" value="0">
                            </div>
                        </div>
                        
                        <div class="form-group" style="background: #E5F3FF; padding: 1rem; border-radius: 10px; margin: 1rem 0;">
                            <label style="font-weight: 700; color: #2c3e50;" data-en="Calculated Total" data-ar="المجموع المحسوب">Calculated Total:</label>
                            <div id="modalTotal" style="font-size: 1.5rem; font-weight: 700; color: #6BCB77; margin-top: 0.5rem;">0.0 / 100</div>
                            <div style="font-size: 0.85rem; color: #666; margin-top: 0.5rem;" data-en="Max: Midterm (30) + Final (40) + Quiz (10) + Assignment (10) + Project (10)" data-ar="الحد الأقصى: نصفي (30) + نهائي (40) + اختبار (10) + واجب (10) + مشروع (10)">Max: Midterm (30) + Final (40) + Quiz (10) + Assignment (10) + Project (10)</div>
                        </div>
                        
                        <div class="action-buttons" style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i>
                                <span data-en="Save Grade" data-ar="حفظ الدرجة">Save Grade</span>
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="closeGradeModal()" data-en="Cancel" data-ar="إلغاء">Cancel</button>
                        </div>
                    </div>
                    
            </form>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        
        let currentGrades = <?php echo json_encode($gradesData, JSON_UNESCAPED_UNICODE); ?>;
        const currentClassId = <?php echo $selectedClassId ?: 'null'; ?>;
        const currentCourseId = <?php echo $selectedCourseId ?: 'null'; ?>;
        const students = <?php echo json_encode($students, JSON_UNESCAPED_UNICODE); ?>;
        const teacherClassCourseMap = <?php echo json_encode($teacherClassCourseMap, JSON_UNESCAPED_UNICODE); ?>;
        const courses = <?php echo json_encode($teacherCourses, JSON_UNESCAPED_UNICODE); ?>;

        function loadGrades() {
            const classId = document.getElementById('classSelector').value;
            const courseId = document.getElementById('courseSelector').value;
            
            if (classId && courseId) {
                window.location.href = `grade-management.php?classId=${classId}&courseId=${courseId}`;
            }
        }

        document.getElementById('classSelector')?.addEventListener('change', function() {
            const classId = this.value;
            const courseSelect = document.getElementById('courseSelector');
            courseSelect.innerHTML = '<option value="">-- Select Subject --</option>';
            
            if (classId && teacherClassCourseMap[classId]) {
                teacherClassCourseMap[classId].forEach(courseId => {
                    const course = courses.find(c => c.Course_ID == courseId);
                    if (course) {
                        const option = document.createElement('option');
                        option.value = course.Course_ID;
                        option.textContent = course.Course_Name;
                        courseSelect.appendChild(option);
                    }
                });
            }
        });

        function filterStudents() {
            const searchTerm = document.getElementById('studentSearch').value.toLowerCase();
            const rows = document.querySelectorAll('#gradesTableBody tr');
            
            rows.forEach(row => {
                const studentName = row.querySelector('td:first-child')?.textContent.toLowerCase() || '';
                const studentId = row.querySelector('td:nth-child(2)')?.textContent.toLowerCase() || '';
                
                if (studentName.includes(searchTerm) || studentId.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function openAddGradeModal() {
            if (!currentClassId || !currentCourseId) {
                showNotification('Please select a class and subject first', 'error');
                return;
            }
            
            document.getElementById('gradeModalTitle').textContent = 'Add Grade';
            document.getElementById('modalStudentId').value = '';
            document.getElementById('modalStudentName').value = '';

            const container = document.getElementById('modalStudentContainer');
            container.innerHTML = '<select id="modalStudentSelect" class="form-control" required><option value="">-- Select Student --</option></select>';
            const studentSelect = document.getElementById('modalStudentSelect');
            
            students.forEach(student => {
                const option = document.createElement('option');
                option.value = student.Student_ID;
                option.textContent = (student.NameEn || student.NameAr || 'N/A') + ' (' + (student.Student_Code || 'N/A') + ')';
                studentSelect.appendChild(option);
            });
            
            studentSelect.onchange = function() {
                const selectedStudent = students.find(s => s.Student_ID == this.value);
                if (selectedStudent) {
                    document.getElementById('modalStudentId').value = selectedStudent.Student_ID;
                    document.getElementById('modalStudentName').value = selectedStudent.NameEn || selectedStudent.NameAr || 'N/A';
                    loadStudentGrades(selectedStudent.Student_ID);
                }
            };

            document.getElementById('modalMidterm').value = '0';
            document.getElementById('modalFinal').value = '0';
            document.getElementById('modalAssignment').value = '0';
            document.getElementById('modalQuiz').value = '0';
            document.getElementById('modalProject').value = '0';
            updateModalTotal();
            
            document.getElementById('gradeModal').style.display = 'flex';
        }

        function openEditGradeModal(studentId, studentName) {
            document.getElementById('gradeModalTitle').textContent = 'Edit Grade';
            document.getElementById('modalStudentId').value = studentId;
            document.getElementById('modalStudentName').value = studentName;

            const container = document.getElementById('modalStudentContainer');
            container.innerHTML = `<input type="text" id="modalStudentDisplay" class="form-control" value="${studentName}" readonly style="background: #f5f5f5;">`;
            
            loadStudentGrades(studentId);
            document.getElementById('gradeModal').style.display = 'flex';
        }

        function loadStudentGrades(studentId) {
            const midterm = Math.min(30, currentGrades[studentId]?.[currentCourseId]?.['Midterm'] || 0);
            const final = Math.min(40, currentGrades[studentId]?.[currentCourseId]?.['Final'] || 0);
            const assignment = Math.min(10, currentGrades[studentId]?.[currentCourseId]?.['Assignment'] || 0);
            const quiz = Math.min(10, currentGrades[studentId]?.[currentCourseId]?.['Quiz'] || 0);
            const project = Math.min(10, currentGrades[studentId]?.[currentCourseId]?.['Project'] || 0);
            
            document.getElementById('modalMidterm').value = midterm;
            document.getElementById('modalFinal').value = final;
            document.getElementById('modalAssignment').value = assignment;
            document.getElementById('modalQuiz').value = quiz;
            document.getElementById('modalProject').value = project;
            
            updateModalTotal();
        }

        function updateModalTotal() {
            
            const midterm = Math.min(30, parseFloat(document.getElementById('modalMidterm').value) || 0);
            const final = Math.min(40, parseFloat(document.getElementById('modalFinal').value) || 0);
            const assignment = Math.min(10, parseFloat(document.getElementById('modalAssignment').value) || 0);
            const quiz = Math.min(10, parseFloat(document.getElementById('modalQuiz').value) || 0);
            const project = Math.min(10, parseFloat(document.getElementById('modalProject').value) || 0);

            const total = (midterm + final + assignment + quiz + project).toFixed(1);
            
            document.getElementById('modalTotal').textContent = total + ' / 100';
        }

        ['modalMidterm', 'modalFinal', 'modalAssignment', 'modalQuiz', 'modalProject'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', function() {
                
                const max = {
                    'modalMidterm': 30,
                    'modalFinal': 40,
                    'modalAssignment': 10,
                    'modalQuiz': 10,
                    'modalProject': 10
                };
                if (parseFloat(this.value) > max[this.id]) {
                    this.value = max[this.id];
                }
                updateModalTotal();
            });
        });

        function closeGradeModal() {
            document.getElementById('gradeModal').style.display = 'none';
        }

        document.getElementById('gradeModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeGradeModal();
            }
        });

        function saveGrade(event) {
            event.preventDefault();
            
            const studentId = parseInt(document.getElementById('modalStudentId').value);
            if (!studentId) {
                showNotification('Please select a student', 'error');
                return;
            }

            const midterm = Math.min(30, parseFloat(document.getElementById('modalMidterm').value) || 0);
            const final = Math.min(40, parseFloat(document.getElementById('modalFinal').value) || 0);
            const assignment = Math.min(10, parseFloat(document.getElementById('modalAssignment').value) || 0);
            const quiz = Math.min(10, parseFloat(document.getElementById('modalQuiz').value) || 0);
            const project = Math.min(10, parseFloat(document.getElementById('modalProject').value) || 0);

            const grades = { midterm, final, assignment, quiz, project };
            const maxValues = { midterm: 30, final: 40, assignment: 10, quiz: 10, project: 10 };
            for (const [type, value] of Object.entries(grades)) {
                if (value < 0 || value > maxValues[type]) {
                    showNotification(`${type} grade must be between 0-${maxValues[type]}`, 'error');
                    return;
                }
            }

            if (!currentGrades[studentId]) {
                currentGrades[studentId] = {};
            }
            if (!currentGrades[studentId][currentCourseId]) {
                currentGrades[studentId][currentCourseId] = {};
            }
            
            currentGrades[studentId][currentCourseId]['Midterm'] = midterm;
            currentGrades[studentId][currentCourseId]['Final'] = final;
            currentGrades[studentId][currentCourseId]['Assignment'] = assignment;
            currentGrades[studentId][currentCourseId]['Quiz'] = quiz;
            currentGrades[studentId][currentCourseId]['Project'] = project;

            updateTableRowAll(studentId);

            saveGradeToDatabase(studentId, { midterm, final, assignment, quiz, project });
            
            closeGradeModal();
        }

        function updateTableRowAll(studentId) {
            const row = document.querySelector(`tr[data-student-id="${studentId}"]`);
            if (!row) return;

            const midterm = Math.min(30, currentGrades[studentId]?.[currentCourseId]?.['Midterm'] || 0);
            const final = Math.min(40, currentGrades[studentId]?.[currentCourseId]?.['Final'] || 0);
            const assignment = Math.min(10, currentGrades[studentId]?.[currentCourseId]?.['Assignment'] || 0);
            const quiz = Math.min(10, currentGrades[studentId]?.[currentCourseId]?.['Quiz'] || 0);
            const project = Math.min(10, currentGrades[studentId]?.[currentCourseId]?.['Project'] || 0);

            row.querySelector('td[data-type="Midterm"]').textContent = midterm.toFixed(1);
            row.querySelector('td[data-type="Midterm"]').setAttribute('data-value', midterm);
            row.querySelector('td[data-type="Final"]').textContent = final.toFixed(1);
            row.querySelector('td[data-type="Final"]').setAttribute('data-value', final);
            row.querySelector('td[data-type="Assignment"]').textContent = assignment.toFixed(1);
            row.querySelector('td[data-type="Assignment"]').setAttribute('data-value', assignment);
            row.querySelector('td[data-type="Quiz"]').textContent = quiz.toFixed(1);
            row.querySelector('td[data-type="Quiz"]').setAttribute('data-value', quiz);
            row.querySelector('td[data-type="Project"]').textContent = project.toFixed(1);
            row.querySelector('td[data-type="Project"]').setAttribute('data-value', project);

            const total = (midterm + final + assignment + quiz + project).toFixed(1);
            
            const totalCell = row.querySelector('td.total-grade');
            totalCell.textContent = total;
            totalCell.setAttribute('data-total', total);

            const totalNum = parseFloat(total);
            let letterGrade = 'N/A';
            if (totalNum >= 97) letterGrade = 'A+';
            else if (totalNum >= 93) letterGrade = 'A';
            else if (totalNum >= 90) letterGrade = 'A-';
            else if (totalNum >= 87) letterGrade = 'B+';
            else if (totalNum >= 83) letterGrade = 'B';
            else if (totalNum >= 80) letterGrade = 'B-';
            else if (totalNum >= 77) letterGrade = 'C+';
            else if (totalNum >= 73) letterGrade = 'C';
            else if (totalNum >= 70) letterGrade = 'C-';
            else if (totalNum > 0) letterGrade = 'F';
            
            const gradeCell = row.querySelector('td.grade-cell:last-of-type');
            if (gradeCell && gradeCell !== totalCell) {
                gradeCell.textContent = letterGrade;
                gradeCell.style.color = totalNum >= 90 ? '#6BCB77' : (totalNum >= 80 ? '#FFD93D' : '#FF6B9D');
            }
        }

        function saveGradeToDatabase(studentId, grades) {
            const gradesToSave = [];
            for (const [type, value] of Object.entries(grades)) {
                if (value > 0) {
                    gradesToSave.push({
                        studentId: studentId,
                        courseId: currentCourseId,
                        type: type.charAt(0).toUpperCase() + type.slice(1), 
                        value: value
                    });
                }
            }
            
            if (gradesToSave.length === 0) return;
            
            const formData = new FormData();
            formData.append('action', 'saveGrades');
            formData.append('grades', JSON.stringify(gradesToSave));
            
            fetch('grade-management-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Grade saved successfully!', 'success');
                } else {
                    showNotification(data.message || 'Error saving grade', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred while saving grade', 'error');
            });
        }

        function saveAllGrades() {
            if (!currentClassId || !currentCourseId) {
                showNotification('Please select a class and subject first', 'error');
                return;
            }

            const gradesToSave = [];
            for (const studentId in currentGrades) {
                if (currentGrades[studentId][currentCourseId]) {
                    for (const type in currentGrades[studentId][currentCourseId]) {
                        gradesToSave.push({
                            studentId: parseInt(studentId),
                            courseId: currentCourseId,
                            type: type,
                            value: currentGrades[studentId][currentCourseId][type]
                        });
                    }
                }
            }
            
            if (gradesToSave.length === 0) {
                showNotification('No grades to save', 'info');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'saveGrades');
            formData.append('grades', JSON.stringify(gradesToSave));
            
            fetch('grade-management-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('All grades saved successfully!', 'success');
                    
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
            } else {
                    showNotification(data.message || 'Error saving grades', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred while saving grades', 'error');
            });
        }

        function showNotification(message, type) {
            const container = document.getElementById('notificationContainer') || document.body;
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 1rem 1.5rem;
                background: ${type === 'success' ? '#6BCB77' : type === 'error' ? '#FF6B9D' : '#FFD93D'};
                color: white;
                border-radius: 10px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.2);
                z-index: 10000;
                animation: slideIn 0.3s ease;
            `;
            notification.textContent = message;
            container.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }
    </script>
</body>
</html>

