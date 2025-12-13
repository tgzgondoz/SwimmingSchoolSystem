[file name]: student/index.php
[file content begin]
<?php
// student/index.php - Student Dashboard Home
session_start();
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

requireRole('student');
$user = getCurrentUser($conn);
$student_id = $_SESSION['user_id'];

// Get student statistics
$total_bookings = $conn->query("SELECT COUNT(*) as total FROM bookings WHERE user_id = $student_id")->fetch_assoc()['total'];
$upcoming_classes = $conn->query("SELECT COUNT(*) as total FROM bookings b JOIN classes c ON b.class_id = c.id WHERE b.user_id = $student_id AND c.start_time >= NOW() AND b.status = 'confirmed'")->fetch_assoc()['total'];
$completed_classes = $conn->query("SELECT COUNT(*) as total FROM bookings b JOIN classes c ON b.class_id = c.id WHERE b.user_id = $student_id AND c.end_time < NOW() AND b.status = 'confirmed'")->fetch_assoc()['total'];
$total_payments = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE user_id = $student_id AND status = 'paid'")->fetch_assoc()['total'];

// Get upcoming classes
$upcoming_classes_list = $conn->query("
    SELECT c.*, i.name as instructor_name, b.status as booking_status
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    LEFT JOIN instructors i ON c.instructor_id = i.id
    WHERE b.user_id = $student_id AND c.start_time >= NOW() AND b.status = 'confirmed'
    ORDER BY c.start_time ASC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Get recent payments
$recent_payments = $conn->query("
    SELECT * FROM payments 
    WHERE user_id = $student_id 
    ORDER BY payment_date DESC 
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Get class recommendations based on age group
$recommended_classes = $conn->query("
    SELECT c.*, i.name as instructor_name
    FROM classes c
    LEFT JOIN instructors i ON c.instructor_id = i.id
    WHERE c.start_time >= NOW() AND c.slots_available > 0
    AND c.age_group LIKE '%" . $conn->real_escape_string($user['age_group'] ?? '') . "%'
    ORDER BY c.start_time ASC
    LIMIT 4
")->fetch_all(MYSQLI_ASSOC);

// Get attendance progress (last 30 days)
$attendance_stats = $conn->query("
    SELECT 
        COUNT(*) as total_classes,
        SUM(CASE WHEN c.end_time < NOW() THEN 1 ELSE 0 END) as attended_classes,
        SUM(CASE WHEN c.end_time >= NOW() THEN 1 ELSE 0 END) as upcoming_classes
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    WHERE b.user_id = $student_id 
    AND b.status = 'confirmed'
    AND c.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
")->fetch_assoc();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Student Dashboard - AquaFlow Swimming School</title>
  
  <link href="../css/style.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root {
      --primary: #3b82f6;
      --secondary: #6b7280;
      --success: #10b981;
      --info: #06b6d4;
      --warning: #f59e0b;
      --danger: #ef4444;
      --light: #f8fafc;
      --dark: #1f2937;
    }

    body {
      background: #f8fafc;
      color: #1f2937;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      min-height: 100vh;
    }

    .dashboard-container {
      padding: 20px;
      max-width: 1400px;
      margin: 0 auto;
    }

    .welcome-section {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      border-radius: 15px;
      padding: 30px;
      color: white;
      margin-bottom: 30px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }

    .welcome-section h1 {
      font-weight: 700;
      margin-bottom: 10px;
    }

    .welcome-section p {
      opacity: 0.9;
      margin-bottom: 0;
    }

    .stat-card {
      background: white;
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
      border: 1px solid #e5e7eb;
      transition: transform 0.3s, box-shadow 0.3s;
      height: 100%;
    }

    .stat-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }

    .stat-icon {
      width: 50px;
      height: 50px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 24px;
      margin-bottom: 15px;
    }

    .stat-number {
      font-size: 28px;
      font-weight: 700;
      margin-bottom: 5px;
    }

    .stat-label {
      font-size: 14px;
      color: #6b7280;
      font-weight: 500;
    }

    .card {
      border: none;
      border-radius: 12px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
      margin-bottom: 24px;
      overflow: hidden;
    }

    .card-header {
      background: white;
      border-bottom: 1px solid #e5e7eb;
      padding: 20px;
      font-weight: 600;
      font-size: 18px;
      color: #1f2937;
    }

    .card-body {
      padding: 20px;
    }

    .list-group-item {
      border: none;
      border-bottom: 1px solid #e5e7eb;
      padding: 15px 0;
    }

    .list-group-item:last-child {
      border-bottom: none;
    }

    .badge {
      font-weight: 500;
      padding: 6px 10px;
      border-radius: 6px;
    }

    .btn-primary {
      background: var(--primary);
      border: none;
      border-radius: 8px;
      padding: 10px 20px;
      font-weight: 500;
      transition: all 0.3s;
    }

    .btn-primary:hover {
      background: #2563eb;
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(59, 130, 246, 0.3);
    }

    .progress {
      height: 8px;
      border-radius: 4px;
      background: #e5e7eb;
      overflow: hidden;
    }

    .progress-bar {
      border-radius: 4px;
    }

    .class-item {
      padding: 15px;
      border-radius: 8px;
      background: #f8fafc;
      margin-bottom: 10px;
      border-left: 4px solid var(--primary);
      transition: all 0.3s;
    }

    .class-item:hover {
      background: #f1f5f9;
      transform: translateX(5px);
    }

    .class-title {
      font-weight: 600;
      color: #1f2937;
      margin-bottom: 5px;
    }

    .class-meta {
      font-size: 13px;
      color: #6b7280;
      margin-bottom: 5px;
    }

    .class-time {
      font-size: 12px;
      color: #9ca3af;
    }

    .empty-state {
      text-align: center;
      padding: 40px 20px;
      color: #6b7280;
    }

    .empty-state i {
      font-size: 48px;
      margin-bottom: 15px;
      opacity: 0.5;
    }

    .sidebar {
      width: 250px;
      background: white;
      position: fixed;
      left: 0;
      top: 0;
      height: 100vh;
      box-shadow: 2px 0 10px rgba(0,0,0,0.05);
      z-index: 1000;
    }

    .main-content {
      margin-left: 250px;
      min-height: 100vh;
    }

    @media (max-width: 768px) {
      .sidebar {
        width: 70px;
      }
      .main-content {
        margin-left: 70px;
      }
      .sidebar .nav-text {
        display: none;
      }
    }

    .nav-link {
      padding: 12px 20px;
      color: #6b7280;
      border-radius: 8px;
      margin: 5px 10px;
      transition: all 0.3s;
    }

    .nav-link:hover, .nav-link.active {
      background: #eff6ff;
      color: var(--primary);
    }

    .nav-link i {
      margin-right: 10px;
      width: 20px;
      text-align: center;
    }

    .header {
      background: white;
      padding: 20px 30px;
      border-bottom: 1px solid #e5e7eb;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .user-menu {
      display: flex;
      align-items: center;
      gap: 15px;
    }

    .avatar {
      width: 40px;
      height: 40px;
      background: var(--primary);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: 600;
    }
  </style>
</head>
<body>
  <!-- Sidebar -->
  <div class="sidebar">
    <div class="p-4">
      <h4 class="text-primary mb-4"><i class="bi bi-water me-2"></i>AquaFlow</h4>
      <nav class="nav flex-column">
        <a class="nav-link active" href="index.php">
          <i class="bi bi-speedometer2"></i>
          <span class="nav-text">Dashboard</span>
        </a>
        <a class="nav-link" href="classes.php">
          <i class="bi bi-calendar-week"></i>
          <span class="nav-text">Classes</span>
        </a>
        <a class="nav-link" href="my-bookings.php">
          <i class="bi bi-ticket-perforated"></i>
          <span class="nav-text">My Bookings</span>
        </a>
        <a class="nav-link" href="payments.php">
          <i class="bi bi-credit-card"></i>
          <span class="nav-text">Payments</span>
        </a>
        <a class="nav-link" href="progress.php">
          <i class="bi bi-graph-up"></i>
          <span class="nav-text">Progress</span>
        </a>
        <a class="nav-link" href="profile.php">
          <i class="bi bi-person-circle"></i>
          <span class="nav-text">Profile</span>
        </a>
        <a class="nav-link" href="../admin/logout.php">
          <i class="bi bi-box-arrow-right"></i>
          <span class="nav-text">Logout</span>
        </a>
      </nav>
    </div>
  </div>

  <!-- Main Content -->
  <div class="main-content">
    <!-- Header -->
    <div class="header">
      <div>
        <h5 class="mb-0">Student Dashboard</h5>
        <small class="text-muted">Welcome back, <?= htmlspecialchars($user['name']) ?>!</small>
      </div>
      <div class="user-menu">
        <div class="avatar">
          <?= strtoupper(substr($user['name'], 0, 1)) ?>
        </div>
        <div>
          <div class="fw-medium"><?= htmlspecialchars($user['name']) ?></div>
          <small class="text-muted">Student</small>
        </div>
      </div>
    </div>

    <div class="dashboard-container">
      <!-- Welcome Section -->
      <div class="welcome-section">
        <div class="row align-items-center">
          <div class="col-md-8">
            <h1>Welcome back, <?= htmlspecialchars($user['name']) ?>!</h1>
            <p>Track your swimming classes, view upcoming sessions, and monitor your progress.</p>
          </div>
          <div class="col-md-4 text-end">
            <div class="d-inline-block bg-white text-dark rounded-pill px-4 py-2">
              <i class="bi bi-calendar-check me-2"></i>
              <?= date('l, F j, Y') ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Statistics Cards -->
      <div class="row mb-4">
        <div class="col-md-3">
          <div class="stat-card">
            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
              <i class="bi bi-ticket-perforated"></i>
            </div>
            <div class="stat-number"><?= $total_bookings ?></div>
            <div class="stat-label">Total Bookings</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stat-card">
            <div class="stat-icon bg-success bg-opacity-10 text-success">
              <i class="bi bi-calendar-check"></i>
            </div>
            <div class="stat-number"><?= $upcoming_classes ?></div>
            <div class="stat-label">Upcoming Classes</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stat-card">
            <div class="stat-icon bg-info bg-opacity-10 text-info">
              <i class="bi bi-check-circle"></i>
            </div>
            <div class="stat-number"><?= $completed_classes ?></div>
            <div class="stat-label">Completed</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stat-card">
            <div class="stat-icon bg-warning bg-opacity-10 text-warning">
              <i class="bi bi-currency-dollar"></i>
            </div>
            <div class="stat-number">$<?= number_format($total_payments, 2) ?></div>
            <div class="stat-label">Total Payments</div>
          </div>
        </div>
      </div>

      <div class="row">
        <!-- Left Column: Upcoming Classes & Recommended Classes -->
        <div class="col-lg-8">
          <!-- Upcoming Classes -->
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <span>Upcoming Classes</span>
              <a href="my-bookings.php" class="btn btn-sm btn-primary">View All</a>
            </div>
            <div class="card-body">
              <?php if(empty($upcoming_classes_list)): ?>
                <div class="empty-state">
                  <i class="bi bi-calendar-x"></i>
                  <h5>No Upcoming Classes</h5>
                  <p>You don't have any upcoming classes booked.</p>
                  <a href="classes.php" class="btn btn-primary">Browse Classes</a>
                </div>
              <?php else: ?>
                <?php foreach($upcoming_classes_list as $class): ?>
                  <div class="class-item">
                    <div class="row align-items-center">
                      <div class="col-md-8">
                        <div class="class-title"><?= htmlspecialchars($class['title']) ?></div>
                        <div class="class-meta">
                          <i class="bi bi-person me-1"></i><?= htmlspecialchars($class['instructor_name']) ?> •
                          <i class="bi bi-people me-1"></i><?= htmlspecialchars($class['age_group']) ?>
                        </div>
                        <div class="class-time">
                          <i class="bi bi-clock me-1"></i>
                          <?= date('M j, Y g:i A', strtotime($class['start_time'])) ?>
                        </div>
                      </div>
                      <div class="col-md-4 text-end">
                        <span class="badge bg-success">Confirmed</span>
                        <div class="mt-2">
                          <a href="class-details.php?id=<?= $class['id'] ?>" class="btn btn-sm btn-outline-primary">View Details</a>
                        </div>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <!-- Recommended Classes -->
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <span>Recommended for You</span>
              <a href="classes.php" class="btn btn-sm btn-primary">Browse All</a>
            </div>
            <div class="card-body">
              <?php if(empty($recommended_classes)): ?>
                <div class="empty-state">
                  <i class="bi bi-calendar-x"></i>
                  <p>No classes available for your age group.</p>
                </div>
              <?php else: ?>
                <div class="row">
                  <?php foreach($recommended_classes as $class): ?>
                    <div class="col-md-6 mb-3">
                      <div class="class-item">
                        <div class="class-title"><?= htmlspecialchars($class['title']) ?></div>
                        <div class="class-meta">
                          <i class="bi bi-person me-1"></i><?= htmlspecialchars($class['instructor_name']) ?>
                        </div>
                        <div class="class-meta">
                          <i class="bi bi-people me-1"></i><?= htmlspecialchars($class['age_group']) ?>
                        </div>
                        <div class="class-time">
                          <i class="bi bi-clock me-1"></i>
                          <?= date('M j, Y g:i A', strtotime($class['start_time'])) ?>
                        </div>
                        <div class="mt-2">
                          <span class="badge bg-info"><?= $class['slots_available'] ?> slots left</span>
                          <a href="class-details.php?id=<?= $class['id'] ?>" class="btn btn-sm btn-primary float-end">Book Now</a>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Right Column: Recent Payments & Progress -->
        <div class="col-lg-4">
          <!-- Recent Payments -->
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <span>Recent Payments</span>
              <a href="payments.php" class="btn btn-sm btn-primary">View All</a>
            </div>
            <div class="card-body">
              <?php if(empty($recent_payments)): ?>
                <div class="empty-state">
                  <i class="bi bi-credit-card"></i>
                  <p>No payment history</p>
                </div>
              <?php else: ?>
                <?php foreach($recent_payments as $payment): ?>
                  <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center">
                      <div>
                        <div class="fw-medium">$<?= number_format($payment['amount'], 2) ?></div>
                        <small class="text-muted">
                          <?= date('M j, Y', strtotime($payment['payment_date'])) ?>
                        </small>
                      </div>
                      <div>
                        <span class="badge bg-<?= $payment['status'] == 'paid' ? 'success' : ($payment['status'] == 'pending' ? 'warning' : 'danger') ?>">
                          <?= ucfirst($payment['status']) ?>
                        </span>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <!-- Attendance Progress -->
          <div class="card">
            <div class="card-header">
              <span>Attendance (Last 30 Days)</span>
            </div>
            <div class="card-body">
              <div class="text-center mb-4">
                <div class="display-4 fw-bold text-primary">
                  <?= $attendance_stats['attended_classes'] ?>
                </div>
                <div class="text-muted">classes attended</div>
              </div>
              
              <div class="mb-3">
                <div class="d-flex justify-content-between mb-1">
                  <span>Attendance Rate</span>
                  <span>
                    <?= $attendance_stats['total_classes'] > 0 ? 
                      round(($attendance_stats['attended_classes'] / $attendance_stats['total_classes']) * 100) : 0 ?>%
                  </span>
                </div>
                <div class="progress">
                  <div class="progress-bar bg-primary" style="width: <?= $attendance_stats['total_classes'] > 0 ? 
                    ($attendance_stats['attended_classes'] / $attendance_stats['total_classes']) * 100 : 0 ?>%"></div>
                </div>
              </div>

              <div class="row text-center">
                <div class="col-6">
                  <div class="fw-bold"><?= $attendance_stats['total_classes'] ?></div>
                  <small class="text-muted">Total</small>
                </div>
                <div class="col-6">
                  <div class="fw-bold text-success"><?= $attendance_stats['attended_classes'] ?></div>
                  <small class="text-muted">Attended</small>
                </div>
              </div>
            </div>
          </div>

          <!-- Quick Actions -->
          <div class="card">
            <div class="card-header">
              <span>Quick Actions</span>
            </div>
            <div class="card-body">
              <div class="d-grid gap-2">
                <a href="classes.php" class="btn btn-primary">
                  <i class="bi bi-search me-2"></i>Browse Classes
                </a>
                <a href="my-bookings.php" class="btn btn-outline-primary">
                  <i class="bi bi-ticket-perforated me-2"></i>My Bookings
                </a>
                <a href="payments.php" class="btn btn-outline-primary">
                  <i class="bi bi-credit-card me-2"></i>Make Payment
                </a>
                <a href="profile.php" class="btn btn-outline-primary">
                  <i class="bi bi-gear me-2"></i>Update Profile
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../js/main.js"></script>
  <script>
    // Simple theme switcher
    document.addEventListener('DOMContentLoaded', function() {
      const savedTheme = localStorage.getItem('theme') || 'light';
      if (savedTheme === 'dark') {
        document.body.classList.add('dark-mode');
      }

      // Update time every minute
      function updateTime() {
        const now = new Date();
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        document.querySelector('.date-display').textContent = now.toLocaleDateString('en-US', options);
      }
      
      updateTime();
      setInterval(updateTime, 60000);
    });
  </script>
</body>
</html>
[file content end]