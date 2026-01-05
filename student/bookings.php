<?php
// student/bookings.php - Manage class bookings
session_start();
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

requireRole('student');
$user = getCurrentUser($conn);
$student_id = $_SESSION['user_id'];

$success_message = '';
$error_message = '';

// Handle new booking request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_booking'])) {
    $class_id = intval($_POST['class_id']);
    $child_name = trim($_POST['child_name'] ?? '');
    $child_age = $_POST['child_age'] !== '' ? intval($_POST['child_age']) : null;
    $child_gender = $_POST['child_gender'] ?? null;
    $special_notes = trim($_POST['special_notes'] ?? '');
    
    // Check if class exists and has available slots
    $check_stmt = $conn->prepare("SELECT title, start_time, slots_available, max_capacity, price FROM classes WHERE id = ? AND start_time >= NOW() AND status = 'scheduled'");
    $check_stmt->bind_param('i', $class_id);
    $check_stmt->execute();
    $class_result = $check_stmt->get_result();
    
    if ($class_result->num_rows > 0) {
        $class = $class_result->fetch_assoc();
        
        // Check if slots are available
        if ($class['slots_available'] > 0) {
            // Check if student already has a booking for this class
            $existing_stmt = $conn->prepare("SELECT id FROM bookings WHERE user_id = ? AND class_id = ? AND status IN ('pending', 'confirmed')");
            $existing_stmt->bind_param('ii', $student_id, $class_id);
            $existing_stmt->execute();
            
            if ($existing_stmt->get_result()->num_rows == 0) {
                // Create booking with 'pending' status
                $stmt = $conn->prepare("INSERT INTO bookings (user_id, class_id, child_name, child_age, child_gender, special_notes, status, booking_date, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())");
                $stmt->bind_param('iisisss', $student_id, $class_id, $child_name, $child_age, $child_gender, $special_notes);
                
                if ($stmt->execute()) {
                    $success_message = "Booking request submitted successfully! Please wait for admin approval.";
                    header("Location: bookings.php?success=" . urlencode($success_message));
                    exit();
                } else {
                    $error_message = "Failed to submit booking request. Please try again.";
                }
                $stmt->close();
            } else {
                $error_message = "You already have a booking for this class.";
            }
            $existing_stmt->close();
        } else {
            $error_message = "No available slots for this class.";
        }
    } else {
        $error_message = "Class not found or not available for booking.";
    }
    $check_stmt->close();
}

