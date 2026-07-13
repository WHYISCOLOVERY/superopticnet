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
     ORDER BY (FIELD(account_type, 'admin', 'operator', 'technician') = 0),
              FIELD(account_type, 'admin', 'operator', 'technician') ASC, id
     LIMIT 1"
)->fetch();

$tariffs = $db->query(
    'SELECT *
     FROM billing_tariffs
     WHERE status = "active"
       AND service_type = "pppoe"
     ORDER BY monthly_fee, download_mbps, id'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

if (!is_array($employee) || count($tariffs) < 2) {
    fwrite(STDERR, "Employee or multiple active PPPoE tariffs not available.\n");
    exit(3);
}

$originalTariff = $tariffs[0];
$updatedTariff = $tariffs[1];
$subscriberCode = 'SMOKE-EDIT-' . date('His');
$accountId = 0;

try {
    $payload = [
        'profile' => [
            'subscriber_code' => $subscriberCode,
            'full_name' => 'Stable Edit Smoke',
        ],
        'billing' => [
            'service_type' => 'pppoe',
            'tariff' => (string) ($originalTariff['name'] ?? ''),
            'pppoe_username' => $subscriberCode,
            'pppoe_password' => 'Edit1234',
            'status' => 'Aktiv',
            'radius_status' => 'Aktiv',
            'default_days' => (int) app_billing_tariff_default_days($originalTariff),
            'billing_days' => (int) app_billing_tariff_default_days($originalTariff),
        ],
        'internet_services' => [[
            'service_type' => 'pppoe',
            'username' => $subscriberCode,
            'password' => 'Edit1234',
            'tariff' => (string) ($originalTariff['name'] ?? ''),
            'status' => 'active',
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
        'full_name' => 'Stable Edit Smoke',
        'subscriber_code' => $subscriberCode,
        'plan' => (string) ($originalTariff['name'] ?? ''),
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
        'action' => 'save_billing_customer_edit',
        'account_id' => (string) $accountId,
        'csrf_token' => $csrfToken,
        'full_name' => 'Stable Edit Smoke Updated',
        'contract_no' => $subscriberCode,
        'fin_code' => 'ED' . substr(preg_replace('/\D/', '', $subscriberCode) ?: '12345', -5),
        'document_no' => 'AA' . substr(hash('crc32b', $subscriberCode), 0, 7),
        'phone' => '0500000000',
        'email' => 'stable-edit@example.test',
        'category' => 'residential',
        'area' => 'Test area',
        'address' => 'Smoke address',
        'tariff_id' => (string) ($updatedTariff['id'] ?? ''),
        'billing_days' => '',
        'stable_due_day' => '1',
        'expires_at' => '',
        'status' => 'Aktiv',
        'is_active' => '1',
        'change_reason' => 'Smoke tariff and stable day correction',
    ];
    $_REQUEST = $_GET + $_POST;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['REQUEST_URI'] = '/superopticnet/billing-customer-edit.php?id=' . rawurlencode((string) $accountId);
    $_SERVER['SCRIPT_NAME'] = '/superopticnet/billing-customer-edit.php';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $runnerFile = tempnam(sys_get_temp_dir(), 'billing_edit_smoke_');
    if ($runnerFile === false) {
        throw new RuntimeException('Unable to create temp runner.');
    }
    $runnerPhp = $runnerFile . '.php';
    @rename($runnerFile, $runnerPhp);
    $sessionPayload = var_export(app_db_user_session_payload($employee), true);
    $getPayload = var_export($_GET, true);
    $postPayload = var_export($_POST, true);
    $requestPayload = var_export($_REQUEST, true);
    $serverPayload = var_export([
        'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'],
        'HTTP_HOST' => $_SERVER['HTTP_HOST'],
        'REQUEST_URI' => $_SERVER['REQUEST_URI'],
        'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'],
        'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'],
    ], true);
    file_put_contents($runnerPhp, <<<PHP
<?php
require 'D:/xampp/htdocs/superopticnet/config.php';
app_session_boot();
\$_SESSION = [];
\$_SESSION['portal_user'] = {$sessionPayload};
\$_GET = {$getPayload};
\$_POST = {$postPayload};
\$_POST['csrf_token'] = app_csrf_token();
\$_REQUEST = {$requestPayload};
if (is_array(\$_REQUEST)) {
    \$_REQUEST['csrf_token'] = \$_POST['csrf_token'];
}
foreach ({$serverPayload} as \$key => \$value) {
    \$_SERVER[\$key] = \$value;
}
include 'D:/xampp/htdocs/superopticnet/billing-customer-edit.php';
PHP);
    $output = [];
    $exitCode = 1;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runnerPhp) . ' 2>&1', $output, $exitCode);
    @unlink($runnerPhp);
    if ($exitCode !== 0) {
        throw new RuntimeException('Edit handler failed: ' . implode("\n", $output));
    }

    $updatedAccount = app_db_account_by_id($accountId);
    $updatedPayload = is_array($updatedAccount) ? app_db_account_payload($updatedAccount) : [];
    $updatedBilling = is_array($updatedPayload['billing'] ?? null) ? $updatedPayload['billing'] : [];
    $updatedServices = is_array($updatedPayload['internet_services'] ?? null) ? array_values(array_filter($updatedPayload['internet_services'], 'is_array')) : [];
    $updatedService = $updatedServices[0] ?? [];
    $expectedExpiresAt = app_billing_resolve_next_expires_at(
        app_billing_tariff_default_days($updatedTariff),
        1,
        null,
        time()
    );

    $actualTimestamp = strtotime((string) ($updatedBilling['expires_at'] ?? '')) ?: 0;
    $expectedTimestamp = strtotime($expectedExpiresAt) ?: 0;
    $ok = is_array($updatedAccount)
        && (string) ($updatedAccount['plan'] ?? '') === (string) ($updatedTariff['name'] ?? '')
        && (string) ($updatedBilling['tariff'] ?? '') === (string) ($updatedTariff['name'] ?? '')
        && (int) ($updatedBilling['stable_due_day'] ?? 0) === 1
        && (int) ($updatedService['stable_due_day'] ?? 0) === 1
        && abs($actualTimestamp - $expectedTimestamp) <= 5;

    echo json_encode([
        'status' => $ok ? 'ok' : 'failed',
        'subscriber_code' => $subscriberCode,
        'expected_tariff' => $updatedTariff['name'] ?? null,
        'actual_tariff' => $updatedBilling['tariff'] ?? null,
        'expected_expires_at' => $expectedExpiresAt,
        'actual_expires_at' => $updatedBilling['expires_at'] ?? null,
        'billing_stable_due_day' => $updatedBilling['stable_due_day'] ?? null,
        'service_stable_due_day' => $updatedService['stable_due_day'] ?? null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    exit($ok ? 0 : 5);
} finally {
    if ($accountId > 0) {
        foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
            $statement = $db->prepare("DELETE FROM {$table} WHERE username = :username");
            $statement->execute(['username' => $subscriberCode]);
        }
        $db->prepare('DELETE FROM customer_ip_assignments WHERE customer_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE FROM ip_assignment_history WHERE customer_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE FROM billing_radius_services WHERE account_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE FROM billing_customer_fin_claims WHERE account_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $accountId]);
    }
}
