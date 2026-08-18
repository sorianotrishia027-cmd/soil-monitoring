<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure database connection
if (!isset($conn)) {
    require_once __DIR__ . '/../config/db_connect.php';
}

// Enforce admin-only access clearance
if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    echo "<p class='error' style='padding:15px; color:#dc3545;'>⛔ Access Denied. Administrative clearance required.</p>";
    exit;
}

$msg = "";

// Handle updating a farmer's device label assignment
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'assign_label') {
    $farmer_id = intval($_POST['user_id'] ?? 0);
    $new_label = trim($_POST['device_label'] ?? '');

    if ($farmer_id > 0 && !empty($new_label)) {
        try {
            // Inserts a baseline telemetry row to bind this device label to the farmer
            $stmt = $conn->prepare("INSERT INTO sensor_data (user_id, device_label, moisture, ph_level, temperature, nitrogen, phosphorus, potassium, status) 
                                    VALUES (?, ?, 45.0, 6.2, 26.0, 35, 22, 30, 'OPTIMAL')");
            $stmt->execute([$farmer_id, $new_label]);
            $msg = "<div class='alert success' style='background:#e8f5e9; color:#2e7d32; padding:12px 16px; border-radius:8px; margin-bottom:20px;'>✅ Successfully mapped tracking identifier '<strong>" . htmlspecialchars($new_label) . "</strong>' to the selected farmer profile.</div>";
        } catch (PDOException $e) {
            $msg = "<div class='alert danger' style='background:#ffebee; color:#c62828; padding:12px 16px; border-radius:8px; margin-bottom:20px;'>❌ Mapping update failed: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        $msg = "<div class='alert warning' style='background:#fff8e1; color:#f57f17; padding:12px 16px; border-radius:8px; margin-bottom:20px;'>⚠️ Please select a farmer and enter a valid device label string.</div>";
    }
}

// Fixed Query: Fetch latest mapped device label for each farmer without relying on s.created_at
$assignments_query = "
    SELECT 
        u.id AS user_id, 
        u.username, 
        u.fullname, 
        s.device_label,
        s.id AS last_log_id
    FROM users u
    LEFT JOIN sensor_data s ON s.id = (
        SELECT max_s.id 
        FROM sensor_data max_s 
        WHERE max_s.user_id = u.id 
        ORDER BY max_s.id DESC 
        LIMIT 1
    )
    WHERE LOWER(u.role) = 'farmer'
    ORDER BY u.username ASC
";

try {
    $field_mappings = $conn->query($assignments_query)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $field_mappings = [];
    $msg = "<div class='alert danger' style='background:#ffebee; color:#c62828; padding:12px 16px; border-radius:8px; margin-bottom:20px;'>❌ Query Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}
?>

<div class="sub-view-panel-container" style="padding: 10px;">
    <div class="view-panel-header" style="margin-bottom: 20px;">
        <h3 style="margin: 0; color: #1a252c;">IoT Virtual Node Assignment Matrix</h3>
        <p style="margin: 4px 0 0; color: #6c757d; font-size: 14px;">Assign hardware node labels directly to farmers to stream dynamic telemetry logs into their dashboards.</p>
    </div>

    <?= $msg ?>

    <div class="insights-dashboard-split-row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px;">
        
        <!-- Action Card: Bind Node -->
        <div class="action-alert-panel-card" style="background: #ffffff; border: 1px solid #ccd4cc; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
            <h3 style="margin-top: 0; margin-bottom: 15px; color: #198754;">🔗 Bind Node Identifier</h3>
            <form action="dashboard.php?page=devices_manage" method="POST">
                <input type="hidden" name="action" value="assign_label">
                
                <label style="display:block; margin-bottom: 6px; font-weight: 600; font-size: 13px; color: #495057;">Select Target Farmer:</label>
                <div class="input-wrapper" style="background: #f8f9fa; border: 1px solid #ced4da; border-radius: 6px; padding: 4px 10px; margin-bottom: 15px;">
                    <select name="user_id" style="width:100%; background:transparent; border:none; padding:8px 0; outline:none; font-size:14px; color:#212529;" required>
                        <option value="">-- Choose Account --</option>
                        <?php foreach ($field_mappings as $row): ?>
                            <option value="<?= $row['user_id'] ?>">
                                <?= htmlspecialchars($row['username']) ?> (<?= htmlspecialchars($row['fullname'] ?: 'No Name') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <label style="display:block; margin-bottom: 6px; font-weight: 600; font-size: 13px; color: #495057;">Virtual Device UID Label:</label>
                <div class="input-wrapper" style="background: #f8f9fa; border: 1px solid #ced4da; border-radius: 6px; padding: 8px 12px; margin-bottom: 18px;">
                    <input type="text" name="device_label" placeholder="e.g., ESP32-RICE-NODE-01" style="width: 100%; border: none; background: transparent; outline: none; font-size: 14px;" required>
                </div>

                <button type="submit" style="width: 100%; background: #198754; color: #ffffff; border: none; padding: 12px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 14px;">
                    Deploy Assignment
                </button>
            </form>
        </div>

        <!-- Info Card: Architecture -->
        <div class="action-alert-panel-card" style="background: #ffffff; border: 1px solid #ccd4cc; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <h3 style="margin-top: 0; margin-bottom: 10px; color: #212529;">Hardware Linkage Architecture</h3>
                <p style="font-size: 14px; line-height: 1.5; color: #6c757d;">
                    By storing the device identifier inside history logs, telemetry data dynamically maps to the farmer’s account for real-time dashboard display.
                </p>
            </div>
            <div style="border-left: 4px solid #198754; background: #f8f9fa; padding: 12px; border-radius: 0 8px 8px 0; margin-top: 15px;">
                <span style="font-size: 11px; font-weight: 700; color: #6c757d; text-transform: uppercase;">DATABASE MAPPING</span>
                <p style="font-weight: bold; font-size: 13px; margin: 4px 0 0; color: #212529;">Active Structure: <code>users</code> ➔ <code>sensor_data</code></p>
            </div>
        </div>
    </div>

    <!-- Section Header -->
    <div class="view-panel-header" style="margin-bottom: 15px;">
        <h3 style="margin: 0; color: #1a252c;">📋 Field Node Linkage Maps</h3>
    </div>

    <!-- Data Table -->
    <div class="history-table-wrapper" style="overflow-x: auto; background: #ffffff; padding: 20px; border-radius: 12px; border: 1px solid #ccd4cc; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
        <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 14px;">
            <thead>
                <tr style="border-bottom: 2px solid #e2e8e2; color: #495057; background: #f8f9fa;">
                    <th style="padding: 12px;">Farmer Account</th>
                    <th style="padding: 12px;">Full Name</th>
                    <th style="padding: 12px;">Assigned Node ID</th>
                    <th style="padding: 12px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($field_mappings)): ?>
                    <?php foreach ($field_mappings as $row): ?>
                        <tr style="border-bottom: 1px solid #f0f4f0;">
                            <td style="padding: 12px; font-weight: bold; color: #198754;">👤 <?= htmlspecialchars($row['username']) ?></td>
                            <td style="padding: 12px;"><?= htmlspecialchars($row['fullname'] ?: '---') ?></td>
                            <td style="padding: 12px;">
                                <code><?= $row['device_label'] ? htmlspecialchars($row['device_label']) : '<span style="color:#999; font-style:italic;">No Node Configured</span>' ?></code>
                            </td>
                            <td style="padding: 12px;">
                                <span style="padding: 4px 10px; border-radius: 12px; font-weight: 600; font-size: 12px; <?= $row['device_label'] ? 'background:#e8f5e9; color:#2e7d32;' : 'background:#f5f5f5; color:#777;' ?>">
                                    <?= $row['device_label'] ? 'Active Node' : 'Idle' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="padding: 20px; text-align: center; color: #6c757d;">No registered farmer accounts found in the database.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>