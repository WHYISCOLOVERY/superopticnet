<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}

$employee = $db->query(
    "SELECT *
     FROM portal_accounts
     WHERE role = 'employee' AND is_active = 1
     ORDER BY FIELD(account_type, 'admin', 'operator', 'technician'), id ASC
     LIMIT 1"
)?->fetch(PDO::FETCH_ASSOC);

if (!is_array($employee)) {
    fwrite(STDERR, "missing_employee_account\n");
    exit(3);
}

$runId = date('His') . '-' . bin2hex(random_bytes(3));
$subscriberCode = 'SMOKE-EQ-' . $runId;
$macTail = strtoupper(implode(':', str_split(bin2hex(random_bytes(3)), 2)));
$accountId = 0;
$assetId = 0;
$modelId = 0;

try {
    $payload = [
        'profile' => [
            'subscriber_code' => $subscriberCode,
            'full_name' => 'Equipment Lifecycle Smoke',
            'phone' => '994501111111',
        ],
        'billing' => [
            'service_type' => 'pppoe',
            'tariff' => 'Smoke',
            'status' => 'Aktiv',
            'radius_status' => 'Aktiv',
        ],
        'internet_services' => [],
        'ont_profiles' => [],
    ];

    $insert = $db->prepare(
        'INSERT INTO portal_accounts (
            login, password_hash, role, account_type, full_name, subscriber_code, plan, status, is_active, payload_json
         ) VALUES (
            :login, :password_hash, "customer", "customer", :full_name, :subscriber_code, "Smoke", "Aktiv", 1, :payload_json
         )'
    );
    $insert->execute([
        'login' => strtolower($subscriberCode),
        'password_hash' => password_hash('Portal1234', PASSWORD_DEFAULT),
        'full_name' => 'Equipment Lifecycle Smoke',
        'subscriber_code' => $subscriberCode,
        'payload_json' => app_db_json_encode($payload),
    ]);
    $accountId = (int) $db->lastInsertId();

    $modelId = app_mgs_inventory_upsert_asset_model($db, 'HSGQ', 'Customer360 Smoke ONT', 'ont');
    $assetId = app_mgs_inventory_create_asset($db, [
        'model_id' => $modelId,
        'asset_code' => 'C360-ASSET-' . $runId,
        'serial_number' => 'C360SN' . strtoupper(str_replace('-', '', $runId)),
        'mac_address' => '02:36:0A:' . $macTail,
        'ownership_type' => 'company',
        'lifecycle_status' => 'in_warehouse',
        'condition_status' => 'USED_WORKING',
    ]);
    app_mgs_inventory_install_asset($db, $assetId, $subscriberCode, 'T001', 'SMOKE-TICKET-' . $runId, 'company');

    app_session_boot();
    $_SESSION = [];
    $_SESSION['portal_user'] = app_db_user_session_payload($employee);

    $_GET = ['id' => (string) $accountId, 'tab' => 'devices'];
    $_POST = [];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['REQUEST_URI'] = '/superopticnet/billing-customer-profile.php?id=' . rawurlencode((string) $accountId) . '&tab=devices';
    $_SERVER['SCRIPT_NAME'] = '/superopticnet/billing-customer-profile.php';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    ob_start();
    include dirname(__DIR__) . '/billing-customer-profile.php';
    $html = (string) ob_get_clean();

    $markers = [
        'data-customer-equipment-lifecycle',
        'Customer equipment',
        'C360SN',
        'USED_WORKING',
        'SMOKE-TICKET-',
    ];
    $missing = [];
    foreach ($markers as $marker) {
        if (!str_contains($html, $marker)) {
            $missing[] = $marker;
        }
    }

    echo json_encode([
        'status' => $missing === [] ? 'ok' : 'failed',
        'subscriber_code' => $subscriberCode,
        'missing' => $missing,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    exit($missing === [] ? 0 : 4);
} finally {
    if ($subscriberCode !== '') {
        $db->prepare('DELETE FROM customer_equipment_assignments WHERE customer_code = :code')->execute(['code' => $subscriberCode]);
        $db->prepare('DELETE FROM inventory_lifecycle_movements WHERE customer_code = :code')->execute(['code' => $subscriberCode]);
        $db->prepare('DELETE FROM portal_accounts WHERE subscriber_code = :code')->execute(['code' => $subscriberCode]);
    }
    if ($assetId > 0) {
        $db->prepare('DELETE FROM inventory_asset_inspections WHERE asset_id = :id')->execute(['id' => $assetId]);
        $db->prepare('DELETE FROM inventory_asset_returns WHERE asset_id = :id')->execute(['id' => $assetId]);
        $db->prepare('DELETE FROM inventory_assets WHERE id = :id')->execute(['id' => $assetId]);
    }
}
