<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include "../config/db_connect.php";

$role = strtolower($_SESSION['role'] ?? 'farmer');
$user_id = $_SESSION['user_id'] ?? 0;

// Admin views newest absolute stream, Farmer views ONLY their own latest field record
if ($role === 'admin') {
    // Left join with users table so admin can trace which farmer triggered the current status flags
    $stmt = $conn->query("SELECT s.*, u.username FROM sensor_data s LEFT JOIN users u ON s.user_id = u.id ORDER BY s.id DESC LIMIT 1");
    $latest = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $stmt = $conn->prepare("SELECT * FROM sensor_data WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $latest = $stmt->fetch(PDO::FETCH_ASSOC);
}
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
        <div class="alert info">
            <strong>Awaiting Telemetry Streams</strong><br>
            No active sensor data has been posted to this account yet. Telemetry will automatically show up once the hardware nodes are connected.
        </div>
    <?php else: ?>

        <?php if ($role === 'admin'): ?>
            <div class="notification-event-strip status-border-admin" style="background: #e3f2fd; margin-bottom: 20px;">
                <span class="muted-title" style="color: #0d47a1; font-weight: bold; font-size: 11px;">TELEMETRY OWNER</span>
                <p style="font-size: 15px; font-weight: 600; color: #1565c0; margin-top: 2px;">
                    Node User: <?= htmlspecialchars($latest['username'] ?? 'System / Unlinked Hardware') ?>
                </p>
            </div>
        <?php endif; ?>

        <div class="alert <?= $latest['moisture'] < 30 ? 'danger' : ($latest['moisture'] > 60 ? 'warning' : 'success') ?>">
            <strong>Moisture Content (<?= htmlspecialchars($latest['moisture']) ?>%)</strong><br>
            <?= $latest['moisture'] < 30 ? "Too dry — water the field immediately to protect root networks." : ($latest['moisture'] > 60 ? "Too wet — halt irrigation pumps and check soil drainage avenues." : "✅ Favorable moisture baseline detected.") ?>
        </div>

        <div class="alert <?= $latest['ph_level'] < 5.0 ? 'danger' : ($latest['ph_level'] > 7.5 ? 'warning' : 'success') ?>">
            <strong>Soil pH Balance (<?= htmlspecialchars($latest['ph_level']) ?>)</strong><br>
            <?= $latest['ph_level'] < 5.0 ? "Too acidic — apply agricultural lime or dolomite treatments." : ($latest['ph_level'] > 7.5 ? "Too alkaline — integrate organic compost matter or sulfur additives." : "✅ Ideal pH balance for crop nutrient absorption.") ?>
        </div>

        <div class="alert <?= $latest['temperature'] > 35 ? 'danger' : ($latest['temperature'] < 18 ? 'info' : 'success') ?>">
            <strong>Ambient Temperature (<?= htmlspecialchars($latest['temperature']) ?>°C)</strong><br>
            <?= $latest['temperature'] > 35 ? "Too hot — run early morning or late afternoon deep watering routines." : ($latest['temperature'] < 18 ? "Too cool — apply organic mulch canvas layers to preserve soil heat." : "✅ Optimal thermal conditions for steady growth.") ?>
        </div>

        <div class="alert <?= $latest['nitrogen'] < 20 ? 'warning' : ($latest['nitrogen'] > 50 ? 'danger' : 'success') ?>">
            <strong>Nitrogen (N) (<?= htmlspecialchars($latest['nitrogen']) ?> mg/kg)</strong><br>
            <?= $latest['nitrogen'] < 20 ? "Low nutrient state — apply calculated urea or chicken manure blends." : ($latest['nitrogen'] > 50 ? "Excess concentrations — pause nitrogenous chemical additive usage." : "✅ Nitrogen proportions are currently optimal.") ?>
        </div>

        <div class="alert <?= $latest['phosphorus'] < 10 ? 'warning' : ($latest['phosphorus'] > 30 ? 'danger' : 'success') ?>">
            <strong>Phosphorus (P) (<?= htmlspecialchars($latest['phosphorus']) ?> mg/kg)</strong><br>
            <?= $latest['phosphorus'] < 10 ? "Low baseline — dress soil profile with localized superphosphate complexes." : ($latest['phosphorus'] > 30 ? "Excess saturation — scale back phosphorus fertilizer input ratios." : "✅ Phosphorus structural counts are stable.") ?>
        </div>

        <div class="alert <?= $latest['potassium'] < 15 ? 'warning' : ($latest['potassium'] > 50 ? 'danger' : 'success') ?>">
            <strong>Potassium (K) (<?= htmlspecialchars($latest['potassium']) ?> mg/kg)</strong><br>
            <?= $latest['potassium'] < 15 ? "Deficient compound count — apply muriate of potash or clean wood ash." : ($latest['potassium'] > 50 ? "Excess saturation — limit mineral fertilizer application schedules." : "✅ Potassium health thresholds look great.") ?>
        </div>

    <?php endif; ?>
</div>