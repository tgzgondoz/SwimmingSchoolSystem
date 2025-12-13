<?php
// admin/students.php
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
            $success_message = "Student deleted successfully!";
        } else {
            $error_message = "Failed to delete student.";
        }
    }
    
    if (isset($_POST['update_student'])) {
        $student_id = intval($_POST['student_id']);
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $age = intval($_POST['age']);
        $emergency_contact = trim($_POST['emergency_contact']);
        $medical_notes = trim($_POST['medical_notes']);
        
        $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, phone = ?, age = ?, emergency_contact = ?, medical_notes = ? WHERE id = ? AND role = 'student'");
        $stmt->bind_param('sssisssi', $name, $email, $phone, $age, $emergency_contact, $medical_notes, $student_id);
        
        if ($stmt->execute()) {
            $success_message = "Student updated successfully!";
        } else {
            $error_message = "Failed to update student.";
        }
    }
    
    if (isset($_POST['add_student'])) {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $age = intval($_POST['age']);
        $emergency_contact = trim($_POST['emergency_contact']);
        $medical_notes = trim($_POST['medical_notes']);
        $role = 'student';
        
        $stmt = $conn->prepare("INSERT INTO users (name, email, phone, age, emergency_contact, medical_notes, role, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param('ssissss', $name, $email, $phone, $age, $emergency_contact, $medical_notes, $role);
        
        if ($stmt->execute()) {
            $success_message = "Student added successfully!";
        } else {
            $error_message = "Failed to add student.";
        }
    }
}

// Handle class filtering
$class_filter = isset($_GET['class_filter']) ? intval($_GET['class_filter']) : 0;

