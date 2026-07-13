<?php
declare(strict_types=1);

require __DIR__ . '/../mgs-integration-lib.php';

$errors = [];
$db = app_db();

if (!$db instanceof PDO) {
    $errors[] = 'db_not_ready';
} else {
    app_mgs_ensure_schema($db);
    foreach (['hr_payroll_legal_profiles', 'hr_payroll_records'] as $table) {
        try {
            $statement = $db->prepare('SHOW TABLES LIKE :table_name');
            $statement->execute(['table_name' => $table]);
            if (!$statement->fetchColumn()) {
                $errors[] = $table . '_missing';
            }
        } catch (Throwable $exception) {
            $errors[] = $table . '_check_failed:' . $exception->getMessage();
        }
    }
}

foreach ([
    'app_mgs_hr_default_legal_profile',
    'app_mgs_hr_payroll_preview',
    'app_mgs_hr_payroll_rows',
    'app_mgs_save_hr_payroll_record',
] as $functionName) {
    if (!function_exists($functionName)) {
        $errors[] = $functionName . '_missing';
    }
}

if (function_exists('app_mgs_hr_payroll_preview')) {
    $preview = app_mgs_hr_payroll_preview(['gross_salary' => 1000]);
    foreach ([
        'employee_income_tax',
        'employee_social_insurance',
        'employee_unemployment_insurance',
        'employee_health_insurance',
        'employee_total_deductions',
        'net_salary',
        'employer_social_insurance',
        'employer_unemployment_insurance',
        'employer_health_insurance',
        'employer_total_cost',
    ] as $key) {
        if (!array_key_exists($key, $preview)) {
            $errors[] = 'preview_' . $key . '_missing';
        }
    }
    $expected = [
        'employee_income_tax' => 24.0,
        'employee_social_insurance' => 86.0,
        'employee_unemployment_insurance' => 5.0,
        'employee_health_insurance' => 20.0,
        'net_salary' => 865.0,
        'employer_total_cost' => 1189.0,
    ];
    foreach ($expected as $key => $value) {
        if (array_key_exists($key, $preview) && abs((float) $preview[$key] - $value) > 0.01) {
            $errors[] = 'preview_' . $key . '_expected_' . $value . '_got_' . (string) $preview[$key];
        }
    }
}

if ($db instanceof PDO && function_exists('app_mgs_save_hr_payroll_record')) {
    $recordId = 0;
    $employeeId = 0;
    try {
        $employeeInsert=$db->prepare('INSERT INTO portal_accounts(login,password_hash,role,account_type,employee_role,full_name,status,is_active,payload_json)VALUES(:login,:password,"employee","employee","hr","Smoke Payroll","Aktiv",1,"{}")');$employeeInsert->execute(['login'=>'smoke-payroll-'.bin2hex(random_bytes(4)),'password'=>password_hash('smoke',PASSWORD_DEFAULT)]);$employeeId=(int)$db->lastInsertId();
        $result = app_mgs_save_hr_payroll_record([
            'employee_account_id' => $employeeId,
            'period_month' => '2026-06',
            'gross_salary' => 1000,
            'status' => 'draft',
        ], ['id' => 987654, 'full_name' => 'Smoke HR'], $db);
        $recordId = (int) ($result['id'] ?? 0);
        if (empty($result['ok']) || $recordId <= 0) {
            $errors[] = 'payroll_save_failed';
        } else {
            $statement = $db->prepare('SELECT net_salary, employer_total_cost FROM hr_payroll_records WHERE id = :id');
            $statement->execute(['id' => $recordId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            if (abs((float) ($row['net_salary'] ?? 0) - 865.0) > 0.01
                || abs((float) ($row['employer_total_cost'] ?? 0) - 1189.0) > 0.01
            ) {
                $errors[] = 'payroll_roundtrip_values_failed';
            }
            $duplicate=app_mgs_save_hr_payroll_record(['employee_account_id'=>$employeeId,'period_month'=>'2026-06','gross_salary'=>1000],['id'=>987654,'full_name'=>'Smoke HR'],$db);if(($duplicate['error']??'')!=='payroll_period_exists')$errors[]='duplicate_payroll_accepted';
        }
    } catch (Throwable $exception) {
        $errors[] = 'payroll_roundtrip_exception:' . $exception->getMessage();
    } finally {
        if ($recordId > 0) {
            try {
                $cleanup = $db->prepare('DELETE FROM hr_payroll_records WHERE id = :id');
                $cleanup->execute(['id' => $recordId]);
            } catch (Throwable) {
            }
        }
        if($employeeId>0){try{$db->prepare('DELETE FROM portal_accounts WHERE id=:id')->execute(['id'=>$employeeId]);}catch(Throwable){}}
        try {
            if($employeeId>0)$db->prepare("DELETE FROM mgs_operation_actions WHERE action_type='hr_payroll_record_create' AND entity_id=:id")->execute(['id'=>(string)$employeeId]);
        } catch (Throwable) {
        }
    }
}

$operationsPage = (string) file_get_contents(__DIR__ . '/../operations-center.php');
foreach ([
    'data-hr-payroll-legal-analysis',
    'name="action" value="create_hr_payroll_record"',
    'name="gross_salary"',
    'name="leave_days"',
    'name="travel_days"',
    'employee_income_tax',
    'employer_total_cost',
] as $marker) {
    if (!str_contains($operationsPage, $marker)) {
        $errors[] = $marker . '_missing';
    }
}

echo json_encode([
    'ok' => $errors === [],
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
