<?php
// admin/bookings.php - Admin Booking Management
session_start();

// CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

// Rate Limiting
$now = time();
if (!isset($_SESSION['request_count'])) {
    $_SESSION['request_count'] = 1;
    $_SESSION['first_request'] = $now;
} else {
    $_SESSION['request_count']++;
}

if ($_SESSION['request_count'] > 100 && ($now - $_SESSION['first_request']) < 300) {
    die('Too many requests. Please try again later.');
}

// Authentication and role check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$admin_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

// Function to send email notifications
function sendBookingNotification($conn, $booking_id, $action) {
    // Get booking details
    $stmt = $conn->prepare("
        SELECT u.email, u.name as student_name, c.title as class_name, 
               DATE_FORMAT(c.start_time, '%W, %M %d, %Y at %h:%i %p') as class_time
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        JOIN classes c ON b.class_id = c.id
        WHERE b.id = ?
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $booking = $result->fetch_assoc();
        $student_email = $booking['email'];
        $student_name = $booking['student_name'];
        $class_name = $booking['class_name'];
        $class_time = $booking['class_time'];
        
        // Get school info
        $school_stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'school_name'");
        $school_stmt->execute();
        $school_result = $school_stmt->get_result();
        $school = $school_result->fetch_assoc();
        $school_name = $school['setting_value'] ?? 'Elite Swimming Academy';
        
        // Prepare email content based on action
        $subject = '';
        $message = '';
        
        switch ($action) {
            case 'confirmed':
                $subject = "Booking Confirmed: $class_name";
                $message = "Dear $student_name,\n\nYour booking for '$class_name' on $class_time has been confirmed.\n\nThank you for choosing $school_name!";
                break;
            case 'rejected':
                $subject = "Booking Request Declined: $class_name";
                $message = "Dear $student_name,\n\nUnfortunately, your booking request for '$class_name' on $class_time could not be approved at this time.\n\nPlease contact us for more information.\n\n$school_name Team";
                break;
            case 'cancelled':
                $subject = "Booking Cancelled: $class_name";
                $message = "Dear $student_name,\n\nYour booking for '$class_name' on $class_time has been cancelled.\n\n$school_name Team";
                break;
        }
        
        // Send email (you would implement your email sending logic here)
        // For example: mail($student_email, $subject, $message);
        
        // Log the notification
        error_log("Notification sent to $student_email: $subject");
    }
    $stmt->close();
}

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

// Handle export request
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="bookings_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Student', 'Class', 'Child Name', 'Child Age', 'Status', 'Payment Status', 'Created', 'Class Time', 'Price']);
    
    $export_stmt = $conn->prepare("
        SELECT b.*, u.name as student_name, c.title as class_name, c.start_time, c.price
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        JOIN classes c ON b.class_id = c.id
        ORDER BY b.created_at DESC
    ");
    $export_stmt->execute();
    $result = $export_stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['id'],
            $row['student_name'],
            $row['class_name'],
            $row['child_name'] ?? 'Self',
            $row['child_age'] ?? 'N/A',
            ucfirst($row['status']),
            ucfirst($row['payment_status']),
            $row['created_at'],
            $row['start_time'],
            '$' . number_format($row['price'], 2)
        ]);
    }
    fclose($output);
    exit;
}

// Load students and available classes for booking form
$students = [];
$students_stmt = $conn->prepare("SELECT id, name, email FROM users WHERE role = 'student' AND (status = 'active' OR status IS NULL OR status = '') ORDER BY name ASC");
if ($students_stmt) {
    $students_stmt->execute();
    $students_result = $students_stmt->get_result();
    $students = $students_result->fetch_all(MYSQLI_ASSOC) ?: [];
    $students_stmt->close();
}

