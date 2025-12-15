<?php
// admin/analytics.php
session_start();
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

// Check database connection
if (!$conn) {
    die("Database connection error.");
}

requireRole('admin');
$user = getCurrentUser($conn);

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Handle report generation and exports
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        die("Security token invalid. Please try again.");
    }
    
    if (isset($_POST['generate_report'])) {
        $report_type = filter_input(INPUT_POST, 'report_type', FILTER_SANITIZE_SPECIAL_CHARS);
        $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_SPECIAL_CHARS);
        $end_date = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_SPECIAL_CHARS);
        
        // Validate dates
        if (!validateDate($start_date) || !validateDate($end_date)) {
            die("Invalid date format.");
        }
        
        // Ensure end date is not before start date
        if (strtotime($end_date) < strtotime($start_date)) {
            die("End date must be after start date.");
        }
        
        // Limit date range to prevent excessive data load
        $max_days = 365; // Maximum 1 year
        if ((strtotime($end_date) - strtotime($start_date)) > ($max_days * 86400)) {
            die("Date range cannot exceed $max_days days.");
        }
        
        // Generate different reports based on type
        $allowed_reports = ['financial', 'attendance', 'student', 'class'];
        if (!in_array($report_type, $allowed_reports)) {
            die("Invalid report type.");
        }
        
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
            $format = filter_input(INPUT_POST, 'export_format', FILTER_SANITIZE_SPECIAL_CHARS);
            $allowed_formats = ['csv', 'pdf', 'excel'];
            if (!in_array($format, $allowed_formats)) {
                die("Invalid export format.");
            }
            
            // Sanitize filename
            $filename = preg_replace('/[^a-zA-Z0-9_-]/', '', $filename);
            exportReport($report_data, $filename, $format);
            exit;
        } else {
            $_SESSION['report_preview'] = $report_data;
            header('Location: analytics.php?preview=true');
            exit;
        }
    }
}

