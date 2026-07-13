<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "DB connection is not ready.\n");
    exit(2);
}

$tables = [
    'portal_accounts',
    'billing_tariffs',
    'billing_payments',
    'billing_radius_services',
    'billing_ip_pools',
    'ip_pools',
    'ip_addresses',
    'customer_ip_assignments',
    'legacy_billing_profile_sections',
];

$result = [
    'checked_at' => date('c'),
    'database' => [],
    'tables' => [],
];

$dbName = '';
try {
    $dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
} catch (Throwable) {
    $dbName = '';
}
$result['database'] = [
    'name' => $dbName,
];

foreach ($tables as $table) {
    try {
        $count = $db->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn();
        $result['tables'][$table] = [
            'exists' => true,
            'rows' => (int) $count,
        ];
    } catch (Throwable $exception) {
        $result['tables'][$table] = [
            'exists' => false,
            'rows' => null,
        ];
    }
}

$result['legacy'] = [
    'old_customers' => (int) $db->query("SELECT COUNT(*) FROM portal_accounts WHERE role = 'customer' AND external_id LIKE 'old-billing:%'")->fetchColumn(),
    'old_payments' => (int) $db->query("SELECT COUNT(*) FROM billing_payments WHERE external_id LIKE 'old-billing-payment:%'")->fetchColumn(),
    'old_tariffs' => (int) $db->query("SELECT COUNT(*) FROM billing_tariffs WHERE external_id LIKE 'old-billing-package:%'")->fetchColumn(),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
