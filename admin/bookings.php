<?php
// admin/bookings.php - Admin Booking Management
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

// Get school info from settings
$school_name = "Elite Swimming Academy";
$school_email = "admin@aquaflow.com";

$settings_stmt = $conn->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('school_name', 'school_email')");
if ($settings_stmt) {
    $settings_stmt->execute();
    $settings_result = $settings_stmt->get_result();
    while ($setting = $settings_result->fetch_assoc()) {
        if ($setting['setting_key'] == 'school_name') {
            $school_name = $setting['setting_value'];
        } elseif ($setting['setting_key'] == 'school_email') {
            $school_email = $setting['setting_value'];
        }
    }
    $settings_stmt->close();
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

// Load success message from session
if (isset($_SESSION['success_msg'])) {
    $success_msg = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}

// Load error message from session
if (isset($_SESSION['error_msg'])) {
    $error_msg = $_SESSION['error_msg'];
    unset($_SESSION['error_msg']);
}

// Handle booking status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $booking_id = intval($_POST['booking_id']);
    $status = $_POST['status'];
    
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
            }
            
            $_SESSION['success_msg'] = "Booking status updated successfully!";
            header("Location: bookings.php");
            exit();
        } else {
            $error_msg = "Failed to update booking status.";
        }
        $update_stmt->close();
    } catch (Exception $e) {
        error_log("Booking update error: " . $e->getMessage());
        $error_msg = "An error occurred while updating the booking.";
    }
}

// Handle add booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_booking'])) {
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
                    $_SESSION['success_msg'] = 'Booking created successfully!';
                    header('Location: bookings.php');
                    exit();
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

// Handle edit/update booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_booking'])) {
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

                    $_SESSION['success_msg'] = 'Booking updated successfully!';
                    header('Location: bookings.php');
                    exit();
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

// Handle booking deletion
if (isset($_GET['delete'])) {
    $booking_id = intval($_GET['delete']);
    
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
        
        // Delete the booking
        $delete_stmt = $conn->prepare("DELETE FROM bookings WHERE id = ?");
        $delete_stmt->bind_param("i", $booking_id);
        
        if ($delete_stmt->execute()) {
            $_SESSION['success_msg'] = "Booking deleted successfully!";
            header("Location: bookings.php");
            exit();
        } else {
            $error_msg = "Failed to delete booking.";
        }
        $delete_stmt->close();
    } catch (Exception $e) {
        error_log("Booking deletion error: " . $e->getMessage());
        $error_msg = "An error occurred while deleting the booking.";
    }
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $selected_bookings = $_POST['selected_bookings'] ?? [];
    
    if (empty($selected_bookings)) {
        $error_msg = "No bookings selected.";
    } else {
        $placeholders = implode(',', array_fill(0, count($selected_bookings), '?'));
        $types = str_repeat('i', count($selected_bookings));
        
        try {
            if ($action == 'confirm') {
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

                    $_SESSION['success_msg'] = "Selected bookings confirmed successfully!";
                } else {
                    $error_msg = "Failed to confirm bookings.";
                }
                $update_stmt->close();
            } elseif ($action == 'cancel') {
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
                    
                    $_SESSION['success_msg'] = "Selected bookings cancelled successfully!";
                } else {
                    $error_msg = "Failed to cancel bookings.";
                }
                $update_stmt->close();
            } elseif ($action == 'delete') {
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
                    $_SESSION['success_msg'] = "Selected bookings deleted successfully!";
                } else {
                    $error_msg = "Failed to delete bookings.";
                }
                $delete_stmt->close();
            }
            
            header("Location: bookings.php");
            exit();
        } catch (Exception $e) {
            error_log("Bulk action error: " . $e->getMessage());
            $error_msg = "An error occurred while processing the bulk action.";
        }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Build query with filters
$query = "
    SELECT b.*, 
           u.name as student_name, 
           u.email as student_email,
           u.phone as student_phone,
           c.title as class_name,
           c.start_time as class_time,
           c.price as class_price,
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

// Pagination
$per_page = 10;
$total_pages = ceil($total_bookings / $per_page);
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $per_page;

