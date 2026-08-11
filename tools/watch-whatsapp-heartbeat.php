<?php
declare(strict_types=1);

require dirname(__DIR__) . '/whatsapp-monitor-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

/**
 * Active watchdog for WhatsApp bridge heartbeat (docs vision §12.4). Until
 * now whatsapp_monitor_number_status() was only ever computed on page load
 * -- a genuinely dead session was visible if a human happened to look, but
 * nothing watched it continuously or told anyone. This worker walks every
 * active company number on a timer and raises an alarm through the same
 * alarm/dedup/Telegram pipeline the NOC device-offline rule already uses,
 * so a stale session gets the same "someone gets pinged" treatment a dead
 * router does. Read-only: never touches the bridge process itself.
 */

function whatsapp_heartbeat_watch_has_flag(array $argv, string $flag): bool
{
    return in_array($flag, $argv, true);
}

function whatsapp_heartbeat_watch_out(array $payload, int $exitCode = 0): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

$argv = $_SERVER['argv'] ?? [];
$apply = whatsapp_heartbeat_watch_has_flag($argv, '--apply');
$startedAt = microtime(true);

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    whatsapp_heartbeat_watch_out([
        'status' => 'failed',
        'reason' => 'db_unavailable',
        'generated_at' => date(DATE_ATOM),
    ], 2);
}

// whatsapp_monitor_can_view_all() is a separate authorization system from
// app_noc_authorize() -- it checks employee_role against an allowlist, not
// the noc_service_actor/service_name shape app_noc_system_actor() produces.
// Without this, whatsapp_monitor_chat_number_groups() silently scopes to
// employee_id = 0 (this actor's id) and every number is filtered out.
$actor = array_merge(app_noc_system_actor('whatsapp-heartbeat-watcher'), ['employee_role' => 'noc']);
$cards = whatsapp_monitor_number_status_cards($db, $actor, false);

$offline = 0;
$results = [];

foreach ($cards as $card) {
    $status = (string) ($card['status'] ?? '');
    if ($status !== 'offline') {
        continue;
    }
    $numberId = (int) ($card['number_id'] ?? 0);
    if ($numberId <= 0) {
        continue;
    }
    $offline++;
    $companyNumber = (string) ($card['company_number'] ?? $numberId);
    $employeeName = (string) ($card['employee_name'] ?? '');
    $reason = (string) ($card['status_reason'] ?? '');

    $alarm = [
        'dedup_key' => 'whatsapp-heartbeat-offline:' . $numberId,
        // app_notification_resolve_bot() ignores telegram_bot_key for actual
        // routing -- it derives a category from a keyword regex over the
        // fingerprint (event_type+alarm_type+source+object_type+title) unless
        // 'category' is set explicitly. Without this, the word "offline" in
        // the title alone matches the generic NETWORK rule and misroutes to
        // the network bot instead of a system/ops channel.
        'category' => 'SYSTEM_HEALTH',
        'alarm_type' => 'WHATSAPP_HEARTBEAT_LOST',
        'severity' => 'warning',
        'object_type' => 'whatsapp_number',
        'object_id' => (string) $numberId,
        'area' => 'Mesaj Merkezi',
        'title' => 'WhatsApp nomresi offline: ' . $companyNumber,
        'human_problem' => $reason !== '' ? $reason : 'Heartbeat kesilib.',
        'recommendation' => 'Bridge prosesini ve QR sessiyasini yoxlayin (whatsapp-monitor.php?view=pairing).',
        'message' => 'WhatsApp nomresi offline gorunur: ' . $companyNumber
            . ($employeeName !== '' ? ' (' . $employeeName . ')' : '')
            . '. Sebeb: ' . ($reason !== '' ? $reason : 'namelum') . '.',
        'source' => 'whatsapp_heartbeat_watch',
        'telegram_bot_key' => 'superpilot',
    ];
    $alarmResult = app_mgs_record_alarm_event($alarm, !$apply, $apply, $actor, $db);
    $results[] = [
        'number_id' => $numberId,
        'company_number' => $companyNumber,
        'status_reason' => $reason,
        'alarm_ok' => !empty($alarmResult['ok']),
    ];
}

whatsapp_heartbeat_watch_out([
    'status' => 'ok',
    'mode' => $apply ? 'apply' : 'dry-run',
    'numbers_checked' => count($cards),
    'offline_count' => $offline,
    'results' => array_slice($results, 0, 25),
    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    'generated_at' => date(DATE_ATOM),
]);
