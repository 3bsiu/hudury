<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('admin');

$currentAdminId = getCurrentUserId();
$currentAdmin = getCurrentUserData($pdo);
$adminName = $_SESSION['user_name'] ?? 'Admin';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$successMessage = '';
$errorMessage = '';
if (isset($_GET['success']) && isset($_GET['message'])) {
    $successMessage = urldecode($_GET['message']);
}

$recentPayments = [];
$paymentSearchQuery = $_GET['paymentSearch'] ?? '';
try {
    if (!empty($paymentSearchQuery)) {
        
        $stmt = $pdo->prepare("
            SELECT ph.*, 
                   i.Installment_Number,
                   s.Student_ID, s.Student_Code, s.NameEn as StudentNameEn, s.NameAr as StudentNameAr,
                   a.NameEn as AdminNameEn, a.NameAr as AdminNameAr
            FROM payment_history ph
            INNER JOIN installment i ON ph.Installment_ID = i.Installment_ID
            INNER JOIN student s ON ph.Student_ID = s.Student_ID
            LEFT JOIN admin a ON ph.Recorded_By = a.Admin_ID
            WHERE ph.Payment_ID = ?
            ORDER BY ph.Payment_Date DESC
            LIMIT 10
        ");
        $stmt->execute([intval($paymentSearchQuery)]);
        $recentPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        
        $stmt = $pdo->query("
            SELECT ph.*, 
                   i.Installment_Number,
                   s.Student_ID, s.Student_Code, s.NameEn as StudentNameEn, s.NameAr as StudentNameAr,
                   a.NameEn as AdminNameEn, a.NameAr as AdminNameAr
            FROM payment_history ph
            INNER JOIN installment i ON ph.Installment_ID = i.Installment_ID
            INNER JOIN student s ON ph.Student_ID = s.Student_ID
            LEFT JOIN admin a ON ph.Recorded_By = a.Admin_ID
            ORDER BY ph.Payment_Date DESC
            LIMIT 10
        ");
        $recentPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching payment history: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'updateStudentFinancials') {
        try {
            $studentId = intval($_POST['studentId'] ?? 0);
            $totalRequired = floatval($_POST['totalRequired'] ?? 0);
            $totalPaid = floatval($_POST['totalPaid'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            
            if ($studentId <= 0) {
                throw new Exception('Invalid student ID');
            }
            if ($totalRequired < 0) {
                throw new Exception('Total required cannot be negative');
            }
            if ($totalPaid < 0) {
                throw new Exception('Total paid cannot be negative');
            }
            if ($totalPaid > $totalRequired) {
                throw new Exception('Total paid cannot exceed total required');
            }
            
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT Installment_ID, Amount FROM installment WHERE Student_ID = ?");
            $stmt->execute([$studentId]);
            $existingInstallments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $currentTotalRequired = array_sum(array_column($existingInstallments, 'Amount'));
            $difference = $totalRequired - $currentTotalRequired;

            if (abs($difference) > 0.01) {
                if (empty($existingInstallments)) {
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO installment (Student_ID, Installment_Number, Amount, Due_Date, Status, Notes)
                        VALUES (?, '1', ?, DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'unpaid', ?)
                    ");
                    $stmt->execute([$studentId, $totalRequired, $notes]);
                } else {
                    
                    $firstInst = $existingInstallments[0];
                    $newAmount = floatval($firstInst['Amount']) + $difference;
                    if ($newAmount > 0) {
                        $stmt = $pdo->prepare("UPDATE installment SET Amount = ? WHERE Installment_ID = ?");
                        $stmt->execute([$newAmount, $firstInst['Installment_ID']]);
                    }
                }
            }

            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(ph.Amount), 0) as current_paid
                FROM payment_history ph
                INNER JOIN installment i ON ph.Installment_ID = i.Installment_ID
                WHERE i.Student_ID = ?
            ");
            $stmt->execute([$studentId]);
            $currentPaid = floatval($stmt->fetch(PDO::FETCH_ASSOC)['current_paid'] ?? 0);
            $paidDifference = $totalPaid - $currentPaid;

            if (abs($paidDifference) > 0.01 && !empty($existingInstallments)) {
                $firstInst = $existingInstallments[0];
                $stmt = $pdo->prepare("
                    INSERT INTO payment_history (Installment_ID, Student_ID, Amount, Payment_Date, Payment_Method, Recorded_By, Notes)
                    VALUES (?, ?, ?, NOW(), 'manual_entry', ?, ?)
                ");
                $stmt->execute([$firstInst['Installment_ID'], $studentId, $paidDifference, $currentAdminId, $notes]);

                if ($totalPaid >= $totalRequired) {
                    $stmt = $pdo->prepare("
                        UPDATE installment 
                        SET Status = 'paid', Paid_Date = CURDATE()
                        WHERE Student_ID = ?
                    ");
                    $stmt->execute([$studentId]);
                }
            }
            
            $pdo->commit();

            header("Location: installments-management.php?success=1&message=" . urlencode('Student financial information updated successfully!'));
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = 'Error updating student financials: ' . $e->getMessage();
            error_log("Error updating student financials: " . $e->getMessage());
        }
    } elseif ($action === 'bulkAddInstallments') {
        try {
            $classId = intval($_POST['classId'] ?? 0);
            $gradeLevel = trim($_POST['gradeLevel'] ?? '');
            $section = trim($_POST['section'] ?? '');
            $amount = floatval($_POST['amount'] ?? 0);
            $installmentNumber = trim($_POST['installmentNumber'] ?? '1');
            $dueDate = $_POST['dueDate'] ?? date('Y-m-d', strtotime('+30 days'));
            $notes = trim($_POST['notes'] ?? '');
            
            if ($amount <= 0) {
                throw new Exception('Amount must be greater than zero');
            }
            if ($amount > 100000) {
                throw new Exception('Amount exceeds maximum limit');
            }
            
            $pdo->beginTransaction();

            $whereConditions = [];
            $params = [];
            
            if ($classId > 0) {
                $whereConditions[] = "s.Class_ID = ?";
                $params[] = $classId;
            } elseif (!empty($gradeLevel) && $gradeLevel !== 'all') {
                $whereConditions[] = "c.Grade_Level = ?";
                $params[] = $gradeLevel;
                
                if (!empty($section) && $section !== 'all') {
                    $whereConditions[] = "UPPER(c.Section) = UPPER(?)";
                    $params[] = $section;
                }
            } else {
                throw new Exception('Please select a class, grade, or section');
            }
            
            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) . ' AND s.Status = "active"' : 'WHERE s.Status = "active"';
            
            $stmt = $pdo->prepare("
                SELECT s.Student_ID 
                FROM student s
                LEFT JOIN class c ON s.Class_ID = c.Class_ID
                $whereClause
            ");
            $stmt->execute($params);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($students)) {
                throw new Exception('No students found for the selected criteria');
            }
            
            $insertedCount = 0;
            $stmt = $pdo->prepare("
                INSERT INTO installment (Student_ID, Installment_Number, Amount, Due_Date, Status, Notes)
                VALUES (?, ?, ?, ?, 'unpaid', ?)
            ");
            
            foreach ($students as $student) {
                $stmt->execute([
                    $student['Student_ID'],
                    $installmentNumber,
                    $amount,
                    $dueDate,
                    $notes ?: null
                ]);
                $insertedCount++;
            }
            
            $pdo->commit();

            header("Location: installments-management.php?success=1&message=" . urlencode("Successfully added installments to $insertedCount student(s)!"));
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = 'Error adding bulk installments: ' . $e->getMessage();
            error_log("Error adding bulk installments: " . $e->getMessage());
        }
    } elseif ($action === 'addPayment') {
        try {
            $studentId = intval($_POST['studentId'] ?? 0);
            $installmentId = intval($_POST['installmentId'] ?? 0);
            $paymentAmount = floatval($_POST['paymentAmount'] ?? 0);
            $paymentMethod = $_POST['paymentMethod'] ?? 'manual_entry';
            $notes = trim($_POST['notes'] ?? '');
            $paymentDate = $_POST['paymentDate'] ?? date('Y-m-d H:i:s');

            if ($studentId <= 0) {
                throw new Exception('Invalid student ID');
            }
            if ($paymentAmount <= 0) {
                throw new Exception('Payment amount must be greater than zero');
            }
            if ($paymentAmount > 100000) {
                throw new Exception('Payment amount exceeds maximum limit');
            }
            
            $pdo->beginTransaction();

            $datePrefix = date('Ymd');
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM payment_history WHERE Receipt_Number LIKE ?");
            $stmt->execute(["REC-$datePrefix-%"]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            $receiptNumber = 'REC-' . $datePrefix . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);

            $attempts = 0;
            while ($attempts < 10) {
                $stmt = $pdo->prepare("SELECT Payment_ID FROM payment_history WHERE Receipt_Number = ? LIMIT 1");
                $stmt->execute([$receiptNumber]);
                if (!$stmt->fetch()) {
                    break; 
                }
                
                $count++;
                $receiptNumber = 'REC-' . $datePrefix . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
                $attempts++;
            }

            $selectedInstallmentId = intval($_POST['selectedInstallmentId'] ?? 0);
            
            if ($selectedInstallmentId <= 0) {
                throw new Exception('Please select an installment to apply this payment to');
            }

            $stmt = $pdo->prepare("
                SELECT i.Installment_ID, i.Amount, i.Student_ID,
                       COALESCE(SUM(ph.Amount), 0) as paid_amount
                FROM installment i
                LEFT JOIN payment_history ph ON i.Installment_ID = ph.Installment_ID
                WHERE i.Installment_ID = ? AND i.Student_ID = ?
                GROUP BY i.Installment_ID, i.Amount, i.Student_ID
            ");
            $stmt->execute([$selectedInstallmentId, $studentId]);
            $selectedInst = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$selectedInst) {
                throw new Exception('Selected installment not found or does not belong to this student');
            }
            
            $installmentAmount = floatval($selectedInst['Amount']);
            $paidAmount = floatval($selectedInst['paid_amount']);
            $remaining = $installmentAmount - $paidAmount;

            if ($paymentAmount > $remaining + 0.01) {
                throw new Exception("Payment amount (".number_format($paymentAmount, 2)." JOD) exceeds remaining amount for this installment (".number_format($remaining, 2)." JOD). Please adjust the payment amount or select a different installment.");
            }
            
            $remainingPayment = $paymentAmount;
            $allocations = []; 

            if ($remaining > 0.01 && $remainingPayment > 0.01) {
                $amountToApply = min($remainingPayment, $remaining);

                $stmt = $pdo->prepare("
                    INSERT INTO payment_history (Installment_ID, Student_ID, Amount, Payment_Date, Payment_Method, Receipt_Number, Recorded_By, Notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $selectedInstallmentId, $studentId, $amountToApply, $paymentDate, $paymentMethod,
                    $receiptNumber, $currentAdminId, $notes ?: null
                ]);
                $allocations[] = ['installment_id' => $selectedInstallmentId, 'amount' => $amountToApply];
                $remainingPayment -= $amountToApply;
            } else {
                throw new Exception('This installment is already fully paid. Please select a different installment.');
            }

            if ($remainingPayment > 0.01) {
                throw new Exception("Payment amount (".number_format($paymentAmount, 2)." JOD) exceeds the remaining amount for the selected installment (".number_format($remaining, 2)." JOD). Please reduce the payment amount or select a different installment.");
            }

            foreach ($allocations as $allocation) {
                $instId = $allocation['installment_id'];

                $stmt = $pdo->prepare("
                    SELECT i.Amount,
                           COALESCE(SUM(ph.Amount), 0) as total_paid
                    FROM installment i
                    LEFT JOIN payment_history ph ON i.Installment_ID = ph.Installment_ID
                    WHERE i.Installment_ID = ?
                    GROUP BY i.Installment_ID, i.Amount
                ");
                $stmt->execute([$instId]);
                $instData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($instData) {
                    $instAmount = floatval($instData['Amount']);
                    $totalPaid = floatval($instData['total_paid']);
                    
                    if ($totalPaid >= $instAmount - 0.01) {
                        
                        $stmt = $pdo->prepare("
                            UPDATE installment 
                            SET Status = 'paid', Paid_Date = DATE(?), Payment_Method = ?, Receipt_Number = ?
                            WHERE Installment_ID = ?
                        ");
                        $stmt->execute([$paymentDate, $paymentMethod, $receiptNumber, $instId]);
                    }
                }
            }
            
            $pdo->commit();

            header("Location: installments-management.php?success=1&message=" . urlencode("Payment of " . number_format($paymentAmount, 2) . " JOD recorded successfully! Receipt: $receiptNumber"));
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = 'Error recording payment: ' . $e->getMessage();
            error_log("Error recording payment: " . $e->getMessage());
        }
    }
}

$classes = [];
try {
    $stmt = $pdo->query("SELECT Class_ID, Name, Grade_Level, Section FROM class ORDER BY Grade_Level, Section, Name");
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching classes: " . $e->getMessage());
}

$gradeLevels = [];
$sections = [];
foreach ($classes as $class) {
    if (!empty($class['Grade_Level']) && !in_array($class['Grade_Level'], $gradeLevels)) {
        $gradeLevels[] = $class['Grade_Level'];
    }
    if (!empty($class['Section']) && !in_array($class['Section'], $sections)) {
        $sections[] = $class['Section'];
    }
}
sort($gradeLevels);
sort($sections);

$allStudents = [];
$summaryStats = [
    'totalAmount' => 0,
    'paidAmount' => 0,
    'pendingAmount' => 0,
    'totalStudents' => 0
];

try {
    
    $stmt = $pdo->query("
        SELECT 
            s.Student_ID, s.Student_Code, s.NameEn, s.NameAr, s.Class_ID,
            c.Name as ClassName, c.Grade_Level, c.Section,
            p.Parent_ID, p.NameEn as ParentNameEn, p.NameAr as ParentNameAr,
            psr.Is_Primary, psr.Relationship_Type
        FROM student s
        LEFT JOIN class c ON s.Class_ID = c.Class_ID
        LEFT JOIN parent_student_relationship psr ON s.Student_ID = psr.Student_ID AND psr.Is_Primary = 1
        LEFT JOIN parent p ON psr.Parent_ID = p.Parent_ID
        WHERE s.Status = 'active'
        ORDER BY s.NameEn ASC
    ");
    $allStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $allInstallments = [];
    if (!empty($allStudents)) {
        $studentIds = array_column($allStudents, 'Student_ID');
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        
        $stmt = $pdo->prepare("
            SELECT 
                i.Installment_ID, i.Student_ID, i.Installment_Number, i.Amount, i.Due_Date,
                i.Status, i.Paid_Date, i.Payment_Method, i.Receipt_Number, i.Notes
            FROM installment i
            WHERE i.Student_ID IN ($placeholders)
            ORDER BY i.Due_Date ASC
        ");
        $stmt->execute($studentIds);
        $allInstallments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->query("
        SELECT 
            COALESCE(SUM(i.Amount), 0) as total_required,
            COALESCE(SUM(ph.Amount), 0) as total_paid
        FROM installment i
        LEFT JOIN payment_history ph ON i.Installment_ID = ph.Installment_ID
    ");
    $summaryRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $summaryStats['totalAmount'] = floatval($summaryRow['total_required'] ?? 0);
    $summaryStats['paidAmount'] = floatval($summaryRow['total_paid'] ?? 0);
    $summaryStats['pendingAmount'] = $summaryStats['totalAmount'] - $summaryStats['paidAmount'];
    $summaryStats['totalStudents'] = count($allStudents);
} catch (PDOException $e) {
    error_log("Error fetching students: " . $e->getMessage());
}

$searchQuery = $_GET['search'] ?? '';
$searchedStudentId = null;
$searchedStudent = null;
$studentFinancialData = null;

if (!empty($searchQuery)) {
    try {
        
        $stmt = $pdo->prepare("
            SELECT s.Student_ID, s.Student_Code, s.NameEn, s.NameAr, s.National_ID, s.Class_ID,
                   c.Name as ClassName, c.Grade_Level, c.Section
            FROM student s
            LEFT JOIN class c ON s.Class_ID = c.Class_ID
            WHERE s.Student_Code = ? OR s.National_ID = ? OR s.Student_ID = ?
            LIMIT 1
        ");
        $stmt->execute([$searchQuery, $searchQuery, intval($searchQuery)]);
        $searchedStudent = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($searchedStudent) {
            $searchedStudentId = $searchedStudent['Student_ID'];

            $stmt = $pdo->prepare("
                SELECT * FROM installment 
                WHERE Student_ID = ? 
                ORDER BY Due_Date ASC
            ");
            $stmt->execute([$searchedStudentId]);
            $studentInstallments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                SELECT ph.*, i.Installment_Number,
                       a.NameEn as AdminNameEn, a.NameAr as AdminNameAr
                FROM payment_history ph
                INNER JOIN installment i ON ph.Installment_ID = i.Installment_ID
                LEFT JOIN admin a ON ph.Recorded_By = a.Admin_ID
                WHERE ph.Student_ID = ?
                ORDER BY ph.Payment_Date DESC
            ");
            $stmt->execute([$searchedStudentId]);
            $paymentHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalFees = 0;
            $paidAmount = 0;
            foreach ($studentInstallments as $inst) {
                $totalFees += floatval($inst['Amount']);
                if ($inst['Status'] === 'paid') {
                    $paidAmount += floatval($inst['Amount']);
                }
            }

            $paidFromHistory = 0;
            foreach ($paymentHistory as $payment) {
                $paidFromHistory += floatval($payment['Amount']);
            }
            $paidAmount = max($paidAmount, $paidFromHistory); 
            
            $studentFinancialData = [
                'totalFees' => $totalFees,
                'paidAmount' => $paidAmount,
                'remainingBalance' => $totalFees - $paidAmount,
                'installments' => $studentInstallments,
                'paymentHistory' => $paymentHistory
            ];
        }
    } catch (PDOException $e) {
        error_log("Error searching for student: " . $e->getMessage());
        $errorMessage = 'Error searching for student: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installments Management - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .summary-card {
            background: linear-gradient(135deg, #FFE5E5, #E5F3FF);
            padding: 2rem;
            border-radius: 20px;
            text-align: center;
            border: 3px solid #FFE5E5;
            transition: all 0.3s;
        }
        .summary-card:hover {
            transform: translateY(-5px);
            border-color: #FF6B9D;
        }
        .summary-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .summary-value {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--primary-color);
            margin: 0.5rem 0;
        }
        .summary-label {
            color: #666;
            font-weight: 600;
        }
        .progress-bar-container {
            background: #FFF9F5;
            border-radius: 10px;
            padding: 0.5rem;
            margin-top: 0.5rem;
        }
        .progress-bar {
            background: #FFE5E5;
            border-radius: 5px;
            height: 12px;
            overflow: hidden;
            position: relative;
        }
        .progress-fill {
            height: 100%;
            transition: width 0.3s;
            border-radius: 5px;
        }
        .progress-fill.paid {
            background: linear-gradient(90deg, #6BCB77, #4CAF50);
        }
        .progress-fill.partial {
            background: linear-gradient(90deg, #FFD93D, #FFC107);
        }
        .progress-fill.unpaid {
            background: linear-gradient(90deg, #FF6B9D, #C44569);
        }
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
        .status-indicator.paid { background: #6BCB77; }
        .status-indicator.partial { background: #FFD93D; }
        .status-indicator.unpaid { background: #FF6B9D; }
        .status-indicator.overdue { background: #C44569; }
        .financial-summary-card {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 1rem;
            border: 2px solid #FFE5E5;
        }
        .financial-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.8rem 0;
            border-bottom: 1px solid #FFE5E5;
        }
        .financial-item:last-child {
            border-bottom: none;
        }
        .financial-label {
            font-weight: 600;
            color: #666;
        }
        .financial-value {
            font-size: 1.2rem;
            font-weight: 800;
        }
        .financial-value.positive {
            color: #6BCB77;
        }
        .financial-value.negative {
            color: #FF6B9D;
        }
        .financial-value.neutral {
            color: var(--primary-color);
        }
    </style>
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">💰</span>
                <span data-en="Installments Management" data-ar="إدارة الأقساط">Installments Management</span>
            </h1>
            <p class="page-subtitle" data-en="Manage student installments and financial records" data-ar="إدارة أقساط الطلاب والسجلات المالية">Manage student installments and financial records</p>
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

        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-icon">💰</div>
                <div class="summary-value"><?php echo number_format($summaryStats['totalAmount'], 2); ?> JOD</div>
                <div class="summary-label" data-en="Total Amount" data-ar="إجمالي المبلغ">Total Amount</div>
            </div>
            <div class="summary-card">
                <div class="summary-icon">✅</div>
                <div class="summary-value"><?php echo number_format($summaryStats['paidAmount'], 2); ?> JOD</div>
                <div class="summary-label" data-en="Paid Amount" data-ar="المبلغ المدفوع">Paid Amount</div>
            </div>
            <div class="summary-card">
                <div class="summary-icon">⏳</div>
                <div class="summary-value"><?php echo number_format($summaryStats['pendingAmount'], 2); ?> JOD</div>
                <div class="summary-label" data-en="Pending Amount" data-ar="المبلغ المعلق">Pending Amount</div>
            </div>
            <div class="summary-card">
                <div class="summary-icon">👥</div>
                <div class="summary-value"><?php echo number_format($summaryStats['totalStudents']); ?></div>
                <div class="summary-label" data-en="Total Students" data-ar="إجمالي الطلاب">Total Students</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">🔍</span>
                    <span data-en="Filters" data-ar="المرشحات">Filters</span>
                </h2>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label data-en="Grade" data-ar="الصف">Grade</label>
                    <select id="gradeFilter" onchange="loadSections(); filterInstallments()">
                        <option value="all" data-en="All Grades" data-ar="جميع الصفوف">All Grades</option>
                        <?php foreach ($gradeLevels as $grade): ?>
                            <option value="<?php echo htmlspecialchars($grade); ?>" data-en="Grade <?php echo htmlspecialchars($grade); ?>" data-ar="الصف <?php echo htmlspecialchars($grade); ?>">Grade <?php echo htmlspecialchars($grade); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label data-en="Section" data-ar="القسم">Section</label>
                    <select id="sectionFilter" onchange="filterInstallments()" style="display: none;">
                        <option value="all" data-en="All Sections" data-ar="جميع الأقسام">All Sections</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label data-en="Payment Status" data-ar="حالة الدفع">Payment Status</label>
                    <select id="paymentStatusFilter" onchange="filterInstallments()">
                        <option value="all" data-en="All" data-ar="الكل">All</option>
                        <option value="paid" data-en="Paid" data-ar="مدفوع">Paid</option>
                        <option value="partial" data-en="Partially Paid" data-ar="مدفوع جزئياً">Partially Paid</option>
                        <option value="unpaid" data-en="Unpaid" data-ar="غير مدفوع">Unpaid</option>
                        <option value="overdue" data-en="Overdue" data-ar="متأخر">Overdue</option>
                    </select>
                </div>
            </div>
        </div>

        <?php if ($searchedStudent && $studentFinancialData): ?>
            
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <span class="card-icon">👨‍🎓</span>
                        <span data-en="Financial Information" data-ar="المعلومات المالية">Financial Information</span>
                        <span style="font-size: 1rem; color: #666; margin-left: 1rem;">
                            - <?php echo htmlspecialchars($searchedStudent['NameEn']); ?>
                            <?php if (!empty($searchedStudent['NameAr'])): ?>
                                (<?php echo htmlspecialchars($searchedStudent['NameAr']); ?>)
                            <?php endif; ?>
                        </span>
                    </h2>
                </div>

                <div class="financial-summary-card">
                    <div class="financial-item">
                        <span class="financial-label" data-en="Total Fees" data-ar="إجمالي الرسوم">Total Fees</span>
                        <span class="financial-value neutral"><?php echo number_format($studentFinancialData['totalFees'], 2); ?> JOD</span>
                    </div>
                    <div class="financial-item">
                        <span class="financial-label" data-en="Paid Amount" data-ar="المبلغ المدفوع">Paid Amount</span>
                        <span class="financial-value positive"><?php echo number_format($studentFinancialData['paidAmount'], 2); ?> JOD</span>
                    </div>
                    <div class="financial-item">
                        <span class="financial-label" data-en="Remaining Balance" data-ar="الرصيد المتبقي">Remaining Balance</span>
                        <span class="financial-value <?php echo $studentFinancialData['remainingBalance'] > 0 ? 'negative' : 'positive'; ?>">
                            <?php echo number_format($studentFinancialData['remainingBalance'], 2); ?> JOD
                        </span>
                    </div>
                    <div class="progress-bar-container">
                        <div class="progress-bar">
                            <?php 
                            $paymentPercentage = $studentFinancialData['totalFees'] > 0 
                                ? ($studentFinancialData['paidAmount'] / $studentFinancialData['totalFees']) * 100 
                                : 0;
                            $progressClass = $paymentPercentage >= 100 ? 'paid' : ($paymentPercentage > 0 ? 'partial' : 'unpaid');
                            ?>
                            <div class="progress-fill <?php echo $progressClass; ?>" style="width: <?php echo min(100, max(0, $paymentPercentage)); ?>%"></div>
                        </div>
                        <div style="text-align: center; margin-top: 0.3rem; font-size: 0.85rem; color: #666;">
                            <span data-en="Payment Progress" data-ar="تقدم الدفع">Payment Progress: </span>
                            <strong><?php echo number_format($paymentPercentage, 1); ?>%</strong>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    <h3 style="margin-bottom: 1rem; color: var(--primary-color);" data-en="Installments" data-ar="الأقساط">Installments</h3>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th data-en="Installment" data-ar="القسط">Installment</th>
                                    <th data-en="Amount" data-ar="المبلغ">Amount</th>
                                    <th data-en="Due Date" data-ar="تاريخ الاستحقاق">Due Date</th>
                                    <th data-en="Status" data-ar="الحالة">Status</th>
                                    <th data-en="Paid Date" data-ar="تاريخ الدفع">Paid Date</th>
                                    <th data-en="Actions" data-ar="الإجراءات">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($studentFinancialData['installments'])): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 2rem; color: #666;">
                                            <div data-en="No installments found" data-ar="لا توجد أقساط">No installments found</div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($studentFinancialData['installments'] as $inst): ?>
                                        <?php
                                        $dueDate = new DateTime($inst['Due_Date']);
                                        $today = new DateTime();
                                        $isOverdue = $dueDate < $today && $inst['Status'] !== 'paid';
                                        $statusClass = $inst['Status'] === 'paid' ? 'paid' : ($isOverdue ? 'overdue' : 'unpaid');
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($inst['Installment_Number']); ?></td>
                                            <td style="font-weight: 700; color: var(--primary-color);"><?php echo number_format(floatval($inst['Amount']), 2); ?> JOD</td>
                                            <td><?php echo $dueDate->format('M d, Y'); ?></td>
                                            <td>
                                                <span class="status-indicator <?php echo $statusClass; ?>"></span>
                                                <span class="status-badge <?php echo $inst['Status'] === 'paid' ? 'status-active' : ($isOverdue ? 'status-rejected' : 'status-pending'); ?>">
                                                    <?php 
                                                    $currentLang = $_SESSION['language'] ?? 'en';
                                                    if ($inst['Status'] === 'paid') {
                                                        echo $currentLang === 'en' ? 'Paid' : 'مدفوع';
                                                    } elseif ($isOverdue) {
                                                        echo $currentLang === 'en' ? 'Overdue' : 'متأخر';
                                                    } else {
                                                        echo $currentLang === 'en' ? 'Unpaid' : 'غير مدفوع';
                                                    }
                                                    ?>
                                                </span>
                                            </td>
                                            <td><?php echo $inst['Paid_Date'] ? date('M d, Y', strtotime($inst['Paid_Date'])) : '-'; ?></td>
                                            <td>
                                                <button class="btn btn-primary btn-small" 
                                                        onclick="openPaymentModal(<?php echo $inst['Installment_ID']; ?>, <?php echo $searchedStudentId; ?>, <?php echo floatval($inst['Amount']); ?>, <?php echo $studentFinancialData['remainingBalance']; ?>)"
                                                        <?php echo $inst['Status'] === 'paid' ? 'disabled' : ''; ?>
                                                        data-en="Add Payment" data-ar="إضافة دفعة">Add Payment</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <h3 style="margin-bottom: 1rem; color: var(--primary-color);" data-en="Payment History" data-ar="سجل المدفوعات">Payment History</h3>
                    <?php if (empty($studentFinancialData['paymentHistory'])): ?>
                        <div style="text-align: center; padding: 2rem; color: #666; background: #FFF9F5; border-radius: 15px;">
                            <div style="font-size: 2rem; margin-bottom: 0.5rem;">📋</div>
                            <div data-en="No payment history available" data-ar="لا يوجد سجل مدفوعات">No payment history available</div>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th data-en="Date" data-ar="التاريخ">Date</th>
                                        <th data-en="Amount" data-ar="المبلغ">Amount</th>
                                        <th data-en="Installment" data-ar="القسط">Installment</th>
                                        <th data-en="Method" data-ar="طريقة الدفع">Method</th>
                                        <th data-en="Receipt" data-ar="الإيصال">Receipt</th>
                                        <th data-en="Recorded By" data-ar="سجل بواسطة">Recorded By</th>
                                        <th data-en="Notes" data-ar="ملاحظات">Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($studentFinancialData['paymentHistory'] as $payment): ?>
                                        <?php
                                        $paymentDateTime = new DateTime($payment['Payment_Date']);
                                        $methodLabels = [
                                            'cash' => ['en' => 'Cash', 'ar' => 'نقد'],
                                            'bank_transfer' => ['en' => 'Bank Transfer', 'ar' => 'تحويل بنكي'],
                                            'credit_card' => ['en' => 'Credit Card', 'ar' => 'بطاقة ائتمان'],
                                            'manual_entry' => ['en' => 'Manual Entry', 'ar' => 'إدخال يدوي']
                                        ];
                                        $methodLabel = $methodLabels[$payment['Payment_Method']] ?? ['en' => $payment['Payment_Method'], 'ar' => $payment['Payment_Method']];
                                        ?>
                                        <tr>
                                            <td><?php echo $paymentDateTime->format('M d, Y H:i'); ?></td>
                                            <td style="font-weight: 700; color: #6BCB77;"><?php echo number_format(floatval($payment['Amount']), 2); ?> JOD</td>
                                            <td><?php echo htmlspecialchars($payment['Installment_Number']); ?></td>
                                            <td><?php echo $methodLabel['en']; ?></td>
                                            <td><?php echo htmlspecialchars($payment['Receipt_Number'] ?? '-'); ?></td>
                                            <td>
                                                <?php if (!empty($payment['AdminNameEn']) || !empty($payment['AdminNameAr'])): ?>
                                                    <?php echo htmlspecialchars($payment['AdminNameEn'] ?? $payment['AdminNameAr'] ?? 'System'); ?>
                                                <?php else: ?>
                                                    <span style="color: #999;">System</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($payment['Notes'] ?? '-'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif (!empty($searchQuery)): ?>
            
            <div class="card">
                <div style="text-align: center; padding: 3rem; color: #666;">
                    <div style="font-size: 4rem; margin-bottom: 1rem;">🔍</div>
                    <div style="font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; color: #FF6B9D;" data-en="Student not found" data-ar="لم يتم العثور على الطالب">Student not found</div>
                    <div style="font-size: 1rem; margin-bottom: 1.5rem;" data-en="No student found with the ID: <?php echo htmlspecialchars($searchQuery); ?>" data-ar="لم يتم العثور على طالب بالرقم: <?php echo htmlspecialchars($searchQuery); ?>">
                        No student found with the ID: <strong><?php echo htmlspecialchars($searchQuery); ?></strong>
                    </div>
                    <a href="installments-management.php" class="btn btn-primary" style="display: inline-block;" data-en="Try Another Search" data-ar="حاول بحثاً آخر">Try Another Search</a>
                </div>
            </div>
        <?php else: ?>
        
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📋</span>
                    <span data-en="Student Installments" data-ar="أقساط الطلاب">Student Installments</span>
                </h2>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th data-en="Student Number" data-ar="رقم الطالب">Student Number</th>
                            <th data-en="Student Name" data-ar="اسم الطالب">Student Name</th>
                            <th data-en="Parent Name" data-ar="اسم ولي الأمر">Parent Name</th>
                            <th data-en="Total Required Amount" data-ar="إجمالي المبلغ المطلوب">Total Required Amount</th>
                            <th data-en="Total Paid Amount" data-ar="إجمالي المبلغ المدفوع">Total Paid Amount</th>
                            <th data-en="Remaining Amount" data-ar="المبلغ المتبقي">Remaining Amount</th>
                            <th data-en="Last Payment Date" data-ar="تاريخ آخر دفعة">Last Payment Date</th>
                            <th data-en="Student Class" data-ar="فصل الطالب">Student Class</th>
                            <th data-en="Payment Status" data-ar="حالة الدفع">Payment Status</th>
                            <th data-en="Actions" data-ar="الإجراءات">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="installmentsTableBody">
                            <?php if (empty($allStudents)): ?>
                                <tr>
                                    <td colspan="10" style="text-align: center; padding: 2rem; color: #666;">
                                        <div style="font-size: 2rem; margin-bottom: 0.5rem;">💰</div>
                                        <div data-en="No students found" data-ar="لا يوجد طلاب">No students found</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php 
                                
                                $studentsData = [];

                                foreach ($allStudents as $student) {
                                    $studentId = $student['Student_ID'];
                                    $studentsData[$studentId] = [
                                        'student' => [
                                            'Student_ID' => $student['Student_ID'],
                                            'Student_Code' => $student['Student_Code'],
                                            'NameEn' => $student['NameEn'],
                                            'NameAr' => $student['NameAr'],
                                            'ClassName' => $student['ClassName'],
                                            'Grade_Level' => $student['Grade_Level'],
                                            'Section' => $student['Section']
                                        ],
                                        'parent' => [
                                            'ParentNameEn' => $student['ParentNameEn'] ?? null,
                                            'ParentNameAr' => $student['ParentNameAr'] ?? null
                                        ],
                                        'installments' => []
                                    ];
                                }

                                foreach ($allInstallments as $inst) {
                                    $studentId = $inst['Student_ID'];
                                    if (isset($studentsData[$studentId])) {
                                        $studentsData[$studentId]['installments'][] = $inst;
                                    }
                                }

                                foreach ($studentsData as $studentId => &$data) {
                                    $studentId = $data['student']['Student_ID'];

                                    $totalRequired = 0;
                                    $installmentIds = [];
                                    foreach ($data['installments'] as $inst) {
                                        $totalRequired += floatval($inst['Amount']);
                                        $installmentIds[] = $inst['Installment_ID'];
                                    }

                                    $paidAmount = 0;
                                    $lastPaymentDate = null;
                                    if (!empty($installmentIds)) {
                                        $placeholders = implode(',', array_fill(0, count($installmentIds), '?'));
                                        $stmt = $pdo->prepare("
                                            SELECT SUM(Amount) as total_paid, MAX(Payment_Date) as last_payment
                                            FROM payment_history
                                            WHERE Installment_ID IN ($placeholders) AND Student_ID = ?
                                        ");
                                        $params = array_merge($installmentIds, [$studentId]);
                                        $stmt->execute($params);
                                        $paymentData = $stmt->fetch(PDO::FETCH_ASSOC);
                                        $paidAmount = floatval($paymentData['total_paid'] ?? 0);
                                        $lastPaymentDate = $paymentData['last_payment'] ?? null;
                                    }
                                    
                                    $data['totalRequired'] = $totalRequired;
                                    $data['paidAmount'] = $paidAmount;
                                    $data['remainingAmount'] = $totalRequired - $paidAmount;
                                    $data['lastPaymentDate'] = $lastPaymentDate;
                                    $data['paymentPercentage'] = $totalRequired > 0 ? ($paidAmount / $totalRequired) * 100 : 0;
                                }
                                unset($data);
                                ?>
                                <?php foreach ($studentsData as $studentId => $data): ?>
                                    <?php
                                    $student = $data['student'];
                                    $parent = $data['parent'];
                                    $totalFees = $data['totalRequired'];
                                    $paidAmount = $data['paidAmount'];
                                    $remainingBalance = $data['remainingAmount'];
                                    $paymentPercentage = $data['paymentPercentage'];
                                    $lastPaymentDate = $data['lastPaymentDate'];

                                    $paymentStatus = 'unpaid';
                                    if ($paymentPercentage >= 100) {
                                        $paymentStatus = 'paid';
                                    } elseif ($paymentPercentage > 0) {
                                        $paymentStatus = 'partial';
                                    }
                                    ?>
                                    <tr>
                                        <td style="font-weight: 700; color: var(--primary-color);">
                                            <?php echo htmlspecialchars($student['Student_Code']); ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 700;"><?php echo htmlspecialchars($student['NameEn']); ?></div>
                                            <?php if (!empty($student['NameAr'])): ?>
                                                <div style="font-size: 0.85rem; color: #666;"><?php echo htmlspecialchars($student['NameAr']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($parent['ParentNameEn']) || !empty($parent['ParentNameAr'])): ?>
                                                <div style="font-weight: 600;"><?php echo htmlspecialchars($parent['ParentNameEn'] ?? $parent['ParentNameAr'] ?? '-'); ?></div>
                                                <?php if (!empty($parent['ParentNameAr']) && $parent['ParentNameEn'] !== $parent['ParentNameAr']): ?>
                                                    <div style="font-size: 0.85rem; color: #666;"><?php echo htmlspecialchars($parent['ParentNameAr']); ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #999;" data-en="Not Assigned" data-ar="غير معين">Not Assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-weight: 700; color: var(--primary-color);">
                                            <?php echo number_format($totalFees, 2); ?> JOD
                                        </td>
                                        <td style="font-weight: 700; color: #6BCB77;">
                                            <?php echo number_format($paidAmount, 2); ?> JOD
                                        </td>
                                        <td style="font-weight: 700; color: <?php echo $remainingBalance > 0 ? '#FF6B9D' : '#6BCB77'; ?>;">
                                            <?php echo number_format($remainingBalance, 2); ?> JOD
                                        </td>
                                        <td>
                                            <?php if ($lastPaymentDate): ?>
                                                <?php echo date('M d, Y', strtotime($lastPaymentDate)); ?>
                                            <?php else: ?>
                                                <span style="color: #999;" data-en="No payments" data-ar="لا توجد مدفوعات">No payments</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($student['ClassName']): ?>
                                                <?php echo htmlspecialchars($student['ClassName']); ?>
                                            <?php endif; ?>
                                            <?php if ($student['Grade_Level']): ?>
                                                <div style="font-size: 0.85rem; color: #666;">
                                                    <span data-en="Grade" data-ar="الصف">Grade</span> <?php echo htmlspecialchars($student['Grade_Level']); ?>
                                                    <?php if ($student['Section']): ?>
                                                        - <span data-en="Section" data-ar="القسم">Section</span> <?php echo htmlspecialchars($student['Section']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <span class="status-indicator <?php echo $paymentStatus === 'paid' ? 'paid' : ($paymentStatus === 'partial' ? 'partial' : 'unpaid'); ?>"></span>
                                                <span class="status-badge <?php echo $paymentStatus === 'paid' ? 'status-active' : ($paymentStatus === 'partial' ? 'status-pending' : 'status-pending'); ?>">
                                                    <?php 
                                                    $currentLang = $_SESSION['language'] ?? 'en';
                                                    if ($paymentStatus === 'paid') {
                                                        echo $currentLang === 'en' ? 'Paid' : 'مدفوع';
                                                    } elseif ($paymentStatus === 'partial') {
                                                        echo $currentLang === 'en' ? 'Partially Paid' : 'مدفوع جزئياً';
                                                    } else {
                                                        echo $currentLang === 'en' ? 'Unpaid' : 'غير مدفوع';
                                                    }
                                                    ?>
                                                </span>
                                            </div>
                                            <div class="progress-bar-container" style="margin-top: 0.3rem;">
                                                <div class="progress-bar">
                                                    <div class="progress-fill <?php echo $paymentStatus === 'paid' ? 'paid' : ($paymentStatus === 'partial' ? 'partial' : 'unpaid'); ?>" 
                                                         style="width: <?php echo min(100, max(0, $paymentPercentage)); ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                                <button class="btn btn-primary btn-small" 
                                                        onclick="openEditModal(<?php echo $student['Student_ID']; ?>, '<?php echo htmlspecialchars($student['Student_Code']); ?>')"
                                                        data-en="Edit" data-ar="تعديل">Edit</button>
                                                <button class="btn btn-success btn-small" 
                                                        onclick="openAddPaymentModal(<?php echo $student['Student_ID']; ?>, '<?php echo htmlspecialchars($student['NameEn']); ?>', <?php echo $totalFees; ?>, <?php echo $paidAmount; ?>)"
                                                        data-en="Add Payment" data-ar="إضافة دفعة">Add Payment</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 2rem; padding-top: 2rem; border-top: 2px solid #FFE5E5;">
                <div class="card-header">
                    <h2 class="card-title">
                        <span class="card-icon">➕</span>
                        <span data-en="Bulk Add Installments" data-ar="إضافة أقساط بالجملة">Bulk Add Installments</span>
                    </h2>
                </div>
                <form method="POST" action="installments-management.php" onsubmit="return validateBulkAddForm(event)" style="margin-top: 1rem;">
                    <input type="hidden" name="action" value="bulkAddInstallments">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label data-en="Grade" data-ar="الصف">Grade <span style="color: red;">*</span></label>
                            <select id="bulkGrade" name="gradeLevel" onchange="loadBulkSections()" required>
                                <option value="all" data-en="Select Grade" data-ar="اختر الصف">Select Grade</option>
                                <?php foreach ($gradeLevels as $grade): ?>
                                    <option value="<?php echo htmlspecialchars($grade); ?>">Grade <?php echo htmlspecialchars($grade); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label data-en="Section" data-ar="القسم">Section</label>
                            <select id="bulkSection" name="section" style="display: none;">
                                <option value="all" data-en="All Sections" data-ar="جميع الأقسام">All Sections</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label data-en="Amount (JOD)" data-ar="المبلغ (دينار)">Amount (JOD) <span style="color: red;">*</span></label>
                            <input type="number" name="amount" step="0.01" min="0.01" max="100000" required placeholder="0.00">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label data-en="Installment Number" data-ar="رقم القسط">Installment Number</label>
                            <input type="text" name="installmentNumber" value="1" placeholder="e.g., 1, 2, 3">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label data-en="Due Date" data-ar="تاريخ الاستحقاق">Due Date <span style="color: red;">*</span></label>
                            <input type="date" name="dueDate" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label data-en="Notes" data-ar="ملاحظات">Notes</label>
                            <input type="text" name="notes" placeholder="Optional">
                        </div>
                    </div>
                    <div style="margin-top: 1rem;">
                        <button type="submit" class="btn btn-primary" data-en="Add to All Students" data-ar="إضافة لجميع الطلاب">
                            <i class="fas fa-users"></i> Add to All Students
                        </button>
                    </div>
                </form>
            </div>

            <div style="margin-top: 2rem; padding-top: 2rem; border-top: 2px solid #FFE5E5;">
                <div class="card-header">
                    <h2 class="card-title">
                        <span class="card-icon">📋</span>
                        <span data-en="Recent Payment History" data-ar="سجل المدفوعات الأخيرة">Recent Payment History</span>
                    </h2>
                </div>

                <div style="margin-bottom: 1rem;">
                    <form method="GET" action="installments-management.php" style="display: flex; gap: 0.5rem; align-items: flex-end;">
                        <div class="form-group" style="margin-bottom: 0; flex: 1; max-width: 300px;">
                            <label data-en="Search by Payment ID" data-ar="البحث برقم الدفعة">Search by Payment ID</label>
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" 
                                       name="paymentSearch" 
                                       value="<?php echo htmlspecialchars($paymentSearchQuery); ?>"
                                       placeholder="Enter Payment ID...">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary" data-en="Search" data-ar="بحث">Search</button>
                        <?php if (!empty($paymentSearchQuery)): ?>
                            <a href="installments-management.php" class="btn btn-secondary" data-en="Clear" data-ar="مسح">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th data-en="Payment ID" data-ar="رقم الدفعة">Payment ID</th>
                                <th data-en="Date" data-ar="التاريخ">Date</th>
                                <th data-en="Student" data-ar="الطالب">Student</th>
                                <th data-en="Amount" data-ar="المبلغ">Amount</th>
                                <th data-en="Installment" data-ar="القسط">Installment</th>
                                <th data-en="Method" data-ar="طريقة الدفع">Method</th>
                                <th data-en="Receipt" data-ar="الإيصال">Receipt</th>
                                <th data-en="Recorded By" data-ar="سجل بواسطة">Recorded By</th>
                                <th data-en="Notes" data-ar="ملاحظات">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentPayments)): ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 2rem; color: #666;">
                                        <div style="font-size: 2rem; margin-bottom: 0.5rem;">📋</div>
                                        <div data-en="No payment history found" data-ar="لا يوجد سجل مدفوعات">No payment history found</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentPayments as $payment): ?>
                                    <?php
                                    $paymentDateTime = new DateTime($payment['Payment_Date']);
                                    $methodLabels = [
                                        'cash' => ['en' => 'Cash', 'ar' => 'نقد'],
                                        'bank_transfer' => ['en' => 'Bank Transfer', 'ar' => 'تحويل بنكي'],
                                        'credit_card' => ['en' => 'Credit Card', 'ar' => 'بطاقة ائتمان'],
                                        'manual_entry' => ['en' => 'Manual Entry', 'ar' => 'إدخال يدوي']
                                    ];
                                    $methodLabel = $methodLabels[$payment['Payment_Method']] ?? ['en' => $payment['Payment_Method'], 'ar' => $payment['Payment_Method']];
                                    ?>
                                    <tr>
                                        <td style="font-weight: 700; color: var(--primary-color);">#<?php echo $payment['Payment_ID']; ?></td>
                                        <td><?php echo $paymentDateTime->format('M d, Y H:i'); ?></td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($payment['StudentNameEn'] ?? ''); ?></div>
                                            <?php if (!empty($payment['StudentNameAr'])): ?>
                                                <div style="font-size: 0.85rem; color: #666;"><?php echo htmlspecialchars($payment['StudentNameAr']); ?></div>
                                            <?php endif; ?>
                                            <div style="font-size: 0.8rem; color: #999;"><?php echo htmlspecialchars($payment['Student_Code'] ?? ''); ?></div>
                                        </td>
                                        <td style="font-weight: 700; color: #6BCB77;"><?php echo number_format(floatval($payment['Amount']), 2); ?> JOD</td>
                                        <td><?php echo htmlspecialchars($payment['Installment_Number'] ?? '-'); ?></td>
                                        <td><?php echo $methodLabel['en']; ?></td>
                                        <td><?php echo htmlspecialchars($payment['Receipt_Number'] ?? '-'); ?></td>
                                        <td>
                                            <?php if (!empty($payment['AdminNameEn']) || !empty($payment['AdminNameAr'])): ?>
                                                <?php echo htmlspecialchars($payment['AdminNameEn'] ?? $payment['AdminNameAr'] ?? 'System'); ?>
                                            <?php else: ?>
                                                <span style="color: #999;">System</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($payment['Notes'] ?? '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div style="margin-top: 2rem; padding-top: 2rem; border-top: 2px solid #FFE5E5;">
                <div class="card-header">
                    <h2 class="card-title">
                        <span class="card-icon">📊</span>
                        <span data-en="Financial Reports" data-ar="التقارير المالية">Financial Reports</span>
                    </h2>
                </div>

                <div style="margin-top: 1rem; margin-bottom: 1rem; padding: 1rem; background: #FFF9F5; border-radius: 10px;">
                    <h3 style="margin-bottom: 1rem; color: var(--primary-color); font-size: 1rem;" data-en="Report Filters" data-ar="مرشحات التقرير">Report Filters</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label data-en="Student Number" data-ar="رقم الطالب">Student Number</label>
                            <input type="text" 
                                   id="reportStudentNumber" 
                                   placeholder="Enter student number..."
                                   style="width: 100%;">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label data-en="Start Date" data-ar="تاريخ البداية">Start Date</label>
                            <input type="date" id="reportStartDate" style="width: 100%;">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label data-en="End Date" data-ar="تاريخ النهاية">End Date</label>
                            <input type="date" id="reportEndDate" style="width: 100%;">
                        </div>
                    </div>
                </div>
                
                <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1rem;">
                    <button class="btn btn-primary" onclick="generateReport('pdf')" data-en="Generate PDF Report" data-ar="إنشاء تقرير PDF">
                        <i class="fas fa-file-pdf"></i> Generate PDF Report
                    </button>
                    <button class="btn btn-primary" onclick="generateReport('excel')" data-en="Export to Excel" data-ar="تصدير إلى Excel">
                        <i class="fas fa-file-excel"></i> Export to Excel
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="modal" id="paymentModal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h2 class="modal-title" data-en="Add Payment" data-ar="إضافة دفعة">Add Payment</h2>
                <button class="modal-close" onclick="closeModal('paymentModal')">&times;</button>
            </div>
            <form method="POST" action="installments-management.php<?php echo !empty($searchQuery) ? '?search=' . urlencode($searchQuery) : ''; ?>" onsubmit="return validatePaymentForm(event)">
                <input type="hidden" name="action" value="addPayment">
                <input type="hidden" name="studentId" id="paymentStudentId">
                <input type="hidden" name="installmentId" id="paymentInstallmentId">
                
                <div class="form-group">
                    <label data-en="Payment Amount" data-ar="مبلغ الدفع">Payment Amount (JOD) <span style="color: red;">*</span></label>
                    <input type="number" 
                           id="paymentAmount" 
                           name="paymentAmount" 
                           step="0.01" 
                           min="0.01" 
                           max="100000" 
                           required
                           oninput="updatePaymentSummary()">
            </div>
                
                <div class="form-group">
                    <label data-en="Payment Method" data-ar="طريقة الدفع">Payment Method <span style="color: red;">*</span></label>
                    <select id="paymentMethod" name="paymentMethod" required>
                        <option value="cash" data-en="Cash" data-ar="نقد">Cash</option>
                        <option value="bank_transfer" data-en="Bank Transfer" data-ar="تحويل بنكي">Bank Transfer</option>
                        <option value="credit_card" data-en="Credit Card" data-ar="بطاقة ائتمان">Credit Card</option>
                        <option value="manual_entry" data-en="Manual Entry" data-ar="إدخال يدوي">Manual Entry</option>
                    </select>
        </div>

                <input type="hidden" name="receiptNumber" value="">

                <div class="form-group">
                    <label data-en="Payment Date" data-ar="تاريخ الدفع">Payment Date <span style="color: red;">*</span></label>
                    <input type="datetime-local" 
                           id="paymentDate" 
                           name="paymentDate" 
                           value="<?php echo date('Y-m-d\TH:i'); ?>"
                           required>
            </div>
                
                <div class="form-group">
                    <label data-en="Notes" data-ar="ملاحظات">Notes</label>
                    <textarea id="paymentNotes" name="notes" rows="3" placeholder="Optional notes..."></textarea>
            </div>

                <div class="financial-summary-card" id="paymentSummary" style="margin-top: 1rem;">
                    <div class="financial-item">
                        <span class="financial-label" data-en="Installment Amount" data-ar="مبلغ القسط">Installment Amount</span>
                        <span class="financial-value neutral" id="summaryInstallmentAmount">0.00 JOD</span>
                    </div>
                    <div class="financial-item">
                        <span class="financial-label" data-en="Payment Amount" data-ar="مبلغ الدفع">Payment Amount</span>
                        <span class="financial-value positive" id="summaryPaymentAmount">0.00 JOD</span>
                    </div>
                    <div class="financial-item">
                        <span class="financial-label" data-en="Remaining Balance" data-ar="الرصيد المتبقي">Remaining Balance</span>
                        <span class="financial-value negative" id="summaryRemainingBalance">0.00 JOD</span>
                    </div>
                </div>
                
            <div class="action-buttons" style="margin-top: 1.5rem;">
                    <button type="submit" class="btn btn-primary" data-en="Confirm Payment" data-ar="تأكيد الدفع">Confirm Payment</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('paymentModal')" data-en="Cancel" data-ar="إلغاء">Cancel</button>
            </div>
            </form>
        </div>
    </div>

    <div class="modal" id="addPaymentModal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h2 class="modal-title" data-en="Add Payment" data-ar="إضافة دفعة">Add Payment</h2>
                <button class="modal-close" onclick="closeModal('addPaymentModal')">&times;</button>
            </div>
            <form method="POST" action="installments-management.php" onsubmit="return validateAddPaymentForm(event)">
                <input type="hidden" name="action" value="addPayment">
                <input type="hidden" name="studentId" id="addPaymentStudentId">
                <input type="hidden" name="installmentId" id="addPaymentInstallmentId" value="0">
                
                <div id="addPaymentStudentInfo" style="margin-bottom: 1.5rem; padding: 1rem; background: #FFF9F5; border-radius: 10px;">
                    <div style="font-weight: 700; font-size: 1.1rem; margin-bottom: 0.5rem;" id="addPaymentStudentName"></div>
                    <div style="color: #666; font-size: 0.9rem;">
                        <span data-en="Current Total Paid" data-ar="إجمالي المدفوع الحالي">Current Total Paid: </span>
                        <strong id="addPaymentCurrentPaid">0.00 JOD</strong>
                    </div>
                </div>
                
                <div class="form-group">
                    <label data-en="Installment" data-ar="القسط">Installment <span style="color: red;">*</span></label>
                    <select id="addPaymentInstallmentSelect" name="selectedInstallmentId" onchange="updateInstallmentInfo()" required>
                        <option value="" data-en="Select Installment" data-ar="اختر القسط">Select Installment</option>
                    </select>
                    <small style="color: #666; font-size: 0.85rem; display: block; margin-top: 0.25rem;" data-en="Please select which installment this payment applies to" data-ar="يرجى اختيار القسط الذي تنطبق عليه هذه الدفعة">Please select which installment this payment applies to</small>
                </div>
                
                <div class="form-group">
                    <label data-en="Payment Amount (JOD)" data-ar="مبلغ الدفع (دينار)">Payment Amount (JOD) <span style="color: red;">*</span></label>
                    <input type="number" 
                           id="addPaymentAmount" 
                           name="paymentAmount" 
                           step="0.01" 
                           min="0.01" 
                           max="100000" 
                           required
                           oninput="updateAddPaymentSummary()">
                </div>
                
                <div class="form-group">
                    <label data-en="Payment Method" data-ar="طريقة الدفع">Payment Method <span style="color: red;">*</span></label>
                    <select id="addPaymentMethod" name="paymentMethod" required>
                        <option value="cash" data-en="Cash" data-ar="نقد">Cash</option>
                        <option value="bank_transfer" data-en="Bank Transfer" data-ar="تحويل بنكي">Bank Transfer</option>
                        <option value="credit_card" data-en="Credit Card" data-ar="بطاقة ائتمان">Credit Card</option>
                        <option value="manual_entry" data-en="Manual Entry" data-ar="إدخال يدوي">Manual Entry</option>
                    </select>
                </div>

                <input type="hidden" name="receiptNumber" value="">

                <div class="form-group">
                    <label data-en="Payment Date" data-ar="تاريخ الدفع">Payment Date <span style="color: red;">*</span></label>
                    <input type="datetime-local" 
                           id="addPaymentDate" 
                           name="paymentDate" 
                           value="<?php echo date('Y-m-d\TH:i'); ?>"
                           required>
                </div>
                
                <div class="form-group">
                    <label data-en="Notes" data-ar="ملاحظات">Notes</label>
                    <textarea id="addPaymentNotes" name="notes" rows="3" placeholder="Optional notes..."></textarea>
                </div>

                <div class="financial-summary-card" style="margin-top: 1rem;">
                    <div class="financial-item">
                        <span class="financial-label" data-en="Total Required" data-ar="إجمالي المطلوب">Total Required</span>
                        <span class="financial-value neutral" id="addPaymentTotalRequired">0.00 JOD</span>
                    </div>
                    <div class="financial-item">
                        <span class="financial-label" data-en="Current Paid" data-ar="المدفوع الحالي">Current Paid</span>
                        <span class="financial-value positive" id="addPaymentCurrentPaidDisplay">0.00 JOD</span>
                    </div>
                    <div class="financial-item">
                        <span class="financial-label" data-en="New Payment" data-ar="الدفعة الجديدة">New Payment</span>
                        <span class="financial-value positive" id="addPaymentNewPayment">0.00 JOD</span>
                    </div>
                    <div class="financial-item">
                        <span class="financial-label" data-en="New Total Paid" data-ar="إجمالي المدفوع الجديد">New Total Paid</span>
                        <span class="financial-value positive" id="addPaymentNewTotalPaid">0.00 JOD</span>
                    </div>
                    <div class="financial-item">
                        <span class="financial-label" data-en="Remaining After Payment" data-ar="المتبقي بعد الدفع">Remaining After Payment</span>
                        <span class="financial-value" id="addPaymentNewRemaining" style="color: #FF6B9D;">0.00 JOD</span>
                    </div>
                </div>
                
                <div class="action-buttons" style="margin-top: 1.5rem;">
                    <button type="submit" class="btn btn-primary" data-en="Confirm Payment" data-ar="تأكيد الدفع">Confirm Payment</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addPaymentModal')" data-en="Cancel" data-ar="إلغاء">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="editModal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h2 class="modal-title" data-en="Edit Student Financials" data-ar="تعديل البيانات المالية للطالب">Edit Student Financials</h2>
                <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
            </div>
            <form method="POST" action="installments-management.php" onsubmit="return validateEditForm(event)" id="editStudentForm">
                <input type="hidden" name="action" value="updateStudentFinancials">
                <input type="hidden" name="studentId" id="editStudentId">
                
                <div id="editStudentInfo" style="margin-bottom: 1.5rem; padding: 1rem; background: #FFF9F5; border-radius: 10px;">
                    <div style="font-weight: 700; font-size: 1.1rem; margin-bottom: 0.5rem;" id="editStudentName"></div>
                    <div style="color: #666; font-size: 0.9rem;" id="editStudentClass"></div>
                </div>
                
                <div class="form-group">
                    <label data-en="Total Required Amount (JOD)" data-ar="إجمالي المبلغ المطلوب (دينار)">Total Required Amount (JOD) <span style="color: red;">*</span></label>
                    <input type="number" 
                           id="editTotalRequired" 
                           name="totalRequired" 
                           step="0.01" 
                           min="0" 
                           max="100000" 
                           required
                           oninput="updateEditRemaining()">
                </div>
                
                <div class="form-group">
                    <label data-en="Total Paid Amount (JOD)" data-ar="إجمالي المبلغ المدفوع (دينار)">Total Paid Amount (JOD) <span style="color: red;">*</span></label>
                    <input type="number" 
                           id="editTotalPaid" 
                           name="totalPaid" 
                           step="0.01" 
                           min="0" 
                           max="100000" 
                           required
                           oninput="updateEditRemaining()">
                </div>
                
                <div class="form-group">
                    <label data-en="Notes" data-ar="ملاحظات">Notes</label>
                    <textarea id="editNotes" name="notes" rows="3" placeholder="Optional notes..."></textarea>
                </div>

                <div class="financial-summary-card" style="margin-top: 1rem;">
                    <div class="financial-item">
                        <span class="financial-label" data-en="Remaining Amount" data-ar="المبلغ المتبقي">Remaining Amount</span>
                        <span class="financial-value" id="editRemainingAmount" style="color: #FF6B9D;">0.00 JOD</span>
                    </div>
                    <div class="financial-item">
                        <span class="financial-label" data-en="Payment Status" data-ar="حالة الدفع">Payment Status</span>
                        <span class="financial-value" id="editPaymentStatus" style="color: #666;">-</span>
                    </div>
                </div>
                
                <div class="action-buttons" style="margin-top: 1.5rem;">
                    <button type="submit" class="btn btn-primary" data-en="Save Changes" data-ar="حفظ التغييرات">Save Changes</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')" data-en="Cancel" data-ar="إلغاء">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        
        const allInstallments = <?php echo json_encode($allInstallments, JSON_UNESCAPED_UNICODE); ?>;
        const classes = <?php echo json_encode($classes, JSON_UNESCAPED_UNICODE); ?>;

        const studentsDataMap = {};
        <?php if (!empty($studentsData)): ?>
            <?php foreach ($studentsData as $id => $data): ?>
                studentsDataMap[<?php echo $id; ?>] = {
                    student: <?php echo json_encode($data['student'], JSON_UNESCAPED_UNICODE); ?>,
                    parent: <?php echo json_encode($data['parent'], JSON_UNESCAPED_UNICODE); ?>,
                    totalRequired: <?php echo $data['totalRequired']; ?>,
                    paidAmount: <?php echo $data['paidAmount']; ?>,
                    remainingAmount: <?php echo $data['remainingAmount']; ?>
                };
            <?php endforeach; ?>
        <?php endif; ?>
        
        let currentPaymentData = {
            installmentId: null,
            studentId: null,
            installmentAmount: 0,
            remainingBalance: 0
        };

        function loadSections() {
            const grade = document.getElementById('gradeFilter').value;
            const sectionFilter = document.getElementById('sectionFilter');
            sectionFilter.innerHTML = '<option value="all" data-en="All Sections" data-ar="جميع الأقسام">All Sections</option>';
            
            if (grade && grade !== 'all') {
                sectionFilter.style.display = 'inline-block';
                const gradeClasses = classes.filter(c => c.Grade_Level == grade);
                const uniqueSections = [...new Set(gradeClasses.map(c => c.Section).filter(s => s))];
                uniqueSections.sort().forEach(section => {
                    const option = document.createElement('option');
                    option.value = section;
                    option.textContent = 'Section ' + section.toUpperCase();
                    option.setAttribute('data-en', 'Section ' + section.toUpperCase());
                    option.setAttribute('data-ar', 'القسم ' + section.toUpperCase());
                    sectionFilter.appendChild(option);
                });
            } else {
                sectionFilter.style.display = 'none';
            }
            filterInstallments();
        }

        function filterInstallments() {
            const gradeFilter = document.getElementById('gradeFilter').value;
            const sectionFilter = document.getElementById('sectionFilter').value;
            const statusFilter = document.getElementById('paymentStatusFilter').value;

            const tbody = document.getElementById('installmentsTableBody');
            if (!tbody) return;
            
            const rows = tbody.querySelectorAll('tr');
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length < 9) return; 

                const classInfo = cells[6].textContent.toLowerCase();
                const statusText = cells[7].textContent.toLowerCase();

                let matchesGrade = gradeFilter === 'all';
                let matchesSection = sectionFilter === 'all' || !gradeFilter || gradeFilter === 'all';
                if (gradeFilter !== 'all') {
                    matchesGrade = classInfo.includes('grade ' + gradeFilter.toLowerCase());
                }
                if (sectionFilter !== 'all' && gradeFilter !== 'all') {
                    matchesSection = classInfo.includes('section ' + sectionFilter.toLowerCase());
                }

                let matchesStatus = true;
                if (statusFilter !== 'all') {
                    if (statusFilter === 'paid') {
                        matchesStatus = statusText.includes('paid') && !statusText.includes('partial');
                    } else if (statusFilter === 'partial') {
                        matchesStatus = statusText.includes('partial');
                    } else if (statusFilter === 'unpaid') {
                        matchesStatus = statusText.includes('unpaid');
                    } else if (statusFilter === 'overdue') {
                        matchesStatus = statusText.includes('overdue');
                    }
                }

                if (matchesGrade && matchesSection && matchesStatus) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function renderInstallmentsTable(studentsData) {
            const tbody = document.getElementById('installmentsTableBody');
            if (!tbody) return;
            
            if (studentsData.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="10" style="text-align: center; padding: 2rem; color: #666;">
                            <div style="font-size: 2rem; margin-bottom: 0.5rem;">🔍</div>
                            <div data-en="No installments found matching your filters" data-ar="لا توجد أقساط تطابق المرشحات">No installments found matching your filters</div>
                        </td>
                                </tr>
                `;
                return;
            }
            
            tbody.innerHTML = studentsData.map(data => {
                const student = data.student;
                const installments = data.installments;
                const totalFees = installments.reduce((sum, inst) => sum + parseFloat(inst.Amount), 0);
                const paidAmount = installments.filter(inst => inst.Status === 'paid')
                    .reduce((sum, inst) => sum + parseFloat(inst.Amount), 0);
                const remainingBalance = totalFees - paidAmount;
                const paymentPercentage = totalFees > 0 ? (paidAmount / totalFees) * 100 : 0;
                
                const nextDueDate = installments
                    .filter(inst => inst.Status !== 'paid')
                    .map(inst => inst.Due_Date)
                    .sort()[0];
                
                return `
                    <tr>
                        <td>
                            <div style="font-weight: 700;">${escapeHtml(student.NameEn)}</div>
                            ${student.NameAr ? `<div style="font-size: 0.85rem; color: #666;">${escapeHtml(student.NameAr)}</div>` : ''}
                        </td>
                        <td>${escapeHtml(student.Student_Code)}</td>
                        <td>
                            ${student.ClassName ? escapeHtml(student.ClassName) : ''}
                            ${student.Grade_Level ? `<div style="font-size: 0.85rem; color: #666;">Grade ${escapeHtml(student.Grade_Level)}</div>` : ''}
                        </td>
                        <td>
                            ${installments.length} <span data-en="installment(s)" data-ar="قسط(أقساط)">installment(s)</span>
                        </td>
                        <td style="font-weight: 700; color: var(--primary-color);">
                            ${totalFees.toFixed(2)} JOD
                        </td>
                        <td>
                            ${nextDueDate ? new Date(nextDueDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '<span style="color: #6BCB77;" data-en="All Paid" data-ar="مدفوع بالكامل">All Paid</span>'}
                        </td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <span class="status-indicator ${paymentPercentage >= 100 ? 'paid' : (paymentPercentage > 0 ? 'partial' : 'unpaid')}"></span>
                                <span class="status-badge ${paymentPercentage >= 100 ? 'status-active' : 'status-pending'}">
                                    ${paymentPercentage >= 100 ? (currentLanguage === 'en' ? 'Paid' : 'مدفوع') : (paymentPercentage > 0 ? (currentLanguage === 'en' ? 'Partial' : 'جزئي') : (currentLanguage === 'en' ? 'Unpaid' : 'غير مدفوع'))}
                                </span>
                    </div>
                            <div class="progress-bar-container" style="margin-top: 0.3rem;">
                                <div class="progress-bar">
                                    <div class="progress-fill ${paymentPercentage >= 100 ? 'paid' : (paymentPercentage > 0 ? 'partial' : 'unpaid')}" 
                                         style="width: ${Math.min(100, Math.max(0, paymentPercentage))}%"></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <button class="btn btn-primary btn-small" 
                                    onclick="viewStudentFinancials(${student.Student_ID}, '${escapeHtml(student.Student_Code)}')"
                                    data-en="Edit" data-ar="تعديل">Edit</button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function viewStudentFinancials(studentId, studentCode) {
            
            window.location.href = `installments-management.php?search=${encodeURIComponent(studentCode)}`;
        }

        function openEditModal(studentId, studentCode) {
            const studentData = studentsDataMap[studentId];
            if (!studentData) {
                alert('Student data not found');
                return;
            }
            
            const student = studentData.student;
            const parent = studentData.parent;

            document.getElementById('editStudentId').value = studentId;
            document.getElementById('editTotalRequired').value = studentData.totalRequired.toFixed(2);
            document.getElementById('editTotalPaid').value = studentData.paidAmount.toFixed(2);
            document.getElementById('editNotes').value = '';

            const studentName = student.NameEn + (student.NameAr ? ' (' + student.NameAr + ')' : '');
            document.getElementById('editStudentName').textContent = studentName;
            
            let classInfo = '';
            if (student.ClassName) {
                classInfo = student.ClassName;
            }
            if (student.Grade_Level) {
                classInfo += (classInfo ? ' - ' : '') + 'Grade ' + student.Grade_Level;
            }
            if (student.Section) {
                classInfo += ' - Section ' + student.Section;
            }
            document.getElementById('editStudentClass').textContent = classInfo || 'No class assigned';

            updateEditRemaining();

            const modal = document.getElementById('editModal');
            if (modal) {
                modal.classList.add('active');
            }
        }

        function updateEditRemaining() {
            const totalRequired = parseFloat(document.getElementById('editTotalRequired').value) || 0;
            const totalPaid = parseFloat(document.getElementById('editTotalPaid').value) || 0;
            const remaining = totalRequired - totalPaid;
            
            const remainingEl = document.getElementById('editRemainingAmount');
            const statusEl = document.getElementById('editPaymentStatus');
            
            remainingEl.textContent = remaining.toFixed(2) + ' JOD';
            
            if (remaining < 0) {
                remainingEl.style.color = '#FF6B9D';
                statusEl.textContent = 'Error: Paid exceeds Required';
                statusEl.style.color = '#FF6B9D';
            } else if (remaining === 0 && totalRequired > 0) {
                remainingEl.style.color = '#6BCB77';
                statusEl.textContent = 'Paid';
                statusEl.style.color = '#6BCB77';
            } else if (totalPaid > 0) {
                remainingEl.style.color = '#FF6B9D';
                statusEl.textContent = 'Partially Paid';
                statusEl.style.color = '#FFD93D';
            } else {
                remainingEl.style.color = '#FF6B9D';
                statusEl.textContent = 'Unpaid';
                statusEl.style.color = '#FF6B9D';
            }
        }

        function validateEditForm(event) {
            const totalRequired = parseFloat(document.getElementById('editTotalRequired').value);
            const totalPaid = parseFloat(document.getElementById('editTotalPaid').value);
            
            if (totalRequired < 0) {
                event.preventDefault();
                alert('Total required cannot be negative');
                return false;
            }
            
            if (totalPaid < 0) {
                event.preventDefault();
                alert('Total paid cannot be negative');
                return false;
            }
            
            if (totalPaid > totalRequired) {
                if (!confirm('Total paid exceeds total required. Continue anyway?')) {
                    event.preventDefault();
                    return false;
                }
            }
            
            return true;
        }

        function loadBulkSections() {
            const grade = document.getElementById('bulkGrade').value;
            const sectionFilter = document.getElementById('bulkSection');
            sectionFilter.innerHTML = '<option value="all" data-en="All Sections" data-ar="جميع الأقسام">All Sections</option>';
            
            if (grade && grade !== 'all') {
                sectionFilter.style.display = 'inline-block';
                const gradeClasses = classes.filter(c => c.Grade_Level == grade);
                const uniqueSections = [...new Set(gradeClasses.map(c => c.Section).filter(s => s))];
                uniqueSections.sort().forEach(section => {
                    const option = document.createElement('option');
                    option.value = section;
                    option.textContent = 'Section ' + section.toUpperCase();
                    option.setAttribute('data-en', 'Section ' + section.toUpperCase());
                    option.setAttribute('data-ar', 'القسم ' + section.toUpperCase());
                    sectionFilter.appendChild(option);
                });
            } else {
                sectionFilter.style.display = 'none';
            }
        }

        function validateBulkAddForm(event) {
            const grade = document.getElementById('bulkGrade').value;
            const amount = parseFloat(event.target.amount.value);
            
            if (grade === 'all') {
                event.preventDefault();
                alert('Please select a grade');
                return false;
            }
            
            if (!amount || amount <= 0) {
                event.preventDefault();
                alert('Amount must be greater than zero');
                return false;
            }
            
            if (amount > 100000) {
                event.preventDefault();
                alert('Amount exceeds maximum limit (100,000 JOD)');
                return false;
            }
            
            return confirm('This will add installments to all students in the selected class/section. Continue?');
        }

        function generateReport(format) {
            const gradeFilter = document.getElementById('gradeFilter')?.value || 'all';
            const sectionFilter = document.getElementById('sectionFilter')?.value || 'all';
            const statusFilter = document.getElementById('paymentStatusFilter')?.value || 'all';
            const studentNumber = document.getElementById('reportStudentNumber')?.value.trim() || '';
            const startDate = document.getElementById('reportStartDate')?.value || '';
            const endDate = document.getElementById('reportEndDate')?.value || '';

            const params = new URLSearchParams({
                action: 'export',
                format: format,
                grade: gradeFilter,
                section: sectionFilter,
                status: statusFilter
            });

            if (studentNumber) {
                params.append('studentNumber', studentNumber);
            }
            if (startDate) {
                params.append('startDate', startDate);
            }
            if (endDate) {
                params.append('endDate', endDate);
            }

            window.location.href = `installments-management-export.php?${params.toString()}`;
        }

        function openPaymentModal(installmentId, studentId, installmentAmount, remainingBalance) {
            currentPaymentData = {
                installmentId: installmentId,
                studentId: studentId,
                installmentAmount: installmentAmount,
                remainingBalance: remainingBalance
            };
            
            document.getElementById('paymentStudentId').value = studentId;
            document.getElementById('paymentInstallmentId').value = installmentId;
            document.getElementById('paymentAmount').value = '';
            document.getElementById('summaryInstallmentAmount').textContent = installmentAmount.toFixed(2) + ' JOD';
            document.getElementById('summaryPaymentAmount').textContent = '0.00 JOD';
            document.getElementById('summaryRemainingBalance').textContent = remainingBalance.toFixed(2) + ' JOD';
            
            const modal = document.getElementById('paymentModal');
            if (modal) {
                modal.classList.add('active');
            }
        }

        function openAddPaymentModal(studentId, studentName, totalRequired, currentPaid) {
            
            document.getElementById('addPaymentStudentId').value = studentId;
            document.getElementById('addPaymentInstallmentId').value = '0'; 
            document.getElementById('addPaymentAmount').value = '';
            
            document.getElementById('addPaymentNotes').value = '';

            loadStudentInstallments(studentId);

            document.getElementById('addPaymentStudentName').textContent = studentName;
            document.getElementById('addPaymentTotalRequired').textContent = totalRequired.toFixed(2) + ' JOD';
            document.getElementById('addPaymentCurrentPaid').textContent = currentPaid.toFixed(2) + ' JOD';
            document.getElementById('addPaymentCurrentPaidDisplay').textContent = currentPaid.toFixed(2) + ' JOD';
            document.getElementById('addPaymentNewPayment').textContent = '0.00 JOD';
            document.getElementById('addPaymentNewTotalPaid').textContent = currentPaid.toFixed(2) + ' JOD';
            document.getElementById('addPaymentNewRemaining').textContent = (totalRequired - currentPaid).toFixed(2) + ' JOD';

            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            document.getElementById('addPaymentDate').value = `${year}-${month}-${day}T${hours}:${minutes}`;

            const modal = document.getElementById('addPaymentModal');
            if (modal) {
                modal.classList.add('active');
            }
        }

        function loadStudentInstallments(studentId) {
            
            fetch(`installments-management-ajax.php?action=getInstallments&studentId=${studentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.installments) {
                        const select = document.getElementById('addPaymentInstallmentSelect');
                        
                        select.innerHTML = '<option value="" data-en="Select Installment" data-ar="اختر القسط">Select Installment</option>';
                        
                        if (data.installments.length === 0) {
                            const option = document.createElement('option');
                            option.value = '';
                            option.textContent = 'No installments available';
                            option.disabled = true;
                            select.appendChild(option);
                            return;
                        }
                        
                        data.installments.forEach(inst => {
                            const option = document.createElement('option');
                            option.value = inst.Installment_ID;
                            const dueDate = new Date(inst.Due_Date);
                            const monthYear = dueDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
                            const paidAmount = parseFloat(inst.paid_amount || 0);
                            const remaining = parseFloat(inst.Amount) - paidAmount;
                            const status = remaining <= 0.01 ? 'Paid' : (paidAmount > 0 ? 'Partial' : 'Unpaid');
                            
                            option.textContent = `Installment ${inst.Installment_Number} - ${monthYear} (${status}: ${remaining.toFixed(2)} JOD remaining)`;
                            option.setAttribute('data-amount', inst.Amount);
                            option.setAttribute('data-paid', paidAmount);
                            option.setAttribute('data-remaining', remaining);

                            if (remaining <= 0.01) {
                                option.disabled = true;
                                option.textContent += ' [Fully Paid]';
                            }
                            
                            select.appendChild(option);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading installments:', error);
                    const select = document.getElementById('addPaymentInstallmentSelect');
                    select.innerHTML = '<option value="">Error loading installments</option>';
                });
        }

        function updateInstallmentInfo() {
            const select = document.getElementById('addPaymentInstallmentSelect');
            const selectedOption = select.options[select.selectedIndex];
            const installmentId = select.value;

            document.getElementById('addPaymentInstallmentId').value = installmentId;
            document.getElementById('addPaymentSelectedInstallmentId').value = installmentId;

            if (selectedOption && installmentId) {
                const remaining = parseFloat(selectedOption.getAttribute('data-remaining') || 0);
                const paymentAmountInput = document.getElementById('addPaymentAmount');
                if (paymentAmountInput) {
                    paymentAmountInput.max = remaining + 0.01; 
                    paymentAmountInput.setAttribute('data-max-remaining', remaining);
                }
            }
        }

        function updateAddPaymentSummary() {
            const paymentAmount = parseFloat(document.getElementById('addPaymentAmount').value) || 0;
            const currentPaid = parseFloat(document.getElementById('addPaymentCurrentPaid').textContent.replace(' JOD', '')) || 0;
            const totalRequired = parseFloat(document.getElementById('addPaymentTotalRequired').textContent.replace(' JOD', '')) || 0;
            
            const newTotalPaid = currentPaid + paymentAmount; 
            const newRemaining = totalRequired - newTotalPaid;
            
            document.getElementById('addPaymentNewPayment').textContent = paymentAmount.toFixed(2) + ' JOD';
            document.getElementById('addPaymentNewTotalPaid').textContent = newTotalPaid.toFixed(2) + ' JOD';
            document.getElementById('addPaymentNewRemaining').textContent = newRemaining.toFixed(2) + ' JOD';

            const remainingEl = document.getElementById('addPaymentNewRemaining');
            if (newRemaining <= 0.01) {
                remainingEl.style.color = '#6BCB77';
            } else {
                remainingEl.style.color = '#FF6B9D';
            }
        }

        function validateAddPaymentForm(event) {
            const paymentAmount = parseFloat(document.getElementById('addPaymentAmount').value);
            const installmentSelect = document.getElementById('addPaymentInstallmentSelect');
            const selectedInstallmentId = installmentSelect.value;

            if (!selectedInstallmentId || selectedInstallmentId === '') {
                event.preventDefault();
                alert('Please select an installment to apply this payment to');
                return false;
            }

            if (!paymentAmount || paymentAmount <= 0) {
                event.preventDefault();
                alert('Payment amount must be greater than zero');
                return false;
            }
            
            if (paymentAmount > 100000) {
                event.preventDefault();
                alert('Payment amount exceeds maximum limit (100,000 JOD)');
                return false;
            }

            const selectedOption = installmentSelect.options[installmentSelect.selectedIndex];
            if (selectedOption) {
                const remaining = parseFloat(selectedOption.getAttribute('data-remaining') || 0);
                if (paymentAmount > remaining + 0.01) {
                    event.preventDefault();
                    alert(`Payment amount (${paymentAmount.toFixed(2)} JOD) exceeds the remaining amount for this installment (${remaining.toFixed(2)} JOD). Please reduce the payment amount or select a different installment.`);
                    return false;
                }
            }
            
            return true;
        }

        function updatePaymentSummary() {
            const paymentAmount = parseFloat(document.getElementById('paymentAmount').value) || 0;
            const installmentAmount = currentPaymentData.installmentAmount;
            const remainingAfterPayment = Math.max(0, installmentAmount - paymentAmount);
            
            document.getElementById('summaryPaymentAmount').textContent = paymentAmount.toFixed(2) + ' JOD';
            document.getElementById('summaryRemainingBalance').textContent = remainingAfterPayment.toFixed(2) + ' JOD';

            const remainingEl = document.getElementById('summaryRemainingBalance');
            if (remainingAfterPayment <= 0.01) {
                remainingEl.className = 'financial-value positive';
            } else {
                remainingEl.className = 'financial-value negative';
            }
        }

        function validatePaymentForm(event) {
            const paymentAmount = parseFloat(document.getElementById('paymentAmount').value);
            const installmentAmount = currentPaymentData.installmentAmount;
            
            if (!paymentAmount || paymentAmount <= 0) {
                event.preventDefault();
                showNotification(currentLanguage === 'en' ? 'Payment amount must be greater than zero' : 'يجب أن يكون مبلغ الدفع أكبر من الصفر', 'error');
                return false;
            }
            
            if (paymentAmount > 100000) {
                event.preventDefault();
                showNotification(currentLanguage === 'en' ? 'Payment amount exceeds maximum limit (100,000 JOD)' : 'مبلغ الدفع يتجاوز الحد الأقصى (100,000 دينار)', 'error');
                return false;
            }
            
            if (paymentAmount > installmentAmount * 1.1) { 
                if (!confirm(currentLanguage === 'en' 
                    ? `Payment amount (${paymentAmount.toFixed(2)} JOD) exceeds installment amount (${installmentAmount.toFixed(2)} JOD). Continue?`
                    : `مبلغ الدفع (${paymentAmount.toFixed(2)} دينار) يتجاوز مبلغ القسط (${installmentAmount.toFixed(2)} دينار). متابعة؟`)) {
                    event.preventDefault();
                    return false;
                }
            }
            
            return true;
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        document.addEventListener('DOMContentLoaded', function() {
            filterInstallments();

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
        });
    </script>
</body>
</html>
