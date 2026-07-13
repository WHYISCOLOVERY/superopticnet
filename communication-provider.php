<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const APP_COMMUNICATION_SMS_POCTGOYERCINI_BASE_URL = 'https://api.poctgoyercini.com';
const APP_COMMUNICATION_SMS_POCTGOYERCINI_SEND_ENDPOINT = 'https://api.poctgoyercini.com/gateway/api/sms/v1/message/send';

function app_communication_runtime_setting(string $key, string $default = ''): string
{
    $db = app_db();
    if ($db instanceof PDO) {
        try {
            $statement = $db->prepare('SELECT setting_value FROM mgs_runtime_settings WHERE setting_key = :setting_key LIMIT 1');
            $statement->execute(['setting_key' => $key]);
            $value = $statement->fetchColumn();
            if (is_string($value)) {
                return $value;
            }
        } catch (Throwable) {
        }
    }

    return app_config($key, $default);
}

function app_communication_provider_config(string $channel, string $destination = ''): array
{
    $channel = strtolower(trim($channel));
    $destination = trim($destination);

    if ($channel === 'app') {
        return [
            'configured' => true,
            'delivery' => 'portal_inbox',
            'endpoint' => '',
            'token' => '',
            'sender' => 'SuperOptic',
            'destination' => $destination,
        ];
    }

    if ($channel === 'telegram') {
        $token = app_config('telegram_token');
        $chatId = $destination !== '' ? $destination : app_config('telegram_chat_id');

        return [
            'configured' => $token !== '' && $chatId !== '',
            'delivery' => 'telegram',
            'endpoint' => $token !== '' ? ('https://api.telegram.org/bot' . $token . '/sendMessage') : '',
            'token' => '',
            'sender' => 'SuperOptic',
            'destination' => $chatId,
        ];
    }

    if ($channel === 'sms') {
        $driver = strtolower(trim(app_communication_runtime_setting('sms_provider_driver', 'poctgoyercini_json')));
        if ($driver === '') {
            $driver = 'poctgoyercini_json';
        }
        $endpoint = app_communication_runtime_setting('sms_provider_endpoint', APP_COMMUNICATION_SMS_POCTGOYERCINI_SEND_ENDPOINT);
        $token = app_communication_runtime_setting('sms_provider_token');
        $user = app_communication_runtime_setting('sms_provider_user');
        $password = app_communication_runtime_setting('sms_provider_password');
        $credentialReady = match ($driver) {
            'poctgoyercini_json', 'poctgoyercini_xml' => $user !== '' && $password !== '',
            default => $token !== '' || ($user !== '' && $password !== '') || $driver === 'json',
        };

        return [
            'configured' => $endpoint !== '' && $destination !== '' && $credentialReady,
            'delivery' => 'sms_provider',
            'driver' => $driver,
            'endpoint' => $endpoint,
            'token' => $token,
            'user' => $user,
            'password' => $password,
            'sender' => app_communication_runtime_setting('sms_provider_sender', 'SuperOptic'),
            'purpose' => app_communication_runtime_setting('sms_provider_purpose', 'INF'),
            'encoding' => app_communication_runtime_setting('sms_provider_encoding', 'LATIN'),
            'destination' => $destination,
        ];
    }

    if ($channel === 'whatsapp') {
        $enabled = in_array(strtolower((string) app_setting('whatsapp_provider_enabled', '0')), ['1', 'true', 'yes', 'on'], true);

        return [
            'configured' => $enabled && app_config('whatsapp_provider_endpoint') !== '' && app_config('whatsapp_provider_token') !== '' && $destination !== '',
            'delivery' => 'whatsapp_web_bridge',
            'endpoint' => app_config('whatsapp_provider_endpoint'),
            'token' => app_config('whatsapp_provider_token'),
            'sender' => app_config('whatsapp_provider_sender', 'SuperOptic'),
            'destination' => $destination,
        ];
    }

    return [
        'configured' => false,
        'delivery' => 'unsupported',
        'endpoint' => '',
        'token' => '',
        'sender' => '',
        'destination' => $destination,
    ];
}

