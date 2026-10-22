<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__, 4) . '/inventory-operations-lib.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') app_mobile_json_response(null, 405, 'Yalnız POST sorğusu dəstəklənir.');
$user = app_mobile_authenticate();
if (!app_inventory_actor_can_approve($user)) app_mobile_json_response(null, 403, 'Qaytarmanı yalnız admin təsdiqləyə bilər.');
$documentId = (int)(app_mobile_request_body()['document_id'] ?? 0);
if ($documentId <= 0) app_mobile_json_response(null, 422, 'Qaytarma sənədi seçilməyib.');
$db = app_db();
$db->beginTransaction();
try {
    $select = $db->prepare('SELECT * FROM inventory_documents WHERE id=? FOR UPDATE');
    $select->execute([$documentId]);
    $doc = $select->fetch(PDO::FETCH_ASSOC);
    if (!$doc || $doc['document_type'] !== 'employee_return' || $doc['status'] !== 'pending') throw new InvalidArgumentException('Gözləyən qaytarma sənədi tapılmadı.');
    $employeeId = (int)$doc['source_location_id'];
    $lines = $db->prepare('SELECT * FROM inventory_document_items WHERE document_id=? ORDER BY line_no');
    $lines->execute([$documentId]);
    $returnLines = $lines->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($returnLines === []) throw new InvalidArgumentException('Qaytarma sənədində mal yoxdur.');
    foreach ($returnLines as $line) {
        $source = app_inventory_lock_location_balance($db, (int)$line['item_id'], 'employee', (string)$employeeId);
        if ((float)($source['available_quantity'] ?? 0) < (float)$line['quantity']) throw new InvalidArgumentException('Əməkdaşın qalığı dəyişib; sənədi yenidən yoxlayın.');
        $destination = app_inventory_lock_location_balance($db, (int)$line['item_id'], (string)$doc['destination_type'], (string)$doc['destination_id']);
        $db->prepare('UPDATE inventory_location_balances SET available_quantity=available_quantity-? WHERE id=?')->execute([(float)$line['quantity'], $source['id']]);
        $db->prepare('UPDATE inventory_location_balances SET available_quantity=available_quantity+? WHERE id=?')->execute([(float)$line['quantity'], $destination['id']]);
        app_inventory_lifecycle($db, 'return_to_warehouse', (int)$line['item_id'], (float)$line['quantity'], 'employee', (string)$employeeId, (string)$doc['destination_type'], (string)$doc['destination_id'], 'employee return accepted', $user, $doc['document_no'].'-'.$line['line_no'], app_mgs_hr_employee_name($db, $employeeId, (string)$employeeId));
        $serial = clean_text((string)($line['serial_number'] ?? ''));
        $mac = clean_text((string)($line['mac_address'] ?? ''));
        if ($serial !== '' || $mac !== '') {
            $assetUpdate = $db->prepare(
                'UPDATE inventory_assets
                    SET current_location_type="warehouse", current_location_id=?, lifecycle_status="available", updated_at=NOW()
                  WHERE product_id=?
                    AND ((?<>"" AND serial_number=?) OR (?<>"" AND mac_address=?))'
            );
            $assetUpdate->execute([(string)$doc['destination_id'], (int)$line['item_id'], $serial, $serial, $mac, $mac]);
        }
    }
    $db->prepare('UPDATE inventory_documents SET status="approved",approved_by_account_id=?,approved_by_name=?,approved_at=NOW() WHERE id=?')->execute([(int)$user['id'], app_inventory_actor_name($user), $documentId]);
    app_inventory_record_audit($db, 'inventory_return_confirmed', $doc['document_no'], 'executed', $user, ['document_id'=>$documentId]);
    $db->commit();
    app_mobile_json_response(['id'=>$documentId, 'document_no'=>$doc['document_no']]);
} catch (InvalidArgumentException $e) {
    if ($db->inTransaction()) $db->rollBack();
    app_mobile_json_response(null, 422, $e->getMessage());
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    app_mobile_json_response(null, 503, 'Qaytarma qəbul edilmədi.');
}
