<?php
// student/functions.php

function requireStudentRole() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
        header('Location: ../login.php');
        exit();
    }
}

function getCurrentStudent($conn) {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    
    $stmt = $conn->prepare("SELECT id, name, email, phone FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function getStudentBookings($conn, $student_id) {
    // Only show bookings that are not cancelled or are paid
    $stmt = $conn->prepare("
        SELECT b.*, c.title as class_title, c.start_time, c.end_time, 
               c.age_group, i.name as instructor_name, c.price
        FROM bookings b 
        JOIN classes c ON b.class_id = c.id 
        LEFT JOIN instructors i ON c.instructor_id = i.id 
        WHERE b.user_id = ? AND (b.status != 'cancelled' OR b.payment_status = 'paid')
        ORDER BY b.created_at DESC
    ");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function getStudentPayments($conn, $student_id) {
    try {
        // Get payment history from bookings table
        $stmt = $conn->prepare("
            SELECT 
                b.id as payment_id,
                b.payment_date as created_at,
                b.payment_method,
                b.payment_status as status,
                c.price as amount,
                c.title as description,
                'booking_payment' as type
            FROM bookings b 
            JOIN classes c ON b.class_id = c.id 
            WHERE b.user_id = ? AND b.payment_status = 'paid'
            ORDER BY b.payment_date DESC
        ");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Payment query error: " . $e->getMessage());
        return [];
    }
}

function getAvailableClasses($conn) {
    $stmt = $conn->prepare("
        SELECT c.*, i.name as instructor_name,
               (c.slots_total - COUNT(b.id)) as slots_available
        FROM classes c 
        LEFT JOIN instructors i ON c.instructor_id = i.id 
        LEFT JOIN bookings b ON c.id = b.class_id AND b.status != 'cancelled'
        WHERE c.start_time > NOW() 
        GROUP BY c.id 
        HAVING slots_available > 0
        ORDER BY c.start_time ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function bookClass($conn, $student_id, $class_id, $child_details) {
    // Check if class exists and has available slots
    $class_check = $conn->prepare("
        SELECT c.*, 
               (c.slots_total - COUNT(b.id)) as available_slots
        FROM classes c 
        LEFT JOIN bookings b ON c.id = b.class_id AND b.status != 'cancelled'
        WHERE c.id = ? 
        GROUP BY c.id
    ");
    $class_check->bind_param("i", $class_id);
    $class_check->execute();
    $class_result = $class_check->get_result();
    $class = $class_result->fetch_assoc();
    
    if (!$class) {
        return ['success' => false, 'message' => 'Class not found'];
    }
    
    if ($class['available_slots'] <= 0) {
        return ['success' => false, 'message' => 'No available slots'];
    }
    
    // Check if student already has an active booking for this class
    $existing_booking = $conn->prepare("
        SELECT id FROM bookings 
        WHERE user_id = ? AND class_id = ? AND status != 'cancelled'
    ");
    $existing_booking->bind_param("ii", $student_id, $class_id);
    $existing_booking->execute();
    $existing_result = $existing_booking->get_result();
    
    if ($existing_result->num_rows > 0) {
        return ['success' => false, 'message' => 'You already have a booking for this class'];
    }
    
    // Create booking with child details - status is 'pending' until payment
    $stmt = $conn->prepare("
        INSERT INTO bookings (user_id, class_id, child_name, child_age, child_gender, special_notes, status, payment_status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, 'pending', 'pending', NOW())
    ");
    $stmt->bind_param("iisiss", 
        $student_id, 
        $class_id, 
        $child_details['child_name'],
        $child_details['child_age'],
        $child_details['child_gender'],
        $child_details['special_notes']
    );
    
    if ($stmt->execute()) {
        return ['success' => true, 'message' => 'Class booked successfully! Please complete payment to confirm your booking.', 'booking_id' => $conn->insert_id];
    } else {
        return ['success' => false, 'message' => 'Failed to book class: ' . $conn->error];
    }
}

function cancelBooking($conn, $booking_id, $student_id) {
    // Get booking details to check payment status
    $booking_check = $conn->prepare("
        SELECT payment_status FROM bookings WHERE id = ? AND user_id = ?
    ");
    $booking_check->bind_param("ii", $booking_id, $student_id);
    $booking_check->execute();
    $booking_result = $booking_check->get_result();
    $booking = $booking_result->fetch_assoc();
    
    if (!$booking) {
        return ['success' => false, 'message' => 'Booking not found'];
    }
    
    // If payment is already made, don't allow cancellation through this method
    if ($booking['payment_status'] === 'paid') {
        return ['success' => false, 'message' => 'Cannot cancel paid booking. Please contact admin for refund.'];
    }
    
    // For unpaid bookings, mark as cancelled
    $stmt = $conn->prepare("
        UPDATE bookings SET status = 'cancelled' WHERE id = ? AND user_id = ?
    ");
    $stmt->bind_param("ii", $booking_id, $student_id);
    
    if ($stmt->execute()) {
        return ['success' => true, 'message' => 'Booking cancelled successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to cancel booking: ' . $conn->error];
    }
}

function processPayment($conn, $booking_id, $student_id, $payment_method) {
    // Verify booking belongs to student and is not cancelled
    $booking_check = $conn->prepare("
        SELECT b.*, c.price 
        FROM bookings b 
        JOIN classes c ON b.class_id = c.id 
        WHERE b.id = ? AND b.user_id = ? AND b.status != 'cancelled'
    ");
    $booking_check->bind_param("ii", $booking_id, $student_id);
    $booking_check->execute();
    $booking_result = $booking_check->get_result();
    $booking = $booking_result->fetch_assoc();
    
    if (!$booking) {
        return ['success' => false, 'message' => 'Booking not found'];
    }
    
    if ($booking['payment_status'] === 'paid') {
        return ['success' => false, 'message' => 'Booking is already paid'];
    }
    
    // Handle different payment methods
    if ($payment_method === 'cash') {
        // For cash payments, mark as pending and wait for admin confirmation
        $stmt = $conn->prepare("
            UPDATE bookings 
            SET payment_status = 'pending', payment_method = 'cash', status = 'pending'
            WHERE id = ? AND user_id = ?
        ");
        $stmt->bind_param("ii", $booking_id, $student_id);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Cash payment selected. Please pay at the reception to confirm your booking.'];
        }
    } else {
        // For PayNow payments - redirect to payment gateway
        // For now, we'll simulate successful payment
        $stmt = $conn->prepare("
            UPDATE bookings 
            SET payment_status = 'paid', payment_method = 'paynow', 
                status = 'confirmed', payment_date = NOW()
            WHERE id = ? AND user_id = ?
        ");
        $stmt->bind_param("ii", $booking_id, $student_id);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Payment successful! Your booking is now confirmed.'];
        }
    }
    
    return ['success' => false, 'message' => 'Payment processing failed: ' . $conn->error];
}

// Additional helper functions
function getBookingStatusCounts($conn, $student_id) {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'confirmed' AND start_time > NOW() THEN 1 ELSE 0 END) as upcoming
        FROM bookings b
        JOIN classes c ON b.class_id = c.id
        WHERE b.user_id = ? AND b.status != 'cancelled'
    ");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function getUpcomingBookings($conn, $student_id, $limit = 5) {
    $stmt = $conn->prepare("
        SELECT b.*, c.title as class_title, c.start_time, c.end_time, 
               c.age_group, i.name as instructor_name, c.price
        FROM bookings b 
        JOIN classes c ON b.class_id = c.id 
        LEFT JOIN instructors i ON c.instructor_id = i.id 
        WHERE b.user_id = ? AND b.status = 'confirmed' AND c.start_time > NOW()
        ORDER BY c.start_time ASC 
        LIMIT ?
    ");
    $stmt->bind_param("ii", $student_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}
?>