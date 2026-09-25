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

try {
    // Gamit ang PDO prepare at execute
    $stmt = $conn->prepare("SELECT phone_number FROM devices WHERE device_id = ? LIMIT 1");
    $stmt->execute([$device_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && !empty($row['phone_number'])) {
        echo trim($row['phone_number']);
    } else {
        // Fallback table sakaling sa ibang table nakapangalan
        $stmt2 = $conn->prepare("SELECT phone_number FROM device_contacts WHERE device_id = ? LIMIT 1");
        $stmt2->execute([$device_id]);
        $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
        
        if ($row2 && !empty($row2['phone_number'])) {
            echo trim($row2['phone_number']);
        } else {
            echo "";
        }
    }
} catch (PDOException $e) {
    echo "";
}
?>