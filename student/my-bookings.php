<?php
// student/my-bookings.php - Student's Bookings Management
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

// Authentication and role check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$student_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

// Handle booking cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'cancel_booking') {
        $booking_id = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
        
        if (!$booking_id || $booking_id <= 0) {
            $error_msg = "Invalid booking selection.";
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // 1. Get booking details with row lock
                $stmt = $conn->prepare("SELECT b.id, b.class_id, b.status, c.start_time FROM bookings b JOIN classes c ON b.class_id = c.id WHERE b.id = ? AND b.user_id = ? FOR UPDATE");
                $stmt->bind_param("ii", $booking_id, $student_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    throw new Exception("Booking not found or you don't have permission to cancel it.");
                }
                
                $booking = $result->fetch_assoc();
                
                // 2. Check if booking can be cancelled
                if ($booking['status'] === 'cancelled') {
                    throw new Exception("This booking has already been cancelled.");
                }
                
                // Check if class has already started
                if (strtotime($booking['start_time']) < time()) {
                    throw new Exception("Cannot cancel a class that has already started.");
                }
                
                // 3. Update booking status to cancelled
                $update_stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled', cancelled_at = NOW() WHERE id = ? AND user_id = ?");
                $update_stmt->bind_param("ii", $booking_id, $student_id);
                
                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to cancel booking. Please try again.");
                }
                
                // 4. Increment available slots in class
                $slots_stmt = $conn->prepare("UPDATE classes SET slots_available = slots_available + 1 WHERE id = ?");
                $slots_stmt->bind_param("i", $booking['class_id']);
                $slots_stmt->execute();
                
                // 5. Commit transaction
                $conn->commit();
                
                // Set success message
                $_SESSION['success_msg'] = "Booking cancelled successfully. Your slot has been freed.";
                header('Location: my-bookings.php?success=1');
                exit();
                
            } catch (Exception $e) {
                // Rollback on any error
                $conn->rollback();
                $error_msg = $e->getMessage();
                
                // Log error for debugging
                error_log("Booking Cancellation Error - Student: {$student_id}, Booking: {$booking_id}, Error: " . $e->getMessage());
            }
        }
    }
    
    // Handle new booking creation
    if ($_POST['action'] === 'create_booking') {
        $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
        $child_name = trim($_POST['child_name'] ?? '');
        $child_age = filter_input(INPUT_POST, 'child_age', FILTER_VALIDATE_INT);
        $child_gender = $_POST['child_gender'] ?? '';
        $special_notes = trim($_POST['special_notes'] ?? '');
        
        // Validate inputs
        if (!$class_id || $class_id <= 0) {
            $error_msg = "Please select a valid class.";
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // 1. Check if class exists and has available slots
                $class_stmt = $conn->prepare("SELECT id, title, price, slots_available, start_time FROM classes WHERE id = ? AND status = 'scheduled' FOR UPDATE");
                $class_stmt->bind_param("i", $class_id);
                $class_stmt->execute();
                $class_result = $class_stmt->get_result();
                
                if ($class_result->num_rows === 0) {
                    throw new Exception("Class not found or not available for booking.");
                }
                
                $class = $class_result->fetch_assoc();
                
                // 2. Check if class is full
                if ($class['slots_available'] <= 0) {
                    throw new Exception("This class is full. Please select another class.");
                }
                
                // 3. Check if student already has a booking for this class
                $existing_stmt = $conn->prepare("SELECT id FROM bookings WHERE user_id = ? AND class_id = ? AND status NOT IN ('cancelled', 'rejected')");
                $existing_stmt->bind_param("ii", $student_id, $class_id);
                $existing_stmt->execute();
                $existing_result = $existing_stmt->get_result();
                
                if ($existing_result->num_rows > 0) {
                    throw new Exception("You already have a booking for this class.");
                }
                
                // 4. Check if class start time is in the future
                if (strtotime($class['start_time']) < time()) {
                    throw new Exception("Cannot book a class that has already started.");
                }
                
                // 5. Create booking with pending status
                $booking_stmt = $conn->prepare("INSERT INTO bookings (user_id, class_id, child_name, child_age, child_gender, special_notes, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
                $booking_stmt->bind_param("iisiss", $student_id, $class_id, $child_name, $child_age, $child_gender, $special_notes);
                
                if (!$booking_stmt->execute()) {
                    throw new Exception("Failed to create booking. Please try again.");
                }
                
                $booking_id = $booking_stmt->insert_id;
                
                // 6. Create pending payment record
                $payment_stmt = $conn->prepare("INSERT INTO payments (booking_id, user_id, amount, status, payment_method, description, created_at) VALUES (?, ?, ?, 'pending', 'manual', 'Booking created', NOW())");
                $payment_stmt->bind_param("iid", $booking_id, $student_id, $class['price']);
                $payment_stmt->execute();
                
                // 7. Decrement available slots
                $decrement_stmt = $conn->prepare("UPDATE classes SET slots_available = slots_available - 1 WHERE id = ?");
                $decrement_stmt->bind_param("i", $class_id);
                $decrement_stmt->execute();
                
                // 8. Commit transaction
                $conn->commit();
                
                $_SESSION['success_msg'] = "Booking request submitted successfully! Please wait for admin approval.";
                header('Location: my-bookings.php?success=1');
                exit();
                
            } catch (Exception $e) {
                // Rollback on any error
                $conn->rollback();
                $error_msg = $e->getMessage();
                error_log("Booking Creation Error - Student: {$student_id}, Class: {$class_id}, Error: " . $e->getMessage());
            }
        }
    }
}

