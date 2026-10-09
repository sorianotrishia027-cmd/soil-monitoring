<?php
// Set PHP system timezone to Philippine Standard Time
date_default_timezone_set('Asia/Manila');

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
    
    // Set MySQL session time zone to Philippine Time (UTC+8)
    $conn->exec("SET time_zone = '+08:00';");

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

/**
 * Auto-ensure devices table exists
 */
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS devices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            node_name VARCHAR(100) NOT NULL,
            device_uid VARCHAR(100) NOT NULL,
            assigned_user_id INT NULL,
            status VARCHAR(50) DEFAULT 'available',
            location VARCHAR(150) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_device_uid (device_uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) {}

/**
 * Helper to fetch assigned node & device UID aliases for a farmer
 * Guarantees ESP32_GSM_01 and Node 1 are mapped as the same entity.
 */
function get_assigned_device_for_user($conn, $userId) {
    $userId = (int)$userId;
    if (!$conn || $userId <= 0) return null;

    // 1. Check devices table
    try {
        $stmt = $conn->prepare("
            SELECT id, node_name, device_uid, location, status 
            FROM devices 
            WHERE assigned_user_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $nodeName = trim($row['node_name']);
            $deviceUid = trim($row['device_uid']);
            $aliases = [$deviceUid, $nodeName];

            // Unify Node 1 and ESP32_GSM_01 (handling all spacing/hyphen/casing variations)
            $normNode = strtolower(str_replace([' ', '-', '_'], '', $nodeName));
            $normUid = strtolower(str_replace([' ', '-', '_'], '', $deviceUid));
            if ($normNode === 'node1' || $normNode === 'node01' || $normUid === 'esp32gsm01' || $normUid === 'node1') {
                $aliases = ['ESP32_GSM_01', 'ESP32_DEFAULT', 'Node 1', 'node1', 'Node-01', 'node-01', 'node_1', 'NODE 1', 'NODE-01', 'node 1'];
            }

            return [
                'node_id' => (int)$row['id'],
                'node_name' => $nodeName,
                'device_uid' => $deviceUid,
                'location' => $row['location'],
                'display_label' => $nodeName . ' (' . $deviceUid . ')',
                'aliases' => array_values(array_unique(array_filter($aliases)))
            ];
        }
    } catch (PDOException $e) {}

    // 2. Fallback to sensor_data table
    try {
        $stmt = $conn->prepare("
            SELECT device_label 
            FROM sensor_data 
            WHERE user_id = ? AND device_label IS NOT NULL AND TRIM(device_label) <> '' 
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $lbl = $stmt->fetchColumn();
        if ($lbl && trim((string)$lbl) !== '') {
            $lbl = trim((string)$lbl);
            $normLbl = strtolower(str_replace([' ', '-', '_'], '', $lbl));

            // Check if label matches any registered node in devices table
            $dStmt = $conn->prepare("SELECT id, node_name, device_uid, location FROM devices WHERE device_uid = ? OR node_name = ? LIMIT 1");
            $dStmt->execute([$lbl, $lbl]);
            $dRow = $dStmt->fetch(PDO::FETCH_ASSOC);
            if ($dRow) {
                $nodeName = trim($dRow['node_name']);
                $deviceUid = trim($dRow['device_uid']);
                $aliases = [$deviceUid, $nodeName];
                $normNode = strtolower(str_replace([' ', '-', '_'], '', $nodeName));
                $normUid = strtolower(str_replace([' ', '-', '_'], '', $deviceUid));
                if ($normNode === 'node1' || $normNode === 'node01' || $normUid === 'esp32gsm01' || $normUid === 'node1') {
                    $aliases = ['ESP32_GSM_01', 'ESP32_DEFAULT', 'Node 1', 'node1', 'Node-01', 'node-01', 'node_1', 'NODE 1', 'NODE-01', 'node 1'];
                }
                return [
                    'node_id' => (int)$dRow['id'],
                    'node_name' => $nodeName,
                    'device_uid' => $deviceUid,
                    'location' => $dRow['location'],
                    'display_label' => $nodeName . ' (' . $deviceUid . ')',
                    'aliases' => array_values(array_unique(array_filter($aliases)))
                ];
            }

            $nodeName = $lbl;
            $deviceUid = $lbl;
            $aliases = [$lbl];

            if ($normLbl === 'node1' || $normLbl === 'node01' || $normLbl === 'esp32gsm01' || $normLbl === 'esp32default' || str_contains($normLbl, 'node1')) {
                $nodeName = 'Node 1';
                $deviceUid = 'ESP32_GSM_01';
                $aliases = ['ESP32_GSM_01', 'ESP32_DEFAULT', 'Node 1', 'node1', 'Node-01', 'node-01', 'node_1', 'NODE 1', 'NODE-01', 'node 1'];
            }

            return [
                'node_id' => null,
                'node_name' => $nodeName,
                'device_uid' => $deviceUid,
                'location' => null,
                'display_label' => $nodeName === $deviceUid ? $nodeName : ($nodeName . ' (' . $deviceUid . ')'),
                'aliases' => array_values(array_unique(array_filter($aliases)))
            ];
        }
    } catch (PDOException $e) {}

    return null;
}

/**
 * Fetch all registered nodes with assigned farmer details
 */
function get_all_registered_nodes($conn) {
    try {
        $stmt = $conn->query("
            SELECT d.*, u.username as assigned_username, u.fullname as assigned_fullname 
            FROM devices d
            LEFT JOIN users u ON d.assigned_user_id = u.id
            ORDER BY d.id ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}
?>