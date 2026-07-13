<?php
declare(strict_types=1);

require __DIR__ . '/../mgs-integration-lib.php';

$errors = [];
$db = app_db();

if (!$db instanceof PDO) {
    $errors[] = 'db_not_ready';
} else {
    app_mgs_ensure_schema($db);
    foreach (['hr_leave_requests', 'hr_travel_requests', 'hr_employee_documents'] as $table) {
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
    'app_mgs_hr_leave_average',
    'app_mgs_save_hr_leave_request',
    'app_mgs_save_hr_travel_request',
    'app_mgs_save_hr_employee_document',
    'app_mgs_hr_update_record_status',
    'app_mgs_hr_document_completeness',
    'app_mgs_hr_required_document_types',
] as $functionName) {
    if (!function_exists($functionName)) {
        $errors[] = $functionName . '_missing';
    }
}

if ($db instanceof PDO
    && function_exists('app_mgs_hr_leave_average')
    && function_exists('app_mgs_save_hr_leave_request')
    && function_exists('app_mgs_save_hr_travel_request')
    && function_exists('app_mgs_save_hr_employee_document')
) {
    $employeeId = 0;
    $recordIds = [];
    $created = [
        'leave' => 0,
        'travel' => 0,
        'document' => 0,
    ];
    try {
        $employeeInsert=$db->prepare('INSERT INTO portal_accounts(login,password_hash,role,account_type,employee_role,full_name,status,is_active,payload_json)VALUES(:login,:password,"employee","employee","hr","Smoke Leave","Aktiv",1,"{}")');$employeeInsert->execute(['login'=>'smoke-leave-'.bin2hex(random_bytes(4)),'password'=>password_hash('smoke',PASSWORD_DEFAULT)]);$employeeId=(int)$db->lastInsertId();
        $insertPayroll = $db->prepare(
            'INSERT INTO hr_payroll_records (
                employee_account_id, employee_name, period_month, profile_code, gross_salary,
                payroll_tax_base, employee_income_tax, employee_social_insurance,
                employee_unemployment_insurance, employee_health_insurance, employee_total_deductions,
                net_salary, employer_social_insurance, employer_unemployment_insurance, employer_health_insurance,
                employer_total_contributions, employer_total_cost, status, calculation_json
             ) VALUES (
                :employee_account_id, "Smoke Leave", :period_month, "az_private_non_oil_2026", :gross_salary,
                :gross_salary, 0, 0,
                0, 0, 0,
                :gross_salary, 0, 0, 0,
                0, :gross_salary, "paid", "{}"
             )'
        );
        foreach ([['2026-04-01', 1000], ['2026-05-01', 1200]] as [$period, $gross]) {
            $insertPayroll->execute([
                'employee_account_id' => $employeeId,
                'period_month' => $period,
                'gross_salary' => $gross,
            ]);
            $recordIds[] = (int) $db->lastInsertId();
        }

        $average = app_mgs_hr_leave_average($employeeId, '2026-06');
        if ((int) ($average['months_count'] ?? 0) !== 2) {
            $errors[] = 'leave_average_months_failed';
        }
        if (abs((float) ($average['average_monthly_salary'] ?? 0) - 1100.0) > 0.01) {
            $errors[] = 'leave_average_monthly_failed';
        }

        $leave = app_mgs_save_hr_leave_request([
            'employee_account_id' => $employeeId,
            'employee_name' => 'Smoke Leave',
            'period_month' => '2026-06',
            'leave_type' => 'annual',
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-14',
            'leave_days' => 5,
        ], ['id' => 987654, 'full_name' => 'Smoke HR'], $db);
        $created['leave'] = (int) ($leave['id'] ?? 0);
        if (empty($leave['ok']) || abs((float) ($leave['record']['calculated_leave_pay'] ?? 0) - 180.92) > 0.05) {
            $errors[] = 'leave_roundtrip_failed';
        }

        $travel = app_mgs_save_hr_travel_request([
            'employee_account_id' => $employeeId,
            'employee_name' => 'Smoke Leave',
            'destination' => 'Baku',
            'start_date' => '2026-06-20',
            'end_date' => '2026-06-21',
            'travel_days' => 2,
            'daily_rate' => 90,
            'taxable_travel_amount' => 10,
            'purpose' => 'Smoke travel',
        ], ['id' => 987654, 'full_name' => 'Smoke HR'], $db);
        $created['travel'] = (int) ($travel['id'] ?? 0);
        if (empty($travel['ok'])
            || abs((float) ($travel['record']['travel_allowance'] ?? 0) - 180.0) > 0.01
            || abs((float) ($travel['record']['tax_exempt_amount'] ?? 0) - 170.0) > 0.01
        ) {
            $errors[] = 'travel_roundtrip_failed';
        }

        $document = app_mgs_save_hr_employee_document([
            'employee_account_id' => $employeeId,
            'employee_name' => 'Smoke Leave',
            'document_type' => 'employment_contract',
            'status' => 'complete',
            'document_no' => 'SMOKE-DOC',
            'expires_at' => '2026-12-31',
        ], ['id' => 987654, 'full_name' => 'Smoke HR'], $db);
        $created['document'] = (int) ($document['id'] ?? 0);
        if (empty($document['ok']) || $created['document'] <= 0) {
            $errors[] = 'document_roundtrip_failed';
        } else {
            $statusUpdate = app_mgs_hr_update_record_status('document', $created['document'], 'pending', ['id' => 987654, 'full_name' => 'Smoke HR'], $db);
            if (($statusUpdate['error'] ?? '') !== 'status_transition_invalid') {
                $errors[] = 'document_backward_transition_accepted';
            }
        }
    } catch (Throwable $exception) {
        $errors[] = 'roundtrip_exception:' . $exception->getMessage();
    } finally {
        foreach ($recordIds as $id) {
            try {
                $cleanup = $db->prepare('DELETE FROM hr_payroll_records WHERE id = :id');
                $cleanup->execute(['id' => $id]);
            } catch (Throwable) {
            }
        }
        foreach ([
            'hr_leave_requests' => $created['leave'],
            'hr_travel_requests' => $created['travel'],
            'hr_employee_documents' => $created['document'],
        ] as $table => $id) {
            if ($id <= 0) {
                continue;
            }
            try {
                $cleanup = $db->prepare('DELETE FROM ' . $table . ' WHERE id = :id');
                $cleanup->execute(['id' => $id]);
            } catch (Throwable) {
            }
        }
        try {
            $cleanupActions = $db->prepare(
                'DELETE FROM mgs_operation_actions
                  WHERE entity_id = :entity_id
                    AND action_type IN ("hr_leave_request_create","hr_travel_request_create","hr_employee_document_save")'
            );
            $cleanupActions->execute(['entity_id' => (string) $employeeId]);
            if ($created['document'] > 0) {
                $cleanupStatus = $db->prepare('DELETE FROM mgs_operation_actions WHERE action_type = "hr_record_status_update" AND entity_id = :entity_id');
                $cleanupStatus->execute(['entity_id' => 'document:' . $created['document']]);
            }
        } catch (Throwable) {
        }
        if($employeeId>0){try{$db->prepare('DELETE FROM portal_accounts WHERE id=:id')->execute(['id'=>$employeeId]);}catch(Throwable){}}
    }
}

$operationsPage = (string) file_get_contents(__DIR__ . '/../operations-center.php');
foreach ([
    'data-hr-leave-average',
    'data-hr-travel-workflow',
    'data-hr-document-completeness',
    'name="action" value="create_hr_leave_request"',
    'name="action" value="create_hr_travel_request"',
    'name="action" value="create_hr_employee_document"',
    'name="document_type"',
    'name="daily_rate"',
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
