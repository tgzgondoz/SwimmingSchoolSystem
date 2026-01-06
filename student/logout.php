<?php
// student/logout.php - Logout Handler
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['confirm_logout'])) {
    header('Location: logout-confirm.php');
    exit();
}

require_once __DIR__ . '/../inc/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$username = $_SESSION['user_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'student';

// Clear session
$_SESSION = array();

// Destroy session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// Clear remember token cookie if exists
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', true, true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logged Out | Elite Swimming Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .logout-card {
            background: white;
            border-radius: 10px;
            padding: 30px;
            max-width: 400px;
            width: 100%;
            border: 1px solid #dee2e6;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            text-align: center;
        }
        
        .success-icon {
            width: 60px;
            height: 60px;
            background: #198754;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 24px;
        }
        
        h2 {
            color: #212529;
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        p {
            color: #6c757d;
            margin-bottom: 20px;
        }
        
        .user-info {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 6px;
            margin: 15px 0;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 25px;
        }
        
        .btn {
            flex: 1;
            padding: 10px;
            border-radius: 6px;
            font-weight: 500;
            text-decoration: none;
            text-align: center;
        }
        
        .btn-login {
            background: #0d6efd;
            color: white;
            border: none;
        }
        
        .btn-login:hover {
            background: #0a58ca;
            color: white;
        }
        
        .btn-home {
            background: white;
            color: #495057;
            border: 1px solid #dee2e6;
        }
        
        .btn-home:hover {
            background: #f8f9fa;
        }
        
        .countdown {
            font-size: 14px;
            color: #6c757d;
            margin-top: 15px;
        }
        
        @media (max-width: 576px) {
            .btn-group {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="logout-card">
        <div class="success-icon">
            <i class="bi bi-check-circle"></i>
        </div>
        
        <h2>Logged Out Successfully</h2>
        <p>You have been securely logged out of your account.</p>
        
        <div class="user-info">
            <strong><?= htmlspecialchars($username) ?></strong><br>
            <small>Role: <?= htmlspecialchars(ucfirst($role)) ?></small>
        </div>
        
        <div class="btn-group">
            <a href="login.php" class="btn btn-login">
                Sign In Again
            </a>
            <a href="../" class="btn btn-home">
                Home
            </a>
        </div>
        
        <div class="countdown">
            Redirecting to login page in <span id="countdown">5</span> seconds...
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            let seconds = 5;
            const countdownElement = document.getElementById('countdown');
            
            const countdown = setInterval(() => {
                seconds--;
                countdownElement.textContent = seconds;
                
                if (seconds <= 0) {
                    clearInterval(countdown);
                    window.location.href = 'login.php';
                }
            }, 1000);
        });
    </script>
</body>
</html>