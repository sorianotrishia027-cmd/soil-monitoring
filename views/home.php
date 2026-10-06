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

        $stmt = $conn->query("
            SELECT *
            FROM soil_readings
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");

        $latest = $stmt->fetch(PDO::FETCH_ASSOC);

    } elseif ($assigned_device_id !== null) {

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

        $latest = null;
    }

} catch (PDOException $e) {

    $latest = null;
}


/*
|--------------------------------------------------------------------------
| HISTORICAL ANALYTICAL DATA
|--------------------------------------------------------------------------
|
| Get the latest 20 records for:
|
| moisture
| ph
| temperature
| nitrogen
| phosphorus
| potassium
|
*/

try {

    if ($role === 'admin') {

        $history_stmt = $conn->query("
            SELECT
                id,
                moisture,
                ph,
                temperature,
                nitrogen,
                phosphorus,
                potassium,
                created_at
            FROM soil_readings
            ORDER BY created_at DESC, id DESC
            LIMIT 20
        ");

        $history_records =
            $history_stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($assigned_device_id !== null) {

        $history_stmt = $conn->prepare("
            SELECT
                id,
                moisture,
                ph,
                temperature,
                nitrogen,
                phosphorus,
                potassium,
                created_at
            FROM soil_readings
            WHERE device_id = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 20
        ");

        $history_stmt->execute([
            $assigned_device_id
        ]);

        $history_records =
            $history_stmt->fetchAll(PDO::FETCH_ASSOC);

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

$moisture_data = [];
$ph_data = [];
$temperature_data = [];
$nitrogen_data = [];
$phosphorus_data = [];
$potassium_data = [];

foreach ($history_records as $rec) {

    if (!isset($rec['created_at'])) {
        continue;
    }

    $chart_labels[] = date(
        'M j, g:i A',
        strtotime($rec['created_at'])
    );

    $moisture_data[] =
        isset($rec['moisture'])
            ? (float)$rec['moisture']
            : null;

    $ph_data[] =
        isset($rec['ph'])
            ? (float)$rec['ph']
            : null;

    $temperature_data[] =
        isset($rec['temperature'])
            ? (float)$rec['temperature']
            : null;

    $nitrogen_data[] =
        isset($rec['nitrogen'])
            ? (float)$rec['nitrogen']
            : null;

    $phosphorus_data[] =
        isset($rec['phosphorus'])
            ? (float)$rec['phosphorus']
            : null;

    $potassium_data[] =
        isset($rec['potassium'])
            ? (float)$rec['potassium']
            : null;
}


/*
|--------------------------------------------------------------------------
| FALLBACK IF NO DATA
|--------------------------------------------------------------------------
*/

if (empty($chart_labels)) {

    $chart_labels = ['Awaiting Data'];

    $moisture_data = [0];
    $ph_data = [0];
    $temperature_data = [0];
    $nitrogen_data = [0];
    $phosphorus_data = [0];
    $potassium_data = [0];
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
        ($moisture !== null &&
            ($moisture < 20 || $moisture > 80)) ||

        ($ph !== null &&
            ($ph < 5.5 || $ph > 7.5))
    ) {

        $soilStatus = "WARNING";
    }
}

?>

<div class="home-view-grid">


<!-- =========================================================
     SUMMARY TELEMETRY
     ========================================================= -->

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
                    number_format(
                        (float)$latest['moisture'],
                        1
                    )
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
                    number_format(
                        (float)$latest['ph'],
                        1
                    )
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
                    number_format(
                        (float)$latest['temperature'],
                        1
                    )
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

            <?= htmlspecialchars(
                ucfirst($role)
            ) ?>

            Portal

        </span>

    </div>

</div>


<!-- =========================================================
     NPK HERO
     ========================================================= -->

<div class="npk-hero-card">

    <h3>
        Current Nutrient Composition
    </h3>

    <h1 id="home-val-npk">

        NPK:

        <?= $latest
            ? htmlspecialchars(
                $latest['nitrogen'] ?? '--'
            )
            . ' / '
            . htmlspecialchars(
                $latest['phosphorus'] ?? '--'
            )
            . ' / '
            . htmlspecialchars(
                $latest['potassium'] ?? '--'
            )
            : '-- -- --'
        ?>

    </h1>


    <div class="badge-row">

        <span
            id="home-val-badge"
            class="status-pill <?= $latest
                ? (
                    $soilStatus === 'OPTIMAL'
                        ? 'optimal-green'
                        : 'warning-red'
                )
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


<!-- =========================================================
     STATUS + LIVE INFO
     ========================================================= -->

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
                    ? (
                        $soilStatus === 'OPTIMAL'
                            ? '#4caf50'
                            : '#e65100'
                    )
                    : '#ccd4cc'
                ?>;"
        >

            <span class="muted-title">
                STATUS
            </span>

            <p id="home-val-status-desc">

                <?php if (
                    $latest &&
                    isset($latest['created_at'])
                ): ?>

                    Last updated:

                    <?= date(
                        'M j, g:i A',
                        strtotime(
                            $latest['created_at']
                        )
                    ) ?>

                <?php else: ?>

                    System is ready.
                    Awaiting data inputs.

                <?php endif; ?>

            </p>

        </div>

    </div>


    <div class="action-alert-panel-card">

        <h3>
            Live Telemetry
        </h3>

        <p>
            Real-time soil sensor monitoring is active.
        </p>

        <div class="nested-sub-recommends-box">

            <span class="muted-title">
                NODE
            </span>

            <p>

                <?php if ($role === 'admin'): ?>

                    System-wide monitoring

                <?php elseif ($assigned_device_id): ?>

                    <?= htmlspecialchars(
                        $assigned_device_id
                    ) ?>

                <?php else: ?>

                    No node assigned

                <?php endif; ?>

            </p>

        </div>

    </div>

</div>


<!-- =========================================================
     ANALYTICAL DASHBOARD
     ========================================================= -->

<div class="analytical-section-header">

    <div>

        <h3>
            Analytical Dashboard
        </h3>

        <p>
            Historical analysis of soil and nutrient conditions.
        </p>

    </div>

    <span class="analytical-live-indicator">

        <span class="analytical-live-dot"></span>

        LIVE ANALYTICS

    </span>

</div>


<!-- =========================================================
     MOISTURE ANALYTICAL
     ========================================================= -->

<div class="analytical-chart-card">

    <div class="analytical-chart-header">

        <div>

            <h3>
                Soil Moisture Analysis
            </h3>

            <p>
                Moisture percentage over time.
            </p>

        </div>

        <span class="analytical-unit">
            %
        </span>

    </div>


    <div class="analytical-chart-wrapper">

        <canvas id="moistureTrendChart"></canvas>

    </div>

</div>


<!-- =========================================================
     PH + TEMPERATURE
     ========================================================= -->

<div class="analytical-chart-grid">


    <div class="analytical-chart-card">

        <div class="analytical-chart-header">

            <div>

                <h3>
                    pH Analysis
                </h3>

                <p>
                    Soil acidity and alkalinity trend.
                </p>

            </div>

            <span class="analytical-unit">
                pH
            </span>

        </div>


        <div class="analytical-chart-wrapper">

            <canvas id="phTrendChart"></canvas>

        </div>

    </div>


    <div class="analytical-chart-card">

        <div class="analytical-chart-header">

            <div>

                <h3>
                    Temperature Analysis
                </h3>

                <p>
                    Soil temperature trend.
                </p>

            </div>

            <span class="analytical-unit">
                °C
            </span>

        </div>


        <div class="analytical-chart-wrapper">

            <canvas id="temperatureTrendChart"></canvas>

        </div>

    </div>

</div>


<!-- =========================================================
     NPK ANALYTICAL
     ========================================================= -->

<div class="analytical-chart-grid">


    <!-- NITROGEN -->

    <div class="analytical-chart-card">

        <div class="analytical-chart-header">

            <div>

                <h3>
                    Nitrogen Analysis
                </h3>

                <p>
                    Nitrogen concentration over time.
                </p>

            </div>

            <span class="analytical-unit">
                mg/kg
            </span>

        </div>


        <div class="analytical-chart-wrapper">

            <canvas id="nitrogenTrendChart"></canvas>

        </div>

    </div>


    <!-- PHOSPHORUS -->

    <div class="analytical-chart-card">

        <div class="analytical-chart-header">

            <div>

                <h3>
                    Phosphorus Analysis
                </h3>

                <p>
                    Phosphorus concentration over time.
                </p>

            </div>

            <span class="analytical-unit">
                mg/kg
            </span>

        </div>


        <div class="analytical-chart-wrapper">

            <canvas id="phosphorusTrendChart"></canvas>

        </div>

    </div>

</div>


<!-- =========================================================
     POTASSIUM ANALYTICAL
     ========================================================= -->

<div class="analytical-chart-card">

    <div class="analytical-chart-header">

        <div>

            <h3>
                Potassium Analysis
            </h3>

            <p>
                Potassium concentration over time.
            </p>

        </div>

        <span class="analytical-unit">
            mg/kg
        </span>

    </div>


    <div class="analytical-chart-wrapper">

        <canvas id="potassiumTrendChart"></canvas>

    </div>

</div>


</div>


<script>

/*
|--------------------------------------------------------------------------
| INITIAL ANALYTICAL DATA
|--------------------------------------------------------------------------
*/

const initialChartLabels =
    <?= json_encode(
        $chart_labels,
        JSON_UNESCAPED_SLASHES
    ) ?>;


const initialMoistureData =
    <?= json_encode(
        $moisture_data,
        JSON_UNESCAPED_SLASHES
    ) ?>;


const initialPhData =
    <?= json_encode(
        $ph_data,
        JSON_UNESCAPED_SLASHES
    ) ?>;


const initialTemperatureData =
    <?= json_encode(
        $temperature_data,
        JSON_UNESCAPED_SLASHES
    ) ?>;


const initialNitrogenData =
    <?= json_encode(
        $nitrogen_data,
        JSON_UNESCAPED_SLASHES
    ) ?>;


const initialPhosphorusData =
    <?= json_encode(
        $phosphorus_data,
        JSON_UNESCAPED_SLASHES
    ) ?>;


const initialPotassiumData =
    <?= json_encode(
        $potassium_data,
        JSON_UNESCAPED_SLASHES
    ) ?>;


/*
|--------------------------------------------------------------------------
| CHART HELPER
|--------------------------------------------------------------------------
*/

function createAnalyticalChart(
    canvasId,
    label,
    labels,
    data,
    yTitle,
    minValue = undefined,
    maxValue = undefined
) {

    const canvas =
        document.getElementById(canvasId);

    if (!canvas) {
        return null;
    }

    const ctx =
        canvas.getContext('2d');


    const config = {

        type: 'line',

        data: {

            labels: labels,

            datasets: [{

                label: label,

                data: data,

                borderColor: '#0b8a47',

                backgroundColor:
                    'rgba(11, 138, 71, 0.05)',

                borderWidth: 2,

                pointRadius: 3,

                pointHoverRadius: 5,

                fill: true,

                tension: 0.3,

                spanGaps: true

            }]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            animation: false,

            interaction: {

                intersect: false,

                mode: 'index'

            },

            plugins: {

                legend: {

                    display: false

                },

                tooltip: {

                    callbacks: {

                        label: function(context) {

                            let value =
                                context.parsed.y;

                            if (
                                value === null ||
                                value === undefined
                            ) {
                                return label + ': --';
                            }

                            return label +
                                ': ' +
                                value;
                        }

                    }

                }

            },

            scales: {

                y: {

                    min: minValue,

                    max: maxValue,

                    title: {

                        display: true,

                        text: yTitle

                    },

                    grid: {

                        color: '#e2e8e2'

                    }

                },

                x: {

                    grid: {

                        display: false

                    },

                    ticks: {

                        maxRotation: 45,

                        minRotation: 0

                    }

                }

            }

        }

    };


    return new Chart(
        ctx,
        config
    );
}


/*
|--------------------------------------------------------------------------
| CREATE ANALYTICAL CHARTS
|--------------------------------------------------------------------------
*/

const moistureChart =
    createAnalyticalChart(
        'moistureTrendChart',
        'Moisture',
        initialChartLabels,
        initialMoistureData,
        'Moisture (%)',
        0,
        100
    );


const phChart =
    createAnalyticalChart(
        'phTrendChart',
        'pH',
        initialChartLabels,
        initialPhData,
        'pH Level',
        0,
        14
    );


const temperatureChart =
    createAnalyticalChart(
        'temperatureTrendChart',
        'Temperature',
        initialChartLabels,
        initialTemperatureData,
        'Temperature (°C)'
    );


const nitrogenChart =
    createAnalyticalChart(
        'nitrogenTrendChart',
        'Nitrogen',
        initialChartLabels,
        initialNitrogenData,
        'Nitrogen (mg/kg)'
    );


const phosphorusChart =
    createAnalyticalChart(
        'phosphorusTrendChart',
        'Phosphorus',
        initialChartLabels,
        initialPhosphorusData,
        'Phosphorus (mg/kg)'
    );


const potassiumChart =
    createAnalyticalChart(
        'potassiumTrendChart',
        'Potassium',
        initialChartLabels,
        initialPotassiumData,
        'Potassium (mg/kg)'
    );


/*
|--------------------------------------------------------------------------
| REAL-TIME TELEMETRY
|--------------------------------------------------------------------------
*/

(function() {

    let lastHomeId =
        <?= $latest['id'] ?? 0 ?>;


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


            /*
            |--------------------------------------------------------------------------
            | CURRENT VALUE ELEMENTS
            |--------------------------------------------------------------------------
            */

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


            /*
            |--------------------------------------------------------------------------
            | MOISTURE
            |--------------------------------------------------------------------------
            */

            if (
                mEl &&
                d.moisture !== null &&
                d.moisture !== undefined
            ) {

                mEl.textContent =
                    parseFloat(
                        d.moisture
                    ).toFixed(1) + '%';
            }


            /*
            |--------------------------------------------------------------------------
            | PH
            |--------------------------------------------------------------------------
            */

            if (
                phEl &&
                d.ph !== null &&
                d.ph !== undefined
            ) {

                phEl.textContent =
                    parseFloat(
                        d.ph
                    ).toFixed(1);
            }


            /*
            |--------------------------------------------------------------------------
            | TEMPERATURE
            |--------------------------------------------------------------------------
            */

            if (
                tEl &&
                d.temperature !== null &&
                d.temperature !== undefined
            ) {

                tEl.textContent =
                    parseFloat(
                        d.temperature
                    ).toFixed(1) + '°C';
            }


            /*
            |--------------------------------------------------------------------------
            | NPK
            |--------------------------------------------------------------------------
            */

            if (npkEl) {

                npkEl.textContent =
                    'NPK: ' +
                    (d.nitrogen ?? '--') +
                    ' / ' +
                    (d.phosphorus ?? '--') +
                    ' / ' +
                    (d.potassium ?? '--');
            }


            /*
            |--------------------------------------------------------------------------
            | SOIL STATUS
            |--------------------------------------------------------------------------
            */

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

                badgeEl.textContent =
                    status;

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

                titleEl.textContent =
                    status;
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


            /*
            |--------------------------------------------------------------------------
            | ADD NEW DATA TO ALL ANALYTICAL CHARTS
            |--------------------------------------------------------------------------
            */

            if (
                d.id &&
                d.id !== lastHomeId
            ) {

                lastHomeId = d.id;


                let newLabel =
                    d.formatted_time ||
                    new Date().toLocaleTimeString(
                        'en-PH',
                        {
                            hour: 'numeric',
                            minute: '2-digit'
                        }
                    );


                /*
                |--------------------------------------------------------------------------
                | LIMIT CHART HISTORY
                |--------------------------------------------------------------------------
                */

                const maxPoints = 20;


                function appendChartPoint(
                    chart,
                    value
                ) {

                    if (!chart) {
                        return;
                    }


                    chart.data.labels.push(
                        newLabel
                    );


                    if (
                        value === null ||
                        value === undefined ||
                        value === ''
                    ) {

                        chart
                            .data
                            .datasets[0]
                            .data
                            .push(null);

                    } else {

                        chart
                            .data
                            .datasets[0]
                            .data
                            .push(
                                parseFloat(value)
                            );
                    }


                    while (
                        chart.data.labels.length >
                        maxPoints
                    ) {

                        chart.data.labels.shift();

                        chart
                            .data
                            .datasets[0]
                            .data
                            .shift();
                    }


                    chart.update('none');
                }


                /*
                |--------------------------------------------------------------------------
                | UPDATE ALL ANALYTICAL CHARTS
                |--------------------------------------------------------------------------
                */

                appendChartPoint(
                    moistureChart,
                    d.moisture
                );


                appendChartPoint(
                    phChart,
                    d.ph
                );


                appendChartPoint(
                    temperatureChart,
                    d.temperature
                );


                appendChartPoint(
                    nitrogenChart,
                    d.nitrogen
                );


                appendChartPoint(
                    phosphorusChart,
                    d.phosphorus
                );


                appendChartPoint(
                    potassiumChart,
                    d.potassium
                );
            }

        })

        .catch(err => {

            console.debug(
                'Home telemetry live fetch:',
                err
            );

        });
    }


    /*
    |--------------------------------------------------------------------------
    | REFRESH EVERY 2 SECONDS
    |--------------------------------------------------------------------------
    */

    setInterval(
        updateHomeTelemetry,
        2000
    );

})();

</script>


<style>

/*
|--------------------------------------------------------------------------
| ANALYTICAL SECTION
|--------------------------------------------------------------------------
*/

.analytical-section-header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 20px;

    margin-top: 5px;

    margin-bottom: 15px;

    padding: 5px 2px;
}


