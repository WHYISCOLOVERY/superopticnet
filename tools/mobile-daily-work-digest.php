<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/api/mobile/v1/lib/mobile-supervision-lib.php';

$options = getopt('', ['date::', 'force']);
$timezone = new DateTimeZone('Asia/Baku');
$now = new DateTimeImmutable('now', $timezone);
$date = trim((string) ($options['date'] ?? $now->format('Y-m-d')));
$day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
if (!$day || $day->format('Y-m-d') !== $date) {
    fwrite(STDERR, "Invalid --date. Use YYYY-MM-DD.\n");
    exit(2);
}
if (!array_key_exists('force', $options) && $date === $now->format('Y-m-d') && $now->format('H:i') < '18:05') {
    echo "skipped: daily digest runs after 18:05 Asia/Baku\n";
    exit(0);
}

$start = $date . ' 00:00:00';
$end = $day->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
$db = app_db();
$employees = $db->query(
    "SELECT id,full_name FROM portal_accounts
      WHERE role='employee' AND is_active=1 AND employee_role IN ('technician','engineer')
      ORDER BY full_name"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$ticketStatement = $db->prepare(
    "SELECT t.id,t.ticket_no,t.subject,t.status,t.assigned_technician_account_id,
            MAX(CASE WHEN e.event_type='assigned' THEN e.created_at END) assigned_at,
            MIN(CASE WHEN e.event_type='started' THEN e.created_at END) started_at,
            MIN(CASE WHEN e.event_type='resolved' THEN e.created_at END) resolved_event_at,
            t.resolved_at
       FROM portal_tickets t
       LEFT JOIN portal_ticket_events e ON e.ticket_id=t.id
      WHERE t.assigned_technician_account_id IS NOT NULL
        AND (e.created_at<? OR t.opened_at<?)
        AND (e.created_at>=? OR t.resolved_at>=? OR t.status IN ('open','assigned','in_progress','Aciqdir','Icrada','Gozlenilir'))
      GROUP BY t.id,t.ticket_no,t.subject,t.status,t.assigned_technician_account_id,t.resolved_at"
);
$ticketStatement->execute([$end, $end, $start, $start]);
$tickets = $ticketStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$attendanceStatement = $db->prepare(
    'SELECT employee_account_id,check_in_at FROM hr_attendance_events WHERE work_date=?'
);
$attendanceStatement->execute([$date]);
$checkIns = [];
foreach ($attendanceStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $attendance) {
    $checkIns[(int) $attendance['employee_account_id']] = $attendance['check_in_at'];
}

$reports = [];
$totals = ['assigned' => 0, 'started' => 0, 'completed' => 0, 'incomplete' => 0, 'travel_minutes' => 0, 'work_minutes' => 0];
foreach ($employees as $employee) {
    $employeeId = (int) $employee['id'];
    $rows = array_values(array_filter($tickets, static fn (array $ticket): bool => (int) $ticket['assigned_technician_account_id'] === $employeeId));
    usort($rows, static fn (array $a, array $b): int => strcmp((string) ($a['started_at'] ?? '9999'), (string) ($b['started_at'] ?? '9999')));
    $report = [
        'employee_id' => $employeeId,
        'name' => (string) $employee['full_name'],
        'assigned' => 0,
        'started' => 0,
        'completed' => 0,
        'incomplete' => 0,
        'travel_minutes' => 0,
        'work_minutes' => 0,
        'completed_tickets' => [],
        'incomplete_tickets' => [],
    ];
    $previousResolvedAt = null;
    foreach ($rows as $ticket) {
        $assignedAt = (string) ($ticket['assigned_at'] ?? '');
        $startedAt = (string) ($ticket['started_at'] ?? '');
        $resolvedAt = (string) (($ticket['resolved_event_at'] ?? '') ?: ($ticket['resolved_at'] ?? ''));
        if ($assignedAt >= $start && $assignedAt < $end) $report['assigned']++;
        if ($startedAt >= $start && $startedAt < $end) {
            $report['started']++;
            $anchor = $previousResolvedAt ?: ($checkIns[$employeeId] ?? null);
            if ($anchor && $anchor < $startedAt) {
                $report['travel_minutes'] += intdiv(app_mobile_seconds_excluding_lunch(
                    new DateTimeImmutable($anchor, $timezone),
                    new DateTimeImmutable($startedAt, $timezone),
                    '13:00:00',
                    '14:00:00'
                ), 60);
            }
        }
        if ($resolvedAt >= $start && $resolvedAt < $end) {
            $report['completed']++;
            $report['completed_tickets'][] = (string) $ticket['ticket_no'];
            if ($startedAt !== '' && $startedAt < $resolvedAt) {
                $report['work_minutes'] += intdiv(app_mobile_seconds_excluding_lunch(
                    new DateTimeImmutable($startedAt, $timezone),
                    new DateTimeImmutable($resolvedAt, $timezone),
                    '13:00:00',
                    '14:00:00'
                ), 60);
            }
            $previousResolvedAt = $resolvedAt;
        }
        if (in_array((string) $ticket['status'], ['open','assigned','in_progress','Aciqdir','Icrada','Gozlenilir'], true)
            && ($assignedAt === '' || $assignedAt < $end)) {
            $report['incomplete']++;
            $report['incomplete_tickets'][] = (string) $ticket['ticket_no'];
        }
    }
    foreach (array_keys($totals) as $metric) $totals[$metric] += (int) $report[$metric];
    if ($report['assigned'] + $report['started'] + $report['completed'] + $report['incomplete'] > 0) $reports[] = $report;
}

$alertKey = 'daily_digest:' . $date;
$severity = $totals['incomplete'] > 0 ? 'warning' : 'info';
$message = sprintf(
    '%d əməkdaş: %d tapşırıq verildi, %d iş başlandı, %d tamamlandı, %d yarımçıq qaldı. Keçid/yol %d dəq, faktiki iş %d dəq.',
    count($reports), $totals['assigned'], $totals['started'], $totals['completed'], $totals['incomplete'],
    $totals['travel_minutes'], $totals['work_minutes']
);
$insert = $db->prepare(
    "INSERT IGNORE INTO mobile_manager_alerts
        (alert_key,account_id,alert_type,severity,title,message,event_at,metadata_json)
     VALUES (?,NULL,'daily_digest',?,'Gün sonu əməliyyat hesabatı',?,?,?)"
);
$insert->execute([
    $alertKey,
    $severity,
    mb_substr($message, 0, 500),
    $date . ' 18:05:00',
    json_encode(['date' => $date, 'totals' => $totals, 'employees' => $reports], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);

echo ($insert->rowCount() === 1 ? 'created: ' : 'already_exists: ') . $message . PHP_EOL;
