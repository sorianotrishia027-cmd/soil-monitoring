<?php
// api/verify_otp.php
header("Content-Type: application/json");
session_start();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["status" => "error", "message" => "POST method required"]);
    exit;
}

$input_code = trim($_POST['otp_code'] ?? '');
$session_code = $_SESSION['otp_code'] ?? '';
$session_expires = $_SESSION['otp_expires'] ?? 0;

if (empty($input_code)) {
    echo json_encode(["status" => "error", "message" => "Please enter verification code"]);
    exit;
}

if (time() > $session_expires) {
    echo json_encode(["status" => "error", "message" => "Verification code expired. Request a new one."]);
    exit;
}

if ($input_code === $session_code) {
    $_SESSION['otp_verified'] = true;
    echo json_encode(["status" => "success", "message" => "Phone number verified successfully!"]);
} else {
    echo json_encode(["status" => "error", "message" => "Invalid verification code. Please try again."]);
}
?>
