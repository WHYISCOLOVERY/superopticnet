<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') app_mobile_json_response(null, 405, 'Yalnız POST sorğuları dəstəklənir.');
$user = app_mobile_authenticate();
app_mobile_require_role($user, ['technician','engineer','team_lead','operator','support']);
$body = app_mobile_request_body();
$enabled = filter_var($body['service_enabled'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($enabled === null) app_mobile_json_response(null, 422, 'service_enabled true və ya false olmalıdır.');
$permission = trim((string) ($body['permission_status'] ?? 'unknown'));
$allowedPermissions = ['always','whileInUse','granted','denied','deniedForever','unableToDetermine','unknown'];
if (!in_array($permission, $allowedPermissions, true)) $permission = 'unknown';
$db = app_db();
$accountId = (int) $user['id'];
$onDuty = app_mobile_is_on_duty($accountId, $db);
$db->prepare(
    "INSERT INTO mobile_location_compliance
        (account_id,device_uuid,service_enabled,permission_status,disabled_since,last_checked_at)
     VALUES (?,?,?,?,IF(?=0,NOW(),NULL),NOW())
     ON DUPLICATE KEY UPDATE device_uuid=VALUES(device_uuid),service_enabled=VALUES(service_enabled),
       permission_status=VALUES(permission_status),disabled_since=IF(VALUES(service_enabled)=0,COALESCE(disabled_since,NOW()),NULL),last_checked_at=NOW()"
)->execute([$accountId, (string) ($user['authenticated_device_uuid'] ?? ''), $enabled ? 1 : 0, $permission, $enabled ? 1 : 0]);

if ($onDuty && (!$enabled || in_array($permission, ['denied','deniedForever','unableToDetermine','unknown'], true))) {
    $session = $db->prepare('SELECT id FROM mobile_duty_sessions WHERE account_id=? AND ended_at IS NULL ORDER BY id DESC LIMIT 1');
    $session->execute([$accountId]);
    $sessionId = (int) $session->fetchColumn();
    $name = trim((string) ($user['full_name'] ?? $user['login'] ?? 'Əməkdaş'));
    $db->prepare(
        "INSERT IGNORE INTO mobile_manager_alerts
            (alert_key,account_id,alert_type,severity,title,message,event_at,metadata_json)
         VALUES (?,?,'gps_disabled','high',?,?,NOW(),?)"
    )->execute([
        'gps_disabled:' . $accountId . ':' . $sessionId, $accountId,
        $name . ' iş vaxtında GPS-i söndürüb',
        'Məkan xidməti və ya icazəsi bağlıdır. Tətbiq iş əməliyyatlarını GPS bərpa olunana qədər bloklayıb.',
        json_encode(['permission_status' => $permission, 'device_uuid' => (string) ($user['authenticated_device_uuid'] ?? '')], JSON_UNESCAPED_UNICODE),
    ]);
} elseif ($enabled) {
    $db->prepare("UPDATE mobile_manager_alerts SET resolved_at=NOW() WHERE account_id=? AND alert_type='gps_disabled' AND resolved_at IS NULL")
        ->execute([$accountId]);
}

app_mobile_json_response([
    'service_enabled' => $enabled, 'permission_status' => $permission, 'is_on_duty' => $onDuty,
    'work_actions_allowed' => !$onDuty || ($enabled && !in_array($permission, ['denied','deniedForever','unableToDetermine','unknown'], true)),
], $onDuty && !$enabled ? 409 : 200, $onDuty && !$enabled ? 'İş vaxtında GPS mütləq aktiv olmalıdır.' : null);
