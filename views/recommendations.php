<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn)) {
    require_once __DIR__ . '/../config/db_connect.php';
}

$role = strtolower(trim($_SESSION['role'] ?? ''));
$user_id = intval($_SESSION['user_id'] ?? 0);

$latest = null;

if ($role === 'admin') {
    $stmt = $conn->query("SELECT * FROM soil_readings ORDER BY created_at DESC, id DESC LIMIT 1");
    $latest = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($role === 'farmer' && $user_id > 0) {
    $assignedInfo = get_assigned_device_for_user($conn, $user_id);
    if ($assignedInfo && !empty($assignedInfo['aliases'])) {
        $placeholders = implode(',', array_fill(0, count($assignedInfo['aliases']), '?'));
        $stmt = $conn->prepare("SELECT * FROM soil_readings WHERE device_id IN ($placeholders) ORDER BY created_at DESC, id DESC LIMIT 1");
        $stmt->execute($assignedInfo['aliases']);
        $latest = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

$moisture = isset($latest['moisture']) ? floatval($latest['moisture']) : 45.0;
$ph = isset($latest['ph']) ? floatval($latest['ph']) : 6.5;
$n = isset($latest['nitrogen']) ? floatval($latest['nitrogen']) : 35;
$p = isset($latest['phosphorus']) ? floatval($latest['phosphorus']) : 22;
$k = isset($latest['potassium']) ? floatval($latest['potassium']) : 30;
$temp = isset($latest['temperature']) ? floatval($latest['temperature']) : 26.0;

$recommendations = [];

// Moisture rules
if ($moisture < 30) {
    $recommendations[] = [
        'title' => 'Critical Irrigation Needed',
        'category' => 'Water Management',
        'badge' => 'critical',
        'desc' => "Soil moisture is critically low ({$moisture}%). Initiate a 2-3 hour field flood irrigation cycle immediately to prevent crop drought stress."
    ];
} elseif ($moisture > 60) {
    $recommendations[] = [
        'title' => 'Halt Active Irrigation',
        'category' => 'Drainage',
        'badge' => 'warning',
        'desc' => "Soil moisture is elevated ({$moisture}%). Open perimeter drainage outlets to prevent prolonged anaerobic standing water and root rot."
    ];
} else {
    $recommendations[] = [
        'title' => 'Moisture Level Optimal',
        'category' => 'Irrigation',
        'badge' => 'optimal',
        'desc' => "Current soil moisture ({$moisture}%) is optimal for lowland rice growth stages. Maintain regular cycle schedules."
    ];
}

// pH rules
if ($ph < 5.0) {
    $recommendations[] = [
        'title' => 'Apply Agricultural Lime (CaCO3)',
        'category' => 'Soil Conditioning',
        'badge' => 'critical',
        'desc' => "Soil acidity is elevated (pH {$ph}). Broadcast 200-300 kg/ha of calcitic lime during land preparation to neutralize acidity and unlock bound nutrients."
    ];
} elseif ($ph > 7.5) {
    $recommendations[] = [
        'title' => 'Incorporate Organic Compost & Sulfur',
        'category' => 'Soil Conditioning',
        'badge' => 'warning',
        'desc' => "Soil alkalinity is high (pH {$ph}). Apply decomposed organic rice hull mulch or elemental sulfur to gradually lower soil pH."
    ];
} else {
    $recommendations[] = [
        'title' => 'Soil pH in Ideal Range',
        'category' => 'Soil Conditioning',
        'badge' => 'optimal',
        'desc' => "Soil pH ({$ph}) is in the ideal 5.5 - 7.0 buffer zone for maximum macro and micro-nutrient absorption."
    ];
}

// Nutrients rules
if ($n < 20) {
    $recommendations[] = [
        'title' => 'Nitrogen Top-Dressing Required',
        'category' => 'Fertilizer Application',
        'badge' => 'warning',
        'desc' => "Low nitrogen concentration detected ({$n} mg/kg). Apply Urea (46-0-0) or Ammonium Sulfate at early tillering stage."
    ];
}

if ($p < 10) {
    $recommendations[] = [
        'title' => 'Phosphorus Supplementation (16-20-0 / 0-20-0)',
        'category' => 'Root Development',
        'badge' => 'warning',
        'desc' => "Available phosphorus is below threshold ({$p} mg/kg). Apply Solophos or complete fertilizer during basal soil preparation."
    ];
}

if ($k < 15) {
    $recommendations[] = [
        'title' => 'Potassium Application (Muriate of Potash 0-0-60)',
        'category' => 'Grain Filling & Stalk Strength',
        'badge' => 'warning',
        'desc' => "Low potassium detected ({$k} mg/kg). Apply MOP at panicle initiation to enhance grain weight and lodging resistance."
    ];
}
?>

<div class="sub-view-panel-container">

    <div class="view-panel-header">
        <h3>Agronomic Guidance & Advisory</h3>
        <p>Actionable crop management recommendations calculated from current sensor telemetry.</p>
    </div>

    <!-- Parameter Quick Overview Strip -->
    <div class="overview-stats-grid">
        <div class="stat-widget-card" style="min-height: auto; padding: 16px;">
            <span style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Moisture</span>
            <div style="font-size: 22px; font-weight: 800; color: var(--text-heading); margin-top: 4px;"><?= number_format($moisture, 1) ?>%</div>
        </div>
        <div class="stat-widget-card" style="min-height: auto; padding: 16px;">
            <span style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">pH Level</span>
            <div style="font-size: 22px; font-weight: 800; color: var(--text-heading); margin-top: 4px;"><?= number_format($ph, 1) ?></div>
        </div>
        <div class="stat-widget-card" style="min-height: auto; padding: 16px;">
            <span style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">NPK Ratio</span>
            <div style="font-size: 20px; font-weight: 800; color: var(--text-heading); margin-top: 4px;"><?= (int)$n ?>-<?= (int)$p ?>-<?= (int)$k ?></div>
        </div>
        <div class="stat-widget-card" style="min-height: auto; padding: 16px;">
            <span style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Temperature</span>
            <div style="font-size: 22px; font-weight: 800; color: var(--text-heading); margin-top: 4px;"><?= number_format($temp, 1) ?>°C</div>
        </div>
    </div>

    <!-- Recommendations Cards List -->
    <div style="display: flex; flex-direction: column; gap: 14px;">
        <?php foreach ($recommendations as $rec): ?>
            <div class="card-panel" style="padding: 20px 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="badge-pill <?= $rec['badge'] ?>">
                            <?= htmlspecialchars($rec['category']) ?>
                        </span>
                        <h4 style="font-size: 16px; font-weight: 700; color: var(--text-heading); margin: 0;">
                            <?= htmlspecialchars($rec['title']) ?>
                        </h4>
                    </div>
                </div>
                <p style="font-size: 13.5px; line-height: 1.55; color: var(--text-body); margin: 0;">
                    <?= htmlspecialchars($rec['desc']) ?>
                </p>
            </div>
        <?php endforeach; ?>
    </div>

</div>