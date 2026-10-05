<?php
// views/alerts.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

if (!isset($conn)) {
    require_once __DIR__ . '/../config/db_connect.php';
}

$alerts = [];

$user_id = intval($_SESSION['user_id'] ?? 0);
$role = strtolower(trim($_SESSION['role'] ?? 'farmer'));

if ($user_id <= 0) {
    $alerts = [];
} else {
    try {

        /*
         * =========================================================
         * DETERMINE DATA SCOPE
         * =========================================================
         *
         * ADMIN:
         *   Can see alerts from all soil readings.
         *
         * FARMER:
         *   Can only see alerts from the node assigned
         *   to the logged-in farmer.
         *
         * ASSIGNMENT LOGIC:
         *
         *   users.id
         *       ↓
         *   sensor_data.user_id
         *       ↓
         *   sensor_data.device_label
         *       ↓
         *   soil_readings.device_id
         *
         * The device/node is NEVER taken from GET or POST.
         */

        $assignedDeviceLabel = null;

        if ($role !== 'admin') {

            /*
             * Get the node assigned to the logged-in farmer.
             *
             * IMPORTANT:
             * devices_manage.php stores the assignment in
             * sensor_data.device_label, NOT sensor_data.device_id.
             */

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

            $assignedDeviceLabel = $deviceStmt->fetchColumn();

            if (
                $assignedDeviceLabel !== false &&
                $assignedDeviceLabel !== null &&
                trim((string)$assignedDeviceLabel) !== ''
            ) {
                $assignedDeviceLabel = trim((string)$assignedDeviceLabel);
            } else {
                $assignedDeviceLabel = null;
            }
        }

        /*
         * =========================================================
         * FETCH RECENT READINGS
         * =========================================================
         */

        if ($role === 'admin') {

            /*
             * ADMIN:
             * System-wide readings.
             */

            $stmt = $conn->query("
                SELECT *
                FROM soil_readings
                ORDER BY created_at DESC
                LIMIT 10
            ");

        } elseif ($assignedDeviceLabel !== null) {

            /*
             * FARMER:
             * Only readings belonging to the assigned node.
             *
             * sensor_data.device_label
             * must match
             * soil_readings.device_id
             */

            $stmt = $conn->prepare("
                SELECT *
                FROM soil_readings
                WHERE device_id = ?
                ORDER BY created_at DESC
                LIMIT 10
            ");

            $stmt->execute([$assignedDeviceLabel]);

        } else {

            /*
             * FARMER HAS NO ASSIGNED NODE.
             *
             * Do NOT fall back to global soil_readings.
             */

            $stmt = null;
        }

        $recentReadings = $stmt
            ? $stmt->fetchAll(PDO::FETCH_ASSOC)
            : [];

        /*
         * =========================================================
         * ALERT EVALUATION
         * =========================================================
         */

        foreach ($recentReadings as $reading) {

            $moisture = floatval(
                $reading['moisture']
                ?? $reading['soil_moisture']
                ?? 0
            );

            $ph = floatval(
                $reading['ph']
                ?? $reading['ph_level']
                ?? 0
            );

            $n = floatval(
                $reading['nitrogen']
                ?? $reading['n']
                ?? 0
            );

            $p = floatval(
                $reading['phosphorus']
                ?? $reading['p']
                ?? 0
            );

            $k = floatval(
                $reading['potassium']
                ?? $reading['k']
                ?? 0
            );

            $temp = floatval(
                $reading['temperature']
                ?? $reading['temp']
                ?? 0
            );

            $time = isset($reading['created_at'])
                ? date(
                    "M j, Y - g:i A",
                    strtotime($reading['created_at'])
                )
                : 'Recent';

            /*
             * =====================================================
             * MOISTURE
             * =====================================================
             */

            if ($moisture < 30) {

                $alerts[] = [
                    'type' => 'danger',
                    'title' => 'Critical Low Moisture',
                    'msg' => "Soil moisture is at {$moisture}%. Immediate irrigation required.",
                    'time' => $time
                ];

            } elseif ($moisture > 60) {

                $alerts[] = [
                    'type' => 'warning',
                    'title' => 'High Moisture Level',
                    'msg' => "Soil moisture is at {$moisture}%. Halt irrigation to avoid waterlogging.",
                    'time' => $time
                ];
            }

            /*
             * =====================================================
             * pH
             * =====================================================
             */

            if ($ph < 5.0) {

                $alerts[] = [
                    'type' => 'danger',
                    'title' => 'High Soil Acidity',
                    'msg' => "pH reading is {$ph}. Consider applying agricultural lime.",
                    'time' => $time
                ];

            } elseif ($ph > 7.5) {

                $alerts[] = [
                    'type' => 'warning',
                    'title' => 'High Soil Alkalinity',
                    'msg' => "pH reading is {$ph}. Consider adding organic sulfur additives.",
                    'time' => $time
                ];
            }

            /*
             * =====================================================
             * NITROGEN
             * =====================================================
             */

            if ($n < 20) {

                $alerts[] = [
                    'type' => 'warning',
                    'title' => 'Nitrogen Deficiency',
                    'msg' => "Nitrogen is low at {$n} mg/kg. Urea or nitrogen fertilizer recommended.",
                    'time' => $time
                ];
            }

            /*
             * =====================================================
             * TEMPERATURE
             * =====================================================
             */

            if ($temp > 35) {

                $alerts[] = [
                    'type' => 'danger',
                    'title' => 'High Soil Temperature',
                    'msg' => "Soil temp reached {$temp}°C. Avoid midday watering to protect root systems.",
                    'time' => $time
                ];
            }
        }

    } catch (PDOException $e) {

        $alerts = [];
    }
}
?>

<div class="alerts-container">

<div style="margin-bottom: 20px;">
    <h2 style="margin: 0; color: #1a252c;">
        System & Field Alerts
    </h2>

    <p style="margin: 4px 0 0; color: #6c757d; font-size: 14px;">
        Automated warnings based on recent telemetry threshold evaluations.
    </p>
</div>

<div style="display: flex; flex-direction: column; gap: 15px;">

    <?php if (!empty($alerts)): ?>

        <?php foreach ($alerts as $alert): ?>

            <?php

            $isDanger = $alert['type'] === 'danger';

            $bgColor = $isDanger
                ? '#fff5f5'
                : '#fff9db';

            $borderColor = $isDanger
                ? '#ff4d4f'
                : '#ffe066';

            $textColor = $isDanger
                ? '#c92a2a'
                : '#e67700';

            ?>

            <div
                style="
                    background: <?= $bgColor ?>;
                    border-left: 5px solid <?= $borderColor ?>;
                    padding: 16px 20px;
                    border-radius: 8px;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.03);
                "
            >

                <div
                    style="
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        margin-bottom: 6px;
                    "
                >

                    <strong
                        style="
                            color: <?= $textColor ?>;
                            font-size: 15px;
                        "
                    >
                        <?= htmlspecialchars($alert['title']) ?>
                    </strong>

                    <span
                        style="
                            font-size: 12px;
                            color: #868e96;
                        "
                    >
                        <?= htmlspecialchars($alert['time']) ?>
                    </span>

                </div>

                <p
                    style="
                        margin: 0;
                        color: #495057;
                        font-size: 14px;
                    "
                >
                    <?= htmlspecialchars($alert['msg']) ?>
                </p>

            </div>

        <?php endforeach; ?>

    <?php else: ?>

        <div
            style="
                background: #e8f5e9;
                border-left: 5px solid #4caf50;
                padding: 20px;
                border-radius: 8px;
                text-align: center;
            "
        >

            <strong
                style="
                    color: #2e7d32;
                    font-size: 16px;
                "
            >
                All Metrics Nominal
            </strong>

            <p
                style="
                    margin: 5px 0 0;
                    color: #495057;
                    font-size: 14px;
                "
            >
                No critical thresholds breached in recent field readings.
            </p>

        </div>

    <?php endif; ?>

</div>

</div>
?>