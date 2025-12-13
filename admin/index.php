<?php
// admin/index.php
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

requireRole('admin');
$user = getCurrentUser($conn);

// Stats
$students = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE role='student'")->fetch_assoc()['c'];
$instructors = (int)$conn->query("SELECT COUNT(*) AS c FROM instructors")->fetch_assoc()['c'];
$classes = (int)$conn->query("SELECT COUNT(*) AS c FROM classes")->fetch_assoc()['c'];
$bookings = (int)$conn->query("SELECT COUNT(*) AS c FROM bookings")->fetch_assoc()['c'];

// Booking trends (Jan–Dec)
$monthCounts = array_fill(1,12,0);
$q = $conn->prepare("
    SELECT MONTH(created_at) AS m, COUNT(*) AS c 
    FROM bookings 
    WHERE YEAR(created_at) = YEAR(CURDATE()) 
    GROUP BY MONTH(created_at)
");
$q->execute();
$res = $q->get_result();
while($r = $res->fetch_assoc()){
    $monthCounts[(int)$r['m']] = (int)$r['c'];
}

// Class type distribution
$labels = []; 
$counts = [];
$res = $conn->query("SELECT age_group, COUNT(*) AS c FROM classes GROUP BY age_group");
while($r = $res->fetch_assoc()){
    $labels[] = $r['age_group'];
    $counts[] = (int)$r['c'];
}

// Monthly revenue (Jan–Dec)
$rev = array_fill(1,12,0.0);
$q = $conn->prepare("
    SELECT MONTH(payment_date) AS m, IFNULL(SUM(amount),0) AS total 
    FROM payments 
    WHERE status='paid' AND YEAR(payment_date)=YEAR(CURDATE()) 
    GROUP BY MONTH(payment_date)
");
$q->execute();
$res = $q->get_result();
while($r = $res->fetch_assoc()){
    $rev[(int)$r['m']] = (float)$r['total'];
}

// Upcoming classes
$upcoming = $conn->query("
    SELECT c.*, i.name as instructor_name 
    FROM classes c 
    LEFT JOIN instructors i ON c.instructor_id = i.id 
    WHERE c.start_time >= NOW() 
    ORDER BY c.start_time ASC 
    LIMIT 4
")->fetch_all(MYSQLI_ASSOC);

// Recent bookings
$recent = $conn->query("
    SELECT b.*, u.name AS student, c.title AS class_name 
    FROM bookings b
    JOIN users u ON u.id=b.user_id
    JOIN classes c ON c.id=b.class_id
    ORDER BY b.created_at DESC 
    LIMIT 4
")->fetch_all(MYSQLI_ASSOC);

// Total revenue
$total_revenue = $conn->query("SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE status = 'paid'")->fetch_assoc()['total'];
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Dashboard - AquaFlow</title>

<link href="../css/style.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* Reset any conflicting header styles */
.header {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 !important;
    margin: 0 !important;
}

.dashboard-container {
    padding: 20px;
    max-width: 1400px;
    margin: 0 auto;
}

.stat-card { 
    border-radius: 10px; 
    padding: 18px; 
    background: #fff; 
    box-shadow: 0 3px 10px rgba(15,23,42,0.08);
    border-left: 4px solid #4e73df;
    transition: transform 0.2s, box-shadow 0.2s;
    height: 100%;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(15,23,42,0.12);
}

.stat-title { 
    color: #6b7280; 
    font-size: 14px; 
    margin-bottom: 8px;
    font-weight: 500;
}

.stat-value { 
    font-size: 24px; 
    font-weight: 700; 
    margin-bottom: 8px;
    color: #1f2937;
}

.stat-sub { 
    font-size: 13px; 
    color: #9ca3af;
}

.card {
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(15,23,42,0.08);
    border: none;
    margin-bottom: 0;
    height: 100%;
}

.compact-card {
    min-height: 300px;
}

.card-header {
    background: white;
    border-bottom: 1px solid #f1f5f9;
    padding: 12px 16px !important;
    border-radius: 12px 12px 0 0 !important;
}

.card-title {
    font-weight: 600;
    color: #1f2937;
    margin: 0;
    font-size: 16px;
}

.compact-body {
    padding: 16px;
}

.compact-table {
    font-size: 13px;
}

.compact-table th {
    padding: 8px 6px;
    font-size: 12px;
    background-color: #f8fafc;
    font-weight: 600;
}

.compact-table td {
    padding: 8px 6px;
    font-size: 13px;
}

.class-item-sm {
    padding: 10px 0;
    border-bottom: 1px solid #f1f5f9;
}

.class-item-sm:last-child {
    border-bottom: none;
}

.title-sm {
    font-weight: 600;
    color: #1f2937;
    font-size: 14px;
    margin-bottom: 5px;
    line-height: 1.3;
}

.meta-sm {
    font-size: 12px;
    color: #6b7280;
    margin-bottom: 3px;
    line-height: 1.2;
}

.btn-outline-primary {
    border-radius: 6px;
    font-weight: 500;
    font-size: 12px;
    padding: 4px 8px;
}

.chart-container {
    position: relative;
}

.compact-chart {
    height: 160px !important;
}

.badge {
    font-size: 11px;
    font-weight: 500;
    padding: 3px 6px;
}

.empty-state-sm {
    text-align: center;
    padding: 30px 0;
}

.empty-state-sm i {
    font-size: 1.5rem;
    color: #9ca3af;
    display: block;
    margin-bottom: 8px;
}

.empty-state-sm small {
    font-size: 13px;
    color: #6b7280;
    display: block;
}

.stat-icon {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    font-size: 18px;
}

.btn-xs {
    font-size: 12px;
    padding: 4px 8px;
    border-radius: 5px;
}

.btn-sm {
    font-size: 13px;
    padding: 6px 12px;
    border-radius: 6px;
}

.text-sm {
    font-size: 13px;
}

.table-layout-fixed {
    border-collapse: separate;
    border-spacing: 0;
    table-layout: fixed;
    width: 100%;
}

.table-layout-fixed td {
    vertical-align: top;
    border: none;
    padding: 10px;
    height: 100%;
}

.table-layout-fixed tr {
    height: 1px;
}

.table-responsive {
    border-radius: 8px;
}

body {
    font-size: 14px;
    background: #f8fafc;
}

h2 {
    font-size: 1.8rem;
    font-weight: 700;
}

.text-muted {
    font-size: 15px;
}

.fw-medium {
    font-size: 13px;
}

small {
    font-size: 12px;
}

/* Layout fixes */
.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    height: 100vh;
    z-index: 1000;
}

.main-content {
    margin-left: 250px;
    min-height: 100vh;
    background: #f8fafc;
}
</style>
</head>
<body>

<div class="sidebar"><?php include 'components/sidebar.php'; ?></div>

<div class="main-content">
    <!-- Include header only once -->
    <?php include 'components/header.php'; ?> <br>

    <div class="dashboard-container">
        <!-- Welcome section -->
        <div class="mb-4">
            <h3 class="fw-bold">Admin Dashboard</h3>
            <p class="text-muted">Overview of your academy's performance and activities.</p>
        </div>

        <!-- COMPACT STAT CARDS -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stat-card" style="border-left-color: #4e73df;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-title">Total Students</div>
                            <div class="stat-value"><?= $students ?></div>
                            <div class="stat-sub">Active learners</div>
                        </div>
                        <div class="stat-icon bg-primary bg-opacity-10">
                            <i class="bi bi-people text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="stat-card" style="border-left-color: #1cc88a;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-title">Active Classes</div>
                            <div class="stat-value"><?= $classes ?></div>
                            <div class="stat-sub">Current schedule</div>
                        </div>
                        <div class="stat-icon bg-success bg-opacity-10">
                            <i class="bi bi-calendar-week text-success"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="stat-card" style="border-left-color: #f6c23e;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-title">Total Revenue</div>
                            <div class="stat-value">$<?= number_format($total_revenue, 2) ?></div>
                            <div class="stat-sub">All time</div>
                        </div>
                        <div class="stat-icon bg-warning bg-opacity-10">
                            <i class="bi bi-currency-dollar text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="stat-card" style="border-left-color: #36b9cc;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-title">Instructors</div>
                            <div class="stat-value"><?= $instructors ?></div>
                            <div class="stat-sub">Available</div>
                        </div>
                        <div class="stat-icon bg-info bg-opacity-10">
                            <i class="bi bi-person-badge text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rest of your dashboard content remains the same -->
        <!-- Main Booking Trends Chart -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title">Booking Trends (This Year)</h5>
                <div>
                    <a href="bookings.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
            </div>
            <div class="card-body">
                <div class="chart-container" style="height: 300px;">
                    <canvas id="bookingChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Compact Cards Table Layout -->
        <div class="table-responsive">
            <table class="table table-layout-fixed">
                <tbody>
                    <tr>
                        <!-- Row 1: Class Type Distribution & Revenue by Month -->
                        <td width="50%" class="p-2">
                            <div class="card compact-card h-100">
                                <div class="card-header d-flex justify-content-between align-items-center py-2">
                                    <h6 class="card-title mb-0">Class Type Distribution</h6>
                                    <a href="classes.php" class="btn btn-outline-primary btn-xs">View All</a>
                                </div>
                                <div class="compact-body">
                                    <div class="chart-container compact-chart">
                                        <canvas id="classTypeBar"></canvas>
                                    </div>
                                </div>
                            </div>
                        </td>
                        
                        <td width="50%" class="p-2">
                            <div class="card compact-card h-100">
                                <div class="card-header d-flex justify-content-between align-items-center py-2">
                                    <h6 class="card-title mb-0">Revenue by Month</h6>
                                    <a href="payments.php" class="btn btn-outline-primary btn-xs">View All</a>
                                </div>
                                <div class="compact-body">
                                    <div class="chart-container compact-chart">
                                        <canvas id="revenueMini"></canvas>
                                    </div>
                                    <div class="mt-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted">Total Revenue:</span>
                                            <strong class="text-success">$<?= number_format(array_sum($rev), 2) ?></strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    
                    <tr>
                        <!-- Row 2: Recent Bookings & Upcoming Classes -->
                        <td width="50%" class="p-2">
                            <div class="card compact-card h-100">
                                <div class="card-header d-flex justify-content-between align-items-center py-2">
                                    <h6 class="card-title mb-0">Recent Bookings</h6>
                                    <a href="bookings.php" class="btn btn-outline-primary btn-xs">View All</a>
                                </div>
                                <div class="compact-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover mb-0 compact-table">
                                            <thead>
                                                <tr>
                                                    <th width="40%">Student</th>
                                                    <th width="40%">Class</th>
                                                    <th width="20%">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if(empty($recent)): ?>
                                                    <tr>
                                                        <td colspan="3" class="text-center py-3">
                                                            <div class="empty-state-sm">
                                                                <i class="bi bi-inbox"></i>
                                                                <small>No recent bookings</small>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach($recent as $r): ?>
                                                        <tr>
                                                            <td>
                                                                <div class="fw-medium text-sm"><?= htmlspecialchars($r['student']) ?></div>
                                                            </td>
                                                            <td>
                                                                <small class="text-muted"><?= htmlspecialchars($r['class_name']) ?></small>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-<?= 
                                                                    $r['status'] == 'confirmed' ? 'success' : 
                                                                    ($r['status'] == 'pending' ? 'warning' : 'secondary')
                                                                ?>">
                                                                    <?= ucfirst($r['status']) ?>
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </td>
                        
                        <td width="50%" class="p-2">
                            <div class="card compact-card h-100">
                                <div class="card-header d-flex justify-content-between align-items-center py-2">
                                    <h6 class="card-title mb-0">Upcoming Classes</h6>
                                    <a href="classes.php" class="btn btn-outline-primary btn-xs">View All</a>
                                </div>
                                <div class="compact-body">
                                    <?php if(empty($upcoming)): ?>
                                        <div class="empty-state-sm">
                                            <i class="bi bi-calendar-x"></i>
                                            <small>No upcoming classes</small>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach($upcoming as $c): ?>
                                            <div class="class-item-sm">
                                                <div class="title-sm"><?= htmlspecialchars($c['title']) ?></div>
                                                <div class="meta-sm">
                                                    <i class="bi bi-calendar-event me-1"></i>
                                                    <?= date("M d", strtotime($c['start_time'])) ?>
                                                </div>
                                                <div class="meta-sm">
                                                    <i class="bi bi-clock me-1"></i>
                                                    <?= date("H:i", strtotime($c['start_time'])) ?>-<?= date("H:i", strtotime($c['end_time'])) ?>
                                                </div>
                                                <div class="meta-sm">
                                                    <i class="bi bi-people me-1"></i>
                                                    <?= htmlspecialchars($c['age_group']) ?> • <?= $c['slots_available'] ?>/<?= $c['slots_total'] ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/main.js"></script>
<script>
// Your existing chart scripts remain the same
// BOOKING BAR CHART (Monthly Bookings)
new Chart(document.getElementById('bookingChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_map(function($i){ return date("M", mktime(0,0,0,$i,1)); }, range(1,12))) ?>,
    datasets: [{
      label: "Bookings",
      data: <?= json_encode(array_values($monthCounts)) ?>,
      backgroundColor: '#4e73df',
      borderRadius: 6,
      borderSkipped: false,
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: 'rgba(15,23,42,0.9)',
        titleFont: { size: 13 },
        bodyFont: { size: 13 },
        padding: 10,
        cornerRadius: 8
      }
    },
    scales: {
      y: { 
        beginAtZero: true,
        grid: {
          color: 'rgba(0,0,0,0.05)'
        },
        ticks: {
          font: { size: 12 }
        }
      },
      x: {
        grid: {
          display: false
        },
        ticks: {
          font: { size: 12 }
        }
      }
    }
  }
});

// CLASS TYPE BAR CHART - Compact Version
new Chart(document.getElementById('classTypeBar'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($labels) ?>,
    datasets: [{
      label: "Class Types",
      data: <?= json_encode($counts) ?>,
      backgroundColor: [
        '#1abc9c', '#3498db', '#9b59b6',
        '#f39c12', '#e74c3c', '#2ecc71'
      ],
      borderRadius: 4,
      borderSkipped: false,
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: 'rgba(15,23,42,0.9)',
        titleFont: { size: 12 },
        bodyFont: { size: 11 },
        padding: 8,
        cornerRadius: 6
      }
    },
    scales: {
      y: { 
        beginAtZero: true,
        grid: {
          color: 'rgba(0,0,0,0.05)',
          drawBorder: false
        },
        ticks: {
          font: { size: 10 },
          padding: 5
        }
      },
      x: {
        grid: {
          display: false
        },
        ticks: {
          font: { size: 10 },
          padding: 5
        }
      }
    }
  }
});

// REVENUE LINE CHART - Compact Version
new Chart(document.getElementById('revenueMini'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_map(function($i){ return date("M", mktime(0,0,0,$i,1)); }, range(1,12))) ?>,
    datasets: [{
      label: "Revenue",
      data: <?= json_encode(array_values($rev)) ?>,
      fill: true,
      backgroundColor: 'rgba(46, 204, 113, 0.15)',
      borderColor: 'rgba(46, 204, 113, 1)',
      borderWidth: 1.5,
      tension: 0.35,
      pointRadius: 2,
      pointBackgroundColor: 'rgba(46, 204, 113, 1)',
      pointBorderColor: '#fff',
      pointBorderWidth: 1
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: 'rgba(15,23,42,0.9)',
        titleFont: { size: 11 },
        bodyFont: { size: 11 },
        padding: 6,
        cornerRadius: 5,
        callbacks: {
          label: function(context) {
            return '$' + context.parsed.y.toFixed(2);
          }
        }
      }
    },
    scales: {
      y: {
        beginAtZero: true,
        ticks: {
          callback: value => '$' + value,
          font: { size: 10 },
          padding: 4
        },
        grid: {
          color: 'rgba(0,0,0,0.03)',
          drawBorder: false
        }
      },
      x: {
        ticks: {
          font: { size: 10 },
          padding: 4
        },
        grid: {
          display: false
        }
      }
    }
  }
});
</script>

</body>
</html>