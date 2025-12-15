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
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Payments Management - Admin Dashboard</title>
  
  <!-- Favicon -->
  <link rel="icon" type="image/x-icon" href="../img/favicon.ico">
  
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  
  <!-- Custom CSS -->
  <link href="../css/style.css" rel="stylesheet">
  
  <style>
    .dashboard-container {
      padding: 20px;
      max-width: 1400px;
      margin: 0 auto;
    }
    
    .stat-card { 
      border-radius: 10px; 
      padding: 18px; 
      background: #fff; 
      box-shadow: 0 3px 10px rgba(15,23,42,0.08);
      border-left: 4px solid #4e73df;
      transition: transform 0.2s, box-shadow 0.2s;
      height: 100%;
    }
    
    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(15,23,42,0.12);
    }
    
    .stat-title { 
      color: #6b7280; 
      font-size: 14px; 
      margin-bottom: 8px;
      font-weight: 500;
    }
    
    .stat-value { 
      font-size: 24px; 
      font-weight: 700; 
      margin-bottom: 8px;
      color: #1f2937;
    }
    
    .stat-sub { 
      font-size: 13px; 
      color: #9ca3af;
    }
    
    .card {
      border-radius: 12px;
      box-shadow: 0 4px 12px rgba(15,23,42,0.08);
      border: none;
      margin-bottom: 24px;
    }
    
    .card-header {
      background: white;
      border-bottom: 1px solid #f1f5f9;
      padding: 16px 20px;
      border-radius: 12px 12px 0 0 !important;
    }
    
    .card-title {
      font-weight: 600;
      color: #1f2937;
      margin: 0;
      font-size: 18px;
    }
    
    .table th {
      font-weight: 600;
      color: #4b5563;
      font-size: 14px;
      border-top: none;
      background-color: #f8fafc;
    }
    
    .table td {
      font-size: 14px;
      vertical-align: middle;
    }
    
    .badge {
      font-size: 12px;
      font-weight: 500;
      padding: 4px 8px;
    }
    
    .payment-method-badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 11px;
      padding: 4px 8px;
      border-radius: 6px;
    }
    
    .action-buttons {
      display: flex;
      gap: 6px;
    }
    
    .payment-status-badge {
      font-size: 11px;
      padding: 4px 8px;
    }
    
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
    
    .empty-state {
      text-align: center;
      padding: 40px 20px;
      color: #6b7280;
    }
    
    .empty-state i {
      font-size: 3rem;
      margin-bottom: 16px;
      opacity: 0.5;
    }
    
    /* Sidebar and Main Content Layout */
    .sidebar {
      width: 260px;
      background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
      color: white;
      position: fixed;
      height: 100vh;
      z-index: 1000;
      transition: all 0.3s ease;
      box-shadow: 2px 0 20px rgba(0, 0, 0, 0.1);
    }
    
    .main-content {
      flex: 1;
      margin-left: 260px;
      transition: all 0.3s ease;
    }
    
    @media (max-width: 768px) {
      .sidebar {
        width: 70px;
      }
      
      .main-content {
        margin-left: 70px;
      }
      
      .payment-methods-grid {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      }
    }
    
    /* Topbar styling to match dashboard */
    .topbar {
      background: white;
      padding: 1rem 2rem;
      border-bottom: 1px solid #e2e8f0;
      position: sticky;
      top: 0;
      z-index: 100;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }
    
    .page-title h1 {
      font-size: 1.75rem;
      font-weight: 700;
      color: #1e293b;
      margin: 0;
    }
    
    .page-title p {
      color: #64748b;
      margin: 0.25rem 0 0 0;
      font-size: 0.875rem;
    }
    
    .topbar-actions {
      display: flex;
      align-items: center;
      gap: 1rem;
    }
    
    .user-profile {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.5rem 1rem;
      background: #f1f5f9;
      border-radius: 50px;
      cursor: pointer;
      transition: all 0.3s ease;
    }
    
    .user-profile:hover {
      background: #e2e8f0;
    }
    
    .user-avatar {
      width: 36px;
      height: 36px;
      background: linear-gradient(135deg, #4361ee, #3a0ca3);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: 600;
      font-size: 0.875rem;
    }
  </style>
</head>
<body>
  <div class="dashboard-wrapper">
    <!-- Sidebar -->
    <aside class="sidebar">
      <div class="sidebar-header">
        <a href="index.php" class="sidebar-brand">
          <i class="bi bi-droplet-half"></i>
          <span>AquaFlow Pro</span>
        </a>
      </div>
      
      <nav class="sidebar-nav">
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
          <a href="analytics.php" class="nav-link">
            <i class="bi bi-graph-up"></i>
            <span class="nav-text">Analytics</span>
          </a>
        </div>
        <div class="nav-item">
          <a href="settings.php" class="nav-link">
            <i class="bi bi-gear"></i>
            <span class="nav-text">Settings</span>
          </a>
        </div>
        <div class="nav-item mt-4">
          <a href="logout.php" class="nav-link text-danger">
            <i class="bi bi-box-arrow-right"></i>
            <span class="nav-text">Logout</span>
          </a>
        </div>
      </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
      <!-- Topbar -->
      <header class="topbar">
        <div class="page-title">
          <h1>Payments Management</h1>
          <p>Welcome back, <?= htmlspecialchars($user['name'] ?? 'Admin') ?>! Manage payments and payment methods for Zimbabwe.</p>
        </div>
        
        <div class="topbar-actions">
          <div class="dropdown">
            <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="dropdown">
              <i class="bi bi-plus-circle me-1"></i> Quick Action
            </button>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item" href="classes.php?action=new"><i class="bi bi-calendar-plus me-2"></i> Add New Class</a></li>
              <li><a class="dropdown-item" href="students.php?action=new"><i class="bi bi-person-plus me-2"></i> Add Student</a></li>
              <li><a class="dropdown-item" href="instructors.php?action=new"><i class="bi bi-person-badge me-2"></i> Add Instructor</a></li>
            </ul>
          </div>
          
          <div class="user-profile">
            <div class="user-avatar">
              <?= strtoupper(substr($user['name'] ?? 'A', 0, 1)) ?>
            </div>
            <div class="user-info">
              <div class="fw-medium"><?= htmlspecialchars($user['name'] ?? 'Admin') ?></div>
              <small>Administrator</small>
            </div>
            <i class="bi bi-chevron-down"></i>
          </div>
        </div>
      </header>

      <div class="dashboard-container">
        <!-- Alert Messages -->
        <?php if($success_message): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>
        
        <?php if($error_message): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row mb-4">
          <div class="col-md-3">
            <div class="stat-card">
              <div class="stat-title">Total Revenue</div>
              <div class="stat-value text-success">$<?= number_format($total_revenue, 2) ?></div>
              <div class="stat-sub">All paid payments</div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="stat-card">
              <div class="stat-title">Pending Payments</div>
              <div class="stat-value text-warning">$<?= number_format($pending_payments, 2) ?></div>
              <div class="stat-sub">Awaiting confirmation</div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="stat-card">
              <div class="stat-title">Total Transactions</div>
              <div class="stat-value"><?= number_format($total_transactions) ?></div>
              <div class="stat-sub">All payment records</div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="stat-card">
              <div class="stat-title">Successful</div>
              <div class="stat-value text-success"><?= number_format($successful_transactions) ?></div>
              <div class="stat-sub">Paid transactions</div>
            </div>
          </div>
        </div>

        <!-- Header Section -->
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h2 class="fw-bold">All Payments</h2>
            <p class="text-muted">Manage and track all payment transactions</p>
          </div>
          <div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
              <i class="bi bi-plus-circle me-2"></i>Record Manual Payment
            </button>
          </div>
        </div>

        <!-- Payments Table -->
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">Payment History</h5>
            <div class="d-flex gap-2">
              <input type="text" class="form-control form-control-sm" placeholder="Search payments..." id="searchInput">
              <select class="form-select form-select-sm" style="width: 120px;" id="statusFilter">
                <option value="">All Status</option>
                <option value="paid">Paid</option>
                <option value="pending">Pending</option>
                <option value="failed">Failed</option>
              </select>
            </div>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover mb-0">
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
                  <?php if(empty($payments)): ?>
                    <tr>
                      <td colspan="7" class="text-center py-4">
                        <div class="empty-state">
                          <i class="bi bi-credit-card"></i>
                          <h5>No Payments Found</h5>
                          <p>No payment records available.</p>
                        </div>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach($payments as $payment): ?>
                      <tr>
                        <td>
                          <div class="fw-medium"><?= htmlspecialchars($payment['student_name'] ?? 'Unknown') ?></div>
                          <small class="text-muted"><?= htmlspecialchars($payment['email'] ?? '') ?></small>
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
                            <span class="text-muted">Not specified</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <code><?= !empty($payment['reference_number']) ? htmlspecialchars($payment['reference_number']) : 'N/A' ?></code>
                        </td>
                        <td>
                          <div class="fw-medium"><?= date("M j, Y", strtotime($payment['payment_date'] ?? 'now')) ?></div>
                          <small class="text-muted"><?= date("g:i A", strtotime($payment['payment_date'] ?? 'now')) ?></small>
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
                            <button class="btn btn-outline-info btn-sm" title="View Details" data-bs-toggle="modal" data-bs-target="#paymentDetailsModal" 
                                    data-payment='<?= htmlspecialchars(json_encode($payment)) ?>'>
                              <i class="bi bi-eye"></i>
                            </button>
                            <button class="btn btn-outline-primary btn-sm" title="Edit Payment" data-bs-toggle="modal" data-bs-target="#editPaymentModal"
                                    data-payment='<?= htmlspecialchars(json_encode($payment)) ?>'>
                              <i class="bi bi-pencil"></i>
                            </button>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>

  <!-- Record Manual Payment Modal -->
  <div class="modal fade" id="recordPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Record Manual Payment</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form method="POST" id="recordPaymentForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Student</label>
                <select class="form-select" name="user_id" required>
                  <option value="">Select Student</option>
                  <?php foreach($students as $student): ?>
                    <option value="<?= $student['id'] ?>"><?= htmlspecialchars($student['name']) ?> (<?= htmlspecialchars($student['email']) ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Amount (USD)</label>
                <input type="number" class="form-control" name="amount" step="0.01" min="0" required>
              </div>
              <div class="col-12">
                <label class="form-label">Payment Method</label>
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
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" form="recordPaymentForm" name="record_manual_payment" class="btn btn-primary">Record Payment</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Payment Details Modal -->
  <div class="modal fade" id="paymentDetailsModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Payment Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="paymentDetailsContent">
          <!-- Content will be populated by JavaScript -->
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
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form method="POST" id="editPaymentForm">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
          <input type="hidden" name="payment_id" id="edit_payment_id">
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
            <button type="submit" name="update_payment_status" class="btn btn-primary">Update Payment</button>
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

      // Payment details modal
      const paymentDetailsModal = document.getElementById('paymentDetailsModal');
      const paymentDetailsContent = document.getElementById('paymentDetailsContent');
      
      paymentDetailsModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        const payment = JSON.parse(button.getAttribute('data-payment'));
        
        const paymentMethods = <?= json_encode($zim_payment_methods) ?>;
        const paymentMethod = payment.payment_method ? paymentMethods[payment.payment_method] : null;
        
        let content = `
          <div class="row g-3">
            <div class="col-12">
              <h6>Student Information</h6>
              <p class="mb-1"><strong>Name:</strong> ${payment.student_name || 'Unknown'}</p>
              <p class="mb-1"><strong>Email:</strong> ${payment.email || 'N/A'}</p>
            </div>
            <div class="col-12">
              <h6>Payment Details</h6>
              <p class="mb-1"><strong>Amount:</strong> $${parseFloat(payment.amount || 0).toFixed(2)}</p>
              <p class="mb-1"><strong>Status:</strong> 
                <span class="badge bg-${payment.status === 'paid' ? 'success' : payment.status === 'pending' ? 'warning' : 'danger'}">
                  ${payment.status || 'unknown'}
                </span>
              </p>
              ${paymentMethod ? `
                <p class="mb-1"><strong>Method:</strong> 
                  <span class="badge bg-${paymentMethod.color}-subtle text-${paymentMethod.color}">
                    <i class="bi ${paymentMethod.icon}"></i> ${paymentMethod.name}
                  </span>
                </p>
              ` : ''}
              <p class="mb-1"><strong>Reference:</strong> ${payment.reference_number || 'N/A'}</p>
              <p class="mb-1"><strong>Date:</strong> ${new Date(payment.payment_date || Date.now()).toLocaleDateString()}</p>
              ${payment.description ? `<p class="mb-1"><strong>Description:</strong> ${payment.description}</p>` : ''}
            </div>
          </div>
        `;
        
        paymentDetailsContent.innerHTML = content;
      });

      // Edit payment modal
      const editPaymentModal = document.getElementById('editPaymentModal');
      
      editPaymentModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        const payment = JSON.parse(button.getAttribute('data-payment'));
        
        document.getElementById('edit_payment_id').value = payment.id || '';
        document.getElementById('edit_amount').value = payment.amount || '';
        document.getElementById('edit_payment_method').value = payment.payment_method || '';
        document.getElementById('edit_reference_number').value = payment.reference_number || '';
        document.getElementById('edit_status').value = payment.status || 'pending';
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

      // Mobile sidebar toggle
      const sidebarToggle = document.createElement('button');
      sidebarToggle.className = 'btn btn-primary btn-sm d-md-none position-fixed bottom-0 start-0 m-3';
      sidebarToggle.innerHTML = '<i class="bi bi-list"></i>';
      sidebarToggle.style.zIndex = '1050';
      sidebarToggle.onclick = function() {
        document.querySelector('.sidebar').classList.toggle('active');
        document.querySelector('.main-content').classList.toggle('active');
      };
      document.body.appendChild(sidebarToggle);
    });
  </script>
</body>
</html>