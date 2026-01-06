<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-parent.php';

requireUserType('parent');

$currentParentId = getCurrentUserId();
if (!$currentParentId) {
    header('Location: ../signin.php');
    exit();
}

$currentParent = getCurrentUserData($pdo);
if (!$currentParent) {
    header('Location: ../signin.php');
    exit();
}

if ($currentParent['Parent_ID'] != $currentParentId) {
    error_log("Security violation: Parent ID mismatch for user ID: $currentParentId");
    header('Location: ../signin.php');
    exit();
}

$parentName = $currentParent['NameEn'] ?? $currentParent['Name'] ?? $_SESSION['user_name'] ?? 'Parent';

$linkedStudents = [];
$selectedStudentId = isset($_GET['student_id']) ? intval($_GET['student_id']) : null;

if ($currentParentId) {
    try {
        
        $stmt = $pdo->prepare("
            SELECT psr.Student_ID, s.NameEn, s.NameAr, s.Student_Code, s.Class_ID, 
                   c.Name as ClassName, c.Grade_Level, c.Section,
                   psr.Relationship_Type, psr.Is_Primary
            FROM parent_student_relationship psr
            INNER JOIN student s ON psr.Student_ID = s.Student_ID
            LEFT JOIN class c ON s.Class_ID = c.Class_ID
            WHERE psr.Parent_ID = ?
            ORDER BY psr.Is_Primary DESC, s.NameEn ASC
        ");
        $stmt->execute([$currentParentId]);
        $linkedStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($selectedStudentId) {
            $validStudentIds = array_column($linkedStudents, 'Student_ID');
            if (!in_array($selectedStudentId, $validStudentIds)) {
                error_log("Security violation: Parent $currentParentId attempted to access grades for student $selectedStudentId");
                $selectedStudentId = null; 
            }
        }

        if (!$selectedStudentId && !empty($linkedStudents)) {
            $selectedStudentId = $linkedStudents[0]['Student_ID'];
        }
    } catch (PDOException $e) {
        error_log("Error fetching linked students: " . $e->getMessage());
        $linkedStudents = [];
    }
}

$coursesWithGrades = [];
$selectedStudentInfo = null;
$performanceSummary = [
    'overallAverage' => 0,
    'totalCourses' => 0,
    'excellentCount' => 0,
    'goodCount' => 0,
    'needsImprovement' => 0
];

if ($selectedStudentId) {
    
    foreach ($linkedStudents as $student) {
        if ($student['Student_ID'] == $selectedStudentId) {
            $selectedStudentInfo = $student;
            break;
        }
    }
    
    if ($selectedStudentInfo && $selectedStudentInfo['Class_ID']) {
        try {
            
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM parent_student_relationship
                WHERE Parent_ID = ? AND Student_ID = ?
            ");
            $stmt->execute([$currentParentId, $selectedStudentId]);
            $relationshipCheck = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($relationshipCheck && $relationshipCheck['count'] > 0) {
                
                $stmt = $pdo->prepare("
                    SELECT DISTINCT c.Course_ID, c.Course_Name, c.Description, c.Grade_Level
                    FROM course c
                    INNER JOIN course_class cc ON c.Course_ID = cc.Course_ID
                    WHERE cc.Class_ID = ?
                    ORDER BY c.Course_Name
                ");
                $stmt->execute([$selectedStudentInfo['Class_ID']]);
                $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($courses as $course) {
                    $courseId = $course['Course_ID'];

                    $stmt = $pdo->prepare("
                        SELECT Value, Type, Date_Recorded, Remarks
                        FROM grade
                        WHERE Student_ID = ? AND Course_ID = ?
                        ORDER BY Date_Recorded DESC
                    ");
                    $stmt->execute([$selectedStudentId, $courseId]);
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
                        $performanceSummary['excellentCount']++;
                    } elseif ($courseTotal >= 93) {
                        $letterGrade = 'A';
                        $performanceSummary['excellentCount']++;
                    } elseif ($courseTotal >= 90) {
                        $letterGrade = 'A-';
                        $performanceSummary['excellentCount']++;
                    } elseif ($courseTotal >= 87) {
                        $letterGrade = 'B+';
                        $performanceSummary['goodCount']++;
                    } elseif ($courseTotal >= 83) {
                        $letterGrade = 'B';
                        $performanceSummary['goodCount']++;
                    } elseif ($courseTotal >= 80) {
                        $letterGrade = 'B-';
                        $performanceSummary['goodCount']++;
                    } elseif ($courseTotal >= 77) {
                        $letterGrade = 'C+';
                        $performanceSummary['needsImprovement']++;
                    } elseif ($courseTotal >= 73) {
                        $letterGrade = 'C';
                        $performanceSummary['needsImprovement']++;
                    } elseif ($courseTotal >= 70) {
                        $letterGrade = 'C-';
                        $performanceSummary['needsImprovement']++;
                    } elseif ($courseTotal > 0) {
                        $letterGrade = 'F';
                        $performanceSummary['needsImprovement']++;
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
                        $performanceSummary['totalCourses']++;
                    }
                }

                if (!empty($coursesWithGrades)) {
                    $allTotals = array_filter(array_column($coursesWithGrades, 'Total'), function($t) { return $t > 0; });
                    if (!empty($allTotals)) {
                        $performanceSummary['overallAverage'] = round(array_sum($allTotals) / count($allTotals), 2);
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Error fetching grades for parent academic performance: " . $e->getMessage());
            $coursesWithGrades = [];
        }
    }
}

function getGradeLetter($average) {
    if ($average >= 97) return 'A+';
    if ($average >= 93) return 'A';
    if ($average >= 90) return 'A-';
    if ($average >= 87) return 'B+';
    if ($average >= 83) return 'B';
    if ($average >= 80) return 'B-';
    if ($average >= 77) return 'C+';
    if ($average >= 73) return 'C';
    if ($average >= 70) return 'C-';
    if ($average >= 67) return 'D+';
    if ($average >= 63) return 'D';
    if ($average >= 60) return 'D-';
    return 'F';
}

function getGradeClass($average) {
    if ($average >= 90) return 'grade-excellent';
    if ($average >= 70) return 'grade-good';
    return 'grade-poor';
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Performance - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>
    
    <div class="main-content">

    <div class="dashboard-container">
        
        <div class="page-header-section">
            <button class="btn-back" onclick="window.location.href='parent-dashboard.php'" title="Back to Dashboard">
                <i class="fas fa-arrow-left"></i>
                <span data-en="Back to Dashboard" data-ar="العودة إلى لوحة التحكم">Back to Dashboard</span>
            </button>
            <h1 class="page-title">
                <span class="page-icon">📈</span>
                <span data-en="Academic Performance" data-ar="الأداء الأكاديمي">Academic Performance</span>
            </h1>
            <p class="page-subtitle" data-en="View detailed academic performance and grades for all subjects" data-ar="عرض الأداء الأكاديمي التفصيلي والدرجات لجميع المواد">View detailed academic performance and grades for all subjects</p>
        </div>

        <?php if (count($linkedStudents) > 1): ?>
        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">👨‍🎓</span>
                    <span data-en="Select Student" data-ar="اختر الطالب">Select Student</span>
                </h2>
            </div>
            <div style="padding: 1rem;">
                <select class="form-group" style="width: 100%; padding: 1rem; border: 3px solid #FFE5E5; border-radius: 15px; font-size: 1rem; font-weight: 600;" onchange="window.location.href='?student_id=' + this.value;">
                    <?php foreach ($linkedStudents as $student): ?>
                        <option value="<?php echo $student['Student_ID']; ?>" <?php echo ($selectedStudentId == $student['Student_ID']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($student['NameEn']); ?> 
                            <?php if ($student['ClassName']): ?>
                                - <?php echo htmlspecialchars($student['ClassName']); ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($selectedStudentInfo): ?>
        <div class="card" style="margin-bottom: 1.5rem; background: linear-gradient(135deg, #FFE5E5 0%, #E5F3FF 100%);">
            <div style="padding: 1.5rem;">
                <div style="font-weight: 700; font-size: 1.2rem; margin-bottom: 0.5rem; color: var(--text-dark);" data-en="Student Information" data-ar="معلومات الطالب">Student Information</div>
                <div style="font-size: 1.5rem; color: var(--primary-color); font-weight: 800; margin-bottom: 0.5rem;">
                    <?php echo htmlspecialchars($selectedStudentInfo['NameEn']); ?>
                </div>
                <?php if ($selectedStudentInfo['ClassName']): ?>
                <div style="font-size: 1.1rem; color: #666;">
                    <?php echo htmlspecialchars($selectedStudentInfo['ClassName']); ?>
                </div>
                <?php endif; ?>
                <?php if ($performanceSummary['overallAverage'] > 0): ?>
                <div style="margin-top: 1rem; padding-top: 1rem; border-top: 2px solid rgba(255,107,157,0.3);">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-weight: 700;" data-en="Overall Average" data-ar="المعدل العام">Overall Average</span>
                        <span style="font-size: 1.8rem; font-weight: 800; color: var(--primary-color);">
                            <?php echo number_format($performanceSummary['overallAverage'], 2); ?>%
                        </span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📈</span>
                    <span data-en="Academic Performance" data-ar="الأداء الأكاديمي">Academic Performance</span>
                </h2>
            </div>
            <?php if (empty($linkedStudents)): ?>
                <div style="text-align: center; padding: 3rem; color: #666;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">👨‍👩‍👧</div>
                    <div style="font-weight: 700; margin-bottom: 0.5rem;" data-en="No Students Linked" data-ar="لا يوجد طلاب مرتبطين">No Students Linked</div>
                    <div style="font-size: 0.9rem;" data-en="You don't have any students linked to your account. Please contact the administration." data-ar="ليس لديك أي طلاب مرتبطين بحسابك. يرجى الاتصال بالإدارة.">You don't have any students linked to your account. Please contact the administration.</div>
                </div>
            <?php elseif (!$selectedStudentId): ?>
                <div style="text-align: center; padding: 3rem; color: #666;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">📈</div>
                    <div style="font-weight: 700; margin-bottom: 0.5rem;" data-en="No Student Selected" data-ar="لم يتم اختيار طالب">No Student Selected</div>
                    <div style="font-size: 0.9rem;" data-en="Please select a student to view their academic performance." data-ar="يرجى اختيار طالب لعرض أدائه الأكاديمي.">Please select a student to view their academic performance.</div>
                </div>
            <?php elseif (empty($coursesWithGrades)): ?>
                <div style="text-align: center; padding: 3rem; color: #666;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">📚</div>
                    <div style="font-weight: 700; margin-bottom: 0.5rem;" data-en="No Academic Performance Data Available" data-ar="لا توجد بيانات أداء أكاديمي متاحة">No Academic Performance Data Available</div>
                    <div style="font-size: 0.9rem;" data-en="No grades have been recorded for this student yet." data-ar="لم يتم تسجيل أي درجات لهذا الطالب بعد.">No grades have been recorded for this student yet.</div>
                </div>
            <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="grades-table" style="min-width: 800px;">
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
                    <?php if ($performanceSummary['overallAverage'] > 0): ?>
                    <tfoot>
                        <tr class="grades-summary-row" style="background: #FFF9F5;">
                            <td style="font-weight: 700; font-size: 1.1rem;" data-en="Overall Average" data-ar="المعدل العام">Overall Average</td>
                            <td colspan="5" style="text-align: right; font-weight: 700;" data-en="Based on <?php echo $performanceSummary['totalCourses']; ?> course(s)" data-ar="بناءً على <?php echo $performanceSummary['totalCourses']; ?> مقرر">Based on <?php echo $performanceSummary['totalCourses']; ?> course(s)</td>
                            <td class="grade-cell total-grade <?php echo getGradeClass($performanceSummary['overallAverage']); ?>" style="font-weight: 700; font-size: 1.3rem;">
                                <?php echo number_format($performanceSummary['overallAverage'], 2); ?>
                            </td>
                            <td class="grade-cell <?php echo getGradeClass($performanceSummary['overallAverage']); ?>" style="font-weight: 700; font-size: 1.2rem;">
                                <?php echo getGradeLetter($performanceSummary['overallAverage']); ?>
                            </td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($coursesWithGrades) && $selectedStudentId): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📊</span>
                    <span data-en="Progress Tracking" data-ar="تتبع التقدم">Progress Tracking</span>
                </h2>
            </div>
            <?php foreach ($coursesWithGrades as $course): ?>
                <?php 
                $total = $course['Total'];
                $percentage = min(100, max(0, $total)); 
                $gradeClass = getGradeClass($total);
                $emoji = '📚'; 
                $courseNameLower = strtolower($course['Course_Name']);
                if (strpos($courseNameLower, 'math') !== false || strpos($courseNameLower, 'رياض') !== false) {
                    $emoji = '🧮';
                } elseif (strpos($courseNameLower, 'scien') !== false || strpos($courseNameLower, 'علوم') !== false) {
                    $emoji = '🔬';
                } elseif (strpos($courseNameLower, 'english') !== false || strpos($courseNameLower, 'إنجليز') !== false) {
                    $emoji = '📚';
                } elseif (strpos($courseNameLower, 'arabic') !== false || strpos($courseNameLower, 'عربي') !== false) {
                    $emoji = '📖';
                } elseif (strpos($courseNameLower, 'social') !== false || strpos($courseNameLower, 'اجتماع') !== false) {
                    $emoji = '🌍';
                }
                ?>
                <div class="progress-item">
                    <div class="progress-label">
                        <span><?php echo $emoji; ?> <?php echo htmlspecialchars($course['Course_Name']); ?></span>
                        <span><?php echo number_format($total, 1); ?> / 100</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill <?php echo $gradeClass; ?>" style="width: <?php echo $percentage; ?>%">
                            <?php if ($percentage >= 10): ?>
                                <?php echo number_format($total, 1); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div id="profileModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <span onclick="closeProfileSettings()" style="float: right; font-size: 32px; cursor: pointer; color: #FF6B9D; font-weight: bold;">&times;</span>
            <h2 data-en="Profile Settings" data-ar="إعدادات الملف الشخصي">Profile Settings</h2>
            <form onsubmit="handleProfileUpdate(event)">
                <div class="form-group">
                    <label data-en="Phone Number" data-ar="رقم الهاتف">Phone Number</label>
                    <input type="tel" value="+962 7XX XXX XXX" required>
                </div>
                <div class="form-group">
                    <label data-en="Email" data-ar="البريد الإلكتروني">Email</label>
                    <input type="email" value="parent@example.com" required>
                </div>
                <div class="form-group">
                    <label data-en="Address" data-ar="العنوان">Address</label>
                    <textarea rows="3">Amman, Jordan</textarea>
                </div>
                <div class="form-group">
                    <label data-en="Change Password" data-ar="تغيير كلمة المرور">Change Password</label>
                    <input type="password" placeholder="Enter new password">
                </div>
                <button type="submit" class="btn btn-primary" data-en="Save Changes" data-ar="حفظ التغييرات">Save Changes</button>
            </form>
        </div>
    </div>

    <div id="settingsModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <span onclick="closeSettings()" style="float: right; font-size: 32px; cursor: pointer; color: #FF6B9D; font-weight: bold;">&times;</span>
            <h2 data-en="Program Settings" data-ar="إعدادات البرنامج">Program Settings</h2>
            <div class="settings-section">
                <h3 data-en="Notifications" data-ar="الإشعارات">Notifications</h3>
                <div class="setting-item">
                    <label>
                        <input type="checkbox" checked onchange="toggleSetting('email')">
                        <span data-en="Email Notifications" data-ar="إشعارات البريد الإلكتروني">Email Notifications</span>
                    </label>
                </div>
                <div class="setting-item">
                    <label>
                        <input type="checkbox" checked onchange="toggleSetting('assignments')">
                        <span data-en="Assignment Reminders" data-ar="تذكيرات الواجبات">Assignment Reminders</span>
                    </label>
                </div>
                <div class="setting-item">
                    <label>
                        <input type="checkbox" checked onchange="toggleSetting('grades')">
                        <span data-en="Grade Updates" data-ar="تحديثات الدرجات">Grade Updates</span>
                    </label>
                </div>
                <div class="setting-item">
                    <label>
                        <input type="checkbox" checked onchange="toggleSetting('messages')">
                        <span data-en="Teacher Messages" data-ar="رسائل المعلم">Teacher Messages</span>
                    </label>
                </div>
            </div>
            <div class="settings-section">
                <h3 data-en="Appearance" data-ar="المظهر">Appearance</h3>
                <div class="setting-item">
                    <label data-en="Theme Color" data-ar="لون المظهر">Theme Color</label>
                    <input type="color" value="#FF6B9D" onchange="changeTheme(this.value)">
                </div>
                <div class="setting-item">
                    <label>
                        <input type="checkbox" onchange="toggleSetting('darkMode')">
                        <span data-en="Dark Mode" data-ar="الوضع الداكن">Dark Mode</span>
                    </label>
                </div>
            </div>
            <button onclick="saveSettings()" class="btn btn-primary" data-en="Save Settings" data-ar="حفظ الإعدادات">Save Settings</button>
        </div>
    </div>

    <script src="script.js"></script>
</body>
</html>

