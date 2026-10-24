<?php
declare(strict_types=1);

require dirname(__DIR__) . '/admin-privilege-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

// NOTE: PHP does not run try{}finally{} blocks after exit()/die() inside the
// try body -- every failure path below throws instead of exiting directly.
class AdminPrivilegeEscalationSmokeFailure extends RuntimeException
{
}

function admin_privilege_escalation_smoke_fail(string $message, int $code = 1): never
{
    throw new AdminPrivilegeEscalationSmokeFailure($message, $code);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$run = date('His') . random_int(100, 999);
$targetAccountId = 0;
$requesterAccountId = 501101;
$checkerAdminId = 501102;
$requestIds = [];

$requesterActor = ['id' => $requesterAccountId, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'full_name' => 'Privilege Escalation Smoke Requester'];
$checkerActor = ['id' => $checkerAdminId, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'full_name' => 'Privilege Escalation Smoke Checker'];

$cleanup = function () use ($db, &$targetAccountId, &$requestIds): void {
    foreach ($requestIds as $id) {
        $db->prepare('DELETE FROM admin_privilege_change_requests WHERE id = :id')->execute(['id' => $id]);
    }
    if ($targetAccountId > 0) {
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $targetAccountId]);
    }
};

try {
    // 1. Escalation-detection pure logic (no DB needed).
    $baseline = ['account_type' => 'operator', 'employee_role' => 'technician', 'is_active' => 1, 'module_keys' => ['inventory']];

    if (!app_admin_privilege_change_is_escalation($baseline, ['account_type' => 'admin', 'employee_role' => 'admin', 'is_active' => 1, 'module_keys' => ['inventory']])) {
        admin_privilege_escalation_smoke_fail('admin_grant_not_detected_as_escalation');
    }
    if (app_admin_privilege_change_is_escalation(['account_type' => 'admin', 'employee_role' => 'admin', 'is_active' => 1, 'module_keys' => []], ['account_type' => 'operator', 'employee_role' => 'technician', 'is_active' => 1, 'module_keys' => []])) {
        admin_privilege_escalation_smoke_fail('admin_removal_incorrectly_flagged_as_escalation');
    }
    if (!app_admin_privilege_change_is_escalation(['account_type' => 'operator', 'employee_role' => 'technician', 'is_active' => 0, 'module_keys' => []], ['account_type' => 'operator', 'employee_role' => 'technician', 'is_active' => 1, 'module_keys' => []])) {
        admin_privilege_escalation_smoke_fail('reactivation_not_detected_as_escalation');
    }
    if (app_admin_privilege_change_is_escalation(['account_type' => 'operator', 'employee_role' => 'technician', 'is_active' => 1, 'module_keys' => []], ['account_type' => 'operator', 'employee_role' => 'technician', 'is_active' => 0, 'module_keys' => []])) {
        admin_privilege_escalation_smoke_fail('deactivation_incorrectly_flagged_as_escalation');
    }
    if (!app_admin_privilege_change_is_escalation($baseline, ['account_type' => 'operator', 'employee_role' => 'technician', 'is_active' => 1, 'module_keys' => ['inventory', 'finance']])) {
        admin_privilege_escalation_smoke_fail('new_module_not_detected_as_escalation');
    }
    if (app_admin_privilege_change_is_escalation($baseline, ['account_type' => 'operator', 'employee_role' => 'technician', 'is_active' => 1, 'module_keys' => []])) {
        admin_privilege_escalation_smoke_fail('module_removal_incorrectly_flagged_as_escalation');
    }
    if (app_admin_privilege_change_is_escalation($baseline, $baseline)) {
        admin_privilege_escalation_smoke_fail('no_change_incorrectly_flagged_as_escalation');
    }

    // 2. End-to-end: create a real employee account, stage an escalation to
    //    admin, verify the account is NOT changed until a different admin
    //    approves.
    $accountSaved = app_db_upsert_account([
        'login' => 'smoke-privesc-' . strtolower($run) . '@qa.local',
        'password' => 'SmokePass123',
        'role' => 'employee',
        'account_type' => 'operator',
        'employee_role' => 'technician',
        'full_name' => 'Privilege Escalation Smoke Target',
        'is_active' => 1,
    ], false);
    if (!is_array($accountSaved)) {
        admin_privilege_escalation_smoke_fail('target_account_seed_failed');
    }
    $targetAccountId = (int) ($accountSaved['id'] ?? 0);
    if ($targetAccountId <= 0) {
        admin_privilege_escalation_smoke_fail('target_account_id_missing');
    }

    $previousPrivileges = ['account_type' => 'operator', 'employee_role' => 'technician', 'is_active' => 1, 'module_keys' => []];
    $proposedPrivileges = ['account_type' => 'admin', 'employee_role' => 'admin', 'is_active' => 1, 'module_keys' => []];
    $upsertPayload = [
        'id' => $targetAccountId,
        'login' => (string) $accountSaved['login'],
        'password' => '',
        'role' => 'employee',
        'account_type' => 'admin',
        'employee_role' => 'admin',
        'full_name' => 'Privilege Escalation Smoke Target',
        'is_active' => 1,
    ];

    // 2a. Reason is mandatory.
    $noReason = app_admin_privilege_change_request_create($db, $upsertPayload, $previousPrivileges, $proposedPrivileges, '', $requesterActor);
    if (!empty($noReason['ok']) || ($noReason['error'] ?? '') !== 'reason_required') {
        admin_privilege_escalation_smoke_fail('missing_reason_was_not_blocked');
    }

    $created = app_admin_privilege_change_request_create($db, $upsertPayload, $previousPrivileges, $proposedPrivileges, 'Smoke: promoting to admin', $requesterActor);
    if (empty($created['ok']) || (int) ($created['id'] ?? 0) <= 0) {
        admin_privilege_escalation_smoke_fail('escalation_request_create_failed:' . json_encode($created, JSON_UNESCAPED_SLASHES));
    }
    $requestId = (int) $created['id'];
    $requestIds[] = $requestId;

    // 2b. The account must be UNCHANGED right after staging -- the request
    //     alone must never touch portal_accounts.
    $stillTechnician = app_db_account_by_id($targetAccountId);
    if (!is_array($stillTechnician) || (string) ($stillTechnician['account_type'] ?? '') !== 'operator' || (string) ($stillTechnician['employee_role'] ?? '') !== 'technician') {
        admin_privilege_escalation_smoke_fail('account_was_changed_before_approval');
    }

    // 2c. A non-admin cannot resolve at all.
    $nonAdminActor = ['id' => 501103, 'role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'technician', 'full_name' => 'Non-Admin'];
    $nonAdminResolve = app_admin_privilege_change_request_resolve($db, $requestId, 'approved', 'smoke', $nonAdminActor);
    if (!empty($nonAdminResolve['ok']) || ($nonAdminResolve['error'] ?? '') !== 'permission_denied') {
        admin_privilege_escalation_smoke_fail('non_admin_was_able_to_resolve');
    }

    // 2d. The requester cannot approve their own request, even as an admin.
    $selfApprove = app_admin_privilege_change_request_resolve($db, $requestId, 'approved', 'smoke self-approve', $requesterActor);
    if (!empty($selfApprove['ok']) || ($selfApprove['error'] ?? '') !== 'requester_cannot_approve_own_request') {
        admin_privilege_escalation_smoke_fail('requester_was_able_to_approve_own_request');
    }

    // 2e. A DIFFERENT admin approves -> the account actually becomes admin.
    $checkerApprove = app_admin_privilege_change_request_resolve($db, $requestId, 'approved', 'smoke checker approval', $checkerActor);
    if (empty($checkerApprove['ok']) || (int) ($checkerApprove['account_id'] ?? 0) !== $targetAccountId) {
        admin_privilege_escalation_smoke_fail('checker_admin_could_not_approve:' . json_encode($checkerApprove, JSON_UNESCAPED_SLASHES));
    }
    $nowAdmin = app_db_account_by_id($targetAccountId);
    if (!is_array($nowAdmin) || (string) ($nowAdmin['account_type'] ?? '') !== 'admin' || (string) ($nowAdmin['employee_role'] ?? '') !== 'admin') {
        admin_privilege_escalation_smoke_fail('approved_escalation_did_not_apply_to_account');
    }

    // 2f. Approving an already-resolved request again must fail cleanly.
    $reapprove = app_admin_privilege_change_request_resolve($db, $requestId, 'approved', 'smoke re-approve', $checkerActor);
    if (!empty($reapprove['ok']) || ($reapprove['error'] ?? '') !== 'request_already_resolved') {
        admin_privilege_escalation_smoke_fail('already_resolved_request_was_resolved_again');
    }

    // 3. Rejection path: a second escalation request, rejected, must leave
    //    the account exactly as it already is (no privilege change applied).
    $secondCreated = app_admin_privilege_change_request_create(
        $db,
        array_merge($upsertPayload, ['employee_role' => 'super_admin']),
        ['account_type' => 'admin', 'employee_role' => 'admin', 'is_active' => 1, 'module_keys' => []],
        ['account_type' => 'admin', 'employee_role' => 'super_admin', 'is_active' => 1, 'module_keys' => []],
        'Smoke: attempting super_admin-tier escalation',
        $requesterActor
    );
    if (empty($secondCreated['ok'])) {
        admin_privilege_escalation_smoke_fail('second_escalation_request_create_failed');
    }
    $requestIds[] = (int) $secondCreated['id'];
    $rejected = app_admin_privilege_change_request_resolve($db, (int) $secondCreated['id'], 'rejected', 'smoke: not approved', $checkerActor);
    if (empty($rejected['ok']) || ($rejected['status'] ?? '') !== 'rejected' || !empty($rejected['account_id'])) {
        admin_privilege_escalation_smoke_fail('rejection_incorrectly_applied_or_failed:' . json_encode($rejected, JSON_UNESCAPED_SLASHES));
    }
    $stillJustAdmin = app_db_account_by_id($targetAccountId);
    if (!is_array($stillJustAdmin) || (string) ($stillJustAdmin['employee_role'] ?? '') !== 'admin') {
        admin_privilege_escalation_smoke_fail('rejected_request_changed_account_anyway');
    }

    // 4. Listing helper returns both requests (one approved, one rejected --
    //    neither shows up when asking for status=pending anymore).
    $stillPending = app_admin_privilege_change_requests($db, 'pending');
    foreach ($stillPending as $row) {
        if (in_array((int) ($row['id'] ?? 0), $requestIds, true)) {
            admin_privilege_escalation_smoke_fail('resolved_request_still_listed_as_pending');
        }
    }

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'target_account_id' => $targetAccountId,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit($exception->getCode() ?: 1);
}
