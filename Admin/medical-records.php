<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/activity-logger.php';

requireUserType('admin');

$currentAdminId = getCurrentUserId();
$currentAdmin = getCurrentUserData($pdo);
$adminName = $_SESSION['user_name'] ?? 'Admin';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'saveMedicalHistory') {
        $isAjax = isset($_POST['ajax']) && $_POST['ajax'] === '1';
        
        try {
            $studentId = intval($_POST['studentId'] ?? 0);
            $historyDate = $_POST['historyDate'] ?? '';
            $historyType = $_POST['historyType'] ?? 'checkup';
            $historyDescription = trim($_POST['historyDescription'] ?? '');
            $historyPhysician = trim($_POST['historyPhysician'] ?? '');
            $historyNotes = trim($_POST['historyNotes'] ?? '');
            
            if ($studentId <= 0) {
                throw new Exception('Invalid student ID');
            }
            if (empty($historyDate)) {
                throw new Exception('Date is required');
            }
            if (empty($historyDescription)) {
                throw new Exception('Description is required');
            }

            $stmt = $pdo->prepare("SELECT NameEn, NameAr FROM student WHERE Student_ID = ?");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            $studentName = $student ? ($student['NameEn'] . ($student['NameAr'] ? ' (' . $student['NameAr'] . ')' : '')) : "Student ID: {$studentId}";
            
            $stmt = $pdo->prepare("
                INSERT INTO medical_history (Student_ID, Date, Type, Description, Physician, Notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $studentId, $historyDate, $historyType, $historyDescription, 
                $historyPhysician ?: null, $historyNotes ?: null
            ]);

            $details = "Type: {$historyType}, Date: {$historyDate}";
            logMedicalAction($pdo, 'add_history', $studentId, $studentName, $details);
            
            $message = 'Medical history entry added successfully!';
            error_log("Medical history added for Student_ID: $studentId by Admin_ID: $currentAdminId");
            
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $message]);
                exit;
            } else {
                $successMessage = $message;
            }
        } catch (Exception $e) {
            $message = 'Error saving medical history: ' . $e->getMessage();
            error_log("Error saving medical history: " . $e->getMessage());
            
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $message]);
                exit;
            } else {
                $errorMessage = $message;
            }
        }
    } elseif ($action === 'deleteMedicalHistory') {
        $isAjax = isset($_POST['ajax']) && $_POST['ajax'] === '1';
        
        try {
            $historyId = intval($_POST['historyId'] ?? 0);
            $studentId = intval($_POST['studentId'] ?? 0);
            
            if ($historyId <= 0 || $studentId <= 0) {
                throw new Exception('Invalid history ID or student ID');
            }

            $stmt = $pdo->prepare("SELECT NameEn, NameAr FROM student WHERE Student_ID = ?");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            $studentName = $student ? ($student['NameEn'] . ($student['NameAr'] ? ' (' . $student['NameAr'] . ')' : '')) : "Student ID: {$studentId}";
            
            $stmt = $pdo->prepare("SELECT Type, Date FROM medical_history WHERE History_ID = ? AND Student_ID = ?");
            $stmt->execute([$historyId, $studentId]);
            $history = $stmt->fetch(PDO::FETCH_ASSOC);
            $details = $history ? "Type: {$history['Type']}, Date: {$history['Date']}" : '';
            
            $stmt = $pdo->prepare("DELETE FROM medical_history WHERE History_ID = ? AND Student_ID = ?");
            $stmt->execute([$historyId, $studentId]);

            logMedicalAction($pdo, 'delete_history', $studentId, $studentName, $details);
            
            $message = 'Medical history entry deleted successfully!';
            error_log("Medical history deleted: History_ID=$historyId, Student_ID=$studentId by Admin_ID: $currentAdminId");
            
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $message]);
                exit;
            } else {
                $successMessage = $message;
            }
        } catch (Exception $e) {
            $message = 'Error deleting medical history: ' . $e->getMessage();
            error_log("Error deleting medical history: " . $e->getMessage());
            
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $message]);
                exit;
            } else {
                $errorMessage = $message;
            }
        }
    } elseif ($action === 'saveMedicalRecord') {
        try {
            $studentId = intval($_POST['studentId'] ?? 0);
            $allergies = trim($_POST['allergies'] ?? '');
            $bloodType = trim($_POST['bloodType'] ?? '');
            $emergencyContact = trim($_POST['emergencyContact'] ?? '');
            $primaryPhysician = trim($_POST['primaryPhysician'] ?? '');
            $medications = trim($_POST['medications'] ?? '');
            $medicalNotes = trim($_POST['medicalNotes'] ?? '');
            
            if ($studentId <= 0) {
                throw new Exception('Invalid student ID');
            }

            $stmt = $pdo->prepare("SELECT NameEn, NameAr FROM student WHERE Student_ID = ?");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            $studentName = $student ? ($student['NameEn'] . ($student['NameAr'] ? ' (' . $student['NameAr'] . ')' : '')) : "Student ID: {$studentId}";

            $stmt = $pdo->prepare("SELECT Medical_Record_ID FROM medical_record WHERE Student_ID = ?");
            $stmt->execute([$studentId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $isUpdate = (bool)$existing;
            
            if ($existing) {
                
                $stmt = $pdo->prepare("
                    UPDATE medical_record 
                    SET Allergies = ?, Blood_Type = ?, Emergency_Contact = ?, 
                        Primary_Physician = ?, Current_Medications = ?, Medical_Notes = ?, Updated_By = ?
                    WHERE Student_ID = ?
                ");
                $stmt->execute([
                    $allergies ?: null, $bloodType ?: null, $emergencyContact ?: null,
                    $primaryPhysician ?: null, $medications ?: null, $medicalNotes ?: null,
                    $currentAdminId, $studentId
                ]);
            } else {
                
                $stmt = $pdo->prepare("
                    INSERT INTO medical_record (Student_ID, Allergies, Blood_Type, Emergency_Contact, 
                        Primary_Physician, Current_Medications, Medical_Notes, Updated_By)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $studentId, $allergies ?: null, $bloodType ?: null, $emergencyContact ?: null,
                    $primaryPhysician ?: null, $medications ?: null, $medicalNotes ?: null, $currentAdminId
                ]);
            }

            $details = "Blood Type: " . ($bloodType ?: 'N/A') . ", Allergies: " . ($allergies ?: 'None');
            logMedicalAction($pdo, $isUpdate ? 'update' : 'create', $studentId, $studentName, $details);
            
            $successMessage = 'Medical record saved successfully!';
            error_log("Medical record saved for Student_ID: $studentId by Admin_ID: $currentAdminId");
        } catch (Exception $e) {
            $errorMessage = 'Error saving medical record: ' . $e->getMessage();
            error_log("Error saving medical record: " . $e->getMessage());
        }
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'getHistory') {
    $studentId = intval($_GET['studentId'] ?? 0);
    if ($studentId > 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM medical_history 
                WHERE Student_ID = ? 
                ORDER BY Date DESC, Created_At DESC
            ");
            $stmt->execute([$studentId]);
            $medicalHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($medicalHistory)) {
                echo '<div style="text-align: center; padding: 2rem; color: #666;">
                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">📜</div>
                    <div data-en="No medical history entries" data-ar="لا توجد سجلات طبية">No medical history entries</div>
                </div>';
            } else {
                foreach ($medicalHistory as $entry) {
                    $entryDate = new DateTime($entry['Date']);
                    $formattedDate = $entryDate->format('M d, Y');
                    $typeLabels = [
                        'checkup' => ['en' => 'Routine Checkup', 'ar' => 'فحص روتيني'],
                        'vaccination' => ['en' => 'Vaccination', 'ar' => 'تطعيم'],
                        'injury' => ['en' => 'Injury Report', 'ar' => 'تقرير إصابة'],
                        'illness' => ['en' => 'Illness', 'ar' => 'مرض'],
                        'medication' => ['en' => 'Medication', 'ar' => 'دواء'],
                        'other' => ['en' => 'Other', 'ar' => 'أخرى']
                    ];
                    $typeLabel = $typeLabels[$entry['Type']] ?? ['en' => $entry['Type'], 'ar' => $entry['Type']];
                    ?>
                    <div class="user-item">
                        <div class="user-info-item" style="flex: 1;">
                            <div class="user-avatar-item">📋</div>
                            <div>
                                <div style="font-weight: 700;"><?php echo htmlspecialchars($typeLabel['en']); ?></div>
                                <div style="font-size: 0.9rem; color: #666;">
                                    <?php echo htmlspecialchars($formattedDate); ?>
                                    <?php if (!empty($entry['Physician'])): ?>
                                        • <?php echo htmlspecialchars($entry['Physician']); ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($entry['Description'])): ?>
                                    <div style="font-size: 0.85rem; color: #999; margin-top: 0.3rem;">
                                        <?php echo htmlspecialchars($entry['Description']); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($entry['Notes'])): ?>
                                    <div style="font-size: 0.85rem; color: #666; margin-top: 0.3rem; font-style: italic;">
                                        <strong>Notes:</strong> <?php echo htmlspecialchars($entry['Notes']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="button" 
                                class="btn btn-danger btn-small" 
                                onclick="deleteMedicalHistory(<?php echo $entry['History_ID']; ?>, <?php echo $studentId; ?>)"
                                data-en="Delete" 
                                data-ar="حذف">Delete</button>
                    </div>
                    <?php
                }
            }
            exit;
        } catch (PDOException $e) {
            echo '<div style="color: red;">Error loading medical history</div>';
            exit;
        }
    }
}

