<?php
// admin/index.php - Professional Admin Dashboard
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

// Get admin statistics with proper error handling
try {
    // Total Students
    $total_students_stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND (status = 'active' OR status IS NULL OR status = '')");
    $total_students_stmt->execute();
    $total_students_result = $total_students_stmt->get_result();
    $total_students = $total_students_result->fetch_assoc()['total'] ?? 0;
    $total_students_stmt->close();

    // Total Instructors
    $total_instructors_stmt = $conn->prepare("SELECT COUNT(*) as total FROM instructors WHERE status = 'active'");
    $total_instructors_stmt->execute();
    $total_instructors_result = $total_instructors_stmt->get_result();
    $total_instructors = $total_instructors_result->fetch_assoc()['total'] ?? 0;
    $total_instructors_stmt->close();

    // Active Classes (scheduled)
    $active_classes_stmt = $conn->prepare("SELECT COUNT(*) as total FROM classes WHERE status = 'scheduled' AND start_time >= NOW()");
    $active_classes_stmt->execute();
    $active_classes_result = $active_classes_stmt->get_result();
    $active_classes = $active_classes_result->fetch_assoc()['total'] ?? 0;
    $active_classes_stmt->close();

    // Total Bookings
    $total_bookings_stmt = $conn->prepare("SELECT COUNT(*) as total FROM bookings");
    $total_bookings_stmt->execute();
    $total_bookings_result = $total_bookings_stmt->get_result();
    $total_bookings = $total_bookings_result->fetch_assoc()['total'] ?? 0;
    $total_bookings_stmt->close();

    // Today's Bookings
    $today_bookings_stmt = $conn->prepare("SELECT COUNT(*) as total FROM bookings WHERE DATE(created_at) = CURDATE()");
    $today_bookings_stmt->execute();
    $today_bookings_result = $today_bookings_stmt->get_result();
    $today_bookings = $today_bookings_result->fetch_assoc()['total'] ?? 0;
    $today_bookings_stmt->close();

    // Total Revenue
    $total_revenue_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'paid'");
    $total_revenue_stmt->execute();
    $total_revenue_result = $total_revenue_stmt->get_result();
    $total_revenue = $total_revenue_result->fetch_assoc()['total'] ?? 0;
    $total_revenue_stmt->close();

    // Today's Revenue
    $today_revenue_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'paid' AND DATE(payment_date) = CURDATE()");
    $today_revenue_stmt->execute();
    $today_revenue_result = $today_revenue_stmt->get_result();
    $today_revenue = $today_revenue_result->fetch_assoc()['total'] ?? 0;
    $today_revenue_stmt->close();

    // Pending Bookings
    $pending_bookings_stmt = $conn->prepare("SELECT COUNT(*) as total FROM bookings WHERE status = 'pending'");
    $pending_bookings_stmt->execute();
    $pending_bookings_result = $pending_bookings_stmt->get_result();
    $pending_bookings = $pending_bookings_result->fetch_assoc()['total'] ?? 0;
    $pending_bookings_stmt->close();

    // Pending Payments
    $pending_payments_stmt = $conn->prepare("SELECT COUNT(*) as total FROM payments WHERE status = 'pending'");
    $pending_payments_stmt->execute();
    $pending_payments_result = $pending_payments_stmt->get_result();
    $pending_payments = $pending_payments_result->fetch_assoc()['total'] ?? 0;
    $pending_payments_stmt->close();

    // Class Capacity Utilization
    $capacity_stmt = $conn->prepare("
        SELECT 
            AVG((slots_total - slots_available) / slots_total * 100) as avg_utilization,
            SUM(CASE WHEN slots_available = 0 THEN 1 ELSE 0 END) as full_classes,
            SUM(CASE WHEN slots_available > 0 AND slots_available < slots_total THEN 1 ELSE 0 END) as partial_classes,
            SUM(CASE WHEN slots_available = slots_total THEN 1 ELSE 0 END) as empty_classes
        FROM classes 
        WHERE start_time >= NOW() 
        AND status = 'scheduled'
    ");
    $capacity_stmt->execute();
    $capacity_result = $capacity_stmt->get_result();
    $capacity_stats = $capacity_result->fetch_assoc() ?: [
        'avg_utilization' => 0, 
        'full_classes' => 0, 
        'partial_classes' => 0, 
        'empty_classes' => 0
    ];
    $capacity_stmt->close();

    // Get upcoming classes list
    $upcoming_classes_stmt = $conn->prepare("
        SELECT c.*, i.name as instructor_name,
               (c.slots_total - c.slots_available) as booked_slots
        FROM classes c
        LEFT JOIN instructors i ON c.instructor_id = i.id
        WHERE c.start_time >= NOW() 
        AND c.status = 'scheduled'
        ORDER BY c.start_time ASC
        LIMIT 5
    ");
    $upcoming_classes_stmt->execute();
    $upcoming_classes_result = $upcoming_classes_stmt->get_result();
    $upcoming_classes = $upcoming_classes_result->fetch_all(MYSQLI_ASSOC) ?: [];
    $upcoming_classes_stmt->close();

    // Get recent bookings
    $recent_bookings_stmt = $conn->prepare("
        SELECT b.*, u.name as student_name, c.title as class_name,
               p.status as payment_status, p.amount
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        JOIN classes c ON b.class_id = c.id
        LEFT JOIN payments p ON p.booking_id = b.id
        ORDER BY b.created_at DESC
        LIMIT 6
    ");
    $recent_bookings_stmt->execute();
    $recent_bookings_result = $recent_bookings_stmt->get_result();
    $recent_bookings = $recent_bookings_result->fetch_all(MYSQLI_ASSOC) ?: [];
    $recent_bookings_stmt->close();

    // Get recent payments
    $recent_payments_stmt = $conn->prepare("
        SELECT p.*, u.name as student_name, b.child_name
        FROM payments p
        LEFT JOIN users u ON p.user_id = u.id
        LEFT JOIN bookings b ON p.booking_id = b.id
        ORDER BY p.payment_date DESC
        LIMIT 5
    ");
    $recent_payments_stmt->execute();
    $recent_payments_result = $recent_payments_stmt->get_result();
    $recent_payments = $recent_payments_result->fetch_all(MYSQLI_ASSOC) ?: [];
    $recent_payments_stmt->close();

    // Get top instructors by bookings
    $top_instructors_stmt = $conn->prepare("
        SELECT i.*, 
               COUNT(b.id) as total_bookings,
               COUNT(DISTINCT c.id) as total_classes
        FROM instructors i
        LEFT JOIN classes c ON c.instructor_id = i.id
        LEFT JOIN bookings b ON b.class_id = c.id
        WHERE i.status = 'active'
        GROUP BY i.id
        ORDER BY total_bookings DESC
        LIMIT 4
    ");
    $top_instructors_stmt->execute();
    $top_instructors_result = $top_instructors_stmt->get_result();
    $top_instructors = $top_instructors_result->fetch_all(MYSQLI_ASSOC) ?: [];
    $top_instructors_stmt->close();

    // Get class distribution by age group
    $class_distribution_stmt = $conn->prepare("
        SELECT age_group, COUNT(*) as count
        FROM classes
        WHERE start_time >= NOW()
        GROUP BY age_group
        ORDER BY count DESC
    ");
    $class_distribution_stmt->execute();
    $class_distribution_result = $class_distribution_stmt->get_result();
    $class_distribution = $class_distribution_result->fetch_all(MYSQLI_ASSOC) ?: [];
    $class_distribution_stmt->close();

    // Calculate monthly revenue for chart
    $monthly_revenue_stmt = $conn->prepare("
        SELECT 
            MONTH(payment_date) as month,
            YEAR(payment_date) as year,
            COALESCE(SUM(amount), 0) as total
        FROM payments 
        WHERE status = 'paid'
        AND payment_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY YEAR(payment_date), MONTH(payment_date)
        ORDER BY year, month
    ");
    $monthly_revenue_stmt->execute();
    $monthly_revenue_result = $monthly_revenue_stmt->get_result();
    $monthly_revenue_data = $monthly_revenue_result->fetch_all(MYSQLI_ASSOC) ?: [];
    $monthly_revenue_stmt->close();

    // Prepare chart data
    $months = [];
    $revenue_data = [];
    $month_names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    
    for ($i = 5; $i >= 0; $i--) {
        $date = new DateTime("first day of -$i months");
        $months[] = $month_names[$date->format('n') - 1];
        $revenue_data[] = 0;
    }

    foreach ($monthly_revenue_data as $row) {
        $date = new DateTime($row['year'] . '-' . str_pad($row['month'], 2, '0', STR_PAD_LEFT) . '-01');
        $diff = (new DateTime('first day of -5 months'))->diff($date)->m;
        if ($diff >= 0 && $diff <= 5) {
            $revenue_data[$diff] = (float)$row['total'];
        }
    }

    // Calculate monthly bookings for chart
    $monthly_bookings_stmt = $conn->prepare("
        SELECT 
            MONTH(created_at) as month,
            YEAR(created_at) as year,
            COUNT(*) as total
        FROM bookings 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY YEAR(created_at), MONTH(created_at)
        ORDER BY year, month
    ");
    $monthly_bookings_stmt->execute();
    $monthly_bookings_result = $monthly_bookings_stmt->get_result();
    $monthly_bookings_data = $monthly_bookings_result->fetch_all(MYSQLI_ASSOC) ?: [];
    $monthly_bookings_stmt->close();

    // Prepare bookings chart data
    $bookings_data = array_fill(0, 6, 0);
    
    foreach ($monthly_bookings_data as $row) {
        $date = new DateTime($row['year'] . '-' . str_pad($row['month'], 2, '0', STR_PAD_LEFT) . '-01');
        $diff = (new DateTime('first day of -5 months'))->diff($date)->m;
        if ($diff >= 0 && $diff <= 5) {
            $bookings_data[$diff] = (int)$row['total'];
        }
    }

} catch (Exception $e) {
    error_log("Admin dashboard query error: " . $e->getMessage());
    // Initialize empty values if queries fail
    $total_students = $total_instructors = $active_classes = $total_bookings = $today_bookings = 0;
    $total_revenue = $today_revenue = $pending_bookings = $pending_payments = 0;
    $capacity_stats = ['avg_utilization' => 0, 'full_classes' => 0, 'partial_classes' => 0, 'empty_classes' => 0];
    $upcoming_classes = $recent_bookings = $recent_payments = $top_instructors = $class_distribution = [];
    $months = ['Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $revenue_data = $bookings_data = array_fill(0, 6, 0);
    $error_msg = "Unable to load dashboard data. Please try again later.";
}

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

// Get current date and time
$current_date = date('l, F j, Y');
$current_time = date('g:i A');

// Get school info from settings
$school_name = "Elite Swimming Academy";
$school_email = "admin@aquaflow.com";

$settings_stmt = $conn->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('school_name', 'school_email')");
if ($settings_stmt) {
    $settings_stmt->execute();
    $settings_result = $settings_stmt->get_result();
    while ($setting = $settings_result->fetch_assoc()) {
        if ($setting['setting_key'] == 'school_name') {
            $school_name = $setting['setting_value'];
        } elseif ($setting['setting_key'] == 'school_email') {
            $school_email = $setting['setting_value'];
        }
    }
    $settings_stmt->close();
}

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
    <title>Admin Dashboard | <?= htmlspecialchars($school_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        /* Quick Stats */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .quick-stat {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .quick-stat:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(0,0,0,0.1);
        }
        
        .quick-stat i {
            font-size: 24px;
            margin-bottom: 10px;
            color: var(--primary);
        }
        
        .quick-stat h4 {
            font-size: 24px;
            font-weight: 700;
            margin: 0;
            color: var(--dark);
        }
        
        .quick-stat p {
            color: #6c757d;
            font-size: 14px;
            margin: 5px 0 0 0;
        }
        
        /* Main Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
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
        .stat-card:nth-child(6) .stat-icon { background: linear-gradient(135deg, var(--pink) 0%, #b52e6f 100%); }
        
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
        
        /* Charts Section */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 992px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .chart-header h3 {
            font-size: 20px;
            font-weight: 600;
            margin: 0;
            color: var(--dark);
        }
        
        .chart-wrapper {
            position: relative;
            height: 250px;
        }
        
        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
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
            max-height: 400px;
            overflow-y: auto;
        }
        
        /* Class List */
        .class-list, .booking-list, .payment-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .class-item, .booking-item, .payment-item {
            display: flex;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            transition: all 0.3s ease;
            border-left: 4px solid var(--primary);
        }
        
        .class-item:hover, .booking-item:hover, .payment-item:hover {
            background: #e9ecef;
            transform: translateX(3px);
        }
        
        .class-info, .booking-info, .payment-info {
            flex: 1;
        }
        
        .class-info h6, .booking-info h6, .payment-info h6 {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--dark);
        }
        
        .class-meta, .booking-meta, .payment-meta {
            display: flex;
            gap: 15px;
            font-size: 13px;
            color: #6c757d;
        }
        
        .class-meta i, .booking-meta i, .payment-meta i {
            color: var(--primary);
            margin-right: 5px;
        }
        
        .class-status, .booking-status, .payment-status {
            min-width: 100px;
            text-align: right;
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-scheduled { background: rgba(13, 110, 253, 0.1); color: var(--primary); }
        .status-confirmed { background: rgba(25, 135, 84, 0.1); color: var(--success); }
        .status-pending { background: rgba(255, 193, 7, 0.1); color: var(--warning); }
        .status-paid { background: rgba(25, 135, 84, 0.1); color: var(--success); }
        .status-failed { background: rgba(220, 53, 69, 0.1); color: var(--danger); }
        
        .capacity-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            background: rgba(13, 110, 253, 0.1);
            color: var(--primary);
        }
        
        /* Instructor Cards */
        .instructor-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .instructor-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .instructor-card:hover {
            background: #e9ecef;
            transform: translateY(-3px);
        }
        
        .instructor-avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 20px;
            margin: 0 auto 15px;
        }
        
        .instructor-info h6 {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--dark);
        }
        
        .instructor-info p {
            font-size: 13px;
            color: #6c757d;
            margin: 0 0 10px 0;
        }
        
        .instructor-stats {
            display: flex;
            justify-content: center;
            gap: 15px;
            font-size: 12px;
        }
        
        .instructor-stat {
            text-align: center;
        }
        
        .instructor-stat span {
            display: block;
            font-weight: 700;
            color: var(--dark);
        }
        
        /* Capacity Stats */
        .capacity-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .capacity-stat {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 1px solid #e9ecef;
        }
        
        .capacity-stat h5 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .capacity-stat:nth-child(1) h5 { color: var(--success); }
        .capacity-stat:nth-child(2) h5 { color: var(--warning); }
        .capacity-stat:nth-child(3) h5 { color: var(--danger); }
        .capacity-stat:nth-child(4) h5 { color: var(--primary); }
        
        .capacity-stat p {
            font-size: 13px;
            color: #6c757d;
            margin: 0;
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease forwards;
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
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .welcome-banner {
                padding: 20px;
            }
            
            .banner-content h2 {
                font-size: 24px;
            }
            
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 576px) {
            .quick-stats {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Custom Scrollbar */
        .panel-body::-webkit-scrollbar {
            width: 6px;
        }
        
        .panel-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .panel-body::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
        }
        
        .panel-body::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
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
                        <h3><?= htmlspecialchars($school_name) ?></h3>
                        <span>Admin Portal</span>
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
                    <h1>Admin Dashboard 👑</h1>
                    <p>Welcome back, <?= htmlspecialchars($user['name'] ?? 'Admin') ?>! Manage your swimming academy efficiently.</p>
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
            
            <!-- Welcome Banner -->
            <div class="welcome-banner fade-in">
                <div class="banner-content">
                    <h2>System Overview</h2>
                    <p>Monitor your swim academy's performance, track key metrics, and manage operations efficiently.</p>
                    <div class="date-time">
                        <i class="bi bi-calendar-check me-2"></i>
                        <span id="currentDate"><?= $current_date ?></span>
                        <i class="bi bi-clock ms-3 me-2"></i>
                        <span id="currentTime"><?= $current_time ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Quick Stats -->
            <div class="quick-stats fade-in">
                <div class="quick-stat">
                    <i class="bi bi-calendar-check"></i>
                    <h4><?= $today_bookings ?></h4>
                    <p>Today's Bookings</p>
                </div>
                <div class="quick-stat">
                    <i class="bi bi-currency-dollar"></i>
                    <h4>$<?= number_format($today_revenue, 2) ?></h4>
                    <p>Today's Revenue</p>
                </div>
                <div class="quick-stat">
                    <i class="bi bi-clock-history"></i>
                    <h4><?= $pending_bookings ?></h4>
                    <p>Pending Bookings</p>
                </div>
                <div class="quick-stat">
                    <i class="bi bi-activity"></i>
                    <h4><?= $pending_payments ?></h4>
                    <p>Pending Payments</p>
                </div>
            </div>
            
            <!-- Main Stats -->
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-people"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $total_students ?></h3>
                        <p>Total Students</p>
                        <div class="stat-subtext">Active accounts</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-person-badge"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $total_instructors ?></h3>
                        <p>Active Instructors</p>
                        <div class="stat-subtext">Teaching staff</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-calendar-week"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $active_classes ?></h3>
                        <p>Upcoming Classes</p>
                        <div class="stat-subtext">Scheduled sessions</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-ticket-perforated"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $total_bookings ?></h3>
                        <p>Total Bookings</p>
                        <div class="stat-subtext">All-time bookings</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                    <div class="stat-content">
                        <h3>$<?= number_format($total_revenue, 2) ?></h3>
                        <p>Total Revenue</p>
                        <div class="stat-subtext">All-time income</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-bar-chart"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= number_format($capacity_stats['avg_utilization'], 1) ?>%</h3>
                        <p>Class Utilization</p>
                        <div class="stat-subtext">Average occupancy rate</div>
                    </div>
                </div>
            </div>
            
            <!-- Charts Section -->
            <div class="charts-grid">
                <div class="chart-container fade-in">
                    <div class="chart-header">
                        <h3>Revenue Overview</h3>
                        <span class="text-muted" style="font-size: 14px;">Last 6 Months</span>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
                
                <div class="chart-container fade-in">
                    <div class="chart-header">
                        <h3>Bookings Trend</h3>
                        <span class="text-muted" style="font-size: 14px;">Last 6 Months</span>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="bookingsChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Upcoming Classes -->
                    <div class="panel fade-in">
                        <div class="panel-header">
                            <h3>Upcoming Classes</h3>
                            <a href="classes.php" class="btn-link">
                                View All <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                        <div class="panel-body">
                            <?php if (empty($upcoming_classes)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-calendar-x"></i>
                                    <p>No upcoming classes scheduled</p>
                                </div>
                            <?php else: ?>
                                <div class="class-list">
                                    <?php foreach ($upcoming_classes as $class): 
                                        $utilization = $class['slots_total'] > 0 ? 
                                            round(($class['booked_slots'] / $class['slots_total']) * 100) : 0;
                                    ?>
                                        <div class="class-item">
                                            <div class="class-info">
                                                <h6><?= htmlspecialchars($class['title']) ?></h6>
                                                <div class="class-meta">
                                                    <span><i class="bi bi-person"></i> <?= htmlspecialchars($class['instructor_name'] ?? 'Unassigned') ?></span>
                                                    <span><i class="bi bi-clock"></i> <?= date('M d, g:i A', strtotime($class['start_time'])) ?></span>
                                                </div>
                                            </div>
                                            <div class="class-status">
                                                <span class="capacity-badge"><?= $utilization ?>% Full</span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Recent Bookings -->
                    <div class="panel fade-in">
                        <div class="panel-header">
                            <h3>Recent Bookings</h3>
                            <a href="bookings.php" class="btn-link">
                                View All <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                        <div class="panel-body">
                            <?php if (empty($recent_bookings)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-inbox"></i>
                                    <p>No recent bookings</p>
                                </div>
                            <?php else: ?>
                                <div class="booking-list">
                                    <?php foreach ($recent_bookings as $booking): ?>
                                        <div class="booking-item">
                                            <div class="booking-info">
                                                <h6><?= htmlspecialchars($booking['student_name']) ?></h6>
                                                <div class="booking-meta">
                                                    <span><?= htmlspecialchars($booking['class_name']) ?></span>
                                                    <span><i class="bi bi-clock"></i> <?= date('M d', strtotime($booking['created_at'])) ?></span>
                                                </div>
                                            </div>
                                            <div class="booking-status">
                                                <span class="status-badge status-<?= $booking['status'] ?>">
                                                    <?= ucfirst($booking['status']) ?>
                                                </span>
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
                    <!-- Class Capacity Stats -->
                    <div class="panel fade-in">
                        <div class="panel-header">
                            <h3>Class Capacity</h3>
                            <span class="text-muted" style="font-size: 14px;">Current Status</span>
                        </div>
                        <div class="panel-body">
                            <div class="capacity-stats">
                                <div class="capacity-stat">
                                    <h5><?= $capacity_stats['full_classes'] ?></h5>
                                    <p>Full Classes</p>
                                </div>
                                <div class="capacity-stat">
                                    <h5><?= $capacity_stats['partial_classes'] ?></h5>
                                    <p>Partial Classes</p>
                                </div>
                                <div class="capacity-stat">
                                    <h5><?= $capacity_stats['empty_classes'] ?></h5>
                                    <p>Empty Classes</p>
                                </div>
                                <div class="capacity-stat">
                                    <h5><?= number_format($capacity_stats['avg_utilization'], 1) ?>%</h5>
                                    <p>Avg Utilization</p>
                                </div>
                            </div>
                            
                            <?php if (!empty($class_distribution)): ?>
                                <div style="margin-top: 25px;">
                                    <h6 style="font-weight: 600; margin-bottom: 15px; color: var(--dark);">Class Distribution by Age Group</h6>
                                    <div style="display: flex; flex-direction: column; gap: 10px;">
                                        <?php foreach ($class_distribution as $group): ?>
                                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #eee;">
                                                <span><?= htmlspecialchars($group['age_group'] ?: 'Not Specified') ?></span>
                                                <span class="badge bg-primary"><?= $group['count'] ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Top Instructors -->
                    <div class="panel fade-in">
                        <div class="panel-header">
                            <h3>Top Instructors</h3>
                            <a href="instructors.php" class="btn-link">
                                View All <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                        <div class="panel-body">
                            <?php if (empty($top_instructors)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-person-x"></i>
                                    <p>No instructors data</p>
                                </div>
                            <?php else: ?>
                                <div class="instructor-grid">
                                    <?php foreach ($top_instructors as $instructor): ?>
                                        <div class="instructor-card">
                                            <div class="instructor-avatar">
                                                <?= strtoupper(substr($instructor['name'], 0, 1)) ?>
                                            </div>
                                            <div class="instructor-info">
                                                <h6><?= htmlspecialchars($instructor['name']) ?></h6>
                                                <?php if (!empty($instructor['specialization'])): ?>
                                                    <p><?= htmlspecialchars($instructor['specialization']) ?></p>
                                                <?php endif; ?>
                                                <div class="instructor-stats">
                                                    <div class="instructor-stat">
                                                        <span><?= $instructor['total_bookings'] ?></span>
                                                        <small>Bookings</small>
                                                    </div>
                                                    <div class="instructor-stat">
                                                        <span><?= $instructor['total_classes'] ?></span>
                                                        <small>Classes</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
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
                                    <p>No recent payments</p>
                                </div>
                            <?php else: ?>
                                <div class="payment-list">
                                    <?php foreach ($recent_payments as $payment): ?>
                                        <div class="payment-item">
                                            <div class="payment-info">
                                                <h6><?= htmlspecialchars($payment['student_name'] ?? 'Unknown') ?></h6>
                                                <div class="payment-meta">
                                                    <span>$<?= number_format($payment['amount'], 2) ?></span>
                                                    <span><i class="bi bi-clock"></i> <?= date('M d', strtotime($payment['payment_date'])) ?></span>
                                                </div>
                                            </div>
                                            <div class="payment-status">
                                                <span class="status-badge status-<?= $payment['status'] ?>">
                                                    <?= ucfirst($payment['status']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
            
            // Initialize Charts
            const chartColors = {
                primary: 'rgba(13, 110, 253, 0.8)',
                success: 'rgba(25, 135, 84, 0.8)',
                warning: 'rgba(255, 193, 7, 0.8)',
                danger: 'rgba(220, 53, 69, 0.8)',
                info: 'rgba(13, 202, 240, 0.8)',
                purple: 'rgba(111, 66, 193, 0.8)'
            };
            
            // Revenue Chart
            const revenueCtx = document.getElementById('revenueChart').getContext('2d');
            const revenueChart = new Chart(revenueCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($months) ?>,
                    datasets: [{
                        label: 'Revenue ($)',
                        data: <?= json_encode($revenue_data) ?>,
                        borderColor: chartColors.success,
                        backgroundColor: 'rgba(25, 135, 84, 0.1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: chartColors.success,
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return 'Revenue: $' + context.parsed.y.toFixed(2);
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                drawBorder: false
                            },
                            ticks: {
                                callback: function(value) {
                                    return '$' + value;
                                }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
            
            // Bookings Chart
            const bookingsCtx = document.getElementById('bookingsChart').getContext('2d');
            const bookingsChart = new Chart(bookingsCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($months) ?>,
                    datasets: [{
                        label: 'Bookings',
                        data: <?= json_encode($bookings_data) ?>,
                        backgroundColor: chartColors.primary,
                        borderColor: chartColors.primary,
                        borderWidth: 1,
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                drawBorder: false
                            },
                            ticks: {
                                precision: 0
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
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
                let text = stat.textContent;
                let isCurrency = text.includes('$');
                let target = parseFloat(text.replace('$', '').replace(',', '').replace('%', ''));
                
                if (!isNaN(target)) {
                    let current = 0;
                    const increment = target / 20;
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= target) {
                            current = target;
                            clearInterval(timer);
                        }
                        
                        if (text.includes('%')) {
                            stat.textContent = current.toFixed(1) + '%';
                        } else if (isCurrency) {
                            stat.textContent = '$' + current.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                        } else {
                            stat.textContent = Math.round(current).toLocaleString();
                        }
                    }, 50);
                }
            });
            
            // Refresh data every 60 seconds
            setInterval(() => {
                fetch(window.location.href)
                    .then(response => response.text())
                    .then(html => {
                        console.log('Dashboard data refreshed');
                    })
                    .catch(error => console.error('Refresh error:', error));
            }, 60000);
        });
    </script>
</body>
</html>