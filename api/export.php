<?php

require_once '../includes/db.php';
/** @var PDO $pdo */

$host_id = $_GET['host_id'] ?? null;
$hop = $_GET['hop'] ?? 'all';
$type = $_GET['type'] ?? 'mtr'; // 'mtr' or 'speedtest'
$period = $_GET['period'] ?? null;
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;

$time_clause = "";
$params = [];

if ($period) {
    switch ($period) {
        case '30m': $time_clause = "timestamp >= NOW() - INTERVAL 30 MINUTE"; break;
        case '60m': $time_clause = "timestamp >= NOW() - INTERVAL 60 MINUTE"; break;
        case '120m': $time_clause = "timestamp >= NOW() - INTERVAL 120 MINUTE"; break;
        case '8h': $time_clause = "timestamp >= NOW() - INTERVAL 8 HOUR"; break;
        case '24h': $time_clause = "timestamp >= NOW() - INTERVAL 24 HOUR"; break;
        case '7d': $time_clause = "timestamp >= NOW() - INTERVAL 7 DAY"; break;
        case '30d': $time_clause = "timestamp >= NOW() - INTERVAL 30 DAY"; break;
    }
} elseif ($start_date && $end_date) {
    $time_clause = "timestamp BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
}

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="gateway_monitor_' . $type . '_export_' . date('Ymd_His') . '.csv"');

$output = fopen('php://output', 'w');

if ($type === 'speedtest') {
    // Header
    fputcsv($output, ['Host', 'Timestamp', 'Down (Mbps)', 'Up (Mbps)', 'Idle (ms)', 'Down Latency (ms)', 'Up Latency (ms)', 'Down Bloat (ms)', 'Up Bloat (ms)', 'Idle Jitter', 'Down Jitter', 'Up Jitter', 'Server ID', 'Server Name', 'Result URL']);

    $query = "
        SELECT h.name as host_name, s.timestamp, s.download_mbps, s.upload_mbps, s.latency_idle, s.latency_download, s.latency_upload, 
               (s.latency_download - s.latency_idle) as down_bloat, (s.latency_upload - s.latency_idle) as up_bloat,
               s.jitter_idle, s.jitter_download, s.jitter_upload, s.server_id, s.server_name, s.result_url
        FROM speed_tests s
        JOIN hosts h ON s.host_id = h.id
    ";

    $where = [];
    $params_final = [];
    
    if ($host_id) {
        $where[] = "s.host_id = ?";
        $params_final[] = (int)$host_id;
    }
    
    foreach ($params as $p) {
        $params_final[] = $p;
    }

    if ($time_clause) {
        $where[] = "s." . $time_clause;
    }

    if (!empty($where)) {
        $query .= " WHERE " . implode(" AND ", $where);
    }

    $query .= " ORDER BY s.timestamp DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params_final);

    while ($row = $stmt->fetch()) {
        fputcsv($output, $row);
    }
} else {
    // MTR Export (Default)
    fputcsv($output, ['Host', 'Timestamp', 'Target', 'Hop', 'Hostname', 'Loss%', 'Sent', 'Last', 'Avg', 'Best', 'Worst', 'StDev', 'Is Under Load']);

    $query = "
        SELECT h.name as host_name, r.timestamp, r.target, p.hop_number, p.hostname, p.loss, p.sent, p.last, p.avg, p.best, p.worst, p.stdev, r.is_under_load
        FROM mtr_results r
        JOIN hosts h ON r.host_id = h.id
        JOIN mtr_hops p ON r.id = p.result_id
    ";

    $where = [];
    $params_final = [];
    
    if ($host_id) {
        $where[] = "r.host_id = ?";
        $params_final[] = (int)$host_id;
    }
    
    if ($hop !== 'all') {
        $where[] = "p.hop_number = ?";
        $params_final[] = (int)$hop;
    }
    
    foreach ($params as $p) {
        $params_final[] = $p;
    }

    if ($time_clause) {
        $where[] = "r." . $time_clause;
    }

    if (!empty($where)) {
        $query .= " WHERE " . implode(" AND ", $where);
    }

    $query .= " ORDER BY r.timestamp DESC, h.name ASC, p.hop_number ASC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params_final);

    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['host_name'],
            $row['timestamp'],
            $row['target'],
            $row['hop_number'],
            $row['hostname'],
            $row['loss'],
            $row['sent'],
            $row['last'],
            $row['avg'],
            $row['best'],
            $row['worst'],
            $row['stdev'],
            $row['is_under_load'] ? 'Yes' : 'No'
        ]);
    }
}

fclose($output);
