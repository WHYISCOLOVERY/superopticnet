<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

$user = app_mobile_authenticate();
app_mobile_require_role($user, ['admin', 'manager', 'super_admin', 'director', 'team_lead']);

$period = strtolower(trim((string) ($_GET['period'] ?? 'day')));
if (!in_array($period, ['day', 'week', 'month', 'year'], true)) {
    app_mobile_json_response(null, 422, 'period day, week, month və ya year olmalıdır.');
}

$timezone = new DateTimeZone('Asia/Baku');
$today = new DateTimeImmutable('today', $timezone);
$end = $today;
$label = 'Bu gün';

if ($period === 'week') {
    $start = $today->modify('monday this week');
    $end = $start->modify('+6 days');
    $label = 'Bu həftə';
} elseif ($period === 'month') {
    $start = $today->modify('first day of this month');
    $end = $today->modify('last day of this month');
    $label = 'Bu ay';
} elseif ($period === 'year') {
    $start = $today->setDate((int) $today->format('Y'), 1, 1);
    $end = $today->setDate((int) $today->format('Y'), 12, 31);
    $label = 'Bu il';
} else {
    $start = $today;
}

$db = app_db();
$params = [
    'start_date' => $start->format('Y-m-d'),
    'end_date' => $end->format('Y-m-d'),
];

$summaryStatement = $db->prepare(
    "SELECT
        COUNT(DISTINCT e.employee_account_id) AS active_employees,
        COUNT(e.id) AS recorded_days,
        SUM(e.attendance_status = 'late') AS late_days,
        SUM(e.check_in_at IS NOT NULL AND e.check_out_at IS NULL AND e.work_date < CURRENT_DATE()) AS missing_checkout_days,
        SUM(e.check_out_at IS NOT NULL
            AND TIME(e.check_out_at) < COALESCE(p.work_end_time, '18:00:00')) AS early_leave_days,
        COALESCE(SUM(e.worked_minutes), 0) AS worked_minutes,
        COALESCE(SUM(GREATEST(0, COALESCE(p.expected_work_minutes, 480) - e.worked_minutes)), 0) AS missing_minutes
     FROM hr_attendance_events e
     LEFT JOIN hr_attendance_profiles p
       ON p.employee_account_id = e.employee_account_id AND p.status = 'active'
     WHERE e.work_date BETWEEN :start_date AND :end_date"
);
$summaryStatement->execute($params);
$summary = $summaryStatement->fetch(PDO::FETCH_ASSOC) ?: [];

$employeeStatement = $db->prepare(
    "SELECT
        a.id, a.full_name, a.employee_role, a.job_title, a.area,
        COALESCE(p.work_start_time, '09:00:00') AS work_start_time,
        COALESCE(p.work_end_time, '18:00:00') AS work_end_time,
        COALESCE(p.expected_work_minutes, 480) AS expected_work_minutes,
        COUNT(e.id) AS recorded_days,
        SUM(e.attendance_status = 'late') AS late_days,
        SUM(e.check_in_at IS NOT NULL AND e.check_out_at IS NULL AND e.work_date < CURRENT_DATE()) AS missing_checkout_days,
        SUM(e.check_out_at IS NOT NULL
            AND TIME(e.check_out_at) < COALESCE(p.work_end_time, '18:00:00')) AS early_leave_days,
        COALESCE(SUM(e.worked_minutes), 0) AS worked_minutes,
        COALESCE(SUM(GREATEST(0, COALESCE(p.expected_work_minutes, 480) - e.worked_minutes)), 0) AS missing_minutes,
        MAX(e.check_in_at) AS last_check_in_at,
        MAX(e.check_out_at) AS last_check_out_at
     FROM portal_accounts a
     LEFT JOIN hr_attendance_profiles p
       ON p.employee_account_id = a.id AND p.status = 'active'
     LEFT JOIN hr_attendance_events e
       ON e.employee_account_id = a.id
      AND e.work_date BETWEEN :start_date AND :end_date
     WHERE a.role = 'employee' AND a.is_active = 1
     GROUP BY a.id, a.full_name, a.employee_role, a.job_title, a.area,
              p.work_start_time, p.work_end_time, p.expected_work_minutes
     ORDER BY late_days DESC, missing_checkout_days DESC, early_leave_days DESC,
              recorded_days ASC, a.full_name ASC
     LIMIT 100"
);
$employeeStatement->execute($params);
$employees = $employeeStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$recentStatement = $db->prepare(
    "SELECT e.employee_account_id, e.employee_name, e.work_date, e.check_in_at,
            e.check_out_at, e.attendance_status, e.worked_minutes,
            COALESCE(p.work_start_time, '09:00:00') AS work_start_time,
            COALESCE(p.work_end_time, '18:00:00') AS work_end_time,
            COALESCE(p.expected_work_minutes, 480) AS expected_work_minutes,
            a.area, a.job_title
       FROM hr_attendance_events e
       LEFT JOIN portal_accounts a ON a.id = e.employee_account_id
       LEFT JOIN hr_attendance_profiles p
         ON p.employee_account_id = e.employee_account_id AND p.status = 'active'
      WHERE e.work_date BETWEEN :start_date AND :end_date
      ORDER BY e.work_date DESC, e.check_in_at DESC
      LIMIT 80"
);
$recentStatement->execute($params);
$recentRows = $recentStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$toInt = static fn (array $row, string $key): int => (int) ($row[$key] ?? 0);

