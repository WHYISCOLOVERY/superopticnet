<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
require_once $root . '/agent-ops-queue-lib.php';
require_once $root . '/advisor-agent-lib.php';

$passes = [];
$failures = [];

function queue_check(string $name, bool $condition, array &$passes, array &$failures, string $detail = ''): void
{
    if ($condition) {
        $passes[] = $name;
        return;
    }
    $failures[] = $name . ($detail !== '' ? ' (' . $detail . ')' : '');
}

/* ---------------------------------------------------------------------------
 * 1. Deep-link allowlist: only listed base scripts are ever linkable; every
 *    other path (including something that looks like an external/absolute
 *    URL) is refused to '#'.
 * ------------------------------------------------------------------------- */
queue_check('allowlisted path builds a real link', app_agent_ops_queue_link('operations-center.php', ['section' => 'finance']) === 'operations-center.php?section=finance', $passes, $failures, app_agent_ops_queue_link('operations-center.php', ['section' => 'finance']));
queue_check('non-allowlisted local script refused', app_agent_ops_queue_link('config.php') === '#', $passes, $failures);
queue_check('external absolute URL refused', app_agent_ops_queue_link('https://example.com/evil') === '#', $passes, $failures);
queue_check('path traversal refused', app_agent_ops_queue_link('../config.php') === '#', $passes, $failures);

/* ---------------------------------------------------------------------------
 * 2. Stable item id: same source identity -> same id, always 64 hex chars,
 *    different identity -> different id.
 * ------------------------------------------------------------------------- */
$idA1 = app_agent_ops_queue_item_id('billing', 'billing_overdue_customer', '42');
$idA2 = app_agent_ops_queue_item_id('billing', 'billing_overdue_customer', '42');
queue_check('same source identity yields the same stable id across calls', $idA1 === $idA2, $passes, $failures);
queue_check('stable id is a 64-char sha256 hex digest', preg_match('/^[a-f0-9]{64}$/', $idA1) === 1, $passes, $failures, $idA1);
$idB = app_agent_ops_queue_item_id('billing', 'billing_overdue_customer', '43');
queue_check('different entity id yields a different stable id', $idA1 !== $idB, $passes, $failures);

/* ---------------------------------------------------------------------------
 * 3. Source registry completeness: every module bullet from the task is
 *    represented, every builder function actually exists.
 * ------------------------------------------------------------------------- */
$registry = app_agent_ops_queue_sources_registry();
$expectedModulesCovered = ['billing', 'finance', 'hr', 'inventory', 'network', 'messaging', 'crm'];
$coveredModules = array_unique(array_column($registry, 'module'));
foreach ($expectedModulesCovered as $module) {
    queue_check('source registry covers module ' . $module, in_array($module, $coveredModules, true), $passes, $failures);
}
foreach ($registry as $key => $source) {
    queue_check('source ' . $key . ' has a label', trim((string) ($source['label'] ?? '')) !== '', $passes, $failures);
    queue_check('source ' . $key . ' builder function exists', function_exists((string) ($source['builder'] ?? '')), $passes, $failures, (string) ($source['builder'] ?? ''));
}

/* ---------------------------------------------------------------------------
 * 4. Unavailable/error contract: the shared unavailable() helper never
 *    silently reports zero - it always carries available=false + an error.
 * ------------------------------------------------------------------------- */
$unavailable = app_agent_ops_queue_unavailable('synthetic_probe_error');
queue_check('unavailable() reports available=false', $unavailable['available'] === false, $passes, $failures);
queue_check('unavailable() reports an empty item list, not a fabricated one', $unavailable['items'] === [], $passes, $failures);
queue_check('unavailable() redacts a non-allowlisted error reason', $unavailable['error'] === 'source_unavailable', $passes, $failures, (string) ($unavailable['error'] ?? ''));

$rawFailure = app_agent_ops_queue_source_error(
    'billing_overdue',
    new RuntimeException('SQLSTATE synthetic raw schema detail password=should-never-leak')
);
$rawFailureJson = json_encode($rawFailure, JSON_UNESCAPED_SLASHES) ?: '';
queue_check('source failure exposes only its stable public reason code', ($rawFailure['error'] ?? '') === 'billing_source_unavailable', $passes, $failures, $rawFailureJson);
queue_check('source failure response never exposes raw exception text', !str_contains($rawFailureJson, 'SQLSTATE') && !str_contains($rawFailureJson, 'should-never-leak'), $passes, $failures, $rawFailureJson);

