<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn)) {
    require_once __DIR__ . '/../config/db_connect.php';
}

if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    echo "<p class='alert danger'>Access Denied. Administrative clearance required.</p>";
    exit;
}

$msg = "";

// Handle updating a farmer's device label assignment
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'assign_label') {
    $farmer_id = intval($_POST['user_id'] ?? 0);
    $new_label = trim($_POST['device_label'] ?? '');

    if ($farmer_id > 0 && !empty($new_label)) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO sensor_data (user_id, device_label, moisture, ph_level, temperature, nitrogen, phosphorus, potassium, status) 
                VALUES (?, ?, 45.0, 6.2, 26.0, 35, 22, 30, 'OPTIMAL')
            ");
            $stmt->execute([$farmer_id, $new_label]);
            $msg = "<div class='alert success'>Successfully mapped tracking identifier '<strong>" . htmlspecialchars($new_label) . "</strong>' to the selected farmer profile.</div>";
        } catch (PDOException $e) {
            $msg = "<div class='alert danger'>Mapping update failed: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        $msg = "<div class='alert warning'>Please select a farmer and enter a valid device label string.</div>";
    }
}

// Fetch latest mapped device label for each farmer
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
    $msg = "<div class='alert danger'>Query Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}
?>

<div class="sub-view-panel-container">

    <div class="view-panel-header">
        <h3>IoT Virtual Node Assignment Matrix</h3>
        <p>Assign hardware node labels directly to farmers to stream dynamic telemetry logs into their dashboards.</p>
    </div>

    <?= $msg ?>

    <!-- =========================================================
         2-COLUMN SPLIT: BIND NODE IDENTIFIER & HARDWARE ARCHITECTURE (Matches Images 1 & 2)
         ========================================================= -->
    <div class="insights-dashboard-split-row">
        
        <!-- Bind Node Identifier Card -->
        <div class="card-panel">
            <h3 style="font-size: 16px; font-weight: 700; color: var(--text-heading); margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                </svg>
                Bind Node Identifier
            </h3>

            <form action="dashboard.php?page=devices_manage" method="POST">
                <input type="hidden" name="action" value="assign_label">

                <div class="form-group">
                    <label class="form-label">Select Target Farmer:</label>
                    <div class="input-field-wrapper">
                        <select name="user_id" required>
                            <option value="">-- Choose Account --</option>
                            <?php foreach ($field_mappings as $row): ?>
                                <option value="<?= $row['user_id'] ?>">
                                    <?= htmlspecialchars($row['username']) ?> (<?= htmlspecialchars($row['fullname'] ?: 'No Name') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Virtual Device UID Label:</label>
                    <div class="input-field-wrapper">
                        <input type="text" name="device_label" placeholder="e.g., ESP32-RICE-NODE-01" required>
                    </div>
                </div>

                <button type="submit" class="btn-primary" style="margin-top: 8px;">
                    Deploy Assignment
                </button>
            </form>
        </div>

        <!-- Hardware Linkage Architecture Card -->
        <div class="card-panel" style="display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-heading); margin-bottom: 12px;">
                    Hardware Linkage Architecture
                </h3>
                <p style="font-size: 13.5px; line-height: 1.55; color: var(--text-muted);">
                    By storing the device identifier inside history logs, telemetry data dynamically maps to the farmer's account for real-time dashboard display.
                </p>
            </div>

            <div class="directive-highlight-box">
                <div class="directive-muted-tag">DATABASE MAPPING</div>
                <div class="directive-metric-val">
                    Active Structure: <code style="background:#e8f4ec; color:#143d2c; padding:2px 6px; border-radius:4px; font-size:12.5px;">users</code> ➔ <code style="background:#e8f4ec; color:#143d2c; padding:2px 6px; border-radius:4px; font-size:12.5px;">sensor_data</code>
                </div>
            </div>
        </div>

    </div>

    <!-- =========================================================
         FIELD NODE LINKAGE MAPS TABLE (Matches Images 1 & 2)
         ========================================================= -->
    <div class="table-container-card">
        <div class="table-header-flex">
            <div>
                <div class="card-title" style="font-size: 16px; display: flex; align-items: center; gap: 8px;">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10 9 9 9 8 9"/>
                    </svg>
                    Field Node Linkage Maps
                </div>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="custom-data-table">
                <thead>
                    <tr>
                        <th>Farmer Account</th>
                        <th>Full Name</th>
                        <th>Assigned Node ID</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($field_mappings)): ?>
                        <?php foreach ($field_mappings as $row): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px; font-weight: 700; color: var(--text-heading);">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--primary-color);">
                                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                            <circle cx="12" cy="7" r="4"/>
                                        </svg>
                                        <span><?= htmlspecialchars($row['username']) ?></span>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($row['fullname'] ?: '---') ?></td>
                                <td>
                                    <?php if (!empty($row['device_label'])): ?>
                                        <code style="background:#edf3ef; color:#143d2c; padding:3px 8px; border-radius:4px; font-weight:600; font-size:12.5px;">
                                            <?= htmlspecialchars($row['device_label']) ?>
                                        </code>
                                    <?php else: ?>
                                        <span style="color: #9ca3af; font-style: italic; font-size: 13px;">No Node Configured</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge-pill <?= !empty($row['device_label']) ? 'optimal' : 'neutral' ?>" style="font-size: 11px;">
                                        <?= !empty($row['device_label']) ? 'Active Node' : 'Idle' ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: #9ca3af; padding: 24px;">No farmer profiles currently found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>