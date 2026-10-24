<?php
declare(strict_types=1);

/**
 * CrewAI proposal ingest endpoint.
 *
 * POST only. Service-token authenticated (NOT a portal session). Accepts a
 * single crew proposal JSON body and persists it as status='proposed' via
 * app_crew_proposal_ingest(). It NEVER executes, approves, sends or mutates any
 * customer/router/finance/message record -- it only enqueues an advisory item
 * for a human to review in the existing agent-ops queue.
 *
 * Auth: Authorization: Bearer <CREW_INGEST_TOKEN>  (env var, constant-time compare).
 * If the token is unset on the server, the endpoint is hard-disabled (503).
 */

require dirname(__DIR__, 2) . '/crew-proposal-lib.php';

app_send_security_headers();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function crew_ingest_fail(int $code, string $reason): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    crew_ingest_fail(405, 'method_not_allowed');
}

// --- Service-token auth (constant time). Endpoint disabled if no token set. ---
$expected = getenv('CREW_INGEST_TOKEN');
$expected = is_string($expected) ? trim($expected) : '';
if ($expected === '' || strlen($expected) < 24) {
    crew_ingest_fail(503, 'ingest_disabled');
}
// Apache bəzən Authorization başlığını PHP-yə ötürmür; bir neçə mənbədən oxu.
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth === '' && function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    foreach ($headers as $hName => $hVal) {
        if (strcasecmp($hName, 'Authorization') === 0) {
            $auth = (string) $hVal;
            break;
        }
    }
}
$presented = preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1 ? trim($m[1]) : '';
if ($presented === '' || !hash_equals($expected, $presented)) {
    crew_ingest_fail(401, 'auth_required');
}

// --- Body (bounded) ---
$raw = file_get_contents('php://input', false, null, 0, 65536);
if (!is_string($raw) || $raw === '') {
    crew_ingest_fail(400, 'empty_body');
}
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    crew_ingest_fail(400, 'invalid_json');
}

$db = app_db();
if (!$db instanceof PDO) {
    crew_ingest_fail(503, 'database_unavailable');
}

$result = app_crew_proposal_ingest($db, $payload);
if (empty($result['ok'])) {
    crew_ingest_fail(422, (string) ($result['reason'] ?? 'ingest_failed'));
}

http_response_code(201);
echo json_encode([
    'ok' => true,
    'id' => (string) $result['id'],
    'status' => (string) $result['status'],
    'risk' => (string) $result['risk'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
