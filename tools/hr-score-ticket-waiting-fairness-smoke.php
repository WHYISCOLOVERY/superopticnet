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
class HrScoreTicketWaitingFairnessSmokeFailure extends RuntimeException
{
}

function hr_score_ticket_waiting_fairness_smoke_fail(string $message, int $code = 1): never
{
    throw new HrScoreTicketWaitingFairnessSmokeFailure($message, $code);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$run = date('His') . random_int(100, 999);
$employeeId = 0;
$ticketIds = [];
$scoreEventIds = [];
$adminActor = ['id' => 501601, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'is_active' => 1, 'full_name' => 'Ticket Waiting Fairness Smoke'];

$cleanup = function () use ($db, &$employeeId, &$ticketIds, &$scoreEventIds): void {
    foreach ($scoreEventIds as $id) {
        $db->prepare('DELETE FROM hr_score_events WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($ticketIds as $id) {
        $db->prepare('DELETE FROM portal_ticket_events WHERE ticket_id = :id')->execute(['id' => $id]);
        $db->prepare('DELETE FROM portal_tickets WHERE id = :id')->execute(['id' => $id]);
    }
    if ($employeeId > 0) {
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $employeeId]);
    }
};

$insertTicket = static function (string $ticketNo, int $assignedTo) use ($db, &$ticketIds): int {
    $db->prepare(
        "INSERT INTO portal_tickets (ticket_no, customer_name, subject, message, status, opened_at, updated_at, assigned_technician_account_id)
         VALUES (:ticket_no, 'Ticket Waiting Fairness Smoke Customer', 'technical', 'smoke ticket', 'Aciqdir', NOW(), NOW(), :assigned_to)"
    )->execute(['ticket_no' => $ticketNo, 'assigned_to' => $assignedTo]);
    $id = (int) $db->lastInsertId();
    $ticketIds[] = $id;
    return $id;
};

