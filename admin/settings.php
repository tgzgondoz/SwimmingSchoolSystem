<?php
// admin/settings.php
session_start();
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

// Check database connection
if (!$conn) {
    die("Database connection error.");
}

requireRole('admin');
$user = getCurrentUser($conn);

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Initialize messages
$success_message = $error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error_message = "Security token invalid. Please try again.";
    } else {
        if (isset($_POST['update_school_info'])) {
            $school_name = filter_input(INPUT_POST, 'school_name', FILTER_SANITIZE_SPECIAL_CHARS);
            $school_email = filter_input(INPUT_POST, 'school_email', FILTER_SANITIZE_EMAIL);
            $school_phone = filter_input(INPUT_POST, 'school_phone', FILTER_SANITIZE_SPECIAL_CHARS);
            $school_address = filter_input(INPUT_POST, 'school_address', FILTER_SANITIZE_SPECIAL_CHARS);
            
            // Validate email
            if (!filter_var($school_email, FILTER_VALIDATE_EMAIL)) {
                $error_message = "Invalid email address.";
            } else {
                $settings_to_update = [
                    'school_name' => $school_name,
                    'school_email' => $school_email,
                    'school_phone' => $school_phone,
                    'school_address' => $school_address
                ];
                
                foreach ($settings_to_update as $key => $value) {
                    $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                                           ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
                    $stmt->bind_param('sss', $key, $value, $value);
                    $stmt->execute();
                    $stmt->close();
                }
                
                $success_message = "School information updated successfully!";
            }
        }
        
        if (isset($_POST['update_business_hours'])) {
            // Handle business hours update with validation
            $valid_hours = true;
            $hours_data = [];
            
            if (isset($_POST['hours']) && is_array($_POST['hours'])) {
                $allowed_days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                
                foreach ($_POST['hours'] as $day => $hours) {
                    // Validate day
                    if (!in_array($day, $allowed_days)) {
                        $valid_hours = false;
                        $error_message = "Invalid day specified.";
                        break;
                    }
                    
                    // Validate time format
                    if (isset($hours['open']) && $hours['open'] !== 'Closed') {
                        if (!preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $hours['open'])) {
                            $valid_hours = false;
                            $error_message = "Invalid time format for opening.";
                            break;
                        }
                    }
                    
                    if (isset($hours['close']) && $hours['close'] !== 'Closed') {
                        if (!preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $hours['close'])) {
                            $valid_hours = false;
                            $error_message = "Invalid time format for closing.";
                            break;
                        }
                    }
                    
                    $hours_data[$day] = [
                        'open' => $hours['open'] ?? '09:00',
                        'close' => $hours['close'] ?? '17:00'
                    ];
                }
                
                if ($valid_hours) {
                    foreach ($hours_data as $day => $hours) {
                        $value = json_encode($hours);
                        $key = 'business_hours_' . $day;
                        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                                               ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
                        $stmt->bind_param('sss', $key, $value, $value);
                        $stmt->execute();
                        $stmt->close();
                    }
                    $success_message = "Business hours updated successfully!";
                }
            }
        }
        
        if (isset($_POST['update_payment_settings'])) {
            $currency = filter_input(INPUT_POST, 'currency', FILTER_SANITIZE_SPECIAL_CHARS);
            $tax_rate = filter_input(INPUT_POST, 'tax_rate', FILTER_VALIDATE_FLOAT);
            $late_fee = filter_input(INPUT_POST, 'late_fee', FILTER_VALIDATE_FLOAT);
            
            // Validate inputs
            $allowed_currencies = ['USD', 'EUR', 'GBP', 'ZWL'];
            if (!in_array($currency, $allowed_currencies)) {
                $error_message = "Invalid currency selected.";
            } elseif ($tax_rate === false || $tax_rate < 0 || $tax_rate > 100) {
                $error_message = "Invalid tax rate. Must be between 0 and 100.";
            } elseif ($late_fee === false || $late_fee < 0) {
                $error_message = "Invalid late fee amount.";
            } else {
                $settings_to_update = [
                    'currency' => $currency,
                    'tax_rate' => $tax_rate,
                    'late_fee' => $late_fee
                ];
                
                foreach ($settings_to_update as $key => $value) {
                    $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                                           ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
                    $stmt->bind_param('sss', $key, $value, $value);
                    $stmt->execute();
                    $stmt->close();
                }
                $success_message = "Payment settings updated successfully!";
            }
        }
        
        if (isset($_POST['update_notifications'])) {
            // Handle notification settings
            $allowed_notifications = ['email_notifications', 'sms_notifications', 'booking_reminders', 'payment_reminders', 'system_updates'];
            
            foreach ($allowed_notifications as $notification) {
                $enabled = isset($_POST['notifications'][$notification]) ? '1' : '0';
                $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                                       ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
                $setting_key = 'notification_' . $notification;
                $stmt->bind_param('sss', $setting_key, $enabled, $enabled);
                $stmt->execute();
                $stmt->close();
            }
            $success_message = "Notification settings updated successfully!";
        }
        
        if (isset($_POST['change_password'])) {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            // Validate password strength
            if (strlen($new_password) < 8) {
                $error_message = "Password must be at least 8 characters long.";
            } elseif (!preg_match('/[A-Z]/', $new_password)) {
                $error_message = "Password must contain at least one uppercase letter.";
            } elseif (!preg_match('/[a-z]/', $new_password)) {
                $error_message = "Password must contain at least one lowercase letter.";
            } elseif (!preg_match('/[0-9]/', $new_password)) {
                $error_message = "Password must contain at least one number.";
            } elseif ($new_password !== $confirm_password) {
                $error_message = "New passwords do not match!";
            } else {
                // Verify current password and update
                $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
                $user_id = $_SESSION['user_id'] ?? 0;
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $user_data = $result->fetch_assoc();
                $stmt->close();
                
                if ($user_data && password_verify($current_password, $user_data['password'])) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->bind_param('si', $hashed_password, $user_id);
                    $stmt->execute();
                    if ($stmt->affected_rows > 0) {
                        $success_message = "Password changed successfully!";
                        // Clear sensitive data from form
                        unset($_POST['current_password']);
                        unset($_POST['new_password']);
                        unset($_POST['confirm_password']);
                    } else {
                        $error_message = "Failed to update password.";
                    }
                    $stmt->close();
                } else {
                    $error_message = "Current password is incorrect!";
                }
            }
        }
    }
}

