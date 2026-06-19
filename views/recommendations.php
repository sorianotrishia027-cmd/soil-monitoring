<div class="sub-view-panel-container">
    <div class="view-panel-header">
        <h3>Agronomical Management Recommendations</h3>
    </div>

    <div class="recommendations-content-card">
        <h4>Macro-Nutrient Treatment Protocols (N-P-K)</h4>
        
        <div class="npk-treatment-row">
            <h5>Nitrogen (N) Actions</h5>
            <?php if ($role === 'farmer'): ?>
                <p>Apply urea, ammonium sulfate, or organic fertilizers (chicken manure) if readings drop low. Stop application if high to prevent leaf burn.</p>
            <?php else: ?>
                <p>Calculate required dosage based on crop type; track improvements and warn against excesses causing lodging.</p>
            <?php endif; ?>
        </div>

        <div class="npk-treatment-row">
            <h5>Phosphorus (P) Actions</h5>
            <?php if ($role === 'farmer'): ?>
                <p>Apply superphosphate or bone meal near roots when low. Reduce inputs if high to monitor for nutrient lock-up.</p>
            <?php else: ?>
                <p>Recommend deep soil mixing methods for absorption; advise on crop rotation parameters if high.</p>
            <?php endif; ?>
        </div>

        <div class="npk-treatment-row">
            <h5>Potassium (K) Actions</h5>
            <?php if ($role === 'farmer'): ?>
                <p>Apply muriate of potash or wood ash to bolster disease defense vectors. Reduce input if levels register high.</p>
            <?php else: ?>
                <p>Suggest timing fertilizer application cycles directly before flowering/fruiting stages.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="document-summary-box-sheet" style="margin-top: 30px; background: #f0f4f0; padding: 20px; border-radius: 12px;">
        <h4>Summary Report Parameters</h4>
        <p style="font-size: 14px; margin-top: 10px;">
            <strong>For Farmers:</strong> Use real-time sensor readings to adjust irrigation, fertilization, and soil treatment. Follow the suggested dosage and timing to improve yield and reduce input costs.
        </p>
        <p style="font-size: 14px; margin-top: 10px;">
            <strong>For Administrators:</strong> Analyze historical data to identify field trends, provide technical advice, plan farm activities, and ensure sustainable soil management. Use the system to generate reports and guide farmers toward best agricultural practices.
        </p>
    </div>
</div>