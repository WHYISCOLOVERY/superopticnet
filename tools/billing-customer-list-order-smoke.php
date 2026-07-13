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
    echo json_encode(['status' => 'failed', 'message' => 'Database unavailable'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(2);
}

$employee = $db->query(
    "SELECT *
     FROM portal_accounts
     WHERE role = 'employee' AND is_active = 1
     ORDER BY FIELD(account_type, 'admin', 'operator', 'technician'), id
     LIMIT 1"
)->fetch();

if (!is_array($employee)) {
    echo json_encode(['status' => 'failed', 'message' => 'No active employee account'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(3);
}

$_GET = ['per_page' => '25'];
$_POST = [];
$_REQUEST = $_GET;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/superopticnet/billing-customers.php?per_page=25';
$_SERVER['SCRIPT_NAME'] = '/superopticnet/billing-customers.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
app_session_boot();
$_SESSION['portal_user'] = app_db_user_session_payload($employee);

chdir(dirname(__DIR__));
ob_start();
include dirname(__DIR__) . '/billing-customers.php';
$html = (string) ob_get_clean();

preg_match_all('/<tbody>.*?<strong>([^<]+)<\/strong>/s', $html, $matches);
$firstCode = (string) ($matches[1][0] ?? '');
$ok = $firstCode !== '' && !str_starts_with($firstCode, 'SMOKE-');

echo json_encode([
    'status' => $ok ? 'ok' : 'failed',
    'checks' => [
        'first_customer_is_real' => $ok,
    ],
    'first_code' => $firstCode,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($ok ? 0 : 4);
