<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function partial_payment_smoke_out(array $payload, int $code = 0): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

$db = app_db();
if (!$db instanceof PDO) {
    partial_payment_smoke_out(['status' => 'skipped', 'reason' => 'db_unavailable']);
    exit(0);
}

$stamp = date('His') . random_int(100, 999);
$subscriberCode = 'SMOKE-PARTIAL-' . $stamp;
$transactionId = 'SMOKE-PARTIAL-TX-' . $stamp;
$externalId = 'smoke-partial-' . $stamp;
$accountId = 0;
$exitCode = 1;

try {
    $oldExpiresAt = date('Y-m-d H:i:s', strtotime('-2 days'));
    $payload = [
        'profile' => [
            'full_name' => 'Partial Payment Smoke',
            'subscriber_code' => $subscriberCode,
            'phone' => '0500000000',
        ],
        'billing' => [
            'status' => 'Deaktiv',
            'radius_status' => 'Vaxt bitib',
            'balance' => '25.00 AZN',
            'monthly_total' => '25.00 AZN',
            'tariff' => 'Smoke 100',
            'expires_at' => $oldExpiresAt,
        ],
        'payments' => [],
    ];

    $insert = $db->prepare(
        'INSERT INTO portal_accounts (
            login, password_hash, role, account_type, full_name, subscriber_code, phone, plan, status, is_active, payload_json
         ) VALUES (
            :login, :password_hash, "customer", "customer", :full_name, :subscriber_code, :phone, :plan, "Deaktiv", 0, :payload_json
         )'
    );
    $insert->execute([
        'login' => $subscriberCode,
        'password_hash' => password_hash('smoke-pass', PASSWORD_DEFAULT),
        'full_name' => 'Partial Payment Smoke',
        'subscriber_code' => $subscriberCode,
        'phone' => '0500000000',
        'plan' => 'Smoke 100',
        'payload_json' => app_db_json_encode($payload),
    ]);
    $accountId = (int) $db->lastInsertId();

    $applied = app_billing_apply_customer_payment(
        $subscriberCode,
        12.50,
        'partial-smoke',
        30,
        $transactionId,
        $externalId,
        date('Y-m-d H:i:s')
    );
    $updated = app_db_account_by_id($accountId);
    $updatedPayload = is_array($updated) ? app_db_account_payload($updated) : [];
    $billing = is_array($updatedPayload['billing'] ?? null) ? $updatedPayload['billing'] : [];

    $paymentStatement = $db->prepare('SELECT metadata_json FROM billing_payments WHERE external_id = :external_id LIMIT 1');
    $paymentStatement->execute(['external_id' => $externalId]);
    $metadata = app_db_json_decode((string) ($paymentStatement->fetchColumn() ?: ''), []);

    $ok = $applied
        && is_array($updated)
        && (int) ($updated['is_active'] ?? 1) === 0
        && (string) ($updated['status'] ?? '') === 'Deaktiv'
        && app_billing_parse_money_amount($billing['balance'] ?? 0) === 12.50
        && (string) ($billing['expires_at'] ?? '') === $oldExpiresAt
        && (string) ($metadata['payment_effect'] ?? '') === 'partial_balance_reduction';

    partial_payment_smoke_out([
        'status' => $ok ? 'ok' : 'failed',
        'subscriber_code' => $subscriberCode,
        'applied' => $applied,
        'is_active' => is_array($updated) ? (int) ($updated['is_active'] ?? -1) : null,
        'account_status' => is_array($updated) ? (string) ($updated['status'] ?? '') : null,
        'balance' => (string) ($billing['balance'] ?? ''),
        'expires_at' => (string) ($billing['expires_at'] ?? ''),
        'payment_effect' => (string) ($metadata['payment_effect'] ?? ''),
    ], $ok ? 0 : 3);
    $exitCode = $ok ? 0 : 3;
} catch (Throwable $exception) {
    partial_payment_smoke_out(['status' => 'failed', 'reason' => $exception->getMessage()], 2);
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
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $accountId]);
    }
}

exit($exitCode);
