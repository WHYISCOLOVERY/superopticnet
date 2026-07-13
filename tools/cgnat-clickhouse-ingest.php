<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/clickhouse-client.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function cgnat_ingest_arg(string $name, string $default = ''): string
{
    $prefix = '--' . $name . '=';
    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
        if ($arg === '--' . $name) {
            return '1';
        }
        if (str_starts_with((string) $arg, $prefix)) {
            return substr((string) $arg, strlen($prefix));
        }
    }
    return $default;
}

function cgnat_ingest_flag(string $name): bool
{
    return cgnat_ingest_arg($name) === '1';
}

function cgnat_ingest_endpoint(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return ['', 0];
    }
    if (preg_match('/^(.+):(\d+)$/', $value, $match) === 1 && substr_count($match[1], ':') === 0) {
        return [$match[1], (int) $match[2]];
    }
    return [$value, 0];
}

function cgnat_ingest_normalize_time(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return gmdate('Y-m-d H:i:s.u');
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? gmdate('Y-m-d H:i:s.u') : gmdate('Y-m-d H:i:s.000', $timestamp);
}

function cgnat_ingest_row_hash(array $row): string
{
    return hash('sha256', implode('|', [
        (string) ($row['logged_at'] ?? ''),
        (string) ($row['node_name'] ?? ''),
        (string) ($row['protocol'] ?? ''),
        (string) ($row['source_ip'] ?? ''),
        (string) ($row['source_port'] ?? ''),
        (string) ($row['nat_ip'] ?? ''),
        (string) ($row['nat_port'] ?? ''),
        (string) ($row['destination_ip'] ?? ''),
        (string) ($row['destination_port'] ?? ''),
        (string) ($row['username'] ?? ''),
        (string) ($row['subscriber_code'] ?? ''),
    ]));
}

function cgnat_ingest_csv_rows(string $file, string $nodeName): Generator
{
    $handle = fopen($file, 'rb');
    if ($handle === false) {
        return;
    }
    $header = fgetcsv($handle);
    if (!is_array($header)) {
        fclose($handle);
        return;
    }
    $columns = array_map(static function (mixed $key): string {
        $key = strtolower(trim((string) $key));
        return preg_replace('/[^a-z0-9_]+/', '_', $key) ?: $key;
    }, $header);
    while (($data = fgetcsv($handle)) !== false) {
        $row = [];
        foreach ($columns as $index => $column) {
            $row[$column] = $data[$index] ?? '';
        }
        [$sourceIp, $sourcePort] = cgnat_ingest_endpoint((string) ($row['source'] ?? $row['src'] ?? ''));
        [$natIp, $natPort] = cgnat_ingest_endpoint((string) ($row['nat'] ?? $row['translated'] ?? ''));
        [$destinationIp, $destinationPort] = cgnat_ingest_endpoint((string) ($row['destination'] ?? $row['dst'] ?? ''));
        yield [
            'logged_at' => cgnat_ingest_normalize_time((string) ($row['logged_at'] ?? $row['datetime'] ?? $row['date_time'] ?? '')),
            'node_name' => (string) ($row['node_name'] ?? $nodeName),
            'protocol' => strtoupper((string) ($row['protocol'] ?? $row['proto'] ?? '')),
            'source_ip' => (string) ($row['source_ip'] ?? $row['src_ip'] ?? $sourceIp),
            'source_port' => (int) ($row['source_port'] ?? $row['src_port'] ?? $sourcePort),
            'nat_ip' => (string) ($row['nat_ip'] ?? $row['public_ip'] ?? $natIp),
            'nat_port' => (int) ($row['nat_port'] ?? $row['translated_port'] ?? $natPort),
            'destination_ip' => (string) ($row['destination_ip'] ?? $row['dst_ip'] ?? $destinationIp),
            'destination_port' => (int) ($row['destination_port'] ?? $row['dst_port'] ?? $destinationPort),
            'username' => (string) ($row['username'] ?? $row['user'] ?? ''),
            'subscriber_code' => (string) ($row['subscriber_code'] ?? $row['customer_code'] ?? ''),
            'raw_message' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        ];
    }
    fclose($handle);
}

function cgnat_ingest_mysql_rows(PDO $db, int $limit): Generator
{
    $stmt = $db->prepare(
        'SELECT logged_at, node_name, protocol, source_ip, source_port, nat_ip, nat_port,
                destination_ip, destination_port, username, subscriber_code, raw_message, message_hash
           FROM radius_nat_logs
          ORDER BY logged_at ASC, id ASC
          LIMIT :limit'
    );
    $stmt->bindValue('limit', max(1, min($limit, 100000)), PDO::PARAM_INT);
    $stmt->execute();
    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        yield $row;
    }
}

