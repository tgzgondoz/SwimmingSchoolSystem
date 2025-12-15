<?php
// admin/index.php
session_start();
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

// Check database connection
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

requireRole('admin');
$user = getCurrentUser($conn);

// Get current date for time-based stats
$currentYear = date('Y');
$currentMonth = date('n');
$lastMonth = $currentMonth - 1 > 0 ? $currentMonth - 1 : 12;
$lastYear = $currentMonth - 1 > 0 ? $currentYear : $currentYear - 1;

// Enhanced Stats with comparisons
$students = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE role='student'")->fetch_assoc()['c'] ?? 0;
$studentsLastMonth = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE role='student' AND YEAR(created_at) = $lastYear AND MONTH(created_at) = $lastMonth")->fetch_assoc()['c'] ?? 0;
$studentChange = $studentsLastMonth > 0 ? round((($students - $studentsLastMonth) / $studentsLastMonth) * 100, 1) : 100;

$instructors = (int)$conn->query("SELECT COUNT(*) AS c FROM instructors")->fetch_assoc()['c'] ?? 0;
$instructorsLastMonth = (int)$conn->query("SELECT COUNT(*) AS c FROM instructors WHERE YEAR(created_at) = $lastYear AND MONTH(created_at) = $lastMonth")->fetch_assoc()['c'] ?? 0;
$instructorChange = $instructorsLastMonth > 0 ? round((($instructors - $instructorsLastMonth) / $instructorsLastMonth) * 100, 1) : ($instructors > 0 ? 100 : 0);

$classes = (int)$conn->query("SELECT COUNT(*) AS c FROM classes WHERE start_time >= NOW()")->fetch_assoc()['c'] ?? 0;
$totalClasses = (int)$conn->query("SELECT COUNT(*) AS c FROM classes")->fetch_assoc()['c'] ?? 0;
$classesLastMonth = (int)$conn->query("SELECT COUNT(*) AS c FROM classes WHERE YEAR(created_at) = $lastYear AND MONTH(created_at) = $lastMonth")->fetch_assoc()['c'] ?? 0;
$classChange = $classesLastMonth > 0 ? round((($totalClasses - $classesLastMonth) / $classesLastMonth) * 100, 1) : ($totalClasses > 0 ? 100 : 0);

