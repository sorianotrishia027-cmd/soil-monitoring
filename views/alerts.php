<?php
include "../config/db_connect.php";

// Fetch the absolute latest generated or simulated sensor entry row
$latest = $conn->query("SELECT * FROM sensor_data ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
?>
<div class="sub-view-panel">
    <h2>⚠️ Current Alerts</h2>
    
    <?php if (!$latest): ?>
        <p class="alert info">No data yet — run the system once to generate readings.</p>
    <?php else: ?>
        
        <div class="alert <?= $latest['moisture'] < 30 ? 'danger' : ($latest['moisture'] > 60 ? 'warning' : 'success') ?>">
            <strong>Moisture (<?= htmlspecialchars($latest['moisture']) ?>%)</strong><br>
            <?= $latest['moisture'] < 30 ? "Too dry — water immediately" : ($latest['moisture'] > 60 ? "Too wet — stop watering, improve drainage" : "✅ Optimal moisture") ?>
        </div>

        <div class="alert <?= $latest['ph_level'] < 5.0 ? 'danger' : ($latest['ph_level'] > 7.5 ? 'warning' : 'success') ?>">
            <strong>pH Level (<?= htmlspecialchars($latest['ph_level']) ?>)</strong><br>
            <?= $latest['ph_level'] < 5.0 ? "Too acidic — apply lime/dolomite" : ($latest['ph_level'] > 7.5 ? "Too alkaline — add organic compost" : "✅ Ideal pH") ?>
        </div>

        <div class="alert <?= $latest['temperature'] > 35 ? 'danger' : ($latest['temperature'] < 18 ? 'info' : 'success') ?>">
            <strong>Temperature (<?= htmlspecialchars($latest['temperature']) ?>°C)</strong><br>
            <?= $latest['temperature'] > 35 ? "Too hot — water early morning/late afternoon" : ($latest['temperature'] < 18 ? "Too cool — use mulch to retain heat" : "✅ Good temperature") ?>
        </div>

        <div class="alert <?= $latest['nitrogen'] < 20 ? 'warning' : ($latest['nitrogen'] > 50 ? 'danger' : 'success') ?>">
            <strong>Nitrogen (<?= htmlspecialchars($latest['nitrogen']) ?> mg/kg)</strong><br>
            <?= $latest['nitrogen'] < 20 ? "Low — apply urea or chicken manure" : ($latest['nitrogen'] > 50 ? "Excess — stop nitrogen fertilizer" : "✅ Optimal level") ?>
        </div>

        <div class="alert <?= $latest['phosphorus'] < 10 ? 'warning' : ($latest['phosphorus'] > 30 ? 'danger' : 'success') ?>">
            <strong>Phosphorus (<?= htmlspecialchars($latest['phosphorus']) ?> mg/kg)</strong><br>
            <?= $latest['phosphorus'] < 10 ? "Low — apply superphosphate" : ($latest['phosphorus'] > 30 ? "Excess — reduce application" : "✅ Optimal level") ?>
        </div>

        <div class="alert <?= $latest['potassium'] < 15 ? 'warning' : ($latest['potassium'] > 50 ? 'danger' : 'success') ?>">
            <strong>Potassium (<?= htmlspecialchars($latest['potassium']) ?> mg/kg)</strong><br>
            <?= $latest['potassium'] < 15 ? "Low — apply potash or wood ash" : ($latest['potassium'] > 50 ? "Excess — reduce use" : "✅ Optimal level") ?>
        </div>

    <?php endif; ?>
</div>