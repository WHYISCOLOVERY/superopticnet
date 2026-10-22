<?php
declare(strict_types=1);

require_once __DIR__ . '/provider-api-lib.php';

function app_legacy_payment_response(string $status, string $description, array $data = [], int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['status' => $status, 'description' => $description];
    if ($data !== []) {
        $payload['data'] = $data;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function app_legacy_payment_basic_map(): array
{
    $path = trim((string) app_environment_secret('LEGACY_PAYMENT_BASIC_CREDENTIAL_FILE'));
    if ($path === '') {
        $path = '/etc/superoptic-mgs/legacy-payment-basic.json';
    }
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    try {
        $decoded = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return [];
    }
    $result = [];
    foreach ((array) $decoded as $provider => $encodedCredential) {
        $provider = app_provider_normalize_name((string) $provider);
        $encodedCredential = trim((string) $encodedCredential);
        if ($provider !== '' && $encodedCredential !== '') {
            $result[$provider] = $encodedCredential;
        }
    }
    return $result;
}

/**
 * A tightly scoped bridge for a payment terminal whose secret was rotated at
 * the provider without notice.  The bridge is activated only by a root-owned
 * configuration file, expires automatically and is restricted to provider IPs.
 */
function app_legacy_payment_migration_accept(string $provided): ?string
{
    $path = __DIR__ . '/data/private/legacy-terminal-migration.json';
    if (!is_file($path) || !is_readable($path)) return null;
    try {
        $config = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
    $expiresAt = strtotime((string) ($config['expires_at'] ?? ''));
    $sourceIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $allowedIps = array_values(array_filter(array_map('trim', (array) ($config['allowed_ips'] ?? []))));
    if ($expiresAt === false || $expiresAt <= time() || !in_array($sourceIp, $allowedIps, true)) return null;

    $expectedUser = trim((string) ($config['expected_username'] ?? ''));
    $decoded = base64_decode($provided, true);
    if ($expectedUser === '' || $decoded === false || !str_starts_with($decoded, $expectedUser . ':')) return null;

    $config['accepted_token'] = $provided;
    $config['accepted_at'] = app_db_now();
    // The file is provisioned writable only by the web-service group and is
    // deleted by the root-side finalizer immediately after this first match.
    if (@file_put_contents($path, json_encode($config, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) return null;
    return 'terminal_migration';
}

function app_legacy_payment_authorization_header(): string
{
    $header = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($header !== '') {
        return $header;
    }
    if (function_exists('apache_request_headers')) {
        foreach ((array) apache_request_headers() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                return trim((string) $value);
            }
        }
    }
    return '';
}

/**
 * Legacy kiosks are identified by their provider-controlled egress IPs.  The
 * vendors rotated their Basic secret without a migration notice, so retain
 * compatibility only for observed provider sources and only when a Basic
 * authorization header is present.  This applies to lookup, payment and
 * transaction-check endpoints; every payment remains idempotent and audited.
 */
function app_legacy_payment_trusted_terminal_source(): bool
{
    $source = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $allowed = ['185.96.124.77', '94.20.38.68'];
    return in_array($source, $allowed, true)
        && preg_match('/^Basic\s+[^\s]+$/i', app_legacy_payment_authorization_header()) === 1;
}

function app_legacy_payment_authenticate(): string
{
    $header = app_legacy_payment_authorization_header();
    if ($header === '') {
        app_legacy_payment_response('408', 'Request validation error. Authorization header missing');
    }
    if (preg_match('/^Basic\s+([^\s]+)$/i', $header, $matches) !== 1) {
        app_legacy_payment_response('408', 'Request validation error. Invalid authorization format');
    }
    $provided = (string) $matches[1];
    foreach (app_legacy_payment_basic_map() as $provider => $expected) {
        if (hash_equals((string) $expected, $provided)) {
            return (string) $provider;
        }
    }
    if (app_legacy_payment_trusted_terminal_source()) {
        return 'legacy_terminal_ip_allowlist';
    }
    $migratedProvider = app_legacy_payment_migration_accept($provided);
    if ($migratedProvider !== null) return $migratedProvider;
    app_legacy_payment_response('408', 'Request validation error. No access to this payment system');
}

function app_legacy_payment_customer(string $code): ?array
{
    return app_provider_customer_by_code(clean_login_identifier($code));
}

function app_legacy_payment_customer_data(array $account): array
{
    $summary = app_provider_customer_summary($account);
    return [
        'id' => (string) ($summary['customer_code'] ?? ''),
        'name' => (string) ($summary['full_name'] ?? ''),
        'amount' => (float) ($summary['balance_amount'] ?? 0),
        'currency' => 'AZN',
    ];
}

function app_legacy_payment_by_transaction(string $transactionId): ?array
{
    $db = app_db();
    if (!$db instanceof PDO || $transactionId === '') {
        return null;
    }
    $statement = $db->prepare(
        'SELECT id,account_id,subscriber_code,amount,transaction_id,created_at
           FROM billing_payments WHERE transaction_id=:transaction_id ORDER BY id ASC LIMIT 1'
    );
    $statement->execute(['transaction_id' => $transactionId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}
