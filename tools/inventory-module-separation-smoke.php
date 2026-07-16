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
    'catalog',
    'devices',
    'materials',
    'cable-drums',
    'technicians',
    'customers',
    'handover',
    'returns-inspection',
    'repair-rma',
    'movements',
    'approvals',
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

$requiredFiles = ['inventory-module-lib.php'];
if ($mode !== '--queries') {
    $requiredFiles[] = 'inventory-module-partial.php';
    $requiredFiles[] = 'views/inventory/module.php';
    foreach ($expectedTabs as $tab) {
        $requiredFiles[] = 'views/inventory/' . $tab . '.php';
    }
}
foreach ($requiredFiles as $file) {
    $expect(is_file($root . '/' . $file), 'missing_file:' . $file);
}
if ($mode !== '--queries' && is_file($root . '/operations-center.php')) {
    $operationsSource = (string) file_get_contents($root . '/operations-center.php');
    $expect(str_contains($operationsSource, 'document.body.appendChild(dialog);'), 'inventory_modal_not_portaled_above_portal_header');
    $expect(str_contains($operationsSource, 'module.appendChild(dialog);'), 'inventory_modal_not_restored_to_module');
}

$libraryPath = $root . '/inventory-module-lib.php';
if (is_file($libraryPath)) {
    require_once $libraryPath;
}

foreach ([
    'app_inventory_tabs',
    'app_inventory_tab',
    'app_inventory_pagination',
    'app_inventory_catalog_page',
    'app_inventory_asset_page',
    'app_inventory_cable_page',
    'app_inventory_statistics_context',
    'app_inventory_build_context',
] as $functionName) {
    $expect(function_exists($functionName), 'missing_function:' . $functionName);
}

if (function_exists('app_inventory_tabs')) {
    $expect(array_keys(app_inventory_tabs()) === $expectedTabs, 'inventory_tab_contract_mismatch');
}
if (function_exists('app_inventory_tab')) {
    $expect(app_inventory_tab([]) === 'overview', 'inventory_default_tab_not_overview');
    $expect(app_inventory_tab(['inventory_tab' => 'invalid']) === 'overview', 'inventory_invalid_tab_not_normalized');
    $expect(app_inventory_tab(['inventory_tab' => 'stock']) === 'catalog', 'inventory_legacy_stock_not_mapped');
    $expect(app_inventory_tab(['inventory_tab' => 'handover']) === 'handover', 'inventory_handover_not_preserved');
    $expect(app_inventory_tab(['inventory_tab' => 'lifecycle']) === 'overview', 'inventory_legacy_lifecycle_not_mapped');
}
if (function_exists('app_inventory_pagination')) {
    $pagination = app_inventory_pagination(['page' => '3', 'limit' => '500']);
    $expect(($pagination['page'] ?? 0) === 3, 'inventory_page_not_preserved');
    $expect(($pagination['limit'] ?? 0) === 50, 'inventory_limit_not_capped_at_50');
    $expect(($pagination['offset'] ?? -1) === 100, 'inventory_offset_is_wrong');
}

