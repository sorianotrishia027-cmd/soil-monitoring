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
    <title>Dashboard - Soil Monitoring</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="dashboard-container">
        <header>
            <h1>Soil Monitoring Dashboard</h1>
            <div class="user-profile">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>! (<em><?php echo ucfirst($role); ?></em>)</span>
                <a href="auth/logout.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <main>
            <section class="card">
                <h2>Real-time Live Data</h2>
                <div id="data-display">Loading sensor metrics...</div>
            </section>

            <?php if ($role === 'farmer'): ?>
                <section class="card" style="margin-top: 20px;">
                    <h2>🚜 Farmer Tools & Field Overview</h2>
                    <p>Monitor field moisture saturation levels, track environmental values, and optimize rice crop cultivation schedules.</p>
                </section>

            <?php elseif ($role === 'admin'): ?>
                <section class="card" style="margin-top: 20px; border-left: 5px solid #1b5e20;">
                    <h2>⚙️ Administrator Operations Console</h2>
                    <p>Manage node network distributions, evaluate ESP32/Arduino stream stability, and review raw sensor uploads.</p>
                    <div style="background-color: #e8f5e9; padding: 12px; border-radius: 6px; color: #1b5e20; font-size: 14px; font-weight: bold; margin-top: 10px;">
                        ✔ Node Sync Status: Hardware API interfaces connected and accepting sensor streaming packets.
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <script src="js/script.js"></script>
</body>
</html>