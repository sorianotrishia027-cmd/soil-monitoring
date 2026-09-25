<?php
// api/get_node_contacts.php
require_once '../config/db_connect.php';

$device_id = isset($_GET['device_id']) ? $_GET['device_id'] : '';
$node = isset($_GET['node']) ? intval($_GET['node']) : 1;

if (empty($device_id)) {
    echo "";
    exit;
}

// Halimbawa: Kunin ang phone number mula sa iyong database table (palitan ang table at column kung kinakailangan)
$query = "SELECT phone_number FROM device_contacts WHERE device_id = ? AND node_number = ? LIMIT 1";
$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->bind_param("si", $device_id, $node);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        echo trim($row['phone_number']);
    } else {
        // Fallback kung walang nakarehistro sa database table
        echo "09123456789"; 
    }
    $stmt->close();
} else {
    echo "";
}
?>