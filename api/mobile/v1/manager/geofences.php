<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

$user = app_mobile_authenticate();
app_mobile_require_role($user, ['admin', 'manager', 'super_admin', 'director', 'team_lead']);
$db = app_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $areas = $db->query(
        "SELECT area, COUNT(DISTINCT account_id) AS employee_count
           FROM (
                SELECT id AS account_id, TRIM(area) AS area
                  FROM portal_accounts
                 WHERE role='employee' AND is_active=1 AND TRIM(COALESCE(area,''))<>''
                UNION ALL
                SELECT a.account_id, TRIM(a.area) AS area
                  FROM portal_account_areas a
                  JOIN portal_accounts p ON p.id=a.account_id
                 WHERE p.role='employee' AND p.is_active=1 AND TRIM(COALESCE(a.area,''))<>''
           ) assigned
          GROUP BY area
          ORDER BY area"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $configured = [];
    foreach ($db->query(
        'SELECT id,area,center_latitude,center_longitude,radius_meters,is_active,updated_at
           FROM mobile_area_geofences ORDER BY area'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $configured[(string) $row['area']] = [
            'id' => (int) $row['id'],
            'latitude' => (float) $row['center_latitude'],
            'longitude' => (float) $row['center_longitude'],
            'radius_meters' => (int) $row['radius_meters'],
            'is_active' => (bool) $row['is_active'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    app_mobile_json_response([
        'areas' => array_map(static function (array $row) use ($configured): array {
            $area = (string) $row['area'];
            return [
                'area' => $area,
                'employee_count' => (int) $row['employee_count'],
                'geofence' => $configured[$area] ?? null,
            ];
        }, $areas),
        'employees' => $db->query(
            "SELECT p.id,p.full_name,p.area,p.employee_role,
                    h.center_latitude,h.center_longitude,h.radius_meters,
                    h.dwell_threshold_minutes,
                    COALESCE(h.work_start,s.shift_start) AS work_start,
                    COALESCE(h.work_end,s.shift_end) AS work_end,
                    h.lunch_start,h.lunch_end,h.is_active,h.updated_at
               FROM portal_accounts p
               LEFT JOIN mobile_employee_home_geofences h ON h.account_id=p.id
               LEFT JOIN mobile_operator_schedules s ON s.account_id=p.id AND s.is_enabled=1
              WHERE p.role='employee' AND p.is_active=1
                AND p.employee_role NOT IN ('admin','super_admin')
              ORDER BY p.full_name"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'recent_supervision_alerts' => $db->query(
            "SELECT a.id,a.account_id,a.alert_type,a.severity,a.title,a.message,a.event_at,a.resolved_at,
                    p.full_name
               FROM mobile_manager_alerts a
               LEFT JOIN portal_accounts p ON p.id=a.account_id
              WHERE a.event_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)
              ORDER BY a.event_at DESC LIMIT 100"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_mobile_json_response(null, 405, 'Yalnız GET və POST sorğuları dəstəklənir.');
}

$body = app_mobile_request_body();
$type = trim((string) ($body['type'] ?? 'area'));
$latitude = filter_var($body['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$longitude = filter_var($body['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

if ($type === 'home') {
    $accountId = (int) ($body['account_id'] ?? 0);
    $radiusMeters = filter_var($body['radius_meters'] ?? 150, FILTER_VALIDATE_INT);
    $dwellMinutes = filter_var($body['dwell_threshold_minutes'] ?? 30, FILTER_VALIDATE_INT);
    $workStart = trim((string) ($body['work_start'] ?? '09:00'));
    $workEnd = trim((string) ($body['work_end'] ?? '18:00'));
    $lunchStart = trim((string) ($body['lunch_start'] ?? '13:00'));
    $lunchEnd = trim((string) ($body['lunch_end'] ?? '14:00'));
    $isActive = array_key_exists('is_active', $body) ? (bool) $body['is_active'] : true;
    $employee = $db->prepare(
        "SELECT id,employee_role FROM portal_accounts
          WHERE id=? AND role='employee' AND is_active=1
            AND employee_role NOT IN ('admin','super_admin')"
    );
    $employee->execute([$accountId]);
    $employeeRow = $employee->fetch(PDO::FETCH_ASSOC);
    if (!$employeeRow) app_mobile_json_response(null, 404, 'Aktiv əməkdaş tapılmadı.');
    if ($latitude === false || $latitude < 35 || $latitude > 45
        || $longitude === false || $longitude < 40 || $longitude > 55) {
        app_mobile_json_response(null, 422, 'Azərbaycan daxilində düzgün ev geofence koordinatı tələb olunur.');
    }
    if ($radiusMeters === false || $radiusMeters < 50 || $radiusMeters > 1000) {
        app_mobile_json_response(null, 422, 'Ev geofence radiusu 50–1000 metr arasında olmalıdır.');
    }
    if ($dwellMinutes === false || $dwellMinutes < 30 || $dwellMinutes > 240) {
        app_mobile_json_response(null, 422, 'Xəbərdarlıq müddəti 30–240 dəqiqə arasında olmalıdır.');
    }
    foreach ([$workStart, $workEnd, $lunchStart, $lunchEnd] as $clock) {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $clock)) {
            app_mobile_json_response(null, 422, 'İş və fasilə vaxtı HH:MM formatında olmalıdır.');
        }
    }
    $overnight = $workStart >= $workEnd;
    if ($overnight && (string) $employeeRow['employee_role'] !== 'night_operator') {
        app_mobile_json_response(null, 422, 'Gecəni keçən iş vaxtı yalnız gecə operatoru üçün seçilə bilər.');
    }
    if ($lunchStart >= $lunchEnd && !$overnight) {
        app_mobile_json_response(null, 422, 'Fasilə vaxtlarının ardıcıllığı düzgün deyil.');
    }
    $db->prepare(
        'INSERT INTO mobile_employee_home_geofences
            (account_id,center_latitude,center_longitude,radius_meters,dwell_threshold_minutes,
             work_start,work_end,lunch_start,lunch_end,is_active,created_by_account_id)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            center_latitude=VALUES(center_latitude),center_longitude=VALUES(center_longitude),
            radius_meters=VALUES(radius_meters),dwell_threshold_minutes=VALUES(dwell_threshold_minutes),
            work_start=VALUES(work_start),work_end=VALUES(work_end),
            lunch_start=VALUES(lunch_start),lunch_end=VALUES(lunch_end),
            is_active=VALUES(is_active),created_by_account_id=VALUES(created_by_account_id)'
    )->execute([
        $accountId, $latitude, $longitude, $radiusMeters, $dwellMinutes,
        $workStart . ':00', $workEnd . ':00', $lunchStart . ':00', $lunchEnd . ':00',
        $isActive ? 1 : 0, (int) $user['id'],
    ]);
    app_mobile_json_response([
        'saved' => true,
        'type' => 'home',
        'account_id' => $accountId,
        'radius_meters' => (int) $radiusMeters,
        'dwell_threshold_minutes' => (int) $dwellMinutes,
        'is_active' => $isActive,
    ]);
}

$area = trim((string) ($body['area'] ?? ''));
$radiusMeters = filter_var($body['radius_meters'] ?? null, FILTER_VALIDATE_INT);
$isActive = array_key_exists('is_active', $body) ? (bool) $body['is_active'] : true;

if ($area === '' || mb_strlen($area) > 191) {
    app_mobile_json_response(null, 422, 'Ərazi seçilməlidir.');
}
if ($latitude === false || $latitude < 35 || $latitude > 45
    || $longitude === false || $longitude < 40 || $longitude > 55) {
    app_mobile_json_response(null, 422, 'Azərbaycan daxilində düzgün mərkəz koordinatı tələb olunur.');
}
if ($radiusMeters === false || $radiusMeters < 100 || $radiusMeters > 100000) {
    app_mobile_json_response(null, 422, 'Radius 100–100000 metr arasında olmalıdır.');
}

$areaCheck = $db->prepare(
    "SELECT COUNT(*) FROM (
        SELECT TRIM(area) AS area FROM portal_accounts
         WHERE role='employee' AND is_active=1
        UNION SELECT TRIM(area) AS area FROM portal_account_areas
    ) known WHERE area=?"
);
$areaCheck->execute([$area]);
if ((int) $areaCheck->fetchColumn() < 1) {
    app_mobile_json_response(null, 404, 'Bu əraziyə bağlı aktiv əməkdaş tapılmadı.');
}

$statement = $db->prepare(
    'INSERT INTO mobile_area_geofences
        (area,center_latitude,center_longitude,radius_meters,is_active,created_by_account_id)
     VALUES (?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
        center_latitude=VALUES(center_latitude),
        center_longitude=VALUES(center_longitude),
        radius_meters=VALUES(radius_meters),
        is_active=VALUES(is_active),
        created_by_account_id=VALUES(created_by_account_id)'
);
$statement->execute([
    $area,
    $latitude,
    $longitude,
    $radiusMeters,
    $isActive ? 1 : 0,
    (int) $user['id'],
]);

app_mobile_json_response([
    'saved' => true,
    'area' => $area,
    'latitude' => (float) $latitude,
    'longitude' => (float) $longitude,
    'radius_meters' => (int) $radiusMeters,
    'is_active' => $isActive,
]);
