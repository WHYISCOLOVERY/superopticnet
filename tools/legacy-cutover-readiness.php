<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/communication-provider.php';
require_once dirname(__DIR__) . '/legacy-payment-compat-lib.php';

$options = getopt('', ['expected-customers::', 'expected-payments::', 'phase::', 'strict', 'payment-count-only']);
$expectedCustomers = max(1, (int) ($options['expected-customers'] ?? 5500));
$expectedPayments = max(0, (int) ($options['expected-payments'] ?? 0));
$strict = isset($options['strict']);
$phase = strtolower(trim((string) ($options['phase'] ?? 'pre')));
$phase = in_array($phase, ['pre', 'post'], true) ? $phase : 'pre';
$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, json_encode(['status' => 'blocked', 'reason' => 'db_unavailable']) . PHP_EOL);
    exit(2);
}

if (isset($options['payment-count-only'])) {
    $statement = $db->query("SELECT COUNT(*) FROM billing_payments WHERE external_id LIKE 'old-billing-payment:%'");
    if (!$statement) {
        fwrite(STDERR, "payment_count_unavailable\n");
        exit(2);
    }
    echo (int) $statement->fetchColumn() . PHP_EOL;
    exit(0);
}

$checks = [];
$add = static function (string $key, bool $ok, int|string $actual, int|string $expected, string $detail = '') use (&$checks): void {
    $checks[$key] = [
        'ok' => $ok,
        'actual' => $actual,
        'expected' => $expected,
        'detail' => $detail,
    ];
};
$scalar = static function (PDO $db, string $sql): int {
    return (int) ($db->query($sql)?->fetchColumn() ?: 0);
};

$customers = $scalar($db, "SELECT COUNT(*) FROM portal_accounts WHERE role='customer'");
$services = $scalar($db, 'SELECT COUNT(*) FROM billing_radius_services');
$uniqueServices = $scalar($db, 'SELECT COUNT(DISTINCT username) FROM billing_radius_services');
$payments = $scalar($db, "SELECT COUNT(*) FROM billing_payments WHERE external_id LIKE 'old-billing-payment:%'");
$activeTariffs = $scalar($db, "SELECT COUNT(*) FROM billing_tariffs WHERE status='active' AND monthly_fee>0 AND radius_group<>''");
$radiusPools = $scalar($db, "SELECT COUNT(*) FROM billing_ip_pools WHERE status='active'");
$inventoryPools = $scalar($db, "SELECT COUNT(*) FROM ip_pools WHERE status='active'");
$poolMismatch = $scalar($db,
    "SELECT COUNT(*) FROM billing_ip_pools b
      LEFT JOIN ip_pools i ON i.legacy_billing_pool_id=b.id
       AND i.name=b.name AND i.start_ip=b.range_start AND i.end_ip=b.range_end
     WHERE b.status='active' AND i.id IS NULL"
);
$duplicateIps = $scalar($db,
    "SELECT COUNT(*) FROM (
        SELECT COALESCE(NULLIF(static_ip,''),NULLIF(assigned_ip,''),NULLIF(nat_public_ip,'')) ip
          FROM billing_radius_services
         WHERE status='active'
           AND COALESCE(NULLIF(static_ip,''),NULLIF(assigned_ip,''),NULLIF(nat_public_ip,'')) IS NOT NULL
         GROUP BY ip HAVING COUNT(*)>1
     ) duplicates"
);
$activeMissingAuth = $scalar($db,
    "SELECT COUNT(*) FROM billing_radius_services s
      LEFT JOIN radcheck r ON r.username=s.username AND r.attribute='Cleartext-Password'
     WHERE s.status='active' AND s.service_type='pppoe' AND r.id IS NULL"
);
$expiredStillActive = $scalar($db,
    "SELECT COUNT(*) FROM billing_radius_services
      WHERE auto_suspend=1 AND status='active' AND expires_at IS NOT NULL AND expires_at<NOW()"
);
$outboxPending = $scalar($db,
    "SELECT COUNT(*) FROM billing_payment_outbox WHERE status IN ('pending','retrying','processing')"
);
$outboxManual = $scalar($db,
    "SELECT COUNT(*) FROM billing_payment_outbox WHERE status='manual_required'"
);
$nasCount = $scalar($db, "SELECT COUNT(*) FROM nas WHERE nasname='10.99.99.1' AND secret<>''");

$add('customers_imported', $customers >= $expectedCustomers, $customers, '>=' . $expectedCustomers);
$add('radius_service_coverage', $services >= $customers && $uniqueServices === $services, $services . '/' . $uniqueServices, $customers . ' customers with unique service');
$add('legacy_payments_imported', $payments >= $expectedPayments, $payments, '>=' . $expectedPayments);
$add('active_tariffs_available', $activeTariffs > 0, $activeTariffs, '>0');
$add('radius_ip_pools_available', $radiusPools > 0, $radiusPools, '>0');
$add('ip_catalog_synced', $inventoryPools >= $radiusPools && $poolMismatch === 0, $inventoryPools . ' pools; mismatch=' . $poolMismatch, 'mismatch=0');
$add('active_ip_unique', $duplicateIps === 0, $duplicateIps, 0);
$add('active_pppoe_has_auth', $activeMissingAuth === 0, $activeMissingAuth, 0);
$add('expired_services_suspended', $phase === 'pre' || $expiredStillActive === 0, $expiredStillActive, $phase === 'post' ? 0 : 'post-cutover=0', 'Billing maintenance applies this gate immediately after RADIUS cutover.');
$add('payment_outbox_drained', $outboxPending === 0 && $outboxManual === 0, 'pending=' . $outboxPending . '; manual=' . $outboxManual, '0/0');
$add('router_nas_registered', $nasCount === 1, $nasCount, 1);

$legacyCredentialPath = trim((string) app_environment_secret('LEGACY_PAYMENT_BASIC_CREDENTIAL_FILE'));
if ($legacyCredentialPath === '') {
    $legacyCredentialPath = '/etc/superoptic-mgs/legacy-payment-basic.json';
}
$legacyCredentials = app_legacy_payment_basic_map();
$add('legacy_payment_contract',
    is_file(dirname(__DIR__) . '/payments/index.php')
        && is_file(dirname(__DIR__) . '/payments/payments.php')
        && is_file(dirname(__DIR__) . '/payments/check.php')
        && is_readable($legacyCredentialPath)
        && $legacyCredentials !== [],
    count($legacyCredentials) . ' provider credential(s)',
    '>=1 and all endpoints present'
);

$sms = app_communication_provider_public_status('sms', '994000000000');
$add('sms_delivery_configured',
    !empty($sms['enabled']) && !empty($sms['configured']) && !empty($sms['external_delivery_enabled']),
    (string) ($sms['reason'] ?? 'ready'),
    'enabled/configured/external delivery'
);

$failed = array_keys(array_filter($checks, static fn (array $row): bool => empty($row['ok'])));
$result = [
    'status' => $failed === [] ? 'ready' : 'blocked',
    'strict' => $strict,
    'checked_at' => date(DATE_ATOM),
    'summary' => ['checks' => count($checks), 'failed' => count($failed)],
    'failed' => $failed,
    'checks' => $checks,
    'secrets_exposed' => false,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($strict && $failed !== [] ? 1 : 0);