function app_communication_provider_public_status(string $channel, string $destination = ''): array
{
    $provider = app_communication_provider_config($channel, $destination);
    $hasToken = (string) ($provider['token'] ?? '') !== '';
    $hasUserPassword = (string) ($provider['user'] ?? '') !== '' && (string) ($provider['password'] ?? '') !== '';
    $bridgeStatus = strtolower(trim($channel)) === 'whatsapp'
        ? app_communication_whatsapp_bridge_status($provider)
        : null;

    $status = [
        'channel' => strtolower(trim($channel)),
        'configured' => (bool) ($provider['configured'] ?? false),
        'delivery' => (string) ($provider['delivery'] ?? 'unsupported'),
        'driver' => (string) ($provider['driver'] ?? ''),
        'has_endpoint' => (string) ($provider['endpoint'] ?? '') !== '',
        'has_token' => $hasToken,
        'has_credentials' => $hasToken || $hasUserPassword,
        'has_destination' => (string) ($provider['destination'] ?? '') !== '',
        'sender' => (string) ($provider['sender'] ?? ''),
    ];

    if ($bridgeStatus !== null) {
        $status['bridge'] = $bridgeStatus;
    }

    return $status;
}

function app_communication_send_provider(string $channel, string $destination, string $body, string $externalId = ''): array
{
    $channel = strtolower(trim($channel));
    $provider = app_communication_provider_config($channel, $destination);
    $body = trim($body);

    if (empty($provider['configured'])) {
        return [
            'ok' => false,
            'provider_message_id' => null,
            'response' => null,
            'reason' => 'provider_not_configured',
            'delivery' => (string) ($provider['delivery'] ?? 'unsupported'),
        ];
    }

    if ($body === '') {
        return [
            'ok' => false,
            'provider_message_id' => null,
            'response' => null,
            'reason' => 'empty_message',
            'delivery' => (string) ($provider['delivery'] ?? $channel),
        ];
    }

    if ($channel === 'app') {
        return [
            'ok' => true,
            'provider_message_id' => null,
            'response' => ['delivery' => 'portal_inbox'],
            'reason' => '',
            'delivery' => 'portal_inbox',
        ];
    }

    if ($channel === 'telegram') {
        $response = http_post_json((string) $provider['endpoint'], [
            'chat_id' => (string) $provider['destination'],
            'text' => $body,
            'disable_web_page_preview' => true,
        ]);

        return [
            'ok' => is_array($response) && !empty($response['ok']),
            'provider_message_id' => $response['result']['message_id'] ?? null,
            'response' => is_array($response) ? $response : null,
            'reason' => is_array($response) && empty($response['ok']) ? (string) ($response['description'] ?? 'provider_rejected') : '',
            'delivery' => 'telegram',
        ];
    }

    if ($channel === 'sms' && (string) ($provider['driver'] ?? '') === 'poctgoyercini_json') {
        return app_communication_send_poctgoyercini_json_sms($provider, $body, $externalId);
    }

    if ($channel === 'sms' && (string) ($provider['driver'] ?? '') === 'poctgoyercini_xml') {
        return app_communication_send_poctgoyercini_sms($provider, $body);
    }

    if ($channel === 'whatsapp') {
        return app_communication_send_whatsapp_bridge($provider, $body, $externalId);
    }

    $headers = ['Content-Type: application/json'];
    if ((string) ($provider['token'] ?? '') !== '') {
        $headers[] = 'Authorization: Bearer ' . (string) $provider['token'];
    }

    $response = http_post_json((string) $provider['endpoint'], [
        'to' => (string) $provider['destination'],
        'message' => $body,
        'sender' => (string) ($provider['sender'] ?? 'SuperOptic'),
        'external_id' => $externalId,
        'channel' => $channel,
    ], $headers);

    $ok = is_array($response)
        && (
            !empty($response['ok'])
            || !empty($response['success'])
            || strtolower((string) ($response['status'] ?? '')) === 'sent'
            || strtolower((string) ($response['status'] ?? '')) === 'ok'
        );

    return [
        'ok' => $ok,
        'provider_message_id' => $response['message_id'] ?? $response['id'] ?? null,
        'response' => is_array($response) ? $response : null,
        'reason' => $ok ? '' : (is_array($response) ? (string) ($response['error'] ?? $response['message'] ?? 'provider_request_failed') : 'provider_request_failed'),
        'delivery' => (string) ($provider['delivery'] ?? $channel),
    ];
}

