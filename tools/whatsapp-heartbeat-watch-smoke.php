<?php
declare(strict_types=1);

require dirname(__DIR__) . '/whatsapp-monitor-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

// NOTE: PHP does not run try{}finally{} blocks after exit()/die() inside the
// try body -- every failure path below throws instead of exiting directly.
class WhatsappHeartbeatWatchSmokeFailure extends RuntimeException
{
}

function whatsapp_heartbeat_watch_smoke_fail(string $message, int $code = 1): never
{
    throw new WhatsappHeartbeatWatchSmokeFailure($message, $code);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$stamp = date('His') . random_int(100, 999);
$employeeCode = 'SMOKE-WA-HB-' . strtoupper($stamp);
$accountId = 0;
$numberId = 0;
$dedupKey = '';

$cleanup = function () use ($db, &$accountId, &$numberId, &$dedupKey): void {
    if ($dedupKey !== '') {
        $db->prepare('DELETE FROM mgs_alarm_events WHERE dedup_key = :key')->execute(['key' => $dedupKey]);
        $db->prepare('DELETE FROM mgs_dedup_registry WHERE dedup_key = :key')->execute(['key' => $dedupKey]);
    }
    if ($numberId > 0) {
        $db->prepare('DELETE FROM whatsapp_employee_numbers WHERE id = :id')->execute(['id' => $numberId]);
    }
    if ($accountId > 0) {
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $accountId]);
    }
};

try {
    $accountSaved = app_db_upsert_account([
        'login' => strtolower($employeeCode),
        'password' => 'SmokePass123',
        'role' => 'employee',
        'account_type' => 'operator',
        'employee_role' => 'support',
        'full_name' => 'WA Heartbeat Smoke',
        'is_active' => 1,
    ], false);
    if (!is_array($accountSaved)) {
        whatsapp_heartbeat_watch_smoke_fail('account_seed_failed');
    }
    $accountId = (int) ($accountSaved['id'] ?? 0);

    $db->prepare(
        'INSERT INTO whatsapp_employee_numbers (employee_id, phone_number, normalized_phone, status, qr_status, qr_last_seen_at, qr_last_error)
         VALUES (:employee_id, :phone, :phone, "active", "expired", :stale_at, "smoke_forced_expired")'
    )->execute([
        'employee_id' => $accountId,
        'phone' => '99450' . substr($stamp, 0, 7),
        'stale_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
    ]);
    $numberId = (int) $db->lastInsertId();
    $dedupKey = 'whatsapp-heartbeat-offline:' . $numberId;

    // 1. The status function itself must classify a forced-expired QR session as offline.
    $actor = array_merge(app_noc_system_actor('whatsapp-heartbeat-watcher'), ['employee_role' => 'noc']);
    if (!whatsapp_monitor_can_view_all($actor)) {
        whatsapp_heartbeat_watch_smoke_fail('worker_actor_cannot_view_all_numbers');
    }
    $cards = whatsapp_monitor_number_status_cards($db, $actor, false);
    $fixtureCard = null;
    foreach ($cards as $card) {
        if ((int) ($card['number_id'] ?? 0) === $numberId) {
            $fixtureCard = $card;
            break;
        }
    }
    if ($fixtureCard === null) {
        whatsapp_heartbeat_watch_smoke_fail('fixture_number_not_visible_to_worker_actor');
    }
    if ((string) ($fixtureCard['status'] ?? '') !== 'offline') {
        whatsapp_heartbeat_watch_smoke_fail('expired_qr_session_not_classified_offline:' . json_encode($fixtureCard, JSON_UNESCAPED_SLASHES));
    }

    // 2. Worker dry-run must find it without touching the DB.
    $workerScript = dirname(__DIR__) . '/tools/watch-whatsapp-heartbeat.php';
    $php = PHP_BINARY;
    $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open([$php, $workerScript], $descriptorSpec, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        whatsapp_heartbeat_watch_smoke_fail('worker_spawn_failed');
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        whatsapp_heartbeat_watch_smoke_fail('worker_exit_nonzero:' . $exitCode);
    }
    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded) || (string) ($decoded['mode'] ?? '') !== 'dry-run') {
        whatsapp_heartbeat_watch_smoke_fail('worker_output_invalid:' . $stdout);
    }
    $foundInDryRun = false;
    foreach ((array) ($decoded['results'] ?? []) as $result) {
        if ((int) ($result['number_id'] ?? 0) === $numberId) {
            $foundInDryRun = true;
        }
    }
    if (!$foundInDryRun) {
        whatsapp_heartbeat_watch_smoke_fail('dry_run_did_not_surface_fixture_number:' . $stdout);
    }
    // A dry run still writes an audit-trail alarm row (matching
    // app_mgs_analyze_olt_ticket_rules' own dry-run convention elsewhere in
    // this codebase) -- what must NOT happen is a real ('open') status or an
    // actual Telegram send, which record_alarm_event gates on $queueMessage.
    $dryRunRowStatus = $db->prepare('SELECT status FROM mgs_alarm_events WHERE dedup_key = :key');
    $dryRunRowStatus->execute(['key' => $dedupKey]);
    $statusAfterDryRun = $dryRunRowStatus->fetchColumn();
    if ($statusAfterDryRun !== false && $statusAfterDryRun !== 'test') {
        whatsapp_heartbeat_watch_smoke_fail('dry_run_wrote_non_test_status:' . (string) $statusAfterDryRun);
    }

    // 3. Direct test-mode alarm call (no real Telegram send): confirms the
    //    category routing fix actually lands on a real, configured bot key
    //    instead of the network bot the bare "offline" keyword used to match.
    $alarmResult = app_mgs_record_alarm_event([
        'dedup_key' => $dedupKey,
        'category' => 'SYSTEM_HEALTH',
        'alarm_type' => 'WHATSAPP_HEARTBEAT_LOST',
        'severity' => 'warning',
        'object_type' => 'whatsapp_number',
        'object_id' => (string) $numberId,
        'title' => 'WhatsApp nomresi offline: smoke fixture',
        'message' => 'smoke test alarm, category routing check',
        'source' => 'whatsapp_heartbeat_watch',
    ], true, false, $actor, $db);
    if (empty($alarmResult['ok'])) {
        whatsapp_heartbeat_watch_smoke_fail('test_mode_alarm_call_failed:' . json_encode($alarmResult, JSON_UNESCAPED_SLASHES));
    }
    $routedBot = $db->prepare('SELECT telegram_bot_key FROM mgs_alarm_events WHERE dedup_key = :key');
    $routedBot->execute(['key' => $dedupKey]);
    $botKey = (string) $routedBot->fetchColumn();
    if ($botKey === 'network') {
        whatsapp_heartbeat_watch_smoke_fail('regressed_to_network_bot_keyword_misroute');
    }
    if ($botKey === '') {
        whatsapp_heartbeat_watch_smoke_fail('no_bot_key_resolved');
    }

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'number_id' => $numberId,
        'routed_bot_key' => $botKey,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit($exception->getCode() ?: 1);
}
