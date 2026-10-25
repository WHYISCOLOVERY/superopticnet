<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__) . '/lib/mobile-supervision-lib.php';

$user = app_mobile_authenticate();
app_mobile_require_role($user, ['technician', 'engineer']);

$accountId = (int) $user['id'];
$db = app_db();

// 1. Ticket metrics for this technician
$stmtTickets = $db->prepare(
    "SELECT 
        COUNT(CASE WHEN status IN ('open','assigned','Aciqdir','Gozlenilir') THEN 1 END) as pending_count,
        COUNT(CASE WHEN status IN ('in_progress','Icrada') THEN 1 END) as in_progress_count,
        COUNT(CASE WHEN status IN ('resolved','closed','Baglandi','Tesdiqlendi') AND DATE(COALESCE(resolved_at, updated_at)) = CURRENT_DATE() THEN 1 END) as completed_today,
        COUNT(*) as total_assigned
     FROM portal_tickets 
     WHERE assigned_technician_account_id = ?
       AND (ticket_no NOT LIKE 'LEGACY-%' OR opened_at >= (NOW() - INTERVAL 24 HOUR))"
);
$stmtTickets->execute([$accountId]);
$ticketMetrics = $stmtTickets->fetch(PDO::FETCH_ASSOC) ?: [];

// 2. Bound customers metrics (from customer_employee_assignments)
$stmtCustomers = $db->prepare(
    "SELECT 
        COUNT(*) as total_customers,
        COUNT(CASE WHEN p.status = 'active' THEN 1 END) as active_customers,
        COUNT(CASE WHEN COALESCE(p.billing_debt_amount, 0) > 0 THEN 1 END) as debt_customers,
        SUM(COALESCE(p.billing_debt_amount, 0)) as total_debt_amount
     FROM customer_employee_assignments a
     JOIN portal_accounts p ON p.id = a.customer_account_id
     WHERE (a.installer_employee_id = ? OR a.area_responsible_employee_id = ? OR a.finder_employee_id = ?)
       AND a.status = 'active'"
);
$stmtCustomers->execute([$accountId, $accountId, $accountId]);
$customerMetrics = $stmtCustomers->fetch(PDO::FETCH_ASSOC) ?: [];

// 3. Today's collections / payments received if technician accepted cash
$stmtPayments = $db->prepare(
    "SELECT 
        COUNT(*) as payment_count,
        COALESCE(SUM(amount), 0) as total_collected_today
     FROM billing_payments
     WHERE payment_method = ?
       AND DATE(created_at) = CURRENT_DATE()"
);
$stmtPayments->execute([(string) $user['full_name']]);
$paymentMetrics = $stmtPayments->fetch(PDO::FETCH_ASSOC) ?: [];

// 4. Server-authoritative duty session and latest GPS status
$isOnDuty = app_mobile_is_on_duty($accountId, $db);
$stmtLastPing = $db->prepare(
    'SELECT battery_level, recorded_at FROM employee_live_locations WHERE account_id = ? ORDER BY id DESC LIMIT 1'
);
$stmtLastPing->execute([$accountId]);
$lastPing = $stmtLastPing->fetch(PDO::FETCH_ASSOC) ?: [];
$stmtAttendance = $db->prepare(
    'SELECT check_in_at,check_out_at,attendance_status,worked_minutes
       FROM hr_attendance_events WHERE employee_account_id=? AND work_date=CURRENT_DATE() LIMIT 1'
);
$stmtAttendance->execute([$accountId]);
$attendance = $stmtAttendance->fetch(PDO::FETCH_ASSOC) ?: [
    'check_in_at' => null,
    'check_out_at' => null,
    'attendance_status' => null,
    'worked_minutes' => 0,
];

// Live dispatch cards: tickets explicitly assigned by an operator and nearby
// unassigned work. The same payload is polled by the mobile dashboard so a new
// assignment appears without requiring the technician to reopen the screen.
$areaCenters = [];
foreach ($db->query('SELECT area,center_latitude,center_longitude FROM mobile_area_geofences WHERE is_active=1')
    ->fetchAll(PDO::FETCH_ASSOC) ?: [] as $center) {
    $areaCenters[mb_strtolower(trim((string) $center['area']), 'UTF-8')] = [
        'latitude' => (float) $center['center_latitude'],
        'longitude' => (float) $center['center_longitude'],
    ];
}
$latestLocation = null;
$locationStatement = $db->prepare(
    'SELECT latitude,longitude,recorded_at FROM employee_live_locations WHERE account_id=? ORDER BY id DESC LIMIT 1'
);
$locationStatement->execute([$accountId]);
$locationRow = $locationStatement->fetch(PDO::FETCH_ASSOC);
if ($locationRow && strtotime((string) $locationRow['recorded_at']) >= time() - 1800) {
    $latestLocation = [
        'latitude' => (float) $locationRow['latitude'],
        'longitude' => (float) $locationRow['longitude'],
    ];
}

