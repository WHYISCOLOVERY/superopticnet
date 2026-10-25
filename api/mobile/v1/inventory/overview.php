<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__, 4) . '/inventory-module-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    app_mobile_json_response(null, 405, 'Yalnız GET sorğusu dəstəklənir.');
}
$user = app_mobile_authenticate();
app_mobile_require_role($user, [
    'admin', 'super_admin', 'manager', 'director', 'team_lead',
    'warehouse', 'warehouse_manager', 'technician', 'engineer', 'employee', 'operator',
]);
$db = app_db();
$role = strtolower((string)($user['employee_role'] ?: $user['role']));
$isWarehouse = app_inventory_actor_can_write($user);
$accountId = (int)$user['id'];

$balances = $db->prepare(
    'SELECT b.item_id AS id,i.item_name,i.sku,i.unit,b.available_quantity
       FROM inventory_location_balances b
       JOIN inventory_stock_items i ON i.id=b.item_id
      WHERE b.location_type="employee" AND b.location_id=? AND b.available_quantity>0
      ORDER BY i.item_name'
);
$balances->execute([(string)$accountId]);

$returnsSql = 'SELECT d.*,COUNT(di.id) item_count,
                      GROUP_CONCAT(CONCAT(di.item_name," x",di.quantity," ",di.unit) ORDER BY di.line_no SEPARATOR ", ") item_summary
                 FROM inventory_documents d
                 LEFT JOIN inventory_document_items di ON di.document_id=d.id
                WHERE d.document_type="employee_return" AND d.status="pending"';
$returnParams = [];
if (!$isWarehouse) {
    $returnsSql .= ' AND d.source_location_type="employee" AND d.source_location_id=?';
    $returnParams[] = (string)$accountId;
}
$returnsSql .= ' GROUP BY d.id ORDER BY d.created_at DESC LIMIT 100';
$returns = $db->prepare($returnsSql);
$returns->execute($returnParams);

app_mobile_json_response([
    'role' => $role,
    'can_manage' => $isWarehouse,
    'can_approve' => app_inventory_actor_can_approve($user),
    'balances' => $balances->fetchAll(PDO::FETCH_ASSOC),
    'pending_handovers' => app_inventory_pending_handovers($db, $user),
    'pending_returns' => $returns->fetchAll(PDO::FETCH_ASSOC),
    'products' => $isWarehouse ? app_inventory_product_options($db) : [],
    'employees' => $isWarehouse ? app_inventory_employee_options($db) : [],
    'branches' => $isWarehouse && function_exists('app_db_branches') ? app_db_branches($db) : [],
]);
