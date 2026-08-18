<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure database connection
if (!isset($conn)) {
    require_once __DIR__ . '/../config/db_connect.php';
}

$role = strtolower($_SESSION['role'] ?? 'farmer');
$user_id = $_SESSION['user_id'] ?? 0;

if ($role === 'admin') {
    // Admin monitors the latest overall transmission across all nodes
    $stmt = $conn->query("
        SELECT s.*, u.username 
        FROM sensor_data s 
        LEFT JOIN users u ON s.user_id = u.id 
        ORDER BY s.id DESC 
        LIMIT 1
    ");
    $latest = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    // Farmer query: Matches assigned user_id OR recent device logs
    $stmt = $conn->prepare("
        SELECT * FROM sensor_data 
        WHERE user_id = ? 
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $latest = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fallback: If no records match user_id directly, grab the latest unassigned system record
    if (!$latest) {
        $stmt_fallback = $conn->query("SELECT * FROM sensor_data ORDER BY id DESC LIMIT 1");
        $latest = $stmt_fallback->fetch(PDO::FETCH_ASSOC);
    }
}

// Convert metrics to floats for accurate ternary logic evaluations
$moisture   = isset($latest['moisture']) ? floatval($latest['moisture']) : 0.0;
$ph         = isset($latest['ph_level']) ? floatval($latest['ph_level']) : 0.0;
$temp       = isset($latest['temperature']) ? floatval($latest['temperature']) : 0.0;
$nitrogen   = isset($latest['nitrogen']) ? floatval($latest['nitrogen']) : 0.0;
$phosphorus = isset($latest['phosphorus']) ? floatval($latest['phosphorus']) : 0.0;
$potassium  = isset($latest['potassium']) ? floatval($latest['potassium']) : 0.0;
?>

<div class="sub-view-panel-container">
    <div class="view-panel-header">
        <h3>⚠️ System Critical & Condition Alerts</h3>
        <?php if ($role === 'admin'): ?>
            <p>Admin Operations Monitor: Displaying latest node transmission entry recorded in the cooperative database.</p>
        <?php else: ?>
            <p>Real-time agricultural health indicators parsed directly from your hardware sensor logs.</p>
        <?php endif; ?>
    </div>

    <?php if (!$latest): ?>
        <div class="alert info" style="background:#e3f2fd; color:#0d47a1; padding:15px; border-radius:8px;">
            <strong>Awaiting Telemetry Streams</strong><br>
            No active sensor data has been posted to this account yet. Telemetry will automatically show up once the hardware nodes are connected.
        </div>
    <?php else: ?>

        <?php if ($role === 'admin'): ?>
            <div class="notification-event-strip status-border-admin" style="background: #e3f2fd; padding:12px; border-radius:8px; margin-bottom: 20px;">
                <span class="muted-title" style="color: #0d47a1; font-weight: bold; font-size: 11px;">TELEMETRY OWNER</span>
                <p style="font-size: 15px; font-weight: 600; color: #1565c0; margin: 2px 0 0;">
                    Node User: <?= htmlspecialchars($latest['username'] ?? 'System / Unlinked Hardware') ?>
                </p>
            </div>
        <?php endif; ?>

        <!-- Soil Moisture Alert -->
        <div class="alert <?= $moisture < 30.0 ? 'danger' : ($moisture > 60.0 ? 'warning' : 'success') ?>">
            <strong>Moisture Content (<?= number_format($moisture, 2) ?>%)</strong><br>
            <?= $moisture < 30.0 ? "Too dry — water the field immediately to protect root networks." : ($moisture > 60.0 ? "Too wet — halt irrigation pumps and check soil drainage avenues." : "✅ Optimal thermal and soil moisture balance detected.") ?>
        </div>

        <!-- Soil pH Alert -->
        <div class="alert <?= $ph < 5.0 ? 'danger' : ($ph > 7.5 ? 'warning' : 'success') ?>">
            <strong>Soil pH Balance (<?= number_format($ph, 2) ?>)</strong><br>
            <?= $ph < 5.0 ? "Too acidic — apply agricultural lime or dolomite treatments." : ($ph > 7.5 ? "Too alkaline — integrate organic compost matter or sulfur additives." : "✅ Ideal pH balance for crop nutrient absorption.") ?>
        </div>

        <!-- Ambient Temperature Alert -->
        <div class="alert <?= $temp > 35.0 ? 'danger' : ($temp < 18.0 ? 'info' : 'success') ?>">
            <strong>Ambient Temperature (<?= number_format($temp, 1) ?>°C)</strong><br>
            <?= $temp > 35.0 ? "Too hot — run early morning or late afternoon deep watering routines." : ($temp < 18.0 ? "Too cool — apply organic mulch canvas layers to preserve soil heat." : "✅ Optimal thermal conditions for steady growth.") ?>
        </div>

        <!-- Nitrogen (N) Alert -->
        <div class="alert <?= $nitrogen < 20.0 ? 'warning' : ($nitrogen > 50.0 ? 'danger' : 'success') ?>">
            <strong>Nitrogen (N) (<?= number_format($nitrogen, 2) ?> mg/kg)</strong><br>
            <?= $nitrogen < 20.0 ? "Low nutrient state — apply calculated urea or chicken manure blends." : ($nitrogen > 50.0 ? "Excess concentrations — pause nitrogenous chemical additive usage." : "✅ Nitrogen proportions are currently optimal.") ?>
        </div>

        <!-- Phosphorus (P) Alert -->
        <div class="alert <?= $phosphorus < 10.0 ? 'warning' : ($phosphorus > 30.0 ? 'danger' : 'success') ?>">
            <strong>Phosphorus (P) (<?= number_format($phosphorus, 2) ?> mg/kg)</strong><br>
            <?= $phosphorus < 10.0 ? "Low baseline — dress soil profile with localized superphosphate complexes." : ($phosphorus > 30.0 ? "Excess saturation — scale back phosphorus fertilizer input ratios." : "✅ Phosphorus structural counts are stable.") ?>
        </div>

        <!-- Potassium (K) Alert -->
        <div class="alert <?= $potassium < 15.0 ? 'warning' : ($potassium > 50.0 ? 'danger' : 'success') ?>">
            <strong>Potassium (K) (<?= number_format($potassium, 2) ?> mg/kg)</strong><br>
            <?= $potassium < 15.0 ? "Deficient compound count — apply muriate of potash or clean wood ash." : ($potassium > 50.0 ? "Excess saturation — limit mineral fertilizer application schedules." : "✅ Potassium health thresholds look great.") ?>
        </div>

    <?php endif; ?>
</div>