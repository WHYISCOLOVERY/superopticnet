<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/ui-language-lib.php';

$errors = [];
$expect = static function (bool $condition, string $error) use (&$errors): void {
    if (!$condition) {
        $errors[] = $error;
    }
};

$expect(app_ui_status('draft') === 'Qaralama', 'draft_status_label_wrong');
$expect(app_ui_status('pending_approval') === 'Tesdiq gozleyir', 'pending_approval_status_label_wrong');
$expect(app_ui_status('planned') === 'Planlanib', 'planned_status_label_wrong');
$expect(app_ui_status('dry_run') === 'Yoxlama rejimi', 'dry_run_status_label_wrong');
$expect(app_ui_status('executed') === 'Icra edilib', 'executed_status_label_wrong');
$expect(app_ui_action('approve') === 'Tesdiqle', 'approve_action_label_wrong');
$expect(app_ui_label('data_analytics') === 'Data analitika', 'data_analytics_label_wrong');
$expect(app_ui_label('record_id') === 'Qeyd ID', 'record_id_label_wrong');

$files = [
    'operations-center.php',
    'views/finance/employee-bonuses.php',
    'views/finance/invoices.php',
    'views/hr/payroll.php',
    'views/hr/leave-travel.php',
    'views/inventory/returns-inspection.php',
];

$forbidden = [
    'Data Analytics Center',
    'Backup worker',
    'Hesabla / Recalculate',
    'Approve</button>',
    'Paid et',
    'Audit log',
    'Setting key',
    'Problem key',
    'Severity',
    'Record ID',
    'Inspection neticeleri',
    'CSV export',
    'Finance entry',
];

foreach ($files as $file) {
    $path = $root . '/' . $file;
    $content = is_file($path) ? (string) file_get_contents($path) : '';
    $expect($content !== '', 'file_missing_or_empty:' . $file);
    foreach ($forbidden as $needle) {
        $expect(!str_contains($content, $needle), 'forbidden_ui_text:' . $file . ':' . $needle);
    }
}

echo json_encode([
    'status' => $errors === [] ? 'passed' : 'failed',
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
