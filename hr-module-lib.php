<?php
declare(strict_types=1);

require_once __DIR__ . '/hr-employee-service.php';

require_once __DIR__ . '/mgs-integration-lib.php';
// HR views (attendance/*) call app_ui_status()/app_ui_term(); ensure available on
// both full-page and AJAX-partial render paths.
require_once __DIR__ . '/ui-language-lib.php';

function app_hr_tabs(): array
{
    return [
        'overview' => 'Ümumi baxış',
        'employees' => 'Əməkdaşlar',
        'attendance' => 'Davamiyyət',
        'leave-travel' => 'Məzuniyyət və ezamiyyət',
        'performance' => 'Performans',
        'documents' => 'Sənədlər',
        'statistics' => 'Statistika',
    ];
}

function app_hr_tab(array $query): string
{
    $tab = clean_text((string) ($query['hr_tab'] ?? $query['tab'] ?? ''));
    if ($tab === '') {
        $focus = clean_text((string) ($query['focus'] ?? ''));
        $tab = match ($focus) {
            'kpi', 'score', 'performance' => 'performance',
            'attendance', 'schedule' => 'attendance',
            default => 'overview',
        };
    }

    return array_key_exists($tab, app_hr_tabs()) ? $tab : 'overview';
}

function app_hr_pagination(array $query, string $pageKey = 'page'): array
{
    $page = max(1, (int) ($query[$pageKey] ?? 1));
    $limit = max(1, min(50, (int) ($query['limit'] ?? 50)));
    return ['page' => $page, 'limit' => $limit, 'offset' => ($page - 1) * $limit];
}

function app_hr_date_value(mixed $value, string $fallback): string
{
    $date = clean_text((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $fallback;
    }
    $timestamp = strtotime($date);
    return $timestamp === false ? $fallback : date('Y-m-d', $timestamp);
}

function app_hr_date_range(array $query): array
{
    $today = new DateTimeImmutable('today');
    $range = clean_text((string) ($query['range'] ?? 'month'));
    if (!in_array($range, ['today', 'week', 'month', 'year', 'custom'], true)) {
        $range = 'month';
    }
    $start = $today->modify('-29 days')->format('Y-m-d');
    $end = $today->format('Y-m-d');
    if ($range === 'today') {
        $start = $end;
    } elseif ($range === 'week') {
        $start = $today->modify('-6 days')->format('Y-m-d');
    } elseif ($range === 'year') {
        $start = $today->modify('first day of this month')->modify('-11 months')->format('Y-m-d');
    } elseif ($range === 'custom') {
        $start = app_hr_date_value($query['start_date'] ?? '', $start);
        $end = app_hr_date_value($query['end_date'] ?? '', $end);
    }
    if (strtotime($start) > strtotime($end)) {
        [$start, $end] = [$end, $start];
    }
    return ['range' => $range, 'start_date' => $start, 'end_date' => $end];
}

function app_hr_query_page(PDO $db, string $countSql, string $rowsSql, array $params, array $query, string $pageKey = 'page'): array
{
    $pagination = app_hr_pagination($query, $pageKey);
    try {
        $count = $db->prepare($countSql);
        $count->execute($params);
        $total = max(0, (int) $count->fetchColumn());
        $pages = max(1, (int) ceil($total / $pagination['limit']));
        $page = min($pagination['page'], $pages);
        $offset = ($page - 1) * $pagination['limit'];
        $statement = $db->prepare($rowsSql . ' LIMIT ' . $pagination['limit'] . ' OFFSET ' . $offset);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $pagination['limit'],
            'offset' => $offset,
            'start' => $total > 0 ? $offset + 1 : 0,
            'end' => $total > 0 ? min($total, $offset + count($rows)) : 0,
        ];
    } catch (Throwable $exception) {
        return [
            'rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1,
            'limit' => $pagination['limit'], 'offset' => 0, 'start' => 0, 'end' => 0,
            'error' => $exception->getMessage(),
        ];
    }
}

