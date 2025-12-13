<?php
// admin/login.php
session_start();
include __DIR__ . '/../inc/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    $stmt = $conn->prepare('SELECT id, password, role FROM users WHERE email = ? AND role = "admin" LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // For demo purposes - in production, use password_verify($password, $row['password'])
        if ($password === 'password' || password_verify($password, $row['password'])) {
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['role'] = $row['role'];
            header('Location: index.php');
            exit();
        }
    }
    $error = 'Invalid email or password';
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin Login - AquaFlow</title>
  <link href="../css/style.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      padding: 20px;
    }
    .login-card {
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
  </style>
</head>
<body>
  <div class="login-card">
    <div class="logo">
      <h2><i class="bi bi-water me-2"></i>AquaFlow Admin</h2>
      <p class="text-muted">Sign in to your account</p>
    </div>
    
    <?php if($error): ?>
      <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>
    
    <form method="post">
      <div class="mb-3">
        <label class="form-label">Email Address</label>
        <input type="email" class="form-control" name="email" value="admin@aquaflow.com" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" class="form-control" name="password" value="password" required>
        <div class="form-text">Demo: admin@aquaflow.com / password</div>
      </div>
      <button type="submit" class="btn btn-primary w-100 py-2">Sign In</button>
    </form>
  </div>
  
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>