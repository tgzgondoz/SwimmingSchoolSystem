<?php
// student/profile.php - Student Profile Management
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

// Get user info
$user = [];
$user_stmt = $conn->prepare("SELECT id, name, email, phone, age, emergency_contact, medical_notes, created_at, updated_at FROM users WHERE id = ?");
if ($user_stmt) {
    $user_stmt->bind_param("i", $student_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    $user = $user_result->fetch_assoc() ?: [];
    $user_stmt->close();
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $age = isset($_POST['age']) ? intval($_POST['age']) : null;
        $emergency_contact = trim($_POST['emergency_contact'] ?? '');
        $medical_notes = trim($_POST['medical_notes'] ?? '');
        
        // Validate inputs
        if (empty($name) || empty($email)) {
            $error_msg = "Name and email are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = "Invalid email format.";
        } else {
            try {
                // Check if email already exists (excluding current user)
                $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $check_stmt->bind_param('si', $email, $student_id);
                $check_stmt->execute();
                
                if ($check_stmt->get_result()->num_rows > 0) {
                    $error_msg = "Email address already exists.";
                } else {
                    // Update user profile
$update_stmt = $conn->prepare("
    UPDATE users SET 
    name = ?, email = ?, phone = ?, age = ?, 
    emergency_contact = ?, medical_notes = ?, updated_at = NOW()
    WHERE id = ?
");
$update_stmt->bind_param('sssissi', $name, $email, $phone, $age, $emergency_contact, $medical_notes, $student_id); 
                    if ($update_stmt->execute()) {
                        $success_msg = "Profile updated successfully!";
                        // Refresh user data
                        $user_stmt = $conn->prepare("SELECT id, name, email, phone, age, emergency_contact, medical_notes, created_at, updated_at FROM users WHERE id = ?");
                        $user_stmt->bind_param("i", $student_id);
                        $user_stmt->execute();
                        $user_result = $user_stmt->get_result();
                        $user = $user_result->fetch_assoc() ?: [];
                        $user_stmt->close();
                    } else {
                        $error_msg = "Failed to update profile. Please try again.";
                    }
                }
            } catch (Exception $e) {
                $error_msg = "Database error: " . $e->getMessage();
                error_log("Profile update error: " . $e->getMessage());
            }
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_msg = "All password fields are required.";
        } elseif ($new_password !== $confirm_password) {
            $error_msg = "New passwords do not match!";
        } elseif (strlen($new_password) < 8) {
            $error_msg = "Password must be at least 8 characters long.";
        } else {
            try {
                // Verify current password
                $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->bind_param('i', $student_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $user_data = $result->fetch_assoc();
                
                if (!$user_data || !password_verify($current_password, $user_data['password'])) {
                    $error_msg = "Current password is incorrect!";
                } else {
                    // Hash new password and update
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_stmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                    $update_stmt->bind_param('si', $hashed_password, $student_id);
                    
                    if ($update_stmt->execute()) {
                        $success_msg = "Password changed successfully!";
                    } else {
                        $error_msg = "Failed to change password. Please try again.";
                    }
                }
            } catch (Exception $e) {
                $error_msg = "Database error: " . $e->getMessage();
                error_log("Password change error: " . $e->getMessage());
            }
        }
    }
}

// Get user's class history
$class_history = [];
try {
    $history_stmt = $conn->prepare("
        SELECT c.*, i.name as instructor_name, b.status as booking_status,
               b.created_at as booking_date
        FROM bookings b
        JOIN classes c ON b.class_id = c.id
        LEFT JOIN instructors i ON c.instructor_id = i.id
        WHERE b.user_id = ?
        ORDER BY c.start_time DESC
        LIMIT 10
    ");
    $history_stmt->bind_param("i", $student_id);
    $history_stmt->execute();
    $history_result = $history_stmt->get_result();
    $class_history = $history_result->fetch_all(MYSQLI_ASSOC) ?: [];
    $history_stmt->close();
} catch (Exception $e) {
    error_log("Class history query error: " . $e->getMessage());
}

// Get user statistics
$user_stats = [
    'total_bookings' => 0,
    'completed_classes' => 0,
    'upcoming_classes' => 0
];

try {
    $stats_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_bookings,
            SUM(CASE WHEN c.end_time < NOW() AND b.status = 'confirmed' THEN 1 ELSE 0 END) as completed_classes,
            SUM(CASE WHEN c.start_time >= NOW() AND b.status = 'confirmed' THEN 1 ELSE 0 END) as upcoming_classes
        FROM bookings b
        JOIN classes c ON b.class_id = c.id
        WHERE b.user_id = ?
    ");
    $stats_stmt->bind_param("i", $student_id);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $user_stats = $stats_result->fetch_assoc() ?: $user_stats;
    $stats_stmt->close();
} catch (Exception $e) {
    error_log("User stats query error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Elite Swimming Academy</title>
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
        
        /* Profile Header */
        .profile-header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
            font-weight: 600;
            margin: 0 auto 20px;
            box-shadow: 0 10px 25px rgba(13, 110, 253, 0.2);
        }
        
        /* Profile Card */
        .profile-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .profile-card:hover {
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        /* Tabs */
        .nav-tabs {
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 30px;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #495057;
            font-weight: 500;
            padding: 12px 24px;
            margin-right: 10px;
            border-radius: 10px 10px 0 0;
            transition: all 0.3s ease;
        }
        
        .nav-tabs .nav-link:hover {
            color: var(--primary);
            background: var(--light);
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary);
            background: white;
            border-bottom: 3px solid var(--primary);
            font-weight: 600;
        }
        
        /* Form Styling */
        .form-label {
            font-weight: 500;
            color: #495057;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .form-control, .form-select {
            padding: 12px 16px;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: var(--light);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
            background: white;
        }
        
        .btn-save {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: 600;
            font-size: 15px;
            transition: all 0.3s ease;
        }
        
        .btn-save:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(13, 110, 253, 0.3);
        }
        
        .btn-save:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        /* Info Items */
        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 15px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            color: #6c757d;
            font-weight: 500;
        }
        
        .info-value {
            color: var(--dark);
            font-weight: 600;
        }
        
        /* Password Strength */
        .password-strength {
            height: 4px;
            border-radius: 2px;
            background: #dee2e6;
            margin-top: 8px;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease;
        }
        
        .strength-weak {
            background: var(--danger);
        }
        
        .strength-medium {
            background: var(--warning);
        }
        
        .strength-strong {
            background: var(--success);
        }
        
        /* Class History */
        .class-history-item {
            padding: 20px;
            border-radius: 12px;
            background: var(--light);
            margin-bottom: 15px;
            border-left: 4px solid var(--primary);
            transition: all 0.3s ease;
        }
        
        .class-history-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        
        /* Badges */
        .badge-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: white;
        }
        
        .status-completed {
            background: linear-gradient(135deg, var(--success) 0%, #157347 100%);
        }
        
        .status-upcoming {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        }
        
        .status-cancelled {
            background: linear-gradient(135deg, var(--danger) 0%, #dc3545 100%);
        }
        
        .status-pending {
            background: linear-gradient(135deg, var(--warning) 0%, #ffca2c 100%);
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
        
        .empty-state i {
            font-size: 72px;
            color: #dee2e6;
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            font-family: 'Poppins', sans-serif;
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 12px;
            color: #495057;
        }
        
        /* Switch */
        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        /* Modal */
        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
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
            
            .nav-tabs .nav-link {
                padding: 10px 15px;
                font-size: 14px;
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
            
            .profile-header .row {
                flex-direction: column;
                text-align: center;
            }
            
            .nav-tabs {
                flex-direction: column;
            }
            
            .nav-tabs .nav-link {
                margin-right: 0;
                margin-bottom: 5px;
                text-align: left;
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
                    <a href="profile.php" class="nav-link active">
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
                    <h1>My Profile</h1>
                    <p>Manage your personal information and account settings</p>
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
            
            <!-- Alert Messages -->
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
            
            <!-- Profile Header -->
            <div class="profile-header fade-in">
                <div class="row align-items-center">
                    <div class="col-md-4 text-center mb-4 mb-md-0">
                        <div class="profile-avatar">
                            <?= isset($user['name']) ? strtoupper(substr($user['name'], 0, 1)) : 'S' ?>
                        </div>
                        <h3 class="fw-bold mb-1" style="font-family: 'Poppins', sans-serif;"><?= htmlspecialchars($user['name'] ?? 'Student') ?></h3>
                        <p class="text-muted mb-0">Student</p>
                    </div>
                    <div class="col-md-8">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <div class="text-center p-3 bg-light rounded">
                                    <div class="h3 mb-1 fw-bold"><?= $user_stats['total_bookings'] ?></div>
                                    <div class="text-muted">Total Bookings</div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="text-center p-3 bg-light rounded">
                                    <div class="h3 mb-1 fw-bold text-success"><?= $user_stats['completed_classes'] ?></div>
                                    <div class="text-muted">Completed</div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="text-center p-3 bg-light rounded">
                                    <div class="h3 mb-1 fw-bold text-primary"><?= $user_stats['upcoming_classes'] ?></div>
                                    <div class="text-muted">Upcoming</div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="text-center p-3 bg-light rounded">
                                    <div class="h3 mb-1 fw-bold">
                                        <?= isset($user['created_at']) ? date('M j, Y', strtotime($user['created_at'])) : 'N/A' ?>
                                    </div>
                                    <div class="text-muted">Member Since</div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="text-center p-3 bg-light rounded">
                                    <div class="h3 mb-1 fw-bold">
                                        <?= isset($user['updated_at']) && $user['updated_at'] != '0000-00-00 00:00:00' ? date('M j, Y', strtotime($user['updated_at'])) : 'N/A' ?>
                                    </div>
                                    <div class="text-muted">Last Updated</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tabs Navigation -->
            <ul class="nav nav-tabs fade-in" id="profileTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="edit-tab" data-bs-toggle="tab" data-bs-target="#edit" type="button">
                        <i class="bi bi-person me-2"></i>Edit Profile
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="password-tab" data-bs-toggle="tab" data-bs-target="#password" type="button">
                        <i class="bi bi-key me-2"></i>Change Password
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button">
                        <i class="bi bi-clock-history me-2"></i>Class History
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings" type="button">
                        <i class="bi bi-gear me-2"></i>Settings
                    </button>
                </li>
            </ul>
            
            <!-- Tab Content -->
            <div class="tab-content fade-in" id="profileTabContent">
                <!-- Edit Profile Tab -->
                <div class="tab-pane fade show active" id="edit" role="tabpanel">
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="profile-card">
                                <h3 class="mb-4" style="font-family: 'Poppins', sans-serif; font-weight: 600;">Personal Information</h3>
                                <form method="POST" id="profileForm">
                                    <input type="hidden" name="action" value="update_profile">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Full Name *</label>
                                            <input type="text" class="form-control" name="name" 
                                                   value="<?= htmlspecialchars($user['name'] ?? '') ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Email Address *</label>
                                            <input type="email" class="form-control" name="email" 
                                                   value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Phone Number</label>
                                            <input type="tel" class="form-control" name="phone" 
                                                   value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                                                   placeholder="+263 77 123 4567">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Age</label>
                                            <input type="number" class="form-control" name="age" min="1" max="100"
                                                   value="<?= $user['age'] ?? '' ?>">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Emergency Contact</label>
                                            <input type="text" class="form-control" name="emergency_contact" 
                                                   value="<?= htmlspecialchars($user['emergency_contact'] ?? '') ?>"
                                                   placeholder="Name and phone number">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Medical Notes</label>
                                            <textarea class="form-control" name="medical_notes" rows="3"
                                                      placeholder="Any medical conditions, allergies, or special requirements..."><?= htmlspecialchars($user['medical_notes'] ?? '') ?></textarea>
                                        </div>
                                        <div class="col-12 mt-4">
                                            <button type="submit" class="btn-save">
                                                <i class="bi bi-check-circle me-2"></i>Save Changes
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="profile-card">
                                <h5 class="mb-3" style="font-family: 'Poppins', sans-serif; font-weight: 600;">Account Information</h5>
                                <div class="info-item">
                                    <span class="info-label">Account Type</span>
                                    <span class="info-value">Student</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Member Since</span>
                                    <span class="info-value"><?= isset($user['created_at']) ? date('M j, Y', strtotime($user['created_at'])) : 'N/A' ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Account Status</span>
                                    <span class="info-value text-success fw-bold">Active</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Student ID</span>
                                    <span class="info-value">#<?= $student_id ?></span>
                                </div>
                            </div>
                            
                            <div class="profile-card mt-4">
                                <h5 class="mb-3" style="font-family: 'Poppins', sans-serif; font-weight: 600;">Emergency Information</h5>
                                <?php if (!empty($user['emergency_contact'])): ?>
                                    <p class="mb-2 fw-medium"><?= htmlspecialchars($user['emergency_contact']) ?></p>
                                    <small class="text-muted">Contact in case of emergency</small>
                                <?php else: ?>
                                    <p class="text-muted mb-0"><i>No emergency contact provided</i></p>
                                <?php endif; ?>
                                
                                <?php if (!empty($user['medical_notes'])): ?>
                                    <hr>
                                    <h6 class="mb-2" style="font-family: 'Poppins', sans-serif; font-weight: 600;">Medical Notes</h6>
                                    <p class="small text-muted mb-0"><?= htmlspecialchars($user['medical_notes']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Change Password Tab -->
                <div class="tab-pane fade" id="password" role="tabpanel">
                    <div class="row justify-content-center">
                        <div class="col-lg-6">
                            <div class="profile-card">
                                <h3 class="mb-4" style="font-family: 'Poppins', sans-serif; font-weight: 600;">Change Password</h3>
                                <form method="POST" id="passwordForm">
                                    <input type="hidden" name="action" value="change_password">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label">Current Password *</label>
                                            <input type="password" class="form-control" name="current_password" required>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">New Password *</label>
                                            <input type="password" class="form-control" name="new_password" id="newPassword" required>
                                            <div class="password-strength">
                                                <div class="password-strength-bar" id="passwordStrength"></div>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Confirm New Password *</label>
                                            <input type="password" class="form-control" name="confirm_password" id="confirmPassword" required>
                                            <div class="form-text" id="passwordMatch"></div>
                                        </div>
                                        <div class="col-12">
                                            <div class="alert alert-info">
                                                <i class="bi bi-info-circle me-2"></i>
                                                Password must be at least 8 characters long and include uppercase, lowercase, and numbers.
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" class="btn-save" id="changePasswordBtn">
                                                <i class="bi bi-key me-2"></i>Change Password
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Class History Tab -->
                <div class="tab-pane fade" id="history" role="tabpanel">
                    <div class="profile-card">
                        <h3 class="mb-4" style="font-family: 'Poppins', sans-serif; font-weight: 600;">Class History</h3>
                        <?php if (empty($class_history)): ?>
                            <div class="empty-state">
                                <i class="bi bi-calendar-x"></i>
                                <h3>No Class History</h3>
                                <p>You haven't attended any classes yet.</p>
                                <a href="classes.php" class="btn-save" style="text-decoration: none; display: inline-block;">
                                    <i class="bi bi-search me-2"></i>Browse Classes
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($class_history as $class): 
                                    $has_end_time = !empty($class['end_time']) && $class['end_time'] != '0000-00-00 00:00:00';
                                ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="class-history-item">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="mb-0 fw-bold"><?= htmlspecialchars($class['title']) ?></h6>
                                                <?php
                                                $status_class = 'status-pending';
                                                $status_text = ucfirst($class['booking_status']);
                                                
                                                if ($class['booking_status'] === 'confirmed') {
                                                    if (strtotime($class['start_time']) > time()) {
                                                        $status_class = 'status-upcoming';
                                                        $status_text = 'Upcoming';
                                                    } else {
                                                        $status_class = 'status-completed';
                                                        $status_text = 'Completed';
                                                    }
                                                } elseif ($class['booking_status'] === 'cancelled') {
                                                    $status_class = 'status-cancelled';
                                                    $status_text = 'Cancelled';
                                                }
                                                ?>
                                                <span class="badge-status <?= $status_class ?>"><?= $status_text ?></span>
                                            </div>
                                            <div class="text-muted small mb-2">
                                                <i class="bi bi-person me-1"></i><?= htmlspecialchars($class['instructor_name'] ?? 'TBA') ?>
                                            </div>
                                            <div class="text-muted small mb-2">
                                                <i class="bi bi-calendar me-1"></i>
                                                <?= date('M j, Y', strtotime($class['start_time'])) ?>
                                            </div>
                                            <div class="text-muted small">
                                                <i class="bi bi-clock me-1"></i>
                                                <?= date('g:i A', strtotime($class['start_time'])) ?>
                                                <?php if ($has_end_time): ?>
                                                     - <?= date('g:i A', strtotime($class['end_time'])) ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Settings Tab -->
                <div class="tab-pane fade" id="settings" role="tabpanel">
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="profile-card">
                                <h3 class="mb-4" style="font-family: 'Poppins', sans-serif; font-weight: 600;">Notification Settings</h3>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="emailNotifications" checked>
                                    <label class="form-check-label fw-medium" for="emailNotifications">
                                        Email notifications
                                    </label>
                                </div>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="smsNotifications">
                                    <label class="form-check-label fw-medium" for="smsNotifications">
                                        SMS notifications
                                    </label>
                                </div>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="bookingReminders" checked>
                                    <label class="form-check-label fw-medium" for="bookingReminders">
                                        Booking reminders
                                    </label>
                                </div>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="paymentReminders" checked>
                                    <label class="form-check-label fw-medium" for="paymentReminders">
                                        Payment reminders
                                    </label>
                                </div>
                                <button class="btn-save mt-3" onclick="saveSettings()">
                                    <i class="bi bi-save me-2"></i>Save Settings
                                </button>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="profile-card">
                                <h3 class="mb-4" style="font-family: 'Poppins', sans-serif; font-weight: 600;">Privacy Settings</h3>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="showProfile" checked>
                                    <label class="form-check-label fw-medium" for="showProfile">
                                        Show profile to other students
                                    </label>
                                </div>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="showAttendance">
                                    <label class="form-check-label fw-medium" for="showAttendance">
                                        Show attendance statistics
                                    </label>
                                </div>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                    Changing privacy settings may affect your experience in the community.
                                </div>
                            </div>
                            
                            <div class="profile-card mt-4">
                                <h3 class="mb-4" style="font-family: 'Poppins', sans-serif; font-weight: 600;">Account Actions</h3>
                                <div class="d-grid gap-2">
                                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#exportDataModal">
                                        <i class="bi bi-download me-2"></i>Export My Data
                                    </button>
                                    <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#deactivateModal">
                                        <i class="bi bi-pause-circle me-2"></i>Temporarily Deactivate
                                    </button>
                                    <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
                                        <i class="bi bi-trash me-2"></i>Delete Account
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Export Data Modal -->
    <div class="modal fade" id="exportDataModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Export My Data</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Select the data you want to export:</p>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="exportProfile" checked>
                        <label class="form-check-label" for="exportProfile">Profile Information</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="exportBookings" checked>
                        <label class="form-check-label" for="exportBookings">Booking History</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="exportPayments">
                        <label class="form-check-label" for="exportPayments">Payment History</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="exportProgress">
                        <label class="form-check-label" for="exportProgress">Progress Reports</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="exportData()">Export Data</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Deactivate Account Modal -->
    <div class="modal fade" id="deactivateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Deactivate Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to temporarily deactivate your account?</p>
                    <p class="text-muted">Your account will be hidden and you won't be able to book classes until you reactivate it.</p>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="confirmDeactivate">
                        <label class="form-check-label" for="confirmDeactivate">
                            I understand that I won't be able to book classes while my account is deactivated
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" onclick="deactivateAccount()" disabled>Deactivate Account</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Account Modal -->
    <div class="modal fade" id="deleteAccountModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> This action cannot be undone!
                    </div>
                    <p>All your data including bookings, payments, and profile information will be permanently deleted.</p>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="confirmDelete">
                        <label class="form-check-label" for="confirmDelete">
                            I understand that this action is permanent and cannot be reversed
                        </label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="confirmDataLoss">
                        <label class="form-check-label" for="confirmDataLoss">
                            I understand that all my data will be permanently deleted
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="deleteAccount()" disabled>Delete Account</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Password strength checker
            const newPasswordInput = document.getElementById('newPassword');
            const confirmPasswordInput = document.getElementById('confirmPassword');
            const passwordStrengthBar = document.getElementById('passwordStrength');
            const passwordMatchText = document.getElementById('passwordMatch');
            
            function checkPasswordStrength(password) {
                let strength = 0;
                
                // Length check
                if (password.length >= 8) strength++;
                if (password.length >= 12) strength++;
                
                // Character type checks
                if (/[a-z]/.test(password)) strength++;
                if (/[A-Z]/.test(password)) strength++;
                if (/[0-9]/.test(password)) strength++;
                if (/[^a-zA-Z0-9]/.test(password)) strength++;
                
                return strength;
            }
            
            function updatePasswordStrength() {
                const password = newPasswordInput.value;
                const strength = checkPasswordStrength(password);
                
                let width = 0;
                let color = '';
                
                if (password.length === 0) {
                    width = 0;
                } else if (strength <= 2) {
                    width = 33;
                    color = 'strength-weak';
                } else if (strength <= 4) {
                    width = 66;
                    color = 'strength-medium';
                } else {
                    width = 100;
                    color = 'strength-strong';
                }
                
                passwordStrengthBar.style.width = width + '%';
                passwordStrengthBar.className = 'password-strength-bar ' + color;
            }
            
            function checkPasswordMatch() {
                const password = newPasswordInput.value;
                const confirm = confirmPasswordInput.value;
                
                if (confirm.length === 0) {
                    passwordMatchText.textContent = '';
                    passwordMatchText.className = 'form-text';
                } else if (password === confirm) {
                    passwordMatchText.textContent = 'Passwords match';
                    passwordMatchText.className = 'form-text text-success';
                } else {
                    passwordMatchText.textContent = 'Passwords do not match';
                    passwordMatchText.className = 'form-text text-danger';
                }
            }
            
            newPasswordInput.addEventListener('input', function() {
                updatePasswordStrength();
                checkPasswordMatch();
            });
            
            confirmPasswordInput.addEventListener('input', checkPasswordMatch);
            
            // Form validation for password change
            const passwordForm = document.getElementById('passwordForm');
            if (passwordForm) {
                passwordForm.addEventListener('submit', function(e) {
                    const currentPassword = this.querySelector('input[name="current_password"]').value;
                    const newPassword = this.querySelector('input[name="new_password"]').value;
                    const confirmPassword = this.querySelector('input[name="confirm_password"]').value;
                    
                    if (newPassword !== confirmPassword) {
                        e.preventDefault();
                        alert('New passwords do not match!');
                        return;
                    }
                    
                    if (newPassword.length < 8) {
                        e.preventDefault();
                        alert('Password must be at least 8 characters long!');
                        return;
                    }
                    
                    if (!/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/.test(newPassword)) {
                        e.preventDefault();
                        alert('Password must include uppercase, lowercase letters and numbers!');
                        return;
                    }
                    
                    // Show loading state
                    const submitBtn = this.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Changing...';
                    submitBtn.disabled = true;
                    
                    // Revert after 5 seconds if form doesn't submit
                    setTimeout(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }, 5000);
                });
            }
            
            // Handle profile form submission
            const profileForm = document.getElementById('profileForm');
            if (profileForm) {
                profileForm.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
                    submitBtn.disabled = true;
                    
                    setTimeout(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }, 5000);
                });
            }
            
            // Handle tab switching
            const tabTriggers = document.querySelectorAll('[data-bs-toggle="tab"]');
            tabTriggers.forEach(tab => {
                tab.addEventListener('shown.bs.tab', function(event) {
                    // Update URL hash
                    window.location.hash = event.target.getAttribute('data-bs-target');
                });
            });
            
            // Check URL hash on page load
            if (window.location.hash) {
                const tabId = window.location.hash;
                const tab = document.querySelector(`[data-bs-target="${tabId}"]`);
                if (tab) {
                    new bootstrap.Tab(tab).show();
                }
            }
            
            // Add animation to profile cards
            const profileCards = document.querySelectorAll('.profile-card');
            profileCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('fade-in');
            });
            
            // Add hover effect to class history items
            document.querySelectorAll('.class-history-item').forEach(item => {
                item.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateX(5px)';
                });
                
                item.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateX(0)';
                });
            });
            
            // Deactivate account modal validation
            const confirmDeactivateCheckbox = document.getElementById('confirmDeactivate');
            const deactivateBtn = document.querySelector('#deactivateModal .btn-warning');
            
            if (confirmDeactivateCheckbox && deactivateBtn) {
                confirmDeactivateCheckbox.addEventListener('change', function() {
                    deactivateBtn.disabled = !this.checked;
                });
            }
            
            // Delete account modal validation
            const confirmDeleteCheckbox = document.getElementById('confirmDelete');
            const confirmDataLossCheckbox = document.getElementById('confirmDataLoss');
            const deleteBtn = document.querySelector('#deleteAccountModal .btn-danger');
            
            if (confirmDeleteCheckbox && confirmDataLossCheckbox && deleteBtn) {
                function updateDeleteButton() {
                    deleteBtn.disabled = !(confirmDeleteCheckbox.checked && confirmDataLossCheckbox.checked);
                }
                
                confirmDeleteCheckbox.addEventListener('change', updateDeleteButton);
                confirmDataLossCheckbox.addEventListener('change', updateDeleteButton);
            }
            
            // Auto-hide alerts after 5 seconds
            document.querySelectorAll('.alert').forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
        });
        
        // Modal functions
        function exportData() {
            const profile = document.getElementById('exportProfile').checked;
            const bookings = document.getElementById('exportBookings').checked;
            const payments = document.getElementById('exportPayments').checked;
            const progress = document.getElementById('exportProgress').checked;
            
            alert('Export feature coming soon! Selected options:\n' +
                  `Profile: ${profile ? 'Yes' : 'No'}\n` +
                  `Bookings: ${bookings ? 'Yes' : 'No'}\n` +
                  `Payments: ${payments ? 'Yes' : 'No'}\n` +
                  `Progress: ${progress ? 'Yes' : 'No'}`);
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('exportDataModal'));
            modal.hide();
        }
        
        function saveSettings() {
            alert('Settings saved successfully!');
        }
        
        function deactivateAccount() {
            if (confirm('Are you sure you want to deactivate your account?')) {
                alert('Account deactivation feature coming soon!');
                const modal = bootstrap.Modal.getInstance(document.getElementById('deactivateModal'));
                modal.hide();
            }
        }
        
        function deleteAccount() {
            if (confirm('This action is permanent! Are you absolutely sure?')) {
                alert('Account deletion feature coming soon!');
                const modal = bootstrap.Modal.getInstance(document.getElementById('deleteAccountModal'));
                modal.hide();
            }
        }
    </script>
</body>
</html>