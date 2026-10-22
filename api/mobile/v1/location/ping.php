<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__) . '/lib/mobile-supervision-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_mobile_json_response(null, 405, 'Yalnız POST sorğuları dəstəklənir.');
}

$user = app_mobile_authenticate();
app_mobile_require_role($user, ['technician', 'engineer', 'team_lead', 'operator', 'night_operator', 'support', 'noc', 'network_engineer']);

$body = app_mobile_request_body();
$accountId = (int) $user['id'];

// Can accept either a single point or a batch array of offline-recorded points
$locations = isset($body['locations']) && is_array($body['locations']) ? $body['locations'] : [$body];

$db = app_db();
$consentVersion = app_mobile_required_gps_consent_version();
if (!app_mobile_has_current_gps_consent($accountId, $db)) {
    app_mobile_json_response([
        'consent_required' => true,
        'consent_version' => $consentVersion,
    ], 403, 'GPS məlumatı üçün qüvvədə olan razılıq yoxdur.');
}
if (!app_mobile_is_on_duty($accountId, $db)) {
    app_mobile_json_response(null, 409, 'GPS yalnız serverdə aktiv növbə zamanı qəbul edilir.');
}
$inserted = 0;
$anomalies = [];
$geofenceAlerts = [];

$stmt = $db->prepare(
    "INSERT INTO employee_live_locations
     (account_id, client_uuid, latitude, longitude, accuracy, speed, heading, altitude, battery_level, is_charging, is_mock, is_on_duty, activity_type, recorded_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE client_uuid = VALUES(client_uuid)"
);

// Get last known location for teleportation / velocity anomaly check
$stmtLast = $db->prepare(
    "SELECT latitude, longitude, recorded_at FROM employee_live_locations 
     WHERE account_id = ? ORDER BY id DESC LIMIT 1"
);
$stmtLast->execute([$accountId]);
$lastPoint = $stmtLast->fetch(PDO::FETCH_ASSOC);

foreach ($locations as $loc) {
    if (!isset($loc['latitude'], $loc['longitude'])) {
        continue;
    }

    $lat = (float) $loc['latitude'];
    $lng = (float) $loc['longitude'];
    $clientUuid = trim((string) ($loc['client_uuid'] ?? ''));
    if ($clientUuid === '' || strlen($clientUuid) > 64 || preg_match('/^[A-Za-z0-9._:-]+$/', $clientUuid) !== 1) {
        continue;
    }
    
    // Azerbaijan bounding box check (lat: 38.0 to 42.0, lng: 44.0 to 51.0)
    if ($lat < 35.0 || $lat > 45.0 || $lng < 40.0 || $lng > 55.0) {
        continue; // Discard invalid coordinates
    }

    $acc = isset($loc['accuracy']) ? (float) $loc['accuracy'] : null;
    $speed = isset($loc['speed']) ? (float) $loc['speed'] : null;
    $heading = isset($loc['heading']) ? (float) $loc['heading'] : null;
    $alt = isset($loc['altitude']) ? (float) $loc['altitude'] : null;
    $battery = isset($loc['battery_level']) ? (int) $loc['battery_level'] : null;
    $isCharging = !empty($loc['is_charging']) ? 1 : 0;
    $isMock = !empty($loc['is_mock']) ? 1 : 0;
    $isOnDuty = 1;
    $activity = substr(trim((string) ($loc['activity_type'] ?? 'unknown')), 0, 32);
    $recordedAt = !empty($loc['recorded_at']) ? date('Y-m-d H:i:s', strtotime((string) $loc['recorded_at'])) : date('Y-m-d H:i:s');

    // Teleportation / Impossible Velocity Check:
    if ($lastPoint && $isOnDuty && !$isMock) {
        $lastLat = (float) $lastPoint['latitude'];
        $lastLng = (float) $lastPoint['longitude'];
        $lastTime = strtotime((string) $lastPoint['recorded_at']);
        $currentTime = strtotime($recordedAt);
        $timeDiffSeconds = abs($currentTime - $lastTime);

        if ($timeDiffSeconds > 0 && $timeDiffSeconds < 600) { // Within 10 minutes
            // Haversine distance in km
            $theta = $lng - $lastLng;
            $dist = sin(deg2rad($lat)) * sin(deg2rad($lastLat)) +  cos(deg2rad($lat)) * cos(deg2rad($lastLat)) * cos(deg2rad($theta));
            $dist = acos(min(1.0, max(-1.0, $dist)));
            $dist = rad2deg($dist) * 60 * 1.1515 * 1.609344; // km

            $speedKmh = ($dist / ($timeDiffSeconds / 3600));
            if ($speedKmh > 180.0) { // Faster than 180 km/h in urban area
                $anomalies[] = [
                    'type' => 'IMPOSSIBLE_SPEED',
                    'speed_kmh' => round($speedKmh, 1),
                    'distance_km' => round($dist, 2),
                    'seconds' => $timeDiffSeconds,
                ];
                $isMock = 1; // Mark as suspected mock/fake
            }
        }
    }

    $stmt->execute([
        $accountId, $clientUuid, $lat, $lng, $acc, $speed, $heading, $alt,
        $battery, $isCharging, $isMock, $isOnDuty, $activity, $recordedAt
    ]);

    if ($stmt->rowCount() === 1) {
        $lastPoint = ['latitude' => $lat, 'longitude' => $lng, 'recorded_at' => $recordedAt];
        $inserted++;
    }

    // Compare the point only with explicitly configured geofences for the
    // employee's assigned areas. Areas without coordinates stay neutral.
    try {
        $geofences = $db->prepare(
            'SELECT DISTINCT g.area,g.center_latitude,g.center_longitude,g.radius_meters
               FROM mobile_area_geofences g
               JOIN portal_accounts p ON p.id=?
               LEFT JOIN portal_account_areas a ON a.account_id=p.id AND a.area=g.area
              WHERE g.is_active=1 AND (a.account_id IS NOT NULL OR p.area=g.area)'
        );
        $geofences->execute([$accountId]);
        foreach ($geofences->fetchAll(PDO::FETCH_ASSOC) ?: [] as $geofence) {
            $earthRadius = 6371000.0;
            $dLat = deg2rad($lat - (float) $geofence['center_latitude']);
            $dLng = deg2rad($lng - (float) $geofence['center_longitude']);
            $a = sin($dLat / 2) ** 2
                + cos(deg2rad((float) $geofence['center_latitude'])) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;
            $distance = (int) round($earthRadius * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a))));
            $area = (string) $geofence['area'];
            $activeExit = $db->prepare(
                "SELECT id FROM mobile_geofence_events
                  WHERE account_id=? AND area=? AND event_type='left_area' AND resolved_at IS NULL
                  ORDER BY id DESC LIMIT 1"
            );
            $activeExit->execute([$accountId, $area]);
            $activeExitId = (int) $activeExit->fetchColumn();
            if ($distance > (int) $geofence['radius_meters'] && $activeExitId <= 0) {
                $session = $db->prepare('SELECT id FROM mobile_duty_sessions WHERE account_id=? AND ended_at IS NULL ORDER BY id DESC LIMIT 1');
                $session->execute([$accountId]);
                $db->prepare(
                    "INSERT INTO mobile_geofence_events
                     (account_id,area,event_type,latitude,longitude,distance_meters,duty_session_id)
                     VALUES (?,?,'left_area',?,?,?,?)"
                )->execute([$accountId, $area, $lat, $lng, $distance, (int) $session->fetchColumn() ?: null]);
                $eventId = (int) $db->lastInsertId();
                $employeeName = trim((string) ($user['full_name'] ?? $user['login'] ?? 'Əməkdaş'));
                $db->prepare(
                    'INSERT IGNORE INTO mobile_manager_alerts
                        (alert_key,account_id,alert_type,severity,title,message,event_at,metadata_json)
                     VALUES (?,? ,"left_area","warning",?,?,?,?)'
                )->execute([
                    'left_area:' . $eventId,
                    $accountId,
                    $employeeName . ' iş ərazisini tərk edib',
                    $area . ' sərhədindən kənara çıxış qeydə alındı.',
                    $recordedAt,
                    json_encode(['geofence_event_id' => $eventId, 'area' => $area, 'distance_meters' => $distance], JSON_UNESCAPED_UNICODE),
                ]);
                $geofenceAlerts[] = ['area' => $area, 'event' => 'left_area', 'distance_meters' => $distance];
            } elseif ($distance <= (int) $geofence['radius_meters'] && $activeExitId > 0) {
                $db->prepare('UPDATE mobile_geofence_events SET resolved_at=NOW() WHERE id=?')->execute([$activeExitId]);
                $db->prepare('UPDATE mobile_manager_alerts SET resolved_at=? WHERE alert_key=?')
                    ->execute([$recordedAt, 'left_area:' . $activeExitId]);
                $db->prepare(
                    "INSERT INTO mobile_geofence_events
                     (account_id,area,event_type,latitude,longitude,distance_meters,duty_session_id,resolved_at)
                     VALUES (?,?,'returned_to_area',?,?,?,?,NOW())"
                )->execute([$accountId, $area, $lat, $lng, $distance, null]);
                $geofenceAlerts[] = ['area' => $area, 'event' => 'returned_to_area', 'distance_meters' => $distance];
            }
        }
    } catch (Throwable) {
        // Migration/configuration not present yet: location recording continues.
    }

    if ($isMock === 0) {
        try {
            $homeEvent = app_mobile_process_home_dwell($db, $user, $lat, $lng, $recordedAt);
            if ($homeEvent !== null) $geofenceAlerts[] = $homeEvent;
        } catch (Throwable) {
            // Supervision tables are additive; GPS recording must keep working.
        }
    }
}

if ($inserted > 0) {
    $db->prepare(
        "INSERT INTO mobile_location_compliance
            (account_id,device_uuid,service_enabled,permission_status,disabled_since,last_checked_at)
         VALUES (?,?,1,'granted',NULL,NOW())
         ON DUPLICATE KEY UPDATE device_uuid=VALUES(device_uuid),service_enabled=1,permission_status='granted',
            disabled_since=NULL,last_checked_at=NOW()"
    )->execute([$accountId, (string) ($user['authenticated_device_uuid'] ?? '')]);
    $db->prepare(
        "UPDATE mobile_manager_alerts SET resolved_at=NOW()
          WHERE account_id=? AND alert_type IN ('gps_disabled','gps_stale') AND resolved_at IS NULL"
    )->execute([$accountId]);
}

app_mobile_json_response([
    'recorded' => true,
    'points_count' => $inserted,
    'has_anomalies' => !empty($anomalies),
    'anomalies' => $anomalies,
    'geofence_events' => $geofenceAlerts,
], 200);
