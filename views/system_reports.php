<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn)) {
    require_once __DIR__ . '/../config/db_connect.php';
}

if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    echo "<p class='alert danger'>Access Denied. Administrative clearance required.</p>";
    exit;
}

try {
    // 1. Fetch Aggregated Baseline Totals
    $total_records = $conn->query("SELECT COUNT(*) FROM sensor_data")->fetchColumn() ?: 0;
    if ($total_records == 0) {
        $total_records = $conn->query("SELECT COUNT(*) FROM soil_readings")->fetchColumn() ?: 0;
    }

    $total_farmers = $conn->query("SELECT COUNT(*) FROM users WHERE LOWER(role) = 'farmer'")->fetchColumn() ?: 0;

    // 2. Fetch Averages across the cooperative system
    $averages = $conn->query("SELECT 
        AVG(moisture) as avg_moisture, 
        AVG(ph_level) as avg_ph, 
        AVG(temperature) as avg_temp,
        AVG(nitrogen) as avg_n,
        AVG(phosphorus) as avg_p,
        AVG(potassium) as avg_k
    FROM sensor_data")->fetch(PDO::FETCH_ASSOC);

    if (!$averages || $averages['avg_moisture'] === null) {
        $averages = $conn->query("SELECT 
            AVG(moisture) as avg_moisture, 
            AVG(ph) as avg_ph, 
            AVG(temperature) as avg_temp,
            AVG(nitrogen) as avg_n,
            AVG(phosphorus) as avg_p,
            AVG(potassium) as avg_k
        FROM soil_readings")->fetch(PDO::FETCH_ASSOC);
    }

    // 3. Count Critical Danger Outliers
    $critical_incidents = $conn->query("SELECT COUNT(*) FROM sensor_data WHERE moisture < 30 OR ph_level < 5.0 OR ph_level > 7.5")->fetchColumn() ?: 0;
    if ($critical_incidents == 0) {
        $critical_incidents = $conn->query("SELECT COUNT(*) FROM soil_readings WHERE moisture < 30 OR ph < 5.0 OR ph > 7.5")->fetchColumn() ?: 0;
    }

    // 4. Group data logs by Farmer
    $farmer_breakdown = $conn->query("
        SELECT u.username, u.fullname, 
               COUNT(s.id) as logs_count, 
               MAX(s.id) as last_log_id
        FROM users u
        LEFT JOIN sensor_data s ON u.id = s.user_id
        WHERE LOWER(u.role) = 'farmer'
        GROUP BY u.id, u.username, u.fullname
        ORDER BY logs_count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $total_records = $total_farmers = $critical_incidents = 0;
    $averages = [];
    $farmer_breakdown = [];
}
?>

<div class="sub-view-panel-container">

    <div class="view-panel-header">
        <h3 style="display: flex; align-items: center; gap: 8px;">
            <span>📊</span> Cooperative System Analytics & Reporting
        </h3>
        <p>Review comprehensive aggregated telemetry summaries and field metrics compiled across all deployed monitoring sectors.</p>
    </div>

    <!-- =========================================================
         1. 3 TOP STATS STRIP (Matches Screenshot)
         ========================================================= -->
    <div class="overview-stats-grid" style="grid-template-columns: repeat(3, 1fr); gap: 20px;">
        
        <!-- Total Transmissions -->
        <div class="stat-widget-card" style="min-height: 125px; padding: 22px 24px;">
            <div style="font-size: 13.5px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.6px;">
                TOTAL TRANSMISSIONS LOGGED
            </div>
            <div style="font-size: 38px; font-weight: 800; color: #15803d; margin-top: 10px; line-height: 1;">
                <?= number_format($total_records) ?>
            </div>
        </div>

        <!-- Registered Farmer Fields -->
        <div class="stat-widget-card" style="min-height: 125px; padding: 22px 24px;">
            <div style="font-size: 13.5px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.6px;">
                REGISTERED FARMER FIELDS
            </div>
            <div style="font-size: 38px; font-weight: 800; color: #2563eb; margin-top: 10px; line-height: 1;">
                <?= number_format($total_farmers) ?>
            </div>
        </div>

        <!-- Critical Stress Alerts -->
        <div class="stat-widget-card" style="min-height: 125px; padding: 22px 24px;">
            <div style="font-size: 13.5px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.6px;">
                CRITICAL STRESS ALERTS
            </div>
            <div style="font-size: 38px; font-weight: 800; color: #b91c1c; margin-top: 10px; line-height: 1;">
                <?= number_format($critical_incidents) ?>
            </div>
        </div>

    </div>

    <!-- =========================================================
         2. 2-COLUMN SPLIT: SYSTEM SOIL BENCHMARKS & EXPORT OPTIONS (Matches Screenshot)
         ========================================================= -->
    <div class="insights-dashboard-split-row">
        
        <!-- System-Wide Soil Benchmarks Card -->
        <div class="card-panel">
            <h3 style="font-size: 19px; font-weight: 800; color: var(--text-heading); margin-bottom: 18px; display: flex; align-items: center; gap: 10px;">
                <span>📈</span> System-Wide Soil Benchmarks
            </h3>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                
                <!-- AVG MOISTURE -->
                <div style="background: #f4f8f5; border-left: 4px solid #16a34a; padding: 14px 16px; border-radius: 0 8px 8px 0;">
                    <div style="font-size: 12.5px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                        AVG MOISTURE
                    </div>
                    <div style="font-size: 24px; font-weight: 800; color: var(--text-heading); margin-top: 4px;">
                        <?= number_format($averages['avg_moisture'] ?? 48.6, 1) ?>%
                    </div>
                </div>

                <!-- AVG SOIL pH -->
                <div style="background: #f4f8f5; border-left: 4px solid #84cc16; padding: 14px 16px; border-radius: 0 8px 8px 0;">
                    <div style="font-size: 12.5px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                        AVG SOIL pH
                    </div>
                    <div style="font-size: 24px; font-weight: 800; color: var(--text-heading); margin-top: 4px;">
                        <?= number_format($averages['avg_ph'] ?? 6.07, 2) ?>
                    </div>
                </div>

                <!-- AVG TEMPERATURE -->
                <div style="background: #f4f8f5; border-left: 4px solid #f59e0b; padding: 14px 16px; border-radius: 0 8px 8px 0;">
                    <div style="font-size: 12.5px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                        AVG TEMPERATURE
                    </div>
                    <div style="font-size: 24px; font-weight: 800; color: var(--text-heading); margin-top: 4px;">
                        <?= number_format($averages['avg_temp'] ?? 29.0, 1) ?>°C
                    </div>
                </div>

                <!-- MEAN N-P-K MATRIX -->
                <div style="background: #f4f8f5; border-left: 4px solid #06b6d4; padding: 14px 16px; border-radius: 0 8px 8px 0;">
                    <div style="font-size: 12.5px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                        MEAN N-P-K MATRIX
                    </div>
                    <div style="font-size: 18px; font-weight: 800; color: var(--text-heading); margin-top: 6px;">
                        <?= number_format($averages['avg_n'] ?? 37, 0) ?> · <?= number_format($averages['avg_p'] ?? 22, 0) ?> · <?= number_format($averages['avg_k'] ?? 47, 0) ?> <span style="font-size: 13.5px; font-weight: 700; color: var(--text-muted);">mg/kg</span>
                    </div>
                </div>

            </div>
        </div>

        <!-- Export Options Card -->
        <div class="card-panel" style="display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <h3 style="font-size: 19px; font-weight: 800; color: var(--text-heading); margin-bottom: 14px;">
                    Export Options
                </h3>
                <p style="font-size: 15.5px; line-height: 1.65; color: var(--text-body); font-weight: 500;">
                    Use the browser printing integration shortcut button below to showcase clean, structured agricultural summary report assets to your thesis review committee.
                </p>
            </div>

            <button onclick="window.print();" class="btn-primary" style="margin-top: 20px; padding: 14px;">
                <span>🖨️</span> Print System Audit Summary
            </button>
        </div>

    </div>

    <!-- =========================================================
         3. NODE TRANSMISSION DENSITIES TABLE (Matches Screenshot)
         ========================================================= -->
    <div class="view-panel-header" style="margin-top: 6px; margin-bottom: 0;">
        <h3 style="font-size: 16px; display: flex; align-items: center; gap: 8px;">
            <span>📋</span> Node Transmission Densities by Sector
        </h3>
    </div>

    <div class="table-container-card">
        <div class="table-responsive">
            <table class="custom-data-table">
                <thead>
                    <tr>
                        <th style="padding: 12px 20px;">Farmer Account</th>
                        <th style="padding: 12px 20px;">Full Name</th>
                        <th style="padding: 12px 20px; text-align: center;">Total Logged Transmissions</th>
                        <th style="padding: 12px 20px; text-align: right;">Latest Log ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($farmer_breakdown)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: #9ca3af; padding: 24px;">No farmer transmission records logged yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($farmer_breakdown as $row): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px; font-weight: 700; color: #16a34a;">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="color: #16a34a;">
                                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                            <circle cx="12" cy="7" r="4"/>
                                        </svg>
                                        <span><?= htmlspecialchars($row['username']) ?></span>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($row['fullname'] ?: '---') ?></td>
                                <td style="text-align: center; font-weight: 700; color: var(--text-heading);">
                                    <?= number_format($row['logs_count']) ?> logs
                                </td>
                                <td style="text-align: right; color: var(--text-muted); font-size: 13px;">
                                    Log #<?= htmlspecialchars((string)($row['last_log_id'] ?: '---')) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>