<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_mobile_json_response(null, 405, 'Yalnız POST sorğuları dəstəklənir.');
}

$user = app_mobile_authenticate();
app_mobile_require_role($user, ['technician', 'engineer', 'team_lead', 'operator', 'support']);
$body = app_mobile_request_body();
$onDuty = filter_var($body['is_on_duty'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($onDuty === null) {
    app_mobile_json_response(null, 422, 'is_on_duty true və ya false olmalıdır.');
}

$accountId = (int) $user['id'];
$deviceUuid = (string) ($user['authenticated_device_uuid'] ?? '');
$db = app_db();
if ($onDuty && !app_mobile_has_current_gps_consent($accountId, $db)) {
    app_mobile_json_response([
        'consent_required' => true,
        'consent_version' => app_mobile_required_gps_consent_version(),
    ], 403, 'GPS izləmə razılığı qəbul edilməyib.');
}

$db->beginTransaction();
try {
    $db->prepare(
        "UPDATE mobile_duty_sessions SET ended_at = NOW(), ended_reason = 'replaced'
         WHERE account_id = ? AND ended_at IS NULL"
    )->execute([$accountId]);
    if ($onDuty) {
        $db->prepare(
            'INSERT INTO mobile_duty_sessions (account_id, device_uuid, created_ip) VALUES (?, ?, ?)'
        )->execute([$accountId, $deviceUuid, app_client_ip()]);
    }
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}

app_mobile_json_response([
    'is_on_duty' => $onDuty,
    'changed_at' => date(DATE_ATOM),
], 200);
