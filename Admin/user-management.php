<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/activity-logger.php';

requireUserType('admin');

$currentAdminId = getCurrentUserId();
$currentAdmin = getCurrentUserData($pdo);
$adminName = $_SESSION['user_name'] ?? 'Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    ini_set('display_errors', 1);
    error_reporting(E_ALL);

    error_log("POST data received: " . print_r($_POST, true));

    if ($_POST['action'] === 'saveStudent') {
    try {
        $userId = $_POST['userId'] ?? '';
        $isUpdate = !empty($userId);
        
        $studentCode = trim($_POST['studentId'] ?? '');
        $nameEn = trim($_POST['studentNameEn'] ?? '');
        $nameAr = trim($_POST['studentNameAr'] ?? '');
        $email = trim($_POST['studentEmail'] ?? '');
        $phone = trim($_POST['studentPhone'] ?? '');
        $dateOfBirth = $_POST['studentDOB'] ?? null;
        $placeOfBirth = trim($_POST['studentPOB'] ?? '');
        $nationalId = trim($_POST['studentNationalId'] ?? '');
        $address = trim($_POST['studentAddress'] ?? '');
        $grade = intval($_POST['grade'] ?? 0);
        $section = strtoupper(trim($_POST['section'] ?? ''));
        $parentId = intval($_POST['parentId'] ?? 0);
        $createParentAccount = isset($_POST['createParentAccount']) && $_POST['createParentAccount'] === '1';
        $guardianName = trim($_POST['guardianName'] ?? '');
        $guardianRole = trim($_POST['guardianRole'] ?? '');
        $guardianPhone = trim($_POST['guardianPhone'] ?? '');
        $guardianEmail = trim($_POST['guardianEmail'] ?? '');
        $guardianNationalId = trim($_POST['guardianNationalId'] ?? '');

        $classId = intval($_POST['classId'] ?? 0);

        if ($classId > 0) {
            $stmt = $pdo->prepare("SELECT Class_ID, Grade_Level, Section FROM class WHERE Class_ID = ? LIMIT 1");
            $stmt->execute([$classId]);
            $classData = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$classData) {
                header("Location: user-management.php?error=1&message=" . urlencode('Invalid class selected.'));
                exit();
            }
            
            $grade = $classData['Grade_Level'];
            $section = strtoupper($classData['Section']);
        } else {
            header("Location: user-management.php?error=1&message=" . urlencode('Please select a class.'));
            exit();
        }

        if (empty($studentCode) || empty($nameEn) || empty($nameAr) || empty($nationalId) || $classId <= 0) {
            header("Location: user-management.php?error=1&message=" . urlencode('Please fill in all required fields including both English and Arabic names.'));
            exit();
        }

        $pdo->beginTransaction();
        
        if ($isUpdate) {
            
            $parts = explode('_', $userId);
            if (count($parts) !== 2 || $parts[0] !== 'student') {
                $pdo->rollBack();
                header("Location: user-management.php?error=1&message=" . urlencode('Invalid user ID.'));
                exit();
            }
            $studentId = intval($parts[1]);

            $stmt = $pdo->prepare("
                UPDATE student 
                SET Student_Code = ?, NameEn = ?, NameAr = ?, Email = ?, Phone = ?, 
                    Date_Of_Birth = ?, Place_Of_Birth = ?, National_ID = ?, 
                    Address = ?, Parent_ID = ?, Class_ID = ?
                WHERE Student_ID = ?
            ");
            $stmt->execute([
                $studentCode, $nameEn, $nameAr, $email, $phone, $dateOfBirth, $placeOfBirth, 
                $nationalId, $address, $parentId > 0 ? $parentId : null, $classId, $studentId
            ]);
        } else {
            
            $password = $nationalId;

            $stmt = $pdo->prepare("
                INSERT INTO student 
                (Student_Code, NameEn, NameAr, Email, Phone, Date_Of_Birth, Place_Of_Birth, National_ID, Password, Address, Parent_ID, Class_ID, Status, Enrollment_Date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', CURDATE())
            ");
            $stmt->execute([
                $studentCode, $nameEn, $nameAr, $email, $phone, $dateOfBirth, $placeOfBirth, 
                $nationalId, $password, $address, $parentId > 0 ? $parentId : null, $classId
            ]);
            $studentId = $pdo->lastInsertId();
        }

        if ($createParentAccount) {
            
            if (empty($guardianName) || empty($guardianEmail) || empty($guardianRole) || empty($guardianNationalId)) {
                $pdo->rollBack();
                header("Location: user-management.php?error=1&message=" . urlencode('Please fill in all guardian information (Name, Email, National ID, and Relationship Type) to create parent account.'));
                exit();
            }

            $stmt = $pdo->prepare("SELECT Parent_ID FROM parent WHERE Email = ? OR National_ID = ? LIMIT 1");
            $stmt->execute([$guardianEmail, $guardianNationalId]);
            $existingParent = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingParent) {
                
                $parentId = $existingParent['Parent_ID'];
            } else {

                $parentPassword = password_hash($guardianNationalId, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO parent (NameEn, NameAr, Email, Password_Hash, Phone, Address, National_ID, Status, Created_At) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())
                ");
                $stmt->execute([$guardianName, $guardianName, $guardianEmail, $parentPassword, $guardianPhone, $address, $guardianNationalId]);
                $parentId = $pdo->lastInsertId();
            }

            if ($parentId > 0) {
                $stmt = $pdo->prepare("UPDATE student SET Parent_ID = ? WHERE Student_ID = ?");
                $stmt->execute([$parentId, $studentId]);
            }
        }

        if ($parentId > 0 && $studentId > 0) {
            
            $stmt = $pdo->prepare("
                SELECT Relationship_ID FROM parent_student_relationship 
                WHERE Parent_ID = ? AND Student_ID = ?
            ");
            $stmt->execute([$parentId, $studentId]);
            $existingRelationship = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existingRelationship) {

                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count FROM parent_student_relationship 
                    WHERE Student_ID = ? AND Is_Primary = 1
                ");
                $stmt->execute([$studentId]);
                $primaryCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                $isPrimary = ($primaryCount == 0) ? 1 : 0; 
                
                $stmt = $pdo->prepare("
                    INSERT INTO parent_student_relationship (Parent_ID, Student_ID, Relationship_Type, Is_Primary, Created_At) 
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$parentId, $studentId, $guardianRole ?: 'guardian', $isPrimary]);
            }
        }

        if (!$isUpdate && $studentId > 0) {
            
            $stmt = $pdo->prepare("SELECT Student_ID FROM academic_status WHERE Student_ID = ?");
            $stmt->execute([$studentId]);
            if (!$stmt->fetch()) {
                $stmt = $pdo->prepare("
                    INSERT INTO academic_status (Student_ID, Status, Enrollment_Date, Academic_Year) 
                    VALUES (?, 'active', CURDATE(), YEAR(CURDATE()))
                ");
                $stmt->execute([$studentId]);
            }
        }
        
        $pdo->commit();

        $studentName = $nameEn . ($nameAr ? ' (' . $nameAr . ')' : '');
        $details = "Code: {$studentCode}, Grade: {$grade}, Section: {$section}";
        logUserAction($pdo, $isUpdate ? 'update' : 'create', 'student', $studentId, $studentName, $details);

        $message = $isUpdate ? 'Student updated successfully!' : 'Student created successfully!';
        header("Location: user-management.php?success=1&message=" . urlencode($message));
        exit();
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error saving student: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        header("Location: user-management.php?error=1&message=" . urlencode('Database error: ' . $e->getMessage()));
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error saving student: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        header("Location: user-management.php?error=1&message=" . urlencode('Error: ' . $e->getMessage()));
        exit();
    }
    }

    if ($_POST['action'] === 'saveTeacher') {
        try {
            $userId = $_POST['userId'] ?? '';
            $isUpdate = !empty($userId);
            
            $nameEn = trim($_POST['teacherNameEn'] ?? '');
            $nameAr = trim($_POST['teacherNameAr'] ?? '');
            $email = trim($_POST['teacherEmail'] ?? '');
            $phone = trim($_POST['teacherPhone'] ?? '');
            $nationalId = trim($_POST['teacherNationalId'] ?? '');
            $dateOfBirth = $_POST['teacherDOB'] ?? null;
            $position = trim($_POST['teacherPosition'] ?? '');
            $address1 = trim($_POST['teacherAddress1'] ?? '');
            $address2 = trim($_POST['teacherAddress2'] ?? '');
            $courseId = intval($_POST['courseId'] ?? 0);
            $subject = trim($_POST['subject'] ?? ''); 
            $password = trim($_POST['password'] ?? '');
            $assignedClasses = $_POST['assignedClasses'] ?? [];

            if (empty($nameEn) || empty($nameAr) || empty($email) || empty($nationalId) || empty($dateOfBirth) || empty($address1) || empty($address2) || $courseId <= 0) {
                header("Location: user-management.php?error=1&message=" . urlencode('Please fill in all required fields including both names, national ID, date of birth, addresses, and course selection.'));
                exit();
            }

            if (empty($subject) && $courseId > 0) {
                $stmt = $pdo->prepare("SELECT Course_Name FROM course WHERE Course_ID = ? LIMIT 1");
                $stmt->execute([$courseId]);
                $courseData = $stmt->fetch(PDO::FETCH_ASSOC);
                $subject = $courseData ? $courseData['Course_Name'] : '';
            }

            $pdo->beginTransaction();
            
            if ($isUpdate) {
                
                $parts = explode('_', $userId);
                if (count($parts) !== 2 || $parts[0] !== 'teacher') {
                    $pdo->rollBack();
                    header("Location: user-management.php?error=1&message=" . urlencode('Invalid user ID.'));
                    exit();
                }
                $teacherId = intval($parts[1]);

                $updateQuery = "UPDATE teacher SET NameEn = ?, NameAr = ?, Email = ?, Phone = ?, Subject = ?, National_ID = ?, Date_Of_Birth = ?, Position = ?, Address1 = ?, Address2 = ?";
                $updateParams = [$nameEn, $nameAr, $email, $phone, $subject, $nationalId, $dateOfBirth, $position, $address1, $address2];

                if (!empty($password)) {
                    $updateQuery .= ", Password_Hash = ?";
                    $updateParams[] = password_hash($password, PASSWORD_DEFAULT);
                }
                
                $updateQuery .= " WHERE Teacher_ID = ?";
                $updateParams[] = $teacherId;
                
                $stmt = $pdo->prepare($updateQuery);
                $stmt->execute($updateParams);
            } else {
                
                $password = password_hash($nationalId, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("
                    INSERT INTO teacher 
                    (NameEn, NameAr, Email, Password_Hash, Phone, Subject, Status, Created_At, National_ID, Address1, Address2, Date_Of_Birth, assignment_date, Position) 
                    VALUES (?, ?, ?, ?, ?, ?, 'active', NOW(), ?, ?, ?, ?, CURDATE(), ?)
                ");
                $stmt->execute([$nameEn, $nameAr, $email, $password, $phone, $subject, $nationalId, $address1, $address2, $dateOfBirth, $position]);
                $teacherId = $pdo->lastInsertId();
            }

            if ($isUpdate) {
                $stmt = $pdo->prepare("DELETE FROM teacher_class_course WHERE Teacher_ID = ?");
                $stmt->execute([$teacherId]);
            }

            if (!empty($assignedClasses) && is_array($assignedClasses) && $courseId > 0) {
                
                $academicYear = date('Y') . '-' . (date('Y') + 1);
                
                foreach ($assignedClasses as $classIdValue) {
                    $classIdValue = intval($classIdValue);
                    if ($classIdValue > 0) {
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO teacher_class_course (Teacher_ID, Class_ID, Course_ID, Academic_Year, Created_At)
                            VALUES (?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE Created_At = NOW()
                        ");
                        $stmt->execute([$teacherId, $classIdValue, $courseId, $academicYear]);
                    }
                }
            }
            
            $pdo->commit();

            $teacherName = $nameEn . ($nameAr ? ' (' . $nameAr . ')' : '');
            $details = "Subject: {$subject}, Position: {$position}";
            logUserAction($pdo, $isUpdate ? 'update' : 'create', 'teacher', $teacherId, $teacherName, $details);

            $message = $isUpdate ? 'Teacher updated successfully!' : 'Teacher created successfully!';
            header("Location: user-management.php?success=1&message=" . urlencode($message));
            exit();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Error saving teacher: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            header("Location: user-management.php?error=1&message=" . urlencode('Database error: ' . $e->getMessage()));
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error saving teacher: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            header("Location: user-management.php?error=1&message=" . urlencode('Error: ' . $e->getMessage()));
            exit();
        }
    }

    if ($_POST['action'] === 'deleteUser') {
        try {
            $userId = $_POST['userId'] ?? '';
            
            if (empty($userId)) {
                header("Location: user-management.php?error=1&message=" . urlencode('Invalid user ID.'));
                exit();
            }

            $parts = explode('_', $userId);
            if (count($parts) !== 2) {
                header("Location: user-management.php?error=1&message=" . urlencode('Invalid user ID format.'));
                exit();
            }
            
            $userType = $parts[0];
            $dbId = intval($parts[1]);
            
            if ($dbId <= 0) {
                header("Location: user-management.php?error=1&message=" . urlencode('Invalid user ID.'));
                exit();
            }

            $userName = '';
            if ($userType === 'student') {
                $stmt = $pdo->prepare("SELECT NameEn, NameAr FROM student WHERE Student_ID = ?");
                $stmt->execute([$dbId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    $userName = $user['NameEn'] . ($user['NameAr'] ? ' (' . $user['NameAr'] . ')' : '');
                }
            } elseif ($userType === 'teacher') {
                $stmt = $pdo->prepare("SELECT NameEn, NameAr FROM teacher WHERE Teacher_ID = ?");
                $stmt->execute([$dbId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    $userName = $user['NameEn'] . ($user['NameAr'] ? ' (' . $user['NameAr'] . ')' : '');
                }
            } elseif ($userType === 'parent') {
                $stmt = $pdo->prepare("SELECT NameEn, NameAr FROM parent WHERE Parent_ID = ?");
                $stmt->execute([$dbId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    $userName = $user['NameEn'] . ($user['NameAr'] ? ' (' . $user['NameAr'] . ')' : '');
                }
            }
            
            if (empty($userName)) {
                header("Location: user-management.php?error=1&message=" . urlencode('User not found.'));
                exit();
            }

            $pdo->beginTransaction();
            
            if ($userType === 'student') {
                
                $stmt = $pdo->prepare("DELETE FROM parent_student_relationship WHERE Student_ID = ?");
                $stmt->execute([$dbId]);

                $stmt = $pdo->prepare("DELETE FROM academic_status WHERE Student_ID = ?");
                $stmt->execute([$dbId]);

                $stmt = $pdo->prepare("DELETE FROM student WHERE Student_ID = ?");
                $stmt->execute([$dbId]);
                
                if ($stmt->rowCount() === 0) {
                    $pdo->rollBack();
                    header("Location: user-management.php?error=1&message=" . urlencode('Student not found.'));
                    exit();
                }
                
            } elseif ($userType === 'teacher') {
                
                $stmt = $pdo->prepare("DELETE FROM teacher_class_course WHERE Teacher_ID = ?");
                $stmt->execute([$dbId]);

                $stmt = $pdo->prepare("DELETE FROM teacher WHERE Teacher_ID = ?");
                $stmt->execute([$dbId]);
                
                if ($stmt->rowCount() === 0) {
                    $pdo->rollBack();
                    header("Location: user-management.php?error=1&message=" . urlencode('Teacher not found.'));
                    exit();
                }
                
            } elseif ($userType === 'parent') {
                
                $stmt = $pdo->prepare("DELETE FROM parent_student_relationship WHERE Parent_ID = ?");
                $stmt->execute([$dbId]);

                $stmt = $pdo->prepare("UPDATE student SET Parent_ID = NULL WHERE Parent_ID = ?");
                $stmt->execute([$dbId]);

                $stmt = $pdo->prepare("DELETE FROM parent WHERE Parent_ID = ?");
                $stmt->execute([$dbId]);
                
                if ($stmt->rowCount() === 0) {
                    $pdo->rollBack();
                    header("Location: user-management.php?error=1&message=" . urlencode('Parent not found.'));
                    exit();
                }
                
            } else {
                $pdo->rollBack();
                header("Location: user-management.php?error=1&message=" . urlencode('Invalid user type.'));
                exit();
            }
            
            $pdo->commit();

            logUserAction($pdo, 'delete', $userType, $dbId, $userName, '');
            
            header("Location: user-management.php?success=1&message=" . urlencode(ucfirst($userType) . ' deleted successfully!'));
            exit();
            
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Error deleting user: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            header("Location: user-management.php?error=1&message=" . urlencode('Database error: ' . $e->getMessage()));
            exit();
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Error deleting user: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            header("Location: user-management.php?error=1&message=" . urlencode('Error: ' . $e->getMessage()));
            exit();
        }
    }

    if ($_POST['action'] === 'saveParent') {
        try {
            $userId = $_POST['userId'] ?? '';
            $isUpdate = !empty($userId);
            
            $nameEn = trim($_POST['parentNameEn'] ?? '');
            $nameAr = trim($_POST['parentNameAr'] ?? '');
            $email = trim($_POST['parentEmail'] ?? '');
            $phone = trim($_POST['parentPhone'] ?? '');
            $password = trim($_POST['parentPassword'] ?? '');

            if (empty($nameEn) || empty($email)) {
                header("Location: user-management.php?error=1&message=" . urlencode('Please fill in all required fields.'));
                exit();
            }

            $pdo->beginTransaction();
            
            if ($isUpdate) {
                
                $parts = explode('_', $userId);
                if (count($parts) !== 2 || $parts[0] !== 'parent') {
                    $pdo->rollBack();
                    header("Location: user-management.php?error=1&message=" . urlencode('Invalid user ID.'));
                    exit();
                }
                $parentId = intval($parts[1]);

                $stmt = $pdo->prepare("SELECT Email FROM parent WHERE Parent_ID = ?");
                $stmt->execute([$parentId]);
                $currentParent = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($currentParent && $currentParent['Email'] !== $email) {
                    
                    $stmt = $pdo->prepare("SELECT Parent_ID FROM parent WHERE Email = ? AND Parent_ID != ? LIMIT 1");
                    $stmt->execute([$email, $parentId]);
                    if ($stmt->fetch()) {
                        $pdo->rollBack();
                        header("Location: user-management.php?error=1&message=" . urlencode('A parent with this email already exists.'));
                        exit();
                    }
                }

                $updateQuery = "UPDATE parent SET NameEn = ?, NameAr = ?, Email = ?, Phone = ?";
                $updateParams = [$nameEn, $nameAr, $email, $phone];

                if (!empty($password)) {
                    $updateQuery .= ", Password_Hash = ?";
                    $updateParams[] = password_hash($password, PASSWORD_DEFAULT);
                }
                
                $updateQuery .= " WHERE Parent_ID = ?";
                $updateParams[] = $parentId;
                
                $stmt = $pdo->prepare($updateQuery);
                $stmt->execute($updateParams);
            } else {
                
                $stmt = $pdo->prepare("SELECT Parent_ID FROM parent WHERE Email = ? LIMIT 1");
                $stmt->execute([$email]);
                $existingParent = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existingParent) {
                    $pdo->rollBack();
                    header("Location: user-management.php?error=1&message=" . urlencode('A parent with this email already exists.'));
                    exit();
                }

                if (empty($password)) {
                    $password = password_hash($email, PASSWORD_DEFAULT); 
                } else {
                    $password = password_hash($password, PASSWORD_DEFAULT);
                }

                $stmt = $pdo->prepare("
                    INSERT INTO parent 
                    (NameEn, NameAr, Email, Password_Hash, Phone, Status, Created_At) 
                    VALUES (?, ?, ?, ?, ?, 'active', NOW())
                ");
                $stmt->execute([$nameEn, $nameAr, $email, $password, $phone]);
                $parentId = $pdo->lastInsertId();
            }
            
            $pdo->commit();

            $parentName = $nameEn . ($nameAr ? ' (' . $nameAr . ')' : '');
            logUserAction($pdo, $isUpdate ? 'update' : 'create', 'parent', $parentId, $parentName, "Email: {$email}");

            $message = $isUpdate ? 'Parent updated successfully!' : 'Parent created successfully!';
            header("Location: user-management.php?success=1&message=" . urlencode($message));
            exit();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Error saving parent: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            header("Location: user-management.php?error=1&message=" . urlencode('Database error: ' . $e->getMessage()));
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error saving parent: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            header("Location: user-management.php?error=1&message=" . urlencode('Error: ' . $e->getMessage()));
            exit();
        }
    }
}

$classes = [];
$courses = [];
$parents = [];
$allUsers = []; 

try {
    
    $stmt = $pdo->prepare("
        SELECT Class_ID, Name, Grade_Level, Section, Academic_Year 
        FROM class 
        ORDER BY Grade_Level, Section
    ");
    $stmt->execute();
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT Course_ID, Course_Name, Description, Grade_Level
        FROM course 
        ORDER BY Grade_Level, Course_Name
    ");
    $stmt->execute();
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT Parent_ID, NameEn, NameAr, Email, Phone, Status, Created_At
        FROM parent 
        ORDER BY NameEn
    ");
    $stmt->execute();
    $parents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT s.Student_ID, s.Student_Code, s.NameEn, s.NameAr, s.Email, s.Phone, 
               s.Status, s.Enrollment_Date, s.National_ID, s.Date_Of_Birth, 
               s.Place_Of_Birth, s.Address, s.Parent_ID, s.Class_ID,
               c.Grade_Level, c.Section, c.Name as ClassName
        FROM student s
        LEFT JOIN class c ON s.Class_ID = c.Class_ID
        ORDER BY s.NameEn
    ");
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT t.Teacher_ID, t.NameEn, t.NameAr, t.Email, t.Phone, t.Subject, t.Status, t.Created_At,
               t.National_ID, t.Date_Of_Birth, t.Address1, t.Address2, t.Position
        FROM teacher t
        ORDER BY t.NameEn
    ");
    $stmt->execute();
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT Teacher_ID, Class_ID, Course_ID
        FROM teacher_class_course
    ");
    $stmt->execute();
    $teacherClassCourse = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $teacherRelationships = [];
    foreach ($teacherClassCourse as $rel) {
        $teacherId = $rel['Teacher_ID'];
        if (!isset($teacherRelationships[$teacherId])) {
            $teacherRelationships[$teacherId] = [
                'classes' => [],
                'courseId' => null
            ];
        }
        $teacherRelationships[$teacherId]['classes'][] = $rel['Class_ID'];
        
        if (!$teacherRelationships[$teacherId]['courseId']) {
            $teacherRelationships[$teacherId]['courseId'] = $rel['Course_ID'];
        }
    }

    foreach ($students as $student) {
        $allUsers[] = [
            'id' => 'student_' . $student['Student_ID'],
            'dbId' => $student['Student_ID'],
            'studentId' => $student['Student_Code'],
            'name' => $student['NameEn'] ?: $student['NameAr'],
            'nameEn' => $student['NameEn'] ?? '',
            'nameAr' => $student['NameAr'] ?? '',
            'role' => 'student',
            'email' => $student['Email'] ?? '',
            'phone' => $student['Phone'] ?? '',
            'status' => strtolower($student['Status'] ?? 'active'),
            'classId' => $student['Class_ID'] ?? null,
            'grade' => $student['Grade_Level'] ?? '',
            'section' => strtolower($student['Section'] ?? ''),
            'className' => $student['ClassName'] ?? '',
            'nationalId' => $student['National_ID'] ?? '',
            'enrollmentDate' => $student['Enrollment_Date'] ?? '',
            'dateOfBirth' => $student['Date_Of_Birth'] ?? '',
            'placeOfBirth' => $student['Place_Of_Birth'] ?? '',
            'address' => $student['Address'] ?? '',
            'parentId' => $student['Parent_ID'] ?? null
        ];
    }
    
    foreach ($teachers as $teacher) {
        $teacherId = $teacher['Teacher_ID'];
        $relationships = $teacherRelationships[$teacherId] ?? ['classes' => [], 'courseId' => null];
        
        $allUsers[] = [
            'id' => 'teacher_' . $teacher['Teacher_ID'],
            'dbId' => $teacher['Teacher_ID'],
            'name' => $teacher['NameEn'] ?: $teacher['NameAr'],
            'nameEn' => $teacher['NameEn'] ?? '',
            'nameAr' => $teacher['NameAr'] ?? '',
            'role' => 'teacher',
            'email' => $teacher['Email'] ?? '',
            'phone' => $teacher['Phone'] ?? '',
            'status' => strtolower($teacher['Status'] ?? 'active'),
            'subject' => $teacher['Subject'] ?? '',
            'courseId' => $relationships['courseId'],
            'assignedClasses' => $relationships['classes'],
            'nationalId' => $teacher['National_ID'] ?? '',
            'dateOfBirth' => $teacher['Date_Of_Birth'] ?? '',
            'address1' => $teacher['Address1'] ?? '',
            'address2' => $teacher['Address2'] ?? '',
            'position' => $teacher['Position'] ?? '',
            'createdAt' => $teacher['Created_At'] ?? ''
        ];
    }

    $stmt = $pdo->prepare("
        SELECT psr.Parent_ID, psr.Student_ID, psr.Relationship_Type,
               s.Student_Code, s.NameEn as StudentNameEn, s.NameAr as StudentNameAr
        FROM parent_student_relationship psr
        LEFT JOIN student s ON psr.Student_ID = s.Student_ID
    ");
    $stmt->execute();
    $parentStudentRelations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $parentStudents = [];
    foreach ($parentStudentRelations as $rel) {
        $parentId = $rel['Parent_ID'];
        if (!isset($parentStudents[$parentId])) {
            $parentStudents[$parentId] = [];
        }
        $parentStudents[$parentId][] = [
            'studentId' => $rel['Student_ID'],
            'studentCode' => $rel['Student_Code'] ?? '',
            'studentNameEn' => $rel['StudentNameEn'] ?? '',
            'studentNameAr' => $rel['StudentNameAr'] ?? '',
            'relationshipType' => $rel['Relationship_Type'] ?? ''
        ];
    }
    
    foreach ($parents as $parent) {
        $allUsers[] = [
            'id' => 'parent_' . $parent['Parent_ID'],
            'dbId' => $parent['Parent_ID'],
            'name' => $parent['NameEn'] ?: $parent['NameAr'],
            'nameEn' => $parent['NameEn'] ?? '',
            'nameAr' => $parent['NameAr'] ?? '',
            'role' => 'parent',
            'email' => $parent['Email'] ?? '',
            'phone' => $parent['Phone'] ?? '',
            'status' => strtolower($parent['Status'] ?? 'active'),
            'linkedStudents' => $parentStudents[$parent['Parent_ID']] ?? [],
            'createdAt' => $parent['Created_At'] ?? ''
        ];
    }
    
} catch (PDOException $e) {
    error_log("Error fetching data: " . $e->getMessage());
    
    $classes = [
        ['Class_ID' => 1, 'Name' => 'Grade 5 - Section A', 'Grade_Level' => 5, 'Section' => 'A', 'Academic_Year' => '2024-2025'],
        ['Class_ID' => 2, 'Name' => 'Grade 5 - Section B', 'Grade_Level' => 5, 'Section' => 'B', 'Academic_Year' => '2024-2025'],
        ['Class_ID' => 3, 'Name' => 'Grade 6 - Section A', 'Grade_Level' => 6, 'Section' => 'A', 'Academic_Year' => '2024-2025'],
        ['Class_ID' => 4, 'Name' => 'Grade 6 - Section B', 'Grade_Level' => 6, 'Section' => 'B', 'Academic_Year' => '2024-2025'],
        ['Class_ID' => 5, 'Name' => 'Grade 7 - Section A', 'Grade_Level' => 7, 'Section' => 'A', 'Academic_Year' => '2024-2025'],
        ['Class_ID' => 6, 'Name' => 'Grade 7 - Section B', 'Grade_Level' => 7, 'Section' => 'B', 'Academic_Year' => '2024-2025'],
        ['Class_ID' => 7, 'Name' => 'Grade 8 - Section A', 'Grade_Level' => 8, 'Section' => 'A', 'Academic_Year' => '2024-2025'],
        ['Class_ID' => 8, 'Name' => 'Grade 8 - Section B', 'Grade_Level' => 8, 'Section' => 'B', 'Academic_Year' => '2024-2025'],
        ['Class_ID' => 9, 'Name' => 'Grade 9 - Section A', 'Grade_Level' => 9, 'Section' => 'A', 'Academic_Year' => '2024-2025'],
        ['Class_ID' => 10, 'Name' => 'Grade 9 - Section B', 'Grade_Level' => 9, 'Section' => 'B', 'Academic_Year' => '2024-2025']
    ];
    $parents = [];
    $allUsers = [];
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - HUDURY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
        .classes-selection-container {
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: #FFF9F5;
            border-radius: 15px;
            border: 2px solid #FFE5E5;
        }
        .classes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 0.75rem;
            margin-top: 1rem;
            max-height: 300px;
            overflow-y: auto;
            padding: 0.5rem;
        }
        .class-checkbox-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            background: white;
            border-radius: 10px;
            border: 2px solid #E0E0E0;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .class-checkbox-item:hover {
            border-color: #FF6B9D;
            background: #FFF5F8;
        }
        .class-checkbox-item input[type="checkbox"] {
            margin-right: 0.75rem;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .class-checkbox-item.selected {
            border-color: #FF6B9D;
            background: #FFF5F8;
        }
        .selected-classes-summary {
            margin-top: 1rem;
            padding: 1rem;
            background: #E8F5E9;
            border-radius: 10px;
            border-left: 4px solid #6BCB77;
        }
        .selected-classes-summary h4 {
            margin: 0 0 0.5rem 0;
            color: #2c3e50;
            font-size: 0.95rem;
        }
        .selected-classes-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .selected-class-badge {
            background: #6BCB77;
            color: white;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
    </style>
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
                <span class="page-icon">👥</span>
                <span data-en="User Management" data-ar="إدارة المستخدمين">User Management</span>
            </h1>
            <p class="page-subtitle" data-en="Manage all user accounts - students, teachers, and parents" data-ar="إدارة جميع حسابات المستخدمين - الطلاب والمعلمين وأولياء الأمور">Manage all user accounts - students, teachers, and parents</p>
        </div>

        <div class="search-filter-bar">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="userSearch" placeholder="Search users..." data-placeholder-en="Search users..." data-placeholder-ar="البحث عن المستخدمين..." oninput="filterUsers()">
            </div>
            <select class="filter-select" id="roleFilter" onchange="filterUsers()">
                <option value="all" data-en="All Roles" data-ar="جميع الأدوار">All Roles</option>
                <option value="student" data-en="Students" data-ar="الطلاب">Students</option>
                <option value="teacher" data-en="Teachers" data-ar="المعلمون">Teachers</option>
                <option value="parent" data-en="Parents" data-ar="أولياء الأمور">Parents</option>
            </select>
            <button class="btn btn-primary" onclick="openCreateUserModal()" data-en="+ Create User" data-ar="+ إنشاء مستخدم">+ Create User</button>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="card-icon">📋</span>
                    <span data-en="All Users" data-ar="جميع المستخدمين">All Users</span>
                </h2>
                <div style="display: flex; gap: 0.5rem;">
                    <button class="btn btn-secondary btn-small" onclick="importUsers()" data-en="Import" data-ar="استيراد">Import</button>
                    <button class="btn btn-secondary btn-small" onclick="exportUsers()" data-en="Export" data-ar="تصدير">Export</button>
                </div>
            </div>
            <table class="data-table" id="usersTable">
                <thead>
                    <tr>
                        <th data-en="User" data-ar="المستخدم">User</th>
                        <th data-en="Role" data-ar="الدور">Role</th>
                        <th data-en="Email" data-ar="البريد الإلكتروني">Email</th>
                        <th data-en="Status" data-ar="الحالة">Status</th>
                        <th data-en="Actions" data-ar="الإجراءات">Actions</th>
                    </tr>
                </thead>
                <tbody id="usersTableBody">
                    
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal" id="userModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle" data-en="Create User" data-ar="إنشاء مستخدم">Create User</h2>
                <button class="modal-close" onclick="closeModal('userModal')">&times;</button>
            </div>
            <form id="userForm" method="POST" action="user-management.php" onsubmit="return saveUser(event);" novalidate>
                <input type="hidden" id="userId" name="userId" value="">
                <input type="hidden" name="action" id="formAction" value="">
                
                <div class="form-group">
                    <label data-en="Role" data-ar="الدور">Role</label>
                    <select id="userRole" name="role" required onchange="updateFormFields()">
                        <option value="">Select Role</option>
                        <option value="student" data-en="Student" data-ar="طالب">Student</option>
                        <option value="teacher" data-en="Teacher" data-ar="معلم">Teacher</option>
                        <option value="parent" data-en="Parent" data-ar="ولي أمر">Parent</option>
                    </select>
                </div>

                <div id="studentFieldsContainer" style="display: none;">
                    <h3 style="color: var(--primary-color); margin: 1.5rem 0 1rem 0; border-bottom: 2px solid #FFE5E5; padding-bottom: 0.5rem;" data-en="Student Information" data-ar="معلومات الطالب">Student Information</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                        <div class="form-group">
                            <label data-en="Student Name (English)" data-ar="اسم الطالب (إنجليزي)">Student Name (English) <span style="color: red;">*</span></label>
                            <input type="text" id="studentNameEn" name="studentNameEn" required>
                        </div>
                        <div class="form-group">
                            <label data-en="Student Name (Arabic)" data-ar="اسم الطالب (عربي)">Student Name (Arabic) <span style="color: red;">*</span></label>
                            <input type="text" id="studentNameAr" name="studentNameAr" required>
                        </div>
                        <div class="form-group">
                            <label data-en="Date of Birth" data-ar="تاريخ الميلاد">Date of Birth</label>
                            <input type="date" id="studentDOB" name="studentDOB" required>
                        </div>
                        <div class="form-group">
                            <label data-en="Place of Birth" data-ar="مكان الميلاد">Place of Birth</label>
                            <input type="text" id="studentPOB" name="studentPOB" required>
                        </div>
                        <div class="form-group">
                            <label data-en="National ID" data-ar="رقم الهوية الوطنية">National ID</label>
                            <input type="text" id="studentNationalId" name="studentNationalId" required>
                        </div>
                        <div class="form-group">
                            <label data-en="Student ID" data-ar="رقم الطالب">Student ID</label>
                            <input type="text" id="studentId" name="studentId" required>
                        </div>
                        <div class="form-group">
                            <label data-en="Student Email" data-ar="بريد الطالب الإلكتروني">Student Email</label>
                            <input type="email" id="studentEmail" name="studentEmail" required>
                        </div>
                        <div class="form-group">
                            <label data-en="Student Phone" data-ar="هاتف الطالب">Student Phone</label>
                            <input type="tel" id="studentPhone" name="studentPhone">
                        </div>
                        <div class="form-group">
                            <label data-en="Address" data-ar="العنوان">Address</label>
                            <textarea id="studentAddress" name="studentAddress" rows="2" required></textarea>
                        </div>
                        <div class="form-group">
                            <label data-en="Class" data-ar="الفصل">Class</label>
                            <select id="studentClass" name="classId" required onchange="updateStudentClassInfo()">
                                <option value="">Select Class</option>
                                <?php 
                                
                                $classesByGrade = [];
                                foreach ($classes as $class) {
                                    $grade = $class['Grade_Level'];
                                    if (!isset($classesByGrade[$grade])) {
                                        $classesByGrade[$grade] = [];
                                    }
                                    $classesByGrade[$grade][] = $class;
                                }
                                ksort($classesByGrade);
                                foreach ($classesByGrade as $grade => $gradeClasses): 
                                ?>
                                    <optgroup label="Grade <?php echo $grade; ?>">
                                        <?php foreach ($gradeClasses as $class): ?>
                                            <option value="<?php echo $class['Class_ID']; ?>" 
                                                    data-grade="<?php echo $class['Grade_Level']; ?>"
                                                    data-section="<?php echo strtolower($class['Section']); ?>">
                                                <?php echo htmlspecialchars($class['Name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" id="studentGrade" name="grade" value="">
                            <input type="hidden" id="studentSection" name="section" value="">
                        </div>
                    </div>
                    
                    <h3 style="color: var(--primary-color); margin: 1.5rem 0 1rem 0; border-bottom: 2px solid #FFE5E5; padding-bottom: 0.5rem;" data-en="Parent/Guardian Information" data-ar="معلومات ولي الأمر">Parent/Guardian Information</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                        <div class="form-group">
                            <label data-en="Select Existing Parent" data-ar="اختر ولي أمر موجود">Select Existing Parent</label>
                            <select id="parentId" name="parentId" onchange="toggleGuardianFields()">
                                <option value="0" data-en="No Parent Selected" data-ar="لم يتم اختيار ولي أمر">No Parent Selected</option>
                                <?php foreach ($parents as $parent): ?>
                                    <option value="<?php echo $parent['Parent_ID']; ?>">
                                        <?php echo htmlspecialchars($parent['NameEn'] . ' (' . $parent['Email'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color: #666; font-size: 0.85rem;" data-en="Select an existing parent or create a new parent account below" data-ar="اختر ولي أمر موجود أو أنشئ حساب ولي أمر جديد أدناه">Select an existing parent or create a new parent account below</small>
                        </div>
                    </div>

                    <div style="margin: 1rem 0; padding: 1rem; background: #E8F5E9; border-radius: 10px; border-left: 4px solid #6BCB77;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-weight: 600;">
                            <input type="checkbox" id="createParentAccount" name="createParentAccount" value="1" onchange="toggleGuardianFields()" style="width: 20px; height: 20px; cursor: pointer;">
                            <span data-en="Create Parent Account" data-ar="إنشاء حساب ولي أمر">Create Parent Account</span>
                        </label>
                        <small style="display: block; margin-top: 0.5rem; color: #666; font-size: 0.85rem;" data-en="Check this option to create a new parent account and link it to this student" data-ar="حدد هذا الخيار لإنشاء حساب ولي أمر جديد وربطه بهذا الطالب">Check this option to create a new parent account and link it to this student</small>
                    </div>
                    
                    <div id="newGuardianFields" style="display: none;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-top: 1rem;">
                        <div class="form-group">
                                <label data-en="Guardian Name" data-ar="اسم ولي الأمر">Guardian Name <span style="color: red;">*</span></label>
                                <input type="text" id="guardianName" name="guardianName">
                        </div>
                        <div class="form-group">
                                <label data-en="Guardian Role" data-ar="دور ولي الأمر">Guardian Role <span style="color: red;">*</span></label>
                                <select id="guardianRole" name="guardianRole">
                                <option value="">Select Role</option>
                                <option value="father" data-en="Father" data-ar="أب">Father</option>
                                <option value="mother" data-en="Mother" data-ar="أم">Mother</option>
                                <option value="brother" data-en="Brother" data-ar="أخ">Brother</option>
                                <option value="sister" data-en="Sister" data-ar="أخت">Sister</option>
                                <option value="uncle" data-en="Uncle" data-ar="عم/خال">Uncle</option>
                                <option value="aunt" data-en="Aunt" data-ar="عمة/خالة">Aunt</option>
                                <option value="grandfather" data-en="Grandfather" data-ar="جد">Grandfather</option>
                                <option value="grandmother" data-en="Grandmother" data-ar="جدة">Grandmother</option>
                                    <option value="guardian" data-en="Guardian" data-ar="وصي">Guardian</option>
                                <option value="other" data-en="Other" data-ar="أخرى">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label data-en="Guardian Phone Number" data-ar="رقم هاتف ولي الأمر">Guardian Phone Number</label>
                                <input type="tel" id="guardianPhone" name="guardianPhone">
                        </div>
                        <div class="form-group">
                                <label data-en="Guardian Email" data-ar="بريد ولي الأمر الإلكتروني">Guardian Email <span style="color: red;">*</span></label>
                                <input type="email" id="guardianEmail" name="guardianEmail">
                        </div>
                        <div class="form-group">
                                <label data-en="Guardian National ID" data-ar="رقم هوية ولي الأمر الوطنية">Guardian National ID <span style="color: red;">*</span></label>
                                <input type="text" id="guardianNationalId" name="guardianNationalId">
                        </div>
                        </div>
                    </div>
                    <div style="margin-top: 1rem; padding: 1rem; background: #E8F5E9; border-radius: 10px; border-left: 4px solid #6BCB77;">
                        <p style="margin: 0; font-size: 0.9rem; color: #2c3e50;">
                            <strong data-en="Note:" data-ar="ملاحظة:">Note:</strong> 
                            <span data-en="Student password will be automatically set to their National ID. Guardian password will be automatically set to their National ID." data-ar="سيتم تعيين كلمة مرور الطالب تلقائياً إلى رقم الهوية الوطنية. سيتم تعيين كلمة مرور ولي الأمر تلقائياً إلى رقم الهوية الوطنية">Student password will be automatically set to their National ID. Guardian password will be automatically set to their National ID.</span>
                        </p>
                    </div>
                </div>

                <div id="teacherFieldsContainer" style="display: none;">
                    <h3 style="color: var(--primary-color); margin: 1.5rem 0 1rem 0; border-bottom: 2px solid #FFE5E5; padding-bottom: 0.5rem;" data-en="Teacher Information" data-ar="معلومات المعلم">Teacher Information</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                        <div class="form-group">
                            <label data-en="Name (English)" data-ar="الاسم (إنجليزي)">Name (English) <span style="color: red;">*</span></label>
                            <input type="text" id="teacherNameEn" name="teacherNameEn" required>
                        </div>
                        <div class="form-group">
                            <label data-en="Name (Arabic)" data-ar="الاسم (عربي)">Name (Arabic) <span style="color: red;">*</span></label>
                            <input type="text" id="teacherNameAr" name="teacherNameAr" required>
                        </div>
                        <div class="form-group">
                            <label data-en="National ID" data-ar="رقم الهوية الوطنية">National ID <span style="color: red;">*</span></label>
                            <input type="text" id="teacherNationalId" name="teacherNationalId" required>
                        </div>
                        <div class="form-group">
                            <label data-en="Date of Birth" data-ar="تاريخ الميلاد">Date of Birth <span style="color: red;">*</span></label>
                            <input type="date" id="teacherDOB" name="teacherDOB" required>
                        </div>
                        <div class="form-group">
                            <label data-en="Position" data-ar="المنصب">Position</label>
                            <input type="text" id="teacherPosition" name="teacherPosition">
                        </div>
                        <div class="form-group">
                            <label data-en="Address Line 1" data-ar="العنوان السطر الأول">Address Line 1 <span style="color: red;">*</span></label>
                            <input type="text" id="teacherAddress1" name="teacherAddress1" required>
                        </div>
                        <div class="form-group">
                            <label data-en="Address Line 2" data-ar="العنوان السطر الثاني">Address Line 2 <span style="color: red;">*</span></label>
                            <input type="text" id="teacherAddress2" name="teacherAddress2" required>
                        </div>
                        <div class="form-group">
                            <label data-en="Email" data-ar="البريد الإلكتروني">Email</label>
                            <input type="email" id="teacherEmail" name="teacherEmail" required>
                        </div>
                        <div class="form-group">
                            <label data-en="Phone" data-ar="الهاتف">Phone</label>
                            <input type="tel" id="teacherPhone" name="teacherPhone">
                        </div>
                        <div class="form-group">
                            <label data-en="Subject (Course)" data-ar="المادة (المقرر)">Subject (Course)</label>
                            <select id="teacherSubject" name="courseId" required onchange="updateTeacherCourseInfo()">
                                <option value="">Select Course</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?php echo $course['Course_ID']; ?>" 
                                            data-course-name="<?php echo htmlspecialchars($course['Course_Name']); ?>">
                                        <?php echo htmlspecialchars($course['Course_Name']); ?>
                                        <?php if ($course['Grade_Level']): ?>
                                            (Grade <?php echo $course['Grade_Level']; ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" id="teacherCourseName" name="subject" value="">
                        </div>
                    </div>

                    <div class="classes-selection-container">
                        <h3 style="color: var(--primary-color); margin: 0 0 0.5rem 0; font-size: 1.1rem;" data-en="Assign Classes" data-ar="تعيين الفصول">Assign Classes</h3>
                        <p style="color: #666; font-size: 0.9rem; margin: 0 0 1rem 0;" data-en="Select one or more classes to assign to this teacher" data-ar="اختر فصلاً واحداً أو أكثر لتعيينها لهذا المعلم">Select one or more classes to assign to this teacher</p>
                        
                        <div class="classes-grid" id="classesGrid">
                            
                        </div>
                        
                        <div class="selected-classes-summary" id="selectedClassesSummary" style="display: none;">
                            <h4 data-en="Selected Classes" data-ar="الفصول المحددة">Selected Classes:</h4>
                            <div class="selected-classes-list" id="selectedClassesList">
                                
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin-top: 1rem; padding: 1rem; background: #E8F5E9; border-radius: 10px; border-left: 4px solid #6BCB77;">
                        <p style="margin: 0; font-size: 0.9rem; color: #2c3e50;">
                            <strong data-en="Note:" data-ar="ملاحظة:">Note:</strong> 
                            <span data-en="Teacher password will be automatically set to their National ID" data-ar="سيتم تعيين كلمة مرور المعلم تلقائياً إلى رقم الهوية الوطنية">Teacher password will be automatically set to their National ID</span>
                        </p>
                    </div>
                </div>

                <div id="parentFieldsContainer" style="display: none;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                        <div class="form-group">
                            <label data-en="Full Name" data-ar="الاسم الكامل">Full Name</label>
                            <input type="text" id="parentName" name="parentName" required>
                        </div>
                        <div class="form-group">
                            <label data-en="Email" data-ar="البريد الإلكتروني">Email</label>
                            <input type="email" id="parentEmail" name="parentEmail" required>
                        </div>
                        <div class="form-group">
                            <label data-en="Phone" data-ar="الهاتف">Phone</label>
                            <input type="tel" id="parentPhone" name="parentPhone" required>
                        </div>
                        <div class="form-group">
                            <label data-en="Student(s)" data-ar="الطالب/الطلاب">Student(s)</label>
                            <input type="text" id="parentStudents" name="students" placeholder="Comma separated student IDs">
                        </div>
                    </div>
                </div>

                <div class="form-group" id="commonPasswordField">
                    <label data-en="Password" data-ar="كلمة المرور">Password</label>
                    <input type="password" id="userPassword" name="password">
                    <small style="color: #666; font-size: 0.85rem;" data-en="Leave blank to auto-generate" data-ar="اتركه فارغاً للإنشاء التلقائي">Leave blank to auto-generate</small>
                </div>
                <div class="action-buttons">
                    <button type="button" class="btn btn-primary" id="saveUserBtn" onclick="handleSaveClick(event)" data-en="Save User" data-ar="حفظ المستخدم">Save User</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('userModal')" data-en="Cancel" data-ar="إلغاء">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        
        window.openCreateUserModal = window.openCreateUserModal || function() {
            console.log('openCreateUserModal fallback called');
            alert('Please refresh the page. JavaScript may not be fully loaded.');
        };

        const availableClasses = <?php echo json_encode($classes, JSON_UNESCAPED_UNICODE); ?>;

        const allUsers = <?php echo json_encode($allUsers, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        let currentEditingUserId = null;
        let currentUsers = allUsers; 

        function loadUsers() {
            currentUsers = allUsers;
            renderUsers(allUsers);
        }

        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, setting up Create User button');

            const createUserBtn = document.querySelector('button[onclick*="openCreateUserModal"]');
            if (createUserBtn) {
                console.log('Create User button found, adding event listener');
                createUserBtn.addEventListener('click', function(e) {
                    console.log('Create User button clicked via event listener');
                    e.preventDefault();
                    e.stopPropagation();
                    if (typeof openCreateUserModal === 'function') {
                        openCreateUserModal();
                    } else {
                        console.error('openCreateUserModal function not defined!');
                        alert('Error: openCreateUserModal function not found. Please refresh the page.');
                    }
                });
            } else {
                console.warn('Create User button not found');
            }

            if (typeof openModal === 'function') {
                console.log('openModal function is available');
            } else {
                console.error('openModal function not found! Make sure script.js is loaded.');
            }
        });

        function renderUsers(users) {
            const tbody = document.getElementById('usersTableBody');
            if (!tbody) {
                console.error('Table body not found');
                return;
            }
            
            if (users.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 2rem; color: #666;">' + 
                    (currentLanguage === 'en' ? 'No users found' : 'لا يوجد مستخدمون') + '</td></tr>';
                return;
            }
            
            tbody.innerHTML = users.map(user => {
                const name = user.name || 'Unknown';
                const email = user.email || '-';
                const roleDetails = getRoleDetails(user);
                const roleLabel = getRoleLabel(user.role);
                const roleColor = getRoleColor(user.role);
                const statusClass = user.status === 'active' ? 'status-active' : 'status-inactive';
                const statusText = user.status === 'active' ? (currentLanguage === 'en' ? 'Active' : 'نشط') : (currentLanguage === 'en' ? 'Inactive' : 'غير نشط');
                
                return `
                <tr>
                    <td>
                        <div class="user-info-item">
                            <div class="user-avatar-item">${getRoleIcon(user.role)}</div>
                            <div>
                                <div style="font-weight: 700;">${escapeHtml(name)}</div>
                                <div style="font-size: 0.9rem; color: #666;">${escapeHtml(roleDetails)}</div>
                            </div>
                        </div>
                    </td>
                    <td><span class="status-badge" style="background: ${roleColor};">${roleLabel}</span></td>
                    <td>${escapeHtml(email)}</td>
                    <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                    <td>
                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <button class="btn btn-secondary btn-small" onclick="editUser('${escapeHtml(user.id)}'); return false;" data-en="Edit" data-ar="تعديل">Edit</button>
                            ${user.role === 'student' ? `<button class="btn btn-primary btn-small" onclick="createGuardianAccount('${escapeHtml(user.id)}')" data-en="Create Guardian Account" data-ar="إنشاء حساب ولي الأمر">Create Guardian Account</button>` : ''}
                            <button class="btn btn-danger btn-small" onclick="deleteUser('${escapeHtml(user.id)}')" data-en="Delete" data-ar="حذف">Delete</button>
                        </div>
                    </td>
                </tr>
            `;
            }).join('');
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function getRoleIcon(role) {
            const icons = { student: '👨‍🎓', teacher: '👩‍🏫', parent: '👨‍👩‍👧' };
            return icons[role] || '👤';
        }

        function getRoleColor(role) {
            const colors = { student: '#6BCB77', teacher: '#FF6B9D', parent: '#FFD93D' };
            return colors[role] || '#999';
        }

        function getRoleLabel(role) {
            const labels = {
                student: currentLanguage === 'en' ? 'Student' : 'طالب',
                teacher: currentLanguage === 'en' ? 'Teacher' : 'معلم',
                parent: currentLanguage === 'en' ? 'Parent' : 'ولي أمر'
            };
            return labels[role] || role;
        }

        function getRoleDetails(user) {
            if (user.role === 'student') {
                const details = [];
                if (user.studentId) details.push(`ID: ${user.studentId}`);
                if (user.grade) details.push(`Grade ${user.grade}`);
                if (user.section) details.push(`Section ${user.section.toUpperCase()}`);
                return details.length > 0 ? details.join(' - ') : 'Student';
            } else if (user.role === 'teacher') {
                return user.subject || 'Teacher';
            } else {
                return 'Parent';
            }
        }

        function filterUsers() {
            const searchTerm = document.getElementById('userSearch').value.toLowerCase();
            const roleFilter = document.getElementById('roleFilter').value;
            
            let filtered = allUsers.filter(user => {
                const matchesSearch = (user.name && user.name.toLowerCase().includes(searchTerm)) || 
                                    (user.email && user.email.toLowerCase().includes(searchTerm)) ||
                                    (user.studentId && user.studentId.toLowerCase().includes(searchTerm));
                const matchesRole = roleFilter === 'all' || user.role === roleFilter;
                return matchesSearch && matchesRole;
            });
            
            currentUsers = filtered;
            renderUsers(filtered);
        }

        function openCreateUserModal() {
            console.log('openCreateUserModal called');
            try {
                currentEditingUserId = null;

                const form = document.getElementById('userForm');
                if (!form) {
                    console.error('Form not found!');
                    alert('Error: Form not found. Please refresh the page.');
                    return;
                }
                form.reset();

                const userIdField = document.getElementById('userId');
                if (userIdField) {
                    userIdField.value = '';
                }

                const modalTitle = document.getElementById('modalTitle');
                if (modalTitle) {
                    modalTitle.textContent = currentLanguage === 'en' ? 'Create User' : 'إنشاء مستخدم';
                }

                const studentFields = document.getElementById('studentFieldsContainer');
                const teacherFields = document.getElementById('teacherFieldsContainer');
                const parentFields = document.getElementById('parentFieldsContainer');
                const sectionField = document.getElementById('sectionFieldContainer');
                const commonPasswordField = document.getElementById('commonPasswordField');
                
                if (studentFields) studentFields.style.display = 'none';
                if (teacherFields) teacherFields.style.display = 'none';
                if (parentFields) parentFields.style.display = 'none';
                if (sectionField) sectionField.style.display = 'none';
                if (commonPasswordField) {
                    commonPasswordField.style.display = 'block';
                }

                const userPassword = document.getElementById('userPassword');
                if (userPassword) {
                    userPassword.required = false;
                    userPassword.value = '';
                }

                const userRole = document.getElementById('userRole');
                if (userRole) {
                    userRole.value = '';
                }

                const parentId = document.getElementById('parentId');
                if (parentId) {
                    parentId.value = '0';
                }
                const createParentCheckbox = document.getElementById('createParentAccount');
                if (createParentCheckbox) {
                    createParentCheckbox.checked = false;
                }

                if (typeof toggleGuardianFields === 'function') {
                    toggleGuardianFields();
                }

                const selectedClassesSummary = document.getElementById('selectedClassesSummary');
                if (selectedClassesSummary) {
                    selectedClassesSummary.style.display = 'none';
                }
                document.querySelectorAll('input[name="assignedClasses[]"]').forEach(cb => {
                    cb.checked = false;
                });
                if (typeof updateSelectedClasses === 'function') {
                    updateSelectedClasses();
                }

                console.log('Opening modal...');
                if (typeof openModal === 'function') {
                    openModal('userModal');
                } else {
                    console.error('openModal function not found!');
                    
                    const modal = document.getElementById('userModal');
                    if (modal) {
                        modal.classList.add('active');
                        document.body.style.overflow = 'hidden';
                        console.log('Modal opened directly');
                    } else {
                        console.error('Modal element not found!');
                        alert('Error: Modal not found. Please refresh the page.');
                    }
                }
                
                console.log('openCreateUserModal completed successfully');
            } catch (error) {
                console.error('Error in openCreateUserModal:', error);
                alert('Error opening create user form: ' + error.message);
            }
        }

        function editUser(userId) {
            console.log('editUser called with userId:', userId);
            console.log('allUsers length:', allUsers ? allUsers.length : 'allUsers is undefined');
            
            try {
                if (!userId) {
                    console.error('editUser: userId is empty or undefined');
                    alert('Error: User ID is missing. Please try again.');
                    return;
                }
                
                if (!allUsers || allUsers.length === 0) {
                    console.error('editUser: allUsers is empty or undefined');
                    alert('Error: User data not loaded. Please refresh the page.');
                    return;
                }
                
                console.log('Searching for user with id:', userId);
                console.log('Available user IDs:', allUsers.map(u => u.id));
                
                const user = allUsers.find(u => u.id === userId);
                if (!user) {
                    console.error('User not found:', userId);
                    console.error('Available users:', allUsers);
                    alert('Error: User not found. User ID: ' + userId);
                    return;
                }
                
                console.log('User found:', user);
                
                currentEditingUserId = userId;

                const modalTitle = document.getElementById('modalTitle');
                const userIdField = document.getElementById('userId');
                const userRoleField = document.getElementById('userRole');
                const userPasswordField = document.getElementById('userPassword');
                
                if (!modalTitle || !userIdField || !userRoleField) {
                    console.error('Required form elements not found');
                    alert('Error: Form elements not found. Please refresh the page.');
                    return;
                }
                
                modalTitle.textContent = currentLanguage === 'en' ? 'Edit User' : 'تعديل المستخدم';
                userIdField.value = user.id;
                userRoleField.value = user.role;
                
                if (userPasswordField) {
                    userPasswordField.required = false;
                }
                
                console.log('Calling updateFormFields...');
                if (typeof updateFormFields === 'function') {
                    updateFormFields();
                } else {
                    console.error('updateFormFields function not found!');
                }
            
                if (user.role === 'student') {
                    console.log('Loading student data...');
                    const studentNameEnField = document.getElementById('studentNameEn');
                    const studentNameArField = document.getElementById('studentNameAr');
                    const studentEmailField = document.getElementById('studentEmail');
                    const studentPhoneField = document.getElementById('studentPhone');
                    const studentIdField = document.getElementById('studentId');
                    const studentDOBField = document.getElementById('studentDOB');
                    const studentPOBField = document.getElementById('studentPOB');
                    const studentNationalIdField = document.getElementById('studentNationalId');
                    const studentAddressField = document.getElementById('studentAddress');
                    const studentClassField = document.getElementById('studentClass');
                    const parentIdField = document.getElementById('parentId');
                    
                    if (!studentNameEnField || !studentNameArField) {
                        console.error('Student name fields not found!');
                        alert('Error: Student form fields not found. Please refresh the page.');
                        return;
                    }
                    
                    if (studentNameEnField) studentNameEnField.value = user.nameEn || '';
                    if (studentNameArField) studentNameArField.value = user.nameAr || '';
                    if (studentEmailField) studentEmailField.value = user.email || '';
                    if (studentPhoneField) studentPhoneField.value = user.phone || '';
                    if (studentIdField) studentIdField.value = user.studentId || '';
                    if (studentDOBField) studentDOBField.value = user.dateOfBirth || '';
                    if (studentPOBField) studentPOBField.value = user.placeOfBirth || '';
                    if (studentNationalIdField) studentNationalIdField.value = user.nationalId || '';
                    if (studentAddressField) studentAddressField.value = user.address || '';

                    if (studentClassField) {
                        if (user.classId) {
                            studentClassField.value = user.classId;
                            if (typeof updateStudentClassInfo === 'function') {
                                updateStudentClassInfo();
                            }
                        } else if (user.grade && user.section && availableClasses) {
                            
                            const matchingClass = availableClasses.find(c => 
                                c.Grade_Level == user.grade && 
                                c.Section.toLowerCase() === user.section.toLowerCase()
                            );
                            if (matchingClass) {
                                studentClassField.value = matchingClass.Class_ID;
                                if (typeof updateStudentClassInfo === 'function') {
                                    updateStudentClassInfo();
                                }
                            }
                        }
                    }

                    if (parentIdField) {
                        if (user.parentId) {
                            parentIdField.value = user.parentId;
                        } else {
                            parentIdField.value = '0';
                        }
                    }

                    const createParentCheckbox = document.getElementById('createParentAccount');
                    if (createParentCheckbox) {
                        createParentCheckbox.checked = false;
                    }
                    
                    if (typeof toggleGuardianFields === 'function') {
                        toggleGuardianFields();
                    }
                } else if (user.role === 'teacher') {
                console.log('Loading teacher data...');
                const teacherNameEnField = document.getElementById('teacherNameEn');
                const teacherNameArField = document.getElementById('teacherNameAr');
                const teacherEmailField = document.getElementById('teacherEmail');
                const teacherPhoneField = document.getElementById('teacherPhone');
                const teacherNationalIdField = document.getElementById('teacherNationalId');
                const teacherDOBField = document.getElementById('teacherDOB');
                const teacherPositionField = document.getElementById('teacherPosition');
                const teacherAddress1Field = document.getElementById('teacherAddress1');
                const teacherAddress2Field = document.getElementById('teacherAddress2');
                const teacherSubjectField = document.getElementById('teacherSubject');
                
                if (!teacherNameEnField || !teacherNameArField) {
                    console.error('Teacher name fields not found!');
                    alert('Error: Teacher form fields not found. Please refresh the page.');
                    return;
                }
                
                if (teacherNameEnField) teacherNameEnField.value = user.nameEn || '';
                if (teacherNameArField) teacherNameArField.value = user.nameAr || '';
                if (teacherEmailField) teacherEmailField.value = user.email || '';
                if (teacherPhoneField) teacherPhoneField.value = user.phone || '';
                if (teacherNationalIdField) teacherNationalIdField.value = user.nationalId || '';
                if (teacherDOBField) teacherDOBField.value = user.dateOfBirth || '';
                if (teacherPositionField) teacherPositionField.value = user.position || '';
                if (teacherAddress1Field) teacherAddress1Field.value = user.address1 || '';
                if (teacherAddress2Field) teacherAddress2Field.value = user.address2 || '';

                if (teacherSubjectField) {
                    if (user.courseId) {
                        teacherSubjectField.value = user.courseId;
                        if (typeof updateTeacherCourseInfo === 'function') {
                            updateTeacherCourseInfo();
                        }
                    } else if (user.subject) {
                        
                        for (let i = 0; i < teacherSubjectField.options.length; i++) {
                            const option = teacherSubjectField.options[i];
                            if (option.textContent.includes(user.subject) || 
                                option.getAttribute('data-course-name') === user.subject) {
                                teacherSubjectField.value = option.value;
                                if (typeof updateTeacherCourseInfo === 'function') {
                                    updateTeacherCourseInfo();
                                }
                                break;
                            }
                        }
                    }
                }

                if (typeof loadClassesForSelection === 'function') {
                    if (user.assignedClasses && user.assignedClasses.length > 0) {
                        setTimeout(() => {
                            loadClassesForSelection();
                            setTimeout(() => {
                                user.assignedClasses.forEach(classId => {
                                    const checkbox = document.getElementById(`class_${classId}`);
                                    if (checkbox) {
                                        checkbox.checked = true;
                                    }
                                });
                                if (typeof updateSelectedClasses === 'function') {
                                    updateSelectedClasses();
                                }
                            }, 50);
                        }, 100);
                    } else {
                        loadClassesForSelection();
                    }
                }
            } else if (user.role === 'parent') {
                console.log('Loading parent data...');
                const parentNameEnField = document.getElementById('parentNameEn');
                const parentNameArField = document.getElementById('parentNameAr');
                const parentEmailField = document.getElementById('parentEmail');
                const parentPhoneField = document.getElementById('parentPhone');
                
                if (parentNameEnField) parentNameEnField.value = user.nameEn || '';
                if (parentNameArField) parentNameArField.value = user.nameAr || '';
                if (parentEmailField) parentEmailField.value = user.email || '';
                if (parentPhoneField) parentPhoneField.value = user.phone || '';

                const linkedStudentsDiv = document.getElementById('parentLinkedStudents');
                const linkedStudentsList = document.getElementById('parentLinkedStudentsList');
                if (linkedStudentsDiv && linkedStudentsList) {
                    if (user.linkedStudents && user.linkedStudents.length > 0) {
                        console.log('Displaying linked students:', user.linkedStudents);
                        linkedStudentsDiv.style.display = 'block';
                        linkedStudentsList.innerHTML = user.linkedStudents.map(student => {
                            const studentName = student.studentNameEn || student.studentNameAr || 'Unknown';
                            return `<div style="padding: 0.5rem; margin: 0.25rem 0; background: white; border-radius: 5px;">
                                <strong>${escapeHtml(studentName)}</strong> - 
                                <span data-en="Student Number" data-ar="رقم الطالب">Student Number:</span> 
                                <strong>${escapeHtml(student.studentCode || 'N/A')}</strong>
                                <span style="color: #666; font-size: 0.9rem;">(${escapeHtml(student.relationshipType || '')})</span>
                            </div>`;
                        }).join('');
                    } else {
                        linkedStudentsDiv.style.display = 'none';
                    }
                }
            }
            
            console.log('Opening modal...');
            
            if (typeof openModal === 'function') {
                openModal('userModal');
            } else {
                console.warn('openModal function not found, using direct method');
                const modal = document.getElementById('userModal');
                if (modal) {
                    modal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                } else {
                    console.error('Modal element not found!');
                    alert('Error: Modal not found. Please refresh the page.');
                    return;
                }
            }
            
            console.log('editUser completed successfully');
            } catch (error) {
                console.error('Error in editUser:', error);
                console.error('Stack trace:', error.stack);
                alert('Error loading user data: ' + error.message + '\n\nPlease check the browser console for details.');
            }
        }

        window.editUser = editUser;

        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, ensuring editUser is available');

            document.querySelectorAll('button[onclick*="editUser"]').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    console.log('Edit button clicked via event listener');
                    const onclick = btn.getAttribute('onclick');
                    if (onclick) {
                        
                        const match = onclick.match(/editUser\(['"]([^'"]+)['"]\)/);
                        if (match && match[1]) {
                            e.preventDefault();
                            e.stopPropagation();
                            if (typeof editUser === 'function') {
                                editUser(match[1]);
                            } else {
                                console.error('editUser function not defined!');
                                alert('Error: editUser function not found. Please refresh the page.');
                            }
                        }
                    }
                });
            });
        });

        function updateFormFields() {
            const role = document.getElementById('userRole').value;
            document.getElementById('studentFieldsContainer').style.display = role === 'student' ? 'block' : 'none';
            document.getElementById('teacherFieldsContainer').style.display = role === 'teacher' ? 'block' : 'none';
            document.getElementById('parentFieldsContainer').style.display = role === 'parent' ? 'block' : 'none';

            if (role === 'student' || role === 'teacher') {
                document.getElementById('commonPasswordField').style.display = 'none';
            } else {
                document.getElementById('commonPasswordField').style.display = 'block';
            }

            if (role === 'teacher') {
                loadClassesForSelection();
            }

            if (role === 'student') {
                
                document.getElementById('parentId').value = '0';
                const createParentCheckbox = document.getElementById('createParentAccount');
                if (createParentCheckbox) {
                    createParentCheckbox.checked = false;
                }
                toggleGuardianFields();
            }
        }
        
        function loadClassesForSelection() {
            const classesGrid = document.getElementById('classesGrid');
            classesGrid.innerHTML = availableClasses.map(cls => `
                <label class="class-checkbox-item" for="class_${cls.Class_ID}">
                    <input type="checkbox" 
                           id="class_${cls.Class_ID}" 
                           name="assignedClasses[]" 
                           value="${cls.Class_ID}"
                           onchange="updateSelectedClasses()">
                    <span>${cls.Name}</span>
                </label>
            `).join('');
        }
        
        function updateSelectedClasses() {
            const checkboxes = document.querySelectorAll('input[name="assignedClasses[]"]:checked');
            const selectedClassesSummary = document.getElementById('selectedClassesSummary');
            const selectedClassesList = document.getElementById('selectedClassesList');
            
            if (checkboxes.length > 0) {
                selectedClassesSummary.style.display = 'block';
                selectedClassesList.innerHTML = Array.from(checkboxes).map(cb => {
                    const classId = cb.value;
                    const className = availableClasses.find(c => c.Class_ID == classId)?.Name || '';
                    return `<span class="selected-class-badge">${className}</span>`;
                }).join('');
            } else {
                selectedClassesSummary.style.display = 'none';
            }

            document.querySelectorAll('.class-checkbox-item').forEach(item => {
                const checkbox = item.querySelector('input[type="checkbox"]');
                if (checkbox.checked) {
                    item.classList.add('selected');
                } else {
                    item.classList.remove('selected');
                }
            });
        }

        function loadSections() {
            const grade = document.getElementById('studentGrade').value;
            const sectionContainer = document.getElementById('sectionFieldContainer');
            if (grade) {
                sectionContainer.style.display = 'block';
            } else {
                sectionContainer.style.display = 'none';
            }
        }

        function toggleGuardianFields() {
            const parentId = document.getElementById('parentId').value;
            const createParentAccount = document.getElementById('createParentAccount');
            const newGuardianFields = document.getElementById('newGuardianFields');

            if (createParentAccount && createParentAccount.checked) {
                newGuardianFields.style.display = 'block';
                document.getElementById('guardianName').required = true;
                document.getElementById('guardianEmail').required = true;
                document.getElementById('guardianPhone').required = false; 
                document.getElementById('guardianRole').required = true;
                document.getElementById('guardianNationalId').required = true;
                
                if (parentId && parentId !== '0') {
                    document.getElementById('parentId').value = '0';
                }
            } else if (parentId && parentId !== '0') {
                
                newGuardianFields.style.display = 'none';
                document.getElementById('guardianName').required = false;
                document.getElementById('guardianEmail').required = false;
                document.getElementById('guardianPhone').required = false;
                document.getElementById('guardianRole').required = false;
                document.getElementById('guardianNationalId').required = false;
                
                if (createParentAccount) {
                    createParentAccount.checked = false;
                }
            } else {
                
                newGuardianFields.style.display = 'none';
                document.getElementById('guardianName').required = false;
                document.getElementById('guardianEmail').required = false;
                document.getElementById('guardianPhone').required = false;
                document.getElementById('guardianRole').required = false;
                document.getElementById('guardianNationalId').required = false;
            }
        }

        function saveUser(event) {
            console.log('saveUser function called');
            console.log('Event:', event);

            const role = document.getElementById('userRole');
            if (!role) {
                console.error('Role select element not found!');
            event.preventDefault();
                alert('Form error: Role field not found. Please refresh the page.');
                return false;
            }
            
            const roleValue = role.value;
            console.log('Selected role:', roleValue);

            if (!roleValue) {
                event.preventDefault();
                alert('Please select a role first.');
                return false;
            }

            const formAction = document.getElementById('formAction');
            if (!formAction) {
                console.error('formAction element not found!');
                event.preventDefault();
                alert('Form error: Action field not found. Please refresh the page.');
                return false;
            }
            
            if (roleValue === 'student') {
                formAction.value = 'saveStudent';
            } else if (roleValue === 'teacher') {
                formAction.value = 'saveTeacher';
            } else if (roleValue === 'parent') {
                formAction.value = 'saveParent';
            }
            
            console.log('Form action set to:', formAction.value);
            console.log('Form will submit now...');

            return true;
        }

        function handleSaveClick(event) {
            console.log('Save button clicked directly');
            event.preventDefault();
            event.stopPropagation();
            
            const form = document.getElementById('userForm');
            if (!form) {
                console.error('Form not found!');
                alert('Form not found. Please refresh the page.');
                return false;
            }

            const role = document.getElementById('userRole');
            if (!role || !role.value) {
                alert('Please select a role first.');
                return false;
            }
            
            const formAction = document.getElementById('formAction');
            if (!formAction) {
                console.error('formAction not found!');
                alert('Form error. Please refresh the page.');
                return false;
            }
            
            const roleValue = role.value;
            if (roleValue === 'student') {
                formAction.value = 'saveStudent';
            } else if (roleValue === 'teacher') {
                formAction.value = 'saveTeacher';
            } else if (roleValue === 'parent') {
                formAction.value = 'saveParent';
            }
            
            console.log('Submitting form with action:', formAction.value);

            if (!formAction.value) {
                alert('Error: Action not set. Please select a role and try again.');
                return false;
            }

            let isValid = true;
            let errorMessage = '';
            
            if (roleValue === 'student') {
                const studentNameEn = document.getElementById('studentNameEn');
                const studentNameAr = document.getElementById('studentNameAr');
                const studentId = document.getElementById('studentId');
                const studentNationalId = document.getElementById('studentNationalId');
                const studentClass = document.getElementById('studentClass');
                
                if (!studentNameEn || !studentNameEn.value.trim()) {
                    isValid = false;
                    errorMessage = 'Please enter student name (English).';
                } else if (!studentNameAr || !studentNameAr.value.trim()) {
                    isValid = false;
                    errorMessage = 'Please enter student name (Arabic).';
                } else if (!studentId || !studentId.value.trim()) {
                    isValid = false;
                    errorMessage = 'Please enter student ID.';
                } else if (!studentNationalId || !studentNationalId.value.trim()) {
                    isValid = false;
                    errorMessage = 'Please enter national ID.';
                } else if (!studentClass || !studentClass.value) {
                    isValid = false;
                    errorMessage = 'Please select a class.';
                }

                const createParentCheckbox = document.getElementById('createParentAccount');
                if (createParentCheckbox && createParentCheckbox.checked) {
                    const guardianName = document.getElementById('guardianName');
                    const guardianEmail = document.getElementById('guardianEmail');
                    const guardianRole = document.getElementById('guardianRole');
                    const guardianNationalId = document.getElementById('guardianNationalId');
                    
                    if (!guardianName || !guardianName.value.trim()) {
                        isValid = false;
                        errorMessage = 'Please enter guardian name.';
                    } else if (!guardianEmail || !guardianEmail.value.trim()) {
                        isValid = false;
                        errorMessage = 'Please enter guardian email.';
                    } else if (!guardianRole || !guardianRole.value) {
                        isValid = false;
                        errorMessage = 'Please select guardian role.';
                    } else if (!guardianNationalId || !guardianNationalId.value.trim()) {
                        isValid = false;
                        errorMessage = 'Please enter guardian national ID.';
                    }
                }
            } else if (roleValue === 'teacher') {
                const teacherNameEn = document.getElementById('teacherNameEn');
                const teacherNameAr = document.getElementById('teacherNameAr');
                const teacherEmail = document.getElementById('teacherEmail');
                const teacherSubject = document.getElementById('teacherSubject');
                const teacherNationalId = document.getElementById('teacherNationalId');
                const teacherDOB = document.getElementById('teacherDOB');
                const teacherAddress1 = document.getElementById('teacherAddress1');
                const teacherAddress2 = document.getElementById('teacherAddress2');
                
                if (!teacherNameEn || !teacherNameEn.value.trim()) {
                    isValid = false;
                    errorMessage = 'Please enter teacher name (English).';
                } else if (!teacherNameAr || !teacherNameAr.value.trim()) {
                    isValid = false;
                    errorMessage = 'Please enter teacher name (Arabic).';
                } else if (!teacherEmail || !teacherEmail.value.trim()) {
                    isValid = false;
                    errorMessage = 'Please enter teacher email.';
                } else if (!teacherNationalId || !teacherNationalId.value.trim()) {
                    isValid = false;
                    errorMessage = 'Please enter national ID.';
                } else if (!teacherDOB || !teacherDOB.value) {
                    isValid = false;
                    errorMessage = 'Please enter date of birth.';
                } else if (!teacherAddress1 || !teacherAddress1.value.trim()) {
                    isValid = false;
                    errorMessage = 'Please enter address line 1.';
                } else if (!teacherAddress2 || !teacherAddress2.value.trim()) {
                    isValid = false;
                    errorMessage = 'Please enter address line 2.';
                } else if (!teacherSubject || !teacherSubject.value) {
                    isValid = false;
                    errorMessage = 'Please select a course.';
                }
            } else if (roleValue === 'parent') {
                const parentNameEn = document.getElementById('parentNameEn');
                const parentNameAr = document.getElementById('parentNameAr');
                const parentEmail = document.getElementById('parentEmail');
                
                if (!parentNameEn || !parentNameEn.value.trim()) {
                    isValid = false;
                    errorMessage = 'Please enter parent name (English).';
                } else if (!parentNameAr || !parentNameAr.value.trim()) {
                    isValid = false;
                    errorMessage = 'Please enter parent name (Arabic).';
                } else if (!parentEmail || !parentEmail.value.trim()) {
                    isValid = false;
                    errorMessage = 'Please enter parent email.';
                }
            }
            
            if (!isValid) {
                alert(errorMessage);
                return false;
            }

            const submitBtn = document.getElementById('saveUserBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Saving...';
            }

            console.log('Form data being submitted:');
            const formData = new FormData(form);
            for (let pair of formData.entries()) {
                console.log(pair[0] + ': ' + pair[1]);
            }

            console.log('Submitting form now...');
            form.submit();
            
            return false;
        }

        function saveUserOld(event) {
            event.preventDefault();
            console.log('Form submission started');
            
            try {
            const role = document.getElementById('userRole').value;
                console.log('Selected role:', role);

                if (!role) {
                    alert('Please select a role first.');
                    return false;
                }

            if (role === 'student') {
                console.log('Processing student form...');
                const formData = new FormData();
                formData.append('action', 'saveStudent');
                formData.append('studentId', document.getElementById('studentId').value);
                formData.append('studentName', document.getElementById('studentName').value);
                formData.append('studentEmail', document.getElementById('studentEmail').value);
                formData.append('studentPhone', document.getElementById('studentPhone')?.value || '');
                formData.append('studentDOB', document.getElementById('studentDOB').value);
                formData.append('studentPOB', document.getElementById('studentPOB').value);
                formData.append('studentNationalId', document.getElementById('studentNationalId').value);
                formData.append('studentAddress', document.getElementById('studentAddress').value);
                formData.append('grade', document.getElementById('studentGrade').value);
                formData.append('section', document.getElementById('studentSection').value);
                formData.append('parentId', document.getElementById('parentId').value);

                const createParentCheckbox = document.getElementById('createParentAccount');
                formData.append('createParentAccount', createParentCheckbox && createParentCheckbox.checked ? '1' : '0');
                
                formData.append('guardianName', document.getElementById('guardianName').value);
                formData.append('guardianRole', document.getElementById('guardianRole').value);
                formData.append('guardianPhone', document.getElementById('guardianPhone').value);
                formData.append('guardianEmail', document.getElementById('guardianEmail').value);
                
                console.log('Sending fetch request for student...');
                console.log('Form data entries:');
                for (let pair of formData.entries()) {
                    console.log(pair[0] + ': ' + pair[1]);
                }
                
                fetch('user-management.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    console.log('Response received:', response);
                    console.log('Response status:', response.status);
                    console.log('Response statusText:', response.statusText);
                    
                    if (!response.ok) {
                        return response.text().then(text => {
                            console.error('Error response body:', text);
                            throw new Error(`HTTP ${response.status}: ${text.substring(0, 100)}`);
                        });
                    }

                    return response.text().then(text => {
                        console.log('Response text (first 500 chars):', text.substring(0, 500));
                        try {
                            const jsonData = JSON.parse(text);
                            console.log('Parsed JSON:', jsonData);
                            return jsonData;
                        } catch (e) {
                            console.error('JSON parse error:', e);
                            console.error('Full response text:', text);
                            throw new Error('Server returned invalid JSON. Response: ' + text.substring(0, 500));
                        }
                    });
                })
                .then(data => {
                    console.log('Response data:', data);
                    if (data.success) {
                        if (typeof showNotification === 'function') {
                            showNotification(data.message || 'Student created successfully!', 'success');
            } else {
                            alert(data.message || 'Student created successfully!');
                        }
                        closeModal('userModal');
                        
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                        if (typeof showNotification === 'function') {
                            showNotification(data.message || 'Error creating student', 'error');
                        } else {
                            alert(data.message || 'Error creating student');
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    console.error('Error details:', error.message);
                    console.error('Error stack:', error.stack);
                    if (typeof showNotification === 'function') {
                        showNotification('Error saving student: ' + error.message, 'error');
                    } else {
                        alert('Error saving student: ' + error.message + '\n\nPlease check the browser console (F12) for more details.');
                    }
                });
                return false;
            }

            if (role === 'teacher') {
                console.log('Processing teacher form...');
                const formData = new FormData();
                formData.append('action', 'saveTeacher');
                formData.append('teacherName', document.getElementById('teacherName').value);
                formData.append('teacherEmail', document.getElementById('teacherEmail').value);
                formData.append('teacherPhone', document.getElementById('teacherPhone')?.value || '');
                formData.append('teacherSubject', document.getElementById('teacherSubject').value);
                formData.append('teacherPassword', document.getElementById('userPassword')?.value || '');

                const selectedClasses = Array.from(document.querySelectorAll('input[name="assignedClasses[]"]:checked'))
                    .map(cb => parseInt(cb.value));
                selectedClasses.forEach(classId => {
                    formData.append('assignedClasses[]', classId);
                });
                
                fetch('user-management.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    console.log('Response received:', response);
                    console.log('Response status:', response.status);
                    
                    if (!response.ok) {
                        return response.text().then(text => {
                            console.error('Error response body:', text);
                            throw new Error(`HTTP ${response.status}: ${text.substring(0, 100)}`);
                        });
                    }
                    
                    return response.text().then(text => {
                        console.log('Response text:', text);
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('JSON parse error:', e);
                            throw new Error('Server returned invalid JSON. Response: ' + text.substring(0, 200));
                        }
                    });
                })
                .then(data => {
                    console.log('Response data:', data);
                    if (data.success) {
                        if (typeof showNotification === 'function') {
                            showNotification(data.message || 'Teacher created successfully!', 'success');
                        } else {
                            alert(data.message || 'Teacher created successfully!');
                        }
            closeModal('userModal');
                        
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                        if (typeof showNotification === 'function') {
                            showNotification(data.message || 'Error creating teacher', 'error');
                        } else {
                            alert(data.message || 'Error creating teacher');
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    console.error('Error details:', error.message);
                    if (typeof showNotification === 'function') {
                        showNotification('Error saving teacher: ' + error.message, 'error');
                    } else {
                        alert('Error saving teacher: ' + error.message + '\n\nPlease check the browser console for more details.');
                    }
                });
                return false;
            }

            if (role === 'parent') {
                console.log('Processing parent form...');
                const formData = new FormData();
                formData.append('action', 'saveParent');
                formData.append('parentName', document.getElementById('parentName').value);
                formData.append('parentEmail', document.getElementById('parentEmail').value);
                formData.append('parentPhone', document.getElementById('parentPhone')?.value || '');
                formData.append('parentPassword', document.getElementById('userPassword')?.value || '');
                
                fetch('user-management.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    console.log('Response received:', response);
                    console.log('Response status:', response.status);
                    
                    if (!response.ok) {
                        return response.text().then(text => {
                            console.error('Error response body:', text);
                            throw new Error(`HTTP ${response.status}: ${text.substring(0, 100)}`);
                        });
                    }
                    
                    return response.text().then(text => {
                        console.log('Response text:', text);
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('JSON parse error:', e);
                            throw new Error('Server returned invalid JSON. Response: ' + text.substring(0, 200));
                        }
                    });
                })
                .then(data => {
                    console.log('Response data:', data);
                    if (data.success) {
                        if (typeof showNotification === 'function') {
                            showNotification(data.message || 'Parent created successfully!', 'success');
                        } else {
                            alert(data.message || 'Parent created successfully!');
                        }
                        closeModal('userModal');
                        
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                        if (typeof showNotification === 'function') {
                            showNotification(data.message || 'Error creating parent', 'error');
                        } else {
                            alert(data.message || 'Error creating parent');
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    console.error('Error details:', error.message);
                    if (typeof showNotification === 'function') {
                        showNotification('Error saving parent: ' + error.message, 'error');
                    } else {
                        alert('Error saving parent: ' + error.message + '\n\nPlease check the browser console for more details.');
                    }
                });
                return false;
            }

            console.error('Invalid role:', role);
            if (typeof showNotification === 'function') {
                showNotification('Invalid role selected. Please try again.', 'error');
            } else {
                alert('Invalid role selected. Please try again.');
            }
            } catch (error) {
                console.error('Unexpected error in saveUser:', error);
                alert('An unexpected error occurred: ' + error.message + '\n\nPlease check the browser console for more details.');
            }
            return false;
        }

        function createGuardianAccount(studentId) {
            const student = allUsers.find(u => u.id === studentId && u.role === 'student');
            if (!student) {
                console.error('Student not found:', studentId);
                return;
            }
            
            if (confirm(currentLanguage === 'en' ? `Create guardian account for ${student.name}?` : `إنشاء حساب ولي أمر لـ ${student.name}؟`)) {

                if (typeof showNotification === 'function') {
                    showNotification(currentLanguage === 'en' ? 'Guardian account creation feature coming soon!' : 'ميزة إنشاء حساب ولي الأمر قريباً!', 'info');
                } else {
                    alert(currentLanguage === 'en' ? 'Guardian account creation feature coming soon!' : 'ميزة إنشاء حساب ولي الأمر قريباً!');
                }
            }
        }

        function deleteUser(userId) {
            const user = allUsers.find(u => u.id === userId);
            if (!user) {
                console.error('User not found:', userId);
                alert('User not found.');
                return;
            }
            
            const confirmMessage = currentLanguage === 'en' 
                ? `Are you sure you want to delete ${user.name}? This action cannot be undone.`
                : `هل أنت متأكد من حذف ${user.name}؟ لا يمكن التراجع عن هذا الإجراء.`;
            
            if (confirm(confirmMessage)) {
                
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'user-management.php';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'deleteUser';
                form.appendChild(actionInput);
                
                const userIdInput = document.createElement('input');
                userIdInput.type = 'hidden';
                userIdInput.name = 'userId';
                userIdInput.value = userId;
                form.appendChild(userIdInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        function importUsers() {
            alert(currentLanguage === 'en' ? 'Import functionality coming soon!' : 'ميزة الاستيراد قريباً!');
        }

        function exportUsers() {
            alert(currentLanguage === 'en' ? 'Export functionality coming soon!' : 'ميزة التصدير قريباً!');
        }

        loadUsers();
    </script>
</body>
</html>

