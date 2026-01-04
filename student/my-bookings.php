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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_booking') {
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
            $update_stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ? AND user_id = ?");
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
    'type' => $_GET['type'] ?? 'all'
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
}

// Prepare query to get bookings
$query = "SELECT 
            b.*,
            c.*,
            i.name as instructor_name,
            i.specialization,
            CASE 
                WHEN c.start_time >= NOW() THEN 'upcoming'
                ELSE 'past'
            END as time_status
          FROM bookings b
          JOIN classes c ON b.class_id = c.id
          LEFT JOIN instructors i ON c.instructor_id = i.id
          WHERE " . implode(" AND ", $where_conditions) . "
          ORDER BY c.start_time DESC";

$bookings = [];
$stmt = $conn->prepare($query);

if ($stmt) {
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $bookings = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    error_log("Database query error: " . $conn->error);
    $error_msg = "Unable to load bookings. Please try again later.";
}

// Get booking statistics
$stats = [
    'total' => 0,
    'confirmed' => 0,
    'pending' => 0,
    'cancelled' => 0,
    'upcoming' => 0
];

$stats_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
    SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
    SUM(CASE WHEN c.start_time >= NOW() AND b.status IN ('confirmed', 'pending') THEN 1 ELSE 0 END) as upcoming
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
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
        
        /* Stats Cards */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 15px;
        }
        
        .stat-icon.primary {
            background: linear-gradient(135deg, var(--blue-light) 0%, #bfdbfe 100%);
            color: var(--primary);
        }
        
        .stat-icon.success {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: var(--success);
        }
        
        .stat-icon.warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: var(--warning);
        }
        
        .stat-icon.danger {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: var(--danger);
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 14px;
            font-weight: 500;
        }
        
        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        
        .filter-tab {
            padding: 12px 20px;
            border-radius: 10px;
            background: white;
            border: 2px solid #e9ecef;
            color: #495057;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .filter-tab:hover, .filter-tab.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.2);
        }
        
        /* Booking Cards */
        .booking-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .booking-card:hover {
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .booking-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .booking-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--dark);
            margin: 0;
        }
        
        .booking-badge {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: white;
        }
        
        .badge-confirmed {
            background: linear-gradient(135deg, var(--success) 0%, #157347 100%);
        }
        
        .badge-pending {
            background: linear-gradient(135deg, var(--warning) 0%, #ffca2c 100%);
        }
        
        .badge-cancelled {
            background: linear-gradient(135deg, var(--danger) 0%, #dc3545 100%);
        }
        
        .badge-upcoming {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        }
        
        .badge-past {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        }
        
        .instructor-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }
        
        .instructor-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--warning) 0%, #ffca2c 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 16px;
        }
        
        .booking-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .detail-box {
            padding: 15px;
            background: var(--light);
            border-radius: 10px;
            border-left: 4px solid var(--primary);
        }
        
        .detail-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .detail-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .booking-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }
        
        .btn-cancel {
            background: linear-gradient(135deg, var(--danger) 0%, #dc3545 100%);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-cancel:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(220, 53, 69, 0.3);
        }
        
        .btn-cancel:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
        
        .btn-view {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(13, 110, 253, 0.3);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
        }
        
        .empty-state-icon {
            font-size: 80px;
            color: #dee2e6;
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 10px;
            color: #495057;
        }
        
        .empty-state p {
            color: #6c757d;
            max-width: 400px;
            margin: 0 auto 25px;
        }
        
        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        
        .loading-spinner {
            background: white;
            padding: 40px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .booking-details {
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
            
            .filter-tabs {
                overflow-x: auto;
                padding-bottom: 10px;
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
            
            .booking-header {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
            
            .booking-details {
                grid-template-columns: 1fr;
            }
            
            .booking-actions {
                flex-direction: column;
            }
            
            .btn-view, .btn-cancel {
                width: 100%;
                justify-content: center;
            }
            
            .filter-tabs {
                flex-direction: column;
            }
        }
        
        /* Animations */
        .fade-in {
            animation: fadeIn 0.5s ease forwards;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p class="mt-3">Processing...</p>
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
                        <h5><?= htmlspecialchars($user['name'] ?? 'User') ?></h5>
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
            
            <!-- Stats Section -->
            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-icon primary">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div class="stat-value"><?= $stats['total'] ?></div>
                    <div class="stat-label">Total Bookings</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon success">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stat-value"><?= $stats['confirmed'] ?></div>
                    <div class="stat-label">Confirmed</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon warning">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div class="stat-value"><?= $stats['upcoming'] ?></div>
                    <div class="stat-label">Upcoming</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon danger">
                        <i class="bi bi-x-circle"></i>
                    </div>
                    <div class="stat-value"><?= $stats['cancelled'] ?></div>
                    <div class="stat-label">Cancelled</div>
                </div>
            </div>
            
            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <a href="my-bookings.php" class="filter-tab <?= empty($filters['type']) && empty($filters['status']) ? 'active' : '' ?>">All Bookings</a>
                <a href="my-bookings.php?type=upcoming" class="filter-tab <?= $filters['type'] === 'upcoming' ? 'active' : '' ?>">Upcoming</a>
                <a href="my-bookings.php?type=past" class="filter-tab <?= $filters['type'] === 'past' ? 'active' : '' ?>">Past</a>
                <a href="my-bookings.php?status=confirmed" class="filter-tab <?= $filters['status'] === 'confirmed' ? 'active' : '' ?>">Confirmed</a>
                <a href="my-bookings.php?status=pending" class="filter-tab <?= $filters['status'] === 'pending' ? 'active' : '' ?>">Pending</a>
                <a href="my-bookings.php?status=cancelled" class="filter-tab <?= $filters['status'] === 'cancelled' ? 'active' : '' ?>">Cancelled</a>
            </div>
            
            <!-- Bookings List -->
            <?php if (empty($bookings)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-calendar-x"></i>
                    </div>
                    <h3>No Bookings Found</h3>
                    <p>
                        <?php if ($filters['type'] || $filters['status']): ?>
                            No bookings match your current filters.
                        <?php else: ?>
                            You haven't booked any classes yet.
                        <?php endif; ?>
                    </p>
                    <a href="classes.php" class="btn-view">
                        <i class="bi bi-search me-2"></i>Browse Classes
                    </a>
                </div>
            <?php else: ?>
                <div class="fade-in">
                    <?php foreach ($bookings as $booking): 
                        $start_time = strtotime($booking['start_time']);
                        $end_time = strtotime($booking['end_time']);
                        $duration = ($end_time - $start_time) / 60;
                        $is_upcoming = $start_time > time();
                        $has_end_time = !empty($booking['end_time']) && $booking['end_time'] != '0000-00-00 00:00:00';
                    ?>
                        <div class="booking-card">
                            <div class="booking-header">
                                <div>
                                    <h3 class="booking-title"><?= htmlspecialchars($booking['title']) ?></h3>
                                    <div class="instructor-info">
                                        <div class="instructor-avatar">
                                            <?= strtoupper(substr($booking['instructor_name'] ?? 'I', 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-medium"><?= htmlspecialchars($booking['instructor_name'] ?? 'TBA') ?></div>
                                            <?php if (!empty($booking['specialization'])): ?>
                                                <small class="text-muted"><?= htmlspecialchars($booking['specialization']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <span class="booking-badge badge-<?= $booking['status'] ?>">
                                        <?= ucfirst($booking['status']) ?>
                                    </span>
                                    <br>
                                    <span class="booking-badge mt-2 <?= $is_upcoming ? 'badge-upcoming' : 'badge-past' ?>">
                                        <?= $is_upcoming ? 'Upcoming' : 'Past' ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="booking-details">
                                <div class="detail-box">
                                    <div class="detail-label">Date & Time</div>
                                    <div class="detail-value">
                                        <?= date('F j, Y', $start_time) ?><br>
                                        <small>
                                            <?= date('g:i A', $start_time) ?>
                                            <?php if ($has_end_time): ?>
                                                - <?= date('g:i A', $end_time) ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                                
                                <div class="detail-box">
                                    <div class="detail-label">Age Group</div>
                                    <div class="detail-value"><?= htmlspecialchars($booking['age_group']) ?></div>
                                </div>
                                
                                <div class="detail-box">
                                    <div class="detail-label">Price</div>
                                    <div class="detail-value">$<?= number_format($booking['price'], 2) ?></div>
                                </div>
                                
                                <div class="detail-box">
                                    <div class="detail-label">Booking ID</div>
                                    <div class="detail-value">#<?= $booking['id'] ?></div>
                                </div>
                            </div>
                            
                            <?php if (!empty($booking['description'])): ?>
                                <div class="mb-3">
                                    <div class="detail-label mb-2">Description</div>
                                    <p class="text-muted" style="font-size: 14px;">
                                        <?= htmlspecialchars(substr($booking['description'], 0, 200)) ?>
                                        <?= strlen($booking['description']) > 200 ? '...' : '' ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                            
                            <div class="booking-actions">
                                <?php if ($is_upcoming && in_array($booking['status'], ['confirmed', 'pending'])): ?>
                                    <form method="POST" class="cancel-form" onsubmit="return confirmCancellation(this)">
                                        <input type="hidden" name="action" value="cancel_booking">
                                        <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                        <button type="submit" class="btn-cancel">
                                            <i class="bi bi-x-circle"></i>
                                            Cancel Booking
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <?php if ($booking['status'] === 'pending'): ?>
                                    <button class="btn-view" onclick="alert('Payment feature coming soon!')">
                                        <i class="bi bi-credit-card"></i>
                                        Pay Now
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show loading overlay
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }
        
        // Hide loading overlay
        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }
        
        // Confirm booking cancellation
        function confirmCancellation(form) {
            if (!confirm('Are you sure you want to cancel this booking?\n\nThis action cannot be undone and will free up your slot.')) {
                return false;
            }
            
            showLoading();
            
            // Submit form via AJAX
            fetch('', {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.text())
            .then(html => {
                hideLoading();
                
                // Check if response contains success
                if (html.includes('alert-success') || html.includes('Booking cancelled')) {
                    // Reload page to show updated status
                    window.location.reload();
                } else {
                    // Parse HTML to extract error message
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const errorAlert = doc.querySelector('.alert-danger');
                    
                    if (errorAlert) {
                        alert('Cancellation failed: ' + errorAlert.textContent.trim());
                    } else {
                        alert('Cancellation failed. Please try again.');
                    }
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                alert('Network error. Please check your connection and try again.');
            });
            
            return false; // Prevent default form submission
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Add fade-in animation to cards
            const cards = document.querySelectorAll('.booking-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('fade-in');
            });
            
            // Add count-up animation to stats
            const statValues = document.querySelectorAll('.stat-value');
            statValues.forEach(stat => {
                const target = parseInt(stat.textContent);
                let current = 0;
                const increment = Math.ceil(target / 20);
                
                const timer = setInterval(() => {
                    current += increment;
                    if (current >= target) {
                        current = target;
                        clearInterval(timer);
                    }
                    stat.textContent = current;
                }, 50);
            });
            
            // Add active state to filter tabs
            const filterTabs = document.querySelectorAll('.filter-tab');
            filterTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    filterTabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                });
            });
        });
        
        // Auto-hide alerts after 5 seconds
        document.querySelectorAll('.alert').forEach(alert => {
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });
    </script>
</body>
</html>