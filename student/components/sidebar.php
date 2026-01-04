<?php
<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<style>
  .sidebar {
    width: 260px;
    background: #ffffff;
    box-shadow: 2px 0 8px rgba(15,23,42,0.06);
    min-height: 100vh;
    position: fixed;
    left: 0;
    top: 0;
    padding: 24px 0;
    overflow-y: auto;
  }

  .main-content { margin-left: 260px; min-height: 100vh; }

  .sidebar-logo { display: flex; align-items: center; gap: 12px; padding: 0 20px; margin-bottom: 32px; text-decoration: none; color: #111827; font-weight: 700; }
  .sidebar-logo i { font-size: 24px; color: #2563eb; }

  .sidebar-menu { list-style: none; margin: 0; padding: 0; }
  .sidebar-menu li { margin: 0; }

  .sidebar-menu a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 20px;
    color: #6b7280;
    text-decoration: none;
    transition: all 0.2s;
    border-left: 3px solid transparent;
  }

  .sidebar-menu a:hover { background: #f3f4f6; color: #111827; }
  .sidebar-menu a.active { background: #eff6ff; border-left-color: #2563eb; color: #2563eb; font-weight: 600; }

  .sidebar-menu i { width: 20px; text-align: center; }

  .sidebar-bottom { position: absolute; bottom: 20px; left: 0; right: 0; padding: 0 20px; display: flex; gap: 12px; }
  .sidebar-bottom a { flex: 1; padding: 10px; border-radius: 8px; text-align: center; font-size: 13px; text-decoration: none; }
  .sidebar-bottom .btn-logout { background: #fee2e2; color: #991b1b; }
  .sidebar-bottom .btn-logout:hover { background: #fecaca; }

  @media (max-width: 768px) {
    .sidebar { width: 200px; }
    .main-content { margin-left: 0; }
  }
</style>

<div class="sidebar">
  <a href="index.php" class="sidebar-logo">
    <i class="bi bi-water"></i>
    <span>Swimming</span>
  </a>

  <ul class="sidebar-menu">
    <li><a href="index.php" class="<?= $current_page === 'index.php' ? 'active' : '' ?>"><i class="bi bi-grid-fill"></i>Dashboard</a></li>
    <li><a href="bookings.php" class="<?= $current_page === 'bookings.php' ? 'active' : '' ?>"><i class="bi bi-calendar-check"></i>My Classes</a></li>
    <li><a href="profile.php" class="<?= $current_page === 'profile.php' ? 'active' : '' ?>"><i class="bi bi-person-fill"></i>Profile</a></li>
  </ul>

  <div class="sidebar-bottom">
    <form method="post" action="logout.php" style="width:100%;margin:0;">
      <button type="submit" name="confirm_logout" value="1" class="btn-logout" style="width:100%;border:none;background:none;padding:10px;">
        <i class="bi bi-box-arrow-right"></i> Logout
      </button>
    </form>
  </div>
</div>