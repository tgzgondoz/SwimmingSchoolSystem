<?php
// student/register.php - Professional Student Registration Page
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

                // Log activity (best-effort)
                if (function_exists('logActivity')) {
                    logActivity($conn, $new_id, 'register', 'New student account created');
                }

                // Auto-login the user
                session_regenerate_id(true);
                $_SESSION['user_id'] = $new_id;
                $_SESSION['role'] = 'student';
                $_SESSION['user_name'] = $form_data['name'];
                $_SESSION['last_activity'] = time();

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
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register | AquaFlow Swimming School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0d6efd;
            --primary-dark: #0a58ca;
            --secondary: #6c757d;
            --success: #198754;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #0dcaf0;
            --light: #f8f9fa;
            --dark: #212529;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-400: #ced4da;
            --gray-500: #adb5bd;
            --gray-600: #6c757d;
            --gray-700: #495057;
            --gray-800: #343a40;
            --gray-900: #212529;
            --font-primary: 'Inter', sans-serif;
            --font-heading: 'Poppins', sans-serif;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
            --shadow: 0 4px 12px rgba(0,0,0,0.08);
            --shadow-lg: 0 10px 30px rgba(0,0,0,0.12);
            --border-radius: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            font-family: var(--font-primary);
            background: linear-gradient(135deg, #f0f7ff 0%, #e6f0ff 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 20px;
            color: var(--gray-700);
            line-height: 1.6;
        }

        .registration-wrapper {
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
        }

        .registration-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            display: flex;
            min-height: 700px;
        }

        .left-panel {
            flex: 1;
            background: linear-gradient(145deg, #0d6efd 0%, #0a58ca 100%);
            padding: 60px 40px;
            color: white;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .left-panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="white" opacity="0.05"/></svg>');
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 40px;
        }

        .logo-icon {
            width: 48px;
            height: 48px;
            background: rgba(255,255,255,0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            backdrop-filter: blur(10px);
        }

        .logo-text h2 {
            font-family: var(--font-heading);
            font-weight: 700;
            margin: 0;
            font-size: 1.8rem;
        }

        .logo-text p {
            opacity: 0.9;
            font-size: 0.9rem;
            margin: 0;
        }

        .feature-list {
            list-style: none;
            padding: 0;
            margin: 40px 0;
        }

        .feature-list li {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            font-size: 0.95rem;
        }

        .feature-list i {
            background: rgba(255,255,255,0.15);
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .testimonial {
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 20px;
            margin-top: 40px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .testimonial p {
            font-style: italic;
            margin-bottom: 10px;
        }

        .testimonial-author {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
        }

        .testimonial-author img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255,255,255,0.3);
        }

        .right-panel {
            flex: 1.5;
            padding: 50px 40px;
            overflow-y: auto;
        }

        .header-section {
            margin-bottom: 40px;
        }

        .header-section h1 {
            font-family: var(--font-heading);
            font-weight: 700;
            color: var(--gray-900);
            font-size: 2rem;
            margin-bottom: 8px;
        }

        .header-section p {
            color: var(--gray-600);
            margin-bottom: 0;
        }

        .step-indicator {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
        }

        .step {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--gray-500);
        }

        .step-number {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .step.active .step-number {
            background: var(--primary);
            color: white;
        }

        .step.active .step-text {
            color: var(--primary);
            font-weight: 600;
        }

        .form-section {
            margin-bottom: 40px;
        }

        .form-section h3 {
            font-family: var(--font-heading);
            font-weight: 600;
            font-size: 1.25rem;
            color: var(--gray-800);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--gray-100);
        }

        .form-label {
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: 8px;
            font-size: 0.95rem;
        }

        .form-control, .form-select {
            padding: 12px 16px;
            border: 2px solid var(--gray-300);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
        }

        .input-group-text {
            background: var(--gray-100);
            border: 2px solid var(--gray-300);
            border-right: none;
        }

        .password-toggle {
            cursor: pointer;
            background: none;
            border: 2px solid var(--gray-300);
            border-left: none;
            color: var(--gray-600);
        }

        .password-strength-meter {
            height: 4px;
            background: var(--gray-200);
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }

        .password-strength-fill {
            height: 100%;
            width: 0%;
            transition: var(--transition);
            border-radius: 2px;
        }

        .password-requirements {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 8px;
            margin-top: 12px;
        }

        .requirement {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
        }

        .requirement i {
            font-size: 0.75rem;
        }

        .requirement.valid {
            color: var(--success);
        }

        .requirement.invalid {
            color: var(--gray-500);
        }

        .btn-register {
            background: linear-gradient(145deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 14px 32px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1rem;
            border: none;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
        }

        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .btn-register:active {
            transform: translateY(0);
        }

        .terms-box {
            background: var(--gray-100);
            border-radius: 8px;
            padding: 20px;
            margin: 30px 0;
        }

        .alert-custom {
            border-radius: 8px;
            border: none;
            padding: 16px 20px;
            margin-bottom: 24px;
        }

        .progress-indicator {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 30px;
            position: relative;
        }

        .progress-indicator::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 20px;
            right: 20px;
            height: 2px;
            background: var(--gray-200);
            z-index: 1;
        }

        .progress-step {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: var(--gray-600);
            position: relative;
            z-index: 2;
            transition: var(--transition);
        }

        .progress-step.active {
            background: var(--primary);
            color: white;
            transform: scale(1.1);
        }

        .progress-step.completed {
            background: var(--success);
            color: white;
        }

        @media (max-width: 992px) {
            .registration-card {
                flex-direction: column;
                min-height: auto;
            }
            
            .left-panel {
                padding: 40px 20px;
            }
            
            .right-panel {
                padding: 40px 20px;
            }
        }

        @media (max-width: 768px) {
            .password-requirements {
                grid-template-columns: 1fr;
            }
            
            .step-indicator {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="registration-wrapper">
        <div class="registration-card">
            <!-- Left Panel (Branding & Features) -->
            <div class="left-panel">
                <div class="logo">
                    <div class="logo-icon">
                        <i class="bi bi-droplet"></i>
                    </div>
                    <div class="logo-text">
                        <h2>AquaFlow</h2>
                        <p>Swimming School Excellence</p>
                    </div>
                </div>
                
                <h3 style="font-family: var(--font-heading); margin-bottom: 20px;">Join Our Swimming Community</h3>
                <p style="opacity: 0.9; margin-bottom: 30px;">Become part of a premier swimming school that has trained over 5,000 students since 2010.</p>
                
                <ul class="feature-list">
                    <li>
                        <i class="bi bi-shield-check"></i>
                        <span>Certified Professional Instructors</span>
                    </li>
                    <li>
                        <i class="bi bi-calendar-check"></i>
                        <span>Flexible Class Scheduling</span>
                    </li>
                    <li>
                        <i class="bi bi-graph-up"></i>
                        <span>Progress Tracking Dashboard</span>
                    </li>
                    <li>
                        <i class="bi bi-award"></i>
                        <span>Certification & Achievement Badges</span>
                    </li>
                    <li>
                        <i class="bi bi-chat-heart"></i>
                        <span>Personalized Coaching Approach</span>
                    </li>
                </ul>
                
                <div class="testimonial">
                    <p>"AquaFlow transformed my daughter's fear of water into a passion for swimming. The instructors are amazing!"</p>
                    <div class="testimonial-author">
                        <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(45deg, #ff9a9e, #fad0c4); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">S</div>
                        <div>
                            <strong>Sarah Johnson</strong>
                            <div style="opacity: 0.8; font-size: 0.85rem;">Parent of Student</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Panel (Registration Form) -->
            <div class="right-panel">
                <div class="header-section">
                    <h1>Create Your Account</h1>
                    <p>Start your swimming journey with us. Fill in your details below.</p>
                </div>
                
                <!-- Progress Indicator -->
                <div class="progress-indicator">
                    <div class="progress-step active">1</div>
                    <div class="progress-step">2</div>
                    <div class="progress-step">3</div>
                    <div class="progress-step">4</div>
                </div>
                
                <?php if($success): ?>
                    <div class="alert alert-success alert-custom alert-dismissible fade show" role="alert">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-check-circle-fill fs-4 me-3"></i>
                            <div>
                                <h5 class="alert-heading mb-1">Registration Successful!</h5>
                                <?php echo $success_message; ?>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if(isset($errors['general'])): ?>
                    <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
                            <div><?php echo htmlspecialchars($errors['general']); ?></div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <form method="post" id="registrationForm" novalidate>
                    <!-- Personal Information -->
                    <div class="form-section">
                        <h3><i class="bi bi-person-circle me-2"></i>Personal Information</h3>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                                    <input type="text" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" 
                                           name="name" id="name" value="<?php echo htmlspecialchars($form_data['name']); ?>" 
                                           placeholder="John Smith" required>
                                </div>
                                <?php if(isset($errors['name'])): ?>
                                    <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['name']); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Email Address <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                    <input type="email" class="form-control <?php echo isset($errors['email']) ? 'is-invalid' : ''; ?>" 
                                           name="email" id="email" value="<?php echo htmlspecialchars($form_data['email']); ?>" 
                                           placeholder="john@example.com" required>
                                </div>
                                <?php if(isset($errors['email'])): ?>
                                    <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['email']); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Phone Number</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                    <input type="tel" class="form-control <?php echo isset($errors['phone']) ? 'is-invalid' : ''; ?>" 
                                           name="phone" id="phone" value="<?php echo htmlspecialchars($form_data['phone']); ?>" 
                                           placeholder="(123) 456-7890">
                                </div>
                                <?php if(isset($errors['phone'])): ?>
                                    <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['phone']); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date of Birth <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                                    <input type="date" class="form-control <?php echo isset($errors['dob']) ? 'is-invalid' : ''; ?>" 
                                           name="dob" id="dob" value="<?php echo htmlspecialchars($form_data['dob']); ?>" required>
                                </div>
                                <small class="text-muted">Must be at least 3 years old</small>
                                <?php if(isset($errors['dob'])): ?>
                                    <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['dob']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Emergency Contact -->
                    <div class="form-section">
                        <h3><i class="bi bi-telephone-outbound me-2"></i>Emergency Contact</h3>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Contact Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control <?php echo isset($errors['emergency_contact']) ? 'is-invalid' : ''; ?>" 
                                       name="emergency_contact" id="emergency_contact" 
                                       value="<?php echo htmlspecialchars($form_data['emergency_contact']); ?>" 
                                       placeholder="Emergency contact full name" required>
                                <?php if(isset($errors['emergency_contact'])): ?>
                                    <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['emergency_contact']); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Contact Phone <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-telephone-fill"></i></span>
                                    <input type="tel" class="form-control <?php echo isset($errors['emergency_phone']) ? 'is-invalid' : ''; ?>" 
                                           name="emergency_phone" id="emergency_phone" 
                                           value="<?php echo htmlspecialchars($form_data['emergency_phone']); ?>" 
                                           placeholder="(123) 456-7890" required>
                                </div>
                                <?php if(isset($errors['emergency_phone'])): ?>
                                    <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['emergency_phone']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Swimming Information -->
                    <div class="form-section">
                        <h3><i class="bi bi-water me-2"></i>Swimming Information</h3>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Swimming Level <span class="text-danger">*</span></label>
                                <select class="form-select" name="swimming_level" id="swimming_level" required>
                                    <?php foreach($swimming_levels as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo $form_data['swimming_level'] == $value ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Medical Notes</label>
                                <textarea class="form-control" name="medical_notes" id="medical_notes" 
                                          rows="2" placeholder="Any medical conditions, allergies, or special requirements..."><?php echo htmlspecialchars($form_data['medical_notes']); ?></textarea>
                                <small class="text-muted">Please disclose any relevant health information for your safety</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Account Security -->
                    <div class="form-section">
                        <h3><i class="bi bi-shield-lock me-2"></i>Account Security</h3>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control <?php echo isset($errors['password']) ? 'is-invalid' : ''; ?>" 
                                           name="password" id="password" placeholder="Create a strong password" required>
                                    <button class="btn password-toggle" type="button" id="togglePassword">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="password-strength-meter">
                                    <div class="password-strength-fill" id="passwordStrengthFill"></div>
                                </div>
                                <div class="password-requirements" id="passwordRequirements">
                                    <div class="requirement invalid" id="reqLength">
                                        <i class="bi bi-circle"></i>
                                        <span>8+ characters</span>
                                    </div>
                                    <div class="requirement invalid" id="reqUpper">
                                        <i class="bi bi-circle"></i>
                                        <span>Uppercase letter</span>
                                    </div>
                                    <div class="requirement invalid" id="reqLower">
                                        <i class="bi bi-circle"></i>
                                        <span>Lowercase letter</span>
                                    </div>
                                    <div class="requirement invalid" id="reqNumber">
                                        <i class="bi bi-circle"></i>
                                        <span>Number</span>
                                    </div>
                                </div>
                                <?php if(isset($errors['password'])): ?>
                                    <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['password']); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control <?php echo isset($errors['confirm_password']) ? 'is-invalid' : ''; ?>" 
                                           name="confirm_password" id="confirm_password" placeholder="Confirm your password" required>
                                    <button class="btn password-toggle" type="button" id="toggleConfirmPassword">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="mt-2">
                                    <div class="requirement invalid" id="reqMatch">
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
                    <div class="terms-box">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="terms" id="terms" required>
                            <label class="form-check-label" for="terms">
                                I agree to the <a href="#" class="text-decoration-none">Terms of Service</a>, 
                                <a href="#" class="text-decoration-none">Privacy Policy</a>, and understand that 
                                AquaFlow may contact me regarding my account and swimming progress.
                            </label>
                        </div>
                    </div>
                    
                    <!-- Submit Button -->
                    <button type="submit" class="btn-register">
                        <i class="bi bi-person-plus"></i>
                        Create My Account
                    </button>
                    
                    <div class="text-center mt-4">
                        <p class="text-muted">
                            Already have an account? 
                            <a href="login.php" class="text-decoration-none fw-semibold">Sign In Here</a>
                        </p>
                    </div>
                </form>
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
                this.innerHTML = type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
            });
            
            toggleConfirmPassword.addEventListener('click', function() {
                const type = confirmInput.getAttribute('type') === 'password' ? 'text' : 'password';
                confirmInput.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
            });
            
            // Password strength checker
            passwordInput.addEventListener('input', function() {
                const password = passwordInput.value;
                
                // Requirements
                const hasLength = password.length >= 8;
                const hasUpper = /[A-Z]/.test(password);
                const hasLower = /[a-z]/.test(password);
                const hasNumber = /[0-9]/.test(password);
                
                // Update requirement indicators
                updateRequirement('reqLength', hasLength);
                updateRequirement('reqUpper', hasUpper);
                updateRequirement('reqLower', hasLower);
                updateRequirement('reqNumber', hasNumber);
                
                // Calculate and update strength meter
                let strength = 0;
                if (hasLength) strength += 25;
                if (hasUpper) strength += 25;
                if (hasLower) strength += 25;
                if (hasNumber) strength += 25;
                
                const strengthFill = document.getElementById('passwordStrengthFill');
                strengthFill.style.width = strength + '%';
                
                // Update color based on strength
                if (strength < 50) {
                    strengthFill.style.backgroundColor = '#dc3545';
                } else if (strength < 75) {
                    strengthFill.style.backgroundColor = '#ffc107';
                } else {
                    strengthFill.style.backgroundColor = '#198754';
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
            
            // Progress indicator animation
            const formSections = document.querySelectorAll('.form-section');
            const progressSteps = document.querySelectorAll('.progress-step');
            
            function updateProgress() {
                let currentStep = 0;
                formSections.forEach((section, index) => {
                    const inputs = section.querySelectorAll('input, select, textarea');
                    let allFilled = true;
                    
                    inputs.forEach(input => {
                        if (input.required && !input.value.trim()) {
                            allFilled = false;
                        }
                    });
                    
                    if (allFilled) {
                        currentStep = index + 1;
                    }
                });
                
                progressSteps.forEach((step, index) => {
                    step.classList.remove('active', 'completed');
                    if (index < currentStep) {
                        step.classList.add('completed');
                    } else if (index === currentStep) {
                        step.classList.add('active');
                    }
                });
            }
            
            // Listen to all form inputs for progress updates
            document.querySelectorAll('input, select, textarea').forEach(input => {
                input.addEventListener('input', updateProgress);
                input.addEventListener('change', updateProgress);
            });
            
            // Initialize progress
            updateProgress();
            
            // Form submission enhancement
            const form = document.getElementById('registrationForm');
            form.addEventListener('submit', function(e) {
                const terms = document.getElementById('terms');
                if (!terms.checked) {
                    e.preventDefault();
                    terms.focus();
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-warning alert-custom alert-dismissible fade show mt-3';
                    alertDiv.innerHTML = `
                        <div class="d-flex align-items-center">
                            <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
                            <div>Please agree to the terms and conditions to continue.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    `;
                    form.insertBefore(alertDiv, form.querySelector('.terms-box'));
                    
                    // Animate the terms box for attention
                    const termsBox = document.querySelector('.terms-box');
                    termsBox.style.animation = 'none';
                    setTimeout(() => {
                        termsBox.style.animation = 'pulse 0.5s';
                    }, 10);
                }
            });
            
            // Helper function
            function updateRequirement(elementId, met) {
                const element = document.getElementById(elementId);
                const icon = element.querySelector('i');
                
                if (met) {
                    element.classList.remove('invalid');
                    element.classList.add('valid');
                    icon.className = 'bi bi-check-circle-fill';
                } else {
                    element.classList.remove('valid');
                    element.classList.add('invalid');
                    icon.className = 'bi bi-circle';
                }
            }
            
            // Add CSS animation for pulse effect
            const style = document.createElement('style');
            style.textContent = `
                @keyframes pulse {
                    0% { transform: scale(1); }
                    50% { transform: scale(1.02); box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1); }
                    100% { transform: scale(1); }
                }
                .terms-box {
                    transition: all 0.3s ease;
                }
            `;
            document.head.appendChild(style);
        });
    </script>
</body>
</html>