.analytical-section-header h3 {

    margin: 0 0 4px 0;

    font-size: 20px;

    font-weight: 700;

    color: #111111;
}


.analytical-section-header p {

    margin: 0;

    font-size: 13px;

    color: #666666;
}


.analytical-live-indicator {

    display: inline-flex;

    align-items: center;

    gap: 7px;

    padding: 7px 11px;

    border-radius: 20px;

    background: #e8f5e9;

    color: #2e7d32;

    font-size: 10px;

    font-weight: 700;

    letter-spacing: 0.4px;

    white-space: nowrap;
}


.analytical-live-dot {

    width: 7px;

    height: 7px;

    border-radius: 50%;

    background: #2e7d32;

    animation: analyticalPulse 1.8s infinite;
}


@keyframes analyticalPulse {

    0% {
        box-shadow:
            0 0 0 0
            rgba(46, 125, 50, 0.5);
    }

    70% {
        box-shadow:
            0 0 0 6px
            rgba(46, 125, 50, 0);
    }

    100% {
        box-shadow:
            0 0 0 0
            rgba(46, 125, 50, 0);
    }
}


/*
|--------------------------------------------------------------------------
| CHART GRID
|--------------------------------------------------------------------------
*/

.analytical-chart-grid {

    display: grid;

    grid-template-columns:
        repeat(2, minmax(0, 1fr));

    gap: 15px;

    width: 100%;
}


