<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function operations_module_smoke_fail(string $message, int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

function operations_module_smoke_render(string $page, string $query = ''): string
{
    $db = app_db();
    if (!$db instanceof PDO) {
        operations_module_smoke_fail('Database unavailable', 2);
    }

    $employee = $db->query(
        "SELECT *
         FROM portal_accounts
         WHERE role = 'employee' AND is_active = 1
         ORDER BY FIELD(account_type, 'admin', 'operator', 'technician'), id ASC
         LIMIT 1"
    )?->fetch();

    if (!is_array($employee)) {
        operations_module_smoke_fail('No active employee', 3);
    }

    parse_str($query, $params);
    $_GET = array_map(static fn ($value) => is_scalar($value) ? (string) $value : '', $params);
    $_POST = [];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_URI'] = '/superopticnet/' . $page . ($query !== '' ? '?' . $query : '');
    $_SERVER['SCRIPT_NAME'] = '/superopticnet/' . $page;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    app_session_boot();
    $_SESSION['portal_user'] = app_db_user_session_payload($employee);

    chdir(dirname(__DIR__));
    ob_start();
    include dirname(__DIR__) . DIRECTORY_SEPARATOR . $page;
    return (string) ob_get_clean();
}

function operations_module_smoke_service(string $path): array
{
    $result = app_mgs_service_get_json($path);
    return [
        'ok' => (bool) ($result['ok'] ?? false),
        'status' => (int) ($result['status'] ?? 0),
        'error' => (string) ($result['error'] ?? ''),
        'keys' => is_array($result['data'] ?? null) ? array_keys($result['data']) : [],
    ];
}

$nocHtml = operations_module_smoke_render('noc-devices.php', 'view=devices');
foreach ([
    'script/portal.js',
    'Servis qati',
    'Avadanliq oyrenme profilleri',
    'data-noc-open-device-detail',
] as $marker) {
    if (!str_contains($nocHtml, $marker)) {
        operations_module_smoke_fail('noc-devices.php missing marker: ' . $marker);
    }
}

$operationsHtml = operations_module_smoke_render('operations-center.php');
$expectedHrefs = [
    'noc-devices.php?view=monitor',
    'operations-center.php?section=inventory',
    'operations-center.php?section=finance',
    'operations-center.php?section=technical',
    'operations-center.php?section=hr',
    'operations-center.php?section=hr&amp;focus=kpi',
    'operations-center.php?section=reports',
    'noc-devices.php?view=devices',
    'network-definitions.php',
];

foreach ($expectedHrefs as $href) {
    if (!str_contains($operationsHtml, $href)) {
        operations_module_smoke_fail('operations-center.php missing href: ' . $href);
    }
}

$sectionChecks = [
    'overview' => ['Servis statusu'],
    'inventory' => ['data-inventory-module', 'data-inventory-tab="overview"', 'data-inventory-overview'],
    'finance' => ['data-finance-module', 'data-finance-overview'],
    'technical' => ['Texniki isler ve ticket baglama', 'name="action" value="create_technical_task"'],
    'hr' => ['data-hr-module', 'data-hr-tab="overview"', 'data-hr-overview'],
    'bots' => ['name="action" value="send_bot_test_message"'],
    'reports' => ['Hesabatlar, statistika ve backup', 'name="action" value="backup_now"', 'name="action" value="check_update_channel"'],
];

foreach ($sectionChecks as $section => $markers) {
    $html = $section === 'overview' ? $operationsHtml : operations_module_smoke_render('operations-center.php', 'section=' . $section);
    foreach ($markers as $marker) {
        if (!str_contains($html, $marker)) {
            operations_module_smoke_fail('operations-center.php section=' . $section . ' missing marker: ' . $marker);
        }
    }

    if ($section === 'hr') {
        foreach (['Role Agent ', 'Copilot Texnik ', 'Telegram Smoke Operator', 'Smoke Password User'] as $forbiddenEmployee) {
            if (str_contains($html, $forbiddenEmployee)) {
                operations_module_smoke_fail('operations-center.php section=hr exposes test employee in operational HR list: ' . $forbiddenEmployee);
            }
        }
    }
}

$hrAttendanceHtml = operations_module_smoke_render('operations-center.php', 'section=hr&hr_tab=attendance');
foreach (['data-hr-attendance', 'name="action" value="save_attendance_profile"'] as $marker) {
    if (!str_contains($hrAttendanceHtml, $marker)) {
        operations_module_smoke_fail('operations-center.php HR attendance tab missing marker: ' . $marker);
    }
}

$inventoryHandoverHtml = operations_module_smoke_render('operations-center.php', 'section=inventory&inventory_tab=handover');
foreach (['data-inventory-handover', 'name="action" value="create_inventory_handover_local"', 'Tehvil-teslim'] as $marker) {
    if (!str_contains($inventoryHandoverHtml, $marker)) {
        operations_module_smoke_fail('operations-center.php inventory handover tab missing marker: ' . $marker);
    }
}

$inventoryLifecycleHtml = operations_module_smoke_render('operations-center.php', 'section=inventory&inventory_tab=lifecycle');
foreach (['data-inventory-module', 'data-inventory-tab="overview"', 'data-inventory-overview'] as $marker) {
    if (!str_contains($inventoryLifecycleHtml, $marker)) {
        operations_module_smoke_fail('operations-center.php inventory lifecycle tab missing marker: ' . $marker);
    }
}

$inventoryCableHtml = operations_module_smoke_render('operations-center.php', 'section=inventory&inventory_tab=cable-drums');
foreach (['data-inventory-cable-drums', 'Kabel barabanlari', 'Kabel statistikasi'] as $marker) {
    if (!str_contains($inventoryCableHtml, $marker)) {
        operations_module_smoke_fail('operations-center.php inventory cable tab missing marker: ' . $marker);
    }
}

$overviewForbiddenMarkers = [
    'name="action" value="create_inventory_movement"',
    'name="action" value="create_finance_entry"',
    'name="action" value="create_technical_task"',
    'name="action" value="save_attendance_profile"',
    'name="action" value="scan_network"',
    'name="action" value="create_monitor_device"',
    'RADIUS live kontekst',
];

foreach ($overviewForbiddenMarkers as $marker) {
    if (str_contains($operationsHtml, $marker)) {
        operations_module_smoke_fail('operations-center.php default overview renders heavy non-overview marker: ' . $marker);
    }
}

$billingUi = file_get_contents(dirname(__DIR__) . '/billing-ui.php');
if (!is_string($billingUi) || $billingUi === '') {
    operations_module_smoke_fail('Unable to read billing-ui.php', 4);
}

if (!function_exists('billing_nav_groups')) {
    require_once dirname(__DIR__) . '/billing-ui.php';
}

$navGroups = billing_nav_groups('az');
if (array_keys($navGroups) !== ['Overview', 'Customers', 'Billing', 'Analysis']) {
    operations_module_smoke_fail('billing-ui.php must expose Billing-only navigation');
}

$moduleCatalog = app_portal_module_catalog('az');
foreach (['finance', 'hr', 'inventory', 'billing', 'ai', 'messaging', 'statistics', 'network'] as $moduleKey) {
    if (!isset($moduleCatalog[$moduleKey]['href'])) {
        operations_module_smoke_fail('portal module catalog missing route: ' . $moduleKey);
    }
}

$serviceChecks = [];
foreach ([
    '/api/service/platform-summary',
    '/api/service/network-dashboard',
    '/api/service/inventory',
    '/api/service/finance',
    '/api/service/technical',
    '/api/service/attendance',
    '/api/service/kpi',
    '/api/service/reports',
] as $path) {
    $serviceChecks[$path] = operations_module_smoke_service($path);
    if (!$serviceChecks[$path]['ok']) {
        operations_module_smoke_fail('Service API unavailable: ' . $path . ' status=' . $serviceChecks[$path]['status']);
    }
}

echo json_encode([
    'status' => 'ok',
    'checks' => [
        'noc_native_shell' => true,
        'operations_native_hrefs' => true,
        'billing_ui_routes' => true,
        'service_api_modules' => true,
    ],
    'service_checks' => $serviceChecks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
