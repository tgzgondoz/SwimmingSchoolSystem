<?php
// student/classes.php - Professional Browse and Book Classes Page (Fixed)
session_start();
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

requireRole('student');
$user = getCurrentUser($conn);
$student_id = $_SESSION['user_id'];

// Initialize messages
$success_message = $error_message = '';

// Handle class booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_class'])) {
    $class_id = intval($_POST['class_id']);
    
    // Check if already booked
    $check_stmt = $conn->prepare("SELECT id FROM bookings WHERE user_id = ? AND class_id = ?");
    $check_stmt->bind_param('ii', $student_id, $class_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $error_message = "You have already booked this class.";
    } else {
        // Check if class has available slots
        $class_stmt = $conn->prepare("SELECT slots_available, title FROM classes WHERE id = ?");
        $class_stmt->bind_param('i', $class_id);
        $class_stmt->execute();
        $class_result = $class_stmt->get_result();
        $class = $class_result->fetch_assoc();
        
        if ($class['slots_available'] <= 0) {
            $error_message = "This class is fully booked.";
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Create booking
                $stmt = $conn->prepare("INSERT INTO bookings (user_id, class_id, status, booking_date) VALUES (?, ?, 'confirmed', NOW())");
                $stmt->bind_param('ii', $student_id, $class_id);
                $stmt->execute();
                
                // Update available slots
                $update_stmt = $conn->prepare("UPDATE classes SET slots_available = slots_available - 1 WHERE id = ?");
                $update_stmt->bind_param('i', $class_id);
                $update_stmt->execute();
                
                $conn->commit();
                $success_message = "Successfully booked <strong>{$class['title']}</strong>! Your booking is confirmed.";
                
                // Refresh the page to show updated slot count
                header("Location: classes.php?success=" . urlencode($success_message));
                exit();
                
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Failed to book class. Please try again.";
            }
        }
    }
}

// Display success message from URL parameter
if (isset($_GET['success'])) {
    $success_message = htmlspecialchars($_GET['success']);
}

// Get filter parameters
$age_group_filter = $_GET['age_group'] ?? '';
$instructor_filter = $_GET['instructor'] ?? 0;
$date_filter = $_GET['date'] ?? '';

// Build query with filters
$where_conditions = ["c.start_time >= NOW()", "c.slots_available > 0"];
$params = [];
$types = '';

if ($age_group_filter) {
    $where_conditions[] = "c.age_group = ?";
    $params[] = $age_group_filter;
    $types .= 's';
}

if ($instructor_filter) {
    $where_conditions[] = "c.instructor_id = ?";
    $params[] = $instructor_filter;
    $types .= 'i';
}

if ($date_filter) {
    $where_conditions[] = "DATE(c.start_time) = ?";
    $params[] = $date_filter;
    $types .= 's';
}

// Add the student_id parameter for checking bookings
array_unshift($params, $student_id);
$types = 'i' . $types;

$query = "
    SELECT c.*, i.name as instructor_name,
           (SELECT COUNT(*) FROM bookings WHERE class_id = c.id AND user_id = ?) as is_booked
    FROM classes c
    LEFT JOIN instructors i ON c.instructor_id = i.id
    WHERE " . implode(' AND ', $where_conditions) . "
    ORDER BY c.start_time ASC
