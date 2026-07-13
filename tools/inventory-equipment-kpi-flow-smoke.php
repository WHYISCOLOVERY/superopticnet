<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require_once dirname(__DIR__) . '/mgs-integration-lib.php';

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}

$runId = date('YmdHis') . '-' . bin2hex(random_bytes(3));
$tech = 'FLOWTECH-' . $runId;
$customer = 'FLOWCUSTOMER-' . $runId;
$ticketNo = 'FLOWTICKET-' . $runId;
$productId = 0;
$modelId = 0;
$assetId = 0;

function inventory_equipment_kpi_flow_cleanup(PDO $db, string $runId, string $tech, string $customer, string $ticketNo, int $assetId, int $productId, int $modelId): void
{
    $db->prepare('DELETE FROM employee_work_snapshots WHERE employee_name = :tech')->execute(['tech' => $tech]);
    $db->prepare('DELETE FROM portal_tickets WHERE ticket_no = :ticket')->execute(['ticket' => $ticketNo]);
    $db->prepare('DELETE FROM customer_equipment_assignments WHERE customer_code = :customer OR asset_id = :asset')->execute(['customer' => $customer, 'asset' => $assetId]);
    $db->prepare('DELETE FROM inventory_lifecycle_movements WHERE movement_no LIKE :run OR customer_code = :customer OR technician_code = :tech OR ticket_no = :ticket')->execute([
        'run' => '%' . $runId . '%',
        'customer' => $customer,
        'tech' => $tech,
        'ticket' => $ticketNo,
    ]);
    if ($assetId > 0) {
        $db->prepare('DELETE FROM inventory_assets WHERE id = :id')->execute(['id' => $assetId]);
    }
    if ($productId > 0) {
        $db->prepare('DELETE FROM inventory_stock_items WHERE id = :id')->execute(['id' => $productId]);
    }
    if ($modelId > 0) {
        $db->prepare('DELETE FROM inventory_asset_models WHERE id = :id')->execute(['id' => $modelId]);
    }
}

$result = [
    'status' => 'failed',
    'run_id' => $runId,
    'assertions' => [],
    'failed' => [],
];

