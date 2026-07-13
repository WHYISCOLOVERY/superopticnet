<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}

$employee = $db->query(
    "SELECT *
     FROM portal_accounts
     WHERE role = 'employee' AND is_active = 1
     ORDER BY FIELD(account_type, 'admin', 'operator', 'technician'), id ASC
     LIMIT 1"
)?->fetch(PDO::FETCH_ASSOC);

$customer = $db->query(
    "SELECT *
     FROM portal_accounts
     WHERE role = 'customer' AND is_active = 1
     ORDER BY (subscriber_code LIKE 'SMOKE-%') ASC, id ASC
     LIMIT 1"
)?->fetch(PDO::FETCH_ASSOC);

if (!is_array($employee) || !is_array($customer)) {
    fwrite(STDERR, "missing_seed_accounts\n");
    exit(3);
}

$_GET = ['id' => (string) $customer['id']];
$_POST = [];
$_REQUEST = $_GET;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/superopticnet/billing-customer-profile.php?id=' . (int) $customer['id'];
$_SERVER['SCRIPT_NAME'] = '/superopticnet/billing-customer-profile.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

app_session_boot();
$_SESSION['portal_user'] = app_db_user_session_payload($employee);

ob_start();
include dirname(__DIR__) . '/billing-customer-profile.php';
$html = (string) ob_get_clean();

$markers = [
    'name="action" value="refresh_customer_radius_session"',
    'data-profile-radius-refresh',
    'RADIUS yenile',
    'minmax(520px',
];

$missing = [];
foreach ($markers as $marker) {
    if (!str_contains($html, $marker)) {
        $missing[] = $marker;
    }
}

echo json_encode([
    'status' => $missing === [] ? 'ok' : 'failed',
    'customer_id' => (int) $customer['id'],
    'missing' => $missing,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($missing === [] ? 0 : 4);
