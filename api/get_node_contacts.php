<?php
// api/get_node_contacts.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/db_connect.php';

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

$device_id = isset($_GET['device_id']) ? trim($_GET['device_id']) : '';

if (empty($device_id)) {
    echo "Empty device_id";
    exit;
}

// Subukan nating ilagay dito ang tamang table at column mo sa database
// Halimbawa: kung ang table mo ay 'sensors' o kung ano man ang gamit sa ibang files mo
$query = "SELECT phone_number FROM devices WHERE device_id = ?";
$stmt = $conn->prepare($query);

if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param("s", $device_id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo trim($row['phone_number']);
} else {
    echo "No number found for device_id: " . $device_id;
}

$stmt->close();
$conn->close();
?>