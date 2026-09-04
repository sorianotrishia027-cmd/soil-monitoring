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
    $totalPages = max(1, ceil($totalRows / $limit));
} catch (PDOException $e) {
    $totalRows = 0;
    $totalPages = 1;
}

// Ensure page doesn't exceed totalPages
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

// 3. Fetch History Logs for Current Page with LIMIT and OFFSET
try {
    $stmtLogs = $conn->prepare("SELECT * FROM soil_readings ORDER BY id DESC LIMIT :limit OFFSET :offset");
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
        return ['color' => '#dc3545', 'border' => '#dc3545', 'status' => 'Critical (Low)'];
    } elseif ($fVal > $max) {
        return ['color' => '#e65100', 'border' => '#ff9800', 'status' => 'Warning (High)'];
    } else {
        return ['color' => '#198754', 'border' => '#198754', 'status' => 'Optimal'];
    }
}

$mStyle = getStatusStyle($valMoisture, 30, 60);
$phStyle = getStatusStyle($valPh, 5.0, 7.5);
$nStyle = getStatusStyle($valN, 20, 50);
$pStyle = getStatusStyle($valP, 10, 30);
$kStyle = getStatusStyle($valK, 15, 50);
$tStyle = getStatusStyle($valTemp, 20, 32);
?>

<style>
/* Responsive layout styles & Google-style pagination */
.soil-data-wrapper {
    padding: 15px 10px;
    font-family: inherit;
    box-sizing: border-box;
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
}

.soil-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 20px;
}

.soil-metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 16px;
    margin-bottom: 25px;
}

.soil-metric-card {
    background: #ffffff;
    padding: 20px;
    border-radius: 14px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    border: 1px solid #edf2ed;
    border-top: 1px solid #edf2ed;
    border-right: 1px solid #edf2ed;
    border-bottom: 1px solid #edf2ed;
}

.criteria-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    font-size: 0.9rem;
    line-height: 1.6;
}

.table-card {
    background: #ffffff;
    padding: 20px;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.03);
    border: 1px solid #d9e2d9;
    width: 100%;
    box-sizing: border-box;
}

.table-responsive {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    margin-bottom: 15px;
}

.soil-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
    font-size: 0.9rem;
    white-space: nowrap;
}

.soil-table th {
    background: #f4f7f4;
    border-bottom: 2px solid #e2e8e2;
    color: #2c3e2c;
    padding: 12px 14px;
    font-weight: 600;
}

.soil-table td {
    padding: 12px 14px;
    border-bottom: 1px solid #edf2ed;
}

/* Google-style Pagination Styling */
.google-pagination-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    margin-top: 25px;
    padding-top: 15px;
    border-top: 1px solid #edf2ed;
}

.google-pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
    gap: 4px;
}

.gp-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 10px;
    background: #ffffff;
    color: #202124;
    border: 1px solid #dadce0;
    border-radius: 4px;
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 500;
    transition: background 0.2s, border-color 0.2s;
    box-sizing: border-box;
}

.gp-btn:hover {
    background: #f8f9fa;
    border-color: #bdc1c6;
}

.gp-btn.active {
    background: #e8f0fe;
    color: #1a73e8;
    border-color: #1a73e8;
    font-weight: 600;
}

.gp-btn.disabled {
    color: #bdc1c6;
    background: #f8f9fa;
    border-color: #f1f3f4;
    cursor: not-allowed;
    pointer-events: none;
}

.gp-ellipsis {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    height: 36px;
    color: #5f6368;
    font-size: 0.9rem;
}

@media(max-width: 768px) {
    .soil-header {
        flex-direction: column;
        align-items: stretch;
    }
    .table-card {
        padding: 12px;
    }
}
</style>

