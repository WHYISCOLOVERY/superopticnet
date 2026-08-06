<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

define('APP_PG365_STATUS_WORKER_LIBRARY_ONLY', true);
define('APP_COMMUNICATION_QUEUE_WORKER_LIBRARY_ONLY', true);
require __DIR__ . '/pg365-status-worker.php';
require __DIR__ . '/process-communication-queue.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$errors = [];
$expect = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$db = app_db();
if (!$db instanceof PDO) {
    echo json_encode(['ok' => false, 'errors' => ['database_unavailable']], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

app_db_migrate_communication_delivery_state($db);

$messageColumns = ['delivery_state', 'provider_message_id', 'provider_status_code', 'provider_status_description',
    'provider_status_final', 'accepted_at', 'submitted_at', 'delivered_at', 'final_failed_at', 'delivery_updated_at'];
foreach ($messageColumns as $column) {
    $expect(app_db_column_exists($db, 'communication_messages', $column), 'message_column_missing:' . $column);
}
foreach (['delivery_state', 'provider_status_code', 'provider_status_description', 'provider_status_final', 'delivery_updated_at'] as $column) {
    $expect(app_db_column_exists($db, 'communication_delivery_attempts', $column), 'attempt_column_missing:' . $column);
}
$expect(
    app_db_index_exists($db, 'communication_messages', 'idx_communication_messages_delivery_state'),
    'message_delivery_state_index_missing'
);
$expect(
    app_db_index_exists($db, 'communication_delivery_attempts', 'idx_communication_attempts_delivery_state'),
    'attempt_delivery_state_index_missing'
);

$db->beginTransaction();
try {
    $providerMessageId = 'delivery-state-smoke-' . bin2hex(random_bytes(6));
    $insertMessage = $db->prepare(
        'INSERT INTO communication_messages (
            channel, destination, message_body, status, delivery_state, provider_message_id,
            provider_status_final, sent_at, accepted_at, delivery_updated_at, metadata_json
         ) VALUES (
            "sms", "994500000000", "delivery state smoke", "sent", "accepted", :provider_message_id,
            0, NOW(), NOW(), NOW(), :metadata_json
         )'
    );
    $insertMessage->execute([
        'provider_message_id' => $providerMessageId,
        'metadata_json' => app_db_json_encode([
            'worker' => [
                'provider_driver' => 'pg365_json',
                'provider_message_id' => $providerMessageId,
                'provider_status' => 'accepted',
                'provider_status_final' => false,
                'final_channel' => 'sms',
            ],
        ]),
    ]);
    $messageId = (int) $db->lastInsertId();
    $insertAttempt = $db->prepare(
        'INSERT INTO communication_delivery_attempts (
            message_id, channel, delivery, destination_masked, status, delivery_state,
            provider_message_id, provider_status_final, delivery_updated_at
         ) VALUES (
            :message_id, "sms", "sms_provider", "994******000", "sent", "accepted",
            :provider_message_id, 0, NOW()
         )'
    );
    $insertAttempt->execute([
        'message_id' => $messageId,
        'provider_message_id' => $providerMessageId,
    ]);

    $updated = pg365_status_worker_update($db, $messageId, [
        'id' => $providerMessageId,
        'status_code' => 402,
        'status_description' => 'Not delivered',
        'is_final' => true,
        'delivery_state' => 'final_failed',
    ]);
    $expect($updated, 'status_worker_final_failed_update_failed');
    $message = $db->query('SELECT * FROM communication_messages WHERE id = ' . $messageId)->fetch(PDO::FETCH_ASSOC) ?: [];
    $attempt = $db->query('SELECT * FROM communication_delivery_attempts WHERE message_id = ' . $messageId)->fetch(PDO::FETCH_ASSOC) ?: [];
    $expect(($message['status'] ?? '') === 'failed', 'final_failed_message_not_retryable');
    $expect(($message['delivery_state'] ?? '') === 'final_failed', 'final_failed_message_state_wrong');
    $expect((int) ($message['provider_status_final'] ?? 0) === 1, 'final_failed_message_finality_missing');
    $expect(($attempt['status'] ?? '') === 'failed', 'final_failed_attempt_status_wrong');
    $expect(($attempt['delivery_state'] ?? '') === 'final_failed', 'final_failed_attempt_state_wrong');

    $db->exec('UPDATE communication_messages SET status = "queued", sent_at = NULL WHERE id = ' . $messageId);
    $message['status'] = 'queued';
    $message = queue_worker_prepare_retry($db, $message);
    $retryRow = $db->query('SELECT * FROM communication_messages WHERE id = ' . $messageId)->fetch(PDO::FETCH_ASSOC) ?: [];
    $expect(($message['delivery_state'] ?? '') === 'pending', 'retry_return_state_not_pending');
    $expect(($retryRow['status'] ?? '') === 'queued', 'retry_operational_status_changed');
    $expect(($retryRow['delivery_state'] ?? '') === 'pending', 'retry_delivery_state_not_reset');
    $expect((string) ($retryRow['provider_message_id'] ?? '') === '', 'retry_provider_message_id_not_reset');
    $expect((int) ($retryRow['provider_status_final'] ?? 1) === 0, 'retry_provider_finality_not_reset');

    $fallbackMessage = $db->prepare(
        'INSERT INTO communication_messages (
            channel, destination, message_body, status, delivery_state, metadata_json
         ) VALUES (
            "whatsapp", "994500000000", "fallback channel smoke", "queued", "pending", :metadata_json
         )'
    );
    $fallbackMessage->execute([
        'metadata_json' => app_db_json_encode(['preferred_channel' => 'whatsapp']),
    ]);
    $fallbackMessageId = (int) $db->lastInsertId();
    $fallbackRow = $db->query('SELECT * FROM communication_messages WHERE id = ' . $fallbackMessageId)->fetch(PDO::FETCH_ASSOC) ?: [];
    $fallbackProviderId = 'fallback-sms-' . bin2hex(random_bytes(4));
    $fallbackMarked = queue_worker_mark_sent($db, $fallbackRow, [
        'final_channel' => 'sms',
        'delivery_state' => 'accepted',
        'provider_message_id' => $fallbackProviderId,
        'provider_status_final' => false,
    ]);
    $fallbackStored = $db->query('SELECT * FROM communication_messages WHERE id = ' . $fallbackMessageId)->fetch(PDO::FETCH_ASSOC) ?: [];
    $expect($fallbackMarked, 'fallback_message_not_marked_sent');
    $expect(($fallbackStored['channel'] ?? '') === 'sms', 'fallback_final_channel_not_persisted');
    $expect(($fallbackStored['delivery_state'] ?? '') === 'accepted', 'fallback_delivery_state_not_persisted');
    $expect(($fallbackStored['provider_message_id'] ?? '') === $fallbackProviderId, 'fallback_provider_message_id_not_persisted');
    $fallbackMetadata = app_db_json_decode((string) ($fallbackStored['metadata_json'] ?? ''));
    $expect(($fallbackMetadata['preferred_channel'] ?? '') === 'whatsapp', 'fallback_preferred_channel_audit_lost');
    $expect(($fallbackMetadata['worker']['final_channel'] ?? '') === 'sms', 'fallback_worker_final_channel_audit_missing');

    $failedFallback = $db->prepare(
        'INSERT INTO communication_messages (
            channel, destination, message_body, status, delivery_state, metadata_json
         ) VALUES (
            "whatsapp", "994500000000", "failed fallback smoke", "queued", "pending", :metadata_json
         )'
    );
    $failedFallback->execute(['metadata_json' => app_db_json_encode(['preferred_channel' => 'whatsapp'])]);
    $failedFallbackId = (int) $db->lastInsertId();
    $failedFallbackRow = $db->query('SELECT * FROM communication_messages WHERE id = ' . $failedFallbackId)->fetch(PDO::FETCH_ASSOC) ?: [];
    $failedSmsProvider = [
        'channel' => 'sms',
        'preferred_channel' => 'whatsapp',
        'delivery' => 'sms_provider',
        'driver' => 'pg365_json',
    ];
    $failedSmsResult = [
        'ok' => false,
        'provider_status' => 'final_failed',
        'provider_status_code' => 422,
        'provider_status_description' => 'Rejected',
        'provider_status_final' => true,
        'reason' => 'receiver_rejected',
        'delivery' => 'sms_provider',
    ];
    $expect(queue_worker_log_attempt($db, $failedFallbackRow, $failedSmsProvider, $failedSmsResult), 'failed_fallback_attempt_not_logged');
    queue_worker_mark_failed(
        $db,
        $failedFallbackRow,
        queue_worker_failure_metadata($failedFallbackRow, $failedSmsProvider, $failedSmsResult)
    );
    $failedFallbackStored = $db->query('SELECT * FROM communication_messages WHERE id = ' . $failedFallbackId)->fetch(PDO::FETCH_ASSOC) ?: [];
    $failedFallbackAttempts = $db->query('SELECT * FROM communication_delivery_attempts WHERE message_id = ' . $failedFallbackId)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $expect(($failedFallbackStored['status'] ?? '') === 'failed', 'failed_fallback_operational_status_wrong');
    $expect(($failedFallbackStored['channel'] ?? '') === 'sms', 'failed_fallback_final_channel_wrong');
    $expect(($failedFallbackStored['provider_status_code'] ?? null) == 422, 'failed_fallback_provider_status_missing');
    $expect(count($failedFallbackAttempts) === 1, 'failed_fallback_attempt_duplicated');
    $expect(($failedFallbackAttempts[0]['channel'] ?? '') === 'sms', 'failed_fallback_attempt_channel_wrong');
    $expect(queue_worker_is_deferred_result(['provider_status' => 'pending', 'reason' => 'temporary_provider_failure']), 'pending_provider_result_not_deferred');
} catch (Throwable $exception) {
    $errors[] = 'delivery_transition_exception:' . $exception->getMessage();
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$providerSource = (string) file_get_contents(dirname(__DIR__) . '/communication-provider.php');
$queueSource = (string) file_get_contents(__DIR__ . '/process-communication-queue.php');
$statusSource = (string) file_get_contents(__DIR__ . '/pg365-status-worker.php');
$expect(str_contains($providerSource, "'accepted'"), 'provider_acceptance_state_missing');
$expect(str_contains($providerSource, "'submitted'"), 'provider_submitted_state_missing');
$expect(str_contains($providerSource, "'delivered'"), 'provider_delivered_state_missing');
$expect(str_contains($providerSource, "'final_failed'"), 'provider_final_failed_state_missing');
$expect(str_contains($queueSource, 'delivery_state = :delivery_state'), 'queue_first_class_delivery_write_missing');
$expect(str_contains($queueSource, 'queue_worker_prepare_retry'), 'final_failed_retry_reset_missing');
$expect(str_contains($queueSource, 'status = "queued" AND delivery_state = "final_failed"'), 'retry_reset_state_guard_missing');
$expect(str_contains($queueSource, '$finalProvider = $smsProvider;'), 'fallback_final_provider_switch_missing');
$expect(str_contains($queueSource, 'queue_worker_is_deferred_result($finalSendResult)'), 'fallback_deferred_result_guard_missing');
$expect(str_contains($statusSource, "empty(\$provider['status_configured'])"), 'status_worker_poll_configuration_gate_missing');
$expect(!str_contains($statusSource, "app_communication_channel_enabled('sms')"), 'status_worker_must_not_stop_when_sms_send_disabled');
$expect(str_contains($statusSource, 'SET status = CASE WHEN :set_failed_status = 1 THEN "failed" ELSE status END'), 'final_failed_message_status_transition_missing');
$expect(str_contains($statusSource, 'UPDATE communication_delivery_attempts'), 'final_failed_attempt_transition_missing');

echo json_encode([
    'ok' => $errors === [],
    'errors' => $errors,
    'real_provider_requests' => 0,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
