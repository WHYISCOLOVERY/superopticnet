<?php
declare(strict_types=1);

require dirname(__DIR__) . '/communication-queue-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

function queue_worker_usage(): void
{
    echo "Usage: php tools/process-communication-queue.php [--apply] [--limit=50] [--channel=all|app|telegram|sms|whatsapp] [--fail-unconfigured]\n";
    echo "\n";
    echo "Default mode is dry-run. Without --apply no database status is changed and no provider request is sent.\n";
}

function queue_worker_option_value(array $argv, string $name, string $default = ''): string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string) $arg, $prefix)) {
            return substr((string) $arg, strlen($prefix));
        }
    }

    return $default;
}

function queue_worker_json_out(array $payload, int $exitCode = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($exitCode);
}

function queue_worker_metadata(array $message): array
{
    return app_db_json_decode((string) ($message['metadata_json'] ?? ''), []);
}

function queue_worker_update_metadata(PDO $db, int $messageId, array $metadata): void
{
    $statement = $db->prepare(
        'UPDATE communication_messages
         SET metadata_json = :metadata_json
         WHERE id = :id'
    );
    $statement->execute([
        'metadata_json' => app_db_json_encode($metadata),
        'id' => $messageId,
    ]);
}

function queue_worker_mark_sent(PDO $db, array $message, array $workerMeta): void
{
    $messageId = (int) ($message['id'] ?? 0);
    if ($messageId <= 0) {
        return;
    }

    $metadata = queue_worker_metadata($message);
    $metadata['worker'] = $workerMeta + [
        'status' => 'sent',
        'processed_at' => app_db_now(),
    ];

    $statement = $db->prepare(
        'UPDATE communication_messages
         SET status = "sent", sent_at = NOW(), metadata_json = :metadata_json
         WHERE id = :id AND status = "queued"'
    );
    $statement->execute([
        'metadata_json' => app_db_json_encode($metadata),
        'id' => $messageId,
    ]);
}

function queue_worker_mark_failed(PDO $db, array $message, array $workerMeta): void
{
    $messageId = (int) ($message['id'] ?? 0);
    if ($messageId <= 0) {
        return;
    }

    $metadata = queue_worker_metadata($message);
    $metadata['worker'] = $workerMeta + [
        'status' => 'failed',
        'processed_at' => app_db_now(),
    ];

    $statement = $db->prepare(
        'UPDATE communication_messages
         SET status = "failed", metadata_json = :metadata_json
         WHERE id = :id AND status = "queued"'
    );
    $statement->execute([
        'metadata_json' => app_db_json_encode($metadata),
        'id' => $messageId,
    ]);
}

function queue_worker_provider_config(string $channel, string $destination): array
{
    return app_communication_provider_config($channel, $destination);
}

function queue_worker_resolve_provider(string $preferredChannel, string $destination): array
{
    return app_communication_queue_standard_provider($preferredChannel, $destination);
}

function queue_worker_mask_destination(string $value): string
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

function queue_worker_log_attempt(PDO $db, array $message, array $provider, array $sendResult, string $attemptStatus = ''): void
{
    $messageId = (int) ($message['id'] ?? 0);
    $accountId = (int) ($message['account_id'] ?? 0);
    $ok = !empty($sendResult['ok']);
    $status = $attemptStatus !== '' ? $attemptStatus : ($ok ? 'sent' : 'failed');
    if (!in_array($status, ['sent', 'failed', 'skipped'], true)) {
        $status = $ok ? 'sent' : 'failed';
    }

    $responseSummary = [
        'ok' => $ok,
        'delivery' => (string) ($sendResult['delivery'] ?? $provider['delivery'] ?? ''),
        'reason' => (string) ($sendResult['reason'] ?? ''),
        'provider_message_id_present' => !empty($sendResult['provider_message_id']),
    ];

    $statement = $db->prepare(
        'INSERT INTO communication_delivery_attempts (
            message_id, account_id, subscriber_code, channel, delivery, destination_masked,
            status, provider_message_id, reason, response_summary_json
         ) VALUES (
            :message_id, :account_id, :subscriber_code, :channel, :delivery, :destination_masked,
            :status, :provider_message_id, :reason, :response_summary_json
         )'
    );
    $statement->execute([
        'message_id' => $messageId > 0 ? $messageId : null,
        'account_id' => $accountId > 0 ? $accountId : null,
        'subscriber_code' => (string) ($message['subscriber_code'] ?? ''),
        'channel' => strtolower((string) ($provider['channel'] ?? $message['channel'] ?? '')),
        'delivery' => (string) ($sendResult['delivery'] ?? $provider['delivery'] ?? ''),
        'destination_masked' => queue_worker_mask_destination((string) ($provider['destination'] ?? $message['destination'] ?? '')),
        'status' => $status,
        'provider_message_id' => isset($sendResult['provider_message_id']) ? (string) $sendResult['provider_message_id'] : null,
        'reason' => (string) ($sendResult['reason'] ?? ''),
        'response_summary_json' => app_db_json_encode($responseSummary),
    ]);
}

