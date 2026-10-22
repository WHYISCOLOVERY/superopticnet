<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_mobile_json_response(null, 405, 'Yalnız POST sorğuları dəstəklənir.');
}

$body = app_mobile_request_body();
$login = trim((string) ($body['login'] ?? ''));
$password = (string) ($body['password'] ?? '');
$deviceUuid = trim((string) ($body['device_uuid'] ?? ''));
$platform = strtolower(trim((string) ($body['platform'] ?? 'android')));
$deviceModel = trim((string) ($body['device_model'] ?? ''));
$osVersion = trim((string) ($body['os_version'] ?? ''));
$appVersion = trim((string) ($body['app_version'] ?? '1.0.0'));

if ($login === '' || $password === '') {
    app_mobile_json_response(null, 422, 'İstifadəçi adı və parol tələb olunur.');
}
if ($deviceUuid === '') {
    app_mobile_json_response(null, 422, 'Cihaz identifikatoru (device_uuid) tələb olunur.');
}
if (strlen($deviceUuid) > 64 || preg_match('/^[A-Za-z0-9._:-]+$/', $deviceUuid) !== 1) {
    app_mobile_json_response(null, 422, 'device_uuid formatı etibarsızdır.');
}

$db = app_db();

// Search by login, phone, or subscriber_code
$stmt = $db->prepare(
    "SELECT * FROM portal_accounts 
     WHERE (login = :login OR phone = :phone OR subscriber_code = :sub) 
       AND is_active = 1 
     LIMIT 1"
);
$stmt->execute([
    ':login' => $login,
    ':phone' => $login,
    ':sub' => $login,
]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account || !password_verify($password, (string) ($account['password_hash'] ?? ''))) {
    try {
        $db->prepare(
            "INSERT INTO mobile_auth_audit_log
                (account_id,login_entered,event_type,reason_code,device_uuid,platform,device_model,ip_address,user_agent)
             VALUES (?,?,'login_failed','invalid_credentials',?,?,?,?,?)"
        )->execute([
            $account ? (int) $account['id'] : null, mb_substr($login, 0, 191), mb_substr($deviceUuid, 0, 64),
            mb_substr($platform, 0, 24), mb_substr($deviceModel, 0, 128), app_client_ip(),
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable) {
    }
    // Audit failed attempt & rate-limit
    usleep(150000); // Timing defense
    app_mobile_json_response(null, 401, 'İstifadəçi adı və ya parol yanlışdır.');
}

$accountId = (int) $account['id'];
$allowedPlatforms = ['android', 'ios', 'huawei'];
if (!in_array($platform, $allowedPlatforms, true)) {
    $platform = 'android';
}

// A remotely wiped device may not silently reactivate itself with credentials.
$stmtExistingDevice = $db->prepare(
    'SELECT is_active, revoked_at, revoked_reason FROM mobile_device_tokens WHERE account_id = ? AND device_uuid = ? LIMIT 1'
);
$stmtExistingDevice->execute([$accountId, $deviceUuid]);
$existingDevice = $stmtExistingDevice->fetch(PDO::FETCH_ASSOC);
if ($existingDevice && (int) $existingDevice['is_active'] !== 1) {
    if ((string) ($existingDevice['revoked_reason'] ?? '') === 'password_reset') {
        // Correct new credentials prove the reset recipient. A password reset
        // invalidates sessions, but must not permanently remote-wipe the phone.
        $db->prepare(
            'UPDATE mobile_device_tokens
                SET is_active=1,revoked_at=NULL,revoked_reason=NULL,last_seen_at=NOW()
              WHERE account_id=? AND device_uuid=? AND is_active=0 AND revoked_reason="password_reset"'
        )->execute([$accountId, $deviceUuid]);
    } else {
        app_mobile_json_response([
            'remote_wipe' => true,
            'revoked_at' => $existingDevice['revoked_at'] ?? null,
        ], 403, 'Bu cihaz ləğv edilib. Yenidən aktivləşdirmə üçün rəhbərə müraciət edin.');
    }
}

// One employee account may have exactly one active mobile device. A second
// phone must be explicitly released by a manager from the account-security UI.
$stmtOtherDevice = $db->prepare(
    'SELECT device_uuid,device_model,platform,last_seen_at
       FROM mobile_device_tokens
      WHERE account_id=? AND is_active=1 AND device_uuid<>?
      ORDER BY last_seen_at DESC,id DESC LIMIT 1'
);
$stmtOtherDevice->execute([$accountId, $deviceUuid]);
$otherDevice = $stmtOtherDevice->fetch(PDO::FETCH_ASSOC);
if ($otherDevice) {
    app_mobile_json_response([
        'single_device_limit' => true,
        'needs_admin_revoke' => true,
        'active_device' => [
            'model' => (string) ($otherDevice['device_model'] ?? ''),
            'platform' => (string) ($otherDevice['platform'] ?? ''),
            'last_seen_at' => $otherDevice['last_seen_at'] ?? null,
        ],
    ], 409, 'Bu hesab başqa cihazda aktivdir. Köhnə cihaz super admin tərəfindən ləğv edilməlidir.');
}

// Device registration and token issuance must succeed together.
$clientIp = app_client_ip();
$db->beginTransaction();
try {
    $stmtDev = $db->prepare(
        "INSERT INTO mobile_device_tokens
         (account_id, device_uuid, platform, device_model, os_version, app_version, is_active, last_seen_at, last_ip)
         VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), ?)
         ON DUPLICATE KEY UPDATE
         platform = VALUES(platform),
         device_model = VALUES(device_model),
         os_version = VALUES(os_version),
         app_version = VALUES(app_version),
         last_seen_at = NOW(),
         last_ip = VALUES(last_ip)"
    );
    $stmtDev->execute([$accountId, $deviceUuid, $platform, $deviceModel, $osVersion, $appVersion, $clientIp]);
    $stmtDeviceState = $db->prepare(
        'SELECT is_active FROM mobile_device_tokens WHERE account_id = ? AND device_uuid = ? LIMIT 1 FOR UPDATE'
    );
    $stmtDeviceState->execute([$accountId, $deviceUuid]);
    if ((int) $stmtDeviceState->fetchColumn() !== 1) {
        throw new RuntimeException('Ləğv edilmiş cihaz aktivləşdirilə bilməz.');
    }
    $accessToken = app_mobile_generate_access_token($account, $deviceUuid);
    $refreshToken = app_mobile_create_refresh_token($accountId, $deviceUuid);
    $db->prepare(
        "INSERT INTO mobile_auth_audit_log
            (account_id,login_entered,event_type,reason_code,device_uuid,platform,device_model,ip_address,user_agent)
         VALUES (?,?,'login_success','',?,?,?,?,?)"
    )->execute([
        $accountId, mb_substr($login, 0, 191), $deviceUuid, $platform, mb_substr($deviceModel, 0, 128),
        $clientIp, mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
    $db->prepare(
        "INSERT INTO mobile_operator_activity
            (account_id,device_uuid,activity_type,ip_address,metadata_json)
         VALUES (?,?,'login',?,?)"
    )->execute([
        $accountId, $deviceUuid, $clientIp,
        json_encode(['platform' => $platform, 'device_model' => $deviceModel, 'app_version' => $appVersion], JSON_UNESCAPED_UNICODE),
    ]);
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}

// Check if legal GPS consent is required (for employees)
$legalConsentNeeded = false;
if (($account['role'] ?? '') === 'employee') {
    $legalConsentNeeded = !app_mobile_has_current_gps_consent($accountId, $db);
}

app_mobile_json_response([
    'access_token' => $accessToken,
    'refresh_token' => $refreshToken,
    'expires_in' => 86400,
    'legal_consent_needed' => $legalConsentNeeded,
    'legal_consent_version' => app_mobile_required_gps_consent_version(),
    'user' => [
        'id' => $accountId,
        'login' => $account['login'],
        'full_name' => $account['full_name'],
        'role' => $account['role'],
        'employee_role' => $account['employee_role'] ?? 'technician',
        'job_title' => $account['job_title'] ?? '',
        'phone' => $account['phone'] ?? '',
        'area' => $account['area'] ?? '',
        'capabilities' => app_mobile_account_capabilities($account),
    ],
], 200);
