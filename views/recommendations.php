<?php
// views/recommendations.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn)) {
    require_once __DIR__ . '/../config/db_connect.php';
}

$role = strtolower(trim($_SESSION['role'] ?? ''));
$user_id = intval($_SESSION['user_id'] ?? 0);

$latest = null;
$assignedInfo = null;

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

$has_data = ($latest !== null);
$recommendations = [];

$moisture = $has_data && isset($latest['moisture']) ? floatval($latest['moisture']) : null;
$ph = $has_data && isset($latest['ph']) ? floatval($latest['ph']) : null;
$n = $has_data && isset($latest['nitrogen']) ? floatval($latest['nitrogen']) : null;
$p = $has_data && isset($latest['phosphorus']) ? floatval($latest['phosphorus']) : null;
$k = $has_data && isset($latest['potassium']) ? floatval($latest['potassium']) : null;
$temp = $has_data && isset($latest['temperature']) ? floatval($latest['temperature']) : null;

if ($has_data) {
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
    if ($n !== null && $n < 20) {
        $recommendations[] = [
            'title' => 'Nitrogen Top-Dressing Required',
            'category' => 'Fertilizer Application',
            'badge' => 'warning',
            'desc' => "Available nitrogen is low ({$n} mg/kg). Side-dress with urea (46-0-0) or ammonium sulfate at panicle initiation to support tiller formation."
        ];
    }

    if ($p !== null && $p < 10) {
        $recommendations[] = [
            'title' => 'Phosphorus Supplementation Needed',
            'category' => 'Fertilizer Application',
            'badge' => 'warning',
            'desc' => "Phosphorus is deficient ({$p} mg/kg). Apply Solophos (0-18-0) or 16-20-0 during early basal fertilizer application to stimulate strong root development."
        ];
    }

    if ($k !== null && $k < 15) {
        $recommendations[] = [
            'title' => 'Potassium Boost Advised',
            'category' => 'Fertilizer Application',
            'badge' => 'warning',
            'desc' => "Potassium levels are low ({$k} mg/kg). Apply Muriate of Potash (0-0-60) to improve grain filling and increase resistance to lodging and pests."
        ];
    }

    if (empty($recommendations)) {
        $recommendations[] = [
            'title' => 'Standard Agronomic Management',
            'category' => 'Field Maintenance',
            'badge' => 'optimal',
            'desc' => "All physical and chemical parameters are currently balanced. Continue routine weekly monitoring."
        ];
    }
}
?>

<div class="sub-view-panel-container">

    <div class="view-panel-header">
        <h3>Agronomic Guidance & Advisory</h3>
        <p>Actionable crop management recommendations calculated from current sensor telemetry.</p>
    </div>

    <!-- Parameter Quick Overview Strip -->
    <div class="overview-stats-grid">
        <div class="stat-widget-card" style="min-height: auto; padding: 20px;">
            <span style="font-size: 13.5px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Moisture</span>
            <div style="font-size: 28px; font-weight: 800; color: var(--text-heading); margin-top: 6px;"><?= $moisture !== null ? number_format($moisture, 1) . '%' : '--' ?></div>
        </div>
        <div class="stat-widget-card" style="min-height: auto; padding: 20px;">
            <span style="font-size: 13.5px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">pH Level</span>
            <div style="font-size: 28px; font-weight: 800; color: var(--text-heading); margin-top: 6px;"><?= $ph !== null ? number_format($ph, 1) : '--' ?></div>
        </div>
        <div class="stat-widget-card" style="min-height: auto; padding: 20px;">
            <span style="font-size: 13.5px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">NPK Ratio</span>
            <div style="font-size: 26px; font-weight: 800; color: var(--text-heading); margin-top: 6px;"><?= ($n !== null && $p !== null && $k !== null) ? ((int)$n . '-' . (int)$p . '-' . (int)$k) : '--' ?></div>
        </div>
        <div class="stat-widget-card" style="min-height: auto; padding: 20px;">
            <span style="font-size: 13.5px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Temperature</span>
            <div style="font-size: 28px; font-weight: 800; color: var(--text-heading); margin-top: 6px;"><?= $temp !== null ? number_format($temp, 1) . '°C' : '--' ?></div>
        </div>
    </div>

    <!-- Recommendations Cards List -->
    <div style="display: flex; flex-direction: column; gap: 16px;">
        <?php if ($has_data && !empty($recommendations)): ?>
            <?php foreach ($recommendations as $rec): ?>
                <div class="card-panel" style="padding: 24px 26px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 10px;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <span class="badge-pill <?= $rec['badge'] ?>">
                                <?= htmlspecialchars($rec['category']) ?>
                            </span>
                            <h4 style="font-size: 18px; font-weight: 800; color: var(--text-heading); margin: 0;">
                                <?= htmlspecialchars($rec['title']) ?>
                            </h4>
                        </div>
                    </div>
                    <p style="font-size: 15.5px; line-height: 1.65; color: var(--text-body); margin: 0; font-weight: 500;">
                        <?= htmlspecialchars($rec['desc']) ?>
                    </p>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card-panel" style="text-align: center; padding: 44px 24px;">
                <div style="width: 54px; height: 54px; border-radius: 50%; background: #f1f5f9; color: #475569; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px;">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                </div>
                <h3 style="font-size: 20px; font-weight: 800; color: var(--text-heading); margin-bottom: 8px;">No Active Node Telemetry</h3>
                <p style="font-size: 15.5px; color: var(--text-muted); max-width: 500px; margin: 0 auto; font-weight: 600;">
                    No field monitoring node is currently linked to your account. Actionable fertilizer and crop guidance will automatically appear once a node is assigned.
                </p>
            </div>
        <?php endif; ?>
    </div>

</div>