function app_communication_whatsapp_bridge_status(array $provider = []): array
{
    if ($provider === []) {
        $provider = app_communication_provider_config('whatsapp', '994000000000');
    }

    $endpoint = (string) ($provider['endpoint'] ?? app_config('whatsapp_provider_endpoint'));
    $token = (string) ($provider['token'] ?? app_config('whatsapp_provider_token'));
    if ($endpoint === '' || $token === '') {
        return [
            'reachable' => false,
            'connected' => false,
            'status' => 'not_configured',
            'reason' => 'endpoint_or_token_missing',
        ];
    }

    $statusUrl = preg_replace('#/api/send$#', '/api/status', $endpoint) ?? $endpoint;
    if ($statusUrl === $endpoint) {
        $statusUrl = rtrim($endpoint, '/') . '/api/status';
    }

    $response = http_request_raw($statusUrl, 'GET', '', ['x-api-key: ' . $token], 2);
    $body = trim((string) ($response['body'] ?? ''));
    $decoded = json_decode($body, true);

    if (empty($response['ok']) || !is_array($decoded)) {
        return [
            'reachable' => false,
            'connected' => false,
            'status' => 'unreachable',
            'reason' => 'http_' . (string) ($response['status'] ?? 0),
        ];
    }

    return [
        'reachable' => true,
        'connected' => !empty($decoded['connected']),
        'status' => (string) ($decoded['status'] ?? (!empty($decoded['connected']) ? 'connected' : 'loading')),
        'ready_at' => (string) ($decoded['ready_at'] ?? ''),
        'last_qr_at' => (string) ($decoded['last_qr_at'] ?? ''),
        'last_error' => (string) ($decoded['last_error'] ?? ''),
    ];
}

function app_communication_whatsapp_bridge_qr(): array
{
    $provider = app_communication_provider_config('whatsapp', '994000000000');
    $endpoint = (string) ($provider['endpoint'] ?? '');
    $token = (string) ($provider['token'] ?? '');
    if ($endpoint === '' || $token === '') {
        return ['ok' => false, 'status' => 'not_configured', 'qr' => '', 'reason' => 'endpoint_or_token_missing'];
    }

    $qrUrl = preg_replace('#/api/send$#', '/api/qr', $endpoint) ?? $endpoint;
    if ($qrUrl === $endpoint) {
        $qrUrl = rtrim($endpoint, '/') . '/api/qr';
    }

    $response = http_request_raw($qrUrl, 'GET', '', ['x-api-key: ' . $token], 2);
    $decoded = json_decode(trim((string) ($response['body'] ?? '')), true);
    if (empty($response['ok']) || !is_array($decoded)) {
        return ['ok' => false, 'status' => 'unreachable', 'qr' => '', 'reason' => 'http_' . (string) ($response['status'] ?? 0)];
    }

    return [
        'ok' => !empty($decoded['ok']),
        'status' => (string) ($decoded['status'] ?? ''),
        'connected' => !empty($decoded['connected']),
        'qr' => (string) ($decoded['qr'] ?? ''),
        'reason' => (string) ($decoded['error'] ?? ''),
    ];
}

function app_communication_send_whatsapp_bridge(array $provider, string $body, string $externalId = ''): array
{
    $destination = (string) ($provider['destination'] ?? '');
    $guard = app_communication_whatsapp_guard_check($destination);
    if (empty($guard['ok'])) {
        return [
            'ok' => false,
            'provider_message_id' => null,
            'response' => ['guard' => $guard],
            'reason' => (string) ($guard['reason'] ?? 'whatsapp_rate_limited'),
            'delivery' => 'whatsapp_web_bridge',
        ];
    }

    $headers = ['Content-Type: application/json'];
    if ((string) ($provider['token'] ?? '') !== '') {
        $headers[] = 'x-api-key: ' . (string) $provider['token'];
    }

    $response = http_post_json((string) ($provider['endpoint'] ?? ''), [
        'number' => $destination,
        'message' => $body,
        'external_id' => $externalId,
        'sender' => (string) ($provider['sender'] ?? 'SuperOptic'),
    ], $headers);

    $ok = is_array($response) && (!empty($response['ok']) || !empty($response['success']));
    if ($ok) {
        app_communication_whatsapp_guard_record_sent($destination, !empty($guard['known_contact']));
    }

    return [
        'ok' => $ok,
        'provider_message_id' => $response['message_id'] ?? $response['id'] ?? null,
        'response' => is_array($response) ? $response : null,
        'reason' => $ok ? '' : (is_array($response) ? (string) ($response['error'] ?? $response['message'] ?? 'whatsapp_bridge_request_failed') : 'whatsapp_bridge_request_failed'),
        'delivery' => 'whatsapp_web_bridge',
    ];
}

