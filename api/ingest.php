<?php

require_once '../includes/db.php';
/** @var PDO $pdo */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$input = file_get_contents('php://input');
if (empty($input)) {
    error_log("Ingest API: Empty input");
}
$data = json_decode($input, true);

if (!$data || !isset($data['api_key']) || !isset($data['target']) || !isset($data['mtr_data'])) {
    http_response_code(400);
    error_log("Ingest API: Invalid Input. Received: " . $input);
    echo json_encode(['error' => 'Invalid Input']);
    exit;
}

// Authenticate host
$stmt = $pdo->prepare("SELECT id, is_testing_bufferbloat FROM hosts WHERE api_key = ?");
$stmt->execute([$data['api_key']]);
$host = $stmt->fetch();

if (!$host) {
    http_response_code(401);
    error_log("Ingest API: Unauthorized api_key: " . $data['api_key']);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO mtr_results (host_id, target, is_under_load) VALUES (?, ?, ?)");
    $stmt->execute([$host['id'], $data['target'], $host['is_testing_bufferbloat']]);
    $result_id = $pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO mtr_hops (result_id, hop_number, hostname, loss, sent, last, avg, best, worst, stdev) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $hubs = $data['mtr_data']['report']['hubs'] ?? $data['mtr_data']['report']['hub'] ?? $data['mtr_data']['hub'] ?? [];

    foreach ($hubs as $hub) {
        $stmt->execute([
            $result_id,
            $hub['count'] ?? $hub['hop'] ?? 0,
            $hub['host'] ?? null,
            $hub['Loss%'] ?? $hub['loss'] ?? 0,
            $hub['Snt'] ?? $hub['snt'] ?? 0,
            $hub['Last'] ?? $hub['last'] ?? 0,
            $hub['Avg'] ?? $hub['avg'] ?? 0,
            $hub['Best'] ?? $hub['best'] ?? 0,
            $hub['Wrst'] ?? $hub['Worst'] ?? $hub['worst'] ?? 0,
            $hub['StDev'] ?? $hub['stdev'] ?? 0
        ]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'result_id' => $result_id]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
