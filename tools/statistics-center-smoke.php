<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/statistics-center-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$db = app_db();
if (!$db instanceof PDO) {
    echo json_encode(['status' => 'failed', 'errors' => ['db_unavailable']], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$root = dirname(__DIR__);
$errors = [];
$expect = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$required = [
    'statistics-center.php',
    'statistics-center-partial.php',
    'statistics-center-lib.php',
    'views/statistics/module.php',
    'views/statistics/_results.php',
    'css/statistics-center.css',
    'script/statistics-center.js',
];
foreach ($required as $file) {
    $expect(is_file($root . '/' . $file), 'missing_file:' . $file);
}

foreach (['statistics-center.php', 'statistics-center-partial.php'] as $entryPoint) {
    $entrySource = is_file($root . '/' . $entryPoint)
        ? (string) file_get_contents($root . '/' . $entryPoint)
        : '';
    $expect(
        str_contains($entrySource, "billing_require_employee(false, 'statistics')"),
        'statistics_access_gate_missing:' . $entryPoint
    );
}

$admin = ['id' => 1, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'super_admin'];
$adminContext = app_statistics_center_context($db, $admin, [
    'metric' => 'terminals',
    'range' => 'custom',
    'start_date' => '2026-07-13',
    'end_date' => '2026-07-01',
]);
$expect(($adminContext['selected_metric'] ?? '') === 'terminals', 'admin_metric_not_preserved');
$expect(($adminContext['start_date'] ?? '') === '2026-07-01', 'custom_start_not_normalized');
$expect(($adminContext['end_date'] ?? '') === '2026-07-13', 'custom_end_not_normalized');
foreach (array_keys(app_statistics_metric_catalog()) as $metric) {
    $expect(isset($adminContext['available_metrics'][$metric]), 'admin_metric_missing:' . $metric);
    $expect(isset($adminContext['datasets'][$metric]), 'admin_dataset_missing:' . $metric);
    foreach ((array) ($adminContext['datasets'][$metric]['rows'] ?? []) as $row) {
        foreach (['label', 'value', 'count'] as $field) {
            $expect(array_key_exists($field, $row), 'dataset_field_missing:' . $metric . ':' . $field);
        }
    }
}

$operator = ['role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'operator'];
$operatorMetrics = array_keys(app_statistics_allowed_metrics($operator));
foreach (['income', 'customers', 'terminals', 'messages'] as $metric) {
    $expect(in_array($metric, $operatorMetrics, true), 'operator_metric_missing:' . $metric);
}
foreach (['expenses', 'debts', 'network', 'bonuses'] as $metric) {
    $expect(!in_array($metric, $operatorMetrics, true), 'operator_metric_leak:' . $metric);
}
$operatorContext = app_statistics_center_context($db, $operator, ['metric' => 'expenses']);
$expect(($operatorContext['selected_metric'] ?? '') === 'all', 'disallowed_metric_not_normalized');

$noc = ['role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'noc'];
$expect(array_keys(app_statistics_allowed_metrics($noc)) === ['messages', 'network'], 'noc_metric_contract_mismatch');
$hr = ['role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'hr'];
$expect(array_keys(app_statistics_allowed_metrics($hr)) === ['bonuses'], 'hr_metric_contract_mismatch');

$catalog = app_portal_module_catalog('az');
$expect(($catalog['statistics']['href'] ?? '') === 'statistics-center.php', 'statistics_portal_route_not_canonical');
$expect(app_portal_request_module('statistics-center.php', []) === 'statistics', 'statistics_page_permission_missing');
$expect(app_portal_request_module('statistics-center-partial.php', []) === 'statistics', 'statistics_partial_permission_missing');

$script = is_file($root . '/script/statistics-center.js') ? (string) file_get_contents($root . '/script/statistics-center.js') : '';
foreach (['statistics-center-partial.php', 'statistics_fragment', 'AbortController', 'replaceState', '[data-statistics-date]'] as $marker) {
    $expect(str_contains($script, $marker), 'statistics_ajax_marker_missing:' . $marker);
}
$resultsView = is_file($root . '/views/statistics/_results.php') ? (string) file_get_contents($root . '/views/statistics/_results.php') : '';
foreach (['data-statistics-results', 'data-statistics-chart', '<svg', 'statistics-ring-segment', 'visibleKpis'] as $marker) {
    $expect(str_contains($resultsView, $marker), 'statistics_view_marker_missing:' . $marker);
}

$process = proc_open(
    [PHP_BINARY, $root . '/tools/billing-ui-smoke.php', '--render=statistics-center.php', '--query=metric=income&range=week'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root
);
if (is_resource($process)) {
    fclose($pipes[0]);
    $html = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $expect($code === 0, 'statistics_render_failed:' . $stderr);
    $expect(str_contains($html, 'data-statistics-module'), 'statistics_module_marker_missing');
    $expect(str_contains($html, 'data-statistics-results'), 'statistics_results_marker_missing');
    $expect(substr_count($html, 'data-statistics-chart') === 1, 'single_metric_renders_multiple_charts');
} else {
    $expect(false, 'statistics_render_process_failed');
}

echo json_encode([
    'status' => $errors === [] ? 'passed' : 'failed',
    'errors' => $errors,
    'admin_metrics' => array_keys((array) ($adminContext['available_metrics'] ?? [])),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($errors === [] ? 0 : 1);
