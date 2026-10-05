<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "../config/db_connect.php";

date_default_timezone_set('Asia/Manila');

$role = strtolower(trim($_SESSION['role'] ?? 'farmer'));
$user_id = intval($_SESSION['user_id'] ?? 0);

$latest = null;
$history_records = [];
$assigned_device_id = null;


/*
|--------------------------------------------------------------------------
| VERIFY LOGGED-IN USER
|--------------------------------------------------------------------------
*/

try {

    if ($user_id > 0) {

        $userStmt = $conn->prepare("
            SELECT id, role
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

        $userStmt->execute([$user_id]);

        $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

        if ($userRow) {
            $role = strtolower(trim($userRow['role'] ?? $role));
        }
    }

} catch (PDOException $e) {

    // Keep page safe if user verification fails.
}


/*
|--------------------------------------------------------------------------
| GET ASSIGNED NODE FOR FARMER
|--------------------------------------------------------------------------
|
| EXACT SAME NODE-ASSIGNMENT LOGIC USED BY
| api/get_live_telemetry.php
|
| users.id
|     ↓
| sensor_data.user_id
|     ↓
| sensor_data.device_label
|     ↓
| soil_readings.device_id
|
*/

if ($role !== 'admin' && $user_id > 0) {

    try {

        $deviceStmt = $conn->prepare("
            SELECT device_label
            FROM sensor_data
            WHERE user_id = ?
              AND device_label IS NOT NULL
              AND TRIM(device_label) <> ''
            ORDER BY id DESC
            LIMIT 1
        ");

        $deviceStmt->execute([$user_id]);

        $deviceRow = $deviceStmt->fetch(PDO::FETCH_ASSOC);

        if ($deviceRow && !empty($deviceRow['device_label'])) {
            $assigned_device_id = trim($deviceRow['device_label']);
        }

    } catch (PDOException $e) {

        $assigned_device_id = null;
    }
}


/*
|--------------------------------------------------------------------------
| LATEST READING
|--------------------------------------------------------------------------
*/

try {

    if ($role === 'admin') {

        /*
        |--------------------------------------------------------------------------
        | ADMIN:
        | Can see latest system-wide telemetry.
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->query("
            SELECT *
            FROM soil_readings
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");

        $latest = $stmt->fetch(PDO::FETCH_ASSOC);

    } elseif ($assigned_device_id !== null) {

        /*
        |--------------------------------------------------------------------------
        | FARMER:
        | ONLY the assigned device.
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT *
            FROM soil_readings
            WHERE device_id = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");

        $stmt->execute([
            $assigned_device_id
        ]);

        $latest = $stmt->fetch(PDO::FETCH_ASSOC);

    } else {

        /*
        |--------------------------------------------------------------------------
        | FARMER WITHOUT ASSIGNMENT:
        | NEVER FALL BACK TO GLOBAL DATA.
        |--------------------------------------------------------------------------
        */

        $latest = null;
    }

} catch (PDOException $e) {

    $latest = null;
}


/*
|--------------------------------------------------------------------------
| HISTORICAL MOISTURE DATA
|--------------------------------------------------------------------------
*/

try {

    if ($role === 'admin') {

        $history_stmt = $conn->query("
            SELECT moisture, created_at
            FROM soil_readings
            ORDER BY created_at DESC, id DESC
            LIMIT 7
        ");

        $history_records = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($assigned_device_id !== null) {

        $history_stmt = $conn->prepare("
            SELECT moisture, created_at
            FROM soil_readings
            WHERE device_id = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 7
        ");

        $history_stmt->execute([
            $assigned_device_id
        ]);

        $history_records = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

    } else {

        $history_records = [];
    }

} catch (PDOException $e) {

    $history_records = [];
}


/*
|--------------------------------------------------------------------------
| CHART DATA
|--------------------------------------------------------------------------
*/

$history_records = array_reverse($history_records);

$chart_labels = [];
$chart_data = [];

foreach ($history_records as $rec) {

    if (!isset($rec['created_at'])) {
        continue;
    }

    $chart_labels[] = date(
        'M j, g:i A',
        strtotime($rec['created_at'])
    );

    $chart_data[] = isset($rec['moisture'])
        ? (float)$rec['moisture']
        : 0;
}


/*
|--------------------------------------------------------------------------
| FALLBACK IF NO DATA
|--------------------------------------------------------------------------
*/

if (empty($chart_data)) {

    $chart_labels = ['Awaiting Data'];
    $chart_data = [0];
}


/*
|--------------------------------------------------------------------------
| SOIL STATUS
|--------------------------------------------------------------------------
*/

$soilStatus = "OPTIMAL";

if ($latest) {

    $moisture = isset($latest['moisture'])
        ? (float)$latest['moisture']
        : null;

    $ph = isset($latest['ph'])
        ? (float)$latest['ph']
        : null;

    if (
        ($moisture !== null && ($moisture < 20 || $moisture > 80)) ||
        ($ph !== null && ($ph < 5.5 || $ph > 7.5))
    ) {
        $soilStatus = "WARNING";
    }
}

?>

<div class="home-view-grid">

<div class="summary-telemetry-strip">

    <div class="telemetry-chip">

        <span class="chip-label">
            Soil Moisture:
        </span>

        <span
            class="chip-val"
            id="home-val-moisture"
        >
            <?= $latest && isset($latest['moisture'])
                ? htmlspecialchars(
                    number_format((float)$latest['moisture'], 1)
                ) . '%'
                : '--'
            ?>
        </span>

    </div>


    <div class="telemetry-chip">

        <span class="chip-label">
            pH Level:
        </span>

        <span
            class="chip-val"
            id="home-val-ph"
        >
            <?= $latest && isset($latest['ph'])
                ? htmlspecialchars(
                    number_format((float)$latest['ph'], 1)
                )
                : '--'
            ?>
        </span>

    </div>


    <div class="telemetry-chip">

        <span class="chip-label">
            Temperature:
        </span>

        <span
            class="chip-val"
            id="home-val-temp"
        >
            <?= $latest && isset($latest['temperature'])
                ? htmlspecialchars(
                    number_format((float)$latest['temperature'], 1)
                ) . '°C'
                : '--'
            ?>
        </span>

    </div>


    <div class="telemetry-chip">

        <span class="chip-label">
            System Mode:
        </span>

        <span class="chip-val sub-text-alert">
            <?= htmlspecialchars(ucfirst($role)) ?> Portal
        </span>

    </div>

</div>


<div class="npk-hero-card">

    <h3>
        Current Nutrient Composition
    </h3>

    <h1 id="home-val-npk">

        NPK:

        <?= $latest
            ? htmlspecialchars($latest['nitrogen'] ?? '--')
            . ' / '
            . htmlspecialchars($latest['phosphorus'] ?? '--')
            . ' / '
            . htmlspecialchars($latest['potassium'] ?? '--')
            : '-- -- --'
        ?>

    </h1>

    <div class="badge-row">

        <span
            id="home-val-badge"
            class="status-pill <?= $latest
                ? ($soilStatus === 'OPTIMAL'
                    ? 'optimal-green'
                    : 'warning-red')
                : ''
            ?>"
            style="<?= !$latest
                ? 'background: #e0e0e0; color: #666;'
                : ''
            ?>"
        >

            <?= $latest
                ? $soilStatus
                : 'No Data'
            ?>

        </span>

    </div>

</div>


<div class="insights-dashboard-split-row">

    <div class="action-alert-panel-card">

        <h3>
            Soil Status
        </h3>

        <h2 id="home-val-status-title">

            <?= $latest
                ? $soilStatus
                : 'Awaiting Streams'
            ?>

        </h2>


        <div
            class="nested-sub-recommends-box"
            id="home-val-status-box"
            style="border-left-color:
                <?= $latest
                    ? ($soilStatus === 'OPTIMAL'
                        ? '#4caf50'
                        : '#e65100')
                    : '#ccd4cc'
                ?>;"
        >

            <span class="muted-title">
                STATUS
            </span>

            <p id="home-val-status-desc">

                <?php if ($latest && isset($latest['created_at'])): ?>

                    Last updated:
                    <?= date(
                        'M j, g:i A',
                        strtotime($latest['created_at'])
                    ) ?>

                <?php else: ?>

                    System is ready. Awaiting data inputs.

                <?php endif; ?>

            </p>

        </div>

    </div>


    <div class="analytical-chart-card">

        <div
            style="
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 10px;
            "
        >

            <h3 style="margin: 0;">
                Moisture Trend
            </h3>

            <span
                style="
                    font-size: 11px;
                    font-weight: 700;
                    color: #2e7d32;
                    display: flex;
                    align-items: center;
                    gap: 6px;
                "
            >

                <span
                    style="
                        display: inline-block;
                        width: 8px;
                        height: 8px;
                        border-radius: 50%;
                        background-color: #2e7d32;
                        box-shadow:
                            0 0 0 0
                            rgba(46, 125, 50, 0.7);
                        animation: livePulse 1.8s infinite;
                    "
                ></span>

                LIVE REAL-TIME STREAM

            </span>

        </div>


        <div class="canvas-chart-wrapper">

            <canvas id="moistureTrendChart"></canvas>

        </div>

    </div>

</div>

</div>


<script>

const ctx = document
    .getElementById('moistureTrendChart')
    .getContext('2d');


const moistureChart = new Chart(ctx, {

    type: 'line',

    data: {

        labels: <?= json_encode($chart_labels) ?>,

        datasets: [{

            label: 'Moisture Level (%)',

            data: <?= json_encode($chart_data) ?>,

            borderColor: '#0b8a47',

            backgroundColor: 'rgba(11, 138, 71, 0.05)',

            borderWidth: 2,

            fill: true,

            tension: 0.3

        }]

    },

    options: {

        responsive: true,

        maintainAspectRatio: false,

        animation: false,

        plugins: {

            legend: {
                display: false
            }

        },

        scales: {

            y: {

                min: 0,

                max: 100,

                grid: {
                    color: '#e2e8e2'
                },

                ticks: {

                    callback: function(value) {
                        return value + '%';
                    }

                }

            },

            x: {

                grid: {
                    display: false
                }

            }

        }

    }

});


/*
|--------------------------------------------------------------------------
| REAL-TIME TELEMETRY
|--------------------------------------------------------------------------
|
| The API itself enforces the logged-in user's assigned node.
| This page does NOT send user_id or device_id.
|
*/

(function() {

    let lastHomeId = <?= $latest['id'] ?? 0 ?>;


    function updateHomeTelemetry() {

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

            if (
                res.status !== 'success' ||
                !res.data
            ) {

                return;
            }


            const d = res.data;


            const mEl =
                document.getElementById(
                    'home-val-moisture'
                );

            const phEl =
                document.getElementById(
                    'home-val-ph'
                );

            const tEl =
                document.getElementById(
                    'home-val-temp'
                );

            const npkEl =
                document.getElementById(
                    'home-val-npk'
                );

            const badgeEl =
                document.getElementById(
                    'home-val-badge'
                );

            const titleEl =
                document.getElementById(
                    'home-val-status-title'
                );

            const boxEl =
                document.getElementById(
                    'home-val-status-box'
                );

            const descEl =
                document.getElementById(
                    'home-val-status-desc'
                );


            if (
                mEl &&
                d.moisture !== null &&
                d.moisture !== undefined
            ) {

                mEl.textContent =
                    parseFloat(d.moisture)
                    .toFixed(1) + '%';
            }


            if (
                phEl &&
                d.ph !== null &&
                d.ph !== undefined
            ) {

                phEl.textContent =
                    parseFloat(d.ph)
                    .toFixed(1);
            }


            if (
                tEl &&
                d.temperature !== null &&
                d.temperature !== undefined
            ) {

                tEl.textContent =
                    parseFloat(d.temperature)
                    .toFixed(1) + '°C';
            }


            if (npkEl) {

                npkEl.textContent =
                    'NPK: ' +
                    (d.nitrogen ?? '--') +
                    ' / ' +
                    (d.phosphorus ?? '--') +
                    ' / ' +
                    (d.potassium ?? '--');
            }


            let status = "OPTIMAL";
            let isWarning = false;


            if (
                (
                    d.moisture !== null &&
                    d.moisture !== undefined &&
                    (
                        parseFloat(d.moisture) < 20 ||
                        parseFloat(d.moisture) > 80
                    )
                ) ||
                (
                    d.ph !== null &&
                    d.ph !== undefined &&
                    (
                        parseFloat(d.ph) < 5.0 ||
                        parseFloat(d.ph) > 8.0
                    )
                )
            ) {

                status = "WARNING";
                isWarning = true;
            }


            if (badgeEl) {

                badgeEl.textContent = status;

                badgeEl.className =
                    'status-pill ' +
                    (
                        isWarning
                            ? 'warning-red'
                            : 'optimal-green'
                    );

                badgeEl.style = '';
            }


            if (titleEl) {
                titleEl.textContent = status;
            }


            if (boxEl) {

                boxEl.style.borderLeftColor =
                    isWarning
                        ? '#e65100'
                        : '#4caf50';
            }


            if (descEl) {

                descEl.textContent =
                    'Last updated: ' +
                    (
                        d.formatted_time ||
                        'Just now'
                    );
            }


            if (
                d.id &&
                d.id !== lastHomeId &&
                d.chart_labels &&
                d.chart_data
            ) {

                lastHomeId = d.id;

                moistureChart.data.labels =
                    d.chart_labels;

                moistureChart
                    .data
                    .datasets[0]
                    .data = d.chart_data;

                moistureChart.update('none');
            }

        })

        .catch(err => {

            console.debug(
                'Home telemetry live fetch:',
                err
            );

        });

    }


    setInterval(
        updateHomeTelemetry,
        2000
    );

})();

</script>