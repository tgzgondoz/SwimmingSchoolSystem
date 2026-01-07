<?php
// admin/payments.php - Professional Payments Management
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

// Authentication and role check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$admin_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

// Get admin user info
$user = [];
$user_stmt = $conn->prepare("SELECT name, email, phone FROM users WHERE id = ? AND role = 'admin'");
if ($user_stmt) {
    $user_stmt->bind_param("i", $admin_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    $user = $user_result->fetch_assoc() ?: [];
    $user_stmt->close();
}

// Zimbabwe payment methods
$zim_payment_methods = [
    'ecocash' => ['name' => 'EcoCash', 'icon' => 'bi-phone', 'color' => '#16a34a'],
    'onemoney' => ['name' => 'OneMoney', 'icon' => 'bi-phone', 'color' => '#0891b2'],
    'paynow' => ['name' => 'PayNow ZW', 'icon' => 'bi-qr-code', 'color' => '#2563eb'],
    'zip' => ['name' => 'ZIP (ZimSwitch)', 'icon' => 'bi-credit-card', 'color' => '#d97706'],
    'cash_usd' => ['name' => 'Cash (USD)', 'icon' => 'bi-cash', 'color' => '#6b7280'],
    'cash_zig' => ['name' => 'Cash (ZIG)', 'icon' => 'bi-cash-coin', 'color' => '#16a34a'],
    'bank_transfer' => ['name' => 'Bank Transfer', 'icon' => 'bi-bank', 'color' => '#1f2937']
];

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_status'])) {
        $payment_id = intval($_POST['payment_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $payment_method = trim($_POST['payment_method'] ?? '');
        $reference_number = trim($_POST['reference_number'] ?? '');
        
        if ($payment_id <= 0 || !in_array($status, ['pending', 'paid', 'failed'])) {
            $error_msg = "Invalid payment data.";
        } else {
            // Check if updated_at column exists
            $check_column = $conn->query("SHOW COLUMNS FROM payments LIKE 'updated_at'");
            if ($check_column && $check_column->num_rows > 0) {
                // Column exists, use full query
                $stmt = $conn->prepare("UPDATE payments SET status = ?, payment_method = ?, reference_number = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("sssi", $status, $payment_method, $reference_number, $payment_id);
            } else {
                // Column doesn't exist, use simpler query
                $stmt = $conn->prepare("UPDATE payments SET status = ?, payment_method = ?, reference_number = ? WHERE id = ?");
                $stmt->bind_param("sssi", $status, $payment_method, $reference_number, $payment_id);
            }
            
            if ($stmt->execute()) {
                $success_msg = "Payment status updated successfully!";
                
                // Get payment details for logging
                $log_stmt = $conn->prepare("SELECT amount, user_id FROM payments WHERE id = ?");
                $log_stmt->bind_param("i", $payment_id);
                $log_stmt->execute();
                $log_stmt->bind_result($amount, $user_id);
                $log_stmt->fetch();
                $log_stmt->close();
                
                // Log the activity
                logActivity($conn, $admin_id, 'Payment Updated', "Updated payment #$payment_id ($$amount) to $status");
            } else {
                $error_msg = "Failed to update payment: " . $stmt->error;
            }
            $stmt->close();
        }
    }
    
    elseif (isset($_POST['record_payment'])) {
        $student_id = intval($_POST['student_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $payment_method = trim($_POST['payment_method'] ?? '');
        $reference_number = trim($_POST['reference_number'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = trim($_POST['status'] ?? 'pending');
        
        if ($student_id <= 0 || $amount <= 0 || empty($payment_method)) {
            $error_msg = "Please fill in all required fields with valid data.";
        } else {
            // Check if payment_date column exists in POST, otherwise use current date
            $payment_date = isset($_POST['payment_date']) && !empty($_POST['payment_date']) 
                ? $_POST['payment_date'] 
                : date('Y-m-d H:i:s');
            
            // Check if updated_at column exists
            $check_column = $conn->query("SHOW COLUMNS FROM payments LIKE 'updated_at'");
            if ($check_column && $check_column->num_rows > 0) {
                // Column exists, use full query
                $stmt = $conn->prepare("INSERT INTO payments (user_id, amount, payment_method, reference_number, description, status, payment_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->bind_param("idsssss", $student_id, $amount, $payment_method, $reference_number, $description, $status, $payment_date);
            } else {
                // Column doesn't exist, use simpler query
                $stmt = $conn->prepare("INSERT INTO payments (user_id, amount, payment_method, reference_number, description, status, payment_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("idsssss", $student_id, $amount, $payment_method, $reference_number, $description, $status, $payment_date);
            }
            
            if ($stmt->execute()) {
                $payment_id = $stmt->insert_id;
                $success_msg = "Payment recorded successfully!";
                
                // Get student name for logging
                $student_stmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
                $student_stmt->bind_param("i", $student_id);
                $student_stmt->execute();
                $student_stmt->bind_result($student_name);
                $student_stmt->fetch();
                $student_stmt->close();
                
                // Log the activity
                logActivity($conn, $admin_id, 'Payment Recorded', "Recorded payment #$payment_id ($$amount) for $student_name");
            } else {
                $error_msg = "Failed to record payment: " . $stmt->error;
            }
            $stmt->close();
        }
    }
    
    // Store messages in session for redirect
    if ($success_msg) {
        $_SESSION['success_msg'] = $success_msg;
    }
    if ($error_msg) {
        $_SESSION['error_msg'] = $error_msg;
    }
    
    // Redirect to prevent form resubmission
    header("Location: payments.php");
    exit();
}

// Load messages from session
if (isset($_SESSION['success_msg'])) {
    $success_msg = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}
if (isset($_SESSION['error_msg'])) {
    $error_msg = $_SESSION['error_msg'];
    unset($_SESSION['error_msg']);
}

// Handle filtering and searching
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$method_filter = $_GET['method'] ?? 'all';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];
$param_types = '';

if (!empty($search)) {
    $where_conditions[] = "(u.name LIKE ? OR u.email LIKE ? OR p.reference_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'sss';
}

if ($status_filter !== 'all') {
    $where_conditions[] = "p.status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}

if ($method_filter !== 'all' && isset($zim_payment_methods[$method_filter])) {
    $where_conditions[] = "p.payment_method = ?";
    $params[] = $method_filter;
    $param_types .= 's';
}

try {
    // Get payments with user information
    $sql = "SELECT SQL_CALC_FOUND_ROWS p.*, u.name AS student_name, u.email 
            FROM payments p 
            LEFT JOIN users u ON p.user_id = u.id 
            WHERE " . implode(' AND ', $where_conditions) . " 
            ORDER BY p.payment_date DESC LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    $param_types .= 'ii';
    
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $payments_result = $stmt->get_result();
    $payments = $payments_result->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    
    // Get total count for pagination
    $total_result = $conn->query("SELECT FOUND_ROWS() as total");
    $total_payments = $total_result->fetch_assoc()['total'];
    $total_pages = ceil($total_payments / $limit);
    
    // Get payment statistics
    $total_revenue = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'paid'")->fetch_assoc()['total'];
    $pending_payments = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'pending'")->fetch_assoc()['total'];
    $failed_payments = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'failed'")->fetch_assoc()['total'];
    $total_transactions = $conn->query("SELECT COUNT(*) as total FROM payments")->fetch_assoc()['total'];
    
    // Get payment method statistics
    $method_stats = [];
    $method_stmt = $conn->prepare("
        SELECT payment_method, COUNT(*) as count, SUM(amount) as total 
        FROM payments 
        WHERE status = 'paid' AND payment_method IS NOT NULL
        GROUP BY payment_method
        ORDER BY total DESC
    ");
    $method_stmt->execute();
    $method_result = $method_stmt->get_result();
    while ($row = $method_result->fetch_assoc()) {
        $method_stats[] = $row;
    }
    $method_stmt->close();
    
    // Get monthly revenue
    $monthly_revenue = [];
    $monthly_stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(payment_date, '%Y-%m') as month,
            SUM(amount) as total
        FROM payments 
        WHERE status = 'paid'
        GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
        ORDER BY month DESC
        LIMIT 6
    ");
    $monthly_stmt->execute();
    $monthly_result = $monthly_stmt->get_result();
    while ($row = $monthly_result->fetch_assoc()) {
        $monthly_revenue[] = $row;
    }
    $monthly_stmt->close();
    
    // Get students for manual payment recording
    $students = $conn->query("SELECT id, name, email FROM users WHERE role = 'student' AND status = 'active' ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC) ?: [];
    
    // Get recent payments for dashboard
    $recent_payments = $conn->query("
        SELECT p.*, u.name as student_name 
        FROM payments p 
        LEFT JOIN users u ON p.user_id = u.id 
        ORDER BY p.created_at DESC 
        LIMIT 5
    ")->fetch_all(MYSQLI_ASSOC) ?: [];
    
} catch (Exception $e) {
    error_log("Payments query error: " . $e->getMessage());
    $payments = [];
    $total_payments = 0;
    $total_pages = 0;
    $total_revenue = $pending_payments = $failed_payments = $total_transactions = 0;
    $method_stats = [];
    $monthly_revenue = [];
    $students = [];
    $recent_payments = [];
    $error_msg = $error_msg ?: "Unable to load payment data. Please try again later.";
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --success: #16a34a;
            --warning: #d97706;
            --danger: #dc2626;
            --info: #0891b2;
            --light: #f8fafc;
            --dark: #1e293b;
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
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--gray-50);
            min-height: 100vh;
            color: var(--gray-800);
            line-height: 1.5;
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: 240px;
            background-color: white;
            border-right: 1px solid var(--gray-200);
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 1000;
            padding: 20px 0;
        }
        
        .logo-area {
            padding: 0 20px 20px;
            border-bottom: 1px solid var(--gray-200);
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
            width: 36px;
            height: 36px;
            background-color: var(--primary);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }
        
        .logo-text h3 {
            font-weight: 600;
            font-size: 18px;
            margin: 0;
            color: var(--gray-900);
        }
        
        .logo-text span {
            font-size: 12px;
            color: var(--gray-500);
        }
        
        .nav-menu {
            padding: 0 15px;
        }
        
        .nav-item {
            margin-bottom: 4px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 8px;
            color: var(--gray-600);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .nav-link:hover {
            background-color: var(--gray-100);
            color: var(--primary);
        }
        
        .nav-link.active {
            background-color: var(--primary);
            color: white;
        }
        
        .nav-link i {
            width: 18px;
            text-align: center;
            font-size: 16px;
        }
        
        .logout-section {
            padding: 20px;
            position: absolute;
            bottom: 0;
            width: 100%;
            border-top: 1px solid var(--gray-200);
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 240px;
            padding: 24px;
        }
        
        /* Header */
        .header {
            background-color: white;
            border-radius: 8px;
            padding: 20px 24px;
            margin-bottom: 24px;
            border: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left h1 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--gray-900);
        }
        
        .header-left p {
            color: var(--gray-600);
            margin: 0;
            font-size: 14px;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            background-color: var(--gray-50);
            padding: 8px 16px;
            border-radius: 6px;
        }
        
        .user-avatar {
            width: 36px;
            height: 36px;
            background-color: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 500;
            font-size: 14px;
        }
        
        .user-info h5 {
            font-weight: 500;
            margin: 0;
            font-size: 14px;
        }
        
        .user-info p {
            color: var(--gray-500);
            font-size: 12px;
            margin: 0;
        }
        
        /* Alerts */
        .alert-custom {
            border-radius: 8px;
            border: 1px solid;
            padding: 16px 20px;
            margin-bottom: 24px;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            border: 1px solid var(--gray-200);
            position: relative;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
        }
        
        .stat-card:nth-child(1)::before { background-color: var(--success); }
        .stat-card:nth-child(2)::before { background-color: var(--warning); }
        .stat-card:nth-child(3)::before { background-color: var(--danger); }
        .stat-card:nth-child(4)::before { background-color: var(--primary); }
        .stat-card:nth-child(5)::before { background-color: var(--info); }
        
        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 16px;
            color: white;
        }
        
        .stat-card:nth-child(1) .stat-icon { background-color: var(--success); }
        .stat-card:nth-child(2) .stat-icon { background-color: var(--warning); }
        .stat-card:nth-child(3) .stat-icon { background-color: var(--danger); }
        .stat-card:nth-child(4) .stat-icon { background-color: var(--primary); }
        .stat-card:nth-child(5) .stat-icon { background-color: var(--info); }
        
        .stat-content h3 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--gray-900);
        }
        
        .stat-content p {
            color: var(--gray-600);
            font-size: 14px;
            margin: 0;
        }
        
        .stat-subtext {
            font-size: 12px;
            color: var(--gray-500);
            margin-top: 8px;
        }
        
        /* Payment Methods Grid */
        .methods-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin-top: 20px;
        }
        
        .method-card {
            background-color: white;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            padding: 16px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .method-card:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }
        
        .method-card.active {
            border-color: var(--primary);
            background-color: var(--gray-50);
        }
        
        .method-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 8px;
            color: white;
            font-size: 18px;
        }
        
        .method-name {
            font-weight: 500;
            font-size: 14px;
            margin-bottom: 4px;
        }
        
        .method-stats {
            font-size: 12px;
            color: var(--gray-600);
        }
        
        /* Filter Section */
        .filter-section {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            border: 1px solid var(--gray-200);
            margin-bottom: 24px;
        }
        
        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .filter-header h3 {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
            color: var(--gray-900);
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
        }
        
        .form-group label {
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--gray-800);
            font-size: 14px;
            display: block;
        }
        
        /* Table Container */
        .table-container {
            background-color: white;
            border-radius: 8px;
            border: 1px solid var(--gray-200);
            margin-bottom: 24px;
        }
        
        .table-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-header h3 {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
            color: var(--gray-900);
        }
        
        .table-wrapper {
            overflow-x: auto;
        }
        
        .table {
            margin: 0;
            width: 100%;
            font-size: 14px;
        }
        
        .table thead {
            background-color: var(--gray-50);
        }
        
        .table th {
            font-weight: 600;
            color: var(--gray-700);
            padding: 12px 16px;
            border-bottom: 2px solid var(--gray-200);
            white-space: nowrap;
        }
        
        .table td {
            padding: 12px 16px;
            vertical-align: middle;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .table tbody tr:hover {
            background-color: var(--gray-50);
        }
        
        /* Status Badges */
        .badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-weight: 500;
            font-size: 12px;
        }
        
        .badge-success {
            background-color: rgba(22, 163, 74, 0.1);
            color: var(--success);
        }
        
        .badge-warning {
            background-color: rgba(217, 119, 6, 0.1);
            color: var(--warning);
        }
        
        .badge-danger {
            background-color: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .badge-secondary {
            background-color: rgba(107, 114, 128, 0.1);
            color: var(--gray-600);
        }
        
        /* Payment Method Badges */
        .payment-method-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 4px;
            background-color: var(--gray-100);
            color: var(--gray-700);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 6px;
        }
        
        .btn-icon {
            width: 28px;
            height: 28px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 48px 20px;
        }
        
        .empty-state i {
            font-size: 48px;
            color: var(--gray-300);
            margin-bottom: 16px;
        }
        
        .empty-state h4 {
            font-weight: 600;
            color: var(--gray-600);
            margin-bottom: 8px;
            font-size: 16px;
        }
        
        .empty-state p {
            color: var(--gray-500);
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        /* Pagination */
        .pagination-container {
            padding: 16px 20px;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: center;
        }
        
        .pagination {
            margin: 0;
        }
        
        .page-link {
            border: 1px solid var(--gray-300);
            color: var(--primary);
            padding: 6px 12px;
            border-radius: 6px;
            margin: 0 2px;
            font-size: 14px;
        }
        
        .page-item.active .page-link {
            background-color: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        
        /* Modal */
        .modal-content {
            border-radius: 8px;
            border: 1px solid var(--gray-200);
        }
        
        .modal-header {
            background-color: var(--primary);
            color: white;
            border-bottom: none;
            border-radius: 8px 8px 0 0;
            padding: 20px;
        }
        
        .modal-title {
            font-weight: 600;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            border-top: 1px solid var(--gray-200);
            padding: 16px 20px;
        }
        
        .form-label {
            font-weight: 500;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .form-label.required::after {
            content: ' *';
            color: var(--danger);
        }
        
        /* Monthly Revenue Chart */
        .revenue-chart {
            margin-top: 20px;
        }
        
        .chart-bar {
            height: 4px;
            background-color: var(--gray-200);
            border-radius: 2px;
            margin-bottom: 8px;
            overflow: hidden;
        }
        
        .chart-fill {
            height: 100%;
            background-color: var(--primary);
            border-radius: 2px;
        }
        
        .chart-label {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: var(--gray-600);
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                width: 64px;
            }
            
            .main-content {
                margin-left: 64px;
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
                padding: 16px;
            }
            
            .header {
                flex-direction: column;
                gap: 12px;
                text-align: center;
            }
            
            .filter-header {
                flex-direction: column;
                gap: 12px;
                align-items: stretch;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .methods-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .table-header {
                flex-direction: column;
                gap: 12px;
            }
            
            .action-buttons {
                flex-wrap: wrap;
                justify-content: center;
            }
        }
        
        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .methods-grid {
                grid-template-columns: 1fr;
            }
            
            .table-wrapper {
                font-size: 13px;
            }
            
            .table th, .table td {
                padding: 8px 10px;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 4px;
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
                    <button type="submit" name="confirm_logout" value="1" class="nav-link btn" style="background:none;border:none;width:100%;text-align:left;padding:10px 12px;">
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
                    <h1>Manage Payments</h1>
                    <p>Total Revenue: $<?= number_format($total_revenue, 2) ?> • <?= $current_date ?></p>
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
            
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                    <div class="stat-content">
                        <h3>$<?= number_format($total_revenue, 2) ?></h3>
                        <p>Total Revenue</p>
                        <div class="stat-subtext">All successful payments</div>
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
                        <i class="bi bi-x-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3>$<?= number_format($failed_payments, 2) ?></h3>
                        <p>Failed Payments</p>
                        <div class="stat-subtext">Require attention</div>
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
                        <i class="bi bi-graph-up"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= count($monthly_revenue) > 0 ? '$' . number_format($monthly_revenue[0]['total'] ?? 0, 2) : '$0.00' ?></h3>
                        <p>This Month</p>
                        <div class="stat-subtext">Current month revenue</div>
                    </div>
                </div>
            </div>
            
            <!-- Payment Methods Section -->
            <div class="filter-section">
                <h4 class="mb-4" style="font-size: 16px;">Payment Methods Performance</h4>
                <div class="methods-grid">
                    <?php foreach ($zim_payment_methods as $method_key => $method): ?>
                        <?php 
                        $method_stat = array_filter($method_stats, function($stat) use ($method_key) {
                            return $stat['payment_method'] === $method_key;
                        });
                        $method_stat = $method_stat ? array_values($method_stat)[0] : null;
                        ?>
                        <div class="method-card" onclick="filterByMethod('<?= $method_key ?>')">
                            <div class="method-icon" style="background-color: <?= $method['color'] ?>">
                                <i class="bi <?= $method['icon'] ?>"></i>
                            </div>
                            <div class="method-name"><?= $method['name'] ?></div>
                            <div class="method-stats">
                                <?php if ($method_stat): ?>
                                    <?= $method_stat['count'] ?> transactions • $<?= number_format($method_stat['total'], 2) ?>
                                <?php else: ?>
                                    No transactions
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Monthly Revenue Chart -->
            <?php if (!empty($monthly_revenue)): ?>
                <div class="filter-section">
                    <h4 class="mb-4" style="font-size: 16px;">Monthly Revenue (Last 6 Months)</h4>
                    <div class="revenue-chart">
                        <?php 
                        $max_amount = max(array_column($monthly_revenue, 'total'));
                        foreach ($monthly_revenue as $revenue): 
                            $percentage = $max_amount > 0 ? ($revenue['total'] / $max_amount) * 100 : 0;
                        ?>
                            <div class="mb-3">
                                <div class="chart-label">
                                    <span><?= date('F Y', strtotime($revenue['month'] . '-01')) ?></span>
                                    <span>$<?= number_format($revenue['total'], 2) ?></span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-fill" style="width: <?= $percentage ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-header">
                    <h3>Filter Payments</h3>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#recordPaymentModal" style="font-size: 14px;">
                        <i class="bi bi-plus-circle me-2"></i> Record Payment
                    </button>
                </div>
                <form method="GET" class="filter-grid">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Student name, email, or reference...">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select class="form-select" name="status">
                            <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                            <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="failed" <?= $status_filter === 'failed' ? 'selected' : '' ?>>Failed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Payment Method</label>
                        <select class="form-select" name="method">
                            <option value="all" <?= $method_filter === 'all' ? 'selected' : '' ?>>All Methods</option>
                            <?php foreach ($zim_payment_methods as $method_key => $method): ?>
                                <option value="<?= $method_key ?>" <?= $method_filter === $method_key ? 'selected' : '' ?>>
                                    <?= $method['name'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100" style="font-size: 14px;">
                            <i class="bi bi-funnel me-2"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Payments Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3>Payment History</h3>
                    <div>
                        <span class="text-muted" style="font-size: 14px;">Showing <?= count($payments) ?> of <?= $total_payments ?> payments</span>
                    </div>
                </div>
                
                <div class="table-wrapper">
                    <?php if (empty($payments)): ?>
                        <div class="empty-state">
                            <i class="bi bi-credit-card"></i>
                            <h4>No Payments Found</h4>
                            <p>No payments match your search criteria. Try adjusting your filters.</p>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#recordPaymentModal" style="font-size: 14px;">
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
                                <?php foreach ($payments as $payment): ?>
                                    <?php $method = $zim_payment_methods[$payment['payment_method']] ?? null; ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="student-avatar me-3">
                                                    <?= isset($payment['student_name']) ? strtoupper(substr($payment['student_name'], 0, 1)) : 'U' ?>
                                                </div>
                                                <div>
                                                    <div class="fw-medium" style="font-size: 14px;"><?= htmlspecialchars($payment['student_name'] ?? 'Unknown') ?></div>
                                                    <small class="text-muted" style="font-size: 12px;"><?= htmlspecialchars($payment['email'] ?? '') ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-success">$<?= number_format($payment['amount'], 2) ?></div>
                                        </td>
                                        <td>
                                            <?php if ($method): ?>
                                                <span class="payment-method-badge">
                                                    <i class="bi <?= $method['icon'] ?>"></i>
                                                    <?= $method['name'] ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">Unknown</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <code style="font-size: 12px;"><?= !empty($payment['reference_number']) ? htmlspecialchars($payment['reference_number']) : 'N/A' ?></code>
                                        </td>
                                        <td>
                                            <?= date('M j, Y', strtotime($payment['payment_date'])) ?><br>
                                            <small class="text-muted" style="font-size: 12px;"><?= date('g:i A', strtotime($payment['payment_date'])) ?></small>
                                        </td>
                                        <td>
                                            <?php if ($payment['status'] === 'paid'): ?>
                                                <span class="badge badge-success">Paid</span>
                                            <?php elseif ($payment['status'] === 'pending'): ?>
                                                <span class="badge badge-warning">Pending</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Failed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-outline-primary btn-sm btn-icon" 
                                                        data-bs-toggle="modal" data-bs-target="#viewPaymentModal"
                                                        onclick="viewPayment(<?= htmlspecialchars(json_encode($payment)) ?>, <?= htmlspecialchars(json_encode($zim_payment_methods)) ?>)"
                                                        title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button class="btn btn-outline-primary btn-sm btn-icon" 
                                                        data-bs-toggle="modal" data-bs-target="#editPaymentModal"
                                                        onclick="editPayment(<?= htmlspecialchars(json_encode($payment)) ?>)"
                                                        title="Edit Payment">
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
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination-container">
                        <nav aria-label="Page navigation">
                            <ul class="pagination">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" aria-label="Previous">
                                            <span aria-hidden="true">&laquo;</span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                                <?= $i ?>
                                            </a>
                                        </li>
                                    <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">...</span>
                                        </li>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" aria-label="Next">
                                            <span aria-hidden="true">&raquo;</span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Recent Payments -->
            <?php if (!empty($recent_payments)): ?>
                <div class="table-container">
                    <div class="table-header">
                        <h3>Recent Payments</h3>
                    </div>
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_payments as $payment): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($payment['student_name'] ?? 'Unknown') ?></td>
                                        <td class="fw-bold text-success">$<?= number_format($payment['amount'], 2) ?></td>
                                        <td>
                                            <?php $method = $zim_payment_methods[$payment['payment_method']] ?? null; ?>
                                            <?php if ($method): ?>
                                                <span class="badge badge-secondary">
                                                    <i class="bi <?= $method['icon'] ?> me-1"></i>
                                                    <?= substr($method['name'], 0, 10) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($payment['status'] === 'paid'): ?>
                                                <span class="badge badge-success">Paid</span>
                                            <?php elseif ($payment['status'] === 'pending'): ?>
                                                <span class="badge badge-warning">Pending</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Failed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?= date('H:i', strtotime($payment['payment_date'])) ?></small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
    
    <!-- Record Payment Modal -->
    <div class="modal fade" id="recordPaymentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Record New Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="recordPaymentForm">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required">Student</label>
                                <select class="form-select" name="student_id" required>
                                    <option value="">Select Student</option>
                                    <?php foreach ($students as $student): ?>
                                        <option value="<?= $student['id'] ?>"><?= htmlspecialchars($student['name']) ?> (<?= htmlspecialchars($student['email']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">Amount (USD)</label>
                                <input type="number" class="form-control" name="amount" step="0.01" min="0.01" required placeholder="0.00">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">Payment Method</label>
                                <select class="form-select" name="payment_method" required>
                                    <option value="">Select Method</option>
                                    <?php foreach ($zim_payment_methods as $method_key => $method): ?>
                                        <option value="<?= $method_key ?>"><?= $method['name'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="pending">Pending</option>
                                    <option value="paid" selected>Paid</option>
                                    <option value="failed">Failed</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Reference Number</label>
                                <input type="text" class="form-control" name="reference_number" placeholder="EC123456, ZW789012...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Payment Date</label>
                                <input type="datetime-local" class="form-control" name="payment_date" value="<?= date('Y-m-d\TH:i') ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="3" placeholder="Payment for swimming classes, equipment, etc..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="record_payment" class="btn btn-primary">Record Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- View Payment Modal -->
    <div class="modal fade" id="viewPaymentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Payment Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted">Student</label>
                            <p class="fw-medium" id="view_student_name"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Amount</label>
                            <p class="fw-bold text-success" id="view_amount"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Payment Method</label>
                            <p id="view_payment_method"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Status</label>
                            <p id="view_status"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Reference Number</label>
                            <p id="view_reference"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Payment Date</label>
                            <p id="view_date"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Created At</label>
                            <p id="view_created_at"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Last Updated</label>
                            <p id="view_updated_at"></p>
                        </div>
                        <div class="col-12">
                            <label class="form-label text-muted">Description</label>
                            <div class="border rounded p-3 bg-light">
                                <p id="view_description" class="mb-0"></p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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
                    <input type="hidden" name="payment_id" id="edit_payment_id">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label required">Amount (USD)</label>
                                <input type="number" class="form-control" name="amount" id="edit_amount" step="0.01" min="0.01" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label required">Payment Method</label>
                                <select class="form-select" name="payment_method" id="edit_payment_method" required>
                                    <option value="">Select Method</option>
                                    <?php foreach ($zim_payment_methods as $method_key => $method): ?>
                                        <option value="<?= $method_key ?>"><?= $method['name'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label required">Status</label>
                                <select class="form-select" name="status" id="edit_status" required>
                                    <option value="pending">Pending</option>
                                    <option value="paid">Paid</option>
                                    <option value="failed">Failed</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Reference Number</label>
                                <input type="text" class="form-control" name="reference_number" id="edit_reference_number">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_status" class="btn btn-primary">Update Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Edit payment function
            window.editPayment = function(payment) {
                document.getElementById('edit_payment_id').value = payment.id;
                document.getElementById('edit_amount').value = payment.amount;
                document.getElementById('edit_payment_method').value = payment.payment_method || '';
                document.getElementById('edit_status').value = payment.status || 'pending';
                document.getElementById('edit_reference_number').value = payment.reference_number || '';
            }
            
            // View payment function
            window.viewPayment = function(payment, paymentMethods) {
                document.getElementById('view_student_name').textContent = payment.student_name || 'Unknown';
                document.getElementById('view_amount').textContent = '$' + parseFloat(payment.amount || 0).toFixed(2);
                
                const method = payment.payment_method ? paymentMethods[payment.payment_method] : null;
                if (method) {
                    document.getElementById('view_payment_method').innerHTML = `
                        <span class="payment-method-badge">
                            <i class="bi ${method.icon}"></i> ${method.name}
                        </span>
                    `;
                } else {
                    document.getElementById('view_payment_method').textContent = 'Not specified';
                }
                
                // Status
                const statusText = payment.status || 'unknown';
                let statusClass = 'badge-secondary';
                switch(payment.status) {
                    case 'paid': statusClass = 'badge-success'; break;
                    case 'pending': statusClass = 'badge-warning'; break;
                    case 'failed': statusClass = 'badge-danger'; break;
                }
                document.getElementById('view_status').innerHTML = `<span class="badge ${statusClass}">${statusText.charAt(0).toUpperCase() + statusText.slice(1)}</span>`;
                
                document.getElementById('view_reference').textContent = payment.reference_number || 'N/A';
                document.getElementById('view_date').textContent = payment.payment_date ? 
                    new Date(payment.payment_date).toLocaleString('en-US') : 'N/A';
                document.getElementById('view_created_at').textContent = payment.created_at ? 
                    new Date(payment.created_at).toLocaleString('en-US') : 'N/A';
                document.getElementById('view_updated_at').textContent = payment.updated_at ? 
                    new Date(payment.updated_at).toLocaleString('en-US') : 'N/A';
                document.getElementById('view_description').textContent = payment.description || 'None';
            }
            
            // Filter by payment method
            window.filterByMethod = function(method) {
                const url = new URL(window.location);
                url.searchParams.set('method', method);
                window.location.href = url.toString();
            }
            
            // Form validation
            const recordForm = document.getElementById('recordPaymentForm');
            if (recordForm) {
                recordForm.addEventListener('submit', function(e) {
                    const amount = this.querySelector('[name="amount"]');
                    const paymentMethod = this.querySelector('[name="payment_method"]');
                    const studentId = this.querySelector('[name="student_id"]');
                    
                    if (!studentId.value) {
                        e.preventDefault();
                        alert('Please select a student.');
                        return false;
                    }
                    
                    if (!paymentMethod.value) {
                        e.preventDefault();
                        alert('Please select a payment method.');
                        return false;
                    }
                    
                    if (!amount.value || parseFloat(amount.value) <= 0) {
                        e.preventDefault();
                        alert('Please enter a valid amount greater than 0.');
                        return false;
                    }
                    
                    return true;
                });
            }
            
            // Search functionality
            const searchInput = document.querySelector('input[name="search"]');
            const searchForm = searchInput?.closest('form');
            
            if (searchInput && searchForm) {
                let searchTimeout;
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        if (this.value.length >= 3 || this.value.length === 0) {
                            searchForm.submit();
                        }
                    }, 500);
                });
            }
            
            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                    bsAlert.close();
                }, 5000);
            });
            
            // Payment method cards selection in modal
            const methodCards = document.querySelectorAll('.method-card');
            methodCards.forEach(card => {
                card.addEventListener('click', function() {
                    methodCards.forEach(c => c.classList.remove('active'));
                    this.classList.add('active');
                });
            });
        });
    </script>
</body>
</html>