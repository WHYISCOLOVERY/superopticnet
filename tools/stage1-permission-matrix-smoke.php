<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/operations-permission-lib.php';

$checks = [];
$expect = static function (bool $condition, string $name) use (&$checks): void {
    $checks[$name] = $condition;
};

$admin = ['id' => 1, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin'];
$finance = ['id' => 2, 'role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'finance'];
$hr = ['id' => 3, 'role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'hr'];
$warehouse = ['id' => 4, 'role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'warehouse'];
$warehouseManager = ['id' => 5, 'role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'warehouse_manager'];
$support = ['id' => 6, 'role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'support'];

$expect(!app_operations_action_allowed($admin, 'stage1_unknown_write'), 'unknown_action_denied_for_admin');
$expect(!app_operations_action_allowed($finance, 'stage1_unknown_write'), 'unknown_action_denied_for_employee');

foreach (['create_finance_income', 'void_finance_income', 'link_finance_counterparty', 'create_finance_entry'] as $action) {
    $expect(app_operations_action_allowed($finance, $action), 'finance_allowed_' . $action);
    $expect(!app_operations_action_allowed($support, $action), 'support_denied_' . $action);
}

foreach (['create_hr_employee', 'update_hr_employee', 'create_hr_payroll_record', 'record_hr_finance_expense'] as $action) {
    $expect(app_operations_action_allowed($hr, $action), 'hr_allowed_' . $action);
    $expect(!app_operations_action_allowed($finance, $action), 'finance_denied_' . $action);
}

foreach (['update_inventory_item', 'deactivate_inventory_item', 'adjust_inventory_stock', 'create_inventory_repair'] as $action) {
    $expect(app_operations_action_allowed($warehouse, $action), 'warehouse_allowed_' . $action);
    $expect(!app_operations_action_allowed($support, $action), 'support_denied_' . $action);
}

$expect(app_operations_action_allowed($warehouseManager, 'approve_inventory_request'), 'warehouse_manager_approval_allowed');
$expect(!app_operations_action_allowed($warehouse, 'approve_inventory_request'), 'warehouse_approval_denied');

$source = (string) file_get_contents(dirname(__DIR__) . '/operations-center.php');
preg_match_all("/\\$action\\s*===\\s*'([^']+)'/", $source, $matches);
foreach (array_values(array_unique($matches[1] ?? [])) as $action) {
    $expect(app_operations_action_module((string) $action) !== null, 'dispatcher_action_mapped_' . $action);
}
$expect(
    substr_count($source, 'app_mgs_noc_service_request_json(') >= 2,
    'legacy_noc_scan_and_create_routes_use_authorized_service'
);

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
echo json_encode([
    'ok' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
