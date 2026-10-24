<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

$user = app_mobile_authenticate();
app_mobile_require_role($user, ['super_admin']);
$db = app_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $deviceRows = $db->query(
        "SELECT account_id,device_uuid,platform,device_model,os_version,app_version,is_active,
                last_seen_at,last_ip,revoked_at,revoked_reason
           FROM mobile_device_tokens ORDER BY last_seen_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $devicesByAccount = [];
    foreach ($deviceRows as $device) $devicesByAccount[(int) $device['account_id']][] = $device;
    $accounts = $db->query(
        "SELECT p.id,p.login,p.full_name,p.job_title,p.account_type,p.employee_role,p.area,p.is_active,p.payload_json,
                (SELECT MAX(a.occurred_at) FROM mobile_auth_audit_log a WHERE a.account_id=p.id AND a.event_type='login_success') last_login_at,
                (SELECT COUNT(*) FROM mobile_auth_audit_log a WHERE a.account_id=p.id AND a.event_type='login_failed' AND a.occurred_at>=NOW()-INTERVAL 7 DAY) failed_logins_7d,
                (SELECT COUNT(*) FROM mobile_device_tokens d WHERE d.account_id=p.id AND d.is_active=1) active_devices
           FROM portal_accounts p
          WHERE p.role='employee' AND p.account_type<>'customer'
          ORDER BY FIELD(p.employee_role,'super_admin','admin','director','team_lead','noc','engineer','operator','technician'),p.full_name"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $audit = $db->query(
        "SELECT a.id,a.login_entered,a.event_type,a.reason_code,a.device_model,a.ip_address,a.occurred_at,p.full_name
           FROM mobile_auth_audit_log a
           LEFT JOIN portal_accounts p ON p.id=a.account_id
          ORDER BY a.occurred_at DESC,a.id DESC LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    app_mobile_json_response([
        'accounts' => array_map(static function (array $row) use ($devicesByAccount): array {
            $row['id'] = (int) $row['id'];
            $row['is_active'] = (bool) $row['is_active'];
            $row['failed_logins_7d'] = (int) $row['failed_logins_7d'];
            $row['active_devices'] = (int) $row['active_devices'];
            $row['capabilities'] = app_mobile_account_capabilities($row);
            $row['devices'] = $devicesByAccount[(int) $row['id']] ?? [];
            unset($row['payload_json']);
            return $row;
        }, $accounts),
        'audit' => array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            return $row;
        }, $audit),
    ]);
}

