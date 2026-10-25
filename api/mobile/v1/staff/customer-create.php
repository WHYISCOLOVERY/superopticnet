<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__, 4) . '/billing-customer-identity-lib.php';
require_once dirname(__DIR__, 4) . '/router-registry-lib.php';

$user = app_mobile_authenticate();
if (!router_registry_actor_can_create_customer($user)) {
    app_mobile_json_response(null, 403, 'Yeni müştəri yaratmaq üçün operator və ya admin səlahiyyəti lazımdır.');
}
$db = app_db();
if (!$db instanceof PDO) app_mobile_json_response(null, 503, 'Verilənlər bazası hazır deyil.');
app_billing_ensure_identity_schema($db);
router_registry_ensure_schema($db);

$routers = router_registry_customer_creation_routers($db, $user);
$tariffs = $db->query('SELECT id,name,service_type,monthly_fee,download_mbps,upload_mbps FROM billing_tariffs WHERE status="active" ORDER BY service_type,download_mbps,monthly_fee')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$pools = $db->query('SELECT id,name,service_type,pool_type FROM billing_ip_pools WHERE status="active" AND pool_type<>"static" ORDER BY service_type,name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    app_mobile_json_response(['areas' => app_service_areas(), 'routers' => $routers, 'tariffs' => $tariffs, 'pools' => $pools]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') app_mobile_json_response(null, 405, 'Metod dəstəklənmir.');
$body = app_mobile_request_body();
$area = clean_text((string) ($body['area'] ?? ''));
$fullName = clean_text((string) ($body['full_name'] ?? ''));
$phone = clean_text((string) ($body['phone'] ?? ''));
$fin = clean_text((string) ($body['fin_code'] ?? ''));
$document = clean_text((string) ($body['document_no'] ?? ''));
$address = clean_text((string) ($body['address'] ?? ''));
$serviceType = strtolower(clean_text((string) ($body['service_type'] ?? 'pppoe')));
$tariffId = (int) ($body['tariff_id'] ?? 0);
$poolId = (int) ($body['pool_id'] ?? 0);
$routerId = (int) ($body['router_id'] ?? 0);
$requestId = clean_text((string) ($body['request_id'] ?? ''));
if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/', $requestId)) app_mobile_json_response(null, 422, 'Sorğu identifikatoru etibarsızdır.');
if ($area === '' || $fullName === '' || $phone === '' || $fin === '' || $document === '' || $address === '') app_mobile_json_response(null, 422, 'Ad-soyad, telefon, FIN, sənəd, ünvan və ərazi tələb olunur.');
if (!in_array($serviceType, ['pppoe','ipoe'], true)) app_mobile_json_response(null, 422, 'Xidmət növü etibarsızdır.');
$tariffStatement = $db->prepare('SELECT * FROM billing_tariffs WHERE id=? AND status="active" AND service_type=? LIMIT 1');
$tariffStatement->execute([$tariffId, $serviceType]);
$tariff = $tariffStatement->fetch(PDO::FETCH_ASSOC);
$poolStatement = $db->prepare('SELECT * FROM billing_ip_pools WHERE id=? AND status="active" AND service_type=? AND pool_type<>"static" LIMIT 1');
$poolStatement->execute([$poolId, $serviceType]);
$pool = $poolStatement->fetch(PDO::FETCH_ASSOC);
if (!$tariff || !$pool) app_mobile_json_response(null, 422, 'Tarif və IP pool xidmət növünə uyğun seçilməlidir.');
$subscriberCode = app_db_resolve_customer_code_for_area($db, $area, '', true);
if ($subscriberCode === '') app_mobile_json_response(null, 422, 'Ərazi üçün avtomatik müştəri kodu yaradılmadı.');
$assignedIp = app_billing_next_available_ip($poolId) ?? '';
if ($assignedIp === '') app_mobile_json_response(null, 409, 'Seçilmiş IP pool-da boş ünvan yoxdur.');
$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
$password = '';
for ($i=0; $i<10; $i++) $password .= $alphabet[random_int(0, strlen($alphabet)-1)];
$days = app_billing_tariff_default_days($tariff);
$expiresAt = app_billing_resolve_next_expires_at($days, 0, null, time());
$username = clean_text((string) ($body['radius_username'] ?? '')) ?: $subscriberCode;
$payload = [
    'profile' => ['full_name'=>$fullName,'subscriber_code'=>$subscriberCode,'contract_no'=>$subscriberCode,'phone'=>$phone,'fin_code'=>$fin,'document_no'=>$document,'area'=>$area,'address'=>$address,'plan'=>(string)$tariff['name'],'status'=>'Aktiv'],
    'billing' => ['service_type'=>$serviceType,'pppoe_username'=>$username,'pppoe_password'=>$serviceType==='pppoe'?$password:'','tariff'=>(string)$tariff['name'],'pool'=>(string)$pool['name'],'assigned_ip'=>$assignedIp,'ip_address'=>$assignedIp,'status'=>'Aktiv','radius_status'=>'Aktiv','expires_at'=>$expiresAt,'default_days'=>$days,'billing_days'=>$days,'days_left'=>app_billing_days_left($expiresAt),'balance'=>'0 AZN'],
    'internet_services' => [['service_type'=>$serviceType,'username'=>$username,'password'=>$serviceType==='pppoe'?$password:'','tariff'=>(string)$tariff['name'],'pool'=>(string)$pool['name'],'assigned_ip'=>$assignedIp,'ip_address'=>$assignedIp,'vlan'=>clean_text((string)($body['vlan']??'')),'mac'=>strtoupper(clean_text((string)($body['mac']??''))),'status'=>'active','expires_at'=>$expiresAt,'default_days'=>$days]],
    'ont_profiles' => [['olt'=>clean_text((string)($body['olt']??'')),'pon'=>clean_text((string)($body['pon']??'')),'ont_id'=>clean_text((string)($body['ont_id']??'')),'serial'=>clean_text((string)($body['ont_serial']??'')),'mac'=>strtoupper(clean_text((string)($body['mac']??''))),'status'=>'unknown']],
    'customer_creation' => ['request_id'=>$requestId,'actor_id'=>(int)$user['id'],'created_at'=>date(DATE_ATOM)],
];
$payload = app_billing_refresh_payload_totals([], $payload, $tariff);
$result = router_registry_create_customer_with_binding($db, ['login'=>$subscriberCode,'password'=>$password,'role'=>'customer','account_type'=>'customer','full_name'=>$fullName,'subscriber_code'=>$subscriberCode,'contract_no'=>$subscriberCode,'phone'=>$phone,'area'=>$area,'address'=>$address,'plan'=>(string)$tariff['name'],'status'=>'Aktiv','is_active'=>1,'payload'=>$payload], ['fin_code'=>$fin,'document_no'=>$document,'phone'=>$phone,'address'=>$address], $user, $serviceType==='pppoe'?$password:null, $routerId);
if (empty($result['ok'])) app_mobile_json_response(['reason'=>$result['error']??'create_failed'], 409, 'Müştəri yaradılmadı. Kod, FIN, router və RADIUS məlumatını yoxlayın.');
app_mobile_json_response(['customer_id'=>(int)$result['account']['id'],'subscriber_code'=>$subscriberCode,'portal_login'=>$subscriberCode,'temporary_password'=>$password,'assigned_ip'=>$assignedIp], 201);
