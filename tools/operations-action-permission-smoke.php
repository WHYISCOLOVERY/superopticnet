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
echo json_encode(['status'=>$errors===[]?'passed':'failed','errors'=>$errors], JSON_PRETTY_PRINT) . PHP_EOL;
exit($errors===[]?0:1);
