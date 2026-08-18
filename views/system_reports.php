<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure database connection
if (!isset($conn)) {
    require_once __DIR__ . '/../config/db_connect.php';
}

// Security Check: Administrative clearance guard lock
if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    echo "<p class='error' style='padding:15px; color:#dc3545;'>⛔ Access Denied. Administrative clearance required.</p>";
    exit;
}

try {
    // 1. Fetch Aggregated Baseline Totals
    $total_records = $conn->query("SELECT COUNT(*) FROM sensor_data")->fetchColumn() ?: 0;
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

    // 3. Count Critical Danger Outliers (e.g., Moisture < 30% or pH outside optimal range)
    $critical_incidents = $conn->query("SELECT COUNT(*) FROM sensor_data WHERE moisture < 30 OR ph_level < 5.0 OR ph_level > 7.5")->fetchColumn() ?: 0;

    // 4. Group data logs by Farmer (Fixed strict GROUP BY & replaced s.created_at with MAX(s.id))
    $farmer_breakdown = $conn->query("
        SELECT u.username, u.fullname, 
               COUNT(s.id) as logs_count, 
               MAX(s.id) as last_log_id
        FROM users u
        JOIN sensor_data s ON u.id = s.user_id
        WHERE LOWER(u.role) = 'farmer'
        GROUP BY u.id, u.username, u.fullname
        ORDER BY logs_count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    echo "<div style='background:#ffebee; color:#c62828; padding:12px; border-radius:8px;'>❌ Query Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    $total_records = $total_farmers = $critical_incidents = 0;
    $averages = [];
    $farmer_breakdown = [];
}
?>

<div class="sub-view-panel-container" style="padding: 10px;">
    <div class="view-panel-header" style="margin-bottom: 20px;">
        <h3 style="margin: 0;">📊 Cooperative System Analytics & Reporting</h3>
        <p style="margin: 4px 0 0; color: #6c757d; font-size: 14px;">Review comprehensive aggregated telemetry summaries and field metrics compiled across all deployed monitoring sectors.</p>
    </div>

    <!-- Summary Cards -->
    <div class="summary-telemetry-strip" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 25px;">
        <div class="telemetry-chip" style="background: #fff; padding: 16px; border-radius: 10px; border: 1px solid #ccd4cc; box-shadow: 0 2px 6px rgba(0,0,0,0.03);">
            <span class="chip-label" style="display: block; font-size: 12px; font-weight: 700; color: #6c757d; text-transform: uppercase;">Total Transmissions Logged</span>
            <span class="chip-val" style="color: #198754; font-size: 28px; font-weight: 800; margin-top: 5px; display: block;"><?= number_format($total_records) ?></span>
        </div>
        <div class="telemetry-chip" style="background: #fff; padding: 16px; border-radius: 10px; border: 1px solid #ccd4cc; box-shadow: 0 2px 6px rgba(0,0,0,0.03);">
            <span class="chip-label" style="display: block; font-size: 12px; font-weight: 700; color: #6c757d; text-transform: uppercase;">Registered Farmer Fields</span>
            <span class="chip-val" style="color: #1565c0; font-size: 28px; font-weight: 800; margin-top: 5px; display: block;"><?= number_format($total_farmers) ?></span>
        </div>
        <div class="telemetry-chip" style="background: #fff; padding: 16px; border-radius: 10px; border: 1px solid #ccd4cc; box-shadow: 0 2px 6px rgba(0,0,0,0.03);">
            <span class="chip-label" style="display: block; font-size: 12px; font-weight: 700; color: #6c757d; text-transform: uppercase;">Critical Stress Alerts</span>
            <span class="chip-val" style="color: #c62828; font-size: 28px; font-weight: 800; margin-top: 5px; display: block;"><?= number_format($critical_incidents) ?></span>
        </div>
    </div>

    <!-- Insights Split Row -->
    <div class="insights-dashboard-split-row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px;">
        
        <div class="action-alert-panel-card" style="background: #ffffff; border: 1px solid #ccd4cc; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
            <h3 style="margin-top: 0; margin-bottom: 15px; color: #198754;">📈 System-Wide Soil Benchmarks</h3>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; text-align: left;">
                <div class="nested-sub-recommends-box" style="background: #f8f9fa; padding: 12px; border-radius: 6px; border-left: 4px solid #2fa149;">
                    <span class="muted-title" style="font-size: 11px; color: #6c757d; font-weight: 700;">AVG MOISTURE</span>
                    <p style="font-size: 20px; font-weight: bold; margin: 4px 0 0; color: #212529;"><?= number_format($averages['avg_moisture'] ?? 0, 1) ?>%</p>
                </div>
                <div class="nested-sub-recommends-box" style="background: #f8f9fa; padding: 12px; border-radius: 6px; border-left: 4px solid #89cc51;">
                    <span class="muted-title" style="font-size: 11px; color: #6c757d; font-weight: 700;">AVG SOIL pH</span>
                    <p style="font-size: 20px; font-weight: bold; margin: 4px 0 0; color: #212529;"><?= number_format($averages['avg_ph'] ?? 0, 2) ?></p>
                </div>
                <div class="nested-sub-recommends-box" style="background: #f8f9fa; padding: 12px; border-radius: 6px; border-left: 4px solid #ffa726;">
                    <span class="muted-title" style="font-size: 11px; color: #6c757d; font-weight: 700;">AVG TEMPERATURE</span>
                    <p style="font-size: 20px; font-weight: bold; margin: 4px 0 0; color: #212529;"><?= number_format($averages['avg_temp'] ?? 0, 1) ?>°C</p>
                </div>
                <div class="nested-sub-recommends-box" style="background: #f8f9fa; padding: 12px; border-radius: 6px; border-left: 4px solid #29b6f6;">
                    <span class="muted-title" style="font-size: 11px; color: #6c757d; font-weight: 700;">MEAN N-P-K MATRIX</span>
                    <p style="font-size: 15px; font-weight: bold; margin: 4px 0 0; color: #212529;">
                        <?= number_format($averages['avg_n'] ?? 0, 0) ?> - <?= number_format($averages['avg_p'] ?? 0, 0) ?> - <?= number_format($averages['avg_k'] ?? 0, 0) ?> <span style="font-size:10px; color:#666;">mg/kg</span>
                    </p>
                </div>
            </div>
        </div>

        <div class="action-alert-panel-card" style="background: #ffffff; border: 1px solid #ccd4cc; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <h3 style="margin-top: 0; margin-bottom: 10px; color: #212529;">Export Options</h3>
                <p style="font-size: 14px; line-height: 1.5; color: #6c757d;">
                    Use the browser printing integration shortcut button below to showcase clean, structured agricultural summary report assets to your thesis review committee.
                </p>
            </div>
            <button onclick="window.print();" style="width: 100%; background: #198754; color: white; border: none; padding: 12px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 14px; margin-top: 15px;">
                🖨️ Print System Audit Summary
            </button>
        </div>
    </div>

    <!-- Node Transmission Breakdown Table -->
    <div class="view-panel-header" style="margin-bottom: 15px;">
        <h3 style="margin: 0; color: #1a252c;">📋 Node Transmission Densities by Sector</h3>
    </div>

    <div class="history-table-wrapper" style="overflow-x: auto; background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #ccd4cc; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
        <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 14px;">
            <thead>
                <tr style="border-bottom: 2px solid #e2e8e2; color: #495057; background: #f8f9fa;">
                    <th style="padding: 12px;">Farmer Account</th>
                    <th style="padding: 12px;">Full Name</th>
                    <th style="padding: 12px; text-align: center;">Total Logged Transmissions</th>
                    <th style="padding: 12px; text-align: right;">Latest Log ID</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($farmer_breakdown)): ?>
                    <tr>
                        <td colspan="4" style="padding: 20px; text-align: center; color: #6c757d;">No data records assigned to active farmer profiles are currently tracked in the system.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($farmer_breakdown as $row): ?>
                        <tr style="border-bottom: 1px solid #f0f4f0;">
                            <td style="padding: 12px; font-weight: bold; color: #198754;">👤 <?= htmlspecialchars($row['username']) ?></td>
                            <td style="padding: 12px;"><?= htmlspecialchars($row['fullname'] ?: '---') ?></td>
                            <td style="padding: 12px; text-align: center; font-weight: bold; color: #212529;"><?= number_format($row['logs_count']) ?> logs</td>
                            <td style="padding: 12px; text-align: right; color: #6c757d; font-weight: 600;">
                                Log #<?= htmlspecialchars($row['last_log_id']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>