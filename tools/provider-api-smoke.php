<?php
declare(strict_types=1);

require dirname(__DIR__) . '/provider-api-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function provider_smoke_finish(array $payload, int $code = 0): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

$db = app_db();
if (!$db instanceof PDO) {
    provider_smoke_finish([
        'status' => 'skipped',
        'reason' => 'database_unavailable',
    ]);
    exit(0);
}

$subscriberCode = 'SMOKE-PROVIDER-' . date('His') . random_int(100, 999);
$transactionId = 'SMOKE-TX-' . date('YmdHis') . random_int(100, 999);
$externalId = app_provider_external_id('emanat', $transactionId);
$accountId = 0;
$exitCode = 1;

try {
    $payload = [
        'profile' => [
            'full_name' => 'Provider API Smoke',
            'subscriber_code' => $subscriberCode,
            'phone' => '0500000000',
        ],
        'billing' => [
            'status' => 'Deaktiv',
            'balance' => '25.00 AZN',
            'tariff' => 'Smoke 100',
            'expires_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ],
        'payments' => [],
        'statements' => [[
            'period' => date('Y-m'),
            'balance' => '25.00 AZN',
        ]],
    ];
    $insert = $db->prepare(
        'INSERT INTO portal_accounts (
            login, password_hash, role, account_type, full_name, subscriber_code, phone, plan, status, is_active, payload_json
         ) VALUES (
            :login, :password_hash, "customer", "customer", :full_name, :subscriber_code, :phone, :plan, "Deaktiv", 1, :payload_json
         )'
    );
    $insert->execute([
        'login' => $subscriberCode,
        'password_hash' => password_hash('smoke-pass', PASSWORD_DEFAULT),
        'full_name' => 'Provider API Smoke',
        'subscriber_code' => $subscriberCode,
        'phone' => '0500000000',
        'plan' => 'Smoke 100',
        'payload_json' => app_db_json_encode($payload),
    ]);
    $accountId = (int) $db->lastInsertId();

    $account = app_provider_customer_by_code($subscriberCode);
    if ($account === null) {
        throw new RuntimeException('customer_lookup_failed');
    }
    $summaryBefore = app_provider_customer_summary($account);
    $appliedFirst = app_billing_apply_customer_payment($subscriberCode, 12.50, 'emanat:test-terminal', 30, $transactionId, $externalId, date('Y-m-d H:i:s'));
    $appliedSecond = app_billing_apply_customer_payment($subscriberCode, 12.50, 'emanat:test-terminal', 30, $transactionId, $externalId, date('Y-m-d H:i:s'));
    $paymentCountStatement = $db->prepare('SELECT COUNT(*) FROM billing_payments WHERE external_id = :external_id');
    $paymentCountStatement->execute(['external_id' => $externalId]);
    $paymentCount = (int) $paymentCountStatement->fetchColumn();
    $updated = app_provider_customer_by_code($subscriberCode);
    $summaryAfter = is_array($updated) ? app_provider_customer_summary($updated) : [];

    provider_smoke_finish([
        'status' => ($appliedFirst && $appliedSecond && $paymentCount === 1) ? 'ok' : 'failed',
        'subscriber_code' => $subscriberCode,
        'lookup_before' => [
            'full_name' => $summaryBefore['full_name'] ?? '',
            'balance' => $summaryBefore['balance'] ?? '',
        ],
        'payment' => [
            'applied_first' => $appliedFirst,
            'applied_second_duplicate' => $appliedSecond,
            'duplicate_payment_rows' => $paymentCount,
            'external_id' => $externalId,
        ],
        'lookup_after' => [
            'status' => $summaryAfter['status'] ?? '',
            'balance' => $summaryAfter['balance'] ?? '',
            'remaining_days' => $summaryAfter['remaining_days'] ?? null,
        ],
    ], ($appliedFirst && $appliedSecond && $paymentCount === 1) ? 0 : 3);
    $exitCode = ($appliedFirst && $appliedSecond && $paymentCount === 1) ? 0 : 3;
} catch (Throwable $exception) {
    provider_smoke_finish([
        'status' => 'failed',
        'reason' => $exception->getMessage(),
    ], 2);
    $exitCode = 2;
} finally {
    if ($accountId > 0) {
        $db->prepare('DELETE r FROM billing_payment_reversals r JOIN billing_payment_operations o ON o.id = r.operation_id WHERE o.account_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE e FROM billing_payment_operation_events e JOIN billing_payment_operations o ON o.id = e.operation_id WHERE o.account_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE a FROM billing_payment_service_attempts a JOIN billing_payment_service_actions s ON s.id = a.service_action_id JOIN billing_payment_operations o ON o.id = s.operation_id WHERE o.account_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE b FROM billing_payment_outbox b JOIN billing_payment_service_actions s ON s.id = b.service_action_id JOIN billing_payment_operations o ON o.id = s.operation_id WHERE o.account_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE s FROM billing_payment_service_actions s JOIN billing_payment_operations o ON o.id = s.operation_id WHERE o.account_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE FROM billing_payment_operations WHERE account_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE FROM billing_payments WHERE subscriber_code = :code OR external_id = :external_id')->execute([
            'code' => $subscriberCode,
            'external_id' => $externalId,
        ]);
        $db->prepare('DELETE FROM billing_radius_services WHERE account_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE FROM radcheck WHERE username = :username')->execute(['username' => $subscriberCode]);
        $db->prepare('DELETE FROM radreply WHERE username = :username')->execute(['username' => $subscriberCode]);
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $accountId]);
    }
}

exit($exitCode);