if ($method !== 'POST') app_mobile_json_response(null, 405, 'Yalnız GET və POST sorğuları dəstəklənir.');
$body = app_mobile_request_body();
$action = (string) ($body['action'] ?? '');
if (!in_array($action, ['reset_password', 'revoke_device', 'activate_device'], true)) {
    app_mobile_json_response(null, 422, 'Dəstəklənməyən hesab əməliyyatı.');
}
$targetId = (int) ($body['account_id'] ?? 0);
$deviceUuid = trim((string) ($body['device_uuid'] ?? ''));
if ($action === 'activate_device') {
    if ($targetId <= 0 || $deviceUuid === '') app_mobile_json_response(null, 422, 'Hesab və cihaz seçilməlidir.');
    $replaceActiveDevice = filter_var($body['replace_active_device'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $db->beginTransaction();
    try {
        $activeDevice = $db->prepare(
            'SELECT device_uuid FROM mobile_device_tokens WHERE account_id=? AND is_active=1 AND device_uuid<>? LIMIT 1 FOR UPDATE'
        );
        $activeDevice->execute([$targetId, $deviceUuid]);
        $otherDeviceUuid = $activeDevice->fetchColumn();
        if ($otherDeviceUuid !== false && !$replaceActiveDevice) {
            $db->rollBack();
            app_mobile_json_response(null, 409, 'Bu hesabda artıq başqa aktiv cihaz var. Əvvəl həmin cihazı ləğv edin.');
        }
        if ($otherDeviceUuid !== false) {
            $db->prepare(
                "UPDATE mobile_device_tokens
                    SET is_active=0,revoked_at=NOW(),revoked_reason='super_admin_device_replaced'
                  WHERE account_id=? AND device_uuid=? AND is_active=1"
            )->execute([$targetId, (string) $otherDeviceUuid]);
            $db->prepare('DELETE FROM mobile_auth_refresh_tokens WHERE account_id=? AND device_uuid=?')
                ->execute([$targetId, (string) $otherDeviceUuid]);
        }
        $stmt = $db->prepare(
            'UPDATE mobile_device_tokens
                SET is_active=1,revoked_at=NULL,revoked_reason=NULL,last_seen_at=NOW()
              WHERE account_id=? AND device_uuid=? AND is_active=0'
        );
        $stmt->execute([$targetId, $deviceUuid]);
        if ($stmt->rowCount() !== 1) {
            $db->rollBack();
            app_mobile_json_response(null, 404, 'Ləğv edilmiş cihaz tapılmadı.');
        }
        $db->prepare(
            "INSERT INTO mobile_auth_audit_log
                (account_id,login_entered,event_type,reason_code,device_uuid,ip_address,user_agent)
             SELECT id,login,'login_success','super_admin_device_activate',?,?,? FROM portal_accounts WHERE id=?"
        )->execute([$deviceUuid, app_client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $targetId]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }
    app_mobile_json_response(['activated' => true, 'account_id' => $targetId, 'device_uuid' => $deviceUuid]);
}
if ($action === 'revoke_device') {
    if ($targetId <= 0 || $deviceUuid === '') app_mobile_json_response(null, 422, 'Hesab və cihaz seçilməlidir.');
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("UPDATE mobile_device_tokens SET is_active=0,revoked_at=NOW(),revoked_reason='super_admin_revoke' WHERE account_id=? AND device_uuid=? AND is_active=1");
        $stmt->execute([$targetId, $deviceUuid]);
        if ($stmt->rowCount() !== 1) { $db->rollBack(); app_mobile_json_response(null, 404, 'Aktiv cihaz tapılmadı.'); }
        $db->prepare('DELETE FROM mobile_auth_refresh_tokens WHERE account_id=? AND device_uuid=?')->execute([$targetId, $deviceUuid]);
        $db->prepare("UPDATE mobile_duty_sessions SET ended_at=NOW(),ended_reason='device_revoked' WHERE account_id=? AND device_uuid=? AND ended_at IS NULL")
            ->execute([$targetId, $deviceUuid]);
        $db->prepare("INSERT INTO mobile_auth_audit_log (account_id,login_entered,event_type,reason_code,device_uuid,ip_address,user_agent) SELECT id,login,'logout','super_admin_device_revoke',?,?,? FROM portal_accounts WHERE id=?")
            ->execute([$deviceUuid, app_client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $targetId]);
        $db->commit();
    } catch (Throwable $exception) { if ($db->inTransaction()) $db->rollBack(); throw $exception; }
    app_mobile_json_response(['revoked' => true, 'account_id' => $targetId, 'device_uuid' => $deviceUuid]);
}
$password = (string) ($body['new_password'] ?? '');
if ($targetId <= 0) app_mobile_json_response(null, 422, 'Hesab seçilməyib.');
if ($password === '') {
    $password = 'A1!' . rtrim(strtr(base64_encode(random_bytes(15)), '+/', 'XZ'), '=');
}
if (strlen($password) < 10 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)
    || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
    app_mobile_json_response(null, 422, 'Parol ən azı 10 simvol, böyük/kiçik hərf, rəqəm və xüsusi işarə daşımalıdır.');
}

$db->beginTransaction();
try {
    $select = $db->prepare("SELECT * FROM portal_accounts WHERE id=? AND role='employee' AND account_type<>'customer' FOR UPDATE");
    $select->execute([$targetId]);
    $target = $select->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        $db->rollBack();
        app_mobile_json_response(null, 404, 'Əməkdaş hesabı tapılmadı.');
    }
    $payload = json_decode((string) ($target['payload_json'] ?? ''), true);
    $payload = is_array($payload) ? $payload : [];
    $payload['security']['password_reset_at'] = date(DATE_ATOM);
    $payload['security']['password_reset_by'] = (int) $user['id'];
    $db->prepare('UPDATE portal_accounts SET password_hash=?,payload_json=?,updated_at=NOW() WHERE id=?')
        ->execute([
            password_hash($password, PASSWORD_DEFAULT),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $targetId,
        ]);
    $db->prepare('DELETE FROM mobile_auth_refresh_tokens WHERE account_id=?')->execute([$targetId]);
    $db->prepare('UPDATE mobile_device_tokens SET is_active=0,revoked_at=NOW(),revoked_reason="password_reset" WHERE account_id=? AND is_active=1')
        ->execute([$targetId]);
    $db->prepare(
        'INSERT INTO mobile_password_reset_audit
            (target_account_id,actor_account_id,actor_name,revoke_sessions,ip_address)
         VALUES (?,?,?,?,?)'
    )->execute([$targetId, (int) $user['id'], (string) $user['full_name'], 1, app_client_ip()]);
    $db->prepare(
        "INSERT INTO mobile_auth_audit_log
            (account_id,login_entered,event_type,reason_code,device_uuid,ip_address,user_agent)
         VALUES (?,?,'password_reset','super_admin_reset',?,?,?)"
    )->execute([
        $targetId, (string) $target['login'], (string) $user['authenticated_device_uuid'], app_client_ip(),
        mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}

app_mobile_json_response([
    'account_id' => $targetId,
    'login' => (string) $target['login'],
    'temporary_password' => $password,
    'sessions_revoked' => true,
    'message' => 'Parol yeniləndi və əvvəlki cihaz sessiyaları bağlandı.',
]);
