<?php

require_once '../includes/db.php';
/** @var PDO $pdo */

header('Content-Type: application/json');

/**
 * Choose an aggregation bucket (in seconds) to keep chart payloads reasonable for long time ranges.
 * 0 means "no aggregation".
 */
function choose_bucket_seconds(?string $period, ?string $startDate, ?string $endDate): int
{
    // Fixed presets for the built-in UI periods
    switch ($period) {
        case '30d':
            return 7200; // 2 hours
        case '7d':
            return 1800; // 30 minutes
        case '24h':
            return 300; // 5 minutes
        case '8h':
            return 60; // 1 minute
        case '120m':
        case '60m':
        case '30m':
            return 0;
    }

    // For custom ranges, pick a bucket that targets ~2000 points max.
    if ($startDate && $endDate) {
        try {
            $start = new DateTime($startDate);
            $end = new DateTime($endDate);
        } catch (Exception $e) {
            return 0;
        }

        $seconds = $end->getTimestamp() - $start->getTimestamp();
        if ($seconds <= 0) return 0;

        $targetPoints = 2000;
        $rawBucket = (int)ceil($seconds / $targetPoints);

        // Round up to a "nice" bucket size.
        $nice = [60, 300, 900, 1800, 3600, 7200, 14400, 21600, 43200, 86400];
        foreach ($nice as $b) {
            if ($rawBucket <= $b) return $b;
        }
        return 86400;
    }

    return 0;
}

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

// Keep non-chart payloads bounded even when the requested time range is large.
// This prevents the API from timing out / exhausting memory once enough MTR data accumulates.
$table_result_limit = $limit ? max(1, min((int)$limit, 300)) : 200; // number of mtr_results rows per host
$events_limit = $limit ? max(1, min((int)$limit, 5000)) : 2000;
$res_limit_sql = "LIMIT " . (int)$table_result_limit;
$events_limit_sql = "LIMIT " . (int)$events_limit;

$bucket_seconds = choose_bucket_seconds($period, $start_date, $end_date);

$response_data = [];
$response_events = [];
$response_mtr = [];