try {
    $db->beginTransaction();

    $productId = app_mgs_inventory_upsert_stock_item($db, 'FLOW-MODEM-' . $runId, 'Flow GPON modem', 'modem', 'eded');
    $modelId = app_mgs_inventory_upsert_asset_model($db, 'HSGQ', 'Flow GPON ONT', 'modem');
    $assetId = app_mgs_inventory_create_asset($db, [
        'product_id' => $productId,
        'model_id' => $modelId,
        'asset_code' => 'FLOW-ASSET-' . $runId,
        'serial_number' => 'FLOW-SN-' . $runId,
        'mac_address' => '02:55:10:' . strtoupper(substr(str_replace('-', '', $runId), -6, 2)) . ':AA:01',
        'ownership_type' => 'company',
        'lifecycle_status' => 'in_warehouse',
        'condition_status' => 'new',
    ]);

    app_mgs_inventory_record_lifecycle_movement($db, [
        'movement_no' => 'FLOW-ASSIGN-' . $runId,
        'movement_type' => 'assign_to_technician',
        'product_id' => $productId,
        'asset_id' => $assetId,
        'quantity' => 1,
        'from_location_type' => 'warehouse',
        'from_location_id' => 'MAIN',
        'to_location_type' => 'technician',
        'to_location_id' => $tech,
        'technician_code' => $tech,
        'performed_by' => 'warehouse',
    ]);
    $db->prepare('UPDATE inventory_assets SET current_location_type = "technician", current_location_id = :tech WHERE id = :id')
        ->execute(['tech' => $tech, 'id' => $assetId]);

    $beforeBalance = app_mgs_inventory_technician_balance($db, $productId, $tech);
    app_mgs_inventory_install_asset($db, $assetId, $customer, $tech, $ticketNo);
    $afterBalance = app_mgs_inventory_technician_balance($db, $productId, $tech);

    $assetState = $db->prepare('SELECT current_location_type, current_location_id, lifecycle_status, condition_status FROM inventory_assets WHERE id = :id');
    $assetState->execute(['id' => $assetId]);
    $asset = $assetState->fetch(PDO::FETCH_ASSOC) ?: [];

    $assignmentState = $db->prepare('SELECT COUNT(*) FROM customer_equipment_assignments WHERE asset_id = :asset AND customer_code = :customer AND assignment_status = "active"');
    $assignmentState->execute(['asset' => $assetId, 'customer' => $customer]);

    $movementState = $db->prepare(
        'SELECT COUNT(*)
         FROM inventory_lifecycle_movements
         WHERE asset_id = :asset
           AND movement_type = "customer_install"
           AND from_location_type = "technician"
           AND from_location_id = :tech
           AND to_location_type = "customer"
           AND to_location_id = :customer
           AND quantity = 1'
    );
    $movementState->execute(['asset' => $assetId, 'tech' => $tech, 'customer' => $customer]);

    $customerEquipment = app_mgs_customer_equipment_lifecycle($db, $customer, 5);

    app_db_insert_ticket([
        'ticket_no' => $ticketNo,
        'subscriber_code' => $customer,
        'contract_no' => $customer,
        'customer_name' => 'Flow Customer',
        'phone' => '+994500000000',
        'address' => 'Flow smoke address',
        'area' => 'Flow zone',
        'subject' => 'Modem qurasdirma',
        'message' => 'Inventory equipment KPI smoke ticket.',
        'status' => 'Aciqdir',
        'opened_at' => app_db_now(),
        'updated_at' => app_db_now(),
        'assigned_technician_name' => $tech,
        'created_by_role' => 'employee',
        'created_by_name' => 'Inventory smoke',
        'metadata_json' => app_db_json_encode(['smoke' => true, 'run_id' => $runId]),
    ]);
    $snapshot = app_mgs_rebuild_employee_work_snapshots($db);
    $kpiStatement = $db->prepare('SELECT inventory_issued_count FROM employee_work_snapshots WHERE employee_name = :tech AND work_date = CURDATE() ORDER BY id DESC LIMIT 1');
    $kpiStatement->execute(['tech' => $tech]);
    $kpiInventoryCount = (int) ($kpiStatement->fetchColumn() ?: 0);

    $result['assertions'] = [
        'technician_balance_before_install' => $beforeBalance,
        'technician_balance_after_install' => $afterBalance,
        'asset_current_location_type' => (string) ($asset['current_location_type'] ?? ''),
        'asset_current_location_id' => (string) ($asset['current_location_id'] ?? ''),
        'asset_lifecycle_status' => (string) ($asset['lifecycle_status'] ?? ''),
        'active_customer_assignment_count' => (int) $assignmentState->fetchColumn(),
        'customer_install_movement_count' => (int) $movementState->fetchColumn(),
        'customer_equipment_visible' => $customerEquipment !== [],
        'kpi_snapshot_rebuild_ok' => (bool) ($snapshot['ok'] ?? false),
        'kpi_inventory_issued_count' => $kpiInventoryCount,
        'kpi_lifecycle_count_includes_install' => $kpiInventoryCount >= 2,
    ];

    $expected = [
        'technician_balance_before_install' => 1.0,
        'technician_balance_after_install' => 0.0,
        'asset_current_location_type' => 'customer',
        'asset_current_location_id' => $customer,
        'asset_lifecycle_status' => 'installed',
        'active_customer_assignment_count' => 1,
        'customer_install_movement_count' => 1,
        'customer_equipment_visible' => true,
        'kpi_snapshot_rebuild_ok' => true,
        'kpi_lifecycle_count_includes_install' => true,
    ];

    foreach ($expected as $key => $value) {
        if (($result['assertions'][$key] ?? null) !== $value) {
            $result['failed'][$key] = [
                'expected' => $value,
                'actual' => $result['assertions'][$key] ?? null,
            ];
        }
    }

    if ($db->inTransaction()) {
        $db->rollBack();
    } else {
        inventory_equipment_kpi_flow_cleanup($db, $runId, $tech, $customer, $ticketNo, $assetId, $productId, $modelId);
    }
    $result['status'] = $result['failed'] === [] ? 'ok' : 'failed';
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    } else {
        inventory_equipment_kpi_flow_cleanup($db, $runId, $tech, $customer, $ticketNo, $assetId, $productId, $modelId);
    }
    $result['failed']['exception'] = $exception->getMessage();
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['status'] === 'ok' ? 0 : 1);
