<?php
include "../config/db_connect.php";
$latest = $conn->query("SELECT * FROM sensor_data ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$role = $_SESSION['role'] ?? 'farmer';
?>
<div class="home-view-grid">
    <!-- Telemetry Strip Overview -->
    <div class="summary-telemetry-strip">
        <div class="telemetry-chip">
            <span class="chip-label">Soil Moisture:</span>
            <span class="chip-val"><?= $latest ? htmlspecialchars($latest['moisture']) . '%' : '--' ?></span>
        </div>
        <div class="telemetry-chip">
            <span class="chip-label">pH Level:</span>
            <span class="chip-val"><?= $latest ? htmlspecialchars($latest['ph_level']) : '--' ?></span>
        </div>
        <div class="telemetry-chip">
            <span class="chip-label">Temperature:</span>
            <span class="chip-val"><?= $latest ? htmlspecialchars($latest['temperature']) . '°C' : '--' ?></span>
        </div>
        <div class="telemetry-chip">
            <span class="chip-label">System Mode:</span>
            <span class="chip-val sub-text-alert"><?= ucfirst($role) ?> Portal</span>
        </div>
    </div>

    <!-- NPK Nutrient Card -->
    <div class="npk-hero-card">
        <h3>Current Nutrient Composition</h3>
        <h1>NPK: <?= $latest ? htmlspecialchars($latest['nitrogen']) . ' / ' . htmlspecialchars($latest['phosphorus']) . ' / ' . htmlspecialchars($latest['potassium']) : '-- -- --' ?></h1>
        <div class="badge-row">
            <span class="status-pill <?= $latest ? ($latest['status'] === 'OPTIMAL' ? 'optimal-green' : 'warning-red') : '' ?>"
                  style="<?= !$latest ? 'background: #e0e0e0; color: #666;' : '' ?>">
                <?= $latest ? htmlspecialchars($latest['status']) : 'No Data' ?>
            </span>
        </div>
    </div>

    <!-- Status & Chart -->
    <div class="insights-dashboard-split-row">
        <div class="action-alert-panel-card">
            <h3>Soil Status</h3>
            <h2><?= $latest ? htmlspecialchars($latest['status']) : 'Awaiting Streams' ?></h2>
            
            <div class="nested-sub-recommends-box" style="border-left-color: <?= $latest ? '#4caf50' : '#ccd4cc' ?>;">
                <span class="muted-title">STATUS</span>
                <p><?= $latest ? 'Last updated: ' . date('M j, g:i A', strtotime($latest['created_at'])) : 'System is ready. Awaiting data inputs.' ?></p>
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
            labels: ['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6', 'Day 7'],
            datasets: [{
                label: 'Moisture Level (%)',
                data: [],
                borderColor: '#0b8a47',
                borderDash: [5, 5],
                fill: false
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