try {
    // Fetch all hosts
    $stmt_all_hosts = $pdo->query("SELECT id, name, api_key, speedtest_server_id FROM hosts ORDER BY name ASC");
    $all_hosts = $stmt_all_hosts->fetchAll();

// 1. Fetch CHART data
if (in_array($metric, ['bufferbloat', 'download', 'upload', 'speedtest'], true)) {
    foreach ($all_hosts as $host) {
        if ($host_id && $host_id != $host['id']) continue;

        if ($bucket_seconds > 0) {
            $bucket = (int)$bucket_seconds;
            $bucket_ts = "FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(s.timestamp) / $bucket) * $bucket)";
            $sql = "
                SELECT
                    $bucket_ts AS timestamp,
                    AVG(s.download_mbps) AS download_mbps,
                    AVG(s.upload_mbps) AS upload_mbps,
                    AVG(s.latency_idle) AS latency_idle,
                    AVG(s.latency_download) AS latency_download,
                    AVG(s.latency_upload) AS latency_upload
                FROM speed_tests s
                WHERE s.host_id = ? AND s.$time_clause
                GROUP BY timestamp
                ORDER BY timestamp ASC
            ";
        } else {
            $sql = "SELECT s.timestamp, s.download_mbps, s.upload_mbps, s.latency_idle, s.latency_download, s.latency_upload FROM speed_tests s WHERE s.host_id = ? AND s.$time_clause ORDER BY s.timestamp DESC $limit_sql";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$host['id']], $params_base));
        $tests = $stmt->fetchAll();
        if ($bucket_seconds === 0) {
            $tests = array_reverse($tests);
        }

        if ($metric === 'bufferbloat') {
            $response_data[$host['name'] . ' (Down Bloat)'] = array_map(
                static fn($t) => ['timestamp' => $t['timestamp'], 'value' => max(0, (float)$t['latency_download'] - (float)$t['latency_idle']), 'is_under_load' => false],
                $tests
            );
            $response_data[$host['name'] . ' (Up Bloat)'] = array_map(
                static fn($t) => ['timestamp' => $t['timestamp'], 'value' => max(0, (float)$t['latency_upload'] - (float)$t['latency_idle']), 'is_under_load' => false],
                $tests
            );
        } elseif ($metric === 'speedtest') {
            $response_data[$host['name'] . ' (Download)'] = array_map(
                static fn($t) => ['timestamp' => $t['timestamp'], 'value' => $t['download_mbps'], 'is_under_load' => false],
                $tests
            );
            $response_data[$host['name'] . ' (Upload)'] = array_map(
                static fn($t) => ['timestamp' => $t['timestamp'], 'value' => $t['upload_mbps'], 'is_under_load' => false],
                $tests
            );
        } else {
            $col = ($metric === 'download') ? 'download_mbps' : 'upload_mbps';
            $response_data[$host['name']] = array_map(
                static fn($t) => ['timestamp' => $t['timestamp'], 'value' => $t[$col], 'is_under_load' => false],
                $tests
            );
        }
    }
} else {
    foreach ($all_hosts as $host) {
        if ($host_id && $host_id != $host['id']) continue;

        $current_params = array_merge([$host['id']], $params_base);
        if ($hop !== 'last') $current_params[] = (int)$hop;

        if ($bucket_seconds > 0) {
            $bucket = (int)$bucket_seconds;
            $bucket_ts = "FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(r.timestamp) / $bucket) * $bucket)";
            $sql = "
                SELECT
                    $bucket_ts AS timestamp,
                    AVG(h.`$metric_col`) AS value,
                    MAX(r.is_under_load) AS is_under_load
                FROM mtr_results r
                JOIN mtr_hops h ON r.id = h.result_id
                WHERE r.host_id = ? AND r.$time_clause AND h.hop_number = $hop_sql
                GROUP BY timestamp
                ORDER BY timestamp ASC
            ";
        } else {
            $sql = "SELECT r.timestamp, h.`$metric_col` as value, r.is_under_load FROM mtr_results r JOIN mtr_hops h ON r.id = h.result_id WHERE r.host_id = ? AND r.$time_clause AND h.hop_number = $hop_sql ORDER BY r.timestamp DESC $limit_sql";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($current_params);
        $rows = $stmt->fetchAll();
        if ($bucket_seconds === 0) {
            $rows = array_reverse($rows);
        }
        $response_data[$host['name']] = $rows;
    }
}

// 2. Fetch MTR data for the table (ALL HOPS)
// Always bounded by `$table_result_limit` most recent results per host.
foreach ($all_hosts as $host) {
    if ($host_id && $host_id != $host['id']) continue;

    $sql_mtr = "
        SELECT r.timestamp, r.is_under_load, r.target, h.hop_number, h.hostname, h.avg, h.loss
        FROM mtr_results r
        JOIN mtr_hops h ON r.id = h.result_id
        WHERE r.host_id = ? AND r.$time_clause
        AND r.id IN (
            SELECT id FROM (
                SELECT id
                FROM mtr_results
                WHERE host_id = ? AND $time_clause
                ORDER BY timestamp DESC
                $res_limit_sql
            ) tmp
        )
        ORDER BY r.timestamp DESC, h.hop_number ASC
    ";

    $stmt_mtr = $pdo->prepare($sql_mtr);
    $stmt_mtr->execute(array_merge([$host['id']], $params_base, [$host['id']], $params_base));
    $response_mtr[$host['name']] = $stmt_mtr->fetchAll();
}

// 3. Fetch Events (bounded)
$sql_events = "
    SELECT s.timestamp, s.download_mbps, s.upload_mbps, s.latency_idle, s.latency_download, s.latency_upload, s.result_url, h.name as host_name
    FROM speed_tests s
    JOIN hosts h ON s.host_id = h.id
    WHERE s.$time_clause
    ORDER BY s.timestamp ASC
    $events_limit_sql
";
$stmt_events = $pdo->prepare($sql_events);
$stmt_events->execute($params_base);
$response_events = $stmt_events->fetchAll();

// 4. Max Hop (computed over a bounded recent set to avoid scanning huge history)
$max_hop_scope = 500;
$max_hop_sql = "
    SELECT MAX(h.hop_number)
    FROM mtr_hops h
    WHERE h.result_id IN (
        SELECT id FROM (
            SELECT id
            FROM mtr_results
            WHERE " . ($host_id ? "host_id = ? AND " : "") . "$time_clause
            ORDER BY timestamp DESC
            LIMIT " . (int)$max_hop_scope . "
        ) t
    )
";
$stmt_max_hop = $pdo->prepare($max_hop_sql);
$stmt_max_hop->execute($host_id ? array_merge([(int)$host_id], $params_base) : $params_base);
$max_hop = $stmt_max_hop->fetchColumn() ?: 0;

    echo json_encode([
        'data' => $response_data,
        'events' => $response_events,
        'mtr' => $response_mtr,
        'hosts' => $all_hosts,
        'max_hop' => (int)$max_hop,
        'bucket_seconds' => (int)$bucket_seconds
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error while fetching data.',
        'details' => $e->getMessage()
    ]);
}
