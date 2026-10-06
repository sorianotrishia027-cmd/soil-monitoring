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
| VERIFY USER
|--------------------------------------------------------------------------
*/

try {

    $userStmt = $conn->prepare("
        SELECT id, role
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $userStmt->execute([$user_id]);

    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$userRow) {
        echo '<div style="padding:20px;color:#dc3545;">User account not found.</div>';
        exit;
    }

    $role = strtolower(trim($userRow['role'] ?? $role));

    if ($role !== 'admin' && $role !== 'farmer') {
        $role = 'farmer';
    }

} catch (PDOException $e) {

    error_log('[SOIL DATA] User verification error: ' . $e->getMessage());

    echo '<div style="padding:20px;color:#dc3545;">Unable to verify user account.</div>';
    exit;
}


/*
|--------------------------------------------------------------------------
| RESOLVE FARMER DEVICE
|--------------------------------------------------------------------------
|
| IMPORTANT:
| The API is the final source of truth.
| This PHP resolver is only used for initial server-side rendering.
|
*/

$assigned_device_id = null;
$assigned_node_id = null;

if ($role !== 'admin') {

    try {

        $stmtDevice = $conn->prepare("
            SELECT id, device_id, device_label
            FROM sensor_data
            WHERE user_id = ?
            ORDER BY id DESC
        ");

        $stmtDevice->execute([$user_id]);

        $deviceRows = $stmtDevice->fetchAll(PDO::FETCH_ASSOC);

        /*
        |--------------------------------------------------------------------------
        | FIRST: FIND A DEVICE THAT ACTUALLY HAS TELEMETRY
        |--------------------------------------------------------------------------
        */

        foreach ($deviceRows as $deviceRow) {

            $candidates = [];

            $deviceId = trim((string)($deviceRow['device_id'] ?? ''));
            $deviceLabel = trim((string)($deviceRow['device_label'] ?? ''));

            if ($deviceId !== '') {
                $candidates[] = $deviceId;
            }

            if (
                $deviceLabel !== '' &&
                !in_array($deviceLabel, $candidates, true)
            ) {
                $candidates[] = $deviceLabel;
            }

            foreach ($candidates as $candidate) {

                $checkStmt = $conn->prepare("
                    SELECT id
                    FROM soil_readings
                    WHERE device_id = ?
                    LIMIT 1
                ");

                $checkStmt->execute([$candidate]);

                if ($checkStmt->fetch(PDO::FETCH_ASSOC)) {

                    $assigned_device_id = $candidate;
                    $assigned_node_id = $deviceRow['id'] ?? null;

                    break 2;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | SECOND: FALLBACK TO ASSIGNED DEVICE RECORD
        |--------------------------------------------------------------------------
        */

        if ($assigned_device_id === null) {

            foreach ($deviceRows as $deviceRow) {

                $deviceId = trim((string)($deviceRow['device_id'] ?? ''));
                $deviceLabel = trim((string)($deviceRow['device_label'] ?? ''));

                if ($deviceId !== '') {

                    $assigned_device_id = $deviceId;
                    $assigned_node_id = $deviceRow['id'] ?? null;

                    break;
                }

                if ($deviceLabel !== '') {

                    $assigned_device_id = $deviceLabel;
                    $assigned_node_id = $deviceRow['id'] ?? null;

                    break;
                }
            }
        }

        error_log(
            '[SOIL DATA] user_id=' .
            $user_id .
            ' device=' .
            ($assigned_device_id ?? 'NULL')
        );

    } catch (PDOException $e) {

        error_log(
            '[SOIL DATA] Device resolver error: ' .
            $e->getMessage()
        );
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
            ORDER BY id DESC
            LIMIT 1
        ");

        $latest = $stmtLatest->fetch(PDO::FETCH_ASSOC);

    } elseif (
        $assigned_device_id !== null &&
        $assigned_device_id !== ''
    ) {

        $stmtLatest = $conn->prepare("
            SELECT *
            FROM soil_readings
            WHERE device_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");

        $stmtLatest->execute([$assigned_device_id]);

        $latest = $stmtLatest->fetch(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {

    error_log(
        '[SOIL DATA] Latest telemetry error: ' .
        $e->getMessage()
    );
}


/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

$limit = 15;

$page = isset($_GET['history_page'])
    ? max(1, (int)$_GET['history_page'])
    : 1;

$offset = ($page - 1) * $limit;

$totalRows = 0;
$totalPages = 1;


/*
|--------------------------------------------------------------------------
| COUNT
|--------------------------------------------------------------------------
*/

try {

    if ($role === 'admin') {

        $stmtCount = $conn->query("
            SELECT COUNT(*) AS total
            FROM soil_readings
        ");

        $countRow = $stmtCount->fetch(PDO::FETCH_ASSOC);

        $totalRows = (int)($countRow['total'] ?? 0);

    } elseif (
        $assigned_device_id !== null &&
        $assigned_device_id !== ''
    ) {

        $stmtCount = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM soil_readings
            WHERE device_id = ?
        ");

        $stmtCount->execute([$assigned_device_id]);

        $countRow = $stmtCount->fetch(PDO::FETCH_ASSOC);

        $totalRows = (int)($countRow['total'] ?? 0);
    }

    $totalPages = max(
        1,
        (int)ceil($totalRows / $limit)
    );

} catch (PDOException $e) {

    error_log(
        '[SOIL DATA] Count error: ' .
        $e->getMessage()
    );
}


/*
|--------------------------------------------------------------------------
| VALID PAGE
|--------------------------------------------------------------------------
*/

if ($page > $totalPages) {

    $page = $totalPages;

    $offset = ($page - 1) * $limit;
}


/*
|--------------------------------------------------------------------------
| HISTORY
|--------------------------------------------------------------------------
*/

$historyLogs = [];

try {

    $safeLimit = max(
        1,
        min(100, (int)$limit)
    );

    $safeOffset = max(
        0,
        (int)$offset
    );

    if ($role === 'admin') {

        $stmtLogs = $conn->query("
            SELECT *
            FROM soil_readings
            ORDER BY id DESC
            LIMIT {$safeLimit}
            OFFSET {$safeOffset}
        ");

        $historyLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

    } elseif (
        $assigned_device_id !== null &&
        $assigned_device_id !== ''
    ) {

        $stmtLogs = $conn->prepare("
            SELECT *
            FROM soil_readings
            WHERE device_id = ?
            ORDER BY id DESC
            LIMIT {$safeLimit}
            OFFSET {$safeOffset}
        ");

        $stmtLogs->execute([$assigned_device_id]);

        $historyLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
    }

    error_log(
        '[SOIL DATA] History user=' .
        $user_id .
        ' role=' .
        $role .
        ' device=' .
        ($assigned_device_id ?? 'NULL') .
        ' rows=' .
        count($historyLogs) .
        ' total=' .
        $totalRows
    );

} catch (PDOException $e) {

    error_log(
        '[SOIL DATA] History error: ' .
        $e->getMessage()
    );
}


/*
|--------------------------------------------------------------------------
| VALUE HELPERS
|--------------------------------------------------------------------------
*/

$valMoisture = $latest['moisture']
    ?? $latest['soil_moisture']
    ?? null;

$valPh = $latest['ph']
    ?? $latest['ph_level']
    ?? null;

$valN = $latest['nitrogen']
    ?? $latest['n']
    ?? null;

$valP = $latest['phosphorus']
    ?? $latest['p']
    ?? null;

$valK = $latest['potassium']
    ?? $latest['k']
    ?? null;

$valTemp = $latest['temperature']
    ?? $latest['temp']
    ?? null;


/*
|--------------------------------------------------------------------------
| STATUS FUNCTION
|--------------------------------------------------------------------------
*/

if (!function_exists('getStatusStyle')) {

    function getStatusStyle($val, $min, $max)
    {
        if ($val === null || $val === '') {

            return [
                'color' => '#6c757d',
                'border' => '#6c757d',
                'status' => 'No Data'
            ];
        }

        $num = (float)$val;

        if ($num < $min) {

            return [
                'color' => '#dc3545',
                'border' => '#dc3545',
                'status' => 'Critical (Low)'
            ];
        }

        if ($num > $max) {

            return [
                'color' => '#e65100',
                'border' => '#ff9800',
                'status' => 'Warning (High)'
            ];
        }

        return [
            'color' => '#198754',
            'border' => '#198754',
            'status' => 'Optimal'
        ];
    }
}


$mStyle = getStatusStyle($valMoisture, 30, 60);
$phStyle = getStatusStyle($valPh, 5, 7.5);
$nStyle = getStatusStyle($valN, 20, 50);
$pStyle = getStatusStyle($valP, 10, 30);
$kStyle = getStatusStyle($valK, 15, 50);
$tStyle = getStatusStyle($valTemp, 20, 32);

$npkNoResponse = (
    $valN !== null &&
    $valP !== null &&
    $valK !== null &&
    (float)$valN == 0 &&
    (float)$valP == 0 &&
    (float)$valK == 0
);

?>

<style>

@keyframes soilLivePulse {

    0% {
        transform: scale(0.95);
        box-shadow: 0 0 0 0 rgba(46,125,50,.7);
    }

    70% {
        transform: scale(1.1);
        box-shadow: 0 0 0 6px rgba(46,125,50,0);
    }

    100% {
        transform: scale(.95);
        box-shadow: 0 0 0 0 rgba(46,125,50,0);
    }
}

.soil-data-wrapper {
    padding:15px 10px;
    width:100%;
    box-sizing:border-box;
    overflow-x:hidden;
}

.soil-header {
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    flex-wrap:wrap;
    gap:15px;
    margin-bottom:20px;
}

.soil-metrics-grid {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
    gap:16px;
    margin-bottom:25px;
}

.soil-metric-card {
    background:#fff;
    padding:20px;
    border-radius:14px;
    box-shadow:0 4px 15px rgba(0,0,0,.03);
    border:1px solid #edf2ed;
}

.criteria-grid {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
    gap:20px;
    font-size:.9rem;
    line-height:1.6;
}

.table-card {
    background:#fff;
    padding:20px;
    border-radius:16px;
    box-shadow:0 4px 20px rgba(0,0,0,.03);
    border:1px solid #d9e2d9;
    width:100%;
    box-sizing:border-box;
}

.table-responsive {
    width:100%;
    overflow-x:auto;
    -webkit-overflow-scrolling:touch;
}

.soil-table {
    width:100%;
    border-collapse:collapse;
    text-align:left;
    font-size:.9rem;
    white-space:nowrap;
}

.soil-table th {
    background:#f4f7f4;
    border-bottom:2px solid #e2e8e2;
    color:#2c3e2c;
    padding:12px 14px;
    font-weight:600;
}

.soil-table td {
    padding:12px 14px;
    border-bottom:1px solid #edf2ed;
}

.google-pagination-container {
    display:flex;
    justify-content:center;
    margin-top:25px;
    padding-top:15px;
    border-top:1px solid #edf2ed;
}

.google-pagination {
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:4px;
}

.gp-btn {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:36px;
    height:36px;
    padding:0 10px;
    background:#fff;
    color:#202124;
    border:1px solid #dadce0;
    border-radius:4px;
    text-decoration:none;
    font-size:.85rem;
}

.gp-btn.active {
    background:#e8f0fe;
    color:#1a73e8;
    border-color:#1a73e8;
}

.gp-btn.disabled {
    color:#bdc1c6;
    background:#f8f9fa;
    pointer-events:none;
}

.gp-ellipsis {
    padding:0 5px;
    color:#777;
}

@media(max-width:768px) {

    .soil-header {
        flex-direction:column;
    }

    .table-card {
        padding:12px;
    }
}

</style>


<div class="soil-data-wrapper">

    <div class="soil-header">

        <div>

            <h3 style="
                color:var(--primary-color,#2e7d32);
                font-weight:700;
                font-size:1.4rem;
                margin-bottom:4px;
            ">
                My Soil Telemetry & Analysis
            </h3>

            <p style="
                color:#657765;
                font-size:.9rem;
                margin:0;
            ">
                Logged field readings evaluated against optimal agricultural thresholds.
            </p>

        </div>

        <div id="soil-live-badge" style="
            background:#e8f5e9;
            color:#1b5e20;
            padding:6px 14px;
            border-radius:20px;
            font-weight:700;
            font-size:12px;
            display:flex;
            align-items:center;
            gap:8px;
        ">

            <span style="
                display:inline-block;
                width:8px;
                height:8px;
                border-radius:50%;
                background:#2e7d32;
                animation:soilLivePulse 1.8s infinite;
            "></span>

            LIVE REAL-TIME STREAM

        </div>

    </div>


    <!--
    IMPORTANT:
    Do NOT show "No hardware node..." based only on the PHP resolver.
    The API is the source of truth.
    -->

    <div
        id="soil-node-warning"
        style="
            display:none;
            background:#fff3cd;
            border:1px solid #ffe69c;
            color:#664d03;
            padding:18px;
            border-radius:12px;
            margin-bottom:20px;
        "
    >
        No hardware node is currently assigned to this farmer account.
        Please contact the administrator to assign a node.
    </div>


    <!-- METRICS -->

    <div class="soil-metrics-grid">

        <?php

        $cards = [
            [
                'card' => 'soil-card-moisture',
                'status' => 'soil-status-moisture',
                'value' => 'soil-val-moisture',
                'label' => 'Soil Moisture',
                'val' => $valMoisture,
                'style' => $mStyle,
                'unit' => '%',
                'target' => '30% - 60%'
            ],
            [
                'card' => 'soil-card-ph',
                'status' => 'soil-status-ph',
                'value' => 'soil-val-ph',
                'label' => 'pH Level',
                'val' => $valPh,
                'style' => $phStyle,
                'unit' => '',
                'target' => '5.0 - 7.5'
            ],
            [
                'card' => 'soil-card-n',
                'status' => 'soil-status-n',
                'value' => 'soil-val-n',
                'label' => 'Nitrogen (N)',
                'val' => $valN,
                'style' => $nStyle,
                'unit' => ' mg/kg',
                'target' => '20 - 50'
            ],
            [
                'card' => 'soil-card-p',
                'status' => 'soil-status-p',
                'value' => 'soil-val-p',
                'label' => 'Phosphorus (P)',
                'val' => $valP,
                'style' => $pStyle,
                'unit' => ' mg/kg',
                'target' => '10 - 30'
            ],
            [
                'card' => 'soil-card-k',
                'status' => 'soil-status-k',
                'value' => 'soil-val-k',
                'label' => 'Potassium (K)',
                'val' => $valK,
                'style' => $kStyle,
                'unit' => ' mg/kg',
                'target' => '15 - 50'
            ],
            [
                'card' => 'soil-card-temp',
                'status' => 'soil-status-temp',
                'value' => 'soil-val-temp',
                'label' => 'Temperature',
                'val' => $valTemp,
                'style' => $tStyle,
                'unit' => '°C',
                'target' => '20°C - 32°C'
            ]
        ];

        ?>

        <?php foreach ($cards as $c): ?>

            <?php

            $isNpk =
                in_array(
                    $c['card'],
                    [
                        'soil-card-n',
                        'soil-card-p',
                        'soil-card-k'
                    ],
                    true
                );

            $style = $c['style'];

            if (
                $isNpk &&
                $npkNoResponse
            ) {

                $style = [
                    'color' => '#6c757d',
                    'border' => '#6c757d',
                    'status' => 'No Response'
                ];
            }

            ?>

            <div
                class="soil-metric-card"
                id="<?= htmlspecialchars($c['card']) ?>"
                style="border-left:5px solid <?= htmlspecialchars($style['border']) ?>;"
            >

                <div style="
                    display:flex;
                    justify-content:space-between;
                    align-items:center;
                    margin-bottom:8px;
                ">

                    <span style="
                        font-size:.75rem;
                        text-transform:uppercase;
                        color:#657765;
                        font-weight:700;
                    ">
                        <?= htmlspecialchars($c['label']) ?>
                    </span>

                    <span
                        id="<?= htmlspecialchars($c['status']) ?>"
                        style="
                            font-size:.7rem;
                            font-weight:600;
                            padding:2px 6px;
                            border-radius:6px;
                            background:<?= htmlspecialchars($style['color']) ?>15;
                            color:<?= htmlspecialchars($style['color']) ?>;
                        "
                    >
                        <?= htmlspecialchars($style['status']) ?>
                    </span>

                </div>

                <h2
                    id="<?= htmlspecialchars($c['value']) ?>"
                    style="
                        margin:5px 0;
                        color:<?= htmlspecialchars($style['color']) ?>;
                        font-size:1.6rem;
                    "
                >

                    <?php if ($c['val'] !== null): ?>

                        <?= htmlspecialchars(
                            in_array(
                                $c['card'],
                                [
                                    'soil-card-ph',
                                    'soil-card-moisture',
                                    'soil-card-temp'
                                ],
                                true
                            )
                            ? number_format((float)$c['val'], 1)
                            : (string)$c['val']
                        ) ?>

                        <?= htmlspecialchars($c['unit']) ?>

                    <?php else: ?>

                        --

                    <?php endif; ?>

                </h2>

                <span style="
                    font-size:.75rem;
                    color:#888;
                ">
                    Target: <?= htmlspecialchars($c['target']) ?>
                </span>

            </div>

        <?php endforeach; ?>

    </div>


    <!-- CRITERIA -->

    <div style="
        background:#fff;
        padding:20px;
        border-radius:16px;
        box-shadow:0 4px 20px rgba(0,0,0,.03);
        margin-bottom:25px;
        border:1px solid #d9e2d9;
    ">

        <h4 style="
            margin-top:0;
            color:#2c3e2c;
            font-size:1.05rem;
        ">
            Soil Parameter Criteria Reference
        </h4>

        <p style="
            color:#657765;
            font-size:.85rem;
        ">
            Color legend:
            <span style="color:#dc3545;font-weight:600;">
                Red (Critical/Low)
            </span>,
            <span style="color:#198754;font-weight:600;">
                Green (Optimal)
            </span>,
            <span style="color:#ff9800;font-weight:600;">
                Orange (High/Excess)
            </span>.
        </p>

        <div class="criteria-grid">

            <div>
                <strong>Soil Moisture</strong>
                <ul>
                    <li>&lt; 30%: Dry</li>
                    <li>30% – 60%: Optimal</li>
                    <li>&gt; 60%: Wet</li>
                </ul>
            </div>

            <div>
                <strong>Soil pH Level</strong>
                <ul>
                    <li>&lt; 5.0: Acidic</li>
                    <li>5.0 – 7.5: Optimal</li>
                    <li>&gt; 7.5: Alkaline</li>
                </ul>
            </div>

            <div>
                <strong>Temperature</strong>
                <ul>
                    <li>&lt; 20°C: Cool</li>
                    <li>20°C – 32°C: Optimal</li>
                    <li>&gt; 32°C: Heat Stress</li>
                </ul>
            </div>

            <div>
                <strong>Nutrients (N-P-K)</strong>
                <div style="margin-top:5px;">
                    N: 20–50 mg/kg<br>
                    P: 10–30 mg/kg<br>
                    K: 15–50 mg/kg
                </div>
            </div>

        </div>

    </div>


    <!-- HISTORY -->

    <div class="table-card">

        <div style="
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            flex-wrap:wrap;
            gap:10px;
            margin-bottom:15px;
        ">

            <div>

                <h4 style="
                    margin:0;
                    color:#2c3e2c;
                    font-size:1.05rem;
                ">
                    Recent Telemetry History Logs
                </h4>

                <p style="
                    color:#657765;
                    font-size:.85rem;
                    margin:2px 0 0;
                ">
                    Automatic records logged from field sensors.
                </p>

                <div
                    id="soil-history-api-status"
                    style="
                        margin-top:6px;
                        font-size:.75rem;
                        color:#6c757d;
                    "
                >
                    Connecting to telemetry API...
                </div>

            </div>

            <div
                id="soil-history-page-info"
                style="
                    font-size:.82rem;
                    color:#657765;
                    font-weight:500;
                "
            >
                Page <?= (int)$page ?>
                of <?= max(1, (int)$totalPages) ?>
                (Total: <?= (int)$totalRows ?>)
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

                            $lm = $log['moisture']
                                ?? $log['soil_moisture']
                                ?? 0;

                            $lpH = $log['ph']
                                ?? $log['ph_level']
                                ?? 0;

                            $ln = $log['nitrogen']
                                ?? $log['n']
                                ?? 0;

                            $lp = $log['phosphorus']
                                ?? $log['p']
                                ?? 0;

                            $lk = $log['potassium']
                                ?? $log['k']
                                ?? 0;

                            $lt = $log['temperature']
                                ?? $log['temp']
                                ?? 0;

                            $lmStyle = getStatusStyle(
                                $lm,
                                30,
                                60
                            );

                            $lpHStyle = getStatusStyle(
                                $lpH,
                                5,
                                7.5
                            );

                            ?>

                            <tr>

                                <td>
                                    <?= isset($log['created_at'])
                                        ? htmlspecialchars(
                                            date(
                                                'M j, Y - g:i A',
                                                strtotime($log['created_at'])
                                            )
                                        )
                                        : 'N/A'
                                    ?>
                                </td>

                                <td style="
                                    font-weight:600;
                                    color:<?= htmlspecialchars($lmStyle['color']) ?>;
                                ">
                                    <?= number_format((float)$lm, 1) ?>%
                                </td>

                                <td style="
                                    font-weight:600;
                                    color:<?= htmlspecialchars($lpHStyle['color']) ?>;
                                ">
                                    <?= number_format((float)$lpH, 1) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars((string)$ln) ?> mg/kg
                                </td>

                                <td>
                                    <?= htmlspecialchars((string)$lp) ?> mg/kg
                                </td>

                                <td>
                                    <?= htmlspecialchars((string)$lk) ?> mg/kg
                                </td>

                                <td>
                                    <?= number_format((float)$lt, 1) ?>°C
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td
                                colspan="7"
                                style="
                                    padding:25px;
                                    text-align:center;
                                    color:#657765;
                                "
                            >
                                Loading telemetry history...
                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>


        <?php if ($totalPages > 1): ?>

            <?php

            $params = $_GET;

            unset($params['history_page']);

            $queryBase = !empty($params)
                ? '?' . http_build_query($params) . '&'
                : '?';

            ?>

            <div class="google-pagination-container">

                <div class="google-pagination">

                    <a
                        href="<?= htmlspecialchars(
                            $queryBase .
                            'history_page=' .
                            max(1, $page - 1)
                        ) ?>"
                        class="gp-btn <?= $page <= 1 ? 'disabled' : '' ?>"
                    >
                        Previous
                    </a>

                    <?php

                    $range = 2;
                    $lastShown = 0;

                    for ($i = 1; $i <= $totalPages; $i++) {

                        $show = (
                            $i === 1 ||
                            $i === $totalPages ||
                            (
                                $i >= $page - $range &&
                                $i <= $page + $range
                            )
                        );

                        if (!$show) {
                            continue;
                        }

                        if (
                            $lastShown > 0 &&
                            $i > $lastShown + 1
                        ) {
                            echo '<span class="gp-ellipsis">...</span>';
                        }

                        echo '<a href="' .
                            htmlspecialchars(
                                $queryBase .
                                'history_page=' .
                                $i
                            ) .
                            '" class="gp-btn ' .
                            ($i === $page ? 'active' : '') .
                            '">' .
                            $i .
                            '</a>';

                        $lastShown = $i;
                    }

                    ?>

                    <a
                        href="<?= htmlspecialchars(
                            $queryBase .
                            'history_page=' .
                            min($totalPages, $page + 1)
                        ) ?>"
                        class="gp-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"
                    >
                        Next
                    </a>

                </div>

            </div>

        <?php endif; ?>

    </div>

</div>


<script>

(function () {

    /*
    |--------------------------------------------------------------------------
    | ELEMENTS
    |--------------------------------------------------------------------------
    */

    const tbody =
        document.getElementById('soil-history-tbody');

    const pageInfo =
        document.getElementById('soil-history-page-info');

    const apiStatus =
        document.getElementById('soil-history-api-status');

    const nodeWarning =
        document.getElementById('soil-node-warning');

    const currentPage =
        <?= (int)$page ?>;


    /*
    |--------------------------------------------------------------------------
    | STATUS
    |--------------------------------------------------------------------------
    */

    function healthStatus(value, min, max) {

        if (
            value === null ||
            value === undefined ||
            value === '' ||
            isNaN(value)
        ) {

            return {
                color: '#6c757d',
                border: '#6c757d',
                status: 'No Data'
            };
        }

        const num = Number(value);

        if (num < min) {

            return {
                color: '#dc3545',
                border: '#dc3545',
                status: 'Critical (Low)'
            };
        }

        if (num > max) {

            return {
                color: '#e65100',
                border: '#ff9800',
                status: 'Warning (High)'
            };
        }

        return {
            color: '#198754',
            border: '#198754',
            status: 'Optimal'
        };
    }


    /*
    |--------------------------------------------------------------------------
    | HTML ESCAPE
    |--------------------------------------------------------------------------
    */

    function escapeHtml(value) {

        if (
            value === null ||
            value === undefined
        ) {
            return '';
        }

        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE CARD
    |--------------------------------------------------------------------------
    */

    function updateCard(
        cardId,
        statusId,
        valueId,
        value,
        min,
        max,
        formatter
    ) {

        const card =
            document.getElementById(cardId);

        const status =
            document.getElementById(statusId);

        const valueElement =
            document.getElementById(valueId);

        const style =
            healthStatus(
                value,
                min,
                max
            );


        if (card) {

            card.style.borderLeft =
                '5px solid ' +
                style.border;
        }


        if (status) {

            status.innerText =
                style.status;

            status.style.color =
                style.color;

            status.style.backgroundColor =
                style.color + '15';
        }


        if (valueElement) {

            valueElement.innerHTML =
                formatter(value);

            valueElement.style.color =
                style.color;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | RENDER HISTORY
    |--------------------------------------------------------------------------
    */

    function renderHistory(logs) {

        if (!tbody) {
            return;
        }


        if (
            !Array.isArray(logs) ||
            logs.length === 0
        ) {

            tbody.innerHTML = `
                <tr>
                    <td
                        colspan="7"
                        style="
                            padding:25px;
                            text-align:center;
                            color:#657765;
                        "
                    >
                        No sensor logs recorded for this account.
                    </td>
                </tr>
            `;

            return;
        }


        let html = '';


        logs.forEach(function (log, index) {

            const moisture =
                Number(
                    log.moisture ??
                    log.soil_moisture ??
                    0
                );

            const ph =
                Number(
                    log.ph ??
                    log.ph_level ??
                    0
                );

            const nitrogen =
                log.nitrogen ??
                log.n ??
                0;

            const phosphorus =
                log.phosphorus ??
                log.p ??
                0;

            const potassium =
                log.potassium ??
                log.k ??
                0;

            const temperature =
                Number(
                    log.temperature ??
                    log.temp ??
                    0
                );


            const moistureStyle =
                healthStatus(
                    moisture,
                    30,
                    60
                );


            const phStyle =
                healthStatus(
                    ph,
                    5,
                    7.5
                );


            let timestamp =
                log.formatted_time ??
                log.created_at ??
                'N/A';


            /*
            |--------------------------------------------------------------------------
            | FORMAT SERVER TIMESTAMP
            |--------------------------------------------------------------------------
            */

            if (
                log.formatted_time === undefined &&
                log.created_at
            ) {

                try {

                    const parsedDate =
                        new Date(
                            String(log.created_at)
                                .replace(' ', 'T')
                        );

                    if (!isNaN(parsedDate.getTime())) {

                        timestamp =
                            parsedDate.toLocaleString(
                                'en-US',
                                {
                                    month: 'short',
                                    day: 'numeric',
                                    year: 'numeric',
                                    hour: 'numeric',
                                    minute: '2-digit'
                                }
                            );
                    }

                } catch (e) {

                    timestamp =
                        log.created_at;
                }
            }


            timestamp =
                escapeHtml(timestamp);


            html += `
                <tr
                    style="
                        ${
                            index === 0
                                ? 'background:#e8f5e9;'
                                : ''
                        }
                    "
                >

                    <td style="color:#556b55;">
                        ${timestamp}
                    </td>

                    <td style="
                        font-weight:600;
                        color:${moistureStyle.color};
                    ">
                        ${moisture.toFixed(1)}%
                    </td>

                    <td style="
                        font-weight:600;
                        color:${phStyle.color};
                    ">
                        ${ph.toFixed(1)}
                    </td>

                    <td style="color:#2c3e2c;">
                        ${escapeHtml(nitrogen)} mg/kg
                    </td>

                    <td style="color:#2c3e2c;">
                        ${escapeHtml(phosphorus)} mg/kg
                    </td>

                    <td style="color:#2c3e2c;">
                        ${escapeHtml(potassium)} mg/kg
                    </td>

                    <td style="color:#2c3e2c;">
                        ${temperature.toFixed(1)}°C
                    </td>

                </tr>
            `;
        });


        tbody.innerHTML = html;
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE PAGE INFO
    |--------------------------------------------------------------------------
    */

    function updatePageInfo(total) {

        if (!pageInfo) {
            return;
        }

        const count =
            Number(total || 0);

        const pages =
            Math.max(
                1,
                Math.ceil(count / 15)
            );

        pageInfo.innerText =
            `Page ${currentPage} of ${pages} (Total: ${count})`;
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE LIVE CARDS
    |--------------------------------------------------------------------------
    */

    function updateLiveCards(data) {

        if (!data) {
            return;
        }


        updateCard(
            'soil-card-moisture',
            'soil-status-moisture',
            'soil-val-moisture',
            data.moisture,
            30,
            60,
            function (v) {

                if (
                    v === null ||
                    v === undefined ||
                    v === ''
                ) {
                    return '--';
                }

                return Number(v).toFixed(1) + '%';
            }
        );


        updateCard(
            'soil-card-ph',
            'soil-status-ph',
            'soil-val-ph',
            data.ph,
            5,
            7.5,
            function (v) {

                if (
                    v === null ||
                    v === undefined ||
                    v === ''
                ) {
                    return '--';
                }

                return Number(v).toFixed(2);
            }
        );


        updateCard(
            'soil-card-temp',
            'soil-status-temp',
            'soil-val-temp',
            data.temperature,
            20,
            32,
            function (v) {

                if (
                    v === null ||
                    v === undefined ||
                    v === ''
                ) {
                    return '--';
                }

                return Number(v).toFixed(1) + '°C';
            }
        );


        /*
        |--------------------------------------------------------------------------
        | NPK
        |--------------------------------------------------------------------------
        */

        if (data.npk_online) {

            updateCard(
                'soil-card-n',
                'soil-status-n',
                'soil-val-n',
                data.nitrogen,
                20,
                50,
                function (v) {

                    if (
                        v === null ||
                        v === undefined
                    ) {
                        return '--';
                    }

                    return escapeHtml(v) +
                        ' <span style="font-size:.8rem;font-weight:normal;">mg/kg</span>';
                }
            );


            updateCard(
                'soil-card-p',
                'soil-status-p',
                'soil-val-p',
                data.phosphorus,
                10,
                30,
                function (v) {

                    if (
                        v === null ||
                        v === undefined
                    ) {
                        return '--';
                    }

                    return escapeHtml(v) +
                        ' <span style="font-size:.8rem;font-weight:normal;">mg/kg</span>';
                }
            );


            updateCard(
                'soil-card-k',
                'soil-status-k',
                'soil-val-k',
                data.potassium,
                15,
                50,
                function (v) {

                    if (
                        v === null ||
                        v === undefined
                    ) {
                        return '--';
                    }

                    return escapeHtml(v) +
                        ' <span style="font-size:.8rem;font-weight:normal;">mg/kg</span>';
                }
            );

        } else {

            const off = {
                color: '#6c757d',
                border: '#6c757d',
                status: 'No Response'
            };


            ['n', 'p', 'k'].forEach(function (x) {

                const card =
                    document.getElementById(
                        'soil-card-' + x
                    );

                const status =
                    document.getElementById(
                        'soil-status-' + x
                    );

                const value =
                    document.getElementById(
                        'soil-val-' + x
                    );


                if (card) {

                    card.style.borderLeft =
                        '5px solid ' +
                        off.border;
                }


                if (status) {

                    status.innerText =
                        off.status;

                    status.style.color =
                        off.color;

                    status.style.backgroundColor =
                        off.color + '15';
                }


                if (value) {

                    value.innerHTML =
                        '0 <span style="font-size:.8rem;font-weight:normal;">mg/kg</span>';

                    value.style.color =
                        off.color;
                }

            });
        }
    }


    /*
    |--------------------------------------------------------------------------
    | API FETCH
    |--------------------------------------------------------------------------
    */

    function loadTelemetry() {

        const url =
            '/api/get_live_telemetry.php?_=' +
            Date.now();


        fetch(
            url,
            {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json'
                }
            }
        )

        .then(function (response) {

            if (!response.ok) {

                throw new Error(
                    'HTTP ' +
                    response.status
                );
            }

            return response.json();
        })

        .then(function (result) {

            console.log(
                '[SOIL DATA] Telemetry API response:',
                result
            );


            /*
            |--------------------------------------------------------------------------
            | API ERROR
            |--------------------------------------------------------------------------
            */

            if (
                !result ||
                result.status !== 'success'
            ) {

                if (nodeWarning) {
                    nodeWarning.style.display = 'block';
                }

                if (apiStatus) {

                    apiStatus.style.color =
                        '#dc3545';

                    apiStatus.innerText =
                        'API returned no telemetry data.';
                }

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | API SUCCESS
            |--------------------------------------------------------------------------
            */

            if (nodeWarning) {

                /*
                |--------------------------------------------------------------------------
                | Only show warning if API itself confirms no device.
                |--------------------------------------------------------------------------
                */

                const assignedDevice =
                    result.assigned_device ??
                    result.matched_device ??
                    null;

                if (
                    result.role === 'farmer' &&
                    (
                        assignedDevice === null ||
                        assignedDevice === ''
                    ) &&
                    !result.data
                ) {

                    nodeWarning.style.display =
                        'block';

                } else {

                    nodeWarning.style.display =
                        'none';
                }
            }


            /*
            |--------------------------------------------------------------------------
            | API STATUS
            |--------------------------------------------------------------------------
            */

            if (apiStatus) {

                apiStatus.style.color =
                    '#198754';

                apiStatus.innerText =
                    'API connected • Logs received: ' +
                    (
                        Array.isArray(result.recent_logs)
                            ? result.recent_logs.length
                            : 0
                    ) +
                    ' • Total: ' +
                    (
                        result.total_count ??
                        0
                    ) +
                    ' • Device: ' +
                    (
                        result.assigned_device ??
                        result.matched_device ??
                        result.data?.device_id ??
                        'N/A'
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | UPDATE LIVE CARDS
            |--------------------------------------------------------------------------
            */

            if (result.data) {

                updateLiveCards(
                    result.data
                );
            }


            /*
            |--------------------------------------------------------------------------
            | UPDATE PAGE COUNT
            |--------------------------------------------------------------------------
            */

            updatePageInfo(
                result.total_count
            );


            /*
            |--------------------------------------------------------------------------
            | ALWAYS RENDER HISTORY ON PAGE 1
            |--------------------------------------------------------------------------
            */

            if (currentPage === 1) {

                renderHistory(
                    Array.isArray(result.recent_logs)
                        ? result.recent_logs
                        : []
                );
            }

        })

        .catch(function (error) {

            console.error(
                '[SOIL DATA] API ERROR:',
                error
            );


            if (apiStatus) {

                apiStatus.style.color =
                    '#dc3545';

                apiStatus.innerText =
                    'API connection error: ' +
                    error.message;
            }

        });
    }


    /*
    |--------------------------------------------------------------------------
    | START
    |--------------------------------------------------------------------------
    */

    loadTelemetry();


    /*
    |--------------------------------------------------------------------------
    | AUTO REFRESH
    |--------------------------------------------------------------------------
    */

    setInterval(
        loadTelemetry,
        2000
    );

})();

</script>