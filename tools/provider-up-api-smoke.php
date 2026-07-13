<?php
declare(strict_types=1);

require dirname(__DIR__) . '/provider-api-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function provider_up_smoke_finish(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($code);
}

$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
$parsed = app_provider_read_json_body('memberId=100&toProcessId=tx-1&amount=12.50&channelId=1&channelDesc=Test');
if (($parsed['memberId'] ?? '') !== '100' || ($parsed['amount'] ?? '') !== '12.50') {
    provider_up_smoke_finish([
        'status' => 'failed',
        'reason' => 'form_urlencoded_parser_failed',
        'parsed' => $parsed,
    ], 2);
}

$db = app_db();
if (!$db instanceof PDO) {
    provider_up_smoke_finish([
        'status' => 'skipped',
        'reason' => 'database_unavailable',
    ]);
}

$subscriberCode = 'UP-SMOKE-' . date('His') . random_int(100, 999);
$transactionId = 'UP-TX-' . date('YmdHis') . random_int(100, 999);
$externalId = app_provider_external_id('unitedpayment', $transactionId);
$accountId = 0;

try {
    $payload = [
        'profile' => [
            'full_name' => 'Up Provider Smoke',
            'subscriber_code' => $subscriberCode,
            'phone' => '0500000000',
        ],
        'billing' => [
            'status' => 'Deaktiv',
            'balance' => '18.00 AZN',
            'tariff' => 'Smoke 100',
            'expires_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
        ],
        'payments' => [],
        'statements' => [[
            'period' => date('Y-m'),
            'balance' => '18.00 AZN',
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
        'full_name' => 'Up Provider Smoke',
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

    $memberPayload = app_up_provider_member_payload($account);
    $missingStatus = app_provider_payment_by_external_id($externalId) === null ? 'E000' : 'unexpected';
    $applied = app_billing_apply_customer_payment($subscriberCode, 18.00, 'unitedpayment:Test', 0, $transactionId, $externalId, date('Y-m-d H:i:s'));
    $duplicateBeforeApplyAgain = app_provider_payment_by_external_id($externalId) !== null;
    $appliedDuplicateSafe = app_billing_apply_customer_payment($subscriberCode, 18.00, 'unitedpayment:Test', 0, $transactionId, $externalId, date('Y-m-d H:i:s'));
    $paymentCountStatement = $db->prepare('SELECT COUNT(*) FROM billing_payments WHERE external_id = :external_id');
    $paymentCountStatement->execute(['external_id' => $externalId]);
    $paymentCount = (int) $paymentCountStatement->fetchColumn();

    provider_up_smoke_finish([
        'status' => ($applied && $appliedDuplicateSafe && $paymentCount === 1 && $duplicateBeforeApplyAgain) ? 'ok' : 'failed',
        'lookup_response_shape' => [
            'StatusCode' => '00',
            'memberInfo' => $memberPayload['memberInfo'] ?? '',
            'additionalInfo' => $memberPayload['additionalInfo'] ?? '',
            'balance' => $memberPayload['balance'] ?? '',
        ],
        'status_before_payment' => $missingStatus,
        'payment' => [
            'external_id' => $externalId,
            'applied' => $applied,
            'duplicate_detected_for_endpoint' => $duplicateBeforeApplyAgain,
            'duplicate_safe_apply' => $appliedDuplicateSafe,
            'stored_rows' => $paymentCount,
        ],
        'mpay_pending' => app_provider_is_mpay('MPay'),
    ], ($applied && $appliedDuplicateSafe && $paymentCount === 1 && $duplicateBeforeApplyAgain) ? 0 : 3);
} catch (Throwable $exception) {
    provider_up_smoke_finish([
        'status' => 'failed',
        'reason' => $exception->getMessage(),
    ], 2);
} finally {
    if ($accountId > 0) {
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
