<?php
// admin/classes.php
session_start();
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

requireRole('admin');
$user = getCurrentUser($conn);

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_class'])) {
        $class_id = intval($_POST['class_id']);
        $stmt = $conn->prepare("DELETE FROM classes WHERE id = ?");
        $stmt->bind_param('i', $class_id);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Class deleted successfully!";
        } else {
            $_SESSION['error_message'] = "Failed to delete class.";
        }
        header("Location: classes.php");
        exit();
    }
    
    if (isset($_POST['add_class'])) {
        $title = trim($_POST['title']);
        $age_group = trim($_POST['age_group']);
        $description = trim($_POST['description']);
        $instructor_id = intval($_POST['instructor_id']);
        $slots_total = intval($_POST['slots_total']);
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $price = floatval($_POST['price']);
        $location = trim($_POST['location'] ?? '');
        
        $stmt = $conn->prepare("INSERT INTO classes (title, age_group, description, instructor_id, slots_total, slots_available, start_time, end_time, price, location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sssiisssds', $title, $age_group, $description, $instructor_id, $slots_total, $slots_total, $start_time, $end_time, $price, $location);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Class created successfully!";
        } else {
            $_SESSION['error_message'] = "Failed to create class: " . $conn->error;
        }
        header("Location: classes.php");
        exit();
    }
    
    if (isset($_POST['update_class'])) {
        $class_id = intval($_POST['class_id']);
        $title = trim($_POST['title']);
        $age_group = trim($_POST['age_group']);
        $description = trim($_POST['description']);
        $instructor_id = intval($_POST['instructor_id']);
        $slots_total = intval($_POST['slots_total']);
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $price = floatval($_POST['price']);
        $location = trim($_POST['location'] ?? '');
        
        $stmt = $conn->prepare("UPDATE classes SET title = ?, age_group = ?, description = ?, instructor_id = ?, slots_total = ?, start_time = ?, end_time = ?, price = ?, location = ? WHERE id = ?");
        $stmt->bind_param('sssiissdsi', $title, $age_group, $description, $instructor_id, $slots_total, $start_time, $end_time, $price, $location, $class_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Class updated successfully!";
        } else {
            $_SESSION['error_message'] = "Failed to update class: " . $conn->error;
        }
        header("Location: classes.php");
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
$instructor_filter = $_GET['instructor'] ?? 'all';
$age_group_filter = $_GET['age_group'] ?? 'all';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(c.title LIKE ? OR c.description LIKE ? OR c.location LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
    $types .= 'sss';
}

if ($status_filter !== 'all') {
    $current_time = date('Y-m-d H:i:s');
    if ($status_filter === 'upcoming') {
        $where_conditions[] = "c.start_time > ?";
        $params[] = $current_time;
        $types .= 's';
    } elseif ($status_filter === 'ongoing') {
        $where_conditions[] = "c.start_time <= ? AND c.end_time >= ?";
        $params = array_merge($params, [$current_time, $current_time]);
        $types .= 'ss';
    } elseif ($status_filter === 'completed') {
        $where_conditions[] = "c.end_time < ?";
        $params[] = $current_time;
        $types .= 's';
    }
}

if ($instructor_filter !== 'all') {
    $where_conditions[] = "c.instructor_id = ?";
    $params[] = $instructor_filter;
    $types .= 'i';
}

if ($age_group_filter !== 'all') {
    $where_conditions[] = "c.age_group = ?";
    $params[] = $age_group_filter;
    $types .= 's';
}

$where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get classes with pagination
$sql = "
    SELECT SQL_CALC_FOUND_ROWS c.*, 
           i.name AS instructor_name,
           i.email AS instructor_email,
           (SELECT COUNT(*) FROM bookings WHERE class_id = c.id AND status = 'confirmed') as enrolled_count
    FROM classes c 
    LEFT JOIN instructors i ON c.instructor_id = i.id 
    $where_sql
    ORDER BY c.start_time DESC
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
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get total count for pagination
$total_result = $conn->query("SELECT FOUND_ROWS() as total");
$total_classes = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_classes / $limit);

