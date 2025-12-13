<?php
// student/login.php - Student Login Page
session_start();

// Include database connection
include __DIR__ . '/../inc/db.php';

// Initialize error variable
$error = '';

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize inputs
    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    
    // Validate inputs
    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } else {
        // Check for both student and admin roles
        $stmt = $conn->prepare('SELECT id, password, role, name FROM users WHERE email = ? AND role IN ("student", "admin") LIMIT 1');
        if (!$stmt) {
            $error = 'Database error. Please try again.';
        } else {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                // Verify password
                if (password_verify($password, $row['password'])) {
                    $_SESSION['user_id'] = $row['id'];
                    $_SESSION['role'] = $row['role'];
                    $_SESSION['user_name'] = $row['name'];
                    
                    // Set session timeout (1 hour)
                    $_SESSION['last_activity'] = time();
                    
                    // Redirect based on role
                    if ($row['role'] === 'admin') {
                        header('Location: ../admin/index.php');
                    } else {
                        header('Location: index.php'); // Student dashboard
                    }
                    exit();
                }
            }
            // Generic error message for security (don't reveal if user exists)
            $error = 'Invalid email or password';
            
            $stmt->close();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Student Login - AquaFlow</title>
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
    .login-container {
      background: white;
      border-radius: 15px;
      padding: 40px;
      box-shadow: 0 20px 40px rgba(0,0,0,0.1);
      width: 100%;
      max-width: 400px;
    }
    .logo {
      text-align: center;
      margin-bottom: 30px;
    }
    .logo h2 {
      color: #1e40af;
      font-weight: 700;
    }
    .demo-credentials {
      background: #f8fafc;
      border-radius: 8px;
      padding: 15px;
      margin-top: 20px;
      font-size: 13px;
    }
    .btn-group-credential {
      margin-bottom: 15px;
    }
    .btn-group-credential .btn {
      font-size: 0.8rem;
      padding: 5px 10px;
    }
  </style>
</head>
<body>
  <div class="login-container">
    <div class="logo">
      <h2><i class="bi bi-water me-2"></i>AquaFlow</h2>
      <p class="text-muted">Sign in to your account</p>
    </div>
    
    <?php if($error): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>
    
    <form method="post" id="loginForm" novalidate>
      <div class="mb-3">
        <label for="email" class="form-label">Email Address</label>
        <input type="email" class="form-control" name="email" id="email" required 
               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
      </div>
      <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <input type="password" class="form-control" name="password" id="password" required>
      </div>
      <div class="mb-3 form-check">
        <input type="checkbox" class="form-check-input" id="remember" name="remember">
        <label class="form-check-label" for="remember">Remember me</label>
      </div>
      <button type="submit" class="btn btn-primary w-100 py-2 mb-3">Sign In</button>
      
      <div class="text-center">
        <a href="forgot-password.php" class="text-decoration-none">Forgot password?</a>
        <p class="mt-2 mb-0">Don't have an account? <a href="register.php" class="text-decoration-none">Contact admin</a></p>
      </div>
    </form>
    
    <!-- Demo Credentials with buttons -->
    <div class="demo-credentials">
      <h6 class="mb-3">Demo Credentials:</h6>
      
      <div class="btn-group-credential d-flex gap-2">
        <button type="button" class="btn btn-outline-primary flex-fill" id="useAdmin">
          <i class="bi bi-person-fill-gear me-1"></i> Use Admin
        </button>
        <button type="button" class="btn btn-outline-success flex-fill" id="useStudent">
          <i class="bi bi-person-fill me-1"></i> Use Student
        </button>
      </div>
      
      <div class="mt-2">
        <p class="mb-1"><small><strong>Admin:</strong> admin@aquaflow.com / password</small></p>
        <p class="mb-1"><small><strong>Student:</strong> student@aquaflow.com / password</small></p>
        <p class="mb-0"><small><em>For demo purposes only - use any email with password "password"</em></small></p>
      </div>
    </div>
  </div>
  
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Auto-fill demo credentials based on selection
    document.addEventListener('DOMContentLoaded', function() {
      const emailInput = document.getElementById('email');
      const passwordInput = document.getElementById('password');
      
      // Quick fill buttons
      document.getElementById('useAdmin').addEventListener('click', function() {
        emailInput.value = 'admin@aquaflow.com';
        passwordInput.value = 'password';
      });
      
      document.getElementById('useStudent').addEventListener('click', function() {
        emailInput.value = 'student@aquaflow.com';
        passwordInput.value = 'password';
      });
      
      // Form validation
      document.getElementById('loginForm').addEventListener('submit', function(e) {
        if (!emailInput.value || !passwordInput.value) {
          e.preventDefault();
          alert('Please fill in all required fields');
        }
      });
    });
  </script>
</body>
</html>