function app_hr_operational_sql(string $alias = 'a'): string
{
    $haystack = 'LOWER(CONCAT_WS(" ", COALESCE(' . $alias . '.full_name,""), COALESCE(' . $alias . '.login,""), COALESCE(' . $alias . '.employee_role,"")))';
    $parts = [];
    foreach (['role agent', 'copilot texnik', 'telegram smoke', 'smoke password', 'agent.', 'cp-tech-', 'tg-operator-'] as $marker) {
        $parts[] = $haystack . ' NOT LIKE ' . "'%" . $marker . "%'";
    }
    return implode(' AND ', $parts);
}

function app_hr_search_filter(array $query, array $columns, array &$params): string
{
    $search = clean_text((string) ($query['search'] ?? ''));
    if ($search === '' || $columns === []) {
        return '';
    }
    $params['search'] = '%' . $search . '%';
    return ' AND (' . implode(' OR ', array_map(static fn (string $column): string => $column . ' LIKE :search', $columns)) . ')';
}

function app_hr_employee_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE a.role = "employee" AND ' . app_hr_operational_sql('a');
    $where .= app_hr_search_filter($query, ['a.full_name', 'a.login', 'a.employee_role', 'a.job_title', 'a.area'], $params);
    $role = clean_text((string) ($query['employee_role'] ?? ''));
    if ($role !== '') {
        $where .= ' AND a.employee_role = :employee_role';
        $params['employee_role'] = $role;
    }
    $from = ' FROM portal_accounts a
              LEFT JOIN hr_employee_compensations c ON c.id = (
                  SELECT MAX(c2.id) FROM hr_employee_compensations c2
                   WHERE c2.employee_account_id = a.id AND c2.status = "active"
              )
              LEFT JOIN hr_positions p ON p.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, "$.employee.position_id")) AS UNSIGNED)
                  OR (p.title = a.job_title AND JSON_EXTRACT(a.payload_json, "$.employee.position_id") IS NULL)' . $where;
    return app_hr_query_page(
        $db,
        'SELECT COUNT(*)' . $from,
        'SELECT a.id, a.full_name, a.login, a.account_type, a.employee_role, a.job_title, a.email, a.phone, a.area, a.address, a.status, a.is_active,
                COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, "$.employee.position_id")),
                    CAST(p.id AS CHAR)
                ) AS position_id,
                JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, "$.employee.branch_id")) AS branch_id,
                JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, "$.employee.hire_date")) AS hire_date,
                c.salary_mode, c.gross_salary, c.net_salary, c.employee_total_deductions,
                c.employer_total_cost, c.effective_from AS compensation_effective_from' . $from . '
          ORDER BY a.full_name, a.id',
        $params,
        $query
    );
}

function app_hr_attendance_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE a.role = "employee" AND a.is_active = 1 AND ' . app_hr_operational_sql('a');
    $where .= app_hr_search_filter($query, ['a.full_name', 'a.login', 'a.area'], $params);
    $from = ' FROM portal_accounts a
              LEFT JOIN mgs_operation_actions o ON o.id = (
                  SELECT MAX(o2.id) FROM mgs_operation_actions o2
                   WHERE o2.action_type = "attendance_profile_save" AND o2.entity_id = CAST(a.id AS CHAR)
              )
              LEFT JOIN hr_attendance_events e ON e.employee_account_id = a.id AND e.work_date = CURDATE()' . $where;
    return app_hr_query_page(
        $db,
        'SELECT COUNT(*) FROM portal_accounts a' . $where,
        'SELECT a.id, a.full_name, a.login, a.employee_role, a.job_title, a.area,
                JSON_UNQUOTE(JSON_EXTRACT(o.payload_json, "$.branchId")) AS branch_id,
                JSON_UNQUOTE(JSON_EXTRACT(o.payload_json, "$.cardCode")) AS card_code,
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.payload_json, "$.workStartTime")), "09:00") AS work_start_time,
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.payload_json, "$.workEndTime")), "18:00") AS work_end_time,
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.payload_json, "$.lateGraceMinutes")), "10") AS late_grace_minutes,
                e.attendance_status, e.check_in_at, e.check_out_at, e.worked_minutes,
                o.created_at AS profile_updated_at' . $from . '
          ORDER BY a.full_name, a.id',
        $params,
        $query
    );
}