$searchQuery = $_GET['search'] ?? '';
$searchedStudent = null;
$medicalRecord = null;

if (!empty($searchQuery)) {
    try {
        
        $stmt = $pdo->prepare("
            SELECT s.Student_ID, s.Student_Code, s.NameEn, s.NameAr, s.National_ID, 
                   s.Date_Of_Birth, s.Class_ID, c.Name as ClassName, c.Grade_Level, c.Section
            FROM student s
            LEFT JOIN class c ON s.Class_ID = c.Class_ID
            WHERE s.Student_Code = ? OR s.National_ID = ? OR s.Student_ID = ?
            LIMIT 1
        ");
        $stmt->execute([$searchQuery, $searchQuery, intval($searchQuery)]);
        $searchedStudent = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($searchedStudent) {
            
            $stmt = $pdo->prepare("SELECT * FROM medical_record WHERE Student_ID = ?");
            $stmt->execute([$searchedStudent['Student_ID']]);
            $medicalRecord = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                SELECT * FROM medical_history 
                WHERE Student_ID = ? 
                ORDER BY Date DESC, Created_At DESC
            ");
            $stmt->execute([$searchedStudent['Student_ID']]);
            $medicalHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $medicalHistory = [];
        }
    } catch (PDOException $e) {
        error_log("Error searching for student: " . $e->getMessage());
        $errorMessage = 'Error searching for student: ' . $e->getMessage();
        $medicalHistory = [];
    }
} else {
    $medicalHistory = [];
}

$grades = [];
$sections = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT Grade_Level FROM class WHERE Grade_Level IS NOT NULL ORDER BY Grade_Level");
    $grades = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $stmt = $pdo->query("SELECT DISTINCT Section FROM class WHERE Section IS NOT NULL ORDER BY Section");
    $sections = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Error fetching grades/sections: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Records Management - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">🏥</span>
                <span data-en="Medical Records Management" data-ar="إدارة السجلات الطبية">Medical Records Management</span>
            </h1>
            <p class="page-subtitle" data-en="Edit and manage student medical records" data-ar="تعديل وإدارة السجلات الطبية للطلاب">Edit and manage student medical records</p>
        </div>

        <?php if ($successMessage): ?>
            <div style="background: #6BCB77; color: white; padding: 1rem; border-radius: 15px; margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($successMessage); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div style="background: #FF6B9D; color: white; padding: 1rem; border-radius: 15px; margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($errorMessage); ?></span>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">🔍</span>
                    <span data-en="Search Student" data-ar="البحث عن طالب">Search Student</span>
                </h2>
            </div>
            <form method="GET" action="medical-records.php" onsubmit="return validateSearch(event)">
                <div class="search-filter-bar">
                    <div class="search-box" style="flex: 1;">
                        <i class="fas fa-search"></i>
                        <input type="text" 
                               id="studentSearch" 
                               name="search" 
                               value="<?php echo htmlspecialchars($searchQuery); ?>"
                               placeholder="Enter Student ID, Student Code, or National ID..." 
                               required
                               autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary" data-en="Search" data-ar="بحث">Search</button>
                    <?php if (!empty($searchQuery)): ?>
                        <a href="medical-records.php" class="btn btn-secondary" data-en="Clear" data-ar="مسح">Clear</a>
                    <?php endif; ?>
                </div>
                <div style="font-size: 0.85rem; color: #666; margin-top: 0.5rem; padding-left: 0.5rem;">
                    <i class="fas fa-info-circle"></i>
                    <span data-en="Search by: Student ID, Student Code (e.g., S1001), or National ID" data-ar="البحث عن طريق: رقم الطالب، رمز الطالب (مثل S1001)، أو الرقم الوطني">
                        Search by: Student ID, Student Code (e.g., S1001), or National ID
                    </span>
                </div>
            </form>
        </div>

        <?php if (!empty($searchQuery)): ?>
            <?php if ($searchedStudent): ?>
                
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">👨‍🎓</span>
                            <span data-en="Student Information" data-ar="معلومات الطالب">Student Information</span>
                        </h2>
                    </div>
                    <div style="padding: 1.5rem; background: #FFF9F5; border-radius: 15px; margin-bottom: 1.5rem;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                            <div>
                                <div style="font-weight: 700; color: #666; font-size: 0.9rem; margin-bottom: 0.3rem;" data-en="Name" data-ar="الاسم">Name</div>
                                <div style="font-size: 1.1rem; font-weight: 700;">
                                    <?php echo htmlspecialchars($searchedStudent['NameEn']); ?>
                                    <?php if (!empty($searchedStudent['NameAr'])): ?>
                                        <span style="color: #666;">(<?php echo htmlspecialchars($searchedStudent['NameAr']); ?>)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <div style="font-weight: 700; color: #666; font-size: 0.9rem; margin-bottom: 0.3rem;" data-en="Student Code" data-ar="رمز الطالب">Student Code</div>
                                <div style="font-size: 1.1rem; font-weight: 700;"><?php echo htmlspecialchars($searchedStudent['Student_Code']); ?></div>
                            </div>
                            <?php if (!empty($searchedStudent['National_ID'])): ?>
                            <div>
                                <div style="font-weight: 700; color: #666; font-size: 0.9rem; margin-bottom: 0.3rem;" data-en="National ID" data-ar="الرقم الوطني">National ID</div>
                                <div style="font-size: 1.1rem; font-weight: 700;"><?php echo htmlspecialchars($searchedStudent['National_ID']); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if ($searchedStudent['ClassName']): ?>
                            <div>
                                <div style="font-weight: 700; color: #666; font-size: 0.9rem; margin-bottom: 0.3rem;" data-en="Class" data-ar="الفصل">Class</div>
                                <div style="font-size: 1.1rem; font-weight: 700;">
                                    <?php echo htmlspecialchars($searchedStudent['ClassName']); ?>
                                    <?php if ($searchedStudent['Grade_Level']): ?>
                                        <span style="color: #666;">(Grade <?php echo $searchedStudent['Grade_Level']; ?>)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📋</span>
                            <span data-en="Medical Record" data-ar="السجل الطبي">Medical Record</span>
                        </h2>
                    </div>
                    <form method="POST" action="medical-records.php?search=<?php echo urlencode($searchQuery); ?>" onsubmit="return validateMedicalForm(event)">
                        <input type="hidden" name="action" value="saveMedicalRecord">
                        <input type="hidden" name="studentId" value="<?php echo $searchedStudent['Student_ID']; ?>">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
                            <div class="form-group">
                                <label data-en="Allergies" data-ar="الحساسية">Allergies</label>
                                <input type="text" 
                                       id="allergies" 
                                       name="allergies" 
                                       value="<?php echo htmlspecialchars($medicalRecord['Allergies'] ?? ''); ?>"
                                       placeholder="Enter allergies or 'None'">
                            </div>
                            <div class="form-group">
                                <label data-en="Blood Type" data-ar="فصيلة الدم">Blood Type</label>
                                <select id="bloodType" name="bloodType">
                                    <option value="">Select Blood Type</option>
                                    <option value="A+" <?php echo ($medicalRecord['Blood_Type'] ?? '') === 'A+' ? 'selected' : ''; ?>>A+</option>
                                    <option value="A-" <?php echo ($medicalRecord['Blood_Type'] ?? '') === 'A-' ? 'selected' : ''; ?>>A-</option>
                                    <option value="B+" <?php echo ($medicalRecord['Blood_Type'] ?? '') === 'B+' ? 'selected' : ''; ?>>B+</option>
                                    <option value="B-" <?php echo ($medicalRecord['Blood_Type'] ?? '') === 'B-' ? 'selected' : ''; ?>>B-</option>
                                    <option value="AB+" <?php echo ($medicalRecord['Blood_Type'] ?? '') === 'AB+' ? 'selected' : ''; ?>>AB+</option>
                                    <option value="AB-" <?php echo ($medicalRecord['Blood_Type'] ?? '') === 'AB-' ? 'selected' : ''; ?>>AB-</option>
                                    <option value="O+" <?php echo ($medicalRecord['Blood_Type'] ?? '') === 'O+' ? 'selected' : ''; ?>>O+</option>
                                    <option value="O-" <?php echo ($medicalRecord['Blood_Type'] ?? '') === 'O-' ? 'selected' : ''; ?>>O-</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label data-en="Emergency Contact" data-ar="جهة الاتصال في حالات الطوارئ">Emergency Contact</label>
                                <input type="tel" 
                                       id="emergencyContact" 
                                       name="emergencyContact"
                                       value="<?php echo htmlspecialchars($medicalRecord['Emergency_Contact'] ?? ''); ?>"
                                       placeholder="+962 7XX XXX XXX">
                            </div>
                            <div class="form-group">
                                <label data-en="Primary Physician" data-ar="الطبيب الأساسي">Primary Physician</label>
                                <input type="text" 
                                       id="primaryPhysician" 
                                       name="primaryPhysician"
                                       value="<?php echo htmlspecialchars($medicalRecord['Primary_Physician'] ?? ''); ?>"
                                       placeholder="Dr. Name">
                            </div>
                        </div>
                        <div class="form-group">
                            <label data-en="Current Medications" data-ar="الأدوية الحالية">Current Medications</label>
                            <textarea id="medications" 
                                      name="medications" 
                                      rows="3" 
                                      placeholder="List current medications or 'None'"><?php echo htmlspecialchars($medicalRecord['Current_Medications'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label data-en="Medical Notes" data-ar="ملاحظات طبية">Medical Notes</label>
                            <textarea id="medicalNotes" 
                                      name="medicalNotes" 
                                      rows="4" 
                                      placeholder="Additional medical information..."><?php echo htmlspecialchars($medicalRecord['Medical_Notes'] ?? ''); ?></textarea>
                        </div>
                        <div class="action-buttons">
                            <button type="submit" class="btn btn-primary" data-en="Save Medical Record" data-ar="حفظ السجل الطبي">Save Medical Record</button>
                        </div>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📜</span>
                            <span data-en="Medical History" data-ar="السجل الطبي">Medical History</span>
                        </h2>
                        <button class="btn btn-primary btn-small" onclick="openHistoryModal()" data-en="+ Add Entry" data-ar="+ إضافة سجل">+ Add Entry</button>
                    </div>
                    <div id="medicalHistoryList" class="user-list">
                        <?php if (empty($medicalHistory)): ?>
                            <div style="text-align: center; padding: 2rem; color: #666;">
                                <div style="font-size: 2rem; margin-bottom: 0.5rem;">📜</div>
                                <div data-en="No medical history entries" data-ar="لا توجد سجلات طبية">No medical history entries</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($medicalHistory as $entry): ?>
                                <?php
                                $entryDate = new DateTime($entry['Date']);
                                $formattedDate = $entryDate->format('M d, Y');
                                $typeLabels = [
                                    'checkup' => ['en' => 'Routine Checkup', 'ar' => 'فحص روتيني'],
                                    'vaccination' => ['en' => 'Vaccination', 'ar' => 'تطعيم'],
                                    'injury' => ['en' => 'Injury Report', 'ar' => 'تقرير إصابة'],
                                    'illness' => ['en' => 'Illness', 'ar' => 'مرض'],
                                    'medication' => ['en' => 'Medication', 'ar' => 'دواء'],
                                    'other' => ['en' => 'Other', 'ar' => 'أخرى']
                                ];
                                $typeLabel = $typeLabels[$entry['Type']] ?? ['en' => $entry['Type'], 'ar' => $entry['Type']];
                                ?>
                                <div class="user-item">
                                    <div class="user-info-item" style="flex: 1;">
                                        <div class="user-avatar-item">📋</div>
                                        <div>
                                            <div style="font-weight: 700;"><?php echo $typeLabel['en']; ?></div>
                                            <div style="font-size: 0.9rem; color: #666;">
                                                <?php echo $formattedDate; ?>
                                                <?php if (!empty($entry['Physician'])): ?>
                                                    • <?php echo htmlspecialchars($entry['Physician']); ?>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($entry['Description'])): ?>
                                                <div style="font-size: 0.85rem; color: #999; margin-top: 0.3rem;">
                                                    <?php echo htmlspecialchars($entry['Description']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($entry['Notes'])): ?>
                                                <div style="font-size: 0.85rem; color: #666; margin-top: 0.3rem; font-style: italic;">
                                                    <strong>Notes:</strong> <?php echo htmlspecialchars($entry['Notes']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <button type="button" 
                                            class="btn btn-danger btn-small" 
                                            onclick="deleteMedicalHistory(<?php echo $entry['History_ID']; ?>, <?php echo $searchedStudent['Student_ID']; ?>)"
                                            data-en="Delete" 
                                            data-ar="حذف">Delete</button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                
                <div class="card">
                    <div style="text-align: center; padding: 3rem; color: #666;">
                        <div style="font-size: 4rem; margin-bottom: 1rem;">🔍</div>
                        <div style="font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; color: #FF6B9D;" data-en="Student not found" data-ar="لم يتم العثور على الطالب">Student not found</div>
                        <div style="font-size: 1rem; margin-bottom: 1.5rem;" data-en="No student found with the ID: <?php echo htmlspecialchars($searchQuery); ?>" data-ar="لم يتم العثور على طالب بالرقم: <?php echo htmlspecialchars($searchQuery); ?>">
                            No student found with the ID: <strong><?php echo htmlspecialchars($searchQuery); ?></strong>
                        </div>
                        <div style="font-size: 0.9rem; color: #999; margin-bottom: 1rem;" data-en="Please check the ID and try again. You can search by:" data-ar="يرجى التحقق من الرقم والمحاولة مرة أخرى. يمكنك البحث عن طريق:">
                            Please check the ID and try again. You can search by:
                        </div>
                        <ul style="text-align: left; display: inline-block; font-size: 0.9rem; color: #666;">
                            <li data-en="Student Code (e.g., S1001)" data-ar="رمز الطالب (مثل S1001)">Student Code (e.g., S1001)</li>
                            <li data-en="National ID" data-ar="الرقم الوطني">National ID</li>
                            <li data-en="Student ID (numeric)" data-ar="رقم الطالب (رقمي)">Student ID (numeric)</li>
                        </ul>
                        <a href="medical-records.php" class="btn btn-primary" style="margin-top: 1.5rem; display: inline-block;" data-en="Try Another Search" data-ar="حاول بحثاً آخر">Try Another Search</a>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            
            <div class="card">
                <div style="text-align: center; padding: 3rem; color: #666;">
                    <div style="font-size: 4rem; margin-bottom: 1rem;">🏥</div>
                    <div style="font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; color: var(--primary-color);" data-en="Search for a Student" data-ar="ابحث عن طالب">Search for a Student</div>
                    <div style="font-size: 1rem; margin-bottom: 1.5rem;" data-en="Enter a Student ID, Student Code, or National ID to view and edit their medical record." data-ar="أدخل رقم الطالب أو رمز الطالب أو الرقم الوطني لعرض وتعديل سجله الطبي.">
                        Enter a Student ID, Student Code, or National ID to view and edit their medical record.
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($searchedStudent): ?>
    <div class="modal" id="historyModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" data-en="Add Medical History Entry" data-ar="إضافة سجل طبي">Add Medical History Entry</h2>
                <button class="modal-close" onclick="closeModal('historyModal')">&times;</button>
            </div>
            <form id="historyForm" method="POST" onsubmit="return submitHistoryForm(event)">
                <input type="hidden" name="action" value="saveMedicalHistory">
                <input type="hidden" name="studentId" value="<?php echo $searchedStudent['Student_ID']; ?>">
                <div class="form-group">
                    <label data-en="Date" data-ar="التاريخ">Date <span style="color: red;">*</span></label>
                    <input type="date" id="historyDate" name="historyDate" required>
                </div>
                <div class="form-group">
                    <label data-en="Type" data-ar="النوع">Type <span style="color: red;">*</span></label>
                    <select id="historyType" name="historyType" required>
                        <option value="checkup" data-en="Routine Checkup" data-ar="فحص روتيني">Routine Checkup</option>
                        <option value="vaccination" data-en="Vaccination" data-ar="تطعيم">Vaccination</option>
                        <option value="injury" data-en="Injury Report" data-ar="تقرير إصابة">Injury Report</option>
                        <option value="illness" data-en="Illness" data-ar="مرض">Illness</option>
                        <option value="medication" data-en="Medication" data-ar="دواء">Medication</option>
                        <option value="other" data-en="Other" data-ar="أخرى">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label data-en="Description" data-ar="الوصف">Description <span style="color: red;">*</span></label>
                    <textarea id="historyDescription" name="historyDescription" rows="3" required placeholder="Describe the medical event..."></textarea>
                </div>
                <div class="form-group">
                    <label data-en="Physician/Clinic" data-ar="الطبيب/العيادة">Physician/Clinic</label>
                    <input type="text" id="historyPhysician" name="historyPhysician" placeholder="Dr. Name or Clinic Name">
                </div>
                <div class="form-group">
                    <label data-en="Additional Notes" data-ar="ملاحظات إضافية">Additional Notes</label>
                    <textarea id="historyNotes" name="historyNotes" rows="3" placeholder="Any additional information..."></textarea>
                </div>
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" data-en="Save Entry" data-ar="حفظ السجل">Save Entry</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('historyModal')" data-en="Cancel" data-ar="إلغاء">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script src="script.js"></script>
    <script>
        
        function openHistoryModal() {
            const modal = document.getElementById('historyModal');
            if (!modal) {
                console.error('Modal element "historyModal" not found in DOM');
                if (typeof showNotification === 'function') {
                    showNotification(currentLanguage === 'en' ? 'Error: Modal not found. Please refresh the page.' : 'خطأ: لم يتم العثور على النافذة. يرجى تحديث الصفحة.', 'error');
                } else {
                    alert('Modal not found. Please refresh the page.');
                }
                return;
            }

            const dateInput = document.getElementById('historyDate');
            if (dateInput) {
                dateInput.value = new Date().toISOString().split('T')[0];
            }

            modal.classList.add('active');
            
            modal.style.display = 'flex';
            modal.style.alignItems = 'center';
            modal.style.justifyContent = 'center';
            document.body.style.overflow = 'hidden';
        }

        window.openHistoryModal = openHistoryModal;

        const originalCloseModal = window.closeModal || function(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        };
        
        window.closeModal = function(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
                
                modal.style.display = '';
                modal.style.alignItems = '';
                modal.style.justifyContent = '';
                document.body.style.overflow = '';
            }
        };
        
        function validateSearch(event) {
            const searchInput = document.getElementById('studentSearch');
            const searchValue = searchInput.value.trim();
            
            if (!searchValue) {
                event.preventDefault();
                showNotification(currentLanguage === 'en' ? 'Please enter a Student ID, Student Code, or National ID' : 'يرجى إدخال رقم الطالب أو رمز الطالب أو الرقم الوطني', 'error');
                return false;
            }
            
            return true;
        }

        function validateMedicalForm(event) {
            
            return true;
        }

        function validateHistoryForm(event) {
            const date = document.getElementById('historyDate').value;
            const description = document.getElementById('historyDescription').value.trim();
            
            if (!date) {
                event.preventDefault();
                showNotification(currentLanguage === 'en' ? 'Please select a date' : 'يرجى اختيار تاريخ', 'error');
                return false;
            }
            
            if (!description) {
                event.preventDefault();
                showNotification(currentLanguage === 'en' ? 'Please enter a description' : 'يرجى إدخال وصف', 'error');
                return false;
            }
            
            return true;
        }

        function submitHistoryForm(event) {
            event.preventDefault();
            
            if (!validateHistoryForm(event)) {
                return false;
            }
            
            const form = document.getElementById('historyForm');
            const formData = new FormData(form);
            formData.append('ajax', '1'); 
            
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span data-en="Saving..." data-ar="جاري الحفظ...">Saving...</span>';
            
            fetch('medical-records.php?search=<?php echo urlencode($searchQuery ?? ''); ?>', {
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
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
                
                if (data.success) {
                    
                    showNotification(data.message || (currentLanguage === 'en' ? 'Medical history entry added successfully!' : 'تم إضافة السجل الطبي بنجاح!'), 'success');

                    form.reset();
                    
                    const dateInput = document.getElementById('historyDate');
                    if (dateInput) {
                        dateInput.value = new Date().toISOString().split('T')[0];
                    }

                    loadMedicalHistory();
                } else {
                    showNotification(data.message || (currentLanguage === 'en' ? 'Error saving medical history' : 'خطأ في حفظ السجل الطبي'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
                showNotification(currentLanguage === 'en' ? 'Error: ' + error.message : 'خطأ: ' + error.message, 'error');
            });
            
            return false;
        }

        function loadMedicalHistory() {
            const studentId = <?php echo isset($searchedStudent) && isset($searchedStudent['Student_ID']) ? $searchedStudent['Student_ID'] : 0; ?>;
            if (!studentId) {
                console.error('Student ID not found');
                return;
            }
            
            const searchQuery = '<?php echo urlencode($searchQuery ?? ''); ?>';
            fetch('medical-records.php?action=getHistory&studentId=' + studentId + (searchQuery ? '&search=' + searchQuery : ''))
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text();
            })
            .then(html => {
                const historyList = document.getElementById('medicalHistoryList');
                if (historyList) {
                    historyList.innerHTML = html;
                } else {
                    console.error('Medical history list element not found');
                }
            })
            .catch(error => {
                console.error('Error loading medical history:', error);
                
                window.location.reload();
            });
        }

        function deleteMedicalHistory(historyId, studentId) {
            if (!confirm(currentLanguage === 'en' ? 'Are you sure you want to delete this entry?' : 'هل أنت متأكد من حذف هذا السجل؟')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'deleteMedicalHistory');
            formData.append('historyId', historyId);
            formData.append('studentId', studentId);
            formData.append('ajax', '1');
            
            fetch('medical-records.php?search=<?php echo urlencode($searchQuery ?? ''); ?>', {
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
                    showNotification(data.message || (currentLanguage === 'en' ? 'Medical history entry deleted successfully!' : 'تم حذف السجل الطبي بنجاح!'), 'success');
                    loadMedicalHistory();
                } else {
                    showNotification(data.message || (currentLanguage === 'en' ? 'Error deleting medical history' : 'خطأ في حذف السجل الطبي'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(currentLanguage === 'en' ? 'Error: ' + error.message : 'خطأ: ' + error.message, 'error');
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const successMsg = document.querySelector('[style*="background: #6BCB77"]');
            const errorMsg = document.querySelector('[style*="background: #FF6B9D"]');
            
            if (successMsg) {
                setTimeout(() => {
                    successMsg.style.transition = 'opacity 0.5s';
                    successMsg.style.opacity = '0';
                    setTimeout(() => successMsg.remove(), 500);
                }, 5000);
            }
            
            if (errorMsg) {
                setTimeout(() => {
                    errorMsg.style.transition = 'opacity 0.5s';
                    errorMsg.style.opacity = '0';
                    setTimeout(() => errorMsg.remove(), 500);
                }, 8000);
            }

            const addEntryBtn = document.querySelector('button[onclick*="openHistoryModal"]');
            if (addEntryBtn) {
                addEntryBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    openHistoryModal();
                });
            }
        });
    </script>
</body>
</html>
