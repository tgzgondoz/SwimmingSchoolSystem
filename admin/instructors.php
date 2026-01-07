<?php
// admin/instructors.php - Professional Instructors Management
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
    if (isset($_POST['add_instructor'])) {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        // Validate required fields
        if (empty($name) || empty($email)) {
            $error_msg = "Name and email are required fields.";
        } else {
            // Check if email already exists
            $check_stmt = $conn->prepare("SELECT id FROM instructors WHERE email = ?");
            $check_stmt->bind_param("s", $email);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $error_msg = "An instructor with this email already exists!";
            } else {
                // Prepare the insert statement
                $stmt = $conn->prepare("INSERT INTO instructors (name, email, phone, specialization, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                if ($stmt === false) {
                    $error_msg = "Database error: " . $conn->error;
                } else {
                    $stmt->bind_param("sssss", $name, $email, $phone, $specialization, $status);
                    
                    if ($stmt->execute()) {
                        $instructor_id = $stmt->insert_id;
                        $success_msg = "Instructor added successfully!";
                        
                        // Log the activity
                        logActivity($conn, $admin_id, 'Instructor Added', "Added instructor: $name (ID: $instructor_id)");
                    } else {
                        $error_msg = "Failed to add instructor: " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
            $check_stmt->close();
        }
    }
    
    elseif (isset($_POST['update_instructor'])) {
        $instructor_id = intval($_POST['instructor_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        // Validate required fields
        if (empty($name) || empty($email) || $instructor_id <= 0) {
            $error_msg = "Required fields are missing.";
        } else {
            // Check if email already exists for another instructor
            $check_stmt = $conn->prepare("SELECT id FROM instructors WHERE email = ? AND id != ?");
            $check_stmt->bind_param("si", $email, $instructor_id);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $error_msg = "An instructor with this email already exists!";
            } else {
                // Prepare the update statement
                $stmt = $conn->prepare("UPDATE instructors SET name = ?, email = ?, phone = ?, specialization = ?, status = ? WHERE id = ?");
                if ($stmt === false) {
                    $error_msg = "Database error: " . $conn->error;
                } else {
                    $stmt->bind_param("sssssi", $name, $email, $phone, $specialization, $status, $instructor_id);
                    
                    if ($stmt->execute()) {
                        $success_msg = "Instructor updated successfully!";
                        
                        // Log the activity
                        logActivity($conn, $admin_id, 'Instructor Updated', "Updated instructor: $name (ID: $instructor_id)");
                    } else {
                        $error_msg = "Failed to update instructor: " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
            $check_stmt->close();
        }
    }
    
    elseif (isset($_POST['delete_instructor'])) {
        $instructor_id = intval($_POST['instructor_id'] ?? 0);
        
        if ($instructor_id <= 0) {
            $error_msg = "Invalid instructor ID.";
        } else {
            // Check if instructor has assigned classes
            $check_stmt = $conn->prepare("SELECT COUNT(*) as class_count FROM classes WHERE instructor_id = ?");
            $check_stmt->bind_param("i", $instructor_id);
            $check_stmt->execute();
            $check_stmt->bind_result($class_count);
            $check_stmt->fetch();
            $check_stmt->close();
            
            if ($class_count > 0) {
                $error_msg = "Cannot delete instructor. They have assigned classes. Please reassign or cancel the classes first.";
            } else {
                // Get instructor name for logging
                $stmt = $conn->prepare("SELECT name FROM instructors WHERE id = ?");
                $stmt->bind_param("i", $instructor_id);
                $stmt->execute();
                $stmt->bind_result($instructor_name);
                $stmt->fetch();
                $stmt->close();
                
                // Delete the instructor
                $stmt = $conn->prepare("DELETE FROM instructors WHERE id = ?");
                $stmt->bind_param("i", $instructor_id);
                
                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        $success_msg = "Instructor deleted successfully!";
                        
                        // Log the activity
                        logActivity($conn, $admin_id, 'Instructor Deleted', "Deleted instructor: $instructor_name (ID: $instructor_id)");
                    } else {
                        $error_msg = "Instructor not found or could not be deleted.";
                    }
                } else {
                    $error_msg = "Database error: " . $stmt->error;
                }
                $stmt->close();
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
    header("Location: instructors.php");
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
$specialization_filter = $_GET['specialization'] ?? 'all';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];
$param_types = '';

if (!empty($search)) {
    $where_conditions[] = "(name LIKE ? OR email LIKE ? OR phone LIKE ? OR specialization LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'ssss';
}

if ($status_filter !== 'all') {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}

if ($specialization_filter !== 'all' && !empty($specialization_filter)) {
    $where_conditions[] = "specialization = ?";
    $params[] = $specialization_filter;
    $param_types .= 's';
}

// Get instructors with pagination
try {
    // Build SQL query
    $sql = "SELECT SQL_CALC_FOUND_ROWS i.*, 
                   (SELECT COUNT(*) FROM classes WHERE instructor_id = i.id) as total_classes,
                   (SELECT COUNT(DISTINCT b.id) FROM classes c 
                    LEFT JOIN bookings b ON c.id = b.class_id 
                    WHERE c.instructor_id = i.id AND b.status = 'confirmed') as total_bookings
            FROM instructors i 
            WHERE " . implode(' AND ', $where_conditions) . " 
            ORDER BY name ASC 
            LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    $param_types .= 'ii';
    
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $instructors_result = $stmt->get_result();
    $instructors = $instructors_result->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    
    // Get total count for pagination
    $total_result = $conn->query("SELECT FOUND_ROWS() as total");
    $total_instructors = $total_result->fetch_assoc()['total'];
    $total_pages = ceil($total_instructors / $limit);
    
    // Get instructor statistics
    $total_instructors_all = $conn->query("SELECT COUNT(*) as total FROM instructors")->fetch_assoc()['total'];
    $active_instructors = $conn->query("SELECT COUNT(*) as total FROM instructors WHERE status = 'active'")->fetch_assoc()['total'];
    $inactive_instructors = $conn->query("SELECT COUNT(*) as total FROM instructors WHERE status = 'inactive'")->fetch_assoc()['total'];
    $new_this_month = $conn->query("SELECT COUNT(*) as total FROM instructors WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")->fetch_assoc()['total'];
    
    // Get total classes taught
    $total_classes_taught = $conn->query("SELECT COUNT(*) as total FROM classes WHERE instructor_id IS NOT NULL")->fetch_assoc()['total'];
    
    // Get specialization list
    $specializations_result = $conn->query("SELECT DISTINCT specialization FROM instructors WHERE specialization IS NOT NULL AND specialization != '' ORDER BY specialization ASC");
    $specializations = $specializations_result->fetch_all(MYSQLI_ASSOC) ?: [];
    
    // Get top instructors by bookings
    $top_instructors_stmt = $conn->prepare("
        SELECT i.name, i.specialization, 
               COUNT(DISTINCT b.id) as booking_count,
               COUNT(DISTINCT c.id) as class_count
        FROM instructors i
        LEFT JOIN classes c ON i.id = c.instructor_id
        LEFT JOIN bookings b ON c.id = b.class_id AND b.status = 'confirmed'
        WHERE i.status = 'active'
        GROUP BY i.id
        ORDER BY booking_count DESC
        LIMIT 5
    ");
    $top_instructors_stmt->execute();
    $top_instructors_result = $top_instructors_stmt->get_result();
    $top_instructors = $top_instructors_result->fetch_all(MYSQLI_ASSOC) ?: [];
    $top_instructors_stmt->close();
    
    // Get instructor growth this month
    $growth_stmt = $conn->prepare("
        SELECT 
            (SELECT COUNT(*) FROM instructors WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) as new_this_month,
            (SELECT COUNT(*) FROM instructors WHERE created_at < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) as previous_total
    ");
    $growth_stmt->execute();
    $growth_result = $growth_stmt->get_result();
    $growth = $growth_result->fetch_assoc() ?: ['new_this_month' => 0, 'previous_total' => 0];
    $growth_stmt->close();
    
    $growth_percentage = $growth['previous_total'] > 0 ? 
        round(($growth['new_this_month'] / $growth['previous_total']) * 100, 1) : 
        ($growth['new_this_month'] > 0 ? 100 : 0);
        
} catch (Exception $e) {
    error_log("Instructors query error: " . $e->getMessage());
    $instructors = [];
    $total_instructors = 0;
    $total_pages = 0;
    $total_instructors_all = $active_instructors = $inactive_instructors = $new_this_month = $total_classes_taught = 0;
    $specializations = [];
    $top_instructors = [];
    $growth_percentage = 0;
    $error_msg = $error_msg ?: "Unable to load instructor data. Please try again later.";
}

// Get current date and time
$current_date = date('l, F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Instructors | Elite Swimming Academy</title>
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
        
        /* Top Instructors */
        .top-instructors {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            border: 1px solid var(--gray-200);
            margin-bottom: 24px;
        }
        
        .top-instructors-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .top-instructors-header h4 {
            font-weight: 600;
            margin: 0;
            color: var(--gray-900);
            font-size: 16px;
        }
        
        .instructor-rankings {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .ranking-item {
            display: flex;
            align-items: center;
            padding: 12px;
            background-color: var(--gray-50);
            border-radius: 6px;
            border: 1px solid var(--gray-200);
        }
        
        .rank-number {
            width: 32px;
            height: 32px;
            background-color: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 500;
            margin-right: 12px;
            font-size: 14px;
            flex-shrink: 0;
        }
        
        .ranking-info {
            flex: 1;
        }
        
        .ranking-info h6 {
            font-weight: 500;
            margin-bottom: 4px;
            color: var(--gray-900);
            font-size: 14px;
        }
        
        .ranking-info p {
            font-size: 13px;
            color: var(--gray-600);
            margin: 0;
        }
        
        .ranking-stats {
            display: flex;
            gap: 16px;
            font-size: 12px;
        }
        
        .ranking-stat {
            text-align: center;
        }
        
        .ranking-stat span {
            display: block;
            font-weight: 600;
            color: var(--primary);
            font-size: 14px;
        }
        
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
        
        /* Instructor Avatar */
        .instructor-avatar {
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
        
        .badge-info {
            background-color: rgba(8, 145, 178, 0.1);
            color: var(--info);
        }
        
        .badge-warning {
            background-color: rgba(217, 119, 6, 0.1);
            color: var(--warning);
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
            
            .ranking-item {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
            
            .rank-number {
                margin-right: 0;
                margin-bottom: 8px;
            }
            
            .ranking-stats {
                justify-content: center;
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
                    <a href="students.php" class="nav-link">
                        <i class="bi bi-people"></i>
                        <span class="nav-text">Students</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="instructors.php" class="nav-link active">
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
                    <h1>Manage Instructors</h1>
                    <p>Total Instructors: <?= $total_instructors_all ?> • <?= $current_date ?></p>
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
                        <i class="bi bi-person-badge"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $total_instructors_all ?></h3>
                        <p>Total Instructors</p>
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
                        <h3><?= $active_instructors ?></h3>
                        <p>Active Instructors</p>
                        <div class="stat-trend">
                            <span><?= round(($active_instructors / max($total_instructors_all, 1)) * 100) ?>% of total</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-calendar-week"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $total_classes_taught ?></h3>
                        <p>Total Classes Taught</p>
                        <div class="stat-trend">
                            <span>All-time classes</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-arrow-up-right-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $new_this_month ?></h3>
                        <p>New This Month</p>
                        <div class="stat-trend">
                            <span>Recent hires</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Top Instructors -->
            <?php if (!empty($top_instructors)): ?>
                <div class="top-instructors">
                    <div class="top-instructors-header">
                        <h4>Top Instructors</h4>
                        <span class="text-muted" style="font-size: 14px;">By Booking Count</span>
                    </div>
                    <div class="instructor-rankings">
                        <?php $rank = 1; ?>
                        <?php foreach ($top_instructors as $instructor): ?>
                            <div class="ranking-item">
                                <div class="rank-number"><?= $rank++ ?></div>
                                <div class="ranking-info">
                                    <h6><?= htmlspecialchars($instructor['name']) ?></h6>
                                    <p><?= htmlspecialchars($instructor['specialization'] ?? 'General') ?></p>
                                </div>
                                <div class="ranking-stats">
                                    <div class="ranking-stat">
                                        <span><?= $instructor['booking_count'] ?></span>
                                        <small>Bookings</small>
                                    </div>
                                    <div class="ranking-stat">
                                        <span><?= $instructor['class_count'] ?></span>
                                        <small>Classes</small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-header">
                    <h3>Filter Instructors</h3>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addInstructorModal" style="font-size: 14px;">
                        <i class="bi bi-plus-circle me-2"></i> Add Instructor
                    </button>
                </div>
                <form method="GET" class="filter-grid">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Name, email, phone, or specialization...">
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
                        <label>Specialization</label>
                        <select class="form-select" name="specialization">
                            <option value="all" <?= $specialization_filter === 'all' ? 'selected' : '' ?>>All Specializations</option>
                            <?php foreach ($specializations as $spec): ?>
                                <option value="<?= htmlspecialchars($spec['specialization']) ?>" <?= $specialization_filter == $spec['specialization'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($spec['specialization']) ?>
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
            
            <!-- Instructors Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3>Instructor Directory</h3>
                    <div>
                        <span class="text-muted" style="font-size: 14px;">Showing <?= count($instructors) ?> of <?= $total_instructors ?> instructors</span>
                    </div>
                </div>
                
                <div class="table-wrapper">
                    <?php if (empty($instructors)): ?>
                        <div class="empty-state">
                            <i class="bi bi-person-badge"></i>
                            <h4>No Instructors Found</h4>
                            <p>No instructors match your search criteria. Try adjusting your filters.</p>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addInstructorModal" style="font-size: 14px;">
                                <i class="bi bi-plus-circle me-2"></i> Add New Instructor
                            </button>
                        </div>
                    <?php else: ?>
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Instructor</th>
                                    <th>Contact Info</th>
                                    <th>Specialization</th>
                                    <th>Classes/Bookings</th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($instructors as $instructor): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="instructor-avatar me-3">
                                                    <?= strtoupper(substr($instructor['name'], 0, 1)) ?>
                                                </div>
                                                <div>
                                                    <div class="fw-medium" style="font-size: 14px;"><?= htmlspecialchars($instructor['name']) ?></div>
                                                    <small class="text-muted" style="font-size: 12px;">ID: <?= $instructor['id'] ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-medium" style="font-size: 14px;"><?= htmlspecialchars($instructor['email']) ?></div>
                                            <?php if ($instructor['phone']): ?>
                                                <small class="text-muted" style="font-size: 12px;"><?= htmlspecialchars($instructor['phone']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($instructor['specialization']): ?>
                                                <span class="badge badge-info"><?= htmlspecialchars($instructor['specialization']) ?></span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">General</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-2">
                                                <span class="badge badge-warning" title="Classes Taught" style="font-size: 12px;">
                                                    <i class="bi bi-calendar-week me-1"></i><?= $instructor['total_classes'] ?>
                                                </span>
                                                <span class="badge badge-success" title="Total Bookings" style="font-size: 12px;">
                                                    <i class="bi bi-journal-check me-1"></i><?= $instructor['total_bookings'] ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge <?= $instructor['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>">
                                                <?= ucfirst($instructor['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= date('M j, Y', strtotime($instructor['created_at'])) ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-outline-primary btn-sm btn-icon" 
                                                        data-bs-toggle="modal" data-bs-target="#viewInstructorModal"
                                                        onclick="viewInstructor(<?= htmlspecialchars(json_encode($instructor)) ?>)"
                                                        title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button class="btn btn-outline-primary btn-sm btn-icon" 
                                                        data-bs-toggle="modal" data-bs-target="#editInstructorModal"
                                                        onclick="editInstructor(<?= htmlspecialchars(json_encode($instructor)) ?>)"
                                                        title="Edit Instructor">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <form method="POST" class="d-inline" onsubmit="return confirmDeleteInstructor(<?= $instructor['total_classes'] ?>);">
                                                    <input type="hidden" name="instructor_id" value="<?= $instructor['id'] ?>">
                                                    <button type="submit" name="delete_instructor" class="btn btn-outline-danger btn-sm btn-icon" title="Delete Instructor">
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
    
    <!-- Add Instructor Modal -->
    <div class="modal fade" id="addInstructorModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Instructor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="addInstructorForm">
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
                                <label class="form-label">Specialization</label>
                                <input type="text" class="form-control" name="specialization" maxlength="100" placeholder="e.g., Beginner Swimming, Advanced Techniques">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="active" selected>Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_instructor" class="btn btn-primary">Add Instructor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Instructor Modal -->
    <div class="modal fade" id="editInstructorModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Instructor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="editInstructorForm">
                    <input type="hidden" name="instructor_id" id="edit_instructor_id">
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
                                <label class="form-label">Specialization</label>
                                <input type="text" class="form-control" name="specialization" id="edit_specialization" maxlength="100">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="edit_status">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_instructor" class="btn btn-primary">Update Instructor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- View Instructor Modal -->
    <div class="modal fade" id="viewInstructorModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Instructor Details</h5>
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
                            <label class="form-label text-muted">Specialization</label>
                            <p id="view_specialization"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Status</label>
                            <p id="view_status"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Member Since</label>
                            <p id="view_created_at"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Total Classes</label>
                            <p id="view_total_classes" class="fw-medium"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Total Bookings</label>
                            <p id="view_total_bookings" class="fw-medium"></p>
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
            // Edit instructor function
            window.editInstructor = function(instructor) {
                document.getElementById('edit_instructor_id').value = instructor.id;
                document.getElementById('edit_name').value = instructor.name;
                document.getElementById('edit_email').value = instructor.email;
                document.getElementById('edit_phone').value = instructor.phone || '';
                document.getElementById('edit_specialization').value = instructor.specialization || '';
                document.getElementById('edit_status').value = instructor.status || 'active';
            }
            
            // View instructor function
            window.viewInstructor = function(instructor) {
                document.getElementById('view_name').textContent = instructor.name;
                document.getElementById('view_email').textContent = instructor.email;
                document.getElementById('view_phone').textContent = instructor.phone || 'Not provided';
                document.getElementById('view_specialization').textContent = instructor.specialization || 'General';
                document.getElementById('view_status').innerHTML = instructor.status === 'active' 
                    ? '<span class="badge badge-success">Active</span>' 
                    : '<span class="badge badge-danger">Inactive</span>';
                document.getElementById('view_created_at').textContent = instructor.created_at ? 
                    new Date(instructor.created_at).toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    }) : 'N/A';
                document.getElementById('view_total_classes').textContent = instructor.total_classes || '0';
                document.getElementById('view_total_bookings').textContent = instructor.total_bookings || '0';
            }
            
            // Confirm delete with class assignment check
            window.confirmDeleteInstructor = function(classCount) {
                if (classCount > 0) {
                    alert('Cannot delete instructor. They have assigned classes. Please reassign or cancel the classes first.');
                    return false;
                }
                return confirm('Are you sure you want to delete this instructor? This action cannot be undone.');
            }
            
            // Form validation
            const addForm = document.getElementById('addInstructorForm');
            const editForm = document.getElementById('editInstructorForm');
            
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
            
            // Update specialization filter options
            const specializationSelect = document.querySelector('select[name="specialization"]');
            if (specializationSelect) {
                const currentValue = specializationSelect.value;
                // This would typically be populated from an API call
                // For now, we'll keep it as is since we're loading from PHP
            }
        });
    </script>
</body>
</html>