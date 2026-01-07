<?php
// admin/students.php - Professional Students Management
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

// Authentication and role check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$admin_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

// Get admin user info
$user = [];
$user_stmt = $conn->prepare("SELECT name, email, phone FROM users WHERE id = ? AND role = 'admin'");
if ($user_stmt) {
    $user_stmt->bind_param("i", $admin_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    $user = $user_result->fetch_assoc() ?: [];
    $user_stmt->close();
}

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_student'])) {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $age = isset($_POST['age']) && $_POST['age'] !== '' ? intval($_POST['age']) : null;
        $emergency_contact = trim($_POST['emergency_contact'] ?? '');
        $medical_notes = trim($_POST['medical_notes'] ?? '');
        
        // Validate required fields
        if (empty($name) || empty($email)) {
            $error_msg = "Name and email are required fields.";
        } else {
            // Check if email already exists
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $check_stmt->bind_param("s", $email);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $error_msg = "A user with this email already exists!";
            } else {
                // Generate a temporary password
                $temp_password = bin2hex(random_bytes(8));
                $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
                
                // Prepare the insert statement
                $stmt = $conn->prepare("INSERT INTO users (name, email, password, phone, age, emergency_contact, medical_notes, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'student', 'active', NOW())");
                if ($stmt === false) {
                    $error_msg = "Database error: " . $conn->error;
                } else {
                    $stmt->bind_param("ssssiss", $name, $email, $hashed_password, $phone, $age, $emergency_contact, $medical_notes);
                    
                    if ($stmt->execute()) {
                        $student_id = $stmt->insert_id;
                        $success_msg = "Student added successfully! Temporary password: " . $temp_password;
                        
                        // Log the activity
                        logActivity($conn, $admin_id, 'Student Added', "Added student: $name (ID: $student_id)");
                    } else {
                        $error_msg = "Failed to add student: " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
            $check_stmt->close();
        }
    }
    
    elseif (isset($_POST['update_student'])) {
        $student_id = intval($_POST['student_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $age = isset($_POST['age']) && $_POST['age'] !== '' ? intval($_POST['age']) : null;
        $emergency_contact = trim($_POST['emergency_contact'] ?? '');
        $medical_notes = trim($_POST['medical_notes'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        // Validate required fields
        if (empty($name) || empty($email) || $student_id <= 0) {
            $error_msg = "Required fields are missing.";
        } else {
            // Check if email already exists for another user
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check_stmt->bind_param("si", $email, $student_id);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $error_msg = "A user with this email already exists!";
            } else {
                // Prepare the update statement
                $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, phone = ?, age = ?, emergency_contact = ?, medical_notes = ?, status = ?, updated_at = NOW() WHERE id = ? AND role = 'student'");
                if ($stmt === false) {
                    $error_msg = "Database error: " . $conn->error;
                } else {
                    $stmt->bind_param("sssissii", $name, $email, $phone, $age, $emergency_contact, $medical_notes, $status, $student_id);
                    
                    if ($stmt->execute()) {
                        $success_msg = "Student updated successfully!";
                        
                        // Log the activity
                        logActivity($conn, $admin_id, 'Student Updated', "Updated student: $name (ID: $student_id)");
                    } else {
                        $error_msg = "Failed to update student: " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
            $check_stmt->close();
        }
    }
    
    elseif (isset($_POST['delete_student'])) {
        $student_id = intval($_POST['student_id'] ?? 0);
        
        if ($student_id <= 0) {
            $error_msg = "Invalid student ID.";
        } else {
            // Get student name for logging
            $stmt = $conn->prepare("SELECT name FROM users WHERE id = ? AND role = 'student'");
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $stmt->bind_result($student_name);
            $stmt->fetch();
            $stmt->close();
            // Remove dependent records safely to avoid FK constraint errors
            $conn->begin_transaction();
            try {
                // 1) Delete payments for any bookings belonging to this student
                $bk_select = $conn->prepare("SELECT id FROM bookings WHERE user_id = ?");
                $bk_select->bind_param("i", $student_id);
                $bk_select->execute();
                $bk_result = $bk_select->get_result();
                $booking_ids = [];
                while ($row = $bk_result->fetch_assoc()) {
                    $booking_ids[] = (int)$row['id'];
                }
                $bk_select->close();

                if (!empty($booking_ids)) {
                    $pay_del = $conn->prepare("DELETE FROM payments WHERE booking_id = ?");
                    foreach ($booking_ids as $bid) {
                        $pay_del->bind_param("i", $bid);
                        $pay_del->execute();
                    }
                    $pay_del->close();
                }

                // 2) Delete enrollments for this student
                $en_del = $conn->prepare("DELETE FROM enrollments WHERE student_id = ?");
                $en_del->bind_param("i", $student_id);
                $en_del->execute();
                $en_del->close();

                // 3) Delete bookings for this student (after payments removed)
                $bk_del = $conn->prepare("DELETE FROM bookings WHERE user_id = ?");
                $bk_del->bind_param("i", $student_id);
                $bk_del->execute();
                $bk_del->close();

                // 4) Delete the student user
                $del_user = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'student'");
                $del_user->bind_param("i", $student_id);
                $del_user->execute();

                if ($del_user->affected_rows > 0) {
                    $success_msg = "Student deleted successfully!";
                    logActivity($conn, $admin_id, 'Student Deleted', "Deleted student: $student_name (ID: $student_id)");
                } else {
                    throw new Exception('Student not found or could not be deleted.');
                }

                $del_user->close();
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Failed to delete student and related data: " . $e->getMessage();
            }
        }
    }
    
    // Store messages in session for redirect
    if ($success_msg) {
        $_SESSION['success_msg'] = $success_msg;
    }
    if ($error_msg) {
        $_SESSION['error_msg'] = $error_msg;
    }
    
    // Redirect to prevent form resubmission
    header("Location: students.php");
    exit();
}

// Load messages from session
if (isset($_SESSION['success_msg'])) {
    $success_msg = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}
if (isset($_SESSION['error_msg'])) {
    $error_msg = $_SESSION['error_msg'];
    unset($_SESSION['error_msg']);
}

// Handle filtering and searching
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$class_filter = $_GET['class_filter'] ?? 'all';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Build WHERE clause
$where_conditions = ["role = 'student'"];
$params = [];
$param_types = '';

if (!empty($search)) {
    $where_conditions[] = "(name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'sss';
}

if ($status_filter !== 'all') {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}

// Get students with pagination
try {
    // Build SQL query
    $sql = "SELECT SQL_CALC_FOUND_ROWS * FROM users WHERE " . implode(' AND ', $where_conditions) . " ORDER BY name ASC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $param_types .= 'ii';
    
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $students_result = $stmt->get_result();
    $students = $students_result->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    
    // Get total count for pagination
    $total_result = $conn->query("SELECT FOUND_ROWS() as total");
    $total_students = $total_result->fetch_assoc()['total'];
    $total_pages = ceil($total_students / $limit);
    
    // Get student statistics
    $total_students_all = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'student'")->fetch_assoc()['total'];
    $active_students = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND status = 'active'")->fetch_assoc()['total'];
    $inactive_students = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND status = 'inactive'")->fetch_assoc()['total'];
    $new_this_month = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")->fetch_assoc()['total'];
    
    // Get all classes for filter
    $classes = $conn->query("SELECT id, title FROM classes WHERE start_time >= NOW() ORDER BY title ASC")->fetch_all(MYSQLI_ASSOC) ?: [];
    
    // Get enrolled students count
    $enrolled_stmt = $conn->prepare("
        SELECT COUNT(DISTINCT u.id) as total 
        FROM users u 
        INNER JOIN bookings b ON u.id = b.user_id 
        WHERE u.role = 'student' 
        AND b.status = 'confirmed'
    ");
    $enrolled_stmt->execute();
    $enrolled_result = $enrolled_stmt->get_result();
    $enrolled_students = $enrolled_result->fetch_assoc()['total'] ?? 0;
    $enrolled_stmt->close();
    
    // Get age distribution
    $age_distribution_stmt = $conn->prepare("
        SELECT 
            SUM(CASE WHEN age < 6 THEN 1 ELSE 0 END) as toddlers,
            SUM(CASE WHEN age BETWEEN 6 AND 12 THEN 1 ELSE 0 END) as children,
            SUM(CASE WHEN age BETWEEN 13 AND 17 THEN 1 ELSE 0 END) as teens,
            SUM(CASE WHEN age >= 18 THEN 1 ELSE 0 END) as adults,
            SUM(CASE WHEN age IS NULL THEN 1 ELSE 0 END) as unknown
        FROM users 
        WHERE role = 'student'
    ");
    $age_distribution_stmt->execute();
    $age_distribution_result = $age_distribution_stmt->get_result();
    $age_distribution = $age_distribution_result->fetch_assoc() ?: [
        'toddlers' => 0, 'children' => 0, 'teens' => 0, 'adults' => 0, 'unknown' => 0
    ];
    $age_distribution_stmt->close();
    
    // Get student growth this month
    $growth_stmt = $conn->prepare("
        SELECT 
            (SELECT COUNT(*) FROM users WHERE role = 'student' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) as new_this_month,
            (SELECT COUNT(*) FROM users WHERE role = 'student' AND created_at < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) as previous_total
    ");
    $growth_stmt->execute();
    $growth_result = $growth_stmt->get_result();
    $growth = $growth_result->fetch_assoc() ?: ['new_this_month' => 0, 'previous_total' => 0];
    $growth_stmt->close();
    
    $growth_percentage = $growth['previous_total'] > 0 ? 
        round(($growth['new_this_month'] / $growth['previous_total']) * 100, 1) : 
        ($growth['new_this_month'] > 0 ? 100 : 0);
        
} catch (Exception $e) {
    error_log("Students query error: " . $e->getMessage());
    $students = [];
    $total_students = 0;
    $total_pages = 0;
    $total_students_all = $active_students = $inactive_students = $new_this_month = $enrolled_students = 0;
    $age_distribution = ['toddlers' => 0, 'children' => 0, 'teens' => 0, 'adults' => 0, 'unknown' => 0];
    $growth_percentage = 0;
    $classes = [];
    $error_msg = $error_msg ?: "Unable to load student data. Please try again later.";
}

// Get current date and time
$current_date = date('l, F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students | Elite Swimming Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --success: #16a34a;
            --warning: #d97706;
            --danger: #dc2626;
            --info: #0891b2;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--gray-50);
            min-height: 100vh;
            color: var(--gray-800);
            line-height: 1.5;
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: 240px;
            background-color: white;
            border-right: 1px solid var(--gray-200);
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 1000;
            padding: 20px 0;
        }
        
        .logo-area {
            padding: 0 20px 20px;
            border-bottom: 1px solid var(--gray-200);
            margin-bottom: 20px;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--dark);
        }
        
        .logo-icon {
            width: 36px;
            height: 36px;
            background-color: var(--primary);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }
        
        .logo-text h3 {
            font-weight: 600;
            font-size: 18px;
            margin: 0;
            color: var(--gray-900);
        }
        
        .logo-text span {
            font-size: 12px;
            color: var(--gray-500);
        }
        
        .nav-menu {
            padding: 0 15px;
        }
        
        .nav-item {
            margin-bottom: 4px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 8px;
            color: var(--gray-600);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .nav-link:hover {
            background-color: var(--gray-100);
            color: var(--primary);
        }
        
        .nav-link.active {
            background-color: var(--primary);
            color: white;
        }
        
        .nav-link i {
            width: 18px;
            text-align: center;
            font-size: 16px;
        }
        
        .logout-section {
            padding: 20px;
            position: absolute;
            bottom: 0;
            width: 100%;
            border-top: 1px solid var(--gray-200);
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 240px;
            padding: 24px;
        }
        
        /* Header */
        .header {
            background-color: white;
            border-radius: 8px;
            padding: 20px 24px;
            margin-bottom: 24px;
            border: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left h1 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--gray-900);
        }
        
        .header-left p {
            color: var(--gray-600);
            margin: 0;
            font-size: 14px;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            background-color: var(--gray-50);
            padding: 8px 16px;
            border-radius: 6px;
        }
        
        .user-avatar {
            width: 36px;
            height: 36px;
            background-color: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 500;
            font-size: 14px;
        }
        
        .user-info h5 {
            font-weight: 500;
            margin: 0;
            font-size: 14px;
        }
        
        .user-info p {
            color: var(--gray-500);
            font-size: 12px;
            margin: 0;
        }
        
        /* Alerts */
        .alert-custom {
            border-radius: 8px;
            border: 1px solid;
            padding: 16px 20px;
            margin-bottom: 24px;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            border: 1px solid var(--gray-200);
            position: relative;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background-color: var(--primary);
        }
        
        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 16px;
            background-color: var(--primary);
            color: white;
        }
        
        .stat-content h3 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--gray-900);
        }
        
        .stat-content p {
            color: var(--gray-600);
            font-size: 14px;
            margin: 0;
        }
        
        .stat-trend {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            margin-top: 8px;
            color: var(--gray-500);
        }
        
        .trend-up { color: var(--success); }
        .trend-down { color: var(--danger); }
        
        /* Filter Section */
        .filter-section {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            border: 1px solid var(--gray-200);
            margin-bottom: 24px;
        }
        
        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .filter-header h3 {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
            color: var(--gray-900);
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
        }
        
        .form-group label {
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--gray-800);
            font-size: 14px;
            display: block;
        }
        
        /* Age Distribution */
        .age-distribution {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 20px;
        }
        
        .age-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            background-color: var(--gray-50);
            border-radius: 6px;
            border: 1px solid var(--gray-200);
        }
        
        .age-label {
            font-weight: 500;
            color: var(--gray-700);
            font-size: 14px;
        }
        
        .age-count {
            font-weight: 600;
            color: var(--primary);
            font-size: 14px;
        }
        
        /* Table Container */
        .table-container {
            background-color: white;
            border-radius: 8px;
            border: 1px solid var(--gray-200);
            margin-bottom: 24px;
        }
        
        .table-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-header h3 {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
            color: var(--gray-900);
        }
        
        .table-wrapper {
            overflow-x: auto;
        }
        
        .table {
            margin: 0;
            width: 100%;
            font-size: 14px;
        }
        
        .table thead {
            background-color: var(--gray-50);
        }
        
        .table th {
            font-weight: 600;
            color: var(--gray-700);
            padding: 12px 16px;
            border-bottom: 2px solid var(--gray-200);
            white-space: nowrap;
        }
        
        .table td {
            padding: 12px 16px;
            vertical-align: middle;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .table tbody tr:hover {
            background-color: var(--gray-50);
        }
        
        /* Student Avatar */
        .student-avatar {
            width: 36px;
            height: 36px;
            background-color: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 500;
            font-size: 14px;
        }
        
        /* Status Badges */
        .badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-weight: 500;
            font-size: 12px;
        }
        
        .badge-success {
            background-color: rgba(22, 163, 74, 0.1);
            color: var(--success);
        }
        
        .badge-danger {
            background-color: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .badge-secondary {
            background-color: rgba(107, 114, 128, 0.1);
            color: var(--gray-600);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 6px;
        }
        
        .btn-icon {
            width: 28px;
            height: 28px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 48px 20px;
        }
        
        .empty-state i {
            font-size: 48px;
            color: var(--gray-300);
            margin-bottom: 16px;
        }
        
        .empty-state h4 {
            font-weight: 600;
            color: var(--gray-600);
            margin-bottom: 8px;
            font-size: 16px;
        }
        
        .empty-state p {
            color: var(--gray-500);
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        /* Pagination */
        .pagination-container {
            padding: 16px 20px;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: center;
        }
        
        .pagination {
            margin: 0;
        }
        
        .page-link {
            border: 1px solid var(--gray-300);
            color: var(--primary);
            padding: 6px 12px;
            border-radius: 6px;
            margin: 0 2px;
            font-size: 14px;
        }
        
        .page-item.active .page-link {
            background-color: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        
        /* Modal */
        .modal-content {
            border-radius: 8px;
            border: 1px solid var(--gray-200);
        }
        
        .modal-header {
            background-color: var(--primary);
            color: white;
            border-bottom: none;
            border-radius: 8px 8px 0 0;
            padding: 20px;
        }
        
        .modal-title {
            font-weight: 600;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            border-top: 1px solid var(--gray-200);
            padding: 16px 20px;
        }
        
        .form-label {
            font-weight: 500;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .form-label.required::after {
            content: ' *';
            color: var(--danger);
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                width: 64px;
            }
            
            .main-content {
                margin-left: 64px;
            }
            
            .logo-text, .nav-text {
                display: none;
            }
            
            .logo {
                justify-content: center;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 16px;
            }
            
            .header {
                flex-direction: column;
                gap: 12px;
                text-align: center;
            }
            
            .filter-header {
                flex-direction: column;
                gap: 12px;
                align-items: stretch;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .table-header {
                flex-direction: column;
                gap: 12px;
            }
            
            .action-buttons {
                flex-wrap: wrap;
                justify-content: center;
            }
        }
        
        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .table-wrapper {
                font-size: 13px;
            }
            
            .table th, .table td {
                padding: 8px 10px;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 4px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="logo-area">
                <a href="index.php" class="logo">
                    <div class="logo-icon">
                        <i class="bi bi-droplet-half"></i>
                    </div>
                    <div class="logo-text">
                        <h3>Elite Swimming Academy</h3>
                        <span>Admin Portal</span>
                    </div>
                </a>
            </div>
            
            <nav class="nav-menu">
                <div class="nav-item">
                    <a href="index.php" class="nav-link">
                        <i class="bi bi-speedometer2"></i>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="students.php" class="nav-link active">
                        <i class="bi bi-people"></i>
                        <span class="nav-text">Students</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="instructors.php" class="nav-link">
                        <i class="bi bi-person-badge"></i>
                        <span class="nav-text">Instructors</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="classes.php" class="nav-link">
                        <i class="bi bi-calendar-week"></i>
                        <span class="nav-text">Classes</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="bookings.php" class="nav-link">
                        <i class="bi bi-journal-check"></i>
                        <span class="nav-text">Bookings</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="payments.php" class="nav-link">
                        <i class="bi bi-credit-card"></i>
                        <span class="nav-text">Payments</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="settings.php" class="nav-link">
                        <i class="bi bi-gear"></i>
                        <span class="nav-text">Settings</span>
                    </a>
                </div>
            </nav>
            
            <div class="logout-section">
                <form method="post" action="logout.php" style="margin:0;">
                    <button type="submit" name="confirm_logout" value="1" class="nav-link btn" style="background:none;border:none;width:100%;text-align:left;padding:10px 12px;">
                        <i class="bi bi-box-arrow-right"></i>
                        <span class="nav-text">Logout</span>
                    </button>
                </form>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-left">
                    <h1>Manage Students</h1>
                    <p>Total Students: <?= $total_students_all ?> • <?= $current_date ?></p>
                </div>
                <div class="user-profile">
                    <div class="user-avatar">
                        <?= isset($user['name']) ? strtoupper(substr($user['name'], 0, 1)) : 'A' ?>
                    </div>
                    <div class="user-info">
                        <h5><?= htmlspecialchars($user['name'] ?? 'Admin') ?></h5>
                        <p>Administrator</p>
                    </div>
                </div>
            </header>
            
            <!-- Alerts -->
            <?php if ($success_msg): ?>
                <div class="alert alert-success alert-custom alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?= htmlspecialchars($success_msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($error_msg): ?>
                <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-circle-fill me-2"></i>
                    <?= htmlspecialchars($error_msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-people"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $total_students_all ?></h3>
                        <p>Total Students</p>
                        <div class="stat-trend">
                            <i class="bi bi-arrow-up <?= $growth_percentage >= 0 ? 'trend-up' : 'trend-down' ?>"></i>
                            <span><?= abs($growth_percentage) ?>% this month</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-person-check"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $active_students ?></h3>
                        <p>Active Students</p>
                        <div class="stat-trend">
                            <span><?= round(($active_students / max($total_students_all, 1)) * 100) ?>% of total</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-person-plus"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $new_this_month ?></h3>
                        <p>New This Month</p>
                        <div class="stat-trend">
                            <span>Recent signups</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-journal-check"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $enrolled_students ?></h3>
                        <p>Currently Enrolled</p>
                        <div class="stat-trend">
                            <span>Active class bookings</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Age Distribution -->
            <div class="filter-section">
                <h4 class="mb-4" style="font-size: 16px;">Student Age Distribution</h4>
                <div class="age-distribution">
                    <div class="age-item">
                        <span class="age-label">Toddlers (0-5 years)</span>
                        <span class="age-count"><?= $age_distribution['toddlers'] ?></span>
                    </div>
                    <div class="age-item">
                        <span class="age-label">Children (6-12 years)</span>
                        <span class="age-count"><?= $age_distribution['children'] ?></span>
                    </div>
                    <div class="age-item">
                        <span class="age-label">Teens (13-17 years)</span>
                        <span class="age-count"><?= $age_distribution['teens'] ?></span>
                    </div>
                    <div class="age-item">
                        <span class="age-label">Adults (18+ years)</span>
                        <span class="age-count"><?= $age_distribution['adults'] ?></span>
                    </div>
                    <div class="age-item">
                        <span class="age-label">Age Not Specified</span>
                        <span class="age-count"><?= $age_distribution['unknown'] ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-header">
                    <h3>Filter Students</h3>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStudentModal" style="font-size: 14px;">
                        <i class="bi bi-plus-circle me-2"></i> Add Student
                    </button>
                </div>
                <form method="GET" class="filter-grid">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Name, email, or phone...">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select class="form-select" name="status">
                            <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                            <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Class</label>
                        <select class="form-select" name="class_filter">
                            <option value="all" <?= $class_filter === 'all' ? 'selected' : '' ?>>All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $class['id'] ?>" <?= $class_filter == $class['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100" style="font-size: 14px;">
                            <i class="bi bi-funnel me-2"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Students Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3>Student Directory</h3>
                    <div>
                        <span class="text-muted" style="font-size: 14px;">Showing <?= count($students) ?> of <?= $total_students ?> students</span>
                    </div>
                </div>
                
                <div class="table-wrapper">
                    <?php if (empty($students)): ?>
                        <div class="empty-state">
                            <i class="bi bi-people"></i>
                            <h4>No Students Found</h4>
                            <p>No students match your search criteria. Try adjusting your filters.</p>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStudentModal" style="font-size: 14px;">
                                <i class="bi bi-plus-circle me-2"></i> Add New Student
                            </button>
                        </div>
                    <?php else: ?>
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Contact Info</th>
                                    <th>Age</th>
                                    <th>Status</th>
                                    <th>Member Since</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="student-avatar me-3">
                                                    <?= strtoupper(substr($student['name'], 0, 1)) ?>
                                                </div>
                                                <div>
                                                    <div class="fw-medium" style="font-size: 14px;"><?= htmlspecialchars($student['name']) ?></div>
                                                    <small class="text-muted" style="font-size: 12px;">ID: <?= $student['id'] ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-medium" style="font-size: 14px;"><?= htmlspecialchars($student['email']) ?></div>
                                            <?php if ($student['phone']): ?>
                                                <small class="text-muted" style="font-size: 12px;"><?= htmlspecialchars($student['phone']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($student['age']): ?>
                                                <span class="badge badge-secondary"><?= $student['age'] ?> years</span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?= $student['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>">
                                                <?= ucfirst($student['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= date('M j, Y', strtotime($student['created_at'])) ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-outline-primary btn-sm btn-icon" 
                                                        data-bs-toggle="modal" data-bs-target="#viewStudentModal"
                                                        onclick="viewStudent(<?= htmlspecialchars(json_encode($student)) ?>)"
                                                        title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button class="btn btn-outline-primary btn-sm btn-icon" 
                                                        data-bs-toggle="modal" data-bs-target="#editStudentModal"
                                                        onclick="editStudent(<?= htmlspecialchars(json_encode($student)) ?>)"
                                                        title="Edit Student">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this student? This action cannot be undone.');">
                                                    <input type="hidden" name="student_id" value="<?= $student['id'] ?>">
                                                    <button type="submit" name="delete_student" class="btn btn-outline-danger btn-sm btn-icon" title="Delete Student">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination-container">
                        <nav aria-label="Page navigation">
                            <ul class="pagination">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" aria-label="Previous">
                                            <span aria-hidden="true">&laquo;</span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                                <?= $i ?>
                                            </a>
                                        </li>
                                    <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">...</span>
                                        </li>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" aria-label="Next">
                                            <span aria-hidden="true">&raquo;</span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Add Student Modal -->
    <div class="modal fade" id="addStudentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="addStudentForm">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required">Full Name</label>
                                <input type="text" class="form-control" name="name" required maxlength="100">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">Email Address</label>
                                <input type="email" class="form-control" name="email" required maxlength="100">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" name="phone" maxlength="20" placeholder="+263 77 123 4567">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Age</label>
                                <input type="number" class="form-control" name="age" min="1" max="100" placeholder="Optional">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Emergency Contact</label>
                                <input type="text" class="form-control" name="emergency_contact" maxlength="100" placeholder="Name and phone number">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Medical Notes</label>
                                <textarea class="form-control" name="medical_notes" rows="3" placeholder="Any medical conditions, allergies, or special requirements..."></textarea>
                            </div>
                            <div class="col-12">
                                <div class="alert alert-info" style="font-size: 14px;">
                                    <i class="bi bi-info-circle me-2"></i>
                                    A temporary password will be generated and shown upon successful student creation.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_student" class="btn btn-primary">Add Student</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Student Modal -->
    <div class="modal fade" id="editStudentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="editStudentForm">
                    <input type="hidden" name="student_id" id="edit_student_id">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required">Full Name</label>
                                <input type="text" class="form-control" name="name" id="edit_name" required maxlength="100">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">Email Address</label>
                                <input type="email" class="form-control" name="email" id="edit_email" required maxlength="100">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" name="phone" id="edit_phone" maxlength="20">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Age</label>
                                <input type="number" class="form-control" name="age" id="edit_age" min="1" max="100">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="edit_status">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Emergency Contact</label>
                                <input type="text" class="form-control" name="emergency_contact" id="edit_emergency_contact" maxlength="100">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Medical Notes</label>
                                <textarea class="form-control" name="medical_notes" id="edit_medical_notes" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_student" class="btn btn-primary">Update Student</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- View Student Modal -->
    <div class="modal fade" id="viewStudentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Student Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted">Full Name</label>
                            <p class="fw-medium" id="view_name"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Email Address</label>
                            <p id="view_email"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Phone Number</label>
                            <p id="view_phone"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Age</label>
                            <p id="view_age"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Status</label>
                            <p id="view_status"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Member Since</label>
                            <p id="view_created_at"></p>
                        </div>
                        <div class="col-12">
                            <label class="form-label text-muted">Emergency Contact</label>
                            <p id="view_emergency_contact"></p>
                        </div>
                        <div class="col-12">
                            <label class="form-label text-muted">Medical Notes</label>
                            <div class="border rounded p-3 bg-light">
                                <p id="view_medical_notes" class="mb-0"></p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Edit student function
            window.editStudent = function(student) {
                document.getElementById('edit_student_id').value = student.id;
                document.getElementById('edit_name').value = student.name;
                document.getElementById('edit_email').value = student.email;
                document.getElementById('edit_phone').value = student.phone || '';
                document.getElementById('edit_age').value = student.age || '';
                document.getElementById('edit_status').value = student.status || 'active';
                document.getElementById('edit_emergency_contact').value = student.emergency_contact || '';
                document.getElementById('edit_medical_notes').value = student.medical_notes || '';
            }
            
            // View student function
            window.viewStudent = function(student) {
                document.getElementById('view_name').textContent = student.name;
                document.getElementById('view_email').textContent = student.email;
                document.getElementById('view_phone').textContent = student.phone || 'Not provided';
                document.getElementById('view_age').textContent = student.age ? student.age + ' years' : 'Not provided';
                document.getElementById('view_status').innerHTML = student.status === 'active' 
                    ? '<span class="badge badge-success">Active</span>' 
                    : '<span class="badge badge-danger">Inactive</span>';
                document.getElementById('view_created_at').textContent = student.created_at ? 
                    new Date(student.created_at).toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    }) : 'N/A';
                document.getElementById('view_emergency_contact').textContent = student.emergency_contact || 'Not provided';
                document.getElementById('view_medical_notes').textContent = student.medical_notes || 'None';
            }
            
            // Form validation
            const addForm = document.getElementById('addStudentForm');
            const editForm = document.getElementById('editStudentForm');
            
            if (addForm) {
                addForm.addEventListener('submit', function(e) {
                    const name = this.querySelector('[name="name"]').value.trim();
                    const email = this.querySelector('[name="email"]').value.trim();
                    const phone = this.querySelector('[name="phone"]').value.trim();
                    
                    if (!name || !email) {
                        e.preventDefault();
                        alert('Name and email are required fields.');
                        return false;
                    }
                    
                    if (phone && !/^[\d\s\+\-\(\)]+$/.test(phone)) {
                        e.preventDefault();
                        alert('Please enter a valid phone number.');
                        return false;
                    }
                    
                    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                        e.preventDefault();
                        alert('Please enter a valid email address.');
                        return false;
                    }
                });
            }
            
            if (editForm) {
                editForm.addEventListener('submit', function(e) {
                    const name = this.querySelector('[name="name"]').value.trim();
                    const email = this.querySelector('[name="email"]').value.trim();
                    const phone = this.querySelector('[name="phone"]').value.trim();
                    
                    if (!name || !email) {
                        e.preventDefault();
                        alert('Name and email are required fields.');
                        return false;
                    }
                    
                    if (phone && !/^[\d\s\+\-\(\)]+$/.test(phone)) {
                        e.preventDefault();
                        alert('Please enter a valid phone number.');
                        return false;
                    }
                    
                    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                        e.preventDefault();
                        alert('Please enter a valid email address.');
                        return false;
                    }
                });
            }
            
            // Search functionality
            const searchInput = document.querySelector('input[name="search"]');
            const searchForm = searchInput?.closest('form');
            
            if (searchInput && searchForm) {
                let searchTimeout;
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        if (this.value.length >= 3 || this.value.length === 0) {
                            searchForm.submit();
                        }
                    }, 500);
                });
            }
            
            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                    bsAlert.close();
                }, 5000);
            });
        });
    </script>
</body>
</html>