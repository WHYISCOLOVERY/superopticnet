<?php
declare(strict_types=1);

/**
 * Unified internal agent ecosystem - foundation (governed by Serdar/super-admin).
 *
 * This is a policy/read-only layer on top of the existing Stage 5 agent
 * workspace contract (`agent-workspace-lib.php`). It defines six advisor
 * profiles (CEO, Finance, NOC, Technical operations manager, Marketing, CRM)
 * as service identities - not employee login accounts, not passwords - that
 * read the existing service layer and produce structured proposals.
 *
 * Hard rules enforced here:
 *  - No new business backend: every briefing function reads through existing
 *    lib functions or simple read-only aggregate SQL (COUNT/SUM), never a
 *    write.
 *  - Every proposal carries evidence (source + observed_at), risk, the
 *    module permission required to act on it, an approval owner, and a next
 *    action.
 *  - Any next action that maps to a write goes through the existing
 *    `app_agent_action_registry()` / `app_agent_action_gate()` from
 *    `agent-workspace-lib.php` - this file never stages or executes a write
 *    itself.
 *  - Access to an advisor's briefing is gated by the actor already holding
 *    the module permission(s) that advisor reads, or being admin/super_admin.
 *    Serdar/super-admin is always allowed and is the default approval owner
 *    for any cross-module or high-risk proposal.
 */

require_once __DIR__ . '/business-rules-lib.php';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/agent-workspace-lib.php';
require_once __DIR__ . '/finance-module-lib.php';
require_once __DIR__ . '/inventory-module-lib.php';
require_once __DIR__ . '/communication-campaign-lib.php';
require_once __DIR__ . '/audit-log-lib.php';

/**
 * Canonical roster of advisor policy profiles.
 *
 * @return array<string,array<string,mixed>>
 */
function app_advisor_agent_roster(): array
{
    return [
        'ceo' => [
            'label' => 'CEO məsləhətçisi',
            'mission' => 'Cross-module icra baxışı: gəlir/xərc, şəbəkə sağlamlığı, texniki icra, marketinq riski.',
            'read_modules' => ['billing', 'finance', 'hr', 'inventory', 'network', 'statistics', 'crm'],
            'cross_module' => true,
        ],
        'finance' => [
            'label' => 'Maliyyə məsləhətçisi',
            'mission' => 'Nağd axın, alacaq/borc balansı, gecikmiş ödənişlər.',
            'read_modules' => ['finance', 'billing'],
            'cross_module' => false,
        ],
        'noc' => [
            'label' => 'Şəbəkə/NOC mühəndisi məsləhətçisi',
            'mission' => 'Açıq şəbəkə biletləri, ONT siqnal/temperatur və RADIUS doğrulama vəziyyəti.',
            'read_modules' => ['network'],
            'cross_module' => false,
        ],
        'techops' => [
            'label' => 'Texniki əməliyyatlar meneceri məsləhətçisi',
            'mission' => 'Texnik iş yükü, bilet həll sürəti, anbar hazırlığı.',
            'read_modules' => ['network', 'inventory', 'hr'],
            'cross_module' => true,
        ],
        'marketing' => [
            'label' => 'Marketinq məsləhətçisi',
            'mission' => 'Uzunmüddətli passiv müştəri seqmentləri və kampaniya təklifləri (təsdiqsiz göndərmə yoxdur).',
            'read_modules' => ['billing', 'messaging'],
            'cross_module' => false,
        ],
        'crm' => [
            'label' => 'CRM meneceri məsləhətçisi',
            'mission' => 'Bilet növbəsi, gecikmiş/köhnə biletlər, müştəri cavab gözləmə vəziyyəti.',
            'read_modules' => ['crm'],
            'cross_module' => false,
        ],
    ];
}

/**
 * Whether the actor may view a given advisor's briefing. Reuses the existing
 * canonical module-permission check; no new permission system is created.
 * Admin/super_admin always pass. A non-admin actor must hold every module the
 * advisor reads (cross-module advisors are therefore admin-only in practice,
 * because no operational role holds finance+hr+inventory+network+billing at
 * once in the current module map).
 */
function app_advisor_access_allowed(string $advisorKey, array $actor): bool
{
    $roster = app_advisor_agent_roster();
    if (!isset($roster[$advisorKey])) {
        return false;
    }
    if (function_exists('app_ai_user_is_admin') && app_ai_user_is_admin($actor)) {
        return true;
    }
    if (!function_exists('app_portal_can_access_module')) {
        return false;
    }
    foreach ((array) ($roster[$advisorKey]['read_modules'] ?? []) as $module) {
        if (!app_portal_can_access_module($actor, (string) $module)) {
            return false;
        }
    }
    return true;
}

/**
 * Build one structured proposal envelope (evidence, source timestamps, risk,
 * required permission, approval owner, next action). Every advisor briefing
 * function returns a list built exclusively through this helper so the
 * contract cannot drift per-domain.
 *
 * @param list<array{source:string,observed_at:string,detail:string}> $evidence
 */
