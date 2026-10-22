<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/audit-log-lib.php';

function app_portal_human_verification_mode(): string
{
    return app_config('recaptcha_site_key') !== '' && app_environment_secret('RECAPTCHA_SECRET') !== ''
        ? 'google'
        : 'local';
}

function app_portal_local_challenge_minimum_seconds(): int
{
    return max(0, (int) app_setting('captcha_local_min_seconds', 0));
}

function app_portal_human_challenge(string $scope): array
{
    app_session_boot();
    $scope = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower(trim($scope))) ?: 'portal';
    if (app_portal_human_verification_mode() === 'google') {
        return [
            'mode' => 'google',
            'site_key' => app_config('recaptcha_site_key'),
            'scope' => $scope,
        ];
    }

    $left = random_int(2, 9);
    $right = random_int(1, 9);
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['portal_human_challenges'][$scope] = [
        'nonce_hash' => hash('sha256', $nonce),
        'answer' => $left + $right,
        'issued_at' => time(),
    ];

    return [
        'mode' => 'local',
        'scope' => $scope,
        'nonce' => $nonce,
        'question' => $left . ' + ' . $right . ' = ?',
    ];
}

function app_portal_human_verify(string $scope, array $input): array
{
    app_session_boot();
    $scope = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower(trim($scope))) ?: 'portal';
    if (trim((string) ($input['website'] ?? '')) !== '') {
        return ['ok' => false, 'reason' => 'honeypot_filled', 'mode' => app_portal_human_verification_mode()];
    }

    if (app_portal_human_verification_mode() === 'google') {
        return app_portal_google_recaptcha_verify((string) ($input['g-recaptcha-response'] ?? ''));
    }

    $challenge = $_SESSION['portal_human_challenges'][$scope] ?? null;
    unset($_SESSION['portal_human_challenges'][$scope]);
    if (!is_array($challenge)) {
        return ['ok' => false, 'reason' => 'local_challenge_missing', 'mode' => 'local'];
    }

    $nonce = trim((string) ($input['human_nonce'] ?? ''));
    $answer = trim((string) ($input['human_answer'] ?? ''));
    $confirmed = (string) ($input['human_confirm'] ?? '') === '1';
    $age = time() - (int) ($challenge['issued_at'] ?? 0);
    $minimumSeconds = app_portal_local_challenge_minimum_seconds();
    $valid = $confirmed
        && $nonce !== ''
        && hash_equals((string) ($challenge['nonce_hash'] ?? ''), hash('sha256', $nonce))
        && $age >= $minimumSeconds
        && $age <= 300
        && ctype_digit($answer)
        && (int) $answer === (int) ($challenge['answer'] ?? -1);

    return [
        'ok' => $valid,
        'reason' => $valid ? '' : 'local_challenge_failed',
        'mode' => 'local',
    ];
}

function app_portal_render_human_challenge(array $challenge): void
{
    ?>
    <div class="portal-human-check">
        <input class="portal-honeypot" type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true">
        <?php if (($challenge['mode'] ?? '') === 'google'): ?>
            <div class="g-recaptcha" data-sitekey="<?= e((string) ($challenge['site_key'] ?? '')); ?>"></div>
        <?php else: ?>
            <input type="hidden" name="human_nonce" value="<?= e((string) ($challenge['nonce'] ?? '')); ?>">
            <div class="portal-local-challenge">
                <label for="humanAnswer">Təhlükəsizlik hesablaması: <strong><?= e((string) ($challenge['question'] ?? '')); ?></strong></label>
                <input id="humanAnswer" type="number" name="human_answer" inputmode="numeric" required>
                <label class="portal-human-confirm">
                    <input type="checkbox" name="human_confirm" value="1" required>
                    <span>Mən robot deyiləm</span>
                </label>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

function app_portal_google_recaptcha_verify(string $token): array
{
    $secret = app_environment_secret('RECAPTCHA_SECRET');
    if ($secret === '' || trim($token) === '') {
        return ['ok' => false, 'reason' => 'recaptcha_token_missing', 'mode' => 'google'];
    }

    $response = http_request_raw(
        'https://www.google.com/recaptcha/api/siteverify',
        'POST',
        http_build_query([
            'secret' => $secret,
            'response' => trim($token),
            'remoteip' => app_client_ip(),
        ]),
        ['Content-Type: application/x-www-form-urlencoded'],
        8
    );
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    if (empty($response['ok']) || !is_array($payload) || empty($payload['success'])) {
        return ['ok' => false, 'reason' => 'recaptcha_rejected', 'mode' => 'google'];
    }

    $verifiedHost = strtolower(trim((string) ($payload['hostname'] ?? '')));
    $requestHost = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    $requestHost = preg_replace('/:\d+$/', '', $requestHost) ?? $requestHost;
    $configuredHosts = array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', app_config('recaptcha_allowed_hosts'))
    )));
    $allowedHosts = array_values(array_unique(array_filter(array_merge([$requestHost], $configuredHosts))));
    if ($verifiedHost === '' || ($allowedHosts !== [] && !in_array($verifiedHost, $allowedHosts, true))) {
        return ['ok' => false, 'reason' => 'recaptcha_hostname_mismatch', 'mode' => 'google'];
    }

    return ['ok' => true, 'reason' => '', 'mode' => 'google'];
}

