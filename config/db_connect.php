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
    // If connection fails, stop execution and show error
    die(json_encode(["status" => "error", "message" => "Connection failed: " . $e->getMessage()]));
}
?>