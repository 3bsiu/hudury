<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-parent.php';

requireUserType('parent');

$currentParentId = getCurrentUserId();
$currentParent = getCurrentUserData($pdo);
$parentName = $_SESSION['user_name'] ?? 'Parent';

$linkedStudentIds = [];
$linkedStudentsData = [];
$allInstallments = [];
$totalAmount = 0;
$totalPaid = 0;
$totalRemaining = 0;

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
                   c.Name as ClassName, c.Grade_Level, c.Section
            FROM student s
            LEFT JOIN class c ON s.Class_ID = c.Class_ID
            WHERE s.Student_ID IN ($placeholders)
            ORDER BY s.NameEn ASC
        ");
        $stmt->execute($linkedStudentIds);
        $linkedStudentsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT i.*, s.Student_ID, s.Student_Code, s.NameEn as StudentNameEn, s.NameAr as StudentNameAr
            FROM installment i
            INNER JOIN student s ON i.Student_ID = s.Student_ID
            WHERE i.Student_ID IN ($placeholders)
            ORDER BY i.Due_Date ASC, i.Installment_Number ASC
        ");
        $stmt->execute($linkedStudentIds);
        $allInstallments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allInstallments as &$installment) {
            $installmentId = $installment['Installment_ID'];

            $stmt = $pdo->prepare("
                SELECT ph.*, a.NameEn as AdminNameEn, a.NameAr as AdminNameAr
                FROM payment_history ph
                LEFT JOIN admin a ON ph.Recorded_By = a.Admin_ID
                WHERE ph.Installment_ID = ?
                ORDER BY ph.Payment_Date DESC
            ");
            $stmt->execute([$installmentId]);
            $installment['Payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $paidAmount = 0;
            foreach ($installment['Payments'] as $payment) {
                $paidAmount += floatval($payment['Amount']);
            }
            
            $installment['PaidAmount'] = $paidAmount;
            $installment['RemainingAmount'] = floatval($installment['Amount']) - $paidAmount;

            if ($paidAmount >= floatval($installment['Amount']) - 0.01) {
                $installment['PaymentStatus'] = 'paid';
            } elseif ($paidAmount > 0) {
                $installment['PaymentStatus'] = 'partial';
            } else {
                $installment['PaymentStatus'] = 'unpaid';
            }

            $installment['LastPaymentDate'] = !empty($installment['Payments']) 
                ? $installment['Payments'][0]['Payment_Date'] 
                : null;

            $totalAmount += floatval($installment['Amount']);
            $totalPaid += $paidAmount;
            $totalRemaining += $installment['RemainingAmount'];
        }
        unset($installment);
    }
} catch (PDOException $e) {
    error_log("Error fetching installments data: " . $e->getMessage());
    $linkedStudentIds = [];
    $linkedStudentsData = [];
    $allInstallments = [];
}

