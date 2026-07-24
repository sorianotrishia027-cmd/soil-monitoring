<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Adjust relative path to point up one folder to config/db_connect.php
include "../config/db_connect.php";

// Secret API key (Must match the key in your ESP32 code)
$api_key_value = "FarmGuard_SecretKey_2026"; 

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $api_key = $_POST['api_key'] ?? '';
    
    // Security check
    if ($api_key !== $api_key_value) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Unauthorized API Key"]);
        exit;
    }

    // Capture sensor readings
    $user_id     = intval($_POST['user_id'] ?? 0);
    $moisture    = floatval($_POST['moisture'] ?? 0);
    $ph_level    = floatval($_POST['ph_level'] ?? 0);
    $temperature = floatval($_POST['temperature'] ?? 0);
    $nitrogen    = intval($_POST['nitrogen'] ?? 0);
    $phosphorus  = intval($_POST['phosphorus'] ?? 0);
    $potassium   = intval($_POST['potassium'] ?? 0);
    $status      = $_POST['status'] ?? 'Optimal';

    try {
        $stmt = $conn->prepare("INSERT INTO sensor_data (user_id, moisture, ph_level, temperature, nitrogen, phosphorus, potassium, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $moisture, $ph_level, $temperature, $nitrogen, $phosphorus, $potassium, $status]);
        
        echo json_encode(["status" => "success", "message" => "Telemetry data logged successfully"]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method Not Allowed"]);
}
?>