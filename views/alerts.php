<div class="sub-view-panel-container">
    <div class="view-panel-header">
        <h3>🔔 System Operations Alerts & Node Logs</h3>
    </div>

    <div class="alert-display-card-list">
        <div class="notification-event-strip status-border-green">
            <strong>System Status Notification:</strong>
            <p>✔ Operational — All hardware stream interfaces connected and streaming sensor data packet vectors without network loss.</p>
        </div>

        <?php if ($role === 'admin'): ?>
            <div class="notification-event-strip status-border-admin">
                <strong>⚙️ Admin Operational Metric:</strong>
                <p>ESP32 DevKit nodes polling properly. Core base-station API reporting active connection bounds.</p>
            </div>
        <?php endif; ?>
    </div>
</div>