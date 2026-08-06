<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/communication-queue-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

function pg365_status_worker_usage(): void
{
    echo "Usage: php tools/pg365-status-worker.php [--apply] [--limit=100] [--single]\n";
    echo "\n";
    echo "Default mode is dry-run. Without --apply no PG365 request is made and no metadata is changed.\n";
}

function pg365_status_worker_option_value(array $argv, string $name, string $default = ''): string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $argument) {
        if (str_starts_with((string) $argument, $prefix)) {
            return substr((string) $argument, strlen($prefix));
        }
    }

    return $default;
}

function pg365_status_worker_out(array $payload, int $exitCode = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($exitCode);
}

function pg365_status_worker_driver_supported(string $driver): bool
{
    return in_array(strtolower(trim($driver)), ['pg365', 'pg365_json', 'poctgoyercini_json'], true);
}

function pg365_status_worker_delivery_state(array $status): string
{
    $state = strtolower(trim((string) ($status['delivery_state'] ?? 'accepted')));
    $state = match ($state) {
        'sent', 'sending' => 'submitted',
        'failed' => 'final_failed',
        default => $state,
    };

    return in_array($state, ['accepted', 'submitted', 'delivered', 'final_failed'], true)
        ? $state
        : (!empty($status['is_final']) ? 'final_failed' : 'accepted');
}

function pg365_status_worker_count_state(array &$summary, array $status): void
{
    $state = pg365_status_worker_delivery_state($status);
    if (array_key_exists($state, $summary)) {
        $summary[$state]++;
        return;
    }

    $summary['pending']++;
}

function pg365_status_worker_update(PDO $db, int $messageId, array $status): bool
{
    if (!app_communication_queue_claim($db, $messageId)) {
        return false;
    }

    try {
        $statement = $db->prepare(
            'SELECT metadata_json, provider_message_id
             FROM communication_messages
             WHERE id = :id AND status = "sent" AND provider_status_final = 0
             LIMIT 1'
        );
        $statement->execute(['id' => $messageId]);
        $message = $statement->fetch();
        if (!is_array($message)) {
            return false;
        }

        $metadataJson = (string) ($message['metadata_json'] ?? '');
        $metadata = app_db_json_decode($metadataJson, []);
        $deliveryState = pg365_status_worker_delivery_state($status);
        $isFinal = $deliveryState === 'delivered'
            || $deliveryState === 'final_failed'
            || !empty($status['is_final']);
        $providerMessageId = trim((string) ($status['id'] ?? $message['provider_message_id'] ?? ''));
        $metadata['pg365_status'] = $status + ['polled_at' => app_db_now()];
        $worker = is_array($metadata['worker'] ?? null) ? $metadata['worker'] : [];
        $worker['status'] = $deliveryState;
        $worker['delivery_state'] = $deliveryState;
        $worker['provider_status'] = $deliveryState;
        $worker['provider_status_code'] = (int) ($status['status_code'] ?? 0);
        $worker['provider_status_description'] = (string) ($status['status_description'] ?? '');
        $worker['provider_status_final'] = $isFinal;
        $metadata['worker'] = $worker;

        $update = $db->prepare(
            'UPDATE communication_messages
             SET status = CASE WHEN :set_failed_status = 1 THEN "failed" ELSE status END,
                 delivery_state = :delivery_state,
                 provider_message_id = COALESCE(NULLIF(:provider_message_id, ""), provider_message_id),
                 provider_status_code = :provider_status_code,
                 provider_status_description = :provider_status_description,
                 provider_status_final = :provider_status_final,
                 submitted_at = CASE
                    WHEN :is_submitted = 1 THEN COALESCE(submitted_at, NOW())
                    ELSE submitted_at
                 END,
                 delivered_at = CASE
                    WHEN :is_delivered = 1 THEN COALESCE(delivered_at, NOW())
                    ELSE delivered_at
                 END,
                 final_failed_at = CASE
                    WHEN :is_final_failed = 1 THEN COALESCE(final_failed_at, NOW())
                    ELSE final_failed_at
                 END,
                 delivery_updated_at = NOW(),
                 metadata_json = :metadata_json
             WHERE id = :id AND status = "sent" AND provider_status_final = 0'
        );
        $update->execute([
            'delivery_state' => $deliveryState,
            'provider_message_id' => $providerMessageId,
            'provider_status_code' => (int) ($status['status_code'] ?? 0),
            'provider_status_description' => (string) ($status['status_description'] ?? '') ?: null,
            'provider_status_final' => $isFinal ? 1 : 0,
            'set_failed_status' => $deliveryState === 'final_failed' ? 1 : 0,
            'is_submitted' => in_array($deliveryState, ['submitted', 'delivered'], true) ? 1 : 0,
            'is_delivered' => $deliveryState === 'delivered' ? 1 : 0,
            'is_final_failed' => $deliveryState === 'final_failed' ? 1 : 0,
            'metadata_json' => app_db_json_encode($metadata),
            'id' => $messageId,
        ]);

        if ($update->rowCount() === 1 && $providerMessageId !== '') {
            $attemptUpdate = $db->prepare(
                'UPDATE communication_delivery_attempts
                 SET status = CASE WHEN :set_failed_status = 1 THEN "failed" ELSE status END,
                     delivery_state = :delivery_state,
                     provider_status_code = :provider_status_code,
                     provider_status_description = :provider_status_description,
                     provider_status_final = :provider_status_final,
                     delivery_updated_at = NOW()
                 WHERE message_id = :message_id AND provider_message_id = :provider_message_id'
            );
            $attemptUpdate->execute([
                'delivery_state' => $deliveryState,
                'provider_status_code' => (int) ($status['status_code'] ?? 0),
                'provider_status_description' => (string) ($status['status_description'] ?? '') ?: null,
                'provider_status_final' => $isFinal ? 1 : 0,
                'set_failed_status' => $deliveryState === 'final_failed' ? 1 : 0,
                'message_id' => $messageId,
                'provider_message_id' => $providerMessageId,
            ]);
        }

        return $update->rowCount() === 1;
    } finally {
        app_communication_queue_release_claim($db, $messageId);
    }
}

