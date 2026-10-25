<?php
declare(strict_types=1);

require dirname(__DIR__) . '/provider-api-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$checks = [];
$check = static function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

putenv('APP_ENVIRONMENT=production');
putenv('PROVIDER_API_ALLOW_LEGACY_SHARED_CREDENTIALS=0');
putenv('PROVIDER_API_TOKEN=legacy-shared-token-must-not-be-used');
putenv('PROVIDER_API_HMAC_SECRET=legacy-shared-hmac-must-not-be-used');
putenv('EMANAT_PROVIDER_API_TOKEN=emanat-provider-token-0123456789');
putenv('EMANAT_PROVIDER_HMAC_SECRET=emanat-provider-hmac-01234567890123456789');
putenv('UNITEDPAYMENT_PROVIDER_API_TOKEN=united-provider-token-0123456789');
putenv('UNITEDPAYMENT_PROVIDER_HMAC_SECRET=united-provider-hmac-01234567890123456789');
putenv('EMANAT_PROVIDER_ALLOWED_IPS=203.0.113.10,198.51.100.20');

$check('production environment detected', app_provider_is_production());
$check('UP alias normalized', app_provider_normalize_name('UP') === 'unitedpayment');
$check('United Payment alias normalized', app_provider_normalize_name('United Payment') === 'unitedpayment');
$check('Emanat alias normalized', app_provider_normalize_name('Emanat Terminal') === 'emanat');
$check(
    'Emanat token is provider-scoped',
    app_provider_expected_token('emanat') === 'emanat-provider-token-0123456789'
);
$check(
    'United token is provider-scoped',
    app_provider_expected_token('unitedpayment') === 'united-provider-token-0123456789'
);
$check(
    'provider tokens are distinct',
    !hash_equals(app_provider_expected_token('emanat'), app_provider_expected_token('unitedpayment'))
);
$check(
    'legacy shared token is disabled in production',
    !app_provider_legacy_shared_credentials_allowed()
);
$check(
    'provider IP allowlist parsed',
    app_provider_allowed_ips('emanat') === ['203.0.113.10', '198.51.100.20']
);

$timestamp = '1785232800';
$body = '{"provider":"emanat","transaction_id":"TX-1","amount":"12.50"}';
$secret = app_provider_hmac_secret('emanat');
$expected = hash_hmac('sha256', $timestamp . "\n" . $body, $secret);
$check('HMAC contract is stable', hash_equals($expected, app_provider_signature($timestamp, $body, $secret)));

$_SERVER['REQUEST_URI'] = '/api/provider/payment.php';
$check('production payment requires HMAC', app_provider_requires_hmac_for_request());
$check(
    'idempotency header accepted',
    app_provider_request_idempotency_key(['idempotency-key' => 'emanat:TX-1']) === 'emanat:TX-1'
);

$failed = array_values(array_filter($checks, static fn (array $row): bool => !$row['ok']));
echo json_encode([
    'status' => $failed === [] ? 'ok' : 'failed',
    'checks' => $checks,
    'failed' => count($failed),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($failed === [] ? 0 : 1);
