<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const APP_COMMUNICATION_SMS_PG365_BASE_URL = 'https://api.poctgoyercini.com';
const APP_COMMUNICATION_SMS_PG365_HOST = 'api.poctgoyercini.com';
const APP_COMMUNICATION_SMS_PG365_SEND_ENDPOINT = APP_COMMUNICATION_SMS_PG365_BASE_URL . '/gateway/api/sms/v1/message/send';
const APP_COMMUNICATION_SMS_POCTGOYERCINI_BASE_URL = APP_COMMUNICATION_SMS_PG365_BASE_URL;
const APP_COMMUNICATION_SMS_POCTGOYERCINI_SEND_ENDPOINT = APP_COMMUNICATION_SMS_PG365_SEND_ENDPOINT;
const APP_COMMUNICATION_TELEGRAM_BASE_URL = 'https://api.telegram.org';

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

function app_communication_environment_secret(string $key): string
{
    return app_environment_secret($key);
}

function app_communication_environment_secret_ready(string $key): bool
{
    return app_communication_environment_secret($key) !== '';
}

function app_communication_enabled_setting_value(string $value, bool $default = true): bool
{
    $value = strtolower(trim($value));
    if ($value === '') {
        return $default;
    }

    return match ($value) {
        '1', 'true', 'yes', 'on', 'enabled' => true,
        '0', 'false', 'no', 'off', 'disabled' => false,
        default => $default,
    };
}

function app_communication_external_delivery_enabled(): bool
{
    $environmentValue = getenv('COMMUNICATION_EXTERNAL_SEND_ENABLED');
    $value = is_string($environmentValue) && trim($environmentValue) !== ''
        ? $environmentValue
        : app_config('communication_external_send_enabled', '0');

    return app_communication_enabled_setting_value((string) $value, false);
}

function app_communication_channel_enabled(string $channel): bool
{
    $settings = [
        'app' => 'channel_app_enabled',
        'telegram' => 'channel_telegram_enabled',
        'sms' => 'channel_sms_enabled',
        'whatsapp' => 'channel_whatsapp_enabled',
    ];
    $channel = strtolower(trim($channel));
    if (!isset($settings[$channel])) {
        return false;
    }

    return app_communication_enabled_setting_value(
        app_communication_runtime_setting($settings[$channel], '1'),
        true
    );
}