// Load messages from session
if (isset($_SESSION['success_msg'])) {
    $success_msg = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
} elseif (isset($_GET['success'])) {
    $success_msg = "Operation successful!";
}

// Filter bookings
$filters = [
    'status' => $_GET['status'] ?? '',
    'type' => $_GET['type'] ?? 'all',
    'search' => $_GET['search'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? ''
];

// Build WHERE clause for filtering
$where_conditions = ["b.user_id = ?"];
$params = [$student_id];
$param_types = 'i';

// Add status filter
if (!empty($filters['status'])) {
    $where_conditions[] = "b.status = ?";
    $params[] = $filters['status'];
    $param_types .= 's';
}

// Add type filter
if ($filters['type'] === 'upcoming') {
    $where_conditions[] = "c.start_time >= NOW()";
} elseif ($filters['type'] === 'past') {
    $where_conditions[] = "c.start_time < NOW()";
} elseif ($filters['type'] === 'today') {
    $where_conditions[] = "DATE(c.start_time) = CURDATE()";
}

// Add date range filter
if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
    $where_conditions[] = "DATE(c.start_time) BETWEEN ? AND ?";
    $params[] = $filters['date_from'];
    $params[] = $filters['date_to'];
    $param_types .= 'ss';
}

// Add search filter
if (!empty($filters['search'])) {
    $where_conditions[] = "(c.title LIKE ? OR c.description LIKE ? OR i.name LIKE ?)";
    $search_term = "%{$filters['search']}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $param_types .= 'sss';
}

// Prepare query to get bookings with enhanced data - FIXED VERSION
$query = "SELECT 
            b.*,
            c.*,
            i.name as instructor_name,
            i.email as instructor_email,
            i.phone as instructor_phone,
            i.specialization,
            p.status as payment_status,
            p.amount as payment_amount,
            p.id as payment_id,
            p.payment_date,
            CASE 
                WHEN c.start_time >= NOW() THEN 'upcoming'
                ELSE 'past'
            END as time_status,
            TIMESTAMPDIFF(HOUR, NOW(), c.start_time) as hours_until_class,
            (SELECT COUNT(*) FROM bookings b2 WHERE b2.class_id = c.id AND b2.status = 'confirmed') as confirmed_bookings
          FROM bookings b
          JOIN classes c ON b.class_id = c.id
          LEFT JOIN instructors i ON c.instructor_id = i.id
          LEFT JOIN payments p ON b.id = p.booking_id AND p.user_id = b.user_id
          WHERE " . implode(" AND ", $where_conditions) . "
          ORDER BY 
            CASE 
                WHEN b.status = 'pending' THEN 1
                WHEN b.status = 'confirmed' AND c.start_time >= NOW() THEN 2
                WHEN b.status = 'confirmed' AND c.start_time < NOW() THEN 3
                WHEN b.status = 'rejected' THEN 4
                WHEN b.status = 'cancelled' THEN 5
                ELSE 6
            END,
            c.start_time DESC";

$bookings = [];
$stmt = $conn->prepare($query);

if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $bookings = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    error_log("Database query error: " . $conn->error);
    $error_msg = "Unable to load bookings. Please try again later.";
}

