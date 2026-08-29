<?php
declare(strict_types=1);

/**
 * Unified Agent Operations Work Queue / Approval Center - foundation.
 *
 * Read-only projection layer. Every source function below reads through an
 * existing canonical service/read-model function (billing-module-lib.php,
 * finance-module-lib.php, hr-module-lib.php, inventory-module-lib.php,
 * db.php ticket helpers, communication-campaign-lib.php, noc-authorization-lib.php,
 * advisor-agent-lib.php) or, where no per-row function exists for the exact
 * predicate (network ticket rows, failed message rows), a minimal named
 * read-only adapter mirroring an already-established filter (see inline
 * comments). Nothing in this file writes business data, queues, sends,
 * approves or executes anything. The only write is the mandatory append-only
 * access audit performed after the read projection is built.
 *
 * Governance:
 *  - access to a module's queue rows requires the same
 *    `app_portal_can_access_module()` gate the rest of the app already uses;
 *    admin/super_admin see everything, non-admin actors only see modules
 *    they already hold;
 *  - within a module, non-admin/non-owner actors are additionally narrowed to
 *    an object scope in the service layer (not just the UI) - see
 *    "Role + object scope" below; NOC ticket rows keep using the existing
 *    `app_noc_filter_objects()` object-scope filter unchanged;
 *  - advisor-sourced items are included only via `app_advisor_all_briefings()`,
 *    which already enforces `app_advisor_access_allowed()` per advisor and
 *    never fabricates a new proposal envelope - the embedded `_advisor_proposal`
 *    is the exact signed envelope `app_advisor_prepare_proposal_action()`
 *    expects, and is stripped before any item reaches JSON/view output by
 *    `app_agent_ops_queue_public_item()` (see "Public projection" below).
 *
 * Error handling: no source builder ever returns a raw Throwable/SQL/schema
 * message. Every failure - query exception, upstream `{error:...}` page
 * result, or scope metadata that cannot be evaluated - collapses to one of
 * the stable public reason codes in `app_agent_ops_queue_source_reason_codes()`
 * (e.g. `billing_source_unavailable`, `scope_unavailable`). The original
 * exception message is sanitized (secrets/tokens redacted) and only ever
 * written to the server error log, never returned to the API or view.
 *
 * Audit: every call to `app_agent_ops_queue_access()` - the single entry
 * point the API endpoint and the AI module shell both use instead of calling
 * `app_agent_ops_queue_page()` directly - appends one bounded-metadata row to
 * the existing append-only audit ledger (`audit-log-lib.php`), whether the
 * access succeeded or failed. If the audit write itself fails, this fails
 * closed: no queue data is returned at all.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/agent-workspace-lib.php';
require_once __DIR__ . '/advisor-agent-lib.php';
require_once __DIR__ . '/billing-module-lib.php';
require_once __DIR__ . '/finance-module-lib.php';
require_once __DIR__ . '/hr-module-lib.php';
require_once __DIR__ . '/inventory-module-lib.php';
require_once __DIR__ . '/communication-campaign-lib.php';
require_once __DIR__ . '/noc-authorization-lib.php';
require_once __DIR__ . '/ticket-resolution-lib.php';
require_once __DIR__ . '/audit-log-lib.php';
require_once __DIR__ . '/crew-proposal-lib.php';

function app_agent_ops_queue_enabled(?PDO $db = null, array $actor = []): bool
{
    $db = $db ?? (function_exists('app_db') ? app_db() : null);
    if (!$db instanceof PDO || !function_exists('app_feature_flag_enabled')) {
        return false;
    }
    try {
        return app_feature_flag_enabled($db, 'agent_ops_work_queue', $actor, false);
    } catch (Throwable) {
        return false;
    }
}

/**
 * Base scripts a queue item's deep link may point at. Every link is built
 * through `app_agent_ops_queue_link()`, which refuses anything outside this
 * list - a queue row can never carry an arbitrary/external href.
 *
 * @return list<string>
 */
function app_agent_ops_queue_deep_link_allowlist(): array
{
    return [
        'billing-customer-profile.php',
        'operations-center.php',
        'crm.php',
        'message-center.php',
        'noc-devices.php',
    ];
}

function app_agent_ops_queue_link(string $path, array $query = []): string
{
    if (!in_array($path, app_agent_ops_queue_deep_link_allowlist(), true)) {
        return '#';
    }
    $query = array_filter($query, static fn ($value): bool => $value !== null && $value !== '');
    return $query === [] ? $path : $path . '?' . http_build_query($query);
}

function app_agent_ops_queue_item_id(string $module, string $entityType, string $entityId): string
{
    return hash('sha256', $module . '|' . $entityType . '|' . $entityId);
}

/**
 * Normalize one queue item. Every source builder returns items through this
 * function so the shape is identical regardless of source.
 *
 * @param array<string,mixed> $fields
 * @return array<string,mixed>
 */
function app_agent_ops_queue_build_item(array $fields): array
{
    $module = (string) ($fields['source_module'] ?? '');
    $entityType = (string) ($fields['source_entity_type'] ?? '');
    $entityId = (string) ($fields['source_entity_id'] ?? '');
    $severity = (string) ($fields['severity'] ?? 'low');
    $severity = in_array($severity, ['low', 'medium', 'high', 'critical'], true) ? $severity : 'low';
    $dataStatus = (string) ($fields['data_status'] ?? 'verified');
    $dataStatus = in_array($dataStatus, ['verified', 'unavailable'], true) ? $dataStatus : 'verified';
    $nextAction = (string) ($fields['next_action_kind'] ?? 'manual_review');
    $confirmable = (bool) ($fields['confirmable'] ?? str_starts_with($nextAction, 'route_to_agent_action_gate:'));

    return [
        'id' => (string) ($fields['id'] ?? app_agent_ops_queue_item_id($module, $entityType, $entityId)),
        'source_module' => $module,
        'source_entity_type' => $entityType,
        'source_entity_id' => $entityId,
        'title' => (string) ($fields['title'] ?? ''),
        'reason' => (string) ($fields['reason'] ?? ''),
        'severity' => $severity,
        'status' => (string) ($fields['status'] ?? ''),
        'owner_role' => (string) ($fields['owner_role'] ?? $module),
        'scope' => (string) ($fields['scope'] ?? 'company_wide'),
        'created_at' => $fields['created_at'] ?? null,
        'updated_at' => $fields['updated_at'] ?? null,
        'source_timestamp' => $fields['source_timestamp'] ?? ($fields['updated_at'] ?? $fields['created_at'] ?? null),
        'data_status' => $dataStatus,
        'deep_link' => (string) ($fields['deep_link'] ?? '#'),
        'required_permission' => (string) ($fields['required_permission'] ?? $module),
        'approval_owner' => (string) ($fields['approval_owner'] ?? ('module_owner:' . $module)),
        'next_action_kind' => $nextAction,
        'confirmable' => $confirmable,
        'rollback_available' => (bool) ($fields['rollback_available'] ?? false),
        // Only populated for advisor-linked items; carries the exact signed
        // envelope `app_advisor_prepare_proposal_action()` requires. Never
        // rebuilt/guessed for non-advisor rows. Server-side only - stripped by
        // `app_agent_ops_queue_public_item()` before any output layer sees it.
        '_advisor_proposal' => $fields['_advisor_proposal'] ?? null,
    ];
}

