<?php
header("Content-Type: application/json");
require_once '../config/db_connect.php';

try {
    // Fetch the latest entry from your soil metrics table
    // Adjust 'soil_data' and 'created_at' to match your actual database schema
    $stmt = $conn->prepare("SELECT * FROM soil_data ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($data) {
        echo json_encode(["status" => "success", "data" => $data]);
    } else {
        echo json_encode(["status" => "empty", "message" => "No sensor logs found."]);
    }
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>