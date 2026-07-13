<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function crm_prefill_fail(string $message, int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

function crm_prefill_render(string $query = ''): string
{
    $db = app_db();
    if (!$db instanceof PDO) {
        crm_prefill_fail('Database unavailable', 2);
    }

    $employee = $db->query(
        "SELECT *
         FROM portal_accounts
         WHERE role = 'employee' AND is_active = 1
         ORDER BY id ASC
         LIMIT 1"
    )?->fetch();

    if (!is_array($employee)) {
        crm_prefill_fail('No active employee account', 3);
    }

    parse_str($query, $params);
    $_GET = array_map(static fn ($value) => is_scalar($value) ? (string) $value : '', $params);
    $_POST = [];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['REQUEST_URI'] = '/superopticnet/crm.php' . ($query !== '' ? '?' . $query : '');
    $_SERVER['SCRIPT_NAME'] = '/superopticnet/crm.php';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    app_session_boot();
    $_SESSION['portal_user'] = app_db_user_session_payload($employee);

    chdir(dirname(__DIR__));
    ob_start();
    include dirname(__DIR__) . '/crm.php';
    return (string) ob_get_clean();
}

$db = app_db();
if (!$db instanceof PDO) {
    crm_prefill_fail('Database unavailable', 4);
}

$customer = $db->query(
    "SELECT id, full_name, subscriber_code, contract_no, login, phone, area, address
     FROM portal_accounts
     WHERE role = 'customer'
       AND is_active = 1
       AND subscriber_code IS NOT NULL
       AND subscriber_code <> ''
       AND phone IS NOT NULL
       AND phone <> ''
     ORDER BY id ASC
     LIMIT 1"
)?->fetch();

if (!is_array($customer)) {
    crm_prefill_fail('No active customer with phone found', 5);
}

$codeResults = app_db_customer_lookup((string) $customer['subscriber_code'], 5);
if ($codeResults === []) {
    crm_prefill_fail('Customer lookup by subscriber code returned empty result', 6);
}

$phoneResults = app_db_customer_lookup((string) $customer['phone'], 5);
if ($phoneResults === []) {
    crm_prefill_fail('Customer lookup by phone returned empty result', 7);
}

$html = crm_prefill_render(http_build_query(['create' => '1']));
$markers = [
    'data-crm-customer-lookup',
    'list="crmCustomerOptions"',
    'data-customer-account-id-input',
    'data-customer-code-input',
    'data-customer-name-input',
    'data-customer-phone-input',
    'data-customer-area-input',
    'data-crm-area-select',
    'data-customer-address-input',
    'data-crm-area-input',
    'data-crm-customer-preview',
    'list="crmAreaOptions"',
];

foreach ($markers as $marker) {
    if (!str_contains($html, $marker)) {
        crm_prefill_fail('CRM page missing marker: ' . $marker, 8);
    }
}

echo json_encode([
    'status' => 'ok',
    'customer' => [
        'subscriber_code' => (string) $customer['subscriber_code'],
        'phone' => (string) $customer['phone'],
    ],
    'checks' => [
        'lookup_by_code' => true,
        'lookup_by_phone' => true,
        'crm_lookup_markers' => true,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
