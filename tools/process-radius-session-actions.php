<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function radius_worker_option(string $name, array $argv, mixed $default = null): mixed
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
        if ($arg === '--' . $name) {
            return true;
        }
    }

    return $default;
}

function radius_worker_find_radclient(): ?string
{
    $candidates = [];
    if (DIRECTORY_SEPARATOR === '\\') {
        $output = [];
        $code = 0;
        @exec('where.exe radclient 2>NUL', $output, $code);
        if ($code === 0) {
            foreach ($output as $line) {
                $line = trim((string) $line);
                if ($line !== '' && is_file($line)) {
                    $candidates[] = $line;
                }
            }
        }
    } else {
        $output = [];
        $code = 0;
        @exec('command -v radclient 2>/dev/null', $output, $code);
        if ($code === 0) {
            foreach ($output as $line) {
                $line = trim((string) $line);
                if ($line !== '' && is_file($line)) {
                    $candidates[] = $line;
                }
            }
        }
    }

    return $candidates[0] ?? null;
}

function radius_worker_run_radclient(string $binary, string $target, string $secret, string $attributes): array
{
    $command = [$binary, '-x', $target, 'disconnect', $secret];
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return [
            'ok' => false,
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'proc_open_failed',
        ];
    }

    fwrite($pipes[0], $attributes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'ok' => $exitCode === 0 && str_contains($stdout . $stderr, 'Disconnect-ACK'),
        'exit_code' => $exitCode,
        'stdout' => (string) $stdout,
        'stderr' => (string) $stderr,
    ];
}

function radius_worker_parse_attributes(string $attributes): array
{
    $rows = [];
    foreach (preg_split('/\R+/', trim($attributes)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
            $value = stripcslashes(substr($value, 1, -1));
        }
        $rows[$name] = $value;
    }

    return $rows;
}

function radius_worker_encode_attribute(int $type, string $value, bool $ip = false): string
{
    $payload = $ip ? @inet_pton($value) : $value;
    if (!is_string($payload) || $payload === '') {
        return '';
    }

    return chr($type) . chr(strlen($payload) + 2) . $payload;
}

function radius_worker_disconnect_packet(string $secret, string $attributes, int $identifier): array
{
    $parsed = radius_worker_parse_attributes($attributes);
    $attributeMap = [
        'User-Name' => [1, false],
        'NAS-IP-Address' => [4, true],
        'Framed-IP-Address' => [8, true],
        'Calling-Station-Id' => [31, false],
        'NAS-Identifier' => [32, false],
        'Called-Station-Id' => [30, false],
        'Acct-Session-Id' => [44, false],
        'NAS-Port-Id' => [87, false],
    ];

    $encoded = '';
    foreach ($attributeMap as $name => [$type, $isIp]) {
        $value = trim((string) ($parsed[$name] ?? ''));
        if ($value === '') {
            continue;
        }
        $encoded .= radius_worker_encode_attribute((int) $type, $value, (bool) $isIp);
    }

    $code = 40; // Disconnect-Request
    $length = 20 + strlen($encoded);
    $headerWithZeroAuth = chr($code) . chr($identifier) . pack('n', $length) . str_repeat("\0", 16);
    $authenticator = md5($headerWithZeroAuth . $encoded . $secret, true);

    return [
        'packet' => chr($code) . chr($identifier) . pack('n', $length) . $authenticator . $encoded,
        'authenticator' => $authenticator,
        'encoded_attributes' => strlen($encoded),
        'parsed_attributes' => array_keys($parsed),
    ];
}

function radius_worker_run_php_udp(string $target, string $secret, string $attributes, int $timeout = 5): array
{
    if ($secret === '') {
        return [
            'ok' => false,
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'missing_shared_secret',
        ];
    }

    [$host, $portText] = array_pad(explode(':', $target, 2), 2, '3799');
    $host = trim($host);
    $port = max(1, (int) $portText);
    if ($host === '') {
        return [
            'ok' => false,
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'missing_target_host',
        ];
    }

    $identifier = random_int(0, 255);
    $packet = radius_worker_disconnect_packet($secret, $attributes, $identifier);
    if ((int) ($packet['encoded_attributes'] ?? 0) <= 0) {
        return [
            'ok' => false,
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'no_radius_attributes_encoded',
        ];
    }

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client('udp://' . $host . ':' . $port, $errno, $errstr, $timeout);
    if (!is_resource($socket)) {
        return [
            'ok' => false,
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'udp_socket_failed:' . $errstr,
        ];
    }

    stream_set_timeout($socket, $timeout);
    fwrite($socket, (string) $packet['packet']);
    $response = fread($socket, 4096);
    $meta = stream_get_meta_data($socket);
    fclose($socket);

    if (!is_string($response) || $response === '') {
        return [
            'ok' => false,
            'exit_code' => 2,
            'stdout' => 'Disconnect-Request sent via php_udp; no response',
            'stderr' => !empty($meta['timed_out']) ? 'timeout_waiting_for_radius_response' : 'empty_radius_response',
        ];
    }

    $code = ord($response[0] ?? "\0");
    $receivedIdentifier = ord($response[1] ?? "\0");
    $status = match ($code) {
        41 => 'Disconnect-ACK',
        42 => 'Disconnect-NAK',
        default => 'Radius-Code-' . $code,
    };

    return [
        'ok' => $code === 41 && $receivedIdentifier === $identifier,
        'exit_code' => $code === 41 ? 0 : 3,
        'stdout' => $status . ' received via php_udp identifier=' . $receivedIdentifier,
        'stderr' => $receivedIdentifier !== $identifier ? 'radius_identifier_mismatch' : '',
    ];
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}

