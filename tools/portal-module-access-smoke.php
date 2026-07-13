<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../billing-ui.php';

$errors = [];

$expect = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$expectedModules = ['finance', 'hr', 'inventory', 'billing', 'ai', 'messaging', 'whatsapp_monitor', 'statistics', 'network'];
$catalog = function_exists('app_portal_module_catalog') ? app_portal_module_catalog('az') : [];
$expect(array_keys($catalog) === $expectedModules, 'module_catalog_mismatch');

$roleMatrix = [
    'admin' => $expectedModules,
    'finance' => ['finance', 'billing', 'statistics'],
    'warehouse' => ['inventory'],
    'hr' => ['hr', 'statistics'],
    'operator' => ['billing', 'messaging', 'whatsapp_monitor', 'statistics'],
    'night_operator' => ['billing', 'messaging', 'whatsapp_monitor', 'statistics'],
    'support' => ['billing', 'messaging', 'whatsapp_monitor', 'statistics', 'network', 'ai'],
    'technician' => ['inventory', 'network', 'whatsapp_monitor'],
    'engineer' => ['inventory', 'network', 'whatsapp_monitor'],
    'noc' => ['messaging', 'whatsapp_monitor', 'statistics', 'network', 'ai'],
    'sales' => ['finance', 'billing', 'messaging', 'whatsapp_monitor', 'statistics'],
    '' => [],
];

foreach ($roleMatrix as $role => $expected) {
    $user = [
        'role' => 'employee',
        'account_type' => in_array($role, ['admin', 'noc'], true) ? 'admin' : 'operator',
        'employee_role' => $role,
    ];
    $actual = function_exists('app_portal_employee_module_keys')
        ? app_portal_employee_module_keys($user)
        : [];
    $expect($actual === $expected, 'role_matrix_mismatch:' . ($role !== '' ? $role : 'empty'));
}

$nocUser = ['role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'noc'];
$expect(app_portal_is_admin($nocUser), 'account_type_admin_must_view_admin_audit_tools');
$expect(
    function_exists('app_portal_can_access_module')
        && !app_portal_can_access_module($nocUser, 'finance')
        && app_portal_can_access_module($nocUser, 'network'),
    'noc_account_type_must_not_override_employee_role'
);

$requestCases = [
    [['operations-center.php', ['section' => 'finance']], 'finance'],
    [['operations-center.php', ['section' => 'hr']], 'hr'],
    [['operations-center.php', ['section' => 'inventory']], 'inventory'],
    [['billing-dashboard.php', []], 'billing'],
    [['message-center.php', []], 'messaging'],
    [['whatsapp-monitor.php', []], 'whatsapp_monitor'],
    [['billing-statistics.php', []], 'billing'],
    [['noc-devices.php', ['view' => 'monitor']], 'network'],
    [['mgs-copilot-context.php', []], 'ai'],
];

foreach ($requestCases as [$input, $expectedModule]) {
    [$script, $query] = $input;
    $actual = function_exists('app_portal_request_module')
        ? app_portal_request_module($script, $query)
        : null;
    $expect($actual === $expectedModule, 'request_module_mismatch:' . $script . ':' . $expectedModule);
}

$navGroups = billing_nav_groups('az');
$expect(array_keys($navGroups) === ['Overview', 'Customers', 'Billing', 'Analysis'], 'billing_navigation_not_isolated');
$expect(
    function_exists('billing_nav_contains_active')
        && billing_nav_contains_active($navGroups, 'overview')
        && billing_nav_contains_active($navGroups, 'statistics')
        && !billing_nav_contains_active($navGroups, 'messages'),
    'billing_navigation_visibility_boundary_missing'
);
$expect(
    function_exists('billing_header_brand_href')
        && billing_header_brand_href('az', 'overview') === 'billing-dashboard.php'
        && billing_header_brand_href('az', 'statistics') === 'billing-dashboard.php'
        && billing_header_brand_href('az', 'messages') === 'portal.php'
        && billing_header_brand_href('az', 'noc-devices') === 'portal.php',
    'module_header_brand_boundary_missing'
);
$billingKeys = array_column($navGroups['Billing']['links'] ?? [], 'key');
$expect(!in_array('finance', $billingKeys, true), 'finance_still_inside_billing_navigation');

$forbiddenProbe = <<<'PHP'
require getcwd() . '/billing-ui.php';
app_session_boot();
$_SESSION['portal_user'] = [
    'id' => 987654,
    'role' => 'employee',
    'account_type' => 'operator',
    'employee_role' => 'operator',
    'full_name' => 'Permission Smoke',
];
$_SERVER['SCRIPT_NAME'] = '/superopticnet/operations-center.php';
$_GET = ['section' => 'finance'];
register_shutdown_function(static function (): void {
    fwrite(STDERR, 'HTTP_STATUS=' . (string) http_response_code());
});
billing_require_employee(false, 'finance');
echo 'ACCESS_ALLOWED';
PHP;
$forbiddenProcess = proc_open(
    [PHP_BINARY, '-r', $forbiddenProbe],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $forbiddenPipes,
    dirname(__DIR__)
);
if (is_resource($forbiddenProcess)) {
    fclose($forbiddenPipes[0]);
    $forbiddenOutput = (string) stream_get_contents($forbiddenPipes[1]);
    $forbiddenError = (string) stream_get_contents($forbiddenPipes[2]);
    fclose($forbiddenPipes[1]);
    fclose($forbiddenPipes[2]);
    proc_close($forbiddenProcess);
    $expect(!str_contains($forbiddenOutput, 'ACCESS_ALLOWED'), 'forbidden_module_access_was_allowed');
    $expect(str_contains($forbiddenError, 'HTTP_STATUS=403'), 'forbidden_module_did_not_return_http_403');
} else {
    $errors[] = 'forbidden_module_probe_failed';
}

$portalSource = (string) file_get_contents(__DIR__ . '/../portal.php');
foreach (['app_portal_employee_module_keys', 'data-portal-module', 'module_forbidden'] as $marker) {
    $expect(str_contains($portalSource, $marker), 'portal_marker_missing:' . $marker);
}

$adminSource = (string) file_get_contents(__DIR__ . '/../admin-users.php');
$expect(str_contains($adminSource, '<option value="hr"'), 'hr_employee_role_option_missing');

$operationsSource = (string) file_get_contents(__DIR__ . '/../operations-center.php');
foreach ([
    "'finance' => 'Maliyye'",
    "'hr' => 'HR'",
    "'inventory' => 'Anbar'",
    "'noc' => 'Sebeke'",
    "'olt' => 'Sebeke'",
    "'technical' => 'Sebeke'",
    "'reports' => 'Statistika'",
    "'bots' => 'AI'",
    '$operationsPageTitle',
] as $marker) {
    $expect(str_contains($operationsSource, $marker), 'operations_title_marker_missing:' . $marker);
}

echo json_encode([
    'ok' => $errors === [],
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
