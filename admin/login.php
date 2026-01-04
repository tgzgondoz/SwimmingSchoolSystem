<?php
// admin/login.php - Professional Admin Login Page
session_start();

// Include database connection
require_once __DIR__ . '/../inc/db.php';

// Initialize variables
$error = '';
$email = '';
$attempts = $_SESSION['admin_login_attempts'] ?? 0;

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
            // Check for admin role only
            $stmt = $conn->prepare('SELECT id, password, role, name, status FROM users WHERE email = ? AND role = "admin" LIMIT 1');
            if (!$stmt) {
                $error = 'Database error. Please try again.';
            } else {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    // Check if account is active
                    if (isset($row['status']) && $row['status'] === 'suspended') {
                        $error = 'Account suspended. Please contact system administrator.';
                    } else {
                        // Verify password
                        if (password_verify($password, $row['password'])) {
                            // Reset attempts on successful login
                            $_SESSION['admin_login_attempts'] = 0;
                            
                            // Regenerate session ID for security
                            session_regenerate_id(true);
                            
                            // Set session variables
                            $_SESSION['user_id'] = $row['id'];
                            $_SESSION['role'] = $row['role'];
                            $_SESSION['user_name'] = $row['name'];
                            $_SESSION['login_time'] = time();
                            $_SESSION['is_admin'] = true;
                            
                            // Set session timeout (2 hours for admin)
                            $_SESSION['last_activity'] = time();
                            $_SESSION['admin_timeout'] = 7200; // 2 hours
                            
                            // Set remember me cookie (7 days for admin)
                            if ($remember) {
                                $token = bin2hex(random_bytes(32));
                                $expiry = time() + (7 * 24 * 60 * 60); // 7 days
                                
                                // Store token in database
                                $token_stmt = $conn->prepare('INSERT INTO remember_tokens (user_id, token, expires_at, is_admin) VALUES (?, ?, ?, 1)');
                                $token_stmt->bind_param('iss', $row['id'], $token, date('Y-m-d H:i:s', $expiry));
                                $token_stmt->execute();
                                $token_stmt->close();
                                
                                setcookie('admin_remember_token', $token, $expiry, '/', '', true, true);
                            }
                            
                            // Redirect to admin dashboard
                            header('Location: index.php');
                            exit();
                        } else {
                            // Increment failed attempts
                            $_SESSION['admin_login_attempts'] = ++$attempts;
                            $error = 'Invalid email or password';
                        }
                    }
                } else {
                    // Increment failed attempts
                    $_SESSION['admin_login_attempts'] = ++$attempts;
                    $error = 'Invalid email or password';
                }
                
                $stmt->close();
            }
        }
    }
}