<div class="soil-data-wrapper">
    <!-- Header -->
    <div class="soil-header">
        <div>
            <h3 style="color: var(--primary-color, #2e7d32); font-weight: 700; font-size: 1.4rem; margin-bottom: 4px;">My Soil Telemetry & Analysis</h3>
            <p style="color: #657765; font-size: 0.9rem; margin: 0;">Logged field readings evaluated against optimal agricultural thresholds.</p>
        </div>
        <div style="background: #e8f5e9; color: #2e7d32; padding: 6px 14px; border-radius: 20px; font-weight: 600; font-size: 12px; white-space: nowrap;" id="soil-live-badge">
            ● 30-Min Interval Logging Active
        </div>
    </div>

    <!-- Live Telemetry Metric Cards -->
    <div class="soil-metrics-grid">
        <!-- Moisture Card -->
        <div class="soil-metric-card" style="border-left: 5px solid <?= $mStyle['border'] ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.75rem; text-transform: uppercase; color: #657765; font-weight: 700;">Soil Moisture</span>
                <span style="font-size: 0.7rem; font-weight: 600; padding: 2px 6px; border-radius: 6px; background: <?= $mStyle['color'] ?>15; color: <?= $mStyle['color'] ?>;"><?= $mStyle['status'] ?></span>
            </div>
            <h2 style="margin: 5px 0; color: <?= $mStyle['color'] ?>; font-size: 1.6rem;" id="soil-val-moisture">
                <?= $valMoisture !== null ? htmlspecialchars(number_format(floatval($valMoisture), 1)) . '%' : '--' ?>
            </h2>
            <span style="font-size: 0.75rem; color: #888;">Target: 30% - 60%</span>
        </div>

        <!-- pH Card -->
        <div class="soil-metric-card" style="border-left: 5px solid <?= $phStyle['border'] ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.75rem; text-transform: uppercase; color: #657765; font-weight: 700;">pH Level</span>
                <span style="font-size: 0.7rem; font-weight: 600; padding: 2px 6px; border-radius: 6px; background: <?= $phStyle['color'] ?>15; color: <?= $phStyle['color'] ?>;"><?= $phStyle['status'] ?></span>
            </div>
            <h2 style="margin: 5px 0; color: <?= $phStyle['color'] ?>; font-size: 1.6rem;" id="soil-val-ph">
                <?= $valPh !== null ? htmlspecialchars(number_format(floatval($valPh), 1)) : '--' ?>
            </h2>
            <span style="font-size: 0.75rem; color: #888;">Target: 5.0 - 7.5</span>
        </div>

        <!-- Nitrogen Card -->
        <div class="soil-metric-card" style="border-left: 5px solid <?= $nStyle['border'] ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.75rem; text-transform: uppercase; color: #657765; font-weight: 700;">Nitrogen (N)</span>
                <span style="font-size: 0.7rem; font-weight: 600; padding: 2px 6px; border-radius: 6px; background: <?= $nStyle['color'] ?>15; color: <?= $nStyle['color'] ?>;"><?= $nStyle['status'] ?></span>
            </div>
            <h2 style="margin: 5px 0; color: <?= $nStyle['color'] ?>; font-size: 1.6rem;" id="soil-val-n">
                <?= $valN !== null ? htmlspecialchars($valN) : '--' ?> <span style="font-size: 0.8rem; font-weight: normal;">mg/kg</span>
            </h2>
            <span style="font-size: 0.75rem; color: #888;">Target: 20 - 50</span>
        </div>

        <!-- Phosphorus Card -->
        <div class="soil-metric-card" style="border-left: 5px solid <?= $pStyle['border'] ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.75rem; text-transform: uppercase; color: #657765; font-weight: 700;">Phosphorus (P)</span>
                <span style="font-size: 0.7rem; font-weight: 600; padding: 2px 6px; border-radius: 6px; background: <?= $pStyle['color'] ?>15; color: <?= $pStyle['color'] ?>;"><?= $pStyle['status'] ?></span>
            </div>
            <h2 style="margin: 5px 0; color: <?= $pStyle['color'] ?>; font-size: 1.6rem;" id="soil-val-p">
                <?= $valP !== null ? htmlspecialchars($valP) : '--' ?> <span style="font-size: 0.8rem; font-weight: normal;">mg/kg</span>
            </h2>
            <span style="font-size: 0.75rem; color: #888;">Target: 10 - 30</span>
        </div>

        <!-- Potassium Card -->
        <div class="soil-metric-card" style="border-left: 5px solid <?= $kStyle['border'] ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.75rem; text-transform: uppercase; color: #657765; font-weight: 700;">Potassium (K)</span>
                <span style="font-size: 0.7rem; font-weight: 600; padding: 2px 6px; border-radius: 6px; background: <?= $kStyle['color'] ?>15; color: <?= $kStyle['color'] ?>;"><?= $kStyle['status'] ?></span>
            </div>
            <h2 style="margin: 5px 0; color: <?= $kStyle['color'] ?>; font-size: 1.6rem;" id="soil-val-k">
                <?= $valK !== null ? htmlspecialchars($valK) : '--' ?> <span style="font-size: 0.8rem; font-weight: normal;">mg/kg</span>
            </h2>
            <span style="font-size: 0.75rem; color: #888;">Target: 15 - 50</span>
        </div>

        <!-- Temperature Card -->
        <div class="soil-metric-card" style="border-left: 5px solid <?= $tStyle['border'] ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.75rem; text-transform: uppercase; color: #657765; font-weight: 700;">Temperature</span>
                <span style="font-size: 0.7rem; font-weight: 600; padding: 2px 6px; border-radius: 6px; background: <?= $tStyle['color'] ?>15; color: <?= $tStyle['color'] ?>;"><?= $tStyle['status'] ?></span>
            </div>
            <h2 style="margin: 5px 0; color: <?= $tStyle['color'] ?>; font-size: 1.6rem;" id="soil-val-temp">
                <?= $valTemp !== null ? htmlspecialchars(number_format(floatval($valTemp), 1)) . '°C' : '--' ?>
            </h2>
            <span style="font-size: 0.75rem; color: #888;">Target: 20°C - 32°C</span>
        </div>
    </div>

    <!-- Parameter Criteria Reference Card -->
    <div style="background: #ffffff; padding: 20px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); margin-bottom: 25px; border: 1px solid #d9e2d9;">
        <h4 style="margin-top: 0; color: #2c3e2c; font-size: 1.05rem; font-weight: 600;">Soil Parameter Criteria Reference</h4>
        <p style="color: #657765; font-size: 0.85rem; margin-bottom: 15px;">Color legend: <span style="color: #dc3545; font-weight: 600;">Red (Critical/Low)</span>, <span style="color: #198754; font-weight: 600;">Green (Optimal)</span>, <span style="color: #ff9800; font-weight: 600;">Orange (High/Excess)</span>.</p>

        <div class="criteria-grid">
            <div>
                <strong style="color: #2c3e2c;">Soil Moisture</strong>
                <ul style="padding-left: 16px; margin: 4px 0 0; color: #556b55;">
                    <li><strong style="color: #dc3545;">&lt; 30%:</strong> Dry</li>
                    <li><strong style="color: #198754;">30% – 60%:</strong> Optimal</li>
                    <li><strong style="color: #ff9800;">&gt; 60%:</strong> Wet</li>
                </ul>
            </div>
            <div>
                <strong style="color: #2c3e2c;">Soil pH Level</strong>
                <ul style="padding-left: 16px; margin: 4px 0 0; color: #556b55;">
                    <li><strong style="color: #dc3545;">&lt; 5.0:</strong> Acidic</li>
                    <li><strong style="color: #198754;">5.0 – 7.5:</strong> Optimal</li>
                    <li><strong style="color: #ff9800;">&gt; 7.5:</strong> Alkaline</li>
                </ul>
            </div>
            <div>
                <strong style="color: #2c3e2c;">Temperature</strong>
                <ul style="padding-left: 16px; margin: 4px 0 0; color: #556b55;">
                    <li><strong style="color: #dc3545;">&lt; 20°C:</strong> Cool</li>
                    <li><strong style="color: #198754;">20°C – 32°C:</strong> Optimal</li>
                    <li><strong style="color: #ff9800;">&gt; 32°C:</strong> Heat Stress</li>
                </ul>
            </div>
            <div>
                <strong style="color: #2c3e2c;">Nutrients (N-P-K)</strong>
                <div style="margin-top: 4px; color: #556b55; font-size: 0.82rem;">
                    <strong>N:</strong> 20–50 mg/kg<br>
                    <strong>P:</strong> 10–30 mg/kg<br>
                    <strong>K:</strong> 15–50 mg/kg
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Telemetry History Table with Google-style Pagination -->
    <div class="table-card">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; margin-bottom: 15px;">
            <div>
                <h4 style="margin: 0; color: #2c3e2c; font-size: 1.05rem; font-weight: 600;">📋 Recent Telemetry History Logs</h4>
                <p style="color: #657765; font-size: 0.85rem; margin: 2px 0 0;">Automatic records logged from field sensors.</p>
            </div>
            <div style="font-size: 0.82rem; color: #657765; font-weight: 500;">
                Page <?= $page ?> of <?= max(1, $totalPages) ?> (Total: <?= $totalRows ?>)
            </div>
        </div>

        <div class="table-responsive">
            <table class="soil-table">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Moisture</th>
                        <th>pH</th>
                        <th>Nitrogen</th>
                        <th>Phosphorus</th>
                        <th>Potassium</th>
                        <th>Temperature</th>
                    </tr>
                </thead>
                <tbody>
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
                            <tr>
                                <td style="color: #556b55;"><?= isset($log['created_at']) ? date("M j, Y - g:i A", strtotime($log['created_at'])) : 'N/A' ?></td>
                                <td style="font-weight: 600; color: <?= $rowMStyle['color'] ?>;"><?= number_format(floatval($lMoisture), 1) ?>%</td>
                                <td style="font-weight: 600; color: <?= $rowPhStyle['color'] ?>;"><?= number_format(floatval($lPh), 1) ?></td>
                                <td style="color: #2c3e2c;"><?= htmlspecialchars($lN) ?> mg/kg</td>
                                <td style="color: #2c3e2c;"><?= htmlspecialchars($lP) ?> mg/kg</td>
                                <td style="color: #2c3e2c;"><?= htmlspecialchars($lK) ?> mg/kg</td>
                                <td style="color: #2c3e2c;"><?= number_format(floatval($lTemp), 1) ?>°C</td>
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

        <!-- Google-style Truncated Pagination Controls -->
        <?php if ($totalPages > 1): 
            $params = $_GET;
            unset($params['history_page']);
            $queryBase = !empty($params) ? '?' . http_build_query($params) . '&' : '?';
        ?>
            <div class="google-pagination-container">
                <div class="google-pagination">
                    <!-- Previous Button -->
                    <a href="<?= $queryBase ?>history_page=<?= $page - 1 ?>" class="gp-btn <?= ($page <= 1) ? 'disabled' : '' ?>">Previous</a>

                    <?php
                    // Google-style Sliding Window Pagination Logic
                    $range = 2; // Number of pages visible before and after current page
                    $showFirstLast = true;

                    for ($i = 1; $i <= $totalPages; $i++):
                        if ($i == 1 || $i == $totalPages || ($i >= $page - $range && $i <= $page + $range)):
                            if (isset($adjacent) && $adjacent && $i > $adjacent + 1):
                                echo '<span class="gp-ellipsis">...</span>';
                            endif;
                            $activeClass = ($i == $page) ? 'active' : '';
                            echo '<a href="' . $queryBase . 'history_page=' . $i . '" class="gp-btn ' . $activeClass . '">' . $i . '</a>';
                            $adjacent = $i;
                        elseif ($i == $page - $range - 1 || $i == $page + $range + 1):
                            echo '<span class="gp-ellipsis">...</span>';
                        endif;
                    endfor;
                    ?>

                    <!-- Next Button -->
                    <a href="<?= $queryBase ?>history_page=<?= $page + 1 ?>" class="gp-btn <?= ($page >= $totalPages) ? 'disabled' : '' ?>">Next</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>