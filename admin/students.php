<?php
// admin/students.php
session_start();
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

requireRole('admin');
$user = getCurrentUser($conn);

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_student'])) {
        $student_id = intval($_POST['student_id']);
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'student'");
        $stmt->bind_param('i', $student_id);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Student deleted successfully!";
        } else {
            $_SESSION['error_message'] = "Failed to delete student.";
        }
        header("Location: students.php");
        exit();
    }
    
    if (isset($_POST['update_student'])) {
        $student_id = intval($_POST['student_id']);
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $age = intval($_POST['age']);
        $emergency_contact = trim($_POST['emergency_contact']);
        $medical_notes = trim($_POST['medical_notes']);
        $status = $_POST['status'] ?? 'active';
        
        $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, phone = ?, age = ?, emergency_contact = ?, medical_notes = ?, status = ? WHERE id = ? AND role = 'student'");
        $stmt->bind_param('sssisssi', $name, $email, $phone, $age, $emergency_contact, $medical_notes, $status, $student_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Student updated successfully!";
        } else {
            $_SESSION['error_message'] = "Failed to update student.";
        }
        header("Location: students.php");
        exit();
    }
    
    if (isset($_POST['add_student'])) {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $age = intval($_POST['age']);
        $emergency_contact = trim($_POST['emergency_contact']);
        $medical_notes = trim($_POST['medical_notes']);
        $role = 'student';
        $status = 'active';
        
        // Check if email already exists
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND role = 'student'");
        $check_stmt->bind_param('s', $email);
        $check_stmt->execute();
        $check_stmt->store_result();
        
        if ($check_stmt->num_rows > 0) {
            $_SESSION['error_message'] = "A student with this email already exists!";
        } else {
            $stmt = $conn->prepare("INSERT INTO users (name, email, phone, age, emergency_contact, medical_notes, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param('ssisssss', $name, $email, $phone, $age, $emergency_contact, $medical_notes, $role, $status);
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Student added successfully!";
            } else {
                $_SESSION['error_message'] = "Failed to add student.";
            }
        }
        header("Location: students.php");
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
$class_filter = $_GET['class_filter'] ?? 0;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Build WHERE clause
$where_conditions = ["u.role = 'student'"];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
    $types .= 'sss';
}

if ($status_filter !== 'all') {
    $where_conditions[] = "u.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($class_filter > 0) {
    $where_conditions[] = "e.class_id = ?";
    $params[] = $class_filter;
    $types .= 'i';
}

$where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get students with pagination
$sql = "
    SELECT SQL_CALC_FOUND_ROWS u.*, 
           GROUP_CONCAT(DISTINCT c.title SEPARATOR ', ') as enrolled_classes,
           COUNT(DISTINCT e.id) as total_enrollments
    FROM users u
    LEFT JOIN enrollments e ON u.id = e.student_id AND e.status = 'active'
    LEFT JOIN classes c ON e.class_id = c.id
    $where_sql
    GROUP BY u.id
    ORDER BY u.name ASC
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
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get total count for pagination
$total_result = $conn->query("SELECT FOUND_ROWS() as total");
$total_students = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_students / $limit);

// Get all classes for the filter dropdown
$classes = $conn->query("SELECT id, title FROM classes ORDER BY title ASC")->fetch_all(MYSQLI_ASSOC);

// Get student statistics
$total_students_all = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'student'")->fetch_assoc()['total'];
$active_students = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'student' AND status = 'active'")->fetch_assoc()['total'];
$inactive_students = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'student' AND status = 'inactive'")->fetch_assoc()['total'];
$new_this_month = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'student' AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())")->fetch_assoc()['total'];

