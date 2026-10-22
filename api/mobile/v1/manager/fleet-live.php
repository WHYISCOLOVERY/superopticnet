<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__) . '/lib/mobile-supervision-lib.php';

$user = app_mobile_authenticate();
// Require manager or admin role
app_mobile_require_role($user, [
    'admin', 'manager', 'super_admin', 'director', 'team_lead',
    'operator', 'night_operator', 'support', 'engineer', 'noc', 'network_engineer',
]);

$db = app_db();
$isGlobalViewer = in_array((string) ($user['role'] ?? ''), ['admin', 'super_admin', 'director'], true)
    || in_array((string) ($user['employee_role'] ?? ''), [
        'admin', 'super_admin', 'director', 'operator', 'night_operator', 'support',
    ], true);
$viewerAreas = app_db_account_areas((int) $user['id']);
if ($viewerAreas === [] && trim((string) ($user['area'] ?? '')) !== '') {
    $viewerAreas[] = trim((string) $user['area']);
}
$areaClause = '';
$params = [];
if (!$isGlobalViewer) {
    if ($viewerAreas === []) {
        app_mobile_json_response(['total_tracked' => 0, 'fleet' => []], 200);
    }
    $areaPlaceholders = implode(',', array_fill(0, count($viewerAreas), '?'));
    $areaClause = " AND (p.area IN ({$areaPlaceholders}) OR EXISTS (
        SELECT 1 FROM portal_account_areas paa
        WHERE paa.account_id = p.id AND paa.area IN ({$areaPlaceholders})
    ))";
    $params = array_merge($viewerAreas, $viewerAreas);
}

// Fetch latest location for all active employees from employee_live_locations
$sql = "SELECT 
            p.id as account_id,
            p.full_name,
            p.phone,
            p.employee_role,
            p.job_title,
            p.area,
            loc.latitude,
            loc.longitude,
            loc.accuracy,
            loc.speed,
            loc.heading,
            loc.battery_level,
            loc.is_charging,
            loc.is_mock,
            loc.is_on_duty,
            loc.activity_type,
            loc.recorded_at,
            EXISTS(
                SELECT 1 FROM mobile_duty_sessions ds
                WHERE ds.account_id = p.id AND ds.ended_at IS NULL
            ) AS server_on_duty,
            t.id as active_ticket_id,
            t.ticket_no as active_ticket_no,
            t.subject as active_ticket_subject,
            t.customer_name as active_customer_name
            ,(SELECT MIN(e.created_at) FROM portal_ticket_events e WHERE e.ticket_id=t.id AND e.event_type='started') AS active_task_started_at
            ,(SELECT COUNT(*) FROM portal_tickets workload
                WHERE workload.assigned_technician_account_id=p.id
                  AND workload.status IN ('open','assigned','in_progress','Aciqdir','Icrada','Gozlenilir')) AS active_task_count
        FROM portal_accounts p
        JOIN (
            SELECT l1.*
            FROM employee_live_locations l1
            INNER JOIN (
                SELECT account_id, MAX(id) as max_id
                FROM employee_live_locations
                GROUP BY account_id
            ) l2 ON l1.id = l2.max_id
        ) loc ON loc.account_id = p.id
        LEFT JOIN portal_tickets t ON t.id = (
            SELECT active.id FROM portal_tickets active
             WHERE active.assigned_technician_account_id=p.id
               AND active.status IN ('in_progress','Icrada')
             ORDER BY active.updated_at DESC,active.id DESC LIMIT 1
        )
        WHERE p.role = 'employee' AND p.is_active = 1" . $areaClause . "
        ORDER BY loc.recorded_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$fleet = [];
