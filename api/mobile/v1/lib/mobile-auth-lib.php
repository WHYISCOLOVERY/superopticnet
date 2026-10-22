<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/config.php';

/**
 * Mobile API Common Response Formatter
 */
function app_mobile_json_response(mixed $data = null, int $statusCode = 200, ?string $error = null, array $meta = []): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    
    // Security headers
    app_send_security_headers();

    $response = [
        'success' => $statusCode >= 200 && $statusCode < 300 && $error === null,
        'status_code' => $statusCode,
        'timestamp' => date(DATE_ATOM),
    ];

    if ($error !== null) {
        $response['error'] = $error;
    }
    if ($data !== null) {
        $response['data'] = $data;
    }
    if (!empty($meta)) {
        $response['meta'] = $meta;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Read and decode incoming JSON request body
 */
function app_mobile_request_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function app_mobile_signing_secret(): string
{
    $secret = app_environment_secret('MOBILE_JWT_SECRET');
    if (strlen($secret) < 32) {
        throw new RuntimeException('MOBILE_JWT_SECRET ən azı 32 simvol olmalıdır.');
    }
    return $secret;
}

function app_mobile_otp_pepper(): string
{
    $secret = app_environment_secret('MOBILE_OTP_PEPPER');
    if (strlen($secret) < 32) {
        throw new RuntimeException('MOBILE_OTP_PEPPER ən azı 32 simvol olmalıdır.');
    }
    return $secret;
}

function app_mobile_required_gps_consent_version(): string
{
    return trim((string) app_setting('mobile_gps_consent_version', 'v2.0')) ?: 'v2.0';
}

function app_mobile_has_current_gps_consent(int $accountId, ?PDO $db = null): bool
{
    $db ??= app_db();
    $stmt = $db->prepare(
        "SELECT id FROM hr_employee_legal_consents
         WHERE account_id = ? AND consent_type = 'gps_tracking_labor_code' AND consent_version = ?
         LIMIT 1"
    );
    $stmt->execute([$accountId, app_mobile_required_gps_consent_version()]);
    return (bool) $stmt->fetchColumn();
}

function app_mobile_is_on_duty(int $accountId, ?PDO $db = null): bool
{
    $db ??= app_db();
    $stmt = $db->prepare(
        "SELECT id FROM mobile_duty_sessions
         WHERE account_id = ? AND ended_at IS NULL
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$accountId]);
    return (bool) $stmt->fetchColumn();
}

function app_mobile_require_recent_location(array $user, int $maxAgeSeconds = 180): void
{
    $db = app_db();
    $accountId = (int) ($user['id'] ?? 0);
    if ($accountId <= 0 || !app_mobile_is_on_duty($accountId, $db)) {
        app_mobile_json_response(['location_required' => true, 'duty_required' => true], 409,
            'İş əməliyyatı üçün əvvəlcə “Gəldim” düyməsi ilə növbəni başladın.');
    }
    $compliance = $db->prepare('SELECT service_enabled,permission_status,last_checked_at FROM mobile_location_compliance WHERE account_id=?');
    $compliance->execute([$accountId]);
    $state = $compliance->fetch(PDO::FETCH_ASSOC);
    if ($state && (!(bool) $state['service_enabled'] || !in_array((string) $state['permission_status'], ['always','whileInUse','granted'], true))) {
        app_mobile_json_response(['location_required' => true, 'open_location_settings' => true], 409,
            'İş saatında GPS-i söndürmək olmaz. Məkan xidmətini aktiv edin.');
    }
    $latest = $db->prepare('SELECT recorded_at,is_mock,accuracy FROM employee_live_locations WHERE account_id=? ORDER BY recorded_at DESC,id DESC LIMIT 1');
    $latest->execute([$accountId]);
    $point = $latest->fetch(PDO::FETCH_ASSOC);
    $age = $point ? time() - strtotime((string) $point['recorded_at']) : PHP_INT_MAX;
    if (!$point || $age < 0 || $age > $maxAgeSeconds || (int) $point['is_mock'] === 1) {
        app_mobile_json_response([
            'location_required' => true, 'open_location_settings' => true,
            'last_location_at' => $point['recorded_at'] ?? null,
        ], 409, 'Canlı GPS mövqeyi təsdiqlənməyib. Məkan xidmətini açın və yenidən sınayın.');
    }
}