// Age distribution
$age_stats = $conn->query("
    SELECT 
        SUM(CASE WHEN age < 6 THEN 1 ELSE 0 END) as toddlers,
        SUM(CASE WHEN age BETWEEN 6 AND 12 THEN 1 ELSE 0 END) as children,
        SUM(CASE WHEN age BETWEEN 13 AND 17 THEN 1 ELSE 0 END) as teens,
        SUM(CASE WHEN age >= 18 THEN 1 ELSE 0 END) as adults
    FROM users 
    WHERE role = 'student' AND age IS NOT NULL
")->fetch_assoc();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Students | AquaFlow Admin</title>
    
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
            justify-content: space-between;
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
        
        .stat-card.students .stat-icon { background: linear-gradient(135deg, #4361ee, #3a0ca3); }
        .stat-card.active .stat-icon { background: linear-gradient(135deg, #4cc9f0, #4895ef); }
        .stat-card.new .stat-icon { background: linear-gradient(135deg, #f72585, #7209b7); }
        .stat-card.enrolled .stat-icon { background: linear-gradient(135deg, #06d6a0, #118ab2); }
        
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
                    <a href="students.php" class="nav-link active">
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
                    <h1>Manage Students</h1>
                    <p>View and manage all swimming academy students</p>
                </div>
                
                <div class="topbar-actions">
                    <div class="dropdown">
                        <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-plus-circle me-1"></i> Quick Action
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addStudentModal">
                                <i class="bi bi-person-plus me-2"></i> Add New Student
                            </a></li>
                            <li><a class="dropdown-item" href="#">
                                <i class="bi bi-download me-2"></i> Export Students
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
                    <div class="stat-card students">
                        <div class="stat-header">
                            <div class="stat-content">
                                <h3><?= $total_students_all ?></h3>
                                <p>Total Students</p>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-people"></i>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card active">
                        <div class="stat-header">
                            <div class="stat-content">
                                <h3><?= $active_students ?></h3>
                                <p>Active Students</p>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-person-check"></i>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card new">
                        <div class="stat-header">
                            <div class="stat-content">
                                <h3><?= $new_this_month ?></h3>
                                <p>New This Month</p>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-person-plus"></i>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card enrolled">
                        <div class="stat-header">
                            <div class="stat-content">
                                <h3><?= $active_students ?></h3>
                                <p>Currently Enrolled</p>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-journal-check"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-section">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Search Students</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name, email, or phone...">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Status</label>
                            <select class="form-select" name="status">
                                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                                <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Filter by Class</label>
                            <select class="form-select" name="class_filter">
                                <option value="0" <?= $class_filter == 0 ? 'selected' : '' ?>>All Classes</option>
                                <?php foreach($classes as $class): ?>
                                    <option value="<?= $class['id'] ?>" <?= $class_filter == $class['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($class['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-filter me-2"></i>Filter
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Students Table -->
                <div class="data-card">
                    <div class="data-header">
                        <h4>Student Directory</h4>
                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addStudentModal">
                            <i class="bi bi-plus-circle me-1"></i>Add Student
                        </button>
                    </div>
                    
                    <div class="table-container">
                        <?php if(empty($students)): ?>
                            <div class="empty-state">
                                <i class="bi bi-person-x"></i>
                                <h5>No Students Found</h5>
                                <p>No students match your search criteria. Try adjusting your filters or add new students.</p>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStudentModal">
                                    <i class="bi bi-plus-circle me-2"></i>Add New Student
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>Contact Info</th>
                                            <th>Age</th>
                                            <th>Enrolled Classes</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($students as $student): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="user-avatar me-3" style="width: 32px; height: 32px; font-size: 0.75rem;">
                                                            <?= strtoupper(substr($student['name'], 0, 1)) ?>
                                                        </div>
                                                        <div>
                                                            <div class="fw-medium"><?= htmlspecialchars($student['name']) ?></div>
                                                            <small class="text-muted">ID: <?= $student['id'] ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="fw-medium"><?= htmlspecialchars($student['email']) ?></div>
                                                    <small class="text-muted"><?= htmlspecialchars($student['phone'] ?? 'N/A') ?></small>
                                                </td>
                                                <td>
                                                    <?php if($student['age']): ?>
                                                        <span class="badge bg-info">
                                                            <?= $student['age'] ?> years
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if($student['enrolled_classes']): ?>
                                                        <div class="d-flex flex-wrap gap-1">
                                                            <?php 
                                                            $class_list = explode(', ', $student['enrolled_classes']);
                                                            foreach($class_list as $class): 
                                                                if(trim($class)): ?>
                                                                    <span class="badge bg-success"><?= htmlspecialchars($class) ?></span>
                                                                <?php endif; 
                                                            endforeach; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Not Enrolled</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if($student['status'] == 'active'): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button class="btn btn-outline-primary btn-sm" 
                                                                data-bs-toggle="modal" data-bs-target="#editStudentModal"
                                                                onclick="editStudent(<?= htmlspecialchars(json_encode($student)) ?>)">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button class="btn btn-outline-info btn-sm"
                                                                data-bs-toggle="modal" data-bs-target="#viewStudentModal"
                                                                onclick="viewStudent(<?= htmlspecialchars(json_encode($student)) ?>)">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this student? This action cannot be undone.');">
                                                            <input type="hidden" name="student_id" value="<?= $student['id'] ?>">
                                                            <button type="submit" name="delete_student" class="btn btn-outline-danger btn-sm">
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

    <!-- Add Student Modal -->
    <div class="modal fade" id="addStudentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Full Name *</label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email Address *</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" name="phone">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Age *</label>
                                <input type="number" class="form-control" name="age" min="1" max="100" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Emergency Contact</label>
                                <input type="text" class="form-control" name="emergency_contact" placeholder="Name and phone number">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Medical Notes</label>
                                <textarea class="form-control" name="medical_notes" rows="3" placeholder="Any medical conditions, allergies, or special requirements..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_student" class="btn btn-primary">Add Student</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div class="modal fade" id="editStudentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="student_id" id="edit_student_id">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Full Name *</label>
                                <input type="text" class="form-control" name="name" id="edit_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email Address *</label>
                                <input type="email" class="form-control" name="email" id="edit_email" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" name="phone" id="edit_phone">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Age *</label>
                                <input type="number" class="form-control" name="age" id="edit_age" min="1" max="100" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="edit_status">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Emergency Contact</label>
                                <input type="text" class="form-control" name="emergency_contact" id="edit_emergency_contact" placeholder="Name and phone number">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Medical Notes</label>
                                <textarea class="form-control" name="medical_notes" id="edit_medical_notes" rows="3" placeholder="Any medical conditions, allergies, or special requirements..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_student" class="btn btn-primary">Update Student</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Student Modal -->
    <div class="modal fade" id="viewStudentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Student Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Full Name</label>
                            <p class="fs-5 fw-medium" id="view_name"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Email Address</label>
                            <p class="fs-5" id="view_email"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Phone Number</label>
                            <p class="fs-5" id="view_phone"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Age</label>
                            <p class="fs-5" id="view_age"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Status</label>
                            <p id="view_status"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Member Since</label>
                            <p class="fs-5" id="view_created_at"></p>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium text-muted">Emergency Contact</label>
                            <p class="fs-5" id="view_emergency_contact"></p>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium text-muted">Medical Notes</label>
                            <div class="card bg-light">
                                <div class="card-body">
                                    <p class="mb-0" id="view_medical_notes"></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium text-muted">Enrolled Classes</label>
                            <div id="view_enrolled_classes"></div>
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
        // Edit student function
        function editStudent(student) {
            document.getElementById('edit_student_id').value = student.id;
            document.getElementById('edit_name').value = student.name;
            document.getElementById('edit_email').value = student.email;
            document.getElementById('edit_phone').value = student.phone || '';
            document.getElementById('edit_age').value = student.age;
            document.getElementById('edit_status').value = student.status || 'active';
            document.getElementById('edit_emergency_contact').value = student.emergency_contact || '';
            document.getElementById('edit_medical_notes').value = student.medical_notes || '';
        }

        // View student function
        function viewStudent(student) {
            document.getElementById('view_name').textContent = student.name;
            document.getElementById('view_email').textContent = student.email;
            document.getElementById('view_phone').textContent = student.phone || 'Not provided';
            document.getElementById('view_age').textContent = student.age ? student.age + ' years' : 'Not provided';
            document.getElementById('view_status').innerHTML = student.status === 'active' 
                ? '<span class="badge bg-success">Active</span>' 
                : '<span class="badge bg-danger">Inactive</span>';
            document.getElementById('view_created_at').textContent = student.created_at ? new Date(student.created_at).toLocaleDateString() : 'N/A';
            document.getElementById('view_emergency_contact').textContent = student.emergency_contact || 'Not provided';
            document.getElementById('view_medical_notes').textContent = student.medical_notes || 'None';
            
            const enrolledClassesDiv = document.getElementById('view_enrolled_classes');
            if (student.enrolled_classes) {
                const classes = student.enrolled_classes.split(', ');
                enrolledClassesDiv.innerHTML = classes.map(cls => 
                    `<span class="badge bg-success me-1 mb-1">${cls}</span>`
                ).join('');
            } else {
                enrolledClassesDiv.innerHTML = '<span class="badge bg-secondary">Not enrolled in any classes</span>';
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
        });
    </script>
</body>
</html>