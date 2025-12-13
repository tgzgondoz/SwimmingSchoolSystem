[file name]: student/profile.php
[file content begin]
<?php
// student/profile.php - Student Profile Management
session_start();
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

requireRole('student');
$user = getCurrentUser($conn);
$student_id = $_SESSION['user_id'];

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $age = intval($_POST['age']);
        $emergency_contact = trim($_POST['emergency_contact']);
        $medical_notes = trim($_POST['medical_notes']);
        $address = trim($_POST['address']);
        
        // Check if email already exists (excluding current user)
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check_stmt->bind_param('si', $email, $student_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error_message = "Email address already exists.";
        } else {
            $stmt = $conn->prepare("
                UPDATE users SET 
                name = ?, email = ?, phone = ?, age = ?, 
                emergency_contact = ?, medical_notes = ?, address = ?
                WHERE id = ? AND role = 'student'
            ");
            $stmt->bind_param('sssisssi', $name, $email, $phone, $age, $emergency_contact, $medical_notes, $address, $student_id);
            
            if ($stmt->execute()) {
                $success_message = "Profile updated successfully!";
                // Refresh user data
                $user = getCurrentUser($conn);
            } else {
                $error_message = "Failed to update profile: " . $conn->error;
            }
        }
    }
    
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if ($new_password !== $confirm_password) {
            $error_message = "New passwords do not match!";
        } else {
            // Verify current password
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param('i', $student_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_data = $result->fetch_assoc();
            
            if (password_verify($current_password, $user_data['password'])) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param('si', $hashed_password, $student_id);
                $stmt->execute();
                
                if ($stmt->affected_rows > 0) {
                    $success_message = "Password changed successfully!";
                } else {
                    $error_message = "Failed to change password.";
                }
            } else {
                $error_message = "Current password is incorrect!";
            }
        }
    }
}

