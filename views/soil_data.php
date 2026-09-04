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

// Pagination Configuration
$limit = 15;
$page = isset($_GET['history_page']) ? max(1, intval($_GET['history_page'])) : 1;
$offset = ($page - 1) * $limit;

// 2. Fetch Total Count of Logs for Pagination Calculation
try {
    $stmtCount = $conn->query("SELECT COUNT(*) as total FROM soil_readings");
    $totalRows = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    $totalPages = ceil($totalRows / $limit);
} catch (PDOException $e) {
    $totalRows = 0;
    $totalPages = 1;
}

// 3. Fetch History Logs for Current Page with LIMIT and OFFSET
try {
    $stmtLogs = $conn->prepare("SELECT * FROM soil_readings ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
    $stmtLogs->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmtLogs->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtLogs->execute();
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

// Helper Functions for Status Evaluation & Color Mapping
function getStatusStyle($val, $min, $max) {
    if ($val === null) return ['color' => '#6c757d', 'border' => '#6c757d', 'status' => 'No Data'];
    $fVal = floatval($val);
    if ($fVal < $min) {
        return ['color' => '#dc3545', 'border' => '#dc3545', 'status' => 'Critical (Low)']; // Red
    } elseif ($fVal > $max) {
        return ['color' => '#e65100', 'border' => '#ff9800', 'status' => 'Warning (High)']; // Amber/Orange
    } else {
        return ['color' => '#198754', 'border' => '#198754', 'status' => 'Optimal']; // Green
    }
}

$mStyle = getStatusStyle($valMoisture, 30, 60);
$phStyle = getStatusStyle($valPh, 5.0, 7.5);
$nStyle = getStatusStyle($valN, 20, 50);
$pStyle = getStatusStyle($valP, 10, 30);
$kStyle = getStatusStyle($valK, 15, 50);
$tStyle = getStatusStyle($valTemp, 20, 32);
?>

<div class="soil-data-container" style="padding: 10px 5px;">
    <!-- Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
            <h3 style="color: var(--primary-color); font-weight: 700; font-size: 1.5rem; margin-bottom: 6px;">My Soil Telemetry & Analysis</h3>
            <p style="color: #657765; font-size: 0.95rem; margin: 0;">Logged field readings evaluated against optimal agricultural thresholds.</p>
        </div>
        <div style="background: #e8f5e9; color: #2e7d32; padding: 8px 16px; border-radius: 20px; font-weight: 600; font-size: 13px;" id="soil-live-badge">
            ● 30-Min Interval Logging Active
        </div>
    </div>

    <!-- Live Telemetry Metric Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 18px; margin-bottom: 25px;">
        
        <!-- Moisture Card -->
        <div style="background: #ffffff; padding: 20px; border-radius: 14px; border-left: 5px solid <?= $mStyle['border'] ?>; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border-top: 1px solid #edf2ed; border-right: 1px solid #edf2ed; border-bottom: 1px solid #edf2ed;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.8rem; text-transform: uppercase; color: #657765; font-weight: 700;">Soil Moisture</span>
                <span style="font-size: 0.75rem; font-weight: 600; padding: 2px 8px; border-radius: 6px; background: <?= $mStyle['color'] ?>15; color: <?= $mStyle['color'] ?>;"><?= $mStyle['status'] ?></span>
            </div>
            <h2 style="margin: 5px 0; color: <?= $mStyle['color'] ?>; font-size: 1.8rem;" id="soil-val-moisture">
                <?= $valMoisture !== null ? htmlspecialchars(number_format(floatval($valMoisture), 1)) . '%' : '--' ?>
            </h2>
            <span style="font-size: 0.8rem; color: #888;">Target Range: 30% - 60%</span>
        </div>

        <!-- pH Card -->
        <div style="background: #ffffff; padding: 20px; border-radius: 14px; border-left: 5px solid <?= $phStyle['border'] ?>; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border-top: 1px solid #edf2ed; border-right: 1px solid #edf2ed; border-bottom: 1px solid #edf2ed;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.8rem; text-transform: uppercase; color: #657765; font-weight: 700;">pH Level</span>
                <span style="font-size: 0.75rem; font-weight: 600; padding: 2px 8px; border-radius: 6px; background: <?= $phStyle['color'] ?>15; color: <?= $phStyle['color'] ?>;"><?= $phStyle['status'] ?></span>
            </div>
            <h2 style="margin: 5px 0; color: <?= $phStyle['color'] ?>; font-size: 1.8rem;" id="soil-val-ph">
                <?= $valPh !== null ? htmlspecialchars(number_format(floatval($valPh), 1)) : '--' ?>
            </h2>
            <span style="font-size: 0.8rem; color: #888;">Target Range: 5.0 - 7.5</span>
        </div>

        <!-- Nitrogen Card -->
        <div style="background: #ffffff; padding: 20px; border-radius: 14px; border-left: 5px solid <?= $nStyle['border'] ?>; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border-top: 1px solid #edf2ed; border-right: 1px solid #edf2ed; border-bottom: 1px solid #edf2ed;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.8rem; text-transform: uppercase; color: #657765; font-weight: 700;">Nitrogen (N)</span>
                <span style="font-size: 0.75rem; font-weight: 600; padding: 2px 8px; border-radius: 6px; background: <?= $nStyle['color'] ?>15; color: <?= $nStyle['color'] ?>;"><?= $nStyle['status'] ?></span>
            </div>
            <h2 style="margin: 5px 0; color: <?= $nStyle['color'] ?>; font-size: 1.8rem;" id="soil-val-n">
                <?= $valN !== null ? htmlspecialchars($valN) : '--' ?> <span style="font-size: 0.9rem; font-weight: normal;">mg/kg</span>
            </h2>
            <span style="font-size: 0.8rem; color: #888;">Target Range: 20 - 50</span>
        </div>

        <!-- Phosphorus Card -->
        <div style="background: #ffffff; padding: 20px; border-radius: 14px; border-left: 5px solid <?= $pStyle['border'] ?>; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border-top: 1px solid #edf2ed; border-right: 1px solid #edf2ed; border-bottom: 1px solid #edf2ed;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.8rem; text-transform: uppercase; color: #657765; font-weight: 700;">Phosphorus (P)</span>
                <span style="font-size: 0.75rem; font-weight: 600; padding: 2px 8px; border-radius: 6px; background: <?= $pStyle['color'] ?>15; color: <?= $pStyle['color'] ?>;"><?= $pStyle['status'] ?></span>
            </div>
            <h2 style="margin: 5px 0; color: <?= $pStyle['color'] ?>; font-size: 1.8rem;" id="soil-val-p">
                <?= $valP !== null ? htmlspecialchars($valP) : '--' ?> <span style="font-size: 0.9rem; font-weight: normal;">mg/kg</span>
            </h2>
            <span style="font-size: 0.8rem; color: #888;">Target Range: 10 - 30</span>
        </div>

        <!-- Potassium Card -->
        <div style="background: #ffffff; padding: 20px; border-radius: 14px; border-left: 5px solid <?= $kStyle['border'] ?>; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border-top: 1px solid #edf2ed; border-right: 1px solid #edf2ed; border-bottom: 1px solid #edf2ed;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.8rem; text-transform: uppercase; color: #657765; font-weight: 700;">Potassium (K)</span>
                <span style="font-size: 0.75rem; font-weight: 600; padding: 2px 8px; border-radius: 6px; background: <?= $kStyle['color'] ?>15; color: <?= $kStyle['color'] ?>;"><?= $kStyle['status'] ?></span>
            </div>
            <h2 style="margin: 5px 0; color: <?= $kStyle['color'] ?>; font-size: 1.8rem;" id="soil-val-k">
                <?= $valK !== null ? htmlspecialchars($valK) : '--' ?> <span style="font-size: 0.9rem; font-weight: normal;">mg/kg</span>
            </h2>
            <span style="font-size: 0.8rem; color: #888;">Target Range: 15 - 50</span>
        </div>

        <!-- Temperature Card -->
        <div style="background: #ffffff; padding: 20px; border-radius: 14px; border-left: 5px solid <?= $tStyle['border'] ?>; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border-top: 1px solid #edf2ed; border-right: 1px solid #edf2ed; border-bottom: 1px solid #edf2ed;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.8rem; text-transform: uppercase; color: #657765; font-weight: 700;">Temperature</span>
                <span style="font-size: 0.75rem; font-weight: 600; padding: 2px 8px; border-radius: 6px; background: <?= $tStyle['color'] ?>15; color: <?= $tStyle['color'] ?>;"><?= $tStyle['status'] ?></span>
            </div>
            <h2 style="margin: 5px 0; color: <?= $tStyle['color'] ?>; font-size: 1.8rem;" id="soil-val-temp">
                <?= $valTemp !== null ? htmlspecialchars(number_format(floatval($valTemp), 1)) . '°C' : '--' ?>
            </h2>
            <span style="font-size: 0.8rem; color: #888;">Target Range: 20°C - 32°C</span>
        </div>
    </div>

    <!-- Parameter Criteria Reference Card -->
    <div style="background: #ffffff; padding: 25px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); margin-bottom: 25px; border: 1px solid #d9e2d9;">
        <h4 style="margin-top: 0; color: #2c3e2c; font-size: 1.1rem; font-weight: 600;">Soil Parameter Criteria Reference</h4>
        <p style="color: #657765; font-size: 0.9rem; margin-bottom: 20px;">Color legend definition: <span style="color: #dc3545; font-weight: 600;">Red (Critical/Low)</span>, <span style="color: #198754; font-weight: 600;">Green (Optimal)</span>, and <span style="color: #ff9800; font-weight: 600;">Amber/Orange (Excess/High)</span>.</p>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; font-size: 0.9rem; line-height: 1.6;">
            <div>
                <strong style="color: #2c3e2c;">Soil Moisture</strong>
                <ul style="padding-left: 18px; margin: 5px 0 0; color: #556b55;">
                    <li><strong style="color: #dc3545;">&lt; 30%:</strong> Dry (Irrigate)</li>
                    <li><strong style="color: #198754;">30% – 60%:</strong> Optimal</li>
                    <li><strong style="color: #ff9800;">&gt; 60%:</strong> Wet (Excess Water)</li>
                </ul>
            </div>

            <div>
                <strong style="color: #2c3e2c;">Soil pH Level</strong>
                <ul style="padding-left: 18px; margin: 5px 0 0; color: #556b55;">
                    <li><strong style="color: #dc3545;">&lt; 5.0:</strong> Acidic (Needs Lime)</li>
                    <li><strong style="color: #198754;">5.0 – 7.5:</strong> Optimal</li>
                    <li><strong style="color: #ff9800;">&gt; 7.5:</strong> Alkaline (Excess)</li>
                </ul>
            </div>

            <div>
                <strong style="color: #2c3e2c;">Temperature</strong>
                <ul style="padding-left: 18px; margin: 5px 0 0; color: #556b55;">
                    <li><strong style="color: #dc3545;">&lt; 20°C:</strong> Cool</li>
                    <li><strong style="color: #198754;">20°C – 32°C:</strong> Optimal</li>
                    <li><strong style="color: #ff9800;">&gt; 32°C:</strong> High Heat Stress</li>
                </ul>
            </div>

            <div>
                <strong style="color: #2c3e2c;">Nutrients (N-P-K)</strong>
                <div style="margin-top: 5px; color: #556b55; font-size: 0.85rem;">
                    <span style="color: #dc3545;">Low</span> | <span style="color: #198754;">Optimal</span> | <span style="color: #ff9800;">High (Excess)</span><br>
                    <strong>N:</strong> &lt;20 | 20–50 | &gt;50 mg/kg<br>
                    <strong>P:</strong> &lt;10 | 10–30 | &gt;30 mg/kg<br>
                    <strong>K:</strong> &lt;15 | 15–50 | &gt;50 mg/kg
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Telemetry History Table with Pagination -->
    <div style="background: #ffffff; padding: 25px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); border: 1px solid #d9e2d9;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <div>
                <h4 style="margin: 0; color: #2c3e2c; font-size: 1.1rem; font-weight: 600;">📋 Recent Telemetry History Logs</h4>
                <p style="color: #657765; font-size: 0.9rem; margin: 4px 0 0;">Analysis records saved automatically every 30 minutes.</p>
            </div>
            <div style="font-size: 0.85rem; color: #657765; font-weight: 500;">
                Showing page <?= $page ?> of <?= max(1, $totalPages) ?> (Total: <?= $totalRows ?> records)
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem;">
                <thead>
                    <tr style="background: #f4f7f4; border-bottom: 2px solid #e2e8e2; color: #2c3e2c;">
                        <th style="padding: 12px; border-top-left-radius: 8px; border-bottom-left-radius: 8px;">Timestamp</th>
                        <th style="padding: 12px;">Moisture</th>
                        <th style="padding: 12px;">pH</th>
                        <th style="padding: 12px;">Nitrogen</th>
                        <th style="padding: 12px;">Phosphorus</th>
                        <th style="padding: 12px;">Potassium</th>
                        <th style="padding: 12px; border-top-right-radius: 8px; border-bottom-right-radius: 8px;">Temperature</th>
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

                                $rowMStyle  = getStatusStyle($lMoisture, 30, 60);
                                $rowPhStyle = getStatusStyle($lPh, 5.0, 7.5);
                            ?>
                            <tr style="border-bottom: 1px solid #edf2ed;">
                                <td style="padding: 12px; color: #556b55;"><?= isset($log['created_at']) ? date("M j, Y - g:i A", strtotime($log['created_at'])) : 'N/A' ?></td>
                                <td style="padding: 12px; font-weight: 600; color: <?= $rowMStyle['color'] ?>;"><?= number_format(floatval($lMoisture), 1) ?>%</td>
                                <td style="padding: 12px; font-weight: 600; color: <?= $rowPhStyle['color'] ?>;"><?= number_format(floatval($lPh), 1) ?></td>
                                <td style="padding: 12px; color: #2c3e2c;"><?= htmlspecialchars($lN) ?> mg/kg</td>
                                <td style="padding: 12px; color: #2c3e2c;"><?= htmlspecialchars($lP) ?> mg/kg</td>
                                <td style="padding: 12px; color: #2c3e2c;"><?= htmlspecialchars($lK) ?> mg/kg</td>
                                <td style="padding: 12px; color: #2c3e2c;"><?= number_format(floatval($lTemp), 1) ?>°C</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="padding: 25px; text-align: center; color: #657765;">No sensor logs recorded yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?php if ($totalPages > 1): ?>
            <div style="display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 20px;">
                <!-- Previous Button -->
                <?php if ($page > 1): ?>
                    <a href="?history_page=<?= $page - 1 ?>" style="padding: 6px 14px; background: #f4f7f4; color: #2c3e2c; border: 1px solid #d9e2d9; border-radius: 6px; text-decoration: none; font-size: 0.85rem; font-weight: 600;">Previous</a>
                <?php else: ?>
                    <span style="padding: 6px 14px; background: #f9fbf9; color: #b5c2b5; border: 1px solid #e5ebe5; border-radius: 6px; font-size: 0.85rem; cursor: not-allowed;">Previous</span>
                <?php endif; ?>

                <!-- Page Indicator Numbers -->
                <div style="display: flex; gap: 4px;">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?history_page=<?= $i ?>" style="padding: 6px 10px; border-radius: 6px; text-decoration: none; font-size: 0.85rem; font-weight: 600; background: <?= ($i == $page) ? '#2e7d32' : '#f4f7f4' ?>; color: <?= ($i == $page) ? '#ffffff' : '#2c3e2c' ?>; border: 1px solid <?= ($i == $page) ? '#2e7d32' : '#d9e2d9' ?>;"><?= $i ?></a>
                    <?php endfor; ?>
                </div>

                <!-- Next Button -->
                <?php if ($page < $totalPages): ?>
                    <a href="?history_page=<?= $page + 1 ?>" style="padding: 6px 14px; background: #f4f7f4; color: #2c3e2c; border: 1px solid #d9e2d9; border-radius: 6px; text-decoration: none; font-size: 0.85rem; font-weight: 600;">Next</a>
                <?php else: ?>
                    <span style="padding: 6px 14px; background: #f9fbf9; color: #b5c2b5; border: 1px solid #e5ebe5; border-radius: 6px; font-size: 0.85rem; cursor: not-allowed;">Next</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>