function app_communication_provider_config(string $channel, string $destination = ''): array
{
    $channel = strtolower(trim($channel));
    $destination = trim($destination);
    $channelEnabled = app_communication_channel_enabled($channel);
    $externalDeliveryEnabled = app_communication_external_delivery_enabled();

    if ($channel === 'app') {
        return [
            'enabled' => $channelEnabled,
            'configured' => $channelEnabled,
            'send_enabled' => $channelEnabled,
            'external_delivery_enabled' => true,
            'delivery' => 'portal_inbox',
            'endpoint' => '',
            'credentials_ready' => true,
            'credential_envs' => [],
            'sender' => 'SuperOptic',
            'destination' => $destination,
            'reason' => $channelEnabled ? '' : 'channel_disabled',
        ];
    }

    if ($channel === 'telegram') {
        $credentialReady = app_communication_environment_secret_ready('TELEGRAM_TOKEN');
        $chatId = $destination !== '' ? $destination : app_config('telegram_chat_id');

        return [
            'enabled' => $channelEnabled,
            'configured' => $channelEnabled && $externalDeliveryEnabled && $credentialReady && $chatId !== '',
            'send_enabled' => $channelEnabled && $externalDeliveryEnabled,
            'external_delivery_enabled' => $externalDeliveryEnabled,
            'delivery' => 'telegram',
            'endpoint' => APP_COMMUNICATION_TELEGRAM_BASE_URL,
            'credentials_ready' => $credentialReady,
            'credential_envs' => ['TELEGRAM_TOKEN'],
            'sender' => 'SuperOptic',
            'destination' => $chatId,
            'reason' => match (true) {
                !$channelEnabled => 'channel_disabled',
                !$externalDeliveryEnabled => 'external_delivery_paused',
                !$credentialReady => 'telegram_credentials_missing',
                $chatId === '' => 'telegram_destination_missing',
                default => '',
            },
        ];
    }

    if ($channel === 'sms') {
        $endpoint = trim(app_communication_runtime_setting('sms_provider_endpoint', APP_COMMUNICATION_SMS_PG365_SEND_ENDPOINT));
        $credentialReady = app_communication_environment_secret_ready('SMS_PROVIDER_PUBLIC_KEY')
            && app_communication_environment_secret_ready('SMS_PROVIDER_PRIVATE_KEY');
        $endpointValid = app_communication_pg365_url($endpoint, 'send', '') !== '';
        $sendConfigured = $channelEnabled && $externalDeliveryEnabled && $endpointValid && $destination !== '' && $credentialReady;
        $statusConfigured = $endpointValid && $credentialReady;
        $reason = match (true) {
            !$channelEnabled => 'channel_disabled',
            !$externalDeliveryEnabled => 'external_delivery_paused',
            !$endpointValid => 'sms_provider_endpoint_invalid',
            !$credentialReady => 'sms_provider_credentials_missing',
            $destination === '' => 'sms_destination_missing',
            default => '',
        };

        return [
            'enabled' => $channelEnabled,
            'configured' => $sendConfigured,
            'status_configured' => $statusConfigured,
            'send_enabled' => $channelEnabled && $externalDeliveryEnabled,
            'external_delivery_enabled' => $externalDeliveryEnabled,
            'delivery' => 'sms_provider',
            'driver' => 'pg365_json',
            'endpoint' => $endpoint,
            'endpoint_valid' => $endpointValid,
            'credentials_ready' => $credentialReady,
            'credential_envs' => ['SMS_PROVIDER_PUBLIC_KEY', 'SMS_PROVIDER_PRIVATE_KEY'],
            // Originator is provider-managed and optional. Do not guess a
            // header name because PG365 rejects unapproved values.
            'sender' => app_communication_runtime_setting('sms_provider_sender', ''),
            'purpose' => app_communication_runtime_setting('sms_provider_purpose', 'INF'),
            'encoding' => app_communication_runtime_setting('sms_provider_encoding', 'LATIN'),
            'destination' => $destination,
            'reason' => $reason,
        ];
    }

    if ($channel === 'whatsapp') {
        $enabled = in_array(strtolower((string) app_setting('whatsapp_provider_enabled', '0')), ['1', 'true', 'yes', 'on'], true);
        $endpoint = app_config('whatsapp_provider_endpoint');
        $credentialReady = app_communication_environment_secret_ready('WHATSAPP_PROVIDER_TOKEN');

        return [
            'enabled' => $channelEnabled,
            'configured' => $channelEnabled && $externalDeliveryEnabled && $enabled && $endpoint !== '' && $credentialReady && $destination !== '',
            'send_enabled' => $channelEnabled && $externalDeliveryEnabled,
            'external_delivery_enabled' => $externalDeliveryEnabled,
            'delivery' => 'whatsapp_web_bridge',
            'endpoint' => $endpoint,
            'credentials_ready' => $credentialReady,
            'credential_envs' => ['WHATSAPP_PROVIDER_TOKEN'],
            'sender' => app_config('whatsapp_provider_sender', 'SuperOptic'),
            'destination' => $destination,
            'reason' => match (true) {
                !$channelEnabled => 'channel_disabled',
                !$externalDeliveryEnabled => 'external_delivery_paused',
                !$enabled => 'whatsapp_provider_disabled',
                $endpoint === '' => 'whatsapp_endpoint_missing',
                !$credentialReady => 'whatsapp_credentials_missing',
                $destination === '' => 'whatsapp_destination_missing',
                default => '',
            },
        ];
    }

    return [
        'enabled' => false,
        'configured' => false,
        'send_enabled' => false,
        'external_delivery_enabled' => $externalDeliveryEnabled,
        'delivery' => 'unsupported',
        'endpoint' => '',
        'credentials_ready' => false,
        'credential_envs' => [],
        'sender' => '',
        'destination' => $destination,
        'reason' => 'unsupported_channel',
    ];
}

