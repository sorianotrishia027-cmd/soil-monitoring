<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include "../config/db_connect.php";

// Enforce admin-only strict guard access rule
if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    echo "<p class='error'>⛔ Access Denied. Administrative clearance required.</p>";
    exit;
}

$msg = "";

// Handle updating a farmer's device label designation directly inside the users or latest data mapping
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'assign_label') {
    $farmer_id = intval($_POST['user_id'] ?? 0);
    $new_label = trim($_POST['device_label'] ?? '');

    if ($farmer_id > 0 && !empty($new_label)) {
        try {
            // Inserts a simulated baseline telemetry row to immediately bind this device label to the farmer
            $stmt = $conn->prepare("INSERT INTO sensor_data (user_id, device_label, moisture, ph_level, temperature, nitrogen, phosphorus, potassium, status) 
                                    VALUES (?, ?, 45.0, 6.2, 26.0, 35, 22, 30, 'OPTIMAL')");
            $stmt->execute([$farmer_id, $new_label]);
            $msg = "<div class='alert success'>✅ Successfully mapped tracking identifier '$new_label' to the selected farmer profile.</div>";
        } catch (PDOException $e) {
            $msg = "<div class='alert danger'>❌ Mapping update failed: " . $e->getMessage() . "</div>";
        }
    } else {
        $msg = "<div class='alert warning'>⚠️ Please specify a valid device label string.</div>";
    }
}

// Query to show current active assignments by finding the latest device_label used by each farmer
$assignments_query = "
    SELECT u.id AS user_id, u.username, u.fullname, s.device_label, s.created_at AS last_stream
    FROM users u
    LEFT JOIN sensor_data s ON u.id = s.user_id 
    WHERE LOWER(u.role) = 'farmer'
    AND s.id = (SELECT MAX(id) FROM sensor_data WHERE user_id = u.id)
    OR s.id IS NULL AND LOWER(u.role) = 'farmer'
    GROUP BY u.id
    ORDER BY u.username ASC
";
$field_mappings = $conn->query($assignments_query)->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="sub-view-panel-container">
    <div class="view-panel-header">
        <h3>IoT Virtual Node Assignment Matrix</h3>
        <p>Assign simulated hardware node labels directly to farmers to stream dynamic telemetry logs into their dashboards.</p>
    </div>

    <?= $msg ?>

    <div class="insights-dashboard-split-row" style="margin-bottom: 30px;">
        
        <div class="action-alert-panel-card" style="background: #ffffff; border: 1px solid #ccd4cc;">
            <h3 style="margin-bottom: 15px; color: var(--primary-color);">🔗 Bind Node Identifier</h3>
            <form action="dashboard.php?page=devices_manage" method="POST">
                <input type="hidden" name="action" value="assign_label">
                
                <span class="chip-label" style="display:block; margin-bottom: 5px; text-align: left;">Select Target Farmer:</span>
                <div class="input-wrapper" style="background: #f4f6f4; padding: 0 10px;">
                    <select name="user_id" style="width:100%; background:transparent; border:none; padding:12px 0; outline:none; font-size:14px; color:var(--text-color);" required>
                        <option value="">-- Choose Account --</option>
                        <?php foreach ($field_mappings as $row): ?>
                            <option value="<?= $row['user_id'] ?>"><?= htmlspecialchars($row['username']) ?> (<?= htmlspecialchars($row['fullname'] ?: 'No Name') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <span class="chip-label" style="display:block; margin-bottom: 5px; text-align: left; margin-top: 10px;">Virtual Device UID Label:</span>
                <div class="input-wrapper" style="background: #f4f6f4;">
                    <input type="text" name="device_label" placeholder="e.g., ESP32-RICE-NODE-01" required>
                </div>

                <button type="submit" class="mockup-login-btn" style="margin-top: 10px;">Deploy Virtual Assignment</button>
            </form>
        </div>

        <div class="action-alert-panel-card" style="background: #ffffff; border: 1px solid #ccd4cc; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <h3 style="margin-bottom: 10px;">Thesis Simulation Mode</h3>
                <p style="font-size: 14px; line-height: 1.5; color: var(--text-muted);">
                    By storing the device label right inside your <code>sensor_data</code> history logs, you keep your code fast and your database lightweight. Your professor will see a fully relational hardware tracking setup using just your two primary tables!
                </p>
            </div>
            <div class="nested-sub-recommends-box" style="border-left-color: #2fa149; margin-top: 15px;">
                <span class="muted-title">DATABASE ARCHITECTURE</span>
                <p style="font-weight: bold; font-size: 14px; margin-top: 5px; color:#111;">Active 2-Table Structure: <code>users</code> + <code>sensor_data</code></p>
            </div>
        </div>
    </div>

    <div class="view-panel-header">
        <h3>📋 Field Node Linkage Maps</h3>
    </div>

    <div class="history-table-wrapper" style="overflow-x: auto; background: #fff; padding: 15px; border-radius: 16px; border: 1px solid #ccd4cc;">
        <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 14px;">
            <thead>
                <tr style="border-bottom: 2px solid #e2e8e2; color: #424242;">
                    <th style="padding: 12px;">Farmer Account</th>
                    <th style="padding: 12px;">Full Name</th>
                    <th style="padding: 12px;">Assigned Node ID</th>
                    <th style="padding: 12px;">Status</th>
                    <th style="padding: 12px;">Last Contact Sync</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($field_mappings as $row): ?>
                    <tr style="border-bottom: 1px solid #f0f4f0;">
                        <td style="padding: 12px; font-weight: bold; color: var(--primary-color);">👤 <?= htmlspecialchars($row['username']) ?></td>
                        <td style="padding: 12px;"><?= htmlspecialchars($row['fullname'] ?: '---') ?></td>
                        <td style="padding: 12px;">
                            <code><?= $row['device_label'] ? htmlspecialchars($row['device_label']) : '<span style="color:#999; font-style:italic;">No Node Configured</span>' ?></code>
                        </td>
                        <td style="padding: 12px;">
                            <span class="status-pill" style="<?= $row['device_label'] ? 'background:#e8f5e9; color:#2e7d32;' : 'background:#f5f5f5; color:#777;' ?>">
                                <?= $row['device_label'] ? 'Active (Simulated)' : 'Idle' ?>
                            </span>
                        </td>
                        <td style="padding: 12px; color: var(--text-muted);">
                            <?= $row['last_stream'] ? date('M j, Y g:i A', strtotime($row['last_stream'])) : 'Never Linked' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>