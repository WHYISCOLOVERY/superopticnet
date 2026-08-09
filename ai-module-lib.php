<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/copilot-engine.php';
require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/ui-language-lib.php';
require_once __DIR__ . '/ai-provider-lib.php';
require_once __DIR__ . '/agent-ops-queue-lib.php';
require_once __DIR__ . '/ai-team-lib.php';

function app_ai_sections(): array
{
    return [
        'overview' => 'Umumi baxis',
        'copilot' => 'Superpilot',
        'customer-analytics' => 'Musteri analitikasi',
        'network-analytics' => 'Sebeke analitikasi',
        'finance-analytics' => 'Maliyye analitikasi',
        'risks' => 'Riskler',
        'recommendations' => 'Tovsiyeler',
        'team' => 'AI Komanda',
        'work-queue' => 'Təsdiq Mərkəzi',
        'automations' => 'Avtomatlasdirmalar',
        'audit' => 'AI audit',
        'model-status' => 'Model veziyyeti',
    ];
}

function app_ai_routes(): array
{
    return [
        'overview' => 'ai-dashboard.php',
        'copilot' => 'ai-copilot.php',
        'customer-analytics' => 'ai-customer-analytics.php',
        'network-analytics' => 'ai-network-analytics.php',
        'finance-analytics' => 'ai-finance-analytics.php',
        'risks' => 'ai-risks.php',
        'recommendations' => 'ai-recommendations.php',
        'team' => 'ai-team.php',
        'work-queue' => 'ai-work-queue.php',
        'automations' => 'ai-automations.php',
        'audit' => 'ai-audit.php',
        'model-status' => 'ai-model-status.php',
    ];
}

function app_ai_user_is_admin(array $user): bool
{
    $role = strtolower(trim((string) ($user['employee_role'] ?? '')));
    $accountType = strtolower(trim((string) ($user['account_type'] ?? '')));
    return in_array($role, ['admin', 'super_admin'], true)
        || ($role === '' && $accountType === 'admin');
}

function app_ai_visible_sections(array $user, ?PDO $db = null): array
{
    $sections = app_ai_sections();
    $db = $db ?? (function_exists('app_db') ? app_db() : null);
    if (!app_agent_ops_queue_enabled($db, $user)) {
        unset($sections['work-queue']);
    }

    if (app_ai_user_is_admin($user)) {
        return $sections;
    }

    $allowed = ['overview', 'copilot', 'customer-analytics', 'network-analytics', 'risks', 'recommendations', 'team', 'work-queue'];
    if (app_portal_can_access_module($user, 'finance')) {
        $allowed[] = 'finance-analytics';
    }

    return array_intersect_key($sections, array_flip($allowed));
}

function app_ai_visible_routes(array $user): array
{
    return array_intersect_key(app_ai_routes(), app_ai_visible_sections($user));
}

function app_ai_section_allowed(array $user, string $section): bool
{
    return isset(app_ai_visible_sections($user)[$section]);
}

function app_ai_section(array $query, string $fallback = 'overview'): string
{
    $sections = app_ai_sections();
    $fallback = isset($sections[$fallback]) ? $fallback : 'overview';
    $section = clean_text((string) ($query['ai_section'] ?? $fallback));
    return isset($sections[$section]) ? $section : $fallback;
}

function app_ai_pagination(array $query): array
{
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = max(1, min(50, (int) ($query['limit'] ?? 50)));
    return ['page' => $page, 'limit' => $limit, 'offset' => ($page - 1) * $limit];
}

function app_ai_action_condition(): string
{
    return '(action_type = "copilot_action" OR action_type LIKE "ai\\_%" OR action_type LIKE "%copilot%")';
}

function app_ai_action_page(PDO $db, array $query, array $statuses): array
{
    $pagination = app_ai_pagination($query);
    $params = [];
    $where = ' WHERE ' . app_ai_action_condition();
    $statuses = array_values(array_intersect($statuses, ['planned', 'dry_run', 'pending', 'executed', 'failed', 'skipped']));
    if ($statuses !== []) {
        $placeholders = [];
        foreach ($statuses as $index => $status) {
            $key = 'status_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $status;
        }
        $where .= ' AND status IN (' . implode(',', $placeholders) . ')';
    }
    $search = clean_text((string) ($query['search'] ?? ''));
    if ($search !== '') {
        $where .= ' AND (summary LIKE :search OR entity_type LIKE :search OR entity_id LIKE :search OR actor_name LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    try {
        $count = $db->prepare('SELECT COUNT(*) FROM mgs_operation_actions' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $pagination['limit']));
        $page = min($pagination['page'], $pages);
        $offset = ($page - 1) * $pagination['limit'];
        $rows = $db->prepare(
            'SELECT id, action_type, entity_type, entity_id, status, actor_name, summary, ticket_no, created_at, executed_at
               FROM mgs_operation_actions' . $where . '
              ORDER BY created_at DESC, id DESC
              LIMIT ' . $pagination['limit'] . ' OFFSET ' . $offset
        );
        $rows->execute($params);
        $items = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return [
            'rows' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $pagination['limit'],
            'start' => $total > 0 ? $offset + 1 : 0,
            'end' => $total > 0 ? min($total, $offset + count($items)) : 0,
        ];
    } catch (Throwable $exception) {
        return [
            'rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1,
            'limit' => $pagination['limit'], 'start' => 0, 'end' => 0,
            'error' => $exception->getMessage(),
        ];
    }
}

