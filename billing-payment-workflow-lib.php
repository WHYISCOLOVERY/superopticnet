<?php
declare(strict_types=1);

require_once __DIR__ . '/audit-log-lib.php';

function app_billing_payment_flag(string $key, bool $default): bool
{
    $value = strtolower(trim((string) app_setting($key, $default ? '1' : '0')));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function app_billing_payment_outbox_enabled(): bool
{
    return app_billing_payment_flag('billing_payment_outbox_enabled', true);
}

function app_billing_payment_inline_dispatch_enabled(): bool
{
    return app_billing_payment_flag('billing_payment_inline_dispatch_enabled', true);
}

function app_billing_payment_correlation_id(string $value = ''): string
{
    $value = (string) preg_replace('/[^A-Za-z0-9._:-]+/', '', trim($value));
    if ($value === '' && function_exists('app_request_correlation_id')) {
        $value = app_request_correlation_id();
    }
    if ($value === '') {
        $value = 'pay-' . date('YmdHis') . '-' . bin2hex(random_bytes(8));
    }
    return substr($value, 0, 96);
}

function app_billing_payment_idempotency_key(
    string $subscriberCode,
    string $method,
    string $transactionId,
    string $externalId,
    string $provided = ''
): string {
    $provided = trim($provided);
    if ($provided !== '') {
        return 'explicit:' . hash('sha256', $provided);
    }
    if (trim($externalId) !== '') {
        return 'external:' . hash('sha256', strtolower(trim($externalId)));
    }
    if (trim($transactionId) !== '') {
        return 'transaction:' . hash('sha256', implode('|', [
            strtolower(trim($method)),
            strtolower(trim($subscriberCode)),
            strtolower(trim($transactionId)),
        ]));
    }
    return 'generated:' . bin2hex(random_bytes(24));
}

function app_billing_payment_request_hash(array $request): string
{
    ksort($request);
    $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return hash('sha256', is_string($json) ? $json : serialize($request));
}

function app_billing_payment_operation_by_key(PDO $db, string $idempotencyKey, bool $forUpdate = false): ?array
{
    $statement = $db->prepare(
        'SELECT * FROM billing_payment_operations WHERE idempotency_key = :idempotency_key LIMIT 1'
        . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $statement->execute(['idempotency_key' => $idempotencyKey]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function app_billing_payment_event(
    PDO $db,
    int $operationId,
    ?int $actionId,
    ?string $fromState,
    string $toState,
    string $reasonCode,
    string $correlationId,
    array $metadata = []
): void {
    $statement = $db->prepare(
        'INSERT INTO billing_payment_operation_events
            (operation_id, service_action_id, from_state, to_state, reason_code, correlation_id, metadata_json)
         VALUES
            (:operation_id, :service_action_id, :from_state, :to_state, :reason_code, :correlation_id, :metadata_json)'
    );
    $statement->execute([
        'operation_id' => $operationId,
        'service_action_id' => $actionId,
        'from_state' => $fromState,
        'to_state' => $toState,
        'reason_code' => substr($reasonCode, 0, 96),
        'correlation_id' => app_billing_payment_correlation_id($correlationId),
        'metadata_json' => app_db_json_encode($metadata),
    ]);
}

function app_billing_payment_audit(PDO $db, array $event): void
{
    if (!function_exists('app_audit_record')) {
        return;
    }
    $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
    $correlationId = app_billing_payment_correlation_id((string) ($event['correlation_id'] ?? ''));
    $payload['correlation_id'] = $correlationId;
    try {
        app_audit_record($db, [
            'module_key' => 'billing',
            'operation_class' => (string) ($event['operation_class'] ?? 'write'),
            'action_type' => (string) ($event['action_type'] ?? 'billing_payment_state_changed'),
            'entity_type' => 'billing_payment_operation',
            'entity_id' => (string) ($event['operation_id'] ?? ''),
            'status' => (string) ($event['status'] ?? 'executed'),
            'actor_account_id' => $event['actor_account_id'] ?? null,
            'actor_name' => (string) ($event['actor_name'] ?? 'billing-payment-worker'),
            'summary' => (string) ($event['summary'] ?? 'Billing payment workflow state changed'),
            'reason' => (string) ($event['reason'] ?? ''),
            'payload' => $payload,
            'sensitivity' => 'normal',
            'context' => ['request_id' => $correlationId],
        ]);
    } catch (Throwable $exception) {
        error_log('billing_payment_audit_failed:' . get_class($exception));
    }
}

function app_billing_payment_queue_service_action(
    PDO $db,
    int $operationId,
    string $actionType,
    string $correlationId
): int {
    $actionKey = $actionType . ':' . $operationId;
    $statement = $db->prepare(
        'INSERT INTO billing_payment_service_actions
            (operation_id, action_type, action_key, correlation_id, state, next_attempt_at)
         VALUES
            (:operation_id, :action_type, :action_key, :correlation_id, "service_action_pending", NOW())'
    );
    $statement->execute([
        'operation_id' => $operationId,
        'action_type' => $actionType,
        'action_key' => $actionKey,
        'correlation_id' => $correlationId,
    ]);
    $actionId = (int) $db->lastInsertId();

    $outbox = $db->prepare(
        'INSERT INTO billing_payment_outbox (service_action_id, job_type, status, available_at)
         VALUES (:service_action_id, :job_type, "pending", NOW())'
    );
    $outbox->execute(['service_action_id' => $actionId, 'job_type' => $actionType]);
    return $actionId;
}

function app_billing_payment_backoff_seconds(int $attemptNo): int
{
    return min(3600, 15 * (2 ** max(0, min(8, $attemptNo - 1))));
}

function app_billing_payment_process_action(
    PDO $db,
    int $actionId,
    string $workerId,
    ?callable $executor = null
): array {
    $workerId = substr((string) preg_replace('/[^A-Za-z0-9._:-]+/', '', $workerId), 0, 96);
    if ($actionId <= 0 || $workerId === '') {
        return ['ok' => false, 'reason' => 'invalid_worker_request'];
    }

    $db->beginTransaction();
    try {
        $statement = $db->prepare(
            'SELECT a.*, o.account_id, o.state AS operation_state, b.status AS outbox_status,
                    b.available_at, b.locked_at
               FROM billing_payment_service_actions a
               JOIN billing_payment_operations o ON o.id = a.operation_id
               JOIN billing_payment_outbox b ON b.service_action_id = a.id
              WHERE a.id = :id
              LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['id' => $actionId]);
        $action = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($action)) {
            $db->rollBack();
            return ['ok' => false, 'reason' => 'action_not_found'];
        }
        if ((string) $action['state'] === 'service_action_completed') {
            $db->rollBack();
            return ['ok' => true, 'reason' => 'already_completed', 'action_id' => $actionId];
        }
        if (in_array((string) $action['state'], ['manual_reconciliation_required', 'reversed'], true)) {
            $db->rollBack();
            return ['ok' => false, 'reason' => (string) $action['state'], 'action_id' => $actionId];
        }

        $processing = (string) $action['state'] === 'service_action_processing';
        $lockedAt = strtotime((string) ($action['locked_at'] ?? '')) ?: 0;
        if ($processing && $lockedAt > time() - 300) {
            $db->rollBack();
            return ['ok' => false, 'reason' => 'action_busy', 'action_id' => $actionId];
        }
        $availableAt = strtotime((string) ($action['available_at'] ?? '')) ?: 0;
        if (!$processing && $availableAt > time()) {
            $db->rollBack();
            return ['ok' => false, 'reason' => 'action_not_due', 'action_id' => $actionId];
        }

        if ($processing) {
            $stale = $db->prepare(
                'UPDATE billing_payment_service_attempts
                    SET result = "retryable_failure", error_code = "stale_worker_claim",
                        error_message = "Previous worker claim expired", completed_at = NOW()
                  WHERE service_action_id = :action_id AND result = "processing"'
            );
            $stale->execute(['action_id' => $actionId]);
        }

        $attemptNo = (int) $action['attempt_count'] + 1;
        $fromState = (string) $action['state'];
        $db->prepare(
            'UPDATE billing_payment_service_actions
                SET state = "service_action_processing", attempt_count = :attempt_count,
                    last_error_code = NULL, last_error_message = NULL
              WHERE id = :id'
        )->execute(['attempt_count' => $attemptNo, 'id' => $actionId]);
        $db->prepare(
            'UPDATE billing_payment_operations
                SET state = "service_action_processing"
              WHERE id = :id AND state <> "reversed"'
        )->execute(['id' => (int) $action['operation_id']]);
        $db->prepare(
            'UPDATE billing_payment_outbox
                SET status = "processing", locked_at = NOW(), locked_by = :worker_id,
                    attempt_count = :attempt_count
              WHERE service_action_id = :action_id'
        )->execute(['worker_id' => $workerId, 'attempt_count' => $attemptNo, 'action_id' => $actionId]);
        $db->prepare(
            'INSERT INTO billing_payment_service_attempts
                (service_action_id, attempt_no, correlation_id, result)
             VALUES (:service_action_id, :attempt_no, :correlation_id, "processing")'
        )->execute([
            'service_action_id' => $actionId,
            'attempt_no' => $attemptNo,
            'correlation_id' => (string) $action['correlation_id'],
        ]);
        app_billing_payment_event(
            $db,
            (int) $action['operation_id'],
            $actionId,
            $fromState,
            'service_action_processing',
            $processing ? 'stale_claim_recovered' : 'worker_claimed',
            (string) $action['correlation_id'],
            ['attempt_no' => $attemptNo, 'worker_id' => $workerId]
        );
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'reason' => 'claim_failed', 'error_code' => get_class($exception)];
    }

    $ok = false;
    $errorCode = '';
    $errorMessage = '';
    try {
        $accountStatement = $db->prepare('SELECT * FROM portal_accounts WHERE id = :id LIMIT 1');
        $accountStatement->execute(['id' => (int) $action['account_id']]);
        $account = $accountStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account)) {
            throw new RuntimeException('payment_account_not_found');
        }
        $ok = $executor !== null
            ? (bool) $executor($action, $account)
            : (bool) app_billing_sync_radius_account($account);
        if (!$ok) {
            throw new RuntimeException('radius_sync_returned_false');
        }
        if ($executor === null) {
            app_radius_request_account_disconnect($db, $account, 'Payment activation; force RADIUS re-authentication');
        }
    } catch (Throwable $exception) {
        $errorCode = substr((string) preg_replace('/[^A-Za-z0-9_.:-]+/', '_', get_class($exception)), 0, 96);
        $errorMessage = substr((string) preg_replace('/\s+/', ' ', $exception->getMessage()), 0, 255);
    }

    $db->beginTransaction();
    try {
        $locked = $db->prepare('SELECT * FROM billing_payment_service_actions WHERE id = :id LIMIT 1 FOR UPDATE');
        $locked->execute(['id' => $actionId]);
        $current = $locked->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current) || (int) $current['attempt_count'] !== $attemptNo) {
            throw new RuntimeException('action_attempt_changed');
        }

        if ($ok) {
            $db->prepare(
                'UPDATE billing_payment_service_actions
                    SET state = "service_action_completed", completed_at = NOW(), next_attempt_at = NULL
                  WHERE id = :id'
            )->execute(['id' => $actionId]);
            $db->prepare(
                'UPDATE billing_payment_operations
                    SET state = "service_action_completed", completed_at = NOW(),
                        last_error_code = NULL, last_error_message = NULL
                  WHERE id = :id AND state <> "reversed"'
            )->execute(['id' => (int) $action['operation_id']]);
            $db->prepare(
                'UPDATE billing_payment_outbox
                    SET status = "completed", locked_at = NULL, locked_by = NULL,
                        last_error_code = NULL
                  WHERE service_action_id = :action_id'
            )->execute(['action_id' => $actionId]);
            $db->prepare(
                'UPDATE billing_payment_service_attempts
                    SET result = "completed", completed_at = NOW()
                  WHERE service_action_id = :action_id AND attempt_no = :attempt_no'
            )->execute(['action_id' => $actionId, 'attempt_no' => $attemptNo]);
            app_billing_payment_event(
                $db,
                (int) $action['operation_id'],
                $actionId,
                'service_action_processing',
                'service_action_completed',
                'external_action_completed',
                (string) $action['correlation_id'],
                ['attempt_no' => $attemptNo, 'action_key' => (string) $action['action_key']]
            );
        } else {
            $manual = $attemptNo >= (int) $action['max_attempts'];
            $nextState = $manual ? 'manual_reconciliation_required' : 'service_action_retrying';
            $outboxState = $manual ? 'manual_required' : 'retrying';
            $backoff = app_billing_payment_backoff_seconds($attemptNo);
            $db->prepare(
                'UPDATE billing_payment_service_actions
                    SET state = :state, next_attempt_at = IF(:manual = 1, NULL, DATE_ADD(NOW(), INTERVAL :backoff SECOND)),
                        last_error_code = :error_code, last_error_message = :error_message
                  WHERE id = :id'
            )->execute([
                'state' => $nextState,
                'manual' => $manual ? 1 : 0,
                'backoff' => $backoff,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'id' => $actionId,
            ]);
            $db->prepare(
                'UPDATE billing_payment_operations
                    SET state = :state, last_error_code = :error_code, last_error_message = :error_message
                  WHERE id = :id AND state <> "reversed"'
            )->execute([
                'state' => $nextState,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'id' => (int) $action['operation_id'],
            ]);
            $db->prepare(
                'UPDATE billing_payment_outbox
                    SET status = :status,
                        available_at = IF(:manual = 1, available_at, DATE_ADD(NOW(), INTERVAL :backoff SECOND)),
                        locked_at = NULL, locked_by = NULL, last_error_code = :error_code
                  WHERE service_action_id = :action_id'
            )->execute([
                'status' => $outboxState,
                'manual' => $manual ? 1 : 0,
                'backoff' => $backoff,
                'error_code' => $errorCode,
                'action_id' => $actionId,
            ]);
            $db->prepare(
                'UPDATE billing_payment_service_attempts
                    SET result = :result, error_code = :error_code, error_message = :error_message,
                        completed_at = NOW()
                  WHERE service_action_id = :action_id AND attempt_no = :attempt_no'
            )->execute([
                'result' => $manual ? 'manual_required' : 'retryable_failure',
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'action_id' => $actionId,
                'attempt_no' => $attemptNo,
            ]);
            app_billing_payment_event(
                $db,
                (int) $action['operation_id'],
                $actionId,
                'service_action_processing',
                $nextState,
                $manual ? 'attempts_exhausted' : 'retry_scheduled',
                (string) $action['correlation_id'],
                ['attempt_no' => $attemptNo, 'error_code' => $errorCode, 'backoff_seconds' => $backoff]
            );
        }
        app_billing_payment_audit($db, [
            'action_type' => $ok ? 'billing_service_action_completed' : 'billing_service_action_failed',
            'operation_id' => (int) $action['operation_id'],
            'correlation_id' => (string) $action['correlation_id'],
            'status' => $ok ? 'executed' : 'failed',
            'summary' => $ok ? 'RADIUS payment action completed' : 'RADIUS payment action scheduled for recovery',
            'payload' => ['action_id' => $actionId, 'attempt_no' => $attemptNo, 'error_code' => $errorCode],
        ]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'reason' => 'result_persist_failed', 'error_code' => get_class($exception)];
    }

    return [
        'ok' => $ok,
        'reason' => $ok ? '' : ($attemptNo >= (int) $action['max_attempts'] ? 'manual_reconciliation_required' : 'service_action_retrying'),
        'action_id' => $actionId,
        'operation_id' => (int) $action['operation_id'],
        'attempt_no' => $attemptNo,
        'correlation_id' => (string) $action['correlation_id'],
    ];
}

