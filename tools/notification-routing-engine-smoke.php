<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/mgs-integration-lib.php';

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

$failures = [];
$matrix = [
    ['category' => 'CUSTOMER_REQUEST', 'event_type' => 'customer_request', 'expected_bot' => 'customer'],
    ['category' => 'CUSTOMER_TECHNICAL_SUPPORT', 'event_type' => 'customer_ticket', 'expected_bot' => 'customer'],
    ['category' => 'CUSTOMER_BILLING_REQUEST', 'event_type' => 'customer_payment_question', 'expected_bot' => 'customer'],
    ['category' => 'NETWORK', 'event_type' => 'device_offline', 'expected_bot' => 'network'],
    ['category' => 'OLT', 'event_type' => 'olt_offline', 'expected_bot' => 'network'],
    ['category' => 'ONT', 'event_type' => 'ont_reboot_storm', 'expected_bot' => 'network'],
    ['category' => 'OPTICAL', 'event_type' => 'rx_tx_degraded', 'expected_bot' => 'network'],
    ['category' => 'RADIUS', 'event_type' => 'radius_down', 'expected_bot' => 'network'],
    ['category' => 'PAYMENT_PROVIDER', 'event_type' => 'provider_error', 'expected_bot' => 'finance'],
    ['category' => 'FINANCE', 'event_type' => 'daily_report', 'expected_bot' => 'finance'],
    ['category' => 'INVENTORY', 'event_type' => 'low_stock', 'expected_bot' => 'warehouse'],
    ['category' => 'BACKUP', 'event_type' => 'backup_failed', 'expected_bot' => 'backup'],
    ['category' => 'SECURITY', 'event_type' => 'brute_force', 'expected_bot' => 'superpilot'],
    ['category' => 'SYSTEM_HEALTH', 'event_type' => 'disk_full', 'expected_bot' => 'superpilot'],
    ['category' => 'UNCLASSIFIED', 'event_type' => 'unknown_event', 'expected_bot' => 'superpilot'],
];

foreach ($matrix as $case) {
    $route = app_notification_resolve_destination([
        'category' => $case['category'],
        'event_type' => $case['event_type'],
        'message' => 'Smoke routing ' . $case['event_type'],
    ]);
    if (($route['bot_key'] ?? '') !== $case['expected_bot']) {
        $failures[] = 'route_mismatch:' . $case['category'] . ':' . (string) ($route['bot_key'] ?? '');
    }
    if (($case['expected_bot'] ?? '') !== 'customer' && ($route['bot_key'] ?? '') === 'customer') {
        $failures[] = 'non_customer_routed_to_customer:' . $case['category'];
    }
}

$runId = 'notification-routing-smoke-' . date('His') . '-' . bin2hex(random_bytes(3));
$dedupKey = $runId . '-olt';

try {
    $wrongBotAlarm = app_mgs_record_alarm_event([
        'dedup_key' => $dedupKey,
        'alarm_type' => 'OLT_OFFLINE',
        'severity' => 'critical',
        'source' => 'olt_rule_engine',
        'object_type' => 'olt',
        'object_id' => 'smoke-olt',
        'device_name' => 'SMOKE OLT',
        'device_ip' => '172.16.5.250',
        'title' => 'SMOKE OLT offline',
        'human_problem' => 'This is network-only and must not go to customer request group.',
        'recommendation' => 'NOC yoxlasin.',
        'telegram_bot_key' => 'customer',
    ], true, true);
    if (empty($wrongBotAlarm['ok'])) {
        $failures[] = 'wrong_bot_alarm_not_recorded';
    }

    for ($i = 0; $i < 20; $i++) {
        app_notification_route_event([
            'category' => 'NETWORK',
            'event_type' => 'OLT_OFFLINE',
            'dedup_key' => $runId . '-duplicate',
            'title' => 'Duplicate OLT alarm',
            'message' => 'Duplicate OLT alarm password: verysecret phone +994501112233',
        ], true, true);
    }

    $statement = $db->prepare(
        'SELECT metadata_json, message_body
         FROM communication_messages
         WHERE metadata_json LIKE :needle
         ORDER BY id ASC'
    );
    $statement->execute(['needle' => '%' . $runId . '%']);
    $messages = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $networkAlarmMessages = 0;
    $customerMisroutes = 0;
    $duplicateMessages = 0;
    $privacyMasked = false;
    foreach ($messages as $message) {
        $metadata = app_db_json_decode((string) ($message['metadata_json'] ?? ''), []);
        $botKey = (string) ($metadata['bot_key'] ?? '');
        $category = (string) ($metadata['notification_category'] ?? '');
        $dedup = (string) ($metadata['notification_dedup_key'] ?? '');
        if ($category !== '' && $category !== 'CUSTOMER_REQUEST' && str_starts_with($category, 'CUSTOMER') === false && $botKey === 'customer') {
            $customerMisroutes++;
        }
        if ($dedup === $runId . '-duplicate') {
            $duplicateMessages++;
            $privacyMasked = str_contains((string) ($message['message_body'] ?? ''), 'password: ***')
                && str_contains((string) ($message['message_body'] ?? ''), '+994***33');
        }
        if ($dedup === $dedupKey && $botKey === 'network') {
            $networkAlarmMessages++;
        }
    }

    if ($networkAlarmMessages !== 1) {
        $failures[] = 'forced_customer_alarm_not_routed_to_network:' . $networkAlarmMessages;
    }
    if ($customerMisroutes > 0) {
        $failures[] = 'non_customer_messages_in_customer_bot:' . $customerMisroutes;
    }
    if ($duplicateMessages !== 1) {
        $failures[] = 'duplicate_suppression_failed:' . $duplicateMessages;
    }
    if (!$privacyMasked) {
        $failures[] = 'privacy_masking_failed';
    }
} finally {
    $db->prepare('DELETE FROM communication_messages WHERE metadata_json LIKE :needle')->execute(['needle' => '%' . $runId . '%']);
    $db->prepare('DELETE FROM mgs_alarm_events WHERE dedup_key LIKE :needle')->execute(['needle' => $runId . '%']);
    $db->prepare('DELETE FROM mgs_dedup_registry WHERE dedup_key LIKE :needle')->execute(['needle' => $runId . '%']);
    $db->prepare('DELETE FROM mgs_operation_actions WHERE entity_id LIKE :needle OR payload_json LIKE :payload')->execute([
        'needle' => $runId . '%',
        'payload' => '%' . $runId . '%',
    ]);
}

echo json_encode([
    'status' => $failures === [] ? 'ok' : 'failed',
    'checked_cases' => count($matrix),
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 3);