function app_portal_recovery_table_sql(): string
{
    return "CREATE TABLE IF NOT EXISTS portal_password_recovery_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL,
        channel VARCHAR(32) NOT NULL DEFAULT 'whatsapp',
        token_hash CHAR(64) NOT NULL,
        code_hash CHAR(64) NOT NULL,
        destination_hash CHAR(64) NOT NULL,
        requested_ip_hash CHAR(64) NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
        expires_at DATETIME NOT NULL,
        consumed_at DATETIME DEFAULT NULL,
        invalidated_at DATETIME DEFAULT NULL,
        created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
        metadata_json LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_portal_password_recovery_token (token_hash),
        KEY idx_portal_password_recovery_account (account_id, created_at),
        KEY idx_portal_password_recovery_expiry (expires_at, consumed_at, invalidated_at),
        CONSTRAINT fk_portal_password_recovery_account
            FOREIGN KEY (account_id) REFERENCES portal_accounts(id) ON DELETE CASCADE,
        CONSTRAINT fk_portal_password_recovery_actor
            FOREIGN KEY (created_by_account_id) REFERENCES portal_accounts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
}

function app_portal_recovery_ensure_schema(PDO $db): bool
{
    if (app_db_table_exists($db, 'portal_password_recovery_requests')) {
        return true;
    }
    $environment = strtolower(trim(app_config('app_environment', 'development')));
    if (!in_array($environment, ['development', 'dev', 'local', 'test'], true)) {
        return false;
    }
    try {
        $db->exec(app_portal_recovery_table_sql());
        return app_db_table_exists($db, 'portal_password_recovery_requests');
    } catch (Throwable) {
        return false;
    }
}

