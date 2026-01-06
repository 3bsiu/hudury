<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('admin');

$format = $_GET['format'] ?? 'csv';
$grade = $_GET['grade'] ?? 'all';
$section = $_GET['section'] ?? 'all';
$status = $_GET['status'] ?? 'all';
$studentNumber = $_GET['studentNumber'] ?? '';
$startDate = $_GET['startDate'] ?? '';
$endDate = $_GET['endDate'] ?? '';

try {
    
    $query = "
        SELECT 
            s.Student_ID, s.Student_Code, s.NameEn as StudentNameEn, s.NameAr as StudentNameAr,
            c.Name as ClassName, c.Grade_Level, c.Section,
            p.NameEn as ParentNameEn, p.NameAr as ParentNameAr
        FROM student s
        LEFT JOIN class c ON s.Class_ID = c.Class_ID
        LEFT JOIN parent_student_relationship psr ON s.Student_ID = psr.Student_ID AND psr.Is_Primary = 1
        LEFT JOIN parent p ON psr.Parent_ID = p.Parent_ID
        WHERE s.Status = 'active'
    ";
    
    $params = [];

    if ($grade !== 'all') {
        $query .= " AND c.Grade_Level = ?";
        $params[] = $grade;
    }
    
    if ($section !== 'all' && $grade !== 'all') {
        $query .= " AND UPPER(c.Section) = UPPER(?)";
        $params[] = $section;
    }
    
    if ($studentNumber) {
        $query .= " AND s.Student_Code = ?";
        $params[] = $studentNumber;
    }
    
    $query .= " ORDER BY s.NameEn ASC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filteredStudents = [];
    foreach ($students as $student) {
        $studentId = $student['Student_ID'];

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(Amount), 0) as total_required FROM installment WHERE Student_ID = ?");
        $stmt->execute([$studentId]);
        $totalRequired = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total_required'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(ph.Amount), 0) as total_paid, MAX(ph.Payment_Date) as last_payment
            FROM payment_history ph
            INNER JOIN installment i ON ph.Installment_ID = i.Installment_ID
            WHERE i.Student_ID = ?
        ");
        $stmt->execute([$studentId]);
        $paymentData = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalPaid = floatval($paymentData['total_paid'] ?? 0);
        $lastPaymentDate = $paymentData['last_payment'] ?? null;

        $includeStudent = true;
        if ($startDate || $endDate) {
            
            $dateCheckQuery = "
                SELECT COUNT(*) as count
                FROM (
                    SELECT i.Installment_ID, i.Due_Date as date_field, i.Created_At
                    FROM installment i
                    WHERE i.Student_ID = ?
                    UNION
                    SELECT ph.Installment_ID, ph.Payment_Date as date_field, ph.Payment_Date as Created_At
                    FROM payment_history ph
                    INNER JOIN installment i ON ph.Installment_ID = i.Installment_ID
                    WHERE i.Student_ID = ?
                ) as combined
                WHERE 1=1
            ";
            $dateParams = [$studentId, $studentId];
            
            if ($startDate) {
                $dateCheckQuery .= " AND (date_field >= ? OR Created_At >= ?)";
                $dateParams[] = $startDate;
                $dateParams[] = $startDate . ' 00:00:00';
            }
            if ($endDate) {
                $dateCheckQuery .= " AND (date_field <= ? OR Created_At <= ?)";
                $dateParams[] = $endDate;
                $dateParams[] = $endDate . ' 23:59:59';
            }
            
            $dateStmt = $pdo->prepare($dateCheckQuery);
            $dateStmt->execute($dateParams);
            $dateCount = $dateStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

            if ($dateCount == 0) {
                $includeStudent = false;
            }
        }
        
        if ($includeStudent) {
            $student['TotalRequired'] = $totalRequired;
            $student['TotalPaid'] = $totalPaid;
            $student['RemainingAmount'] = $totalRequired - $totalPaid;
            $student['LastPaymentDate'] = $lastPaymentDate;
            $filteredStudents[] = $student;
        }
    }
    $students = $filteredStudents;

    if ($status !== 'all') {
        $students = array_filter($students, function($student) use ($status) {
            if (!isset($student['TotalRequired']) || !isset($student['TotalPaid'])) {
                return false;
            }
            $totalRequired = floatval($student['TotalRequired']);
            $totalPaid = floatval($student['TotalPaid']);
            $percentage = $totalRequired > 0 ? ($totalPaid / $totalRequired) * 100 : 0;
            
            if ($status === 'paid') {
                return $percentage >= 100;
            } elseif ($status === 'partial') {
                return $percentage > 0 && $percentage < 100;
            } elseif ($status === 'unpaid') {
                return $percentage == 0;
            }
            return true;
        });
        $students = array_values($students); 
    }

    foreach ($students as &$student) {
        $studentId = $student['Student_ID'];
        $stmt = $pdo->prepare("
            SELECT ph.*, i.Installment_Number,
                   a.NameEn as AdminNameEn, a.NameAr as AdminNameAr
            FROM payment_history ph
            INNER JOIN installment i ON ph.Installment_ID = i.Installment_ID
            LEFT JOIN admin a ON ph.Recorded_By = a.Admin_ID
            WHERE ph.Student_ID = ?
            ORDER BY ph.Payment_Date DESC
        ");
        $stmt->execute([$studentId]);
        $student['PaymentHistory'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($student);

    foreach ($students as &$student) {
        $totalRequired = floatval($student['TotalRequired']);
        $totalPaid = floatval($student['TotalPaid']);
        $percentage = $totalRequired > 0 ? ($totalPaid / $totalRequired) * 100 : 0;
        
        if ($percentage >= 100) {
            $student['PaymentStatus'] = 'Paid';
        } elseif ($percentage > 0) {
            $student['PaymentStatus'] = 'Partially Paid';
        } else {
            $student['PaymentStatus'] = 'Unpaid';
        }
    }
    unset($student);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="installments_report_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');

        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($output, [
            'Student Number', 'Student Name (EN)', 'Student Name (AR)',
            'Parent Name (EN)', 'Parent Name (AR)',
            'Class', 'Grade', 'Section',
            'Total Required (JOD)', 'Total Paid (JOD)', 'Remaining Amount (JOD)',
            'Last Payment Date', 'Payment Status'
        ]);

        foreach ($students as $student) {
            fputcsv($output, [
                $student['Student_Code'] ?? '',
                $student['StudentNameEn'] ?? '',
                $student['StudentNameAr'] ?? '',
                $student['ParentNameEn'] ?? 'Not Assigned',
                $student['ParentNameAr'] ?? '',
                $student['ClassName'] ?? '',
                $student['Grade_Level'] ?? '',
                $student['Section'] ?? '',
                number_format($student['TotalRequired'], 2),
                number_format($student['TotalPaid'], 2),
                number_format($student['RemainingAmount'], 2),
                $student['LastPaymentDate'] ? date('Y-m-d', strtotime($student['LastPaymentDate'])) : 'No payments',
                $student['PaymentStatus'] ?? 'Unpaid'
            ]);
        }
        
        fclose($output);
        
    } elseif ($format === 'excel') {
        
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="installments_report_' . date('Y-m-d') . '.xls"');
        
        echo '<table border="1">';
        echo '<tr>';
        echo '<th>Student Number</th><th>Student Name (EN)</th><th>Student Name (AR)</th>';
        echo '<th>Parent Name (EN)</th><th>Parent Name (AR)</th>';
        echo '<th>Class</th><th>Grade</th><th>Section</th>';
        echo '<th>Total Required (JOD)</th><th>Total Paid (JOD)</th><th>Remaining Amount (JOD)</th>';
        echo '<th>Last Payment Date</th><th>Payment Status</th>';
        echo '</tr>';
        
        foreach ($students as $student) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($student['Student_Code'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($student['StudentNameEn'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($student['StudentNameAr'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($student['ParentNameEn'] ?? 'Not Assigned') . '</td>';
            echo '<td>' . htmlspecialchars($student['ParentNameAr'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($student['ClassName'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($student['Grade_Level'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($student['Section'] ?? '') . '</td>';
            echo '<td>' . number_format($student['TotalRequired'], 2) . '</td>';
            echo '<td>' . number_format($student['TotalPaid'], 2) . '</td>';
            echo '<td>' . number_format($student['RemainingAmount'], 2) . '</td>';
            echo '<td>' . ($student['LastPaymentDate'] ? date('Y-m-d', strtotime($student['LastPaymentDate'])) : 'No payments') . '</td>';
            echo '<td>' . htmlspecialchars($student['PaymentStatus'] ?? 'Unpaid') . '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
        
    } else {
        
        header('Content-Type: text/html; charset=utf-8');

        $recentPayments = [];
        try {
            $paymentQuery = "
                SELECT ph.*, 
                       i.Installment_Number,
                       s.Student_ID, s.Student_Code, s.NameEn as StudentNameEn, s.NameAr as StudentNameAr,
                       a.NameEn as AdminNameEn, a.NameAr as AdminNameAr
                FROM payment_history ph
                INNER JOIN installment i ON ph.Installment_ID = i.Installment_ID
                INNER JOIN student s ON ph.Student_ID = s.Student_ID
                LEFT JOIN admin a ON ph.Recorded_By = a.Admin_ID
                WHERE 1=1
            ";
            
            $paymentParams = [];

            if ($startDate) {
                $paymentQuery .= " AND ph.Payment_Date >= ?";
                $paymentParams[] = $startDate . ' 00:00:00';
            }
            if ($endDate) {
                $paymentQuery .= " AND ph.Payment_Date <= ?";
                $paymentParams[] = $endDate . ' 23:59:59';
            }
            if ($studentNumber) {
                $paymentQuery .= " AND s.Student_Code = ?";
                $paymentParams[] = $studentNumber;
            }
            
            $paymentQuery .= " ORDER BY ph.Payment_Date DESC LIMIT 10";
            
            $paymentStmt = $pdo->prepare($paymentQuery);
            $paymentStmt->execute($paymentParams);
            $recentPayments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching payment history for PDF: " . $e->getMessage());
        }
        
        ?>
        <!DOCTYPE html>
        <html lang="en" dir="ltr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Financial Report - <?php echo date('Y-m-d'); ?></title>
            <style>
                @media print {
                    body { margin: 0; padding: 20px; }
                    .no-print { display: none; }
                    .page-break { page-break-after: always; }
                }
                body {
                    font-family: Arial, sans-serif;
                    padding: 20px;
                    color: #333;
                }
                .header {
                    text-align: center;
                    margin-bottom: 30px;
                    border-bottom: 3px solid #FF6B9D;
                    padding-bottom: 20px;
                }
                .header h1 {
                    color: #FF6B9D;
                    margin: 0;
                    font-size: 28px;
                }
                .header p {
                    margin: 5px 0;
                    color: #666;
                }
                .section {
                    margin-bottom: 40px;
                    page-break-inside: avoid;
                }
                .section-title {
                    background: linear-gradient(135deg, #FFE5E5, #E5F3FF);
                    padding: 15px;
                    border-radius: 10px;
                    margin-bottom: 20px;
                    font-size: 20px;
                    font-weight: bold;
                    color: #FF6B9D;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 20px;
                    font-size: 12px;
                }
                th {
                    background: #FF6B9D;
                    color: white;
                    padding: 12px 8px;
                    text-align: left;
                    font-weight: bold;
                }
                td {
                    padding: 10px 8px;
                    border-bottom: 1px solid #ddd;
                }
                tr:nth-child(even) {
                    background: #f9f9f9;
                }
                .text-right {
                    text-align: right;
                }
                .text-center {
                    text-align: center;
                }
                .summary-box {
                    background: #FFF9F5;
                    padding: 15px;
                    border-radius: 10px;
                    margin-bottom: 20px;
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 15px;
                }
                .summary-item {
                    text-align: center;
                }
                .summary-item .label {
                    font-size: 12px;
                    color: #666;
                    margin-bottom: 5px;
                }
                .summary-item .value {
                    font-size: 24px;
                    font-weight: bold;
                    color: #FF6B9D;
                }
                .filter-info {
                    background: #f0f0f0;
                    padding: 10px;
                    border-radius: 5px;
                    margin-bottom: 20px;
                    font-size: 12px;
                }
                .no-print {
                    text-align: center;
                    margin: 20px 0;
                }
                .btn-print {
                    background: #FF6B9D;
                    color: white;
                    padding: 10px 20px;
                    border: none;
                    border-radius: 5px;
                    cursor: pointer;
                    font-size: 14px;
                }
            </style>
        </head>
        <body>
            <div class="no-print">
                <button class="btn-print" onclick="window.print()">Print / Save as PDF</button>
            </div>
            
            <div class="header">
                <h1>Financial Report - Installments Management</h1>
                <p>Generated on: <?php echo date('F d, Y H:i'); ?></p>
                <?php if ($studentNumber || $startDate || $endDate): ?>
                    <div class="filter-info">
                        <strong>Filters Applied:</strong>
                        <?php if ($studentNumber): ?>
                            Student Number: <?php echo htmlspecialchars($studentNumber); ?> | 
                        <?php endif; ?>
                        <?php if ($startDate): ?>
                            From: <?php echo date('M d, Y', strtotime($startDate)); ?> | 
                        <?php endif; ?>
                        <?php if ($endDate): ?>
                            To: <?php echo date('M d, Y', strtotime($endDate)); ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php
            $totalStudents = count($students);
            $totalRequired = array_sum(array_column($students, 'TotalRequired'));
            $totalPaid = array_sum(array_column($students, 'TotalPaid'));
            $totalRemaining = array_sum(array_column($students, 'RemainingAmount'));
            ?>
            <div class="summary-box">
                <div class="summary-item">
                    <div class="label">Total Students</div>
                    <div class="value"><?php echo $totalStudents; ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Total Required (JOD)</div>
                    <div class="value"><?php echo number_format($totalRequired, 2); ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Total Paid (JOD)</div>
                    <div class="value"><?php echo number_format($totalPaid, 2); ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Total Remaining (JOD)</div>
                    <div class="value"><?php echo number_format($totalRemaining, 2); ?></div>
                </div>
            </div>

            <div class="section">
                <div class="section-title">Student Installments</div>
                <table>
                    <thead>
                        <tr>
                            <th>Student Number</th>
                            <th>Student Name</th>
                            <th>Parent Name</th>
                            <th>Class</th>
                            <th class="text-right">Total Required</th>
                            <th class="text-right">Total Paid</th>
                            <th class="text-right">Remaining</th>
                            <th>Last Payment</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="9" class="text-center">No students found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['Student_Code'] ?? ''); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($student['StudentNameEn'] ?? ''); ?>
                                        <?php if (!empty($student['StudentNameAr'])): ?>
                                            <br><small style="color: #666;"><?php echo htmlspecialchars($student['StudentNameAr']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($student['ParentNameEn'] ?? 'Not Assigned'); ?>
                                        <?php if (!empty($student['ParentNameAr'])): ?>
                                            <br><small style="color: #666;"><?php echo htmlspecialchars($student['ParentNameAr']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($student['ClassName'] ?? ''); ?>
                                        <?php if ($student['Grade_Level']): ?>
                                            <br><small>Grade <?php echo htmlspecialchars($student['Grade_Level']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right"><?php echo number_format($student['TotalRequired'], 2); ?> JOD</td>
                                    <td class="text-right" style="color: #6BCB77; font-weight: bold;"><?php echo number_format($student['TotalPaid'], 2); ?> JOD</td>
                                    <td class="text-right" style="color: <?php echo $student['RemainingAmount'] > 0 ? '#FF6B9D' : '#6BCB77'; ?>; font-weight: bold;">
                                        <?php echo number_format($student['RemainingAmount'], 2); ?> JOD
                                    </td>
                                    <td>
                                        <?php echo $student['LastPaymentDate'] ? date('M d, Y', strtotime($student['LastPaymentDate'])) : 'No payments'; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($student['PaymentStatus'] ?? 'Unpaid'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="section page-break">
                <div class="section-title">Recent Payment History (Last 10 Payments)</div>
                <table>
                    <thead>
                        <tr>
                            <th>Payment ID</th>
                            <th>Date & Time</th>
                            <th>Student</th>
                            <th>Student Number</th>
                            <th class="text-right">Amount</th>
                            <th>Installment</th>
                            <th>Method</th>
                            <th>Receipt</th>
                            <th>Recorded By</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentPayments)): ?>
                            <tr>
                                <td colspan="10" class="text-center">No payment history found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentPayments as $payment): ?>
                                <?php
                                $paymentDateTime = new DateTime($payment['Payment_Date']);
                                $methodLabels = [
                                    'cash' => 'Cash',
                                    'bank_transfer' => 'Bank Transfer',
                                    'credit_card' => 'Credit Card',
                                    'manual_entry' => 'Manual Entry'
                                ];
                                $methodLabel = $methodLabels[$payment['Payment_Method']] ?? $payment['Payment_Method'];
                                ?>
                                <tr>
                                    <td>#<?php echo $payment['Payment_ID']; ?></td>
                                    <td><?php echo $paymentDateTime->format('M d, Y H:i'); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($payment['StudentNameEn'] ?? ''); ?>
                                        <?php if (!empty($payment['StudentNameAr'])): ?>
                                            <br><small style="color: #666;"><?php echo htmlspecialchars($payment['StudentNameAr']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($payment['Student_Code'] ?? ''); ?></td>
                                    <td class="text-right" style="color: #6BCB77; font-weight: bold;">
                                        <?php echo number_format(floatval($payment['Amount']), 2); ?> JOD
                                    </td>
                                    <td><?php echo htmlspecialchars($payment['Installment_Number'] ?? '-'); ?></td>
                                    <td><?php echo $methodLabel; ?></td>
                                    <td><?php echo htmlspecialchars($payment['Receipt_Number'] ?? '-'); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($payment['AdminNameEn'] ?? $payment['AdminNameAr'] ?? 'System'); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($payment['Notes'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div style="margin-top: 40px; padding-top: 20px; border-top: 2px solid #ddd; text-align: center; color: #666; font-size: 12px;">
                <p>Report generated by HUDURY Management System</p>
                <p>This is an official financial document</p>
            </div>
            
            <script>

            </script>
        </body>
        </html>
        <?php
    }
    
} catch (Exception $e) {
    error_log("Error generating report: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error generating report: ' . $e->getMessage()
    ]);
}
?>

