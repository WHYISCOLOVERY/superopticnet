<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/mgs-integration-lib.php';
require_once dirname(__DIR__) . '/hr-employee-service.php';
require_once dirname(__DIR__) . '/hr-module-lib.php';

$db = app_db();
$run = date('YmdHis') . random_int(100, 999);
$accountId = 0;
$invalidAccountId = 0;
$errors = [];
try {
    $result = app_hr_create_employee($db, ['full_name' => 'Smoke Employee ' . $run, 'login' => 'hr-smoke-' . $run, 'phone' => '+994501234567', 'job_title' => 'Field Specialist', 'branch_id' => '1', 'hire_date' => date('Y-m-d'), 'salary_mode' => 'gross', 'gross_salary' => 1000, 'employee_role' => 'admin', 'account_type' => 'admin', 'is_active' => 1], ['id' => 1, 'role' => 'employee', 'employee_role' => 'hr', 'full_name' => 'HR Smoke']);
    $accountId = (int) ($result['account_id'] ?? 0);
    $row = $accountId > 0 ? app_db_account_by_id($accountId) : null;
    if (empty($result['ok']) || !is_array($row)) $errors[] = 'employee_create_failed';
    if (($row['job_title'] ?? '') !== 'Field Specialist') $errors[] = 'job_title_missing';
    if (($row['employee_role'] ?? 'x') !== '' || ($row['account_type'] ?? '') !== 'employee' || (int) ($row['is_active'] ?? 1) !== 0) $errors[] = 'role_escalation_possible';
    $page=app_hr_employee_page($db,['search'=>'hr-smoke-'.$run,'limit'=>50]);if(!in_array($accountId,array_map('intval',array_column((array)($page['rows']??[]),'id')),true))$errors[]='inactive_employee_hidden';
    $updated=app_hr_update_employee($db,['employee_account_id'=>$accountId,'full_name'=>'Smoke Employee Updated '.$run,'login'=>'hr-smoke-'.$run,'job_title'=>'Senior Field Specialist','hire_date'=>date('Y-m-d'),'branch_id'=>'1'],$actor=['id'=>1,'role'=>'employee','employee_role'=>'hr','full_name'=>'HR Smoke']);
    $updatedRow=app_db_account_by_id($accountId);if(empty($updated['ok'])||($updatedRow['job_title']??'')!=='Senior Field Specialist'||($updatedRow['employee_role']??'x')!=='')$errors[]='employee_update_failed';
    $forbidden = app_hr_create_employee($db, ['full_name' => 'Denied', 'login' => 'denied-' . $run, 'job_title' => 'X'], ['role' => 'employee', 'employee_role' => 'support']);
    if (($forbidden['error'] ?? '') !== 'forbidden') $errors[] = 'hr_permission_not_enforced';
    $invalidSalary=app_hr_create_employee($db,['full_name'=>'Invalid Salary','login'=>'invalid-salary-'.$run,'job_title'=>'Specialist','salary_mode'=>'gross','gross_salary'=>0,'net_salary'=>1000],['id'=>1,'role'=>'employee','employee_role'=>'hr','full_name'=>'HR Smoke']);$invalidAccountId=(int)($invalidSalary['account_id']??0);
    if(($invalidSalary['error']??'')!=='salary_value_mismatch'||$invalidAccountId!==0)$errors[]='salary_mode_mismatch_accepted';
    $bothSalary=app_hr_create_employee($db,['full_name'=>'Both Salary','login'=>'both-salary-'.$run,'job_title'=>'Specialist','salary_mode'=>'gross','gross_salary'=>1000,'net_salary'=>850],['id'=>1,'role'=>'employee','employee_role'=>'hr','full_name'=>'HR Smoke']);if(($bothSalary['error']??'')!=='salary_value_mismatch')$errors[]='both_salary_values_accepted';
    $view = (string) file_get_contents(dirname(__DIR__) . '/views/hr/employees.php');
    foreach (['Yeni emekdas', 'create_hr_employee', 'update_hr_employee', 'data-hr-employee-edit', 'name="job_title"'] as $marker) if (!str_contains($view, $marker)) $errors[] = 'ui_marker_missing:' . $marker;
    foreach(['overview.php','performance.php','documents.php'] as $hrView){$source=(string)file_get_contents(dirname(__DIR__).'/views/hr/'.$hrView);if(!str_contains($source,"['job_title']"))$errors[]='job_title_view_missing:'.$hrView;}
    $serviceSource=(string)file_get_contents(dirname(__DIR__).'/hr-employee-service.php');if(!str_contains($serviceSource,'app_hr_record_audit($db'))$errors[]='transactional_hr_audit_missing';
} finally {
    if ($accountId > 0) {
        $db->prepare('DELETE FROM hr_employee_compensations WHERE employee_account_id=:id')->execute(['id' => $accountId]);
        $db->prepare('DELETE FROM portal_accounts WHERE id=:id')->execute(['id' => $accountId]);
    }
    if($invalidAccountId>0){$db->prepare('DELETE FROM hr_employee_compensations WHERE employee_account_id=:id')->execute(['id'=>$invalidAccountId]);$db->prepare('DELETE FROM portal_accounts WHERE id=:id')->execute(['id'=>$invalidAccountId]);}
    $db->prepare('DELETE FROM mgs_operation_actions WHERE actor_name="HR Smoke"')->execute();
}
echo json_encode(['status' => $errors === [] ? 'passed' : 'failed', 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($errors === [] ? 0 : 1);