function app_portal_recovery_request(
    string $identifier,
    ?callable $sender = null,
    ?string $testCode = null
): array {
    app_session_boot();
    $db = app_db();
    $identifier = clean_login_identifier($identifier);
    $generic = ['accepted' => true, 'issued' => false, 'reason' => 'request_accepted'];
    if (!$db instanceof PDO || !app_portal_recovery_ensure_schema($db) || $identifier === '') {
        return $generic;
    }

    $ipScope = 'customer-password-recovery-ip:' . app_client_ip();
    if (app_rate_limit_is_blocked($ipScope, 3, 900)) {
        return $generic;
    }
    app_rate_limit_hit($ipScope, 900);

    $statement = $db->prepare(
        "SELECT * FROM portal_accounts
         WHERE role = 'customer' AND is_active = 1
           AND (login = :identifier OR subscriber_code = :identifier OR email = :identifier)
         LIMIT 1"
    );
    $statement->execute(['identifier' => $identifier]);
    $account = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($account)) {
        usleep(random_int(50000, 110000));
        return $generic;
    }

    $accountScope = 'customer-password-recovery-account:' . (int) $account['id'];
    if (app_rate_limit_is_blocked($accountScope, 3, 900)) {
        return $generic;
    }

    require_once __DIR__ . '/communication-provider.php';
    $destination = app_communication_whatsapp_normalize_destination((string) ($account['phone'] ?? ''));
    if (!preg_match('/^994\d{9}$/', $destination)) {
        return $generic;
    }

    $token = bin2hex(random_bytes(32));
    $code = $testCode !== null && preg_match('/^\d{6}$/', $testCode) === 1
        ? $testCode
        : (string) random_int(100000, 999999);
    $expiresAt = date('Y-m-d H:i:s', time() + 600);
    $db->beginTransaction();
    try {
        $invalidate = $db->prepare(
            'UPDATE portal_password_recovery_requests
                SET invalidated_at = NOW()
              WHERE account_id = :account_id AND consumed_at IS NULL AND invalidated_at IS NULL'
        );
        $invalidate->execute(['account_id' => (int) $account['id']]);
        $insert = $db->prepare(
            'INSERT INTO portal_password_recovery_requests (
                account_id, channel, token_hash, code_hash, destination_hash, requested_ip_hash,
                attempts, max_attempts, expires_at, metadata_json
             ) VALUES (
                :account_id, :channel, :token_hash, :code_hash, :destination_hash, :requested_ip_hash,
                0, 5, :expires_at, :metadata_json
             )'
        );
        $insert->execute([
            'account_id' => (int) $account['id'],
            'channel' => 'whatsapp',
            'token_hash' => hash('sha256', $token),
            'code_hash' => hash_hmac('sha256', $code, $token),
            'destination_hash' => hash('sha256', $destination),
            'requested_ip_hash' => hash('sha256', app_client_ip()),
            'expires_at' => $expiresAt,
            'metadata_json' => app_db_json_encode(['subscriber_code' => (string) ($account['subscriber_code'] ?? '')]),
        ]);
        $requestId = (int) $db->lastInsertId();
        $db->commit();
    } catch (Throwable) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return $generic;
    }

    $message = "Super Optik sifre yenileme kodunuz: {$code}. Kod 10 deqiqe etibarlidir. Bu kodu hec kimle paylasmayin.";
    $sendResult = $sender !== null
        ? $sender($destination, $message, 'password-recovery-' . $requestId)
        : app_communication_send_provider('whatsapp', $destination, $message, 'password-recovery-' . $requestId);
    if (!is_array($sendResult) || empty($sendResult['ok'])) {
        $failed = $db->prepare('UPDATE portal_password_recovery_requests SET invalidated_at = NOW() WHERE id = :id');
        $failed->execute(['id' => $requestId]);
        app_record_security_event([
            'event' => 'customer_password_recovery_delivery_failed',
            'status' => 'warning',
            'role' => 'customer',
            'login' => (string) ($account['subscriber_code'] ?? ''),
            'meta' => ['account_id' => (int) $account['id'], 'channel' => 'whatsapp'],
        ]);
        return $generic;
    }

    app_rate_limit_hit($accountScope, 900);
    $_SESSION['customer_password_recovery_token'] = $token;
    app_record_security_event([
        'event' => 'customer_password_recovery_code_sent',
        'status' => 'success',
        'role' => 'customer',
        'login' => (string) ($account['subscriber_code'] ?? ''),
        'meta' => ['account_id' => (int) $account['id'], 'channel' => 'whatsapp'],
    ]);

    return ['accepted' => true, 'issued' => true, 'reason' => '', 'expires_at' => $expiresAt];
}

function app_portal_recovery_password_valid(string $password): bool
{
    return strlen($password) >= 14
        && preg_match('/[a-z]/', $password) === 1
        && preg_match('/[A-Z]/', $password) === 1
        && preg_match('/\d/', $password) === 1
        && preg_match('/[^A-Za-z0-9]/', $password) === 1;
}

