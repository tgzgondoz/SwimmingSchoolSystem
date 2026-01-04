
<?php
// admin/payments.php
session_start();
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

// Check database connection
if (!$conn) {
    die("Database connection error.");
}

requireRole('admin');
$user = getCurrentUser($conn);

// Initialize messages
$success_message = $error_message = '';

// Handle payment actions with CSRF protection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error_message = "Security token invalid. Please try again.";
    } else {
        if (isset($_POST['update_payment_status'])) {
            $payment_id = filter_input(INPUT_POST, 'payment_id', FILTER_VALIDATE_INT);
            $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_SPECIAL_CHARS);
            $payment_method = filter_input(INPUT_POST, 'payment_method', FILTER_SANITIZE_SPECIAL_CHARS);
            $reference_number = filter_input(INPUT_POST, 'reference_number', FILTER_SANITIZE_SPECIAL_CHARS);
            
            if ($payment_id && in_array($status, ['pending', 'paid', 'failed'])) {
                $stmt = $conn->prepare("UPDATE payments SET status = ?, payment_method = ?, reference_number = ? WHERE id = ?");
                $stmt->bind_param('sssi', $status, $payment_method, $reference_number, $payment_id);
                $stmt->execute();
                
                if ($stmt->affected_rows > 0) {
                    $success_message = "Payment status updated successfully!";
                } else {
                    $error_message = "Failed to update payment status.";
                }
                $stmt->close();
            } else {
                $error_message = "Invalid payment data.";
            }
        }
        
        if (isset($_POST['record_manual_payment'])) {
            $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
            $payment_method = filter_input(INPUT_POST, 'payment_method', FILTER_SANITIZE_SPECIAL_CHARS);
            $reference_number = filter_input(INPUT_POST, 'reference_number', FILTER_SANITIZE_SPECIAL_CHARS);
            $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_SPECIAL_CHARS);
            
            if ($user_id && $amount && $amount > 0 && $payment_method) {
                // Validate payment method
                $allowed_methods = array_keys($zim_payment_methods ?? []);
                if (!in_array($payment_method, $allowed_methods)) {
                    $error_message = "Invalid payment method.";
                } else {
                    $stmt = $conn->prepare("INSERT INTO payments (user_id, amount, payment_method, reference_number, description, status, payment_date) VALUES (?, ?, ?, ?, ?, 'paid', NOW())");
                    $stmt->bind_param('idsss', $user_id, $amount, $payment_method, $reference_number, $description);
                    $stmt->execute();
                    
                    if ($stmt->affected_rows > 0) {
                        $success_message = "Manual payment recorded successfully!";
                    } else {
                        $error_message = "Failed to record manual payment.";
                    }
                    $stmt->close();
                }
            } else {
                $error_message = "Invalid payment data. Please check all fields.";
            }
        }
    }
}

// Generate CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// Zimbabwe payment methods (define before use in SQL)
$zim_payment_methods = [
    'ecocash' => [
        'name' => 'EcoCash',
        'icon' => 'bi-phone',
        'color' => 'success',
        'instructions' => 'Use merchant number: 077 123 4567\nReference: Student Name + Invoice Number'
    ],
    'onemoney' => [
        'name' => 'OneMoney',
        'icon' => 'bi-phone',
        'color' => 'info',
        'instructions' => 'Use merchant number: 078 123 4567\nReference: Student Name + Invoice Number'
    ],
    'paynow' => [
        'name' => 'PayNow ZW',
        'icon' => 'bi-qr-code',
        'color' => 'primary',
        'instructions' => 'Scan QR code or use merchant code: AQUAFLOW\nReference: Student Name + Invoice Number'
    ],
    'zip' => [
        'name' => 'ZIP (ZimSwitch)',
        'icon' => 'bi-credit-card',
        'color' => 'warning',
        'instructions' => 'Bank: CBZ\nAccount: 45678901234\nReference: Student Name + Invoice Number'
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
        'instructions' => 'Bank: CBZ\nAccount: 45678901234\nBranch: Harare Main\nReference: Student Name + Invoice Number'
    ]
];

