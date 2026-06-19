<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

require_once 'config/db_connect.php';
$role = $_SESSION['role'] ?? 'farmer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RiceFarm Co-op - Soil Monitoring Dashboard</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <div class="dashboard-layout">
        <!-- Sidebar Navigation (Matches Mockups f75c96ea-d760-400b-a3e1-04bf507814c4.jpg & 086e298c-fd86-4927-a357-a6efea3797a3.jpg) -->
        <nav class="sidebar-nav">
            <div class="sidebar-logo-area">
                <!-- Leaf Icon -->
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle; margin-right: 5px;">
                    <path d="M12 2C6.48 2 2 6.48 2 12C2 17.52 6.48 22 12 22C17.52 22 22 17.52 22 12C22 6.48 17.52 2 12 2ZM13 17H11V15H13V17ZM13 13H11V7H13V13Z" fill="currentColor"/>
                </svg>
                <span>RiceFarm Co-op</span>
            </div>
            
            <ul class="sidebar-menu-list">
                <li>
                    <a href="dashboard.php" class="sidebar-link active">
                        <span>📊 Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="sidebar-link">
                        <span>📅 Historical Data</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="sidebar-link">
                        <span>⚙️ Settings</span>
                    </a>
                </li>
            </ul>

            <div class="sidebar-footer">
                <a href="auth/logout.php" class="sidebar-link" style="color: #ff8a80;">
                    <span>🚪 Logout</span>
                </a>
            </div>
        </nav>

        <!-- Main Display Canvas Area -->
        <main class="main-dashboard-content">
            
            <!-- Dashboard Top Bar Wrapper -->
            <div class="dashboard-top-bar">
                <h2>Magssaka Rice Farmers Cooperative</h2>
                <div class="profile-widget">
                    <span>Welcome, <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>! (<em><?php echo ucfirst($role); ?></em>)</span>
                </div>
            </div>

            <!-- Telemetry Metrics Grid Layout (Populated via JS or placeholder values) -->
            <div class="metrics-grid-container">
                <!-- Soil Moisture Display (Highlighted Green Accent Layout) -->
                <div class="metric-data-card highlight-card">
                    <h3>Real-Time Soil Moisture</h3>
                    <p class="giant-metric-display" id="moisture-display">65%</p>
                </div>

                <!-- pH Level Card -->
                <div class="metric-data-card">
                    <h3>pH Level</h3>
                    <p class="giant-metric-display" id="ph-display">6.2</p>
                </div>

                <!-- Soil Temperature Card -->
                <div class="metric-data-card">
                    <h3>Soil Temperature</h3>
                    <p class="giant-metric-display" id="temp-display">28°C</p>
                </div>

                <!-- NPK Levels Card -->
                <div class="metric-data-card">
                    <h3>NPK Levels</h3>
                    <p class="giant-metric-display" id="npk-display">4-2-3</p>
                </div>
            </div>

            <!-- Dynamic System Alerts Banner Block -->
            <div class="status-alert-banner">
                <div>
                    <strong>System Status:</strong> <span style="color: #2e7d32;">● Operational</span>
                </div>
            </div>

            <!-- Core Operational View Rows -->
            <div class="bottom-dashboard-row">
                <!-- Analytics Data Visualizer Component -->
                <div class="analytics-chart-panel">
                    <h4>Moisture Trend (Last 7 Days)</h4>
                    <div id="data-display" style="color: var(--text-muted); font-size: 14px; padding: 20px 0;">
                        Polling realtime streams from agricultural hardware infrastructure nodes...
                    </div>
                </div>

                <!-- Actionable Insight Context Panels -->
                <div class="recommendations-panel">
                    <?php if ($role === 'farmer'): ?>
                        <h4>🚜 Field Management Overview</h4>
                        <p style="font-size: 14px; line-height: 1.6; color: var(--text-muted);">
                            Monitor field moisture saturation levels, track environmental values, and optimize rice crop cultivation schedules.
                        </p>
                        <div style="background-color: #e8f5e9; padding: 12px; border-radius: 8px; color: #2e7d32; font-size: 14px; font-weight: 500; margin-top: 15px;">
                            <strong>Recommendation:</strong> Irrigate tomorrow 6 AM
                        </div>

                    <?php elseif ($role === 'admin'): ?>
                        <h4>⚙️ Operations Console</h4>
                        <p style="font-size: 14px; line-height: 1.6; color: var(--text-muted);">
                            Manage node network distributions, evaluate ESP32/Arduino stream stability, and review raw sensor uploads.
                        </p>
                        <div style="background-color: #e8f5e9; padding: 12px; border-radius: 8px; color: #1b5e20; font-size: 13px; font-weight: bold; margin-top: 15px; border-left: 4px solid #1b5e20;">
                            ✔ Node Sync Status: Hardware API interfaces connected and accepting sensor streaming packets.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </main>
    </div>

    <script src="js/script.js"></script>
</body>
</html>