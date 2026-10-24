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
$positionIds = [];
$errors = [];
$hrActor = ['id' => 1, 'role' => 'employee', 'employee_role' => 'hr', 'full_name' => 'HR Smoke'];

try {
    if (!$db instanceof PDO || !app_hr_position_schema_ready($db)) {
        throw new RuntimeException('hr_position_schema_not_ready');
    }
    $insertPosition = $db->prepare(
        'INSERT INTO hr_positions
            (position_key,title,recommended_account_type,recommended_employee_role,module_keys_json,is_active)
         VALUES (:position_key,:title,"technician","technician",:module_keys_json,1)'
    );
    $firstTitle = 'Field Specialist ' . $run;
    $secondTitle = 'Senior Field Specialist ' . $run;
    foreach ([$firstTitle, $secondTitle] as $index => $title) {
        $insertPosition->execute([
            'position_key' => 'smoke_' . $run . '_' . $index,
            'title' => $title,
            'module_keys_json' => app_db_json_encode(['inventory', 'network']),
        ]);
        $positionIds[] = (int) $db->lastInsertId();
    }

    $result = app_hr_create_employee($db, [
        'full_name' => 'Smoke Employee ' . $run,
        'login' => 'hr-smoke-' . $run,
        'phone' => '+994501234567',
        'position_id' => $positionIds[0],
        'branch_id' => '1',
        'hire_date' => date('Y-m-d'),
        'salary_mode' => 'gross',
        'gross_salary' => 1000,
        'employee_role' => 'admin',
        'account_type' => 'admin',
        'is_active' => 1,
    ], $hrActor);
    $accountId = (int) ($result['account_id'] ?? 0);
    $row = $accountId > 0 ? app_db_account_by_id($accountId) : null;
    if (empty($result['ok']) || !is_array($row)) {
        $errors[] = 'employee_create_failed';
    }
    if (($row['job_title'] ?? '') !== $firstTitle) {
        $errors[] = 'catalog_position_not_saved';
    }
    if (($row['employee_role'] ?? 'x') !== '' || ($row['account_type'] ?? '') !== 'employee' || (int) ($row['is_active'] ?? 1) !== 0) {
        $errors[] = 'role_escalation_possible';
    }
    $payload = is_array($row) ? app_db_account_payload($row) : [];
    if ((int) ($payload['employee']['position_id'] ?? 0) !== $positionIds[0]) {
        $errors[] = 'position_id_not_saved';
    }

    $page = app_hr_employee_page($db, ['search' => 'hr-smoke-' . $run, 'limit' => 50]);
    if (!in_array($accountId, array_map('intval', array_column((array) ($page['rows'] ?? []), 'id')), true)) {
        $errors[] = 'inactive_employee_hidden';
    }

    $updated = app_hr_update_employee($db, [
        'employee_account_id' => $accountId,
        'full_name' => 'Smoke Employee Updated ' . $run,
        'login' => 'hr-smoke-' . $run,
        'position_id' => $positionIds[1],
        'hire_date' => date('Y-m-d'),
        'branch_id' => '1',
    ], $hrActor);
    $updatedRow = app_db_account_by_id($accountId);
    if (empty($updated['ok']) || ($updatedRow['job_title'] ?? '') !== $secondTitle || ($updatedRow['employee_role'] ?? 'x') !== '') {
        $errors[] = 'employee_update_failed';
    }
    if (is_array($updatedRow)) {
        $updatedPayload = app_db_account_payload($updatedRow);
        $updatedPayload['access'] = ['module_keys' => ['inventory', 'network']];
        $db->prepare('UPDATE portal_accounts SET payload_json=:payload WHERE id=:id')->execute([
            'payload' => app_db_json_encode($updatedPayload),
            'id' => $accountId,
        ]);
        $permissionRow = app_db_account_by_id($accountId);
        $sessionUser = is_array($permissionRow) ? app_db_user_session_payload($permissionRow) : [];
        if (app_portal_employee_module_keys($sessionUser) !== ['inventory', 'network']) {
            $errors[] = 'explicit_module_permissions_not_applied';
        }
    }

    $forbidden = app_hr_create_employee($db, [
        'full_name' => 'Denied',
        'login' => 'denied-' . $run,
        'position_id' => $positionIds[0],
    ], ['role' => 'employee', 'employee_role' => 'support']);
    if (($forbidden['error'] ?? '') !== 'forbidden') {
        $errors[] = 'hr_permission_not_enforced';
    }
    $forbiddenPosition = app_hr_save_position($db, [
        'title' => 'Denied Position ' . $run,
        'change_reason' => 'Smoke',
        'is_active' => 1,
    ], ['role' => 'employee', 'employee_role' => 'hr', 'account_type' => 'operator']);
    if (($forbiddenPosition['error'] ?? '') !== 'forbidden') {
        $errors[] = 'position_admin_permission_not_enforced';
    }

    $invalidSalary = app_hr_create_employee($db, [
        'full_name' => 'Invalid Salary',
        'login' => 'invalid-salary-' . $run,
        'position_id' => $positionIds[0],
        'salary_mode' => 'gross',
        'gross_salary' => 0,
        'net_salary' => 1000,
    ], $hrActor);
    $invalidAccountId = (int) ($invalidSalary['account_id'] ?? 0);
    if (($invalidSalary['error'] ?? '') !== 'salary_value_mismatch' || $invalidAccountId !== 0) {
        $errors[] = 'salary_mode_mismatch_accepted';
    }

    $bothSalary = app_hr_create_employee($db, [
        'full_name' => 'Both Salary',
        'login' => 'both-salary-' . $run,
        'position_id' => $positionIds[0],
        'salary_mode' => 'gross',
        'gross_salary' => 1000,
        'net_salary' => 850,
    ], $hrActor);
    if (($bothSalary['error'] ?? '') !== 'salary_value_mismatch') {
        $errors[] = 'both_salary_values_accepted';
    }

    $view = (string) file_get_contents(dirname(__DIR__) . '/views/hr/employees.php');
    foreach (['create_hr_employee', 'update_hr_employee', 'data-hr-employee-edit', 'name="position_id"', 'admin-positions.php', 'name="address"'] as $marker) {
        if (!str_contains($view, $marker)) {
            $errors[] = 'ui_marker_missing:' . $marker;
        }
    }
    if (preg_match('/<input[^>]+name="job_title"/', $view) === 1) {
        $errors[] = 'free_text_job_title_still_present';
    }
    foreach (['overview.php', 'performance.php', 'documents.php'] as $hrView) {
        $source = (string) file_get_contents(dirname(__DIR__) . '/views/hr/' . $hrView);
        if (!str_contains($source, "['job_title']")) {
            $errors[] = 'job_title_view_missing:' . $hrView;
        }
    }
    $serviceSource = (string) file_get_contents(dirname(__DIR__) . '/hr-employee-service.php');
    if (!str_contains($serviceSource, 'app_hr_record_audit($db')) {
        $errors[] = 'transactional_hr_audit_missing';
    }
    if (str_contains($serviceSource, 'ALTER TABLE portal_accounts')) {
        $errors[] = 'runtime_hr_schema_write_present';
    }
    $adminSource = (string) file_get_contents(dirname(__DIR__) . '/admin-users.php');
    foreach (['name="module_keys[]"', 'admin-positions.php', 'Portal modul icazeleri'] as $marker) {
        if (!str_contains($adminSource, $marker)) {
            $errors[] = 'admin_permission_ui_missing:' . $marker;
        }
    }
} finally {
    foreach (array_filter([$accountId, $invalidAccountId]) as $id) {
        $db->prepare('DELETE FROM hr_employee_compensations WHERE employee_account_id=:id')->execute(['id' => $id]);
        $db->prepare('DELETE FROM portal_accounts WHERE id=:id')->execute(['id' => $id]);
    }
    $db->prepare('DELETE FROM mgs_operation_actions WHERE actor_name="HR Smoke"')->execute();
    if ($positionIds !== []) {
        $placeholders = implode(',', array_fill(0, count($positionIds), '?'));
        $db->prepare('DELETE FROM hr_positions WHERE id IN (' . $placeholders . ')')->execute($positionIds);
    }
}

echo json_encode(['status' => $errors === [] ? 'passed' : 'failed', 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($errors === [] ? 0 : 1);
