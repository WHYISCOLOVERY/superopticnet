<?php
declare(strict_types=1);

require dirname(__DIR__) . '/billing-module-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

// NOTE: PHP does not run try{}finally{} blocks after exit()/die() inside the
// try body -- every failure path below throws instead of exiting directly.
class BillingBulkDeactivationSmokeFailure extends RuntimeException
{
}

function billing_bulk_deactivation_smoke_fail(string $message, int $code = 1): never
{
    throw new BillingBulkDeactivationSmokeFailure($message, $code);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$run = date('His') . random_int(100, 999);
$accountIds = [];
$requestIds = [];
$requesterActor = ['id' => 501301, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'full_name' => 'Bulk Deactivation Smoke Requester'];
$checkerActor = ['id' => 501302, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'full_name' => 'Bulk Deactivation Smoke Checker'];

$cleanup = function () use ($db, &$accountIds, &$requestIds): void {
    foreach ($requestIds as $id) {
        $db->prepare('DELETE FROM billing_bulk_deactivation_requests WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($accountIds as $id) {
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $id]);
    }
};

try {
    for ($i = 0; $i < 3; $i++) {
        $saved = app_db_upsert_account([
            'login' => 'smoke-bulkdeact-' . strtolower($run) . '-' . $i . '@qa.local',
            'password' => 'SmokePass123',
            'role' => 'customer',
            'account_type' => 'customer',
            'full_name' => 'Bulk Deactivation Smoke Customer ' . $i,
            'subscriber_code' => 'BULKDEACT-' . strtoupper($run) . '-' . $i,
            'area' => 'Smoke',
            'status' => 'Aktiv',
            'is_active' => 1,
        ], false);
        if (!is_array($saved)) {
            billing_bulk_deactivation_smoke_fail('customer_seed_failed_' . $i);
        }
        $accountIds[] = (int) $saved['id'];
    }
    if (count($accountIds) !== 3) {
        billing_bulk_deactivation_smoke_fail('customer_seed_count_wrong');
    }

    // 1. Reason is mandatory.
    $noReason = app_billing_request_bulk_deactivation($db, $accountIds, '', $requesterActor);
    if (!empty($noReason['ok']) || ($noReason['error'] ?? '') !== 'reason_required') {
        billing_bulk_deactivation_smoke_fail('missing_reason_was_not_blocked');
    }

    // 2. Empty selection is rejected.
    $noAccounts = app_billing_request_bulk_deactivation($db, [], 'smoke: nothing selected', $requesterActor);
    if (!empty($noAccounts['ok']) || ($noAccounts['error'] ?? '') !== 'no_accounts_selected') {
        billing_bulk_deactivation_smoke_fail('empty_selection_was_not_blocked');
    }

    // 3. Too many accounts is rejected (cap check via a synthetic oversized list).
    $tooMany = app_billing_request_bulk_deactivation($db, range(900000, 900000 + APP_BILLING_BULK_DEACTIVATION_MAX_ACCOUNTS), 'smoke: too many', $requesterActor);
    if (!empty($tooMany['ok']) || ($tooMany['error'] ?? '') !== 'too_many_accounts') {
        billing_bulk_deactivation_smoke_fail('oversized_selection_was_not_blocked');
    }

    // 4. Staging a real request must NOT touch any account yet.
    $requested = app_billing_request_bulk_deactivation($db, $accountIds, 'Smoke: closing test segment', $requesterActor);
    if (empty($requested['ok']) || (int) ($requested['account_count'] ?? 0) !== 3) {
        billing_bulk_deactivation_smoke_fail('bulk_request_create_failed:' . json_encode($requested, JSON_UNESCAPED_SLASHES));
    }
    $requestId = (int) $requested['id'];
    $requestIds[] = $requestId;

    foreach ($accountIds as $id) {
        $row = app_db_account_by_id($id);
        if (!is_array($row) || (string) ($row['status'] ?? '') !== 'Aktiv' || (int) ($row['is_active'] ?? 0) !== 1) {
            billing_bulk_deactivation_smoke_fail('account_changed_before_approval:' . $id);
        }
    }

    // 5. A non-admin cannot resolve.
    $nonAdminActor = ['id' => 501303, 'role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'support', 'full_name' => 'Non-Admin'];
    $nonAdminResolve = app_billing_resolve_bulk_deactivation($db, $requestId, 'approved', 'smoke', $nonAdminActor);
    if (!empty($nonAdminResolve['ok']) || ($nonAdminResolve['error'] ?? '') !== 'permission_denied') {
        billing_bulk_deactivation_smoke_fail('non_admin_was_able_to_resolve');
    }

    // 6. The requester cannot approve their own bulk request.
    $selfApprove = app_billing_resolve_bulk_deactivation($db, $requestId, 'approved', 'smoke self-approve', $requesterActor);
    if (!empty($selfApprove['ok']) || ($selfApprove['error'] ?? '') !== 'requester_cannot_approve_own_request') {
        billing_bulk_deactivation_smoke_fail('requester_was_able_to_approve_own_request');
    }

    // 7. A DIFFERENT admin approves -> all 3 accounts actually flip to Deaktiv.
    $checkerApprove = app_billing_resolve_bulk_deactivation($db, $requestId, 'approved', 'smoke checker approval', $checkerActor);
    if (empty($checkerApprove['ok']) || (int) ($checkerApprove['result']['deactivated'] ?? 0) !== 3) {
        billing_bulk_deactivation_smoke_fail('checker_admin_could_not_approve:' . json_encode($checkerApprove, JSON_UNESCAPED_SLASHES));
    }
    foreach ($accountIds as $id) {
        $row = app_db_account_by_id($id);
        if (!is_array($row) || (string) ($row['status'] ?? '') !== 'Deaktiv' || (int) ($row['is_active'] ?? 1) !== 0) {
            billing_bulk_deactivation_smoke_fail('account_not_deactivated_after_approval:' . $id);
        }
    }

    // 8. Approving an already-resolved request again must fail cleanly.
    $reapprove = app_billing_resolve_bulk_deactivation($db, $requestId, 'approved', 'smoke re-approve', $checkerActor);
    if (!empty($reapprove['ok']) || ($reapprove['error'] ?? '') !== 'request_already_resolved') {
        billing_bulk_deactivation_smoke_fail('already_resolved_request_was_resolved_again');
    }

    // 9. Rejection path on a fresh set of accounts must leave them untouched.
    $secondBatchIds = [];
    for ($i = 0; $i < 2; $i++) {
        $saved = app_db_upsert_account([
            'login' => 'smoke-bulkdeact-reject-' . strtolower($run) . '-' . $i . '@qa.local',
            'password' => 'SmokePass123',
            'role' => 'customer',
            'account_type' => 'customer',
            'full_name' => 'Bulk Deactivation Reject Smoke ' . $i,
            'subscriber_code' => 'BULKDEACT-REJ-' . strtoupper($run) . '-' . $i,
            'area' => 'Smoke',
            'status' => 'Aktiv',
            'is_active' => 1,
        ], false);
        $secondBatchIds[] = (int) $saved['id'];
        $accountIds[] = (int) $saved['id'];
    }
    $rejectRequested = app_billing_request_bulk_deactivation($db, $secondBatchIds, 'Smoke: will be rejected', $requesterActor);
    if (empty($rejectRequested['ok'])) {
        billing_bulk_deactivation_smoke_fail('reject_flow_request_create_failed');
    }
    $requestIds[] = (int) $rejectRequested['id'];
    $rejected = app_billing_resolve_bulk_deactivation($db, (int) $rejectRequested['id'], 'rejected', 'smoke: not approved', $checkerActor);
    if (empty($rejected['ok']) || ($rejected['status'] ?? '') !== 'rejected' || (int) ($rejected['result']['deactivated'] ?? -1) !== 0) {
        billing_bulk_deactivation_smoke_fail('rejection_incorrectly_applied_or_failed:' . json_encode($rejected, JSON_UNESCAPED_SLASHES));
    }
    foreach ($secondBatchIds as $id) {
        $row = app_db_account_by_id($id);
        if (!is_array($row) || (string) ($row['status'] ?? '') !== 'Aktiv') {
            billing_bulk_deactivation_smoke_fail('rejected_request_changed_account_anyway:' . $id);
        }
    }

    // 10. Listing helper no longer shows resolved requests as pending.
    $stillPending = app_billing_bulk_deactivation_requests($db, 'pending');
    foreach ($stillPending as $row) {
        if (in_array((int) ($row['id'] ?? 0), $requestIds, true)) {
            billing_bulk_deactivation_smoke_fail('resolved_request_still_listed_as_pending');
        }
    }

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'account_count' => count($accountIds),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit($exception->getCode() ?: 1);
}
