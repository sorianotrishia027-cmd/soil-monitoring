document.addEventListener("DOMContentLoaded", () => {
    const displayDiv = document.getElementById("data-display");

    function fetchSoilData() {
        fetch("api/get_data.php")
            .then(response => response.json())
            .then(res => {
                if (res.status === "success") {
                    // Update this parsing block based on your database column names 
                    // e.g., res.data.moisture, res.data.temperature, etc.
                    displayDiv.innerHTML = `
                        <pre>${JSON.stringify(res.data, null, 2)}</pre>
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

    // Run once at start, then pull updates every 5 seconds
    fetchSoilData();
    setInterval(fetchSoilData, 5000);
});