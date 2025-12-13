<?php
// admin/components/header.php

// Only declare function if it doesn't exist to avoid redeclaration
if (!function_exists('getTimeBasedGreeting')) {
    function getTimeBasedGreeting() {
        $hour = date('H');
        if ($hour < 12) return 'Good morning';
        if ($hour < 17) return 'Good afternoon';
        return 'Good evening';
    }
}

$user = getCurrentUser($conn);
$current_time = date('l, F j, Y');
$greeting = getTimeBasedGreeting();
?>

<header class="modern-header">
    <div class="header-content">
        <!-- Left Section: Page Info -->
        <div class="header-left">
            <div class="page-info">
                <h4 class="greeting"><?= $greeting ?>, <?= htmlspecialchars($user['name'] ?? 'Admin') ?></h4>
                <div class="page-subtitle">
          
    
                    <span class="current-date"><?= $current_time ?></span>
                </div>
            </div>
        </div>

        <!-- Right Section: Actions -->
        <div class="header-actions">
            <!-- Search Bar -->
            <div class="search-container">
                <i class="bi bi-search search-icon"></i>
                <input type="text" class="search-input" placeholder="Search...">
            </div>

            <!-- Notifications -->
            <div class="action-item dropdown">
                <button class="action-btn notification-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-bell"></i>
                    <span class="notification-badge">3</span>
                </button>
                <div class="dropdown-menu notification-dropdown">
                    <div class="dropdown-header">
                        <h6>Notifications</h6>
                        <a href="#" class="mark-read">Mark all read</a>
                    </div>
                    <div class="notification-list">
                        <div class="notification-item">
                            <div class="notification-icon primary">
                                <i class="bi bi-person-plus"></i>
                            </div>
                            <div class="notification-content">
                                <p class="notification-text">New student registration</p>
                                <span class="notification-time">5 min ago</span>
                            </div>
                        </div>
                        <div class="notification-item">
                            <div class="notification-icon success">
                                <i class="bi bi-currency-dollar"></i>
                            </div>
                            <div class="notification-content">
                                <p class="notification-text">Payment received</p>
                                <span class="notification-time">2 hours ago</span>
                            </div>
                        </div>
                    </div>
                    <div class="dropdown-footer">
                        <a href="notifications.php" class="view-all">View all notifications</a>
                    </div>
                </div>
            </div>

            <!-- User Profile -->
            <div class="action-item dropdown">
                <button class="user-profile" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-avatar">
                        <img src="https://ui-avatars.com/api/?name=<?= urlencode($user['name'] ?? 'Admin') ?>&background=3B82F6&color=fff" alt="<?= htmlspecialchars($user['name'] ?? 'Admin') ?>">
                    </div>
                    <div class="user-info">
                        <span class="user-name"><?= htmlspecialchars($user['name'] ?? 'Admin User') ?></span>
                        <span class="user-role">Administrator</span>
                    </div>
                    <i class="bi bi-chevron-down dropdown-arrow"></i>
                </button>
                <div class="dropdown-menu profile-dropdown">
                    <div class="profile-header">
                        <div class="user-avatar large">
                            <img src="https://ui-avatars.com/api/?name=<?= urlencode($user['name'] ?? 'Admin') ?>&background=3B82F6&color=fff&size=48" alt="<?= htmlspecialchars($user['name'] ?? 'Admin') ?>">
                        </div>
                        <div class="user-details">
                            <h6><?= htmlspecialchars($user['name'] ?? 'Admin User') ?></h6>
                            <span><?= htmlspecialchars($user['email'] ?? 'admin@aquaflow.com') ?></span>
                        </div>
                    </div>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="profile.php">
                        <i class="bi bi-person"></i>
                        <span>My Profile</span>
                    </a>
                    <a class="dropdown-item" href="settings.php">
                        <i class="bi bi-gear"></i>
                        <span>Settings</span>
                    </a>
                    <a class="dropdown-item" href="help.php">
                        <i class="bi bi-question-circle"></i>
                        <span>Help & Support</span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item logout-item" href="logout.php">
                        <i class="bi bi-box-arrow-right"></i>
                        <span>Sign out</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</header>

<style>
.modern-header {
    background: #ffffff;
    border-bottom: 1px solid #f1f5f9;
    padding: 0;
    position: sticky;
    top: 0;
    z-index: 1000;
    backdrop-filter: blur(10px);
    background: rgba(255, 255, 255, 0.95);
}

.header-content {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 2rem;
    max-width: 1400px;
    margin: 0 auto;
    gap: 2rem;
}

.header-left {
    flex: 1;
}

.page-info .page-title {
    font-size: 1.75rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 0.25rem 0;
    line-height: 1.2;
}

.page-subtitle {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 0.875rem;
    color: #64748b;
}

.page-subtitle .greeting {
    font-weight: 500;
    color: #475569;
}

.page-subtitle .separator {
    color: #cbd5e1;
}

.page-subtitle .current-date {
    color: #64748b;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 1rem;
}

/* Search Bar */
.search-container {
    position: relative;
    width: 280px;
}

.search-icon {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 0.875rem;
}

.search-input {
    width: 100%;
    padding: 0.75rem 1rem 0.75rem 2.5rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
    background: #f8fafc;
    font-size: 0.875rem;
    transition: all 0.2s ease;
    color: #1e293b;
}

.search-input:focus {
    outline: none;
    border-color: #3b82f6;
    background: #ffffff;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.search-input::placeholder {
    color: #94a3b8;
}

/* Action Items */
.action-item {
    position: relative;
}

.action-btn {
    background: none;
    border: none;
    padding: 0.5rem;
    border-radius: 0.75rem;
    color: #64748b;
    font-size: 1.125rem;
    transition: all 0.2s ease;
    cursor: pointer;
    position: relative;
}

