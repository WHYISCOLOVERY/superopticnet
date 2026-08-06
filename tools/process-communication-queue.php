<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/communication-queue-lib.php';

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

function queue_worker_prepare_retry(PDO $db, array $message): array
{
    if ((string) ($message['delivery_state'] ?? '') !== 'final_failed') {
        return $message;
    }

    $messageId = (int) ($message['id'] ?? 0);
    if ($messageId <= 0) {
        return $message;
    }
    $statement = $db->prepare(
        'UPDATE communication_messages
         SET delivery_state = "pending",
             provider_message_id = NULL,
             provider_status_code = NULL,
             provider_status_description = NULL,
             provider_status_final = 0,
             accepted_at = NULL,
             submitted_at = NULL,
             delivered_at = NULL,
             final_failed_at = NULL,
             delivery_updated_at = NOW()
         WHERE id = :id AND status = "queued" AND delivery_state = "final_failed"'
    );
    $statement->execute(['id' => $messageId]);
    if ($statement->rowCount() !== 1) {
        return $message;
    }

    return array_replace($message, [
        'delivery_state' => 'pending',
        'provider_message_id' => null,
        'provider_status_code' => null,
        'provider_status_description' => null,
        'provider_status_final' => 0,
    ]);
}

function queue_worker_delivery_state(string $state, bool $ok): string
{
    $state = strtolower(trim($state));
    $state = match ($state) {
        'sent', 'sending' => 'submitted',
        'failed' => 'final_failed',
        default => $state,
    };

    return in_array($state, ['pending', 'accepted', 'submitted', 'delivered', 'final_failed'], true)
        ? $state
        : ($ok ? 'accepted' : 'final_failed');
}

function queue_worker_mark_sent(PDO $db, array $message, array $workerMeta): bool
{
    $messageId = (int) ($message['id'] ?? 0);
    if ($messageId <= 0) {
        return false;
    }

    $metadata = queue_worker_metadata($message);
    $deliveryState = queue_worker_delivery_state(
        (string) ($workerMeta['delivery_state'] ?? $workerMeta['provider_status'] ?? ''),
        true
    );
    $workerMeta['delivery_state'] = $deliveryState;
    $metadata['worker'] = $workerMeta + [
        'status' => $deliveryState,
        'processed_at' => app_db_now(),
    ];

    $statement = $db->prepare(
        'UPDATE communication_messages
         SET status = "sent",
             channel = CASE
                WHEN :final_channel_valid = 1 THEN :final_channel
                ELSE channel
             END,
             delivery_state = :delivery_state,
             provider_message_id = :provider_message_id,
             provider_status_code = :provider_status_code,
             provider_status_description = :provider_status_description,
             provider_status_final = :provider_status_final,
             sent_at = NOW(),
             accepted_at = COALESCE(accepted_at, NOW()),
             submitted_at = CASE WHEN :is_submitted = 1 THEN COALESCE(submitted_at, NOW()) ELSE submitted_at END,
             delivered_at = CASE WHEN :is_delivered = 1 THEN COALESCE(delivered_at, NOW()) ELSE delivered_at END,
             final_failed_at = CASE WHEN :is_final_failed = 1 THEN COALESCE(final_failed_at, NOW()) ELSE final_failed_at END,
             delivery_updated_at = NOW(),
             metadata_json = :metadata_json
         WHERE id = :id AND status = "queued"'
    );
    $statement->execute([
        'delivery_state' => $deliveryState,
        'final_channel' => (string) ($workerMeta['final_channel'] ?? ''),
        'final_channel_valid' => in_array((string) ($workerMeta['final_channel'] ?? ''), ['app', 'telegram', 'sms', 'whatsapp'], true) ? 1 : 0,
        'provider_message_id' => ($workerMeta['provider_message_id'] ?? null) ?: null,
        'provider_status_code' => isset($workerMeta['provider_status_code']) ? (int) $workerMeta['provider_status_code'] : null,
        'provider_status_description' => ($workerMeta['provider_status_description'] ?? null) ?: null,
        'provider_status_final' => !empty($workerMeta['provider_status_final']) ? 1 : 0,
        'is_submitted' => $deliveryState === 'submitted' ? 1 : 0,
        'is_delivered' => $deliveryState === 'delivered' ? 1 : 0,
        'is_final_failed' => $deliveryState === 'final_failed' ? 1 : 0,
        'metadata_json' => app_db_json_encode($metadata),
        'id' => $messageId,
    ]);

    return $statement->rowCount() === 1;
}

