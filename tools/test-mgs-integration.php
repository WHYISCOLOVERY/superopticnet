<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

function mgs_test_has_flag(string $flag): bool
{
    return in_array($flag, $_SERVER['argv'] ?? [], true);
}

function mgs_test_json(array $payload, int $exitCode = 0): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

$db = app_db();
if (!$db instanceof PDO) {
    mgs_test_json([
        'status' => 'failed',
        'reason' => 'db_unavailable',
        'generated_at' => date(DATE_ATOM),
    ], 2);
}

$applyInventory = mgs_test_has_flag('--apply-inventory');
$applyBackup = mgs_test_has_flag('--apply-backup');
$sendTelegram = mgs_test_has_flag('--send-telegram');
$probeDevices = mgs_test_has_flag('--probe-devices');
$results = [];
$failed = 0;

$schemaOk = app_mgs_ensure_schema($db);
$results['schema'] = ['ok' => $schemaOk];
$failed += $schemaOk ? 0 : 1;

$botSync = app_mgs_sync_telegram_bot_registry($db);
$results['telegram_registry'] = [
    'ok' => true,
    'synced' => (int) ($botSync['synced'] ?? 0),
    'configured' => (int) ($botSync['configured'] ?? 0),
];

$customBotKey = 'smoke_custom_bot_' . strtolower(bin2hex(random_bytes(3)));
if (!function_exists('app_mgs_upsert_custom_telegram_bot') || !function_exists('app_mgs_delete_custom_telegram_bot')) {
    $results['telegram_custom_bot_lifecycle'] = ['ok' => false, 'reason' => 'custom_bot_helpers_missing'];
    $failed++;
} else {
    $customBot = app_mgs_upsert_custom_telegram_bot($db, [
        'bot_key' => $customBotKey,
        'display_name' => 'Smoke Custom Bot',
        'purpose' => 'Custom bot lifecycle smoke',
        'token' => '1234567890:TEST_custom_token_for_dry_run_only',
        'chat_id' => '-1001234567890',
    ], ['id' => 0, 'full_name' => 'SPoG CLI']);
    $customBotConfig = app_mgs_telegram_bot_config($customBotKey);
    $customBotDryRun = app_mgs_send_telegram_bot_test($customBotKey, 'TEST MESAJI custom bot dry-run', '', false);
    $customBotDelete = app_mgs_delete_custom_telegram_bot($db, $customBotKey, ['id' => 0, 'full_name' => 'SPoG CLI']);
    $customBotAfterDelete = app_mgs_telegram_bot_config($customBotKey);
    $customBotOk = !empty($customBot['ok'])
        && !empty($customBotConfig['configured'])
        && !empty($customBotDryRun['configured'])
        && !empty($customBotDelete['ok'])
        && empty($customBotAfterDelete['configured'])
        && (($customBotAfterDelete['reason'] ?? '') === 'unknown_bot');
    $results['telegram_custom_bot_lifecycle'] = [
        'ok' => $customBotOk,
        'created' => $customBot,
        'configured' => !empty($customBotConfig['configured']),
        'dry_run_reason' => (string) ($customBotDryRun['reason'] ?? ''),
        'deleted' => $customBotDelete,
        'after_delete_reason' => (string) ($customBotAfterDelete['reason'] ?? ''),
    ];
    $failed += $customBotOk ? 0 : 1;
}

$processSync = app_mgs_sync_process_registry($db);
$results['process_registry'] = [
    'ok' => true,
    'synced' => (int) ($processSync['synced'] ?? 0),
    'ready' => (int) ($processSync['ready'] ?? 0),
    'blocked' => (int) ($processSync['blocked'] ?? 0),
];

$deviceLearning = app_mgs_seed_device_learning_profiles($db);
$reachability = app_mgs_refresh_device_reachability($db, $probeDevices);
$results['device_learning'] = [
    'ok' => true,
    'seeded' => (int) ($deviceLearning['seeded'] ?? 0),
    'management_path' => app_mgs_runtime_setting('monitor_management_vlan_connected', '0'),
    'reachability' => $reachability,
];

$backupWorker = app_mgs_run_backup_worker($db, $applyBackup, 3, 'profile_snapshot');
$results['device_backup_worker'] = $backupWorker;
$failed += !empty($backupWorker['ok']) ? 0 : 1;