.action-btn:hover {
    background: #f1f5f9;
    color: #475569;
}

.notification-badge {
    position: absolute;
    top: 0.25rem;
    right: 0.25rem;
    background: #ef4444;
    color: white;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 0.25rem 0.375rem;
    border-radius: 50%;
    min-width: 1.25rem;
    height: 1.25rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #ffffff;
}

/* User Profile */
.user-profile {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    background: none;
    border: none;
    padding: 0.5rem 0.75rem;
    border-radius: 0.75rem;
    transition: all 0.2s ease;
    cursor: pointer;
    text-decoration: none;
    color: inherit;
}

.user-profile:hover {
    background: #f1f5f9;
}

.user-avatar {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 0.75rem;
    overflow: hidden;
    background: #e2e8f0;
}

.user-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.user-avatar.large {
    width: 3rem;
    height: 3rem;
    border-radius: 1rem;
}

.user-info {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    text-align: left;
}

.user-name {
    font-size: 0.875rem;
    font-weight: 600;
    color: #0f172a;
    line-height: 1.2;
}

.user-role {
    font-size: 0.75rem;
    color: #64748b;
    line-height: 1.2;
}

.dropdown-arrow {
    font-size: 0.75rem;
    color: #64748b;
    transition: transform 0.2s ease;
}

.user-profile[aria-expanded="true"] .dropdown-arrow {
    transform: rotate(180deg);
}

/* Dropdown Styles */
.dropdown-menu {
    border: 1px solid #e2e8f0;
    border-radius: 1rem;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    padding: 0;
    min-width: 320px;
    margin-top: 0.5rem;
}

.dropdown-header {
    padding: 1.25rem 1.25rem 0.75rem;
    border-bottom: 1px solid #f1f5f9;
}

.dropdown-header h6 {
    margin: 0;
    font-weight: 600;
    color: #0f172a;
    font-size: 1rem;
}

.mark-read {
    font-size: 0.75rem;
    color: #3b82f6;
    text-decoration: none;
    font-weight: 500;
}

/* Notification Dropdown */
.notification-list {
    max-height: 300px;
    overflow-y: auto;
    padding: 0.5rem;
}

.notification-item {
    display: flex;
    gap: 0.75rem;
    padding: 0.75rem;
    border-radius: 0.75rem;
    transition: background 0.2s ease;
    cursor: pointer;
}

.notification-item:hover {
    background: #f8fafc;
}

.notification-icon {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1rem;
    flex-shrink: 0;
}

.notification-icon.primary {
    background: #3b82f6;
}

.notification-icon.success {
    background: #10b981;
}

.notification-content {
    flex: 1;
}

.notification-text {
    margin: 0 0 0.25rem 0;
    font-size: 0.875rem;
    color: #374151;
    font-weight: 500;
    line-height: 1.3;
}

.notification-time {
    font-size: 0.75rem;
    color: #94a3b8;
}

.dropdown-footer {
    padding: 0.75rem 1.25rem;
    border-top: 1px solid #f1f5f9;
    text-align: center;
}

.view-all {
    color: #3b82f6;
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 500;
}

/* Profile Dropdown */
.profile-header {
    padding: 1.25rem;
    display: flex;
    gap: 1rem;
    align-items: center;
}

.user-details h6 {
    margin: 0 0 0.25rem 0;
    color: #0f172a;
    font-weight: 600;
    font-size: 1rem;
}

.user-details span {
    font-size: 0.875rem;
    color: #64748b;
}

.dropdown-divider {
    margin: 0;
    border-color: #f1f5f9;
}

.dropdown-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1.25rem;
    border-radius: 0;
    text-decoration: none;
    color: #475569;
    font-size: 0.875rem;
    transition: all 0.2s ease;
    border: none;
    background: none;
    width: 100%;
    text-align: left;
}

.dropdown-item:hover {
    background: #f1f5f9;
    color: #0f172a;
}

.dropdown-item i {
    width: 1rem;
    text-align: center;
    font-size: 1rem;
}

.logout-item {
    color: #ef4444;
}

.logout-item:hover {
    background: #fef2f2;
    color: #dc2626;
}

/* Responsive Design */
@media (max-width: 1024px) {
    .header-content {
        padding: 1rem 1.5rem;
    }
    
    .search-container {
        width: 240px;
    }
}

@media (max-width: 768px) {
    .header-content {
        padding: 1rem;
        gap: 1rem;
    }
    
    .search-container {
        display: none;
    }
    
    .user-info {
        display: none;
    }
    
    .page-info .page-title {
        font-size: 1.5rem;
    }
    
    .page-subtitle {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.25rem;
    }
    
    .page-subtitle .separator {
        display: none;
    }
}

@media (max-width: 640px) {
    .dropdown-menu {
        position: fixed;
        left: 1rem !important;
        right: 1rem !important;
        width: auto !important;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Dropdown arrow rotation
    const dropdowns = document.querySelectorAll('.dropdown-toggle');
    dropdowns.forEach(dropdown => {
        dropdown.addEventListener('click', function() {
            const arrow = this.querySelector('.dropdown-arrow');
            if (arrow) {
                arrow.style.transform = this.getAttribute('aria-expanded') === 'true' 
                    ? 'rotate(0deg)' 
                    : 'rotate(180deg)';
            }
        });
    });
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown')) {
            const openDropdowns = document.querySelectorAll('.dropdown-menu.show');
            openDropdowns.forEach(dropdown => {
                dropdown.classList.remove('show');
            });
            const arrows = document.querySelectorAll('.dropdown-arrow');
            arrows.forEach(arrow => {
                arrow.style.transform = 'rotate(0deg)';
            });
        }
    });
});
</script>