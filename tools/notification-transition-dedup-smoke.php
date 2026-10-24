<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

$db = app_db();
$checks = [];
$dedupKey = 'smoke-transition-' . bin2hex(random_bytes(8));
$registryKey = 'notify:' . hash('sha256', $dedupKey);
$releaseDedupKey = $dedupKey . '-release';
$releaseRegistryKey = 'notify:' . hash('sha256', $releaseDedupKey);
$formatted = app_notification_format_event_message([
    'title' => 'OLT cavab vermir',
    'state' => 'offline',
    'device_name' => 'MA5800-X7-TEST',
    'reason' => 'Management yolu elcatan deyil',
    'duration_seconds' => 90061,
], 'OLT');
$checks['az_message_format'] = str_contains($formatted, '[OLT]')
    && str_contains($formatted, 'Status: Offline / xeta var')
    && str_contains($formatted, 'Muddet: 1 gun 1 saat 1 deqiqe')
    && str_contains($formatted, 'Sebeb: Management yolu elcatan deyil');

if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    $checks['db_ready'] = false;
} else {
    $checks['db_ready'] = true;
    try {
        $down = ['dedup_key' => $dedupKey, 'state' => 'offline', 'event_type' => 'device_state', 'title' => 'Smoke down'];
        $firstDown = app_notification_deduplicate_event($down);
        $checks['first_down_allowed'] = empty($firstDown['suppressed']) && ($firstDown['transition_state'] ?? '') === 'down';
        $checks['down_recorded'] = app_notification_record_transition($down, $firstDown);
        $secondDown = app_notification_deduplicate_event($down);
        $checks['repeat_down_suppressed'] = !empty($secondDown['suppressed']) && ($secondDown['reason'] ?? '') === 'duplicate_notification_state';

        $up = ['dedup_key' => $dedupKey, 'state' => 'online', 'event_type' => 'device_state', 'title' => 'Smoke up'];
        $firstUp = app_notification_deduplicate_event($up);
        $checks['first_up_allowed'] = empty($firstUp['suppressed']) && ($firstUp['transition_state'] ?? '') === 'up';
        $checks['up_recorded'] = app_notification_record_transition($up, $firstUp);
        $secondUp = app_notification_deduplicate_event($up);
        $checks['repeat_up_suppressed'] = !empty($secondUp['suppressed']) && ($secondUp['reason'] ?? '') === 'duplicate_notification_state';

        $releaseEvent = ['dedup_key' => $releaseDedupKey, 'state' => 'offline', 'event_type' => 'device_state'];
        $releaseClaim = app_notification_deduplicate_event($releaseEvent);
        $checks['failed_queue_claim_released'] = empty($releaseClaim['suppressed'])
            && app_notification_release_transition($releaseClaim)
            && empty(app_notification_deduplicate_event($releaseEvent)['suppressed']);
    } finally {
        $delete = $db->prepare('DELETE FROM mgs_dedup_registry WHERE dedup_key IN (:dedup_key, :release_key)');
        $delete->execute(['dedup_key' => $registryKey, 'release_key' => $releaseRegistryKey]);
    }
}

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['ok' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
