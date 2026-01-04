<?php
// admin/components/sidebar.php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<div class="sidebar">
  <div class="logo">
    <i class="bi bi-water" style="font-size: 24px;"></i>
    <h3>AquaFlow</h3>
  </div>
  
  <ul class="nav">
    <li class="nav-item">
      <a href="index.php" class="nav-link <?= $current_page === 'index.php' ? 'active' : '' ?>">
        <i class="bi bi-speedometer2"></i>
        <span>Dashboard</span>
      </a>
    </li>
    <li class="nav-item">
      <a href="students.php" class="nav-link <?= $current_page === 'students.php' ? 'active' : '' ?>">
        <i class="bi bi-people"></i>
        <span>Students</span>
      </a>
    </li>
    <li class="nav-item">
      <a href="classes.php" class="nav-link <?= $current_page === 'classes.php' ? 'active' : '' ?>">
        <i class="bi bi-calendar-week"></i>
        <span>Classes</span>
      </a>
    </li>
    <li class="nav-item">
      <a href="payments.php" class="nav-link <?= $current_page === 'payments.php' ? 'active' : '' ?>">
        <i class="bi bi-credit-card"></i>
        <span>Payments</span>
      </a>
    </li>
    <li class="nav-item">
      <a href="analytics.php" class="nav-link <?= $current_page === 'analytics.php' ? 'active' : '' ?>">
        <i class="bi bi-graph-up"></i>
        <span>Analytics</span>
      </a>
    </li>
    <li class="nav-item">
      <a href="settings.php" class="nav-link <?= $current_page === 'settings.php' ? 'active' : '' ?>">
        <i class="bi bi-gear"></i>
        <span>Settings</span>
      </a>
    </li>
    <li class="nav-item mt-auto">
      <form method="post" action="logout.php" style="margin:0;">
        <button type="submit" name="confirm_logout" value="1" class="nav-link text-danger" style="background:none;border:none;width:100%;text-align:left;padding:12px 16px;">
          <i class="bi bi-box-arrow-right"></i>
          <span>Logout</span>
        </button>
      </form>
    </li>
  </ul>
</div>

<button class="menu-toggle d-lg-none">
  <i class="bi bi-list"></i>
</button>

<style>
/* Sidebar fixes */
.sidebar {
  width: 260px;
  position: fixed;
  left: 0;
  top: 0;
  bottom: 0;
  background: linear-gradient(180deg, var(--primary) 0%, var(--primary-dark) 100%);
  padding: 20px 0;
  color: #fff;
  box-shadow: 4px 0 20px rgba(16,24,40,0.06);
  z-index: 1000;
  transition: transform 0.3s ease;
  display: flex;
  flex-direction: column;
}

.sidebar .logo {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 6px 24px 20px;
  border-bottom: 1px solid rgba(255,255,255,0.06);
  margin-bottom: 0;
}

.sidebar .logo h3 {
  margin: 0;
  font-weight: 700;
  font-size: 20px;
}

.sidebar .nav {
  margin-top: 0;
  padding-left: 0;
  list-style: none;
  flex: 1;
  display: flex;
  flex-direction: column;
  padding: 0 12px;
}

.sidebar .nav-item {
  margin-bottom: 4px;
  flex-shrink: 0;
}

.sidebar .nav-link {
  display: flex;
  gap: 12px;
  align-items: center;
  padding: 12px 16px;
  color: rgba(255,255,255,0.8);
  border-radius: 8px;
  text-decoration: none;
  font-weight: 500;
  transition: all 0.3s ease;
  border: none;
}

.sidebar .nav-link i {
  font-size: 16px;
  width: 20px;
  text-align: center;
  flex-shrink: 0;
}

.sidebar .nav-link span {
  flex: 1;
  white-space: nowrap;
}

.sidebar .nav-link:hover {
  background: rgba(255,255,255,0.08);
  color: #fff;
  transform: translateX(2px);
}

.sidebar .nav-link.active {
  background: rgba(255,255,255,0.15);
  color: #fff;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  font-weight: 600;
}

.sidebar .nav-link.text-danger {
  color: rgba(255,255,255,0.7) !important;
}

.sidebar .nav-link.text-danger:hover {
  background: rgba(220,53,69,0.2);
  color: #fff !important;
}

/* Main content adjustment */
.main-content {
  margin-left: 260px;
  padding: 28px;
  transition: margin-left 0.3s ease;
  min-height: 100vh;
  background: var(--bg);
}

/* Mobile menu toggle */
.menu-toggle {
  display: none;
  background: none;
  border: none;
  font-size: 24px;
  cursor: pointer;
  color: #374151;
  position: fixed;
  top: 20px;
  left: 20px;
  z-index: 1001;
  background: white;
  border-radius: 8px;
  padding: 8px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

/* Dark mode support */
body.dark-mode .sidebar {
  background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
}

body.dark-mode .menu-toggle {
  background: #374151;
  color: #f9fafb;
}

/* Responsive */
@media (max-width: 992px) {
  .sidebar {
    transform: translateX(-100%);
  }
  
  .sidebar.active {
    transform: translateX(0);
  }
  
  .main-content {
    margin-left: 0;
    padding: 16px;
  }
  
  .menu-toggle {
    display: block;
  }
}

/* Ensure main content doesn't get affected by sidebar active states */
.dashboard-container,
.card,
.table,
.stat-card {
  position: relative;
  z-index: 1;
}

/* Fix for table layout in dashboard */
.table-layout-fixed {
  border-collapse: separate;
  border-spacing: 0;
  table-layout: fixed;
  width: 100%;
}

.table-layout-fixed td {
  vertical-align: top;
  border: none;
  padding: 10px;
  height: 100%;
}

.table-layout-fixed tr {
  height: 1px;
}

/* Ensure cards maintain their layout */
.compact-card {
  min-height: 300px;
  position: relative;
  overflow: hidden;
}

.chart-container {
  position: relative;
  height: 100%;
}

/* Prevent content shifting */
.stat-card,
.card {
  transform: none !important;
}

.stat-card:hover {
  transform: translateY(-2px) !important;
}
</style>

<script>
// Mobile menu functionality
document.addEventListener('DOMContentLoaded', function() {
  const menuToggle = document.querySelector('.menu-toggle');
  const sidebar = document.querySelector('.sidebar');
  
  if (menuToggle && sidebar) {
    menuToggle.addEventListener('click', () => {
      sidebar.classList.toggle('active');
    });
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
      if (window.innerWidth <= 992) {
        const isClickInsideSidebar = sidebar.contains(event.target);
        const isClickOnToggle = menuToggle.contains(event.target);
        
        if (!isClickInsideSidebar && !isClickOnToggle && sidebar.classList.contains('active')) {
          sidebar.classList.remove('active');
        }
      }
    });
  }
  
  // Close sidebar when navigating on mobile
  const navLinks = document.querySelectorAll('.sidebar .nav-link');
  navLinks.forEach(link => {
    link.addEventListener('click', () => {
      if (window.innerWidth <= 992) {
        sidebar.classList.remove('active');
      }
    });
  });
});
</script>