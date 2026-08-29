<?php
declare(strict_types=1);

/**
 * Read-only monitoring snapshot for the AI dashboard's continuous watch loop.
 * GET, service-token. Returns compact numeric health signals from existing
 * advisor read-models. Never writes.
 */

require_once dirname(__DIR__, 2) . '/advisor-agent-lib.php';

app_send_security_headers();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function mon_fail(int $code, string $reason): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    mon_fail(405, 'method_not_allowed');
}
$expected = getenv('CREW_INGEST_TOKEN');
$expected = is_string($expected) ? trim($expected) : '';
if ($expected === '' || strlen($expected) < 24) {
    mon_fail(503, 'ingest_disabled');
}
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth === '' && function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $hName => $hVal) {
        if (strcasecmp($hName, 'Authorization') === 0) { $auth = (string) $hVal; break; }
    }
}
$presented = preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1 ? trim($m[1]) : '';
if ($presented === '' || !hash_equals($expected, $presented)) {
    mon_fail(401, 'auth_required');
}

$db = app_db();
if (!$db instanceof PDO) {
    mon_fail(503, 'database_unavailable');
}

$safe = static function (callable $fn) {
    try {
        $v = $fn();
        return $v === null ? null : (int) $v;
    } catch (Throwable) {
        return null;
    }
};

$signals = [
    'network' => [
        'open_tickets' => $safe(fn() => app_advisor_read_model_open_network_tickets($db)),
        'unassigned'   => $safe(fn() => app_advisor_read_model_unassigned_open_tickets($db)),
        'blocked'      => $safe(fn() => app_advisor_read_model_dept_blocked_tickets($db)),
        'stale'        => $safe(fn() => app_advisor_read_model_stale_open_tickets($db, 3)),
    ],
    'inventory' => [
        'critical_stock' => $safe(fn() => app_advisor_read_model_critical_stock_count($db)),
    ],
    'crm' => [
        'inactive_customers' => $safe(fn() => app_advisor_read_model_long_inactive_customer_count($db, 60)),
    ],
];

echo json_encode(['ok' => true, 'ts' => date('Y-m-d H:i:s'), 'signals' => $signals],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
