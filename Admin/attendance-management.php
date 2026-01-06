<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('admin');

$grades = [];
$sections = [];

try {
    $stmt = $pdo->query("SELECT DISTINCT Grade_Level FROM class WHERE Grade_Level IS NOT NULL ORDER BY Grade_Level ASC");
    $grades = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $stmt = $pdo->query("SELECT DISTINCT Section FROM class WHERE Section IS NOT NULL ORDER BY Section ASC");
    $sections = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Error loading filter options: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Management - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
        .class-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .class-card:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        
        .class-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, #FFE5E5 0%, #FFD6D6 100%);
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.3s ease;
        }
        
        .class-header:hover {
            background: linear-gradient(135deg, #FFD6D6 0%, #FFC6C6 100%);
        }
        
        .class-header.expanded {
            border-bottom: 2px solid #FF6B6B;
        }
        
        .class-info {
            flex: 1;
        }
        
        .class-name {
            font-size: 1.25rem;
            font-weight: 600;
            color: #2C3E50;
            margin-bottom: 0.5rem;
        }
        
        .class-stats {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            margin-top: 0.5rem;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }
        
        .stat-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        
        .stat-present { background: #D4EDDA; color: #155724; }
        .stat-absent { background: #F8D7DA; color: #721C24; }
        .stat-late { background: #FFF3CD; color: #856404; }
        .stat-excused { background: #D1ECF1; color: #0C5460; }
        .stat-total { background: #E2E3E5; color: #383D41; }
        
        .class-toggle {
            font-size: 1.5rem;
            color: #FF6B6B;
            transition: transform 0.3s ease;
        }
        
        .class-toggle.expanded {
            transform: rotate(180deg);
        }
        
        .class-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        
        .class-content.expanded {
            max-height: 5000px;
        }
        
        .class-actions {
            padding: 1rem 1.5rem;
            background: #F8F9FA;
            border-top: 1px solid #E9ECEF;
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        
        .student-table-container {
            padding: 1.5rem;
            overflow-x: auto;
        }
        
        .status-select {
            padding: 0.5rem;
            border-radius: 8px;
            border: 2px solid #FFE5E5;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .status-select:focus {
            outline: none;
            border-color: #FF6B6B;
        }
        
        .status-present { background: #D4EDDA; color: #155724; }
        .status-absent { background: #F8D7DA; color: #721C24; }
        .status-late { background: #FFF3CD; color: #856404; }
        .status-excused { background: #D1ECF1; color: #0C5460; }
        
        .attendance-note {
            padding: 0.5rem;
            border-radius: 8px;
            border: 2px solid #FFE5E5;
            font-size: 0.9rem;
            width: 100%;
            min-width: 200px;
        }
        
        .attendance-note:focus {
            outline: none;
            border-color: #FF6B6B;
        }
        
        .last-updated {
            font-size: 0.85rem;
            color: #6C757D;
            font-style: italic;
        }
        
        .no-students {
            text-align: center;
            padding: 2rem;
            color: #6C757D;
        }
        
        .loading-spinner {
            text-align: center;
            padding: 2rem;
            color: #FF6B6B;
        }
        
        .loading-spinner i {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .summary-card {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 1.5rem;
        }
        
        .summary-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }
        
        .summary-stat {
            text-align: center;
        }
        
        .summary-stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .summary-stat-label {
            font-size: 0.85rem;
            opacity: 0.9;
        }
        
        @media (max-width: 768px) {
            .class-stats {
                gap: 1rem;
            }
            
            .class-actions {
                flex-direction: column;
            }
            
            .class-actions .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">✅</span>
                <span data-en="Attendance Management" data-ar="إدارة الحضور">Attendance Management</span>
            </h1>
            <p class="page-subtitle" data-en="Manage attendance for all classes and students across the entire system" data-ar="إدارة الحضور لجميع الصفوف والطلاب في النظام">Manage attendance for all classes and students across the entire system</p>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">🔍</span>
                    <span data-en="Filters" data-ar="المرشحات">Filters</span>
                </h2>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label data-en="Date" data-ar="التاريخ">Date</label>
                    <input type="date" id="attendanceDate" value="<?php echo date('Y-m-d'); ?>" onchange="loadClasses()">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label data-en="Grade" data-ar="الصف">Grade</label>
                    <select id="gradeFilter" onchange="loadClasses()">
                        <option value="all" data-en="All Grades" data-ar="جميع الصفوف">All Grades</option>
                        <?php foreach ($grades as $g): ?>
                            <option value="<?php echo htmlspecialchars($g); ?>" data-en="Grade <?php echo htmlspecialchars($g); ?>" data-ar="الصف <?php echo htmlspecialchars($g); ?>">Grade <?php echo htmlspecialchars($g); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label data-en="Section" data-ar="القسم">Section</label>
                    <select id="sectionFilter" onchange="loadClasses()">
                        <option value="all" data-en="All Sections" data-ar="جميع الأقسام">All Sections</option>
                        <?php foreach ($sections as $s): ?>
                            <option value="<?php echo htmlspecialchars($s); ?>" data-en="Section <?php echo htmlspecialchars($s); ?>" data-ar="القسم <?php echo htmlspecialchars($s); ?>">Section <?php echo htmlspecialchars($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label data-en="Status Filter" data-ar="تصفية الحالة">Status Filter</label>
                    <select id="statusFilter" onchange="loadClasses()">
                        <option value="all" data-en="All" data-ar="الكل">All</option>
                        <option value="present" data-en="Present" data-ar="حاضر">Present</option>
                        <option value="absent" data-en="Absent" data-ar="غائب">Absent</option>
                        <option value="late" data-en="Late" data-ar="متأخر">Late</option>
                        <option value="excused" data-en="Excused" data-ar="معذور">Excused</option>
                    </select>
                </div>
            </div>
            <div class="search-filter-bar" style="margin-top: 1rem;">
                <button class="btn btn-primary" onclick="generateDailyReport()" data-en="Daily Report" data-ar="تقرير يومي">Daily Report</button>
                <button class="btn btn-secondary" onclick="loadClasses()" data-en="Refresh" data-ar="تحديث">Refresh</button>
            </div>
        </div>

        <div id="dailySummary" class="summary-card" style="display: none;">
            <div class="summary-title" data-en="Daily Attendance Summary" data-ar="ملخص الحضور اليومي">Daily Attendance Summary</div>
            <div class="summary-stats" id="summaryStats"></div>
        </div>

        <div id="classesContainer">
            <div class="loading-spinner">
                <i class="fas fa-spinner"></i>
                <p data-en="Loading classes..." data-ar="جاري تحميل الصفوف...">Loading classes...</p>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        let expandedClasses = new Set();
        let currentDate = document.getElementById('attendanceDate').value;

        function loadClasses() {
            const date = document.getElementById('attendanceDate').value;
            const grade = document.getElementById('gradeFilter').value;
            const section = document.getElementById('sectionFilter').value;
            const statusFilter = document.getElementById('statusFilter').value;
            
            currentDate = date;
            
            const container = document.getElementById('classesContainer');
            container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner"></i><p data-en="Loading classes..." data-ar="جاري تحميل الصفوف...">Loading classes...</p></div>';
            
            const formData = new FormData();
            formData.append('action', 'loadClasses');
            formData.append('date', date);
            formData.append('grade', grade);
            formData.append('section', section);
            formData.append('statusFilter', statusFilter);
            
            fetch('attendance-management-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderClasses(data.classes);
                } else {
                    container.innerHTML = `<div class="no-students">${data.message || 'Error loading classes'}</div>`;
                    showNotification(data.message || 'Error loading classes', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                container.innerHTML = '<div class="no-students">Error loading classes. Please try again.</div>';
                showNotification('Error loading classes', 'error');
            });
        }

        function renderClasses(classes) {
            const container = document.getElementById('classesContainer');
            
            if (classes.length === 0) {
                container.innerHTML = '<div class="no-students" data-en="No classes found" data-ar="لا توجد صفوف">No classes found</div>';
                return;
            }
            
            container.innerHTML = classes.map(classData => {
                const classId = classData.Class_ID;
                const isExpanded = expandedClasses.has(classId);
                const total = parseInt(classData.total_students) || 0;
                const present = parseInt(classData.present_count) || 0;
                const absent = parseInt(classData.absent_count) || 0;
                const late = parseInt(classData.late_count) || 0;
                const excused = parseInt(classData.excused_count) || 0;
                const recorded = parseInt(classData.recorded_count) || 0;
                
                return `
                    <div class="class-card" data-class-id="${classId}">
                        <div class="class-header ${isExpanded ? 'expanded' : ''}" onclick="toggleClass(${classId})">
                            <div class="class-info">
                                <div class="class-name">${escapeHtml(classData.Class_Name)}</div>
                                <div class="class-stats">
                                    <div class="stat-item">
                                        <span class="stat-badge stat-total">${total}</span>
                                        <span data-en="Total" data-ar="الإجمالي">Total</span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-badge stat-present">${present}</span>
                                        <span data-en="Present" data-ar="حاضر">Present</span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-badge stat-absent">${absent}</span>
                                        <span data-en="Absent" data-ar="غائب">Absent</span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-badge stat-late">${late}</span>
                                        <span data-en="Late" data-ar="متأخر">Late</span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-badge stat-excused">${excused}</span>
                                        <span data-en="Excused" data-ar="معذور">Excused</span>
                                    </div>
                                    <div class="stat-item">
                                        <span style="color: #6C757D; font-size: 0.85rem;">${recorded}/${total} <span data-en="Recorded" data-ar="مسجل">Recorded</span></span>
                                    </div>
                                </div>
                            </div>
                            <div class="class-toggle ${isExpanded ? 'expanded' : ''}">
                                <i class="fas fa-chevron-down"></i>
                            </div>
                        </div>
                        <div class="class-content ${isExpanded ? 'expanded' : ''}" id="class-content-${classId}">
                            ${isExpanded ? '<div class="loading-spinner"><i class="fas fa-spinner"></i><p data-en="Loading students..." data-ar="جاري تحميل الطلاب...">Loading students...</p></div>' : ''}
                        </div>
                    </div>
                `;
            }).join('');

            expandedClasses.forEach(classId => {
                loadStudents(classId);
            });
        }

        function toggleClass(classId) {
            const isExpanded = expandedClasses.has(classId);
            const contentDiv = document.getElementById(`class-content-${classId}`);
            
            if (isExpanded) {
                expandedClasses.delete(classId);
            } else {
                expandedClasses.add(classId);
                if (contentDiv && !contentDiv.querySelector('.student-table-container')) {
                    loadStudents(classId);
                }
            }

            const date = document.getElementById('attendanceDate').value;
            const grade = document.getElementById('gradeFilter').value;
            const section = document.getElementById('sectionFilter').value;
            const statusFilter = document.getElementById('statusFilter').value;
            
            const formData = new FormData();
            formData.append('action', 'loadClasses');
            formData.append('date', date);
            formData.append('grade', grade);
            formData.append('section', section);
            formData.append('statusFilter', statusFilter);
            
            fetch('attendance-management-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderClasses(data.classes);
                }
            });
        }

        function loadStudents(classId) {
            const contentDiv = document.getElementById(`class-content-${classId}`);
            if (!contentDiv) return;
            
            const date = document.getElementById('attendanceDate').value;
            
            const formData = new FormData();
            formData.append('action', 'loadStudents');
            formData.append('classId', classId);
            formData.append('date', date);
            
            fetch('attendance-management-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderStudents(classId, data.students);
                } else {
                    contentDiv.innerHTML = `<div class="no-students">${data.message || 'Error loading students'}</div>`;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                contentDiv.innerHTML = '<div class="no-students">Error loading students</div>';
            });
        }

        function renderStudents(classId, students) {
            const contentDiv = document.getElementById(`class-content-${classId}`);
            if (!contentDiv) return;
            
            if (students.length === 0) {
                contentDiv.innerHTML = '<div class="no-students" data-en="No students in this class" data-ar="لا يوجد طلاب في هذا الصف">No students in this class</div>';
                return;
            }
            
            const tableHTML = `
                <div class="student-table-container">
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th data-en="Student Name" data-ar="اسم الطالب">Student Name</th>
                                    <th data-en="Student ID" data-ar="رقم الطالب">Student ID</th>
                                    <th data-en="Status" data-ar="الحالة">Status</th>
                                    <th data-en="Notes" data-ar="ملاحظة">Notes</th>
                                    <th data-en="Last Updated" data-ar="آخر تحديث">Last Updated</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${students.map(student => {
                                    const status = student.Status || 'Present';
                                    const statusClass = `status-${status.toLowerCase()}`;
                                    const lastUpdated = student.Last_Updated ? new Date(student.Last_Updated).toLocaleString() : '-';
                                    
                                    return `
                                        <tr>
                                            <td>${escapeHtml(student.NameEn || student.NameAr || 'N/A')}</td>
                                            <td>${escapeHtml(student.Student_Code || 'N/A')}</td>
                                            <td>
                                                <select class="status-select ${statusClass}" 
                                                        onchange="updateAttendance(${student.Student_ID}, ${classId}, this.value)"
                                                        data-student-id="${student.Student_ID}">
                                                    <option value="Present" ${status === 'Present' ? 'selected' : ''} data-en="Present" data-ar="حاضر">Present</option>
                                                    <option value="Absent" ${status === 'Absent' ? 'selected' : ''} data-en="Absent" data-ar="غائب">Absent</option>
                                                    <option value="Late" ${status === 'Late' ? 'selected' : ''} data-en="Late" data-ar="متأخر">Late</option>
                                                    <option value="Excused" ${status === 'Excused' ? 'selected' : ''} data-en="Excused" data-ar="معذور">Excused</option>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" 
                                                       class="attendance-note" 
                                                       value="${escapeHtml(student.Notes || '')}" 
                                                       placeholder="Add note..."
                                                       onchange="updateAttendanceNote(${student.Student_ID}, ${classId}, this.value)"
                                                       data-student-id="${student.Student_ID}">
                                            </td>
                                            <td>
                                                <span class="last-updated">${lastUpdated}</span>
                                            </td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="class-actions">
                    <button class="btn btn-primary" onclick="bulkMarkAttendance(${classId}, 'Present')" data-en="Mark All Present" data-ar="تسجيل الكل حاضر">Mark All Present</button>
                    <button class="btn btn-secondary" onclick="bulkMarkAttendance(${classId}, 'Absent')" data-en="Mark All Absent" data-ar="تسجيل الكل غائب">Mark All Absent</button>
                    <button class="btn btn-secondary" onclick="bulkMarkAttendance(${classId}, 'Late')" data-en="Mark All Late" data-ar="تسجيل الكل متأخر">Mark All Late</button>
                    <button class="btn btn-secondary" onclick="bulkMarkAttendance(${classId}, 'Excused')" data-en="Mark All Excused" data-ar="تسجيل الكل معذور">Mark All Excused</button>
                </div>
            `;
            
            contentDiv.innerHTML = tableHTML;
        }

        function updateAttendance(studentId, classId, status) {
            const date = document.getElementById('attendanceDate').value;

            const noteInput = document.querySelector(`input[data-student-id="${studentId}"]`);
            const notes = noteInput ? noteInput.value : '';
            
            const formData = new FormData();
            formData.append('action', 'saveAttendance');
            formData.append('studentId', studentId);
            formData.append('classId', classId);
            formData.append('date', date);
            formData.append('status', status);
            formData.append('notes', notes);
            
            fetch('attendance-management-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    
                    const select = document.querySelector(`select[data-student-id="${studentId}"]`);
                    if (select) {
                        select.className = `status-select status-${status.toLowerCase()}`;
                    }

                    if (data.attendance && data.attendance.Last_Updated) {
                        const row = select?.closest('tr');
                        if (row) {
                            const lastUpdatedCell = row.querySelector('.last-updated');
                            if (lastUpdatedCell) {
                                lastUpdatedCell.textContent = new Date(data.attendance.Last_Updated).toLocaleString();
                            }
                        }
                    }

                    loadClasses();
                } else {
                    showNotification(data.message || 'Error saving attendance', 'error');
                    
                    loadStudents(classId);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error saving attendance', 'error');
                loadStudents(classId);
            });
        }

        function updateAttendanceNote(studentId, classId, note) {
            const date = document.getElementById('attendanceDate').value;

            const select = document.querySelector(`select[data-student-id="${studentId}"]`);
            const status = select ? select.value : 'Present';
            
            const formData = new FormData();
            formData.append('action', 'saveAttendance');
            formData.append('studentId', studentId);
            formData.append('classId', classId);
            formData.append('date', date);
            formData.append('status', status);
            formData.append('notes', note);
            
            fetch('attendance-management-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    
                    if (data.attendance && data.attendance.Last_Updated) {
                        const input = document.querySelector(`input[data-student-id="${studentId}"]`);
                        if (input) {
                            const row = input.closest('tr');
                            if (row) {
                                const lastUpdatedCell = row.querySelector('.last-updated');
                                if (lastUpdatedCell) {
                                    lastUpdatedCell.textContent = new Date(data.attendance.Last_Updated).toLocaleString();
                                }
                            }
                        }
                    }
                } else {
                    showNotification(data.message || 'Error saving note', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error saving note', 'error');
            });
        }

        function bulkMarkAttendance(classId, status) {
            if (!confirm(`Are you sure you want to mark all students as ${status}?`)) {
                return;
            }
            
            const date = document.getElementById('attendanceDate').value;
            
            const formData = new FormData();
            formData.append('action', 'bulkMarkAttendance');
            formData.append('classId', classId);
            formData.append('date', date);
            formData.append('status', status);
            
            fetch('attendance-management-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message || 'Bulk attendance saved successfully', 'success');
                    loadStudents(classId);
                    loadClasses();
                } else {
                    showNotification(data.message || 'Error saving bulk attendance', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error saving bulk attendance', 'error');
            });
        }

        function generateDailyReport() {
            const date = document.getElementById('attendanceDate').value;
            const grade = document.getElementById('gradeFilter').value;
            const section = document.getElementById('sectionFilter').value;
            
            const formData = new FormData();
            formData.append('action', 'getDailyReport');
            formData.append('date', date);
            formData.append('grade', grade);
            formData.append('section', section);
            
            fetch('attendance-management-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const summary = document.getElementById('dailySummary');
                    const stats = document.getElementById('summaryStats');
                    
                    const totals = data.totals;
                    stats.innerHTML = `
                        <div class="summary-stat">
                            <div class="summary-stat-value">${totals.total_students}</div>
                            <div class="summary-stat-label" data-en="Total Students" data-ar="إجمالي الطلاب">Total Students</div>
                        </div>
                        <div class="summary-stat">
                            <div class="summary-stat-value">${totals.present}</div>
                            <div class="summary-stat-label" data-en="Present" data-ar="حاضر">Present</div>
                        </div>
                        <div class="summary-stat">
                            <div class="summary-stat-value">${totals.absent}</div>
                            <div class="summary-stat-label" data-en="Absent" data-ar="غائب">Absent</div>
                        </div>
                        <div class="summary-stat">
                            <div class="summary-stat-value">${totals.late}</div>
                            <div class="summary-stat-label" data-en="Late" data-ar="متأخر">Late</div>
                        </div>
                        <div class="summary-stat">
                            <div class="summary-stat-value">${totals.excused}</div>
                            <div class="summary-stat-label" data-en="Excused" data-ar="معذور">Excused</div>
                        </div>
                        <div class="summary-stat">
                            <div class="summary-stat-value">${totals.recorded_count}/${totals.total_students}</div>
                            <div class="summary-stat-label" data-en="Recorded" data-ar="مسجل">Recorded</div>
                        </div>
                    `;
                    
                    summary.style.display = 'block';
                    summary.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                } else {
                    showNotification(data.message || 'Error generating report', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error generating report', 'error');
            });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadClasses();
        });
    </script>
</body>
</html>
