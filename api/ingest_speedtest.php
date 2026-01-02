<?php

require_once '../includes/db.php';
/** @var PDO $pdo */

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);
$force = isset($data['force']) && $data['force'] === true;

if (!$data || !isset($data['api_key']) || !isset($data['speedtest_data'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid Input']);
    exit;
}

// Authenticate host
$stmt = $pdo->prepare("SELECT id, last_speed_test FROM hosts WHERE api_key = ?");
$stmt->execute([$data['api_key']]);
$host = $stmt->fetch();

if (!$host) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Fetch interval setting
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'speedtest_interval'");
$stmt->execute();
$interval_min = $stmt->fetchColumn() ?: 60; // Default to 60 if not set

// Rate limiting based on setting (with a 5-minute grace period to account for execution time)
if (!$force && $host['last_speed_test']) {
    $last_test = strtotime($host['last_speed_test']);
    if (time() - $last_test < (($interval_min * 60) - 300)) {
        http_response_code(429);
        echo json_encode(['error' => 'Rate limit exceeded based on speedtest_interval setting.']);
        exit;
    }
}

$st = $data['speedtest_data'];

try {
    $stmt = $pdo->prepare("
        INSERT INTO speed_tests (
            host_id, download_mbps, upload_mbps, 
            latency_idle, latency_download, latency_upload,
            jitter_idle, jitter_download, jitter_upload,
            server_id, server_name, result_url
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $host['id'],
        ($st['download']['bandwidth'] ?? 0) * 8 / 1000000, // bits to mbps
        ($st['upload']['bandwidth'] ?? 0) * 8 / 1000000,
        $st['ping']['latency'] ?? 0,
        $st['download']['latency']['iqm'] ?? 0,
        $st['upload']['latency']['iqm'] ?? 0,
        $st['ping']['jitter'] ?? 0,
        $st['download']['latency']['jitter'] ?? 0,
        $st['upload']['latency']['jitter'] ?? 0,
        $st['server']['id'] ?? null,
        $st['server']['name'] ?? null,
        $st['result']['url'] ?? null
    ]);

    $stmt = $pdo->prepare("UPDATE hosts SET last_speed_test = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$host['id']]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