// Handle booking cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    $booking_id = intval($_POST['booking_id']);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get class_id and status from booking
        $stmt = $conn->prepare("SELECT class_id, status FROM bookings WHERE id = ? AND user_id = ?");
        $stmt->bind_param('ii', $booking_id, $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $booking = $result->fetch_assoc();
            $class_id = $booking['class_id'];
            $current_status = $booking['status'];
            
            // Update booking status to cancelled
            $update_stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled', cancellation_date = NOW() WHERE id = ? AND user_id = ?");
            $update_stmt->bind_param('ii', $booking_id, $student_id);
            $update_stmt->execute();
            
            // If booking was confirmed, increment available slots
            if ($current_status === 'confirmed') {
                $slots_stmt = $conn->prepare("UPDATE classes SET slots_available = slots_available + 1 WHERE id = ?");
                $slots_stmt->bind_param('i', $class_id);
                $slots_stmt->execute();
                $slots_stmt->close();
            }
            
            $conn->commit();
            $success_message = "Booking cancelled successfully.";
            
            // Refresh page
            header("Location: bookings.php?success=" . urlencode($success_message));
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

// Get all bookings
$all_bookings = $conn->query("
    SELECT b.*, c.title, c.start_time, c.end_time, c.age_group, c.price, 
           i.name as instructor_name, c.slots_available, c.max_capacity
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    LEFT JOIN instructors i ON c.instructor_id = i.id
    WHERE b.user_id = $student_id
    ORDER BY 
        CASE b.status
            WHEN 'pending' THEN 1
            WHEN 'confirmed' THEN 2
            WHEN 'cancelled' THEN 3
            ELSE 4
        END,
        c.start_time ASC
")->fetch_all(MYSQLI_ASSOC);

// Count bookings by status
$pending_count = 0;
$confirmed_count = 0;
$cancelled_count = 0;
$upcoming_count = 0;

foreach ($all_bookings as $booking) {
    switch ($booking['status']) {
        case 'pending': $pending_count++; break;
        case 'confirmed': 
            $confirmed_count++;
            if (strtotime($booking['start_time']) > time()) {
                $upcoming_count++;
            }
            break;
        case 'cancelled': $cancelled_count++; break;
    }
}

// Get available classes for booking
$available_classes = $conn->query("
    SELECT c.*, i.name as instructor_name
    FROM classes c
    LEFT JOIN instructors i ON c.instructor_id = i.id
    WHERE c.start_time >= NOW() 
    AND c.status = 'scheduled'
    AND c.slots_available > 0
    AND c.id NOT IN (
        SELECT class_id FROM bookings 
        WHERE user_id = $student_id 
        AND status IN ('pending', 'confirmed')
    )
    ORDER BY c.start_time ASC
")->fetch_all(MYSQLI_ASSOC);
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

        /* Sidebar Styling */
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

        .stat-icon.warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: var(--warning);
        }

        .stat-icon.success {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: var(--success);
        }

        .stat-icon.secondary {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: var(--gray-600);
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

        /* Action Button */
        .action-button {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 14px 28px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            box-shadow: 0 4px 15px rgba(13, 110, 253, 0.2);
        }

        .action-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(13, 110, 253, 0.3);
            color: white;
        }

        /* Status Badges */
        .booking-badge {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: white;
        }

        .badge-pending {
            background: linear-gradient(135deg, var(--warning) 0%, #d97706 100%);
        }

        .badge-confirmed {
            background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
        }

        .badge-cancelled {
            background: linear-gradient(135deg, var(--gray-400) 0%, var(--gray-500) 100%);
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
        }

        .booking-title {
            font-family: 'Poppins', sans-serif;
            font-size: 20px;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
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

        /* Modal Styling */
        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 20px 30px;
        }

        .modal-title {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
        }

        .btn-close-white {
            filter: invert(1) grayscale(100%) brightness(200%);
        }

        /* Booking Action Card */
        .booking-action-card {
            background: linear-gradient(135deg, #f8fafc 0%, #e6f0ff 100%);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            border: 2px solid var(--primary);
            box-shadow: 0 4px 20px rgba(13, 110, 253, 0.1);
        }

        /* Floating Action Button */
        .floating-action-button {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
            box-shadow: 0 6px 20px rgba(13, 110, 253, 0.4);
            z-index: 1000;
            cursor: pointer;
            border: none;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .floating-action-button:hover {
            transform: scale(1.1);
            box-shadow: 0 10px 30px rgba(13, 110, 253, 0.6);
            color: white;
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
            }
            
            .booking-actions {
                flex-direction: column;
            }
            
            .btn-cancel {
                width: 100%;
            }
            
            .booking-details {
                grid-template-columns: 1fr;
            }
            
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .floating-action-button {
                bottom: 20px;
                right: 20px;
                width: 60px;
                height: 60px;
                font-size: 24px;
            }
        }

        @media (max-width: 576px) {
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .floating-action-button {
                bottom: 15px;
                right: 15px;
                width: 55px;
                height: 55px;
                font-size: 22px;
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
                    <a href="bookings.php" class="nav-link active">
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
                    <div class="stat-icon warning">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div class="stat-value"><?= $pending_count ?></div>
                    <div class="stat-label">Pending Approval</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon success">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stat-value"><?= $confirmed_count ?></div>
                    <div class="stat-label">Confirmed</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon primary">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div class="stat-value"><?= $upcoming_count ?></div>
                    <div class="stat-label">Upcoming</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon secondary">
                        <i class="bi bi-x-circle"></i>
                    </div>
                    <div class="stat-value"><?= $cancelled_count ?></div>
                    <div class="stat-label">Cancelled</div>
                </div>
            </div>

            <!-- Booking Action Card -->
            <div class="booking-action-card fade-in">
                <div class="text-center">
                    <h3 class="mb-3" style="color: var(--primary);">
                        <i class="bi bi-calendar-check me-2"></i> Ready to Book a Class?
                    </h3>
                    <p class="mb-4 text-muted">
                        Find the perfect class time and secure your spot today
                    </p>
                    
                    <div class="row justify-content-center">
                        <div class="col-md-8 col-lg-6">
                            <div class="d-grid gap-3">
                                <!-- Primary Booking Button -->
                                <button type="button" class="btn btn-primary btn-lg py-3" 
                                        data-bs-toggle="modal" data-bs-target="#bookClassModal"
                                        style="font-size: 18px; font-weight: 600;">
                                    <i class="bi bi-calendar-plus me-2"></i> Book New Swimming Class
                                </button>
                                
                                <!-- Browse Classes Link -->
                                <a href="classes.php" class="btn btn-outline-primary btn-lg py-3">
                                    <i class="bi bi-search me-2"></i> Browse All Available Classes
                                </a>
                            </div>
                            
                            <!-- Available Classes Count -->
                            <div class="mt-4">
                                <?php if(!empty($available_classes)): ?>
                                    <div class="alert alert-success d-inline-flex align-items-center">
                                        <i class="bi bi-check-circle-fill me-2"></i>
                                        <span>
                                            <strong><?= count($available_classes) ?></strong> 
                                            <?= count($available_classes) == 1 ? 'class is' : 'classes are' ?> available for booking
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning d-inline-flex align-items-center">
                                        <i class="bi bi-info-circle-fill me-2"></i>
                                        <span>No classes available at the moment. Please check back later.</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bookings Section -->
            <div class="fade-in">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 style="font-family: 'Poppins', sans-serif; font-weight: 600;">My Bookings</h2>
                    <span class="badge bg-primary">
                        Total: <?= count($all_bookings) ?>
                    </span>
                </div>
                
                <?php if(empty($all_bookings)): ?>
                    <div class="empty-state">
                        <i class="bi bi-calendar-x"></i>
                        <h3>No Bookings Yet</h3>
                        <p>You haven't booked any swimming classes yet. Click the button above to book your first class.</p>
                        <div class="d-flex justify-content-center gap-3">
                            <button type="button" class="action-button" data-bs-toggle="modal" data-bs-target="#bookClassModal">
                                <i class="bi bi-calendar-plus"></i> Book Your First Class
                            </button>
                            <a href="classes.php" class="btn btn-outline-primary btn-lg">
                                <i class="bi bi-calendar-week"></i> Browse Classes
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach($all_bookings as $booking): 
                        $start_time = strtotime($booking['start_time']);
                        $end_time = strtotime($booking['end_time']);
                        $is_upcoming = $start_time > time();
                        $status = $booking['status'];
                    ?>
                        <div class="booking-card">
                            <div class="booking-header">
                                <h3 class="booking-title"><?= htmlspecialchars($booking['title']) ?></h3>
                                <span class="booking-badge badge-<?= $status ?>">
                                    <?= ucfirst($status) ?>
                                </span>
                            </div>
                            
                            <div class="booking-details">
                                <div class="detail-box">
                                    <div class="detail-label">Date & Time</div>
                                    <div class="detail-value">
                                        <?= date('F j, Y', $start_time) ?><br>
                                        <?= date('g:i A', $start_time) ?> - <?= date('g:i A', $end_time) ?>
                                    </div>
                                </div>
                                
                                <div class="detail-box">
                                    <div class="detail-label">Instructor</div>
                                    <div class="detail-value"><?= htmlspecialchars($booking['instructor_name'] ?? 'TBA') ?></div>
                                </div>
                                
                                <div class="detail-box">
                                    <div class="detail-label">Status</div>
                                    <div class="detail-value">
                                        <?php if($status == 'pending'): ?>
                                            <span class="text-warning"><i class="bi bi-clock me-1"></i> Awaiting Approval</span>
                                        <?php elseif($status == 'confirmed'): ?>
                                            <span class="text-success"><i class="bi bi-check-circle me-1"></i> Confirmed</span>
                                        <?php else: ?>
                                            <span class="text-danger"><i class="bi bi-x-circle me-1"></i> Cancelled</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <?php if($booking['child_name']): ?>
                                    <div class="detail-box">
                                        <div class="detail-label">Child</div>
                                        <div class="detail-value">
                                            <?= htmlspecialchars($booking['child_name']) ?> 
                                            (Age: <?= $booking['child_age'] ?>, <?= ucfirst($booking['child_gender']) ?>)
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="detail-box">
                                    <div class="detail-label">Price</div>
                                    <div class="detail-value">$<?= number_format($booking['price'], 2) ?></div>
                                </div>
                                
                                <div class="detail-box">
                                    <div class="detail-label">Booking Date</div>
                                    <div class="detail-value"><?= date('F j, Y', strtotime($booking['booking_date'])) ?></div>
                                </div>
                            </div>
                            
                            <?php if($status == 'pending'): ?>
                                <div class="booking-actions">
                                    <form method="POST">
                                        <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                        <button type="submit" name="cancel_booking" class="btn-cancel" onclick="return confirm('Are you sure you want to cancel this booking request?')">
                                            <i class="bi bi-x-circle"></i>
                                            Cancel Request
                                        </button>
                                    </form>
                                </div>
                            <?php elseif($status == 'confirmed' && $is_upcoming): ?>
                                <div class="booking-actions">
                                    <form method="POST">
                                        <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                        <button type="submit" name="cancel_booking" class="btn-cancel" onclick="return confirm('Are you sure you want to cancel this confirmed booking?')">
                                            <i class="bi bi-x-circle"></i>
                                            Cancel Booking
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Floating Action Button -->
    <a href="#" class="floating-action-button" data-bs-toggle="modal" data-bs-target="#bookClassModal">
        <i class="bi bi-plus-lg"></i>
    </a>

    <!-- Book Class Modal -->
    <div class="modal fade" id="bookClassModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Book Swimming Class</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label required">Select Class</label>
                                <select name="class_id" class="form-select" required>
                                    <option value="">Choose a class...</option>
                                    <?php foreach($available_classes as $class): 
                                        $start_time = strtotime($class['start_time']);
                                        $end_time = strtotime($class['end_time']);
                                    ?>
                                        <option value="<?= $class['id'] ?>">
                                            <?= htmlspecialchars($class['title']) ?> - 
                                            <?= date('M j, Y', $start_time) ?> 
                                            <?= date('g:i A', $start_time) ?>-<?= date('g:i A', $end_time) ?>
                                            (<?= $class['slots_available'] ?> slots available)
                                            <?php if($class['instructor_name']): ?>
                                                - Instructor: <?= htmlspecialchars($class['instructor_name']) ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if(empty($available_classes)): ?>
                                    <div class="alert alert-warning mt-2">
                                        <i class="bi bi-exclamation-triangle me-2"></i>
                                        No classes available at the moment. Please check back later or contact the administrator.
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="forChild" onclick="toggleChildFields()">
                                    <label class="form-check-label" for="forChild">
                                        <i class="bi bi-person-plus me-1"></i> This booking is for a child
                                    </label>
                                </div>
                            </div>
                            
                            <div class="col-md-6 child-fields" style="display: none;">
                                <label class="form-label">Child's Name</label>
                                <input type="text" name="child_name" class="form-control" placeholder="Enter child's name">
                            </div>
                            
                            <div class="col-md-3 child-fields" style="display: none;">
                                <label class="form-label">Child's Age</label>
                                <input type="number" name="child_age" class="form-control" min="1" max="18" placeholder="Age">
                            </div>
                            
                            <div class="col-md-3 child-fields" style="display: none;">
                                <label class="form-label">Child's Gender</label>
                                <select name="child_gender" class="form-select">
                                    <option value="">Select</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Special Notes (Optional)</label>
                                <textarea name="special_notes" class="form-control" rows="3" placeholder="Any special requirements, medical conditions, or notes..."></textarea>
                            </div>
                            
                            <div class="col-12">
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <strong>Important:</strong> Your booking will be submitted for admin approval. 
                                    You'll receive a confirmation once approved. Cancellation may be subject to fees.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="request_booking" class="btn btn-primary" <?= empty($available_classes) ? 'disabled' : '' ?>>
                            <i class="bi bi-send me-2"></i> Submit Booking Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log("DEBUG: Student Bookings Page Loaded");
            
            // Toggle child fields
            function toggleChildFields() {
                const childFields = document.querySelectorAll('.child-fields');
                const forChild = document.getElementById('forChild').checked;
                
                childFields.forEach(field => {
                    field.style.display = forChild ? 'block' : 'none';
                });
            }
            
            // Attach event to checkbox
            const forChildCheckbox = document.getElementById('forChild');
            if (forChildCheckbox) {
                forChildCheckbox.addEventListener('change', toggleChildFields);
                console.log("DEBUG: Child checkbox found and event attached");
            } else {
                console.warn("WARNING: Child checkbox not found");
            }
            
            // Check if booking modal button works
            const bookButtons = document.querySelectorAll('[data-bs-target="#bookClassModal"]');
            console.log("DEBUG: Found", bookButtons.length, "book buttons");
            
            bookButtons.forEach((button, index) => {
                button.addEventListener('click', function() {
                    console.log("DEBUG: Book button #" + (index + 1) + " clicked");
                    
                    // Check if modal exists
                    const modal = document.getElementById('bookClassModal');
                    if (!modal) {
                        console.error("ERROR: Book modal not found!");
                        alert("Error: Booking system not available. Please contact administrator.");
                        return false;
                    }
                    
                    // Check if there are available classes
                    const classSelect = modal.querySelector('select[name="class_id"]');
                    if (classSelect && classSelect.options.length <= 1) {
                        alert("No classes available for booking at the moment. Please check back later.");
                        return false;
                    }
                });
            });
            
            // Check if modal exists
            const modal = document.getElementById('bookClassModal');
            if (modal) {
                console.log("DEBUG: Book modal found");
                
                // Check for available classes in modal
                modal.addEventListener('show.bs.modal', function() {
                    const classSelect = this.querySelector('select[name="class_id"]');
                    const submitBtn = this.querySelector('button[name="request_booking"]');
                    
                    if (classSelect && classSelect.options.length <= 1) {
                        console.warn("WARNING: No available classes in modal");
                        if (submitBtn) {
                            submitBtn.disabled = true;
                            submitBtn.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i> No Classes Available';
                        }
                    } else {
                        console.log("DEBUG: Available classes found:", classSelect.options.length - 1);
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = '<i class="bi bi-send me-2"></i> Submit Booking Request';
                        }
                    }
                });
            } else {
                console.error("ERROR: Book modal not found in DOM");
            }
            
            // Add animation to booking cards
            const cards = document.querySelectorAll('.booking-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('fade-in');
            });
            
            // Confirmation for cancellation
            const cancelForms = document.querySelectorAll('form[method="POST"]');
            cancelForms.forEach(form => {
                const cancelBtn = form.querySelector('button[name="cancel_booking"]');
                if (cancelBtn) {
                    cancelBtn.addEventListener('click', function(e) {
                        if (!confirm('Are you sure you want to cancel this booking?')) {
                            e.preventDefault();
                            return false;
                        }
                        
                        // Show loading state
                        const originalText = this.innerHTML;
                        this.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
                        this.disabled = true;
                        
                        // Revert after 3 seconds if form doesn't submit
                        setTimeout(() => {
                            if (this.disabled) {
                                this.innerHTML = originalText;
                                this.disabled = false;
                            }
                        }, 3000);
                    });
                }
            });
            
            // Add count-up animation to stats
            const statValues = document.querySelectorAll('.stat-value');
            statValues.forEach(stat => {
                const target = parseInt(stat.textContent);
                if (!isNaN(target) && target > 0) {
                    let current = 0;
                    const increment = Math.ceil(target / 30);
                    
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= target) {
                            current = target;
                            clearInterval(timer);
                        }
                        stat.textContent = current;
                    }, 50);
                }
            });
            
            // Auto-close alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
            
            // Emergency fallback for modal if Bootstrap fails
            if (typeof bootstrap === 'undefined') {
                console.error("ERROR: Bootstrap not loaded!");
                const emergencyDiv = document.createElement('div');
                emergencyDiv.className = 'alert alert-danger text-center';
                emergencyDiv.innerHTML = `
                    <h4><i class="bi bi-exclamation-triangle me-2"></i> System Error</h4>
                    <p>Booking system requires Bootstrap. Please contact administrator.</p>
                `;
                document.querySelector('.main-content').prepend(emergencyDiv);
            }
        });
    </script>
</body>
</html>