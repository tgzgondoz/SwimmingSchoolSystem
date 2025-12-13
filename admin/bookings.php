<?php
// admin/bookings.php
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
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $success_message = "Booking status updated successfully!";
        } else {
            $error_message = "Failed to update booking status.";
        }
    }
    
    if (isset($_POST['delete_booking'])) {
        $booking_id = intval($_POST['booking_id']);
        
        $stmt = $conn->prepare("DELETE FROM bookings WHERE id = ?");
        $stmt->bind_param('i', $booking_id);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $success_message = "Booking deleted successfully!";
        } else {
            $error_message = "Failed to delete booking.";
        }
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
            $error_message = "This student is already booked for this class.";
        } else {
            $stmt = $conn->prepare("INSERT INTO bookings (user_id, class_id, status) VALUES (?, ?, ?)");
            $stmt->bind_param('iis', $user_id, $class_id, $status);
            
            if ($stmt->execute()) {
                $success_message = "Booking created successfully!";
                
                // Update class available slots
                $update_stmt = $conn->prepare("UPDATE classes SET slots_available = slots_available - 1 WHERE id = ? AND slots_available > 0");
                $update_stmt->bind_param('i', $class_id);
                $update_stmt->execute();
            } else {
                $error_message = "Failed to create booking: " . $conn->error;
            }
        }
    }
}

// Handle filtering
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : '';
$class_filter = isset($_GET['class_filter']) ? intval($_GET['class_filter']) : 0;

// Build query based on filters
$where_conditions = [];
$params = [];
$types = '';

if ($status_filter) {
    $where_conditions[] = "b.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($class_filter > 0) {
    $where_conditions[] = "b.class_id = ?";
    $params[] = $class_filter;
    $types .= 'i';
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(' AND ', $where_conditions);
}

// Get all bookings with user and class information
$query = "
    SELECT b.*, 
           u.name AS student_name, 
           u.email AS student_email,
           u.phone AS student_phone,
           c.title AS class_title,
           c.start_time AS class_start,
           c.end_time AS class_end,
           c.age_group AS class_age_group,
           i.name AS instructor_name
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    JOIN classes c ON b.class_id = c.id
    LEFT JOIN instructors i ON c.instructor_id = i.id
    $where_clause
    ORDER BY b.created_at DESC
";

if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $bookings = $result->fetch_all(MYSQLI_ASSOC);
} else {
    $bookings = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
}