$bookings = (int)$conn->query("SELECT COUNT(*) AS c FROM bookings")->fetch_assoc()['c'] ?? 0;
$bookingsToday = (int)$conn->query("SELECT COUNT(*) AS c FROM bookings WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['c'] ?? 0;
$bookingsLastMonth = (int)$conn->query("SELECT COUNT(*) AS c FROM bookings WHERE YEAR(created_at) = $lastYear AND MONTH(created_at) = $lastMonth")->fetch_assoc()['c'] ?? 0;
$bookingChange = $bookingsLastMonth > 0 ? round((($bookings - $bookingsLastMonth) / $bookingsLastMonth) * 100, 1) : ($bookings > 0 ? 100 : 0);

// Booking trends (Jan–Dec) with previous year comparison
$monthCounts = array_fill(1, 12, 0);
$monthCountsPrev = array_fill(1, 12, 0);
$currentYear = date('Y');
$prevYear = $currentYear - 1;

$q = $conn->prepare("
    SELECT MONTH(created_at) AS m, COUNT(*) AS c 
    FROM bookings 
    WHERE YEAR(created_at) = ? 
    GROUP BY MONTH(created_at)
");
$q->bind_param("i", $currentYear);
$q->execute();
$res = $q->get_result();
while($r = $res->fetch_assoc()){
    $monthCounts[(int)$r['m']] = (int)$r['c'];
}

$q->bind_param("i", $prevYear);
$q->execute();
$res = $q->get_result();
while($r = $res->fetch_assoc()){
    $monthCountsPrev[(int)$r['m']] = (int)$r['c'];
}

// Class type distribution with percentage
$labels = []; 
$counts = [];
$totalClassCount = 0;
$res = $conn->query("SELECT age_group, COUNT(*) AS c FROM classes GROUP BY age_group ORDER BY c DESC");
while($r = $res->fetch_assoc()){
    $labels[] = $r['age_group'];
    $counts[] = (int)$r['c'];
    $totalClassCount += (int)$r['c'];
}

$classPercentages = array_map(function($count) use ($totalClassCount) {
    return $totalClassCount > 0 ? round(($count / $totalClassCount) * 100, 1) : 0;
}, $counts);

// Monthly revenue (Jan–Dec) with growth
$rev = array_fill(1, 12, 0.0);
$revPrev = array_fill(1, 12, 0.0);
$q = $conn->prepare("
    SELECT MONTH(payment_date) AS m, IFNULL(SUM(amount),0) AS total 
    FROM payments 
    WHERE status='paid' AND YEAR(payment_date)=? 
    GROUP BY MONTH(payment_date)
");
$q->bind_param("i", $currentYear);
$q->execute();
$res = $q->get_result();
while($r = $res->fetch_assoc()){
    $rev[(int)$r['m']] = (float)$r['total'];
}

$q->bind_param("i", $prevYear);
$q->execute();
$res = $q->get_result();
while($r = $res->fetch_assoc()){
    $revPrev[(int)$r['m']] = (float)$r['total'];
}

// Calculate revenue metrics
$total_revenue = (float)$conn->query("SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE status = 'paid'")->fetch_assoc()['total'] ?? 0;
$revenueThisMonth = $rev[$currentMonth] ?? 0;
$revenueLastMonth = $rev[$lastMonth] ?? 0;
$revenueChange = $revenueLastMonth > 0 ? round((($revenueThisMonth - $revenueLastMonth) / $revenueLastMonth) * 100, 1) : ($revenueThisMonth > 0 ? 100 : 0);

// Upcoming classes with more details - REMOVED photo column
$upcoming = $conn->query("
    SELECT c.*, i.name as instructor_name,
           (SELECT COUNT(*) FROM bookings WHERE class_id = c.id AND status = 'confirmed') as booked_slots
    FROM classes c 
    LEFT JOIN instructors i ON c.instructor_id = i.id 
    WHERE c.start_time >= NOW() 
    ORDER BY c.start_time ASC 
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Recent bookings with payment status
$recent = $conn->query("
    SELECT b.*, u.name AS student, u.email AS student_email, 
           c.title AS class_name, p.status AS payment_status, p.amount
    FROM bookings b
    JOIN users u ON u.id=b.user_id
    JOIN classes c ON c.id=b.class_id
    LEFT JOIN payments p ON p.booking_id = b.id
    ORDER BY b.created_at DESC 
    LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

// Top instructors by bookings - SIMPLIFIED without reviews table
$topInstructors = $conn->query("
    SELECT i.name, i.email,
           COUNT(b.id) as total_bookings
    FROM instructors i
    LEFT JOIN classes c ON c.instructor_id = i.id
    LEFT JOIN bookings b ON b.class_id = c.id
    GROUP BY i.id
    ORDER BY total_bookings DESC
    LIMIT 4
")->fetch_all(MYSQLI_ASSOC);

// Recent activity log - Check if table exists first
$activities = [];
$activityTableExists = $conn->query("SHOW TABLES LIKE 'activity_log'")->num_rows > 0;
if ($activityTableExists) {
    $activities = $conn->query("
        SELECT a.*, u.name as user_name, u.role as user_role
        FROM activity_log a
        LEFT JOIN users u ON a.user_id = u.id
        ORDER BY a.created_at DESC
        LIMIT 8
    ")->fetch_all(MYSQLI_ASSOC);
} else {
    // Create some sample activity data if table doesn't exist
    $activities = [
        ['description' => 'System initialized', 'user_name' => 'System', 'user_role' => 'system', 'created_at' => date('Y-m-d H:i:s')],
        ['description' => 'Admin logged in', 'user_name' => $user['name'] ?? 'Admin', 'user_role' => 'admin', 'created_at' => date('Y-m-d H:i:s', strtotime('-5 minutes'))],
    ];
}

// Class capacity utilization
$capacity = $conn->query("
    SELECT 
        SUM(CASE WHEN c.slots_available = 0 THEN 1 ELSE 0 END) as full_classes,
        SUM(CASE WHEN c.slots_available > 0 AND c.slots_available < c.slots_total THEN 1 ELSE 0 END) as partial_classes,
        SUM(CASE WHEN c.slots_available = c.slots_total THEN 1 ELSE 0 END) as empty_classes,
        AVG((c.slots_total - c.slots_available) / c.slots_total * 100) as avg_utilization
    FROM classes c
    WHERE c.start_time >= NOW()
")->fetch_assoc();

// Quick stats for today
$today = date('Y-m-d');
$todayBookings = (int)$conn->query("SELECT COUNT(*) FROM bookings WHERE DATE(created_at) = '$today'")->fetch_array()[0] ?? 0;
$todayRevenue = (float)$conn->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE DATE(payment_date) = '$today' AND status = 'paid'")->fetch_array()[0] ?? 0;

// Helper function for month names
function getMonthName($monthNum) {
    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return $months[$monthNum - 1] ?? 'Unknown';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard | AquaFlow Swim Academy</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="../img/favicon.ico">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
            --light-color: #f8f9fa;
            --dark-color: #212529;
            --gray-color: #6c757d;
            --border-radius: 12px;
            --box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease;
        }
        
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: #f8fafc;
            color: #334155;
            overflow-x: hidden;
        }
        
        .dashboard-wrapper {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar Styles */
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
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 260px;
            transition: var(--transition);
        }
        
        /* Topbar */
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
        
        .user-info small {
            color: #64748b;
            font-size: 0.75rem;
        }
        
        /* Dashboard Content */
        .dashboard-content {
            padding: 2rem;
        }
        
        /* Stat Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
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
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }
        
        .stat-card.students::before { background: linear-gradient(180deg, #4361ee, #3a0ca3); }
        .stat-card.instructors::before { background: linear-gradient(180deg, #4cc9f0, #4895ef); }
        .stat-card.classes::before { background: linear-gradient(180deg, #f72585, #7209b7); }
        .stat-card.revenue::before { background: linear-gradient(180deg, #06d6a0, #118ab2); }
        
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
        .stat-card.instructors .stat-icon { background: linear-gradient(135deg, #4cc9f0, #4895ef); }
        .stat-card.classes .stat-icon { background: linear-gradient(135deg, #f72585, #7209b7); }
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
        
        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            margin-top: 0.5rem;
        }
        
        .trend-up { color: #10b981; }
        .trend-down { color: #ef4444; }
        .trend-neutral { color: #6b7280; }
        
        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        @media (max-width: 1200px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .chart-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--box-shadow);
            border: 1px solid #f1f5f9;
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .chart-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
            color: #1e293b;
        }
        
        .chart-wrapper {
            position: relative;
            height: 300px;
        }
        
        /* Data Grid */
        .data-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
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
        
        .data-body {
            padding: 1.5rem;
        }
        
        /* Activity List */
        .activity-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .activity-item {
            display: flex;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            color: white;
            flex-shrink: 0;
        }
        
        .activity-icon.user { background: linear-gradient(135deg, #4361ee, #3a0ca3); }
        .activity-icon.booking { background: linear-gradient(135deg, #4cc9f0, #4895ef); }
        .activity-icon.payment { background: linear-gradient(135deg, #06d6a0, #118ab2); }
        .activity-icon.class { background: linear-gradient(135deg, #f72585, #7209b7); }
        .activity-icon.info { background: linear-gradient(135deg, #6b7280, #4b5563); }
        
        .activity-content h6 {
            font-size: 0.875rem;
            font-weight: 600;
            margin: 0 0 0.25rem 0;
            color: #1e293b;
        }
        
        .activity-content p {
            font-size: 0.75rem;
            color: #64748b;
            margin: 0 0 0.25rem 0;
        }
        
        .activity-time {
            font-size: 0.75rem;
            color: #94a3b8;
        }
        
        /* Class List */
        .class-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .class-item:last-child {
            border-bottom: none;
        }
        
        .class-info h6 {
            font-size: 0.875rem;
            font-weight: 600;
            margin: 0 0 0.25rem 0;
            color: #1e293b;
        }
        
        .class-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.75rem;
            color: #64748b;
        }
        
        .class-stats {
            text-align: right;
        }
        
        .class-capacity {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .capacity-high { background: #dcfce7; color: #166534; }
        .capacity-medium { background: #fef3c7; color: #92400e; }
        .capacity-low { background: #fee2e2; color: #991b1b; }
        
        /* Quick Stats */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .quick-stat {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.25rem;
            text-align: center;
            box-shadow: var(--box-shadow);
            border: 1px solid #f1f5f9;
        }
        
        .quick-stat i {
            font-size: 1.5rem;
            margin-bottom: 0.75rem;
            color: #4361ee;
        }
        
        .quick-stat h4 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
            color: #1e293b;
        }
        
        .quick-stat p {
            color: #64748b;
            font-size: 0.875rem;
            margin: 0.25rem 0 0 0;
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
        
        /* Buttons */
        .btn {
            border-radius: 8px;
            font-weight: 500;
            padding: 0.5rem 1.25rem;
            transition: var(--transition);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #4361ee, #3a0ca3);
            border: none;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(67, 97, 238, 0.3);
        }
        
        /* Utility Classes */
        .text-gradient {
            background: linear-gradient(135deg, #4361ee, #3a0ca3);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .glass-effect {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.6s ease-out;
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
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .data-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #4361ee, #3a0ca3);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #3a0ca3, #4361ee);
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
                    <a href="index.php" class="nav-link active">
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
                <div class="nav-item">
                    <a href="analytics.php" class="nav-link">
                        <i class="bi bi-graph-up"></i>
                        <span class="nav-text">Analytics</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="settings.php" class="nav-link">
                        <i class="bi bi-gear"></i>
                        <span class="nav-text">Settings</span>
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
                    <h1>Dashboard Overview</h1>
                    <p>Welcome back, <?= htmlspecialchars($user['name'] ?? 'Admin') ?>! Here's what's happening today.</p>
                </div>
                
                <div class="topbar-actions">
                    <div class="dropdown">
                        <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-plus-circle me-1"></i> Quick Action
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="classes.php?action=new"><i class="bi bi-calendar-plus me-2"></i> Add New Class</a></li>
                            <li><a class="dropdown-item" href="students.php?action=new"><i class="bi bi-person-plus me-2"></i> Add Student</a></li>
                            <li><a class="dropdown-item" href="instructors.php?action=new"><i class="bi bi-person-badge me-2"></i> Add Instructor</a></li>
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
            <div class="dashboard-content animate-fade-in">
                <!-- Quick Stats -->
                <div class="quick-stats">
                    <div class="quick-stat">
                        <i class="bi bi-calendar-check"></i>
                        <h4><?= $todayBookings ?></h4>
                        <p>Today's Bookings</p>
                    </div>
                    <div class="quick-stat">
                        <i class="bi bi-currency-dollar"></i>
                        <h4>$<?= number_format($todayRevenue, 2) ?></h4>
                        <p>Today's Revenue</p>
                    </div>
                    <div class="quick-stat">
                        <i class="bi bi-clock-history"></i>
                        <h4><?= $classes ?></h4>
                        <p>Upcoming Classes</p>
                    </div>
                    <div class="quick-stat">
                        <i class="bi bi-activity"></i>
                        <h4><?= isset($capacity['avg_utilization']) && $capacity['avg_utilization'] !== null ? number_format($capacity['avg_utilization'], 1) : '0' ?>%</h4>
                        <p>Class Utilization</p>
                    </div>
                </div>

                <!-- Main Stats -->
                <div class="stats-grid">
                    <div class="stat-card students">
                        <div class="stat-header">
                            <div class="stat-content">
                                <h3><?= $students ?></h3>
                                <p>Total Students</p>
                                <div class="stat-trend <?= $studentChange >= 0 ? 'trend-up' : 'trend-down' ?>">
                                    <i class="bi bi-<?= $studentChange >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i>
                                    <span><?= abs($studentChange) ?>% <?= $studentChange >= 0 ? 'increase' : 'decrease' ?></span>
                                </div>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-people"></i>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card instructors">
                        <div class="stat-header">
                            <div class="stat-content">
                                <h3><?= $instructors ?></h3>
                                <p>Active Instructors</p>
                                <div class="stat-trend <?= $instructorChange >= 0 ? 'trend-up' : 'trend-down' ?>">
                                    <i class="bi bi-<?= $instructorChange >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i>
                                    <span><?= abs($instructorChange) ?>% <?= $instructorChange >= 0 ? 'increase' : 'decrease' ?></span>
                                </div>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-person-badge"></i>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card classes">
                        <div class="stat-header">
                            <div class="stat-content">
                                <h3><?= $totalClasses ?></h3>
                                <p>Total Classes</p>
                                <div class="stat-trend <?= $classChange >= 0 ? 'trend-up' : 'trend-down' ?>">
                                    <i class="bi bi-<?= $classChange >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i>
                                    <span><?= abs($classChange) ?>% <?= $classChange >= 0 ? 'increase' : 'decrease' ?></span>
                                </div>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-calendar-week"></i>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card revenue">
                        <div class="stat-header">
                            <div class="stat-content">
                                <h3>$<?= number_format($total_revenue, 2) ?></h3>
                                <p>Total Revenue</p>
                                <div class="stat-trend <?= $revenueChange >= 0 ? 'trend-up' : 'trend-down' ?>">
                                    <i class="bi bi-<?= $revenueChange >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i>
                                    <span><?= abs($revenueChange) ?>% <?= $revenueChange >= 0 ? 'increase' : 'decrease' ?></span>
                                </div>
                            </div>
                            <div class="stat-icon">
                                <i class="bi bi-currency-dollar"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="charts-grid">
                    <div class="chart-container">
                        <div class="chart-header">
                            <h3>Booking Trends</h3>
                            <div>
                                <select class="form-select form-select-sm w-auto" id="bookingPeriod">
                                    <option value="year">This Year</option>
                                    <option value="month">This Month</option>
                                    <option value="week">This Week</option>
                                </select>
                            </div>
                        </div>
                        <div class="chart-wrapper">
                            <canvas id="bookingChart"></canvas>
                        </div>
                    </div>

                    <div class="chart-container">
                        <div class="chart-header">
                            <h3>Revenue Overview</h3>
                            <div>
                                <select class="form-select form-select-sm w-auto" id="revenuePeriod">
                                    <option value="year">Yearly</option>
                                    <option value="month">Monthly</option>
                                </select>
                            </div>
                        </div>
                        <div class="chart-wrapper">
                            <canvas id="revenueChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Data Grid -->
                <div class="data-grid">
                    <!-- Recent Bookings -->
                    <div class="data-card">
                        <div class="data-header">
                            <h4>Recent Bookings</h4>
                            <a href="bookings.php" class="btn btn-primary btn-sm">View All</a>
                        </div>
                        <div class="data-body">
                            <div class="activity-list">
                                <?php if(empty($recent)): ?>
                                    <div class="text-center py-4">
                                        <i class="bi bi-inbox fs-1 text-muted"></i>
                                        <p class="text-muted mt-2">No recent bookings</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach($recent as $r): ?>
                                        <div class="activity-item">
                                            <div class="activity-icon booking">
                                                <i class="bi bi-journal-check"></i>
                                            </div>
                                            <div class="activity-content flex-grow-1">
                                                <h6><?= htmlspecialchars($r['student']) ?></h6>
                                                <p><?= htmlspecialchars($r['class_name']) ?></p>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span class="activity-time">
                                                        <?= date('M d, H:i', strtotime($r['created_at'])) ?>
                                                    </span>
                                                    <span class="badge badge-<?= 
                                                        $r['status'] == 'confirmed' ? 'success' : 
                                                        ($r['status'] == 'pending' ? 'warning' : 'danger')
                                                    ?>">
                                                        <?= ucfirst($r['status']) ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Upcoming Classes -->
                    <div class="data-card">
                        <div class="data-header">
                            <h4>Upcoming Classes</h4>
                            <a href="classes.php" class="btn btn-primary btn-sm">View All</a>
                        </div>
                        <div class="data-body">
                            <?php if(empty($upcoming)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-calendar-x fs-1 text-muted"></i>
                                    <p class="text-muted mt-2">No upcoming classes</p>
                                </div>
                            <?php else: ?>
                                <?php foreach($upcoming as $c): 
                                    $utilization = $c['slots_total'] > 0 ? (($c['slots_total'] - $c['slots_available']) / $c['slots_total']) * 100 : 0;
                                    $capacityClass = $utilization >= 80 ? 'capacity-high' : ($utilization >= 40 ? 'capacity-medium' : 'capacity-low');
                                ?>
                                    <div class="class-item">
                                        <div class="class-info">
                                            <h6><?= htmlspecialchars($c['title']) ?></h6>
                                            <div class="class-meta">
                                                <span><i class="bi bi-person me-1"></i> <?= htmlspecialchars($c['instructor_name']) ?></span>
                                                <span><i class="bi bi-clock me-1"></i> <?= date('M d, H:i', strtotime($c['start_time'])) ?></span>
                                            </div>
                                        </div>
                                        <div class="class-stats">
                                            <span class="class-capacity <?= $capacityClass ?>">
                                                <?= $c['booked_slots'] ?>/<?= $c['slots_total'] ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Second Data Grid -->
                <div class="data-grid">
                    <!-- Top Instructors -->
                    <div class="data-card">
                        <div class="data-header">
                            <h4>Top Instructors</h4>
                            <a href="instructors.php" class="btn btn-primary btn-sm">View All</a>
                        </div>
                        <div class="data-body">
                            <?php if(empty($topInstructors)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-person-x fs-1 text-muted"></i>
                                    <p class="text-muted mt-2">No instructor data</p>
                                </div>
                            <?php else: ?>
                                <?php foreach($topInstructors as $i): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon user">
                                            <i class="bi bi-person"></i>
                                        </div>
                                        <div class="activity-content flex-grow-1">
                                            <h6><?= htmlspecialchars($i['name']) ?></h6>
                                            <p><?= isset($i['email']) ? htmlspecialchars($i['email']) : 'No email' ?></p>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="activity-time">
                                                    <?= isset($i['total_bookings']) ? $i['total_bookings'] : 0 ?> bookings
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="data-card">
                        <div class="data-header">
                            <h4>Recent Activity</h4>
                            <button class="btn btn-outline-primary btn-sm" onclick="location.reload()">Refresh</button>
                        </div>
                        <div class="data-body">
                            <?php if(empty($activities)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-activity fs-1 text-muted"></i>
                                    <p class="text-muted mt-2">No recent activity</p>
                                </div>
                            <?php else: ?>
                                <div class="activity-list">
                                    <?php foreach($activities as $a): 
                                        // Use switch instead of match for PHP 7.x compatibility
                                        $actionType = isset($a['action_type']) ? $a['action_type'] : '';
                                        switch($actionType) {
                                            case 'user_registered':
                                                $iconClass = 'user';
                                                break;
                                            case 'booking_created':
                                                $iconClass = 'booking';
                                                break;
                                            case 'payment_received':
                                                $iconClass = 'payment';
                                                break;
                                            case 'class_created':
                                                $iconClass = 'class';
                                                break;
                                            default:
                                                $iconClass = 'info';
                                        }
                                    ?>
                                        <div class="activity-item">
                                            <div class="activity-icon <?= $iconClass ?>">
                                                <i class="bi bi-<?= 
                                                    $iconClass == 'user' ? 'person' : 
                                                    ($iconClass == 'booking' ? 'journal-check' : 
                                                    ($iconClass == 'payment' ? 'credit-card' : 
                                                    ($iconClass == 'class' ? 'calendar-week' : 'info-circle')))
                                                ?>"></i>
                                            </div>
                                            <div class="activity-content flex-grow-1">
                                                <h6><?= isset($a['description']) ? htmlspecialchars($a['description']) : 'System Activity' ?></h6>
                                                <p><?= isset($a['user_name']) ? htmlspecialchars($a['user_name']) : 'System' ?> (<?= isset($a['user_role']) ? $a['user_role'] : 'system' ?>)</p>
                                                <span class="activity-time">
                                                    <?= isset($a['created_at']) ? date('M d, H:i', strtotime($a['created_at'])) : date('M d, H:i') ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Class Distribution Chart -->
                <div class="chart-container mt-4">
                    <div class="chart-header">
                        <h3>Class Type Distribution</h3>
                        <div>
                            <a href="classes.php" class="btn btn-primary btn-sm">Manage Classes</a>
                        </div>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="classDistributionChart"></canvas>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom Scripts -->
    <script src="../js/main.js"></script>
    
    <script>
    // Chart Colors
    const chartColors = {
        primary: '#4361ee',
        secondary: '#3a0ca3',
        success: '#4cc9f0',
        info: '#4895ef',
        warning: '#f72585',
        danger: '#7209b7'
    };

    // Booking Trends Chart
    const bookingCtx = document.getElementById('bookingChart').getContext('2d');
    const bookingChart = new Chart(bookingCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode(array_map(function($i){ 
                $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                return $monthNames[$i-1];
            }, range(1,12))) ?>,
            datasets: [{
                label: 'Current Year',
                data: <?= json_encode(array_values($monthCounts)) ?>,
                borderColor: chartColors.primary,
                backgroundColor: 'rgba(67, 97, 238, 0.1)',
                borderWidth: 2,
                tension: 0.4,
                fill: true
            }, {
                label: 'Previous Year',
                data: <?= json_encode(array_values($monthCountsPrev)) ?>,
                borderColor: chartColors.info,
                backgroundColor: 'transparent',
                borderWidth: 1,
                borderDash: [5, 5],
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        drawBorder: false
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });

    // Revenue Chart
    const revenueCtx = document.getElementById('revenueChart').getContext('2d');
    const revenueChart = new Chart(revenueCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_map(function($i){ 
                $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                return $monthNames[$i-1];
            }, range(1,12))) ?>,
            datasets: [{
                label: 'Current Year',
                data: <?= json_encode(array_values($rev)) ?>,
                backgroundColor: chartColors.success,
                borderRadius: 6
            }, {
                label: 'Previous Year',
                data: <?= json_encode(array_values($revPrev)) ?>,
                backgroundColor: chartColors.info,
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '$' + value;
                        }
                    }
                }
            }
        }
    });

    // Class Distribution Chart
    const classDistCtx = document.getElementById('classDistributionChart').getContext('2d');
    const classDistChart = new Chart(classDistCtx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($labels) ?>,
            datasets: [{
                data: <?= json_encode($counts) ?>,
                backgroundColor: [
                    chartColors.primary,
                    chartColors.secondary,
                    chartColors.success,
                    chartColors.info,
                    chartColors.warning,
                    chartColors.danger
                ],
                borderWidth: 0,
                hoverOffset: 15
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const percentage = <?= json_encode($classPercentages) ?>[context.dataIndex] || 0;
                            return `${label}: ${value} (${percentage}%)`;
                        }
                    }
                }
            },
            cutout: '70%'
        }
    });

    // Period selectors functionality
    document.getElementById('bookingPeriod').addEventListener('change', function(e) {
        // In a real app, you would fetch new data based on the selected period
        console.log('Booking period changed to:', e.target.value);
    });

    document.getElementById('revenuePeriod').addEventListener('change', function(e) {
        // In a real app, you would fetch new data based on the selected period
        console.log('Revenue period changed to:', e.target.value);
    });

    // Real-time updates simulation (every 30 seconds)
    setInterval(() => {
        // This would be replaced with actual AJAX calls in production
        console.log('Checking for updates...');
    }, 30000);

    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Sidebar toggle for mobile
    const sidebarToggle = document.createElement('button');
    sidebarToggle.className = 'btn btn-primary btn-sm d-md-none position-fixed bottom-0 start-0 m-3';
    sidebarToggle.innerHTML = '<i class="bi bi-list"></i>';
    sidebarToggle.style.zIndex = '1050';
    sidebarToggle.onclick = function() {
        document.querySelector('.sidebar').classList.toggle('active');
        document.querySelector('.main-content').classList.toggle('active');
    };
    document.body.appendChild(sidebarToggle);
    </script>
</body>
</html>