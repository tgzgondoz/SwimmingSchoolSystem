<?php
// student/bookings.php
session_start();
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

requireRole('student');
$user = getCurrentUser($conn);
$student_id = $user['id'];

$success_message = '';
$error_message = '';

// Handle booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_class'])) {
    $class_id = intval($_POST['class_id']);
    
    // Check if already enrolled
    $check = $conn->query("SELECT id FROM enrollments WHERE student_id = $student_id AND class_id = $class_id");
    
    if ($check->num_rows === 0) {
        $stmt = $conn->prepare("INSERT INTO enrollments (student_id, class_id, enrollment_date, status) VALUES (?, ?, NOW(), 'active')");
        $stmt->bind_param('ii', $student_id, $class_id);
        
        if ($stmt->execute()) {
            $success_message = "Successfully enrolled in class!";
        } else {
            $error_message = "Failed to enroll in class.";
        }
    } else {
        $error_message = "You are already enrolled in this class.";
    }
}

// Get available classes
$available_classes = $conn->query("
    SELECT c.*, i.name as instructor_name,
           (SELECT COUNT(*) FROM enrollments WHERE class_id = c.id AND status = 'active') as enrolled_count
    FROM classes c 
    LEFT JOIN instructors i ON c.instructor_id = i.id 
    WHERE c.start_time > NOW()
    ORDER BY c.start_time ASC
")->fetch_all(MYSQLI_ASSOC);

// Get enrolled classes
$enrolled_classes = $conn->query("
    SELECT c.*, i.name as instructor_name
    FROM classes c 
    JOIN enrollments e ON c.id = e.class_id 
    LEFT JOIN instructors i ON c.instructor_id = i.id 
    WHERE e.student_id = $student_id AND e.status = 'active'
    ORDER BY c.start_time ASC
")->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Class Bookings - Student Portal</title>
  
  <link href="../css/style.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root { --bg: #f6f8fb; --card: #ffffff; --text: #111827; --muted: #6b7280; --accent: #2563eb; --radius: 12px; --shadow: 0 6px 18px rgba(15,23,42,0.06); }
    body { background: var(--bg); color: var(--text); font-family: Inter, ui-sans-serif, system-ui; }
    .dashboard-container { padding: 28px; max-width: 1200px; margin: 0 auto 80px; }
    .card { background: var(--card); border-radius: var(--radius); box-shadow: var(--shadow); border: none; }
    .card-header { background: transparent; border-bottom: 1px solid rgba(15,23,42,0.05); padding: 18px 20px; }
    .card-title { font-weight: 600; color: var(--text); margin: 0; }
    .class-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
    .class-item { background: var(--card); border-radius: var(--radius); padding: 16px; border: 1px solid rgba(15,23,42,0.05); transition: transform 0.2s; }
    .class-item:hover { transform: translateY(-4px); box-shadow: var(--shadow); }
    .class-item.enrolled { border-left: 4px solid #16a34a; }
    .empty-state { text-align: center; padding: 40px 20px; color: var(--muted); }
    .empty-state i { font-size: 3rem; margin-bottom: 16px; opacity: 0.5; }
    .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
    .alert-danger { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; }
    .alert-success { background: #dcfce7; border: 1px solid #bbf7d0; color: #166534; }
  </style>
</head>
<body>

  <div class="sidebar"><?php include 'components/sidebar.php'; ?></div>

  <div class="main-content">
    <div class="dashboard-container">
      <div class="mb-4">
        <h2 class="fw-bold mb-1">Class Bookings</h2>
        <p class="text-muted">Browse and enroll in available swimming classes</p>
      </div>

      <?php if ($success_message): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= $success_message ?></div>
      <?php endif; ?>
      
      <?php if ($error_message): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle me-2"></i><?= $error_message ?></div>
      <?php endif; ?>

      <!-- My Enrollments -->
      <div class="card mb-4">
        <div class="card-header">
          <h5 class="card-title">My Enrolled Classes</h5>
        </div>
        <div class="card-body">
          <?php if (empty($enrolled_classes)): ?>
            <div class="empty-state"><i class="bi bi-inbox"></i><h5>No Enrolled Classes</h5></div>
          <?php else: ?>
            <div class="class-grid">
              <?php foreach ($enrolled_classes as $class): ?>
                <div class="class-item enrolled">
                  <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="fw-bold"><?= htmlspecialchars($class['title']) ?></div>
                    <span class="badge bg-success">Enrolled</span>
                  </div>
                  <div class="small text-muted">
                    <div><i class="bi bi-calendar me-1"></i><?= date('M j, Y g:i A', strtotime($class['start_time'])) ?></div>
                    <div><i class="bi bi-person me-1"></i><?= htmlspecialchars($class['instructor_name'] ?? 'TBA') ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Available Classes -->
      <div class="card">
        <div class="card-header">
          <h5 class="card-title">Available Classes</h5>
        </div>
        <div class="card-body">
          <?php if (empty($available_classes)): ?>
            <div class="empty-state"><i class="bi bi-calendar-x"></i><h5>No Available Classes</h5></div>
          <?php else: ?>
            <div class="class-grid">
              <?php foreach ($available_classes as $class): ?>
                <div class="class-item">
                  <div class="fw-bold mb-2"><?= htmlspecialchars($class['title']) ?></div>
                  <div class="small text-muted mb-3">
                    <div><i class="bi bi-calendar me-1"></i><?= date('M j, Y g:i A', strtotime($class['start_time'])) ?></div>
                    <div><i class="bi bi-person me-1"></i><?= htmlspecialchars($class['instructor_name'] ?? 'TBA') ?></div>
                    <div><i class="bi bi-people me-1"></i><?= $class['enrolled_count'] ?> / <?= $class['slots_total'] ?> enrolled</div>
                  </div>
                  <form method="POST" class="d-grid">
                    <input type="hidden" name="class_id" value="<?= $class['id'] ?>">
                    <button type="submit" name="enroll_class" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Enroll</button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>