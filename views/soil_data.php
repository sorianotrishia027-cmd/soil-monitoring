
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

} catch (PDOException $e) {

    echo '<div style="padding:20px;color:#dc3545;">Unable to verify user account.</div>';
    exit;
}


/*
|--------------------------------------------------------------------------
| RESOLVE ASSIGNED NODE
|--------------------------------------------------------------------------
|
| EXACT SAME LOGIC USED BY:
|
| api/get_live_telemetry.php
| views/home.php
| devices_manage.php
|
| users.id
|     ↓
| sensor_data.user_id
|     ↓
| sensor_data.device_label
|     ↓
| soil_readings.device_id
|
| IMPORTANT:
| We do NOT accept user_id or device_id from GET/POST.
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
            ORDER BY id DESC
            LIMIT 1
        ");

        $latest = $stmtLatest->fetch(PDO::FETCH_ASSOC);

    } elseif ($assigned_device_id !== null) {

        $stmtLatest = $conn->prepare("
            SELECT *
            FROM soil_readings
            WHERE device_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");

        $stmtLatest->execute([
            $assigned_device_id
        ]);

        $latest = $stmtLatest->fetch(PDO::FETCH_ASSOC);

    } else {

        /*
         * Farmer without assignment must NEVER
         * receive global telemetry.
         */

        $latest = null;
    }

} catch (PDOException $e) {

    $latest = null;
}


/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

$limit = 15;

$page = isset($_GET['history_page'])
    ? max(1, intval($_GET['history_page']))
    : 1;

$offset = ($page - 1) * $limit;


/*
|--------------------------------------------------------------------------
| COUNT TELEMETRY RECORDS
|--------------------------------------------------------------------------
*/

$totalRows = 0;
$totalPages = 1;

