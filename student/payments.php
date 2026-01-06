<?php
// student/payments.php - Student Payments Management
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$student_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

// Get payment settings
$currency = 'USD';
$tax_rate = 8.5;
$late_fee = 25.00;

// Get student's payments
$payments = [];
$payments_stmt = $conn->prepare("SELECT * FROM payments WHERE user_id = ? ORDER BY payment_date DESC");
if ($payments_stmt) {
    $payments_stmt->bind_param("i", $student_id);
    $payments_stmt->execute();
    $payments_result = $payments_stmt->get_result();
    $payments = $payments_result->fetch_all(MYSQLI_ASSOC);
    $payments_stmt->close();
}

// Get pending payments (unpaid classes)
$pending_payments = [];
$pending_stmt = $conn->prepare("
    SELECT c.*, b.id as booking_id, b.created_at as booking_date
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    LEFT JOIN payments p ON b.id = p.booking_id AND p.status = 'paid'
    WHERE b.user_id = ? 
    AND b.status = 'confirmed'
    AND p.id IS NULL
    AND c.start_time >= NOW()
");
if ($pending_stmt) {
    $pending_stmt->bind_param("i", $student_id);
    $pending_stmt->execute();
    $pending_result = $pending_stmt->get_result();
    $pending_payments = $pending_result->fetch_all(MYSQLI_ASSOC);
    $pending_stmt->close();
}

// Calculate totals
$total_paid = 0;
$paid_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE user_id = ? AND status = 'paid'");
if ($paid_stmt) {
    $paid_stmt->bind_param("i", $student_id);
    $paid_stmt->execute();
    $paid_result = $paid_stmt->get_result();
    $total_paid = $paid_result->fetch_assoc()['total'];
    $paid_stmt->close();
}

$total_pending = 0;
if (!empty($pending_payments)) {
    foreach ($pending_payments as $payment) {
        $total_pending += $payment['price'];
    }
}

$total_pending_with_tax = $total_pending * (1 + $tax_rate / 100);

// Payment methods
$payment_methods = [
    'ecocash' => [
        'name' => 'EcoCash',
        'icon' => 'bi-phone',
        'instructions' => 'Use merchant number: 077 123 4567<br>Reference: Your Name + Booking ID'
    ],
    'onemoney' => [
        'name' => 'OneMoney',
        'icon' => 'bi-phone',
        'instructions' => 'Use merchant number: 078 123 4567<br>Reference: Your Name + Booking ID'
    ],
    'paynow' => [
        'name' => 'PayNow',
        'icon' => 'bi-qr-code',
        'instructions' => 'Scan QR code or use merchant code<br>Reference: Your Name + Booking ID'
    ],
    'bank' => [
        'name' => 'Bank Transfer',
        'icon' => 'bi-bank',
        'instructions' => 'Bank: CBZ<br>Account: 45678901234<br>Reference: Your Name + Booking ID'
    ],
    'cash_usd' => [
        'name' => 'Cash (USD)',
        'icon' => 'bi-cash',
        'instructions' => 'Pay at reception during business hours'
    ],
    'cash_zig' => [
        'name' => 'Cash (ZIG)',
        'icon' => 'bi-cash-coin',
        'instructions' => 'Pay at reception during business hours'
    ]
];

// Handle payment initiation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['initiate_payment'])) {
    $amount = floatval($_POST['amount']);
    $method = $_POST['payment_method'];
    
    if (empty($pending_payments)) {
        $error_msg = 'No pending bookings to pay for.';
    } else {
        $first_booking_id = null;
        foreach ($pending_payments as $pending) {
            $booking_id = intval($pending['booking_id']);
            $price = (float)$pending['price'];
            $reference = 'PAY' . time() . rand(100,999);
            $desc = 'Payment for booking #' . $booking_id;

            $ins = $conn->prepare("INSERT INTO payments (booking_id, user_id, amount, payment_method, reference_number, description, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
            if ($ins) {
                $ins->bind_param('iidsss', $booking_id, $student_id, $price, $method, $reference, $desc);
                $ins->execute();
                $ins->close();
                if (!$first_booking_id) $first_booking_id = $booking_id;
            }
        }

        if ($first_booking_id) {
            if ($method === 'paynow' || $method === 'ecocash') {
                header('Location: /student/paynow_initiate.php?booking_id=' . $first_booking_id);
                exit();
            }
        }

        $success_msg = 'Payment(s) initiated. Follow instructions for your chosen method.';
    }
}

// Get user info
$user = [];
$user_stmt = $conn->prepare("SELECT name, email FROM users WHERE id = ?");
if ($user_stmt) {
    $user_stmt->bind_param("i", $student_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    $user = $user_result->fetch_assoc() ?: [];
    $user_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments | Elite Swimming Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #f8f9fa;
            color: #333;
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #dee2e6;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 1000;
            padding: 20px 0;
        }
        
        .logo-area {
            padding: 0 25px 25px;
            border-bottom: 1px solid #dee2e6;
            margin-bottom: 20px;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #212529;
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
            font-size: 18px;
            margin: 0;
            color: #0d6efd;
        }
        
        .logo-text span {
            font-size: 12px;
            color: #6c757d;
        }
        
        .nav-menu {
            padding: 0 15px;
        }
        
        .nav-item {
            margin-bottom: 5px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            border-radius: 8px;
            color: #495057;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .nav-link:hover {
            background: #e9ecef;
            color: #0d6efd;
        }
        
        .nav-link.active {
            background: #0d6efd;
            color: white;
        }
        
        .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }
        
        .logout-section {
            padding: 20px;
            position: absolute;
            bottom: 0;
            width: 100%;
            border-top: 1px solid #dee2e6;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 30px;
        }
        
        /* Header */
        .header {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left h1 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 5px;
            color: #212529;
        }
        
        .header-left p {
            color: #6c757d;
            margin: 0;
            font-size: 14px;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 15px;
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 8px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            background: #6c757d;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 500;
            font-size: 16px;
        }
        
        .user-info h5 {
            font-weight: 600;
            margin: 0;
            font-size: 14px;
        }
        
        .user-info p {
            color: #6c757d;
            font-size: 12px;
            margin: 0;
        }
        
        /* Alerts */
        .alert-custom {
            border-radius: 8px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        
        /* Stats */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid #dee2e6;
            transition: all 0.2s ease;
        }
        
        .stat-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-bottom: 15px;
            color: white;
        }
        
        .stat-card:nth-child(1) .stat-icon { background: #198754; }
        .stat-card:nth-child(2) .stat-icon { background: #ffc107; }
        .stat-card:nth-child(3) .stat-icon { background: #0d6efd; }
        .stat-card:nth-child(4) .stat-icon { background: #6c757d; }
        
        .stat-value {
            font-size: 24px;
            font-weight: 600;
            color: #212529;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 13px;
        }
        
        /* Payment Card */
        .payment-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid #dee2e6;
            margin-bottom: 20px;
        }
        
        .invoice-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #dee2e6;
        }
        
        .invoice-item:last-child {
            border-bottom: none;
        }
        
        .invoice-total {
            font-size: 18px;
            font-weight: 600;
            color: #212529;
        }
        
        /* Payment Methods */
        .payment-methods {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        @media (max-width: 768px) {
            .payment-methods {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        .payment-method {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            background: white;
        }
        
        .payment-method:hover {
            border-color: #0d6efd;
            background: #f8f9fa;
        }
        
        .payment-method.selected {
            border-color: #0d6efd;
            background: #e7f1ff;
        }
        
        .payment-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-size: 20px;
            background: #f8f9fa;
            color: #0d6efd;
        }
        
        /* Instructions Box */
        .instructions-box {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            border-left: 3px solid #0d6efd;
        }
        
        /* Payment History */
        .payment-history {
            background: white;
            border-radius: 10px;
            border: 1px solid #dee2e6;
            overflow: hidden;
        }
        
        .payment-item {
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .payment-item:last-child {
            border-bottom: none;
        }
        
        .payment-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-paid {
            background: rgba(25, 135, 84, 0.1);
            color: #198754;
        }
        
        .status-pending {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }
        
        .status-failed {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        
        /* Payment Tips */
        .payment-tips {
            background: white;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid #dee2e6;
        }
        
        /* Button */
        .btn-pay {
            background: #198754;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 30px;
            font-weight: 500;
            font-size: 16px;
            transition: all 0.2s ease;
        }
        
        .btn-pay:hover:not(:disabled) {
            background: #157347;
        }
        
        .btn-pay:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            background: white;
            border-radius: 10px;
            border: 1px solid #dee2e6;
        }
        
        .empty-state i {
            font-size: 48px;
            color: #dee2e6;
            margin-bottom: 15px;
        }
        
        .empty-state h3 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
            color: #495057;
        }
        
        /* Responsive */
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
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            
            .header {
                flex-direction: column;
                gap: 10px;
                text-align: center;
                padding: 15px;
            }
            
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .user-profile {
                width: 100%;
                justify-content: center;
            }
        }
        
        @media (max-width: 576px) {
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .payment-methods {
                grid-template-columns: repeat(2, 1fr);
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
                        <h3>Elite Swimming</h3>
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
                        <i class="bi bi-calendar-check"></i>
                        <span class="nav-text">Book Classes</span>
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
                <form method="post" action="logout.php" style="margin:0;">
                    <button type="submit" name="confirm_logout" value="1" class="nav-link btn" style="background:none;border:none;width:100%;text-align:left;padding:12px 15px;">
                        <i class="bi bi-box-arrow-right"></i>
                        <span class="nav-text">Logout</span>
                    </button>
                </form>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-left">
                    <h1>Payments</h1>
                    <p>Manage your payments and view payment history</p>
                </div>
                <div class="user-profile">
                    <div class="user-avatar">
                        <?= isset($user['name']) ? strtoupper(substr($user['name'], 0, 1)) : 'U' ?>
                    </div>
                    <div class="user-info">
                        <h5><?= htmlspecialchars($user['name'] ?? 'User') ?></h5>
                        <p>Student ID: <?= htmlspecialchars($student_id) ?></p>
                    </div>
                </div>
            </header>
            
            <!-- Alerts -->
            <?php if ($success_msg): ?>
                <div class="alert alert-success alert-custom alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?= htmlspecialchars($success_msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($error_msg): ?>
                <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-circle-fill me-2"></i>
                    <?= htmlspecialchars($error_msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-currency-dollar"></i>
                    </div>
                    <div class="stat-value">$<?= number_format($total_paid, 2) ?></div>
                    <div class="stat-label">Total Paid</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div class="stat-value">$<?= number_format($total_pending_with_tax, 2) ?></div>
                    <div class="stat-label">Pending Balance</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div class="stat-value"><?= count($pending_payments) ?></div>
                    <div class="stat-label">Classes Pending</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-receipt"></i>
                    </div>
                    <div class="stat-value"><?= count($payments) ?></div>
                    <div class="stat-label">Total Transactions</div>
                </div>
            </div>

            <div class="row">
                <!-- Left Column -->
                <div class="col-lg-8">
                    <!-- Pending Payments -->
                    <div class="payment-card">
                        <h3 class="mb-4">Pending Payments</h3>
                        
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
                            <h4 class="mb-3">Select Payment Method</h4>
                            <div class="payment-methods" id="paymentMethods">
                                <?php foreach($payment_methods as $method => $details): ?>
                                    <div class="payment-method" data-method="<?= $method ?>" data-details='<?= htmlspecialchars(json_encode($details)) ?>'>
                                        <div class="payment-icon">
                                            <i class="bi <?= $details['icon'] ?>"></i>
                                        </div>
                                        <div class="fw-medium"><?= $details['name'] ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Payment Instructions -->
                            <div class="instructions-box" id="paymentInstructions">
                                <h5 class="mb-3">Payment Instructions</h5>
                                <p class="mb-0">Select a payment method to view instructions.</p>
                            </div>

                            <!-- Make Payment Button -->
                            <div class="text-center mt-4">
                                <form method="POST" id="paymentForm">
                                    <input type="hidden" name="amount" value="<?= $total_pending_with_tax ?>">
                                    <input type="hidden" name="payment_method" id="selectedPaymentMethod" value="">
                                    <button type="submit" name="initiate_payment" class="btn-pay" id="makePaymentBtn" disabled>
                                        Make Payment - $<?= number_format($total_pending_with_tax, 2) ?>
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="col-lg-4">
                    <div class="payment-history">
                        <div class="payment-item" style="background: #f8f9fa;">
                            <div>
                                <div class="fw-bold">Payment History</div>
                                <small class="text-muted">Recent transactions</small>
                            </div>
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
                                        <?php if(!empty($payment['reference_number'])): ?>
                                            <div class="small text-muted">Ref: <?= $payment['reference_number'] ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-end">
                                        <span class="payment-status status-<?= $payment['status'] ?>">
                                            <?= ucfirst($payment['status']) ?>
                                        </span>
                                        <?php if(!empty($payment['payment_method'])): ?>
                                            <div class="small text-muted mt-2">
                                                <?= $payment_methods[$payment['payment_method']]['name'] ?? ucfirst($payment['payment_method']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Payment Tips -->
                    <div class="payment-tips mt-4">
                        <h5 class="mb-3">
                            <i class="bi bi-lightbulb text-warning me-2"></i>Payment Tips
                        </h5>
                        <ul class="list-unstyled text-muted small">
                            <li class="mb-3">
                                <i class="bi bi-check-circle text-success me-2"></i>
                                Always include your name and booking ID as reference
                            </li>
                            <li class="mb-3">
                                <i class="bi bi-check-circle text-success me-2"></i>
                                Save payment confirmation
                            </li>
                            <li class="mb-3">
                                <i class="bi bi-check-circle text-success me-2"></i>
                                Payments are processed within 24 hours
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
                        <h5 class="mb-3">${methodDetails.name} Instructions</h5>
                        <p class="mb-0">${methodDetails.instructions}</p>
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
                
                if (!confirm('Are you sure you want to proceed with this payment?')) {
                    e.preventDefault();
                    return;
                }
                
                // Show loading state
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = 'Processing...';
                submitBtn.disabled = true;
            });
        });
    </script>
</body>
</html>