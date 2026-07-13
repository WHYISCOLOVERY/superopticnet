<?php
declare(strict_types=1);

require dirname(__DIR__) . '/provider-api-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function security_injection_fail(string $message, int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

$db = app_db();
if (!$db instanceof PDO) {
    security_injection_fail('Database unavailable', 2);
}

$payloads = [
    "' OR '1'='1",
    "admin' --",
    "serdar@sp.az' OR 1=1 --",
    "2320025' UNION SELECT password_hash FROM portal_accounts --",
];

foreach ($payloads as $payload) {
    if (app_portal_login('employee', $payload, $payload) !== null) {
        security_injection_fail('Employee login accepted injection payload', 3);
    }
    if (app_portal_login('customer', $payload, $payload) !== null) {
        security_injection_fail('Customer login accepted injection payload', 4);
    }
    if (app_provider_customer_by_code($payload) !== null) {
        security_injection_fail('Provider lookup accepted injection payload as customer code', 5);
    }
}

$lookup = app_db_customer_lookup("' OR 1=1 --", 10);
if ($lookup !== []) {
    security_injection_fail('Customer lookup returned rows for SQL-like payload', 6);
}

$scope = 'smoke_security_rate_' . bin2hex(random_bytes(4));
for ($i = 0; $i < 5; $i++) {
    app_rate_limit_hit($scope, 300);
}
if (!app_rate_limit_is_blocked($scope, 5, 300)) {
    security_injection_fail('Rate limit scope did not block after configured hits', 7);
}
app_rate_limit_clear($scope);
if (app_rate_limit_is_blocked($scope, 5, 300)) {
    security_injection_fail('Rate limit scope was not cleared', 8);
}

echo json_encode([
    'status' => 'ok',
    'checks' => [
        'employee_login_injection_rejected' => true,
        'customer_login_injection_rejected' => true,
        'provider_lookup_injection_rejected' => true,
        'customer_search_sql_like_payload_empty' => true,
        'rate_limit_block_and_clear' => true,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