/*
|--------------------------------------------------------------------------
| ANALYTICAL CARD
|--------------------------------------------------------------------------
*/

.analytical-chart-card {

    background: #ffffff;

    border-radius: 20px;

    padding: 25px;

    box-shadow:
        0 4px 12px
        rgba(0, 0, 0, 0.04);

    border: 1px solid #e1e7e1;

    min-width: 0;

    margin-bottom: 15px;
}


/*
|--------------------------------------------------------------------------
| CHART HEADER
|--------------------------------------------------------------------------
*/

.analytical-chart-header {

    display: flex;

    justify-content: space-between;

    align-items: flex-start;

    gap: 15px;

    margin-bottom: 15px;
}


.analytical-chart-header h3 {

    margin: 0 0 4px 0;

    font-size: 16px;

    font-weight: 700;

    color: #111111;
}


.analytical-chart-header p {

    margin: 0;

    font-size: 12px;

    color: #777777;
}


.analytical-unit {

    padding: 5px 9px;

    border-radius: 8px;

    background: #e8f5e9;

    color: #2e7d32;

    font-size: 11px;

    font-weight: 700;

    white-space: nowrap;
}


/*
|--------------------------------------------------------------------------
| CHART WRAPPER
|--------------------------------------------------------------------------
*/

.analytical-chart-wrapper {

    position: relative;

    width: 100%;

    height: 280px;
}


/*
|--------------------------------------------------------------------------
| CANVAS
|--------------------------------------------------------------------------
*/

.analytical-chart-wrapper canvas {

    width: 100% !important;

    height: 100% !important;
}


/*
|--------------------------------------------------------------------------
| MOBILE
|--------------------------------------------------------------------------
*/

@media (max-width: 992px) {

    .analytical-chart-grid {

        grid-template-columns: 1fr;
    }

}


@media (max-width: 768px) {

    .analytical-section-header {

        flex-direction: column;

        align-items: flex-start;
    }


    .analytical-live-indicator {

        align-self: flex-start;
    }


    .analytical-chart-card {

        padding: 20px;

        border-radius: 16px;
    }


    .analytical-chart-wrapper {

        height: 240px;
    }

}

</style>