function app_advisor_build_proposal(
    string $advisorKey,
    string $proposalType,
    string $summary,
    array $evidence,
    string $risk,
    ?string $linkedActionKind = null,
    string $dataStatus = 'verified'
): array {
    $roster = app_advisor_agent_roster();
    $advisor = $roster[$advisorKey] ?? ['label' => $advisorKey, 'read_modules' => [], 'cross_module' => true];
    $crossModule = !empty($advisor['cross_module']);

    $registry = function_exists('app_agent_action_registry') ? app_agent_action_registry() : [];
    $registryEntry = $linkedActionKind !== null ? ($registry[$linkedActionKind] ?? null) : null;

    if ($registryEntry !== null) {
        $requiredPermission = implode(',', app_agent_action_modules($registryEntry));
        $nextAction = !empty($registryEntry['go_live_blocker'])
            ? 'blocked_go_live_blocker:' . $linkedActionKind
            : 'route_to_agent_action_gate:' . $linkedActionKind;
    } elseif ($linkedActionKind !== null) {
        // Named next step that is not (yet) a Stage 5 registry action, e.g. a
        // campaign proposal handled by the campaign approve/queue contract.
        $requiredPermission = implode(',', (array) ($advisor['read_modules'] ?? []));
        $nextAction = $linkedActionKind;
    } else {
        $requiredPermission = implode(',', (array) ($advisor['read_modules'] ?? []));
        $nextAction = 'manual_review';
    }

    $risk = in_array($risk, ['low', 'medium', 'high', 'critical'], true) ? $risk : 'medium';
    $now = function_exists('app_db_now') ? app_db_now() : date('Y-m-d H:i:s');
    $reportingDate = substr($now, 0, 10);

    return [
        // Deterministic/idempotent: the same advisor + proposal type + evidence
        // content (source+detail, excluding the volatile observed_at moment) on
        // the same reporting date always yields the same id. No random_int: a
        // proposal identity must be reproducible across repeated council runs
        // for the same underlying data, so downstream approval/audit references
        // stay stable instead of minting a fresh id every time.
        'id' => app_advisor_proposal_identity([
            'advisor_key' => $advisorKey,
            'proposal_type' => $proposalType,
            'summary' => $summary,
            'evidence' => $evidence,
            'risk' => $risk,
            'data_status' => $dataStatus,
            'next_action' => $nextAction,
            'required_permission' => $requiredPermission,
            'approval_owner' => 'super_admin',
            'status' => 'proposed',
            'generated_at' => $reportingDate,
        ]),
        'advisor_key' => $advisorKey,
        'advisor_label' => (string) ($advisor['label'] ?? $advisorKey),
        'proposal_type' => $proposalType,
        'generated_at' => $now,
        'summary' => $summary,
        'evidence' => array_values($evidence),
        'data_status' => $dataStatus,
        'risk' => $risk,
        'cross_module' => $crossModule,
        'required_permission' => $requiredPermission,
        // Serdar/super-admin is the final approval authority for cross-module
        // or high/critical-risk proposals; kept as the single approval owner
        // for every write-capable proposal at this foundation stage since no
        // narrower delegated-approval hierarchy exists yet.
        'approval_owner' => ($crossModule || in_array($risk, ['high', 'critical'], true) || $linkedActionKind !== null)
            ? 'super_admin'
            : 'super_admin',
        'next_action' => $nextAction,
        'status' => 'proposed',
    ];
}

function app_advisor_evidence(string $source, string $detail, ?string $observedAt = null): array
{
    return [
        'source' => $source,
        'observed_at' => $observedAt ?? (function_exists('app_db_now') ? app_db_now() : date('Y-m-d H:i:s')),
        'detail' => $detail,
    ];
}

/**
 * Stable fingerprint of an evidence list for proposal-identity purposes.
 * Deliberately excludes `observed_at` (which changes on every call even when
 * the underlying data has not) so identity is driven only by source+detail.
 */
function app_advisor_evidence_signature(array $evidence): string
{
    $parts = [];
    foreach ($evidence as $item) {
        $parts[] = (string) ($item['source'] ?? '') . '=' . (string) ($item['detail'] ?? '');
    }
    return hash('sha256', implode('|', $parts));
}

/**
 * Canonical proposal identity. Action routing and governance fields are part
 * of the signature so a caller cannot keep a valid id while replacing the
 * requested action, risk or approval contract.
 */
function app_advisor_proposal_identity(array $proposal): string
{
    $reportingDate = substr((string) ($proposal['generated_at'] ?? ''), 0, 10);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportingDate) !== 1) {
        return '';
    }

    $identity = [
        'advisor_key' => (string) ($proposal['advisor_key'] ?? ''),
        'proposal_type' => (string) ($proposal['proposal_type'] ?? ''),
        'reporting_date' => $reportingDate,
        'summary' => (string) ($proposal['summary'] ?? ''),
        'evidence_signature' => app_advisor_evidence_signature(
            is_array($proposal['evidence'] ?? null) ? $proposal['evidence'] : []
        ),
        'risk' => (string) ($proposal['risk'] ?? ''),
        'data_status' => (string) ($proposal['data_status'] ?? ''),
        'next_action' => (string) ($proposal['next_action'] ?? ''),
        'required_permission' => (string) ($proposal['required_permission'] ?? ''),
        'approval_owner' => (string) ($proposal['approval_owner'] ?? ''),
        'status' => (string) ($proposal['status'] ?? ''),
    ];

    return hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
}

