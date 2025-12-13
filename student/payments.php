[file name]: student/payments.php
[file content begin]
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

// Get student's payments
$payments = $conn->query("
    SELECT * FROM payments 
    WHERE user_id = $student_id 
    ORDER BY payment_date DESC
")->fetch_all(MYSQLI_ASSOC);

// Get pending payments (unpaid classes)
$pending_payments = $conn->query("
    SELECT c.*, b.id as booking_id, b.created_at as booking_date
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    WHERE b.user_id = $student_id 
    AND b.status = 'confirmed'
    AND NOT EXISTS (
        SELECT 1 FROM payments p 
        WHERE p.user_id = $student_id 
        AND p.class_id = c.id 
        AND p.status = 'paid'
    )
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
    WHERE b.user_id = $student_id 
    AND b.status = 'confirmed'
    AND NOT EXISTS (
        SELECT 1 FROM payments p 
        WHERE p.user_id = $student_id 
        AND p.class_id = c.id 
        AND p.status = 'paid'
    )
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
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Payments - Student Dashboard</title>
  
  <link href="../css/style.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    .dashboard-container {
      padding: 20px;
      max-width: 1400px;
      margin: 0 auto;
    }

    .balance-card {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      border-radius: 12px;
      padding: 30px;
      color: white;
      margin-bottom: 24px;
    }

    .payment-card {
      background: white;
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
      margin-bottom: 20px;
    }

    .payment-methods {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 15px;
      margin: 20px 0;
    }

    .payment-method {
      border: 2px solid #e5e7eb;
      border-radius: 8px;
      padding: 15px;
      text-align: center;
      cursor: pointer;
      transition: all 0.3s;
    }

    .payment-method:hover {
      border-color: #667eea;
      background: #f8fafc;
    }

    .payment-method.selected {
      border-color: #667eea;
      background: #eff6ff;
    }

    .payment-icon {
      width: 50px;
      height: 50px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 10px;
      font-size: 24px;
    }

    .payment-history {
      background: white;
      border-radius: 12px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
      overflow: hidden;
    }

    .payment-item {
      padding: 15px 20px;
      border-bottom: 1px solid #e5e7eb;
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
      padding: 5px 10px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 500;
    }

    .status-paid {
      background: #dcfce7;
      color: #166534;
    }

    .status-pending {
      background: #fef3c7;
      color: #92400e;
    }

    .status-failed {
      background: #fee2e2;
      color: #dc2626;
    }

    .badge-currency {
      font-size: 10px;
      padding: 2px 6px;
      border-radius: 4px;
      margin-left: 4px;
    }

    .usd-badge {
      background: #dcfce7;
      color: #166534;
    }

    .zig-badge {
      background: #fef3c7;
      color: #92400e;
    }

    .empty-state {
      text-align: center;
      padding: 40px 20px;
      color: #6b7280;
    }

    .empty-state i {
      font-size: 48px;
      opacity: 0.5;
      margin-bottom: 15px;
    }

    .instructions-box {
      background: #f8fafc;
      border-radius: 8px;
      padding: 20px;
      margin-top: 20px;
      white-space: pre-line;
    }

    .btn-pay {
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      color: white;
      border: none;
      border-radius: 8px;
      padding: 12px 30px;
      font-weight: 500;
      transition: all 0.3s;
    }

    .btn-pay:hover {
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
    }

    .invoice-item {
      display: flex;
      justify-content: space-between;
      padding: 10px 0;
      border-bottom: 1px solid #e5e7eb;
    }

    .invoice-item:last-child {
      border-bottom: none;
    }

    .invoice-total {
      font-size: 18px;
      font-weight: 600;
      color: #1f2937;
    }
  </style>
</head>
<body>
  <div class="sidebar"><?php include 'components/sidebar.php'; ?></div>
  
  <div class="main-content">
    <div class="header"><?php include 'components/header.php'; ?></div>
    
    <div class="dashboard-container">
      <!-- Page Header -->
      <div class="mb-4">
        <h1 class="fw-bold">Payments</h1>
        <p class="text-muted">Manage your payments and view payment history</p>
      </div>

      <!-- Balance Overview -->
      <div class="balance-card">
        <div class="row align-items-center">
          <div class="col-md-8">
            <h2 class="mb-2">Payment Overview</h2>
            <p class="mb-0">Track your payment status and make payments</p>
          </div>
          <div class="col-md-4 text-end">
            <div class="display-4 fw-bold">$<?= number_format($total_paid, 2) ?></div>
            <div class="text-white-50">Total Paid</div>
          </div>
        </div>
        <div class="row mt-4">
          <div class="col-md-4">
            <div class="bg-white bg-opacity-20 p-3 rounded">
              <div class="small">Pending Balance</div>
              <div class="h4 mb-0">$<?= number_format($total_pending_with_tax, 2) ?></div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="bg-white bg-opacity-20 p-3 rounded">
              <div class="small">Classes Pending Payment</div>
              <div class="h4 mb-0"><?= count($pending_payments) ?></div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="bg-white bg-opacity-20 p-3 rounded">
              <div class="small">Total Transactions</div>
              <div class="h4 mb-0"><?= count($payments) ?></div>
            </div>
          </div>
        </div>
      </div>

      <div class="row">
        <!-- Left Column: Pending Payments & Payment Methods -->
        <div class="col-lg-8">
          <!-- Pending Payments -->
          <div class="payment-card">
            <h4 class="mb-4">Pending Payments</h4>
            <?php if(empty($pending_payments)): ?>
              <div class="empty-state">
                <i class="bi bi-check-circle"></i>
                <h5>No Pending Payments</h5>
                <p>All your classes are paid for.</p>
              </div>
            <?php else: ?>
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
                <div class="invoice-item mt-3 pt-3 border-top">
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

              <!-- Payment Methods -->
              <h5 class="mb-3">Select Payment Method</h5>
              <div class="payment-methods" id="paymentMethods">
                <?php foreach($zim_payment_methods as $method => $details): ?>
                  <div class="payment-method" data-method="<?= $method ?>">
                    <div class="payment-icon bg-<?= $details['color'] ?> bg-opacity-10 text-<?= $details['color'] ?>">
                      <i class="bi <?= $details['icon'] ?>"></i>
                    </div>
                    <div class="fw-medium"><?= $details['name'] ?></div>
                    <?php if(strpos($method, 'cash') !== false): ?>
                      <span class="badge-currency <?= $method === 'cash_usd' ? 'usd-badge' : 'zig-badge' ?>">
                        <?= $method === 'cash_usd' ? 'USD' : 'ZIG' ?>
                      </span>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>

              <!-- Payment Instructions -->
              <div class="instructions-box" id="paymentInstructions">
                <h6 class="mb-3">Payment Instructions</h6>
                <p class="mb-0">Select a payment method to view instructions.</p>
              </div>

              <!-- Make Payment Button -->
              <div class="text-center mt-4">
                <button class="btn btn-pay" id="makePaymentBtn" disabled>
                  <i class="bi bi-credit-card me-2"></i>Make Payment
                </button>
                <p class="text-muted mt-2 small">
                  Once payment is made, upload proof of payment for verification.
                </p>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Right Column: Payment History -->
        <div class="col-lg-4">
          <div class="payment-history">
            <div class="payment-item bg-light">
              <div class="fw-bold">Payment History</div>
              <small class="text-muted">Recent transactions</small>
            </div>
            
            <?php if(empty($payments)): ?>
              <div class="empty-state">
                <i class="bi bi-credit-card"></i>
                <p>No payment history</p>
              </div>
            <?php else: ?>
              <?php foreach($payments as $payment): ?>
                <div class="payment-item">
                  <div>
                    <div class="fw-medium">$<?= number_format($payment['amount'], 2) ?></div>
                    <small class="text-muted">
                      <?= date('M j, Y', strtotime($payment['payment_date'])) ?>
                    </small>
                  </div>
                  <div class="text-end">
                    <span class="payment-status status-<?= $payment['status'] ?>">
                      <i class="bi bi-circle-fill" style="font-size: 8px;"></i>
                      <?= ucfirst($payment['status']) ?>
                    </span>
                    <?php if(!empty($payment['payment_method'])): ?>
                      <div class="small text-muted mt-1">
                        <?= $zim_payment_methods[$payment['payment_method']]['name'] ?? ucfirst($payment['payment_method']) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if(count($payments) > 0): ?>
              <div class="payment-item bg-light">
                <a href="payment-history.php" class="text-primary text-decoration-none">
                  <i class="bi bi-clock-history me-2"></i>View Full History
                </a>
              </div>
            <?php endif; ?>
          </div>

          <!-- Payment Tips -->
          <div class="payment-card mt-4">
            <h6 class="mb-3"><i class="bi bi-lightbulb me-2"></i>Payment Tips</h6>
            <ul class="list-unstyled text-muted small">
              <li class="mb-2">
                <i class="bi bi-check-circle text-success me-2"></i>
                Always include your name and booking ID as reference
              </li>
              <li class="mb-2">
                <i class="bi bi-check-circle text-success me-2"></i>
                Save payment confirmation for verification
              </li>
              <li class="mb-2">
                <i class="bi bi-check-circle text-success me-2"></i>
                Payments are processed within 24 hours
              </li>
              <li class="mb-2">
                <i class="bi bi-check-circle text-success me-2"></i>
                Contact admin for payment issues
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const paymentMethods = document.querySelectorAll('.payment-method');
      const instructionsBox = document.getElementById('paymentInstructions');
      const makePaymentBtn = document.getElementById('makePaymentBtn');
      
      // Payment method selection
      paymentMethods.forEach(method => {
        method.addEventListener('click', function() {
          // Remove selected class from all methods
          paymentMethods.forEach(m => m.classList.remove('selected'));
          
          // Add selected class to clicked method
          this.classList.add('selected');
          
          // Get method details
          const methodName = this.dataset.method;
          const methodDetails = <?= json_encode($zim_payment_methods) ?>[methodName];
          
          // Update instructions
          if (methodDetails) {
            instructionsBox.innerHTML = `
              <h6 class="mb-3">${methodDetails.name} Instructions</h6>
              <p class="mb-0">${methodDetails.instructions}</p>
            `;
          }
          
          // Enable make payment button
          makePaymentBtn.disabled = false;
        });
      });
      
      // Make payment button click
      makePaymentBtn.addEventListener('click', function() {
        const selectedMethod = document.querySelector('.payment-method.selected');
        if (selectedMethod) {
          const methodName = selectedMethod.dataset.method;
          const methodDetails = <?= json_encode($zim_payment_methods) ?>[methodName];
          
          // Show payment confirmation
          const confirmation = confirm(
            `You have selected ${methodDetails.name}.\n\n` +
            'Please complete the payment using the instructions provided.\n' +
            'After payment, upload the proof of payment for verification.\n\n' +
            'Proceed to payment?'
          );
          
          if (confirmation) {
            // In a real app, this would redirect to payment gateway
            alert('Payment initiated! Please follow the instructions to complete your payment.');
          }
        }
      });
    });
  </script>
</body>
</html>
[file content end]