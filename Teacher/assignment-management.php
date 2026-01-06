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

$teacherAssignments = [];

try {
    $stmt = $pdo->prepare("
        SELECT a.*, c.Name as Class_Name, co.Course_Name,
               (SELECT COUNT(*) FROM submission s WHERE s.Assignment_ID = a.Assignment_ID) as Submission_Count
        FROM assignment a
        JOIN class c ON a.Class_ID = c.Class_ID
        JOIN course co ON a.Course_ID = co.Course_ID
        WHERE a.Teacher_ID = ? AND a.Status != 'cancelled'
        ORDER BY a.Upload_Date DESC
    ");
    $stmt->execute([$currentTeacherId]);
    $teacherAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching assignments: " . $e->getMessage());
    $teacherAssignments = [];
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignment Management - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        .assignment-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border-left: 5px solid var(--primary-color);
            transition: all 0.3s;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .assignment-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .assignment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .assignment-info {
            flex: 1;
            min-width: 250px;
        }
        .assignment-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }
        .assignment-meta {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
            font-size: 0.9rem;
            color: #666;
        }
        .assignment-meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .assignment-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .btn-edit {
            background: var(--accent-blue);
            color: white;
        }
        .btn-delete {
            background: #ff4444;
            color: white;
        }
        .btn-delete:hover {
            background: #cc0000;
        }
        .submission-badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            background: var(--bg-blue);
            border-radius: 15px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-top: 0.5rem;
        }

            transition: all 0.3s ease;
        }

            display: none !important;
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
            .assignment-header {
                flex-direction: column;
            }
            .assignment-actions {
                width: 100%;
            }
            .assignment-actions .btn {
                flex: 1;
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
                <span class="page-icon">📝</span>
                <span data-en="Assignment Management" data-ar="إدارة الواجبات">Assignment Management</span>
            </h1>
            <p class="page-subtitle" data-en="Create, edit, and manage your assignments" data-ar="إنشاء وتعديل وإدارة الواجبات الخاصة بك">Create, edit, and manage your assignments</p>
        </div>

        <div class="card" style="margin-bottom: 2rem;">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">➕</span>
                    <span data-en="Create New Assignment" data-ar="إنشاء واجب جديد">Create New Assignment</span>
                </h2>
                <button class="btn btn-primary" onclick="toggleCreateForm()" id="toggleCreateBtn" data-en="Show Form" data-ar="إظهار النموذج">Show Form</button>
            </div>
            <div id="createAssignmentForm" class="hidden" style="padding: 1.5rem;">
                <form id="assignmentForm" onsubmit="createAssignment(event)">
                    
                    <div class="form-group">
                        <label data-en="Select Class *" data-ar="اختر الفصل *">Select Class *</label>
                        <select id="assignmentClass" required onchange="updateSubjectOptions()">
                            <option value="" data-en="Select Class" data-ar="اختر الفصل">Select Class</option>
                            <?php foreach ($teacherClasses as $class): ?>
                                <option value="<?php echo $class['Class_ID']; ?>">
                                    <?php echo htmlspecialchars($class['Class_Name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label data-en="Select Subject *" data-ar="اختر المادة *">Select Subject *</label>
                        <select id="assignmentCourse" required>
                            <option value="" data-en="Select Subject (Choose class first)" data-ar="اختر المادة (اختر الفصل أولاً)">Select Subject (Choose class first)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label data-en="Assignment Title *" data-ar="عنوان الواجب *">Assignment Title *</label>
                        <input type="text" id="assignmentTitle" required placeholder="Enter assignment title">
                    </div>
                    <div class="form-group">
                        <label data-en="Description" data-ar="الوصف">Description</label>
                        <textarea id="assignmentDescription" rows="4" placeholder="Enter assignment description..."></textarea>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label data-en="Due Date *" data-ar="تاريخ الاستحقاق *">Due Date *</label>
                            <input type="datetime-local" id="assignmentDueDate" required>
                        </div>
                        <div class="form-group">
                            <label data-en="Total Marks" data-ar="الدرجة الكلية">Total Marks</label>
                            <input type="number" id="assignmentTotalMarks" min="0" step="0.01" placeholder="100">
                        </div>
                    </div>
                    <div class="form-group">
                        <label data-en="Upload Assignment File (Optional)" data-ar="رفع ملف الواجب (اختياري)">Upload Assignment File (Optional)</label>
                        <div class="upload-area" onclick="document.getElementById('assignmentFileInput').click()">
                            <div class="upload-icon">📎</div>
                            <div data-en="Click to upload file or drag and drop" data-ar="انقر للرفع أو اسحب وأفلت">Click to upload file or drag and drop</div>
                            <input type="file" id="assignmentFileInput" style="display: none;" accept=".pdf,.doc,.docx,.txt">
                        </div>
                        <div id="assignmentFileList" style="margin-top: 0.5rem;"></div>
                    </div>
                    <div class="action-buttons">
                        <button type="submit" class="btn btn-primary" data-en="Create Assignment" data-ar="إنشاء الواجب">Create Assignment</button>
                        <button type="button" class="btn btn-secondary" onclick="resetAssignmentForm()" data-en="Reset" data-ar="إعادة تعيين">Reset</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📋</span>
                    <span data-en="My Assignments" data-ar="واجباتي">My Assignments</span>
                </h2>
            </div>
            <div style="padding: 1.5rem;">
                <div id="assignmentsList">
                    <?php if (empty($teacherAssignments)): ?>
                        <div class="empty-state">
                            <i class="fas fa-clipboard-list"></i>
                            <div data-en="No assignments yet. Create your first assignment!" data-ar="لا توجد واجبات بعد. أنشئ واجبك الأول!">No assignments yet. Create your first assignment!</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($teacherAssignments as $assignment): ?>
                            <div class="assignment-card" data-assignment-id="<?php echo $assignment['Assignment_ID']; ?>">
                                <div class="assignment-header">
                                    <div class="assignment-info">
                                        <div class="assignment-title"><?php echo htmlspecialchars($assignment['Title']); ?></div>
                                        <div class="assignment-meta">
                                            <div class="assignment-meta-item">
                                                <i class="fas fa-users"></i>
                                                <span><strong data-en="Class" data-ar="الفصل">Class:</strong> <?php echo htmlspecialchars($assignment['Class_Name']); ?></span>
                                            </div>
                                            <div class="assignment-meta-item">
                                                <i class="fas fa-book"></i>
                                                <span><strong data-en="Subject" data-ar="المادة">Subject:</strong> <?php echo htmlspecialchars($assignment['Course_Name']); ?></span>
                                            </div>
                                            <div class="assignment-meta-item">
                                                <i class="fas fa-calendar-alt"></i>
                                                <span><strong data-en="Due Date" data-ar="تاريخ الاستحقاق">Due Date:</strong> <?php echo date('M d, Y H:i', strtotime($assignment['Due_Date'])); ?></span>
                                            </div>
                                            <?php if ($assignment['Description']): ?>
                                                <div class="assignment-meta-item" style="margin-top: 0.5rem;">
                                                    <i class="fas fa-align-left"></i>
                                                    <span><?php echo htmlspecialchars($assignment['Description']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="submission-badge">
                                                <i class="fas fa-paper-plane"></i>
                                                <span data-en="<?php echo $assignment['Submission_Count']; ?> submission(s)" data-ar="<?php echo $assignment['Submission_Count']; ?> تقديم(ات)"><?php echo $assignment['Submission_Count']; ?> submission(s)</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="assignment-actions">
                                        <button class="btn btn-edit" onclick="editAssignment(<?php echo $assignment['Assignment_ID']; ?>)" data-en="Edit" data-ar="تعديل">
                                            <i class="fas fa-edit"></i> <span data-en="Edit" data-ar="تعديل">Edit</span>
                                        </button>
                                        <button class="btn btn-delete" onclick="deleteAssignment(<?php echo $assignment['Assignment_ID']; ?>)" data-en="Delete" data-ar="حذف">
                                            <i class="fas fa-trash"></i> <span data-en="Delete" data-ar="حذف">Delete</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="editModal" role="dialog">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h2 class="modal-title" data-en="Edit Assignment" data-ar="تعديل الواجب">Edit Assignment</h2>
                <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
            </div>
            <div id="editModalContent">
                
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        const teacherClassCourseMap = <?php echo json_encode($teacherClassCourseMap); ?>;
        const teacherCourses = <?php echo json_encode($teacherCourses); ?>;
        const teacherClasses = <?php echo json_encode($teacherClasses); ?>;
        const teacherAssignments = <?php echo json_encode($teacherAssignments); ?>;
        
        function toggleCreateForm() {
            const form = document.getElementById('createAssignmentForm');
            const btn = document.getElementById('toggleCreateBtn');
            
            if (!form || !btn) {
                console.error('Form or button not found');
                return;
            }
            
            if (form.classList.contains('hidden')) {
                form.classList.remove('hidden');
                btn.textContent = currentLanguage === 'en' ? 'Hide Form' : 'إخفاء النموذج';
            } else {
                form.classList.add('hidden');
                btn.textContent = currentLanguage === 'en' ? 'Show Form' : 'إظهار النموذج';
            }
        }
        
        window.toggleCreateForm = toggleCreateForm;
        
        function updateSubjectOptions() {
            const classId = parseInt(document.getElementById('assignmentClass').value);
            const subjectSelect = document.getElementById('assignmentCourse');
            
            subjectSelect.innerHTML = '<option value="" data-en="Select Subject" data-ar="اختر المادة">Select Subject</option>';
            
            if (classId && teacherClassCourseMap && teacherClassCourseMap[classId]) {
                const courseIds = teacherClassCourseMap[classId];
                
                teacherCourses.forEach(course => {
                    if (courseIds.includes(course.Course_ID)) {
                        const option = document.createElement('option');
                        option.value = course.Course_ID;
                        option.textContent = course.Course_Name;
                        subjectSelect.appendChild(option);
                    }
                });
            }
        }
        
        function createAssignment(event) {
            event.preventDefault();
            
            const formData = new FormData();
            formData.append('action', 'createAssignment');
            formData.append('title', document.getElementById('assignmentTitle').value);
            formData.append('description', document.getElementById('assignmentDescription').value);
            formData.append('course_id', document.getElementById('assignmentCourse').value);
            formData.append('class_id', document.getElementById('assignmentClass').value);
            formData.append('due_date', document.getElementById('assignmentDueDate').value);
            formData.append('total_marks', document.getElementById('assignmentTotalMarks').value || null);
            
            const fileInput = document.getElementById('assignmentFileInput');
            if (fileInput.files.length > 0) {
                formData.append('assignment_file', fileInput.files[0]);
            }
            
            fetch('assignments-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message || (currentLanguage === 'en' ? 'Assignment created successfully!' : 'تم إنشاء الواجب بنجاح!'), 'success');
                    resetAssignmentForm();
                    toggleCreateForm();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showNotification(data.message || (currentLanguage === 'en' ? 'Error creating assignment' : 'خطأ في إنشاء الواجب'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(currentLanguage === 'en' ? 'Error creating assignment' : 'خطأ في إنشاء الواجب', 'error');
            });
        }
        
        function resetAssignmentForm() {
            document.getElementById('assignmentForm').reset();
            document.getElementById('assignmentCourse').innerHTML = '<option value="" data-en="Select Subject (Choose class first)" data-ar="اختر المادة (اختر الفصل أولاً)">Select Subject (Choose class first)</option>';
            document.getElementById('assignmentFileList').innerHTML = '';
        }
        
        function editAssignment(assignmentId) {
            const assignment = teacherAssignments.find(a => a.Assignment_ID == assignmentId);
            if (!assignment) {
                showNotification(currentLanguage === 'en' ? 'Assignment not found' : 'الواجب غير موجود', 'error');
                return;
            }

            fetch(`assignments-ajax.php?action=getAssignmentDetails&assignment_id=${assignmentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayEditModal(data.assignment);
                    } else {
                        showNotification(data.message || (currentLanguage === 'en' ? 'Error loading assignment' : 'خطأ في تحميل الواجب'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification(currentLanguage === 'en' ? 'Error loading assignment' : 'خطأ في تحميل الواجب', 'error');
                });
        }
        
        function displayEditModal(assignment) {
            
            const dueDate = new Date(assignment.Due_Date);
            const formattedDate = dueDate.toISOString().slice(0, 16);

            const classId = assignment.Class_ID;
            let subjectOptions = '<option value="" data-en="Select Subject" data-ar="اختر المادة">Select Subject</option>';
            if (teacherClassCourseMap && teacherClassCourseMap[classId]) {
                const courseIds = teacherClassCourseMap[classId];
                teacherCourses.forEach(course => {
                    if (courseIds.includes(course.Course_ID)) {
                        const selected = course.Course_ID == assignment.Course_ID ? 'selected' : '';
                        subjectOptions += `<option value="${course.Course_ID}" ${selected}>${course.Course_Name}</option>`;
                    }
                });
            }
            
            document.getElementById('editModalContent').innerHTML = `
                <form onsubmit="saveAssignment(event, ${assignment.Assignment_ID})" style="padding: 1.5rem;">
                    <div class="form-group">
                        <label data-en="Select Class *" data-ar="اختر الفصل *">Select Class *</label>
                        <select id="editClass" required onchange="updateEditSubjectOptions()">
                            <option value="" data-en="Select Class" data-ar="اختر الفصل">Select Class</option>
                            ${teacherClasses.map(c => `<option value="${c.Class_ID}" ${c.Class_ID == assignment.Class_ID ? 'selected' : ''}>${c.Class_Name}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group">
                        <label data-en="Select Subject *" data-ar="اختر المادة *">Select Subject *</label>
                        <select id="editCourse" required>
                            ${subjectOptions}
                        </select>
                    </div>
                    <div class="form-group">
                        <label data-en="Assignment Title *" data-ar="عنوان الواجب *">Assignment Title *</label>
                        <input type="text" id="editTitle" value="${assignment.Title.replace(/"/g, '&quot;')}" required>
                    </div>
                    <div class="form-group">
                        <label data-en="Description" data-ar="الوصف">Description</label>
                        <textarea id="editDescription" rows="4">${(assignment.Description || '').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</textarea>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label data-en="Due Date *" data-ar="تاريخ الاستحقاق *">Due Date *</label>
                            <input type="datetime-local" id="editDueDate" value="${formattedDate}" required>
                        </div>
                        <div class="form-group">
                            <label data-en="Total Marks" data-ar="الدرجة الكلية">Total Marks</label>
                            <input type="number" id="editTotalMarks" min="0" step="0.01" value="${assignment.Total_Marks || ''}">
                        </div>
                    </div>
                    <div class="action-buttons">
                        <button type="submit" class="btn btn-primary" data-en="Save Changes" data-ar="حفظ التغييرات">Save Changes</button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')" data-en="Cancel" data-ar="إلغاء">Cancel</button>
                    </div>
                </form>
            `;
            
            openModal('editModal');
        }
        
        function updateEditSubjectOptions() {
            const classId = parseInt(document.getElementById('editClass').value);
            const subjectSelect = document.getElementById('editCourse');
            
            subjectSelect.innerHTML = '<option value="" data-en="Select Subject" data-ar="اختر المادة">Select Subject</option>';
            
            if (classId && teacherClassCourseMap && teacherClassCourseMap[classId]) {
                const courseIds = teacherClassCourseMap[classId];
                teacherCourses.forEach(course => {
                    if (courseIds.includes(course.Course_ID)) {
                        const option = document.createElement('option');
                        option.value = course.Course_ID;
                        option.textContent = course.Course_Name;
                        subjectSelect.appendChild(option);
                    }
                });
            }
        }
        
        function saveAssignment(event, assignmentId) {
            event.preventDefault();
            
            const formData = new FormData();
            formData.append('action', 'updateAssignment');
            formData.append('assignment_id', assignmentId);
            formData.append('title', document.getElementById('editTitle').value);
            formData.append('description', document.getElementById('editDescription').value);
            formData.append('course_id', document.getElementById('editCourse').value);
            formData.append('class_id', document.getElementById('editClass').value);
            formData.append('due_date', document.getElementById('editDueDate').value);
            formData.append('total_marks', document.getElementById('editTotalMarks').value || null);
            
            fetch('assignments-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message || (currentLanguage === 'en' ? 'Assignment updated successfully!' : 'تم تحديث الواجب بنجاح!'), 'success');
                    closeModal('editModal');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showNotification(data.message || (currentLanguage === 'en' ? 'Error updating assignment' : 'خطأ في تحديث الواجب'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(currentLanguage === 'en' ? 'Error updating assignment' : 'خطأ في تحديث الواجب', 'error');
            });
        }
        
        function deleteAssignment(assignmentId) {
            const assignment = teacherAssignments.find(a => a.Assignment_ID == assignmentId);
            if (!assignment) {
                showNotification(currentLanguage === 'en' ? 'Assignment not found' : 'الواجب غير موجود', 'error');
                return;
            }
            
            const submissionCount = assignment.Submission_Count || 0;
            const confirmMessage = currentLanguage === 'en' 
                ? `Are you sure you want to delete "${assignment.Title}"?\n\nThis assignment has ${submissionCount} submission(s). The assignment will be marked as cancelled, but student submissions will be preserved.`
                : `هل أنت متأكد من حذف "${assignment.Title}"؟\n\nهذا الواجب يحتوي على ${submissionCount} تقديم(ات). سيتم إلغاء الواجب، لكن تقديمات الطلاب سيتم الحفاظ عليها.`;
            
            if (!confirm(confirmMessage)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'deleteAssignment');
            formData.append('assignment_id', assignmentId);
            
            fetch('assignments-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message || (currentLanguage === 'en' ? 'Assignment deleted successfully!' : 'تم حذف الواجب بنجاح!'), 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showNotification(data.message || (currentLanguage === 'en' ? 'Error deleting assignment' : 'خطأ في حذف الواجب'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(currentLanguage === 'en' ? 'Error deleting assignment' : 'خطأ في حذف الواجب', 'error');
            });
        }

        document.getElementById('assignmentFileInput')?.addEventListener('change', function(e) {
            const fileList = document.getElementById('assignmentFileList');
            fileList.innerHTML = '';
            if (e.target.files.length > 0) {
                const file = e.target.files[0];
                const fileItem = document.createElement('div');
                fileItem.style.cssText = 'padding: 0.5rem; background: #FFF9F5; border-radius: 10px; margin-top: 0.5rem; display: flex; justify-content: space-between; align-items: center;';
                fileItem.innerHTML = `
                    <span>📄 ${file.name} (${(file.size / 1024).toFixed(2)} KB)</span>
                    <button type="button" onclick="this.parentElement.remove(); document.getElementById('assignmentFileInput').value='';" style="background: #FF6B9D; color: white; border: none; padding: 0.3rem 0.8rem; border-radius: 5px; cursor: pointer;">×</button>
                `;
                fileList.appendChild(fileItem);
            }
        });
        
        function openProfileSettings() {
            window.location.href = 'notifications-and-settings.php#profile';
        }

    </script>
</body>
</html>

