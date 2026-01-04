<?php
// student/paynow_callback.php - Simulated Paynow callback handler
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

$payment_id = intval($_GET['payment_id'] ?? 0);
$status = $_GET['status'] ?? '';

if ($payment_id <= 0) {
    die('Invalid payment id');
}

$allowed = ['success', 'failed'];
if (!in_array($status, $allowed)) {
    die('Invalid status');
}

// Fetch payment and booking
$stmt = $conn->prepare("SELECT p.*, b.id as booking_id, b.class_id, b.user_id as booking_user, b.status as booking_status FROM payments p LEFT JOIN bookings b ON p.booking_id = b.id WHERE p.id = ? LIMIT 1");
$stmt->bind_param('i', $payment_id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    die('Payment not found');
}

try {
    $conn->begin_transaction();

    if ($status === 'success') {
        // Mark payment as paid
        $u = $conn->prepare("UPDATE payments SET status = 'paid', payment_date = NOW() WHERE id = ?");
        $u->bind_param('i', $payment_id);
        $u->execute();
        $u->close();

        // Update booking payment_status and booking status to confirmed
        if ($payment['booking_id']) {
            $bupd = $conn->prepare("UPDATE bookings SET payment_status = 'paid', status = 'confirmed' WHERE id = ?");
            $bupd->bind_param('i', $payment['booking_id']);
            $bupd->execute();
            $bupd->close();

            // Create enrollment if not exists
            $chk = $conn->prepare("SELECT id FROM enrollments WHERE student_id = ? AND class_id = ? LIMIT 1");
            $chk->bind_param('ii', $payment['user_id'], $payment['class_id']);
            $chk->execute();
            $cres = $chk->get_result();
            $exists = $cres->num_rows > 0;
            $chk->close();

            if (!$exists) {
                $en = $conn->prepare("INSERT INTO enrollments (student_id, class_id, enrollment_date, status, enrolled_at) VALUES (?, ?, NOW(), 'active', NOW())");
                $en->bind_param('ii', $payment['user_id'], $payment['class_id']);
                $en->execute();
                $en->close();
            }
        }

        $conn->commit();
        $_SESSION['success_msg'] = 'Payment successful. Booking confirmed.';
        header('Location: classes.php');
        exit();
    } else {
        // Failed
        $u = $conn->prepare("UPDATE payments SET status = 'failed' WHERE id = ?");
        $u->bind_param('i', $payment_id);
        $u->execute();
        $u->close();
        $conn->commit();
        $_SESSION['error_msg'] = 'Payment failed or was not completed.';
        header('Location: classes.php');
        exit();
    }
} catch (Exception $e) {
    $conn->rollback();
    error_log('Paynow callback error: ' . $e->getMessage());
    die('An error occurred');
}
