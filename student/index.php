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

    // Completed classes (classes that have ended)
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

    // Get attendance progress (last 30 days)
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
    // Initialize empty values if queries fail
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

// Get current date and time
$current_date = date('l, F j, Y');
$current_time = date('g:i A');

// Load success message from session
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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0d6efd;
            --primary-dark: #0a58ca;
            --success: #198754;
            --warning: #ffc107;
            --danger: #dc3545;
            --light: #f8f9fa;
            --dark: #212529;
            --aqua: #0dcaf0;
            --blue-light: #e7f1ff;
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
            transition: all 0.3s ease;
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
        
        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 15px;
            padding: 30px;
            color: white;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
        }
        
        .banner-content {
            position: relative;
            z-index: 2;
        }
        
        .banner-content h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .banner-content p {
            font-size: 16px;
            opacity: 0.9;
            margin-bottom: 20px;
            max-width: 600px;
        }
        
        .date-time {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 10px 20px;
            border-radius: 50px;
            font-size: 14px;
            backdrop-filter: blur(10px);
        }
        
        /* Next Class Banner */
        .next-class-banner {
            background: linear-gradient(135deg, var(--success) 0%, #157347 100%);
            color: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            display: <?= $next_class ? 'block' : 'none' ?>;
        }
        
        .next-class-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .next-class-icon {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }
        
        .next-class-info h4 {
            font-family: 'Poppins', sans-serif;
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .next-class-info p {
            opacity: 0.9;
            margin: 0;
        }
        
        .countdown {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }
        
        .countdown-item {
            text-align: center;
        }
        
        .countdown-number {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .countdown-label {
            font-size: 12px;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 1px;
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
        .stat-card:nth-child(4) .stat-icon { background: linear-gradient(135deg, var(--aqua) 0%, #0891b2 100%); }
        
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
        
        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
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
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            overflow: hidden;
            border: 1px solid #e9ecef;
        }
        
        .panel-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .panel-header h3 {
            font-family: 'Poppins', sans-serif;
            font-size: 20px;
            font-weight: 600;
            margin: 0;
            color: var(--dark);
        }
        
        .panel-header .btn-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }
        
        .panel-header .btn-link:hover {
            color: var(--primary-dark);
            gap: 8px;
        }
        
        .panel-body {
            padding: 25px;
        }
        
        /* Class List */
        .class-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .class-item {
            display: flex;
            align-items: center;
            padding: 20px;
            background: var(--light);
            border-radius: 12px;
            border-left: 4px solid var(--primary);
            transition: all 0.3s ease;
        }
        
        .class-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        
        .class-time {
            min-width: 100px;
            text-align: center;
            padding-right: 20px;
            border-right: 1px solid #dee2e6;
        }
        
        .class-date {
            font-size: 14px;
            color: #6c757d;
            margin-bottom: 5px;
        }
        
        .class-hour {
            font-size: 20px;
            font-weight: 700;
            color: var(--dark);
        }
        
        .class-details {
            flex: 1;
            padding: 0 20px;
        }
        
        .class-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .class-instructor {
            font-size: 14px;
            color: #6c757d;
            margin-bottom: 5px;
        }
        
        .class-instructor i {
            color: var(--primary);
            margin-right: 5px;
        }
        
        .class-status {
            min-width: 100px;
            text-align: center;
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-badge.confirmed {
            background: rgba(25, 135, 84, 0.1);
            color: var(--success);
        }
        
        .status-badge.pending {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning);
        }
        
        /* Payment List */
        .payment-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .payment-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            background: var(--light);
            border-radius: 12px;
            transition: all 0.3s ease;
        }
        
        .payment-item:hover {
            background: #e9ecef;
        }
        
        .payment-info h5 {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--dark);
        }
        
        .payment-date {
            font-size: 13px;
            color: #6c757d;
        }
        
        .payment-amount {
            font-weight: 700;
            font-size: 18px;
            color: var(--dark);
        }
        
        .payment-status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .status-paid {
            background: rgba(25, 135, 84, 0.1);
            color: var(--success);
        }
        
        .status-pending {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning);
        }
        
        .status-failed {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
        }
        
        /* Progress Card */
        .progress-card {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
        }
        
        .progress-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .progress-icon {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .progress-content h4 {
            font-family: 'Poppins', sans-serif;
            font-size: 20px;
            margin-bottom: 5px;
        }
        
        .progress-content p {
            opacity: 0.8;
            font-size: 14px;
            margin: 0;
        }
        
        .progress-bar-container {
            margin-bottom: 15px;
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .progress-bar {
            height: 8px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #60a5fa, #93c5fd);
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }
        
        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px 15px;
            background: var(--light);
            border-radius: 12px;
            text-decoration: none;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        
        .action-btn:hover {
            background: white;
            border-color: var(--primary);
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(13, 110, 253, 0.1);
        }
        
        .action-btn i {
            font-size: 24px;
            color: var(--primary);
            margin-bottom: 10px;
        }
        
        .action-btn span {
            font-weight: 600;
            color: var(--dark);
            text-align: center;
            font-size: 14px;
        }
        
        /* Recommended Classes */
        .recommended-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .class-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .class-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .class-image {
            height: 150px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            position: relative;
            overflow: hidden;
        }
        
        .class-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .class-content {
            padding: 20px;
        }
        
        .class-content h4 {
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--dark);
        }
        
        .class-meta {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            font-size: 13px;
            color: #6c757d;
        }
        
        .class-meta i {
            color: var(--primary);
            margin-right: 5px;
        }
        
        .class-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
        }
        
        .slots-info {
            font-size: 13px;
            color: #6c757d;
        }
        
        .slots-info strong {
            color: var(--success);
        }
        
        /* Student Level Card */
        .level-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
            margin-bottom: 20px;
        }
        
        .level-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .level-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        
        .level-content h4 {
            font-family: 'Poppins', sans-serif;
            font-size: 20px;
            margin-bottom: 5px;
            color: var(--dark);
        }
        
        .level-content p {
            color: #6c757d;
            font-size: 14px;
            margin: 0;
        }
        
        .level-badge {
            display: inline-block;
            padding: 8px 16px;
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.3;
        }
        
        .empty-state h5 {
            font-weight: 600;
            margin-bottom: 10px;
            color: #495057;
        }
        
        .empty-state p {
            margin-bottom: 20px;
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
            
            .content-grid {
                grid-template-columns: 1fr;
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
            
            .welcome-banner {
                padding: 20px;
            }
            
            .banner-content h2 {
                font-size: 24px;
            }
            
            .countdown {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .countdown-item {
                flex: 1;
                min-width: 70px;
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
                        <h3>Elite Swimming Academy</h3>
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
                    <h1 id="greeting">Welcome back, <?= htmlspecialchars($user['name'] ?? 'Student') ?>! 👋</h1>
                    <p>Here's what's happening with your swimming journey today</p>
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
                <div class="next-class-banner fade-in">
                    <div class="next-class-header">
                        <div class="next-class-icon">
                            <i class="bi bi-clock"></i>
                        </div>
                        <div class="next-class-info">
                            <h4>Your Next Class</h4>
                            <p><?= htmlspecialchars($next_class['title']) ?> with <?= htmlspecialchars($next_class['instructor_name']) ?></p>
                        </div>
                    </div>
                    <div class="countdown" id="countdown">
                        <!-- Countdown will be populated by JavaScript -->
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Welcome Banner -->
            <div class="welcome-banner fade-in">
                <div class="banner-content">
                    <h2>Welcome to Your Dashboard!</h2>
                    <p>Track your progress, book new classes, and manage your swimming journey all in one place.</p>
                    <div class="date-time">
                        <i class="bi bi-calendar-check me-2"></i>
                        <span id="currentDate"><?= $current_date ?></span>
                        <i class="bi bi-clock ms-3 me-2"></i>
                        <span id="currentTime"><?= $current_time ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="stats-grid fade-in">
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
                    <div class="panel fade-in">
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
                                    <a href="classes.php" class="btn btn-primary">Browse Classes</a>
                                </div>
                            <?php else: ?>
                                <div class="class-list">
                                    <?php foreach ($upcoming_classes_list as $class): 
                                        $has_end_time = !empty($class['end_time']) && $class['end_time'] != '0000-00-00 00:00:00';
                                    ?>
                                        <div class="class-item">
                                            <div class="class-time">
                                                <div class="class-date">
                                                    <?= date('D, M j', strtotime($class['start_time'])) ?>
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
                    <div class="panel fade-in">
                        <div class="panel-header">
                            <h3>Recommended Classes</h3>
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
                                                <?php if (!empty($class['specialization'])): ?>
                                                    <p class="text-muted" style="font-size: 12px; margin-bottom: 10px;">
                                                        <i class="bi bi-star"></i> <?= htmlspecialchars($class['specialization']) ?>
                                                    </p>
                                                <?php endif; ?>
                                                <div class="class-actions">
                                                    <div class="slots-info">
                                                        <strong><?= $class['slots_available'] ?></strong> slots available
                                                    </div>
                                                    <a href="classes.php" class="btn btn-sm btn-primary">
                                                        View Details
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
                    <div class="level-card fade-in">
                        <div class="level-header">
                            <div class="level-icon">
                                <i class="bi bi-award"></i>
                            </div>
                            <div class="level-content">
                                <h4>Swimming Progress</h4>
                                <p>Keep up the great work!</p>
                            </div>
                        </div>
                        <div class="text-center">
                            <span class="level-badge"><?= $total_bookings ?> Classes Booked</span>
                        </div>
                    </div>
                    
                    <!-- Recent Payments -->
                    <div class="panel fade-in">
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
                    <div class="progress-card fade-in">
                        <div class="progress-header">
                            <div class="progress-icon">
                                <i class="bi bi-graph-up-arrow"></i>
                            </div>
                            <div class="progress-content">
                                <h4>Attendance Progress</h4>
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
                                <div class="h4 fw-bold"><?= $attendance_stats['total_classes'] ?></div>
                                <small>Total Classes</small>
                            </div>
                            <div class="text-center">
                                <div class="h4 fw-bold text-success"><?= $attendance_stats['attended_classes'] ?></div>
                                <small>Attended</small>
                            </div>
                            <div class="text-center">
                                <div class="h4 fw-bold text-primary"><?= $attendance_stats['upcoming_classes'] ?></div>
                                <small>Upcoming</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="panel fade-in">
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
                                    <span>Profile Settings</span>
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
            
            // Update date and time in real-time
            function updateDateTime() {
                const now = new Date();
                
                // Format date
                const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
                const dateString = now.toLocaleDateString('en-US', options);
                
                // Format time
                const timeString = now.toLocaleTimeString('en-US', { 
                    hour: 'numeric', 
                    minute: '2-digit',
                    hour12: true 
                });
                
                // Update elements
                const dateElement = document.getElementById('currentDate');
                const timeElement = document.getElementById('currentTime');
                
                if (dateElement) dateElement.textContent = dateString;
                if (timeElement) timeElement.textContent = timeString;
            }
            
            updateDateTime();
            setInterval(updateDateTime, 1000);
            
            // Update greeting based on time of day
            function updateGreeting() {
                const hour = new Date().getHours();
                let greeting = 'Good ';
                
                if (hour < 12) greeting += 'Morning';
                else if (hour < 18) greeting += 'Afternoon';
                else greeting += 'Evening';
                
                const userName = "<?= htmlspecialchars($user['name'] ?? 'Student') ?>";
                const header = document.getElementById('greeting');
                if (header) {
                    header.innerHTML = `${greeting}, ${userName}! <span class="wave">👋</span>`;
                }
            }
            
            updateGreeting();
            
            // Animate progress bars on scroll
            const observerOptions = {
                threshold: 0.5
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const progressFill = entry.target.querySelector('.progress-fill');
                        if (progressFill) {
                            const width = progressFill.style.width;
                            progressFill.style.width = '0';
                            setTimeout(() => {
                                progressFill.style.width = width;
                            }, 300);
                        }
                    }
                });
            }, observerOptions);
            
            document.querySelectorAll('.progress-card').forEach(card => {
                observer.observe(card);
            });
            
            // Add fade-in animation to cards
            const cards = document.querySelectorAll('.fade-in');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
            
            // Add hover effect to stat cards
            document.querySelectorAll('.stat-card').forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
            
            // Add count-up animation to stats
            const statValues = document.querySelectorAll('.stat-content h3');
            statValues.forEach(stat => {
                const target = parseInt(stat.textContent.replace('$', '').replace(',', ''));
                let current = 0;
                const increment = Math.ceil(target / 20);
                
                const timer = setInterval(() => {
                    current += increment;
                    if (current >= target) {
                        current = target;
                        clearInterval(timer);
                    }
                    // Format number with commas and add $ back for payment
                    if (stat.textContent.includes('$')) {
                        stat.textContent = '$' + current.toLocaleString();
                    } else {
                        stat.textContent = current.toLocaleString();
                    }
                }, 50);
            });
            
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