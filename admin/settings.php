<?php
// admin/settings.php
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

requireRole('admin');
$user = getCurrentUser($conn);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_school_info'])) {
        $settings_to_update = [
            'school_name' => $_POST['school_name'],
            'school_email' => $_POST['school_email'],
            'school_phone' => $_POST['school_phone'],
            'school_address' => $_POST['school_address']
        ];
        
        foreach ($settings_to_update as $key => $value) {
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                                   ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->bind_param('sss', $key, $value, $value);
            $stmt->execute();
        }
        
        $success_message = "School information updated successfully!";
    }
    
    if (isset($_POST['update_business_hours'])) {
        // Handle business hours update
        foreach ($_POST['hours'] as $day => $hours) {
            $value = json_encode($hours);
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                                   ON DUPLICATE KEY UPDATE setting_value = ?");
            $key = 'business_hours_' . $day;
            $stmt->bind_param('sss', $key, $value, $value);
            $stmt->execute();
        }
        $success_message = "Business hours updated successfully!";
    }
    
    if (isset($_POST['update_payment_settings'])) {
        $settings_to_update = [
            'currency' => $_POST['currency'],
            'tax_rate' => $_POST['tax_rate'],
            'late_fee' => $_POST['late_fee']
        ];
        
        foreach ($settings_to_update as $key => $value) {
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                                   ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->bind_param('sss', $key, $value, $value);
            $stmt->execute();
        }
        $success_message = "Payment settings updated successfully!";
    }
    
    if (isset($_POST['update_notifications'])) {
        foreach ($_POST['notifications'] as $key => $value) {
            $enabled = isset($value) ? '1' : '0';
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                                   ON DUPLICATE KEY UPDATE setting_value = ?");
            $setting_key = 'notification_' . $key;
            $stmt->bind_param('sss', $setting_key, $enabled, $enabled);
            $stmt->execute();
        }
        $success_message = "Notification settings updated successfully!";
    }
    
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if ($new_password === $confirm_password) {
            // Verify current password and update
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param('i', $_SESSION['user_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_data = $result->fetch_assoc();
            
            if (password_verify($current_password, $user_data['password'])) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param('si', $hashed_password, $_SESSION['user_id']);
                $stmt->execute();
                $success_message = "Password changed successfully!";
            } else {
                $error_message = "Current password is incorrect!";
            }
        } else {
            $error_message = "New passwords do not match!";
        }
    }
}

// Load settings from database
$settings = [
    'school_name' => getSetting($conn, 'school_name', 'AquaFlow Swimming School'),
    'school_email' => getSetting($conn, 'school_email', 'admin@aquaflow.com'),
    'school_phone' => getSetting($conn, 'school_phone', '+1 (555) 123-4567'),
    'school_address' => getSetting($conn, 'school_address', '123 Swimming Lane, Water City, WC 12345'),
    'payment_settings' => [
        'currency' => getSetting($conn, 'currency', 'USD'),
        'tax_rate' => getSetting($conn, 'tax_rate', '8.5'),
        'late_fee' => getSetting($conn, 'late_fee', '25.00')
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
    $hours_json = getSetting($conn, 'business_hours_' . $day, '["09:00", "17:00"]');
    $business_hours[$day] = json_decode($hours_json, true);
}
// Special hours for weekend
$business_hours['saturday'] = json_decode(getSetting($conn, 'business_hours_saturday', '["10:00", "14:00"]'), true);
$business_hours['sunday'] = json_decode(getSetting($conn, 'business_hours_sunday', '["Closed", "Closed"]'), true);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Settings - Admin Dashboard</title>
  
  <link href="../css/style.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
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
    
    .settings-section {
      margin-bottom: 32px;
    }
    
    .settings-section:last-child {
      margin-bottom: 0;
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
  </style>
</head>
<body>
  <div class="sidebar"><?php include 'components/sidebar.php'; ?></div>

  <div class="main-content">
    <div class="header"><?php include 'components/header.php'; ?></div>

    <div class="dashboard-container">
      <!-- Alert Messages -->
      <?php if(isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="bi bi-check-circle me-2"></i><?= $success_message ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      
      <?php if(isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="bi bi-exclamation-circle me-2"></i><?= $error_message ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <!-- Header Section -->
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h2 class="fw-bold">Settings</h2>
          <p class="text-muted">Manage your school settings and preferences</p>
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
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">School Name</label>
                <input type="text" class="form-control" name="school_name" value="<?= htmlspecialchars($settings['school_name']) ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Contact Email</label>
                <input type="email" class="form-control" name="school_email" value="<?= htmlspecialchars($settings['school_email']) ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Phone Number</label>
                <input type="tel" class="form-control" name="school_phone" value="<?= htmlspecialchars($settings['school_phone']) ?>">
              </div>
              <div class="col-12">
                <label class="form-label">Address</label>
                <textarea class="form-control" name="school_address" rows="2"><?= htmlspecialchars($settings['school_address']) ?></textarea>
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
            <?php foreach($business_hours as $day => $hours): ?>
              <div class="business-hours-row">
                <div class="day-label"><?= ucfirst($day) ?></div>
                <?php if($hours[0] === 'Closed'): ?>
                  <div class="closed-badge">Closed</div>
                <?php else: ?>
                  <div class="time-inputs">
                    <input type="time" class="form-control" name="hours[<?= $day ?>][open]" value="<?= $hours[0] ?>" style="width: 120px;">
                    <span class="time-separator">to</span>
                    <input type="time" class="form-control" name="hours[<?= $day ?>][close]" value="<?= $hours[1] ?>" style="width: 120px;">
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
                <input type="number" class="form-control" name="tax_rate" step="0.1" min="0" max="50" value="<?= $settings['payment_settings']['tax_rate'] ?>" required>
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
                    <?= ucwords(str_replace('_', ' ', $key)) ?>
                  </div>
                  <div class="notification-desc">
                    <?= $description ?>
                  </div>
                </div>
                <div class="notification-toggle">
                  <label class="toggle-switch">
                    <input type="checkbox" name="notifications[<?= $key ?>]" <?= $settings['notifications'][$key] ? 'checked' : '' ?>>
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
          <form method="POST">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Current Password</label>
                <input type="password" class="form-control" name="current_password" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">New Password</label>
                <input type="password" class="form-control" name="new_password" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Confirm New Password</label>
                <input type="password" class="form-control" name="confirm_password" required>
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
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../js/main.js"></script>
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
    });

    function setTheme(theme) {
      const body = document.body;
      const themeOptions = document.querySelectorAll('.theme-option');
      
      // Update active state
      themeOptions.forEach(opt => opt.classList.remove('active'));
      document.querySelector(`[data-theme="${theme}"]`).classList.add('active');
      
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

    // Form validation
    document.addEventListener('DOMContentLoaded', function() {
      const passwordForm = document.querySelector('form[action=""]');
      if (passwordForm) {
        passwordForm.addEventListener('submit', function(e) {
          const newPassword = this.querySelector('input[name="new_password"]').value;
          const confirmPassword = this.querySelector('input[name="confirm_password"]').value;
          
          if (newPassword !== confirmPassword) {
            e.preventDefault();
            alert('New passwords do not match!');
          }
        });
      }
    });
  </script>
</body>
</html>