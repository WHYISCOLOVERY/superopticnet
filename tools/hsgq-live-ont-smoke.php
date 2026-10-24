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

$nocActor = ['role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'is_active' => 1, 'name' => 'HSGQ live smoke'];
$device = app_mgs_device_learning_profile_by_key('olt-hsgq-kurdexani-20', $db, $nocActor);
if (!is_array($device)) {
    fwrite(STDERR, "device_not_found\n");
    exit(3);
}

$pathReady = app_mgs_management_path_ready();
$snapshot = app_mgs_hsgq_live_ont_snapshot($device, 1, '1', $nocActor, $db);
if (empty($snapshot['ok'])) {
    $reason = (string) ($snapshot['reason'] ?? 'unknown');
    $liveRequired = in_array(strtolower((string) getenv('REQUIRE_HSGQ_LIVE')), ['1', 'true', 'yes', 'on'], true);
    $liveUnavailable = in_array($reason, ['management_path_down', 'ssh_port_unreachable'], true)
        || str_contains($reason, 'SSHException')
        || str_contains($reason, 'Unable to connect');

    if (!$liveRequired && ($liveUnavailable || (!$pathReady && $reason === 'management_path_down'))) {
        echo json_encode([
            'status' => 'skipped',
            'reason' => $reason,
            'device_key' => (string) ($device['device_key'] ?? ''),
            'live_required' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }
    fwrite(STDERR, 'snapshot_failed:' . $reason . "\n");
    exit(4);
}

$requiredTop = ['device_key', 'pon', 'ont_id', 'ont', 'commands', 'raw'];
foreach ($requiredTop as $key) {
    if (!array_key_exists($key, $snapshot)) {
        fwrite(STDERR, 'missing_key:' . $key . "\n");
        exit(5);
    }
}

$ont = is_array($snapshot['ont'] ?? null) ? $snapshot['ont'] : [];
foreach (['serial', 'status', 'temperature', 'voltage', 'bias_current', 'ont_rx', 'ont_tx', 'olt_rx', 'olt_tx'] as $key) {
    if (!array_key_exists($key, $ont) || trim((string) $ont[$key]) === '') {
        fwrite(STDERR, 'missing_ont_field:' . $key . "\n");
        exit(6);
    }
}

$raw = is_array($snapshot['raw'] ?? null) ? $snapshot['raw'] : [];
foreach (['ont_info', 'ont_optical', 'optical_state'] as $key) {
    if (!array_key_exists($key, $raw) || trim((string) $raw[$key]) === '') {
        fwrite(STDERR, 'missing_raw:' . $key . "\n");
        exit(7);
    }
}

echo json_encode([
    'status' => 'ok',
    'device_key' => (string) $snapshot['device_key'],
    'pon' => (string) $snapshot['pon'],
    'ont_id' => (string) $snapshot['ont_id'],
    'serial' => (string) $ont['serial'],
    'temperature' => (string) $ont['temperature'],
    'ont_rx' => (string) $ont['ont_rx'],
    'ont_tx' => (string) $ont['ont_tx'],
    'olt_rx' => (string) $ont['olt_rx'],
    'olt_tx' => (string) $ont['olt_tx'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