function cgnat_ingest_spool_rows(string $spoolDir, string $nodeName, int $limit): Generator
{
    if (!is_dir($spoolDir)) {
        return;
    }
    $processed = 0;
    $files = glob(rtrim($spoolDir, '/\\') . '/*.{csv,log,txt}', GLOB_BRACE) ?: [];
    sort($files);
    foreach ($files as $file) {
        if ($processed >= $limit || !is_file($file)) {
            break;
        }
        if (preg_match('/\.csv$/i', $file) === 1) {
            foreach (cgnat_ingest_csv_rows($file, $nodeName) as $row) {
                yield $row + ['_spool_file' => $file];
                $processed++;
                if ($processed >= $limit) {
                    break 2;
                }
            }
            continue;
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            continue;
        }
        while (($line = fgets($handle)) !== false) {
            $parts = preg_split('/\s+/', trim($line));
            if (!is_array($parts) || count($parts) < 8) {
                continue;
            }
            yield [
                'logged_at' => cgnat_ingest_normalize_time(($parts[0] ?? '') . ' ' . ($parts[1] ?? '')),
                'node_name' => $nodeName,
                'protocol' => strtoupper((string) ($parts[2] ?? '')),
                'source_ip' => (string) ($parts[3] ?? ''),
                'source_port' => (int) ($parts[4] ?? 0),
                'nat_ip' => (string) ($parts[5] ?? ''),
                'nat_port' => (int) ($parts[6] ?? 0),
                'destination_ip' => (string) ($parts[7] ?? ''),
                'destination_port' => (int) ($parts[8] ?? 0),
                'username' => (string) ($parts[9] ?? ''),
                'subscriber_code' => (string) ($parts[10] ?? ''),
                'raw_message' => trim($line),
                '_spool_file' => $file,
            ];
            $processed++;
            if ($processed >= $limit) {
                break;
            }
        }
        fclose($handle);
    }
}

$apply = cgnat_ingest_flag('apply');
$source = cgnat_ingest_arg('source', 'mysql');
$file = cgnat_ingest_arg('file');
$spoolDir = cgnat_ingest_arg('spool-dir', dirname(__DIR__) . '/storage/cgnat-queue');
$node = cgnat_ingest_arg('node', 'nat-log-node-1');
$limit = max(1, (int) cgnat_ingest_arg('limit', '10000'));
$batchSize = max(1, min(10000, (int) cgnat_ingest_arg('batch', '5000')));

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "mysql_unavailable\n");
    exit(2);
}

$rows = match ($source) {
    'file' => cgnat_ingest_csv_rows($file, $node),
    'spool', 'queue' => cgnat_ingest_spool_rows($spoolDir, $node, $limit),
    default => cgnat_ingest_mysql_rows($db, $limit),
};

$result = [
    'status' => 'ok',
    'mode' => $apply ? 'apply' : 'dry-run',
    'source' => $source,
    'spool_dir' => in_array($source, ['spool', 'queue'], true) ? $spoolDir : null,
    'processed' => 0,
    'valid' => 0,
    'sent_batches' => 0,
    'sent_rows' => 0,
    'errors' => [],
];

$batch = [];
$spoolFiles = [];
foreach ($rows as $row) {
    $result['processed']++;
    if (trim((string) ($row['source_ip'] ?? '')) === '' || trim((string) ($row['destination_ip'] ?? '')) === '') {
        continue;
    }
    $row['message_hash'] = (string) ($row['message_hash'] ?? '') !== '' ? (string) $row['message_hash'] : cgnat_ingest_row_hash($row);
    $row['logged_at'] = cgnat_ingest_normalize_time((string) ($row['logged_at'] ?? ''));
    if (isset($row['_spool_file'])) {
        $spoolFiles[(string) $row['_spool_file']] = true;
        unset($row['_spool_file']);
    }
    $result['valid']++;
    $batch[] = $row;

    if (count($batch) >= $batchSize) {
        if ($apply) {
            $response = app_clickhouse_request(
                'INSERT INTO superoptic_analytics.radius_nat_logs FORMAT JSONEachRow',
                app_clickhouse_json_each_row($batch)
            );
            if (!$response['ok']) {
                $result['errors'][] = $response['error'] ?: ('http_' . $response['status']);
                $result['status'] = 'failed';
                break;
            }
        }
        $result['sent_batches']++;
        $result['sent_rows'] += count($batch);
        $batch = [];
    }
}

if ($apply && $result['status'] === 'ok' && $spoolFiles !== []) {
    $archiveDir = rtrim($spoolDir, '/\\') . '/processed/' . gmdate('Ymd');
    if (!is_dir($archiveDir)) {
        mkdir($archiveDir, 0775, true);
    }
    foreach (array_keys($spoolFiles) as $spoolFile) {
        if (is_file($spoolFile)) {
            @rename($spoolFile, $archiveDir . '/' . basename($spoolFile));
        }
    }
    $result['spool_files_archived'] = count($spoolFiles);
}

if ($batch !== [] && $result['status'] === 'ok') {
    if ($apply) {
        $response = app_clickhouse_request(
            'INSERT INTO superoptic_analytics.radius_nat_logs FORMAT JSONEachRow',
            app_clickhouse_json_each_row($batch)
        );
        if (!$response['ok']) {
            $result['errors'][] = $response['error'] ?: ('http_' . $response['status']);
            $result['status'] = 'failed';
        }
    }
    $result['sent_batches']++;
    $result['sent_rows'] += count($batch);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['status'] === 'ok' ? 0 : 3);
