<?php
// student/payments.php - Student Payments Management
session_start();
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

requireRole('student');
$user = getCurrentUser($conn);
$student_id = $_SESSION['user_id'];

// Get payment settings
$currency = getSetting($conn, 'currency', 'USD');
$tax_rate = getSetting($conn, 'tax_rate', '8.5');
$late_fee = getSetting($conn, 'late_fee', '25.00');

// Get student's payments (updated query - remove class_id reference)
$payments = $conn->query("
    SELECT * FROM payments 
    WHERE user_id = $student_id 
    ORDER BY payment_date DESC
")->fetch_all(MYSQLI_ASSOC);

// Get pending payments (unpaid classes) - updated query
$pending_payments = $conn->query("
    SELECT c.*, b.id as booking_id, b.created_at as booking_date
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    LEFT JOIN payments p ON b.id = p.booking_id AND p.status = 'paid'
    WHERE b.user_id = $student_id 
    AND b.status = 'confirmed'
    AND p.id IS NULL
    AND c.start_time >= NOW()
")->fetch_all(MYSQLI_ASSOC);

// Calculate totals
$total_paid = $conn->query("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM payments 
    WHERE user_id = $student_id AND status = 'paid'
")->fetch_assoc()['total'];

$total_pending = $conn->query("
    SELECT COALESCE(SUM(c.price), 0) as total 
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    LEFT JOIN payments p ON b.id = p.booking_id AND p.status = 'paid'
    WHERE b.user_id = $student_id 
    AND b.status = 'confirmed'
    AND p.id IS NULL
")->fetch_assoc()['total'];

$total_pending_with_tax = $total_pending * (1 + $tax_rate / 100);

// Zimbabwe payment methods
$zim_payment_methods = [
    'ecocash' => [
        'name' => 'EcoCash',
        'icon' => 'bi-phone',
        'color' => 'success',
        'instructions' => 'Use merchant number: 077 123 4567\nReference: Your Name + Booking ID'
    ],
    'onemoney' => [
        'name' => 'OneMoney',
        'icon' => 'bi-phone',
        'color' => 'info',
        'instructions' => 'Use merchant number: 078 123 4567\nReference: Your Name + Booking ID'
    ],
    'paynow' => [
        'name' => 'PayNow ZW',
        'icon' => 'bi-qr-code',
        'color' => 'primary',
        'instructions' => 'Scan QR code or use merchant code: AQUAFLOW\nReference: Your Name + Booking ID'
    ],
    'zip' => [
        'name' => 'ZIP (ZimSwitch)',
        'icon' => 'bi-credit-card',
        'color' => 'warning',
        'instructions' => 'Bank: CBZ\nAccount: 45678901234\nReference: Your Name + Booking ID'
    ],
    'cash_usd' => [
        'name' => 'Cash (USD)',
        'icon' => 'bi-cash',
        'color' => 'secondary',
        'instructions' => 'Pay at reception during business hours\nGet official receipt'
    ],
    'cash_zig' => [
        'name' => 'Cash (ZIG)',
        'icon' => 'bi-cash-coin',
        'color' => 'success',
        'instructions' => 'Pay at reception during business hours\nGet official receipt'
    ],
    'bank_transfer' => [
        'name' => 'Bank Transfer',
        'icon' => 'bi-bank',
        'color' => 'dark',
        'instructions' => 'Bank: CBZ\nAccount: 45678901234\nBranch: Harare Main\nReference: Your Name + Booking ID'
    ]
];

// Handle payment initiation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['initiate_payment'])) {
    $amount = floatval($_POST['amount']);
    $method = $_POST['payment_method'];
    
    // Generate payment reference
    $payment_reference = 'PAY' . time() . rand(100, 999);
    
    // Create payment record
    $stmt = $conn->prepare("
        INSERT INTO payments (user_id, amount, payment_method, reference, status, payment_date) 
        VALUES (?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->bind_param('idss', $student_id, $amount, $method, $payment_reference);
    
    if ($stmt->execute()) {
        $payment_id = $stmt->insert_id;
        
        // For pending bookings, associate them with this payment
        foreach ($pending_payments as $pending) {
            $stmt2 = $conn->prepare("
                UPDATE bookings 
                SET payment_id = ? 
                WHERE id = ? AND user_id = ?
            ");
            $stmt2->bind_param('iii', $payment_id, $pending['booking_id'], $student_id);
            $stmt2->execute();
        }
        
        $success_message = "Payment initiated! Reference: $payment_reference";
    } else {
        $error_message = "Failed to initiate payment.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments | AquaFlow Student Portal</title>
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
            color: var(--gray-800);
            line-height: 1.6;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styling - Same as other pages */
        .sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid var(--gray-200);
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .logo-area {
            padding: 25px 20px;
            border-bottom: 1px solid var(--gray-200);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .logo-text h3 {
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            font-size: 20px;
            margin: 0;
            color: var(--gray-900);
        }

        .logo-text span {
            font-size: 12px;
            color: var(--gray-500);
            font-weight: 500;
        }

        .nav-menu {
            padding: 20px 15px;
        }

        .nav-item {
            margin-bottom: 5px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            border-radius: 10px;
            color: var(--gray-600);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            background: var(--gray-100);
            color: var(--primary);
            transform: translateX(5px);
        }

        .nav-link.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.2);
        }

        .nav-link.active i {
            color: white;
        }

        .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 18px;
            color: var(--gray-500);
        }

        .nav-link.active:hover {
            transform: translateX(5px);
            background: linear-gradient(135deg, var(--primary-dark) 0%, #0a3d9c 100%);
        }

        .logout-section {
            padding: 20px;
            border-top: 1px solid var(--gray-200);
            margin-top: auto;
            position: absolute;
            bottom: 0;
            width: 100%;
        }

        /* Main Content Styling */
        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 30px;
            transition: all 0.3s ease;
        }

        /* Header Styling */
        .header {
            background: white;
            border-radius: 15px;
            padding: 20px 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-left h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--gray-900);
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .header-left p {
            color: var(--gray-600);
            margin: 0;
            font-size: 16px;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
            border: 3px solid white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .user-info h5 {
            font-weight: 600;
            margin: 0;
            color: var(--gray-900);
        }

        .user-info p {
            color: var(--gray-500);
            font-size: 13px;
            margin: 0;
        }

        /* Alert Messages */
        .alert-custom {
            border-radius: 12px;
            border: none;
            padding: 20px 25px;
            margin-bottom: 30px;
            animation: slideIn 0.5s ease;
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

        .alert-custom i {
            font-size: 22px;
            margin-right: 12px;
        }

        /* Stats Cards */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 15px;
        }

        .stat-icon.primary {
            background: linear-gradient(135deg, var(--primary-light) 0%, #bfdbfe 100%);
            color: var(--primary);
        }

        .stat-icon.success {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: var(--success);
        }

        .stat-icon.warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: var(--warning);
        }

        .stat-icon.danger {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: var(--danger);
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 5px;
        }

        .stat-label {
            color: var(--gray-500);
            font-size: 14px;
            font-weight: 500;
        }

        /* Balance Card */
        .balance-card {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 15px;
            padding: 30px;
            color: white;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(13, 110, 253, 0.2);
        }

        /* Payment Methods */
        .payment-methods {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            margin: 25px 0;
        }

        .payment-method {
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
        }

        .payment-method:hover {
            border-color: var(--primary);
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .payment-method.selected {
            border-color: var(--primary);
            background: var(--primary-light);
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(13, 110, 253, 0.2);
        }

        .payment-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-size: 24px;
            background: linear-gradient(135deg, var(--primary-light) 0%, #bfdbfe 100%);
            color: var(--primary);
        }

        .payment-method.selected .payment-icon {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        /* Invoice Items */
        .invoice-item {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .invoice-item:last-child {
            border-bottom: none;
        }

        .invoice-total {
            font-size: 18px;
            font-weight: 600;
            color: var(--gray-900);
        }

        /* Payment History */
        .payment-history {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            border: 1px solid var(--gray-200);
        }

        .payment-item {
            padding: 15px 20px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .payment-item:last-child {
            border-bottom: none;
        }

        .payment-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-paid {
            background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
            color: white;
        }

        .status-pending {
            background: linear-gradient(135deg, var(--warning) 0%, #d97706 100%);
            color: white;
        }

        .status-failed {
            background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%);
            color: white;
        }

        /* Instructions Box */
        .instructions-box {
            background: var(--gray-50);
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            border-left: 4px solid var(--primary);
        }

        /* Buttons */
        .btn-pay {
            background: linear-gradient(90deg, var(--success), #059669);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-pay:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3);
        }

        .btn-pay:disabled {
            background: var(--gray-400);
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--gray-200);
        }

        .empty-state i {
            font-size: 64px;
            color: var(--gray-300);
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-family: 'Poppins', sans-serif;
            font-size: 24px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 12px;
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .sidebar {
                width: 70px;
            }
            
            .main-content {
                margin-left: 70px;
            }
            
            .logo-text, .nav-text {
                display: none;
            }
            
            .logo {
                justify-content: center;
            }
            
            .main-content {
                padding: 20px;
            }
            
            .payment-methods {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .header-left h1 {
                font-size: 24px;
            }
            
            .balance-card .row {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .payment-methods {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Animations */
        .fade-in {
            animation: fadeIn 0.5s ease forwards;
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
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="logo-area">
                <a href="index.php" class="logo">
                    <div class="logo-icon">
                        <i class="bi bi-droplet"></i>
                    </div>
                    <div class="logo-text">
                        <h3>AquaFlow</h3>
                        <span>Student Portal</span>
                    </div>
                </a>
            </div>

            <nav class="nav-menu">
                <div class="nav-item">
                    <a href="index.php" class="nav-link">
                        <i class="bi bi-speedometer2"></i>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="classes.php" class="nav-link">
                        <i class="bi bi-calendar-week"></i>
                        <span class="nav-text">Classes</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="my-bookings.php" class="nav-link">
                        <i class="bi bi-ticket-perforated"></i>
                        <span class="nav-text">My Bookings</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="payments.php" class="nav-link active">
                        <i class="bi bi-credit-card"></i>
                        <span class="nav-text">Payments</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="profile.php" class="nav-link">
                        <i class="bi bi-person-circle"></i>
                        <span class="nav-text">Profile</span>
                    </a>
                </div>
            </nav>

            <div class="logout-section">
                <a href="logout.php" class="nav-link">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="nav-text">Logout</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header fade-in">
                <div class="header-left">
                    <h1>Payments</h1>
                    <p>Manage your payments and view payment history</p>
                </div>
                <div class="user-profile">
                    <div class="user-avatar">
                        <?= strtoupper(substr($user['name'], 0, 1)) ?>
                    </div>
                    <div class="user-info">
                        <h5><?= htmlspecialchars($user['name']) ?></h5>
                        <p>Student ID: <?= htmlspecialchars($user['student_id'] ?? 'N/A') ?></p>
                    </div>
                </div>
            </header>

            <!-- Alert Messages -->
            <?php if(isset($success_message)): ?>
                <div class="alert alert-success alert-custom alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i>
                    <?= $success_message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if(isset($error_message)): ?>
                <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-circle"></i>
                    <?= $error_message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Section -->
            <div class="stats-container fade-in">
                <div class="stat-card">
                    <div class="stat-icon success">
                        <i class="bi bi-currency-dollar"></i>
                    </div>
                    <div class="stat-value">$<?= number_format($total_paid, 2) ?></div>
                    <div class="stat-label">Total Paid</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon warning">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div class="stat-value">$<?= number_format($total_pending_with_tax, 2) ?></div>
                    <div class="stat-label">Pending Balance</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon primary">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div class="stat-value"><?= count($pending_payments) ?></div>
                    <div class="stat-label">Classes Pending</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon danger">
                        <i class="bi bi-receipt"></i>
                    </div>
                    <div class="stat-value"><?= count($payments) ?></div>
                    <div class="stat-label">Total Transactions</div>
                </div>
            </div>

            <div class="row">
                <!-- Left Column: Pending Payments & Payment Methods -->
                <div class="col-lg-8">
                    <!-- Pending Payments -->
                    <div class="payment-card fade-in" style="background: white; border-radius: 15px; padding: 25px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);">
                        <h3 class="mb-4" style="font-family: 'Poppins', sans-serif; font-weight: 600;">Pending Payments</h3>
                        
                        <?php if(empty($pending_payments)): ?>
                            <div class="empty-state">
                                <i class="bi bi-check-circle"></i>
                                <h3>No Pending Payments</h3>
                                <p>All your classes are paid for.</p>
                            </div>
                        <?php else: ?>
                            <!-- Pending Payments List -->
                            <div class="mb-4">
                                <?php foreach($pending_payments as $payment): ?>
                                    <div class="invoice-item">
                                        <div>
                                            <div class="fw-medium"><?= htmlspecialchars($payment['title']) ?></div>
                                            <small class="text-muted">
                                                Booking #<?= $payment['booking_id'] ?> • 
                                                <?= date('M j, Y', strtotime($payment['start_time'])) ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-bold">$<?= number_format($payment['price'], 2) ?></div>
                                            <small class="text-muted">Due before class</small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                
                                <!-- Invoice Summary -->
                                <div class="mt-4 pt-4 border-top">
                                    <div class="invoice-item">
                                        <div>Subtotal</div>
                                        <div>$<?= number_format($total_pending, 2) ?></div>
                                    </div>
                                    <div class="invoice-item">
                                        <div>Tax (<?= $tax_rate ?>%)</div>
                                        <div>$<?= number_format($total_pending * ($tax_rate / 100), 2) ?></div>
                                    </div>
                                    <div class="invoice-item">
                                        <div class="invoice-total">Total Due</div>
                                        <div class="invoice-total">$<?= number_format($total_pending_with_tax, 2) ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Payment Methods -->
                            <h4 class="mb-3" style="font-family: 'Poppins', sans-serif; font-weight: 600;">Select Payment Method</h4>
                            <div class="payment-methods fade-in" id="paymentMethods">
                                <?php foreach($zim_payment_methods as $method => $details): ?>
                                    <div class="payment-method" data-method="<?= $method ?>" data-details='<?= json_encode($details) ?>'>
                                        <div class="payment-icon">
                                            <i class="bi <?= $details['icon'] ?>"></i>
                                        </div>
                                        <div class="fw-medium"><?= $details['name'] ?></div>
                                        <?php if(strpos($method, 'cash') !== false): ?>
                                            <small class="text-muted">
                                                <?= $method === 'cash_usd' ? 'USD' : 'ZIG' ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Payment Instructions -->
                            <div class="instructions-box fade-in" id="paymentInstructions">
                                <h5 class="mb-3" style="font-family: 'Poppins', sans-serif; font-weight: 600;">Payment Instructions</h5>
                                <p class="mb-0">Select a payment method to view instructions.</p>
                            </div>

                            <!-- Make Payment Button -->
                            <div class="text-center mt-4 fade-in">
                                <form method="POST" id="paymentForm">
                                    <input type="hidden" name="amount" value="<?= $total_pending_with_tax ?>">
                                    <input type="hidden" name="payment_method" id="selectedPaymentMethod" value="">
                                    <button type="submit" name="initiate_payment" class="btn-pay" id="makePaymentBtn" disabled>
                                        <i class="bi bi-credit-card"></i>
                                        Make Payment - $<?= number_format($total_pending_with_tax, 2) ?>
                                    </button>
                                </form>
                                <p class="text-muted mt-3 small">
                                    Once payment is made, upload proof of payment for verification.
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Column: Payment History -->
                <div class="col-lg-4">
                    <div class="payment-history fade-in">
                        <div class="payment-item" style="background: var(--gray-50); border-bottom: 1px solid var(--gray-200);">
                            <div>
                                <div class="fw-bold" style="font-family: 'Poppins', sans-serif;">Payment History</div>
                                <small class="text-muted">Recent transactions</small>
                            </div>
                            <i class="bi bi-clock-history text-primary"></i>
                        </div>
                        
                        <?php if(empty($payments)): ?>
                            <div class="empty-state" style="padding: 30px 20px;">
                                <i class="bi bi-credit-card"></i>
                                <p class="text-muted mb-0">No payment history</p>
                            </div>
                        <?php else: ?>
                            <?php foreach($payments as $payment): ?>
                                <div class="payment-item">
                                    <div>
                                        <div class="fw-medium">$<?= number_format($payment['amount'], 2) ?></div>
                                        <small class="text-muted">
                                            <?= date('M j, Y', strtotime($payment['payment_date'])) ?>
                                        </small>
                                        <?php if(!empty($payment['reference'])): ?>
                                            <div class="small text-muted">Ref: <?= $payment['reference'] ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-end">
                                        <span class="payment-status status-<?= $payment['status'] ?>">
                                            <i class="bi bi-circle-fill" style="font-size: 8px;"></i>
                                            <?= ucfirst($payment['status']) ?>
                                        </span>
                                        <?php if(!empty($payment['payment_method'])): ?>
                                            <div class="small text-muted mt-2">
                                                <?= $zim_payment_methods[$payment['payment_method']]['name'] ?? ucfirst($payment['payment_method']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Payment Tips -->
                    <div class="payment-card fade-in mt-4" style="background: white; border-radius: 15px; padding: 20px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);">
                        <h5 class="mb-3" style="font-family: 'Poppins', sans-serif; font-weight: 600;">
                            <i class="bi bi-lightbulb text-warning me-2"></i>Payment Tips
                        </h5>
                        <ul class="list-unstyled text-muted small">
                            <li class="mb-3">
                                <i class="bi bi-check-circle text-success me-2"></i>
                                Always include your name and booking ID as reference
                            </li>
                            <li class="mb-3">
                                <i class="bi bi-check-circle text-success me-2"></i>
                                Save payment confirmation for verification
                            </li>
                            <li class="mb-3">
                                <i class="bi bi-check-circle text-success me-2"></i>
                                Payments are processed within 24 hours
                            </li>
                            <li class="mb-3">
                                <i class="bi bi-check-circle text-success me-2"></i>
                                Contact admin for payment issues
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const paymentMethods = document.querySelectorAll('.payment-method');
            const instructionsBox = document.getElementById('paymentInstructions');
            const makePaymentBtn = document.getElementById('makePaymentBtn');
            const selectedPaymentMethodInput = document.getElementById('selectedPaymentMethod');
            
            // Payment method selection
            paymentMethods.forEach(method => {
                method.addEventListener('click', function() {
                    // Remove selected class from all methods
                    paymentMethods.forEach(m => m.classList.remove('selected'));
                    
                    // Add selected class to clicked method
                    this.classList.add('selected');
                    
                    // Get method details
                    const methodName = this.dataset.method;
                    const methodDetails = JSON.parse(this.dataset.details);
                    
                    // Update hidden input
                    selectedPaymentMethodInput.value = methodName;
                    
                    // Update instructions
                    instructionsBox.innerHTML = `
                        <h5 class="mb-3" style="font-family: 'Poppins', sans-serif; font-weight: 600;">${methodDetails.name} Instructions</h5>
                        <p class="mb-0" style="white-space: pre-line;">${methodDetails.instructions}</p>
                    `;
                    
                    // Enable make payment button
                    makePaymentBtn.disabled = false;
                });
            });
            
            // Payment form submission
            const paymentForm = document.getElementById('paymentForm');
            paymentForm.addEventListener('submit', function(e) {
                const selectedMethod = document.querySelector('.payment-method.selected');
                if (!selectedMethod) {
                    e.preventDefault();
                    alert('Please select a payment method first.');
                    return;
                }
                
                const methodName = selectedMethod.dataset.method;
                const methodDetails = JSON.parse(selectedMethod.dataset.details);
                const amount = <?= $total_pending_with_tax ?>;
                
                if (!confirm(
                    `Confirm Payment\n\n` +
                    `Amount: $${amount.toFixed(2)}\n` +
                    `Method: ${methodDetails.name}\n\n` +
                    `Please complete the payment using the instructions provided.\n` +
                    `After payment, upload the proof of payment for verification.\n\n` +
                    `Proceed to payment?`
                )) {
                    e.preventDefault();
                    return;
                }
                
                // Show loading state
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
                submitBtn.disabled = true;
                
                // Revert after 5 seconds if form doesn't submit
                setTimeout(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 5000);
            });
            
            // Add animation to payment methods
            paymentMethods.forEach((method, index) => {
                method.style.animationDelay = `${index * 0.1}s`;
                method.classList.add('fade-in');
            });
            
            // Add hover effect to payment history items
            document.querySelectorAll('.payment-item:not(:first-child)').forEach(item => {
                item.style.cursor = 'pointer';
                item.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = 'var(--gray-50)';
                });
                item.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = '';
                });
            });
        });
    </script>
</body>
</html>