<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/finance-operations-lib.php';
require dirname(__DIR__) . '/inventory-operations-lib.php';

$db = app_db();
$checks = [];
$errors = [];
$expect = static function (bool $condition, string $name) use (&$checks, &$errors): void {
    $checks[$name] = $condition;
    if (!$condition) {
        $errors[] = $name;
    }
};

if (!$db instanceof PDO) {
    echo json_encode(['ok' => false, 'errors' => ['db_not_ready']], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$flagKeys = [
    'stage1_finance_ledger',
    'stage1_e_invoice_atomic_import',
    'stage1_inventory_repair_contract',
];
$flagState = [];
$runId = bin2hex(random_bytes(8));
$financeActor = [
    'id' => 0,
    'role' => 'employee',
    'account_type' => 'operator',
    'employee_role' => 'finance',
    'full_name' => 'Stage 1 Finance Smoke',
];
$hrActor = [
    'id' => 0,
    'role' => 'employee',
    'account_type' => 'operator',
    'employee_role' => 'hr',
    'full_name' => 'Stage 1 HR Smoke',
];
$warehouseActor = [
    'id' => 0,
    'role' => 'employee',
    'account_type' => 'operator',
    'employee_role' => 'warehouse',
    'full_name' => 'Stage 1 Warehouse Smoke',
];
$supportActor = [
    'id' => 0,
    'role' => 'employee',
    'account_type' => 'operator',
    'employee_role' => 'support',
    'full_name' => 'Stage 1 Support Smoke',
];

$financeSourceIds = [];
$documentKeys = [];
$batchKeys = [];
$payrollId = 0;
$assetId = 0;
$returnId = 0;
$inspectionId = 0;
$repairId = 0;

try {
    $loadFlag = $db->prepare(
        'SELECT flag_key,is_enabled,rollout_percent,role_scope,user_scope_json,note,source
           FROM app_feature_flags WHERE flag_key = :flag_key'
    );
    foreach ($flagKeys as $flagKey) {
        $loadFlag->execute(['flag_key' => $flagKey]);
        $row = $loadFlag->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('feature_flag_missing:' . $flagKey);
        }
        $flagState[$flagKey] = $row;
    }
    $enableFlag = $db->prepare(
        'UPDATE app_feature_flags
            SET is_enabled = 1, rollout_percent = 100, note = :note
          WHERE flag_key = :flag_key'
    );
    foreach ($flagKeys as $flagKey) {
        $enableFlag->execute(['flag_key' => $flagKey, 'note' => 'Temporary Stage 1 service smoke']);
    }

    $sourceId = 'stage1-smoke:' . $runId;
    $financeSourceIds[] = $sourceId;
    $sourceInput = [
        'entry_type' => 'expense',
        'title' => 'Stage 1 idempotency smoke',
        'category' => 'stage1_smoke',
        'amount' => 42.15,
        'currency' => 'AZN',
        'transaction_date' => date('Y-m-d'),
        'source_type' => 'stage1_smoke',
        'source_id' => $sourceId,
        'idempotency_key' => 'stage1-smoke:' . $runId,
        'correlation_id' => 'stage1-correlation-' . $runId,
        'payload' => ['run_id' => $runId],
    ];
    $firstEntry = app_finance_post_source_entry($db, $sourceInput, $financeActor);
    $retryEntry = app_finance_post_source_entry($db, $sourceInput, $financeActor);
    $deniedEntry = app_finance_post_source_entry(
        $db,
        $sourceInput + ['source_id' => $sourceId . ':denied', 'idempotency_key' => 'denied:' . $runId],
        $supportActor
    );
    $expect(!empty($firstEntry['ok']) && empty($firstEntry['existing']), 'finance_source_first_write');
    $expect(
        !empty($retryEntry['ok'])
        && !empty($retryEntry['existing'])
        && (int) ($retryEntry['id'] ?? 0) === (int) ($firstEntry['id'] ?? -1),
        'finance_source_retry_is_idempotent'
    );
    $expect(($deniedEntry['error'] ?? '') === 'forbidden', 'finance_source_role_denied');
    $count = $db->prepare('SELECT COUNT(*) FROM finance_entries WHERE source_type = "stage1_smoke" AND source_id = :source_id');
    $count->execute(['source_id' => $sourceId]);
    $expect((int) $count->fetchColumn() === 1, 'finance_source_single_row');

    $payrollInsert = $db->prepare(
        'INSERT INTO hr_payroll_records (
            employee_account_id,employee_name,period_month,gross_salary,leave_pay,taxable_travel_amount,
            payroll_tax_base,employee_income_tax,employee_social_insurance,
            employee_unemployment_insurance,employee_health_insurance,employee_total_deductions,
            net_salary,employer_social_insurance,employer_unemployment_insurance,
            employer_health_insurance,employer_total_contributions,employer_total_cost,status,
            calculation_json,note,created_by_name
         ) VALUES (
            NULL,:employee_name,:period_month,1000,20,10,1030,24,30,5,6,65,
            965,120,4,5,129,1159,"approved",:calculation_json,:note,:created_by_name
         )'
    );
    $payrollInsert->execute([
        'employee_name' => 'Stage 1 Payroll ' . $runId,
        'period_month' => date('Y-m-01'),
        'calculation_json' => app_db_json_encode(['run_id' => $runId]),
        'note' => 'Stage 1 payroll bridge smoke',
        'created_by_name' => 'Stage 1 HR Smoke',
    ]);
    $payrollId = (int) $db->lastInsertId();
    $hrSourceId = 'payroll:' . $payrollId;
    $financeSourceIds[] = $hrSourceId;
    $hrFirst = app_mgs_hr_record_finance_expense('payroll', $payrollId, $hrActor, $db);
    $hrRetry = app_mgs_hr_record_finance_expense('payroll', $payrollId, $hrActor, $db);
    $expect(!empty($hrFirst['ok']) && empty($hrFirst['existing']), 'hr_payroll_finance_first_write');
    $expect(
        !empty($hrRetry['ok'])
        && !empty($hrRetry['existing'])
        && (int) ($hrRetry['finance_entry_id'] ?? 0) === (int) ($hrFirst['finance_entry_id'] ?? -1),
        'hr_payroll_finance_retry_is_idempotent'
    );
    $payloadQuery = $db->prepare(
        'SELECT amount,payload_json FROM finance_entries WHERE source_type = "hr" AND source_id = :source_id'
    );
    $payloadQuery->execute(['source_id' => $hrSourceId]);
    $payrollFinance = $payloadQuery->fetch(PDO::FETCH_ASSOC) ?: [];
    $payrollPayload = json_decode((string) ($payrollFinance['payload_json'] ?? ''), true);
    $expect(abs((float) ($payrollFinance['amount'] ?? 0) - 1159.0) < 0.001, 'hr_payroll_employer_cost_posted');
    $expect(
        is_array($payrollPayload)
        && abs((float) ($payrollPayload['payroll_breakdown']['employee_income_tax'] ?? -1) - 24.0) < 0.001
        && abs((float) ($payrollPayload['payroll_breakdown']['net_salary'] ?? -1) - 965.0) < 0.001,
        'hr_payroll_tax_breakdown_preserved'
    );

    $documentA = 'S1-A-' . $runId;
    $documentB = 'S1-B-' . $runId;
    $successRows = [
        [
            'ledger_type' => 'payable',
            'document_no' => $documentA,
            'counterparty_name' => 'Stage 1 Supplier ' . $runId,
            'counterparty_type' => 'supplier',
            'amount' => 100.25,
            'due_date' => date('Y-m-d'),
            'note' => 'Stage 1 e-invoice',
        ],
        [
            'ledger_type' => 'receivable',
            'document_no' => $documentB,
            'counterparty_name' => 'Stage 1 Customer ' . $runId,
            'counterparty_type' => 'customer',
            'amount' => 55.75,
            'due_date' => date('Y-m-d'),
            'note' => 'Stage 1 e-invoice',
        ],
    ];
    foreach ($successRows as $row) {
        $documentKeys[] = hash(
            'sha256',
            implode('|', [
                $row['ledger_type'],
                mb_strtolower(trim($row['counterparty_name']), 'UTF-8'),
                mb_strtolower(trim($row['document_no']), 'UTF-8'),
            ])
        );
    }
    $successImport = app_mgs_import_finance_e_invoice_rows($successRows, $financeActor, $db);
    $batchKeys[] = (string) ($successImport['batch_id'] ?? '');
    $replayImport = app_mgs_import_finance_e_invoice_rows($successRows, $financeActor, $db);
    $expect(
        !empty($successImport['ok'])
        && (int) ($successImport['imported'] ?? 0) === 2
        && empty($successImport['existing']),
        'e_invoice_atomic_import_success'
    );
    $expect(
        !empty($replayImport['ok'])
        && !empty($replayImport['existing'])
        && (string) ($replayImport['batch_id'] ?? '') === (string) ($successImport['batch_id'] ?? 'missing'),
        'e_invoice_request_replay_is_idempotent'
    );
    $referenceQuery = $db->prepare(
        'SELECT reference_no FROM finance_entries
          WHERE source_type = "e_invoice" AND source_id = :source_id'
    );
    $referenceQuery->execute(['source_id' => $documentKeys[0]]);
    $expect(
        (string) $referenceQuery->fetchColumn() === 'EINV-' . strtoupper(substr($documentKeys[0], 0, 24)),
        'e_invoice_reference_is_collision_safe'
    );

    $newFailureDocument = 'S1-ROLLBACK-' . $runId;
    $failureCounterparty = 'Stage 1 Rollback Supplier ' . $runId;
    $failureDocumentKey = hash(
        'sha256',
        implode('|', ['payable', mb_strtolower($failureCounterparty, 'UTF-8'), mb_strtolower($newFailureDocument, 'UTF-8')])
    );
    $documentKeys[] = $failureDocumentKey;
    $failureRows = [
        [
            'ledger_type' => 'payable',
            'document_no' => $newFailureDocument,
            'counterparty_name' => $failureCounterparty,
            'counterparty_type' => 'supplier',
            'amount' => 77,
            'due_date' => date('Y-m-d'),
        ],
        $successRows[0],
    ];
    $failureImport = app_mgs_import_finance_e_invoice_rows($failureRows, $financeActor, $db);
    $batchKeys[] = (string) ($failureImport['batch_id'] ?? '');
    $expect(
        empty($failureImport['ok']) && ($failureImport['error'] ?? '') === 'e_invoice_atomic_import_failed',
        'e_invoice_batch_failure_reported'
    );
    $atomicCount = $db->prepare(
        'SELECT
            (SELECT COUNT(*) FROM finance_entries WHERE source_type = "e_invoice" AND source_id = :document_key)
          + (SELECT COUNT(*) FROM finance_debt_ledger WHERE source_document_key = :document_key)
          + (SELECT COUNT(*) FROM finance_e_invoice_imports WHERE document_key = :document_key)'
    );
    $atomicCount->execute(['document_key' => $failureDocumentKey]);
    $expect((int) $atomicCount->fetchColumn() === 0, 'e_invoice_failed_batch_business_rows_rolled_back');

    $assetCode = 'S1-ASSET-' . strtoupper($runId);
    $assetInsert = $db->prepare(
        'INSERT INTO inventory_assets (
            asset_code,serial_number,serial_number_normalized,ownership_type,lifecycle_status,
            condition_status,current_location_type,current_location_id,notes,metadata_json
         ) VALUES (
            :asset_code,:serial_number,:serial_number,"company","assigned","used",
            "employee","stage1-smoke",:notes,:metadata_json
         )'
    );
    $assetInsert->execute([
        'asset_code' => $assetCode,
        'serial_number' => $assetCode . '-SN',
        'notes' => 'Stage 1 inventory smoke',
        'metadata_json' => app_db_json_encode(['run_id' => $runId]),
    ]);
    $assetId = (int) $db->lastInsertId();
    $returnInput = [
        'asset_id' => $assetId,
        'reason' => 'Returned for Stage 1 inspection',
        'idempotency_key' => 'stage1-return:' . $runId,
        'correlation_id' => 'stage1-inventory-' . $runId,
        'location_type' => 'warehouse',
        'location_id' => 'SMOKE',
    ];
    $returnFirst = app_inventory_receive_asset_return($db, $returnInput, $warehouseActor);
    $returnRetry = app_inventory_receive_asset_return($db, $returnInput, $warehouseActor);
    $returnId = (int) ($returnFirst['id'] ?? 0);
    $expect(!empty($returnFirst['ok']) && empty($returnFirst['existing']), 'inventory_return_first_write');
    $expect(
        !empty($returnRetry['ok'])
        && !empty($returnRetry['existing'])
        && (int) ($returnRetry['id'] ?? 0) === $returnId,
        'inventory_return_retry_is_idempotent'
    );
    $assetState = $db->prepare('SELECT lifecycle_status,condition_status FROM inventory_assets WHERE id = :id');
    $assetState->execute(['id' => $assetId]);
    $quarantineState = $assetState->fetch(PDO::FETCH_ASSOC) ?: [];
    $expect(
        ($quarantineState['lifecycle_status'] ?? '') === 'return_quarantine'
        && ($quarantineState['condition_status'] ?? '') === 'pending_inspection',
        'inventory_return_is_quarantined'
    );

    $inspectionInput = [
        'return_id' => $returnId,
        'inspection_status' => 'repair',
        'reason' => 'Hardware failure confirmed',
        'idempotency_key' => 'stage1-inspection:' . $runId,
        'correlation_id' => 'stage1-inventory-' . $runId,
    ];
    $inspectionFirst = app_inventory_inspect_asset_return($db, $inspectionInput, $warehouseActor);
    $inspectionRetry = app_inventory_inspect_asset_return($db, $inspectionInput, $warehouseActor);
    $inspectionId = (int) ($inspectionFirst['id'] ?? 0);
    $expect(!empty($inspectionFirst['ok']) && empty($inspectionFirst['existing']), 'inventory_inspection_first_write');
    $expect(
        !empty($inspectionRetry['ok'])
        && !empty($inspectionRetry['existing'])
        && (int) ($inspectionRetry['id'] ?? 0) === $inspectionId,
        'inventory_inspection_retry_is_idempotent'
    );

    $repairInput = [
        'inspection_id' => $inspectionId,
        'reason' => 'Repair workflow smoke',
        'vendor_name' => 'Stage 1 Repair Vendor',
        'idempotency_key' => 'stage1-repair:' . $runId,
        'correlation_id' => 'stage1-inventory-' . $runId,
    ];
    $repairFirst = app_inventory_create_asset_repair($db, $repairInput, $warehouseActor);
    $repairRetry = app_inventory_create_asset_repair($db, $repairInput, $warehouseActor);
    $repairId = (int) ($repairFirst['id'] ?? 0);
    $expect(!empty($repairFirst['ok']) && empty($repairFirst['existing']), 'inventory_repair_first_write');
    $expect(
        !empty($repairRetry['ok'])
        && !empty($repairRetry['existing'])
        && (int) ($repairRetry['id'] ?? 0) === $repairId,
        'inventory_repair_retry_is_idempotent'
    );
    $invalidTransition = app_inventory_update_asset_repair_status($db, [
        'repair_id' => $repairId,
        'status' => 'repaired',
        'reason' => 'Invalid direct completion',
        'idempotency_key' => 'stage1-repair-invalid:' . $runId,
    ], $warehouseActor);
    $expect(($invalidTransition['error'] ?? '') === 'repair_transition_invalid', 'inventory_invalid_transition_denied');
    $toRepair = app_inventory_update_asset_repair_status($db, [
        'repair_id' => $repairId,
        'status' => 'in_repair',
        'reason' => 'Repair started',
        'idempotency_key' => 'stage1-repair-start:' . $runId,
    ], $warehouseActor);
    $toComplete = app_inventory_update_asset_repair_status($db, [
        'repair_id' => $repairId,
        'status' => 'repaired',
        'reason' => 'Repair completed',
        'idempotency_key' => 'stage1-repair-complete:' . $runId,
    ], $warehouseActor);
    $expect(!empty($toRepair['ok']) && !empty($toComplete['ok']), 'inventory_repair_valid_transitions');
    $finalState = $db->prepare(
        'SELECT a.lifecycle_status,a.condition_status,r.return_status,p.repair_status
           FROM inventory_assets a
           INNER JOIN inventory_asset_returns r ON r.asset_id = a.id
           INNER JOIN inventory_asset_repairs p ON p.return_id = r.id
          WHERE a.id = :id'
    );
    $finalState->execute(['id' => $assetId]);
    $finalRow = $finalState->fetch(PDO::FETCH_ASSOC) ?: [];
    $expect(
        ($finalRow['lifecycle_status'] ?? '') === 'in_warehouse'
        && ($finalRow['condition_status'] ?? '') === 'repaired'
        && ($finalRow['return_status'] ?? '') === 'closed'
        && ($finalRow['repair_status'] ?? '') === 'repaired',
        'inventory_repair_closes_return_and_restocks_asset'
    );
    $eventCount = $db->prepare('SELECT COUNT(*) FROM inventory_asset_repair_events WHERE repair_id = :repair_id');
    $eventCount->execute(['repair_id' => $repairId]);
    $expect((int) $eventCount->fetchColumn() === 3, 'inventory_repair_immutable_event_chain');
} catch (Throwable $exception) {
    $errors[] = 'unhandled_exception:' . $exception->getMessage();
} finally {
    try {
        if ($repairId > 0) {
            $db->prepare('DELETE FROM inventory_asset_repair_events WHERE repair_id = :id')->execute(['id' => $repairId]);
            $db->prepare('DELETE FROM inventory_asset_repairs WHERE id = :id')->execute(['id' => $repairId]);
        }
        if ($inspectionId > 0) {
            $db->prepare('DELETE FROM inventory_asset_inspections WHERE id = :id')->execute(['id' => $inspectionId]);
        }
        if ($returnId > 0) {
            $db->prepare('DELETE FROM inventory_asset_returns WHERE id = :id')->execute(['id' => $returnId]);
        }
        if ($assetId > 0) {
            $db->prepare('DELETE FROM inventory_assets WHERE id = :id')->execute(['id' => $assetId]);
        }

        foreach (array_unique(array_filter($batchKeys)) as $batchKey) {
            $db->prepare('DELETE FROM finance_e_invoice_imports WHERE import_batch = :batch_key')
                ->execute(['batch_key' => $batchKey]);
        }
        foreach (array_unique(array_filter($documentKeys)) as $documentKey) {
            $db->prepare('DELETE FROM finance_e_invoice_imports WHERE document_key = :document_key')
                ->execute(['document_key' => $documentKey]);
            $db->prepare('DELETE FROM finance_debt_ledger WHERE source_document_key = :document_key')
                ->execute(['document_key' => $documentKey]);
            $db->prepare('DELETE FROM finance_entries WHERE source_type = "e_invoice" AND source_id = :document_key')
                ->execute(['document_key' => $documentKey]);
        }
        foreach (array_unique(array_filter($batchKeys)) as $batchKey) {
            $db->prepare('DELETE FROM finance_e_invoice_batches WHERE batch_key = :batch_key')
                ->execute(['batch_key' => $batchKey]);
        }
        foreach (array_unique(array_filter($financeSourceIds)) as $sourceId) {
            $sourceType = str_starts_with($sourceId, 'payroll:') ? 'hr' : 'stage1_smoke';
            $db->prepare('DELETE FROM finance_entries WHERE source_type = :source_type AND source_id = :source_id')
                ->execute(['source_type' => $sourceType, 'source_id' => $sourceId]);
        }
        if ($payrollId > 0) {
            $db->prepare('DELETE FROM hr_payroll_records WHERE id = :id')->execute(['id' => $payrollId]);
        }
    } catch (Throwable $cleanupException) {
        $errors[] = 'fixture_cleanup_failed:' . $cleanupException->getMessage();
    }

    foreach ($flagState as $flagKey => $row) {
        try {
            $restore = $db->prepare(
                'UPDATE app_feature_flags
                    SET is_enabled = :is_enabled,rollout_percent = :rollout_percent,
                        role_scope = :role_scope,user_scope_json = :user_scope_json,
                        note = :note,source = :source
                  WHERE flag_key = :flag_key'
            );
            $restore->execute([
                'is_enabled' => (int) $row['is_enabled'],
                'rollout_percent' => (float) $row['rollout_percent'],
                'role_scope' => $row['role_scope'],
                'user_scope_json' => $row['user_scope_json'],
                'note' => $row['note'],
                'source' => $row['source'],
                'flag_key' => $flagKey,
            ]);
        } catch (Throwable $restoreException) {
            $errors[] = 'feature_flag_restore_failed:' . $flagKey . ':' . $restoreException->getMessage();
        }
    }
}

$errors = array_values(array_unique($errors));
echo json_encode([
    'ok' => $errors === [],
    'checks' => $checks,
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
