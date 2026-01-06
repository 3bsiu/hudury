<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notifications-student.php';

requireUserType('student');

$currentStudentId = getCurrentUserId();
$currentStudent = getCurrentUserData($pdo);
$studentName = $_SESSION['user_name'] ?? 'Student';

$attendanceRecords = [];
$attendanceStats = [
    'total_present' => 0,
    'total_absent' => 0,
    'total_late' => 0,
    'total_excused' => 0,
    'attendance_rate' => 0
];

if ($currentStudentId) {
    try {
        
        $stmt = $pdo->prepare("
            SELECT 
                a.Attendance_ID,
                a.Date,
                a.Status,
                a.Notes,
                a.Excuse_Submitted,
                a.Excuse_Approved,
                a.Excuse_File_Path,
                a.Created_At
            FROM attendance a
            WHERE a.Student_ID = ?
            ORDER BY a.Date DESC
        ");
        $stmt->execute([$currentStudentId]);
        $attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalRecords = count($attendanceRecords);
        foreach ($attendanceRecords as $record) {
            $status = strtolower($record['Status']);
            switch ($status) {
                case 'present':
                    $attendanceStats['total_present']++;
                    break;
                case 'absent':
                    $attendanceStats['total_absent']++;
                    break;
                case 'late':
                    $attendanceStats['total_late']++;
                    break;
                case 'excused':
                    $attendanceStats['total_excused']++;
                    break;
            }
        }

        if ($totalRecords > 0) {
            $attended = $attendanceStats['total_present'] + $attendanceStats['total_excused'];
            $attendanceStats['attendance_rate'] = round(($attended / $totalRecords) * 100, 1);
        }
    } catch (PDOException $e) {
        error_log("Error fetching attendance records: " . $e->getMessage());
    }
}

$attendanceByMonth = [];
foreach ($attendanceRecords as $record) {
    $date = new DateTime($record['Date']);
    $monthKey = $date->format('Y-m');
    $monthName = $date->format('F Y');
    
    if (!isset($attendanceByMonth[$monthKey])) {
        $attendanceByMonth[$monthKey] = [
            'name' => $monthName,
            'key' => strtolower($date->format('F')),
            'records' => [],
            'stats' => ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0]
        ];
    }
    
    $attendanceByMonth[$monthKey]['records'][] = $record;
    $status = strtolower($record['Status']);
    if (isset($attendanceByMonth[$monthKey]['stats'][$status])) {
        $attendanceByMonth[$monthKey]['stats'][$status]++;
    }
}

krsort($attendanceByMonth);

