<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('admin');

$stats = [
    'totalStudents' => 0,
    'totalTeachers' => 0,
    'totalParents' => 0,
    'attendanceRate' => 0,
    'activeAssignments' => 0,
    'averageGrade' => 0
];

try {
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM student");
    $stats['totalStudents'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM teacher");
    $stats['totalTeachers'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM parent");
    $stats['totalParents'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN Status = 'Present' THEN 1 ELSE 0 END) as present
        FROM attendance
    ");
    $attendanceData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($attendanceData && $attendanceData['total'] > 0) {
        $stats['attendanceRate'] = round(($attendanceData['present'] / $attendanceData['total']) * 100, 1);
    } else {
        $stats['attendanceRate'] = 0;
    }

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM assignment WHERE Status = 'active'");
    $stats['activeAssignments'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

    $stmt = $pdo->query("SELECT AVG(Value) as average FROM grade");
    $gradeData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($gradeData && $gradeData['average'] !== null) {
        $stats['averageGrade'] = round(floatval($gradeData['average']), 1);
    } else {
        $stats['averageGrade'] = 0;
    }

    $classes = [];
    $stmt = $pdo->query("
        SELECT DISTINCT Grade_Level, Section 
        FROM class 
        ORDER BY Grade_Level, Section
    ");
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $attendanceTrendData = [];
    $stmt = $pdo->query("
        SELECT 
            Date,
            COUNT(*) as total,
            SUM(CASE WHEN Status = 'Present' THEN 1 ELSE 0 END) as present
        FROM attendance
        WHERE Date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY Date
        ORDER BY Date ASC
    ");
    $attendanceTrendData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $gradeDistributionData = [];
    $stmt = $pdo->query("
        SELECT 
            CASE 
                WHEN Value >= 90 THEN 'A (90-100)'
                WHEN Value >= 80 THEN 'B (80-89)'
                WHEN Value >= 70 THEN 'C (70-79)'
                WHEN Value >= 60 THEN 'D (60-69)'
                ELSE 'F (Below 60)'
            END as grade_range,
            COUNT(*) as count
        FROM grade
        GROUP BY grade_range
        ORDER BY MIN(Value) DESC
    ");
    $gradeDistributionData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching statistics: " . $e->getMessage());
    $classes = [];
    $attendanceTrendData = [];
    $gradeDistributionData = [];
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: linear-gradient(135deg, #FFE5E5, #E5F3FF);
            padding: 2rem;
            border-radius: 20px;
            border: 3px solid #FFE5E5;
            text-align: center;
            transition: all 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            border-color: #FF6B9D;
        }
        .stat-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--primary-color);
            margin: 0.5rem 0;
        }
        .stat-label {
            color: #666;
            font-weight: 600;
            font-size: 1.1rem;
        }
        .chart-container {
            background: white;
            padding: 2rem;
            border-radius: 20px;
            margin-bottom: 1.5rem;
            border: 3px solid #FFE5E5;
        }
        .chart-placeholder {
            height: 300px;
            background: linear-gradient(135deg, #FFE5E5, #E5F3FF);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            font-size: 1.2rem;
        }
        .chart-wrapper {
            position: relative;
            height: 300px;
            width: 100%;
        }
    </style>
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">📈</span>
                <span data-en="Reports & Analytics" data-ar="التقارير والتحليلات">Reports & Analytics</span>
            </h1>
            <p class="page-subtitle" data-en="Comprehensive analytics and insights for your school" data-ar="تحليلات ورؤى شاملة لمدرستك">Comprehensive analytics and insights for your school</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-value"><?php echo number_format($stats['totalStudents']); ?></div>
                <div class="stat-label" data-en="Total Students" data-ar="إجمالي الطلاب">Total Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">👩‍🏫</div>
                <div class="stat-value"><?php echo number_format($stats['totalTeachers']); ?></div>
                <div class="stat-label" data-en="Teachers" data-ar="المعلمون">Teachers</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">👨‍👩‍👧</div>
                <div class="stat-value"><?php echo number_format($stats['totalParents']); ?></div>
                <div class="stat-label" data-en="Parents" data-ar="أولياء الأمور">Parents</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">✅</div>
                <div class="stat-value"><?php echo $stats['attendanceRate']; ?>%</div>
                <div class="stat-label" data-en="Attendance Rate" data-ar="معدل الحضور">Attendance Rate</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📝</div>
                <div class="stat-value"><?php echo number_format($stats['activeAssignments']); ?></div>
                <div class="stat-label" data-en="Active Assignments" data-ar="الواجبات النشطة">Active Assignments</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⭐</div>
                <div class="stat-value"><?php echo $stats['averageGrade']; ?>%</div>
                <div class="stat-label" data-en="Average Grade" data-ar="متوسط الدرجات">Average Grade</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📊</span>
                    <span data-en="Attendance Trends" data-ar="اتجاهات الحضور">Attendance Trends</span>
                </h2>
            </div>
            <div class="chart-container">
                <div class="chart-wrapper">
                    <canvas id="attendanceTrendChart"></canvas>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📈</span>
                    <span data-en="Academic Performance" data-ar="الأداء الأكاديمي">Academic Performance</span>
                </h2>
            </div>
            <div class="chart-container">
                <div class="chart-wrapper">
                    <canvas id="gradeDistributionChart"></canvas>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">🔍</span>
                    <span data-en="Report Filters" data-ar="مرشحات التقرير">Report Filters</span>
                </h2>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label data-en="Report Category" data-ar="فئة التقرير">Report Category</label>
                    <select id="reportCategory">
                        <option value="all" data-en="All Categories" data-ar="جميع الفئات">All Categories</option>
                        <option value="attendance" data-en="Attendance" data-ar="الحضور">Attendance</option>
                        <option value="academic" data-en="Academic" data-ar="أكاديمي">Academic</option>
                        <option value="financial" data-en="Financial" data-ar="مالي">Financial</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label data-en="Grade" data-ar="الصف">Grade</label>
                    <select id="reportGrade">
                        <option value="all" data-en="All Grades" data-ar="جميع الصفوف">All Grades</option>
                        <?php
                        $uniqueGrades = [];
                        foreach ($classes as $class) {
                            if (!in_array($class['Grade_Level'], $uniqueGrades)) {
                                $uniqueGrades[] = $class['Grade_Level'];
                            }
                        }
                        sort($uniqueGrades);
                        foreach ($uniqueGrades as $grade): ?>
                            <option value="<?php echo $grade; ?>" data-en="Grade <?php echo $grade; ?>" data-ar="الصف <?php echo $grade; ?>">Grade <?php echo $grade; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label data-en="Section" data-ar="القسم">Section</label>
                    <select id="reportSection">
                        <option value="all" data-en="All Sections" data-ar="جميع الأقسام">All Sections</option>
                        <?php
                        $uniqueSections = [];
                        foreach ($classes as $class) {
                            if (!empty($class['Section']) && !in_array($class['Section'], $uniqueSections)) {
                                $uniqueSections[] = $class['Section'];
                            }
                        }
                        sort($uniqueSections);
                        foreach ($uniqueSections as $section): ?>
                            <option value="<?php echo strtolower($section); ?>" data-en="Section <?php echo $section; ?>" data-ar="القسم <?php echo $section; ?>">Section <?php echo $section; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label data-en="Date Range" data-ar="نطاق التاريخ">Date Range</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                        <input type="date" id="reportStartDate">
                        <input type="date" id="reportEndDate">
                    </div>
                </div>
            </div>
            <div class="action-buttons" style="margin-top: 1rem;">
                <button class="btn btn-primary" onclick="applyFilters()" data-en="Apply Filters" data-ar="تطبيق المرشحات">Apply Filters</button>
                <button class="btn btn-secondary" onclick="resetFilters()" data-en="Reset" data-ar="إعادة تعيين">Reset</button>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📋</span>
                    <span data-en="Available Reports" data-ar="التقارير المتاحة">Available Reports</span>
                </h2>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem;">
                <button class="btn btn-primary" style="padding: 1.5rem; text-align: left;" onclick="generateReport('attendance')">
                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">✅</div>
                    <div style="font-weight: 700; margin-bottom: 0.3rem;" data-en="Attendance Report" data-ar="تقرير الحضور">Attendance Report</div>
                    <div style="font-size: 0.9rem; opacity: 0.8;" data-en="Generate comprehensive attendance report" data-ar="إنشاء تقرير حضور شامل">Generate comprehensive attendance report</div>
                </button>
                <button class="btn btn-primary" style="padding: 1.5rem; text-align: left;" onclick="generateReport('academic')">
                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">📊</div>
                    <div style="font-weight: 700; margin-bottom: 0.3rem;" data-en="Academic Report" data-ar="تقرير أكاديمي">Academic Report</div>
                    <div style="font-size: 0.9rem; opacity: 0.8;" data-en="Student performance and grades" data-ar="أداء ودرجات الطلاب">Student performance and grades</div>
                </button>
                <button class="btn btn-primary" style="padding: 1.5rem; text-align: left;" onclick="generateReport('financial')">
                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">💰</div>
                    <div style="font-weight: 700; margin-bottom: 0.3rem;" data-en="Financial Report" data-ar="تقرير مالي">Financial Report</div>
                    <div style="font-size: 0.9rem; opacity: 0.8;" data-en="School finances and payments" data-ar="المالية والمدفوعات">School finances and payments</div>
                </button>
            </div>
        </div>

        <div class="card" id="generatedReportsCard" style="display: none;">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📄</span>
                    <span data-en="Generated Reports" data-ar="التقارير المولدة">Generated Reports</span>
                </h2>
                <button class="btn btn-secondary btn-small" onclick="exportAllReports()" data-en="Export All" data-ar="تصدير الكل">Export All</button>
            </div>
            <div id="generatedReportsList" class="user-list">
                
            </div>
        </div>

        <div style="text-align: center; margin: 2rem 0;">
            <button class="btn btn-secondary" id="viewMoreBtn" onclick="toggleAdvancedReports()" style="padding: 1rem 2rem; font-size: 1.1rem;">
                <i class="fas fa-chevron-down" id="viewMoreIcon" style="margin-right: 0.5rem; transition: transform 0.3s;"></i>
                <span data-en="View More" data-ar="عرض المزيد">View More</span>
            </button>
        </div>

        <div id="advancedReportsSection" style="display: none; animation: slideDown 0.3s ease-out;">
            <div class="card" style="margin-bottom: 1.5rem; background: linear-gradient(135deg, #FFF9F5, #E5F3FF); border: 3px solid #FFE5E5;">
                <div class="card-header">
                    <h2 class="card-title">
                        <span class="card-icon">🔍</span>
                        <span data-en="Advanced Reports" data-ar="التقارير المتقدمة">Advanced Reports</span>
                    </h2>
                    <p style="margin-top: 0.5rem; color: #666; font-size: 0.95rem;" data-en="Generate detailed reports for administrative purposes. All data is read-only and excludes sensitive information." data-ar="إنشاء تقارير مفصلة للأغراض الإدارية. جميع البيانات للقراءة فقط ولا تشمل المعلومات الحساسة.">Generate detailed reports for administrative purposes. All data is read-only and excludes sensitive information.</p>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
                
                <div class="card" style="border: 2px solid #FFE5E5; transition: all 0.3s;" onmouseover="this.style.borderColor='#FF6B9D'; this.style.transform='translateY(-5px)'" onmouseout="this.style.borderColor='#FFE5E5'; this.style.transform='translateY(0)'">
                    <div style="text-align: center; padding: 1.5rem;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">✅</div>
                        <h3 style="margin-bottom: 0.5rem; color: var(--primary-color);" data-en="Attendance Report" data-ar="تقرير الحضور">Attendance Report</h3>
                        <p style="color: #666; font-size: 0.9rem; margin-bottom: 1.5rem;" data-en="Track attendance and absences with detailed student information" data-ar="تتبع الحضور والغياب مع معلومات الطلاب التفصيلية">Track attendance and absences with detailed student information</p>
                        <div class="form-group" style="margin-bottom: 1rem;">
                            <label style="font-size: 0.85rem;" data-en="Filter by Class" data-ar="تصفية حسب الفصل">Filter by Class</label>
                            <select id="attendanceReportClass" style="width: 100%;">
                                <option value="all" data-en="All Classes" data-ar="جميع الفصول">All Classes</option>
                                <?php
                                $stmt = $pdo->query("SELECT Class_ID, Name, Grade_Level, Section FROM class ORDER BY Grade_Level, Section");
                                $allClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($allClasses as $class): ?>
                                    <option value="<?php echo $class['Class_ID']; ?>"><?php echo htmlspecialchars($class['Name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 1rem;">
                            <label style="font-size: 0.85rem;" data-en="Date Range" data-ar="نطاق التاريخ">Date Range</label>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                                <input type="date" id="attendanceReportStartDate" style="width: 100%;">
                                <input type="date" id="attendanceReportEndDate" style="width: 100%;">
                            </div>
                        </div>
                        <button class="btn btn-primary" onclick="generateAdvancedReport('attendance')" style="width: 100%;" data-en="Generate Report" data-ar="إنشاء التقرير">Generate Report</button>
                    </div>
                    <div id="attendanceReportResult" style="display: none; padding: 1rem; border-top: 2px solid #FFE5E5; max-height: 400px; overflow-y: auto;"></div>
                </div>

                <div class="card" style="border: 2px solid #FFE5E5; transition: all 0.3s;" onmouseover="this.style.borderColor='#FF6B9D'; this.style.transform='translateY(-5px)'" onmouseout="this.style.borderColor='#FFE5E5'; this.style.transform='translateY(0)'">
                    <div style="text-align: center; padding: 1.5rem;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">👨‍🎓</div>
                        <h3 style="margin-bottom: 0.5rem; color: var(--primary-color);" data-en="Students Report" data-ar="تقرير الطلاب">Students Report</h3>
                        <p style="color: #666; font-size: 0.9rem; margin-bottom: 1.5rem;" data-en="View student names, IDs, classes, and enrollment status" data-ar="عرض أسماء الطلاب وأرقامهم وفصولهم وحالة التسجيل">View student names, IDs, classes, and enrollment status</p>
                        <div class="form-group" style="margin-bottom: 1rem;">
                            <label style="font-size: 0.85rem;" data-en="Filter by Class" data-ar="تصفية حسب الفصل">Filter by Class</label>
                            <select id="studentsReportClass" style="width: 100%;">
                                <option value="all" data-en="All Classes" data-ar="جميع الفصول">All Classes</option>
                                <?php foreach ($allClasses as $class): ?>
                                    <option value="<?php echo $class['Class_ID']; ?>"><?php echo htmlspecialchars($class['Name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 1rem;">
                            <label style="font-size: 0.85rem;" data-en="Status" data-ar="الحالة">Status</label>
                            <select id="studentsReportStatus" style="width: 100%;">
                                <option value="all" data-en="All Status" data-ar="جميع الحالات">All Status</option>
                                <option value="active" data-en="Active" data-ar="نشط">Active</option>
                                <option value="inactive" data-en="Inactive" data-ar="غير نشط">Inactive</option>
                            </select>
                        </div>
                        <button class="btn btn-primary" onclick="generateAdvancedReport('students')" style="width: 100%;" data-en="Generate Report" data-ar="إنشاء التقرير">Generate Report</button>
                    </div>
                    <div id="studentsReportResult" style="display: none; padding: 1rem; border-top: 2px solid #FFE5E5; max-height: 400px; overflow-y: auto;"></div>
                </div>

                <div class="card" style="border: 2px solid #FFE5E5; transition: all 0.3s;" onmouseover="this.style.borderColor='#FF6B9D'; this.style.transform='translateY(-5px)'" onmouseout="this.style.borderColor='#FFE5E5'; this.style.transform='translateY(0)'">
                    <div style="text-align: center; padding: 1.5rem;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">👨‍👩‍👦</div>
                        <h3 style="margin-bottom: 0.5rem; color: var(--primary-color);" data-en="Parents Report" data-ar="تقرير أولياء الأمور">Parents Report</h3>
                        <p style="color: #666; font-size: 0.9rem; margin-bottom: 1.5rem;" data-en="View parents and their associated children" data-ar="عرض أولياء الأمور وأطفالهم المرتبطين">View parents and their associated children</p>
                        <div class="form-group" style="margin-bottom: 1rem;">
                            <label style="font-size: 0.85rem;" data-en="Filter by Student Class" data-ar="تصفية حسب فصل الطالب">Filter by Student Class</label>
                            <select id="parentsReportClass" style="width: 100%;">
                                <option value="all" data-en="All Classes" data-ar="جميع الفصول">All Classes</option>
                                <?php foreach ($allClasses as $class): ?>
                                    <option value="<?php echo $class['Class_ID']; ?>"><?php echo htmlspecialchars($class['Name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button class="btn btn-primary" onclick="generateAdvancedReport('parents')" style="width: 100%;" data-en="Generate Report" data-ar="إنشاء التقرير">Generate Report</button>
                    </div>
                    <div id="parentsReportResult" style="display: none; padding: 1rem; border-top: 2px solid #FFE5E5; max-height: 400px; overflow-y: auto;"></div>
                </div>

                <div class="card" style="border: 2px solid #FFE5E5; transition: all 0.3s;" onmouseover="this.style.borderColor='#FF6B9D'; this.style.transform='translateY(-5px)'" onmouseout="this.style.borderColor='#FFE5E5'; this.style.transform='translateY(0)'">
                    <div style="text-align: center; padding: 1.5rem;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">👩‍🏫</div>
                        <h3 style="margin-bottom: 0.5rem; color: var(--primary-color);" data-en="Teachers Report" data-ar="تقرير المعلمين">Teachers Report</h3>
                        <p style="color: #666; font-size: 0.9rem; margin-bottom: 1.5rem;" data-en="View teachers and their assigned classes/subjects" data-ar="عرض المعلمين والفصول/المواد المخصصة لهم">View teachers and their assigned classes/subjects</p>
                        <div class="form-group" style="margin-bottom: 1rem;">
                            <label style="font-size: 0.85rem;" data-en="Filter by Status" data-ar="تصفية حسب الحالة">Filter by Status</label>
                            <select id="teachersReportStatus" style="width: 100%;">
                                <option value="all" data-en="All Teachers" data-ar="جميع المعلمين">All Teachers</option>
                                <option value="active" data-en="Active" data-ar="نشط">Active</option>
                                <option value="inactive" data-en="Inactive" data-ar="غير نشط">Inactive</option>
                            </select>
                        </div>
                        <button class="btn btn-primary" onclick="generateAdvancedReport('teachers')" style="width: 100%;" data-en="Generate Report" data-ar="إنشاء التقرير">Generate Report</button>
                    </div>
                    <div id="teachersReportResult" style="display: none; padding: 1rem; border-top: 2px solid #FFE5E5; max-height: 400px; overflow-y: auto;"></div>
                </div>

                <div class="card" style="border: 2px solid #FFE5E5; transition: all 0.3s; grid-column: 1 / -1;" onmouseover="this.style.borderColor='#FF6B9D'; this.style.transform='translateY(-5px)'" onmouseout="this.style.borderColor='#FFE5E5'; this.style.transform='translateY(0)'">
                    <div style="text-align: center; padding: 1.5rem;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">📅</div>
                        <h3 style="margin-bottom: 0.5rem; color: var(--primary-color);" data-en="Class Timetables Report" data-ar="تقرير جداول الفصول">Class Timetables Report</h3>
                        <p style="color: #666; font-size: 0.9rem; margin-bottom: 1.5rem;" data-en="View complete class schedules with subjects, teachers, and time slots" data-ar="عرض جداول الفصول الكاملة مع المواد والمعلمين وأوقات الحصص">View complete class schedules with subjects, teachers, and time slots</p>
                        <div class="form-group" style="margin-bottom: 1rem; max-width: 400px; margin-left: auto; margin-right: auto;">
                            <label style="font-size: 0.85rem;" data-en="Select Class" data-ar="اختر الفصل">Select Class</label>
                            <select id="timetablesReportClass" style="width: 100%;">
                                <option value="all" data-en="All Classes" data-ar="جميع الفصول">All Classes</option>
                                <?php foreach ($allClasses as $class): ?>
                                    <option value="<?php echo $class['Class_ID']; ?>"><?php echo htmlspecialchars($class['Name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button class="btn btn-primary" onclick="generateAdvancedReport('timetables')" style="width: 100%; max-width: 400px;" data-en="Generate Report" data-ar="إنشاء التقرير">Generate Report</button>
                    </div>
                    <div id="timetablesReportResult" style="display: none; padding: 1rem; border-top: 2px solid #FFE5E5; max-height: 500px; overflow-y: auto;"></div>
                </div>
            </div>
        </div>
    </div>

    <style>
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

            margin-top: 2rem;
        }
        @media (max-width: 768px) {

                grid-column: 1 / -1;
            }
        }
    </style>

    <script src="script.js"></script>
    <script>
        
        const attendanceTrendData = <?php echo json_encode($attendanceTrendData); ?>;
        const gradeDistributionData = <?php echo json_encode($gradeDistributionData); ?>;
        
        const generatedReports = [];

        function formatDate(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString);
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            return months[date.getMonth()] + ' ' + date.getDate() + ', ' + date.getFullYear();
        }
        
        function formatTime(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString);
            return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        }

        let attendanceDetailData = {};

        let attendanceChart = null;
        function initAttendanceChart(data = null) {
            const ctx = document.getElementById('attendanceTrendChart');
            if (!ctx) return;
            
            const dataToUse = data || attendanceTrendData;

            const labels = [];
            const attendanceRates = [];
            attendanceDetailData = {};
            
            dataToUse.forEach((item, index) => {
                const date = new Date(item.Date);
                const dateLabel = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                labels.push(dateLabel);
                const present = parseInt(item.present) || 0;
                const total = parseInt(item.total) || 0;
                const absent = total - present;
                const rate = total > 0 ? Math.round((present / total) * 100) : 0;
                
                attendanceRates.push(rate);
                attendanceDetailData[index] = {
                    present: present,
                    absent: absent,
                    total: total
                };
            });

            if (labels.length === 0) {
                if (attendanceChart) {
                    attendanceChart.destroy();
                    attendanceChart = null;
                }
                ctx.parentElement.innerHTML = '<div class="chart-placeholder" data-en="No attendance data available" data-ar="لا توجد بيانات حضور متاحة">No attendance data available</div>';
                return;
            }

            if (attendanceChart) {
                attendanceChart.destroy();
            }
            
            attendanceChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: currentLanguage === 'en' ? 'Attendance Rate (%)' : 'معدل الحضور (%)',
                        data: attendanceRates,
                        borderColor: '#6BCB77',
                        backgroundColor: 'rgba(107, 203, 119, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        pointBackgroundColor: '#6BCB77',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                font: {
                                    family: 'Nunito, sans-serif',
                                    size: 12,
                                    weight: '600'
                                },
                                padding: 15
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleFont: {
                                family: 'Nunito, sans-serif',
                                size: 14,
                                weight: '700'
                            },
                            bodyFont: {
                                family: 'Nunito, sans-serif',
                                size: 12
                            },
                            callbacks: {
                                label: function(context) {
                                    const index = context.dataIndex;
                                    const detail = attendanceDetailData[index] || { present: 0, absent: 0, total: 0 };
                                    return [
                                        `${currentLanguage === 'en' ? 'Rate' : 'المعدل'}: ${context.parsed.y}%`,
                                        `${currentLanguage === 'en' ? 'Present' : 'حاضر'}: ${detail.present}`,
                                        `${currentLanguage === 'en' ? 'Absent' : 'غائب'}: ${detail.absent}`,
                                        `${currentLanguage === 'en' ? 'Total' : 'الإجمالي'}: ${detail.total}`
                                    ];
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                },
                                font: {
                                    family: 'Nunito, sans-serif',
                                    size: 11
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            ticks: {
                                font: {
                                    family: 'Nunito, sans-serif',
                                    size: 11
                                },
                                maxRotation: 45,
                                minRotation: 45
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        let gradeChart = null;
        function initGradeChart() {
            const ctx = document.getElementById('gradeDistributionChart');
            if (!ctx) return;

            const labels = [];
            const data = [];
            const colors = ['#6BCB77', '#4CAF50', '#FFD93D', '#FF9800', '#FF6B9D'];
            
            gradeDistributionData.forEach((item, index) => {
                labels.push(item.grade_range);
                data.push(parseInt(item.count) || 0);
            });

            if (labels.length === 0) {
                ctx.parentElement.innerHTML = '<div class="chart-placeholder" data-en="No grade data available" data-ar="لا توجد بيانات درجات متاحة">No grade data available</div>';
                return;
            }
            
            gradeChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: currentLanguage === 'en' ? 'Number of Students' : 'عدد الطلاب',
                        data: data,
                        backgroundColor: colors.slice(0, labels.length),
                        borderColor: colors.slice(0, labels.length).map(c => c.replace('0.8', '1')),
                        borderWidth: 2,
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleFont: {
                                family: 'Nunito, sans-serif',
                                size: 14,
                                weight: '700'
                            },
                            bodyFont: {
                                family: 'Nunito, sans-serif',
                                size: 12
                            },
                            callbacks: {
                                label: function(context) {
                                    return `${currentLanguage === 'en' ? 'Students' : 'طلاب'}: ${context.parsed.y}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                font: {
                                    family: 'Nunito, sans-serif',
                                    size: 11
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            ticks: {
                                font: {
                                    family: 'Nunito, sans-serif',
                                    size: 11
                                }
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            
            if (typeof currentLanguage === 'undefined') {
                currentLanguage = 'en';
            }

            setTimeout(() => {
                initAttendanceChart();
                initGradeChart();
            }, 100);
        });

        function applyFilters() {
            const category = document.getElementById('reportCategory').value;
            const grade = document.getElementById('reportGrade').value;
            const section = document.getElementById('reportSection').value;
            const startDate = document.getElementById('reportStartDate').value;
            const endDate = document.getElementById('reportEndDate').value;

            const loadingMsg = currentLanguage === 'en' ? 'Applying filters...' : 'جارٍ تطبيق المرشحات...';
            if (typeof showNotification !== 'undefined') {
                showNotification(loadingMsg, 'info');
            }

            const formData = new FormData();
            formData.append('action', 'getFilteredData');
            formData.append('category', category);
            formData.append('grade', grade);
            formData.append('section', section);
            formData.append('startDate', startDate);
            formData.append('endDate', endDate);
            
            fetch('reports-analytics-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    
                    if (data.attendanceData && (category === 'all' || category === 'attendance')) {
                        updateAttendanceChart(data.attendanceData);
                    }
                    if (data.gradeData && (category === 'all' || category === 'academic')) {
                        updateGradeChart(data.gradeData);
                    }
                    
                    if (typeof showNotification !== 'undefined') {
            showNotification(currentLanguage === 'en' ? 'Filters applied!' : 'تم تطبيق المرشحات!', 'success');
                    }
                } else {
                    if (typeof showNotification !== 'undefined') {
                        showNotification(data.message || (currentLanguage === 'en' ? 'Error applying filters' : 'خطأ في تطبيق المرشحات'), 'error');
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (typeof showNotification !== 'undefined') {
                    showNotification(currentLanguage === 'en' ? 'An error occurred' : 'حدث خطأ', 'error');
                }
            });
        }

        function resetFilters() {
            document.getElementById('reportCategory').value = 'all';
            document.getElementById('reportGrade').value = 'all';
            document.getElementById('reportSection').value = 'all';
            document.getElementById('reportStartDate').value = '';
            document.getElementById('reportEndDate').value = '';

            updateAttendanceChart(attendanceTrendData);
            updateGradeChart(gradeDistributionData);
            
            if (typeof showNotification !== 'undefined') {
            showNotification(currentLanguage === 'en' ? 'Filters reset!' : 'تم إعادة تعيين المرشحات!', 'info');
            }
        }

        function updateAttendanceChart(data) {
            initAttendanceChart(data);
        }

        function updateGradeChart(data) {
            if (!gradeChart) {
                initGradeChart();
                return;
            }
            
            const labels = [];
            const chartData = [];
            const colors = ['#6BCB77', '#4CAF50', '#FFD93D', '#FF9800', '#FF6B9D'];
            
            data.forEach((item, index) => {
                labels.push(item.grade_range);
                chartData.push(parseInt(item.count) || 0);
            });
            
            if (labels.length === 0) {
                gradeChart.data.labels = [];
                gradeChart.data.datasets[0].data = [];
                gradeChart.update();
                return;
            }
            
            gradeChart.data.labels = labels;
            gradeChart.data.datasets[0].data = chartData;
            gradeChart.data.datasets[0].backgroundColor = colors.slice(0, labels.length);
            gradeChart.update();
        }

        function generateReport(type) {
            const category = document.getElementById('reportCategory').value;
            const grade = document.getElementById('reportGrade').value;
            const section = document.getElementById('reportSection').value;
            const startDate = document.getElementById('reportStartDate').value;
            const endDate = document.getElementById('reportEndDate').value;
            
            if (typeof showNotification !== 'undefined') {
                showNotification(currentLanguage === 'en' ? `Generating ${type} report...` : `جارٍ إنشاء تقرير ${type}...`, 'info');
            }

            const formData = new FormData();
            formData.append('action', 'generateReport');
            formData.append('reportType', type);
            formData.append('category', category);
            formData.append('grade', grade);
            formData.append('section', section);
            formData.append('startDate', startDate);
            formData.append('endDate', endDate);
            
            fetch('reports-analytics-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                const report = {
                    id: generatedReports.length + 1,
                    type: type,
                    category: category,
                    grade: grade !== 'all' ? `Grade ${grade}` : 'All Grades',
                    section: section !== 'all' ? `Section ${section.toUpperCase()}` : 'All Sections',
                    dateRange: startDate && endDate ? `${formatDate(startDate)} - ${formatDate(endDate)}` : 'All Time',
                        generatedAt: new Date().toISOString(),
                        data: data.reportData || {}
                };
                
                generatedReports.unshift(report);
                renderGeneratedReports();
                document.getElementById('generatedReportsCard').style.display = 'block';
                    
                    if (typeof showNotification !== 'undefined') {
                showNotification(currentLanguage === 'en' ? 'Report generated successfully!' : 'تم إنشاء التقرير بنجاح!', 'success');
                    }
                } else {
                    if (typeof showNotification !== 'undefined') {
                        showNotification(data.message || (currentLanguage === 'en' ? 'Error generating report' : 'خطأ في إنشاء التقرير'), 'error');
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (typeof showNotification !== 'undefined') {
                    showNotification(currentLanguage === 'en' ? 'An error occurred' : 'حدث خطأ', 'error');
                }
            });
        }

        function renderGeneratedReports() {
            const container = document.getElementById('generatedReportsList');
            container.innerHTML = generatedReports.map(report => `
                <div class="user-item">
                    <div class="user-info-item" style="flex: 1;">
                        <div class="user-avatar-item">📄</div>
                        <div>
                            <div style="font-weight: 700;">${getReportTypeLabel(report.type)}</div>
                            <div style="font-size: 0.9rem; color: #666;">
                                ${report.grade} • ${report.section} • ${report.dateRange}
                            </div>
                            <div style="font-size: 0.85rem; color: #999; margin-top: 0.3rem;">
                                ${formatDate(report.generatedAt)} at ${formatTime(report.generatedAt)}
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 0.5rem;">
                        <button class="btn btn-secondary btn-small" onclick="viewReport(${report.id})" data-en="View" data-ar="عرض">View</button>
                        <button class="btn btn-secondary btn-small" onclick="exportReport(${report.id}, 'csv')" data-en="Export CSV" data-ar="تصدير CSV">Export CSV</button>
                        <button class="btn btn-secondary btn-small" onclick="exportReport(${report.id}, 'pdf')" data-en="Export PDF" data-ar="تصدير PDF">Export PDF</button>
                    </div>
                </div>
            `).join('');
        }

        function getReportTypeLabel(type) {
            const labels = {
                attendance: currentLanguage === 'en' ? 'Attendance Report' : 'تقرير الحضور',
                academic: currentLanguage === 'en' ? 'Academic Report' : 'تقرير أكاديمي',
                financial: currentLanguage === 'en' ? 'Financial Report' : 'تقرير مالي'
            };
            return labels[type] || type;
        }

        function viewReport(reportId) {
            const report = generatedReports.find(r => r.id === reportId);
            if (!report) {
                if (typeof showNotification !== 'undefined') {
                    showNotification(currentLanguage === 'en' ? 'Report not found' : 'التقرير غير موجود', 'error');
                }
                return;
            }

            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.id = 'reportViewModal';
            modal.style.display = 'block';
            modal.innerHTML = `
                <div class="modal-content" style="max-width: 900px; max-height: 90vh; overflow-y: auto;">
                    <div class="modal-header">
                        <h2 class="modal-title">${getReportTypeLabel(report.type)}</h2>
                        <button class="modal-close" onclick="closeReportModal()">&times;</button>
                    </div>
                    <div style="padding: 1.5rem;">
                        <div style="margin-bottom: 1.5rem; padding: 1rem; background: #FFF9F5; border-radius: 10px;">
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                                <div>
                                    <div style="font-weight: 700; color: #666; font-size: 0.9rem;" data-en="Grade" data-ar="الصف">Grade</div>
                                    <div>${report.grade}</div>
                                </div>
                                <div>
                                    <div style="font-weight: 700; color: #666; font-size: 0.9rem;" data-en="Section" data-ar="القسم">Section</div>
                                    <div>${report.section}</div>
                                </div>
                                <div>
                                    <div style="font-weight: 700; color: #666; font-size: 0.9rem;" data-en="Date Range" data-ar="نطاق التاريخ">Date Range</div>
                                    <div>${report.dateRange}</div>
                                </div>
                                <div>
                                    <div style="font-weight: 700; color: #666; font-size: 0.9rem;" data-en="Generated" data-ar="تم الإنشاء">Generated</div>
                                    <div>${formatDate(report.generatedAt)}</div>
                                </div>
                            </div>
                        </div>
                        <div id="reportViewContent">
                            ${renderReportContent(report)}
                        </div>
                        <div class="action-buttons" style="margin-top: 1.5rem;">
                            <button class="btn btn-primary" onclick="exportReport(${reportId}, 'csv')" data-en="Export CSV" data-ar="تصدير CSV">Export CSV</button>
                            <button class="btn btn-primary" onclick="exportReport(${reportId}, 'pdf')" data-en="Export PDF" data-ar="تصدير PDF">Export PDF</button>
                            <button class="btn btn-secondary" onclick="closeReportModal()" data-en="Close" data-ar="إغلاق">Close</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }
        
        function closeReportModal() {
            const modal = document.getElementById('reportViewModal');
            if (modal) {
                modal.remove();
            }
        }
        
        function renderReportContent(report) {
            if (!report.data || Object.keys(report.data).length === 0) {
                return '<div style="text-align: center; padding: 2rem; color: #666;" data-en="No data available for this report" data-ar="لا توجد بيانات متاحة لهذا التقرير">No data available for this report</div>';
            }
            
            let content = '';
            
            if (report.type === 'attendance' && report.data.attendanceData) {
                content = '<h3 style="margin-bottom: 1rem;" data-en="Attendance Summary" data-ar="ملخص الحضور">Attendance Summary</h3>';
                content += '<table style="width: 100%; border-collapse: collapse; margin-bottom: 1rem;">';
                content += '<thead><tr style="background: #FFE5E5;"><th style="padding: 0.75rem; text-align: left;" data-en="Date" data-ar="التاريخ">Date</th><th style="padding: 0.75rem; text-align: left;" data-en="Present" data-ar="حاضر">Present</th><th style="padding: 0.75rem; text-align: left;" data-en="Absent" data-ar="غائب">Absent</th><th style="padding: 0.75rem; text-align: left;" data-en="Total" data-ar="الإجمالي">Total</th><th style="padding: 0.75rem; text-align: left;" data-en="Rate" data-ar="المعدل">Rate</th></tr></thead><tbody>';
                
                report.data.attendanceData.forEach(item => {
                    const date = new Date(item.Date);
                    const present = parseInt(item.present) || 0;
                    const total = parseInt(item.total) || 0;
                    const absent = total - present;
                    const rate = total > 0 ? Math.round((present / total) * 100) : 0;
                    content += `<tr><td style="padding: 0.75rem; border-bottom: 1px solid #FFE5E5;">${date.toLocaleDateString()}</td><td style="padding: 0.75rem; border-bottom: 1px solid #FFE5E5;">${present}</td><td style="padding: 0.75rem; border-bottom: 1px solid #FFE5E5;">${absent}</td><td style="padding: 0.75rem; border-bottom: 1px solid #FFE5E5;">${total}</td><td style="padding: 0.75rem; border-bottom: 1px solid #FFE5E5;">${rate}%</td></tr>`;
                });
                
                content += '</tbody></table>';
            } else if (report.type === 'academic' && report.data.academicData) {
                if (report.data.academicData.length > 0) {
                    content = '<h3 style="margin-bottom: 1.5rem;" data-en="Academic Report - Complete Materials by Grade" data-ar="تقرير أكاديمي - المواد الكاملة حسب الصف">Academic Report - Complete Materials by Grade</h3>';
                    content += '<p style="margin-bottom: 1.5rem; color: #666; font-size: 0.9rem;" data-en="All materials (courses/subjects) for each grade level" data-ar="جميع المواد (المقررات/المواضيع) لكل مستوى صف">All materials (courses/subjects) for each grade level</p>';

                    report.data.academicData.forEach((gradeData, gradeIndex) => {
                        const isLastGrade = gradeIndex === report.data.academicData.length - 1;
                        const marginBottom = isLastGrade ? '1rem' : '2rem';
                        
                        content += `<div style="margin-bottom: ${marginBottom};">`;
                        content += `<h4 style="margin-bottom: 1rem; color: var(--primary-color); font-size: 1.3rem; font-weight: 700; padding: 0.75rem; background: linear-gradient(135deg, #FFE5E5, #E5F3FF); border-radius: 10px;">`;
                        content += `<span data-en="Grade" data-ar="الصف">Grade</span> ${gradeData.grade_level}`;
                        if (gradeData.materials_count > 0) {
                            content += ` <span style="font-size: 0.9rem; color: #666; font-weight: 500;">(${gradeData.materials_count} <span data-en="materials" data-ar="مادة">materials</span>)</span>`;
                        }
                        content += `</h4>`;
                        
                        if (gradeData.has_materials && gradeData.materials.length > 0) {
                            content += '<div style="overflow-x: auto;">';
                            content += '<table style="width: 100%; border-collapse: collapse; min-width: 500px;">';
                            content += '<thead><tr style="background: #FFE5E5;"><th style="padding: 0.75rem; text-align: left;" data-en="Material/Subject" data-ar="المادة/الموضوع">Material/Subject</th><th style="padding: 0.75rem; text-align: left;" data-en="Assigned Classes" data-ar="الفصول المخصصة">Assigned Classes</th><th style="padding: 0.75rem; text-align: left;" data-en="Average Grade" data-ar="متوسط الدرجة">Average Grade</th></tr></thead><tbody>';
                            
                            gradeData.materials.forEach((material, materialIndex) => {
                                const isLastMaterial = materialIndex === gradeData.materials.length - 1;
                                const borderBottom = isLastMaterial ? 'none' : '1px solid #FFE5E5';
                                
                                let averageGradeDisplay = '—';
                                if (material.has_grades && material.average_grade !== null) {
                                    averageGradeDisplay = `<span style="font-weight: 600; color: var(--primary-color);">${material.average_grade}%</span>`;
                                } else {
                                    averageGradeDisplay = '<span style="color: #999; font-style: italic;" data-en="No grades yet" data-ar="لا توجد درجات بعد">No grades yet</span>';
                                }
                                
                                content += `<tr>`;
                                content += `<td style="padding: 0.75rem; border-bottom: ${borderBottom};">`;
                                content += `<div style="font-weight: 600;">${material.material_name}</div>`;
                                if (material.description) {
                                    content += `<div style="font-size: 0.85rem; color: #666; margin-top: 0.25rem;">${material.description}</div>`;
                                }
                                content += `</td>`;
                                content += `<td style="padding: 0.75rem; border-bottom: ${borderBottom};">${material.assigned_classes_count}</td>`;
                                content += `<td style="padding: 0.75rem; border-bottom: ${borderBottom};">${averageGradeDisplay}</td>`;
                                content += `</tr>`;
                            });
                            
                            content += '</tbody></table>';
                            content += '</div>';
                        } else {
                            
                            content += '<div style="padding: 1.5rem; background: #FFF9F5; border-radius: 10px; border: 2px dashed #FFE5E5; text-align: center;">';
                            content += '<div style="font-size: 2rem; margin-bottom: 0.5rem;">📚</div>';
                            content += '<div style="font-weight: 600; color: #666; margin-bottom: 0.5rem;" data-en="No Materials Assigned" data-ar="لا توجد مواد مخصصة">No Materials Assigned</div>';
                            content += '<div style="font-size: 0.9rem; color: #999;" data-en="This grade does not have any courses or materials assigned yet." data-ar="هذا الصف لا يحتوي على أي مقررات أو مواد مخصصة حتى الآن.">This grade does not have any courses or materials assigned yet.</div>';
                            content += '</div>';
                        }
                        
                        content += '</div>';
                    });
                } else {
                    content = '<div style="text-align: center; padding: 2rem; color: #666;">';
                    content += '<div style="font-size: 2rem; margin-bottom: 1rem;">📊</div>';
                    content += '<div style="font-weight: 700; margin-bottom: 0.5rem;" data-en="No Academic Data Found" data-ar="لم يتم العثور على بيانات أكاديمية">No Academic Data Found</div>';
                    content += '<div style="font-size: 0.9rem;" data-en="No grade levels found in the system." data-ar="لم يتم العثور على مستويات صفوف في النظام.">No grade levels found in the system.</div>';
                    content += '</div>';
                }
            } else if (report.type === 'financial' && report.data.financialData) {
                content = '<h3 style="margin-bottom: 1rem;" data-en="Financial Summary" data-ar="ملخص مالي">Financial Summary</h3>';
                content += '<table style="width: 100%; border-collapse: collapse; margin-bottom: 1rem;">';
                content += '<thead><tr style="background: #FFE5E5;"><th style="padding: 0.75rem; text-align: left;" data-en="Installment" data-ar="القسط">Installment</th><th style="padding: 0.75rem; text-align: left;" data-en="Paid" data-ar="مدفوع">Paid</th><th style="padding: 0.75rem; text-align: left;" data-en="Unpaid" data-ar="غير مدفوع">Unpaid</th><th style="padding: 0.75rem; text-align: left;" data-en="Overdue" data-ar="متأخر">Overdue</th><th style="padding: 0.75rem; text-align: left;" data-en="Total Amount" data-ar="المبلغ الإجمالي">Total Amount</th></tr></thead><tbody>';
                
                report.data.financialData.forEach(item => {
                    content += `<tr><td style="padding: 0.75rem; border-bottom: 1px solid #FFE5E5;">${item.Installment_Number}</td><td style="padding: 0.75rem; border-bottom: 1px solid #FFE5E5;">${item.paid}</td><td style="padding: 0.75rem; border-bottom: 1px solid #FFE5E5;">${item.unpaid}</td><td style="padding: 0.75rem; border-bottom: 1px solid #FFE5E5;">${item.overdue}</td><td style="padding: 0.75rem; border-bottom: 1px solid #FFE5E5;">${parseFloat(item.total_amount || 0).toFixed(2)} JOD</td></tr>`;
                });
                
                content += '</tbody></table>';
            } else {
                content = '<div style="text-align: center; padding: 2rem; color: #666;" data-en="Report data will be displayed here" data-ar="ستظهر بيانات التقرير هنا">Report data will be displayed here</div>';
            }
            
            return content;
        }

        function exportReport(reportId, format) {
            const report = generatedReports.find(r => r.id === reportId);
            if (!report) {
                if (typeof showNotification !== 'undefined') {
                    showNotification(currentLanguage === 'en' ? 'Report not found' : 'التقرير غير موجود', 'error');
                }
                return;
            }
            
            if (format === 'csv') {
                exportReportToCSV(report);
            } else if (format === 'pdf') {
                exportReportToPDF(report);
            }
        }
        
        function exportReportToCSV(report) {
            let csvData = [];
            const filename = `${report.type}_report_${new Date().toISOString().split('T')[0]}.csv`;
            
            if (report.type === 'attendance' && report.data.attendanceData) {
                csvData = [
                    ['Date', 'Present', 'Absent', 'Total', 'Rate (%)'],
                    ...report.data.attendanceData.map(item => {
                        const present = parseInt(item.present) || 0;
                        const total = parseInt(item.total) || 0;
                        const absent = total - present;
                        const rate = total > 0 ? Math.round((present / total) * 100) : 0;
                        return [item.Date, present, absent, total, rate];
                    })
                ];
            } else if (report.type === 'academic' && report.data.academicData) {
                
                csvData = [
                    ['Grade', 'Material/Subject', 'Description', 'Assigned Classes', 'Average Grade (%)', 'Has Grades']
                ];
                
                report.data.academicData.forEach(gradeData => {
                    if (gradeData.has_materials && gradeData.materials.length > 0) {
                        gradeData.materials.forEach(material => {
                            csvData.push([
                                `Grade ${gradeData.grade_level}`,
                                material.material_name,
                                material.description || '',
                                material.assigned_classes_count,
                                material.average_grade !== null ? material.average_grade : 'N/A',
                                material.has_grades ? 'Yes' : 'No'
                            ]);
                        });
                    } else {
                        
                        csvData.push([
                            `Grade ${gradeData.grade_level}`,
                            'No materials assigned',
                            '',
                            '0',
                            'N/A',
                            'No'
                        ]);
                    }
                });
            } else if (report.type === 'financial' && report.data.financialData) {
                csvData = [
                    ['Installment', 'Paid', 'Unpaid', 'Overdue', 'Total Amount (JOD)'],
                    ...report.data.financialData.map(item => [
                        item.Installment_Number,
                        item.paid,
                        item.unpaid,
                        item.overdue,
                        parseFloat(item.total_amount || 0).toFixed(2)
                    ])
                ];
            } else {
                if (typeof showNotification !== 'undefined') {
                    showNotification(currentLanguage === 'en' ? 'No data to export' : 'لا توجد بيانات للتصدير', 'error');
                }
                return;
            }
            
            const csv = csvData.map(row => row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(',')).join('\n');
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            link.click();
            URL.revokeObjectURL(link.href);
            
            if (typeof showNotification !== 'undefined') {
                showNotification(currentLanguage === 'en' ? 'Report exported successfully!' : 'تم تصدير التقرير بنجاح!', 'success');
            }
        }
        
        function exportReportToPDF(report) {
            
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                    <head>
                        <title>${getReportTypeLabel(report.type)}</title>
                        <style>
                            body { font-family: Arial, sans-serif; padding: 20px; }
                            h1 { color: #333; }
                            h3 { color: #333; margin-top: 20px; margin-bottom: 10px; }
                            h4 { color: #333; margin-top: 15px; margin-bottom: 10px; }
                            table { width: 100%; border-collapse: collapse; margin-top: 20px; margin-bottom: 20px; }
                            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                            th { background-color: #f2f2f2; font-weight: bold; }
                            p { margin: 10px 0; }
                        </style>
                    </head>
                    <body>
                        <h1>${getReportTypeLabel(report.type)}</h1>
                        <p><strong>Grade:</strong> ${report.grade}</p>
                        <p><strong>Section:</strong> ${report.section}</p>
                        <p><strong>Date Range:</strong> ${report.dateRange}</p>
                        <p><strong>Generated:</strong> ${formatDate(report.generatedAt)}</p>
                        ${renderReportContent(report)}
                    </body>
                </html>
            `);
            printWindow.document.close();
            setTimeout(() => {
                printWindow.print();
            }, 250);
        }

        function exportAllReports() {
            if (generatedReports.length === 0) {
                if (typeof showNotification !== 'undefined') {
                    showNotification(currentLanguage === 'en' ? 'No reports to export' : 'لا توجد تقارير للتصدير', 'error');
                }
                return;
            }
            
            generatedReports.forEach((report, index) => {
                setTimeout(() => {
                    exportReportToCSV(report);
                }, index * 500);
            });
            
            if (typeof showNotification !== 'undefined') {
                showNotification(currentLanguage === 'en' ? 'All reports exported!' : 'تم تصدير جميع التقارير!', 'success');
            }
        }

        const advancedReportsData = {};

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function toggleAdvancedReports() {
            const section = document.getElementById('advancedReportsSection');
            const icon = document.getElementById('viewMoreIcon');
            const btn = document.getElementById('viewMoreBtn');
            const span = btn.querySelector('span[data-en]');
            
            if (section.style.display === 'none') {
                section.style.display = 'block';
                icon.style.transform = 'rotate(180deg)';
                if (span) {
                    span.setAttribute('data-en', 'Show Less');
                    span.setAttribute('data-ar', 'عرض أقل');
                    span.textContent = currentLanguage === 'en' ? 'Show Less' : 'عرض أقل';
                }
            } else {
                section.style.display = 'none';
                icon.style.transform = 'rotate(0deg)';
                if (span) {
                    span.setAttribute('data-en', 'View More');
                    span.setAttribute('data-ar', 'عرض المزيد');
                    span.textContent = currentLanguage === 'en' ? 'View More' : 'عرض المزيد';
                }
            }
        }

        function generateAdvancedReport(reportType) {
            const resultDiv = document.getElementById(reportType + 'ReportResult');
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = '<div style="text-align: center; padding: 2rem; color: #666;"><i class="fas fa-spinner fa-spin" style="font-size: 2rem; margin-bottom: 1rem;"></i><div>' + (currentLanguage === 'en' ? 'Generating report...' : 'جارٍ إنشاء التقرير...') + '</div></div>';

            const formData = new FormData();
            formData.append('action', 'generateAdvancedReport');
            formData.append('reportType', reportType);

            if (reportType === 'attendance') {
                formData.append('classId', document.getElementById('attendanceReportClass').value);
                formData.append('startDate', document.getElementById('attendanceReportStartDate').value);
                formData.append('endDate', document.getElementById('attendanceReportEndDate').value);
            } else if (reportType === 'students') {
                formData.append('classId', document.getElementById('studentsReportClass').value);
                formData.append('status', document.getElementById('studentsReportStatus').value);
            } else if (reportType === 'parents') {
                formData.append('classId', document.getElementById('parentsReportClass').value);
            } else if (reportType === 'teachers') {
                formData.append('status', document.getElementById('teachersReportStatus').value);
            } else if (reportType === 'timetables') {
                formData.append('classId', document.getElementById('timetablesReportClass').value);
            }

            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 30000); 

            fetch('reports-analytics-ajax.php', {
                method: 'POST',
                body: formData,
                signal: controller.signal
            })
            .then(response => {
                clearTimeout(timeoutId);
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.text().then(text => {
                    console.log('Raw response:', text.substring(0, 200)); 
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON response:', text);
                        throw new Error('Invalid JSON response from server: ' + e.message);
                    }
                });
            })
            .then(data => {
                if (data.success) {
                    
                    advancedReportsData[reportType] = data.reportData;
                    renderAdvancedReport(reportType, data.reportData);
                    if (typeof showNotification !== 'undefined') {
                        showNotification(currentLanguage === 'en' ? 'Report generated successfully!' : 'تم إنشاء التقرير بنجاح!', 'success');
                    }
                } else {
                    resultDiv.innerHTML = '<div style="padding: 2rem; text-align: center; color: #FF6B9D;">' + escapeHtml(data.message || (currentLanguage === 'en' ? 'Error generating report' : 'خطأ في إنشاء التقرير')) + '</div>';
                    if (typeof showNotification !== 'undefined') {
                        showNotification(data.message || (currentLanguage === 'en' ? 'Error generating report' : 'خطأ في إنشاء التقرير'), 'error');
                    }
                }
            })
            .catch(error => {
                clearTimeout(timeoutId);
                console.error('Error:', error);
                let errorMsg = error.message || (currentLanguage === 'en' ? 'An error occurred' : 'حدث خطأ');
                if (error.name === 'AbortError') {
                    errorMsg = currentLanguage === 'en' ? 'Request timed out. Please try again.' : 'انتهت مهلة الطلب. يرجى المحاولة مرة أخرى.';
                }
                resultDiv.innerHTML = '<div style="padding: 2rem; text-align: center; color: #FF6B9D;"><div style="margin-bottom: 0.5rem;">' + (currentLanguage === 'en' ? 'An error occurred' : 'حدث خطأ') + '</div><div style="font-size: 0.85rem; color: #999;">' + escapeHtml(errorMsg) + '</div></div>';
                if (typeof showNotification !== 'undefined') {
                    showNotification(errorMsg, 'error');
                }
            });
        }

        function renderAdvancedReport(reportType, data) {
            const resultDiv = document.getElementById(reportType + 'ReportResult');
            let html = '';

            if (reportType === 'attendance') {
                html = '<div style="overflow-x: auto;"><table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;"><thead><tr style="background: #FFE5E5;"><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Student Name' : 'اسم الطالب') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Class' : 'الفصل') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Date' : 'التاريخ') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Status' : 'الحالة') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Notes' : 'ملاحظات') + '</th></tr></thead><tbody>';
                
                if (data && data.length > 0) {
                    data.forEach(item => {
                        html += '<tr><td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(item.student_name || '') + '</td>';
                        html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(item.class_name || '') + '</td>';
                        html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + (item.date ? new Date(item.date).toLocaleDateString() : '') + '</td>';
                        html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(item.status || '') + '</td>';
                        html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(item.notes || '-') + '</td></tr>';
                    });
                } else {
                    html += '<tr><td colspan="5" style="padding: 2rem; text-align: center; color: #999;">' + (currentLanguage === 'en' ? 'No attendance records found' : 'لم يتم العثور على سجلات حضور') + '</td></tr>';
                }
                html += '</tbody></table></div>';

            } else if (reportType === 'students') {
                html = '<div style="overflow-x: auto;"><table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;"><thead><tr style="background: #FFE5E5;"><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Student ID' : 'رقم الطالب') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Full Name' : 'الاسم الكامل') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Class' : 'الفصل') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Section' : 'القسم') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Status' : 'الحالة') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Enrollment Date' : 'تاريخ التسجيل') + '</th></tr></thead><tbody>';
                
                if (data && data.length > 0) {
                    data.forEach(item => {
                        html += '<tr><td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(item.student_code || '') + '</td>';
                        html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(item.name || '') + '</td>';
                        html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(item.class_name || '-') + '</td>';
                        html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(item.section || '-') + '</td>';
                        html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(item.status || '') + '</td>';
                        html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + (item.enrollment_date ? new Date(item.enrollment_date).toLocaleDateString() : '-') + '</td></tr>';
                    });
                } else {
                    html += '<tr><td colspan="6" style="padding: 2rem; text-align: center; color: #999;">' + (currentLanguage === 'en' ? 'No students found' : 'لم يتم العثور على طلاب') + '</td></tr>';
                }
                html += '</tbody></table></div>';

            } else if (reportType === 'parents') {
                html = '<div style="overflow-x: auto;"><table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;"><thead><tr style="background: #FFE5E5;"><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Parent Name' : 'اسم ولي الأمر') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Phone' : 'الهاتف') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Email' : 'البريد الإلكتروني') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Student Name' : 'اسم الطالب') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Student Class' : 'فصل الطالب') + '</th></tr></thead><tbody>';
                
                if (data && data.length > 0) {
                    data.forEach(item => {
                        html += '<tr><td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(item.parent_name || '') + '</td>';
                        html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(item.phone || '-') + '</td>';
                        html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(item.email || '-') + '</td>';
                        html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(item.student_name || '') + '</td>';
                        html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(item.student_class || '') + '</td></tr>';
                    });
                } else {
                    html += '<tr><td colspan="5" style="padding: 2rem; text-align: center; color: #999;">' + (currentLanguage === 'en' ? 'No parents found' : 'لم يتم العثور على أولياء أمور') + '</td></tr>';
                }
                html += '</tbody></table></div>';

            } else if (reportType === 'teachers') {
                html = '<div style="overflow-x: auto;"><table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;"><thead><tr style="background: #FFE5E5;"><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Teacher ID' : 'رقم المعلم') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Teacher Name' : 'اسم المعلم') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Subject' : 'المادة') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Assigned Classes' : 'الفصول المخصصة') + '</th></tr></thead><tbody>';
                
                if (data && data.length > 0) {
                    data.forEach(item => {
                        html += '<tr><td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(item.teacher_id || '') + '</td>';
                        html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(item.teacher_name || '') + '</td>';
                        html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(item.subject || '-') + '</td>';
                        html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(item.assigned_classes || '-') + '</td></tr>';
                    });
                } else {
                    html += '<tr><td colspan="4" style="padding: 2rem; text-align: center; color: #999;">' + (currentLanguage === 'en' ? 'No teachers found' : 'لم يتم العثور على معلمين') + '</td></tr>';
                }
                html += '</tbody></table></div>';

            } else if (reportType === 'timetables') {
                html = '<div style="overflow-x: auto;">';
                if (data && data.length > 0) {
                    
                    const classesMap = {};
                    data.forEach(item => {
                        const classKey = item.class_id;
                        if (!classesMap[classKey]) {
                            classesMap[classKey] = {
                                class_name: item.class_name,
                                schedules: []
                            };
                        }
                        classesMap[classKey].schedules.push(item);
                    });

                    Object.keys(classesMap).forEach(classKey => {
                        const classData = classesMap[classKey];
                        html += '<div style="margin-bottom: 2rem; padding: 1rem; background: #FFF9F5; border-radius: 10px; border: 2px solid #FFE5E5;">';
                        html += '<h4 style="margin-bottom: 1rem; color: var(--primary-color);">' + escapeHtml(classData.class_name) + '</h4>';
                        html += '<table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;"><thead><tr style="background: #FFE5E5;"><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Day' : 'اليوم') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Time' : 'الوقت') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Subject' : 'المادة') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Teacher' : 'المعلم') + '</th><th style="padding: 0.75rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Room' : 'القاعة') + '</th></tr></thead><tbody>';
                        
                        classData.schedules.forEach(schedule => {
                            html += '<tr><td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(schedule.day || '') + '</td>';
                            html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(schedule.start_time || '') + ' - ' + escapeHtml(schedule.end_time || '') + '</td>';
                            html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(schedule.subject || schedule.course_name || '-') + '</td>';
                            html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(schedule.teacher_name || '-') + '</td>';
                            html += '<td style="padding: 0.75rem; border: 1px solid #ddd;">' + escapeHtml(schedule.room || '-') + '</td></tr>';
                        });
                        
                        html += '</tbody></table></div>';
                    });
                } else {
                    html += '<div style="padding: 2rem; text-align: center; color: #999;">' + (currentLanguage === 'en' ? 'No timetable data found' : 'لم يتم العثور على بيانات الجدول') + '</div>';
                }
                html += '</div>';
            }

            html += '<div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 2px solid #FFE5E5; display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">';
            html += '<button class="btn btn-primary" onclick="viewAdvancedReport(\'' + reportType + '\')" style="min-width: 120px;"><i class="fas fa-eye" style="margin-right: 0.5rem;"></i><span data-en="View Full Report" data-ar="عرض التقرير الكامل">View Full Report</span></button>';
            html += '<button class="btn btn-secondary" onclick="downloadAdvancedReport(\'' + reportType + '\', \'csv\')" style="min-width: 120px;"><i class="fas fa-download" style="margin-right: 0.5rem;"></i><span data-en="Download CSV" data-ar="تحميل CSV">Download CSV</span></button>';
            html += '<button class="btn btn-secondary" onclick="downloadAdvancedReport(\'' + reportType + '\', \'pdf\')" style="min-width: 120px;"><i class="fas fa-file-pdf" style="margin-right: 0.5rem;"></i><span data-en="Download PDF" data-ar="تحميل PDF">Download PDF</span></button>';
            html += '</div>';

            resultDiv.innerHTML = html;
        }

        function viewAdvancedReport(reportType) {
            const data = advancedReportsData[reportType];
            if (!data || data.length === 0) {
                if (typeof showNotification !== 'undefined') {
                    showNotification(currentLanguage === 'en' ? 'No report data available' : 'لا توجد بيانات تقرير متاحة', 'error');
                }
                return;
            }

            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.id = 'advancedReportModal';
            modal.style.display = 'block';
            modal.style.zIndex = '10000';
            modal.innerHTML = `
                <div class="modal-content" style="max-width: 95%; max-height: 90vh; overflow-y: auto;">
                    <div class="modal-header">
                        <h2 class="modal-title">${getAdvancedReportTitle(reportType)}</h2>
                        <button class="modal-close" onclick="closeAdvancedReportModal()">&times;</button>
                    </div>
                    <div style="padding: 1.5rem;">
                        <div id="advancedReportModalContent"></div>
                        <div class="action-buttons" style="margin-top: 1.5rem; justify-content: center;">
                            <button class="btn btn-primary" onclick="downloadAdvancedReport('${reportType}', 'csv')" data-en="Download CSV" data-ar="تحميل CSV">Download CSV</button>
                            <button class="btn btn-primary" onclick="downloadAdvancedReport('${reportType}', 'pdf')" data-en="Download PDF" data-ar="تحميل PDF">Download PDF</button>
                            <button class="btn btn-secondary" onclick="closeAdvancedReportModal()" data-en="Close" data-ar="إغلاق">Close</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);

            const contentDiv = document.getElementById('advancedReportModalContent');
            renderAdvancedReportContent(reportType, data, contentDiv);
        }

        function closeAdvancedReportModal() {
            const modal = document.getElementById('advancedReportModal');
            if (modal) {
                modal.remove();
            }
        }

        function getAdvancedReportTitle(reportType) {
            const titles = {
                attendance: currentLanguage === 'en' ? 'Attendance Report' : 'تقرير الحضور',
                students: currentLanguage === 'en' ? 'Students Report' : 'تقرير الطلاب',
                parents: currentLanguage === 'en' ? 'Parents Report' : 'تقرير أولياء الأمور',
                teachers: currentLanguage === 'en' ? 'Teachers Report' : 'تقرير المعلمين',
                timetables: currentLanguage === 'en' ? 'Class Timetables Report' : 'تقرير جداول الفصول'
            };
            return titles[reportType] || 'Report';
        }

        function renderAdvancedReportContent(reportType, data, container) {
            
            let html = '';

            if (reportType === 'attendance') {
                html = '<div style="overflow-x: auto;"><table style="width: 100%; border-collapse: collapse; font-size: 0.95rem;"><thead><tr style="background: #FFE5E5;"><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Student Name' : 'اسم الطالب') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Class' : 'الفصل') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Date' : 'التاريخ') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Status' : 'الحالة') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Notes' : 'ملاحظات') + '</th></tr></thead><tbody>';
                if (data && data.length > 0) {
                    data.forEach(item => {
                        html += '<tr><td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(item.student_name || '') + '</td>';
                        html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(item.class_name || '') + '</td>';
                        html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + (item.date ? new Date(item.date).toLocaleDateString() : '') + '</td>';
                        html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(item.status || '') + '</td>';
                        html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(item.notes || '-') + '</td></tr>';
                    });
                } else {
                    html += '<tr><td colspan="5" style="padding: 2rem; text-align: center; color: #999;">' + (currentLanguage === 'en' ? 'No attendance records found' : 'لم يتم العثور على سجلات حضور') + '</td></tr>';
                }
                html += '</tbody></table></div>';
            } else if (reportType === 'students') {
                html = '<div style="overflow-x: auto;"><table style="width: 100%; border-collapse: collapse; font-size: 0.95rem;"><thead><tr style="background: #FFE5E5;"><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Student ID' : 'رقم الطالب') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Full Name' : 'الاسم الكامل') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Class' : 'الفصل') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Section' : 'القسم') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Status' : 'الحالة') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Enrollment Date' : 'تاريخ التسجيل') + '</th></tr></thead><tbody>';
                if (data && data.length > 0) {
                    data.forEach(item => {
                        html += '<tr><td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(item.student_code || '') + '</td>';
                        html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(item.name || '') + '</td>';
                        html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(item.class_name || '-') + '</td>';
                        html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(item.section || '-') + '</td>';
                        html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(item.status || '') + '</td>';
                        html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + (item.enrollment_date ? new Date(item.enrollment_date).toLocaleDateString() : '-') + '</td></tr>';
                    });
                } else {
                    html += '<tr><td colspan="6" style="padding: 2rem; text-align: center; color: #999;">' + (currentLanguage === 'en' ? 'No students found' : 'لم يتم العثور على طلاب') + '</td></tr>';
                }
                html += '</tbody></table></div>';
            } else if (reportType === 'parents') {
                html = '<div style="overflow-x: auto;"><table style="width: 100%; border-collapse: collapse; font-size: 0.95rem;"><thead><tr style="background: #FFE5E5;"><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Parent Name' : 'اسم ولي الأمر') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Phone' : 'الهاتف') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Email' : 'البريد الإلكتروني') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Student Name' : 'اسم الطالب') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Student Class' : 'فصل الطالب') + '</th></tr></thead><tbody>';
                if (data && data.length > 0) {
                    data.forEach(item => {
                        html += '<tr><td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(item.parent_name || '') + '</td>';
                        html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(item.phone || '-') + '</td>';
                        html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(item.email || '-') + '</td>';
                        html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(item.student_name || '') + '</td>';
                        html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(item.student_class || '') + '</td></tr>';
                    });
                } else {
                    html += '<tr><td colspan="5" style="padding: 2rem; text-align: center; color: #999;">' + (currentLanguage === 'en' ? 'No parents found' : 'لم يتم العثور على أولياء أمور') + '</td></tr>';
                }
                html += '</tbody></table></div>';
            } else if (reportType === 'teachers') {
                html = '<div style="overflow-x: auto;"><table style="width: 100%; border-collapse: collapse; font-size: 0.95rem;"><thead><tr style="background: #FFE5E5;"><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Teacher ID' : 'رقم المعلم') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Teacher Name' : 'اسم المعلم') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Subject' : 'المادة') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Assigned Classes' : 'الفصول المخصصة') + '</th></tr></thead><tbody>';
                if (data && data.length > 0) {
                    data.forEach(item => {
                        html += '<tr><td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(item.teacher_id || '') + '</td>';
                        html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(item.teacher_name || '') + '</td>';
                        html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(item.subject || '-') + '</td>';
                        html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(item.assigned_classes || '-') + '</td></tr>';
                    });
                } else {
                    html += '<tr><td colspan="4" style="padding: 2rem; text-align: center; color: #999;">' + (currentLanguage === 'en' ? 'No teachers found' : 'لم يتم العثور على معلمين') + '</td></tr>';
                }
                html += '</tbody></table></div>';
            } else if (reportType === 'timetables') {
                html = '<div style="overflow-x: auto;">';
                if (data && data.length > 0) {
                    const classesMap = {};
                    data.forEach(item => {
                        const classKey = item.class_id;
                        if (!classesMap[classKey]) {
                            classesMap[classKey] = {
                                class_name: item.class_name,
                                schedules: []
                            };
                        }
                        classesMap[classKey].schedules.push(item);
                    });

                    Object.keys(classesMap).forEach(classKey => {
                        const classData = classesMap[classKey];
                        html += '<div style="margin-bottom: 2rem; padding: 1rem; background: #FFF9F5; border-radius: 10px; border: 2px solid #FFE5E5;">';
                        html += '<h4 style="margin-bottom: 1rem; color: var(--primary-color);">' + escapeHtml(classData.class_name) + '</h4>';
                        html += '<table style="width: 100%; border-collapse: collapse; font-size: 0.95rem;"><thead><tr style="background: #FFE5E5;"><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Day' : 'اليوم') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Time' : 'الوقت') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Subject' : 'المادة') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Teacher' : 'المعلم') + '</th><th style="padding: 1rem; text-align: left; border: 1px solid #ddd;">' + (currentLanguage === 'en' ? 'Room' : 'القاعة') + '</th></tr></thead><tbody>';
                        
                        classData.schedules.forEach(schedule => {
                            html += '<tr><td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(schedule.day || '') + '</td>';
                            html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(schedule.start_time || '') + ' - ' + escapeHtml(schedule.end_time || '') + '</td>';
                            html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(schedule.subject || schedule.course_name || '-') + '</td>';
                            html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(schedule.teacher_name || '-') + '</td>';
                            html += '<td style="padding: 1rem; border: 1px solid #ddd;">' + escapeHtml(schedule.room || '-') + '</td></tr>';
                        });
                        
                        html += '</tbody></table></div>';
                    });
                } else {
                    html += '<div style="padding: 2rem; text-align: center; color: #999;">' + (currentLanguage === 'en' ? 'No timetable data found' : 'لم يتم العثور على بيانات الجدول') + '</div>';
                }
                html += '</div>';
            }

            container.innerHTML = html;
        }

        function downloadAdvancedReport(reportType, format) {
            const data = advancedReportsData[reportType];
            if (!data || data.length === 0) {
                if (typeof showNotification !== 'undefined') {
                    showNotification(currentLanguage === 'en' ? 'No report data available' : 'لا توجد بيانات تقرير متاحة', 'error');
                }
                return;
            }

            if (format === 'csv') {
                downloadAdvancedReportCSV(reportType, data);
            } else if (format === 'pdf') {
                downloadAdvancedReportPDF(reportType, data);
            }
        }

        function downloadAdvancedReportCSV(reportType, data) {
            let csvData = [];
            const filename = `${reportType}_report_${new Date().toISOString().split('T')[0]}.csv`;

            if (reportType === 'attendance') {
                csvData = [
                    ['Student Name', 'Class', 'Date', 'Status', 'Notes'],
                    ...data.map(item => [
                        item.student_name || '',
                        item.class_name || '',
                        item.date ? new Date(item.date).toLocaleDateString() : '',
                        item.status || '',
                        item.notes || ''
                    ])
                ];
            } else if (reportType === 'students') {
                csvData = [
                    ['Student ID', 'Full Name', 'Class', 'Section', 'Status', 'Enrollment Date'],
                    ...data.map(item => [
                        item.student_code || '',
                        item.name || '',
                        item.class_name || '-',
                        item.section || '-',
                        item.status || '',
                        item.enrollment_date ? new Date(item.enrollment_date).toLocaleDateString() : '-'
                    ])
                ];
            } else if (reportType === 'parents') {
                csvData = [
                    ['Parent Name', 'Phone', 'Email', 'Student Name', 'Student Class'],
                    ...data.map(item => [
                        item.parent_name || '',
                        item.phone || '-',
                        item.email || '-',
                        item.student_name || '',
                        item.student_class || ''
                    ])
                ];
            } else if (reportType === 'teachers') {
                csvData = [
                    ['Teacher ID', 'Teacher Name', 'Subject', 'Assigned Classes'],
                    ...data.map(item => [
                        item.teacher_id || '',
                        item.teacher_name || '',
                        item.subject || '-',
                        item.assigned_classes || '-'
                    ])
                ];
            } else if (reportType === 'timetables') {
                csvData = [
                    ['Class', 'Day', 'Time', 'Subject', 'Teacher', 'Room'],
                    ...data.map(item => [
                        item.class_name || '',
                        item.day || '',
                        (item.start_time || '') + ' - ' + (item.end_time || ''),
                        item.subject || item.course_name || '-',
                        item.teacher_name || '-',
                        item.room || '-'
                    ])
                ];
            }

            const csv = csvData.map(row => row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(',')).join('\n');
            const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' }); 
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            link.click();
            URL.revokeObjectURL(link.href);

            if (typeof showNotification !== 'undefined') {
                showNotification(currentLanguage === 'en' ? 'Report downloaded successfully!' : 'تم تحميل التقرير بنجاح!', 'success');
            }
        }

        function downloadAdvancedReportPDF(reportType, data) {
            const printWindow = window.open('', '_blank');
            const title = getAdvancedReportTitle(reportType);

            const tempDiv = document.createElement('div');
            renderAdvancedReportContent(reportType, data, tempDiv);
            const content = tempDiv.innerHTML;

            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                    <head>
                        <title>${title}</title>
                        <meta charset="UTF-8">
                        <style>
                            @media print {
                                body { margin: 0; padding: 20px; }
                            }
                            body { 
                                font-family: Arial, sans-serif; 
                                padding: 20px; 
                                direction: ${currentLanguage === 'ar' ? 'rtl' : 'ltr'};
                            }
                            h1 { color: #FF6B9D; margin-bottom: 20px; }
                            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                            th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
                            th { background-color: #FFE5E5; font-weight: bold; }
                            tr:nth-child(even) { background-color: #f9f9f9; }
                        </style>
                    </head>
                    <body>
                        <h1>${title}</h1>
                        <p><strong>${currentLanguage === 'en' ? 'Generated on' : 'تم الإنشاء في'}:</strong> ${new Date().toLocaleString()}</p>
                        ${content}
                    </body>
                </html>
            `);
            printWindow.document.close();
            setTimeout(() => {
                printWindow.print();
            }, 250);
        }
    </script>
</body>
</html>

