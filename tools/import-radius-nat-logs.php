<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function nat_import_arg(string $name, string $default = ''): string
{
    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
        if ($arg === '--' . $name) {
            return '1';
        }
        $prefix = '--' . $name . '=';
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }

    return $default;
}

function nat_import_has_flag(string $name): bool
{
    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
        if ($arg === '--' . $name || str_starts_with($arg, '--' . $name . '=')) {
            return true;
        }
    }

    return false;
}

function nat_import_normalize_key(string $key): string
{
    $key = preg_replace('/^\xEF\xBB\xBF/', '', $key) ?? $key;
    $key = strtolower(trim($key));
    $key = str_replace([' ', '-', '.', '/', '\\'], '_', $key);
    return preg_replace('/_+/', '_', $key) ?? $key;
}

function nat_import_pick(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
            return trim((string) $row[$key]);
        }
    }

    return '';
}

function nat_import_parse_endpoint(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return ['', null];
    }

    if (preg_match('/^\[([0-9a-f:.]+)\]:(\d+)$/i', $value, $match) === 1) {
        return [$match[1], (int) $match[2]];
    }

    if (preg_match('/^(.+):(\d+)$/', $value, $match) === 1 && substr_count($match[1], ':') === 0) {
        return [$match[1], (int) $match[2]];
    }

    return [$value, null];
}

function nat_import_parse_datetime_value(string $date, string $time = ''): string
{
    $date = trim($date);
    $time = trim($time);
    $value = trim($date . ($time !== '' ? ' ' . $time : ''));
    if ($value === '') {
        return date('Y-m-d H:i:s');
    }

    $parsed = app_db_parse_datetime($value);
    if ($parsed !== null) {
        return $parsed;
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', $timestamp);
}

function nat_import_row_from_csv(array $row, string $nodeName): array
{
    $loggedAt = nat_import_parse_datetime_value(
        nat_import_pick($row, ['logged_at', 'datetime', 'date_time', 'tarix', 'date']),
        nat_import_pick($row, ['time', 'saat'])
    );

    [$sourceIp, $sourcePortFromEndpoint] = nat_import_parse_endpoint(nat_import_pick($row, ['source', 'src', 'src_endpoint']));
    [$natIp, $natPortFromEndpoint] = nat_import_parse_endpoint(nat_import_pick($row, ['nat', 'translated', 'nat_endpoint']));
    [$destinationIp, $destinationPortFromEndpoint] = nat_import_parse_endpoint(nat_import_pick($row, ['destination', 'dst', 'dst_endpoint']));

    $sourceIp = $sourceIp !== '' ? $sourceIp : nat_import_pick($row, ['source_ip', 'src_ip', 'manba_ip']);
    $natIp = $natIp !== '' ? $natIp : nat_import_pick($row, ['nat_ip', 'public_ip', 'translated_ip']);
    $destinationIp = $destinationIp !== '' ? $destinationIp : nat_import_pick($row, ['destination_ip', 'dst_ip', 'target_ip', 'hedef_ip']);

    return [
        'logged_at' => $loggedAt,
        'node_name' => nat_import_pick($row, ['node_name', 'node', 'server']) ?: $nodeName,
        'protocol' => strtoupper(nat_import_pick($row, ['protocol', 'proto'])),
        'source_ip' => $sourceIp,
        'source_port' => nat_import_pick($row, ['source_port', 'src_port', 'spt']) !== '' ? (int) nat_import_pick($row, ['source_port', 'src_port', 'spt']) : $sourcePortFromEndpoint,
        'nat_ip' => $natIp,
        'nat_port' => nat_import_pick($row, ['nat_port', 'translated_port']) !== '' ? (int) nat_import_pick($row, ['nat_port', 'translated_port']) : $natPortFromEndpoint,
        'destination_ip' => $destinationIp,
        'destination_port' => nat_import_pick($row, ['destination_port', 'dst_port', 'dpt']) !== '' ? (int) nat_import_pick($row, ['destination_port', 'dst_port', 'dpt']) : $destinationPortFromEndpoint,
        'username' => nat_import_pick($row, ['username', 'user_name', 'user']),
        'subscriber_code' => nat_import_pick($row, ['subscriber_code', 'customer_code', 'abonent_kodu']),
        'raw_message' => nat_import_pick($row, ['raw_message', 'message', 'raw']) ?: json_encode($row, JSON_UNESCAPED_UNICODE),
    ];
}

function nat_import_parse_key_values(string $line): array
{
    $pairs = [];
    if (preg_match_all('/\b([A-Za-z_][A-Za-z0-9_-]*)=("[^"]*"|\'[^\']*\'|\S+)/', $line, $matches, PREG_SET_ORDER) !== false) {
        foreach ($matches as $match) {
            $value = trim((string) $match[2], "\"'");
            $pairs[nat_import_normalize_key((string) $match[1])] = $value;
        }
    }

    return $pairs;
}

function nat_import_row_from_line(string $line, string $nodeName): ?array
{
    $line = trim($line);
    if ($line === '') {
        return null;
    }

    $pairs = nat_import_parse_key_values($line);
    if ($pairs === []) {
        return null;
    }

    $dateValue = $pairs['logged_at'] ?? $pairs['datetime'] ?? $pairs['date'] ?? '';
    if ($dateValue === '' && preg_match('/^([A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})/', $line, $match) === 1) {
        $dateValue = date('Y') . ' ' . $match[1];
    }

    $csvRow = [
        'logged_at' => $dateValue,
        'node_name' => $pairs['node'] ?? $pairs['node_name'] ?? $nodeName,
        'protocol' => $pairs['proto'] ?? $pairs['protocol'] ?? '',
        'source_ip' => $pairs['src'] ?? $pairs['source_ip'] ?? '',
        'source_port' => $pairs['spt'] ?? $pairs['source_port'] ?? '',
        'nat_ip' => $pairs['nat'] ?? $pairs['nat_ip'] ?? $pairs['translated_ip'] ?? '',
        'nat_port' => $pairs['nat_port'] ?? $pairs['translated_port'] ?? '',
        'destination_ip' => $pairs['dst'] ?? $pairs['destination_ip'] ?? '',
        'destination_port' => $pairs['dpt'] ?? $pairs['destination_port'] ?? '',
        'username' => $pairs['username'] ?? $pairs['user'] ?? '',
        'subscriber_code' => $pairs['subscriber_code'] ?? $pairs['code'] ?? '',
        'raw_message' => $line,
    ];

    return nat_import_row_from_csv($csvRow, $nodeName);
}

function nat_import_row_hash(array $row): string
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

function nat_import_valid_row(array $row): bool
{
    return trim((string) ($row['source_ip'] ?? '')) !== '' && trim((string) ($row['destination_ip'] ?? '')) !== '';
}

if (nat_import_has_flag('help')) {
    echo "Usage:\n";
    echo "  php tools/import-radius-nat-logs.php --file=/path/nat.csv --node=nat-log-node-1 --apply\n";
    echo "  php tools/import-radius-nat-logs.php --file=/path/syslog.log --format=syslog --node=nat-log-node-1\n";
    echo "\nDefault mode is dry-run. Add --apply to write DB rows.\n";
    exit(0);
}

$file = nat_import_arg('file');
$format = strtolower(nat_import_arg('format', 'auto'));
$nodeName = trim(nat_import_arg('node', 'nat-log-node-1'));
$nodeIp = trim(nat_import_arg('node-ip', ''));
$apply = nat_import_has_flag('apply');

if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "NAT log fayli tapilmadi. --file=... verin.\n");
    exit(1);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "MySQL baglantisi hazir deyil.\n");
    exit(1);
}

