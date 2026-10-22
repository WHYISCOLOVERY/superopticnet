<?php
declare(strict_types=1);

/**
 * Read-only reconciliation of router session usernames passed on STDIN against
 * the billing database. Values are normalized and only aggregate counts leave
 * the server.
 */
$applicationRoot = is_file('/var/www/superopticnet/db.php')
    ? '/var/www/superopticnet'
    : dirname(__DIR__);
require_once $applicationRoot . '/config.php';
require_once $applicationRoot . '/db.php';

$usernames = array_values(array_unique(array_filter(
    preg_split('/\R+/', (string) stream_get_contents(STDIN)) ?: [],
    static fn (string $value): bool => preg_match('/^[A-Za-z0-9._@-]{1,80}$/', $value) === 1
)));

if ($usernames === []) {
    throw new RuntimeException('Router session input is empty.');
}

$db = app_db();
$placeholders = implode(',', array_fill(0, count($usernames), '?'));
$base = "FROM (SELECT username, account_id, status FROM billing_radius_services WHERE username IN ($placeholders)) s
    LEFT JOIN portal_accounts p ON p.id = s.account_id AND p.role = 'customer'";

$queries = [
    'router_session_usernames' => count($usernames),
    'registered_services' => "SELECT COUNT(*) $base",
    'active_services' => "SELECT COUNT(*) $base WHERE s.status = 'active'",
    'active_portal_accounts' => "SELECT COUNT(*) $base WHERE p.is_active = 1",
    'active_service_and_account' => "SELECT COUNT(*) $base WHERE s.status = 'active' AND p.is_active = 1",
    'inactive_portal_accounts' => "SELECT COUNT(*) $base WHERE p.is_active = 0",
    'unregistered_router_sessions' => count($usernames),
];

foreach ($queries as $name => $sql) {
    if (is_int($sql)) {
        continue;
    }
    $statement = $db->prepare($sql);
    $statement->execute($usernames);
    $queries[$name] = (int) $statement->fetchColumn();
}
$queries['unregistered_router_sessions'] = max(0, $usernames === [] ? 0 : count($usernames) - $queries['registered_services']);

foreach ($queries as $name => $value) {
    echo $name . '=' . $value . PHP_EOL;
}
