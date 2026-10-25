<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

function nel_arg(string $name, string $default = ''): string
{
    $prefix = '--' . $name . '=';
    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
        if ($argument === '--' . $name) {
            return '1';
        }
        if (str_starts_with((string) $argument, $prefix)) {
            return substr((string) $argument, strlen($prefix));
        }
    }
    return $default;
}

function nel_ipv4(string $value, string $field, array &$errors): string
{
    $value = trim($value);
    if ($value !== '' && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        $errors[] = $field . '_invalid';
    }
    return $value;
}

function nel_port(string $value, string $field, array &$errors): ?int
{
    if (trim($value) === '') {
        return null;
    }
    $port = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if ($port === false) {
        $errors[] = $field . '_invalid';
        return null;
    }
    return (int) $port;
}

function nel_time(string $value, string $field, DateTimeZone $zone, array &$errors): ?DateTimeImmutable
{
    try {
        return new DateTimeImmutable($value, $zone);
    } catch (Throwable) {
        $errors[] = $field . '_invalid';
        return null;
    }
}

$root = rtrim(nel_arg('root', '/var/log/superoptic/netflow'), '/');
$requester = trim(nel_arg('requester'));
$reason = trim(nel_arg('reason'));
$approvedBy = trim(nel_arg('approved-by'));
$dryRun = nel_arg('dry-run') === '1';
$limit = max(1, min(1_000_000, (int) nel_arg('limit', '100000')));
$zone = new DateTimeZone('Asia/Baku');
$now = new DateTimeImmutable('now', $zone);
$errors = [];

$from = nel_time(nel_arg('from', $now->modify('-1 hour')->format('Y-m-d H:i:s')), 'from', $zone, $errors);
$to = nel_time(nel_arg('to', $now->format('Y-m-d H:i:s')), 'to', $zone, $errors);
$privateIp = nel_ipv4(nel_arg('private-ip'), 'private_ip', $errors);
$publicIp = nel_ipv4(nel_arg('public-ip'), 'public_ip', $errors);
$destinationIp = nel_ipv4(nel_arg('destination-ip'), 'destination_ip', $errors);
$privatePort = nel_port(nel_arg('private-port'), 'private_port', $errors);
$publicPort = nel_port(nel_arg('public-port'), 'public_port', $errors);
$destinationPort = nel_port(nel_arg('destination-port'), 'destination_port', $errors);
$event = strtolower(trim(nel_arg('event')));

if ($requester === '') {
    $errors[] = 'requester_required';
}
if ($reason === '') {
    $errors[] = 'reason_required';
}
if (!$dryRun && $approvedBy === '') {
    $errors[] = 'approval_required';
}
if (!is_dir($root)) {
    $errors[] = 'flow_root_unavailable';
}
if ($from !== null && $to !== null && ($to <= $from || $to->getTimestamp() - $from->getTimestamp() > 31 * 86400)) {
    $errors[] = 'time_window_invalid_or_over_31_days';
}
if ($event !== '' && !in_array($event, ['add', 'delete'], true)) {
    $errors[] = 'event_invalid';
}

$filters = [];
if ($privateIp !== '') {
    $filters[] = 'src ip ' . $privateIp;
}
if ($publicIp !== '') {
    $filters[] = 'src nip ' . $publicIp;
}
if ($destinationIp !== '') {
    $filters[] = 'dst ip ' . $destinationIp;
}
if ($privatePort !== null) {
    $filters[] = 'src port ' . $privatePort;
}
if ($publicPort !== null) {
    $filters[] = 'src nport ' . $publicPort;
}
if ($destinationPort !== null) {
    $filters[] = 'dst port ' . $destinationPort;
}
if ($event !== '') {
    $filters[] = 'nat event ' . $event;
}
if ($filters === []) {
    $errors[] = 'at_least_one_ip_port_or_event_filter_required';
}

if ($errors !== []) {
    echo json_encode(['status' => 'failed', 'errors' => array_values(array_unique($errors))], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(2);
}

$timeWindow = $from->format('Y/m/d.H:i:s') . '-' . $to->format('Y/m/d.H:i:s');
$filter = implode(' and ', array_map(static fn (string $item): string => '(' . $item . ')', $filters));
$format = 'fmt:%ts,%pr,%sa,%sp,%da,%dp,%nsa,%nsp,%nda,%ndp,%nevt';
$command = ['/usr/bin/nfdump', '-r', $root, '-q', '-t', $timeWindow, '-o', $format, '-c', (string) $limit, $filter];
$scope = [
    'from' => $from->format(DateTimeInterface::ATOM),
    'to' => $to->format(DateTimeInterface::ATOM),
    'private_ip' => $privateIp,
    'public_ip' => $publicIp,
    'destination_ip' => $destinationIp,
    'private_port' => $privatePort,
    'public_port' => $publicPort,
    'destination_port' => $destinationPort,
    'event' => $event,
    'limit' => $limit,
];

if ($dryRun) {
    echo json_encode([
        'status' => 'ok',
        'mode' => 'dry-run',
        'requester' => $requester,
        'reason' => $reason,
        'scope' => $scope,
        'filter' => $filter,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

$descriptorSpec = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open($command, $descriptorSpec, $pipes, null, ['LC_ALL' => 'C'], ['bypass_shell' => true]);
if (!is_resource($process)) {
    fwrite(STDERR, "nfdump_start_failed\n");
    exit(3);
}
$body = stream_get_contents($pipes[1]);
$errorOutput = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
if ($exitCode !== 0) {
    echo json_encode(['status' => 'failed', 'error' => 'nfdump_failed', 'detail' => trim((string) $errorOutput)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(4);
}

$exportDir = dirname(__DIR__) . '/storage/law-exports/' . $now->format('Y/m');
if (!is_dir($exportDir) && !mkdir($exportDir, 0770, true) && !is_dir($exportDir)) {
    fwrite(STDERR, "export_directory_failed\n");
    exit(5);
}
$stamp = $now->format('Ymd-His');
$scopeHash = substr(hash('sha256', json_encode($scope, JSON_UNESCAPED_SLASHES) ?: ''), 0, 12);
$path = $exportDir . '/nfdump-nel-' . $stamp . '-' . $scopeHash . '.csv';
$header = "logged_at,protocol,private_ip,private_port,destination_ip,destination_port,public_ip,public_port,translated_destination_ip,translated_destination_port,event\n";
file_put_contents($path, $header . (string) $body, LOCK_EX);
$hash = hash_file('sha256', $path) ?: '';
$rowCount = substr_count(trim((string) $body), "\n") + (trim((string) $body) !== '' ? 1 : 0);

$audit = [
    'created_at' => $now->format(DateTimeInterface::ATOM),
    'requester' => $requester,
    'reason' => $reason,
    'approved_by' => $approvedBy,
    'scope' => $scope,
    'filter' => $filter,
    'output_path' => $path,
    'row_count' => $rowCount,
    'sha256' => $hash,
];
$auditPath = dirname(__DIR__) . '/storage/law-exports/nfdump-audit.jsonl';
file_put_contents($auditPath, (json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') . "\n", FILE_APPEND | LOCK_EX);

echo json_encode([
    'status' => 'ok',
    'output_path' => $path,
    'row_count' => $rowCount,
    'sha256' => $hash,
    'audit_path' => $auditPath,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
