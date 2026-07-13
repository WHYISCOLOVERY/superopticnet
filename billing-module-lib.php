<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-customer-identity-lib.php';

require_once __DIR__ . '/billing-ui.php';

function app_billing_sections(): array
{
    return [
        'overview' => 'Umumi baxis',
        'customers' => 'Musteriler',
        'packages' => 'Paketler',
        'balances' => 'Balans ve borclar',
        'payments' => 'Odenisler',
        'services' => 'Xidmet aktivliyi',
        'providers' => 'Provider inteqrasiyalari',
        'documents' => 'Billing senedleri',
        'reports' => 'Billing hesabatlari',
        'statistics' => 'Billing statistikasi',
    ];
}

function app_billing_section(array $query, string $fallback = 'overview'): string
{
    $sections = app_billing_sections();
    $fallback = isset($sections[$fallback]) ? $fallback : 'overview';
    $section = clean_text((string) ($query['billing_section'] ?? $fallback));
    return isset($sections[$section]) ? $section : $fallback;
}

function app_billing_pagination(array $query): array
{
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = max(1, min(50, (int) ($query['limit'] ?? $query['per_page'] ?? 50)));
    return ['page' => $page, 'limit' => $limit, 'offset' => ($page - 1) * $limit];
}

function app_billing_query_page(PDO $db, string $countSql, string $rowsSql, array $params, array $query): array
{
    $pagination = app_billing_pagination($query);
    try {
        $countStatement = $db->prepare($countSql);
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();
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

function app_billing_search_filter(array $query, array $columns, array &$params): string
{
    $search = clean_text((string) ($query['search'] ?? ''));
    if ($search === '' || $columns === []) {
        return '';
    }
    $params['search'] = '%' . $search . '%';
    return ' AND (' . implode(' OR ', array_map(static fn (string $column): string => $column . ' LIKE :search', $columns)) . ')';
}

function app_billing_operational_customer_sql(string $alias = 'a'): string
{
    return $alias . '.id > 0';
}

function app_billing_operational_payment_sql(string $alias = 'p'): string
{
    return $alias . '.id > 0';
}

function app_billing_debt_sql(string $alias = 'a'): string
{
    return 'CAST(REPLACE(REPLACE(REPLACE(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(' . $alias . '.payload_json, "$.billing.debt_raw")), "0"), "AZN", ""), " ", ""), ",", ".") AS DECIMAL(12,2))';
}

function app_billing_package_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE 1=1';
    $where .= app_billing_search_filter($query, ['t.name', 't.radius_group', 't.service_type'], $params);
    $status = clean_text((string) ($query['status'] ?? ''));
    if ($status !== '') {
        $where .= ' AND t.status = :status';
        $params['status'] = $status;
    }
    $from = ' FROM billing_tariffs t' . $where;
    return app_billing_query_page(
        $db,
        'SELECT COUNT(*)' . $from,
        'SELECT t.*, (SELECT COUNT(*) FROM billing_radius_services s WHERE s.tariff_id = t.id) AS service_count' . $from . ' ORDER BY t.status, t.monthly_fee, t.name',
        $params,
        $query
    );
}

function app_billing_balance_page(PDO $db, array $query): array
{
    $params = [];
    $debt = app_billing_debt_sql('a');
    $where = ' WHERE a.role = "customer" AND ' . app_billing_operational_customer_sql('a');
    app_billing_ensure_identity_schema($db);
    $where .= app_billing_search_filter($query, ['a.subscriber_code', 'a.contract_no', 'a.full_name', 'a.phone', 'a.fin_code', 'a.document_no', 'a.area'], $params);
    $status = clean_text((string) ($query['status'] ?? ''));
    if ($status === 'debt') {
        $where .= ' AND ' . $debt . ' > 0';
    } elseif ($status === 'clear') {
        $where .= ' AND ' . $debt . ' <= 0';
    }
    $from = ' FROM portal_accounts a' . $where;
    return app_billing_query_page(
        $db,
        'SELECT COUNT(*)' . $from,
        'SELECT a.id, a.subscriber_code, a.contract_no, a.full_name, a.phone, a.fin_code, a.document_no, a.address, a.area, a.plan, a.status, a.is_active,
                ' . $debt . ' AS debt_amount,
                (SELECT COALESCE(SUM(p.amount),0) FROM billing_payments p WHERE p.account_id = a.id AND ' . app_billing_operational_payment_sql('p') . ') AS paid_total,
                (SELECT MAX(s.expires_at) FROM billing_radius_services s WHERE s.account_id = a.id) AS expires_at,
                (SELECT COUNT(*) FROM billing_radius_services s WHERE s.account_id = a.id AND s.status = "active") AS active_service_count' . $from . '
          ORDER BY debt_amount DESC, a.full_name, a.id',
        $params,
        $query
    );
}

function app_billing_service_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE ' . app_billing_operational_customer_sql('a');
    $where .= app_billing_search_filter($query, ['s.username', 'a.subscriber_code', 'a.full_name', 'a.phone', 't.name', 's.assigned_ip'], $params);
    $status = clean_text((string) ($query['status'] ?? ''));
    if ($status !== '') {
        $where .= ' AND s.status = :status';
        $params['status'] = $status;
    }
    $from = ' FROM billing_radius_services s
              INNER JOIN portal_accounts a ON a.id = s.account_id
              LEFT JOIN billing_tariffs t ON t.id = s.tariff_id' . $where;
    return app_billing_query_page(
        $db,
        'SELECT COUNT(*)' . $from,
        'SELECT s.id, s.username, s.service_type, s.assigned_ip, s.nat_public_ip, s.static_ip, s.status, s.expires_at, s.last_radius_sync_at,
                a.id AS account_id, a.subscriber_code, a.full_name, a.phone, a.area, t.name AS tariff_name, t.monthly_fee' . $from . '
          ORDER BY FIELD(s.status,"expired","suspended","disabled","active"), s.expires_at, s.id DESC',
        $params,
        $query
    );
}

