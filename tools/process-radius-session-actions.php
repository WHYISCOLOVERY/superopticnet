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

/**
 * $packetType: 'disconnect' | 'coa' — radclient-in öz paket-tipi açar sözü (FreeRADIUS radclient
 * "disconnect" və "coa" hər ikisini dəstəkləyir).
 */
function radius_worker_run_radclient(string $binary, string $target, string $secret, string $attributes, string $packetType = 'disconnect'): array
{
    $secretFile = tempnam(sys_get_temp_dir(), 'radius-secret-');
    if ($secretFile === false || file_put_contents($secretFile, $secret) === false) {
        return [
            'ok' => false,
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'temporary_secret_file_failed',
        ];
    }
    @chmod($secretFile, 0600);
    $command = [$binary, '-x', '-S', $secretFile, $target, $packetType];
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    try {
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
    } finally {
        @unlink($secretFile);
    }

    $combined = (string) $stdout . (string) $stderr;
    $ackWord = $packetType === 'coa' ? 'CoA-ACK' : 'Disconnect-ACK';
    $nakWord = $packetType === 'coa' ? 'CoA-NAK' : 'Disconnect-NAK';
    $responseStatus = str_contains($combined, $ackWord)
        ? $ackWord
        : (str_contains($combined, $nakWord) ? $nakWord : '');

    return [
        'ok' => $exitCode === 0 && $responseStatus === $ackWord,
        'exit_code' => $exitCode,
        'stdout' => (string) $stdout,
        'stderr' => (string) $stderr,
        'radius_response' => $responseStatus,
        'response_verified' => $responseStatus !== '',
    ];
}

function radius_worker_secret_for_target(PDO $db, string $targetHost, array $settings): array
{
    $targetHost = trim($targetHost);
    if ($targetHost !== '') {
        try {
            $statement = $db->prepare(
                'SELECT radius_secret_env
                 FROM router_registry
                 WHERE is_active = 1 AND (management_ip = :target OR nas_identity = :target)
                 ORDER BY id ASC
                 LIMIT 1'
            );
            $statement->execute(['target' => $targetHost]);
            $reference = trim((string) $statement->fetchColumn());
            if ($reference !== '') {
                if (preg_match('/^ROUTER_[A-Z0-9]+(?:_[A-Z0-9]+)*_RADIUS_SECRET$/D', $reference) !== 1) {
                    return ['ok' => false, 'secret' => '', 'reference' => $reference, 'reason' => 'invalid_router_secret_reference'];
                }
                $secret = trim((string) getenv($reference));
                return $secret !== ''
                    ? ['ok' => true, 'secret' => $secret, 'reference' => $reference, 'reason' => '']
                    : ['ok' => false, 'secret' => '', 'reference' => $reference, 'reason' => 'router_secret_environment_missing'];
            }
        } catch (Throwable) {
            return ['ok' => false, 'secret' => '', 'reference' => '', 'reason' => 'router_registry_unavailable'];
        }
    }

    $defaultHost = trim((string) ($settings['router_ip'] ?? ''));
    $defaultSecret = trim((string) ($settings['shared_secret'] ?? ''));
    if ($targetHost !== '' && $targetHost === $defaultHost && $defaultSecret !== '') {
        return ['ok' => true, 'secret' => $defaultSecret, 'reference' => 'RADIUS_SHARED_SECRET', 'reason' => ''];
    }

    return ['ok' => false, 'secret' => '', 'reference' => '', 'reason' => 'router_secret_reference_missing'];
}

/**
 * Sətir-sətir "Name = value" cütlüklərini SİYAHI kimi qaytarır (assoc map DEYİL) — çünki CoA-da
 * eyni adlı atribut (Cisco-AVPair) TƏKRAR göndərilə bilər (in + out istiqamətləri üçün ayrı-ayrı);
 * assoc map istifadə etsəydik ikincisi birincini üstələyərdi.
 * @return array<int, array{name: string, value: string}>
 */
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
        $rows[] = ['name' => $name, 'value' => $value];
    }

    return $rows;
}

