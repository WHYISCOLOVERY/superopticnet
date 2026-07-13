<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/billing-ui.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$root = dirname(__DIR__);
$mode = (string) ($_SERVER['argv'][1] ?? 'all');
$errors = [];
$expectedSections = [
    'overview',
    'customers',
    'packages',
    'balances',
    'payments',
    'services',
    'providers',
    'documents',
    'reports',
    'statistics',
];
$sectionRoutes = [
    'overview' => ['billing-dashboard.php', 'data-billing-overview'],
    'packages' => ['billing-packages.php', 'data-billing-packages'],
    'balances' => ['billing-balances.php', 'data-billing-balances'],
    'services' => ['billing-services.php', 'data-billing-services'],
    'providers' => ['billing-providers.php', 'data-billing-providers'],
    'documents' => ['billing-documents.php', 'data-billing-documents'],
    'reports' => ['billing-reports.php', 'data-billing-reports'],
    'statistics' => ['billing-statistics.php', 'data-billing-statistics'],
];

$expect = static function (bool $condition, string $error) use (&$errors): void {
    if (!$condition) {
        $errors[] = $error;
    }
};

$render = static function (string $page, string $query = '') use ($root): array {
    $arguments = [PHP_BINARY, $root . '/tools/billing-ui-smoke.php', '--render=' . $page];
    if ($query !== '') {
        $arguments[] = '--query=' . $query;
    }
    $process = proc_open($arguments, [
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
    return ['exit_code' => proc_close($process), 'html' => $html, 'stderr' => $stderr];
};

$requiredFiles = ['billing-module-lib.php'];
if ($mode !== '--queries') {
    $requiredFiles = array_merge($requiredFiles, [
    'billing-section-page.php',
    'billing-module-partial.php',
    'billing-packages.php',
    'billing-balances.php',
    'billing-services.php',
    'billing-providers.php',
    'billing-documents.php',
    'billing-reports.php',
    'views/billing/module.php',
    'views/billing/overview.php',
    'views/billing/packages.php',
    'views/billing/balances.php',
    'views/billing/services.php',
    'views/billing/providers.php',
    'views/billing/documents.php',
    'views/billing/reports.php',
    'views/billing/statistics.php',
    'views/billing/_pagination.php',
    'views/billing/_statistics-results.php',
    ]);
}
foreach ($requiredFiles as $file) {
    $expect(is_file($root . '/' . $file), 'missing_file:' . $file);
}

$libraryPath = $root . '/billing-module-lib.php';
if (is_file($libraryPath)) {
    require_once $libraryPath;
}

foreach ([
    'app_billing_sections',
    'app_billing_section',
    'app_billing_pagination',
    'app_billing_package_page',
    'app_billing_balance_page',
    'app_billing_service_page',
    'app_billing_provider_page',
    'app_billing_document_page',
    'app_billing_report_context',
    'app_billing_statistics_context',
    'app_billing_overview_context',
    'app_billing_build_context',
] as $functionName) {
    $expect(function_exists($functionName), 'missing_function:' . $functionName);
}

if (function_exists('app_billing_sections')) {
    $expect(array_keys(app_billing_sections()) === $expectedSections, 'billing_section_contract_mismatch');
}
if (function_exists('app_billing_section')) {
    $expect(app_billing_section([]) === 'overview', 'billing_default_section_not_overview');
    $expect(app_billing_section(['billing_section' => 'invalid']) === 'overview', 'billing_invalid_section_not_normalized');
    $expect(app_billing_section(['billing_section' => 'services']) === 'services', 'billing_selected_section_not_preserved');
}
if (function_exists('app_billing_pagination')) {
    $pagination = app_billing_pagination(['page' => 3, 'limit' => 500]);
    $expect(($pagination['page'] ?? 0) === 3, 'billing_page_not_preserved');
    $expect(($pagination['limit'] ?? 0) === 50, 'billing_limit_not_capped');
    $expect(($pagination['offset'] ?? -1) === 100, 'billing_offset_invalid');
}

if (($mode === '--queries' || $mode === 'all') && function_exists('app_billing_build_context')) {
    $db = app_db();
    $user = ['id' => 1, 'role' => 'employee', 'employee_role' => 'admin'];
    foreach (['packages', 'balances', 'services', 'providers', 'documents'] as $section) {
        $context = app_billing_build_context($db, $user, $section, ['page' => 1, 'limit' => 50]);
        $page = (array) ($context['data']['page'] ?? []);
        $expect(!isset($page['error']), 'billing_query_failed:' . $section . ':' . (string) ($page['error'] ?? ''));
        $expect(count((array) ($page['rows'] ?? [])) <= 50, 'billing_page_exceeds_50:' . $section);
    }
    $statistics = app_billing_statistics_context($db, ['range' => 'month']);
    $datasets = array_keys((array) ($statistics['datasets'] ?? []));
    foreach (['finance', 'expense', 'hr', 'inventory', 'network', 'radius', 'traffic', 'messages', 'ai'] as $forbidden) {
        $expect(!in_array($forbidden, $datasets, true), 'billing_statistics_contains_' . $forbidden);
    }
    foreach ((array) ($statistics['datasets'] ?? []) as $key => $dataset) {
        $expect(count((array) ($dataset['rows'] ?? [])) <= 12, 'billing_dataset_exceeds_12:' . $key);
    }
}

if ($mode !== '--queries') {
    foreach ($sectionRoutes as $section => [$page, $marker]) {
        $result = $render($page);
        $expect($result['exit_code'] === 0, 'billing_render_failed:' . $section . ':' . $result['stderr']);
        $expect(str_contains($result['html'], 'data-billing-module'), 'billing_module_marker_missing:' . $section);
        $expect(str_contains($result['html'], 'data-billing-section="' . $section . '"'), 'billing_section_marker_missing:' . $section);
        $expect(str_contains($result['html'], $marker), 'billing_content_marker_missing:' . $section);
        $expect(substr_count($result['html'], 'data-billing-row') <= 50, 'billing_render_exceeds_50:' . $section);
    }

    $overview = $render('billing-dashboard.php');
    foreach (['Online sessiyalar', 'NOC cihaz paneli', 'CRM ve avto bilet axini', 'AI Revenue Optimization', 'Billing maintenance', 'Top trafik'] as $foreignHeading) {
        $expect(!str_contains($overview['html'], $foreignHeading), 'billing_overview_contains_foreign_block:' . $foreignHeading);
    }
    $expect(substr_count($overview['html'], 'data-billing-kpi') <= 6, 'billing_overview_exceeds_6_kpis');
    $expect(substr_count($overview['html'], 'data-billing-chart') <= 3, 'billing_overview_exceeds_3_charts');

    $navSource = (string) file_get_contents($root . '/billing-ui.php');
    foreach (['billing-dashboard.php', 'billing-customers.php', 'billing-packages.php', 'billing-balances.php', 'billing-payments.php', 'billing-services.php', 'billing-providers.php', 'billing-documents.php', 'billing-reports.php', 'billing-statistics.php'] as $route) {
        $expect(str_contains($navSource, $route), 'billing_nav_route_missing:' . $route);
    }
    foreach (['billing-requests.php', 'radius-active.php', 'noc-devices.php', 'operations-center.php?section=finance'] as $foreignRoute) {
        $activeNav = strstr($navSource, 'return [') ?: $navSource;
        $activeNav = strstr($activeNav, '];', true) ?: $activeNav;
        $expect(!str_contains($activeNav, $foreignRoute), 'billing_nav_contains_foreign_route:' . $foreignRoute);
    }

    $partialSource = is_file($root . '/billing-module-partial.php') ? (string) file_get_contents($root . '/billing-module-partial.php') : '';
    $expect(str_contains($partialSource, "billing_require_employee(false, 'billing')"), 'billing_partial_permission_missing');
    $pageSource = is_file($root . '/billing-section-page.php') ? (string) file_get_contents($root . '/billing-section-page.php') : '';
    foreach (['fetch(', 'AbortController', 'history.pushState', 'popstate'] as $ajaxMarker) {
        $expect(str_contains($pageSource, $ajaxMarker), 'billing_ajax_marker_missing:' . $ajaxMarker);
    }
}

if ($errors !== []) {
    echo json_encode(['status' => 'failed', 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

echo json_encode([
    'status' => 'passed',
    'sections' => $expectedSections,
    'message' => 'Billing module separation, pagination, permissions, AJAX and statistics smoke passed.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