function app_mobile_ticket_is_assigned_to(array $ticket, int $accountId): bool
{
    return (int) ($ticket['assigned_technician_account_id'] ?? 0) === $accountId;
}

function app_mobile_otp_hash(string $otp): string
{
    return hash_hmac('sha256', $otp, app_mobile_otp_pepper());
}

function app_mobile_canonicalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $item) {
        $value[$key] = app_mobile_canonicalize($item);
    }
    return $value;
}

function app_mobile_payload_hash(array $payload): string
{
    $json = json_encode(app_mobile_canonicalize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return hash('sha256', $json);
}

/**
 * Generate HMAC-signed Mobile Bearer Token
 * Payload contains: account_id, role, employee_role, device_uuid, issued_at, expires_at
 */
function app_mobile_generate_access_token(array $account, string $deviceUuid, int $ttlSeconds = 86400): string
{
    $secret = app_mobile_signing_secret();
    $payload = [
        'uid' => (int) $account['id'],
        'role' => (string) ($account['role'] ?? 'employee'),
        'sub' => (string) ($account['login'] ?? ''),
        'dev' => $deviceUuid,
        'iat' => time(),
        'exp' => time() + $ttlSeconds,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $b64Payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', $b64Payload, $secret);
    
    return $b64Payload . '.' . $signature;
}

/**
 * Verify and decode Mobile Bearer Token
 */
function app_mobile_verify_access_token(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return null;
    }
    [$b64Payload, $signature] = $parts;
    $secret = app_mobile_signing_secret();
    $expected = hash_hmac('sha256', $b64Payload, $secret);
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $json = base64_decode(strtr($b64Payload, '-_', '+/'));
    $payload = json_decode($json, true);
    if (!is_array($payload) || !isset($payload['uid'], $payload['exp'])) {
        return null;
    }
    if ($payload['exp'] < time()) {
        return null; // Expired
    }

    return $payload;
}

/**
 * Generate single-use Refresh Token and store its SHA-256 hash in DB
 */
function app_mobile_create_refresh_token(int $accountId, string $deviceUuid, int $daysValid = 30): string
{
    $db = app_db();
    $rawToken = bin2hex(random_bytes(32)); // 64 chars
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', time() + ($daysValid * 86400));

    $stmt = $db->prepare(
        "INSERT INTO mobile_auth_refresh_tokens (token_hash, account_id, device_uuid, expires_at, is_used)
         VALUES (?, ?, ?, ?, 0)"
    );
    $stmt->execute([$tokenHash, $accountId, $deviceUuid, $expiresAt]);

    return $rawToken;
}

/**
 * Rotate single-use Refresh Token:
 * Consumes existing token, detects replay theft, returns new Access Token + Refresh Token
 */
function app_mobile_rotate_tokens(string $rawRefreshToken, string $deviceUuid): ?array
{
    $db = app_db();
    $tokenHash = hash('sha256', $rawRefreshToken);

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            "SELECT * FROM mobile_auth_refresh_tokens WHERE token_hash = ? LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $db->rollBack();
            return null;
        }

    // Token Reuse / Replay Detection:
    // If an already used refresh token is presented, suspect token theft -> invalidate all tokens for this device!
    if ((int) $row['is_used'] === 1) {
        $db->prepare("DELETE FROM mobile_auth_refresh_tokens WHERE account_id = ? AND device_uuid = ?")
           ->execute([(int) $row['account_id'], (string) $row['device_uuid']]);
        $db->commit();
        return null;
    }

    // Check expiration
    if (strtotime($row['expires_at']) < time()) {
        $db->rollBack();
        return null;
    }

    // Check device match
    if ($row['device_uuid'] !== $deviceUuid) {
        $db->rollBack();
        return null;
    }

    // Mark current token as used
        $updated = $db->prepare(
            "UPDATE mobile_auth_refresh_tokens SET is_used = 1, used_at = NOW() WHERE token_hash = ? AND is_used = 0"
        );
        $updated->execute([$tokenHash]);
        if ($updated->rowCount() !== 1) {
            $db->rollBack();
            return null;
        }

    // Fetch account
    $stmtAcc = $db->prepare("SELECT * FROM portal_accounts WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmtAcc->execute([(int) $row['account_id']]);
    $account = $stmtAcc->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        $db->rollBack();
        return null;
    }

    // Check if device is revoked
    $stmtDev = $db->prepare(
        "SELECT is_active, revoked_at FROM mobile_device_tokens WHERE account_id = ? AND device_uuid = ? LIMIT 1"
    );
    $stmtDev->execute([(int) $account['id'], $deviceUuid]);
    $devRow = $stmtDev->fetch(PDO::FETCH_ASSOC);
    if (!$devRow || (int) $devRow['is_active'] !== 1) {
        $db->rollBack();
        return null;
    }

    // Generate new pair
    $newAccessToken = app_mobile_generate_access_token($account, $deviceUuid);
    $newRefreshToken = app_mobile_create_refresh_token((int) $account['id'], $deviceUuid);

    $db->commit();

    return [
        'access_token' => $newAccessToken,
        'refresh_token' => $newRefreshToken,
        'expires_in' => 86400,
        'account' => [
            'id' => (int) $account['id'],
            'login' => $account['login'],
            'full_name' => $account['full_name'],
            'role' => $account['role'],
            'employee_role' => $account['employee_role'],
            'capabilities' => app_mobile_account_capabilities($account),
        ],
    ];
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

/**
 * Mobile Authentication Guard
 * Validates Bearer token, checks device active status, returns authenticated account
 */
function app_mobile_authenticate(): array
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($authHeader, 'Bearer ')) {
        // Fallback check
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    }

    if (!str_starts_with($authHeader, 'Bearer ')) {
        app_mobile_json_response(null, 401, 'Autentifikasiya tokeni təqdim edilməyib.');
    }

    $token = substr($authHeader, 7);
    $claims = app_mobile_verify_access_token($token);
    if (!$claims) {
        app_mobile_json_response(null, 401, 'Token etibarsızdır və ya vaxtı bitib.');
    }

    $db = app_db();
    $stmt = $db->prepare("SELECT * FROM portal_accounts WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([(int) $claims['uid']]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        app_mobile_json_response(null, 401, 'İstifadəçi hesabı tapılmadı və ya bloklanıb.');
    }

    // Verify remote wipe / device active status
    $deviceUuid = (string) ($claims['dev'] ?? '');
    if ($deviceUuid !== '') {
        $stmtDev = $db->prepare(
            "SELECT is_active, revoked_at FROM mobile_device_tokens WHERE account_id = ? AND device_uuid = ? LIMIT 1"
        );
        $stmtDev->execute([(int) $account['id'], $deviceUuid]);
        $device = $stmtDev->fetch(PDO::FETCH_ASSOC);
        if (!$device || (int) $device['is_active'] !== 1) {
            app_mobile_json_response([
                'remote_wipe' => true,
                'revoked_at' => $device['revoked_at'] ?? null,
            ], 403, 'Bu cihaz sistem tərəfindən uzaqdan ləğv edilib (Remote Wipe).');
        }
    } else {
        app_mobile_json_response(null, 401, 'Token cihaz identifikatoru daşımır.');
    }

    $account['authenticated_device_uuid'] = $deviceUuid;
    return $account;
}

