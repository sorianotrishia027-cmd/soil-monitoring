<?php
// api/get_node_contacts.php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/db_connect.php';

$device_id = isset($_GET['device_id']) ? trim($_GET['device_id']) : '';

if (empty($device_id)) {
    echo "";
    exit;
}

// Subukan munang hanapin sa table na naglalaman ng device info o contacts
// Palitan ang 'devices' o 'device_contacts' at column names kung iba ang pangalan sa database mo
$query = "SELECT phone_number FROM devices WHERE device_id = ? LIMIT 1";
$stmt = $conn->prepare($query);

if ($stmt) {
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        echo trim($row['phone_number']);
    } else {
        // Fallback query kung ibang table ang ginagamit mo para sa contacts
        $query2 = "SELECT phone_number FROM device_contacts WHERE device_id = ? LIMIT 1";
        $stmt2 = $conn->prepare($query2);
        if ($stmt2) {
            $stmt2->bind_param("s", $device_id);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            if ($row2 = $res2->fetch_assoc()) {
                echo trim($row2['phone_number']);
            }
            $stmt2->close();
        }
    }
    $stmt->close();
}

$conn->close();
?>