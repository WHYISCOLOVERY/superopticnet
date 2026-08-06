<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/config.php';

putenv('BILLING_PAYMENT_OUTBOX_ENABLED=1');
putenv('BILLING_PAYMENT_INLINE_DISPATCH_ENABLED=0');

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database is unavailable.\n");
    exit(2);
}

$checks = [];
$expect = static function (bool $ok, string $name) use (&$checks): void {
    $checks[$name] = $ok;
};
$subscriberCode = 'STAGE0-PAY-' . date('YmdHis') . '-' . random_int(100, 999);
$accountId = 0;
$operationId = 0;
$paymentId = 0;
$correlationId = 'stage0-smoke-' . bin2hex(random_bytes(8));

try {
    $tariff = $db->query(
        'SELECT * FROM billing_tariffs WHERE status = "active" ORDER BY monthly_fee, id LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    if (!is_array($tariff)) {
        throw new RuntimeException('active_tariff_missing');
    }
    $amount = max(0.01, (float) ($tariff['monthly_fee'] ?? 0));
    $oldExpiresAt = date('Y-m-d H:i:s', strtotime('-2 days'));
    $payload = [
        'profile' => ['subscriber_code' => $subscriberCode, 'full_name' => 'Stage0 Payment Smoke'],
        'billing' => [
            'service_type' => 'pppoe',
            'tariff' => (string) ($tariff['name'] ?? ''),
            'pppoe_username' => $subscriberCode,
            'pppoe_password' => 'Stage0Smoke123',
            'status' => 'Deaktiv',
            'radius_status' => 'Vaxt bitib',
            'expires_at' => $oldExpiresAt,
            'monthly_total' => number_format($amount, 2, '.', '') . ' AZN',
            'balance' => number_format($amount, 2, '.', '') . ' AZN',
            'default_days' => 30,
        ],
        'internet_services' => [[
            'service_type' => 'pppoe',
            'username' => $subscriberCode,
            'password' => 'Stage0Smoke123',
            'tariff' => (string) ($tariff['name'] ?? ''),
            'status' => 'expired',
            'expires_at' => $oldExpiresAt,
        ]],
    ];
    $insert = $db->prepare(
        'INSERT INTO portal_accounts
            (login, password_hash, role, account_type, full_name, subscriber_code, plan, status, is_active, payload_json)
         VALUES
            (:login, :password_hash, "customer", "customer", :full_name, :subscriber_code,
             :plan, "Deaktiv", 0, :payload_json)'
    );
    $insert->execute([
        'login' => strtolower($subscriberCode),
        'password_hash' => password_hash('Stage0Smoke123', PASSWORD_DEFAULT),
        'full_name' => 'Stage0 Payment Smoke',
        'subscriber_code' => $subscriberCode,
        'plan' => (string) ($tariff['name'] ?? ''),
        'payload_json' => app_db_json_encode($payload),
    ]);
    $accountId = (int) $db->lastInsertId();
    $account = app_db_account_by_id($accountId);
    if (!is_array($account) || !app_billing_sync_radius_account($account)) {
        throw new RuntimeException('initial_radius_sync_failed');
    }

    $idempotency = 'stage0-payment-' . bin2hex(random_bytes(12));
    $transactionId = 'STAGE0-TX-' . bin2hex(random_bytes(8));
    $first = app_billing_apply_customer_payment(
        $subscriberCode,
        $amount,
        'stage0-smoke',
        30,
        $transactionId,
        '',
        null,
        $idempotency,
        $correlationId
    );
    $second = app_billing_apply_customer_payment(
        $subscriberCode,
        $amount,
        'stage0-smoke',
        30,
        $transactionId,
        '',
        null,
        $idempotency,
        $correlationId
    );
    $mismatch = app_billing_apply_customer_payment(
        $subscriberCode,
        $amount + 1,
        'stage0-smoke',
        30,
        $transactionId,
        '',
        null,
        $idempotency,
        $correlationId
    );

    $operationStatement = $db->prepare(
        'SELECT * FROM billing_payment_operations WHERE subscriber_code = :subscriber_code ORDER BY id DESC LIMIT 1'
    );
    $operationStatement->execute(['subscriber_code' => $subscriberCode]);
    $operation = $operationStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($operation)) {
        throw new RuntimeException('operation_missing');
    }
    $operationId = (int) $operation['id'];
    $paymentId = (int) $operation['payment_id'];
    $actionStatement = $db->prepare(
        'SELECT * FROM billing_payment_service_actions WHERE operation_id = :operation_id AND action_type = "radius_sync"'
    );
    $actionStatement->execute(['operation_id' => $operationId]);
    $action = $actionStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($action)) {
        throw new RuntimeException('radius_action_missing');
    }
    $actionId = (int) $action['id'];

    $expect($first, 'first_payment_accepted');
    $expect($second, 'same_request_idempotent_success');
    $expect(!$mismatch, 'same_key_different_request_rejected');
    $expect((int) $db->query('SELECT COUNT(*) FROM billing_payment_operations WHERE account_id = ' . $accountId)->fetchColumn() === 1, 'single_operation');
    $expect((int) $db->query('SELECT COUNT(*) FROM billing_payments WHERE account_id = ' . $accountId)->fetchColumn() === 1, 'single_payment');
    $expect((int) $db->query('SELECT COUNT(*) FROM billing_payment_service_actions WHERE operation_id = ' . $operationId)->fetchColumn() === 1, 'single_service_action');
    $expect((int) $db->query('SELECT COUNT(*) FROM billing_payment_outbox WHERE service_action_id = ' . $actionId)->fetchColumn() === 1, 'single_outbox_job');
    $expect((string) $operation['state'] === 'service_action_pending', 'initial_state_pending');

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $db->prepare('UPDATE billing_payment_outbox SET available_at = NOW() WHERE service_action_id = :id')
            ->execute(['id' => $actionId]);
        app_billing_payment_process_action(
            $db,
            $actionId,
            'stage0-failing-worker',
            static fn (): bool => false
        );
    }
    $failedAction = $db->query('SELECT * FROM billing_payment_service_actions WHERE id = ' . $actionId)->fetch(PDO::FETCH_ASSOC);
    $failedOperation = $db->query('SELECT * FROM billing_payment_operations WHERE id = ' . $operationId)->fetch(PDO::FETCH_ASSOC);
    $expect((string) ($failedAction['state'] ?? '') === 'manual_reconciliation_required', 'attempts_end_in_manual_reconciliation');
    $expect((string) ($failedOperation['state'] ?? '') === 'manual_reconciliation_required', 'operation_manual_reconciliation_state');
    $expect((int) $db->query('SELECT COUNT(*) FROM billing_payment_service_attempts WHERE service_action_id = ' . $actionId)->fetchColumn() === 5, 'five_immutable_attempts');

    $admin = [
        'id' => null,
        'role' => 'employee',
        'account_type' => 'admin',
        'employee_role' => 'admin',
        'full_name' => 'Stage0 Smoke Admin',
        'is_active' => 1,
    ];
    $retry = app_billing_payment_retry_action($db, $actionId, $admin, 'Stage0 retry verification');
    $db->prepare('UPDATE billing_payment_outbox SET available_at = NOW() WHERE service_action_id = :id')
        ->execute(['id' => $actionId]);
    $recovered = app_billing_payment_process_action(
        $db,
        $actionId,
        'stage0-recovery-worker',
        static fn (): bool => true
    );
    $expect(!empty($retry['ok']), 'manual_retry_approved');
    $expect(!empty($recovered['ok']), 'recovery_completed');
    $expect((string) $db->query('SELECT state FROM billing_payment_operations WHERE id = ' . $operationId)->fetchColumn() === 'service_action_completed', 'operation_completed_after_recovery');

    $reversal = app_billing_payment_reverse_operation(
        $db,
        $operationId,
        $admin,
        'Stage0 compensation verification',
        $correlationId . '-reverse'
    );
    $compensationId = (int) ($reversal['compensation_action_id'] ?? 0);
    $compensation = app_billing_payment_process_action(
        $db,
        $compensationId,
        'stage0-compensation-worker',
        static fn (): bool => true
    );
    $expect(!empty($reversal['ok']), 'reversal_created');
    $expect(!empty($compensation['ok']), 'compensation_completed');
    $expect((string) $db->query('SELECT state FROM billing_payment_operations WHERE id = ' . $operationId)->fetchColumn() === 'reversed', 'operation_remains_reversed');
    $expect((int) $db->query('SELECT COUNT(*) FROM billing_payment_reversals WHERE operation_id = ' . $operationId)->fetchColumn() === 1, 'single_reversal');
    $expect((int) $db->query('SELECT COUNT(*) FROM billing_payment_service_actions WHERE operation_id = ' . $operationId . ' AND action_type = "radius_compensation"')->fetchColumn() === 1, 'single_compensation_action');
    $expect((int) $db->query('SELECT COUNT(*) FROM billing_payment_operation_events WHERE operation_id = ' . $operationId . ' AND correlation_id = ' . $db->quote($correlationId))->fetchColumn() > 0, 'correlation_id_recorded');
} catch (Throwable $exception) {
    $checks['uncaught_exception:' . get_class($exception) . ':' . $exception->getMessage()] = false;
} finally {
    if ($operationId > 0) {
        $db->prepare('DELETE FROM billing_payment_reversals WHERE operation_id = :id')->execute(['id' => $operationId]);
        $db->prepare('DELETE FROM billing_payment_operation_events WHERE operation_id = :id')->execute(['id' => $operationId]);
        $db->prepare('DELETE a FROM billing_payment_service_attempts a JOIN billing_payment_service_actions s ON s.id = a.service_action_id WHERE s.operation_id = :id')->execute(['id' => $operationId]);
        $db->prepare('DELETE b FROM billing_payment_outbox b JOIN billing_payment_service_actions s ON s.id = b.service_action_id WHERE s.operation_id = :id')->execute(['id' => $operationId]);
        $db->prepare('DELETE FROM billing_payment_service_actions WHERE operation_id = :id')->execute(['id' => $operationId]);
        $db->prepare('DELETE FROM billing_payment_operations WHERE id = :id')->execute(['id' => $operationId]);
    }
    if ($paymentId > 0) {
        $db->prepare('DELETE FROM billing_payments WHERE id = :id')->execute(['id' => $paymentId]);
    }
    if ($accountId > 0) {
        foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
            $db->prepare('DELETE FROM ' . $table . ' WHERE username = :username')->execute(['username' => $subscriberCode]);
        }
        $db->prepare('DELETE FROM billing_radius_services WHERE account_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $accountId]);
    }
}

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
echo json_encode(['ok' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($failed === [] ? 0 : 1);
