<?php
// admin/analytics.php
session_start();
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

requireRole('admin');
$user = getCurrentUser($conn);

// Handle report generation and exports
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['generate_report'])) {
        $report_type = $_POST['report_type'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        
        // Generate different reports based on type
        switch($report_type) {
            case 'financial':
                $report_data = generateFinancialReport($conn, $start_date, $end_date);
                $filename = "financial_report_{$start_date}_to_{$end_date}";
                break;
            case 'attendance':
                $report_data = generateAttendanceReport($conn, $start_date, $end_date);
                $filename = "attendance_report_{$start_date}_to_{$end_date}";
                break;
            case 'student':
                $report_data = generateStudentReport($conn, $start_date, $end_date);
                $filename = "student_report_{$start_date}_to_{$end_date}";
                break;
            case 'class':
                $report_data = generateClassReport($conn, $start_date, $end_date);
                $filename = "class_report_{$start_date}_to_{$end_date}";
                break;
            default:
                $report_data = [];
                $filename = "report_{$start_date}_to_{$end_date}";
        }
        
        if (isset($_POST['export_format'])) {
            $format = $_POST['export_format'];
            exportReport($report_data, $filename, $format);
            exit;
        } else {
            $_SESSION['report_preview'] = $report_data;
            header('Location: analytics.php?preview=true');
            exit;
        }
    }
}