function app_communication_provider_public_status(string $channel, string $destination = ''): array
{
    $provider = app_communication_provider_config($channel, $destination);
    $credentialsReady = (bool) ($provider['credentials_ready'] ?? false);
    $bridgeStatus = null;
    if (strtolower(trim($channel)) === 'whatsapp') {
        $bridgeStatus = !empty($provider['enabled'])
            ? app_communication_whatsapp_bridge_status($provider)
            : [
                'reachable' => false,
                'connected' => false,
                'status' => 'disabled',
                'reason' => 'channel_disabled',
            ];
    }

    $status = [
        'channel' => strtolower(trim($channel)),
        'enabled' => (bool) ($provider['enabled'] ?? false),
        'configured' => (bool) ($provider['configured'] ?? false),
        'status_configured' => (bool) ($provider['status_configured'] ?? false),
        'send_enabled' => (bool) ($provider['send_enabled'] ?? false),
        'external_delivery_enabled' => (bool) ($provider['external_delivery_enabled'] ?? false),
        'reason' => (string) ($provider['reason'] ?? ''),
        'delivery' => (string) ($provider['delivery'] ?? 'unsupported'),
        'driver' => (string) ($provider['driver'] ?? ''),
        'has_endpoint' => (string) ($provider['endpoint'] ?? '') !== '',
        'has_token' => $credentialsReady && count((array) ($provider['credential_envs'] ?? [])) === 1,
        'has_credentials' => $credentialsReady,
        'credential_envs' => array_values((array) ($provider['credential_envs'] ?? [])),
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

    if (in_array($channel, ['app', 'telegram', 'sms', 'whatsapp'], true)
        && !app_communication_channel_enabled($channel)) {
        return [
            'ok' => false,
            'provider_message_id' => null,
            'response' => null,
            'reason' => 'channel_disabled',
            'delivery' => (string) ($provider['delivery'] ?? 'unsupported'),
        ];
    }

    if ($channel !== 'app'
        && in_array($channel, ['telegram', 'sms', 'whatsapp'], true)
        && !app_communication_external_delivery_enabled()) {
        return [
            'ok' => false,
            'provider_message_id' => null,
            'response' => null,
            'reason' => 'external_delivery_paused',
            'delivery' => (string) ($provider['delivery'] ?? $channel),
        ];
    }

    if (empty($provider['configured'])) {
        return [
            'ok' => false,
            'provider_message_id' => null,
            'response' => null,
            'reason' => (string) ($provider['reason'] ?? 'provider_not_configured') ?: 'provider_not_configured',
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
        $token = app_communication_environment_secret('TELEGRAM_TOKEN');
        if ($token === '') {
            return [
                'ok' => false,
                'provider_message_id' => null,
                'response' => null,
                'reason' => 'telegram_credentials_missing',
                'delivery' => 'telegram',
            ];
        }
        $response = http_post_json(APP_COMMUNICATION_TELEGRAM_BASE_URL . '/bot' . $token . '/sendMessage', [
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

    if ($channel === 'sms') {
        return app_communication_send_poctgoyercini_json_sms($provider, $body, $externalId);
    }

    if ($channel === 'whatsapp') {
        return app_communication_send_whatsapp_bridge($provider, $body, $externalId);
    }

    return [
        'ok' => false,
        'provider_message_id' => null,
        'response' => null,
        'reason' => 'unsupported_provider_transport',
        'delivery' => (string) ($provider['delivery'] ?? 'unsupported'),
    ];
}

function app_communication_whatsapp_bridge_status(array $provider = []): array
{
    if ($provider === []) {
        $provider = app_communication_provider_config('whatsapp', '994000000000');
    }

    $endpoint = (string) ($provider['endpoint'] ?? app_config('whatsapp_provider_endpoint'));
    $token = app_communication_environment_secret('WHATSAPP_PROVIDER_TOKEN');
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

    // The status probe blocks up to 2s per call when the bridge is down, and it
    // is queried on every message-center render. Cache the result briefly so a
    // down (or up) bridge does not add 2s to each page load. A status indicator
    // being ~15s stale is acceptable.
    $cacheFile = sys_get_temp_dir() . '/soc_wa_status_' . md5($statusUrl) . '.json';
    if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < 15) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $response = http_request_raw($statusUrl, 'GET', '', ['x-api-key: ' . $token], 2);
    $body = trim((string) ($response['body'] ?? ''));
    $decoded = json_decode($body, true);

    if (empty($response['ok']) || !is_array($decoded)) {
        $result = [
            'reachable' => false,
            'connected' => false,
            'status' => 'unreachable',
            'reason' => 'http_' . (string) ($response['status'] ?? 0),
        ];
    } else {
        $result = [
            'reachable' => true,
            'connected' => !empty($decoded['connected']),
            'status' => (string) ($decoded['status'] ?? (!empty($decoded['connected']) ? 'connected' : 'loading')),
            'provider_number' => app_communication_whatsapp_normalize_destination((string) ($decoded['provider_number'] ?? '')),
            'ready_at' => (string) ($decoded['ready_at'] ?? ''),
            'last_qr_at' => (string) ($decoded['last_qr_at'] ?? ''),
            'qr_expires_at' => (string) ($decoded['qr_expires_at'] ?? ''),
            'qr_expired' => !empty($decoded['qr_expired']),
            'last_error' => (string) ($decoded['last_error'] ?? ''),
        ];
    }

    @file_put_contents($cacheFile, json_encode($result));
    return $result;
}

function app_communication_whatsapp_bridge_qr(): array
{
    $provider = app_communication_provider_config('whatsapp', '994000000000');
    $endpoint = (string) ($provider['endpoint'] ?? '');
    $token = app_communication_environment_secret('WHATSAPP_PROVIDER_TOKEN');
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
        'qr_expires_at' => (string) ($decoded['qr_expires_at'] ?? ''),
        'qr_expired' => !empty($decoded['qr_expired']),
        'reason' => (string) ($decoded['error'] ?? ''),
    ];
}

function app_communication_whatsapp_bridge_refresh(): array
{
    $provider = app_communication_provider_config('whatsapp', '994000000000');
    $endpoint = (string) ($provider['endpoint'] ?? '');
    $token = app_communication_environment_secret('WHATSAPP_PROVIDER_TOKEN');
    if ($endpoint === '' || $token === '') {
        return ['ok' => false, 'status' => 'not_configured', 'reason' => 'endpoint_or_token_missing'];
    }

    $refreshUrl = preg_replace('#/api/send$#', '/api/refresh', $endpoint) ?? $endpoint;
    if ($refreshUrl === $endpoint) {
        $refreshUrl = rtrim($endpoint, '/') . '/api/refresh';
    }
    $response = http_post_json($refreshUrl, ['reason' => 'admin_manual_refresh'], [
        'Content-Type: application/json',
        'x-api-key: ' . $token,
    ]);

    return [
        'ok' => is_array($response) && !empty($response['ok']),
        'status' => is_array($response) ? (string) ($response['status'] ?? '') : '',
        'reason' => is_array($response) ? (string) ($response['error'] ?? '') : 'bridge_request_failed',
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

    $token = app_communication_environment_secret('WHATSAPP_PROVIDER_TOKEN');
    if ($token === '') {
        return [
            'ok' => false,
            'provider_message_id' => null,
            'response' => null,
            'reason' => 'whatsapp_credentials_missing',
            'delivery' => 'whatsapp_web_bridge',
        ];
    }
    $headers = ['Content-Type: application/json', 'x-api-key: ' . $token];

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

function app_communication_whatsapp_guard_schema(PDO $db): bool
{
    try {
        $statement = $db->query(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ("whatsapp_delivery_guard","whatsapp_delivery_guard_global")'
        );
        return (int) $statement->fetchColumn() === 2;
    } catch (Throwable) {
        return false;
    }
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

    if (!app_communication_whatsapp_guard_schema($db)) {
        return ['ok' => false, 'reason' => 'whatsapp_guard_schema_not_migrated'];
    }
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

    if (!app_communication_whatsapp_guard_schema($db)) {
        return;
    }
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

function app_communication_pg365_url(
    string $endpoint,
    string $operation,
    string $publicKey,
    array $query = []
): string {
    $operation = strtolower(trim($operation));
    if (!in_array($operation, ['send', 'status', 'statusmany'], true)) {
        return '';
    }

    $url = trim($endpoint) !== '' ? trim($endpoint) : APP_COMMUNICATION_SMS_PG365_SEND_ENDPOINT;
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }

    $parts = parse_url($url);
    if (!is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || strtolower((string) ($parts['host'] ?? '')) !== APP_COMMUNICATION_SMS_PG365_HOST
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['port'])) {
        return '';
    }

    $path = (string) ($parts['path'] ?? '');
    if ($path === '' || $path === '/') {
        $path = '/gateway/api/sms/v1/message/' . $operation;
    } elseif (preg_match('#/gateway/api/sms/v1/message(?:/(?:send|status|statusmany))?/?$#i', $path) === 1) {
        $path = preg_replace(
            '#/gateway/api/sms/v1/message(?:/(?:send|status|statusmany))?/?$#i',
            '/gateway/api/sms/v1/message/' . $operation,
            $path
        ) ?? $path;
    } else {
        return '';
    }

    $queryValues = [];
    parse_str((string) ($parts['query'] ?? ''), $queryValues);
    foreach (array_keys($queryValues) as $key) {
        if (in_array(strtolower((string) $key), ['publickey', 'id'], true)) {
            unset($queryValues[$key]);
        }
    }
    $queryValues['publicKey'] = $publicKey;
    foreach ($query as $key => $value) {
        $queryValues[(string) $key] = (string) $value;
    }

    $authority = (string) $parts['host'];
    if (isset($parts['port'])) {
        $authority .= ':' . (int) $parts['port'];
    }

    return 'https://' . $authority . $path . '?' . http_build_query($queryValues, '', '&', PHP_QUERY_RFC3986);
}

function app_communication_pg365_request_url_allowed(string $url): bool
{
    $parts = parse_url(trim($url));
    if (!is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || strtolower((string) ($parts['host'] ?? '')) !== APP_COMMUNICATION_SMS_PG365_HOST
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['port'])
        || isset($parts['fragment'])) {
        return false;
    }

    return in_array((string) ($parts['path'] ?? ''), [
        '/gateway/api/sms/v1/message/send',
        '/gateway/api/sms/v1/message/status',
        '/gateway/api/sms/v1/message/statusmany',
    ], true);
}

function app_communication_poctgoyercini_send_url(string $endpoint, string $publicKey): string
{
    return app_communication_pg365_url($endpoint, 'send', $publicKey);
}

function app_communication_pg365_json_request(
    string $url,
    string $method,
    ?array $payload,
    array $headers,
    ?callable $transport = null
): ?array {
    $method = strtoupper(trim($method));
    if ($url === ''
        || !app_communication_pg365_request_url_allowed($url)
        || !in_array($method, ['GET', 'POST'], true)) {
        return null;
    }

    if ($transport !== null) {
        $mocked = $transport($url, $method, $payload, $headers);
        if (!is_array($mocked)) {
            return null;
        }
        $mockHttpStatus = isset($mocked['http_status']) ? (int) $mocked['http_status'] : 200;
        if ($mockHttpStatus < 200 || $mockHttpStatus >= 300) {
            return [
                'Status' => $mockHttpStatus,
                'Description' => 'sms_provider_http_' . $mockHttpStatus,
            ];
        }
        if (isset($mocked['body']) && is_string($mocked['body'])) {
            $decoded = json_decode($mocked['body'], true);
            return is_array($decoded) ? $decoded : null;
        }

        return $mocked;
    }

    $body = $payload === null
        ? ''
        : (json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    $httpStatus = 0;

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
        ];
        if ($body !== '') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);
        $responseBody = curl_exec($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
    } else {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN) && ini_get('allow_url_fopen') !== '1') {
            return null;
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ]);
        $responseBody = @file_get_contents($url, false, $context);
        $responseHeaders = is_array($http_response_header ?? null) ? $http_response_header : [];
        foreach ($responseHeaders as $responseHeader) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', (string) $responseHeader, $matches) === 1) {
                $httpStatus = (int) $matches[1];
            }
        }
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        return [
            'Status' => $httpStatus,
            'Description' => 'sms_provider_http_' . $httpStatus,
        ];
    }

    if (!is_string($responseBody) || $responseBody === '') {
        return null;
    }
    $decoded = json_decode($responseBody, true);

    return is_array($decoded) ? $decoded : null;
}

