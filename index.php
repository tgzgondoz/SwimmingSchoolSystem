 <?php
// index.php - Professional Public Landing Page (Fixed)
session_start();
include __DIR__ . '/inc/db.php';

// Get school settings
$school_name = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'school_name'")->fetch_assoc()['setting_value'] ?? 'AquaFlow Swimming School';
$school_email = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'school_email'")->fetch_assoc()['setting_value'] ?? 'info@aquaflow.com';
$school_phone = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'school_phone'")->fetch_assoc()['setting_value'] ?? '+263 77 123 4567';
$school_address = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'school_address'")->fetch_assoc()['setting_value'] ?? '123 Swimming Lane, Harare';

// Get additional school info
$school_motto = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'school_motto'")->fetch_assoc()['setting_value'] ?? 'Swimming Excellence for All';
$school_description = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'school_description'")->fetch_assoc()['setting_value'] ?? 'Professional swimming instruction for all ages and skill levels in Zimbabwe';

// Get school stats - using fallbacks since students table might not exist
try {
    // Try to get total students from users table
    $total_students_result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'");
    $total_students = $total_students_result ? $total_students_result->fetch_assoc()['count'] : 500;
} catch (Exception $e) {
    $total_students = 500; // Fallback value
}

try {
    // Try to get total upcoming classes
    $total_classes_result = $conn->query("SELECT COUNT(*) as count FROM classes WHERE start_time >= NOW()");
    $total_classes = $total_classes_result ? $total_classes_result->fetch_assoc()['count'] : 25;
} catch (Exception $e) {
    $total_classes = 25; // Fallback value
}

