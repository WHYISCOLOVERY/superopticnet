<?php
declare(strict_types=1);

/**
 * Read-only Unified Agent Operations Work Queue endpoint.
 *
 * GET only. Never writes, queues, sends, approves or executes anything -
 * every row is a projection built by `app_agent_ops_queue_page()`
 * (agent-ops-queue-lib.php), which itself only reads through existing
 * canonical service/read-model functions. Module permission and per-row
 * object-scope filtering happen inside that function via
 * `app_portal_can_access_module()` / `app_agent_ops_queue_scope_gate()` /
 * `app_noc_filter_objects()` / `app_advisor_access_allowed()` - this file
 * does not duplicate or loosen them. Every access is routed through
 * `app_agent_ops_queue_access()`, which audits the access (fail-closed if the
 * audit write itself fails) and whose result is stripped of any server-side-
 * only field (`app_agent_ops_queue_public_page()`) before being encoded here.
 */

require dirname(__DIR__, 2) . '/agent-ops-queue-lib.php';

app_session_boot();
app_send_security_headers();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user = app_portal_current_user();
if ($user === null || ($user['role'] ?? '') !== 'employee') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'auth_required'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!app_portal_can_access_module($user, 'ai')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'reason' => 'module_permission_denied'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$db = app_db();
if (!$db instanceof PDO) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'reason' => 'database_unavailable'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!app_agent_ops_queue_enabled($db, $user)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'reason' => 'feature_disabled'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$filters = [
    'page' => (int) ($_GET['page'] ?? 1),
    'limit' => (int) ($_GET['limit'] ?? 50),
    'modules' => (string) ($_GET['modules'] ?? ''),
    'severity' => (string) ($_GET['severity'] ?? ''),
    'search' => (string) ($_GET['search'] ?? ''),
];

// Every access - successful or failed - is audited inside this call; if the
// audit ledger write itself fails, this fails closed (reason=audit_unavailable)
// and no queue data is returned.
$access = app_agent_ops_queue_access($db, $user, $filters);
if (!$access['ok']) {
    http_response_code($access['reason'] === 'audit_unavailable' ? 503 : 500);
    echo json_encode(['ok' => false, 'reason' => (string) ($access['reason'] ?? 'queue_unavailable')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Server-side-only fields (the signed advisor proposal envelope) never reach
// this response - stripped by app_agent_ops_queue_public_page().
$page = app_agent_ops_queue_public_page($access['page']);

echo json_encode(['ok' => true] + $page, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