function app_communication_pg365_headers(string $privateKey): array
{
    return [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $privateKey,
    ];
}

function app_communication_pg365_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int) $value === 1;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

function app_communication_pg365_delivery_state(int $statusCode, bool $isFinal): string
{
    return match (true) {
        $statusCode === 400 => 'delivered',
        in_array($statusCode, [110, 120, 200, 300], true) => 'submitted',
        $isFinal => 'final_failed',
        default => 'accepted',
    };
}

function app_communication_pg365_parse_status_response(?array $response): array
{
    $responseStatus = is_array($response) ? (int) ($response['Status'] ?? 0) : 0;
    if (!is_array($response) || $responseStatus !== 200) {
        return [
            'ok' => false,
            'items' => [],
            'reason' => !is_array($response)
                ? 'sms_provider_request_failed'
                : ((string) ($response['Description'] ?? '') ?: 'sms_provider_status_' . $responseStatus),
            'response' => $response,
        ];
    }

    $result = $response['Result'] ?? [];
    if (!is_array($result)) {
        $result = [];
    }
    $rows = $result === [] ? [] : (array_is_list($result) ? $result : [$result]);
    $items = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $statusCode = (int) ($row['Status'] ?? 0);
        $isFinal = $statusCode === 400 || app_communication_pg365_bool($row['IsFinal'] ?? false);
        $items[] = [
            'id' => $id,
            'receiver' => (string) ($row['Receiver'] ?? ''),
            'status_code' => $statusCode,
            'status_description' => (string) ($row['StatusDescription'] ?? ''),
            'record_time' => (string) ($row['RecordTime'] ?? ''),
            'sent_time' => (string) ($row['SentTime'] ?? ''),
            'deliver_time' => (string) ($row['DeliverTime'] ?? ''),
            'is_final' => $isFinal,
            'delivery_state' => app_communication_pg365_delivery_state($statusCode, $isFinal),
        ];
    }

    return [
        'ok' => true,
        'items' => $items,
        'reason' => '',
        'response' => $response,
    ];
}