// Function to get setting with fallback
function getSetting($conn, $key, $default = '') {
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    if (!$stmt) {
        return $default;
    }
    
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $row = $result->fetch_assoc()) {
        $value = $row['setting_value'];
        $stmt->close();
        return $value;
    }
    
    $stmt->close();
    return $default;
}

// Load settings from database
$settings = [
    'school_name' => htmlspecialchars(getSetting($conn, 'school_name', 'AquaFlow Swimming School')),
    'school_email' => htmlspecialchars(getSetting($conn, 'school_email', 'admin@aquaflow.com')),
    'school_phone' => htmlspecialchars(getSetting($conn, 'school_phone', '+1 (555) 123-4567')),
    'school_address' => htmlspecialchars(getSetting($conn, 'school_address', '123 Swimming Lane, Water City, WC 12345')),
    'payment_settings' => [
        'currency' => htmlspecialchars(getSetting($conn, 'currency', 'USD')),
        'tax_rate' => floatval(getSetting($conn, 'tax_rate', '8.5')),
        'late_fee' => floatval(getSetting($conn, 'late_fee', '25.00'))
    ],
    'notifications' => [
        'email_notifications' => getSetting($conn, 'notification_email_notifications', '1') === '1',
        'sms_notifications' => getSetting($conn, 'notification_sms_notifications', '0') === '1',
        'booking_reminders' => getSetting($conn, 'notification_booking_reminders', '1') === '1',
        'payment_reminders' => getSetting($conn, 'notification_payment_reminders', '1') === '1',
        'system_updates' => getSetting($conn, 'notification_system_updates', '1') === '1'
    ]
];