// Get available classes for new bookings - SIMPLIFIED VERSION
$available_classes = [];
$classes_query = "SELECT c.*, 
                  i.name as instructor_name,
                  CASE 
                    WHEN c.slots_available <= 0 THEN 'full'
                    WHEN c.slots_available <= 2 THEN 'almost_full'
                    ELSE 'available'
                  END as availability_status
                  FROM classes c
                  LEFT JOIN instructors i ON c.instructor_id = i.id
                  WHERE c.status = 'scheduled' 
                  AND c.start_time >= NOW()
                  AND c.slots_available > 0
                  AND c.id NOT IN (
                    SELECT class_id FROM bookings 
                    WHERE user_id = ? 
                    AND status NOT IN ('cancelled', 'rejected')
                  )
                  ORDER BY c.start_time ASC";

$classes_stmt = $conn->prepare($classes_query);
if ($classes_stmt) {
    $classes_stmt->bind_param("i", $student_id);
    $classes_stmt->execute();
    $classes_result = $classes_stmt->get_result();
    $available_classes = $classes_result->fetch_all(MYSQLI_ASSOC);
    $classes_stmt->close();
}

// Get booking statistics with enhanced metrics - SIMPLIFIED VERSION
$stats = [
    'total' => 0,
    'confirmed' => 0,
    'pending' => 0,
    'cancelled' => 0,
    'upcoming' => 0,
    'rejected' => 0,
    'attended' => 0,
    'total_spent' => 0
];

