<?php
// admin/classes.php - Professional Classes Management
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
    if (isset($_POST['add_class'])) {
        $title = trim($_POST['title'] ?? '');
        $age_group = trim($_POST['age_group'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $instructor_id = isset($_POST['instructor_id']) && $_POST['instructor_id'] !== '' ? intval($_POST['instructor_id']) : null;
        $slots_total = intval($_POST['slots_total'] ?? 10);
        $start_time = trim($_POST['start_time'] ?? '');
        $end_time = trim($_POST['end_time'] ?? '');
        $price = floatval($_POST['price'] ?? 0.00);
        
        // Validate required fields
        if (empty($title) || empty($age_group) || empty($start_time) || empty($end_time)) {
            $error_msg = "Title, age group, and schedule times are required fields.";
        } elseif ($slots_total < 1) {
            $error_msg = "Total slots must be at least 1.";
        } elseif ($price < 0) {
            $error_msg = "Price cannot be negative.";
        } else {
            // Validate time logic
            if (strtotime($start_time) >= strtotime($end_time)) {
                $error_msg = "End time must be after start time.";
            } else {
                // Check for overlapping classes for the same instructor
                if ($instructor_id) {
                    $check_stmt = $conn->prepare("
                        SELECT id, title 
                        FROM classes 
                        WHERE instructor_id = ? 
                        AND status = 'scheduled'
                        AND (
                            (start_time <= ? AND end_time >= ?) OR
                            (start_time <= ? AND end_time >= ?) OR
                            (start_time >= ? AND end_time <= ?)
                        )
                        AND id != ?
                    ");
                    $exclude_id = 0; // For new class, id is 0
                    $check_stmt->bind_param("issssssi", 
                        $instructor_id, 
                        $start_time, $start_time,
                        $end_time, $end_time,
                        $start_time, $end_time,
                        $exclude_id
                    );
                    $check_stmt->execute();
                    $check_stmt->store_result();
                    
                    if ($check_stmt->num_rows > 0) {
                        $error_msg = "This instructor already has a scheduled class during this time period.";
                    }
                    $check_stmt->close();
                }
                
                if (!$error_msg) {
                    // Prepare the insert statement
                    $stmt = $conn->prepare("INSERT INTO classes (title, age_group, description, instructor_id, slots_total, slots_available, start_time, end_time, price, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', NOW())");
                    if ($stmt === false) {
                        $error_msg = "Database error: " . $conn->error;
                    } else {
                        $stmt->bind_param("sssiiissd", $title, $age_group, $description, $instructor_id, $slots_total, $slots_total, $start_time, $end_time, $price);
                        
                        if ($stmt->execute()) {
                            $class_id = $stmt->insert_id;
                            $success_msg = "Class created successfully!";
                            
                            // Log the activity
                            logActivity($conn, $admin_id, 'Class Created', "Created class: $title (ID: $class_id)");
                        } else {
                            $error_msg = "Failed to create class: " . $stmt->error;
                        }
                        $stmt->close();
                    }
                }
            }
        }
    }
    
    elseif (isset($_POST['update_class'])) {
        $class_id = intval($_POST['class_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $age_group = trim($_POST['age_group'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $instructor_id = isset($_POST['instructor_id']) && $_POST['instructor_id'] !== '' ? intval($_POST['instructor_id']) : null;
        $slots_total = intval($_POST['slots_total'] ?? 10);
        $start_time = trim($_POST['start_time'] ?? '');
        $end_time = trim($_POST['end_time'] ?? '');
        $price = floatval($_POST['price'] ?? 0.00);
        $status = $_POST['status'] ?? 'scheduled';
        
        // Validate required fields
        if (empty($title) || empty($age_group) || empty($start_time) || empty($end_time) || $class_id <= 0) {
            $error_msg = "Required fields are missing.";
        } elseif ($slots_total < 1) {
            $error_msg = "Total slots must be at least 1.";
        } elseif ($price < 0) {
            $error_msg = "Price cannot be negative.";
        } else {
            // Get current slots to ensure we don't reduce below enrolled students
            $current_stmt = $conn->prepare("SELECT slots_total, slots_available FROM classes WHERE id = ?");
            $current_stmt->bind_param("i", $class_id);
            $current_stmt->execute();
            $current_stmt->bind_result($current_slots_total, $current_slots_available);
            $current_stmt->fetch();
            $current_stmt->close();
            
            $currently_enrolled = $current_slots_total - $current_slots_available;
            
            if ($slots_total < $currently_enrolled) {
                $error_msg = "Cannot reduce total slots below currently enrolled students ($currently_enrolled enrolled).";
            } else {
                // Validate time logic
                if (strtotime($start_time) >= strtotime($end_time)) {
                    $error_msg = "End time must be after start time.";
                } else {
                    // Check for overlapping classes for the same instructor (excluding current class)
                    if ($instructor_id) {
                        $check_stmt = $conn->prepare("
                            SELECT id, title 
                            FROM classes 
                            WHERE instructor_id = ? 
                            AND status = 'scheduled'
                            AND id != ?
                            AND (
                                (start_time <= ? AND end_time >= ?) OR
                                (start_time <= ? AND end_time >= ?) OR
                                (start_time >= ? AND end_time <= ?)
                            )
                        ");
                        $check_stmt->bind_param("iissssss", 
                            $instructor_id,
                            $class_id,
                            $start_time, $start_time,
                            $end_time, $end_time,
                            $start_time, $end_time
                        );
                        $check_stmt->execute();
                        $check_stmt->store_result();
                        
                        if ($check_stmt->num_rows > 0) {
                            $check_stmt->bind_result($overlap_id, $overlap_title);
                            $check_stmt->fetch();
                            $error_msg = "This instructor already has a scheduled class during this time period: $overlap_title (ID: $overlap_id)";
                        }
                        $check_stmt->close();
                    }
                    
                    if (!$error_msg) {
                        // Calculate new slots_available
                        $new_slots_available = $slots_total - $currently_enrolled;
                        
                        // Prepare the update statement
                        $stmt = $conn->prepare("UPDATE classes SET title = ?, age_group = ?, description = ?, instructor_id = ?, slots_total = ?, slots_available = ?, start_time = ?, end_time = ?, price = ?, status = ? WHERE id = ?");
                        if ($stmt === false) {
                            $error_msg = "Database error: " . $conn->error;
                        } else {
                            $stmt->bind_param("sssiiissdsi", $title, $age_group, $description, $instructor_id, $slots_total, $new_slots_available, $start_time, $end_time, $price, $status, $class_id);
                            
                            if ($stmt->execute()) {
                                $success_msg = "Class updated successfully!";
                                
                                // Log the activity
                                logActivity($conn, $admin_id, 'Class Updated', "Updated class: $title (ID: $class_id)");
                            } else {
                                $error_msg = "Failed to update class: " . $stmt->error;
                            }
                            $stmt->close();
                        }
                    }
                }
            }
        }
    }
    
    elseif (isset($_POST['delete_class'])) {
        $class_id = intval($_POST['class_id'] ?? 0);
        
        if ($class_id <= 0) {
            $error_msg = "Invalid class ID.";
        } else {
            // Check if class has bookings
            $check_stmt = $conn->prepare("SELECT COUNT(*) as booking_count FROM bookings WHERE class_id = ?");
            $check_stmt->bind_param("i", $class_id);
            $check_stmt->execute();
            $check_stmt->bind_result($booking_count);
            $check_stmt->fetch();
            $check_stmt->close();
            
            if ($booking_count > 0) {
                $error_msg = "Cannot delete class. There are bookings for this class. Please cancel or reassign bookings first.";
            } else {
                // Get class title for logging
                $stmt = $conn->prepare("SELECT title FROM classes WHERE id = ?");
                $stmt->bind_param("i", $class_id);
                $stmt->execute();
                $stmt->bind_result($class_title);
                $stmt->fetch();
                $stmt->close();
                
                // Delete the class
                $stmt = $conn->prepare("DELETE FROM classes WHERE id = ?");
                $stmt->bind_param("i", $class_id);
                
                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        $success_msg = "Class deleted successfully!";
                        
                        // Log the activity
                        logActivity($conn, $admin_id, 'Class Deleted', "Deleted class: $class_title (ID: $class_id)");
                    } else {
                        $error_msg = "Class not found or could not be deleted.";
                    }
                } else {
                    $error_msg = "Database error: " . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
    
    elseif (isset($_POST['cancel_class'])) {
        $class_id = intval($_POST['class_id'] ?? 0);
        
        if ($class_id <= 0) {
            $error_msg = "Invalid class ID.";
        } else {
            // Get class title for logging
            $stmt = $conn->prepare("SELECT title FROM classes WHERE id = ?");
            $stmt->bind_param("i", $class_id);
            $stmt->execute();
            $stmt->bind_result($class_title);
            $stmt->fetch();
            $stmt->close();
            
            // Update class status to cancelled
            $stmt = $conn->prepare("UPDATE classes SET status = 'cancelled' WHERE id = ?");
            $stmt->bind_param("i", $class_id);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $success_msg = "Class cancelled successfully! All bookings will be marked as cancelled.";
                    
                    // Update all bookings for this class
                    $update_bookings = $conn->prepare("UPDATE bookings SET status = 'cancelled' WHERE class_id = ?");
                    $update_bookings->bind_param("i", $class_id);
                    $update_bookings->execute();
                    $update_bookings->close();
                    
                    // Log the activity
                    logActivity($conn, $admin_id, 'Class Cancelled', "Cancelled class: $class_title (ID: $class_id)");
                } else {
                    $error_msg = "Class not found or could not be cancelled.";
                }
            } else {
                $error_msg = "Database error: " . $stmt->error;
            }
            $stmt->close();
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
    header("Location: classes.php");
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
$instructor_filter = $_GET['instructor'] ?? 'all';
$age_group_filter = $_GET['age_group'] ?? 'all';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];
$param_types = '';

if (!empty($search)) {
    $where_conditions[] = "(c.title LIKE ? OR c.description LIKE ? OR c.age_group LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'sss';
}

if ($status_filter !== 'all') {
    $where_conditions[] = "c.status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}

if ($instructor_filter !== 'all' && !empty($instructor_filter)) {
    $where_conditions[] = "c.instructor_id = ?";
    $params[] = $instructor_filter;
    $param_types .= 'i';
}

if ($age_group_filter !== 'all' && !empty($age_group_filter)) {
    $where_conditions[] = "c.age_group = ?";
    $params[] = $age_group_filter;
    $param_types .= 's';
}

// Get classes with pagination
try {
    // Build SQL query
    $sql = "SELECT SQL_CALC_FOUND_ROWS c.*, 
                   i.name AS instructor_name,
                   i.email AS instructor_email,
                   i.phone AS instructor_phone,
                   (SELECT COUNT(*) FROM bookings WHERE class_id = c.id AND status IN ('confirmed', 'pending')) as enrolled_count
            FROM classes c 
            LEFT JOIN instructors i ON c.instructor_id = i.id 
            WHERE " . implode(' AND ', $where_conditions) . " 
            ORDER BY c.start_time ASC
            LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    $param_types .= 'ii';
    
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $classes_result = $stmt->get_result();
    $classes = $classes_result->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    
    // Get total count for pagination
    $total_result = $conn->query("SELECT FOUND_ROWS() as total");
    $total_classes = $total_result->fetch_assoc()['total'];
    $total_pages = ceil($total_classes / $limit);
    
    // Get class statistics
    $total_classes_all = $conn->query("SELECT COUNT(*) as total FROM classes")->fetch_assoc()['total'];
    $scheduled_classes = $conn->query("SELECT COUNT(*) as total FROM classes WHERE status = 'scheduled'")->fetch_assoc()['total'];
    $in_progress_classes = $conn->query("SELECT COUNT(*) as total FROM classes WHERE status = 'in-progress'")->fetch_assoc()['total'];
    $completed_classes = $conn->query("SELECT COUNT(*) as total FROM classes WHERE status = 'completed'")->fetch_assoc()['total'];
    $cancelled_classes = $conn->query("SELECT COUNT(*) as total FROM classes WHERE status = 'cancelled'")->fetch_assoc()['total'];
    
    // Get capacity statistics
    $capacity_stats = $conn->query("
        SELECT 
            SUM(slots_total) as total_capacity,
            SUM(slots_total - slots_available) as total_enrolled,
            AVG((slots_total - slots_available) / slots_total * 100) as avg_utilization
        FROM classes 
        WHERE status = 'scheduled'
    ")->fetch_assoc() ?: ['total_capacity' => 0, 'total_enrolled' => 0, 'avg_utilization' => 0];
    
    // Get weekly classes
    $week_start = date('Y-m-d', strtotime('monday this week'));
    $week_end = date('Y-m-d', strtotime('sunday this week'));
    $weekly_classes = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM classes 
        WHERE DATE(start_time) BETWEEN ? AND ?
    ");
    $weekly_classes->bind_param("ss", $week_start, $week_end);
    $weekly_classes->execute();
    $weekly_classes->bind_result($weekly_total);
    $weekly_classes->fetch();
    $weekly_classes->close();
    
    // Get instructors for filter
    $instructors = $conn->query("SELECT id, name FROM instructors WHERE status = 'active' ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC) ?: [];
    
    // Get unique age groups
    $age_groups = $conn->query("SELECT DISTINCT age_group FROM classes WHERE age_group IS NOT NULL AND age_group != '' ORDER BY age_group ASC")->fetch_all(MYSQLI_ASSOC) ?: [];
    
    // Get upcoming classes for quick stats
    $upcoming_stmt = $conn->prepare("
        SELECT c.*, i.name as instructor_name
        FROM classes c
        LEFT JOIN instructors i ON c.instructor_id = i.id
        WHERE c.start_time >= NOW() 
        AND c.status = 'scheduled'
        ORDER BY c.start_time ASC
        LIMIT 5
    ");
    $upcoming_stmt->execute();
    $upcoming_result = $upcoming_stmt->get_result();
    $upcoming_classes = $upcoming_result->fetch_all(MYSQLI_ASSOC) ?: [];
    $upcoming_stmt->close();
    
    // Get revenue from classes
    $revenue_stmt = $conn->prepare("
        SELECT 
            SUM(p.amount) as total_revenue,
            COUNT(DISTINCT p.id) as total_payments
        FROM payments p
        INNER JOIN bookings b ON p.booking_id = b.id
        INNER JOIN classes c ON b.class_id = c.id
        WHERE p.status = 'paid'
        AND c.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $revenue_stmt->execute();
    $revenue_result = $revenue_stmt->get_result();
    $revenue_stats = $revenue_result->fetch_assoc() ?: ['total_revenue' => 0, 'total_payments' => 0];
    $revenue_stmt->close();
    
} catch (Exception $e) {
    error_log("Classes query error: " . $e->getMessage());
    $classes = [];
    $total_classes = 0;
    $total_pages = 0;
    $total_classes_all = $scheduled_classes = $in_progress_classes = $completed_classes = $cancelled_classes = 0;
    $capacity_stats = ['total_capacity' => 0, 'total_enrolled' => 0, 'avg_utilization' => 0];
    $weekly_total = 0;
    $instructors = [];
    $age_groups = [];
    $upcoming_classes = [];
    $revenue_stats = ['total_revenue' => 0, 'total_payments' => 0];
    $error_msg = $error_msg ?: "Unable to load class data. Please try again later.";
}

// Get current date and time
$current_date = date('l, F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Classes | Elite Swimming Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0d6efd;
            --primary-dark: #0a58ca;
            --success: #198754;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #0dcaf0;
            --light: #f8f9fa;
            --dark: #212529;
            --purple: #6f42c1;
            --pink: #d63384;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
            min-height: 100vh;
            color: #333;
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: 260px;
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 1000;
            padding: 20px 0;
        }
        
        .logo-area {
            padding: 0 25px 25px;
            border-bottom: 1px solid #eee;
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
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--purple) 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }
        
        .logo-text h3 {
            font-weight: 700;
            font-size: 22px;
            margin: 0;
            background: linear-gradient(90deg, var(--primary), var(--purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .logo-text span {
            font-size: 12px;
            color: #6c757d;
        }
        
        .nav-menu {
            padding: 0 15px;
        }
        
        .nav-item {
            margin-bottom: 5px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            border-radius: 10px;
            color: #495057;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .nav-link:hover {
            background: rgba(13, 110, 253, 0.1);
            color: var(--primary);
            transform: translateX(5px);
        }
        
        .nav-link.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(13, 110, 253, 0.2);
        }
        
        .nav-link.active:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, #0a3d9c 100%);
        }
        
        .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 18px;
        }
        
        .logout-section {
            padding: 20px;
            position: absolute;
            bottom: 0;
            width: 100%;
            border-top: 1px solid #eee;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 30px;
        }
        
        /* Header */
        .header {
            background: white;
            border-radius: 15px;
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left h1 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
            background: linear-gradient(90deg, var(--primary), var(--purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .header-left p {
            color: #6c757d;
            margin: 0;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 15px;
            background: var(--light);
            padding: 12px 20px;
            border-radius: 10px;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
        }
        
        .user-info h5 {
            font-weight: 600;
            margin: 0;
        }
        
        .user-info p {
            color: #6c757d;
            font-size: 14px;
            margin: 0;
        }
        
        /* Alerts */
        .alert-custom {
            border-radius: 12px;
            border: none;
            padding: 20px 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            animation: slideIn 0.5s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            border: 1px solid #e9ecef;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
        }
        
        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 20px;
            color: white;
        }
        
        .stat-card:nth-child(1) .stat-icon { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-card:nth-child(2) .stat-icon { background: linear-gradient(135deg, var(--success) 0%, #157347 100%); }
        .stat-card:nth-child(3) .stat-icon { background: linear-gradient(135deg, var(--warning) 0%, #ffca2c 100%); }
        .stat-card:nth-child(4) .stat-icon { background: linear-gradient(135deg, var(--info) 0%, #0891b2 100%); }
        .stat-card:nth-child(5) .stat-icon { background: linear-gradient(135deg, var(--purple) 0%, #5936a0 100%); }
        
        .stat-content h3 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--dark);
        }
        
        .stat-content p {
            color: #6c757d;
            font-size: 14px;
            margin: 0;
        }
        
        .stat-subtext {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
        }
        
        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            border: 1px solid #e9ecef;
        }
        
        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .filter-header h3 {
            font-size: 20px;
            font-weight: 600;
            margin: 0;
            color: var(--dark);
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .form-group label {
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--dark);
        }
        
        /* Table Container */
        .table-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            overflow: hidden;
            border: 1px solid #e9ecef;
            margin-bottom: 30px;
        }
        
        .table-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-header h3 {
            font-size: 20px;
            font-weight: 600;
            margin: 0;
            color: var(--dark);
        }
        
        .table-wrapper {
            overflow-x: auto;
        }
        
        .table {
            margin: 0;
            width: 100%;
        }
        
        .table thead {
            background: #f8f9fa;
        }
        
        .table th {
            font-weight: 600;
            color: #495057;
            padding: 15px 20px;
            border-bottom: 2px solid #e9ecef;
            white-space: nowrap;
        }
        
        .table td {
            padding: 15px 20px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
        }
        
        .table tbody tr {
            transition: all 0.2s ease;
        }
        
        .table tbody tr:hover {
            background: #f8f9fa;
        }
        
        /* Class Status */
        .class-status {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        /* Status Badges */
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 12px;
        }
        
        .badge-success {
            background: rgba(25, 135, 84, 0.1);
            color: var(--success);
        }
        
        .badge-warning {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning);
        }
        
        .badge-danger {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
        }
        
        .badge-info {
            background: rgba(13, 202, 240, 0.1);
            color: var(--info);
        }
        
        .badge-primary {
            background: rgba(13, 110, 253, 0.1);
            color: var(--primary);
        }
        
        .badge-secondary {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
        }
        
        /* Progress Bar */
        .progress {
            height: 6px;
            background-color: #e9ecef;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            border-radius: 3px;
        }
        
        .capacity-low { background: var(--success); }
        .capacity-medium { background: var(--warning); }
        .capacity-high { background: var(--danger); }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-icon {
            width: 32px;
            height: 32px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state i {
            font-size: 64px;
            color: #dee2e6;
            margin-bottom: 20px;
        }
        
        .empty-state h4 {
            font-weight: 600;
            color: #6c757d;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: #6c757d;
            margin-bottom: 20px;
        }
        
        /* Pagination */
        .pagination-container {
            padding: 20px 25px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: center;
        }
        
        .pagination {
            margin: 0;
        }
        
        .page-link {
            border: 1px solid #dee2e6;
            color: var(--primary);
            padding: 8px 16px;
            border-radius: 8px;
            margin: 0 2px;
        }
        
        .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        
        /* Upcoming Classes */
        .upcoming-classes {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            border: 1px solid #e9ecef;
        }
        
        .upcoming-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .upcoming-header h4 {
            font-weight: 600;
            margin: 0;
            color: var(--dark);
        }
        
        .upcoming-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .upcoming-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        
        .upcoming-item:hover {
            background: #e9ecef;
        }
        
        .upcoming-info h6 {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--dark);
        }
        
        .upcoming-info p {
            font-size: 13px;
            color: #6c757d;
            margin: 0;
        }
        
        .upcoming-time {
            text-align: right;
        }
        
        .upcoming-time .date {
            font-weight: 600;
            color: var(--primary);
        }
        
        .upcoming-time .time {
            font-size: 13px;
            color: #6c757d;
        }
        
        /* Modal */
        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border-bottom: none;
            border-radius: 15px 15px 0 0;
            padding: 25px;
        }
        
        .modal-title {
            font-weight: 600;
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .modal-footer {
            border-top: 1px solid #e9ecef;
            padding: 20px 25px;
        }
        
        .form-label {
            font-weight: 500;
            margin-bottom: 8px;
        }
        
        .form-label.required::after {
            content: ' *';
            color: var(--danger);
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease forwards;
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                width: 70px;
            }
            
            .main-content {
                margin-left: 70px;
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
                padding: 20px;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .table-header {
                flex-direction: column;
                gap: 15px;
            }
            
            .action-buttons {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .upcoming-item {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            
            .upcoming-time {
                text-align: center;
            }
        }
        
        @media (max-width: 576px) {
            .table-wrapper {
                font-size: 14px;
            }
            
            .table th, .table td {
                padding: 10px;
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
                    <a href="instructors.php" class="nav-link">
                        <i class="bi bi-person-badge"></i>
                        <span class="nav-text">Instructors</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="classes.php" class="nav-link active">
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
                    <button type="submit" name="confirm_logout" value="1" class="nav-link btn" style="background:none;border:none;width:100%;text-align:left;padding:12px 15px;">
                        <i class="bi bi-box-arrow-right"></i>
                        <span class="nav-text">Logout</span>
                    </button>
                </form>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header fade-in">
                <div class="header-left">
                    <h1>Manage Classes</h1>
                    <p>Total Classes: <?= $total_classes_all ?> • <?= $current_date ?></p>
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
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-calendar-week"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $total_classes_all ?></h3>
                        <p>Total Classes</p>
                        <div class="stat-subtext">All-time classes</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $scheduled_classes ?></h3>
                        <p>Scheduled Classes</p>
                        <div class="stat-subtext">Upcoming sessions</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-people"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $capacity_stats['total_enrolled'] ?></h3>
                        <p>Students Enrolled</p>
                        <div class="stat-subtext">Active bookings</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-bar-chart"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= number_format($capacity_stats['avg_utilization'] ?? 0, 1) ?>%</h3>
                        <p>Avg Utilization</p>
                        <div class="stat-subtext">Capacity usage</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                    <div class="stat-content">
                        <h3>$<?= number_format($revenue_stats['total_revenue'] ?? 0, 2) ?></h3>
                        <p>30-Day Revenue</p>
                        <div class="stat-subtext">From class payments</div>
                    </div>
                </div>
            </div>
            
            <!-- Upcoming Classes -->
            <?php if (!empty($upcoming_classes)): ?>
                <div class="upcoming-classes fade-in">
                    <div class="upcoming-header">
                        <h4>Upcoming Classes</h4>
                        <a href="?status=scheduled" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                    <div class="upcoming-list">
                        <?php foreach ($upcoming_classes as $class): 
                            $utilization = $class['slots_total'] > 0 ? 
                                (($class['slots_total'] - $class['slots_available']) / $class['slots_total']) * 100 : 0;
                        ?>
                            <div class="upcoming-item">
                                <div class="upcoming-info">
                                    <h6><?= htmlspecialchars($class['title']) ?></h6>
                                    <p>
                                        <?= htmlspecialchars($class['instructor_name'] ?? 'Unassigned') ?> • 
                                        <?= htmlspecialchars($class['age_group']) ?> • 
                                        <?= round($utilization) ?>% full
                                    </p>
                                </div>
                                <div class="upcoming-time">
                                    <div class="date"><?= date('D, M j', strtotime($class['start_time'])) ?></div>
                                    <div class="time"><?= date('g:i A', strtotime($class['start_time'])) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Filter Section -->
            <div class="filter-section fade-in">
                <div class="filter-header">
                    <h3>Filter Classes</h3>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClassModal">
                        <i class="bi bi-plus-circle me-2"></i> Add Class
                    </button>
                </div>
                <form method="GET" class="filter-grid">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Title, description, or age group...">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select class="form-select" name="status">
                            <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                            <option value="scheduled" <?= $status_filter === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                            <option value="in-progress" <?= $status_filter === 'in-progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Instructor</label>
                        <select class="form-select" name="instructor">
                            <option value="all" <?= $instructor_filter === 'all' ? 'selected' : '' ?>>All Instructors</option>
                            <?php foreach ($instructors as $instructor): ?>
                                <option value="<?= $instructor['id'] ?>" <?= $instructor_filter == $instructor['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($instructor['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Age Group</label>
                        <select class="form-select" name="age_group">
                            <option value="all" <?= $age_group_filter === 'all' ? 'selected' : '' ?>>All Age Groups</option>
                            <?php foreach ($age_groups as $group): ?>
                                <option value="<?= htmlspecialchars($group['age_group']) ?>" <?= $age_group_filter == $group['age_group'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($group['age_group']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-funnel me-2"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Classes Table -->
            <div class="table-container fade-in">
                <div class="table-header">
                    <h3>Class Schedule</h3>
                    <div>
                        <span class="text-muted">Showing <?= count($classes) ?> of <?= $total_classes ?> classes</span>
                    </div>
                </div>
                
                <div class="table-wrapper">
                    <?php if (empty($classes)): ?>
                        <div class="empty-state">
                            <i class="bi bi-calendar-x"></i>
                            <h4>No Classes Found</h4>
                            <p>No classes match your search criteria. Try adjusting your filters.</p>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClassModal">
                                <i class="bi bi-plus-circle me-2"></i> Add New Class
                            </button>
                        </div>
                    <?php else: ?>
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Class Details</th>
                                    <th>Schedule</th>
                                    <th>Instructor</th>
                                    <th>Capacity</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classes as $class): 
                                    // Calculate capacity percentage
                                    $capacity_percentage = $class['slots_total'] > 0 ? 
                                        (($class['slots_total'] - $class['slots_available']) / $class['slots_total']) * 100 : 0;
                                    
                                    // Determine capacity color
                                    $capacity_color = $capacity_percentage >= 90 ? 'high' : 
                                                    ($capacity_percentage >= 75 ? 'medium' : 'low');
                                    
                                    // Determine status badge
                                    $status_badge = '';
                                    switch ($class['status']) {
                                        case 'scheduled':
                                            $status_badge = 'badge-primary';
                                            break;
                                        case 'in-progress':
                                            $status_badge = 'badge-warning';
                                            break;
                                        case 'completed':
                                            $status_badge = 'badge-success';
                                            break;
                                        case 'cancelled':
                                            $status_badge = 'badge-danger';
                                            break;
                                        default:
                                            $status_badge = 'badge-secondary';
                                    }
                                ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-start">
                                                <div>
                                                    <div class="fw-semibold"><?= htmlspecialchars($class['title']) ?></div>
                                                    <div class="text-muted" style="font-size: 13px;">
                                                        <?= htmlspecialchars($class['age_group']) ?>
                                                    </div>
                                                    <?php if (!empty($class['description'])): ?>
                                                        <div class="text-muted mt-1" style="font-size: 12px;">
                                                            <?= htmlspecialchars(substr($class['description'], 0, 60)) ?>...
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-medium"><?= date('M j, Y', strtotime($class['start_time'])) ?></div>
                                            <div class="text-muted" style="font-size: 13px;">
                                                <?= date('g:i A', strtotime($class['start_time'])) ?> - <?= date('g:i A', strtotime($class['end_time'])) ?>
                                            </div>
                                            <div class="text-muted" style="font-size: 12px;">
                                                $<?= number_format($class['price'], 2) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($class['instructor_name']): ?>
                                                <div class="fw-medium"><?= htmlspecialchars($class['instructor_name']) ?></div>
                                                <div class="text-muted" style="font-size: 12px;">
                                                    <?= htmlspecialchars($class['instructor_email'] ?? '') ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="class-status">
                                                <div>
                                                    <span class="fw-medium"><?= $class['slots_total'] - $class['slots_available'] ?>/<?= $class['slots_total'] ?></span>
                                                    <span class="text-muted" style="font-size: 12px;">
                                                        (<?= round($capacity_percentage) ?>%)
                                                    </span>
                                                </div>
                                                <div class="progress" style="width: 100px;">
                                                    <div class="progress-bar capacity-<?= $capacity_color ?>" style="width: <?= $capacity_percentage ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge <?= $status_badge ?>">
                                                <?= ucfirst(str_replace('-', ' ', $class['status'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-outline-primary btn-sm btn-icon" 
                                                        data-bs-toggle="modal" data-bs-target="#viewClassModal"
                                                        onclick="viewClass(<?= htmlspecialchars(json_encode($class)) ?>)"
                                                        title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <?php if ($class['status'] !== 'completed' && $class['status'] !== 'cancelled'): ?>
                                                    <button class="btn btn-outline-primary btn-sm btn-icon" 
                                                            data-bs-toggle="modal" data-bs-target="#editClassModal"
                                                            onclick="editClass(<?= htmlspecialchars(json_encode($class)) ?>)"
                                                            title="Edit Class">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($class['status'] === 'scheduled'): ?>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to cancel this class? All bookings will be cancelled.');">
                                                        <input type="hidden" name="class_id" value="<?= $class['id'] ?>">
                                                        <button type="submit" name="cancel_class" class="btn btn-outline-warning btn-sm btn-icon" title="Cancel Class">
                                                            <i class="bi bi-x-circle"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($class['enrolled_count'] == 0): ?>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this class? This action cannot be undone.');">
                                                        <input type="hidden" name="class_id" value="<?= $class['id'] ?>">
                                                        <button type="submit" name="delete_class" class="btn btn-outline-danger btn-sm btn-icon" title="Delete Class">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
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
    
    <!-- Add Class Modal -->
    <div class="modal fade" id="addClassModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Class</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="addClassForm">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required">Class Title</label>
                                <input type="text" class="form-control" name="title" required maxlength="100" placeholder="e.g., Beginner Swimming">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">Age Group</label>
                                <input type="text" class="form-control" name="age_group" required maxlength="50" placeholder="e.g., Kids (4-7)">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="3" placeholder="Describe the class content, objectives, etc."></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Instructor</label>
                                <select class="form-select" name="instructor_id">
                                    <option value="">Select Instructor (Optional)</option>
                                    <?php foreach ($instructors as $instructor): ?>
                                        <option value="<?= $instructor['id'] ?>"><?= htmlspecialchars($instructor['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">Total Slots</label>
                                <input type="number" class="form-control" name="slots_total" required min="1" max="100" value="10">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">Start Time</label>
                                <input type="datetime-local" class="form-control" name="start_time" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">End Time</label>
                                <input type="datetime-local" class="form-control" name="end_time" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">Price ($)</label>
                                <input type="number" class="form-control" name="price" required step="0.01" min="0" value="50.00">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_class" class="btn btn-primary">Create Class</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Class Modal -->
    <div class="modal fade" id="editClassModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Class</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="editClassForm">
                    <input type="hidden" name="class_id" id="edit_class_id">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required">Class Title</label>
                                <input type="text" class="form-control" name="title" id="edit_title" required maxlength="100">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">Age Group</label>
                                <input type="text" class="form-control" name="age_group" id="edit_age_group" required maxlength="50">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Instructor</label>
                                <select class="form-select" name="instructor_id" id="edit_instructor_id">
                                    <option value="">Select Instructor (Optional)</option>
                                    <?php foreach ($instructors as $instructor): ?>
                                        <option value="<?= $instructor['id'] ?>"><?= htmlspecialchars($instructor['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">Total Slots</label>
                                <input type="number" class="form-control" name="slots_total" id="edit_slots_total" required min="1" max="100">
                                <small class="text-muted" id="edit_slots_hint"></small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">Start Time</label>
                                <input type="datetime-local" class="form-control" name="start_time" id="edit_start_time" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">End Time</label>
                                <input type="datetime-local" class="form-control" name="end_time" id="edit_end_time" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">Price ($)</label>
                                <input type="number" class="form-control" name="price" id="edit_price" required step="0.01" min="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">Status</label>
                                <select class="form-select" name="status" id="edit_status">
                                    <option value="scheduled">Scheduled</option>
                                    <option value="in-progress">In Progress</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_class" class="btn btn-primary">Update Class</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- View Class Modal -->
    <div class="modal fade" id="viewClassModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Class Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted">Class Title</label>
                            <p class="fw-semibold" id="view_title"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Age Group</label>
                            <p id="view_age_group"></p>
                        </div>
                        <div class="col-12">
                            <label class="form-label text-muted">Description</label>
                            <div class="border rounded p-3 bg-light">
                                <p id="view_description" class="mb-0"></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Instructor</label>
                            <p id="view_instructor"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Schedule</label>
                            <p id="view_schedule"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Capacity</label>
                            <p id="view_capacity"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Price</label>
                            <p id="view_price"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Status</label>
                            <p id="view_status"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Enrolled Students</label>
                            <p id="view_enrolled"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Created</label>
                            <p id="view_created_at"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Last Updated</label>
                            <p id="view_updated_at"></p>
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
            // Set default datetime values for new class modal
            const now = new Date();
            const tomorrow = new Date(now.getTime() + 24 * 60 * 60 * 1000);
            tomorrow.setHours(10, 0, 0, 0); // Set to 10:00 AM
            
            const endTime = new Date(tomorrow.getTime() + 60 * 60 * 1000); // +1 hour
            
            // Format for datetime-local input
            const formatDateTime = (date) => {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                const hours = String(date.getHours()).padStart(2, '0');
                const minutes = String(date.getMinutes()).padStart(2, '0');
                return `${year}-${month}-${day}T${hours}:${minutes}`;
            };
            
            // Set default values when modal opens
            const addModal = document.getElementById('addClassModal');
            if (addModal) {
                addModal.addEventListener('show.bs.modal', function() {
                    const form = this.querySelector('form');
                    const startInput = form.querySelector('input[name="start_time"]');
                    const endInput = form.querySelector('input[name="end_time"]');
                    
                    if (startInput && !startInput.value) {
                        startInput.value = formatDateTime(tomorrow);
                    }
                    if (endInput && !endInput.value) {
                        endInput.value = formatDateTime(endTime);
                    }
                });
            }
            
            // Edit class function
            window.editClass = function(cls) {
                document.getElementById('edit_class_id').value = cls.id;
                document.getElementById('edit_title').value = cls.title;
                document.getElementById('edit_age_group').value = cls.age_group;
                document.getElementById('edit_description').value = cls.description || '';
                document.getElementById('edit_instructor_id').value = cls.instructor_id || '';
                document.getElementById('edit_slots_total').value = cls.slots_total;
                document.getElementById('edit_start_time').value = cls.start_time.slice(0, 16);
                document.getElementById('edit_end_time').value = cls.end_time.slice(0, 16);
                document.getElementById('edit_price').value = cls.price;
                document.getElementById('edit_status').value = cls.status;
                
                // Calculate enrolled students
                const enrolled = cls.slots_total - cls.slots_available;
                const slotsHint = document.getElementById('edit_slots_hint');
                if (slotsHint) {
                    slotsHint.textContent = `${enrolled} students currently enrolled. Cannot reduce below this number.`;
                }
            }
            
            // View class function
            window.viewClass = function(cls) {
                document.getElementById('view_title').textContent = cls.title;
                document.getElementById('view_age_group').textContent = cls.age_group;
                document.getElementById('view_description').textContent = cls.description || 'No description provided';
                document.getElementById('view_instructor').textContent = cls.instructor_name || 'Unassigned';
                
                // Format schedule
                const startDate = new Date(cls.start_time);
                const endDate = new Date(cls.end_time);
                const scheduleText = `
                    ${startDate.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}<br>
                    ${startDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })} - 
                    ${endDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}
                `;
                document.getElementById('view_schedule').innerHTML = scheduleText;
                
                // Capacity
                const enrolled = cls.slots_total - cls.slots_available;
                const percentage = Math.round((enrolled / cls.slots_total) * 100);
                document.getElementById('view_capacity').textContent = `${enrolled}/${cls.slots_total} slots (${percentage}% full)`;
                
                // Price
                document.getElementById('view_price').textContent = '$' + parseFloat(cls.price).toFixed(2);
                
                // Status with badge
                const statusText = cls.status.charAt(0).toUpperCase() + cls.status.slice(1).replace('-', ' ');
                let statusClass = 'badge-secondary';
                switch(cls.status) {
                    case 'scheduled': statusClass = 'badge-primary'; break;
                    case 'in-progress': statusClass = 'badge-warning'; break;
                    case 'completed': statusClass = 'badge-success'; break;
                    case 'cancelled': statusClass = 'badge-danger'; break;
                }
                document.getElementById('view_status').innerHTML = `<span class="badge ${statusClass}">${statusText}</span>`;
                
                // Enrolled students
                document.getElementById('view_enrolled').textContent = cls.enrolled_count || '0';
                
                // Dates
                document.getElementById('view_created_at').textContent = cls.created_at ? 
                    new Date(cls.created_at).toLocaleString('en-US') : 'N/A';
                document.getElementById('view_updated_at').textContent = cls.updated_at ? 
                    new Date(cls.updated_at).toLocaleString('en-US') : 'N/A';
            }
            
            // Form validation
            const addForm = document.getElementById('addClassForm');
            const editForm = document.getElementById('editClassForm');
            
            function validateClassForm(form, isEdit = false) {
                const title = form.querySelector('[name="title"]').value.trim();
                const ageGroup = form.querySelector('[name="age_group"]').value.trim();
                const slotsTotal = parseInt(form.querySelector('[name="slots_total"]').value);
                const startTime = form.querySelector('[name="start_time"]').value;
                const endTime = form.querySelector('[name="end_time"]').value;
                const price = parseFloat(form.querySelector('[name="price"]').value);
                
                if (!title || !ageGroup || !startTime || !endTime) {
                    alert('Please fill in all required fields.');
                    return false;
                }
                
                if (slotsTotal < 1 || slotsTotal > 100) {
                    alert('Total slots must be between 1 and 100.');
                    return false;
                }
                
                if (price < 0) {
                    alert('Price cannot be negative.');
                    return false;
                }
                
                if (new Date(startTime) >= new Date(endTime)) {
                    alert('End time must be after start time.');
                    return false;
                }
                
                // Check if class is in the past for new classes
                if (!isEdit && new Date(startTime) < new Date()) {
                    if (!confirm('This class is scheduled in the past. Are you sure you want to create it?')) {
                        return false;
                    }
                }
                
                return true;
            }
            
            if (addForm) {
                addForm.addEventListener('submit', function(e) {
                    if (!validateClassForm(this, false)) {
                        e.preventDefault();
                    }
                });
            }
            
            if (editForm) {
                editForm.addEventListener('submit', function(e) {
                    if (!validateClassForm(this, true)) {
                        e.preventDefault();
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
            
            // Add fade-in animation to cards
            const cards = document.querySelectorAll('.fade-in');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
            
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