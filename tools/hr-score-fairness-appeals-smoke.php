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
class HrScoreFairnessAppealsSmokeFailure extends RuntimeException
{
}

function hr_fairness_appeals_smoke_fail(string $message, int $code = 1): never
{
    throw new HrScoreFairnessAppealsSmokeFailure($message, $code);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$stamp = date('His') . random_int(100, 999);
$employeeId = 0;
$otherEmployeeId = 0;
$ticketIds = [];
$ticketNos = [];
$scoreEventIds = [];
$appealIds = [];
$surgeDate = '2001-03-01';
$normalDate = '2001-02-01';

$adminActor = ['id' => 0, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'full_name' => 'Fairness Smoke Admin'];

$cleanup = function () use ($db, &$employeeId, &$otherEmployeeId, &$ticketIds, &$scoreEventIds, &$appealIds): void {
    foreach ($appealIds as $id) {
        $db->prepare('DELETE FROM hr_score_appeals WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($scoreEventIds as $id) {
        $db->prepare('DELETE FROM hr_score_events WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($ticketIds as $id) {
        $db->prepare('DELETE FROM portal_tickets WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ([$employeeId, $otherEmployeeId] as $id) {
        if ($id > 0) {
            $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $id]);
        }
    }
};

try {
    $employeeSaved = app_db_upsert_account([
        'login' => 'smoke-fair-emp-' . strtolower($stamp),
        'password' => 'SmokePass123',
        'role' => 'employee',
        'account_type' => 'operator',
        'employee_role' => 'technician',
        'full_name' => 'Fairness Smoke Employee',
        'is_active' => 1,
    ], false);
    $employeeId = (int) ($employeeSaved['id'] ?? 0);
    if ($employeeId <= 0) {
        hr_fairness_appeals_smoke_fail('employee_seed_failed');
    }
    $otherSaved = app_db_upsert_account([
        'login' => 'smoke-fair-other-' . strtolower($stamp),
        'password' => 'SmokePass123',
        'role' => 'employee',
        'account_type' => 'operator',
        'employee_role' => 'technician',
        'full_name' => 'Fairness Smoke Other Employee',
        'is_active' => 1,
    ], false);
    $otherEmployeeId = (int) ($otherSaved['id'] ?? 0);
    if ($otherEmployeeId <= 0) {
        hr_fairness_appeals_smoke_fail('other_employee_seed_failed');
    }

    $insertTicket = static function (string $ticketNo, ?int $assignedTo, string $openedAt) use ($db, &$ticketIds, &$ticketNos): void {
        $db->prepare(
            'INSERT INTO portal_tickets (ticket_no, customer_name, subject, message, status, opened_at, updated_at, assigned_technician_account_id)
             VALUES (:ticket_no, "Fairness Smoke Customer", "technical", "smoke ticket", "Aciqdir", :opened_at, :updated_at, :assigned_to)'
        )->execute(['ticket_no' => $ticketNo, 'opened_at' => $openedAt, 'updated_at' => $openedAt, 'assigned_to' => $assignedTo]);
        $ticketIds[] = (int) $db->lastInsertId();
        $ticketNos[] = $ticketNo;
    };

    // 1. task_missed with no ticket reference must be refused outright.
    $noTicket = app_mgs_record_hr_score_event([
        'employee_account_id' => $employeeId,
        'event_type' => 'penalty',
        'reason_code' => 'task_missed',
        'event_date' => $normalDate,
        'reason' => 'smoke: no ticket reference',
    ], $adminActor, $db);
    if (!empty($noTicket['ok']) || ($noTicket['error'] ?? '') !== 'task_missed_requires_ticket_reference') {
        hr_fairness_appeals_smoke_fail('missing_ticket_reference_not_blocked:' . json_encode($noTicket, JSON_UNESCAPED_SLASHES));
    }

    // 2. task_missed against a ticket assigned to a DIFFERENT employee must be refused.
    $wrongTicketNo = 'FAIR-WRONG-' . $stamp;
    $insertTicket($wrongTicketNo, $otherEmployeeId, $normalDate . ' 10:00:00');
    $wrongAssignee = app_mgs_record_hr_score_event([
        'employee_account_id' => $employeeId,
        'event_type' => 'penalty',
        'reason_code' => 'task_missed',
        'event_date' => $normalDate,
        'related_ticket_no' => $wrongTicketNo,
        'reason' => 'smoke: wrong assignee',
    ], $adminActor, $db);
    if (!empty($wrongAssignee['ok']) || ($wrongAssignee['error'] ?? '') !== 'task_not_assigned_to_employee') {
        hr_fairness_appeals_smoke_fail('unassigned_task_penalty_not_blocked:' . json_encode($wrongAssignee, JSON_UNESCAPED_SLASHES));
    }

    // 3. task_missed against a ticket correctly assigned to this employee, on a
    //    day with normal ticket volume, must succeed.
    $rightTicketNo = 'FAIR-RIGHT-' . $stamp;
    $insertTicket($rightTicketNo, $employeeId, $normalDate . ' 10:00:00');
    $validPenalty = app_mgs_record_hr_score_event([
        'employee_account_id' => $employeeId,
        'event_type' => 'penalty',
        'reason_code' => 'task_missed',
        'event_date' => $normalDate,
        'related_ticket_no' => $rightTicketNo,
        'reason' => 'smoke: valid task_missed penalty',
    ], $adminActor, $db);
    if (empty($validPenalty['ok'])) {
        hr_fairness_appeals_smoke_fail('valid_task_missed_penalty_blocked:' . json_encode($validPenalty, JSON_UNESCAPED_SLASHES));
    }
    $scoreEventIds[] = (int) ($validPenalty['id'] ?? 0);
    if ((string) ($validPenalty['record']['source_type'] ?? '') !== 'ticket' || (string) ($validPenalty['record']['source_id'] ?? '') !== $rightTicketNo) {
        hr_fairness_appeals_smoke_fail('valid_task_missed_penalty_not_linked_to_ticket');
    }

    // 4. Mass-incident day: seed >= threshold (30) tickets opened the same day,
    //    then a task_missed penalty referencing a validly-assigned ticket that
    //    same day must be refused as a likely mass incident.
    for ($i = 0; $i < 30; $i++) {
        $insertTicket('FAIR-SURGE-' . $stamp . '-' . $i, null, $surgeDate . ' 09:00:00');
    }
    $surgeTicketNo = 'FAIR-SURGE-TARGET-' . $stamp;
    $insertTicket($surgeTicketNo, $employeeId, $surgeDate . ' 09:05:00');
    $surgePenalty = app_mgs_record_hr_score_event([
        'employee_account_id' => $employeeId,
        'event_type' => 'penalty',
        'reason_code' => 'task_missed',
        'event_date' => $surgeDate,
        'related_ticket_no' => $surgeTicketNo,
        'reason' => 'smoke: mass incident day',
    ], $adminActor, $db);
    if (!empty($surgePenalty['ok']) || ($surgePenalty['error'] ?? '') !== 'penalty_blocked_by_mass_incident') {
        hr_fairness_appeals_smoke_fail('mass_incident_penalty_not_blocked:' . json_encode($surgePenalty, JSON_UNESCAPED_SLASHES));
    }
    if ((int) ($surgePenalty['ticket_count'] ?? 0) < 30) {
        hr_fairness_appeals_smoke_fail('mass_incident_ticket_count_wrong:' . json_encode($surgePenalty, JSON_UNESCAPED_SLASHES));
    }

    // 5. An explicit override must still allow the penalty through on a surge day.
    $overriddenPenalty = app_mgs_record_hr_score_event([
        'employee_account_id' => $employeeId,
        'event_type' => 'penalty',
        'reason_code' => 'task_missed',
        'event_date' => $surgeDate,
        'related_ticket_no' => $surgeTicketNo,
        'reason' => 'smoke: override mass incident block',
        'override_mass_incident' => true,
    ], $adminActor, $db);
    if (empty($overriddenPenalty['ok'])) {
        hr_fairness_appeals_smoke_fail('override_mass_incident_did_not_allow_penalty:' . json_encode($overriddenPenalty, JSON_UNESCAPED_SLASHES));
    }
    $scoreEventIds[] = (int) ($overriddenPenalty['id'] ?? 0);

    // --- Appeal mechanism (vision 9.3: "iscinin KPI-ye etiraz mexanizmi olur") ---

    // 6. A non-admin cannot file an appeal on someone else's behalf.
    $strangerActor = ['id' => $otherEmployeeId, 'role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'technician', 'full_name' => 'Stranger'];
    $strangerFile = app_mgs_hr_score_appeal_file($db, (int) $validPenalty['id'], 'smoke: stranger tries to appeal', $strangerActor);
    if (!empty($strangerFile['ok']) || ($strangerFile['error'] ?? '') !== 'permission_denied') {
        hr_fairness_appeals_smoke_fail('stranger_was_able_to_file_appeal:' . json_encode($strangerFile, JSON_UNESCAPED_SLASHES));
    }

    // 7. The employee themselves can file an appeal against their own penalty.
    $selfActor = ['id' => $employeeId, 'role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'technician', 'full_name' => 'Fairness Smoke Employee'];
    $filed = app_mgs_hr_score_appeal_file($db, (int) $validPenalty['id'], 'smoke: I was on-site, this ticket was resolved', $selfActor);
    if (empty($filed['ok'])) {
        hr_fairness_appeals_smoke_fail('employee_could_not_file_own_appeal:' . json_encode($filed, JSON_UNESCAPED_SLASHES));
    }
    $appealIds[] = (int) $filed['id'];

    // 8. A duplicate pending appeal on the same event must be refused.
    $duplicateFile = app_mgs_hr_score_appeal_file($db, (int) $validPenalty['id'], 'smoke: duplicate', $selfActor);
    if (!empty($duplicateFile['ok']) || ($duplicateFile['error'] ?? '') !== 'appeal_already_pending') {
        hr_fairness_appeals_smoke_fail('duplicate_appeal_not_blocked:' . json_encode($duplicateFile, JSON_UNESCAPED_SLASHES));
    }

    // 9. The appellant cannot review their own appeal, even as an admin actor
    //    with the same account id.
    $selfAdminActor = ['id' => $employeeId, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'full_name' => 'Fairness Smoke Employee (admin)'];
    $selfReview = app_mgs_hr_score_appeal_resolve($db, (int) $filed['id'], 'overturned', 'smoke: self review attempt', $selfAdminActor);
    if (!empty($selfReview['ok']) || ($selfReview['error'] ?? '') !== 'appellant_cannot_review_own_appeal') {
        hr_fairness_appeals_smoke_fail('appellant_was_able_to_review_own_appeal:' . json_encode($selfReview, JSON_UNESCAPED_SLASHES));
    }

    // 10. A genuine different admin overturns the appeal -> a reversal score
    //     event must appear and net points for the original penalty must be 0.
    $resolved = app_mgs_hr_score_appeal_resolve($db, (int) $filed['id'], 'overturned', 'smoke: ticket was actually resolved on time', $adminActor);
    if (empty($resolved['ok']) || ($resolved['status'] ?? '') !== 'overturned') {
        hr_fairness_appeals_smoke_fail('checker_could_not_overturn_appeal:' . json_encode($resolved, JSON_UNESCAPED_SLASHES));
    }
    $reversalId = (int) ($resolved['reversal_score_event_id'] ?? 0);
    if ($reversalId <= 0) {
        hr_fairness_appeals_smoke_fail('overturn_did_not_create_reversal_event');
    }
    $scoreEventIds[] = $reversalId;
    $netStatement = $db->prepare('SELECT COALESCE(SUM(points_delta),0) FROM hr_score_events WHERE id IN (:original_id, :reversal_id)');
    $netStatement->execute(['original_id' => (int) $validPenalty['id'], 'reversal_id' => $reversalId]);
    if ((float) $netStatement->fetchColumn() !== 0.0) {
        hr_fairness_appeals_smoke_fail('reversal_did_not_net_to_zero');
    }
    $appealRow = $db->prepare('SELECT status, reversal_score_event_id FROM hr_score_appeals WHERE id = :id');
    $appealRow->execute(['id' => (int) $filed['id']]);
    $appealRowData = $appealRow->fetch(PDO::FETCH_ASSOC) ?: [];
    if (($appealRowData['status'] ?? '') !== 'overturned' || (int) ($appealRowData['reversal_score_event_id'] ?? 0) !== $reversalId) {
        hr_fairness_appeals_smoke_fail('appeal_row_not_updated_correctly');
    }

    // 11. Already-resolved appeal cannot be resolved a second time.
    $reResolve = app_mgs_hr_score_appeal_resolve($db, (int) $filed['id'], 'upheld', 'smoke: second resolve attempt', $adminActor);
    if (!empty($reResolve['ok']) || ($reResolve['error'] ?? '') !== 'appeal_already_resolved') {
        hr_fairness_appeals_smoke_fail('resolved_appeal_was_resolved_again:' . json_encode($reResolve, JSON_UNESCAPED_SLASHES));
    }

    // 12. Upheld path: a second appeal on the overridden-mass-incident penalty,
    //     upheld by admin, must NOT create a reversal event.
    $secondFiled = app_mgs_hr_score_appeal_file($db, (int) $overriddenPenalty['id'], 'smoke: second appeal, expected to be upheld', $selfActor);
    if (empty($secondFiled['ok'])) {
        hr_fairness_appeals_smoke_fail('second_appeal_file_failed:' . json_encode($secondFiled, JSON_UNESCAPED_SLASHES));
    }
    $appealIds[] = (int) $secondFiled['id'];
    $upheld = app_mgs_hr_score_appeal_resolve($db, (int) $secondFiled['id'], 'upheld', 'smoke: penalty was justified', $adminActor);
    if (empty($upheld['ok']) || ($upheld['status'] ?? '') !== 'upheld' || !empty($upheld['reversal_score_event_id'])) {
        hr_fairness_appeals_smoke_fail('upheld_appeal_incorrectly_created_reversal:' . json_encode($upheld, JSON_UNESCAPED_SLASHES));
    }

    // 13. Listing helper returns both appeals for this employee.
    $employeeAppeals = app_mgs_hr_score_appeals($db, ['employee_account_id' => $employeeId]);
    if (count($employeeAppeals) < 2) {
        hr_fairness_appeals_smoke_fail('appeal_listing_missing_rows');
    }

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
