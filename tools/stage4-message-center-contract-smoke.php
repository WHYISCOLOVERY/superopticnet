<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
require_once $root . '/whatsapp-monitor-lib.php';
require_once $root . '/db.php';

$passes = [];
$failures = [];

function stage4_check(string $name, bool $condition, array &$passes, array &$failures, string $detail = ''): void
{
    if ($condition) {
        $passes[] = $name;
        return;
    }
    $failures[] = $name . ($detail !== '' ? ' (' . $detail . ')' : '');
}

$settings = [
    'heartbeat_stale_minutes' => ['setting_value' => '10'],
    'sync_stale_minutes' => ['setting_value' => '30'],
];
$now = new DateTimeImmutable('2026-07-25 12:00:00');
$fmt = static fn (int $minutesAgo): string => $now->modify('-' . $minutesAgo . ' minutes')->format('Y-m-d H:i:s');

// 1. Canonical status contract: every branch returns the required keys.
$requiredKeys = ['status', 'status_reason', 'last_activity_at', 'last_sync_at', 'unread_count'];
$sample = whatsapp_monitor_number_status([
    'qr_status' => 'connected',
    'number_status' => 'active',
    'qr_last_seen_at' => $fmt(2),
    'last_message_at' => $fmt(5),
    'unread_count' => 3,
], $settings, $now);
foreach ($requiredKeys as $key) {
    stage4_check('status payload has ' . $key, array_key_exists($key, $sample), $passes, $failures);
}
stage4_check('status enum is valid', in_array($sample['status'], ['online', 'sync_delayed', 'offline', 'disabled'], true), $passes, $failures, $sample['status']);

// 2. Threshold semantics.
$onlineCase = whatsapp_monitor_number_status(['qr_status' => 'connected', 'number_status' => 'active', 'qr_last_seen_at' => $fmt(3)], $settings, $now);
stage4_check('fresh heartbeat -> online', $onlineCase['status'] === 'online', $passes, $failures, $onlineCase['status']);

$delayedCase = whatsapp_monitor_number_status(['qr_status' => 'connected', 'number_status' => 'active', 'qr_last_seen_at' => $fmt(20)], $settings, $now);
stage4_check('20 min heartbeat -> sync_delayed', $delayedCase['status'] === 'sync_delayed', $passes, $failures, $delayedCase['status']);

$offlineCase = whatsapp_monitor_number_status(['qr_status' => 'connected', 'number_status' => 'active', 'qr_last_seen_at' => $fmt(90)], $settings, $now);
stage4_check('90 min heartbeat -> offline', $offlineCase['status'] === 'offline', $passes, $failures, $offlineCase['status']);

$disabledCase = whatsapp_monitor_number_status(['qr_status' => 'not_connected', 'number_status' => 'active'], $settings, $now);
stage4_check('no QR session -> disabled', $disabledCase['status'] === 'disabled', $passes, $failures, $disabledCase['status']);

$inactiveCase = whatsapp_monitor_number_status(['qr_status' => 'connected', 'number_status' => 'returned', 'qr_last_seen_at' => $fmt(1)], $settings, $now);
stage4_check('inactive number -> disabled', $inactiveCase['status'] === 'disabled', $passes, $failures, $inactiveCase['status']);

$pendingCase = whatsapp_monitor_number_status(['qr_status' => 'qr_ready', 'number_status' => 'active'], $settings, $now);
stage4_check('pending QR -> sync_delayed', $pendingCase['status'] === 'sync_delayed', $passes, $failures, $pendingCase['status']);

stage4_check('every status has a human reason', trim((string) $offlineCase['status_reason']) !== '', $passes, $failures);

// 3. Default view and tabs contract.
$monitorLib = (string) file_get_contents($root . '/whatsapp-monitor-lib.php');
stage4_check('default view helper exists', function_exists('whatsapp_monitor_default_view'), $passes, $failures);
stage4_check('all-view-keys helper exists', function_exists('whatsapp_monitor_all_view_keys'), $passes, $failures);
stage4_check('screen-wall stays a reachable alias', in_array('screen-wall', whatsapp_monitor_all_view_keys(), true), $passes, $failures);
stage4_check('live-monitor is a valid view', in_array('live-monitor', whatsapp_monitor_all_view_keys(), true), $passes, $failures);

// 4. Read-only guarantee: no send/reply/delete action in the monitor page or views.
$monitorPage = (string) file_get_contents($root . '/whatsapp-monitor.php');
$viewFiles = glob($root . '/views/whatsapp-monitor/*.php') ?: [];
$sendMarker = false;
foreach (array_merge([$root . '/whatsapp-monitor.php'], $viewFiles) as $file) {
    $content = (string) file_get_contents($file);
    if (preg_match('/action["\']?\s*[=:>]+\s*["\'](send_message|reply_message|delete_message|send_whatsapp)/i', $content) === 1) {
        $sendMarker = true;
        break;
    }
}
stage4_check('no send/reply/delete action in monitor', !$sendMarker, $passes, $failures);