function app_communication_whatsapp_normalize_destination(string $destination): string
{
    $digits = preg_replace('/\D+/', '', $destination) ?? '';
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
        $digits = '994' . substr($digits, 1);
    }
    if (strlen($digits) === 9 && preg_match('/^[457]\d{8}$/', $digits) === 1) {
        $digits = '994' . $digits;
    }

    return $digits;
}

function app_communication_whatsapp_guard_limits(): array
{
    $read = static function (string $key, string $default): string {
        $db = app_db();
        if ($db instanceof PDO) {
            try {
                $statement = $db->prepare('SELECT setting_value FROM mgs_runtime_settings WHERE setting_key = :setting_key LIMIT 1');
                $statement->execute(['setting_key' => $key]);
                $value = $statement->fetchColumn();
                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            } catch (Throwable) {
            }
        }

        return (string) app_setting($key, $default);
    };

    return [
        'unknown_daily_limit' => max(1, (int) $read('whatsapp_unknown_daily_limit', '5')),
        'known_daily_limit' => max(1, (int) $read('whatsapp_known_daily_limit', '60')),
        'unknown_min_gap_sec' => max(60, (int) $read('whatsapp_unknown_min_gap_sec', '900')),
        'known_min_gap_sec' => max(30, (int) $read('whatsapp_known_min_gap_sec', '120')),
        'global_daily_limit' => max(1, (int) $read('whatsapp_global_daily_limit', '80')),
        'global_hourly_limit' => max(1, (int) $read('whatsapp_global_hourly_limit', '10')),
        'global_min_gap_sec' => max(30, (int) $read('whatsapp_global_min_gap_sec', '90')),
    ];
}

