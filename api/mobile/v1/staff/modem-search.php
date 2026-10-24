<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__, 4) . '/mgs-integration-lib.php';
require_once dirname(__DIR__, 4) . '/customer-operator-policy.php';
require_once dirname(__DIR__, 4) . '/portal-security-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_mobile_json_response(null, 405, 'Yalnız POST sorğuları dəstəklənir.');
}

$user = app_mobile_authenticate();
app_mobile_require_role($user, ['admin', 'manager', 'super_admin', 'director', 'team_lead', 'operator', 'night_operator', 'support', 'technician', 'engineer', 'noc', 'network_engineer']);
$query = trim((string) (app_mobile_request_body()['query'] ?? ''));
if ($query === '' || mb_strlen($query) > 128) {
    app_mobile_json_response(null, 422, 'Müştəri kodu, MAC və ya modem SN daxil edin.');
}

$db = app_db();
$actor = $user;
$employeeRole = strtolower(trim((string) ($user['employee_role'] ?? '')));
if (in_array($employeeRole, ['operator', 'night_operator', 'technician', 'engineer', 'team_lead'], true)) {
    $actor['employee_role'] = 'support';
}

$candidates = app_mgs_modem_search_candidates($query, 3, $db, $actor);
if ($employeeRole === 'technician') {
    if ($candidates === []) {
        app_mobile_json_response(null, 404, 'Bu sorğuya uyğun sizə bağlı müştəri tapılmadı.');
    }
    $customerId = (int) ($candidates[0]['account_id'] ?? 0);
    $actor['ticket_resolution_scope'] = true;
    $actor['ticket_resolution_device'] = (string) ($candidates[0]['olt'] ?? '');
}

$result = app_mgs_build_modem_search_reply($query, true, $db, $actor);
if (empty($result['ok'])) {
    $searchHint = (string) ($result['text'] ?? '');
    app_mobile_json_response(
        ['reason' => $result['reason'] ?? 'not_found', 'live_reason' => (string) ($result['live_reason'] ?? '')],
        404,
        $searchHint !== '' ? $searchHint : 'Modem və ya müştəri tapılmadı.'
    );
}

