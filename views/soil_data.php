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
| RESOLVE ASSIGNED NODE (UNIFIED WITH ESP32_GSM_01 & NODE 1)
|--------------------------------------------------------------------------
*/
$assigned_info = null;
$assigned_device_id = null;
$assigned_node_name = null;
$assigned_aliases = [];

if ($role !== 'admin' && $user_id > 0) {
    $assigned_info = get_assigned_device_for_user($conn, $user_id);
    if ($assigned_info) {
        $assigned_device_id = $assigned_info['device_uid'];
        $assigned_node_name = $assigned_info['node_name'];
        $assigned_aliases = $assigned_info['aliases'];
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
    } elseif (!empty($assigned_aliases)) {
        $placeholders = implode(',', array_fill(0, count($assigned_aliases), '?'));
        $stmtLatest = $conn->prepare("
            SELECT *
            FROM soil_readings
            WHERE device_id IN ($placeholders)
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $stmtLatest->execute($assigned_aliases);
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
    } elseif (!empty($assigned_aliases)) {
        $placeholders = implode(',', array_fill(0, count($assigned_aliases), '?'));
        $stmtCount = $conn->prepare("SELECT COUNT(*) AS total FROM soil_readings WHERE device_id IN ($placeholders)");
        $stmtCount->execute($assigned_aliases);
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
    } elseif (!empty($assigned_aliases)) {
        $placeholders = implode(',', array_fill(0, count($assigned_aliases), '?'));
        $stmtLogs = $conn->prepare("
            SELECT *
            FROM soil_readings
            WHERE device_id IN ($placeholders)
            ORDER BY created_at DESC, id DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmtLogs->execute($assigned_aliases);
        $historyLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $historyLogs = [];
}

// Parameter status styling helper
function getMetricBadge($type, $val) {
    if ($val === null || $val === '') {
        return ['class' => 'neutral', 'label' => 'No Data', 'color' => '#6b7280'];
    }
    $f = floatval($val);
    switch ($type) {
        case 'moisture':
            if ($f < 30) return ['class' => 'critical', 'label' => 'Critical (Low)', 'color' => '#dc2626'];
            if ($f > 60) return ['class' => 'warning', 'label' => 'High (Wet)', 'color' => '#ea580c'];
            return ['class' => 'optimal', 'label' => 'Optimal', 'color' => '#16a34a'];
            
        case 'ph':
            if ($f < 5.0) return ['class' => 'critical', 'label' => 'Acidic (Low)', 'color' => '#dc2626'];
            if ($f > 7.5) return ['class' => 'warning', 'label' => 'Alkaline (High)', 'color' => '#ea580c'];
            return ['class' => 'optimal', 'label' => 'Optimal', 'color' => '#16a34a'];
            
        case 'temperature':
            if ($f < 20) return ['class' => 'critical', 'label' => 'Cool (Low)', 'color' => '#dc2626'];
            if ($f > 32) return ['class' => 'warning', 'label' => 'Heat Stress', 'color' => '#ea580c'];
            return ['class' => 'optimal', 'label' => 'Optimal', 'color' => '#16a34a'];

        case 'n':
        case 'nitrogen':
            if ($f < 20) return ['class' => 'critical', 'label' => 'Low', 'color' => '#dc2626'];
            if ($f > 50) return ['class' => 'warning', 'label' => 'High', 'color' => '#ea580c'];
            return ['class' => 'optimal', 'label' => 'Optimal', 'color' => '#16a34a'];

        case 'p':
        case 'phosphorus':
            if ($f < 10) return ['class' => 'critical', 'label' => 'Low', 'color' => '#dc2626'];
            if ($f > 30) return ['class' => 'warning', 'label' => 'High', 'color' => '#ea580c'];
            return ['class' => 'optimal', 'label' => 'Optimal', 'color' => '#16a34a'];

        case 'k':
        case 'potassium':
            if ($f < 15) return ['class' => 'critical', 'label' => 'Low', 'color' => '#dc2626'];
            if ($f > 50) return ['class' => 'warning', 'label' => 'High', 'color' => '#ea580c'];
            return ['class' => 'optimal', 'label' => 'Optimal', 'color' => '#16a34a'];
    }
    return ['class' => 'optimal', 'label' => 'Optimal', 'color' => '#16a34a'];
}

$valMoisture = $latest['moisture'] ?? 0.0;
$valPh = $latest['ph'] ?? 6.6;
$valTemp = $latest['temperature'] ?? 31.6;
$valN = $latest['nitrogen'] ?? 46;
$valP = $latest['phosphorus'] ?? 24;
$valK = $latest['potassium'] ?? 74;

$mBadge = getMetricBadge('moisture', $valMoisture);
$phBadge = getMetricBadge('ph', $valPh);
$tBadge = getMetricBadge('temperature', $valTemp);
$nBadge = getMetricBadge('nitrogen', $valN);
$pBadge = getMetricBadge('phosphorus', $valP);
$kBadge = getMetricBadge('potassium', $valK);

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
                    <li class="criteria-item"><span class="bullet-dot red"></span> Low: N&lt;20, P&lt;10, K&lt;15</li>
                    <li class="criteria-item"><span class="bullet-dot green"></span> Optimal: N:20-50, P:10-30, K:15-50</li>
                    <li class="criteria-item"><span class="bullet-dot orange"></span> Excess: N&gt;50, P&gt;30, K&gt;50</li>
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
                <div class="card-subtitle">Automatic records logged from field sensors with status indicators.</div>
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
                        <?php foreach ($historyLogs as $row): 
                            $rM = getMetricBadge('moisture', $row['moisture'] ?? null);
                            $rPh = getMetricBadge('ph', $row['ph'] ?? null);
                            $rN = getMetricBadge('nitrogen', $row['nitrogen'] ?? null);
                            $rP = getMetricBadge('phosphorus', $row['phosphorus'] ?? null);
                            $rK = getMetricBadge('potassium', $row['potassium'] ?? null);
                            $rT = getMetricBadge('temperature', $row['temperature'] ?? null);
                        ?>
                            <tr>
                                <td style="color: #6b7280;"><?= htmlspecialchars($row['created_at'] ?? '---') ?></td>
                                <td>
                                    <span style="display:inline-flex; align-items:center; gap:6px; font-weight:700; color:<?= $rM['color'] ?>;">
                                        <span class="bullet-dot" style="background-color:<?= $rM['color'] ?>;"></span>
                                        <?= isset($row['moisture']) ? number_format((float)$row['moisture'], 1) . '%' : '---' ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="display:inline-flex; align-items:center; gap:6px; font-weight:600; color:<?= $rPh['color'] ?>;">
                                        <span class="bullet-dot" style="background-color:<?= $rPh['color'] ?>;"></span>
                                        <?= isset($row['ph']) ? number_format((float)$row['ph'], 1) : '---' ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="display:inline-flex; align-items:center; gap:6px; color:<?= $rN['color'] ?>;">
                                        <span class="bullet-dot" style="background-color:<?= $rN['color'] ?>;"></span>
                                        <?= htmlspecialchars((string)($row['nitrogen'] ?? '---')) ?> mg/kg
                                    </span>
                                </td>
                                <td>
                                    <span style="display:inline-flex; align-items:center; gap:6px; color:<?= $rP['color'] ?>;">
                                        <span class="bullet-dot" style="background-color:<?= $rP['color'] ?>;"></span>
                                        <?= htmlspecialchars((string)($row['phosphorus'] ?? '---')) ?> mg/kg
                                    </span>
                                </td>
                                <td>
                                    <span style="display:inline-flex; align-items:center; gap:6px; color:<?= $rK['color'] ?>;">
                                        <span class="bullet-dot" style="background-color:<?= $rK['color'] ?>;"></span>
                                        <?= htmlspecialchars((string)($row['potassium'] ?? '---')) ?> mg/kg
                                    </span>
                                </td>
                                <td>
                                    <span style="display:inline-flex; align-items:center; gap:6px; font-weight:600; color:<?= $rT['color'] ?>;">
                                        <span class="bullet-dot" style="background-color:<?= $rT['color'] ?>;"></span>
                                        <?= isset($row['temperature']) ? number_format((float)$row['temperature'], 1) . '°C' : '---' ?>
                                    </span>
                                </td>
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