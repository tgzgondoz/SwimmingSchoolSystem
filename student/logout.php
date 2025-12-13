<?php
// student/logout.php - Professional Logout with Confirmation
session_start();

// If direct access, redirect to confirmation
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['confirm_logout'])) {
    header('Location: logout-confirm.php');
    exit();
}

// Include database connection
require_once __DIR__ . '/../inc/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['user_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'student';

// Clear any remember me tokens
if (isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    
    // Delete token from database if table exists
    try {
        $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE token = ?");
        if ($stmt) {
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        // Table might not exist, ignore error
    }
    
    // Clear cookie
    setcookie('remember_token', '', time() - 3600, '/', '', true, true);
}

// Clear all session variables
$_SESSION = array();

// Destroy session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Show goodbye page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logged Out | AquaFlow Swimming School</title>
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
            --info: #06b6d4;
            --light: #f8f9fa;
            --dark: #212529;
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
            justify-content: center;
            color: var(--gray-800);
            line-height: 1.6;
            padding: 20px;
        }

        .logout-container {
            width: 100%;
            max-width: 500px;
            animation: fadeIn 0.8s ease;
        }

        .logout-card {
            background: white;
            border-radius: 20px;
            padding: 50px 40px;
            box-shadow: 0 20px 60px rgba(37, 99, 235, 0.1);
            text-align: center;
            border: 1px solid var(--gray-200);
            position: relative;
            overflow: hidden;
        }

        .logout-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
        }

        .logo-area {
            margin-bottom: 30px;
        }

        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 20px;
        }

        .logo-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }

        .logo-text h2 {
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            font-size: 28px;
            color: var(--gray-900);
            margin: 0;
        }

        .logo-text span {
            font-size: 14px;
            color: var(--gray-500);
            font-weight: 500;
        }

        .goodbye-icon {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            color: white;
            font-size: 48px;
            animation: bounce 1s infinite alternate;
        }

        @keyframes bounce {
            from {
                transform: translateY(0);
            }
            to {
                transform: translateY(-10px);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message-area {
            margin-bottom: 40px;
        }

        .message-area h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 32px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 15px;
        }

        .message-area p {
            color: var(--gray-600);
            font-size: 16px;
            margin-bottom: 20px;
        }

        .user-info {
            background: var(--gray-50);
            border-radius: 12px;
            padding: 15px;
            margin: 20px 0;
            border-left: 4px solid var(--primary);
        }

        .user-info p {
            margin: 5px 0;
            color: var(--gray-700);
        }

        .user-info strong {
            color: var(--gray-900);
        }

        .actions-area {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-top: 30px;
        }

        .btn-primary {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: 12px;
            padding: 16px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(13, 110, 253, 0.3);
            color: white;
        }

        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 2px solid var(--primary);
            border-radius: 12px;
            padding: 16px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-secondary:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        .security-note {
            background: var(--gray-50);
            border-radius: 12px;
            padding: 20px;
            margin-top: 30px;
            border-left: 4px solid var(--success);
        }

        .security-note h4 {
            font-family: 'Poppins', sans-serif;
            font-size: 16px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .security-note p {
            color: var(--gray-600);
            font-size: 14px;
            margin: 0;
        }

        .countdown {
            font-size: 14px;
            color: var(--gray-500);
            margin-top: 20px;
        }

        .countdown span {
            color: var(--primary);
            font-weight: 600;
        }

        /* Animation for success checkmark */
        .checkmark {
            width: 80px;
            height: 80px;
            margin: 0 auto 30px;
        }

        .checkmark-circle {
            stroke-dasharray: 166;
            stroke-dashoffset: 166;
            stroke-width: 2;
            stroke-miterlimit: 10;
            stroke: var(--success);
            fill: none;
            animation: stroke 0.6s cubic-bezier(0.65, 0, 0.45, 1) forwards;
        }

        .checkmark-check {
            transform-origin: 50% 50%;
            stroke-dasharray: 48;
            stroke-dashoffset: 48;
            stroke: var(--success);
            animation: stroke 0.3s cubic-bezier(0.65, 0, 0.45, 1) 0.8s forwards;
        }

        @keyframes stroke {
            100% {
                stroke-dashoffset: 0;
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .logout-card {
                padding: 40px 25px;
            }
            
            .message-area h1 {
                font-size: 28px;
            }
            
            .actions-area {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .logout-card {
                padding: 30px 20px;
            }
            
            .message-area h1 {
                font-size: 24px;
            }
            
            .logo-text h2 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="logout-container">
        <div class="logout-card">
            <div class="logo-area">
                <div class="logo">
                    <div class="logo-icon">
                        <i class="bi bi-droplet"></i>
                    </div>
                    <div class="logo-text">
                        <h2>AquaFlow</h2>
                        <span>Swimming School</span>
                    </div>
                </div>
            </div>

            <!-- Animated Success Checkmark -->
            <div class="checkmark">
                <svg class="checkmark-circle" viewBox="0 0 52 52">
                    <circle class="checkmark-circle-bg" cx="26" cy="26" r="25" fill="none"/>
                    <path class="checkmark-check" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>
                </svg>
            </div>

            <div class="message-area">
                <h1>Successfully Logged Out</h1>
                <p>You have been securely logged out of your AquaFlow account.</p>
                
                <div class="user-info">
                    <p><strong>User:</strong> <?= htmlspecialchars($username) ?></p>
                    <p><strong>Role:</strong> <?= htmlspecialchars(ucfirst($role)) ?></p>
                    <p><strong>Time:</strong> <?= date('F j, Y \a\t g:i A') ?></p>
                </div>
            </div>

            <div class="security-note">
                <h4><i class="bi bi-shield-check"></i> Security Note</h4>
                <p>For your security, all session data has been cleared. To access your account again, please sign in with your credentials.</p>
            </div>

            <div class="actions-area">
                <a href="login.php" class="btn-primary">
                    <i class="bi bi-box-arrow-in-right"></i>
                    Sign In Again
                </a>
                <a href="../" class="btn-secondary">
                    <i class="bi bi-house"></i>
                    Return to Homepage
                </a>
            </div>

            <div class="countdown">
                <p>You will be automatically redirected to the login page in <span id="countdown">10</span> seconds.</p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Countdown timer for auto-redirect
            let seconds = 10;
            const countdownElement = document.getElementById('countdown');
            const loginButton = document.querySelector('.btn-primary');
            
            const countdown = setInterval(() => {
                seconds--;
                countdownElement.textContent = seconds;
                
                if (seconds <= 0) {
                    clearInterval(countdown);
                    window.location.href = 'login.php';
                }
            }, 1000);
            
            // Optional: Skip countdown if user clicks login
            loginButton.addEventListener('click', function(e) {
                clearInterval(countdown);
                // Button will naturally navigate to login.php
            });
            
            // Add animation to checkmark
            const checkmark = document.querySelector('.checkmark');
            checkmark.style.opacity = '1';
            
            // Add some visual feedback
            setTimeout(() => {
                document.querySelector('.logout-card').style.transform = 'scale(1.02)';
                setTimeout(() => {
                    document.querySelector('.logout-card').style.transform = 'scale(1)';
                }, 200);
            }, 500);
            
            // Play logout sound (optional)
            try {
                const audio = new Audio('data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEARKwAAIhYAQACABAAZGF0YQQAAAAAAA==');
                audio.volume = 0.3;
                audio.play();
            } catch (e) {
                // Sound not supported or error
            }
        });
    </script>
</body>
</html>