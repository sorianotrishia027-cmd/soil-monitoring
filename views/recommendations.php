<?php
include "../config/db_connect.php";

// Fetch the absolute latest generated or simulated sensor entry row
$latest = $conn->query("SELECT * FROM sensor_data ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
?>
<div class="sub-view-panel">
    <h2>📋 Recommendations</h2>
    
    <?php if (!$latest): ?>
        <p>No data available to generate recommendations.</p>
    <?php else: ?>
        
        <h3>👨‍🌾 For Farmers</h3>
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

        <h3>👔 For Administrators</h3>
        <ul>
            <li>Monitor daily readings to spot long-term trends.</li>
            <li>Guide farmers on correct fertilizer dosages.</li>
            <li>Generate weekly soil health reports.</li>
            <li>Send alerts when conditions become critical.</li>
        </ul>

    <?php endif; ?>
</div>