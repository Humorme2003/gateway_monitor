<?php

require_once '../includes/db.php';
/** @var PDO $pdo */

header('Content-Type: application/json');

$host_id = $_GET['host_id'] ?? null;
$limit = $_GET['limit'] ?? null;
$hop = $_GET['hop'] ?? 'last';
$metric = $_GET['metric'] ?? 'avg';
$period = $_GET['period'] ?? null;
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;

// Map the metric to a hard-coded column name to prevent SQL injection
$metric_map = [
    'last'  => 'last',
    'best'  => 'best',
    'worst' => 'worst',
    'stdev' => 'stdev',
    'loss'  => 'loss',
    'bufferbloat' => 'avg',
    'speedtest' => 'download_mbps',
    'download' => 'download_mbps',
    'upload' => 'upload_mbps'
];
$metric_col = $metric_map[$metric] ?? 'avg';

$time_clause = "";
$params_base = [];

if ($period) {
    switch ($period) {
        case '30m': $time_clause = "timestamp >= NOW() - INTERVAL 30 MINUTE"; break;
        case '60m': $time_clause = "timestamp >= NOW() - INTERVAL 60 MINUTE"; break;
        case '120m': $time_clause = "timestamp >= NOW() - INTERVAL 120 MINUTE"; break;
        case '8h': $time_clause = "timestamp >= NOW() - INTERVAL 8 HOUR"; break;
        case '24h': $time_clause = "timestamp >= NOW() - INTERVAL 24 HOUR"; break;
        case '7d': $time_clause = "timestamp >= NOW() - INTERVAL 7 DAY"; break;
        case '30d': $time_clause = "timestamp >= NOW() - INTERVAL 30 DAY"; break;
        default: $time_clause = "timestamp >= NOW() - INTERVAL 60 MINUTE";
    }
} elseif ($start_date && $end_date) {
    $time_clause = "timestamp BETWEEN ? AND ?";
    $params_base[] = $start_date;
    $params_base[] = $end_date;
} else {
    $time_clause = "timestamp >= NOW() - INTERVAL 60 MINUTE";
}

$hop_sql = ($hop === 'last') 
    ? "(SELECT MAX(hop_number) FROM mtr_hops WHERE result_id = r.id)" 
    : "?";

$limit_sql = $limit ? "LIMIT " . (int)$limit : "";

$response_data = [];
$response_events = [];
$response_mtr = [];

// Fetch all hosts
$stmt_all_hosts = $pdo->query("SELECT id, name, api_key, speedtest_server_id FROM hosts ORDER BY name ASC");
$all_hosts = $stmt_all_hosts->fetchAll();

// 1. Fetch CHART data
if (in_array($metric, ['bufferbloat', 'download', 'upload', 'speedtest'])) {
    foreach ($all_hosts as $host) {
        if ($host_id && $host_id != $host['id']) continue;
        
        $sql = "SELECT timestamp, download_mbps, upload_mbps, latency_idle, latency_download, latency_upload FROM speed_tests WHERE host_id = ? AND $time_clause ORDER BY timestamp DESC $limit_sql";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$host['id']], $params_base));
        $tests = $stmt->fetchAll();
        
        if ($metric === 'bufferbloat') {
            $response_data[$host['name'] . ' (Down Bloat)'] = array_reverse(array_map(fn($t) => ['timestamp' => $t['timestamp'], 'value' => max(0, $t['latency_download'] - $t['latency_idle']), 'is_under_load' => false], $tests));
            $response_data[$host['name'] . ' (Up Bloat)'] = array_reverse(array_map(fn($t) => ['timestamp' => $t['timestamp'], 'value' => max(0, $t['latency_upload'] - $t['latency_idle']), 'is_under_load' => false], $tests));
        } elseif ($metric === 'speedtest') {
            $response_data[$host['name'] . ' (Download)'] = array_reverse(array_map(fn($t) => ['timestamp' => $t['timestamp'], 'value' => $t['download_mbps'], 'is_under_load' => false], $tests));
            $response_data[$host['name'] . ' (Upload)'] = array_reverse(array_map(fn($t) => ['timestamp' => $t['timestamp'], 'value' => $t['upload_mbps'], 'is_under_load' => false], $tests));
        } else {
            $col = ($metric === 'download') ? 'download_mbps' : 'upload_mbps';
            $response_data[$host['name']] = array_reverse(array_map(fn($t) => ['timestamp' => $t['timestamp'], 'value' => $t[$col], 'is_under_load' => false], $tests));
        }
    }
} else {
    foreach ($all_hosts as $host) {
        if ($host_id && $host_id != $host['id']) continue;
        
        $current_params = array_merge([$host['id']], $params_base);
        if ($hop !== 'last') $current_params[] = (int)$hop;
        
        $sql = "SELECT r.timestamp, h.`$metric_col` as value, r.is_under_load FROM mtr_results r JOIN mtr_hops h ON r.id = h.result_id WHERE r.host_id = ? AND r.$time_clause AND h.hop_number = $hop_sql ORDER BY r.timestamp DESC $limit_sql";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($current_params);
        $response_data[$host['name']] = array_reverse($stmt->fetchAll());
    }
}

// 2. Fetch MTR data for the table (ALL HOPS)
foreach ($all_hosts as $host) {
    if ($host_id && $host_id != $host['id']) continue;
    
    $res_limit_sql = $limit ? "LIMIT " . (int)$limit : "";
    
    $sql_mtr = "
        SELECT r.timestamp, r.is_under_load, r.target, h.hop_number, h.hostname, h.avg, h.loss
        FROM mtr_results r
        JOIN mtr_hops h ON r.id = h.result_id
        WHERE r.host_id = ? AND r.$time_clause
        AND r.id IN (SELECT id FROM (SELECT id FROM mtr_results WHERE host_id = ? AND $time_clause ORDER BY timestamp DESC $res_limit_sql) tmp)
        ORDER BY r.timestamp DESC, h.hop_number ASC
    ";
    
    $stmt_mtr = $pdo->prepare($sql_mtr);
    $stmt_mtr->execute(array_merge([$host['id']], $params_base, [$host['id']], $params_base));
    $response_mtr[$host['name']] = $stmt_mtr->fetchAll();
}

// 3. Fetch Events
$sql_events = "SELECT s.timestamp, s.download_mbps, s.upload_mbps, s.latency_idle, s.latency_download, s.latency_upload, s.result_url, h.name as host_name FROM speed_tests s JOIN hosts h ON s.host_id = h.id WHERE s.$time_clause ORDER BY s.timestamp ASC";
$stmt_events = $pdo->prepare($sql_events);
$stmt_events->execute($params_base);
$response_events = $stmt_events->fetchAll();

// 4. Max Hop
$max_hop_sql = "SELECT MAX(h.hop_number) FROM mtr_hops h JOIN mtr_results r ON h.result_id = r.id WHERE " . ($host_id ? "r.host_id = ? AND " : "") . "r.$time_clause";
$stmt_max_hop = $pdo->prepare($max_hop_sql);
$stmt_max_hop->execute($host_id ? array_merge([(int)$host_id], $params_base) : $params_base);
$max_hop = $stmt_max_hop->fetchColumn() ?: 0;

echo json_encode([
    'data' => $response_data,
    'events' => $response_events,
    'mtr' => $response_mtr,
    'hosts' => $all_hosts,
    'max_hop' => (int)$max_hop
]);
