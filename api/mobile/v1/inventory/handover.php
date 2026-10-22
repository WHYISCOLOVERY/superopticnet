<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__, 4) . '/inventory-operations-lib.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') app_mobile_json_response(null, 405, 'Yalnız POST sorğusu dəstəklənir.');
$user = app_mobile_authenticate();
if (!app_inventory_actor_can_write($user)) app_mobile_json_response(null, 403, 'Anbar təhvili üçün səlahiyyətiniz yoxdur.');
$body = app_mobile_request_body();
$result = app_inventory_handover_employee(app_db(), [
    'item_id' => (int)($body['item_id'] ?? 0),
    'employee_id' => (int)($body['employee_id'] ?? 0),
    'quantity' => (float)($body['quantity'] ?? 0),
    'source_location_type' => 'warehouse',
    'source_location_id' => 'MAIN',
    'serial_number' => trim((string)($body['serial_number'] ?? '')),
    'mac_address' => trim((string)($body['mac_address'] ?? '')),
    'note' => trim((string)($body['note'] ?? 'Mobil anbar təhvili')),
    'require_confirmation' => true,
], $user);
if (empty($result['ok'])) app_mobile_json_response(null, 422, 'Təhvil yaradılmadı: ' . ($result['error'] ?? 'xəta'));
app_mobile_json_response($result, 201);
