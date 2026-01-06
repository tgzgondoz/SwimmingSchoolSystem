<?php
// student/login.php - Professional Login Page (No Demo)
session_start();

require_once __DIR__ . '/../inc/db.php';

$error = '';
$email = '';
$attempts = $_SESSION['login_attempts'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($attempts >= 5) {
        $error = 'Too many failed attempts. Please try again in 15 minutes.';
    } else {
        $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'] ?? '';
        
        if (empty($email) || empty($password)) {
            $error = 'Please enter both email and password';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address';
        } else {
            $stmt = $conn->prepare('SELECT id, password, role, name FROM users WHERE email = ? AND role IN ("student", "admin") LIMIT 1');
            if (!$stmt) {
                $error = 'Database error. Please try again.';
            } else {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    if (password_verify($password, $row['password'])) {
                        $_SESSION['login_attempts'] = 0;
                        
                        session_regenerate_id(true);
                        
                        $_SESSION['user_id'] = $row['id'];
                        $_SESSION['role'] = $row['role'];
                        $_SESSION['user_name'] = $row['name'];
                        $_SESSION['login_time'] = time();
                        
                        if ($row['role'] === 'admin') {
                            header('Location: ../admin/index.php');
                        } else {
                            header('Location: index.php');
                        }
                        exit();
                    } else {
                        $_SESSION['login_attempts'] = ++$attempts;
                        $error = 'Invalid email or password';
                    }
                } else {
                    $_SESSION['login_attempts'] = ++$attempts;
                    $error = 'Invalid email or password';
                }
                
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | Elite Swimming Academy</title>
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

        .login-wrapper {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
            padding: 20px;
        }

        .login-container {
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

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header h2 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #212529;
        }

        .login-header p {
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

        .form-control {
            padding: 12px 15px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 14px;
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
        }

        .btn-login {
            background: #0d6efd;
            color: white;
            padding: 12px;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            font-size: 15px;
            width: 100%;
            margin-bottom: 20px;
        }

        .btn-login:hover:not(:disabled) {
            background: #0a58ca;
        }

        .btn-login:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }

        .register-section {
            text-align: center;
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #dee2e6;
        }

        .register-section h5 {
            font-weight: 600;
            color: #212529;
            margin-bottom: 10px;
            font-size: 16px;
        }

        .btn-signup {
            background: white;
            color: #0d6efd;
            padding: 10px 20px;
            border: 1px solid #0d6efd;
            border-radius: 6px;
            font-weight: 500;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }

        .btn-signup:hover {
            background: #0d6efd;
            color: white;
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

        @media (max-width: 576px) {
            .login-wrapper {
                padding: 10px;
            }
            
            .login-container {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-container">
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

            <div class="login-header">
                <h2>Sign In</h2>
                <p>Enter your credentials to continue</p>
            </div>

            <?php if($error): ?>
                <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <?php if($attempts > 0): ?>
                        <div class="small mt-1">Attempts: <?php echo $attempts; ?>/5</div>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form method="post" id="loginForm">
                <div class="form-group">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" 
                           class="form-control" 
                           name="email" 
                           id="email" 
                           value="<?php echo htmlspecialchars($email); ?>" 
                           placeholder="Enter your email"
                           required
                           <?php echo $attempts >= 5 ? 'disabled' : ''; ?>>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div style="position: relative;">
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
                </div>

                <button type="submit" 
                        class="btn-login"
                        id="loginButton"
                        <?php echo $attempts >= 5 ? 'disabled' : ''; ?>>
                    Sign In
                </button>
            </form>

            <div class="register-section">
                <h5>Don't have an account?</h5>
                <a href="register.php" class="btn-signup">
                    Create Account
                </a>
            </div>

            <div class="footer-links">
                <a href="../">Home</a>
                <a href="contact.php">Contact</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            
            // Password toggle functionality
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.innerHTML = type === 'password' ? 
                    '<i class="bi bi-eye"></i>' : 
                    '<i class="bi bi-eye-slash"></i>';
            });
            
            // Auto-focus email field if not disabled
            const emailInput = document.getElementById('email');
            if (emailInput && !emailInput.disabled) {
                emailInput.focus();
            }
        });
    </script>
</body>
</html>