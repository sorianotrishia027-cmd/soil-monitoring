<?php
// api/get_node_contacts.php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/db_connect.php';

try {
    // Kunin ang lahat ng may phone_number na may laman sa users table
    $stmt = $conn->prepare("SELECT phone_number FROM users WHERE phone_number IS NOT NULL AND phone_number != ''");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $numbers = [];
    foreach ($rows as $row) {
        $num = trim($row['phone_number']);
        if (!empty($num) && !in_array($num, $numbers)) {
            $numbers[] = $num;
        }
    }

    // I-print ang mga numero na magkahiwalay ng bagong linya para basahin ng ESP32
    echo implode("\n", $numbers);

} catch (PDOException $e) {
    echo "";
}
?>