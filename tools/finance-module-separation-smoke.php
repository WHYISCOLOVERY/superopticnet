<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/billing-ui.php';
require_once dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$root = dirname(__DIR__);
$errors = [];
$mode = (string) ($_SERVER['argv'][1] ?? 'all');
$expectedTabs = [
    'overview',
    'invoices',
    'payments',
    'income',
    'companies',
    'expenses',
    'cash-bank',
    'reconciliation',
    'assets',
    'employee-bonuses',
    'statistics',
];

$expect = static function (bool $condition, string $error) use (&$errors): void {
    if (!$condition) {
        $errors[] = $error;
    }
};

$render = static function (string $query) use ($root): array {
    $command = [
        PHP_BINARY,
        $root . '/tools/billing-ui-smoke.php',
        '--render=operations-center.php',
        '--query=' . $query,
    ];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $root);
    if (!is_resource($process)) {
        return ['exit_code' => 99, 'html' => '', 'stderr' => 'render_process_failed'];
    }

    fclose($pipes[0]);
    $html = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'html' => $html,
        'stderr' => $stderr,
    ];
};

$requiredFiles = ['finance-module-lib.php'];
if ($mode !== '--queries') {
    $requiredFiles[] = 'finance-module-partial.php';
    $requiredFiles[] = 'views/finance/module.php';
    foreach ($expectedTabs as $tab) {
        $requiredFiles[] = 'views/finance/' . $tab . '.php';
    }
}
foreach ($requiredFiles as $file) {
    $expect(is_file($root . '/' . $file), 'missing_file:' . $file);
}

$libraryPath = $root . '/finance-module-lib.php';
if (is_file($libraryPath)) {
    require_once $libraryPath;
}

foreach ([
    'app_finance_tabs',
    'app_finance_tab',
    'app_finance_pagination',
    'app_finance_payment_page',
    'app_finance_statistics_context',
    'app_finance_build_context',
] as $functionName) {
    $expect(function_exists($functionName), 'missing_function:' . $functionName);
}

if (function_exists('app_finance_tabs')) {
    $expect(array_keys(app_finance_tabs()) === $expectedTabs, 'finance_tab_contract_mismatch');
}
if (function_exists('app_finance_tab')) {
    $expect(app_finance_tab([]) === 'overview', 'finance_default_tab_not_overview');
    $expect(app_finance_tab(['finance_tab' => 'invalid']) === 'overview', 'finance_invalid_tab_not_normalized');
    $expect(app_finance_tab(['finance_tab' => 'payments']) === 'payments', 'finance_selected_tab_not_preserved');
}
if (function_exists('app_finance_pagination')) {
    $page = app_finance_pagination(['page' => '3', 'limit' => '500']);
    $expect(($page['page'] ?? 0) === 3, 'finance_page_not_preserved');
    $expect(($page['limit'] ?? 0) === 50, 'finance_limit_not_capped_at_50');
    $expect(($page['offset'] ?? -1) === 100, 'finance_offset_is_wrong');
}

if ($mode === '--queries' || $mode === 'all') {
    $financeLibrarySource = is_file($libraryPath) ? (string) file_get_contents($libraryPath) : '';
    $expect(!str_contains($financeLibrarySource, 'DATE(p.created_at)'), 'finance_payment_filter_disables_created_at_index');
    $expect(!str_contains($financeLibrarySource, 'DATE(created_at) BETWEEN'), 'finance_statistics_filter_disables_created_at_index');

    $db = app_db();
    if ($db instanceof PDO && function_exists('app_finance_payment_page')) {
        $first = app_finance_payment_page($db, ['page' => '1', 'limit' => '50']);
        $second = app_finance_payment_page($db, ['page' => '2', 'limit' => '50']);
        $expect(count((array) ($first['rows'] ?? [])) <= 50, 'finance_payment_page_exceeds_50');
        $expect((int) ($first['limit'] ?? 0) === 50, 'finance_payment_page_limit_mismatch');
        $expect((int) ($first['total'] ?? 0) >= count((array) ($first['rows'] ?? [])), 'finance_payment_total_is_invalid');
        if ((int) ($first['total'] ?? 0) > 50) {
            $firstId = (string) ($first['rows'][0]['id'] ?? '');
            $secondId = (string) ($second['rows'][0]['id'] ?? '');
            $expect($firstId !== '' && $secondId !== '' && $firstId !== $secondId, 'finance_payment_offset_not_applied');
        }
    }

    if ($db instanceof PDO && function_exists('app_finance_statistics_context')) {
        $statistics = app_finance_statistics_context($db, [
            'start_date' => date('Y-m-d', strtotime('-6 days')),
            'end_date' => date('Y-m-d'),
            'report_type' => 'overview',
        ]);
        $datasetKeys = array_keys((array) ($statistics['datasets'] ?? []));
        $expect($datasetKeys !== [], 'finance_statistics_datasets_empty');
        foreach (['radius', 'traffic', 'customers', 'packages', 'auth'] as $forbiddenKey) {
            $expect(!in_array($forbiddenKey, $datasetKeys, true), 'finance_statistics_contains_' . $forbiddenKey);
        }
        $yearStatistics = app_finance_statistics_context($db, ['range' => 'year']);
        foreach ((array) ($yearStatistics['datasets'] ?? []) as $datasetKey => $dataset) {
            $expect(count((array) ($dataset['rows'] ?? [])) <= 12, 'finance_year_dataset_exceeds_12_buckets:' . $datasetKey);
        }
    }
}

