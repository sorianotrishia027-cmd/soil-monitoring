<?php
// api/get_node_contacts.php

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
header("Content-Type: text/plain; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/db_connect.php';

$device_id = trim(
    $_GET['device_id']
    ?? $_POST['device_id']
    ?? 'ESP32_GSM_01'
);

/*
|--------------------------------------------------------------------------
| NORMALIZE PHONE NUMBER
|--------------------------------------------------------------------------
*/
function cleanPhoneNumber($raw)
{
    if (!$raw) {
        return '';
    }

    $phone = trim($raw);

    // Remove spaces, dashes, parentheses, etc.
    $phone = preg_replace('/[^0-9+]/', '', $phone);

    /*
    |--------------------------------------------------------------------------
    | Philippine number:
    | 09171234567
    | -> +639171234567
    |--------------------------------------------------------------------------
    */
    if (preg_match('/^09[0-9]{9}$/', $phone)) {
        return '+63' . substr($phone, 1);
    }

    /*
    |--------------------------------------------------------------------------
    | Already international:
    | +639171234567
    |--------------------------------------------------------------------------
    */
    if (preg_match('/^\+639[0-9]{9}$/', $phone)) {
        return $phone;
    }

    /*
    |--------------------------------------------------------------------------
    | International without +
    | 639171234567
    |--------------------------------------------------------------------------
    */
    if (preg_match('/^639[0-9]{9}$/', $phone)) {
        return '+' . $phone;
    }

    return '';
}

try {

    /*
    |--------------------------------------------------------------------------
    | GET REGISTERED FARMER CONTACT NUMBERS
    |--------------------------------------------------------------------------
    |
    | The actual database column is:
    |
    | users.phone_number
    |
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->query("
        SELECT
            id,
            username,
            fullname,
            phone_number,
            role
        FROM users
        WHERE LOWER(role) = 'farmer'
          AND phone_number IS NOT NULL
          AND TRIM(phone_number) != ''
        ORDER BY id ASC
    ");

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $contacts = [];

    foreach ($users as $user) {

        $phone = cleanPhoneNumber(
            $user['phone_number'] ?? ''
        );

        if (
            $phone !== '' &&
            !in_array($phone, $contacts, true)
        ) {
            $contacts[] = $phone;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | RETURN CONTACTS
    |--------------------------------------------------------------------------
    |
    | Example:
    |
    | +639924996572 +639128057380
    |
    |--------------------------------------------------------------------------
    */

    echo implode(' ', $contacts);

} catch (PDOException $e) {

    // Keep response empty so ESP32 does not parse PHP errors as phone numbers.
    echo "";
}

exit;
?>