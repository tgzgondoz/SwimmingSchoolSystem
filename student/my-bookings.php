<?php
// student/my-bookings.php - Student's Bookings Management
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

// Authentication and role check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$student_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

// Handle booking cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'cancel_booking') {
        $booking_id = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
        
        if (!$booking_id || $booking_id <= 0) {
            $error_msg = "Invalid booking selection.";
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // 1. Get booking details with row lock
                $stmt = $conn->prepare("SELECT b.id, b.class_id, b.status, c.start_time FROM bookings b JOIN classes c ON b.class_id = c.id WHERE b.id = ? AND b.user_id = ? FOR UPDATE");
                $stmt->bind_param("ii", $booking_id, $student_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    throw new Exception("Booking not found or you don't have permission to cancel it.");
                }
                
                $booking = $result->fetch_assoc();
                
                // 2. Check if booking can be cancelled
                if ($booking['status'] === 'cancelled') {
                    throw new Exception("This booking has already been cancelled.");
                }
                
                // Check if class has already started
                if (strtotime($booking['start_time']) < time()) {
                    throw new Exception("Cannot cancel a class that has already started.");
                }
                
                // 3. Update booking status to cancelled
                $update_stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ? AND user_id = ?");
                $update_stmt->bind_param("ii", $booking_id, $student_id);
                
                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to cancel booking. Please try again.");
                }
                
                // 4. Increment available slots in class
                $slots_stmt = $conn->prepare("UPDATE classes SET slots_available = slots_available + 1 WHERE id = ?");
                $slots_stmt->bind_param("i", $booking['class_id']);
                $slots_stmt->execute();
                
                // 5. Commit transaction
                $conn->commit();
                
                // Set success message
                $_SESSION['success_msg'] = "Booking cancelled successfully. Your slot has been freed.";
                header('Location: my-bookings.php?success=1');
                exit();
                
            } catch (Exception $e) {
                // Rollback on any error
                $conn->rollback();
                $error_msg = $e->getMessage();
                
                // Log error for debugging
                error_log("Booking Cancellation Error - Student: {$student_id}, Booking: {$booking_id}, Error: " . $e->getMessage());
            }
        }
    }
    
    // Handle new booking creation
    if ($_POST['action'] === 'create_booking') {
        $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
        $child_name = trim($_POST['child_name'] ?? '');
        $child_age = filter_input(INPUT_POST, 'child_age', FILTER_VALIDATE_INT);
        $child_gender = $_POST['child_gender'] ?? '';
        $special_notes = trim($_POST['special_notes'] ?? '');
        
        // Validate inputs
        if (!$class_id || $class_id <= 0) {
            $error_msg = "Please select a valid class.";
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // 1. Check if class exists and has available slots
                $class_stmt = $conn->prepare("SELECT id, title, price, slots_available, start_time FROM classes WHERE id = ? AND status = 'scheduled' FOR UPDATE");
                $class_stmt->bind_param("i", $class_id);
                $class_stmt->execute();
                $class_result = $class_stmt->get_result();
                
                if ($class_result->num_rows === 0) {
                    throw new Exception("Class not found or not available for booking.");
                }
                
                $class = $class_result->fetch_assoc();
                
                // 2. Check if class is full
                if ($class['slots_available'] <= 0) {
                    throw new Exception("This class is full. Please select another class.");
                }
                
                // 3. Check if student already has a booking for this class
                $existing_stmt = $conn->prepare("SELECT id FROM bookings WHERE user_id = ? AND class_id = ? AND status NOT IN ('cancelled', 'rejected')");
                $existing_stmt->bind_param("ii", $student_id, $class_id);
                $existing_stmt->execute();
                $existing_result = $existing_stmt->get_result();
                
                if ($existing_result->num_rows > 0) {
                    throw new Exception("You already have a booking for this class.");
                }
                
                // 4. Check if class start time is in the future
                if (strtotime($class['start_time']) < time()) {
                    throw new Exception("Cannot book a class that has already started.");
                }
                
                // 5. Create booking with pending status
                $booking_stmt = $conn->prepare("INSERT INTO bookings (user_id, class_id, child_name, child_age, child_gender, special_notes, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
                $booking_stmt->bind_param("iisiss", $student_id, $class_id, $child_name, $child_age, $child_gender, $special_notes);
                
                if (!$booking_stmt->execute()) {
                    throw new Exception("Failed to create booking. Please try again.");
                }
                
                $booking_id = $booking_stmt->insert_id;
                
                // 6. Create pending payment record
                $payment_stmt = $conn->prepare("INSERT INTO payments (booking_id, user_id, amount, status, payment_method, description, created_at) VALUES (?, ?, ?, 'pending', 'manual', 'Booking created', NOW())");
                $payment_stmt->bind_param("iid", $booking_id, $student_id, $class['price']);
                $payment_stmt->execute();
                
                // 7. Commit transaction
                $conn->commit();
                
                $_SESSION['success_msg'] = "Booking request submitted successfully! Please wait for admin approval.";
                header('Location: my-bookings.php?success=1');
                exit();
                
            } catch (Exception $e) {
                // Rollback on any error
                $conn->rollback();
                $error_msg = $e->getMessage();
                error_log("Booking Creation Error - Student: {$student_id}, Class: {$class_id}, Error: " . $e->getMessage());
            }
        }
    }
}

// Load messages from session
if (isset($_SESSION['success_msg'])) {
    $success_msg = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
} elseif (isset($_GET['success'])) {
    $success_msg = "Operation successful!";
}

// Filter bookings
$filters = [
    'status' => $_GET['status'] ?? '',
    'type' => $_GET['type'] ?? 'all'
];