function app_advisor_proposal_envelope_valid(array $proposal): bool
{
    $advisorKey = (string) ($proposal['advisor_key'] ?? '');
    $expected = app_advisor_proposal_identity($proposal);
    $actual = (string) ($proposal['id'] ?? '');

    return isset(app_advisor_agent_roster()[$advisorKey])
        && (string) ($proposal['status'] ?? '') === 'proposed'
        && $expected !== ''
        && strlen($actual) === 64
        && hash_equals($expected, $actual);
}

/** Read-only scalar helper shared by all briefings; never throws, never writes. */
function app_advisor_scalar(PDO $db, string $sql, array $bindings = []): ?float
{
    try {
        $statement = $db->prepare($sql);
        $statement->execute($bindings);
        $value = $statement->fetchColumn();
        return $value === false ? null : (float) $value;
    } catch (Throwable) {
        return null;
    }
}

/**
 * Read-model adapters used by the briefing functions below. No dedicated
 * service/read-model function exists yet for these two ticket counts
 * (`app_db_ticket_where_sql()` in `db.php` only supports single-value
 * equality filters, not the OR/LIKE/NULL conditions needed here), so per the
 * task's fallback rule these stay as clearly named, read-only query adapters
 * instead of inline SQL scattered across the briefing bodies. Neither writes;
 * both return null (never 0) on any query failure so the caller can tell
 * "no matches" apart from "data unavailable".
 */
function app_advisor_read_model_open_network_tickets(PDO $db): ?float
{
    return app_advisor_scalar(
        $db,
        "SELECT COUNT(*) FROM portal_tickets
          WHERE status NOT IN ('Baglandi', 'closed', 'resolved')
            AND (subject LIKE '%internet%' OR subject LIKE '%şəbəkə%' OR subject LIKE '%sebeke%' OR message LIKE '%ONT%' OR message LIKE '%RADIUS%')"
    );
}

function app_advisor_read_model_unassigned_open_tickets(PDO $db): ?float
{
    return app_advisor_scalar(
        $db,
        "SELECT COUNT(*) FROM portal_tickets
          WHERE status NOT IN ('Baglandi', 'closed', 'resolved')
            AND (assigned_technician_account_id IS NULL OR assigned_technician_account_id = 0)"
    );
}

/**
 * Critical-stock count. Reuses the canonical `app_inventory_operational_stock_sql()`
 * filter from `inventory-module-lib.php` (same one `app_inventory_statistics_context()`
 * uses for its "Kritik stok" KPI) so this never drifts from the business
 * definition and, unlike an ad hoc copy, correctly excludes smoke/test
 * fixture rows. Execution stays through `app_advisor_scalar()` rather than
 * `app_inventory_scalar()` because the latter always returns 0.0 on failure,
 * which would hide a genuine "data unavailable" state from the briefing.
 */
function app_advisor_read_model_critical_stock_count(PDO $db): ?float
{
    return app_advisor_scalar(
        $db,
        'SELECT COUNT(*) FROM inventory_stock_items i WHERE i.status = "active"
           AND i.quantity_on_hand <= i.minimum_quantity AND ' . app_inventory_operational_stock_sql('i')
    );
}

/**
 * Total long-inactive-customer count for the marketing briefing headline.
 * `app_communication_campaign_long_inactive_candidates()` (communication-campaign-lib.php)
 * is the canonical service function for this segment, but it is deliberately
 * capped at 500 rows because it selects *candidates to message*, not a total
 * count - reusing it here would silently under-report the headline for any
 * segment larger than 500. This adapter mirrors its exact WHERE-clause shape
 * (LEFT JOIN + MAX(last_payment_at), not a separately-drifting NOT EXISTS
 * formulation) so the two never disagree on which customers qualify, while
 * still returning the true unbounded count.
 */
function app_advisor_read_model_long_inactive_customer_count(PDO $db, int $inactiveDays): ?float
{
    return app_advisor_scalar(
        $db,
        'SELECT COUNT(*)
           FROM portal_accounts a
           LEFT JOIN (
               SELECT account_id, MAX(created_at) AS last_payment_at
                 FROM billing_payments
                GROUP BY account_id
           ) lp ON lp.account_id = a.id
          WHERE a.role = "customer" AND a.is_active = 1
            AND (lp.last_payment_at IS NULL OR lp.last_payment_at < DATE_SUB(NOW(), INTERVAL :days DAY))',
        ['days' => $inactiveDays]
    );
}

function app_advisor_read_model_stale_open_tickets(PDO $db, int $staleDays): ?float
{
    return app_advisor_scalar(
        $db,
        "SELECT COUNT(*) FROM portal_tickets
          WHERE status NOT IN ('Baglandi', 'closed', 'resolved')
            AND created_at < DATE_SUB(NOW(), INTERVAL :stale_days DAY)",
        ['stale_days' => $staleDays]
    );
}

