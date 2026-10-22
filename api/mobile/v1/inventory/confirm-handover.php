<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__, 4) . '/inventory-operations-lib.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') app_mobile_json_response(null, 405, 'Yalnız POST sorğusu dəstəklənir.');
$user = app_mobile_authenticate();
$body = app_mobile_request_body();
$result = app_inventory_confirm_employee_handover(app_db(), (int)($body['document_id'] ?? 0), $user);
if (empty($result['ok'])) app_mobile_json_response(null, 422, 'Təhvil təsdiqlənmədi: ' . ($result['error'] ?? 'xəta'));
app_mobile_json_response($result);
