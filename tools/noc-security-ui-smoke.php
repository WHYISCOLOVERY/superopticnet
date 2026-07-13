<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function noc_security_ui_fail(string $message, int $code = 1): never
{
    echo json_encode(['status' => 'failed', 'message' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

$db = app_db();
if (!$db instanceof PDO) {
    noc_security_ui_fail('Database unavailable', 2);
}

$employee = $db->query(
    "SELECT *
     FROM portal_accounts
     WHERE role = 'employee' AND is_active = 1
     ORDER BY FIELD(account_type, 'admin', 'operator', 'technician'), id ASC
     LIMIT 1"
)?->fetch(PDO::FETCH_ASSOC);

if (!is_array($employee)) {
    noc_security_ui_fail('No active employee', 3);
}

$_GET = [];
$_POST = [];
$_REQUEST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/superopticnet/noc-security.php';
$_SERVER['SCRIPT_NAME'] = '/superopticnet/noc-security.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

app_session_boot();
$_SESSION['portal_user'] = app_db_user_session_payload($employee);

chdir(dirname(__DIR__));
ob_start();
include dirname(__DIR__) . '/noc-security.php';
$html = (string) ob_get_clean();

foreach ([
    'Security / abuse monitor',
    'NOC security engine',
    'Virus, botnet, top-talker ve abuse monitorinqi',
    'Aktiv risk kartlari',
    'Scan idarəsi',
    'Security event tarixçəsi',
    'security-anomaly',
] as $marker) {
    if (!str_contains($html, $marker)) {
        noc_security_ui_fail('missing marker: ' . $marker, 4);
    }
}

echo json_encode([
    'status' => 'ok',
    'html_bytes' => strlen($html),
    'has_security_nav' => str_contains($html, 'Security / abuse monitor'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