";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get unique values for filters
$age_groups = $conn->query("SELECT DISTINCT age_group FROM classes WHERE start_time >= NOW() ORDER BY age_group")->fetch_all(MYSQLI_ASSOC);
$instructors = $conn->query("SELECT id, name FROM instructors ORDER BY name")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Classes | AquaFlow Student Portal</title>
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

        /* Filter Panel */
        .filter-panel {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--gray-200);
        }

        .filter-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }

        .filter-header h3 {
            font-family: 'Poppins', sans-serif;
            font-size: 20px;
            font-weight: 600;
            margin: 0;
            color: var(--gray-900);
        }

        .filter-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 22px;
        }

        .filter-form .row {
            align-items: flex-end;
        }

        .form-label {
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-control, .form-select {
            padding: 12px 16px;
            border: 2px solid var(--gray-200);
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: var(--gray-50);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
            background: white;
        }

        .filter-actions {
            display: flex;
            gap: 12px;
            margin-top: 10px;
        }

        .btn-primary {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: 10px;
            padding: 12px 24px;
            font-weight: 600;
            font-size: 15px;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(13, 110, 253, 0.3);
        }

        .btn-outline {
            background: white;
            color: var(--primary);
            border: 2px solid var(--primary);
            border-radius: 10px;
            padding: 12px 24px;
            font-weight: 600;
            font-size: 15px;
            transition: all 0.3s ease;
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        /* Classes Grid */
        .classes-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .classes-header h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 24px;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
        }

        .classes-count {
            background: var(--primary-light);
            color: var(--primary);
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
        }

        .classes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        @media (max-width: 992px) {
            .classes-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .classes-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Class Card */
        .class-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border: 1px solid var(--gray-200);
            position: relative;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .class-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }

        .class-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 2;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-age {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            color: white;
        }

        .class-image {
            height: 180px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            position: relative;
            overflow: hidden;
        }

        .class-image::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, rgba(0,0,0,0.2), rgba(0,0,0,0.1));
        }

        .slots-indicator {
            position: absolute;
            bottom: 20px;
            left: 20px;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            padding: 8px 16px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-800);
        }

        .slots-indicator i {
            color: var(--primary);
        }

        .class-content {
            padding: 25px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .class-title {
            font-family: 'Poppins', sans-serif;
            font-size: 20px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 12px;
            line-height: 1.4;
        }

        .class-instructor {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .instructor-avatar {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
        }

        .instructor-info h5 {
            font-size: 15px;
            font-weight: 600;
            margin: 0;
            color: var(--gray-900);
        }

        .instructor-info p {
            font-size: 13px;
            color: var(--gray-500);
            margin: 0;
        }

        .class-details {
            margin-bottom: 20px;
            flex: 1;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            font-size: 14px;
            color: var(--gray-600);
        }

        .detail-item i {
            color: var(--primary);
            width: 20px;
            text-align: center;
        }

        .class-price {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 20px;
            border-top: 1px solid var(--gray-200);
            margin-top: auto;
        }

        .price-tag {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }

        .price-tag span {
            font-size: 14px;
            color: var(--gray-500);
            font-weight: 500;
        }

        .btn-book {
            background: linear-gradient(90deg, var(--success), #059669);
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

        .btn-book:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
        }

        .btn-book:disabled {
            background: var(--gray-400);
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        .btn-booked {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
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
            
            .filter-form .row > div {
                margin-bottom: 15px;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .btn-primary, .btn-outline {
                width: 100%;
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
                    <a href="classes.php" class="nav-link active">
                        <i class="bi bi-calendar-week"></i>
                        <span class="nav-text">Classes</span>
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
                    <h1>Browse Swimming Classes</h1>
                    <p>Discover and book classes that match your skill level and schedule</p>
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

            <!-- Filter Panel -->
            <div class="filter-panel fade-in">
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
                                <?php foreach($age_groups as $group): ?>
                                    <option value="<?= htmlspecialchars($group['age_group']) ?>" 
                                        <?= $age_group_filter == $group['age_group'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($group['age_group']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Instructor</label>
                            <select class="form-select" name="instructor">
                                <option value="">All Instructors</option>
                                <?php foreach($instructors as $instructor): ?>
                                    <option value="<?= $instructor['id'] ?>" 
                                        <?= $instructor_filter == $instructor['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($instructor['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Date</label>
                            <input type="date" class="form-control" name="date" 
                                   value="<?= htmlspecialchars($date_filter) ?>"
                                   min="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    
                    <div class="filter-actions mt-4">
                        <button type="submit" class="btn-primary">
                            <i class="bi bi-search me-2"></i>Apply Filters
                        </button>
                        <a href="classes.php" class="btn-outline">
                            <i class="bi bi-arrow-clockwise me-2"></i>Clear Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- Classes Section -->
            <div class="fade-in">
                <div class="classes-header">
                    <h2>Available Classes</h2>
                    <div class="classes-count">
                        <?= count($classes) ?> class<?= count($classes) != 1 ? 'es' : '' ?> found
                    </div>
                </div>

                <?php if(empty($classes)): ?>
                    <div class="empty-state">
                        <i class="bi bi-calendar-x"></i>
                        <h3>No Classes Found</h3>
                        <p>No swimming classes match your current filters. Try adjusting your search criteria or check back later for new classes.</p>
                        <a href="classes.php" class="btn-primary">
                            <i class="bi bi-arrow-clockwise me-2"></i>Clear All Filters
                        </a>
                    </div>
                <?php else: ?>
                    <div class="classes-grid">
                        <?php foreach($classes as $class): 
                            // Determine slot availability color
                            $max_capacity = $class['max_capacity'] ?? 10;
                            $slot_percentage = ($class['slots_available'] / $max_capacity) * 100;
                            
                            // Check if already booked
                            $is_booked = $class['is_booked'] > 0;
                            
                            // Format date and time
                            $start_time = strtotime($class['start_time']);
                            $end_time = strtotime($class['end_time']);
                            $duration_minutes = ($end_time - $start_time) / 60;
                        ?>
                            <div class="class-card">
                                <!-- Badges -->
                                <div class="class-badge badge-age">
                                    <?= htmlspecialchars($class['age_group']) ?>
                                </div>
                                
                                <!-- Class Image -->
                                <div class="class-image">
                                    <div class="slots-indicator">
                                        <i class="bi bi-people-fill"></i>
                                        <span><?= $class['slots_available'] ?> of <?= $max_capacity ?> slots available</span>
                                    </div>
                                </div>
                                
                                <!-- Class Content -->
                                <div class="class-content">
                                    <!-- Title -->
                                    <h3 class="class-title"><?= htmlspecialchars($class['title']) ?></h3>
                                    
                                    <!-- Instructor -->
                                    <div class="class-instructor">
                                        <div class="instructor-avatar">
                                            <?= strtoupper(substr($class['instructor_name'] ?? 'I', 0, 1)) ?>
                                        </div>
                                        <div class="instructor-info">
                                            <h5><?= htmlspecialchars($class['instructor_name'] ?? 'TBA') ?></h5>
                                            <p>Certified Swimming Instructor</p>
                                        </div>
                                    </div>
                                    
                                    <!-- Details -->
                                    <div class="class-details">
                                        <div class="detail-item">
                                            <i class="bi bi-calendar"></i>
                                            <span><?= date('F j, Y', $start_time) ?></span>
                                        </div>
                                        <div class="detail-item">
                                            <i class="bi bi-clock"></i>
                                            <span><?= date('g:i A', $start_time) ?> - <?= date('g:i A', $end_time) ?></span>
                                        </div>
                                        <div class="detail-item">
                                            <i class="bi bi-hourglass"></i>
                                            <span><?= $duration_minutes ?> minutes</span>
                                        </div>
                                        <?php if(!empty($class['description'])): ?>
                                            <p class="mt-2 text-muted" style="font-size: 14px;">
                                                <?= htmlspecialchars(substr($class['description'], 0, 100)) ?>
                                                <?= strlen($class['description']) > 100 ? '...' : '' ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Price and Book Button -->
                                    <div class="class-price">
                                        <div class="price-tag">
                                            $<?= number_format($class['price'] ?? 0, 2) ?>
                                            <span>per class</span>
                                        </div>
                                        
                                        <?php if($is_booked): ?>
                                            <button class="btn-book btn-booked" disabled>
                                                <i class="bi bi-check-circle"></i>
                                                Already Booked
                                            </button>
                                        <?php elseif($class['slots_available'] <= 0): ?>
                                            <button class="btn-book" disabled>
                                                <i class="bi bi-x-circle"></i>
                                                Fully Booked
                                            </button>
                                        <?php else: ?>
                                            <form method="POST" style="margin: 0;">
                                                <input type="hidden" name="class_id" value="<?= $class['id'] ?>">
                                                <button type="submit" name="book_class" class="btn-book">
                                                    <i class="bi bi-calendar-plus"></i>
                                                    Book Now
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
        document.addEventListener('DOMContentLoaded', function() {
            // Set min date for date filter
            const today = new Date().toISOString().split('T')[0];
            document.querySelector('input[name="date"]').min = today;
            
            // Add animation to class cards
            const cards = document.querySelectorAll('.class-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('fade-in');
            });
            
            // Confirmation for booking
            const bookingForms = document.querySelectorAll('form[method="POST"]');
            bookingForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const button = this.querySelector('button[type="submit"]');
                    const classTitle = this.closest('.class-card').querySelector('.class-title').textContent;
                    
                    if (!confirm(`Are you sure you want to book "${classTitle}"?`)) {
                        e.preventDefault();
                        return;
                    }
                    
                    // Show loading state
                    const originalText = button.innerHTML;
                    button.innerHTML = '<i class="bi bi-hourglass-split"></i> Booking...';
                    button.disabled = true;
                    
                    // Revert after 3 seconds if form doesn't submit
                    setTimeout(() => {
                        if (!button.disabled) {
                            button.innerHTML = originalText;
                        }
                    }, 3000);
                });
            });
            
            // Add hover effect to filter panel
            const filterPanel = document.querySelector('.filter-panel');
            filterPanel.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
            });
            
            filterPanel.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
            
            // Update classes count with animation
            const classesCount = document.querySelector('.classes-count');
            if (classesCount) {
                let count = parseInt(classesCount.textContent);
                classesCount.textContent = '0 classes found';
                
                let current = 0;
                const increment = Math.ceil(count / 20);
                
                const timer = setInterval(() => {
                    current += increment;
                    if (current >= count) {
                        current = count;
                        clearInterval(timer);
                    }
                    classesCount.textContent = current + ' class' + (current !== 1 ? 'es' : '') + ' found';
                }, 50);
            }
            
            // Show more details on card click (mobile)
            if (window.innerWidth < 768) {
                document.querySelectorAll('.class-card').forEach(card => {
                    card.style.cursor = 'pointer';
                    card.addEventListener('click', function(e) {
                        if (!e.target.closest('.btn-book') && !e.target.closest('form')) {
                            this.classList.toggle('expanded');
                        }
                    });
                });
            }
        });
    </script>
</body>
</html>