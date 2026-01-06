<?php

if (!function_exists('getTimeAgo')) {
    function getTimeAgo($datetime) {
        $now = new DateTime();
        $diff = $now->diff($datetime);
        
        if ($diff->y > 0) {
            return $diff->y . ' ' . ($diff->y == 1 ? 'year' : 'years') . ' ago';
        } elseif ($diff->m > 0) {
            return $diff->m . ' ' . ($diff->m == 1 ? 'month' : 'months') . ' ago';
        } elseif ($diff->d > 0) {
            return $diff->d . ' ' . ($diff->d == 1 ? 'day' : 'days') . ' ago';
        } elseif ($diff->h > 0) {
            return $diff->h . ' ' . ($diff->h == 1 ? 'hour' : 'hours') . ' ago';
        } elseif ($diff->i > 0) {
            return $diff->i . ' ' . ($diff->i == 1 ? 'minute' : 'minutes') . ' ago';
        } else {
            return 'Just now';
        }
    }
}

$currentParentId = isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'parent' ? intval($_SESSION['user_id']) : null;
$linkedStudentIds = [];

if ($currentParentId) {
    try {
        $stmt = $pdo->prepare("SELECT Student_ID FROM parent_student_relationship WHERE Parent_ID = ?");
        $stmt->execute([$currentParentId]);
        $linkedStudentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $linkedStudentIds = array_map('intval', $linkedStudentIds);

        if (count($linkedStudentIds) > 5) {
            error_log("WARNING: Parent ID $currentParentId has " . count($linkedStudentIds) . " linked students. This might indicate a data issue.");
        }

        if (count($linkedStudentIds) > 0) {
            error_log("DEBUG: Parent ID $currentParentId has linked students: " . implode(', ', $linkedStudentIds));
        } else {
            error_log("WARNING: Parent ID $currentParentId has NO linked students - they will not see any student-specific notifications");
        }
    } catch (PDOException $e) {
        error_log("Error fetching linked students: " . $e->getMessage());
        $linkedStudentIds = []; 
    }
}

