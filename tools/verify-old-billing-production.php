<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

function legacy_verify_arg(string $name, int $default = 0): int
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv as $argument) {
        if (str_starts_with((string) $argument, $prefix)) {
            return max(0, (int) substr((string) $argument, strlen($prefix)));
        }
    }

    return $default;
}

function legacy_verify_count(PDO $db, string $sql): int
{
    return (int) ($db->query($sql)->fetchColumn() ?: 0);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database is unavailable.\n");
    exit(2);
}

$expectedCustomers = legacy_verify_arg('expected-customers');
$expectedPackages = legacy_verify_arg('expected-packages');
$expectedPayments = legacy_verify_arg('expected-payments');
$expectedSecrets = legacy_verify_arg('expected-secrets');

$counts = [
    'old_customers' => legacy_verify_count(
        $db,
        "SELECT COUNT(*) FROM portal_accounts WHERE role='customer' AND external_id LIKE 'old-billing:%'"
    ),
    'old_packages' => legacy_verify_count(
        $db,
        "SELECT COUNT(*) FROM billing_tariffs WHERE external_id LIKE 'old-billing-package:%'"
    ),
    'old_payments' => legacy_verify_count(
        $db,
        "SELECT COUNT(*) FROM billing_payments WHERE external_id LIKE 'old-billing-payment:%'"
    ),
    'old_services' => legacy_verify_count(
        $db,
        "SELECT COUNT(*)
         FROM billing_radius_services s
         INNER JOIN portal_accounts a ON a.id=s.account_id
         WHERE a.external_id LIKE 'old-billing:%'"
    ),
    'old_customers_with_source_ip' => legacy_verify_count(
        $db,
        "SELECT COUNT(*)
         FROM portal_accounts
         WHERE external_id LIKE 'old-billing:%'
           AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.billing.ip_address')), '') <> ''"
    ),
    'old_customers_with_normalized_expiry' => legacy_verify_count(
        $db,
        "SELECT COUNT(*)
         FROM portal_accounts
         WHERE external_id LIKE 'old-billing:%'
           AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.billing.expires_at')), '') <> ''"
    ),
    'active_ip_assignments' => legacy_verify_count(
        $db,
        "SELECT COUNT(*)
         FROM customer_ip_assignments i
         INNER JOIN portal_accounts a ON a.id=i.customer_id
         WHERE a.external_id LIKE 'old-billing:%' AND i.active=1"
    ),
    'imported_pools' => legacy_verify_count(
        $db,
        "SELECT COUNT(*) FROM billing_ip_pools
         WHERE name LIKE 'Pool %' OR name LIKE 'Static Pool %'"
    ),
    'old_customers_with_area' => legacy_verify_count(
        $db,
        "SELECT COUNT(*) FROM portal_accounts
         WHERE external_id LIKE 'old-billing:%' AND TRIM(COALESCE(area, '')) <> ''"
    ),
    'old_customers_with_street_address' => legacy_verify_count(
        $db,
        "SELECT COUNT(*) FROM portal_accounts
         WHERE external_id LIKE 'old-billing:%' AND TRIM(COALESCE(address, '')) <> ''"
    ),
    'old_customers_with_notes' => legacy_verify_count(
        $db,
        "SELECT COUNT(*) FROM portal_accounts
         WHERE external_id LIKE 'old-billing:%'
           AND JSON_LENGTH(JSON_EXTRACT(payload_json, '$.notes')) > 0"
    ),
    'encrypted_pppoe_secrets' => legacy_verify_count(
        $db,
        "SELECT COUNT(*)
         FROM legacy_customer_secret_vault v
         INNER JOIN portal_accounts a ON a.id=v.account_id
         WHERE a.external_id LIKE 'old-billing:%' AND v.secret_type='pppoe_password'"
    ),
    'old_payment_rejects' => legacy_verify_count(
        $db,
        "SELECT COUNT(*) FROM legacy_import_rejects WHERE source_type='payment'"
    ),
];

