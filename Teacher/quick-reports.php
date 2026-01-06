<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-teacher.php';

requireUserType('teacher');

$currentTeacherId = getCurrentUserId();
$currentTeacher = getCurrentUserData($pdo);
$teacherName = $_SESSION['user_name'] ?? 'Teacher';

$teacherClasses = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.Class_ID, c.Name as Class_Name, c.Grade_Level, c.Section
        FROM teacher_class_course tcc
        JOIN class c ON tcc.Class_ID = c.Class_ID
        WHERE tcc.Teacher_ID = ?
        ORDER BY c.Grade_Level, c.Section
    ");
    $stmt->execute([$currentTeacherId]);
    $teacherClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching teacher classes: " . $e->getMessage());
    $teacherClasses = [];
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quick Reports - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        .reports-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .report-type-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .report-type-card {
            background: white;
            padding: 1.5rem;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s;
            border: 3px solid transparent;
            text-align: center;
            box-shadow: 0 3px 15px rgba(0,0,0,0.1);
        }

        .report-type-card:hover {
            transform: translateY(-5px);
            border-color: var(--primary-color);
            box-shadow: 0 8px 25px rgba(255,107,157,0.3);
        }

        .report-type-card.active {
            background: linear-gradient(135deg, #FFE5E5, #E5F3FF);
            border-color: var(--primary-color);
        }

        .report-type-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .report-type-title {
            font-family: 'Fredoka', sans-serif;
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .report-type-desc {
            font-size: 0.85rem;
            color: #666;
        }

        .report-filters {
            background: white;
            padding: 2rem;
            border-radius: 25px;
            margin-bottom: 2rem;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .filter-header {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #FFE5E5;
        }

        .filter-header h3 {
            font-family: 'Fredoka', sans-serif;
            font-size: 1.3rem;
            color: var(--primary-color);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-header h3 i {
            font-size: 1.2rem;
        }

        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .filter-select {
            width: 100%;
            padding: 0.8rem 1rem;
            border: 2px solid #FFE5E5;
            border-radius: 10px;
            font-size: 1rem;
            background: white;
            transition: all 0.3s;
        }

        .filter-select:hover {
            border-color: #FFB3D1;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 107, 157, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            flex-wrap: wrap;
            padding-top: 1rem;
            border-top: 2px solid #FFE5E5;
        }

        .filter-actions .btn {
            min-width: 150px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s;
        }

        .filter-actions .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .filter-actions .btn:active {
            transform: translateY(0);
        }

        .report-content {
            background: white;
            padding: 2rem;
            border-radius: 25px;
            margin-bottom: 2rem;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .class-section {
            margin-bottom: 3rem;
        }

        .class-section:last-child {
            margin-bottom: 0;
        }

        .class-header {
            font-family: 'Fredoka', sans-serif;
            font-size: 1.5rem;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 3px solid #FFE5E5;
        }

        .report-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 1rem;
            overflow-x: auto;
        }

        .report-table th {
            background: linear-gradient(135deg, #FF6B9D, #6BCB77);
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 700;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .report-table td {
            padding: 1rem;
            border-bottom: 2px solid #FFE5E5;
        }

        .report-table tr:hover {
            background: #FFF9F5;
        }

        .assignment-item {
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: #FFF9F5;
            border-radius: 15px;
            border-left: 4px solid var(--primary-color);
        }

        .assignment-header {
            font-family: 'Fredoka', sans-serif;
            font-size: 1.2rem;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .assignment-meta {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-submitted {
            background: #6BCB77;
            color: white;
        }

        .status-not-submitted {
            background: #FF6B9D;
            color: white;
        }

        .status-late {
            background: #FFD93D;
            color: #333;
        }

        .loading-spinner {
            text-align: center;
            padding: 3rem;
            color: #666;
        }

        .loading-spinner i {
            font-size: 3rem;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #666;
        }

        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            .report-type-selector {
                grid-template-columns: 1fr;
            }

            .filter-row {
                grid-template-columns: 1fr;
            }

            .report-table {
                font-size: 0.85rem;
            }

            .report-table th,
            .report-table td {
                padding: 0.7rem;
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
                <span data-en="Quick Reports" data-ar="التقارير السريعة">Quick Reports</span>
            </h1>
            <p class="page-subtitle" data-en="Generate comprehensive reports for grades, attendance, and assignments" data-ar="إنشاء تقارير شاملة للدرجات والحضور والواجبات">Generate comprehensive reports for grades, attendance, and assignments</p>
        </div>

        <div class="report-type-selector">
            <div class="report-type-card active" onclick="selectReportType('grades')" data-type="grades">
                <div class="report-type-icon">📊</div>
                <div class="report-type-title" data-en="Grades Report" data-ar="تقرير الدرجات">Grades Report</div>
                <div class="report-type-desc" data-en="Academic performance overview" data-ar="نظرة عامة على الأداء الأكاديمي">Academic performance overview</div>
            </div>
            <div class="report-type-card" onclick="selectReportType('attendance')" data-type="attendance">
                <div class="report-type-icon">📅</div>
                <div class="report-type-title" data-en="Attendance Report" data-ar="تقرير الحضور">Attendance Report</div>
                <div class="report-type-desc" data-en="Attendance monitoring and statistics" data-ar="مراقبة الحضور والإحصائيات">Attendance monitoring and statistics</div>
            </div>
            <div class="report-type-card" onclick="selectReportType('assignments')" data-type="assignments">
                <div class="report-type-icon">📝</div>
                <div class="report-type-title" data-en="Assignments Report" data-ar="تقرير الواجبات">Assignments Report</div>
                <div class="report-type-desc" data-en="Assignment tracking and submissions" data-ar="تتبع الواجبات والتقديمات">Assignment tracking and submissions</div>
            </div>
        </div>

        <div class="report-filters">
            <div class="filter-header">
                <h3 data-en="Filter Options" data-ar="خيارات التصفية">
                    <i class="fas fa-filter"></i> Filter Options
                </h3>
            </div>
            <div class="filter-row">
                <div class="form-group">
                    <label data-en="Report Type" data-ar="نوع التقرير">
                        <i class="fas fa-chart-bar"></i> Report Type
                    </label>
                    <select id="reportTypeFilter" class="filter-select">
                        <option value="grades" data-en="Grades Report" data-ar="تقرير الدرجات">Grades Report</option>
                        <option value="attendance" data-en="Attendance Report" data-ar="تقرير الحضور">Attendance Report</option>
                        <option value="assignments" data-en="Assignments Report" data-ar="تقرير الواجبات">Assignments Report</option>
                    </select>
                </div>
                <div class="form-group">
                    <label data-en="Class" data-ar="الفصل">
                        <i class="fas fa-users"></i> Class
                    </label>
                    <select id="classFilter" class="filter-select">
                        <option value="all" data-en="All My Classes" data-ar="جميع فصولي">All My Classes</option>
                        <?php foreach ($teacherClasses as $class): ?>
                            <option value="<?php echo htmlspecialchars($class['Class_ID']); ?>">
                                <?php echo htmlspecialchars($class['Class_Name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="filter-actions">
                <button type="button" class="btn btn-primary" onclick="applyFilters()">
                    <i class="fas fa-check"></i>
                    <span data-en="Apply Filters" data-ar="تطبيق التصفية">Apply Filters</span>
                </button>
                <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                    <i class="fas fa-redo"></i>
                    <span data-en="Reset" data-ar="إعادة تعيين">Reset</span>
                </button>
            </div>
        </div>

        <div id="reportContent">
            <div class="loading-spinner">
                <i class="fas fa-spinner"></i>
                <p data-en="Loading report..." data-ar="جاري تحميل التقرير...">Loading report...</p>
            </div>
        </div>
    </div>

    <script>
        
        let currentReportType = 'grades';
        
        function selectReportType(type) {
            console.log('Selecting report type:', type);
            currentReportType = type;
            console.log('Current report type set to:', currentReportType);

            document.querySelectorAll('.report-type-card').forEach(card => {
                card.classList.remove('active');
            });
            const activeCard = document.querySelector(`.report-type-card[data-type="${type}"]`);
            if (activeCard) {
                activeCard.classList.add('active');
            } else {
                console.error('Card not found for type:', type);
            }

            const reportTypeFilter = document.getElementById('reportTypeFilter');
            if (reportTypeFilter) {
                reportTypeFilter.value = type;
            }

            generateReport();
        }

        function applyFilters() {
            
            const reportTypeFilter = document.getElementById('reportTypeFilter');
            if (reportTypeFilter) {
                const selectedType = reportTypeFilter.value;
                if (selectedType !== currentReportType) {
                    
                    selectReportType(selectedType);
                } else {
                    
                    generateReport();
                }
            } else {
                generateReport();
            }
        }

        function resetFilters() {
            
            const reportTypeFilter = document.getElementById('reportTypeFilter');
            const classFilter = document.getElementById('classFilter');
            
            if (reportTypeFilter) {
                reportTypeFilter.value = 'grades';
            }
            if (classFilter) {
                classFilter.value = 'all';
            }

            selectReportType('grades');
        }

        function generateReport() {
            const container = document.getElementById('reportContent');
            const classFilter = document.getElementById('classFilter').value;

            container.innerHTML = `
                <div class="loading-spinner">
                    <i class="fas fa-spinner"></i>
                    <p data-en="Loading report..." data-ar="جاري تحميل التقرير...">Loading report...</p>
                </div>
            `;

            const actionMap = {
                'grades': 'get_grades_report',
                'attendance': 'get_attendance_report',
                'assignments': 'get_assignments_report'
            };
            const action = actionMap[currentReportType] || 'get_grades_report';
            const url = `quick-reports-ajax.php?action=${action}&class_id=${encodeURIComponent(classFilter)}`;
            console.log('Current report type:', currentReportType);
            console.log('Action:', action);
            console.log('Fetching:', url);
            
            fetch(url)
                .then(response => {
                    console.log('Response status:', response.status);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.text();
                })
                .then(text => {
                    console.log('Response text:', text);
                    try {
                        const data = JSON.parse(text);
                        console.log('Parsed data:', data);
                        if (data.success) {
                            renderReport(data.data);
                        } else {
                            container.innerHTML = `
                                <div class="empty-state">
                                    <div class="empty-state-icon">⚠️</div>
                                    <p>${escapeHtml(data.message || 'Error loading report')}</p>
                                </div>
                            `;
                        }
                    } catch (e) {
                        console.error('JSON parse error:', e);
                        console.error('Response was:', text);
                        container.innerHTML = `
                            <div class="empty-state">
                                <div class="empty-state-icon">⚠️</div>
                                <p data-en="Error parsing response. Check console for details." data-ar="خطأ في تحليل الاستجابة. تحقق من وحدة التحكم للتفاصيل.">Error parsing response. Check console for details.</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    container.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-state-icon">⚠️</div>
                            <p data-en="Error loading report. Please try again." data-ar="خطأ في تحميل التقرير. يرجى المحاولة مرة أخرى.">Error loading report. Please try again.</p>
                            <p style="font-size: 0.8rem; margin-top: 0.5rem; color: #999;">${escapeHtml(error.message)}</p>
                        </div>
                    `;
                });
        }

        function renderReport(data) {
            const container = document.getElementById('reportContent');
            
            console.log('Rendering report, type:', currentReportType);
            console.log('Data received:', data);
            
            if (!data || data.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <p data-en="No data available for the selected filters." data-ar="لا توجد بيانات متاحة للفلاتر المحددة.">No data available for the selected filters.</p>
                    </div>
                `;
                return;
            }
            
            switch(currentReportType) {
                case 'grades':
                    console.log('Rendering grades report');
                    renderGradesReport(data);
                    break;
                case 'attendance':
                    console.log('Rendering attendance report');
                    renderAttendanceReport(data);
                    break;
                case 'assignments':
                    console.log('Rendering assignments report');
                    renderAssignmentsReport(data);
                    break;
                default:
                    console.error('Unknown report type:', currentReportType);
                    container.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-state-icon">⚠️</div>
                            <p>Unknown report type: ${currentReportType}</p>
                        </div>
                    `;
            }
        }

        function renderGradesReport(data) {
            const container = document.getElementById('reportContent');
            let html = '<div class="report-content">';
            
            data.forEach(classData => {
                html += `
                    <div class="class-section">
                        <h2 class="class-header">${escapeHtml(classData.class_name)}</h2>
                        <div class="table-container">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th data-en="Student Name" data-ar="اسم الطالب">Student Name</th>
                                        <th data-en="Subject" data-ar="المادة">Subject</th>
                                        <th data-en="Midterm" data-ar="الامتحان النصفي">Midterm</th>
                                        <th data-en="Final" data-ar="النهائي">Final</th>
                                        <th data-en="Quiz" data-ar="الاختبار">Quiz</th>
                                        <th data-en="Assignment" data-ar="الواجب">Assignment</th>
                                        <th data-en="Project" data-ar="المشروع">Project</th>
                                        <th data-en="Total" data-ar="المجموع">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;
                
                classData.students.forEach(student => {
                    student.subjects.forEach((subject, index) => {
                        html += `
                            <tr>
                                ${index === 0 ? `<td rowspan="${student.subjects.length}">${escapeHtml(student.student_name)}</td>` : ''}
                                <td>${escapeHtml(subject.course_name)}</td>
                                <td>${subject.midterm}</td>
                                <td>${subject.final}</td>
                                <td>${subject.quiz}</td>
                                <td>${subject.assignment}</td>
                                <td>${subject.project}</td>
                                <td><strong>${subject.total}</strong></td>
                            </tr>
                        `;
                    });
                });
                
                html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            container.innerHTML = html;
        }

        function renderAttendanceReport(data) {
            const container = document.getElementById('reportContent');
            let html = '<div class="report-content">';
            
            data.forEach(classData => {
                html += `
                    <div class="class-section">
                        <h2 class="class-header">${escapeHtml(classData.class_name)}</h2>
                        <div class="table-container">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th data-en="Student Name" data-ar="اسم الطالب">Student Name</th>
                                        <th data-en="Total Present" data-ar="إجمالي الحضور">Total Present</th>
                                        <th data-en="Total Absent" data-ar="إجمالي الغياب">Total Absent</th>
                                        <th data-en="Total Late" data-ar="إجمالي التأخير">Total Late</th>
                                        <th data-en="Attendance Percentage" data-ar="نسبة الحضور">Attendance Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;
                
                classData.students.forEach(student => {
                    html += `
                        <tr>
                            <td>${escapeHtml(student.student_name)}</td>
                            <td>${student.total_present}</td>
                            <td>${student.total_absent}</td>
                            <td>${student.total_late}</td>
                            <td><strong>${student.attendance_percentage}%</strong></td>
                        </tr>
                    `;
                });
                
                html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            container.innerHTML = html;
        }

        function renderAssignmentsReport(data) {
            const container = document.getElementById('reportContent');
            let html = '<div class="report-content">';
            
            data.forEach(classData => {
                html += `
                    <div class="class-section">
                        <h2 class="class-header">${escapeHtml(classData.class_name)}</h2>
                `;
                
                if (classData.assignments && classData.assignments.length > 0) {
                    classData.assignments.forEach(assignment => {
                        html += `
                            <div class="assignment-item">
                                <div class="assignment-header">${escapeHtml(assignment.title)}</div>
                                <div class="assignment-meta">
                                    <strong data-en="Subject" data-ar="المادة">Subject:</strong> ${escapeHtml(assignment.subject)} | 
                                    <strong data-en="Deadline" data-ar="الموعد النهائي">Deadline:</strong> ${formatDate(assignment.deadline)}
                                </div>
                                <div class="table-container">
                                    <table class="report-table">
                                        <thead>
                                            <tr>
                                                <th data-en="Student Name" data-ar="اسم الطالب">Student Name</th>
                                                <th data-en="Submission Status" data-ar="حالة التقديم">Submission Status</th>
                                                <th data-en="Submission Date" data-ar="تاريخ التقديم">Submission Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                        `;
                        
                        assignment.students.forEach(student => {
                            const statusClass = student.submission_status === 'Submitted' ? 'status-submitted' : 
                                               student.submission_status === 'Late' ? 'status-late' : 'status-not-submitted';
                            html += `
                                <tr>
                                    <td>${escapeHtml(student.student_name)}</td>
                                    <td>
                                        <span class="status-badge ${statusClass}">
                                            ${escapeHtml(student.submission_status)}
                                        </span>
                                    </td>
                                    <td>${student.submission_date ? formatDate(student.submission_date) : '-'}</td>
                                </tr>
                            `;
                        });
                        
                        html += `
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        `;
                    });
                } else {
                    html += `
                        <div class="empty-state">
                            <div class="empty-state-icon">📝</div>
                            <p data-en="No assignments found for this class." data-ar="لم يتم العثور على واجبات لهذا الفصل.">No assignments found for this class.</p>
                        </div>
                    `;
                }
                
                html += `</div>`;
            });
            
            html += '</div>';
            container.innerHTML = html;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDate(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const reportTypeFilter = document.getElementById('reportTypeFilter');
            const classFilter = document.getElementById('classFilter');

            if (reportTypeFilter) {
                reportTypeFilter.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        applyFilters();
                    }
                });
            }
            
            if (classFilter) {
                classFilter.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        applyFilters();
                    }
                });
            }
        });

    </script>
    <script src="script.js"></script>
    <script src="script.js"></script>
</body>
</html>