$notifications = [];
try {
    if ($currentParentId) {
        
        $linkedStudentClassIds = [];
        if (!empty($linkedStudentIds)) {
            $placeholders = implode(',', array_fill(0, count($linkedStudentIds), '?'));
            $stmt = $pdo->prepare("
                SELECT DISTINCT Class_ID 
                FROM student 
                WHERE Student_ID IN ($placeholders) AND Class_ID IS NOT NULL
            ");
            $stmt->execute($linkedStudentIds);
            $linkedStudentClassIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $conditions = [];
        $params = [];

        $conditions[] = "Target_Role = 'All'";

        if (!empty($linkedStudentIds)) {
            $studentPlaceholders = implode(',', array_fill(0, count($linkedStudentIds), '?'));

            $conditions[] = "(Target_Role = 'Parent' AND Target_Student_ID IS NOT NULL AND Target_Student_ID != 0 AND CAST(Target_Student_ID AS UNSIGNED) IN ($studentPlaceholders))";
            $params = array_merge($params, $linkedStudentIds);
        } else {

            error_log("SECURITY: Parent ID $currentParentId has NO linked students - will NOT show any student-specific Parent notifications");
        }

        if (!empty($linkedStudentClassIds)) {
            $classPlaceholders = implode(',', array_fill(0, count($linkedStudentClassIds), '?'));
            $conditions[] = "(Target_Role = 'Parent' AND Target_Class_ID IN ($classPlaceholders) AND (Target_Student_ID IS NULL OR Target_Student_ID = 0))";
            $params = array_merge($params, $linkedStudentClassIds);
        }

        if (!empty($linkedStudentIds)) {
            $studentPlaceholders = implode(',', array_fill(0, count($linkedStudentIds), '?'));
            $conditions[] = "(Target_Role = 'Student' AND Target_Student_ID IS NOT NULL AND Target_Student_ID IN ($studentPlaceholders))";
            $params = array_merge($params, $linkedStudentIds);
        }
        
        if (count($conditions) > 0) {

            $studentIdCheck = '';
            if (!empty($linkedStudentIds)) {
                $studentIdPlaceholders = implode(',', array_fill(0, count($linkedStudentIds), '?'));
                
                $studentIdCheck = "AND (
                    n.Target_Role != 'Parent' 
                    OR n.Target_Student_ID IS NULL 
                    OR n.Target_Student_ID = 0 
                    OR CAST(n.Target_Student_ID AS UNSIGNED) IN ($studentIdPlaceholders)
                )";
            } else {
                
                $studentIdCheck = "AND (
                    n.Target_Role != 'Parent' 
                    OR n.Target_Student_ID IS NULL 
                    OR n.Target_Student_ID = 0
                )";
            }
            
            $query = "
                SELECT DISTINCT n.Notif_ID, n.Title, n.Content, n.Date_Sent, n.Sender_Type, n.Target_Role, n.Target_Class_ID, n.Target_Student_ID
                FROM notification n
                WHERE (" . implode(' OR ', $conditions) . ")
                $studentIdCheck
                AND NOT (
                    n.Target_Role = 'Student' 
                    AND n.Target_Class_ID IS NOT NULL
                    AND EXISTS (
                        SELECT 1 
                        FROM notification pn 
                        WHERE pn.Target_Role = 'Parent' 
                        AND pn.Target_Class_ID = n.Target_Class_ID
                        AND pn.Title = n.Title
                        AND pn.Sender_Type = n.Sender_Type
                        AND pn.Sender_ID = n.Sender_ID
                        AND ABS(TIMESTAMPDIFF(SECOND, pn.Date_Sent, n.Date_Sent)) <= 60
                    )
                )
                ORDER BY n.Date_Sent DESC
                LIMIT 20
            ";

            $stmt = $pdo->prepare($query);

            $finalParams = $params;
            if (!empty($linkedStudentIds) && strpos($studentIdCheck, '?') !== false) {
                
                $finalParams = array_merge($params, $linkedStudentIds);
            }
            
            error_log("DEBUG: Executing query with " . count($finalParams) . " parameters. Linked students: [" . implode(',', $linkedStudentIds) . "]");
            $stmt->execute($finalParams);
            $rawNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

            error_log("DEBUG: Parent ID $currentParentId query returned " . count($rawNotifications) . " notifications. Linked students: [" . implode(',', $linkedStudentIds) . "]");
            foreach ($rawNotifications as $idx => $notif) {
                $notifStudentId = $notif['Target_Student_ID'] ?? 'NULL';
                error_log("DEBUG: Raw notification #" . ($idx + 1) . " - ID: {$notif['Notif_ID']}, Role: {$notif['Target_Role']}, Student ID: $notifStudentId, Title: {$notif['Title']}");
            }

            $validNotifications = [];
            foreach ($rawNotifications as $notif) {
                $isValid = false;
                
                $notifStudentId = null;
                if ($notif['Target_Student_ID'] !== null && $notif['Target_Student_ID'] !== '' && $notif['Target_Student_ID'] !== '0' && $notif['Target_Student_ID'] !== 0) {
                    $notifStudentId = intval($notif['Target_Student_ID']);
                    
                    if ($notifStudentId <= 0) {
                        $notifStudentId = null;
                    }
                }
                $notifClassId = $notif['Target_Class_ID'] !== null ? intval($notif['Target_Class_ID']) : null;

                if ($notif['Target_Role'] === 'All') {
                    $isValid = true; 
                } elseif ($notif['Target_Role'] === 'Parent' && $notifStudentId !== null && $notifStudentId > 0) {

                    $isValid = in_array($notifStudentId, $linkedStudentIds, true); 
                    
                    if ($isValid) {
                        error_log("DEBUG: Parent ID $currentParentId CAN see notification {$notif['Notif_ID']} for student $notifStudentId - student is linked. Linked students: [" . implode(',', $linkedStudentIds) . "]");
                    } else {
                        
                        error_log("CRITICAL SECURITY VIOLATION: Parent ID $currentParentId attempted to see notification {$notif['Notif_ID']} for student $notifStudentId - NOT their child! Linked students: [" . implode(',', $linkedStudentIds) . "]. Notification Title: {$notif['Title']}. Content: " . substr($notif['Content'], 0, 100));
                        $isValid = false; 
                    }
                } elseif ($notif['Target_Role'] === 'Parent' && ($notifStudentId === null || $notifStudentId === 0)) {

                    $isValid = $notifClassId !== null && in_array($notifClassId, $linkedStudentClassIds, true); 
                    if (!$isValid && $notifClassId !== null) {
                        error_log("SECURITY BLOCK: Parent ID $currentParentId cannot see class-wide notification {$notif['Notif_ID']} for class $notifClassId - not their children's class. Their classes: [" . implode(',', $linkedStudentClassIds) . "]");
                    }
                } elseif ($notif['Target_Role'] === 'Student' && $notifStudentId !== null && $notifStudentId > 0) {
                    
                    $isValid = in_array($notifStudentId, $linkedStudentIds, true); 
                    if (!$isValid) {
                        error_log("SECURITY BLOCK: Parent ID $currentParentId cannot see student notification {$notif['Notif_ID']} for student $notifStudentId - not their child. Linked students: [" . implode(',', $linkedStudentIds) . "]");
                    }
                } else {
                    
                    $isValid = false;
                    error_log("SECURITY BLOCK: Invalid notification format for parent $currentParentId - Notification ID: {$notif['Notif_ID']}, Role: {$notif['Target_Role']}, Student ID: " . ($notifStudentId ?? 'NULL') . ", Class ID: " . ($notifClassId ?? 'NULL'));
                }

                if ($isValid === true) {
                    $validNotifications[] = $notif;
                } else {
                    
                    error_log("SECURITY BLOCKED: Notification ID {$notif['Notif_ID']} for parent $currentParentId - Role: {$notif['Target_Role']}, Student ID: " . ($notifStudentId ?? 'NULL') . ", Class ID: " . ($notifClassId ?? 'NULL') . ", Title: {$notif['Title']}, Content: " . substr($notif['Content'], 0, 150));
                }
            }

            error_log("DEBUG: Parent ID $currentParentId will see " . count($validNotifications) . " valid notifications out of " . count($rawNotifications) . " total");

            $finalValidNotifications = [];
            foreach ($validNotifications as $notif) {
                $notifStudentId = null;
                if ($notif['Target_Student_ID'] !== null && $notif['Target_Student_ID'] !== '' && $notif['Target_Student_ID'] !== '0' && $notif['Target_Student_ID'] !== 0) {
                    $notifStudentId = intval($notif['Target_Student_ID']);
                    if ($notifStudentId <= 0) {
                        $notifStudentId = null;
                    }
                }

                if ($notif['Target_Role'] === 'Parent' && $notifStudentId !== null && $notifStudentId > 0) {
                    if (!in_array($notifStudentId, $linkedStudentIds, true)) {
                        
                        error_log("CRITICAL ERROR: Final validation failed for notification {$notif['Notif_ID']} - student $notifStudentId not in linked list [" . implode(',', $linkedStudentIds) . "]");
                        continue; 
                    }
                }
                
                $finalValidNotifications[] = $notif;
            }

            $notifications = $finalValidNotifications;

            if (count($finalValidNotifications) !== count($validNotifications)) {
                error_log("CRITICAL: Final validation removed " . (count($validNotifications) - count($finalValidNotifications)) . " unauthorized notifications for parent $currentParentId");
            }

            $absolutelyValidNotifications = [];
            foreach ($finalValidNotifications as $notif) {
                $notifStudentId = null;
                if ($notif['Target_Student_ID'] !== null && $notif['Target_Student_ID'] !== '' && $notif['Target_Student_ID'] !== '0' && $notif['Target_Student_ID'] !== 0) {
                    $notifStudentId = intval($notif['Target_Student_ID']);
                    if ($notifStudentId <= 0) {
                        $notifStudentId = null;
                    }
                }

                if ($notif['Target_Role'] === 'Parent' && $notifStudentId !== null && $notifStudentId > 0) {
                    if (!in_array($notifStudentId, $linkedStudentIds, true)) {
                        
                        error_log("CRITICAL SECURITY BREACH: Notification {$notif['Notif_ID']} for student $notifStudentId passed all checks but student is NOT in linked list [" . implode(',', $linkedStudentIds) . "] - BLOCKING");
                        continue; 
                    }
                }
                
                $absolutelyValidNotifications[] = $notif;
            }

            $notifications = $absolutelyValidNotifications;

            if (count($absolutelyValidNotifications) !== count($finalValidNotifications)) {
                error_log("CRITICAL SECURITY: Absolute validation removed " . (count($finalValidNotifications) - count($absolutelyValidNotifications)) . " unauthorized notifications for parent $currentParentId");
            }

            $finalStudentIds = [];
            foreach ($absolutelyValidNotifications as $notif) {
                if ($notif['Target_Student_ID'] !== null && $notif['Target_Student_ID'] !== '' && $notif['Target_Student_ID'] !== '0' && $notif['Target_Student_ID'] !== 0) {
                    $finalStudentIds[] = intval($notif['Target_Student_ID']);
                }
            }
            $finalStudentIds = array_unique($finalStudentIds);
            error_log("FINAL SECURITY CHECK: Parent ID $currentParentId will see notifications for student IDs: [" . implode(',', $finalStudentIds) . "]. Linked students: [" . implode(',', $linkedStudentIds) . "]");

            if (!empty($finalStudentIds)) {
                foreach ($finalStudentIds as $finalStudentId) {
                    if (!in_array($finalStudentId, $linkedStudentIds, true)) {
                        error_log("CRITICAL SECURITY ERROR: Final notification list contains student ID $finalStudentId which is NOT in linked students list! This is a serious bug!");
                    }
                }
            }
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching notifications for parents: " . $e->getMessage());
    $notifications = [];
}
?>