function app_communication_whatsapp_guard_schema(PDO $db): void
{
    $db->exec(
        'CREATE TABLE IF NOT EXISTS whatsapp_delivery_guard (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            destination_hash CHAR(64) NOT NULL UNIQUE,
            known_contact TINYINT(1) NOT NULL DEFAULT 0,
            sent_24h INT NOT NULL DEFAULT 0,
            sent_total INT NOT NULL DEFAULT 0,
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_window_at DATETIME NULL,
            last_sent_at DATETIME NULL,
            blocked_until DATETIME NULL,
            note VARCHAR(255) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_whatsapp_guard_blocked (blocked_until),
            KEY idx_whatsapp_guard_last_sent (last_sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS whatsapp_delivery_guard_global (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            sent_24h INT NOT NULL DEFAULT 0,
            sent_hour INT NOT NULL DEFAULT 0,
            last_day_window_at DATETIME NULL,
            last_hour_window_at DATETIME NULL,
            last_sent_at DATETIME NULL,
            blocked_until DATETIME NULL,
            note VARCHAR(255) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function app_communication_whatsapp_guard_check(string $destination): array
{
    $normalized = app_communication_whatsapp_normalize_destination($destination);
    if ($normalized === '') {
        return ['ok' => false, 'reason' => 'whatsapp_empty_destination'];
    }

    $db = app_db();
    if (!$db instanceof PDO) {
        return ['ok' => false, 'reason' => 'whatsapp_guard_db_unavailable'];
    }

    app_communication_whatsapp_guard_schema($db);
    $hash = hash('sha256', $normalized);
    $limits = app_communication_whatsapp_guard_limits();
    $now = (int) ($db->query('SELECT UNIX_TIMESTAMP(NOW())')->fetchColumn() ?: time());

    $db->beginTransaction();
    try {
        $insert = $db->prepare(
            'INSERT IGNORE INTO whatsapp_delivery_guard (destination_hash, first_seen_at, last_window_at)
             VALUES (:destination_hash, NOW(), NOW())'
        );
        $insert->execute(['destination_hash' => $hash]);

        $select = $db->prepare(
            'SELECT *,
                UNIX_TIMESTAMP(last_window_at) AS last_window_ts,
                UNIX_TIMESTAMP(last_sent_at) AS last_sent_ts,
                UNIX_TIMESTAMP(blocked_until) AS blocked_until_ts
             FROM whatsapp_delivery_guard
             WHERE destination_hash = :destination_hash
             FOR UPDATE'
        );
        $select->execute(['destination_hash' => $hash]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $db->rollBack();
            return ['ok' => false, 'reason' => 'whatsapp_guard_row_missing'];
        }

        $windowAt = (int) ($row['last_window_ts'] ?? 0);
        if ($windowAt <= 0 || ($now - $windowAt) >= 86400) {
            $reset = $db->prepare(
                'UPDATE whatsapp_delivery_guard
                 SET sent_24h = 0, last_window_at = NOW()
                 WHERE destination_hash = :destination_hash'
            );
            $reset->execute(['destination_hash' => $hash]);
            $row['sent_24h'] = 0;
            $row['last_window_ts'] = $now;
        }

        $known = ((int) ($row['known_contact'] ?? 0)) === 1 || ((int) ($row['sent_total'] ?? 0)) > 0;
        $dailyLimit = $known ? $limits['known_daily_limit'] : $limits['unknown_daily_limit'];
        $minGap = $known ? $limits['known_min_gap_sec'] : $limits['unknown_min_gap_sec'];
        $sent24h = (int) ($row['sent_24h'] ?? 0);
        $lastSentAt = (int) ($row['last_sent_ts'] ?? 0);
        $blockedUntil = (int) ($row['blocked_until_ts'] ?? 0);

        if ($blockedUntil > $now) {
            $db->commit();
            return [
                'ok' => false,
                'reason' => 'whatsapp_rate_limited_blocked_until',
                'known_contact' => $known,
                'wait_seconds' => $blockedUntil - $now,
                'limit' => $dailyLimit,
                'sent_24h' => $sent24h,
            ];
        }

        if ($sent24h >= $dailyLimit) {
            $until = $now + 3600;
            $block = $db->prepare(
                'UPDATE whatsapp_delivery_guard
                 SET blocked_until = FROM_UNIXTIME(:blocked_until), note = :note
                 WHERE destination_hash = :destination_hash'
            );
            $block->execute([
                'blocked_until' => $until,
                'note' => 'daily limit reached',
                'destination_hash' => $hash,
            ]);
            $db->commit();
            return [
                'ok' => false,
                'reason' => 'whatsapp_rate_limited_daily',
                'known_contact' => $known,
                'wait_seconds' => 3600,
                'limit' => $dailyLimit,
                'sent_24h' => $sent24h,
            ];
        }

        if ($lastSentAt > 0 && ($now - $lastSentAt) < $minGap) {
            $db->commit();
            return [
                'ok' => false,
                'reason' => 'whatsapp_rate_limited_gap',
                'known_contact' => $known,
                'wait_seconds' => $minGap - ($now - $lastSentAt),
                'limit' => $dailyLimit,
                'sent_24h' => $sent24h,
            ];
        }

        $global = app_communication_whatsapp_global_guard_check_locked($db, $limits, $now);
        if (empty($global['ok'])) {
            $db->commit();
            return $global + [
                'known_contact' => $known,
                'limit' => $dailyLimit,
                'sent_24h' => $sent24h,
            ];
        }

        $db->commit();
        return [
            'ok' => true,
            'reason' => '',
            'known_contact' => $known,
            'limit' => $dailyLimit,
            'sent_24h' => $sent24h,
        ];
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'reason' => 'whatsapp_guard_error:' . $exception->getMessage()];
    }
}

function app_communication_whatsapp_global_guard_check_locked(PDO $db, array $limits, int $now): array
{
    $db->exec(
        'INSERT IGNORE INTO whatsapp_delivery_guard_global (
            id, sent_24h, sent_hour, last_day_window_at, last_hour_window_at
         ) VALUES (1, 0, 0, NOW(), NOW())'
    );

    $row = $db->query(
        'SELECT *,
            UNIX_TIMESTAMP(last_day_window_at) AS day_window_ts,
            UNIX_TIMESTAMP(last_hour_window_at) AS hour_window_ts,
            UNIX_TIMESTAMP(last_sent_at) AS last_sent_ts,
            UNIX_TIMESTAMP(blocked_until) AS blocked_until_ts
         FROM whatsapp_delivery_guard_global
         WHERE id = 1
         FOR UPDATE'
    )->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['ok' => false, 'reason' => 'whatsapp_global_guard_row_missing'];
    }

    $dayWindow = (int) ($row['day_window_ts'] ?? 0);
    if ($dayWindow <= 0 || ($now - $dayWindow) >= 86400) {
        $db->exec('UPDATE whatsapp_delivery_guard_global SET sent_24h = 0, last_day_window_at = NOW() WHERE id = 1');
        $row['sent_24h'] = 0;
    }

    $hourWindow = (int) ($row['hour_window_ts'] ?? 0);
    if ($hourWindow <= 0 || ($now - $hourWindow) >= 3600) {
        $db->exec('UPDATE whatsapp_delivery_guard_global SET sent_hour = 0, last_hour_window_at = NOW() WHERE id = 1');
        $row['sent_hour'] = 0;
    }

    $blockedUntil = (int) ($row['blocked_until_ts'] ?? 0);
    if ($blockedUntil > $now) {
        return [
            'ok' => false,
            'reason' => 'whatsapp_rate_limited_global_blocked_until',
            'wait_seconds' => $blockedUntil - $now,
            'global_sent_24h' => (int) ($row['sent_24h'] ?? 0),
            'global_sent_hour' => (int) ($row['sent_hour'] ?? 0),
            'global_daily_limit' => (int) ($limits['global_daily_limit'] ?? 80),
            'global_hourly_limit' => (int) ($limits['global_hourly_limit'] ?? 10),
        ];
    }

    $sent24h = (int) ($row['sent_24h'] ?? 0);
    $sentHour = (int) ($row['sent_hour'] ?? 0);
    $globalDaily = (int) ($limits['global_daily_limit'] ?? 80);
    $globalHourly = (int) ($limits['global_hourly_limit'] ?? 10);
    if ($sent24h >= $globalDaily) {
        $until = $now + 3600;
        $statement = $db->prepare('UPDATE whatsapp_delivery_guard_global SET blocked_until = FROM_UNIXTIME(:until), note = "global daily limit reached" WHERE id = 1');
        $statement->execute(['until' => $until]);
        return [
            'ok' => false,
            'reason' => 'whatsapp_rate_limited_global_daily',
            'wait_seconds' => 3600,
            'global_sent_24h' => $sent24h,
            'global_sent_hour' => $sentHour,
            'global_daily_limit' => $globalDaily,
            'global_hourly_limit' => $globalHourly,
        ];
    }

    if ($sentHour >= $globalHourly) {
        $until = $now + 900;
        $statement = $db->prepare('UPDATE whatsapp_delivery_guard_global SET blocked_until = FROM_UNIXTIME(:until), note = "global hourly limit reached" WHERE id = 1');
        $statement->execute(['until' => $until]);
        return [
            'ok' => false,
            'reason' => 'whatsapp_rate_limited_global_hourly',
            'wait_seconds' => 900,
            'global_sent_24h' => $sent24h,
            'global_sent_hour' => $sentHour,
            'global_daily_limit' => $globalDaily,
            'global_hourly_limit' => $globalHourly,
        ];
    }

    $lastSentAt = (int) ($row['last_sent_ts'] ?? 0);
    $globalGap = (int) ($limits['global_min_gap_sec'] ?? 90);
    if ($lastSentAt > 0 && ($now - $lastSentAt) < $globalGap) {
        return [
            'ok' => false,
            'reason' => 'whatsapp_rate_limited_global_gap',
            'wait_seconds' => $globalGap - ($now - $lastSentAt),
            'global_sent_24h' => $sent24h,
            'global_sent_hour' => $sentHour,
            'global_daily_limit' => $globalDaily,
            'global_hourly_limit' => $globalHourly,
        ];
    }

    return [
        'ok' => true,
        'reason' => '',
        'global_sent_24h' => $sent24h,
        'global_sent_hour' => $sentHour,
        'global_daily_limit' => $globalDaily,
        'global_hourly_limit' => $globalHourly,
    ];
}

function app_communication_whatsapp_guard_record_sent(string $destination, bool $knownContact): void
{
    $normalized = app_communication_whatsapp_normalize_destination($destination);
    if ($normalized === '') {
        return;
    }

    $db = app_db();
    if (!$db instanceof PDO) {
        return;
    }

    app_communication_whatsapp_guard_schema($db);
    $hash = hash('sha256', $normalized);
    $db->beginTransaction();
    try {
        $statement = $db->prepare(
        'INSERT INTO whatsapp_delivery_guard (
            destination_hash, known_contact, sent_24h, sent_total, first_seen_at, last_window_at, last_sent_at, note
         ) VALUES (
            :destination_hash, 1, 1, 1, NOW(), NOW(), NOW(), :note
         )
         ON DUPLICATE KEY UPDATE
            known_contact = 1,
            sent_24h = IF(last_window_at IS NULL OR TIMESTAMPDIFF(SECOND, last_window_at, NOW()) >= 86400, 1, sent_24h + 1),
            sent_total = sent_total + 1,
            last_window_at = IF(last_window_at IS NULL OR TIMESTAMPDIFF(SECOND, last_window_at, NOW()) >= 86400, NOW(), last_window_at),
            last_sent_at = NOW(),
            blocked_until = NULL,
            note = :note'
        );
        $statement->execute([
            'destination_hash' => $hash,
            'note' => $knownContact ? 'known contact sent' : 'first successful contact sent',
        ]);
        $db->exec(
            'INSERT INTO whatsapp_delivery_guard_global (
            id, sent_24h, sent_hour, last_day_window_at, last_hour_window_at, last_sent_at
         ) VALUES (
            1, 1, 1, NOW(), NOW(), NOW()
         )
         ON DUPLICATE KEY UPDATE
            sent_24h = IF(last_day_window_at IS NULL OR TIMESTAMPDIFF(SECOND, last_day_window_at, NOW()) >= 86400, 1, sent_24h + 1),
            sent_hour = IF(last_hour_window_at IS NULL OR TIMESTAMPDIFF(SECOND, last_hour_window_at, NOW()) >= 3600, 1, sent_hour + 1),
            last_day_window_at = IF(last_day_window_at IS NULL OR TIMESTAMPDIFF(SECOND, last_day_window_at, NOW()) >= 86400, NOW(), last_day_window_at),
            last_hour_window_at = IF(last_hour_window_at IS NULL OR TIMESTAMPDIFF(SECOND, last_hour_window_at, NOW()) >= 3600, NOW(), last_hour_window_at),
            last_sent_at = NOW(),
            blocked_until = NULL,
            note = "whatsapp sent"'
        );
        $db->commit();
    } catch (Throwable) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

