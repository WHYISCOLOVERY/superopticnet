<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__) . '/lib/mobile-supervision-lib.php';

$user = app_mobile_authenticate();
app_mobile_require_role($user, ['technician', 'engineer']);

$accountId = (int) $user['id'];
$status = trim((string) ($_GET['status'] ?? ''));
$problemType = trim((string) ($_GET['problem_type'] ?? ''));
$datePeriod = trim((string) ($_GET['date_period'] ?? ''));
$limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));

$db = app_db();

$where = [
    "(t.assigned_technician_account_id = :aid OR (t.assigned_technician_account_id IS NULL AND EXISTS (SELECT 1 FROM portal_account_areas taa WHERE taa.account_id=:aid_area AND taa.area=t.area)))",
    "(t.ticket_no NOT LIKE 'LEGACY-%' OR t.opened_at >= (NOW() - INTERVAL 24 HOUR))",
];
$params = [
    ':aid' => $accountId,
    ':aid_area' => $accountId,
];

if ($status !== '') {
    $where[] = "t.status = :status";
    $params[':status'] = $status;
} else {
    // Default to active tickets
    $where[] = "t.status IN ('open','assigned','in_progress','Aciqdir','Icrada','Gozlenilir')";
}

$problemNeedles = [
    'installation' => ['qosul', 'qoşul', 'yeni musteri', 'yeni müştəri', 'installation'],
    'signal' => ['siqnal', 'signal', 'rx', 'tx', 'dbm', 'optik'],
    'modem' => ['modem', 'ont', 'router', 'sn', 'mac'],
    'cable' => ['kabel', 'connector', 'konnektor', 'fast'],
    'pon' => ['pon', 'olt', 'splitter'],
];
if ($problemType !== '' && isset($problemNeedles[$problemType])) {
    $parts = [];
    foreach ($problemNeedles[$problemType] as $index => $needle) {
        $key = ':problem_' . $index;
        $parts[] = "(LOWER(t.subject) LIKE {$key} OR LOWER(t.message) LIKE {$key})";
        $params[$key] = '%' . mb_strtolower($needle) . '%';
    }
    $where[] = '(' . implode(' OR ', $parts) . ')';
}

if ($datePeriod === 'today') {
    $where[] = 'DATE(t.opened_at)=CURRENT_DATE()';
} elseif ($datePeriod === 'week') {
    $where[] = 't.opened_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
} elseif ($datePeriod === 'month') {
    $where[] = 't.opened_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
}

$whereSql = implode(' AND ', $where);
$fetchLimit = min(300, max($limit, $limit * 3));

$sql = "SELECT 
            t.id,
            t.ticket_no,
            t.customer_account_id,
            t.subscriber_code,
            t.contract_no,
            t.customer_name,
            t.phone,
            t.address,
            t.area,
            t.subject,
            t.message,
            t.status,
            t.opened_at,
            t.updated_at,
            t.assigned_technician_name,
            t.metadata_json,
            p.plan as customer_plan,
            p.billing_debt_amount as customer_debt,
            (SELECT ev.event_type FROM portal_ticket_events ev
              WHERE ev.ticket_id=t.id AND ev.event_type='started'
              ORDER BY ev.id DESC LIMIT 1) AS progress_event,
            (SELECT COUNT(*)
               FROM portal_tickets repeated
              WHERE repeated.opened_at >= DATE_SUB(NOW(),INTERVAL 90 DAY)
                AND ((t.customer_account_id IS NOT NULL AND repeated.customer_account_id=t.customer_account_id)
                  OR (COALESCE(t.subscriber_code,'')<>'' AND repeated.subscriber_code=t.subscriber_code))) AS repeat_count
        FROM portal_tickets t
        LEFT JOIN portal_accounts p ON p.id = t.customer_account_id
        WHERE $whereSql
        ORDER BY t.opened_at ASC
        LIMIT $fetchLimit";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($tickets as &$ticket) {
    $ticket['_priority'] = app_mobile_ticket_priority($ticket, (int) ($ticket['repeat_count'] ?? 0));
}
unset($ticket);
usort($tickets, static function (array $left, array $right): int {
    $scoreOrder = ((int) ($right['_priority']['score'] ?? 0)) <=> ((int) ($left['_priority']['score'] ?? 0));
    if ($scoreOrder !== 0) return $scoreOrder;
    return strcmp((string) ($left['opened_at'] ?? ''), (string) ($right['opened_at'] ?? ''));
});
$tickets = array_slice($tickets, 0, $limit);

// Enrich with proof submission status if exists
$ticketIds = array_column($tickets, 'id');
$proofsMap = [];
if (!empty($ticketIds)) {
    $inClause = implode(',', array_map('intval', $ticketIds));
    $stmtProofs = $db->query("SELECT ticket_id, status, optical_power_dbm, otp_verified FROM mobile_task_proofs WHERE ticket_id IN ($inClause)");
    while ($r = $stmtProofs->fetch(PDO::FETCH_ASSOC)) {
        $proofsMap[(int) $r['ticket_id']] = $r;
    }
}

$formatted = [];
foreach ($tickets as $t) {
    $tid = (int) $t['id'];
    $proof = $proofsMap[$tid] ?? null;

    $formatted[] = [
        'id' => $tid,
        'ticket_no' => $t['ticket_no'],
        'customer' => [
            'id' => (int) $t['customer_account_id'],
            'name' => $t['customer_name'],
            'phone' => $t['phone'],
            'subscriber_code' => $t['subscriber_code'],
            'contract_no' => $t['contract_no'],
            'address' => $t['address'],
            'area' => $t['area'],
            'plan' => $t['customer_plan'] ?? '',
            'debt_azn' => (float) ($t['customer_debt'] ?? 0.0),
        ],
        'subject' => $t['subject'],
        'message' => $t['message'],
        'status' => $t['status'],
        'problem_type' => $problemType,
        'metadata' => json_decode((string) ($t['metadata_json'] ?? ''), true) ?: [],
        'opened_at' => $t['opened_at'],
        'progress_event' => (string) ($t['progress_event'] ?? ''),
        'priority' => [
            'score' => (int) ($t['_priority']['score'] ?? 0),
            'label' => (string) ($t['_priority']['label'] ?? 'normal'),
            'reasons' => array_values((array) ($t['_priority']['reasons'] ?? [])),
            'queue_position' => count($formatted) + 1,
        ],
        'proof' => $proof ? [
            'status' => $proof['status'],
            'optical_power_dbm' => $proof['optical_power_dbm'] !== null ? (float) $proof['optical_power_dbm'] : null,
            'otp_verified' => (bool) $proof['otp_verified'],
        ] : null,
    ];
}

app_mobile_json_response([
    'count' => count($formatted),
    'tasks' => $formatted,
], 200);
