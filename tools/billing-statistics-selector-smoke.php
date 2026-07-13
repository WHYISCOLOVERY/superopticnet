<?php
declare(strict_types=1);

require_once __DIR__ . '/../billing-module-lib.php';

$errors = [];
$expect = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) $errors[] = $message;
};

$view = (string) file_get_contents(__DIR__ . '/../views/billing/statistics.php');
$fragment = (string) file_get_contents(__DIR__ . '/../views/billing/_statistics-results.php');
$page = (string) file_get_contents(__DIR__ . '/../billing-section-page.php');
foreach (['data-billing-statistics', 'data-billing-statistics-form', 'data-billing-statistics-status', 'name="metric"', 'name="range"', 'name="start_date"', 'name="end_date"'] as $marker) {
    $expect(str_contains($view, $marker), 'statistics_view_marker_missing:' . $marker);
}
foreach (['data-billing-statistics-results', 'data-billing-chart', 'data-billing-kpi'] as $marker) {
    $expect(str_contains($fragment, $marker), 'statistics_fragment_marker_missing:' . $marker);
}
foreach (['fetch(', 'AbortController', 'history.pushState', 'popstate', 'loadStatistics', "billing_fragment', 'statistics-results'", "module.addEventListener('change'"] as $marker) {
    $expect(str_contains($page, $marker), 'statistics_ajax_marker_missing:' . $marker);
}

$db = app_db();
if ($db instanceof PDO) {
    $context = app_billing_statistics_context($db, ['range' => 'month']);
    $datasets = array_keys((array) ($context['datasets'] ?? []));
    $expect($datasets === ['payments', 'customers', 'services', 'packages', 'providers'], 'billing_dataset_contract_mismatch');
    foreach (['finance', 'expense', 'debt', 'terminal', 'network', 'radius', 'traffic', 'messages', 'ai', 'hr', 'inventory'] as $foreign) {
        $expect(!in_array($foreign, $datasets, true), 'foreign_dataset_present:' . $foreign);
    }
}

$previousGet = $_GET;
$_GET = ['metric' => 'providers'];
$billingContext = ['query' => $_GET];
$billingStatistics = [
    'kpis' => [],
    'datasets' => ['providers' => ['title' => 'Provider odenisleri', 'kind' => 'money', 'rows' => []]],
];
ob_start();
try {
    require __DIR__ . '/../views/billing/_statistics-results.php';
} catch (Throwable $exception) {
    $errors[] = 'empty_dataset_render_failed:' . $exception->getMessage();
}
ob_end_clean();
$_GET = $previousGet;

echo json_encode(['ok' => $errors === [], 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($errors === [] ? 0 : 1);