try {
    // Try to get certified instructors
    $certified_instructors_result = $conn->query("SELECT COUNT(*) as count FROM instructors");
    $certified_instructors = $certified_instructors_result ? $certified_instructors_result->fetch_assoc()['count'] : 15;
} catch (Exception $e) {
    $certified_instructors = 15; // Fallback value
}

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
    <title><?= htmlspecialchars($school_name) ?> | Waterfalls</title>
    <meta name="description" content="<?= htmlspecialchars($school_description) ?>">
    <meta name="keywords" content="swimming school, learn to swim, swimming lessons, Zimbabwe, professional instructors, swimming classes">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0d6efd;
            --primary-dark: #0a58ca;
            --primary-light: #dbeafe;
            --secondary: #6c757d;
            --accent: #8b5cf6;
            --accent-dark: #7c3aed;
            --success: #10b981;
            --success-dark: #059669;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #06b6d4;
            --light: #f8f9fa;
            --dark: #1e293b;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            line-height: 1.7;
            color: var(--gray-800);
            overflow-x: hidden;
        }

        h1, h2, h3, h4, h5, h6 {
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 1rem;
        }

        .text-gradient {
            background: linear-gradient(90deg, var(--primary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

      /* Navigation */
.navbar {
    padding: 1rem 0;
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1030;
    transition: all 0.3s ease;
}

.navbar.scrolled {
    padding: 0.75rem 0;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
}

.navbar-brand {
    font-family: 'Poppins', sans-serif;
    font-weight: 800;
    font-size: 1.5rem; /* Reduced from 1.75rem */
    color: var(--primary) !important;
    display: flex;
    align-items: center;
    gap: 0.5rem; /* Reduced from 0.75rem */
    text-decoration: none;
    max-width: 50%; /* Limit brand width */
    flex-shrink: 1; /* Allow shrinking */
    overflow: hidden;
}

.brand-icon {
    width: 40px; /* Reduced from 44px */
    height: 40px; /* Reduced from 44px */
    background: linear-gradient(135deg, var(--primary), var(--accent));
    border-radius: 10px; /* Reduced from 12px */
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.25rem; /* Reduced from 1.5rem */
    box-shadow: 0 4px 12px rgba(13, 110, 253, 0.2);
    flex-shrink: 0; /* Don't shrink icon */
}

.navbar-brand span {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    /*max-width: calc(100% - 60px); /* Limit text width */
}

.navbar-nav {
    align-items: center;
    gap: 0.25rem; /* Reduced gap */
    flex-wrap: nowrap;
    min-width: 0; /* Allow shrinking */
}

.nav-link {
    font-weight: 600;
    color: var(--gray-700) !important;
    padding: 0.625rem 0.875rem !important; /* Reduced padding */
    border-radius: 8px;
    transition: all 0.3s ease;
    font-size: 0.95rem; /* Slightly smaller font */
    text-decoration: none;
    white-space: nowrap;
    flex-shrink: 0;
}

.nav-link:hover {
    color: var(--primary) !important;
    background: var(--primary-light);
}

.nav-link.active {
    color: white !important;
    background: linear-gradient(90deg, var(--primary), var(--primary-dark));
    box-shadow: 0 4px 12px rgba(13, 110, 253, 0.2);
}

/* Button styling within navbar */
.navbar .btn {
    border-radius: 8px;
    padding: 0.625rem 1.25rem; /* Reduced padding */
    font-weight: 600;
    font-size: 0.95rem; /* Slightly smaller */
    min-height: 44px; /* Reduced from 48px */
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.375rem; /* Reduced gap */
    white-space: nowrap;
    text-decoration: none;
    border: none;
    transition: all 0.3s ease;
    flex-shrink: 0;
}

.navbar .btn-primary {
    background: linear-gradient(90deg, var(--primary), var(--primary-dark));
    color: white;
    box-shadow: 0 4px 12px rgba(13, 110, 253, 0.2);
}

.navbar .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(13, 110, 253, 0.3);
}

.navbar .btn-secondary {
    background: rgba(255, 255, 255, 0.1);
    color: var(--primary);
    border: 2px solid var(--primary);
}

.navbar .btn-secondary:hover {
    background: var(--primary);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(13, 110, 253, 0.2);
}

/* Adjust spacing for nav items with buttons */
.navbar-nav .nav-item.ms-2 {
    margin-left: 0.5rem !important; /* Reduced from 0.75rem */
}

/* Optimize for smaller desktop screens */
@media (max-width: 1199px) {
    .navbar-brand {
        font-size: 1.35rem;
        max-width: 45%;
    }
    
    .brand-icon {
        width: 36px;
        height: 36px;
        font-size: 1.1rem;
    }
    
    .nav-link {
        padding: 0.5rem 0.75rem !important;
        font-size: 0.9rem;
    }
    
    .navbar .btn {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
        min-height: 40px;
    }
    
    .navbar-nav .nav-item.ms-2 {
        margin-left: 0.375rem !important;
    }
}

/* Mobile menu styling */
@media (max-width: 991px) {
    .navbar-collapse {
        padding-top: 1rem;
    }
    
    .navbar-nav {
        gap: 0.5rem;
        width: 100%;
    }
    
    .nav-item {
        width: 100%;
    }
    
    .nav-link {
        padding: 0.875rem !important;
        justify-content: center;
        text-align: center;
        font-size: 1rem;
    }
    
    .navbar-nav .nav-item.ms-2 {
        margin-left: 0 !important;
        margin-top: 0.5rem;
    }
    
    .navbar .btn {
        width: 100%;
        justify-content: center;
        padding: 0.875rem 1.5rem;
        font-size: 1rem;
        min-height: 48px;
    }
    
    .navbar-toggler {
        border: none;
        padding: 0.5rem;
        font-size: 1.25rem;
        box-shadow: none;
    }
    
    .navbar-toggler:focus {
        box-shadow: none;
        outline: none;
    }
    
    .navbar-brand {
        max-width: 70%; /* More space on mobile */
        font-size: 1.4rem;
    }
}

/* Extra small screens */
@media (max-width: 576px) {
    .navbar-brand {
        font-size: 1.25rem;
        max-width: 60%;
    }
    
    .brand-icon {
        width: 34px;
        height: 34px;
        font-size: 1rem;
    }
}

/* Ensure navbar container uses available space efficiently */
.navbar > .container {
    padding-right: 12px;
    padding-left: 12px;
}

/* Compress navbar on very small screens */
@media (max-width: 360px) {
    .navbar-brand {
        font-size: 1.1rem;
    }
    
    .brand-icon {
        width: 32px;
        height: 32px;
        font-size: 0.9rem;
    }
    
    .navbar-brand span {
        display: none; /* Hide full name on very small screens */
    }
    
    .navbar-brand::after {
        content: "AFSS";
        font-size: 1.1rem;
        font-weight: 800;
        color: var(--primary);
    }
}

/* Prevent navbar items from wrapping on desktop */
@media (min-width: 992px) {
    .navbar-collapse {
        flex-grow: 0; /* Don't take extra space */
        min-width: 0; /* Allow shrinking */
    }
    
    .navbar-nav {
        justify-content: flex-end;
        flex-wrap: nowrap;
    }
    
    /* Compact layout for nav items */
    .nav-item:not(.ms-2) {
        margin-right: 0.125rem;
    }
}

/* Hide text on buttons when space is tight */
@media (max-width: 1100px) and (min-width: 992px) {
    .navbar .btn .btn-text {
        display: none;
    }
    
    .navbar .btn {
        padding: 0.625rem;
        min-width: 44px;
    }
    
    .navbar .btn i {
        margin: 0;
    }
}
/* Hover effect for active state */
.nav-link.active:hover {
    background: linear-gradient(90deg, var(--primary-dark), var(--primary));
    transform: translateY(-2px);
}

        /* Hero Section */
        .hero-section {
            min-height: 100vh;
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            padding-top: 80px;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            color: white;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800" opacity="0.1"><path d="M0,0L48,32C96,64,192,128,288,149.3C384,171,480,149,576,138.7C672,128,768,128,864,138.7C960,149,1056,171,1152,181.3C1248,192,1344,192,1392,192L1440,192L1440,800L1392,800C1344,800,1248,800,1152,800C1056,800,960,800,864,800C768,800,672,800,576,800C480,800,384,800,288,800C192,800,96,800,48,800L0,800Z" fill="white"/></svg>');
            background-size: cover;
            background-position: center;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            padding: 4rem 0;
        }

        .hero-title {
            font-size: 3.5rem;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 1.5rem;
        }

        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.5rem;
            }
        }

        .hero-subtitle {
            font-size: 1.25rem;
            opacity: 0.9;
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
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.875rem;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Buttons */
        .btn-primary {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: 10px;
            padding: 1rem 2rem;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(13, 110, 253, 0.3);
            color: white;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 10px;
            padding: 1rem 2rem;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            backdrop-filter: blur(10px);
        }

        .btn-secondary:hover {
            background: white;
            color: var(--primary);
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(255, 255, 255, 0.2);
        }

        .btn-accent {
            background: linear-gradient(90deg, var(--accent), var(--accent-dark));
            color: white;
            border: none;
            border-radius: 10px;
            padding: 1rem 2rem;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .btn-accent:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(139, 92, 246, 0.3);
            color: white;
        }

        /* Sections */
        section {
            padding: 5rem 0;
        }

        .section-title {
            text-align: center;
            margin-bottom: 3rem;
        }

        .section-title h2 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .section-title .subtitle {
            color: var(--gray-600);
            font-size: 1.125rem;
            max-width: 600px;
            margin: 0 auto;
        }

        /* Features */
        .feature-card {
            background: white;
            border-radius: 15px;
            padding: 2.5rem;
            height: 100%;
            transition: all 0.3s ease;
            border: 1px solid var(--gray-200);
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
        }

        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }

        .feature-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.75rem;
            margin-bottom: 1.5rem;
        }

        .feature-card h4 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        /* Class Cards */
        .class-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            height: 100%;
            border: 1px solid var(--gray-200);
        }

        .class-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .class-header {
            height: 200px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            position: relative;
            overflow: hidden;
        }

        .class-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .class-body {
            padding: 2rem;
        }

        .class-title {
            font-size: 1.5rem;
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
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 1.5rem;
        }

        .class-price span {
            font-size: 0.875rem;
            color: var(--gray-500);
            font-weight: 500;
        }

        
    

        /* Footer */
        footer {
            background: var(--gray-900);
            color: white;
            padding: 5rem 0 2rem;
        }

        .school-description {
    color: var(--gray-400);
    font-size: 0.875rem;
    line-height: 1.7;
    margin-bottom: 1.5rem;
}

        .footer-section {
            margin-bottom: 3rem;
        }

        .footer-section h5 {
            font-size: 1rem;
            margin-bottom: 1.5rem;
            color: white;
        }

        .footer-links {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .footer-links li {
            margin-bottom: 0.75rem;
        }

        .footer-links a {
            color: var(--gray-400);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .footer-links a:hover {
            color: white;
            padding-left: 0.5rem;
        }

        .contact-info {
            color: var(--gray-400);
        }

        .contact-info i {
            color: var(--primary);
            margin-right: 0.75rem;
            width: 20px;
        }

        .social-links {
            display: flex;
            gap: 0.875rem;
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
            transform: translateY(-3px);
        }

        .copyright {
            text-align: center;
            padding-top: 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--gray-500);
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

        .fade-in-up {
            animation: fadeInUp 0.8s ease forwards;
        }

        .delay-1 {
            animation-delay: 0.2s;
        }

        .delay-2 {
            animation-delay: 0.4s;
        }

        .delay-3 {
            animation-delay: 0.6s;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            section {
                padding: 3rem 0;
            }
            
            .hero-title {
                font-size: 2.5rem;
            }
            
            .section-title h2 {
                font-size: 2rem;
            }
            
            .feature-card, .class-card {
                margin-bottom: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light">
        <div class="container">
            <a class="navbar-brand" href="#">
                <div class="brand-icon">
                    <i class="bi bi-droplet"></i>
                </div>
                <span><?= htmlspecialchars($school_name) ?></span>
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
                        <a class="nav-link" href="#contact">Contact</a>
                    </li>
                    <li class="nav-item ms-2">
                        <a href="student/login.php" class="btn btn-secondary">Login</a>
                    </li>
                    <li class="nav-item ms-2">
                        <a href="student/register.php" class="btn btn-primary">
                            <i class="bi bi-person-plus"></i> Sign Up
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section" id="home">
        <div class="container">
            <div class="hero-content">
                <h1 class="hero-title fade-in-up">
                    Where Confidence Flows &<br>
                    <span>Champions Grow</span>
                </h1>
                <p class="hero-subtitle fade-in-up delay-1">
                    <?= htmlspecialchars($school_description) ?>. Join Zimbabwe's premier swimming school offering 
                    professional lessons for all ages and skill levels.
                </p>
                <div class="d-flex flex-wrap gap-3 mb-5 fade-in-up delay-2">
                    <a href="student/register.php" class="btn btn-primary">
                        <i class="bi bi-person-plus"></i> Start Your Journey
                    </a>
                    <a href="#classes" class="btn btn-secondary">
                        <i class="bi bi-play-circle"></i> Explore Classes
                    </a>
                </div>
                <div class="hero-stats fade-in-up delay-3">
                    <div class="stat-item">
                        <div class="stat-number"><?= number_format($total_students) ?>+</div>
                        <div class="stat-label">Happy Students</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?= $certified_instructors ?></div>
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
    </section>

    <!-- Features Section -->
    <section id="about">
        <div class="container">
            <div class="section-title">
                <h2>Why Choose <span class="text-gradient"><?= htmlspecialchars($school_name) ?></span>?</h2>
                <p class="subtitle">We provide exceptional swimming education with a focus on safety, skill development, and fun.</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="feature-card fade-in-up">
                        <div class="feature-icon">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <h4>Safety First Approach</h4>
                        <p>Certified lifeguards, modern safety equipment, and strict protocols ensure a secure learning environment for all students.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card fade-in-up delay-1">
                        <div class="feature-icon">
                            <i class="bi bi-person-badge"></i>
                        </div>
                        <h4>Expert Instructors</h4>
                        <p>Our certified instructors have years of experience and are passionate about helping students achieve their swimming goals.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card fade-in-up delay-2">
                        <div class="feature-icon">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <h4>Progress Tracking</h4>
                        <p>Monitor your swimming journey with our digital progress tracking system and personalized feedback.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Classes Section -->
    <section class="bg-light" id="classes">
        <div class="container">
            <div class="section-title">
                <h2>Featured <span class="text-gradient">Classes</span></h2>
                <p class="subtitle">Find the perfect swimming class for your age and skill level</p>
            </div>
            <?php
            // Get featured classes with error handling
            try {
                $classes_result = $conn->query("
                    SELECT c.*, i.name as instructor_name 
                    FROM classes c 
                    LEFT JOIN instructors i ON c.instructor_id = i.id 
                    WHERE c.start_time >= NOW() AND c.slots_available > 0 
                    ORDER BY c.start_time ASC 
                    LIMIT 3
                ");
                $classes = $classes_result ? $classes_result->fetch_all(MYSQLI_ASSOC) : [];
            } catch (Exception $e) {
                $classes = [];
            }
            
            if (!empty($classes)):
            ?>
            <div class="row g-4">
                <?php foreach($classes as $class): ?>
                <div class="col-md-4">
                    <div class="class-card fade-in-up">
                        <div class="class-header">
                            <span class="class-badge"><?= htmlspecialchars($class['age_group']) ?></span>
                        </div>
                        <div class="class-body">
                            <h3 class="class-title"><?= htmlspecialchars($class['title']) ?></h3>
                            <div class="class-meta">
                                <span><i class="bi bi-person"></i> <?= htmlspecialchars($class['instructor_name'] ?? 'Certified Instructor') ?></span>
                                <span><i class="bi bi-calendar"></i> <?= date('M j, Y', strtotime($class['start_time'])) ?></span>
                            </div>
                            <p class="text-muted"><?= htmlspecialchars(substr($class['description'] ?? 'Professional swimming instruction', 0, 120)) ?>...</p>
                            <div class="class-price">
                                $<?= number_format($class['price'] ?? 0, 2) ?> <span>per session</span>
                            </div>
                            <a href="student/register.php" class="btn btn-accent w-100">
                                <i class="bi bi-calendar-plus"></i> Book Now
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center py-5">
                <div class="feature-icon mx-auto mb-4" style="background: var(--gray-300);">
                    <i class="bi bi-calendar-x"></i>
                </div>
                <h4 class="text-muted mb-3">Classes Coming Soon!</h4>
                <p class="text-muted">We're currently scheduling our next batch of swimming classes. Check back soon or register to be notified.</p>
                <a href="student/register.php" class="btn btn-primary mt-3">
                    <i class="bi bi-bell me-2"></i> View All Classes
                </a>
            </div>
            <?php endif; ?>
            
        </div>
    </section>

    <!-- Footer -->
    <footer id="contact">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 footer-section">
                    <h5>
                        <div class="brand-icon d-inline-flex align-items-center justify-content-center me-2">
                            <i class="bi bi-droplet"></i>
                        </div>
                        <?= htmlspecialchars($school_name) ?>
                    </h5>
                    <p class="school-description"><?= htmlspecialchars($school_description) ?></p>
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
                            <?= htmlspecialchars($school_address) ?>
                        </p>
                        <p class="mb-3">
                            <i class="bi bi-telephone"></i>
                            <?= htmlspecialchars($school_phone) ?>
                        </p>
                        <p class="mb-3">
                            <i class="bi bi-envelope"></i>
                            <?= htmlspecialchars($school_email) ?>
                        </p>
                        <p class="mb-0">
                            <i class="bi bi-clock"></i>
                            Mon-Fri: 8AM-5PM
                        </p>
                    </div>
                </div>
            </div>
            <div class="copyright">
                <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($school_name) ?>. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Navbar scroll effect
            window.addEventListener('scroll', function() {
                const navbar = document.querySelector('.navbar');
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
                            navbarCollapse.classList.remove('show');
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
                        entry.target.classList.add('fade-in-up');
                    }
                });
            }, observerOptions);

            // Observe elements with animation classes
            document.querySelectorAll('.feature-card, .class-card, .testimonial-card').forEach(el => {
                observer.observe(el);
            });

            // Initialize animations on page load
            setTimeout(() => {
                document.querySelectorAll('.fade-in-up').forEach(el => {
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                });
            }, 100);

            // Update active nav link based on scroll position
            const sections = document.querySelectorAll('section[id]');
            const navLinks = document.querySelectorAll('.nav-link');
            
            window.addEventListener('scroll', function() {
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
            });
        });
    </script>
</body>
</html>