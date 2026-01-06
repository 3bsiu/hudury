<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';

requireUserType('admin');

$currentAdminId = getCurrentUserId();
$currentAdmin = getCurrentUserData($pdo);
$adminName = $_SESSION['user_name'] ?? 'Admin';

ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'createEvent') {
    try {
        
        $title = trim($_POST['eventTitle'] ?? '');
        $description = trim($_POST['eventDescription'] ?? '');
        $date = $_POST['eventDate'] ?? '';
        $time = $_POST['eventTime'] ?? null;
        $location = trim($_POST['eventLocation'] ?? '');
        $type = $_POST['eventType'] ?? 'other';

        $targetAudience = strtolower(trim($_POST['eventAudience'] ?? 'all'));
        $validAudiences = ['all', 'students', 'parents', 'teachers'];
        if (!in_array($targetAudience, $validAudiences)) {
            $targetAudience = 'all'; 
        }
        
        $targetClassId = !empty($_POST['targetClassId']) ? intval($_POST['targetClassId']) : null;
        $adminId = 1; 

        error_log("Creating event with Target_Audience: " . $targetAudience);

        if (empty($title) || empty($date)) {
            header("Location: school-events-management.php?error=1&message=" . urlencode('Please fill in all required fields (Title and Date).'));
            exit();
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            header("Location: school-events-management.php?error=1&message=" . urlencode('Invalid date format.'));
            exit();
        }

        $stmt = $pdo->prepare("
            INSERT INTO event (Title, Description, Date, Time, Location, Type, Target_Audience, Target_Class_ID, Admin_ID, Created_At)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $title,
            $description ?: null,
            $date,
            $time ?: null,
            $location ?: null,
            $type,
            $targetAudience,
            $targetClassId,
            $adminId
        ]);

        header("Location: school-events-management.php?success=1&message=" . urlencode('Event created successfully!'));
        exit();
        
    } catch (PDOException $e) {
        error_log("Error creating event: " . $e->getMessage());
        header("Location: school-events-management.php?error=1&message=" . urlencode('Database error: ' . $e->getMessage()));
        exit();
    } catch (Exception $e) {
        error_log("Error creating event: " . $e->getMessage());
        header("Location: school-events-management.php?error=1&message=" . urlencode('Error: ' . $e->getMessage()));
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'deleteEvent') {
    try {
        $eventId = intval($_POST['eventId'] ?? 0);
        
        if ($eventId <= 0) {
            header("Location: school-events-management.php?error=1&message=" . urlencode('Invalid event ID.'));
            exit();
        }
        
        $stmt = $pdo->prepare("DELETE FROM event WHERE Event_ID = ?");
        $stmt->execute([$eventId]);
        
        header("Location: school-events-management.php?success=1&message=" . urlencode('Event deleted successfully!'));
        exit();
        
    } catch (PDOException $e) {
        error_log("Error deleting event: " . $e->getMessage());
        header("Location: school-events-management.php?error=1&message=" . urlencode('Database error: ' . $e->getMessage()));
        exit();
    }
}