function app_communication_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function app_communication_normalize_sms_destination(string $destination): string
{
    $digits = preg_replace('/\D+/', '', $destination) ?? '';
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
        $digits = '994' . substr($digits, 1);
    }
    if (strlen($digits) === 9 && preg_match('/^[457]\d{8}$/', $digits) === 1) {
        $digits = '994' . $digits;
    }

    return $digits;
}

function app_communication_safe_provider_text(string $value): string
{
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_check_encoding') && function_exists('mb_convert_encoding')) {
        if (@mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1251,Windows-1252,ISO-8859-1');
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }
    }

    $json = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
    if (is_string($json)) {
        $decoded = json_decode($json, true);
        if (is_string($decoded)) {
            return $decoded;
        }
    }

    return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $value) ?? '';
}

function app_communication_poctgoyercini_send_url(string $endpoint, string $publicKey): string
{
    $url = trim($endpoint) !== '' ? trim($endpoint) : APP_COMMUNICATION_SMS_POCTGOYERCINI_SEND_ENDPOINT;
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }

    $path = parse_url($url, PHP_URL_PATH);
    if ($path === null || $path === '' || $path === '/') {
        $url = rtrim($url, '/') . '/gateway/api/sms/v1/message/send';
    }

    if (!preg_match('/(?:\?|&)publicKey=/i', $url)) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'publicKey=' . rawurlencode($publicKey);
    }

    return $url;
}

