<?php
declare(strict_types=1);

require dirname(__DIR__) . '/communication-provider.php';
require_once dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

function provider_test_usage(): void
{
    echo "Usage: php tools/test-communication-provider.php --channel=telegram|sms|whatsapp|app [--bot=network|customer|finance|warehouse|backup|diagnostics|superpilot] [--subscriber=2320025] [--destination=...] [--message=...] [--apply]\n";
    echo "\n";
    echo "Default mode is dry-run. Telegram defaults to --bot=superpilot so generic customer/NOC groups are not mixed.\n";
    echo "With --apply it sends one TEST MESAJI without creating DB messages, tickets or alarms.\n";
}

function provider_test_option(array $argv, string $name, string $default = ''): string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string) $arg, $prefix)) {
            return substr((string) $arg, strlen($prefix));
        }
    }

    return $default;
}

function provider_test_mask(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $length = strlen($value);
    if ($length <= 6) {
        return str_repeat('*', $length);
    }

    return substr($value, 0, 3) . str_repeat('*', max(3, $length - 6)) . substr($value, -3);
}

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    provider_test_usage();
    exit(0);
}

$apply = in_array('--apply', $argv, true);
$channel = strtolower(trim(provider_test_option($argv, 'channel', 'telegram')));
$botKey = clean_text(provider_test_option($argv, 'bot', 'superpilot')) ?: 'superpilot';
$destination = trim(provider_test_option($argv, 'destination', ''));
$subscriberCode = clean_text(provider_test_option($argv, 'subscriber', ''));
$message = trim(provider_test_option($argv, 'message', ''));

if (!in_array($channel, ['telegram', 'sms', 'whatsapp', 'app'], true)) {
    fwrite(STDERR, "Invalid channel: {$channel}\n");
    provider_test_usage();
    exit(2);
}

$telegramBots = app_mgs_telegram_bot_definitions();
if ($channel === 'telegram' && !isset($telegramBots[$botKey])) {
    fwrite(STDERR, "Invalid Telegram bot profile: {$botKey}\n");
    provider_test_usage();
    exit(2);
}

$db = app_db();
if (in_array($channel, ['sms', 'whatsapp'], true) && $subscriberCode !== '') {
    $account = app_db_account_by_subscriber_code($subscriberCode);
    $destination = is_array($account) ? trim((string) ($account['phone'] ?? '')) : '';
    if ($destination === '') {
        fwrite(STDERR, "Subscriber or subscriber phone was not found.\n");
        exit(3);
    }
}
if ($apply && in_array($channel, ['sms', 'whatsapp'], true)) {
    $allowedSubscriber = trim((string) (getenv('SMS_LIVE_TEST_SUBSCRIBER_CODE') ?: '2320025'));
    if ($subscriberCode === '' || !hash_equals($allowedSubscriber, $subscriberCode)) {
        fwrite(STDERR, "Real SMS/WhatsApp test is restricted to the configured test subscriber.\n");
        exit(3);
    }
}

if ($message === '') {
    $message = 'TEST MESAJI' . "\n" . 'SuperOptic MGS provider smoke test: ' . date('Y-m-d H:i:s');
} elseif (!str_starts_with($message, 'TEST MESAJI')) {
    $message = 'TEST MESAJI' . "\n" . $message;
}

if (mb_strlen($message) > 640) {
    $message = mb_substr($message, 0, 640);
}

$provider = $channel === 'telegram'
    ? app_mgs_telegram_bot_config($botKey, $destination)
    : app_communication_provider_config($channel, $destination);
$publicStatus = app_communication_provider_public_status($channel, $destination);

