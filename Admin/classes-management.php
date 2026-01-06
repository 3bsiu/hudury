<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('admin');

$currentAdminId = getCurrentUserId();
$currentAdmin = getCurrentUserData($pdo);
$adminName = $_SESSION['user_name'] ?? 'Admin';

$classes = [];
try {
    $stmt = $pdo->query("SELECT Class_ID, Name, Grade_Level, Section, Academic_Year, Created_At FROM class ORDER BY Grade_Level, Section, Name");
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching classes: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classes Management - HUDURY</title>
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
            background: linear-gradient(135deg, #FF6B9D, #C44569);
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
            border-color: #FF6B9D;
            box-shadow: 0 0 0 3px rgba(255, 107, 157, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .required-field {
            color: #FF6B9D;
            font-weight: 700;
        }
        
        .btn-submit {
            background: linear-gradient(135deg, #FF6B9D, #C44569);
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
            box-shadow: 0 6px 20px rgba(255, 107, 157, 0.3);
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
        
        .classes-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        .classes-table thead {
            background: #FFE5E5;
        }
        
        .classes-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 700;
            color: var(--text-dark);
            border-bottom: 2px solid #FF6B9D;
        }
        
        .classes-table td {
            padding: 1rem;
            border-bottom: 1px solid #FFE5E5;
        }
        
        .classes-table tr:hover {
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
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .classes-table {
                font-size: 0.9rem;
            }
            
            .classes-table th,
            .classes-table td {
                padding: 0.75rem 0.5rem;
            }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">🏫</span>
                <span data-en="Classes Management" data-ar="إدارة الفصول">Classes Management</span>
            </h1>
            <p class="page-subtitle" data-en="Add and manage school classes" data-ar="إضافة وإدارة فصول المدرسة">Add and manage school classes</p>
        </div>

        <div id="messageContainer"></div>

        <div class="management-container">
            
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">➕</div>
                    <h2 class="section-title" id="formTitle" data-en="Add New Class" data-ar="إضافة فصل جديد">Add New Class</h2>
                </div>
                
                <form id="classForm" method="POST" onsubmit="return submitClassForm(event);">
                    <input type="hidden" name="action" id="classAction" value="addClass">
                    <input type="hidden" name="classId" id="classId" value="">
                    
                    <div class="form-group">
                        <label data-en="Class Name" data-ar="اسم الفصل">
                            Class Name <span class="required-field">*</span>
                        </label>
                        <input type="text" name="className" id="className" required 
                               placeholder="e.g., Grade 5 - Section A" data-placeholder-en="e.g., Grade 5 - Section A" data-placeholder-ar="مثال: الصف الخامس - القسم أ">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label data-en="Grade Level" data-ar="مستوى الصف">
                                Grade Level <span class="required-field">*</span>
                            </label>
                            <input type="number" name="gradeLevel" id="gradeLevel" min="1" max="12" required
                                   placeholder="e.g., 5" data-placeholder-en="e.g., 5" data-placeholder-ar="مثال: 5">
                        </div>
                        
                        <div class="form-group">
                            <label data-en="Section" data-ar="القسم">
                                Section <span style="color: #999; font-weight: normal;">(Optional)</span>
                            </label>
                            <input type="text" name="section" id="section" maxlength="10"
                                   placeholder="e.g., A" data-placeholder-en="e.g., A" data-placeholder-ar="مثال: أ">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label data-en="Academic Year" data-ar="السنة الأكاديمية">
                            Academic Year <span style="color: #999; font-weight: normal;">(Optional)</span>
                        </label>
                        <input type="text" name="academicYear" id="academicYear" 
                               placeholder="e.g., 2024-2025" data-placeholder-en="e.g., 2024-2025" data-placeholder-ar="مثال: 2024-2025">
                    </div>
                    
                    <div style="display: flex; gap: 1rem;">
                        <button type="submit" class="btn-submit" id="classSubmitBtn">
                            <i class="fas fa-save"></i>
                            <span data-en="Save Class" data-ar="حفظ الفصل">Save Class</span>
                        </button>
                        <button type="button" class="btn-cancel" id="classCancelBtn" onclick="cancelClassEdit()" style="display: none;">
                            <i class="fas fa-times"></i>
                            <span data-en="Cancel" data-ar="إلغاء">Cancel</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="list-section">
                <div class="section-header">
                    <div class="section-icon">📋</div>
                    <h2 class="section-title" data-en="All Classes" data-ar="جميع الفصول">All Classes</h2>
                </div>
                
                <div id="classesList">
                    <?php if (empty($classes)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📭</div>
                            <p data-en="No classes added yet" data-ar="لم يتم إضافة فصول بعد">No classes added yet</p>
                        </div>
                    <?php else: ?>
                        <table class="classes-table">
                            <thead>
                                <tr>
                                    <th data-en="Class Name" data-ar="اسم الفصل">Class Name</th>
                                    <th data-en="Grade Level" data-ar="مستوى الصف">Grade Level</th>
                                    <th data-en="Section" data-ar="القسم">Section</th>
                                    <th data-en="Academic Year" data-ar="السنة الأكاديمية">Academic Year</th>
                                    <th data-en="Actions" data-ar="الإجراءات">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="classesTableBody">
                                <?php foreach ($classes as $class): ?>
                                    <tr data-class-id="<?php echo $class['Class_ID']; ?>">
                                        <td><?php echo htmlspecialchars($class['Name']); ?></td>
                                        <td><?php echo htmlspecialchars($class['Grade_Level']); ?></td>
                                        <td><?php echo htmlspecialchars($class['Section'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($class['Academic_Year'] ?? '-'); ?></td>
                                        <td>
                                            <button class="btn-edit" onclick="editClass(<?php echo $class['Class_ID']; ?>)">
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
        
        window.submitClassForm = function(event) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }
            
            const className = document.getElementById('className').value.trim();
            const gradeLevel = document.getElementById('gradeLevel').value;
            
            if (!className) {
                showMessage('Please enter a class name', 'error');
                return false;
            }
            
            if (!gradeLevel || gradeLevel <= 0) {
                showMessage('Please enter a valid grade level (1-12)', 'error');
                return false;
            }
            
            const formData = new FormData(document.getElementById('classForm'));
            
            const submitBtn = document.getElementById('classSubmitBtn');
            const originalBtnText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span data-en="Saving..." data-ar="جاري الحفظ...">Saving...</span>';
            
            fetch('classes-ajax.php', {
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
                        updateClassInTable(data.class);
                    } else {
                        addClassToTable(data.class);
                    }
                    cancelClassEdit();
                } else {
                    showMessage(data.message || 'Failed to save class', 'error');
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

        window.editClass = function(classId) {
            const formData = new FormData();
            formData.append('action', 'getClass');
            formData.append('classId', classId);
            
            fetch('classes-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.class) {
                    document.getElementById('className').value = data.class.Name || '';
                    document.getElementById('gradeLevel').value = data.class.Grade_Level || '';
                    document.getElementById('section').value = data.class.Section || '';
                    document.getElementById('academicYear').value = data.class.Academic_Year || '';
                    document.getElementById('classId').value = data.class.Class_ID;
                    document.getElementById('classAction').value = 'updateClass';
                    
                    document.getElementById('formTitle').textContent = 'Edit Class';
                    document.getElementById('classSubmitBtn').innerHTML = '<i class="fas fa-save"></i> <span data-en="Update Class" data-ar="تحديث الفصل">Update Class</span>';
                    document.getElementById('classCancelBtn').style.display = 'flex';
                    
                    document.getElementById('classForm').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                } else {
                    showMessage(data.message || 'Failed to load class data', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('An error occurred while loading class data', 'error');
            });
        }

        window.cancelClassEdit = function() {
            document.getElementById('classForm').reset();
            document.getElementById('classId').value = '';
            document.getElementById('classAction').value = 'addClass';
            document.getElementById('formTitle').textContent = 'Add New Class';
            document.getElementById('classSubmitBtn').innerHTML = '<i class="fas fa-save"></i> <span data-en="Save Class" data-ar="حفظ الفصل">Save Class</span>';
            document.getElementById('classCancelBtn').style.display = 'none';
        }

        function addClassToTable(classData) {
            const tbody = document.getElementById('classesTableBody');
            if (!tbody) {
                
                const listDiv = document.getElementById('classesList');
                listDiv.innerHTML = `
                    <table class="classes-table">
                        <thead>
                            <tr>
                                <th data-en="Class Name" data-ar="اسم الفصل">Class Name</th>
                                <th data-en="Grade Level" data-ar="مستوى الصف">Grade Level</th>
                                <th data-en="Section" data-ar="القسم">Section</th>
                                <th data-en="Academic Year" data-ar="السنة الأكاديمية">Academic Year</th>
                                <th data-en="Actions" data-ar="الإجراءات">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="classesTableBody"></tbody>
                    </table>
                `;
            }
            
            const row = document.createElement('tr');
            row.setAttribute('data-class-id', classData.Class_ID);
            row.innerHTML = `
                <td>${escapeHtml(classData.Name)}</td>
                <td>${escapeHtml(classData.Grade_Level)}</td>
                <td>${escapeHtml(classData.Section || '-')}</td>
                <td>${escapeHtml(classData.Academic_Year || '-')}</td>
                <td>
                    <button class="btn-edit" onclick="editClass(${classData.Class_ID})">
                        <i class="fas fa-edit"></i>
                        <span data-en="Edit" data-ar="تعديل">Edit</span>
                    </button>
                </td>
            `;
            
            document.getElementById('classesTableBody').appendChild(row);
        }

        function updateClassInTable(classData) {
            const row = document.querySelector(`tr[data-class-id="${classData.Class_ID}"]`);
            if (row) {
                row.innerHTML = `
                    <td>${escapeHtml(classData.Name)}</td>
                    <td>${escapeHtml(classData.Grade_Level)}</td>
                    <td>${escapeHtml(classData.Section || '-')}</td>
                    <td>${escapeHtml(classData.Academic_Year || '-')}</td>
                    <td>
                        <button class="btn-edit" onclick="editClass(${classData.Class_ID})">
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
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>

