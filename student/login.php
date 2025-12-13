<?php
// student/login.php - Professional Login Page (No Demo)
session_start();

// Include database connection
require_once __DIR__ . '/../inc/db.php';

// Initialize variables
$error = '';
$email = '';
$attempts = $_SESSION['login_attempts'] ?? 0;

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check rate limiting
    if ($attempts >= 5) {
        $error = 'Too many failed attempts. Please try again in 15 minutes.';
    } else {
        // Validate and sanitize inputs
        $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);
        
        // Validate inputs
        if (empty($email) || empty($password)) {
            $error = 'Please enter both email and password';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address';
        } else {
            // Check for both student and admin roles
            $stmt = $conn->prepare('SELECT id, password, role, name, status FROM users WHERE email = ? AND role IN ("student", "admin") LIMIT 1');
            if (!$stmt) {
                $error = 'Database error. Please try again.';
            } else {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    // Check if account is active
                    if (isset($row['status']) && $row['status'] === 'suspended') {
                        $error = 'Account suspended. Please contact support.';
                    } else {
                        // Verify password
                        if (password_verify($password, $row['password'])) {
                            // Reset attempts on successful login
                            $_SESSION['login_attempts'] = 0;
                            
                            // Regenerate session ID for security
                            session_regenerate_id(true);
                            
                            // Set session variables
                            $_SESSION['user_id'] = $row['id'];
                            $_SESSION['role'] = $row['role'];
                            $_SESSION['user_name'] = $row['name'];
                            $_SESSION['login_time'] = time();
                            
                            // Set session timeout (1 hour)
                            $_SESSION['last_activity'] = time();
                            
                            // Set remember me cookie (30 days)
                            if ($remember) {
                                $token = bin2hex(random_bytes(32));
                                $expiry = time() + (30 * 24 * 60 * 60);
                                
                                // Store token in database
                                $token_stmt = $conn->prepare('INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, ?)');
                                $token_stmt->bind_param('iss', $row['id'], $token, date('Y-m-d H:i:s', $expiry));
                                $token_stmt->execute();
                                $token_stmt->close();
                                
                                setcookie('remember_token', $token, $expiry, '/', '', true, true);
                            }
                            
                            // Redirect based on role
                            if ($row['role'] === 'admin') {
                                header('Location: ../admin/index.php');
                            } else {
                                header('Location: index.php');
                            }
                            exit();
                        } else {
                            // Increment failed attempts
                            $_SESSION['login_attempts'] = ++$attempts;
                            $error = 'Invalid email or password';
                        }
                    }
                } else {
                    // Increment failed attempts
                    $_SESSION['login_attempts'] = ++$attempts;
                    $error = 'Invalid email or password';
                }
                
                $stmt->close();
            }
        }
    }
}