/** @return array{available:bool,items:list<array<string,mixed>>,error?:string} */
function app_agent_ops_queue_unavailable(string $error): array
{
    return ['available' => false, 'items' => [], 'error' => app_agent_ops_queue_public_reason($error)];
}

// --- Error sanitization (never a raw Throwable/SQL/schema message) --------

/**
 * One stable, allowlisted public reason code per registered source. Never a
 * raw exception message, SQL fragment or schema detail.
 *
 * @return array<string,string>
 */
function app_agent_ops_queue_source_reason_codes(): array
{
    return [
        'billing_overdue' => 'billing_source_unavailable',
        'finance_receivables' => 'finance_source_unavailable',
        'finance_payroll' => 'finance_payroll_source_unavailable',
        'hr_attendance' => 'hr_source_unavailable',
        'hr_documents' => 'hr_source_unavailable',
        'hr_performance' => 'hr_source_unavailable',
        'inventory_critical_stock' => 'inventory_source_unavailable',
        'inventory_pending_inspection' => 'inventory_source_unavailable',
        'noc_unresolved_tickets' => 'network_source_unavailable',
        'noc_verification_required' => 'network_source_unavailable',
        'messaging_campaign_approvals' => 'messaging_source_unavailable',
        'messaging_failed_deliveries' => 'messaging_source_unavailable',
        'crm_inactive_customers' => 'crm_source_unavailable',
        'advisor_proposals' => 'advisor_source_unavailable',
    ];
}

function app_agent_ops_queue_public_reason(string $reason): string
{
    $allowed = array_values(array_unique(array_merge(
        array_values(app_agent_ops_queue_source_reason_codes()),
        ['source_unavailable', 'scope_unavailable', 'builder_missing', 'queue_unavailable', 'audit_unavailable']
    )));
    return in_array($reason, $allowed, true) ? $reason : 'source_unavailable';
}

/**
 * Internal diagnostics only ever go here (sanitized, truncated, server log) -
 * never to the API response or the rendered view.
 */
function app_agent_ops_queue_log_internal_error(string $sourceKey, Throwable $exception): void
{
    $message = function_exists('app_audit_sanitize_string')
        ? app_audit_sanitize_string($exception->getMessage())
        : $exception->getMessage();
    error_log(sprintf('[agent_ops_queue] source=%s error=%s', $sourceKey, substr($message, 0, 500)));
}

/**
 * The only way a source builder may report failure. Always returns one of
 * the allowlisted reason codes above - never the exception's own message.
 *
 * @return array{available:bool,items:list<array<string,mixed>>,error:string}
 */
function app_agent_ops_queue_source_error(string $sourceKey, ?Throwable $exception = null): array
{
    if ($exception instanceof Throwable) {
        app_agent_ops_queue_log_internal_error($sourceKey, $exception);
    }
    return app_agent_ops_queue_unavailable(app_agent_ops_queue_source_reason_codes()[$sourceKey] ?? 'source_unavailable');
}

// --- Role + object scope (enforced in the service, not only the UI) -------

/**
 * Modules whose queue sources have a single canonical owner role that already
 * gets an unrestricted whole-module view on every other page for that module
 * today (mirrors `operations-permission-lib.php`'s own module-action
 * ownership: finance actions require `employee_role=finance`, hr actions
 * require `hr`, inventory writes require `warehouse`/`warehouse_manager`, NOC
 * actions require `noc`). Billing, messaging and CRM have no single owner
 * role in this codebase - several roles share whole-module read access to
 * them - so every non-admin actor for those modules is narrowed below
 * instead of getting a second free pass.
 *
 * @return array<string,list<string>>
 */
function app_agent_ops_queue_module_owner_roles(): array
{
    return [
        'finance' => ['finance'],
        'hr' => ['hr'],
        'inventory' => ['warehouse', 'warehouse_manager'],
        'network' => ['noc'],
    ];
}

function app_agent_ops_queue_actor_role(array $actor): string
{
    return function_exists('app_noc_actor_role')
        ? app_noc_actor_role($actor)
        : strtolower(trim((string) ($actor['employee_role'] ?? '')));
}

function app_agent_ops_queue_actor_is_module_owner(array $actor, string $module): bool
{
    $owners = app_agent_ops_queue_module_owner_roles()[$module] ?? [];
    return $owners !== [] && in_array(app_agent_ops_queue_actor_role($actor), $owners, true);
}

/**
 * Areas the actor may see rows for - the exact same
 * `portal_account_areas`/responsible-areas list `app_noc_scope_values()`
 * (noc-authorization-lib.php) already reads for NOC's own object-scope
 * filter. Reused as-is, not re-derived, so this queue never becomes a second/
 * parallel authorization backend.
 *
 * @return list<string>
 */
function app_agent_ops_queue_actor_areas(array $actor, ?PDO $db): array
{
    return function_exists('app_noc_scope_values') ? app_noc_scope_values($actor, $db)['areas'] : [];
}

/**
 * Central role+object-scope gate for every non-NOC, non-advisor source.
 * Admins and a module's explicit owner role keep the whole-module view every
 * other page for that module already grants (`mode=global`). Every other
 * non-admin actor is narrowed to their own area list (`mode=area`) - callers
 * must fail the source closed (`scope_unavailable`) when that actor has no
 * areas assigned, or when the source's rows carry no area/branch field to
 * narrow by at all. This never silently falls back to a company-wide row set.
 *
 * @return array{mode:string,areas:list<string>}
 */
function app_agent_ops_queue_scope_gate(array $actor, string $module, ?PDO $db): array
{
    $isAdmin = function_exists('app_portal_is_admin') && app_portal_is_admin($actor);
    if ($isAdmin || app_agent_ops_queue_actor_is_module_owner($actor, $module)) {
        return ['mode' => 'global', 'areas' => []];
    }
    return ['mode' => 'area', 'areas' => app_agent_ops_queue_actor_areas($actor, $db)];
}

function app_agent_ops_queue_scope_label(array $scope): string
{
    return $scope['mode'] === 'global' ? 'company_wide' : ('area:' . implode(',', $scope['areas']));
}

function app_agent_ops_queue_row_area_matches(array $actorAreas, string $rowArea): bool
{
    $rowArea = strtolower(trim($rowArea));
    if ($rowArea === '') {
        return false;
    }
    foreach ($actorAreas as $area) {
        if ($area !== '' && ($area === $rowArea || str_contains($rowArea, $area) || str_contains($area, $rowArea))) {
            return true;
        }
    }
    return false;
}

// --- Billing -----------------------------------------------------------

