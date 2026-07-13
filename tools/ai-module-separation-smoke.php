<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/billing-ui.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$errors = [];
$expect = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$expectedSections = [
    'overview',
    'copilot',
    'customer-analytics',
    'network-analytics',
    'finance-analytics',
    'risks',
    'recommendations',
    'automations',
    'audit',
    'model-status',
];
$routes = [
    'overview' => 'ai-dashboard.php',
    'copilot' => 'ai-copilot.php',
    'customer-analytics' => 'ai-customer-analytics.php',
    'network-analytics' => 'ai-network-analytics.php',
    'finance-analytics' => 'ai-finance-analytics.php',
    'risks' => 'ai-risks.php',
    'recommendations' => 'ai-recommendations.php',
    'automations' => 'ai-automations.php',
    'audit' => 'ai-audit.php',
    'model-status' => 'ai-model-status.php',
];

$requiredFiles = [
    'ai-module-lib.php',
    'ai-section-page.php',
    'ai-module-partial.php',
    'views/ai/module.php',
    'views/ai/_pagination.php',
];
foreach ($routes as $route) {
    $requiredFiles[] = $route;
}
foreach ($expectedSections as $section) {
    $requiredFiles[] = 'views/ai/' . $section . '.php';
}
foreach ($requiredFiles as $file) {
    $expect(is_file($root . '/' . $file), 'missing_file:' . $file);
}

if (is_file($root . '/ai-module-lib.php')) {
    require_once $root . '/ai-module-lib.php';
}

$librarySource = is_file($root . '/ai-module-lib.php') ? (string) file_get_contents($root . '/ai-module-lib.php') : '';
$expect(!str_contains($librarySource, 'app_mgs_summary('), 'ai_context_uses_cross_domain_mgs_summary');
foreach (['inventory_stock_items', 'employee_work_snapshots', 'billing_payments', 'FROM finance_', 'JOIN finance_'] as $foreignTable) {
    $expect(!str_contains($librarySource, $foreignTable), 'ai_context_contains_foreign_table:' . $foreignTable);
}

foreach (['app_ai_sections', 'app_ai_section', 'app_ai_pagination', 'app_ai_build_context'] as $functionName) {
    $expect(function_exists($functionName), 'missing_function:' . $functionName);
}
if (function_exists('app_ai_sections')) {
    $expect(array_keys(app_ai_sections()) === $expectedSections, 'ai_section_contract_mismatch');
}
if (function_exists('app_ai_section')) {
    $expect(app_ai_section([]) === 'overview', 'ai_default_section_not_overview');
    $expect(app_ai_section(['ai_section' => 'invalid']) === 'overview', 'ai_invalid_section_not_normalized');
    $expect(app_ai_section(['ai_section' => 'audit']) === 'audit', 'ai_selected_section_not_preserved');
}
if (function_exists('app_ai_pagination')) {
    $pagination = app_ai_pagination(['page' => 3, 'limit' => 500]);
    $expect(($pagination['page'] ?? 0) === 3, 'ai_page_not_preserved');
    $expect(($pagination['limit'] ?? 0) === 50, 'ai_limit_not_capped');
    $expect(($pagination['offset'] ?? -1) === 100, 'ai_offset_invalid');
}

if (function_exists('app_ai_build_context')) {
    $db = app_db();
    $user = ['id' => 1, 'role' => 'employee', 'employee_role' => 'admin'];
    foreach (['automations', 'audit', 'risks'] as $section) {
        $context = app_ai_build_context($db, $user, $section, ['page' => 1, 'limit' => 500]);
        $page = (array) ($context['data']['page'] ?? []);
        $expect(!isset($page['error']), 'ai_query_failed:' . $section . ':' . (string) ($page['error'] ?? ''));
        $expect(count((array) ($page['rows'] ?? [])) <= 50, 'ai_page_exceeds_50:' . $section);
        $expect((int) ($page['limit'] ?? 0) === 50, 'ai_query_limit_not_capped:' . $section);
    }
}