$available_classes = [];
$classes_stmt = $conn->prepare("SELECT id, title, start_time, price, slots_available FROM classes WHERE start_time >= NOW() AND status = 'scheduled' ORDER BY start_time ASC");
if ($classes_stmt) {
    $classes_stmt->execute();
    $classes_result = $classes_stmt->get_result();
    $available_classes = $classes_result->fetch_all(MYSQLI_ASSOC) ?: [];
    $classes_stmt->close();
}

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Token Validation
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error_msg = "Security token invalid. Please refresh the page and try again.";
    } elseif (isset($_POST['add_booking'])) {
        $user_id = intval($_POST['user_id'] ?? 0);
        $class_id = intval($_POST['class_id'] ?? 0);
        $child_name = trim($_POST['child_name'] ?? '');
        $child_age = $_POST['child_age'] !== '' ? intval($_POST['child_age']) : 0;
        $child_gender = $_POST['child_gender'] ?? null;
        $special_notes = trim($_POST['special_notes'] ?? '');
        $status = $_POST['status'] ?? 'pending';
        $payment_status = $_POST['payment_status'] ?? 'pending';
        $payment_method = trim($_POST['payment_method'] ?? '');

        if ($user_id <= 0 || $class_id <= 0) {
            $error_msg = "Student and class are required to create a booking.";
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO bookings (user_id, class_id, status, payment_status, payment_method, child_name, child_age, child_gender, special_notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                if ($stmt) {
                    $stmt->bind_param('iisssisss', $user_id, $class_id, $status, $payment_status, $payment_method, $child_name, $child_age, $child_gender, $special_notes);
                    if ($stmt->execute()) {
                        $new_id = $stmt->insert_id;
                        // If confirmed, decrement class slots
                        if ($status === 'confirmed') {
                            $slot_stmt = $conn->prepare("UPDATE classes SET slots_available = slots_available - 1 WHERE id = ? AND slots_available > 0");
                            if ($slot_stmt) {
                                $slot_stmt->bind_param('i', $class_id);
                                $slot_stmt->execute();
                                $slot_stmt->close();
                            }
                        }
                        
                        // Log activity
                        logActivity($conn, $admin_id, 'Booking Created', "Created booking for student ID: $user_id, class ID: $class_id");
                        
                        $success_msg = 'Booking created successfully!';
                    } else {
                        $error_msg = 'Failed to create booking: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error_msg = 'Database error: ' . $conn->error;
                }
            } catch (Exception $e) {
                error_log('Add booking error: ' . $e->getMessage());
                $error_msg = 'An error occurred while creating the booking.';
            }
        }
    }
    
    elseif (isset($_POST['update_booking'])) {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $new_user_id = intval($_POST['user_id'] ?? 0);
        $new_class_id = intval($_POST['class_id'] ?? 0);
        $child_name = trim($_POST['child_name'] ?? '');
        $child_age = $_POST['child_age'] !== '' ? intval($_POST['child_age']) : null;
        $child_gender = $_POST['child_gender'] ?? null;
        $special_notes = trim($_POST['special_notes'] ?? '');
        $new_status = $_POST['status'] ?? 'pending';
        $payment_status = $_POST['payment_status'] ?? 'pending';
        $payment_method = trim($_POST['payment_method'] ?? '');

        if ($booking_id <= 0 || $new_user_id <= 0 || $new_class_id <= 0) {
            $error_msg = 'Invalid booking details.';
        } else {
            try {
                // Fetch existing booking
                $orig_stmt = $conn->prepare("SELECT class_id, status, user_id FROM bookings WHERE id = ? LIMIT 1");
                $orig_stmt->bind_param('i', $booking_id);
                $orig_stmt->execute();
                $orig_res = $orig_stmt->get_result();
                $orig = $orig_res->fetch_assoc() ?: null;
                $orig_stmt->close();

                if (!$orig) {
                    $error_msg = 'Original booking not found.';
                } else {
                    $old_class = intval($orig['class_id']);
                    $old_status = $orig['status'];

                    // Begin update
                    $update_stmt = $conn->prepare("UPDATE bookings SET user_id = ?, class_id = ?, child_name = ?, child_age = ?, child_gender = ?, special_notes = ?, status = ? WHERE id = ?");
                    $update_stmt->bind_param('iisisssi', $new_user_id, $new_class_id, $child_name, $child_age, $child_gender, $special_notes, $new_status, $booking_id);
                    $ok = $update_stmt->execute();
                    $update_stmt->close();

                    if ($ok) {
                        // Adjust class slots if class changed or status changed
                        // If class changed and original was confirmed, increase old class slots
                        if ($old_class !== $new_class_id && $old_status === 'confirmed') {
                            $inc = $conn->prepare("UPDATE classes SET slots_available = slots_available + 1 WHERE id = ?");
                            $inc->bind_param('i', $old_class);
                            $inc->execute();
                            $inc->close();
                        }

                        // If new status is confirmed and old wasn't, decrement new class slots
                        if ($old_status !== 'confirmed' && $new_status === 'confirmed') {
                            $dec = $conn->prepare("UPDATE classes SET slots_available = slots_available - 1 WHERE id = ? AND slots_available > 0");
                            $dec->bind_param('i', $new_class_id);
                            $dec->execute();
                            $dec->close();
                        }

                        // If old was confirmed and new is not confirmed and class remains same, increment slots
                        if ($old_status === 'confirmed' && $new_status !== 'confirmed' && $old_class === $new_class_id) {
                            $inc2 = $conn->prepare("UPDATE classes SET slots_available = slots_available + 1 WHERE id = ?");
                            $inc2->bind_param('i', $old_class);
                            $inc2->execute();
                            $inc2->close();
                        }

                        // If class changed and new status is confirmed, decrement new class (if not already handled)
                        if ($old_class !== $new_class_id && $new_status === 'confirmed') {
                            $dec2 = $conn->prepare("UPDATE classes SET slots_available = slots_available - 1 WHERE id = ? AND slots_available > 0");
                            $dec2->bind_param('i', $new_class_id);
                            $dec2->execute();
                            $dec2->close();
                        }

                        // Handle payments: ensure payment row exists and update it
                        $pay_stmt = $conn->prepare("SELECT id FROM payments WHERE booking_id = ? LIMIT 1");
                        $pay_stmt->bind_param('i', $booking_id);
                        $pay_stmt->execute();
                        $pay_res = $pay_stmt->get_result();
                        $has_pay = $pay_res->num_rows > 0;
                        $pay_stmt->close();

                        // Get class price
                        $price = 0.00;
                        $price_stmt = $conn->prepare("SELECT price FROM classes WHERE id = ? LIMIT 1");
                        if ($price_stmt) {
                            $price_stmt->bind_param('i', $new_class_id);
                            $price_stmt->execute();
                            $prow = $price_stmt->get_result()->fetch_assoc();
                            if ($prow) $price = (float)$prow['price'];
                            $price_stmt->close();
                        }

                        if ($has_pay) {
                            $upd_pay = $conn->prepare("UPDATE payments SET user_id = ?, amount = ?, payment_method = ?, description = ?, status = ?, payment_date = CASE WHEN ? = 'paid' THEN NOW() ELSE payment_date END WHERE booking_id = ?");
                            $desc = 'Updated from admin booking edit';
                            $upd_pay->bind_param('idssssi', $new_user_id, $price, $payment_method, $desc, $payment_status, $payment_status, $booking_id);
                            $upd_pay->execute();
                            $upd_pay->close();
                        } else {
                            $ins_pay = $conn->prepare("INSERT INTO payments (booking_id, user_id, amount, payment_method, description, status, payment_date, created_at) VALUES (?, ?, ?, ?, ?, ?, CASE WHEN ? = 'paid' THEN NOW() ELSE NULL END, NOW())");
                            $desc = 'Auto-created from admin booking edit';
                            $ins_pay->bind_param('iidssss', $booking_id, $new_user_id, $price, $payment_method, $desc, $payment_status, $payment_status);
                            $ins_pay->execute();
                            $ins_pay->close();
                        }

                        // Log activity
                        logActivity($conn, $admin_id, 'Booking Updated', "Updated booking ID: $booking_id");
                        
                        $success_msg = 'Booking updated successfully!';
                    } else {
                        $error_msg = 'Failed to update booking.';
                    }
                }
            } catch (Exception $e) {
                error_log('Update booking error: ' . $e->getMessage());
                $error_msg = 'An error occurred while updating the booking.';
            }
        }
    }
    
    elseif (isset($_POST['update_status'])) {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        
        try {
            $update_stmt = $conn->prepare("UPDATE bookings SET status = ? WHERE id = ?");
            $update_stmt->bind_param("si", $status, $booking_id);
            
            if ($update_stmt->execute()) {
                // If confirmed, update class slots
                if ($status == 'confirmed') {
                    // Get class_id and user_id for this booking
                    $class_stmt = $conn->prepare("SELECT class_id, user_id FROM bookings WHERE id = ?");
                    $class_stmt->bind_param("i", $booking_id);
                    $class_stmt->execute();
                    $class_result = $class_stmt->get_result();
                    $class_row = $class_result->fetch_assoc();
                    $class_stmt->close();

                    if ($class_row) {
                        $class_id = $class_row['class_id'];
                        $user_id = $class_row['user_id'];
                        // Decrease available slots
                        $slot_stmt = $conn->prepare("UPDATE classes SET slots_available = slots_available - 1 WHERE id = ? AND slots_available > 0");
                        $slot_stmt->bind_param("i", $class_id);
                        $slot_stmt->execute();
                        $slot_stmt->close();

                        // Ensure a payment record exists for this booking
                        $pay_check = $conn->prepare("SELECT id FROM payments WHERE booking_id = ? LIMIT 1");
                        if ($pay_check) {
                            $pay_check->bind_param('i', $booking_id);
                            $pay_check->execute();
                            $pay_res = $pay_check->get_result();
                            $has_payment = $pay_res->num_rows > 0;
                            $pay_check->close();

                            if (!$has_payment) {
                                // Get class price
                                $price_stmt = $conn->prepare("SELECT price FROM classes WHERE id = ? LIMIT 1");
                                $price = 0.00;
                                if ($price_stmt) {
                                    $price_stmt->bind_param('i', $class_id);
                                    $price_stmt->execute();
                                    $price_res = $price_stmt->get_result();
                                    if ($rowp = $price_res->fetch_assoc()) {
                                        $price = (float)$rowp['price'];
                                    }
                                    $price_stmt->close();
                                }

                                $ins = $conn->prepare("INSERT INTO payments (booking_id, user_id, amount, payment_method, description, status, payment_date, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
                                if ($ins) {
                                    $empty_method = '';
                                    $desc = 'Auto-created for confirmed booking';
                                    $pstatus = 'pending';
                                    $ins->bind_param('iidsss', $booking_id, $user_id, $price, $empty_method, $desc, $pstatus);
                                    $ins->execute();
                                    $ins->close();
                                }
                            }
                        }
                    }
                } elseif ($status == 'cancelled' || $status == 'rejected') {
                    // If cancelling a confirmed booking, restore slots
                    $check_stmt = $conn->prepare("SELECT class_id FROM bookings WHERE id = ? AND status = 'confirmed'");
                    $check_stmt->bind_param("i", $booking_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows > 0) {
                        $row = $check_result->fetch_assoc();
                        $slot_stmt = $conn->prepare("UPDATE classes SET slots_available = slots_available + 1 WHERE id = ?");
                        $slot_stmt->bind_param("i", $row['class_id']);
                        $slot_stmt->execute();
                        $slot_stmt->close();
                    }
                    $check_stmt->close();
                }
                
                // Send notification email
                sendBookingNotification($conn, $booking_id, $status);
                
                // Log activity
                logActivity($conn, $admin_id, 'Booking Status Updated', "Updated booking ID: $booking_id to status: $status");
                
                $success_msg = "Booking status updated successfully!";
            } else {
                $error_msg = "Failed to update booking status.";
            }
            $update_stmt->close();
        } catch (Exception $e) {
            error_log("Booking update error: " . $e->getMessage());
            $error_msg = "An error occurred while updating the booking.";
        }
    }
    
    elseif (isset($_POST['delete_booking'])) {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        
        try {
            // Check if booking exists
            $check_stmt = $conn->prepare("SELECT class_id FROM bookings WHERE id = ? AND status = 'confirmed'");
            $check_stmt->bind_param("i", $booking_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                // If confirmed booking, increase available slots
                $row = $check_result->fetch_assoc();
                $slot_stmt = $conn->prepare("UPDATE classes SET slots_available = slots_available + 1 WHERE id = ?");
                $slot_stmt->bind_param("i", $row['class_id']);
                $slot_stmt->execute();
                $slot_stmt->close();
            }
            $check_stmt->close();
            
            // Get booking info for logging
            $info_stmt = $conn->prepare("SELECT user_id, class_id FROM bookings WHERE id = ?");
            $info_stmt->bind_param("i", $booking_id);
            $info_stmt->execute();
            $info_result = $info_stmt->get_result();
            $booking_info = $info_result->fetch_assoc();
            $info_stmt->close();
            
            // Delete the booking
            $delete_stmt = $conn->prepare("DELETE FROM bookings WHERE id = ?");
            $delete_stmt->bind_param("i", $booking_id);
            
            if ($delete_stmt->execute()) {
                // Log activity
                if ($booking_info) {
                    logActivity($conn, $admin_id, 'Booking Deleted', "Deleted booking ID: $booking_id (Student: {$booking_info['user_id']}, Class: {$booking_info['class_id']})");
                }
                
                $success_msg = "Booking deleted successfully!";
            } else {
                $error_msg = "Failed to delete booking.";
            }
            $delete_stmt->close();
        } catch (Exception $e) {
            error_log("Booking deletion error: " . $e->getMessage());
            $error_msg = "An error occurred while deleting the booking.";
        }
    }
    
    // Handle bulk operations
    elseif (isset($_POST['bulk_action'])) {
        $bulk_action = $_POST['bulk_action'];
        $selected_bookings = $_POST['selected_bookings'] ?? [];
        
        if (empty($selected_bookings)) {
            $error_msg = "No bookings selected.";
        } else {
            $placeholders = implode(',', array_fill(0, count($selected_bookings), '?'));
            $types = str_repeat('i', count($selected_bookings));
            
            try {
                if ($bulk_action == 'confirm') {
                    $update_stmt = $conn->prepare("UPDATE bookings SET status = 'confirmed' WHERE id IN ($placeholders)");
                    $update_stmt->bind_param($types, ...$selected_bookings);
                    
                    if ($update_stmt->execute()) {
                        // Update class slots for confirmed bookings
                        $slot_stmt = $conn->prepare("
                            UPDATE classes c
                            JOIN bookings b ON c.id = b.class_id
                            SET c.slots_available = c.slots_available - 1
                            WHERE b.id IN ($placeholders) AND b.status = 'confirmed'
                        ");
                        $slot_stmt->bind_param($types, ...$selected_bookings);
                        $slot_stmt->execute();
                        $slot_stmt->close();

                        // Ensure payment records exist for confirmed bookings
                        $pay_check = $conn->prepare("SELECT id FROM payments WHERE booking_id = ? LIMIT 1");
                        $price_stmt = $conn->prepare("SELECT class_id, user_id FROM bookings WHERE id = ? LIMIT 1");
                        $class_price_stmt = $conn->prepare("SELECT price FROM classes WHERE id = ? LIMIT 1");
                        $ins = $conn->prepare("INSERT INTO payments (booking_id, user_id, amount, payment_method, description, status, payment_date, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");

                        foreach ($selected_bookings as $bid) {
                            $bid = intval($bid);
                            // check existing payment
                            if ($pay_check) {
                                $pay_check->bind_param('i', $bid);
                                $pay_check->execute();
                                $res = $pay_check->get_result();
                                $has = $res->num_rows > 0;
                                $pay_check->close();
                                // re-prepare for next iteration
                                $pay_check = $conn->prepare("SELECT id FROM payments WHERE booking_id = ? LIMIT 1");
                            } else {
                                $has = false;
                            }

                            if ($has) continue;

                            // get class_id and user_id
                            if ($price_stmt) {
                                $price_stmt->bind_param('i', $bid);
                                $price_stmt->execute();
                                $bres = $price_stmt->get_result();
                                $brow = $bres->fetch_assoc();
                            } else {
                                $brow = null;
                            }

                            if (!$brow) continue;

                            $class_id = intval($brow['class_id']);
                            $user_id = intval($brow['user_id']);

                            // get price
                            $price = 0.00;
                            if ($class_price_stmt) {
                                $class_price_stmt->bind_param('i', $class_id);
                                $class_price_stmt->execute();
                                $cres = $class_price_stmt->get_result();
                                if ($crow = $cres->fetch_assoc()) {
                                    $price = (float)$crow['price'];
                                }
                            }

                            if ($ins) {
                                $empty_method = '';
                                $desc = 'Auto-created for confirmed booking';
                                $pstatus = 'pending';
                                $ins->bind_param('iidsss', $bid, $user_id, $price, $empty_method, $desc, $pstatus);
                                $ins->execute();
                            }
                        }

                        if ($pay_check) $pay_check->close();
                        if ($price_stmt) $price_stmt->close();
                        if ($class_price_stmt) $class_price_stmt->close();
                        if ($ins) $ins->close();

                        // Log activity
                        logActivity($conn, $admin_id, 'Bulk Booking Confirm', "Confirmed " . count($selected_bookings) . " bookings");
                        
                        $success_msg = "Selected bookings confirmed successfully!";
                    } else {
                        $error_msg = "Failed to confirm bookings.";
                    }
                    $update_stmt->close();
                } elseif ($bulk_action == 'reject') {
                    $update_stmt = $conn->prepare("UPDATE bookings SET status = 'rejected' WHERE id IN ($placeholders) AND status = 'pending'");
                    $update_stmt->bind_param($types, ...$selected_bookings);
                    
                    if ($update_stmt->execute()) {
                        // Log activity
                        logActivity($conn, $admin_id, 'Bulk Booking Reject', "Rejected " . count($selected_bookings) . " bookings");
                        
                        $success_msg = "Selected bookings rejected successfully!";
                    } else {
                        $error_msg = "Failed to reject bookings.";
                    }
                    $update_stmt->close();
                } elseif ($bulk_action == 'cancel') {
                    $update_stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled' WHERE id IN ($placeholders)");
                    $update_stmt->bind_param($types, ...$selected_bookings);
                    
                    if ($update_stmt->execute()) {
                        // Increase slots for cancelled confirmed bookings
                        $slot_stmt = $conn->prepare("
                            UPDATE classes c
                            JOIN bookings b ON c.id = b.class_id
                            SET c.slots_available = c.slots_available + 1
                            WHERE b.id IN ($placeholders) AND b.status = 'cancelled'
                        ");
                        $slot_stmt->bind_param($types, ...$selected_bookings);
                        $slot_stmt->execute();
                        $slot_stmt->close();
                        
                        // Log activity
                        logActivity($conn, $admin_id, 'Bulk Booking Cancel', "Cancelled " . count($selected_bookings) . " bookings");
                        
                        $success_msg = "Selected bookings cancelled successfully!";
                    } else {
                        $error_msg = "Failed to cancel bookings.";
                    }
                    $update_stmt->close();
                } elseif ($bulk_action == 'delete') {
                    // First, get class_id for confirmed bookings to increase slots
                    $check_stmt = $conn->prepare("
                        SELECT b.id, b.class_id FROM bookings b
                        WHERE b.id IN ($placeholders) AND b.status = 'confirmed'
                    ");
                    $check_stmt->bind_param($types, ...$selected_bookings);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    while ($row = $check_result->fetch_assoc()) {
                        $slot_stmt = $conn->prepare("UPDATE classes SET slots_available = slots_available + 1 WHERE id = ?");
                        $slot_stmt->bind_param("i", $row['class_id']);
                        $slot_stmt->execute();
                        $slot_stmt->close();
                    }
                    $check_stmt->close();
                    
                    // Delete the bookings
                    $delete_stmt = $conn->prepare("DELETE FROM bookings WHERE id IN ($placeholders)");
                    $delete_stmt->bind_param($types, ...$selected_bookings);
                    
                    if ($delete_stmt->execute()) {
                        // Log activity
                        logActivity($conn, $admin_id, 'Bulk Booking Delete', "Deleted " . count($selected_bookings) . " bookings");
                        
                        $success_msg = "Selected bookings deleted successfully!";
                    } else {
                        $error_msg = "Failed to delete bookings.";
                    }
                    $delete_stmt->close();
                }
            } catch (Exception $e) {
                error_log("Bulk action error: " . $e->getMessage());
                $error_msg = "An error occurred while processing the bulk action.";
            }
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
    header("Location: bookings.php");
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

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Build query with filters
$query = "
    SELECT b.*, 
           u.name as student_name, 
           u.email as student_email,
           u.phone as student_phone,
           c.title as class_name,
           c.start_time as class_time,
           c.price as class_price,
           c.slots_available,
           i.name as instructor_name,
           p.amount as payment_amount,
           p.status as payment_status,
           p.payment_method
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    JOIN classes c ON b.class_id = c.id
    LEFT JOIN instructors i ON c.instructor_id = i.id
    LEFT JOIN payments p ON p.booking_id = b.id
";

$where_conditions = [];
$params = [];
$types = '';

if ($status_filter) {
    $where_conditions[] = "b.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($date_from) {
    $where_conditions[] = "DATE(b.created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to) {
    $where_conditions[] = "DATE(b.created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

if ($search) {
    $search_term = "%$search%";
    $where_conditions[] = "(u.name LIKE ? OR u.email LIKE ? OR c.title LIKE ? OR b.child_name LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ssss';
}

if (!empty($where_conditions)) {
    $query .= " WHERE " . implode(" AND ", $where_conditions);
}

$query .= " ORDER BY b.created_at DESC";

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM bookings b JOIN users u ON b.user_id = u.id JOIN classes c ON b.class_id = c.id";
if (!empty($where_conditions)) {
    $count_query .= " WHERE " . implode(" AND ", $where_conditions);
}

$count_stmt = $conn->prepare($count_query);
if ($params) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_bookings = $count_result->fetch_assoc()['total'] ?? 0;
$count_stmt->close();

// Add pagination to main query
$query .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

// Fetch bookings
$stmt = $conn->prepare($query);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$bookings = $result->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

// Calculate total pages
$total_pages = ceil($total_bookings / $limit);

// Get booking statistics
$stats_stmt = $conn->prepare("
    SELECT 
        status,
        COUNT(*) as count
    FROM bookings 
    GROUP BY status
    ORDER BY status
");
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$booking_stats = [];
$total_count = 0;
while ($stat = $stats_result->fetch_assoc()) {
    $booking_stats[$stat['status']] = $stat['count'];
    $total_count += $stat['count'];
}
$stats_stmt->close();

// Get revenue statistics
$revenue_stmt = $conn->prepare("
    SELECT 
        SUM(p.amount) as total_revenue,
        COUNT(DISTINCT p.id) as total_payments
    FROM payments p
    INNER JOIN bookings b ON p.booking_id = b.id
    WHERE p.status = 'paid'
    AND b.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$revenue_stmt->execute();
$revenue_result = $revenue_stmt->get_result();
$revenue_stats = $revenue_result->fetch_assoc() ?: ['total_revenue' => 0, 'total_payments' => 0];
$revenue_stmt->close();

// Get current date and time
$current_date = date('l, F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Bookings | Elite Swimming Academy</title>
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
            background-color: var(--primary);
        }
        
        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 16px;
            background-color: var(--primary);
            color: white;
        }
        
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
        
        .stat-trend {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            margin-top: 8px;
            color: var(--gray-500);
        }
        
        .trend-up { color: var(--success); }
        .trend-down { color: var(--danger); }
        
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
        
        /* Bulk Actions */
        .bulk-actions {
            background-color: white;
            border-radius: 8px;
            padding: 15px 20px;
            border: 1px solid var(--gray-200);
            margin-bottom: 16px;
            display: none;
        }
        
        .bulk-actions.show {
            display: flex;
            align-items: center;
            gap: 12px;
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
        
        .badge-danger {
            background-color: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }
        
        .badge-warning {
            background-color: rgba(217, 119, 6, 0.1);
            color: var(--warning);
        }
        
        .badge-info {
            background-color: rgba(8, 145, 178, 0.1);
            color: var(--info);
        }
        
        .badge-primary {
            background-color: rgba(37, 99, 235, 0.1);
            color: var(--primary);
        }
        
        .badge-secondary {
            background-color: rgba(107, 114, 128, 0.1);
            color: var(--gray-600);
        }
        
        /* Student Avatar */
        .student-avatar {
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
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-header {
                flex-direction: column;
                gap: 12px;
                align-items: stretch;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .bulk-actions {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            
            .bulk-actions .d-flex {
                flex-direction: column;
                gap: 10px;
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
                    <a href="bookings.php" class="nav-link active">
                        <i class="bi bi-journal-check"></i>
                        <span class="nav-text">Bookings</span>
                        <?php $pending_count = $booking_stats['pending'] ?? 0; if ($pending_count > 0): ?>
                            <span class="badge bg-warning text-dark ms-auto" style="font-size: 11px;"><?= $pending_count ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="payments.php" class="nav-link">
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
                    <h1>Manage Bookings</h1>
                    <p>Total Bookings: <?= $total_count ?> • <?= $current_date ?></p>
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
            
            <!-- Bulk Actions -->
            <div class="bulk-actions" id="bulkActions">
                <div>
                    <span id="selectedCount">0</span> bookings selected
                </div>
                <div class="d-flex align-items-center gap-2">
                    <select class="form-select form-select-sm" style="width: auto;" id="bulkActionSelect">
                        <option value="">Choose action...</option>
                        <option value="confirm">Confirm Selected</option>
                        <option value="reject">Reject Selected</option>
                        <option value="cancel">Cancel Selected</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button class="btn btn-sm btn-primary" onclick="applyBulkAction()">Apply</button>
                    <button class="btn btn-sm btn-secondary" onclick="clearSelection()">Clear</button>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $booking_stats['pending'] ?? 0 ?></h3>
                        <p>Pending Bookings</p>
                        <div class="stat-trend">
                            <span>Awaiting approval</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $booking_stats['confirmed'] ?? 0 ?></h3>
                        <p>Confirmed Bookings</p>
                        <div class="stat-trend">
                            <span>Active reservations</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-x-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $booking_stats['cancelled'] ?? 0 ?></h3>
                        <p>Cancelled Bookings</p>
                        <div class="stat-trend">
                            <span>No shows & cancellations</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                    <div class="stat-content">
                        <h3>$<?= number_format($revenue_stats['total_revenue'] ?? 0, 2) ?></h3>
                        <p>30-Day Revenue</p>
                        <div class="stat-trend">
                            <span><?= $revenue_stats['total_payments'] ?? 0 ?> payments</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-journal-text"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $total_count ?></h3>
                        <p>Total Bookings</p>
                        <div class="stat-trend">
                            <span>All-time bookings</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-header">
                    <h3>Filter Bookings</h3>
                    <div class="d-flex gap-2">
                        <a href="?export=csv" class="btn btn-outline-success" style="font-size: 14px;">
                            <i class="bi bi-download me-2"></i> Export CSV
                        </a>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBookingModal" style="font-size: 14px;">
                            <i class="bi bi-plus-circle me-2"></i> Add Booking
                        </button>
                    </div>
                </div>
                <form method="GET" class="filter-grid">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Student name, class, or child name...">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select class="form-select" name="status">
                            <option value="" <?= $status_filter === '' ? 'selected' : '' ?>>All Status</option>
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="confirmed" <?= $status_filter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                            <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>From Date</label>
                        <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                    </div>
                    <div class="form-group">
                        <label>To Date</label>
                        <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                    <div class="form-group d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100" style="font-size: 14px;">
                            <i class="bi bi-funnel me-2"></i> Apply Filters
                        </button>
                    </div>
                </form>
                
                <!-- Quick Filters -->
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <a href="?status=pending" class="btn btn-sm btn-outline-warning">
                        <i class="bi bi-clock me-1"></i> Pending (<?= $booking_stats['pending'] ?? 0 ?>)
                    </a>
                    <a href="?status=confirmed" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-check-circle me-1"></i> Confirmed (<?= $booking_stats['confirmed'] ?? 0 ?>)
                    </a>
                    <a href="?status=cancelled" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-x-circle me-1"></i> Cancelled (<?= $booking_stats['cancelled'] ?? 0 ?>)
                    </a>
                    <a href="?date_from=<?= date('Y-m-d') ?>" class="btn btn-sm btn-outline-info">
                        <i class="bi bi-calendar-day me-1"></i> Today's
                    </a>
                    <a href="bookings.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-x-circle me-1"></i> Clear Filters
                    </a>
                </div>
            </div>
            
            <!-- Bookings Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3>Booking List</h3>
                    <div>
                        <span class="text-muted" style="font-size: 14px;">Showing <?= count($bookings) ?> of <?= $total_bookings ?> bookings</span>
                    </div>
                </div>
                
                <div class="table-wrapper">
                    <?php if (empty($bookings)): ?>
                        <div class="empty-state">
                            <i class="bi bi-journal-x"></i>
                            <h4>No Bookings Found</h4>
                            <p>No bookings match your search criteria. Try adjusting your filters.</p>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBookingModal" style="font-size: 14px;">
                                <i class="bi bi-plus-circle me-2"></i> Add New Booking
                            </button>
                        </div>
                    <?php else: ?>
                        <form method="POST" id="bulkForm">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th width="40">
                                            <input type="checkbox" class="form-check-input" id="selectAll" onchange="selectAllBookings(this.checked)">
                                        </th>
                                        <th>Booking Details</th>
                                        <th>Student</th>
                                        <th>Class Info</th>
                                        <th>Status</th>
                                        <th>Payment</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bookings as $booking): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="form-check-input booking-checkbox" name="selected_bookings[]" value="<?= $booking['id'] ?>" onchange="updateSelection()">
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-start">
                                                    <div>
                                                        <div class="fw-medium" style="font-size: 14px;">Booking #<?= str_pad($booking['id'], 5, '0', STR_PAD_LEFT) ?></div>
                                                        <div class="text-muted" style="font-size: 12px;">
                                                            <?= date('M j, Y', strtotime($booking['created_at'])) ?>
                                                        </div>
                                                        <?php if ($booking['child_name']): ?>
                                                            <div class="text-muted mt-1" style="font-size: 12px;">
                                                                Child: <?= htmlspecialchars($booking['child_name']) ?> (Age: <?= $booking['child_age'] ?? 'N/A' ?>)
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="student-avatar me-3">
                                                        <?= strtoupper(substr($booking['student_name'], 0, 1)) ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-medium" style="font-size: 14px;"><?= htmlspecialchars($booking['student_name']) ?></div>
                                                        <div class="text-muted" style="font-size: 12px;"><?= htmlspecialchars($booking['student_email']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="fw-medium" style="font-size: 14px;"><?= htmlspecialchars($booking['class_name']) ?></div>
                                                <div class="text-muted" style="font-size: 12px;">
                                                    <?= date('M j, Y g:i A', strtotime($booking['class_time'])) ?>
                                                </div>
                                                <div class="text-muted" style="font-size: 12px;">
                                                    $<?= number_format($booking['class_price'], 2) ?> • <?= $booking['instructor_name'] ?? 'TBA' ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge <?= 
                                                    $booking['status'] === 'pending' ? 'badge-warning' : 
                                                    ($booking['status'] === 'confirmed' ? 'badge-success' : 
                                                    ($booking['status'] === 'cancelled' ? 'badge-danger' : 
                                                    ($booking['status'] === 'rejected' ? 'badge-secondary' : 
                                                    ($booking['status'] === 'completed' ? 'badge-info' : 'badge-secondary'))))
                                                ?>">
                                                    <?= ucfirst($booking['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($booking['payment_amount']): ?>
                                                    <div class="fw-medium" style="font-size: 14px;">$<?= number_format($booking['payment_amount'], 2) ?></div>
                                                    <span class="badge <?= 
                                                        ($booking['payment_status'] ?? 'pending') === 'paid' ? 'badge-success' : 
                                                        (($booking['payment_status'] ?? 'pending') === 'pending' ? 'badge-warning' : 'badge-danger')
                                                    ?>" style="font-size: 11px;">
                                                        <?= ucfirst($booking['payment_status'] ?? 'pending') ?>
                                                    </span>
                                                    <?php if ($booking['payment_method']): ?>
                                                        <div class="text-muted" style="font-size: 11px;"><?= ucfirst($booking['payment_method']) ?></div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary" style="font-size: 11px;">No Payment</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-outline-primary btn-sm btn-icon" 
                                                            data-bs-toggle="modal" data-bs-target="#viewBookingModal"
                                                            onclick="viewBooking(<?= htmlspecialchars(json_encode($booking)) ?>)"
                                                            title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button class="btn btn-outline-primary btn-sm btn-icon" 
                                                            data-bs-toggle="modal" data-bs-target="#editBookingModal"
                                                            onclick="editBooking(<?= htmlspecialchars(json_encode($booking)) ?>)"
                                                            title="Edit Booking">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <?php if ($booking['status'] === 'pending'): ?>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to confirm this booking?');">
                                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                            <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                            <input type="hidden" name="status" value="confirmed">
                                                            <button type="submit" name="update_status" class="btn btn-outline-success btn-sm btn-icon" title="Confirm Booking">
                                                                <i class="bi bi-check-lg"></i>
                                                            </button>
                                                        </form>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to reject this booking?');">
                                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                            <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                            <input type="hidden" name="status" value="rejected">
                                                            <button type="submit" name="update_status" class="btn btn-outline-warning btn-sm btn-icon" title="Reject Booking">
                                                                <i class="bi bi-x-lg"></i>
                                                            </button>
                                                        </form>
                                                    <?php elseif ($booking['status'] === 'confirmed'): ?>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to cancel this booking?');">
                                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                            <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                            <input type="hidden" name="status" value="cancelled">
                                                            <button type="submit" name="update_status" class="btn btn-outline-warning btn-sm btn-icon" title="Cancel Booking">
                                                                <i class="bi bi-x-circle"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this booking? This action cannot be undone.');">
                                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                        <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                        <button type="submit" name="delete_booking" class="btn btn-outline-danger btn-sm btn-icon" title="Delete Booking">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <input type="hidden" name="bulk_action" id="bulkActionInput">
                        </form>
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
        </main>
    </div>
    
    <!-- Add Booking Modal -->
    <div class="modal fade" id="addBookingModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Booking</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="addBookingForm">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required">Student</label>
                                <select class="form-select" name="user_id" required>
                                    <option value="">Select Student</option>
                                    <?php foreach ($students as $student): ?>
                                        <option value="<?= $student['id'] ?>"><?= htmlspecialchars($student['name']) ?> (<?= htmlspecialchars($student['email']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">Class</label>
                                <select class="form-select" name="class_id" required>
                                    <option value="">Select Class</option>
                                    <?php foreach ($available_classes as $class): ?>
                                        <option value="<?= $class['id'] ?>">
                                            <?= htmlspecialchars($class['title']) ?> - 
                                            <?= date('M j, Y g:i A', strtotime($class['start_time'])) ?> - 
                                            $<?= number_format($class['price'], 2) ?> 
                                            (<?= $class['slots_available'] ?> slots available)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Child Name</label>
                                <input type="text" class="form-control" name="child_name" placeholder="Optional - leave blank if booking for student">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Child Age</label>
                                <input type="number" class="form-control" name="child_age" min="0" max="100" placeholder="Optional">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Child Gender</label>
                                <select class="form-select" name="child_gender">
                                    <option value="">Select</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Special Notes</label>
                                <textarea class="form-control" name="special_notes" rows="3" placeholder="Any special requirements or notes..."></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Booking Status</label>
                                <select class="form-select" name="status">
                                    <option value="pending">Pending</option>
                                    <option value="confirmed">Confirmed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Payment Status</label>
                                <select class="form-select" name="payment_status">
                                    <option value="pending">Pending</option>
                                    <option value="paid">Paid</option>
                                    <option value="failed">Failed</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <div class="alert alert-info" style="font-size: 14px;">
                                    <i class="bi bi-info-circle me-2"></i>
                                    When creating a confirmed booking, class slots will automatically be reduced.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_booking" class="btn btn-primary">Create Booking</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Booking Modal -->
    <div class="modal fade" id="editBookingModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Booking</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="editBookingForm">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="booking_id" id="edit_booking_id">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required">Student</label>
                                <select class="form-select" name="user_id" id="edit_user_id" required>
                                    <?php foreach ($students as $student): ?>
                                        <option value="<?= $student['id'] ?>"><?= htmlspecialchars($student['name']) ?> (<?= htmlspecialchars($student['email']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">Class</label>
                                <select class="form-select" name="class_id" id="edit_class_id" required>
                                    <?php foreach ($available_classes as $class): ?>
                                        <option value="<?= $class['id'] ?>">
                                            <?= htmlspecialchars($class['title']) ?> - 
                                            <?= date('M j, Y g:i A', strtotime($class['start_time'])) ?> - 
                                            $<?= number_format($class['price'], 2) ?> 
                                            (<?= $class['slots_available'] ?> slots available)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Child Name</label>
                                <input type="text" class="form-control" name="child_name" id="edit_child_name">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Child Age</label>
                                <input type="number" class="form-control" name="child_age" id="edit_child_age" min="0" max="100">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Child Gender</label>
                                <select class="form-select" name="child_gender" id="edit_child_gender">
                                    <option value="">Select</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Special Notes</label>
                                <textarea class="form-control" name="special_notes" id="edit_special_notes" rows="3"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Booking Status</label>
                                <select class="form-select" name="status" id="edit_status">
                                    <option value="pending">Pending</option>
                                    <option value="confirmed">Confirmed</option>
                                    <option value="cancelled">Cancelled</option>
                                    <option value="rejected">Rejected</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Payment Status</label>
                                <select class="form-select" name="payment_status" id="edit_payment_status">
                                    <option value="pending">Pending</option>
                                    <option value="paid">Paid</option>
                                    <option value="failed">Failed</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Payment Method</label>
                                <input type="text" class="form-control" name="payment_method" id="edit_payment_method">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_booking" class="btn btn-primary">Update Booking</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- View Booking Modal -->
    <div class="modal fade" id="viewBookingModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Booking Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted">Booking ID</label>
                            <p class="fw-medium" id="view_booking_id"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Created Date</label>
                            <p id="view_created_at"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Student</label>
                            <p id="view_student_name"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Student Email</label>
                            <p id="view_student_email"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Class</label>
                            <p id="view_class_name"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Class Time</label>
                            <p id="view_class_time"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Child Name</label>
                            <p id="view_child_name"></p>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-muted">Child Age</label>
                            <p id="view_child_age"></p>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-muted">Child Gender</label>
                            <p id="view_child_gender"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Booking Status</label>
                            <p id="view_status"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Payment Status</label>
                            <p id="view_payment_status"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Payment Amount</label>
                            <p id="view_payment_amount"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Payment Method</label>
                            <p id="view_payment_method"></p>
                        </div>
                        <div class="col-12">
                            <label class="form-label text-muted">Special Notes</label>
                            <div class="border rounded p-3 bg-light">
                                <p id="view_special_notes" class="mb-0"></p>
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Edit booking function
            window.editBooking = function(booking) {
                document.getElementById('edit_booking_id').value = booking.id;
                document.getElementById('edit_user_id').value = booking.user_id;
                document.getElementById('edit_class_id').value = booking.class_id;
                document.getElementById('edit_child_name').value = booking.child_name || '';
                document.getElementById('edit_child_age').value = booking.child_age || '';
                document.getElementById('edit_child_gender').value = booking.child_gender || '';
                document.getElementById('edit_special_notes').value = booking.special_notes || '';
                document.getElementById('edit_status').value = booking.status;
                document.getElementById('edit_payment_status').value = booking.payment_status || 'pending';
                document.getElementById('edit_payment_method').value = booking.payment_method || '';
            }
            
            // View booking function
            window.viewBooking = function(booking) {
                document.getElementById('view_booking_id').textContent = '#' + booking.id.toString().padStart(5, '0');
                document.getElementById('view_created_at').textContent = new Date(booking.created_at).toLocaleString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
                document.getElementById('view_student_name').textContent = booking.student_name;
                document.getElementById('view_student_email').textContent = booking.student_email;
                document.getElementById('view_class_name').textContent = booking.class_name;
                document.getElementById('view_class_time').textContent = new Date(booking.class_time).toLocaleString('en-US', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
                document.getElementById('view_child_name').textContent = booking.child_name || 'Self';
                document.getElementById('view_child_age').textContent = booking.child_age || 'N/A';
                document.getElementById('view_child_gender').textContent = booking.child_gender ? booking.child_gender.charAt(0).toUpperCase() + booking.child_gender.slice(1) : 'N/A';
                
                // Status with badge
                const statusText = booking.status.charAt(0).toUpperCase() + booking.status.slice(1);
                let statusClass = 'badge-secondary';
                switch(booking.status) {
                    case 'pending': statusClass = 'badge-warning'; break;
                    case 'confirmed': statusClass = 'badge-success'; break;
                    case 'cancelled': statusClass = 'badge-danger'; break;
                    case 'rejected': statusClass = 'badge-secondary'; break;
                    case 'completed': statusClass = 'badge-info'; break;
                }
                document.getElementById('view_status').innerHTML = `<span class="badge ${statusClass}">${statusText}</span>`;
                
                // Payment status
                const paymentStatus = booking.payment_status || 'pending';
                const paymentStatusText = paymentStatus.charAt(0).toUpperCase() + paymentStatus.slice(1);
                let paymentStatusClass = 'badge-secondary';
                switch(paymentStatus) {
                    case 'pending': paymentStatusClass = 'badge-warning'; break;
                    case 'paid': paymentStatusClass = 'badge-success'; break;
                    case 'failed': paymentStatusClass = 'badge-danger'; break;
                }
                document.getElementById('view_payment_status').innerHTML = `<span class="badge ${paymentStatusClass}">${paymentStatusText}</span>`;
                
                document.getElementById('view_payment_amount').textContent = booking.payment_amount ? '$' + parseFloat(booking.payment_amount).toFixed(2) : 'N/A';
                document.getElementById('view_payment_method').textContent = booking.payment_method || 'N/A';
                document.getElementById('view_special_notes').textContent = booking.special_notes || 'None';
            }
            
            // Bulk selection functions
            window.selectAllBookings = function(selectAll) {
                const checkboxes = document.querySelectorAll('.booking-checkbox');
                checkboxes.forEach(cb => {
                    cb.checked = selectAll;
                });
                updateSelection();
            }
            
            window.updateSelection = function() {
                const selected = document.querySelectorAll('.booking-checkbox:checked');
                const count = selected.length;
                const bulkActions = document.getElementById('bulkActions');
                const selectedCount = document.getElementById('selectedCount');
                
                if (selectedCount) {
                    selectedCount.textContent = count;
                }
                
                if (bulkActions) {
                    if (count > 0) {
                        bulkActions.classList.add('show');
                    } else {
                        bulkActions.classList.remove('show');
                    }
                }
            }
            
            window.clearSelection = function() {
                selectAllBookings(false);
            }
            
            window.applyBulkAction = function() {
                const action = document.getElementById('bulkActionSelect').value;
                const bulkForm = document.getElementById('bulkForm');
                const bulkActionInput = document.getElementById('bulkActionInput');
                
                if (!action) {
                    alert('Please select an action.');
                    return;
                }
                
                const selected = document.querySelectorAll('.booking-checkbox:checked');
                if (selected.length === 0) {
                    alert('No bookings selected.');
                    return;
                }
                
                let confirmMessage = '';
                switch(action) {
                    case 'confirm':
                        confirmMessage = `Are you sure you want to confirm ${selected.length} booking(s)?`;
                        break;
                    case 'reject':
                        confirmMessage = `Are you sure you want to reject ${selected.length} booking(s)?`;
                        break;
                    case 'cancel':
                        confirmMessage = `Are you sure you want to cancel ${selected.length} booking(s)?`;
                        break;
                    case 'delete':
                        confirmMessage = `Are you sure you want to delete ${selected.length} booking(s)? This action cannot be undone.`;
                        break;
                }
                
                if (!confirm(confirmMessage)) {
                    return;
                }
                
                bulkActionInput.value = action;
                bulkForm.submit();
            }
            
            // Form validation
            const addForm = document.getElementById('addBookingForm');
            const editForm = document.getElementById('editBookingForm');
            
            function validateBookingForm(form) {
                const student = form.querySelector('[name="user_id"]').value;
                const classId = form.querySelector('[name="class_id"]').value;
                
                if (!student || !classId) {
                    alert('Student and class are required fields.');
                    return false;
                }
                
                return true;
            }
            
            if (addForm) {
                addForm.addEventListener('submit', function(e) {
                    if (!validateBookingForm(this)) {
                        e.preventDefault();
                    }
                });
            }
            
            if (editForm) {
                editForm.addEventListener('submit', function(e) {
                    if (!validateBookingForm(this)) {
                        e.preventDefault();
                    }
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
            
            // Date validation
            const dateFrom = document.querySelector('input[name="date_from"]');
            const dateTo = document.querySelector('input[name="date_to"]');
            
            if (dateFrom && dateTo) {
                dateFrom.addEventListener('change', function() {
                    if (this.value && dateTo.value && this.value > dateTo.value) {
                        dateTo.value = this.value;
                    }
                });
                
                dateTo.addEventListener('change', function() {
                    if (this.value && dateFrom.value && this.value < dateFrom.value) {
                        dateFrom.value = this.value;
                    }
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
        });
    </script>
</body>
</html>