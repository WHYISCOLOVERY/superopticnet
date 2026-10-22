<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_mobile_json_response(null, 405, 'Yalnız POST sorğuları dəstəklənir.');
}

$user = app_mobile_authenticate();
$body = app_mobile_request_body();

$targetAccountId = (int) ($body['target_account_id'] ?? $user['id']);
$targetDeviceUuid = trim((string) ($body['device_uuid'] ?? ''));
$reason = trim((string) ($body['reason'] ?? 'İstifadəçi və ya rəhbər tərəfindən uzaqdan ləğv edildi (Remote Wipe).'));

// Authorization check: only admin/manager can revoke someone else's device
if ($targetAccountId !== (int) $user['id']) {
    app_mobile_require_role($user, ['admin', 'manager', 'super_admin', 'director', 'team_lead']);
}

if ($targetDeviceUuid === '') {
    app_mobile_json_response(null, 422, 'device_uuid tələb olunur.');
}

$db = app_db();
if ($targetAccountId !== (int) $user['id']) {
    $isGlobalManager = in_array((string) ($user['role'] ?? ''), ['admin', 'super_admin', 'director'], true)
        || in_array((string) ($user['employee_role'] ?? ''), ['admin', 'super_admin', 'director'], true);
    if (!$isGlobalManager) {
        $managerAreas = app_db_account_areas((int) $user['id']);
        if ($managerAreas === [] && trim((string) ($user['area'] ?? '')) !== '') {
            $managerAreas[] = trim((string) $user['area']);
        }
        $targetAreas = app_db_account_areas($targetAccountId);
        $stmtTarget = $db->prepare('SELECT area FROM portal_accounts WHERE id = ? LIMIT 1');
        $stmtTarget->execute([$targetAccountId]);
        $targetPrimaryArea = trim((string) ($stmtTarget->fetchColumn() ?: ''));
        if ($targetPrimaryArea !== '') {
            $targetAreas[] = $targetPrimaryArea;
        }
        if ($managerAreas === [] || array_intersect($managerAreas, array_unique($targetAreas)) === []) {
            app_mobile_json_response(null, 403, 'Bu cihaz sizin idarə etdiyiniz əraziyə aid deyil.');
        }
    }
}

$db->beginTransaction();
try {
    $stmt = $db->prepare(
        "UPDATE mobile_device_tokens
         SET is_active = 0, revoked_at = NOW(), revoked_reason = ?
         WHERE account_id = ? AND device_uuid = ? AND is_active = 1"
    );
    $stmt->execute([$reason, $targetAccountId, $targetDeviceUuid]);
    if ($stmt->rowCount() !== 1) {
        $db->rollBack();
        app_mobile_json_response(null, 404, 'Aktiv cihaz qeydi tapılmadı.');
    }
    $db->prepare(
        'DELETE FROM mobile_auth_refresh_tokens WHERE account_id = ? AND device_uuid = ?'
    )->execute([$targetAccountId, $targetDeviceUuid]);
    $db->prepare(
        "UPDATE mobile_duty_sessions SET ended_at = NOW(), ended_reason = 'device_revoked'
         WHERE account_id = ? AND device_uuid = ? AND ended_at IS NULL"
    )->execute([$targetAccountId, $targetDeviceUuid]);
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}

app_mobile_json_response([
    'revoked' => true,
    'remote_wipe_command_staged' => false,
    'account_id' => $targetAccountId,
    'device_uuid' => $targetDeviceUuid,
    'reason' => $reason,
], 200);
