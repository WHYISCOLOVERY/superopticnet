<?php
declare(strict_types=1);

/**
 * Read-only list of crew_proposals for the local AI dashboard.
 * GET only, service-token authenticated (same CREW_INGEST_TOKEN). Never writes.
 */

require_once dirname(__DIR__, 2) . '/config.php';

app_send_security_headers();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function crew_list_fail(int $code, string $reason): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    crew_list_fail(405, 'method_not_allowed');
}

$expected = getenv('CREW_INGEST_TOKEN');
$expected = is_string($expected) ? trim($expected) : '';
if ($expected === '' || strlen($expected) < 24) {
    crew_list_fail(503, 'ingest_disabled');
}
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth === '' && function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $hName => $hVal) {
        if (strcasecmp($hName, 'Authorization') === 0) { $auth = (string) $hVal; break; }
    }
}
$presented = preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1 ? trim($m[1]) : '';
if ($presented === '' || !hash_equals($expected, $presented)) {
    crew_list_fail(401, 'auth_required');
}

$db = app_db();
if (!$db instanceof PDO) {
    crew_list_fail(503, 'database_unavailable');
}

try {
    $rows = $db->query(
        "SELECT id, module, risk, status, source_agent, summary, reason, next_action, created_at
           FROM crew_proposals
          ORDER BY created_at DESC
          LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    crew_list_fail(500, 'query_failed');
}

echo json_encode(['ok' => true, 'count' => count($rows), 'items' => $rows],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
