<?php

ob_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('admin');

ob_clean();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$action = $_POST['action'] ?? '';

if ($action === 'generateReport') {
    try {
        $reportType = $_POST['reportType'] ?? '';
        $grade = $_POST['grade'] ?? 'all';
        $section = $_POST['section'] ?? 'all';
        $startDate = $_POST['startDate'] ?? '';
        $endDate = $_POST['endDate'] ?? '';
        
        $response = [
            'success' => true,
            'reportData' => []
        ];

        $classWhere = '';
        $classParams = [];
        
        if ($grade !== 'all' || $section !== 'all') {
            $classConditions = [];
            if ($grade !== 'all') {
                $classConditions[] = 'c.Grade_Level = ?';
                $classParams[] = $grade;
            }
            if ($section !== 'all') {
                $classConditions[] = 'UPPER(c.Section) = UPPER(?)';
                $classParams[] = $section;
            }
            $classWhere = ' AND ' . implode(' AND ', $classConditions);
        }

        $dateWhere = '';
        if ($startDate && $endDate) {
            $dateWhere = ' AND a.Date BETWEEN ? AND ?';
        } elseif ($startDate) {
            $dateWhere = ' AND a.Date >= ?';
        } elseif ($endDate) {
            $dateWhere = ' AND a.Date <= ?';
        }
        
        if ($reportType === 'attendance') {
            $attendanceQuery = "
                SELECT 
                    a.Date,
                    COUNT(*) as total,
                    SUM(CASE WHEN a.Status = 'Present' THEN 1 ELSE 0 END) as present
                FROM attendance a
                INNER JOIN student s ON a.Student_ID = s.Student_ID
                INNER JOIN class c ON s.Class_ID = c.Class_ID
                WHERE 1=1
            ";
            
            $attendanceParams = [];
            
            if ($classWhere) {
                $attendanceQuery .= $classWhere;
                $attendanceParams = array_merge($attendanceParams, $classParams);
            }
            
            if ($dateWhere) {
                $attendanceQuery .= $dateWhere;
                if ($startDate && $endDate) {
                    $attendanceParams[] = $startDate;
                    $attendanceParams[] = $endDate;
                } elseif ($startDate) {
                    $attendanceParams[] = $startDate;
                } elseif ($endDate) {
                    $attendanceParams[] = $endDate;
                }
            } else {
                $attendanceQuery .= ' AND a.Date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
            }
            
            $attendanceQuery .= ' GROUP BY a.Date ORDER BY a.Date ASC';
            
            $stmt = $pdo->prepare($attendanceQuery);
            $stmt->execute($attendanceParams);
            $response['reportData']['attendanceData'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } elseif ($reportType === 'academic') {

            $gradesQuery = "
                SELECT DISTINCT Grade_Level
                FROM class
                WHERE Grade_Level IS NOT NULL
                ORDER BY Grade_Level ASC
            ";
            
            $stmt = $pdo->query($gradesQuery);
            $allGrades = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if ($grade !== 'all') {
                $allGrades = array_filter($allGrades, function($g) use ($grade) {
                    return $g == $grade;
                });
            }

            $academicData = [];
            
            foreach ($allGrades as $gradeLevel) {

                $materialsQuery = "
                    SELECT DISTINCT
                        co.Course_ID,
                        co.Course_Name as Material_Name,
                        co.Description,
                        COUNT(DISTINCT cc.Class_ID) as assigned_classes_count
                    FROM course co
                    LEFT JOIN course_class cc ON co.Course_ID = cc.Course_ID
                    LEFT JOIN class c ON cc.Class_ID = c.Class_ID
                    WHERE co.Grade_Level = ? OR (c.Grade_Level = ? AND cc.Class_ID IS NOT NULL)
                    GROUP BY co.Course_ID, co.Course_Name, co.Description
                    ORDER BY co.Course_Name
                ";
                
                $stmt = $pdo->prepare($materialsQuery);
                $stmt->execute([$gradeLevel, $gradeLevel]);
                $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $classesQuery = "
                    SELECT DISTINCT
                        c.Class_ID,
                        c.Name as Class_Name,
                        c.Section
                    FROM class c
                    WHERE c.Grade_Level = ?
                ";
                
                $classesParams = [$gradeLevel];
                if ($section !== 'all') {
                    $classesQuery .= ' AND UPPER(c.Section) = UPPER(?)';
                    $classesParams[] = $section;
                }
                
                $classesQuery .= ' ORDER BY c.Section';
                
                $stmt = $pdo->prepare($classesQuery);
                $stmt->execute($classesParams);
                $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $gradeMaterials = [];

                foreach ($materials as $material) {
                    $courseId = $material['Course_ID'];

                    $avgQuery = "
                        SELECT 
                            AVG(g.Value) as average_grade,
                            COUNT(DISTINCT g.Student_ID) as students_with_grades,
                            COUNT(g.Grade_ID) as total_grades,
                            COUNT(DISTINCT s.Class_ID) as classes_with_grades
                        FROM grade g
                        INNER JOIN student s ON g.Student_ID = s.Student_ID
                        INNER JOIN class c ON s.Class_ID = c.Class_ID
                        WHERE c.Grade_Level = ?
                        AND g.Course_ID = ?
                    ";
                    
                    $avgParams = [$gradeLevel, $courseId];

                    if ($startDate && $endDate) {
                        $avgQuery .= ' AND g.Date_Recorded BETWEEN ? AND ?';
                        $avgParams[] = $startDate;
                        $avgParams[] = $endDate;
                    } elseif ($startDate) {
                        $avgQuery .= ' AND g.Date_Recorded >= ?';
                        $avgParams[] = $startDate;
                    } elseif ($endDate) {
                        $avgQuery .= ' AND g.Date_Recorded <= ?';
                        $avgParams[] = $endDate;
                    }

                    if ($section !== 'all') {
                        $avgQuery .= ' AND UPPER(c.Section) = UPPER(?)';
                        $avgParams[] = $section;
                    }
                    
                    $stmt = $pdo->prepare($avgQuery);
                    $stmt->execute($avgParams);
                    $avgResult = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $gradeMaterials[] = [
                        'material_id' => $courseId,
                        'material_name' => $material['Material_Name'],
                        'description' => $material['Description'] ?? '',
                        'assigned_classes_count' => intval($material['assigned_classes_count']),
                        'average_grade' => $avgResult && $avgResult['average_grade'] !== null ? round(floatval($avgResult['average_grade']), 2) : null,
                        'students_with_grades' => $avgResult ? intval($avgResult['students_with_grades']) : 0,
                        'total_grades' => $avgResult ? intval($avgResult['total_grades']) : 0,
                        'has_grades' => $avgResult && $avgResult['total_grades'] > 0
                    ];
                }

                $academicData[] = [
                    'grade_level' => $gradeLevel,
                    'materials' => $gradeMaterials,
                    'has_materials' => !empty($gradeMaterials),
                    'materials_count' => count($gradeMaterials)
                ];
            }
            
            $response['reportData']['academicData'] = $academicData;
            
        } elseif ($reportType === 'financial') {
            
            $financialQuery = "
                SELECT 
                    i.Installment_Number,
                    COUNT(*) as total,
                    SUM(CASE WHEN i.Status = 'paid' THEN 1 ELSE 0 END) as paid,
                    SUM(CASE WHEN i.Status = 'unpaid' THEN 1 ELSE 0 END) as unpaid,
                    SUM(CASE WHEN i.Status = 'overdue' THEN 1 ELSE 0 END) as overdue,
                    SUM(i.Amount) as total_amount,
                    SUM(CASE WHEN i.Status = 'paid' THEN i.Amount ELSE 0 END) as paid_amount
                FROM installment i
                INNER JOIN student s ON i.Student_ID = s.Student_ID
                INNER JOIN class c ON s.Class_ID = c.Class_ID
                WHERE 1=1
            ";
            
            $financialParams = [];
            
            if ($classWhere) {
                $financialQuery .= $classWhere;
                $financialParams = array_merge($financialParams, $classParams);
            }
            
            if ($dateWhere) {
                $financialDateWhere = str_replace('a.Date', 'i.Due_Date', $dateWhere);
                $financialQuery .= $financialDateWhere;
                if ($startDate && $endDate) {
                    $financialParams[] = $startDate;
                    $financialParams[] = $endDate;
                } elseif ($startDate) {
                    $financialParams[] = $startDate;
                } elseif ($endDate) {
                    $financialParams[] = $endDate;
                }
            }
            
            $financialQuery .= ' GROUP BY i.Installment_Number ORDER BY i.Installment_Number';
            
            $stmt = $pdo->prepare($financialQuery);
            $stmt->execute($financialParams);
            $response['reportData']['financialData'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo json_encode($response);
        
    } catch (PDOException $e) {
        error_log("Error generating report: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error generating report: ' . $e->getMessage()]);
    }
} elseif ($action === 'getFilteredData') {
    try {
        $grade = $_POST['grade'] ?? 'all';
        $section = $_POST['section'] ?? 'all';
        $startDate = $_POST['startDate'] ?? '';
        $endDate = $_POST['endDate'] ?? '';
        $category = $_POST['category'] ?? 'all';
        
        $response = [
            'success' => true,
            'attendanceData' => [],
            'gradeData' => []
        ];

        $classWhere = '';
        $classParams = [];
        
        if ($grade !== 'all' || $section !== 'all') {
            $classConditions = [];
            if ($grade !== 'all') {
                $classConditions[] = 'c.Grade_Level = ?';
                $classParams[] = $grade;
            }
            if ($section !== 'all') {
                $classConditions[] = 'UPPER(c.Section) = UPPER(?)';
                $classParams[] = $section;
            }
            $classWhere = ' AND ' . implode(' AND ', $classConditions);
        }

        $dateWhere = '';
        if ($startDate && $endDate) {
            $dateWhere = ' AND a.Date BETWEEN ? AND ?';
        } elseif ($startDate) {
            $dateWhere = ' AND a.Date >= ?';
        } elseif ($endDate) {
            $dateWhere = ' AND a.Date <= ?';
        }

        if ($category === 'all' || $category === 'attendance') {
            $attendanceQuery = "
                SELECT 
                    a.Date,
                    COUNT(*) as total,
                    SUM(CASE WHEN a.Status = 'Present' THEN 1 ELSE 0 END) as present
                FROM attendance a
                INNER JOIN student s ON a.Student_ID = s.Student_ID
                INNER JOIN class c ON s.Class_ID = c.Class_ID
                WHERE 1=1
            ";
            
            $attendanceParams = [];
            
            if ($classWhere) {
                $attendanceQuery .= $classWhere;
                $attendanceParams = array_merge($attendanceParams, $classParams);
            }
            
            if ($dateWhere) {
                $attendanceQuery .= $dateWhere;
                if ($startDate && $endDate) {
                    $attendanceParams[] = $startDate;
                    $attendanceParams[] = $endDate;
                } elseif ($startDate) {
                    $attendanceParams[] = $startDate;
                } elseif ($endDate) {
                    $attendanceParams[] = $endDate;
                }
            } else {
                
                $attendanceQuery .= ' AND a.Date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
            }
            
            $attendanceQuery .= ' GROUP BY a.Date ORDER BY a.Date ASC';
            
            $stmt = $pdo->prepare($attendanceQuery);
            $stmt->execute($attendanceParams);
            $response['attendanceData'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($category === 'all' || $category === 'academic') {
            $gradeQuery = "
                SELECT 
                    CASE 
                        WHEN g.Value >= 90 THEN 'A (90-100)'
                        WHEN g.Value >= 80 THEN 'B (80-89)'
                        WHEN g.Value >= 70 THEN 'C (70-79)'
                        WHEN g.Value >= 60 THEN 'D (60-69)'
                        ELSE 'F (Below 60)'
                    END as grade_range,
                    COUNT(*) as count
                FROM grade g
                INNER JOIN student s ON g.Student_ID = s.Student_ID
                INNER JOIN class c ON s.Class_ID = c.Class_ID
                WHERE 1=1
            ";
            
            $gradeParams = [];
            
            if ($classWhere) {
                $gradeQuery .= $classWhere;
                $gradeParams = array_merge($gradeParams, $classParams);
            }
            
            if ($dateWhere) {
                $gradeDateWhere = str_replace('a.Date', 'g.Date_Recorded', $dateWhere);
                $gradeQuery .= $gradeDateWhere;
                if ($startDate && $endDate) {
                    $gradeParams[] = $startDate;
                    $gradeParams[] = $endDate;
                } elseif ($startDate) {
                    $gradeParams[] = $startDate;
                } elseif ($endDate) {
                    $gradeParams[] = $endDate;
                }
            }
            
            $gradeQuery .= ' GROUP BY grade_range ORDER BY MIN(g.Value) DESC';
            
            $stmt = $pdo->prepare($gradeQuery);
            $stmt->execute($gradeParams);
            $response['gradeData'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo json_encode($response);
        
    } catch (PDOException $e) {
        error_log("Error fetching filtered data: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error fetching data: ' . $e->getMessage()]);
    }
} elseif ($action === 'generateAdvancedReport') {
    try {
        $reportType = $_POST['reportType'] ?? '';

        error_log("generateAdvancedReport called with type: " . $reportType);
        
        if (empty($reportType)) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Report type is required']);
            exit();
        }
        
        $response = [
            'success' => true,
            'reportData' => []
        ];

        if ($reportType === 'attendance') {
            
            $classId = $_POST['classId'] ?? 'all';
            $startDate = $_POST['startDate'] ?? '';
            $endDate = $_POST['endDate'] ?? '';

            $query = "
                SELECT 
                    a.Attendance_ID,
                    a.Date as date,
                    a.Status as status,
                    a.Notes as notes,
                    s.Student_Code,
                    CONCAT(COALESCE(s.NameEn, ''), ' ', COALESCE(s.NameAr, '')) as student_name,
                    c.Name as class_name,
                    c.Grade_Level,
                    c.Section
                FROM attendance a
                INNER JOIN student s ON a.Student_ID = s.Student_ID
                INNER JOIN class c ON s.Class_ID = c.Class_ID
                WHERE 1=1
            ";

            $params = [];

            if ($classId !== 'all') {
                $query .= " AND c.Class_ID = ?";
                $params[] = $classId;
            }

            if ($startDate) {
                $query .= " AND a.Date >= ?";
                $params[] = $startDate;
            }

            if ($endDate) {
                $query .= " AND a.Date <= ?";
                $params[] = $endDate;
            }

            $query .= " ORDER BY a.Date DESC, s.NameEn ASC LIMIT 1000";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $response['reportData'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } elseif ($reportType === 'students') {
            
            $classId = $_POST['classId'] ?? 'all';
            $status = $_POST['status'] ?? 'all';

            $query = "
                SELECT 
                    s.Student_ID,
                    s.Student_Code as student_code,
                    CONCAT(COALESCE(s.NameEn, ''), ' ', COALESCE(s.NameAr, '')) as name,
                    c.Name as class_name,
                    c.Grade_Level,
                    c.Section as section,
                    s.Status as status,
                    s.Enrollment_Date as enrollment_date,
                    s.Email,
                    s.Phone
                FROM student s
                LEFT JOIN class c ON s.Class_ID = c.Class_ID
                WHERE 1=1
            ";

            $params = [];

            if ($classId !== 'all') {
                $query .= " AND c.Class_ID = ?";
                $params[] = $classId;
            }

            if ($status !== 'all') {
                $query .= " AND s.Status = ?";
                $params[] = $status;
            }

            $query .= " ORDER BY s.NameEn ASC";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $response['reportData'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } elseif ($reportType === 'parents') {
            
            $classId = $_POST['classId'] ?? 'all';

            $query = "
                SELECT 
                    p.Parent_ID,
                    CONCAT(COALESCE(p.NameEn, ''), ' ', COALESCE(p.NameAr, '')) as parent_name,
                    p.Phone as phone,
                    p.Email as email,
                    p.National_ID,
                    psr.Relationship_Type,
                    s.Student_Code,
                    CONCAT(COALESCE(s.NameEn, ''), ' ', COALESCE(s.NameAr, '')) as student_name,
                    c.Name as student_class,
                    c.Grade_Level,
                    c.Section
                FROM parent p
                INNER JOIN parent_student_relationship psr ON p.Parent_ID = psr.Parent_ID
                INNER JOIN student s ON psr.Student_ID = s.Student_ID
                LEFT JOIN class c ON s.Class_ID = c.Class_ID
                WHERE 1=1
            ";

            $params = [];

            if ($classId !== 'all') {
                $query .= " AND c.Class_ID = ?";
                $params[] = $classId;
            }

            $query .= " ORDER BY p.NameEn ASC, s.NameEn ASC";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $response['reportData'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } elseif ($reportType === 'teachers') {
            
            $status = $_POST['status'] ?? 'all';

            $query = "
                SELECT 
                    t.Teacher_ID as teacher_id,
                    CONCAT(COALESCE(t.NameEn, ''), ' ', COALESCE(t.NameAr, '')) as teacher_name,
                    t.Subject as subject,
                    t.Position,
                    t.Email,
                    t.Phone,
                    GROUP_CONCAT(DISTINCT CONCAT(c.Name, ' (', COALESCE(co.Course_Name, ''), ')') SEPARATOR ', ') as assigned_classes
                FROM teacher t
                LEFT JOIN teacher_class_course tcc ON t.Teacher_ID = tcc.Teacher_ID
                LEFT JOIN class c ON tcc.Class_ID = c.Class_ID
                LEFT JOIN course co ON tcc.Course_ID = co.Course_ID
                WHERE 1=1
            ";

            $params = [];

            if ($status !== 'all') {
                $query .= " AND t.Status = ?";
                $params[] = $status;
            }

            $query .= " GROUP BY t.Teacher_ID, t.NameEn, t.NameAr, t.Subject, t.Position, t.Email, t.Phone ORDER BY t.NameEn ASC";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $response['reportData'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } elseif ($reportType === 'timetables') {
            
            $classId = $_POST['classId'] ?? 'all';

            $query = "
                SELECT 
                    s.Schedule_ID,
                    s.Day_Of_Week as day,
                    s.Start_Time as start_time,
                    s.End_Time as end_time,
                    s.Subject as subject,
                    s.Room as room,
                    c.Class_ID as class_id,
                    c.Name as class_name,
                    c.Grade_Level,
                    c.Section,
                    co.Course_Name as course_name,
                    CONCAT(COALESCE(t.NameEn, ''), ' ', COALESCE(t.NameAr, '')) as teacher_name
                FROM schedule s
                INNER JOIN class c ON s.Class_ID = c.Class_ID
                LEFT JOIN course co ON s.Course_ID = co.Course_ID
                LEFT JOIN teacher t ON s.Teacher_ID = t.Teacher_ID
                WHERE s.Type = 'Class'
            ";

            $params = [];

            if ($classId !== 'all') {
                $query .= " AND c.Class_ID = ?";
                $params[] = $classId;
            }

            $query .= " ORDER BY c.Grade_Level, c.Section, 
                CASE s.Day_Of_Week
                    WHEN 'Sunday' THEN 1
                    WHEN 'Monday' THEN 2
                    WHEN 'Tuesday' THEN 3
                    WHEN 'Wednesday' THEN 4
                    WHEN 'Thursday' THEN 5
                    WHEN 'Friday' THEN 6
                    WHEN 'Saturday' THEN 7
                END,
                s.Start_Time ASC";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $response['reportData'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid report type: ' . htmlspecialchars($reportType)]);
            exit();
        }

        ob_clean(); 
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit();

    } catch (PDOException $e) {
        error_log("Error generating advanced report: " . $e->getMessage());
        ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Exception $e) {
        error_log("Error generating advanced report: " . $e->getMessage());
        ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit();
    }
} else {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit();
}
?>

