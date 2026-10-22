<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

$user = app_mobile_authenticate();
app_mobile_require_role($user, ['admin', 'manager', 'super_admin', 'director']);
$db = app_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $rows = $db->query(
        "SELECT p.id account_id,p.full_name,p.login,p.job_title,p.is_active,
                s.id schedule_id,s.timezone,s.shift_start,s.shift_end,s.weekdays_json,s.breaks_json,
                s.required_interval_minutes,s.grace_minutes,s.is_enabled,
                (SELECT MAX(a.occurred_at) FROM mobile_operator_activity a WHERE a.account_id=p.id) last_activity_at,
                (SELECT COUNT(*) FROM mobile_manager_alerts ma WHERE ma.account_id=p.id AND ma.alert_type='operator_presence_missed' AND ma.resolved_at IS NULL) open_missed_alerts
           FROM portal_accounts p
           LEFT JOIN mobile_operator_schedules s ON s.account_id=p.id
          WHERE p.role='employee' AND p.employee_role='night_operator' AND p.is_active=1
          ORDER BY p.full_name"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    app_mobile_json_response(['operators' => array_map(static function (array $row): array {
        $row['account_id'] = (int) $row['account_id'];
        $row['schedule_id'] = $row['schedule_id'] === null ? null : (int) $row['schedule_id'];
        $row['is_active'] = (bool) $row['is_active'];
        $row['is_enabled'] = (bool) ($row['is_enabled'] ?? false);
        $row['required_interval_minutes'] = (int) ($row['required_interval_minutes'] ?? 60);
        $row['grace_minutes'] = (int) ($row['grace_minutes'] ?? 10);
        $row['open_missed_alerts'] = (int) $row['open_missed_alerts'];
        $row['weekdays'] = json_decode((string) ($row['weekdays_json'] ?? '[]'), true) ?: [];
        $row['breaks'] = json_decode((string) ($row['breaks_json'] ?? '[]'), true) ?: [];
        unset($row['weekdays_json'], $row['breaks_json']);
        return $row;
    }, $rows)]);
}

if ($method !== 'POST') app_mobile_json_response(null, 405, 'Yalnız GET və POST sorğuları dəstəklənir.');
$body = app_mobile_request_body();
$accountId = (int) ($body['account_id'] ?? 0);
$shiftStart = trim((string) ($body['shift_start'] ?? '20:00'));
$shiftEnd = trim((string) ($body['shift_end'] ?? '08:00'));
$interval = max(30, min(180, (int) ($body['required_interval_minutes'] ?? 60)));
$grace = max(0, min(30, (int) ($body['grace_minutes'] ?? 10)));
$enabled = !empty($body['is_enabled']) ? 1 : 0;
$weekdays = array_values(array_unique(array_filter(array_map('intval', (array) ($body['weekdays'] ?? [])), static fn(int $day): bool => $day >= 1 && $day <= 7)));
sort($weekdays);
$breaks = [];
foreach ((array) ($body['breaks'] ?? []) as $break) {
    if (!is_array($break)) continue;
    $start = trim((string) ($break['start'] ?? ''));
    $end = trim((string) ($break['end'] ?? ''));
    if (preg_match('/^\d{2}:\d{2}$/', $start) && preg_match('/^\d{2}:\d{2}$/', $end)) $breaks[] = ['start' => $start, 'end' => $end];
}
if ($accountId <= 0 || !preg_match('/^\d{2}:\d{2}$/', $shiftStart) || !preg_match('/^\d{2}:\d{2}$/', $shiftEnd) || $weekdays === []) {
    app_mobile_json_response(null, 422, 'Operator, növbə saatları və iş günləri düzgün seçilməlidir.');
}
$check = $db->prepare("SELECT id,full_name FROM portal_accounts WHERE id=? AND role='employee' AND employee_role='night_operator' AND is_active=1");
$check->execute([$accountId]);
$operator = $check->fetch(PDO::FETCH_ASSOC);
if (!$operator) app_mobile_json_response(null, 404, 'Aktiv operator tapılmadı.');

$db->prepare(
    "INSERT INTO mobile_operator_schedules
        (account_id,shift_start,shift_end,weekdays_json,breaks_json,required_interval_minutes,grace_minutes,is_enabled,updated_by_account_id)
     VALUES (?,?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE shift_start=VALUES(shift_start),shift_end=VALUES(shift_end),weekdays_json=VALUES(weekdays_json),
        breaks_json=VALUES(breaks_json),required_interval_minutes=VALUES(required_interval_minutes),grace_minutes=VALUES(grace_minutes),
        is_enabled=VALUES(is_enabled),updated_by_account_id=VALUES(updated_by_account_id)"
)->execute([
    $accountId, $shiftStart . ':00', $shiftEnd . ':00', json_encode($weekdays),
    json_encode($breaks, JSON_UNESCAPED_UNICODE), $interval, $grace, $enabled, (int) $user['id'],
]);

app_mobile_json_response(['saved' => true, 'account_id' => $accountId, 'operator' => $operator['full_name']]);