// Report generation functions
function generateFinancialReport($conn, $start_date, $end_date) {
    $report = [
        'title' => 'Financial Report',
        'period' => "$start_date to $end_date",
        'headers' => ['Date', 'Student', 'Amount', 'Payment Method', 'Status'],
        'data' => [],
        'summary' => []
    ];
    
    // Revenue data
    $stmt = $conn->prepare("
        SELECT p.payment_date, u.name as student_name, p.amount, p.payment_method, p.status
        FROM payments p 
        LEFT JOIN users u ON p.user_id = u.id 
        WHERE p.payment_date BETWEEN ? AND ?
        ORDER BY p.payment_date DESC
    ");
    $end_date_time = $end_date . ' 23:59:59';
    $stmt->bind_param('ss', $start_date, $end_date_time);
    $stmt->execute();
    $result = $stmt->get_result();
    $revenue = [];
    if ($result) {
        $revenue = $result->fetch_all(MYSQLI_ASSOC);
    }
    
    $report['data'] = $revenue;
    
    // Summary
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as total 
        FROM payments 
        WHERE status = 'paid' AND payment_date BETWEEN ? AND ?
    ");
    $stmt->bind_param('ss', $start_date, $end_date_time);
    $stmt->execute();
    $total_revenue_result = $stmt->get_result();
    $total_revenue = $total_revenue_result ? $total_revenue_result->fetch_assoc()['total'] : 0;
    
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as total 
        FROM payments 
        WHERE status = 'pending' AND payment_date BETWEEN ? AND ?
    ");
    $stmt->bind_param('ss', $start_date, $end_date_time);
    $stmt->execute();
    $pending_payments_result = $stmt->get_result();
    $pending_payments = $pending_payments_result ? $pending_payments_result->fetch_assoc()['total'] : 0;
    
    $report['summary'] = [
        'Total Revenue' => '$' . number_format($total_revenue, 2),
        'Pending Payments' => '$' . number_format($pending_payments, 2),
        'Total Transactions' => count($revenue)
    ];
    
    return $report;
}

function generateAttendanceReport($conn, $start_date, $end_date) {
    $report = [
        'title' => 'Attendance Report',
        'period' => "$start_date to $end_date",
        'headers' => ['Class', 'Instructor', 'Date', 'Total Slots', 'Booked', 'Attendance Rate'],
        'data' => [],
        'summary' => []
    ];
    
    $stmt = $conn->prepare("
        SELECT c.title, i.name as instructor, c.start_time, c.slots_total, c.slots_available,
               (c.slots_total - c.slots_available) as booked
        FROM classes c 
        LEFT JOIN instructors i ON c.instructor_id = i.id 
        WHERE c.start_time BETWEEN ? AND ?
        ORDER BY c.start_time DESC
    ");
    $end_date_time = $end_date . ' 23:59:59';
    $stmt->bind_param('ss', $start_date, $end_date_time);
    $stmt->execute();
    $result = $stmt->get_result();
    $classes = [];
    if ($result) {
        $classes = $result->fetch_all(MYSQLI_ASSOC);
    }
    
    foreach ($classes as $class) {
        $attendance_rate = $class['slots_total'] > 0 ? 
            (($class['booked'] / $class['slots_total']) * 100) : 0;
            
        $report['data'][] = [
            'title' => $class['title'],
            'instructor' => $class['instructor'],
            'start_time' => date('M j, Y', strtotime($class['start_time'])),
            'slots_total' => $class['slots_total'],
            'booked' => $class['booked'],
            'attendance_rate' => number_format($attendance_rate, 1) . '%'
        ];
    }
    
    // Summary
    $total_classes = count($classes);
    $total_slots = array_sum(array_column($classes, 'slots_total'));
    $total_booked = array_sum(array_column($classes, 'booked'));
    $avg_attendance = $total_slots > 0 ? ($total_booked / $total_slots) * 100 : 0;
    
    $report['summary'] = [
        'Total Classes' => $total_classes,
        'Total Slots' => $total_slots,
        'Total Bookings' => $total_booked,
        'Average Attendance' => number_format($avg_attendance, 1) . '%'
    ];
    
    return $report;
}

function generateStudentReport($conn, $start_date, $end_date) {
    $report = [
        'title' => 'Student Activity Report',
        'period' => "$start_date to $end_date",
        'headers' => ['Student', 'Email', 'Registration Date', 'Total Bookings', 'Status'],
        'data' => [],
        'summary' => []
    ];
    
    $stmt = $conn->prepare("
        SELECT u.name, u.email, u.created_at, 
               COUNT(b.id) as total_bookings
        FROM users u 
        LEFT JOIN bookings b ON u.id = b.user_id 
        WHERE u.role = 'student' AND u.created_at BETWEEN ? AND ?
        GROUP BY u.id, u.name, u.email, u.created_at
        ORDER BY u.created_at DESC
    ");
    $end_date_time = $end_date . ' 23:59:59';
    $stmt->bind_param('ss', $start_date, $end_date_time);
    $stmt->execute();
    $result = $stmt->get_result();
    $students = [];
    if ($result) {
        $students = $result->fetch_all(MYSQLI_ASSOC);
    }
    
    $report['data'] = $students;
    
    // Summary
    $total_students = count($students);
    $total_bookings = array_sum(array_column($students, 'total_bookings'));
    $avg_bookings = $total_students > 0 ? $total_bookings / $total_students : 0;
    
    $report['summary'] = [
        'Total Students' => $total_students,
        'Total Bookings' => $total_bookings,
        'Average Bookings per Student' => number_format($avg_bookings, 1)
    ];
    
    return $report;
}

function generateClassReport($conn, $start_date, $end_date) {
    $report = [
        'title' => 'Class Performance Report',
        'period' => "$start_date to $end_date",
        'headers' => ['Class Name', 'Age Group', 'Instructor', 'Schedule', 'Bookings', 'Revenue'],
        'data' => [],
        'summary' => []
    ];
    
    $stmt = $conn->prepare("
        SELECT c.title, c.age_group, i.name as instructor, c.start_time, c.end_time,
               (c.slots_total - c.slots_available) as bookings,
               COALESCE(SUM(p.amount), 0) as revenue
        FROM classes c 
        LEFT JOIN instructors i ON c.instructor_id = i.id 
        LEFT JOIN bookings b ON c.id = b.class_id 
        LEFT JOIN payments p ON b.user_id = p.user_id AND p.status = 'paid'
        WHERE c.start_time BETWEEN ? AND ?
        GROUP BY c.id, c.title, c.age_group, i.name, c.start_time, c.end_time, c.slots_total, c.slots_available
        ORDER BY revenue DESC
    ");
    $end_date_time = $end_date . ' 23:59:59';
    $stmt->bind_param('ss', $start_date, $end_date_time);
    $stmt->execute();
    $result = $stmt->get_result();
    $classes = [];
    if ($result) {
        $classes = $result->fetch_all(MYSQLI_ASSOC);
    }
    
    foreach ($classes as $class) {
        $report['data'][] = [
            'title' => $class['title'],
            'age_group' => $class['age_group'],
            'instructor' => $class['instructor'],
            'schedule' => date('M j, Y g:i A', strtotime($class['start_time'])),
            'bookings' => $class['bookings'],
            'revenue' => '$' . number_format($class['revenue'], 2)
        ];
    }
    
    // Summary
    $total_classes = count($classes);
    $total_revenue = array_sum(array_column($classes, 'revenue'));
    $total_bookings = array_sum(array_column($classes, 'bookings'));
    
    $report['summary'] = [
        'Total Classes' => $total_classes,
        'Total Revenue' => '$' . number_format($total_revenue, 2),
        'Total Bookings' => $total_bookings,
        'Average Revenue per Class' => '$' . number_format($total_revenue / max($total_classes, 1), 2)
    ];
    
    return $report;
}

function exportReport($report, $filename, $format) {
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add title and period
        fputcsv($output, [$report['title']]);
        fputcsv($output, ['Period:', $report['period']]);
        fputcsv($output, []); // Empty line
        
        // Add headers
        fputcsv($output, $report['headers']);
        
        // Add data
        foreach ($report['data'] as $row) {
            fputcsv($output, array_values($row));
        }
        
        // Add summary
        fputcsv($output, []); // Empty line
        fputcsv($output, ['Summary']);
        foreach ($report['summary'] as $key => $value) {
            fputcsv($output, [$key, $value]);
        }
        
        fclose($output);
        exit;
        
    } elseif ($format === 'pdf') {
        // For PDF, you would need a PDF library like TCPDF or Dompdf
        // This is a simplified version
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
        
        // Simple PDF output (in real implementation, use a proper PDF library)
        echo "<html>";
        echo "<head><title>{$report['title']}</title></head>";
        echo "<body>";
        echo "<h1>{$report['title']}</h1>";
        echo "<p>Period: {$report['period']}</p>";
        echo "<table border='1'>";
        echo "<tr>";
        foreach ($report['headers'] as $header) {
            echo "<th>{$header}</th>";
        }
        echo "</tr>";
        foreach ($report['data'] as $row) {
            echo "<tr>";
            foreach ($row as $cell) {
                echo "<td>{$cell}</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
        echo "<h2>Summary</h2>";
        foreach ($report['summary'] as $key => $value) {
            echo "<p><strong>{$key}:</strong> {$value}</p>";
        }
        echo "</body></html>";
        exit;
        
    } elseif ($format === 'excel') {
        // Excel export
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        
        echo '<table border="1">';
        echo '<tr><th colspan="' . count($report['headers']) . '">' . $report['title'] . '</th></tr>';
        echo '<tr><th colspan="' . count($report['headers']) . '">Period: ' . $report['period'] . '</th></tr>';
        echo '<tr>';
        foreach ($report['headers'] as $header) {
            echo '<th>' . $header . '</th>';
        }
        echo '</tr>';
        
        foreach ($report['data'] as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . $cell . '</td>';
            }
            echo '</tr>';
        }
        
        echo '<tr><th colspan="' . count($report['headers']) . '">Summary</th></tr>';
        foreach ($report['summary'] as $key => $value) {
            echo '<tr><td colspan="' . (count($report['headers']) - 1) . '">' . $key . '</td><td>' . $value . '</td></tr>';
        }
        echo '</table>';
        exit;
    }
}

// Get default dates
$default_start = date('Y-m-d', strtotime('-30 days'));
$default_end = date('Y-m-d');

// Check for preview data
$preview_data = null;
if (isset($_SESSION['report_preview'])) {
    $preview_data = $_SESSION['report_preview'];
    unset($_SESSION['report_preview']);
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Reports & Analytics - Admin Dashboard</title>
  
  <link href="../css/style.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --bg:#f6f8fb;
      --card:#ffffff;
      --muted:#6b7280;
      --accent:#2563eb;
      --radius:12px;
      --shadow: 0 6px 18px rgba(15,23,42,0.06);
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
    }

    body { background: var(--bg); color: #111827; -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale; }

    .dashboard-container {
      padding: 28px;
      max-width: 1200px;
      margin: 0 auto 80px;
    }

    .card {
      background: var(--card);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      border: none;
      margin-bottom: 20px;
    }

    .card-header {
      background: transparent;
      border-bottom: none;
      padding: 18px 20px;
    }

    .card-body { padding: 20px; }

    .page-title {
      margin-bottom: 18px;
      font-weight: 700;
      font-size: 20px;
    }

    .report-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 16px;
    }

    .report-type-card {
      background: linear-gradient(180deg, rgba(255,255,255,0.9), rgba(250,250,250,0.9));
      border-radius: 10px;
      padding: 18px;
      text-align: left;
      cursor: pointer;
      transition: transform .18s ease, box-shadow .18s ease;
      border: 1px solid rgba(15,23,42,0.04);
      display:flex;
      gap:12px;
      align-items:center;
    }

    .report-type-card .icon {
      width:48px;height:48px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;color:white;
    }

    .report-type-card .meta { flex:1; }
    .report-type-card h6 { margin:0;font-size:16px;font-weight:600; }
    .report-type-card p { margin:4px 0 0;color:var(--muted);font-size:13px }

    .report-type-card:hover { transform: translateY(-6px); box-shadow: 0 12px 30px rgba(2,6,23,0.08); }

    .report-type-card.active { border-color: rgba(37,99,235,0.12); box-shadow:0 10px 28px rgba(37,99,235,0.06); }

    .financial .icon { background: linear-gradient(135deg,#eff6ff,#bfdbfe); color:#1e40af; }
    .attendance .icon { background: linear-gradient(135deg,#ecfdf5,#bbf7d0); color:#166534; }
    .student .icon { background: linear-gradient(135deg,#fff7ed,#fde68a); color:#92400e; }
    .class .icon { background: linear-gradient(135deg,#fff0f6,#fbcfe8); color:#9f1239; }

    .form-row { display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
    .form-row .col { flex:1; min-width:160px; }

    .export-options { display:flex; gap:10px; }
    .btn-export { padding:10px 14px; border-radius:10px; border:1px solid rgba(15,23,42,0.06); background:white; cursor:pointer; display:flex; gap:8px; align-items:center; }
    .btn-export.active { background: linear-gradient(90deg,#eff6ff,#e0f2fe); border-color:rgba(37,99,235,0.16); }

    .preview-card { max-height:420px; overflow:auto; padding:12px; border-radius:10px; background:#fbfdff; border:1px solid rgba(15,23,42,0.03); }

    .summary-list { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-top:12px; }
    .summary-item { background:white; padding:12px; border-radius:8px; border:1px solid rgba(15,23,42,0.04); }

    .quick-export .btn { border-radius:10px; padding:12px 18px; }

    @media (max-width:600px){
      .form-row { flex-direction:column; }
    }
  </style>
</head>
<body>

  <div class="sidebar"><?php include 'components/sidebar.php'; ?></div>

  <div class="main-content">
    <div class="dashboard-container">
      <!-- Header Section -->
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <h2 class="page-title">Reports & Data Export</h2>
          <p class="text-muted mb-0">Generate comprehensive reports and export data in various formats</p>
        </div>
      </div>

      <!-- Report Type Selection -->
      <div class="card">
        <div class="card-body">
          <div class="report-grid">
            <div class="report-type-card active" data-report-type="financial" id="rt-financial">
              <div class="icon financial"><i class="bi bi-currency-dollar"></i></div>
              <div class="meta">
                <h6>Financial Report</h6>
                <p>Revenue, payments, and financial transactions</p>
              </div>
            </div>

            <div class="report-type-card" data-report-type="attendance" id="rt-attendance">
              <div class="icon attendance"><i class="bi bi-people"></i></div>
              <div class="meta">
                <h6>Attendance Report</h6>
                <p>Class attendance and booking statistics</p>
              </div>
            </div>

            <div class="report-type-card" data-report-type="student" id="rt-student">
              <div class="icon student"><i class="bi bi-person"></i></div>
              <div class="meta">
                <h6>Student Report</h6>
                <p>Student activity and registration data</p>
              </div>
            </div>

            <div class="report-type-card" data-report-type="class" id="rt-class">
              <div class="icon class"><i class="bi bi-calendar-week"></i></div>
              <div class="meta">
                <h6>Class Report</h6>
                <p>Class performance and scheduling</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Report Configuration -->
      <div class="card">
        <div class="card-body">
          <form method="POST" id="reportForm">
            <input type="hidden" name="report_type" id="reportType" value="financial">
            <div class="form-row">
              <div class="col">
                <label class="form-label small">Start Date</label>
                <input type="date" class="form-control" name="start_date" value="<?= $default_start ?>" required>
              </div>
              <div class="col">
                <label class="form-label small">End Date</label>
                <input type="date" class="form-control" name="end_date" value="<?= $default_end ?>" required>
              </div>
              <div class="col">
                <label class="form-label small">Export Format</label>
                <div class="export-options">
                  <label class="btn-export active" id="exp-csv">
                    <input type="radio" name="export_format" value="csv" checked hidden>
                    <i class="bi bi-file-earmark-spreadsheet"></i> CSV
                  </label>
                  <label class="btn-export" id="exp-pdf">
                    <input type="radio" name="export_format" value="pdf" hidden>
                    <i class="bi bi-file-pdf"></i> PDF
                  </label>
                  <label class="btn-export" id="exp-xls">
                    <input type="radio" name="export_format" value="excel" hidden>
                    <i class="bi bi-file-excel"></i> Excel
                  </label>
                </div>
              </div>
            </div>
            
            <div class="mt-4 d-flex gap-2">
              <button type="submit" name="generate_report" class="btn btn-primary">
                <i class="bi bi-download me-2"></i>Generate & Export
              </button>
              <button type="button" id="previewReport" class="btn btn-outline-primary">
                <i class="bi bi-eye me-2"></i>Preview
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Report Preview -->
      <div class="card" id="previewSection" style="display: <?= $preview_data ? 'block' : 'none' ?>;">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-3">
            <h5 class="mb-0">Report Preview</h5>
            <button type="button" class="btn btn-sm btn-light" id="closePreview"><i class="bi bi-x-lg"></i></button>
          </div>

          <div id="previewContent" class="preview-card">
            <?php if ($preview_data): ?>
              <h5><?= htmlspecialchars($preview_data['title']) ?></h5>
              <p class="text-muted small">Period: <?= htmlspecialchars($preview_data['period']) ?></p>
              
              <div class="table-responsive">
                <table class="table table-sm table-hover">
                  <thead>
                    <tr>
                      <?php foreach ($preview_data['headers'] as $header): ?>
                        <th><?= htmlspecialchars($header) ?></th>
                      <?php endforeach; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($preview_data['data'] as $row): ?>
                      <tr>
                        <?php foreach ($row as $cell): ?>
                          <td><?= htmlspecialchars($cell) ?></td>
                        <?php endforeach; ?>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              
              <div class="summary-list">
                <?php foreach ($preview_data['summary'] as $key => $value): ?>
                  <div class="summary-item">
                    <div class="text-muted small"><?= htmlspecialchars($key) ?></div>
                    <div class="fw-bold"><?= htmlspecialchars($value) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="text-center text-muted">
                <i class="bi bi-graph-up" style="font-size: 2.5rem;"></i>
                <p class="mb-0 mt-2">No preview available. Generate a report to see preview.</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Quick Export Options -->
      <div class="card quick-export">
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-4">
              <a href="export_data.php?type=students" class="btn btn-outline-primary w-100">
                <i class="bi bi-people me-2"></i>Export Students
              </a>
            </div>
            <div class="col-md-4">
              <a href="export_data.php?type=classes" class="btn btn-outline-primary w-100">
                <i class="bi bi-calendar-week me-2"></i>Export Classes
              </a>
            </div>
            <div class="col-md-4">
              <a href="export_data.php?type=bookings" class="btn btn-outline-primary w-100">
                <i class="bi bi-clipboard-check me-2"></i>Export Bookings
              </a>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../js/main.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Report type selection
      const reportCards = document.querySelectorAll('.report-type-card');
      const reportTypeInput = document.getElementById('reportType');
      
      reportCards.forEach(card => {
        card.addEventListener('click', function() {
          reportCards.forEach(c => c.classList.remove('active'));
          this.classList.add('active');
          reportTypeInput.value = this.dataset.reportType;
        });
      });
      
      // Export format selection
      const exportOptions = document.querySelectorAll('.btn-export');
      exportOptions.forEach(option => {
        option.addEventListener('click', function() {
          exportOptions.forEach(o => o.classList.remove('active'));
          this.classList.add('active');
          const input = this.querySelector('input');
          if (input) input.checked = true;
        });
      });
      
      // Preview functionality
      const previewBtn = document.getElementById('previewReport');
      const previewSection = document.getElementById('previewSection');
      const closePreview = document.getElementById('closePreview');
      
      previewBtn.addEventListener('click', function() {
        const form = document.getElementById('reportForm');
        const tempForm = document.createElement('form');
        tempForm.method = 'POST';
        tempForm.action = 'analytics.php';
        
        const formData = new FormData(form);
        for (let [key, value] of formData.entries()) {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = key;
          input.value = value;
          tempForm.appendChild(input);
        }
        
        const previewInput = document.createElement('input');
        previewInput.type = 'hidden';
        previewInput.name = 'generate_report';
        previewInput.value = '1';
        tempForm.appendChild(previewInput);
        
        document.body.appendChild(tempForm);
        tempForm.submit();
      });
      
      closePreview.addEventListener('click', function() {
        previewSection.style.display = 'none';
      });

      // Close preview with Escape
      document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') closePreview.click();
      });
    });
  </script>
</body>
</html>