$paymentProgress = $totalAmount > 0 ? ($totalPaid / $totalAmount) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installments - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
        .installments-page-header {
            background: linear-gradient(135deg, #FF6B9D, #C44569);
            color: white;
            padding: 2rem;
            margin-bottom: 2rem;
            border-radius: 0 0 30px 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .installments-page-header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .back-button {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
            padding: 0.8rem 1.5rem;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 700;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
        }

        .back-button:hover {
            background: rgba(255,255,255,0.3);
            transform: translateX(-5px);
        }

        .installments-page-title {
            font-family: 'Fredoka', sans-serif;
            font-size: 2.5rem;
            margin: 0;
        }

        .installments-page-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .installments-page-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .installments-page-card {
            background: white;
            padding: 2rem;
            border-radius: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            text-align: center;
            transition: all 0.4s;
        }

        .installments-page-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(255, 107, 157, 0.3);
        }

        .installments-page-card-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .installments-page-card-value {
            font-size: 2.5rem;
            font-weight: 800;
            font-family: 'Fredoka', sans-serif;
            margin-bottom: 0.5rem;
        }

        .installments-page-card-label {
            color: #666;
            font-weight: 600;
        }

        .installments-page-section {
            background: white;
            padding: 2.5rem;
            border-radius: 30px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .installments-page-section-title {
            font-family: 'Fredoka', sans-serif;
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .installments-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .installments-table thead {
            background: linear-gradient(135deg, #FF6B9D, #6BCB77);
            color: white;
        }

        .installments-table th {
            padding: 1.2rem;
            text-align: left;
            font-weight: 700;
            font-size: 1.1rem;
        }

        .installments-table td {
            padding: 1.2rem;
            border-bottom: 2px solid #FFE5E5;
        }

        .installments-table tr:hover {
            background: #FFF9F5;
        }

        .installments-table tr:last-child td {
            border-bottom: none;
        }

        .table-status-badge {
            padding: 0.6rem 1.2rem;
            border-radius: 20px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table-status-paid {
            background: linear-gradient(135deg, #6BCB77, #4CAF50);
            color: white;
        }

        .table-status-pending {
            background: linear-gradient(135deg, #FFD93D, #FFC107);
            color: var(--text-dark);
        }

        .table-status-unpaid {
            background: linear-gradient(135deg, #FF6B9D, #FF4757);
            color: white;
        }

        .table-status-partial {
            background: linear-gradient(135deg, #FFD93D, #FFC107);
            color: var(--text-dark);
        }

        .table-totals-row {
            background: #FFF9F5;
            font-weight: 800;
            font-size: 1.1rem;
        }

        .table-totals-row td {
            border-top: 3px solid #FF6B9D;
        }

        .payment-history-item {
            padding: 0.5rem;
            margin: 0.3rem 0;
            background: #f9f9f9;
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .payment-history-item strong {
            color: #6BCB77;
        }

        .filter-section {
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: #FFF9F5;
            border-radius: 15px;
        }

        .filter-section select {
            padding: 0.8rem;
            border: 2px solid #FFE5E5;
            border-radius: 10px;
            font-size: 1rem;
            background: white;
            cursor: pointer;
        }

        .table-amount {
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--primary-color);
            font-family: 'Fredoka', sans-serif;
        }

        @media (max-width: 768px) {
            .installments-page-header-content {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }

            .installments-page-title {
                font-size: 2rem;
            }

            .installments-page-container {
                padding: 1rem;
            }

            .installments-page-summary {
                grid-template-columns: 1fr;
            }

            .installments-page-section {
                padding: 1.5rem;
                overflow-x: auto;
            }

            .installments-table {
                min-width: 600px;
            }

            .installments-table th,
            .installments-table td {
                padding: 0.8rem;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 480px) {
            .installments-page-header {
                padding: 1.5rem;
            }

            .installments-page-title {
                font-size: 1.5rem;
            }

            .installments-page-card {
                padding: 1.5rem;
            }

            .installments-page-card-icon {
                font-size: 2.5rem;
            }

            .installments-page-card-value {
                font-size: 2rem;
            }

            .installments-page-section {
                padding: 1rem;
            }

            .installments-page-section-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    
    <div class="installments-page-header">
        <div class="installments-page-header-content">
            <a href="parent-dashboard.php" class="back-button">
                <i class="fas fa-arrow-left"></i>
                <span data-en="Back to Dashboard" data-ar="العودة إلى لوحة التحكم">Back to Dashboard</span>
            </a>
            <h1 class="installments-page-title" data-en="Installments History" data-ar="سجل الأقساط">Installments History</h1>
            <div></div>
        </div>
    </div>

    <div class="installments-page-container">
        
        <div class="installments-page-summary">
            <div class="installments-page-card">
                <div class="installments-page-card-icon">💰</div>
                <div class="installments-page-card-value total-amount"><?php echo number_format($totalAmount, 2); ?> JOD</div>
                <div class="installments-page-card-label" data-en="Total Amount Due" data-ar="إجمالي المبلغ المستحق">Total Amount Due</div>
            </div>
            <div class="installments-page-card">
                <div class="installments-page-card-icon">✅</div>
                <div class="installments-page-card-value paid-amount" style="color: #6BCB77;"><?php echo number_format($totalPaid, 2); ?> JOD</div>
                <div class="installments-page-card-label" data-en="Total Paid" data-ar="إجمالي المدفوع">Total Paid</div>
            </div>
            <div class="installments-page-card">
                <div class="installments-page-card-icon">⏳</div>
                <div class="installments-page-card-value outstanding-amount" style="color: #FF6B9D;"><?php echo number_format($totalRemaining, 2); ?> JOD</div>
                <div class="installments-page-card-label" data-en="Outstanding Balance" data-ar="الرصيد المستحق">Outstanding Balance</div>
            </div>
            <div class="installments-page-card">
                <div class="installments-page-card-icon">📊</div>
                <div class="installments-page-card-value" style="color: #6BCB77;"><?php echo number_format($paymentProgress, 1); ?>%</div>
                <div class="installments-page-card-label" data-en="Payment Progress" data-ar="تقدم الدفع">Payment Progress</div>
            </div>
        </div>

        <div class="installments-page-section">
            <h2 class="installments-page-section-title">
                <span>📊</span>
                <span data-en="Payment Progress" data-ar="تقدم الدفع">Payment Progress</span>
            </h2>
            <div class="payment-progress">
                <div class="progress-label-financial">
                    <span data-en="Payment Progress" data-ar="تقدم الدفع">Payment Progress</span>
                    <span><?php echo number_format($paymentProgress, 1); ?>%</span>
                </div>
                <div class="progress-bar-financial">
                    <div class="progress-fill-financial" style="width: <?php echo min(100, max(0, $paymentProgress)); ?>%"></div>
                </div>
            </div>
        </div>

        <div class="installments-page-section">
            <h2 class="installments-page-section-title">
                <span>💳</span>
                <span data-en="Payment History" data-ar="سجل المدفوعات">Payment History</span>
            </h2>
            <div style="overflow-x: auto;">
                <table class="installments-table">
                    <thead>
                        <tr>
                            <th data-en="Payment ID" data-ar="رقم الدفعة">Payment ID</th>
                            <th data-en="Date & Time" data-ar="التاريخ والوقت">Date & Time</th>
                            <th data-en="Student" data-ar="الطالب">Student</th>
                            <th data-en="Installment" data-ar="القسط">Installment</th>
                            <th data-en="Month/Period" data-ar="الشهر/الفترة">Month/Period</th>
                            <th data-en="Amount" data-ar="المبلغ">Amount</th>
                            <th data-en="Payment Method" data-ar="طريقة الدفع">Payment Method</th>
                            <th data-en="Receipt Number" data-ar="رقم الإيصال">Receipt Number</th>
                            <th data-en="Notes" data-ar="ملاحظات">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        
                        $allPaymentHistory = [];
                        if (!empty($linkedStudentIds)) {
                            $placeholders = implode(',', array_fill(0, count($linkedStudentIds), '?'));
                            $stmt = $pdo->prepare("
                                SELECT ph.*, 
                                       i.Installment_Number, i.Due_Date,
                                       s.Student_ID, s.Student_Code, s.NameEn as StudentNameEn, s.NameAr as StudentNameAr
                                FROM payment_history ph
                                INNER JOIN installment i ON ph.Installment_ID = i.Installment_ID
                                INNER JOIN student s ON ph.Student_ID = s.Student_ID
                                WHERE ph.Student_ID IN ($placeholders)
                                ORDER BY ph.Payment_Date DESC, ph.Payment_ID DESC
                            ");
                            $stmt->execute($linkedStudentIds);
                            $allPaymentHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        }
                        ?>
                        <?php if (empty($allPaymentHistory)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 3rem; color: #666;">
                                    <div style="font-size: 3rem; margin-bottom: 1rem;">💳</div>
                                    <div data-en="No payment history found" data-ar="لا يوجد سجل مدفوعات">No payment history found</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allPaymentHistory as $payment): 
                                $paymentDateTime = new DateTime($payment['Payment_Date']);
                                $dueDate = new DateTime($payment['Due_Date']);
                                $monthPeriod = $dueDate->format('F Y');
                                
                                $methodLabels = [
                                    'cash' => 'Cash',
                                    'bank_transfer' => 'Bank Transfer',
                                    'credit_card' => 'Credit Card',
                                    'manual_entry' => 'Manual Entry'
                                ];
                                $methodLabel = $methodLabels[$payment['Payment_Method']] ?? $payment['Payment_Method'];
                            ?>
                                <tr data-student-id="<?php echo $payment['Student_ID']; ?>">
                                    <td style="font-weight: 700; color: var(--primary-color);">

                                    </td>
                                    <td><?php echo $paymentDateTime->format('M d, Y H:i'); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($payment['StudentNameEn']); ?></strong>
                                        <br><small style="color: #666;"><?php echo htmlspecialchars($payment['Student_Code']); ?></small>
                                    </td>
                                    <td style="font-weight: 700;">
                                        <?php echo htmlspecialchars($payment['Installment_Number'] ?? 'N/A'); ?>
                                    </td>
                                    <td><?php echo $monthPeriod; ?></td>
                                    <td class="table-amount" style="color: #6BCB77;">
                                        <?php echo number_format(floatval($payment['Amount']), 2); ?> JOD
                                    </td>
                                    <td><?php echo $methodLabel; ?></td>
                                    <td>
                                        <?php if (!empty($payment['Receipt_Number'])): ?>
                                            <strong><?php echo htmlspecialchars($payment['Receipt_Number']); ?></strong>
                                        <?php else: ?>
                                            <span style="color: #999;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($payment['Notes'])): ?>
                                            <?php echo htmlspecialchars($payment['Notes']); ?>
                                        <?php else: ?>
                                            <span style="color: #999;">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="installments-page-section">
            <h2 class="installments-page-section-title">
                <span>📋</span>
                <span data-en="Detailed Installments" data-ar="الأقساط التفصيلية">Detailed Installments</span>
            </h2>
            
            <?php if (!empty($linkedStudentsData) && count($linkedStudentsData) > 1): ?>
            
            <div class="filter-section">
                <label style="font-weight: 700; margin-bottom: 0.5rem; display: block;" data-en="Select Student" data-ar="اختر الطالب">Select Student:</label>
                <select id="studentFilter" onchange="filterByStudent()">
                    <option value="all" data-en="All Students" data-ar="جميع الطلاب">All Students</option>
                    <?php foreach ($linkedStudentsData as $student): ?>
                        <option value="<?php echo $student['Student_ID']; ?>">
                            <?php echo htmlspecialchars($student['NameEn']); ?> 
                            (<?php echo htmlspecialchars($student['Student_Code']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <div style="overflow-x: auto;">
                <table class="installments-table">
                    <thead>
                        <tr>
                            <th data-en="Student" data-ar="الطالب">Student</th>
                            <th data-en="Installment" data-ar="القسط">Installment</th>
                            <th data-en="Month/Period" data-ar="الشهر/الفترة">Month/Period</th>
                            <th data-en="Total Amount Due" data-ar="إجمالي المبلغ المستحق">Total Amount Due</th>
                            <th data-en="Total Paid" data-ar="إجمالي المدفوع">Total Paid</th>
                            <th data-en="Remaining" data-ar="المتبقي">Remaining</th>
                            <th data-en="Status" data-ar="الحالة">Status</th>
                            <th data-en="Last Payment Date" data-ar="تاريخ آخر دفعة">Last Payment Date</th>
                            <th data-en="Due Date" data-ar="تاريخ الاستحقاق">Due Date</th>
                            <th data-en="Notes" data-ar="ملاحظات">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allInstallments)): ?>
                            <tr>
                                <td colspan="10" style="text-align: center; padding: 3rem; color: #666;">
                                    <div style="font-size: 3rem; margin-bottom: 1rem;">📋</div>
                                    <div data-en="No installments found" data-ar="لا توجد أقساط">No installments found</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allInstallments as $installment): 
                                $dueDate = new DateTime($installment['Due_Date']);
                                $monthPeriod = $dueDate->format('F Y');

                                $statusClass = 'table-status-unpaid';
                                $statusIcon = 'fas fa-times-circle';
                                $statusText = 'Unpaid';
                                if ($installment['PaymentStatus'] === 'paid') {
                                    $statusClass = 'table-status-paid';
                                    $statusIcon = 'fas fa-check-circle';
                                    $statusText = 'Paid';
                                } elseif ($installment['PaymentStatus'] === 'partial') {
                                    $statusClass = 'table-status-partial';
                                    $statusIcon = 'fas fa-clock';
                                    $statusText = 'Partially Paid';
                                }
                            ?>
                                <tr data-student-id="<?php echo $installment['Student_ID']; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($installment['StudentNameEn']); ?></strong>
                                        <br><small style="color: #666;"><?php echo htmlspecialchars($installment['Student_Code']); ?></small>
                                    </td>
                                    <td style="font-weight: 700;">
                                        <?php echo htmlspecialchars($installment['Installment_Number'] ?? 'N/A'); ?>
                                    </td>
                                    <td><?php echo $monthPeriod; ?></td>
                                    <td class="table-amount"><?php echo number_format(floatval($installment['Amount']), 2); ?> JOD</td>
                                    <td style="color: #6BCB77; font-weight: 700;">
                                        <?php echo number_format($installment['PaidAmount'], 2); ?> JOD
                                    </td>
                                    <td style="color: <?php echo $installment['RemainingAmount'] > 0 ? '#FF6B9D' : '#6BCB77'; ?>; font-weight: 700;">
                                        <?php echo number_format($installment['RemainingAmount'], 2); ?> JOD
                                    </td>
                                    <td>
                                        <span class="table-status-badge <?php echo $statusClass; ?>">
                                            <i class="<?php echo $statusIcon; ?>"></i>
                                            <span data-en="<?php echo $statusText; ?>" data-ar="<?php 
                                                echo $statusText === 'Paid' ? 'مدفوع' : ($statusText === 'Partially Paid' ? 'مدفوع جزئياً' : 'غير مدفوع'); 
                                            ?>"><?php echo $statusText; ?></span>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($installment['LastPaymentDate']): 
                                            $lastPayment = new DateTime($installment['LastPaymentDate']);
                                            echo $lastPayment->format('M d, Y');
                                        else: ?>
                                            <span style="color: #999;" data-en="No payments" data-ar="لا توجد مدفوعات">No payments</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $dueDate->format('M d, Y'); ?></td>
                                    <td>
                                        <?php if (!empty($installment['Notes'])): ?>
                                            <?php echo htmlspecialchars($installment['Notes']); ?>
                                        <?php else: ?>
                                            <span style="color: #999;">-</span>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($installment['Payments'])): ?>
                                            <div style="margin-top: 0.5rem;">
                                                <details style="cursor: pointer;">
                                                    <summary style="color: #6BCB77; font-size: 0.85rem; font-weight: 600;" data-en="View Payments" data-ar="عرض المدفوعات">View Payments</summary>
                                                    <div style="margin-top: 0.5rem;">
                                                        <?php foreach ($installment['Payments'] as $payment): 
                                                            $paymentDate = new DateTime($payment['Payment_Date']);
                                                        ?>
                                                            <div class="payment-history-item">
                                                                <strong><?php echo number_format(floatval($payment['Amount']), 2); ?> JOD</strong>
                                                                - <?php echo $paymentDate->format('M d, Y'); ?>
                                                                <?php if ($payment['Receipt_Number']): ?>
                                                                    <br><small>Receipt: <?php echo htmlspecialchars($payment['Receipt_Number']); ?></small>
                                                                <?php endif; ?>
                                                                <?php if ($payment['Notes']): ?>
                                                                    <br><small><?php echo htmlspecialchars($payment['Notes']); ?></small>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </details>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <tr class="table-totals-row">
                                <td colspan="3" style="text-align: right;" data-en="TOTALS" data-ar="الإجمالي">TOTALS:</td>
                                <td class="table-amount"><?php echo number_format($totalAmount, 2); ?> JOD</td>
                                <td style="color: #6BCB77;"><?php echo number_format($totalPaid, 2); ?> JOD</td>
                                <td style="color: #FF6B9D;"><?php echo number_format($totalRemaining, 2); ?> JOD</td>
                                <td colspan="4"></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script src="script.js"></script>
    <script>
        
        let currentLanguage = 'en';

        function toggleLanguage() {
            currentLanguage = currentLanguage === 'en' ? 'ar' : 'en';
            document.documentElement.lang = currentLanguage;
            document.documentElement.dir = currentLanguage === 'ar' ? 'rtl' : 'ltr';
            
            document.querySelectorAll('[data-en][data-ar]').forEach(element => {
                const text = element.getAttribute(`data-${currentLanguage}`);
                if (text) {
                    element.textContent = text;
                }
            });
        }

        window.addEventListener('load', () => {
            const progressBar = document.querySelector('.progress-fill-financial');
            if (progressBar) {
                const width = progressBar.style.width;
                progressBar.style.width = '0%';
                setTimeout(() => {
                    progressBar.style.width = width;
                }, 300);
            }
        });

        function filterByStudent() {
            const selectedStudentId = document.getElementById('studentFilter')?.value;
            
            const installmentRows = document.querySelectorAll('table.installments-table tbody tr[data-student-id]');
            installmentRows.forEach(row => {
                if (selectedStudentId === 'all' || row.getAttribute('data-student-id') === selectedStudentId) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });

            const paymentHistoryTables = document.querySelectorAll('table.installments-table');
            if (paymentHistoryTables.length > 1) {
                const paymentRows = paymentHistoryTables[0].querySelectorAll('tbody tr[data-student-id]');
                paymentRows.forEach(row => {
                    if (selectedStudentId === 'all' || row.getAttribute('data-student-id') === selectedStudentId) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            }
        }
    </script>
</body>
</html>

