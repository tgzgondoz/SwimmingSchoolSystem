[file name]: student/classes.php
[file content begin]
<?php
// student/classes.php - Browse and Book Classes
session_start();
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

requireRole('student');
$user = getCurrentUser($conn);
$student_id = $_SESSION['user_id'];

// Handle class booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_class'])) {
    $class_id = intval($_POST['class_id']);
    
    // Check if already booked
    $check_stmt = $conn->prepare("SELECT id FROM bookings WHERE user_id = ? AND class_id = ?");
    $check_stmt->bind_param('ii', $student_id, $class_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $error_message = "You have already booked this class.";
    } else {
        // Check if class has available slots
        $class_stmt = $conn->prepare("SELECT slots_available FROM classes WHERE id = ?");
        $class_stmt->bind_param('i', $class_id);
        $class_stmt->execute();
        $class_result = $class_stmt->get_result();
        $class = $class_result->fetch_assoc();
        
        if ($class['slots_available'] <= 0) {
            $error_message = "This class is fully booked.";
        } else {
            // Create booking
            $stmt = $conn->prepare("INSERT INTO bookings (user_id, class_id, status) VALUES (?, ?, 'pending')");
            $stmt->bind_param('ii', $student_id, $class_id);
            
            if ($stmt->execute()) {
                // Update available slots
                $update_stmt = $conn->prepare("UPDATE classes SET slots_available = slots_available - 1 WHERE id = ?");
                $update_stmt->bind_param('i', $class_id);
                $update_stmt->execute();
                
                $success_message = "Class booked successfully! Awaiting confirmation.";
            } else {
                $error_message = "Failed to book class: " . $conn->error;
            }
        }
    }
}

// Get filter parameters
$age_group_filter = isset($_GET['age_group']) ? $_GET['age_group'] : '';
$instructor_filter = isset($_GET['instructor']) ? intval($_GET['instructor']) : 0;
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';

// Build query
$where_conditions = ["c.start_time >= NOW()", "c.slots_available > 0"];
$params = [];
$types = '';

if ($age_group_filter) {
    $where_conditions[] = "c.age_group = ?";
    $params[] = $age_group_filter;
    $types .= 's';
}

if ($instructor_filter > 0) {
    $where_conditions[] = "c.instructor_id = ?";
    $params[] = $instructor_filter;
    $types .= 'i';
}

if ($date_filter) {
    $where_conditions[] = "DATE(c.start_time) = ?";
    $params[] = $date_filter;
    $types .= 's';
}

// Get classes
$query = "
    SELECT c.*, i.name as instructor_name, i.bio as instructor_bio,
           (SELECT COUNT(*) FROM bookings WHERE class_id = c.id AND user_id = ?) as is_booked
    FROM classes c
    LEFT JOIN instructors i ON c.instructor_id = i.id
    WHERE " . implode(' AND ', $where_conditions) . "
    ORDER BY c.start_time ASC
";

array_unshift($params, $student_id);
array_unshift($params, $types . 'i');
$types = 'i' . $types;

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get age groups for filter
$age_groups = $conn->query("SELECT DISTINCT age_group FROM classes ORDER BY age_group")->fetch_all(MYSQLI_ASSOC);

// Get instructors for filter
$instructors = $conn->query("SELECT id, name FROM instructors ORDER BY name")->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Browse Classes - Student Dashboard</title>
  
  <link href="../css/style.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    .dashboard-container {
      padding: 20px;
      max-width: 1400px;
      margin: 0 auto;
    }

    .filter-card {
      background: white;
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
      margin-bottom: 24px;
    }

    .class-card {
      background: white;
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
      margin-bottom: 24px;
      transition: transform 0.3s, box-shadow 0.3s;
      height: 100%;
    }

    .class-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }

    .class-header {
      padding: 20px;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
    }

    .class-body {
      padding: 20px;
    }

    .class-title {
      font-size: 20px;
      font-weight: 600;
      margin-bottom: 10px;
      color: #1f2937;
    }

    .class-meta {
      display: flex;
      align-items: center;
      gap: 15px;
      margin-bottom: 15px;
      font-size: 14px;
      color: #6b7280;
    }

    .class-meta i {
      margin-right: 5px;
    }

    .class-description {
      color: #6b7280;
      margin-bottom: 20px;
      line-height: 1.6;
    }

    .badge {
      font-weight: 500;
      padding: 6px 10px;
      border-radius: 6px;
    }

    .btn-book {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      border: none;
      border-radius: 8px;
      padding: 10px 20px;
      font-weight: 500;
      transition: all 0.3s;
    }

    .btn-book:hover {
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
    }

    .btn-book:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }

    .slot-indicator {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 5px 10px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 500;
    }

    .slots-high {
      background: #dcfce7;
      color: #166534;
    }

    .slots-medium {
      background: #fef3c7;
      color: #92400e;
    }

    .slots-low {
      background: #fee2e2;
      color: #dc2626;
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

    .form-control, .form-select {
      border-radius: 8px;
      border: 1px solid #e5e7eb;
      padding: 10px;
    }

    .form-control:focus, .form-select:focus {
      border-color: #667eea;
      box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
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
        <h1 class="fw-bold">Browse Classes</h1>
        <p class="text-muted">Find and book swimming classes that match your level and schedule</p>
      </div>

      <!-- Filter Section -->
      <div class="filter-card">
        <form method="GET" class="row g-3 align-items-end">
          <div class="col-md-3">
            <label class="form-label">Age Group</label>
            <select class="form-select" name="age_group">
              <option value="">All Age Groups</option>
              <?php foreach($age_groups as $group): ?>
                <option value="<?= $group['age_group'] ?>" <?= $age_group_filter == $group['age_group'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($group['age_group']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Instructor</label>
            <select class="form-select" name="instructor">
              <option value="0">All Instructors</option>
              <?php foreach($instructors as $instructor): ?>
                <option value="<?= $instructor['id'] ?>" <?= $instructor_filter == $instructor['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($instructor['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div