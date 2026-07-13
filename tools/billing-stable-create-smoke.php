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

$staleBillingPools = $db->query("SELECT id FROM billing_ip_pools WHERE name LIKE 'Smoke PPPoE Pool %'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
foreach ($staleBillingPools as $stalePoolId) {
    $stalePoolId = (int) $stalePoolId;
    if ($stalePoolId <= 0) {
        continue;
    }
    $db->prepare('DELETE FROM ip_pools WHERE legacy_billing_pool_id = :id')->execute(['id' => $stalePoolId]);
    $db->prepare('DELETE FROM billing_ip_pools WHERE id = :id')->execute(['id' => $stalePoolId]);
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
       AND pool_type <> "static"
     ORDER BY id
     LIMIT 1'
)->fetch();

if (!is_array($employee) || !is_array($tariff) || !is_array($pool)) {
    fwrite(STDERR, "Employee/tariff/pool seed not available.\n");
    exit(3);
}

$subscriberCode = 'SMOKE-CREATE-' . date('His');
$expectedExpiresAt = app_billing_resolve_next_expires_at(
    app_billing_tariff_default_days($tariff),
    1,
    null,
    time()
);
$createdAccountId = 0;
$expectedAssignedIp = app_billing_next_available_ip((int) ($pool['id'] ?? 0)) ?? '';
$createdPoolId = 0;
if ($expectedAssignedIp === '') {
    $poolName = 'Smoke PPPoE Pool ' . date('YmdHis');
    $rangeOctet = random_int(1, 240);
    $insertPool = $db->prepare(
        'INSERT INTO billing_ip_pools (name, service_type, pool_type, range_start, range_end, gateway, netmask, status)
         VALUES (:name, "pppoe", "dynamic", :range_start, :range_end, :gateway, :netmask, "active")'
    );
    $insertPool->execute([
        'name' => $poolName,
        'range_start' => '10.254.' . $rangeOctet . '.10',
        'range_end' => '10.254.' . $rangeOctet . '.30',
        'gateway' => '10.254.' . $rangeOctet . '.1',
        'netmask' => '255.255.255.0',
    ]);
    $createdPoolId = (int) $db->lastInsertId();
    $pool = $db->query('SELECT * FROM billing_ip_pools WHERE id = ' . $createdPoolId . ' LIMIT 1')->fetch();
    $expectedAssignedIp = app_billing_next_available_ip($createdPoolId) ?? '';
}

try {
    app_session_boot();
    $_SESSION = [];
    $_SESSION['portal_user'] = app_db_user_session_payload($employee);
    $csrfToken = app_csrf_token();

    $_GET = [];
    $identitySuffix = (string) random_int(1000000, 9999999);
    $_POST = [
        'action' => 'create_billing_customer',
        'csrf_token' => $csrfToken,
        'subscriber_code' => $subscriberCode,
        'full_name' => 'Stable Create Smoke',
        'phone' => '+99450' . $identitySuffix,
        'fin_code' => 'SMK' . substr($identitySuffix, 0, 5),
        'document_no' => 'AZE' . $identitySuffix,
        'address' => 'Stable smoke test address',
        'service_type' => 'pppoe',
        'tariff_id' => (string) ($tariff['id'] ?? ''),
        'pool_id' => (string) ($pool['id'] ?? ''),
        'pppoe_password' => 'Create1234',
        'radius_username' => $subscriberCode,
        'billing_days' => '',
        'stable_due_day' => '1',
        'expires_at' => '',
        'portal_password' => 'Portal1234',
    ];
    $_REQUEST = $_POST;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['REQUEST_URI'] = '/superopticnet/billing-customers.php';
    $_SERVER['SCRIPT_NAME'] = '/superopticnet/billing-customers.php';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    ob_start();
    include dirname(__DIR__) . '/billing-customers.php';
    $renderedHtml = (string) ob_get_clean();
    $flashMessage = '';
    if (isset($flash) && is_array($flash)) {
        $flashMessage = (string) ($flash['message'] ?? '');
    }

    $account = app_db_account_by_subscriber_code($subscriberCode);
    $createdAccountId = (int) ($account['id'] ?? 0);
    $payload = is_array($account) ? app_db_account_payload($account) : [];
    $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
    $services = is_array($payload['internet_services'] ?? null) ? array_values(array_filter($payload['internet_services'], 'is_array')) : [];
    $service = $services[0] ?? [];

    $actualExpiresAt = (string) ($billing['expires_at'] ?? '');
    $actualAssignedIp = (string) ($billing['assigned_ip'] ?? '');
    $actualServiceAssignedIp = (string) ($service['assigned_ip'] ?? '');
    $actualBillingIpMode = (string) ($billing['ip_assignment_mode'] ?? '');
    $actualServiceIpMode = (string) ($service['ip_assignment_mode'] ?? '');
    $radreplyIp = '';
    $radcheckExpiration = '';
    if ($createdAccountId > 0) {
        $replyStatement = $db->prepare(
            'SELECT value
             FROM radreply
             WHERE username = :username AND attribute = "Framed-IP-Address"
             ORDER BY id DESC LIMIT 1'
        );
        $replyStatement->execute(['username' => $subscriberCode]);
        $radreplyIp = (string) ($replyStatement->fetchColumn() ?: '');

        $expirationStatement = $db->prepare(
            'SELECT value
             FROM radcheck
             WHERE username = :username AND attribute = "Expiration"
             ORDER BY id DESC LIMIT 1'
        );
        $expirationStatement->execute(['username' => $subscriberCode]);
        $radcheckExpiration = (string) ($expirationStatement->fetchColumn() ?: '');
    }
    $actualTimestamp = strtotime($actualExpiresAt) ?: 0;
    $expectedTimestamp = strtotime($expectedExpiresAt) ?: 0;
    $expectedRadcheckExpiration = $actualTimestamp > 0 ? date('M d Y H:i:s', $actualTimestamp) : '';
    $ok = $createdAccountId > 0
        && (int) ($billing['stable_due_day'] ?? 0) === 1
        && (int) ($service['stable_due_day'] ?? 0) === 1
        && abs($actualTimestamp - $expectedTimestamp) <= 5
        && $expectedAssignedIp !== ''
        && $actualAssignedIp === $expectedAssignedIp
        && $actualServiceAssignedIp === $expectedAssignedIp
        && $actualBillingIpMode === 'pool'
        && $actualServiceIpMode === 'pool'
        && $radreplyIp === $expectedAssignedIp
        && $radcheckExpiration === $expectedRadcheckExpiration;

    echo json_encode([
        'status' => $ok ? 'ok' : 'failed',
        'subscriber_code' => $subscriberCode,
        'expected_expires_at' => $expectedExpiresAt,
        'actual_expires_at' => $actualExpiresAt,
        'expected_assigned_ip' => $expectedAssignedIp,
        'actual_assigned_ip' => $actualAssignedIp,
        'service_assigned_ip' => $actualServiceAssignedIp,
        'billing_ip_assignment_mode' => $actualBillingIpMode,
        'service_ip_assignment_mode' => $actualServiceIpMode,
        'radreply_framed_ip' => $radreplyIp,
        'expected_radcheck_expiration' => $expectedRadcheckExpiration,
        'actual_radcheck_expiration' => $radcheckExpiration,
        'billing_stable_due_day' => $billing['stable_due_day'] ?? null,
        'service_stable_due_day' => $service['stable_due_day'] ?? null,
        'flash_message' => $flashMessage,
        'rendered_error_detected' => str_contains($renderedHtml, 'portal-alert-error'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    exit($ok ? 0 : 5);
} finally {
    if ($createdAccountId > 0) {
        foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
            $statement = $db->prepare("DELETE FROM {$table} WHERE username = :username");
            $statement->execute(['username' => $subscriberCode]);
        }
        $db->prepare('DELETE FROM customer_ip_assignments WHERE customer_id = :id')->execute(['id' => $createdAccountId]);
        $db->prepare('DELETE FROM ip_assignment_history WHERE customer_id = :id')->execute(['id' => $createdAccountId]);
        $db->prepare('DELETE FROM billing_radius_services WHERE account_id = :id')->execute(['id' => $createdAccountId]);
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $createdAccountId]);
    }
    if ($createdPoolId > 0) {
        $db->prepare('DELETE FROM ip_pools WHERE legacy_billing_pool_id = :id')->execute(['id' => $createdPoolId]);
        $db->prepare('DELETE FROM billing_ip_pools WHERE id = :id')->execute(['id' => $createdPoolId]);
    }
}
