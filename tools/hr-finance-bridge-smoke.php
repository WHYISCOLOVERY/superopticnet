<?php
declare(strict_types=1);

require __DIR__ . '/../mgs-integration-lib.php';

$errors = [];
$db = app_db();

foreach ([
    'app_mgs_hr_update_record_status',
    'app_mgs_hr_record_finance_expense',
    'app_mgs_hr_cost_report_rows',
    'app_mgs_hr_finance_bridge_rows',
] as $functionName) {
    if (!function_exists($functionName)) {
        $errors[] = $functionName . '_missing';
    }
}

if (!$db instanceof PDO) {
    $errors[] = 'db_not_ready';
} elseif ($errors === []) {
    app_mgs_ensure_schema($db);
    $payrollId = 0;
    $employeeId = 0;
    $expenseEntityId = '';
    $hrActor = ['id' => 987654, 'full_name' => 'Smoke HR', 'employee_role' => 'hr'];

    try {
        $employeeInsert=$db->prepare('INSERT INTO portal_accounts(login,password_hash,role,account_type,employee_role,full_name,status,is_active,payload_json)VALUES(:login,:password,"employee","employee","hr","Smoke Finance Bridge","Aktiv",1,"{}")');$employeeInsert->execute(['login'=>'smoke-bridge-'.bin2hex(random_bytes(4)),'password'=>password_hash('smoke',PASSWORD_DEFAULT)]);$employeeId=(int)$db->lastInsertId();
        $payroll = app_mgs_save_hr_payroll_record([
            'employee_account_id' => $employeeId,
            'period_month' => '2026-06',
            'gross_salary' => 1000,
            'status' => 'draft',
        ], $hrActor, $db);
        $payrollId = (int) ($payroll['id'] ?? 0);
        $expenseEntityId = 'hr-payroll-' . $payrollId;
        if (empty($payroll['ok']) || $payrollId <= 0) {
            $errors[] = 'payroll_seed_failed';
        }

        if ($payrollId > 0) {
            $approved = app_mgs_hr_update_record_status('payroll', $payrollId, 'approved', $hrActor, $db, 'Smoke test approval');
            $statusResult = !empty($approved['ok']) ? app_mgs_hr_update_record_status('payroll', $payrollId, 'paid', $hrActor, $db, 'Smoke test payout') : $approved;
            if (empty($statusResult['ok']) || (string) ($statusResult['record']['status'] ?? '') !== 'paid') {
                $errors[] = 'payroll_status_update_failed';
            }

            $expense = app_mgs_hr_record_finance_expense('payroll', $payrollId, $hrActor, $db);
            if (empty($expense['ok']) || (float) ($expense['amount'] ?? 0) < 1188.99) {
                $errors[] = 'finance_expense_record_failed:' . (string) ($expense['error'] ?? 'amount_mismatch');
            }

            $expenseAgain = app_mgs_hr_record_finance_expense('payroll', $payrollId, $hrActor, $db);
            if (empty($expenseAgain['ok']) || (int) ($expenseAgain['action_id'] ?? 0) !== (int) ($expense['action_id'] ?? 0)) {
                $errors[] = 'finance_expense_idempotency_failed:' . (string) ($expenseAgain['error'] ?? 'action_mismatch');
            }

            $statement = $db->prepare(
                'SELECT status, amount, payload_json
                   FROM finance_entries
                  WHERE source_type = "hr"
                    AND source_id = :source_id
                  LIMIT 1'
            );
            $statement->execute(['source_id' => 'payroll:' . $payrollId]);
            $actionRow = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            if ((string) ($actionRow['status'] ?? '') !== 'posted') {
                $errors[] = 'finance_action_missing';
            }
            $payload = json_decode((string) ($actionRow['payload_json'] ?? ''), true);
            if (!is_array($payload)
                || (string) ($payload['source'] ?? '') !== 'hr_finance_bridge'
                || (string) ($payload['record_type'] ?? '') !== 'payroll'
                || (float) ($actionRow['amount'] ?? 0) < 1188.99
            ) {
                $errors[] = 'finance_action_payload_failed';
            }

            $reportRows = app_mgs_hr_cost_report_rows($db, 20);
            $reportHit = false;
            foreach ($reportRows as $row) {
                if ((string) ($row['record_type'] ?? '') === 'payroll'
                    && (int) ($row['record_id'] ?? 0) === $payrollId
                    && (string) ($row['status'] ?? '') === 'paid'
                    && (float) ($row['amount'] ?? 0) >= 1188.99
                ) {
                    $reportHit = true;
                    break;
                }
            }
            if (!$reportHit) {
                $errors[] = 'cost_report_row_missing';
            }

            $bridgeRows = app_mgs_hr_finance_bridge_rows($db, 10);
            $bridgeHit = false;
            foreach ($bridgeRows as $row) {
                if ((string) ($row['entity_id'] ?? '') === $expenseEntityId
                    && (string) ($row['payload']['source'] ?? '') === 'hr_finance_bridge'
                ) {
                    $bridgeHit = true;
                    break;
                }
            }
            if (!$bridgeHit) {
                $errors[] = 'bridge_row_missing';
            }
        }
    } catch (Throwable $exception) {
        $errors[] = 'roundtrip_exception:' . $exception->getMessage();
    } finally {
        if ($payrollId > 0) {
            try {
                $cleanup = $db->prepare('DELETE FROM hr_payroll_records WHERE id = :id');
                $cleanup->execute(['id' => $payrollId]);
            } catch (Throwable) {
            }
        }
        if($employeeId>0){try{$db->prepare('DELETE FROM portal_accounts WHERE id=:id')->execute(['id'=>$employeeId]);}catch(Throwable){}}
        if ($payrollId > 0) {
            try {
                $db->prepare('DELETE FROM finance_entries WHERE source_type = "hr" AND source_id = :source_id')
                    ->execute(['source_id' => 'payroll:' . $payrollId]);
            } catch (Throwable) {
            }
        }
        try {
            $cleanup = $db->prepare(
                'DELETE FROM mgs_operation_actions
                  WHERE (action_type IN ("hr_payroll_record_create","hr_finance_expense_recorded","hr_record_status_update")
                         AND (entity_id = :employee_name OR entity_id = :expense_entity_id OR entity_id = :record_entity_id))'
            );
            $cleanup->execute([
                'employee_name' => (string)$employeeId,
                'expense_entity_id' => $expenseEntityId,
                'record_entity_id' => 'payroll:' . $payrollId,
            ]);
        } catch (Throwable) {
        }
    }
}

$operationsPage = (string) file_get_contents(__DIR__ . '/../operations-center.php');
foreach ([
    'data-hr-finance-bridge',
    'data-hr-cost-report',
    'data-hr-print-report',
    'name="action" value="update_hr_record_status"',
    'name="action" value="record_hr_finance_expense"',
    'name="hr_record_type"',
    'name="hr_record_status"',
    'window.print()',
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