if ($mode === '--queries' || $mode === 'all') {
    $db = app_db();
    if ($db instanceof PDO) {
        $queryFunctions = [
            'catalog' => 'app_inventory_catalog_page',
            'devices' => 'app_inventory_asset_page',
            'materials' => 'app_inventory_material_page',
            'cable-drums' => 'app_inventory_cable_page',
            'technicians' => 'app_inventory_technician_page',
            'customers' => 'app_inventory_customer_page',
            'handover' => 'app_inventory_handover_page',
            'returns' => 'app_inventory_return_page',
            'inspection' => 'app_inventory_inspection_page',
            'repair-rma' => 'app_inventory_rma_page',
            'stock-movements' => 'app_inventory_stock_movement_page',
            'lifecycle-movements' => 'app_inventory_lifecycle_movement_page',
        ];
        foreach ($queryFunctions as $name => $functionName) {
            if (!function_exists($functionName)) {
                $errors[] = 'missing_query_function:' . $functionName;
                continue;
            }
            $page = $functionName($db, ['page' => 1, 'limit' => 50]);
            $expect(!isset($page['error']), 'inventory_query_failed:' . $name . ':' . (string) ($page['error'] ?? ''));
            $expect(count((array) ($page['rows'] ?? [])) <= 50, 'inventory_page_exceeds_50:' . $name);
            $expect((int) ($page['total'] ?? 0) >= count((array) ($page['rows'] ?? [])), 'inventory_total_invalid:' . $name);
            if (in_array($name, ['catalog', 'materials', 'stock-movements'], true)) {
                $pageText = json_encode($page['rows'] ?? [], JSON_UNESCAPED_UNICODE) ?: '';
                foreach (['Copilot smoke', 'CP-MODEM-', 'Flow GPON modem', 'Smoke Test Modem', 'SMOKE-SKU-', 'TEST-FASTCONNECTOR'] as $forbiddenRecord) {
                    $expect(!str_contains($pageText, $forbiddenRecord), 'inventory_exposes_test_record:' . $name . ':' . $forbiddenRecord);
                }
            }
        }
    }
    if ($db instanceof PDO && function_exists('app_inventory_statistics_context')) {
        $statistics = app_inventory_statistics_context($db, ['range' => 'year']);
        $datasetKeys = array_keys((array) ($statistics['datasets'] ?? []));
        $expect($datasetKeys !== [], 'inventory_statistics_datasets_empty');
        foreach (['billing', 'finance', 'hr', 'radius', 'network', 'customers'] as $forbiddenKey) {
            $expect(!in_array($forbiddenKey, $datasetKeys, true), 'inventory_statistics_contains_' . $forbiddenKey);
        }
        foreach ((array) ($statistics['datasets'] ?? []) as $datasetKey => $dataset) {
            $expect(count((array) ($dataset['rows'] ?? [])) <= 12, 'inventory_dataset_exceeds_12_rows:' . $datasetKey);
        }
    }
}

