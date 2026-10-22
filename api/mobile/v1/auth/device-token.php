<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_mobile_json_response(null, 405, 'Yalnız POST sorğuları dəstəklənir.');
}

$user = app_mobile_authenticate();
$body = app_mobile_request_body();

$fcmToken = trim((string) ($body['fcm_token'] ?? ''));
$hmsToken = trim((string) ($body['hms_token'] ?? ''));
$deviceUuid = (string) ($user['authenticated_device_uuid'] ?? trim((string) ($body['device_uuid'] ?? '')));

if ($deviceUuid === '') {
    app_mobile_json_response(null, 422, 'device_uuid müəyyən edilə bilmədi.');
}
if ($fcmToken === '' && $hmsToken === '') {
    app_mobile_json_response(null, 422, 'fcm_token və ya hms_token göndərilməlidir.');
}

$db = app_db();
$stmt = $db->prepare(
    "UPDATE mobile_device_tokens 
     SET fcm_token = COALESCE(NULLIF(?, ''), fcm_token),
         hms_token = COALESCE(NULLIF(?, ''), hms_token),
         last_seen_at = NOW(),
         last_ip = ?
     WHERE account_id = ? AND device_uuid = ?"
);
$clientIp = app_client_ip();
$stmt->execute([$fcmToken, $hmsToken, $clientIp, (int) $user['id'], $deviceUuid]);
if ($stmt->rowCount() !== 1) {
    app_mobile_json_response(null, 404, 'Aktiv cihaz qeydi tapılmadı.');
}

app_mobile_json_response([
    'registered' => true,
    'device_uuid' => $deviceUuid,
    'fcm_token_set' => $fcmToken !== '',
    'hms_token_set' => $hmsToken !== '',
], 200);
