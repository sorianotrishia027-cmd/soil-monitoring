<?php
// api/get_live_telemetry.php
header("Content-Type: application/json");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../config/db_connect.php';
date_default_timezone_set('Asia/Manila');

try {
    $stmt = $conn->query("SELECT * FROM soil_readings ORDER BY id DESC LIMIT 1");
    $latest = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$latest) {
        echo json_encode([
            "status" => "empty",
            "message" => "No telemetry data recorded yet."
        ]);
        exit;
    }

    $valMoisture = isset($latest['moisture']) ? floatval($latest['moisture']) : null;
    $valPh       = isset($latest['ph']) ? floatval($latest['ph']) : null;
    $valN        = isset($latest['nitrogen']) ? intval($latest['nitrogen']) : null;
    $valP        = isset($latest['phosphorus']) ? intval($latest['phosphorus']) : null;
    $valK        = isset($latest['potassium']) ? intval($latest['potassium']) : null;
    $valTemp     = isset($latest['temperature']) ? floatval($latest['temperature']) : null;
    $createdAt   = $latest['created_at'] ?? date('Y-m-d H:i:s');
    $npkOnline   = ($valN > 0 || $valP > 0 || $valK > 0);
    $formattedTime = date('M j, Y - g:i:s A', strtotime($createdAt));

    // Fetch last 7 readings for live chart sync
    $chartStmt = $conn->query("SELECT moisture, created_at FROM (SELECT id, moisture, created_at FROM soil_readings ORDER BY id DESC LIMIT 7) AS sub ORDER BY id ASC");
    $chartRows = $chartStmt ? $chartStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $chartLabels = [];
    $chartData = [];
    foreach ($chartRows as $row) {
        $chartLabels[] = date('g:i:s A', strtotime($row['created_at']));
        $chartData[] = floatval($row['moisture']);
    }

    // Fetch last 15 readings for real-time history table sync
    $logsStmt = $conn->query("SELECT * FROM soil_readings ORDER BY id DESC LIMIT 15");
    $recentLogs = $logsStmt ? $logsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $formattedLogs = [];
    foreach ($recentLogs as $log) {
        $formattedLogs[] = [
            'id'             => (int)$log['id'],
            'created_at'     => $log['created_at'] ?? '',
            'formatted_time' => isset($log['created_at']) ? date("M j, Y - g:i A", strtotime($log['created_at'])) : 'N/A',
            'moisture'       => floatval($log['moisture'] ?? 0),
            'ph'             => floatval($log['ph'] ?? 0),
            'nitrogen'       => intval($log['nitrogen'] ?? 0),
            'phosphorus'     => intval($log['phosphorus'] ?? 0),
            'potassium'      => intval($log['potassium'] ?? 0),
            'temperature'    => floatval($log['temperature'] ?? 0)
        ];
    }

    $totalCount = (int)$conn->query("SELECT COUNT(*) FROM soil_readings")->fetchColumn();

    echo json_encode([
        "status" => "success",
        "data" => [
            "id"             => (int)($latest['id'] ?? 0),
            "device_id"      => $latest['device_id'] ?? 'ESP32_GSM_01',
            "moisture"       => $valMoisture,
            "ph"             => $valPh,
            "nitrogen"       => $valN,
            "phosphorus"     => $valP,
            "potassium"      => $valK,
            "temperature"    => $valTemp,
            "npk_online"     => $npkOnline,
            "created_at"     => $createdAt,
            "formatted_time" => $formattedTime,
            "chart_labels"   => $chartLabels,
            "chart_data"     => $chartData
        ],
        "recent_logs" => $formattedLogs,
        "total_count" => $totalCount
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Database error: " . $e->getMessage()
    ]);
}

