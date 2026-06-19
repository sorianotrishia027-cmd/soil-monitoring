document.addEventListener("DOMContentLoaded", () => {
    const displayDiv = document.getElementById("data-display");

    function fetchSoilData() {
        fetch("api/get_data.php")
            .then(response => response.json())
            .then(res => {
                if (res.status === "success") {
                    const data = res.data;
                    
                    // Creates nice responsive metric elements instead of raw unparsed JSON
                    displayDiv.innerHTML = `
                        <div class="metrics-grid">
                            <div class="metric-card">
                                <h3>💧 Soil Moisture</h3>
                                <p class="metric-value">${data.moisture ?? 'N/A'}%</p>
                            </div>
                            <div class="metric-card">
                                <h3>🌡️ Temperature</h3>
                                <p class="metric-value">${data.temperature ?? 'N/A'}°C</p>
                            </div>
                            <div class="metric-card">
                                <h3>🧪 pH Level</h3>
                                <p class="metric-value">${data.ph ?? 'N/A'}</p>
                            </div>
                            <div class="metric-card">
                                <h3>📊 Last Sensor Log</h3>
                                <p class="metric-ts">${data.created_at ?? 'Just now'}</p>
                            </div>
                        </div>
                    `;
                } else {
                    displayDiv.innerHTML = `<p>${res.message || 'No records available.'}</p>`;
                }
            })
            .catch(err => {
                console.error("Error fetching metrics:", err);
                displayDiv.innerHTML = "<p class='error'>Failed to load data from server.</p>";
            });
    }

    fetchSoilData();
    setInterval(fetchSoilData, 5000); // Check for new sensor logs every 5 seconds
});