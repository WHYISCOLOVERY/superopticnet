<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

function app_cisco_snmp_run_fixed_change(array $device, array $profile, array $change): array
{
    $python = app_mgs_find_python_binary();
    $helper = __DIR__ . '/_cisco-snmp-config-apply.py';
    if ($python === null || !is_file($helper)) {
        return ['ok' => false, 'error' => 'helper_unavailable'];
    }
    $payload = array_merge($change, [
        'host' => (string) ($device['ip_address'] ?? ''),
        'port' => max(1, (int) ($profile['ssh_port'] ?? 22)),
        'username' => (string) ($profile['ssh_username'] ?? ''),
        'password' => (string) ($profile['ssh_password'] ?? ''),
        'timeout_ms' => 4500,
    ]);
    $process = @proc_open(
        [$python, $helper, '--payload-stdin'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__)
    );
    if (!is_resource($process)) {
        return ['ok' => false, 'error' => 'helper_unavailable'];
    }
    fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_SLASHES));
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    unset($payload, $change, $stderr);
    $decoded = json_decode(trim($stdout), true);
    unset($stdout);
    if ($code !== 0 || !is_array($decoded) || empty($decoded['ok'])) {
        return ['ok' => false, 'error' => is_array($decoded) ? (string) ($decoded['error'] ?? 'change_failed') : 'change_failed'];
    }
    return ['ok' => true, 'persisted' => !empty($decoded['persisted'])];
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

function app_cisco_snmp_read_metadata(array $device, array $profile, string $sourceIp): array
{
    $python = app_mgs_find_python_binary();
    $helper = __DIR__ . '/_cisco-snmp-config-read.py';
    if ($python === null || !is_file($helper)) {
        return ['ok' => false, 'error' => 'helper_unavailable'];
    }
    $payload = [
        'host' => (string) ($device['ip_address'] ?? ''),
        'port' => max(1, (int) ($profile['ssh_port'] ?? 22)),
        'username' => (string) ($profile['ssh_username'] ?? ''),
        'password' => (string) ($profile['ssh_password'] ?? ''),
        'timeout_ms' => 4500,
        'source_ip' => $sourceIp,
    ];
    $process = @proc_open(
        [$python, $helper, '--payload-stdin'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__)
    );
    if (!is_resource($process)) {
        return ['ok' => false, 'error' => 'helper_unavailable'];
    }
    fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_SLASHES));
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    unset($payload, $stderr);
    $decoded = json_decode(trim($stdout), true);
    unset($stdout);
    if ($code !== 0 || !is_array($decoded) || empty($decoded['ok'])) {
        return ['ok' => false, 'error' => is_array($decoded) ? (string) ($decoded['error'] ?? 'config_read_failed') : 'config_read_failed'];
    }
    return $decoded;
}

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
    'name' => 'Cisco SNMP monitoring repair',
];
$plans = [
    [
        'device_key' => 'core-sptech-asr1006-mgmt',
        'change' => [
            'action' => 'permit_snmp_source',
            'acl' => 'SNMP-MGMT',
        ],
        'new_community' => false,
    ],
    [
        'device_key' => 'switch-cisco-2960-172-16-5-41',
        'change' => [
            'action' => 'configure_snmp_ro',
            'acl' => 'SO-SNMP-MONITOR',
            'community' => 'so_' . bin2hex(random_bytes(18)),
        ],
        'new_community' => true,
    ],
];

$requestedDeviceKeys = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--device-key=')) {
        $requestedDeviceKeys[] = trim(substr($argument, strlen('--device-key=')));
    }
}
if ($requestedDeviceKeys !== []) {
    $requestedLookup = array_fill_keys(array_filter($requestedDeviceKeys), true);
    $plans = array_values(array_filter(
        $plans,
        static fn (array $plan): bool => isset($requestedLookup[(string) ($plan['device_key'] ?? '')])
    ));
}

