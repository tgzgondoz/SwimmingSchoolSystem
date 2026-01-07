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
    <title>Admin Login | Elite Swimming Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
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
            background-color: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            color: var(--gray-800);
            line-height: 1.6;
        }

        .login-wrapper {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            border: 1px solid var(--gray-200);
        }

        .login-header {
            padding: 32px 32px 24px;
            text-align: center;
            border-bottom: 1px solid var(--gray-200);
            background-color: #f8fafc;
        }

        .login-header h2 {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 8px;
        }

        .login-header p {
            color: var(--gray-600);
            font-size: 14px;
            font-weight: 400;
        }

        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background-color: #1e40af;
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 12px;
        }

        .login-body {
            padding: 32px;
        }

        .alert-custom {
            border-radius: 8px;
            border: 1px solid;
            border-left-width: 4px;
            padding: 16px;
            margin-bottom: 24px;
        }

        .alert-custom.alert-danger {
            background-color: #fef2f2;
            border-color: #fecaca;
            border-left-color: #ef4444;
            color: #991b1b;
        }

        .alert-custom i {
            font-size: 16px;
            margin-right: 8px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 8px;
            font-size: 14px;
            display: block;
        }

        .input-group {
            position: relative;
        }

        .form-control {
            padding: 12px 16px;
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s ease;
            background: white;
            font-weight: 400;
            width: 100%;
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1);
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
            color: var(--gray-500);
            cursor: pointer;
            transition: color 0.2s ease;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .password-toggle:hover {
            color: var(--primary);
        }

        .options-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .form-check {
            margin: 0;
        }

        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .form-check-input:focus {
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1);
        }

        .form-check-label {
            font-size: 14px;
            color: var(--gray-700);
        }

        .forgot-password {
            color: var(--primary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .forgot-password:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .btn-login {
            background-color: var(--primary);
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 15px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background-color 0.2s ease;
            cursor: pointer;
            margin-bottom: 24px;
        }

        .btn-login:hover {
            background-color: var(--primary-dark);
        }

        .btn-login:disabled {
            background-color: var(--gray-400);
            cursor: not-allowed;
        }

        .back-to-home {
            text-align: center;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--gray-200);
        }

        .back-to-home a {
            color: var(--gray-700);
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: color 0.2s ease;
            font-size: 14px;
        }

        .back-to-home a:hover {
            color: var(--primary);
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-200);
        }

        .footer-links a {
            color: var(--gray-500);
            text-decoration: none;
            font-size: 12px;
            font-weight: 400;
            transition: color 0.2s ease;
        }

        .footer-links a:hover {
            color: var(--primary);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .login-wrapper {
                padding: 16px;
                max-width: 100%;
            }
            
            .login-body {
                padding: 24px;
            }
            
            .options-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            
            .footer-links {
                flex-wrap: wrap;
                gap: 12px;
            }
        }

        /* Loading spinner */
        .spinner-border {
            width: 16px;
            height: 16px;
            border-width: 2px;
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-container">
            <div class="login-header">
                <h2>Admin Login</h2>
                <p>Enter administrator credentials to continue</p>
                <div class="admin-badge">
                    <i class="bi bi-shield-lock"></i>
                    <span>Restricted Access</span>
                </div>
            </div>

            <div class="login-body">
                <?php if($error): ?>
                    <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-octagon"></i>
                        <div style="display: inline-block;">
                            <strong style="display: block; margin-bottom: 4px;">Access Denied</strong>
                            <div style="font-size: 14px;"><?php echo htmlspecialchars($error); ?></div>
                            <?php if($attempts > 0): ?>
                                <div class="small mt-2" style="font-size: 12px; color: #7f1d1d;">Security alert: <?php echo $attempts; ?> failed attempt(s)</div>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="font-size: 12px;"></button>
                    </div>
                <?php endif; ?>

                <form method="post" id="adminLoginForm" novalidate>
                    <div class="form-group">
                        <label for="adminEmail" class="form-label">
                            <i class="bi bi-envelope me-1"></i>Email Address
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
                                <i class="bi bi-person-badge"></i>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="adminPassword" class="form-label">
                            <i class="bi bi-lock me-1"></i>Password
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
                                Remember me for 7 days
                            </label>
                        </div>
                        <a href="../forgot-password.php?type=admin" class="forgot-password">
                            Forgot Password?
                        </a>
                    </div>

                    <button type="submit" 
                            class="btn-login"
                            id="adminLoginButton"
                            <?php echo $attempts >= 5 ? 'disabled' : ''; ?>>
                        <i class="bi bi-shield-lock"></i>
                        <span>Sign In</span>
                    </button>
                </form>

                <div class="back-to-home">
                    <a href="../">
                        <i class="bi bi-arrow-left"></i>
                        Back to Homepage
                    </a>
                </div>

                <div class="footer-links">
                    <a href="privacy.php">Privacy Policy</a>
                    <a href="terms.php">Terms of Service</a>
                    <a href="../contact.php">Support</a>
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
                    Signing in...
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
                    showWarning(`${attempts} failed attempt(s). ${attemptsLeft} attempt(s) remaining before lockout.`);
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
            
            // Helper functions
            function showError(message) {
                // Create or update alert
                let alertDiv = document.querySelector('.alert.alert-danger');
                if (!alertDiv) {
                    alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-danger alert-custom alert-dismissible fade show';
                    alertDiv.innerHTML = `
                        <i class="bi bi-exclamation-octagon"></i>
                        <div style="display: inline-block;">
                            <strong style="display: block; margin-bottom: 4px;">Access Denied</strong>
                            <div style="font-size: 14px;">${message}</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="font-size: 12px;"></button>
                    `;
                    adminLoginForm.parentNode.insertBefore(alertDiv, adminLoginForm);
                }
            }
            
            function showWarning(message) {
                const warningDiv = document.createElement('div');
                warningDiv.className = 'alert alert-warning alert-custom alert-dismissible fade show mt-3';
                warningDiv.innerHTML = `
                    <i class="bi bi-exclamation-triangle"></i>
                    <div style="display: inline-block;">
                        <strong style="display: block; margin-bottom: 4px;">Security Alert</strong>
                        <div style="font-size: 14px;">${message}</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="font-size: 12px;"></button>
                `;
                adminLoginForm.parentNode.insertBefore(warningDiv, adminLoginForm);
            }
        });
    </script>
</body>
</html>