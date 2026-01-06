<?php
// student/paynow_initiate.php - Initiates simulated Paynow flow
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

$booking_id = intval($_GET['booking_id'] ?? 0);
if ($booking_id <= 0) {
    die('Invalid booking id');
}

// Find payment for booking
$stmt = $conn->prepare("SELECT id FROM payments WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param('i', $booking_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res) {
    die('No payment record found for booking');
}

$payment_id = intval($res['id']);
// Redirect to simulated gateway
header('Location: paynow_gateway.php?payment_id=' . $payment_id);
exit();