function app_ai_action_counts(PDO $db): array
{
    $counts = array_fill_keys(['planned', 'dry_run', 'pending', 'executed', 'failed', 'skipped'], 0);
    try {
        $rows = $db->query(
            'SELECT status, COUNT(*) AS total
               FROM mgs_operation_actions
              WHERE ' . app_ai_action_condition() . '
              GROUP BY status'
        );
        foreach ($rows !== false ? ($rows->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) ($row['total'] ?? 0);
            }
        }
    } catch (Throwable) {
    }
    return $counts;
}

function app_ai_context_summary(PDO $db): array
{
    $scalar = static function (string $sql) use ($db): int {
        try {
            return (int) $db->query($sql)->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    };
    $actionCondition = app_ai_action_condition();
    return [
        'operations_today' => $scalar('SELECT COUNT(*) FROM mgs_operation_actions WHERE ' . $actionCondition . ' AND created_at >= CURDATE()'),
        'device_profiles' => $scalar('SELECT COUNT(*) FROM device_learning_profiles'),
        'devices_reachable' => $scalar('SELECT COUNT(*) FROM device_learning_profiles WHERE reachability_state = "reachable"'),
        'devices_unreachable' => $scalar('SELECT COUNT(*) FROM device_learning_profiles WHERE reachability_state = "unreachable_confirmed"'),
        'devices_unknown' => $scalar('SELECT COUNT(*) FROM device_learning_profiles WHERE reachability_state = "unknown"'),
        'processes_ready' => $scalar('SELECT COUNT(*) FROM mgs_process_registry WHERE status = "ready"'),
        'processes_total' => $scalar('SELECT COUNT(*) FROM mgs_process_registry'),
        'management_path_ready' => app_mgs_management_path_ready() ? 1 : 0,
    ];
}

function app_ai_recent_actions(PDO $db, int $limit = 5): array
{
    $limit = max(1, min(10, $limit));
    try {
        $rows = $db->query(
            'SELECT id, action_type, entity_type, entity_id, status, actor_name, summary, created_at
               FROM mgs_operation_actions
              WHERE ' . app_ai_action_condition() . '
              ORDER BY created_at DESC, id DESC LIMIT ' . $limit
        );
        return $rows !== false ? ($rows->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable) {
        return [];
    }
}

function app_ai_recommendations(PDO $db): array
{
    $summary = app_ai_context_summary($db);
    $bridge = app_mgs_bridge_status();
    $counts = app_ai_action_counts($db);
    $items = [];
    if (empty($bridge['online'])) {
        $items[] = ['level' => 'warning', 'title' => 'MGS bridge offline', 'text' => 'Canli servis konteksti elcatan deyil; neticeler lokal melumatlarla mehdud qala biler.', 'href' => 'ai-model-status.php'];
    }
    if ((int) ($counts['failed'] ?? 0) > 0) {
        $items[] = ['level' => 'danger', 'title' => 'Ugursuz AI emeliyyatlari var', 'text' => (int) $counts['failed'] . ' emeliyyatin audit neticesi ugursuzdur.', 'href' => 'ai-audit.php?status=failed'];
    }
    if ((int) ($counts['pending'] ?? 0) > 0) {
        $items[] = ['level' => 'info', 'title' => 'Tesdiq gozleyen emeliyyatlar', 'text' => (int) $counts['pending'] . ' emeliyyat movcud tesdiq axininda gozleyir.', 'href' => 'ai-automations.php?status=pending'];
    }
    if ((int) ($summary['management_path_ready'] ?? 0) === 0) {
        $items[] = ['level' => 'warning', 'title' => 'Monitorinq yolu aktiv deyil', 'text' => 'Sebeke analitikasi daxili offline hallari suppression rejiminde saxlayir.', 'href' => 'ai-network-analytics.php'];
    }
    return $items;
}

function app_ai_build_context(PDO $db, array $user, string $section, array $query): array
{
    $section = app_ai_section(['ai_section' => $section]);
    $visibleSections = app_ai_visible_sections($user, $db);
    if (!isset($visibleSections[$section])) {
        $section = 'overview';
    }
    app_mgs_ensure_schema($db);
    $data = [];

    if ($section === 'overview') {
        $summary = app_ai_context_summary($db);
        $data = [
            'summary' => $summary,
            'counts' => app_ai_action_counts($db),
            'recent' => app_ai_recent_actions($db, 5),
            'bridge' => app_mgs_bridge_status(),
            'recommendations' => array_slice(app_ai_recommendations($db), 0, 4),
        ];
    } elseif ($section === 'customer-analytics') {
        $question = trim((string) ($query['q'] ?? ''));
        $data = [
            'question' => $question,
            'analysis' => $question !== '' ? app_copilot_customer_analysis($question, $db) : null,
        ];
    } elseif ($section === 'network-analytics') {
        $data = [
            'summary' => app_ai_context_summary($db),
            'bridge' => app_mgs_bridge_status(),
            'management_path_ready' => app_mgs_management_path_ready(),
        ];
    } elseif ($section === 'finance-analytics') {
        $data = [
            'available' => trim(app_config('ai_finance_analytics_url')) !== '',
            'canonical_href' => 'operations-center.php?section=finance&finance_tab=statistics',
        ];
    } elseif ($section === 'risks') {
        $data = [
            'page' => app_ai_action_page($db, $query, ['pending', 'failed']),
            'recommendations' => app_ai_recommendations($db),
        ];
    } elseif ($section === 'recommendations') {
        $data = ['recommendations' => app_ai_recommendations($db)];
    } elseif ($section === 'team') {
        $personas = [];
        foreach (app_ai_team_roster() as $personaKey => $persona) {
            if (!app_ai_team_access_allowed($personaKey, $user)) {
                continue;
            }
            $personas[$personaKey] = $persona + [
                'recent' => app_ai_team_recent_conversations($db, $personaKey, 3),
            ];
        }
        $data = [
            'personas' => $personas,
            'pending_tasks' => app_ai_user_is_admin($user) ? app_ai_team_pending_tasks($db, 30) : [],
            'approved_tasks' => app_ai_user_is_admin($user) ? app_ai_team_approved_tasks($db, 20) : [],
            'employees' => app_ai_user_is_admin($user) ? app_ai_team_active_employees($db) : [],
        ];
    } elseif ($section === 'work-queue') {
        // Routed through app_agent_ops_queue_access() (audits the access,
        // fail-closed if the audit write itself fails) and
        // app_agent_ops_queue_public_page() (strips server-side-only fields,
        // e.g. the signed advisor proposal envelope) - never call
        // app_agent_ops_queue_page() directly from a rendering path.
        $access = app_agent_ops_queue_access($db, $user, $query);
        $data = [
            'queue' => $access['ok']
                ? app_agent_ops_queue_public_page($access['page'])
                : [
                    'items' => [], 'sources' => [], 'total' => 0, 'page' => 1, 'pages' => 1,
                    'limit' => 50, 'start' => 0, 'end' => 0,
                    'error' => (string) ($access['reason'] ?? 'queue_unavailable'),
                ],
        ];
    } elseif ($section === 'automations') {
        $data = ['page' => app_ai_action_page($db, $query, ['planned', 'dry_run', 'pending'])];
    } elseif ($section === 'audit') {
        $statuses = clean_text((string) ($query['status'] ?? ''));
        $allowed = ['executed', 'failed', 'skipped'];
        $data = ['page' => app_ai_action_page($db, $query, in_array($statuses, $allowed, true) ? [$statuses] : $allowed)];
    } elseif ($section === 'model-status') {
        $bridge = app_mgs_bridge_status();
        $externalModel = app_ai_deepseek_public_status();
        $data = [
            'engine' => 'Superpilot evidence and rule engine',
            'customer_analyzer' => function_exists('app_copilot_customer_analysis'),
            'action_agent' => is_file(__DIR__ . '/copilot-agent.php'),
            'api_token_configured' => app_environment_secret('COPILOT_API_TOKEN') !== '',
            'bridge' => $bridge,
            'external_model_configured' => !empty($externalModel['configured']),
            'external_model' => $externalModel,
        ];
    }

    return [
        'lang' => app_lang(),
        'user' => $user,
        'section' => $section,
        'sections' => $visibleSections,
        'routes' => app_ai_visible_routes($user),
        'is_admin' => app_ai_user_is_admin($user),
        'data' => $data,
        'query' => $query,
    ];
}