$stats_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
    SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
    SUM(CASE WHEN b.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
    SUM(CASE WHEN c.start_time >= NOW() AND b.status IN ('confirmed', 'pending') THEN 1 ELSE 0 END) as upcoming,
    SUM(CASE WHEN c.start_time < NOW() AND b.status = 'confirmed' THEN 1 ELSE 0 END) as attended,
    COALESCE(SUM(CASE WHEN p.status = 'paid' THEN p.amount ELSE 0 END), 0) as total_spent
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    LEFT JOIN payments p ON b.id = p.booking_id AND p.user_id = b.user_id
    WHERE b.user_id = ?";

$stats_stmt = $conn->prepare($stats_query);
if ($stats_stmt) {
    $stats_stmt->bind_param("i", $student_id);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $stats = $stats_result->fetch_assoc() ?: $stats;
    $stats_stmt->close();
}

// Get user info
$user = [];
$user_stmt = $conn->prepare("SELECT name, email, phone, age, emergency_contact FROM users WHERE id = ?");
if ($user_stmt) {
    $user_stmt->bind_param("i", $student_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    $user = $user_result->fetch_assoc() ?: [];
    $user_stmt->close();
}

// Get pending bookings count for sidebar
$pending_count = $stats['pending'] ?? 0;

// Calculate cancellation rate
$cancellation_rate = $stats['total'] > 0 ? round(($stats['cancelled'] / $stats['total']) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings | Elite Swimming Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* === CSS VARIABLES - Match Dashboard Theme === */
        :root {
            --primary: #0d6efd;
            --primary-dark: #0a58ca;
            --primary-light: #e7f1ff;
            --secondary: #6c757d;
            --success: #198754;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #0dcaf0;
            --light: #f8f9fa;
            --dark: #212529;
            --aqua: #0dcaf0;
            --blue-light: #e7f1ff;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-400: #ced4da;
            --gray-500: #adb5bd;
            --gray-600: #6c757d;
            --gray-700: #495057;
            --gray-800: #343a40;
            --gray-900: #212529;
            --border-radius: 12px;
            --box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            --transition: all 0.3s ease;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
            min-height: 100vh;
            color: #333;
        }

        /* === DASHBOARD LAYOUT === */
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar - Match Dashboard */
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
            background: linear-gradient(135deg, var(--primary) 0%, var(--aqua) 100%);
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
            background: linear-gradient(90deg, var(--primary), var(--aqua));
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
            transition: var(--transition);
        }

        .nav-link:hover {
            background: var(--blue-light);
            color: var(--primary);
            transform: translateX(5px);
        }

        .nav-link.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(13, 110, 253, 0.2);
        }

        .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 18px;
        }

        .notification-badge {
            background: var(--danger);
            color: white;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 10px;
            margin-left: auto;
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

        /* === HEADER - Match Dashboard === */
        .header {
            background: white;
            border-radius: 15px;
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-left h1 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
            background: linear-gradient(90deg, var(--primary), var(--aqua));
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

        /* === ALERTS - Match Dashboard === */
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

        /* === QUICK STATS === */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
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
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 15px;
            color: white;
        }

        .stat-icon.primary { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); }
        .stat-icon.success { background: linear-gradient(135deg, var(--success) 0%, #157347 100%); }
        .stat-icon.warning { background: linear-gradient(135deg, var(--warning) 0%, #ffca2c 100%); }
        .stat-icon.danger { background: linear-gradient(135deg, var(--danger) 0%, #b02a37 100%); }
        .stat-icon.info { background: linear-gradient(135deg, var(--info) 0%, #0891b2 100%); }

        .stat-content h3 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--dark);
        }

        .stat-content p {
            color: #6c757d;
            font-size: 14px;
            margin: 0;
        }

        /* === FILTER SECTION === */
        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
        }

        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 8px 20px;
            background: var(--light);
            border-radius: 8px;
            color: var(--gray-600);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            border: 2px solid transparent;
        }

        .filter-tab:hover {
            background: var(--gray-200);
            color: var(--dark);
        }

        .filter-tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .search-box {
            position: relative;
            max-width: 400px;
        }

        .search-box input {
            padding-left: 45px;
            border-radius: 10px;
            border: 2px solid var(--gray-300);
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-500);
        }

        /* === BOOKINGS GRID === */
        .bookings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        @media (max-width: 992px) {
            .bookings-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Booking Card */
        .booking-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            border: 1px solid #e9ecef;
        }

        .booking-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
        }

        .booking-header {
            padding: 20px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .booking-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
            margin: 0;
        }

        .booking-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-confirmed { background: rgba(25, 135, 84, 0.1); color: var(--success); }
        .status-pending { background: rgba(255, 193, 7, 0.1); color: var(--warning); }
        .status-cancelled { background: rgba(220, 53, 69, 0.1); color: var(--danger); }
        .status-rejected { background: rgba(108, 117, 125, 0.1); color: var(--secondary); }

        .booking-body {
            padding: 20px;
        }

        .booking-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .detail-item i {
            color: var(--primary);
            font-size: 16px;
            width: 20px;
        }

        .detail-label {
            font-size: 12px;
            color: var(--gray-600);
            margin-bottom: 3px;
        }

        .detail-value {
            font-size: 14px;
            font-weight: 500;
            color: var(--dark);
        }

        .booking-footer {
            padding: 20px;
            background: var(--light);
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .booking-price {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
        }

        .booking-actions {
            display: flex;
            gap: 10px;
        }

        .btn-action {
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 14px;
            transition: var(--transition);
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-action.primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .btn-action.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.3);
        }

        .btn-action.secondary {
            background: white;
            color: var(--dark);
            border: 2px solid var(--gray-300);
        }

        .btn-action.secondary:hover {
            background: var(--gray-100);
            border-color: var(--gray-400);
        }

        .btn-action.danger {
            background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%);
            color: #991b1b;
        }

        .btn-action.danger:hover {
            background: linear-gradient(135deg, #fca5a5 0%, #f87171 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
        }

        /* === MODAL STYLES === */
        .modal-class-card {
            background: white;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            padding: 20px;
            cursor: pointer;
            transition: var(--transition);
            height: 100%;
        }

        .modal-class-card:hover {
            border-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: var(--box-shadow);
        }

        .modal-class-card.selected {
            border-color: var(--primary);
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        }

        /* === EMPTY STATE === */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 15px;
            box-shadow: var(--box-shadow);
        }

        .empty-state-icon {
            font-size: 64px;
            color: var(--gray-400);
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 24px;
            color: var(--dark);
            margin-bottom: 10px;
        }

        .empty-state p {
            color: var(--gray-600);
            margin-bottom: 30px;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }

        /* === LOADING OVERLAY === */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.95);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
        }

        .loading-spinner {
            text-align: center;
            background: white;
            padding: 40px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .spinner {
            width: 60px;
            height: 60px;
            border: 4px solid var(--primary-light);
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* === ANIMATIONS === */
        .fade-in {
            animation: fadeIn 0.5s ease forwards;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* === RESPONSIVE DESIGN === */
        @media (max-width: 1200px) {
            .main-content {
                padding: 20px;
            }
            
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

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
            
            .notification-badge {
                position: absolute;
                right: 5px;
                top: 5px;
            }
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .quick-stats {
                grid-template-columns: 1fr;
            }
            
            .filter-tabs {
                justify-content: center;
            }
            
            .booking-details {
                grid-template-columns: 1fr;
            }
            
            .booking-footer {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }
            
            .booking-actions {
                justify-content: center;
            }
        }

        /* === ADDITIONAL ENHANCEMENTS === */
        .time-until {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            background: rgba(13, 110, 253, 0.1);
            color: var(--primary);
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .instructor-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            background: rgba(13, 202, 240, 0.1);
            color: var(--info);
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .progress-bar {
            height: 6px;
            background: var(--gray-200);
            border-radius: 3px;
            overflow: hidden;
            margin: 10px 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--aqua));
            border-radius: 3px;
            transition: width 0.5s ease;
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p>Processing your request...</p>
        </div>
    </div>
    
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="logo-area">
                <a href="index.php" class="logo">
                    <div class="logo-icon">
                        <i class="bi bi-droplet"></i>
                    </div>
                    <div class="logo-text">
                        <h3>Elite Swimming Academy</h3>
                        <span>Student Portal</span>
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
                    <a href="classes.php" class="nav-link">
                        <i class="bi bi-calendar-check"></i>
                        <span class="nav-text">Book Classes</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="my-bookings.php" class="nav-link active">
                        <i class="bi bi-ticket-perforated"></i>
                        <span class="nav-text">My Bookings</span>
                        <?php if ($pending_count > 0): ?>
                            <span class="notification-badge"><?= $pending_count ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="payments.php" class="nav-link">
                        <i class="bi bi-credit-card"></i>
                        <span class="nav-text">Payments</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="profile.php" class="nav-link">
                        <i class="bi bi-person-circle"></i>
                        <span class="nav-text">Profile</span>
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
            <header class="header">
                <div class="header-left">
                    <h1>My Bookings</h1>
                    <p>Manage your swimming class bookings and track your progress</p>
                </div>
                <div class="user-profile">
                    <div class="user-avatar">
                        <?= isset($user['name']) ? strtoupper(substr($user['name'], 0, 1)) : 'U' ?>
                    </div>
                    <div class="user-info">
                        <h5><?= htmlspecialchars($user['name'] ?? 'Student') ?></h5>
                        <p>Member Since <?= date('M Y', strtotime('-6 months')) ?></p>
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
            
            <!-- Quick Stats -->
            <div class="quick-stats">
                <div class="stat-card">
                    <div class="stat-icon primary">
                        <i class="bi bi-ticket-perforated"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $stats['total'] ?></h3>
                        <p>Total Bookings</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon success">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $stats['confirmed'] ?></h3>
                        <p>Confirmed</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon warning">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $stats['pending'] ?></h3>
                        <p>Pending Approval</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon danger">
                        <i class="bi bi-x-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $stats['cancelled'] ?></h3>
                        <p>Cancelled</p>
                    </div>
                </div>
            </div>
            
            <!-- Filter Section -->
            <div class="filter-section">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="filter-tabs">
                        <a href="my-bookings.php" class="filter-tab <?= empty($filters['type']) && empty($filters['status']) ? 'active' : '' ?>">
                            All Bookings
                        </a>
                        <a href="my-bookings.php?type=upcoming" class="filter-tab <?= $filters['type'] === 'upcoming' ? 'active' : '' ?>">
                            Upcoming
                        </a>
                        <a href="my-bookings.php?type=past" class="filter-tab <?= $filters['type'] === 'past' ? 'active' : '' ?>">
                            Past
                        </a>
                        <a href="my-bookings.php?status=pending" class="filter-tab <?= $filters['status'] === 'pending' ? 'active' : '' ?>">
                            Pending
                        </a>
                        <a href="my-bookings.php?status=confirmed" class="filter-tab <?= $filters['status'] === 'confirmed' ? 'active' : '' ?>">
                            Confirmed
                        </a>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <form method="GET" class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" name="search" class="form-control" placeholder="Search bookings..." value="<?= htmlspecialchars($filters['search']) ?>">
                        </form>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bookClassModal">
                            <i class="bi bi-plus-circle me-2"></i>Book New Class
                        </button>
                    </div>
                </div>
                
                <div class="d-flex gap-3 align-items-center">
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Showing <?= count($bookings) ?> booking<?= count($bookings) !== 1 ? 's' : '' ?>
                    </small>
                    <div class="progress-bar" style="flex: 1; max-width: 300px;">
                        <div class="progress-fill" style="width: <?= $stats['total'] > 0 ? ($stats['attended'] / $stats['total'] * 100) : 0 ?>%"></div>
                    </div>
                    <small class="text-muted">
                        <?= $stats['attended'] ?> of <?= $stats['total'] ?> completed
                    </small>
                </div>
            </div>
            
            <!-- Bookings Grid -->
            <?php if (empty($bookings)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-calendar-x"></i>
                    </div>
                    <h3>No Bookings Found</h3>
                    <p>
                        <?php if ($filters['type'] || $filters['status'] || $filters['search']): ?>
                            No bookings match your current filters. Try adjusting your search criteria.
                        <?php else: ?>
                            You haven't booked any classes yet. Start your swimming journey today!
                        <?php endif; ?>
                    </p>
                    <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#bookClassModal">
                        <i class="bi bi-plus-circle me-2"></i>Book Your First Class
                    </button>
                </div>
            <?php else: ?>
                <div class="bookings-grid fade-in">
                    <?php foreach ($bookings as $booking): 
                        $start_time = strtotime($booking['start_time']);
                        $end_time = strtotime($booking['end_time']);
                        $duration = ($end_time - $start_time) / 60;
                        $is_upcoming = $start_time > time();
                        $is_today = date('Y-m-d', $start_time) === date('Y-m-d');
                        $has_end_time = !empty($booking['end_time']) && $booking['end_time'] != '0000-00-00 00:00:00';
                        
                        // Determine status color
                        $status_class = '';
                        if ($booking['status'] === 'confirmed') $status_class = 'status-confirmed';
                        elseif ($booking['status'] === 'pending') $status_class = 'status-pending';
                        elseif ($booking['status'] === 'cancelled') $status_class = 'status-cancelled';
                        elseif ($booking['status'] === 'rejected') $status_class = 'status-rejected';
                    ?>
                        <div class="booking-card">
                            <div class="booking-header">
                                <div>
                                    <h5 class="booking-title"><?= htmlspecialchars($booking['title']) ?></h5>
                                    <div class="d-flex align-items-center gap-2 mt-2">
                                        <span class="booking-status <?= $status_class ?>">
                                            <?= ucfirst($booking['status']) ?>
                                        </span>
                                        <?php if ($is_today && $is_upcoming): ?>
                                            <span class="time-until">
                                                <i class="bi bi-clock"></i>
                                                Today, <?= date('g:i A', $start_time) ?>
                                            </span>
                                        <?php elseif ($is_upcoming): ?>
                                            <span class="time-until">
                                                <i class="bi bi-clock"></i>
                                                In <?= $booking['hours_until_class'] ?>h
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="instructor-badge">
                                    <i class="bi bi-person"></i>
                                    <?= htmlspecialchars($booking['instructor_name'] ?? 'TBA') ?>
                                </div>
                            </div>
                            
                            <div class="booking-body">
                                <div class="booking-details">
                                    <div class="detail-item">
                                        <i class="bi bi-calendar"></i>
                                        <div>
                                            <div class="detail-label">Date</div>
                                            <div class="detail-value"><?= date('F j, Y', $start_time) ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <i class="bi bi-clock"></i>
                                        <div>
                                            <div class="detail-label">Time</div>
                                            <div class="detail-value">
                                                <?= date('g:i A', $start_time) ?>
                                                <?php if ($has_end_time): ?>
                                                    - <?= date('g:i A', $end_time) ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <i class="bi bi-cash"></i>
                                        <div>
                                            <div class="detail-label">Amount</div>
                                            <div class="detail-value">$<?= number_format($booking['price'], 2) ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <i class="bi bi-credit-card"></i>
                                        <div>
                                            <div class="detail-label">Payment</div>
                                            <div class="detail-value <?= ($booking['payment_status'] ?? 'pending') === 'paid' ? 'text-success' : 'text-warning' ?>">
                                                <?= ucfirst($booking['payment_status'] ?? 'pending') ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if (!empty($booking['child_name'])): ?>
                                    <div class="mt-3 p-3 bg-light rounded">
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="bi bi-person text-primary"></i>
                                            <strong>Child:</strong>
                                            <span><?= htmlspecialchars($booking['child_name']) ?></span>
                                            <?php if ($booking['child_age']): ?>
                                                <span class="text-muted">(Age: <?= $booking['child_age'] ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($booking['special_notes'])): ?>
                                    <div class="mt-3">
                                        <small class="text-muted">Notes:</small>
                                        <p class="mb-0"><?= htmlspecialchars($booking['special_notes']) ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="booking-footer">
                                <div class="booking-price">
                                    $<?= number_format($booking['price'], 2) ?>
                                </div>
                                <div class="booking-actions">
                                    <?php if ($is_upcoming && in_array($booking['status'], ['confirmed', 'pending'])): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to cancel this booking?')">
                                            <input type="hidden" name="action" value="cancel_booking">
                                            <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                            <button type="submit" class="btn-action danger">
                                                <i class="bi bi-x-circle"></i>
                                                Cancel
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <?php if (($booking['payment_status'] ?? 'pending') === 'pending'): ?>
                                        <button class="btn-action primary" onclick="window.location.href='payments.php?booking_id=<?= $booking['id'] ?>'">
                                            <i class="bi bi-credit-card"></i>
                                            Pay Now
                                        </button>
                                    <?php endif; ?>
                                    
                                    <button class="btn-action secondary" onclick="viewBookingDetails(<?= $booking['id'] ?>)">
                                        <i class="bi bi-eye"></i>
                                        View
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination or View All -->
                <div class="text-center mt-4">
                    <a href="my-bookings.php?type=all" class="btn btn-outline-primary">
                        <i class="bi bi-list-ul me-2"></i>View All Bookings
                    </a>
                </div>
            <?php endif; ?>
        </main>
    </div>
    
    <!-- Book Class Modal -->
    <div class="modal fade" id="bookClassModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Book a New Class</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="bookingForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_booking">
                        
                        <!-- Available Classes -->
                        <div class="mb-4">
                            <h6 class="mb-3">Available Classes</h6>
                            <?php if (empty($available_classes)): ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                    No available classes at the moment. Please check back later.
                                </div>
                            <?php else: ?>
                                <div class="row">
                                    <?php foreach ($available_classes as $class): 
                                        $availability_class = $class['availability_status'] === 'full' ? 'border-danger' : 
                                                            ($class['availability_status'] === 'almost_full' ? 'border-warning' : 'border-success');
                                    ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="modal-class-card <?= $availability_class ?>" onclick="selectClass(this, <?= $class['id'] ?>)">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <h6 class="mb-0 fw-semibold"><?= htmlspecialchars($class['title']) ?></h6>
                                                    <span class="badge bg-<?= $class['availability_status'] === 'full' ? 'danger' : 
                                                                         ($class['availability_status'] === 'almost_full' ? 'warning' : 'success') ?>">
                                                        <?= ucfirst(str_replace('_', ' ', $class['availability_status'])) ?>
                                                    </span>
                                                </div>
                                                <p class="text-muted mb-2 small">
                                                    <i class="bi bi-person me-1"></i>
                                                    <?= htmlspecialchars($class['instructor_name'] ?? 'TBA') ?>
                                                </p>
                                                <div class="mb-2">
                                                    <i class="bi bi-calendar me-1"></i>
                                                    <?= date('D, M j', strtotime($class['start_time'])) ?>
                                                </div>
                                                <div class="mb-3">
                                                    <i class="bi bi-clock me-1"></i>
                                                    <?= date('g:i A', strtotime($class['start_time'])) ?>
                                                    <?php if (!empty($class['end_time']) && $class['end_time'] != '0000-00-00 00:00:00'): ?>
                                                        - <?= date('g:i A', strtotime($class['end_time'])) ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span class="h5 text-primary mb-0">$<?= number_format($class['price'], 2) ?></span>
                                                    <small class="text-muted">
                                                        <?= $class['slots_available'] ?> slots available
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="class_id" id="selected_class_id" required>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Child Information -->
                        <div class="mb-4">
                            <h6 class="mb-3">
                                <i class="bi bi-person me-2"></i>Participant Information (Optional)
                            </h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Child's Name</label>
                                    <input type="text" name="child_name" class="form-control" placeholder="If booking for a child">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Age</label>
                                    <input type="number" name="child_age" class="form-control" min="0" max="18" placeholder="Age">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Gender</label>
                                    <select name="child_gender" class="form-control">
                                        <option value="">Select</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Additional Information -->
                        <div class="mb-3">
                            <h6 class="mb-3">
                                <i class="bi bi-sticky me-2"></i>Additional Information
                            </h6>
                            <div class="mb-3">
                                <label class="form-label">Special Notes</label>
                                <textarea name="special_notes" class="form-control" rows="3" placeholder="Any special requirements, allergies, or notes..."></textarea>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Note:</strong> Your booking request will be sent for admin approval. You'll receive a notification once it's approved or rejected.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="submitBookingBtn" <?= empty($available_classes) ? 'disabled' : '' ?>>
                            <i class="bi bi-send me-2"></i>Submit Booking Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Details Modal -->
    <div class="modal fade" id="viewDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Booking Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="bookingDetailsContent">
                    <!-- Content will be loaded via JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global variables
        let selectedClassId = null;
        let selectedClassElement = null;
        
        // Show loading overlay
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }
        
        // Hide loading overlay
        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }
        
        // Select class for booking
        function selectClass(element, classId) {
            // Remove selected class from all cards
            document.querySelectorAll('.modal-class-card').forEach(card => {
                card.classList.remove('selected');
                card.style.borderColor = '';
            });
            
            // Add selected class to clicked card
            element.classList.add('selected');
            element.style.borderColor = '#0d6efd';
            
            // Set selected values
            selectedClassId = classId;
            selectedClassElement = element;
            
            // Update hidden input
            document.getElementById('selected_class_id').value = classId;
            
            // Enable submit button
            document.getElementById('submitBookingBtn').disabled = false;
            
            // Scroll to selected card
            element.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        
        // View booking details
        function viewBookingDetails(bookingId) {
            showLoading();
            
            // In a real application, you would fetch booking details via AJAX
            // For now, we'll show a simple alert
            hideLoading();
            alert(`Booking #${bookingId} details:\n\nThis feature would show complete booking information, instructor contact, location details, and more.`);
            
            // Example AJAX implementation:
            /*
            fetch(`get-booking-details.php?id=${bookingId}`)
                .then(response => response.json())
                .then(data => {
                    const modal = new bootstrap.Modal(document.getElementById('viewDetailsModal'));
                    document.getElementById('bookingDetailsContent').innerHTML = `
                        <h5>${data.title}</h5>
                        <p>${data.description}</p>
                    `;
                    modal.show();
                })
                .finally(() => hideLoading());
            */
        }
        
        // Handle booking form submission
        document.getElementById('bookingForm')?.addEventListener('submit', function(e) {
            if (!selectedClassId) {
                e.preventDefault();
                alert('Please select a class first.');
                return false;
            }
            
            if (!confirm('Submit booking request? The admin will review and approve/reject your request.')) {
                e.preventDefault();
                return false;
            }
            
            showLoading();
            // Form will submit normally, loading overlay will be hidden on page reload
        });
        
        // Handle cancellation with confirmation
        document.querySelectorAll('form[onsubmit*="confirm"]').forEach(form => {
            form.onsubmit = function(e) {
                if (!confirm('Are you sure you want to cancel this booking?\n\nThis action cannot be undone and will free up your slot.')) {
                    return false;
                }
                showLoading();
            };
        });
        
        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Add animations to booking cards
            const cards = document.querySelectorAll('.booking-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('fade-in');
            });
            
            // Animate stat numbers
            const statValues = document.querySelectorAll('.stat-content h3');
            statValues.forEach(stat => {
                const target = parseInt(stat.textContent);
                let current = 0;
                const increment = Math.ceil(target / 30);
                const interval = 40;
                
                const timer = setInterval(() => {
                    current += increment;
                    if (current >= target) {
                        current = target;
                        clearInterval(timer);
                    }
                    stat.textContent = current.toLocaleString();
                }, interval);
            });
            
            // Auto-hide alerts after 5 seconds
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                    bsAlert.close();
                });
            }, 5000);
            
            // Add search form auto-submit
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                let searchTimer;
                searchInput.addEventListener('keyup', function() {
                    clearTimeout(searchTimer);
                    searchTimer = setTimeout(() => {
                        this.form.submit();
                    }, 500);
                });
            }
            
            // Check if URL has success parameter and scroll to alert
            if (window.location.search.includes('success')) {
                setTimeout(() => {
                    const successAlert = document.querySelector('.alert-success');
                    if (successAlert) {
                        successAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }, 300);
            }
        });
        
        // Add smooth scroll to top when clicking filter tabs
        document.querySelectorAll('.filter-tab').forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                const url = this.href;
                window.scrollTo({ top: 0, behavior: 'smooth' });
                setTimeout(() => {
                    window.location.href = url;
                }, 300);
            });
        });
    </script>
</body>
</html>