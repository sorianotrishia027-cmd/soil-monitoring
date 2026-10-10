<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn)) {
    require_once __DIR__ . '/../config/db_connect.php';
}

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
        $userStmt = $conn->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$user_id]);
        $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
        if ($userRow) {
            $role = strtolower(trim($userRow['role'] ?? $role));
        }
    }
} catch (PDOException $e) {}

/*
|--------------------------------------------------------------------------
| GET ASSIGNED NODE FOR FARMER (UNIFIED WITH ESP32_GSM_01 & NODE 1)
|--------------------------------------------------------------------------
*/
$assigned_info = null;
$assigned_device_id = null;
$assigned_node_name = null;
$assigned_aliases = [];

if ($role !== 'admin' && $user_id > 0) {
    $assigned_info = get_assigned_device_for_user($conn, $user_id);
    if ($assigned_info) {
        $assigned_device_id = $assigned_info['device_uid'];
        $assigned_node_name = $assigned_info['node_name'];
        $assigned_aliases = $assigned_info['aliases'];
    }
}

/*
|--------------------------------------------------------------------------
| LATEST READING & HISTORICAL ANALYTICAL DATA
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

        $history_stmt = $conn->query("
            SELECT id, moisture, ph, temperature, nitrogen, phosphorus, potassium, created_at
            FROM soil_readings
            ORDER BY created_at DESC, id DESC
            LIMIT 20
        ");
        $history_records = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif (!empty($assigned_aliases)) {
        $placeholders = implode(',', array_fill(0, count($assigned_aliases), '?'));
        
        $stmt = $conn->prepare("
            SELECT *
            FROM soil_readings
            WHERE device_id IN ($placeholders)
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute($assigned_aliases);
        $latest = $stmt->fetch(PDO::FETCH_ASSOC);

        $history_stmt = $conn->prepare("
            SELECT id, moisture, ph, temperature, nitrogen, phosphorus, potassium, created_at
            FROM soil_readings
            WHERE device_id IN ($placeholders)
            ORDER BY created_at DESC, id DESC
            LIMIT 20
        ");
        $history_stmt->execute($assigned_aliases);
        $history_records = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $latest = null;
        $history_records = [];
    }
} catch (PDOException $e) {
    $latest = null;
    $history_records = [];
}

$history_records = array_reverse($history_records);
$chart_labels = [];
$moisture_data = [];
$ph_data = [];
$temperature_data = [];
$nitrogen_data = [];
$phosphorus_data = [];
$potassium_data = [];

foreach ($history_records as $rec) {
    if (!isset($rec['created_at'])) continue;
    $chart_labels[] = date('g:i A', strtotime($rec['created_at']));
    $moisture_data[] = isset($rec['moisture']) ? (float)$rec['moisture'] : null;
    $ph_data[] = isset($rec['ph']) ? (float)$rec['ph'] : null;
    $temperature_data[] = isset($rec['temperature']) ? (float)$rec['temperature'] : null;
    $nitrogen_data[] = isset($rec['nitrogen']) ? (float)$rec['nitrogen'] : null;
    $phosphorus_data[] = isset($rec['phosphorus']) ? (float)$rec['phosphorus'] : null;
    $potassium_data[] = isset($rec['potassium']) ? (float)$rec['potassium'] : null;
}

if (empty($chart_labels)) {
    $chart_labels = ['No Data'];
    $moisture_data = [0];
    $ph_data = [0];
    $temperature_data = [0];
    $nitrogen_data = [0];
    $phosphorus_data = [0];
    $potassium_data = [0];
}

// Color-coding evaluation logic
function evaluateParam($type, $val) {
    if ($val === null || $val === '') {
        return ['color' => '#6b7280', 'bg' => '#f3f4f6', 'class' => 'neutral', 'label' => 'No Data', 'code' => 'neutral'];
    }
    $v = floatval($val);
    switch ($type) {
        case 'moisture':
            if ($v < 30) return ['color' => '#dc2626', 'bg' => '#fee2e2', 'class' => 'critical', 'label' => 'Critical (Dry)', 'code' => 'red'];
            if ($v > 60) return ['color' => '#ea580c', 'bg' => '#fef3c7', 'class' => 'warning', 'label' => 'High (Wet)', 'code' => 'orange'];
            return ['color' => '#16a34a', 'bg' => '#dcfce7', 'class' => 'optimal', 'label' => 'Optimal', 'code' => 'green'];
            
        case 'ph':
            if ($v < 5.0) return ['color' => '#dc2626', 'bg' => '#fee2e2', 'class' => 'critical', 'label' => 'Acidic (Low)', 'code' => 'red'];
            if ($v > 7.5) return ['color' => '#ea580c', 'bg' => '#fef3c7', 'class' => 'warning', 'label' => 'Alkaline (High)', 'code' => 'orange'];
            return ['color' => '#16a34a', 'bg' => '#dcfce7', 'class' => 'optimal', 'label' => 'Optimal', 'code' => 'green'];
            
        case 'temp':
        case 'temperature':
            if ($v < 20) return ['color' => '#dc2626', 'bg' => '#fee2e2', 'class' => 'critical', 'label' => 'Cool (Low)', 'code' => 'red'];
            if ($v > 32) return ['color' => '#ea580c', 'bg' => '#fef3c7', 'class' => 'warning', 'label' => 'Heat Stress', 'code' => 'orange'];
            return ['color' => '#16a34a', 'bg' => '#dcfce7', 'class' => 'optimal', 'label' => 'Optimal', 'code' => 'green'];
            
        case 'n':
        case 'nitrogen':
            if ($v < 20) return ['color' => '#dc2626', 'bg' => '#fee2e2', 'class' => 'critical', 'label' => 'Deficient (Low)', 'code' => 'red'];
            if ($v > 50) return ['color' => '#ea580c', 'bg' => '#fef3c7', 'class' => 'warning', 'label' => 'High / Excess', 'code' => 'orange'];
            return ['color' => '#16a34a', 'bg' => '#dcfce7', 'class' => 'optimal', 'label' => 'Optimal', 'code' => 'green'];
            
        case 'p':
        case 'phosphorus':
            if ($v < 10) return ['color' => '#dc2626', 'bg' => '#fee2e2', 'class' => 'critical', 'label' => 'Deficient (Low)', 'code' => 'red'];
            if ($v > 30) return ['color' => '#ea580c', 'bg' => '#fef3c7', 'class' => 'warning', 'label' => 'High / Excess', 'code' => 'orange'];
            return ['color' => '#16a34a', 'bg' => '#dcfce7', 'class' => 'optimal', 'label' => 'Optimal', 'code' => 'green'];
            
        case 'k':
        case 'potassium':
            if ($v < 15) return ['color' => '#dc2626', 'bg' => '#fee2e2', 'class' => 'critical', 'label' => 'Deficient (Low)', 'code' => 'red'];
            if ($v > 50) return ['color' => '#ea580c', 'bg' => '#fef3c7', 'class' => 'warning', 'label' => 'High / Excess', 'code' => 'orange'];
            return ['color' => '#16a34a', 'bg' => '#dcfce7', 'class' => 'optimal', 'label' => 'Optimal', 'code' => 'green'];
    }
}

$has_data = ($latest !== null);

$moisture_raw = $has_data && isset($latest['moisture']) ? (float)$latest['moisture'] : null;
$ph_raw = $has_data && isset($latest['ph']) ? (float)$latest['ph'] : null;
$temp_raw = $has_data && isset($latest['temperature']) ? (float)$latest['temperature'] : null;
$n_raw = $has_data && isset($latest['nitrogen']) ? (float)$latest['nitrogen'] : null;
$p_raw = $has_data && isset($latest['phosphorus']) ? (float)$latest['phosphorus'] : null;
$k_raw = $has_data && isset($latest['potassium']) ? (float)$latest['potassium'] : null;

$m_status = evaluateParam('moisture', $moisture_raw);
$ph_status = evaluateParam('ph', $ph_raw);
$t_status = evaluateParam('temp', $temp_raw);
$n_status = evaluateParam('n', $n_raw);
$p_status = evaluateParam('p', $p_raw);
$k_status = evaluateParam('k', $k_raw);

$reading_time = ($has_data && isset($latest['created_at'])) ? date('g:i A', strtotime($latest['created_at'])) : '--';
$reading_date = ($has_data && isset($latest['created_at'])) ? date('M j, Y', strtotime($latest['created_at'])) : ($role === 'farmer' && !$assigned_info ? 'No Node Assigned' : 'No Readings Yet');

$active_node = ($role === 'admin') 
    ? ($latest['device_id'] ?? 'Node 1 (ESP32_GSM_01)') 
    : ($assigned_info ? $assigned_info['display_label'] : 'No Node Configured');

$is_outdated = true;
if ($has_data && isset($latest['created_at'])) {
    $diff = time() - strtotime($latest['created_at']);
    if ($diff < 600) {
        $is_outdated = false;
    }
}
?>

<div class="sub-view-panel-container">

    <!-- =========================================================
         1. 4 TOP METRIC CARDS ROW WITH COLOR CODING (Matches Image 3)
         ========================================================= -->
    <div class="overview-stats-grid">
        
        <!-- Soil Moisture -->
        <div class="stat-widget-card" style="border-top: 4px solid <?= $m_status['color'] ?>;">
            <div class="stat-widget-top">
                <div class="stat-icon-label">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="<?= $m_status['color'] ?>" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
                    </svg>
                    <span>Soil moisture</span>
                </div>
                <span class="badge-pill <?= $m_status['class'] ?>">
                    <?= $m_status['label'] ?>
                </span>
            </div>
            <div class="stat-widget-bottom">
                <div class="stat-big-value" id="home-val-moisture"><?= $moisture_raw !== null ? number_format((float)$moisture_raw, 1) . '%' : '--' ?></div>
                <div class="stat-sub-text">Target: 30% – 60%</div>
            </div>
        </div>

        <!-- Soil pH -->
        <div class="stat-widget-card" style="border-top: 4px solid <?= $ph_status['color'] ?>;">
            <div class="stat-widget-top">
                <div class="stat-icon-label">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="<?= $ph_status['color'] ?>" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/>
                        <path d="M2 21c0-3 1.85-5.36 5.08-6"/>
                    </svg>
                    <span>Soil pH</span>
                </div>
                <span class="badge-pill <?= $ph_status['class'] ?>">
                    <?= $ph_status['label'] ?>
                </span>
            </div>
            <div class="stat-widget-bottom">
                <div class="stat-big-value" id="home-val-ph"><?= $ph_raw !== null ? number_format((float)$ph_raw, 1) : '--' ?></div>
                <div class="stat-sub-text">Target: 5.0 – 7.5</div>
            </div>
        </div>

        <!-- Soil Temperature -->
        <div class="stat-widget-card" style="border-top: 4px solid <?= $t_status['color'] ?>;">
            <div class="stat-widget-top">
                <div class="stat-icon-label">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="<?= $t_status['color'] ?>" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z"/>
                    </svg>
                    <span>Soil temperature</span>
                </div>
                <span class="badge-pill <?= $t_status['class'] ?>">
                    <?= $t_status['label'] ?>
                </span>
            </div>
            <div class="stat-widget-bottom">
                <div class="stat-big-value" id="home-val-temp"><?= $temp_raw !== null ? number_format((float)$temp_raw, 1) . '°C' : '--' ?></div>
                <div class="stat-sub-text">Target: 20°C – 32°C</div>
            </div>
        </div>

        <!-- Last Reading -->
        <div class="stat-widget-card" style="border-top: 4px solid <?= !$has_data ? '#9ca3af' : ($is_outdated ? '#f59e0b' : '#16a34a') ?>;">
            <div class="stat-widget-top">
                <div class="stat-icon-label">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <span>Last reading</span>
                </div>
                <span class="badge-pill <?= !$has_data ? 'neutral' : ($is_outdated ? 'warning' : 'optimal') ?>">
                    <?= !$has_data ? 'No Data' : ($is_outdated ? 'Outdated' : 'Live') ?>
                </span>
            </div>
            <div class="stat-widget-bottom">
                <div class="stat-time-value" id="home-val-time"><?= $reading_time ?></div>
                <div class="stat-sub-text" id="home-val-date"><?= $reading_date ?></div>
            </div>
        </div>

    </div>

    <!-- =========================================================
         2. SOIL NUTRIENTS CARD (WITH N-P-K COLOR CODING)
         ========================================================= -->
    <div class="nutrients-summary-card">
        <div class="card-header-bar">
            <div class="card-title">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22v-9"/>
                    <path d="M12 13a5 5 0 0 0-5-5H3a9 9 0 0 0 9 9Z"/>
                    <path d="M12 13a5 5 0 0 1 5-5h4a9 9 0 0 1-9 9Z"/>
                </svg>
                Soil nutrients
            </div>
            <span class="badge-pill warning" style="background:#fef3c7; color:#92400e;">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                Hardware verification unavailable
            </span>
        </div>

        <div class="nutrients-columns-grid">
            
            <!-- Nitrogen -->
            <div class="nutrient-col-item" style="border-left: 4px solid <?= $n_status['color'] ?>;">
                <div class="nutrient-col-header">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="<?= $n_status['color'] ?>" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22v-9"/>
                            <path d="M12 13a5 5 0 0 0-5-5H3a9 9 0 0 0 9 9Z"/>
                        </svg>
                        <span>Nitrogen (N)</span>
                    </div>
                    <span class="badge-pill <?= $n_status['class'] ?>"><?= $n_status['label'] ?></span>
                </div>
                <div style="display: flex; align-items: baseline; gap: 8px; margin-top: 8px;">
                    <span class="nutrient-big-num"><?= $n_raw !== null ? htmlspecialchars((string)round($n_raw)) : '--' ?></span>
                    <span class="nutrient-unit">mg/kg</span>
                </div>
                <div style="font-size: 14.5px; font-weight: 700; color: var(--text-muted); margin-top: 4px;">Target: 20 – 50 mg/kg</div>
            </div>

            <!-- Phosphorus -->
            <div class="nutrient-col-item" style="border-left: 4px solid <?= $p_status['color'] ?>;">
                <div class="nutrient-col-header">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="<?= $p_status['color'] ?>" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3"/>
                            <circle cx="19" cy="6" r="2"/>
                            <circle cx="5" cy="6" r="2"/>
                        </svg>
                        <span>Phosphorus (P)</span>
                    </div>
                    <span class="badge-pill <?= $p_status['class'] ?>"><?= $p_status['label'] ?></span>
                </div>
                <div style="display: flex; align-items: baseline; gap: 8px; margin-top: 8px;">
                    <span class="nutrient-big-num"><?= $p_raw !== null ? htmlspecialchars((string)round($p_raw)) : '--' ?></span>
                    <span class="nutrient-unit">mg/kg</span>
                </div>
                <div style="font-size: 14.5px; font-weight: 700; color: var(--text-muted); margin-top: 4px;">Target: 10 – 30 mg/kg</div>
            </div>

            <!-- Potassium -->
            <div class="nutrient-col-item" style="border-left: 4px solid <?= $k_status['color'] ?>;">
                <div class="nutrient-col-header">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="<?= $k_status['color'] ?>" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/>
                        </svg>
                        <span>Potassium (K)</span>
                    </div>
                    <span class="badge-pill <?= $k_status['class'] ?>"><?= $k_status['label'] ?></span>
                </div>
                <div style="display: flex; align-items: baseline; gap: 8px; margin-top: 8px;">
                    <span class="nutrient-big-num"><?= $k_raw !== null ? htmlspecialchars((string)round($k_raw)) : '--' ?></span>
                    <span class="nutrient-unit">mg/kg</span>
                </div>
                <div style="font-size: 14.5px; font-weight: 700; color: var(--text-muted); margin-top: 4px;">Target: 15 – 50 mg/kg</div>
            </div>

        </div>

        <div class="nutrients-footer-note">
            Verify NPK readings before making fertilizer decisions. Color Legend: 
            <span style="color:#dc2626; font-weight:700;">● Red (Deficient/Low)</span> &nbsp;|&nbsp; 
            <span style="color:#16a34a; font-weight:700;">● Green (Optimal)</span> &nbsp;|&nbsp; 
            <span style="color:#ea580c; font-weight:700;">● Orange (High/Excess)</span>
        </div>
    </div>

    <!-- =========================================================
         3. 2-COLUMN SPLIT: FIELD CONDITION & MONITORING NODE
         ========================================================= -->
    <div class="two-column-split-grid">
        
        <!-- Field Condition Card -->
        <div class="card-panel">
            <div class="card-title" style="margin-bottom: 16px;">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22v-9"/>
                    <path d="M12 13a5 5 0 0 0-5-5H3a9 9 0 0 0 9 9Z"/>
                </svg>
                Field condition
            </div>

            <div class="condition-status-box <?= !$has_data ? 'neutral' : ($is_outdated ? 'warning' : 'optimal') ?>">
                <div class="condition-icon-badge" style="color: <?= !$has_data ? '#6b7280' : ($is_outdated ? '#ea580c' : '#16a34a') ?>;">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                </div>
                <div class="condition-title">
                    <?= !$has_data 
                        ? 'No Monitoring Node Assigned' 
                        : ($is_outdated ? 'A fresh reading is needed' : 'Optimal Field Conditions') ?>
                </div>
                <div class="condition-desc">
                    <?= !$has_data 
                        ? 'This farmer account has no active hardware node assigned yet. Contact your administrator.' 
                        : ($is_outdated ? 'The last reading is over 10 minutes old. Check the monitoring node.' : 'All sensor telemetry parameters are in good health.') ?>
                </div>
            </div>

            <div style="font-size: 14.5px; font-weight: 600; color: var(--text-muted);">
                Reference ranges: moisture 20-80%; pH 5.5-7.5.
            </div>
        </div>

        <!-- Monitoring Node Card -->
        <div class="card-panel" style="display: flex; flex-direction: column; justify-content: space-between;">
            <div class="card-title">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="2" width="20" height="8" rx="2"/>
                    <rect x="2" y="14" width="20" height="8" rx="2"/>
                    <line x1="6" y1="6" x2="6.01" y2="6"/>
                    <line x1="6" y1="18" x2="6.01" y2="18"/>
                </svg>
                Monitoring node
            </div>

            <div class="node-center-info">
                <div class="node-icon-circle">
                    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12.55a11 11 0 0 1 14.08 0"/>
                        <path d="M1.42 9a16 16 0 0 1 21.16 0"/>
                        <path d="M8.53 16.11a6 6 0 0 1 6.95 0"/>
                        <line x1="12" y1="20" x2="12.01" y2="20"/>
                    </svg>
                </div>
                <span class="node-id-label"><?= htmlspecialchars($active_node) ?></span>
                <span class="node-status-text"><?= !$has_data ? 'Standby / Unassigned' : ($is_outdated ? 'Last reading is outdated' : 'Active Connection') ?></span>
                
                <?php if ($role === 'admin'): ?>
                    <a href="dashboard.php?page=devices_manage" class="btn-outline">
                        Review hardware
                    </a>
                <?php else: ?>
                    <a href="dashboard.php?page=soil" class="btn-outline">
                        View Telemetry
                    </a>
                <?php endif; ?>
            </div>

            <div style="font-size: 14px; font-weight: 600; color: var(--text-muted); text-align: center;">
                <?= $has_data ? 'Node synchronization active via GSM telemetry.' : 'No telemetry hardware currently streaming to this profile.' ?>
            </div>
        </div>

    </div>

    <!-- =========================================================
         4. READING TRENDS (MOISTURE, PH, TEMPERATURE & N-P-K TRENDS)
         ========================================================= -->
    <div class="card-panel">
        <div class="card-header-bar">
            <div>
                <div class="card-title">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="20" x2="18" y2="10"/>
                        <line x1="12" y1="20" x2="12" y2="4"/>
                        <line x1="6" y1="20" x2="6" y2="14"/>
                    </svg>
                    Reading trends
                </div>
                <div class="card-subtitle">The latest 20 recorded readings across reporting nodes (Soil conditions & NPK telemetry).</div>
            </div>
            <div style="font-size: 14px; font-weight: 700; color: var(--text-muted);">
                Refreshes every 15 seconds
            </div>
        </div>

        <!-- Soil Physical Parameters (Row 1) -->
        <div class="trends-charts-row" style="margin-top: 15px;">
            
            <!-- Moisture Trend -->
            <div style="background:#fbfdfc; border: 1.5px solid #d4e2d8; border-radius: 12px; padding: 16px;">
                <div style="font-size: 15px; font-weight: 800; color: var(--text-heading); margin-bottom: 10px; display:flex; justify-content:space-between;">
                    <span>Moisture trend</span>
                    <span style="color:var(--text-muted); font-weight:700;">%</span>
                </div>
                <div style="height: 140px; position: relative;">
                    <canvas id="homeMoistureChart"></canvas>
                </div>
            </div>

            <!-- pH Trend -->
            <div style="background:#fbfdfc; border: 1.5px solid #d4e2d8; border-radius: 12px; padding: 16px;">
                <div style="font-size: 15px; font-weight: 800; color: var(--text-heading); margin-bottom: 10px; display:flex; justify-content:space-between;">
                    <span>pH trend</span>
                    <span style="color:var(--text-muted); font-weight:700;">pH</span>
                </div>
                <div style="height: 140px; position: relative;">
                    <canvas id="homePhChart"></canvas>
                </div>
            </div>

            <!-- Temperature Trend -->
            <div class="trend-card-span-mobile" style="background:#fbfdfc; border: 1.5px solid #d4e2d8; border-radius: 12px; padding: 16px;">
                <div style="font-size: 15px; font-weight: 800; color: var(--text-heading); margin-bottom: 10px; display:flex; justify-content:space-between;">
                    <span>Temperature trend</span>
                    <span style="color:var(--text-muted); font-weight:700;">°C</span>
                </div>
                <div style="height: 140px; position: relative;">
                    <canvas id="homeTempChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Soil NPK Nutrients Trends (Row 2) -->
        <div class="trends-charts-row" style="margin-top: 18px;">
            
            <!-- Nitrogen (N) Trend -->
            <div style="background:#fbfdfc; border: 1.5px solid #d4e2d8; border-radius: 12px; padding: 16px;">
                <div style="font-size: 15px; font-weight: 800; color: var(--text-heading); margin-bottom: 10px; display:flex; justify-content:space-between;">
                    <span>Nitrogen (N) trend</span>
                    <span style="color:var(--text-muted); font-weight:700;">mg/kg</span>
                </div>
                <div style="height: 140px; position: relative;">
                    <canvas id="homeNitrogenChart"></canvas>
                </div>
            </div>

            <!-- Phosphorus (P) Trend -->
            <div style="background:#fbfdfc; border: 1.5px solid #d4e2d8; border-radius: 12px; padding: 16px;">
                <div style="font-size: 15px; font-weight: 800; color: var(--text-heading); margin-bottom: 10px; display:flex; justify-content:space-between;">
                    <span>Phosphorus (P) trend</span>
                    <span style="color:var(--text-muted); font-weight:700;">mg/kg</span>
                </div>
                <div style="height: 140px; position: relative;">
                    <canvas id="homePhosphorusChart"></canvas>
                </div>
            </div>

            <!-- Potassium (K) Trend -->
            <div class="trend-card-span-mobile" style="background:#fbfdfc; border: 1.5px solid #d4e2d8; border-radius: 12px; padding: 16px;">
                <div style="font-size: 15px; font-weight: 800; color: var(--text-heading); margin-bottom: 10px; display:flex; justify-content:space-between;">
                    <span>Potassium (K) trend</span>
                    <span style="color:var(--text-muted); font-weight:700;">mg/kg</span>
                </div>
                <div style="height: 140px; position: relative;">
                    <canvas id="homePotassiumChart"></canvas>
                </div>
            </div>
        </div>

    </div>

</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const labels = <?= json_encode($chart_labels) ?>;
    const mData = <?= json_encode($moisture_data) ?>;
    const phData = <?= json_encode($ph_data) ?>;
    const tData = <?= json_encode($temperature_data) ?>;
    const nData = <?= json_encode($nitrogen_data) ?>;
    const pData = <?= json_encode($phosphorus_data) ?>;
    const kData = <?= json_encode($potassium_data) ?>;

    const commonOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { display: false },
            y: { grid: { color: '#f0f4f1' }, ticks: { font: { size: 10 } } }
        },
        elements: {
            point: { radius: 2, hoverRadius: 4 },
            line: { tension: 0.35, borderWidth: 2 }
        }
    };

    // Moisture Chart
    const ctxM = document.getElementById('homeMoistureChart')?.getContext('2d');
    if (ctxM) {
        new Chart(ctxM, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    data: mData,
                    borderColor: '#1e593d',
                    backgroundColor: 'rgba(30, 89, 61, 0.08)',
                    fill: true
                }]
            },
            options: commonOptions
        });
    }

    // pH Chart
    const ctxPh = document.getElementById('homePhChart')?.getContext('2d');
    if (ctxPh) {
        new Chart(ctxPh, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    data: phData,
                    borderColor: '#2b7a4b',
                    backgroundColor: 'rgba(43, 122, 75, 0.08)',
                    fill: true
                }]
            },
            options: commonOptions
        });
    }

    // Temp Chart
    const ctxT = document.getElementById('homeTempChart')?.getContext('2d');
    if (ctxT) {
        new Chart(ctxT, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    data: tData,
                    borderColor: '#d97706',
                    backgroundColor: 'rgba(217, 119, 6, 0.08)',
                    fill: true
                }]
            },
            options: commonOptions
        });
    }

    // Nitrogen Chart (Green)
    const ctxN = document.getElementById('homeNitrogenChart')?.getContext('2d');
    if (ctxN) {
        new Chart(ctxN, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    data: nData,
                    borderColor: '#16a34a',
                    backgroundColor: 'rgba(22, 163, 74, 0.08)',
                    fill: true
                }]
            },
            options: commonOptions
        });
    }

    // Phosphorus Chart (Blue-Green)
    const ctxP = document.getElementById('homePhosphorusChart')?.getContext('2d');
    if (ctxP) {
        new Chart(ctxP, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    data: pData,
                    borderColor: '#0284c7',
                    backgroundColor: 'rgba(2, 132, 199, 0.08)',
                    fill: true
                }]
            },
            options: commonOptions
        });
    }

    // Potassium Chart (Amber-Orange)
    const ctxK = document.getElementById('homePotassiumChart')?.getContext('2d');
    if (ctxK) {
        new Chart(ctxK, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    data: kData,
                    borderColor: '#ea580c',
                    backgroundColor: 'rgba(234, 88, 12, 0.08)',
                    fill: true
                }]
            },
            options: commonOptions
        });
    }
});
</script>