$dispatchStatement = $db->prepare(
    "SELECT t.id,t.ticket_no,t.customer_name,t.address,t.area,t.subject,t.message,t.status,
            t.opened_at,t.updated_at,t.metadata_json,
            (SELECT e.actor_name FROM portal_ticket_events e
              WHERE e.ticket_id=t.id AND e.event_type='assigned' ORDER BY e.id DESC LIMIT 1) AS assigned_by,
            (SELECT e.created_at FROM portal_ticket_events e
              WHERE e.ticket_id=t.id AND e.event_type='assigned' ORDER BY e.id DESC LIMIT 1) AS assigned_at
       FROM portal_tickets t
      WHERE t.assigned_technician_account_id=?
        AND t.status IN ('open','assigned','in_progress','Aciqdir','Icrada','Gozlenilir')
        AND (t.ticket_no NOT LIKE 'LEGACY-%' OR t.opened_at >= (NOW() - INTERVAL 24 HOUR))
      ORDER BY (t.status IN ('in_progress','Icrada')) DESC,t.updated_at DESC LIMIT 5"
);
$dispatchStatement->execute([$accountId]);
$dispatchCards = [];
$appendDispatchCard = static function (array $ticket, string $kind, ?int $distance = null) use (&$dispatchCards): void {
    $priority = app_mobile_ticket_priority($ticket, 0);
    $dispatchCards[] = [
        'kind' => $kind,
        'ticket_id' => (int) $ticket['id'],
        'ticket_no' => (string) $ticket['ticket_no'],
        'subject' => (string) $ticket['subject'],
        'customer_name' => (string) $ticket['customer_name'],
        'address' => (string) $ticket['address'],
        'area' => (string) $ticket['area'],
        'status' => (string) $ticket['status'],
        'opened_at' => (string) $ticket['opened_at'],
        'assigned_by' => (string) ($ticket['assigned_by'] ?? ''),
        'assigned_at' => $ticket['assigned_at'] ?? null,
        'distance_meters' => $distance,
        'priority' => $priority,
    ];
};
foreach ($dispatchStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ticket) {
    $appendDispatchCard($ticket, 'assigned');
}

if ($latestLocation !== null) {
    $nearbyRows = $db->query(
        "SELECT id,ticket_no,customer_name,address,area,subject,message,status,opened_at,updated_at,metadata_json
           FROM portal_tickets
          WHERE assigned_technician_account_id IS NULL
            AND status IN ('open','Aciqdir','Gozlenilir')
            AND (ticket_no NOT LIKE 'LEGACY-%' OR opened_at >= (NOW() - INTERVAL 24 HOUR))
          ORDER BY opened_at ASC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $nearby = [];
    foreach ($nearbyRows as $ticket) {
        $metadata = json_decode((string) ($ticket['metadata_json'] ?? ''), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $latitude = filter_var($metadata['latitude'] ?? $metadata['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($metadata['longitude'] ?? $metadata['lng'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($latitude === false || $longitude === false) {
            $center = $areaCenters[mb_strtolower(trim((string) $ticket['area']), 'UTF-8')] ?? null;
            if ($center === null) continue;
            $latitude = $center['latitude'];
            $longitude = $center['longitude'];
        }
        $distance = app_mobile_distance_meters(
            $latestLocation['latitude'], $latestLocation['longitude'], (float) $latitude, (float) $longitude
        );
        if ($distance <= 10000) $nearby[] = ['ticket' => $ticket, 'distance' => $distance];
    }
    usort($nearby, static fn(array $a, array $b): int => $a['distance'] <=> $b['distance']);
    foreach (array_slice($nearby, 0, 3) as $item) {
        $appendDispatchCard($item['ticket'], 'nearby', (int) $item['distance']);
    }
}

app_mobile_json_response([
    'employee' => [
        'id' => $accountId,
        'full_name' => $user['full_name'],
        'job_title' => $user['job_title'] ?? 'Texnik',
        'is_on_duty' => $isOnDuty,
        'last_ping' => $lastPing['recorded_at'] ?? null,
        'attendance' => $attendance,
    ],
    'tasks' => [
        'pending' => (int) ($ticketMetrics['pending_count'] ?? 0),
        'in_progress' => (int) ($ticketMetrics['in_progress_count'] ?? 0),
        'completed_today' => (int) ($ticketMetrics['completed_today'] ?? 0),
        'total' => (int) ($ticketMetrics['total_assigned'] ?? 0),
    ],
    'customers' => [
        'total' => (int) ($customerMetrics['total_customers'] ?? 0),
        'active' => (int) ($customerMetrics['active_customers'] ?? 0),
        'with_debt' => (int) ($customerMetrics['debt_customers'] ?? 0),
        'total_debt_azn' => (float) ($customerMetrics['total_debt_amount'] ?? 0.0),
    ],
    'cash_collected_today' => [
        'count' => (int) ($paymentMetrics['payment_count'] ?? 0),
        'amount_azn' => (float) ($paymentMetrics['total_collected_today'] ?? 0.0),
    ],
    'dispatch_cards' => $dispatchCards,
    'dispatch_poll_seconds' => 20,
], 200);
