<?php
// admin/classes.php
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
            $success_message = "Class deleted successfully!";
        } else {
            $error_message = "Failed to delete class.";
        }
    }
    
    if (isset($_POST['add_class'])) {
        $title = $_POST['title'];
        $age_group = $_POST['age_group'];
        $description = $_POST['description'];
        $instructor_id = $_POST['instructor_id'];
        $slots_total = $_POST['slots_total'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $price = $_POST['price'];
        
        $stmt = $conn->prepare("INSERT INTO classes (title, age_group, description, instructor_id, slots_total, slots_available, start_time, end_time, price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sssiisssd', $title, $age_group, $description, $instructor_id, $slots_total, $slots_total, $start_time, $end_time, $price);
        
        if ($stmt->execute()) {
            $success_message = "Class created successfully!";
        } else {
            $error_message = "Failed to create class: " . $conn->error;
        }
    }
    
    if (isset($_POST['update_class'])) {
        $class_id = $_POST['class_id'];
        $title = $_POST['title'];
        $age_group = $_POST['age_group'];
        $description = $_POST['description'];
        $instructor_id = $_POST['instructor_id'];
        $slots_total = $_POST['slots_total'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $price = $_POST['price'];
        
        $stmt = $conn->prepare("UPDATE classes SET title = ?, age_group = ?, description = ?, instructor_id = ?, slots_total = ?, start_time = ?, end_time = ?, price = ? WHERE id = ?");
        $stmt->bind_param('sssiissdi', $title, $age_group, $description, $instructor_id, $slots_total, $start_time, $end_time, $price, $class_id);
        
        if ($stmt->execute()) {
            $success_message = "Class updated successfully!";
        } else {
            $error_message = "Failed to update class: " . $conn->error;
        }
    }
}

// Get all classes with instructor names
$classes = $conn->query("
    SELECT c.*, i.name AS instructor_name 
    FROM classes c 
    LEFT JOIN instructors i ON c.instructor_id = i.id 
    ORDER BY c.start_time DESC
")->fetch_all(MYSQLI_ASSOC);

// Get instructors for the add class form
$instructors = $conn->query("SELECT id, name FROM instructors")->fetch_all(MYSQLI_ASSOC);

// Get class statistics
$total_classes = $conn->query("SELECT COUNT(*) AS total FROM classes")->fetch_assoc()['total'];
$upcoming_classes = $conn->query("SELECT COUNT(*) AS total FROM classes WHERE start_time >= NOW()")->fetch_assoc()['total'];
$active_classes = $conn->query("SELECT COUNT(*) AS total FROM classes WHERE start_time <= NOW() AND end_time >= NOW()")->fetch_assoc()['total'];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Manage Classes - Admin Dashboard</title>
  
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
    
    .class-status-badge {
      font-size: 11px;
      padding: 3px 8px;
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
      padding: 12px;
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
          <h2 class="fw-bold">Manage Classes</h2>
          <p class="text-muted">View and manage all swimming classes</p>
        </div>
        <div>
          <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClassModal">
            <i class="bi bi-plus-circle me-2"></i>Add New Class
          </button>
        </div>
      </div>

      <!-- Filter Section -->
      <div class="filter-section">
        <form class="row g-2 align-items-end" method="GET">
          <div class="col-md-6">
            <label class="form-label fw-medium">Search Classes:</label>
            <input type="text" class="form-control" placeholder="Search classes..." id="searchInput" />
          </div>
          <div class="col-md-2 d-flex align-items-end">
            <button type="button" class="btn btn-outline-secondary w-100" id="clearFilters">
              <i class="bi bi-arrow-clockwise me-1"></i>Clear
            </button>
          </div>
        </form>
      </div>

      <!-- Classes Table -->
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="card-title mb-0">All Classes
            <span class="badge bg-primary ms-2"><?= count($classes) ?></span>
          </h5>
        </div>
         <div class="card-body p-0">
           <div class="table-responsive">
             <table class="table table-hover mb-0">
              <thead>
                <tr>
                  <th>Class Name</th>
                  <th>Instructor</th>
                  <th>Age Group</th>
                  <th>Schedule</th>
                  <th>Slots</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if(empty($classes)): ?>
                  <tr>
                    <td colspan="7" class="text-center py-4">
                      <div class="empty-state">
                        <i class="bi bi-calendar-x"></i>
                        <h5>No Classes Found</h5>
                        <p>Get started by creating your first class.</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClassModal">
                          <i class="bi bi-plus-circle me-2"></i>Add New Class
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach($classes as $class): ?>
                    <tr>
                      <td>
                        <div class="fw-medium"><?= htmlspecialchars($class['title']) ?></div>
                        <small class="text-muted"><?= htmlspecialchars($class['description'] ?? 'No description') ?></small>
                      </td>
                      <td>
                        <div class="fw-medium"><?= htmlspecialchars($class['instructor_name'] ?? 'Unassigned') ?></div>
                      </td>
                      <td>
                        <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25">
                          <?= htmlspecialchars($class['age_group']) ?>
                        </span>
                      </td>
                      <td>
                        <div class="fw-medium"><?= date("M j, Y", strtotime($class['start_time'])) ?></div>
                        <small class="text-muted">
                          <?= date("g:i A", strtotime($class['start_time'])) ?> - <?= date("g:i A", strtotime($class['end_time'])) ?>
                        </small>
                      </td>
                      <td>
                        <div class="fw-medium">
                          <?= $class['slots_available'] ?>/<?= $class['slots_total'] ?>
                        </div>
                        <div class="progress" style="height: 4px; width: 80px;">
                          <?php 
                            $percentage = ($class['slots_total'] - $class['slots_available']) / $class['slots_total'] * 100;
                            $color = $percentage >= 90 ? 'bg-danger' : ($percentage >= 75 ? 'bg-warning' : 'bg-success');
                          ?>
                          <div class="progress-bar <?= $color ?>" style="width: <?= $percentage ?>%"></div>
                        </div>
                      </td>
                      <td>
                        <?php
                          $status_class = '';
                          $status_text = '';
                          $current_time = time();
                          $start_time = strtotime($class['start_time']);
                          $end_time = strtotime($class['end_time']);
                          
                          if ($current_time > $end_time) {
                            $status_class = 'bg-secondary';
                            $status_text = 'Completed';
                          } elseif ($current_time >= $start_time && $current_time <= $end_time) {
                            $status_class = 'bg-success';
                            $status_text = 'In Progress';
                          } elseif ($current_time < $start_time) {
                            $status_class = 'bg-primary';
                            $status_text = 'Upcoming';
                          } else {
                            $status_class = 'bg-warning';
                            $status_text = 'Scheduled';
                          }
                        ?>
                        <span class="badge class-status-badge <?= $status_class ?>">
                          <?= $status_text ?>
                        </span>
                      </td>
                      <td>
                        <div class="action-buttons">
                          <button class="btn btn-outline-primary btn-sm" title="Edit Class"
                                  data-bs-toggle="modal" data-bs-target="#editClassModal"
                                  onclick="editClass(<?= htmlspecialchars(json_encode($class)) ?>)">
                            <i class="bi bi-pencil"></i>
                          </button>
                          <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this class?');">
                            <input type="hidden" name="class_id" value="<?= $class['id'] ?>">
                            <button type="submit" name="delete_class" class="btn btn-outline-danger btn-sm" title="Delete Class">
                              <i class="bi bi-trash"></i>
                            </button>
                          </form>
                          <button class="btn btn-outline-info btn-sm" title="View Details"
                                  data-bs-toggle="modal" data-bs-target="#viewClassModal"
                                  onclick="viewClass(<?= htmlspecialchars(json_encode($class)) ?>)">
                            <i class="bi bi-eye"></i>
                          </button>
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
                <label class="form-label">Class Title</label>
                <input type="text" class="form-control" name="title" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Age Group</label>
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
                <textarea class="form-control" name="description" rows="3"></textarea>
              </div>
              <div class="col-md-6">
                <label class="form-label">Instructor</label>
                <select class="form-select" name="instructor_id" required>
                  <option value="">Select Instructor</option>
                  <?php foreach($instructors as $instructor): ?>
                    <option value="<?= $instructor['id'] ?>"><?= htmlspecialchars($instructor['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Total Slots</label>
                <input type="number" class="form-control" name="slots_total" min="1" max="50" value="10" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Start Time</label>
                <input type="datetime-local" class="form-control" name="start_time" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">End Time</label>
                <input type="datetime-local" class="form-control" name="end_time" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Price</label>
                <div class="input-group">
                  <span class="input-group-text">$</span>
                  <input type="number" class="form-control" name="price" step="0.01" min="0" value="50.00" required>
                </div>
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
                <label class="form-label">Class Title</label>
                <input type="text" class="form-control" name="title" id="edit_title" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Age Group</label>
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
                <label class="form-label">Instructor</label>
                <select class="form-select" name="instructor_id" id="edit_instructor_id" required>
                  <option value="">Select Instructor</option>
                  <?php foreach($instructors as $instructor): ?>
                    <option value="<?= $instructor['id'] ?>"><?= htmlspecialchars($instructor['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Total Slots</label>
                <input type="number" class="form-control" name="slots_total" id="edit_slots_total" min="1" max="50" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Start Time</label>
                <input type="datetime-local" class="form-control" name="start_time" id="edit_start_time" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">End Time</label>
                <input type="datetime-local" class="form-control" name="end_time" id="edit_end_time" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Price</label>
                <div class="input-group">
                  <span class="input-group-text">$</span>
                  <input type="number" class="form-control" name="price" id="edit_price" step="0.01" min="0" required>
                </div>
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
              <label class="form-label fw-medium">Class Title</label>
              <p class="form-control-plaintext" id="view_title"></p>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Age Group</label>
              <p class="form-control-plaintext" id="view_age_group"></p>
            </div>
            <div class="col-12">
              <label class="form-label fw-medium">Description</label>
              <p class="form-control-plaintext" id="view_description"></p>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Instructor</label>
              <p class="form-control-plaintext" id="view_instructor"></p>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Total Slots</label>
              <p class="form-control-plaintext" id="view_slots"></p>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Start Time</label>
              <p class="form-control-plaintext" id="view_start_time"></p>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">End Time</label>
              <p class="form-control-plaintext" id="view_end_time"></p>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Price</label>
              <p class="form-control-plaintext" id="view_price"></p>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Status</label>
              <p class="form-control-plaintext" id="view_status"></p>
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
      document.getElementById('searchInput').value = '';
      const rows = document.querySelectorAll('tbody tr');
      rows.forEach(row => row.style.display = '');
    });

    // Set default datetime values for the modal
    document.addEventListener('DOMContentLoaded', function() {
      const now = new Date();
      const startTime = new Date(now.getTime() + 24 * 60 * 60 * 1000); // Tomorrow
      const endTime = new Date(startTime.getTime() + 60 * 60 * 1000); // +1 hour
      
      // Format for datetime-local input
      const formatDateTime = (date) => {
        return date.toISOString().slice(0, 16);
      };
      
      // Set default values when modal opens
      document.getElementById('addClassModal').addEventListener('show.bs.modal', function() {
        const form = this.querySelector('form');
        form.querySelector('input[name="start_time"]').value = formatDateTime(startTime);
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
    }

    // View class function
    function viewClass(cls) {
      document.getElementById('view_title').textContent = cls.title;
      document.getElementById('view_age_group').textContent = cls.age_group;
      document.getElementById('view_description').textContent = cls.description || 'No description';
      document.getElementById('view_instructor').textContent = cls.instructor_name || 'Unassigned';
      document.getElementById('view_slots').textContent = cls.slots_available + '/' + cls.slots_total;
      document.getElementById('view_start_time').textContent = new Date(cls.start_time).toLocaleString();
      document.getElementById('view_end_time').textContent = new Date(cls.end_time).toLocaleString();
      document.getElementById('view_price').textContent = '$' + parseFloat(cls.price).toFixed(2);
      
      // Calculate status
      const current_time = new Date();
      const start_time = new Date(cls.start_time);
      const end_time = new Date(cls.end_time);
      
      let status = '';
      if (current_time > end_time) {
        status = 'Completed';
      } else if (current_time >= start_time && current_time <= end_time) {
        status = 'In Progress';
      } else if (current_time < start_time) {
        status = 'Upcoming';
      } else {
        status = 'Scheduled';
      }
      document.getElementById('view_status').textContent = status;
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