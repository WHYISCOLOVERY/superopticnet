<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$requiredBots = ['network', 'customer', 'finance', 'warehouse', 'backup', 'diagnostics', 'superpilot'];
$definitions = app_mgs_telegram_bot_definitions();
$missingBots = array_values(array_filter(
    $requiredBots,
    static fn (string $botKey): bool => !isset($definitions[$botKey])
));

$queueLib = dirname(__DIR__) . '/communication-queue-lib.php';
if (!file_exists($queueLib)) {
    echo json_encode([
        'status' => 'failed',
        'reason' => 'communication_queue_lib_missing',
        'missing_bots' => $missingBots,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}

require_once $queueLib;

if (!function_exists('app_communication_queue_resolve_provider_for_message')) {
    echo json_encode([
        'status' => 'failed',
        'reason' => 'queue_bot_routing_helper_missing',
        'missing_bots' => $missingBots,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(3);
}

if (!function_exists('app_mgs_queue_telegram_bot_message')) {
    echo json_encode([
        'status' => 'failed',
        'reason' => 'mgs_bot_message_queue_helper_missing',
        'missing_bots' => $missingBots,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(4);
}

$failures = [];
foreach ($missingBots as $botKey) {
    $failures[] = 'missing_bot_definition:' . $botKey;
}

foreach ($requiredBots as $botKey) {
    if (!isset($definitions[$botKey])) {
        continue;
    }

    $message = [
        'id' => 0,
        'channel' => 'telegram',
        'destination' => '777' . strlen($botKey),
        'message_body' => 'Routing smoke ' . $botKey,
        'metadata_json' => app_db_json_encode(['bot_key' => $botKey]),
    ];
    $provider = app_communication_queue_resolve_provider_for_message($message);
    if (($provider['bot_key'] ?? '') !== $botKey) {
        $failures[] = 'bot_key_not_preserved:' . $botKey;
    }
    if (($provider['delivery'] ?? '') !== 'telegram_bot') {
        $failures[] = 'telegram_bot_delivery_not_selected:' . $botKey;
    }
    if (($provider['destination'] ?? '') !== $message['destination']) {
        $failures[] = 'message_destination_not_preserved:' . $botKey;
    }
}

$missingRouteProvider = app_communication_queue_resolve_provider_for_message([
    'id' => 0,
    'channel' => 'telegram',
    'destination' => '',
    'message_body' => 'Routing smoke missing bot',
    'metadata_json' => app_db_json_encode([]),
]);
if (!empty($missingRouteProvider['configured'])) {
    $failures[] = 'telegram_missing_bot_and_destination_was_configured';
}
if (($missingRouteProvider['reason'] ?? '') !== 'missing_telegram_bot_key_or_destination') {
    $failures[] = 'telegram_missing_bot_guard_reason:' . (string) ($missingRouteProvider['reason'] ?? '');
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    echo json_encode([
        'status' => 'failed',
        'reason' => 'db_unavailable',
        'failures' => $failures,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(5);
}

app_mgs_sync_telegram_bot_registry($db);
$placeholders = implode(',', array_fill(0, count($requiredBots), '?'));
$statement = $db->prepare('SELECT bot_key FROM telegram_bot_registry WHERE bot_key IN (' . $placeholders . ')');
$statement->execute($requiredBots);
$registryBots = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
foreach ($requiredBots as $botKey) {
    if (!in_array($botKey, $registryBots, true)) {
        $failures[] = 'registry_missing_bot:' . $botKey;
    }
}

$runId = 'bot-routing-smoke-' . date('His') . '-' . bin2hex(random_bytes(3));
try {
    foreach ($requiredBots as $botKey) {
        $queued = app_mgs_queue_telegram_bot_message(
            $botKey,
            'smoke_' . $botKey,
            'TEST MESAJI ' . $runId . ' ' . $botKey,
            ['run_id' => $runId],
            true
        );
        if (empty($queued['ok'])) {
            $failures[] = 'message_not_queued:' . $botKey . ':' . (string) ($queued['reason'] ?? '');
        }
    }

    $statement = $db->prepare(
        'SELECT metadata_json, recipient_name, channel, template_code
         FROM communication_messages
         WHERE metadata_json LIKE :needle
         ORDER BY id ASC'
    );
    $statement->execute(['needle' => '%' . $runId . '%']);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($rows) !== count($requiredBots)) {
        $failures[] = 'queued_message_count_mismatch:' . count($rows);
    }
    foreach ($rows as $row) {
        $metadata = app_db_json_decode((string) ($row['metadata_json'] ?? ''), []);
        $botKey = (string) ($metadata['bot_key'] ?? '');
        if (!in_array($botKey, $requiredBots, true)) {
            $failures[] = 'queued_message_invalid_bot_key:' . $botKey;
        }
        if (($row['channel'] ?? '') !== 'telegram') {
            $failures[] = 'queued_message_invalid_channel:' . $botKey;
        }
    }
} finally {
    $db->prepare('DELETE FROM communication_messages WHERE metadata_json LIKE :needle')->execute([
        'needle' => '%' . $runId . '%',
    ]);
}

echo json_encode([
    'status' => $failures === [] ? 'ok' : 'failed',
    'checked_bots' => $requiredBots,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 5);