function app_portal_recovery_complete(string $code, string $password, string $confirmation): array
{
    app_session_boot();
    $db = app_db();
    $token = (string) ($_SESSION['customer_password_recovery_token'] ?? '');
    if (!$db instanceof PDO || $token === '' || !app_portal_recovery_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'recovery_session_invalid'];
    }
    if (!preg_match('/^\d{6}$/', $code)) {
        return ['ok' => false, 'reason' => 'recovery_code_invalid'];
    }
    if ($password !== $confirmation || !app_portal_recovery_password_valid($password)) {
        return ['ok' => false, 'reason' => 'password_policy_failed'];
    }

    try {
        $db->beginTransaction();
        $statement = $db->prepare(
            'SELECT r.*, a.login, a.full_name, a.subscriber_code, a.payload_json, a.role, a.is_active
             FROM portal_password_recovery_requests r
             INNER JOIN portal_accounts a ON a.id = r.account_id
             WHERE r.token_hash = :token_hash
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $request = $statement->fetch(PDO::FETCH_ASSOC);
        $eligible = is_array($request)
            && (string) ($request['role'] ?? '') === 'customer'
            && (int) ($request['is_active'] ?? 0) === 1
            && empty($request['consumed_at'])
            && empty($request['invalidated_at'])
            && strtotime((string) ($request['expires_at'] ?? '')) >= time()
            && (int) ($request['attempts'] ?? 0) < (int) ($request['max_attempts'] ?? 5);
        if (!$eligible) {
            throw new RuntimeException('recovery_request_expired');
        }

        $validCode = hash_equals((string) $request['code_hash'], hash_hmac('sha256', $code, $token));
        if (!$validCode) {
            $attempts = (int) $request['attempts'] + 1;
            $failed = $db->prepare(
                'UPDATE portal_password_recovery_requests
                    SET attempts = :attempts_value,
                        invalidated_at = CASE WHEN :attempts_limit >= max_attempts THEN NOW() ELSE invalidated_at END
                  WHERE id = :id'
            );
            $failed->execute([
                'attempts_value' => $attempts,
                'attempts_limit' => $attempts,
                'id' => (int) $request['id'],
            ]);
            $db->commit();
            return ['ok' => false, 'reason' => 'recovery_code_invalid'];
        }

        $payload = app_db_json_decode((string) ($request['payload_json'] ?? ''), []);
        $security = is_array($payload['security'] ?? null) ? $payload['security'] : [];
        $previousMustChange = !empty($security['must_change_password']);
        $security['must_change_password'] = false;
        $security['password_changed_at'] = date(DATE_ATOM);
        $security['password_recovery_method'] = 'whatsapp_otp';
        $payload['security'] = $security;
        $updateAccount = $db->prepare(
            'UPDATE portal_accounts SET password_hash = :password_hash, payload_json = :payload_json WHERE id = :id'
        );
        $updateAccount->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'payload_json' => app_db_json_encode($payload),
            'id' => (int) $request['account_id'],
        ]);
        $consume = $db->prepare(
            'UPDATE portal_password_recovery_requests
                SET consumed_at = NOW()
              WHERE account_id = :account_id AND consumed_at IS NULL AND invalidated_at IS NULL'
        );
        $consume->execute(['account_id' => (int) $request['account_id']]);

        app_audit_record($db, [
            'module_key' => 'security',
            'operation_class' => 'credential_recovery',
            'action_type' => 'customer_password_recovered_by_whatsapp',
            'entity_type' => 'portal_account',
            'entity_id' => (string) $request['account_id'],
            'status' => 'executed',
            'actor_account_id' => (int) $request['account_id'],
            'actor_name' => (string) ($request['full_name'] ?? $request['login'] ?? 'customer'),
            'summary' => 'Customer password recovered with registered WhatsApp OTP',
            'reason' => 'Registered WhatsApp number and one-time code verified',
            'before' => ['must_change_password' => $previousMustChange],
            'after' => ['must_change_password' => false, 'method' => 'whatsapp_otp'],
            'sensitivity' => 'critical',
        ]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'reason' => $exception->getMessage() === 'recovery_request_expired' ? 'recovery_request_expired' : 'recovery_failed'];
    }

    unset($_SESSION['customer_password_recovery_token']);
    return ['ok' => true, 'reason' => ''];
}

