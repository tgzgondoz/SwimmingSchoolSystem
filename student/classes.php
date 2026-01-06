<?php
// student/classes.php - Complete Class Booking System
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

// Handle class booking with robust error handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_class') {
    $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
    
    if (!$class_id || $class_id <= 0) {
        $error_msg = "Invalid class selection.";
    } else {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // 1. Check if class exists and has available slots (with row lock)
            $stmt = $conn->prepare("SELECT id, title, slots_available, slots_total FROM classes WHERE id = ? AND start_time > NOW() AND status = 'scheduled' FOR UPDATE");
            $stmt->bind_param("i", $class_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception("Class not found, has already started, or is not scheduled.");
            }
            
            $class = $result->fetch_assoc();
            
            // 2. Check if slots are available
            if ($class['slots_available'] <= 0) {
                throw new Exception("This class is fully booked.");
            }
            
            // 3. Check if student already booked this class
            $check_stmt = $conn->prepare("SELECT id FROM bookings WHERE user_id = ? AND class_id = ? AND status != 'cancelled'");
            $check_stmt->bind_param("ii", $student_id, $class_id);
            $check_stmt->execute();
            
            if ($check_stmt->get_result()->num_rows > 0) {
                throw new Exception("You have already booked this class.");
            }
            
            // 4. Create booking record
            $insert_stmt = $conn->prepare("INSERT INTO bookings (user_id, class_id, status, created_at) VALUES (?, ?, 'pending', NOW())");
            $insert_stmt->bind_param("ii", $student_id, $class_id);
            
            if (!$insert_stmt->execute()) {
                throw new Exception("Failed to create booking. Please try again.");
            }
            
            $booking_id = $conn->insert_id;
            
            // 5. Update class slots
            $update_stmt = $conn->prepare("UPDATE classes SET slots_available = slots_available - 1 WHERE id = ? AND slots_available > 0 AND status = 'scheduled'");
            $update_stmt->bind_param("i", $class_id);
            $update_stmt->execute();
            
            if ($update_stmt->affected_rows === 0) {
                throw new Exception("No slots available to update or class is not scheduled.");
            }
            
            // 6. Commit transaction
            $conn->commit();
            
            // Set success message and refresh
            $_SESSION['success_msg'] = "Successfully booked '{$class['title']}'! Your booking is pending confirmation. Booking ID: #{$booking_id}";
            header('Location: classes.php?success=1');
            exit();
            
        } catch (Exception $e) {
            // Rollback on any error
            $conn->rollback();
            $error_msg = $e->getMessage();
            
            // Log error for debugging
            error_log("Booking Error - Student: {$student_id}, Class: {$class_id}, Error: " . $e->getMessage());
        }
    }
}

// Load success message from session
if (isset($_SESSION['success_msg'])) {
    $success_msg = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
} elseif (isset($_GET['success'])) {
    $success_msg = "Booking successful! Please check your bookings.";
}

// Get filter parameters
$filters = [
    'age_group' => $_GET['age_group'] ?? '',
    'instructor' => isset($_GET['instructor']) ? (int)$_GET['instructor'] : 0,
    'date' => $_GET['date'] ?? ''
];

// Build WHERE clause for filtering
$where_conditions = ["c.slots_available > 0", "c.start_time > NOW()", "c.status = 'scheduled'"];
$params = [];
$param_types = '';

// Add age group filter
if (!empty($filters['age_group'])) {
    $where_conditions[] = "c.age_group = ?";
    $params[] = $filters['age_group'];
    $param_types .= 's';
}

// Add instructor filter
if ($filters['instructor'] > 0) {
    $where_conditions[] = "c.instructor_id = ?";
    $params[] = $filters['instructor'];
    $param_types .= 'i';
}

// Add date filter
if (!empty($filters['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date'])) {
    $where_conditions[] = "DATE(c.start_time) = ?";
    $params[] = $filters['date'];
    $param_types .= 's';
}

// Prepare query to get available classes with booking status
$query = "SELECT 
            c.*, 
            i.name as instructor_name,
            i.specialization,
            (SELECT COUNT(*) FROM bookings b WHERE b.class_id = c.id AND b.user_id = ? AND b.status != 'cancelled') as already_booked
          FROM classes c
          LEFT JOIN instructors i ON c.instructor_id = i.id
          WHERE " . implode(" AND ", $where_conditions) . "
          ORDER BY c.start_time ASC";

