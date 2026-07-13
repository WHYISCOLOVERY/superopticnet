<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function billing_customer_edit_ui_smoke_fail(string $message, int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

$db = app_db();
if (!$db instanceof PDO) {
    billing_customer_edit_ui_smoke_fail('Database unavailable', 2);
}

$employee = $db->query(
    "SELECT *
     FROM portal_accounts
     WHERE role = 'employee' AND is_active = 1
     ORDER BY FIELD(account_type, 'admin', 'operator', 'technician') ASC, id
     LIMIT 1"
)?->fetch();

$customer = $db->query(
    "SELECT *
     FROM portal_accounts
     WHERE role = 'customer' AND is_active = 1
     ORDER BY updated_at DESC, id DESC
     LIMIT 1"
)?->fetch();

if (!is_array($employee) || !is_array($customer)) {
    billing_customer_edit_ui_smoke_fail('Employee or customer missing', 3);
}

$_GET = ['id' => (string) ($customer['id'] ?? 0)];
$_POST = [];
$_REQUEST = $_GET;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/superopticnet/billing-customer-edit.php?id=' . rawurlencode((string) ($customer['id'] ?? 0));
$_SERVER['SCRIPT_NAME'] = '/superopticnet/billing-customer-edit.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

app_session_boot();
$_SESSION['portal_user'] = app_db_user_session_payload($employee);

chdir(dirname(__DIR__));
ob_start();
include dirname(__DIR__) . '/billing-customer-edit.php';
$html = (string) ob_get_clean();

$expectedMarkers = [
    'billing-edit-plan-summary',
    'billing-edit-anchor-preview',
    'billing-edit-stable-presets',
    'Tarif secimi ve aylıq anchor',
];

foreach ($expectedMarkers as $marker) {
    if (!str_contains($html, $marker)) {
        billing_customer_edit_ui_smoke_fail('billing-customer-edit.php missing marker: ' . $marker, 4);
    }
}

echo json_encode([
    'status' => 'ok',
    'markers_checked' => count($expectedMarkers),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
