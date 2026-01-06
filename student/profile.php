<?php
// student/profile.php - Student Profile Management
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$student_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

// Get user info
$user = [];
$user_stmt = $conn->prepare("SELECT id, name, email, phone, age, emergency_contact, medical_notes, created_at FROM users WHERE id = ?");
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
        
        if (empty($name) || empty($email)) {
            $error_msg = "Name and email are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = "Invalid email format.";
        } else {
            try {
                // Check if email already exists
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
                        emergency_contact = ?, medical_notes = ?
                        WHERE id = ?
                    ");
                    $update_stmt->bind_param('sssissi', $name, $email, $phone, $age, $emergency_contact, $medical_notes, $student_id); 
                    
                    if ($update_stmt->execute()) {
                        $success_msg = "Profile updated successfully!";
                        // Refresh user data
                        $user['name'] = $name;
                        $user['email'] = $email;
                        $user['phone'] = $phone;
                        $user['age'] = $age;
                        $user['emergency_contact'] = $emergency_contact;
                        $user['medical_notes'] = $medical_notes;
                    } else {
                        $error_msg = "Failed to update profile.";
                    }
                }
            } catch (Exception $e) {
                $error_msg = "Database error.";
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
                    $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $update_stmt->bind_param('si', $hashed_password, $student_id);
                    
                    if ($update_stmt->execute()) {
                        $success_msg = "Password changed successfully!";
                    } else {
                        $error_msg = "Failed to change password.";
                    }
                }
            } catch (Exception $e) {
                $error_msg = "Database error.";
            }
        }
    }
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
    // Silently fail - stats are not critical
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
        
        /* Profile Header */
        .profile-header {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid #dee2e6;
        }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            background: #0d6efd;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            font-weight: 600;
            margin: 0 auto 15px;
        }
        
        /* Profile Card */
        .profile-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
        }
        
        /* Tabs */
        .nav-tabs {
            border-bottom: 1px solid #dee2e6;
            margin-bottom: 20px;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #495057;
            font-weight: 500;
            padding: 10px 20px;
            border-bottom: 2px solid transparent;
        }
        
        .nav-tabs .nav-link:hover {
            color: #0d6efd;
        }
        
        .nav-tabs .nav-link.active {
            color: #0d6efd;
            border-bottom: 2px solid #0d6efd;
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
            padding: 10px 12px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .btn-save {
            background: #0d6efd;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 10px 20px;
            font-weight: 500;
            font-size: 14px;
        }
        
        .btn-save:hover:not(:disabled) {
            background: #0a58ca;
        }
        
        /* Info Items */
        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #dee2e6;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            color: #6c757d;
        }
        
        .info-value {
            color: #212529;
            font-weight: 500;
        }
        
        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .stat-box {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 20px;
            font-weight: 600;
            color: #212529;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6c757d;
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
                grid-template-columns: 1fr;
            }
            
            .user-profile {
                width: 100%;
                justify-content: center;
            }
            
            .nav-tabs .nav-link {
                padding: 8px 12px;
                font-size: 14px;
            }
        }
        
        @media (max-width: 576px) {
            .profile-avatar {
                width: 60px;
                height: 60px;
                font-size: 24px;
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
                    <p>Manage your personal information</p>
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

            <!-- Profile Header -->
            <div class="profile-header">
                <div class="text-center mb-4">
                    <div class="profile-avatar">
                        <?= isset($user['name']) ? strtoupper(substr($user['name'], 0, 1)) : 'S' ?>
                    </div>
                    <h3 class="fw-bold mb-1"><?= htmlspecialchars($user['name'] ?? 'Student') ?></h3>
                    <p class="text-muted mb-3">Student</p>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="stat-number"><?= $user_stats['total_bookings'] ?></div>
                        <div class="stat-label">Total Bookings</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number text-success"><?= $user_stats['completed_classes'] ?></div>
                        <div class="stat-label">Completed</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number text-primary"><?= $user_stats['upcoming_classes'] ?></div>
                        <div class="stat-label">Upcoming</div>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-6">
                        <div class="small text-muted">Member Since:</div>
                        <div class="fw-medium"><?= isset($user['created_at']) ? date('M j, Y', strtotime($user['created_at'])) : 'N/A' ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted">Student ID:</div>
                        <div class="fw-medium">#<?= $student_id ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Tabs Navigation -->
            <ul class="nav nav-tabs" id="profileTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="edit-tab" data-bs-toggle="tab" data-bs-target="#edit" type="button">
                        Edit Profile
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="password-tab" data-bs-toggle="tab" data-bs-target="#password" type="button">
                        Change Password
                    </button>
                </li>
            </ul>
            
            <!-- Tab Content -->
            <div class="tab-content" id="profileTabContent">
                <!-- Edit Profile Tab -->
                <div class="tab-pane fade show active" id="edit" role="tabpanel">
                    <div class="profile-card">
                        <h3 class="mb-4">Personal Information</h3>
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
                                           value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Age</label>
                                    <input type="number" class="form-control" name="age" min="1" max="100"
                                           value="<?= $user['age'] ?? '' ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Emergency Contact</label>
                                    <input type="text" class="form-control" name="emergency_contact" 
                                           value="<?= htmlspecialchars($user['emergency_contact'] ?? '') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Medical Notes</label>
                                    <textarea class="form-control" name="medical_notes" rows="3"><?= htmlspecialchars($user['medical_notes'] ?? '') ?></textarea>
                                </div>
                                <div class="col-12 mt-3">
                                    <button type="submit" class="btn-save">
                                        Save Changes
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Account Info -->
                    <div class="profile-card">
                        <h5 class="mb-3">Account Information</h5>
                        <div class="info-item">
                            <span class="info-label">Account Type</span>
                            <span class="info-value">Student</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Account Status</span>
                            <span class="info-value text-success">Active</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Member Since</span>
                            <span class="info-value"><?= isset($user['created_at']) ? date('M j, Y', strtotime($user['created_at'])) : 'N/A' ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Change Password Tab -->
                <div class="tab-pane fade" id="password" role="tabpanel">
                    <div class="profile-card">
                        <h3 class="mb-4">Change Password</h3>
                        <form method="POST" id="passwordForm">
                            <input type="hidden" name="action" value="change_password">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Current Password *</label>
                                    <input type="password" class="form-control" name="current_password" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">New Password *</label>
                                    <input type="password" class="form-control" name="new_password" required>
                                    <div class="form-text">Must be at least 8 characters</div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Confirm New Password *</label>
                                    <input type="password" class="form-control" name="confirm_password" required>
                                </div>
                                <div class="col-12 mt-3">
                                    <button type="submit" class="btn-save">
                                        Change Password
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Form validation for password change
            const passwordForm = document.getElementById('passwordForm');
            if (passwordForm) {
                passwordForm.addEventListener('submit', function(e) {
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
                });
            }
            
            // Auto-hide alerts after 5 seconds
            document.querySelectorAll('.alert').forEach(alert => {
                setTimeout(() => {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                    bsAlert.close();
                }, 5000);
            });
        });
    </script>
</body>
</html>