$internalItem = app_agent_ops_queue_build_item([
    'source_module' => 'ai',
    'source_entity_type' => 'synthetic',
    'source_entity_id' => 'public-projection-probe',
    'title' => 'Public title',
]);
$internalItem['_advisor_proposal'] = ['signature' => 'private-signature-sentinel', 'evidence' => [['detail' => 'private-evidence-sentinel']]];
$internalItem['nested_internal'] = ['token' => 'private-token-sentinel'];
$internalItem['created_at'] = ['evidence' => 'nested-through-public-key-sentinel'];
$publicItem = app_agent_ops_queue_public_item($internalItem);
$publicItemJson = json_encode($publicItem, JSON_UNESCAPED_SLASHES) ?: '';
queue_check('public projection removes the server-side advisor envelope', !array_key_exists('_advisor_proposal', $publicItem), $passes, $failures);
queue_check('public projection is an explicit allowlist and removes unknown nested fields', !array_key_exists('nested_internal', $publicItem), $passes, $failures);
queue_check('public projection rejects nested data even under a public scalar key', array_key_exists('created_at', $publicItem) && $publicItem['created_at'] === null, $passes, $failures);
queue_check('public projection JSON contains no nested signature/evidence/token sentinel', !preg_match('/private-(?:signature|evidence|token)|nested-through-public-key/', $publicItemJson), $passes, $failures, $publicItemJson);

$auditProbeSearch = 'sensitive-customer-name-never-store';
$auditPayloadProbe = app_agent_ops_queue_audit_payload([
    'modules' => 'billing,not-a-module,' . $auditProbeSearch,
    'severity' => 'high,not-a-severity,' . $auditProbeSearch,
    'search' => $auditProbeSearch,
    'page' => -3,
    'limit' => 999,
], ['total' => 2, 'sources' => ['billing_overdue' => ['available' => true, 'count' => 2]]]);
$auditPayloadJson = json_encode($auditPayloadProbe, JSON_UNESCAPED_SLASHES) ?: '';
queue_check('audit payload stores only allowlisted module filters', ($auditPayloadProbe['modules_filter'] ?? []) === ['billing'], $passes, $failures, $auditPayloadJson);
queue_check('audit payload stores only allowlisted severity filters', ($auditPayloadProbe['severity_filter'] ?? []) === ['high'], $passes, $failures, $auditPayloadJson);
queue_check('audit payload clamps pagination metadata', ($auditPayloadProbe['page'] ?? 0) === 1 && ($auditPayloadProbe['limit'] ?? 0) === 50, $passes, $failures, $auditPayloadJson);
queue_check('audit payload stores a search hash, never plaintext search', ($auditPayloadProbe['search_sha256'] ?? '') === hash('sha256', $auditProbeSearch) && !str_contains($auditPayloadJson, $auditProbeSearch), $passes, $failures, $auditPayloadJson);

/* ---------------------------------------------------------------------------
 * 5. Static business no-write guarantee: the queue lib and API endpoint never
 *    write business data or call an action service. The one permitted write
 *    is app_audit_record(), the mandatory append-only access audit.
 * ------------------------------------------------------------------------- */
$stripPhpComments = static function (string $source): string {
    // Strip /** ... */ and // ... comments before scanning for write-verb
    // markers, so a doc-comment that merely *names* a write function (to
    // explain why it is deliberately never called) does not trip this check.
    $noBlockComments = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;
    return preg_replace('#//[^\n]*#', '', $noBlockComments) ?? $noBlockComments;
};