function app_billing_payment_process_next(PDO $db, string $workerId, ?callable $executor = null): array
{
    $statement = $db->query(
        'SELECT a.id
           FROM billing_payment_service_actions a
           JOIN billing_payment_outbox b ON b.service_action_id = a.id
          WHERE (b.status IN ("pending", "retrying") AND b.available_at <= NOW())
             OR (b.status = "processing" AND b.locked_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
          ORDER BY b.available_at, b.id
          LIMIT 1'
    );
    $actionId = (int) ($statement?->fetchColumn() ?: 0);
    if ($actionId <= 0) {
        return ['ok' => true, 'reason' => 'queue_empty'];
    }
    return app_billing_payment_process_action($db, $actionId, $workerId, $executor);
}

function app_billing_payment_retry_action(PDO $db, int $actionId, array $actor, string $reason): array
{
    if (!app_portal_is_admin($actor) || trim($reason) === '') {
        return ['ok' => false, 'reason' => 'admin_reason_required'];
    }
    $db->beginTransaction();
    try {
        $statement = $db->prepare(
            'SELECT a.*, o.state AS operation_state FROM billing_payment_service_actions a
             JOIN billing_payment_operations o ON o.id = a.operation_id
             WHERE a.id = :id LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['id' => $actionId]);
        $action = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($action) || (string) $action['state'] !== 'manual_reconciliation_required') {
            throw new RuntimeException('action_not_manual_required');
        }
        $db->prepare(
            'UPDATE billing_payment_service_actions
                SET state = "service_action_retrying",
                    max_attempts = GREATEST(max_attempts, attempt_count + 5),
                    next_attempt_at = NOW(),
                    last_error_code = NULL, last_error_message = NULL
              WHERE id = :id'
        )->execute(['id' => $actionId]);
        $db->prepare(
            'UPDATE billing_payment_operations SET state = "service_action_retrying" WHERE id = :id'
        )->execute(['id' => (int) $action['operation_id']]);
        $db->prepare(
            'UPDATE billing_payment_outbox
                SET status = "retrying", available_at = NOW(), locked_at = NULL, locked_by = NULL,
                    last_error_code = NULL
              WHERE service_action_id = :id'
        )->execute(['id' => $actionId]);
        app_billing_payment_event(
            $db,
            (int) $action['operation_id'],
            $actionId,
            'manual_reconciliation_required',
            'service_action_retrying',
            'manual_retry_approved',
            (string) $action['correlation_id'],
            ['reason' => substr(trim($reason), 0, 255), 'actor_account_id' => (int) ($actor['id'] ?? 0)]
        );
        app_billing_payment_audit($db, [
            'action_type' => 'billing_manual_reconciliation_retry',
            'operation_id' => (int) $action['operation_id'],
            'correlation_id' => (string) $action['correlation_id'],
            'actor_account_id' => (int) ($actor['id'] ?? 0),
            'actor_name' => (string) ($actor['full_name'] ?? $actor['login'] ?? 'admin'),
            'summary' => 'Manual reconciliation retry approved',
            'reason' => $reason,
            'payload' => ['action_id' => $actionId],
        ]);
        $db->commit();
        return ['ok' => true, 'action_id' => $actionId];
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'reason' => $exception->getMessage()];
    }
}

