<?php
// views/devices_manage.php

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

/*
|--------------------------------------------------------------------------
| HANDLE FORM SUBMISSIONS
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. REGISTER / ADD NEW HARDWARE NODE (Single Input Field)
    if ($action === 'add_node') {
        $input = trim($_POST['node_name'] ?? '');

        if (!empty($input)) {
            $node_name = $input;
            $device_uid = $input;

            if (preg_match('/^Node\s*(\d+)$/i', $input, $m)) {
                $node_name = 'Node ' . $m[1];
                $device_uid = 'Node ' . $m[1];
            } elseif (preg_match('/^ESP32[_-]?(?:GSM[_-]?)?0*(\d+)$/i', $input, $m)) {
                $node_name = 'Node ' . intval($m[1]);
                $device_uid = $input;
            }

            try {
                $stmt = $conn->prepare("
                    INSERT INTO devices (node_name, device_uid, status) 
                    VALUES (?, ?, 'available')
                    ON DUPLICATE KEY UPDATE node_name = VALUES(node_name), status = 'available'
                ");
                $stmt->execute([$node_name, $device_uid]);
                $msg = "<div class='alert success'>Successfully registered <strong>" . htmlspecialchars($node_name) . "</strong> to the node inventory!</div>";
            } catch (PDOException $e) {
                $msg = "<div class='alert danger'>Failed to register node: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        } else {
            $msg = "<div class='alert warning'>Please enter a valid node identifier or label.</div>";
        }
    }

    // 2. ASSIGN NODE TO FARMER VIA DROPDOWN
    elseif ($action === 'assign_node') {
        $farmer_id = intval($_POST['user_id'] ?? 0);
        $device_id_val = trim($_POST['device_node_id'] ?? '');

        if ($farmer_id > 0) {
            try {
                if ($device_id_val === 'UNASSIGN') {
                    // Release any nodes currently assigned to this farmer
                    $clearStmt = $conn->prepare("UPDATE devices SET assigned_user_id = NULL, status = 'available' WHERE assigned_user_id = ?");
                    $clearStmt->execute([$farmer_id]);
                    $msg = "<div class='alert success'>Farmer node assignment successfully released.</div>";
                } elseif (is_numeric($device_id_val) && intval($device_id_val) > 0) {
                    $node_pk = intval($device_id_val);

                    // Fetch the node info
                    $nodeStmt = $conn->prepare("SELECT * FROM devices WHERE id = ? LIMIT 1");
                    $nodeStmt->execute([$node_pk]);
                    $nodeInfo = $nodeStmt->fetch(PDO::FETCH_ASSOC);

                    if ($nodeInfo) {
                        // 1. Clear previous assignments for this farmer
                        $clearOld = $conn->prepare("UPDATE devices SET assigned_user_id = NULL, status = 'available' WHERE assigned_user_id = ?");
                        $clearOld->execute([$farmer_id]);

                        // 2. Assign this node to the farmer
                        $assignStmt = $conn->prepare("UPDATE devices SET assigned_user_id = ?, status = 'assigned' WHERE id = ?");
                        $assignStmt->execute([$farmer_id, $node_pk]);

                        // 3. Keep sensor_data synchronized for backward compatibility
                        $syncStmt = $conn->prepare("
                            INSERT INTO sensor_data (user_id, device_label, moisture, ph_level, temperature, nitrogen, phosphorus, potassium, status) 
                            VALUES (?, ?, 45.0, 6.2, 26.0, 35, 22, 30, 'OPTIMAL')
                        ");
                        $syncStmt->execute([$farmer_id, $nodeInfo['device_uid']]);

                        $msg = "<div class='alert success'>Successfully assigned <strong>" . htmlspecialchars($nodeInfo['node_name']) . " (" . htmlspecialchars($nodeInfo['device_uid']) . ")</strong> to the selected farmer!</div>";
                    } else {
                        $msg = "<div class='alert danger'>Selected node could not be found.</div>";
                    }
                }
            } catch (PDOException $e) {
                $msg = "<div class='alert danger'>Assignment error: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        } else {
            $msg = "<div class='alert warning'>Please select a target farmer account.</div>";
        }
    }

    // 3. RELEASE / UNASSIGN DIRECTLY FROM INVENTORY
    elseif ($action === 'unassign_node_direct') {
        $node_pk = intval($_POST['node_id'] ?? 0);
        if ($node_pk > 0) {
            try {
                $stmt = $conn->prepare("UPDATE devices SET assigned_user_id = NULL, status = 'available' WHERE id = ?");
                $stmt->execute([$node_pk]);
                $msg = "<div class='alert success'>Node has been released and is now available for assignment.</div>";
            } catch (PDOException $e) {
                $msg = "<div class='alert danger'>Error releasing node: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }

    // 4. DELETE NODE
    elseif ($action === 'delete_node') {
        $node_pk = intval($_POST['node_id'] ?? 0);
        if ($node_pk > 0) {
            try {
                $stmt = $conn->prepare("DELETE FROM devices WHERE id = ?");
                $stmt->execute([$node_pk]);
                $msg = "<div class='alert success'>Hardware node removed from system registry.</div>";
            } catch (PDOException $e) {
                $msg = "<div class='alert danger'>Error deleting node: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| FETCH INVENTORY & FARMER MAPPINGS
|--------------------------------------------------------------------------
*/
$registered_nodes = get_all_registered_nodes($conn);