$candidate = is_array($result['candidate'] ?? null) ? $result['candidate'] : [];
$liveOnt = is_array($result['live']['ont'] ?? null) ? $result['live']['ont'] : [];
$equipmentLifecycle = app_mgs_customer_equipment_lifecycle(
    $db,
    (string)($candidate['subscriber_code'] ?? ''),
    20
);
$serviceDetails = [];
$sessionTraffic = [];
$customerId = (int) ($candidate['account_id'] ?? 0);
$ticketHistory = [];
$storedPppoePassword = '';
if ($customerId > 0) {
    $serviceStmt = $db->prepare(
        'SELECT p.plan,b.username,b.password,b.status,b.assigned_ip,b.static_ip,b.tariff_id
           FROM portal_accounts p
           LEFT JOIN billing_radius_services b ON b.account_id=p.id
          WHERE p.id=? ORDER BY b.id DESC LIMIT 1'
    );
    $serviceStmt->execute([$customerId]);
    $serviceDetails = $serviceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($serviceDetails['username'])) {
        $sessionStmt = $db->prepare(
            'SELECT framedipaddress,acctstarttime,acctupdatetime,acctsessiontime,acctinputoctets,acctoutputoctets
               FROM radacct WHERE username=? ORDER BY radacctid DESC LIMIT 1'
        );
        $sessionStmt->execute([(string) $serviceDetails['username']]);
        $sessionTraffic = $sessionStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    try {
        $storedPppoePassword = (string) (app_legacy_customer_secret_reveal($db, $customerId, 'pppoe_password') ?? '');
    } catch (Throwable) {
        $storedPppoePassword = '';
    }
    if ($storedPppoePassword === '') {
        $storedPppoePassword = (string) ($serviceDetails['password'] ?? '');
    }
    $historyStmt = $db->prepare(
        "SELECT id,ticket_no,subject,message,status,opened_at,resolved_at,resolution_reason,resolution_note,
                handled_by,assigned_technician_name,
                TIMESTAMPDIFF(MINUTE,opened_at,COALESCE(resolved_at,updated_at)) AS elapsed_minutes
           FROM portal_tickets
          WHERE customer_account_id=? OR (COALESCE(subscriber_code,'')<>'' AND subscriber_code=?)
          ORDER BY opened_at DESC,id DESC LIMIT 50"
    );
    $historyStmt->execute([$customerId, (string) ($candidate['subscriber_code'] ?? '')]);
    $ticketHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
app_mobile_json_response([
    'can_manage_customer' => app_customer_operator_allowed($user),
    'can_link_ont' => app_portal_is_admin($user) || in_array($employeeRole, [
        'operator', 'night_operator', 'engineer', 'noc', 'network_engineer', 'team_lead', 'manager', 'director',
    ], true),
    'customer' => [
        'id' => (int) ($candidate['account_id'] ?? 0),
        'name' => (string) ($candidate['full_name'] ?? '-'),
        'subscriber_code' => (string) ($candidate['subscriber_code'] ?? '-'),
        'phone' => (string) ($candidate['phone'] ?? '-'),
        'area' => (string) ($candidate['area'] ?? '-'),
        'address' => (string) ($candidate['address'] ?? '-'),
        'status' => (string) ($candidate['status'] ?? $candidate['ont_status'] ?? '-'),
    ],
    'device' => [
        'device_key' => (string) ($candidate['device_key'] ?? ''),
        'olt' => (string) ($candidate['olt'] ?? '-'),
        'pon' => (string) ($candidate['pon'] ?? '-'),
        'ont_id' => (string) ($candidate['ont_id'] ?? '-'),
        'serial' => (string) ($candidate['serial'] ?? '-'),
        'mac' => (string) ($candidate['mac'] ?? '-'),
        'status' => (string) ($liveOnt['status'] ?? $candidate['ont_status'] ?? '-'),
        'ont_rx' => $liveOnt['ont_rx'] ?? $candidate['ont_rx'] ?? null,
        'ont_tx' => $liveOnt['ont_tx'] ?? $candidate['ont_tx'] ?? null,
        'olt_rx' => $liveOnt['olt_rx'] ?? $candidate['olt_rx'] ?? null,
        'temperature' => $liveOnt['temperature'] ?? $candidate['temperature'] ?? null,
    ],
    'service' => [
        'radius_online' => (bool) ($candidate['radius_online'] ?? false),
        'open_ticket_count' => (int) ($candidate['open_ticket_count'] ?? 0),
        'last_ticket_no' => (string) ($candidate['last_ticket_no'] ?? ''),
        'service_active' => !in_array(
            strtolower((string)($candidate['status'] ?? '')),
            ['inactive', 'deaktiv', 'blocked', 'suspended', 'bağlı'],
            true
        ),
        'pppoe_username' => (string) ($serviceDetails['username'] ?? ''),
        'pppoe_password' => $storedPppoePassword,
        'plan' => (string) ($serviceDetails['plan'] ?? $candidate['plan'] ?? ''),
        'service_status' => (string) ($serviceDetails['status'] ?? ''),
        'session_ip' => (string) ($sessionTraffic['framedipaddress'] ?? $serviceDetails['assigned_ip'] ?? $serviceDetails['static_ip'] ?? ''),
        'session_started_at' => $sessionTraffic['acctstarttime'] ?? null,
        'session_updated_at' => $sessionTraffic['acctupdatetime'] ?? null,
        'session_seconds' => (int) ($sessionTraffic['acctsessiontime'] ?? 0),
        'upload_bytes' => (int) ($sessionTraffic['acctinputoctets'] ?? 0),
        'download_bytes' => (int) ($sessionTraffic['acctoutputoctets'] ?? 0),
    ],
    'equipment_lifecycle' => $equipmentLifecycle,
    'ticket_history' => $ticketHistory,
    'live' => !empty($result['live']),
    'live_reason' => (string) ($result['live_reason'] ?? ''),
], 200);