$alarm = app_mgs_record_alarm_event([
    'alarm_type' => 'INTEGRATION_CLI_SMOKE',
    'severity' => 'warning',
    'source' => 'mgs-integration-cli',
    'object_type' => 'system',
    'object_id' => 'spog-cli',
    'title' => 'SPoG CLI smoke alarm',
    'device_name' => 'SuperOptic MGS',
    'human_problem' => 'Alarm, ticket ve mesaj renderer test rejiminde yoxlanildi',
    'recommendation' => 'Bu test real bilet yaratmir ve real alarm sayilmir.',
    'telegram_bot_key' => 'network',
], true, true);
$results['alarm_message_queue'] = $alarm;
$failed += !empty($alarm['ok']) ? 0 : 1;

$ticketCloseDryRun = app_mgs_record_operation_action([
    'action_type' => 'ticket_closed',
    'entity_type' => 'ticket',
    'entity_id' => 'TEST-CLOSE',
    'status' => 'dry_run',
    'actor_name' => 'SPoG CLI',
    'summary' => 'Ticket baglama audit dry-run',
    'ticket_no' => 'TEST-CLOSE',
    'payload' => ['reason' => 'CLI smoke', 'note' => 'No real ticket was closed.'],
]);
$results['ticket_close_audit'] = ['ok' => $ticketCloseDryRun, 'mode' => 'dry-run'];
$failed += $ticketCloseDryRun ? 0 : 1;

if ($applyInventory) {
    $stockIn = app_mgs_record_inventory_movement([
        'sku' => 'TEST-FASTCONNECTOR',
        'item_name' => 'Fast connector SC/APC',
        'category' => 'fiber',
        'unit' => 'eded',
        'quantity' => 2,
        'movement_type' => 'in',
        'ticket_no' => 'TEST-INVENTORY',
        'actor_name' => 'SPoG CLI',
        'note' => 'CLI test medaxil',
    ], true);
    $stockIssue = app_mgs_record_inventory_movement([
        'sku' => 'TEST-FASTCONNECTOR',
        'item_name' => 'Fast connector SC/APC',
        'category' => 'fiber',
        'unit' => 'eded',
        'quantity' => 1,
        'movement_type' => 'customer_install',
        'ticket_no' => 'TEST-INVENTORY',
        'actor_name' => 'SPoG CLI',
        'note' => 'CLI test mexaric',
    ], true);
    $results['inventory_flow'] = ['mode' => 'apply', 'stock_in' => $stockIn, 'stock_issue' => $stockIssue];
    $failed += (!empty($stockIn['ok']) && !empty($stockIssue['ok'])) ? 0 : 1;
} else {
    $inventoryDryRun = app_mgs_record_inventory_movement([
        'sku' => 'TEST-FASTCONNECTOR',
        'item_name' => 'Fast connector SC/APC',
        'category' => 'fiber',
        'unit' => 'eded',
        'quantity' => 1,
        'movement_type' => 'customer_install',
        'ticket_no' => 'TEST-INVENTORY',
        'actor_name' => 'SPoG CLI',
        'note' => 'CLI dry-run',
    ], false);
    $results['inventory_flow'] = $inventoryDryRun;
    $failed += !empty($inventoryDryRun['ok']) ? 0 : 1;
}

$employeeSnapshots = app_mgs_rebuild_employee_work_snapshots($db);
$results['employee_work_snapshots'] = $employeeSnapshots;
$failed += !empty($employeeSnapshots['ok']) ? 0 : 1;

if ($sendTelegram) {
    $telegramTest = app_mgs_send_telegram_bot_test('network', 'TEST MESAJI' . "\n" . 'SPoG CLI integration smoke test', '', true);
    $results['telegram_real_test'] = $telegramTest;
    $failed += !empty($telegramTest['sent']) ? 0 : 1;
} else {
    $telegramDryRun = app_mgs_send_telegram_bot_test('network', 'TEST MESAJI' . "\n" . 'SPoG CLI dry-run', '', false);
    $results['telegram_dry_run'] = $telegramDryRun;
    $failed += !empty($telegramDryRun['configured']) ? 0 : 1;
}

$summary = app_mgs_summary($db);
mgs_test_json([
    'status' => $failed === 0 ? 'ok' : 'failed',
    'mode' => [
        'inventory' => $applyInventory ? 'apply' : 'dry-run',
        'backup' => $applyBackup ? 'apply' : 'dry-run',
        'telegram' => $sendTelegram ? 'apply' : 'dry-run',
        'probe' => $probeDevices ? 'enabled' : 'path-state-only',
    ],
    'failed' => $failed,
    'results' => $results,
    'summary' => $summary,
    'generated_at' => date(DATE_ATOM),
], $failed === 0 ? 0 : 3);
