<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include "../config/db_connect.php";

$role = strtolower($_SESSION['role'] ?? 'farmer');
$user_id = $_SESSION['user_id'] ?? 0;

// Admin views system-wide newest record, Farmer views ONLY their own latest record
if ($role === 'admin') {
    $latest = $conn->query("SELECT * FROM sensor_data ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} else {
    $stmt = $conn->prepare("SELECT * FROM sensor_data WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $latest = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<div class="sub-view-panel">
    <h2>📋 Recommendations</h2>
    
    <?php if (!$latest): ?>
        <p>No telemetry data available yet to evaluate farming recommendations.</p>
    <?php else: ?>
        
        <?php if ($role === 'farmer'): ?>
            <h3>👨‍🌾 Customized For Your Rice Field</h3>
            <ul>
                <?php if ($latest['moisture'] < 30): ?>
                    <li>Water the field immediately to prevent crop stress.</li>
                <?php endif; ?>
                
                <?php if ($latest['moisture'] > 60): ?>
                    <li>Stop watering and improve soil drainage.</li>
                <?php endif; ?>
                
                <?php if ($latest['ph_level'] < 5.0): ?>
                    <li>Apply agricultural lime or dolomite to raise pH.</li>
                <?php endif; ?>
                
                <?php if ($latest['ph_level'] > 7.5): ?>
                    <li>Mix compost or well-rotted manure to lower pH.</li>
                <?php endif; ?>
                
                <?php if ($latest['nitrogen'] < 20): ?>
                    <li>Add nitrogen-rich fertilizer (urea, ammonium sulfate).</li>
                <?php endif; ?>
                
                <?php if ($latest['phosphorus'] < 10): ?>
                    <li>Apply phosphate fertilizer to support root growth.</li>
                <?php endif; ?>
                
                <?php if ($latest['potassium'] < 15): ?>
                    <li>Use muriate of potash or wood ash to improve resistance.</li>
                <?php endif; ?>
                
                <?php if ($latest['status'] === "OPTIMAL"): ?>
                    <li>Maintain current irrigation and fertilization schedule.</li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>

        <?php if ($role === 'admin'): ?>
            <h3>👔 Cooperative Administrative Action Directives</h3>
            <ul>
                <li>Monitor daily readings to spot long-term regional trends.</li>
                <li>Guide farmers on correct fertilizer dosages based on aggregated low-nutrient logs.</li>
                <li>Generate weekly soil health overview metrics.</li>
                <li>Send physical cooperative broadcast alerts when conditions become critical.</li>
            </ul>
        <?php endif; ?>

    <?php endif; ?>
</div>