try {

    if ($role === 'admin') {

        $stmtCount = $conn->query("
            SELECT COUNT(*) AS total
            FROM soil_readings
        ");

        $totalRows = (int)(
            $stmtCount->fetch(PDO::FETCH_ASSOC)['total'] ?? 0
        );

    } elseif ($assigned_device_id !== null) {

        $stmtCount = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM soil_readings
            WHERE device_id = ?
        ");

        $stmtCount->execute([
            $assigned_device_id
        ]);

        $totalRows = (int)(
            $stmtCount->fetch(PDO::FETCH_ASSOC)['total'] ?? 0
        );
    }

    $totalPages = max(
        1,
        (int)ceil($totalRows / $limit)
    );

} catch (PDOException $e) {

    $totalRows = 0;
    $totalPages = 1;
}


/*
|--------------------------------------------------------------------------
| PREVENT INVALID PAGE
|--------------------------------------------------------------------------
*/

if ($page > $totalPages) {

    $page = $totalPages;

    $offset = ($page - 1) * $limit;
}


/*
|--------------------------------------------------------------------------
| FETCH HISTORY
|--------------------------------------------------------------------------
*/

$historyLogs = [];

try {

    if ($role === 'admin') {

        $stmtLogs = $conn->prepare("
            SELECT *
            FROM soil_readings
            ORDER BY id DESC
            LIMIT :limit OFFSET :offset
        ");

        $stmtLogs->bindValue(
            ':limit',
            $limit,
            PDO::PARAM_INT
        );

        $stmtLogs->bindValue(
            ':offset',
            $offset,
            PDO::PARAM_INT
        );

        $stmtLogs->execute();

    } elseif ($assigned_device_id !== null) {

        $stmtLogs = $conn->prepare("
            SELECT *
            FROM soil_readings
            WHERE device_id = ?
            ORDER BY id DESC
            LIMIT :limit OFFSET :offset
        ");

        $stmtLogs->bindValue(
            1,
            $assigned_device_id,
            PDO::PARAM_STR
        );

        $stmtLogs->bindValue(
            ':limit',
            $limit,
            PDO::PARAM_INT
        );

        $stmtLogs->bindValue(
            ':offset',
            $offset,
            PDO::PARAM_INT
        );

        $stmtLogs->execute();

    } else {

        $stmtLogs = null;
    }

    if ($stmtLogs) {
        $historyLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {

    $historyLogs = [];
}


/*
|--------------------------------------------------------------------------
| SAFE FIELD EXTRACTION
|--------------------------------------------------------------------------
*/

$valMoisture = isset($latest['moisture'])
    ? $latest['moisture']
    : ($latest['soil_moisture'] ?? null);

$valPh = isset($latest['ph'])
    ? $latest['ph']
    : ($latest['ph_level'] ?? null);

$valN = isset($latest['nitrogen'])
    ? $latest['nitrogen']
    : ($latest['n'] ?? null);

$valP = isset($latest['phosphorus'])
    ? $latest['phosphorus']
    : ($latest['p'] ?? null);

$valK = isset($latest['potassium'])
    ? $latest['potassium']
    : ($latest['k'] ?? null);

$valTemp = isset($latest['temperature'])
    ? $latest['temperature']
    : ($latest['temp'] ?? null);


/*
|--------------------------------------------------------------------------
| STATUS HELPER
|--------------------------------------------------------------------------
*/

function getStatusStyle($val, $min, $max) {

    if ($val === null) {

        return [
            'color' => '#6c757d',
            'border' => '#6c757d',
            'status' => 'No Data'
        ];
    }

    $fVal = floatval($val);

    if ($fVal < $min) {

        return [
            'color' => '#dc3545',
            'border' => '#dc3545',
            'status' => 'Critical (Low)'
        ];

    } elseif ($fVal > $max) {

        return [
            'color' => '#e65100',
            'border' => '#ff9800',
            'status' => 'Warning (High)'
        ];

    } else {

        return [
            'color' => '#198754',
            'border' => '#198754',
            'status' => 'Optimal'
        ];
    }
}


$mStyle = getStatusStyle(
    $valMoisture,
    30,
    60
);

$phStyle = getStatusStyle(
    $valPh,
    5.0,
    7.5
);

$nStyle = getStatusStyle(
    $valN,
    20,
    50
);

$pStyle = getStatusStyle(
    $valP,
    10,
    30
);

$kStyle = getStatusStyle(
    $valK,
    15,
    50
);

$tStyle = getStatusStyle(
    $valTemp,
    20,
    32
);


$npkNoResponse = (
    $valN !== null &&
    $valP !== null &&
    $valK !== null &&
    floatval($valN) == 0 &&
    floatval($valP) == 0 &&
    floatval($valK) == 0
);

?>

<style>
@keyframes livePulse {
    0% {
        transform: scale(0.95);
        box-shadow: 0 0 0 0 rgba(46, 125, 50, 0.7);
    }

    70% {
        transform: scale(1.1);
        box-shadow: 0 0 0 6px rgba(46, 125, 50, 0);
    }

    100% {
        transform: scale(0.95);
        box-shadow: 0 0 0 0 rgba(46, 125, 50, 0);
    }
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

<div class="soil-header">

    <div>

        <h3 style="color: var(--primary-color, #2e7d32); font-weight: 700; font-size: 1.4rem; margin-bottom: 4px;">
            My Soil Telemetry & Analysis
        </h3>

        <p style="color: #657765; font-size: 0.9rem; margin: 0;">
            Logged field readings evaluated against optimal agricultural thresholds.
        </p>

    </div>

    <div
        style="background: #e8f5e9; color: #1b5e20; padding: 6px 14px; border-radius: 20px; font-weight: 700; font-size: 12px; white-space: nowrap; display: flex; align-items: center; gap: 8px;"
        id="soil-live-badge"
    >

        <span
            style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background-color: #2e7d32; animation: livePulse 1.8s infinite;"
        ></span>

        LIVE REAL-TIME STREAM

    </div>

</div>


<?php if ($role !== 'admin' && $assigned_device_id === null): ?>

    <div style="
        background:#fff3cd;
        border:1px solid #ffe69c;
        color:#664d03;
        padding:18px;
        border-radius:12px;
        margin-bottom:20px;
    ">
        No hardware node is currently assigned to this farmer account.
        Please contact the administrator to assign a node.
    </div>

<?php endif; ?>


<div class="soil-metrics-grid">

    <div
        class="soil-metric-card"
        id="soil-card-moisture"
        style="border-left: 5px solid <?= $mStyle['border'] ?>;"
    >

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">

            <span style="font-size:0.75rem;text-transform:uppercase;color:#657765;font-weight:700;">
                Soil Moisture
            </span>

            <span
                id="soil-status-moisture"
                style="font-size:0.7rem;font-weight:600;padding:2px 6px;border-radius:6px;background:<?= $mStyle['color'] ?>15;color:<?= $mStyle['color'] ?>;"
            >
                <?= $mStyle['status'] ?>
            </span>

        </div>

        <h2
            id="soil-val-moisture"
            style="margin:5px 0;color:<?= $mStyle['color'] ?>;font-size:1.6rem;"
        >
            <?= $valMoisture !== null
                ? htmlspecialchars(number_format(floatval($valMoisture), 1)) . '%'
                : '--'
            ?>
        </h2>

        <span style="font-size:0.75rem;color:#888;">
            Target: 30% - 60%
        </span>

    </div>


    <div
        class="soil-metric-card"
        id="soil-card-ph"
        style="border-left:5px solid <?= $phStyle['border'] ?>;"
    >

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">

            <span style="font-size:0.75rem;text-transform:uppercase;color:#657765;font-weight:700;">
                pH Level
            </span>

            <span
                id="soil-status-ph"
                style="font-size:0.7rem;font-weight:600;padding:2px 6px;border-radius:6px;background:<?= $phStyle['color'] ?>15;color:<?= $phStyle['color'] ?>;"
            >
                <?= $phStyle['status'] ?>
            </span>

        </div>

        <h2
            id="soil-val-ph"
            style="margin:5px 0;color:<?= $phStyle['color'] ?>;font-size:1.6rem;"
        >
            <?= $valPh !== null
                ? htmlspecialchars(number_format(floatval($valPh), 1))
                : '--'
            ?>
        </h2>

        <span style="font-size:0.75rem;color:#888;">
            Target: 5.0 - 7.5
        </span>

    </div>


    <div
        class="soil-metric-card"
        id="soil-card-n"
        style="border-left:5px solid <?= $npkNoResponse ? '#6c757d' : $nStyle['border'] ?>;"
    >

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">

            <span style="font-size:0.75rem;text-transform:uppercase;color:#657765;font-weight:700;">
                Nitrogen (N)
            </span>

            <span
                id="soil-status-n"
                style="
                    font-size:0.7rem;
                    font-weight:600;
                    padding:2px 6px;
                    border-radius:6px;
                    background:<?= $npkNoResponse ? '#6c757d15' : $nStyle['color'].'15' ?>;
                    color:<?= $npkNoResponse ? '#6c757d' : $nStyle['color'] ?>;
                "
            >
                <?= $npkNoResponse ? 'No Response' : $nStyle['status'] ?>
            </span>

        </div>

        <h2
            id="soil-val-n"
            style="
                margin:5px 0;
                color:<?= $npkNoResponse ? '#6c757d' : $nStyle['color'] ?>;
                font-size:1.6rem;
            "
        >
            <?= $valN !== null ? htmlspecialchars($valN) : '--' ?>

            <span style="font-size:0.8rem;font-weight:normal;">
                mg/kg
            </span>

        </h2>

        <span style="font-size:0.75rem;color:#888;">
            Target: 20 - 50
        </span>

    </div>


    <div
        class="soil-metric-card"
        id="soil-card-p"
        style="border-left:5px solid <?= $npkNoResponse ? '#6c757d' : $pStyle['border'] ?>;"
    >

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">

            <span style="font-size:0.75rem;text-transform:uppercase;color:#657765;font-weight:700;">
                Phosphorus (P)
            </span>

            <span
                id="soil-status-p"
                style="
                    font-size:0.7rem;
                    font-weight:600;
                    padding:2px 6px;
                    border-radius:6px;
                    background:<?= $npkNoResponse ? '#6c757d15' : $pStyle['color'].'15' ?>;
                    color:<?= $npkNoResponse ? '#6c757d' : $pStyle['color'] ?>;
                "
            >
                <?= $npkNoResponse ? 'No Response' : $pStyle['status'] ?>
            </span>

        </div>

        <h2
            id="soil-val-p"
            style="
                margin:5px 0;
                color:<?= $npkNoResponse ? '#6c757d' : $pStyle['color'] ?>;
                font-size:1.6rem;
            "
        >
            <?= $valP !== null ? htmlspecialchars($valP) : '--' ?>

            <span style="font-size:0.8rem;font-weight:normal;">
                mg/kg
            </span>

        </h2>

        <span style="font-size:0.75rem;color:#888;">
            Target: 10 - 30
        </span>

    </div>


    <div
        class="soil-metric-card"
        id="soil-card-k"
        style="border-left:5px solid <?= $npkNoResponse ? '#6c757d' : $kStyle['border'] ?>;"
    >

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">

            <span style="font-size:0.75rem;text-transform:uppercase;color:#657765;font-weight:700;">
                Potassium (K)
            </span>

            <span
                id="soil-status-k"
                style="
                    font-size:0.7rem;
                    font-weight:600;
                    padding:2px 6px;
                    border-radius:6px;
                    background:<?= $npkNoResponse ? '#6c757d15' : $kStyle['color'].'15' ?>;
                    color:<?= $npkNoResponse ? '#6c757d' : $kStyle['color'] ?>;
                "
            >
                <?= $npkNoResponse ? 'No Response' : $kStyle['status'] ?>
            </span>

        </div>

        <h2
            id="soil-val-k"
            style="
                margin:5px 0;
                color:<?= $npkNoResponse ? '#6c757d' : $kStyle['color'] ?>;
                font-size:1.6rem;
            "
        >
            <?= $valK !== null ? htmlspecialchars($valK) : '--' ?>

            <span style="font-size:0.8rem;font-weight:normal;">
                mg/kg
            </span>

        </h2>

        <span style="font-size:0.75rem;color:#888;">
            Target: 15 - 50
        </span>

    </div>


    <div
        class="soil-metric-card"
        id="soil-card-temp"
        style="border-left:5px solid <?= $tStyle['border'] ?>;"
    >

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">

            <span style="font-size:0.75rem;text-transform:uppercase;color:#657765;font-weight:700;">
                Temperature
            </span>

            <span
                id="soil-status-temp"
                style="font-size:0.7rem;font-weight:600;padding:2px 6px;border-radius:6px;background:<?= $tStyle['color'] ?>15;color:<?= $tStyle['color'] ?>;"
            >
                <?= $tStyle['status'] ?>
            </span>

        </div>

        <h2
            id="soil-val-temp"
            style="margin:5px 0;color:<?= $tStyle['color'] ?>;font-size:1.6rem;"
        >
            <?= $valTemp !== null
                ? htmlspecialchars(number_format(floatval($valTemp), 1)) . '°C'
                : '--'
            ?>
        </h2>

        <span style="font-size:0.75rem;color:#888;">
            Target: 20°C - 32°C
        </span>

    </div>

</div>


<div
    style="
        background:#ffffff;
        padding:20px;
        border-radius:16px;
        box-shadow:0 4px 20px rgba(0,0,0,0.03);
        margin-bottom:25px;
        border:1px solid #d9e2d9;
    "
>

    <h4 style="margin-top:0;color:#2c3e2c;font-size:1.05rem;font-weight:600;">
        Soil Parameter Criteria Reference
    </h4>

    <p style="color:#657765;font-size:0.85rem;margin-bottom:15px;">
        Color legend:
        <span style="color:#dc3545;font-weight:600;">Red (Critical/Low)</span>,
        <span style="color:#198754;font-weight:600;">Green (Optimal)</span>,
        <span style="color:#ff9800;font-weight:600;">Orange (High/Excess)</span>.
    </p>

    <div class="criteria-grid">

        <div>

            <strong style="color:#2c3e2c;">
                Soil Moisture
            </strong>

            <ul style="padding-left:16px;margin:4px 0 0;color:#556b55;">
                <li><strong style="color:#dc3545;">&lt; 30%:</strong> Dry</li>
                <li><strong style="color:#198754;">30% – 60%:</strong> Optimal</li>
                <li><strong style="color:#ff9800;">&gt; 60%:</strong> Wet</li>
            </ul>

        </div>


        <div>

            <strong style="color:#2c3e2c;">
                Soil pH Level
            </strong>

            <ul style="padding-left:16px;margin:4px 0 0;color:#556b55;">
                <li><strong style="color:#dc3545;">&lt; 5.0:</strong> Acidic</li>
                <li><strong style="color:#198754;">5.0 – 7.5:</strong> Optimal</li>
                <li><strong style="color:#ff9800;">&gt; 7.5:</strong> Alkaline</li>
            </ul>

        </div>


        <div>

            <strong style="color:#2c3e2c;">
                Temperature
            </strong>

            <ul style="padding-left:16px;margin:4px 0 0;color:#556b55;">
                <li><strong style="color:#dc3545;">&lt; 20°C:</strong> Cool</li>
                <li><strong style="color:#198754;">20°C – 32°C:</strong> Optimal</li>
                <li><strong style="color:#ff9800;">&gt; 32°C:</strong> Heat Stress</li>
            </ul>

        </div>


        <div>

            <strong style="color:#2c3e2c;">
                Nutrients (N-P-K)
            </strong>

            <div style="margin-top:4px;color:#556b55;font-size:0.82rem;">
                <strong>N:</strong> 20–50 mg/kg<br>
                <strong>P:</strong> 10–30 mg/kg<br>
                <strong>K:</strong> 15–50 mg/kg
            </div>

        </div>

    </div>

</div>


<div class="table-card">

    <div
        style="
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            flex-wrap:wrap;
            gap:10px;
            margin-bottom:15px;
        "
    >

        <div>

            <h4 style="margin:0;color:#2c3e2c;font-size:1.05rem;font-weight:600;">
                Recent Telemetry History Logs
            </h4>

            <p style="color:#657765;font-size:0.85rem;margin:2px 0 0;">
                Automatic records logged from field sensors.
            </p>

        </div>

        <div
            style="font-size:0.82rem;color:#657765;font-weight:500;"
            id="soil-history-page-info"
        >
            Page <?= $page ?> of <?= max(1, $totalPages) ?>
            (Total: <?= $totalRows ?>)
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

                        $lMoisture = $log['moisture']
                            ?? $log['soil_moisture']
                            ?? 0;

                        $lPh = $log['ph']
                            ?? $log['ph_level']
                            ?? 0;

                        $lN = $log['nitrogen']
                            ?? $log['n']
                            ?? 0;

                        $lP = $log['phosphorus']
                            ?? $log['p']
                            ?? 0;

                        $lK = $log['potassium']
                            ?? $log['k']
                            ?? 0;

                        $lTemp = $log['temperature']
                            ?? $log['temp']
                            ?? 0;

                        $rowMStyle = getStatusStyle(
                            $lMoisture,
                            30,
                            60
                        );

                        $rowPhStyle = getStatusStyle(
                            $lPh,
                            5.0,
                            7.5
                        );

                        ?>

                        <tr>

                            <td style="color:#556b55;">
                                <?= isset($log['created_at'])
                                    ? date(
                                        "M j, Y - g:i A",
                                        strtotime($log['created_at'])
                                    )
                                    : 'N/A'
                                ?>
                            </td>

                            <td style="font-weight:600;color:<?= $rowMStyle['color'] ?>;">
                                <?= number_format(floatval($lMoisture), 1) ?>%
                            </td>

                            <td style="font-weight:600;color:<?= $rowPhStyle['color'] ?>;">
                                <?= number_format(floatval($lPh), 1) ?>
                            </td>

                            <td style="color:#2c3e2c;">
                                <?= htmlspecialchars($lN) ?> mg/kg
                            </td>

                            <td style="color:#2c3e2c;">
                                <?= htmlspecialchars($lP) ?> mg/kg
                            </td>

                            <td style="color:#2c3e2c;">
                                <?= htmlspecialchars($lK) ?> mg/kg
                            </td>

                            <td style="color:#2c3e2c;">
                                <?= number_format(floatval($lTemp), 1) ?>°C
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>

                        <td
                            colspan="7"
                            style="padding:25px;text-align:center;color:#657765;"
                        >
                            No sensor logs recorded for this account.
                        </td>

                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>


    <?php if ($totalPages > 1):

        $params = $_GET;

        unset($params['history_page']);

        $queryBase = !empty($params)
            ? '?' . http_build_query($params) . '&'
            : '?';

    ?>

        <div class="google-pagination-container">

            <div class="google-pagination">

                <a
                    href="<?= $queryBase ?>history_page=<?= max(1, $page - 1) ?>"
                    class="gp-btn <?= ($page <= 1) ? 'disabled' : '' ?>"
                >
                    Previous
                </a>


                <?php

                $range = 2;
                $lastShown = 0;

                for ($i = 1; $i <= $totalPages; $i++):

                    $showPage = (
                        $i == 1 ||
                        $i == $totalPages ||
                        ($i >= $page - $range && $i <= $page + $range)
                    );

                    if ($showPage):

                        if (
                            $lastShown > 0 &&
                            $i > $lastShown + 1
                        ) {
                            echo '<span class="gp-ellipsis">...</span>';
                        }

                        $activeClass =
                            ($i == $page)
                            ? 'active'
                            : '';

                        echo '<a href="' .
                            htmlspecialchars(
                                $queryBase .
                                'history_page=' .
                                $i
                            ) .
                            '" class="gp-btn ' .
                            $activeClass .
                            '">' .
                            $i .
                            '</a>';

                        $lastShown = $i;

                    endif;

                endfor;

                ?>


                <a
                    href="<?= $queryBase ?>history_page=<?= min($totalPages, $page + 1) ?>"
                    class="gp-btn <?= ($page >= $totalPages) ? 'disabled' : '' ?>"
                >
                    Next
                </a>

            </div>

        </div>

    <?php endif; ?>

</div>

</div>


<script>
(function() {

    let lastReadingId =
        <?= (int)($latest['id'] ?? 0) ?>;


    function getHealthStatus(
        val,
        min,
        max
    ) {

        if (
            val === null ||
            val === undefined ||
            isNaN(val)
        ) {

            return {
                color: '#6c757d',
                border: '#6c757d',
                status: 'No Data'
            };
        }

        const num = parseFloat(val);

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


    function updateCard(
        cardId,
        statusId,
        valId,
        valueText,
        style
    ) {

        const card =
            document.getElementById(cardId);

        const statusEl =
            document.getElementById(statusId);

        const valEl =
            document.getElementById(valId);


        if (card) {

            card.style.borderLeft =
                `5px solid ${style.border}`;
        }


        if (statusEl) {

            statusEl.innerText =
                style.status;

            statusEl.style.color =
                style.color;

            statusEl.style.backgroundColor =
                style.color + '15';
        }


        if (valEl) {

            valEl.innerHTML =
                valueText;

            valEl.style.color =
                style.color;
        }
    }


    function fetchLiveTelemetry() {

        fetch(
            'api/get_live_telemetry.php?_=' +
            Date.now(),
            {
                credentials: 'same-origin'
            }
        )

        .then(res => {

            if (!res.ok) {

                throw new Error(
                    'HTTP ' + res.status
                );
            }

            return res.json();

        })

        .then(res => {

            /*
             * The API is already session-scoped.
             * No user_id/device_id is sent from JavaScript.
             */

            if (
                res.status !== 'success' ||
                !res.data
            ) {

                return;
            }


            const d = res.data;


            const mStyle =
                getHealthStatus(
                    d.moisture,
                    30,
                    60
                );


            const phStyle =
                getHealthStatus(
                    d.ph,
                    5.0,
                    7.5
                );


            const tStyle =
                getHealthStatus(
                    d.temperature,
                    20,
                    32
                );


            updateCard(
                'soil-card-moisture',
                'soil-status-moisture',
                'soil-val-moisture',
                d.moisture !== null
                    ? parseFloat(
                        d.moisture
                    ).toFixed(1) + '%'
                    : '--',
                mStyle
            );


            updateCard(
                'soil-card-ph',
                'soil-status-ph',
                'soil-val-ph',
                d.ph !== null
                    ? parseFloat(
                        d.ph
                    ).toFixed(1)
                    : '--',
                phStyle
            );


            updateCard(
                'soil-card-temp',
                'soil-status-temp',
                'soil-val-temp',
                d.temperature !== null
                    ? parseFloat(
                        d.temperature
                    ).toFixed(1) + '°C'
                    : '--',
                tStyle
            );


            if (d.npk_online) {

                const nStyle =
                    getHealthStatus(
                        d.nitrogen,
                        20,
                        50
                    );


                const pStyle =
                    getHealthStatus(
                        d.phosphorus,
                        10,
                        30
                    );


                const kStyle =
                    getHealthStatus(
                        d.potassium,
                        15,
                        50
                    );


                updateCard(
                    'soil-card-n',
                    'soil-status-n',
                    'soil-val-n',
                    `${d.nitrogen} <span style="font-size:0.8rem;font-weight:normal;">mg/kg</span>`,
                    nStyle
                );


                updateCard(
                    'soil-card-p',
                    'soil-status-p',
                    'soil-val-p',
                    `${d.phosphorus} <span style="font-size:0.8rem;font-weight:normal;">mg/kg</span>`,
                    pStyle
                );


                updateCard(
                    'soil-card-k',
                    'soil-status-k',
                    'soil-val-k',
                    `${d.potassium} <span style="font-size:0.8rem;font-weight:normal;">mg/kg</span>`,
                    kStyle
                );


            } else {

                const offStyle = {
                    color: '#6c757d',
                    border: '#6c757d',
                    status: 'No Response'
                };


                updateCard(
                    'soil-card-n',
                    'soil-status-n',
                    'soil-val-n',
                    `0 <span style="font-size:0.8rem;font-weight:normal;">mg/kg</span>`,
                    offStyle
                );


                updateCard(
                    'soil-card-p',
                    'soil-status-p',
                    'soil-val-p',
                    `0 <span style="font-size:0.8rem;font-weight:normal;">mg/kg</span>`,
                    offStyle
                );


                updateCard(
                    'soil-card-k',
                    'soil-status-k',
                    'soil-val-k',
                    `0 <span style="font-size:0.8rem;font-weight:normal;">mg/kg</span>`,
                    offStyle
                );
            }


            /*
             * IMPORTANT:
             *
             * recent_logs comes from the already
             * session-filtered get_live_telemetry.php.
             *
             * Therefore a farmer can only receive
             * history records for their assigned node.
             */

            if (
                d.id &&
                Number(d.id) !== Number(lastReadingId)
            ) {

                lastReadingId =
                    Number(d.id);


                const tbody =
                    document.getElementById(
                        'soil-history-tbody'
                    );


                const pageInfoEl =
                    document.getElementById(
                        'soil-history-page-info'
                    );


                const isPageOne =
                    !window.location.search.includes(
                        'history_page'
                    ) ||
                    window.location.search.includes(
                        'history_page=1'
                    );


                if (
                    res.total_count !== undefined &&
                    pageInfoEl
                ) {

                    const totalPages =
                        Math.max(
                            1,
                            Math.ceil(
                                Number(
                                    res.total_count
                                ) / 15
                            )
                        );


                    const curPage =
                        <?= (int)$page ?>;


                    pageInfoEl.innerText =
                        `Page ${curPage} of ${totalPages} (Total: ${res.total_count})`;
                }


                if (
                    isPageOne &&
                    tbody &&
                    Array.isArray(
                        res.recent_logs
                    ) &&
                    res.recent_logs.length > 0
                ) {

                    let html = '';


                    res.recent_logs.forEach(
                        (log, index) => {

                            const rMStyle =
                                getHealthStatus(
                                    log.moisture,
                                    30,
                                    60
                                );


                            const rPhStyle =
                                getHealthStatus(
                                    log.ph,
                                    5.0,
                                    7.5
                                );


                            const isNewTop =
                                index === 0;


                            html += `
                                <tr style="${
                                    isNewTop
                                        ? 'background-color:#e8f5e9;transition:background-color 2.5s ease;'
                                        : ''
                                }">

                                    <td style="color:#556b55;">
                                        ${log.formatted_time ?? ''}
                                    </td>

                                    <td style="font-weight:600;color:${rMStyle.color};">
                                        ${parseFloat(log.moisture ?? 0).toFixed(1)}%
                                    </td>

                                    <td style="font-weight:600;color:${rPhStyle.color};">
                                        ${parseFloat(log.ph ?? 0).toFixed(1)}
                                    </td>

                                    <td style="color:#2c3e2c;">
                                        ${log.nitrogen ?? 0} mg/kg
                                    </td>

                                    <td style="color:#2c3e2c;">
                                        ${log.phosphorus ?? 0} mg/kg
                                    </td>

                                    <td style="color:#2c3e2c;">
                                        ${log.potassium ?? 0} mg/kg
                                    </td>

                                    <td style="color:#2c3e2c;">
                                        ${parseFloat(log.temperature ?? 0).toFixed(1)}°C
                                    </td>

                                </tr>
                            `;
                        }
                    );


                    tbody.innerHTML =
                        html;


                    const firstRow =
                        tbody.firstElementChild;


                    if (firstRow) {

                        setTimeout(
                            () => {
                                firstRow.style.backgroundColor =
                                    '';
                            },
                            2500
                        );
                    }
                }
            }

        })

        .catch(err => {

            console.debug(
                'Live telemetry fetch check:',
                err
            );

        });
    }


    fetchLiveTelemetry();


    setInterval(
        fetchLiveTelemetry,
        2000
    );

})();
</script>