$now = time();
$areaCenters = [];
foreach ($db->query('SELECT area,center_latitude,center_longitude FROM mobile_area_geofences WHERE is_active=1')
    ->fetchAll(PDO::FETCH_ASSOC) ?: [] as $center) {
    $areaCenters[mb_strtolower(trim((string) $center['area']), 'UTF-8')] = [
        'latitude' => (float) $center['center_latitude'],
        'longitude' => (float) $center['center_longitude'],
    ];
}
$openTicketRows = $db->query(
    "SELECT id,ticket_no,subject,customer_name,address,area,status,metadata_json,opened_at
       FROM portal_tickets
      WHERE assigned_technician_account_id IS NULL
        AND status IN ('open','Aciqdir','Gozlenilir')
        AND (ticket_no NOT LIKE 'LEGACY-%' OR opened_at >= (NOW() - INTERVAL 24 HOUR))
      ORDER BY opened_at ASC LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$routeStatement = $db->prepare(
    'SELECT latitude,longitude,recorded_at
       FROM employee_live_locations
      WHERE account_id=? AND recorded_at>=CURRENT_DATE() AND is_mock=0
      ORDER BY id DESC LIMIT 240'
);

foreach ($rows as $r) {
    $recordedTime = strtotime((string) $r['recorded_at']);
    $secondsAgo = $now - $recordedTime;
    
    // Status resolution
    $status = 'off_duty';
    if ((int) $r['server_on_duty'] === 1) {
        if ($secondsAgo > 1800) { // No signal in 30 mins
            $status = 'stale_signal';
        } else if (!empty($r['active_ticket_id'])) {
            $status = 'busy_working';
        } else if ((float) ($r['speed'] ?? 0) > 4.0) { // moving > ~15 km/h
            $status = 'en_route';
        } else {
            $status = 'available';
        }
    }

    $nearbyTickets = [];
    foreach ($openTicketRows as $ticket) {
        $metadata = json_decode((string) ($ticket['metadata_json'] ?? ''), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $ticketLat = filter_var($metadata['latitude'] ?? $metadata['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $ticketLng = filter_var($metadata['longitude'] ?? $metadata['lng'] ?? null, FILTER_VALIDATE_FLOAT);
        $locationSource = 'exact';
        if ($ticketLat === false || $ticketLng === false) {
            $center = $areaCenters[mb_strtolower(trim((string) $ticket['area']), 'UTF-8')] ?? null;
            if ($center === null) continue;
            $ticketLat = $center['latitude'];
            $ticketLng = $center['longitude'];
            $locationSource = 'area_center';
        }
        $distance = app_mobile_distance_meters(
            (float) $r['latitude'], (float) $r['longitude'], (float) $ticketLat, (float) $ticketLng
        );
        if ($distance > 30000) continue;
        $nearbyTickets[] = [
            'ticket_id' => (int) $ticket['id'],
            'ticket_no' => (string) $ticket['ticket_no'],
            'subject' => (string) $ticket['subject'],
            'customer_name' => (string) $ticket['customer_name'],
            'address' => (string) $ticket['address'],
            'area' => (string) $ticket['area'],
            'opened_at' => (string) $ticket['opened_at'],
            'distance_meters' => $distance,
            'location_source' => $locationSource,
            'latitude' => (float) $ticketLat,
            'longitude' => (float) $ticketLng,
        ];
    }
    usort($nearbyTickets, static fn (array $a, array $b): int => $a['distance_meters'] <=> $b['distance_meters']);
    $routeStatement->execute([(int) $r['account_id']]);
    $routeRows = array_reverse($routeStatement->fetchAll(PDO::FETCH_ASSOC) ?: []);
    $route = array_map(static fn(array $point): array => [
        'latitude' => (float) $point['latitude'],
        'longitude' => (float) $point['longitude'],
        'recorded_at' => (string) $point['recorded_at'],
    ], $routeRows);

    $fleet[] = [
        'employee_id' => (int) $r['account_id'],
        'name' => $r['full_name'],
        'phone' => $r['phone'],
        'role' => $r['employee_role'] ?? 'technician',
        'title' => $r['job_title'] ?? 'Texnik',
        'area' => $r['area'],
        'status' => $status, // available, busy_working, en_route, stale_signal, off_duty
        'active_task_count' => (int) ($r['active_task_count'] ?? 0),
        'location' => [
            'latitude' => (float) $r['latitude'],
            'longitude' => (float) $r['longitude'],
            'accuracy_m' => $r['accuracy'] !== null ? (float) $r['accuracy'] : null,
            'speed_kmh' => $r['speed'] !== null ? round(((float) $r['speed']) * 3.6, 1) : null,
            'heading_deg' => $r['heading'] !== null ? (float) $r['heading'] : null,
            'is_mock_detected' => (bool) $r['is_mock'],
            'recorded_at' => $r['recorded_at'],
            'seconds_ago' => $secondsAgo,
        ],
        'device' => [
            'battery_level' => $r['battery_level'] !== null ? (int) $r['battery_level'] : null,
            'is_charging' => (bool) $r['is_charging'],
            'activity' => $r['activity_type'] ?? 'unknown',
        ],
        'active_task' => !empty($r['active_ticket_id']) ? [
            'ticket_id' => (int) $r['active_ticket_id'],
            'ticket_no' => $r['active_ticket_no'],
            'subject' => $r['active_ticket_subject'],
            'customer_name' => $r['active_customer_name'],
            'started_at' => $r['active_task_started_at'],
        ] : null,
        'nearby_tickets' => array_slice($nearbyTickets, 0, 5),
        'route_today' => $route,
    ];
}

app_mobile_json_response([
    'total_tracked' => count($fleet),
    'fleet' => $fleet,
], 200);