// Build WHERE clause for filtering
$where_conditions = ["b.user_id = ?"];
$params = [$student_id];
$param_types = 'i';

// Add status filter
if (!empty($filters['status'])) {
    $where_conditions[] = "b.status = ?";
    $params[] = $filters['status'];
    $param_types .= 's';
}

// Add type filter
if ($filters['type'] === 'upcoming') {
    $where_conditions[] = "c.start_time >= NOW()";
} elseif ($filters['type'] === 'past') {
    $where_conditions[] = "c.start_time < NOW()";
}

// Prepare query to get bookings
$query = "SELECT 
            b.*,
            c.*,
            i.name as instructor_name,
            i.specialization,
            p.status as payment_status,
            p.amount as payment_amount,
            CASE 
                WHEN c.start_time >= NOW() THEN 'upcoming'
                ELSE 'past'
            END as time_status
          FROM bookings b
          JOIN classes c ON b.class_id = c.id
          LEFT JOIN instructors i ON c.instructor_id = i.id
          LEFT JOIN payments p ON b.id = p.booking_id AND p.user_id = b.user_id
          WHERE " . implode(" AND ", $where_conditions) . "
          ORDER BY c.start_time DESC";

$bookings = [];
$stmt = $conn->prepare($query);

if ($stmt) {
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $bookings = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    error_log("Database query error: " . $conn->error);
    $error_msg = "Unable to load bookings. Please try again later.";
}

// Get available classes for new bookings
$available_classes = [];
$classes_query = "SELECT c.*, 
                  i.name as instructor_name,
                  CASE 
                    WHEN c.slots_available <= 0 THEN 'Full'
                    WHEN c.slots_available <= 2 THEN 'Almost Full'
                    ELSE 'Available'
                  END as availability_status
                  FROM classes c
                  LEFT JOIN instructors i ON c.instructor_id = i.id
                  WHERE c.status = 'scheduled' 
                  AND c.start_time >= NOW()
                  AND c.slots_available > 0
                  AND c.id NOT IN (
                    SELECT class_id FROM bookings 
                    WHERE user_id = ? 
                    AND status NOT IN ('cancelled', 'rejected')
                  )
                  ORDER BY c.start_time ASC";

$classes_stmt = $conn->prepare($classes_query);
if ($classes_stmt) {
    $classes_stmt->bind_param("i", $student_id);
    $classes_stmt->execute();
    $classes_result = $classes_stmt->get_result();
    $available_classes = $classes_result->fetch_all(MYSQLI_ASSOC);
    $classes_stmt->close();
}

// Get booking statistics
$stats = [
    'total' => 0,
    'confirmed' => 0,
    'pending' => 0,
    'cancelled' => 0,
    'upcoming' => 0,
    'rejected' => 0
];

