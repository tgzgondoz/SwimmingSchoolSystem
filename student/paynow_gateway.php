<?php
// student/paynow_gateway.php - Simulated Paynow gateway for testing
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

$payment_id = intval($_GET['payment_id'] ?? 0);
if ($payment_id <= 0) {
    echo "Invalid payment id";
    exit;
}

$stm = $conn->prepare("SELECT p.*, b.class_id, c.title, c.price FROM payments p LEFT JOIN bookings b ON p.booking_id = b.id LEFT JOIN classes c ON b.class_id = c.id WHERE p.id = ? LIMIT 1");
$stm->bind_param('i', $payment_id);
$stm->execute();
$pay = $stm->get_result()->fetch_assoc();
$stm->close();

if (!$pay) {
    echo "Payment not found";
    exit;
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Simulated Paynow Gateway</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
    <div class="container">
        <h3>Simulated Paynow Gateway</h3>
        <p>Payment Reference: <strong><?= htmlspecialchars($pay['reference_number']) ?></strong></p>
        <p>Amount: <strong>$<?= number_format($pay['amount'], 2) ?></strong></p>
        <p>Booking ID: <strong><?= intval($pay['booking_id']) ?></strong></p>
        <div class="mt-4">
            <a href="paynow_callback.php?payment_id=<?= $payment_id ?>&status=success" class="btn btn-success me-2">Simulate Success</a>
            <a href="paynow_callback.php?payment_id=<?= $payment_id ?>&status=failed" class="btn btn-danger">Simulate Failure</a>
        </div>
        <p class="mt-3 text-muted">This is a simulated gateway page for local testing. Replace with real Paynow integration in production.</p>
    </div>
</body>
</html>