$results = [];
foreach ($plans as $plan) {
    $deviceKey = (string) $plan['device_key'];
    $device = app_mgs_device_learning_profile_by_key($deviceKey, $db, $actor);
    if (!is_array($device)) {
        $results[] = ['device_key' => $deviceKey, 'ok' => false, 'error_code' => 'device_not_found'];
        continue;
    }
    $profile = app_mgs_device_access_profile($device, $db, $actor, 'update_device_access');
    $sourceIp = app_cisco_snmp_source_ip((string) ($device['ip_address'] ?? ''));
    if ($sourceIp === null) {
        $results[] = ['device_key' => $deviceKey, 'ok' => false, 'error_code' => 'monitor_source_ip_unavailable'];
        continue;
    }
    $currentCommunity = (string) ($profile['read_community'] ?? '');
    if ($currentCommunity !== '') {
        $currentProbe = app_mgs_snmp_get_raw(
            (string) ($device['ip_address'] ?? ''),
            $currentCommunity,
            max(1, (int) ($profile['port'] ?? 161)),
            '1.3.6.1.2.1.1.1.0',
            1800
        );
        if (!empty($currentProbe['ok'])) {
            $results[] = [
                'device_key' => $deviceKey,
                'ok' => true,
                'config_applied' => false,
                'state' => 'already_healthy',
                'snmp_reachable' => true,
                'snmp_version' => (int) ($currentProbe['version'] ?? 0) === 1 ? '2c' : '1',
                'vault_updated' => false,
                'error_code' => null,
            ];
            continue;
        }
    }
    $change = $plan['change'];
    $change['source_ip'] = $sourceIp;
    $metadata = [];
    $permitAlreadyPresent = false;
    if (($change['action'] ?? '') === 'permit_snmp_source') {
        $metadata = app_cisco_snmp_read_metadata($device, $profile, $sourceIp);
        if (empty($metadata['ok'])) {
            $results[] = ['device_key' => $deviceKey, 'ok' => false, 'error_code' => (string) ($metadata['error'] ?? 'config_read_failed')];
            continue;
        }
        $targetAcl = (string) ($change['acl'] ?? '');
        $aclStatus = null;
        foreach ((array) ($metadata['acl_statuses'] ?? []) as $candidateStatus) {
            if (is_array($candidateStatus) && (string) ($candidateStatus['acl'] ?? '') === $targetAcl) {
                $aclStatus = $candidateStatus;
                break;
            }
        }
        if (!is_array($aclStatus) || empty($aclStatus['readable'])) {
            $results[] = ['device_key' => $deviceKey, 'ok' => false, 'error_code' => 'snmp_acl_unreadable'];
            continue;
        }
        $permitAlreadyPresent = !empty($aclStatus['source_permitted']);
        if (!$permitAlreadyPresent) {
            $sequence = (int) ($aclStatus['suggested_sequence'] ?? 0);
            if ($sequence < 1) {
                $results[] = ['device_key' => $deviceKey, 'ok' => false, 'error_code' => 'snmp_acl_sequence_unavailable'];
                continue;
            }
            $change['sequence'] = $sequence;
        }
    }
    $community = !empty($plan['new_community'])
        ? (string) ($change['community'] ?? '')
        : (string) ($profile['read_community'] ?? '');
    if (empty($plan['new_community']) && $metadata !== []) {
        foreach ((array) ($metadata['entries'] ?? []) as $entry) {
            if (is_array($entry) && (string) ($entry['acl'] ?? '') === (string) ($change['acl'] ?? '') && trim((string) ($entry['community'] ?? '')) !== '') {
                $community = (string) $entry['community'];
                break;
            }
        }
    }
    if ($community === '') {
        $results[] = ['device_key' => $deviceKey, 'ok' => false, 'error_code' => 'missing_snmp_community'];
        continue;
    }

    $applied = $permitAlreadyPresent
        ? ['ok' => true, 'persisted' => false]
        : app_cisco_snmp_run_fixed_change($device, $profile, $change);
    if (empty($applied['ok'])) {
        $results[] = ['device_key' => $deviceKey, 'ok' => false, 'error_code' => (string) ($applied['error'] ?? 'change_failed')];
        continue;
    }

    $probe = app_mgs_snmp_get_raw(
        (string) ($device['ip_address'] ?? ''),
        $community,
        max(1, (int) ($profile['port'] ?? 161)),
        '1.3.6.1.2.1.1.1.0',
        2200
    );
    if (empty($probe['ok'])) {
        if (($change['action'] ?? '') === 'permit_snmp_source' && !$permitAlreadyPresent) {
            app_cisco_snmp_run_fixed_change($device, $profile, [
                'action' => 'rollback_snmp_source',
                'source_ip' => $sourceIp,
                'acl' => (string) ($change['acl'] ?? ''),
                'sequence' => (int) ($change['sequence'] ?? 0),
            ]);
        } elseif (!empty($plan['new_community'])) {
            app_cisco_snmp_run_fixed_change($device, $profile, [
                'action' => 'rollback_snmp_ro',
                'source_ip' => $sourceIp,
                'acl' => (string) ($change['acl'] ?? ''),
                'community' => $community,
            ]);
        }
        unset($community, $change);
        $results[] = ['device_key' => $deviceKey, 'ok' => false, 'config_applied' => true, 'snmp_reachable' => false, 'error_code' => (string) ($probe['error'] ?? 'verification_failed')];
        continue;
    }

    $stored = app_mgs_set_device_access_override($deviceKey, ['snmp_read_community' => $community], $actor, $db);
    unset($community, $change);
    $ok = !empty($stored['ok']);
    unset($stored);
    app_mgs_record_operation_action([
        'action_type' => 'cisco_snmp_monitoring_repair',
        'entity_type' => 'device_learning_profiles',
        'entity_id' => $deviceKey,
        'status' => $ok ? 'executed' : 'failed',
        'actor' => $actor,
        'summary' => $ok ? 'Cisco SNMP monitoring access repaired and verified.' : 'Cisco SNMP monitoring verification passed but vault update failed.',
        'payload' => [
            'device_key' => $deviceKey,
            'source_ip' => $sourceIp,
            'snmp_version' => (int) ($probe['version'] ?? 0) === 1 ? '2c' : '1',
            'community_rotated' => !empty($plan['new_community']),
            'secret_values_logged' => false,
        ],
    ]);
    $results[] = [
        'device_key' => $deviceKey,
        'ok' => $ok,
        'config_applied' => true,
        'config_persisted' => !empty($applied['persisted']),
        'snmp_reachable' => true,
        'snmp_version' => (int) ($probe['version'] ?? 0) === 1 ? '2c' : '1',
        'vault_updated' => $ok,
        'error_code' => $ok ? null : 'vault_update_failed',
    ];
}

echo json_encode(['results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(count(array_filter($results, static fn ($result): bool => empty($result['ok']))) === 0 ? 0 : 3);
