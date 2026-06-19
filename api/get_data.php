<?php
header("Content-Type: application/json");
require_once '../config/db_connect.php';

try {
    // Queries your explicit sensor_data database table layout
    $stmt = $conn->prepare("SELECT * FROM sensor_data ORDER BY id DESC LIMIT 1");
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