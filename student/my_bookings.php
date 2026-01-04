[file name]: student/my-bookings.php
[file content begin]
<?php
// student/my-bookings.php - Student's Bookings Management
session_start();
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

requireRole('student');
$user = getCurrentUser($conn);
$student_id = $_SESSION['user_id'];

// Handle booking cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    $booking_id = intval($_POST['booking_id']);
    
    $stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $booking_id, $student_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        // Update class available slots
        $update_stmt = $conn->prepare("
            UPDATE classes c
            JOIN bookings b ON c.id = b.class_id
            SET c.slots_available = c.slots_available + 1
            WHERE b.id = ?
        ");
        $update_stmt->bind_param('i', $booking_id);
        $update_stmt->execute();
        
        $success_message = "Booking cancelled successfully.";
    } else {
        $error_message = "Failed to cancel booking.";
    }
}

// Filter bookings
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';

// Build query based on filters
$where_conditions = ["b.user_id = ?"];
$params = [$student_id];
$types = 'i';

if ($status_filter) {
    $where_conditions[] = "b.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($type_filter === 'upcoming') {
    $where_conditions[] = "c.start_time >= NOW()";
} elseif ($type_filter === 'past') {
    $where_conditions[] = "c.start_time < NOW()";
}

// Get bookings
$query = "
    SELECT b.*, c.*, i.name as instructor_name,
           CASE 
               WHEN c.start_time < NOW() THEN 'past'
               ELSE 'upcoming'
           END as time_status
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    LEFT JOIN instructors i ON c.instructor_id = i.id
    WHERE " . implode(' AND ', $where_conditions) . "
    ORDER BY c.start_time DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get booking statistics