function app_advisor_read_model_dept_blocked_tickets(PDO $db): ?float
{
    return app_advisor_scalar(
        $db,
        'SELECT COUNT(*) FROM portal_tickets WHERE status = :status',
        ['status' => defined('APP_TICKET_STATUS_DEPT_BLOCKED') ? APP_TICKET_STATUS_DEPT_BLOCKED : 'Basqa_Sobede_Gozleyir']
    );
}

/**
 * CRM briefing: ticket-queue health. Deliberately does not overlap with the
 * NOC advisor's "open network/ONT/RADIUS tickets" figure (that's a network-
 * cause filter; this is an age/dependency filter across ALL ticket types).
 */
function app_advisor_crm_briefing(PDO $db): array
{
    $proposals = [];
    $staleDays = 7;
    $staleCount = app_advisor_read_model_stale_open_tickets($db, $staleDays);
    $deptBlockedCount = app_advisor_read_model_dept_blocked_tickets($db);

    if ($staleCount === null && $deptBlockedCount === null) {
        $proposals[] = app_advisor_build_proposal(
            'crm',
            'data_unavailable',
            'Bilet növbəsi datası əlçatan deyil (unverified).',
            [app_advisor_evidence('portal_tickets', 'Sorğu xətası və ya cədvəl hazır deyil')],
            'medium',
            null,
            'unavailable'
        );
        return $proposals;
    }

    if ($staleCount !== null && $staleCount > 0) {
        $proposals[] = app_advisor_build_proposal(
            'crm',
            'stale_open_tickets',
            sprintf('%d gündən köhnə, hələ bağlanmamış %d bilet var.', $staleDays, (int) $staleCount),
            [app_advisor_evidence('portal_tickets', sprintf('status açıq, created_at < indi-%d gün', $staleDays))],
            $staleCount >= 10 ? 'high' : ($staleCount >= 3 ? 'medium' : 'low')
        );
    }
    if ($deptBlockedCount !== null && $deptBlockedCount > 0) {
        $proposals[] = app_advisor_build_proposal(
            'crm',
            'dept_blocked_tickets',
            sprintf('%d bilet başqa şöbədən asılı olaraq gözləyir.', (int) $deptBlockedCount),
            [app_advisor_evidence('portal_tickets', 'status = Basqa_Sobede_Gozleyir')],
            $deptBlockedCount >= 5 ? 'high' : 'medium'
        );
    }

    return $proposals;
}

function app_advisor_finance_briefing(PDO $db): array
{
    $proposals = [];
    $kpis = function_exists('app_finance_overview_kpis') ? app_finance_overview_kpis($db, ['period' => 'this_month']) : [];
    $byLabel = [];
    foreach ($kpis as $row) {
        if (is_array($row)) {
            $byLabel[(string) ($row['label'] ?? '')] = (float) ($row['value'] ?? 0);
        }
    }

    if ($kpis === []) {
        $proposals[] = app_advisor_build_proposal(
            'finance',
            'data_unavailable',
            'Bu ay üçün maliyyə KPI datası əlçatan deyil (unverified).',
            [app_advisor_evidence('app_finance_overview_kpis', 'Boş nəticə və ya sxem hazır deyil')],
            'medium',
            null,
            'unavailable'
        );
        return $proposals;
    }

    if (array_key_exists('Net pul axini', $byLabel)) {
        $netCashFlow = $byLabel['Net pul axini'];
        $proposals[] = app_advisor_build_proposal(
            'finance',
            'cash_flow_digest',
            $netCashFlow < 0
                ? sprintf('Bu ay net pul axını mənfidir: %.2f AZN.', $netCashFlow)
                : sprintf('Bu ay net pul axını müsbətdir: %.2f AZN.', $netCashFlow),
            [app_advisor_evidence('app_finance_overview_kpis:this_month', sprintf('Gəlir=%.2f, Xərc=%.2f', $byLabel['Gelir'] ?? 0.0, $byLabel['Xerc'] ?? 0.0))],
            $netCashFlow < 0 ? 'high' : 'low'
        );
    }

    $receivable = $byLabel['Alacaq'] ?? null;
    if ($receivable !== null && $receivable > 0) {
        $proposals[] = app_advisor_build_proposal(
            'finance',
            'receivable_watch',
            sprintf('Açıq alacaq balansı: %.2f AZN.', $receivable),
            [app_advisor_evidence('app_finance_overview_kpis:this_month', 'finance_debt_ledger receivable open+partial cəmi')],
            $receivable > 5000 ? 'medium' : 'low'
        );
    }

    return $proposals;
}