app_mobile_json_response([
    'period' => [
        'key' => $period,
        'label' => $label,
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $end->format('Y-m-d'),
    ],
    'summary' => [
        'active_employees' => $toInt($summary, 'active_employees'),
        'recorded_days' => $toInt($summary, 'recorded_days'),
        'late_days' => $toInt($summary, 'late_days'),
        'missing_checkout_days' => $toInt($summary, 'missing_checkout_days'),
        'early_leave_days' => $toInt($summary, 'early_leave_days'),
        'worked_minutes' => $toInt($summary, 'worked_minutes'),
        'missing_minutes' => $toInt($summary, 'missing_minutes'),
    ],
    'employees' => array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'full_name' => (string) $row['full_name'],
            'employee_role' => (string) ($row['employee_role'] ?? ''),
            'job_title' => (string) ($row['job_title'] ?? ''),
            'area' => (string) ($row['area'] ?? ''),
            'work_start_time' => substr((string) ($row['work_start_time'] ?? '09:00'), 0, 5),
            'work_end_time' => substr((string) ($row['work_end_time'] ?? '18:00'), 0, 5),
            'expected_work_minutes' => (int) ($row['expected_work_minutes'] ?? 480),
            'recorded_days' => (int) ($row['recorded_days'] ?? 0),
            'late_days' => (int) ($row['late_days'] ?? 0),
            'missing_checkout_days' => (int) ($row['missing_checkout_days'] ?? 0),
            'early_leave_days' => (int) ($row['early_leave_days'] ?? 0),
            'worked_minutes' => (int) ($row['worked_minutes'] ?? 0),
            'missing_minutes' => (int) ($row['missing_minutes'] ?? 0),
            'last_check_in_at' => (string) ($row['last_check_in_at'] ?? ''),
            'last_check_out_at' => (string) ($row['last_check_out_at'] ?? ''),
        ];
    }, $employees),
    'recent' => array_map(static function (array $row): array {
        $earlyLeave = !empty($row['check_out_at'])
            && substr((string) $row['check_out_at'], 11, 8) < (string) ($row['work_end_time'] ?? '18:00:00');
        return [
            'employee_account_id' => (int) $row['employee_account_id'],
            'employee_name' => (string) $row['employee_name'],
            'area' => (string) ($row['area'] ?? ''),
            'job_title' => (string) ($row['job_title'] ?? ''),
            'work_date' => (string) $row['work_date'],
            'check_in_at' => (string) ($row['check_in_at'] ?? ''),
            'check_out_at' => (string) ($row['check_out_at'] ?? ''),
            'attendance_status' => (string) $row['attendance_status'],
            'worked_minutes' => (int) ($row['worked_minutes'] ?? 0),
            'work_start_time' => substr((string) ($row['work_start_time'] ?? '09:00'), 0, 5),
            'work_end_time' => substr((string) ($row['work_end_time'] ?? '18:00'), 0, 5),
            'early_leave' => $earlyLeave,
        ];
    }, $recentRows),
], 200);
