<?php
// api/get_node_contacts.php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
header("Content-Type: text/plain; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/db_connect.php';

$device_id = trim($_GET['device_id'] ?? $_POST['device_id'] ?? 'ESP32_GSM_01');

function cleanPhoneNumber($raw) {
    if (!$raw) return '';
    $phone = preg_replace('/[^0-9+]/', '', trim($raw));
    if (strlen($phone) >= 10) return $phone;
    return '';
}

try {
    // Kunin ang lahat ng registered Farmers at Admins na may nakalagay na phone_number / contact_number
    $stmt = $conn->query("
        SELECT * 
        FROM users 
        WHERE (phone_number IS NOT NULL AND TRIM(phone_number) != '') 
           OR (contact_number IS NOT NULL AND TRIM(contact_number) != '')
        ORDER BY 
          CASE WHEN LOWER(role) = 'farmer' THEN 1 WHEN LOWER(role) = 'admin' THEN 2 ELSE 3 END, 
          id ASC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $contacts = [];
    foreach ($users as $u) {
        $p = cleanPhoneNumber($u['phone_number'] ?? $u['contact_number'] ?? '');
        if (!empty($p) && !in_array($p, $contacts)) {
            $contacts[] = $p;
        }
    }

    if (!empty($contacts)) {
        // Ibalik ang phone numbers (e.g. 09128057380)
        echo implode(' ', $contacts);
        exit;
    }

    echo "";
} catch (PDOException $e) {
    echo "";
}
?>