function app_hr_leave_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE 1=1';
    $where .= app_hr_search_filter($query, ['employee_name', 'leave_type', 'status'], $params);
    $status = clean_text((string) ($query['status'] ?? ''));
    if ($status !== '') {
        $where .= ' AND status = :status';
        $params['status'] = $status;
    }
    return app_hr_query_page($db, 'SELECT COUNT(*) FROM hr_leave_requests' . $where, 'SELECT * FROM hr_leave_requests' . $where . ' ORDER BY created_at DESC, id DESC', $params, $query);
}

function app_hr_travel_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE 1=1';
    $where .= app_hr_search_filter($query, ['employee_name', 'destination', 'purpose', 'status'], $params);
    $status = clean_text((string) ($query['status'] ?? ''));
    if ($status !== '') {
        $where .= ' AND status = :status';
        $params['status'] = $status;
    }
    return app_hr_query_page($db, 'SELECT COUNT(*) FROM hr_travel_requests' . $where, 'SELECT * FROM hr_travel_requests' . $where . ' ORDER BY created_at DESC, id DESC', $params, $query);
}

function app_hr_score_page(PDO $db, array $query): array
{
    $periodStart = app_hr_date_value($query['score_period_start'] ?? '', date('Y-01-01'));
    $periodEnd = app_hr_date_value($query['score_period_end'] ?? '', date('Y-12-31'));
    $params = ['period_start' => $periodStart, 'period_end' => $periodEnd];
    $where = ' WHERE a.role = "employee" AND a.is_active = 1 AND ' . app_hr_operational_sql('a');
    $where .= app_hr_search_filter($query, ['a.full_name', 'a.login', 'a.job_title'], $params);
    $from = ' FROM portal_accounts a
              LEFT JOIN (
                  SELECT employee_account_id,
                         COUNT(*) AS event_count,
                         COALESCE(SUM(CASE WHEN points_delta < 0 THEN points_delta ELSE 0 END),0) AS penalty_points,
                         COALESCE(SUM(CASE WHEN points_delta > 0 THEN points_delta ELSE 0 END),0) AS reward_points,
                         COALESCE(SUM(points_delta),0) AS points_total
                    FROM hr_score_events
                   WHERE event_date BETWEEN :period_start AND :period_end
                   GROUP BY employee_account_id
              ) s ON s.employee_account_id = a.id' . $where;
    return app_hr_query_page(
        $db,
        'SELECT COUNT(*)' . $from,
        'SELECT a.id, a.full_name, a.login, a.employee_role, a.job_title,
                LEAST(100, GREATEST(0, 100 + COALESCE(s.points_total,0))) AS score,
                COALESCE(s.penalty_points,0) AS penalty_points,
                COALESCE(s.reward_points,0) AS reward_points,
                COALESCE(s.event_count,0) AS event_count' . $from . '
          ORDER BY score DESC, a.full_name, a.id',
        $params,
        $query
    ) + ['period_start' => $periodStart, 'period_end' => $periodEnd];
}

function app_hr_payroll_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE 1=1';
    $where .= app_hr_search_filter($query, ['employee_name', 'profile_code', 'status'], $params);
    $status = clean_text((string) ($query['status'] ?? ''));
    if ($status !== '') {
        $where .= ' AND status = :status';
        $params['status'] = $status;
    }
    return app_hr_query_page($db, 'SELECT COUNT(*) FROM hr_payroll_records' . $where, 'SELECT * FROM hr_payroll_records' . $where . ' ORDER BY period_month DESC, id DESC', $params, $query);
}

