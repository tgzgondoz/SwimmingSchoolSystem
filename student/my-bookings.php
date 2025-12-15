<?php
// student/my-bookings.php - Student's Bookings Management
session_start();
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

requireRole('student');
$user = getCurrentUser($conn);
$student_id = $_SESSION['user_id'];

// Initialize messages
$success_message = $error_message = '';

// Handle booking cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    $booking_id = intval($_POST['booking_id']);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get class_id from booking
        $stmt = $conn->prepare("SELECT class_id FROM bookings WHERE id = ? AND user_id = ?");
        $stmt->bind_param('ii', $booking_id, $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $booking = $result->fetch_assoc();
            $class_id = $booking['class_id'];
            
            // Update booking status to cancelled
            $update_stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled', cancellation_date = NOW() WHERE id = ? AND user_id = ?");
            $update_stmt->bind_param('ii', $booking_id, $student_id);
            $update_stmt->execute();
            
            // Increment available slots
            $slots_stmt = $conn->prepare("UPDATE classes SET slots_available = slots_available + 1 WHERE id = ?");
            $slots_stmt->bind_param('i', $class_id);
            $slots_stmt->execute();
            
            $conn->commit();
            $success_message = "Booking cancelled successfully. Your slot has been freed.";
            
            // Refresh page
            header("Location: my-bookings.php?success=" . urlencode($success_message));
            exit();
        } else {
            $error_message = "Booking not found or you don't have permission to cancel it.";
        }
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Failed to cancel booking. Please try again.";
    }
}

// Display messages from URL parameters
if (isset($_GET['success'])) {
    $success_message = htmlspecialchars($_GET['success']);
}
if (isset($_GET['error'])) {
    $error_message = htmlspecialchars($_GET['error']);
}

// Filter bookings
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';

// Build query based on filters
$where_conditions = ["b.user_id = ?"];
$params = [$student_id];
$types = 'i';

if ($status_filter) {
    $where_conditions[] = "b.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($type_filter === 'upcoming') {
    $where_conditions[] = "c.start_time >= NOW()";
} elseif ($type_filter === 'past') {
    $where_conditions[] = "c.start_time < NOW()";
}

// Get bookings
$query = "
    SELECT b.*, c.*, i.name as instructor_name,
           CASE 
               WHEN c.start_time < NOW() THEN 'past'
               ELSE 'upcoming'
           END as time_status
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    LEFT JOIN instructors i ON c.instructor_id = i.id
    WHERE " . implode(' AND ', $where_conditions) . "
    ORDER BY c.start_time DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get booking statistics
$stats_query = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
        SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN c.start_time >= NOW() AND b.status = 'confirmed' THEN 1 ELSE 0 END) as upcoming
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    WHERE b.user_id = ?
";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bind_param('i', $student_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();

