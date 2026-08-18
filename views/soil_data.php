<?php
// views/soil_data.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure database connection is present
if (!isset($conn)) {
    require_once __DIR__ . '/../config/db_connect.php';
}

$user_id = $_SESSION['user_id'] ?? 0;
$role = strtolower($_SESSION['role'] ?? 'farmer');

// Fetch latest live reading from soil_readings
try {
    $stmtLatest = $conn->query("SELECT * FROM soil_readings ORDER BY created_at DESC LIMIT 1");
    $latest = $stmtLatest->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $latest = null;
}

// Fetch historical logs (last 15 entries)
try {
    $stmtLogs = $conn->query("SELECT * FROM soil_readings ORDER BY created_at DESC LIMIT 15");
    $historyLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $historyLogs = [];
}
?>

<div class="soil-data-container">
    <!-- View Title & Live Sync Badge -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
            <h2 style="margin: 0; color: #1a252c;">My Soil Telemetry & Analysis</h2>
            <p style="margin: 4px 0 0; color: #6c757d; font-size: 14px;">Real-time parameters streamed from field sensor node</p>
        </div>
        <div style="background: #e8f5e9; color: #2e7d32; padding: 8px 16px; border-radius: 20px; font-weight: 600; font-size: 13px;" id="soil-live-badge">
            ● Live Stream Connected
        </div>
    </div>

    <!-- Live Telemetry Metric Cards Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 25px;">
        
        <!-- Moisture Card -->
        <div style="background: #ffffff; padding: 18px; border-radius: 12px; border-left: 4px solid #0d6efd; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <span style="font-size: 12px; text-transform: uppercase; color: #6c757d; font-weight: 700;">Soil Moisture</span>
            <h2 style="margin: 8px 0; color: #0d6efd;" id="soil-val-moisture">
                <?= $latest ? htmlspecialchars(number_format($latest['moisture'], 1)) . '%' : '--' ?>
            </h2>
            <span style="font-size: 11px; color: #888;">Optimal: 30% - 60%</span>
        </div>

        <!-- pH Card -->
        <div style="background: #ffffff; padding: 18px; border-radius: 12px; border-left: 4px solid #198754; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <span style="font-size: 12px; text-transform: uppercase; color: #6c757d; font-weight: 700;">pH Level</span>
            <h2 style="margin: 8px 0; color: #198754;" id="soil-val-ph">
                <?= $latest ? htmlspecialchars(number_format($latest['ph'], 1)) : '--' ?>
            </h2>
            <span style="font-size: 11px; color: #888;">Optimal: 5.0 - 7.5</span>
        </div>

        <!-- Nitrogen Card -->
        <div style="background: #ffffff; padding: 18px; border-radius: 12px; border-left: 4px solid #0dcaf0; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <span style="font-size: 12px; text-transform: uppercase; color: #6c757d; font-weight: 700;">Nitrogen (N)</span>
            <h2 style="margin: 8px 0; color: #0dcaf0;" id="soil-val-n">
                <?= $latest ? htmlspecialchars($latest['nitrogen']) : '--' ?> <span style="font-size: 14px;">mg/kg</span>
            </h2>
            <span style="font-size: 11px; color: #888;">Optimal: 20 - 50</span>
        </div>

        <!-- Phosphorus Card -->
        <div style="background: #ffffff; padding: 18px; border-radius: 12px; border-left: 4px solid #ffc107; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <span style="font-size: 12px; text-transform: uppercase; color: #6c757d; font-weight: 700;">Phosphorus (P)</span>
            <h2 style="margin: 8px 0; color: #d39e00;" id="soil-val-p">
                <?= $latest ? htmlspecialchars($latest['phosphorus']) : '--' ?> <span style="font-size: 14px;">mg/kg</span>
            </h2>
            <span style="font-size: 11px; color: #888;">Optimal: 10 - 30</span>
        </div>

        <!-- Potassium Card -->
        <div style="background: #ffffff; padding: 18px; border-radius: 12px; border-left: 4px solid #dc3545; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <span style="font-size: 12px; text-transform: uppercase; color: #6c757d; font-weight: 700;">Potassium (K)</span>
            <h2 style="margin: 8px 0; color: #dc3545;" id="soil-val-k">
                <?= $latest ? htmlspecialchars($latest['potassium']) : '--' ?> <span style="font-size: 14px;">mg/kg</span>
            </h2>
            <span style="font-size: 11px; color: #888;">Optimal: 15 - 50</span>
        </div>

        <!-- Temperature Card -->
        <div style="background: #ffffff; padding: 18px; border-radius: 12px; border-left: 4px solid #6c757d; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <span style="font-size: 12px; text-transform: uppercase; color: #6c757d; font-weight: 700;">Temperature</span>
            <h2 style="margin: 8px 0; color: #343a40;" id="soil-val-temp">
                <?= $latest ? htmlspecialchars(number_format($latest['temperature'], 1)) . '°C' : '--' ?>
            </h2>
            <span style="font-size: 11px; color: #888;">Optimal: 20°C - 32°C</span>
        </div>
    </div>

    <!-- Parameter Criteria Rules Reference Card -->
    <div style="background: #ffffff; padding: 22px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 25px;">
        <h3 style="margin-top: 0; color: #212529; font-size: 18px;">Soil Parameter Criteria Rules</h3>
        <p style="color: #6c757d; font-size: 13px; margin-bottom: 15px;">Review standard ranges and threshold matrices used to evaluate crop growing conditions.</p>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; font-size: 13px; line-height: 1.6;">
            <div>
                <strong>Soil Moisture (Range: 0 – 100%)</strong>
                <ul style="padding-left: 18px; margin: 5px 0 0; color: #495057;">
                    <li><strong>&lt; 30% (Dry):</strong> Requires immediate irrigation intervention.</li>
                    <li><strong>30% – 60% (Optimal):</strong> Favorable status bounds.</li>
                    <li><strong>&gt; 60% (Wet / Saturated):</strong> Halt water application; optimize drainage.</li>
                </ul>
            </div>

            <div>
                <strong>Soil pH Level (Range: 0 – 14 | Ideal: 5.5 – 7.0)</strong>
                <ul style="padding-left: 18px; margin: 5px 0 0; color: #495057;">
                    <li><strong>&lt; 5.0 (Acidic):</strong> Requires lime or dolomite treatments.</li>
                    <li><strong>5.0 – 7.5 (Optimal):</strong> Stable absorption environments.</li>
                    <li><strong>&gt; 7.5 (Alkaline):</strong> Requires organic compound or sulfur additives.</li>
                </ul>
            </div>

            <div>
                <strong>Temperature (Range: 0°C – 50°C | Ideal: 20°C – 30°C)</strong>
                <ul style="padding-left: 18px; margin: 5px 0 0; color: #495057;">
                    <li><strong>&lt; 18°C (Cool / Low):</strong> Delay vulnerable seeding; apply mulch.</li>
                    <li><strong>20°C – 32°C (Optimal):</strong> Superb conditions for baseline growth.</li>
                    <li><strong>&gt; 35°C (High / Hot):</strong> High evaporation risk; avoid midday watering.</li>
                </ul>
            </div>

            <div>
                <strong>Nutrients (N-P-K) Unit: mg/kg</strong>
                <div style="margin-top: 5px; color: #495057;">
                    <strong>Nitrogen (N):</strong> &lt;20: Low | 20–50: Optimal | &gt;50: High<br>
                    <strong>Phosphorus (P):</strong> &lt;10: Low | 10–30: Optimal | &gt;30: High<br>
                    <strong>Potassium (K):</strong> &lt;15: Low | 15–50: Optimal | &gt;50: High
                </div>
            </div>
        </div>
    </div>

    <!-- Telemetry History Log Table -->
    <div style="background: #ffffff; padding: 22px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
        <h3 style="margin-top: 0; color: #212529; font-size: 18px;">📋 Recent Telemetry History</h3>
        <p style="color: #6c757d; font-size: 13px; margin-bottom: 15px;">Review your field's last logged analysis data profiles.</p>

        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13px;">
                <thead>
                    <tr style="background: #f8f9fa; border-bottom: 2px solid #dee2e6; color: #495057;">
                        <th style="padding: 10px;">Timestamp</th>
                        <th style="padding: 10px;">Moisture</th>
                        <th style="padding: 10px;">pH</th>
                        <th style="padding: 10px;">Nitrogen</th>
                        <th style="padding: 10px;">Phosphorus</th>
                        <th style="padding: 10px;">Potassium</th>
                        <th style="padding: 10px;">Temperature</th>
                    </tr>
                </thead>
                <tbody id="telemetry-table-body">
                    <?php if (!empty($historyLogs)): ?>
                        <?php foreach ($historyLogs as $log): ?>
                            <tr style="border-bottom: 1px solid #e9ecef;">
                                <td style="padding: 10px;"><?= date("M j, Y - g:i:s A", strtotime($log['created_at'])) ?></td>
                                <td style="padding: 10px; font-weight: 600; color: #0d6efd;"><?= number_format($log['moisture'], 1) ?>%</td>
                                <td style="padding: 10px; font-weight: 600; color: #198754;"><?= number_format($log['ph'], 1) ?></td>
                                <td style="padding: 10px;"><?= htmlspecialchars($log['nitrogen']) ?> mg/kg</td>
                                <td style="padding: 10px;"><?= htmlspecialchars($log['phosphorus']) ?> mg/kg</td>
                                <td style="padding: 10px;"><?= htmlspecialchars($log['potassium']) ?> mg/kg</td>
                                <td style="padding: 10px;"><?= number_format($log['temperature'], 1) ?>°C</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="padding: 20px; text-align: center; color: #6c757d;">No readings logged yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Live Auto Polling Script -->
<script>
function pollSoilDataView() {
    fetch('api/get_latest_data.php')
        .then(res => res.json())
        .then(res => {
            if (res.status === 'success' && res.data) {
                const d = res.data;
                document.getElementById('soil-val-moisture').innerText = (parseFloat(d.moisture) || 0).toFixed(1) + '%';
                document.getElementById('soil-val-ph').innerText = (parseFloat(d.ph) || 0).toFixed(1);
                document.getElementById('soil-val-n').innerHTML = (d.nitrogen || 0) + ' <span style="font-size: 14px;">mg/kg</span>';
                document.getElementById('soil-val-p').innerHTML = (d.phosphorus || 0) + ' <span style="font-size: 14px;">mg/kg</span>';
                document.getElementById('soil-val-k').innerHTML = (d.potassium || 0) + ' <span style="font-size: 14px;">mg/kg</span>';
                document.getElementById('soil-val-temp').innerText = (parseFloat(d.temperature) || 0).toFixed(1) + '°C';
            }
        })
        .catch(err => console.error("Error updating soil telemetry:", err));
}

// Refresh telemetry cards every 5 seconds
setInterval(pollSoilDataView, 5000);
</script>