$problems = [
    'duplicate_subscriber_codes' => legacy_verify_count(
        $db,
        "SELECT COUNT(*) FROM (
            SELECT subscriber_code
            FROM portal_accounts
            WHERE external_id LIKE 'old-billing:%'
            GROUP BY subscriber_code
            HAVING COUNT(*) > 1
         ) duplicate_codes"
    ),
    'customers_without_service_snapshot' => legacy_verify_count(
        $db,
        "SELECT COUNT(*)
         FROM portal_accounts a
         WHERE a.external_id LIKE 'old-billing:%'
           AND NOT EXISTS (SELECT 1 FROM billing_radius_services s WHERE s.account_id=a.id)"
    ),
    'source_ips_without_service_ip' => legacy_verify_count(
        $db,
        "SELECT COUNT(*)
         FROM portal_accounts a
         LEFT JOIN billing_radius_services s ON s.account_id=a.id
         WHERE a.external_id LIKE 'old-billing:%'
           AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '$.billing.ip_address')), '') <> ''
           AND COALESCE(s.assigned_ip, s.static_ip, s.nat_public_ip, '') = ''"
    ),
    'service_ips_without_active_assignment' => legacy_verify_count(
        $db,
        "SELECT COUNT(*)
         FROM billing_radius_services s
         INNER JOIN portal_accounts a ON a.id=s.account_id
         WHERE a.external_id LIKE 'old-billing:%'
           AND COALESCE(s.assigned_ip, s.static_ip, s.nat_public_ip, '') <> ''
           AND NOT EXISTS (
               SELECT 1 FROM customer_ip_assignments i
               WHERE i.customer_id=s.account_id
                 AND i.service_username=s.username
                 AND i.active=1
                 AND COALESCE(i.local_ip, i.static_ip, i.nat_public_ip, '') =
                     COALESCE(s.assigned_ip, s.static_ip, s.nat_public_ip, '')
           )"
    ),
    'active_duplicate_ips' => legacy_verify_count(
        $db,
        "SELECT COUNT(*) FROM (
            SELECT COALESCE(NULLIF(static_ip,''), NULLIF(assigned_ip,''), NULLIF(nat_public_ip,'')) AS service_ip
            FROM billing_radius_services
            WHERE status='active'
              AND COALESCE(NULLIF(static_ip,''), NULLIF(assigned_ip,''), NULLIF(nat_public_ip,'')) IS NOT NULL
            GROUP BY service_ip
            HAVING COUNT(*) > 1
         ) duplicate_ips"
    ),
    'service_ips_outside_pool' => legacy_verify_count(
        $db,
        "SELECT COUNT(*)
         FROM billing_radius_services s
         INNER JOIN portal_accounts a ON a.id=s.account_id
         LEFT JOIN billing_ip_pools p
           ON p.status='active'
          AND INET_ATON(COALESCE(NULLIF(s.static_ip,''), NULLIF(s.assigned_ip,''), NULLIF(s.nat_public_ip,'')))
              BETWEEN INET_ATON(p.range_start) AND INET_ATON(p.range_end)
         WHERE a.external_id LIKE 'old-billing:%'
           AND COALESCE(NULLIF(s.static_ip,''), NULLIF(s.assigned_ip,''), NULLIF(s.nat_public_ip,'')) IS NOT NULL
           AND p.id IS NULL"
    ),
    'plaintext_legacy_secrets' => legacy_verify_count(
        $db,
        "SELECT COUNT(*)
         FROM portal_accounts
         WHERE external_id LIKE 'old-billing:%'
           AND (
               COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.credentials.pppoe_password')), '') <> ''
               OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.credentials.customer_portal_password')), '') <> ''
               OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.legacy_exact_fields.pppoe_password')), '') <> ''
               OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.legacy_exact_fields.customer_portal_password')), '') <> ''
               OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.old_billing.edit_json.pass')), '') NOT IN ('', '[redacted]')
               OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.old_billing.edit_json.stabil')), '') NOT IN ('', '[redacted]')
           )"
    ),
    'invalid_encrypted_secret_rows' => legacy_verify_count(
        $db,
        "SELECT COUNT(*)
         FROM legacy_customer_secret_vault v
         INNER JOIN portal_accounts a ON a.id=v.account_id
         WHERE a.external_id LIKE 'old-billing:%'
           AND (
               v.algorithm <> 'aes-256-gcm'
               OR TRIM(v.ciphertext_b64) = ''
               OR TRIM(v.nonce_b64) = ''
               OR TRIM(v.auth_tag_b64) = ''
           )"
    ),
];

$expected = [
    'customers' => $expectedCustomers,
    'packages' => $expectedPackages,
    'payments' => $expectedPayments,
    'secrets' => $expectedSecrets,
];
$expectationFailures = [
    'customers' => $expectedCustomers > 0 && $counts['old_customers'] !== $expectedCustomers,
    'packages' => $expectedPackages > 0 && $counts['old_packages'] !== $expectedPackages,
    'payments' => $expectedPayments > 0
        && ($counts['old_payments'] + $counts['old_payment_rejects']) !== $expectedPayments,
    'secrets' => $expectedSecrets > 0 && $counts['encrypted_pppoe_secrets'] !== $expectedSecrets,
];

$blockingProblemKeys = [
    'duplicate_subscriber_codes',
    'customers_without_service_snapshot',
    'source_ips_without_service_ip',
    'service_ips_without_active_assignment',
    'service_ips_outside_pool',
    'plaintext_legacy_secrets',
    'invalid_encrypted_secret_rows',
];
$blockingProblems = array_filter(
    $problems,
    static fn (int $value, string $key): bool => in_array($key, $blockingProblemKeys, true) && $value > 0,
    ARRAY_FILTER_USE_BOTH
);
$ok = !in_array(true, $expectationFailures, true) && $blockingProblems === [];

$result = [
    'checked_at' => date(DATE_ATOM),
    'expected' => $expected,
    'counts' => $counts,
    'problems' => $problems,
    'warnings' => [
        'active_duplicate_ips_require_manual_reconciliation' => $problems['active_duplicate_ips'],
    ],
    'expectation_failures' => $expectationFailures,
    'ok' => $ok,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($ok ? 0 : 4);
