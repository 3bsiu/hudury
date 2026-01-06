<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-teacher.php';

requireUserType('teacher');

$currentTeacherId = getCurrentUserId();
$currentTeacher = getCurrentUserData($pdo);
$teacherName = $_SESSION['user_name'] ?? 'Teacher';

$supervisedClasses = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.Class_ID, c.Grade_Level, c.Section, c.Name as Class_Name
        FROM teacher_class_course tcc
        JOIN class c ON tcc.Class_ID = c.Class_ID
        WHERE tcc.Teacher_ID = ?
        ORDER BY c.Grade_Level, c.Section
    ");
    $stmt->execute([$currentTeacherId]);
    $supervisedClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching supervised classes: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Supervision - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>
    
    <div class="main-content">

    <div id="notificationContainer"></div>

    <div class="dashboard-container">
        
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">👥</span>
                <span data-en="Class Supervision" data-ar="إشراف الفصل">Class Supervision</span>
            </h1>
            <p class="page-subtitle" data-en="Manage your supervised classes and students" data-ar="إدارة الفصول والطلاب المشرف عليهم">Manage your supervised classes and students</p>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">🔍</span>
                    <span data-en="Filters" data-ar="المرشحات">Filters</span>
                </h2>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label data-en="Class" data-ar="الفصل">Class</label>
                    <select id="classSelector" onchange="loadStudents()">
                        <option value="all" data-en="All Classes" data-ar="جميع الفصول">All Classes</option>
                        <?php foreach ($supervisedClasses as $class): ?>
                            <option value="<?php echo htmlspecialchars($class['Class_ID']); ?>" 
                                    data-grade="<?php echo htmlspecialchars($class['Grade_Level']); ?>"
                                    data-section="<?php echo htmlspecialchars($class['Section']); ?>">
                                <?php echo htmlspecialchars($class['Class_Name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label data-en="Section" data-ar="القسم">Section</label>
                    <select id="sectionSelector" onchange="loadStudents()">
                        <option value="all" data-en="All Sections" data-ar="جميع الأقسام">All Sections</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">👦</span>
                    <span data-en="Students" data-ar="الطلاب">Students</span>
                </h2>
            </div>
            <div class="search-filter-bar">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="studentSearch" placeholder="Search students..." data-placeholder-en="Search students..." data-placeholder-ar="البحث عن الطلاب..." oninput="filterStudents()">
                </div>
            </div>
            <div id="studentsList" class="user-list">
                
                <div style="padding: 2rem; text-align: center; color: #999;">
                    <i class="fas fa-spinner fa-spin"></i>
                    <span data-en="Loading students..." data-ar="جاري تحميل الطلاب...">Loading students...</span>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="noteModal" role="dialog">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h2 class="modal-title" id="noteModalTitle" data-en="Add Note" data-ar="إضافة ملاحظة">Add Note</h2>
                <button class="modal-close" onclick="closeModal('noteModal')">&times;</button>
            </div>
            <form id="noteForm" onsubmit="saveNote(event)">
                <input type="hidden" id="noteId" value="">
                <input type="hidden" id="noteStudentId" value="">
                <input type="hidden" id="noteClassId" value="">
                <input type="hidden" id="noteSectionId" value="">
                
                <div class="form-group">
                    <label id="noteStudentNameLabel" data-en="Student" data-ar="الطالب">Student</label>
                    <input type="text" id="noteStudentName" readonly style="background: #f5f5f5;">
                </div>
                
                <div class="form-group">
                    <label data-en="Behavior Level" data-ar="مستوى السلوك">Behavior Level</label>
                    <select id="noteBehaviorLevel" required>
                        <option value="Excellent" data-en="Excellent" data-ar="ممتاز">Excellent</option>
                        <option value="Good" data-en="Good" data-ar="جيد" selected>Good</option>
                        <option value="Average" data-en="Average" data-ar="متوسط">Average</option>
                        <option value="Needs Attention" data-en="Needs Attention" data-ar="يحتاج انتباه">Needs Attention</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label data-en="Note" data-ar="الملاحظة">Note</label>
                    <textarea id="noteText" rows="6" required placeholder="Enter your note about this student..." data-placeholder-en="Enter your note about this student..." data-placeholder-ar="أدخل ملاحظتك عن هذا الطالب..."></textarea>
                </div>
                
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" data-en="Save" data-ar="حفظ">Save</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('noteModal')" data-en="Cancel" data-ar="إلغاء">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="notesViewModal" role="dialog">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h2 class="modal-title" id="notesViewTitle" data-en="Student Notes" data-ar="ملاحظات الطالب">Student Notes</h2>
                <button class="modal-close" onclick="closeModal('notesViewModal')">&times;</button>
            </div>
            <div id="notesViewContent">
                
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        let allStudents = [];
        let allNotes = {}; 
        const currentTeacherId = <?php echo $currentTeacherId; ?>;

        document.getElementById('classSelector').addEventListener('change', function() {
            updateSectionDropdown();
            loadStudents();
        });
        
        function updateSectionDropdown() {
            const classSelector = document.getElementById('classSelector');
            const sectionSelector = document.getElementById('sectionSelector');
            const selectedClassId = classSelector.value;
            
            sectionSelector.innerHTML = '<option value="all" data-en="All Sections" data-ar="جميع الأقسام">All Sections</option>';
            
            if (selectedClassId !== 'all') {
                const selectedOption = classSelector.options[classSelector.selectedIndex];
                const section = selectedOption.getAttribute('data-section');
                if (section) {
                    const option = document.createElement('option');
                    option.value = section;
                    option.textContent = section;
                    sectionSelector.appendChild(option);
                }
            } else {
                
                const sections = new Set();
                Array.from(classSelector.options).forEach(opt => {
                    if (opt.value !== 'all' && opt.getAttribute('data-section')) {
                        sections.add(opt.getAttribute('data-section'));
                    }
                });
                Array.from(sections).sort().forEach(section => {
                    const option = document.createElement('option');
                    option.value = section;
                    option.textContent = section;
                    sectionSelector.appendChild(option);
                });
            }
        }
        
        function loadStudents() {
            const classId = document.getElementById('classSelector').value;
            const sectionId = document.getElementById('sectionSelector').value;
            
            const url = `class-supervision-ajax.php?action=getStudents&class_id=${classId}&section_id=${sectionId}`;
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        allStudents = data.students || [];
                        
                        loadAllNotes();
                        renderStudents();
                    } else {
                        console.error('Error loading students:', data.message);
                        document.getElementById('studentsList').innerHTML = 
                            '<div style="padding: 2rem; text-align: center; color: #999;">' + 
                            (data.message || 'Error loading students') + '</div>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('studentsList').innerHTML = 
                        '<div style="padding: 2rem; text-align: center; color: #999;">Error loading students. Please refresh the page.</div>';
                });
        }
        
        function loadAllNotes() {
            
            const promises = allStudents.map(student => {
                return fetch(`class-supervision-ajax.php?action=getNotes&student_id=${student.id}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            allNotes[student.id] = data.notes || [];
                        }
                    })
                    .catch(error => {
                        console.error(`Error loading notes for student ${student.id}:`, error);
                        allNotes[student.id] = [];
                    });
            });
            
            Promise.all(promises).then(() => {
                renderStudents();
            });
        }
        
        function renderStudents() {
            const container = document.getElementById('studentsList');
            const searchTerm = document.getElementById('studentSearch').value.toLowerCase();
            
            let filtered = allStudents.filter(student => {
                const matchesSearch = !searchTerm || 
                    (student.name && student.name.toLowerCase().includes(searchTerm)) ||
                    (student.studentId && student.studentId.toLowerCase().includes(searchTerm));
                return matchesSearch;
            });
            
            if (filtered.length === 0) {
                container.innerHTML = '<div style="padding: 2rem; text-align: center; color: #999;">No students found.</div>';
                return;
            }
            
            container.innerHTML = filtered.map(student => {
                const notes = allNotes[student.id] || [];
                const lastNote = notes.length > 0 ? notes[0] : null;
                const myNotes = notes.filter(n => n.teacher_id == currentTeacherId);
                
                return `
                    <div class="user-item">
                        <div class="user-info-item" style="flex: 1;">
                            <div class="user-avatar-item">👤</div>
                            <div style="flex: 1;">
                                <div style="font-weight: 700; font-size: 1.1rem;">${escapeHtml(student.name || 'Unknown')}</div>
                                <div style="font-size: 0.9rem; color: #666; margin-top: 0.3rem;">
                                    ${escapeHtml(student.studentId || '')} • ${escapeHtml(student.className || '')}
                                </div>
                                ${lastNote ? `
                                    <div style="font-size: 0.85rem; color: #999; margin-top: 0.5rem;">
                                        <span style="color: ${getBehaviorColor(lastNote.behavior_level)}; font-weight: 600;">
                                            ${escapeHtml(lastNote.behavior_level)}
                                        </span>
                                        • ${formatDate(lastNote.created_at)} • ${escapeHtml(lastNote.teacher_name)}
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                        <div class="action-buttons" style="margin-left: 1rem;">
                            <button class="btn btn-small btn-secondary" onclick="viewNotes(${student.id}, '${escapeHtml(student.name)}')" 
                                    data-en="View Notes" data-ar="عرض الملاحظات">
                                <i class="fas fa-sticky-note"></i> ${notes.length}
                            </button>
                            <button class="btn btn-small btn-primary" onclick="addNote(${student.id}, '${escapeHtml(student.name)}', ${student.Class_ID || 0}, '${escapeHtml(student.section || '')}')" 
                                    data-en="Add Note" data-ar="إضافة ملاحظة">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                `;
            }).join('');
        }
        
        function getBehaviorColor(level) {
            const colors = {
                'Excellent': '#28a745',
                'Good': '#17a2b8',
                'Average': '#ffc107',
                'Needs Attention': '#dc3545'
            };
            return colors[level] || '#666';
        }
        
        function addNote(studentId, studentName, classId, sectionId) {
            document.getElementById('noteModalTitle').textContent = currentLanguage === 'en' ? 'Add Note' : 'إضافة ملاحظة';
            document.getElementById('noteId').value = '';
            document.getElementById('noteStudentId').value = studentId;
            document.getElementById('noteClassId').value = classId;
            document.getElementById('noteSectionId').value = sectionId;
            document.getElementById('noteStudentName').value = studentName;
            document.getElementById('noteBehaviorLevel').value = 'Good';
            document.getElementById('noteText').value = '';
            openModal('noteModal');
        }
        
        function editNote(noteId, studentId, studentName) {
            const notes = allNotes[studentId] || [];
            const note = notes.find(n => n.id == noteId);
            if (!note) return;

            if (note.teacher_id != currentTeacherId) {
                showNotification(currentLanguage === 'en' ? 'You can only edit your own notes' : 'يمكنك تعديل ملاحظاتك فقط', 'warning');
                return;
            }
            
            document.getElementById('noteModalTitle').textContent = currentLanguage === 'en' ? 'Edit Note' : 'تعديل الملاحظة';
            document.getElementById('noteId').value = noteId;
            document.getElementById('noteStudentId').value = studentId;
            document.getElementById('noteStudentName').value = studentName;
            document.getElementById('noteBehaviorLevel').value = note.behavior_level;
            document.getElementById('noteText').value = note.note_text;
            openModal('noteModal');
        }
        
        function saveNote(event) {
            event.preventDefault();
            
            const noteId = document.getElementById('noteId').value;
            const studentId = document.getElementById('noteStudentId').value;
            const classId = document.getElementById('noteClassId').value;
            const sectionId = document.getElementById('noteSectionId').value;
            const behaviorLevel = document.getElementById('noteBehaviorLevel').value;
            const noteText = document.getElementById('noteText').value;
            
            if (!noteText.trim()) {
                showNotification(currentLanguage === 'en' ? 'Please enter a note' : 'يرجى إدخال ملاحظة', 'warning');
                return;
            }
            
            const formData = new FormData();
            if (noteId) {
                formData.append('action', 'updateNote');
                formData.append('note_id', noteId);
            } else {
                formData.append('action', 'addNote');
                formData.append('student_id', studentId);
                formData.append('class_id', classId);
                formData.append('section_id', sectionId);
            }
            formData.append('note_text', noteText);
            formData.append('behavior_level', behaviorLevel);
            
            fetch('class-supervision-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(
                        currentLanguage === 'en' ? 'Note saved successfully!' : 'تم حفظ الملاحظة بنجاح!',
                        'success'
                    );
                    closeModal('noteModal');
                    
                    loadNotesForStudent(studentId);
                } else {
                    showNotification(data.message || 'Error saving note', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(currentLanguage === 'en' ? 'Error saving note' : 'خطأ في حفظ الملاحظة', 'error');
            });
        }
        
        function loadNotesForStudent(studentId) {
            fetch(`class-supervision-ajax.php?action=getNotes&student_id=${studentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        allNotes[studentId] = data.notes || [];
                        renderStudents();
                    }
                })
                .catch(error => {
                    console.error('Error loading notes:', error);
                });
        }
        
        function viewNotes(studentId, studentName) {
            const notes = allNotes[studentId] || [];
            
            document.getElementById('notesViewTitle').textContent = 
                (currentLanguage === 'en' ? 'Notes for ' : 'ملاحظات ') + studentName;
            
            if (notes.length === 0) {
                document.getElementById('notesViewContent').innerHTML = 
                    '<div style="padding: 2rem; text-align: center; color: #999;">' +
                    (currentLanguage === 'en' ? 'No notes yet.' : 'لا توجد ملاحظات بعد.') +
                    '</div>';
            } else {
                document.getElementById('notesViewContent').innerHTML = notes.map(note => {
                    const canEdit = note.teacher_id == currentTeacherId;
                    const canDelete = note.teacher_id == currentTeacherId;
                    return `
                        <div style="border: 1px solid #e0e0e0; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; background: #f9f9f9; position: relative;">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 0.5rem;">
                                <div style="flex: 1;">
                                    <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                        <span style="color: ${getBehaviorColor(note.behavior_level)}; font-weight: 600; font-size: 0.9rem;">
                                            ${escapeHtml(note.behavior_level)}
                                        </span>
                                        <span style="color: #666; font-size: 0.85rem;">
                                            • ${escapeHtml(note.teacher_name)} • ${formatDate(note.created_at)}
                                        </span>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 0.5rem;">
                                    ${canEdit ? `
                                        <button class="btn btn-small btn-secondary" onclick="editNote(${note.id}, ${studentId}, '${escapeHtml(studentName)}')" 
                                                style="padding: 0.25rem 0.5rem; font-size: 0.8rem;" 
                                                title="${currentLanguage === 'en' ? 'Edit' : 'تعديل'}">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    ` : ''}
                                    ${canDelete ? `
                                        <button class="btn btn-small" onclick="deleteNote(${note.id}, ${studentId})" 
                                                style="padding: 0.25rem 0.5rem; font-size: 0.8rem; background: #dc3545; color: white; border: none;" 
                                                title="${currentLanguage === 'en' ? 'Delete' : 'حذف'}">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    ` : ''}
                                </div>
                            </div>
                            <div style="color: #333; white-space: pre-wrap; margin-top: 0.5rem;">${escapeHtml(note.note_text)}</div>
                        </div>
                    `;
                }).join('');
            }
            
            openModal('notesViewModal');
        }
        
        function deleteNote(noteId, studentId) {
            if (!confirm(currentLanguage === 'en' ? 'Are you sure you want to delete this note? This action cannot be undone.' : 'هل أنت متأكد من حذف هذه الملاحظة؟ لا يمكن التراجع عن هذا الإجراء.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'deleteNote');
            formData.append('note_id', noteId);
            
            fetch('class-supervision-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(
                        currentLanguage === 'en' ? 'Note deleted successfully!' : 'تم حذف الملاحظة بنجاح!',
                        'success'
                    );
                    
                    if (allNotes[studentId]) {
                        allNotes[studentId] = allNotes[studentId].filter(n => n.id != noteId);
                    }
                    
                    viewNotes(studentId, document.getElementById('notesViewTitle').textContent.replace(currentLanguage === 'en' ? 'Notes for ' : 'ملاحظات ', ''));
                    
                    renderStudents();
                } else {
                    showNotification(data.message || (currentLanguage === 'en' ? 'Error deleting note' : 'خطأ في حذف الملاحظة'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(currentLanguage === 'en' ? 'Error deleting note' : 'خطأ في حذف الملاحظة', 'error');
            });
        }
        
        function filterStudents() {
            renderStudents();
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function formatDate(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString);
            return date.toLocaleDateString(currentLanguage === 'ar' ? 'ar-JO' : 'en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }

        updateSectionDropdown();
        loadStudents();
    </script>
</body>
</html>

