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
class HrScoreLeaveFairnessSmokeFailure extends RuntimeException
{
}

function hr_score_leave_fairness_smoke_fail(string $message, int $code = 1): never
{
    throw new HrScoreLeaveFairnessSmokeFailure($message, $code);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$stamp = date('His') . random_int(100, 999);
$employeeId = 0;
$leaveRequestId = 0;
$scoreEventIds = [];
$actor = ['id' => 0, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'full_name' => 'Leave Fairness Smoke'];

$cleanup = function () use ($db, &$employeeId, &$leaveRequestId, &$scoreEventIds): void {
    foreach ($scoreEventIds as $id) {
        $db->prepare('DELETE FROM hr_score_events WHERE id = :id')->execute(['id' => $id]);
    }
    if ($leaveRequestId > 0) {
        $db->prepare('DELETE FROM hr_leave_requests WHERE id = :id')->execute(['id' => $leaveRequestId]);
    }
    if ($employeeId > 0) {
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $employeeId]);
    }
};

try {
    $accountSaved = app_db_upsert_account([
        'login' => 'smoke-leave-fair-' . strtolower($stamp),
        'password' => 'SmokePass123',
        'role' => 'employee',
        'account_type' => 'operator',
        'employee_role' => 'technician',
        'full_name' => 'Leave Fairness Smoke Employee',
        'is_active' => 1,
    ], false);
    if (!is_array($accountSaved)) {
        hr_score_leave_fairness_smoke_fail('employee_seed_failed');
    }
    $employeeId = (int) ($accountSaved['id'] ?? 0);
    if ($employeeId <= 0) {
        hr_score_leave_fairness_smoke_fail('employee_id_missing');
    }

    $onLeaveDate = date('Y-m-d', strtotime('+3 days'));
    $notOnLeaveDate = date('Y-m-d', strtotime('+30 days'));

    $db->prepare(
        'INSERT INTO hr_leave_requests (employee_account_id, employee_name, period_month, leave_type, start_date, end_date, leave_days, status)
         VALUES (:employee_id, :employee_name, :period_month, "annual", :start_date, :end_date, 5, "approved")'
    )->execute([
        'employee_id' => $employeeId,
        'employee_name' => 'Leave Fairness Smoke Employee',
        'period_month' => date('Y-m-01', strtotime($onLeaveDate)),
        'start_date' => date('Y-m-d', strtotime($onLeaveDate . ' -2 days')),
        'end_date' => date('Y-m-d', strtotime($onLeaveDate . ' +2 days')),
    ]);
    $leaveRequestId = (int) $db->lastInsertId();
    if ($leaveRequestId <= 0) {
        hr_score_leave_fairness_smoke_fail('leave_request_seed_failed');
    }

    // 1. A penalty dated INSIDE the approved leave window must be refused.
    $blocked = app_mgs_record_hr_score_event([
        'employee_account_id' => $employeeId,
        'event_type' => 'penalty',
        'reason_code' => 'absence',
        'points_delta' => -5,
        'event_date' => $onLeaveDate,
        'reason' => 'smoke: should be blocked',
    ], $actor, $db);
    if (!empty($blocked['ok']) || ($blocked['error'] ?? '') !== 'penalty_blocked_by_approved_leave') {
        hr_score_leave_fairness_smoke_fail('penalty_during_leave_was_not_blocked:' . json_encode($blocked, JSON_UNESCAPED_SLASHES));
    }
    if ((int) ($blocked['leave_request_id'] ?? 0) !== $leaveRequestId) {
        hr_score_leave_fairness_smoke_fail('blocked_response_did_not_reference_correct_leave_request');
    }

    // 2. A penalty OUTSIDE the leave window must succeed normally (the rule
    //    must not become a blanket block on all penalties for this employee).
    $allowed = app_mgs_record_hr_score_event([
        'employee_account_id' => $employeeId,
        'event_type' => 'penalty',
        'reason_code' => 'absence',
        'points_delta' => -5,
        'event_date' => $notOnLeaveDate,
        'reason' => 'smoke: should succeed, outside leave window',
    ], $actor, $db);
    if (empty($allowed['ok'])) {
        hr_score_leave_fairness_smoke_fail('penalty_outside_leave_was_incorrectly_blocked:' . json_encode($allowed, JSON_UNESCAPED_SLASHES));
    }
    $scoreEventIds[] = (int) ($allowed['id'] ?? 0);

    // 3. A REWARD dated inside the leave window must NOT be blocked -- the
    //    rule only protects against penalties, never rewards/resets.
    $rewardDuringLeave = app_mgs_record_hr_score_event([
        'employee_account_id' => $employeeId,
        'event_type' => 'reward',
        'reason_code' => 'bonus',
        'points_delta' => 3,
        'event_date' => $onLeaveDate,
    ], $actor, $db);
    if (empty($rewardDuringLeave['ok'])) {
        hr_score_leave_fairness_smoke_fail('reward_during_leave_was_incorrectly_blocked:' . json_encode($rewardDuringLeave, JSON_UNESCAPED_SLASHES));
    }
    $scoreEventIds[] = (int) ($rewardDuringLeave['id'] ?? 0);

    // 4. A DRAFT (not yet approved) leave request must NOT block a penalty --
    //    only approved/paid leave counts, matching what actually happened.
    $db->prepare('UPDATE hr_leave_requests SET status = "draft" WHERE id = :id')->execute(['id' => $leaveRequestId]);
    $penaltyDuringDraftLeave = app_mgs_record_hr_score_event([
        'employee_account_id' => $employeeId,
        'event_type' => 'penalty',
        'reason_code' => 'absence',
        'points_delta' => -5,
        'event_date' => $onLeaveDate,
        'reason' => 'smoke: draft leave must not block',
    ], $actor, $db);
    if (empty($penaltyDuringDraftLeave['ok'])) {
        hr_score_leave_fairness_smoke_fail('draft_leave_incorrectly_blocked_penalty:' . json_encode($penaltyDuringDraftLeave, JSON_UNESCAPED_SLASHES));
    }
    $scoreEventIds[] = (int) ($penaltyDuringDraftLeave['id'] ?? 0);

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'employee_id' => $employeeId,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit($exception->getCode() ?: 1);
}
