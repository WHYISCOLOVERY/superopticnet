<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__) . '/lib/mobile-alert-feed-lib.php';

$user = app_mobile_authenticate();
app_mobile_require_role($user, [
    'admin', 'manager', 'super_admin', 'director', 'team_lead',
    'operator', 'night_operator', 'support',
]);
$db = app_db();

$ticketCounts = $db->query(
    "SELECT
        COUNT(CASE WHEN status IN ('open','assigned','in_progress','Aciqdir','Icrada','Gozlenilir') THEN 1 END) AS active_count,
        COUNT(CASE WHEN status IN ('open','Aciqdir','Gozlenilir') AND assigned_technician_account_id IS NULL THEN 1 END) AS unassigned_count,
        COUNT(CASE WHEN status IN ('in_progress','Icrada') THEN 1 END) AS in_progress_count,
        COUNT(CASE WHEN status IN ('resolved','closed','Baglandi','Tesdiqlendi') AND DATE(COALESCE(resolved_at, updated_at)) = CURRENT_DATE() THEN 1 END) AS completed_today
     FROM portal_tickets
     WHERE ticket_no NOT LIKE 'LEGACY-%' OR opened_at >= (NOW() - INTERVAL 24 HOUR)"
)->fetch(PDO::FETCH_ASSOC) ?: [];

$agingCounts = $db->query(
    "SELECT
        COUNT(CASE WHEN opened_at < DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) AS older_than_24h,
        COUNT(CASE WHEN opened_at < DATE_SUB(NOW(), INTERVAL 3 DAY) THEN 1 END) AS older_than_3d,
        COUNT(CASE WHEN opened_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) AS older_than_7d
     FROM portal_tickets
     WHERE status IN ('open','assigned','in_progress','Aciqdir','Icrada','Gozlenilir')
       AND (ticket_no NOT LIKE 'LEGACY-%' OR opened_at >= (NOW() - INTERVAL 24 HOUR))"
)->fetch(PDO::FETCH_ASSOC) ?: [];

$attendanceToday = $db->query(
    "SELECT COUNT(*) AS checked_in,
            SUM(attendance_status='late') AS late_count,
            SUM(check_out_at IS NOT NULL) AS checked_out
       FROM hr_attendance_events WHERE work_date=CURRENT_DATE()"
)->fetch(PDO::FETCH_ASSOC) ?: [];

$onDuty = (int) $db->query(
    'SELECT COUNT(DISTINCT account_id) FROM mobile_duty_sessions WHERE ended_at IS NULL'
)->fetchColumn();
$pendingProofs = (int) $db->query(
    "SELECT COUNT(*) FROM mobile_task_proofs WHERE status = 'submitted'"
)->fetchColumn();
$actionableAlarms = app_mobile_actionable_alarm_feed($db, 40);
$criticalAlarms = count(array_filter(
    $actionableAlarms,
    static fn (array $alarm): bool => $alarm['status'] === 'open'
        && in_array($alarm['severity'], ['critical', 'emergency'], true)
));

