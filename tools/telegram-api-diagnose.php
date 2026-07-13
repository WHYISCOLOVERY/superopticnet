<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$selectedBot = 'all';
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--bot=')) {
        $selectedBot = substr($arg, strlen('--bot='));
    }
}

$definitions = app_mgs_telegram_bot_definitions();
$botKeys = $selectedBot === 'all' ? array_keys($definitions) : [$selectedBot];
$results = [];
$failed = 0;

foreach ($botKeys as $botKey) {
    $config = app_mgs_telegram_bot_config($botKey);
    $result = [
        'bot_key' => $botKey,
        'configured' => !empty($config['configured']),
        'destination_masked' => (string) ($config['destination_masked'] ?? ''),
        'http_status' => 0,
        'telegram_ok' => false,
        'telegram_error_code' => null,
        'description' => '',
        'message_id' => null,
    ];

    if (empty($config['configured'])) {
        $result['description'] = (string) ($config['reason'] ?? 'bot_not_configured');
        $results[] = $result;
        $failed++;
        continue;
    }

    $response = http_request((string) $config['endpoint'], [
        'chat_id' => (string) $config['destination'],
        'text' => 'TEST MESAJI' . PHP_EOL . 'SuperOptic MGS Telegram API diagnosis: ' . $botKey,
        'disable_web_page_preview' => true,
    ], ['Content-Type: application/json'], true);

    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    $result['http_status'] = (int) ($response['status'] ?? 0);
    $result['telegram_ok'] = is_array($decoded) && !empty($decoded['ok']);
    $result['telegram_error_code'] = is_array($decoded) ? ($decoded['error_code'] ?? null) : null;
    $result['description'] = is_array($decoded)
        ? (string) ($decoded['description'] ?? '')
        : 'non_json_or_empty_response';
    $result['message_id'] = $decoded['result']['message_id'] ?? null;

    if (!$result['telegram_ok']) {
        $failed++;
    }
    $results[] = $result;
}

echo json_encode([
    'status' => $failed === 0 ? 'ok' : 'failed',
    'checked' => count($results),
    'failed' => $failed,
    'results' => $results,
    'generated_at' => date(DATE_ATOM),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === 0 ? 0 : 2);