/** Parse edilmiş siyahıdan verilmiş ada uyğun İLK dəyəri tapır (təkrarsız atributlar üçün). */
function radius_worker_first_attribute_value(array $parsed, string $name): string
{
    foreach ($parsed as $pair) {
        if (($pair['name'] ?? '') === $name) {
            return trim((string) ($pair['value'] ?? ''));
        }
    }
    return '';
}

function radius_worker_encode_attribute(int $type, string $value, bool $ip = false): string
{
    $payload = $ip ? @inet_pton($value) : $value;
    if (!is_string($payload) || $payload === '') {
        return '';
    }

    return chr($type) . chr(strlen($payload) + 2) . $payload;
}

/**
 * Cisco Vendor-Specific Attribute (RADIUS type 26, Vendor-Id 9 = Cisco, Vendor-Type 1 = Cisco-AVPair).
 * RFC 2865 §5.26 formatı: Type(1) Length(1) Vendor-Id(4) [Vendor-Type(1) Vendor-Length(1) Data...]
 */
function radius_worker_encode_cisco_avpair(string $value): string
{
    if ($value === '' || strlen($value) > 247) {
        return '';
    }
    $vendorType = 1;
    $vendorLength = 2 + strlen($value);
    $vendorPortion = chr($vendorType) . chr($vendorLength) . $value;
    $vendorId = pack('N', 9);
    $outerLength = 2 + 4 + strlen($vendorPortion);

    return chr(26) . chr($outerLength) . $vendorId . $vendorPortion;
}

const RADIUS_WORKER_SESSION_ATTRIBUTE_MAP = [
    'User-Name' => [1, false],
    'NAS-IP-Address' => [4, true],
    'Framed-IP-Address' => [8, true],
    'Calling-Station-Id' => [31, false],
    'NAS-Identifier' => [32, false],
    'Called-Station-Id' => [30, false],
    'Acct-Session-Id' => [44, false],
    'NAS-Port-Id' => [87, false],
];

function radius_worker_disconnect_packet(string $secret, string $attributes, int $identifier): array
{
    $parsed = radius_worker_parse_attributes($attributes);

    $encoded = '';
    foreach (RADIUS_WORKER_SESSION_ATTRIBUTE_MAP as $name => [$type, $isIp]) {
        $value = radius_worker_first_attribute_value($parsed, $name);
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
        'parsed_attributes' => array_values(array_unique(array_column($parsed, 'name'))),
    ];
}

/**
 * CoA-Request (RFC 5176, kod 43) — Disconnect-Request-dən FƏRQLİ olaraq sessiyanı KƏSMİR, yalnız
 * göndərilən atributları (burada: Cisco-AVPair ilə QoS policy-map) canlı sessiyaya tətbiq edir.
 * Eyni sessiya-eynilik atributları istifadə olunur (User-Name/Framed-IP və s.) + bütün Cisco-AVPair
 * təkrarları (in + out istiqamətləri üçün) VSA kimi əlavə olunur.
 */
function radius_worker_coa_packet(string $secret, string $attributes, int $identifier): array
{
    $parsed = radius_worker_parse_attributes($attributes);

    $encoded = '';
    foreach (RADIUS_WORKER_SESSION_ATTRIBUTE_MAP as $name => [$type, $isIp]) {
        $value = radius_worker_first_attribute_value($parsed, $name);
        if ($value === '') {
            continue;
        }
        $encoded .= radius_worker_encode_attribute((int) $type, $value, (bool) $isIp);
    }
    foreach ($parsed as $pair) {
        if (($pair['name'] ?? '') !== 'Cisco-AVPair') {
            continue;
        }
        $value = trim((string) ($pair['value'] ?? ''));
        if ($value === '') {
            continue;
        }
        $encoded .= radius_worker_encode_cisco_avpair($value);
    }

    $code = 43; // CoA-Request
    $length = 20 + strlen($encoded);
    $headerWithZeroAuth = chr($code) . chr($identifier) . pack('n', $length) . str_repeat("\0", 16);
    $authenticator = md5($headerWithZeroAuth . $encoded . $secret, true);

    return [
        'packet' => chr($code) . chr($identifier) . pack('n', $length) . $authenticator . $encoded,
        'authenticator' => $authenticator,
        'encoded_attributes' => strlen($encoded),
        'parsed_attributes' => array_values(array_unique(array_column($parsed, 'name'))),
    ];
}

