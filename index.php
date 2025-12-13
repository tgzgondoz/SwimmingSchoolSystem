[file name]: index.php (root - updated)
[file content begin]
<?php
// index.php - Public Landing Page
session_start();
include __DIR__ . '/inc/db.php';

// Get school settings
$school_name = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'school_name'")->fetch_assoc()['setting_value'] ?? 'AquaFlow Swimming School';
$school_email = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'school_email'")->fetch_assoc()['setting_value'] ?? 'info@aquaflow.com';
$school_phone = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'school_phone'")->fetch_assoc()['setting_value'] ?? '+263 77 123 4567';
$school_address = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'school_address'")->fetch_assoc()['setting_value'] ?? '123 Swimming Lane, Harare';

// If user is logged in, redirect to appropriate dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            header('Location: admin/index.php');
            break;
        case 'student':
            header('Location: student/index.php');
            break;
        default:
            // Stay on landing page
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($school_name) ?> - Swimming School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #6b7280;
            --accent: #8b5cf6;
        }
        
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }
        
        .hero {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            padding: 80px 0;
        }
        
        .feature-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            margin-bottom: 20px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(59, 130, 246, 0.3);
        }
        
        .btn-outline-primary {
            border: 2px solid #3b82f6;
            color: #3b82f6;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .btn-outline-primary:hover {
            background: #3b82f6;
            color: white;
            transform: translateY(-2px);
        }
        
        .class-card {
            border: none;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .class-card:hover {
            transform: translateY(-5px);
        }
        
        .class-badge {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        footer {
            background: #1f2937;
            color: white;
            padding: 40px 0;
        }
        
        .nav-link {
            color: #6b7280;
            font-weight: 500;
            padding: 8px 16px;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .nav-link:hover {
            color: #3b82f6;
            background: #f3f4f6;
        }
        
        .stats-number {
            font-size: 36px;
            font-weight: 700;
            color: #3b82f6;
            margin-bottom: 5px;
        }
        
        .stats-label {
            color: #6b7280;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm py-3">
        <div class="container">
            <a class="navbar-brand fw-bold text-primary" href="#">
                <i class="bi bi-water me-2"></i><?= htmlspecialchars($school_name) ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="#home">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#classes">Classes</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#about">About</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#contact">Contact</a>
                    </li>
                    <li class="nav-item ms-2">
                        <a href="login.php" class="btn btn-outline-primary">Login</a>
                    </li>
                    <li class="nav-item ms-2">
                        <a href="student/register.php" class="btn btn-primary">Sign Up</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero" id="home">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h1 class="display-4 fw-bold mb-4">Learn to Swim with Confidence</h1>
                    <p class="lead mb-4">Join <?= htmlspecialchars($school_name) ?> - Zimbabwe's premier swimming school offering professional lessons for all ages and skill levels.</p>
                    <div class="d-flex gap-3">
                        <a href="student/register.php" class="btn btn-light btn-lg">
                            <i class="bi bi-person-plus me-2"></i>Start Learning
                        </a>
                        <a href="#classes" class="btn btn-outline-light btn-lg">
                            <i class="bi bi-play-circle me-2"></i>View Classes
                        </a>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="text-center">
                        <i class="bi bi-water" style="font-size: 200px; opacity: 0.2;"></i>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="py-5 bg-light">
        <div class="container">
            <div class="row text-center">
                <div class="col-md-3 mb-4">
                    <div class="stats-number">500+</div>
                    <div class="stats-label">Students Trained</div>
                </div>
                <div class="col-md-3 mb-4">
                    <div class="stats-number">15</div>
                    <div class="stats-label">Certified Instructors</div>
                </div>
                <div class="col-md-3 mb-4">
                    <div class="stats-number">98%</div>
                    <div class="stats-label">Success Rate</div>
                </div>
                <div class="col-md-3 mb-4">
                    <div class="stats-number">24/7</div>
                    <div class="stats-label">Online Support</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="py-5" id="about">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold mb-3">Why Choose Us?</h2>
                <p class="text-muted">Professional swimming instruction for everyone</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="text-center p-4">
                        <div class="feature-icon mx-auto">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <h4 class="fw-bold mb-3">Safety First</h4>
                        <p class="text-muted">Certified lifeguards and safety protocols ensure a secure learning environment.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center p-4">
                        <div class="feature-icon mx-auto">
                            <i class="bi bi-person-badge"></i>
                        </div>
                        <h4 class="fw-bold mb-3">Expert Instructors</h4>
                        <p class="text-muted">Our instructors are certified professionals with years of teaching experience.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center p-4">
                        <div class="feature-icon mx-auto">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <h4 class="fw-bold mb-3">Progress Tracking</h4>
                        <p class="text-muted">Monitor your swimming progress with our digital tracking system.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Classes Section -->
    <section class="py-5 bg-light" id="classes">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold mb-3">Our Swimming Classes</h2>
                <p class="text-muted">Find the perfect class for your age and skill level</p>
            </div>
            <div class="row g-4">
                <?php
                // Get featured classes
                $classes = $conn->query("
                    SELECT c.*, i.name as instructor_name 
                    FROM classes c 
                    LEFT JOIN instructors i ON c.instructor_id = i.id 
                    WHERE c.start_time >= NOW() AND c.slots_available > 0 
                    ORDER BY c.start_time ASC 
                    LIMIT 3
                ")->fetch_all(MYSQLI_ASSOC);
                
                foreach($classes as $class):
                ?>
                <div class="col-md-4">
                    <div class="class-card">
                        <div class="card-body p-4">
                            <span class="class-badge mb-3 d-inline-block"><?= htmlspecialchars($class['age_group']) ?></span>
                            <h5 class="card-title fw-bold"><?= htmlspecialchars($class['title']) ?></h5>
                            <p class="card-text text-muted"><?= htmlspecialchars(substr($class['description'] ?? 'Professional swimming instruction', 0, 100)) ?>...</p>
                            <div class="mb-3">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-person me-2 text-primary"></i>
                                    <small><?= htmlspecialchars($class['instructor_name']) ?></small>
                                </div>
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-calendar me-2 text-primary"></i>
                                    <small><?= date('M j, Y', strtotime($class['start_time'])) ?></small>
                                </div>
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-clock me-2 text-primary"></i>
                                    <small><?= date('g:i A', strtotime($class['start_time'])) ?></small>
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-bold text-success">$<?= number_format($class['price'], 2) ?></span>
                                <a href="login.php" class="btn btn-sm btn-primary">Book Now</a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="text-center mt-4">
                <a href="student/register.php" class="btn btn-primary">
                    <i class="bi bi-eye me-2"></i>View All Classes
                </a>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-5" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); color: white;">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <h2 class="fw-bold mb-3">Ready to Dive In?</h2>
                    <p class="mb-0">Join hundreds of students who have learned to swim with confidence at <?= htmlspecialchars($school_name) ?>.</p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <a href="student/register.php" class="btn btn-light btn-lg">
                        <i class="bi bi-person-plus me-2"></i>Register Now
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer id="contact">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <h4 class="fw-bold mb-4">
                        <i class="bi bi-water me-2"></i><?= htmlspecialchars($school_name) ?>
                    </h4>
                    <p>Professional swimming instruction for all ages in Zimbabwe.</p>
                    <div class="d-flex gap-3">
                        <a href="#" class="text-white"><i class="bi bi-facebook"></i></a>
                        <a href="#" class="text-white"><i class="bi bi-twitter"></i></a>
                        <a href="#" class="text-white"><i class="bi bi-instagram"></i></a>
                    </div>
                </div>
                <div class="col-lg-4 mb-4">
                    <h5 class="fw-bold mb-4">Quick Links</h5>
                    <ul class="list-unstyled">
                        <li class="mb-2"><a href="login.php" class="text-white-50 text-decoration-none">Student Login</a></li>
                        <li class="mb-2"><a href="student/register.php" class="text-white-50 text-decoration-none">Student Registration</a></li>
                        <li class="mb-2"><a href="admin/login.php" class="text-white-50 text-decoration-none">Admin Login</a></li>
                        <li><a href="#contact" class="text-white-50 text-decoration-none">Contact Us</a></li>
                    </ul>
                </div>
                <div class="col-lg-4 mb-4">
                    <h5 class="fw-bold mb-4">Contact Info</h5>
                    <ul class="list-unstyled text-white-50">
                        <li class="mb-2">
                            <i class="bi bi-geo-alt me-2"></i><?= htmlspecialchars($school_address) ?>
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-telephone me-2"></i><?= htmlspecialchars($school_phone) ?>
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-envelope me-2"></i><?= htmlspecialchars($school_email) ?>
                        </li>
                    </ul>
                </div>
            </div>
            <hr class="my-4 border-secondary">
            <div class="text-center text-white-50">
                <p class="mb-0">&copy; <?= date('Y') ?> <?= htmlspecialchars($school_name) ?>. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    </script>
</body>
</html>
[file content end]