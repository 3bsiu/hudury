<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/activity-logger.php';

requireUserType('admin');

$currentAdminId = getCurrentUserId();
$currentAdmin = getCurrentUserData($pdo);
$adminName = $_SESSION['user_name'] ?? 'Admin';
$adminId = $currentAdminId ?? 1;

ini_set('display_errors', 1);
error_reporting(E_ALL);

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'updateStatus') {
    try {
        $studentId = intval($_POST['studentId'] ?? 0);
        $status = trim($_POST['studentStatus'] ?? '');
        $sponsoringEntity = trim($_POST['sponsoringEntity'] ?? '');
        $notes = trim($_POST['studentNotes'] ?? '');

        if ($studentId <= 0) {
            throw new Exception('Invalid student ID.');
        }
        
        $allowedStatuses = ['active', 'inactive', 'graduated', 'transferred', 'suspended'];
        if (!in_array($status, $allowedStatuses)) {
            throw new Exception('Invalid status value.');
        }

        $stmt = $pdo->prepare("SELECT Student_ID FROM academic_status WHERE Student_ID = ?");
        $stmt->execute([$studentId]);
        $exists = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT NameEn, NameAr FROM student WHERE Student_ID = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        $studentName = $student ? ($student['NameEn'] . ($student['NameAr'] ? ' (' . $student['NameAr'] . ')' : '')) : "Student ID: {$studentId}";

        $oldStatus = null;
        if ($exists) {
            $stmt = $pdo->prepare("SELECT Status FROM academic_status WHERE Student_ID = ?");
            $stmt->execute([$studentId]);
            $oldData = $stmt->fetch(PDO::FETCH_ASSOC);
            $oldStatus = $oldData ? $oldData['Status'] : null;
        }
        
        if ($exists) {
            
            $stmt = $pdo->prepare("
                UPDATE academic_status 
                SET Status = ?, 
                    Sponsoring_Entity = ?, 
                    Notes = ?, 
                    Updated_By = ?, 
                    Updated_At = NOW()
                WHERE Student_ID = ?
            ");
            $stmt->execute([$status, $sponsoringEntity ?: null, $notes ?: null, $adminId, $studentId]);
        } else {
            
            $stmt = $pdo->prepare("
                INSERT INTO academic_status 
                (Student_ID, Status, Sponsoring_Entity, Notes, Updated_By, Created_At, Updated_At)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$studentId, $status, $sponsoringEntity ?: null, $notes ?: null, $adminId]);
        }

        $details = $oldStatus ? "Status changed from {$oldStatus} to {$status}" : "Status set to {$status}";
        if ($sponsoringEntity) {
            $details .= ", Sponsor: {$sponsoringEntity}";
        }
        logAcademicStatusAction($pdo, $exists ? 'update' : 'create', $studentId, $studentName, $status, $details);
        
        $successMessage = 'Academic status updated successfully!';
        header("Location: academic-status-management.php?success=1&message=" . urlencode($successMessage));
        exit();
        
    } catch (PDOException $e) {
        error_log("Database error updating academic status: " . $e->getMessage());
        $errorMessage = 'Database error: ' . $e->getMessage();
    } catch (Exception $e) {
        error_log("Error updating academic status: " . $e->getMessage());
        $errorMessage = $e->getMessage();
    }
}

$students = [];
$allClasses = [];