// Get students for the add booking form
$students = $conn->query("SELECT id, name, email FROM users WHERE role = 'student' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Get classes for the add booking form and filter
$classes = $conn->query("SELECT id, title, start_time, slots_available FROM classes WHERE start_time >= NOW() ORDER BY start_time ASC")->fetch_all(MYSQLI_ASSOC);

// Get booking statistics
$total_bookings = $conn->query("SELECT COUNT(*) AS total FROM bookings")->fetch_assoc()['total'];
$confirmed_bookings = $conn->query("SELECT COUNT(*) AS total FROM bookings WHERE status = 'confirmed'")->fetch_assoc()['total'];
$pending_bookings = $conn->query("SELECT COUNT(*) AS total FROM bookings WHERE status = 'pending'")->fetch_assoc()['total'];
$upcoming_bookings = $conn->query("
    SELECT COUNT(*) AS total 
    FROM bookings b 
    JOIN classes c ON b.class_id = c.id 
    WHERE c.start_time >= NOW() AND b.status = 'confirmed'
")->fetch_assoc()['total'];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Manage Bookings - Admin Dashboard</title>
  
  <link href="../css/style.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
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
      margin-bottom: 20px;
    }
    
    .card-header {
      background: white;
      border-bottom: 1px solid #f1f5f9;
      padding: 16px 20px;
      border-radius: 12px 12px 0 0 !important;
    }
    
    .card-title {
      font-weight: 600;
      color: #1f2937;
      margin: 0;
      font-size: 18px;
    }
    
    .table th {
      font-weight: 600;
      color: #4b5563;
      font-size: 14px;
      border-top: none;
      background-color: #f8fafc;
    }
    
    .table td {
      font-size: 14px;
      vertical-align: middle;
    }
    
    .badge {
      font-size: 12px;
      font-weight: 500;
      padding: 4px 8px;
    }
    
    .btn-sm {
      font-size: 12px;
      padding: 4px 8px;
    }
    
    .action-buttons {
      display: flex;
      gap: 6px;
    }
    
    .empty-state {
      text-align: center;
      padding: 40px 20px;
      color: #6b7280;
    }
    
    .empty-state i {
      font-size: 3rem;
      margin-bottom: 16px;
      opacity: 0.5;
    }
    
    .alert {
      border-radius: 8px;
      border: none;
    }
    
    .filter-section {
      background: #f8f9fa;
      border-radius: 8px;
      padding: 15px;
      margin-bottom: 20px;
    }
    
    .booking-status-badge {
      font-size: 11px;
      padding: 4px 8px;
    }
    
    .upcoming-badge {
      background: #dcfce7;
      color: #166534;
      border: 1px solid #bbf7d0;
    }
    
    .completed-badge {
      background: #f3f4f6;
      color: #6b7280;
      border: 1px solid #e5e7eb;
    }
    
    .cancelled-badge {
      background: #fee2e2;
      color: #dc2626;
      border: 1px solid #fecaca;
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

      <!-- Header Section -->
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h2 class="fw-bold">Manage Bookings</h2>
          <p class="text-muted">View and manage all class bookings</p>
        </div>
        <div>
          <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBookingModal">
            <i class="bi bi-plus-circle me-2"></i>Add New Booking
          </button>
        </div>
      </div>

      <!-- Statistics Cards -->
      <div class="row mb-4">
        <div class="col-md-3">
          <div class="stat-card" style="border-left-color: #4e73df;">
            <div class="stat-title">Total Bookings</div>
            <div class="stat-value"><?= $total_bookings ?></div>
            <div class="stat-sub">All time bookings</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stat-card" style="border-left-color: #1cc88a;">
            <div class="stat-title">Confirmed</div>
            <div class="stat-value"><?= $confirmed_bookings ?></div>
            <div class="stat-sub">Active bookings</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stat-card" style="border-left-color: #f6c23e;">
            <div class="stat-title">Pending</div>
            <div class="stat-value"><?= $pending_bookings ?></div>
            <div class="stat-sub">Awaiting confirmation</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stat-card" style="border-left-color: #36b9cc;">
            <div class="stat-title">Upcoming</div>
            <div class="stat-value"><?= $upcoming_bookings ?></div>
            <div class="stat-sub">Future classes</div>
          </div>
        </div>
      </div>

      <!-- Filter Section -->
      <div class="filter-section">
        <form method="GET" class="row g-3 align-items-center">
          <div class="col-md-4">
            <label class="form-label fw-medium">Filter by Status:</label>
            <select class="form-select" name="status_filter" onchange="this.form.submit()">
              <option value="">All Status</option>
              <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
              <option value="confirmed" <?= $status_filter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
              <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
              <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-medium">Filter by Class:</label>
            <select class="form-select" name="class_filter" onchange="this.form.submit()">
              <option value="0">All Classes</option>
              <?php foreach($classes as $class): ?>
                <option value="<?= $class['id'] ?>" <?= $class_filter == $class['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($class['title']) ?> (<?= date('M j', strtotime($class['start_time'])) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <div class="d-flex justify-content-end gap-2">
              <input type="text" class="form-control" placeholder="Search bookings..." id="searchInput" style="max-width: 300px;">
              <button type="button" class="btn btn-outline-secondary" id="clearFilters">
                <i class="bi bi-arrow-clockwise"></i> Reset
              </button>
            </div>
          </div>
        </form>
      </div>

      <!-- Bookings Table -->
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="card-title mb-0">
            <?= $status_filter ? ucfirst($status_filter) . ' Bookings' : 'All Bookings' ?>
            <span class="badge bg-primary ms-2"><?= count($bookings) ?></span>
          </h5>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Class</th>
                  <th>Instructor</th>
                  <th>Schedule</th>
                  <th>Booking Date</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if(empty($bookings)): ?>
                  <tr>
                    <td colspan="7" class="text-center py-4">
                      <div class="empty-state">
                        <i class="bi bi-calendar-x"></i>
                        <h5>No Bookings Found</h5>
                        <p><?= $status_filter || $class_filter ? 'No bookings match your filters.' : 'Get started by creating your first booking.' ?></p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBookingModal">
                          <i class="bi bi-plus-circle me-2"></i>Add New Booking
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach($bookings as $booking): ?>
                    <tr>
                      <td>
                        <div class="fw-medium"><?= htmlspecialchars($booking['student_name']) ?></div>
                        <small class="text-muted"><?= htmlspecialchars($booking['student_email']) ?></small>
                      </td>
                      <td>
                        <div class="fw-medium"><?= htmlspecialchars($booking['class_title']) ?></div>
                        <small class="text-muted"><?= htmlspecialchars($booking['class_age_group']) ?></small>
                      </td>
                      <td>
                        <div class="fw-medium"><?= htmlspecialchars($booking['instructor_name'] ?? 'N/A') ?></div>
                      </td>
                      <td>
                        <div class="fw-medium"><?= date("M j, Y", strtotime($booking['class_start'])) ?></div>
                        <small class="text-muted">
                          <?= date("g:i A", strtotime($booking['class_start'])) ?> - <?= date("g:i A", strtotime($booking['class_end'])) ?>
                        </small>
                      </td>
                      <td>
                        <div class="fw-medium"><?= date("M j, Y", strtotime($booking['created_at'])) ?></div>
                        <small class="text-muted"><?= date("g:i A", strtotime($booking['created_at'])) ?></small>
                      </td>
                      <td>
                        <form method="POST" class="d-inline">
                          <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                          <select name="status" class="form-select form-select-sm booking-status" style="width: 120px;" onchange="this.form.submit()">
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
                          <button class="btn btn-outline-info btn-sm" title="View Details"
                                  data-bs-toggle="modal" data-bs-target="#viewBookingModal"
                                  onclick="viewBooking(<?= htmlspecialchars(json_encode($booking)) ?>)">
                            <i class="bi bi-eye"></i>
                          </button>
                          <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this booking? This action cannot be undone.');">
                            <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                            <button type="submit" name="delete_booking" class="btn btn-outline-danger btn-sm" title="Delete Booking">
                              <i class="bi bi-trash"></i>
                            </button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
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
                <select class="form-select" name="user_id" required>
                  <option value="">Select Student</option>
                  <?php foreach($students as $student): ?>
                    <option value="<?= $student['id'] ?>"><?= htmlspecialchars($student['name']) ?> (<?= htmlspecialchars($student['email']) ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Class *</label>
                <select class="form-select" name="class_id" required id="classSelect">
                  <option value="">Select Class</option>
                  <?php foreach($classes as $class): ?>
                    <option value="<?= $class['id'] ?>" data-slots="<?= $class['slots_available'] ?>">
                      <?= htmlspecialchars($class['title']) ?> 
                      (<?= date('M j, Y g:i A', strtotime($class['start_time'])) ?>)
                      - <?= $class['slots_available'] ?> slots left
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text" id="slotsInfo">Select a class to see available slots</div>
              </div>
              <div class="col-md-6">
                <label class="form-label">Status *</label>
                <select class="form-select" name="status" required>
                  <option value="pending">Pending</option>
                  <option value="confirmed" selected>Confirmed</option>
                  <option value="cancelled">Cancelled</option>
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
              <label class="form-label fw-medium">Student Information</label>
              <div class="border rounded p-3">
                <p class="mb-1"><strong>Name:</strong> <span id="view_student_name"></span></p>
                <p class="mb-1"><strong>Email:</strong> <span id="view_student_email"></span></p>
                <p class="mb-0"><strong>Phone:</strong> <span id="view_student_phone"></span></p>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Class Information</label>
              <div class="border rounded p-3">
                <p class="mb-1"><strong>Class:</strong> <span id="view_class_title"></span></p>
                <p class="mb-1"><strong>Instructor:</strong> <span id="view_instructor_name"></span></p>
                <p class="mb-1"><strong>Age Group:</strong> <span id="view_age_group"></span></p>
                <p class="mb-0"><strong>Schedule:</strong> <span id="view_schedule"></span></p>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Booking Information</label>
              <div class="border rounded p-3">
                <p class="mb-1"><strong>Status:</strong> <span id="view_status"></span></p>
                <p class="mb-1"><strong>Booking Date:</strong> <span id="view_booking_date"></span></p>
                <p class="mb-0"><strong>Booking ID:</strong> <span id="view_booking_id"></span></p>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Class Timing</label>
              <div class="border rounded p-3">
                <p class="mb-1"><strong>Start Time:</strong> <span id="view_start_time"></span></p>
                <p class="mb-1"><strong>End Time:</strong> <span id="view_end_time"></span></p>
                <p class="mb-0"><strong>Duration:</strong> <span id="view_duration"></span></p>
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
    // Simple search functionality
    document.getElementById('searchInput').addEventListener('input', function(e) {
      const searchTerm = e.target.value.toLowerCase();
      const rows = document.querySelectorAll('tbody tr');
      
      rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
      });
    });

    // Clear filters
    document.getElementById('clearFilters').addEventListener('click', function() {
      window.location.href = 'bookings.php';
    });

    // Class slots information
    document.getElementById('classSelect').addEventListener('change', function() {
      const selectedOption = this.options[this.selectedIndex];
      const slotsAvailable = selectedOption.getAttribute('data-slots');
      const slotsInfo = document.getElementById('slotsInfo');
      
      if (slotsAvailable !== null) {
        slotsInfo.textContent = `${slotsAvailable} slots available`;
        if (slotsAvailable === '0') {
          slotsInfo.className = 'form-text text-danger';
        } else {
          slotsInfo.className = 'form-text text-success';
        }
      } else {
        slotsInfo.textContent = 'Select a class to see available slots';
        slotsInfo.className = 'form-text text-muted';
      }
    });

    // View booking function
    function viewBooking(booking) {
      document.getElementById('view_student_name').textContent = booking.student_name;
      document.getElementById('view_student_email').textContent = booking.student_email;
      document.getElementById('view_student_phone').textContent = booking.student_phone || 'Not provided';
      document.getElementById('view_class_title').textContent = booking.class_title;
      document.getElementById('view_instructor_name').textContent = booking.instructor_name || 'N/A';
      document.getElementById('view_age_group').textContent = booking.class_age_group;
      document.getElementById('view_booking_id').textContent = booking.id;
      
      // Format dates
      const startTime = new Date(booking.class_start);
      const endTime = new Date(booking.class_end);
      const bookingDate = new Date(booking.created_at);
      
      document.getElementById('view_schedule').textContent = startTime.toLocaleDateString() + ' ' + startTime.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
      document.getElementById('view_start_time').textContent = startTime.toLocaleString();
      document.getElementById('view_end_time').textContent = endTime.toLocaleString();
      document.getElementById('view_booking_date').textContent = bookingDate.toLocaleString();
      
      // Calculate duration
      const duration = (endTime - startTime) / (1000 * 60 * 60);
      document.getElementById('view_duration').textContent = duration + ' hours';
      
      // Status with badge
      const statusColors = {
        'pending': 'warning',
        'confirmed': 'success',
        'cancelled': 'danger',
        'completed': 'secondary'
      };
      const statusBadge = `<span class="badge bg-${statusColors[booking.status]}">${booking.status.charAt(0).toUpperCase() + booking.status.slice(1)}</span>`;
      document.getElementById('view_status').innerHTML = statusBadge;
    }

    // Apply saved theme
    document.addEventListener('DOMContentLoaded', function() {
      const savedTheme = localStorage.getItem('theme') || 'light';
      if (savedTheme === 'dark') {
        document.body.classList.add('dark-mode');
      }
    });
  </script>
</body>
</html>