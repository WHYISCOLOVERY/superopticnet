<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';
$library = dirname(__DIR__) . '/operations-permission-lib.php';
$errors = [];
if (!is_file($library)) {
    echo json_encode(['status'=>'failed','errors'=>['permission_library_missing']], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}
require $library;
$actors = [
    'finance'=>['role'=>'employee','employee_role'=>'finance','account_type'=>'operator'],
    'hr'=>['role'=>'employee','employee_role'=>'hr','account_type'=>'operator'],
    'warehouse'=>['role'=>'employee','employee_role'=>'warehouse','account_type'=>'operator'],
    'warehouse_manager'=>['role'=>'employee','employee_role'=>'warehouse_manager','account_type'=>'operator'],
    'support'=>['role'=>'employee','employee_role'=>'support','account_type'=>'operator'],
    'sales'=>['role'=>'employee','employee_role'=>'sales','account_type'=>'operator'],
    'technician'=>['role'=>'employee','employee_role'=>'technician','account_type'=>'technician'],
];
$cases = [
    'create_finance_entry'=>['finance'],
    'create_finance_income'=>['finance'],
    'create_hr_payroll_record'=>['hr'],
    'create_hr_employee'=>['hr'],
    'create_inventory_movement'=>['warehouse','warehouse_manager'],
    'create_inventory_intake'=>['warehouse','warehouse_manager'],
    'approve_inventory_request'=>['warehouse_manager'],
    'scan_network'=>['support','technician'],
    'send_bot_test_message'=>['support','sales'],
];
foreach ($cases as $action=>$allowedActors) {
    foreach ($actors as $name=>$actor) {
        $allowed = app_operations_action_allowed($actor, $action);
        if ($allowed !== in_array($name,$allowedActors,true)) $errors[] = $action . ':actor:' . $name;
    }
}
$source = (string) file_get_contents(dirname(__DIR__) . '/operations-center.php');
if (!str_contains($source, 'app_operations_action_allowed($user, $action)')) $errors[]='router_guard_missing';

// Every finance/hr/inventory/bonus write action goes through the shared
// CSRF-protected dispatch block; if an action string is dispatched there but
// missing from operations-permission-lib.php's module map,
// app_operations_action_module() returns null and app_operations_action_allowed()
// rejects it for EVERY actor (including admin) before the handler ever runs --
// a silent "feature exists in code but is unreachable via the real UI" bug.
// This caught approve_finance_expense/confirm_asset_writeoff/
// request_finance_debt_writeoff/resolve_finance_debt_writeoff/
// file_hr_score_appeal/resolve_hr_score_appeal all being unreachable
// (2026-08-09) despite passing their own function-level smoke tests.
if (preg_match(
    "/if \\(in_array\\(\\\$action, \\['create_finance_income',.*?'send_bot_test_message'\\], true\\)\\)/s",
    $source,
    $matches
) === 1) {
    preg_match_all("/'([a-z_]+)'/", $matches[0], $actionMatches);
    $dispatchedActions = array_values(array_unique($actionMatches[1] ?? []));
    if ($dispatchedActions === []) {
        $errors[] = 'dispatch_action_list_parse_empty';
    }
    foreach ($dispatchedActions as $dispatchedAction) {
        if (app_operations_action_module($dispatchedAction) === null) {
            $errors[] = 'unreachable_action_missing_from_module_map:' . $dispatchedAction;
        }
    }
} else {
    $errors[] = 'dispatch_action_list_pattern_not_found';
}

echo json_encode(['status'=>$errors===[]?'passed':'failed','errors'=>$errors], JSON_PRETTY_PRINT) . PHP_EOL;
exit($errors===[]?0:1);
