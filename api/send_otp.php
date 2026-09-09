<?php
// api/send_otp.php
header("Content-Type: application/json");
session_start();
require_once '../config/db_connect.php';

// Auto-create sms_outbox table to store pending OTPs for ESP32/GSM to fetch & send
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS sms_outbox (
        id INT AUTO_INCREMENT PRIMARY KEY,
        phone VARCHAR(20) NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["status" => "error", "message" => "POST method required"]);
    exit;
}

$phone = trim($_POST['phone'] ?? '');

if (empty($phone)) {
    echo json_encode(["status" => "error", "message" => "Contact number is required"]);
    exit;
}

// Generate 6-digit random verification code
$otp_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

// Save to session for verification
$_SESSION['otp_code'] = $otp_code;
$_SESSION['otp_phone'] = $phone;
$_SESSION['otp_expires'] = time() + 300; // 5 minutes validity

// Queue SMS message in sms_outbox so ESP32 or SMS gateway sends it
try {
    $msg = "Sto. Cristo Coop Verification Code: " . $otp_code . ". Valid for 5 minutes. Do not share.";
    $stmt = $conn->prepare("INSERT INTO sms_outbox (phone, message, status) VALUES (?, ?, 'pending')");
    $stmt->execute([$phone, $msg]);

    echo json_encode([
        "status" => "success",
        "message" => "Verification code sent to $phone",
        "demo_code" => $otp_code // Included for testing convenience
    ]);
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => "Failed to queue OTP: " . $e->getMessage()]);
}
?>
