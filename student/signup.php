<?php
// student/register.php - Professional Student Registration
session_start();

// Include database connection
require_once __DIR__ . '/../inc/db.php';

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
    'confirm_password' => '',
    'terms' => false
];

// Swimming levels
$swimming_levels = [
    'beginner' => 'Beginner (No experience)',
    'basic' => 'Basic (Can float)',
    'intermediate' => 'Intermediate (Can swim short distances)',
    'advanced' => 'Advanced (Comfortable in water)',
    'competitive' => 'Competitive (Training for competitions)'
];

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize inputs
    $form_data['name'] = trim($_POST['name'] ?? '');
    $form_data['email'] = trim($_POST['email'] ?? '');
    $form_data['phone'] = trim($_POST['phone'] ?? '');
    $form_data['dob'] = $_POST['dob'] ?? '';
    $form_data['emergency_contact'] = trim($_POST['emergency_contact'] ?? '');
    $form_data['emergency_phone'] = trim($_POST['emergency_phone'] ?? '');
    $form_data['medical_notes'] = trim($_POST['medical_notes'] ?? '');
    $form_data['swimming_level'] = $_POST['swimming_level'] ?? 'beginner';
    $form_data['password'] = $_POST['password'] ?? '';
    $form_data['confirm_password'] = $_POST['confirm_password'] ?? '';
    $form_data['terms'] = isset($_POST['terms']) && $_POST['terms'] === 'on';

    // Validation
    if (empty($form_data['name'])) {
        $errors['name'] = 'Full name is required';
    } elseif (strlen($form_data['name']) < 2) {
        $errors['name'] = 'Name must be at least 2 characters';
    }

    if (empty($form_data['email'])) {
        $errors['email'] = 'Email is required';
    } elseif (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Invalid email format';
    } else {
        // Check if email exists
        $check_stmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
        $check_stmt->bind_param('s', $form_data['email']);
        $check_stmt->execute();
        $check_stmt->store_result();
        if ($check_stmt->num_rows > 0) {
            $errors['email'] = 'Email already registered';
        }
        $check_stmt->close();
    }

    // Date of birth validation
    if (empty($form_data['dob'])) {
        $errors['dob'] = 'Date of birth is required';
    } else {
        $dob = new DateTime($form_data['dob']);
        $today = new DateTime();
        $age = $today->diff($dob)->y;
        
        if ($age < 3) {
            $errors['dob'] = 'Must be at least 3 years old';
        } elseif ($age > 80) {
            $errors['dob'] = 'Please enter a valid date';
        }
    }

    // Emergency contact validation
    if (empty($form_data['emergency_contact'])) {
        $errors['emergency_contact'] = 'Emergency contact name is required';
    }

    if (empty($form_data['emergency_phone'])) {
        $errors['emergency_phone'] = 'Emergency phone is required';
    } elseif (!preg_match('/^[\d\s\-\+\(\)]{10,20}$/', $form_data['emergency_phone'])) {
        $errors['emergency_phone'] = 'Invalid phone format';
    }

    // Password validation
    if (empty($form_data['password'])) {
        $errors['password'] = 'Password is required';
    } elseif (strlen($form_data['password']) < 8) {
        $errors['password'] = 'Minimum 8 characters required';
    } elseif (!preg_match('/[A-Z]/', $form_data['password'])) {
        $errors['password'] = 'At least one uppercase letter required';
    } elseif (!preg_match('/[a-z]/', $form_data['password'])) {
        $errors['password'] = 'At least one lowercase letter required';
    } elseif (!preg_match('/[0-9]/', $form_data['password'])) {
        $errors['password'] = 'At least one number required';
    }

    if ($form_data['password'] !== $form_data['confirm_password']) {
        $errors['confirm_password'] = 'Passwords do not match';
    }

    if (!$form_data['terms']) {
        $errors['terms'] = 'You must agree to the terms';
    }

    // If no errors, proceed with registration
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            // Hash password
            $hashed_password = password_hash($form_data['password'], PASSWORD_DEFAULT);
            
            // Generate student ID
            $student_id = 'STU-' . date('Ymd') . '-' . str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
            
            // Insert into users table
            $user_stmt = $conn->prepare('INSERT INTO users (name, email, password, role, student_id, created_at) 
                                        VALUES (?, ?, ?, "student", ?, NOW())');
            $user_stmt->bind_param('ssss', $form_data['name'], $form_data['email'], $hashed_password, $student_id);
            $user_stmt->execute();
            $user_id = $user_stmt->insert_id;
            $user_stmt->close();
            
            // Insert into students table
            $student_stmt = $conn->prepare('INSERT INTO students 
                (user_id, student_id, phone, date_of_birth, emergency_contact, emergency_phone, 
                 medical_notes, swimming_level, status, registration_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, "active", CURDATE())');
            $student_stmt->bind_param('isssssss', 
                $user_id, $student_id, $form_data['phone'], $form_data['dob'],
                $form_data['emergency_contact'], $form_data['emergency_phone'],
                $form_data['medical_notes'], $form_data['swimming_level']
            );
            $student_stmt->execute();
            $student_stmt->close();
            
            $conn->commit();
            $success = true;
            
            // Auto login after registration
            $_SESSION['user_id'] = $user_id;
            $_SESSION['role'] = 'student';
            $_SESSION['user_name'] = $form_data['name'];
            $_SESSION['student_id'] = $student_id;
            
            // Redirect to dashboard
            header('Location: index.php?registered=true');
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $errors['general'] = 'Registration failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join AquaFlow | Student Registration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-blue: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #dbeafe;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
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
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e6f0ff 100%);
            min-height: 100vh;
            color: var(--gray-800);
            line-height: 1.6;
        }

        .registration-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .registration-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(37, 99, 235, 0.1);
            overflow: hidden;
            display: flex;
            min-height: 90vh;
        }

        .left-panel {
            flex: 1;
            background: linear-gradient(145deg, var(--primary-blue) 0%, var(--primary-dark) 100%);
            padding: 50px 40px;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .left-panel::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
        }

        .logo-area {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 60px;
        }

        .logo-icon {
            width: 50px;
            height: 50px;
            background: rgba(255,255,255,0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            backdrop-filter: blur(10px);
        }

        .logo-text h1 {
            font-size: 28px;
            font-weight: 700;
            margin: 0;
            letter-spacing: -0.5px;
        }

        .logo-text p {
            opacity: 0.9;
            font-size: 14px;
            margin: 0;
        }

        .feature-grid {
            margin: 50px 0;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }

        .feature-icon {
            width: 48px;
            height: 48px;
            background: rgba(255,255,255,0.15);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .feature-text h4 {
            font-size: 16px;
            font-weight: 600;
            margin: 0 0 5px 0;
        }

        .feature-text p {
            font-size: 14px;
            opacity: 0.9;
            margin: 0;
            line-height: 1.5;
        }

        .testimonial-box {
            background: rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 25px;
            margin-top: 40px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .testimonial-text {
            font-style: italic;
            font-size: 15px;
            line-height: 1.7;
            margin-bottom: 20px;
        }

        .testimonial-author {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .author-avatar {
            width: 44px;
            height: 44px;
            background: linear-gradient(45deg, #ff6b6b, #ffa8a8);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
        }

        .author-info h5 {
            font-size: 15px;
            font-weight: 600;
            margin: 0;
        }

        .author-info p {
            font-size: 13px;
            opacity: 0.8;
            margin: 0;
        }

        .right-panel {
            flex: 1.2;
            padding: 50px 40px;
            overflow-y: auto;
        }

        .registration-header {
            margin-bottom: 40px;
        }

        .registration-header h2 {
            font-size: 32px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 10px;
            background: linear-gradient(90deg, var(--primary-blue), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .registration-header p {
            color: var(--gray-600);
            font-size: 16px;
        }

        .form-step-indicator {
            display: flex;
            gap: 10px;
            margin-bottom: 40px;
        }

        .step {
            flex: 1;
            height: 6px;
            background: var(--gray-200);
            border-radius: 3px;
            transition: all 0.3s ease;
        }

        .step.active {
            background: var(--primary-blue);
        }

        .form-section {
            margin-bottom: 35px;
            animation: slideIn 0.5s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--gray-100);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-label {
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: 8px;
            font-size: 14px;
        }

        .required::after {
            content: " *";
            color: var(--danger);
        }

        .form-control {
            padding: 12px 16px;
            border: 2px solid var(--gray-200);
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .input-group-text {
            background: var(--gray-50);
            border: 2px solid var(--gray-200);
            border-right: none;
            color: var(--gray-600);
        }

        .password-toggle {
            background: none;
            border: 2px solid var(--gray-200);
            border-left: none;
            color: var(--gray-600);
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: var(--primary-blue);
        }

        .password-strength {
            height: 4px;
            background: var(--gray-200);
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease, background-color 0.3s ease;
            border-radius: 2px;
        }

        .requirements {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            margin-top: 12px;
        }

        @media (max-width: 768px) {
            .requirements {
                grid-template-columns: 1fr;
            }
        }

        .requirement {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }

        .requirement i {
            font-size: 12px;
            width: 16px;
            text-align: center;
        }

        .requirement.met {
            color: var(--success);
        }

        .requirement.unmet {
            color: var(--gray-500);
        }

        .terms-container {
            background: var(--gray-50);
            border-radius: 12px;
            padding: 20px;
            margin: 30px 0;
            border: 2px solid var(--gray-200);
        }

        .form-check-input:checked {
            background-color: var(--primary-blue);
            border-color: var(--primary-blue);
        }

        .btn-register {
            background: linear-gradient(90deg, var(--primary-blue), var(--primary-dark));
            color: white;
            padding: 16px 32px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 16px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(37, 99, 235, 0.3);
        }

        .btn-register:active {
            transform: translateY(0);
        }

        .login-link {
            text-align: center;
            margin-top: 25px;
            color: var(--gray-600);
            font-size: 15px;
        }

        .login-link a {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .login-link a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        /* Alert Styles */
        .alert-custom {
            border-radius: 12px;
            border: none;
            padding: 16px 20px;
            margin-bottom: 25px;
            animation: slideIn 0.5s ease;
        }

        .alert-custom i {
            font-size: 20px;
            margin-right: 12px;
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .registration-card {
                flex-direction: column;
                min-height: auto;
            }
            
            .left-panel, .right-panel {
                padding: 40px 30px;
            }
            
            .left-panel {
                display: none; /* Hide left panel on mobile for better form focus */
            }
        }

        @media (max-width: 768px) {
            .registration-container {
                padding: 10px;
            }
            
            .right-panel {
                padding: 30px 20px;
            }
            
            .registration-header h2 {
                font-size: 28px;
            }
        }

        /* Custom form validation styles */
        .is-invalid {
            border-color: var(--danger) !important;
        }

        .is-invalid:focus {
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1) !important;
        }

        .invalid-feedback {
            font-size: 13px;
            color: var(--danger);
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="registration-container">
        <div class="registration-card">
            <!-- Left Panel - Branding & Features -->
            <div class="left-panel">
                <div class="logo-area">
                    <div class="logo-icon">
                        <i class="bi bi-droplet"></i>
                    </div>
                    <div class="logo-text">
                        <h1>AquaFlow</h1>
                        <p>Swimming Excellence Since 2010</p>
                    </div>
                </div>

                <div class="feature-grid">
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Safety First</h4>
                            <p>Certified instructors & controlled environment</p>
                        </div>
                    </div>
                    
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Track Progress</h4>
                            <p>Monitor improvement with detailed analytics</p>
                        </div>
                    </div>
                    
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Flexible Schedule</h4>
                            <p>Choose from multiple time slots daily</p>
                        </div>
                    </div>
                    
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="bi bi-award"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Certification</h4>
                            <p>Earn recognized swimming certifications</p>
                        </div>
                    </div>
                </div>

                <div class="testimonial-box">
                    <div class="testimonial-text">
                        "AquaFlow transformed my fear of water into a love for swimming. The instructors are incredibly supportive!"
                    </div>
                    <div class="testimonial-author">
                        <div class="author-avatar">M</div>
                        <div class="author-info">
                            <h5>Michael Chen</h5>
                            <p>Student for 2 years</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Panel - Registration Form -->
            <div class="right-panel">
                <div class="registration-header">
                    <h2>Join AquaFlow Today</h2>
                    <p>Create your student account in just a few steps</p>
                </div>

                <div class="form-step-indicator">
                    <div class="step active"></div>
                    <div class="step"></div>
                    <div class="step"></div>
                    <div class="step"></div>
                </div>

                <?php if(isset($errors['general'])): ?>
                    <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($errors['general']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="post" id="registrationForm" novalidate>
                    <!-- Personal Information -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="bi bi-person-circle"></i>
                            Personal Information
                        </h3>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required">Full Name</label>
                                <input type="text" 
                                       class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" 
                                       name="name" 
                                       id="name" 
                                       value="<?php echo htmlspecialchars($form_data['name']); ?>" 
                                       placeholder="John Smith"
                                       required>
                                <?php if(isset($errors['name'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['name']); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label required">Email Address</label>
                                <input type="email" 
                                       class="form-control <?php echo isset($errors['email']) ? 'is-invalid' : ''; ?>" 
                                       name="email" 
                                       id="email" 
                                       value="<?php echo htmlspecialchars($form_data['email']); ?>" 
                                       placeholder="john@example.com"
                                       required>
                                <?php if(isset($errors['email'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['email']); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" 
                                       class="form-control" 
                                       name="phone" 
                                       id="phone" 
                                       value="<?php echo htmlspecialchars($form_data['phone']); ?>" 
                                       placeholder="(123) 456-7890">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label required">Date of Birth</label>
                                <input type="date" 
                                       class="form-control <?php echo isset($errors['dob']) ? 'is-invalid' : ''; ?>" 
                                       name="dob" 
                                       id="dob" 
                                       value="<?php echo htmlspecialchars($form_data['dob']); ?>" 
                                       required>
                                <?php if(isset($errors['dob'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['dob']); ?></div>
                                <?php endif; ?>
                                <small class="text-muted mt-1 d-block">Must be at least 3 years old</small>
                            </div>
                        </div>
                    </div>

                    <!-- Emergency Contact -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="bi bi-telephone-outbound"></i>
                            Emergency Contact
                        </h3>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required">Contact Name</label>
                                <input type="text" 
                                       class="form-control <?php echo isset($errors['emergency_contact']) ? 'is-invalid' : ''; ?>" 
                                       name="emergency_contact" 
                                       id="emergency_contact" 
                                       value="<?php echo htmlspecialchars($form_data['emergency_contact']); ?>" 
                                       placeholder="Jane Smith"
                                       required>
                                <?php if(isset($errors['emergency_contact'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['emergency_contact']); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label required">Contact Phone</label>
                                <input type="tel" 
                                       class="form-control <?php echo isset($errors['emergency_phone']) ? 'is-invalid' : ''; ?>" 
                                       name="emergency_phone" 
                                       id="emergency_phone" 
                                       value="<?php echo htmlspecialchars($form_data['emergency_phone']); ?>" 
                                       placeholder="(123) 456-7890"
                                       required>
                                <?php if(isset($errors['emergency_phone'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['emergency_phone']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Swimming Information -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="bi bi-water"></i>
                            Swimming Information
                        </h3>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required">Swimming Level</label>
                                <select class="form-select" name="swimming_level" id="swimming_level" required>
                                    <?php foreach($swimming_levels as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" 
                                            <?php echo $form_data['swimming_level'] == $value ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Medical Notes</label>
                                <textarea class="form-control" 
                                          name="medical_notes" 
                                          id="medical_notes" 
                                          rows="3"
                                          placeholder="Any medical conditions, allergies, or special requirements..."><?php echo htmlspecialchars($form_data['medical_notes']); ?></textarea>
                                <small class="text-muted">This information helps us ensure your safety</small>
                            </div>
                        </div>
                    </div>

                    <!-- Account Security -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="bi bi-shield-lock"></i>
                            Account Security
                        </h3>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required">Password</label>
                                <div class="input-group">
                                    <input type="password" 
                                           class="form-control <?php echo isset($errors['password']) ? 'is-invalid' : ''; ?>" 
                                           name="password" 
                                           id="password" 
                                           placeholder="Create a strong password"
                                           required>
                                    <button type="button" class="btn password-toggle" id="togglePassword">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                
                                <div class="password-strength mt-2">
                                    <div class="strength-bar" id="strengthBar"></div>
                                </div>
                                
                                <div class="requirements mt-3">
                                    <div class="requirement unmet" id="reqLength">
                                        <i class="bi bi-circle"></i>
                                        <span>8+ characters</span>
                                    </div>
                                    <div class="requirement unmet" id="reqUpper">
                                        <i class="bi bi-circle"></i>
                                        <span>Uppercase letter</span>
                                    </div>
                                    <div class="requirement unmet" id="reqLower">
                                        <i class="bi bi-circle"></i>
                                        <span>Lowercase letter</span>
                                    </div>
                                    <div class="requirement unmet" id="reqNumber">
                                        <i class="bi bi-circle"></i>
                                        <span>One number</span>
                                    </div>
                                </div>
                                
                                <?php if(isset($errors['password'])): ?>
                                    <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['password']); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label required">Confirm Password</label>
                                <div class="input-group">
                                    <input type="password" 
                                           class="form-control <?php echo isset($errors['confirm_password']) ? 'is-invalid' : ''; ?>" 
                                           name="confirm_password" 
                                           id="confirm_password" 
                                           placeholder="Re-enter your password"
                                           required>
                                    <button type="button" class="btn password-toggle" id="toggleConfirmPassword">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                
                                <div class="requirements mt-3">
                                    <div class="requirement unmet" id="reqMatch">
                                        <i class="bi bi-circle"></i>
                                        <span>Passwords match</span>
                                    </div>
                                </div>
                                
                                <?php if(isset($errors['confirm_password'])): ?>
                                    <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['confirm_password']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Terms & Conditions -->
                    <div class="terms-container">
                        <div class="form-check">
                            <input class="form-check-input <?php echo isset($errors['terms']) ? 'is-invalid' : ''; ?>" 
                                   type="checkbox" 
                                   name="terms" 
                                   id="terms" 
                                   required>
                            <label class="form-check-label" for="terms">
                                I agree to the <a href="#" class="text-primary text-decoration-none">Terms of Service</a> 
                                and <a href="#" class="text-primary text-decoration-none">Privacy Policy</a>. I understand 
                                that my information will be used in accordance with these policies.
                            </label>
                            <?php if(isset($errors['terms'])): ?>
                                <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['terms']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" class="btn-register">
                        <i class="bi bi-person-plus"></i>
                        Create Account & Continue
                    </button>
                </form>

                <div class="login-link">
                    Already have an account? 
                    <a href="login.php">Sign in here</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Password toggle functionality
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
            
            // Password strength checker
            passwordInput.addEventListener('input', function() {
                const password = passwordInput.value;
                
                // Check requirements
                const hasLength = password.length >= 8;
                const hasUpper = /[A-Z]/.test(password);
                const hasLower = /[a-z]/.test(password);
                const hasNumber = /[0-9]/.test(password);
                
                // Update requirement indicators
                updateRequirement('reqLength', hasLength);
                updateRequirement('reqUpper', hasUpper);
                updateRequirement('reqLower', hasLower);
                updateRequirement('reqNumber', hasNumber);
                
                // Calculate strength
                let strength = 0;
                if (hasLength) strength += 25;
                if (hasUpper) strength += 25;
                if (hasLower) strength += 25;
                if (hasNumber) strength += 25;
                
                // Update strength bar
                const strengthBar = document.getElementById('strengthBar');
                strengthBar.style.width = strength + '%';
                
                // Update color based on strength
                if (strength < 50) {
                    strengthBar.style.backgroundColor = '#ef4444'; // Red
                } else if (strength < 75) {
                    strengthBar.style.backgroundColor = '#f59e0b'; // Yellow
                } else {
                    strengthBar.style.backgroundColor = '#10b981'; // Green
                }
            });
            
            // Password match checker
            confirmInput.addEventListener('input', function() {
                const match = passwordInput.value === confirmInput.value && passwordInput.value !== '';
                updateRequirement('reqMatch', match);
            });
            
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
            
            // Step indicator update based on form progress
            const form = document.getElementById('registrationForm');
            const steps = document.querySelectorAll('.step');
            
            form.addEventListener('input', function() {
                const sections = document.querySelectorAll('.form-section');
                let filledSections = 0;
                
                sections.forEach((section, index) => {
                    const requiredInputs = section.querySelectorAll('[required]');
                    let sectionFilled = true;
                    
                    requiredInputs.forEach(input => {
                        if (!input.value.trim()) {
                            sectionFilled = false;
                        }
                    });
                    
                    if (sectionFilled) {
                        filledSections = index + 1;
                    }
                });
                
                // Update step indicators
                steps.forEach((step, index) => {
                    step.classList.remove('active');
                    if (index <= filledSections) {
                        step.classList.add('active');
                    }
                });
            });
            
            // Form validation on submit
            form.addEventListener('submit', function(e) {
                const terms = document.getElementById('terms');
                if (!terms.checked) {
                    e.preventDefault();
                    terms.focus();
                    
                    // Add visual feedback
                    const termsContainer = document.querySelector('.terms-container');
                    termsContainer.style.borderColor = '#ef4444';
                    termsContainer.style.animation = 'shake 0.5s';
                    
                    setTimeout(() => {
                        termsContainer.style.animation = '';
                    }, 500);
                }
            });
            
            // Helper function to update requirement indicators
            function updateRequirement(elementId, met) {
                const element = document.getElementById(elementId);
                const icon = element.querySelector('i');
                
                if (met) {
                    element.classList.remove('unmet');
                    element.classList.add('met');
                    icon.className = 'bi bi-check-circle-fill';
                } else {
                    element.classList.remove('met');
                    element.classList.add('unmet');
                    icon.className = 'bi bi-circle';
                }
            }
            
            // Add shake animation for errors
            const style = document.createElement('style');
            style.textContent = `
                @keyframes shake {
                    0%, 100% { transform: translateX(0); }
                    10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
                    20%, 40%, 60%, 80% { transform: translateX(5px); }
                }
            `;
            document.head.appendChild(style);
            
            // Set maximum date for date of birth (must be at least 3 years old)
            const today = new Date();
            const maxDate = new Date(today.getFullYear() - 3, today.getMonth(), today.getDate());
            document.getElementById('dob').max = maxDate.toISOString().split('T')[0];
            
            // Set minimum date (reasonable limit)
            const minDate = new Date(today.getFullYear() - 80, today.getMonth(), today.getDate());
            document.getElementById('dob').min = minDate.toISOString().split('T')[0];
        });
    </script>
</body>
</html>