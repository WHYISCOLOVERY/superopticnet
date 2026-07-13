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

$employee = $db->query(
    "SELECT *
     FROM portal_accounts
     WHERE role = 'employee' AND is_active = 1
     ORDER BY FIELD(account_type, 'admin', 'operator', 'technician') ASC, id
     LIMIT 1"
)->fetch();

$tariff = $db->query(
    'SELECT *
     FROM billing_tariffs
     WHERE status = "active"
       AND service_type = "pppoe"
     ORDER BY monthly_fee, download_mbps
     LIMIT 1'
)->fetch();

$pool = $db->query(
    'SELECT *
     FROM billing_ip_pools
     WHERE status = "active"
       AND service_type = "pppoe"
     ORDER BY id
     LIMIT 1'
)->fetch();

if (!is_array($employee) || !is_array($tariff) || !is_array($pool)) {
    fwrite(STDERR, "Employee/tariff/pool seed not available.\n");
    exit(3);
}

$subscriberCode = 'SMOKE-SERVICE-' . date('His');
$accountId = 0;
$newUsername = $subscriberCode . '-2';
$expectedExpiresAt = app_billing_resolve_next_expires_at(
    app_billing_tariff_default_days($tariff),
    1,
    null,
    time()
);

try {
    $payload = [
        'profile' => [
            'subscriber_code' => $subscriberCode,
            'full_name' => 'Stable Add Service Smoke',
        ],
        'billing' => [
            'service_type' => 'pppoe',
            'tariff' => (string) ($tariff['name'] ?? ''),
            'pppoe_username' => $subscriberCode,
            'pppoe_password' => 'Base1234',
            'status' => 'Aktiv',
            'radius_status' => 'Aktiv',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'default_days' => 30,
            'billing_days' => 30,
        ],
        'internet_services' => [[
            'service_type' => 'pppoe',
            'username' => $subscriberCode,
            'password' => 'Base1234',
            'tariff' => (string) ($tariff['name'] ?? ''),
            'status' => 'active',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]],
    ];

    $insert = $db->prepare(
        'INSERT INTO portal_accounts (
            login, password_hash, role, account_type, full_name, subscriber_code, plan, status, is_active, payload_json
         ) VALUES (
            :login, :password_hash, "customer", "customer", :full_name, :subscriber_code, :plan, "Aktiv", 1, :payload_json
         )'
    );
    $insert->execute([
        'login' => strtolower($subscriberCode),
        'password_hash' => password_hash('Portal1234', PASSWORD_DEFAULT),
        'full_name' => 'Stable Add Service Smoke',
        'subscriber_code' => $subscriberCode,
        'plan' => (string) ($tariff['name'] ?? ''),
        'payload_json' => app_db_json_encode($payload),
    ]);
    $accountId = (int) $db->lastInsertId();

    $account = app_db_account_by_id($accountId);
    if ($account === null || !app_billing_sync_radius_account($account)) {
        throw new RuntimeException('Initial RADIUS sync failed.');
    }

    app_session_boot();
    $_SESSION = [];
    $_SESSION['portal_user'] = app_db_user_session_payload($employee);
    $csrfToken = app_csrf_token();

    $_GET = ['id' => (string) $accountId];
    $_POST = [
        'action' => 'add_radius_service',
        'csrf_token' => $csrfToken,
        'service_type' => 'pppoe',
        'username' => $newUsername,
        'password' => 'Service1234',
        'tariff_id' => (string) ($tariff['id'] ?? ''),
        'pool_id' => (string) ($pool['id'] ?? ''),
        'assigned_ip' => '',
        'nat_public_ip' => '',
        'static_ip' => '',
        'vlan' => '',
        'mac_address' => '',
        'expires_at' => '',
        'service_days' => '',
        'stable_due_day' => '1',
    ];
    $_REQUEST = $_GET + $_POST;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['REQUEST_URI'] = '/superopticnet/billing-customer-profile.php?id=' . rawurlencode((string) $accountId);
    $_SERVER['SCRIPT_NAME'] = '/superopticnet/billing-customer-profile.php';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    ob_start();
    include dirname(__DIR__) . '/billing-customer-profile.php';
    ob_end_clean();

    $updated = app_db_account_by_id($accountId);
    $updatedPayload = is_array($updated) ? app_db_account_payload($updated) : [];
    $updatedBilling = is_array($updatedPayload['billing'] ?? null) ? $updatedPayload['billing'] : [];
    $updatedServices = is_array($updatedPayload['internet_services'] ?? null) ? array_values(array_filter($updatedPayload['internet_services'], 'is_array')) : [];
    $createdService = null;
    foreach ($updatedServices as $service) {
        if (strcasecmp((string) ($service['username'] ?? ''), $newUsername) === 0) {
            $createdService = $service;
            break;
        }
    }

    $actualTimestamp = strtotime((string) ($createdService['expires_at'] ?? '')) ?: 0;
    $expectedTimestamp = strtotime($expectedExpiresAt) ?: 0;
    $ok = is_array($createdService)
        && (int) ($updatedBilling['stable_due_day'] ?? 0) === 1
        && (int) ($createdService['stable_due_day'] ?? 0) === 1
        && abs($actualTimestamp - $expectedTimestamp) <= 5;

    echo json_encode([
        'status' => $ok ? 'ok' : 'failed',
        'subscriber_code' => $subscriberCode,
        'service_username' => $newUsername,
        'expected_expires_at' => $expectedExpiresAt,
        'actual_expires_at' => $createdService['expires_at'] ?? null,
        'billing_stable_due_day' => $updatedBilling['stable_due_day'] ?? null,
        'service_stable_due_day' => $createdService['stable_due_day'] ?? null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    exit($ok ? 0 : 5);
} finally {
    if ($accountId > 0) {
        foreach ([$subscriberCode, $newUsername] as $username) {
            foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
                $statement = $db->prepare("DELETE FROM {$table} WHERE username = :username");
                $statement->execute(['username' => $username]);
            }
        }
        $db->prepare('DELETE FROM customer_ip_assignments WHERE customer_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE FROM ip_assignment_history WHERE customer_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE FROM billing_radius_services WHERE account_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $accountId]);
    }
}
