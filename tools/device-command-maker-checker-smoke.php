<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

// NOTE: PHP does not run try{}finally{} blocks after exit()/die() inside the
// try body -- every failure path below throws instead of exiting directly.
class DeviceCommandMakerCheckerSmokeFailure extends RuntimeException
{
}

function device_command_maker_checker_smoke_fail(string $message, int $code = 1): never
{
    throw new DeviceCommandMakerCheckerSmokeFailure($message, $code);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$run = date('His') . random_int(100, 999);
$deviceKey = 'smoke-device-command-' . $run;
$requestIds = [];
$requesterActor = ['id' => 501401, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'full_name' => 'Device Command Smoke Requester'];
$checkerActor = ['id' => 501402, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'full_name' => 'Device Command Smoke Checker'];

$cleanup = function () use ($db, $deviceKey, &$requestIds): void {
    foreach ($requestIds as $id) {
        $db->prepare('DELETE FROM mgs_device_command_requests WHERE id = :id')->execute(['id' => $id]);
    }
    $db->prepare('DELETE FROM device_access_overrides WHERE device_key = :device_key')->execute(['device_key' => $deviceKey]);
    $db->prepare('DELETE FROM device_learning_profiles WHERE device_key = :device_key')->execute(['device_key' => $deviceKey]);
};

try {
    // A synthetic device registered like a real one, but pointed at TEST-NET-1
    // (192.0.2.0/24, RFC 5737) -- guaranteed non-routable, so even if the
    // maker-checker gate below were broken, no real network hardware could
    // ever be reached by this test.
    $db->prepare(
        "INSERT INTO device_learning_profiles (device_key, device_name, ip_address, device_type, vendor, management_network, status)
         VALUES (:device_key, :device_name, '192.0.2.55', 'olt', 'HSGQ', 'public', 'learned')"
    )->execute(['device_key' => $deviceKey, 'device_name' => 'Smoke Fake Device ' . $run]);
    $overrideResult = app_mgs_set_device_access_override($deviceKey, [
        'ssh_username' => 'smoke-user',
        'ssh_password' => 'smoke-password-' . $run,
        'ssh_port' => 22,
        'reason' => 'Smoke test dummy credentials (TEST-NET-1, never a real device)',
    ], $requesterActor, $db);
    if (empty($overrideResult['ok'])) {
        device_command_maker_checker_smoke_fail('access_override_setup_failed:' . json_encode($overrideResult, JSON_UNESCAPED_SLASHES));
    }
    $fakeDevice = app_mgs_device_learning_profile_by_key($deviceKey, $db, $requesterActor);
    if (!is_array($fakeDevice)) {
        device_command_maker_checker_smoke_fail('fake_device_lookup_failed');
    }

    // 1. A non-dangerous command must NOT be staged -- it attempts the normal
    //    execution path directly (a real, bounded-timeout SSH attempt against
    //    a non-routable address, which fails, but the failure reason proves
    //    the maker-checker gate was never involved).
    $readResult = app_mgs_execute_device_command_plan($fakeDevice, app_mgs_command_list_from_text('display version'), $requesterActor, $db, [
        'action_type' => 'smoke_device_command',
        'timeout_ms' => 2000,
    ]);
    if ((string) ($readResult['reason'] ?? '') === 'dangerous_command_staged_for_approval') {
        device_command_maker_checker_smoke_fail('non_dangerous_command_was_incorrectly_staged');
    }

    // 2. A dangerous command WITHOUT allow_dangerous must be refused exactly
    //    as before this change (pre-existing behavior, unchanged) -- and,
    //    critically, must return WITHOUT ever attempting a connection.
    $noAllow = app_mgs_execute_device_command_plan($fakeDevice, app_mgs_command_list_from_text('reboot'), $requesterActor, $db, [
        'action_type' => 'smoke_device_command',
    ]);
    if (!empty($noAllow['ok']) || ($noAllow['reason'] ?? '') !== 'dangerous_command_requires_explicit_apply') {
        device_command_maker_checker_smoke_fail('dangerous_without_allow_flag_was_not_blocked:' . json_encode($noAllow, JSON_UNESCAPED_SLASHES));
    }

    // 3. Dangerous + allow_dangerous but no reason must be refused, also
    //    without attempting a connection.
    $noReason = app_mgs_execute_device_command_plan($fakeDevice, app_mgs_command_list_from_text('reboot'), $requesterActor, $db, [
        'allow_dangerous' => true,
        'action_type' => 'smoke_device_command',
    ]);
    if (!empty($noReason['ok']) || ($noReason['reason'] ?? '') !== 'reason_required') {
        device_command_maker_checker_smoke_fail('staging_without_reason_was_not_blocked:' . json_encode($noReason, JSON_UNESCAPED_SLASHES));
    }

    // 4. Dangerous + allow_dangerous + reason must STAGE, not execute --
    //    the response must come back as a stage record, never touching SSH.
    $staged = app_mgs_execute_device_command_plan($fakeDevice, app_mgs_command_list_from_text("reboot\ndisplay version"), $requesterActor, $db, [
        'allow_dangerous' => true,
        'action_type' => 'smoke_device_command',
        'reason' => 'Smoke: scheduled maintenance reboot',
    ]);
    if (!empty($staged['ok']) || (string) ($staged['reason'] ?? '') !== 'dangerous_command_staged_for_approval' || (int) ($staged['request_id'] ?? 0) <= 0) {
        device_command_maker_checker_smoke_fail('dangerous_command_was_not_staged:' . json_encode($staged, JSON_UNESCAPED_SLASHES));
    }
    $requestId = (int) $staged['request_id'];
    $requestIds[] = $requestId;

    $pendingRow = $db->prepare('SELECT status, device_key, reason FROM mgs_device_command_requests WHERE id = :id');
    $pendingRow->execute(['id' => $requestId]);
    $pendingData = $pendingRow->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((string) ($pendingData['status'] ?? '') !== 'pending' || (string) ($pendingData['device_key'] ?? '') !== $deviceKey) {
        device_command_maker_checker_smoke_fail('staged_request_row_wrong');
    }

    // 4b. A CONSTRUCTIVE config command (VLAN create/bind -- no "delete"/
    //     "shutdown"/etc. keyword at all) with allow_dangerous=true must ALSO
    //     stage, not execute immediately. This is the exact vlan_push /
    //     ONT-provisioning gap the vision doc worries about: config pushes
    //     that break customer service don't need a "dangerous" keyword to be
    //     dangerous.
    $vlanStaged = app_mgs_execute_device_command_plan($fakeDevice, app_mgs_command_list_from_text('vlan 100 smart'), $requesterActor, $db, [
        'allow_dangerous' => true,
        'action_type' => 'smoke_vlan_push',
        'reason' => 'Smoke: VLAN provisioning for new customer segment',
    ]);
    if (!empty($vlanStaged['ok']) || (string) ($vlanStaged['reason'] ?? '') !== 'dangerous_command_staged_for_approval') {
        device_command_maker_checker_smoke_fail('constructive_config_command_was_not_staged:' . json_encode($vlanStaged, JSON_UNESCAPED_SLASHES));
    }
    $requestIds[] = (int) $vlanStaged['request_id'];

    // 5. A non-admin cannot resolve.
    $nonAdminActor = ['id' => 501403, 'role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'support', 'full_name' => 'Non-Admin'];
    $nonAdminResolve = app_mgs_resolve_device_command_request($db, $requestId, 'approved', 'smoke', $nonAdminActor);
    if (!empty($nonAdminResolve['ok']) || ($nonAdminResolve['error'] ?? '') !== 'permission_denied') {
        device_command_maker_checker_smoke_fail('non_admin_was_able_to_resolve');
    }

    // 6. The requester cannot approve their own device command request.
    $selfApprove = app_mgs_resolve_device_command_request($db, $requestId, 'approved', 'smoke self-approve', $requesterActor);
    if (!empty($selfApprove['ok']) || ($selfApprove['error'] ?? '') !== 'requester_cannot_approve_own_request') {
        device_command_maker_checker_smoke_fail('requester_was_able_to_approve_own_request');
    }

    // 7. A DIFFERENT admin approving triggers a REAL execution attempt --
    //    which fails (non-routable synthetic address), so the request must
    //    stay pending, retryable once real connectivity/credentials exist.
    $checkerApprove = app_mgs_resolve_device_command_request($db, $requestId, 'approved', 'smoke checker approval attempt', $checkerActor);
    if (!empty($checkerApprove['ok']) || ($checkerApprove['error'] ?? '') !== 'device_command_execution_failed') {
        device_command_maker_checker_smoke_fail('approval_did_not_attempt_real_execution:' . json_encode($checkerApprove, JSON_UNESCAPED_SLASHES));
    }
    $stillPendingRow = $db->prepare('SELECT status FROM mgs_device_command_requests WHERE id = :id');
    $stillPendingRow->execute(['id' => $requestId]);
    if ((string) $stillPendingRow->fetchColumn() !== 'pending') {
        device_command_maker_checker_smoke_fail('failed_execution_incorrectly_closed_the_request');
    }

    // 8. Rejecting the same request must succeed and never attempt execution.
    $rejected = app_mgs_resolve_device_command_request($db, $requestId, 'rejected', 'smoke: maintenance window cancelled', $checkerActor);
    if (empty($rejected['ok']) || ($rejected['status'] ?? '') !== 'rejected' || $rejected['exec_result'] !== null) {
        device_command_maker_checker_smoke_fail('rejection_incorrectly_executed_or_failed:' . json_encode($rejected, JSON_UNESCAPED_SLASHES));
    }

    // 9. Listing helper no longer shows the resolved request as pending.
    $stillPending = app_mgs_device_command_requests($db, 'pending');
    foreach ($stillPending as $row) {
        if ((int) ($row['id'] ?? 0) === $requestId) {
            device_command_maker_checker_smoke_fail('resolved_request_still_listed_as_pending');
        }
    }

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'request_id' => $requestId,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit($exception->getCode() ?: 1);
}
