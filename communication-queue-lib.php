<?php
declare(strict_types=1);

require_once __DIR__ . '/mgs-integration-lib.php';

function app_communication_queue_message_metadata(array $message): array
{
    return app_db_json_decode((string) ($message['metadata_json'] ?? ''), []);
}

function app_communication_queue_standard_provider(string $preferredChannel, string $destination): array
{
    $preferredChannel = strtolower(trim($preferredChannel));
    $fallbackOrder = [
        'auto' => ['whatsapp', 'app', 'telegram', 'sms'],
        'whatsapp' => ['whatsapp', 'app', 'telegram', 'sms'],
        'app' => ['app', 'telegram', 'sms'],
        'telegram' => ['telegram', 'app', 'sms'],
        'sms' => ['sms', 'app', 'telegram'],
    ];
    $channels = $fallbackOrder[$preferredChannel] ?? [$preferredChannel];
    $lastProvider = null;

    foreach ($channels as $channel) {
        $provider = app_communication_provider_config($channel, $destination);
        $provider['channel'] = $channel;
        $provider['preferred_channel'] = $preferredChannel;
        if (!empty($provider['configured'])) {
            return $provider;
        }
        $lastProvider = $provider;
    }

    $provider = is_array($lastProvider)
        ? $lastProvider
        : app_communication_provider_config($preferredChannel, $destination);
    $provider['channel'] = (string) ($provider['channel'] ?? $preferredChannel);
    $provider['preferred_channel'] = $preferredChannel;

    return $provider;
}

function app_communication_queue_resolve_provider_for_message(array $message): array
{
    $messageChannel = strtolower(trim((string) ($message['channel'] ?? '')));
    $destination = trim((string) ($message['destination'] ?? ''));
    $metadata = app_communication_queue_message_metadata($message);
    $botKey = clean_text((string) ($metadata['bot_key'] ?? ''));

    if ($messageChannel === 'telegram' && $botKey !== '') {
        $botConfig = app_mgs_telegram_bot_config($botKey, $destination);
        if (($botConfig['reason'] ?? '') !== 'unknown_bot') {
            return [
                'configured' => !empty($botConfig['configured']),
                'delivery' => 'telegram_bot',
                'endpoint' => (string) ($botConfig['endpoint'] ?? ''),
                'sender' => 'SuperOptic',
                'destination' => (string) ($botConfig['destination'] ?? $destination),
                'channel' => 'telegram',
                'preferred_channel' => 'telegram',
                'bot_key' => $botKey,
                'bot_display_name' => (string) ($botConfig['display_name'] ?? $botKey),
                'reason' => empty($botConfig['configured']) ? 'bot_not_configured' : '',
            ];
        }
    }

    if ($messageChannel === 'telegram' && $botKey === '' && $destination === '') {
        return [
            'configured' => false,
            'delivery' => 'telegram',
            'endpoint' => '',
            'sender' => 'SuperOptic',
            'destination' => '',
            'channel' => 'telegram',
            'preferred_channel' => 'telegram',
            'reason' => 'missing_telegram_bot_key_or_destination',
        ];
    }

    return app_communication_queue_standard_provider($messageChannel, $destination);
}

function app_communication_queue_send_provider_for_message(array $message, array $provider): array
{
    $channel = strtolower((string) ($provider['channel'] ?? $message['channel'] ?? ''));
    $body = trim((string) ($message['message_body'] ?? ''));

    if (($provider['delivery'] ?? '') === 'telegram_bot') {
        if (empty($provider['configured'])) {
            return [
                'ok' => false,
                'provider_message_id' => null,
                'response' => null,
                'reason' => (string) ($provider['reason'] ?? 'bot_not_configured'),
                'delivery' => 'telegram_bot',
            ];
        }
        if ($body === '') {
            return [
                'ok' => false,
                'provider_message_id' => null,
                'response' => null,
                'reason' => 'empty_message',
                'delivery' => 'telegram_bot',
            ];
        }

        $response = http_post_json((string) ($provider['endpoint'] ?? ''), [
            'chat_id' => (string) ($provider['destination'] ?? ''),
            'text' => $body,
            'disable_web_page_preview' => true,
        ]);

        return [
            'ok' => is_array($response) && !empty($response['ok']),
            'provider_message_id' => $response['result']['message_id'] ?? null,
            'response' => is_array($response) ? $response : null,
            'reason' => is_array($response) && empty($response['ok'])
                ? (string) ($response['description'] ?? 'provider_rejected')
                : '',
            'delivery' => 'telegram_bot',
        ];
    }

    return app_communication_send_provider(
        $channel,
        (string) ($provider['destination'] ?? ''),
        $body,
        (string) ($message['id'] ?? '')
    );
}