// Get user's class history
$class_history = $conn->query("
    SELECT c.*, i.name as instructor_name, b.status as booking_status,
           b.created_at as booking_date
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    LEFT JOIN instructors i ON c.instructor_id = i.id
    WHERE b.user_id = $student_id
    ORDER BY c.start_time DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>My Profile - Student Dashboard</title>
  
  <link href="../css/style.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    .dashboard-container {
      padding: 20px;
      max-width: 1400px;
      margin: 0 auto;
    }

    .profile-header {
      background: white;
      border-radius: 12px;
      padding: 30px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
      margin-bottom: 30px;
    }

    .profile-avatar {
      width: 120px;
      height: 120px;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 48px;
      font-weight: 600;
      margin: 0 auto 20px;
    }

    .profile-card {
      background: white;
      border-radius: 12px;
      padding: 25px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
      margin-bottom: 24px;
      height: 100%;
    }

    .nav-tabs {
      border-bottom: 2px solid #e5e7eb;
      margin-bottom: 20px;
    }

    .nav-tabs .nav-link {
      border: none;
      color: #6b7280;
      font-weight: 500;
      padding: 10px 20px;
      margin-right: 10px;
      border-radius: 8px 8px 0 0;
    }

    .nav-tabs .nav-link:hover {
      color: #3b82f6;
      background: #f8fafc;
    }

    .nav-tabs .nav-link.active {
      color: #3b82f6;
      background: white;
      border-bottom: 3px solid #3b82f6;
    }

    .form-label {
      font-weight: 500;
      color: #374151;
      margin-bottom: 8px;
    }

    .form-control {
      border-radius: 8px;
      border: 1px solid #e5e7eb;
      padding: 10px;
    }

    .form-control:focus {
      border-color: #667eea;
      box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .btn-save {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      border: none;
      border-radius: 8px;
      padding: 12px 30px;
      font-weight: 500;
      transition: all 0.3s;
    }

    .btn-save:hover {
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
    }

    .info-item {
      display: flex;
      justify-content: space-between;
      padding: 12px 0;
      border-bottom: 1px solid #e5e7eb;
    }

    .info-item:last-child {
      border-bottom: none;
    }

    .info-label {
      color: #6b7280;
      font-weight: 500;
    }

    .info-value {
      color: #1f2937;
      font-weight: 500;
    }

    .badge-status {
      padding: 5px 10px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 500;
    }

    .status-completed {
      background: #dcfce7;
      color: #166534;
    }

    .status-upcoming {
      background: #dbeafe;
      color: #1d4ed8;
    }

    .status-cancelled {
      background: #fee2e2;
      color: #dc2626;
    }

    .password-strength {
      height: 4px;
      border-radius: 2px;
      background: #e5e7eb;
      margin-top: 5px;
      overflow: hidden;
    }

    .password-strength-bar {
      height: 100%;
      width: 0%;
      transition: width 0.3s;
    }

    .strength-weak {
      background: #ef4444;
    }

    .strength-medium {
      background: #f59e0b;
    }

    .strength-strong {
      background: #10b981;
    }

    .class-history-item {
      padding: 15px;
      border-radius: 8px;
      background: #f8fafc;
      margin-bottom: 10px;
      border-left: 4px solid #667eea;
    }

    .class-history-item:hover {
      background: #f1f5f9;
    }

    .empty-state {
      text-align: center;
      padding: 40px 20px;
      color: #6b7280;
    }

    .empty-state i {
      font-size: 48px;
      opacity: 0.5;
      margin-bottom: 15px;
    }

    .avatar-upload {
      position: relative;
      width: 120px;
      margin: 0 auto 20px;
    }

    .avatar-upload label {
      position: absolute;
      bottom: 0;
      right: 0;
      background: white;
      border-radius: 50%;
      width: 36px;
      height: 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      cursor: pointer;
      transition: all 0.3s;
    }

    .avatar-upload label:hover {
      transform: scale(1.1);
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

      <!-- Profile Header -->
      <div class="profile-header">
        <div class="row align-items-center">
          <div class="col-md-3 text-center">
            <div class="profile-avatar">
              <?= strtoupper(substr($user['name'], 0, 1)) ?>
            </div>
            <h5 class="fw-bold mb-1"><?= htmlspecialchars($user['name']) ?></h5>
            <p class="text-muted mb-0">Student</p>
          </div>
          <div class="col-md-9">
            <div class="row">
              <div class="col-md-4">
                <div class="text-center">
                  <div class="h4 mb-1"><?= count($class_history) ?></div>
                  <div class="text-muted">Classes Taken</div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="text-center">
                  <div class="h4 mb-1">
                    <?= date('M j, Y', strtotime($user['created_at'])) ?>
                  </div>
                  <div class="text-muted">Member Since</div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="text-center">
                  <div class="h4 mb-1">
                    <?= !empty($user['last_login']) ? date('M j, Y', strtotime($user['last_login'])) : 'Never' ?>
                  </div>
                  <div class="text-muted">Last Login</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Tabs Navigation -->
      <ul class="nav nav-tabs" id="profileTab" role="tablist">
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
      <div class="tab-content" id="profileTabContent">
        <!-- Edit Profile Tab -->
        <div class="tab-pane fade show active" id="edit">
          <div class="row">
            <div class="col-lg-8">
              <div class="profile-card">
                <h4 class="mb-4">Personal Information</h4>
                <form method="POST">
                  <div class="row g-3">
                    <div class="col-md-6">
                      <label class="form-label">Full Name</label>
                      <input type="text" class="form-control" name="name" 
                             value="<?= htmlspecialchars($user['name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Email Address</label>
                      <input type="email" class="form-control" name="email" 
                             value="<?= htmlspecialchars($user['email']) ?>" required>
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
                      <label class="form-label">Address</label>
                      <textarea class="form-control" name="address" rows="2"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
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
                    <div class="col-12">
                      <button type="submit" name="update_profile" class="btn btn-save">
                        <i class="bi bi-check-circle me-2"></i>Save Changes
                      </button>
                    </div>
                  </div>
                </form>
              </div>
            </div>
            <div class="col-lg-4">
              <div class="profile-card">
                <h5 class="mb-3">Account Information</h5>
                <div class="info-item">
                  <span class="info-label">Account Type</span>
                  <span class="info-value">Student</span>
                </div>
                <div class="info-item">
                  <span class="info-label">Member Since</span>
                  <span class="info-value"><?= date('M j, Y', strtotime($user['created_at'])) ?></span>
                </div>
                <div class="info-item">
                  <span class="info-label">Account Status</span>
                  <span class="info-value text-success">Active</span>
                </div>
                <div class="info-item">
                  <span class="info-label">Student ID</span>
                  <span class="info-value">#<?= $user['id'] ?></span>
                </div>
              </div>

              <div class="profile-card mt-4">
                <h5 class="mb-3">Emergency Information</h5>
                <?php if(!empty($user['emergency_contact'])): ?>
                  <p class="mb-2"><?= htmlspecialchars($user['emergency_contact']) ?></p>
                  <small class="text-muted">Contact in case of emergency</small>
                <?php else: ?>
                  <p class="text-muted mb-0">No emergency contact provided</p>
                <?php endif; ?>
                
                <?php if(!empty($user['medical_notes'])): ?>
                  <hr>
                  <h6 class="mb-2">Medical Notes</h6>
                  <p class="small text-muted mb-0"><?= htmlspecialchars($user['medical_notes']) ?></p>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <!-- Change Password Tab -->
        <div class="tab-pane fade" id="password">
          <div class="row justify-content-center">
            <div class="col-lg-6">
              <div class="profile-card">
                <h4 class="mb-4">Change Password</h4>
                <form method="POST">
                  <div class="row g-3">
                    <div class="col-12">
                      <label class="form-label">Current Password</label>
                      <input type="password" class="form-control" name="current_password" required>
                    </div>
                    <div class="col-12">
                      <label class="form-label">New Password</label>
                      <input type="password" class="form-control" name="new_password" id="newPassword" required>
                      <div class="password-strength">
                        <div class="password-strength-bar" id="passwordStrength"></div>
                      </div>
                    </div>
                    <div class="col-12">
                      <label class="form-label">Confirm New Password</label>
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
                      <button type="submit" name="change_password" class="btn btn-save" id="changePasswordBtn">
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
        <div class="tab-pane fade" id="history">
          <div class="profile-card">
            <h4 class="mb-4">Class History</h4>
            <?php if(empty($class_history)): ?>
              <div class="empty-state">
                <i class="bi bi-calendar-x"></i>
                <h5>No Class History</h5>
                <p>You haven't attended any classes yet.</p>
                <a href="classes.php" class="btn btn-primary">Browse Classes</a>
              </div>
            <?php else: ?>
              <div class="row">
                <?php foreach($class_history as $class): ?>
                  <div class="col-md-6 mb-3">
                    <div class="class-history-item">
                      <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="mb-0"><?= htmlspecialchars($class['title']) ?></h6>
                        <?php
                        $status_class = '';
                        if ($class['booking_status'] === 'confirmed' && strtotime($class['start_time']) > time()) {
                          $status_class = 'status-upcoming';
                          $status_text = 'Upcoming';
                        } elseif ($class['booking_status'] === 'confirmed' && strtotime($class['start_time']) <= time()) {
                          $status_class = 'status-completed';
                          $status_text = 'Completed';
                        } else {
                          $status_class = 'status-cancelled';
                          $status_text = 'Cancelled';
                        }
                        ?>
                        <span class="badge-status <?= $status_class ?>"><?= $status_text ?></span>
                      </div>
                      <div class="text-muted small mb-2">
                        <i class="bi bi-person me-1"></i><?= htmlspecialchars($class['instructor_name']) ?>
                      </div>
                      <div class="text-muted small mb-2">
                        <i class="bi bi-calendar me-1"></i>
                        <?= date('M j, Y', strtotime($class['start_time'])) ?>
                      </div>
                      <div class="text-muted small">
                        <i class="bi bi-clock me-1"></i>
                        <?= date('g:i A', strtotime($class['start_time'])) ?> - <?= date('g:i A', strtotime($class['end_time'])) ?>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Settings Tab -->
        <div class="tab-pane fade" id="settings">
          <div class="row">
            <div class="col-lg-6">
              <div class="profile-card">
                <h4 class="mb-4">Notification Settings</h4>
                <div class="form-check form-switch mb-3">
                  <input class="form-check-input" type="checkbox" id="emailNotifications" checked>
                  <label class="form-check-label" for="emailNotifications">
                    Email notifications
                  </label>
                </div>
                <div class="form-check form-switch mb-3">
                  <input class="form-check-input" type="checkbox" id="smsNotifications">
                  <label class="form-check-label" for="smsNotifications">
                    SMS notifications
                  </label>
                </div>
                <div class="form-check form-switch mb-3">
                  <input class="form-check-input" type="checkbox" id="bookingReminders" checked>
                  <label class="form-check-label" for="bookingReminders">
                    Booking reminders
                  </label>
                </div>
                <div class="form-check form-switch mb-3">
                  <input class="form-check-input" type="checkbox" id="paymentReminders" checked>
                  <label class="form-check-label" for="paymentReminders">
                    Payment reminders
                  </label>
                </div>
                <button class="btn btn-save mt-3">
                  <i class="bi bi-save me-2"></i>Save Settings
                </button>
              </div>
            </div>
            <div class="col-lg-6">
              <div class="profile-card">
                <h4 class="mb-4">Privacy Settings</h4>
                <div class="form-check form-switch mb-3">
                  <input class="form-check-input" type="checkbox" id="showProfile" checked>
                  <label class="form-check-label" for="showProfile">
                    Show profile to other students
                  </label>
                </div>
                <div class="form-check form-switch mb-3">
                  <input class="form-check-input" type="checkbox" id="showAttendance">
                  <label class="form-check-label" for="showAttendance">
                    Show attendance statistics
                  </label>
                </div>
                <div class="alert alert-warning">
                  <i class="bi bi-exclamation-triangle me-2"></i>
                  Changing privacy settings may affect your experience in the community.
                </div>
              </div>

              <div class="profile-card mt-4">
                <h4 class="mb-4">Account Actions</h4>
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
    </div>
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
          <button type="button" class="btn btn-primary">Export Data</button>
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
      const changePasswordBtn = document.getElementById('changePasswordBtn');
      
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
        let text = '';
        
        if (password.length === 0) {
          width = 0;
          text = '';
        } else if (strength <= 2) {
          width = 33;
          color = 'strength-weak';
          text = 'Weak password';
        } else if (strength <= 4) {
          width = 66;
          color = 'strength-medium';
          text = 'Medium password';
        } else {
          width = 100;
          color = 'strength-strong';
          text = 'Strong password';
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
      const passwordForm = document.querySelector('form[method="POST"]');
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
        });
      }
      
      // Initialize tab functionality
      const profileTab = new bootstrap.Tab(document.getElementById('edit-tab'));
      
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
    });
  </script>
</body>
</html>
[file content end]