function app_billing_provider_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE ' . app_billing_operational_payment_sql('p');
    $where .= app_billing_search_filter($query, ['p.payment_method'], $params);
    $from = ' FROM billing_payments p' . $where;
    return app_billing_query_page(
        $db,
        'SELECT COUNT(DISTINCT p.payment_method)' . $from,
        'SELECT p.payment_method AS provider_name, COUNT(*) AS payment_count, COALESCE(SUM(p.amount),0) AS amount_total,
                MAX(p.created_at) AS last_payment_at, COUNT(DISTINCT p.subscriber_code) AS customer_count' . $from . '
          GROUP BY p.payment_method ORDER BY amount_total DESC, provider_name',
        $params,
        $query
    );
}

function app_billing_document_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE ' . app_billing_operational_payment_sql('p') . ' AND ' . app_billing_operational_customer_sql('a');
    $where .= app_billing_search_filter($query, ['p.external_id', 'p.transaction_id', 'p.subscriber_code', 'a.contract_no', 'a.full_name', 'p.payment_method'], $params);
    $from = ' FROM billing_payments p INNER JOIN portal_accounts a ON a.id = p.account_id' . $where;
    return app_billing_query_page(
        $db,
        'SELECT COUNT(*)' . $from,
        'SELECT STRAIGHT_JOIN p.id, p.external_id, p.transaction_id, p.subscriber_code, p.amount, p.payment_method, p.created_at,
                a.contract_no, a.full_name, a.phone' . $from . ' ORDER BY p.created_at DESC, p.id DESC',
        $params,
        $query
    );
}

function app_billing_date_range(array $query): array
{
    $range = clean_text((string) ($query['range'] ?? 'month'));
    $allowed = ['today', 'yesterday', 'week', 'month', 'year', 'custom'];
    $range = in_array($range, $allowed, true) ? $range : 'month';
    $today = new DateTimeImmutable('today');
    if ($range === 'today') {
        $start = $end = $today;
    } elseif ($range === 'yesterday') {
        $start = $end = $today->modify('-1 day');
    } elseif ($range === 'week') {
        $start = $today->modify('-6 days');
        $end = $today;
    } elseif ($range === 'year') {
        $start = $today->modify('-11 months')->modify('first day of this month');
        $end = $today;
    } elseif ($range === 'custom') {
        $startText = clean_text((string) ($query['start_date'] ?? ''));
        $endText = clean_text((string) ($query['end_date'] ?? ''));
        $start = DateTimeImmutable::createFromFormat('Y-m-d', $startText) ?: $today->modify('-29 days');
        $end = DateTimeImmutable::createFromFormat('Y-m-d', $endText) ?: $today;
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
    } else {
        $start = $today->modify('-29 days');
        $end = $today;
    }
    return ['range' => $range, 'start_date' => $start->format('Y-m-d'), 'end_date' => $end->format('Y-m-d')];
}

function app_billing_dataset(PDO $db, string $sql, array $params = []): array
{
    try {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        $rows = [];
    }
    return array_map(static fn (array $row): array => [
        'label' => (string) ($row['label'] ?? '-'),
        'value' => (float) ($row['value_total'] ?? 0),
        'count' => (int) ($row['row_count'] ?? 0),
    ], $rows);
}

function app_billing_scalar(PDO $db, string $sql, array $params = []): float
{
    try {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return (float) $statement->fetchColumn();
    } catch (Throwable) {
        return 0.0;
    }
}

