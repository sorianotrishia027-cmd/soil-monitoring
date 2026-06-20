<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include "../config/db_connect.php";

// Security Check: Administrative clearance guard lock
if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    echo "<p class='error'>⛔ Access Denied. Administrative clearance required.</p>";
    exit;
}

// 1. Fetch Aggregated Baseline Totals
$total_records = $conn->query("SELECT COUNT(*) FROM sensor_data")->fetchColumn();
$total_farmers = $conn->query("SELECT COUNT(*) FROM users WHERE LOWER(role) = 'farmer'")->fetchColumn();

// 2. Fetch Averages across the cooperative system
$averages = $conn->query("SELECT 
    AVG(moisture) as avg_moisture, 
    AVG(ph_level) as avg_ph, 
    AVG(temperature) as avg_temp,
    AVG(nitrogen) as avg_n,
    AVG(phosphorus) as avg_p,
    AVG(potassium) as avg_k
FROM sensor_data")->fetch(PDO::FETCH_ASSOC);

// 3. Count Critical Danger Outliers (e.g., Moisture < 30% or pH outside optimal range)
$critical_incidents = $conn->query("SELECT COUNT(*) FROM sensor_data WHERE moisture < 30 OR ph_level < 5.0 OR ph_level > 7.5")->fetchColumn();

// 4. Group data logs by Farmer to show panel overview breakdown statistics
$farmer_breakdown = $conn->query("
    SELECT u.username, u.fullname, 
           COUNT(s.id) as logs_count, 
           MAX(s.created_at) as last_submission
    FROM users u
    JOIN sensor_data s ON u.id = s.user_id
    WHERE LOWER(u.role) = 'farmer'
    GROUP BY u.id
    ORDER BY logs_count DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="sub-view-panel-container">
    <div class="view-panel-header">
        <h3>📊 Cooperative System Analytics & Reporting</h3>
        <p>Review comprehensive aggregated telemetry summaries and field metrics compiled across all deployed monitoring sectors.</p>
    </div>

    <div class="summary-telemetry-strip">
        <div class="telemetry-chip" style="border: 1px solid #ccd4cc;">
            <span class="chip-label">Total Transmissions Logged</span>
            <span class="chip-val" style="color: var(--primary-color); font-size: 24px; font-weight: 800;"><?= $total_records ?></span>
        </div>
        <div class="telemetry-chip" style="border: 1px solid #ccd4cc;">
            <span class="chip-label">Registered Farmer Fields</span>
            <span class="chip-val" style="color: #1565c0; font-size: 24px; font-weight: 800;"><?= $total_farmers ?></span>
        </div>
        <div class="telemetry-chip" style="border: 1px solid #ccd4cc;">
            <span class="chip-label">Critical Stress Alerts</span>
            <span class="chip-val" style="color: #c62828; font-size: 24px; font-weight: 800;"><?= $critical_incidents ?></span>
        </div>
    </div>

    <div class="insights-dashboard-split-row" style="margin-top: 10px; margin-bottom: 30px;">
        
        <div class="action-alert-panel-card" style="background: #ffffff; border: 1px solid #ccd4cc;">
            <h3 style="margin-bottom: 15px; color: var(--primary-color);">📈 System-Wide Soil Benchmarks</h3>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; text-align: left;">
                <div class="nested-sub-recommends-box" style="border-left-color: #2fa149;">
                    <span class="muted-title">AVG MOISTURE</span>
                    <p style="font-size: 18px; font-weight: bold;"><?= number_format($averages['avg_moisture'] ?? 0, 1) ?>%</p>
                </div>
                <div class="nested-sub-recommends-box" style="border-left-color: #89cc51;">
                    <span class="muted-title">AVG SOIL pH</span>
                    <p style="font-size: 18px; font-weight: bold;"><?= number_format($averages['avg_ph'] ?? 0, 1) ?></p>
                </div>
                <div class="nested-sub-recommends-box" style="border-left-color: #ffa726;">
                    <span class="muted-title">AVG TEMPERATURE</span>
                    <p style="font-size: 18px; font-weight: bold;"><?= number_format($averages['avg_temp'] ?? 0, 1) ?>°C</p>
                </div>
                <div class="nested-sub-recommends-box" style="border-left-color: #29b6f6;">
                    <span class="muted-title">MEAN N-P-K MATRIX</span>
                    <p style="font-size: 14px; font-weight: bold; margin-top: 4px;">
                        <?= number_format($averages['avg_n'] ?? 0, 0) ?> - <?= number_format($averages['avg_p'] ?? 0, 0) ?> - <?= number_format($averages['avg_k'] ?? 0, 0) ?> <span style="font-size:10px; color:#666;">mg/kg</span>
                    </p>
                </div>
            </div>
        </div>

        <div class="action-alert-panel-card" style="background: #ffffff; border: 1px solid #ccd4cc; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <h3 style="margin-bottom: 10px;">Export Options</h3>
                <p style="font-size: 14px; line-height: 1.5; color: var(--text-muted);">
                    Use the browser printing integration shortcut button below to showcase clean, structured agricultural summary report assets to your thesis review committee.
                </p>
            </div>
            <button onclick="window.print();" class="mockup-login-btn" style="margin-top: 15px;">
                🖨️ Print System Audit Summary
            </button>
        </div>
    </div>

    <div class="view-panel-header">
        <h3>📋 Node Transmission Densities by Sector</h3>
    </div>

    <div class="history-table-wrapper" style="overflow-x: auto; background: #fff; padding: 15px; border-radius: 16px; border: 1px solid #ccd4cc;">
        <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 14px;">
            <thead>
                <tr style="border-bottom: 2px solid #e2e8e2; color: #424242;">
                    <th style="padding: 12px;">Farmer Account</th>
                    <th style="padding: 12px;">Full Name</th>
                    <th style="padding: 12px; text-align: center;">Total Logged Transmissions</th>
                    <th style="padding: 12px; text-align: right;">Last Active Connection Sync</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($farmer_breakdown)): ?>
                    <tr>
                        <td colspan="4" style="padding: 15px; text-align: center; color: #888;">No data records assigned to active farmer profiles are currently tracked in the system.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($farmer_breakdown as $row): ?>
                        <tr style="border-bottom: 1px solid #f0f4f0;">
                            <td style="padding: 12px; font-weight: bold; color: var(--primary-color);">👤 <?= htmlspecialchars($row['username']) ?></td>
                            <td style="padding: 12px;"><?= htmlspecialchars($row['fullname'] ?: '---') ?></td>
                            <td style="padding: 12px; text-align: center; font-weight: bold; color: #333;"><?= $row['logs_count'] ?> logs</td>
                            <td style="padding: 12px; text-align: right; color: var(--text-muted);">
                                <?= date('M j, Y g:i A', strtotime($row['last_submission'])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>