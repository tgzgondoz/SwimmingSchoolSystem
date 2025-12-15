<?php
// admin/bookings.php
session_start();
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

requireRole('admin');
$user = getCurrentUser($conn);

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_booking_status'])) {
        $booking_id = intval($_POST['booking_id']);
        $status = $_POST['status'];
        
        $stmt = $conn->prepare("UPDATE bookings SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $status, $booking_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Booking status updated successfully!";
            
            // If status changed to confirmed, update class slots
            if ($status === 'confirmed') {
                $update_stmt = $conn->prepare("
                    UPDATE classes c 
                    JOIN bookings b ON b.class_id = c.id 
                    SET c.slots_available = GREATEST(0, c.slots_available - 1) 
                    WHERE b.id = ? AND c.slots_available > 0
                ");
                $update_stmt->bind_param('i', $booking_id);
                $update_stmt->execute();
            }
        } else {
            $_SESSION['error_message'] = "Failed to update booking status.";
        }
        header("Location: bookings.php");
        exit();
    }
    
    if (isset($_POST['delete_booking'])) {
        $booking_id = intval($_POST['booking_id']);
        
        // Get booking info before deletion to update slots
        $booking_info = $conn->query("
            SELECT b.class_id, b.status 
            FROM bookings b 
            WHERE b.id = $booking_id
        ")->fetch_assoc();
        
        $stmt = $conn->prepare("DELETE FROM bookings WHERE id = ?");
        $stmt->bind_param('i', $booking_id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Booking deleted successfully!";
            
            // If confirmed booking was deleted, free up the slot
            if ($booking_info && $booking_info['status'] === 'confirmed') {
                $update_stmt = $conn->prepare("
                    UPDATE classes 
                    SET slots_available = slots_available + 1 
                    WHERE id = ?
                ");
                $update_stmt->bind_param('i', $booking_info['class_id']);
                $update_stmt->execute();
            }
        } else {
            $_SESSION['error_message'] = "Failed to delete booking.";
        }
        header("Location: bookings.php");
        exit();
    }
    
    if (isset($_POST['add_booking'])) {
        $user_id = intval($_POST['user_id']);
        $class_id = intval($_POST['class_id']);
        $status = $_POST['status'];
        
        // Check if booking already exists
        $check_stmt = $conn->prepare("SELECT id FROM bookings WHERE user_id = ? AND class_id = ?");
        $check_stmt->bind_param('ii', $user_id, $class_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $_SESSION['error_message'] = "This student is already booked for this class.";
        } else {
            $stmt = $conn->prepare("INSERT INTO bookings (user_id, class_id, status) VALUES (?, ?, ?)");
            $stmt->bind_param('iis', $user_id, $class_id, $status);
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Booking created successfully!";
                
                // Update class available slots if confirmed
                if ($status === 'confirmed') {
                    $update_stmt = $conn->prepare("
                        UPDATE classes 
                        SET slots_available = GREATEST(0, slots_available - 1) 
                        WHERE id = ? AND slots_available > 0
                    ");
                    $update_stmt->bind_param('i', $class_id);
                    $update_stmt->execute();
                }
            } else {
                $_SESSION['error_message'] = "Failed to create booking: " . $conn->error;
            }
        }
        header("Location: bookings.php");
        exit();
    }
    
    // Bulk status update
    if (isset($_POST['bulk_update'])) {
        $status = $_POST['bulk_status'];
        $booking_ids = $_POST['booking_ids'] ?? [];
        
        if (!empty($booking_ids)) {
            $placeholders = implode(',', array_fill(0, count($booking_ids), '?'));
            $types = str_repeat('i', count($booking_ids));
            
            $stmt = $conn->prepare("UPDATE bookings SET status = ? WHERE id IN ($placeholders)");
            $stmt->bind_param($types . 's', ...array_merge($booking_ids, [$status]));
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Bulk update completed successfully!";
            } else {
                $_SESSION['error_message'] = "Failed to update bookings.";
            }
        }
        header("Location: bookings.php");
        exit();
    }
}

