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
@keyframes livePulse {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(46, 125, 50, 0.7); }
    70% { transform: scale(1.1); box-shadow: 0 0 0 6px rgba(46, 125, 50, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(46, 125, 50, 0); }
}
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
        <div style="background: #e8f5e9; color: #1b5e20; padding: 6px 14px; border-radius: 20px; font-weight: 700; font-size: 12px; white-space: nowrap; display: flex; align-items: center; gap: 8px; border: 1px solid #c8e6c9;" id="soil-live-badge">
            <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background-color: #2e7d32; box-shadow: 0 0 0 0 rgba(46, 125, 50, 0.7); animation: livePulse 1.8s infinite;"></span>
            LIVE REAL-TIME STREAM
        </div>
    </div>

    <!-- Live Telemetry Metric Cards -->
    <div class="soil-metrics-grid">
        <!-- Moisture Card -->
        <div class="soil-metric-card" id="soil-card-moisture" style="border-left: 5px solid <?= $mStyle['border'] ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.75rem; text-transform: uppercase; color: #657765; font-weight: 700;">Soil Moisture</span>
                <span id="soil-status-moisture" style="font-size: 0.7rem; font-weight: 600; padding: 2px 6px; border-radius: 6px; background: <?= $mStyle['color'] ?>15; color: <?= $mStyle['color'] ?>;"><?= $mStyle['status'] ?></span>
            </div>
            <h2 style="margin: 5px 0; color: <?= $mStyle['color'] ?>; font-size: 1.6rem;" id="soil-val-moisture">
                <?= $valMoisture !== null ? htmlspecialchars(number_format(floatval($valMoisture), 1)) . '%' : '--' ?>
            </h2>
            <span style="font-size: 0.75rem; color: #888;">Target: 30% - 60%</span>
        </div>

        <!-- pH Card -->
        <div class="soil-metric-card" id="soil-card-ph" style="border-left: 5px solid <?= $phStyle['border'] ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.75rem; text-transform: uppercase; color: #657765; font-weight: 700;">pH Level</span>
                <span id="soil-status-ph" style="font-size: 0.7rem; font-weight: 600; padding: 2px 6px; border-radius: 6px; background: <?= $phStyle['color'] ?>15; color: <?= $phStyle['color'] ?>;"><?= $phStyle['status'] ?></span>
            </div>
            <h2 style="margin: 5px 0; color: <?= $phStyle['color'] ?>; font-size: 1.6rem;" id="soil-val-ph">
                <?= $valPh !== null ? htmlspecialchars(number_format(floatval($valPh), 1)) : '--' ?>
            </h2>
            <span style="font-size: 0.75rem; color: #888;">Target: 5.0 - 7.5</span>
        </div>

        <!-- Nitrogen Card -->
        <div class="soil-metric-card" id="soil-card-n" style="border-left: 5px solid <?= ($valN == 0 && $valP == 0 && $valK == 0) ? '#6c757d' : $nStyle['border'] ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.75rem; text-transform: uppercase; color: #657765; font-weight: 700;">Nitrogen (N)</span>
                <span id="soil-status-n" style="font-size: 0.7rem; font-weight: 600; padding: 2px 6px; border-radius: 6px; background: <?= ($valN == 0 && $valP == 0 && $valK == 0) ? '#6c757d15' : $nStyle['color'].'15' ?>; color: <?= ($valN == 0 && $valP == 0 && $valK == 0) ? '#6c757d' : $nStyle['color'] ?>;">
                    <?= ($valN == 0 && $valP == 0 && $valK == 0) ? 'No Response' : $nStyle['status'] ?>
                </span>
            </div>
            <h2 style="margin: 5px 0; color: <?= ($valN == 0 && $valP == 0 && $valK == 0) ? '#6c757d' : $nStyle['color'] ?>; font-size: 1.6rem;" id="soil-val-n">
                <?= $valN !== null ? htmlspecialchars($valN) : '--' ?> <span style="font-size: 0.8rem; font-weight: normal;">mg/kg</span>
            </h2>
            <span style="font-size: 0.75rem; color: #888;">Target: 20 - 50</span>
        </div>

        <!-- Phosphorus Card -->
        <div class="soil-metric-card" id="soil-card-p" style="border-left: 5px solid <?= ($valN == 0 && $valP == 0 && $valK == 0) ? '#6c757d' : $pStyle['border'] ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.75rem; text-transform: uppercase; color: #657765; font-weight: 700;">Phosphorus (P)</span>
                <span id="soil-status-p" style="font-size: 0.7rem; font-weight: 600; padding: 2px 6px; border-radius: 6px; background: <?= ($valN == 0 && $valP == 0 && $valK == 0) ? '#6c757d15' : $pStyle['color'].'15' ?>; color: <?= ($valN == 0 && $valP == 0 && $valK == 0) ? '#6c757d' : $pStyle['color'] ?>;">
                    <?= ($valN == 0 && $valP == 0 && $valK == 0) ? 'No Response' : $pStyle['status'] ?>
                </span>
            </div>
            <h2 style="margin: 5px 0; color: <?= ($valN == 0 && $valP == 0 && $valK == 0) ? '#6c757d' : $pStyle['color'] ?>; font-size: 1.6rem;" id="soil-val-p">
                <?= $valP !== null ? htmlspecialchars($valP) : '--' ?> <span style="font-size: 0.8rem; font-weight: normal;">mg/kg</span>
            </h2>
            <span style="font-size: 0.75rem; color: #888;">Target: 10 - 30</span>
        </div>

        <!-- Potassium Card -->
        <div class="soil-metric-card" id="soil-card-k" style="border-left: 5px solid <?= ($valN == 0 && $valP == 0 && $valK == 0) ? '#6c757d' : $kStyle['border'] ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.75rem; text-transform: uppercase; color: #657765; font-weight: 700;">Potassium (K)</span>
                <span id="soil-status-k" style="font-size: 0.7rem; font-weight: 600; padding: 2px 6px; border-radius: 6px; background: <?= ($valN == 0 && $valP == 0 && $valK == 0) ? '#6c757d15' : $kStyle['color'].'15' ?>; color: <?= ($valN == 0 && $valP == 0 && $valK == 0) ? '#6c757d' : $kStyle['color'] ?>;">
                    <?= ($valN == 0 && $valP == 0 && $valK == 0) ? 'No Response' : $kStyle['status'] ?>
                </span>
            </div>
            <h2 style="margin: 5px 0; color: <?= ($valN == 0 && $valP == 0 && $valK == 0) ? '#6c757d' : $kStyle['color'] ?>; font-size: 1.6rem;" id="soil-val-k">
                <?= $valK !== null ? htmlspecialchars($valK) : '--' ?> <span style="font-size: 0.8rem; font-weight: normal;">mg/kg</span>
            </h2>
            <span style="font-size: 0.75rem; color: #888;">Target: 15 - 50</span>
        </div>

        <!-- Temperature Card -->
        <div class="soil-metric-card" id="soil-card-temp" style="border-left: 5px solid <?= $tStyle['border'] ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.75rem; text-transform: uppercase; color: #657765; font-weight: 700;">Temperature</span>
                <span id="soil-status-temp" style="font-size: 0.7rem; font-weight: 600; padding: 2px 6px; border-radius: 6px; background: <?= $tStyle['color'] ?>15; color: <?= $tStyle['color'] ?>;"><?= $tStyle['status'] ?></span>
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
            <div style="font-size: 0.82rem; color: #657765; font-weight: 500;" id="soil-history-page-info">
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
                <tbody id="soil-history-tbody">
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

