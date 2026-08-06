<?php
declare(strict_types=1);

require_once __DIR__ . '/analytics-center-lib.php';
require_once __DIR__ . '/billing-module-lib.php';
require_once __DIR__ . '/finance-module-lib.php';
require_once __DIR__ . '/ui-language-lib.php';

function app_statistics_user_is_admin(array $user): bool
{
    $role = strtolower(trim((string) ($user['employee_role'] ?? '')));
    $accountType = strtolower(trim((string) ($user['account_type'] ?? '')));
    return in_array($role, ['admin', 'super_admin'], true)
        || ($role === '' && $accountType === 'admin');
}

function app_statistics_metric_catalog(): array
{
    return [
        'income' => ['label' => 'Gəlir', 'kind' => 'money', 'icon' => 'fa-arrow-trend-up'],
        'expenses' => ['label' => 'Xərclər', 'kind' => 'money', 'icon' => 'fa-receipt'],
        'debts' => ['label' => 'Borc və alacaq', 'kind' => 'money', 'icon' => 'fa-scale-balanced'],
        'customers' => ['label' => 'Yeni müştərilər', 'kind' => 'count', 'icon' => 'fa-users'],
        'terminals' => ['label' => 'Ödəniş kanalları', 'kind' => 'money', 'icon' => 'fa-building-columns'],
        'messages' => ['label' => 'Mesaj kanalları', 'kind' => 'count', 'icon' => 'fa-message'],
        'network' => ['label' => 'Şəbəkə vəziyyəti', 'kind' => 'count', 'icon' => 'fa-network-wired'],
        'bonuses' => ['label' => 'Əməkdaş bonusları', 'kind' => 'money', 'icon' => 'fa-award'],
    ];
}

function app_statistics_allowed_metrics(array $user): array
{
    $catalog = app_statistics_metric_catalog();
    if (app_statistics_user_is_admin($user)) {
        return $catalog;
    }

    $keys = [];
    if (app_portal_can_access_module($user, 'billing')) {
        $keys = array_merge($keys, ['income', 'customers', 'terminals']);
    }
    if (app_portal_can_access_module($user, 'finance')) {
        $keys = array_merge($keys, ['income', 'expenses', 'debts', 'terminals']);
    }
    if (app_portal_can_access_module($user, 'messaging')) {
        $keys[] = 'messages';
    }
    if (app_portal_can_access_module($user, 'network')) {
        $keys[] = 'network';
    }
    if (app_portal_can_access_module($user, 'hr')) {
        $keys[] = 'bonuses';
    }

    $allowed = [];
    foreach (array_unique($keys) as $key) {
        if (isset($catalog[$key])) {
            $allowed[$key] = $catalog[$key];
        }
    }
    return $allowed;
}

