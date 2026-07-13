<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/clickhouse-client.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function law_arg(string $name, string $default = ''): string
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

function law_format(string $format): string
{
    return match (strtolower($format)) {
        'json', 'jsonl', 'jsoneachrow' => 'JSONEachRow',
        'parquet' => 'Parquet',
        default => 'CSVWithNames',
    };
}

function law_extension(string $format): string
{
    return match (strtolower($format)) {
        'json', 'jsonl', 'jsoneachrow' => 'jsonl',
        'parquet' => 'parquet',
        default => 'csv',
    };
}

$requester = trim(law_arg('requester'));
$reason = trim(law_arg('reason'));
$approvedBy = trim(law_arg('approved-by'));
$format = strtolower(trim(law_arg('format', 'csv')));
$timeFrom = trim(law_arg('from', gmdate('Y-m-d H:i:s', time() - 3600)));
$timeTo = trim(law_arg('to', gmdate('Y-m-d H:i:s')));
$sourceIp = trim(law_arg('source-ip'));
$natIp = trim(law_arg('nat-ip'));
$destinationIp = trim(law_arg('destination-ip'));
$subscriberCode = trim(law_arg('subscriber-code'));
$limit = max(1, min(1000000, (int) law_arg('limit', '100000')));
$dryRun = law_arg('dry-run') === '1';

$errors = [];
if ($requester === '') {
    $errors[] = 'requester_required';
}
if ($reason === '') {
    $errors[] = 'reason_required';
}
if (!$dryRun && $approvedBy === '') {
    $errors[] = 'approval_required';
}
if (!in_array($format, ['csv', 'json', 'jsonl', 'parquet'], true)) {
    $errors[] = 'unsupported_format';
}

$filters = [
    'logged_at >= parseDateTimeBestEffort(' . app_clickhouse_quote($timeFrom) . ')',
    'logged_at <= parseDateTimeBestEffort(' . app_clickhouse_quote($timeTo) . ')',
];
if ($sourceIp !== '') {
    $filters[] = 'source_ip = toIPv4(' . app_clickhouse_quote($sourceIp) . ')';
}
if ($natIp !== '') {
    $filters[] = 'nat_ip = toIPv4(' . app_clickhouse_quote($natIp) . ')';
}
if ($destinationIp !== '') {
    $filters[] = 'destination_ip = toIPv4(' . app_clickhouse_quote($destinationIp) . ')';
}
if ($subscriberCode !== '') {
    $filters[] = 'subscriber_code = ' . app_clickhouse_quote($subscriberCode);
}

$where = implode(' AND ', $filters);
$outputFormat = law_format($format);
$query = 'SELECT logged_at, node_name, protocol, source_ip, source_port, nat_ip, nat_port,
                 destination_ip, destination_port, username, subscriber_code, message_hash
            FROM superoptic_analytics.radius_nat_logs
           WHERE ' . $where . '
           ORDER BY logged_at ASC
           LIMIT ' . $limit . '
          FORMAT ' . $outputFormat;

if ($errors !== []) {
    echo json_encode(['status' => 'failed', 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(2);
}

$result = [
    'status' => 'ok',
    'mode' => $dryRun ? 'dry-run' : 'apply',
    'requester' => $requester,
    'reason' => $reason,
    'approved_by' => $approvedBy,
    'format' => $format,
    'query_scope' => [
        'from' => $timeFrom,
        'to' => $timeTo,
        'source_ip' => $sourceIp,
        'nat_ip' => $natIp,
        'destination_ip' => $destinationIp,
        'subscriber_code' => $subscriberCode,
        'limit' => $limit,
    ],
    'output_path' => '',
    'row_count_estimate' => null,
    'export_hash' => '',
    'audit_recorded' => false,
];

if ($dryRun) {
    $result['query'] = $query;
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

$response = app_clickhouse_request($query);
if (!$response['ok']) {
    $result['status'] = 'failed';
    $result['error'] = $response['error'] ?: ('http_' . $response['status']);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(3);
}

$dir = dirname(__DIR__) . '/storage/law-exports/' . gmdate('Y/m');
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$path = $dir . '/nat-export-' . gmdate('Ymd-His') . '-' . substr(hash('sha256', $requester . $reason . $timeFrom . $timeTo), 0, 10) . '.' . law_extension($format);
file_put_contents($path, $response['body']);
$hash = hash_file('sha256', $path) ?: hash('sha256', $response['body']);
$rowCount = $format === 'csv'
    ? max(0, substr_count((string) $response['body'], "\n") - 1)
    : max(0, substr_count(trim((string) $response['body']), "\n") + (trim((string) $response['body']) !== '' ? 1 : 0));

$auditRow = [
    'requester' => $requester,
    'reason' => $reason,
    'approval_status' => 'approved',
    'approved_by' => $approvedBy,
    'approved_at' => gmdate('Y-m-d H:i:s.000'),
    'query_scope' => json_encode($result['query_scope'], JSON_UNESCAPED_SLASHES),
    'export_format' => $format,
    'output_path' => $path,
    'row_count' => $rowCount,
    'export_hash' => $hash,
    'immutable_marker' => hash('sha256', $requester . '|' . $reason . '|' . $path . '|' . $hash),
];
$audit = app_clickhouse_request(
    'INSERT INTO superoptic_analytics.law_export_requests FORMAT JSONEachRow',
    app_clickhouse_json_each_row([$auditRow])
);

$result['output_path'] = $path;
$result['row_count_estimate'] = $rowCount;
$result['export_hash'] = $hash;
$result['audit_recorded'] = (bool) $audit['ok'];
if (!$audit['ok']) {
    $result['audit_error'] = $audit['error'] ?: ('http_' . $audit['status']);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['audit_recorded'] ? 0 : 4);
