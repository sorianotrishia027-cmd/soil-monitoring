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
| GET ASSIGNED NODE FOR FARMER
|--------------------------------------------------------------------------
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
        $stmt->execute([$assigned_device_id]);
        $latest = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $latest = null;
    }
} catch (PDOException $e) {
    $latest = null;
}

/*
|--------------------------------------------------------------------------
| HISTORICAL ANALYTICAL DATA (Latest 20)
|--------------------------------------------------------------------------
*/
try {
    if ($role === 'admin') {
        $history_stmt = $conn->query("
            SELECT id, moisture, ph, temperature, nitrogen, phosphorus, potassium, created_at
            FROM soil_readings
            ORDER BY created_at DESC, id DESC
            LIMIT 20
        ");
        $history_records = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($assigned_device_id !== null) {
        $history_stmt = $conn->prepare("
            SELECT id, moisture, ph, temperature, nitrogen, phosphorus, potassium, created_at
            FROM soil_readings
            WHERE device_id = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 20
        ");
        $history_stmt->execute([$assigned_device_id]);
        $history_records = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $history_records = [];
    }
} catch (PDOException $e) {
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

$moisture_raw = $latest['moisture'] ?? 0.0;
$ph_raw = $latest['ph'] ?? 6.6;
$temp_raw = $latest['temperature'] ?? 31.6;
$n_raw = $latest['nitrogen'] ?? 46;
$p_raw = $latest['phosphorus'] ?? 24;
$k_raw = $latest['potassium'] ?? 74;

$m_status = evaluateParam('moisture', $moisture_raw);
$ph_status = evaluateParam('ph', $ph_raw);
$t_status = evaluateParam('temp', $temp_raw);
$n_status = evaluateParam('n', $n_raw);
$p_status = evaluateParam('p', $p_raw);
$k_status = evaluateParam('k', $k_raw);

$reading_time = ($latest && isset($latest['created_at'])) ? date('g:i A', strtotime($latest['created_at'])) : date('g:i A');
$reading_date = ($latest && isset($latest['created_at'])) ? date('M j, Y', strtotime($latest['created_at'])) : date('M j, Y');

$active_node = ($role === 'admin') 
    ? ($latest['device_id'] ?? 'ESP32_GSM_01') 
    : ($assigned_device_id ?? 'No Node Configured');

$is_outdated = true;
if ($latest && isset($latest['created_at'])) {
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
        <div class="stat-widget-card" style="border-top: 3px solid <?= $m_status['color'] ?>;">
            <div class="stat-widget-top">
                <div class="stat-icon-label">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="<?= $m_status['color'] ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
                    </svg>
                    <span>Soil moisture</span>
                </div>
                <span class="badge-pill <?= $m_status['class'] ?>" style="font-size: 10.5px; padding: 2px 8px;">
                    <?= $m_status['label'] ?>
                </span>
            </div>
            <div class="stat-widget-bottom">
                <div class="stat-big-value" id="home-val-moisture"><?= number_format((float)$moisture_raw, 1) ?>%</div>
                <div class="stat-sub-text">Target: 30% – 60%</div>
            </div>
        </div>

        <!-- Soil pH -->
        <div class="stat-widget-card" style="border-top: 3px solid <?= $ph_status['color'] ?>;">
            <div class="stat-widget-top">
                <div class="stat-icon-label">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="<?= $ph_status['color'] ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/>
                        <path d="M2 21c0-3 1.85-5.36 5.08-6"/>
                    </svg>
                    <span>Soil pH</span>
                </div>
                <span class="badge-pill <?= $ph_status['class'] ?>" style="font-size: 10.5px; padding: 2px 8px;">
                    <?= $ph_status['label'] ?>
                </span>
            </div>
            <div class="stat-widget-bottom">
                <div class="stat-big-value" id="home-val-ph"><?= number_format((float)$ph_raw, 1) ?></div>
                <div class="stat-sub-text">Target: 5.0 – 7.5</div>
            </div>
        </div>

        <!-- Soil Temperature -->
        <div class="stat-widget-card" style="border-top: 3px solid <?= $t_status['color'] ?>;">
            <div class="stat-widget-top">
                <div class="stat-icon-label">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="<?= $t_status['color'] ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z"/>
                    </svg>
                    <span>Soil temperature</span>
                </div>
                <span class="badge-pill <?= $t_status['class'] ?>" style="font-size: 10.5px; padding: 2px 8px;">
                    <?= $t_status['label'] ?>
                </span>
            </div>
            <div class="stat-widget-bottom">
                <div class="stat-big-value" id="home-val-temp"><?= number_format((float)$temp_raw, 1) ?>°C</div>
                <div class="stat-sub-text">Target: 20°C – 32°C</div>
            </div>
        </div>

        <!-- Last Reading -->
        <div class="stat-widget-card" style="border-top: 3px solid <?= $is_outdated ? '#f59e0b' : '#16a34a' ?>;">
            <div class="stat-widget-top">
                <div class="stat-icon-label">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <span>Last reading</span>
                </div>
                <span class="badge-pill <?= $is_outdated ? 'warning' : 'optimal' ?>" style="font-size: 10.5px; padding: 2px 8px;">
                    <?= $is_outdated ? 'Outdated' : 'Live' ?>
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
            <div class="nutrient-col-item" style="border-left: 3px solid <?= $n_status['color'] ?>; padding-left: 14px;">
                <div class="nutrient-col-header" style="justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="<?= $n_status['color'] ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22v-9"/>
                            <path d="M12 13a5 5 0 0 0-5-5H3a9 9 0 0 0 9 9Z"/>
                        </svg>
                        <span>Nitrogen (N)</span>
                    </div>
                    <span class="badge-pill <?= $n_status['class'] ?>" style="font-size: 10px; padding: 2px 6px;"><?= $n_status['label'] ?></span>
                </div>
                <div style="display: flex; align-items: baseline; gap: 6px; margin-top: 6px;">
                    <span class="nutrient-big-num" style="margin-left:0;"><?= htmlspecialchars((string)$n_raw) ?></span>
                    <span class="nutrient-unit" style="margin-left:0;">mg/kg</span>
                </div>
                <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 2px;">Target: 20 – 50 mg/kg</div>
            </div>

            <!-- Phosphorus -->
            <div class="nutrient-col-item" style="border-left: 3px solid <?= $p_status['color'] ?>; padding-left: 14px;">
                <div class="nutrient-col-header" style="justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="<?= $p_status['color'] ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3"/>
                            <circle cx="19" cy="6" r="2"/>
                            <circle cx="5" cy="6" r="2"/>
                        </svg>
                        <span>Phosphorus (P)</span>
                    </div>
                    <span class="badge-pill <?= $p_status['class'] ?>" style="font-size: 10px; padding: 2px 6px;"><?= $p_status['label'] ?></span>
                </div>
                <div style="display: flex; align-items: baseline; gap: 6px; margin-top: 6px;">
                    <span class="nutrient-big-num" style="margin-left:0;"><?= htmlspecialchars((string)$p_raw) ?></span>
                    <span class="nutrient-unit" style="margin-left:0;">mg/kg</span>
                </div>
                <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 2px;">Target: 10 – 30 mg/kg</div>
            </div>

            <!-- Potassium -->
            <div class="nutrient-col-item" style="border-left: 3px solid <?= $k_status['color'] ?>; padding-left: 14px;">
                <div class="nutrient-col-header" style="justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="<?= $k_status['color'] ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/>
                        </svg>
                        <span>Potassium (K)</span>
                    </div>
                    <span class="badge-pill <?= $k_status['class'] ?>" style="font-size: 10px; padding: 2px 6px;"><?= $k_status['label'] ?></span>
                </div>
                <div style="display: flex; align-items: baseline; gap: 6px; margin-top: 6px;">
                    <span class="nutrient-big-num" style="margin-left:0;"><?= htmlspecialchars((string)$k_raw) ?></span>
                    <span class="nutrient-unit" style="margin-left:0;">mg/kg</span>
                </div>
                <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 2px;">Target: 15 – 50 mg/kg</div>
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

            <div class="condition-status-box <?= !$is_outdated ? 'optimal' : '' ?>">
                <div class="condition-icon-badge" style="color: <?= $is_outdated ? '#ea580c' : '#16a34a' ?>;">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/>
                    </svg>
                </div>
                <div class="condition-title">
                    <?= $is_outdated ? 'A fresh reading is needed' : 'Optimal Field Conditions' ?>
                </div>
                <div class="condition-desc">
                    <?= $is_outdated 
                        ? 'The last reading is over 10 minutes old. Check the monitoring node.' 
                        : 'All sensor telemetry parameters are in good health.' ?>
                </div>
            </div>

            <div style="font-size: 12px; color: var(--text-muted);">
                Reference ranges: moisture 20-80%; pH 5.5-7.5.
            </div>
        </div>

        <!-- Monitoring Node Card -->
        <div class="card-panel" style="display: flex; flex-direction: column; justify-content: space-between;">
            <div class="card-title">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="2" width="20" height="8" rx="2"/>
                    <rect x="2" y="14" width="20" height="8" rx="2"/>
                    <line x1="6" y1="6" x2="6.01" y2="6"/>
                    <line x1="6" y1="18" x2="6.01" y2="18"/>
                </svg>
                Monitoring node
            </div>

            <div class="node-center-info">
                <div class="node-icon-circle">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12.55a11 11 0 0 1 14.08 0"/>
                        <path d="M1.42 9a16 16 0 0 1 21.16 0"/>
                        <path d="M8.53 16.11a6 6 0 0 1 6.95 0"/>
                        <line x1="12" y1="20" x2="12.01" y2="20"/>
                    </svg>
                </div>
                <span class="node-id-label"><?= htmlspecialchars($active_node) ?></span>
                <span class="node-status-text"><?= $is_outdated ? 'Last reading is outdated' : 'Active Connection' ?></span>
                
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

            <div style="font-size: 12px; color: var(--text-muted); text-align: center;">
                Node synchronization active via GSM telemetry.
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
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="20" x2="18" y2="10"/>
                        <line x1="12" y1="20" x2="12" y2="4"/>
                        <line x1="6" y1="20" x2="6" y2="14"/>
                    </svg>
                    Reading trends
                </div>
                <div class="card-subtitle">The latest 20 recorded readings across reporting nodes (Soil conditions & NPK telemetry).</div>
            </div>
            <div style="font-size: 12px; color: var(--text-muted);">
                Refreshes every 15 seconds
            </div>
        </div>

        <!-- Soil Physical Parameters (Row 1) -->
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 15px;">
            
            <!-- Moisture Trend -->
            <div style="background:#fafdfb; border: 1px solid #e1e9e3; border-radius: 10px; padding: 14px;">
                <div style="font-size: 12.5px; font-weight: 700; color: var(--text-heading); margin-bottom: 8px; display:flex; justify-content:space-between;">
                    <span>Moisture trend</span>
                    <span style="color:#6b7280; font-weight:500;">%</span>
                </div>
                <div style="height: 130px; position: relative;">
                    <canvas id="homeMoistureChart"></canvas>
                </div>
            </div>

            <!-- pH Trend -->
            <div style="background:#fafdfb; border: 1px solid #e1e9e3; border-radius: 10px; padding: 14px;">
                <div style="font-size: 12.5px; font-weight: 700; color: var(--text-heading); margin-bottom: 8px; display:flex; justify-content:space-between;">
                    <span>pH trend</span>
                    <span style="color:#6b7280; font-weight:500;">pH</span>
                </div>
                <div style="height: 130px; position: relative;">
                    <canvas id="homePhChart"></canvas>
                </div>
            </div>

            <!-- Temperature Trend -->
            <div style="background:#fafdfb; border: 1px solid #e1e9e3; border-radius: 10px; padding: 14px;">
                <div style="font-size: 12.5px; font-weight: 700; color: var(--text-heading); margin-bottom: 8px; display:flex; justify-content:space-between;">
                    <span>Temperature trend</span>
                    <span style="color:#6b7280; font-weight:500;">°C</span>
                </div>
                <div style="height: 130px; position: relative;">
                    <canvas id="homeTempChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Soil NPK Nutrients Trends (Row 2) -->
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 16px;">
            
            <!-- Nitrogen (N) Trend -->
            <div style="background:#fafdfb; border: 1px solid #e1e9e3; border-radius: 10px; padding: 14px;">
                <div style="font-size: 12.5px; font-weight: 700; color: var(--text-heading); margin-bottom: 8px; display:flex; justify-content:space-between;">
                    <span>Nitrogen (N) trend</span>
                    <span style="color:#6b7280; font-weight:500;">mg/kg</span>
                </div>
                <div style="height: 130px; position: relative;">
                    <canvas id="homeNitrogenChart"></canvas>
                </div>
            </div>

            <!-- Phosphorus (P) Trend -->
            <div style="background:#fafdfb; border: 1px solid #e1e9e3; border-radius: 10px; padding: 14px;">
                <div style="font-size: 12.5px; font-weight: 700; color: var(--text-heading); margin-bottom: 8px; display:flex; justify-content:space-between;">
                    <span>Phosphorus (P) trend</span>
                    <span style="color:#6b7280; font-weight:500;">mg/kg</span>
                </div>
                <div style="height: 130px; position: relative;">
                    <canvas id="homePhosphorusChart"></canvas>
                </div>
            </div>

            <!-- Potassium (K) Trend -->
            <div style="background:#fafdfb; border: 1px solid #e1e9e3; border-radius: 10px; padding: 14px;">
                <div style="font-size: 12.5px; font-weight: 700; color: var(--text-heading); margin-bottom: 8px; display:flex; justify-content:space-between;">
                    <span>Potassium (K) trend</span>
                    <span style="color:#6b7280; font-weight:500;">mg/kg</span>
                </div>
                <div style="height: 130px; position: relative;">
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