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
$expect(app_ui_status('pending_approval') === 'Təsdiq gözləyir', 'pending_approval_status_label_wrong');
$expect(app_ui_status('planned') === 'Planlanıb', 'planned_status_label_wrong');
$expect(app_ui_status('dry_run') === 'Yoxlama rejimi', 'dry_run_status_label_wrong');
$expect(app_ui_status('executed') === 'İcra edilib', 'executed_status_label_wrong');
$expect(app_ui_status('posted') === 'Qeydə alınıb', 'posted_status_label_wrong');
$expect(app_ui_status('present') === 'İşdədir', 'present_status_label_wrong');
$expect(app_ui_status('late') === 'Gecikib', 'late_status_label_wrong');
$expect(app_ui_status('absent') === 'İşə gəlməyib', 'absent_status_label_wrong');
$expect(app_ui_status('leave') === 'Məzuniyyətdədir', 'leave_status_label_wrong');
$expect(app_ui_status('travel') === 'Ezamiyyətdədir', 'travel_status_label_wrong');
$expect(app_ui_action('approve') === 'Təsdiqlə', 'approve_action_label_wrong');
$expect(app_ui_label('data_analytics') === 'Məlumat analitikası', 'data_analytics_label_wrong');
$expect(app_ui_label('record_id') === 'Qeyd ID', 'record_id_label_wrong');
$expect(app_ui_label('dashboard') === 'Ümumi baxış', 'dashboard_label_wrong');
$expect(app_ui_label('backup_worker') === 'Yedəkləmə xidməti', 'backup_worker_label_wrong');
$expect(app_ui_message('empty') === 'Məlumat tapılmadı.', 'empty_message_wrong');
$expect(app_ui_message('forbidden') === 'Bu əməliyyat üçün icazəniz yoxdur.', 'forbidden_message_wrong');

$files = [
    'operations-center.php',
    'views/finance/employee-bonuses.php',
    'views/finance/invoices.php',
    'views/hr/payroll.php',
    'views/hr/leave-travel.php',
    'views/inventory/returns-inspection.php',
    'views/finance/income.php',
    'views/hr/attendance.php',
    'views/inventory/catalog.php',
    'views/billing/services.php',
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

$statusViews = [
    'views/finance/income.php' => "app_ui_status((string) (\$row['status']",
    'views/hr/attendance.php' => "app_ui_status((string) (\$row['attendance_status']",
    'views/inventory/catalog.php' => "app_ui_status((string) (\$row['status']",
    'views/billing/services.php' => "app_ui_status((string) (\$row['status']",
];
foreach ($statusViews as $file => $needle) {
    $content = (string) file_get_contents($root . '/' . $file);
    $expect(str_contains($content, $needle), 'display_status_mapping_missing:' . $file);
}

$shellFiles = [
    'views/_module-group-nav.php',
    'views/finance/module.php',
    'views/hr/module.php',
    'views/inventory/module.php',
    'views/billing/module.php',
];
foreach ($shellFiles as $file) {
    $content = (string) file_get_contents($root . '/' . $file);
    $expect(!str_contains($content, '<style'), 'inline_shell_style_remains:' . $file);
}

$shellCss = (string) @file_get_contents($root . '/css/operations-modules.css');
$expect($shellCss !== '', 'operations_modules_stylesheet_missing');
$expect(str_contains($shellCss, '.module-group-nav'), 'shared_module_nav_style_missing');
$expect(str_contains($shellCss, 'overflow-wrap:anywhere'), 'shared_kpi_overflow_guard_missing');

echo json_encode([
    'status' => $errors === [] ? 'passed' : 'failed',
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
