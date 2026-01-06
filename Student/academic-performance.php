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

$coursesWithGrades = [];
$overallAverage = 0;
$totalSubjects = 0;
$excellentGrades = 0; 
$goodGrades = 0; 
$averageGrades = 0; 
$gradeDistribution = ['A' => 0, 'B' => 0, 'C' => 0];

if ($currentStudentClassId) {
    try {
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.Course_ID, c.Course_Name, c.Description, c.Grade_Level
            FROM course c
            INNER JOIN course_class cc ON c.Course_ID = cc.Course_ID
            WHERE cc.Class_ID = ?
            ORDER BY c.Course_Name
        ");
        $stmt->execute([$currentStudentClassId]);
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $totalSubjects = count($courses);
        $allGrades = [];

            foreach ($courses as $course) {
                $courseId = $course['Course_ID'];

                $stmt = $pdo->prepare("
                    SELECT Value, Type, Date_Recorded, Remarks
                    FROM grade
                    WHERE Student_ID = ? AND Course_ID = ?
                    ORDER BY Date_Recorded DESC
                ");
                $stmt->execute([$currentStudentId, $courseId]);
                $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $gradeByType = [
                    'Midterm' => 0,
                    'Final' => 0,
                    'Assignment' => 0,
                    'Quiz' => 0,
                    'Project' => 0
                ];
                
                foreach ($grades as $grade) {
                    $type = $grade['Type'];
                    if (isset($gradeByType[$type])) {
                        
                        if ($gradeByType[$type] == 0) {
                            $gradeByType[$type] = floatval($grade['Value']);
                        }
                    }
                }

                $midterm = min(30, $gradeByType['Midterm']);
                $final = min(40, $gradeByType['Final']);
                $assignment = min(10, $gradeByType['Assignment']);
                $quiz = min(10, $gradeByType['Quiz']);
                $project = min(10, $gradeByType['Project']);

                $courseTotal = round($midterm + $final + $assignment + $quiz + $project, 1);

                $letterGrade = 'N/A';
                if ($courseTotal >= 97) {
                    $letterGrade = 'A+';
                    $excellentGrades++;
                    $gradeDistribution['A']++;
                } elseif ($courseTotal >= 93) {
                    $letterGrade = 'A';
                    $excellentGrades++;
                    $gradeDistribution['A']++;
                } elseif ($courseTotal >= 90) {
                    $letterGrade = 'A-';
                    $excellentGrades++;
                    $gradeDistribution['A']++;
                } elseif ($courseTotal >= 87) {
                    $letterGrade = 'B+';
                    $goodGrades++;
                    $gradeDistribution['B']++;
                } elseif ($courseTotal >= 83) {
                    $letterGrade = 'B';
                    $goodGrades++;
                    $gradeDistribution['B']++;
                } elseif ($courseTotal >= 80) {
                    $letterGrade = 'B-';
                    $goodGrades++;
                    $gradeDistribution['B']++;
                } elseif ($courseTotal >= 77) {
                    $letterGrade = 'C+';
                    $averageGrades++;
                    $gradeDistribution['C']++;
                } elseif ($courseTotal >= 73) {
                    $letterGrade = 'C';
                    $averageGrades++;
                    $gradeDistribution['C']++;
                } elseif ($courseTotal >= 70) {
                    $letterGrade = 'C-';
                    $averageGrades++;
                    $gradeDistribution['C']++;
                } elseif ($courseTotal > 0) {
                    $letterGrade = 'F';
                    $averageGrades++;
                    $gradeDistribution['C']++;
                }
                
                $coursesWithGrades[] = [
                    'Course_ID' => $courseId,
                    'Course_Name' => $course['Course_Name'],
                    'Description' => $course['Description'],
                    'Grade_Level' => $course['Grade_Level'],
                    'Midterm' => $midterm,
                    'Final' => $final,
                    'Assignment' => $assignment,
                    'Quiz' => $quiz,
                    'Project' => $project,
                    'Total' => $courseTotal,
                    'LetterGrade' => $letterGrade,
                    'GradesByType' => [
                        'Midterm' => $midterm,
                        'Final' => $final,
                        'Assignment' => $assignment,
                        'Quiz' => $quiz,
                        'Project' => $project
                    ],
                    'AllGrades' => $grades
                ];
                
                if ($courseTotal > 0) {
                    $allGrades[] = $courseTotal;
                }
            }

            if (!empty($allGrades)) {
                $overallAverage = round(array_sum($allGrades) / count($allGrades), 2);
            }
        
    } catch (PDOException $e) {
        error_log("Error fetching courses and grades: " . $e->getMessage());
    }
}

$overallLetterGrade = 'N/A';
if ($overallAverage >= 97) {
    $overallLetterGrade = 'A+';
} elseif ($overallAverage >= 93) {
    $overallLetterGrade = 'A';
} elseif ($overallAverage >= 90) {
    $overallLetterGrade = 'A-';
} elseif ($overallAverage >= 87) {
    $overallLetterGrade = 'B+';
} elseif ($overallAverage >= 83) {
    $overallLetterGrade = 'B';
} elseif ($overallAverage >= 80) {
    $overallLetterGrade = 'B-';
} elseif ($overallAverage >= 77) {
    $overallLetterGrade = 'C+';
} elseif ($overallAverage >= 73) {
    $overallLetterGrade = 'C';
} elseif ($overallAverage >= 70) {
    $overallLetterGrade = 'C-';
} elseif ($overallAverage > 0) {
    $overallLetterGrade = 'F';
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Performance - HUDURY</title>
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
        .performance-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .summary-card {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 2rem;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(255, 107, 157, 0.3);
        }
        .summary-card.overall {
            background: linear-gradient(135deg, #6BCB77, #4ECDC4);
        }
        .summary-card.excellent {
            background: linear-gradient(135deg, #FFD93D, #FFB84D);
        }
        .summary-value {
            font-size: 3rem;
            font-weight: 800;
            margin: 0.5rem 0;
        }
        .summary-label {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        .progress-item {
            margin-bottom: 2rem;
        }
        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-weight: 700;
        }
        .progress-bar {
            height: 30px;
            background: #FFE5E5;
            border-radius: 15px;
            overflow: hidden;
            position: relative;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            border-radius: 15px;
            transition: width 1s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>
    
    <div class="main-content">

    <div class="dashboard-container">
        
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">📈</span>
                <span data-en="Academic Performance" data-ar="الأداء الأكاديمي">Academic Performance</span>
            </h1>
            <p class="page-subtitle" data-en="Track your grades and academic progress across all subjects" data-ar="تتبع درجاتك والتقدم الأكاديمي في جميع المواد">Track your grades and academic progress across all subjects</p>
        </div>

        <div class="performance-summary">
            <div class="summary-card overall">
                <div class="summary-label" data-en="Overall Average" data-ar="المعدل العام">Overall Average</div>
                <div class="summary-value"><?php echo $overallAverage > 0 ? number_format($overallAverage, 1) . '%' : '0%'; ?></div>
                <div class="summary-label" data-en="Grade: <?php echo $overallLetterGrade; ?>" data-ar="الدرجة: <?php echo $overallLetterGrade; ?>">Grade: <?php echo $overallLetterGrade; ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-label" data-en="Total Subjects" data-ar="إجمالي المواد">Total Subjects</div>
                <div class="summary-value"><?php echo $totalSubjects; ?></div>
                <div class="summary-label" data-en="All Active" data-ar="جميعها نشطة">All Active</div>
            </div>
            <div class="summary-card excellent">
                <div class="summary-label" data-en="Excellent Grades" data-ar="الدرجات الممتازة">Excellent Grades</div>
                <div class="summary-value"><?php echo $excellentGrades; ?></div>
                <div class="summary-label" data-en="A & A+" data-ar="A و A+">A & A+</div>
            </div>
            <div class="summary-card">
                <div class="summary-label" data-en="Good Grades" data-ar="الدرجات الجيدة">Good Grades</div>
                <div class="summary-value"><?php echo $goodGrades; ?></div>
                <div class="summary-label" data-en="B & B+" data-ar="B و B+">B & B+</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📊</span>
                    <span data-en="Detailed Grades" data-ar="الدرجات التفصيلية">Detailed Grades</span>
                </h2>
            </div>
            <div style="overflow-x: auto;">
                <?php if (empty($coursesWithGrades)): ?>
                    <div style="text-align: center; padding: 3rem; color: #999;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">📚</div>
                        <div data-en="No courses assigned to your class yet" data-ar="لم يتم تعيين مقررات لفصلك بعد">No courses assigned to your class yet</div>
                    </div>
                <?php else: ?>
                        <table class="grades-table">
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
                                <?php foreach ($coursesWithGrades as $course): 
                                    $total = $course['Total'];
                                    $letterGrade = $course['LetterGrade'];
                                    $gradesByType = $course['GradesByType'];

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
                                        <td class="grade-cell" style="background: #FFF9E5;"><?php echo number_format($gradesByType['Midterm'], 1); ?></td>
                                        <td class="grade-cell" style="background: #FFE5E5;"><?php echo number_format($gradesByType['Final'], 1); ?></td>
                                        <td class="grade-cell" style="background: #E5F3FF;"><?php echo number_format($gradesByType['Assignment'], 1); ?></td>
                                        <td class="grade-cell" style="background: #E5FFE5;"><?php echo number_format($gradesByType['Quiz'], 1); ?></td>
                                        <td class="grade-cell" style="background: #F5E5FF;"><?php echo number_format($gradesByType['Project'], 1); ?></td>
                                        <td class="grade-cell total-grade <?php echo $gradeClass; ?>" style="font-weight: 700; font-size: 1.1rem; background: #E5F3FF;"><?php echo number_format($total, 1); ?></td>
                                        <td class="grade-cell <?php echo $gradeClass; ?>" style="font-weight: 700; color: <?php echo $total >= 90 ? '#6BCB77' : ($total >= 80 ? '#FFD93D' : '#FF6B9D'); ?>;"><?php echo $letterGrade; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📊</span>
                    <span data-en="Subject Progress" data-ar="تقدم المواد">Subject Progress</span>
                </h2>
            </div>
            <?php if (empty($coursesWithGrades)): ?>
                <div style="text-align: center; padding: 2rem; color: #999;" data-en="No progress data available" data-ar="لا توجد بيانات تقدم متاحة">No progress data available</div>
            <?php else: ?>
                <?php foreach ($coursesWithGrades as $course): 
                    $total = $course['Total'];
                    $courseName = htmlspecialchars($course['Course_Name']);
                    $progressPercent = min(100, max(0, $total)); 
                ?>
                    <div class="progress-item">
                        <div class="progress-label">
                            <span><?php echo $courseName; ?></span>
                            <span><?php echo number_format($total, 1); ?> / 100</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $progressPercent; ?>%" data-percent="<?php echo $progressPercent; ?>"><?php echo number_format($progressPercent, 1); ?>%</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📈</span>
                    <span data-en="Grade Distribution" data-ar="توزيع الدرجات">Grade Distribution</span>
                </h2>
            </div>
            <div style="padding: 2rem;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1.5rem;">
                    <div style="text-align: center; padding: 1.5rem; background: #FFF9F5; border-radius: 15px;">
                        <div style="font-size: 2.5rem; font-weight: 800; color: #6BCB77;"><?php echo $gradeDistribution['A']; ?></div>
                        <div style="font-weight: 700; margin-top: 0.5rem;" data-en="A & A+" data-ar="A و A+">A & A+</div>
                    </div>
                    <div style="text-align: center; padding: 1.5rem; background: #FFF9F5; border-radius: 15px;">
                        <div style="font-size: 2.5rem; font-weight: 800; color: #FFD93D;"><?php echo $gradeDistribution['B']; ?></div>
                        <div style="font-weight: 700; margin-top: 0.5rem;" data-en="B & B+" data-ar="B و B+">B & B+</div>
                    </div>
                    <div style="text-align: center; padding: 1.5rem; background: #FFF9F5; border-radius: 15px;">
                        <div style="font-size: 2.5rem; font-weight: 800; color: #FF6B9D;"><?php echo $gradeDistribution['C']; ?></div>
                        <div style="font-weight: 700; margin-top: 0.5rem;" data-en="C & Below" data-ar="C وأقل">C & Below</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        
        window.addEventListener('load', () => {
            document.querySelectorAll('.progress-fill').forEach(bar => {
                const percent = bar.getAttribute('data-percent') || bar.textContent.replace('%', '');
                const targetWidth = parseFloat(percent) || 0;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = targetWidth + '%';
                }, 100);
            });
        });
    </script>
</body>
</html>

