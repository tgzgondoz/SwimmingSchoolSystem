<?php
// admin/settings.php - Professional Settings Management
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

// Initialize settings array with default values
$settings = [
    'school_name' => 'Elite Swimming Academy',
    'school_email' => 'admin@eliteswim.com',
    'school_phone' => '+263 77 123 4567',
    'school_address' => '123 Swimming Lane, Harare, Zimbabwe',
    'currency' => 'USD',
    'tax_rate' => '14.5',
    'late_fee' => '15.00',
    'logo_url' => '',
    'website' => 'https://eliteswimacademy.com',
    'timezone' => 'Africa/Harare'
];

// Business hours default
$business_hours = [
    'monday' => ['open' => '08:00', 'close' => '18:00'],
    'tuesday' => ['open' => '08:00', 'close' => '18:00'],
    'wednesday' => ['open' => '08:00', 'close' => '18:00'],
    'thursday' => ['open' => '08:00', 'close' => '18:00'],
    'friday' => ['open' => '08:00', 'close' => '18:00'],
    'saturday' => ['open' => '09:00', 'close' => '14:00'],
    'sunday' => ['open' => 'Closed', 'close' => 'Closed']
];

// Notification settings default
$notifications = [
    'email_enabled' => true,
    'sms_enabled' => false,
    'booking_reminders' => true,
    'payment_reminders' => true,
    'class_updates' => true,
    'marketing_emails' => false
];

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_general'])) {
        $school_name = trim($_POST['school_name'] ?? '');
        $school_email = trim($_POST['school_email'] ?? '');
        $school_phone = trim($_POST['school_phone'] ?? '');
        $school_address = trim($_POST['school_address'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $timezone = trim($_POST['timezone'] ?? '');
        
        if (empty($school_name) || empty($school_email)) {
            $error_msg = "School name and email are required fields.";
        } elseif (!filter_var($school_email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = "Please enter a valid email address.";
        } else {
            // Update settings in database (simplified for example)
            $settings['school_name'] = $school_name;
            $settings['school_email'] = $school_email;
            $settings['school_phone'] = $school_phone;
            $settings['school_address'] = $school_address;
            $settings['website'] = $website;
            $settings['timezone'] = $timezone;
            
            $success_msg = "General settings updated successfully!";
            
            // Log the activity
            logActivity($conn, $admin_id, 'Settings Updated', "Updated general settings");
        }
    }
    
    elseif (isset($_POST['update_business_hours'])) {
        $valid = true;
        $new_hours = [];
        
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $open = trim($_POST[$day . '_open'] ?? '');
            $close = trim($_POST[$day . '_close'] ?? '');
            
            if ($open === 'Closed') {
                $new_hours[$day] = ['open' => 'Closed', 'close' => 'Closed'];
            } elseif (!empty($open) && !empty($close)) {
                // Validate time format
                if (preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $open) && 
                    preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $close)) {
                    $new_hours[$day] = ['open' => $open, 'close' => $close];
                } else {
                    $valid = false;
                    $error_msg = "Invalid time format for $day.";
                    break;
                }
            } else {
                $valid = false;
                $error_msg = "Please enter valid hours for $day or select 'Closed'.";
                break;
            }
        }
        
        if ($valid) {
            $business_hours = $new_hours;
            $success_msg = "Business hours updated successfully!";
            
            // Log the activity
            logActivity($conn, $admin_id, 'Settings Updated', "Updated business hours");
        }
    }
    
    elseif (isset($_POST['update_payment_settings'])) {
        $currency = trim($_POST['currency'] ?? '');
        $tax_rate = floatval($_POST['tax_rate'] ?? 0);
        $late_fee = floatval($_POST['late_fee'] ?? 0);
        
        $allowed_currencies = ['USD', 'EUR', 'GBP', 'ZWL', 'ZAR'];
        
        if (!in_array($currency, $allowed_currencies)) {
            $error_msg = "Please select a valid currency.";
        } elseif ($tax_rate < 0 || $tax_rate > 50) {
            $error_msg = "Tax rate must be between 0 and 50%.";
        } elseif ($late_fee < 0) {
            $error_msg = "Late fee cannot be negative.";
        } else {
            $settings['currency'] = $currency;
            $settings['tax_rate'] = $tax_rate;
            $settings['late_fee'] = $late_fee;
            
            $success_msg = "Payment settings updated successfully!";
            
            // Log the activity
            logActivity($conn, $admin_id, 'Settings Updated', "Updated payment settings");
        }
    }
    
    elseif (isset($_POST['update_notifications'])) {
        $notifications['email_enabled'] = isset($_POST['email_enabled']);
        $notifications['sms_enabled'] = isset($_POST['sms_enabled']);
        $notifications['booking_reminders'] = isset($_POST['booking_reminders']);
        $notifications['payment_reminders'] = isset($_POST['payment_reminders']);
        $notifications['class_updates'] = isset($_POST['class_updates']);
        $notifications['marketing_emails'] = isset($_POST['marketing_emails']);
        
        $success_msg = "Notification settings updated successfully!";
        
        // Log the activity
        logActivity($conn, $admin_id, 'Settings Updated', "Updated notification settings");
    }
    
    elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_msg = "All password fields are required.";
        } elseif ($new_password !== $confirm_password) {
            $error_msg = "New passwords do not match.";
        } elseif (strlen($new_password) < 8) {
            $error_msg = "Password must be at least 8 characters long.";
        } else {
            // Verify current password
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $admin_id);
            $stmt->execute();
            $stmt->bind_result($hashed_password);
            $stmt->fetch();
            $stmt->close();
            
            if (password_verify($current_password, $hashed_password)) {
                // Update password
                $new_hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                $update_stmt->bind_param("si", $new_hashed_password, $admin_id);
                
                if ($update_stmt->execute()) {
                    $success_msg = "Password changed successfully!";
                    
                    // Log the activity
                    logActivity($conn, $admin_id, 'Security', "Changed password");
                } else {
                    $error_msg = "Failed to update password.";
                }
                $update_stmt->close();
            } else {
                $error_msg = "Current password is incorrect.";
            }
        }
    }
    
    // Store messages in session for redirect
    if ($success_msg) {
        $_SESSION['success_msg'] = $success_msg;
    }
    if ($error_msg) {
        $_SESSION['error_msg'] = $error_msg;
    }
    
    // Redirect to prevent form resubmission
    header("Location: settings.php");
    exit();
}