// Get session messages
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Handle filtering and searching
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$class_filter = $_GET['class_filter'] ?? 'all';
$instructor_filter = $_GET['instructor_filter'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(u.name LIKE ? OR u.email LIKE ? OR c.title LIKE ? OR i.name LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $types .= 'ssss';
}

if ($status_filter !== 'all') {
    $where_conditions[] = "b.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($class_filter !== 'all') {
    $where_conditions[] = "c.id = ?";
    $params[] = $class_filter;
    $types .= 'i';
}

if ($instructor_filter !== 'all') {
    $where_conditions[] = "c.instructor_id = ?";
    $params[] = $instructor_filter;
    $types .= 'i';
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(b.created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(b.created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get bookings with pagination - REMOVED location column
$sql = "
    SELECT SQL_CALC_FOUND_ROWS b.*, 
           u.name AS student_name, 
           u.email AS student_email,
           u.phone AS student_phone,
           c.title AS class_title,
           c.start_time AS class_start,
           c.end_time AS class_end,
           c.age_group AS class_age_group,
           c.price AS class_price,
           i.name AS instructor_name,
           i.email AS instructor_email,
           (SELECT COUNT(*) FROM payments p WHERE p.booking_id = b.id AND p.status = 'paid') as payment_count,
           (SELECT SUM(amount) FROM payments p WHERE p.booking_id = b.id AND p.status = 'paid') as paid_amount
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    JOIN classes c ON b.class_id = c.id
    LEFT JOIN instructors i ON c.instructor_id = i.id
    $where_sql
    ORDER BY b.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get total count for pagination
$total_result = $conn->query("SELECT FOUND_ROWS() as total");
$total_bookings = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_bookings / $limit);

// Get students for the add booking form
$students = $conn->query("SELECT id, name, email FROM users WHERE role = 'student' ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// Get classes for the add booking form and filter
$classes = $conn->query("SELECT id, title, start_time, slots_available, price FROM classes WHERE start_time >= NOW() ORDER BY start_time ASC")->fetch_all(MYSQLI_ASSOC);

// Get instructors for filter
$instructors = $conn->query("SELECT id, name FROM instructors ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// Get booking statistics
$total_bookings_all = $conn->query("SELECT COUNT(*) AS total FROM bookings")->fetch_assoc()['total'];
$confirmed_bookings = $conn->query("SELECT COUNT(*) AS total FROM bookings WHERE status = 'confirmed'")->fetch_assoc()['total'];
$pending_bookings = $conn->query("SELECT COUNT(*) AS total FROM bookings WHERE status = 'pending'")->fetch_assoc()['total'];
$cancelled_bookings = $conn->query("SELECT COUNT(*) AS total FROM bookings WHERE status = 'cancelled'")->fetch_assoc()['total'];
$completed_bookings = $conn->query("SELECT COUNT(*) AS total FROM bookings WHERE status = 'completed'")->fetch_assoc()['total'];

// Today's bookings
$today = date('Y-m-d');
$today_bookings = $conn->query("SELECT COUNT(*) as total FROM bookings WHERE DATE(created_at) = '$today'")->fetch_assoc()['total'];

// Monthly revenue from bookings
$monthly_revenue = $conn->query("
    SELECT COALESCE(SUM(p.amount), 0) as total 
    FROM payments p 
    JOIN bookings b ON p.booking_id = b.id 
    WHERE p.status = 'paid' 
    AND MONTH(p.payment_date) = MONTH(CURRENT_DATE()) 
    AND YEAR(p.payment_date) = YEAR(CURRENT_DATE())
")->fetch_assoc()['total'];

// Upcoming bookings (next 7 days)
$next_week = date('Y-m-d', strtotime('+7 days'));
$upcoming_bookings = $conn->query("
    SELECT COUNT(*) as total 
    FROM bookings b 
    JOIN classes c ON b.class_id = c.id 
    WHERE c.start_time BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY) 
    AND b.status = 'confirmed'
")->fetch_assoc()['total'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Bookings | AquaFlow Admin</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="../css/style.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --success-color: #4cc9f0;
            --info-color: #4895ef;
            --warning-color: #f72585;
            --danger-color: #7209b7;
            --border-radius: 12px;
            --box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease;
        }
        
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: #f8fafc;
            color: #334155;
        }
        
        .dashboard-wrapper {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            color: white;
            position: fixed;
            height: 100vh;
            z-index: 1000;
            transition: var(--transition);
            box-shadow: 2px 0 20px rgba(0, 0, 0, 0.1);
        }
        
        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-brand {
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .sidebar-brand i {
            color: #60a5fa;
        }
        
        .sidebar-nav {
            padding: 1rem 0;
        }
        
        .nav-item {
            margin-bottom: 0.25rem;
        }
        
        .nav-link {
            color: #cbd5e1;
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: var(--transition);
            border-left: 3px solid transparent;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255, 255, 255, 0.05);
            color: white;
            border-left-color: #60a5fa;
        }
        
        .nav-link i {
            width: 20px;
            text-align: center;
        }
        
        .main-content {
            flex: 1;
            margin-left: 260px;
            transition: var(--transition);
        }
        
        .topbar {
            background: white;
            padding: 1rem 2rem;
            border-bottom: 1px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            justify-content: between;
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
            transition: var(--transition);
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
        
        .dashboard-content {
            padding: 2rem;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            border: 1px solid #f1f5f9;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: white;
        }
        
        .stat-card.total .stat-icon { background: linear-gradient(135deg, #4361ee, #3a0ca3); }
        .stat-card.confirmed .stat-icon { background: linear-gradient(135deg, #4cc9f0, #4895ef); }
        .stat-card.pending .stat-icon { background: linear-gradient(135deg, #f72585, #7209b7); }
        .stat-card.revenue .stat-icon { background: linear-gradient(135deg, #06d6a0, #118ab2); }
        
        .stat-content h3 {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0;
            color: #1e293b;
        }
        
        .stat-content p {
            color: #64748b;
            font-size: 0.875rem;
            margin: 0.25rem 0 0 0;
        }
        
        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--box-shadow);
            border: 1px solid #f1f5f9;
        }
        
        /* Table */
        .data-card {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
            border: 1px solid #f1f5f9;
        }
        
        .data-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .data-header h4 {
            font-size: 1.125rem;
            font-weight: 600;
            margin: 0;
            color: #1e293b;
        }
        
        .table-container {
            padding: 1.5rem;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            font-weight: 600;
            color: #475569;
            background-color: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            padding: 1rem 0.75rem;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .table td {
            padding: 1rem 0.75rem;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.875rem;
        }
        
        .table tr:hover {
            background-color: #f8fafc;
        }
        
        /* Badges */
        .badge {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.75rem;
        }
        
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #e0f2fe; color: #0c4a6e; }
        .badge-primary { background: #e0e7ff; color: #3730a3; }
        .badge-secondary { background: #f1f5f9; color: #475569; }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }
        
        /* Pagination */
        .pagination {
            margin-bottom: 0;
        }
        
        .page-link {
            border: none;
            color: #64748b;
            padding: 0.5rem 0.75rem;
        }
        
        .page-link:hover {
            background-color: #f1f5f9;
            color: #334155;
        }
        
        .page-item.active .page-link {
            background-color: #4361ee;
            border-color: #4361ee;
        }
        
        /* Alert */
        .alert {
            border-radius: 8px;
            border: none;
            box-shadow: var(--box-shadow);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }
        
        .empty-state i {
            font-size: 3rem;
            color: #94a3b8;
            margin-bottom: 1rem;
        }
        
        .empty-state h5 {
            color: #475569;
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            color: #64748b;
            margin-bottom: 1.5rem;
        }
        
        /* Modal */
        .modal-content {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }
        
        .modal-header {
            border-bottom: 1px solid #e2e8f0;
            padding: 1.5rem;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-footer {
            border-top: 1px solid #e2e8f0;
            padding: 1rem 1.5rem;
        }
        
        /* Bulk Actions */
        .bulk-actions {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid #e2e8f0;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
            }
            
            .sidebar .nav-text {
                display: none;
            }
            
            .sidebar-brand span {
                display: none;
            }
            
            .main-content {
                margin-left: 70px;
            }
            
            .dashboard-content {
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
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
                    <h1>Manage Bookings</h1>
                    <p>View and manage all class bookings and enrollments</p>
                </div>
                
                <div class="topbar-actions">
                    <div class="dropdown">
                        <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-plus-circle me-1"></i> Quick Action
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addBookingModal">
                                <i class="bi bi-calendar-plus me-2"></i> Add New Booking
                            </a></li>
                            <li><a class="dropdown-item" href="bookings.php?export=csv">
                                <i class="bi bi-download me-2"></i> Export Bookings
                            </a></li>
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

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Alert Messages -->
                <?php if(isset($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                        <i class="bi bi-check-circle me-2"></i><?= $success_message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if(isset($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                        <i class="bi bi-exclamation-circle me-2"></i><?= $error_message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Stats Overview -->
                <div class="stats-grid">
                    <div class="stat-card total">
                        <div class="stat-header">
                            <div class="stat-content">
                                <h3><?= $total_bookings_all ?></h3>
                                <p>Total Bookings</p>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-journal-check"></i>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card confirmed">
                        <div class="stat-header">
                            <div class="stat-content">
                                <h3><?= $confirmed_bookings ?></h3>
                                <p>Confirmed</p>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-check-circle"></i>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card pending">
                        <div class="stat-header">
                            <div class="stat-content">
                                <h3><?= $pending_bookings ?></h3>
                                <p>Pending</p>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-clock-history"></i>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card revenue">
                        <div class="stat-header">
                            <div class="stat-content">
                                <h3>$<?= number_format($monthly_revenue, 2) ?></h3>
                                <p>Monthly Revenue</p>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-currency-dollar"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-section">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Search Bookings</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search students, classes, or instructors...">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-medium">Status</label>
                            <select class="form-select" name="status">
                                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="confirmed" <?= $status_filter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-medium">Class</label>
                            <select class="form-select" name="class_filter">
                                <option value="all" <?= $class_filter === 'all' ? 'selected' : '' ?>>All Classes</option>
                                <?php foreach($classes as $class): ?>
                                    <option value="<?= $class['id'] ?>" <?= $class_filter == $class['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($class['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-medium">Instructor</label>
                            <select class="form-select" name="instructor_filter">
                                <option value="all" <?= $instructor_filter === 'all' ? 'selected' : '' ?>>All Instructors</option>
                                <?php foreach($instructors as $instructor): ?>
                                    <option value="<?= $instructor['id'] ?>" <?= $instructor_filter == $instructor['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($instructor['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="d-flex gap-2 w-100">
                                <button type="submit" class="btn btn-primary flex-grow-1">
                                    <i class="bi bi-filter me-2"></i>Filter
                                </button>
                                <a href="bookings.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </a>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">From Date</label>
                            <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">To Date</label>
                            <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                        </div>
                    </form>
                </div>

                <!-- Bookings Table -->
                <div class="data-card">
                    <div class="data-header">
                        <h4>Bookings Directory</h4>
                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addBookingModal">
                            <i class="bi bi-plus-circle me-1"></i>Add Booking
                        </button>
                    </div>
                    
                    <div class="table-container">
                        <?php if(empty($bookings)): ?>
                            <div class="empty-state">
                                <i class="bi bi-calendar-x"></i>
                                <h5>No Bookings Found</h5>
                                <p>No bookings match your search criteria. Try adjusting your filters or add new bookings.</p>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBookingModal">
                                    <i class="bi bi-plus-circle me-2"></i>Add New Booking
                                </button>
                            </div>
                        <?php else: ?>
                            <!-- Bulk Actions -->
                            <div class="bulk-actions">
                                <form method="POST" class="row g-3 align-items-center" id="bulkForm">
                                    <div class="col-auto">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="selectAll">
                                            <label class="form-check-label fw-medium" for="selectAll">
                                                Select All
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <select class="form-select form-select-sm" name="bulk_status" style="width: 150px;">
                                            <option value="">Bulk Action</option>
                                            <option value="confirmed">Mark as Confirmed</option>
                                            <option value="pending">Mark as Pending</option>
                                            <option value="cancelled">Mark as Cancelled</option>
                                            <option value="completed">Mark as Completed</option>
                                        </select>
                                    </div>
                                    <div class="col-auto">
                                        <button type="submit" name="bulk_update" class="btn btn-primary btn-sm">
                                            Apply
                                        </button>
                                    </div>
                                </form>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th width="30"></th>
                                            <th>Booking Details</th>
                                            <th>Class Information</th>
                                            <th>Schedule</th>
                                            <th>Payment</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($bookings as $booking): 
                                            // Determine status badge color
                                            $status_colors = [
                                                'pending' => 'warning',
                                                'confirmed' => 'success',
                                                'cancelled' => 'danger',
                                                'completed' => 'secondary'
                                            ];
                                            $status_color = $status_colors[$booking['status']] ?? 'secondary';
                                            
                                            // Check if class is upcoming
                                            $class_start = new DateTime($booking['class_start']);
                                            $now = new DateTime();
                                            $is_upcoming = $class_start > $now;
                                        ?>
                                            <tr>
                                                <td>
                                                    <input class="form-check-input booking-checkbox" type="checkbox" name="booking_ids[]" value="<?= $booking['id'] ?>">
                                                </td>
                                                <td>
                                                    <div class="fw-medium"><?= htmlspecialchars($booking['student_name']) ?></div>
                                                    <div class="text-muted">
                                                        <small><?= htmlspecialchars($booking['student_email']) ?></small>
                                                    </div>
                                                    <div class="mt-1">
                                                        <small class="text-muted">
                                                            <i class="bi bi-calendar3 me-1"></i>
                                                            <?= date("M j, Y", strtotime($booking['created_at'])) ?>
                                                        </small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="fw-medium"><?= htmlspecialchars($booking['class_title']) ?></div>
                                                    <div class="text-muted">
                                                        <small>
                                                            <i class="bi bi-person me-1"></i>
                                                            <?= htmlspecialchars($booking['instructor_name'] ?? 'N/A') ?>
                                                        </small>
                                                    </div>
                                                    <div class="mt-1">
                                                        <span class="badge bg-info"><?= htmlspecialchars($booking['class_age_group']) ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="fw-medium"><?= date("M j, Y", strtotime($booking['class_start'])) ?></div>
                                                    <div class="text-muted">
                                                        <small>
                                                            <?= date("g:i A", strtotime($booking['class_start'])) ?> - 
                                                            <?= date("g:i A", strtotime($booking['class_end'])) ?>
                                                        </small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="fw-medium">$<?= number_format($booking['class_price'] ?? 0, 2) ?></div>
                                                    <?php if($booking['payment_count'] > 0): ?>
                                                        <div class="text-success">
                                                            <small>
                                                                <i class="bi bi-check-circle me-1"></i>
                                                                Paid: $<?= number_format($booking['paid_amount'] ?? 0, 2) ?>
                                                            </small>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="text-warning">
                                                            <small>
                                                                <i class="bi bi-clock me-1"></i>
                                                                Payment Pending
                                                            </small>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                        <select name="status" class="form-select form-select-sm booking-status" onchange="this.form.submit()" style="width: 120px;">
                                                            <option value="pending" <?= $booking['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                                            <option value="confirmed" <?= $booking['status'] == 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                                            <option value="cancelled" <?= $booking['status'] == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                                            <option value="completed" <?= $booking['status'] == 'completed' ? 'selected' : '' ?>>Completed</option>
                                                        </select>
                                                        <input type="hidden" name="update_booking_status" value="1">
                                                    </form>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button class="btn btn-outline-info btn-sm"
                                                                data-bs-toggle="modal" data-bs-target="#viewBookingModal"
                                                                onclick="viewBooking(<?= htmlspecialchars(json_encode($booking)) ?>)">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this booking? This action cannot be undone.');">
                                                            <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                            <button type="submit" name="delete_booking" class="btn btn-outline-danger btn-sm">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if($total_pages > 1): ?>
                                <nav aria-label="Page navigation" class="mt-4">
                                    <ul class="pagination justify-content-center">
                                        <?php if($page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                                    <i class="bi bi-chevron-left"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                        
                                        <?php for($i = 1; $i <= $total_pages; $i++): ?>
                                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                                    <?= $i ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <?php if($page < $total_pages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                                    <i class="bi bi-chevron-right"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Booking Modal -->
    <div class="modal fade" id="addBookingModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Booking</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Student *</label>
                                <select class="form-select" name="user_id" required id="studentSelect">
                                    <option value="">Select Student</option>
                                    <?php foreach($students as $student): ?>
                                        <option value="<?= $student['id'] ?>">
                                            <?= htmlspecialchars($student['name']) ?> (<?= htmlspecialchars($student['email']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Class *</label>
                                <select class="form-select" name="class_id" required id="classSelect" onchange="updateClassInfo()">
                                    <option value="">Select Class</option>
                                    <?php foreach($classes as $class): ?>
                                        <option value="<?= $class['id'] ?>" data-slots="<?= $class['slots_available'] ?>" data-price="<?= $class['price'] ?? 0 ?>" data-schedule="<?= date('M j, Y g:i A', strtotime($class['start_time'])) ?>">
                                            <?= htmlspecialchars($class['title']) ?> 
                                            (<?= date('M j, Y g:i A', strtotime($class['start_time'])) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" id="classInfo">Select a class to see details</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status *</label>
                                <select class="form-select" name="status" required>
                                    <option value="pending">Pending</option>
                                    <option value="confirmed" selected>Confirmed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Booking Date</label>
                                <input type="date" class="form-control" value="<?= date('Y-m-d') ?>" readonly>
                                <small class="form-text text-muted">Current date will be used</small>
                            </div>
                        </div>
                        <div class="mt-4 p-3 bg-light rounded" id="classDetails" style="display: none;">
                            <h6 class="fw-medium mb-2">Class Details:</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <p class="mb-1"><strong>Available Slots:</strong> <span id="slotsInfo">0</span></p>
                                    <p class="mb-1"><strong>Price:</strong> $<span id="priceInfo">0.00</span></p>
                                </div>
                                <div class="col-md-6">
                                    <p class="mb-1"><strong>Schedule:</strong> <span id="scheduleInfo">N/A</span></p>
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

    <!-- View Booking Modal -->
    <div class="modal fade" id="viewBookingModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Booking Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Student Information</label>
                            <div class="card bg-light">
                                <div class="card-body">
                                    <p class="mb-1"><strong>Name:</strong> <span id="view_student_name"></span></p>
                                    <p class="mb-1"><strong>Email:</strong> <span id="view_student_email"></span></p>
                                    <p class="mb-0"><strong>Phone:</strong> <span id="view_student_phone"></span></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Class Information</label>
                            <div class="card bg-light">
                                <div class="card-body">
                                    <p class="mb-1"><strong>Class:</strong> <span id="view_class_title"></span></p>
                                    <p class="mb-1"><strong>Instructor:</strong> <span id="view_instructor_name"></span></p>
                                    <p class="mb-0"><strong>Age Group:</strong> <span id="view_age_group"></span></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Schedule Details</label>
                            <div class="card bg-light">
                                <div class="card-body">
                                    <p class="mb-1"><strong>Start Time:</strong> <span id="view_start_time"></span></p>
                                    <p class="mb-1"><strong>End Time:</strong> <span id="view_end_time"></span></p>
                                    <p class="mb-0"><strong>Duration:</strong> <span id="view_duration"></span></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Booking Information</label>
                            <div class="card bg-light">
                                <div class="card-body">
                                    <p class="mb-1"><strong>Status:</strong> <span id="view_status"></span></p>
                                    <p class="mb-1"><strong>Booking Date:</strong> <span id="view_booking_date"></span></p>
                                    <p class="mb-0"><strong>Booking ID:</strong> <span id="view_booking_id"></span></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Payment Information</label>
                            <div class="card bg-light">
                                <div class="card-body">
                                    <p class="mb-1"><strong>Class Price:</strong> <span id="view_class_price"></span></p>
                                    <p class="mb-1"><strong>Paid Amount:</strong> <span id="view_paid_amount"></span></p>
                                    <p class="mb-0"><strong>Payment Status:</strong> <span id="view_payment_status"></span></p>
                                </div>
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
    <script src="../js/main.js"></script>
    <script>
        // Bulk selection functionality
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.booking-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });

        // Class information update for add booking modal
        function updateClassInfo() {
            const classSelect = document.getElementById('classSelect');
            const selectedOption = classSelect.options[classSelect.selectedIndex];
            const classDetails = document.getElementById('classDetails');
            
            if (selectedOption.value) {
                const slots = selectedOption.getAttribute('data-slots') || '0';
                const price = selectedOption.getAttribute('data-price') || '0';
                const schedule = selectedOption.getAttribute('data-schedule') || 'N/A';
                
                document.getElementById('slotsInfo').textContent = slots;
                document.getElementById('priceInfo').textContent = parseFloat(price).toFixed(2);
                document.getElementById('scheduleInfo').textContent = schedule;
                
                // Update slots info text
                const slotsText = slots === '0' ? 'No slots available' : `${slots} slots available`;
                document.getElementById('classInfo').textContent = slotsText;
                document.getElementById('classInfo').className = slots === '0' ? 'form-text text-danger' : 'form-text text-success';
                
                classDetails.style.display = 'block';
            } else {
                document.getElementById('classInfo').textContent = 'Select a class to see details';
                document.getElementById('classInfo').className = 'form-text text-muted';
                classDetails.style.display = 'none';
            }
        }

        // View booking function
        function viewBooking(booking) {
            // Student info
            document.getElementById('view_student_name').textContent = booking.student_name;
            document.getElementById('view_student_email').textContent = booking.student_email;
            document.getElementById('view_student_phone').textContent = booking.student_phone || 'Not provided';
            
            // Class info
            document.getElementById('view_class_title').textContent = booking.class_title;
            document.getElementById('view_instructor_name').textContent = booking.instructor_name || 'N/A';
            document.getElementById('view_age_group').textContent = booking.class_age_group || 'N/A';
            
            // Schedule info
            const startTime = new Date(booking.class_start);
            const endTime = new Date(booking.class_end);
            document.getElementById('view_start_time').textContent = startTime.toLocaleString();
            document.getElementById('view_end_time').textContent = endTime.toLocaleString();
            
            // Calculate duration
            const durationMs = endTime - startTime;
            const durationHours = Math.floor(durationMs / (1000 * 60 * 60));
            const durationMinutes = Math.floor((durationMs % (1000 * 60 * 60)) / (1000 * 60));
            document.getElementById('view_duration').textContent = `${durationHours}h ${durationMinutes}m`;
            
            // Booking info
            const bookingDate = new Date(booking.created_at);
            document.getElementById('view_booking_id').textContent = booking.id;
            document.getElementById('view_booking_date').textContent = bookingDate.toLocaleString();
            
            // Status with badge
            const statusColors = {
                'pending': 'warning',
                'confirmed': 'success',
                'cancelled': 'danger',
                'completed': 'secondary'
            };
            const statusColor = statusColors[booking.status] || 'secondary';
            const statusText = booking.status.charAt(0).toUpperCase() + booking.status.slice(1);
            document.getElementById('view_status').innerHTML = `<span class="badge bg-${statusColor}">${statusText}</span>`;
            
            // Payment info
            document.getElementById('view_class_price').textContent = '$' + (parseFloat(booking.class_price) || 0).toFixed(2);
            document.getElementById('view_paid_amount').textContent = booking.paid_amount ? '$' + parseFloat(booking.paid_amount).toFixed(2) : '$0.00';
            
            if (booking.payment_count > 0) {
                document.getElementById('view_payment_status').innerHTML = '<span class="badge bg-success">Paid</span>';
            } else {
                document.getElementById('view_payment_status').innerHTML = '<span class="badge bg-warning">Pending</span>';
            }
        }

        // Quick search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="search"]');
            const searchForm = searchInput.closest('form');
            
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    searchForm.submit();
                }
            });
            
            // Initialize class info if modal opens with pre-selected value
            document.getElementById('addBookingModal').addEventListener('show.bs.modal', function() {
                updateClassInfo();
            });
        });
    </script>
</body>
</html>