$catalog = app_portal_module_catalog('az');
$expect(($catalog['ai']['href'] ?? '') === 'ai-dashboard.php', 'ai_portal_landing_not_canonical');
foreach ($routes as $section => $route) {
    $expect(app_portal_request_module($route, []) === 'ai', 'ai_route_permission_missing:' . $section);
}

$pageSource = is_file($root . '/ai-section-page.php') ? (string) file_get_contents($root . '/ai-section-page.php') : '';
$scriptSource = is_file($root . '/script/ai-center.js') ? (string) file_get_contents($root . '/script/ai-center.js') : '';
$ajaxSource = $pageSource . "\n" . $scriptSource;
foreach (['fetch(', 'AbortController', 'history.pushState', 'popstate'] as $marker) {
    $expect(str_contains($ajaxSource, $marker), 'ai_ajax_marker_missing:' . $marker);
}
foreach (['[data-ai-nav]', 'sectionFromUrl', 'updateShell', 'dataset.aiSection'] as $marker) {
    $expect(str_contains($ajaxSource, $marker), 'ai_nav_ajax_marker_missing:' . $marker);
}
$partialSource = is_file($root . '/ai-module-partial.php') ? (string) file_get_contents($root . '/ai-module-partial.php') : '';
$expect(str_contains($partialSource, "billing_require_employee(false, 'ai')"), 'ai_partial_permission_missing');
$expect(str_contains($partialSource, 'app_ai_section_allowed'), 'ai_partial_section_permission_missing');

if (function_exists('app_ai_visible_sections')) {
    $supportSections = app_ai_visible_sections(['role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'support']);
    foreach (['automations', 'audit', 'model-status', 'finance-analytics'] as $hiddenSection) {
        $expect(!isset($supportSections[$hiddenSection]), 'ai_support_sees_restricted_section:' . $hiddenSection);
    }
    foreach (['overview', 'copilot', 'customer-analytics', 'network-analytics', 'risks', 'recommendations'] as $visibleSection) {
        $expect(isset($supportSections[$visibleSection]), 'ai_support_missing_section:' . $visibleSection);
    }
}

$moduleSource = is_file($root . '/views/ai/module.php') ? (string) file_get_contents($root . '/views/ai/module.php') : '';
foreach (['data-ai-command-strip', 'data-ai-title', 'data-ai-kicker', 'Superpilot sorus', 'Musteri analizi', 'Sebeke analizi', 'Risk novbesi'] as $marker) {
    $expect(str_contains($moduleSource, $marker), 'ai_module_command_marker_missing:' . $marker);
}

$legacySource = (string) file_get_contents($root . '/mgs-copilot-context.php');
$expect(str_contains($legacySource, 'ai-network-analytics.php'), 'ai_legacy_html_redirect_missing');
$expect(str_contains($legacySource, 'format=json'), 'ai_legacy_json_contract_missing');

$render = static function (string $page) use ($root): array {
    $process = proc_open(
        [PHP_BINARY, $root . '/tools/billing-ui-smoke.php', '--render=' . $page],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
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
foreach (['ai-dashboard.php', 'ai-customer-analytics.php', 'ai-automations.php', 'ai-audit.php', 'ai-model-status.php'] as $page) {
    $result = $render($page);
    $expect($result['code'] === 0, 'ai_render_failed:' . $page . ':' . $result['error']);
    $expect(str_contains($result['html'], 'data-ai-module'), 'ai_module_marker_missing:' . $page);
}
$overview = $render('ai-dashboard.php');
$expect(substr_count($overview['html'], 'data-ai-kpi') <= 6, 'ai_overview_exceeds_6_kpis');
$expect(substr_count($overview['html'], 'data-ai-chart') <= 3, 'ai_overview_exceeds_3_charts');

if ($errors !== []) {
    echo json_encode(['status' => 'failed', 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

echo json_encode([
    'status' => 'passed',
    'sections' => $expectedSections,
    'message' => 'AI module routes, pagination, permissions, AJAX and compatibility smoke passed.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
