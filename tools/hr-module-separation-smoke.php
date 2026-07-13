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
    'employees',
    'attendance',
    'leave-travel',
    'performance',
    'payroll',
    'documents',
    'statistics',
];

$expect = static function (bool $condition, string $error) use (&$errors): void {
    if (!$condition) {
        $errors[] = $error;
    }
};

$render = static function (string $query) use ($root): array {
    $process = proc_open([
        PHP_BINARY,
        $root . '/tools/billing-ui-smoke.php',
        '--render=operations-center.php',
        '--query=' . $query,
    ], [
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

$requiredFiles = ['hr-module-lib.php'];
if ($mode !== '--queries') {
    $requiredFiles[] = 'hr-module-partial.php';
    $requiredFiles[] = 'views/hr/module.php';
    foreach ($expectedTabs as $tab) {
        $requiredFiles[] = 'views/hr/' . $tab . '.php';
    }
}
foreach ($requiredFiles as $file) {
    $expect(is_file($root . '/' . $file), 'missing_file:' . $file);
}
if ($mode !== '--queries' && is_file($root . '/operations-center.php')) {
    $operationsSource = (string) file_get_contents($root . '/operations-center.php');
    $expect(str_contains($operationsSource,'const openHrDialog =')&&str_contains($operationsSource,'document.body.appendChild(dialog);')&&str_contains($operationsSource,"document.body.classList.add('hr-dialog-open');"),'hr_dialog_not_portaled_to_viewport');
    $expect(str_contains($operationsSource, "const hrModule = document.querySelector('[data-hr-module]');"), 'hr_dialog_restore_module_missing');
    $expect(str_contains($operationsSource, 'hrModule.appendChild(dialog);'), 'hr_dialog_not_restored_to_module');
}

$libraryPath = $root . '/hr-module-lib.php';
if (is_file($libraryPath)) {
    require_once $libraryPath;
}

foreach ([
    'app_hr_tabs',
    'app_hr_tab',
    'app_hr_pagination',
    'app_hr_employee_page',
    'app_hr_payroll_page',
    'app_hr_statistics_context',
    'app_hr_build_context',
] as $functionName) {
    $expect(function_exists($functionName), 'missing_function:' . $functionName);
}

if (function_exists('app_hr_tabs')) {
    $expect(array_keys(app_hr_tabs()) === $expectedTabs, 'hr_tab_contract_mismatch');
}
if (function_exists('app_hr_tab')) {
    $expect(app_hr_tab([]) === 'overview', 'hr_default_tab_not_overview');
    $expect(app_hr_tab(['hr_tab' => 'invalid']) === 'overview', 'hr_invalid_tab_not_normalized');
    $expect(app_hr_tab(['hr_tab' => 'payroll']) === 'payroll', 'hr_selected_tab_not_preserved');
    $expect(app_hr_tab(['focus' => 'kpi']) === 'performance', 'hr_kpi_focus_not_mapped');
}
if (function_exists('app_hr_pagination')) {
    $pagination = app_hr_pagination(['page' => '3', 'limit' => '500']);
    $expect(($pagination['page'] ?? 0) === 3, 'hr_page_not_preserved');
    $expect(($pagination['limit'] ?? 0) === 50, 'hr_limit_not_capped_at_50');
    $expect(($pagination['offset'] ?? -1) === 100, 'hr_offset_is_wrong');
}

if ($mode === '--queries' || $mode === 'all') {
    $db = app_db();
    if ($db instanceof PDO && function_exists('app_hr_employee_page')) {
        $employees = app_hr_employee_page($db, ['page' => 1, 'limit' => 50]);
        $expect(count((array) ($employees['rows'] ?? [])) <= 50, 'hr_employee_page_exceeds_50');
        $employeeText = json_encode($employees['rows'] ?? [], JSON_UNESCAPED_UNICODE) ?: '';
        foreach (['Role Agent ', 'Copilot Texnik ', 'Smoke Password User', 'Telegram Smoke Operator'] as $forbiddenEmployee) {
            $expect(!str_contains($employeeText, $forbiddenEmployee), 'hr_employee_page_exposes_test_account:' . $forbiddenEmployee);
        }
    }
    if ($db instanceof PDO && function_exists('app_hr_payroll_page')) {
        $payroll = app_hr_payroll_page($db, ['page' => 1, 'limit' => 50]);
        $expect(count((array) ($payroll['rows'] ?? [])) <= 50, 'hr_payroll_page_exceeds_50');
        $expect((int) ($payroll['total'] ?? 0) >= count((array) ($payroll['rows'] ?? [])), 'hr_payroll_total_invalid');
    }
    if ($db instanceof PDO && function_exists('app_hr_score_page')) {
        $scores = app_hr_score_page($db, ['page' => 1, 'limit' => 50]);
        $expect(!isset($scores['error']), 'hr_score_page_query_failed:' . (string) ($scores['error'] ?? ''));
        $expect(count((array) ($scores['rows'] ?? [])) <= 50, 'hr_score_page_exceeds_50');
    }
    if ($db instanceof PDO && function_exists('app_hr_statistics_context')) {
        $statistics = app_hr_statistics_context($db, ['range' => 'year']);
        $datasetKeys = array_keys((array) ($statistics['datasets'] ?? []));
        $expect($datasetKeys !== [], 'hr_statistics_datasets_empty');
        foreach (['billing', 'radius', 'traffic', 'inventory', 'network', 'customers'] as $forbiddenKey) {
            $expect(!in_array($forbiddenKey, $datasetKeys, true), 'hr_statistics_contains_' . $forbiddenKey);
        }
        foreach ((array) ($statistics['datasets'] ?? []) as $datasetKey => $dataset) {
            $expect(count((array) ($dataset['rows'] ?? [])) <= 12, 'hr_year_dataset_exceeds_12_buckets:' . $datasetKey);
        }
    }
}

if ($mode !== '--queries') {
    $overview = $render('section=hr&hr_tab=overview');
    $expect($overview['exit_code'] === 0, 'hr_overview_render_failed:' . $overview['stderr']);
    $expect(str_contains($overview['html'], 'data-hr-module'), 'hr_module_marker_missing');
    $expect(str_contains($overview['html'], 'data-hr-tab="overview"'), 'hr_overview_marker_missing');
    $expect(str_contains($overview['html'],'open=employee-create')&&str_contains($overview['html'],'Yeni emekdas'),'hr_employee_quick_action_missing');
    foreach ([
        'save_hr_employee_compensation',
        'record_hr_score_event',
        'create_hr_payroll_record',
        'create_hr_leave_request',
        'create_hr_travel_request',
        'create_hr_employee_document',
        'update_hr_record_status',
        'save_attendance_profile',
    ] as $action) {
        $expect(!str_contains($overview['html'], 'name="action" value="' . $action . '"'), 'hr_overview_contains_workflow:' . $action);
    }

    $tabContracts = [
        'employees' => ['data-hr-employees', 'save_hr_employee_compensation'],
        'attendance' => ['data-hr-attendance', 'save_attendance_profile'],
        'leave-travel' => ['data-hr-leave-travel', 'create_hr_leave_request', 'create_hr_travel_request'],
        'performance' => ['data-hr-performance', 'record_hr_score_event'],
        'payroll' => ['data-hr-payroll', 'create_hr_payroll_record'],
        'documents' => ['data-hr-documents', 'create_hr_employee_document', 'update_hr_record_status'],
        'statistics' => ['data-hr-statistics-panel'],
    ];
    foreach ($tabContracts as $tab => $markers) {
        $result = $render('section=hr&hr_tab=' . rawurlencode($tab));
        $expect($result['exit_code'] === 0, 'hr_tab_render_failed:' . $tab . ':' . $result['stderr']);
        foreach ($markers as $marker) {
            $expect(str_contains($result['html'], $marker), 'hr_tab_marker_missing:' . $tab . ':' . $marker);
        }
        $expect(substr_count($result['html'], 'data-hr-row') <= 50, 'hr_tab_exceeds_50_rows:' . $tab);
        if ($tab === 'statistics') {
            $expect(substr_count($result['html'], 'data-hr-stat-chart') <= 4, 'hr_statistics_exceeds_4_charts');
            $statisticsHtml = strstr($result['html'], 'data-hr-statistics-panel') ?: '';
            $statisticsHtml = strstr($statisticsHtml, '</section>', true) ?: $statisticsHtml;
            $expect(!preg_match('/RADIUS|\bbilling\b|\binventory\b|\bnetwork\b/', $statisticsHtml), 'hr_statistics_renders_foreign_domain');
        }
    }
}

if ($mode === '--ajax' || $mode === 'all') {
    $operationsSource = (string) file_get_contents($root . '/operations-center.php');
    $partialSource = is_file($root . '/hr-module-partial.php') ? (string) file_get_contents($root . '/hr-module-partial.php') : '';
    $librarySource = is_file($libraryPath) ? (string) file_get_contents($libraryPath) : '';
    foreach (['data-hr-module', 'hr-module-partial.php', 'hrRequest', 'history.pushState', 'popstate'] as $marker) {
        $expect(str_contains($operationsSource, $marker), 'hr_ajax_marker_missing:' . $marker);
    }
    $expect(str_contains($partialSource, "billing_require_employee(false, 'hr')"), 'hr_partial_permission_guard_missing');
    $expect(!preg_match('/\b(radacct|radpostauth|billing_payments|inventory_assets|mgs_alarm_events)\b/i', $librarySource), 'hr_statistics_queries_foreign_domain');
}

if ($errors !== []) {
    echo json_encode(['status' => 'failed', 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

echo json_encode([
    'status' => 'passed',
    'tabs' => $expectedTabs,
    'message' => 'HR module separation, pagination, AJAX and statistics context smoke passed.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