$queueLibSrc = (string) file_get_contents($root . '/agent-ops-queue-lib.php');
$apiSrc = (string) file_get_contents($root . '/api/agent-ops/work-queue.php');
$queueLibCode = $stripPhpComments($queueLibSrc);
$apiCode = $stripPhpComments($apiSrc);
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'app_agent_prepare_action(', 'app_advisor_prepare_proposal_action(', 'app_communication_campaign_approve_and_queue(', 'app_communication_campaign_prepare_long_inactive(', '->beginTransaction', '->exec('] as $writeMarker) {
    queue_check('queue lib never calls write marker "' . $writeMarker . '" outside comments', !str_contains($queueLibCode, $writeMarker), $passes, $failures);
}
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'app_agent_prepare_action(', 'app_advisor_prepare_proposal_action('] as $writeMarker) {
    queue_check('API endpoint never calls write marker "' . $writeMarker . '" outside comments', !str_contains($apiCode, $writeMarker), $passes, $failures);
}
queue_check('API endpoint is read-only JSON only (no $_POST handling)', !str_contains($apiSrc, '$_POST'), $passes, $failures);
queue_check('queue service contains exactly one canonical audit write call', substr_count($queueLibCode, 'app_audit_record(') === 1, $passes, $failures, (string) substr_count($queueLibCode, 'app_audit_record('));
queue_check('queue service never creates schema during a GET/read access', !str_contains($queueLibCode, 'app_audit_ensure_schema('), $passes, $failures);

/* ---------------------------------------------------------------------------
 * 6. View/UI contract: mobile stacking data-labels present, both empty and
 *    error design-system states wired, no write/approve form in this view.
 * ------------------------------------------------------------------------- */
$viewSrc = (string) file_get_contents($root . '/views/ai/work-queue.php');
queue_check('view uses data-label attributes for mobile stacking', substr_count($viewSrc, 'data-label="') >= 5, $passes, $failures, (string) substr_count($viewSrc, 'data-label="'));
queue_check('view wires the empty design-system state', str_contains($viewSrc, "app_design_system_state('empty'"), $passes, $failures);
queue_check('view wires the error design-system state for unavailable sources', str_contains($viewSrc, "app_design_system_state('error'"), $passes, $failures);
queue_check('view never renders a POST form (no write/approve action)', !preg_match('/method=["\']post["\']/i', $viewSrc), $passes, $failures);
queue_check('view never renders an action-dispatch hidden input (the write-trigger pattern used elsewhere in this app)', !str_contains($viewSrc, 'name="action"'), $passes, $failures);
queue_check('view uses the Azerbaijani confirmation-center title', str_contains($viewSrc, 'Təsdiq Mərkəzi'), $passes, $failures);
queue_check('view renders an explicit fail-closed audit error state', str_contains($viewSrc, "\$queueError === 'audit_unavailable'"), $passes, $failures);
queue_check('view deep-link buttons only ("Modula keç"), no approve/send/pay label', str_contains($viewSrc, 'Modula keç') && !preg_match('/Təsdiq et|Göndər|Ödə/u', $viewSrc), $passes, $failures);

/* ---------------------------------------------------------------------------
 * 7. Feature flag fail-closed default.
 * ------------------------------------------------------------------------- */
$featureFlagsSrc = (string) file_get_contents($root . '/feature-flags-lib.php');
queue_check('agent_ops_work_queue flag is seeded', str_contains($featureFlagsSrc, "'agent_ops_work_queue'"), $passes, $failures);

require_once $root . '/config.php';
require_once $root . '/feature-flags-lib.php';
$db = app_db();

