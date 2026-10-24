<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

class MgsAlarmStateHistorySmokeFailure extends RuntimeException
{
}

function mgs_alarm_state_smoke_fail(string $message): never
{
    throw new MgsAlarmStateHistorySmokeFailure($message);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

if (!app_mgs_ensure_schema($db)) {
    fwrite(STDERR, 'mgs_schema_unavailable' . PHP_EOL);
    exit(2);
}

$hasHistoryTable = (int) $db->query(
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "mgs_alarm_state_events"'
)->fetchColumn() > 0;
if (!$hasHistoryTable) {
    fwrite(STDERR, 'mgs_alarm_state_events_not_migrated:run tools/run-portal-migrations.php' . PHP_EOL);
    exit(2);
}

$nocActor = ['id' => 900501, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'is_active' => 1, 'full_name' => 'Smoke NOC Admin'];
$objectId = 'smoke-alarm-history-' . bin2hex(random_bytes(4));
$dedupKey = null;
$alarmId = null;
$cleanup = function () use ($db, &$dedupKey): void {
    if ($dedupKey === null) {
        return;
    }
    $db->prepare('DELETE FROM mgs_alarm_state_events WHERE dedup_key = :dedup_key')->execute(['dedup_key' => $dedupKey]);
    $db->prepare('DELETE FROM mgs_alarm_events WHERE dedup_key = :dedup_key')->execute(['dedup_key' => $dedupKey]);
    $db->prepare('DELETE FROM mgs_dedup_registry WHERE dedup_key = :dedup_key')->execute(['dedup_key' => $dedupKey]);
};

try {
    // --- First occurrence: fresh alarm, no prior row for this dedup_key ---
    $first = app_mgs_record_alarm_event([
        'alarm_type' => 'SMOKE_STATE_HISTORY',
        'severity' => 'warning',
        'source' => 'mgs-alarm-state-history-smoke',
        'object_type' => 'system',
        'object_id' => $objectId,
        'title' => 'Alarm state history smoke',
        'telegram_bot_key' => 'network',
    ], true, false, $nocActor, $db);
    if (empty($first['ok'])) {
        mgs_alarm_state_smoke_fail('first_alarm_record_failed:' . json_encode($first, JSON_UNESCAPED_SLASHES));
    }
    $dedupKey = (string) $first['dedup_key'];

    $alarmRow = $db->prepare('SELECT id FROM mgs_alarm_events WHERE dedup_key = :dedup_key');
    $alarmRow->execute(['dedup_key' => $dedupKey]);
    $alarmId = (int) $alarmRow->fetchColumn();
    if ($alarmId <= 0) {
        mgs_alarm_state_smoke_fail('alarm_row_not_found_after_first_record');
    }

    $events = $db->prepare('SELECT * FROM mgs_alarm_state_events WHERE dedup_key = :dedup_key ORDER BY id ASC');
    $events->execute(['dedup_key' => $dedupKey]);
    $rows = $events->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 1) {
        mgs_alarm_state_smoke_fail('expected_one_state_event_after_first_record:' . count($rows));
    }
    $firstEvent = $rows[0];
    if ((int) $firstEvent['alarm_event_id'] !== $alarmId) {
        mgs_alarm_state_smoke_fail('first_event_alarm_id_mismatch');
    }
    if ($firstEvent['from_status'] !== null || $firstEvent['from_severity'] !== null) {
        mgs_alarm_state_smoke_fail('first_event_should_have_no_from_state:' . json_encode($firstEvent, JSON_UNESCAPED_SLASHES));
    }
    if ((string) $firstEvent['to_status'] !== 'test' || (string) $firstEvent['to_severity'] !== 'warning') {
        mgs_alarm_state_smoke_fail('first_event_wrong_to_state:' . json_encode($firstEvent, JSON_UNESCAPED_SLASHES));
    }
    if ((int) $firstEvent['seen_count'] !== 1) {
        mgs_alarm_state_smoke_fail('first_event_seen_count_should_be_1:' . $firstEvent['seen_count']);
    }

    // --- Re-occurrence: same dedup_key, escalated severity -> should log a second transition, not a new alarm row ---
    $second = app_mgs_record_alarm_event([
        'alarm_type' => 'SMOKE_STATE_HISTORY',
        'severity' => 'critical',
        'source' => 'mgs-alarm-state-history-smoke',
        'object_type' => 'system',
        'object_id' => $objectId,
        'title' => 'Alarm state history smoke',
        'telegram_bot_key' => 'network',
    ], true, false, $nocActor, $db);
    if (empty($second['ok']) || (string) $second['dedup_key'] !== $dedupKey) {
        mgs_alarm_state_smoke_fail('second_alarm_record_should_reuse_dedup_key:' . json_encode($second, JSON_UNESCAPED_SLASHES));
    }

    $alarmRowCount = (int) $db->query(
        'SELECT COUNT(*) FROM mgs_alarm_events WHERE dedup_key = ' . $db->quote($dedupKey)
    )->fetchColumn();
    if ($alarmRowCount !== 1) {
        mgs_alarm_state_smoke_fail('dedup_should_keep_exactly_one_alarm_row:' . $alarmRowCount);
    }

    $events->execute(['dedup_key' => $dedupKey]);
    $rows = $events->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 2) {
        mgs_alarm_state_smoke_fail('expected_two_state_events_after_second_record:' . count($rows));
    }
    $secondEvent = $rows[1];
    if ((string) $secondEvent['from_status'] !== 'test' || (string) $secondEvent['from_severity'] !== 'warning') {
        mgs_alarm_state_smoke_fail('second_event_wrong_from_state:' . json_encode($secondEvent, JSON_UNESCAPED_SLASHES));
    }
    if ((string) $secondEvent['to_status'] !== 'test' || (string) $secondEvent['to_severity'] !== 'critical') {
        mgs_alarm_state_smoke_fail('second_event_wrong_to_state:' . json_encode($secondEvent, JSON_UNESCAPED_SLASHES));
    }
    if ((int) $secondEvent['seen_count'] !== 2) {
        mgs_alarm_state_smoke_fail('second_event_seen_count_should_be_2:' . $secondEvent['seen_count']);
    }
    if ((int) $secondEvent['alarm_event_id'] !== $alarmId) {
        mgs_alarm_state_smoke_fail('second_event_alarm_id_mismatch');
    }

    $cleanup();
    echo json_encode(['status' => 'passed', 'dedup_key' => $dedupKey, 'alarm_event_id' => $alarmId], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
