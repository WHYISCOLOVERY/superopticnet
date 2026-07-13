<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$options = getopt('', ['page:', 'query::']);
$page = basename((string) ($options['page'] ?? ''));
$query = (string) ($options['query'] ?? '');
$allowed = [
    'statistics-center.php',
    'statistics-center-partial.php',
    'ai-dashboard.php',
    'ai-copilot.php',
    'ai-customer-analytics.php',
    'ai-network-analytics.php',
    'ai-finance-analytics.php',
    'ai-risks.php',
    'ai-recommendations.php',
    'ai-automations.php',
    'ai-audit.php',
    'ai-model-status.php',
    'ai-module-partial.php',
];
if (!in_array($page, $allowed, true)) {
    fwrite(STDERR, 'Unsupported fixture page');
    exit(2);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, 'Database unavailable');
    exit(3);
}
$statement = $db->query(
    "SELECT * FROM portal_accounts
      WHERE role = 'employee' AND is_active = 1
      ORDER BY CASE WHEN employee_role = 'super_admin' THEN 0 WHEN employee_role = 'admin' THEN 1 ELSE 2 END, id
      LIMIT 1"
);
$employee = $statement ? $statement->fetch(PDO::FETCH_ASSOC) : false;
if (!is_array($employee)) {
    fwrite(STDERR, 'Employee fixture unavailable');
    exit(4);
}

parse_str($query, $params);
$_GET = array_map(static fn ($value) => is_scalar($value) ? (string) $value : '', $params);
$_POST = [];
$_REQUEST = $_GET;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/superopticnet/' . $page . ($query !== '' ? '?' . $query : '');
$_SERVER['SCRIPT_NAME'] = '/superopticnet/' . $page;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

app_session_boot();
$_SESSION['portal_user'] = app_db_user_session_payload($employee);
chdir(dirname(__DIR__));
include dirname(__DIR__) . DIRECTORY_SEPARATOR . $page;