$employeeRows = $db->query(
    "SELECT p.id, p.full_name, p.role, p.account_type, p.employee_role, p.job_title, p.phone, p.area, p.is_active,
            EXISTS(SELECT 1 FROM mobile_duty_sessions ds WHERE ds.account_id = p.id AND ds.ended_at IS NULL) AS is_on_duty,
            (SELECT COUNT(*) FROM portal_tickets t WHERE t.assigned_technician_account_id = p.id
              AND t.status IN ('assigned','in_progress','Aciqdir','Icrada','Gozlenilir')
              AND (t.ticket_no NOT LIKE 'LEGACY-%' OR t.opened_at >= (NOW() - INTERVAL 24 HOUR))) AS active_tasks
     FROM portal_accounts p
     WHERE p.role = 'employee' AND p.is_active = 1
     ORDER BY is_on_duty DESC, p.full_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$assignableEmployees = array_values(array_filter(
    $employeeRows,
    static fn (array $employee): bool => app_mobile_can_assign_employee($user, $employee)
));
$fieldTeam = array_values(array_filter(
    $employeeRows,
    static fn (array $employee): bool => !in_array(
        app_mobile_employee_role($employee),
        ['super_admin', 'operator', 'night_operator', 'support'],
        true
    )
));
$employeePayload = static fn (array $row): array => [
    'id' => (int) $row['id'],
    'full_name' => (string) $row['full_name'],
    'employee_role' => (string) $row['employee_role'],
    'job_title' => (string) ($row['job_title'] ?? ''),
    'phone' => (string) ($row['phone'] ?? ''),
    'area' => (string) ($row['area'] ?? ''),
    'is_on_duty' => (bool) $row['is_on_duty'],
    'active_tasks' => (int) $row['active_tasks'],
];

$tickets = $db->query(
    "SELECT id, ticket_no, customer_name, phone, address, area, subject, message, status,
            assigned_technician_account_id, assigned_technician_name, opened_at, updated_at
     FROM portal_tickets
     WHERE status IN ('open','assigned','in_progress','Aciqdir','Icrada','Gozlenilir')
       AND (ticket_no NOT LIKE 'LEGACY-%' OR opened_at >= (NOW() - INTERVAL 24 HOUR))
     ORDER BY opened_at DESC, id DESC, FIELD(status, 'in_progress','Icrada','assigned','open','Aciqdir','Gozlenilir')
     LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC);

$proofs = $db->query(
    "SELECT p.id, p.ticket_id, p.account_id, p.photo_path, p.watermark_data,
            p.optical_power_dbm, p.ocr_raw_text, p.otp_verified, p.resolution_notes,
            p.materials_json, p.status, p.created_at,
            t.ticket_no, t.subject, t.customer_name,
            a.full_name AS technician_name
     FROM mobile_task_proofs p
     JOIN portal_tickets t ON t.id = p.ticket_id
     JOIN portal_accounts a ON a.id = p.account_id
     WHERE p.status = 'submitted'
     ORDER BY p.created_at ASC
     LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC);

$notifications = $actionableAlarms;
/** @var array<string, bool> $seenAiTitles */
$seenAiTitles = [];
foreach ($db->query(
    "SELECT id, title, description, risk, status, telegram_sent, created_at AS occurred_at
     FROM ai_team_tasks
     WHERE status = 'pending_admin_review' AND created_at >= (NOW() - INTERVAL 24 HOUR)
     ORDER BY created_at DESC LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $titleKey = mb_strtolower(trim((string) $row['title']));
    if ($titleKey === '' || isset($seenAiTitles[$titleKey])) {
        continue;
    }
    $seenAiTitles[$titleKey] = true;
    $notifications[] = [
        'id' => 'ai-task-' . $row['id'],
        'kind' => 'ai_task',
        'group' => 'AI təklifi — rəhbər qərarı gözləyir',
        'severity' => (string) $row['risk'],
        'title' => (string) $row['title'],
        'message' => 'Son 24 saatda yaradılan AI təklifidir. Təsdiqdən əvvəl səbəbi və təsiri yoxlayın. ' . (string) $row['description'],
        'status' => (string) $row['status'],
        'telegram_sent' => (bool) $row['telegram_sent'],
        'occurred_at' => (string) $row['occurred_at'],
    ];
    if (count($seenAiTitles) >= 15) {
        break;
    }
}
try {
    foreach ($db->query(
        "SELECT a.id,a.alert_type,a.severity,a.title,a.message,a.event_at,a.resolved_at,p.full_name
           FROM mobile_manager_alerts a
           LEFT JOIN portal_accounts p ON p.id=a.account_id
          WHERE (a.alert_type IN ('home_dwell','operator_presence_reminder','operator_presence_missed','gps_disabled','gps_stale') AND a.resolved_at IS NULL)
             OR (a.alert_type='daily_digest' AND a.event_at >= (NOW() - INTERVAL 7 DAY))
          ORDER BY a.event_at DESC LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $notifications[] = [
            'id' => 'supervision-' . $row['id'],
            'kind' => (string) $row['alert_type'],
            'group' => (string) $row['alert_type'] === 'daily_digest'
                ? 'Gün sonu iş hesabatı'
                : 'İş vaxtı uzunmüddətli ev geofence qalması',
            'severity' => (string) $row['severity'],
            'title' => (string) $row['title'],
            'message' => (string) $row['message'],
            'status' => 'open',
            'occurred_at' => (string) $row['event_at'],
        ];
    }
    foreach ($db->query(
        "SELECT e.id,e.area,e.distance_meters,e.opened_at,a.full_name
           FROM mobile_geofence_events e
           JOIN portal_accounts a ON a.id=e.account_id
          WHERE e.event_type='left_area' AND e.resolved_at IS NULL
          ORDER BY e.opened_at DESC LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $notifications[] = [
            'id' => 'geofence-' . $row['id'],
            'kind' => 'geofence',
            'group' => 'İş ərazisindən çıxış',
            'severity' => 'warning',
            'title' => (string) $row['full_name'] . ' iş ərazisini tərk edib',
            'message' => (string) $row['area'] . ' sərhədindən təxminən ' . (int) $row['distance_meters'] . ' metr məsafədədir.',
            'status' => 'open',
            'occurred_at' => (string) $row['opened_at'],
        ];
    }
} catch (Throwable) {
}
foreach ($db->query(
    "SELECT id, recipient_name, channel, message_body, status, provider_status_description,
            created_at AS occurred_at
     FROM communication_messages
     WHERE (status = 'failed' OR delivery_state = 'final_failed')
       AND created_at >= (NOW() - INTERVAL 7 DAY)
     ORDER BY created_at DESC LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $notifications[] = [
        'id' => 'message-' . $row['id'],
        'kind' => 'delivery',
        'group' => 'Bildiriş çatdırılması',
        'severity' => 'warning',
        'title' => strtoupper((string) $row['channel']) . ' göndərişi uğursuz oldu',
        'message' => trim((string) ($row['provider_status_description'] ?: $row['message_body'])),
        'status' => (string) $row['status'],
        'recipient_name' => (string) ($row['recipient_name'] ?? ''),
        'occurred_at' => (string) $row['occurred_at'],
    ];
}
usort($notifications, static function (array $a, array $b): int {
    $kindPriority = ['network_incident' => 0, 'home_dwell' => 1, 'geofence' => 2, 'daily_digest' => 3, 'delivery' => 4, 'ai_task' => 5];
    $severityPriority = ['emergency' => 0, 'critical' => 1, 'high' => 1, 'warning' => 2, 'medium' => 2, 'info' => 3];
    $kindOrder = ($kindPriority[$a['kind']] ?? 9) <=> ($kindPriority[$b['kind']] ?? 9);
    if ($kindOrder !== 0) {
        return $kindOrder;
    }
    $severityOrder = ($severityPriority[$a['severity']] ?? 9) <=> ($severityPriority[$b['severity']] ?? 9);
    return $severityOrder !== 0 ? $severityOrder : strcmp($b['occurred_at'], $a['occurred_at']);
});
$notifications = array_slice($notifications, 0, 40);

app_mobile_json_response([
    'metrics' => [
        'active_tickets' => (int) ($ticketCounts['active_count'] ?? 0),
        'unassigned_tickets' => (int) ($ticketCounts['unassigned_count'] ?? 0),
        'in_progress_tickets' => (int) ($ticketCounts['in_progress_count'] ?? 0),
        'completed_today' => (int) ($ticketCounts['completed_today'] ?? 0),
        'on_duty_employees' => $onDuty,
        'pending_proofs' => $pendingProofs,
        'critical_alarms' => $criticalAlarms,
        'old_open_24h' => (int) ($agingCounts['older_than_24h'] ?? 0),
        'old_open_3d' => (int) ($agingCounts['older_than_3d'] ?? 0),
        'old_open_7d' => (int) ($agingCounts['older_than_7d'] ?? 0),
        'checked_in_today' => (int) ($attendanceToday['checked_in'] ?? 0),
        'late_today' => (int) ($attendanceToday['late_count'] ?? 0),
        'checked_out_today' => (int) ($attendanceToday['checked_out'] ?? 0),
    ],
    'technicians' => array_map($employeePayload, $assignableEmployees),
    'assignable_employees' => array_map($employeePayload, $assignableEmployees),
    'field_team' => array_map($employeePayload, $fieldTeam),
    'tickets' => array_map(static function (array $row): array {
        $row['id'] = (int) $row['id'];
        $row['assigned_technician_account_id'] = $row['assigned_technician_account_id'] !== null
            ? (int) $row['assigned_technician_account_id']
            : null;
        return $row;
    }, $tickets),
    'pending_proofs' => array_map(static function (array $row): array {
        foreach (['id', 'ticket_id', 'account_id'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['otp_verified'] = (bool) $row['otp_verified'];
        return $row;
    }, $proofs),
    'notifications' => $notifications,
], 200);