/**
 * Role Check Helper for Mobile Endpoints
 */
function app_mobile_require_role(array $account, array $allowedRoles): void
{
    $role = (string) ($account['role'] ?? '');
    $empRole = (string) ($account['employee_role'] ?? '');

    $capabilities = app_mobile_account_capabilities($account);
    $hasRole = in_array($role, $allowedRoles, true) 
            || in_array($empRole, $allowedRoles, true)
            || array_intersect($capabilities, $allowedRoles) !== []
            || ($role === 'employee' && in_array('employee', $allowedRoles, true));

    if (!$hasRole) {
        app_mobile_json_response(null, 403, 'Bu əməliyyat üçün səlahiyyətiniz çatmır.');
    }
}

/** @return list<string> */
function app_mobile_account_capabilities(array $account): array
{
    $payload = json_decode((string) ($account['payload_json'] ?? ''), true);
    $payload = is_array($payload) ? $payload : [];
    $values = is_array($payload['mobile_capabilities'] ?? null)
        ? $payload['mobile_capabilities']
        : [];
    $access = is_array($payload['access'] ?? null) ? $payload['access'] : [];
    $modules = is_array($access['module_keys'] ?? null) ? $access['module_keys'] : [];
    $moduleCapabilities = [
        'finance' => ['finance'],
        'inventory' => ['warehouse', 'warehouse_manager'],
        'network' => ['noc_monitor'],
        'billing' => ['operator'],
        'crm' => ['operator'],
    ];
    foreach ($modules as $module) {
        foreach ($moduleCapabilities[strtolower(trim((string) $module))] ?? [] as $capability) {
            $values[] = $capability;
        }
    }
    return array_values(array_unique(array_filter(array_map(
        static fn (mixed $value): string => strtolower(trim((string) $value)),
        $values
    ))));
}

