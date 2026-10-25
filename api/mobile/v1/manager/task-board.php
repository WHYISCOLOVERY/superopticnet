<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__) . '/lib/mobile-supervision-lib.php';

$user = app_mobile_authenticate();
app_mobile_require_role($user, ['admin', 'manager', 'super_admin', 'director', 'team_lead', 'operator', 'night_operator', 'support']);

$period = strtolower(trim((string) ($_GET['period'] ?? 'month')));
$allowedPeriods = ['day', 'week', 'month', 'year', 'all'];
if (!in_array($period, $allowedPeriods, true)) {
    $period = 'month';
}

$db = app_db();
$now = new DateTimeImmutable('now');
$rangeStart = match ($period) {
    'day' => $now->format('Y-m-d 00:00:00'),
    'week' => $now->modify('monday this week')->format('Y-m-d 00:00:00'),
    'year' => $now->format('Y-01-01 00:00:00'),
    'all' => '1970-01-01 00:00:00',
    default => $now->format('Y-m-01 00:00:00'),
};
$rangeEnd = $now->format('Y-m-d 23:59:59');

$employeeRows = $db->query(
    "SELECT id, full_name, role, account_type, employee_role, job_title, area, phone, is_active
       FROM portal_accounts
      WHERE role = 'employee'
        AND is_active = 1
      ORDER BY full_name ASC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$employeeRows = array_values(array_filter(
    $employeeRows,
    static fn (array $employee): bool => app_mobile_can_assign_employee($user, $employee)
));

$employees = [];
foreach ($employeeRows as $row) {
    $id = (int) $row['id'];
    $employees[$id] = [
        'id' => $id,
        'name' => (string) $row['full_name'],
        'role' => (string) ($row['employee_role'] ?? ''),
        'job_title' => (string) ($row['job_title'] ?? ''),
        'area' => (string) ($row['area'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
        'stats' => [
            'total' => 0,
            'active' => 0,
            'completed' => 0,
            'overdue' => 0,
            'unassigned' => 0,
            'stale_24h' => 0,
            'avg_completion_hours' => 0.0,
            'travel_minutes' => 0,
            'work_minutes' => 0,
            'travel_samples' => 0,
            'work_samples' => 0,
        ],
        'tasks' => [],
    ];
}

$employees[0] = [
    'id' => 0,
    'name' => 'Təyinatsız tapşırıqlar',
    'role' => 'unassigned',
    'job_title' => 'İcraçı seçilməyib',
    'area' => '',
    'phone' => '',
    'stats' => [
        'total' => 0,
        'active' => 0,
        'completed' => 0,
        'overdue' => 0,
        'unassigned' => 0,
        'stale_24h' => 0,
        'avg_completion_hours' => 0.0,
        'travel_minutes' => 0,
        'work_minutes' => 0,
        'travel_samples' => 0,
        'work_samples' => 0,
    ],
    'tasks' => [],
];

$taskStatement = $db->prepare(
    "SELECT
        t.id,
        t.ticket_no,
        t.subject,
        t.message,
        t.customer_name,
        t.area,
        t.status,
        t.opened_at,
        t.updated_at,
        t.resolved_at,
        t.assigned_technician_account_id,
        t.assigned_technician_name,
        t.created_by_name,
        t.metadata_json,
        TIMESTAMPDIFF(HOUR, t.opened_at, COALESCE(t.resolved_at, NOW())) AS age_hours,
        TIMESTAMPDIFF(HOUR, t.opened_at, t.resolved_at) AS completion_hours,
        e.event_type AS last_event_type,
        e.event_note AS last_event_note,
        e.created_at AS last_event_at
        ,(SELECT MIN(ev.created_at) FROM portal_ticket_events ev WHERE ev.ticket_id=t.id AND ev.event_type='started') AS work_started_at
        ,(SELECT MIN(ev.created_at) FROM portal_ticket_events ev WHERE ev.ticket_id=t.id AND ev.event_type='resolved') AS work_resolved_at
        ,(SELECT MAX(COALESCE(previous.resolved_at,previous.updated_at))
            FROM portal_tickets previous
           WHERE previous.assigned_technician_account_id=t.assigned_technician_account_id
             AND previous.id<>t.id
             AND previous.status IN ('resolved','closed','Baglandi','Tesdiqlendi')
             AND COALESCE(previous.resolved_at,previous.updated_at)<
                 (SELECT MIN(se.created_at) FROM portal_ticket_events se WHERE se.ticket_id=t.id AND se.event_type='started')
             AND DATE(COALESCE(previous.resolved_at,previous.updated_at))=DATE(
                 (SELECT MIN(se2.created_at) FROM portal_ticket_events se2 WHERE se2.ticket_id=t.id AND se2.event_type='started')
             )
         ) AS previous_resolved_at
        ,(SELECT ae.check_in_at FROM hr_attendance_events ae
           WHERE ae.employee_account_id=t.assigned_technician_account_id
             AND ae.work_date=DATE((SELECT MIN(se3.created_at) FROM portal_ticket_events se3 WHERE se3.ticket_id=t.id AND se3.event_type='started'))
           LIMIT 1) AS day_check_in_at
       FROM portal_tickets t
       LEFT JOIN portal_ticket_events e ON e.id = (
            SELECT ev.id
              FROM portal_ticket_events ev
             WHERE ev.ticket_id = t.id
             ORDER BY ev.created_at DESC, ev.id DESC
             LIMIT 1
       )
      WHERE (t.ticket_no NOT LIKE 'LEGACY-%' OR t.opened_at >= (NOW() - INTERVAL 24 HOUR))
        AND (
          t.status IN ('open','assigned','in_progress','Aciqdir','Icrada','Gozlenilir')
          OR COALESCE(t.resolved_at, t.updated_at, t.opened_at) BETWEEN :range_start AND :range_end
        )
      ORDER BY
        CASE
          WHEN t.status IN ('open','assigned','in_progress','Aciqdir','Icrada','Gozlenilir') THEN 0
          ELSE 1
        END,
        COALESCE(t.updated_at, t.opened_at) DESC
      LIMIT 500"
);
$taskStatement->execute([
    'range_start' => $rangeStart,
    'range_end' => $rangeEnd,
]);

$summary = [
    'employees' => count($employeeRows),
    'total' => 0,
    'active' => 0,
    'completed' => 0,
    'overdue' => 0,
    'unassigned' => 0,
    'stale_24h' => 0,
    'travel_minutes' => 0,
    'work_minutes' => 0,
    'travel_samples' => 0,
    'work_samples' => 0,
];
$completionHours = [];
$employeeCompletionHours = [];

$activeStatuses = ['open', 'assigned', 'in_progress', 'Aciqdir', 'Icrada', 'Gozlenilir'];
$completedStatuses = ['resolved', 'closed', 'Baglandi', 'Tesdiqlendi'];

foreach ($taskStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $employeeId = (int) ($row['assigned_technician_account_id'] ?? 0);
    if (!isset($employees[$employeeId])) {
        $employeeId = 0;
    }
    $status = (string) ($row['status'] ?? '');
    $isActive = in_array($status, $activeStatuses, true);
    $isCompleted = in_array($status, $completedStatuses, true);
    $metadata = json_decode((string) ($row['metadata_json'] ?? ''), true);
    $metadata = is_array($metadata) ? $metadata : [];
    $dueDate = trim((string) ($metadata['due_date'] ?? ''));
    $priority = trim((string) ($metadata['priority'] ?? 'normal'));
    if (!in_array($priority, ['low', 'normal', 'high', 'critical'], true)) {
        $priority = 'normal';
    }
    $isOverdue = $isActive && $dueDate !== '' && $dueDate < $now->format('Y-m-d');
    $isStale = $isActive && (int) ($row['age_hours'] ?? 0) >= 24;

    $employees[$employeeId]['stats']['total']++;
    $summary['total']++;
    if ($isActive) {
        $employees[$employeeId]['stats']['active']++;
        $summary['active']++;
    }
    if ($isCompleted) {
        $employees[$employeeId]['stats']['completed']++;
        $summary['completed']++;
    }
    if ($isOverdue) {
        $employees[$employeeId]['stats']['overdue']++;
        $summary['overdue']++;
    }
    if ($employeeId === 0) {
        $employees[$employeeId]['stats']['unassigned']++;
        $summary['unassigned']++;
    }
    if ($isStale) {
        $employees[$employeeId]['stats']['stale_24h']++;
        $summary['stale_24h']++;
    }
    if ($isCompleted && $row['completion_hours'] !== null) {
        $hours = max(0, (float) $row['completion_hours']);
        $completionHours[] = $hours;
        $employeeCompletionHours[$employeeId][] = $hours;
    }
    $travelMinutes = null;
    $travelSource = null;
    $travelAnchorAt = $row['previous_resolved_at'] ?: $row['day_check_in_at'];
    if (!empty($travelAnchorAt) && !empty($row['work_started_at'])) {
        $travelMinutes = intdiv(app_mobile_seconds_excluding_lunch(
            new DateTimeImmutable((string) $travelAnchorAt, new DateTimeZone('Asia/Baku')),
            new DateTimeImmutable((string) $row['work_started_at'], new DateTimeZone('Asia/Baku')),
            '13:00:00',
            '14:00:00'
        ), 60);
        $travelSource = $row['previous_resolved_at'] ? 'previous_ticket' : 'check_in';
        $employees[$employeeId]['stats']['travel_minutes'] += $travelMinutes;
        $employees[$employeeId]['stats']['travel_samples']++;
        $summary['travel_minutes'] += $travelMinutes;
        $summary['travel_samples']++;
    }
    $workMinutes = null;
    $workEndAt = $row['work_resolved_at'] ?: $row['resolved_at'];
    if (!empty($row['work_started_at']) && !empty($workEndAt)) {
        $workMinutes = intdiv(app_mobile_seconds_excluding_lunch(
            new DateTimeImmutable((string) $row['work_started_at'], new DateTimeZone('Asia/Baku')),
            new DateTimeImmutable((string) $workEndAt, new DateTimeZone('Asia/Baku')),
            '13:00:00',
            '14:00:00'
        ), 60);
        $employees[$employeeId]['stats']['work_minutes'] += $workMinutes;
        $employees[$employeeId]['stats']['work_samples']++;
        $summary['work_minutes'] += $workMinutes;
        $summary['work_samples']++;
    }

    if (count($employees[$employeeId]['tasks']) < 8) {
        $employees[$employeeId]['tasks'][] = [
            'id' => (int) $row['id'],
            'ticket_no' => (string) $row['ticket_no'],
            'subject' => (string) $row['subject'],
            'message' => (string) $row['message'],
            'customer_name' => (string) $row['customer_name'],
            'area' => (string) $row['area'],
            'status' => $status,
            'priority' => $priority,
            'due_date' => $dueDate !== '' ? $dueDate : null,
            'is_overdue' => $isOverdue,
            'is_stale_24h' => $isStale,
            'opened_at' => (string) $row['opened_at'],
            'updated_at' => (string) $row['updated_at'],
            'resolved_at' => $row['resolved_at'] !== null ? (string) $row['resolved_at'] : null,
            'age_hours' => (int) ($row['age_hours'] ?? 0),
            'completion_hours' => $row['completion_hours'] !== null ? (int) $row['completion_hours'] : null,
            'timing' => [
                'work_started_at' => $row['work_started_at'],
                'resolved_at' => $workEndAt,
                'travel_anchor_at' => $travelAnchorAt,
                'travel_source' => $travelMinutes !== null ? $travelSource : null,
                'travel_minutes' => $travelMinutes,
                'work_minutes' => $workMinutes,
            ],
            'created_by_name' => (string) ($row['created_by_name'] ?? ''),
            'last_event' => [
                'type' => (string) ($row['last_event_type'] ?? ''),
                'note' => (string) ($row['last_event_note'] ?? ''),
                'at' => $row['last_event_at'] !== null ? (string) $row['last_event_at'] : null,
            ],
        ];
    }
}

foreach ($employees as $id => &$employee) {
    $hours = $employeeCompletionHours[$id] ?? [];
    if ($hours !== []) {
        $employee['stats']['avg_completion_hours'] = round(array_sum($hours) / count($hours), 1);
    }
    $employee['stats']['avg_travel_minutes'] = (int) ($employee['stats']['travel_samples'] ?? 0) > 0
        ? round((int) $employee['stats']['travel_minutes'] / (int) $employee['stats']['travel_samples'])
        : 0;
    $employee['stats']['avg_work_minutes'] = (int) ($employee['stats']['work_samples'] ?? 0) > 0
        ? round((int) $employee['stats']['work_minutes'] / (int) $employee['stats']['work_samples'])
        : 0;
}
unset($employee);

app_mobile_json_response([
    'period' => [
        'key' => $period,
        'start' => $rangeStart,
        'end' => $rangeEnd,
    ],
    'summary' => $summary + [
        'avg_completion_hours' => $completionHours === []
            ? 0.0
            : round(array_sum($completionHours) / count($completionHours), 1),
        'avg_travel_minutes' => (int) $summary['travel_samples'] > 0
            ? round((int) $summary['travel_minutes'] / (int) $summary['travel_samples'])
            : 0,
        'avg_work_minutes' => (int) $summary['work_samples'] > 0
            ? round((int) $summary['work_minutes'] / (int) $summary['work_samples'])
            : 0,
    ],
    'employees' => array_values(array_filter(
        $employees,
        static fn (array $employee): bool => (int) ($employee['stats']['total'] ?? 0) > 0 || (int) $employee['id'] !== 0
    )),
], 200);
