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
        $assignedAliases = [];

        if ($role !== 'admin') {
            $assignedInfo = get_assigned_device_for_user($conn, $user_id);
            if ($assignedInfo && !empty($assignedInfo['aliases'])) {
                $assignedAliases = $assignedInfo['aliases'];
            }
        }

        if ($role === 'admin') {
            $stmt = $conn->query("SELECT * FROM soil_readings ORDER BY created_at DESC, id DESC LIMIT 15");
            $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif (!empty($assignedAliases)) {
            $placeholders = implode(',', array_fill(0, count($assignedAliases), '?'));
            $stmt = $conn->prepare("SELECT * FROM soil_readings WHERE device_id IN ($placeholders) ORDER BY created_at DESC, id DESC LIMIT 15");
            $stmt->execute($assignedAliases);
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

    <?php if ($role === 'farmer' && empty($assignedAliases)): ?>
        <div class="card-panel" style="text-align: center; padding: 44px 24px;">
            <div style="width: 56px; height: 56px; border-radius: 50%; background: #f1f5f9; color: #475569; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px;">
                <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
            </div>
            <h4 style="font-size: 20px; font-weight: 800; color: var(--text-heading); margin-bottom: 8px;">No Node Configured</h4>
            <p style="font-size: 15.5px; color: var(--text-muted); max-width: 480px; margin: 0 auto; font-weight: 600;">No monitoring node is currently assigned to this farmer profile. Contact your cooperative administrator to link a field sensor node.</p>
        </div>
    <?php elseif (empty($alerts)): ?>
        <div class="card-panel" style="text-align: center; padding: 44px 24px;">
            <div style="width: 56px; height: 56px; border-radius: 50%; background: #dcfce7; color: #15803d; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px;">
                <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </div>
            <h4 style="font-size: 20px; font-weight: 800; color: var(--text-heading); margin-bottom: 8px;">All Parameters Normal</h4>
            <p style="font-size: 15.5px; color: var(--text-muted); max-width: 480px; margin: 0 auto; font-weight: 600;">No critical anomalies detected in recent telemetry. Your soil conditions are currently within target thresholds.</p>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 14px;">
            <?php foreach ($alerts as $item): ?>
                <div class="card-panel" style="padding: 22px 24px; border-left: 5px solid <?= $item['type'] === 'critical' ? '#dc2626' : '#d97706' ?>;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
                        <span class="badge-pill <?= $item['type'] === 'critical' ? 'critical' : 'warning' ?>">
                            <?= htmlspecialchars($item['title']) ?>
                        </span>
                        <span style="font-size: 14px; font-weight: 700; color: var(--text-muted);"><?= htmlspecialchars($item['time']) ?></span>
                    </div>
                    <p style="font-size: 16px; color: var(--text-body); margin: 0; font-weight: 600; line-height: 1.6;">
                        <?= htmlspecialchars($item['msg']) ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>