try {
    $accountSaved = app_db_upsert_account([
        'login' => 'smoke-ticket-wait-' . strtolower($run),
        'password' => 'SmokePass123',
        'role' => 'employee',
        'account_type' => 'operator',
        'employee_role' => 'technician',
        'full_name' => 'Ticket Waiting Fairness Smoke Employee',
        'is_active' => 1,
    ], false);
    if (!is_array($accountSaved)) {
        hr_score_ticket_waiting_fairness_smoke_fail('employee_seed_failed');
    }
    $employeeId = (int) ($accountSaved['id'] ?? 0);
    if ($employeeId <= 0) {
        hr_score_ticket_waiting_fairness_smoke_fail('employee_id_missing');
    }

    // --- Customer non-response status ---
    $custTicketNo = 'WAIT-CUST-' . $run;
    $custTicketId = $insertTicket($custTicketNo, $employeeId);

    // 1. Cannot double-set / clear-without-set.
    $clearBeforeSet = app_db_clear_ticket_awaiting_customer($custTicketNo, $adminActor);
    if (!empty($clearBeforeSet['ok']) || ($clearBeforeSet['error'] ?? '') !== 'not_awaiting_customer') {
        hr_score_ticket_waiting_fairness_smoke_fail('clear_before_set_was_not_blocked');
    }

    // 2. Setting works and status actually changes.
    $setCustomer = app_db_set_ticket_awaiting_customer($custTicketNo, $adminActor, 'smoke: waiting on customer to confirm access');
    if (empty($setCustomer['ok'])) {
        hr_score_ticket_waiting_fairness_smoke_fail('set_awaiting_customer_failed:' . json_encode($setCustomer, JSON_UNESCAPED_SLASHES));
    }
    $ticketRowCheck = $db->prepare('SELECT status FROM portal_tickets WHERE id = :id');
    $ticketRowCheck->execute(['id' => $custTicketId]);
    if ((string) $ticketRowCheck->fetchColumn() !== APP_TICKET_STATUS_AWAITING_CUSTOMER) {
        hr_score_ticket_waiting_fairness_smoke_fail('ticket_status_not_updated_to_awaiting_customer');
    }

    // 3. A task_missed penalty dated today (while still awaiting customer)
    //    must be refused.
    $blockedByCustomer = app_mgs_record_hr_score_event([
        'employee_account_id' => $employeeId,
        'event_type' => 'penalty',
        'reason_code' => 'task_missed',
        'event_date' => date('Y-m-d'),
        'related_ticket_no' => $custTicketNo,
        'reason' => 'smoke: should be blocked, awaiting customer',
    ], $adminActor, $db);
    if (!empty($blockedByCustomer['ok']) || ($blockedByCustomer['error'] ?? '') !== 'penalty_blocked_by_customer_no_response') {
        hr_score_ticket_waiting_fairness_smoke_fail('penalty_during_customer_wait_was_not_blocked:' . json_encode($blockedByCustomer, JSON_UNESCAPED_SLASHES));
    }

    // 4. The override flag still allows an explicit override.
    $overridden = app_mgs_record_hr_score_event([
        'employee_account_id' => $employeeId,
        'event_type' => 'penalty',
        'reason_code' => 'task_missed',
        'event_date' => date('Y-m-d'),
        'related_ticket_no' => $custTicketNo,
        'reason' => 'smoke: override customer wait block',
        'override_ticket_waiting_state' => true,
    ], $adminActor, $db);
    if (empty($overridden['ok'])) {
        hr_score_ticket_waiting_fairness_smoke_fail('override_did_not_allow_penalty:' . json_encode($overridden, JSON_UNESCAPED_SLASHES));
    }
    $scoreEventIds[] = (int) ($overridden['id'] ?? 0);

    // 5. Clearing the wait, then a penalty dated AFTER clearing must succeed
    //    normally (the rule only protects the actual waiting window).
    $clearCustomer = app_db_clear_ticket_awaiting_customer($custTicketNo, $adminActor);
    if (empty($clearCustomer['ok'])) {
        hr_score_ticket_waiting_fairness_smoke_fail('clear_awaiting_customer_failed');
    }
    $afterClearPenalty = app_mgs_record_hr_score_event([
        'employee_account_id' => $employeeId,
        'event_type' => 'penalty',
        'reason_code' => 'task_missed',
        'event_date' => date('Y-m-d'),
        'related_ticket_no' => $custTicketNo,
        'reason' => 'smoke: penalty after customer responded',
    ], $adminActor, $db);
    if (empty($afterClearPenalty['ok'])) {
        hr_score_ticket_waiting_fairness_smoke_fail('penalty_after_clear_was_incorrectly_blocked:' . json_encode($afterClearPenalty, JSON_UNESCAPED_SLASHES));
    }
    $scoreEventIds[] = (int) ($afterClearPenalty['id'] ?? 0);

    // 6. A penalty dated BEFORE the ticket was ever set to waiting (a date
    //    with no waiting-state history at all) must be unaffected.
    $priorDatePenalty = app_mgs_record_hr_score_event([
        'employee_account_id' => $employeeId,
        'event_type' => 'penalty',
        'reason_code' => 'task_missed',
        'event_date' => date('Y-m-d', strtotime('-30 days')),
        'related_ticket_no' => $custTicketNo,
        'reason' => 'smoke: penalty on an unrelated prior date',
    ], $adminActor, $db);
    if (empty($priorDatePenalty['ok'])) {
        hr_score_ticket_waiting_fairness_smoke_fail('penalty_on_unrelated_prior_date_was_incorrectly_blocked:' . json_encode($priorDatePenalty, JSON_UNESCAPED_SLASHES));
    }
    $scoreEventIds[] = (int) ($priorDatePenalty['id'] ?? 0);

    // --- Cross-department delay exclusion ---
    $deptTicketNo = 'WAIT-DEPT-' . $run;
    $deptTicketId = $insertTicket($deptTicketNo, $employeeId);

    // 7. Department + reason are both required.
    $missingDept = app_db_set_ticket_dept_blocked($deptTicketNo, '', 'smoke: no department given', $adminActor);
    if (!empty($missingDept['ok']) || ($missingDept['error'] ?? '') !== 'department_and_reason_required') {
        hr_score_ticket_waiting_fairness_smoke_fail('missing_department_was_not_blocked');
    }

    $setDept = app_db_set_ticket_dept_blocked($deptTicketNo, 'warehouse', 'smoke: waiting on warehouse to deliver ONT', $adminActor);
    if (empty($setDept['ok'])) {
        hr_score_ticket_waiting_fairness_smoke_fail('set_dept_blocked_failed:' . json_encode($setDept, JSON_UNESCAPED_SLASHES));
    }

    // 8. A task_missed penalty while dept-blocked must be refused and report
    //    which department it was waiting on.
    $blockedByDept = app_mgs_record_hr_score_event([
        'employee_account_id' => $employeeId,
        'event_type' => 'penalty',
        'reason_code' => 'task_missed',
        'event_date' => date('Y-m-d'),
        'related_ticket_no' => $deptTicketNo,
        'reason' => 'smoke: should be blocked, waiting on warehouse',
    ], $adminActor, $db);
    if (!empty($blockedByDept['ok']) || ($blockedByDept['error'] ?? '') !== 'penalty_blocked_by_dept_dependency' || (string) ($blockedByDept['waiting_on_department'] ?? '') !== 'warehouse') {
        hr_score_ticket_waiting_fairness_smoke_fail('penalty_during_dept_wait_was_not_blocked:' . json_encode($blockedByDept, JSON_UNESCAPED_SLASHES));
    }

    // 9. Clearing dept-block restores normal penalty behavior.
    $clearDept = app_db_clear_ticket_dept_blocked($deptTicketNo, $adminActor);
    if (empty($clearDept['ok'])) {
        hr_score_ticket_waiting_fairness_smoke_fail('clear_dept_blocked_failed');
    }
    $afterDeptClearPenalty = app_mgs_record_hr_score_event([
        'employee_account_id' => $employeeId,
        'event_type' => 'penalty',
        'reason_code' => 'task_missed',
        'event_date' => date('Y-m-d'),
        'related_ticket_no' => $deptTicketNo,
        'reason' => 'smoke: penalty after warehouse resolved',
    ], $adminActor, $db);
    if (empty($afterDeptClearPenalty['ok'])) {
        hr_score_ticket_waiting_fairness_smoke_fail('penalty_after_dept_clear_was_incorrectly_blocked:' . json_encode($afterDeptClearPenalty, JSON_UNESCAPED_SLASHES));
    }
    $scoreEventIds[] = (int) ($afterDeptClearPenalty['id'] ?? 0);

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
