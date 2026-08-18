<?php
// config/get_latest_sensor.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';

function getLatestSensorData($conn) {
    $role = strtolower($_SESSION['role'] ?? 'farmer');
    $user_id = $_SESSION['user_id'] ?? 0;

    if ($role === 'admin') {
        // Admin gets absolute latest global record
        $stmt = $conn->query("
            SELECT s.*, u.username 
            FROM sensor_data s 
            LEFT JOIN users u ON s.user_id = u.id 
            ORDER BY s.id DESC 
            LIMIT 1
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // 1. Try to fetch the latest record pinned specifically to this logged-in user
    $stmt = $conn->prepare("
        SELECT * FROM sensor_data 
        WHERE user_id = ? 
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $latest = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. If no user-specific record exists, fetch the absolute latest system record
    if (!$latest) {
        $stmt_fallback = $conn->query("SELECT * FROM sensor_data ORDER BY id DESC LIMIT 1");
        $latest = $stmt_fallback->fetch(PDO::FETCH_ASSOC);
    }

    return $latest;
}