function queue_worker_log_run(PDO $db, array $summary, string $startedAt, float $startedFloat, bool $failUnconfigured, int $limit): bool
{
    $durationMs = max(0, (int) round((microtime(true) - $startedFloat) * 1000));

    try {
        $statement = $db->prepare(
            'INSERT INTO communication_worker_runs (
                mode, channel, status, limit_count, scanned_count, would_send_count,
                sent_count, failed_count, left_queued_count, unconfigured_count,
                fail_unconfigured, duration_ms, by_channel_json, summary_json, started_at, finished_at
             ) VALUES (
                :mode, :channel, "completed", :limit_count, :scanned_count, :would_send_count,
                :sent_count, :failed_count, :left_queued_count, :unconfigured_count,
                :fail_unconfigured, :duration_ms, :by_channel_json, :summary_json, :started_at, NOW()
             )'
        );
        $statement->execute([
            'mode' => (string) ($summary['mode'] ?? ''),
            'channel' => (string) ($summary['channel'] ?? ''),
            'limit_count' => $limit,
            'scanned_count' => (int) ($summary['scanned'] ?? 0),
            'would_send_count' => (int) ($summary['would_send'] ?? 0),
            'sent_count' => (int) ($summary['sent'] ?? 0),
            'failed_count' => (int) ($summary['failed'] ?? 0),
            'left_queued_count' => (int) ($summary['left_queued'] ?? 0),
            'unconfigured_count' => (int) ($summary['unconfigured'] ?? 0),
            'fail_unconfigured' => $failUnconfigured ? 1 : 0,
            'duration_ms' => $durationMs,
            'by_channel_json' => app_db_json_encode($summary['by_channel'] ?? []),
            'summary_json' => app_db_json_encode($summary),
            'started_at' => $startedAt,
        ]);
        return true;
    } catch (Throwable) {
        return false;
    }
}

function queue_worker_send_provider(array $message, array $provider): array
{
    return app_communication_queue_send_provider_for_message($message, $provider);
}

function queue_worker_is_deferred_reason(string $reason): bool
{
    return str_starts_with($reason, 'whatsapp_rate_limited')
        || $reason === 'whatsapp_not_ready'
        || $reason === 'whatsapp_bridge_request_failed';
}

function queue_worker_should_sms_fallback(string $channel, string $reason): bool
{
    return $channel === 'whatsapp' && str_starts_with($reason, 'whatsapp_rate_limited');
}

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    queue_worker_usage();
    exit(0);
}

$apply = in_array('--apply', $argv, true);
$failUnconfigured = in_array('--fail-unconfigured', $argv, true);
$startedAt = app_db_now();
$startedFloat = microtime(true);
$limit = (int) queue_worker_option_value($argv, 'limit', '50');
$limit = max(1, min(500, $limit));
$channel = strtolower(trim(queue_worker_option_value($argv, 'channel', 'all')));
$allowedChannels = ['all', 'app', 'telegram', 'sms', 'whatsapp'];
if (!in_array($channel, $allowedChannels, true)) {
    fwrite(STDERR, "Invalid channel: {$channel}\n");
    queue_worker_usage();
    exit(2);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "MySQL/PDO connection is not ready.\n");
    exit(3);
}

$sql = 'SELECT *
        FROM communication_messages
        WHERE status = "queued"';
$params = [];
if ($channel !== 'all') {
    $sql .= ' AND channel = :channel';
    $params['channel'] = $channel;
}
$sql .= ' ORDER BY created_at ASC, id ASC LIMIT ' . $limit;

$statement = $db->prepare($sql);
$statement->execute($params);
$messages = $statement->fetchAll() ?: [];

$summary = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'channel' => $channel,
    'scanned' => count($messages),
    'would_send' => 0,
    'sent' => 0,
    'failed' => 0,
    'left_queued' => 0,
    'unconfigured' => 0,
    'by_channel' => [
        'app' => 0,
        'telegram' => 0,
        'sms' => 0,
        'whatsapp' => 0,
        'unsupported' => 0,
    ],
];

