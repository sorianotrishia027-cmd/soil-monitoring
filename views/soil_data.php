<?php
// views/soil_data.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

if (!isset($conn)) {
    require_once __DIR__ . '/../config/db_connect.php';
}

$user_id = $_SESSION['user_id'] ?? 0;
$role = strtolower($_SESSION['role'] ?? 'farmer');

// 1. Fetch Latest Telemetry Reading from soil_readings
if (!isset($latest) || empty($latest)) {
    try {
        $stmtLatest = $conn->query("SELECT * FROM soil_readings ORDER BY id DESC LIMIT 1");
        $latest = $stmtLatest->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $latest = null;
    }
}

// 2. Fetch History Logs for Table View
try {
    $stmtLogs = $conn->query("SELECT * FROM soil_readings ORDER BY created_at DESC LIMIT 20");
    $historyLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $historyLogs = [];
}

// Safe Field Extraction with Key Fallbacks
$valMoisture = isset($latest['moisture']) ? $latest['moisture'] : ($latest['soil_moisture'] ?? null);
$valPh       = isset($latest['ph']) ? $latest['ph'] : ($latest['ph_level'] ?? null);
$valN        = isset($latest['nitrogen']) ? $latest['nitrogen'] : ($latest['n'] ?? null);
$valP        = isset($latest['phosphorus']) ? $latest['phosphorus'] : ($latest['p'] ?? null);
$valK        = isset($latest['potassium']) ? $latest['potassium'] : ($latest['k'] ?? null);
$valTemp     = isset($latest['temperature']) ? $latest['temperature'] : ($latest['temp'] ?? null);
?>

