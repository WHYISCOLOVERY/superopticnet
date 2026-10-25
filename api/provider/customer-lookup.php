<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/provider-api-lib.php';

app_send_security_headers();

$rawBody = (string) file_get_contents('php://input');
$auth = app_provider_authenticate($rawBody, '', 'customer:read');

$code = clean_login_identifier((string) ($_GET['code'] ?? $_GET['customer_code'] ?? $_GET['abonent_kodu'] ?? ''));
if ($code === '') {
    app_provider_json_response(['success' => false, 'code' => 'customer_code_required'], 400);
}

$account = app_provider_customer_by_code($code);
if ($account === null) {
    app_provider_json_response([
        'success' => false,
        'code' => 'customer_not_found',
        'customer_code' => $code,
    ], 404);
}

app_provider_json_response([
    'success' => true,
    'provider' => (string) $auth['provider'],
    'customer' => app_provider_customer_summary($account),
]);
