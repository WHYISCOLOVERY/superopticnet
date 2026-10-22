<?php
declare(strict_types=1);

/**
 * Read-only canonical workflow catalog for SuperOptic agents.
 *
 * This describes routing, evidence and approval gates. It is not an execution
 * grant and never exposes credentials, raw customer data or device output.
 */

require_once dirname(__DIR__, 2) . '/crew-service-auth-lib.php';

app_send_security_headers();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function agent_workflows_fail(int $status, string $reason): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    agent_workflows_fail(405, 'method_not_allowed');
}

$db = app_db();
if (!$db instanceof PDO) {
    agent_workflows_fail(503, 'database_unavailable');
}
$actor = app_crew_service_actor($db, app_crew_service_bearer());
if (!is_array($actor)) {
    agent_workflows_fail(401, 'auth_required');
}

$modules = function_exists('app_portal_employee_module_keys')
    ? array_values(array_unique(array_map('strval', app_portal_employee_module_keys($actor))))
    : [];
if (!in_array('ai', $modules, true) && !in_array('network', $modules, true)) {
    agent_workflows_fail(403, 'module_denied');
}

$path = dirname(__DIR__, 2) . '/config/agent_workflows.json';
if (!is_file($path)) {
    agent_workflows_fail(503, 'workflow_catalog_unavailable');
}
$catalog = json_decode((string) file_get_contents($path), true);
if (!is_array($catalog) || !is_array($catalog['workflows'] ?? null)) {
    agent_workflows_fail(503, 'workflow_catalog_invalid');
}

$requestedModule = strtolower(trim((string) ($_GET['module'] ?? '')));
$allowed = array_values(array_filter($catalog['workflows'], static function ($workflow) use ($modules, $requestedModule): bool {
    if (!is_array($workflow)) {
        return false;
    }
    $module = strtolower(trim((string) ($workflow['module'] ?? '')));
    if ($requestedModule !== '' && $module !== $requestedModule) {
        return false;
    }
    // AI service actors can read the catalog, but each row still carries its
    // owning module so the agent cannot treat another module's actions as
    // automatically authorized.
    return in_array('ai', $modules, true) || in_array($module, $modules, true);
}));

$safe = array_map(static function (array $workflow): array {
    return [
        'id' => (string) ($workflow['id'] ?? ''),
        'label' => (string) ($workflow['label'] ?? ''),
        'module' => (string) ($workflow['module'] ?? ''),
        'intent_phrases' => array_values(array_map('strval', $workflow['intent_phrases'] ?? [])),
        'stages' => array_values(array_map('strval', $workflow['stages'] ?? [])),
        'read_operations' => array_values(array_map('strval', $workflow['read_operations'] ?? [])),
        'propose_operations' => array_values(array_map('strval', $workflow['propose_operations'] ?? [])),
        'approval_required_operations' => array_values(array_map('strval', $workflow['approval_required_operations'] ?? [])),
        'evidence_required' => array_values(array_map('strval', $workflow['evidence_required'] ?? [])),
        'source_refs' => array_values(array_map('strval', $workflow['source_refs'] ?? [])),
    ];
}, $allowed);

echo json_encode([
    'ok' => true,
    'schema' => (int) ($catalog['schema'] ?? 1),
    'source' => (string) ($catalog['source'] ?? ''),
    'as' => (string) ($actor['login'] ?? ''),
    'modules' => $modules,
    'requested_module' => $requestedModule !== '' ? $requestedModule : null,
    'count' => count($safe),
    'workflows' => $safe,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