if ($mode !== '--queries') {
    $overview = $render('section=inventory&inventory_tab=overview');
    $expect($overview['exit_code'] === 0, 'inventory_overview_render_failed:' . $overview['stderr']);
    $expect(str_contains($overview['html'], 'data-inventory-module'), 'inventory_module_marker_missing');
    $expect(str_contains($overview['html'], 'data-inventory-tab="overview"'), 'inventory_overview_marker_missing');
    $expect(str_contains($overview['html'], 'data-inventory-overview'), 'inventory_overview_content_missing');
    $expect(str_contains($overview['html'], 'data-inventory-quick-actions'), 'inventory_quick_action_panel_missing');
    $expect(!str_contains($overview['html'], 'data-inventory-command-strip'), 'inventory_duplicate_command_strip_remains');
    $expect(str_contains($overview['html'], 'Mal mədaxil et') && str_contains($overview['html'], 'open=stock-intake'), 'inventory_intake_quick_action_missing');
    $expect(str_contains($overview['html'], 'Filiala transfer et') && str_contains($overview['html'], 'open=branch-transfer'), 'inventory_transfer_quick_action_missing');
    $expect(str_contains($overview['html'], 'Texnikə təhvil ver') && str_contains($overview['html'], 'open=handover-create'), 'inventory_handover_quick_action_missing');
    $expect(str_contains($overview['html'], 'Qaytarma yarat') && str_contains($overview['html'], 'open=external-return'), 'inventory_return_quick_action_missing');
    $expect(str_contains($overview['html'], 'Mədaxil və transfer'), 'inventory_nav_medaxil_transfer_label_missing');
    foreach (['create_inventory_category', 'create_inventory_intake', 'create_inventory_branch_transfer', 'create_inventory_handover_local', 'create_inventory_external_request'] as $action) {
        $expect(!str_contains($overview['html'], 'name="action" value="' . $action . '"'), 'inventory_overview_contains_workflow:' . $action);
    }

    foreach ([
        'stock-intake' => 'section=inventory&inventory_tab=movements&open=stock-intake',
        'branch-transfer' => 'section=inventory&inventory_tab=movements&open=branch-transfer',
        'handover-create' => 'section=inventory&inventory_tab=handover&open=handover-create',
        'external-return' => 'section=inventory&inventory_tab=approvals&open=external-return',
    ] as $dialogName => $dialogQuery) {
        $dialogResult = $render($dialogQuery);
        $expect($dialogResult['exit_code'] === 0, 'inventory_open_dialog_render_failed:' . $dialogName . ':' . $dialogResult['stderr']);
        $openMarker = 'data-inventory-dialog="' . $dialogName . '"';
        $hiddenMarker = 'data-inventory-dialog="' . $dialogName . '" hidden';
        $expect(str_contains($dialogResult['html'], $openMarker), 'inventory_open_dialog_marker_missing:' . $dialogName);
        $expect(!str_contains($dialogResult['html'], $hiddenMarker), 'inventory_open_dialog_still_hidden:' . $dialogName);
    }

    $tabContracts = [
        'catalog' => ['data-inventory-catalog', 'create_inventory_category'],
        'devices' => ['data-inventory-devices'],
        'materials' => ['data-inventory-materials'],
        'cable-drums' => ['data-inventory-cable-drums'],
        'technicians' => ['data-inventory-technicians'],
        'customers' => ['data-inventory-customers'],
        'handover' => ['data-inventory-handover', 'create_inventory_handover_local'],
        'returns-inspection' => ['data-inventory-returns-inspection'],
        'repair-rma' => ['data-inventory-repair-rma'],
        'movements' => ['data-inventory-movements', 'create_inventory_intake', 'create_inventory_branch_transfer'],
        'approvals' => ['data-inventory-approvals', 'create_inventory_external_request'],
        'statistics' => ['data-inventory-statistics-panel'],
    ];
    foreach ($tabContracts as $tab => $markers) {
        $result = $render('section=inventory&inventory_tab=' . rawurlencode($tab));
        $expect($result['exit_code'] === 0, 'inventory_tab_render_failed:' . $tab . ':' . $result['stderr']);
        foreach ($markers as $marker) {
            $expect(str_contains($result['html'], $marker), 'inventory_tab_marker_missing:' . $tab . ':' . $marker);
        }
        if($tab==='movements')foreach(['name="new_sku"','name="new_item_name"','name="new_category"','name="new_unit"','name="serial_number"','name="mac_address"','name="from_location"','name="to_location"','warehouse:MAIN'] as $field)$expect(str_contains($result['html'],$field),'inventory_movement_field_missing:'.$field);
        if($tab==='approvals')foreach(['name="source_location"','warehouse:MAIN','QAY sened'] as $field)$expect(str_contains($result['html'],$field),'inventory_return_field_missing:'.$field);
        $expect(substr_count($result['html'], 'data-inventory-row') <= 50, 'inventory_tab_exceeds_50_rows:' . $tab);
        if ($tab === 'statistics') {
            $expect(substr_count($result['html'], 'data-inventory-stat-chart') <= 4, 'inventory_statistics_exceeds_4_charts');
        }
    }
}

if ($mode === '--ajax' || $mode === 'all') {
    $operationsSource = (string) file_get_contents($root . '/operations-center.php');
    $partialSource = is_file($root . '/inventory-module-partial.php') ? (string) file_get_contents($root . '/inventory-module-partial.php') : '';
    $librarySource = is_file($libraryPath) ? (string) file_get_contents($libraryPath) : '';
    foreach (['data-inventory-module', 'inventory-module-partial.php', 'inventoryRequest', 'history.pushState', 'popstate'] as $marker) {
        $expect(str_contains($operationsSource, $marker), 'inventory_ajax_marker_missing:' . $marker);
    }
    $expect(str_contains($operationsSource, '$needsTechnicalService = $activeOpsSection === \'technical\';'), 'inventory_still_loads_technical_service');
    $expect(str_contains($partialSource, "billing_require_employee(false, 'inventory')"), 'inventory_partial_permission_guard_missing');
    $expect(!preg_match('/\b(radacct|radpostauth|billing_payments|finance_entries|hr_payroll_records|mgs_alarm_events)\b/i', $librarySource), 'inventory_queries_foreign_domain');
}

if ($errors !== []) {
    echo json_encode(['status' => 'failed', 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

echo json_encode([
    'status' => 'passed',
    'tabs' => $expectedTabs,
    'message' => 'Inventory module separation, pagination, AJAX and statistics context smoke passed.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
