<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

function bot_test_option(array $argv, string $name, string $default = ''): string
{
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--' . $name . '=')) {
            return substr($arg, strlen('--' . $name . '='));
        }
    }

    return $default;
}

$apply = in_array('--apply', $_SERVER['argv'] ?? [], true);
$selectedBot = bot_test_option($_SERVER['argv'] ?? [], 'bot', 'all');
$destination = bot_test_option($_SERVER['argv'] ?? [], 'destination', '');
$message = bot_test_option($_SERVER['argv'] ?? [], 'message', '');
$definitions = app_mgs_telegram_bot_definitions();
$botKeys = $selectedBot === 'all' ? array_keys($definitions) : [$selectedBot];
$db = app_db();
if ($db instanceof PDO) {
    app_mgs_ensure_schema($db);
    app_mgs_sync_telegram_bot_registry($db);
}

$results = [];
$failed = 0;
foreach ($botKeys as $botKey) {
    if (!isset($definitions[$botKey])) {
        $results[] = [
            'bot_key' => $botKey,
            'configured' => false,
            'mode' => $apply ? 'apply' : 'dry-run',
            'sent' => false,
            'reason' => 'unknown_bot',
        ];
        $failed++;
        continue;
    }

    $result = app_mgs_send_telegram_bot_test($botKey, $message, $destination, $apply);
    $results[] = $result;
    if ($apply && (empty($result['configured']) || empty($result['sent']))) {
        $failed++;
    }
}

$payload = [
    'status' => $failed === 0 ? 'ok' : 'failed',
    'mode' => $apply ? 'apply' : 'dry-run',
    'selected_bot' => $selectedBot,
    'checked' => count($results),
    'failed' => $failed,
    'results' => $results,
    'generated_at' => date(DATE_ATOM),
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === 0 ? 0 : 2);
