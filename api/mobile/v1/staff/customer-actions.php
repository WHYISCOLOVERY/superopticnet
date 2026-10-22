<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__, 4) . '/customer-operator-policy.php';
require_once dirname(__DIR__, 4) . '/portal-security-lib.php';
require_once dirname(__DIR__, 4) . '/audit-log-lib.php';
$user = app_mobile_authenticate();
if (!app_customer_operator_allowed($user)) app_mobile_json_response(null, 403, 'İcazə yoxdur.');
$body = app_mobile_request_body();
$id = (int) ($body['customer_id'] ?? $_GET['customer_id'] ?? 0);
$row = app_db_account_by_id($id);
if (!$row || ($row['role'] ?? '') !== 'customer') app_mobile_json_response(null, 404, 'Müştəri tapılmadı.');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $payload = app_db_account_payload($row);
    $serviceType = strtolower((string) ($payload['billing']['service_type'] ?? $payload['internet_services'][0]['service_type'] ?? 'pppoe'));
    $tariffQuery = app_db()->prepare('SELECT id,name,monthly_fee FROM billing_tariffs WHERE status="active" AND service_type=? ORDER BY download_mbps,monthly_fee,name');
    $tariffQuery->execute([$serviceType]);
    app_mobile_json_response(['login' => $row['login'], 'phone' => $row['phone'], 'billing' => array_intersect_key($payload['billing'] ?? [], array_flip(['tariff','stable_due_day','default_days','borrowed_days_pending','debt_raw'])), 'tariffs' => $tariffQuery->fetchAll(PDO::FETCH_ASSOC) ?: [], 'history' => array_slice(array_reverse($payload['customer_operator_history'] ?? []), 0, 20)]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') app_mobile_json_response(null, 405, 'Metod dəstəklənmir.');
if (($body['action'] ?? '') === 'reset_password') {
    $result = app_portal_privileged_password_reset($row, $user, trim((string) ($body['reason'] ?? '')), 'customer_operator');
} else {
    $result = app_customer_operator_change(app_db(), $id, $user, $body);
}
app_mobile_json_response($result, !empty($result['ok']) ? 200 : 422, $result['error'] ?? $result['reason'] ?? null);
