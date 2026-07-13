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

$device = app_mgs_device_learning_profile_by_key('olt-hsgq-kurdexani-20', $db);
if (!is_array($device)) {
    fwrite(STDERR, "device_not_found\n");
    exit(3);
}

$payload = app_mgs_backup_payload_for_device($device, 'cli_discovery_snapshot');
if (empty($payload['ok'])) {
    $reason = (string) ($payload['reason'] ?? 'unknown');
    $liveRequired = in_array(strtolower((string) getenv('REQUIRE_HSGQ_LIVE')), ['1', 'true', 'yes', 'on'], true);
    $liveUnavailable = in_array($reason, ['management_path_down', 'ssh_port_unreachable'], true)
        || str_contains($reason, 'SSHException')
        || str_contains($reason, 'Unable to connect');

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
$requiredDiscovery = ['prompts', 'nodes', 'keywords', 'sample_ont_id'];
foreach ($requiredDiscovery as $key) {
    if (!array_key_exists($key, $discovery)) {
        fwrite(STDERR, 'missing_discovery_key:' . $key . "\n");
        exit(7);
    }
}

$nodes = is_array($discovery['nodes'] ?? null) ? $discovery['nodes'] : [];
foreach ([
    'user_help',
    'show_help_user',
    'enable_help',
    'config_help',
    'ont_help',
    'port_help',
    'gpon_help',
    'gpon_context_help',
    'show_help_gpon',
    'show_ont_info_help',
    'show_ont_info_all',
    'show_ont_optical_help',
    'show_optical_state',
    'show_statistic',
    'show_gpon_statistic',
    'show_port_transceiver',
] as $nodeKey) {
    if (!array_key_exists($nodeKey, $nodes) || trim((string) $nodes[$nodeKey]) === '') {
        fwrite(STDERR, 'missing_node:' . $nodeKey . "\n");
        exit(8);
    }
}

$keywords = is_array($discovery['keywords'] ?? null) ? $discovery['keywords'] : [];
if ($keywords === [] || !in_array('ont', $keywords, true) || !in_array('gpon', $keywords, true)) {
    fwrite(STDERR, "keywords_incomplete\n");
    exit(9);
}

$meta = is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : [];
if (($meta['source'] ?? '') !== 'live_hsgq_cli_discovery') {
    fwrite(STDERR, "metadata_source_invalid\n");
    exit(10);
}

$derivedCommandMap = is_array($decoded['derived_command_map'] ?? null) ? $decoded['derived_command_map'] : [];
foreach ([
    'enter_enable',
    'enter_config',
    'enter_gpon_context',
    'ont_info_all',
    'ont_info_single',
    'ont_optical_all',
    'ont_optical_single',
    'optical_state',
    'port_statistics',
    'gpon_statistics',
    'port_transceiver',
] as $commandKey) {
    if (!array_key_exists($commandKey, $derivedCommandMap) || trim((string) $derivedCommandMap[$commandKey]) === '') {
        fwrite(STDERR, 'missing_command:' . $commandKey . "\n");
        exit(11);
    }
}

echo json_encode([
    'status' => 'ok',
    'device_key' => (string) ($device['device_key'] ?? ''),
    'keywords' => $keywords,
    'prompt_keys' => array_keys(is_array($discovery['prompts'] ?? null) ? $discovery['prompts'] : []),
    'sample_ont_id' => (string) ($discovery['sample_ont_id'] ?? ''),
    'learned_command_keys' => array_keys($derivedCommandMap),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);
