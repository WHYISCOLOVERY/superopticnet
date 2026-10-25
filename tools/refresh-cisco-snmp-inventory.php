<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
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
    'name' => 'Cisco SNMP inventory refresh',
];
$deviceKeys = [
    'core-sptech-asr1006-mgmt',
    'switch-cisco-2960-172-16-5-41',
];
$results = [];
foreach ($deviceKeys as $deviceKey) {
    $device = app_mgs_device_learning_profile_by_key($deviceKey, $db, $actor);
    if (!is_array($device)) {
        $results[] = ['device_key' => $deviceKey, 'ok' => false, 'error_code' => 'device_not_found'];
        continue;
    }
    $discovery = app_mgs_discover_device_interfaces($device, $db, $actor, 512);
    if (empty($discovery['ok'])) {
        $results[] = [
            'device_key' => $deviceKey,
            'ok' => false,
            'error_code' => (string) ($discovery['reason'] ?? 'interface_discovery_failed'),
        ];
        continue;
    }
    $traffic = app_mgs_poll_device_interface_traffic($db, $actor, true, $deviceKey, 200);
    $results[] = [
        'device_key' => $deviceKey,
        'ok' => !empty($traffic['ok']),
        'interface_count' => (int) ($discovery['interface_count'] ?? 0),
        'traffic_polled' => (int) ($traffic['polled'] ?? 0),
        'traffic_samples_stored' => (int) ($traffic['stored'] ?? 0),
        'traffic_failures' => (int) ($traffic['failed'] ?? 0),
        'error_code' => !empty($traffic['ok']) ? null : (string) ($traffic['reason'] ?? 'traffic_poll_failed'),
    ];
}

echo json_encode(['results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(count(array_filter($results, static fn ($result): bool => empty($result['ok']))) === 0 ? 0 : 3);
