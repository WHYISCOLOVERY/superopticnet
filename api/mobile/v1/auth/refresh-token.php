<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_mobile_json_response(null, 405, 'Yalnız POST sorğuları dəstəklənir.');
}

$body = app_mobile_request_body();
$refreshToken = trim((string) ($body['refresh_token'] ?? ''));
$deviceUuid = trim((string) ($body['device_uuid'] ?? ''));

if ($refreshToken === '' || $deviceUuid === '') {
    app_mobile_json_response(null, 422, 'refresh_token və device_uuid tələb olunur.');
}
if (strlen($deviceUuid) > 64 || preg_match('/^[A-Za-z0-9._:-]+$/', $deviceUuid) !== 1) {
    app_mobile_json_response(null, 422, 'device_uuid formatı etibarsızdır.');
}

$rotated = app_mobile_rotate_tokens($refreshToken, $deviceUuid);
if (!$rotated) {
    app_mobile_json_response(null, 401, 'Yeniləmə tokeni etibarsızdır və ya təkrar istifadə edilib.');
}

app_mobile_json_response($rotated, 200);