function app_communication_send_poctgoyercini_json_sms(array $provider, string $body, string $externalId = ''): array
{
    $destination = app_communication_normalize_sms_destination((string) ($provider['destination'] ?? ''));
    if ($destination === '') {
        return [
            'ok' => false,
            'provider_message_id' => null,
            'response' => null,
            'reason' => 'empty_sms_destination',
            'delivery' => 'sms_provider',
        ];
    }

    $publicKey = trim((string) ($provider['user'] ?? ''));
    $privateKey = trim((string) ($provider['password'] ?? ''));
    if ($publicKey === '' || $privateKey === '') {
        return [
            'ok' => false,
            'provider_message_id' => null,
            'response' => null,
            'reason' => 'sms_provider_credentials_missing',
            'delivery' => 'sms_provider',
        ];
    }

    $purpose = strtoupper(trim((string) ($provider['purpose'] ?? 'INF')));
    if (!in_array($purpose, ['OTP', 'INF', 'ADV'], true)) {
        $purpose = 'INF';
    }
    $encoding = strtoupper(trim((string) ($provider['encoding'] ?? 'LATIN')));
    if (!in_array($encoding, ['LATIN', 'TURKISH', 'UNICODE'], true)) {
        $encoding = 'LATIN';
    }

    $reportLabel = trim($externalId) !== '' ? trim($externalId) : 'SuperOptic';
    $options = [
        'Encoding' => $encoding,
        'SmsType' => 'SMS',
        'ReportLabel' => mb_substr($reportLabel, 0, 100),
    ];
    $sender = trim((string) ($provider['sender'] ?? ''));
    if ($sender !== '') {
        $options['Originator'] = $sender;
    }

    $payload = [
        'Text' => mb_substr($body, 0, 1000),
        'Purpose' => $purpose,
        'Options' => $options,
        'Receivers' => [
            ['Receiver' => $destination],
        ],
    ];

    $response = http_post_json(
        app_communication_poctgoyercini_send_url((string) ($provider['endpoint'] ?? ''), $publicKey),
        $payload,
        [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $privateKey,
        ]
    );

    $result = is_array($response['Result'] ?? null) ? $response['Result'] : [];
    $accepted = is_array($result['ReceiversAccepted'] ?? null) ? $result['ReceiversAccepted'] : [];
    $rejected = is_array($result['ReceiversRejected'] ?? null) ? $result['ReceiversRejected'] : [];
    $status = is_array($response) ? (int) ($response['Status'] ?? 0) : 0;
    $ok = is_array($response) && $status === 200 && $accepted !== [];
    $firstAccepted = is_array($accepted[0] ?? null) ? $accepted[0] : [];
    $firstRejected = is_array($rejected[0] ?? null) ? $rejected[0] : [];

    $reason = '';
    if (!$ok) {
        $reason = match (true) {
            !is_array($response) => 'sms_provider_request_failed',
            $status !== 200 => (string) ($response['Description'] ?? ('sms_provider_status_' . $status)),
            $firstRejected !== [] => trim((string) ($firstRejected['ErrorCode'] ?? '') . ':' . (string) ($firstRejected['ErrorMessage'] ?? ''), ':'),
            default => 'sms_provider_no_accepted_receivers',
        };
    }

    return [
        'ok' => $ok,
        'provider_message_id' => $firstAccepted['id'] ?? null,
        'response' => is_array($response) ? $response : null,
        'reason' => $reason,
        'delivery' => 'sms_provider',
    ];
}