function app_hr_document_page(PDO $db, array $query): array
{
    $view = clean_text((string) ($query['document_view'] ?? 'completeness'));
    if ($view === 'records') {
        $params = [];
        $where = ' WHERE 1=1';
        $where .= app_hr_search_filter($query, ['employee_name', 'document_type', 'document_no', 'status'], $params);
        return app_hr_query_page($db, 'SELECT COUNT(*) FROM hr_employee_documents' . $where, 'SELECT * FROM hr_employee_documents' . $where . ' ORDER BY updated_at DESC, id DESC', $params, $query) + ['view' => 'records'];
    }

    $requiredCount = count(app_mgs_hr_required_document_types());
    $params = [];
    $where = ' WHERE a.role = "employee" AND a.is_active = 1 AND ' . app_hr_operational_sql('a');
    $where .= app_hr_search_filter($query, ['a.full_name', 'a.login', 'a.job_title'], $params);
    $from = ' FROM portal_accounts a
              LEFT JOIN hr_employee_documents d ON d.employee_account_id = a.id' . $where . '
              GROUP BY a.id, a.full_name, a.login, a.employee_role, a.job_title';
    $page = app_hr_query_page(
        $db,
        'SELECT COUNT(*) FROM portal_accounts a' . $where,
        'SELECT a.id, a.full_name AS employee_name, a.login, a.employee_role, a.job_title,
                COUNT(DISTINCT CASE WHEN d.status = "complete" AND d.document_type IN ("employment_contract","id_card","tax_social_registration","bank_account","medical_clearance") AND (d.expires_at IS NULL OR d.expires_at >= CURDATE()) THEN d.document_type END) AS complete_count' . $from . '
          ORDER BY a.full_name, a.id',
        $params,
        $query
    );
    foreach ($page['rows'] as &$row) {
        $complete = min($requiredCount, (int) ($row['complete_count'] ?? 0));
        $row['required_count'] = $requiredCount;
        $row['missing_count'] = max(0, $requiredCount - $complete);
        $row['complete_percent'] = $requiredCount > 0 ? (int) round(($complete / $requiredCount) * 100) : 0;
    }
    unset($row);
    return $page + ['view' => 'completeness'];
}

function app_hr_dataset(PDO $db, string $sql, array $params, string $labelKey, string $valueKey, string $countKey = ''): array
{
    try {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        $rows = [];
    }
    return array_map(static fn (array $row): array => [
        'label' => (string) ($row[$labelKey] ?? '-'),
        'value' => (float) ($row[$valueKey] ?? 0),
        'count' => $countKey !== '' ? (int) ($row[$countKey] ?? 0) : 0,
    ], $rows);
}

function app_hr_scalar(PDO $db, string $sql, array $params = []): float
{
    try {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return (float) $statement->fetchColumn();
    } catch (Throwable) {
        return 0.0;
    }
}

