<?php
// student/logout-confirm.php - Logout Confirmation Page
session_start();

// If not logged in, redirect to login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$username = $_SESSION['user_name'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Logout | AquaFlow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f0f9ff 0%, #e6f0ff 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        
        .confirm-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 450px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
            text-align: center;
            border-top: 5px solid #0d6efd;
        }
        
        .warning-icon {
            width: 80px;
            height: 80px;
            background: #fef3c7;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            color: #f59e0b;
            font-size: 36px;
        }
        
        h2 {
            color: #1f2937;
            margin-bottom: 15px;
            font-weight: 700;
        }
        
        p {
            color: #6b7280;
            margin-bottom: 25px;
        }
        
        .user-highlight {
            background: #eff6ff;
            padding: 10px 15px;
            border-radius: 10px;
            margin: 20px 0;
            border-left: 4px solid #0d6efd;
        }
        
        .btn-group {
            display: flex;
            gap: 15px;
            margin-top: 25px;
        }
        
        .btn {
            flex: 1;
            padding: 12px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .btn-cancel {
            background: white;
            color: #4b5563;
            border: 2px solid #d1d5db;
        }
        
        .btn-cancel:hover {
            background: #f9fafb;
            border-color: #9ca3af;
        }
        
        .btn-logout {
            background: linear-gradient(90deg, #ef4444, #dc2626);
            color: white;
            border: none;
        }
        
        .btn-logout:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(239, 68, 68, 0.3);
        }
    </style>
</head>
<body>
    <div class="confirm-card">
        <div class="warning-icon">
            <i class="bi bi-exclamation-triangle"></i>
        </div>
        
        <h2>Confirm Logout</h2>
        <p>Are you sure you want to log out of your AquaFlow account?</p>
        
        <div class="user-highlight">
            <strong>Currently logged in as:</strong><br>
            <?= htmlspecialchars($username) ?>
        </div>
        
        <p class="text-muted small">
            <i class="bi bi-info-circle me-1"></i>
            You will need to sign in again to access your account.
        </p>
        
        <form method="POST" action="logout.php" class="btn-group">
            <a href="index.php" class="btn btn-cancel">
                <i class="bi bi-arrow-left me-2"></i>Cancel
            </a>
            <button type="submit" name="confirm_logout" class="btn btn-logout">
                <i class="bi bi-box-arrow-right me-2"></i>Log Out
            </button>
        </form>
    </div>
</body>
</html>