// Date validation function
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
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
    
    // Revenue data with prepared statement
    $stmt = $conn->prepare("
        SELECT p.payment_date, u.name as student_name, p.amount, p.payment_method, p.status
        FROM payments p 
        LEFT JOIN users u ON p.user_id = u.id 
        WHERE p.payment_date BETWEEN ? AND ?
        ORDER BY p.payment_date DESC
    ");
    
    if (!$stmt) {
        return $report; // Return empty report on error
    }
    
    $end_date_time = $end_date . ' 23:59:59';
    $stmt->bind_param('ss', $start_date, $end_date_time);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result) {
        $revenue = $result->fetch_all(MYSQLI_ASSOC);
        // Sanitize output data
        foreach ($revenue as &$row) {
            $row['student_name'] = htmlspecialchars($row['student_name'] ?? '');
            $row['payment_method'] = htmlspecialchars($row['payment_method'] ?? '');
            $row['status'] = htmlspecialchars($row['status'] ?? '');
        }
        $report['data'] = $revenue;
    }
    $stmt->close();
    
    // Summary with prepared statements
    $total_revenue = 0;
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as total 
        FROM payments 
        WHERE status = 'paid' AND payment_date BETWEEN ? AND ?
    ");
    if ($stmt) {
        $stmt->bind_param('ss', $start_date, $end_date_time);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            $total_revenue = $result->fetch_assoc()['total'] ?? 0;
        }
        $stmt->close();
    }
    
    $pending_payments = 0;
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as total 
        FROM payments 
        WHERE status = 'pending' AND payment_date BETWEEN ? AND ?
    ");
    if ($stmt) {
        $stmt->bind_param('ss', $start_date, $end_date_time);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            $pending_payments = $result->fetch_assoc()['total'] ?? 0;
        }
        $stmt->close();
    }
    
    $report['summary'] = [
        'Total Revenue' => '$' . number_format($total_revenue, 2),
        'Pending Payments' => '$' . number_format($pending_payments, 2),
        'Total Transactions' => count($report['data'])
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
        SELECT c.title, i.name as instructor, c.start_time, c.slots_total, c.slots_available
        FROM classes c 
        LEFT JOIN instructors i ON c.instructor_id = i.id 
        WHERE c.start_time BETWEEN ? AND ?
        ORDER BY c.start_time DESC
    ");
    
    if (!$stmt) {
        return $report;
    }
    
    $end_date_time = $end_date . ' 23:59:59';
    $stmt->bind_param('ss', $start_date, $end_date_time);
    $stmt->execute();
    $result = $stmt->get_result();
    $classes = [];
    
    if ($result) {
        $classes = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
    
    foreach ($classes as $class) {
        $booked = $class['slots_total'] - $class['slots_available'];
        $attendance_rate = $class['slots_total'] > 0 ? 
            (($booked / $class['slots_total']) * 100) : 0;
            
        $report['data'][] = [
            'title' => htmlspecialchars($class['title'] ?? ''),
            'instructor' => htmlspecialchars($class['instructor'] ?? ''),
            'start_time' => date('M j, Y', strtotime($class['start_time'])),
            'slots_total' => $class['slots_total'],
            'booked' => $booked,
            'attendance_rate' => number_format($attendance_rate, 1) . '%'
        ];
    }
    
    // Summary
    $total_classes = count($classes);
    $total_slots = array_sum(array_column($report['data'], 'slots_total'));
    $total_booked = array_sum(array_column($report['data'], 'booked'));
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
        'headers' => ['Student', 'Email', 'Registration Date', 'Total Bookings'],
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
    
    if (!$stmt) {
        return $report;
    }
    
    $end_date_time = $end_date . ' 23:59:59';
    $stmt->bind_param('ss', $start_date, $end_date_time);
    $stmt->execute();
    $result = $stmt->get_result();
    $students = [];
    
    if ($result) {
        $students = $result->fetch_all(MYSQLI_ASSOC);
        // Sanitize output data
        foreach ($students as &$student) {
            $student['name'] = htmlspecialchars($student['name'] ?? '');
            $student['email'] = htmlspecialchars($student['email'] ?? '');
        }
    }
    $stmt->close();
    
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
               COALESCE(SUM(CASE WHEN p.status = 'paid' THEN p.amount ELSE 0 END), 0) as revenue
        FROM classes c 
        LEFT JOIN instructors i ON c.instructor_id = i.id 
        LEFT JOIN bookings b ON c.id = b.class_id 
        LEFT JOIN payments p ON b.user_id = p.user_id
        WHERE c.start_time BETWEEN ? AND ?
        GROUP BY c.id, c.title, c.age_group, i.name, c.start_time, c.end_time, c.slots_total, c.slots_available
        ORDER BY revenue DESC
    ");
    
    if (!$stmt) {
        return $report;
    }
    
    $end_date_time = $end_date . ' 23:59:59';
    $stmt->bind_param('ss', $start_date, $end_date_time);
    $stmt->execute();
    $result = $stmt->get_result();
    $classes = [];
    
    if ($result) {
        $classes = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
    
    foreach ($classes as $class) {
        $report['data'][] = [
            'title' => htmlspecialchars($class['title'] ?? ''),
            'age_group' => htmlspecialchars($class['age_group'] ?? ''),
            'instructor' => htmlspecialchars($class['instructor'] ?? ''),
            'schedule' => date('M j, Y g:i A', strtotime($class['start_time'])),
            'bookings' => $class['bookings'] ?? 0,
            'revenue' => '$' . number_format($class['revenue'] ?? 0, 2)
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
    // Sanitize filename for security
    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
    
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM for Excel compatibility
        fwrite($output, "\xEF\xBB\xBF");
        
        // Add title and period
        fputcsv($output, [htmlspecialchars_decode($report['title'])]);
        fputcsv($output, ['Period:', htmlspecialchars_decode($report['period'])]);
        fputcsv($output, []); // Empty line
        
        // Add headers
        fputcsv($output, array_map('htmlspecialchars_decode', $report['headers']));
        
        // Add data
        foreach ($report['data'] as $row) {
            fputcsv($output, array_map('htmlspecialchars_decode', array_values($row)));
        }
        
        // Add summary
        fputcsv($output, []); // Empty line
        fputcsv($output, ['Summary']);
        foreach ($report['summary'] as $key => $value) {
            fputcsv($output, [htmlspecialchars_decode($key), htmlspecialchars_decode($value)]);
        }
        
        fclose($output);
        exit;
        
    } elseif ($format === 'pdf') {
        // Note: For production, use a proper PDF library like TCPDF or Dompdf
        // This is a simplified HTML output for demonstration
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
        
        // In production, you would use:
        // require_once('../vendor/autoload.php');
        // $pdf = new TCPDF();
        // ... generate PDF content
        
        // For now, output HTML that can be saved as PDF by browser
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>' . htmlspecialchars($report['title']) . '</title>
            <style>
                body { font-family: Arial, sans-serif; }
                h1 { color: #333; }
                table { border-collapse: collapse; width: 100%; margin: 20px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
            </style>
        </head>
        <body>
            <h1>' . htmlspecialchars($report['title']) . '</h1>
            <p><strong>Period:</strong> ' . htmlspecialchars($report['period']) . '</p>
            
            <table>
                <tr>';
        foreach ($report['headers'] as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }
        $html .= '</tr>';
        
        foreach ($report['data'] as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . htmlspecialchars($cell) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>
            
            <h2>Summary</h2>';
        foreach ($report['summary'] as $key => $value) {
            $html .= '<p><strong>' . htmlspecialchars($key) . ':</strong> ' . htmlspecialchars($value) . '</p>';
        }
        $html .= '</body></html>';
        
        echo $html;
        exit;
        
    } elseif ($format === 'excel') {
        // Excel export (HTML table for Excel)
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        
        echo '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>' . htmlspecialchars($report['title']) . '</title>
            <style>
                table { border-collapse: collapse; width: 100%; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; font-weight: bold; }
            </style>
        </head>
        <body>';
        
        echo '<h1>' . htmlspecialchars($report['title']) . '</h1>';
        echo '<p><strong>Period:</strong> ' . htmlspecialchars($report['period']) . '</p>';
        
        echo '<table border="1">';
        echo '<tr>';
        foreach ($report['headers'] as $header) {
            echo '<th>' . htmlspecialchars($header) . '</th>';
        }
        echo '</tr>';
        
        foreach ($report['data'] as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . htmlspecialchars($cell) . '</td>';
            }
            echo '</tr>';
        }
        echo '</table>';
        
        echo '<h2>Summary</h2>';
        foreach ($report['summary'] as $key => $value) {
            echo '<p><strong>' . htmlspecialchars($key) . ':</strong> ' . htmlspecialchars($value) . '</p>';
        }
        
        echo '</body></html>';
        exit;
    }
}

// Get default dates (last 30 days)
$default_start = date('Y-m-d', strtotime('-30 days'));
$default_end = date('Y-m-d');

// Check for preview data
$preview_data = null;
if (isset($_SESSION['report_preview'])) {
    $preview_data = $_SESSION['report_preview'];
    // Clean session data
    unset($_SESSION['report_preview']);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reports & Analytics - Admin Dashboard</title>
  
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  
  <!-- Custom CSS -->
  <link href="../css/style.css" rel="stylesheet">
  
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

    body { 
      background: var(--bg); 
      color: #111827; 
      -webkit-font-smoothing:antialiased; 
      -moz-osx-font-smoothing:grayscale; 
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
      transition: all 0.3s ease;
      box-shadow: 2px 0 20px rgba(0, 0, 0, 0.1);
    }
    
    .main-content {
      flex: 1;
      margin-left: 260px;
      transition: all 0.3s ease;
    }
    
    /* Topbar Styles */
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
      transition: all 0.3s ease;
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

    @media (max-width: 768px) {
      .sidebar {
        width: 70px;
      }
      
      .main-content {
        margin-left: 70px;
      }
      
      .report-grid {
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      }
      
      .form-row { flex-direction:column; }
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
          <a href="index.php" class="nav-link">
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
          <a href="analytics.php" class="nav-link active">
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
          <h1>Reports & Analytics</h1>
          <p>Welcome back, <?= htmlspecialchars($user['name'] ?? 'Admin') ?>! Generate comprehensive reports and export data.</p>
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
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
              <input type="hidden" name="report_type" id="reportType" value="financial">
              <div class="form-row">
                <div class="col">
                  <label class="form-label small">Start Date</label>
                  <input type="date" class="form-control" name="start_date" value="<?= htmlspecialchars($default_start) ?>" required max="<?= date('Y-m-d') ?>">
                </div>
                <div class="col">
                  <label class="form-label small">End Date</label>
                  <input type="date" class="form-control" name="end_date" value="<?= htmlspecialchars($default_end) ?>" required max="<?= date('Y-m-d') ?>">
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
                <h5><?= htmlspecialchars($preview_data['title'] ?? '') ?></h5>
                <p class="text-muted small">Period: <?= htmlspecialchars($preview_data['period'] ?? '') ?></p>
                
                <?php if (!empty($preview_data['data'])): ?>
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
                <?php else: ?>
                  <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> No data available for the selected period.
                  </div>
                <?php endif; ?>
                
                <?php if (!empty($preview_data['summary'])): ?>
                  <div class="summary-list">
                    <?php foreach ($preview_data['summary'] as $key => $value): ?>
                      <div class="summary-item">
                        <div class="text-muted small"><?= htmlspecialchars($key) ?></div>
                        <div class="fw-bold"><?= htmlspecialchars($value) ?></div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
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
                <a href="export_data.php?type=students&token=<?= urlencode($csrf_token) ?>" class="btn btn-outline-primary w-100">
                  <i class="bi bi-people me-2"></i>Export Students
                </a>
              </div>
              <div class="col-md-4">
                <a href="export_data.php?type=classes&token=<?= urlencode($csrf_token) ?>" class="btn btn-outline-primary w-100">
                  <i class="bi bi-calendar-week me-2"></i>Export Classes
                </a>
              </div>
              <div class="col-md-4">
                <a href="export_data.php?type=bookings&token=<?= urlencode($csrf_token) ?>" class="btn btn-outline-primary w-100">
                  <i class="bi bi-clipboard-check me-2"></i>Export Bookings
                </a>
              </div>
            </div>
          </div>
        </div>

      </div>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
        
        // Copy all form inputs
        const formData = new FormData(form);
        for (let [key, value] of formData.entries()) {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = key;
          input.value = value;
          tempForm.appendChild(input);
        }
        
        // Add preview indicator
        const previewInput = document.createElement('input');
        previewInput.type = 'hidden';
        previewInput.name = 'generate_report';
        previewInput.value = '1';
        tempForm.appendChild(previewInput);
        
        // Add CSRF token
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = '<?= htmlspecialchars($csrf_token) ?>';
        tempForm.appendChild(csrfInput);
        
        document.body.appendChild(tempForm);
        tempForm.submit();
      });
      
      closePreview.addEventListener('click', function() {
        previewSection.style.display = 'none';
      });

      // Close preview with Escape
      document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && previewSection.style.display !== 'none') {
          closePreview.click();
        }
      });
      
      // Date validation
      const startDateInput = document.querySelector('input[name="start_date"]');
      const endDateInput = document.querySelector('input[name="end_date"]');
      
      function validateDates() {
        const startDate = new Date(startDateInput.value);
        const endDate = new Date(endDateInput.value);
        
        if (startDate > endDate) {
          alert('Start date cannot be after end date.');
          endDateInput.value = startDateInput.value;
        }
        
        // Limit to 365 days
        const diffTime = Math.abs(endDate - startDate);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        
        if (diffDays > 365) {
          alert('Date range cannot exceed 365 days.');
          const newEndDate = new Date(startDate);
          newEndDate.setDate(startDate.getDate() + 365);
          endDateInput.value = newEndDate.toISOString().split('T')[0];
        }
      }
      
      startDateInput.addEventListener('change', validateDates);
      endDateInput.addEventListener('change', validateDates);
      
      // Mobile sidebar toggle
      const sidebarToggle = document.createElement('button');
      sidebarToggle.className = 'btn btn-primary btn-sm d-md-none position-fixed bottom-0 start-0 m-3';
      sidebarToggle.innerHTML = '<i class="bi bi-list"></i>';
      sidebarToggle.style.zIndex = '1050';
      sidebarToggle.onclick = function() {
        document.querySelector('.sidebar').classList.toggle('active');
        document.querySelector('.main-content').classList.toggle('active');
      };
      document.body.appendChild(sidebarToggle);
    });
  </script>
</body>
</html>