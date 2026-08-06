<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function ont_command_map_smoke_out(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($code);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    ont_command_map_smoke_out(['status' => 'skipped', 'reason' => 'db_unavailable']);
}

$device = [
    'device_key' => 'smoke-hsgq-default-map',
    'device_name' => 'Smoke HSGQ',
    'vendor' => 'HSGQ',
    'model' => 'G08L',
    'ip_address' => '127.0.0.1',
    'command_map_json' => '{}',
];
$nocActor = ['role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'is_active' => 1, 'name' => 'ONT command smoke'];

$plan = app_mgs_hsgq_ont_control_plan($device, 'restart', 'PON03', '032', $db, $nocActor);
$commands = array_values(array_map('strval', (array) ($plan['commands'] ?? [])));
$ok = !empty($plan['ok'])
    && ($plan['operation'] ?? '') === 'ont_restart'
    && in_array('enable', $commands, true)
    && in_array('configure', $commands, true)
    && in_array('interface gpon PON03', $commands, true)
    && in_array('ont reboot 032', $commands, true)
    && !empty($plan['requires_dangerous_apply']);

ont_command_map_smoke_out([
    'status' => $ok ? 'ok' : 'failed',
    'plan' => $plan,
], $ok ? 0 : 3);
