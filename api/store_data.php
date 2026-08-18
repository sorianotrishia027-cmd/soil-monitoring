<?php
// api/store_data.php
header("Content-Type: application/json");
require_once '../config/db_connect.php';

// Define your secret key matching your ESP32 payload
define("ESP32_SECRET_KEY", "SCC_AGRI_SECRET_KEY_2026");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Only POST requests allowed"]);
    exit;
}

// 1. Support both JSON payload and standard $_POST form data
$raw_input = file_get_contents("php://input");
$json_data = json_decode($raw_input, true) ?? [];

$api_key     = $_POST['api_key'] ?? $json_data['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
$device_id   = $_POST['device_id'] ?? $json_data['device_id'] ?? 'ESP32_DEFAULT';
$moisture    = $_POST['moisture'] ?? $json_data['moisture'] ?? null;
$ph          = $_POST['ph'] ?? $json_data['ph'] ?? null;
$nitrogen    = $_POST['nitrogen'] ?? $json_data['nitrogen'] ?? null;
$phosphorus  = $_POST['phosphorus'] ?? $json_data['phosphorus'] ?? null;
$potassium   = $_POST['potassium'] ?? $json_data['potassium'] ?? null;
$temperature = $_POST['temperature'] ?? $json_data['temperature'] ?? null;

// 2. Validate API Key
if ($api_key !== ESP32_SECRET_KEY) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Unauthorized: Invalid API Key"]);
    exit;
}

// 3. Extract & sanitize numeric parameters
$moisture    = filter_var($moisture, FILTER_VALIDATE_FLOAT);
$ph          = filter_var($ph, FILTER_VALIDATE_FLOAT);
$nitrogen    = filter_var($nitrogen, FILTER_VALIDATE_FLOAT);
$phosphorus  = filter_var($phosphorus, FILTER_VALIDATE_FLOAT);
$potassium   = filter_var($potassium, FILTER_VALIDATE_FLOAT);
$temperature = filter_var($temperature, FILTER_VALIDATE_FLOAT);

if ($moisture === false || $ph === false) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid or missing moisture/pH readings"]);
    exit;
}

// 4. Insert telemetry data into database
try {
    $sql = "INSERT INTO soil_readings (device_id, moisture, ph, nitrogen, phosphorus, potassium, temperature, created_at) 
            VALUES (:device_id, :moisture, :ph, :nitrogen, :phosphorus, :potassium, :temperature, NOW())";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':device_id'   => $device_id,
        ':moisture'    => $moisture,
        ':ph'          => $ph,
        ':nitrogen'    => $nitrogen,
        ':phosphorus'  => $phosphorus,
        ':potassium'   => $potassium,
        ':temperature' => $temperature
    ]);

    echo json_encode([
        "status"     => "success", 
        "message"    => "Telemetry stored successfully",
        "reading_id" => $conn->lastInsertId()
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database Query Failed: " . $e->getMessage()]);
}
?>