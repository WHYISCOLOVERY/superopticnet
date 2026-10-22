<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__) . '/lib/mobile-inventory-lib.php';
$user = app_mobile_authenticate();
app_mobile_require_role($user, ['technician', 'engineer']);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') app_mobile_json_response(null, 405, 'Yalnız GET sorğusu dəstəklənir.');
try { app_mobile_json_response(app_mobile_inventory_options(app_db(), $user)); }
catch (Throwable $e) { app_mobile_json_response(null, 503, 'Adınızdakı inventar yüklənmədi.'); }