// Check for remember token
if (empty($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    
    $stmt = $conn->prepare('SELECT u.id, u.name, u.role FROM users u 
                           JOIN remember_tokens rt ON u.id = rt.user_id 
                           WHERE rt.token = ? AND rt.expires_at > NOW() AND u.status = "active"');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $_SESSION['user_id'] = $row['id'];
        $_SESSION['role'] = $row['role'];
        $_SESSION['user_name'] = $row['name'];
        $_SESSION['last_activity'] = time();
        
        if ($row['role'] === 'admin') {
            header('Location: ../admin/index.php');
        } else {
            header('Location: index.php');
        }
        exit();
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | AquaFlow Swimming School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0d6efd;
            --primary-dark: #0a58ca;
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
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e6f0ff 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            color: var(--gray-800);
            line-height: 1.6;
        }

        .login-wrapper {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(37, 99, 235, 0.1);
            overflow: hidden;
            display: flex;
            min-height: 700px;
        }

        .left-panel {
            flex: 1;
            background: linear-gradient(145deg, var(--primary) 0%, var(--primary-dark) 100%);
            padding: 60px 50px;
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
            top: -50%;
            right: -30%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
        }

        .left-panel::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -20%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
        }

        .logo-area {
            position: relative;
            z-index: 2;
            margin-bottom: 60px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .logo-icon {
            width: 56px;
            height: 56px;
            background: rgba(255,255,255,0.2);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            backdrop-filter: blur(10px);
        }

        .logo-text h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 32px;
            font-weight: 700;
            margin: 0;
            letter-spacing: -0.5px;
        }

        .logo-text p {
            opacity: 0.9;
            font-size: 15px;
            margin: 5px 0 0 0;
        }

        .welcome-text {
            position: relative;
            z-index: 2;
            margin-bottom: 40px;
        }

        .welcome-text h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 15px;
            line-height: 1.2;
        }

        .welcome-text p {
            font-size: 17px;
            opacity: 0.9;
            line-height: 1.6;
        }

        .features-list {
            position: relative;
            z-index: 2;
            margin-top: 40px;
        }

        .feature {
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
        }

        .testimonial {
            position: relative;
            z-index: 2;
            background: rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 25px;
            margin-top: 40px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .testimonial p {
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
            background: linear-gradient(45deg, #60a5fa, #93c5fd);
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
            flex: 1;
            padding: 60px 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-header {
            margin-bottom: 40px;
        }

        .login-header h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 36px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 10px;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .login-header p {
            color: var(--gray-600);
            font-size: 16px;
        }

        .alert-custom {
            border-radius: 12px;
            border: none;
            padding: 16px 20px;
            margin-bottom: 30px;
            animation: slideIn 0.5s ease;
        }

        .alert-custom i {
            font-size: 20px;
            margin-right: 12px;
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

        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        .form-label {
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: 8px;
            font-size: 14px;
            display: block;
        }

        .input-group {
            position: relative;
        }

        .form-control {
            padding: 15px 16px;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: var(--gray-50);
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
            background: white;
        }

        .input-icon {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
            pointer-events: none;
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray-400);
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: var(--primary);
        }

        .options-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .form-check {
            margin: 0;
        }

        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .forgot-password {
            color: var(--primary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .forgot-password:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .btn-login {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 16px;
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
            margin-bottom: 25px;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(13, 110, 253, 0.3);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-login:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        .register-section {
            text-align: center;
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid var(--gray-200);
        }

        .register-section h5 {
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 15px;
            font-size: 16px;
        }

        .register-section p {
            color: var(--gray-600);
            margin-bottom: 20px;
            font-size: 15px;
        }

        .btn-signup {
            background: white;
            color: var(--primary);
            padding: 14px 28px;
            border: 2px solid var(--primary);
            border-radius: 10px;
            font-weight: 600;
            font-size: 15px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-signup:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(13, 110, 253, 0.2);
            text-decoration: none;
        }

        .register-link {
            text-align: center;
            margin-top: 25px;
            color: var(--gray-600);
            font-size: 15px;
        }

        .register-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .register-link a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-200);
        }

        .footer-links a {
            color: var(--gray-500);
            text-decoration: none;
            font-size: 13px;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: var(--primary);
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .login-container {
                flex-direction: column;
                min-height: auto;
            }
            
            .left-panel, .right-panel {
                padding: 40px 30px;
            }
            
            .left-panel {
                display: none; /* Hide left panel on mobile for better focus */
            }
        }

        @media (max-width: 768px) {
            .login-wrapper {
                padding: 10px;
            }
            
            .right-panel {
                padding: 30px 20px;
            }
            
            .login-header h2 {
                font-size: 28px;
            }
            
            .options-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
        }

        /* Password strength warning */
        .password-strength {
            height: 4px;
            background: var(--gray-200);
            border-radius: 2px;
            margin-top: 8px;
            display: none;
        }

        .strength-bar {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }

        /* Animation for error */
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }

        .shake {
            animation: shake 0.5s;
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-container">
            <!-- Left Panel - Branding & Features -->
            <div class="left-panel">
                <div class="logo-area">
                    <div class="logo">
                        <div class="logo-icon">
                            <i class="bi bi-droplet"></i>
                        </div>
                        <div class="logo-text">
                            <h1>AquaFlow</h1>
                            <p>Swimming Excellence</p>
                        </div>
                    </div>
                </div>

                <div class="welcome-text">
                    <h2>Welcome Back!</h2>
                    <p>Sign in to continue your swimming journey. Track your progress, book classes, and achieve your swimming goals with AquaFlow.</p>
                </div>

                <div class="features-list">
                    <div class="feature">
                        <div class="feature-icon">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Progress Tracking</h4>
                            <p>Monitor your improvement with detailed analytics</p>
                        </div>
                    </div>
                    
                    <div class="feature">
                        <div class="feature-icon">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Easy Booking</h4>
                            <p>Schedule classes with certified instructors</p>
                        </div>
                    </div>
                    
                    <div class="feature">
                        <div class="feature-icon">
                            <i class="bi bi-award"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Achieve Goals</h4>
                            <p>Earn certifications and recognition</p>
                        </div>
                    </div>
                </div>

                <div class="testimonial">
                    <p>"Thanks to AquaFlow, I went from fearing deep water to swimming 50 meters non-stop. The progress tracking kept me motivated!"</p>
                    <div class="testimonial-author">
                        <div class="author-avatar">S</div>
                        <div class="author-info">
                            <h5>Sarah Johnson</h5>
                            <p>Student for 1 year</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Panel - Login Form -->
            <div class="right-panel">
                <div class="login-header">
                    <h2>Sign In to Account</h2>
                    <p>Enter your credentials to access your dashboard</p>
                </div>

                <?php if($error): ?>
                    <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($error); ?>
                        <?php if($attempts > 0): ?>
                            <div class="small mt-1">Attempts remaining: <?php echo max(0, 5 - $attempts); ?></div>
                        <?php endif; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="post" id="loginForm" novalidate>
                    <div class="form-group">
                        <label for="email" class="form-label">Email Address</label>
                        <div class="input-group">
                            <input type="email" 
                                   class="form-control" 
                                   name="email" 
                                   id="email" 
                                   value="<?php echo htmlspecialchars($email); ?>" 
                                   placeholder="Enter your email"
                                   required
                                   <?php echo $attempts >= 5 ? 'disabled' : ''; ?>>
                            <div class="input-icon">
                                <i class="bi bi-envelope"></i>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group">
                            <input type="password" 
                                   class="form-control" 
                                   name="password" 
                                   id="password" 
                                   placeholder="Enter your password"
                                   required
                                   <?php echo $attempts >= 5 ? 'disabled' : ''; ?>>
                            <button type="button" class="password-toggle" id="togglePassword">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        
                        <!-- Password strength indicator -->
                        <div class="password-strength" id="passwordStrength">
                            <div class="strength-bar" id="strengthBar"></div>
                        </div>
                    </div>

                    <div class="options-row">
                        <div class="form-check">
                            <input class="form-check-input" 
                                   type="checkbox" 
                                   name="remember" 
                                   id="remember"
                                   <?php echo $attempts >= 5 ? 'disabled' : ''; ?>>
                            <label class="form-check-label" for="remember">
                                Remember me for 30 days
                            </label>
                        </div>
                        <a href="forgot-password.php" class="forgot-password">
                            Forgot Password?
                        </a>
                    </div>

                    <button type="submit" 
                            class="btn-login"
                            id="loginButton"
                            <?php echo $attempts >= 5 ? 'disabled' : ''; ?>>
                        <i class="bi bi-box-arrow-in-right"></i>
                        Sign In to Dashboard
                    </button>
                </form>

                <!-- New Account Section -->
                <div class="register-section">
                    <h5>New to AquaFlow?</h5>
                    <p>Join our swimming community and start your journey today. Get access to professional instructors, progress tracking, and flexible scheduling.</p>
                    <a href="register.php" class="btn-signup">
                        <i class="bi bi-person-plus"></i>
                        Create New Account
                    </a>
                </div>

                <div class="footer-links">
                    <a href="../">Homepage</a>
                    <a href="terms.php">Terms of Service</a>
                    <a href="privacy.php">Privacy Policy</a>
                    <a href="contact.php">Contact Support</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const emailInput = document.getElementById('email');
            const passwordInput = document.getElementById('password');
            const togglePassword = document.getElementById('togglePassword');
            const loginForm = document.getElementById('loginForm');
            const loginButton = document.getElementById('loginButton');
            const strengthBar = document.getElementById('strengthBar');
            const passwordStrength = document.getElementById('passwordStrength');
            
            let attempts = <?php echo $attempts; ?>;
            const maxAttempts = 5;
            
            // Password toggle functionality
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.innerHTML = type === 'password' ? 
                    '<i class="bi bi-eye"></i>' : 
                    '<i class="bi bi-eye-slash"></i>';
            });
            
            // Password strength checker
            passwordInput.addEventListener('input', function() {
                checkPasswordStrength(this.value);
            });
            
            function checkPasswordStrength(password) {
                let strength = 0;
                
                if (password.length >= 8) strength += 25;
                if (/[A-Z]/.test(password)) strength += 25;
                if (/[a-z]/.test(password)) strength += 25;
                if (/[0-9]/.test(password)) strength += 25;
                
                // Show strength bar only if password is weak
                if (strength < 50 && password.length > 0) {
                    passwordStrength.style.display = 'block';
                    strengthBar.style.width = strength + '%';
                    
                    if (strength < 25) {
                        strengthBar.style.backgroundColor = '#ef4444'; // Red
                    } else {
                        strengthBar.style.backgroundColor = '#f59e0b'; // Yellow
                    }
                } else {
                    passwordStrength.style.display = 'none';
                }
            }
            
            // Form validation
            loginForm.addEventListener('submit', function(e) {
                if (attempts >= maxAttempts) {
                    e.preventDefault();
                    showError('Too many failed attempts. Please try again later.');
                    return;
                }
                
                if (!emailInput.value || !passwordInput.value) {
                    e.preventDefault();
                    showError('Please fill in all required fields');
                    return;
                }
                
                // Show loading state
                loginButton.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Signing In...';
                loginButton.disabled = true;
            });
            
            // Rate limiting indicator
            if (attempts > 0) {
                const attemptsLeft = maxAttempts - attempts;
                if (attemptsLeft > 0) {
                    showWarning(`Failed attempts: ${attempts}. ${attemptsLeft} attempt(s) remaining.`);
                }
            }
            
            // Auto-focus email field
            if (attempts < maxAttempts) {
                setTimeout(() => {
                    if (!emailInput.value) {
                        emailInput.focus();
                    } else {
                        passwordInput.focus();
                    }
                }, 100);
            }
            
            // Helper functions
            function showError(message) {
                // Create or update alert
                let alertDiv = document.querySelector('.alert.alert-danger');
                if (!alertDiv) {
                    alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-danger alert-custom alert-dismissible fade show';
                    alertDiv.innerHTML = `
                        <i class="bi bi-exclamation-triangle"></i>
                        ${message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    `;
                    loginForm.parentNode.insertBefore(alertDiv, loginForm);
                } else {
                    alertDiv.querySelector('i').nextSibling.textContent = message;
                }
                
                // Shake form
                loginForm.classList.add('shake');
                setTimeout(() => {
                    loginForm.classList.remove('shake');
                }, 500);
            }
            
            function showWarning(message) {
                const warningDiv = document.createElement('div');
                warningDiv.className = 'alert alert-warning alert-custom alert-dismissible fade show mt-3';
                warningDiv.innerHTML = `
                    <i class="bi bi-exclamation-triangle"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                loginForm.parentNode.insertBefore(warningDiv, loginForm);
            }
        });
    </script>
</body>
</html>