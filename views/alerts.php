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

if ($user_id > 0) {
    try {
        $assignedDeviceLabel = null;

        if ($role !== 'admin') {
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

            if ($assignedDeviceLabel !== false && $assignedDeviceLabel !== null && trim((string)$assignedDeviceLabel) !== '') {
                $assignedDeviceLabel = trim((string)$assignedDeviceLabel);
            } else {
                $assignedDeviceLabel = null;
            }
        }

        if ($role === 'admin') {
            $stmt = $conn->query("SELECT * FROM soil_readings ORDER BY created_at DESC, id DESC LIMIT 15");
            $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($assignedDeviceLabel !== null) {
            $stmt = $conn->prepare("SELECT * FROM soil_readings WHERE device_id = ? ORDER BY created_at DESC, id DESC LIMIT 15");
            $stmt->execute([$assignedDeviceLabel]);
            $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $readings = [];
        }

        foreach ($readings as $reading) {
            $moisture = floatval($reading['moisture'] ?? $reading['soil_moisture'] ?? 0);
            $ph = floatval($reading['ph'] ?? $reading['ph_level'] ?? 0);
            $n = floatval($reading['nitrogen'] ?? $reading['n'] ?? 0);
            $p = floatval($reading['phosphorus'] ?? $reading['p'] ?? 0);
            $k = floatval($reading['potassium'] ?? $reading['k'] ?? 0);
            $temp = floatval($reading['temperature'] ?? $reading['temp'] ?? 0);
            $time = isset($reading['created_at']) ? date("M j, Y · g:i A", strtotime($reading['created_at'])) : 'Recent';

            if ($moisture < 30) {
                $alerts[] = [
                    'type' => 'critical',
                    'title' => 'Critical Low Moisture',
                    'msg' => "Soil moisture is at {$moisture}%. Immediate irrigation required.",
                    'time' => $time
                ];
            } elseif ($moisture > 60) {
                $alerts[] = [
                    'type' => 'warning',
                    'title' => 'High Moisture Level',
                    'msg' => "Soil moisture is at {$moisture}%. Halt irrigation to avoid root hypoxia.",
                    'time' => $time
                ];
            }

            if ($ph < 5.0) {
                $alerts[] = [
                    'type' => 'critical',
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

            if ($n > 0 && $n < 20) {
                $alerts[] = [
                    'type' => 'warning',
                    'title' => 'Nitrogen Deficiency',
                    'msg' => "Nitrogen level is {$n} mg/kg. Urea supplementation recommended.",
                    'time' => $time
                ];
            }

            if ($temp > 32) {
                $alerts[] = [
                    'type' => 'warning',
                    'title' => 'High Soil Temperature',
                    'msg' => "Temperature recorded at {$temp}°C. Monitor crop heat stress.",
                    'time' => $time
                ];
            }
        }
    } catch (PDOException $e) {
        $alerts = [];
    }
}
?>

<div class="sub-view-panel-container">

    <div class="view-panel-header">
        <h3>System Parameter Alerts & Warnings</h3>
        <p>Automated threshold notifications based on field telemetry data.</p>
    </div>

    <?php if (empty($alerts)): ?>
        <div class="card-panel" style="text-align: center; padding: 40px 20px;">
            <div style="width: 50px; height: 50px; border-radius: 50%; background: #dcfce7; color: #15803d; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </div>
            <h4 style="font-size: 17px; font-weight: 700; color: var(--text-heading); margin-bottom: 6px;">All Parameters Normal</h4>
            <p style="font-size: 13.5px; color: var(--text-muted); max-width: 400px; margin: 0 auto;">No critical anomalies detected in recent telemetry. Your soil conditions are currently within target thresholds.</p>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 12px;">
            <?php foreach ($alerts as $item): ?>
                <div class="card-panel" style="padding: 18px 20px; border-left: 4px solid <?= $item['type'] === 'critical' ? '#dc2626' : '#d97706' ?>;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px;">
                        <span class="badge-pill <?= $item['type'] === 'critical' ? 'critical' : 'warning' ?>">
                            <?= htmlspecialchars($item['title']) ?>
                        </span>
                        <span style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($item['time']) ?></span>
                    </div>
                    <p style="font-size: 13.5px; color: var(--text-body); margin: 0; font-weight: 500;">
                        <?= htmlspecialchars($item['msg']) ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>