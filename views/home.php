<div class="home-view-grid">
    <div class="summary-telemetry-strip">
        <div class="telemetry-chip">
            <span class="chip-label">Soil Moisture:</span>
            <span class="chip-val">65% <span class="status-indicator-dot green">●</span></span>
        </div>
        <div class="telemetry-chip">
            <span class="chip-label">pH Level:</span>
            <span class="chip-val">6.2</span>
        </div>
        <div class="telemetry-chip">
            <span class="chip-label">Temperature:</span>
            <span class="chip-val">28°C</span>
        </div>
        <div class="telemetry-chip">
            <span class="chip-label">Recommendations:</span>
            <span class="chip-val sub-text-alert">30400/695/0% <span class="status-indicator-dot green">●</span></span>
        </div>
    </div>

    <div class="npk-hero-card">
        <h1>NPK: 4-2-3</h1>
        <div class="badge-row">
            <span class="status-pill optimal-green">Optimal</span>
            <span class="status-pill warning-red">Alert</span>
        </div>
    </div>

    <div class="insights-dashboard-split-row">
        <div class="action-alert-panel-card">
            <h3>Soil moisture low - irrigate</h3>
            <h2>irrigate now</h2>
            
            <div class="nested-sub-recommends-box">
                <span class="muted-title">RECOMMS</span>
                <p>Apply organic fertilizer next week</p>
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
    const ctx = document.getElementById('moistureTrendChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: ['10', '22', '40', '55', '75', '98', '110', '120'],
            datasets: [{
                label: 'Moisture level Data Stream (%)',
                data: [150, 220, 200, 380, 360, 310, 480, 410],
                borderColor: '#4caf50',
                backgroundColor: 'rgba(76, 175, 80, 0.15)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { min: 0, max: 500, grid: { color: '#e0e0e0' } },
                x: { grid: { display: false } }
            }
        }
    });
</script>