<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('admin');

$currentAdminId = getCurrentUserId();
$currentAdmin = getCurrentUserData($pdo);
$adminName = $_SESSION['user_name'] ?? 'Admin';

$gradeLevels = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT Grade_Level FROM class WHERE Grade_Level IS NOT NULL ORDER BY Grade_Level");
    $gradeLevels = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Error fetching grade levels: " . $e->getMessage());
}

$courses = [];
try {
    $stmt = $pdo->query("
        SELECT c.Course_ID, c.Course_Name, c.Description, c.Grade_Level, c.Created_At,
               GROUP_CONCAT(cl.Name ORDER BY cl.Section SEPARATOR ', ') as Class_Names,
               COUNT(cc.Class_ID) as Class_Count
        FROM course c
        LEFT JOIN course_class cc ON c.Course_ID = cc.Course_ID
        LEFT JOIN class cl ON cc.Class_ID = cl.Class_ID
        GROUP BY c.Course_ID
        ORDER BY c.Grade_Level, c.Course_Name
    ");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching courses: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Courses Management - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
        .management-container {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 2rem;
            margin-top: 2rem;
        }
        
        .form-section {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            height: fit-content;
        }
        
        .list-section {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 3px solid #FFE5E5;
        }
        
        .section-icon {
            font-size: 2rem;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #6BCB77, #4CAF50);
            border-radius: 15px;
            color: white;
        }
        
        .section-title {
            font-family: 'Fredoka', sans-serif;
            font-size: 1.8rem;
            color: var(--text-dark);
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #FFE5E5;
            border-radius: 10px;
            font-size: 1rem;
            font-family: 'Nunito', sans-serif;
            transition: all 0.3s;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #6BCB77;
            box-shadow: 0 0 0 3px rgba(107, 203, 119, 0.1);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .required-field {
            color: #FF6B9D;
            font-weight: 700;
        }
        
        .grade-info {
            margin-top: 0.5rem;
            padding: 0.75rem;
            background: #E5F3FF;
            border-radius: 8px;
            font-size: 0.9rem;
            color: #2c3e50;
            display: none;
        }
        
        .btn-submit {
            background: linear-gradient(135deg, #6BCB77, #4CAF50);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 15px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(107, 203, 119, 0.3);
        }
        
        .btn-cancel {
            background: #999;
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 15px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            flex: 1;
        }
        
        .btn-cancel:hover {
            background: #777;
            transform: translateY(-2px);
        }
        
        .courses-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        .courses-table thead {
            background: #E5F3FF;
        }
        
        .courses-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 700;
            color: var(--text-dark);
            border-bottom: 2px solid #6BCB77;
        }
        
        .courses-table td {
            padding: 1rem;
            border-bottom: 1px solid #E5F3FF;
        }
        
        .courses-table tr:hover {
            background: #FFF9F5;
        }
        
        .btn-edit {
            background: linear-gradient(135deg, #6BCB77, #4CAF50);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(107, 203, 119, 0.3);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #999;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        
        .message {
            padding: 1rem;
            border-radius: 15px;
            margin-bottom: 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .message-success {
            background: #6BCB77;
            color: white;
        }
        
        .message-error {
            background: #FF6B9D;
            color: white;
        }
        
        @media (max-width: 968px) {
            .management-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">📖</span>
                <span data-en="Courses Management" data-ar="إدارة المقررات">Courses Management</span>
            </h1>
            <p class="page-subtitle" data-en="Add and manage courses with automatic grade level assignment" data-ar="إضافة وإدارة المقررات مع التخصيص التلقائي لمستوى الصف">Add and manage courses with automatic grade level assignment</p>
        </div>

        <div id="messageContainer"></div>

        <div class="management-container">
            
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">➕</div>
                    <h2 class="section-title" id="formTitle" data-en="Add New Course" data-ar="إضافة مقرر جديد">Add New Course</h2>
                </div>
                
                <form id="courseForm" method="POST" onsubmit="return submitCourseForm(event);">
                    <input type="hidden" name="action" id="courseAction" value="addCourse">
                    <input type="hidden" name="courseId" id="courseId" value="">
                    
                    <div class="form-group">
                        <label data-en="Course Name" data-ar="اسم المقرر">
                            Course Name <span class="required-field">*</span>
                        </label>
                        <input type="text" name="courseName" id="courseName" required 
                               placeholder="e.g., Mathematics" data-placeholder-en="e.g., Mathematics" data-placeholder-ar="مثال: الرياضيات">
                    </div>
                    
                    <div class="form-group">
                        <label data-en="Grade Level" data-ar="مستوى الصف">
                            Grade Level <span class="required-field">*</span>
                        </label>
                        <select name="gradeLevel" id="gradeLevel" required onchange="updateGradeInfo(this.value)">
                            <option value="">-- Select Grade Level --</option>
                            <?php foreach ($gradeLevels as $grade): ?>
                                <option value="<?php echo $grade; ?>"><?php echo $grade; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="gradeInfo" class="grade-info">
                            <i class="fas fa-info-circle"></i>
                            <span id="gradeInfoText"></span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label data-en="Description" data-ar="الوصف">
                            Description <span style="color: #999; font-weight: normal;">(Optional)</span>
                        </label>
                        <textarea name="description" id="description" 
                                  placeholder="Course description..." 
                                  data-placeholder-en="Course description..." 
                                  data-placeholder-ar="وصف المقرر..."></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 1rem;">
                        <button type="submit" class="btn-submit" id="courseSubmitBtn">
                            <i class="fas fa-save"></i>
                            <span data-en="Save Course" data-ar="حفظ المقرر">Save Course</span>
                        </button>
                        <button type="button" class="btn-cancel" id="courseCancelBtn" onclick="cancelCourseEdit()" style="display: none;">
                            <i class="fas fa-times"></i>
                            <span data-en="Cancel" data-ar="إلغاء">Cancel</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="list-section">
                <div class="section-header">
                    <div class="section-icon">📋</div>
                    <h2 class="section-title" data-en="All Courses" data-ar="جميع المقررات">All Courses</h2>
                </div>
                
                <div id="coursesList">
                    <?php if (empty($courses)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📭</div>
                            <p data-en="No courses added yet" data-ar="لم يتم إضافة مقررات بعد">No courses added yet</p>
                        </div>
                    <?php else: ?>
                        <table class="courses-table">
                            <thead>
                                <tr>
                                    <th data-en="Course Name" data-ar="اسم المقرر">Course Name</th>
                                    <th data-en="Grade Level" data-ar="مستوى الصف">Grade Level</th>
                                    <th data-en="Assigned Classes" data-ar="الفصول المخصصة">Assigned Classes</th>
                                    <th data-en="Description" data-ar="الوصف">Description</th>
                                    <th data-en="Actions" data-ar="الإجراءات">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="coursesTableBody">
                                <?php foreach ($courses as $course): ?>
                                    <tr data-course-id="<?php echo $course['Course_ID']; ?>">
                                        <td><strong><?php echo htmlspecialchars($course['Course_Name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($course['Grade_Level']); ?></td>
                                        <td>
                                            <?php if ($course['Class_Count'] > 0): ?>
                                                <span style="color: #6BCB77;"><i class="fas fa-check-circle"></i> <?php echo $course['Class_Count']; ?> class(es)</span>
                                                <br><small style="color: #666;"><?php echo htmlspecialchars($course['Class_Names']); ?></small>
                                            <?php else: ?>
                                                <span style="color: #FF6B9D;"><i class="fas fa-exclamation-triangle"></i> No classes assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars(substr($course['Description'] ?? '-', 0, 50)) . (strlen($course['Description'] ?? '') > 50 ? '...' : ''); ?></td>
                                        <td>
                                            <button class="btn-edit" onclick="editCourse(<?php echo $course['Course_ID']; ?>)">
                                                <i class="fas fa-edit"></i>
                                                <span data-en="Edit" data-ar="تعديل">Edit</span>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        
        function updateGradeInfo(gradeLevel) {
            const infoDiv = document.getElementById('gradeInfo');
            const infoText = document.getElementById('gradeInfoText');
            
            if (!gradeLevel) {
                infoDiv.style.display = 'none';
                return;
            }

            const formData = new FormData();
            formData.append('action', 'getClassesByGrade');
            formData.append('gradeLevel', gradeLevel);
            
            fetch('courses-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.count > 0) {
                        const classNames = data.classes.map(c => c.Section ? `Section ${c.Section}` : 'Class').join(', ');
                        infoText.textContent = `This course will be automatically assigned to all ${data.count} class(es) under Grade ${gradeLevel}: ${classNames}`;
                        infoDiv.style.display = 'block';
                        infoDiv.style.background = '#E5F3FF';
                    } else {
                        infoText.textContent = `No classes found for Grade ${gradeLevel} yet. The course will be assigned automatically when classes are created.`;
                        infoDiv.style.display = 'block';
                        infoDiv.style.background = '#FFF9E5';
                    }
                }
            })
            .catch(error => {
                console.error('Error fetching classes:', error);
            });
        }

        window.submitCourseForm = function(event) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }
            
            const courseName = document.getElementById('courseName').value.trim();
            const gradeLevel = document.getElementById('gradeLevel').value;
            
            if (!courseName) {
                showMessage('Please enter a course name', 'error');
                return false;
            }
            
            if (!gradeLevel) {
                showMessage('Please select a grade level', 'error');
                return false;
            }
            
            const formData = new FormData(document.getElementById('courseForm'));
            
            const submitBtn = document.getElementById('courseSubmitBtn');
            const originalBtnText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span data-en="Saving..." data-ar="جاري الحفظ...">Saving...</span>';
            
            fetch('courses-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('JSON parse error:', e, 'Response:', text);
                    throw new Error('Invalid response from server');
                }
            }))
            .then(data => {
                if (data.success) {
                    showMessage(data.message, 'success');
                    if (data.action === 'update') {
                        updateCourseInTable(data.course);
                    } else {
                        addCourseToTable(data.course);
                    }
                    cancelCourseEdit();
                } else {
                    showMessage(data.message || 'Failed to save course', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('An error occurred: ' + error.message, 'error');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
            });
            
            return false;
        }

        window.editCourse = function(courseId) {
            const formData = new FormData();
            formData.append('action', 'getCourse');
            formData.append('courseId', courseId);
            
            fetch('courses-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.course) {
                    document.getElementById('courseName').value = data.course.Course_Name || '';
                    document.getElementById('gradeLevel').value = data.course.Grade_Level || '';
                    document.getElementById('description').value = data.course.Description || '';
                    document.getElementById('courseId').value = data.course.Course_ID;
                    document.getElementById('courseAction').value = 'updateCourse';
                    
                    updateGradeInfo(data.course.Grade_Level);
                    
                    document.getElementById('formTitle').textContent = 'Edit Course';
                    document.getElementById('courseSubmitBtn').innerHTML = '<i class="fas fa-save"></i> <span data-en="Update Course" data-ar="تحديث المقرر">Update Course</span>';
                    document.getElementById('courseCancelBtn').style.display = 'flex';
                    
                    document.getElementById('courseForm').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                } else {
                    showMessage(data.message || 'Failed to load course data', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('An error occurred while loading course data', 'error');
            });
        }

        window.cancelCourseEdit = function() {
            document.getElementById('courseForm').reset();
            document.getElementById('courseId').value = '';
            document.getElementById('courseAction').value = 'addCourse';
            document.getElementById('gradeInfo').style.display = 'none';
            document.getElementById('formTitle').textContent = 'Add New Course';
            document.getElementById('courseSubmitBtn').innerHTML = '<i class="fas fa-save"></i> <span data-en="Save Course" data-ar="حفظ المقرر">Save Course</span>';
            document.getElementById('courseCancelBtn').style.display = 'none';
        }

        function addCourseToTable(courseData) {
            const tbody = document.getElementById('coursesTableBody');
            if (!tbody) {
                const listDiv = document.getElementById('coursesList');
                listDiv.innerHTML = `
                    <table class="courses-table">
                        <thead>
                            <tr>
                                <th data-en="Course Name" data-ar="اسم المقرر">Course Name</th>
                                <th data-en="Grade Level" data-ar="مستوى الصف">Grade Level</th>
                                <th data-en="Assigned Classes" data-ar="الفصول المخصصة">Assigned Classes</th>
                                <th data-en="Description" data-ar="الوصف">Description</th>
                                <th data-en="Actions" data-ar="الإجراءات">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="coursesTableBody"></tbody>
                    </table>
                `;
            }
            
            const row = document.createElement('tr');
            row.setAttribute('data-course-id', courseData.Course_ID);
            
            const assignedInfo = courseData.Class_Count > 0 
                ? `<span style="color: #6BCB77;"><i class="fas fa-check-circle"></i> ${courseData.Class_Count} class(es)</span><br><small style="color: #666;">${escapeHtml(courseData.Class_Names || '')}</small>`
                : `<span style="color: #FF6B9D;"><i class="fas fa-exclamation-triangle"></i> No classes assigned</span>`;
            
            const desc = courseData.Description ? (courseData.Description.length > 50 ? courseData.Description.substring(0, 50) + '...' : courseData.Description) : '-';
            
            row.innerHTML = `
                <td><strong>${escapeHtml(courseData.Course_Name)}</strong></td>
                <td>${escapeHtml(courseData.Grade_Level)}</td>
                <td>${assignedInfo}</td>
                <td>${escapeHtml(desc)}</td>
                <td>
                    <button class="btn-edit" onclick="editCourse(${courseData.Course_ID})">
                        <i class="fas fa-edit"></i>
                        <span data-en="Edit" data-ar="تعديل">Edit</span>
                    </button>
                </td>
            `;
            
            document.getElementById('coursesTableBody').appendChild(row);
        }

        function updateCourseInTable(courseData) {
            const row = document.querySelector(`tr[data-course-id="${courseData.Course_ID}"]`);
            if (row) {
                const assignedInfo = courseData.Class_Count > 0 
                    ? `<span style="color: #6BCB77;"><i class="fas fa-check-circle"></i> ${courseData.Class_Count} class(es)</span><br><small style="color: #666;">${escapeHtml(courseData.Class_Names || '')}</small>`
                    : `<span style="color: #FF6B9D;"><i class="fas fa-exclamation-triangle"></i> No classes assigned</span>`;
                
                const desc = courseData.Description ? (courseData.Description.length > 50 ? courseData.Description.substring(0, 50) + '...' : courseData.Description) : '-';
                
                row.innerHTML = `
                    <td><strong>${escapeHtml(courseData.Course_Name)}</strong></td>
                    <td>${escapeHtml(courseData.Grade_Level)}</td>
                    <td>${assignedInfo}</td>
                    <td>${escapeHtml(desc)}</td>
                    <td>
                        <button class="btn-edit" onclick="editCourse(${courseData.Course_ID})">
                            <i class="fas fa-edit"></i>
                            <span data-en="Edit" data-ar="تعديل">Edit</span>
                        </button>
                    </td>
                `;
            }
        }

        function showMessage(message, type) {
            const container = document.getElementById('messageContainer');
            const messageDiv = document.createElement('div');
            messageDiv.className = `message message-${type}`;
            messageDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check' : 'exclamation'}-circle"></i><span>${escapeHtml(message)}</span>`;
            
            container.innerHTML = '';
            container.appendChild(messageDiv);
            
            setTimeout(() => {
                messageDiv.style.transition = 'opacity 0.5s';
                messageDiv.style.opacity = '0';
                setTimeout(() => messageDiv.remove(), 500);
            }, 5000);
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>