/**
 * $packetType: 'disconnect' | 'coa'. radclient tapılmadıqda istifadə olunan xam UDP fallback-dır.
 */
function radius_worker_run_php_udp(string $target, string $secret, string $attributes, string $packetType = 'disconnect', int $timeout = 5): array
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
    $packet = $packetType === 'coa'
        ? radius_worker_coa_packet($secret, $attributes, $identifier)
        : radius_worker_disconnect_packet($secret, $attributes, $identifier);
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

    $requestLabel = $packetType === 'coa' ? 'CoA-Request' : 'Disconnect-Request';
    if (!is_string($response) || $response === '') {
        return [
            'ok' => false,
            'exit_code' => 2,
            'stdout' => $requestLabel . ' sent via php_udp; no response',
            'stderr' => !empty($meta['timed_out']) ? 'timeout_waiting_for_radius_response' : 'empty_radius_response',
        ];
    }

    if (strlen($response) < 20) {
        return [
            'ok' => false,
            'exit_code' => 3,
            'stdout' => 'Malformed RADIUS response received via php_udp',
            'stderr' => 'radius_response_too_short',
            'radius_response' => '',
            'response_verified' => false,
        ];
    }

    $code = ord($response[0]);
    $receivedIdentifier = ord($response[1] ?? "\0");
    $declaredLength = unpack('nlength', substr($response, 2, 2));
    $responseLength = (int) ($declaredLength['length'] ?? 0);
    if ($responseLength < 20 || $responseLength > strlen($response)) {
        return [
            'ok' => false,
            'exit_code' => 3,
            'stdout' => 'Malformed RADIUS response received via php_udp',
            'stderr' => 'radius_response_length_invalid',
            'radius_response' => '',
            'response_verified' => false,
        ];
    }
    $response = substr($response, 0, $responseLength);
    $responseAuthenticator = substr($response, 4, 16);
    $responseAttributes = substr($response, 20);
    $expectedAuthenticator = md5(
        substr($response, 0, 4) . (string) $packet['authenticator'] . $responseAttributes . $secret,
        true
    );
    $responseVerified = hash_equals($expectedAuthenticator, $responseAuthenticator);

    $ackCode = $packetType === 'coa' ? 44 : 41;
    $nakCode = $packetType === 'coa' ? 45 : 42;
    $ackLabel = $packetType === 'coa' ? 'CoA-ACK' : 'Disconnect-ACK';
    $nakLabel = $packetType === 'coa' ? 'CoA-NAK' : 'Disconnect-NAK';
    $status = match ($code) {
        $ackCode => $ackLabel,
        $nakCode => $nakLabel,
        default => 'Radius-Code-' . $code,
    };

    return [
        'ok' => $code === $ackCode && $receivedIdentifier === $identifier && $responseVerified,
        'exit_code' => $code === $ackCode ? 0 : 3,
        'stdout' => $status . ' received via php_udp identifier=' . $receivedIdentifier,
        'stderr' => match (true) {
            $receivedIdentifier !== $identifier => 'radius_identifier_mismatch',
            !$responseVerified => 'radius_response_authenticator_invalid',
            default => '',
        },
        'radius_response' => $status,
        'response_verified' => $responseVerified && $receivedIdentifier === $identifier,
    ];
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}

$settings = app_radius_settings();

$idFilter = max(0, (int) radius_worker_option('id', $argv, 0));
$limit = max(1, min(50, (int) radius_worker_option('limit', $argv, 5)));
$dryRun = (bool) radius_worker_option('dry-run', $argv, false);
$autoApprove = (bool) radius_worker_option('auto-approve', $argv, false);
$emitJson = (bool) radius_worker_option('json', $argv, false);
$actionTypeFilter = trim((string) radius_worker_option('action-type', $argv, ''));

$radclient = null;
$runner = 'preview';
if (!$dryRun) {
    $radclient = radius_worker_find_radclient();
    $runner = $radclient !== null ? basename((string) $radclient) : 'php_udp';
}

