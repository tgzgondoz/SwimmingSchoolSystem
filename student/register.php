[file name]: student/register.php
[file content begin]
<?php
// student/register.php - Student Registration Page
session_start();
include __DIR__ . '/../inc/db.php';

$error = '';
$success = '';

// Handle registration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $phone = trim($_POST['phone'] ?? '');
    $age = intval($_POST['age'] ?? 0);
    $emergency_contact = trim($_POST['emergency_contact'] ?? '');
    $medical_notes = trim($_POST['medical_notes'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    // Validation
    if (empty($name) || empty($email) || empty($password)) {
        $error = 'Please fill in all required fields.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($age < 4 || $age > 100) {
        $error = 'Please enter a valid age (4-100).';
    } else {
        // Check if email already exists
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check_stmt->bind_param('s', $email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error = 'Email address already registered. Please use a different email or login.';
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $role = 'student';
            
            // Insert new student
            $stmt = $conn->prepare("
                INSERT INTO users (name, email, password, role, phone, age, emergency_contact, medical_notes, address, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param('sssssisss', $name, $email, $hashed_password, $role, $phone, $age, $emergency_contact, $medical_notes, $address);
            
            if ($stmt->execute()) {
                $student_id = $stmt->insert_id;
                
                // Auto-login after registration
                $_SESSION['user_id'] = $student_id;
                $_SESSION['role'] = $role;
                $_SESSION['user_name'] = $name;
                
                // Send welcome email (in production)
                // sendWelcomeEmail($email, $name);
                
                // Redirect to student dashboard
                header('Location: index.php?welcome=1');
                exit();
            } else {
                $error = 'Registration failed. Please try again. Error: ' . $conn->error;
            }
        }
    }
}

// Get school settings for registration info
$school_name = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'school_name'")->fetch_assoc()['setting_value'] ?? 'AquaFlow Swimming School';
$school_email = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'school_email'")->fetch_assoc()['setting_value'] ?? 'info@aquaflow.com';
$school_phone = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'school_phone'")->fetch_assoc()['setting_value'] ?? '+263 77 123 4567';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Student Registration - <?= htmlspecialchars($school_name) ?></title>
  
  <link href="../css/style.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body {
      background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      padding: 20px;
    }
    
    .register-container {
      background: white;
      border-radius: 15px;
      box-shadow: 0 20px 40px rgba(0,0,0,0.1);
      width: 100%;
      max-width: 800px;
      overflow: hidden;
    }
    
    .register-header {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      padding: 30px;
      text-align: center;
    }
    
    .register-body {
      padding: 30px;
    }
    
    .form-section {
      margin-bottom: 30px;
      padding-bottom: 20px;
      border-bottom: 1px solid #e5e7eb;
    }
    
    .form-section:last-child {
      border-bottom: none;
    }
    
    .section-title {
      color: #1e40af;
      font-weight: 600;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .section-title i {
      font-size: 20px;
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
    
    .requirement-list {
      list-style: none;
      padding: 0;
      margin: 10px 0;
      font-size: 13px;
    }
    
    .requirement-list li {
      margin-bottom: 5px;
      color: #6b7280;
    }
    
    .requirement-list li.valid {
      color: #10b981;
    }
    
    .requirement-list li.invalid {
      color: #ef4444;
    }
    
    .requirement-list li i {
      margin-right: 5px;
    }
    
    .benefits-card {
      background: #f8fafc;
      border-radius: 10px;
      padding: 20px;
      margin-top: 20px;
    }
    
    .benefits-list {
      list-style: none;
      padding: 0;
    }
    
    .benefits-list li {
      margin-bottom: 10px;
      display: flex;
      align-items: flex-start;
      gap: 10px;
    }
    
    .benefits-list li i {
      color: #10b981;
      margin-top: 3px;
    }
    
    .login-link {
      text-align: center;
      margin-top: 20px;
      padding-top: 20px;
      border-top: 1px solid #e5e7eb;
    }
    
    .terms-check {
      font-size: 14px;
    }
  </style>
</head>
<body>
  <div class="register-container">
    <div class="register-header">
      <h2 class="mb-2"><i class="bi bi-water me-2"></i><?= htmlspecialchars($school_name) ?></h2>
      <p class="mb-0">Create Your Student Account</p>
    </div>
    
    <div class="register-body">
      <?php if($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="bi bi-exclamation-circle me-2"></i><?= $error ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      
      <?php if($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="bi bi-check-circle me-2"></i><?= $success ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      
      <form method="POST" id="registerForm">
        <!-- Personal Information -->
        <div class="form-section">
          <h4 class="section-title">
            <i class="bi bi-person-circle"></i>Personal Information
          </h4>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Full Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
              <div class="form-text">As it appears on your ID</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email Address <span class="text-danger">*</span></label>
              <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
              <div class="form-text">We'll send important updates to this email</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone Number</label>
              <input type="tel" class="form-control" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" placeholder="+263 77 123 4567">
              <div class="form-text">Zimbabwe format preferred</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Age <span class="text-danger">*</span></label>
              <input type="number" class="form-control" name="age" value="<?= htmlspecialchars($_POST['age'] ?? '') ?>" min="4" max="100" required>
              <div class="form-text">Must be between 4 and 100 years</div>
            </div>
            <div class="col-12">
              <label class="form-label">Address</label>
              <textarea class="form-control" name="address" rows="2"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
            </div>
          </div>
        </div>
        
        <!-- Account Security -->
        <div class="form-section">
          <h4 class="section-title">
            <i class="bi bi-shield-lock"></i>Account Security
          </h4>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Password <span class="text-danger">*</span></label>
              <input type="password" class="form-control" name="password" id="password" required>
              <div class="password-strength">
                <div class="password-strength-bar" id="passwordStrength"></div>
              </div>
              <ul class="requirement-list" id="passwordRequirements">
                <li id="req-length" class="invalid">
                  <i class="bi bi-x-circle"></i>At least 8 characters
                </li>
                <li id="req-uppercase" class="invalid">
                  <i class="bi bi-x-circle"></i>One uppercase letter
                </li>
                <li id="req-lowercase" class="invalid">
                  <i class="bi bi-x-circle"></i>One lowercase letter
                </li>
                <li id="req-number" class="invalid">
                  <i class="bi bi-x-circle"></i>One number
                </li>
              </ul>
            </div>
            <div class="col-md-6">
              <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
              <input type="password" class="form-control" name="confirm_password" id="confirmPassword" required>
              <div class="form-text" id="passwordMatch"></div>
            </div>
          </div>
        </div>
        
        <!-- Emergency & Medical Information -->
        <div class="form-section">
          <h4 class="section-title">
            <i class="bi bi-heart-pulse"></i>Emergency & Medical Information
          </h4>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Emergency Contact</label>
              <input type="text" class="form-control" name="emergency_contact" value="<?= htmlspecialchars($_POST['emergency_contact'] ?? '') ?>" placeholder="Name and phone number">
              <div class="form-text">Who should we contact in case of emergency?</div>
            </div>
            <div class="col-12">
              <label class="form-label">Medical Notes</label>
              <textarea class="form-control" name="medical_notes" rows="3" placeholder="Any medical conditions, allergies, or special requirements..."><?= htmlspecialchars($_POST['medical_notes'] ?? '') ?></textarea>
              <div class="form-text">This information helps our instructors provide appropriate care</div>
            </div>
          </div>
        </div>
        
        <!-- Terms & Conditions -->
        <div class="form-section">
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="terms" name="terms" required>
            <label class="form-check-label terms-check" for="terms">
              I agree to the <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms and Conditions</a> and 
              <a href="#" data-bs-toggle="modal" data-bs-target="#privacyModal">Privacy Policy</a> of <?= htmlspecialchars($school_name) ?>
            </label>
          </div>
          
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="newsletter" name="newsletter" checked>
            <label class="form-check-label terms-check" for="newsletter">
              I want to receive updates about new classes, promotions, and swimming tips
            </label>
          </div>
        </div>
        
        <!-- Submit Button -->
        <div class="d-grid gap-2">
          <button type="submit" class="btn btn-primary btn-lg" id="registerBtn">
            <i class="bi bi-person-plus me-2"></i>Create Account
          </button>
        </div>
      </form>
      
      <div class="login-link">
        <p class="mb-0">Already have an account? <a href="login.php" class="text-decoration-none fw-medium">Sign In</a></p>
      </div>
      
      <!-- Benefits Section -->
      <div class="benefits-card">
        <h5 class="mb-3">Why Register?</h5>
        <ul class="benefits-list">
          <li>
            <i class="bi bi-check-circle-fill"></i>
            <div>Book swimming classes instantly</div>
          </li>
          <li>
            <i class="bi bi-check-circle-fill"></i>
            <div>Track your progress and achievements</div>
          </li>
          <li>
            <i class="bi bi-check-circle-fill"></i>
            <div>Receive personalized class recommendations</div>
          </li>
          <li>
            <i class="bi bi-check-circle-fill"></i>
            <div>Secure online payments</div>
          </li>
          <li>
            <i class="bi bi-check-circle-fill"></i>
            <div>Access instructor feedback and ratings</div>
          </li>
        </ul>
      </div>
    </div>
  </div>
  
  <!-- Terms Modal -->
  <div class="modal fade" id="termsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Terms and Conditions</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <h6>1. Acceptance of Terms</h6>
          <p>By registering for an account with <?= htmlspecialchars($school_name) ?>, you agree to these terms and conditions.</p>
          
          <h6>2. Student Responsibilities</h6>
          <p>Students must arrive on time for classes, follow instructor directions, and adhere to pool safety rules.</p>
          
          <h6>3. Payment Terms</h6>
          <p>Payments for classes are due in advance. Cancellations must be made at least 24 hours before class for a refund.</p>
          
          <h6>4. Health and Safety</h6>
          <p>Students must disclose any medical conditions that may affect their ability to swim safely.</p>
          
          <h6>5. Liability</h6>
          <p><?= htmlspecialchars($school_name) ?> is not liable for personal injury or loss of property.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
  
  <!-- Privacy Modal -->
  <div class="modal fade" id="privacyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Privacy Policy</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <h6>1. Information We Collect</h6>
          <p>We collect personal information including name, email, phone number, age, and medical information for registration purposes.</p>
          
          <h6>2. How We Use Your Information</h6>
          <p>Your information is used to manage your account, process payments, provide class updates, and ensure your safety during classes.</p>
          
          <h6>3. Data Security</h6>
          <p>We implement security measures to protect your personal information from unauthorized access.</p>
          
          <h6>4. Sharing of Information</h6>
          <p>We do not sell your personal information. Medical information is only shared with instructors for safety purposes.</p>
          
          <h6>5. Your Rights</h6>
          <p>You have the right to access, correct, or delete your personal information at any time.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
  
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const passwordInput = document.getElementById('password');
      const confirmPasswordInput = document.getElementById('confirmPassword');
      const passwordStrengthBar = document.getElementById('passwordStrength');
      const passwordMatchText = document.getElementById('passwordMatch');
      const registerBtn = document.getElementById('registerBtn');
      
      // Password requirements elements
      const reqLength = document.getElementById('req-length');
      const reqUppercase = document.getElementById('req-uppercase');
      const reqLowercase = document.getElementById('req-lowercase');
      const reqNumber = document.getElementById('req-number');
      
      function checkPasswordStrength(password) {
        let strength = 0;
        let requirements = {
          length: false,
          uppercase: false,
          lowercase: false,
          number: false
        };
        
        // Length check
        if (password.length >= 8) {
          strength += 25;
          requirements.length = true;
          reqLength.className = 'valid';
          reqLength.innerHTML = '<i class="bi bi-check-circle"></i>At least 8 characters';
        } else {
          reqLength.className = 'invalid';
          reqLength.innerHTML = '<i class="bi bi-x-circle"></i>At least 8 characters';
        }
        
        // Uppercase check
        if (/[A-Z]/.test(password)) {
          strength += 25;
          requirements.uppercase = true;
          reqUppercase.className = 'valid';
          reqUppercase.innerHTML = '<i class="bi bi-check-circle"></i>One uppercase letter';
        } else {
          reqUppercase.className = 'invalid';
          reqUppercase.innerHTML = '<i class="bi bi-x-circle"></i>One uppercase letter';
        }
        
        // Lowercase check
        if (/[a-z]/.test(password)) {
          strength += 25;
          requirements.lowercase = true;
          reqLowercase.className = 'valid';
          reqLowercase.innerHTML = '<i class="bi bi-check-circle"></i>One lowercase letter';
        } else {
          reqLowercase.className = 'invalid';
          reqLowercase.innerHTML = '<i class="bi bi-x-circle"></i>One lowercase letter';
        }
        
        // Number check
        if (/[0-9]/.test(password)) {
          strength += 25;
          requirements.number = true;
          reqNumber.className = 'valid';
          reqNumber.innerHTML = '<i class="bi bi-check-circle"></i>One number';
        } else {
          reqNumber.className = 'invalid';
          reqNumber.innerHTML = '<i class="bi bi-x-circle"></i>One number';
        }
        
        return { strength, requirements };
      }
      
      function updatePasswordStrength() {
        const password = passwordInput.value;
        const { strength, requirements } = checkPasswordStrength(password);
        
        let width = strength;
        let color = '';
        
        if (strength <= 25) {
          color = 'strength-weak';
        } else if (strength <= 50) {
          color = 'strength-medium';
        } else if (strength <= 75) {
          color = 'strength-medium';
        } else {
          color = 'strength-strong';
        }
        
        passwordStrengthBar.style.width = width + '%';
        passwordStrengthBar.className = 'password-strength-bar ' + color;
      }
      
      function checkPasswordMatch() {
        const password = passwordInput.value;
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
      
      function validateForm() {
        const password = passwordInput.value;
        const confirm = confirmPasswordInput.value;
        const terms = document.getElementById('terms').checked;
        
        // Check password strength
        const { requirements } = checkPasswordStrength(password);
        const isPasswordStrong = Object.values(requirements).every(req => req === true);
        
        // Check password match
        const isPasswordMatch = password === confirm;
        
        // Enable/disable submit button
        registerBtn.disabled = !(isPasswordStrong && isPasswordMatch && terms);
        
        return isPasswordStrong && isPasswordMatch && terms;
      }
      
      // Event listeners
      passwordInput.addEventListener('input', function() {
        updatePasswordStrength();
        checkPasswordMatch();
        validateForm();
      });
      
      confirmPasswordInput.addEventListener('input', function() {
        checkPasswordMatch();
        validateForm();
      });
      
      document.getElementById('terms').addEventListener('change', validateForm);
      
      // Form submission validation
      document.getElementById('registerForm').addEventListener('submit', function(e) {
        if (!validateForm()) {
          e.preventDefault();
          if (!document.getElementById('terms').checked) {
            alert('Please accept the Terms and Conditions to continue.');
          } else {
            alert('Please ensure your password meets all requirements and matches.');
          }
        }
      });
      
      // Initialize validation
      validateForm();
    });
  </script>
</body>
</html>
[file content end]