// Get CSS from classes.php for consistent styling
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings | AquaFlow Student Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0d6efd;
            --primary-dark: #0a58ca;
            --primary-light: #dbeafe;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #06b6d4;
            --light: #f8f9fa;
            --dark: #212529;
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
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e6f0ff 100%);
            min-height: 100vh;
            color: var(--gray-800);
            line-height: 1.6;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styling - Same as classes.php */
        .sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid var(--gray-200);
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .logo-area {
            padding: 25px 20px;
            border-bottom: 1px solid var(--gray-200);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .logo-text h3 {
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            font-size: 20px;
            margin: 0;
            color: var(--gray-900);
        }

        .logo-text span {
            font-size: 12px;
            color: var(--gray-500);
            font-weight: 500;
        }

        .nav-menu {
            padding: 20px 15px;
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
            color: var(--gray-600);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            background: var(--gray-100);
            color: var(--primary);
            transform: translateX(5px);
        }

        .nav-link.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.2);
        }

        .nav-link.active i {
            color: white;
        }

        .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 18px;
            color: var(--gray-500);
        }

        .nav-link.active:hover {
            transform: translateX(5px);
            background: linear-gradient(135deg, var(--primary-dark) 0%, #0a3d9c 100%);
        }

        .logout-section {
            padding: 20px;
            border-top: 1px solid var(--gray-200);
            margin-top: auto;
            position: absolute;
            bottom: 0;
            width: 100%;
        }

        /* Main Content Styling */
        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 30px;
            transition: all 0.3s ease;
        }

        /* Header Styling */
        .header {
            background: white;
            border-radius: 15px;
            padding: 20px 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-left h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--gray-900);
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .header-left p {
            color: var(--gray-600);
            margin: 0;
            font-size: 16px;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
            border: 3px solid white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .user-info h5 {
            font-weight: 600;
            margin: 0;
            color: var(--gray-900);
        }

        .user-info p {
            color: var(--gray-500);
            font-size: 13px;
            margin: 0;
        }

        /* Alert Messages */
        .alert-custom {
            border-radius: 12px;
            border: none;
            padding: 20px 25px;
            margin-bottom: 30px;
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

        .alert-custom i {
            font-size: 22px;
            margin-right: 12px;
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
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
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
            background: linear-gradient(135deg, var(--primary-light) 0%, #bfdbfe 100%);
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
            color: var(--gray-900);
            margin-bottom: 5px;
        }

        .stat-label {
            color: var(--gray-500);
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
            border: 2px solid var(--gray-200);
            color: var(--gray-600);
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
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }

        .booking-card:hover {
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .booking-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--gray-200);
        }

        .booking-title {
            font-family: 'Poppins', sans-serif;
            font-size: 20px;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
        }

        .booking-badge {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-confirmed {
            background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
            color: white;
        }

        .badge-pending {
            background: linear-gradient(135deg, var(--warning) 0%, #d97706 100%);
            color: white;
        }

        .badge-cancelled {
            background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%);
            color: white;
        }

        .badge-upcoming {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .badge-past {
            background: linear-gradient(135deg, var(--gray-400) 0%, var(--gray-500) 100%);
            color: white;
        }

        .booking-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .detail-box {
            padding: 15px;
            background: var(--gray-50);
            border-radius: 10px;
            border-left: 4px solid var(--primary);
        }

        .detail-label {
            font-size: 12px;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .detail-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--gray-800);
        }

        .booking-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-200);
        }

        .btn-cancel {
            background: linear-gradient(90deg, var(--danger), #dc2626);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-cancel:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(239, 68, 68, 0.3);
        }

        .btn-view {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(13, 110, 253, 0.3);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--gray-200);
        }

        .empty-state i {
            font-size: 72px;
            color: var(--gray-300);
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-family: 'Poppins', sans-serif;
            font-size: 24px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 12px;
        }

        .empty-state p {
            color: var(--gray-500);
            margin-bottom: 25px;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Instructor Info */
        .instructor-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .instructor-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 16px;
        }

        /* Responsive Design */
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
            
            .main-content {
                padding: 20px;
            }
            
            .filter-tabs {
                overflow-x: auto;
                padding-bottom: 10px;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .header-left h1 {
                font-size: 24px;
            }
            
            .booking-header {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
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
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="logo-area">
                <a href="index.php" class="logo">
                    <div class="logo-icon">
                        <i class="bi bi-droplet"></i>
                    </div>
                    <div class="logo-text">
                        <h3>AquaFlow</h3>
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
                        <i class="bi bi-calendar-week"></i>
                        <span class="nav-text">Classes</span>
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
                <a href="logout.php" class="nav-link">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="nav-text">Logout</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header fade-in">
                <div class="header-left">
                    <h1>My Bookings</h1>
                    <p>Manage your swimming class bookings and view booking history</p>
                </div>
                <div class="user-profile">
                    <div class="user-avatar">
                        <?= strtoupper(substr($user['name'], 0, 1)) ?>
                    </div>
                    <div class="user-info">
                        <h5><?= htmlspecialchars($user['name']) ?></h5>
                        <p>Student ID: <?= htmlspecialchars($user['student_id'] ?? 'N/A') ?></p>
                    </div>
                </div>
            </header>

            <!-- Alert Messages -->
            <?php if($success_message): ?>
                <div class="alert alert-success alert-custom alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i>
                    <?= $success_message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if($error_message): ?>
                <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-circle"></i>
                    <?= $error_message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Section -->
            <div class="stats-container fade-in">
                <div class="stat-card">
                    <div class="stat-icon primary">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div class="stat-value"><?= $stats['total'] ?? 0 ?></div>
                    <div class="stat-label">Total Bookings</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon success">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stat-value"><?= $stats['confirmed'] ?? 0 ?></div>
                    <div class="stat-label">Confirmed</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon warning">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div class="stat-value"><?= $stats['upcoming'] ?? 0 ?></div>
                    <div class="stat-label">Upcoming</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon danger">
                        <i class="bi bi-x-circle"></i>
                    </div>
                    <div class="stat-value"><?= $stats['cancelled'] ?? 0 ?></div>
                    <div class="stat-label">Cancelled</div>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="filter-tabs fade-in">
                <a href="my-bookings.php" class="filter-tab <?= !$type_filter && !$status_filter ? 'active' : '' ?>">All Bookings</a>
                <a href="my-bookings.php?type=upcoming" class="filter-tab <?= $type_filter === 'upcoming' ? 'active' : '' ?>">Upcoming</a>
                <a href="my-bookings.php?type=past" class="filter-tab <?= $type_filter === 'past' ? 'active' : '' ?>">Past</a>
                <a href="my-bookings.php?status=confirmed" class="filter-tab <?= $status_filter === 'confirmed' ? 'active' : '' ?>">Confirmed</a>
                <a href="my-bookings.php?status=pending" class="filter-tab <?= $status_filter === 'pending' ? 'active' : '' ?>">Pending</a>
                <a href="my-bookings.php?status=cancelled" class="filter-tab <?= $status_filter === 'cancelled' ? 'active' : '' ?>">Cancelled</a>
            </div>

            <!-- Bookings List -->
            <?php if(empty($bookings)): ?>
                <div class="empty-state fade-in">
                    <i class="bi bi-calendar-x"></i>
                    <h3>No Bookings Found</h3>
                    <p>
                        <?php if($status_filter || $type_filter): ?>
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
                    <?php foreach($bookings as $booking): 
                        $start_time = strtotime($booking['start_time']);
                        $end_time = strtotime($booking['end_time']);
                        $duration_minutes = ($end_time - $start_time) / 60;
                        $is_upcoming = $start_time > time();
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
                                            <small class="text-muted">Certified Instructor</small>
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
                                        <small><?= date('g:i A', $start_time) ?> - <?= date('g:i A', $end_time) ?></small>
                                    </div>
                                </div>
                                
                                <div class="detail-box">
                                    <div class="detail-label">Age Group</div>
                                    <div class="detail-value"><?= htmlspecialchars($booking['age_group']) ?></div>
                                </div>
                                
                                <div class="detail-box">
                                    <div class="detail-label">Price</div>
                                    <div class="detail-value">$<?= number_format($booking['price'] ?? 0, 2) ?></div>
                                </div>
                                
                                <div class="detail-box">
                                    <div class="detail-label">Booking Date</div>
                                    <div class="detail-value"><?= date('F j, Y', strtotime($booking['booking_date'])) ?></div>
                                </div>
                            </div>
                            
                            <?php if($booking['description']): ?>
                                <div class="mb-3">
                                    <div class="detail-label mb-2">Description</div>
                                    <p class="text-muted" style="font-size: 14px;">
                                        <?= htmlspecialchars(substr($booking['description'], 0, 200)) ?>
                                        <?= strlen($booking['description']) > 200 ? '...' : '' ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                            
                            <div class="booking-actions">
                                <?php if($is_upcoming && $booking['status'] === 'confirmed'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                        <button type="submit" name="cancel_booking" class="btn-cancel">
                                            <i class="bi bi-x-circle"></i>
                                            Cancel Booking
                                        </button>
                                    </form>
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
        document.addEventListener('DOMContentLoaded', function() {
            // Add animation to booking cards
            const cards = document.querySelectorAll('.booking-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('fade-in');
            });
            
            // Confirmation for cancellation
            const cancelForms = document.querySelectorAll('form[method="POST"]');
            cancelForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    if (!confirm('Are you sure you want to cancel this booking? This action cannot be undone.')) {
                        e.preventDefault();
                        return;
                    }
                    
                    const button = this.querySelector('button[type="submit"]');
                    
                    // Show loading state
                    const originalText = button.innerHTML;
                    button.innerHTML = '<i class="bi bi-hourglass-split"></i> Cancelling...';
                    button.disabled = true;
                    
                    // Revert after 3 seconds if form doesn't submit
                    setTimeout(() => {
                        if (!button.disabled) {
                            button.innerHTML = originalText;
                        }
                    }, 3000);
                });
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
    </script>
</body>
</html>