<?php
declare(strict_types=1);

require __DIR__ . '/../mgs-integration-lib.php';

$errors = [];
$db = app_db();

foreach ([
    'app_mgs_save_inventory_depreciation',
    'app_mgs_finance_depreciation_rows',
    'app_mgs_finance_printable_inventory_rows',
    'app_mgs_finance_employee_property_rows',
    'app_mgs_save_finance_debt_record',
    'app_mgs_finance_debt_rows',
    'app_mgs_finance_debt_summary',
] as $functionName) {
    if (!function_exists($functionName)) {
        $errors[] = $functionName . '_missing';
    }
}

if (!$db instanceof PDO) {
    $errors[] = 'db_not_ready';
} elseif ($errors === []) {
    app_mgs_ensure_schema($db);
    foreach (['finance_asset_depreciations', 'finance_debt_ledger'] as $table) {
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

    $runId = bin2hex(random_bytes(4));
    $stockId = 0;
    $modelId = 0;
    $assetId = 0;
    $debtIds = [];

    try {
        $stockId = app_mgs_inventory_upsert_stock_item($db, 'FIN-SMOKE-' . $runId, 'Finance Smoke ONT', 'ont_modem', 'eded');
        $modelId = app_mgs_inventory_upsert_asset_model($db, 'SmokeVendor', 'FinanceAsset' . $runId, 'ont');
        $assetId = app_mgs_inventory_create_asset($db, [
            'product_id' => $stockId,
            'model_id' => $modelId,
            'asset_code' => 'FIN-ASSET-' . $runId,
            'serial_number' => 'FIN-SN-' . $runId,
            'mac_address' => 'AA:BB:CC:' . substr($runId, 0, 2) . ':' . substr($runId, 2, 2) . ':' . substr($runId, 4, 2),
            'ownership_type' => 'company',
            'lifecycle_status' => 'in_use',
            'condition_status' => 'new',
            'current_location_type' => 'technician',
            'current_location_id' => '987654',
        ]);

        $depreciation = app_mgs_save_inventory_depreciation([
            'asset_id' => $assetId,
            'asset_label' => 'Finance Smoke ONT',
            'acquisition_cost' => 100,
            'depreciation_rate' => 100,
            'fiscal_year' => 2026,
            'writeoff_threshold' => 1,
            'request_writeoff' => 1,
            'note' => 'Smoke depreciation',
        ], ['id' => 987654, 'full_name' => 'Smoke Finance'], $db);
        if (empty($depreciation['ok'])
            || abs((float) ($depreciation['record']['depreciation_amount'] ?? 0) - 100.0) > 0.01
            || abs((float) ($depreciation['record']['net_book_value'] ?? 1) - 0.0) > 0.01
            || (string) ($depreciation['record']['writeoff_status'] ?? '') !== 'pending_writeoff'
        ) {
            $errors[] = 'depreciation_roundtrip_failed';
        }
        $depreciationId = (int) ($depreciation['id'] ?? 0);

        // Maker-checker (vision 19.2 "asset silinmesi"): an eligible writeoff
        // must NOT flip the asset's lifecycle_status until a different admin
        // confirms it separately.
        $assetStatus = $db->prepare('SELECT lifecycle_status FROM inventory_assets WHERE id = :id');
        $assetStatus->execute(['id' => $assetId]);
        if ((string) $assetStatus->fetchColumn() === 'written_off') {
            $errors[] = 'asset_writeoff_applied_before_confirmation';
        }

        $creatorActor = ['id' => 987654, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'full_name' => 'Smoke Finance'];
        $selfConfirm = app_mgs_confirm_inventory_writeoff($db, $depreciationId, $creatorActor);
        if (!empty($selfConfirm['ok']) || ($selfConfirm['error'] ?? '') !== 'creator_cannot_confirm') {
            $errors[] = 'creator_was_able_to_confirm_own_writeoff';
        }

        $checkerActor = ['id' => 987655, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'full_name' => 'Smoke Finance Checker'];
        $checkerConfirm = app_mgs_confirm_inventory_writeoff($db, $depreciationId, $checkerActor);
        if (empty($checkerConfirm['ok'])) {
            $errors[] = 'checker_could_not_confirm_writeoff:' . (string) ($checkerConfirm['error'] ?? '');
        }

        $assetStatus->execute(['id' => $assetId]);
        if ((string) $assetStatus->fetchColumn() !== 'written_off') {
            $errors[] = 'asset_writeoff_status_failed';
        }

        $depreciationRows = app_mgs_finance_depreciation_rows($db, 20);
        $depreciationHit = false;
        foreach ($depreciationRows as $row) {
            if ((int) ($row['asset_id'] ?? 0) === $assetId && (string) ($row['writeoff_status'] ?? '') === 'written_off') {
                $depreciationHit = true;
                break;
            }
        }
        if (!$depreciationHit) {
            $errors[] = 'depreciation_rows_missing';
        }

        $inventoryRows = app_mgs_finance_printable_inventory_rows($db, 50);
        $inventoryHit = false;
        foreach ($inventoryRows as $row) {
            if ((string) ($row['asset_code'] ?? '') === 'FIN-ASSET-' . $runId) {
                $inventoryHit = true;
                break;
            }
        }
        if (!$inventoryHit) {
            $errors[] = 'printable_inventory_row_missing';
        }

        $employeeRows = app_mgs_finance_employee_property_rows($db, '987654', 50);
        $employeeHit = false;
        foreach ($employeeRows as $row) {
            if ((string) ($row['asset_code'] ?? '') === 'FIN-ASSET-' . $runId
                && (string) ($row['employee_ref'] ?? '') === '987654'
            ) {
                $employeeHit = true;
                break;
            }
        }
        if (!$employeeHit) {
            $errors[] = 'employee_property_row_missing';
        }

        $receivable = app_mgs_save_finance_debt_record([
            'ledger_type' => 'receivable',
            'counterparty_name' => 'Smoke Customer',
            'counterparty_type' => 'customer',
            'amount' => 300,
            'document_no' => 'EQ-' . $runId,
            'due_date' => '2026-12-31',
            'note' => 'Smoke receivable',
        ], ['id' => 987654, 'full_name' => 'Smoke Finance'], $db);
        $payable = app_mgs_save_finance_debt_record([
            'ledger_type' => 'payable',
            'counterparty_name' => 'Smoke Supplier',
            'counterparty_type' => 'supplier',
            'amount' => 120,
            'document_no' => 'SUP-' . $runId,
            'due_date' => '2026-12-31',
            'note' => 'Smoke payable',
        ], ['id' => 987654, 'full_name' => 'Smoke Finance'], $db);
        $debtIds = [(int) ($receivable['id'] ?? 0), (int) ($payable['id'] ?? 0)];
        if (empty($receivable['ok']) || empty($payable['ok'])) {
            $errors[] = 'debt_records_save_failed';
        }

        $summary = app_mgs_finance_debt_summary($db);
        if ((float) ($summary['receivable_open'] ?? 0) < 300 || (float) ($summary['payable_open'] ?? 0) < 120) {
            $errors[] = 'debt_summary_failed';
        }

        $debtRows = app_mgs_finance_debt_rows($db, 'all', 20);
        $debtHit = false;
        foreach ($debtRows as $row) {
            if ((string) ($row['document_no'] ?? '') === 'EQ-' . $runId
                && (string) ($row['ledger_type'] ?? '') === 'receivable'
            ) {
                $debtHit = true;
                break;
            }
        }
        if (!$debtHit) {
            $errors[] = 'debt_rows_missing';
        }
    } catch (Throwable $exception) {
        $errors[] = 'roundtrip_exception:' . $exception->getMessage();
    } finally {
        foreach ($debtIds as $id) {
            if ($id <= 0) {
                continue;
            }
            try {
                $cleanup = $db->prepare('DELETE FROM finance_debt_ledger WHERE id = :id');
                $cleanup->execute(['id' => $id]);
            } catch (Throwable) {
            }
        }
        if ($assetId > 0) {
            try {
                $cleanup = $db->prepare('DELETE FROM finance_asset_depreciations WHERE asset_id = :asset_id');
                $cleanup->execute(['asset_id' => $assetId]);
            } catch (Throwable) {
            }
            try {
                $cleanup = $db->prepare('DELETE FROM inventory_assets WHERE id = :id');
                $cleanup->execute(['id' => $assetId]);
            } catch (Throwable) {
            }
        }
        if ($stockId > 0) {
            try {
                $cleanup = $db->prepare('DELETE FROM inventory_stock_items WHERE id = :id');
                $cleanup->execute(['id' => $stockId]);
            } catch (Throwable) {
            }
        }
        if ($modelId > 0) {
            try {
                $cleanup = $db->prepare('DELETE FROM inventory_asset_models WHERE id = :id');
                $cleanup->execute(['id' => $modelId]);
            } catch (Throwable) {
            }
        }
        try {
            $cleanup = $db->prepare(
                'DELETE FROM mgs_operation_actions
                  WHERE action_type IN ("finance_asset_depreciation","finance_debt_record")
                    AND (entity_id = :asset_entity OR entity_id LIKE :doc_prefix)'
            );
            $cleanup->execute([
                'asset_entity' => 'asset:' . $assetId,
                'doc_prefix' => '%-' . $runId,
            ]);
        } catch (Throwable) {
        }
    }
}

$operationsPage = (string) file_get_contents(__DIR__ . '/../operations-center.php')
    . (string) file_get_contents(__DIR__ . '/../views/finance/assets.php')
    . (string) file_get_contents(__DIR__ . '/../views/finance/reconciliation.php');
foreach ([
    'data-finance-asset-depreciation',
    'data-finance-debt-ledger',
    'data-finance-printable-inventory',
    'data-finance-printable-employee-assets',
    'data-finance-print',
    'name="action" value="save_asset_depreciation"',
    'name="action" value="create_finance_debt_record"',
    'name="depreciation_rate"',
    'name="ledger_type"',
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
