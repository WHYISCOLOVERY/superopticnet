<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}

$nocActor = ['role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'is_active' => 1, 'name' => 'Cisco CLI smoke'];
$device = app_mgs_device_learning_profile_by_key('core-sptech-asr1006-mgmt', $db, $nocActor);
if (!is_array($device)) {
    fwrite(STDERR, "device_not_found\n");
    exit(3);
}

$payload = app_mgs_backup_payload_for_device($device, 'cli_discovery_snapshot', $nocActor, $db);
if (empty($payload['ok'])) {
    $reason = (string) ($payload['reason'] ?? 'unknown');
    // Same escape hatch as tools/hsgq-cli-discovery-smoke.php: this device is
    // real infrastructure this dev machine has no network path to, so a
    // clean "can't reach it" failure is expected here, not a test failure.
    $liveRequired = in_array(strtolower((string) getenv('REQUIRE_CISCO_LIVE')), ['1', 'true', 'yes', 'on'], true);
    $liveUnavailable = in_array($reason, ['management_path_down', 'ssh_port_unreachable', 'missing_ssh_credentials'], true)
        || str_contains($reason, 'SSHException')
        || str_contains($reason, 'Unable to connect')
        || str_contains($reason, 'timed out')
        || str_contains($reason, 'TimeoutError')
        || str_contains($reason, 'cisco_cli_helper_unavailable');

    if (!$liveRequired && $liveUnavailable) {
        echo json_encode([
            'status' => 'skipped',
            'reason' => $reason,
            'device_key' => (string) ($device['device_key'] ?? ''),
            'live_required' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    fwrite(STDERR, 'payload_failed:' . $reason . "\n");
    exit(4);
}

$decoded = json_decode((string) ($payload['config_text'] ?? ''), true);
if (!is_array($decoded)) {
    fwrite(STDERR, "invalid_json\n");
    exit(5);
}

$requiredTopLevel = ['device', 'discovery', 'metadata', 'derived_command_map'];
foreach ($requiredTopLevel as $key) {
    if (!array_key_exists($key, $decoded)) {
        fwrite(STDERR, 'missing_key:' . $key . "\n");
        exit(6);
    }
}

$discovery = is_array($decoded['discovery'] ?? null) ? $decoded['discovery'] : [];
$raw = is_array($discovery['raw'] ?? null) ? $discovery['raw'] : [];
if (empty($raw['is_cisco_ios'])) {
    fwrite(STDERR, "probe_did_not_confirm_cisco_ios:" . json_encode($raw, JSON_UNESCAPED_SLASHES) . "\n");
    exit(7);
}

$outputs = is_array($raw['outputs'] ?? null) ? $raw['outputs'] : [];
foreach (['show_version', 'show_ip_interface_brief', 'show_interfaces_status', 'show_vlan_brief', 'show_mac_address_table', 'show_inventory'] as $outputKey) {
    if (!array_key_exists($outputKey, $outputs)) {
        fwrite(STDERR, 'missing_output:' . $outputKey . "\n");
        exit(8);
    }
}

$meta = is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : [];
if (($meta['source'] ?? '') !== 'live_cisco_cli_discovery') {
    fwrite(STDERR, "metadata_source_invalid\n");
    exit(9);
}

$derivedCommandMap = is_array($decoded['derived_command_map'] ?? null) ? $decoded['derived_command_map'] : [];
foreach (['show_version', 'ip_interface_brief', 'interfaces_status', 'vlan_brief', 'mac_table', 'inventory'] as $commandKey) {
    if (!array_key_exists($commandKey, $derivedCommandMap) || trim((string) $derivedCommandMap[$commandKey]) === '') {
        fwrite(STDERR, 'missing_command:' . $commandKey . "\n");
        exit(10);
    }
}

echo json_encode([
    'status' => 'ok',
    'device_key' => (string) ($device['device_key'] ?? ''),
    'hostname' => (string) ($raw['hostname'] ?? ''),
    'privileged_mode' => !empty($raw['privileged_mode']),
    'learned_command_keys' => array_keys($derivedCommandMap),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);
