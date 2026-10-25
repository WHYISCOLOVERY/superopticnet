<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

$user = app_mobile_authenticate();
app_mobile_require_role($user, ['technician', 'engineer', 'team_lead', 'operator', 'night_operator', 'support', 'noc', 'network_engineer']);

$db = app_db();
$accountId = (int) $user['id'];
$today = date('Y-m-d');

$todayStatement = $db->prepare(
    'SELECT e.*, p.work_start_time, p.work_end_time, p.late_grace_minutes, p.expected_work_minutes
       FROM hr_attendance_events e
       LEFT JOIN hr_attendance_profiles p ON p.employee_account_id = e.employee_account_id AND p.status = "active"
      WHERE e.employee_account_id = ? AND e.work_date = ? LIMIT 1'
);
$todayStatement->execute([$accountId, $today]);
$todayRecord = $todayStatement->fetch(PDO::FETCH_ASSOC) ?: null;

$statsStatement = $db->prepare(
    "SELECT
        COUNT(*) AS recorded_days,
        SUM(attendance_status = 'late') AS late_days,
        SUM(check_out_at IS NULL AND check_in_at IS NOT NULL AND work_date < CURRENT_DATE()) AS missing_checkout_days,
        COALESCE(SUM(worked_minutes), 0) AS worked_minutes
     FROM hr_attendance_events
     WHERE employee_account_id = ? AND work_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)"
);
$statsStatement->execute([$accountId]);
$stats = $statsStatement->fetch(PDO::FETCH_ASSOC) ?: [];

app_mobile_json_response([
    'is_on_duty' => app_mobile_is_on_duty($accountId, $db),
    'today' => $todayRecord,
    'last_30_days' => [
        'recorded_days' => (int) ($stats['recorded_days'] ?? 0),
        'late_days' => (int) ($stats['late_days'] ?? 0),
        'missing_checkout_days' => (int) ($stats['missing_checkout_days'] ?? 0),
        'worked_minutes' => (int) ($stats['worked_minutes'] ?? 0),
    ],
], 200);
