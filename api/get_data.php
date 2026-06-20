<?php
header("Content-Type: application/json");
include "../config/db_connect.php";

// Pull target session context if requested from web interface, default to a target post parameter for hardware
$user_id = $_POST['user_id'] ?? ($_GET['user_id'] ?? null);

// If running in browser session context
if (!$user_id) {
    session_start();
    $user_id = $_SESSION['user_id'] ?? null;
}

// Generate sample readings (Simulating live data before building the hardware)
$moisture    = round(rand(20, 80) + (rand(0, 99) / 100), 2);
$ph_level    = round(rand(45, 75) / 10, 1);
$temperature = round(rand(22, 35) + (rand(0, 99) / 100), 2);
$nitrogen    = rand(10, 60);
$phosphorus  = rand(5, 40);
$potassium   = rand(15, 70);

// Evaluate custom status code strings based on cooperative rules
if ($moisture < 30) {
    $status = "DRY";
} elseif ($moisture > 60) {
    $status = "WET";
} elseif ($ph_level < 5.0) {
    $status = "ACIDIC";
} elseif ($ph_level > 7.5) {
    $status = "ALKALINE";
} elseif ($temperature < 18) {
    $status = "COOL";
} elseif ($temperature > 35) {
    $status = "HOT";
} elseif ($nitrogen < 20 || $phosphorus < 10 || $potassium < 15) {
    $status = "LOW NUTRIENTS";
} elseif ($nitrogen > 50 || $phosphorus > 30 || $potassium > 50) {
    $status = "EXCESS NUTRIENTS";
} else {
    $status = "OPTIMAL";
}

// Insert directly into data tables linked by user_id references
try {
    $stmt = $conn->prepare("INSERT INTO sensor_data 
        (user_id, moisture, ph_level, temperature, nitrogen, phosphorus, potassium, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $moisture, $ph_level, $temperature, $nitrogen, $phosphorus, $potassium, $status]);
    
    // Return latest record back to user interface
    $last_id = $conn->lastInsertId();
    $stmt_latest = $conn->prepare("SELECT * FROM sensor_data WHERE id = ?");
    $stmt_latest->execute([$last_id]);
    $latest = $stmt_latest->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode($latest);
} catch (PDOException $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
?>