<div class="soil-data-container">
    <!-- Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
            <h2 style="margin: 0; color: #1a252c;">My Soil Telemetry & Analysis</h2>
            <p style="margin: 4px 0 0; color: #6c757d; font-size: 14px;">Logged field readings (Saved every 30 minutes)</p>
        </div>
        <div style="background: #e8f5e9; color: #2e7d32; padding: 8px 16px; border-radius: 20px; font-weight: 600; font-size: 13px;" id="soil-live-badge">
            ● 30-Min Interval Logging Active
        </div>
    </div>

    <!-- Live Telemetry Metric Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 25px;">
        
        <!-- Moisture Card -->
        <div style="background: #ffffff; padding: 18px; border-radius: 12px; border-left: 4px solid #0d6efd; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <span style="font-size: 12px; text-transform: uppercase; color: #6c757d; font-weight: 700;">Soil Moisture</span>
            <h2 style="margin: 8px 0; color: #0d6efd;" id="soil-val-moisture">
                <?= $valMoisture !== null ? htmlspecialchars(number_format(floatval($valMoisture), 1)) . '%' : '--' ?>
            </h2>
            <span style="font-size: 11px; color: #888;">Target: 30% - 60%</span>
        </div>

        <!-- pH Card -->
        <div style="background: #ffffff; padding: 18px; border-radius: 12px; border-left: 4px solid #198754; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <span style="font-size: 12px; text-transform: uppercase; color: #6c757d; font-weight: 700;">pH Level</span>
            <h2 style="margin: 8px 0; color: #198754;" id="soil-val-ph">
                <?= $valPh !== null ? htmlspecialchars(number_format(floatval($valPh), 1)) : '--' ?>
            </h2>
            <span style="font-size: 11px; color: #888;">Target: 5.0 - 7.5</span>
        </div>

        <!-- Nitrogen Card -->
        <div style="background: #ffffff; padding: 18px; border-radius: 12px; border-left: 4px solid #0dcaf0; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <span style="font-size: 12px; text-transform: uppercase; color: #6c757d; font-weight: 700;">Nitrogen (N)</span>
            <h2 style="margin: 8px 0; color: #0dcaf0;" id="soil-val-n">
                <?= $valN !== null ? htmlspecialchars($valN) : '--' ?> <span style="font-size: 14px;">mg/kg</span>
            </h2>
            <span style="font-size: 11px; color: #888;">Target: 20 - 50</span>
        </div>

        <!-- Phosphorus Card -->
        <div style="background: #ffffff; padding: 18px; border-radius: 12px; border-left: 4px solid #ffc107; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <span style="font-size: 12px; text-transform: uppercase; color: #6c757d; font-weight: 700;">Phosphorus (P)</span>
            <h2 style="margin: 8px 0; color: #d39e00;" id="soil-val-p">
                <?= $valP !== null ? htmlspecialchars($valP) : '--' ?> <span style="font-size: 14px;">mg/kg</span>
            </h2>
            <span style="font-size: 11px; color: #888;">Target: 10 - 30</span>
        </div>

        <!-- Potassium Card -->
        <div style="background: #ffffff; padding: 18px; border-radius: 12px; border-left: 4px solid #dc3545; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <span style="font-size: 12px; text-transform: uppercase; color: #6c757d; font-weight: 700;">Potassium (K)</span>
            <h2 style="margin: 8px 0; color: #dc3545;" id="soil-val-k">
                <?= $valK !== null ? htmlspecialchars($valK) : '--' ?> <span style="font-size: 14px;">mg/kg</span>
            </h2>
            <span style="font-size: 11px; color: #888;">Target: 15 - 50</span>
        </div>

        <!-- Temperature Card -->
        <div style="background: #ffffff; padding: 18px; border-radius: 12px; border-left: 4px solid #6c757d; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <span style="font-size: 12px; text-transform: uppercase; color: #6c757d; font-weight: 700;">Temperature</span>
            <h2 style="margin: 8px 0; color: #343a40;" id="soil-val-temp">
                <?= $valTemp !== null ? htmlspecialchars(number_format(floatval($valTemp), 1)) . '°C' : '--' ?>
            </h2>
            <span style="font-size: 11px; color: #888;">Target: 20°C - 32°C</span>
        </div>
    </div>

    <!-- Parameter Criteria Reference Card -->
    <div style="background: #ffffff; padding: 22px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 25px;">
        <h3 style="margin-top: 0; color: #212529; font-size: 18px;">Soil Parameter Criteria Rules</h3>
        <p style="color: #6c757d; font-size: 13px; margin-bottom: 15px;">Review standard ranges and threshold matrices used to evaluate crop growing conditions.</p>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; font-size: 13px; line-height: 1.6;">
            <div>
                <strong>Soil Moisture (Range: 0 – 100%)</strong>
                <ul style="padding-left: 18px; margin: 5px 0 0; color: #495057;">
                    <li><strong>&lt; 30% (Dry):</strong> Requires immediate irrigation.</li>
                    <li><strong>30% – 60% (Optimal):</strong> Favorable growth bounds.</li>
                    <li><strong>&gt; 60% (Wet):</strong> Halt irrigation; optimize drainage.</li>
                </ul>
            </div>

            <div>
                <strong>Soil pH Level (Range: 0 – 14)</strong>
                <ul style="padding-left: 18px; margin: 5px 0 0; color: #495057;">
                    <li><strong>&lt; 5.0 (Acidic):</strong> Requires lime treatments.</li>
                    <li><strong>5.0 – 7.5 (Optimal):</strong> Stable absorption environments.</li>
                    <li><strong>&gt; 7.5 (Alkaline):</strong> Requires organic or sulfur additives.</li>
                </ul>
            </div>

            <div>
                <strong>Temperature (Range: 0°C – 50°C)</strong>
                <ul style="padding-left: 18px; margin: 5px 0 0; color: #495057;">
                    <li><strong>&lt; 18°C (Cool):</strong> Apply mulch covers.</li>
                    <li><strong>20°C – 32°C (Optimal):</strong> Baseline growth conditions.</li>
                    <li><strong>&gt; 35°C (High):</strong> Avoid midday watering.</li>
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

    <!-- Recent Telemetry History Table -->
    <div style="background: #ffffff; padding: 22px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
        <h3 style="margin-top: 0; color: #212529; font-size: 18px;">📋 Recent Telemetry History</h3>
        <p style="color: #6c757d; font-size: 13px; margin-bottom: 15px;">Logged analysis data recorded every 30 minutes.</p>

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
                            <?php 
                                $lMoisture = $log['moisture'] ?? $log['soil_moisture'] ?? 0;
                                $lPh       = $log['ph'] ?? $log['ph_level'] ?? 0;
                                $lN        = $log['nitrogen'] ?? $log['n'] ?? 0;
                                $lP        = $log['phosphorus'] ?? $log['p'] ?? 0;
                                $lK        = $log['potassium'] ?? $log['k'] ?? 0;
                                $lTemp     = $log['temperature'] ?? $log['temp'] ?? 0;
                            ?>
                            <tr style="border-bottom: 1px solid #e9ecef;">
                                <td style="padding: 10px;"><?= isset($log['created_at']) ? date("M j, Y - g:i A", strtotime($log['created_at'])) : 'N/A' ?></td>
                                <td style="padding: 10px; font-weight: 600; color: #0d6efd;"><?= number_format(floatval($lMoisture), 1) ?>%</td>
                                <td style="padding: 10px; font-weight: 600; color: #198754;"><?= number_format(floatval($lPh), 1) ?></td>
                                <td style="padding: 10px;"><?= htmlspecialchars($lN) ?> mg/kg</td>
                                <td style="padding: 10px;"><?= htmlspecialchars($lP) ?> mg/kg</td>
                                <td style="padding: 10px;"><?= htmlspecialchars($lK) ?> mg/kg</td>
                                <td style="padding: 10px;"><?= number_format(floatval($lTemp), 1) ?>°C</td>
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