function app_advisor_noc_briefing(PDO $db): array
{
    $proposals = [];
    $openNetworkTickets = app_advisor_read_model_open_network_tickets($db);
    if ($openNetworkTickets === null) {
        $proposals[] = app_advisor_build_proposal(
            'noc',
            'data_unavailable',
            'Açıq şəbəkə biletləri sorğusu icra oluna bilmədi (unverified).',
            [app_advisor_evidence('portal_tickets', 'Sorğu xətası və ya cədvəl hazır deyil')],
            'medium',
            null,
            'unavailable'
        );
        return $proposals;
    }

    $proposals[] = app_advisor_build_proposal(
        'noc',
        'open_network_tickets',
        sprintf('Açıq şəbəkə/ONT/RADIUS mövzulu %d bilet var.', (int) $openNetworkTickets),
        [app_advisor_evidence('portal_tickets', 'status != closed/baglandi/resolved, şəbəkə açar sözləri')],
        $openNetworkTickets >= 10 ? 'high' : ($openNetworkTickets >= 3 ? 'medium' : 'low')
    );

    $unreachable = function_exists('app_ai_context_summary') ? (int) (app_ai_context_summary($db)['devices_unreachable'] ?? 0) : null;
    if ($unreachable !== null && $unreachable > 0) {
        $proposals[] = app_advisor_build_proposal(
            'noc',
            'unreachable_devices',
            sprintf('%d cihaz "unreachable_confirmed" statusundadır.', $unreachable),
            [app_advisor_evidence('device_learning_profiles', 'reachability_state = unreachable_confirmed')],
            $unreachable >= 5 ? 'high' : 'medium'
        );
    }

    return $proposals;
}

function app_advisor_techops_briefing(PDO $db): array
{
    $proposals = [];

    $criticalStock = app_advisor_read_model_critical_stock_count($db);
    if ($criticalStock !== null && $criticalStock > 0) {
        $proposals[] = app_advisor_build_proposal(
            'techops',
            'critical_stock',
            sprintf('%d anbar mövqeyi minimum həddə və ya altındadır.', (int) $criticalStock),
            [app_advisor_evidence('inventory_stock_items', 'quantity_on_hand <= minimum_quantity, status=active')],
            $criticalStock >= 5 ? 'high' : 'medium',
            'inventory_movement'
        );
    }

    $unassignedOpen = app_advisor_read_model_unassigned_open_tickets($db);
    if ($unassignedOpen !== null && $unassignedOpen > 0) {
        $proposals[] = app_advisor_build_proposal(
            'techops',
            'unassigned_tickets',
            sprintf('%d açıq bilet heç bir texnikə təyin olunmayıb.', (int) $unassignedOpen),
            [app_advisor_evidence('portal_tickets', 'assigned_technician_account_id boşdur, status açıq')],
            $unassignedOpen >= 5 ? 'high' : 'medium'
        );
    }

    if ($criticalStock === null && $unassignedOpen === null) {
        $proposals[] = app_advisor_build_proposal(
            'techops',
            'data_unavailable',
            'Texniki əməliyyat datası əlçatan deyil (unverified).',
            [app_advisor_evidence('inventory_stock_items/portal_tickets', 'Sorğu xətası və ya cədvəl hazır deyil')],
            'medium',
            null,
            'unavailable'
        );
    }

    return $proposals;
}

/**
 * Long-inactive-customer segment size (read-only). Candidate selection for an
 * actual campaign proposal happens in
 * `app_communication_campaign_prepare_long_inactive()`
 * (`communication-campaign-lib.php`), which stays behind the existing
 * pending_approval -> distinct-approver -> queue contract; this function only
 * reports how many customers would qualify today.
 */
function app_advisor_marketing_briefing(PDO $db): array
{
    $proposals = [];
    $inactiveThresholdDays = (int) app_business_rule_get($db, 'churn_inactive_threshold_days', 45);
    $count = app_advisor_read_model_long_inactive_customer_count($db, $inactiveThresholdDays);
    if ($count === null) {
        $proposals[] = app_advisor_build_proposal(
            'marketing',
            'data_unavailable',
            'Uzunmüddətli passiv müştəri seqmenti hesablana bilmədi (unverified).',
            [app_advisor_evidence('portal_accounts/billing_payments', 'Sorğu xətası və ya cədvəl hazır deyil')],
            'medium',
            null,
            'unavailable'
        );
        return $proposals;
    }

    if ($count > 0) {
        $proposals[] = app_advisor_build_proposal(
            'marketing',
            'long_inactive_segment',
            sprintf('%d aktiv müştəri son %d gündə ödəniş etməyib (churn riski).', (int) $count, $inactiveThresholdDays),
            [app_advisor_evidence('portal_accounts+billing_payments', sprintf('is_active=1, %d gün ərzində billing_payments yoxdur', $inactiveThresholdDays))],
            $count >= 25 ? 'high' : ($count >= 5 ? 'medium' : 'low'),
            'call:app_communication_campaign_prepare_long_inactive'
        );
    }

    return $proposals;
}

/**
 * CEO briefing composes the other four domains rather than re-querying, so
 * the cross-module view never drifts from what each specialist advisor
 * already reported. Only carried over when the underlying domain briefing is
 * accessible to admin (CEO advisor is itself admin-only, see
 * `app_advisor_access_allowed()`).
 */