function app_billing_statistics_context(PDO $db, array $query): array
{
    $dates = app_billing_date_range($query);
    $params = [
        'start_at' => $dates['start_date'] . ' 00:00:00',
        'end_at' => date('Y-m-d 00:00:00', strtotime($dates['end_date'] . ' +1 day')),
    ];
    $paymentGuard = app_billing_operational_payment_sql('p');
    $customerGuard = app_billing_operational_customer_sql('a');
    $bucket = $dates['range'] === 'year' ? 'DATE_FORMAT(p.created_at, "%Y-%m")' : 'DATE(p.created_at)';
    $payments = app_billing_dataset($db, 'SELECT ' . $bucket . ' AS label, COALESCE(SUM(p.amount),0) AS value_total, COUNT(*) AS row_count FROM billing_payments p WHERE ' . $paymentGuard . ' AND p.created_at >= :start_at AND p.created_at < :end_at GROUP BY ' . $bucket . ' ORDER BY label LIMIT 12', $params);
    $customers = app_billing_dataset($db, 'SELECT CASE WHEN a.is_active = 1 THEN "active" ELSE "inactive" END AS label, COUNT(*) AS value_total, COUNT(*) AS row_count FROM portal_accounts a WHERE a.role = "customer" AND ' . $customerGuard . ' GROUP BY a.is_active ORDER BY value_total DESC LIMIT 12');
    $services = app_billing_dataset($db, 'SELECT s.status AS label, COUNT(*) AS value_total, COUNT(*) AS row_count FROM billing_radius_services s INNER JOIN portal_accounts a ON a.id = s.account_id WHERE ' . $customerGuard . ' GROUP BY s.status ORDER BY value_total DESC LIMIT 12');
    $packages = app_billing_dataset($db, 'SELECT COALESCE(t.name,"Paketsiz") AS label, COUNT(*) AS value_total, COUNT(*) AS row_count FROM billing_radius_services s INNER JOIN portal_accounts a ON a.id = s.account_id LEFT JOIN billing_tariffs t ON t.id = s.tariff_id WHERE ' . $customerGuard . ' GROUP BY t.id, t.name ORDER BY value_total DESC LIMIT 12');
    $providers = app_billing_dataset($db, 'SELECT p.payment_method AS label, COALESCE(SUM(p.amount),0) AS value_total, COUNT(*) AS row_count FROM billing_payments p WHERE ' . $paymentGuard . ' AND p.created_at >= :start_at AND p.created_at < :end_at GROUP BY p.payment_method ORDER BY value_total DESC LIMIT 12', $params);
    return $dates + [
        'kpis' => [
            ['label' => 'Dovr odenisi', 'value' => array_sum(array_column($payments, 'value')), 'kind' => 'money'],
            ['label' => 'Aktiv musteri', 'value' => app_billing_scalar($db, 'SELECT COUNT(*) FROM portal_accounts a WHERE a.role = "customer" AND a.is_active = 1 AND ' . $customerGuard), 'kind' => 'count'],
            ['label' => 'Aktiv xidmet', 'value' => app_billing_scalar($db, 'SELECT COUNT(*) FROM billing_radius_services s INNER JOIN portal_accounts a ON a.id = s.account_id WHERE s.status = "active" AND ' . $customerGuard), 'kind' => 'count'],
            ['label' => 'Aktiv paket', 'value' => app_billing_scalar($db, 'SELECT COUNT(*) FROM billing_tariffs WHERE status = "active"'), 'kind' => 'count'],
        ],
        'datasets' => [
            'payments' => ['title' => 'Gunluk odenisler', 'kind' => 'money', 'rows' => $payments],
            'customers' => ['title' => 'Musteri statusu', 'kind' => 'count', 'rows' => $customers],
            'services' => ['title' => 'Xidmet statusu', 'kind' => 'count', 'rows' => $services],
            'packages' => ['title' => 'Paket paylanmasi', 'kind' => 'count', 'rows' => $packages],
            'providers' => ['title' => 'Provider odenisleri', 'kind' => 'money', 'rows' => $providers],
        ],
    ];
}

function app_billing_report_context(PDO $db, array $query): array
{
    $report = clean_text((string) ($query['report'] ?? 'payments'));
    $report = in_array($report, ['payments', 'customers', 'services', 'packages', 'providers'], true) ? $report : 'payments';
    $statistics = app_billing_statistics_context($db, $query);
    return $statistics + ['report' => $report, 'dataset' => (array) ($statistics['datasets'][$report] ?? [])];
}

