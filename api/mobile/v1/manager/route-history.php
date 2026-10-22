<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__) . '/lib/mobile-supervision-lib.php';

// Full-day movement history of one employee for supervisors: every saved GPS
// point of the chosen day (down-sampled for transport), the places where the
// employee stayed, and the duty sessions of that day.

$user = app_mobile_authenticate();
app_mobile_require_role($user, [
    'admin', 'manager', 'super_admin', 'director', 'team_lead',
    'operator', 'night_operator', 'support', 'engineer', 'noc', 'network_engineer',
]);

$employeeId = (int) ($_GET['employee_id'] ?? 0);
if ($employeeId <= 0) {
    app_mobile_json_response(null, 422, 'Əməkdaş seçilməyib.');
}
$date = trim((string) ($_GET['date'] ?? date('Y-m-d')));
$day = DateTimeImmutable::createFromFormat('Y-m-d', $date, new DateTimeZone('Asia/Baku'));
if (!$day instanceof DateTimeImmutable || $day->format('Y-m-d') !== $date) {
    app_mobile_json_response(null, 422, 'Tarix YYYY-MM-DD formatında olmalıdır.');
}
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Baku'));
if ($day > $today || $day < $today->modify('-31 days')) {
    app_mobile_json_response(null, 422, 'Yalnız son 31 gün üçün tarixçə saxlanılır.');
}

$db = app_db();
$isGlobalViewer = in_array((string) ($user['role'] ?? ''), ['admin', 'super_admin', 'director'], true)
    || in_array((string) ($user['employee_role'] ?? ''), [
        'admin', 'super_admin', 'director', 'operator', 'night_operator', 'support',
    ], true);

$employee = $db->prepare("SELECT id, full_name, area, employee_role, job_title FROM portal_accounts WHERE id = ? AND role = 'employee' LIMIT 1");
$employee->execute([$employeeId]);
$employeeRow = $employee->fetch(PDO::FETCH_ASSOC);
if (!is_array($employeeRow)) {
    app_mobile_json_response(null, 404, 'Əməkdaş tapılmadı.');
}

if (!$isGlobalViewer) {
    $viewerAreas = app_db_account_areas((int) $user['id']);
    if ($viewerAreas === [] && trim((string) ($user['area'] ?? '')) !== '') {
        $viewerAreas[] = trim((string) $user['area']);
    }
    $employeeAreas = app_db_account_areas($employeeId);
    if (trim((string) ($employeeRow['area'] ?? '')) !== '') {
        $employeeAreas[] = trim((string) $employeeRow['area']);
    }
    if ($viewerAreas === [] || array_intersect($viewerAreas, $employeeAreas) === []) {
        app_mobile_json_response(null, 403, 'Bu əməkdaşın marşrutuna baxmaq icazəniz yoxdur.');
    }
}

$from = $day->format('Y-m-d 00:00:00');
$to = $day->format('Y-m-d 23:59:59');
$stmt = $db->prepare(
    'SELECT latitude, longitude, accuracy, speed, recorded_at, activity_type
       FROM employee_live_locations
      WHERE account_id = ? AND is_mock = 0 AND recorded_at BETWEEN ? AND ?
      ORDER BY recorded_at ASC, id ASC
      LIMIT 6000'
);
$stmt->execute([$employeeId, $from, $to]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$points = [];
$stops = [];
$distanceMeters = 0.0;
$previous = null;
$stopStart = null;
$stopLast = null;
foreach ($rows as $row) {
    $point = [
        'latitude' => (float) $row['latitude'],
        'longitude' => (float) $row['longitude'],
        'recorded_at' => (string) $row['recorded_at'],
        'speed_kmh' => $row['speed'] !== null ? round(((float) $row['speed']) * 3.6, 1) : null,
        'event' => in_array((string) $row['activity_type'], ['check_in', 'check_out'], true) ? (string) $row['activity_type'] : null,
    ];
    if ($previous !== null) {
        $step = app_mobile_distance_meters($previous['latitude'], $previous['longitude'], $point['latitude'], $point['longitude']);
        if ($step < 20000) {
            $distanceMeters += $step;
        }
    }
    // A stay = consecutive points within 60 m of the first point of the stay.
    if ($stopStart === null) {
        $stopStart = $point;
        $stopLast = $point;
    } else {
        $away = app_mobile_distance_meters($stopStart['latitude'], $stopStart['longitude'], $point['latitude'], $point['longitude']);
        if ($away <= 60) {
            $stopLast = $point;
        } else {
            $minutes = (int) round((strtotime($stopLast['recorded_at']) - strtotime($stopStart['recorded_at'])) / 60);
            if ($minutes >= 5) {
                $stops[] = [
                    'latitude' => $stopStart['latitude'],
                    'longitude' => $stopStart['longitude'],
                    'arrived_at' => $stopStart['recorded_at'],
                    'left_at' => $stopLast['recorded_at'],
                    'minutes' => $minutes,
                ];
            }
            $stopStart = $point;
            $stopLast = $point;
        }
    }
    $previous = $point;
    $points[] = $point;
}
if ($stopStart !== null && $stopLast !== null) {
    $minutes = (int) round((strtotime($stopLast['recorded_at']) - strtotime($stopStart['recorded_at'])) / 60);
    if ($minutes >= 5) {
        $stops[] = [
            'latitude' => $stopStart['latitude'],
            'longitude' => $stopStart['longitude'],
            'arrived_at' => $stopStart['recorded_at'],
            'left_at' => $stopLast['recorded_at'],
            'minutes' => $minutes,
        ];
    }
}

// Keep the transport small: at most ~1500 points, always keeping check-in/out points.
$maxPoints = 1500;
$sampled = $points;
if (count($points) > $maxPoints) {
    $stride = (int) ceil(count($points) / $maxPoints);
    $sampled = [];
    foreach ($points as $index => $point) {
        if ($index % $stride === 0 || $point['event'] !== null || $index === count($points) - 1) {
            $sampled[] = $point;
        }
    }
}

$sessions = $db->prepare(
    'SELECT created_at AS started_at, ended_at, ended_reason
       FROM mobile_duty_sessions
      WHERE account_id = ? AND created_at <= ? AND (ended_at IS NULL OR ended_at >= ?)
      ORDER BY id ASC'
);
$sessions->execute([$employeeId, $to, $from]);

app_mobile_json_response([
    'employee' => [
        'id' => (int) $employeeRow['id'],
        'name' => (string) $employeeRow['full_name'],
        'title' => (string) ($employeeRow['job_title'] ?? ''),
        'area' => (string) ($employeeRow['area'] ?? ''),
    ],
    'date' => $date,
    'summary' => [
        'saved_points' => count($points),
        'first_seen_at' => $points !== [] ? $points[0]['recorded_at'] : null,
        'last_seen_at' => $points !== [] ? $points[count($points) - 1]['recorded_at'] : null,
        'distance_km' => round($distanceMeters / 1000, 2),
        'stop_count' => count($stops),
    ],
    'duty_sessions' => $sessions->fetchAll(PDO::FETCH_ASSOC) ?: [],
    'stops' => $stops,
    'points' => $sampled,
], 200);
