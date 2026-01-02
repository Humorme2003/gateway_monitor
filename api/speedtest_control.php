<?php

require_once '../includes/db.php';
/** @var PDO $pdo */

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$api_key = $_POST['api_key'] ?? $_GET['api_key'] ?? '';
$force = isset($_GET['force']) || isset($_POST['force']);

if (empty($api_key)) {
    http_response_code(400);
    echo json_encode(['error' => 'API key required']);
    exit;
}

// Authenticate host
$stmt = $pdo->prepare("SELECT id, last_speed_test, speedtest_server_id FROM hosts WHERE api_key = ?");
$stmt->execute([$api_key]);
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

if ($action === 'start') {
    // Check if it's too soon
    if (!$force && $host['last_speed_test']) {
        $last_test = strtotime($host['last_speed_test']);
        $seconds_since = time() - $last_test;
        if ($seconds_since < ($interval_min * 60)) {
            $remaining = ($interval_min * 60) - $seconds_since;
            http_response_code(429); // Too Many Requests
            echo json_encode([
                'success' => false, 
                'error' => 'Too soon', 
                'remaining_seconds' => $remaining,
                'message' => "Next test due in " . ceil($remaining / 60) . " minutes."
            ]);
            exit;
        }
    }

    $stmt = $pdo->prepare("UPDATE hosts SET is_testing_bufferbloat = 1 WHERE id = ?");
    $stmt->execute([$host['id']]);

    // Priority: 1. Host-specific setting, 2. Global setting
    $server_id = $host['speedtest_server_id'];

    if (empty($server_id)) {
        // Fetch global server ID setting
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'speedtest_server_id'");
        $stmt->execute();
        $server_id = $stmt->fetchColumn();
    }

    echo json_encode(['success' => true, 'status' => 'started', 'server_id' => $server_id]);
} elseif ($action === 'stop') {
    $stmt = $pdo->prepare("UPDATE hosts SET is_testing_bufferbloat = 0 WHERE id = ?");
    $stmt->execute([$host['id']]);
    echo json_encode(['success' => true, 'status' => 'stopped']);
} elseif ($action === 'status') {
    $stmt = $pdo->prepare("SELECT is_testing_bufferbloat FROM hosts WHERE id = ?");
    $stmt->execute([$host['id']]);
    $status = $stmt->fetch();
    echo json_encode(['is_testing_bufferbloat' => (bool)$status['is_testing_bufferbloat']]);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
}