$currentMonthStats = [
    'present' => 0,
    'absent' => 0,
    'late' => 0,
    'excused' => 0
];
if (!empty($attendanceByMonth)) {
    $firstMonth = reset($attendanceByMonth);
    $currentMonthStats = $firstMonth['stats'];
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Record - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
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
            margin-bottom: 1rem;
        }
        .back-button:hover {
            background: rgba(255,255,255,0.3);
            transform: translateX(-5px);
        }
        .attendance-calendar-view {
            margin-top: 2rem;
        }
        .attendance-month-section {
            margin-bottom: 3rem;
        }
        .month-header {
            margin-bottom: 1.5rem;
        }
        .attendance-month-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .month-stats {
            display: flex;
            gap: 1.5rem;
            font-size: 1rem;
        }
        .stat-present {
            color: #6BCB77;
            font-weight: 600;
        }
        .stat-absent {
            color: #FF6B6B;
            font-weight: 600;
        }
        .stat-late {
            color: #FFD93D;
            font-weight: 600;
        }
        .attendance-days-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
        }
        .attendance-day-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            transition: all 0.3s;
            border-left: 5px solid #ddd;
        }
        .attendance-day-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        .attendance-day-card.status-present {
            border-left-color: #6BCB77;
        }
        .attendance-day-card.status-absent {
            border-left-color: #FF6B6B;
        }
        .attendance-day-card.status-late {
            border-left-color: #FFD93D;
        }
        .day-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .day-name {
            font-weight: 700;
            color: var(--text-dark);
        }
        .day-date {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary-color);
        }
        .day-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        .status-icon {
            font-size: 1.2rem;
        }
        .status-text {
            font-weight: 600;
            color: var(--text-dark);
        }
        .day-note {
            margin-top: 0.5rem;
            font-size: 0.85rem;
            color: #666;
            padding-top: 0.5rem;
            border-top: 1px solid #FFE5E5;
        }
        .attendance-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .summary-stat-card {
            background: white;
            padding: 2rem;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        .summary-stat-card.present {
            border-top: 5px solid #6BCB77;
        }
        .summary-stat-card.absent {
            border-top: 5px solid #FF6B6B;
        }
        .summary-stat-card.late {
            border-top: 5px solid #FFD93D;
        }
        .stat-value {
            font-size: 3rem;
            font-weight: 800;
            margin: 0.5rem 0;
        }
        .stat-value.present {
            color: #6BCB77;
        }
        .stat-value.absent {
            color: #FF6B6B;
        }
        .stat-value.late {
            color: #FFD93D;
        }
        .stat-label {
            font-size: 1.1rem;
            color: #666;
            font-weight: 600;
        }
    </style>
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>
    
    <div class="main-content">

    <div class="dashboard-container">
        
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">📅</span>
                <span data-en="Attendance Record" data-ar="سجل الحضور">Attendance Record</span>
            </h1>
            <p class="page-subtitle" data-en="View your daily attendance and absence records" data-ar="عرض سجل حضورك اليومي والغياب">View your daily attendance and absence records</p>
        </div>

        <div class="attendance-summary">
            <div class="summary-stat-card present">
                <div class="stat-label" data-en="Present Days" data-ar="أيام الحضور">Present Days</div>
                <div class="stat-value present"><?php echo $currentMonthStats['present']; ?></div>
                <div class="stat-label" data-en="This Month" data-ar="هذا الشهر">This Month</div>
            </div>
            <div class="summary-stat-card absent">
                <div class="stat-label" data-en="Absent Days" data-ar="أيام الغياب">Absent Days</div>
                <div class="stat-value absent"><?php echo $currentMonthStats['absent']; ?></div>
                <div class="stat-label" data-en="This Month" data-ar="هذا الشهر">This Month</div>
            </div>
            <div class="summary-stat-card late">
                <div class="stat-label" data-en="Late Arrivals" data-ar="التأخيرات">Late Arrivals</div>
                <div class="stat-value late"><?php echo $currentMonthStats['late']; ?></div>
                <div class="stat-label" data-en="This Month" data-ar="هذا الشهر">This Month</div>
            </div>
            <div class="summary-stat-card">
                <div class="stat-label" data-en="Attendance Rate" data-ar="معدل الحضور">Attendance Rate</div>
                <div class="stat-value" style="color: #6BCB77;"><?php echo $attendanceStats['attendance_rate']; ?>%</div>
                <div class="stat-label" data-en="Overall" data-ar="الإجمالي">Overall</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📅</span>
                    <span data-en="Monthly Attendance" data-ar="الحضور الشهري">Monthly Attendance</span>
                </h2>
                <?php if (!empty($attendanceByMonth)): ?>
                <select class="filter-select" id="monthFilter" onchange="filterAttendanceByMonth()" style="padding: 0.5rem 1rem; border: 2px solid #FFE5E5; border-radius: 10px;">
                    <?php 
                    $firstMonth = true;
                    foreach ($attendanceByMonth as $monthKey => $monthData): 
                    ?>
                        <option value="<?php echo $monthData['key']; ?>" <?php echo $firstMonth ? 'selected' : ''; ?> data-en="<?php echo $monthData['name']; ?>" data-ar="<?php echo $monthData['name']; ?>"><?php echo $monthData['name']; ?></option>
                    <?php 
                        $firstMonth = false;
                    endforeach; 
                    ?>
                </select>
                <?php endif; ?>
            </div>
            
            <div class="attendance-calendar-view">
                <?php if (empty($attendanceByMonth)): ?>
                    <div style="text-align: center; padding: 3rem; color: #666;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">📅</div>
                        <div data-en="No attendance records found" data-ar="لا توجد سجلات حضور">No attendance records found</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($attendanceByMonth as $monthKey => $monthData): ?>
                        <div class="attendance-month-section" data-month="<?php echo $monthData['key']; ?>" style="<?php echo $monthData === reset($attendanceByMonth) ? '' : 'display: none;'; ?>">
                            <div class="month-header">
                                <h3 class="attendance-month-title">
                                    <span data-en="<?php echo $monthData['name']; ?>" data-ar="<?php echo $monthData['name']; ?>"><?php echo $monthData['name']; ?></span>
                                    <span class="month-stats">
                                        <span class="stat-present" data-en="<?php echo $monthData['stats']['present']; ?> Present" data-ar="<?php echo $monthData['stats']['present']; ?> حاضر"><?php echo $monthData['stats']['present']; ?> Present</span>
                                        <span class="stat-absent" data-en="<?php echo $monthData['stats']['absent']; ?> Absent" data-ar="<?php echo $monthData['stats']['absent']; ?> غائب"><?php echo $monthData['stats']['absent']; ?> Absent</span>
                                        <?php if ($monthData['stats']['late'] > 0): ?>
                                        <span class="stat-late" data-en="<?php echo $monthData['stats']['late']; ?> Late" data-ar="<?php echo $monthData['stats']['late']; ?> متأخر"><?php echo $monthData['stats']['late']; ?> Late</span>
                                        <?php endif; ?>
                                    </span>
                                </h3>
                            </div>
                            <div class="attendance-days-grid">
                                <?php 
                                
                                usort($monthData['records'], function($a, $b) {
                                    return strtotime($b['Date']) - strtotime($a['Date']);
                                });
                                
                                foreach ($monthData['records'] as $record): 
                                    $date = new DateTime($record['Date']);
                                    $dayName = $date->format('l');
                                    $dayNumber = $date->format('j');
                                    $status = strtolower($record['Status']);

                                    $statusClass = 'status-' . $status;
                                    $statusIcon = '✅';
                                    $statusText = 'Present';
                                    $statusTextAr = 'حاضر';
                                    
                                    if ($status === 'absent') {
                                        $statusIcon = '❌';
                                        $statusText = 'Absent';
                                        $statusTextAr = 'غائب';
                                    } elseif ($status === 'late') {
                                        $statusIcon = '⏰';
                                        $statusText = 'Late';
                                        $statusTextAr = 'متأخر';
                                    } elseif ($status === 'excused') {
                                        $statusIcon = '✅';
                                        $statusText = 'Excused';
                                        $statusTextAr = 'معذور';
                                    }

                                    $dayNames = [
                                        'Monday' => ['en' => 'Monday', 'ar' => 'الإثنين'],
                                        'Tuesday' => ['en' => 'Tuesday', 'ar' => 'الثلاثاء'],
                                        'Wednesday' => ['en' => 'Wednesday', 'ar' => 'الأربعاء'],
                                        'Thursday' => ['en' => 'Thursday', 'ar' => 'الخميس'],
                                        'Friday' => ['en' => 'Friday', 'ar' => 'الجمعة'],
                                        'Saturday' => ['en' => 'Saturday', 'ar' => 'السبت'],
                                        'Sunday' => ['en' => 'Sunday', 'ar' => 'الأحد']
                                    ];
                                    $dayNameTrans = $dayNames[$dayName] ?? ['en' => $dayName, 'ar' => $dayName];

                                    $timeNote = '';
                                    if ($record['Created_At']) {
                                        $createdAt = new DateTime($record['Created_At']);
                                        $timeNote = $createdAt->format('g:i A');
                                    }
                                ?>
                                    <div class="attendance-day-card <?php echo $statusClass; ?>" data-date="<?php echo $record['Date']; ?>" data-day="<?php echo $dayName; ?>">
                                        <div class="day-header">
                                            <div class="day-name" data-en="<?php echo $dayNameTrans['en']; ?>" data-ar="<?php echo $dayNameTrans['ar']; ?>"><?php echo $dayNameTrans['en']; ?></div>
                                            <div class="day-date"><?php echo $dayNumber; ?></div>
                                        </div>
                                        <div class="day-status">
                                            <span class="status-icon"><?php echo $statusIcon; ?></span>
                                            <span class="status-text" data-en="<?php echo $statusText; ?>" data-ar="<?php echo $statusTextAr; ?>"><?php echo $statusText; ?></span>
                                        </div>
                                        <?php if ($record['Notes'] || $timeNote): ?>
                                        <div class="day-note">
                                            <?php if ($timeNote && $status === 'late'): ?>
                                                <span data-en="Arrived at <?php echo $timeNote; ?>" data-ar="وصل في <?php echo $timeNote; ?>">Arrived at <?php echo htmlspecialchars($timeNote); ?></span>
                                            <?php elseif ($record['Notes']): ?>
                                                <?php echo htmlspecialchars($record['Notes']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        function filterAttendanceByMonth() {
            const selectedMonth = document.getElementById('monthFilter').value;
            const sections = document.querySelectorAll('.attendance-month-section');
            
            sections.forEach(section => {
                if (section.dataset.month === selectedMonth) {
                    section.style.display = 'block';
                } else {
                    section.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>