function app_billing_payment_reverse_operation(
    PDO $db,
    int $operationId,
    array $actor,
    string $reason,
    string $correlationId = ''
): array {
    if (!app_portal_is_admin($actor) || trim($reason) === '') {
        return ['ok' => false, 'reason' => 'admin_reason_required'];
    }
    $correlationId = app_billing_payment_correlation_id($correlationId);
    $db->beginTransaction();
    try {
        $statement = $db->prepare('SELECT * FROM billing_payment_operations WHERE id = :id LIMIT 1 FOR UPDATE');
        $statement->execute(['id' => $operationId]);
        $operation = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($operation)) {
            throw new RuntimeException('operation_not_found');
        }
        if ((string) $operation['state'] === 'reversed') {
            $db->rollBack();
            return ['ok' => true, 'reason' => 'already_reversed', 'operation_id' => $operationId];
        }
        $snapshot = app_db_json_decode((string) ($operation['before_snapshot_json'] ?? ''), []);
        if (!isset($snapshot['account']) || !is_array($snapshot['account'])) {
            throw new RuntimeException('reversal_snapshot_missing');
        }

        $accountBefore = $snapshot['account'];
        $db->prepare(
            'UPDATE portal_accounts
                SET payload_json = :payload_json, status = :status, is_active = :is_active, updated_at = NOW()
              WHERE id = :id'
        )->execute([
            'payload_json' => (string) ($accountBefore['payload_json'] ?? '{}'),
            'status' => (string) ($accountBefore['status'] ?? ''),
            'is_active' => (int) ($accountBefore['is_active'] ?? 0),
            'id' => (int) $operation['account_id'],
        ]);
        foreach ((array) ($snapshot['radius_services'] ?? []) as $service) {
            if (!is_array($service) || (int) ($service['id'] ?? 0) <= 0) {
                continue;
            }
            $db->prepare(
                'UPDATE billing_radius_services
                    SET status = :status, expires_at = :expires_at, updated_at = NOW()
                  WHERE id = :id AND account_id = :account_id'
            )->execute([
                'status' => (string) ($service['status'] ?? 'inactive'),
                'expires_at' => $service['expires_at'] ?? null,
                'id' => (int) $service['id'],
                'account_id' => (int) $operation['account_id'],
            ]);
        }

        $db->prepare(
            'UPDATE billing_payment_outbox b
             JOIN billing_payment_service_actions a ON a.id = b.service_action_id
                SET b.status = "cancelled", b.locked_at = NULL, b.locked_by = NULL,
                    a.state = "reversed"
              WHERE a.operation_id = :operation_id AND a.action_type = "radius_sync"
                AND a.state <> "service_action_completed"'
        )->execute(['operation_id' => $operationId]);
        $db->prepare(
            'INSERT INTO billing_payment_reversals
                (operation_id, payment_id, amount, reason, actor_account_id, actor_name, correlation_id)
             VALUES
                (:operation_id, :payment_id, :amount, :reason, :actor_account_id, :actor_name, :correlation_id)'
        )->execute([
            'operation_id' => $operationId,
            'payment_id' => (int) $operation['payment_id'],
            'amount' => (float) $operation['amount'],
            'reason' => substr(trim($reason), 0, 255),
            'actor_account_id' => (int) ($actor['id'] ?? 0) ?: null,
            'actor_name' => substr((string) ($actor['full_name'] ?? $actor['login'] ?? 'admin'), 0, 191),
            'correlation_id' => $correlationId,
        ]);
        $db->prepare(
            'UPDATE billing_payment_operations SET state = "reversed", reversed_at = NOW() WHERE id = :id'
        )->execute(['id' => $operationId]);
        $actionId = app_billing_payment_queue_service_action($db, $operationId, 'radius_compensation', $correlationId);
        app_billing_payment_event(
            $db,
            $operationId,
            $actionId,
            (string) $operation['state'],
            'reversed',
            'payment_reversed',
            $correlationId,
            ['reason' => substr(trim($reason), 0, 255), 'payment_id' => (int) $operation['payment_id']]
        );
        app_billing_payment_audit($db, [
            'action_type' => 'billing_payment_reversed',
            'operation_id' => $operationId,
            'correlation_id' => $correlationId,
            'actor_account_id' => (int) ($actor['id'] ?? 0),
            'actor_name' => (string) ($actor['full_name'] ?? $actor['login'] ?? 'admin'),
            'summary' => 'Billing payment reversed and compensation queued',
            'reason' => $reason,
            'payload' => ['payment_id' => (int) $operation['payment_id'], 'compensation_action_id' => $actionId],
        ]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'reason' => $exception->getMessage()];
    }

    if (app_billing_payment_inline_dispatch_enabled()) {
        app_billing_payment_process_action($db, $actionId, 'inline-compensation-' . getmypid());
    }
    return ['ok' => true, 'operation_id' => $operationId, 'compensation_action_id' => $actionId, 'correlation_id' => $correlationId];
}