try {
    
    $stmt = $pdo->prepare("
        SELECT DISTINCT Grade_Level, Section 
        FROM class 
        WHERE Grade_Level IS NOT NULL 
        ORDER BY Grade_Level, Section
    ");
    $stmt->execute();
    $allClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT 
            s.Student_ID,
            s.Student_Code,
            s.NameEn,
            s.NameAr,
            s.Class_ID,
            c.Grade_Level,
            c.Section,
            c.Name AS ClassName,
            COALESCE(a.Status, 'active') AS Status,
            a.Sponsoring_Entity,
            a.Notes,
            a.Enrollment_Date,
            a.Academic_Year
        FROM student s
        LEFT JOIN class c ON s.Class_ID = c.Class_ID
        LEFT JOIN academic_status a ON s.Student_ID = a.Student_ID
        ORDER BY c.Grade_Level, c.Section, s.NameEn
    ");
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching students: " . $e->getMessage());
    $errorMessage = 'Error loading student data: ' . $e->getMessage();
    $students = [];
}

$gradeLevels = [];
foreach ($allClasses as $class) {
    if (!in_array($class['Grade_Level'], $gradeLevels)) {
        $gradeLevels[] = $class['Grade_Level'];
    }
}
sort($gradeLevels);
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Status Management - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>

    <div class="dashboard-container">
        
        <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
            <div style="background: #6BCB77; color: white; padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; font-weight: 600;">
                <?php echo htmlspecialchars($_GET['message'] ?? 'Operation completed successfully!'); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['error']) && $_GET['error'] == '1'): ?>
            <div style="background: #FF6B9D; color: white; padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; font-weight: 600;">
                <?php echo htmlspecialchars($_GET['message'] ?? 'An error occurred.'); ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <div style="background: #FF6B9D; color: white; padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; font-weight: 600;">
                <?php echo htmlspecialchars($errorMessage); ?>
            </div>
        <?php endif; ?>
        
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">📊</span>
                <span data-en="Academic Status Management" data-ar="إدارة الحالة الأكاديمية">Academic Status Management</span>
            </h1>
            <p class="page-subtitle" data-en="Manage student academic status, sponsoring entities, and notes" data-ar="إدارة الحالة الأكاديمية للطلاب، الجهات الراعية، والملاحظات">Manage student academic status, sponsoring entities, and notes</p>
        </div>

        <div class="search-filter-bar">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="studentSearch" placeholder="Search students..." oninput="filterStudents()">
            </div>
            <select class="filter-select" id="statusFilter" onchange="filterStudents()">
                <option value="all" data-en="All Status" data-ar="جميع الحالات">All Status</option>
                <option value="active" data-en="Active" data-ar="نشط">Active</option>
                <option value="inactive" data-en="Inactive" data-ar="غير نشط">Inactive</option>
                <option value="graduated" data-en="Graduated" data-ar="متخرج">Graduated</option>
                <option value="transferred" data-en="Transferred" data-ar="منقول">Transferred</option>
                <option value="suspended" data-en="Suspended" data-ar="معلق">Suspended</option>
            </select>
            <select class="filter-select" id="gradeFilter" onchange="loadSections(); filterStudents()">
                <option value="all" data-en="All Grades" data-ar="جميع الصفوف">All Grades</option>
                <?php foreach ($gradeLevels as $grade): ?>
                    <option value="<?php echo $grade; ?>" data-en="Grade <?php echo $grade; ?>" data-ar="الصف <?php echo $grade; ?>">Grade <?php echo $grade; ?></option>
                <?php endforeach; ?>
            </select>
            <select class="filter-select" id="sectionFilter" onchange="filterStudents()" style="display: none;">
                <option value="all" data-en="All Sections" data-ar="جميع الأقسام">All Sections</option>
            </select>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">👨‍🎓</span>
                    <span data-en="Students Academic Status" data-ar="الحالة الأكاديمية للطلاب">Students Academic Status</span>
                </h2>
            </div>
            <div id="studentsList" class="user-list">
                <?php if (empty($students)): ?>
                    <div style="text-align: center; padding: 3rem; color: #666;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">👨‍🎓</div>
                        <div data-en="No students found" data-ar="لا يوجد طلاب">No students found</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <?php
                        $statusClass = 'status-inactive';
                        $statusText = 'Inactive';
                        if ($student['Status'] === 'active') {
                            $statusClass = 'status-active';
                            $statusText = 'Active';
                        } elseif ($student['Status'] === 'graduated') {
                            $statusClass = 'status-approved';
                            $statusText = 'Graduated';
                        } elseif ($student['Status'] === 'transferred') {
                            $statusClass = 'status-pending';
                            $statusText = 'Transferred';
                        } elseif ($student['Status'] === 'suspended') {
                            $statusClass = 'status-rejected';
                            $statusText = 'Suspended';
                        }
                        $studentName = !empty($student['NameEn']) ? $student['NameEn'] : $student['NameAr'];
                        $grade = $student['Grade_Level'] ?? 'N/A';
                        $section = $student['Section'] ?? 'N/A';
                        ?>
                        <div class="user-item" data-student-id="<?php echo $student['Student_ID']; ?>" 
                             data-grade="<?php echo $grade; ?>" 
                             data-section="<?php echo strtolower($section); ?>"
                             data-status="<?php echo $student['Status']; ?>"
                             data-name="<?php echo htmlspecialchars(strtolower($studentName)); ?>"
                             data-code="<?php echo htmlspecialchars(strtolower($student['Student_Code'])); ?>">
                            <div class="user-info-item" style="flex: 1;">
                                <div class="user-avatar-item">👨‍🎓</div>
                                <div>
                                    <div style="font-weight: 700;"><?php echo htmlspecialchars($studentName); ?></div>
                                    <div style="font-size: 0.9rem; color: #666;">
                                        ID: <?php echo htmlspecialchars($student['Student_Code']); ?> 
                                        <?php if ($grade !== 'N/A'): ?>
                                            • Grade <?php echo $grade; ?> 
                                        <?php endif; ?>
                                        <?php if ($section !== 'N/A'): ?>
                                            • Section <?php echo $section; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 0.85rem; color: #999; margin-top: 0.3rem;">
                                        <?php echo htmlspecialchars($student['Sponsoring_Entity'] ?: 'No sponsor'); ?>
                                        <?php if ($student['Notes']): ?>
                                            • <?php echo htmlspecialchars(substr($student['Notes'], 0, 50)) . (strlen($student['Notes']) > 50 ? '...' : ''); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                <span class="status-badge <?php echo $statusClass; ?>">
                                    <?php echo $statusText; ?>
                                </span>
                                <button class="btn btn-secondary btn-small" onclick="editStatus(<?php echo $student['Student_ID']; ?>)" data-en="Edit" data-ar="تعديل">Edit</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal" id="statusModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" data-en="Edit Academic Status" data-ar="تعديل الحالة الأكاديمية">Edit Academic Status</h2>
                <button class="modal-close" onclick="closeModal('statusModal')">&times;</button>
            </div>
            <form method="POST" action="academic-status-management.php" onsubmit="return validateStatusForm(event)">
                <input type="hidden" name="action" value="updateStatus">
                <input type="hidden" id="studentId" name="studentId">
                <div class="form-group">
                    <label data-en="Student Name" data-ar="اسم الطالب">Student Name</label>
                    <input type="text" id="studentName" readonly>
                </div>
                <div class="form-group">
                    <label data-en="Status" data-ar="الحالة">Status <span style="color: red;">*</span></label>
                    <select id="studentStatus" name="studentStatus" required>
                        <option value="active" data-en="Active" data-ar="نشط">Active</option>
                        <option value="inactive" data-en="Inactive" data-ar="غير نشط">Inactive</option>
                        <option value="graduated" data-en="Graduated" data-ar="متخرج">Graduated</option>
                        <option value="transferred" data-en="Transferred" data-ar="منقول">Transferred</option>
                        <option value="suspended" data-en="Suspended" data-ar="معلق">Suspended</option>
                    </select>
                </div>
                <div class="form-group">
                    <label data-en="Sponsoring Entity" data-ar="الجهة الراعية">Sponsoring Entity</label>
                    <input type="text" id="sponsoringEntity" name="sponsoringEntity" placeholder="Enter sponsoring entity name">
                </div>
                <div class="form-group">
                    <label data-en="Notes" data-ar="ملاحظات">Notes</label>
                    <textarea id="studentNotes" name="studentNotes" rows="5" placeholder="Add notes about the student..."></textarea>
                </div>
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" data-en="Save Changes" data-ar="حفظ التغييرات">Save Changes</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('statusModal')" data-en="Cancel" data-ar="إلغاء">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        
        const allStudents = <?php echo json_encode($students, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const allClasses = <?php echo json_encode($allClasses, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        let studentDataMap = {};
        allStudents.forEach(student => {
            studentDataMap[student.Student_ID] = student;
        });

        function loadSections() {
            const grade = document.getElementById('gradeFilter').value;
            const sectionFilter = document.getElementById('sectionFilter');

            sectionFilter.innerHTML = '<option value="all" data-en="All Sections" data-ar="جميع الأقسام">All Sections</option>';
            
            if (grade && grade !== 'all') {
                
                const sections = [...new Set(allClasses
                    .filter(c => c.Grade_Level == grade)
                    .map(c => c.Section)
                    .filter(s => s)
                )].sort();
                
                sections.forEach(section => {
                    const option = document.createElement('option');
                    option.value = section.toLowerCase();
                    option.textContent = 'Section ' + section;
                    option.setAttribute('data-en', 'Section ' + section);
                    option.setAttribute('data-ar', 'القسم ' + section);
                    sectionFilter.appendChild(option);
                });
                
                sectionFilter.style.display = 'inline-block';
            } else {
                sectionFilter.style.display = 'none';
                sectionFilter.value = 'all';
            }
            filterStudents();
        }

        function filterStudents() {
            const searchTerm = document.getElementById('studentSearch').value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter').value;
            const gradeFilter = document.getElementById('gradeFilter').value;
            const sectionFilter = document.getElementById('sectionFilter').value;
            
            const studentItems = document.querySelectorAll('.user-item');
            
            studentItems.forEach(item => {
                const studentId = item.getAttribute('data-student-id');
                const grade = item.getAttribute('data-grade');
                const section = item.getAttribute('data-section');
                const status = item.getAttribute('data-status');
                const name = item.getAttribute('data-name');
                const code = item.getAttribute('data-code');
                
                const matchesSearch = name.includes(searchTerm) || code.includes(searchTerm);
                const matchesStatus = statusFilter === 'all' || status === statusFilter;
                const matchesGrade = gradeFilter === 'all' || grade === gradeFilter || grade === 'N/A';
                const matchesSection = sectionFilter === 'all' || !gradeFilter || gradeFilter === 'all' || section === sectionFilter.toLowerCase();
                
                if (matchesSearch && matchesStatus && matchesGrade && matchesSection) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        function editStatus(studentId) {
            const student = studentDataMap[studentId];
            if (!student) {
                alert('Student not found.');
                return;
            }
            
            document.getElementById('studentId').value = student.Student_ID;
            document.getElementById('studentName').value = (student.NameEn || student.NameAr);
            document.getElementById('studentStatus').value = student.Status || 'active';
            document.getElementById('sponsoringEntity').value = student.Sponsoring_Entity || '';
            document.getElementById('studentNotes').value = student.Notes || '';
            
            openModal('statusModal');
        }

        function validateStatusForm(event) {
            const status = document.getElementById('studentStatus').value;
            const studentId = document.getElementById('studentId').value;
            
            if (!studentId || studentId <= 0) {
                alert('Invalid student ID.');
                event.preventDefault();
                return false;
            }
            
            if (!status) {
                alert('Please select a status.');
                event.preventDefault();
                return false;
            }
            
            return true;
        }

        document.addEventListener('DOMContentLoaded', function() {
            
            const gradeFilter = document.getElementById('gradeFilter');
            if (gradeFilter.value && gradeFilter.value !== 'all') {
                loadSections();
            }
        });
    </script>
</body>
</html>