// Load business hours
$business_hours = [];
$days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
foreach ($days as $day) {
    $hours_json = getSetting($conn, 'business_hours_' . $day, '{"open":"09:00","close":"17:00"}');
    $hours = json_decode($hours_json, true);
    if ($hours && is_array($hours)) {
        $business_hours[$day] = [
            'open' => htmlspecialchars($hours['open'] ?? '09:00'),
            'close' => htmlspecialchars($hours['close'] ?? '17:00')
        ];
    } else {
        $business_hours[$day] = ['open' => '09:00', 'close' => '17:00'];
    }
}

// Set special hours for weekend if not already set
if (!isset($business_hours['saturday'])) {
    $business_hours['saturday'] = ['open' => '10:00', 'close' => '14:00'];
}
if (!isset($business_hours['sunday'])) {
    $business_hours['sunday'] = ['open' => 'Closed', 'close' => 'Closed'];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Settings - Admin Dashboard</title>
  
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  
  <!-- Custom CSS -->
  <link href="../css/style.css" rel="stylesheet">
  
  <style>
    .dashboard-container {
      padding: 20px;
      max-width: 1200px;
      margin: 0 auto;
    }
    
    .card {
      border-radius: 12px;
      box-shadow: 0 4px 12px rgba(15,23,42,0.08);
      border: none;
      margin-bottom: 24px;
    }
    
    .card-header {
      background: white;
      border-bottom: 1px solid #f1f5f9;
      padding: 20px 24px;
      border-radius: 12px 12px 0 0 !important;
    }
    
    .card-title {
      font-weight: 600;
      color: #1f2937;
      margin: 0;
      font-size: 18px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .card-body {
      padding: 24px;
    }
    
    .form-label {
      font-weight: 500;
      color: #374151;
      margin-bottom: 8px;
    }
    
    .form-control, .form-select {
      border-radius: 8px;
      border: 1px solid #d1d5db;
      padding: 10px 12px;
      font-size: 14px;
    }
    
    .btn {
      border-radius: 8px;
      font-weight: 500;
      padding: 10px 20px;
    }
    
    .business-hours-row {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 0;
      border-bottom: 1px solid #f1f5f9;
    }
    
    .business-hours-row:last-child {
      border-bottom: none;
    }
    
    .day-label {
      min-width: 100px;
      font-weight: 500;
      color: #374151;
    }
    
    .time-inputs {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .time-separator {
      color: #6b7280;
      font-weight: 500;
    }
    
    .closed-badge {
      background: #f3f4f6;
      color: #6b7280;
      padding: 6px 12px;
      border-radius: 6px;
      font-size: 14px;
      font-weight: 500;
    }
    
    .toggle-switch {
      position: relative;
      display: inline-block;
      width: 50px;
      height: 24px;
    }
    
    .toggle-switch input {
      opacity: 0;
      width: 0;
      height: 0;
    }
    
    .toggle-slider {
      position: absolute;
      cursor: pointer;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background-color: #d1d5db;
      transition: .4s;
      border-radius: 24px;
    }
    
    .toggle-slider:before {
      position: absolute;
      content: "";
      height: 16px;
      width: 16px;
      left: 4px;
      bottom: 4px;
      background-color: white;
      transition: .4s;
      border-radius: 50%;
    }
    
    input:checked + .toggle-slider {
      background-color: #10b981;
    }
    
    input:checked + .toggle-slider:before {
      transform: translateX(26px);
    }
    
    .notification-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 0;
      border-bottom: 1px solid #f1f5f9;
    }
    
    .notification-item:last-child {
      border-bottom: none;
    }
    
    .notification-info {
      flex: 1;
    }
    
    .notification-title {
      font-weight: 500;
      color: #374151;
      margin-bottom: 2px;
    }
    
    .notification-desc {
      font-size: 13px;
      color: #6b7280;
    }
    
    .theme-selector {
      display: flex;
      gap: 12px;
      margin-top: 16px;
    }
    
    .theme-option {
      flex: 1;
      text-align: center;
      padding: 16px;
      border: 2px solid #e5e7eb;
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.3s ease;
    }
    
    .theme-option:hover {
      border-color: #3b82f6;
    }
    
    .theme-option.active {
      border-color: #3b82f6;
      background-color: #eff6ff;
    }
    
    .theme-preview {
      width: 60px;
      height: 60px;
      border-radius: 8px;
      margin: 0 auto 8px;
      border: 2px solid #e5e7eb;
    }
    
    .theme-light {
      background: linear-gradient(135deg, #ffffff 50%, #f3f4f6 50%);
    }
    
    .theme-dark {
      background: linear-gradient(135deg, #1f2937 50%, #374151 50%);
    }
    
    .theme-auto {
      background: linear-gradient(135deg, #ffffff 50%, #1f2937 50%);
      position: relative;
    }
    
    .theme-auto::after {
      content: "A";
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      font-weight: bold;
      color: #6b7280;
    }
    
    /* Sidebar and Main Content Layout */
    .sidebar {
      width: 260px;
      background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
      color: white;
      position: fixed;
      height: 100vh;
      z-index: 1000;
      transition: all 0.3s ease;
      box-shadow: 2px 0 20px rgba(0, 0, 0, 0.1);
    }
    
    .main-content {
      flex: 1;
      margin-left: 260px;
      transition: all 0.3s ease;
    }
    
    /* Topbar styling to match dashboard */
    .topbar {
      background: white;
      padding: 1rem 2rem;
      border-bottom: 1px solid #e2e8f0;
      position: sticky;
      top: 0;
      z-index: 100;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }
    
    .page-title h1 {
      font-size: 1.75rem;
      font-weight: 700;
      color: #1e293b;
      margin: 0;
    }
    
    .page-title p {
      color: #64748b;
      margin: 0.25rem 0 0 0;
      font-size: 0.875rem;
    }
    
    .topbar-actions {
      display: flex;
      align-items: center;
      gap: 1rem;
    }
    
    .user-profile {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.5rem 1rem;
      background: #f1f5f9;
      border-radius: 50px;
      cursor: pointer;
      transition: all 0.3s ease;
    }
    
    .user-profile:hover {
      background: #e2e8f0;
    }
    
    .user-avatar {
      width: 36px;
      height: 36px;
      background: linear-gradient(135deg, #4361ee, #3a0ca3);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: 600;
      font-size: 0.875rem;
    }
    
    @media (max-width: 768px) {
      .sidebar {
        width: 70px;
      }
      
      .main-content {
        margin-left: 70px;
      }
      
      .business-hours-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
      }
      
      .day-label {
        min-width: auto;
      }
      
      .theme-selector {
        flex-direction: column;
      }
    }
  </style>
</head>
<body>
  <div class="dashboard-wrapper">
    <!-- Sidebar -->
    <aside class="sidebar">
      <div class="sidebar-header">
        <a href="index.php" class="sidebar-brand">
          <i class="bi bi-droplet-half"></i>
          <span>AquaFlow Pro</span>
        </a>
      </div>
      
      <nav class="sidebar-nav">
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
          <a href="analytics.php" class="nav-link">
            <i class="bi bi-graph-up"></i>
            <span class="nav-text">Analytics</span>
          </a>
        </div>
        <div class="nav-item">
          <a href="settings.php" class="nav-link active">
            <i class="bi bi-gear"></i>
            <span class="nav-text">Settings</span>
          </a>
        </div>
        <div class="nav-item mt-4">
          <a href="logout.php" class="nav-link text-danger">
            <i class="bi bi-box-arrow-right"></i>
            <span class="nav-text">Logout</span>
          </a>
        </div>
      </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
      <!-- Topbar -->
      <header class="topbar">
        <div class="page-title">
          <h1>Settings</h1>
          <p>Welcome back, <?= htmlspecialchars($user['name'] ?? 'Admin') ?>! Manage your school settings and preferences.</p>
        </div>
        
        <div class="topbar-actions">
          <div class="dropdown">
            <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="dropdown">
              <i class="bi bi-plus-circle me-1"></i> Quick Action
            </button>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item" href="classes.php?action=new"><i class="bi bi-calendar-plus me-2"></i> Add New Class</a></li>
              <li><a class="dropdown-item" href="students.php?action=new"><i class="bi bi-person-plus me-2"></i> Add Student</a></li>
              <li><a class="dropdown-item" href="instructors.php?action=new"><i class="bi bi-person-badge me-2"></i> Add Instructor</a></li>
            </ul>
          </div>
          
          <div class="user-profile">
            <div class="user-avatar">
              <?= strtoupper(substr($user['name'] ?? 'A', 0, 1)) ?>
            </div>
            <div class="user-info">
              <div class="fw-medium"><?= htmlspecialchars($user['name'] ?? 'Admin') ?></div>
              <small>Administrator</small>
            </div>
            <i class="bi bi-chevron-down"></i>
          </div>
        </div>
      </header>

      <div class="dashboard-container">
        <!-- Alert Messages -->
        <?php if($success_message): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>
        
        <?php if($error_message): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <!-- Header Section -->
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h2 class="fw-bold">System Settings</h2>
            <p class="text-muted">Configure school settings and preferences</p>
          </div>
        </div>

        <!-- School Information -->
        <div class="card">
          <div class="card-header">
            <h5 class="card-title">
              <i class="bi bi-building"></i>
              School Information
            </h5>
          </div>
          <div class="card-body">
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">School Name</label>
                  <input type="text" class="form-control" name="school_name" value="<?= $settings['school_name'] ?>" required maxlength="100">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Contact Email</label>
                  <input type="email" class="form-control" name="school_email" value="<?= $settings['school_email'] ?>" required maxlength="100">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Phone Number</label>
                  <input type="tel" class="form-control" name="school_phone" value="<?= $settings['school_phone'] ?>" maxlength="20">
                </div>
                <div class="col-12">
                  <label class="form-label">Address</label>
                  <textarea class="form-control" name="school_address" rows="2" maxlength="255"><?= $settings['school_address'] ?></textarea>
                </div>
                <div class="col-12">
                  <button type="submit" name="update_school_info" class="btn btn-primary">
                    <i class="bi bi-check-lg me-2"></i>Save Changes
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>

        <!-- Business Hours -->
        <div class="card">
          <div class="card-header">
            <h5 class="card-title">
              <i class="bi bi-clock"></i>
              Business Hours
            </h5>
          </div>
          <div class="card-body">
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
              <?php foreach($business_hours as $day => $hours): ?>
                <div class="business-hours-row">
                  <div class="day-label"><?= ucfirst(htmlspecialchars($day)) ?></div>
                  <?php if($hours['open'] === 'Closed'): ?>
                    <div class="closed-badge">Closed</div>
                  <?php else: ?>
                    <div class="time-inputs">
                      <input type="time" class="form-control" name="hours[<?= htmlspecialchars($day) ?>][open]" value="<?= htmlspecialchars($hours['open']) ?>" style="width: 120px;">
                      <span class="time-separator">to</span>
                      <input type="time" class="form-control" name="hours[<?= htmlspecialchars($day) ?>][close]" value="<?= htmlspecialchars($hours['close']) ?>" style="width: 120px;">
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
              <div class="mt-4">
                <button type="submit" name="update_business_hours" class="btn btn-primary">
                  <i class="bi bi-check-lg me-2"></i>Update Hours
                </button>
              </div>
            </form>
          </div>
        </div>

        <!-- Payment Settings -->
        <div class="card">
          <div class="card-header">
            <h5 class="card-title">
              <i class="bi bi-credit-card"></i>
              Payment Settings
            </h5>
          </div>
          <div class="card-body">
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">Currency</label>
                  <select class="form-select" name="currency" required>
                    <option value="USD" <?= $settings['payment_settings']['currency'] === 'USD' ? 'selected' : '' ?>>USD ($)</option>
                    <option value="EUR" <?= $settings['payment_settings']['currency'] === 'EUR' ? 'selected' : '' ?>>EUR (€)</option>
                    <option value="GBP" <?= $settings['payment_settings']['currency'] === 'GBP' ? 'selected' : '' ?>>GBP (£)</option>
                    <option value="ZWL" <?= $settings['payment_settings']['currency'] === 'ZWL' ? 'selected' : '' ?>>ZWL (Z$)</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Tax Rate (%)</label>
                  <input type="number" class="form-control" name="tax_rate" step="0.1" min="0" max="100" value="<?= $settings['payment_settings']['tax_rate'] ?>" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Late Fee Amount</label>
                  <div class="input-group">
                    <span class="input-group-text">$</span>
                    <input type="number" class="form-control" name="late_fee" step="0.01" min="0" value="<?= $settings['payment_settings']['late_fee'] ?>" required>
                  </div>
                </div>
                <div class="col-12">
                  <button type="submit" name="update_payment_settings" class="btn btn-primary">
                    <i class="bi bi-check-lg me-2"></i>Update Payment Settings
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>

        <!-- Notification Settings -->
        <div class="card">
          <div class="card-header">
            <h5 class="card-title">
              <i class="bi bi-bell"></i>
              Notification Settings
            </h5>
          </div>
          <div class="card-body">
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
              <?php 
              $notification_types = [
                'email_notifications' => 'Receive notifications via email',
                'sms_notifications' => 'Receive notifications via SMS',
                'booking_reminders' => 'Get reminders for upcoming bookings',
                'payment_reminders' => 'Receive payment due reminders',
                'system_updates' => 'Get notified about system updates'
              ];
              
              foreach($notification_types as $key => $description): ?>
                <div class="notification-item">
                  <div class="notification-info">
                    <div class="notification-title">
                      <?= htmlspecialchars(ucwords(str_replace('_', ' ', $key))) ?>
                    </div>
                    <div class="notification-desc">
                      <?= htmlspecialchars($description) ?>
                    </div>
                  </div>
                  <div class="notification-toggle">
                    <label class="toggle-switch">
                      <input type="checkbox" name="notifications[<?= htmlspecialchars($key) ?>]" <?= $settings['notifications'][$key] ? 'checked' : '' ?>>
                      <span class="toggle-slider"></span>
                    </label>
                  </div>
                </div>
              <?php endforeach; ?>
              <div class="mt-4">
                <button type="submit" name="update_notifications" class="btn btn-primary">
                  <i class="bi bi-check-lg me-2"></i>Save Notification Settings
                </button>
              </div>
            </form>
          </div>
        </div>

        <!-- Appearance Settings -->
        <div class="card">
          <div class="card-header">
            <h5 class="card-title">
              <i class="bi bi-palette"></i>
              Appearance
            </h5>
          </div>
          <div class="card-body">
            <div class="theme-selector">
              <div class="theme-option active" data-theme="light" onclick="setTheme('light')">
                <div class="theme-preview theme-light"></div>
                <div>Light</div>
              </div>
              <div class="theme-option" data-theme="dark" onclick="setTheme('dark')">
                <div class="theme-preview theme-dark"></div>
                <div>Dark</div>
              </div>
              <div class="theme-option" data-theme="auto" onclick="setTheme('auto')">
                <div class="theme-preview theme-auto"></div>
                <div>Auto</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Account Security -->
        <div class="card">
          <div class="card-header">
            <h5 class="card-title">
              <i class="bi bi-shield-lock"></i>
              Account Security
            </h5>
          </div>
          <div class="card-body">
            <form method="POST" id="passwordForm">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Current Password</label>
                  <input type="password" class="form-control" name="current_password" required autocomplete="current-password">
                </div>
                <div class="col-md-6">
                  <label class="form-label">New Password</label>
                  <input type="password" class="form-control" name="new_password" required autocomplete="new-password">
                  <small class="form-text text-muted">Password must be at least 8 characters with uppercase, lowercase, and numbers.</small>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Confirm New Password</label>
                  <input type="password" class="form-control" name="confirm_password" required autocomplete="new-password">
                </div>
                <div class="col-12">
                  <button type="submit" name="change_password" class="btn btn-primary">
                    <i class="bi bi-key me-2"></i>Change Password
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
    // Theme switching functionality
    document.addEventListener('DOMContentLoaded', function() {
      const themeOptions = document.querySelectorAll('.theme-option');
      const body = document.body;
      
      // Load saved theme from localStorage
      const savedTheme = localStorage.getItem('theme') || 'light';
      setTheme(savedTheme);
      
      // Set active theme option
      themeOptions.forEach(option => {
        if (option.dataset.theme === savedTheme) {
          option.classList.add('active');
        }
      });
      
      // Listen for system theme changes
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
        if (localStorage.getItem('theme') === 'auto') {
          setTheme('auto');
        }
      });
      
      // Password form validation
      const passwordForm = document.getElementById('passwordForm');
      if (passwordForm) {
        passwordForm.addEventListener('submit', function(e) {
          const currentPassword = this.querySelector('input[name="current_password"]').value;
          const newPassword = this.querySelector('input[name="new_password"]').value;
          const confirmPassword = this.querySelector('input[name="confirm_password"]').value;
          
          // Password validation
          if (newPassword.length < 8) {
            e.preventDefault();
            alert('Password must be at least 8 characters long.');
            return;
          }
          
          if (!/[A-Z]/.test(newPassword)) {
            e.preventDefault();
            alert('Password must contain at least one uppercase letter.');
            return;
          }
          
          if (!/[a-z]/.test(newPassword)) {
            e.preventDefault();
            alert('Password must contain at least one lowercase letter.');
            return;
          }
          
          if (!/[0-9]/.test(newPassword)) {
            e.preventDefault();
            alert('Password must contain at least one number.');
            return;
          }
          
          if (newPassword !== confirmPassword) {
            e.preventDefault();
            alert('New passwords do not match!');
            return;
          }
          
          // Clear sensitive fields after validation
          setTimeout(() => {
            this.querySelector('input[name="current_password"]').value = '';
            this.querySelector('input[name="new_password"]').value = '';
            this.querySelector('input[name="confirm_password"]').value = '';
          }, 100);
        });
      }
    });

    function setTheme(theme) {
      const body = document.body;
      const themeOptions = document.querySelectorAll('.theme-option');
      
      // Update active state
      themeOptions.forEach(opt => opt.classList.remove('active'));
      const activeOption = document.querySelector(`[data-theme="${theme}"]`);
      if (activeOption) {
        activeOption.classList.add('active');
      }
      
      if (theme === 'auto') {
        // Check system preference
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
          body.classList.add('dark-mode');
        } else {
          body.classList.remove('dark-mode');
        }
      } else if (theme === 'dark') {
        body.classList.add('dark-mode');
      } else {
        body.classList.remove('dark-mode');
      }
      
      // Save to localStorage
      localStorage.setItem('theme', theme);
    }

    // Mobile sidebar toggle
    const sidebarToggle = document.createElement('button');
    sidebarToggle.className = 'btn btn-primary btn-sm d-md-none position-fixed bottom-0 start-0 m-3';
    sidebarToggle.innerHTML = '<i class="bi bi-list"></i>';
    sidebarToggle.style.zIndex = '1050';
    sidebarToggle.onclick = function() {
      document.querySelector('.sidebar').classList.toggle('active');
      document.querySelector('.main-content').classList.toggle('active');
    };
    document.body.appendChild(sidebarToggle);
  </script>
</body>
</html>