<?php
// api/store_data.php

header("Content-Type: application/json");

require_once '../config/db_connect.php';

define("ESP32_SECRET_KEY", "SCC_AGRI_SECRET_KEY_2026");

if ($_SERVER["REQUEST_METHOD"] !== "POST" && $_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);

    echo json_encode([
        "status" => "error",
        "message" => "Only GET or POST requests allowed"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| READ REQUEST DATA
|--------------------------------------------------------------------------
*/

$raw_input = file_get_contents("php://input");

$json_data = json_decode($raw_input, true);

if (!is_array($json_data)) {
    $json_data = [];
}

$api_key =
    $_REQUEST['api_key']
    ?? $json_data['api_key']
    ?? $_SERVER['HTTP_X_API_KEY']
    ?? '';

$device_id =
    $_REQUEST['device_id']
    ?? $json_data['device_id']
    ?? '';

$moisture =
    $_REQUEST['moisture']
    ?? $json_data['moisture']
    ?? null;

$ph =
    $_REQUEST['ph']
    ?? $json_data['ph']
    ?? null;

$nitrogen =
    $_REQUEST['nitrogen']
    ?? $json_data['nitrogen']
    ?? null;

$phosphorus =
    $_REQUEST['phosphorus']
    ?? $json_data['phosphorus']
    ?? null;

$potassium =
    $_REQUEST['potassium']
    ?? $json_data['potassium']
    ?? null;

$temperature =
    $_REQUEST['temperature']
    ?? $json_data['temperature']
    ?? null;


/*
|--------------------------------------------------------------------------
| VALIDATE API KEY
|--------------------------------------------------------------------------
*/

if ($api_key !== ESP32_SECRET_KEY) {

    http_response_code(403);

    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized: Invalid API Key"
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| VALIDATE DEVICE ID
|--------------------------------------------------------------------------
*/

$device_id = trim((string)$device_id);

if ($device_id === '') {

    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Missing device_id"
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| SANITIZE NUMERIC READINGS
|--------------------------------------------------------------------------
*/

$moisture = filter_var($moisture, FILTER_VALIDATE_FLOAT);
$ph = filter_var($ph, FILTER_VALIDATE_FLOAT);
$nitrogen = filter_var($nitrogen, FILTER_VALIDATE_FLOAT);
$phosphorus = filter_var($phosphorus, FILTER_VALIDATE_FLOAT);
$potassium = filter_var($potassium, FILTER_VALIDATE_FLOAT);
$temperature = filter_var($temperature, FILTER_VALIDATE_FLOAT);


/*
|--------------------------------------------------------------------------
| REQUIRED READINGS
|--------------------------------------------------------------------------
*/

if ($moisture === false || $moisture === null ||
    $ph === false || $ph === null) {

    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Invalid or missing moisture/pH readings"
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| RESOLVE FARMER OWNER
|--------------------------------------------------------------------------
|
| devices_manage.php currently stores the assignment in:
|
| sensor_data.user_id
| sensor_data.device_label
|
| Therefore we find the farmer whose latest assignment matches
| the incoming ESP32 device_id.
|
*/

try {

    // 1. Resolve from devices table
    $devOwnerStmt = $conn->prepare("
        SELECT assigned_user_id, node_name, device_uid 
        FROM devices 
        WHERE device_uid = ? OR node_name = ?
        LIMIT 1
    ");
    $devOwnerStmt->execute([$device_id, $device_id]);
    $devOwner = $devOwnerStmt->fetch(PDO::FETCH_ASSOC);

    $owner_user_id = null;
    if ($devOwner && !empty($devOwner['assigned_user_id'])) {
        $owner_user_id = intval($devOwner['assigned_user_id']);
    }

    // 2. Fallback to sensor_data table
    if ($owner_user_id === null) {
        $owner_stmt = $conn->prepare("
            SELECT
                user_id,
                device_label
            FROM sensor_data
            WHERE device_label = ?
              AND user_id IS NOT NULL
            ORDER BY id DESC
            LIMIT 1
        ");

        $owner_stmt->execute([$device_id]);
        $owner = $owner_stmt->fetch(PDO::FETCH_ASSOC);

        if ($owner) {
            $owner_user_id = intval($owner['user_id']);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | STORE TELEMETRY
    |--------------------------------------------------------------------------
    */

    $sql = "
        INSERT INTO soil_readings
        (
            device_id,
            moisture,
            ph,
            nitrogen,
            phosphorus,
            potassium,
            temperature,
            created_at
        )
        VALUES
        (
            :device_id,
            :moisture,
            :ph,
            :nitrogen,
            :phosphorus,
            :potassium,
            :temperature,
            NOW()
        )
    ";

    $stmt = $conn->prepare($sql);

    $stmt->execute([
        ':device_id' => $device_id,
        ':moisture' => $moisture,
        ':ph' => $ph,
        ':nitrogen' => $nitrogen !== false ? $nitrogen : null,
        ':phosphorus' => $phosphorus !== false ? $phosphorus : null,
        ':potassium' => $potassium !== false ? $potassium : null,
        ':temperature' => $temperature !== false ? $temperature : null
    ]);

    $reading_id = $conn->lastInsertId();


    /*
    |--------------------------------------------------------------------------
    | ALSO UPDATE sensor_data
    |--------------------------------------------------------------------------
    |
    | This keeps the existing dashboard/account mapping synchronized.
    |
    | If the device is already assigned to a farmer, the new reading
    | is stored under that farmer's user_id.
    |
    */

    if ($owner_user_id !== null && $owner_user_id > 0) {

        $status = 'OPTIMAL';

        if ($moisture < 30 || $ph < 5.0 || $temperature > 35) {
            $status = 'CRITICAL';
        } elseif (
            $moisture > 60 ||
            $ph > 7.5 ||
            $nitrogen < 20
        ) {
            $status = 'WARNING';
        }

        $sensor_stmt = $conn->prepare("
            INSERT INTO sensor_data
            (
                user_id,
                device_label,
                moisture,
                ph_level,
                temperature,
                nitrogen,
                phosphorus,
                potassium,
                status
            )
            VALUES
            (
                :user_id,
                :device_label,
                :moisture,
                :ph_level,
                :temperature,
                :nitrogen,
                :phosphorus,
                :potassium,
                :status
            )
        ");

        $sensor_stmt->execute([
            ':user_id' => $owner_user_id,
            ':device_label' => $device_id,
            ':moisture' => $moisture,
            ':ph_level' => $ph,
            ':temperature' => $temperature !== false ? $temperature : null,
            ':nitrogen' => $nitrogen !== false ? $nitrogen : null,
            ':phosphorus' => $phosphorus !== false ? $phosphorus : null,
            ':potassium' => $potassium !== false ? $potassium : null,
            ':status' => $status
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | PENDING SMS
    |--------------------------------------------------------------------------
    */

    $pending_sms_stmt = $conn->query("
        SELECT
            id,
            phone,
            message
        FROM sms_outbox
        WHERE status = 'pending'
        ORDER BY id ASC
        LIMIT 3
    ");

    $pending_sms = $pending_sms_stmt->fetchAll(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | MARK SMS AS SENT/PROCESSING
    |--------------------------------------------------------------------------
    */

    if (!empty($pending_sms)) {

        $ids = array_column($pending_sms, 'id');

        $in_clause = implode(
            ',',
            array_map('intval', $ids)
        );

        $conn->exec("
            UPDATE sms_outbox
            SET status = 'sent'
            WHERE id IN ($in_clause)
        ");
    }


    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        "status" => "success",
        "message" => "Telemetry stored successfully",
        "reading_id" => $reading_id,
        "device_id" => $device_id,
        "assigned_user_id" => $owner_user_id,
        "node_assigned" => $owner_user_id !== null,
        "pending_sms" => $pending_sms
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Database Query Failed: " . $e->getMessage()
    ]);
}
?>