function app_communication_send_poctgoyercini_sms(array $provider, string $body): array
{
    $destination = app_communication_normalize_sms_destination((string) ($provider['destination'] ?? ''));
    if ($destination === '') {
        return [
            'ok' => false,
            'provider_message_id' => null,
            'response' => null,
            'reason' => 'empty_sms_destination',
            'delivery' => 'sms_provider',
        ];
    }

    $xml = '<SMS-InsRequest>'
        . '<CLIENT user="' . app_communication_xml_escape((string) ($provider['user'] ?? '')) . '" pwd="' . app_communication_xml_escape((string) ($provider['password'] ?? '')) . '" />'
        . '<INSERTMSG text="' . app_communication_xml_escape($body) . '" dt="' . date('Y/m/d H:i') . '">'
        . '<TO>' . app_communication_xml_escape($destination) . '</TO>'
        . '</INSERTMSG>'
        . '</SMS-InsRequest>';

    $response = http_request_raw(
        (string) ($provider['endpoint'] ?? ''),
        'POST',
        $xml,
        [],
        20
    );
    $responseBody = trim(app_communication_safe_provider_text((string) ($response['body'] ?? '')));
    $lowerBody = strtolower($responseBody);
    $ok = !empty($response['ok'])
        && $responseBody !== ''
        && !str_contains($lowerBody, 'error')
        && !str_contains($lowerBody, 'failed')
        && !str_contains($lowerBody, 'invalid')
        && !str_contains($lowerBody, 'denied');

    return [
        'ok' => $ok,
        'provider_message_id' => app_communication_poctgoyercini_message_id($responseBody),
        'response' => [
            'status' => (int) ($response['status'] ?? 0),
            'body_preview' => mb_substr($responseBody, 0, 500),
        ],
        'reason' => $ok ? '' : ('sms_provider_rejected_or_unexpected_response_http_' . (string) ($response['status'] ?? 0)),
        'delivery' => 'sms_provider',
    ];
}

function app_communication_poctgoyercini_message_id(string $responseBody): ?string
{
    if (preg_match('/(?:id|msgid|messageid)[^0-9A-Za-z_-]*([0-9A-Za-z_-]+)/i', $responseBody, $match) === 1) {
        return (string) $match[1];
    }

    return null;
}
