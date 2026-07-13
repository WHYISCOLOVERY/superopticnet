<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/mgs-integration-lib.php';
require_once dirname(__DIR__) . '/communication-queue-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    echo json_encode(['status' => 'failed', 'reason' => 'db_unavailable'], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(2);
}

$category = 'NETWORK';
$bot = '';
$expectMissingConfig = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--category=')) {
        $category = strtoupper(trim(substr($arg, 11)));
    }
    if (str_starts_with($arg, '--bot=')) {
        $bot = trim(substr($arg, 6));
    }
    if ($arg === '--expect-missing-config') {
        $expectMissingConfig = true;
    }
}

$runId = 'real-queue-route-' . date('His') . '-' . bin2hex(random_bytes(3));
$event = [
    'category' => $category,
    'event_type' => 'REAL_QUEUE_PROVIDER_TEST',
    'source' => 'codex_real_test',
    'title' => 'Real queue provider test',
    'message' => "TEST MESAJI\nREAL TEST - Queue -> routing -> provider -> sent status yoxlamasi\nRun: {$runId}",
    'dedup_key' => $runId,
    'template_code' => 'real_queue_provider_test',
];
if ($bot !== '') {
    $event['metadata'] = ['requested_bot' => $bot];
}

$route = app_notification_route_event($event, false, true);
$messageId = (int) ($route['message_id'] ?? 0);
$message = null;
$provider = [];
$send = [];

if ($messageId > 0) {
    $statement = $db->prepare('SELECT * FROM communication_messages WHERE id = :id');
    $statement->execute(['id' => $messageId]);
    $message = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    if (is_array($message)) {
        $provider = app_communication_queue_resolve_provider_for_message($message);
        $send = app_communication_queue_send_provider_for_message($message, $provider);
        if (!empty($send['ok'])) {
            $metadata = app_db_json_decode((string) ($message['metadata_json'] ?? ''), []);
            $metadata['real_queue_provider_test'] = [
                'sent' => true,
                'provider_message_id_present' => !empty($send['provider_message_id']),
                'tested_at' => app_db_now(),
            ];
            $update = $db->prepare(
                'UPDATE communication_messages
                 SET status = "sent", sent_at = NOW(), metadata_json = :metadata_json
                 WHERE id = :id'
            );
            $update->execute([
                'metadata_json' => app_db_json_encode($metadata),
                'id' => $messageId,
            ]);
        }
    }
}

$missingConfigOk = $expectMissingConfig
    && empty($route['queued'])
    && str_starts_with((string) ($route['reason'] ?? ''), 'missing_');

$payload = [
    'status' => ((!empty($route['queued']) && !empty($send['ok'])) || $missingConfigOk) ? 'ok' : 'failed',
    'run_id' => $runId,
    'route' => [
        'queued' => (bool) ($route['queued'] ?? false),
        'category' => (string) ($route['category'] ?? ''),
        'bot_key' => (string) ($route['bot_key'] ?? ''),
        'message_id' => $messageId,
        'reason' => (string) ($route['reason'] ?? ''),
    ],
    'db_message_found' => is_array($message),
    'provider' => [
        'configured' => (bool) ($provider['configured'] ?? false),
        'delivery' => (string) ($provider['delivery'] ?? ''),
        'bot_key' => (string) ($provider['bot_key'] ?? ''),
        'destination_masked' => isset($provider['destination']) ? app_mgs_mask((string) $provider['destination']) : '',
    ],
    'send' => [
        'ok' => (bool) ($send['ok'] ?? false),
        'provider_message_id' => $send['provider_message_id'] ?? null,
        'reason' => (string) ($send['reason'] ?? ''),
    ],
    'expect_missing_config' => $expectMissingConfig,
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($payload['status'] === 'ok' ? 0 : 3);