function app_communication_pg365_poll_status(
    array $provider,
    string $receiverId,
    ?callable $transport = null
): array {
    $receiverId = trim($receiverId);
    $publicKey = app_communication_environment_secret('SMS_PROVIDER_PUBLIC_KEY');
    $privateKey = app_communication_environment_secret('SMS_PROVIDER_PRIVATE_KEY');
    if ($receiverId === '') {
        return ['ok' => false, 'items' => [], 'item' => null, 'reason' => 'sms_provider_message_id_missing', 'response' => null];
    }
    if ($publicKey === '' || $privateKey === '') {
        return ['ok' => false, 'items' => [], 'item' => null, 'reason' => 'sms_provider_credentials_missing', 'response' => null];
    }

    $url = app_communication_pg365_url((string) ($provider['endpoint'] ?? ''), 'status', $publicKey, ['id' => $receiverId]);
    if ($url === '') {
        return ['ok' => false, 'items' => [], 'item' => null, 'reason' => 'sms_provider_endpoint_invalid', 'response' => null];
    }
    $parsed = app_communication_pg365_parse_status_response(app_communication_pg365_json_request(
        $url,
        'GET',
        null,
        app_communication_pg365_headers($privateKey),
        $transport
    ));
    $parsed['item'] = $parsed['items'][0] ?? null;

    return $parsed;
}

