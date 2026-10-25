<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_mobile_json_response(null, 405, 'Yalnız POST sorğuları dəstəklənir.');
}

$user = app_mobile_authenticate();
$body = app_mobile_request_body();

$consentType = 'gps_tracking_labor_code';
$consentVersion = app_mobile_required_gps_consent_version();
$agreed = (bool) ($body['agreed'] ?? false);

if (!$agreed) {
    app_mobile_json_response(null, 422, 'Razılıq qəbul edilməyib.');
}

$db = app_db();
$clientIp = app_client_ip();
$userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'SuperOptic-MobileApp');
$deviceUuid = (string) ($user['authenticated_device_uuid'] ?? '');

$stmt = $db->prepare(
    "INSERT INTO hr_employee_legal_consents
     (account_id, consent_type, consent_version, ip_address, user_agent, device_uuid, agreed_at)
     VALUES (?, ?, ?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE
       ip_address = VALUES(ip_address),
       user_agent = VALUES(user_agent),
       device_uuid = VALUES(device_uuid),
       agreed_at = NOW()"
);
$stmt->execute([(int) $user['id'], $consentType, $consentVersion, $clientIp, $userAgent, $deviceUuid]);

app_mobile_json_response([
    'consent_signed' => true,
    'consent_type' => $consentType,
    'version' => $consentVersion,
    'account_id' => (int) $user['id'],
    'agreed_at' => date(DATE_ATOM),
], 200);
