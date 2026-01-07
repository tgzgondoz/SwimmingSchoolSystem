<?php
// index.php - Professional Public Landing Page
session_start();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

// Redirect logged-in users to appropriate dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            header('Location: admin/index.php');
            exit();
        case 'student':
            header('Location: student/dashboard.php');
            exit();
    }
}

// Get school settings
$settings = [];
$settings_result = $conn->query("SELECT setting_key, setting_value FROM settings");
if ($settings_result) {
    while ($row = $settings_result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

// Set default values
$school_name = htmlspecialchars($settings['school_name'] ?? 'Elite Swimming Academy');
$school_email = htmlspecialchars($settings['school_email'] ?? 'info@eliteswimacademy.com');
$school_phone = htmlspecialchars($settings['school_phone'] ?? '+263 77 123 4567');
$school_address = htmlspecialchars($settings['school_address'] ?? '123 Swimming Lane, Harare, Zimbabwe');
$school_motto = htmlspecialchars($settings['school_motto'] ?? 'Excellence in Every Stroke');
$school_description = htmlspecialchars($settings['school_description'] ?? 'Professional swimming instruction for all ages and skill levels in Zimbabwe');

// Get school statistics
try {
    $total_students = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'student'")->fetch_assoc()['total'] ?? 500;
    $active_instructors = $conn->query("SELECT COUNT(*) as total FROM instructors WHERE status = 'active'")->fetch_assoc()['total'] ?? 15;
    $total_classes = $conn->query("SELECT COUNT(*) as total FROM classes WHERE start_time >= NOW()")->fetch_assoc()['total'] ?? 25;
} catch (Exception $e) {
    // Fallback values
    $total_students = 500;
    $active_instructors = 15;
    $total_classes = 25;
}

// Get featured classes
$featured_classes = [];
try {
    $classes_stmt = $conn->prepare("
        SELECT c.*, i.name as instructor_name 
        FROM classes c 
        LEFT JOIN instructors i ON c.instructor_id = i.id 
        WHERE c.start_time >= NOW() AND c.slots_available > 0 
        ORDER BY c.created_at DESC 
        LIMIT 3
    ");
    if ($classes_stmt) {
        $classes_stmt->execute();
        $classes_result = $classes_stmt->get_result();
        $featured_classes = $classes_result->fetch_all(MYSQLI_ASSOC) ?: [];
        $classes_stmt->close();
    }
} catch (Exception $e) {
    // Keep empty array
}

// Get testimonials
$testimonials = [
    [
        'name' => 'Tendai Moyo',
        'age' => 'Parent',
        'text' => 'My children have gained so much confidence since joining. The instructors are amazing!',
        'rating' => 5
    ],
    [
        'name' => 'Samantha Chidziva',
        'age' => 'Adult Student',
        'text' => 'I never thought I\'d learn to swim at 40. Patient instructors and great facilities!',
        'rating' => 5
    ],
    [
        'name' => 'David Zhou',
        'age' => 'Competitive Swimmer',
        'text' => 'The advanced training program took my swimming to the next level. Highly recommended!',
        'rating' => 5
    ]
];

// Get current date
$current_year = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $school_name ?> | Professional Swimming Academy</title>
    <meta name="description" content="<?= $school_description ?>">
    <meta name="keywords" content="swimming lessons, learn to swim, swimming classes, Zimbabwe, swimming school, professional instructors">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #dbeafe;
            --secondary: #64748b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            
            --gradient-primary: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            --gradient-dark: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html {
            scroll-behavior: smooth;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--gray-800);
            line-height: 1.6;
            overflow-x: hidden;
        }
        
        h1, h2, h3, h4, h5, h6 {
            font-weight: 700;
            line-height: 1.2;
            color: var(--dark);
        }
        
        /* Navigation */
        .navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 1rem 0;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            border-bottom: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }
        
        .navbar.scrolled {
            padding: 0.75rem 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }
        
        .navbar-brand {
            font-weight: 800;
            font-size: 1.5rem;
            color: var(--primary) !important;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .navbar-brand i {
            font-size: 1.75rem;
        }
        
        .nav-link {
            font-weight: 500;
            color: var(--gray-700) !important;
            padding: 0.5rem 1rem !important;
            border-radius: 6px;
            transition: all 0.3s ease;
        }
        
        .nav-link:hover {
            color: var(--primary) !important;
            background: var(--primary-light);
        }
        
        .nav-link.active {
            color: var(--primary) !important;
            font-weight: 600;
        }
        
        /* Buttons */
        .btn {
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
        }
        
        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.3);
            color: white;
        }
        
        .btn-outline-primary {
            border: 2px solid var(--primary);
            color: var(--primary);
            background: transparent;
        }
        
        .btn-outline-primary:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }
        
        /* Hero Section */
        .hero-section {
            min-height: 100vh;
            background: linear-gradient(rgba(30, 41, 59, 0.85), rgba(30, 41, 59, 0.9)), 
                        url('https://images.unsplash.com/photo-1571019613454-1cb2f99b2d8b?ixlib=rb-4.0.3&auto=format&fit=crop&w=2070&q=80');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            color: white;
            display: flex;
            align-items: center;
            padding-top: 80px;
        }
        
        .hero-content {
            padding: 4rem 0;
        }
        
        .hero-title {
            font-size: 3.5rem;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 1.5rem;
            color: white;
        }
        
        .hero-subtitle {
            font-size: 1.25rem;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 2rem;
            max-width: 600px;
        }
        
        .hero-stats {
            display: flex;
            gap: 2rem;
            margin-top: 3rem;
            flex-wrap: wrap;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: white;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 0.875rem;
            color: rgba(255, 255, 255, 0.8);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        /* Sections */
        section {
            padding: 5rem 0;
        }
        
        .section-header {
            text-align: center;
            margin-bottom: 3rem;
        }
        
        .section-title {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }
        
        .section-subtitle {
            color: var(--gray-600);
            font-size: 1.125rem;
            max-width: 600px;
            margin: 0 auto;
        }
        
        /* Features */
        .features-section {
            background: var(--gray-50);
        }
        
        .feature-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            height: 100%;
            transition: all 0.3s ease;
            border: 1px solid var(--gray-200);
            position: relative;
            overflow: hidden;
        }
        
        .feature-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
            border-color: var(--primary);
        }
        
        .feature-icon {
            width: 60px;
            height: 60px;
            background: var(--primary-light);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .feature-card h4 {
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }
        
        /* Classes */
        .class-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
            height: 100%;
            border: 1px solid var(--gray-200);
        }
        
        .class-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border-color: var(--primary);
        }
        
        .class-image {
            height: 200px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            position: relative;
            overflow: hidden;
        }
        
        .class-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: white;
            color: var(--primary);
            padding: 0.375rem 0.875rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .class-content {
            padding: 1.75rem;
        }
        
        .class-title {
            font-size: 1.25rem;
            margin-bottom: 0.75rem;
        }
        
        .class-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: var(--gray-600);
        }
        
        .class-meta i {
            color: var(--primary);
        }
        
        .class-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 1.5rem;
        }
        
        .class-price span {
            font-size: 0.875rem;
            color: var(--gray-500);
            font-weight: 500;
        }
        
        /* Testimonials */
        .testimonials-section {
            background: var(--gradient-dark);
            color: white;
        }
        
        .testimonials-section .section-title {
            color: white;
        }
        
        .testimonials-section .section-subtitle {
            color: rgba(255, 255, 255, 0.8);
        }
        
        .testimonial-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            height: 100%;
        }
        
        .testimonial-rating {
            color: #fbbf24;
            margin-bottom: 1rem;
            font-size: 1.25rem;
        }
        
        .testimonial-text {
            font-size: 1rem;
            line-height: 1.7;
            margin-bottom: 1.5rem;
            color: rgba(255, 255, 255, 0.9);
        }
        
        .testimonial-author {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .author-avatar {
            width: 48px;
            height: 48px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }
        
        .author-info h6 {
            color: white;
            margin: 0;
            font-size: 1rem;
        }
        
        .author-info p {
            color: rgba(255, 255, 255, 0.7);
            margin: 0;
            font-size: 0.875rem;
        }
        
        /* CTA Section */
        .cta-section {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            text-align: center;
        }
        
        .cta-title {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: white;
        }
        
        .cta-subtitle {
            font-size: 1.125rem;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 2rem;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* Footer */
        footer {
            background: var(--dark);
            color: white;
            padding: 4rem 0 2rem;
        }
        
        .footer-section {
            margin-bottom: 2.5rem;
        }
        
        .footer-logo {
            font-size: 1.5rem;
            font-weight: 800;
            color: white;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .footer-logo i {
            color: var(--primary);
        }
        
        .school-description {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.875rem;
            line-height: 1.7;
            margin-bottom: 1.5rem;
        }
        
        .footer-links h5 {
            color: white;
            font-size: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .footer-links ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .footer-links li {
            margin-bottom: 0.75rem;
        }
        
        .footer-links a {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .footer-links a:hover {
            color: white;
            padding-left: 0.5rem;
        }
        
        .contact-info {
            color: rgba(255, 255, 255, 0.7);
        }
        
        .contact-info i {
            color: var(--primary);
            margin-right: 0.75rem;
            width: 20px;
        }
        
        .social-links {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }
        
        .social-links a {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .social-links a:hover {
            background: var(--primary);
            transform: translateY(-2px);
        }
        
        .copyright {
            text-align: center;
            padding-top: 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.875rem;
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in {
            opacity: 0;
            animation: fadeInUp 0.6s ease forwards;
        }
        
        .delay-1 {
            animation-delay: 0.2s;
        }
        
        .delay-2 {
            animation-delay: 0.4s;
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .hero-title {
                font-size: 2.75rem;
            }
            
            .section-title {
                font-size: 2rem;
            }
            
            .cta-title {
                font-size: 2rem;
            }
        }
        
        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.25rem;
            }
            
            section {
                padding: 3rem 0;
            }
            
            .hero-stats {
                gap: 1.5rem;
            }
            
            .stat-number {
                font-size: 1.75rem;
            }
            
            .feature-card, .class-card, .testimonial-card {
                margin-bottom: 1.5rem;
            }
        }
        
        @media (max-width: 576px) {
            .hero-title {
                font-size: 2rem;
            }
            
            .section-title {
                font-size: 1.75rem;
            }
            
            .btn {
                width: 100%;
                margin-bottom: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="bi bi-droplet-half"></i>
                <?= $school_name ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="#home">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#about">About</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#classes">Classes</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#testimonials">Testimonials</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#contact">Contact</a>
                    </li>
                    <li class="nav-item ms-2">
                        <a href="student/login.php" class="btn btn-outline-primary">Login</a>
                    </li>
                    <li class="nav-item ms-2">
                        <a href="student/register.php" class="btn btn-primary">
                            <i class="bi bi-person-plus me-1"></i> Sign Up
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section" id="home">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <div class="hero-content fade-in">
                        <h1 class="hero-title">Dive into Excellence with <?= $school_name ?></h1>
                        <p class="hero-subtitle"><?= $school_description ?>. Join Zimbabwe's premier swimming academy offering world-class instruction for all ages and skill levels.</p>
                        <div class="d-flex flex-wrap gap-3 mb-5">
                            <a href="student/register.php" class="btn btn-primary">
                                <i class="bi bi-person-plus me-2"></i> Start Your Journey
                            </a>
                            <a href="#classes" class="btn btn-outline-primary" style="background: rgba(255, 255, 255, 0.1); color: white; border-color: white;">
                                <i class="bi bi-play-circle me-2"></i> Explore Classes
                            </a>
                        </div>
                        <div class="hero-stats">
                            <div class="stat-item">
                                <div class="stat-number"><?= number_format($total_students) ?>+</div>
                                <div class="stat-label">Happy Students</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?= $active_instructors ?></div>
                                <div class="stat-label">Certified Instructors</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?= $total_classes ?>+</div>
                                <div class="stat-label">Weekly Classes</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number">98%</div>
                                <div class="stat-label">Success Rate</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <!-- Hero image would go here -->
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="about">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Why Choose <?= $school_name ?>?</h2>
                <p class="section-subtitle">We provide exceptional swimming education with a focus on safety, skill development, and building water confidence.</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4 fade-in">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <h4>Safety First</h4>
                        <p>Certified lifeguards, modern safety equipment, and strict protocols ensure a secure learning environment for all our students.</p>
                    </div>
                </div>
                <div class="col-md-4 fade-in delay-1">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-person-badge"></i>
                        </div>
                        <h4>Expert Instructors</h4>
                        <p>Our certified instructors have years of experience and are passionate about helping students achieve their swimming goals.</p>
                    </div>
                </div>
                <div class="col-md-4 fade-in delay-2">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <h4>Progress Tracking</h4>
                        <p>Monitor your swimming journey with our digital progress tracking system and receive personalized feedback from instructors.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section">
        <div class="container">
            <div class="row g-4 align-items-center">
                <div class="col-lg-6">
                    <h2 class="section-title">Our Approach to Swimming Education</h2>
                    <p class="mb-4">At <?= $school_name ?>, we believe swimming is more than just a skill – it's a life-saving ability and a pathway to confidence and fitness.</p>
                    <ul class="list-unstyled">
                        <li class="mb-3">
                            <i class="bi bi-check-circle-fill text-primary me-2"></i>
                            <strong>Personalized Learning:</strong> Customized programs based on age, skill level, and goals
                        </li>
                        <li class="mb-3">
                            <i class="bi bi-check-circle-fill text-primary me-2"></i>
                            <strong>Modern Facilities:</strong> Temperature-controlled pools and state-of-the-art equipment
                        </li>
                        <li class="mb-3">
                            <i class="bi bi-check-circle-fill text-primary me-2"></i>
                            <strong>Flexible Scheduling:</strong> Morning, afternoon, and weekend classes available
                        </li>
                        <li class="mb-3">
                            <i class="bi bi-check-circle-fill text-primary me-2"></i>
                            <strong>Progress Certificates:</strong> Recognize achievements and track development
                        </li>
                    </ul>
                </div>
                <div class="col-lg-6">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-award"></i>
                        </div>
                        <h4>Certified Excellence</h4>
                        <p>We are proudly certified by the Zimbabwe Swimming Federation and follow international safety standards. Our instructors undergo regular training and certification to ensure the highest quality of instruction.</p>
                        <div class="mt-4">
                            <a href="student/register.php" class="btn btn-primary">
                                <i class="bi bi-calendar-check me-2"></i> Book Your First Lesson
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Classes Section -->
    <section id="classes">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Featured Classes</h2>
                <p class="section-subtitle">Find the perfect swimming class for your age and skill level</p>
            </div>
            
            <?php if (!empty($featured_classes)): ?>
                <div class="row g-4">
                    <?php foreach ($featured_classes as $class): ?>
                        <div class="col-md-4 fade-in">
                            <div class="class-card">
                                <div class="class-image">
                                    <span class="class-badge"><?= htmlspecialchars($class['age_group'] ?? 'All Ages') ?></span>
                                </div>
                                <div class="class-content">
                                    <h3 class="class-title"><?= htmlspecialchars($class['title']) ?></h3>
                                    <div class="class-meta">
                                        <span><i class="bi bi-person"></i> <?= htmlspecialchars($class['instructor_name'] ?? 'Certified Instructor') ?></span>
                                        <span><i class="bi bi-clock"></i> <?= date('g:i A', strtotime($class['start_time'])) ?></span>
                                    </div>
                                    <p class="text-muted mb-3"><?= htmlspecialchars(substr($class['description'] ?? 'Professional swimming instruction', 0, 100)) ?>...</p>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="class-price">
                                            $<?= number_format($class['price'] ?? 0, 2) ?> <span>per session</span>
                                        </div>
                                        <a href="student/register.php" class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-calendar-plus"></i> Book
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="text-center mt-5 fade-in">
                    <a href="student/register.php?view=classes" class="btn btn-primary">
                        <i class="bi bi-eye me-2"></i> View All Classes
                    </a>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <div class="feature-icon mx-auto mb-4" style="background: var(--gray-200); color: var(--gray-600);">
                        <i class="bi bi-calendar-x"></i>
                    </div>
                    <h4 class="text-muted mb-3">New Classes Coming Soon!</h4>
                    <p class="text-muted mb-4">We're currently scheduling our next batch of swimming classes. Check back soon or register to be notified.</p>
                    <a href="student/register.php" class="btn btn-primary">
                        <i class="bi bi-bell me-2"></i> Get Notified
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Testimonials Section -->
    <section class="testimonials-section" id="testimonials">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">What Our Students Say</h2>
                <p class="section-subtitle">Hear from our happy students and parents about their swimming journey with us</p>
            </div>
            <div class="row g-4">
                <?php foreach ($testimonials as $testimonial): ?>
                    <div class="col-md-4 fade-in">
                        <div class="testimonial-card">
                            <div class="testimonial-rating">
                                <?php for ($i = 0; $i < $testimonial['rating']; $i++): ?>
                                    <i class="bi bi-star-fill"></i>
                                <?php endfor; ?>
                            </div>
                            <p class="testimonial-text">"<?= htmlspecialchars($testimonial['text']) ?>"</p>
                            <div class="testimonial-author">
                                <div class="author-avatar">
                                    <?= strtoupper(substr($testimonial['name'], 0, 1)) ?>
                                </div>
                                <div class="author-info">
                                    <h6><?= htmlspecialchars($testimonial['name']) ?></h6>
                                    <p><?= htmlspecialchars($testimonial['age']) ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <div class="container">
            <h2 class="cta-title">Ready to Dive In?</h2>
            <p class="cta-subtitle">Join <?= $school_name ?> today and start your swimming journey. Whether you're a beginner or looking to improve your skills, we have the perfect class for you.</p>
            <div class="d-flex flex-wrap gap-3 justify-content-center">
                <a href="student/register.php" class="btn btn-light" style="color: var(--primary);">
                    <i class="bi bi-person-plus me-2"></i> Sign Up Now
                </a>
                <a href="#contact" class="btn btn-outline-light">
                    <i class="bi bi-telephone me-2"></i> Contact Us
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer id="contact">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 footer-section">
                    <div class="footer-logo">
                        <i class="bi bi-droplet-half"></i>
                        <?= $school_name ?>
                    </div>
                    <p class="school-description"><?= $school_description ?></p>
                    <div class="social-links">
                        <a href="#"><i class="bi bi-facebook"></i></a>
                        <a href="#"><i class="bi bi-twitter"></i></a>
                        <a href="#"><i class="bi bi-instagram"></i></a>
                        <a href="#"><i class="bi bi-youtube"></i></a>
                    </div>
                </div>
                <div class="col-lg-2 footer-section">
                    <h5>Quick Links</h5>
                    <ul class="footer-links">
                        <li><a href="#home">Home</a></li>
                        <li><a href="#about">About Us</a></li>
                        <li><a href="#classes">Classes</a></li>
                        <li><a href="#testimonials">Testimonials</a></li>
                        <li><a href="#contact">Contact</a></li>
                    </ul>
                </div>
                <div class="col-lg-3 footer-section">
                    <h5>Account</h5>
                    <ul class="footer-links">
                        <li><a href="student/login.php">Student Login</a></li>
                        <li><a href="student/register.php">Student Registration</a></li>
                        <li><a href="admin/login.php">Admin Login</a></li>
                        <li><a href="forgot-password.php">Forgot Password</a></li>
                    </ul>
                </div>
                <div class="col-lg-3 footer-section">
                    <h5>Contact Info</h5>
                    <div class="contact-info">
                        <p class="mb-3">
                            <i class="bi bi-geo-alt"></i>
                            <?= $school_address ?>
                        </p>
                        <p class="mb-3">
                            <i class="bi bi-telephone"></i>
                            <?= $school_phone ?>
                        </p>
                        <p class="mb-3">
                            <i class="bi bi-envelope"></i>
                            <?= $school_email ?>
                        </p>
                        <p class="mb-0">
                            <i class="bi bi-clock"></i>
                            Mon-Fri: 8AM-5PM • Sat: 9AM-2PM
                        </p>
                    </div>
                </div>
            </div>
            <div class="copyright">
                <p>&copy; <?= $current_year ?> <?= $school_name ?>. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Navbar scroll effect
            const navbar = document.querySelector('.navbar');
            window.addEventListener('scroll', function() {
                if (window.scrollY > 50) {
                    navbar.classList.add('scrolled');
                } else {
                    navbar.classList.remove('scrolled');
                }
            });

            // Smooth scrolling for anchor links
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetId = this.getAttribute('href');
                    if (targetId === '#') return;
                    
                    const target = document.querySelector(targetId);
                    if (target) {
                        // Close mobile navbar if open
                        const navbarCollapse = document.querySelector('.navbar-collapse');
                        if (navbarCollapse.classList.contains('show')) {
                            const bsCollapse = bootstrap.Collapse.getInstance(navbarCollapse);
                            bsCollapse.hide();
                        }
                        
                        // Scroll to target
                        window.scrollTo({
                            top: target.offsetTop - 80,
                            behavior: 'smooth'
                        });
                        
                        // Update active nav link
                        document.querySelectorAll('.nav-link').forEach(link => {
                            link.classList.remove('active');
                        });
                        this.classList.add('active');
                    }
                });
            });

            // Intersection Observer for animations
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);

            // Observe elements with fade-in class
            document.querySelectorAll('.fade-in').forEach(el => {
                observer.observe(el);
            });

            // Update active nav link based on scroll position
            const sections = document.querySelectorAll('section[id]');
            const navLinks = document.querySelectorAll('.nav-link');
            
            function updateActiveNavLink() {
                let current = '';
                sections.forEach(section => {
                    const sectionTop = section.offsetTop;
                    const sectionHeight = section.clientHeight;
                    if (scrollY >= (sectionTop - 100)) {
                        current = section.getAttribute('id');
                    }
                });

                navLinks.forEach(link => {
                    link.classList.remove('active');
                    if (link.getAttribute('href') === `#${current}`) {
                        link.classList.add('active');
                    }
                });
            }

            window.addEventListener('scroll', updateActiveNavLink);
            updateActiveNavLink(); // Initial call

            // Initialize animations
            setTimeout(() => {
                document.querySelectorAll('.fade-in').forEach(el => {
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                });
            }, 100);
        });
    </script>
</body>
</html>