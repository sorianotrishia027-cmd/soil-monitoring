<?php
// Ensure session role is accessible (default to farmer if not set)
$role = $_SESSION['role'] ?? 'farmer';
?>
<div class="home-view-grid">
    <!-- Telemetry Strip Overview -->
    <div class="summary-telemetry-strip">
        <div class="telemetry-chip">
            <span class="chip-label">Soil Moisture:</span>
            <span class="chip-val">65% <span class="status-indicator-dot green">●</span></span>
        </div>
        <div class="telemetry-chip">
            <span class="chip-label">pH Level:</span>
            <span class="chip-val">6.2 <span class="status-indicator-dot green">●</span></span>
        </div>
        <div class="telemetry-chip">
            <span class="chip-label">Temperature:</span>
            <span class="chip-val">28°C <span class="status-indicator-dot green">●</span></span>
        </div>
        <div class="telemetry-chip">
            <span class="chip-label">System Mode:</span>
            <span class="chip-val sub-text-alert"><?php echo ucfirst($role); ?> Portal</span>
        </div>
    </div>

    <!-- Macro-Nutrient Highlight Card -->
    <div class="npk-hero-card">
        <h3>Current Nutrient Composition</h3>
        <h1>NPK: 4-2-3 (mg/kg)</h1>
        <div class="badge-row">
            <span class="status-pill optimal-green">Soil Conditions Stable</span>
        </div>
    </div>

    <!-- Live Alerts and Chart Analytics Mapping -->
    <div class="insights-dashboard-split-row">
        <div class="action-alert-panel-card">
            <h3>Soil Status: Wet / Saturated</h3>
            <h2>Monitor Drainage</h2>
            
            <div class="nested-sub-recommends-box">
                <span class="muted-title">RECOMMENDED ACTION</span>
                <?php if ($role === 'farmer'): ?>
                    <p>Stop watering; improve soil drainage; avoid tilling to prevent compaction.</p>
                <?php else: ?>
                    <p>Advise on drainage solutions; monitor for risk of root rot or fungal diseases.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="analytical-chart-card">
            <h3>Moisture Trend (Last 7 Days)</h3>
            <div class="canvas-chart-wrapper">
                <canvas id="moistureTrendChart"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
    // Self-correcting chart implementation for real-time scale visibility
    const ctx = document.getElementById('moistureTrendChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: ['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6', 'Day 7'],
            datasets: [{
                label: 'Moisture level Data Stream (%)',
                data: [58, 60, 62, 61, 64, 63, 65], // Mapped to logical percentage metrics
                borderColor: '#0b8a47',
                backgroundColor: 'rgba(11, 138, 71, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.3,
                pointRadius: 4
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