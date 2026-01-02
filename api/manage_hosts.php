<?php

require_once '../includes/db.php';
/** @var PDO $pdo */

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['action'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$action = $input['action'];

try {
    if ($action === 'add') {
        $name = $input['name'] ?? 'New Gateway';
        $server_id = $input['speedtest_server_id'] ?? null;
        if (empty($server_id)) $server_id = null;
        
        // Generate a random API key
        $api_key = bin2hex(random_bytes(16));
        
        $stmt = $pdo->prepare("INSERT INTO hosts (name, api_key, speedtest_server_id) VALUES (?, ?, ?)");
        $stmt->execute([$name, $api_key, $server_id]);
        
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'api_key' => $api_key]);
        
    } elseif ($action === 'update') {
        if (!isset($input['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Host ID required']);
            exit;
        }
        
        $id = (int)$input['id'];
        $name = $input['name'] ?? null;
        $server_id = isset($input['speedtest_server_id']) ? $input['speedtest_server_id'] : null;
        
        $fields = [];
        $params = [];
        
        if ($name !== null) {
            $fields[] = "name = ?";
            $params[] = $name;
        }
        
        if ($server_id !== null) {
            $fields[] = "speedtest_server_id = ?";
            $params[] = $server_id === "" ? null : $server_id;
        }
        
        if (empty($fields)) {
            echo json_encode(['success' => true, 'message' => 'No changes made']);
            exit;
        }
        
        $params[] = $id;
        $sql = "UPDATE hosts SET " . implode(", ", $fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        echo json_encode(['success' => true]);
        
    } elseif ($action === 'delete') {
        if (!isset($input['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Host ID required']);
            exit;
        }
        
        $id = (int)$input['id'];
        
        // Note: mtr_results and speed_tests should have ON DELETE CASCADE in schema.
        $stmt = $pdo->prepare("DELETE FROM hosts WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(['success' => true]);
        
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