function app_hr_statistics_context(PDO $db, array $query): array
{
    $dates = app_hr_date_range($query);
    $params = ['start_date' => $dates['start_date'], 'end_date' => $dates['end_date']];
    $bucket = $dates['range'] === 'year' ? 'DATE_FORMAT(period_month, "%Y-%m")' : 'DATE(period_month)';
    $dateBucket = $dates['range'] === 'year' ? 'DATE_FORMAT(created_at, "%Y-%m")' : 'DATE(created_at)';

    $payroll = app_hr_dataset($db, 'SELECT ' . $bucket . ' AS label, COALESCE(SUM(employer_total_cost),0) AS value_total, COUNT(*) AS row_count FROM hr_payroll_records WHERE period_month BETWEEN :start_date AND :end_date GROUP BY ' . $bucket . ' ORDER BY label', $params, 'label', 'value_total', 'row_count');
    $leave = app_hr_dataset($db, 'SELECT ' . $bucket . ' AS label, COALESCE(SUM(calculated_leave_pay),0) AS value_total, COUNT(*) AS row_count FROM hr_leave_requests WHERE period_month BETWEEN :start_date AND :end_date GROUP BY ' . $bucket . ' ORDER BY label', $params, 'label', 'value_total', 'row_count');
    $travel = app_hr_dataset($db, 'SELECT ' . $dateBucket . ' AS label, COALESCE(SUM(travel_allowance),0) AS value_total, COUNT(*) AS row_count FROM hr_travel_requests WHERE created_at >= :start_at AND created_at < :end_at GROUP BY ' . $dateBucket . ' ORDER BY label', [
        'start_at' => $dates['start_date'] . ' 00:00:00',
        'end_at' => date('Y-m-d 00:00:00', strtotime($dates['end_date'] . ' +1 day')),
    ], 'label', 'value_total', 'row_count');
    $scores = app_hr_dataset($db, 'SELECT event_type AS label, COALESCE(SUM(ABS(points_delta)),0) AS value_total, COUNT(*) AS row_count FROM hr_score_events WHERE event_date BETWEEN :start_date AND :end_date GROUP BY event_type ORDER BY value_total DESC', $params, 'label', 'value_total', 'row_count');
    $documents = app_hr_dataset($db, 'SELECT status AS label, COUNT(*) AS value_total, COUNT(*) AS row_count FROM hr_employee_documents GROUP BY status ORDER BY value_total DESC', [], 'label', 'value_total', 'row_count');
    $compensation = app_hr_dataset($db, 'SELECT salary_mode AS label, COALESCE(SUM(employer_total_cost),0) AS value_total, COUNT(*) AS row_count FROM hr_employee_compensations WHERE status = "active" GROUP BY salary_mode ORDER BY value_total DESC', [], 'label', 'value_total', 'row_count');

    return $dates + [
        'kpis' => [
            ['label' => 'Aktiv emekdas', 'value' => app_hr_scalar($db, 'SELECT COUNT(*) FROM portal_accounts a WHERE a.role = "employee" AND a.is_active = 1 AND ' . app_hr_operational_sql('a')), 'kind' => 'count'],
            ['label' => 'Xalis maas', 'value' => app_hr_scalar($db, 'SELECT COALESCE(SUM(net_salary),0) FROM hr_payroll_records WHERE period_month BETWEEN :start_date AND :end_date', $params), 'kind' => 'money'],
            ['label' => 'Isci tutulmalari', 'value' => app_hr_scalar($db, 'SELECT COALESCE(SUM(employee_total_deductions),0) FROM hr_payroll_records WHERE period_month BETWEEN :start_date AND :end_date', $params), 'kind' => 'money'],
            ['label' => 'Sirketin tam xerci', 'value' => app_hr_scalar($db, 'SELECT COALESCE(SUM(employer_total_cost),0) FROM hr_payroll_records WHERE period_month BETWEEN :start_date AND :end_date', $params), 'kind' => 'money'],
            ['label' => 'Mezuniyyet pulu', 'value' => app_hr_scalar($db, 'SELECT COALESCE(SUM(calculated_leave_pay),0) FROM hr_leave_requests WHERE period_month BETWEEN :start_date AND :end_date', $params), 'kind' => 'money'],
            ['label' => 'Ezamiyyet', 'value' => app_hr_scalar($db, 'SELECT COALESCE(SUM(travel_allowance),0) FROM hr_travel_requests WHERE start_date BETWEEN :start_date AND :end_date', $params), 'kind' => 'money'],
        ],
        'datasets' => [
            'payroll_cost' => ['title' => 'Maas ve sirket xerci', 'kind' => 'money', 'rows' => $payroll],
            'leave_pay' => ['title' => 'Mezuniyyet pulu', 'kind' => 'money', 'rows' => $leave],
            'travel_cost' => ['title' => 'Ezamiyyet xerci', 'kind' => 'money', 'rows' => $travel],
            'score_events' => ['title' => 'Ceza ve mukafat ballari', 'kind' => 'count', 'rows' => $scores],
            'document_status' => ['title' => 'Sened statuslari', 'kind' => 'count', 'rows' => $documents],
            'salary_modes' => ['title' => 'Gross ve net bazasi', 'kind' => 'money', 'rows' => $compensation],
        ],
    ];
}

function app_hr_url(string $tab, array $params = [], string $lang = 'az'): string
{
    $query = ['section' => 'hr', 'hr_tab' => $tab] + $params;
    if ($lang !== 'az') {
        $query['lang'] = $lang;
    }
    return 'operations-center.php?' . http_build_query($query);
}

