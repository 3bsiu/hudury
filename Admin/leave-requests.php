<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('admin');

$leaveRequests = [];
$pendingCount = 0;

try {
    $stmt = $pdo->prepare("
        SELECT lr.*, 
               (CASE WHEN lr.Document_Data IS NOT NULL THEN 1 ELSE 0 END) as Has_Document_Data,
               s.NameEn as Student_Name, s.NameAr as Student_NameAr, s.Student_Code,
               s.Class_ID, c.Name as Class_Name, c.Grade_Level, c.Section,
               p.NameEn as Parent_Name, p.NameAr as Parent_NameAr, p.Email as Parent_Email, p.Phone as Parent_Phone,
               a.NameEn as Reviewed_By_Name
        FROM leave_request lr
        INNER JOIN student s ON lr.Student_ID = s.Student_ID
        LEFT JOIN class c ON s.Class_ID = c.Class_ID
        INNER JOIN parent p ON lr.Parent_ID = p.Parent_ID
        LEFT JOIN admin a ON lr.Reviewed_By = a.Admin_ID
        ORDER BY lr.Submitted_At DESC
    ");
    $stmt->execute();
    $leaveRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $pendingCount = count(array_filter($leaveRequests, function($r) { return strtolower($r['Status']) === 'pending'; }));
} catch (PDOException $e) {
    error_log("Error fetching leave requests: " . $e->getMessage());
    $leaveRequests = [];
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Requests - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
        .leave-request-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        .leave-request-table th {
            background: linear-gradient(135deg, #FF6B9D, #6BCB77);
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 700;
        }
        
        .leave-request-table td {
            padding: 1rem;
            border-bottom: 2px solid #FFE5E5;
        }
        
        .leave-request-table tr:hover {
            background: #FFF9F5;
        }
        
        .status-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.85rem;
            display: inline-block;
        }
        
        .status-pending {
            background: linear-gradient(135deg, #FFD93D, #FFC107);
            color: var(--text-dark);
        }
        
        .status-approved {
            background: linear-gradient(135deg, #6BCB77, #4CAF50);
            color: white;
        }
        
        .status-rejected {
            background: linear-gradient(135deg, #FF6B9D, #C44569);
            color: white;
        }
        
        .action-buttons-inline {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-small {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-approve {
            background: #6BCB77;
            color: white;
        }
        
        .btn-approve:hover {
            background: #4CAF50;
            transform: translateY(-2px);
        }
        
        .btn-reject {
            background: #FF6B9D;
            color: white;
        }
        
        .btn-reject:hover {
            background: #C44569;
            transform: translateY(-2px);
        }
        
        @media (max-width: 768px) {
            .leave-request-table {
                font-size: 0.85rem;
            }
            
            .leave-request-table th,
            .leave-request-table td {
                padding: 0.6rem 0.4rem;
                font-size: 0.8rem;
            }
            
            .action-buttons-inline {
                flex-direction: column;
            }
            
            .btn-small {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    
    <?php require_once __DIR__ . '/../includes/unified-header.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">📋</span>
                <span data-en="Leave Requests Management" data-ar="إدارة طلبات الإجازة">Leave Requests Management</span>
            </h1>
            <p class="page-subtitle" data-en="Review and manage leave requests from parents" data-ar="مراجعة وإدارة طلبات الإجازة من أولياء الأمور">Review and manage leave requests from parents</p>
        </div>

        <div class="search-filter-bar">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="leaveSearch" placeholder="Search by student name, ID, or parent..." oninput="filterRequests()">
            </div>
            <select class="filter-select" id="statusFilter" onchange="filterRequests()">
                <option value="all" data-en="All Status" data-ar="جميع الحالات">All Status</option>
                <option value="pending" data-en="Pending" data-ar="قيد المراجعة">Pending</option>
                <option value="approved" data-en="Approved" data-ar="موافق عليه">Approved</option>
                <option value="rejected" data-en="Rejected" data-ar="مرفوض">Rejected</option>
            </select>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📋</span>
                    <span data-en="Leave Requests" data-ar="طلبات الإجازة">Leave Requests</span>
                </h2>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <span class="status-badge status-pending" id="pendingCount"><?php echo $pendingCount; ?></span>
                    <span data-en="Pending" data-ar="قيد المراجعة">Pending</span>
                </div>
            </div>
            <div style="overflow-x: auto;">
                <?php if (empty($leaveRequests)): ?>
                    <div style="text-align: center; padding: 3rem; color: #999;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">📋</div>
                        <div data-en="No leave requests found" data-ar="لا توجد طلبات إجازة">No leave requests found</div>
                    </div>
                <?php else: ?>
                    <table class="leave-request-table">
                        <thead>
                            <tr>
                                <th data-en="Student" data-ar="الطالب">Student</th>
                                <th data-en="Parent" data-ar="ولي الأمر">Parent</th>
                                <th data-en="Leave Date" data-ar="تاريخ الإجازة">Leave Date</th>
                                <th data-en="Reason" data-ar="السبب">Reason</th>
                                <th data-en="Status" data-ar="الحالة">Status</th>
                                <th data-en="Submitted" data-ar="تم الإرسال">Submitted</th>
                                <th data-en="Actions" data-ar="الإجراءات">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="requestsTableBody">
                            <?php 
                            $reasonLabels = [
                                'medical' => ['en' => 'Medical', 'ar' => 'طبي'],
                                'family' => ['en' => 'Family Emergency', 'ar' => 'طوارئ عائلية'],
                                'personal' => ['en' => 'Personal', 'ar' => 'شخصي'],
                                'vacation' => ['en' => 'Vacation', 'ar' => 'عطلة'],
                                'other' => ['en' => 'Other', 'ar' => 'أخرى']
                            ];
                            $statusLabels = [
                                'pending' => ['en' => 'Pending', 'ar' => 'قيد المراجعة'],
                                'approved' => ['en' => 'Approved', 'ar' => 'موافق عليه'],
                                'rejected' => ['en' => 'Rejected', 'ar' => 'مرفوض']
                            ];
                            foreach ($leaveRequests as $request): 
                                $status = strtolower($request['Status']);
                                $reasonLabel = $reasonLabels[$request['Reason']] ?? ['en' => $request['Reason'], 'ar' => $request['Reason']];
                                $statusLabel = $statusLabels[$status] ?? ['en' => ucfirst($status), 'ar' => ucfirst($status)];
                                $leaveDate = new DateTime($request['Leave_Date']);
                                $endDate = $request['End_Date'] ? new DateTime($request['End_Date']) : null;
                                $submittedAt = new DateTime($request['Submitted_At']);
                            ?>
                                <tr data-status="<?php echo $status; ?>" data-student="<?php echo strtolower($request['Student_Name']); ?>" data-parent="<?php echo strtolower($request['Parent_Name']); ?>">
                                    <td>
                                        <div style="font-weight: 700;"><?php echo htmlspecialchars($request['Student_Name']); ?></div>
                                        <div style="font-size: 0.85rem; color: #666;"><?php echo htmlspecialchars($request['Student_Code']); ?></div>
                                        <?php if ($request['Class_Name']): ?>
                                            <div style="font-size: 0.8rem; color: #999;"><?php echo htmlspecialchars($request['Class_Name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($request['Parent_Name']); ?></div>
                                        <?php if ($request['Parent_Email']): ?>
                                            <div style="font-size: 0.85rem; color: #666;"><?php echo htmlspecialchars($request['Parent_Email']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $leaveDate->format('M d, Y'); ?>
                                        <?php if ($endDate): ?>
                                            <div style="font-size: 0.85rem; color: #666;">to <?php echo $endDate->format('M d, Y'); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span data-en="<?php echo $reasonLabel['en']; ?>" data-ar="<?php echo $reasonLabel['ar']; ?>"><?php echo $reasonLabel['en']; ?></span>
                                        <?php if ($request['Has_Document_Data'] || $request['Document_Path']): ?>
                                            <div style="margin-top: 0.3rem;"><i class="fas fa-file" style="color: #FF6B9D;"></i></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $status; ?>">
                                            <span data-en="<?php echo $statusLabel['en']; ?>" data-ar="<?php echo $statusLabel['ar']; ?>"><?php echo $statusLabel['en']; ?></span>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $submittedAt->format('M d, Y'); ?>
                                        <div style="font-size: 0.85rem; color: #666;"><?php echo $submittedAt->format('g:i A'); ?></div>
                                    </td>
                                    <td>
                                        <?php if ($status === 'pending'): ?>
                                            <div class="action-buttons-inline">
                                                <button class="btn-small btn-approve" onclick="approveRequest(<?php echo $request['Leave_Request_ID']; ?>)" data-en="Approve" data-ar="موافقة">Approve</button>
                                                <button class="btn-small btn-reject" onclick="rejectRequest(<?php echo $request['Leave_Request_ID']; ?>)" data-en="Reject" data-ar="رفض">Reject</button>
                                            </div>
                                        <?php else: ?>
                                            <button class="btn-small" onclick="viewRequestDetails(<?php echo $request['Leave_Request_ID']; ?>)" style="background: #E5F3FF; color: #2c3e50;" data-en="View" data-ar="عرض">View</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal" id="requestModal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h2 class="modal-title" data-en="Leave Request Details" data-ar="تفاصيل طلب الإجازة">Leave Request Details</h2>
                <button class="modal-close" onclick="closeModal('requestModal')">&times;</button>
            </div>
            <div id="requestDetails">
                
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        let allRequests = <?php echo json_encode($leaveRequests); ?>;
        
        function filterRequests() {
            const searchTerm = document.getElementById('leaveSearch').value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter').value;
            const rows = document.querySelectorAll('#requestsTableBody tr');
            
            rows.forEach(row => {
                const status = row.getAttribute('data-status');
                const student = row.getAttribute('data-student');
                const parent = row.getAttribute('data-parent');
                
                const matchesSearch = !searchTerm || student.includes(searchTerm) || parent.includes(searchTerm);
                const matchesStatus = statusFilter === 'all' || status === statusFilter;
                
                row.style.display = (matchesSearch && matchesStatus) ? '' : 'none';
            });

            const visiblePending = Array.from(rows).filter(row => {
                return row.style.display !== 'none' && row.getAttribute('data-status') === 'pending';
            }).length;
            document.getElementById('pendingCount').textContent = visiblePending;
        }
        
        function approveRequest(requestId) {
            if (confirm(currentLanguage === 'en' ? 'Approve this leave request?' : 'الموافقة على طلب الإجازة هذا؟')) {
                const formData = new FormData();
                formData.append('action', 'approveRequest');
                formData.append('request_id', requestId);
                
                fetch('leave-requests-ajax.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message || (currentLanguage === 'en' ? 'Request approved!' : 'تمت الموافقة!'), 'success');
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                        showNotification(data.message || (currentLanguage === 'en' ? 'Error approving request' : 'خطأ في الموافقة'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification(currentLanguage === 'en' ? 'An error occurred' : 'حدث خطأ', 'error');
                });
            }
        }
        
        function rejectRequest(requestId) {
            const reason = prompt(currentLanguage === 'en' ? 'Enter rejection reason (optional):' : 'أدخل سبب الرفض (اختياري):');
            
            const formData = new FormData();
            formData.append('action', 'rejectRequest');
            formData.append('request_id', requestId);
            if (reason) formData.append('review_notes', reason);
            
            fetch('leave-requests-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message || (currentLanguage === 'en' ? 'Request rejected' : 'تم الرفض'), 'error');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showNotification(data.message || (currentLanguage === 'en' ? 'Error rejecting request' : 'خطأ في الرفض'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(currentLanguage === 'en' ? 'An error occurred' : 'حدث خطأ', 'error');
            });
        }
        
        function viewRequestDetails(requestId) {
            const request = allRequests.find(r => r.Leave_Request_ID == requestId);
            if (!request) return;
            
            const reasonLabels = {
                'medical': {en: 'Medical Appointment / Illness', ar: 'موعد طبي / مرض'},
                'family': {en: 'Family Emergency', ar: 'طوارئ عائلية'},
                'personal': {en: 'Personal Reasons', ar: 'أسباب شخصية'},
                'vacation': {en: 'Family Vacation', ar: 'عطلة عائلية'},
                'other': {en: 'Other', ar: 'أخرى'}
            };
            
            const reasonLabel = reasonLabels[request.Reason] || {en: request.Reason, ar: request.Reason};
            const leaveDate = new Date(request.Leave_Date);
            const endDate = request.End_Date ? new Date(request.End_Date) : null;
            
            document.getElementById('requestDetails').innerHTML = `
                <div style="margin-bottom: 1.5rem;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            <div style="font-weight: 700; color: var(--primary-color); margin-bottom: 0.3rem;" data-en="Student" data-ar="الطالب">Student</div>
                            <div>${request.Student_Name} (${request.Student_Code})</div>
                            ${request.Class_Name ? `<div style="font-size: 0.85rem; color: #666;">${request.Class_Name}</div>` : ''}
                        </div>
                        <div>
                            <div style="font-weight: 700; color: var(--primary-color); margin-bottom: 0.3rem;" data-en="Parent" data-ar="ولي الأمر">Parent</div>
                            <div>${request.Parent_Name}</div>
                            ${request.Parent_Email ? `<div style="font-size: 0.85rem; color: #666;">${request.Parent_Email}</div>` : ''}
                            ${request.Parent_Phone ? `<div style="font-size: 0.85rem; color: #666;">${request.Parent_Phone}</div>` : ''}
                        </div>
                        <div>
                            <div style="font-weight: 700; color: var(--primary-color); margin-bottom: 0.3rem;" data-en="Leave Date" data-ar="تاريخ الإجازة">Leave Date</div>
                            <div>${leaveDate.toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'})}${endDate ? ' - ' + endDate.toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'}) : ''}</div>
                        </div>
                        <div>
                            <div style="font-weight: 700; color: var(--primary-color); margin-bottom: 0.3rem;" data-en="Reason" data-ar="السبب">Reason</div>
                            <div data-en="${reasonLabel.en}" data-ar="${reasonLabel.ar}">${reasonLabel.en}</div>
                        </div>
                    </div>
                    ${request.Notes ? `
                    <div style="background: #FFF9F5; padding: 1.5rem; border-radius: 15px; border-left: 4px solid #FF6B9D; margin-bottom: 1rem;">
                        <div style="font-weight: 700; margin-bottom: 0.5rem;" data-en="Notes" data-ar="ملاحظات">Notes</div>
                        <div style="line-height: 1.8; color: var(--text-dark);">${request.Notes}</div>
                    </div>
                    ` : ''}
                    ${request.Review_Notes ? `
                    <div style="background: #E5F3FF; padding: 1.5rem; border-radius: 15px; border-left: 4px solid #6BCB77; margin-bottom: 1rem;">
                        <div style="font-weight: 700; margin-bottom: 0.5rem;" data-en="Admin Notes" data-ar="ملاحظات الإدارة">Admin Notes</div>
                        <div style="line-height: 1.8; color: var(--text-dark);">${request.Review_Notes}</div>
                    </div>
                    ` : ''}
                    ${(request.Has_Document_Data || request.Document_Path) ? `
                    <div style="margin-bottom: 1rem;">
                        <a href="${request.Has_Document_Data ? `../includes/file-serve.php?type=leave_document&id=${request.Leave_Request_ID}` : `../${request.Document_Path}`}" target="_blank" style="color: #FF6B9D; text-decoration: none;">
                            <i class="fas fa-file"></i> <span data-en="View Supporting Document" data-ar="عرض المستند الداعم">View Supporting Document</span>
                        </a>
                    </div>
                    ` : ''}
                    ${request.Reviewed_By_Name ? `
                    <div style="font-size: 0.9rem; color: #666;">
                        <span data-en="Reviewed by:" data-ar="تمت المراجعة بواسطة:">Reviewed by:</span> ${request.Reviewed_By_Name}
                        ${request.Reviewed_At ? ' on ' + new Date(request.Reviewed_At).toLocaleDateString() : ''}
                    </div>
                    ` : ''}
                </div>
            `;
            openModal('requestModal');
        }
        
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 1rem 1.5rem;
                background: ${type === 'success' ? '#6BCB77' : '#FF6B9D'};
                color: white;
                border-radius: 10px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.2);
                z-index: 10000;
                animation: slideIn 0.3s;
            `;
            notification.textContent = message;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }

        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
