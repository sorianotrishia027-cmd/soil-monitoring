<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

require_once 'config/db_connect.php';
$role = $_SESSION['role'] ?? 'farmer';
$page = $_GET['page'] ?? 'home';
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
                    <a href="dashboard.php?page=home" class="menu-link-item <?php echo $page === 'home' ? 'active' : ''; ?>">
                        <span class="nav-text">Home</span>
                    </a>
                </li>
                <li>
                    <a href="dashboard.php?page=soil" class="menu-link-item <?php echo $page === 'soil' ? 'active' : ''; ?>">
                        <span class="nav-text">Soil Data</span>
                    </a>
                </li>
                <li>
                    <a href="dashboard.php?page=alerts" class="menu-link-item <?php echo $page === 'alerts' ? 'active' : ''; ?>">
                        <span class="nav-text">Alerts</span>
                    </a>
                </li>
                <li>
                    <a href="dashboard.php?page=recommendations" class="menu-link-item <?php echo $page === 'recommendations' ? 'active' : ''; ?>">
                        <span class="nav-text">Recommendations</span>
                    </a>
                </li>
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
                    <span class="user-badge"><?php echo htmlspecialchars($_SESSION['username']); ?> (<?php echo ucfirst($role); ?>)</span>
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
                        case 'home':
                        default:
                            include 'views/home.php';
                            break;
                    }
                ?>
            </div>

        </main>
    </div>

</body>
</html>