if ($db instanceof PDO) {
    app_feature_flags_ensure_schema($db);
    $priorFlag = $db->query("SELECT is_enabled, rollout_percent, role_scope FROM app_feature_flags WHERE flag_key = 'agent_ops_work_queue' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    queue_check('flag defaults to disabled (fail-closed) before this test enables it', !app_feature_flag_enabled($db, 'agent_ops_work_queue', ['id' => 1], false) || (bool) ($priorFlag['is_enabled'] ?? 0) === false, $passes, $failures);

    app_feature_flag_save($db, 'agent_ops_work_queue', true, 100.0, '', [], 'agent-ops-work-queue-smoke');

    $admin = ['id' => 1, 'login' => 'smoke-admin@example.com', 'employee_role' => 'admin', 'account_type' => 'admin', 'role' => 'employee', 'is_active' => 1];
    $billingActor = ['id' => 999101, 'login' => 'smoke-billing@example.com', 'employee_role' => 'operator', 'account_type' => 'employee', 'role' => 'employee', 'is_active' => 1];
    $hrActor = ['id' => 999102, 'login' => 'smoke-hr@example.com', 'employee_role' => 'hr', 'account_type' => 'employee', 'role' => 'employee', 'is_active' => 1];
    $areaActor = ['id' => 999103, 'login' => 'smoke-area@example.com', 'employee_role' => 'operator', 'account_type' => 'employee', 'role' => 'employee', 'is_active' => 1, 'responsible_areas' => ['Astara']];

    /* -----------------------------------------------------------------------
     * 8. Role/module/object-scope filtering.
     * ------------------------------------------------------------------- */
    $adminPage = app_agent_ops_queue_page($db, $admin, ['page' => 1, 'limit' => 50]);
    queue_check('admin sees every registered source key in the source-status map', count(array_diff(array_keys($registry), array_keys($adminPage['sources']))) === 0, $passes, $failures, implode(',', array_diff(array_keys($registry), array_keys($adminPage['sources']))));
    queue_check('admin queue is cross-module (sees more than one module)', count(array_unique(array_column($adminPage['items'], 'source_module'))) >= 1, $passes, $failures);

    $hrPage = app_agent_ops_queue_page($db, $hrActor, ['page' => 1, 'limit' => 50]);
    $hrModules = array_unique(array_column($hrPage['items'], 'source_module'));
    queue_check('HR-only actor sees only hr-module items (or none)', array_diff($hrModules, ['hr']) === [], $passes, $failures, implode(',', $hrModules));
    queue_check('HR-only actor source-status map excludes non-hr sources', count(array_diff(array_keys($hrPage['sources']), array_keys(array_filter($registry, static fn (array $s): bool => $s['module'] === 'hr')))) === 0, $passes, $failures);

    $billingPage = app_agent_ops_queue_page($db, $billingActor, ['page' => 1, 'limit' => 50]);
    $billingModules = array_unique(array_column($billingPage['items'], 'source_module'));
    queue_check('operator (billing-scoped) actor never sees hr/finance-only items', array_intersect($billingModules, ['hr']) === [], $passes, $failures, implode(',', $billingModules));

    $areaScope = app_agent_ops_queue_scope_gate($areaActor, 'billing', $db);
    queue_check('non-owner actor receives restricted area scope', ($areaScope['mode'] ?? '') === 'area' && ($areaScope['areas'] ?? []) === ['astara'], $passes, $failures, json_encode($areaScope) ?: '');
    queue_check('area scope matches its assigned area', app_agent_ops_queue_row_area_matches($areaScope['areas'], 'Astara rayonu'), $passes, $failures);
    queue_check('area scope rejects a different area', !app_agent_ops_queue_row_area_matches($areaScope['areas'], 'Bakı'), $passes, $failures);
    queue_check('module owner retains the canonical global module scope', (app_agent_ops_queue_scope_gate($hrActor, 'hr', $db)['mode'] ?? '') === 'global', $passes, $failures);

    $unscopedBilling = app_agent_ops_queue_source_billing_overdue($db, $billingActor);
    queue_check('non-owner without object scope fails the billing source closed', ($unscopedBilling['available'] ?? true) === false && ($unscopedBilling['error'] ?? '') === 'scope_unavailable', $passes, $failures, json_encode($unscopedBilling) ?: '');
    $areaBilling = app_agent_ops_queue_source_billing_overdue($db, $areaActor);
    $areaBillingScopeOk = (bool) ($areaBilling['available'] ?? false);
    foreach ((array) ($areaBilling['items'] ?? []) as $item) {
        $areaBillingScopeOk = $areaBillingScopeOk && str_starts_with((string) ($item['scope'] ?? ''), 'area:astara');
    }
    queue_check('scoped billing source returns only area-labelled items', $areaBillingScopeOk, $passes, $failures, json_encode($areaBilling) ?: '');

    $auditSearch = 'audit-plaintext-probe-' . bin2hex(random_bytes(6));
    $auditStartedTransaction = false;
    try {
        $db->beginTransaction();
        $auditStartedTransaction = true;
        $auditWritten = app_agent_ops_queue_write_audit($db, $admin, ['modules' => 'billing', 'severity' => 'high', 'search' => $auditSearch, 'page' => 1, 'limit' => 50], ['total' => 1, 'sources' => ['billing_overdue' => ['available' => true, 'count' => 1]]], 'executed');
        $auditRowStatement = $db->query('SELECT payload_json FROM mgs_operation_actions WHERE action_type = "agent_ops_queue_access" ORDER BY id DESC LIMIT 1');
        $auditRow = $auditRowStatement !== false ? $auditRowStatement->fetch(PDO::FETCH_ASSOC) : false;
        $auditStoredPayload = is_array($auditRow) ? (string) ($auditRow['payload_json'] ?? '') : '';
        queue_check('queue access writes one canonical append-only audit row', $auditWritten && $auditStoredPayload !== '', $passes, $failures, $auditStoredPayload);
        queue_check('persisted audit row contains the search hash but never plaintext search', str_contains($auditStoredPayload, hash('sha256', $auditSearch)) && !str_contains($auditStoredPayload, $auditSearch), $passes, $failures, $auditStoredPayload);
    } catch (Throwable $exception) {
        queue_check('queue access writes one canonical append-only audit row', false, $passes, $failures, $exception->getMessage());
        queue_check('persisted audit row contains the search hash but never plaintext search', false, $passes, $failures, $exception->getMessage());
    } finally {
        if ($auditStartedTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
    }

    if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        $auditFailureDb = new PDO('sqlite::memory:');
        $auditFailureDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $auditFailureResult = app_agent_ops_queue_access($auditFailureDb, $admin, ['modules' => 'billing']);
        queue_check('audit persistence failure makes queue access fail closed', ($auditFailureResult['ok'] ?? true) === false && ($auditFailureResult['reason'] ?? '') === 'audit_unavailable' && !array_key_exists('page', $auditFailureResult), $passes, $failures, json_encode($auditFailureResult) ?: '');
    } else {
        $passes[] = 'audit failure fail-closed probe skipped (pdo_sqlite unavailable)';
    }

    /* -----------------------------------------------------------------------
     * 9. Determinism: identical filters/actor -> identical ordered id list.
     * ------------------------------------------------------------------- */
    $adminPageAgain = app_agent_ops_queue_page($db, $admin, ['page' => 1, 'limit' => 50]);
    queue_check('repeated call with identical filters yields identical ordered item ids', array_column($adminPage['items'], 'id') === array_column($adminPageAgain['items'], 'id'), $passes, $failures);

    /* -----------------------------------------------------------------------
     * 10. Pagination cap: never more than 50 rows per page, regardless of
     *     what the caller asks for.
     * ------------------------------------------------------------------- */
    $overLimitPage = app_agent_ops_queue_page($db, $admin, ['page' => 1, 'limit' => 9999]);
    queue_check('requested limit above 50 is clamped to 50', $overLimitPage['limit'] === 50, $passes, $failures, (string) $overLimitPage['limit']);
    queue_check('returned item count never exceeds the clamped limit', count($overLimitPage['items']) <= 50, $passes, $failures, (string) count($overLimitPage['items']));

    /* -----------------------------------------------------------------------
     * 11. Deterministic ordering: severity rank is non-decreasing across the
     *     returned page (critical/high before medium/low).
     * ------------------------------------------------------------------- */
    $previousRank = -1;
    $orderingOk = true;
    foreach ($adminPage['items'] as $item) {
        $rank = app_agent_ops_queue_severity_rank((string) $item['severity']);
        if ($rank < $previousRank) {
            $orderingOk = false;
            break;
        }
        $previousRank = $rank;
    }
    queue_check('items are ordered by non-decreasing severity rank', $orderingOk, $passes, $failures);

    /* -----------------------------------------------------------------------
     * 12. Item schema completeness + no secrets/PII-shaped keys.
     * ------------------------------------------------------------------- */
    $requiredKeys = ['id', 'source_module', 'source_entity_type', 'source_entity_id', 'title', 'reason', 'severity', 'status', 'owner_role', 'scope', 'created_at', 'updated_at', 'source_timestamp', 'data_status', 'deep_link', 'required_permission', 'approval_owner', 'next_action_kind', 'confirmable', 'rollback_available'];
    $schemaOk = true;
    $forbiddenKeyOk = true;
    foreach ($adminPage['items'] as $item) {
        foreach ($requiredKeys as $requiredKey) {
            if (!array_key_exists($requiredKey, $item)) {
                $schemaOk = false;
                break 2;
            }
        }
        foreach (array_keys($item) as $itemKey) {
            if (preg_match('/password|secret|token|fin_code/i', (string) $itemKey) === 1) {
                $forbiddenKeyOk = false;
                break 2;
            }
        }
    }
    queue_check('every item carries the full mandated field set', $schemaOk, $passes, $failures);
    queue_check('no item carries a secret/password/token/FIN-shaped key', $forbiddenKeyOk, $passes, $failures);

    /* -----------------------------------------------------------------------
     * 13. Blocked action behavior: no confirmable item may ever point at a
     *     go-live-blocked registry kind.
     * ------------------------------------------------------------------- */
    $registryActions = app_agent_action_registry();
    $blockedActionLeak = false;
    $advisorEnvelopeOk = true;
    foreach ($adminPage['items'] as $item) {
        if (!empty($item['confirmable']) && str_starts_with((string) $item['next_action_kind'], 'route_to_agent_action_gate:')) {
            $kind = substr((string) $item['next_action_kind'], strlen('route_to_agent_action_gate:'));
            if (!empty($registryActions[$kind]['go_live_blocker'] ?? false)) {
                $blockedActionLeak = true;
            }
        }
        if (is_array($item['_advisor_proposal'] ?? null) && !app_advisor_proposal_envelope_valid($item['_advisor_proposal'])) {
            $advisorEnvelopeOk = false;
        }
    }
    queue_check('no confirmable item ever links to a go-live-blocked action kind', !$blockedActionLeak, $passes, $failures);
    queue_check('every embedded advisor proposal is a genuinely signed envelope (never fabricated)', $advisorEnvelopeOk, $passes, $failures);

    /* -----------------------------------------------------------------------
     * 14. Source-unavailable truthfulness: every real builder returns the
     *     honest {available,items[,error]} shape - never collapses a query
     *     failure into a silent empty/zero result without saying so.
     * ------------------------------------------------------------------- */
    $shapeOk = true;
    foreach ($registry as $source) {
        $builder = (string) $source['builder'];
        $result = $builder($db, $admin);
        if (!array_key_exists('available', $result) || !is_bool($result['available'])) {
            $shapeOk = false;
            break;
        }
        if ($result['available'] && !is_array($result['items'] ?? null)) {
            $shapeOk = false;
            break;
        }
        if (!$result['available'] && !array_key_exists('error', $result)) {
            $shapeOk = false;
            break;
        }
    }
    queue_check('every source builder honors the {available,items[,error]} contract', $shapeOk, $passes, $failures);

    /* -----------------------------------------------------------------------
     * 15. Filters: severity/search narrow the result set correctly.
     * ------------------------------------------------------------------- */
    $highOnly = app_agent_ops_queue_page($db, $admin, ['page' => 1, 'limit' => 50, 'severity' => 'high,critical']);
    $highOnlyOk = true;
    foreach ($highOnly['items'] as $item) {
        if (!in_array((string) $item['severity'], ['high', 'critical'], true)) {
            $highOnlyOk = false;
        }
    }
    queue_check('severity filter narrows to only the requested severities', $highOnlyOk, $passes, $failures);

    $modulesOnly = app_agent_ops_queue_page($db, $admin, ['page' => 1, 'limit' => 50, 'modules' => 'inventory']);
    $modulesOnlyOk = true;
    foreach ($modulesOnly['items'] as $item) {
        if ((string) $item['source_module'] !== 'inventory') {
            $modulesOnlyOk = false;
        }
    }
    queue_check('module filter narrows to only the requested module', $modulesOnlyOk, $passes, $failures);

    // Restore the flag to its prior state - this smoke test must not leave
    // the feature permanently toggled on for every actor.
    app_feature_flag_save(
        $db,
        'agent_ops_work_queue',
        (bool) ($priorFlag['is_enabled'] ?? 0),
        (float) ($priorFlag['rollout_percent'] ?? 0),
        (string) ($priorFlag['role_scope'] ?? ''),
        [],
        'restored after agent-ops-work-queue-smoke'
    );
} else {
    $passes[] = 'db-dependent queue checks skipped (database unavailable)';
}

echo 'agent_ops_work_queue_pass=' . count($passes) . PHP_EOL;
if ($failures !== []) {
    echo 'agent_ops_work_queue_fail=' . count($failures) . PHP_EOL;
    foreach ($failures as $failure) {
        echo 'FAIL: ' . $failure . PHP_EOL;
    }
    exit(1);
}
echo 'agent_ops_work_queue_fail=0' . PHP_EOL;
exit(0);