function app_agent_ops_queue_source_billing_overdue(PDO $db, array $actor): array
{
    $scope = app_agent_ops_queue_scope_gate($actor, 'billing', $db);
    if ($scope['mode'] === 'area' && $scope['areas'] === []) {
        return app_agent_ops_queue_unavailable('scope_unavailable');
    }

    try {
        $page = app_billing_balance_page($db, ['status' => 'debt', 'limit' => 50, 'page' => 1]);
    } catch (Throwable $exception) {
        return app_agent_ops_queue_source_error('billing_overdue', $exception);
    }
    if (isset($page['error'])) {
        return app_agent_ops_queue_source_error('billing_overdue');
    }
    $scopeLabel = app_agent_ops_queue_scope_label($scope);

    $items = [];
    foreach ((array) ($page['rows'] ?? []) as $row) {
        if ($scope['mode'] === 'area' && !app_agent_ops_queue_row_area_matches($scope['areas'], (string) ($row['area'] ?? ''))) {
            continue;
        }
        $debt = (float) ($row['debt_amount'] ?? 0);
        if ($debt <= 0) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        $items[] = app_agent_ops_queue_build_item([
            'source_module' => 'billing',
            'source_entity_type' => 'billing_overdue_customer',
            'source_entity_id' => $id,
            'title' => trim((string) ($row['full_name'] ?? '')) . ' (' . (string) ($row['subscriber_code'] ?? '') . ')',
            'reason' => sprintf('Odenilmemis balans: %.2f AZN', $debt),
            'severity' => $debt >= 500 ? 'high' : ($debt >= 100 ? 'medium' : 'low'),
            'status' => 'debt',
            'updated_at' => $row['expires_at'] ?? null,
            'scope' => $scopeLabel,
            'deep_link' => app_agent_ops_queue_link('billing-customer-profile.php', ['id' => $id]),
            'required_permission' => 'billing',
        ]);
    }
    return ['available' => true, 'items' => $items];
}

// --- Finance -------------------------------------------------------------

function app_agent_ops_queue_source_finance_receivables(PDO $db, array $actor): array
{
    // finance_debt_ledger carries no area/branch column, so a non-owner actor
    // can never be narrowed - fail closed rather than show company-wide debt.
    // In practice only the `finance` owner role (and admin) hold the `finance`
    // module today, so this only ever bites a role map that later grants
    // `finance` module access to someone else.
    if (app_agent_ops_queue_scope_gate($actor, 'finance', $db)['mode'] === 'area') {
        return app_agent_ops_queue_unavailable('scope_unavailable');
    }

    try {
        $page = app_finance_debt_page($db, ['ledger_type' => 'receivable', 'status' => 'open', 'limit' => 50, 'page' => 1]);
    } catch (Throwable $exception) {
        return app_agent_ops_queue_source_error('finance_receivables', $exception);
    }
    if (isset($page['error'])) {
        return app_agent_ops_queue_source_error('finance_receivables');
    }

    $items = [];
    foreach ((array) ($page['rows'] ?? []) as $row) {
        $amountOpen = (float) ($row['amount_open'] ?? 0);
        $id = (string) ($row['id'] ?? '');
        $items[] = app_agent_ops_queue_build_item([
            'source_module' => 'finance',
            'source_entity_type' => 'finance_overdue_receivable',
            'source_entity_id' => $id,
            'title' => (string) ($row['counterparty_name'] ?? 'Naməlum kontragent'),
            'reason' => sprintf('Açıq alacaq: %.2f AZN (sened %s)', $amountOpen, (string) ($row['document_no'] ?? '')),
            'severity' => $amountOpen >= 1000 ? 'high' : ($amountOpen >= 200 ? 'medium' : 'low'),
            'status' => (string) ($row['status'] ?? 'open'),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'source_timestamp' => $row['due_date'] ?? ($row['updated_at'] ?? null),
            'deep_link' => app_agent_ops_queue_link('operations-center.php', ['section' => 'finance', 'finance_tab' => 'companies', 'search' => (string) ($row['counterparty_name'] ?? '')]),
            'required_permission' => 'finance',
        ]);
    }
    return ['available' => true, 'items' => $items];
}

function app_agent_ops_queue_source_finance_payroll(PDO $db, array $actor): array
{
    // hr_payroll_records exposes `employee_account_id` (the payroll subject),
    // not an area/branch the requesting actor can be scoped by - fail closed
    // for any non-owner rather than resolve it into a second scope lookup.
    if (app_agent_ops_queue_scope_gate($actor, 'finance', $db)['mode'] === 'area') {
        return app_agent_ops_queue_unavailable('scope_unavailable');
    }

    try {
        $page = app_hr_payroll_page($db, ['status' => 'pending_approval', 'limit' => 50, 'page' => 1]);
    } catch (Throwable $exception) {
        return app_agent_ops_queue_source_error('finance_payroll', $exception);
    }
    if (isset($page['error'])) {
        return app_agent_ops_queue_source_error('finance_payroll');
    }

    $items = [];
    foreach ((array) ($page['rows'] ?? []) as $row) {
        $id = (string) ($row['id'] ?? '');
        $items[] = app_agent_ops_queue_build_item([
            'source_module' => 'finance',
            'source_entity_type' => 'finance_payroll_readiness',
            'source_entity_id' => $id,
            'title' => (string) ($row['employee_name'] ?? 'Naməlum əməkdaş') . ' - ' . (string) ($row['period_month'] ?? ''),
            'reason' => 'Maaş qeydi təsdiq gözləyir (pending_approval).',
            'severity' => 'medium',
            'status' => 'pending_approval',
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'deep_link' => app_agent_ops_queue_link('operations-center.php', ['section' => 'finance', 'finance_tab' => 'payroll']),
            'required_permission' => 'finance',
        ]);
    }
    return ['available' => true, 'items' => $items];
}

// --- HR --------------------------------------------------------------------

function app_agent_ops_queue_source_hr_attendance(PDO $db, array $actor): array
{
    $scope = app_agent_ops_queue_scope_gate($actor, 'hr', $db);
    if ($scope['mode'] === 'area' && $scope['areas'] === []) {
        return app_agent_ops_queue_unavailable('scope_unavailable');
    }

    try {
        $page = app_hr_attendance_page($db, ['limit' => 50, 'page' => 1]);
    } catch (Throwable $exception) {
        return app_agent_ops_queue_source_error('hr_attendance', $exception);
    }
    if (isset($page['error'])) {
        return app_agent_ops_queue_source_error('hr_attendance');
    }
    $scopeLabel = app_agent_ops_queue_scope_label($scope);

    $items = [];
    foreach ((array) ($page['rows'] ?? []) as $row) {
        if ($scope['mode'] === 'area' && !app_agent_ops_queue_row_area_matches($scope['areas'], (string) ($row['area'] ?? ''))) {
            continue;
        }
        $status = (string) ($row['attendance_status'] ?? '');
        if (!in_array($status, ['absent', 'late'], true)) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        $items[] = app_agent_ops_queue_build_item([
            'source_module' => 'hr',
            'source_entity_type' => 'hr_attendance_exception',
            'source_entity_id' => $id,
            'title' => (string) ($row['full_name'] ?? 'Naməlum əməkdaş'),
            'reason' => $status === 'absent' ? 'Bu gün davamiyyət qeydi: qayıb.' : 'Bu gün davamiyyət qeydi: gecikmə.',
            'severity' => $status === 'absent' ? 'high' : 'medium',
            'status' => $status,
            'updated_at' => $row['check_in_at'] ?? null,
            'scope' => $scopeLabel,
            'deep_link' => app_agent_ops_queue_link('operations-center.php', ['section' => 'hr', 'hr_tab' => 'attendance']),
            'required_permission' => 'hr',
        ]);
    }
    return ['available' => true, 'items' => $items];
}

