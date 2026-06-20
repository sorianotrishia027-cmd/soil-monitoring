<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include "../config/db_connect.php";

$role = strtolower($_SESSION['role'] ?? 'farmer');
$user_id = $_SESSION['user_id'] ?? 0;

// Gather real historical rows to display once available
if ($role === 'admin') {
    $stmt = $conn->query("SELECT s.*, u.username FROM sensor_data s LEFT JOIN users u ON s.user_id = u.id ORDER BY s.id DESC LIMIT 10");
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $conn->prepare("SELECT * FROM sensor_data WHERE user_id = ? ORDER BY id DESC LIMIT 10");
    $stmt->execute([$user_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<div class="sub-view-panel-container">
    <div class="view-panel-header">
        <h3>Soil Parameter Criteria Rules</h3>
        <p>Review standard ranges and threshold matrices used to evaluate crop growing conditions.</p>
    </div>

    <div class="matrix-list-container">
        <div class="parameter-sheet-card">
            <h4>Soil Moisture (Range: 0 – 100%)</h4>
            <ul>
                <li><strong>&lt; 30% (Dry):</strong> Requires immediate irrigation intervention.</li>
                <li><strong>30% – 60% (Optimal):</strong> Favorable status bounds.</li>
                <li><strong>&gt; 60% (Wet / Saturated):</strong> Halt water application; optimize drainage avenues.</li>
            </ul>
        </div>

        <div class="parameter-sheet-card">
            <h4>Soil pH Level (Range: 0 – 14 | Ideal: 5.5 – 7.0)</h4>
            <ul>
                <li><strong>&lt; 5.0 (Acidic):</strong> Requires lime or dolomite treatments.</li>
                <li><strong>5.0 – 7.5 (Optimal):</strong> Stable absorption environments.</li>
                <li><strong>&gt; 7.5 (Alkaline):</strong> Requires organic compound or sulfur additives.</li>
            </ul><br>
        </div>

        <div class="parameter-sheet-card">
            <h4>Temperature (Range: 0°C – 50°C | Ideal: 20°C – 30°C)</h4>
            <ul>
                <li><strong>&lt; 18°C (Cool / Low):</strong> Delay vulnerable seeding actions; apply mulch covers.</li>
                <li><strong>20°C – 32°C (Optimal):</strong> Superb conditions for baseline growth.</li>
                <li><strong>&gt; 35°C (High / Hot):</strong> High moisture evaporation risks; avoid midday watering routines.</li>
            </ul><br>
        </div>

        <div class="parameter-sheet-card">
            <h4>Nutrients (N-P-K) Unit: mg/kg</h4>
            <div class="nutrients-sub-grid">
                <div>
                    <h5>Nitrogen (N)</h5>
                    <p>&lt; 20: Low | 20 – 50: Optimal | &gt; 50: High</p>
                </div>
                <div>
                    <h5>Phosphorus (P)</h5>
                    <p>&lt; 10: Low | 10 – 30: Optimal | &gt; 30: High</p>
                </div>
                <div>
                    <h5>Potassium (K)</h5>
                    <p>&lt; 15: Low | 15 – 50: Optimal | &gt; 50: High</p>
                </div>
            </div>
        </div>
    </div>

    <div class="view-panel-header" style="margin-top: 30px;">
        <h3>📋 Recent Telemetry History</h3>
        <p><?= $role === 'admin' ? 'System Audit View: Reviewing last 10 transmissions across all active field nodes.' : 'Review your field\'s last 10 logged analysis data profiles.' ?></p>
    </div>

    <div class="history-table-wrapper" style="overflow-x: auto; background: #fff; padding: 15px; border-radius: 8px; border: 1px solid #e2e8e2;">
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 2px solid #e2e8e2; color: #424242;">
                    <?php if($role === 'admin'): ?><th style="padding: 10px;">Farmer</th><?php endif; ?>
                    <th style="padding: 10px;">Moisture</th>
                    <th style="padding: 10px;">pH</th>
                    <th style="padding: 10px;">Temp</th>
                    <th style="padding: 10px;">N-P-K</th>
                    <th style="padding: 10px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history)): ?>
                    <tr>
                        <td colspan="<?= $role === 'admin' ? 6 : 5 ?>" style="padding: 15px; text-align: center; color: #888;">No historical entries recorded. Data streams will initialize when hardware goes online.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($history as $row): ?>
                        <tr style="border-bottom: 1px solid #f0f4f0;">
                            <?php if($role === 'admin'): ?>
                                <td style="padding: 10px; font-weight: bold;"><?= htmlspecialchars($row['username'] ?? 'System / Unlinked') ?></td>
                            <?php endif; ?>
                            <td style="padding: 10px;"><?= htmlspecialchars($row['moisture']) ?>%</td>
                            <td style="padding: 10px;"><?= htmlspecialchars($row['ph_level']) ?></td>
                            <td style="padding: 10px;"><?= htmlspecialchars($row['temperature']) ?>°C</td>
                            <td style="padding: 10px;"><?= htmlspecialchars($row['nitrogen']) . '-' . htmlspecialchars($row['phosphorus']) . '-' . htmlspecialchars($row['potassium']) ?></td>
                            <td style="padding: 10px;"><span class="status-pill"><?= htmlspecialchars($row['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>