<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/analytics-center-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$db = app_db();
if (!$db instanceof PDO) {
    echo json_encode(['status' => 'failed', 'errors' => ['db_unavailable']], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$errors = [];
$expect = static function (bool $condition, string $error) use (&$errors): void {
    if (!$condition) {
        $errors[] = $error;
    }
};

$context = app_analytics_center_context($db, [
    'id' => 1,
    'role' => 'employee',
    'account_type' => 'admin',
    'employee_role' => 'super_admin',
]);

$expect(isset($context['generated_at']) && is_string($context['generated_at']), 'generated_at_missing');
$expect(isset($context['tables']) && is_array($context['tables']) && count($context['tables']) >= 5, 'tables_snapshot_missing');
$expect(isset($context['kpis']) && is_array($context['kpis']) && count($context['kpis']) >= 5, 'kpi_snapshot_missing');
$expect(isset($context['charts']) && is_array($context['charts']), 'charts_missing');

foreach (['monthly_revenue', 'provider_revenue', 'radius_risk', 'bonus_periods', 'message_channels'] as $chartKey) {
    $expect(array_key_exists($chartKey, $context['charts']), 'chart_missing:' . $chartKey);
    $expect(is_array($context['charts'][$chartKey]), 'chart_not_array:' . $chartKey);
}

$readyStatuses = array_column((array) $context['tables'], 'status');
$expect(in_array('ready', $readyStatuses, true), 'no_ready_table_detected');
$expect(isset($context['source_notes']) && is_array($context['source_notes']) && count($context['source_notes']) >= 3, 'source_notes_missing');
$expect(isset($context['feature_flags']) && is_array($context['feature_flags']), 'feature_flags_missing');

echo json_encode(['status' => $errors === [] ? 'passed' : 'failed', 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($errors === [] ? 0 : 1);