function app_agent_ops_queue_source_hr_documents(PDO $db, array $actor): array
{
    // Completeness rows carry no area/branch field - fail closed for any
    // non-owner rather than show company-wide document gaps.
    if (app_agent_ops_queue_scope_gate($actor, 'hr', $db)['mode'] === 'area') {
        return app_agent_ops_queue_unavailable('scope_unavailable');
    }

    try {
        $page = app_hr_document_page($db, ['document_view' => 'completeness', 'limit' => 50, 'page' => 1]);
    } catch (Throwable $exception) {
        return app_agent_ops_queue_source_error('hr_documents', $exception);
    }
    if (isset($page['error'])) {
        return app_agent_ops_queue_source_error('hr_documents');
    }

    $items = [];
    foreach ((array) ($page['rows'] ?? []) as $row) {
        $missing = (int) ($row['missing_count'] ?? 0);
        if ($missing <= 0) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        $items[] = app_agent_ops_queue_build_item([
            'source_module' => 'hr',
            'source_entity_type' => 'hr_document_exception',
            'source_entity_id' => $id,
            'title' => (string) ($row['employee_name'] ?? 'Naməlum əməkdaş'),
            'reason' => sprintf('%d tələb olunan sənəd əskikdir/etibarsızdır.', $missing),
            'severity' => $missing >= 3 ? 'high' : 'medium',
            'status' => 'incomplete_documents',
            'deep_link' => app_agent_ops_queue_link('operations-center.php', ['section' => 'hr', 'hr_tab' => 'documents']),
            'required_permission' => 'hr',
        ]);
    }
    return ['available' => true, 'items' => $items];
}

function app_agent_ops_queue_source_hr_performance(PDO $db, array $actor): array
{
    // Score rows carry no area/branch field - fail closed for any non-owner.
    if (app_agent_ops_queue_scope_gate($actor, 'hr', $db)['mode'] === 'area') {
        return app_agent_ops_queue_unavailable('scope_unavailable');
    }

    try {
        $page = app_hr_score_page($db, ['limit' => 50, 'page' => 1]);
    } catch (Throwable $exception) {
        return app_agent_ops_queue_source_error('hr_performance', $exception);
    }
    if (isset($page['error'])) {
        return app_agent_ops_queue_source_error('hr_performance');
    }

    $items = [];
    foreach ((array) ($page['rows'] ?? []) as $row) {
        $score = (int) ($row['score'] ?? 100);
        if ($score > 60) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        $items[] = app_agent_ops_queue_build_item([
            'source_module' => 'hr',
            'source_entity_type' => 'hr_performance_exception',
            'source_entity_id' => $id,
            'title' => (string) ($row['full_name'] ?? 'Naməlum əməkdaş'),
            'reason' => sprintf('Sicil balı aşağıdır: %d/100.', $score),
            'severity' => $score <= 40 ? 'high' : 'medium',
            'status' => 'low_score',
            'deep_link' => app_agent_ops_queue_link('operations-center.php', ['section' => 'hr', 'hr_tab' => 'performance']),
            'required_permission' => 'hr',
        ]);
    }
    return ['available' => true, 'items' => $items];
}

// --- Inventory ---------------------------------------------------------

function app_agent_ops_queue_source_inventory_critical_stock(PDO $db, array $actor): array
{
    // Catalog rows carry no area/branch field (stock items are a shared
    // catalog, not per-branch) - fail closed for any non-owner (e.g.
    // technician/engineer, who hold the `inventory` module but are not
    // `warehouse`/`warehouse_manager`) rather than show company-wide stock.
    if (app_agent_ops_queue_scope_gate($actor, 'inventory', $db)['mode'] === 'area') {
        return app_agent_ops_queue_unavailable('scope_unavailable');
    }

    try {
        $page = app_inventory_catalog_page($db, ['critical' => 1, 'status' => 'active', 'limit' => 50, 'page' => 1]);
    } catch (Throwable $exception) {
        return app_agent_ops_queue_source_error('inventory_critical_stock', $exception);
    }
    if (isset($page['error'])) {
        return app_agent_ops_queue_source_error('inventory_critical_stock');
    }

    $items = [];
    foreach ((array) ($page['rows'] ?? []) as $row) {
        $onHand = (float) ($row['quantity_on_hand'] ?? 0);
        $minimum = (float) ($row['minimum_quantity'] ?? 0);
        $id = (string) ($row['id'] ?? '');
        $items[] = app_agent_ops_queue_build_item([
            'source_module' => 'inventory',
            'source_entity_type' => 'inventory_critical_stock',
            'source_entity_id' => $id,
            'title' => (string) ($row['item_name'] ?? (string) ($row['sku'] ?? '')),
            'reason' => sprintf('Anbar qalığı: %s, minimum: %s', (string) ($row['quantity_on_hand'] ?? '0'), (string) ($row['minimum_quantity'] ?? '0')),
            'severity' => $onHand <= 0 ? 'critical' : ($onHand <= $minimum / 2 ? 'high' : 'medium'),
            'status' => 'critical_stock',
            'updated_at' => $row['updated_at'] ?? null,
            'deep_link' => app_agent_ops_queue_link('operations-center.php', ['section' => 'inventory', 'inventory_tab' => 'catalog']),
            'required_permission' => 'inventory',
        ]);
    }
    return ['available' => true, 'items' => $items];
}

function app_agent_ops_queue_source_inventory_pending_inspection(PDO $db, array $actor): array
{
    // Return rows carry no area/branch field - fail closed for any non-owner.
    if (app_agent_ops_queue_scope_gate($actor, 'inventory', $db)['mode'] === 'area') {
        return app_agent_ops_queue_unavailable('scope_unavailable');
    }

    try {
        $page = app_inventory_return_page($db, ['status' => 'received', 'limit' => 50, 'page' => 1]);
    } catch (Throwable $exception) {
        return app_agent_ops_queue_source_error('inventory_pending_inspection', $exception);
    }
    if (isset($page['error'])) {
        return app_agent_ops_queue_source_error('inventory_pending_inspection');
    }

    $items = [];
    foreach ((array) ($page['rows'] ?? []) as $row) {
        $id = (string) ($row['id'] ?? '');
        $createdAt = (string) ($row['created_at'] ?? '');
        $ageDays = $createdAt !== '' ? max(0, (int) floor((time() - strtotime($createdAt)) / 86400)) : 0;
        $items[] = app_agent_ops_queue_build_item([
            'source_module' => 'inventory',
            'source_entity_type' => 'inventory_pending_inspection',
            'source_entity_id' => $id,
            'title' => (string) ($row['asset_code'] ?? ('Qaytarma #' . $id)),
            'reason' => sprintf('Qeydiyyat: %s (%d gündür yoxlanılmır).', (string) ($row['return_ticket_no'] ?? ''), $ageDays),
            'severity' => $ageDays >= 3 ? 'high' : 'medium',
            'status' => 'received',
            'created_at' => $row['created_at'] ?? null,
            'deep_link' => app_agent_ops_queue_link('operations-center.php', ['section' => 'inventory', 'inventory_tab' => 'returns-inspection']),
            'required_permission' => 'inventory',
        ]);
    }
    return ['available' => true, 'items' => $items];
}

