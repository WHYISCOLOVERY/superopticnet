<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

$user = app_mobile_authenticate();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') app_mobile_json_response(null, 405, 'Yalnız POST sorğuları dəstəklənir.');
$body = app_mobile_request_body();
$soundKey = trim((string) ($body['sound_key'] ?? 'superoptic_alert'));
if (!in_array($soundKey, ['superoptic_alert','system_default','silent'], true)) $soundKey = 'superoptic_alert';
$quietStart = trim((string) ($body['quiet_start'] ?? ''));
$quietEnd = trim((string) ($body['quiet_end'] ?? ''));
foreach ([&$quietStart, &$quietEnd] as &$time) if ($time !== '' && !preg_match('/^\d{2}:\d{2}$/', $time)) $time = '';
unset($time);
$db = app_db();
$db->prepare(
    "INSERT INTO mobile_notification_preferences (account_id,sound_enabled,vibration_enabled,sound_key,quiet_start,quiet_end)
     VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE sound_enabled=VALUES(sound_enabled),vibration_enabled=VALUES(vibration_enabled),
       sound_key=VALUES(sound_key),quiet_start=VALUES(quiet_start),quiet_end=VALUES(quiet_end)"
)->execute([
    (int) $user['id'], !empty($body['sound_enabled']) ? 1 : 0, !empty($body['vibration_enabled']) ? 1 : 0,
    $soundKey, $quietStart !== '' ? $quietStart . ':00' : null, $quietEnd !== '' ? $quietEnd . ':00' : null,
]);
app_mobile_json_response(['saved' => true]);