$settings = app_radius_settings();
$readiness = app_radius_disconnect_worker_readiness($settings);

$idFilter = max(0, (int) radius_worker_option('id', $argv, 0));
$limit = max(1, min(50, (int) radius_worker_option('limit', $argv, 5)));
$dryRun = (bool) radius_worker_option('dry-run', $argv, false);
$autoApprove = (bool) radius_worker_option('auto-approve', $argv, false);
$emitJson = (bool) radius_worker_option('json', $argv, false);

if (!$dryRun && empty($readiness['ok'])) {
    fwrite(STDERR, 'worker_not_ready:' . (string) ($readiness['reason'] ?? 'unknown') . PHP_EOL);
    exit(3);
}

$radclient = null;
$runner = 'preview';
if (!$dryRun) {
    $radclient = radius_worker_find_radclient();
    $runner = $radclient !== null ? basename((string) $radclient) : 'php_udp';
}

$statusFilter = $dryRun || $autoApprove ? 'pending' : 'approved';
$sql = 'SELECT * FROM radius_session_actions WHERE action_type = "disconnect_request" AND status = "' . $statusFilter . '"';
$params = [];
if ($idFilter > 0) {
    $sql .= ' AND id = :id';
    $params['id'] = $idFilter;
}
$sql .= ' ORDER BY requested_at ASC, id ASC LIMIT ' . $limit;

$statement = $db->prepare($sql);
$statement->execute($params);
$rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$results = [
    'dry_run' => $dryRun,
    'auto_approve' => $autoApprove,
    'runner' => $dryRun ? 'preview' : $runner,
    'settings' => [
        'router_ip' => (string) ($settings['router_ip'] ?? ''),
        'coa_port' => (int) ($settings['coa_port'] ?? 3799),
        'server_ip' => (string) ($settings['server_ip'] ?? ''),
        'dynamic_author_client_ip' => (string) ($settings['dynamic_author_client_ip'] ?? ''),
    ],
    'actions' => [],
];

foreach ($rows as $row) {
    $payload = app_radius_disconnect_payload($row, $settings);
    $actionResult = [
        'id' => (int) ($row['id'] ?? 0),
        'username' => (string) ($row['username'] ?? ''),
        'payload' => $payload,
    ];

    if ($dryRun) {
        $actionResult['status'] = 'preview';
        $results['actions'][] = $actionResult;
        continue;
    }

    if ($autoApprove) {
        $claim = $db->prepare(
            'UPDATE radius_session_actions
             SET status = "approved", approved_at = NOW()
             WHERE id = :id AND status = "pending"'
        );
        $claim->execute(['id' => (int) $row['id']]);
        if ($claim->rowCount() < 1) {
            $actionResult['status'] = 'skipped';
            $actionResult['reason'] = 'claim_failed';
            $results['actions'][] = $actionResult;
            continue;
        }
    }

    $runnerResult = $radclient !== null
        ? radius_worker_run_radclient((string) $radclient, (string) $payload['target'], (string) ($settings['shared_secret'] ?? ''), (string) $payload['attributes'])
        : radius_worker_run_php_udp((string) $payload['target'], (string) ($settings['shared_secret'] ?? ''), (string) $payload['attributes']);
    $finalStatus = !empty($runnerResult['ok']) ? 'executed' : 'rejected';
    $resultJson = [
        'runner' => $runner,
        'target' => (string) $payload['target'],
        'username' => (string) ($row['username'] ?? ''),
        'requested_at' => (string) ($row['requested_at'] ?? ''),
        'stdout' => (string) ($runnerResult['stdout'] ?? ''),
        'stderr' => (string) ($runnerResult['stderr'] ?? ''),
        'exit_code' => (int) ($runnerResult['exit_code'] ?? 1),
    ];

    $update = $db->prepare(
        'UPDATE radius_session_actions
         SET status = :status, result_json = :result_json
         WHERE id = :id'
    );
    $update->execute([
        'status' => $finalStatus,
        'result_json' => app_db_json_encode($resultJson),
        'id' => (int) $row['id'],
    ]);

    $actionResult['status'] = $finalStatus;
    $actionResult['result'] = $resultJson;
    $results['actions'][] = $actionResult;
}

if ($emitJson) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo 'Processed ' . count($results['actions']) . ' action(s).' . PHP_EOL;
}