function app_billing_overview_context(PDO $db, array $query): array
{
    $customerGuard = app_billing_operational_customer_sql('a');
    $paymentGuard = app_billing_operational_payment_sql('p');
    $debt = app_billing_debt_sql('a');
    $recent = [];
    $alerts = [];
    try {
        $recentStatement = $db->query('SELECT STRAIGHT_JOIN p.id, p.subscriber_code, p.amount, p.payment_method, p.transaction_id, p.created_at, a.full_name FROM billing_payments p INNER JOIN portal_accounts a ON a.id = p.account_id WHERE ' . $paymentGuard . ' AND ' . $customerGuard . ' ORDER BY p.created_at DESC, p.id DESC LIMIT 5');
        $recent = $recentStatement !== false ? ($recentStatement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $alertStatement = $db->query('SELECT a.id, a.subscriber_code, a.full_name, ' . $debt . ' AS debt_amount, MAX(s.expires_at) AS expires_at, MAX(s.status) AS service_status FROM portal_accounts a LEFT JOIN billing_radius_services s ON s.account_id = a.id WHERE a.role = "customer" AND ' . $customerGuard . ' GROUP BY a.id HAVING debt_amount > 0 OR expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY) ORDER BY debt_amount DESC, expires_at LIMIT 5');
        $alerts = $alertStatement !== false ? ($alertStatement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable) {
        $recent = [];
        $alerts = [];
    }
    return [
        'kpis' => [
            ['label' => 'Cemi musteri', 'value' => app_billing_scalar($db, 'SELECT COUNT(*) FROM portal_accounts a WHERE a.role = "customer" AND ' . $customerGuard), 'kind' => 'count'],
            ['label' => 'Aktiv musteri', 'value' => app_billing_scalar($db, 'SELECT COUNT(*) FROM portal_accounts a WHERE a.role = "customer" AND a.is_active = 1 AND ' . $customerGuard), 'kind' => 'count'],
            ['label' => 'Aktiv xidmet', 'value' => app_billing_scalar($db, 'SELECT COUNT(*) FROM billing_radius_services s INNER JOIN portal_accounts a ON a.id = s.account_id WHERE s.status = "active" AND ' . $customerGuard), 'kind' => 'count'],
            ['label' => 'Riskli xidmet', 'value' => app_billing_scalar($db, 'SELECT COUNT(*) FROM billing_radius_services s INNER JOIN portal_accounts a ON a.id = s.account_id WHERE (s.status IN ("expired","suspended","disabled") OR s.expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)) AND ' . $customerGuard), 'kind' => 'count'],
            ['label' => 'Bugun odenis', 'value' => app_billing_scalar($db, 'SELECT COALESCE(SUM(p.amount),0) FROM billing_payments p WHERE DATE(p.created_at) = CURDATE() AND ' . $paymentGuard), 'kind' => 'money'],
            ['label' => 'Musteri borcu', 'value' => app_billing_scalar($db, 'SELECT COALESCE(SUM(' . $debt . '),0) FROM portal_accounts a WHERE a.role = "customer" AND ' . $customerGuard), 'kind' => 'money'],
        ],
        'alerts' => $alerts,
        'recent' => $recent,
    ];
}

function app_billing_url(string $section, array $params = [], string $lang = 'az'): string
{
    $routes = [
        'overview' => 'billing-dashboard.php',
        'customers' => 'billing-customers.php',
        'packages' => 'billing-packages.php',
        'balances' => 'billing-balances.php',
        'payments' => 'billing-payments.php',
        'services' => 'billing-services.php',
        'providers' => 'billing-providers.php',
        'documents' => 'billing-documents.php',
        'reports' => 'billing-reports.php',
        'statistics' => 'billing-statistics.php',
    ];
    $route = $routes[$section] ?? $routes['overview'];
    if ($lang !== 'az') {
        $params['lang'] = $lang;
    }
    return $route . ($params !== [] ? '?' . http_build_query($params) : '');
}

function app_billing_build_context(PDO $db, array $user, string $section, array $query): array
{
    $section = app_billing_section(['billing_section' => $section]);
    $context = [
        'section' => $section,
        'sections' => app_billing_sections(),
        'query' => $query,
        'user' => $user,
        'lang' => app_lang(),
        'data' => [],
    ];
    if ($section === 'overview') {
        $context['data'] = app_billing_overview_context($db, $query);
    } elseif ($section === 'packages') {
        $context['data']['page'] = app_billing_package_page($db, $query);
    } elseif ($section === 'balances') {
        $context['data']['page'] = app_billing_balance_page($db, $query);
    } elseif ($section === 'services') {
        $context['data']['page'] = app_billing_service_page($db, $query);
    } elseif ($section === 'providers') {
        $context['data']['page'] = app_billing_provider_page($db, $query);
    } elseif ($section === 'documents') {
        $context['data']['page'] = app_billing_document_page($db, $query);
    } elseif ($section === 'reports') {
        $context['data']['report'] = app_billing_report_context($db, $query);
    } elseif ($section === 'statistics') {
        $context['data']['statistics'] = app_billing_statistics_context($db, $query);
    }
    return $context;
}
