<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/mgs-integration-lib.php';
require_once dirname(__DIR__) . '/inventory-operations-lib.php';
require_once dirname(__DIR__) . '/inventory-module-lib.php';
require_once dirname(__DIR__) . '/audit-log-lib.php';

$db = app_db();
$errors = [];
$expect = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

if (!$db instanceof PDO) {
    echo json_encode(['status' => 'failed', 'errors' => ['db_not_ready']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

$run = date('YmdHis') . random_int(100, 999);
$itemId = 0;
$docId = 0;
$actor = [
    'id' => 910101,
    'role' => 'employee',
    'account_type' => 'operator',
    'employee_role' => 'warehouse_manager',
    'full_name' => 'Inventory Catalog Probe',
    'login' => 'inventory-catalog-smoke@superoptic.local',
];

try {
    app_inventory_ensure_operational_schema($db);
    app_audit_ensure_schema($db);

    foreach (['app_inventory_update_item', 'app_inventory_deactivate_item', 'app_inventory_adjust_stock'] as $functionName) {
        $expect(function_exists($functionName), $functionName . '_missing');
    }

    $insert = $db->prepare(
        'INSERT INTO inventory_stock_items(sku,item_name,category,unit,quantity_on_hand,minimum_quantity,status)
         VALUES(:sku,:name,"other","eded",5,1,"active")'
    );
    $insert->execute([
        'sku' => 'CAT-' . $run,
        'name' => 'Catalog Probe ' . $run,
    ]);
    $itemId = (int) $db->lastInsertId();
    app_inventory_lock_location_balance($db, $itemId, 'warehouse', 'MAIN');
    $db->prepare(
        'UPDATE inventory_location_balances
            SET available_quantity = 5
          WHERE item_id = :item_id AND location_type = "warehouse" AND location_id = "MAIN"'
    )->execute(['item_id' => $itemId]);

    $doc = app_inventory_write_document(
        $db,
        'SMK',
        'test_reference',
        'approved',
        ['destination_type' => 'warehouse', 'destination_id' => 'MAIN', 'note' => 'catalog smoke reference'],
        [['item_id' => $itemId, 'quantity' => 1, 'unit_price' => 1]],
        $actor
    );
    $docId = (int) ($doc['id'] ?? 0);

    if ($errors === []) {
        $missingReason = app_inventory_update_item($db, [
            'item_id' => $itemId,
            'item_name' => 'Should not save',
            'category' => 'other',
            'unit' => 'eded',
            'minimum_quantity' => 2,
            'reason' => '',
        ], $actor);
        $expect(($missingReason['error'] ?? '') === 'reason_required', 'update_without_reason_allowed');

        $updated = app_inventory_update_item($db, [
            'item_id' => $itemId,
            'item_name' => 'Updated Catalog Probe ' . $run,
            'category' => 'active_equipment',
            'unit' => 'paket',
            'minimum_quantity' => 2,
            'reason' => 'Catalog correction',
        ], $actor);
        $expect(($updated['ok'] ?? false) === true, 'catalog_update_failed');
        $row = $db->prepare('SELECT item_name, category, unit, minimum_quantity, status FROM inventory_stock_items WHERE id = :id');
        $row->execute(['id' => $itemId]);
        $item = $row->fetch(PDO::FETCH_ASSOC) ?: [];
        $expect((string) ($item['item_name'] ?? '') === 'Updated Catalog Probe ' . $run, 'item_name_not_updated');
        $expect((string) ($item['category'] ?? '') === 'active_equipment', 'category_not_updated');
        $expect((string) ($item['unit'] ?? '') === 'paket', 'unit_not_updated');
        $expect((float) ($item['minimum_quantity'] ?? 0) === 2.0, 'minimum_not_updated');
        $expect((string) ($item['status'] ?? '') === 'active', 'update_changed_status');

        $badAdjust = app_inventory_adjust_stock($db, [
            'item_id' => $itemId,
            'location_type' => 'warehouse',
            'location_id' => 'MAIN',
            'quantity_delta' => -99,
            'reason' => 'Impossible stock reduction',
        ], $actor);
        $expect(($badAdjust['error'] ?? '') === 'insufficient_stock', 'negative_stock_not_blocked');

        $missingAdjustReason = app_inventory_adjust_stock($db, [
            'item_id' => $itemId,
            'location_type' => 'warehouse',
            'location_id' => 'MAIN',
            'quantity_delta' => -1,
            'reason' => '',
        ], $actor);
        $expect(($missingAdjustReason['error'] ?? '') === 'reason_required', 'adjust_without_reason_allowed');

        $adjusted = app_inventory_adjust_stock($db, [
            'item_id' => $itemId,
            'location_type' => 'warehouse',
            'location_id' => 'MAIN',
            'quantity_delta' => -2,
            'reason' => 'Damaged during count',
        ], $actor);
        $expect(($adjusted['ok'] ?? false) === true, 'stock_adjustment_failed');
        $expect(app_inventory_company_quantity($db, $itemId) === 3.0, 'company_quantity_delta_wrong');
        $expect(app_inventory_location_quantity($db, $itemId, 'warehouse', 'MAIN') === 3.0, 'location_quantity_delta_wrong');

        $missingDeactivateReason = app_inventory_deactivate_item($db, [
            'item_id' => $itemId,
            'reason' => '',
        ], $actor);
        $expect(($missingDeactivateReason['error'] ?? '') === 'reason_required', 'deactivate_without_reason_allowed');

        $deactivated = app_inventory_deactivate_item($db, [
            'item_id' => $itemId,
            'reason' => 'No longer used in catalog',
        ], $actor);
        $expect(($deactivated['ok'] ?? false) === true, 'deactivate_failed');
        $status = $db->prepare('SELECT status FROM inventory_stock_items WHERE id = :id');
        $status->execute(['id' => $itemId]);
        $expect((string) $status->fetchColumn() === 'disabled', 'item_not_disabled');
        $referenceCount = $db->prepare('SELECT COUNT(*) FROM inventory_document_items WHERE document_id = :doc_id AND item_id = :item_id');
        $referenceCount->execute(['doc_id' => $docId, 'item_id' => $itemId]);
        $expect((int) $referenceCount->fetchColumn() === 1, 'referenced_item_document_line_removed');

        $events = $db->prepare(
            'SELECT action_type, reason, before_json, after_json
               FROM mgs_operation_actions
              WHERE entity_type = "inventory_item"
                AND entity_id = :item_id
                AND action_type IN ("inventory_item_updated","inventory_stock_adjusted","inventory_item_deactivated")'
        );
        $events->execute(['item_id' => (string) $itemId]);
        $auditRows = $events->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $expect(count($auditRows) === 3, 'inventory_audit_events_missing');
        foreach ($auditRows as $auditRow) {
            $expect((string) ($auditRow['reason'] ?? '') !== '', 'inventory_audit_reason_missing');
            $expect((string) ($auditRow['before_json'] ?? '') !== '', 'inventory_audit_before_missing');
            $expect((string) ($auditRow['after_json'] ?? '') !== '', 'inventory_audit_after_missing');
        }

        $catalogPage = app_inventory_catalog_page($db, ['limit' => 500, 'status' => 'disabled', 'search' => $run]);
        $expect(($catalogPage['limit'] ?? null) === 50, 'catalog_page_limit_not_capped');
        $expect((int) ($catalogPage['total'] ?? 0) >= 1, 'disabled_catalog_item_not_queryable');
    }
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $errors[] = 'exception:' . $exception->getMessage();
} finally {
    if ($docId > 0) {
        $db->prepare('DELETE FROM inventory_document_items WHERE document_id = :id')->execute(['id' => $docId]);
        $db->prepare('DELETE FROM inventory_documents WHERE id = :id')->execute(['id' => $docId]);
    }
    if ($itemId > 0) {
        $db->prepare('DELETE FROM inventory_location_balances WHERE item_id = :id')->execute(['id' => $itemId]);
        $db->prepare('DELETE FROM inventory_lifecycle_movements WHERE product_id = :id')->execute(['id' => $itemId]);
        $db->prepare('DELETE FROM inventory_stock_movements WHERE item_id = :id')->execute(['id' => $itemId]);
        $db->prepare('DELETE FROM inventory_stock_items WHERE id = :id')->execute(['id' => $itemId]);
    }
}

echo json_encode([
    'status' => $errors === [] ? 'passed' : 'failed',
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