<!-- Real-Time Telemetry Live Polling & DOM Updater (No Page Refresh Required) -->
<script>
(function() {
    let lastReadingId = <?= $latest['id'] ?? 0 ?>;

    function getHealthStatus(val, min, max, unit = '') {
        if (val === null || isNaN(val)) {
            return { color: '#6c757d', border: '#6c757d', status: 'No Data' };
        }
        const num = parseFloat(val);
        if (num < min) {
            return { color: '#dc3545', border: '#dc3545', status: 'Critical (Low)' };
        } else if (num > max) {
            return { color: '#e65100', border: '#ff9800', status: 'Warning (High)' };
        } else {
            return { color: '#198754', border: '#198754', status: 'Optimal' };
        }
    }

    function updateCard(cardId, statusId, valId, valueText, style) {
        const card = document.getElementById(cardId);
        const statusEl = document.getElementById(statusId);
        const valEl = document.getElementById(valId);

        if (card) card.style.borderLeft = `5px solid ${style.border}`;
        if (statusEl) {
            statusEl.innerText = style.status;
            statusEl.style.color = style.color;
            statusEl.style.backgroundColor = style.color + '15';
        }
        if (valEl) {
            valEl.innerHTML = valueText;
            valEl.style.color = style.color;
        }
    }

    function fetchLiveTelemetry() {
        fetch('api/get_live_telemetry.php?_=' + Date.now())
            .then(res => res.json())
            .then(res => {
                if (res.status !== 'success' || !res.data) return;
                const d = res.data;

                // 1. Calculate Statuses
                const mStyle = getHealthStatus(d.moisture, 30, 60);
                const phStyle = getHealthStatus(d.ph, 5.0, 7.5);
                const tStyle = getHealthStatus(d.temperature, 20, 32);

                // 2. Update Moisture, pH, Temp
                updateCard('soil-card-moisture', 'soil-status-moisture', 'soil-val-moisture', 
                    d.moisture !== null ? (parseFloat(d.moisture).toFixed(1) + '%') : '--', mStyle);

                updateCard('soil-card-ph', 'soil-status-ph', 'soil-val-ph', 
                    d.ph !== null ? parseFloat(d.ph).toFixed(1) : '--', phStyle);

                updateCard('soil-card-temp', 'soil-status-temp', 'soil-val-temp', 
                    d.temperature !== null ? (parseFloat(d.temperature).toFixed(1) + '°C') : '--', tStyle);

                // 3. Update NPK (Handle Pure Hardware vs No Response)
                if (d.npk_online) {
                    const nStyle = getHealthStatus(d.nitrogen, 20, 50);
                    const pStyle = getHealthStatus(d.phosphorus, 10, 30);
                    const kStyle = getHealthStatus(d.potassium, 15, 50);

                    updateCard('soil-card-n', 'soil-status-n', 'soil-val-n', 
                        `${d.nitrogen} <span style="font-size: 0.8rem; font-weight: normal;">mg/kg</span>`, nStyle);
                    updateCard('soil-card-p', 'soil-status-p', 'soil-val-p', 
                        `${d.phosphorus} <span style="font-size: 0.8rem; font-weight: normal;">mg/kg</span>`, pStyle);
                    updateCard('soil-card-k', 'soil-status-k', 'soil-val-k', 
                        `${d.potassium} <span style="font-size: 0.8rem; font-weight: normal;">mg/kg</span>`, kStyle);
                } else {
                    const offStyle = { color: '#6c757d', border: '#6c757d', status: 'No Response' };
                    updateCard('soil-card-n', 'soil-status-n', 'soil-val-n', 
                        `0 <span style="font-size: 0.8rem; font-weight: normal;">mg/kg</span>`, offStyle);
                    updateCard('soil-card-p', 'soil-status-p', 'soil-val-p', 
                        `0 <span style="font-size: 0.8rem; font-weight: normal;">mg/kg</span>`, offStyle);
                    updateCard('soil-card-k', 'soil-status-k', 'soil-val-k', 
                        `0 <span style="font-size: 0.8rem; font-weight: normal;">mg/kg</span>`, offStyle);
                }

                // 4. Real-time sync for Recent Telemetry History table if a new reading arrived
                if (d.id && d.id !== lastReadingId) {
                    lastReadingId = d.id;

                    const tbody = document.getElementById('soil-history-tbody') || document.querySelector('.soil-table tbody');
                    const pageInfoEl = document.getElementById('soil-history-page-info');
                    const isPageOne = (!window.location.search.includes('history_page') || window.location.search.includes('history_page=1'));

                    // Update total count indicator
                    if (res.total_count && pageInfoEl) {
                        const totalPages = Math.max(1, Math.ceil(res.total_count / 15));
                        const curPage = <?= $page ?>;
                        pageInfoEl.innerText = `Page ${curPage} of ${totalPages} (Total: ${res.total_count})`;
                    }

                    // On Page 1: dynamically re-render the 15 records in the table smoothly with highlight
                    if (isPageOne && tbody && res.recent_logs && res.recent_logs.length > 0) {
                        let html = '';
                        res.recent_logs.forEach((log, index) => {
                            const rMStyle = getHealthStatus(log.moisture, 30, 60);
                            const rPhStyle = getHealthStatus(log.ph, 5.0, 7.5);
                            const isNewTop = (index === 0);
                            html += `
                                <tr style="${isNewTop ? 'background-color: #e8f5e9; transition: background-color 2.5s ease;' : ''}">
                                    <td style="color: #556b55;">${log.formatted_time}</td>
                                    <td style="font-weight: 600; color: ${rMStyle.color};">${parseFloat(log.moisture).toFixed(1)}%</td>
                                    <td style="font-weight: 600; color: ${rPhStyle.color};">${parseFloat(log.ph).toFixed(1)}</td>
                                    <td style="color: #2c3e2c;">${log.nitrogen} mg/kg</td>
                                    <td style="color: #2c3e2c;">${log.phosphorus} mg/kg</td>
                                    <td style="color: #2c3e2c;">${log.potassium} mg/kg</td>
                                    <td style="color: #2c3e2c;">${parseFloat(log.temperature).toFixed(1)}°C</td>
                                </tr>
                            `;
                        });
                        tbody.innerHTML = html;

                        // Fade top row back to normal after 2.5 seconds
                        const firstRow = tbody.firstElementChild;
                        if (firstRow) {
                            setTimeout(() => { firstRow.style.backgroundColor = ''; }, 2500);
                        }
                    }
                }
            })
            .catch(err => console.debug('Live telemetry fetch check:', err));
    }

    // Run immediately and stream every 2 seconds
    fetchLiveTelemetry();
    setInterval(fetchLiveTelemetry, 2000);
})();
</script>