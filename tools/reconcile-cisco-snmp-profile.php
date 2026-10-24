<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function app_cisco_snmp_source_ip(string $targetIp): ?string
{
    $socket = @stream_socket_client(
        'udp://' . $targetIp . ':161',
        $errorCode,
        $errorMessage,
        2,
        STREAM_CLIENT_CONNECT
    );
    if (!is_resource($socket)) {
        return null;
    }
    $local = (string) @stream_socket_get_name($socket, false);
    fclose($socket);
    if ($local === '') {
        return null;
    }
    $ip = preg_replace('/:\d+$/', '', $local);
    return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : null;
}

/**
 * Reconciles the encrypted SNMP read credential with an already configured
 * Cisco device. It never prints SSH/SNMP secrets and never enters config mode.
 */
function app_reconcile_cisco_snmp_device(string $deviceKey, PDO $db, array $actor): array
{
    $device = app_mgs_device_learning_profile_by_key($deviceKey, $db, $actor);
    if (!is_array($device)) {
        return ['device_key' => $deviceKey, 'ok' => false, 'error_code' => 'device_not_found'];
    }
    if (strtoupper((string) ($device['vendor'] ?? '')) !== 'CISCO') {
        return ['device_key' => $deviceKey, 'ok' => false, 'error_code' => 'unsupported_vendor'];
    }

    $profile = app_mgs_device_access_profile($device, $db, $actor, 'update_device_access');
    $username = trim((string) ($profile['ssh_username'] ?? ''));
    $password = (string) ($profile['ssh_password'] ?? '');
    if ($username === '' || $password === '') {
        return ['device_key' => $deviceKey, 'ok' => false, 'error_code' => 'missing_ssh_credentials'];
    }

    $python = app_mgs_find_python_binary();
    $helper = __DIR__ . '/_cisco-snmp-config-read.py';
    if ($python === null || !is_file($helper)) {
        return ['device_key' => $deviceKey, 'ok' => false, 'error_code' => 'helper_unavailable'];
    }

    $process = @proc_open(
        [$python, $helper, '--payload-stdin'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__)
    );
    if (!is_resource($process)) {
        return ['device_key' => $deviceKey, 'ok' => false, 'error_code' => 'helper_unavailable'];
    }
    $sourceIp = app_cisco_snmp_source_ip((string) ($device['ip_address'] ?? ''));
    if ($sourceIp === null) {
        return ['device_key' => $deviceKey, 'ok' => false, 'error_code' => 'monitor_source_ip_unavailable'];
    }
    fwrite($pipes[0], json_encode([
        'host' => (string) ($device['ip_address'] ?? ''),
        'port' => max(1, (int) ($profile['ssh_port'] ?? 22)),
        'username' => $username,
        'password' => $password,
        'timeout_ms' => 4000,
        'source_ip' => $sourceIp,
    ], JSON_UNESCAPED_SLASHES));
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $decoded = json_decode(trim($stdout), true);
    unset($stdout, $stderr, $password);
    if ($exitCode !== 0 || !is_array($decoded) || empty($decoded['ok'])) {
        return [
            'device_key' => $deviceKey,
            'ok' => false,
            'ssh_reachable' => is_array($decoded),
            'error_code' => is_array($decoded) ? (string) ($decoded['error'] ?? 'config_read_failed') : 'config_read_failed',
        ];
    }

    $entries = array_values(array_filter(
        is_array($decoded['entries'] ?? null) ? $decoded['entries'] : [],
        static fn ($entry): bool => is_array($entry) && trim((string) ($entry['community'] ?? '')) !== ''
    ));
    $ip = (string) ($device['ip_address'] ?? '');
    $snmpPort = max(1, (int) ($profile['port'] ?? 161));
    $selected = null;
    $probe = ['ok' => false, 'error' => 'configured_community_not_reachable_from_source'];
    foreach ($entries as $entry) {
        $candidate = (string) $entry['community'];
        $candidateProbe = app_mgs_snmp_get_raw($ip, $candidate, $snmpPort, '1.3.6.1.2.1.1.1.0', 1600);
        if (!empty($candidateProbe['ok'])) {
            $selected = $candidate;
            $probe = $candidateProbe;
            break;
        }
        $probe = $candidateProbe;
    }

    $safeBase = [
        'device_key' => $deviceKey,
        'device_ip' => $ip,
        'ssh_reachable' => true,
        'privileged_mode' => !empty($decoded['privileged']),
        'configured_ro_candidates' => count($entries),
        'acl_bound_candidates' => count(array_filter($entries, static fn ($entry): bool => trim((string) ($entry['acl'] ?? '')) !== '')),
        'snmp_config_shape' => is_array($decoded['snmp_shape'] ?? null) ? $decoded['snmp_shape'] : [],
        'acl_statuses' => array_map(
            static fn (array $status): array => [
                'acl' => (string) ($status['acl'] ?? ''),
                'readable' => !empty($status['readable']),
                'entry_count' => (int) ($status['entry_count'] ?? 0),
                'source_permitted' => !empty($status['source_permitted']),
                'standard' => !empty($status['standard']),
                'suggested_sequence' => isset($status['suggested_sequence']) ? (int) $status['suggested_sequence'] : null,
            ],
            array_values(array_filter(
                is_array($decoded['acl_statuses'] ?? null) ? $decoded['acl_statuses'] : [],
                'is_array'
            ))
        ),
    ];
    unset($decoded, $entries);

    if ($selected === null) {
        return array_merge($safeBase, [
            'ok' => false,
            'snmp_reachable' => false,
            'error_code' => (string) ($probe['error'] ?? 'configured_community_not_reachable_from_source'),
        ]);
    }

    $stored = app_mgs_set_device_access_override($deviceKey, ['snmp_read_community' => $selected], $actor, $db);
    unset($selected);
    if (empty($stored['ok'])) {
        return array_merge($safeBase, ['ok' => false, 'snmp_reachable' => true, 'error_code' => 'vault_update_failed']);
    }
    unset($stored);

    return array_merge($safeBase, [
        'ok' => true,
        'snmp_reachable' => true,
        'snmp_version' => (int) ($probe['version'] ?? 0) === 1 ? '2c' : '1',
        'vault_updated' => true,
        'error_code' => null,
    ]);
}

$requested = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--device-key=')) {
        $requested[] = trim(substr($argument, strlen('--device-key=')));
    }
}
$deviceKeys = $requested !== [] ? $requested : [
    'core-sptech-asr1006-mgmt',
    'switch-cisco-2960-172-16-5-41',
];
$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}
$actor = [
    'role' => 'employee',
    'account_type' => 'admin',
    'employee_role' => 'admin',
    'is_active' => 1,
    'name' => 'Cisco SNMP vault reconciler',
];
$results = [];
foreach (array_values(array_unique(array_filter($deviceKeys))) as $deviceKey) {
    $results[] = app_reconcile_cisco_snmp_device($deviceKey, $db, $actor);
}
echo json_encode(['results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(count(array_filter($results, static fn ($result): bool => empty($result['ok']))) === 0 ? 0 : 3);