if ($mode !== '--queries') {
    $overview = $render('section=finance&finance_tab=overview');
    $expect($overview['exit_code'] === 0, 'finance_overview_render_failed:' . $overview['stderr']);
    $expect(str_contains($overview['html'], 'data-finance-module'), 'finance_module_marker_missing');
    $expect(str_contains($overview['html'], 'data-finance-tab="overview"'), 'finance_overview_marker_missing');
    $expect(str_contains($overview['html'],'open=income-create')&&str_contains($overview['html'],'Yeni gəlir'),'finance_income_quick_action_missing');
    $expect(str_contains($overview['html'],'open=counterparty-create')&&str_contains($overview['html'],'Yeni qarşı tərəf'),'finance_counterparty_quick_action_missing');
    foreach ([
        'name="action" value="create_finance_entry"',
        'name="action" value="save_asset_depreciation"',
        'name="action" value="create_finance_debt_record"',
        'name="action" value="record_finance_debt_payment"',
        'name="action" value="import_finance_e_invoice"',
    ] as $forbiddenMarker) {
        $expect(!str_contains($overview['html'], $forbiddenMarker), 'finance_overview_contains_workflow:' . $forbiddenMarker);
    }

    $tabMarkers = [
        'invoices' => 'data-finance-invoices',
        'payments' => 'data-finance-payments',
        'income' => 'data-finance-income',
        'companies' => 'data-finance-companies',
        'expenses' => 'data-finance-expenses',
        'cash-bank' => 'data-finance-cash-bank',
        'reconciliation' => 'data-finance-reconciliation',
        'assets' => 'data-finance-assets',
        'employee-bonuses' => 'data-finance-employee-bonuses',
        'statistics' => 'data-finance-statistics-panel',
    ];
    foreach ($tabMarkers as $tab => $marker) {
        $result = $render('section=finance&finance_tab=' . rawurlencode($tab));
        $expect($result['exit_code'] === 0, 'finance_tab_render_failed:' . $tab . ':' . $result['stderr']);
        $expect(str_contains($result['html'], $marker), 'finance_tab_marker_missing:' . $tab);
        $expect(substr_count($result['html'], 'data-finance-row') <= 50, 'finance_tab_exceeds_50_rows:' . $tab);
        if ($tab === 'income') {
            $expect(str_contains($result['html'], 'name="action" value="create_finance_income"'), 'finance_income_create_action_missing');
        }
        if ($tab === 'expenses') {
            $expect(str_contains($result['html'], 'name="employee_user_id"'), 'finance_expense_employee_context_missing');
            foreach (['Role Agent ', 'Copilot Texnik ', 'Smoke Password User', 'Telegram Smoke Operator'] as $testEmployee) {
                $expect(!str_contains($result['html'], $testEmployee), 'finance_expense_exposes_test_employee:' . $testEmployee);
            }
        }
        if ($tab === 'statistics') {
            $expect(substr_count($result['html'], 'data-finance-stat-chart') <= 4, 'finance_statistics_exceeds_4_charts');
        }
    }

    $report = $render('section=reports&module=finance&report_type=invoices');
    $expect($report['exit_code'] === 0, 'finance_report_context_render_failed');
    $expect(str_contains($report['html'], 'finance_tab=statistics'), 'finance_report_statistics_route_missing');
    $expect(str_contains($report['html'], 'report_type=invoices'), 'finance_report_type_not_preserved');
}

if ($mode === '--ajax' || $mode === 'all') {
    $operationsSource = (string) file_get_contents($root . '/operations-center.php');
    $partialSource = is_file($root . '/finance-module-partial.php')
        ? (string) file_get_contents($root . '/finance-module-partial.php')
        : '';
    $statisticsSource = is_file($root . '/finance-module-lib.php')
        ? (string) file_get_contents($root . '/finance-module-lib.php')
        : '';

    foreach (['AbortController', 'history.pushState', 'popstate', 'data-finance-module'] as $marker) {
        $expect(str_contains($operationsSource, $marker), 'finance_ajax_marker_missing:' . $marker);
    }
    $expect(str_contains($partialSource, "billing_require_employee(false, 'finance')"), 'finance_partial_permission_guard_missing');
    $expect(!preg_match('/\b(radacct|radpostauth|billing_radius_services)\b/i', $statisticsSource), 'finance_statistics_queries_non_finance_domain');
}

if ($errors !== []) {
    echo json_encode([
        'status' => 'failed',
        'errors' => $errors,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

echo json_encode([
    'status' => 'passed',
    'tabs' => $expectedTabs,
    'message' => 'Finance module separation, pagination, AJAX and statistics context smoke passed.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
