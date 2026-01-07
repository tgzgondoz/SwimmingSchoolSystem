<?php
// admin/classes.php - Professional Classes Management
session_start();

// CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

// Rate Limiting
$now = time();
if (!isset($_SESSION['request_count'])) {
    $_SESSION['request_count'] = 1;
    $_SESSION['first_request'] = $now;
} else {
    $_SESSION['request_count']++;
}

if ($_SESSION['request_count'] > 100 && ($now - $_SESSION['first_request']) < 300) {
    die('Too many requests. Please try again later.');
}

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

// Handle export request
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="classes_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Title', 'Age Group', 'Instructor', 'Start Time', 'End Time', 'Capacity', 'Price', 'Status']);
    
    $export_stmt = $conn->prepare("SELECT c.*, i.name as instructor_name FROM classes c LEFT JOIN instructors i ON c.instructor_id = i.id");
    $export_stmt->execute();
    $result = $export_stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['id'],
            $row['title'],
            $row['age_group'],
            $row['instructor_name'],
            $row['start_time'],
            $row['end_time'],
            ($row['slots_total'] - $row['slots_available']) . '/' . $row['slots_total'],
            '$' . number_format($row['price'], 2),
            ucfirst(str_replace('-', ' ', $row['status']))
        ]);
    }
    fclose($output);
    exit;
}

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Token Validation
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error_msg = "Security token invalid. Please refresh the page and try again.";
    } elseif (isset($_POST['add_class'])) {
        $title = htmlspecialchars(trim($_POST['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $age_group = preg_replace('/[^A-Za-z0-9\s\-()]/', '', trim($_POST['age_group'] ?? ''));
        $description = htmlspecialchars(trim($_POST['description'] ?? ''), ENT_QUOTES, 'UTF-8');
        $instructor_id = isset($_POST['instructor_id']) && $_POST['instructor_id'] !== '' ? intval($_POST['instructor_id']) : null;
        $slots_total = intval($_POST['slots_total'] ?? 10);
        $start_time = trim($_POST['start_time'] ?? '');
        $end_time = trim($_POST['end_time'] ?? '');
        $price = $_POST['price'] ?? '0.00';
        
        // Validate price format
        if (!is_numeric($price) || $price < 0) {
            $error_msg = "Invalid price format.";
        } else {
            $price = floatval($price);
        }
        
        // Validate date/time format
        function isValidDateTime($datetime) {
            $d = DateTime::createFromFormat('Y-m-d\TH:i', $datetime);
            return $d && $d->format('Y-m-d\TH:i') === $datetime;
        }
        
        if (!isValidDateTime($start_time) || !isValidDateTime($end_time)) {
            $error_msg = "Invalid date/time format.";
        }
        
        // Validate required fields
        if (empty($title) || empty($age_group) || empty($start_time) || empty($end_time)) {
            $error_msg = "Title, age group, and schedule times are required fields.";
        } elseif ($slots_total < 1 || $slots_total > 100) {
            $error_msg = "Total slots must be between 1 and 100.";
        } elseif ($price < 0) {
            $error_msg = "Price cannot be negative.";
        } else {
            // Validate time logic
            if (strtotime($start_time) >= strtotime($end_time)) {
                $error_msg = "End time must be after start time.";
            } else {
                // Check if class is in the past
                if (strtotime($start_time) < time()) {
                    if (!isset($_POST['confirm_past_class'])) {
                        $error_msg = "This class is scheduled in the past. Please confirm to proceed.";
                        $_SESSION['pending_class_data'] = $_POST;
                        $_SESSION['pending_class_data']['confirm_past'] = true;
                    }
                }
                
                // Check for overlapping classes for the same instructor
                if (!$error_msg && $instructor_id) {
                    $check_stmt = $conn->prepare("
                        SELECT id, title 
                        FROM classes 
                        WHERE instructor_id = ? 
                        AND status IN ('scheduled', 'in-progress')
                        AND id != ?
                        AND (
                            (start_time < ? AND end_time > ?) OR
                            (start_time < ? AND end_time > ?) OR
                            (start_time >= ? AND end_time <= ?)
                        )
                    ");
                    $exclude_id = 0; // For new class, id is 0
                    $check_stmt->bind_param("iissssss", 
                        $instructor_id,
                        $exclude_id,
                        $end_time, $start_time,
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
                            
                            // Clear pending class data if exists
                            if (isset($_SESSION['pending_class_data'])) {
                                unset($_SESSION['pending_class_data']);
                            }
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
        $title = htmlspecialchars(trim($_POST['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $age_group = preg_replace('/[^A-Za-z0-9\s\-()]/', '', trim($_POST['age_group'] ?? ''));
        $description = htmlspecialchars(trim($_POST['description'] ?? ''), ENT_QUOTES, 'UTF-8');
        $instructor_id = isset($_POST['instructor_id']) && $_POST['instructor_id'] !== '' ? intval($_POST['instructor_id']) : null;
        $slots_total = intval($_POST['slots_total'] ?? 10);
        $start_time = trim($_POST['start_time'] ?? '');
        $end_time = trim($_POST['end_time'] ?? '');
        $price = $_POST['price'] ?? '0.00';
        $status = $_POST['status'] ?? 'scheduled';
        
        // Validate price format
        if (!is_numeric($price) || $price < 0) {
            $error_msg = "Invalid price format.";
        } else {
            $price = floatval($price);
        }
        
        // Validate required fields
        if (empty($title) || empty($age_group) || empty($start_time) || empty($end_time) || $class_id <= 0) {
            $error_msg = "Required fields are missing.";
        } elseif ($slots_total < 1 || $slots_total > 100) {
            $error_msg = "Total slots must be between 1 and 100.";
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
                            AND status IN ('scheduled', 'in-progress')
                            AND id != ?
                            AND (
                                (start_time < ? AND end_time > ?) OR
                                (start_time < ? AND end_time > ?) OR
                                (start_time >= ? AND end_time <= ?)
                            )
                        ");
                        $check_stmt->bind_param("iissssss", 
                            $instructor_id,
                            $class_id,
                            $end_time, $start_time,
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
                        $stmt = $conn->prepare("UPDATE classes SET title = ?, age_group = ?, description = ?, instructor_id = ?, slots_total = ?, slots_available = ?, start_time = ?, end_time = ?, price = ?, status = ?, updated_at = NOW() WHERE id = ?");
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
            $check_stmt = $conn->prepare("SELECT COUNT(*) as booking_count FROM bookings WHERE class_id = ? AND status != 'cancelled'");
            $check_stmt->bind_param("i", $class_id);
            $check_stmt->execute();
            $check_stmt->bind_result($booking_count);
            $check_stmt->fetch();
            $check_stmt->close();
            
            if ($booking_count > 0) {
                $error_msg = "Cannot delete class. There are active bookings for this class. Please cancel or reassign bookings first.";
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
            $stmt = $conn->prepare("UPDATE classes SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $class_id);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $success_msg = "Class cancelled successfully! All bookings will be marked as cancelled.";
                    
                    // Update all bookings for this class
                    $update_bookings = $conn->prepare("UPDATE bookings SET status = 'cancelled', updated_at = NOW() WHERE class_id = ?");
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
    
    // Handle bulk operations
    elseif (isset($_POST['bulk_action'])) {
        $bulk_action = $_POST['bulk_action'];
        $selected_classes = $_POST['selected_classes'] ?? [];
        
        if (empty($selected_classes)) {
            $error_msg = "No classes selected.";
        } else {
            $class_ids = array_map('intval', $selected_classes);
            $placeholders = implode(',', array_fill(0, count($class_ids), '?'));
            
            if ($bulk_action === 'cancel') {
                $stmt = $conn->prepare("UPDATE classes SET status = 'cancelled', updated_at = NOW() WHERE id IN ($placeholders)");
                $stmt->bind_param(str_repeat('i', count($class_ids)), ...$class_ids);
                
                if ($stmt->execute()) {
                    $affected = $stmt->affected_rows;
                    $success_msg = "Cancelled $affected class(es) successfully.";
                    
                    // Also cancel bookings for these classes
                    $update_bookings = $conn->prepare("UPDATE bookings SET status = 'cancelled', updated_at = NOW() WHERE class_id IN ($placeholders)");
                    $update_bookings->bind_param(str_repeat('i', count($class_ids)), ...$class_ids);
                    $update_bookings->execute();
                    $update_bookings->close();
                    
                    logActivity($conn, $admin_id, 'Bulk Cancel', "Cancelled $affected classes");
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
    // Get all stats in one query
    $stats = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
            SUM(CASE WHEN status = 'in-progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM classes
    ")->fetch_assoc() ?: [];
    
    $total_classes_all = $stats['total'] ?? 0;
    $scheduled_classes = $stats['scheduled'] ?? 0;
    $in_progress_classes = $stats['in_progress'] ?? 0;
    $completed_classes = $stats['completed'] ?? 0;
    $cancelled_classes = $stats['cancelled'] ?? 0;
    
    // Build SQL query for classes
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
        
        /* Bulk Actions */
        .bulk-actions {
            background-color: white;
            border-radius: 8px;
            padding: 15px 20px;
            border: 1px solid var(--gray-200);
            margin-bottom: 16px;
            display: none;
        }
        
        .bulk-actions.show {
            display: flex;
            align-items: center;
            gap: 12px;
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
        
        .badge-warning {
            background-color: rgba(217, 119, 6, 0.1);
            color: var(--warning);
        }
        
        .badge-info {
            background-color: rgba(8, 145, 178, 0.1);
            color: var(--info);
        }
        
        .badge-primary {
            background-color: rgba(37, 99, 235, 0.1);
            color: var(--primary);
        }
        
        .badge-secondary {
            background-color: rgba(107, 114, 128, 0.1);
            color: var(--gray-600);
        }
        
        /* Progress Bar */
        .progress {
            height: 6px;
            background-color: var(--gray-200);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 4px;
        }
        
        .progress-bar {
            height: 100%;
            border-radius: 3px;
        }
        
        .capacity-low { background-color: var(--success); }
        .capacity-medium { background-color: var(--warning); }
        .capacity-high { background-color: var(--danger); }
        
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
        
        /* Upcoming Classes */
        .upcoming-classes {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            border: 1px solid var(--gray-200);
            margin-bottom: 24px;
        }
        
        .upcoming-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .upcoming-header h4 {
            font-weight: 600;
            margin: 0;
            color: var(--gray-900);
            font-size: 16px;
        }
        
        .upcoming-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .upcoming-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background-color: var(--gray-50);
            border-radius: 6px;
            border: 1px solid var(--gray-200);
        }
        
        .upcoming-info h6 {
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--gray-900);
            font-size: 14px;
        }
        
        .upcoming-info p {
            font-size: 12px;
            color: var(--gray-600);
            margin: 0;
        }
        
        .upcoming-time {
            text-align: right;
        }
        
        .upcoming-time .date {
            font-weight: 600;
            color: var(--primary);
            font-size: 13px;
        }
        
        .upcoming-time .time {
            font-size: 12px;
            color: var(--gray-600);
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
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-header {
                flex-direction: column;
                gap: 12px;
                align-items: stretch;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .bulk-actions {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            
            .bulk-actions .d-flex {
                flex-direction: column;
                gap: 10px;
            }
            
            .table-header {
                flex-direction: column;
                gap: 12px;
            }
            
            .upcoming-item {
                flex-direction: column;
                gap: 8px;
                text-align: center;
            }
            
            .upcoming-time {
                text-align: center;
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
            
            <!-- Bulk Actions -->
            <div class="bulk-actions" id="bulkActions">
                <div>
                    <span id="selectedCount">0</span> classes selected
                </div>
                <div class="d-flex align-items-center gap-2">
                    <select class="form-select form-select-sm" style="width: auto;" id="bulkActionSelect">
                        <option value="">Choose action...</option>
                        <option value="cancel">Cancel Selected</option>
                    </select>
                    <button class="btn btn-sm btn-primary" onclick="applyBulkAction()">Apply</button>
                    <button class="btn btn-sm btn-secondary" onclick="clearSelection()">Clear</button>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-calendar-week"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $total_classes_all ?></h3>
                        <p>Total Classes</p>
                        <div class="stat-trend">
                            <span>All-time classes</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $scheduled_classes ?></h3>
                        <p>Scheduled</p>
                        <div class="stat-trend">
                            <span>Upcoming sessions</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-people"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $capacity_stats['total_enrolled'] ?></h3>
                        <p>Students Enrolled</p>
                        <div class="stat-trend">
                            <span>Active bookings</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-bar-chart"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= number_format($capacity_stats['avg_utilization'] ?? 0, 1) ?>%</h3>
                        <p>Avg Utilization</p>
                        <div class="stat-trend">
                            <span>Capacity usage</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                    <div class="stat-content">
                        <h3>$<?= number_format($revenue_stats['total_revenue'] ?? 0, 2) ?></h3>
                        <p>30-Day Revenue</p>
                        <div class="stat-trend">
                            <span>From class payments</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Upcoming Classes -->
            <?php if (!empty($upcoming_classes)): ?>
                <div class="upcoming-classes">
                    <div class="upcoming-header">
                        <h4>Upcoming Classes</h4>
                        <a href="?status=scheduled" class="btn btn-sm btn-outline-primary" style="font-size: 12px;">
                            View All
                        </a>
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
            <div class="filter-section">
                <div class="filter-header">
                    <h3>Filter Classes</h3>
                    <div class="d-flex gap-2">
                        <a href="?export=csv" class="btn btn-outline-success" style="font-size: 14px;">
                            <i class="bi bi-download me-2"></i> Export CSV
                        </a>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClassModal" style="font-size: 14px;">
                            <i class="bi bi-plus-circle me-2"></i> Add Class
                        </button>
                    </div>
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
                        <button type="submit" class="btn btn-primary w-100" style="font-size: 14px;">
                            <i class="bi bi-funnel me-2"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Classes Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3>Class Schedule</h3>
                    <div>
                        <span class="text-muted" style="font-size: 14px;">Showing <?= count($classes) ?> of <?= $total_classes ?> classes</span>
                    </div>
                </div>
                
                <div class="table-wrapper">
                    <?php if (empty($classes)): ?>
                        <div class="empty-state">
                            <i class="bi bi-calendar-x"></i>
                            <h4>No Classes Found</h4>
                            <p>No classes match your search criteria. Try adjusting your filters.</p>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClassModal" style="font-size: 14px;">
                                <i class="bi bi-plus-circle me-2"></i> Add New Class
                            </button>
                        </div>
                    <?php else: ?>
                        <form method="POST" id="bulkForm">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th width="40">
                                            <input type="checkbox" class="form-check-input" id="selectAll" onchange="selectAllClasses(this.checked)">
                                        </th>
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
                                                <input type="checkbox" class="form-check-input class-checkbox" name="selected_classes[]" value="<?= $class['id'] ?>" onchange="updateSelection()">
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-start">
                                                    <div>
                                                        <div class="fw-medium" style="font-size: 14px;"><?= htmlspecialchars($class['title']) ?></div>
                                                        <div class="text-muted" style="font-size: 12px;">
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
                                                <div class="fw-medium" style="font-size: 14px;"><?= date('M j, Y', strtotime($class['start_time'])) ?></div>
                                                <div class="text-muted" style="font-size: 12px;">
                                                    <?= date('g:i A', strtotime($class['start_time'])) ?> - <?= date('g:i A', strtotime($class['end_time'])) ?>
                                                </div>
                                                <div class="text-muted" style="font-size: 12px;">
                                                    $<?= number_format($class['price'], 2) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($class['instructor_name']): ?>
                                                    <div class="fw-medium" style="font-size: 14px;"><?= htmlspecialchars($class['instructor_name']) ?></div>
                                                    <div class="text-muted" style="font-size: 12px;">
                                                        <?= htmlspecialchars($class['instructor_email'] ?? '') ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Unassigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div>
                                                    <div style="font-size: 14px;">
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
                                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                            <input type="hidden" name="class_id" value="<?= $class['id'] ?>">
                                                            <button type="submit" name="cancel_class" class="btn btn-outline-warning btn-sm btn-icon" title="Cancel Class">
                                                                <i class="bi bi-x-circle"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if ($class['enrolled_count'] == 0): ?>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this class? This action cannot be undone.');">
                                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
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
                            <input type="hidden" name="bulk_action" id="bulkActionInput">
                        </form>
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
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
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
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
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
                                <small class="text-muted" id="edit_slots_hint" style="font-size: 12px;"></small>
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
                            <p class="fw-medium" id="view_title"></p>
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
    
    <!-- Confirm Past Class Modal -->
    <div class="modal fade" id="confirmPastClassModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Past Class</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        This class is scheduled in the past. Are you sure you want to create it?
                    </div>
                    <p style="font-size: 14px;">This might be intended for historical data entry or back-dating.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="submitPastClass()">Yes, Create Class</button>
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
                
                // Check instructor availability in real-time
                if (cls.instructor_id) {
                    checkInstructorAvailability(cls.instructor_id, cls.start_time.slice(0, 16), cls.end_time.slice(0, 16), cls.id);
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
            
            // Check instructor availability
            window.checkInstructorAvailability = function(instructorId, startTime, endTime, excludeClassId = 0) {
                if (!instructorId) return;
                
                fetch('check_availability.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ 
                        instructorId, 
                        startTime, 
                        endTime, 
                        excludeClassId 
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.available === false) {
                        alert('Warning: Instructor is already scheduled during this time.');
                    }
                })
                .catch(error => console.error('Error:', error));
            }
            
            // Bulk selection functions
            window.selectAllClasses = function(selectAll) {
                const checkboxes = document.querySelectorAll('.class-checkbox');
                checkboxes.forEach(cb => {
                    cb.checked = selectAll;
                });
                updateSelection();
            }
            
            window.updateSelection = function() {
                const selected = document.querySelectorAll('.class-checkbox:checked');
                const count = selected.length;
                const bulkActions = document.getElementById('bulkActions');
                const selectedCount = document.getElementById('selectedCount');
                
                if (selectedCount) {
                    selectedCount.textContent = count;
                }
                
                if (bulkActions) {
                    if (count > 0) {
                        bulkActions.classList.add('show');
                    } else {
                        bulkActions.classList.remove('show');
                    }
                }
            }
            
            window.clearSelection = function() {
                selectAllClasses(false);
            }
            
            window.applyBulkAction = function() {
                const action = document.getElementById('bulkActionSelect').value;
                const bulkForm = document.getElementById('bulkForm');
                const bulkActionInput = document.getElementById('bulkActionInput');
                
                if (!action) {
                    alert('Please select an action.');
                    return;
                }
                
                const selected = document.querySelectorAll('.class-checkbox:checked');
                if (selected.length === 0) {
                    alert('No classes selected.');
                    return;
                }
                
                if (action === 'cancel') {
                    if (!confirm(`Are you sure you want to cancel ${selected.length} class(es)? All bookings will be cancelled.`)) {
                        return;
                    }
                }
                
                bulkActionInput.value = action;
                bulkForm.submit();
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
                    const confirmModal = new bootstrap.Modal(document.getElementById('confirmPastClassModal'));
                    confirmModal.show();
                    return false;
                }
                
                return true;
            }
            
            window.submitPastClass = function() {
                const form = document.getElementById('addClassForm');
                const confirmCheckbox = document.createElement('input');
                confirmCheckbox.type = 'hidden';
                confirmCheckbox.name = 'confirm_past_class';
                confirmCheckbox.value = '1';
                form.appendChild(confirmCheckbox);
                form.submit();
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
            
            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                    bsAlert.close();
                }, 5000);
            });
            
            // Check for pending class data
            <?php if (isset($_SESSION['pending_class_data'])): ?>
                const pendingData = <?= json_encode($_SESSION['pending_class_data']) ?>;
                if (pendingData.confirm_past) {
                    const addClassModal = new bootstrap.Modal(document.getElementById('addClassModal'));
                    addClassModal.show();
                    
                    // Populate form with pending data
                    const form = document.getElementById('addClassForm');
                    for (const key in pendingData) {
                        if (key !== 'confirm_past') {
                            const input = form.querySelector(`[name="${key}"]`);
                            if (input) {
                                input.value = pendingData[key];
                            }
                        }
                    }
                    
                    // Show confirmation modal
                    setTimeout(() => {
                        const confirmModal = new bootstrap.Modal(document.getElementById('confirmPastClassModal'));
                        confirmModal.show();
                    }, 500);
                }
            <?php endif; ?>
        });
    </script>
</body>
</html>