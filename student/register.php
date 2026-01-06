<?php
// student/register.php - Simplified Student Registration
session_start();

// Include database connection and functions
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

// Initialize variables
$errors = [];
$success = false;
$form_data = [
    'name' => '',
    'email' => '',
    'phone' => '',
    'dob' => '',
    'emergency_contact' => '',
    'emergency_phone' => '',
    'medical_notes' => '',
    'swimming_level' => 'beginner',
    'password' => '',
    'confirm_password' => ''
];

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and sanitize input
    $form_data['name'] = trim($_POST['name'] ?? '');
    $form_data['email'] = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $form_data['phone'] = trim($_POST['phone'] ?? '');
    $form_data['dob'] = trim($_POST['dob'] ?? '');
    $form_data['emergency_contact'] = trim($_POST['emergency_contact'] ?? '');
    $form_data['emergency_phone'] = trim($_POST['emergency_phone'] ?? '');
    $form_data['medical_notes'] = trim($_POST['medical_notes'] ?? '');
    $form_data['swimming_level'] = trim($_POST['swimming_level'] ?? 'beginner');
    $form_data['password'] = $_POST['password'] ?? '';
    $form_data['confirm_password'] = $_POST['confirm_password'] ?? '';

    // Basic validations
    if ($form_data['name'] === '') {
        $errors['name'] = 'Please enter your full name';
    }

    if (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address';
    }

    if ($form_data['dob'] === '') {
        $errors['dob'] = 'Please enter your date of birth';
    } else {
        // Calculate age
        $dob_ts = strtotime($form_data['dob']);
        if ($dob_ts === false) {
            $errors['dob'] = 'Invalid date of birth';
        } else {
            $age = (int) date('Y', time() - $dob_ts) - 1970;
            if ($age < 3) {
                $errors['dob'] = 'Student must be at least 3 years old';
            }
        }
    }

    if ($form_data['emergency_contact'] === '') {
        $errors['emergency_contact'] = 'Please provide an emergency contact name';
    }
    if ($form_data['emergency_phone'] === '') {
        $errors['emergency_phone'] = 'Please provide an emergency contact phone';
    }

    // Password checks
    if (strlen($form_data['password']) < 8) {
        $errors['password'] = 'Password must be at least 8 characters';
    }
    if ($form_data['password'] !== $form_data['confirm_password']) {
        $errors['confirm_password'] = 'Passwords do not match';
    }

    if (!isset($_POST['terms'])) {
        $errors['general'] = 'You must agree to the terms to register';
    }

    // If no validation errors, proceed to create account
    if (empty($errors)) {
        // Check for existing email
        $chk = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        if ($chk) {
            $chk->bind_param('s', $form_data['email']);
            $chk->execute();
            $res = $chk->get_result();
            if ($res && $res->num_rows > 0) {
                $errors['email'] = 'An account with that email already exists';
            }
            $chk->close();
        }
    }

    if (empty($errors)) {
        // Hash password
        $hashed = password_hash($form_data['password'], PASSWORD_DEFAULT);

        // Prepare insert
        $ins = $conn->prepare('INSERT INTO users (name, email, password, phone, age, emergency_contact, medical_notes, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, "student", "active", NOW())');
        if ($ins) {
            $phone = $form_data['phone'] !== '' ? $form_data['phone'] : null;
            $age_val = isset($age) ? $age : null;
            $emergency = $form_data['emergency_contact'];
            $medical = $form_data['medical_notes'] !== '' ? $form_data['medical_notes'] : null;
            $ins->bind_param('ssssiss', $form_data['name'], $form_data['email'], $hashed, $phone, $age_val, $emergency, $medical);
            if ($ins->execute()) {
                $new_id = $conn->insert_id;
                $ins->close();

                // Log activity
                if (function_exists('logActivity')) {
                    logActivity($conn, $new_id, 'register', 'New student account created');
                }

                // Auto-login the user
                session_regenerate_id(true);
                $_SESSION['user_id'] = $new_id;
                $_SESSION['role'] = 'student';
                $_SESSION['user_name'] = $form_data['name'];
                $_SESSION['login_time'] = time();

                // Redirect to student dashboard
                header('Location: index.php');
                exit();
            } else {
                $errors['general'] = 'Could not create account. Please try again later.';
            }
        } else {
            $errors['general'] = 'Database error. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | Elite Swimming Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #f8f9fa;
            display: flex;
            align-items: center;
            min-height: 100vh;
            color: #333;
        }

        .register-wrapper {
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }

        .register-container {
            background: white;
            border-radius: 10px;
            padding: 40px 30px;
            border: 1px solid #dee2e6;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .logo-area {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            margin-bottom: 15px;
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
            font-size: 20px;
            margin: 0;
            color: #212529;
        }

        .logo-text span {
            font-size: 12px;
            color: #6c757d;
        }

        .register-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .register-header h2 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #212529;
        }

        .register-header p {
            color: #6c757d;
            margin: 0;
            font-size: 14px;
        }

        .alert-custom {
            border-radius: 8px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            font-weight: 500;
            color: #495057;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-control, .form-select {
            padding: 12px 15px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 14px;
        }

        .form-control:focus, .form-select:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.1);
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            z-index: 2;
        }

        .password-strength {
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }

        .password-strength-fill {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease;
            border-radius: 2px;
        }

        .btn-register {
            background: #0d6efd;
            color: white;
            padding: 12px;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            font-size: 15px;
            width: 100%;
            margin: 10px 0;
        }

        .btn-register:hover {
            background: #0a58ca;
        }

        .terms-box {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 15px;
            margin: 20px 0;
            border: 1px solid #e9ecef;
        }

        .footer-links {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
        }

        .footer-links a {
            color: #6c757d;
            text-decoration: none;
            font-size: 13px;
            margin: 0 10px;
        }

        .footer-links a:hover {
            color: #0d6efd;
        }

        .row {
            margin-left: -10px;
            margin-right: -10px;
        }

        .col-md-6 {
            padding-left: 10px;
            padding-right: 10px;
        }

        .small-text {
            font-size: 12px;
            color: #6c757d;
            margin-top: 4px;
        }

        @media (max-width: 576px) {
            .register-wrapper {
                padding: 10px;
            }
            
            .register-container {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="register-wrapper">
        <div class="register-container">
            <div class="logo-area">
                <a href="../" class="logo">
                    <div class="logo-icon">
                        <i class="bi bi-droplet"></i>
                    </div>
                    <div class="logo-text">
                        <h3>Elite Swimming</h3>
                        <span>Student Portal</span>
                    </div>
                </a>
            </div>

            <div class="register-header">
                <h2>Create Account</h2>
                <p>Fill in your details to get started</p>
            </div>

            <?php if(isset($errors['general'])): ?>
                <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($errors['general']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form method="post" id="registrationForm" novalidate>
                <!-- Personal Information -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="name" class="form-label">Full Name *</label>
                            <input type="text" 
                                   class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" 
                                   name="name" 
                                   id="name" 
                                   value="<?php echo htmlspecialchars($form_data['name']); ?>" 
                                   placeholder="John Smith"
                                   required>
                            <?php if(isset($errors['name'])): ?>
                                <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['name']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="email" class="form-label">Email Address *</label>
                            <input type="email" 
                                   class="form-control <?php echo isset($errors['email']) ? 'is-invalid' : ''; ?>" 
                                   name="email" 
                                   id="email" 
                                   value="<?php echo htmlspecialchars($form_data['email']); ?>" 
                                   placeholder="john@example.com"
                                   required>
                            <?php if(isset($errors['email'])): ?>
                                <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['email']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="dob" class="form-label">Date of Birth *</label>
                            <input type="date" 
                                   class="form-control <?php echo isset($errors['dob']) ? 'is-invalid' : ''; ?>" 
                                   name="dob" 
                                   id="dob" 
                                   value="<?php echo htmlspecialchars($form_data['dob']); ?>"
                                   required>
                            <div class="small-text">Must be at least 3 years old</div>
                            <?php if(isset($errors['dob'])): ?>
                                <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['dob']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="phone" class="form-label">Phone Number</label>
                            <input type="tel" 
                                   class="form-control" 
                                   name="phone" 
                                   id="phone" 
                                   value="<?php echo htmlspecialchars($form_data['phone']); ?>" 
                                   placeholder="(123) 456-7890">
                        </div>
                    </div>
                </div>

                <!-- Emergency Contact -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="emergency_contact" class="form-label">Emergency Contact *</label>
                            <input type="text" 
                                   class="form-control <?php echo isset($errors['emergency_contact']) ? 'is-invalid' : ''; ?>" 
                                   name="emergency_contact" 
                                   id="emergency_contact" 
                                   value="<?php echo htmlspecialchars($form_data['emergency_contact']); ?>" 
                                   placeholder="Contact name"
                                   required>
                            <?php if(isset($errors['emergency_contact'])): ?>
                                <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['emergency_contact']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="emergency_phone" class="form-label">Emergency Phone *</label>
                            <input type="tel" 
                                   class="form-control <?php echo isset($errors['emergency_phone']) ? 'is-invalid' : ''; ?>" 
                                   name="emergency_phone" 
                                   id="emergency_phone" 
                                   value="<?php echo htmlspecialchars($form_data['emergency_phone']); ?>" 
                                   placeholder="(123) 456-7890"
                                   required>
                            <?php if(isset($errors['emergency_phone'])): ?>
                                <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['emergency_phone']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Swimming Information -->
                <div class="form-group">
                    <label for="swimming_level" class="form-label">Swimming Level</label>
                    <select class="form-select" name="swimming_level" id="swimming_level">
                        <option value="beginner" <?php echo $form_data['swimming_level'] == 'beginner' ? 'selected' : ''; ?>>Beginner (No experience)</option>
                        <option value="basic" <?php echo $form_data['swimming_level'] == 'basic' ? 'selected' : ''; ?>>Basic (Can float)</option>
                        <option value="intermediate" <?php echo $form_data['swimming_level'] == 'intermediate' ? 'selected' : ''; ?>>Intermediate (Can swim short distances)</option>
                        <option value="advanced" <?php echo $form_data['swimming_level'] == 'advanced' ? 'selected' : ''; ?>>Advanced (Comfortable in water)</option>
                        <option value="competitive" <?php echo $form_data['swimming_level'] == 'competitive' ? 'selected' : ''; ?>>Competitive (Training for competitions)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="medical_notes" class="form-label">Medical Notes</label>
                    <textarea class="form-control" 
                              name="medical_notes" 
                              id="medical_notes" 
                              rows="2" 
                              placeholder="Any medical conditions, allergies, or special requirements..."><?php echo htmlspecialchars($form_data['medical_notes']); ?></textarea>
                    <div class="small-text">Optional - for your safety</div>
                </div>

                <!-- Account Security -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="password" class="form-label">Password *</label>
                            <div style="position: relative;">
                                <input type="password" 
                                       class="form-control <?php echo isset($errors['password']) ? 'is-invalid' : ''; ?>" 
                                       name="password" 
                                       id="password" 
                                       placeholder="At least 8 characters"
                                       required>
                                <button type="button" class="password-toggle" id="togglePassword">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="password-strength">
                                <div class="password-strength-fill" id="passwordStrengthFill"></div>
                            </div>
                            <?php if(isset($errors['password'])): ?>
                                <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['password']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="confirm_password" class="form-label">Confirm Password *</label>
                            <div style="position: relative;">
                                <input type="password" 
                                       class="form-control <?php echo isset($errors['confirm_password']) ? 'is-invalid' : ''; ?>" 
                                       name="confirm_password" 
                                       id="confirm_password" 
                                       placeholder="Confirm your password"
                                       required>
                                <button type="button" class="password-toggle" id="toggleConfirmPassword">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <?php if(isset($errors['confirm_password'])): ?>
                                <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['confirm_password']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Terms & Conditions -->
                <div class="terms-box">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="terms" id="terms" required>
                        <label class="form-check-label" for="terms">
                            I agree to the <a href="#" class="text-decoration-none">Terms of Service</a> and 
                            <a href="#" class="text-decoration-none">Privacy Policy</a>
                        </label>
                    </div>
                </div>

                <button type="submit" class="btn-register">
                    Create Account
                </button>

                <div class="text-center mt-3">
                    <p class="text-muted mb-0">
                        Already have an account? 
                        <a href="login.php" class="text-decoration-none">Sign In</a>
                    </p>
                </div>
            </form>

            <div class="footer-links">
                <a href="../">Home</a>
                <a href="contact.php">Contact</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Password toggle functionality (matches login page)
            const togglePassword = document.getElementById('togglePassword');
            const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
            const passwordInput = document.getElementById('password');
            const confirmInput = document.getElementById('confirm_password');
            
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.innerHTML = type === 'password' ? 
                    '<i class="bi bi-eye"></i>' : 
                    '<i class="bi bi-eye-slash"></i>';
            });
            
            toggleConfirmPassword.addEventListener('click', function() {
                const type = confirmInput.getAttribute('type') === 'password' ? 'text' : 'password';
                confirmInput.setAttribute('type', type);
                this.innerHTML = type === 'password' ? 
                    '<i class="bi bi-eye"></i>' : 
                    '<i class="bi bi-eye-slash"></i>';
            });
            
            // Password strength indicator (simplified)
            passwordInput.addEventListener('input', function() {
                const password = passwordInput.value;
                let strength = 0;
                
                if (password.length >= 8) strength += 50;
                if (/[A-Z]/.test(password)) strength += 25;
                if (/[0-9]/.test(password)) strength += 25;
                
                const strengthFill = document.getElementById('passwordStrengthFill');
                strengthFill.style.width = strength + '%';
                
                // Update color
                if (strength < 50) {
                    strengthFill.style.backgroundColor = '#dc3545';
                } else if (strength < 75) {
                    strengthFill.style.backgroundColor = '#ffc107';
                } else {
                    strengthFill.style.backgroundColor = '#198754';
                }
            });
            
            // Auto-focus name field
            const nameInput = document.getElementById('name');
            if (nameInput) {
                nameInput.focus();
            }
            
            // Phone number formatting
            const phoneInputs = document.querySelectorAll('input[type="tel"]');
            phoneInputs.forEach(input => {
                input.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/\D/g, '');
                    if (value.length > 10) value = value.substring(0, 10);
                    
                    if (value.length === 10) {
                        value = value.replace(/(\d{3})(\d{3})(\d{4})/, '($1) $2-$3');
                    }
                    e.target.value = value;
                });
            });
        });
    </script>
</body>
</html>