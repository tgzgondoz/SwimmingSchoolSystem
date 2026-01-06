<?php
// student/logout-confirm.php - Logout Confirmation Page
session_start();

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
    <title>Confirm Logout | Elite Swimming Academy</title>
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
        
        .confirm-card {
            background: white;
            border-radius: 10px;
            padding: 30px;
            max-width: 400px;
            width: 100%;
            border: 1px solid #dee2e6;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            text-align: center;
        }
        
        .warning-icon {
            width: 60px;
            height: 60px;
            background: #ffc107;
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
            border-left: 3px solid #0d6efd;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn {
            flex: 1;
            padding: 10px;
            border-radius: 6px;
            font-weight: 500;
        }
        
        .btn-cancel {
            background: white;
            color: #495057;
            border: 1px solid #dee2e6;
        }
        
        .btn-cancel:hover {
            background: #f8f9fa;
        }
        
        .btn-logout {
            background: #dc3545;
            color: white;
            border: none;
        }
        
        .btn-logout:hover {
            background: #c82333;
        }
        
        @media (max-width: 576px) {
            .btn-group {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="confirm-card">
        <div class="warning-icon">
            <i class="bi bi-exclamation-triangle"></i>
        </div>
        
        <h2>Confirm Logout</h2>
        <p>Are you sure you want to log out?</p>
        
        <div class="user-info">
            Logged in as:<br>
            <strong><?= htmlspecialchars($username) ?></strong>
        </div>
        
        <p class="text-muted small">
            You will need to sign in again to access your account.
        </p>
        
        <form method="POST" action="logout.php" class="btn-group">
            <a href="index.php" class="btn btn-cancel">
                Cancel
            </a>
            <button type="submit" name="confirm_logout" class="btn btn-logout">
                Log Out
            </button>
        </form>
    </div>
</body>
</html>