function app_communication_pg365_poll_status_many(
    array $provider,
    array $receiverIds,
    ?callable $transport = null
): array {
    $receiverIds = array_values(array_unique(array_filter(array_map(
        static fn (mixed $value): string => trim((string) $value),
        $receiverIds
    ), static fn (string $value): bool => $value !== '')));
    $receiverIds = array_slice($receiverIds, 0, 10000);
    $publicKey = app_communication_environment_secret('SMS_PROVIDER_PUBLIC_KEY');
    $privateKey = app_communication_environment_secret('SMS_PROVIDER_PRIVATE_KEY');
    if ($receiverIds === []) {
        return ['ok' => false, 'items' => [], 'reason' => 'sms_provider_message_ids_missing', 'response' => null];
    }
    if ($publicKey === '' || $privateKey === '') {
        return ['ok' => false, 'items' => [], 'reason' => 'sms_provider_credentials_missing', 'response' => null];
    }

    $url = app_communication_pg365_url((string) ($provider['endpoint'] ?? ''), 'statusmany', $publicKey);
    if ($url === '') {
        return ['ok' => false, 'items' => [], 'reason' => 'sms_provider_endpoint_invalid', 'response' => null];
    }

    return app_communication_pg365_parse_status_response(app_communication_pg365_json_request(
        $url,
        'GET',
        ['ReceiverIds' => $receiverIds],
        app_communication_pg365_headers($privateKey),
        $transport
    ));
}