// Get all payments with user information using prepared statement
$payments = [];
$stmt = $conn->prepare("
    SELECT p.*, u.name AS student_name, u.email
    FROM payments p 
    LEFT JOIN users u ON p.user_id = u.id 
    ORDER BY p.payment_date DESC
");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $payments = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get students for manual payment recording
$students = [];
$stmt = $conn->prepare("SELECT id, name, email FROM users WHERE role = 'student' ORDER BY name");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $students = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Payment statistics using prepared statements
$total_revenue = $pending_payments = $total_transactions = $successful_transactions = 0;

$stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE status = 'paid'");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $total_revenue = $result->fetch_assoc()['total'] ?? 0;
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE status = 'pending'");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $pending_payments = $result->fetch_assoc()['total'] ?? 0;
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM payments");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $total_transactions = $result->fetch_assoc()['total'] ?? 0;
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM payments WHERE status = 'paid'");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $successful_transactions = $result->fetch_assoc()['total'] ?? 0;
    $stmt->close();
}

// Payment method statistics
$payment_methods_stats = [];
$stmt = $conn->prepare("
    SELECT payment_method, COUNT(*) as count, SUM(amount) as total 
    FROM payments 
    WHERE status = 'paid' AND payment_method IS NOT NULL
    GROUP BY payment_method
");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $payment_methods_stats = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get current date and time
$current_date = date('l, F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Payments | Elite Swimming Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0d6efd;
            --primary-dark: #0a58ca;
            --success: #198754;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #0dcaf0;
            --light: #f8f9fa;
            --dark: #212529;
            --purple: #6f42c1;
            --pink: #d63384;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
            min-height: 100vh;
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
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 1000;
            padding: 20px 0;
        }
        
        .logo-area {
            padding: 0 25px 25px;
            border-bottom: 1px solid #eee;
            margin-bottom: 20px;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--dark);
        }
        
        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--purple) 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }
        
        .logo-text h3 {
            font-weight: 700;
            font-size: 22px;
            margin: 0;
            background: linear-gradient(90deg, var(--primary), var(--purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
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
            border-radius: 10px;
            color: #495057;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .nav-link:hover {
            background: rgba(13, 110, 253, 0.1);
            color: var(--primary);
            transform: translateX(5px);
        }
        
        .nav-link.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(13, 110, 253, 0.2);
        }
        
        .nav-link.active:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, #0a3d9c 100%);
        }
        
        .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 18px;
        }
        
        .logout-section {
            padding: 20px;
            position: absolute;
            bottom: 0;
            width: 100%;
            border-top: 1px solid #eee;
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
            border-radius: 15px;
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left h1 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
            background: linear-gradient(90deg, var(--primary), var(--purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .header-left p {
            color: #6c757d;
            margin: 0;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 15px;
            background: var(--light);
            padding: 12px 20px;
            border-radius: 10px;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
        }
        
        .user-info h5 {
            font-weight: 600;
            margin: 0;
        }
        
        .user-info p {
            color: #6c757d;
            font-size: 14px;
            margin: 0;
        }
        
        /* Alerts */
        .alert-custom {
            border-radius: 12px;
            border: none;
            padding: 20px 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
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
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            border: 1px solid #e9ecef;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
        }
        
        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 20px;
            color: white;
        }
        
        .stat-card:nth-child(1) .stat-icon { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-card:nth-child(2) .stat-icon { background: linear-gradient(135deg, var(--success) 0%, #157347 100%); }
        .stat-card:nth-child(3) .stat-icon { background: linear-gradient(135deg, var(--warning) 0%, #ffca2c 100%); }
        .stat-card:nth-child(4) .stat-icon { background: linear-gradient(135deg, var(--info) 0%, #0891b2 100%); }
        .stat-card:nth-child(5) .stat-icon { background: linear-gradient(135deg, var(--purple) 0%, #5936a0 100%); }
        
        .stat-content h3 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--dark);
        }
        
        .stat-content p {
            color: #6c757d;
            font-size: 14px;
            margin: 0;
        }
        
        .stat-subtext {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
        }
        
        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            border: 1px solid #e9ecef;
        }
        
        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .filter-header h3 {
            font-size: 20px;
            font-weight: 600;
            margin: 0;
            color: var(--dark);
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .form-group label {
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--dark);
        }
        
        /* Table Container */
        .table-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            overflow: hidden;
            border: 1px solid #e9ecef;
            margin-bottom: 30px;
        }
        
        .table-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-header h3 {
            font-size: 20px;
            font-weight: 600;
            margin: 0;
            color: var(--dark);
        }
        
        .table-wrapper {
            overflow-x: auto;
        }
        
        .table {
            margin: 0;
            width: 100%;
        }
        
        .table thead {
            background: #f8f9fa;
        }
        
        .table th {
            font-weight: 600;
            color: #495057;
            padding: 15px 20px;
            border-bottom: 2px solid #e9ecef;
            white-space: nowrap;
        }
        
        .table td {
            padding: 15px 20px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
        }
        
        .table tbody tr {
            transition: all 0.2s ease;
        }
        
        .table tbody tr:hover {
            background: #f8f9fa;
        }
        
        /* Status Badges */
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 12px;
        }
        
        .badge-success {
            background: rgba(25, 135, 84, 0.1);
            color: var(--success);
        }
        
        .badge-warning {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning);
        }
        
        .badge-danger {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
        }
        
        .badge-info {
            background: rgba(13, 202, 240, 0.1);
            color: var(--info);
        }
        
        .badge-primary {
            background: rgba(13, 110, 253, 0.1);
            color: var(--primary);
        }
        
        .badge-secondary {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-icon {
            width: 32px;
            height: 32px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
        }
        
        /* Payment Method Badges */
        .payment-method-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 6px;
        }
        
        /* Payment Methods Grid */
        .payment-methods-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin: 20px 0;
        }
        
        .payment-method-card {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .payment-method-card:hover {
            border-color: #3b82f6;
            background: #f8fafc;
        }
        
        .payment-method-card.active {
            border-color: #3b82f6;
            background: #eff6ff;
        }
        
        .payment-icon {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 8px;
            font-size: 20px;
        }
        
        .instructions-box {
            background: #f8fafc;
            border-radius: 8px;
            padding: 16px;
            margin-top: 12px;
            font-size: 12px;
            white-space: pre-line;
        }
        
        .currency-badge {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
            margin-left: 4px;
        }
        
        .usd-badge { background: #dcfce7; color: #166534; }
        .zig-badge { background: #fef3c7; color: #92400e; }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state i {
            font-size: 64px;
            color: #dee2e6;
            margin-bottom: 20px;
        }
        
        .empty-state h4 {
            font-weight: 600;
            color: #6c757d;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: #6c757d;
            margin-bottom: 20px;
        }
        
        /* Pagination */
        .pagination-container {
            padding: 20px 25px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: center;
        }
        
        .pagination {
            margin: 0;
        }
        
        .page-link {
            border: 1px solid #dee2e6;
            color: var(--primary);
            padding: 8px 16px;
            border-radius: 8px;
            margin: 0 2px;
        }
        
        .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        
        /* Modal */
        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border-bottom: none;
            border-radius: 15px 15px 0 0;
            padding: 25px;
        }
        
        .modal-title {
            font-weight: 600;
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .modal-footer {
            border-top: 1px solid #e9ecef;
            padding: 20px 25px;
        }
        
        .form-label {
            font-weight: 500;
            margin-bottom: 8px;
        }
        
        .form-label.required::after {
            content: ' *';
            color: var(--danger);
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease forwards;
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
                padding: 20px;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .table-header {
                flex-direction: column;
                gap: 15px;
            }
            
            .action-buttons {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .payment-methods-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
        }
        
        @media (max-width: 576px) {
            .table-wrapper {
                font-size: 14px;
            }
            
            .table th, .table td {
                padding: 10px;
            }
            
            .payment-methods-grid {
                grid-template-columns: 1fr;
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
                        <i class="bi bi-droplet-half"></i>
                    </div>
                    <div class="logo-text">
                        <h3>Elite Swimming Academy</h3>
                        <span>Admin Portal</span>
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
                    <a href="students.php" class="nav-link">
                        <i class="bi bi-people"></i>
                        <span class="nav-text">Students</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="instructors.php" class="nav-link">
                        <i class="bi bi-person-badge"></i>
                        <span class="nav-text">Instructors</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="classes.php" class="nav-link">
                        <i class="bi bi-calendar-week"></i>
                        <span class="nav-text">Classes</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="bookings.php" class="nav-link">
                        <i class="bi bi-journal-check"></i>
                        <span class="nav-text">Bookings</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="payments.php" class="nav-link active">
                        <i class="bi bi-credit-card"></i>
                        <span class="nav-text">Payments</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="settings.php" class="nav-link">
                        <i class="bi bi-gear"></i>
                        <span class="nav-text">Settings</span>
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
            <header class="header fade-in">
                <div class="header-left">
                    <h1>Manage Payments</h1>
                    <p>Total Transactions: <?= $total_transactions ?> • <?= $current_date ?></p>
                </div>
                <div class="user-profile">
                    <div class="user-avatar">
                        <?= isset($user['name']) ? strtoupper(substr($user['name'], 0, 1)) : 'A' ?>
                    </div>
                    <div class="user-info">
                        <h5><?= htmlspecialchars($user['name'] ?? 'Admin') ?></h5>
                        <p>Administrator</p>
                    </div>
                </div>
            </header>
            
            <!-- Alerts -->
            <?php if ($success_message): ?>
                <div class="alert alert-success alert-custom alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?= htmlspecialchars($success_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-circle-fill me-2"></i>
                    <?= htmlspecialchars($error_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Stats Cards -->
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                    <div class="stat-content">
                        <h3>$<?= number_format($total_revenue, 2) ?></h3>
                        <p>Total Revenue</p>
                        <div class="stat-subtext">All paid payments</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div class="stat-content">
                        <h3>$<?= number_format($pending_payments, 2) ?></h3>
                        <p>Pending Payments</p>
                        <div class="stat-subtext">Awaiting confirmation</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-credit-card"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= number_format($total_transactions) ?></h3>
                        <p>Total Transactions</p>
                        <div class="stat-subtext">All payment records</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= number_format($successful_transactions) ?></h3>
                        <p>Successful</p>
                        <div class="stat-subtext">Paid transactions</div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Section -->
            <div class="filter-section fade-in">
                <div class="filter-header">
                    <h3>Payment Management</h3>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
                        <i class="bi bi-plus-circle me-2"></i> Record Payment
                    </button>
                </div>
                <div class="filter-grid">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" class="form-control" id="searchInput" placeholder="Search by student name, email, or reference...">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select class="form-select" id="statusFilter">
                            <option value="">All Status</option>
                            <option value="paid">Paid</option>
                            <option value="pending">Pending</option>
                            <option value="failed">Failed</option>
                        </select>
                    </div>
                    <div class="form-group d-flex align-items-end">
                        <button type="button" id="clearFilters" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-arrow-clockwise me-2"></i> Reset Filters
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Payments Table -->
            <div class="table-container fade-in">
                <div class="table-header">
                    <h3>Payment History</h3>
                    <div>
                        <span class="text-muted">Showing <?= count($payments) ?> transactions</span>
                    </div>
                </div>
                
                <div class="table-wrapper">
                    <?php if (empty($payments)): ?>
                        <div class="empty-state">
                            <i class="bi bi-credit-card"></i>
                            <h4>No Payments Found</h4>
                            <p>No payment records available.</p>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
                                <i class="bi bi-plus-circle me-2"></i> Record New Payment
                            </button>
                        </div>
                    <?php else: ?>
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Amount</th>
                                    <th>Payment Method</th>
                                    <th>Reference</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($payments as $payment): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($payment['student_name'] ?? 'Unknown') ?></div>
                                            <div class="text-muted" style="font-size: 13px;">
                                                <?= htmlspecialchars($payment['email'] ?? '') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-success">$<?= number_format($payment['amount'] ?? 0, 2) ?></div>
                                        </td>
                                        <td>
                                            <?php if(!empty($payment['payment_method'])): ?>
                                                <span class="payment-method-badge bg-<?= $zim_payment_methods[$payment['payment_method']]['color'] ?? 'secondary' ?>-subtle text-<?= $zim_payment_methods[$payment['payment_method']]['color'] ?? 'secondary' ?>">
                                                    <i class="bi <?= $zim_payment_methods[$payment['payment_method']]['icon'] ?? 'bi-credit-card' ?>"></i>
                                                    <?= htmlspecialchars($zim_payment_methods[$payment['payment_method']]['name'] ?? ucfirst($payment['payment_method'])) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">Not specified</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <code><?= !empty($payment['reference_number']) ? htmlspecialchars($payment['reference_number']) : 'N/A' ?></code>
                                        </td>
                                        <td>
                                            <div class="fw-medium"><?= date("M j, Y", strtotime($payment['payment_date'] ?? 'now')) ?></div>
                                            <div class="text-muted" style="font-size: 13px;">
                                                <?= date("g:i A", strtotime($payment['payment_date'] ?? 'now')) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                <input type="hidden" name="payment_id" value="<?= $payment['id'] ?>">
                                                <input type="hidden" name="payment_method" value="<?= htmlspecialchars($payment['payment_method'] ?? '') ?>">
                                                <input type="hidden" name="reference_number" value="<?= htmlspecialchars($payment['reference_number'] ?? '') ?>">
                                                <select name="status" class="form-select form-select-sm payment-status" style="width: 100px;" onchange="this.form.submit()">
                                                    <option value="pending" <?= ($payment['status'] ?? '') == 'pending' ? 'selected' : '' ?>>Pending</option>
                                                    <option value="paid" <?= ($payment['status'] ?? '') == 'paid' ? 'selected' : '' ?>>Paid</option>
                                                    <option value="failed" <?= ($payment['status'] ?? '') == 'failed' ? 'selected' : '' ?>>Failed</option>
                                                </select>
                                                <input type="hidden" name="update_payment_status" value="1">
                                            </form>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-outline-primary btn-sm btn-icon" 
                                                        title="View Details" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#paymentDetailsModal" 
                                                        onclick="viewPayment(<?= htmlspecialchars(json_encode($payment)) ?>, <?= htmlspecialchars(json_encode($zim_payment_methods)) ?>)">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button class="btn btn-outline-primary btn-sm btn-icon" 
                                                        title="Edit Payment" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#editPaymentModal"
                                                        onclick="editPayment(<?= htmlspecialchars(json_encode($payment)) ?>)">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                
                <!-- Pagination would go here if implemented -->
            </div>
            
            <!-- Payment Methods Statistics -->
            <?php if (!empty($payment_methods_stats)): ?>
                <div class="table-container fade-in">
                    <div class="table-header">
                        <h3>Payment Methods Summary</h3>
                    </div>
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Payment Method</th>
                                    <th>Transactions</th>
                                    <th>Total Amount</th>
                                    <th>Average Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payment_methods_stats as $method): ?>
                                    <tr>
                                        <td>
                                            <span class="payment-method-badge bg-<?= $zim_payment_methods[$method['payment_method']]['color'] ?? 'secondary' ?>-subtle text-<?= $zim_payment_methods[$method['payment_method']]['color'] ?? 'secondary' ?>">
                                                <i class="bi <?= $zim_payment_methods[$method['payment_method']]['icon'] ?? 'bi-credit-card' ?>"></i>
                                                <?= htmlspecialchars($zim_payment_methods[$method['payment_method']]['name'] ?? ucfirst($method['payment_method'])) ?>
                                            </span>
                                        </td>
                                        <td><?= $method['count'] ?></td>
                                        <td>$<?= number_format($method['total'] ?? 0, 2) ?></td>
                                        <td>$<?= $method['count'] > 0 ? number_format(($method['total'] ?? 0) / $method['count'], 2) : '0.00' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
    
    <!-- Record Manual Payment Modal -->
    <div class="modal fade" id="recordPaymentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Record Manual Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="recordPaymentForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required">Student</label>
                                <select class="form-select" name="user_id" required>
                                    <option value="">Select Student</option>
                                    <?php foreach($students as $student): ?>
                                        <option value="<?= $student['id'] ?>"><?= htmlspecialchars($student['name']) ?> (<?= htmlspecialchars($student['email']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">Amount (USD)</label>
                                <input type="number" class="form-control" name="amount" step="0.01" min="0" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label required">Payment Method</label>
                                <div class="payment-methods-grid">
                                    <?php foreach($zim_payment_methods as $method => $details): ?>
                                        <label class="payment-method-card">
                                            <input type="radio" name="payment_method" value="<?= $method ?>" required class="d-none">
                                            <div class="payment-icon bg-<?= $details['color'] ?> bg-opacity-10 text-<?= $details['color'] ?>">
                                                <i class="bi <?= $details['icon'] ?>"></i>
                                            </div>
                                            <h6 class="mb-2"><?= $details['name'] ?></h6>
                                            <?php if(strpos($method, 'cash') !== false): ?>
                                                <span class="currency-badge <?= $method === 'cash_usd' ? 'usd-badge' : 'zig-badge' ?>">
                                                    <?= $method === 'cash_usd' ? 'USD' : 'ZIG' ?>
                                                </span>
                                            <?php endif; ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Reference Number</label>
                                <input type="text" class="form-control" name="reference_number" placeholder="e.g., EC123456, ZW789012">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="2" placeholder="Payment for swimming classes..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" form="recordPaymentForm" name="record_manual_payment" class="btn btn-primary">Record Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Payment Details Modal -->
    <div class="modal fade" id="paymentDetailsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Payment Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <h6>Student Information</h6>
                            <p class="mb-1"><strong>Name:</strong> <span id="detail_student_name"></span></p>
                            <p class="mb-1"><strong>Email:</strong> <span id="detail_student_email"></span></p>
                        </div>
                        <div class="col-12">
                            <h6>Payment Details</h6>
                            <p class="mb-1"><strong>Amount:</strong> <span id="detail_amount"></span></p>
                            <p class="mb-1"><strong>Status:</strong> <span id="detail_status"></span></p>
                            <p class="mb-1"><strong>Method:</strong> <span id="detail_method"></span></p>
                            <p class="mb-1"><strong>Reference:</strong> <span id="detail_reference"></span></p>
                            <p class="mb-1"><strong>Date:</strong> <span id="detail_date"></span></p>
                            <p class="mb-1"><strong>Description:</strong> <span id="detail_description"></span></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Payment Modal -->
    <div class="modal fade" id="editPaymentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="editPaymentForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="payment_id" id="edit_payment_id">
                    <input type="hidden" name="update_payment_status" value="1">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Amount (USD)</label>
                                <input type="number" class="form-control" name="amount" id="edit_amount" step="0.01" min="0" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Payment Method</label>
                                <select class="form-select" name="payment_method" id="edit_payment_method" required>
                                    <option value="">Select Payment Method</option>
                                    <?php foreach($zim_payment_methods as $method => $details): ?>
                                        <option value="<?= $method ?>"><?= $details['name'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Reference Number</label>
                                <input type="text" class="form-control" name="reference_number" id="edit_reference_number">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="edit_status" required>
                                    <option value="pending">Pending</option>
                                    <option value="paid">Paid</option>
                                    <option value="failed">Failed</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Payment method selection in modal
            const paymentMethodCards = document.querySelectorAll('.payment-method-card');
            paymentMethodCards.forEach(card => {
                card.addEventListener('click', function() {
                    paymentMethodCards.forEach(c => c.classList.remove('active'));
                    this.classList.add('active');
                    this.querySelector('input').checked = true;
                });
            });

            // Search functionality
            document.getElementById('searchInput').addEventListener('input', function(e) {
                const searchTerm = e.target.value.toLowerCase();
                const rows = document.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(searchTerm) ? '' : 'none';
                });
            });

            // Status filter functionality
            document.getElementById('statusFilter').addEventListener('change', function(e) {
                const status = e.target.value;
                const rows = document.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    if (!status) {
                        row.style.display = '';
                        return;
                    }
                    
                    const statusSelect = row.querySelector('.payment-status');
                    if (statusSelect && statusSelect.value === status) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });

            // Clear filters
            document.getElementById('clearFilters').addEventListener('click', function() {
                document.getElementById('searchInput').value = '';
                document.getElementById('statusFilter').value = '';
                
                const rows = document.querySelectorAll('tbody tr');
                rows.forEach(row => {
                    row.style.display = '';
                });
            });

            // View payment function
            window.viewPayment = function(payment, paymentMethods) {
                document.getElementById('detail_student_name').textContent = payment.student_name || 'Unknown';
                document.getElementById('detail_student_email').textContent = payment.email || 'N/A';
                document.getElementById('detail_amount').textContent = '$' + parseFloat(payment.amount || 0).toFixed(2);
                
                // Status
                const statusText = payment.status || 'unknown';
                let statusClass = 'badge-secondary';
                switch(payment.status) {
                    case 'paid': statusClass = 'badge-success'; break;
                    case 'pending': statusClass = 'badge-warning'; break;
                    case 'failed': statusClass = 'badge-danger'; break;
                }
                document.getElementById('detail_status').innerHTML = `<span class="badge ${statusClass}">${statusText.charAt(0).toUpperCase() + statusText.slice(1)}</span>`;
                
                // Payment method
                const paymentMethod = payment.payment_method ? paymentMethods[payment.payment_method] : null;
                if (paymentMethod) {
                    document.getElementById('detail_method').innerHTML = `
                        <span class="payment-method-badge bg-${paymentMethod.color}-subtle text-${paymentMethod.color}">
                            <i class="bi ${paymentMethod.icon}"></i> ${paymentMethod.name}
                        </span>
                    `;
                } else {
                    document.getElementById('detail_method').textContent = 'Not specified';
                }
                
                document.getElementById('detail_reference').textContent = payment.reference_number || 'N/A';
                document.getElementById('detail_date').textContent = new Date(payment.payment_date || Date.now()).toLocaleString('en-US');
                document.getElementById('detail_description').textContent = payment.description || 'N/A';
            };

            // Edit payment function
            window.editPayment = function(payment) {
                document.getElementById('edit_payment_id').value = payment.id || '';
                document.getElementById('edit_amount').value = payment.amount || '';
                document.getElementById('edit_payment_method').value = payment.payment_method || '';
                document.getElementById('edit_reference_number').value = payment.reference_number || '';
                document.getElementById('edit_status').value = payment.status || 'pending';
            };

            // Add fade-in animation to cards
            const cards = document.querySelectorAll('.fade-in');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
            
            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                    bsAlert.close();
                }, 5000);
            });
            
            // Form validation for record payment
            const recordForm = document.getElementById('recordPaymentForm');
            if (recordForm) {
                recordForm.addEventListener('submit', function(e) {
                    const amount = this.querySelector('input[name="amount"]');
                    const paymentMethod = this.querySelector('input[name="payment_method"]:checked');
                    
                    if (!paymentMethod) {
                        alert('Please select a payment method.');
                        e.preventDefault();
                        return false;
                    }
                    
                    if (!amount.value || parseFloat(amount.value) <= 0) {
                        alert('Please enter a valid amount greater than 0.');
                        e.preventDefault();
                        return false;
                    }
                    
                    return true;
                });
            }
        });
    </script>
</body>
</html>