$stats_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
    SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
    SUM(CASE WHEN b.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
    SUM(CASE WHEN c.start_time >= NOW() AND b.status IN ('confirmed', 'pending') THEN 1 ELSE 0 END) as upcoming
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    WHERE b.user_id = ?";

$stats_stmt = $conn->prepare($stats_query);
if ($stats_stmt) {
    $stats_stmt->bind_param("i", $student_id);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $stats = $stats_result->fetch_assoc() ?: $stats;
    $stats_stmt->close();
}

// Get user info
$user = [];
$user_stmt = $conn->prepare("SELECT name, email, phone, age, emergency_contact FROM users WHERE id = ?");
if ($user_stmt) {
    $user_stmt->bind_param("i", $student_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    $user = $user_result->fetch_assoc() ?: [];
    $user_stmt->close();
}

// Get pending bookings count for sidebar
$pending_count = $stats['pending'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings | Elite Swimming Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
/* ===== ROOT VARIABLES ===== */
:root {
    --primary: #3b82f6;
    --primary-dark: #2563eb;
    --primary-light: #93c5fd;
    --secondary: #64748b;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
    --info: #06b6d4;
    --dark: #1f2937;
    --light: #f8fafc;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-600: #4b5563;
    --gray-700: #374151;
    --gray-800: #1f2937;
    --gray-900: #111827;
    --border-radius: 12px;
    --box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    --transition: all 0.3s ease;
}

/* ===== LOADING OVERLAY ===== */
.loading-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(255, 255, 255, 0.95);
    z-index: 9999;
    justify-content: center;
    align-items: center;
    backdrop-filter: blur(5px);
}

.loading-spinner {
    text-align: center;
    background: white;
    padding: 3rem;
    border-radius: var(--border-radius);
    box-shadow: var(--box-shadow);
    animation: fadeIn 0.3s ease;
}

.spinner {
    width: 60px;
    height: 60px;
    border: 4px solid var(--primary-light);
    border-top: 4px solid var(--primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin: 0 auto;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ===== DASHBOARD LAYOUT ===== */
.dashboard-container {
    display: flex;
    min-height: 100vh;
    background: var(--gray-100);
    font-family: 'Poppins', sans-serif;
}

/* Sidebar */
.sidebar {
    width: 280px;
    background: linear-gradient(180deg, var(--dark) 0%, var(--gray-900) 100%);
    color: white;
    padding: 1.5rem 0;
    display: flex;
    flex-direction: column;
    position: sticky;
    top: 0;
    height: 100vh;
    box-shadow: 4px 0 12px rgba(0, 0, 0, 0.1);
}

.logo-area {
    padding: 0 1.5rem 2rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.logo {
    display: flex;
    align-items: center;
    color: white;
    text-decoration: none;
}

.logo:hover {
    color: white;
    opacity: 0.9;
}

.logo-icon {
    background: var(--primary);
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 1rem;
    font-size: 1.8rem;
}

.logo-text h3 {
    font-size: 1.2rem;
    font-weight: 700;
    margin: 0;
    line-height: 1.2;
}

.logo-text span {
    font-size: 0.85rem;
    opacity: 0.8;
    display: block;
    margin-top: 0.2rem;
}

.nav-menu {
    flex: 1;
    padding: 2rem 0;
}

.nav-item {
    margin-bottom: 0.5rem;
}

.nav-link {
    display: flex;
    align-items: center;
    padding: 1rem 1.5rem;
    color: rgba(255, 255, 255, 0.8);
    text-decoration: none;
    transition: var(--transition);
    position: relative;
    border-left: 4px solid transparent;
}

.nav-link:hover {
    color: white;
    background: rgba(255, 255, 255, 0.05);
    border-left-color: var(--primary);
}

.nav-link.active {
    color: white;
    background: rgba(59, 130, 246, 0.1);
    border-left-color: var(--primary);
    font-weight: 500;
}

.nav-link i {
    font-size: 1.3rem;
    width: 30px;
}

.nav-text {
    font-size: 1rem;
    font-weight: 500;
}

.notification-badge {
    position: absolute;
    right: 1.5rem;
    background: var(--danger);
    color: white;
    font-size: 0.75rem;
    padding: 0.2rem 0.5rem;
    border-radius: 10px;
    min-width: 20px;
    text-align: center;
}

.logout-section {
    padding: 1rem 1.5rem;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
}

.logout-section .nav-link {
    padding: 0.8rem 1rem;
    color: rgba(255, 255, 255, 0.8);
    border-radius: 8px;
}

.logout-section .nav-link:hover {
    background: rgba(239, 68, 68, 0.1);
    color: #ef4444;
    border-left-color: transparent;
}

/* Main Content */
.main-content {
    flex: 1;
    padding: 2rem;
    overflow-y: auto;
    max-width: calc(100vw - 280px);
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    background: white;
    padding: 1.5rem;
    border-radius: var(--border-radius);
    box-shadow: var(--box-shadow);
}

.header-left h1 {
    font-size: 2rem;
    font-weight: 700;
    color: var(--dark);
    margin: 0;
}

.header-left p {
    color: var(--gray-500);
    margin: 0.5rem 0 0;
    font-size: 1rem;
}

.user-profile {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.5rem 1rem;
    background: var(--gray-100);
    border-radius: var(--border-radius);
}

.user-avatar {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, var(--primary), var(--info));
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    font-weight: 700;
}

.user-info h5 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
}

.user-info p {
    margin: 0;
    font-size: 0.85rem;
    color: var(--gray-500);
}

/* ===== ALERTS ===== */
.alert-custom {
    border: none;
    border-radius: var(--border-radius);
    padding: 1rem 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--box-shadow);
    animation: slideInDown 0.3s ease;
}

@keyframes slideInDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.alert-success {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #065f46;
    border-left: 4px solid #10b981;
}

.alert-danger {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #991b1b;
    border-left: 4px solid #ef4444;
}

.alert-warning {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: #92400e;
    border-left: 4px solid #f59e0b;
}

.alert-info {
    background: linear-gradient(135deg, #cffafe 0%, #a5f3fc 100%);
    color: #155e75;
    border-left: 4px solid #06b6d4;
}

/* ===== QUICK ACTIONS ===== */
.quick-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.quick-action-btn {
    background: white;
    border: 2px dashed var(--gray-300);
    border-radius: var(--border-radius);
    padding: 1.5rem;
    text-align: center;
    text-decoration: none;
    color: var(--dark);
    transition: var(--transition);
    cursor: pointer;
}

.quick-action-btn:hover {
    border-color: var(--primary);
    transform: translateY(-2px);
    box-shadow: var(--box-shadow);
}

.quick-action-icon {
    width: 60px;
    height: 60px;
    background: var(--primary-light);
    color: var(--primary);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    margin: 0 auto 1rem;
}

.quick-action-btn h5 {
    font-size: 1.1rem;
    margin-bottom: 0.5rem;
    font-weight: 600;
}

/* ===== STATS SECTION ===== */
.stats-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    border-radius: var(--border-radius);
    padding: 1.5rem;
    box-shadow: var(--box-shadow);
    text-align: center;
    transition: var(--transition);
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    margin: 0 auto 1rem;
}

.stat-icon.primary {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: var(--primary);
}

.stat-icon.success {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: var(--success);
}

.stat-icon.warning {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: var(--warning);
}

.stat-icon.danger {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: var(--danger);
}

.stat-value {
    font-size: 2.2rem;
    font-weight: 700;
    color: var(--dark);
    margin: 0.5rem 0;
    line-height: 1;
}

.stat-label {
    font-size: 0.95rem;
    color: var(--gray-500);
    font-weight: 500;
}

/* ===== FILTER TABS ===== */
.filter-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 2rem;
    padding: 0.5rem;
    background: white;
    border-radius: var(--border-radius);
    box-shadow: var(--box-shadow);
    overflow-x: auto;
}

.filter-tab {
    padding: 0.75rem 1.5rem;
    background: var(--gray-100);
    border-radius: 8px;
    color: var(--gray-600);
    text-decoration: none;
    font-weight: 500;
    white-space: nowrap;
    transition: var(--transition);
    border: 2px solid transparent;
}

.filter-tab:hover {
    background: var(--gray-200);
    color: var(--dark);
}

.filter-tab.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
    font-weight: 600;
}

/* ===== CLASS CARDS ===== */
.fade-in {
    animation: fadeIn 0.5s ease forwards;
}