// Fetch farmers with their currently mapped node
$assignments_query = "
    SELECT 
        u.id AS user_id, 
        u.username, 
        u.fullname, 
        d.id AS device_id,
        d.node_name,
        d.device_uid,
        s.device_label AS legacy_label
    FROM users u
    LEFT JOIN devices d ON d.assigned_user_id = u.id
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
    $farmer_mappings = $conn->query($assignments_query)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $farmer_mappings = [];
}
?>

<div class="sub-view-panel-container">

    <div class="view-panel-header">
        <h3 style="display: flex; align-items: center; gap: 8px;">
            <span>📡</span> IoT Field Node & Hardware Management
        </h3>
        <p>Register monitoring nodes (e.g., Node 1 = ESP32_GSM_01) and deploy hardware assignments to farmers via dropdown selection.</p>
    </div>

    <?= $msg ?>

    <!-- =========================================================
         2-COLUMN SPLIT: 1. ADD NEW NODE (1 INPUT) & 2. ASSIGN NODE (DROPDOWN)
         ========================================================= -->
    <div class="insights-dashboard-split-row">
        
        <!-- CARD 1: ADD / REGISTER NEW NODE (1 TEXTFIELD) -->
        <div class="card-panel">
            <h3 style="font-size: 19px; font-weight: 800; color: var(--text-heading); margin-bottom: 18px; display: flex; align-items: center; gap: 10px;">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="16"></line>
                    <line x1="8" y1="12" x2="16" y2="12"></line>
                </svg>
                Register New Field Node
            </h3>

            <form action="dashboard.php?page=devices_manage" method="POST">
                <input type="hidden" name="action" value="add_node">

                <div class="form-group">
                    <label class="form-label">Node Identifier / Label:</label>
                    <div class="input-field-wrapper">
                        <input type="text" name="node_name" placeholder="e.g., Node 2 or ESP32_GSM_02" required>
                    </div>
                    <small style="color: var(--text-subtle); font-size: 13.5px; font-weight: 600; margin-top: 8px; display:block;">
                        * Enter a node name or hardware UID to register it into the system inventory.
                    </small>
                </div>

                <button type="submit" class="btn-primary" style="margin-top: 16px;">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    Register Node
                </button>
            </form>
        </div>

        <!-- CARD 2: BIND / ASSIGN NODE TO FARMER (DROPDOWN) -->
        <div class="card-panel">
            <h3 style="font-size: 19px; font-weight: 800; color: var(--text-heading); margin-bottom: 18px; display: flex; align-items: center; gap: 10px;">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                </svg>
                Bind Node to Farmer
            </h3>

            <form action="dashboard.php?page=devices_manage" method="POST">
                <input type="hidden" name="action" value="assign_node">

                <div class="form-group">
                    <label class="form-label">Select Target Farmer:</label>
                    <div class="input-field-wrapper">
                        <select name="user_id" required>
                            <option value="">-- Choose Farmer Account --</option>
                            <?php foreach ($farmer_mappings as $farmer): ?>
                                <?php 
                                    $currentLabel = $farmer['node_name'] ? ($farmer['node_name'] . ' [' . $farmer['device_uid'] . ']') : ($farmer['legacy_label'] ?: 'None');
                                ?>
                                <option value="<?= $farmer['user_id'] ?>">
                                    <?= htmlspecialchars($farmer['username']) ?> (<?= htmlspecialchars($farmer['fullname'] ?: 'No Name') ?>) — Current: <?= htmlspecialchars($currentLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Select Available Field Node (Dropdown):</label>
                    <div class="input-field-wrapper">
                        <select name="device_node_id" required>
                            <option value="">-- Select Registered Node --</option>
                            
                            <!-- AVAILABLE NODES GROUP -->
                            <optgroup label="✅ Available Nodes">
                                <?php 
                                $hasAvailable = false;
                                foreach ($registered_nodes as $node): 
                                    if (empty($node['assigned_user_id'])):
                                        $hasAvailable = true;
                                ?>
                                    <option value="<?= $node['id'] ?>">
                                        <?= htmlspecialchars($node['node_name']) ?> (<?= htmlspecialchars($node['device_uid']) ?>) — Available
                                    </option>
                                <?php 
                                    endif;
                                endforeach; 
                                if (!$hasAvailable):
                                ?>
                                    <option value="" disabled>(No available nodes — register a new node on the left)</option>
                                <?php endif; ?>
                            </optgroup>

                            <!-- CURRENTLY ASSIGNED NODES GROUP -->
                            <?php if (!empty($registered_nodes)): ?>
                                <optgroup label="🔄 Reassign Existing Node">
                                    <?php foreach ($registered_nodes as $node): ?>
                                        <?php if (!empty($node['assigned_user_id'])): ?>
                                            <option value="<?= $node['id'] ?>">
                                                <?= htmlspecialchars($node['node_name']) ?> (<?= htmlspecialchars($node['device_uid']) ?>) — [Assigned to: <?= htmlspecialchars($node['assigned_username'] ?: 'User #' . $node['assigned_user_id']) ?>]
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>

                            <!-- UNASSIGN OPTION -->
                            <optgroup label="❌ Remove Node Assignment">
                                <option value="UNASSIGN">-- Unassign / Release Current Node --</option>
                            </optgroup>
                        </select>
                    </div>
                </div>

                <div class="directive-highlight-box" style="margin-top: 16px; margin-bottom: 16px;">
                    <div class="directive-muted-tag">HARDWARE ARCHITECTURE</div>
                    <div class="directive-metric-val" style="font-size: 14.5px; line-height: 1.5;">
                        <strong>Node 1</strong> and <strong>ESP32_GSM_01</strong> are automatically unified for seamless real-time telemetry streaming.
                    </div>
                </div>

                <button type="submit" class="btn-primary" style="margin-top: 10px;">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                    </svg>
                    Deploy Node Assignment
                </button>
            </form>
        </div>

    </div>

    <!-- =========================================================
         TABLE 1: REGISTERED HARDWARE NODES INVENTORY
         ========================================================= -->
    <div class="table-container-card" style="margin-bottom: 24px;">
        <div class="table-header-flex">
            <div>
                <div class="card-title" style="font-size: 16px; display: flex; align-items: center; gap: 8px;">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect>
                        <rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect>
                        <line x1="6" y1="6" x2="6.01" y2="6"></line>
                        <line x1="6" y1="18" x2="6.01" y2="18"></line>
                    </svg>
                    Registered Hardware Nodes Inventory
                </div>
            </div>
            <div style="font-size: 12.5px; color: var(--text-muted); font-weight: 600;">
                Total Nodes: <?= count($registered_nodes) ?>
            </div>
        </div>

        <div class="table-responsive">
            <table class="custom-data-table">
                <thead>
                    <tr>
                        <th>Node Name</th>
                        <th>Hardware Device UID</th>
                        <th>Assigned Farmer</th>
                        <th>Status</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($registered_nodes)): ?>
                        <?php foreach ($registered_nodes as $node): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px; font-weight: 800; color: var(--text-heading);">
                                        <div style="width:8px; height:8px; border-radius:50%; background: <?= !empty($node['assigned_user_id']) ? '#16a34a' : '#3b82f6' ?>;"></div>
                                        <span><?= htmlspecialchars($node['node_name']) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <code style="background:#edf3ef; color:#143d2c; padding:3px 8px; border-radius:4px; font-weight:700; font-size:12.5px;">
                                        <?= htmlspecialchars($node['device_uid']) ?>
                                    </code>
                                </td>
                                <td>
                                    <?php if (!empty($node['assigned_user_id'])): ?>
                                        <div style="display: flex; align-items: center; gap: 6px; font-weight: 700; color: var(--text-heading);">
                                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--primary-color);">
                                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                                <circle cx="12" cy="7" r="4"/>
                                            </svg>
                                            <span><?= htmlspecialchars($node['assigned_username'] ?: 'Farmer #' . $node['assigned_user_id']) ?></span>
                                            <span style="font-size: 11.5px; color: var(--text-muted); font-weight: normal;">(<?= htmlspecialchars($node['assigned_fullname'] ?: '') ?>)</span>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #6b7280; font-style: italic; font-size: 12.5px;">Unassigned / Available</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge-pill <?= !empty($node['assigned_user_id']) ? 'optimal' : 'neutral' ?>" style="font-size: 11px;">
                                        <?= !empty($node['assigned_user_id']) ? 'Assigned & Active' : 'Available' ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; gap: 6px; align-items: center;">
                                        <?php if (!empty($node['assigned_user_id'])): ?>
                                            <form action="dashboard.php?page=devices_manage" method="POST" style="display:inline;" onsubmit="return confirm('Release this node from the assigned farmer?');">
                                                <input type="hidden" name="action" value="unassign_node_direct">
                                                <input type="hidden" name="node_id" value="<?= $node['id'] ?>">
                                                <button type="submit" class="btn-outline" style="padding: 4px 10px; font-size: 11.5px; color: #b45309; border-color: #fde68a; background: #fffbeb;">
                                                    Release
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <form action="dashboard.php?page=devices_manage" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to remove this node from registry?');">
                                            <input type="hidden" name="action" value="delete_node">
                                            <input type="hidden" name="node_id" value="<?= $node['id'] ?>">
                                            <button type="submit" class="btn-outline" style="padding: 4px 10px; font-size: 11.5px; color: #dc2626; border-color: #fecaca; background: #fef2f2;">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: #9ca3af; padding: 24px;">No hardware nodes currently registered. Use the form above to add a node.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- =========================================================
         TABLE 2: FIELD NODE LINKAGE MAPS (FARMER DIRECTORY)
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
                    Field Node Linkage Maps (Farmer Directory)
                </div>
            </div>
            <div style="font-size: 12.5px; color: var(--text-muted); font-weight: 600;">
                Total Farmers: <?= count($farmer_mappings) ?>
            </div>
        </div>

        <div class="table-responsive">
            <table class="custom-data-table">
                <thead>
                    <tr>
                        <th>Farmer Account</th>
                        <th>Full Name</th>
                        <th>Assigned Node & UID</th>
                        <th>Sync Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($farmer_mappings)): ?>
                        <?php foreach ($farmer_mappings as $row): ?>
                            <?php 
                                $hasNode = !empty($row['node_name']) || !empty($row['legacy_label']);
                                if ($row['node_name']) {
                                    $displayNode = $row['node_name'] . ' (' . $row['device_uid'] . ')';
                                } elseif (!empty($row['legacy_label'])) {
                                    $lbl = $row['legacy_label'];
                                    $norm = strtolower(str_replace([' ', '-', '_'], '', $lbl));
                                    if ($norm === 'node1' || $norm === 'node01' || $norm === 'esp32gsm01') {
                                        $displayNode = 'Node 1 (ESP32_GSM_01)';
                                    } else {
                                        $displayNode = $lbl;
                                    }
                                } else {
                                    $displayNode = 'No Node Configured';
                                }
                            ?>
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
                                    <?php if ($hasNode): ?>
                                        <code style="background:#edf3ef; color:#143d2c; padding:3px 8px; border-radius:4px; font-weight:700; font-size:12.5px;">
                                            <?= htmlspecialchars($displayNode) ?>
                                        </code>
                                    <?php else: ?>
                                        <span style="color: #9ca3af; font-style: italic; font-size: 13px;">No Node Configured</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge-pill <?= $hasNode ? 'optimal' : 'neutral' ?>" style="font-size: 11px;">
                                        <?= $hasNode ? 'Live Sync Active' : 'Standby / Idle' ?>
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