function app_communication_send_poctgoyercini_json_sms(
    array $provider,
    string $body,
    string $externalId = '',
    ?callable $transport = null
): array
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

    $publicKey = app_communication_environment_secret('SMS_PROVIDER_PUBLIC_KEY');
    $privateKey = app_communication_environment_secret('SMS_PROVIDER_PRIVATE_KEY');
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

    $url = app_communication_poctgoyercini_send_url((string) ($provider['endpoint'] ?? ''), $publicKey);
    if ($url === '') {
        return [
            'ok' => false,
            'provider_message_id' => null,
            'provider_status' => 'final_failed',
            'provider_status_final' => true,
            'response' => null,
            'reason' => 'sms_provider_endpoint_invalid',
            'delivery' => 'sms_provider',
        ];
    }

    $response = app_communication_pg365_json_request(
        $url,
        'POST',
        $payload,
        app_communication_pg365_headers($privateKey),
        $transport
    );

    $result = is_array($response['Result'] ?? null) ? $response['Result'] : [];
    $accepted = is_array($result['ReceiversAccepted'] ?? null) ? $result['ReceiversAccepted'] : [];
    $rejected = is_array($result['ReceiversRejected'] ?? null) ? $result['ReceiversRejected'] : [];
    $status = is_array($response) ? (int) ($response['Status'] ?? 0) : 0;
    $ok = is_array($response) && $status === 200 && $accepted !== [];
    $firstAccepted = is_array($accepted[0] ?? null) ? $accepted[0] : [];
    $firstRejected = is_array($rejected[0] ?? null) ? $rejected[0] : [];
    $providerMessageId = trim((string) ($firstAccepted['id'] ?? ''));

    $transientFailure = !is_array($response)
        || $status <= 0
        || in_array($status, [408, 425, 429], true)
        || $status >= 500;
    $reason = '';
    if (!$ok) {
        $reason = match (true) {
            !is_array($response) => 'sms_provider_request_failed',
            $status <= 0 => 'sms_provider_invalid_response',
            $status !== 200 => (string) ($response['Description'] ?? ('sms_provider_status_' . $status)),
            $firstRejected !== [] => trim((string) ($firstRejected['ErrorCode'] ?? '') . ':' . (string) ($firstRejected['ErrorMessage'] ?? ''), ':'),
            default => 'sms_provider_no_accepted_receivers',
        };
    }

    return [
        'ok' => $ok,
        'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
        'provider_status' => $ok ? 'accepted' : ($transientFailure ? 'pending' : 'final_failed'),
        'provider_status_final' => !$ok && !$transientFailure,
        'response' => is_array($response) ? $response : null,
        'reason' => $reason,
        'delivery' => 'sms_provider',
    ];
}