$stats_query = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
        SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN c.start_time >= NOW() AND b.status = 'confirmed' THEN 1 ELSE 0 END) as upcoming
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    WHERE b.user_id = ?
";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bind_param('i', $student_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>My Bookings - Student Dashboard</title>
  
  <link href="../css/style.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    .dashboard-container {
      padding: 20px;
      max-width: 1400px;
      margin: 0 auto;
    }

    .stats-card {
      background: white;
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
      margin-bottom: 24px;
    }

    .booking-card {
      background: white;
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
      margin-bottom: 20px;
      transition: all 0.3s;
    }

    .booking-card:hover {
      box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }

    .booking-header {
      padding: 15px 20px;
      border-bottom: 1px solid #e5e7eb;
      background: #f8fafc;
    }

    .booking-body {
      padding: 20px;
    }

    .booking-status {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 5px 10px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 500;
    }

    .status-confirmed {
      background: #dcfce7;
      color: #166534;
    }

    .status-pending {
      background: #fef3c7;
      color: #92400e;
    }

    .status-cancelled {
      background: #fee2e2;
      color: #dc2626;
    }

    .status-completed {
      background: #e5e7eb;
      color: #6b7280;
    }

    .badge-time {
      background: #dbeafe;
      color: #1d4ed8;
      padding: 3px 8px;
      border-radius: 4px;
      font-size: 11px;
    }

    .btn-cancel {
      background: #fee2e2;
      color: #dc2626;
      border: none;
      border-radius: 6px;
      padding: 8px 15px;
      font-size: 14px;
      transition: all 0.3s;
    }

    .btn-cancel:hover {
      background: #fecaca;
      transform: translateY(-2px);
    }

    .btn-view {
      background: #dbeafe;
      color: #1d4ed8;
      border: none;
      border-radius: 6px;
      padding: 8px 15px;
      font-size: 14px;
      transition: all 0.3s;
    }

    .btn-view:hover {
      background: #bfdbfe;
      transform: translateY(-2px);
    }

    .filter-tabs {
      display: flex;
      gap: 10px;
      margin-bottom: 20px;
      flex-wrap: wrap;
    }

    .filter-tab {
      padding: 8px 16px;
      border-radius: 8px;
      background: white;
      border: 1px solid #e5e7eb;
      color: #6b7280;
      text-decoration: none;
      transition: all 0.3s;
    }

    .filter-tab:hover, .filter-tab.active {
      background: #3b82f6;
      color: white;
      border-color: #3b82f6;
    }

    .stat-number {
      font-size: 24px;
      font-weight: 700;
      margin-bottom: 5px;
    }

    .stat-label {
      font-size: 14px;
      color: #6b7280;
    }

    .empty-state {
      text-align: center;
      padding: 60px 20px;
    }

    .empty-state i {
      font-size: 64px;
      color: #e5e7eb;
      margin-bottom: 20px;
    }

    .instructor-info {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 10px;
    }

    .instructor-avatar {
      width: 40px;
      height: 40px;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
  <div class="sidebar"><?php include 'components/sidebar.php'; ?></div>
  
  <div class="main-content">
    <div class="header"><?php include 'components/header.php'; ?></div>
    
    <div class="dashboard-container">
      <!-- Alert Messages -->
      <?php if(isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="bi bi-check-circle me-2"></i><?= $success_message ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      
      <?php if(isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="bi bi-exclamation-circle me-2"></i><?= $error_message ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <!-- Page Header -->
      <div class="mb-4">
        <h1 class="fw-bold">My Bookings</h1>
        <p class="text-muted">Manage your class bookings and view booking history</p>
      </div>

      <!-- Statistics Cards -->
      <div class="row mb-4">
        <div class="col-md-3">
          <div class="stats-card">
            <div class="stat-number"><?= $stats['total'] ?></div>
            <div class="stat-label">Total Bookings</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stats-card">
            <div class="stat-number"><?= $stats['confirmed'] ?></div>
            <div class="stat-label">Confirmed</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stats-card">
            <div class="stat-number"><?= $stats['upcoming'] ?></div>
            <div class="stat-label">Upcoming</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stats-card">
            <div class="stat-number"><?= $stats['pending'] ?></div>
            <div class="stat-label">Pending</div>
          </div>
        </div>
      </div>

      <!-- Filter Tabs -->
      <div class="filter-tabs">
        <a href="my-bookings.php" class="filter-tab <?= !$type_filter && !$status_filter ? 'active' : '' ?>">All Bookings</a>
        <a href="my-bookings.php?type=upcoming" class="filter-tab <?= $type_filter === 'upcoming' ? 'active' : '' ?>">Upcoming</a>
        <a href="my-bookings.php?type=past" class="filter-tab <?= $type_filter === 'past' ? 'active' : '' ?>">Past</a>
        <a href="my-bookings.php?status=confirmed" class="filter-tab <?= $status_filter === 'confirmed' ? 'active' : '' ?>">Confirmed</a>
        <a href="my-bookings.php?status=pending" class="filter-tab <?= $status_filter === 'pending' ? 'active' : '' ?>">Pending</a>
        <a href="my-bookings.php?status=cancelled" class="filter-tab <?= $status_filter === 'cancelled' ? 'active' : '' ?>">Cancelled</a>
      </div>

      <!-- Bookings List -->
      <?php if(empty($bookings)): ?>
        <div class="empty-state">
          <i class="bi bi-calendar-x"></i>
          <h3>No Bookings Found</h3>
          <p class="text-muted">
            <?php if($status_filter || $type_filter): ?>
              No bookings match your current filters.
            <?php else: ?>
              You haven't booked any classes yet.
            <?php endif; ?>
          </p>
          <a href="classes.php" class="btn btn-primary">
            <i class="bi bi-search me-2"></i>Browse Classes
          </a>
        </div>
      <?php else: ?>
        <div class="row">
          <?php foreach($bookings as $booking): ?>
            <div class="col-12">
              <div class="booking-card">
                <div class="booking-header d-flex justify-content-between align-items-center">
                  <div>
                    <span class="booking-status status-<?= $booking['status'] ?>">
                      <i class="bi bi-circle-fill" style="font-size: 8px;"></i>
                      <?= ucfirst($booking['status']) ?>
                    </span>
                    <span class="badge-time ms-2">
                      <i class="bi bi-clock me-1"></i>
                      <?= strtotime($booking['start_time']) > time() ? 'Upcoming' : 'Past' ?>
                    </span>
                  </div>
                  <div class="text-muted small">
                    Booking ID: #<?= $booking['id'] ?>
                  </div>
                </div>
                <div class="booking-body">
                  <div class="row align-items-center">
                    <div class="col-md-8">
                      <h5 class="mb-2"><?= htmlspecialchars($booking['title']) ?></h5>
                      <div class="instructor-info">
                        <div class="instructor-avatar">
                          <?= strtoupper(substr($booking['instructor_name'], 0, 1)) ?>
                        </div>
                        <div>
                          <div class="fw-medium"><?= htmlspecialchars($booking['instructor_name']) ?></div>
                          <small class="text-muted">Instructor</small>
                        </div>
                      </div>
                      <div class="row text-muted">
                        <div class="col-6">
                          <i class="bi bi-calendar me-1"></i>
                          <?= date('M j, Y', strtotime($booking['start_time'])) ?>
                        </div>
                        <div class="col-6">
                          <i class="bi bi-clock me-1"></i>
                          <?= date('g:i A', strtotime($booking['start_time'])) ?> - <?= date('g:i A', strtotime($booking['end_time'])) ?>
                        </div>
                        <div class="col-6">
                          <i class="bi bi-people me-1"></i>
                          <?= htmlspecialchars($booking['age_group']) ?>
                        </div>
                        <div class="col-6">
                          <i class="bi bi-currency-dollar me-1"></i>
                          $<?= number_format($booking['price'], 2) ?>
                        </div>
                      </div>
                    </div>
                    <div class="col-md-4 text-end">
                      <div class="d-flex gap-2 justify-content-end">
                        <a href="class-details.php?id=<?= $booking['id'] ?>" class="btn btn-view">
                          <i class="bi bi-eye me-1"></i>View Details
                        </a>
                        <?php if($booking['status'] === 'pending' || $booking['status'] === 'confirmed'): ?>
                          <form method="POST" class="d-inline" 
                                onsubmit="return confirm('Are you sure you want to cancel this booking?');">
                            <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                            <button type="submit" name="cancel_booking" class="btn btn-cancel">
                              <i class="bi bi-x-circle me-1"></i>Cancel
                            </button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Add confirmation for cancellation
    document.addEventListener('DOMContentLoaded', function() {
      const cancelForms = document.querySelectorAll('form[method="POST"]');
      cancelForms.forEach(form => {
        const button = form.querySelector('button[type="submit"]');
        if (button && button.name === 'cancel_booking') {
          form.addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to cancel this booking? This action cannot be undone.')) {
              e.preventDefault();
            }
          });
        }
      });
    });
  </script>
</body>
</html>
[file content end]