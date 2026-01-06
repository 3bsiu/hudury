<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-teacher.php';

requireUserType('teacher');

$currentTeacherId = getCurrentUserId();
if (!$currentTeacherId || !is_numeric($currentTeacherId)) {
    error_log("CRITICAL: Invalid teacher ID in assignments.php");
    header('Location: teacher-dashboard.php');
    exit();
}

$currentTeacher = getCurrentUserData($pdo);
$teacherName = $_SESSION['user_name'] ?? 'Teacher';

$teacherClasses = [];
$teacherCourses = [];
$teacherAssignmentsList = []; 

try {
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM teacher WHERE Teacher_ID = ?");
    $stmt->execute([$currentTeacherId]);
    $teacherExists = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$teacherExists || $teacherExists['count'] == 0) {
        error_log("CRITICAL: Teacher ID {$currentTeacherId} does not exist in database");
        throw new Exception("Invalid teacher account");
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT c.Class_ID, c.Name as Class_Name
        FROM teacher_class_course tcc
        INNER JOIN class c ON tcc.Class_ID = c.Class_ID
        WHERE tcc.Teacher_ID = ?
        ORDER BY c.Grade_Level, c.Section
    ");
    $stmt->execute([$currentTeacherId]);
    $teacherClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT DISTINCT a.Assignment_ID, a.Title
        FROM assignment a
        WHERE a.Teacher_ID = ? AND a.Status != 'cancelled'
        ORDER BY a.Title
    ");
    $stmt->execute([$currentTeacherId]);
    $teacherAssignmentsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("CRITICAL Database Error in assignments.php (teacher data): " . $e->getMessage());
    error_log("SQL Error Code: " . $e->getCode());
    error_log("Teacher ID: " . $currentTeacherId);
    
    $teacherClasses = [];
    $teacherAssignmentsList = [];
} catch (Exception $e) {
    error_log("CRITICAL Error in assignments.php: " . $e->getMessage());
    $teacherClasses = [];
    $teacherAssignmentsList = [];
}

$dashboardStats = [
    'total_assignments' => 0,
    'total_students_submitted' => 0
];

