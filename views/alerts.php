<div class="sub-view-panel-container">
    <div class="view-panel-header">
        <h3>System Diagnostics & Status Alerts</h3>
        <p>Real-time threshold alert status evaluations parsed for your profile assignment.</p>
    </div>

    <div class="alert-display-card-list">
        <div class="notification-event-strip status-border-green">
            <strong>Soil Moisture Status Log</strong>
            <p>Current moisture reading is outside baseline parameters (&lt; 30%).</p>
            <div class="role-action-box">
                <?php if ($role === 'farmer'): ?>
                    <p><strong>Action for Farmer:</strong> Water the field immediately; check irrigation lines; avoid planting sensitive crops until moisture rises.</p>
                <?php else: ?>
                    <p><strong>Action for Admin:</strong> Schedule irrigation monitoring; alert farmer if area is consistently dry; review water supply access.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="notification-event-strip status-border-green">
            <strong>Soil pH Level Alert</strong>
            <p>Soil chemistry exhibits an imbalance (&lt; 5.0 Acidic).</p>
            <div class="role-action-box">
                <?php if ($role === 'farmer'): ?>
                    <p><strong>Action for Farmer:</strong> Apply agricultural lime or dolomite to raise pH; reduce use of acidic fertilizers.</p>
                <?php else: ?>
                    <p><strong>Action for Admin:</strong> Recommend exact lime dosage based on soil type; follow up after 2–4 weeks.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="notification-event-strip status-border-green">
            <strong>Temperature Threshold Alert</strong>
            <p>Thermal readout shifts beyond optimal boundaries (&gt; 35°C High / Hot).</p>
            <div class="role-action-box">
                <?php if ($role === 'farmer'): ?>
                    <p><strong>Action for Farmer:</strong> Increase watering frequency; use organic mulch to reduce soil heat; avoid midday irrigation.</p>
                <?php else: ?>
                    <p><strong>Action for Admin:</strong> Issue heat stress warnings; recommend drought-resistant crop varieties.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>