$events = [];
try {
    $stmt = $pdo->prepare("
        SELECT Event_ID, Title, Description, Date, Time, Location, Type, Target_Audience, Target_Class_ID, Created_At
        FROM event
        ORDER BY Date DESC, Time DESC
    ");
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching events: " . $e->getMessage());
    $events = [];
}

$classes = [];
try {
    $stmt = $pdo->prepare("
        SELECT Class_ID, Name, Grade_Level, Section
        FROM class
        ORDER BY Grade_Level, Section
    ");
    $stmt->execute();
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching classes: " . $e->getMessage());
    $classes = [];
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Events Management - HUDURY</title>
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
        
        <div class="page-header">
            <h1 class="page-title">
                <span class="page-icon">📅</span>
                <span data-en="School Events Management" data-ar="إدارة أحداث المدرسة">School Events Management</span>
            </h1>
            <p class="page-subtitle" data-en="Create and manage school events and activities" data-ar="إنشاء وإدارة أحداث وأنشطة المدرسة">Create and manage school events and activities</p>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">➕</span>
                    <span data-en="Create New Event" data-ar="إنشاء حدث جديد">Create New Event</span>
                </h2>
            </div>
            <form method="POST" action="school-events-management.php" onsubmit="return validateEventForm(event)">
                <input type="hidden" name="action" value="createEvent">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
                    <div class="form-group">
                        <label data-en="Event Title" data-ar="عنوان الحدث">Event Title <span style="color: red;">*</span></label>
                        <input type="text" id="eventTitle" name="eventTitle" required>
                    </div>
                    <div class="form-group">
                        <label data-en="Event Type" data-ar="نوع الحدث">Event Type <span style="color: red;">*</span></label>
                        <select id="eventType" name="eventType" required>
                            <option value="">Select Type</option>
                            <option value="academic" data-en="Academic" data-ar="أكاديمي">Academic</option>
                            <option value="sports" data-en="Sports" data-ar="رياضي">Sports</option>
                            <option value="cultural" data-en="Cultural" data-ar="ثقافي">Cultural</option>
                            <option value="meeting" data-en="Meeting" data-ar="اجتماع">Meeting</option>
                            <option value="other" data-en="Other" data-ar="أخرى">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label data-en="Event Date" data-ar="تاريخ الحدث">Event Date <span style="color: red;">*</span></label>
                        <input type="date" id="eventDate" name="eventDate" required>
                    </div>
                    <div class="form-group">
                        <label data-en="Event Time" data-ar="وقت الحدث">Event Time</label>
                        <input type="time" id="eventTime" name="eventTime">
                    </div>
                    <div class="form-group">
                        <label data-en="Location" data-ar="الموقع">Location</label>
                        <input type="text" id="eventLocation" name="eventLocation">
                    </div>
                    <div class="form-group">
                        <label data-en="Target Audience" data-ar="الجمهور المستهدف">Target Audience <span style="color: red;">*</span></label>
                        <select id="eventAudience" name="eventAudience" required>
                            <option value="all" data-en="All" data-ar="الكل">All</option>
                            <option value="students" data-en="Students Only" data-ar="الطلاب فقط">Students Only</option>
                            <option value="parents" data-en="Parents Only" data-ar="أولياء الأمور فقط">Parents Only</option>
                            <option value="teachers" data-en="Teachers Only" data-ar="المعلمون فقط">Teachers Only</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label data-en="Target Class (Optional)" data-ar="الفصل المستهدف (اختياري)">Target Class (Optional)</label>
                        <select id="targetClassId" name="targetClassId">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['Class_ID']; ?>">
                                    <?php echo htmlspecialchars($class['Name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label data-en="Description" data-ar="الوصف">Description</label>
                    <textarea id="eventDescription" name="eventDescription" rows="4"></textarea>
                </div>
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" data-en="Create Event" data-ar="إنشاء الحدث">Create Event</button>
                    <button type="button" class="btn btn-secondary" onclick="document.querySelector('form').reset()" data-en="Clear" data-ar="مسح">Clear</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📋</span>
                    <span data-en="Upcoming Events" data-ar="الأحداث القادمة">Upcoming Events</span>
                </h2>
                <div class="search-filter-bar" style="margin: 0;">
                    <div class="search-box" style="margin: 0;">
                        <i class="fas fa-search"></i>
                        <input type="text" id="eventSearch" placeholder="Search events..." oninput="filterEvents()">
                    </div>
                    <select class="filter-select" id="eventFilter" onchange="filterEvents()">
                        <option value="all" data-en="All Events" data-ar="جميع الأحداث">All Events</option>
                        <option value="upcoming" data-en="Upcoming" data-ar="قادمة">Upcoming</option>
                        <option value="past" data-en="Past" data-ar="سابقة">Past</option>
                    </select>
                </div>
            </div>
            <div id="eventsList" class="user-list">
                
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        
        const allEvents = <?php echo json_encode($events, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        let currentEvents = allEvents;

        console.log('Events loaded from database:', allEvents);

        function loadEvents() {
            currentEvents = allEvents;
            renderEvents(allEvents);
        }

        function renderEvents(events) {
            const container = document.getElementById('eventsList');
            
            if (!events || events.length === 0) {
                container.innerHTML = '<div style="text-align: center; padding: 2rem; color: #666;">' + 
                    (currentLanguage === 'en' ? 'No events found' : 'لا توجد أحداث') + '</div>';
                return;
            }
            
            container.innerHTML = events.map(event => {
                const eventDate = new Date(event.Date);
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const isUpcoming = eventDate >= today;
                const dateStr = formatDate(event.Date);
                const timeStr = event.Time ? formatTime(event.Time) : '';
                const locationStr = event.Location || 'Not specified';
                const typeLabels = {
                    'academic': currentLanguage === 'en' ? 'Academic' : 'أكاديمي',
                    'sports': currentLanguage === 'en' ? 'Sports' : 'رياضي',
                    'cultural': currentLanguage === 'en' ? 'Cultural' : 'ثقافي',
                    'meeting': currentLanguage === 'en' ? 'Meeting' : 'اجتماع',
                    'other': currentLanguage === 'en' ? 'Other' : 'أخرى'
                };
                const audienceLabels = {
                    'all': currentLanguage === 'en' ? 'All' : 'الكل',
                    'students': currentLanguage === 'en' ? 'Students' : 'الطلاب',
                    'parents': currentLanguage === 'en' ? 'Parents' : 'أولياء الأمور',
                    'teachers': currentLanguage === 'en' ? 'Teachers' : 'المعلمون'
                };
                
                return `
                    <div class="user-item">
                        <div class="user-info-item" style="flex: 1;">
                            <div class="user-avatar-item">📅</div>
                            <div>
                                <div style="font-weight: 700;">${escapeHtml(event.Title)}</div>
                                <div style="font-size: 0.9rem; color: #666;">
                                    ${typeLabels[event.Type] || event.Type} • ${dateStr}${timeStr ? ' at ' + timeStr : ''} • ${escapeHtml(locationStr)}
                                </div>
                                <div style="font-size: 0.85rem; color: #999; margin-top: 0.3rem;">
                                    ${escapeHtml(event.Description || 'No description')}
                                </div>
                                <div style="font-size: 0.8rem; color: #666; margin-top: 0.3rem;">
                                    <span data-en="Target Audience" data-ar="الجمهور المستهدف">Target Audience:</span> ${audienceLabels[event.Target_Audience] || event.Target_Audience}
                                </div>
                            </div>
                        </div>
                        <div style="display: flex; gap: 0.5rem;">
                            <button class="btn btn-secondary btn-small" onclick="editEvent(${event.Event_ID})" data-en="Edit" data-ar="تعديل">Edit</button>
                            <button class="btn btn-danger btn-small" onclick="deleteEvent(${event.Event_ID})" data-en="Delete" data-ar="حذف">Delete</button>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function validateEventForm(event) {
            const title = document.getElementById('eventTitle').value.trim();
            const date = document.getElementById('eventDate').value;
            const type = document.getElementById('eventType').value;
            const audience = document.getElementById('eventAudience').value;
            
            if (!title) {
                alert(currentLanguage === 'en' ? 'Please enter event title.' : 'يرجى إدخال عنوان الحدث.');
                event.preventDefault();
                return false;
            }
            
            if (!date) {
                alert(currentLanguage === 'en' ? 'Please select event date.' : 'يرجى اختيار تاريخ الحدث.');
                event.preventDefault();
                return false;
            }
            
            if (!type) {
                alert(currentLanguage === 'en' ? 'Please select event type.' : 'يرجى اختيار نوع الحدث.');
                event.preventDefault();
                return false;
            }
            
            if (!audience) {
                alert(currentLanguage === 'en' ? 'Please select target audience.' : 'يرجى اختيار الجمهور المستهدف.');
                event.preventDefault();
                return false;
            }
            
            return true;
        }

        function editEvent(eventId) {
            const event = allEvents.find(e => e.Event_ID == eventId);
            if (!event) {
                alert(currentLanguage === 'en' ? 'Event not found.' : 'الحدث غير موجود.');
                return;
            }
            
            document.getElementById('eventTitle').value = event.Title || '';
            document.getElementById('eventType').value = event.Type || '';
            document.getElementById('eventDate').value = event.Date || '';
            document.getElementById('eventTime').value = event.Time || '';
            document.getElementById('eventLocation').value = event.Location || '';
            document.getElementById('eventAudience').value = event.Target_Audience || 'all';
            document.getElementById('targetClassId').value = event.Target_Class_ID || '';
            document.getElementById('eventDescription').value = event.Description || '';
            
            document.querySelector('form').scrollIntoView({ behavior: 'smooth' });
        }

        function deleteEvent(eventId) {
            if (confirm(currentLanguage === 'en' ? 'Are you sure you want to delete this event?' : 'هل أنت متأكد من حذف هذا الحدث؟')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'school-events-management.php';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'deleteEvent';
                form.appendChild(actionInput);
                
                const eventIdInput = document.createElement('input');
                eventIdInput.type = 'hidden';
                eventIdInput.name = 'eventId';
                eventIdInput.value = eventId;
                form.appendChild(eventIdInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        function filterEvents() {
            const searchTerm = document.getElementById('eventSearch').value.toLowerCase();
            const filter = document.getElementById('eventFilter').value;
            
            let filtered = allEvents.filter(event => {
                const matchesSearch = (event.Title && event.Title.toLowerCase().includes(searchTerm)) || 
                                    (event.Description && event.Description.toLowerCase().includes(searchTerm));
                const eventDate = new Date(event.Date);
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const isUpcoming = eventDate >= today;
                const matchesFilter = filter === 'all' || 
                                    (filter === 'upcoming' && isUpcoming) ||
                                    (filter === 'past' && !isUpcoming);
                return matchesSearch && matchesFilter;
            });
            
            renderEvents(filtered);
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatTime(timeStr) {
            if (!timeStr) return '';
            
            const parts = timeStr.split(':');
            if (parts.length >= 2) {
                return parts[0] + ':' + parts[1];
            }
            return timeStr;
        }

        loadEvents();
    </script>
</body>
</html>

