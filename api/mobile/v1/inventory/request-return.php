<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__, 4) . '/inventory-operations-lib.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') app_mobile_json_response(null, 405, 'Yalnız POST sorğusu dəstəklənir.');
$user = app_mobile_authenticate();
$body = app_mobile_request_body();
$itemId = (int)($body['item_id'] ?? 0);
$quantity = (float)($body['quantity'] ?? 0);
$db = app_db();
if ($itemId <= 0 || $quantity <= 0) app_mobile_json_response(null, 422, 'Mal və düzgün miqdar seçin.');
$destinationId = trim((string)($body['destination_id'] ?? 'MAIN')) ?: 'MAIN';
if (!app_inventory_location_exists($db, 'warehouse', $destinationId)) app_mobile_json_response(null, 422, 'Qaytarma anbarı etibarsızdır.');
$db->beginTransaction();
try {
    $balance = app_inventory_lock_location_balance($db, $itemId, 'employee', (string)$user['id']);
    if ((float)($balance['available_quantity'] ?? 0) < $quantity) throw new InvalidArgumentException('Adınızdakı qalıq kifayət etmir.');
    $document = app_inventory_write_document($db, 'QBL', 'employee_return', 'pending', [
        'source_location_type' => 'employee', 'source_location_id' => (string)$user['id'],
        'destination_type' => 'warehouse', 'destination_id' => $destinationId,
        'reason' => 'employee_return_request', 'note' => trim((string)($body['note'] ?? 'Mobil qaytarma sorğusu')),
    ], [[
        'item_id' => $itemId, 'quantity' => $quantity,
        'serial_number' => trim((string)($body['serial_number'] ?? '')),
        'mac_address' => trim((string)($body['mac_address'] ?? '')),
    ]], $user);
    app_inventory_record_audit($db, 'inventory_return_requested', $document['document_no'], 'pending', $user, ['document_id'=>$document['id']]);
    $db->commit();
    app_mobile_json_response($document + ['ok'=>true], 201);
} catch (InvalidArgumentException $e) {
    if ($db->inTransaction()) $db->rollBack();
    app_mobile_json_response(null, 422, $e->getMessage());
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    app_mobile_json_response(null, 503, 'Qaytarma sorğusu yaradılmadı.');
}
