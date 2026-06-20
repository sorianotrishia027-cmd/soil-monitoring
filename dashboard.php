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
        <nav class="sidebar-nav-panel">
            <div class="sidebar-brand-header">
                <div class="circular-logo-icon">SCC</div>
            </div>
            
            <ul class="sidebar-menu-links">
                <li>
                    <a href="dashboard.php?page=home" class="menu-link-item <?= $page === 'home' ? 'active' : '' ?>">
                        <span class="nav-text">Home</span>
                    </a>
                </li>
                
                <?php if ($role === 'admin'): ?>
                    <li>
                        <a href="dashboard.php?page=users_manage" class="menu-link-item <?= $page === 'users_manage' ? 'active' : '' ?>">
                            <span class="nav-text">Manage Accounts</span>
                        </a>
                    </li>
                    <li>
                        <a href="dashboard.php?page=devices_manage" class="menu-link-item <?= $page === 'devices_manage' ? 'active' : '' ?>">
                            <span class="nav-text">Manage Hardware</span>
                        </a>
                    </li>
                    <li>
                        <a href="dashboard.php?page=soil" class="menu-link-item <?= $page === 'soil' ? 'active' : '' ?>">
                            <span class="nav-text">All Sensor Data</span>
                        </a>
                    </li>
                    <li>
                        <a href="dashboard.php?page=system_reports" class="menu-link-item <?= $page === 'system_reports' ? 'active' : '' ?>">
                            <span class="nav-text">System Reports</span>
                        </a>
                    </li>
                <?php else: ?>
                    <li>
                        <a href="dashboard.php?page=soil" class="menu-link-item <?= $page === 'soil' ? 'active' : '' ?>">
                            <span class="nav-text">My Soil Data</span>
                        </a>
                    </li>
                    <li>
                        <a href="dashboard.php?page=alerts" class="menu-link-item <?= $page === 'alerts' ? 'active' : '' ?>">
                            <span class="nav-text">My Alerts</span>
                        </a>
                    </li>
                    <li>
                        <a href="dashboard.php?page=recommendations" class="menu-link-item <?= $page === 'recommendations' ? 'active' : '' ?>">
                            <span class="nav-text">Recommendations</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>

            <div class="sidebar-bottom-action">
                <a href="auth/logout.php" class="menu-link-item logout-link-style">
                    <span class="nav-text">Logout</span>
                </a>
            </div>
        </nav>

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