<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_mobile_json_response(null, 405, 'Yalnız POST sorğuları dəstəklənir.');
}

$user = app_mobile_authenticate();
$body = app_mobile_request_body();
$type = strtolower(trim((string) ($body['activity_type'] ?? 'heartbeat')));
if (!in_array($type, ['app_open', 'foreground', 'heartbeat'], true)) $type = 'heartbeat';
$db = app_db();
$deviceUuid = (string) ($user['authenticated_device_uuid'] ?? '');

$db->prepare(
    'INSERT INTO mobile_operator_activity (account_id,device_uuid,activity_type,ip_address,metadata_json)
     VALUES (?,?,?,?,?)'
)->execute([
    (int) $user['id'], $deviceUuid, $type, app_client_ip(),
    json_encode([
        'app_version' => trim((string) ($body['app_version'] ?? '')),
        'screen' => mb_substr(trim((string) ($body['screen'] ?? '')), 0, 96),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);
$db->prepare('UPDATE mobile_device_tokens SET last_seen_at=NOW(),last_ip=? WHERE account_id=? AND device_uuid=? AND is_active=1')
    ->execute([app_client_ip(), (int) $user['id'], $deviceUuid]);

$schedule = null;
if (in_array((string) ($user['employee_role'] ?? ''), ['operator', 'support'], true)) {
    $stmt = $db->prepare('SELECT * FROM mobile_operator_schedules WHERE account_id=? LIMIT 1');
    $stmt->execute([(int) $user['id']]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($schedule) {
        $schedule['is_enabled'] = (bool) $schedule['is_enabled'];
        $schedule['required_interval_minutes'] = (int) $schedule['required_interval_minutes'];
        $schedule['grace_minutes'] = (int) $schedule['grace_minutes'];
        $schedule['weekdays'] = json_decode((string) $schedule['weekdays_json'], true) ?: [];
        $schedule['breaks'] = json_decode((string) ($schedule['breaks_json'] ?? '[]'), true) ?: [];
        unset($schedule['weekdays_json'], $schedule['breaks_json']);
    }
}

$alerts = [];
$stmtAlerts = $db->prepare(
    "SELECT id,alert_type,severity,title,message,event_at
       FROM mobile_manager_alerts
      WHERE account_id=? AND resolved_at IS NULL
        AND alert_type IN ('operator_presence_reminder','operator_presence_missed')
      ORDER BY event_at DESC LIMIT 10"
);
$stmtAlerts->execute([(int) $user['id']]);
$alerts = $stmtAlerts->fetchAll(PDO::FETCH_ASSOC) ?: [];

app_mobile_json_response([
    'recorded' => true,
    'recorded_at' => date(DATE_ATOM),
    'schedule' => $schedule,
    'alerts' => $alerts,
]);