// Load messages from session
if (isset($_SESSION['success_msg'])) {
    $success_msg = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}
if (isset($_SESSION['error_msg'])) {
    $error_msg = $_SESSION['error_msg'];
    unset($_SESSION['error_msg']);
}

// Timezone options
$timezones = [
    'Africa/Harare' => 'Harare (GMT+2)',
    'Africa/Johannesburg' => 'Johannesburg (GMT+2)',
    'Africa/Lagos' => 'Lagos (GMT+1)',
    'Europe/London' => 'London (GMT)',
    'America/New_York' => 'New York (GMT-5)',
    'Asia/Dubai' => 'Dubai (GMT+4)',
    'Asia/Singapore' => 'Singapore (GMT+8)',
    'Australia/Sydney' => 'Sydney (GMT+11)'
];

// Currency options
$currencies = [
    'USD' => 'US Dollar ($)',
    'EUR' => 'Euro (€)',
    'GBP' => 'British Pound (£)',
    'ZWL' => 'Zimbabwean Dollar (Z$)',
    'ZAR' => 'South African Rand (R)'
];

// Get current date and time
$current_date = date('l, F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | Elite Swimming Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --success: #16a34a;
            --warning: #d97706;
            --danger: #dc2626;
            --info: #0891b2;
            --light: #f8fafc;
            --dark: #1e293b;
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
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--gray-50);
            min-height: 100vh;
            color: var(--gray-800);
            line-height: 1.5;
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar - Same as students.php and payments.php */
        .sidebar {
            width: 240px;
            background-color: white;
            border-right: 1px solid var(--gray-200);
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 1000;
            padding: 20px 0;
        }
        
        .logo-area {
            padding: 0 20px 20px;
            border-bottom: 1px solid var(--gray-200);
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
            width: 36px;
            height: 36px;
            background-color: var(--primary);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }
        
        .logo-text h3 {
            font-weight: 600;
            font-size: 18px;
            margin: 0;
            color: var(--gray-900);
        }
        
        .logo-text span {
            font-size: 12px;
            color: var(--gray-500);
        }
        
        .nav-menu {
            padding: 0 15px;
        }
        
        .nav-item {
            margin-bottom: 4px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 8px;
            color: var(--gray-600);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .nav-link:hover {
            background-color: var(--gray-100);
            color: var(--primary);
        }
        
        .nav-link.active {
            background-color: var(--primary);
            color: white;
        }
        
        .nav-link i {
            width: 18px;
            text-align: center;
            font-size: 16px;
        }
        
        .logout-section {
            padding: 20px;
            position: absolute;
            bottom: 0;
            width: 100%;
            border-top: 1px solid var(--gray-200);
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 240px;
            padding: 24px;
        }
        
        /* Header */
        .header {
            background-color: white;
            border-radius: 8px;
            padding: 20px 24px;
            margin-bottom: 24px;
            border: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left h1 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--gray-900);
        }
        
        .header-left p {
            color: var(--gray-600);
            margin: 0;
            font-size: 14px;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            background-color: var(--gray-50);
            padding: 8px 16px;
            border-radius: 6px;
        }
        
        .user-avatar {
            width: 36px;
            height: 36px;
            background-color: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 500;
            font-size: 14px;
        }
        
        .user-info h5 {
            font-weight: 500;
            margin: 0;
            font-size: 14px;
        }
        
        .user-info p {
            color: var(--gray-500);
            font-size: 12px;
            margin: 0;
        }
        
        /* Alerts */
        .alert-custom {
            border-radius: 8px;
            border: 1px solid;
            padding: 16px 20px;
            margin-bottom: 24px;
        }
        
        /* Settings Grid */
        .settings-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
        }
        
        /* Settings Card */
        .settings-card {
            background-color: white;
            border-radius: 8px;
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }
        
        .settings-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .settings-header i {
            color: var(--primary);
            font-size: 18px;
        }
        
        .settings-header h3 {
            font-size: 16px;
            font-weight: 600;
            margin: 0;
            color: var(--gray-900);
        }
        
        .settings-body {
            padding: 20px;
        }
        
        /* Form Styles */
        .form-label {
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--gray-800);
            font-size: 14px;
            display: block;
        }
        
        .form-label.required::after {
            content: ' *';
            color: var(--danger);
        }
        
        .form-control, .form-select {
            border-radius: 6px;
            border: 1px solid var(--gray-300);
            padding: 8px 12px;
            font-size: 14px;
            transition: all 0.2s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        /* Business Hours Grid */
        .hours-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }
        
        .hours-row {
            display: grid;
            grid-template-columns: 120px 1fr 1fr;
            gap: 12px;
            align-items: center;
        }
        
        .hours-row .day-label {
            font-weight: 500;
            color: var(--gray-700);
            font-size: 14px;
        }
        
        .hours-inputs {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .hours-separator {
            color: var(--gray-500);
            font-weight: 500;
        }
        
        .closed-badge {
            background-color: var(--gray-100);
            color: var(--gray-600);
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            display: inline-block;
        }
        
        /* Switch Toggle */
        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--gray-300);
            transition: .4s;
            border-radius: 24px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: var(--success);
        }
        
        input:checked + .slider:before {
            transform: translateX(20px);
        }
        
        /* Notification Items */
        .notification-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .notification-item:last-child {
            border-bottom: none;
        }
        
        .notification-info h6 {
            font-weight: 500;
            margin: 0;
            font-size: 14px;
            color: var(--gray-800);
        }
        
        .notification-info p {
            font-size: 12px;
            color: var(--gray-600);
            margin: 4px 0 0 0;
        }
        
        /* System Status */
        .system-status {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .status-item {
            background-color: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: 6px;
            padding: 12px;
        }
        
        .status-label {
            font-size: 12px;
            color: var(--gray-600);
            margin-bottom: 4px;
        }
        
        .status-value {
            font-weight: 500;
            font-size: 14px;
            color: var(--gray-800);
        }
        
        .status-good {
            color: var(--success);
        }
        
        .status-warning {
            color: var(--warning);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            margin-top: 20px;
        }
        
        /* Password Strength Meter */
        .password-strength {
            margin-top: 8px;
        }
        
        .strength-bar {
            height: 4px;
            background-color: var(--gray-200);
            border-radius: 2px;
            overflow: hidden;
            margin-bottom: 4px;
        }
        
        .strength-fill {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease;
        }
        
        .strength-weak .strength-fill {
            background-color: var(--danger);
            width: 33%;
        }
        
        .strength-medium .strength-fill {
            background-color: var(--warning);
            width: 66%;
        }
        
        .strength-strong .strength-fill {
            background-color: var(--success);
            width: 100%;
        }
        
        .strength-text {
            font-size: 12px;
            color: var(--gray-600);
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                width: 64px;
            }
            
            .main-content {
                margin-left: 64px;
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
                padding: 16px;
            }
            
            .header {
                flex-direction: column;
                gap: 12px;
                text-align: center;
            }
            
            .hours-row {
                grid-template-columns: 1fr;
                gap: 8px;
            }
            
            .hours-inputs {
                justify-content: flex-start;
            }
            
            .system-status {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 576px) {
            .action-buttons {
                flex-direction: column;
            }
            
            .action-buttons .btn {
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
                        <i class="bi bi-droplet-half"></i>
                    </div>
                    <div class="logo-text">
                        <h3>Elite Swimming Academy</h3>
                        <span>Admin Portal</span>
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
                    <a href="settings.php" class="nav-link active">
                        <i class="bi bi-gear"></i>
                        <span class="nav-text">Settings</span>
                    </a>
                </div>
            </nav>
            
            <div class="logout-section">
                <form method="post" action="logout.php" style="margin:0;">
                    <button type="submit" name="confirm_logout" value="1" class="nav-link btn" style="background:none;border:none;width:100%;text-align:left;padding:10px 12px;">
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
                    <h1>Settings</h1>
                    <p>System Configuration • <?= $current_date ?></p>
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
            
            <!-- System Status -->
            <div class="settings-card">
                <div class="settings-header">
                    <i class="bi bi-graph-up"></i>
                    <h3>System Status</h3>
                </div>
                <div class="settings-body">
                    <div class="system-status">
                        <div class="status-item">
                            <div class="status-label">PHP Version</div>
                            <div class="status-value status-good"><?= phpversion() ?></div>
                        </div>
                        <div class="status-item">
                            <div class="status-label">Database</div>
                            <div class="status-value status-good">Connected</div>
                        </div>
                        <div class="status-item">
                            <div class="status-label">Disk Space</div>
                            <div class="status-value status-good">85% Free</div>
                        </div>
                        <div class="status-item">
                            <div class="status-label">Last Backup</div>
                            <div class="status-value status-warning">2 days ago</div>
                        </div>
                    </div>
                    <div class="action-buttons">
                        <button class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-database me-2"></i> Backup Now
                        </button>
                        <button class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-arrow-clockwise me-2"></i> Clear Cache
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="settings-grid">
                <!-- General Settings -->
                <div class="settings-card">
                    <div class="settings-header">
                        <i class="bi bi-building"></i>
                        <h3>General Settings</h3>
                    </div>
                    <div class="settings-body">
                        <form method="POST">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label required">School Name</label>
                                    <input type="text" class="form-control" name="school_name" value="<?= htmlspecialchars($settings['school_name']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label required">Contact Email</label>
                                    <input type="email" class="form-control" name="school_email" value="<?= htmlspecialchars($settings['school_email']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Phone Number</label>
                                    <input type="tel" class="form-control" name="school_phone" value="<?= htmlspecialchars($settings['school_phone']) ?>" placeholder="+263 77 123 4567">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Website</label>
                                    <input type="url" class="form-control" name="website" value="<?= htmlspecialchars($settings['website']) ?>" placeholder="https://example.com">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Timezone</label>
                                    <select class="form-select" name="timezone">
                                        <?php foreach ($timezones as $value => $label): ?>
                                            <option value="<?= $value ?>" <?= $settings['timezone'] === $value ? 'selected' : '' ?>>
                                                <?= $label ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">School Address</label>
                                    <textarea class="form-control" name="school_address" rows="2"><?= htmlspecialchars($settings['school_address']) ?></textarea>
                                </div>
                                <div class="col-12">
                                    <button type="submit" name="update_general" class="btn btn-primary">
                                        <i class="bi bi-check-circle me-2"></i> Save General Settings
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Business Hours -->
                <div class="settings-card">
                    <div class="settings-header">
                        <i class="bi bi-clock"></i>
                        <h3>Business Hours</h3>
                    </div>
                    <div class="settings-body">
                        <form method="POST">
                            <div class="hours-grid">
                                <?php foreach ($business_hours as $day => $hours): ?>
                                    <div class="hours-row">
                                        <div class="day-label"><?= ucfirst($day) ?></div>
                                        <div class="hours-inputs">
                                            <?php if ($hours['open'] === 'Closed'): ?>
                                                <span class="closed-badge">Closed</span>
                                            <?php else: ?>
                                                <input type="time" class="form-control" name="<?= $day ?>_open" value="<?= $hours['open'] ?>">
                                                <span class="hours-separator">to</span>
                                                <input type="time" class="form-control" name="<?= $day ?>_close" value="<?= $hours['close'] ?>">
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <select class="form-select" onchange="toggleDayClosed(this, '<?= $day ?>')">
                                                <option value="open" <?= $hours['open'] !== 'Closed' ? 'selected' : '' ?>>Open</option>
                                                <option value="closed" <?= $hours['open'] === 'Closed' ? 'selected' : '' ?>>Closed</option>
                                            </select>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="action-buttons">
                                <button type="submit" name="update_business_hours" class="btn btn-primary">
                                    <i class="bi bi-check-circle me-2"></i> Save Business Hours
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="setDefaultHours()">
                                    <i class="bi bi-arrow-clockwise me-2"></i> Reset to Default
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Payment Settings -->
                <div class="settings-card">
                    <div class="settings-header">
                        <i class="bi bi-credit-card"></i>
                        <h3>Payment Settings</h3>
                    </div>
                    <div class="settings-body">
                        <form method="POST">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label required">Currency</label>
                                    <select class="form-select" name="currency" required>
                                        <?php foreach ($currencies as $code => $name): ?>
                                            <option value="<?= $code ?>" <?= $settings['currency'] === $code ? 'selected' : '' ?>>
                                                <?= $name ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label required">Tax Rate (%)</label>
                                    <input type="number" class="form-control" name="tax_rate" value="<?= $settings['tax_rate'] ?>" step="0.1" min="0" max="50" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label required">Late Fee</label>
                                    <div class="input-group">
                                        <span class="input-group-text">$</span>
                                        <input type="number" class="form-control" name="late_fee" value="<?= $settings['late_fee'] ?>" step="0.01" min="0" required>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button type="submit" name="update_payment_settings" class="btn btn-primary">
                                        <i class="bi bi-check-circle me-2"></i> Save Payment Settings
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Notification Settings -->
                <div class="settings-card">
                    <div class="settings-header">
                        <i class="bi bi-bell"></i>
                        <h3>Notification Settings</h3>
                    </div>
                    <div class="settings-body">
                        <form method="POST">
                            <div class="notification-item">
                                <div class="notification-info">
                                    <h6>Email Notifications</h6>
                                    <p>Receive system notifications via email</p>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="email_enabled" <?= $notifications['email_enabled'] ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            
                            <div class="notification-item">
                                <div class="notification-info">
                                    <h6>SMS Notifications</h6>
                                    <p>Receive urgent notifications via SMS</p>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="sms_enabled" <?= $notifications['sms_enabled'] ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            
                            <div class="notification-item">
                                <div class="notification-info">
                                    <h6>Booking Reminders</h6>
                                    <p>Send reminders for upcoming classes</p>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="booking_reminders" <?= $notifications['booking_reminders'] ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            
                            <div class="notification-item">
                                <div class="notification-info">
                                    <h6>Payment Reminders</h6>
                                    <p>Send payment due reminders</p>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="payment_reminders" <?= $notifications['payment_reminders'] ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            
                            <div class="notification-item">
                                <div class="notification-info">
                                    <h6>Class Updates</h6>
                                    <p>Notify about class schedule changes</p>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="class_updates" <?= $notifications['class_updates'] ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            
                            <div class="notification-item">
                                <div class="notification-info">
                                    <h6>Marketing Emails</h6>
                                    <p>Receive promotional emails and offers</p>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="marketing_emails" <?= $notifications['marketing_emails'] ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            
                            <div class="action-buttons">
                                <button type="submit" name="update_notifications" class="btn btn-primary">
                                    <i class="bi bi-check-circle me-2"></i> Save Notification Settings
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="selectAllNotifications(true)">
                                    <i class="bi bi-check-all me-2"></i> Select All
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="selectAllNotifications(false)">
                                    <i class="bi bi-x-circle me-2"></i> Deselect All
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Account Security -->
                <div class="settings-card">
                    <div class="settings-header">
                        <i class="bi bi-shield-lock"></i>
                        <h3>Account Security</h3>
                    </div>
                    <div class="settings-body">
                        <form method="POST" id="passwordForm">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label required">Current Password</label>
                                    <input type="password" class="form-control" name="current_password" id="current_password" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label required">New Password</label>
                                    <input type="password" class="form-control" name="new_password" id="new_password" required oninput="checkPasswordStrength(this.value)">
                                    <div class="password-strength" id="passwordStrength">
                                        <div class="strength-bar">
                                            <div class="strength-fill"></div>
                                        </div>
                                        <div class="strength-text" id="strengthText">Password strength: None</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label required">Confirm New Password</label>
                                    <input type="password" class="form-control" name="confirm_password" id="confirm_password" required oninput="checkPasswordMatch()">
                                    <div id="passwordMatch" class="mt-2" style="font-size: 12px;"></div>
                                </div>
                                <div class="col-12">
                                    <div class="alert alert-info" style="font-size: 14px;">
                                        <i class="bi bi-info-circle me-2"></i>
                                        Password must be at least 8 characters long and contain uppercase, lowercase, and numbers.
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button type="submit" name="change_password" class="btn btn-primary">
                                        <i class="bi bi-key me-2"></i> Change Password
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
            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                    bsAlert.close();
                }, 5000);
            });
        });
        
        // Toggle day closed/open
        function toggleDayClosed(select, day) {
            const row = select.closest('.hours-row');
            const inputs = row.querySelector('.hours-inputs');
            
            if (select.value === 'closed') {
                inputs.innerHTML = '<span class="closed-badge">Closed</span>';
            } else {
                inputs.innerHTML = `
                    <input type="time" class="form-control" name="${day}_open" value="09:00">
                    <span class="hours-separator">to</span>
                    <input type="time" class="form-control" name="${day}_close" value="17:00">
                `;
            }
        }
        
        // Set default business hours
        function setDefaultHours() {
            if (confirm('Reset all business hours to default?')) {
                const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                
                days.forEach(day => {
                    const row = document.querySelector(`.hours-row .day-label:contains("${day.charAt(0).toUpperCase() + day.slice(1)}")`).closest('.hours-row');
                    const select = row.querySelector('select');
                    
                    if (day === 'sunday') {
                        select.value = 'closed';
                        row.querySelector('.hours-inputs').innerHTML = '<span class="closed-badge">Closed</span>';
                    } else if (day === 'saturday') {
                        select.value = 'open';
                        row.querySelector('.hours-inputs').innerHTML = `
                            <input type="time" class="form-control" name="${day}_open" value="09:00">
                            <span class="hours-separator">to</span>
                            <input type="time" class="form-control" name="${day}_close" value="14:00">
                        `;
                    } else {
                        select.value = 'open';
                        row.querySelector('.hours-inputs').innerHTML = `
                            <input type="time" class="form-control" name="${day}_open" value="08:00">
                            <span class="hours-separator">to</span>
                            <input type="time" class="form-control" name="${day}_close" value="18:00">
                        `;
                    }
                });
            }
        }
        
        // Select/deselect all notifications
        function selectAllNotifications(select) {
            const checkboxes = document.querySelectorAll('input[type="checkbox"][name$="enabled"], input[type="checkbox"][name$="reminders"], input[type="checkbox"][name$="updates"], input[type="checkbox"][name$="emails"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = select;
            });
        }
        
        // Check password strength
        function checkPasswordStrength(password) {
            const strengthBar = document.getElementById('passwordStrength');
            const strengthText = document.getElementById('strengthText');
            
            // Reset
            strengthBar.className = 'password-strength';
            strengthBar.querySelector('.strength-fill').style.width = '0%';
            
            if (!password) {
                strengthText.textContent = 'Password strength: None';
                return;
            }
            
            let strength = 0;
            
            // Length check
            if (password.length >= 8) strength += 1;
            if (password.length >= 12) strength += 1;
            
            // Character variety checks
            if (/[a-z]/.test(password)) strength += 1;
            if (/[A-Z]/.test(password)) strength += 1;
            if (/[0-9]/.test(password)) strength += 1;
            if (/[^A-Za-z0-9]/.test(password)) strength += 1;
            
            // Update UI
            if (strength <= 2) {
                strengthBar.classList.add('strength-weak');
                strengthText.textContent = 'Password strength: Weak';
            } else if (strength <= 4) {
                strengthBar.classList.add('strength-medium');
                strengthText.textContent = 'Password strength: Medium';
            } else {
                strengthBar.classList.add('strength-strong');
                strengthText.textContent = 'Password strength: Strong';
            }
        }
        
        // Check password match
        function checkPasswordMatch() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchDiv = document.getElementById('passwordMatch');
            
            if (!newPassword || !confirmPassword) {
                matchDiv.textContent = '';
                return;
            }
            
            if (newPassword === confirmPassword) {
                matchDiv.innerHTML = '<span style="color: var(--success);"><i class="bi bi-check-circle-fill me-1"></i>Passwords match</span>';
            } else {
                matchDiv.innerHTML = '<span style="color: var(--danger);"><i class="bi bi-x-circle-fill me-1"></i>Passwords do not match</span>';
            }
        }
        
        // Form validation for password change
        const passwordForm = document.getElementById('passwordForm');
        if (passwordForm) {
            passwordForm.addEventListener('submit', function(e) {
                const currentPassword = this.querySelector('#current_password').value;
                const newPassword = this.querySelector('#new_password').value;
                const confirmPassword = this.querySelector('#confirm_password').value;
                
                if (!currentPassword) {
                    e.preventDefault();
                    alert('Please enter your current password.');
                    return false;
                }
                
                if (!newPassword) {
                    e.preventDefault();
                    alert('Please enter a new password.');
                    return false;
                }
                
                if (newPassword.length < 8) {
                    e.preventDefault();
                    alert('Password must be at least 8 characters long.');
                    return false;
                }
                
                if (!/[A-Z]/.test(newPassword)) {
                    e.preventDefault();
                    alert('Password must contain at least one uppercase letter.');
                    return false;
                }
                
                if (!/[a-z]/.test(newPassword)) {
                    e.preventDefault();
                    alert('Password must contain at least one lowercase letter.');
                    return false;
                }
                
                if (!/[0-9]/.test(newPassword)) {
                    e.preventDefault();
                    alert('Password must contain at least one number.');
                    return false;
                }
                
                if (newPassword !== confirmPassword) {
                    e.preventDefault();
                    alert('New passwords do not match!');
                    return false;
                }
                
                return true;
            });
        }
    </script>
</body>
</html>