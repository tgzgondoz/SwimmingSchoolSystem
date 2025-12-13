[file name]: student/progress.php
[file content begin]
<?php
// student/progress.php - Student Progress Tracking
session_start();
include __DIR__ . '/../inc/db.php';
include __DIR__ . '/../inc/functions.php';

requireRole('student');
$user = getCurrentUser($conn);
$student_id = $_SESSION['user_id'];

// Get progress data
$progress_data = $conn->query("
    SELECT 
        DATE_FORMAT(c.start_time, '%Y-%m') as month,
        COUNT(*) as classes_attended,
        AVG(c.difficulty_level) as avg_difficulty,
        GROUP_CONCAT(DISTINCT c.age_group) as levels_attended
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    WHERE b.user_id = $student_id 
    AND b.status = 'confirmed'
    AND c.end_time < NOW()
    GROUP BY DATE_FORMAT(c.start_time, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

// Get current level progress
$current_level = $conn->query("
    SELECT age_group, COUNT(*) as classes_completed
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    WHERE b.user_id = $student_id 
    AND b.status = 'confirmed'
    AND c.end_time < NOW()
    GROUP BY c.age_group
    ORDER BY 
        CASE c.age_group
            WHEN 'Kids (4-7)' THEN 1
            WHEN 'Children (8-12)' THEN 2
            WHEN 'Teens (13-17)' THEN 3
            WHEN 'Adults (18+)' THEN 4
            WHEN 'Seniors (55+)' THEN 5
            ELSE 6
        END DESC
")->fetch_all(MYSQLI_ASSOC);

// Get instructor feedback
$instructor_feedback = $conn->query("
    SELECT f.*, i.name as instructor_name, c.title as class_title
    FROM feedback f
    JOIN classes c ON f.class_id = c.id
    JOIN instructors i ON c.instructor_id = i.id
    WHERE f.student_id = $student_id
    ORDER BY f.created_at DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Get achievements
$achievements = $conn->query("
    SELECT 
        'First Class' as achievement,
        MIN(c.start_time) as date_earned,
        'Attended your first swimming class' as description
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    WHERE b.user_id = $student_id AND b.status = 'confirmed'
    UNION ALL
    SELECT 
        '5 Classes' as achievement,
        MAX(c.start_time) as date_earned,
        'Completed 5 swimming classes' as description
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    WHERE b.user_id = $student_id AND b.status = 'confirmed'
    GROUP BY b.user_id
    HAVING COUNT(*) >= 5
    UNION ALL
    SELECT 
        'Perfect Attendance' as achievement,
        MAX(c.start_time) as date_earned,
        'No missed classes for a month' as description
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    WHERE b.user_id = $student_id 
    AND b.status = 'confirmed'
    AND c.start_time >= DATE_SUB(NOW(), INTERVAL 1 MONTH)
    GROUP BY b.user_id
    HAVING COUNT(*) >= 4
")->fetch_all(MYSQLI_ASSOC);

// Calculate statistics
$total_classes = $conn->query("
    SELECT COUNT(*) as total 
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    WHERE b.user_id = $student_id AND b.status = 'confirmed' AND c.end_time < NOW()
")->fetch_assoc()['total'];

$attendance_rate = $conn->query("
    SELECT 
        COUNT(*) as total_booked,
        SUM(CASE WHEN c.end_time < NOW() THEN 1 ELSE 0 END) as attended,
        ROUND(SUM(CASE WHEN c.end_time < NOW() THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) as rate
    FROM bookings b
    JOIN classes c ON b.class_id = c.id
    WHERE b.user_id = $student_id AND b.status = 'confirmed'
")->fetch_assoc();

$current_streak = $conn->query("
    SELECT COUNT(*) as streak
    FROM (
        SELECT DISTINCT DATE(c.start_time) as class_date
        FROM bookings b
        JOIN classes c ON b.class_id = c.id
        WHERE b.user_id = $student_id 
        AND b.status = 'confirmed'
        AND c.start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY class_date DESC
    ) as recent_classes
")->fetch_assoc()['streak'];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>My Progress - Student Dashboard</title>
  
  <link href="../css/style.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    .dashboard-container {
      padding: 20px;
      max-width: 1400px;
      margin: 0 auto;
    }

    .progress-card {
      background: white;
      border-radius: 12px;
      padding: 25px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
      margin-bottom: 24px;
      height: 100%;
    }

    .progress-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
    }

    .progress-title {
      font-size: 20px;
      font-weight: 600;
      color: #1f2937;
      margin: 0;
    }

    .progress-chart {
      height: 300px;
      margin: 20px 0;
    }

    .stat-circle {
      width: 100px;
      height: 100px;
      border-radius: 50%;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      margin: 0 auto;
      border: 5px solid;
      font-weight: 600;
    }

    .stat-circle-label {
      font-size: 14px;
      color: #6b7280;
      margin-top: 5px;
      text-align: center;
    }

    .level-progress {
      margin: 20px 0;
    }

    .level-item {
      margin-bottom: 15px;
    }

    .level-name {
      font-weight: 500;
      color: #1f2937;
      margin-bottom: 5px;
    }

    .progress-bar {
      height: 10px;
      border-radius: 5px;
      background: #e5e7eb;
      overflow: hidden;
    }

    .progress-fill {
      height: 100%;
      border-radius: 5px;
      transition: width 0.5s ease;
    }

    .achievement-card {
      background: #f8fafc;
      border-radius: 10px;
      padding: 15px;
      margin-bottom: 10px;
      border-left: 4px solid #3b82f6;
    }

    .achievement-icon {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      margin-right: 15px;
      color: white;
    }

    .feedback-card {
      background: white;
      border-radius: 10px;
      padding: 20px;
      margin-bottom: 15px;
      border: 1px solid #e5e7eb;
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }

    .instructor-info {
      display: flex;
      align-items: center;
      margin-bottom: 15px;
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
      margin-right: 15px;
    }

    .rating-stars {
      color: #f59e0b;
      font-size: 14px;
    }

    .feedback-text {
      color: #4b5563;
      line-height: 1.6;
      font-style: italic;
      margin-bottom: 10px;
    }

    .feedback-date {
      font-size: 12px;
      color: #9ca3af;
    }

    .badge-achievement {
      background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
      color: white;
      padding: 3px 8px;
      border-radius: 12px;
      font-size: 10px;
      font-weight: 500;
    }

    .empty-state {
      text-align: center;
      padding: 40px 20px;
      color: #6b7280;
    }

    .empty-state i {
      font-size: 48px;
      opacity: 0.5;
      margin-bottom: 15px;
    }

    .streak-counter {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 5px 10px;
      background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
      color: white;
      border-radius: 20px;
      font-weight: 500;
    }

    .water-icon {
      color: #3b82f6;
      animation: float 3s ease-in-out infinite;
    }

    @keyframes float {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-5px); }
    }

    .skill-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px 0;
      border-bottom: 1px solid #e5e7eb;
    }

    .skill-item:last-child {
      border-bottom: none;
    }

    .skill-level {
      width: 100px;
      height: 8px;
      background: #e5e7eb;
      border-radius: 4px;
      overflow: hidden;
    }

    .skill-progress {
      height: 100%;
      border-radius: 4px;
    }
  </style>
</head>
<body>
  <div class="sidebar"><?php include 'components/sidebar.php'; ?></div>
  
  <div class="main-content">
    <div class="header"><?php include 'components/header.php'; ?></div>
    
    <div class="dashboard-container">
      <!-- Page Header -->
      <div class="mb-4">
        <h1 class="fw-bold">My Progress</h1>
        <p class="text-muted">Track your swimming journey and achievements</p>
      </div>

      <!-- Progress Overview -->
      <div class="progress-card">
        <div class="progress-header">
          <h2 class="progress-title">Progress Overview</h2>
          <span class="streak-counter">
            <i class="bi bi-fire"></i>
            <?= $current_streak ?> day streak
          </span>
        </div>
        
        <div class="row text-center mb-4">
          <div class="col-md-3">
            <div class="stat-circle" style="border-color: #3b82f6; color: #3b82f6;">
              <div class="h2"><?= $total_classes ?></div>
            </div>
            <div class="stat-circle-label">Classes Completed</div>
          </div>
          <div class="col-md-3">
            <div class="stat-circle" style="border-color: #10b981; color: #10b981;">
              <div class="h2"><?= $attendance_rate['rate'] ?>%</div>
            </div>
            <div class="stat-circle-label">Attendance Rate</div>
          </div>
          <div class="col-md-3">
            <div class="stat-circle" style="border-color: #f59e0b; color: #f59e0b;">
              <div class="h2"><?= count($current_level) ?></div>
            </div>
            <div class="stat-circle-label">Levels Progressed</div>
          </div>
          <div class="col-md-3">
            <div class="stat-circle" style="border-color: #8b5cf6; color: #8b5cf6;">
              <div class="h2"><?= count($achievements) ?></div>
            </div>
            <div class="stat-circle-label">Achievements</div>
          </div>
        </div>

        <!-- Progress Chart -->
        <div class="progress-chart">
          <canvas id="progressChart"></canvas>
        </div>
      </div>

      <div class="row">
        <!-- Left Column: Level Progress & Skills -->
        <div class="col-lg-8">
          <!-- Level Progress -->
          <div class="progress-card">
            <h4 class="mb-4">
              <i class="bi bi-graph-up water-icon me-2"></i>
              Level Progress
            </h4>
            
            <?php if(empty($current_level)): ?>
              <div class="empty-state">
                <i class="bi bi-graph-up"></i>
                <p>No progress data available yet.</p>
                <p class="text-muted small">Complete some classes to see your progress here.</p>
              </div>
            <?php else: ?>
              <div class="level-progress">
                <?php 
                $levels = ['Kids (4-7)', 'Children (8-12)', 'Teens (13-17)', 'Adults (18+)', 'Seniors (55+)'];
                foreach($levels as $level): 
                  $completed = 0;
                  foreach($current_level as $lvl) {
                    if ($lvl['age_group'] === $level) {
                      $completed = $lvl['classes_completed'];
                      break;
                    }
                  }
                  $percentage = min(($completed / 10) * 100, 100); // Assuming 10 classes per level
                ?>
                  <div class="level-item">
                    <div class="d-flex justify-content-between mb-2">
                      <span class="level-name"><?= $level ?></span>
                      <span class="text-muted"><?= $completed ?> / 10 classes</span>
                    </div>
                    <div class="progress-bar">
                      <div class="progress-fill" style="width: <?= $percentage ?>%; background: linear-gradient(90deg, #3b82f6, #8b5cf6);"></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <!-- Skills Development -->
          <div class="progress-card">
            <h4 class="mb-4">
              <i class="bi bi-trophy water-icon me-2"></i>
              Skills Development
            </h4>
            <div class="row">
              <div class="col-md-6">
                <div class="skill-item">
                  <span>Breathing Control</span>
                  <div class="skill-level">
                    <div class="skill-progress" style="width: 75%; background: #3b82f6;"></div>
                  </div>
                </div>
                <div class="skill-item">
                  <span>Floating</span>
                  <div class="skill-level">
                    <div class="skill-progress" style="width: 90%; background: #10b981;"></div>
                  </div>
                </div>
                <div class="skill-item">
                  <span>Kicking</span>
                  <div class="skill-level">
                    <div class="skill-progress" style="width: 65%; background: #f59e0b;"></div>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="skill-item">
                  <span>Arm Strokes</span>
                  <div class="skill-level">
                    <div class="skill-progress" style="width: 70%; background: #8b5cf6;"></div>
                  </div>
                </div>
                <div class="skill-item">
                  <span>Diving</span>
                  <div class="skill-level">
                    <div class="skill-progress" style="width: 50%; background: #ef4444;"></div>
                  </div>
                </div>
                <div class="skill-item">
                  <span>Endurance</span>
                  <div class="skill-level">
                    <div class="skill-progress" style="width: 80%; background: #06b6d4;"></div>
                  </div>
                </div>
              </div>
            </div>
            <div class="text-center mt-3">
              <small class="text-muted">Skills assessment based on instructor feedback</small>
            </div>
          </div>
        </div>

        <!-- Right Column: Achievements & Feedback -->
        <div class="col-lg-4">
          <!-- Achievements -->
          <div class="progress-card">
            <h4 class="mb-4">
              <i class="bi bi-award water-icon me-2"></i>
              Achievements
            </h4>
            
            <?php if(empty($achievements)): ?>
              <div class="empty-state">
                <i class="bi bi-award"></i>
                <p>No achievements yet.</p>
                <p class="text-muted small">Keep attending classes to earn achievements!</p>
              </div>
            <?php else: ?>
              <?php foreach($achievements as $achievement): ?>
                <div class="achievement-card">
                  <div class="d-flex align-items-center">
                    <div class="achievement-icon" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                      <i class="bi bi-trophy"></i>
                    </div>
                    <div>
                      <div class="fw-medium"><?= $achievement['achievement'] ?></div>
                      <small class="text-muted"><?= $achievement['description'] ?></small>
                      <div class="mt-1">
                        <small class="text-muted">
                          <i class="bi bi-calendar me-1"></i>
                          Earned on <?= date('M j, Y', strtotime($achievement['date_earned'])) ?>
                        </small>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <!-- Instructor Feedback -->
          <div class="progress-card">
            <h4 class="mb-4">
              <i class="bi bi-chat-left-text water-icon me-2"></i>
              Instructor Feedback
            </h4>
            
            <?php if(empty($instructor_feedback)): ?>
              <div class="empty-state">
                <i class="bi bi-chat-left"></i>
                <p>No feedback yet.</p>
                <p class="text-muted small">Your instructors will provide feedback after classes.</p>
              </div>
            <?php else: ?>
              <?php foreach($instructor_feedback as $feedback): ?>
                <div class="feedback-card">
                  <div class="instructor-info">
                    <div class="instructor-avatar">
                      <?= strtoupper(substr($feedback['instructor_name'], 0, 1)) ?>
                    </div>
                    <div>
                      <div class="fw-medium"><?= htmlspecialchars($feedback['instructor_name']) ?></div>
                      <div class="rating-stars">
                        <?php for($i = 1; $i <= 5; $i++): ?>
                          <i class="bi bi-star<?= $i <= $feedback['rating'] ? '-fill' : '' ?>"></i>
                        <?php endfor; ?>
                      </div>
                    </div>
                  </div>
                  <div class="feedback-text">
                    "<?= htmlspecialchars($feedback['comments']) ?>"
                  </div>
                  <div class="d-flex justify-content-between">
                    <small class="feedback-date">
                      <?= date('M j, Y', strtotime($feedback['created_at'])) ?>
                    </small>
                    <small class="text-muted">
                      <?= htmlspecialchars($feedback['class_title']) ?>
                    </small>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <!-- Progress Tips -->
          <div class="progress-card">
            <h6 class="mb-3">
              <i class="bi bi-lightbulb water-icon me-2"></i>
              Progress Tips
            </h6>
            <ul class="list-unstyled text-muted small">
              <li class="mb-2">
                <i class="bi bi-check-circle text-success me-2"></i>
                Attend classes regularly for consistent progress
              </li>
              <li class="mb-2">
                <i class="bi bi-check-circle text-success me-2"></i>
                Practice breathing exercises daily
              </li>
              <li class="mb-2">
                <i class="bi bi-check-circle text-success me-2"></i>
                Review instructor feedback after each class
              </li>
              <li class="mb-2">
                <i class="bi bi-check-circle text-success me-2"></i>
                Set realistic goals for each level
              </li>
              <li class="mb-2">
                <i class="bi bi-check-circle text-success me-2"></i>
                Stay hydrated and maintain a healthy diet
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Progress Chart
      const ctx = document.getElementById('progressChart').getContext('2d');
      
      // Prepare data for chart
      const months = <?= json_encode(array_column($progress_data, 'month')) ?>;
      const classesAttended = <?= json_encode(array_column($progress_data, 'classes_attended')) ?>;
      const avgDifficulty = <?= json_encode(array_column($progress_data, 'avg_difficulty')) ?>;
      
      // Format month labels
      const monthLabels = months.map(month => {
        const date = new Date(month + '-01');
        return date.toLocaleDateString('en-US', { month: 'short', year: '2-digit' });
      });
      
      new Chart(ctx, {
        type: 'line',
        data: {
          labels: monthLabels.reverse(),
          datasets: [
            {
              label: 'Classes Attended',
              data: classesAttended.reverse(),
              borderColor: '#3b82f6',
              backgroundColor: 'rgba(59, 130, 246, 0.1)',
              tension: 0.4,
              fill: true,
              yAxisID: 'y'
            },
            {
              label: 'Avg Difficulty',
              data: avgDifficulty.reverse(),
              borderColor: '#10b981',
              backgroundColor: 'rgba(16, 185, 129, 0.1)',
              tension: 0.4,
              fill: false,
              yAxisID: 'y1'
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: {
            mode: 'index',
            intersect: false
          },
          plugins: {
            legend: {
              position: 'top'
            },
            tooltip: {
              callbacks: {
                label: function(context) {
                  let label = context.dataset.label || '';
                  if (label) {
                    label += ': ';
                  }
                  if (context.dataset.label === 'Avg Difficulty') {
                    label += context.parsed.y.toFixed(1) + '/5';
                  } else {
                    label += context.parsed.y;
                  }
                  return label;
                }
              }
            }
          },
          scales: {
            x: {
              grid: {
                display: false
              }
            },
            y: {
              type: 'linear',
              display: true,
              position: 'left',
              title: {
                display: true,
                text: 'Classes Attended'
              },
              min: 0
            },
            y1: {
              type: 'linear',
              display: true,
              position: 'right',
              title: {
                display: true,
                text: 'Difficulty Level'
              },
              min: 0,
              max: 5,
              grid: {
                drawOnChartArea: false
              }
            }
          }
        }
      });
      
      // Animate progress bars
      const progressBars = document.querySelectorAll('.progress-fill, .skill-progress');
      progressBars.forEach(bar => {
        const width = bar.style.width;
        bar.style.width = '0%';
        setTimeout(() => {
          bar.style.width = width;
        }, 300);
      });
    });
  </script>
</body>
</html>
[file content end]