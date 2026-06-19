<?php
session_start();
// Protect the page - redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

require_once 'config/db_connect.php';
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
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</span>
                <a href="auth/logout.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <main>
            <section class="card">
                <h2>Real-time Live Data</h2>
                <div id="data-display">Loading sensor metrics...</div>
            </section>
        </main>
    </div>

    <script src="js/script.js"></script>
</body>
</html>