if (defined('APP_PG365_STATUS_WORKER_LIBRARY_ONLY') && APP_PG365_STATUS_WORKER_LIBRARY_ONLY === true) {
    return;
}

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    pg365_status_worker_usage();
    exit(0);
}

$apply = in_array('--apply', $argv, true);
$single = in_array('--single', $argv, true);
$limit = max(1, min(500, (int) pg365_status_worker_option_value($argv, 'limit', '100')));
$scanLimit = min(5000, max($limit * 10, 500));
$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "MySQL/PDO connection is not ready.\n");
    exit(3);
}

$statement = $db->query(
    'SELECT
        m.id AS message_id,
        m.channel,
        m.delivery_state,
        m.provider_message_id,
        m.provider_status_final,
        m.metadata_json
     FROM communication_messages m
     WHERE m.status = "sent"
       AND m.provider_status_final = 0
       AND m.delivery_state IN ("pending","accepted","submitted")
       AND (NULLIF(m.provider_message_id, "") IS NOT NULL OR m.metadata_json LIKE "%provider_message_id%")
       AND (m.channel = "sms" OR m.metadata_json LIKE "%\"final_channel\":\"sms\"%")
     ORDER BY COALESCE(m.delivery_updated_at, m.sent_at, m.created_at) ASC, m.id ASC
     LIMIT ' . $scanLimit
);
$rows = $statement->fetchAll() ?: [];

$summary = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'poll_method' => $single ? 'status' : 'statusmany',
    'scanned' => count($rows),
    'candidates' => 0,
    'updated' => 0,
    'delivered' => 0,
    'accepted' => 0,
    'submitted' => 0,
    'final_failed' => 0,
    'pending' => 0,
    'missing_from_response' => 0,
    'request_errors' => 0,
    'skipped_final' => 0,
    'backfilled_final' => 0,
    'skipped_other_driver' => 0,
    'skipped_duplicate' => 0,
];

