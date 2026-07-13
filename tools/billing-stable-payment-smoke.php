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
    'SELECT *
     FROM billing_tariffs
     WHERE status = "active"
       AND service_type = "pppoe"
     ORDER BY monthly_fee, download_mbps
     LIMIT 1'
)->fetch();

if (!is_array($tariff)) {
    fwrite(STDERR, "No active PPPoE tariff found.\n");
    exit(3);
}

$subscriberCode = 'SMOKE-STABLE-' . date('His');
$password = 'Stable1234';
$accountId = 0;

try {
    $oldExpiresAt = date('Y-m-01 19:00:00', strtotime('-1 month')) ?: date('Y-m-d H:i:s', strtotime('-30 days'));
    $expectedExpiresAt = app_billing_next_stable_due_datetime(1, time(), $oldExpiresAt);

    $payload = [
        'profile' => [
            'subscriber_code' => $subscriberCode,
            'full_name' => 'Stable Day Smoke',
            'stable_due_day' => 1,
        ],
        'billing' => [
            'service_type' => 'pppoe',
            'tariff' => (string) ($tariff['name'] ?? ''),
            'pppoe_username' => $subscriberCode,
            'pppoe_password' => $password,
            'status' => 'Deaktiv',
            'radius_status' => 'Vaxt bitib',
            'expires_at' => $oldExpiresAt,
            'default_days' => 30,
            'billing_days' => 30,
            'stable_due_day' => 1,
        ],
        'internet_services' => [
            [
                'service_type' => 'pppoe',
                'username' => $subscriberCode,
                'password' => $password,
                'tariff' => (string) ($tariff['name'] ?? ''),
                'status' => 'expired',
                'expires_at' => $oldExpiresAt,
                'stable_due_day' => 1,
            ],
        ],
    ];

    $insert = $db->prepare(
        'INSERT INTO portal_accounts (
            login, password_hash, role, account_type, full_name, subscriber_code, plan, status, is_active, payload_json
         ) VALUES (
            :login, :password_hash, "customer", "customer", :full_name, :subscriber_code, :plan, "Deaktiv", 0, :payload_json
         )'
    );
    $insert->execute([
        'login' => strtolower($subscriberCode),
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'full_name' => 'Stable Day Smoke',
        'subscriber_code' => $subscriberCode,
        'plan' => (string) ($tariff['name'] ?? ''),
        'payload_json' => app_db_json_encode($payload),
    ]);
    $accountId = (int) $db->lastInsertId();

    $account = app_db_account_by_id($accountId);
    if ($account === null || !app_billing_sync_radius_account($account)) {
        throw new RuntimeException('Initial RADIUS sync failed.');
    }

    if (!app_billing_apply_customer_payment($subscriberCode, 0.0, 'stable-smoke', 30, 'SMOKE-STABLE-TX-' . date('YmdHis'))) {
        throw new RuntimeException('Payment apply failed.');
    }

    $updated = app_db_account_by_id($accountId);
    $updatedPayload = is_array($updated) ? app_db_account_payload($updated) : [];
    $updatedBilling = is_array($updatedPayload['billing'] ?? null) ? $updatedPayload['billing'] : [];
    $actualExpiresAt = (string) ($updatedBilling['expires_at'] ?? '');

    $ok = $actualExpiresAt === $expectedExpiresAt;
    echo json_encode([
        'status' => $ok ? 'ok' : 'failed',
        'expected_expires_at' => $expectedExpiresAt,
        'actual_expires_at' => $actualExpiresAt,
        'stable_due_day' => $updatedBilling['stable_due_day'] ?? null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    exit($ok ? 0 : 5);
} finally {
    if ($accountId > 0) {
        foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
            $statement = $db->prepare("DELETE FROM {$table} WHERE username = :username");
            $statement->execute(['username' => $subscriberCode]);
        }
        $db->prepare('DELETE FROM billing_payments WHERE subscriber_code = :code')->execute(['code' => $subscriberCode]);
        $db->prepare('DELETE FROM billing_radius_services WHERE account_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $accountId]);
    }
}