$query .= " LIMIT ? OFFSET ?";
$params[] = $per_page;
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Bookings | <?= htmlspecialchars($school_name) ?></title>
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
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            text-align: center;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
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
        }
        
        .stat-card:nth-child(1)::before { background: linear-gradient(90deg, var(--warning), #ffca2c); }
        .stat-card:nth-child(2)::before { background: linear-gradient(90deg, var(--success), #157347); }
        .stat-card:nth-child(3)::before { background: linear-gradient(90deg, var(--danger), #bb2d3b); }
        .stat-card:nth-child(4)::before { background: linear-gradient(90deg, var(--info), #0891b2); }
        .stat-card:nth-child(5)::before { background: linear-gradient(90deg, #6c757d, #495057); }
        
        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin: 0 auto 15px;
            color: white;
        }
        
        .stat-card:nth-child(1) .stat-icon { background: linear-gradient(135deg, var(--warning) 0%, #ffca2c 100%); }
        .stat-card:nth-child(2) .stat-icon { background: linear-gradient(135deg, var(--success) 0%, #157347 100%); }
        .stat-card:nth-child(3) .stat-icon { background: linear-gradient(135deg, var(--danger) 0%, #bb2d3b 100%); }
        .stat-card:nth-child(4) .stat-icon { background: linear-gradient(135deg, var(--info) 0%, #0891b2 100%); }
        .stat-card:nth-child(5) .stat-icon { background: linear-gradient(135deg, #6c757d 0%, #495057 100%); }
        
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
        
        /* Content Area */
        .content-area {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .content-header h2 {
            font-size: 24px;
            font-weight: 600;
            color: var(--dark);
            margin: 0;
        }
        
        /* Filters */
        .filters-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            border: 1px solid #e9ecef;
        }
        
        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .filters-header h5 {
            font-weight: 600;
            margin: 0;
            color: var(--dark);
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }
        
        .filter-group label {
            font-weight: 500;
            font-size: 14px;
            margin-bottom: 5px;
            color: #495057;
        }
        
        /* Table */
        .table-responsive {
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e9ecef;
        }
        
        .table {
            margin: 0;
        }
        
        .table thead th {
            background: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            color: #495057;
            padding: 15px 12px;
            white-space: nowrap;
        }
        
        .table tbody td {
            padding: 15px 12px;
            vertical-align: middle;
            border-color: #e9ecef;
        }
        
        .table tbody tr:hover {
            background-color: rgba(13, 110, 253, 0.03);
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 85px;
        }
        
        .status-pending { background: rgba(255, 193, 7, 0.1); color: var(--warning); border: 1px solid rgba(255, 193, 7, 0.2); }
        .status-confirmed { background: rgba(25, 135, 84, 0.1); color: var(--success); border: 1px solid rgba(25, 135, 84, 0.2); }
        .status-cancelled { background: rgba(220, 53, 69, 0.1); color: var(--danger); border: 1px solid rgba(220, 53, 69, 0.2); }
        .status-completed { background: rgba(13, 202, 240, 0.1); color: var(--info); border: 1px solid rgba(13, 202, 240, 0.2); }
        
        /* Payment Status Badges */
        .payment-pending { background: rgba(255, 193, 7, 0.1); color: var(--warning); border: 1px solid rgba(255, 193, 7, 0.2); }
        .payment-paid { background: rgba(25, 135, 84, 0.1); color: var(--success); border: 1px solid rgba(25, 135, 84, 0.2); }
        .payment-failed { background: rgba(220, 53, 69, 0.1); color: var(--danger); border: 1px solid rgba(220, 53, 69, 0.2); }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 13px;
        }
        
        /* Bulk Actions */
        .bulk-actions {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        /* Pagination */
        .pagination {
            justify-content: center;
            margin-top: 30px;
        }
        
        .page-link {
            border: none;
            color: var(--primary);
            font-weight: 500;
            padding: 8px 15px;
            margin: 0 2px;
            border-radius: 8px !important;
        }
        
        .page-link:hover {
            background: rgba(13, 110, 253, 0.1);
            color: var(--primary);
        }
        
        .page-item.active .page-link {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
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
            border-radius: 15px 15px 0 0 !important;
            padding: 20px 30px;
        }
        
        .modal-body {
            padding: 30px;
        }
        
        /* Alerts */
        .alert {
            border-radius: 12px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: rgba(25, 135, 84, 0.1);
            color: var(--success);
            border-left: 4px solid var(--success);
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }
        
        /* Checkbox */
        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
            color: #6c757d;
        }
        
        .empty-state h5 {
            font-weight: 600;
            margin-bottom: 10px;
            color: #495057;
        }
        
        .empty-state p {
            color: #6c757d;
            margin-bottom: 20px;
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
            
            .content-header {
                flex-direction: column;
                align-items: stretch;
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
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .table-responsive {
                font-size: 14px;
            }
            
            .table thead th,
            .table tbody td {
                padding: 10px 8px;
            }
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease forwards;
        }
        
        /* Custom Scrollbar */
        .table-responsive::-webkit-scrollbar {
            height: 8px;
        }
        
        .table-responsive::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .table-responsive::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
        }
        
        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
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
                        <h3><?= htmlspecialchars($school_name) ?></h3>
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
                    <h1>Manage Bookings 📋</h1>
                    <p>View, manage, and update swimming class bookings.</p>
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
            
            <!-- Booking Statistics -->
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $booking_stats['pending'] ?? 0 ?></h3>
                        <p>Pending Bookings</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $booking_stats['confirmed'] ?? 0 ?></h3>
                        <p>Confirmed Bookings</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-x-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $booking_stats['cancelled'] ?? 0 ?></h3>
                        <p>Cancelled Bookings</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-check-all"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $booking_stats['completed'] ?? 0 ?></h3>
                        <p>Completed Bookings</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-journal-text"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $total_count ?></h3>
                        <p>Total Bookings</p>
                    </div>
                </div>
            </div>
            
            <!-- Main Content Area -->
            <div class="content-area fade-in">
                <!-- Messages -->
                <?php if ($success_msg): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle me-2"></i>
                        <?= htmlspecialchars($success_msg) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_msg): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <?= htmlspecialchars($error_msg) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Content Header -->
                <div class="content-header">
                    <h2><i class="bi bi-journal-check me-2"></i> All Bookings</h2>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBookingModal">
                        <i class="bi bi-plus-circle me-2"></i> Create New Booking
                    </button>
                </div>
                
                <!-- Filters -->
                <div class="filters-card">
                    <div class="filters-header">
                        <h5><i class="bi bi-funnel me-2"></i> Filter Bookings</h5>
                        <a href="bookings.php" class="btn btn-sm btn-outline-secondary">Clear Filters</a>
                    </div>
                    <form method="GET" class="filter-form">
                        <div class="filter-group">
                            <label for="status">Status</label>
                            <select class="form-control" id="status" name="status">
                                <option value="">All Status</option>
                                <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="confirmed" <?= $status_filter == 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                <option value="cancelled" <?= $status_filter == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                <option value="completed" <?= $status_filter == 'completed' ? 'selected' : '' ?>>Completed</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="date_from">From Date</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="date_to">To Date</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="search">Search</label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   placeholder="Search by student, class, or child name" value="<?= htmlspecialchars($search) ?>">
                        </div>
                        
                        <div class="filter-group">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search me-2"></i> Apply Filters
                            </button>
                        </div>
                    </form>
                </div>
                
                <?php if (!empty($bookings)): ?>
                    <!-- Bulk Actions -->
                    <form method="POST" id="bulkForm">
                        <div class="bulk-actions">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="selectAll">
                                <label class="form-check-label" for="selectAll">Select All</label>
                            </div>
                            
                            <select class="form-select w-auto" name="bulk_action" style="max-width: 200px;">
                                <option value="">Bulk Actions</option>
                                <option value="confirm">Confirm Selected</option>
                                <option value="cancel">Cancel Selected</option>
                                <option value="delete">Delete Selected</option>
                            </select>
                            
                            <button type="submit" class="btn btn-outline-primary">
                                <i class="bi bi-play-circle me-2"></i> Apply
                            </button>
                        </div>
                        
                        <!-- Bookings Table -->
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th width="50">
                                            <input type="checkbox" class="form-check-input" id="selectAllTable">
                                        </th>
                                        <th>Booking ID</th>
                                        <th>Student</th>
                                        <th>Child Info</th>
                                        <th>Class</th>
                                        <th>Instructor</th>
                                        <th>Date & Time</th>
                                        <th>Status</th>
                                        <th>Payment</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bookings as $booking): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="form-check-input booking-checkbox" 
                                                       name="selected_bookings[]" value="<?= $booking['id'] ?>">
                                            </td>
                                            <td>
                                                <strong>#<?= str_pad($booking['id'], 5, '0', STR_PAD_LEFT) ?></strong>
                                                <br>
                                                <small class="text-muted"><?= date('M d, Y', strtotime($booking['created_at'])) ?></small>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($booking['student_name']) ?></strong>
                                                <br>
                                                <small class="text-muted"><?= htmlspecialchars($booking['student_email']) ?></small>
                                                <?php if ($booking['student_phone']): ?>
                                                    <br>
                                                    <small><i class="bi bi-telephone me-1"></i> <?= htmlspecialchars($booking['student_phone']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($booking['child_name']): ?>
                                                    <strong><?= htmlspecialchars($booking['child_name']) ?></strong>
                                                    <br>
                                                    <small>Age: <?= $booking['child_age'] ?? 'N/A' ?> • Gender: <?= ucfirst($booking['child_gender'] ?? 'N/A') ?></small>
                                                    <?php if ($booking['special_notes']): ?>
                                                        <br>
                                                        <small class="text-muted"><i class="bi bi-sticky me-1"></i> <?= htmlspecialchars($booking['special_notes']) ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Self</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($booking['class_name']) ?></strong>
                                                <br>
                                                <small class="text-muted">$<?= number_format($booking['class_price'], 2) ?></small>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($booking['instructor_name'] ?? 'TBA') ?>
                                            </td>
                                            <td>
                                                <?= date('M d, Y', strtotime($booking['class_time'])) ?>
                                                <br>
                                                <small class="text-muted"><?= date('g:i A', strtotime($booking['class_time'])) ?></small>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?= $booking['status'] ?>">
                                                    <?= ucfirst($booking['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($booking['payment_amount']): ?>
                                                    <span class="payment-badge payment-<?= $booking['payment_status'] ?? 'pending' ?>">
                                                        <?= ucfirst($booking['payment_status'] ?? 'pending') ?>
                                                    </span>
                                                    <br>
                                                    <small>$<?= number_format($booking['payment_amount'], 2) ?></small>
                                                    <?php if ($booking['payment_method']): ?>
                                                        <br>
                                                        <small class="text-muted"><?= ucfirst($booking['payment_method']) ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="payment-badge payment-pending">No Payment</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    
                                                                                    <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                                                            data-bs-toggle="modal" 
                                                                                            data-bs-target="#editModal<?= $booking['id'] ?>">
                                                                                        <i class="bi bi-pencil"></i>
                                                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#viewModal<?= $booking['id'] ?>">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    
                                                    <?php if ($booking['status'] != 'confirmed'): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-success" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#confirmModal<?= $booking['id'] ?>">
                                                            <i class="bi bi-check-lg"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($booking['status'] != 'cancelled'): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-warning" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#cancelModal<?= $booking['id'] ?>">
                                                            <i class="bi bi-x-lg"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <a href="bookings.php?delete=<?= $booking['id'] ?>" 
                                                       class="btn btn-sm btn-outline-danger"
                                                       onclick="return confirm('Are you sure you want to delete this booking?');">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        
                                        <!-- View Modal -->
                                        <div class="modal fade" id="viewModal<?= $booking['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Booking Details - #<?= str_pad($booking['id'], 5, '0', STR_PAD_LEFT) ?></h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="row">
                                                            <div class="col-md-6 mb-3">
                                                                <h6><i class="bi bi-person me-2"></i> Student Information</h6>
                                                                <p class="mb-1"><strong>Name:</strong> <?= htmlspecialchars($booking['student_name']) ?></p>
                                                                <p class="mb-1"><strong>Email:</strong> <?= htmlspecialchars($booking['student_email']) ?></p>
                                                                <?php if ($booking['student_phone']): ?>
                                                                    <p class="mb-1"><strong>Phone:</strong> <?= htmlspecialchars($booking['student_phone']) ?></p>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="col-md-6 mb-3">
                                                                <h6><i class="bi bi-people me-2"></i> Child Information</h6>
                                                                <?php if ($booking['child_name']): ?>
                                                                    <p class="mb-1"><strong>Name:</strong> <?= htmlspecialchars($booking['child_name']) ?></p>
                                                                    <p class="mb-1"><strong>Age:</strong> <?= $booking['child_age'] ?? 'N/A' ?></p>
                                                                    <p class="mb-1"><strong>Gender:</strong> <?= ucfirst($booking['child_gender'] ?? 'N/A') ?></p>
                                                                <?php else: ?>
                                                                    <p class="mb-1 text-muted">Booking for student themselves</p>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="row">
                                                            <div class="col-md-6 mb-3">
                                                                <h6><i class="bi bi-calendar-week me-2"></i> Class Information</h6>
                                                                <p class="mb-1"><strong>Class:</strong> <?= htmlspecialchars($booking['class_name']) ?></p>
                                                                <p class="mb-1"><strong>Instructor:</strong> <?= htmlspecialchars($booking['instructor_name'] ?? 'TBA') ?></p>
                                                                <p class="mb-1"><strong>Date & Time:</strong> <?= date('F j, Y g:i A', strtotime($booking['class_time'])) ?></p>
                                                                <p class="mb-1"><strong>Price:</strong> $<?= number_format($booking['class_price'], 2) ?></p>
                                                            </div>
                                                            <div class="col-md-6 mb-3">
                                                                <h6><i class="bi bi-info-circle me-2"></i> Booking Details</h6>
                                                                <p class="mb-1"><strong>Status:</strong> 
                                                                    <span class="status-badge status-<?= $booking['status'] ?>">
                                                                        <?= ucfirst($booking['status']) ?>
                                                                    </span>
                                                                </p>
                                                                <p class="mb-1"><strong>Created:</strong> <?= date('F j, Y g:i A', strtotime($booking['created_at'])) ?></p>
                                                                <p class="mb-1"><strong>Payment Status:</strong> 
                                                                    <span class="payment-badge payment-<?= $booking['payment_status'] ?? 'pending' ?>">
                                                                        <?= ucfirst($booking['payment_status'] ?? 'pending') ?>
                                                                    </span>
                                                                </p>
                                                                <?php if ($booking['payment_method']): ?>
                                                                    <p class="mb-1"><strong>Payment Method:</strong> <?= ucfirst($booking['payment_method']) ?></p>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        
                                                        <?php if ($booking['special_notes']): ?>
                                                            <div class="mb-3">
                                                                <h6><i class="bi bi-sticky me-2"></i> Special Notes</h6>
                                                                <div class="alert alert-light">
                                                                    <?= nl2br(htmlspecialchars($booking['special_notes'])) ?>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Confirm Modal -->
                                        <div class="modal fade" id="confirmModal<?= $booking['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Confirm Booking</h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p>Are you sure you want to confirm this booking?</p>
                                                            <p><strong>Student:</strong> <?= htmlspecialchars($booking['student_name']) ?></p>
                                                            <p><strong>Class:</strong> <?= htmlspecialchars($booking['class_name']) ?></p>
                                                            <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                            <input type="hidden" name="status" value="confirmed">
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="update_status" class="btn btn-success">Confirm Booking</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Cancel Modal -->
                                        <div class="modal fade" id="cancelModal<?= $booking['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Cancel Booking</h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p>Are you sure you want to cancel this booking?</p>
                                                            <p><strong>Student:</strong> <?= htmlspecialchars($booking['student_name']) ?></p>
                                                            <p><strong>Class:</strong> <?= htmlspecialchars($booking['class_name']) ?></p>
                                                            <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                            <input type="hidden" name="status" value="cancelled">
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                            <button type="submit" name="update_status" class="btn btn-warning">Cancel Booking</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Edit Modal -->
                                        <div class="modal fade" id="editModal<?= $booking['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Edit Booking - #<?= str_pad($booking['id'], 5, '0', STR_PAD_LEFT) ?></h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                            <div class="row g-3">
                                                                <div class="col-md-6">
                                                                    <label class="form-label required">Student</label>
                                                                    <select name="user_id" class="form-select" required>
                                                                        <?php foreach ($students as $stu): ?>
                                                                            <option value="<?= $stu['id'] ?>" <?= $stu['id'] == $booking['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($stu['name']) ?> &lt;<?= htmlspecialchars($stu['email']) ?>&gt;</option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label required">Class</label>
                                                                    <select name="class_id" class="form-select" required>
                                                                        <?php
                                                                            // Ensure current class is present first
                                                                            echo '<option value="' . $booking['class_id'] . '">' . htmlspecialchars($booking['class_name']) . ' — ' . date('M j, Y g:i A', strtotime($booking['class_time'])) . ' (current)</option>';
                                                                        ?>
                                                                        <?php foreach ($available_classes as $cls): ?>
                                                                            <?php if ($cls['id'] == $booking['class_id']) continue; ?>
                                                                            <option value="<?= $cls['id'] ?>"><?= htmlspecialchars($cls['title']) ?> — <?= date('M j, Y g:i A', strtotime($cls['start_time'])) ?> (<?= $cls['slots_available'] ?> slots)</option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Child Name (optional)</label>
                                                                    <input type="text" name="child_name" class="form-control" value="<?= htmlspecialchars($booking['child_name']) ?>">
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <label class="form-label">Child Age</label>
                                                                    <input type="number" name="child_age" class="form-control" min="0" value="<?= htmlspecialchars($booking['child_age']) ?>">
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <label class="form-label">Child Gender</label>
                                                                    <select name="child_gender" class="form-select">
                                                                        <option value="">Select</option>
                                                                        <option value="male" <?= ($booking['child_gender'] === 'male') ? 'selected' : '' ?>>Male</option>
                                                                        <option value="female" <?= ($booking['child_gender'] === 'female') ? 'selected' : '' ?>>Female</option>
                                                                        <option value="other" <?= ($booking['child_gender'] === 'other') ? 'selected' : '' ?>>Other</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-12">
                                                                    <label class="form-label">Special Notes</label>
                                                                    <textarea name="special_notes" class="form-control" rows="3"><?= htmlspecialchars($booking['special_notes']) ?></textarea>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Booking Status</label>
                                                                    <select name="status" class="form-select">
                                                                        <option value="pending" <?= $booking['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                                        <option value="confirmed" <?= $booking['status'] === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                                                        <option value="cancelled" <?= $booking['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Payment Status</label>
                                                                    <select name="payment_status" class="form-select">
                                                                        <option value="pending" <?= ($booking['payment_status'] ?? 'pending') === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                                        <option value="paid" <?= ($booking['payment_status'] ?? '') === 'paid' ? 'selected' : '' ?>>Paid</option>
                                                                        <option value="failed" <?= ($booking['payment_status'] ?? '') === 'failed' ? 'selected' : '' ?>>Failed</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Payment Method</label>
                                                                    <input type="text" name="payment_method" class="form-control" value="<?= htmlspecialchars($booking['payment_method'] ?? '') ?>">
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="update_booking" class="btn btn-primary">Save Changes</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Bookings pagination">
                            <ul class="pagination">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $page - 1 ?>&status=<?= htmlspecialchars($status_filter) ?>&date_from=<?= htmlspecialchars($date_from) ?>&date_to=<?= htmlspecialchars($date_to) ?>&search=<?= htmlspecialchars($search) ?>">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?page=<?= $i ?>&status=<?= htmlspecialchars($status_filter) ?>&date_from=<?= htmlspecialchars($date_from) ?>&date_to=<?= htmlspecialchars($date_to) ?>&search=<?= htmlspecialchars($search) ?>">
                                            <?= $i ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $page + 1 ?>&status=<?= htmlspecialchars($status_filter) ?>&date_from=<?= htmlspecialchars($date_from) ?>&date_to=<?= htmlspecialchars($date_to) ?>&search=<?= htmlspecialchars($search) ?>">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- Empty State -->
                    <div class="empty-state">
                        <i class="bi bi-journal-x"></i>
                        <h5>No Bookings Found</h5>
                        <p>No bookings match your current filters.</p>
                        <a href="bookings.php" class="btn btn-primary">
                            <i class="bi bi-arrow-clockwise me-2"></i> Clear Filters
                        </a>
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
                            <h5 class="modal-title">Create New Booking</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label required">Student</label>
                                        <select name="user_id" class="form-select" required>
                                            <option value="">Select Student</option>
                                            <?php foreach ($students as $stu): ?>
                                                <option value="<?= $stu['id'] ?>"><?= htmlspecialchars($stu['name']) ?> &lt;<?= htmlspecialchars($stu['email']) ?>&gt;</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label required">Class</label>
                                        <select name="class_id" class="form-select" required>
                                            <option value="">Select Class</option>
                                            <?php foreach ($available_classes as $cls): ?>
                                                <option value="<?= $cls['id'] ?>"><?= htmlspecialchars($cls['title']) ?> — <?= date('M j, Y g:i A', strtotime($cls['start_time'])) ?> (<?= $cls['slots_available'] ?> slots)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Child Name (optional)</label>
                                        <input type="text" name="child_name" class="form-control">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Child Age</label>
                                        <input type="number" name="child_age" class="form-control" min="0">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Child Gender</label>
                                        <select name="child_gender" class="form-select">
                                            <option value="">Select</option>
                                            <option value="male">Male</option>
                                            <option value="female">Female</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Special Notes</label>
                                        <textarea name="special_notes" class="form-control" rows="3"></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Booking Status</label>
                                        <select name="status" class="form-select">
                                            <option value="pending">Pending</option>
                                            <option value="confirmed">Confirmed</option>
                                            <option value="cancelled">Cancelled</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Payment Status</label>
                                        <select name="payment_status" class="form-select">
                                            <option value="pending">Pending</option>
                                            <option value="paid">Paid</option>
                                            <option value="failed">Failed</option>
                                        </select>
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

            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
            <script>
                // Move modal elements to document.body to avoid issues when modals
                // are rendered inside tables/forms which can prevent backdrop/close
                document.addEventListener('DOMContentLoaded', function() {
                    document.querySelectorAll('.modal').forEach(function(modal) {
                        if (modal.parentNode !== document.body) {
                            document.body.appendChild(modal);
                        }
                    });
                });
            </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Select all checkboxes
            const selectAll = document.getElementById('selectAll');
            const selectAllTable = document.getElementById('selectAllTable');
            const checkboxes = document.querySelectorAll('.booking-checkbox');
            
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                });
            }
            
            if (selectAllTable) {
                selectAllTable.addEventListener('change', function() {
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                });
            }
            
            // Bulk form validation
            const bulkForm = document.getElementById('bulkForm');
            if (bulkForm) {
                bulkForm.addEventListener('submit', function(e) {
                    const selectedAction = this.querySelector('select[name="bulk_action"]').value;
                    const selectedBookings = Array.from(checkboxes).filter(cb => cb.checked);
                    
                    if (!selectedAction) {
                        e.preventDefault();
                        alert('Please select a bulk action.');
                        return false;
                    }
                    
                    if (selectedBookings.length === 0) {
                        e.preventDefault();
                        alert('Please select at least one booking.');
                        return false;
                    }
                    
                    if (selectedAction === 'delete') {
                        if (!confirm(`Are you sure you want to delete ${selectedBookings.length} booking(s)?`)) {
                            e.preventDefault();
                            return false;
                        }
                    }
                    
                    if (selectedAction === 'confirm' || selectedAction === 'cancel') {
                        const actionText = selectedAction === 'confirm' ? 'confirm' : 'cancel';
                        if (!confirm(`Are you sure you want to ${actionText} ${selectedBookings.length} booking(s)?`)) {
                            e.preventDefault();
                            return false;
                        }
                    }
                    
                    return true;
                });
            }
            
            // Auto-close alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
            
            // Date range validation
            const dateFrom = document.getElementById('date_from');
            const dateTo = document.getElementById('date_to');
            
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
        });
    </script>
</body>
</html>