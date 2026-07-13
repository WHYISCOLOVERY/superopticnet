<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$renderer = $root . '/tools/billing-ui-smoke.php';
$errors = [];
$expect = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};
$render = static function (string $page, string $query = '') use ($root, $renderer): array {
    $command = [PHP_BINARY, $renderer, '--render=' . $page];
    if ($query !== '') {
        $command[] = '--query=' . $query;
    }
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $root);
    if (!is_resource($process)) {
        return ['code' => 99, 'html' => '', 'error' => 'render_process_failed'];
    }
    fclose($pipes[0]);
    $html = (string) stream_get_contents($pipes[1]);
    $error = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'html' => $html, 'error' => $error];
};

$pages = [
    'billing_overview' => $render('billing-dashboard.php'),
    'billing_customers' => $render('billing-customers.php'),
    'billing_payments' => $render('billing-payments.php'),
    'billing_statistics' => $render('billing-statistics.php'),
    'finance_overview' => $render('operations-center.php', 'section=finance&finance_tab=overview'),
    'finance_statistics' => $render('operations-center.php', 'section=finance&finance_tab=statistics'),
    'hr_overview' => $render('operations-center.php', 'section=hr&hr_tab=overview'),
    'hr_statistics' => $render('operations-center.php', 'section=hr&hr_tab=statistics'),
    'inventory_overview' => $render('operations-center.php', 'section=inventory&inventory_tab=overview'),
    'inventory_statistics' => $render('operations-center.php', 'section=inventory&inventory_tab=statistics'),
];
foreach ($pages as $name => $result) {
    $expect($result['code'] === 0, 'render_failed:' . $name . ':' . $result['error']);
}

$expect(!str_contains($pages['billing_overview']['html'], 'data-billing-chart'), 'billing_overview_duplicates_statistics_charts');
$expect(str_contains($pages['billing_statistics']['html'], 'data-billing-chart'), 'billing_statistics_chart_missing');
$expect(!str_contains($pages['finance_overview']['html'], 'class="finance-chart"'), 'finance_overview_duplicates_statistics_charts');
$expect(str_contains($pages['finance_statistics']['html'], 'data-finance-stat-chart'), 'finance_statistics_chart_missing');
$expect(!str_contains($pages['hr_overview']['html'], 'data-hr-stat-chart'), 'hr_overview_duplicates_statistics_charts');
$expect(str_contains($pages['hr_statistics']['html'], 'data-hr-stat-chart'), 'hr_statistics_chart_missing');
$expect(!str_contains($pages['inventory_overview']['html'], 'data-inventory-stat-chart'), 'inventory_overview_duplicates_statistics_charts');
$expect(str_contains($pages['inventory_statistics']['html'], 'data-inventory-stat-chart'), 'inventory_statistics_chart_missing');

foreach (['customers' => 'billing_customers', 'payments' => 'billing_payments'] as $section => $pageKey) {
    $html = $pages[$pageKey]['html'];
    $expect(str_contains($html, 'data-billing-legacy-module'), 'billing_shared_chrome_missing:' . $section);
    $expect(str_contains($html, 'data-billing-section="' . $section . '"'), 'billing_active_section_missing:' . $section);
    $expect(!str_contains($html, 'class="billing-tabs"'), 'billing_flat_tabs_still_rendered:' . $section);
}

$paymentsHtml = $pages['billing_payments']['html'];
$expect(str_contains($pages['billing_customers']['html'], '<span>Musteriler</span>'), 'customers_header_title_inconsistent');
$expect(str_contains($paymentsHtml, '<span>Odenisler</span>'), 'payments_header_title_inconsistent');
$expect(!str_contains($paymentsHtml, '> Analitika</a>'), 'payments_duplicate_analytics_link');
$expect(!str_contains($paymentsHtml, 'class="mgs-chart-card"'), 'payments_duplicate_analytics_cards');
$expect(substr_count($paymentsHtml, 'data-payment-kpi') === 4, 'payments_kpi_count_invalid');

foreach ([
    'billing_overview' => 'billing',
    'billing_customers' => 'billing',
    'billing_payments' => 'billing',
    'finance_overview' => 'finance',
    'hr_overview' => 'hr',
    'inventory_overview' => 'inventory',
] as $pageKey => $module) {
    $html = $pages[$pageKey]['html'];
    $groupCount = substr_count($html, 'data-module-nav-group');
    $expect(substr_count($html, 'data-module-group-nav') === 1, $module . '_grouped_navigation_count_invalid:' . $pageKey);
    $expect($groupCount >= 3 && $groupCount <= 5, $module . '_group_count_invalid:' . $groupCount);
}
foreach (['finance_overview' => 'finance-tabs', 'hr_overview' => 'hr-tabs', 'inventory_overview' => 'inventory-tabs'] as $pageKey => $flatClass) {
    $expect(!str_contains($pages[$pageKey]['html'], 'class="' . $flatClass . '"'), 'flat_module_tabs_still_rendered:' . $pageKey);
}

foreach (['billing', 'finance', 'hr', 'inventory'] as $module) {
    $source = (string) file_get_contents($root . '/views/' . $module . '/module.php');
    $expect(str_contains($source, 'overflow-wrap:anywhere'), $module . '_kpi_overflow_wrap_missing');
    $expect(str_contains($source, 'min-width:0'), $module . '_kpi_min_width_missing');
}

$financeLibrary = (string) file_get_contents($root . '/finance-module-lib.php');
$expect(str_contains($financeLibrary, 'function app_finance_overview_kpis'), 'finance_overview_kpi_query_missing');
$overviewBranchStart = strpos($financeLibrary, "if (\$tab === 'overview')");
$overviewBranchEnd = $overviewBranchStart !== false ? strpos($financeLibrary, "} elseif (\$tab === 'invoices')", $overviewBranchStart) : false;
$overviewBranch = $overviewBranchStart !== false && $overviewBranchEnd !== false
    ? substr($financeLibrary, $overviewBranchStart, $overviewBranchEnd - $overviewBranchStart)
    : '';
$expect(!str_contains($overviewBranch, 'app_finance_statistics_context'), 'finance_overview_still_builds_statistics_datasets');

$billingLibrary = (string) file_get_contents($root . '/billing-module-lib.php');
$billingOverviewStart = strpos($billingLibrary, 'function app_billing_overview_context');
$billingOverviewEnd = $billingOverviewStart !== false ? strpos($billingLibrary, 'function app_billing_url', $billingOverviewStart) : false;
$billingOverviewSource = $billingOverviewStart !== false && $billingOverviewEnd !== false
    ? substr($billingLibrary, $billingOverviewStart, $billingOverviewEnd - $billingOverviewStart)
    : '';
$expect(!str_contains($billingOverviewSource, 'app_billing_statistics_context'), 'billing_overview_still_builds_statistics_datasets');

if ($errors !== []) {
    echo json_encode(['status' => 'failed', 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

echo json_encode([
    'status' => 'passed',
    'message' => 'Finance, HR, Inventory and Billing UI ownership and overflow contract passed.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