function app_hr_build_context(PDO $db, array $user, array $query): array
{
    app_hr_ensure_employee_schema($db);
    app_mgs_ensure_schema($db);
    $tab = app_hr_tab($query);
    $context = ['tab' => $tab, 'tabs' => app_hr_tabs(), 'query' => $query, 'user' => $user, 'lang' => app_lang(), 'data' => []];
    $employeeSelector = static function () use ($db): array {
        $statement = $db->query('SELECT id,full_name,login,employee_role,job_title,is_active FROM portal_accounts a WHERE a.role="employee" AND ' . app_hr_operational_sql('a') . ' ORDER BY a.full_name,a.id LIMIT 1000');
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    };

    if ($tab === 'overview') {
        $context['data']['statistics'] = app_hr_statistics_context($db, $query);
        $context['data']['employees'] = array_slice($employeeSelector(), 0, 5);
        $context['data']['documents'] = array_slice((array) (app_hr_document_page($db, ['page' => 1, 'limit' => 5])['rows'] ?? []), 0, 5);
    } elseif ($tab === 'employees') {
        $context['data']['page'] = app_hr_employee_page($db, $query);
        $context['data']['employees'] = $employeeSelector();
        $context['data']['branches'] = function_exists('app_db_branches') ? app_db_branches($db) : [];
        $context['data']['positions'] = app_hr_positions($db, true);
        $context['data']['can_manage_positions'] = app_portal_is_admin($user);
    } elseif ($tab === 'attendance') {
        $context['data']['page'] = app_hr_attendance_page($db, $query);
        $context['data']['employees'] = $employeeSelector();
        $context['data']['branches'] = function_exists('app_db_branches') ? app_db_branches($db) : [];
    } elseif ($tab === 'leave-travel') {
        $view = clean_text((string) ($query['request_view'] ?? 'leave'));
        $view = in_array($view, ['leave', 'travel'], true) ? $view : 'leave';
        $context['data']['view'] = $view;
        $context['data']['page'] = $view === 'travel' ? app_hr_travel_page($db, $query) : app_hr_leave_page($db, $query);
        $context['data']['employees'] = $employeeSelector();
        $firstEmployeeId = (int) ($context['data']['employees'][0]['id'] ?? 0);
        $context['data']['leave_average'] = app_mgs_hr_leave_average($firstEmployeeId, date('Y-m'), $db);
    } elseif ($tab === 'performance') {
        $context['data']['page'] = app_hr_score_page($db, $query);
        $context['data']['employees'] = $employeeSelector();
        $context['data']['reasons'] = app_mgs_hr_score_reason_defaults();
        $context['data']['recent_events'] = app_mgs_hr_score_recent_events($db, 25);
        $context['data']['pending_appeals'] = app_mgs_hr_score_appeals($db, ['status' => 'pending']);
        $context['data']['can_manage_appeals'] = app_portal_is_admin($user);
    } elseif ($tab === 'payroll') {
        $context['data']['page'] = app_hr_payroll_page($db, $query);
        $context['data']['employees'] = $employeeSelector();
        $context['data']['legal_profile'] = app_mgs_hr_default_legal_profile();
        $context['data']['preview'] = app_mgs_hr_payroll_preview([
            'gross_salary' => max(0, (float) ($query['payroll_gross_salary'] ?? 1000)),
            'leave_days' => max(0, (float) ($query['payroll_leave_days'] ?? 0)),
            'leave_daily_rate' => max(0, (float) ($query['payroll_leave_daily_rate'] ?? 0)),
            'travel_days' => max(0, (float) ($query['payroll_travel_days'] ?? 0)),
            'travel_daily_rate' => max(0, (float) ($query['payroll_travel_daily_rate'] ?? 0)),
            'taxable_travel_amount' => max(0, (float) ($query['payroll_taxable_travel_amount'] ?? 0)),
        ], $context['data']['legal_profile']);
        $context['data']['cost_rows'] = app_mgs_hr_cost_report_rows($db, 12);
        $context['data']['finance_rows'] = app_mgs_hr_finance_bridge_rows($db, 8);
    } elseif ($tab === 'documents') {
        $context['data']['page'] = app_hr_document_page($db, $query);
        $context['data']['employees'] = $employeeSelector();
        $context['data']['required_types'] = app_mgs_hr_required_document_types();
    } elseif ($tab === 'statistics') {
        $context['data']['statistics'] = app_hr_statistics_context($db, $query);
    }
    $context['data']['can_write'] = app_hr_actor_can_create_employee($user);
    return $context;
}
