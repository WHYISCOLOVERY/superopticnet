<?php
declare(strict_types=1);

/**
 * AI-crew read proxy — authenticate via department SERVICE TOKEN, act AS that
 * employee. Solves the 401: agents read real data scoped to the modules their
 * department account is granted (least privilege), attributed to that account.
 *
 * GET. Bearer <department service token>.
 *   ?resource=work-queue  → agent-ops queue rows the actor's modules allow
 * Read-only.
 */

require dirname(__DIR__, 2) . '/agent-ops-queue-lib.php';
require_once dirname(__DIR__, 2) . '/crew-service-auth-lib.php';

app_send_security_headers();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function asuser_fail(int $code, string $reason): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    asuser_fail(405, 'method_not_allowed');
}

$db = app_db();
if (!$db instanceof PDO) {
    asuser_fail(503, 'database_unavailable');
}

$actor = app_crew_service_actor($db, app_crew_service_bearer());
if ($actor === null) {
    asuser_fail(401, 'auth_required');
}

$resource = (string) ($_GET['resource'] ?? 'work-queue');

if ($resource === 'work-queue') {
    $filters = [
        'page' => (int) ($_GET['page'] ?? 1),
        'limit' => (int) ($_GET['limit'] ?? 50),
        'modules' => (string) ($_GET['modules'] ?? ''),
        'severity' => (string) ($_GET['severity'] ?? ''),
        'search' => (string) ($_GET['search'] ?? ''),
    ];
    try {
        $page = app_agent_ops_queue_page($db, $actor, $filters);
        $public = app_agent_ops_queue_public_page($page);
    } catch (Throwable $e) {
        asuser_fail(500, 'queue_unavailable');
    }
    echo json_encode([
        'ok' => true,
        'as' => (string) ($actor['login'] ?? ''),
        'modules' => app_portal_employee_module_keys($actor),
    ] + $public, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

asuser_fail(404, 'unknown_resource');