$result = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'channel' => $channel,
    'bot_key' => $channel === 'telegram' ? $botKey : '',
    'subscriber_code' => $subscriberCode,
    'configured' => (bool) ($provider['configured'] ?? false),
    'delivery' => $channel === 'telegram' ? 'telegram_bot' : (string) ($provider['delivery'] ?? 'unsupported'),
    'destination_masked' => provider_test_mask((string) ($provider['destination'] ?? '')),
    'provider_status' => $channel === 'telegram'
        ? [
            'channel' => 'telegram',
            'delivery' => 'telegram_bot',
            'bot_key' => $botKey,
            'configured' => (bool) ($provider['configured'] ?? false),
            'has_endpoint' => (string) ($provider['endpoint'] ?? '') !== '',
            'has_destination' => (string) ($provider['destination'] ?? '') !== '',
            'destination_masked' => provider_test_mask((string) ($provider['destination'] ?? '')),
        ]
        : $publicStatus,
    'message_preview' => $message,
    'sent' => false,
    'provider_message_id' => null,
    'reason' => '',
];

if (empty($provider['configured'])) {
    $result['reason'] = 'provider_not_configured_or_destination_missing';
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($apply ? 3 : 0);
}

if (!$apply) {
    $result['reason'] = 'dry_run_no_provider_request_sent';
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

if ($channel !== 'app' && !app_communication_external_delivery_enabled()) {
    $result['reason'] = 'external_delivery_paused';
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(3);
}

if ($channel === 'telegram') {
    $telegramResponse = http_post_json((string) ($provider['endpoint'] ?? ''), [
        'chat_id' => (string) ($provider['destination'] ?? ''),
        'text' => $message,
        'disable_web_page_preview' => true,
    ]);
    $sendResult = [
        'ok' => is_array($telegramResponse) && !empty($telegramResponse['ok']),
        'provider_message_id' => is_array($telegramResponse) ? ($telegramResponse['result']['message_id'] ?? null) : null,
        'reason' => is_array($telegramResponse) && empty($telegramResponse['ok'])
            ? (string) ($telegramResponse['description'] ?? 'provider_rejected')
            : '',
        'response' => is_array($telegramResponse) ? $telegramResponse : null,
    ];
} else {
    $sendResult = app_communication_send_provider($channel, $destination, $message, 'provider-smoke-' . date('YmdHis'));
}
$result['sent'] = !empty($sendResult['ok']);
$result['provider_message_id'] = $sendResult['provider_message_id'] ?? null;
$result['reason'] = (string) ($sendResult['reason'] ?? '');
$providerResponse = is_array($sendResult['response'] ?? null) ? $sendResult['response'] : [];
$result['response_summary'] = [
    'ok' => !empty($sendResult['ok']),
    'provider_status' => (string) ($sendResult['provider_status'] ?? ''),
    'provider_status_final' => (bool) ($sendResult['provider_status_final'] ?? false),
    'provider_http_status' => (int) ($providerResponse['http_status'] ?? 0),
];

if ($apply && $db instanceof PDO && function_exists('app_audit_record')) {
    $ownsAuditTransaction = !$db->inTransaction();
    try {
        if ($ownsAuditTransaction) {
            $db->beginTransaction();
        }
        app_audit_record($db, [
            'module_key' => 'messaging',
            'operation_class' => 'provider_test',
            'action_type' => 'communication_provider_live_test',
            'entity_type' => 'communication_provider',
            'entity_id' => $channel . ':' . ($subscriberCode !== '' ? $subscriberCode : $botKey),
            'status' => $result['sent'] ? 'executed' : 'failed',
            'actor_account_id' => null,
            'actor_name' => 'Provider test CLI',
            'summary' => 'Bounded live communication provider test',
            'reason' => $result['reason'],
            'after' => [
                'channel' => $channel,
                'bot_key' => $channel === 'telegram' ? $botKey : '',
                'subscriber_code' => $subscriberCode,
                'destination_masked' => $result['destination_masked'],
                'provider_message_id' => $result['provider_message_id'],
            ],
            'sensitivity' => 'critical',
        ]);
        if ($ownsAuditTransaction && $db->inTransaction()) {
            $db->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsAuditTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        app_runtime_error_reference('provider_test_audit', $exception);
    }
}

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
echo ($json !== false ? $json : '{"mode":"apply","sent":false,"reason":"json_encode_failed"}') . PHP_EOL;
exit($result['sent'] ? 0 : 4);