function app_statistics_rows(PDO $db, string $sql, array $params = []): array
{
    try {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return array_map(static fn (array $row): array => [
            'label' => trim((string) ($row['label'] ?? '')) ?: 'Digər',
            'value' => max(0.0, (float) ($row['value_total'] ?? $row['value'] ?? 0)),
            'count' => max(0, (int) ($row['row_count'] ?? $row['count'] ?? 0)),
        ], $statement->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable) {
        return [];
    }
}

function app_statistics_normalize_rows(array $rows): array
{
    return array_values(array_map(static fn (array $row): array => [
        'label' => trim((string) ($row['label'] ?? '')) ?: 'Digər',
        'value' => max(0.0, (float) ($row['value'] ?? $row['value_total'] ?? 0)),
        'count' => max(0, (int) ($row['count'] ?? $row['row_count'] ?? 0)),
    ], $rows));
}

function app_statistics_center_context(PDO $db, array $user, array $query = []): array
{
    $dates = app_finance_date_range($query);
    $allowedMetrics = app_statistics_allowed_metrics($user);
    $selectedMetric = clean_text((string) ($query['metric'] ?? 'all'));
    if ($selectedMetric !== 'all' && !isset($allowedMetrics[$selectedMetric])) {
        $selectedMetric = 'all';
    }
    if (empty($dates['valid'])) {
        return [
            'generated_at' => date(DATE_ATOM),
            'range' => (string) ($dates['range'] ?? 'custom'),
            'start_date' => '',
            'end_date' => '',
            'timezone' => (string) ($dates['timezone'] ?? app_timezone()),
            'status' => 'error',
            'error' => (string) ($dates['error'] ?? 'invalid_date_range'),
            'selected_metric' => $selectedMetric,
            'available_metrics' => $allowedMetrics,
            'datasets' => [],
            'kpis' => [],
            'is_admin' => app_statistics_user_is_admin($user),
            'source_notes' => [],
            'data_quality' => [],
        ];
    }

    $params = [
        'start_at' => $dates['start_at'],
        'end_at' => $dates['operational_end_at_exclusive'],
    ];
    $canFinance = app_statistics_user_is_admin($user) || app_portal_can_access_module($user, 'finance');
    $canBilling = app_statistics_user_is_admin($user) || app_portal_can_access_module($user, 'billing');
    $finance = $canFinance ? app_finance_statistics_context($db, $query) : [];
    $billing = $canBilling ? app_billing_statistics_context($db, $query) : [];
    $datasets = [];

    if (isset($allowedMetrics['income'])) {
        $source = $canFinance
            ? (array) ($finance['datasets']['income']['rows'] ?? [])
            : (array) ($billing['datasets']['payments']['rows'] ?? []);
        $datasets['income'] = app_statistics_normalize_rows($source);
    }
    if (isset($allowedMetrics['expenses'])) {
        $datasets['expenses'] = app_statistics_normalize_rows((array) ($finance['datasets']['expenses']['rows'] ?? []));
    }
    if (isset($allowedMetrics['debts'])) {
        $datasets['debts'] = app_statistics_normalize_rows((array) ($finance['datasets']['debts']['rows'] ?? []));
    }
    if (isset($allowedMetrics['customers'])) {
        $customerGuard = app_billing_operational_customer_sql('a');
        $bucket = $dates['range'] === 'year'
            ? 'DATE_FORMAT(a.created_at, "%Y-%m")'
            : 'DATE(a.created_at)';
        $datasets['customers'] = app_statistics_rows(
            $db,
            'SELECT ' . $bucket . ' AS label, COUNT(*) AS value_total, COUNT(*) AS row_count
               FROM portal_accounts a
              WHERE a.role = "customer" AND ' . $customerGuard . '
                AND a.created_at >= :start_at AND a.created_at < :end_at
              GROUP BY ' . $bucket . '
              ORDER BY label',
            $params
        );
    }
    if (isset($allowedMetrics['terminals'])) {
        $source = $canFinance
            ? (array) ($finance['datasets']['payment_methods']['rows'] ?? [])
            : (array) ($billing['datasets']['providers']['rows'] ?? []);
        $datasets['terminals'] = app_statistics_normalize_rows($source);
    }
    if (isset($allowedMetrics['messages'])) {
        $datasets['messages'] = app_statistics_rows(
            $db,
            'SELECT COALESCE(NULLIF(channel, ""), "Digər") AS label,
                    COUNT(*) AS value_total, COUNT(*) AS row_count
               FROM communication_messages
              WHERE created_at >= :start_at AND created_at < :end_at
              GROUP BY COALESCE(NULLIF(channel, ""), "Digər")
              ORDER BY value_total DESC',
            $params
        );
    }
    if (isset($allowedMetrics['network'])) {
        $datasets['network'] = app_statistics_rows(
            $db,
            'SELECT reachability_state AS label, COUNT(*) AS value_total, COUNT(*) AS row_count
               FROM device_learning_profiles
              WHERE updated_at >= :start_at AND updated_at < :end_at
              GROUP BY reachability_state
              ORDER BY value_total DESC',
            $params
        );
    }
    if (isset($allowedMetrics['bonuses']) && app_analytics_table_exists($db, 'employee_bonus_summaries')) {
        $bucket = $dates['range'] === 'year'
            ? 'DATE_FORMAT(p.period_start, "%Y-%m")'
            : 'p.period_start';
        $datasets['bonuses'] = app_statistics_rows(
            $db,
            'SELECT ' . $bucket . ' AS label, COALESCE(SUM(s.total_payable), 0) AS value_total,
                    COUNT(*) AS row_count
               FROM employee_bonus_summaries s
               INNER JOIN bonus_periods p ON p.id = s.period_id
              WHERE p.period_end >= :start_date AND p.period_start <= :end_date
              GROUP BY ' . $bucket . '
              ORDER BY label',
            ['start_date' => $dates['start_date'], 'end_date' => $dates['end_date']]
        );
    }

    foreach ($allowedMetrics as $key => $metric) {
        $allRows = (array) ($datasets[$key] ?? []);
        $totalValue = array_sum(array_column($allRows, 'value'));
        $totalCount = array_sum(array_column($allRows, 'count'));
        $datasets[$key] = [
            'key' => $key,
            'title' => (string) $metric['label'],
            'kind' => (string) $metric['kind'],
            'icon' => (string) $metric['icon'],
            'rows' => array_slice($allRows, 0, 12),
            'total_value' => $totalValue,
            'total_count' => $totalCount,
            'truncated' => count($allRows) > 12,
        ];
    }

    $kpis = [];
    foreach ($datasets as $key => $dataset) {
        $kpis[$key] = [
            'key' => $key,
            'label' => (string) ($dataset['title'] ?? $key),
            'kind' => (string) ($dataset['kind'] ?? 'count'),
            'value' => (float) ($dataset['total_value'] ?? 0),
            'count' => (int) ($dataset['total_count'] ?? 0),
        ];
    }

    return [
        'generated_at' => date(DATE_ATOM),
        'status' => 'ok',
        'range' => $dates['range'],
        'start_date' => $dates['start_date'],
        'end_date' => $dates['end_date'],
        'timezone' => $dates['timezone'],
        'semantic' => $dates['semantic'],
        'as_of_at' => $dates['as_of_at'],
        'selected_metric' => $selectedMetric,
        'available_metrics' => $allowedMetrics,
        'datasets' => $datasets,
        'kpis' => $kpis,
        'is_admin' => app_statistics_user_is_admin($user),
        'data_quality' => [
            'future_billing_payment_rows' => (int) (
                $finance['data_quality']['future_billing_payment_rows']
                ?? $billing['data_quality']['future_billing_payment_rows']
                ?? 0
            ),
            'future_rows_excluded' => (bool) (
                $finance['data_quality']['future_rows_excluded']
                ?? $billing['data_quality']['future_rows_excluded']
                ?? false
            ),
        ],
        'source_notes' => [
            'income' => $canFinance ? 'Billing ödənişləri və təsdiqlənmiş əl ilə daxil edilən gəlirlər.' : 'Billing ödəniş qeydləri.',
            'expenses' => 'Maliyyə modulunda xərc kimi qeydə alınmış əməliyyatlar.',
            'debts' => 'Hazırda açıq və qismən ödənmiş borc/alacaq qalıqları.',
            'customers' => 'Seçilmiş dövrdə yaradılmış əməliyyat müştəri hesabları.',
            'terminals' => 'Ödəniş mənbəyi və provider üzrə daxilolmalar.',
            'messages' => 'Seçilmiş dövrdə kanal üzrə yaradılmış mesajlar.',
            'network' => 'Seçilmiş dövrdə yenilənmiş cihazların son əlçatanlıq vəziyyəti.',
            'bonuses' => 'Seçilmiş dövrlə kəsişən əməkdaş bonus yekunları.',
        ],
    ];
}
