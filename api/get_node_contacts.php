<?php
// api/get_node_contacts.php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/db_connect.php';

try {
    // Kunin ang lahat ng phone numbers na hindi blangko
    $stmt = $conn->prepare("SELECT phone_number FROM users WHERE phone_number IS NOT NULL AND phone_number != ''");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($rows) {
        $numbers = [];
        foreach ($rows as $row) {
            $num = trim($row['phone_number']);
            if (!empty($num)) {
                $numbers[] = $num;
            }
        }
        // Pagsama-samahin ang mga numero na pinaghihiwalay ng comma (o space, depende sa parse ng ESP32)
        echo implode(',', $numbers);
    } else {
        echo "";
    }
} catch (PDOException $e) {
    echo "";
}
?>