// --- NOC / network tickets -----------------------------------------------

/**
 * Closed-like ticket statuses. Mirrors the same set
 * `app_advisor_read_model_open_network_tickets()` (advisor-agent-lib.php)
 * already treats as "resolved" - kept identical rather than re-derived so
 * the two views of "open tickets" never silently disagree.
 *
 * @return list<string>
 */
function app_agent_ops_queue_ticket_closed_statuses(): array
{
    return ['Baglandi', 'closed', 'resolved'];
}

function app_agent_ops_queue_source_noc_unresolved_tickets(PDO $db, array $actor): array
{
    try {
        // 500 is app_db_ticket_records()'s own hard cap (db.php) - still one
        // bounded query, just using the function's full headroom instead of
        // an arbitrary smaller number that could silently under-report once
        // open-ticket volume grows past it.
        $rows = app_db_ticket_records(['limit' => 500]);
    } catch (Throwable $exception) {
        return app_agent_ops_queue_source_error('noc_unresolved_tickets', $exception);
    }

    $closed = app_agent_ops_queue_ticket_closed_statuses();
    $open = array_values(array_filter($rows, static fn (array $row): bool => !in_array((string) ($row['status'] ?? ''), $closed, true)));
    // Object-scope narrowing for non-admin/non-noc actors - unchanged, still
    // the canonical `app_noc_filter_objects()` gate NOC itself uses.
    $open = app_noc_filter_objects($open, $actor, $db);

    $items = [];
    foreach (array_slice($open, 0, 50) as $row) {
        $ticketNo = (string) ($row['id'] ?? '');
        $openedAt = (string) ($row['opened_at'] ?? '');
        $ageDays = $openedAt !== '' ? max(0, (int) floor((time() - strtotime($openedAt)) / 86400)) : 0;
        $items[] = app_agent_ops_queue_build_item([
            'source_module' => 'network',
            'source_entity_type' => 'noc_unresolved_ticket',
            'source_entity_id' => $ticketNo,
            'title' => (string) ($row['subject'] ?? 'Bilet') . ' - ' . $ticketNo,
            'reason' => sprintf('Status: %s, açıqdır: %d gün.', (string) ($row['status'] ?? ''), $ageDays),
            'severity' => $ageDays >= 3 ? 'high' : ($ageDays >= 1 ? 'medium' : 'low'),
            'status' => (string) ($row['status'] ?? ''),
            'created_at' => $row['opened_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'deep_link' => app_agent_ops_queue_link('crm.php', ['q' => $ticketNo]),
            'required_permission' => 'network',
        ]);
    }
    return ['available' => true, 'items' => $items];
}

function app_agent_ops_queue_source_noc_verification_required(PDO $db, array $actor): array
{
    try {
        $rows = app_db_ticket_records(['limit' => 500]);
    } catch (Throwable $exception) {
        return app_agent_ops_queue_source_error('noc_verification_required', $exception);
    }

    $flagged = ['verification_processing', 'manual_review_required', 'verification_failed'];
    $pending = array_values(array_filter($rows, static fn (array $row): bool => in_array((string) ($row['resolution_verification_status'] ?? 'none'), $flagged, true)));
    $pending = app_noc_filter_objects($pending, $actor, $db);

    $items = [];
    foreach (array_slice($pending, 0, 50) as $row) {
        $ticketNo = (string) ($row['id'] ?? '');
        $verificationStatus = (string) ($row['resolution_verification_status'] ?? 'none');
        $items[] = app_agent_ops_queue_build_item([
            'source_module' => 'network',
            'source_entity_type' => 'noc_verification_required_closure',
            'source_entity_id' => $ticketNo,
            'title' => (string) ($row['subject'] ?? 'Bilet') . ' - ' . $ticketNo,
            'reason' => 'Baglanma tesdiqi: ' . $verificationStatus . ' (ONT/RADIUS yoxlaması tələb olunur).',
            'severity' => $verificationStatus === 'verification_processing' ? 'medium' : 'high',
            'status' => $verificationStatus,
            'created_at' => $row['close_requested_at'] ?? null,
            'updated_at' => $row['verified_at'] ?? null,
            'deep_link' => app_agent_ops_queue_link('crm.php', ['q' => $ticketNo]),
            'required_permission' => 'network',
        ]);
    }
    return ['available' => true, 'items' => $items];
}

// --- Message Center ------------------------------------------------------

function app_agent_ops_queue_source_messaging_campaign_approvals(PDO $db, array $actor): array
{
    // No area/branch field on campaign rows and no single messaging owner
    // role - fail closed for any non-admin actor.
    if (app_agent_ops_queue_scope_gate($actor, 'messaging', $db)['mode'] === 'area') {
        return app_agent_ops_queue_unavailable('scope_unavailable');
    }

    try {
        $campaigns = app_communication_campaign_recent($db, 50);
    } catch (Throwable $exception) {
        return app_agent_ops_queue_source_error('messaging_campaign_approvals', $exception);
    }

    $items = [];
    foreach ($campaigns as $row) {
        if ((string) ($row['approval_status'] ?? '') !== 'pending_approval') {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        $items[] = app_agent_ops_queue_build_item([
            'source_module' => 'messaging',
            'source_entity_type' => 'messaging_campaign_approval',
            'source_entity_id' => $id,
            'title' => (string) ($row['template_code'] ?? 'Kampaniya') . ' (' . (string) ($row['recipient_count'] ?? 0) . ' alıcı)',
            'reason' => 'Kampaniya ikinci admin təsdiqi gözləyir (özünütəsdiq qadağandır).',
            'severity' => 'medium',
            'status' => 'pending_approval',
            'created_at' => $row['created_at'] ?? null,
            'deep_link' => app_agent_ops_queue_link('message-center.php', ['view' => 'group']),
            'required_permission' => 'messaging',
        ]);
    }
    return ['available' => true, 'items' => $items];
}

/**
 * Failed communication_messages rows. `message_center_filtered_messages()`
 * (message-center.php) is the canonical row reader for this table, but that
 * file executes route-level side effects at top level (auth redirect, etc.)
 * on require - it cannot be safely required here. This mirrors only its
 * `status = 'failed'` predicate (message-center.php:139-152), a single
 * equality filter, not a re-derived business rule.
 */
function app_agent_ops_queue_source_messaging_failed_deliveries(PDO $db, array $actor): array
{
    // No area/branch field on message rows and no single messaging owner
    // role - fail closed for any non-admin actor.
    if (app_agent_ops_queue_scope_gate($actor, 'messaging', $db)['mode'] === 'area') {
        return app_agent_ops_queue_unavailable('scope_unavailable');
    }

    try {
        $statement = $db->prepare(
            'SELECT m.id, m.subscriber_code, m.channel, m.status, m.destination, m.created_at, a.full_name
               FROM communication_messages m
               LEFT JOIN portal_accounts a ON a.id = m.account_id
              WHERE m.status = "failed"
              ORDER BY m.created_at DESC, m.id DESC
              LIMIT 50'
        );
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $exception) {
        return app_agent_ops_queue_source_error('messaging_failed_deliveries', $exception);
    }

    $items = [];
    foreach ($rows as $row) {
        $id = (string) ($row['id'] ?? '');
        $items[] = app_agent_ops_queue_build_item([
            'source_module' => 'messaging',
            'source_entity_type' => 'messaging_failed_delivery',
            'source_entity_id' => $id,
            'title' => trim((string) ($row['full_name'] ?? '')) !== '' ? (string) $row['full_name'] : (string) ($row['subscriber_code'] ?? 'Naməlum alıcı'),
            'reason' => sprintf('%s kanalı ilə göndəriş uğursuz oldu.', (string) ($row['channel'] ?? '')),
            'severity' => 'medium',
            'status' => 'failed',
            'created_at' => $row['created_at'] ?? null,
            'deep_link' => app_agent_ops_queue_link('message-center.php', ['view' => 'history', 'message_status' => 'failed']),
            'required_permission' => 'messaging',
        ]);
    }
    return ['available' => true, 'items' => $items];
}

// --- CRM / marketing -------------------------------------------------------

function app_agent_ops_queue_source_crm_inactive_customers(PDO $db, array $actor): array
{
    $scope = app_agent_ops_queue_scope_gate($actor, 'crm', $db);
    if ($scope['mode'] === 'area' && $scope['areas'] === []) {
        return app_agent_ops_queue_unavailable('scope_unavailable');
    }

    $inactiveDays = 45;
    try {
        $candidates = app_communication_campaign_long_inactive_candidates($db, $inactiveDays, 50);
    } catch (Throwable $exception) {
        return app_agent_ops_queue_source_error('crm_inactive_customers', $exception);
    }
    $scopeLabel = app_agent_ops_queue_scope_label($scope);

    $items = [];
    foreach ($candidates as $row) {
        if ($scope['mode'] === 'area' && !app_agent_ops_queue_row_area_matches($scope['areas'], (string) ($row['area'] ?? ''))) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        $lastPaymentAt = trim((string) ($row['last_payment_at'] ?? ''));
        $days = $lastPaymentAt !== '' ? (int) floor((time() - strtotime($lastPaymentAt)) / 86400) : null;
        $items[] = app_agent_ops_queue_build_item([
            'source_module' => 'crm',
            'source_entity_type' => 'crm_inactive_customer',
            'source_entity_id' => $id,
            'title' => trim((string) ($row['full_name'] ?? '')) . ' (' . (string) ($row['subscriber_code'] ?? '') . ')',
            'reason' => $days !== null
                ? sprintf('%d gündür ödəniş yoxdur (churn riski). Heç vaxt avtomatik göndərilmir.', $days)
                : 'Heç bir ödəniş qeydi yoxdur (churn riski). Heç vaxt avtomatik göndərilmir.',
            'severity' => $days === null || $days >= 90 ? 'high' : ($days >= 60 ? 'medium' : 'low'),
            'status' => 'inactive',
            'source_timestamp' => $lastPaymentAt !== '' ? $lastPaymentAt : null,
            'scope' => $scopeLabel,
            // Never registry-linked (never auto-send); the real next step is
            // `app_communication_campaign_prepare_long_inactive()`, the exact
            // same next_action label the marketing advisor uses.
            'next_action_kind' => 'call:app_communication_campaign_prepare_long_inactive',
            'confirmable' => false,
            'deep_link' => app_agent_ops_queue_link('message-center.php', ['view' => 'group']),
            'required_permission' => 'crm',
        ]);
    }
    return ['available' => true, 'items' => $items];
}

// --- Advisor council proposals (finance/noc/techops/marketing) -----------

/**
 * Wraps the existing advisor briefings (finance, noc, techops, marketing) as
 * queue items. `ceo` is intentionally excluded: it only recomposes the other
 * four, so including it would duplicate every item under a second id. Each
 * item carries the exact signed proposal envelope
 * (`app_advisor_build_proposal()`) as `_advisor_proposal`, so - and only for
 * these items - `confirmable` may legitimately route through the existing
 * `app_advisor_prepare_proposal_action()` preview bridge; no envelope is
 * fabricated for any other source in this file. Access is governed entirely
 * by `app_advisor_all_briefings()` -> `app_advisor_access_allowed()` per
 * advisor (admin, or an actor holding every one of that advisor's
 * `read_modules`) - not by the area-scope gate above, which does not apply
 * to this source.
 */
function app_agent_ops_queue_source_advisor_proposals(PDO $db, array $actor): array
{
    try {
        $briefings = app_advisor_all_briefings($db, $actor);
    } catch (Throwable $exception) {
        return app_agent_ops_queue_source_error('advisor_proposals', $exception);
    }

    $moduleByAdvisor = ['finance' => 'finance', 'noc' => 'network', 'techops' => 'inventory', 'marketing' => 'messaging', 'crm' => 'crm'];
    $linkByAdvisor = [
        'finance' => app_agent_ops_queue_link('operations-center.php', ['section' => 'finance']),
        'noc' => app_agent_ops_queue_link('noc-devices.php', ['view' => 'monitor']),
        'techops' => app_agent_ops_queue_link('operations-center.php', ['section' => 'inventory']),
        'marketing' => app_agent_ops_queue_link('message-center.php', ['view' => 'group']),
        'crm' => app_agent_ops_queue_link('crm.php', []),
    ];

    $items = [];
    foreach ($briefings as $advisorKey => $briefing) {
        if (!array_key_exists($advisorKey, $moduleByAdvisor)) {
            continue;
        }
        $module = $moduleByAdvisor[$advisorKey];
        foreach ((array) ($briefing['proposals'] ?? []) as $proposal) {
            if (!is_array($proposal) || in_array((string) ($proposal['proposal_type'] ?? ''), ['data_unavailable', 'no_flagged_items'], true)) {
                continue;
            }
            $nextAction = (string) ($proposal['next_action'] ?? 'manual_review');
            $confirmable = str_starts_with($nextAction, 'route_to_agent_action_gate:');
            $reversible = false;
            if ($confirmable) {
                $kind = substr($nextAction, strlen('route_to_agent_action_gate:'));
                $registryEntry = app_agent_action_registry()[$kind] ?? [];
                $reversible = (bool) ($registryEntry['reversible'] ?? false);
            }
            $items[] = app_agent_ops_queue_build_item([
                'id' => (string) ($proposal['id'] ?? ''),
                'source_module' => $module,
                'source_entity_type' => 'advisor_proposal:' . (string) ($proposal['proposal_type'] ?? ''),
                'source_entity_id' => (string) ($proposal['id'] ?? ''),
                'title' => (string) ($proposal['summary'] ?? ''),
                'reason' => (string) (($proposal['evidence'][0]['detail'] ?? '')),
                'severity' => (string) ($proposal['risk'] ?? 'medium'),
                'status' => (string) ($proposal['status'] ?? 'proposed'),
                // Deliberately date-only, not the full `generated_at`
                // datetime: app_advisor_build_proposal() stamps `generated_at`
                // with a fresh app_db_now() on every single call, so two
                // proposals built milliseconds apart within the same request
                // (or two proposals from two different queue-page calls)
                // would tie-break inconsistently on wall-clock time and break
                // the deterministic-ordering contract. The proposal's own
                // `id` is already date-truncated for the same reason
                // (app_advisor_proposal_identity()) - this mirrors that.
                'created_at' => substr((string) ($proposal['generated_at'] ?? ''), 0, 10) ?: null,
                'updated_at' => substr((string) ($proposal['generated_at'] ?? ''), 0, 10) ?: null,
                'data_status' => (string) ($proposal['data_status'] ?? 'verified'),
                'deep_link' => $linkByAdvisor[$advisorKey] ?? '#',
                'required_permission' => (string) ($proposal['required_permission'] ?? $module),
                'approval_owner' => (string) ($proposal['approval_owner'] ?? 'super_admin'),
                'next_action_kind' => $nextAction,
                'confirmable' => $confirmable,
                'rollback_available' => $reversible,
                'owner_role' => $advisorKey . '_advisor',
                '_advisor_proposal' => $confirmable ? $proposal : null,
            ]);
        }
    }
    return ['available' => true, 'items' => $items];
}

/**
 * Registry of every queue source: key, label, owning module (for the
 * permission/object-scope gate) and the builder function name.
 *
 * @return array<string,array{label:string,module:string,builder:string}>
 */
function app_agent_ops_queue_sources_registry(): array
{
    return [
        'billing_overdue' => ['label' => 'Billing: gecikmiş ödənişlər', 'module' => 'billing', 'builder' => 'app_agent_ops_queue_source_billing_overdue'],
        'finance_receivables' => ['label' => 'Maliyyə: açıq alacaqlar', 'module' => 'finance', 'builder' => 'app_agent_ops_queue_source_finance_receivables'],
        'finance_payroll' => ['label' => 'Maliyyə: maaş hazırlığı', 'module' => 'finance', 'builder' => 'app_agent_ops_queue_source_finance_payroll'],
        'hr_attendance' => ['label' => 'HR: davamiyyət istisnaları', 'module' => 'hr', 'builder' => 'app_agent_ops_queue_source_hr_attendance'],
        'hr_documents' => ['label' => 'HR: sənəd istisnaları', 'module' => 'hr', 'builder' => 'app_agent_ops_queue_source_hr_documents'],
        'hr_performance' => ['label' => 'HR: performans istisnaları', 'module' => 'hr', 'builder' => 'app_agent_ops_queue_source_hr_performance'],
        'inventory_critical_stock' => ['label' => 'Anbar: kritik stok', 'module' => 'inventory', 'builder' => 'app_agent_ops_queue_source_inventory_critical_stock'],
        'inventory_pending_inspection' => ['label' => 'Anbar: yoxlama gözləyən qaytarmalar', 'module' => 'inventory', 'builder' => 'app_agent_ops_queue_source_inventory_pending_inspection'],
        'noc_unresolved_tickets' => ['label' => 'Şəbəkə: açıq biletlər', 'module' => 'network', 'builder' => 'app_agent_ops_queue_source_noc_unresolved_tickets'],
        'noc_verification_required' => ['label' => 'Şəbəkə: baglanma tesdiqi gözləyən biletlər', 'module' => 'network', 'builder' => 'app_agent_ops_queue_source_noc_verification_required'],
        'messaging_campaign_approvals' => ['label' => 'Mesaj Mərkəzi: kampaniya təsdiqi', 'module' => 'messaging', 'builder' => 'app_agent_ops_queue_source_messaging_campaign_approvals'],
        'messaging_failed_deliveries' => ['label' => 'Mesaj Mərkəzi: uğursuz göndərişlər', 'module' => 'messaging', 'builder' => 'app_agent_ops_queue_source_messaging_failed_deliveries'],
        'crm_inactive_customers' => ['label' => 'CRM: passiv müştəri təklifləri', 'module' => 'crm', 'builder' => 'app_agent_ops_queue_source_crm_inactive_customers'],
        'advisor_proposals' => ['label' => 'Məsləhətçi şurası təklifləri', 'module' => 'ai', 'builder' => 'app_agent_ops_queue_source_advisor_proposals'],
        'crew_proposals' => ['label' => 'AI Komanda təklifləri', 'module' => 'ai', 'builder' => 'app_agent_ops_queue_source_crew_proposals'],
    ];
}

function app_agent_ops_queue_severity_rank(string $severity): int
{
    return match ($severity) {
        'critical' => 0,
        'high' => 1,
        'medium' => 2,
        default => 3,
    };
}

function app_agent_ops_queue_pagination(array $filters): array
{
    $page = max(1, (int) ($filters['page'] ?? 1));
    $limit = max(1, min(50, (int) ($filters['limit'] ?? 50)));
    return ['page' => $page, 'limit' => $limit, 'offset' => ($page - 1) * $limit];
}

/**
 * Main aggregator: gates every source by module/advisor access, merges,
 * filters, sorts deterministically and paginates in PHP (each source is
 * already bounded to <=50 rows at the SQL layer, so the merge step is
 * bounded by source count x 50, never an unbounded scan). Role+object scope
 * narrowing happens inside each source builder (see above), not here and not
 * only in the view.
 *
 * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,limit:int,sources:array<string,array<string,mixed>>,generated_at:string}
 */
function app_agent_ops_queue_page(PDO $db, array $actor, array $filters = []): array
{
    $isAdmin = function_exists('app_portal_is_admin') && app_portal_is_admin($actor);
    $requestedModules = array_values(array_filter(array_map('trim', explode(',', (string) ($filters['modules'] ?? '')))));
    $requestedSeverity = array_values(array_filter(array_map('trim', explode(',', (string) ($filters['severity'] ?? '')))));
    $search = mb_strtolower(trim((string) ($filters['search'] ?? '')), 'UTF-8');

    $sourceStatus = [];
    $allItems = [];
    foreach (app_agent_ops_queue_sources_registry() as $key => $source) {
        $module = (string) $source['module'];
        if (!$isAdmin && (!function_exists('app_portal_can_access_module') || !app_portal_can_access_module($actor, $module))) {
            continue;
        }
        if ($requestedModules !== [] && !in_array($module, $requestedModules, true)) {
            continue;
        }
        $builder = (string) $source['builder'];
        if (!function_exists($builder)) {
            $sourceStatus[$key] = ['available' => false, 'count' => 0, 'error' => 'builder_missing'];
            continue;
        }
        $result = $builder($db, $actor);
        $available = (bool) ($result['available'] ?? false);
        $items = (array) ($result['items'] ?? []);
        $sourceStatus[$key] = [
            'available' => $available,
            'count' => count($items),
            'error' => $available ? null : app_agent_ops_queue_public_reason((string) ($result['error'] ?? 'source_unavailable')),
        ];
        foreach ($items as $item) {
            $allItems[] = $item;
        }
    }

    if ($requestedSeverity !== []) {
        $allItems = array_values(array_filter($allItems, static fn (array $item): bool => in_array((string) $item['severity'], $requestedSeverity, true)));
    }
    if ($search !== '') {
        $allItems = array_values(array_filter($allItems, static function (array $item) use ($search): bool {
            $haystack = mb_strtolower($item['title'] . ' ' . $item['reason'], 'UTF-8');
            return str_contains($haystack, $search);
        }));
    }

    usort($allItems, static function (array $a, array $b): int {
        $rank = app_agent_ops_queue_severity_rank((string) $a['severity']) <=> app_agent_ops_queue_severity_rank((string) $b['severity']);
        if ($rank !== 0) {
            return $rank;
        }
        $timeA = strtotime((string) ($a['source_timestamp'] ?? '')) ?: 0;
        $timeB = strtotime((string) ($b['source_timestamp'] ?? '')) ?: 0;
        if ($timeA !== $timeB) {
            return $timeB <=> $timeA;
        }
        return strcmp((string) $a['id'], (string) $b['id']);
    });

    $pagination = app_agent_ops_queue_pagination($filters);
    $total = count($allItems);
    $pages = max(1, (int) ceil($total / $pagination['limit']));
    $page = min($pagination['page'], $pages);
    $offset = ($page - 1) * $pagination['limit'];
    $slice = array_slice($allItems, $offset, $pagination['limit']);

    return [
        'items' => $slice,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'limit' => $pagination['limit'],
        'start' => $total > 0 ? $offset + 1 : 0,
        'end' => $total > 0 ? min($total, $offset + count($slice)) : 0,
        'sources' => $sourceStatus,
        'generated_at' => function_exists('app_db_now') ? app_db_now() : date('Y-m-d H:i:s'),
    ];
}

// --- Public projection (server-side-only fields never reach output) -------

/**
 * Strips every internal signed envelope/evidence field before an item ever
 * reaches JSON output or a rendered view. `_advisor_proposal` (and the
 * evidence/id/signature fields nested inside it) is a server-side-only
 * artifact `app_advisor_prepare_proposal_action()` needs internally - it must
 * never be serialized to the browser. Every other field on the item is
 * already the public, bounded shape `app_agent_ops_queue_build_item()`
 * produces.
 *
 * @param array<string,mixed> $item
 * @return array<string,mixed>
 */
function app_agent_ops_queue_public_item(array $item): array
{
    $publicKeys = [
        'id', 'source_module', 'source_entity_type', 'source_entity_id', 'title',
        'reason', 'severity', 'status', 'owner_role', 'scope', 'created_at',
        'updated_at', 'source_timestamp', 'data_status', 'deep_link',
        'required_permission', 'approval_owner', 'next_action_kind',
        'confirmable', 'rollback_available',
    ];
    $public = array_intersect_key($item, array_fill_keys($publicKeys, true));
    foreach ($public as $key => $value) {
        if (is_array($value) || is_object($value) || is_resource($value)) {
            $public[$key] = null;
        }
    }
    return $public;
}

/**
 * @param array<string,mixed> $page
 * @return array<string,mixed>
 */
function app_agent_ops_queue_public_page(array $page): array
{
    $page['items'] = array_map('app_agent_ops_queue_public_item', (array) ($page['items'] ?? []));
    return $page;
}

// --- Audit + access wrapper -------------------------------------------

/**
 * Bounded audit payload for one queue access - never plaintext search,
 * customer/employee names or source row content. Only counts, the filters as
 * requested (not the rows they matched), and a search hash.
 *
 * @param array<string,mixed> $filters
 * @param array<string,mixed>|null $page
 * @return array<string,mixed>
 */
function app_agent_ops_queue_audit_payload(array $filters, ?array $page): array
{
    $sourceStatus = [];
    foreach ((array) ($page['sources'] ?? []) as $key => $state) {
        $sourceStatus[(string) $key] = [
            'available' => (bool) ($state['available'] ?? false),
            'count' => (int) ($state['count'] ?? 0),
        ];
    }
    $search = trim((string) ($filters['search'] ?? ''));
    $allowedModules = array_values(array_unique(array_column(app_agent_ops_queue_sources_registry(), 'module')));
    $normalizeFilter = static function (mixed $value, array $allowed): array {
        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string) $value)),
            static fn (string $candidate): bool => in_array($candidate, $allowed, true)
        )));
    };
    $pagination = app_agent_ops_queue_pagination($filters);

    return [
        'modules_filter' => $normalizeFilter($filters['modules'] ?? '', $allowedModules),
        'severity_filter' => $normalizeFilter($filters['severity'] ?? '', ['critical', 'high', 'medium', 'low']),
        'page' => $pagination['page'],
        'limit' => $pagination['limit'],
        'result_count' => (int) ($page['total'] ?? 0),
        'search_sha256' => $search !== '' ? hash('sha256', mb_strtolower($search, 'UTF-8')) : null,
        'source_status' => $sourceStatus,
    ];
}