function app_advisor_ceo_briefing(PDO $db): array
{
    $domains = [
        'finance' => app_advisor_finance_briefing($db),
        'noc' => app_advisor_noc_briefing($db),
        'techops' => app_advisor_techops_briefing($db),
        'marketing' => app_advisor_marketing_briefing($db),
        'crm' => app_advisor_crm_briefing($db),
    ];

    $proposals = [];
    foreach ($domains as $items) {
        foreach ($items as $item) {
            if (($item['proposal_type'] ?? '') === 'data_unavailable') {
                continue;
            }
            $proposals[] = $item;
        }
    }

    usort($proposals, static function (array $a, array $b): int {
        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        return ($order[$a['risk']] ?? 4) <=> ($order[$b['risk']] ?? 4);
    });

    if ($proposals === []) {
        $proposals[] = app_advisor_build_proposal(
            'ceo',
            'no_flagged_items',
            'Cari icra dövründə heç bir modul yüksək riskli məqam bildirmir.',
            [app_advisor_evidence('finance+noc+techops+marketing advisors', 'Bütün alt-briefinglər boş və ya aşağı risklidir')],
            'low'
        );
    }

    return $proposals;
}

/**
 * Single entry point: returns every advisor briefing the actor is allowed to
 * see, gated by `app_advisor_access_allowed()`. Advisors the actor cannot
 * access are omitted (not returned empty), so this never signals which
 * private data exists behind a permission the actor lacks.
 *
 * @return array<string,array{advisor:array<string,mixed>,proposals:list<array<string,mixed>>}>
 */
function app_advisor_all_briefings(PDO $db, array $actor): array
{
    $roster = app_advisor_agent_roster();
    $result = [];
    foreach ($roster as $key => $advisor) {
        if (!app_advisor_access_allowed($key, $actor)) {
            continue;
        }
        $proposals = match ($key) {
            'ceo' => app_advisor_ceo_briefing($db),
            'finance' => app_advisor_finance_briefing($db),
            'noc' => app_advisor_noc_briefing($db),
            'techops' => app_advisor_techops_briefing($db),
            'marketing' => app_advisor_marketing_briefing($db),
            'crm' => app_advisor_crm_briefing($db),
            default => [],
        };
        $result[$key] = ['advisor' => $advisor, 'proposals' => $proposals];
    }
    return $result;
}

/**
 * Canonical council entry point (the single place a business objective ever
 * enters the advisor ecosystem). Blocks prompt injection with
 * `app_agent_detect_injection()`, sanitizes the objective with
 * `app_agent_sanitize_for_prompt()` before it is used or stored anywhere,
 * resolves only the advisor profiles the actor may access (via
 * `app_advisor_all_briefings()` -> `app_advisor_access_allowed()`), and
 * gathers their deterministic briefings. Never executes or stages a business
 * write - every proposal returned only ever carries a `next_action`
 * describing what a super-admin could do next.
 *
 * Every call - success, injection refusal, or empty-access - writes exactly
 * one append-only audit record. If that write cannot happen (schema
 * unavailable, DB error), the whole run fails closed: no briefing/proposal
 * data is returned, because an un-audited council run would defeat the
 * governance requirement that every run is traceable.
 *
 * @return array{ok:bool,reason?:string,run_id:string,advisors?:array<string,array<string,mixed>>}
 */
function app_advisor_council_run(PDO $db, array $actor, string $objective): array
{
    $runId = bin2hex(random_bytes(16));
    $objective = trim($objective);
    if ($objective === '') {
        if (!app_advisor_council_audit($db, $runId, $actor, '', [], 'objective_required')) {
            return ['ok' => false, 'reason' => 'audit_unavailable_fail_closed', 'run_id' => $runId];
        }
        return ['ok' => false, 'reason' => 'objective_required', 'run_id' => $runId];
    }

    $injection = app_agent_detect_injection($objective);
    // Sanitize before anything else touches this text - including the
    // injection-blocked audit path below. app_agent_sanitize_for_prompt()
    // only redacts secret/PII-shaped substrings, not injection phrasing
    // itself, so app_advisor_council_audit() additionally never stores any
    // excerpt of a blocked objective (only its hash) - the whole point of
    // refusing the input is to not let it propagate further.
    $sanitizedObjective = app_agent_sanitize_for_prompt($objective);

    if ($injection) {
        if (!app_advisor_council_audit($db, $runId, $actor, $sanitizedObjective, [], 'prompt_injection_blocked')) {
            return ['ok' => false, 'reason' => 'audit_unavailable_fail_closed', 'run_id' => $runId];
        }
        return ['ok' => false, 'reason' => 'prompt_injection_blocked', 'run_id' => $runId];
    }

    $briefings = app_advisor_all_briefings($db, $actor);

    if (!app_advisor_council_audit($db, $runId, $actor, $sanitizedObjective, $briefings, 'ok')) {
        return ['ok' => false, 'reason' => 'audit_unavailable_fail_closed', 'run_id' => $runId];
    }

    return ['ok' => true, 'run_id' => $runId, 'advisors' => $briefings];
}

