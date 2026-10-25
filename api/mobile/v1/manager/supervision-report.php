<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    app_mobile_json_response(null, 405, 'Yalnız GET sorğusu dəstəklənir.');
}
$user = app_mobile_authenticate();
app_mobile_require_role($user, ['admin', 'manager', 'super_admin', 'director', 'team_lead']);
$db = app_db();

$date = trim((string) ($_GET['date'] ?? date('Y-m-d')));
$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Baku'));
if (!$parsed || $parsed->format('Y-m-d') !== $date) {
    app_mobile_json_response(null, 422, 'Tarix YYYY-MM-DD formatında olmalıdır.');
}
$start = $date . ' 00:00:00';
$end = $parsed->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

$statement = $db->prepare(
    "SELECT p.id,p.full_name,p.area,
            (SELECT COUNT(*) FROM mobile_geofence_events g
              WHERE g.account_id=p.id AND g.event_type='left_area' AND g.opened_at>=? AND g.opened_at<?) AS area_exit_count,
            (SELECT COUNT(*) FROM mobile_home_dwell_events h
              WHERE h.account_id=p.id AND h.alert_started_at IS NOT NULL
                AND h.entered_at<? AND COALESCE(h.exited_at,h.last_seen_at)>=?) AS home_alert_count,
            (SELECT COALESCE(SUM(h.unauthorized_seconds),0) FROM mobile_home_dwell_events h
              WHERE h.account_id=p.id AND h.alert_started_at IS NOT NULL
                AND h.entered_at<? AND COALESCE(h.exited_at,h.last_seen_at)>=?) AS home_seconds,
            (SELECT MIN(h.alert_started_at) FROM mobile_home_dwell_events h
              WHERE h.account_id=p.id AND h.alert_started_at>=? AND h.alert_started_at<?) AS first_home_alert_at,
            (SELECT MAX(COALESCE(h.exited_at,h.last_seen_at)) FROM mobile_home_dwell_events h
              WHERE h.account_id=p.id AND h.alert_started_at>=? AND h.alert_started_at<?) AS last_home_seen_at
       FROM portal_accounts p
      WHERE p.role='employee' AND p.is_active=1
        AND p.employee_role IN ('technician','engineer')
      HAVING area_exit_count>0 OR home_alert_count>0
      ORDER BY home_alert_count DESC,area_exit_count DESC,p.full_name"
);
$statement->execute([$start, $end, $end, $start, $end, $start, $start, $end, $start, $end]);
$rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$openAlerts = $db->prepare(
    'SELECT COUNT(*) FROM mobile_manager_alerts WHERE event_at>=? AND event_at<? AND resolved_at IS NULL'
);
$openAlerts->execute([$start, $end]);

app_mobile_json_response([
    'date' => $date,
    'employee_count' => count($rows),
    'open_alerts' => (int) $openAlerts->fetchColumn(),
    'employees' => array_map(static function (array $row): array {
        $seconds = (int) ($row['home_seconds'] ?? 0);
        return [
            'id' => (int) $row['id'],
            'full_name' => (string) $row['full_name'],
            'area' => (string) ($row['area'] ?? ''),
            'area_exit_count' => (int) $row['area_exit_count'],
            'home_alert_count' => (int) $row['home_alert_count'],
            'home_minutes' => intdiv($seconds, 60),
            'first_home_alert_at' => $row['first_home_alert_at'],
            'last_home_seen_at' => $row['last_home_seen_at'],
        ];
    }, $rows),
]);