// Build query based on filter
if ($class_filter > 0) {
    // Get students enrolled in specific class
    $students = $conn->query("
        SELECT u.*, 
               GROUP_CONCAT(DISTINCT c.title SEPARATOR ', ') as enrolled_classes
        FROM users u
        LEFT JOIN enrollments e ON u.id = e.student_id
        LEFT JOIN classes c ON e.class_id = c.id
        WHERE u.role = 'student' AND e.class_id = $class_filter
        GROUP BY u.id
        ORDER BY u.name ASC
    ")->fetch_all(MYSQLI_ASSOC);
} else {
    // Get all students with their enrolled classes
    $students = $conn->query("
        SELECT u.*, 
               GROUP_CONCAT(DISTINCT c.title SEPARATOR ', ') as enrolled_classes
        FROM users u
        LEFT JOIN enrollments e ON u.id = e.student_id
        LEFT JOIN classes c ON e.class_id = c.id
        WHERE u.role = 'student'
        GROUP BY u.id
        ORDER BY u.name ASC
    ")->fetch_all(MYSQLI_ASSOC);
}

// Get all classes for the filter dropdown
$classes = $conn->query("SELECT id, title FROM classes ORDER BY title ASC")->fetch_all(MYSQLI_ASSOC);

// Get student statistics
$total_students = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'student'")->fetch_assoc()['total'];
$active_students = $conn->query("SELECT COUNT(DISTINCT student_id) AS total FROM enrollments WHERE status = 'active'")->fetch_assoc()['total'];
$new_this_month = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'student' AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())")->fetch_assoc()['total'];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Manage Students - Admin Dashboard</title>
  
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
    
    /* Alert messages */
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
  </style>
</head>
<body>

  <div class="sidebar"><?php include 'components/sidebar.php'; ?></div>

  <div class="main-content">

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
          <h2 class="fw-bold">Manage Students</h2>
          <p class="text-muted">View and manage all swimming students</p>
        </div>
        <div>
          <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStudentModal">
            <i class="bi bi-plus-circle me-2"></i>Add New Student
          </button>
        </div>
      </div>

      <!-- Filter Section -->
      <div class="filter-section">
        <form method="GET" class="row g-2 align-items-end">
          <div class="col-md-5">
        <label class="form-label fw-medium">Filter by Class:</label>
        <select class="form-select" name="class_filter" onchange="this.form.submit()">
          <option value="0" <?= $class_filter == 0 ? 'selected' : '' ?>>All Students</option>
          <?php foreach($classes as $class): ?>
            <option value="<?= $class['id'] ?>" <?= $class_filter == $class['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($class['title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
          </div>
          <div class="col-md-5">
        <label class="form-label fw-medium">Search:</label>
        <input type="text" class="form-control" placeholder="Search students..." id="searchInput">
          </div>
        </form>
      </div>

      <!-- Students Table -->
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="card-title mb-0">
            <?= $class_filter > 0 ? 'Students in Selected Class' : 'All Students' ?>
            <span class="badge bg-primary ms-2"><?= count($students) ?></span>
          </h5>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead>
                <tr>
                  <th>Student Name</th>
                  <th>Contact Info</th>
                  <th>Age</th>
                  <th>Emergency Contact</th>
                  <th>Enrolled Classes</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if(empty($students)): ?>
                  <tr>
                    <td colspan="7" class="text-center py-4">
                      <div class="empty-state">
                        <i class="bi bi-person-x"></i>
                        <h5>No Students Found</h5>
                        <p><?= $class_filter > 0 ? 'No students enrolled in this class.' : 'Get started by adding your first student.' ?></p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStudentModal">
                          <i class="bi bi-plus-circle me-2"></i>Add New Student
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach($students as $student): ?>
                    <tr>
                      <td>
                        <div class="fw-medium"><?= htmlspecialchars($student['name']) ?></div>
                        <small class="text-muted">ID: <?= $student['id'] ?></small>
                      </td>
                      <td>
                        <div class="fw-medium"><?= htmlspecialchars($student['email']) ?></div>
                        <small class="text-muted"><?= htmlspecialchars($student['phone'] ?? '') ?></small>
                      </td>
                      <td>
                        <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25">
                          <?= isset($student['age']) && $student['age'] ? $student['age'] . ' years' : 'N/A' ?>
                        </span>
                      </td>
                      <td>
                        <div class="fw-medium"><?= htmlspecialchars($student['emergency_contact'] ?? '') ?></div>
                      </td>
                      <td>
                        <?php if(!empty($student['enrolled_classes'])): ?>
                          <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25">
                            <?= htmlspecialchars($student['enrolled_classes']) ?>
                          </span>
                        <?php else: ?>
                          <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25">
                            Not Enrolled
                          </span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="badge bg-success">Active</span>
                      </td>
                      <td>
                        <div class="action-buttons">
                          <button class="btn btn-outline-primary btn-sm" title="Edit Student" 
                                  data-bs-toggle="modal" data-bs-target="#editStudentModal"
                                  onclick="editStudent(<?= htmlspecialchars(json_encode($student)) ?>)">
                            <i class="bi bi-pencil"></i>
                          </button>
                          <button class="btn btn-outline-info btn-sm" title="View Details"
                                  data-bs-toggle="modal" data-bs-target="#viewStudentModal"
                                  onclick="viewStudent(<?= htmlspecialchars(json_encode($student)) ?>)">
                            <i class="bi bi-eye"></i>
                          </button>
                          <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this student? This action cannot be undone.');">
                            <input type="hidden" name="student_id" value="<?= $student['id'] ?>">
                            <button type="submit" name="delete_student" class="btn btn-outline-danger btn-sm" title="Delete Student">
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
                <textarea class="form-control" name="medical_notes" rows="3" placeholder="Any medical conditions or allergies..."></textarea>
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
              <div class="col-12">
                <label class="form-label">Emergency Contact</label>
                <input type="text" class="form-control" name="emergency_contact" id="edit_emergency_contact" placeholder="Name and phone number">
              </div>
              <div class="col-12">
                <label class="form-label">Medical Notes</label>
                <textarea class="form-control" name="medical_notes" id="edit_medical_notes" rows="3" placeholder="Any medical conditions or allergies..."></textarea>
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
              <label class="form-label fw-medium">Full Name</label>
              <p class="form-control-plaintext" id="view_name"></p>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Email Address</label>
              <p class="form-control-plaintext" id="view_email"></p>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Phone Number</label>
              <p class="form-control-plaintext" id="view_phone"></p>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Age</label>
              <p class="form-control-plaintext" id="view_age"></p>
            </div>
            <div class="col-12">
              <label class="form-label fw-medium">Emergency Contact</label>
              <p class="form-control-plaintext" id="view_emergency_contact"></p>
            </div>
            <div class="col-12">
              <label class="form-label fw-medium">Medical Notes</label>
              <p class="form-control-plaintext" id="view_medical_notes"></p>
            </div>
            <div class="col-12">
              <label class="form-label fw-medium">Enrolled Classes</label>
              <p class="form-control-plaintext" id="view_enrolled_classes"></p>
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

    // Edit student function
    function editStudent(student) {
      document.getElementById('edit_student_id').value = student.id;
      document.getElementById('edit_name').value = student.name;
      document.getElementById('edit_email').value = student.email;
      document.getElementById('edit_phone').value = student.phone || '';
      document.getElementById('edit_age').value = student.age;
      document.getElementById('edit_emergency_contact').value = student.emergency_contact || '';
      document.getElementById('edit_medical_notes').value = student.medical_notes || '';
    }

    // View student function
    function viewStudent(student) {
      document.getElementById('view_name').textContent = student.name;
      document.getElementById('view_email').textContent = student.email;
      document.getElementById('view_phone').textContent = student.phone || 'Not provided';
      document.getElementById('view_age').textContent = student.age + ' years';
      document.getElementById('view_emergency_contact').textContent = student.emergency_contact || 'Not provided';
      document.getElementById('view_medical_notes').textContent = student.medical_notes || 'None';
      document.getElementById('view_enrolled_classes').textContent = student.enrolled_classes || 'Not enrolled in any classes';
    }
  </script>
</body>
</html>