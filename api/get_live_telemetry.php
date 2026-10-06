<?php
// api/get_live_telemetry.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../config/db_connect.php';

date_default_timezone_set('Asia/Manila');

try {

    // =====================================================
    // 1. CHECK LOGIN
    // =====================================================

    if (!isset($_SESSION['user_id'])) {

        http_response_code(401);

        echo json_encode([
            "status" => "error",
            "message" => "Unauthorized. Please login first."
        ]);

        exit;
    }

    $userId = (int)$_SESSION['user_id'];

    if ($userId <= 0) {

        http_response_code(401);

        echo json_encode([
            "status" => "error",
            "message" => "Invalid user session."
        ]);

        exit;
    }

    // =====================================================
    // 2. VERIFY USER
    // =====================================================

    $userStmt = $conn->prepare("
        SELECT id, role
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $userStmt->execute([$userId]);

    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {

        session_destroy();

        http_response_code(401);

        echo json_encode([
            "status" => "error",
            "message" => "User account not found."
        ]);

        exit;
    }

    $role = strtolower(trim($user['role'] ?? ''));

    if ($role === '') {
        $role = 'farmer';
    }

    // =====================================================
    // 3. RESOLVE FARMER DEVICE
    // =====================================================

    $assignedDevice = null;

    /*
     * These are only for diagnostics.
     * They are not accepted from GET/POST/JavaScript.
     */
    $deviceCandidates = [];
    $matchedCandidate = null;

    if ($role !== 'admin') {

        /*
         * Get BOTH device_id and device_label.
         *
         * Previous code only used device_label.
         * That can cause a mismatch because soil_readings
         * uses device_id.
         */
        $assignmentStmt = $conn->prepare("
            SELECT id, device_id, device_label
            FROM sensor_data
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT 20
        ");

        $assignmentStmt->execute([$userId]);

        $assignments = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC);

        /*
         * Build unique candidate list.
         *
         * Newest sensor_data records are checked first.
         */
        foreach ($assignments as $assignment) {

            $deviceId = trim((string)($assignment['device_id'] ?? ''));
            $deviceLabel = trim((string)($assignment['device_label'] ?? ''));

            if ($deviceId !== '') {

                if (!in_array($deviceId, $deviceCandidates, true)) {
                    $deviceCandidates[] = $deviceId;
                }
            }

            if ($deviceLabel !== '') {

                if (!in_array($deviceLabel, $deviceCandidates, true)) {
                    $deviceCandidates[] = $deviceLabel;
                }
            }
        }

        /*
         * Find the candidate that ACTUALLY exists in soil_readings.
         */
        foreach ($deviceCandidates as $candidate) {

            $checkStmt = $conn->prepare("
                SELECT COUNT(*)
                FROM soil_readings
                WHERE device_id = ?
            ");

            $checkStmt->execute([$candidate]);

            $candidateCount = (int)$checkStmt->fetchColumn();

            if ($candidateCount > 0) {

                $assignedDevice = $candidate;
                $matchedCandidate = $candidate;

                break;
            }
        }

        /*
         * If no candidate has telemetry, do NOT use global data.
         */
        if ($assignedDevice === null) {

            echo json_encode([
                "status" => "empty",
                "message" => "No telemetry data found for the node assigned to this farmer account.",
                "data" => null,
                "recent_logs" => [],
                "total_count" => 0,
                "assigned_device" => null,
                "matched_device" => null,
                "role" => $role,
                "debug_candidates" => $deviceCandidates
            ]);

            exit;
        }
    }

    // =====================================================
    // 4. GET LATEST TELEMETRY
    // =====================================================

    if ($role === 'admin') {

        $stmt = $conn->query("
            SELECT *
            FROM soil_readings
            ORDER BY id DESC
            LIMIT 1
        ");

    } else {

        $stmt = $conn->prepare("
            SELECT *
            FROM soil_readings
            WHERE device_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");

        $stmt->execute([$assignedDevice]);
    }

    $latest = $stmt->fetch(PDO::FETCH_ASSOC);

    // =====================================================
    // 5. NO LATEST TELEMETRY
    // =====================================================

    if (!$latest) {

        echo json_encode([
            "status" => "empty",
            "message" => "No telemetry data recorded for the assigned node.",
            "data" => null,
            "recent_logs" => [],
            "total_count" => 0,
            "assigned_device" => $assignedDevice,
            "matched_device" => $matchedCandidate,
            "role" => $role
        ]);

        exit;
    }

    // =====================================================
    // 6. LATEST VALUES
    // =====================================================

    $valMoisture = isset($latest['moisture'])
        ? floatval($latest['moisture'])
        : null;

    $valPh = isset($latest['ph'])
        ? floatval($latest['ph'])
        : null;

    $valN = isset($latest['nitrogen'])
        ? intval($latest['nitrogen'])
        : null;

    $valP = isset($latest['phosphorus'])
        ? intval($latest['phosphorus'])
        : null;

    $valK = isset($latest['potassium'])
        ? intval($latest['potassium'])
        : null;

    $valTemp = isset($latest['temperature'])
        ? floatval($latest['temperature'])
        : null;

    $createdAt = $latest['created_at']
        ?? date('Y-m-d H:i:s');

    $timestamp = strtotime($createdAt);

    if ($timestamp === false) {
        $timestamp = time();
    }

    $npkOnline = (
        ($valN !== null && $valN > 0) ||
        ($valP !== null && $valP > 0) ||
        ($valK !== null && $valK > 0)
    );

    $formattedTime = date(
        'M j, Y - g:i:s A',
        $timestamp
    );

    // =====================================================
    // 7. LAST 7 READINGS FOR CHART
    // =====================================================

    if ($role === 'admin') {

        $chartStmt = $conn->query("
            SELECT moisture, created_at
            FROM (
                SELECT id, moisture, created_at
                FROM soil_readings
                ORDER BY id DESC
                LIMIT 7
            ) AS sub
            ORDER BY id ASC
        ");

    } else {

        $chartStmt = $conn->prepare("
            SELECT moisture, created_at
            FROM (
                SELECT id, moisture, created_at
                FROM soil_readings
                WHERE device_id = ?
                ORDER BY id DESC
                LIMIT 7
            ) AS sub
            ORDER BY id ASC
        ");

        $chartStmt->execute([$assignedDevice]);
    }

    $chartRows = $chartStmt->fetchAll(PDO::FETCH_ASSOC);

    $chartLabels = [];
    $chartData = [];

    foreach ($chartRows as $row) {

        $rowTimestamp = strtotime(
            $row['created_at'] ?? ''
        );

        if ($rowTimestamp === false) {
            $rowTimestamp = time();
        }

        $chartLabels[] = date(
            'g:i:s A',
            $rowTimestamp
        );

        $chartData[] = floatval(
            $row['moisture'] ?? 0
        );
    }

    // =====================================================
    // 8. LAST 15 READINGS FOR HISTORY
    // =====================================================

    if ($role === 'admin') {

        $logsStmt = $conn->query("
            SELECT *
            FROM soil_readings
            ORDER BY id DESC
            LIMIT 15
        ");

    } else {

        $logsStmt = $conn->prepare("
            SELECT *
            FROM soil_readings
            WHERE device_id = ?
            ORDER BY id DESC
            LIMIT 15
        ");

        $logsStmt->execute([$assignedDevice]);
    }

    $recentLogs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedLogs = [];

    foreach ($recentLogs as $log) {

        $logTimestamp = strtotime(
            $log['created_at'] ?? ''
        );

        if ($logTimestamp === false) {
            $logTimestamp = time();
        }

        $formattedLogs[] = [
            'id' => (int)($log['id'] ?? 0),

            'device_id' => trim(
                (string)($log['device_id'] ?? '')
            ),

            'created_at' => $log['created_at'] ?? '',

            'formatted_time' => !empty($log['created_at'])
                ? date(
                    "M j, Y - g:i A",
                    $logTimestamp
                )
                : 'N/A',

            'moisture' => floatval(
                $log['moisture'] ?? 0
            ),

            'ph' => floatval(
                $log['ph'] ?? 0
            ),

            'nitrogen' => intval(
                $log['nitrogen'] ?? 0
            ),

            'phosphorus' => intval(
                $log['phosphorus'] ?? 0
            ),

            'potassium' => intval(
                $log['potassium'] ?? 0
            ),

            'temperature' => floatval(
                $log['temperature'] ?? 0
            )
        ];
    }

    // =====================================================
    // 9. TOTAL COUNT
    // =====================================================

    if ($role === 'admin') {

        $countStmt = $conn->query("
            SELECT COUNT(*)
            FROM soil_readings
        ");

        $totalCount = (int)$countStmt->fetchColumn();

    } else {

        $countStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM soil_readings
            WHERE device_id = ?
        ");

        $countStmt->execute([$assignedDevice]);

        $totalCount = (int)$countStmt->fetchColumn();
    }

    // =====================================================
    // 10. RETURN JSON
    // =====================================================

    echo json_encode([
        "status" => "success",

        "data" => [
            "id" => (int)($latest['id'] ?? 0),

            "device_id" => $latest['device_id']
                ?? $assignedDevice
                ?? null,

            "moisture" => $valMoisture,

            "ph" => $valPh,

            "nitrogen" => $valN,

            "phosphorus" => $valP,

            "potassium" => $valK,

            "temperature" => $valTemp,

            "npk_online" => $npkOnline,

            "created_at" => $createdAt,

            "formatted_time" => $formattedTime,

            "chart_labels" => $chartLabels,

            "chart_data" => $chartData
        ],

        "recent_logs" => $formattedLogs,

        "total_count" => $totalCount,

        "assigned_device" => $assignedDevice,

        "matched_device" => $matchedCandidate,

        "role" => $role,

        /*
         * Temporary diagnostic information.
         * This helps us confirm exactly which device values
         * belong to the farmer.
         */
        "debug_candidates" => $deviceCandidates
    ]);

} catch (PDOException $e) {

    error_log(
        '[GET LIVE TELEMETRY][PDO] ' .
        $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Database error."
    ]);

} catch (Throwable $e) {

    error_log(
        '[GET LIVE TELEMETRY][GENERAL] ' .
        $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Server error."
    ]);
}
?>