// Get instructors for filters and forms
$instructors = $conn->query("SELECT id, name FROM instructors ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// Get unique age groups
$age_groups = $conn->query("SELECT DISTINCT age_group FROM classes WHERE age_group IS NOT NULL ORDER BY age_group ASC")->fetch_all(MYSQLI_ASSOC);

// Get class statistics
$total_classes_all = $conn->query("SELECT COUNT(*) AS total FROM classes")->fetch_assoc()['total'];
$upcoming_classes = $conn->query("SELECT COUNT(*) AS total FROM classes WHERE start_time > NOW()")->fetch_assoc()['total'];
$ongoing_classes = $conn->query("SELECT COUNT(*) AS total FROM classes WHERE start_time <= NOW() AND end_time >= NOW()")->fetch_assoc()['total'];
$completed_classes = $conn->query("SELECT COUNT(*) AS total FROM classes WHERE end_time < NOW()")->fetch_assoc()['total'];
$total_capacity = $conn->query("SELECT SUM(slots_total) as total FROM classes")->fetch_assoc()['total'];
$filled_capacity = $conn->query("SELECT SUM(slots_total - slots_available) as total FROM classes")->fetch_assoc()['total'];
$utilization_rate = $total_capacity > 0 ? round(($filled_capacity / $total_capacity) * 100, 1) : 0;

// Get upcoming classes for the week
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));
$weekly_classes = $conn->query("
    SELECT COUNT(*) as total 
    FROM classes 
    WHERE DATE(start_time) BETWEEN '$week_start' AND '$week_end'
")->fetch_assoc()['total'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Classes | AquaFlow Admin</title>
    
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
        .stat-card.upcoming .stat-icon { background: linear-gradient(135deg, #4cc9f0, #4895ef); }
        .stat-card.ongoing .stat-icon { background: linear-gradient(135deg, #f72585, #7209b7); }
        .stat-card.utilization .stat-icon { background: linear-gradient(135deg, #06d6a0, #118ab2); }
        
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
        
        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            margin-top: 0.5rem;
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
        
        /* Progress Bars */
        .progress {
            height: 6px;
            background-color: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .progress-bar {
            border-radius: 3px;
        }
        
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
                    <a href="classes.php" class="nav-link active">
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
                    <h1>Manage Classes</h1>
                    <p>View and manage all swimming classes</p>
                </div>
                
                <div class="topbar-actions">
                    <div class="dropdown">
                        <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-plus-circle me-1"></i> Quick Action
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addClassModal">
                                <i class="bi bi-calendar-plus me-2"></i> Add New Class
                            </a></li>
                            <li><a class="dropdown-item" href="#">
                                <i class="bi bi-calendar-range me-2"></i> Schedule Multiple
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
                                <h3><?= $total_classes_all ?></h3>
                                <p>Total Classes</p>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-calendar-week"></i>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card upcoming">
                        <div class="stat-header">
                            <div class="stat-content">
                                <h3><?= $upcoming_classes ?></h3>
                                <p>Upcoming Classes</p>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-calendar-check"></i>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card ongoing">
                        <div class="stat-header">
                            <div class="stat-content">
                                <h3><?= $ongoing_classes ?></h3>
                                <p>Ongoing Classes</p>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-clock-history"></i>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card utilization">
                        <div class="stat-header">
                            <div class="stat-content">
                                <h3><?= $utilization_rate ?>%</h3>
                                <p>Capacity Utilization</p>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-activity"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-section">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Search Classes</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search classes...">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-medium">Status</label>
                            <select class="form-select" name="status">
                                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                                <option value="upcoming" <?= $status_filter === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                                <option value="ongoing" <?= $status_filter === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                                <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-medium">Instructor</label>
                            <select class="form-select" name="instructor">
                                <option value="all" <?= $instructor_filter === 'all' ? 'selected' : '' ?>>All Instructors</option>
                                <?php foreach($instructors as $instructor): ?>
                                    <option value="<?= $instructor['id'] ?>" <?= $instructor_filter == $instructor['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($instructor['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-medium">Age Group</label>
                            <select class="form-select" name="age_group">
                                <option value="all" <?= $age_group_filter === 'all' ? 'selected' : '' ?>>All Age Groups</option>
                                <?php foreach($age_groups as $group): ?>
                                    <option value="<?= htmlspecialchars($group['age_group']) ?>" <?= $age_group_filter == $group['age_group'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($group['age_group']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="d-flex gap-2 w-100">
                                <button type="submit" class="btn btn-primary flex-grow-1">
                                    <i class="bi bi-filter me-2"></i>Filter
                                </button>
                                <a href="classes.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Classes Table -->
                <div class="data-card">
                    <div class="data-header">
                        <h4>Class Schedule</h4>
                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addClassModal">
                            <i class="bi bi-plus-circle me-1"></i>Add Class
                        </button>
                    </div>
                    
                    <div class="table-container">
                        <?php if(empty($classes)): ?>
                            <div class="empty-state">
                                <i class="bi bi-calendar-x"></i>
                                <h5>No Classes Found</h5>
                                <p>No classes match your search criteria. Try adjusting your filters or add new classes.</p>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClassModal">
                                    <i class="bi bi-plus-circle me-2"></i>Add New Class
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Class Details</th>
                                            <th>Schedule</th>
                                            <th>Instructor</th>
                                            <th>Capacity</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($classes as $class): 
                                            // Calculate class status
                                            $current_time = new DateTime();
                                            $start_time = new DateTime($class['start_time']);
                                            $end_time = new DateTime($class['end_time']);
                                            
                                            if ($current_time > $end_time) {
                                                $status_class = 'secondary';
                                                $status_text = 'Completed';
                                            } elseif ($current_time >= $start_time && $current_time <= $end_time) {
                                                $status_class = 'success';
                                                $status_text = 'In Progress';
                                            } else {
                                                $status_class = 'primary';
                                                $status_text = 'Upcoming';
                                            }
                                            
                                            // Calculate capacity percentage
                                            $capacity_percentage = $class['slots_total'] > 0 ? 
                                                (($class['slots_total'] - $class['slots_available']) / $class['slots_total']) * 100 : 0;
                                            $capacity_color = $capacity_percentage >= 90 ? 'danger' : 
                                                            ($capacity_percentage >= 75 ? 'warning' : 'success');
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-medium"><?= htmlspecialchars($class['title']) ?></div>
                                                    <div class="d-flex align-items-center gap-2 mt-1">
                                                        <span class="badge bg-info"><?= htmlspecialchars($class['age_group']) ?></span>
                                                        <?php if($class['location']): ?>
                                                            <small class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($class['location']) ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="fw-medium"><?= date("M j, Y", strtotime($class['start_time'])) ?></div>
                                                    <div class="text-muted">
                                                        <?= date("g:i A", strtotime($class['start_time'])) ?> - <?= date("g:i A", strtotime($class['end_time'])) ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if($class['instructor_name']): ?>
                                                        <div class="fw-medium"><?= htmlspecialchars($class['instructor_name']) ?></div>
                                                        <small class="text-muted"><?= htmlspecialchars($class['instructor_email'] ?? '') ?></small>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Unassigned</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="fw-medium mb-1">
                                                        <?= ($class['slots_total'] - $class['slots_available']) ?>/<?= $class['slots_total'] ?>
                                                        <small class="text-muted">(<?= round($capacity_percentage) ?>%)</small>
                                                    </div>
                                                    <div class="progress" style="height: 6px; width: 100px;">
                                                        <div class="progress-bar bg-<?= $capacity_color ?>" style="width: <?= $capacity_percentage ?>%"></div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= $status_class ?>"><?= $status_text ?></span>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button class="btn btn-outline-primary btn-sm" 
                                                                data-bs-toggle="modal" data-bs-target="#editClassModal"
                                                                onclick="editClass(<?= htmlspecialchars(json_encode($class)) ?>)">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button class="btn btn-outline-info btn-sm"
                                                                data-bs-toggle="modal" data-bs-target="#viewClassModal"
                                                                onclick="viewClass(<?= htmlspecialchars(json_encode($class)) ?>)">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this class? This action cannot be undone.');">
                                                            <input type="hidden" name="class_id" value="<?= $class['id'] ?>">
                                                            <button type="submit" name="delete_class" class="btn btn-outline-danger btn-sm">
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

    <!-- Add Class Modal -->
    <div class="modal fade" id="addClassModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Class</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Class Title *</label>
                                <input type="text" class="form-control" name="title" required placeholder="e.g., Beginner Swimming Class">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Age Group *</label>
                                <select class="form-select" name="age_group" required>
                                    <option value="">Select Age Group</option>
                                    <option value="Kids (4-7)">Kids (4-7)</option>
                                    <option value="Children (8-12)">Children (8-12)</option>
                                    <option value="Teens (13-17)">Teens (13-17)</option>
                                    <option value="Adults (18+)">Adults (18+)</option>
                                    <option value="Seniors (55+)">Seniors (55+)</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="3" placeholder="Brief description of the class..."></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Instructor *</label>
                                <select class="form-select" name="instructor_id" required>
                                    <option value="">Select Instructor</option>
                                    <?php foreach($instructors as $instructor): ?>
                                        <option value="<?= $instructor['id'] ?>"><?= htmlspecialchars($instructor['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Total Slots *</label>
                                <input type="number" class="form-control" name="slots_total" min="1" max="50" value="15" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Start Time *</label>
                                <input type="datetime-local" class="form-control" name="start_time" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">End Time *</label>
                                <input type="datetime-local" class="form-control" name="end_time" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Price *</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" class="form-control" name="price" step="0.01" min="0" value="50.00" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Location</label>
                                <input type="text" class="form-control" name="location" placeholder="e.g., Main Pool Area">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_class" class="btn btn-primary">Create Class</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Class Modal -->
    <div class="modal fade" id="editClassModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Class</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="class_id" id="edit_class_id">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Class Title *</label>
                                <input type="text" class="form-control" name="title" id="edit_title" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Age Group *</label>
                                <select class="form-select" name="age_group" id="edit_age_group" required>
                                    <option value="">Select Age Group</option>
                                    <option value="Kids (4-7)">Kids (4-7)</option>
                                    <option value="Children (8-12)">Children (8-12)</option>
                                    <option value="Teens (13-17)">Teens (13-17)</option>
                                    <option value="Adults (18+)">Adults (18+)</option>
                                    <option value="Seniors (55+)">Seniors (55+)</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Instructor *</label>
                                <select class="form-select" name="instructor_id" id="edit_instructor_id" required>
                                    <option value="">Select Instructor</option>
                                    <?php foreach($instructors as $instructor): ?>
                                        <option value="<?= $instructor['id'] ?>"><?= htmlspecialchars($instructor['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Total Slots *</label>
                                <input type="number" class="form-control" name="slots_total" id="edit_slots_total" min="1" max="50" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Start Time *</label>
                                <input type="datetime-local" class="form-control" name="start_time" id="edit_start_time" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">End Time *</label>
                                <input type="datetime-local" class="form-control" name="end_time" id="edit_end_time" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Price *</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" class="form-control" name="price" id="edit_price" step="0.01" min="0" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Location</label>
                                <input type="text" class="form-control" name="location" id="edit_location" placeholder="e.g., Main Pool Area">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_class" class="btn btn-primary">Update Class</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Class Modal -->
    <div class="modal fade" id="viewClassModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Class Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Class Title</label>
                            <p class="fs-5 fw-medium" id="view_title"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Age Group</label>
                            <p class="fs-5" id="view_age_group"></p>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium text-muted">Description</label>
                            <div class="card bg-light">
                                <div class="card-body">
                                    <p class="mb-0" id="view_description"></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Instructor</label>
                            <p class="fs-5" id="view_instructor"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Location</label>
                            <p class="fs-5" id="view_location"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Schedule</label>
                            <p class="fs-5">
                                <i class="bi bi-calendar me-1"></i>
                                <span id="view_schedule_date"></span><br>
                                <i class="bi bi-clock me-1"></i>
                                <span id="view_schedule_time"></span>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Capacity</label>
                            <p class="fs-5">
                                <span id="view_capacity"></span> slots<br>
                                <small class="text-muted" id="view_capacity_percentage"></small>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Price</label>
                            <p class="fs-5" id="view_price"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Status</label>
                            <p class="fs-5" id="view_status"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Enrolled Students</label>
                            <p class="fs-5" id="view_enrolled"></p>
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
        // Set default datetime values for new class modal
        document.addEventListener('DOMContentLoaded', function() {
            const now = new Date();
            const tomorrow = new Date(now.getTime() + 24 * 60 * 60 * 1000);
            tomorrow.setHours(10, 0, 0, 0); // Set to 10:00 AM
            
            const endTime = new Date(tomorrow.getTime() + 60 * 60 * 1000); // +1 hour
            
            // Format for datetime-local input
            const formatDateTime = (date) => {
                return date.toISOString().slice(0, 16);
            };
            
            // Set default values when modal opens
            document.getElementById('addClassModal').addEventListener('show.bs.modal', function() {
                const form = this.querySelector('form');
                form.querySelector('input[name="start_time"]').value = formatDateTime(tomorrow);
                form.querySelector('input[name="end_time"]').value = formatDateTime(endTime);
            });
        });

        // Edit class function
        function editClass(cls) {
            document.getElementById('edit_class_id').value = cls.id;
            document.getElementById('edit_title').value = cls.title;
            document.getElementById('edit_age_group').value = cls.age_group;
            document.getElementById('edit_description').value = cls.description || '';
            document.getElementById('edit_instructor_id').value = cls.instructor_id;
            document.getElementById('edit_slots_total').value = cls.slots_total;
            document.getElementById('edit_start_time').value = cls.start_time.slice(0, 16);
            document.getElementById('edit_end_time').value = cls.end_time.slice(0, 16);
            document.getElementById('edit_price').value = cls.price;
            document.getElementById('edit_location').value = cls.location || '';
        }

        // View class function
        function viewClass(cls) {
            document.getElementById('view_title').textContent = cls.title;
            document.getElementById('view_age_group').textContent = cls.age_group;
            document.getElementById('view_description').textContent = cls.description || 'No description available';
            document.getElementById('view_instructor').textContent = cls.instructor_name || 'Unassigned';
            document.getElementById('view_location').textContent = cls.location || 'Not specified';
            
            // Format schedule
            const startDate = new Date(cls.start_time);
            const endDate = new Date(cls.end_time);
            document.getElementById('view_schedule_date').textContent = startDate.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            document.getElementById('view_schedule_time').textContent = 
                startDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) + 
                ' - ' + 
                endDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
            
            // Capacity
            const enrolled = cls.slots_total - cls.slots_available;
            const percentage = Math.round((enrolled / cls.slots_total) * 100);
            document.getElementById('view_capacity').textContent = enrolled + '/' + cls.slots_total;
            document.getElementById('view_capacity_percentage').textContent = percentage + '% filled';
            
            // Price
            document.getElementById('view_price').textContent = '$' + parseFloat(cls.price).toFixed(2);
            
            // Status
            const currentTime = new Date();
            const startTime = new Date(cls.start_time);
            const endTime = new Date(cls.end_time);
            
            let status = '';
            let statusClass = '';
            
            if (currentTime > endTime) {
                status = 'Completed';
                statusClass = 'badge bg-secondary';
            } else if (currentTime >= startTime && currentTime <= endTime) {
                status = 'In Progress';
                statusClass = 'badge bg-success';
            } else {
                status = 'Upcoming';
                statusClass = 'badge bg-primary';
            }
            
            document.getElementById('view_status').innerHTML = `<span class="${statusClass}">${status}</span>`;
            
            // Enrolled students
            document.getElementById('view_enrolled').textContent = cls.enrolled_count || '0';
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
        });
    </script>
</body>
</html>