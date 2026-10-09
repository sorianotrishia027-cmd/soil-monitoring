<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit;
}

require_once 'config/db_connect.php';

/*
|--------------------------------------------------------------------------
| CURRENT USER SESSION
|--------------------------------------------------------------------------
*/

$user_id = intval($_SESSION['user_id'] ?? 0);

if ($user_id <= 0) {
    session_destroy();
    header("Location: auth/login.php");
    exit;
}

$page = $_GET['page'] ?? 'home';

$currentUser = null;
$databaseError = false;

/*
|--------------------------------------------------------------------------
| LOAD CURRENT LOGGED-IN USER
|--------------------------------------------------------------------------
|
| This is important for My Profile.
| The profile page can also use $currentUser directly.
|
*/

try {

    $userStmt = $conn->prepare("
        SELECT
            id,
            username,
            email,
            fullname,
            role,
            contact_number
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $userStmt->execute([$user_id]);

    $currentUser = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentUser) {

        session_destroy();
        header("Location: auth/login.php");
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | GET ROLE FROM DATABASE
    |--------------------------------------------------------------------------
    */

    $role = strtolower(trim($currentUser['role'] ?? ''));

    if ($role === '') {
        $role = 'farmer';
    }

    /*
    |--------------------------------------------------------------------------
    | SYNCHRONIZE SESSION DATA
    |--------------------------------------------------------------------------
    */

    $_SESSION['user_id'] = (int)$currentUser['id'];
    $_SESSION['username'] = $currentUser['username'] ?? '';
    $_SESSION['email'] = $currentUser['email'] ?? '';
    $_SESSION['fullname'] = $currentUser['fullname'] ?? '';
    $_SESSION['role'] = $role;
    $_SESSION['contact_number'] = $currentUser['contact_number'] ?? '';

} catch (PDOException $e) {

    $databaseError = true;

    /*
    |--------------------------------------------------------------------------
    | FALLBACK TO SESSION
    |--------------------------------------------------------------------------
    */

    $role = strtolower(trim($_SESSION['role'] ?? 'farmer'));

    if ($role === '') {
        $role = 'farmer';
    }

    $currentUser = [
        'id' => $user_id,
        'username' => $_SESSION['username'] ?? '',
        'email' => $_SESSION['email'] ?? '',
        'fullname' => $_SESSION['fullname'] ?? '',
        'role' => $role,
        'contact_number' => $_SESSION['contact_number'] ?? ''
    ];
}


/*
|--------------------------------------------------------------------------
| SECURITY: ALLOWED PAGES
|--------------------------------------------------------------------------
*/

$farmer_pages = [
    'home',
    'soil',
    'alerts',
    'recommendations',
    'profile'
];

$admin_pages = [
    'home',
    'soil',
    'users_manage',
    'devices_manage',
    'system_reports',
    'profile'
];


/*
|--------------------------------------------------------------------------
| SECURITY: PAGE ACCESS
|--------------------------------------------------------------------------
*/

if ($role === 'admin') {

    if (!in_array($page, $admin_pages, true)) {
        header("Location: dashboard.php?page=home");
        exit;
    }

} else {

    /*
    |--------------------------------------------------------------------------
    | ALL NON-ADMIN USERS ARE TREATED AS FARMER
    |--------------------------------------------------------------------------
    */

    $role = 'farmer';

    if (!in_array($page, $farmer_pages, true)) {
        header("Location: dashboard.php?page=home");
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| FIND FARMER ASSIGNED DEVICE
|--------------------------------------------------------------------------
*/

$assigned_node_id = null;
$assigned_device_id = null;

if ($role === 'farmer') {

    try {

        /*
        |--------------------------------------------------------------------------
        | TRY SENSOR DATA ASSIGNMENT
        |--------------------------------------------------------------------------
        */

        $nodeStmt = $conn->prepare("
            SELECT device_id
            FROM sensor_data
            WHERE user_id = ?
              AND device_id IS NOT NULL
              AND TRIM(device_id) <> ''
            ORDER BY id DESC
            LIMIT 1
        ");

        $nodeStmt->execute([$user_id]);

        $nodeResult = $nodeStmt->fetch(PDO::FETCH_ASSOC);

        if ($nodeResult && !empty($nodeResult['device_id'])) {

            $assigned_device_id = trim($nodeResult['device_id']);
        }

    } catch (PDOException $e) {

        $assigned_device_id = null;
    }
}


/*
|--------------------------------------------------------------------------
| LATEST TELEMETRY
|--------------------------------------------------------------------------
*/

$latest = null;

try {

    if ($role === 'admin') {

        /*
        |--------------------------------------------------------------------------
        | ADMIN CAN SEE LATEST SYSTEM TELEMETRY
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->query("
            SELECT *
            FROM soil_readings
            ORDER BY id DESC
            LIMIT 1
        ");

        $latest = $stmt->fetch(PDO::FETCH_ASSOC);

    } else {

        /*
        |--------------------------------------------------------------------------
        | FARMER
        |--------------------------------------------------------------------------
        |
        | Only show the farmer's assigned device data.
        |
        */

        if ($assigned_device_id !== null) {

            $stmt = $conn->prepare("
                SELECT *
                FROM soil_readings
                WHERE device_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");

            $stmt->execute([
                $assigned_device_id
            ]);

            $latest = $stmt->fetch(PDO::FETCH_ASSOC);

        } else {

            /*
            |--------------------------------------------------------------------------
            | FALLBACK TO SENSOR_DATA OWNERSHIP
            |--------------------------------------------------------------------------
            */

            try {

                $stmt = $conn->prepare("
                    SELECT *
                    FROM sensor_data
                    WHERE user_id = ?
                    ORDER BY id DESC
                    LIMIT 1
                ");

                $stmt->execute([
                    $user_id
                ]);

                $latest = $stmt->fetch(PDO::FETCH_ASSOC);

            } catch (PDOException $innerException) {

                $latest = null;
            }
        }
    }

} catch (PDOException $e) {

    $latest = null;
}

$page_titles = [
    'home' => [
        'title' => 'Farm overview',
        'subtitle' => 'Soil conditions and recent readings for your rice fields.'
    ],
    'users_manage' => [
        'title' => 'Manage accounts',
        'subtitle' => 'Manage farmer access to the soil monitoring system.'
    ],
    'devices_manage' => [
        'title' => 'Monitoring hardware',
        'subtitle' => 'Manage field nodes and farmer assignments.'
    ],
    'soil' => [
        'title' => 'Soil readings',
        'subtitle' => 'Review sensor measurements and your field history.'
    ],
    'system_reports' => [
        'title' => 'System reports',
        'subtitle' => 'Review cooperative monitoring activity and recorded data.'
    ],
    'alerts' => [
        'title' => 'System alerts',
        'subtitle' => 'Critical moisture, pH, and nutrient parameter threshold alerts.'
    ],
    'recommendations' => [
        'title' => 'Field recommendations',
        'subtitle' => 'Agronomic suggestions and soil amendment recommendations.'
    ],
    'profile' => [
        'title' => 'My profile',
        'subtitle' => 'Manage your personal profile and account credentials.'
    ]
];

$currentPageInfo = $page_titles[$page] ?? [
    'title' => 'Farm overview',
    'subtitle' => 'Soil conditions and recent readings for your rice fields.'
];

$userInitial = strtoupper(substr($currentUser['username'] ?? 'A', 0, 1));
$displayRole = ($role === 'admin') ? 'Administrator' : 'Farmer';
$displayName = htmlspecialchars($currentUser['username'] ?? 'admin', ENT_QUOTES, 'UTF-8');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SCC Soil Monitor - Sto Cristo Concepcion Farmers Agriculture Cooperative</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="dashboard-body-frame">

<div class="dashboard-layout-wrapper">

<!-- =========================================================
     SIDEBAR NAVIGATION (Matches screenshots)
========================================================== -->
<aside class="sidebar-nav-panel">

    <div class="sidebar-brand-header">
        <div style="display: flex; align-items: flex-start; gap: 12px; flex: 1; min-width: 0;">
            <svg class="sidebar-brand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/>
                <path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>
            </svg>
            <div class="sidebar-brand-info">
                <span class="brand-main-title">SCC<br>Soil Monitor</span>
                <span class="brand-sub-title">Sto Cristo Concepcion Farmers Agriculture Cooperative</span>
                <span class="brand-location">Concepcion, Tarlac, Philippines</span>
            </div>
        </div>
        <button type="button" class="sidebar-close-btn" onclick="toggleMobileSidebar()" aria-label="Close navigation">
            <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
    </div>

    <ul class="sidebar-menu-links">
        <!-- HOME -->
        <li>
            <a href="dashboard.php?page=home" class="menu-link-item <?= $page === 'home' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                    <polyline points="9 22 9 12 15 12 15 22"/>
                </svg>
                <span class="nav-text">Home</span>
            </a>
        </li>

        <?php if ($role === 'admin'): ?>
            <!-- MANAGE ACCOUNTS -->
            <li>
                <a href="dashboard.php?page=users_manage" class="menu-link-item <?= $page === 'users_manage' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                    <span class="nav-text">Manage Accounts</span>
                </a>
            </li>

            <!-- MANAGE HARDWARE -->
            <li>
                <a href="dashboard.php?page=devices_manage" class="menu-link-item <?= $page === 'devices_manage' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="4" y="4" width="16" height="16" rx="2"/>
                        <rect x="9" y="9" width="6" height="6"/>
                        <path d="M9 1v3M15 1v3M9 20v3M15 20v3M20 9h3M20 14h3M1 9h3M1 14h3"/>
                    </svg>
                    <span class="nav-text">Manage Hardware</span>
                </a>
            </li>

            <!-- ALL SENSOR DATA -->
            <li>
                <a href="dashboard.php?page=soil" class="menu-link-item <?= $page === 'soil' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="20" x2="18" y2="10"/>
                        <line x1="12" y1="20" x2="12" y2="4"/>
                        <line x1="6" y1="20" x2="6" y2="14"/>
                    </svg>
                    <span class="nav-text">All Sensor Data</span>
                </a>
            </li>

            <!-- SYSTEM REPORTS -->
            <li>
                <a href="dashboard.php?page=system_reports" class="menu-link-item <?= $page === 'system_reports' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10 9 9 9 8 9"/>
                    </svg>
                    <span class="nav-text">System Reports</span>
                </a>
            </li>
        <?php else: ?>
            <!-- FARMER MENU -->
            <li>
                <a href="dashboard.php?page=soil" class="menu-link-item <?= $page === 'soil' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="20" x2="18" y2="10"/>
                        <line x1="12" y1="20" x2="12" y2="4"/>
                        <line x1="6" y1="20" x2="6" y2="14"/>
                    </svg>
                    <span class="nav-text">My Soil Data</span>
                </a>
            </li>

            <li>
                <a href="dashboard.php?page=alerts" class="menu-link-item <?= $page === 'alerts' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/>
                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    <span class="nav-text">My Alerts</span>
                </a>
            </li>

            <li>
                <a href="dashboard.php?page=recommendations" class="menu-link-item <?= $page === 'recommendations' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                    <span class="nav-text">Recommendations</span>
                </a>
            </li>
        <?php endif; ?>

        <!-- MY PROFILE -->
        <li>
            <a href="dashboard.php?page=profile" class="menu-link-item <?= $page === 'profile' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
                <span class="nav-text">My Profile</span>
            </a>
        </li>
    </ul>

    <!-- LOGOUT -->
    <div class="sidebar-bottom-action">
        <a href="auth/logout.php" class="menu-link-item logout-link-style">
            <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            <span class="nav-text">Logout</span>
        </a>
    </div>
</aside>

<!-- MOBILE BACKDROP OVERLAY -->
<div id="sidebarBackdrop" class="sidebar-backdrop-overlay" onclick="toggleMobileSidebar()"></div>

<!-- =========================================================
     MAIN CONTENT CANVAS
========================================================== -->
<main class="main-dashboard-canvas">

    <!-- TOP HEADER -->
    <header class="dashboard-canvas-header">
        <div class="header-title-container">
            <button type="button" class="mobile-nav-toggle-btn" onclick="toggleMobileSidebar()" aria-label="Toggle navigation">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="3" y1="12" x2="21" y2="12"/>
                    <line x1="3" y1="6" x2="21" y2="6"/>
                    <line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </button>
            <div class="page-title-group">
                <h1><?= htmlspecialchars($currentPageInfo['title']) ?></h1>
                <p><?= htmlspecialchars($currentPageInfo['subtitle']) ?></p>
            </div>
        </div>

        <div class="header-user-profile">
            <div class="user-avatar-circle">
                <?= $userInitial ?>
            </div>
            <div class="user-meta-details">
                <span class="user-display-name"><?= $displayName ?></span>
                <span class="user-role-label"><?= $displayRole ?></span>
            </div>
        </div>
    </header>

    <!-- PAGE VIEW CONTENT OUTLET -->
    <div class="view-content-outlet-container">


        <?php

        switch ($page) {


            /*
            |--------------------------------------------------------------------------
            | SOIL DATA
            |--------------------------------------------------------------------------
            */

            case 'soil':

                include 'views/soil_data.php';

                break;


            /*
            |--------------------------------------------------------------------------
            | ALERTS
            |--------------------------------------------------------------------------
            */

            case 'alerts':

                if ($role === 'farmer') {

                    include 'views/alerts.php';

                } else {

                    header("Location: dashboard.php?page=home");
                    exit;
                }

                break;


            /*
            |--------------------------------------------------------------------------
            | RECOMMENDATIONS
            |--------------------------------------------------------------------------
            */

            case 'recommendations':

                if ($role === 'farmer') {

                    include 'views/recommendations.php';

                } else {

                    header("Location: dashboard.php?page=home");
                    exit;
                }

                break;


            /*
            |--------------------------------------------------------------------------
            | MANAGE ACCOUNTS
            |--------------------------------------------------------------------------
            */

            case 'users_manage':

                if ($role === 'admin') {

                    include 'views/users_manage.php';

                } else {

                    header("Location: dashboard.php?page=home");
                    exit;
                }

                break;


            /*
            |--------------------------------------------------------------------------
            | MANAGE HARDWARE
            |--------------------------------------------------------------------------
            */

            case 'devices_manage':

                if ($role === 'admin') {

                    include 'views/devices_manage.php';

                } else {

                    header("Location: dashboard.php?page=home");
                    exit;
                }

                break;


            /*
            |--------------------------------------------------------------------------
            | SYSTEM REPORTS
            |--------------------------------------------------------------------------
            */

            case 'system_reports':

                if ($role === 'admin') {

                    include 'views/system_reports.php';

                } else {

                    header("Location: dashboard.php?page=home");
                    exit;
                }

                break;


            /*
            |--------------------------------------------------------------------------
            | MY PROFILE
            |--------------------------------------------------------------------------
            |
            | $currentUser is already loaded above.
            | views/profile.php can use this variable.
            |
            */

            case 'profile':

                include 'views/profile.php';

                break;


            /*
            |--------------------------------------------------------------------------
            | HOME
            |--------------------------------------------------------------------------
            */

            case 'home':

            default:

                include 'views/home.php';

                break;
        }

        ?>

    </div>

</main>

</div>


<!-- =============================================================
     GLOBAL JAVASCRIPT
============================================================= -->

<script src="js/script.js"></script>
<script>
function toggleMobileSidebar() {
    const sidebar = document.querySelector('.sidebar-nav-panel');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (sidebar) sidebar.classList.toggle('mobile-open');
    if (backdrop) backdrop.classList.toggle('mobile-open');
}
</script>

</body>
</html>