// Add student_id as first parameter for the subquery
array_unshift($params, $student_id);
$param_types = 'i' . $param_types;

$classes = [];
$stmt = $conn->prepare($query);

if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $classes = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    error_log("Database query error: " . $conn->error);
    $error_msg = "Unable to load classes. Please try again later.";
}

// Get filter options
$age_groups = $conn->query("SELECT DISTINCT age_group FROM classes WHERE slots_available > 0 AND start_time > NOW() AND status = 'scheduled' ORDER BY age_group")->fetch_all(MYSQLI_ASSOC);
$instructors = $conn->query("SELECT id, name FROM instructors WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

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
    <title>Book Classes | Elite Swimming Academy</title>
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
        
        /* Filter Panel */
        .filter-panel {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid #dee2e6;
        }
        
        .filter-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .filter-icon {
            width: 40px;
            height: 40px;
            background: #0d6efd;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }
        
        .filter-header h3 {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
        }
        
        .filter-form .form-label {
            font-weight: 500;
            color: #495057;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .filter-form .form-control,
        .filter-form .form-select {
            padding: 10px 12px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        /* Classes Header */
        .classes-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .classes-header h2 {
            font-size: 20px;
            font-weight: 600;
            margin: 0;
        }
        
        .classes-count {
            background: #e9ecef;
            color: #495057;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 500;
            font-size: 14px;
        }
        
        /* Classes Grid */
        .classes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        @media (max-width: 768px) {
            .classes-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Class Card */
        .class-card {
            background: white;
            border-radius: 10px;
            border: 1px solid #dee2e6;
            transition: all 0.2s ease;
            overflow: hidden;
        }
        
        .class-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .class-header {
            background: #0d6efd;
            padding: 15px;
            color: white;
            position: relative;
        }
        
        .class-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(255,255,255,0.2);
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .class-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .class-instructor {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }
        
        .instructor-avatar {
            width: 35px;
            height: 35px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #0d6efd;
            font-weight: 500;
            font-size: 14px;
        }
        
        .instructor-info h6 {
            margin: 0;
            font-size: 13px;
            color: rgba(255,255,255,0.9);
        }
        
        .instructor-info p {
            margin: 0;
            font-size: 11px;
            color: rgba(255,255,255,0.7);
        }
        
        .class-content {
            padding: 20px;
        }
        
        .slots-info {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #495057;
            margin-bottom: 15px;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 6px;
        }
        
        .class-details {
            margin-bottom: 15px;
        }
        
        .detail-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            font-size: 13px;
        }
        
        .detail-item i {
            color: #0d6efd;
            width: 16px;
            text-align: center;
        }
        
        .class-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
        }
        
        .class-price {
            font-size: 18px;
            font-weight: 600;
            color: #0d6efd;
        }
        
        .class-price small {
            font-size: 12px;
            color: #6c757d;
            font-weight: 400;
        }
        
        .btn-book {
            background: #198754;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 10px 20px;
            font-weight: 500;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }
        
        .btn-book:hover:not(:disabled) {
            background: #157347;
        }
        
        .btn-book:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
        
        .btn-booked {
            background: #6c757d;
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
            max-width: 400px;
            margin: 0 auto 20px;
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
            
            .filter-form .row > div {
                margin-bottom: 15px;
            }
            
            .user-profile {
                width: 100%;
                justify-content: center;
            }
        }
        
        @media (max-width: 576px) {
            .classes-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .filter-actions .btn {
                width: 100%;
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
                    <a href="classes.php" class="nav-link active">
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
                    <h1>Book Classes</h1>
                    <p>Find and book swimming classes</p>
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
            
            <!-- Filter Panel -->
            <div class="filter-panel">
                <div class="filter-header">
                    <div class="filter-icon">
                        <i class="bi bi-funnel"></i>
                    </div>
                    <h3>Filter Classes</h3>
                </div>
                
                <form method="GET" class="filter-form">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Age Group</label>
                            <select class="form-select" name="age_group">
                                <option value="">All Age Groups</option>
                                <?php foreach ($age_groups as $group): ?>
                                    <option value="<?= htmlspecialchars($group['age_group']) ?>"
                                        <?= $filters['age_group'] === $group['age_group'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($group['age_group']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Instructor</label>
                            <select class="form-select" name="instructor">
                                <option value="">All Instructors</option>
                                <?php foreach ($instructors as $instructor): ?>
                                    <option value="<?= $instructor['id'] ?>"
                                        <?= $filters['instructor'] === $instructor['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($instructor['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Date</label>
                            <input type="date" class="form-control" name="date" 
                                   value="<?= htmlspecialchars($filters['date']) ?>"
                                   min="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-funnel me-2"></i>Apply Filters
                        </button>
                        <a href="classes.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise me-2"></i>Clear
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Classes Section -->
            <div>
                <div class="classes-header">
                    <h2>Available Classes</h2>
                    <div class="classes-count">
                        <?= count($classes) ?> class<?= count($classes) !== 1 ? 'es' : '' ?> found
                    </div>
                </div>
                
                <?php if (empty($classes)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="bi bi-calendar-x"></i>
                        </div>
                        <h3>No Classes Available</h3>
                        <p>There are no classes matching your filters at the moment.</p>
                        <a href="classes.php" class="btn btn-primary">
                            <i class="bi bi-arrow-clockwise me-2"></i>View All Classes
                        </a>
                    </div>
                <?php else: ?>
                    <div class="classes-grid">
                        <?php foreach ($classes as $class): 
                            $start_time = strtotime($class['start_time']);
                            $end_time = strtotime($class['end_time']);
                            $duration = ($end_time - $start_time) / 60;
                            $already_booked = $class['already_booked'] > 0;
                            $has_end_time = !empty($class['end_time']) && $class['end_time'] != '0000-00-00 00:00:00';
                        ?>
                            <div class="class-card">
                                <div class="class-header">
                                    <div class="class-badge">
                                        <?= htmlspecialchars($class['age_group']) ?>
                                    </div>
                                    <h3 class="class-title"><?= htmlspecialchars($class['title']) ?></h3>
                                    <div class="class-instructor">
                                        <div class="instructor-avatar">
                                            <?= strtoupper(substr($class['instructor_name'] ?? 'I', 0, 1)) ?>
                                        </div>
                                        <div class="instructor-info">
                                            <h6><?= htmlspecialchars($class['instructor_name'] ?? 'TBA') ?></h6>
                                            <?php if (!empty($class['specialization'])): ?>
                                                <p><?= htmlspecialchars($class['specialization']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="class-content">
                                    <div class="slots-info">
                                        <i class="bi bi-people"></i>
                                        <span><?= $class['slots_available'] ?> of <?= $class['slots_total'] ?> slots available</span>
                                    </div>
                                    
                                    <div class="class-details">
                                        <div class="detail-item">
                                            <i class="bi bi-calendar-date"></i>
                                            <span><?= date('F j, Y', $start_time) ?></span>
                                        </div>
                                        <div class="detail-item">
                                            <i class="bi bi-clock"></i>
                                            <span>
                                                <?= date('g:i A', $start_time) ?>
                                                <?php if ($has_end_time): ?>
                                                     - <?= date('g:i A', $end_time) ?>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <?php if ($has_end_time): ?>
                                            <div class="detail-item">
                                                <i class="bi bi-hourglass"></i>
                                                <span><?= $duration ?> minutes</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="class-footer">
                                        <div class="class-price">
                                            $<?= number_format($class['price'], 2) ?>
                                            <small>/session</small>
                                        </div>
                                        
                                        <?php if ($already_booked): ?>
                                            <button class="btn-book btn-booked" disabled>
                                                <i class="bi bi-check-circle"></i>
                                                Booked
                                            </button>
                                        <?php elseif ($class['slots_available'] <= 0): ?>
                                            <button class="btn-book" disabled>
                                                <i class="bi bi-x-circle"></i>
                                                Full
                                            </button>
                                        <?php else: ?>
                                            <form method="POST" class="booking-form" onsubmit="return confirmBooking(this)">
                                                <input type="hidden" name="action" value="book_class">
                                                <input type="hidden" name="class_id" value="<?= $class['id'] ?>">
                                                <button type="submit" class="btn-book">
                                                    <i class="bi bi-calendar-plus"></i>
                                                    Book
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Confirm booking
        function confirmBooking(form) {
            const classTitle = form.closest('.class-card').querySelector('.class-title').textContent;
            
            if (!confirm(`Book "${classTitle}"?\n\nThis will reserve one slot for you.`)) {
                return false;
            }
            
            return true;
        }
        
        // Update min date for date filter
        document.addEventListener('DOMContentLoaded', function() {
            const dateInput = document.querySelector('input[name="date"]');
            if (dateInput) {
                dateInput.min = new Date().toISOString().split('T')[0];
            }
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