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
            <span class="chip-val" id="home-val-moisture"><?= $latest ? htmlspecialchars(number_format($latest['moisture'], 1)) . '%' : '--' ?></span>
        </div>
        <div class="telemetry-chip">
            <span class="chip-label">pH Level:</span>
            <span class="chip-val" id="home-val-ph"><?= $latest ? htmlspecialchars(number_format($latest['ph'], 1)) : '--' ?></span>
        </div>
        <div class="telemetry-chip">
            <span class="chip-label">Temperature:</span>
            <span class="chip-val" id="home-val-temp"><?= $latest ? htmlspecialchars(number_format($latest['temperature'], 1)) . '°C' : '--' ?></span>
        </div>
        <div class="telemetry-chip">
            <span class="chip-label">System Mode:</span>
            <span class="chip-val sub-text-alert"><?= ucfirst($role) ?> Portal</span>
        </div>
    </div>

    <div class="npk-hero-card">
        <h3>Current Nutrient Composition</h3>
        <h1 id="home-val-npk">NPK: <?= $latest ? htmlspecialchars($latest['nitrogen']) . ' / ' . htmlspecialchars($latest['phosphorus']) . ' / ' . htmlspecialchars($latest['potassium']) : '-- -- --' ?></h1>
        <div class="badge-row">
            <span id="home-val-badge" class="status-pill <?= $latest ? ($soilStatus === 'OPTIMAL' ? 'optimal-green' : 'warning-red') : '' ?>"
                  style="<?= !$latest ? 'background: #e0e0e0; color: #666;' : '' ?>">
                <?= $latest ? $soilStatus : 'No Data' ?>
            </span>
        </div>
    </div>

    <div class="insights-dashboard-split-row">
        <div class="action-alert-panel-card">
            <h3>Soil Status</h3>
            <h2 id="home-val-status-title"><?= $latest ? $soilStatus : 'Awaiting Streams' ?></h2>
            
            <div class="nested-sub-recommends-box" id="home-val-status-box" style="border-left-color: <?= $latest ? ($soilStatus === 'OPTIMAL' ? '#4caf50' : '#e65100') : '#ccd4cc' ?>;">
                <span class="muted-title">STATUS</span>
                <p id="home-val-status-desc"><?= ($latest && isset($latest['created_at'])) ? 'Last updated: ' . date('M j, g:i A', strtotime($latest['created_at'])) : 'System is ready. Awaiting data inputs.' ?></p>
            </div>
        </div>

        <div class="analytical-chart-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <h3 style="margin: 0;">Moisture Trend</h3>
                <span style="font-size: 11px; font-weight: 700; color: #2e7d32; display: flex; align-items: center; gap: 6px;">
                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background-color: #2e7d32; box-shadow: 0 0 0 0 rgba(46, 125, 50, 0.7); animation: livePulse 1.8s infinite;"></span>
                    LIVE REAL-TIME STREAM
                </span>
            </div>
            <div class="canvas-chart-wrapper">
                <canvas id="moistureTrendChart"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
    const ctx = document.getElementById('moistureTrendChart').getContext('2d');
    const moistureChart = new Chart(ctx, {
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
            animation: false,
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

    // Real-Time Live Auto-Update Without Page Reload
    (function() {
        let lastHomeId = <?= $latest['id'] ?? 0 ?>;

        function updateHomeTelemetry() {
            fetch('api/get_live_telemetry.php?_=' + Date.now())
                .then(res => res.json())
                .then(res => {
                    if (res.status !== 'success' || !res.data) return;
                    const d = res.data;

                    // Update Top Chips
                    const mEl = document.getElementById('home-val-moisture');
                    const phEl = document.getElementById('home-val-ph');
                    const tEl = document.getElementById('home-val-temp');
                    const npkEl = document.getElementById('home-val-npk');
                    const badgeEl = document.getElementById('home-val-badge');
                    const titleEl = document.getElementById('home-val-status-title');
                    const boxEl = document.getElementById('home-val-status-box');
                    const descEl = document.getElementById('home-val-status-desc');

                    if (mEl && d.moisture !== null) mEl.textContent = parseFloat(d.moisture).toFixed(1) + '%';
                    if (phEl && d.ph !== null) phEl.textContent = parseFloat(d.ph).toFixed(1);
                    if (tEl && d.temperature !== null) tEl.textContent = parseFloat(d.temperature).toFixed(1) + '°C';
                    if (npkEl) npkEl.textContent = `NPK: ${d.nitrogen} / ${d.phosphorus} / ${d.potassium}`;

                    // Compute Status
                    let status = "OPTIMAL";
                    let isWarning = false;
                    if (d.moisture < 20 || d.moisture > 80 || d.ph < 5.0 || d.ph > 8.0) {
                        status = "WARNING";
                        isWarning = true;
                    }

                    if (badgeEl) {
                        badgeEl.textContent = status;
                        badgeEl.className = 'status-pill ' + (isWarning ? 'warning-red' : 'optimal-green');
                        badgeEl.style = '';
                    }

                    if (titleEl) titleEl.textContent = status;
                    if (boxEl) boxEl.style.borderLeftColor = isWarning ? '#e65100' : '#4caf50';
                    if (descEl) descEl.textContent = 'Last updated: ' + (d.formatted_time || 'Just now');

                    // Update Chart if new data arrived
                    if (d.id && d.id !== lastHomeId && d.chart_labels && d.chart_data) {
                        lastHomeId = d.id;
                        moistureChart.data.labels = d.chart_labels;
                        moistureChart.data.datasets[0].data = d.chart_data;
                        moistureChart.update('none');
                    }
                })
                .catch(err => console.debug('Home telemetry live fetch:', err));
        }

        // Live stream check every 2 seconds
        setInterval(updateHomeTelemetry, 2000);
    })();
</script>