$result = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'file' => $file,
    'format' => $format,
    'node' => $nodeName,
    'processed' => 0,
    'valid' => 0,
    'inserted' => 0,
    'duplicates' => 0,
    'invalid' => 0,
];

$insert = $db->prepare(
    'INSERT IGNORE INTO radius_nat_logs (
        logged_at, node_name, protocol, source_ip, source_port, nat_ip, nat_port,
        destination_ip, destination_port, username, subscriber_code, raw_message, message_hash
     ) VALUES (
        :logged_at, :node_name, :protocol, :source_ip, :source_port, :nat_ip, :nat_port,
        :destination_ip, :destination_port, :username, :subscriber_code, :raw_message, :message_hash
     )'
);

if ($apply) {
    $node = $db->prepare(
        'INSERT INTO radius_nat_log_nodes (node_name, ip_address, last_seen_at)
         VALUES (:node_name, :ip_address, NOW())
         ON DUPLICATE KEY UPDATE ip_address = VALUES(ip_address), last_seen_at = NOW(), updated_at = NOW()'
    );
    $node->execute([
        'node_name' => $nodeName,
        'ip_address' => $nodeIp,
    ]);
}

$handle = fopen($file, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Fayl oxunmadi.\n");
    exit(1);
}

$rows = [];
if ($format === 'csv' || ($format === 'auto' && preg_match('/\.csv$/i', $file) === 1)) {
    $header = fgetcsv($handle);
    if (!is_array($header) || $header === []) {
        fwrite(STDERR, "CSV basligi bosdur.\n");
        fclose($handle);
        exit(1);
    }
    $columns = array_map(static fn ($key): string => nat_import_normalize_key((string) $key), $header);
    while (($data = fgetcsv($handle)) !== false) {
        $assoc = [];
        foreach ($columns as $index => $column) {
            $assoc[$column] = $data[$index] ?? '';
        }
        $rows[] = nat_import_row_from_csv($assoc, $nodeName);
    }
} else {
    while (($line = fgets($handle)) !== false) {
        $row = nat_import_row_from_line($line, $nodeName);
        if ($row !== null) {
            $rows[] = $row;
        } else {
            $result['processed']++;
            $result['invalid']++;
        }
    }
}
fclose($handle);

foreach ($rows as $row) {
    $result['processed']++;
    if (!nat_import_valid_row($row)) {
        $result['invalid']++;
        continue;
    }

    $result['valid']++;
    $row['message_hash'] = nat_import_row_hash($row);
    if (!$apply) {
        continue;
    }

    $insert->execute([
        'logged_at' => $row['logged_at'],
        'node_name' => $row['node_name'] !== '' ? $row['node_name'] : $nodeName,
        'protocol' => $row['protocol'],
        'source_ip' => $row['source_ip'],
        'source_port' => $row['source_port'],
        'nat_ip' => $row['nat_ip'],
        'nat_port' => $row['nat_port'],
        'destination_ip' => $row['destination_ip'],
        'destination_port' => $row['destination_port'],
        'username' => $row['username'] !== '' ? $row['username'] : null,
        'subscriber_code' => $row['subscriber_code'] !== '' ? $row['subscriber_code'] : null,
        'raw_message' => $row['raw_message'],
        'message_hash' => $row['message_hash'],
    ]);

    if ($insert->rowCount() > 0) {
        $result['inserted']++;
    } else {
        $result['duplicates']++;
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
