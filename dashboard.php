<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit;
}

require_once 'config/db_connect.php';
$role = strtolower($_SESSION['role'] ?? 'farmer');
$page = $_GET['page'] ?? 'home';

// Security Guard: Prevent farmers from accessing admin-exclusive subviews manually
$admin_exclusive_pages = ['users_manage', 'devices_manage', 'system_reports'];
if ($role === 'farmer' && in_array($page, $admin_exclusive_pages)) {
    header("Location: dashboard.php?page=home");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sto Cristo Concepcion Farmers Agriculture Cooperative</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="dashboard-body-frame">

    <div class="dashboard-layout-wrapper">
        <aside class="sidebar-nav-panel">
            <div class="sidebar-brand-header">
                <div class="circular-logo-icon" style="font-weight: 800; font-size: 14px; color: var(--primary-color);">SCC</div>
            </div>
            
            <ul class="sidebar-menu-links">
                <li>
                    <a href="dashboard.php?page=home" class="menu-link-item <?= $page === 'home' ? 'active' : '' ?>">
                        <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                            <polyline points="9 22 9 12 15 12 15 22"/>
                        </svg>
                        <span class="nav-text">Home</span>
                    </a>
                </li>
                
                <?php if ($role === 'admin'): ?>
                    <li>
                        <a href="dashboard.php?page=users_manage" class="menu-link-item <?= $page === 'users_manage' ? 'active' : '' ?>">
                            <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                            </svg>
                            <span class="nav-text">Manage Accounts</span>
                        </a>
                    </li>
                    <li>
                        <a href="dashboard.php?page=devices_manage" class="menu-link-item <?= $page === 'devices_manage' ? 'active' : '' ?>">
                            <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none">
                                <rect x="2" y="2" width="20" height="8" rx="2" ry="2"/>
                                <rect x="2" y="14" width="20" height="8" rx="2" ry="2"/>
                                <line x1="6" y1="6" x2="6.01" y2="6"/>
                                <line x1="6" y1="18" x2="6.01" y2="18"/>
                            </svg>
                            <span class="nav-text">Manage Hardware</span>
                        </a>
                    </li>
                    <li>
                        <a href="dashboard.php?page=soil" class="menu-link-item <?= $page === 'soil' ? 'active' : '' ?>">
                            <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none">
                                <line x1="18" y1="20" x2="18" y2="10"/>
                                <line x1="12" y1="20" x2="12" y2="4"/>
                                <line x1="6" y1="20" x2="6" y2="14"/>
                            </svg>
                            <span class="nav-text">All Sensor Data</span>
                        </a>
                    </li>
                    <li>
                        <a href="dashboard.php?page=system_reports" class="menu-link-item <?= $page === 'system_reports' ? 'active' : '' ?>">
                            <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                                <line x1="16" y1="13" x2="8" y2="13"/>
                                <line x1="16" y1="17" x2="8" y2="17"/>
                            </svg>
                            <span class="nav-text">System Reports</span>
                        </a>
                    </li>
                <?php else: ?>
                    <li>
                        <a href="dashboard.php?page=soil" class="menu-link-item <?= $page === 'soil' ? 'active' : '' ?>">
                            <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none">
                                <line x1="18" y1="20" x2="18" y2="10"/>
                                <line x1="12" y1="20" x2="12" y2="4"/>
                                <line x1="6" y1="20" x2="6" y2="14"/>
                            </svg>
                            <span class="nav-text">My Soil Data</span>
                        </a>
                    </li>
                    <li>
                        <a href="dashboard.php?page=alerts" class="menu-link-item <?= $page === 'alerts' ? 'active' : '' ?>">
                            <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none">
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                                <line x1="12" y1="9" x2="12" y2="13"/>
                                <line x1="12" y1="17" x2="12.01" y2="17"/>
                            </svg>
                            <span class="nav-text">My Alerts</span>
                        </a>
                    </li>
                    <li>
                        <a href="dashboard.php?page=recommendations" class="menu-link-item <?= $page === 'recommendations' ? 'active' : '' ?>">
                            <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                            </svg>
                            <span class="nav-text">Recommendations</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>

            <div class="sidebar-bottom-action">
                <a href="auth/logout.php" class="menu-link-item logout-link-style">
                    <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <polyline points="16 17 21 12 16 7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                    <span class="nav-text">Logout</span>
                </a>
            </div>
        </aside>

        <main class="main-dashboard-canvas">
            <header class="dashboard-canvas-header">
                <h2>Sto Cristo Concepcion Farmers Agriculture Cooperative</h2>
                <div class="header-action-widgets">
                    <span class="user-badge"><?= htmlspecialchars($_SESSION['username']) ?> (<?= ucfirst($role) ?>)</span>
                </div>
            </header>

            <div class="view-content-outlet-container">
                <?php 
                    switch ($page) {
                        case 'soil':
                            include 'views/soil_data.php';
                            break;
                        case 'alerts':
                            include 'views/alerts.php';
                            break;
                        case 'recommendations':
                            include 'views/recommendations.php';
                            break;
                        // Admin-exclusive View Imports
                        case 'users_manage':
                            include 'views/users_manage.php';
                            break;
                        case 'devices_manage':
                            include 'views/devices_manage.php';
                            break;
                        case 'system_reports':
                            include 'views/system_reports.php';
                            break;
                        case 'home':
                        default:
                            include 'views/home.php';
                            break;
                    }
                ?>
            </div>
        </main>
    </div>

    <script src="js/script.js"></script>
</body>
</html>