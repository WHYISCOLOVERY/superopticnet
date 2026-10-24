<?php
declare(strict_types=1);

require_once __DIR__ . '/reporting-period-lib.php';
require_once __DIR__ . '/stage1-feature-lib.php';
require_once __DIR__ . '/business-rules-lib.php';

require_once __DIR__ . '/billing-customer-identity-lib.php';

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/ui-language-lib.php';

function app_billing_sections(): array
{
    return [
        'overview' => 'Ümumi baxış',
        'customers' => 'Müştərilər',
        'packages' => 'Paketlər',
        'balances' => 'Balans və borclar',
        'payments' => 'Ödənişlər',
        'services' => 'Xidmət aktivliyi',
        'providers' => 'Provayder inteqrasiyaları',
        'documents' => 'Billing sənədləri',
        'reports' => 'Billing hesabatları',
        'statistics' => 'Billing statistikası',
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

/**
 * Canonical "revenue in [startAt, endAt)" for billing_payments. This is the
 * one place that sums payments for a period — Billing's own dashboard,
 * Finance's income line and Analytics' snapshot all call this instead of
 * each keeping an independent copy of the same SUM(amount) query, so a
 * future change to the operational payment guard (or any other filter)
 * can't silently diverge between pages that are supposed to agree.
 */
function app_billing_period_revenue_total(PDO $db, string $startAt, string $endAt): float
{
    return app_billing_scalar(
        $db,
        'SELECT COALESCE(SUM(p.amount),0) FROM billing_payments p WHERE '
            . app_billing_operational_payment_sql('p')
            . ' AND p.created_at >= :start_at AND p.created_at < :end_at',
        ['start_at' => $startAt, 'end_at' => $endAt]
    );
}

/**
 * Canonical "how many active customers right now" count (all-time, not
 * period-bound). Same reasoning as app_billing_period_revenue_total(): one
 * definition instead of several copy-pasted literals that must be kept in
 * sync by hand.
 */
function app_billing_active_customer_count(PDO $db): float
{
    return app_billing_scalar(
        $db,
        'SELECT COUNT(*) FROM portal_accounts a WHERE a.role = "customer" AND a.is_active = 1 AND '
            . app_billing_operational_customer_sql('a')
    );
}

function app_billing_debt_sql(string $alias = 'a'): string
{
    // Prefer the indexed generated column (app_db_migrate_billing_debt_column)
    // when present; fall back to the live JSON expression otherwise. The column
    // check is memoized so it costs nothing after the first call per request.
    static $hasColumn = null;
    if ($hasColumn === null) {
        try {
            $db = app_db();
            $hasColumn = $db instanceof PDO
                && (bool) $db->query("SHOW COLUMNS FROM portal_accounts LIKE 'billing_debt_amount'")->fetch();
        } catch (Throwable) {
            $hasColumn = false;
        }
    }
    if ($hasColumn) {
        return $alias . '.billing_debt_amount';
    }
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
    return app_reporting_period($query);
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
    // Request-level memoization: dashboards compute the same aggregate (e.g.
    // "Aktiv xidmet") from more than one context in a single render. Caching by
    // SQL+params removes the duplicate scans without changing any value.
    static $memo = [];
    $key = $sql . '|' . serialize($params);
    if (array_key_exists($key, $memo)) {
        return $memo[$key];
    }
    try {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return $memo[$key] = (float) $statement->fetchColumn();
    } catch (Throwable) {
        return $memo[$key] = 0.0;
    }
}

/**
 * Tiny fail-safe file cache for expensive, non-critical aggregates (dashboard
 * KPIs). On any error it falls through to a live computation. TTL in seconds.
 */
function app_billing_cache_remember(string $key, int $ttlSeconds, callable $producer)
{
    $dir = __DIR__ . '/data/cache';
    $file = $dir . '/billing_' . preg_replace('/[^a-z0-9_.-]/i', '_', $key) . '.json';
    if (is_file($file) && (time() - (int) filemtime($file)) < $ttlSeconds) {
        $cached = json_decode((string) @file_get_contents($file), true);
        if (is_array($cached) && array_key_exists('v', $cached)) {
            return $cached['v'];
        }
    }
    $value = $producer();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($file, json_encode(['v' => $value], JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $value;
}

function app_billing_statistics_context(PDO $db, array $query): array
{
    $dates = app_billing_date_range($query);
    if (empty($dates['valid'])) {
        return $dates + ['kpis' => [], 'datasets' => [], 'data_quality' => []];
    }
    $dateContractEnabled = app_stage1_feature_enabled($db, 'stage1_statistics_date_contract');
    $params = [
        'start_at' => $dates['start_at'],
        'end_at' => $dateContractEnabled ? $dates['operational_end_at_exclusive'] : $dates['end_at_exclusive'],
    ];
    $paymentGuard = app_billing_operational_payment_sql('p');
    $customerGuard = app_billing_operational_customer_sql('a');
    $bucket = $dates['range'] === 'year' ? 'DATE_FORMAT(p.created_at, "%Y-%m")' : 'DATE(p.created_at)';
    $payments = app_billing_dataset($db, 'SELECT ' . $bucket . ' AS label, COALESCE(SUM(p.amount),0) AS value_total, COUNT(*) AS row_count FROM billing_payments p WHERE ' . $paymentGuard . ' AND p.created_at >= :start_at AND p.created_at < :end_at GROUP BY ' . $bucket . ' ORDER BY label', $params);
    $customers = app_billing_dataset($db, 'SELECT CASE WHEN a.is_active = 1 THEN "active" ELSE "inactive" END AS label, COUNT(*) AS value_total, COUNT(*) AS row_count FROM portal_accounts a WHERE a.role = "customer" AND ' . $customerGuard . ' GROUP BY a.is_active ORDER BY value_total DESC LIMIT 12');
    $services = app_billing_dataset($db, 'SELECT s.status AS label, COUNT(*) AS value_total, COUNT(*) AS row_count FROM billing_radius_services s INNER JOIN portal_accounts a ON a.id = s.account_id WHERE ' . $customerGuard . ' GROUP BY s.status ORDER BY value_total DESC LIMIT 12');
    $packages = app_billing_dataset($db, 'SELECT COALESCE(t.name,"Paketsiz") AS label, COUNT(*) AS value_total, COUNT(*) AS row_count FROM billing_radius_services s INNER JOIN portal_accounts a ON a.id = s.account_id LEFT JOIN billing_tariffs t ON t.id = s.tariff_id WHERE ' . $customerGuard . ' GROUP BY t.id, t.name ORDER BY value_total DESC LIMIT 12');
    $providers = app_billing_dataset($db, 'SELECT p.payment_method AS label, COALESCE(SUM(p.amount),0) AS value_total, COUNT(*) AS row_count FROM billing_payments p WHERE ' . $paymentGuard . ' AND p.created_at >= :start_at AND p.created_at < :end_at GROUP BY p.payment_method ORDER BY value_total DESC LIMIT 12', $params);
    $futurePayments = app_billing_scalar(
        $db,
        'SELECT COUNT(*) FROM billing_payments p WHERE ' . $paymentGuard . ' AND p.created_at >= :future_cutoff_at',
        ['future_cutoff_at' => $dates['future_cutoff_at']]
    );
    return $dates + [
        'kpis' => [
            ['label' => 'Dovr odenisi', 'value' => array_sum(array_column($payments, 'value')), 'kind' => 'money'],
            ['label' => 'Aktiv musteri', 'value' => app_billing_active_customer_count($db), 'kind' => 'count'],
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
        'data_quality' => [
            'future_billing_payment_rows' => (int) $futurePayments,
            'future_rows_excluded' => $dateContractEnabled,
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

    // The overview KPIs (total debt from JSON, service counts, today's payments)
    // and the alert/recent lists are expensive aggregates over 130k+ payments and
    // 5k+ services. They do not need millisecond freshness on an admin dashboard,
    // so cache the whole block per operational scope for a short TTL. This is the
    // single biggest saving on the dashboard render.
    $cacheKey = 'overview_' . md5($customerGuard . '|' . $paymentGuard . '|' . $debt);
    return app_billing_cache_remember($cacheKey, 45, static function () use ($db, $customerGuard, $paymentGuard, $debt): array {
        $recent = [];
        $alerts = [];
        $riskyWindowDays = max(1, (int) app_business_rule_get($db, 'billing_risky_service_window_days', 7));
        try {
            $recentStatement = $db->query('SELECT STRAIGHT_JOIN p.id, p.subscriber_code, p.amount, p.payment_method, p.transaction_id, p.created_at, a.full_name FROM billing_payments p INNER JOIN portal_accounts a ON a.id = p.account_id WHERE ' . $paymentGuard . ' AND ' . $customerGuard . ' ORDER BY p.created_at DESC, p.id DESC LIMIT 5');
            $recent = $recentStatement !== false ? ($recentStatement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            $alertStatement = $db->query('SELECT a.id, a.subscriber_code, a.full_name, ' . $debt . ' AS debt_amount, MAX(s.expires_at) AS expires_at, MAX(s.status) AS service_status FROM portal_accounts a LEFT JOIN billing_radius_services s ON s.account_id = a.id WHERE a.role = "customer" AND ' . $customerGuard . ' GROUP BY a.id HAVING debt_amount > 0 OR expires_at <= DATE_ADD(NOW(), INTERVAL ' . $riskyWindowDays . ' DAY) ORDER BY debt_amount DESC, expires_at LIMIT 5');
            $alerts = $alertStatement !== false ? ($alertStatement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable) {
            $recent = [];
            $alerts = [];
        }
        $totalCustomers = app_billing_scalar($db, 'SELECT COUNT(*) FROM portal_accounts a WHERE a.role = "customer" AND ' . $customerGuard);
        $activeCustomers = app_billing_active_customer_count($db);
        $activeServices = app_billing_scalar($db, 'SELECT COUNT(*) FROM billing_radius_services s INNER JOIN portal_accounts a ON a.id = s.account_id WHERE s.status = "active" AND ' . $customerGuard);
        $riskyServices = app_billing_scalar($db, 'SELECT COUNT(*) FROM billing_radius_services s INNER JOIN portal_accounts a ON a.id = s.account_id WHERE (s.status IN ("expired","suspended","disabled") OR s.expires_at <= DATE_ADD(NOW(), INTERVAL ' . $riskyWindowDays . ' DAY)) AND ' . $customerGuard);
        $todayPayment = app_billing_scalar($db, 'SELECT COALESCE(SUM(p.amount),0) FROM billing_payments p WHERE p.created_at >= CURDATE() AND p.created_at < CURDATE() + INTERVAL 1 DAY AND ' . $paymentGuard);
        $customerDebt = app_billing_scalar($db, 'SELECT COALESCE(SUM(' . $debt . '),0) FROM portal_accounts a WHERE a.role = "customer" AND ' . $customerGuard);

        // 14-day daily payment trend (index-friendly range), zero-filled.
        $trend = [];
        for ($d = 13; $d >= 0; $d--) {
            $trend[date('Y-m-d', strtotime('-' . $d . ' day'))] = 0.0;
        }
        try {
            $trendStmt = $db->query('SELECT DATE(p.created_at) AS d, COALESCE(SUM(p.amount),0) AS total
                FROM billing_payments p
                WHERE p.created_at >= CURDATE() - INTERVAL 13 DAY AND p.created_at < CURDATE() + INTERVAL 1 DAY AND ' . $paymentGuard . '
                GROUP BY DATE(p.created_at)');
            foreach (($trendStmt !== false ? $trendStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $trendRow) {
                $key = (string) ($trendRow['d'] ?? '');
                if (array_key_exists($key, $trend)) {
                    $trend[$key] = (float) ($trendRow['total'] ?? 0);
                }
            }
        } catch (Throwable) {
            // Trend is decorative; leave zero-filled on failure.
        }
        $trendSeries = [];
        foreach ($trend as $day => $total) {
            $trendSeries[] = ['label' => date('d.m', strtotime($day)), 'value' => $total];
        }

        return [
            'kpis' => [
                ['label' => 'Cemi musteri', 'value' => $totalCustomers, 'kind' => 'count', 'icon' => 'fa-users', 'tone' => 'info'],
                ['label' => 'Aktiv musteri', 'value' => $activeCustomers, 'kind' => 'count', 'icon' => 'fa-user-check', 'tone' => 'success'],
                ['label' => 'Aktiv xidmet', 'value' => $activeServices, 'kind' => 'count', 'icon' => 'fa-signal', 'tone' => 'primary'],
                ['label' => 'Riskli xidmet', 'value' => $riskyServices, 'kind' => 'count', 'icon' => 'fa-circle-exclamation', 'tone' => 'warning'],
                ['label' => 'Bugun odenis', 'value' => $todayPayment, 'kind' => 'money', 'icon' => 'fa-wallet', 'tone' => 'success'],
                ['label' => 'Musteri borcu', 'value' => $customerDebt, 'kind' => 'money', 'icon' => 'fa-receipt', 'tone' => 'danger'],
            ],
            'charts' => [
                'trend' => $trendSeries,
                'services' => [
                    ['label' => 'Aktiv', 'value' => $activeServices, 'color' => 'var(--ui-color-success, #16754a)'],
                    ['label' => 'Riskli', 'value' => $riskyServices, 'color' => 'var(--ui-color-warning, #9a5a0a)'],
                ],
                'customers' => [
                    ['label' => 'Aktiv', 'value' => $activeCustomers, 'color' => 'var(--ui-color-primary, #087783)'],
                    ['label' => 'Passiv', 'value' => max(0.0, $totalCustomers - $activeCustomers), 'color' => 'var(--ui-color-neutral, #64748b)'],
                ],
            ],
            'alerts' => $alerts,
            'recent' => $recent,
        ];
    });
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

// Maker-checker (vision 19.2 "kutlevi musteri deaktivlesmesi" -- this feature
// did not exist before). Deactivating one customer at a time already goes
// through the full billing-customer-edit identity/RADIUS pipeline; a BULK
// deactivation is staged here instead and only touches accounts + RADIUS
// once a DIFFERENT admin approves it.
const APP_BILLING_BULK_DEACTIVATION_MAX_ACCOUNTS = 200;

function app_billing_request_bulk_deactivation(PDO $db, array $accountIds, string $reason, array $actor): array
{
    $reason = mb_substr(clean_text($reason), 0, 255);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'reason_required'];
    }
    $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds), static fn (int $id): bool => $id > 0)));
    if ($accountIds === []) {
        return ['ok' => false, 'error' => 'no_accounts_selected'];
    }
    if (count($accountIds) > APP_BILLING_BULK_DEACTIVATION_MAX_ACCOUNTS) {
        return ['ok' => false, 'error' => 'too_many_accounts', 'max' => APP_BILLING_BULK_DEACTIVATION_MAX_ACCOUNTS];
    }

    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $verify = $db->prepare("SELECT id FROM portal_accounts WHERE role = 'customer' AND id IN ({$placeholders})");
    $verify->execute($accountIds);
    $validIds = array_map('intval', $verify->fetchAll(PDO::FETCH_COLUMN));
    if ($validIds === []) {
        return ['ok' => false, 'error' => 'no_valid_customer_accounts'];
    }

    $insert = $db->prepare(
        'INSERT INTO billing_bulk_deactivation_requests (account_ids_json, account_count, reason, status, requested_by_account_id, requested_by_name)
         VALUES (:account_ids_json, :account_count, :reason, "pending", :actor_id, :actor_name)'
    );
    $insert->execute([
        'account_ids_json' => app_db_json_encode($validIds),
        'account_count' => count($validIds),
        'reason' => $reason,
        'actor_id' => (int) ($actor['id'] ?? 0) ?: null,
        'actor_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'admin')),
    ]);
    $id = (int) $db->lastInsertId();

    if (function_exists('app_audit_record')) {
        app_audit_ensure_schema($db);
        try {
            $db->beginTransaction();
            app_audit_record($db, [
                'module_key' => 'billing',
                'operation_class' => 'customer_edit',
                'action_type' => 'bulk_customer_deactivation_requested',
                'entity_type' => 'bulk_request',
                'entity_id' => (string) $id,
                'status' => 'pending',
                'actor_account_id' => (int) ($actor['id'] ?? 0),
                'actor_name' => (string) ($actor['full_name'] ?? $actor['login'] ?? 'admin'),
                'summary' => 'Bulk deactivation requested for ' . count($validIds) . ' account(s)',
                'reason' => $reason,
                'payload' => ['account_ids' => $validIds],
                'sensitivity' => 'critical',
            ]);
            $db->commit();
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }
    }

    return ['ok' => true, 'id' => $id, 'account_count' => count($validIds)];
}

function app_billing_bulk_deactivation_requests(PDO $db, string $status = 'pending'): array
{
    $statement = $db->prepare('SELECT * FROM billing_bulk_deactivation_requests WHERE status = :status ORDER BY created_at DESC LIMIT 50');
    $statement->execute(['status' => $status]);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function app_billing_apply_single_deactivation(PDO $db, int $accountId): bool
{
    $account = app_db_account_by_id($accountId);
    if (!is_array($account) || (string) ($account['role'] ?? '') !== 'customer') {
        return false;
    }
    $db->prepare("UPDATE portal_accounts SET status = 'Deaktiv', is_active = 0 WHERE id = :id")->execute(['id' => $accountId]);
    $updated = app_db_account_by_id($accountId);
    return is_array($updated) && app_billing_sync_radius_account($updated, null, $db);
}

function app_billing_resolve_bulk_deactivation(PDO $db, int $requestId, string $decision, string $resolutionNote, array $actor): array
{
    if (!app_portal_is_admin($actor)) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        return ['ok' => false, 'error' => 'invalid_decision'];
    }
    $resolutionNote = mb_substr(clean_text($resolutionNote), 0, 255);
    $actorId = (int) ($actor['id'] ?? 0);

    $requestStatement = $db->prepare('SELECT * FROM billing_bulk_deactivation_requests WHERE id = :id LIMIT 1');
    $requestStatement->execute(['id' => $requestId]);
    $request = $requestStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($request)) {
        return ['ok' => false, 'error' => 'request_not_found'];
    }
    if ((string) $request['status'] !== 'pending') {
        return ['ok' => false, 'error' => 'request_already_resolved'];
    }
    if ($actorId > 0 && $actorId === (int) ($request['requested_by_account_id'] ?? 0)) {
        return ['ok' => false, 'error' => 'requester_cannot_approve_own_request'];
    }

    $result = ['deactivated' => 0, 'failed' => 0];
    if ($decision === 'approved') {
        $accountIds = app_mgs_json_decode_array($request['account_ids_json'] ?? []);
        foreach ($accountIds as $accountId) {
            if (app_billing_apply_single_deactivation($db, (int) $accountId)) {
                $result['deactivated']++;
            } else {
                $result['failed']++;
            }
        }
    }

    if (function_exists('app_audit_ensure_schema')) {
        app_audit_ensure_schema($db);
    }
    try {
        $db->beginTransaction();
        $update = $db->prepare(
            "UPDATE billing_bulk_deactivation_requests SET status = :status, reviewed_by_account_id = :actor_id,
                reviewed_by_name = :actor_name, resolution_note = :resolution_note, result_json = :result_json, reviewed_at = NOW()
             WHERE id = :id AND status = 'pending'"
        );
        $update->execute([
            'status' => $decision,
            'actor_id' => $actorId ?: null,
            'actor_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'admin')),
            'resolution_note' => $resolutionNote ?: null,
            'result_json' => app_db_json_encode($result),
            'id' => $requestId,
        ]);
        if ($update->rowCount() === 0) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'request_already_resolved'];
        }
        if (function_exists('app_audit_record')) {
            app_audit_record($db, [
                'module_key' => 'billing',
                'operation_class' => 'customer_edit',
                'action_type' => 'bulk_customer_deactivation_' . $decision,
                'entity_type' => 'bulk_request',
                'entity_id' => (string) $requestId,
                'status' => 'executed',
                'actor_account_id' => $actorId,
                'actor_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'admin')),
                'summary' => 'Bulk deactivation request #' . $requestId . ' ' . $decision,
                'reason' => $resolutionNote,
                'payload' => $result,
                'sensitivity' => 'critical',
            ]);
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'request_resolve_failed', 'message' => $exception->getMessage()];
    }

    return ['ok' => true, 'status' => $decision, 'result' => $result];
}
