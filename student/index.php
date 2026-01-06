<?php
// student/index.php - Professional Student Dashboard
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

// Get student statistics with proper error handling
try {
    // Total bookings
    $total_bookings_stmt = $conn->prepare("SELECT COUNT(*) as total FROM bookings WHERE user_id = ?");
    $total_bookings_stmt->bind_param("i", $student_id);
    $total_bookings_stmt->execute();
    $total_bookings_result = $total_bookings_stmt->get_result();
    $total_bookings = $total_bookings_result->fetch_assoc()['total'] ?? 0;
    $total_bookings_stmt->close();

    // Upcoming classes
    $upcoming_classes_stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM bookings b 
        JOIN classes c ON b.class_id = c.id 
        WHERE b.user_id = ? 
        AND c.start_time >= NOW() 
        AND b.status = 'confirmed'
    ");
    $upcoming_classes_stmt->bind_param("i", $student_id);
    $upcoming_classes_stmt->execute();
    $upcoming_classes_result = $upcoming_classes_stmt->get_result();
    $upcoming_classes = $upcoming_classes_result->fetch_assoc()['total'] ?? 0;
    $upcoming_classes_stmt->close();

    // Completed classes
    $completed_classes_stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM bookings b 
        JOIN classes c ON b.class_id = c.id 
        WHERE b.user_id = ? 
        AND c.end_time < NOW() 
        AND b.status = 'confirmed'
        AND c.end_time != '0000-00-00 00:00:00'
    ");
    $completed_classes_stmt->bind_param("i", $student_id);
    $completed_classes_stmt->execute();
    $completed_classes_result = $completed_classes_stmt->get_result();
    $completed_classes = $completed_classes_result->fetch_assoc()['total'] ?? 0;
    $completed_classes_stmt->close();

    // Total payments
    $total_payments_stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as total 
        FROM payments 
        WHERE user_id = ? 
        AND status = 'paid'
    ");
    $total_payments_stmt->bind_param("i", $student_id);
    $total_payments_stmt->execute();
    $total_payments_result = $total_payments_stmt->get_result();
    $total_payments = $total_payments_result->fetch_assoc()['total'] ?? 0;
    $total_payments_stmt->close();

    // Get upcoming classes list
    $upcoming_classes_list_stmt = $conn->prepare("
        SELECT c.*, i.name as instructor_name, b.status as booking_status
        FROM bookings b
        JOIN classes c ON b.class_id = c.id
        LEFT JOIN instructors i ON c.instructor_id = i.id
        WHERE b.user_id = ? 
        AND c.start_time >= NOW() 
        AND b.status = 'confirmed'
        ORDER BY c.start_time ASC
        LIMIT 5
    ");
    $upcoming_classes_list_stmt->bind_param("i", $student_id);
    $upcoming_classes_list_stmt->execute();
    $upcoming_classes_list_result = $upcoming_classes_list_stmt->get_result();
    $upcoming_classes_list = $upcoming_classes_list_result->fetch_all(MYSQLI_ASSOC) ?: [];
    $upcoming_classes_list_stmt->close();

    // Get recent payments
    $recent_payments_stmt = $conn->prepare("
        SELECT * FROM payments 
        WHERE user_id = ? 
        ORDER BY payment_date DESC 
        LIMIT 5
    ");
    $recent_payments_stmt->bind_param("i", $student_id);
    $recent_payments_stmt->execute();
    $recent_payments_result = $recent_payments_stmt->get_result();
    $recent_payments = $recent_payments_result->fetch_all(MYSQLI_ASSOC) ?: [];
    $recent_payments_stmt->close();

    // Get class recommendations
    $recommended_classes_stmt = $conn->prepare("
        SELECT c.*, i.name as instructor_name, i.specialization, c.slots_available
        FROM classes c
        LEFT JOIN instructors i ON c.instructor_id = i.id
        WHERE c.start_time >= NOW() 
        AND c.slots_available > 0
        AND c.status = 'scheduled'
        ORDER BY c.start_time ASC
        LIMIT 4
    ");
    $recommended_classes_stmt->execute();
    $recommended_classes_result = $recommended_classes_stmt->get_result();
    $recommended_classes = $recommended_classes_result->fetch_all(MYSQLI_ASSOC) ?: [];
    $recommended_classes_stmt->close();

    // Get attendance progress
    $attendance_stats_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_classes,
            SUM(CASE WHEN c.end_time < NOW() THEN 1 ELSE 0 END) as attended_classes,
            SUM(CASE WHEN c.end_time >= NOW() THEN 1 ELSE 0 END) as upcoming_classes
        FROM bookings b
        JOIN classes c ON b.class_id = c.id
        WHERE b.user_id = ? 
        AND b.status = 'confirmed'
        AND c.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        AND c.end_time != '0000-00-00 00:00:00'
    ");
    $attendance_stats_stmt->bind_param("i", $student_id);
    $attendance_stats_stmt->execute();
    $attendance_stats_result = $attendance_stats_stmt->get_result();
    $attendance_stats = $attendance_stats_result->fetch_assoc() ?: ['total_classes' => 0, 'attended_classes' => 0, 'upcoming_classes' => 0];
    $attendance_stats_stmt->close();

    // Calculate next class time
    $next_class_stmt = $conn->prepare("
        SELECT c.*, i.name as instructor_name
        FROM bookings b
        JOIN classes c ON b.class_id = c.id
        LEFT JOIN instructors i ON c.instructor_id = i.id
        WHERE b.user_id = ? 
        AND c.start_time >= NOW() 
        AND b.status = 'confirmed'
        ORDER BY c.start_time ASC
        LIMIT 1
    ");
    $next_class_stmt->bind_param("i", $student_id);
    $next_class_stmt->execute();
    $next_class_result = $next_class_stmt->get_result();
    $next_class = $next_class_result->fetch_assoc() ?: null;
    $next_class_stmt->close();

} catch (Exception $e) {
    error_log("Dashboard query error: " . $e->getMessage());
    $total_bookings = 0;
    $upcoming_classes = 0;
    $completed_classes = 0;
    $total_payments = 0;
    $upcoming_classes_list = [];
    $recent_payments = [];
    $recommended_classes = [];
    $attendance_stats = ['total_classes' => 0, 'attended_classes' => 0, 'upcoming_classes' => 0];
    $next_class = null;
    $error_msg = "Unable to load dashboard data. Please try again later.";
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

$current_date = date('l, F j, Y');
$current_time = date('g:i A');

if (isset($_SESSION['success_msg'])) {
    $success_msg = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Elite Swimming Academy</title>
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
        
        /* Next Class Banner */
        .next-class-banner {
            background: #198754;
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            display: <?= $next_class ? 'block' : 'none' ?>;
        }
        
        .next-class-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .next-class-icon {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .next-class-info h4 {
            font-size: 18px;
            margin-bottom: 5px;
        }
        
        .next-class-info p {
            opacity: 0.9;
            margin: 0;
            font-size: 14px;
        }
        
        .countdown {
            display: flex;
            gap: 20px;
            margin-top: 15px;
        }
        
        .countdown-item {
            text-align: center;
        }
        
        .countdown-number {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .countdown-label {
            font-size: 11px;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        /* Stats Cards */
        .stats-grid {
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
            width: 48px;
            height: 48px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 15px;
            color: white;
        }
        
        .stat-card:nth-child(1) .stat-icon { background: #0d6efd; }
        .stat-card:nth-child(2) .stat-icon { background: #198754; }
        .stat-card:nth-child(3) .stat-icon { background: #ffc107; }
        .stat-card:nth-child(4) .stat-icon { background: #6c757d; }
        
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
        
        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Panel Styling */
        .panel {
            background: white;
            border-radius: 10px;
            border: 1px solid #dee2e6;
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .panel-header {
            padding: 15px 20px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .panel-header h3 {
            font-size: 16px;
            font-weight: 600;
            margin: 0;
            color: #212529;
        }
        
        .panel-header .btn-link {
            color: #0d6efd;
            text-decoration: none;
            font-weight: 500;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .panel-body {
            padding: 20px;
        }
        
        /* Class List */
        .class-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .class-item {
            display: flex;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #0d6efd;
            transition: all 0.2s ease;
        }
        
        .class-item:hover {
            background: #e9ecef;
        }
        
        .class-time {
            min-width: 80px;
            text-align: center;
            padding-right: 15px;
            border-right: 1px solid #dee2e6;
        }
        
        .class-date {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 5px;
        }
        
        .class-hour {
            font-size: 16px;
            font-weight: 600;
            color: #212529;
        }
        
        .class-details {
            flex: 1;
            padding: 0 15px;
        }
        
        .class-title {
            font-weight: 500;
            color: #212529;
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        .class-instructor {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 5px;
        }
        
        .class-instructor i {
            color: #0d6efd;
            margin-right: 5px;
        }
        
        .class-status {
            min-width: 80px;
            text-align: center;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .status-badge.confirmed {
            background: rgba(25, 135, 84, 0.1);
            color: #198754;
        }
        
        /* Payment List */
        .payment-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .payment-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        
        .payment-item:hover {
            background: #e9ecef;
        }
        
        .payment-info h5 {
            font-weight: 500;
            margin-bottom: 5px;
            color: #212529;
            font-size: 14px;
        }
        
        .payment-date {
            font-size: 12px;
            color: #6c757d;
        }
        
        .payment-amount {
            font-weight: 600;
            font-size: 14px;
            color: #212529;
        }
        
        .payment-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
            margin-left: 8px;
        }
        
        .status-paid {
            background: rgba(25, 135, 84, 0.1);
            color: #198754;
        }
        
        .status-pending {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }
        
        /* Progress Card */
        .progress-card {
            background: #6c757d;
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .progress-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .progress-icon {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        .progress-content h4 {
            font-size: 16px;
            margin-bottom: 5px;
        }
        
        .progress-content p {
            opacity: 0.8;
            font-size: 13px;
            margin: 0;
        }
        
        .progress-bar-container {
            margin-bottom: 10px;
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 13px;
        }
        
        .progress-bar {
            height: 6px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: #adb5bd;
            border-radius: 3px;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        
        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 15px 10px;
            background: #f8f9fa;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.2s ease;
            border: 1px solid #dee2e6;
        }
        
        .action-btn:hover {
            background: #e9ecef;
            border-color: #0d6efd;
        }
        
        .action-btn i {
            font-size: 20px;
            color: #0d6efd;
            margin-bottom: 8px;
        }
        
        .action-btn span {
            font-weight: 500;
            color: #212529;
            text-align: center;
            font-size: 12px;
        }
        
        /* Recommended Classes */
        .recommended-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 15px;
        }
        
        .class-card {
            background: white;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            transition: all 0.2s ease;
        }
        
        .class-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .class-image {
            height: 120px;
            background: #e9ecef;
            position: relative;
        }
        
        .class-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .class-content {
            padding: 15px;
        }
        
        .class-content h4 {
            font-weight: 500;
            margin-bottom: 8px;
            color: #212529;
            font-size: 14px;
        }
        
        .class-meta {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-bottom: 10px;
            font-size: 12px;
            color: #6c757d;
        }
        
        .class-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
        }
        
        .slots-info {
            font-size: 12px;
            color: #6c757d;
        }
        
        /* Student Level Card */
        .level-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid #dee2e6;
            margin-bottom: 20px;
        }
        
        .level-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .level-icon {
            width: 40px;
            height: 40px;
            background: #6f42c1;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }
        
        .level-content h4 {
            font-size: 16px;
            margin-bottom: 5px;
            color: #212529;
        }
        
        .level-content p {
            color: #6c757d;
            font-size: 13px;
            margin: 0;
        }
        
        .level-badge {
            display: inline-block;
            padding: 6px 12px;
            background: rgba(111, 66, 193, 0.1);
            color: #6f42c1;
            border-radius: 4px;
            font-weight: 500;
            font-size: 13px;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 32px;
            margin-bottom: 10px;
            opacity: 0.3;
        }
        
        .empty-state h5 {
            font-weight: 500;
            margin-bottom: 5px;
            color: #495057;
            font-size: 14px;
        }
        
        .empty-state p {
            margin-bottom: 15px;
            font-size: 13px;
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
            
            .content-grid {
                grid-template-columns: 1fr;
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
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .recommended-grid {
                grid-template-columns: 1fr;
            }
            
            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .user-profile {
                width: 100%;
                justify-content: center;
            }
        }
        
        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .next-class-banner {
                padding: 15px;
            }
            
            .countdown {
                gap: 10px;
            }
            
            .countdown-number {
                font-size: 20px;
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
                    <a href="index.php" class="nav-link active">
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
                    <a href="my-bookings.php" class="nav-link">
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
                    <h1>Welcome, <?= htmlspecialchars($user['name'] ?? 'Student') ?>!</h1>
                    <p>Manage your swimming classes and progress</p>
                </div>
                <div class="user-profile">
                    <div class="user-avatar">
                        <?= isset($user['name']) ? strtoupper(substr($user['name'], 0, 1)) : 'S' ?>
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
            
            <!-- Next Class Banner -->
            <?php if ($next_class): ?>
                <div class="next-class-banner">
                    <div class="next-class-header">
                        <div class="next-class-icon">
                            <i class="bi bi-clock"></i>
                        </div>
                        <div class="next-class-info">
                            <h4>Next Class</h4>
                            <p><?= htmlspecialchars($next_class['title']) ?> with <?= htmlspecialchars($next_class['instructor_name']) ?></p>
                        </div>
                    </div>
                    <div class="countdown" id="countdown">
                        <!-- Countdown will be populated by JavaScript -->
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-ticket-perforated"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $total_bookings ?></h3>
                        <p>Total Bookings</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $upcoming_classes ?></h3>
                        <p>Upcoming Classes</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $completed_classes ?></h3>
                        <p>Completed Classes</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                    <div class="stat-content">
                        <h3>$<?= number_format($total_payments, 2) ?></h3>
                        <p>Total Payments</p>
                    </div>
                </div>
            </div>
            
            <!-- Main Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Upcoming Classes -->
                    <div class="panel">
                        <div class="panel-header">
                            <h3>Upcoming Classes</h3>
                            <a href="my-bookings.php" class="btn-link">
                                View All <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                        <div class="panel-body">
                            <?php if (empty($upcoming_classes_list)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-calendar-x"></i>
                                    <h5>No Upcoming Classes</h5>
                                    <p>You don't have any upcoming classes booked.</p>
                                    <a href="classes.php" class="btn btn-primary btn-sm">Browse Classes</a>
                                </div>
                            <?php else: ?>
                                <div class="class-list">
                                    <?php foreach ($upcoming_classes_list as $class): ?>
                                        <div class="class-item">
                                            <div class="class-time">
                                                <div class="class-date">
                                                    <?= date('M j', strtotime($class['start_time'])) ?>
                                                </div>
                                                <div class="class-hour">
                                                    <?= date('g:i A', strtotime($class['start_time'])) ?>
                                                </div>
                                            </div>
                                            <div class="class-details">
                                                <div class="class-title"><?= htmlspecialchars($class['title']) ?></div>
                                                <div class="class-instructor">
                                                    <i class="bi bi-person"></i>
                                                    <?= htmlspecialchars($class['instructor_name']) ?>
                                                </div>
                                            </div>
                                            <div class="class-status">
                                                <span class="status-badge confirmed">Confirmed</span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Recommended Classes -->
                    <div class="panel">
                        <div class="panel-header">
                            <h3>Available Classes</h3>
                            <a href="classes.php" class="btn-link">
                                Browse All <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                        <div class="panel-body">
                            <?php if (empty($recommended_classes)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-calendar-x"></i>
                                    <p>No classes available at the moment.</p>
                                </div>
                            <?php else: ?>
                                <div class="recommended-grid">
                                    <?php foreach ($recommended_classes as $class): ?>
                                        <div class="class-card">
                                            <div class="class-image">
                                                <div class="class-badge">
                                                    <?= $class['slots_available'] ?> slots
                                                </div>
                                            </div>
                                            <div class="class-content">
                                                <h4><?= htmlspecialchars($class['title']) ?></h4>
                                                <div class="class-meta">
                                                    <span><i class="bi bi-person"></i> <?= htmlspecialchars($class['instructor_name']) ?></span>
                                                    <span><i class="bi bi-clock"></i> <?= date('M j, g:i A', strtotime($class['start_time'])) ?></span>
                                                </div>
                                                <div class="class-actions">
                                                    <div class="slots-info">
                                                        <?= $class['slots_available'] ?> slots available
                                                    </div>
                                                    <a href="classes.php" class="btn btn-primary btn-sm">
                                                        View
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div class="right-column">
                    <!-- Student Level -->
                    <div class="level-card">
                        <div class="level-header">
                            <div class="level-icon">
                                <i class="bi bi-award"></i>
                            </div>
                            <div class="level-content">
                                <h4>Your Progress</h4>
                                <p><?= $total_bookings ?> classes booked</p>
                            </div>
                        </div>
                        <div class="text-center">
                            <span class="level-badge">Active Student</span>
                        </div>
                    </div>
                    
                    <!-- Recent Payments -->
                    <div class="panel">
                        <div class="panel-header">
                            <h3>Recent Payments</h3>
                            <a href="payments.php" class="btn-link">
                                View All <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                        <div class="panel-body">
                            <?php if (empty($recent_payments)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-credit-card"></i>
                                    <p>No payment history</p>
                                </div>
                            <?php else: ?>
                                <div class="payment-list">
                                    <?php foreach ($recent_payments as $payment): ?>
                                        <div class="payment-item">
                                            <div class="payment-info">
                                                <h5><?= htmlspecialchars($payment['description'] ?? 'Class Payment') ?></h5>
                                                <div class="payment-date">
                                                    <?= date('M j, Y', strtotime($payment['payment_date'])) ?>
                                                </div>
                                            </div>
                                            <div>
                                                <span class="payment-amount">$<?= number_format($payment['amount'], 2) ?></span>
                                                <span class="payment-status status-<?= $payment['status'] ?>">
                                                    <?= ucfirst($payment['status']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Progress Tracking -->
                    <div class="progress-card">
                        <div class="progress-header">
                            <div class="progress-icon">
                                <i class="bi bi-graph-up-arrow"></i>
                            </div>
                            <div class="progress-content">
                                <h4>Attendance</h4>
                                <p>Last 30 days</p>
                            </div>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-label">
                                <span>Attendance Rate</span>
                                <span>
                                    <?= $attendance_stats['total_classes'] > 0 ? 
                                        round(($attendance_stats['attended_classes'] / $attendance_stats['total_classes']) * 100) : 0 ?>%
                                </span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?= $attendance_stats['total_classes'] > 0 ? 
                                    ($attendance_stats['attended_classes'] / $attendance_stats['total_classes']) * 100 : 0 ?>%"></div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between mt-3">
                            <div class="text-center">
                                <div class="h5 fw-bold"><?= $attendance_stats['total_classes'] ?></div>
                                <small>Total</small>
                            </div>
                            <div class="text-center">
                                <div class="h5 fw-bold"><?= $attendance_stats['attended_classes'] ?></div>
                                <small>Attended</small>
                            </div>
                            <div class="text-center">
                                <div class="h5 fw-bold"><?= $attendance_stats['upcoming_classes'] ?></div>
                                <small>Upcoming</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="panel">
                        <div class="panel-header">
                            <h3>Quick Actions</h3>
                        </div>
                        <div class="panel-body">
                            <div class="quick-actions">
                                <a href="classes.php" class="action-btn">
                                    <i class="bi bi-search"></i>
                                    <span>Browse Classes</span>
                                </a>
                                <a href="my-bookings.php" class="action-btn">
                                    <i class="bi bi-ticket-perforated"></i>
                                    <span>My Bookings</span>
                                </a>
                                <a href="payments.php" class="action-btn">
                                    <i class="bi bi-credit-card"></i>
                                    <span>Make Payment</span>
                                </a>
                                <a href="profile.php" class="action-btn">
                                    <i class="bi bi-gear"></i>
                                    <span>Profile</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Countdown timer for next class
            <?php if ($next_class): ?>
            function updateCountdown() {
                const nextClassTime = new Date("<?= $next_class['start_time'] ?>").getTime();
                const now = new Date().getTime();
                const distance = nextClassTime - now;
                
                if (distance < 0) {
                    document.getElementById('countdown').innerHTML = '<div class="text-center">Class has started!</div>';
                    return;
                }
                
                const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((distance % (1000 * 60)) / 1000);
                
                const countdownHTML = `
                    <div class="countdown-item">
                        <div class="countdown-number">${days}</div>
                        <div class="countdown-label">Days</div>
                    </div>
                    <div class="countdown-item">
                        <div class="countdown-number">${hours}</div>
                        <div class="countdown-label">Hours</div>
                    </div>
                    <div class="countdown-item">
                        <div class="countdown-number">${minutes}</div>
                        <div class="countdown-label">Minutes</div>
                    </div>
                    <div class="countdown-item">
                        <div class="countdown-number">${seconds}</div>
                        <div class="countdown-label">Seconds</div>
                    </div>
                `;
                
                document.getElementById('countdown').innerHTML = countdownHTML;
            }
            
            updateCountdown();
            setInterval(updateCountdown, 1000);
            <?php endif; ?>
            
            // Auto-hide alerts after 5 seconds
            document.querySelectorAll('.alert').forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
        });
    </script>
</body>
</html>