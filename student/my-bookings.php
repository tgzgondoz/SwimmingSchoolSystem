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

// Prepare query to get bookings
$query = "SELECT 
            b.*,
            c.*,
            i.name as instructor_name,
            p.status as payment_status,
            p.amount as payment_amount,
            CASE 
                WHEN c.start_time >= NOW() THEN 'upcoming'
                ELSE 'past'
            END as time_status
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

// Get available classes for new bookings
$available_classes = [];
$classes_query = "SELECT c.*, i.name as instructor_name
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

// Get booking statistics
$stats = [
    'total' => 0,
    'confirmed' => 0,
    'pending' => 0,
    'cancelled' => 0,
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings | Elite Swimming Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #f8f9fa;
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
            border-right: 1px solid #dee2e6;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 1000;
            padding: 20px 0;
        }
        
        .logo-area {
            padding: 0 25px 25px;
            border-bottom: 1px solid #dee2e6;
            margin-bottom: 20px;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #212529;
        }
        
        .logo-icon {
            width: 40px;
            height: 40px;
            background: #0d6efd;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }
        
        .logo-text h3 {
            font-weight: 600;
            font-size: 18px;
            margin: 0;
            color: #0d6efd;
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
            border-radius: 8px;
            color: #495057;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .nav-link:hover {
            background: #e9ecef;
            color: #0d6efd;
        }
        
        .nav-link.active {
            background: #0d6efd;
            color: white;
        }
        
        .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }
        
        .logout-section {
            padding: 20px;
            position: absolute;
            bottom: 0;
            width: 100%;
            border-top: 1px solid #dee2e6;
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
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left h1 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 5px;
            color: #212529;
        }
        
        .header-left p {
            color: #6c757d;
            margin: 0;
            font-size: 14px;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 15px;
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 8px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            background: #6c757d;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 500;
            font-size: 16px;
        }
        
        .user-info h5 {
            font-weight: 600;
            margin: 0;
            font-size: 14px;
        }
        
        .user-info p {
            color: #6c757d;
            font-size: 12px;
            margin: 0;
        }
        
        /* Alerts */
        .alert-custom {
            border-radius: 8px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        
        /* Quick Stats */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid #dee2e6;
            transition: all 0.2s ease;
        }
        
        .stat-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-bottom: 15px;
            color: white;
        }
        
        .stat-card:nth-child(1) .stat-icon { background: #0d6efd; }
        .stat-card:nth-child(2) .stat-icon { background: #198754; }
        .stat-card:nth-child(3) .stat-icon { background: #ffc107; }
        .stat-card:nth-child(4) .stat-icon { background: #dc3545; }
        
        .stat-content h3 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 5px;
            color: #212529;
        }
        
        .stat-content p {
            color: #6c757d;
            font-size: 13px;
            margin: 0;
        }
        
        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid #dee2e6;
        }
        
        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filter-tab {
            padding: 8px 16px;
            background: #f8f9fa;
            border-radius: 6px;
            color: #6c757d;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
            border: 1px solid #dee2e6;
        }
        
        .filter-tab:hover {
            background: #e9ecef;
            color: #212529;
        }
        
        .filter-tab.active {
            background: #0d6efd;
            color: white;
            border-color: #0d6efd;
        }
        
        .search-box {
            position: relative;
            max-width: 300px;
        }
        
        .search-box input {
            padding-left: 40px;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }
        
        /* Bookings Grid */
        .bookings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 768px) {
            .bookings-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Booking Card */
        .booking-card {
            background: white;
            border-radius: 10px;
            border: 1px solid #dee2e6;
            transition: all 0.2s ease;
            overflow: hidden;
        }
        
        .booking-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .booking-header {
            padding: 20px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        
        .booking-title {
            font-size: 16px;
            font-weight: 600;
            color: #212529;
            margin: 0;
        }
        
        .booking-status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-confirmed { background: rgba(25, 135, 84, 0.1); color: #198754; }
        .status-pending { background: rgba(255, 193, 7, 0.1); color: #ffc107; }
        .status-cancelled { background: rgba(220, 53, 69, 0.1); color: #dc3545; }
        .status-rejected { background: rgba(108, 117, 125, 0.1); color: #6c757d; }
        
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
            color: #0d6efd;
            font-size: 14px;
            width: 16px;
        }
        
        .detail-label {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 3px;
        }
        
        .detail-value {
            font-size: 13px;
            font-weight: 500;
            color: #212529;
        }
        
        .booking-footer {
            padding: 15px 20px;
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .booking-price {
            font-size: 18px;
            font-weight: 600;
            color: #0d6efd;
        }
        
        .booking-actions {
            display: flex;
            gap: 8px;
        }
        
        .btn-action {
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 500;
            font-size: 13px;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-action.primary {
            background: #0d6efd;
            color: white;
        }
        
        .btn-action.primary:hover {
            background: #0a58ca;
        }
        
        .btn-action.secondary {
            background: white;
            color: #212529;
            border: 1px solid #dee2e6;
        }
        
        .btn-action.secondary:hover {
            background: #f8f9fa;
        }
        
        .btn-action.danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-action.danger:hover {
            background: #b02a37;
        }
        
        /* Modal Styles */
        .modal-class-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-bottom: 15px;
        }
        
        .modal-class-card:hover {
            border-color: #0d6efd;
            background: #f8f9fa;
        }
        
        .modal-class-card.selected {
            border-color: #0d6efd;
            background: #e7f1ff;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            background: white;
            border-radius: 10px;
            border: 1px solid #dee2e6;
        }
        
        .empty-state-icon {
            font-size: 48px;
            color: #dee2e6;
            margin-bottom: 15px;
        }
        
        .empty-state h3 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
            color: #495057;
        }
        
        .empty-state p {
            color: #6c757d;
            margin-bottom: 20px;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
            font-size: 14px;
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
                padding: 15px;
            }
            
            .header {
                flex-direction: column;
                gap: 10px;
                text-align: center;
                padding: 15px;
            }
            
            .filter-tabs {
                justify-content: center;
            }
            
            .search-box {
                max-width: 100%;
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
            
            .user-profile {
                width: 100%;
                justify-content: center;
            }
        }
        
        @media (max-width: 576px) {
            .quick-stats {
                grid-template-columns: 1fr;
            }
            
            .filter-tabs {
                flex-direction: column;
            }
            
            .filter-tab {
                text-align: center;
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
                        <i class="bi bi-droplet"></i>
                    </div>
                    <div class="logo-text">
                        <h3>Elite Swimming</h3>
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
                    <p>Manage your swimming class bookings</p>
                </div>
                <div class="user-profile">
                    <div class="user-avatar">
                        <?= isset($user['name']) ? strtoupper(substr($user['name'], 0, 1)) : 'U' ?>
                    </div>
                    <div class="user-info">
                        <h5><?= htmlspecialchars($user['name'] ?? 'Student') ?></h5>
                        <p>Student ID: <?= htmlspecialchars($student_id) ?></p>
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
                    <div class="stat-icon">
                        <i class="bi bi-ticket-perforated"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $stats['total'] ?></h3>
                        <p>Total Bookings</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $stats['confirmed'] ?></h3>
                        <p>Confirmed</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $stats['pending'] ?></h3>
                        <p>Pending</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
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
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                    <div class="filter-tabs">
                        <a href="my-bookings.php" class="filter-tab <?= empty($filters['type']) && empty($filters['status']) ? 'active' : '' ?>">
                            All
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
                            <input type="text" name="search" class="form-control" placeholder="Search..." value="<?= htmlspecialchars($filters['search']) ?>">
                        </form>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bookClassModal">
                            <i class="bi bi-plus-circle me-2"></i>New Booking
                        </button>
                    </div>
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
                            No bookings match your current filters.
                        <?php else: ?>
                            You haven't booked any classes yet.
                        <?php endif; ?>
                    </p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bookClassModal">
                        <i class="bi bi-plus-circle me-2"></i>Book a Class
                    </button>
                </div>
            <?php else: ?>
                <div class="bookings-grid">
                    <?php foreach ($bookings as $booking): 
                        $start_time = strtotime($booking['start_time']);
                        $end_time = strtotime($booking['end_time']);
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
                                    <span class="booking-status <?= $status_class ?> mt-2 d-inline-block">
                                        <?= ucfirst($booking['status']) ?>
                                    </span>
                                </div>
                                <small class="text-muted">
                                    <?= htmlspecialchars($booking['instructor_name'] ?? 'TBA') ?>
                                </small>
                            </div>
                            
                            <div class="booking-body">
                                <div class="booking-details">
                                    <div class="detail-item">
                                        <i class="bi bi-calendar"></i>
                                        <div>
                                            <div class="detail-label">Date</div>
                                            <div class="detail-value"><?= date('M j, Y', $start_time) ?></div>
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
                                    <div class="mt-3 p-2 bg-light rounded">
                                        <small class="text-muted">Child:</small>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="bi bi-person text-primary"></i>
                                            <span><?= htmlspecialchars($booking['child_name']) ?></span>
                                            <?php if ($booking['child_age']): ?>
                                                <span class="text-muted">(<?= $booking['child_age'] ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="booking-footer">
                                <div class="booking-price">
                                    $<?= number_format($booking['price'], 2) ?>
                                </div>
                                <div class="booking-actions">
                                    <?php if ($start_time > time() && in_array($booking['status'], ['confirmed', 'pending'])): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Cancel this booking?')">
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
                                            Pay
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
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
                                    No available classes at the moment.
                                </div>
                            <?php else: ?>
                                <?php foreach ($available_classes as $class): ?>
                                    <div class="modal-class-card" onclick="selectClass(this, <?= $class['id'] ?>)">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h6 class="mb-0 fw-semibold"><?= htmlspecialchars($class['title']) ?></h6>
                                            <small class="text-muted">
                                                <?= $class['slots_available'] ?> slots
                                            </small>
                                        </div>
                                        <p class="text-muted mb-2 small">
                                            <i class="bi bi-person me-1"></i>
                                            <?= htmlspecialchars($class['instructor_name'] ?? 'TBA') ?>
                                        </p>
                                        <div class="mb-2">
                                            <i class="bi bi-calendar me-1"></i>
                                            <?= date('M j', strtotime($class['start_time'])) ?>
                                        </div>
                                        <div class="mb-3">
                                            <i class="bi bi-clock me-1"></i>
                                            <?= date('g:i A', strtotime($class['start_time'])) ?>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="h5 text-primary mb-0">$<?= number_format($class['price'], 2) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <input type="hidden" name="class_id" id="selected_class_id" required>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Child Information -->
                        <div class="mb-4">
                            <h6 class="mb-3">Participant Information (Optional)</h6>
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
                            <div class="mb-3">
                                <label class="form-label">Special Notes</label>
                                <textarea name="special_notes" class="form-control" rows="3" placeholder="Any special requirements or notes..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="submitBookingBtn" <?= empty($available_classes) ? 'disabled' : '' ?>>
                            Submit Booking Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Select class for booking
        function selectClass(element, classId) {
            // Remove selected class from all cards
            document.querySelectorAll('.modal-class-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Add selected class to clicked card
            element.classList.add('selected');
            
            // Update hidden input
            document.getElementById('selected_class_id').value = classId;
            
            // Enable submit button
            document.getElementById('submitBookingBtn').disabled = false;
        }
        
        // Handle booking form submission
        document.getElementById('bookingForm')?.addEventListener('submit', function(e) {
            if (!document.getElementById('selected_class_id').value) {
                e.preventDefault();
                alert('Please select a class first.');
                return false;
            }
            
            if (!confirm('Submit booking request?')) {
                e.preventDefault();
                return false;
            }
        });
        
        // Auto-hide alerts after 5 seconds
        document.querySelectorAll('.alert').forEach(alert => {
            setTimeout(() => {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                bsAlert.close();
            }, 5000);
        });
    </script>
</body>
</html>