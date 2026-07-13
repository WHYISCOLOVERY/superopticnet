<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function noc_modem_preview_fail(string $message, int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

$db = app_db();
if (!$db instanceof PDO) {
    noc_modem_preview_fail('Database unavailable.', 2);
}

$employee = $db->query(
    "SELECT *
     FROM portal_accounts
     WHERE role = 'employee' AND is_active = 1
     ORDER BY id ASC
     LIMIT 1"
)?->fetch(PDO::FETCH_ASSOC);
if (!is_array($employee)) {
    noc_modem_preview_fail('No active employee account.', 3);
}

$query = (string) ($db->query('SELECT username FROM billing_radius_services WHERE username <> "" ORDER BY id LIMIT 1')->fetchColumn() ?: '');
if ($query === '') {
    noc_modem_preview_fail('No radius username to search.', 4);
}

$_GET = ['ont_q' => $query];
$_POST = [];
$_REQUEST = $_GET;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/superopticnet/noc-devices.php?ont_q=' . rawurlencode($query);
$_SERVER['SCRIPT_NAME'] = '/superopticnet/noc-devices.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

app_session_boot();
$_SESSION['portal_user'] = app_db_user_session_payload($employee);

chdir(dirname(__DIR__));
ob_start();
include dirname(__DIR__) . '/noc-devices.php';
$html = (string) ob_get_clean();

foreach ([
    'data-modem-bot-preview',
    'MODEM AXTARIS NETICESI',
    'Live OLT query',
    'ONT RX / TX',
    'OLT RX / TX',
    'Temperatur',
] as $marker) {
    if (!str_contains($html, $marker)) {
        noc_modem_preview_fail('NOC modem bot preview missing marker: ' . $marker);
    }
}

echo json_encode([
    'status' => 'ok',
    'query' => $query,
    'checks' => [
        'modem_bot_preview' => true,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
