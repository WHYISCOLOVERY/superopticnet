<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "MySQL/PDO connection is not ready.\n");
    exit(2);
}

$tariff = $db->query(
    'SELECT * FROM billing_tariffs
     WHERE status = "active"
       AND service_type IN ("pppoe", "ipoe")
       AND monthly_fee > 0
       AND download_mbps >= 10
     ORDER BY monthly_fee, download_mbps
     LIMIT 1'
)->fetch();
if (!is_array($tariff)) {
    fwrite(STDERR, "No active billing tariff with monthly fee found.\n");
    exit(3);
}

$subscriberCode = 'SMOKE-BILL-' . date('His');
$transactionId = 'SMOKE-TX-' . date('YmdHis');
$externalId = 'SMOKE-EXT-' . date('YmdHis');
$login = strtolower($subscriberCode);
$password = 'Smoke1234';
$accountId = 0;

try {
    $payload = [
        'profile' => [
            'subscriber_code' => $subscriberCode,
            'full_name' => 'Billing Smoke Test',
            'phone' => '+994000000000',
        ],
        'billing' => [
            'service_type' => (string) ($tariff['service_type'] ?? 'pppoe'),
            'tariff' => (string) ($tariff['name'] ?? ''),
            'pppoe_username' => $subscriberCode,
            'pppoe_password' => $password,
            'status' => 'Deaktiv',
            'radius_status' => 'Vaxt bitib',
            'expires_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'default_days' => 30,
            'billing_days' => 30,
        ],
        'internet_services' => [
            [
                'service_type' => (string) ($tariff['service_type'] ?? 'pppoe'),
                'username' => $subscriberCode,
                'password' => $password,
                'tariff' => (string) ($tariff['name'] ?? ''),
                'status' => 'expired',
                'expires_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
            ],
        ],
        'extra_services' => [
            [
                'type' => 'iptv',
                'title' => 'Smoke IPTV',
                'details' => 'Smoke test active add-on',
                'price' => 7.50,
                'status' => 'active',
            ],
            [
                'type' => 'static_ip',
                'title' => 'Disabled static IP',
                'details' => 'Must not be counted',
                'price' => 10.00,
                'status' => 'disabled',
            ],
        ],
    ];

    $insert = $db->prepare(
        'INSERT INTO portal_accounts (
            login, password_hash, role, account_type, full_name, subscriber_code, phone, plan, status, is_active, payload_json
         ) VALUES (
            :login, :password_hash, "customer", "customer", :full_name, :subscriber_code, :phone, :plan, "Deaktiv", 0, :payload_json
         )'
    );
    $insert->execute([
        'login' => $login,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'full_name' => 'Billing Smoke Test',
        'subscriber_code' => $subscriberCode,
        'phone' => '+994000000000',
        'plan' => (string) ($tariff['name'] ?? ''),
        'payload_json' => app_db_json_encode($payload),
    ]);
    $accountId = (int) $db->lastInsertId();

    $account = app_db_account_by_id($accountId);
    $expiredSync = $account !== null && app_billing_sync_radius_account($account);

    $expiredRadcheck = [];
    $statement = $db->prepare('SELECT attribute, op, value FROM radcheck WHERE username = :username ORDER BY id');
    $statement->execute(['username' => $subscriberCode]);
    $expiredRadcheck = $statement->fetchAll();

    $paymentOk = app_billing_apply_customer_payment($subscriberCode, 0.0, 'smoke-test', 30, $transactionId, $externalId);

    $paymentStatement = $db->prepare('SELECT amount, external_id, metadata_json FROM billing_payments WHERE transaction_id = :tx LIMIT 1');
    $paymentStatement->execute(['tx' => $transactionId]);
    $payment = $paymentStatement->fetch() ?: [];

    $activeRadcheckStatement = $db->prepare('SELECT attribute, op, value FROM radcheck WHERE username = :username ORDER BY id');
    $activeRadcheckStatement->execute(['username' => $subscriberCode]);

    $activeRadreplyStatement = $db->prepare('SELECT attribute, op, value FROM radreply WHERE username = :username ORDER BY id');
    $activeRadreplyStatement->execute(['username' => $subscriberCode]);

    $metadata = app_db_json_decode((string) ($payment['metadata_json'] ?? ''), []);
    $result = [
        'subscriber_code' => $subscriberCode,
        'expired_sync' => $expiredSync,
        'expired_radcheck' => $expiredRadcheck,
        'payment_applied' => $paymentOk,
        'tariff_fee' => (float) ($tariff['monthly_fee'] ?? 0),
        'expected_payment_amount' => round((float) ($tariff['monthly_fee'] ?? 0) + 7.50, 2),
        'actual_payment_amount' => isset($payment['amount']) ? (float) $payment['amount'] : null,
        'expected_external_id' => $externalId,
        'actual_external_id' => (string) ($payment['external_id'] ?? ''),
        'metadata' => $metadata,
        'active_radcheck' => $activeRadcheckStatement->fetchAll(),
        'active_radreply' => $activeRadreplyStatement->fetchAll(),
        'cleaned' => false,
    ];
} finally {
    if ($accountId > 0) {
        foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
            $statement = $db->prepare("DELETE FROM {$table} WHERE username = :username");
            $statement->execute(['username' => $subscriberCode]);
        }
        $db->prepare('DELETE FROM billing_payments WHERE subscriber_code = :code OR transaction_id = :tx')->execute([
            'code' => $subscriberCode,
            'tx' => $transactionId,
        ]);
        $db->prepare('DELETE FROM billing_radius_services WHERE account_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $accountId]);
    }
}

$result['cleaned'] = true;
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
