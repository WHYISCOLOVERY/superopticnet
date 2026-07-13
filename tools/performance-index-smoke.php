<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function performance_index_smoke_finish(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($code);
}

$db = app_db();
if (!$db instanceof PDO) {
    performance_index_smoke_finish(['status' => 'skipped', 'reason' => 'database_unavailable']);
}

app_db_bootstrap($db);

$required = [
    'portal_accounts' => [
        'idx_portal_accounts_role_active_status',
        'idx_portal_accounts_role_area',
        'idx_portal_accounts_contract',
        'idx_portal_accounts_phone',
    ],
    'billing_radius_services' => [
        'idx_billing_radius_services_account_status',
        'idx_billing_radius_services_ip_lookup',
        'idx_billing_radius_services_status',
    ],
    'billing_payments' => [
        'idx_billing_payments_account',
        'idx_billing_payments_subscriber_created',
        'idx_billing_payments_method_created',
        'idx_billing_payments_transaction',
        'idx_billing_payments_created',
    ],
    'portal_tickets' => [
        'idx_portal_tickets_customer_status',
        'idx_portal_tickets_subscriber_status',
        'idx_portal_tickets_status',
        'idx_portal_tickets_assigned',
    ],
    'radacct' => [
        'idx_radacct_stop_user',
        'idx_radacct_start_user',
        'idx_radacct_username',
    ],
];

$missing = [];
foreach ($required as $table => $indexes) {
    foreach ($indexes as $index) {
        if (!app_db_index_exists($db, $table, $index)) {
            $missing[] = $table . '.' . $index;
        }
    }
}

performance_index_smoke_finish([
    'status' => $missing === [] ? 'ok' : 'failed',
    'missing' => $missing,
    'checked_tables' => array_keys($required),
], $missing === [] ? 0 : 3);