/**
 * Append-only audit record for one council run. Stores only: objective
 * hash, advisor keys, proposal IDs, data-status counts and the
 * correlation/run id - never the raw objective, prompts, secrets or PII. A
 * sanitized short summary excerpt (already passed through
 * `app_agent_sanitize_for_prompt()` upstream) is included only for a
 * successful run; a blocked/injection-flagged objective is never excerpted
 * at all, only hashed - refusing the input means it must not propagate any
 * further, sanitized or not. Returns false (never throws) when the audit
 * could not be written, so the caller can fail the whole run closed.
 *
 * @param array<string,array{advisor:array<string,mixed>,proposals:list<array<string,mixed>>}> $briefings
 */
function app_advisor_council_audit(
    PDO $db,
    string $runId,
    array $actor,
    string $sanitizedObjective,
    array $briefings,
    string $outcome
): bool {
    $proposalIds = [];
    $dataStatusCounts = [];
    foreach ($briefings as $briefing) {
        foreach ((array) ($briefing['proposals'] ?? []) as $proposal) {
            $proposalIds[] = (string) ($proposal['id'] ?? '');
            $status = (string) ($proposal['data_status'] ?? 'unknown');
            $dataStatusCounts[$status] = ($dataStatusCounts[$status] ?? 0) + 1;
        }
    }

    try {
        if (!$db->inTransaction()) {
            app_audit_ensure_schema($db);
        }
        app_audit_record($db, [
            'module_key' => 'ai_agent',
            'operation_class' => 'advisor_council',
            'action_type' => 'advisor_council_run',
            'entity_type' => 'advisor_council_run',
            'entity_id' => $runId,
            'status' => $outcome === 'ok' ? 'executed' : 'skipped',
            'actor_account_id' => app_audit_actor_id($actor),
            'actor_name' => app_audit_actor_name($actor),
            'summary' => 'Advisor council run (' . $outcome . ')',
            'sensitivity' => 'normal',
            'payload' => [
                'run_id' => $runId,
                'outcome' => $outcome,
                'objective_hash' => hash('sha256', $sanitizedObjective),
                // Never persist an objective excerpt. Even redacted free text
                // may still contain a person's name or address; a bounded
                // intent category is sufficient for operational reporting.
                'objective_summary' => $outcome === 'ok' ? app_advisor_objective_category($sanitizedObjective) : '',
                'advisor_keys' => array_keys($briefings),
                'proposal_ids' => $proposalIds,
                'data_status_counts' => $dataStatusCounts,
            ],
        ]);
        return true;
    } catch (Throwable) {
        return false;
    }
}

function app_advisor_objective_category(string $objective): string
{
    $normalized = mb_strtolower($objective, 'UTF-8');
    $categories = [
        'finance' => ['maliyy', 'gelir', 'gəlir', 'xerc', 'xərc', 'borc', 'odenis', 'ödəniş'],
        'noc' => ['noc', 'sebeke', 'şəbəkə', 'ont', 'olt', 'radius', 'signal', 'siqnal'],
        'techops' => ['texnik', 'bilet', 'ticket', 'anbar', 'stok', 'tapsiriq', 'tapşırıq'],
        'marketing' => ['marketinq', 'marketing', 'kampaniya', 'passiv musteri', 'passiv müştəri'],
        'crm' => ['crm', 'musteri xidmeti', 'müştəri xidməti', 'bilet novbesi', 'bilet növbəsi'],
        'ceo' => ['ceo', 'direktor', 'rehber', 'rəhbər', 'umumi hesabat', 'ümumi hesabat'],
    ];
    foreach ($categories as $category => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($normalized, $needle)) {
                return $category;
            }
        }
    }
    return 'general';
}

/**
 * Proposal -> action preparation bridge. Accepts only an already-built
 * proposal envelope (from `app_advisor_build_proposal()` /
 * `app_advisor_all_briefings()`), never a free-form kind/payload from the
 * caller directly. It:
 *  - requires the proposal's linked next_action to exactly match a kind
 *    registered in `app_agent_action_registry()` (agent-workspace-lib.php),
 *    and re-checks the registry's own `go_live_blocker` flag directly rather
 *    than trusting the envelope's routing label, so every go-live blocker
 *    stays blocked;
 *  - requires the proposal's `approval_owner` to be `super_admin` AND the
 *    actor to actually be the designated super-admin
 *    (`app_portal_is_super_admin()`), not merely holding a super-admin-ish
 *    role string;
 *  - calls the existing `app_agent_prepare_action()` and returns its preview
 *    verbatim - that function only reads/validates (permission gate,
 *    injection check, idempotency-key lookup) and never writes, so this
 *    bridge never stages, confirms or executes the action itself, and the
 *    idempotency-key duplicate check it performs is preserved unchanged;
 *  - writes exactly one sanitized, append-only proposal-preparation audit
 *    record, and fails the whole call closed (no preview returned) if that
 *    record cannot be written.
 *
 * @param array<string,mixed> $proposal An envelope from app_advisor_build_proposal().
 * @param array<string,mixed> $payload  The would-be write payload for app_agent_prepare_action() (never executed here).
 * @return array{ok:bool,reason?:string,preview?:array<string,mixed>,run_id:string,duplicate_id?:int}
 */
