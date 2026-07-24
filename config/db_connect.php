<?php
// Railway automatically provides these environment variables when deployed
$host = getenv('MYSQLHOST') ?: "thomas.proxy.rlwy.net";
$dbname = getenv('MYSQLDATABASE') ?: "railway";
$user = getenv('MYSQLUSER') ?: "root";
$pass = getenv('MYSQLPASSWORD') ?: "XFyYHOjIZlWLkECZwHeAbllZBfszyGXv"; 
$port = getenv('MYSQLPORT') ?: 18293;

try {
    $conn = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8", $user, $pass);
    // Set the PDO error mode to exception
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    $message = "Connection failed: " . $e->getMessage();

    if (php_sapi_name() === 'cli') {
        die($message);
    }

    if (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
        header('Content-Type: application/json');
        die(json_encode(["status" => "error", "message" => $message]));
    }

    echo "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"UTF-8\"><title>Database Error</title></head><body><h1>Database connection failed</h1><p>" . htmlspecialchars($message) . "</p></body></html>";
    exit;
}
?>