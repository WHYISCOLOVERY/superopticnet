<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$errors = [];
$expect = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};
$source = static fn (string $path): string => is_file($root . '/' . $path)
    ? (string) file_get_contents($root . '/' . $path)
    : '';

$operations = $source('operations-center.php');
$expect(str_contains($operations, 'legacy_inventory_action_disabled'), 'legacy_inventory_action_not_disabled');
foreach ([
    'create_finance_income' => 'app_finance_save_entry',
    'save_finance_counterparty' => 'app_finance_save_counterparty',
    'create_hr_employee' => 'app_hr_create_employee',
    'create_inventory_intake' => 'app_inventory_post_intake',
    'create_inventory_branch_transfer' => 'app_inventory_transfer_branch',
    'create_inventory_external_request' => 'app_inventory_create_external_request',
    'approve_inventory_request' => 'app_inventory_approve_request',
    'reject_inventory_request' => 'app_inventory_reject_request',
] as $action => $service) {
    $expect(str_contains($operations, "'" . $action . "'"), 'operations_action_missing:' . $action);
    $expect(str_contains($operations, $service . '('), 'operations_service_call_missing:' . $service);
}

foreach (['finance-operations-lib.php', 'hr-employee-service.php', 'inventory-operations-lib.php'] as $include) {
    $expect(str_contains($operations, "require_once __DIR__ . '/" . $include . "';"), 'operations_include_missing:' . $include);
}

$dbSource = $source('db.php');
foreach (['job_title', 'fin_code', 'document_no'] as $column) {
    $expect(str_contains($dbSource, $column), 'account_column_mapping_missing:' . $column);
}

$config = $source('config.php');
$expect(str_contains($config, "'warehouse_manager' => ['inventory']"), 'warehouse_manager_permission_missing');

$admin = $source('admin-users.php');
$expect(str_contains($admin, 'value="warehouse_manager"'), 'admin_warehouse_manager_option_missing');
$expect(str_contains($admin, 'job_title'), 'admin_job_title_display_missing');

$portalActions = $source('portal-actions.php');
$expect(str_contains($portalActions, "app_portal_is_admin(\$user)"), 'admin_role_assignment_guard_missing');
$expect(str_contains($portalActions, "'job_title'"), 'admin_job_title_preservation_missing');

if ($errors !== []) {
    echo json_encode(['status' => 'failed', 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

echo json_encode([
    'status' => 'passed',
    'message' => 'Operational services are wired to shared handlers and admin-only authorization controls.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