$statusFilter = $dryRun || $autoApprove ? 'pending' : 'approved';
$knownActionTypes = ['disconnect_request', 'coa_qos_change'];
if (in_array($actionTypeFilter, $knownActionTypes, true)) {
    $actionTypesSql = '"' . $actionTypeFilter . '"';
} else {
    $actionTypesSql = implode(',', array_map(static fn (string $t): string => '"' . $t . '"', $knownActionTypes));
}
$sql = 'SELECT * FROM radius_session_actions WHERE action_type IN (' . $actionTypesSql . ') AND status = "' . $statusFilter . '"';
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
        'default_router_ip' => (string) ($settings['router_ip'] ?? ''),
        'coa_port' => (int) ($settings['coa_port'] ?? 3799),
        'server_ip' => (string) ($settings['server_ip'] ?? ''),
        'dynamic_author_client_ip' => (string) ($settings['dynamic_author_client_ip'] ?? ''),
    ],
    'actions' => [],
];

foreach ($rows as $row) {
    $actionType = (string) ($row['action_type'] ?? 'disconnect_request');
    $packetType = $actionType === 'coa_qos_change' ? 'coa' : 'disconnect';
    $payload = $actionType === 'coa_qos_change'
        ? app_radius_coa_qos_payload($row, $settings)
        : app_radius_disconnect_payload($row, $settings);

    $actionResult = [
        'id' => (int) ($row['id'] ?? 0),
        'action_type' => $actionType,
        'username' => (string) ($row['username'] ?? ''),
        'payload' => $payload,
    ];

    if ($actionType === 'coa_qos_change' && trim((string) ($payload['policy_map_name'] ?? '')) === '') {
        $actionResult['status'] = 'skipped';
        $actionResult['reason'] = 'missing_or_invalid_policy_map_name';
        $results['actions'][] = $actionResult;
        continue;
    }

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

    $secretResolution = radius_worker_secret_for_target(
        $db,
        (string) ($payload['target_host'] ?? ''),
        $settings
    );
    if (empty($secretResolution['ok'])) {
        $resultJson = [
            'runner' => $runner,
            'target' => (string) $payload['target'],
            'username' => (string) ($row['username'] ?? ''),
            'requested_at' => (string) ($row['requested_at'] ?? ''),
            'stdout' => '',
            'stderr' => (string) ($secretResolution['reason'] ?? 'router_secret_unavailable'),
            'exit_code' => 1,
            'secret_reference' => (string) ($secretResolution['reference'] ?? ''),
            'radius_response' => '',
            'response_verified' => false,
        ];
        $db->prepare(
            'UPDATE radius_session_actions SET status = "rejected", result_json = :result_json WHERE id = :id'
        )->execute([
            'result_json' => app_db_json_encode($resultJson),
            'id' => (int) $row['id'],
        ]);
        $actionResult['status'] = 'rejected';
        $actionResult['result'] = $resultJson;
        $results['actions'][] = $actionResult;
        continue;
    }

    $runnerResult = $radclient !== null
        ? radius_worker_run_radclient((string) $radclient, (string) $payload['target'], (string) $secretResolution['secret'], (string) $payload['attributes'], $packetType)
        : radius_worker_run_php_udp((string) $payload['target'], (string) $secretResolution['secret'], (string) $payload['attributes'], $packetType);
    $finalStatus = !empty($runnerResult['ok']) ? 'executed' : 'rejected';
    $resultJson = [
        'runner' => $runner,
        'action_type' => $actionType,
        'target' => (string) $payload['target'],
        'username' => (string) ($row['username'] ?? ''),
        'requested_at' => (string) ($row['requested_at'] ?? ''),
        'stdout' => (string) ($runnerResult['stdout'] ?? ''),
        'stderr' => (string) ($runnerResult['stderr'] ?? ''),
        'exit_code' => (int) ($runnerResult['exit_code'] ?? 1),
        'secret_reference' => (string) ($secretResolution['reference'] ?? ''),
        'radius_response' => (string) ($runnerResult['radius_response'] ?? ''),
        'response_verified' => !empty($runnerResult['response_verified']),
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
