<?php
declare(strict_types=1);

require dirname(__DIR__) . '/integration-api-client-lib.php';

$failures = [];
$checks = 0;
$expect = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$first = app_integration_api_generate_material('emanat');
$second = app_integration_api_generate_material('emanat');
$expect($first['key_id'] !== $second['key_id'], 'key_id_not_unique');
$expect($first['secret'] !== $second['secret'], 'private_secret_not_unique');
$expect(str_starts_with($first['key_id'], 'sop_live_emanat_'), 'key_id_prefix_invalid');
$expect(str_starts_with($first['secret'], 'sop_secret_'), 'private_secret_prefix_invalid');

$hash = app_integration_api_secret_hash($first['secret']);
$expect($hash !== $first['secret'], 'secret_stored_as_plaintext');
$expect(password_verify($first['secret'], $hash), 'secret_hash_verification_failed');
$parsed = app_integration_api_parse_bearer($first['key_id'] . '.' . $first['secret']);
$expect(is_array($parsed) && $parsed['key_id'] === $first['key_id'], 'bearer_parse_failed');
$expect(app_integration_api_parse_bearer('legacy-token') === null, 'legacy_token_misclassified');

$expect(app_integration_api_provider_slug('United Payment') === 'unitedpayment', 'unitedpayment_normalization_failed');
$expect(app_integration_api_provider_slug('Emanat Terminal') === 'emanat', 'emanat_normalization_failed');
$expect(
    app_integration_api_normalize_scopes(['customer:read', 'invalid', 'payment:write'])
        === ['customer:read', 'payment:write'],
    'scope_allowlist_failed'
);
$expect(app_integration_api_ip_matches('203.0.113.10', '203.0.113.0/24'), 'ipv4_cidr_match_failed');
$expect(!app_integration_api_ip_matches('203.0.114.10', '203.0.113.0/24'), 'ipv4_cidr_deny_failed');
$expect(app_integration_api_ip_matches('2001:db8::5', '2001:db8::/64'), 'ipv6_cidr_match_failed');

$root = dirname(__DIR__);
$routeScopes = [
    'api/provider/customer-lookup.php' => "'customer:read'",
    'api/provider/payment.php' => "'payment:write'",
    'api/provider/payment-status.php' => "'payment:status'",
    'api/provider/up/insert-payment/index.php' => "'payment:write'",
    'api/provider/up/payment-status/index.php' => "'payment:status'",
];
foreach ($routeScopes as $path => $needle) {
    $source = (string) file_get_contents($root . '/' . $path);
    $expect(str_contains($source, $needle), 'route_scope_missing:' . $path);
}

$migration = (string) file_get_contents($root . '/db/migrations/20260729_110_integration_api_clients.php');
$expect(str_contains($migration, 'secret_hash VARCHAR(255) NOT NULL'), 'secret_hash_column_missing');
$expect(!str_contains($migration, 'private_secret'), 'plaintext_secret_column_detected');
$expect(str_contains($migration, "ENUM('active','revoked')"), 'credential_status_constraint_missing');

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