// Check for admin remember token
if (empty($_SESSION['user_id']) && isset($_COOKIE['admin_remember_token'])) {
    $token = $_COOKIE['admin_remember_token'];
    
    $stmt = $conn->prepare('SELECT u.id, u.name, u.role FROM users u 
                           JOIN remember_tokens rt ON u.id = rt.user_id 
                           WHERE rt.token = ? AND rt.expires_at > NOW() AND u.status = "active" AND u.role = "admin" AND rt.is_admin = 1');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $_SESSION['user_id'] = $row['id'];
        $_SESSION['role'] = $row['role'];
        $_SESSION['user_name'] = $row['name'];
        $_SESSION['is_admin'] = true;
        $_SESSION['last_activity'] = time();
        $_SESSION['admin_timeout'] = 7200;
        
        header('Location: dashboard.php');
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
    <title>Admin Sign In | Elite Swimming Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0d6efd;
            --primary-dark: #0a58ca;
            --primary-light: #dbeafe;
            --accent: #8b5cf6;
            --accent-dark: #7c3aed;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #06b6d4;
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
            --admin-primary: #1e40af;
            --admin-dark: #1e3a8a;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #e0f2fe 100%);
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
            box-shadow: 0 20px 60px rgba(30, 64, 175, 0.15);
            overflow: hidden;
            display: flex;
            min-height: 700px;
            border: 1px solid rgba(30, 64, 175, 0.1);
        }

        .left-panel {
            flex: 1;
            background: linear-gradient(145deg, var(--admin-primary) 0%, var(--admin-dark) 100%);
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
            width: 60px;
            height: 60px;
            background: rgba(255,255,255,0.2);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255,255,255,0.3);
        }

        .logo-text h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 34px;
            font-weight: 800;
            margin: 0;
            letter-spacing: -0.5px;
        }

        .logo-text p {
            opacity: 0.9;
            font-size: 16px;
            margin: 5px 0 0 0;
            font-weight: 500;
        }

        .welcome-text {
            position: relative;
            z-index: 2;
            margin-bottom: 40px;
        }

        .welcome-text h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 38px;
            font-weight: 800;
            margin-bottom: 20px;
            line-height: 1.2;
        }

        .welcome-text p {
            font-size: 18px;
            opacity: 0.9;
            line-height: 1.6;
            max-width: 500px;
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
            width: 52px;
            height: 52px;
            background: rgba(255,255,255,0.15);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
            border: 1px solid rgba(255,255,255,0.2);
        }

        .feature-text h4 {
            font-size: 17px;
            font-weight: 700;
            margin: 0 0 5px 0;
        }

        .feature-text p {
            font-size: 15px;
            opacity: 0.9;
            margin: 0;
        }

        .security-badge {
            position: relative;
            z-index: 2;
            background: rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 25px;
            margin-top: 40px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .security-badge .badge-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }

        .badge-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(45deg, #60a5fa, #93c5fd);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .badge-info h5 {
            font-size: 16px;
            font-weight: 700;
            margin: 0;
        }

        .badge-info p {
            font-size: 14px;
            opacity: 0.9;
            margin: 0;
        }

        .security-features {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .security-features li {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            font-size: 14px;
        }

        .security-features li i {
            color: #10b981;
            font-size: 12px;
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
            font-weight: 800;
            color: var(--admin-primary);
            margin-bottom: 10px;
        }

        .login-header p {
            color: var(--gray-600);
            font-size: 16px;
            font-weight: 500;
        }

        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(90deg, var(--admin-primary), var(--admin-dark));
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin-top: 10px;
        }

        .alert-custom {
            border-radius: 12px;
            border: none;
            padding: 18px 22px;
            margin-bottom: 30px;
            animation: slideIn 0.5s ease;
            border-left: 4px solid;
        }

        .alert-custom.alert-danger {
            background: linear-gradient(90deg, #fee, #fdd);
            border-left-color: var(--danger);
        }

        .alert-custom.alert-warning {
            background: linear-gradient(90deg, #fffbeb, #fef3c7);
            border-left-color: var(--warning);
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
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 10px;
            font-size: 15px;
            display: block;
        }

        .input-group {
            position: relative;
        }

        .form-control {
            padding: 16px 18px;
            border: 2px solid var(--gray-200);
            border-radius: 14px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: white;
            font-weight: 500;
        }

        .form-control:focus {
            border-color: var(--admin-primary);
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
            outline: none;
        }

        .input-icon {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-500);
            pointer-events: none;
        }

        .password-toggle {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray-500);
            cursor: pointer;
            transition: color 0.3s ease;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .password-toggle:hover {
            color: var(--admin-primary);
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
            background-color: var(--admin-primary);
            border-color: var(--admin-primary);
        }

        .form-check-input:focus {
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.25);
        }

        .forgot-password {
            color: var(--admin-primary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .forgot-password:hover {
            color: var(--admin-dark);
            text-decoration: underline;
        }

        .btn-login {
            background: linear-gradient(90deg, var(--admin-primary), var(--admin-dark));
            color: white;
            padding: 18px;
            border: none;
            border-radius: 14px;
            font-weight: 700;
            font-size: 17px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transition: all 0.3s ease;
            cursor: pointer;
            margin-bottom: 25px;
            letter-spacing: 0.5px;
        }

        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(30, 64, 175, 0.3);
        }

        .btn-login:active {
            transform: translateY(-1px);
        }

        .btn-login:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        .demo-credentials {
            background: var(--gray-50);
            border-radius: 12px;
            padding: 20px;
            margin-top: 30px;
            border-left: 4px solid var(--info);
        }

        .demo-credentials h6 {
            color: var(--gray-800);
            font-weight: 700;
            margin-bottom: 10px;
            font-size: 15px;
        }

        .demo-credentials p {
            color: var(--gray-600);
            font-size: 14px;
            margin-bottom: 5px;
        }

        .demo-credentials .credentials {
            background: white;
            border-radius: 8px;
            padding: 12px;
            margin-top: 10px;
            font-family: 'Consolas', monospace;
            font-size: 13px;
            border: 1px solid var(--gray-200);
        }

        .back-to-home {
            text-align: center;
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid var(--gray-200);
        }

        .back-to-home a {
            color: var(--gray-700);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .back-to-home a:hover {
            color: var(--admin-primary);
            transform: translateX(-5px);
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 25px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-200);
        }

        .footer-links a {
            color: var(--gray-500);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .footer-links a:hover {
            color: var(--admin-primary);
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
                display: none; /* Hide left panel on mobile */
            }
            
            .right-panel {
                padding: 40px 30px;
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
            
            .footer-links {
                flex-wrap: wrap;
                gap: 15px;
            }
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

        /* Loading spinner */
        .spinner-border {
            width: 18px;
            height: 18px;
            border-width: 2px;
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-container">
            <!-- Left Panel - Admin Branding & Features -->
            <div class="left-panel">
                <div class="logo-area">
                    <div class="logo">
                        <div class="logo-icon">
                            <i class="bi bi-shield-lock"></i>
                        </div>
                        <div class="logo-text">
                            <h1>Elite Swimming Academy</h1>
                            <p>Administration Portal</p>
                        </div>
                    </div>
                </div>

                <div class="welcome-text">
                    <h2>Admin Access</h2>
                    <p>Secure administrative access to manage students, instructors, classes, and school operations. Ensure smooth operation of AquaFlow Swimming School.</p>
                </div>

                <div class="features-list">
                    <div class="feature">
                        <div class="feature-icon">
                            <i class="bi bi-people"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Manage Users</h4>
                            <p>Control student and instructor accounts</p>
                        </div>
                    </div>
                    
                    <div class="feature">
                        <div class="feature-icon">
                            <i class="bi bi-calendar-week"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Class Scheduling</h4>
                            <p>Schedule and manage swimming classes</p>
                        </div>
                    </div>
                    
                    <div class="feature">
                        <div class="feature-icon">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Analytics & Reports</h4>
                            <p>Track performance and generate insights</p>
                        </div>
                    </div>
                    
                    <div class="feature">
                        <div class="feature-icon">
                            <i class="bi bi-gear"></i>
                        </div>
                        <div class="feature-text">
                            <h4>System Settings</h4>
                            <p>Configure school settings and preferences</p>
                        </div>
                    </div>
                </div>

                
            </div>

            <!-- Right Panel - Admin Login Form -->
            <div class="right-panel">
                <div class="login-header">
                    <h2>Admin Sign In</h2>
                    <p>Enter administrator credentials to access the dashboard</p>
                    <div class="admin-badge">
                        <i class="bi bi-shield-lock"></i>
                        <span>Restricted Access</span>
                    </div>
                </div>

                <?php if($error): ?>
                    <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-octagon"></i>
                        <div>
                            <strong>Access Denied</strong>
                            <div class="mt-1"><?php echo htmlspecialchars($error); ?></div>
                            <?php if($attempts > 0): ?>
                                <div class="small mt-2">Security alert: <?php echo $attempts; ?> failed attempt(s)</div>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="post" id="adminLoginForm" novalidate>
                    <div class="form-group">
                        <label for="adminEmail" class="form-label">
                            <i class="bi bi-person-badge me-1"></i>Admin Email
                        </label>
                        <div class="input-group">
                            <input type="email" 
                                   class="form-control" 
                                   name="email" 
                                   id="adminEmail" 
                                   value="<?php echo htmlspecialchars($email); ?>" 
                                   placeholder="admin@aquaflow.com"
                                   required
                                   <?php echo $attempts >= 5 ? 'disabled' : ''; ?>>
                            <div class="input-icon">
                                <i class="bi bi-envelope-at"></i>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="adminPassword" class="form-label">
                            <i class="bi bi-key me-1"></i>Admin Password
                        </label>
                        <div class="input-group">
                            <input type="password" 
                                   class="form-control" 
                                   name="password" 
                                   id="adminPassword" 
                                   placeholder="••••••••"
                                   required
                                   <?php echo $attempts >= 5 ? 'disabled' : ''; ?>>
                            <button type="button" class="password-toggle" id="togglePassword">
                                <i class="bi bi-eye"></i>
                            </button>
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
                                <i class="bi bi-clock me-1"></i>Remember for 7 days
                            </label>
                        </div>
                        <a href="../forgot-password.php?type=admin" class="forgot-password">
                            <i class="bi bi-lock me-1"></i>Forgot Password?
                        </a>
                    </div>

                    <button type="submit" 
                            class="btn-login"
                            id="adminLoginButton"
                            <?php echo $attempts >= 5 ? 'disabled' : ''; ?>>
                        <i class="bi bi-shield-lock"></i>
                        <span>Access Admin Dashboard</span>
                    </button>

                    
                </form>

                <div class="back-to-home">
                    <a href="../">
                        <i class="bi bi-arrow-left"></i>
                        Back to Homepage
                    </a>
                </div>

                <div class="footer-links">
                    <a href="privacy.php"><i class="bi bi-shield-check"></i> Privacy Policy</a>
                    <a href="terms.php"><i class="bi bi-file-text"></i> Terms of Service</a>
                    <a href="../contact.php"><i class="bi bi-headset"></i> Support</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const adminEmail = document.getElementById('adminEmail');
            const adminPassword = document.getElementById('adminPassword');
            const togglePassword = document.getElementById('togglePassword');
            const adminLoginForm = document.getElementById('adminLoginForm');
            const adminLoginButton = document.getElementById('adminLoginButton');
            
            let attempts = <?php echo $attempts; ?>;
            const maxAttempts = 5;
            
            // Password toggle functionality
            togglePassword.addEventListener('click', function() {
                const type = adminPassword.getAttribute('type') === 'password' ? 'text' : 'password';
                adminPassword.setAttribute('type', type);
                this.innerHTML = type === 'password' ? 
                    '<i class="bi bi-eye"></i>' : 
                    '<i class="bi bi-eye-slash"></i>';
            });
            
            // Form validation
            adminLoginForm.addEventListener('submit', function(e) {
                if (attempts >= maxAttempts) {
                    e.preventDefault();
                    showError('Security lock: Maximum attempts reached. Contact system administrator.');
                    return;
                }
                
                if (!adminEmail.value || !adminPassword.value) {
                    e.preventDefault();
                    showError('Please fill in all required fields');
                    return;
                }
                
                // Validate email format
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(adminEmail.value)) {
                    e.preventDefault();
                    showError('Please enter a valid email address');
                    return;
                }
                
                // Show loading state
                const originalHTML = adminLoginButton.innerHTML;
                adminLoginButton.innerHTML = `
                    <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                    Verifying Credentials...
                `;
                adminLoginButton.disabled = true;
                
                // Restore button after 3 seconds (fallback)
                setTimeout(() => {
                    adminLoginButton.innerHTML = originalHTML;
                    adminLoginButton.disabled = false;
                }, 3000);
            });
            
            // Rate limiting indicator
            if (attempts > 0) {
                const attemptsLeft = maxAttempts - attempts;
                if (attemptsLeft > 0) {
                    showWarning(`Security Notice: ${attempts} failed attempt(s). ${attemptsLeft} attempt(s) remaining before lockout.`);
                } else {
                    showError('Account temporarily locked. Please try again later or contact administrator.');
                }
            }
            
            // Auto-focus email field
            if (attempts < maxAttempts) {
                setTimeout(() => {
                    if (!adminEmail.value) {
                        adminEmail.focus();
                    } else {
                        adminPassword.focus();
                    }
                }, 100);
            }
            
            // Auto-fill demo credentials on click (optional)
            adminEmail.addEventListener('click', function() {
                if (!this.value) {
                    this.value = 'admin@aquaflow.com';
                    adminPassword.value = 'password';
                    adminPassword.focus();
                }
            });
            
            // Helper functions
            function showError(message) {
                // Create or update alert
                let alertDiv = document.querySelector('.alert.alert-danger');
                if (!alertDiv) {
                    alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-danger alert-custom alert-dismissible fade show';
                    alertDiv.innerHTML = `
                        <i class="bi bi-exclamation-octagon"></i>
                        <div>
                            <strong>Access Denied</strong>
                            <div class="mt-1">${message}</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    `;
                    adminLoginForm.parentNode.insertBefore(alertDiv, adminLoginForm);
                } else {
                    alertDiv.querySelector('strong').nextElementSibling.textContent = message;
                }
                
                // Shake form
                adminLoginForm.classList.add('shake');
                setTimeout(() => {
                    adminLoginForm.classList.remove('shake');
                }, 500);
            }
            
            function showWarning(message) {
                const warningDiv = document.createElement('div');
                warningDiv.className = 'alert alert-warning alert-custom alert-dismissible fade show mt-3';
                warningDiv.innerHTML = `
                    <i class="bi bi-exclamation-triangle"></i>
                    <div>
                        <strong>Security Alert</strong>
                        <div class="mt-1">${message}</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                adminLoginForm.parentNode.insertBefore(warningDiv, adminLoginForm);
            }
            
            
        });
    </script>
</body>
</html>