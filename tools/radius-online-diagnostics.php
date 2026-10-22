<?php
declare(strict_types=1);

/**
 * Read-only production diagnostic for reconciling billing customer counts with
 * live RADIUS accounting. It intentionally reports aggregates only.
 */
$applicationRoot = is_file('/var/www/superopticnet/db.php')
    ? '/var/www/superopticnet'
    : dirname(__DIR__);
require_once $applicationRoot . '/config.php';
require_once $applicationRoot . '/db.php';

$db = app_db();
$queries = [
    'portal_customers' => "SELECT COUNT(*) FROM portal_accounts WHERE role = 'customer'",
    'active_portal_customers' => "SELECT COUNT(*) FROM portal_accounts WHERE role = 'customer' AND is_active = 1",
    'radius_services_active' => "SELECT COUNT(DISTINCT account_id) FROM billing_radius_services WHERE status = 'active'",
    'radacct_open_rows' => "SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL",
    'radacct_open_distinct_username' => "SELECT COUNT(DISTINCT username) FROM radacct WHERE acctstoptime IS NULL",
    'radacct_open_customer_join' => "SELECT COUNT(DISTINCT s.account_id)
        FROM radacct r
        JOIN billing_radius_services s ON s.username = r.username
        JOIN portal_accounts p ON p.id = s.account_id AND p.role = 'customer'
        WHERE r.acctstoptime IS NULL AND p.is_active = 1",
    'radacct_open_with_service' => "SELECT COUNT(DISTINCT r.username)
        FROM radacct r JOIN billing_radius_services s ON s.username = r.username
        WHERE r.acctstoptime IS NULL",
    'radacct_open_active_service' => "SELECT COUNT(DISTINCT r.username)
        FROM radacct r JOIN billing_radius_services s ON s.username = r.username AND s.status = 'active'
        WHERE r.acctstoptime IS NULL",
    'radacct_open_inactive_portal' => "SELECT COUNT(DISTINCT r.username)
        FROM radacct r
        JOIN billing_radius_services s ON s.username = r.username
        JOIN portal_accounts p ON p.id = s.account_id AND p.role = 'customer'
        WHERE r.acctstoptime IS NULL AND p.is_active = 0",
    'radacct_open_without_service' => "SELECT COUNT(DISTINCT r.username)
        FROM radacct r LEFT JOIN billing_radius_services s ON s.username = r.username
        WHERE r.acctstoptime IS NULL AND s.username IS NULL",
    'radacct_open_duplicate_usernames' => "SELECT COUNT(*) FROM (
        SELECT username FROM radacct WHERE acctstoptime IS NULL
        GROUP BY username HAVING COUNT(*) > 1
    ) duplicate_sessions",
    'radacct_open_last_24h' => "SELECT COUNT(DISTINCT username)
        FROM radacct
        WHERE acctstoptime IS NULL AND acctstarttime >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
    'radacct_fresh_20m' => "SELECT COUNT(DISTINCT username)
        FROM radacct
        WHERE acctstoptime IS NULL
          AND COALESCE(acctupdatetime, acctstarttime) >= DATE_SUB(NOW(), INTERVAL 20 MINUTE)",
    'radacct_fresh_12m' => "SELECT COUNT(DISTINCT username)
        FROM radacct
        WHERE acctstoptime IS NULL
          AND COALESCE(acctupdatetime, acctstarttime) >= DATE_SUB(NOW(), INTERVAL 12 MINUTE)",
    'radacct_fresh_60m' => "SELECT COUNT(DISTINCT username)
        FROM radacct
        WHERE acctstoptime IS NULL
          AND COALESCE(acctupdatetime, acctstarttime) >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)",
];

foreach ($queries as $name => $sql) {
    echo $name . '=' . (int) $db->query($sql)->fetchColumn() . PHP_EOL;
}

$states = $db->query("SELECT COALESCE(NULLIF(LOWER(TRIM(s.status)), ''), 'missing') AS service_status,
        COALESCE(p.is_active, -1) AS portal_active,
        COUNT(DISTINCT r.username) AS total
    FROM radacct r
    LEFT JOIN billing_radius_services s ON s.username = r.username
    LEFT JOIN portal_accounts p ON p.id = s.account_id AND p.role = 'customer'
    WHERE r.acctstoptime IS NULL
    GROUP BY COALESCE(NULLIF(LOWER(TRIM(s.status)), ''), 'missing'), COALESCE(p.is_active, -1)
    ORDER BY total DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($states as $state) {
    echo 'open_state[' . $state['service_status'] . ',' . $state['portal_active'] . ']=' . $state['total'] . PHP_EOL;
}

$times = $db->query("SELECT MIN(acctstarttime), MAX(COALESCE(acctupdatetime, acctstarttime))
    FROM radacct WHERE acctstoptime IS NULL")->fetch(PDO::FETCH_NUM) ?: ['', ''];
echo 'open_oldest=' . (string) ($times[0] ?? '') . PHP_EOL;
echo 'open_last_update=' . (string) ($times[1] ?? '') . PHP_EOL;
