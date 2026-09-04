<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include "../config/db_connect.php";

$role = strtolower($_SESSION['role'] ?? 'farmer');
$user_id = $_SESSION['user_id'] ?? 0;

// Fetch the latest reading from soil_readings
try {
    $stmt = $conn->query("SELECT * FROM soil_readings ORDER BY created_at DESC LIMIT 1");
    $latest = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $latest = null;
}

// Fetch historical readings for the moisture trend chart (last 7 entries)
try {
    $history_stmt = $conn->query("SELECT moisture, created_at FROM soil_readings ORDER BY created_at ASC LIMIT 7");
    $history_records = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $history_records = [];
}

$chart_labels = [];
$chart_data = [];

foreach ($history_records as $rec) {
    $chart_labels[] = date('M j, g:i A', strtotime($rec['created_at']));
    $chart_data[] = (float)$rec['moisture'];
}

// Fallback if no records exist yet
if (empty($chart_data)) {
    $chart_labels = ['Awaiting Data'];
    $chart_data = [0];
}

// Calculate simple soil status if not stored directly
$soilStatus = "OPTIMAL";
if ($latest) {
    if ($latest['moisture'] < 20 || $latest['moisture'] > 80 || $latest['ph'] < 5.5 || $latest['ph'] > 7.5) {
        $soilStatus = "WARNING";
    }
}
?>
<div class="home-view-grid">
    <div class="summary-telemetry-strip">
        <div class="telemetry-chip">
            <span class="chip-label">Soil Moisture:</span>
            <span class="chip-val"><?= $latest ? htmlspecialchars(number_format($latest['moisture'], 1)) . '%' : '--' ?></span>
        </div>
        <div class="telemetry-chip">
            <span class="chip-label">pH Level:</span>
            <span class="chip-val"><?= $latest ? htmlspecialchars(number_format($latest['ph'], 1)) : '--' ?></span>
        </div>
        <div class="telemetry-chip">
            <span class="chip-label">Temperature:</span>
            <span class="chip-val"><?= $latest ? htmlspecialchars(number_format($latest['temperature'], 1)) . '°C' : '--' ?></span>
        </div>
        <div class="telemetry-chip">
            <span class="chip-label">System Mode:</span>
            <span class="chip-val sub-text-alert"><?= ucfirst($role) ?> Portal</span>
        </div>
    </div>

    <div class="npk-hero-card">
        <h3>Current Nutrient Composition</h3>
        <h1>NPK: <?= $latest ? htmlspecialchars($latest['nitrogen']) . ' / ' . htmlspecialchars($latest['phosphorus']) . ' / ' . htmlspecialchars($latest['potassium']) : '-- -- --' ?></h1>
        <div class="badge-row">
            <span class="status-pill <?= $latest ? ($soilStatus === 'OPTIMAL' ? 'optimal-green' : 'warning-red') : '' ?>"
                  style="<?= !$latest ? 'background: #e0e0e0; color: #666;' : '' ?>">
                <?= $latest ? $soilStatus : 'No Data' ?>
            </span>
        </div>
    </div>

    <div class="insights-dashboard-split-row">
        <div class="action-alert-panel-card">
            <h3>Soil Status</h3>
            <h2><?= $latest ? $soilStatus : 'Awaiting Streams' ?></h2>
            
            <div class="nested-sub-recommends-box" style="border-left-color: <?= $latest ? '#4caf50' : '#ccd4cc' ?>;">
                <span class="muted-title">STATUS</span>
                <p><?= ($latest && isset($latest['created_at'])) ? 'Last updated: ' . date('M j, g:i A', strtotime($latest['created_at'])) : 'System is ready. Awaiting data inputs.' ?></p>
            </div>
        </div>

        <div class="analytical-chart-card">
            <h3>Moisture Trend</h3>
            <div class="canvas-chart-wrapper">
                <canvas id="moistureTrendChart"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
    const ctx = document.getElementById('moistureTrendChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chart_labels) ?>,
            datasets: [{
                label: 'Moisture Level (%)',
                data: <?= json_encode($chart_data) ?>,
                borderColor: '#0b8a47',
                backgroundColor: 'rgba(11, 138, 71, 0.05)',
                borderWidth: 2,
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { 
                    min: 0, 
                    max: 100, 
                    grid: { color: '#e2e8e2' },
                    ticks: { callback: function(value) { return value + '%'; } }
                },
                x: { grid: { display: false } }
            }
        }
    });
</script>