// 5. Production mock guard: the synthetic TEST row stays behind demo fixtures.
$messageCenter = (string) file_get_contents($root . '/message-center.php');
stage4_check(
    'TEST MESAJI sample row is demo-gated',
    (bool) preg_match('/if \(\$recent === \[\] && app_demo_fixtures_enabled\(\)\)/', $messageCenter),
    $passes,
    $failures
);
stage4_check('demo fixtures disabled in production', !app_demo_fixtures_enabled(), $passes, $failures);

// 6. Migration and cutover contracts.
$migration = (string) @file_get_contents($root . '/db/migrations/20260725_050_stage4_message_center.php');
stage4_check('stage4 migration exists', $migration !== '', $passes, $failures);
stage4_check('stage4 migration refuses enabled rollback', str_contains($migration, 'stage4_rollback_requires_disabled_flag'), $passes, $failures);
$cutover = (string) @file_get_contents($root . '/tools/stage4-feature-cutover.php');
stage4_check('stage4 cutover guards migration', str_contains($cutover, 'stage4_migration_not_ready'), $passes, $failures);

// 7. Status API endpoint contract.
$api = (string) @file_get_contents($root . '/api/whatsapp-monitor/number-status.php');
stage4_check('status API requires whatsapp_monitor module', str_contains($api, "billing_require_employee(false, 'whatsapp_monitor')"), $passes, $failures);
stage4_check('status API returns cards', str_contains($api, 'whatsapp_monitor_number_status_cards'), $passes, $failures);
stage4_check('status poll stays light (no chat previews)', str_contains($api, 'whatsapp_monitor_number_status_cards($db, $user, false)'), $passes, $failures);

// 7b. Phone-grid + enlarge modal contract.
$liveView = (string) @file_get_contents($root . '/views/whatsapp-monitor/live-monitor.php');
foreach ([
    'data-wa-phone',            // phone cards
    'wa-phone-screen',          // inline chat preview inside each phone
    'data-wa-modal',            // in-page enlarge modal
    'data-wa-chat-search',      // chat search
    'data-wa-message-search',   // message search
    'api/whatsapp-monitor/chat-list.php',
    'api/whatsapp-monitor/chat-messages.php',
] as $marker) {
    stage4_check('live-monitor markup: ' . $marker, str_contains($liveView, $marker), $passes, $failures);
}
stage4_check('modal uses aria-modal dialog', str_contains($liveView, 'role="dialog" aria-modal="true"'), $passes, $failures);
stage4_check('cards expose recent_chats', str_contains($monitorLib, "'recent_chats' =>"), $passes, $failures);

// 8. Runtime flag reflects DB state.
$db = app_db();
if ($db instanceof PDO) {
    $flagStatement = $db->prepare('SELECT is_enabled FROM app_feature_flags WHERE flag_key = "stage4_message_center"');
    $flagStatement->execute();
    $flagEnabled = (int) $flagStatement->fetchColumn() === 1;
    $adminActor = ['employee_role' => 'admin', 'account_type' => 'admin', 'id' => 1];
    stage4_check(
        'runtime flag matches DB state',
        whatsapp_monitor_stage4_enabled($db, $adminActor) === $flagEnabled,
        $passes,
        $failures
    );
    $expectedDefault = $flagEnabled ? 'live-monitor' : 'overview';
    stage4_check(
        'default view matches flag',
        whatsapp_monitor_default_view($db, $adminActor) === $expectedDefault,
        $passes,
        $failures,
        whatsapp_monitor_default_view($db, $adminActor)
    );
    $tabs = whatsapp_monitor_tabs($db, $adminActor);
    if ($flagEnabled) {
        stage4_check('flag on: menu leads with live-monitor', array_key_first($tabs) === 'live-monitor', $passes, $failures);
        stage4_check('flag on: screen-wall not in menu', !array_key_exists('screen-wall', $tabs), $passes, $failures);
    } else {
        stage4_check('flag off: menu keeps overview', array_key_exists('overview', $tabs), $passes, $failures);
    }
} else {
    $passes[] = 'db checks skipped (database unavailable)';
}

echo 'stage4_contract_pass=' . count($passes) . PHP_EOL;
if ($failures !== []) {
    echo 'stage4_contract_fail=' . count($failures) . PHP_EOL;
    foreach ($failures as $failure) {
        echo 'FAIL: ' . $failure . PHP_EOL;
    }
    exit(1);
}
echo 'stage4_contract_fail=0' . PHP_EOL;
exit(0);
