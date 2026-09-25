<?php
// api/get_node_contacts.php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/db_connect.php';

try {
    // Kunin ang phone number mula sa users table
    $stmt = $conn->prepare("SELECT phone_number FROM users WHERE phone_number IS NOT NULL AND phone_number != '' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && !empty($row['phone_number'])) {
        echo trim($row['phone_number']);
    } else {
        echo "";
    }
} catch (PDOException $e) {
    echo "";
}
?>