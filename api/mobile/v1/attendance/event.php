<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__, 4) . '/mgs-integration-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_mobile_json_response(null, 405, 'Yalnız POST sorğuları dəstəklənir.');
}

$user = app_mobile_authenticate();
app_mobile_require_role($user, ['technician', 'engineer', 'team_lead', 'operator', 'night_operator', 'support', 'noc', 'network_engineer']);
$body = app_mobile_request_body();
$eventType = trim((string) ($body['event_type'] ?? ''));
if (!in_array($eventType, ['check_in', 'check_out'], true)) {
    app_mobile_json_response(null, 422, 'event_type check_in və ya check_out olmalıdır.');
}

$latitude = filter_var($body['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$longitude = filter_var($body['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
$clientUuid = trim((string) ($body['client_uuid'] ?? ''));
if ($latitude === false || $longitude === false || $latitude < 35 || $latitude > 45 || $longitude < 40 || $longitude > 55) {
    app_mobile_json_response(null, 422, 'Davamiyyət üçün düzgün GPS mövqeyi tələb olunur.');
}
if (!empty($body['is_mock'])) {
    app_mobile_json_response(null, 422, 'Saxta GPS mövqeyi ilə davamiyyət qeydə alına bilməz.');
}
$accuracy = isset($body['accuracy']) ? (float) $body['accuracy'] : 9999.0;
if ($accuracy <= 0 || $accuracy > 250) {
    app_mobile_json_response(null, 422, 'Mövqe dəqiqliyi zəifdir. Açıq sahədə GPS yenilənməsini gözləyin.');
}
if ($clientUuid === '' || strlen($clientUuid) > 64 || preg_match('/^[A-Za-z0-9._:-]+$/', $clientUuid) !== 1) {
    app_mobile_json_response(null, 422, 'client_uuid etibarsızdır.');
}

$db = app_db();
$accountId = (int) $user['id'];
if (!app_mobile_has_current_gps_consent($accountId, $db)) {
    app_mobile_json_response(['consent_required' => true], 403, 'GPS izləmə razılığı qəbul edilməyib.');
}

if ($eventType === 'check_in') {
    $home = $db->prepare('SELECT * FROM mobile_employee_home_geofences WHERE account_id=? AND is_active=1 LIMIT 1');
    $home->execute([$accountId]);
    $homeConfig = $home->fetch(PDO::FETCH_ASSOC) ?: null;
    $employeeRole = strtolower(trim((string) ($user['employee_role'] ?? '')));
    $isNightOperator = $employeeRole === 'night_operator';
    if ($isNightOperator && !$homeConfig) {
        app_mobile_json_response(null, 409, 'Gecə operatoru üçün ev iş məntəqəsi rəhbər tərəfindən qurulmayıb.');
    }
    if ($homeConfig) {
        $earth = 6371000.0;
        $dLat = deg2rad((float) $homeConfig['center_latitude'] - (float) $latitude);
        $dLng = deg2rad((float) $homeConfig['center_longitude'] - (float) $longitude);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad((float) $latitude)) * cos(deg2rad((float) $homeConfig['center_latitude']))
            * sin($dLng / 2) ** 2;
        $distance = (int) round($earth * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a))));
        $insideHome = $distance <= (int) $homeConfig['radius_meters'];
        if ($isNightOperator && !$insideHome) {
            app_mobile_json_response(null, 409, 'Gecə növbəsi yalnız rəhbərin təyin etdiyi ev iş məntəqəsindən başlana bilər.');
        }
        if (!$isNightOperator && $insideHome) {
            app_mobile_json_response(null, 409, 'İşə gəliş ev geofence-i daxilindən qəbul edilmir.');
        }
    }
}

try {
    $recordedAt = new DateTimeImmutable((string) ($body['recorded_at'] ?? 'now'));
    $recordedAt = $recordedAt->setTimezone(new DateTimeZone('Asia/Baku'));
    if ($recordedAt->getTimestamp() > time() + 300 || $recordedAt->getTimestamp() < time() - 30 * 86400) {
        throw new InvalidArgumentException('invalid_time');
    }
} catch (Throwable) {
    app_mobile_json_response(null, 422, 'Davamiyyət vaxtı etibarsızdır.');
}
app_mgs_ensure_schema($db);
$mutation = app_mobile_idempotent_mutation($clientUuid, $accountId, 'attendance_event', $body,
static function () use ($db, $user, $body, $eventType, $accountId, $clientUuid, $latitude, $longitude, $recordedAt): array {
$db->prepare('SELECT id FROM portal_accounts WHERE id=? FOR UPDATE')->execute([$accountId]);
$result = app_mgs_record_hr_attendance_event([
    'employee_account_id' => $accountId,
    'event_type' => $eventType,
    'work_date' => $recordedAt->format('Y-m-d'),
    'event_at' => $recordedAt->format('Y-m-d H:i:s'),
    'source' => 'mobile_gps',
    'note' => trim((string) ($body['note'] ?? 'Mobil tətbiqdən GPS ilə qeyd edildi')),
], $user, $db);
if (empty($result['ok'])) {
    $messages = [
        'already_checked_in' => 'Bu gün üçün gəliş artıq qeydə alınıb.',
        'check_in_required' => 'Əvvəlcə “Gəldim” düyməsini basın.',
        'already_checked_out' => 'Bu gün üçün iş bitməsi artıq qeydə alınıb.',
    ];
    $reason = (string) ($result['error'] ?? 'attendance_save_failed');
    return ['status_code' => 409, 'data' => ['reason' => $reason, 'error' => $messages[$reason] ?? 'Davamiyyət qeydi saxlanmadı.']];
}

    if ($eventType === 'check_in') {
        $db->prepare("UPDATE mobile_duty_sessions SET ended_at=NOW(), ended_reason='replaced' WHERE account_id=? AND ended_at IS NULL")
            ->execute([$accountId]);
        $db->prepare('INSERT INTO mobile_duty_sessions (account_id, device_uuid, created_ip) VALUES (?, ?, ?)')
            ->execute([$accountId, (string) ($user['authenticated_device_uuid'] ?? ''), app_client_ip()]);
    } else {
        $db->prepare("UPDATE mobile_duty_sessions SET ended_at=NOW(), ended_reason='employee_checkout' WHERE account_id=? AND ended_at IS NULL")
            ->execute([$accountId]);
    }
    $db->prepare(
        'INSERT INTO employee_live_locations
         (account_id,client_uuid,latitude,longitude,accuracy,battery_level,is_mock,is_on_duty,activity_type,recorded_at)
         VALUES (?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE client_uuid=VALUES(client_uuid)'
    )->execute([
        $accountId, $clientUuid, $latitude, $longitude,
        isset($body['accuracy']) ? (float) $body['accuracy'] : null,
        isset($body['battery_level']) ? (int) $body['battery_level'] : null,
        !empty($body['is_mock']) ? 1 : 0,
        $eventType === 'check_in' ? 1 : 0,
        $eventType,
        $recordedAt->format('Y-m-d H:i:s'),
    ]);

return ['status_code' => 200, 'data' => [
    'event_type' => $eventType,
    'is_on_duty' => $eventType === 'check_in',
    'record' => $result['record'] ?? [],
]];
});
app_mobile_json_response($mutation['data'], $mutation['status_code'],
    $mutation['status_code'] >= 400 ? ($mutation['data']['error'] ?? 'Davamiyyət qəbul edilmədi.') : null);