foreach ($messages as $message) {
    if (!is_array($message)) {
        continue;
    }

    $messageChannel = strtolower((string) ($message['channel'] ?? ''));
    if (!isset($summary['by_channel'][$messageChannel])) {
        $messageChannel = 'unsupported';
    }
    $summary['by_channel'][$messageChannel]++;

    $provider = app_communication_queue_resolve_provider_for_message($message);

    if (empty($provider['configured'])) {
        $summary['unconfigured']++;
        if ($apply && $failUnconfigured) {
            queue_worker_log_attempt($db, $message, $provider, [
                'ok' => false,
                'delivery' => (string) ($provider['delivery'] ?? 'unsupported'),
                'reason' => 'provider_not_configured',
            ], 'failed');
            queue_worker_mark_failed($db, $message, [
                'provider' => (string) ($provider['delivery'] ?? 'unsupported'),
                'reason' => 'provider_not_configured',
                'preferred_channel' => (string) ($provider['preferred_channel'] ?? $messageChannel),
                'final_channel' => (string) ($provider['channel'] ?? $messageChannel),
            ]);
            $summary['failed']++;
        } else {
            $summary['left_queued']++;
        }
        continue;
    }

    if (!$apply) {
        $summary['would_send']++;
        continue;
    }

    $sendResult = queue_worker_send_provider($message, $provider);
    if (!empty($sendResult['ok'])) {
        queue_worker_log_attempt($db, $message, $provider, $sendResult);
        queue_worker_mark_sent($db, $message, [
            'provider' => (string) ($provider['delivery'] ?? $messageChannel),
            'provider_message_id' => $sendResult['provider_message_id'] ?? null,
            'preferred_channel' => (string) ($provider['preferred_channel'] ?? $messageChannel),
            'final_channel' => (string) ($provider['channel'] ?? $messageChannel),
        ]);
        $summary['sent']++;
        continue;
    }

    $reason = (string) ($sendResult['reason'] ?? '');
    if (queue_worker_should_sms_fallback((string) ($provider['channel'] ?? $messageChannel), $reason)) {
        $smsProvider = queue_worker_provider_config('sms', trim((string) ($message['destination'] ?? '')));
        $smsProvider['channel'] = 'sms';
        $smsProvider['preferred_channel'] = 'whatsapp';
        if (!empty($smsProvider['configured'])) {
            $smsResult = queue_worker_send_provider($message, $smsProvider);
            queue_worker_log_attempt($db, $message, $smsProvider, $smsResult);
            if (!empty($smsResult['ok'])) {
                queue_worker_mark_sent($db, $message, [
                    'provider' => (string) ($smsProvider['delivery'] ?? 'sms_provider'),
                    'provider_message_id' => $smsResult['provider_message_id'] ?? null,
                    'preferred_channel' => 'whatsapp',
                    'final_channel' => 'sms',
                    'fallback_reason' => $reason,
                ]);
                $summary['sent']++;
                continue;
            }

            $reason = (string) ($smsResult['reason'] ?? 'sms_fallback_failed');
        }
    }

    if (queue_worker_is_deferred_reason($reason)) {
        queue_worker_log_attempt($db, $message, $provider, $sendResult, 'skipped');
        $metadata = queue_worker_metadata($message);
        $metadata['worker'] = [
            'status' => 'deferred',
            'provider' => (string) ($provider['delivery'] ?? $messageChannel),
            'reason' => $reason,
            'preferred_channel' => (string) ($provider['preferred_channel'] ?? $messageChannel),
            'final_channel' => (string) ($provider['channel'] ?? $messageChannel),
            'processed_at' => app_db_now(),
        ];
        queue_worker_update_metadata($db, (int) ($message['id'] ?? 0), $metadata);
        $summary['left_queued']++;
        continue;
    }

    queue_worker_log_attempt($db, $message, $provider, $sendResult);
    queue_worker_mark_failed($db, $message, [
        'provider' => (string) ($provider['delivery'] ?? $messageChannel),
        'reason' => 'provider_request_failed',
        'preferred_channel' => (string) ($provider['preferred_channel'] ?? $messageChannel),
        'final_channel' => (string) ($provider['channel'] ?? $messageChannel),
    ]);
    $summary['failed']++;
}

if ($apply) {
    $summary['run_logged'] = queue_worker_log_run($db, $summary, $startedAt, $startedFloat, $failUnconfigured, $limit);
} else {
    $summary['run_logged'] = false;
}

queue_worker_json_out($summary);