function app_advisor_prepare_proposal_action(array $proposal, array $payload, array $actor, PDO $db): array
{
    $runId = bin2hex(random_bytes(16));

    $deny = static function (string $reason, string $kind = 'unknown') use ($db, $runId, $actor, $proposal): array {
        $prepared = ['ok' => false, 'reason' => $reason];
        if (!app_advisor_proposal_preparation_audit($db, $runId, $actor, $proposal, $kind, $prepared)) {
            return ['ok' => false, 'reason' => 'audit_unavailable_fail_closed', 'run_id' => $runId];
        }
        return ['ok' => false, 'reason' => $reason, 'run_id' => $runId];
    };

    foreach (['id', 'advisor_key', 'proposal_type', 'summary', 'evidence', 'risk', 'data_status', 'next_action', 'approval_owner', 'required_permission', 'status', 'generated_at'] as $field) {
        if (!array_key_exists($field, $proposal)) {
            return $deny('invalid_proposal_structure');
        }
    }
    if (!app_advisor_proposal_envelope_valid($proposal)) {
        return $deny('proposal_tampered');
    }

    $nextAction = (string) ($proposal['next_action'] ?? '');
    if (str_starts_with($nextAction, 'blocked_go_live_blocker:')) {
        return $deny('go_live_blocker_not_agent_writable', substr($nextAction, strlen('blocked_go_live_blocker:')) ?: 'unknown');
    }
    if (!str_starts_with($nextAction, 'route_to_agent_action_gate:')) {
        // Not linked to a Stage 5 registry action at all (manual_review,
        // data_unavailable, or a campaign-only "call:..." next action that is
        // handled by its own service contract, e.g.
        // app_communication_campaign_prepare_long_inactive()) - nothing to bridge.
        return $deny('proposal_not_action_linked');
    }
    $kind = substr($nextAction, strlen('route_to_agent_action_gate:'));

    $registry = app_agent_action_registry();
    if (!isset($registry[$kind])) {
        return $deny('unregistered_action_kind', $kind !== '' ? $kind : 'unknown');
    }
    if (!empty($registry[$kind]['go_live_blocker'])) {
        return $deny('go_live_blocker_not_agent_writable', $kind);
    }

    if ((string) ($proposal['approval_owner'] ?? '') !== 'super_admin') {
        return $deny('approval_owner_not_super_admin', $kind);
    }
    if (!function_exists('app_portal_is_super_admin') || !app_portal_is_super_admin($actor)) {
        return $deny('actor_not_super_admin', $kind);
    }

    $prepared = app_agent_prepare_action($kind, $payload, $actor, $db);

    if (!app_advisor_proposal_preparation_audit($db, $runId, $actor, $proposal, $kind, $prepared)) {
        return ['ok' => false, 'reason' => 'audit_unavailable_fail_closed', 'run_id' => $runId];
    }

    if (empty($prepared['ok'])) {
        return [
            'ok' => false,
            'reason' => (string) ($prepared['reason'] ?? 'agent_gate_denied'),
            'run_id' => $runId,
            'duplicate_id' => (int) ($prepared['duplicate_id'] ?? 0),
        ];
    }

    return ['ok' => true, 'run_id' => $runId, 'preview' => $prepared['preview'] ?? []];
}

/**
 * Append-only audit record for one proposal-preparation attempt. Stores only
 * the run id, proposal id, advisor key, linked action kind, and the
 * prepare-action outcome (never the write payload, which may carry
 * customer-identifying free text destined for `app_agent_action_preview()`).
 * Returns false (never throws) so the bridge can fail closed.
 *
 * @param array<string,mixed> $proposal
 * @param array<string,mixed> $prepared
 */
function app_advisor_proposal_preparation_audit(
    PDO $db,
    string $runId,
    array $actor,
    array $proposal,
    string $kind,
    array $prepared
): bool {
    try {
        if (!$db->inTransaction()) {
            app_audit_ensure_schema($db);
        }
        app_audit_record($db, [
            'module_key' => 'ai_agent',
            'operation_class' => 'advisor_council',
            'action_type' => 'advisor_proposal_preparation',
            'entity_type' => 'agent_action_kind',
            'entity_id' => $kind,
            'status' => !empty($prepared['ok']) ? 'dry_run' : 'skipped',
            'actor_account_id' => app_audit_actor_id($actor),
            'actor_name' => app_audit_actor_name($actor),
            'summary' => 'Advisor proposal preparation (' . $kind . ')',
            'sensitivity' => 'critical',
            'payload' => [
                'run_id' => $runId,
                'proposal_id' => (string) ($proposal['id'] ?? ''),
                'advisor_key' => (string) ($proposal['advisor_key'] ?? ''),
                'linked_kind' => $kind,
                'prepare_ok' => !empty($prepared['ok']),
                'prepare_reason' => (string) ($prepared['reason'] ?? ''),
                'duplicate_id' => (int) ($prepared['duplicate_id'] ?? 0),
            ],
        ]);
        return true;
    } catch (Throwable) {
        return false;
    }
}