try {
    
    if (!$currentTeacherId || !is_numeric($currentTeacherId)) {
        throw new Exception("Invalid teacher ID");
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT Assignment_ID) as total
        FROM assignment
        WHERE Teacher_ID = ? AND (Status IS NULL OR Status != 'cancelled')
    ");
    $stmt->execute([$currentTeacherId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $dashboardStats['total_assignments'] = intval($result['total'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT s.Student_ID) as total
        FROM submission s
        INNER JOIN assignment a ON s.Assignment_ID = a.Assignment_ID
        WHERE a.Teacher_ID = ?
    ");
    $stmt->execute([$currentTeacherId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $dashboardStats['total_students_submitted'] = intval($result['total'] ?? 0);
    
} catch (PDOException $e) {
    error_log("CRITICAL Database Error in assignments.php (dashboard stats): " . $e->getMessage());
    error_log("SQL Error Code: " . $e->getCode());
    error_log("SQL State: " . $e->errorInfo[0] ?? 'N/A');
    
} catch (Exception $e) {
    error_log("CRITICAL Error calculating dashboard stats: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Submissions - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        .mini-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .stat-box {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
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
            font-size: 0.95rem;
        }
        .filters-section {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        .submissions-table-container {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow-x: auto;
        }
        .submissions-table {
            width: 100%;
            border-collapse: collapse;
        }
        .submissions-table thead {
            background: var(--bg-light);
        }
        .submissions-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 700;
            color: var(--text-dark);
            border-bottom: 2px solid var(--bg-pink);
        }
        .submissions-table td {
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
        }
        .submissions-table tbody tr {
            transition: all 0.3s;
        }
        .submissions-table tbody tr:hover {
            background: var(--bg-light);
        }
        .status-badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 15px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .status-submitted {
            background: #FFF9E5;
            color: #FFA500;
        }
        .status-graded {
            background: #E5FFE5;
            color: #6BCB77;
        }
        .status-late {
            background: #FFE5E5;
            color: #FF6B9D;
        }
        .action-buttons-cell {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .btn-view {
            background: var(--accent-blue);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s;
        }
        .btn-view:hover {
            background: #4A90E2;
            transform: translateY(-2px);
        }
        .btn-grade {
            background: var(--primary-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s;
        }
        .btn-grade:hover {
            background: var(--secondary-color);
            transform: translateY(-2px);
        }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #999;
        }
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        @media (max-width: 768px) {
            .submissions-table {
                font-size: 0.85rem;
            }
            .submissions-table th,
            .submissions-table td {
                padding: 0.7rem 0.5rem;
            }
            .action-buttons-cell {
                flex-direction: column;
            }
            .action-buttons-cell .btn {
                width: 100%;
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
                <span class="page-icon">📋</span>
                <span data-en="Student Submissions" data-ar="تقديمات الطلاب">Student Submissions</span>
            </h1>
            <p class="page-subtitle" data-en="View and grade student assignment submissions" data-ar="عرض وتصحيح تقديمات واجبات الطلاب">View and grade student assignment submissions</p>
        </div>

        <div class="mini-dashboard">
            <div class="stat-box">
                <div class="stat-icon">📝</div>
                <div class="stat-value"><?php echo $dashboardStats['total_assignments']; ?></div>
                <div class="stat-label" data-en="Total Assignments" data-ar="إجمالي الواجبات">Total Assignments</div>
            </div>
            <div class="stat-box">
                <div class="stat-icon">👥</div>
                <div class="stat-value"><?php echo $dashboardStats['total_students_submitted']; ?></div>
                <div class="stat-label" data-en="Total Students Submitted" data-ar="إجمالي الطلاب المقدمين">Total Students Submitted</div>
            </div>
        </div>

        <div class="filters-section">
            <h3 style="margin-bottom: 1rem;" data-en="Filters" data-ar="التصفية">Filters</h3>
            <div class="filters-grid">
                
                <div class="form-group" style="margin-bottom: 0;">
                    <label data-en="Filter by Class" data-ar="تصفية حسب الفصل">Filter by Class</label>
                    <select id="classFilter" onchange="loadSubmissions()">
                        <option value="all" data-en="All Classes" data-ar="جميع الفصول">All Classes</option>
                        <?php foreach ($teacherClasses as $class): ?>
                            <option value="<?php echo $class['Class_ID']; ?>">
                                <?php echo htmlspecialchars($class['Class_Name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label data-en="Filter by Assignment" data-ar="تصفية حسب الواجب">Filter by Assignment</label>
                    <select id="assignmentFilter" onchange="loadSubmissions()">
                        <option value="all" data-en="All Assignments" data-ar="جميع الواجبات">All Assignments</option>
                        <?php foreach ($teacherAssignmentsList as $assignment): ?>
                            <option value="<?php echo $assignment['Assignment_ID']; ?>">
                                <?php echo htmlspecialchars($assignment['Title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label data-en="Filter by Student Name" data-ar="تصفية حسب اسم الطالب">Filter by Student Name</label>
                    <input type="text" id="studentNameFilter" placeholder="Enter student name..." data-placeholder-en="Enter student name..." data-placeholder-ar="أدخل اسم الطالب..." oninput="loadSubmissions()">
                </div>
            </div>
        </div>

        <div class="submissions-table-container">
            <div id="submissionsContainer">
                <div class="empty-state">
                    <i class="fas fa-spinner fa-spin"></i>
                    <div data-en="Loading submissions..." data-ar="جاري تحميل التقديمات...">Loading submissions...</div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="viewModal" role="dialog">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h2 class="modal-title" data-en="View Submission" data-ar="عرض التقديم">View Submission</h2>
                <button class="modal-close" onclick="closeModal('viewModal')">&times;</button>
            </div>
            <div id="viewModalContent">
                
            </div>
        </div>
    </div>

    <div class="modal" id="gradeModal" role="dialog">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h2 class="modal-title" data-en="Grade Assignment" data-ar="تصحيح الواجب">Grade Assignment</h2>
                <button class="modal-close" onclick="closeModal('gradeModal')">&times;</button>
            </div>
            <div id="gradeModalContent">
                
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        let submissionsData = [];
        
        function loadSubmissions() {
            const classId = document.getElementById('classFilter').value;
            const assignmentId = document.getElementById('assignmentFilter').value;
            const studentName = document.getElementById('studentNameFilter').value.trim();
            
            const params = new URLSearchParams();
            params.append('action', 'getSubmissions');
            if (classId !== 'all') params.append('class_id', classId);
            if (assignmentId !== 'all') params.append('assignment_id', assignmentId);
            if (studentName) params.append('student_name', studentName);
            
            document.getElementById('submissionsContainer').innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-spinner fa-spin"></i>
                    <div data-en="Loading submissions..." data-ar="جاري تحميل التقديمات...">Loading submissions...</div>
                </div>
            `;
            
            fetch('assignments-ajax.php?' + params.toString())
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Submissions API Response:', data);
                    
                    if (data.success) {
                        submissionsData = data.submissions || [];
                        console.log('Loaded submissions:', submissionsData.length);
                        if (data.debug) {
                            console.log('Debug info:', data.debug);
                        }
                        renderSubmissions();
                    } else {
                        console.error('API Error:', data);
                        console.error('Error Message:', data.message);
                        console.error('Error Details:', data.error);
                        console.error('SQL Error:', data.sql_error);
                        console.error('SQL State:', data.sql_state);

                        let errorMsg = data.message || (currentLanguage === 'en' ? 'Error loading submissions' : 'خطأ في تحميل التقديمات');
                        if (data.sql_error) {
                            errorMsg += '\nSQL Error: ' + data.sql_error;
                        }
                        showNotification(errorMsg, 'error');
                        submissionsData = [];
                        renderSubmissions();
                    }
                })
                .catch(error => {
                    console.error('Fetch Error:', error);
                    showNotification(currentLanguage === 'en' ? 'Error loading submissions: ' + error.message : 'خطأ في تحميل التقديمات: ' + error.message, 'error');
                    submissionsData = [];
                    renderSubmissions();
                });
        }
        
        function renderSubmissions() {
            const container = document.getElementById('submissionsContainer');
            
            console.log('Rendering submissions, count:', submissionsData.length);
            
            if (submissionsData.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <div data-en="No submissions found" data-ar="لا توجد تقديمات">No submissions found</div>
                        <div style="margin-top: 1rem; font-size: 0.85rem; color: #999;" data-en="Check console for debug information" data-ar="تحقق من وحدة التحكم للحصول على معلومات التصحيح">Check console for debug information</div>
                    </div>
                `;
                return;
            }
            
            let html = `
                <table class="submissions-table">
                    <thead>
                        <tr>
                            <th data-en="Student Name" data-ar="اسم الطالب">Student Name</th>
                            <th data-en="Class" data-ar="الفصل">Class</th>
                            <th data-en="Subject" data-ar="المادة">Subject</th>
                            <th data-en="Assignment Name" data-ar="اسم الواجب">Assignment Name</th>
                            <th data-en="Submission Date" data-ar="تاريخ التقديم">Submission Date</th>
                            <th data-en="Status" data-ar="الحالة">Status</th>
                            <th data-en="Grade" data-ar="الدرجة">Grade</th>
                            <th data-en="Actions" data-ar="الإجراءات">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            submissionsData.forEach(sub => {
                const submissionDate = new Date(sub.Submission_Date);
                const formattedDate = submissionDate.toLocaleDateString() + ' ' + submissionDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
                
                let statusClass = 'status-submitted';
                let statusText = currentLanguage === 'en' ? 'Submitted' : 'تم التقديم';
                
                if (sub.Status === 'graded') {
                    statusClass = 'status-graded';
                    statusText = currentLanguage === 'en' ? 'Graded' : 'مصحح';
                } else if (sub.Status === 'late') {
                    statusClass = 'status-late';
                    statusText = currentLanguage === 'en' ? 'Late' : 'متأخر';
                }
                
                html += `
                    <tr>
                        <td><strong>${sub.Student_Name || 'N/A'}</strong><br><small style="color: #666;">${sub.Student_Code || ''}</small></td>
                        <td>${sub.Class_Name || 'N/A'}</td>
                        <td>${sub.Course_Name || 'N/A'}</td>
                        <td>${sub.Assignment_Title || 'N/A'}</td>
                        <td>${formattedDate}</td>
                        <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                        <td><strong style="color: var(--primary-color);">${sub.Grade !== null ? parseFloat(sub.Grade).toFixed(1) : '-'}</strong></td>
                        <td class="action-buttons-cell">
                            <button class="btn-view" onclick="viewSubmission(${sub.Submission_ID})" data-en="View" data-ar="عرض">
                                <i class="fas fa-eye"></i> <span data-en="View" data-ar="عرض">View</span>
                            </button>
                            <button class="btn-grade" onclick="openGradeModal(${sub.Submission_ID})" data-en="Grade" data-ar="تصحيح">
                                <i class="fas fa-check"></i> <span data-en="Grade" data-ar="تصحيح">Grade</span>
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            html += `
                    </tbody>
                </table>
            `;
            
            container.innerHTML = html;
        }
        
        function viewSubmission(submissionId) {
            const submission = submissionsData.find(s => s.Submission_ID == submissionId);
            if (!submission) {
                showNotification(currentLanguage === 'en' ? 'Submission not found' : 'التقديم غير موجود', 'error');
                return;
            }

            fetch(`assignments-ajax.php?action=getSubmissionDetails&submission_id=${submissionId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayViewModal(data.submission);
                    } else {
                        showNotification(data.message || (currentLanguage === 'en' ? 'Error loading submission' : 'خطأ في تحميل التقديم'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification(currentLanguage === 'en' ? 'Error loading submission' : 'خطأ في تحميل التقديم', 'error');
                });
        }
        
        function displayViewModal(submission) {
            const submissionDate = new Date(submission.Submission_Date);
            const formattedDate = submissionDate.toLocaleDateString() + ' ' + submissionDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
            
            let fileSection = '';
            if (submission.Submission_ID && submission.File_Name) {
                const fileUrl = `../includes/file-serve.php?type=submission&id=${submission.Submission_ID}`;
                fileSection = `
                    <div class="form-group">
                        <label data-en="Submitted File" data-ar="الملف المقدم">Submitted File</label>
                        <div style="padding: 1rem; background: var(--bg-light); border-radius: 10px;">
                            <i class="fas fa-file"></i>
                            <a href="${fileUrl}" target="_blank" style="color: var(--primary-color); text-decoration: none; margin-left: 0.5rem;">
                                ${submission.File_Name}
                            </a>
                            <a href="${fileUrl}" download style="margin-left: 1rem; color: var(--accent-blue);">
                                <i class="fas fa-download"></i> <span data-en="Download" data-ar="تحميل">Download</span>
                            </a>
                        </div>
                    </div>
                `;
            }
            
            document.getElementById('viewModalContent').innerHTML = `
                <div style="padding: 1.5rem;">
                    <div class="form-group">
                        <label data-en="Student" data-ar="الطالب">Student</label>
                        <input type="text" value="${submission.Student_Name || 'Unknown'} (${submission.Student_Code || 'N/A'})" readonly>
                    </div>
                    <div class="form-group">
                        <label data-en="Class" data-ar="الفصل">Class</label>
                        <input type="text" value="${submission.Class_Name || 'N/A'}" readonly>
                    </div>
                    <div class="form-group">
                        <label data-en="Subject" data-ar="المادة">Subject</label>
                        <input type="text" value="${submission.Course_Name || 'N/A'}" readonly>
                    </div>
                    <div class="form-group">
                        <label data-en="Assignment" data-ar="الواجب">Assignment</label>
                        <input type="text" value="${submission.Assignment_Title || 'N/A'}" readonly>
                    </div>
                    <div class="form-group">
                        <label data-en="Submission Date" data-ar="تاريخ التقديم">Submission Date</label>
                        <input type="text" value="${formattedDate}" readonly>
                    </div>
                    ${fileSection}
                    ${submission.Feedback ? `
                        <div class="form-group">
                            <label data-en="Feedback" data-ar="التعليقات">Feedback</label>
                            <textarea rows="4" readonly style="background: var(--bg-light);">${submission.Feedback}</textarea>
                        </div>
                    ` : ''}
                    ${submission.Grade !== null ? `
                        <div class="form-group">
                            <label data-en="Grade" data-ar="الدرجة">Grade</label>
                            <input type="text" value="${parseFloat(submission.Grade).toFixed(1)}" readonly style="font-size: 1.5rem; font-weight: 700; color: var(--primary-color);">
                        </div>
                    ` : ''}
                    <div class="action-buttons" style="margin-top: 1.5rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('viewModal')" data-en="Close" data-ar="إغلاق">Close</button>
                        <button type="button" class="btn btn-primary" onclick="closeModal('viewModal'); openGradeModal(${submission.Submission_ID});" data-en="Grade This Submission" data-ar="تصحيح هذا التقديم">Grade This Submission</button>
                    </div>
                </div>
            `;
            
            openModal('viewModal');
        }
        
        function openGradeModal(submissionId) {
            const submission = submissionsData.find(s => s.Submission_ID == submissionId);
            if (!submission) {
                showNotification(currentLanguage === 'en' ? 'Submission not found' : 'التقديم غير موجود', 'error');
                return;
            }

            fetch(`assignments-ajax.php?action=getSubmissionDetails&submission_id=${submissionId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayGradeModal(data.submission);
                    } else {
                        showNotification(data.message || (currentLanguage === 'en' ? 'Error loading submission' : 'خطأ في تحميل التقديم'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification(currentLanguage === 'en' ? 'Error loading submission' : 'خطأ في تحميل التقديم', 'error');
                });
        }
        
        function displayGradeModal(submission) {
            const totalMarks = submission.Total_Marks || 100;
            const currentGrade = submission.Grade || 0;
            
            document.getElementById('gradeModalContent').innerHTML = `
                <form onsubmit="saveGrade(event, ${submission.Submission_ID})" style="padding: 1.5rem;">
                    <div class="form-group">
                        <label data-en="Student" data-ar="الطالب">Student</label>
                        <input type="text" value="${submission.Student_Name || 'Unknown'} (${submission.Student_Code || 'N/A'})" readonly>
                    </div>
                    <div class="form-group">
                        <label data-en="Assignment" data-ar="الواجب">Assignment</label>
                        <input type="text" value="${submission.Assignment_Title || 'N/A'}" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label data-en="Grade (out of ${totalMarks})" data-ar="الدرجة (من ${totalMarks})">Grade (out of ${totalMarks}) *</label>
                        <input type="number" id="grade_${submission.Submission_ID}" 
                               value="${currentGrade}" 
                               min="0" 
                               max="${totalMarks}" 
                               step="0.01" 
                               required
                               onchange="updateGradePercentage(${submission.Submission_ID}, ${totalMarks})">
                        <div style="margin-top: 0.5rem; font-size: 1.2rem; font-weight: 700; color: var(--primary-color);" id="gradePercentage_${submission.Submission_ID}">
                            ${totalMarks > 0 ? ((currentGrade / totalMarks) * 100).toFixed(1) + '%' : '0%'}
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label data-en="Feedback" data-ar="التعليقات">Feedback</label>
                        <textarea id="feedback_${submission.Submission_ID}" rows="5" placeholder="${currentLanguage === 'en' ? 'Enter feedback for the student...' : 'أدخل تعليقات للطالب...'}" data-placeholder-en="Enter feedback for the student..." data-placeholder-ar="أدخل تعليقات للطالب...">${submission.Feedback || ''}</textarea>
                    </div>
                    
                    <div class="action-buttons">
                        <button type="submit" class="btn btn-primary" data-en="Save Grade" data-ar="حفظ الدرجة">Save Grade</button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('gradeModal')" data-en="Cancel" data-ar="إلغاء">Cancel</button>
                    </div>
                </form>
            `;
            
            openModal('gradeModal');
        }
        
        function updateGradePercentage(submissionId, totalMarks) {
            const grade = parseFloat(document.getElementById(`grade_${submissionId}`).value) || 0;
            const percentage = totalMarks > 0 ? ((grade / totalMarks) * 100).toFixed(1) : 0;
            document.getElementById(`gradePercentage_${submissionId}`).textContent = percentage + '%';
        }
        
        function saveGrade(event, submissionId) {
            event.preventDefault();

            const gradeInput = document.getElementById(`grade_${submissionId}`).value;
            const grade = gradeInput !== '' && gradeInput !== null ? parseFloat(gradeInput) : null;

            if (gradeInput !== '' && gradeInput !== null && (isNaN(grade) || grade < 0)) {
                showNotification(currentLanguage === 'en' ? 'Please enter a valid grade (0 or higher)' : 'يرجى إدخال درجة صحيحة (0 أو أعلى)', 'error');
                return;
            }
            
            const feedback = document.getElementById(`feedback_${submissionId}`).value;
            
            const formData = new FormData();
            formData.append('action', 'saveGrade');
            formData.append('submission_id', submissionId);
            
            formData.append('grade', gradeInput !== '' && gradeInput !== null ? gradeInput : '');
            formData.append('feedback', feedback);
            
            fetch('assignments-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message || (currentLanguage === 'en' ? 'Grade saved successfully!' : 'تم حفظ الدرجة بنجاح!'), 'success');
                    closeModal('gradeModal');
                    loadSubmissions(); 
                } else {
                    showNotification(data.message || (currentLanguage === 'en' ? 'Error saving grade' : 'خطأ في حفظ الدرجة'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(currentLanguage === 'en' ? 'Error saving grade' : 'خطأ في حفظ الدرجة', 'error');
            });
        }
        
        function openProfileSettings() {
            window.location.href = 'notifications-and-settings.php#profile';
        }

        function testSubmissions() {
            fetch('assignments-ajax.php?action=testSubmissions')
                .then(response => response.json())
                .then(data => {
                    console.log('Test Submissions Result:', data);
                    alert('Test Results:\n' + 
                          'Total Submissions: ' + (data.total_submissions || 0) + '\n' +
                          'Teacher ID: ' + (data.teacher_id || 'N/A') + '\n' +
                          'Sample: ' + (data.sample_submission ? 'Found' : 'None'));
                })
                .catch(error => {
                    console.error('Test Error:', error);
                    alert('Test failed: ' + error.message);
                });
        }

        window.testSubmissions = testSubmissions;

        loadSubmissions();
    </script>
</body>
</html>
