<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-parent.php';

requireUserType('parent');

$currentParentId = getCurrentUserId();
$currentParent = getCurrentUserData($pdo);

if (!$currentParentId || !is_numeric($currentParentId)) {
    error_log("Error: Invalid parent ID: " . $currentParentId);
    header("Location: parent-dashboard.php?error=invalid_parent");
    exit();
}

$parentFullData = null;
$parentName = 'Parent';
try {
    $stmt = $pdo->prepare("SELECT Parent_ID, NameEn, NameAr, Email FROM parent WHERE Parent_ID = ?");
    $stmt->execute([$currentParentId]);
    $parentFullData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($parentFullData) {
        $parentName = $parentFullData['NameEn'] ?? $parentFullData['NameAr'] ?? $_SESSION['user_name'] ?? 'Parent';
    } else {
        $parentName = $_SESSION['user_name'] ?? 'Parent';
    }
} catch (PDOException $e) {
    error_log("Error fetching parent data: " . $e->getMessage());
    $parentName = $_SESSION['user_name'] ?? 'Parent';
}

$linkedStudentIds = [];
$linkedStudentsData = [];
try {
    $stmt = $pdo->prepare("
        SELECT psr.Student_ID, psr.Relationship_Type, psr.Is_Primary
        FROM parent_student_relationship psr
        WHERE psr.Parent_ID = ?
        ORDER BY psr.Is_Primary DESC, psr.Created_At ASC
    ");
    $stmt->execute([$currentParentId]);
    $relationships = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $linkedStudentIds = array_column($relationships, 'Student_ID');
    
    if (!empty($linkedStudentIds)) {
        $placeholders = implode(',', array_fill(0, count($linkedStudentIds), '?'));
        $stmt = $pdo->prepare("
            SELECT s.Student_ID, s.Student_Code, s.NameEn, s.NameAr, s.Class_ID, 
                   c.Name as Class_Name, c.Grade_Level, c.Section,
                   psr.Relationship_Type, psr.Is_Primary
            FROM student s
            LEFT JOIN class c ON s.Class_ID = c.Class_ID
            LEFT JOIN parent_student_relationship psr ON s.Student_ID = psr.Student_ID AND psr.Parent_ID = ?
            WHERE s.Student_ID IN ($placeholders)
            ORDER BY psr.Is_Primary DESC, s.NameEn ASC
        ");
        $params = array_merge([$currentParentId], $linkedStudentIds);
        $stmt->execute($params);
        $linkedStudentsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching linked students: " . $e->getMessage());
    $linkedStudentIds = [];
    $linkedStudentsData = [];
}

$selectedStudentId = isset($_GET['studentId']) ? intval($_GET['studentId']) : null;

$selectedStudent = null;
if ($selectedStudentId) {
    
    $isLinked = false;
    foreach ($linkedStudentsData as $student) {
        if ($student['Student_ID'] == $selectedStudentId) {
            $isLinked = true;
            $selectedStudent = $student;
            break;
        }
    }
    
    if (!$isLinked) {
        
        error_log("Security: Parent $currentParentId attempted to access medical records for unlinked student $selectedStudentId");
        header("Location: medical-records.php?error=unauthorized");
        exit();
    }
} elseif (!empty($linkedStudentsData)) {
    
    $selectedStudent = $linkedStudentsData[0];
    $selectedStudentId = $selectedStudent['Student_ID'];
}

$medicalRecord = null;
$medicalHistory = [];

if ($selectedStudentId) {
    try {
        
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM parent_student_relationship 
            WHERE Parent_ID = ? AND Student_ID = ?
        ");
        $stmt->execute([$currentParentId, $selectedStudentId]);
        $isAuthorized = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
        
        if (!$isAuthorized) {
            error_log("Security: Unauthorized access attempt - Parent $currentParentId, Student $selectedStudentId");
            header("Location: medical-records.php?error=unauthorized");
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT mr.*, s.NameEn, s.NameAr, s.Student_Code
            FROM medical_record mr
            INNER JOIN student s ON mr.Student_ID = s.Student_ID
            WHERE mr.Student_ID = ?
        ");
        $stmt->execute([$selectedStudentId]);
        $medicalRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT *
            FROM medical_history
            WHERE Student_ID = ?
            ORDER BY Date DESC, Created_At DESC
            LIMIT 50
        ");
        $stmt->execute([$selectedStudentId]);
        $medicalHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Error fetching medical records: " . $e->getMessage());
        $medicalRecord = null;
        $medicalHistory = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Records - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        .medical-overview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .medical-record-card {
            background: #FFF9F5;
            padding: 1.5rem;
            border-radius: 15px;
            border-left: 4px solid #FF6B9D;
        }

        .medical-record-label {
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .medical-record-value {
            color: #666;
            line-height: 1.6;
        }

        .medical-history-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .medical-history-item {
            background: linear-gradient(135deg, #FFF9F5, #E5F3FF);
            border-radius: 20px;
            padding: 1.5rem;
            border-left: 5px solid #6BCB77;
            transition: all 0.3s;
        }

        .medical-history-item:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .medical-history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #FFE5E5;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .medical-history-date {
            font-weight: 700;
            color: var(--primary-color);
        }

        .medical-history-type {
            background: linear-gradient(135deg, #FF6B9D, #6BCB77);
            color: white;
            padding: 0.4rem 1rem;
            border-radius: 15px;
            font-size: 0.85rem;
            font-weight: 700;
        }

        .medical-history-details {
            margin-top: 1rem;
        }

        .medical-history-doctor {
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .medical-history-notes {
            color: #666;
            line-height: 1.6;
        }

        .medications-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .medication-item {
            background: #FFF9F5;
            padding: 1.5rem;
            border-radius: 15px;
            border-left: 4px solid #6BCB77;
        }

        .medication-name {
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .medication-info {
            color: #666;
        }

        .emergency-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .emergency-info-item {
            background: linear-gradient(135deg, #FFE5E5, #FFF9F5);
            padding: 1.5rem;
            border-radius: 20px;
            text-align: center;
            transition: all 0.3s;
        }

        .emergency-info-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }

        .emergency-info-label {
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .emergency-info-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.3rem;
        }

        .emergency-info-relation {
            color: #666;
            font-size: 0.9rem;
        }

        .student-selector {
            margin-bottom: 2rem;
        }

        .student-selector select {
            width: 100%;
            padding: 1rem;
            border: 3px solid #FFE5E5;
            border-radius: 15px;
            font-family: 'Nunito', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
        }

        .student-selector select:focus {
            outline: none;
            border-color: #FF6B9D;
            transform: scale(1.02);
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #666;
        }

        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .medical-overview-grid,
            .emergency-info-grid {
                grid-template-columns: 1fr;
            }
            
            .medical-history-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>
    
    <div class="main-content">

    <div class="dashboard-container">
        
        <div class="page-header">
            <a href="parent-dashboard.php" class="btn-back" data-en="← Back to Dashboard" data-ar="← العودة للوحة التحكم">
                <i class="fas fa-arrow-left"></i>
                <span data-en="Back to Dashboard" data-ar="العودة للوحة التحكم">Back to Dashboard</span>
            </a>
            <h1 class="page-title">
                <span class="page-icon">🏥</span>
                <span data-en="Medical Records" data-ar="السجلات الطبية">Medical Records</span>
            </h1>
            <p class="page-subtitle" data-en="View and manage your child's medical information" data-ar="عرض وإدارة المعلومات الطبية لطفلك">View and manage your child's medical information</p>
        </div>

        <?php if (empty($linkedStudentsData)): ?>
            
            <div class="card">
                <div class="empty-state">
                    <div class="empty-state-icon">👨‍👩‍👧</div>
                    <h2 data-en="No Students Linked" data-ar="لا يوجد طلاب مرتبطين">No Students Linked</h2>
                    <p data-en="You don't have any students linked to your account. Please contact the administrator to link your child(ren) to your account." data-ar="ليس لديك أي طلاب مرتبطين بحسابك. يرجى الاتصال بالإدارة لربط طفلك (أطفالك) بحسابك.">
                        You don't have any students linked to your account. Please contact the administrator to link your child(ren) to your account.
                    </p>
                </div>
            </div>
        <?php else: ?>
            
            <?php if (count($linkedStudentsData) > 1): ?>
                <div class="card student-selector">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label data-en="Select Student" data-ar="اختر الطالب">Select Student</label>
                        <select onchange="window.location.href='medical-records.php?studentId=' + this.value">
                            <option value="" data-en="Select a student" data-ar="اختر طالباً">Select a student</option>
                            <?php foreach ($linkedStudentsData as $student): ?>
                                <option value="<?php echo $student['Student_ID']; ?>" <?php echo ($selectedStudentId == $student['Student_ID']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($student['NameEn']); ?>
                                    <?php if ($student['Class_Name']): ?>
                                        - <?php echo htmlspecialchars($student['Class_Name']); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$selectedStudent): ?>
                
                <div class="card">
                    <div class="empty-state">
                        <div class="empty-state-icon">🏥</div>
                        <h2 data-en="Select a Student" data-ar="اختر طالباً">Select a Student</h2>
                        <p data-en="Please select a student to view their medical records." data-ar="يرجى اختيار طالب لعرض سجلاته الطبية.">
                            Please select a student to view their medical records.
                        </p>
                    </div>
                </div>
            <?php elseif (!$medicalRecord): ?>
                
                <div class="card">
                    <div class="empty-state">
                        <div class="empty-state-icon">🏥</div>
                        <h2 data-en="No Medical Records Available" data-ar="لا توجد سجلات طبية متاحة">No Medical Records Available</h2>
                        <p data-en="Medical records for <?php echo htmlspecialchars($selectedStudent['NameEn']); ?> have not been added yet. Please contact the school administration to add medical information." data-ar="لم يتم إضافة السجلات الطبية لـ <?php echo htmlspecialchars($selectedStudent['NameEn']); ?> بعد. يرجى الاتصال بإدارة المدرسة لإضافة المعلومات الطبية.">
                            Medical records for <?php echo htmlspecialchars($selectedStudent['NameEn']); ?> have not been added yet. Please contact the school administration to add medical information.
                        </p>
                    </div>
                </div>
            <?php else: ?>
                
                <div class="card" style="background: linear-gradient(135deg, #FFE5E5 0%, #E5F3FF 100%); margin-bottom: 2rem;">
                    <div style="display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap;">
                        <div style="font-size: 3rem;"><?php echo (strpos($selectedStudent['NameEn'], ' ') !== false && strtolower(substr($selectedStudent['NameEn'], 0, 1)) === 's') ? '👧' : '👦'; ?></div>
                        <div style="flex: 1;">
                            <h2 style="font-family: 'Fredoka', sans-serif; font-size: 1.8rem; color: var(--text-dark); margin-bottom: 0.5rem;">
                                <?php echo htmlspecialchars($selectedStudent['NameEn']); ?>
                            </h2>
                            <?php if ($selectedStudent['Class_Name']): ?>
                                <div style="color: #666; font-size: 0.9rem;">
                                    <i class="fas fa-graduation-cap" style="margin-right: 0.3rem;"></i>
                                    <?php echo htmlspecialchars($selectedStudent['Class_Name']); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($selectedStudent['Student_Code']): ?>
                                <div style="color: #999; font-size: 0.85rem; margin-top: 0.3rem;">
                                    <span data-en="Student ID:" data-ar="رقم الطالب:">Student ID:</span> <?php echo htmlspecialchars($selectedStudent['Student_Code']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📋</span>
                            <span data-en="Medical Overview" data-ar="نظرة عامة طبية">Medical Overview</span>
                        </h2>
                    </div>
                    <div class="medical-overview-grid">
                        <div class="medical-record-card">
                            <div class="medical-record-label" data-en="Allergies" data-ar="الحساسية">Allergies</div>
                            <div class="medical-record-value">
                                <?php if (!empty($medicalRecord['Allergies'])): ?>
                                    <?php echo nl2br(htmlspecialchars($medicalRecord['Allergies'])); ?>
                                <?php else: ?>
                                    <span data-en="No known allergies" data-ar="لا توجد حساسية معروفة">No known allergies</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($medicalRecord['Blood_Type'])): ?>
                        <div class="medical-record-card">
                            <div class="medical-record-label" data-en="Blood Type" data-ar="فصيلة الدم">Blood Type</div>
                            <div class="medical-record-value"><?php echo htmlspecialchars($medicalRecord['Blood_Type']); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($medicalRecord['Emergency_Contact'])): ?>
                        <div class="medical-record-card">
                            <div class="medical-record-label" data-en="Emergency Contact" data-ar="جهة الاتصال في حالات الطوارئ">Emergency Contact</div>
                            <div class="medical-record-value"><?php echo htmlspecialchars($medicalRecord['Emergency_Contact']); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($medicalRecord['Primary_Physician'])): ?>
                        <div class="medical-record-card">
                            <div class="medical-record-label" data-en="Primary Physician" data-ar="الطبيب الأساسي">Primary Physician</div>
                            <div class="medical-record-value"><?php echo htmlspecialchars($medicalRecord['Primary_Physician']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📜</span>
                            <span data-en="Medical History" data-ar="السجل الطبي">Medical History</span>
                        </h2>
                    </div>
                    <div class="medical-history-list">
                        <?php if (empty($medicalHistory)): ?>
                            <div style="text-align: center; padding: 2rem; color: #666;">
                                <div style="font-size: 2rem; margin-bottom: 0.5rem;">📜</div>
                                <div data-en="No medical history records available" data-ar="لا توجد سجلات تاريخ طبي متاحة">No medical history records available</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($medicalHistory as $history): ?>
                                <?php
                                $typeLabels = [
                                    'illness' => ['en' => 'Illness', 'ar' => 'مرض'],
                                    'injury' => ['en' => 'Injury', 'ar' => 'إصابة'],
                                    'vaccination' => ['en' => 'Vaccination', 'ar' => 'تطعيم'],
                                    'checkup' => ['en' => 'Routine Checkup', 'ar' => 'فحص روتيني'],
                                    'medication' => ['en' => 'Medication', 'ar' => 'دواء'],
                                    'other' => ['en' => 'Other', 'ar' => 'أخرى']
                                ];
                                $typeLabel = $typeLabels[$history['Type']] ?? ['en' => ucfirst($history['Type']), 'ar' => $history['Type']];
                                $historyDate = new DateTime($history['Date']);
                                $formattedDate = $historyDate->format('M d, Y');
                                ?>
                                <div class="medical-history-item">
                                    <div class="medical-history-header">
                                        <div class="medical-history-date"><?php echo $formattedDate; ?></div>
                                        <div class="medical-history-type">
                                            <span data-en="<?php echo $typeLabel['en']; ?>" data-ar="<?php echo $typeLabel['ar']; ?>"><?php echo $typeLabel['en']; ?></span>
                                        </div>
                                    </div>
                                    <div class="medical-history-details">
                                        <?php if (!empty($history['Physician'])): ?>
                                            <div class="medical-history-doctor">
                                                <i class="fas fa-user-md" style="margin-right: 0.5rem;"></i>
                                                <?php echo htmlspecialchars($history['Physician']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="medical-history-notes">
                                            <?php echo nl2br(htmlspecialchars($history['Description'])); ?>
                                        </div>
                                        <?php if (!empty($history['Notes'])): ?>
                                            <div class="medical-history-notes" style="margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #FFE5E5; font-style: italic;">
                                                <strong data-en="Additional Notes:" data-ar="ملاحظات إضافية:">Additional Notes:</strong> <?php echo nl2br(htmlspecialchars($history['Notes'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">💊</span>
                            <span data-en="Current Medications" data-ar="الأدوية الحالية">Current Medications</span>
                        </h2>
                    </div>
                    <div class="medications-list">
                        <?php if (!empty($medicalRecord['Current_Medications'])): ?>
                            <div class="medication-item">
                                <div class="medication-name" data-en="Medications" data-ar="الأدوية">Medications</div>
                                <div class="medication-info"><?php echo nl2br(htmlspecialchars($medicalRecord['Current_Medications'])); ?></div>
                            </div>
                        <?php else: ?>
                            <div class="medication-item">
                                <div class="medication-name" data-en="No Current Medications" data-ar="لا توجد أدوية حالية">No Current Medications</div>
                                <div class="medication-info" data-en="No medications are currently prescribed." data-ar="لا توجد أدوية موصوفة حالياً.">No medications are currently prescribed.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($medicalRecord['Medical_Notes'])): ?>
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">📝</span>
                            <span data-en="Medical Notes" data-ar="ملاحظات طبية">Medical Notes</span>
                        </h2>
                    </div>
                    <div style="padding: 1.5rem; background: #FFF9F5; border-radius: 15px; border-left: 4px solid #FF6B9D;">
                        <div style="color: #666; line-height: 1.8;">
                            <?php echo nl2br(htmlspecialchars($medicalRecord['Medical_Notes'])); ?>
                        </div>
                        <?php if ($medicalRecord['Updated_At']): ?>
                            <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #FFE5E5; font-size: 0.85rem; color: #999;">
                                <i class="fas fa-clock" style="margin-right: 0.3rem;"></i>
                                <span data-en="Last updated:" data-ar="آخر تحديث:">Last updated:</span> 
                                <?php echo date('M d, Y g:i A', strtotime($medicalRecord['Updated_At'])); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span class="card-icon">🚨</span>
                            <span data-en="Emergency Information" data-ar="معلومات الطوارئ">Emergency Information</span>
                        </h2>
                    </div>
                    <div class="emergency-info-grid">
                        <?php if (!empty($medicalRecord['Emergency_Contact'])): ?>
                        <div class="emergency-info-item">
                            <div class="emergency-info-label" data-en="Emergency Contact" data-ar="جهة الاتصال في حالات الطوارئ">Emergency Contact</div>
                            <div class="emergency-info-value"><?php echo htmlspecialchars($medicalRecord['Emergency_Contact']); ?></div>
                            <div class="emergency-info-relation" data-en="Contact Number" data-ar="رقم الاتصال">Contact Number</div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($medicalRecord['Primary_Physician'])): ?>
                        <div class="emergency-info-item">
                            <div class="emergency-info-label" data-en="Primary Physician" data-ar="الطبيب الأساسي">Primary Physician</div>
                            <div class="emergency-info-value"><?php echo htmlspecialchars($medicalRecord['Primary_Physician']); ?></div>
                            <div class="emergency-info-relation" data-en="Family Doctor" data-ar="طبيب العائلة">Family Doctor</div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($medicalRecord['Blood_Type'])): ?>
                        <div class="emergency-info-item">
                            <div class="emergency-info-label" data-en="Blood Type" data-ar="فصيلة الدم">Blood Type</div>
                            <div class="emergency-info-value"><?php echo htmlspecialchars($medicalRecord['Blood_Type']); ?></div>
                            <div class="emergency-info-relation" data-en="Important for emergencies" data-ar="مهم في حالات الطوارئ">Important for emergencies</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="script.js"></script>
    <script>
        function openProfileSettings() {
            window.location.href = 'notifications-and-settings.php#profile';
        }

        function openSettings() {
            window.location.href = 'notifications-and-settings.php';
        }

        <?php if (isset($_GET['error']) && $_GET['error'] === 'unauthorized'): ?>
        document.addEventListener('DOMContentLoaded', function() {
            showNotification(
                currentLanguage === 'en' 
                    ? 'Unauthorized access. You can only view medical records for your linked children.' 
                    : 'وصول غير مصرح به. يمكنك فقط عرض السجلات الطبية لأطفالك المرتبطين.',
                'error'
            );
        });
        <?php endif; ?>
    </script>
</body>
</html>
