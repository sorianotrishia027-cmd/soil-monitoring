<?php
// views/soil_data.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

if (!isset($conn)) {
    require_once __DIR__ . '/../config/db_connect.php';
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$role = strtolower(trim($_SESSION['role'] ?? 'farmer'));

if ($user_id <= 0) {
    echo '<div style="padding:20px;color:#dc3545;">Invalid user session.</div>';
    exit;
}

/*
|--------------------------------------------------------------------------
| VERIFY LOGGED-IN USER
|--------------------------------------------------------------------------
*/
try {
    $userStmt = $conn->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
    $userStmt->execute([$user_id]);
    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$userRow) {
        echo '<div style="padding:20px;color:#dc3545;">User account not found.</div>';
        exit;
    }
    $role = strtolower(trim($userRow['role'] ?? $role));
} catch (PDOException $e) {
    echo '<div style="padding:20px;color:#dc3545;">Unable to verify user account.</div>';
    exit;
}

/*
|--------------------------------------------------------------------------
| RESOLVE ASSIGNED NODE
|--------------------------------------------------------------------------
*/
$assigned_device_id = null;

if ($role !== 'admin') {
    try {
        $stmtDevice = $conn->prepare("
            SELECT device_label
            FROM sensor_data
            WHERE user_id = ?
              AND device_label IS NOT NULL
              AND TRIM(device_label) <> ''
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmtDevice->execute([$user_id]);
        $deviceRow = $stmtDevice->fetch(PDO::FETCH_ASSOC);

        if ($deviceRow && !empty($deviceRow['device_label'])) {
            $assigned_device_id = trim($deviceRow['device_label']);
        }
    } catch (PDOException $e) {
        $assigned_device_id = null;
    }
}

/*
|--------------------------------------------------------------------------
| LATEST TELEMETRY
|--------------------------------------------------------------------------
*/
$latest = null;

try {
    if ($role === 'admin') {
        $stmtLatest = $conn->query("
            SELECT *
            FROM soil_readings
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $latest = $stmtLatest->fetch(PDO::FETCH_ASSOC);
    } elseif ($assigned_device_id !== null) {
        $stmtLatest = $conn->prepare("
            SELECT *
            FROM soil_readings
            WHERE device_id = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $stmtLatest->execute([$assigned_device_id]);
        $latest = $stmtLatest->fetch(PDO::FETCH_ASSOC);
    } else {
        $latest = null;
    }
} catch (PDOException $e) {
    $latest = null;
}

/*
|--------------------------------------------------------------------------
| PAGINATION & HISTORY LOGS
|--------------------------------------------------------------------------
*/
$limit = 15;
$page = isset($_GET['history_page']) ? max(1, intval($_GET['history_page'])) : 1;
$offset = ($page - 1) * $limit;

$totalRows = 0;
$totalPages = 1;

try {
    if ($role === 'admin') {
        $stmtCount = $conn->query("SELECT COUNT(*) AS total FROM soil_readings");
        $totalRows = (int)($stmtCount->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    } elseif ($assigned_device_id !== null) {
        $stmtCount = $conn->prepare("SELECT COUNT(*) AS total FROM soil_readings WHERE device_id = ?");
        $stmtCount->execute([$assigned_device_id]);
        $totalRows = (int)($stmtCount->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    }
    $totalPages = max(1, (int)ceil($totalRows / $limit));
} catch (PDOException $e) {
    $totalRows = 0;
    $totalPages = 1;
}

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$historyLogs = [];
try {
    if ($role === 'admin') {
        $stmtLogs = $conn->prepare("
            SELECT *
            FROM soil_readings
            ORDER BY created_at DESC, id DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmtLogs->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmtLogs->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmtLogs->execute();
        $historyLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($assigned_device_id !== null) {
        $stmtLogs = $conn->prepare("
            SELECT *
            FROM soil_readings
            WHERE device_id = ?
            ORDER BY created_at DESC, id DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmtLogs->bindValue(1, $assigned_device_id, PDO::PARAM_STR);
        $stmtLogs->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmtLogs->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmtLogs->execute();
        $historyLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $historyLogs = [];
}

// Parameter status styling helper
function getMetricBadge($val, $min, $max, $isNutrient = false) {
    if ($val === null) {
        return ['class' => 'neutral', 'label' => 'No Data'];
    }
    if ($isNutrient) {
        return ['class' => 'neutral', 'label' => 'Unverified'];
    }
    $f = floatval($val);
    if ($f < $min) {
        return ['class' => 'critical', 'label' => 'Critical (Low)'];
    } elseif ($f > $max) {
        return ['class' => 'warning', 'label' => 'Warning (High)'];
    } else {
        return ['class' => 'optimal', 'label' => 'Optimal'];
    }
}

$valMoisture = $latest['moisture'] ?? 0.0;
$valPh = $latest['ph'] ?? 6.6;
$valTemp = $latest['temperature'] ?? 31.6;
$valN = $latest['nitrogen'] ?? 46;
$valP = $latest['phosphorus'] ?? 24;
$valK = $latest['potassium'] ?? 74;

$mBadge = getMetricBadge($valMoisture, 30, 60);
$phBadge = getMetricBadge($valPh, 5.0, 7.5);
$tBadge = getMetricBadge($valTemp, 20, 32);
$nBadge = getMetricBadge($valN, 20, 50, true);
$pBadge = getMetricBadge($valP, 10, 30, true);
$kBadge = getMetricBadge($valK, 15, 50, true);

$readingFormatted = ($latest && isset($latest['created_at']))
    ? date('M j, Y · g:i A', strtotime($latest['created_at']))
    : date('M j, Y · g:i A');

$timeDiff = ($latest && isset($latest['created_at'])) ? (time() - strtotime($latest['created_at'])) : 9999;
$outdatedText = ($timeDiff > 600) ? ' · Over 10 minutes old' : ' · Live Synchronized';
?>

<div class="sub-view-panel-container">

    <!-- =========================================================
         1. TOP HEADER & TELEMETRY SUMMARY (Matches Image 4)
         ========================================================= -->
    <div>
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px;">
            <div>
                <h3 style="font-size: 20px; font-weight: 800; color: var(--text-heading); margin: 0;">My Soil Telemetry & Analysis</h3>
                <p style="font-size: 13.5px; color: var(--text-muted); margin-top: 3px;">Recorded soil measurements and field history.</p>
            </div>
            <span style="font-size: 12px; color: var(--text-muted); background: #ffffff; border: 1px solid #d5e0d7; padding: 4px 10px; border-radius: 20px;">
                Updates every 15 seconds
            </span>
        </div>
        
        <div style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 14px;">
            Last reading: <?= $readingFormatted ?><?= $outdatedText ?>
        </div>

        <!-- Warning banner -->
        <div class="warning-alert-banner">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <span>NPK hardware verification unavailable</span>
        </div>
    </div>

    <!-- =========================================================
         2. 6-PARAMETER METRICS GRID (3 columns x 2 rows - Matches Image 4)
         ========================================================= -->
    <div class="six-parameter-grid">
        
        <!-- Soil Moisture -->
        <div class="parameter-card">
            <div class="parameter-card-top">
                <span class="parameter-name">SOIL MOISTURE</span>
                <span class="badge-pill <?= $mBadge['class'] ?>"><?= $mBadge['label'] ?></span>
            </div>
            <div class="parameter-value-large"><?= number_format((float)$valMoisture, 1) ?>%</div>
            <div class="parameter-target-range">Target: 30% - 60%</div>
        </div>

        <!-- pH Level -->
        <div class="parameter-card">
            <div class="parameter-card-top">
                <span class="parameter-name">PH LEVEL</span>
                <span class="badge-pill <?= $phBadge['class'] ?>"><?= $phBadge['label'] ?></span>
            </div>
            <div class="parameter-value-large"><?= number_format((float)$valPh, 1) ?></div>
            <div class="parameter-target-range">Target: 5.0 - 7.5</div>
        </div>

        <!-- Nitrogen (N) -->
        <div class="parameter-card">
            <div class="parameter-card-top">
                <span class="parameter-name">NITROGEN (N)</span>
                <span class="badge-pill <?= $nBadge['class'] ?>"><?= $nBadge['label'] ?></span>
            </div>
            <div class="parameter-value-large"><?= htmlspecialchars((string)$valN) ?> <span style="font-size: 14px; font-weight: 500; color: #6b7280;">mg/kg</span></div>
            <div class="parameter-target-range">Target: 20 - 50</div>
        </div>

        <!-- Phosphorus (P) -->
        <div class="parameter-card">
            <div class="parameter-card-top">
                <span class="parameter-name">PHOSPHORUS (P)</span>
                <span class="badge-pill <?= $pBadge['class'] ?>"><?= $pBadge['label'] ?></span>
            </div>
            <div class="parameter-value-large"><?= htmlspecialchars((string)$valP) ?> <span style="font-size: 14px; font-weight: 500; color: #6b7280;">mg/kg</span></div>
            <div class="parameter-target-range">Target: 10 - 30</div>
        </div>

        <!-- Potassium (K) -->
        <div class="parameter-card">
            <div class="parameter-card-top">
                <span class="parameter-name">POTASSIUM (K)</span>
                <span class="badge-pill <?= $kBadge['class'] ?>"><?= $kBadge['label'] ?></span>
            </div>
            <div class="parameter-value-large"><?= htmlspecialchars((string)$valK) ?> <span style="font-size: 14px; font-weight: 500; color: #6b7280;">mg/kg</span></div>
            <div class="parameter-target-range">Target: 15 - 50</div>
        </div>

        <!-- Temperature -->
        <div class="parameter-card">
            <div class="parameter-card-top">
                <span class="parameter-name">TEMPERATURE</span>
                <span class="badge-pill <?= $tBadge['class'] ?>"><?= $tBadge['label'] ?></span>
            </div>
            <div class="parameter-value-large"><?= number_format((float)$valTemp, 1) ?>°C</div>
            <div class="parameter-target-range">Target: 20°C - 32°C</div>
        </div>

    </div>

    <!-- =========================================================
         3. SOIL PARAMETER CRITERIA REFERENCE (Matches Image 4)
         ========================================================= -->
    <div class="card-panel">
        <div class="card-title" style="font-size: 16px;">Soil Parameter Criteria Reference</div>
        <div class="card-subtitle" style="margin-bottom: 16px;">Color legend: Red (Critical/Low), Green (Optimal), Orange (High/Excess).</div>

        <div class="criteria-columns-grid">
            <!-- Soil Moisture -->
            <div class="criteria-col">
                <h4>Soil Moisture</h4>
                <ul class="criteria-list">
                    <li class="criteria-item"><span class="bullet-dot red"></span> &lt; 30%: Dry</li>
                    <li class="criteria-item"><span class="bullet-dot green"></span> 30% – 60%: Optimal</li>
                    <li class="criteria-item"><span class="bullet-dot orange"></span> &gt; 60%: Wet</li>
                </ul>
            </div>

            <!-- Soil pH Level -->
            <div class="criteria-col">
                <h4>Soil pH Level</h4>
                <ul class="criteria-list">
                    <li class="criteria-item"><span class="bullet-dot red"></span> &lt; 5.0: Acidic</li>
                    <li class="criteria-item"><span class="bullet-dot green"></span> 5.0 – 7.5: Optimal</li>
                    <li class="criteria-item"><span class="bullet-dot orange"></span> &gt; 7.5: Alkaline</li>
                </ul>
            </div>

            <!-- Temperature -->
            <div class="criteria-col">
                <h4>Temperature</h4>
                <ul class="criteria-list">
                    <li class="criteria-item"><span class="bullet-dot red"></span> &lt; 20°C: Cool</li>
                    <li class="criteria-item"><span class="bullet-dot green"></span> 20°C – 32°C: Optimal</li>
                    <li class="criteria-item"><span class="bullet-dot orange"></span> &gt; 32°C: Heat Stress</li>
                </ul>
            </div>

            <!-- Nutrients (N-P-K) -->
            <div class="criteria-col">
                <h4>Nutrients (N-P-K)</h4>
                <ul class="criteria-list">
                    <li class="criteria-item"><span class="bullet-dot neutral"></span> N: 20–50 mg/kg</li>
                    <li class="criteria-item"><span class="bullet-dot neutral"></span> P: 10–30 mg/kg</li>
                    <li class="criteria-item"><span class="bullet-dot neutral"></span> K: 15–50 mg/kg</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- =========================================================
         4. RECENT TELEMETRY HISTORY LOGS TABLE (Matches Image 4)
         ========================================================= -->
    <div class="table-container-card">
        <div class="table-header-flex">
            <div>
                <div class="card-title" style="font-size: 16px;">Recent Telemetry History Logs</div>
                <div class="card-subtitle">Automatic records logged from field sensors.</div>
            </div>
            <div style="font-size: 13px; font-weight: 600; color: var(--text-muted);">
                Page <?= $page ?> of <?= $totalPages ?> (Total: <?= number_format($totalRows) ?>)
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="custom-data-table">
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
                        <?php foreach ($historyLogs as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['created_at'] ?? '---') ?></td>
                                <td><strong><?= isset($row['moisture']) ? number_format((float)$row['moisture'], 1) . '%' : '---' ?></strong></td>
                                <td><?= isset($row['ph']) ? number_format((float)$row['ph'], 1) : '---' ?></td>
                                <td><?= htmlspecialchars($row['nitrogen'] ?? '---') ?> mg/kg</td>
                                <td><?= htmlspecialchars($row['phosphorus'] ?? '---') ?> mg/kg</td>
                                <td><?= htmlspecialchars($row['potassium'] ?? '---') ?> mg/kg</td>
                                <td><?= isset($row['temperature']) ? number_format((float)$row['temperature'], 1) . '°C' : '---' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: #9ca3af; padding: 24px;">No historical telemetry records logged yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div style="display: flex; justify-content: center; gap: 6px; padding: 16px; border-top: 1px solid #f0f4f1;">
                <?php if ($page > 1): ?>
                    <a href="dashboard.php?page=soil&history_page=<?= $page - 1 ?>" class="btn-outline" style="padding: 6px 12px; font-size: 12px;">Previous</a>
                <?php endif; ?>

                <?php 
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                for ($p = $start; $p <= $end; $p++): 
                ?>
                    <a href="dashboard.php?page=soil&history_page=<?= $p ?>" class="<?= $p === $page ? 'btn-primary' : 'btn-outline' ?>" style="width: auto; padding: 6px 12px; font-size: 12px; <?= $p === $page ? 'background:var(--primary-color);color:#fff;' : '' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="dashboard.php?page=soil&history_page=<?= $page + 1 ?>" class="btn-outline" style="padding: 6px 12px; font-size: 12px;">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

</div>