.class-card {
    background: white;
    border-radius: var(--border-radius);
    box-shadow: var(--box-shadow);
    margin-bottom: 1.5rem;
    overflow: hidden;
    transition: var(--transition);
    opacity: 0;
    transform: translateY(10px);
}

.class-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
}

.class-header {
    padding: 1.5rem 1.5rem 1rem;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 1rem;
}

.class-title {
    font-size: 1.3rem;
    font-weight: 700;
    color: var(--dark);
    margin: 0;
}

.class-instructor {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    background: var(--gray-100);
    padding: 0.5rem 1rem;
    border-radius: 50px;
}

.instructor-avatar {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--info), var(--primary));
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 1rem;
}

.class-body {
    padding: 1.5rem;
}

/* Status Timeline */
.status-timeline {
    display: flex;
    justify-content: space-between;
    margin-bottom: 1.5rem;
    padding: 1rem;
    background: var(--gray-50);
    border-radius: 10px;
    position: relative;
}

.status-timeline::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 20px;
    right: 20px;
    height: 2px;
    background: var(--gray-300);
    transform: translateY(-50%);
    z-index: 1;
}

.status-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    z-index: 2;
    flex: 1;
}

.status-step i {
    width: 40px;
    height: 40px;
    background: white;
    border: 2px solid var(--gray-300);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 0.5rem;
    font-size: 1.2rem;
    color: var(--gray-400);
}

.status-step.active i {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
    box-shadow: 0 0 0 5px rgba(59, 130, 246, 0.2);
}

.status-step.completed i {
    background: var(--success);
    border-color: var(--success);
    color: white;
}

.status-label {
    font-size: 0.85rem;
    color: var(--gray-500);
    font-weight: 500;
    text-align: center;
}

.status-step.active .status-label {
    color: var(--primary);
    font-weight: 600;
}

.status-step.completed .status-label {
    color: var(--success);
}

/* Class Details */
.class-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.class-detail {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem;
    background: var(--gray-50);
    border-radius: 8px;
}

.class-detail i {
    color: var(--primary);
    font-size: 1.1rem;
}

.class-detail span {
    font-size: 0.95rem;
    color: var(--gray-700);
    font-weight: 500;
}

.class-description {
    padding: 1rem;
    background: var(--gray-50);
    border-radius: 8px;
    font-size: 0.95rem;
    color: var(--gray-600);
    line-height: 1.5;
    margin-top: 1rem;
}

/* Availability Badges */
.class-availability {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem 1rem;
    border-radius: 50px;
    font-size: 0.85rem;
    font-weight: 600;
    white-space: nowrap;
}

.availability-available {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #065f46;
}

.availability-full {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #991b1b;
}

.availability-almost-full {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: #92400e;
}

/* Class Footer */
.class-footer {
    padding: 1.5rem;
    background: var(--gray-50);
    border-top: 1px solid var(--gray-200);
}

.class-price {
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--primary);
}

.action-btn-group {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.btn-action {
    padding: 0.6rem 1.5rem;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.95rem;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
}

.btn-action.primary {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
}

.btn-action.primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.btn-action.secondary {
    background: white;
    color: var(--dark);
    border: 2px solid var(--gray-300);
}

.btn-action.secondary:hover {
    background: var(--gray-100);
    border-color: var(--gray-400);
    transform: translateY(-2px);
}

.btn-action.danger {
    background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%);
    color: #991b1b;
    border: 2px solid transparent;
}

.btn-action.danger:hover {
    background: linear-gradient(135deg, #fca5a5 0%, #f87171 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
}

/* ===== EMPTY STATE ===== */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    background: white;
    border-radius: var(--border-radius);
    box-shadow: var(--box-shadow);
}

.empty-state-icon {
    width: 100px;
    height: 100px;
    background: var(--gray-100);
    color: var(--gray-400);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    margin: 0 auto 1.5rem;
}

.empty-state h3 {
    font-size: 1.8rem;
    color: var(--dark);
    margin-bottom: 0.5rem;
}

.empty-state p {
    color: var(--gray-500);
    font-size: 1.1rem;
    margin-bottom: 2rem;
    max-width: 500px;
    margin-left: auto;
    margin-right: auto;
}

/* ===== MODAL STYLES ===== */
.modal-class-card {
    background: white;
    border: 2px solid var(--gray-200);
    border-radius: 12px;
    padding: 1.5rem;
    cursor: pointer;
    transition: var(--transition);
    height: 100%;
}

.modal-class-card:hover {
    border-color: var(--primary-light);
    transform: translateY(-2px);
    box-shadow: var(--box-shadow);
}

.modal-class-card.selected {
    border-color: var(--primary);
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-section {
    background: var(--gray-50);
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.form-section-title {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--dark);
    margin-bottom: 1.5rem;
}

.form-section-title i {
    color: var(--primary);
    font-size: 1.3rem;
}

/* Form Elements */
.form-control, .form-select {
    border: 2px solid var(--gray-300);
    border-radius: 8px;
    padding: 0.75rem 1rem;
    font-size: 1rem;
    transition: var(--transition);
}

.form-control:focus, .form-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    outline: none;
}

.form-label {
    font-weight: 600;
    color: var(--gray-700);
    margin-bottom: 0.5rem;
    font-size: 0.95rem;
}

/* Buttons */
.btn {
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    font-weight: 600;
    font-size: 1rem;
    transition: var(--transition);
    border: none;
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    border: none;
}

