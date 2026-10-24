<?php
declare(strict_types=1);

require __DIR__ . '/../mgs-integration-lib.php';

$db = app_db();
$errors = [];

if (!$db instanceof PDO) {
    $errors[] = 'db_not_ready';
} else {
    app_mgs_ensure_schema($db);
    $tableExists = false;
    try {
        $statement = $db->query('SHOW TABLES LIKE "monitor_map_nodes"');
        $tableExists = $statement !== false && (bool) $statement->fetchColumn();
    } catch (Throwable) {
        $tableExists = false;
    }
    if (!$tableExists) {
        $errors[] = 'monitor_map_nodes_missing';
    }
}

foreach ([
    'app_mgs_monitor_map_layout',
    'app_mgs_save_monitor_map_node',
] as $functionName) {
    if (!function_exists($functionName)) {
        $errors[] = $functionName . '_missing';
    }
}

$nocPage = (string) file_get_contents(__DIR__ . '/../noc-devices.php');
$apiPage = is_file(__DIR__ . '/../api/monitor-layout.php')
    ? (string) file_get_contents(__DIR__ . '/../api/monitor-layout.php')
    : '';

$markers = [
    'save_url' => 'data-monitor-save-url',
    'csrf' => 'data-monitor-csrf',
    'fullscreen_button' => 'data-monitor-fullscreen',
    'tooltip' => 'monitor-node-tooltip',
    'status_text' => 'data-monitor-status-text',
    'server_layout' => '$monitorLayout',
    'api_endpoint' => 'app_mgs_save_monitor_map_node',
];

foreach ($markers as $key => $marker) {
    $haystack = $key === 'api_endpoint' ? $apiPage : $nocPage;
    if (!str_contains($haystack, $marker)) {
        $errors[] = $key . '_marker_missing';
    }
}

if ($db instanceof PDO && function_exists('app_mgs_save_monitor_map_node') && function_exists('app_mgs_monitor_map_layout')) {
    $smokeUser = [
        'id' => 987654,
        'full_name' => 'Smoke Monitor',
        'role' => 'employee',
        'account_type' => 'admin',
        'employee_role' => 'admin',
        'is_active' => 1,
    ];
    $deviceId = '__smoke_monitor_layout__';
    try {
        app_mgs_save_monitor_map_node('noc-monitor-smoke', $smokeUser, $deviceId, 12.5, 34.5, 1.2, 'profile note', $db);
        $layout = app_mgs_monitor_map_layout('noc-monitor-smoke', $smokeUser, $db);
        $saved = $layout[$deviceId] ?? null;
        if (!is_array($saved)
            || abs((float) ($saved['x'] ?? 0) - 12.5) > 0.01
            || abs((float) ($saved['y'] ?? 0) - 34.5) > 0.01
            || abs((float) ($saved['scale'] ?? 0) - 1.2) > 0.01
            || (string) ($saved['note'] ?? '') !== 'profile note'
        ) {
            $errors[] = 'layout_roundtrip_failed';
        }
    } catch (Throwable $exception) {
        $errors[] = 'layout_roundtrip_exception:' . $exception->getMessage();
    } finally {
        try {
            $cleanup = $db->prepare('DELETE FROM monitor_map_nodes WHERE map_key = :map_key AND account_id = :account_id AND device_id = :device_id');
            $cleanup->execute([
                'map_key' => 'noc-monitor-smoke',
                'account_id' => 987654,
                'device_id' => $deviceId,
            ]);
        } catch (Throwable) {
        }
    }
}

echo json_encode([
    'ok' => $errors === [],
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