function app_mobile_has_capability(array $account, string $capability): bool
{
    return in_array(strtolower(trim($capability)), app_mobile_account_capabilities($account), true);
}

function app_mobile_employee_role(array $account): string
{
    return strtolower(trim((string) ($account['employee_role'] ?? $account['account_type'] ?? 'employee')));
}

function app_mobile_can_assign_employee(array $actor, array $target): bool
{
    if (($target['role'] ?? 'employee') !== 'employee' || (int) ($target['is_active'] ?? 1) !== 1) {
        return false;
    }
    $actorRole = app_mobile_employee_role($actor);
    $targetRole = app_mobile_employee_role($target);
    $actorId = (int) ($actor['id'] ?? 0);
    $targetId = (int) ($target['id'] ?? 0);

    if ($actorRole === 'super_admin') {
        return true;
    }
    if (in_array($actorRole, ['admin', 'manager', 'director'], true)) {
        return $targetId !== $actorId && !in_array($targetRole, ['super_admin', 'admin'], true);
    }
    if (in_array($actorRole, ['operator', 'night_operator', 'support'], true)) {
        return $targetRole !== 'super_admin';
    }
    if ($actorRole === 'team_lead') {
        return $targetId !== $actorId && in_array($targetRole, ['technician', 'engineer'], true);
    }

    return false;
}

/**
 * Outbox Idempotency Wrapper
 * Ensures that offline mutations executed with a client_uuid are processed exactly once.
 */
function app_mobile_idempotent_mutation(string $clientUuid, int $accountId, string $actionType, array $payload, callable $processor): array
{
    $clientUuid = trim($clientUuid);
    if ($clientUuid === '' || strlen($clientUuid) > 64 || preg_match('/^[A-Za-z0-9._:-]+$/', $clientUuid) !== 1) {
        return [
            'status_code' => 422,
            'data' => ['error' => 'Etibarlı client_uuid tələb olunur.'],
            'is_duplicate' => false,
        ];
    }

    $db = app_db();
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $payloadHash = app_mobile_payload_hash($payload);

    $db->beginTransaction();
    try {
        try {
            $stmtInsert = $db->prepare(
                "INSERT INTO mobile_outbox_queue
                 (client_uuid, account_id, action_type, payload_json, payload_sha256, status, response_code, started_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 'processing', 0, NOW(), NOW())"
            );
            $stmtInsert->execute([$clientUuid, $accountId, $actionType, $payloadJson, $payloadHash]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
            $db->rollBack();
            $stmtCheck = $db->prepare(
                "SELECT account_id, action_type, payload_sha256, status, response_code, response_json
                 FROM mobile_outbox_queue WHERE client_uuid = ? LIMIT 1"
            );
            $stmtCheck->execute([$clientUuid]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if (!$existing || (int) $existing['account_id'] !== $accountId
                || (string) $existing['action_type'] !== $actionType
                || !hash_equals((string) ($existing['payload_sha256'] ?? ''), $payloadHash)) {
                return ['status_code' => 409, 'data' => ['error' => 'client_uuid başqa əməliyyat üçün istifadə edilib.'], 'is_duplicate' => true];
            }
            if ((string) $existing['status'] !== 'completed') {
                return ['status_code' => 409, 'data' => ['error' => 'Əməliyyat hazırda emal olunur.'], 'is_duplicate' => true];
            }
            $cachedBody = json_decode((string) $existing['response_json'], true);
            return [
                'status_code' => (int) $existing['response_code'],
                'data' => is_array($cachedBody) ? $cachedBody : ['cached' => true],
                'is_duplicate' => true,
            ];
        }

        $result = $processor($payload);
        $statusCode = (int) ($result['status_code'] ?? 200);
        $responseData = $result['data'] ?? [];
        $stmtDone = $db->prepare(
            "UPDATE mobile_outbox_queue
             SET status = 'completed', response_code = ?, response_json = ?, updated_at = NOW()
             WHERE client_uuid = ?"
        );
        $stmtDone->execute([
            $statusCode,
            json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $clientUuid,
        ]);
        $db->commit();
        return ['status_code' => $statusCode, 'data' => $responseData, 'is_duplicate' => false];
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}