.btn-primary:hover {
    background: linear-gradient(135deg, var(--primary-dark), #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.btn-secondary {
    background: var(--gray-200);
    color: var(--dark);
    border: 2px solid var(--gray-300);
}

.btn-secondary:hover {
    background: var(--gray-300);
    border-color: var(--gray-400);
    transform: translateY(-2px);
}

/* ===== RESPONSIVE DESIGN ===== */
@media (max-width: 1200px) {
    .sidebar {
        width: 250px;
    }
    
    .main-content {
        max-width: calc(100vw - 250px);
    }
    
    .stats-container {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    }
}

@media (max-width: 992px) {
    .dashboard-container {
        flex-direction: column;
    }
    
    .sidebar {
        width: 100%;
        height: auto;
        position: relative;
        padding: 1rem 0;
    }
    
    .nav-menu {
        display: flex;
        overflow-x: auto;
        padding: 1rem;
        gap: 0.5rem;
    }
    
    .nav-item {
        margin-bottom: 0;
        flex-shrink: 0;
    }
    
    .nav-link {
        border-left: none;
        border-bottom: 4px solid transparent;
        flex-direction: column;
        padding: 0.75rem 1rem;
        min-width: 100px;
    }
    
    .nav-link.active {
        border-left-color: transparent;
        border-bottom-color: var(--primary);
    }
    
    .nav-link:hover {
        border-left-color: transparent;
        border-bottom-color: var(--primary);
    }
    
    .nav-text {
        font-size: 0.85rem;
        margin-top: 0.5rem;
    }
    
    .main-content {
        max-width: 100%;
        padding: 1.5rem;
    }
    
    .header {
        flex-direction: column;
        text-align: center;
        gap: 1rem;
    }
    
    .quick-actions {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    }
    
    .status-timeline {
        overflow-x: auto;
        padding: 1rem 0.5rem;
    }
    
    .status-step {
        min-width: 80px;
    }
    
    .class-details {
        grid-template-columns: 1fr;
    }
    
    .action-btn-group {
        justify-content: flex-start;
    }
}

@media (max-width: 768px) {
    .main-content {
        padding: 1rem;
    }
    
    .header {
        padding: 1rem;
    }
    
    .header-left h1 {
        font-size: 1.5rem;
    }
    
    .stats-container {
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }
    
    .stat-card {
        padding: 1rem;
    }
    
    .stat-value {
        font-size: 1.8rem;
    }
    
    .filter-tabs {
        gap: 0.25rem;
        padding: 0.25rem;
    }
    
    .filter-tab {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
    }
    
    .class-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .class-instructor {
        align-self: flex-start;
    }
    
    .class-footer {
        flex-direction: column;
        gap: 1rem;
        align-items: stretch;
    }
    
    .class-price {
        text-align: center;
        font-size: 1.5rem;
    }
    
    .action-btn-group {
        justify-content: center;
    }
    
    .btn-action {
        flex: 1;
        justify-content: center;
        min-width: 120px;
    }
}

@media (max-width: 576px) {
    .logo-area {
        padding: 0 1rem 1rem;
    }
    
    .logo {
        flex-direction: column;
        text-align: center;
    }
    
    .logo-icon {
        margin-right: 0;
        margin-bottom: 1rem;
    }
    
    .user-profile {
        flex-direction: column;
        text-align: center;
        padding: 1rem;
    }
    
    .quick-actions {
        grid-template-columns: 1fr;
    }
    
    .stats-container {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .modal-dialog {
        margin: 0.5rem;
    }
}

/* Animation for status update */
@keyframes statusUpdate {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}

.status-update {
    animation: statusUpdate 0.5s ease;
}

/* Smooth scrolling */
html {
    scroll-behavior: smooth;
}

/* Custom scrollbar */
::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

::-webkit-scrollbar-track {
    background: var(--gray-100);
    border-radius: 4px;
}

::-webkit-scrollbar-thumb {
    background: var(--gray-400);
    border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
    background: var(--gray-500);
}

/* Print styles */
@media print {
    .sidebar,
    .header,
    .quick-actions,
    .filter-tabs,
    .class-footer,
    .btn-action {
        display: none !important;
    }
    
    .main-content {
        padding: 0;
        max-width: 100%;
    }
    
    .class-card {
        box-shadow: none;
        border: 1px solid var(--gray-300);
        break-inside: avoid;
    }
}
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p class="mt-3">Processing...</p>
        </div>
    </div>
    
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="logo-area">
                <a href="index.php" class="logo">
                    <div class="logo-icon">
                        <i class="bi bi-droplet"></i>
                    </div>
                    <div class="logo-text">
                        <h3>Elite Swimming Academy</h3>
                        <span>Student Portal</span>
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
                    <a href="classes.php" class="nav-link">
                        <i class="bi bi-calendar-check"></i>
                        <span class="nav-text">Book Classes</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="my-bookings.php" class="nav-link active">
                        <i class="bi bi-ticket-perforated"></i>
                        <span class="nav-text">My Bookings</span>
                        <?php if ($pending_count > 0): ?>
                            <span class="notification-badge"><?= $pending_count ?></span>
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
                    <a href="profile.php" class="nav-link">
                        <i class="bi bi-person-circle"></i>
                        <span class="nav-text">Profile</span>
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
            <header class="header">
                <div class="header-left">
                    <h1>My Bookings</h1>
                    <p>Manage your swimming class bookings</p>
                </div>
                <div class="user-profile">
                    <div class="user-avatar">
                        <?= isset($user['name']) ? strtoupper(substr($user['name'], 0, 1)) : 'U' ?>
                    </div>
                    <div class="user-info">
                        <h5><?= htmlspecialchars($user['name'] ?? 'User') ?></h5>
                        <p>Student ID: <?= htmlspecialchars($student_id) ?></p>
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
            
            <!-- Quick Actions -->
            <div class="quick-actions">
                <button class="quick-action-btn" data-bs-toggle="modal" data-bs-target="#bookClassModal">
                    <div class="quick-action-icon">
                        <i class="bi bi-plus-circle"></i>
                    </div>
                    <h5>Book New Class</h5>
                    <p class="text-muted mb-0">Schedule a swimming class</p>
                </button>
                
                <a href="?type=upcoming" class="quick-action-btn">
                    <div class="quick-action-icon">
                        <i class="bi bi-calendar-event"></i>
                    </div>
                    <h5>Upcoming Classes</h5>
                    <p class="text-muted mb-0"><?= $stats['upcoming'] ?> upcoming</p>
                </a>
                
                <a href="?status=pending" class="quick-action-btn">
                    <div class="quick-action-icon">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <h5>Pending Approval</h5>
                    <p class="text-muted mb-0"><?= $stats['pending'] ?> pending</p>
                </a>
            </div>
            
            <!-- Stats Section -->
            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-icon primary">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div class="stat-value"><?= $stats['total'] ?></div>
                    <div class="stat-label">Total Bookings</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon success">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stat-value"><?= $stats['confirmed'] ?></div>
                    <div class="stat-label">Confirmed</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon warning">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div class="stat-value"><?= $stats['pending'] ?></div>
                    <div class="stat-label">Pending Approval</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon danger">
                        <i class="bi bi-x-circle"></i>
                    </div>
                    <div class="stat-value"><?= $stats['cancelled'] ?></div>
                    <div class="stat-label">Cancelled</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #e5e7eb; color: #6c757d;">
                        <i class="bi bi-slash-circle"></i>
                    </div>
                    <div class="stat-value"><?= $stats['rejected'] ?></div>
                    <div class="stat-label">Rejected</div>
                </div>
            </div>
            
            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <a href="my-bookings.php" class="filter-tab <?= empty($filters['type']) && empty($filters['status']) ? 'active' : '' ?>">All Bookings</a>
                <a href="my-bookings.php?type=upcoming" class="filter-tab <?= $filters['type'] === 'upcoming' ? 'active' : '' ?>">Upcoming</a>
                <a href="my-bookings.php?type=past" class="filter-tab <?= $filters['type'] === 'past' ? 'active' : '' ?>">Past</a>
                <a href="my-bookings.php?status=confirmed" class="filter-tab <?= $filters['status'] === 'confirmed' ? 'active' : '' ?>">Confirmed</a>
                <a href="my-bookings.php?status=pending" class="filter-tab <?= $filters['status'] === 'pending' ? 'active' : '' ?>">Pending</a>
                <a href="my-bookings.php?status=cancelled" class="filter-tab <?= $filters['status'] === 'cancelled' ? 'active' : '' ?>">Cancelled</a>
                <a href="my-bookings.php?status=rejected" class="filter-tab <?= $filters['status'] === 'rejected' ? 'active' : '' ?>">Rejected</a>
            </div>
            
            <!-- Bookings List -->
            <?php if (empty($bookings)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-calendar-x"></i>
                    </div>
                    <h3>No Bookings Found</h3>
                    <p>
                        <?php if ($filters['type'] || $filters['status']): ?>
                            No bookings match your current filters.
                        <?php else: ?>
                            You haven't booked any classes yet. Start by booking your first class!
                        <?php endif; ?>
                    </p>
                    <button class="btn-action primary" data-bs-toggle="modal" data-bs-target="#bookClassModal">
                        <i class="bi bi-plus-circle me-2"></i>Book Your First Class
                    </button>
                </div>
            <?php else: ?>
                <div class="fade-in">
                    <?php foreach ($bookings as $booking): 
                        $start_time = strtotime($booking['start_time']);
                        $end_time = strtotime($booking['end_time']);
                        $duration = ($end_time - $start_time) / 60;
                        $is_upcoming = $start_time > time();
                        $has_end_time = !empty($booking['end_time']) && $booking['end_time'] != '0000-00-00 00:00:00';
                        $status_timeline = [
                            'pending' => ['label' => 'Requested', 'active' => in_array($booking['status'], ['pending', 'confirmed', 'rejected', 'cancelled'])],
                            'confirmed' => ['label' => 'Approved', 'active' => in_array($booking['status'], ['confirmed', 'rejected', 'cancelled'])],
                            'completed' => ['label' => 'Completed', 'active' => !$is_upcoming && $booking['status'] === 'confirmed'],
                            'rejected' => ['label' => 'Rejected', 'active' => $booking['status'] === 'rejected'],
                            'cancelled' => ['label' => 'Cancelled', 'active' => $booking['status'] === 'cancelled']
                        ];
                    ?>
                        <div class="class-card" style="margin-bottom: 20px;">
                            <div class="class-header">
                                <h3 class="class-title"><?= htmlspecialchars($booking['title']) ?></h3>
                                <div class="class-instructor">
                                    <div class="instructor-avatar">
                                        <?= strtoupper(substr($booking['instructor_name'] ?? 'I', 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="fw-medium"><?= htmlspecialchars($booking['instructor_name'] ?? 'TBA') ?></div>
                                        <?php if (!empty($booking['specialization'])): ?>
                                            <small class="text-muted"><?= htmlspecialchars($booking['specialization']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="class-body">
                                <!-- Status Timeline -->
                                <div class="status-timeline">
                                    <?php foreach ($status_timeline as $status => $info): ?>
                                        <?php if ($info['active']): ?>
                                            <div class="status-step <?= $booking['status'] === $status ? 'active' : '' ?> <?= ($status === 'rejected' && $booking['status'] === 'rejected') || ($status === 'cancelled' && $booking['status'] === 'cancelled') ? 'completed' : '' ?>">
                                                <?php if ($status === 'pending'): ?><i class="bi bi-clock"></i>
                                                <?php elseif ($status === 'confirmed'): ?><i class="bi bi-check-circle"></i>
                                                <?php elseif ($status === 'completed'): ?><i class="bi bi-flag"></i>
                                                <?php elseif ($status === 'rejected'): ?><i class="bi bi-slash-circle"></i>
                                                <?php elseif ($status === 'cancelled'): ?><i class="bi bi-x-circle"></i>
                                                <?php endif; ?>
                                                <div class="status-label"><?= $info['label'] ?></div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="class-details">
                                    <div class="class-detail">
                                        <i class="bi bi-calendar"></i>
                                        <span><?= date('F j, Y', $start_time) ?></span>
                                    </div>
                                    <div class="class-detail">
                                        <i class="bi bi-clock"></i>
                                        <span><?= date('g:i A', $start_time) ?>
                                            <?php if ($has_end_time): ?>
                                                - <?= date('g:i A', $end_time) ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="class-detail">
                                        <i class="bi bi-cash"></i>
                                        <span>$<?= number_format($booking['price'], 2) ?></span>
                                    </div>
                                    <div class="class-detail">
                                        <i class="bi bi-ticket"></i>
                                        <span>Booking ID: #<?= str_pad($booking['id'], 6, '0', STR_PAD_LEFT) ?></span>
                                    </div>
                                    <?php if (!empty($booking['child_name'])): ?>
                                        <div class="class-detail">
                                            <i class="bi bi-person"></i>
                                            <span>Child: <?= htmlspecialchars($booking['child_name']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (!empty($booking['description'])): ?>
                                    <div class="class-description">
                                        <?= htmlspecialchars(substr($booking['description'], 0, 150)) ?>
                                        <?= strlen($booking['description']) > 150 ? '...' : '' ?>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Status and Payment Badges -->
                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <span class="class-availability <?= $is_upcoming ? 'availability-available' : 'availability-full' ?>" style="background: <?= $booking['status'] === 'confirmed' ? '#d1fae5' : ($booking['status'] === 'pending' ? '#fef3c7' : '#fee2e2') ?>; color: <?= $booking['status'] === 'confirmed' ? '#065f46' : ($booking['status'] === 'pending' ? '#92400e' : '#991b1b') ?>;">
                                        <?= ucfirst($booking['status']) ?>
                                    </span>
                                    <?php if ($booking['payment_status']): ?>
                                        <span class="class-availability <?= $booking['payment_status'] === 'paid' ? 'availability-available' : 'availability-full' ?>">
                                            <i class="bi bi-credit-card"></i>
                                            <?= ucfirst($booking['payment_status']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="class-footer">
                                <div class="d-flex justify-content-between align-items-center w-100">
                                    <div class="class-price">$<?= number_format($booking['price'], 2) ?></div>
                                    <div class="action-btn-group">
                                        <?php if ($is_upcoming && in_array($booking['status'], ['confirmed', 'pending'])): ?>
                                            <form method="POST" class="cancel-form" onsubmit="return confirmCancellation(this)">
                                                <input type="hidden" name="action" value="cancel_booking">
                                                <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                <button type="submit" class="btn-action danger">
                                                    <i class="bi bi-x-circle"></i>
                                                    Cancel
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <?php if ($booking['status'] === 'pending'): ?>
                                            <button class="btn-action secondary" onclick="alert('Payment feature coming soon!')">
                                                <i class="bi bi-credit-card"></i>
                                                Pay Now
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($booking['status'] === 'confirmed' && ($booking['payment_status'] ?? 'pending') === 'pending'): ?>
                                            <button class="btn-action primary" onclick="alert('Payment feature coming soon!')">
                                                <i class="bi bi-credit-card"></i>
                                                Make Payment
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($booking['status'] === 'rejected'): ?>
                                            <button class="btn-action secondary" onclick="bookAgain(<?= $booking['class_id'] ?>)">
                                                <i class="bi bi-arrow-repeat"></i>
                                                Book Again
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
    
    <!-- Book Class Modal -->
    <div class="modal fade" id="bookClassModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Book a Swimming Class</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="bookingForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_booking">
                        
                        <!-- Step 1: Select Class -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="bi bi-calendar-check"></i>
                                Select Class
                            </div>
                            
                            <?php if (empty($available_classes)): ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                    No available classes found. Please check back later.
                                </div>
                            <?php else: ?>
                                <div class="row">
                                    <?php foreach ($available_classes as $class): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="modal-class-card" onclick="selectClass(this, <?= $class['id'] ?>, <?= $class['price'] ?>)">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <h6 class="mb-0"><?= htmlspecialchars($class['title']) ?></h6>
                                                    <span class="class-availability availability-<?= strtolower(str_replace(' ', '-', $class['availability_status'])) ?>">
                                                        <?= $class['availability_status'] ?>
                                                    </span>
                                                </div>
                                                <p class="text-muted mb-2" style="font-size: 13px;">
                                                    <i class="bi bi-person me-1"></i><?= htmlspecialchars($class['instructor_name'] ?? 'TBA') ?>
                                                </p>
                                                <p class="mb-1">
                                                    <i class="bi bi-calendar me-1"></i>
                                                    <?= date('M j, Y', strtotime($class['start_time'])) ?>
                                                </p>
                                                <p class="mb-1">
                                                    <i class="bi bi-clock me-1"></i>
                                                    <?= date('g:i A', strtotime($class['start_time'])) ?>
                                                </p>
                                                <div class="d-flex justify-content-between align-items-center mt-3">
                                                    <span class="fw-bold text-primary">$<?= number_format($class['price'], 2) ?></span>
                                                    <small class="text-muted">
                                                        <?= $class['slots_available'] ?> slots available
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="class_id" id="selected_class_id" required>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Step 2: Child Information (Optional) -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="bi bi-person"></i>
                                Participant Information (Optional)
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Child's Name</label>
                                    <input type="text" name="child_name" class="form-control" placeholder="If booking for a child">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Age</label>
                                    <input type="number" name="child_age" class="form-control" min="0" max="18" placeholder="Age">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Gender</label>
                                    <select name="child_gender" class="form-control">
                                        <option value="">Select</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 3: Additional Information -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="bi bi-sticky"></i>
                                Additional Information
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Special Notes</label>
                                <textarea name="special_notes" class="form-control" rows="3" placeholder="Any special requirements, allergies, or notes..."></textarea>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Note:</strong> Your booking request will be sent for admin approval. You'll receive a notification once it's approved or rejected.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="submitBookingBtn" <?= empty($available_classes) ? 'disabled' : '' ?>>
                            <i class="bi bi-send me-2"></i>Submit Booking Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let selectedClassId = null;
        let selectedClassPrice = null;
        
        // Show loading overlay
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }
        
        // Hide loading overlay
        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }
        
        // Select class for booking
        function selectClass(element, classId, price) {
            // Remove selected class from all cards
            document.querySelectorAll('.modal-class-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Add selected class to clicked card
            element.classList.add('selected');
            
            // Set selected values
            selectedClassId = classId;
            selectedClassPrice = price;
            
            // Update hidden input
            document.getElementById('selected_class_id').value = classId;
            
            // Enable submit button
            document.getElementById('submitBookingBtn').disabled = false;
        }
        
        // Book again after rejection
        function bookAgain(classId) {
            // Pre-select the class in modal
            selectedClassId = classId;
            document.getElementById('selected_class_id').value = classId;
            
            // Show booking modal
            const modal = new bootstrap.Modal(document.getElementById('bookClassModal'));
            modal.show();
            
            // Find and select the corresponding class card
            setTimeout(() => {
                document.querySelectorAll('.modal-class-card').forEach(card => {
                    if (card.querySelector('h6')?.textContent?.includes('Class ' + classId)) {
                        card.classList.add('selected');
                        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                });
                document.getElementById('submitBookingBtn').disabled = false;
            }, 500);
        }
        
        // Confirm booking cancellation
        function confirmCancellation(form) {
            if (!confirm('Are you sure you want to cancel this booking?\n\nThis action cannot be undone and will free up your slot.')) {
                return false;
            }
            
            showLoading();
            
            // Submit form via AJAX
            fetch('', {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.text())
            .then(html => {
                hideLoading();
                
                // Check if response contains success
                if (html.includes('alert-success') || html.includes('Booking cancelled')) {
                    // Reload page to show updated status
                    window.location.reload();
                } else {
                    // Parse HTML to extract error message
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const errorAlert = doc.querySelector('.alert-danger');
                    
                    if (errorAlert) {
                        alert('Cancellation failed: ' + errorAlert.textContent.trim());
                    } else {
                        alert('Cancellation failed. Please try again.');
                    }
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                alert('Network error. Please check your connection and try again.');
            });
            
            return false; // Prevent default form submission
        }
        
        // Handle booking form submission
        document.getElementById('bookingForm').addEventListener('submit', function(e) {
            if (!selectedClassId) {
                e.preventDefault();
                alert('Please select a class first.');
                return false;
            }
            
            if (!confirm('Submit booking request? The admin will review and approve/reject your request.')) {
                e.preventDefault();
                return false;
            }
            
            showLoading();
            
            // Submit via AJAX for better UX
            fetch('', {
                method: 'POST',
                body: new FormData(this),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.text())
            .then(html => {
                hideLoading();
                
                if (html.includes('alert-success') || html.includes('Booking request submitted')) {
                    // Close modal and reload page
                    const modal = bootstrap.Modal.getInstance(document.getElementById('bookClassModal'));
                    modal.hide();
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const errorAlert = doc.querySelector('.alert-danger');
                    
                    if (errorAlert) {
                        alert('Booking failed: ' + errorAlert.textContent.trim());
                    } else {
                        alert('Booking failed. Please try again.');
                    }
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                alert('Network error. Please check your connection and try again.');
            });
            
            e.preventDefault();
            return false;
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            // Add fade-in animation to cards
            const cards = document.querySelectorAll('.class-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('fade-in');
            });
            
            // Add count-up animation to stats
            const statValues = document.querySelectorAll('.stat-value');
            statValues.forEach(stat => {
                const target = parseInt(stat.textContent);
                let current = 0;
                const increment = Math.ceil(target / 20);
                
                const timer = setInterval(() => {
                    current += increment;
                    if (current >= target) {
                        current = target;
                        clearInterval(timer);
                    }
                    stat.textContent = current;
                }, 50);
            });
            
            // Add active state to filter tabs
            const filterTabs = document.querySelectorAll('.filter-tab');
            filterTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    filterTabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                });
            });
            
            // Initialize tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Check if there's a pending booking success message
            if (window.location.search.includes('success')) {
                setTimeout(() => {
                    const successAlert = document.querySelector('.alert-success');
                    if (successAlert) {
                        successAlert.scrollIntoView({ behavior: 'smooth' });
                    }
                }, 500);
            }
        });
        
        // Auto-hide alerts after 5 seconds
        document.querySelectorAll('.alert').forEach(alert => {
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });
    </script>
</body>
</html>