/**
 * Writes one append-only audit row for a queue access attempt. Returns false
 * (never throws) if the audit ledger itself is unavailable, so the caller can
 * fail closed instead of returning queue data with no audit trail.
 *
 * @param array<string,mixed> $filters
 * @param array<string,mixed>|null $page
 */
function app_agent_ops_queue_write_audit(PDO $db, array $actor, array $filters, ?array $page, string $status): bool
{
    if (!function_exists('app_audit_record')) {
        return false;
    }
    try {
        app_audit_record($db, [
            'module_key' => 'ai_agent',
            'operation_class' => 'agent_ops_queue',
            'action_type' => 'agent_ops_queue_access',
            'entity_type' => 'agent_ops_queue_page',
            'entity_id' => 'work_queue',
            'status' => $status,
            'actor_account_id' => app_audit_actor_id($actor),
            'actor_name' => app_audit_actor_name($actor),
            'summary' => 'Agent ops work queue access (' . $status . ')',
            'sensitivity' => 'normal',
            'payload' => app_agent_ops_queue_audit_payload($filters, $page),
        ]);
        return true;
    } catch (Throwable $exception) {
        app_agent_ops_queue_log_internal_error('audit', $exception);
        return false;
    }
}

/**
 * Single entry point every caller (API endpoint, AI module shell) must use
 * instead of calling `app_agent_ops_queue_page()` directly. Every access -
 * successful or failed - is appended to the audit ledger first; if that
 * write itself fails, this fails closed and no queue data is returned.
 *
 * @param array<string,mixed> $filters
 * @return array{ok:bool,reason?:string,page?:array<string,mixed>}
 */
function app_agent_ops_queue_access(PDO $db, array $actor, array $filters = []): array
{
    try {
        $page = app_agent_ops_queue_page($db, $actor, $filters);
    } catch (Throwable $exception) {
        app_agent_ops_queue_log_internal_error('page', $exception);
        if (!app_agent_ops_queue_write_audit($db, $actor, $filters, null, 'failed')) {
            return ['ok' => false, 'reason' => 'audit_unavailable'];
        }
        return ['ok' => false, 'reason' => 'queue_unavailable'];
    }

    if (!app_agent_ops_queue_write_audit($db, $actor, $filters, $page, 'executed')) {
        return ['ok' => false, 'reason' => 'audit_unavailable'];
    }

    return ['ok' => true, 'page' => $page];
}