$candidates = [];
$seenMessages = [];
$seenReceiverIds = [];
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $messageId = (int) ($row['message_id'] ?? 0);
    if ($messageId <= 0) {
        continue;
    }

    $metadata = app_db_json_decode((string) ($row['metadata_json'] ?? ''), []);
    $statusMetadata = is_array($metadata['pg365_status'] ?? null) ? $metadata['pg365_status'] : [];
    $workerMetadata = is_array($metadata['worker'] ?? null) ? $metadata['worker'] : [];
    $driver = trim((string) ($workerMetadata['provider_driver'] ?? ''));
    $finalChannel = strtolower(trim((string) ($workerMetadata['final_channel'] ?? $row['channel'] ?? '')));
    if ($finalChannel !== 'sms' || ($driver !== '' && !pg365_status_worker_driver_supported($driver))) {
        $summary['skipped_other_driver']++;
        continue;
    }
    $receiverId = trim((string) ($row['provider_message_id'] ?? $workerMetadata['provider_message_id'] ?? ''));
    if ($receiverId === '') {
        continue;
    }
    if (isset($seenMessages[$messageId]) || isset($seenReceiverIds[$receiverId])) {
        $summary['skipped_duplicate']++;
        continue;
    }
    if (count($seenMessages) >= $limit) {
        break;
    }
    $seenMessages[$messageId] = true;
    $seenReceiverIds[$receiverId] = true;

    $storedFinalStatus = [];
    if (!empty($statusMetadata['is_final'])) {
        $storedFinalStatus = $statusMetadata;
    } elseif (!empty($workerMetadata['provider_status_final'])) {
        $storedFinalStatus = [
            'id' => $receiverId,
            'status_code' => (int) ($workerMetadata['provider_status_code'] ?? 0),
            'status_description' => (string) ($workerMetadata['provider_status_description'] ?? ''),
            'is_final' => true,
            'delivery_state' => (string) ($workerMetadata['delivery_state'] ?? $workerMetadata['provider_status'] ?? 'final_failed'),
        ];
    }
    if ($storedFinalStatus !== []) {
        $storedFinalStatus['id'] = (string) ($storedFinalStatus['id'] ?? $receiverId) ?: $receiverId;
        $storedFinalStatus['is_final'] = true;
        if ($apply && pg365_status_worker_update($db, $messageId, $storedFinalStatus)) {
            $summary['updated']++;
            $summary['backfilled_final']++;
            pg365_status_worker_count_state($summary, $storedFinalStatus);
        } else {
            $summary['skipped_final']++;
        }
        continue;
    }
    $candidates[] = [
        'message_id' => $messageId,
        'receiver_id' => $receiverId,
    ];
    if (count($seenMessages) >= $limit) {
        break;
    }
}
$summary['candidates'] = count($candidates);

if (!$apply || $candidates === []) {
    pg365_status_worker_out($summary);
}

$provider = app_communication_provider_config('sms', 'status-poll');
$provider['channel'] = 'sms';
if (empty($provider['status_configured'])) {
    $summary['reason'] = (string) ($provider['reason'] ?? '') ?: 'provider_not_configured_for_status';
    pg365_status_worker_out($summary, 4);
}
if (!pg365_status_worker_driver_supported((string) ($provider['driver'] ?? ''))) {
    $summary['reason'] = 'sms_provider_driver_not_pg365';
    pg365_status_worker_out($summary, 4);
}

$statusesById = [];
if ($single) {
    foreach ($candidates as $candidate) {
        $poll = app_communication_pg365_poll_status($provider, (string) $candidate['receiver_id']);
        if (empty($poll['ok'])) {
            $summary['request_errors']++;
            $summary['last_error'] = (string) ($poll['reason'] ?? 'sms_provider_request_failed');
            continue;
        }
        $item = $poll['item'] ?? null;
        if (is_array($item)) {
            $statusesById[(string) ($item['id'] ?? '')] = $item;
        }
    }
} else {
    $poll = app_communication_pg365_poll_status_many(
        $provider,
        array_column($candidates, 'receiver_id')
    );
    if (empty($poll['ok'])) {
        $summary['request_errors']++;
        $summary['last_error'] = (string) ($poll['reason'] ?? 'sms_provider_request_failed');
        pg365_status_worker_out($summary, 5);
    }
    foreach (($poll['items'] ?? []) as $item) {
        if (is_array($item) && (string) ($item['id'] ?? '') !== '') {
            $statusesById[(string) $item['id']] = $item;
        }
    }
}

foreach ($candidates as $candidate) {
    $receiverId = (string) $candidate['receiver_id'];
    $status = $statusesById[$receiverId] ?? null;
    if (!is_array($status)) {
        $summary['missing_from_response']++;
        continue;
    }

    pg365_status_worker_count_state($summary, $status);
    if (pg365_status_worker_update($db, (int) $candidate['message_id'], $status)) {
        $summary['updated']++;
    }
}

pg365_status_worker_out($summary, $summary['request_errors'] > 0 ? 5 : 0);