function queue_worker_mark_failed(PDO $db, array $message, array $workerMeta): void
{
    $messageId = (int) ($message['id'] ?? 0);
    if ($messageId <= 0) {
        return;
    }

    $metadata = queue_worker_metadata($message);
    $metadata['worker'] = $workerMeta + [
        'status' => 'final_failed',
        'delivery_state' => 'final_failed',
        'provider_status_final' => true,
        'processed_at' => app_db_now(),
    ];

    $statement = $db->prepare(
        'UPDATE communication_messages
         SET status = "failed",
             channel = CASE
                WHEN :final_channel_valid = 1 THEN :final_channel
                ELSE channel
             END,
             delivery_state = "final_failed",
             provider_message_id = COALESCE(NULLIF(:provider_message_id, ""), provider_message_id),
             provider_status_code = :provider_status_code,
             provider_status_description = :provider_status_description,
             provider_status_final = 1,
             final_failed_at = COALESCE(final_failed_at, NOW()),
             delivery_updated_at = NOW(),
             metadata_json = :metadata_json
         WHERE id = :id AND status = "queued"'
    );
    $statement->execute([
        'final_channel' => (string) ($workerMeta['final_channel'] ?? ''),
        'final_channel_valid' => in_array((string) ($workerMeta['final_channel'] ?? ''), ['app', 'telegram', 'sms', 'whatsapp'], true) ? 1 : 0,
        'provider_message_id' => (string) ($workerMeta['provider_message_id'] ?? ''),
        'provider_status_code' => isset($workerMeta['provider_status_code']) ? (int) $workerMeta['provider_status_code'] : null,
        'provider_status_description' => (string) ($workerMeta['provider_status_description'] ?? '') ?: null,
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

function queue_worker_success_metadata(array $message, array $provider, array $sendResult, array $extra = []): array
{
    $providerStatus = queue_worker_delivery_state((string) ($sendResult['provider_status'] ?? ''), true);

    return $extra + [
        'status' => $providerStatus,
        'delivery_state' => $providerStatus,
        'provider' => (string) ($provider['delivery'] ?? $message['channel'] ?? ''),
        'provider_driver' => (string) ($provider['driver'] ?? ''),
        'provider_message_id' => $sendResult['provider_message_id'] ?? null,
        'provider_status' => $providerStatus,
        'provider_status_code' => isset($sendResult['provider_status_code']) ? (int) $sendResult['provider_status_code'] : null,
        'provider_status_description' => (string) ($sendResult['provider_status_description'] ?? ''),
        'provider_status_final' => (bool) ($sendResult['provider_status_final'] ?? false),
        'idempotency_key' => app_communication_queue_external_id($message),
        'preferred_channel' => (string) ($provider['preferred_channel'] ?? $message['channel'] ?? ''),
        'final_channel' => (string) ($provider['channel'] ?? $message['channel'] ?? ''),
    ];
}

function queue_worker_failure_metadata(array $message, array $provider, array $sendResult, array $extra = []): array
{
    return $extra + [
        'provider' => (string) ($provider['delivery'] ?? $message['channel'] ?? ''),
        'provider_driver' => (string) ($provider['driver'] ?? ''),
        'provider_message_id' => $sendResult['provider_message_id'] ?? null,
        'provider_status_code' => isset($sendResult['provider_status_code']) ? (int) $sendResult['provider_status_code'] : null,
        'provider_status_description' => (string) ($sendResult['provider_status_description'] ?? ''),
        'reason' => (string) ($sendResult['reason'] ?? '') ?: 'provider_request_failed',
        'preferred_channel' => (string) ($provider['preferred_channel'] ?? $message['channel'] ?? ''),
        'final_channel' => (string) ($provider['channel'] ?? $message['channel'] ?? ''),
    ];
}

function queue_worker_log_attempt(PDO $db, array $message, array $provider, array $sendResult, string $attemptStatus = ''): bool
{
    $messageId = (int) ($message['id'] ?? 0);
    $accountId = (int) ($message['account_id'] ?? 0);
    $ok = !empty($sendResult['ok']);
    $status = $attemptStatus !== '' ? $attemptStatus : ($ok ? 'sent' : 'failed');
    if (!in_array($status, ['sent', 'failed', 'skipped'], true)) {
        $status = $ok ? 'sent' : 'failed';
    }
    $deliveryState = $status === 'skipped'
        ? 'pending'
        : queue_worker_delivery_state((string) ($sendResult['provider_status'] ?? ''), $ok);

    $responseSummary = [
        'ok' => $ok,
        'delivery' => (string) ($sendResult['delivery'] ?? $provider['delivery'] ?? ''),
        'reason' => (string) ($sendResult['reason'] ?? ''),
        'provider_message_id_present' => !empty($sendResult['provider_message_id']),
        'delivery_state' => $deliveryState,
        'provider_status' => $deliveryState,
        'provider_status_code' => isset($sendResult['provider_status_code']) ? (int) $sendResult['provider_status_code'] : null,
        'provider_status_final' => (bool) ($sendResult['provider_status_final'] ?? false),
    ];

    try {
        $statement = $db->prepare(
            'INSERT INTO communication_delivery_attempts (
                message_id, account_id, subscriber_code, channel, delivery, destination_masked,
                status, delivery_state, provider_message_id, provider_status_code,
                provider_status_description, provider_status_final, delivery_updated_at,
                reason, response_summary_json
             ) VALUES (
                :message_id, :account_id, :subscriber_code, :channel, :delivery, :destination_masked,
                :status, :delivery_state, :provider_message_id, :provider_status_code,
                :provider_status_description, :provider_status_final, NOW(),
                :reason, :response_summary_json
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
            'delivery_state' => $deliveryState,
            'provider_message_id' => isset($sendResult['provider_message_id']) ? (string) $sendResult['provider_message_id'] : null,
            'provider_status_code' => isset($sendResult['provider_status_code']) ? (int) $sendResult['provider_status_code'] : null,
            'provider_status_description' => (string) ($sendResult['provider_status_description'] ?? '') ?: null,
            'provider_status_final' => $deliveryState === 'final_failed' || !empty($sendResult['provider_status_final']) ? 1 : 0,
            'reason' => (string) ($sendResult['reason'] ?? ''),
            'response_summary_json' => app_db_json_encode($responseSummary),
        ]);
        return true;
    } catch (Throwable) {
        return false;
    }
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
    return $reason === 'external_delivery_paused'
        || str_starts_with($reason, 'whatsapp_rate_limited')
        || $reason === 'whatsapp_not_ready'
        || $reason === 'whatsapp_bridge_request_failed'
        || $reason === 'sms_provider_request_failed'
        || $reason === 'sms_provider_invalid_response';
}

function queue_worker_is_deferred_result(array $sendResult): bool
{
    return strtolower(trim((string) ($sendResult['provider_status'] ?? ''))) === 'pending'
        || queue_worker_is_deferred_reason((string) ($sendResult['reason'] ?? ''));
}

function queue_worker_should_sms_fallback(string $channel, string $reason): bool
{
    return $channel === 'whatsapp' && str_starts_with($reason, 'whatsapp_rate_limited');
}

if (defined('APP_COMMUNICATION_QUEUE_WORKER_LIBRARY_ONLY') && APP_COMMUNICATION_QUEUE_WORKER_LIBRARY_ONLY === true) {
    return;
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
    'accepted' => 0,
    'submitted' => 0,
    'failed' => 0,
    'left_queued' => 0,
    'unconfigured' => 0,
    'paused' => 0,
    'claim_conflicts' => 0,
    'already_processed' => 0,
    'attempt_log_failures' => 0,
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

    $messageId = (int) ($message['id'] ?? 0);
    $claimed = false;
    if ($apply) {
        $claimed = app_communication_queue_claim($db, $messageId);
        if (!$claimed) {
            $summary['claim_conflicts']++;
            $summary['left_queued']++;
            continue;
        }

        $refreshed = app_communication_queue_refresh_claimed_message($db, $messageId);
        if ($refreshed === null) {
            app_communication_queue_release_claim($db, $messageId);
            $summary['already_processed']++;
            continue;
        }
        $message = queue_worker_prepare_retry($db, $refreshed);
    }

    try {
        $messageChannel = strtolower((string) ($message['channel'] ?? ''));
        if (!isset($summary['by_channel'][$messageChannel])) {
            $messageChannel = 'unsupported';
        }
        $summary['by_channel'][$messageChannel]++;

        $provider = app_communication_queue_resolve_provider_for_message($message);

        if (empty($provider['configured'])) {
            $providerReason = (string) ($provider['reason'] ?? '') ?: 'provider_not_configured';
            if ($providerReason === 'external_delivery_paused') {
                $summary['paused']++;
                $summary['left_queued']++;
                continue;
            }
            $summary['unconfigured']++;
            if ($apply && $failUnconfigured) {
                if (!queue_worker_log_attempt($db, $message, $provider, [
                    'ok' => false,
                    'delivery' => (string) ($provider['delivery'] ?? 'unsupported'),
                    'reason' => $providerReason,
                ], 'failed')) {
                    $summary['attempt_log_failures']++;
                }
                queue_worker_mark_failed($db, $message, [
                    'provider' => (string) ($provider['delivery'] ?? 'unsupported'),
                    'reason' => $providerReason,
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
            if (!queue_worker_mark_sent($db, $message, queue_worker_success_metadata($message, $provider, $sendResult))) {
                throw new RuntimeException('accepted_message_state_transition_failed');
            }
            if (!queue_worker_log_attempt($db, $message, $provider, $sendResult)) {
                $summary['attempt_log_failures']++;
            }
            if (($sendResult['provider_status'] ?? '') === 'submitted') {
                $summary['submitted']++;
            }
            $summary['accepted']++;
            $summary['sent']++;
            continue;
        }

        $reason = (string) ($sendResult['reason'] ?? '');
        $finalProvider = $provider;
        $finalSendResult = $sendResult;
        $finalAttemptLogged = false;
        if (queue_worker_should_sms_fallback((string) ($provider['channel'] ?? $messageChannel), $reason)) {
            if (!queue_worker_log_attempt($db, $message, $provider, $sendResult, 'skipped')) {
                $summary['attempt_log_failures']++;
            }
            $finalAttemptLogged = true;
            $smsProvider = queue_worker_provider_config('sms', trim((string) ($message['destination'] ?? '')));
            $smsProvider['channel'] = 'sms';
            $smsProvider['preferred_channel'] = 'whatsapp';
            if (!empty($smsProvider['configured'])) {
                $smsResult = queue_worker_send_provider($message, $smsProvider);
                if (!empty($smsResult['ok'])) {
                    if (!queue_worker_mark_sent($db, $message, queue_worker_success_metadata(
                        $message,
                        $smsProvider,
                        $smsResult,
                        [
                            'preferred_channel' => 'whatsapp',
                            'final_channel' => 'sms',
                            'fallback_reason' => $reason,
                        ]
                    ))) {
                        throw new RuntimeException('accepted_fallback_message_state_transition_failed');
                    }
                    if (!queue_worker_log_attempt($db, $message, $smsProvider, $smsResult)) {
                        $summary['attempt_log_failures']++;
                    }
                    if (($smsResult['provider_status'] ?? '') === 'submitted') {
                        $summary['submitted']++;
                    }
                    $summary['accepted']++;
                    $summary['sent']++;
                    continue;
                }

                $finalProvider = $smsProvider;
                $finalSendResult = $smsResult;
                $finalAttemptLogged = false;
                $reason = (string) ($smsResult['reason'] ?? 'sms_fallback_failed');
            }
        }

        if (queue_worker_is_deferred_result($finalSendResult)) {
            if (!$finalAttemptLogged && !queue_worker_log_attempt($db, $message, $finalProvider, $finalSendResult, 'skipped')) {
                $summary['attempt_log_failures']++;
            }
            $metadata = queue_worker_metadata($message);
            $metadata['worker'] = [
                'status' => 'deferred',
                'provider' => (string) ($finalProvider['delivery'] ?? $messageChannel),
                'reason' => $reason,
                'preferred_channel' => (string) ($finalProvider['preferred_channel'] ?? $messageChannel),
                'final_channel' => (string) ($finalProvider['channel'] ?? $messageChannel),
                'processed_at' => app_db_now(),
            ];
            queue_worker_update_metadata($db, (int) ($message['id'] ?? 0), $metadata);
            $summary['left_queued']++;
            continue;
        }

        if (!$finalAttemptLogged && !queue_worker_log_attempt($db, $message, $finalProvider, $finalSendResult)) {
            $summary['attempt_log_failures']++;
        }
        queue_worker_mark_failed($db, $message, queue_worker_failure_metadata(
            $message,
            $finalProvider,
            $finalSendResult,
            ['reason' => $reason !== '' ? $reason : 'provider_request_failed']
        ));
        $summary['failed']++;
    } finally {
        if ($claimed) {
            app_communication_queue_release_claim($db, $messageId);
        }
    }
}

if ($apply) {
    $summary['run_logged'] = queue_worker_log_run($db, $summary, $startedAt, $startedFloat, $failUnconfigured, $limit);
} else {
    $summary['run_logged'] = false;
}

queue_worker_json_out($summary);