function app_portal_temporary_password(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $random = '';
    for ($index = 0; $index < 18; $index++) {
        $random .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'So!7a-' . $random;
}

function app_portal_privileged_password_reset(array $target, array $actor, string $reason, string $mode): array
{
    $db = app_db();
    $reason = clean_text($reason);
    $targetRole = (string) ($target['role'] ?? '');
    $actorIsEmployee = (string) ($actor['role'] ?? '') === 'employee';
    $allowed = match ($mode) {
        'customer_operator' => $targetRole === 'customer'
            && $actorIsEmployee
            && app_portal_can_access_module($actor, 'billing'),
        'staff_admin' => $targetRole === 'employee'
            && $actorIsEmployee
            && app_portal_is_admin($actor)
            && (!app_portal_is_super_admin($target) || app_portal_is_super_admin($actor)),
        default => false,
    };
    if (!$db instanceof PDO || !$allowed || $reason === '') {
        return ['ok' => false, 'reason' => !$allowed ? 'forbidden' : 'reason_required'];
    }

    $password = app_portal_temporary_password();
    $payload = app_db_account_payload($target);
    $security = is_array($payload['security'] ?? null) ? $payload['security'] : [];
    $previousMustChange = !empty($security['must_change_password']);
    $security['must_change_password'] = true;
    $security['password_reset_at'] = date(DATE_ATOM);
    $security['password_reset_method'] = $mode;
    $security['password_reset_by_account_id'] = (int) ($actor['id'] ?? 0);
    $payload['security'] = $security;

    app_audit_ensure_schema($db);
    try {
        $db->beginTransaction();
        $lockedTarget = $db->prepare('SELECT payload_json FROM portal_accounts WHERE id=? FOR UPDATE');
        $lockedTarget->execute([(int) $target['id']]);
        $freshPayload = json_decode((string) $lockedTarget->fetchColumn(), true);
        if (is_array($freshPayload)) {
            $freshSecurity = is_array($freshPayload['security'] ?? null) ? $freshPayload['security'] : [];
            $freshPayload['security'] = array_merge($freshSecurity, $security);
            $payload = $freshPayload;
        }
        $update = $db->prepare(
            'UPDATE portal_accounts
                SET password_hash = :password_hash, payload_json = :payload_json, updated_at = NOW()
              WHERE id = :id'
        );
        $update->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'payload_json' => app_db_json_encode($payload),
            'id' => (int) $target['id'],
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Staff password hash was not updated.');
        }
        if (app_db_table_exists($db, 'portal_password_recovery_requests')) {
            $invalidate = $db->prepare(
                'UPDATE portal_password_recovery_requests
                    SET invalidated_at = NOW()
                  WHERE account_id = :account_id AND consumed_at IS NULL AND invalidated_at IS NULL'
            );
            $invalidate->execute(['account_id' => (int) $target['id']]);
        }
        if (app_db_table_exists($db, 'mobile_auth_refresh_tokens')) {
            $db->prepare('DELETE FROM mobile_auth_refresh_tokens WHERE account_id = :account_id')
                ->execute(['account_id' => (int) $target['id']]);
        }
        if (app_db_table_exists($db, 'mobile_device_tokens')) {
            $db->prepare(
                "UPDATE mobile_device_tokens
                    SET is_active=0,revoked_at=NOW(),revoked_reason='password_reset'
                  WHERE account_id=:account_id AND is_active=1"
            )->execute(['account_id' => (int) $target['id']]);
        }
        if (app_db_table_exists($db, 'mobile_duty_sessions')) {
            $db->prepare(
                "UPDATE mobile_duty_sessions
                    SET ended_at=NOW(),ended_reason='password_reset'
                  WHERE account_id=:account_id AND ended_at IS NULL"
            )->execute(['account_id' => (int) $target['id']]);
        }

        $actionType = $mode === 'staff_admin'
            ? 'staff_password_reset_by_admin'
            : 'customer_password_reset_by_operator';
        app_audit_record($db, [
            'module_key' => 'security',
            'operation_class' => 'credential_recovery',
            'action_type' => $actionType,
            'entity_type' => 'portal_account',
            'entity_id' => (string) $target['id'],
            'status' => 'executed',
            'actor_account_id' => (int) ($actor['id'] ?? 0),
            'actor_name' => (string) ($actor['full_name'] ?? $actor['login'] ?? 'employee'),
            'summary' => $mode === 'staff_admin'
                ? 'Staff temporary password issued by admin'
                : 'Customer temporary password issued by operator',
            'reason' => $reason,
            'before' => ['must_change_password' => $previousMustChange, 'role' => $targetRole],
            'after' => ['must_change_password' => true, 'role' => $targetRole, 'reset_method' => $mode],
            'sensitivity' => 'critical',
        ]);
        $db->commit();
    } catch (Throwable) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'reason' => 'reset_failed'];
    }

    return ['ok' => true, 'password' => $password, 'login' => (string) ($target['login'] ?? '')];
}
