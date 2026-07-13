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
    echo "Usage: php tools/test-communication-provider.php --channel=telegram|sms|whatsapp|app [--bot=network|customer|finance|warehouse|backup|diagnostics|superpilot] [--destination=...] [--message=...] [--apply]\n";
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
$result['response_summary'] = is_array($sendResult['response'] ?? null) ? $sendResult['response'] : null;

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
echo ($json !== false ? $json : '{"mode":"apply","sent":false,"reason":"json_encode_failed"}') . PHP_EOL;
exit($result['sent'] ? 0 : 4);
