<?php
// api/get_node_contacts.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/db_connect.php';

$device_id = isset($_GET['device_id']) ? trim($_GET['device_id']) : 'ESP32_GSM_01';

echo "Testing for device_id: " . htmlspecialchars($device_id) . "<br><br>";

try {
    // 1. I-check muna ang lahat ng tables sa database para makita kung ano ang meron
    $tables =$conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Existing tables in DB: " . implode(', ', $tables) . "<br><br>";

    // 2. Subukan ang 'devices' table kung nag-eexist
    if (in_array('devices', $tables)) {
        $stmt =$conn->prepare("SELECT * FROM devices WHERE device_id = ?");
        $stmt->execute([$device_id]);
        $data =$stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Data in 'devices' table for this ID:<br>";
        echo "<pre>" . print_r($data, true) . "</pre>";
    }

    // 3. Subukan ang ibang posibleng table (halimbawa ay 'device_contacts' o katulad nito)
    foreach ($tables as$tbl) {
        if ($tbl != 'devices') {
            try {
                $stmt =$conn->prepare("SELECT * FROM `$tbl` LIMIT 2");
                $stmt->execute();
                $sample =$stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($sample) && array_key_exists('phone_number',$sample[0])) {
                    echo "Found 'phone_number' column in table: <b>$tbl</b><br>";
                    $stmt2 =$conn->prepare("SELECT phone_number FROM `$tbl` WHERE device_id = ?");
                    $stmt2->execute([$device_id]);
                    $res =$stmt2->fetch(PDO::FETCH_ASSOC);
                    echo "Result from $tbl: " . htmlspecialchars($res['phone_number'] ?? 'Not found') . "<br>";
                }
            } catch (Exception $ex) {
                // Ignore errors for tables without device_id
            }
        }
    }

} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage();
}
?>