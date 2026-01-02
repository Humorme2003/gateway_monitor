<?php
require_once '../includes/db.php';
/** @var PDO $pdo */

header('Content-Type: application/json');

// Security: In a real app, this should be protected by a secret key or only allowed from localhost
// $secret = $_GET['key'] ?? '';
// if ($secret !== 'YOUR_MAINTENANCE_KEY') {
//     http_response_code(403);
//     echo json_encode(['error' => 'Forbidden']);
//     exit;
// }

// Get retention period from settings or use default (30 days)
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'data_retention_days'");
    $stmt->execute();
    $days = $stmt->fetchColumn() ?: 30;

    $pdo->beginTransaction();
    
    // Delete mtr_results (cascades to mtr_hops because of ON DELETE CASCADE)
    $stmt = $pdo->prepare("DELETE FROM mtr_results WHERE timestamp < NOW() - INTERVAL ? DAY");
    $stmt->execute([(int)$days]);
    $mtr_count = $stmt->rowCount();
    
    // Delete speed_tests
    $stmt = $pdo->prepare("DELETE FROM speed_tests WHERE timestamp < NOW() - INTERVAL ? DAY");
    $stmt->execute([(int)$days]);
    $speed_count = $stmt->rowCount();
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Maintenance complete',
        'pruned' => [
            'mtr_results' => $mtr_count,
            'speed_tests' => $speed_count
        ],
        'retention_days' => $days
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
