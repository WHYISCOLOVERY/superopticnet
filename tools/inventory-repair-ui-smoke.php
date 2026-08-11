<?php
declare(strict_types=1);

require dirname(__DIR__) . '/inventory-operations-lib.php';
require dirname(__DIR__) . '/inventory-module-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

// Covers the view-layer contract added on top of the already-tested repair
// state machine (tools/stage1-service-data-contract-smoke.php): does
// app_inventory_inspection_page() surface the right repair_id/repair_status
// for the "open repair" button's eligibility check. inventory_asset_repairs
// has UNIQUE(inspection_id), so once ANY repair exists for an inspection
// (including a cancelled one) a second create_inventory_repair call for the
// same inspection always fails -- the button must never reappear after that,
// and the row must keep showing the repair's real (possibly terminal)
// status instead.
class InventoryRepairUiSmokeFailure extends RuntimeException
{
}

function inventory_repair_ui_smoke_fail(string $message, int $code = 1): never
{
    throw new InventoryRepairUiSmokeFailure($message, $code);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$runId = bin2hex(random_bytes(6));
$warehouseActor = [
    'id' => 0,
    'role' => 'employee',
    'account_type' => 'operator',
    'employee_role' => 'warehouse',
    'full_name' => 'Repair UI Smoke',
];
$assetId = 0;
$returnId = 0;
$inspectionId = 0;
$repairIds = [];

$cleanup = function () use ($db, &$assetId, &$returnId, &$inspectionId, &$repairIds): void {
    foreach ($repairIds as $id) {
        $db->prepare('DELETE FROM inventory_asset_repair_events WHERE repair_id = :id')->execute(['id' => $id]);
        $db->prepare('DELETE FROM inventory_asset_repairs WHERE id = :id')->execute(['id' => $id]);
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
};

// Mirrors the view's own eligibility formula exactly (views/inventory/returns-inspection.php).
$isEligibleForOpen = static function (array $row): bool {
    return in_array((string) ($row['inspection_status'] ?? ''), ['repair', 'rma'], true)
        && (string) ($row['return_status'] ?? '') === 'inspected'
        && (int) ($row['repair_id'] ?? 0) === 0;
};

$findInspectionRow = static function () use ($db, &$inspectionId, &$assetCode): ?array {
    $page = app_inventory_inspection_page($db, ['search' => $assetCode]);
    foreach ((array) ($page['rows'] ?? []) as $row) {
        if ((int) ($row['id'] ?? 0) === $inspectionId) {
            return $row;
        }
    }
    return null;
};

try {
    $assetCode = 'REPAIR-UI-' . strtoupper($runId);
    $db->prepare(
        'INSERT INTO inventory_assets (
            asset_code,serial_number,serial_number_normalized,ownership_type,lifecycle_status,
            condition_status,current_location_type,current_location_id,notes,metadata_json
         ) VALUES (
            :asset_code,:serial_number,:serial_number,"company","assigned","used",
            "employee","repair-ui-smoke",:notes,:metadata_json
         )'
    )->execute([
        'asset_code' => $assetCode,
        'serial_number' => $assetCode . '-SN',
        'notes' => 'Repair UI smoke fixture',
        'metadata_json' => app_db_json_encode(['run_id' => $runId]),
    ]);
    $assetId = (int) $db->lastInsertId();

    $returnResult = app_inventory_receive_asset_return($db, [
        'asset_id' => $assetId,
        'reason' => 'Repair UI smoke return',
        'idempotency_key' => 'repair-ui-return:' . $runId,
    ], $warehouseActor);
    $returnId = (int) ($returnResult['id'] ?? 0);
    if (empty($returnResult['ok']) || $returnId <= 0) {
        inventory_repair_ui_smoke_fail('return_seed_failed');
    }

    $inspectionResult = app_inventory_inspect_asset_return($db, [
        'return_id' => $returnId,
        'inspection_status' => 'repair',
        'reason' => 'Repair UI smoke inspection',
        'idempotency_key' => 'repair-ui-inspect:' . $runId,
    ], $warehouseActor);
    $inspectionId = (int) ($inspectionResult['id'] ?? 0);
    if (empty($inspectionResult['ok']) || $inspectionId <= 0) {
        inventory_repair_ui_smoke_fail('inspection_seed_failed');
    }

    // 1. Freshly inspected, no repair yet -> must be eligible, repair_id must be 0/absent.
    $row = $findInspectionRow();
    if ($row === null) {
        inventory_repair_ui_smoke_fail('inspection_row_not_found_before_repair');
    }
    if ((int) ($row['repair_id'] ?? 0) !== 0) {
        inventory_repair_ui_smoke_fail('unexpected_repair_id_before_any_repair_created');
    }
    if (!$isEligibleForOpen($row)) {
        inventory_repair_ui_smoke_fail('should_be_eligible_before_repair_opened:' . json_encode($row, JSON_UNESCAPED_SLASHES));
    }

    // 2. Open a repair -> must now be linked, pending, NOT eligible for a second open.
    $repairResult = app_inventory_create_asset_repair($db, [
        'inspection_id' => $inspectionId,
        'reason' => 'Repair UI smoke repair #1',
        'idempotency_key' => 'repair-ui-repair1:' . $runId,
    ], $warehouseActor);
    $repairId1 = (int) ($repairResult['id'] ?? 0);
    if (empty($repairResult['ok']) || $repairId1 <= 0) {
        inventory_repair_ui_smoke_fail('repair_create_failed');
    }
    $repairIds[] = $repairId1;

    $row = $findInspectionRow();
    if ($row === null || (int) ($row['repair_id'] ?? 0) !== $repairId1 || (string) ($row['repair_status'] ?? '') !== 'pending') {
        inventory_repair_ui_smoke_fail('inspection_row_not_linked_to_new_repair:' . json_encode($row, JSON_UNESCAPED_SLASHES));
    }
    if ($isEligibleForOpen($row)) {
        inventory_repair_ui_smoke_fail('should_not_be_eligible_while_repair_pending');
    }

    // Also check the repair-rma tab's own query surfaces the same repair with the fields the view renders.
    $rmaPage = app_inventory_rma_page($db, ['search' => $assetCode]);
    $rmaRow = null;
    foreach ((array) ($rmaPage['rows'] ?? []) as $candidate) {
        if ((int) ($candidate['repair_id'] ?? 0) === $repairId1) {
            $rmaRow = $candidate;
            break;
        }
    }
    if ($rmaRow === null || (string) ($rmaRow['repair_status'] ?? '') !== 'pending' || (string) ($rmaRow['asset_code'] ?? '') !== $assetCode) {
        inventory_repair_ui_smoke_fail('rma_page_missing_or_wrong_fixture_row');
    }

    // 3. Cancel it -> return status reverts to 'inspected', but the row must still show the
    //    (now cancelled) repair and must NOT offer "open repair" again -- the DB has
    //    UNIQUE(inspection_id) on inventory_asset_repairs, so a second create for the same
    //    inspection can never succeed. Showing the button here would be a dead-end affordance.
    $cancelResult = app_inventory_update_asset_repair_status($db, [
        'repair_id' => $repairId1,
        'status' => 'cancelled',
        'reason' => 'Repair UI smoke cancel',
        'idempotency_key' => 'repair-ui-cancel1:' . $runId,
    ], $warehouseActor);
    if (empty($cancelResult['ok'])) {
        inventory_repair_ui_smoke_fail('cancel_transition_failed');
    }

    $row = $findInspectionRow();
    if ($row === null) {
        inventory_repair_ui_smoke_fail('inspection_row_not_found_after_cancel');
    }
    if ((string) ($row['return_status'] ?? '') !== 'inspected') {
        inventory_repair_ui_smoke_fail('return_status_did_not_revert_after_cancel:' . json_encode($row, JSON_UNESCAPED_SLASHES));
    }
    if ((int) ($row['repair_id'] ?? 0) !== $repairId1 || (string) ($row['repair_status'] ?? '') !== 'cancelled') {
        inventory_repair_ui_smoke_fail('cancelled_repair_not_surfaced_on_inspection_row:' . json_encode($row, JSON_UNESCAPED_SLASHES));
    }
    if ($isEligibleForOpen($row)) {
        inventory_repair_ui_smoke_fail('must_not_offer_reopen_after_cancellation_unique_constraint_would_reject_it');
    }

    // 4. Prove that constraint for real: a second create_inventory_repair for the same
    //    inspection must fail cleanly (not crash), confirming the UI is right to hide the button.
    $repairResult2 = app_inventory_create_asset_repair($db, [
        'inspection_id' => $inspectionId,
        'reason' => 'Repair UI smoke repair #2 (should be rejected)',
        'idempotency_key' => 'repair-ui-repair2:' . $runId,
    ], $warehouseActor);
    if (!empty($repairResult2['ok'])) {
        $repairIds[] = (int) ($repairResult2['id'] ?? 0);
        inventory_repair_ui_smoke_fail('second_repair_unexpectedly_succeeded_unique_constraint_not_enforced');
    }

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'asset_code' => $assetCode,
        'inspection_id' => $inspectionId,
        'repair_id' => $repairId1,
        'second_create_reason' => (string) ($repairResult2['error'] ?? ''),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit($exception->getCode() ?: 1);
}
