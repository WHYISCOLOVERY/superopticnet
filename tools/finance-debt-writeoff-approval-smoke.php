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
class FinanceDebtWriteoffSmokeFailure extends RuntimeException
{
}

function finance_debt_writeoff_smoke_fail(string $message, int $code = 1): never
{
    throw new FinanceDebtWriteoffSmokeFailure($message, $code);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$run = date('His') . random_int(100, 999);
$debtId = 0;
$partialDebtId = 0;
$requesterActor = ['id' => 501201, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'full_name' => 'Debt Writeoff Smoke Finance'];
$checkerActor = ['id' => 501202, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'full_name' => 'Debt Writeoff Smoke Admin'];

$cleanup = function () use ($db, &$debtId, &$partialDebtId): void {
    foreach ([$debtId, $partialDebtId] as $id) {
        if ($id > 0) {
            $db->prepare('DELETE FROM finance_debt_writeoff_requests WHERE debt_id = :id')->execute(['id' => $id]);
            $db->prepare('DELETE FROM finance_debt_payments WHERE debt_id = :id')->execute(['id' => $id]);
            $db->prepare('DELETE FROM finance_debt_ledger WHERE id = :id')->execute(['id' => $id]);
        }
    }
};

try {
    $debtSaved = app_mgs_save_finance_debt_record([
        'ledger_type' => 'receivable',
        'counterparty_name' => 'Debt Writeoff Smoke Customer ' . $run,
        'counterparty_type' => 'customer',
        'amount' => 200,
        'document_no' => 'DWO-' . $run,
    ], $requesterActor, $db);
    $debtId = (int) ($debtSaved['id'] ?? 0);
    if (empty($debtSaved['ok']) || $debtId <= 0) {
        finance_debt_writeoff_smoke_fail('debt_seed_failed:' . json_encode($debtSaved, JSON_UNESCAPED_SLASHES));
    }

    // 1. Reason is mandatory.
    $noReason = app_mgs_request_finance_debt_writeoff($db, $debtId, 0, '', $requesterActor);
    if (!empty($noReason['ok']) || ($noReason['error'] ?? '') !== 'reason_required') {
        finance_debt_writeoff_smoke_fail('missing_reason_was_not_blocked');
    }

    // 2. Requesting a full writeoff (amount=0 means "everything open") must
    //    stage a pending request WITHOUT touching the ledger balance yet.
    $requested = app_mgs_request_finance_debt_writeoff($db, $debtId, 0, 'Smoke: customer went bankrupt, uncollectible', $requesterActor);
    if (empty($requested['ok']) || (int) ($requested['id'] ?? 0) <= 0) {
        finance_debt_writeoff_smoke_fail('writeoff_request_create_failed:' . json_encode($requested, JSON_UNESCAPED_SLASHES));
    }
    $requestId = (int) $requested['id'];
    if (abs((float) ($requested['requested_amount'] ?? 0) - 200.0) > 0.01) {
        finance_debt_writeoff_smoke_fail('requested_amount_wrong');
    }

    $stillOpenStatement = $db->prepare('SELECT amount_open, status FROM finance_debt_ledger WHERE id = :id');
    $stillOpenStatement->execute(['id' => $debtId]);
    $stillOpen = $stillOpenStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    if (abs((float) ($stillOpen['amount_open'] ?? 0) - 200.0) > 0.01 || (string) ($stillOpen['status'] ?? '') !== 'open') {
        finance_debt_writeoff_smoke_fail('debt_ledger_changed_before_approval');
    }

    // 3. A duplicate pending request against the same debt is allowed at the
    //    lib level (no uniqueness constraint) -- but a non-admin cannot ever
    //    resolve either one.
    $nonAdminActor = ['id' => 501203, 'role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'finance', 'full_name' => 'Non-Admin Finance'];
    $nonAdminResolve = app_mgs_resolve_finance_debt_writeoff($db, $requestId, 'approved', 'smoke', $nonAdminActor);
    if (!empty($nonAdminResolve['ok']) || ($nonAdminResolve['error'] ?? '') !== 'permission_denied') {
        finance_debt_writeoff_smoke_fail('non_admin_was_able_to_resolve');
    }

    // 4. The requester cannot approve their own writeoff request.
    $selfApprove = app_mgs_resolve_finance_debt_writeoff($db, $requestId, 'approved', 'smoke self-approve', $requesterActor);
    if (!empty($selfApprove['ok']) || ($selfApprove['error'] ?? '') !== 'requester_cannot_approve_own_request') {
        finance_debt_writeoff_smoke_fail('requester_was_able_to_approve_own_request');
    }

    // 5. A DIFFERENT admin approves -> the ledger balance actually drops to
    //    zero, status flips to closed, and a writeoff-tagged payment row
    //    exists (never mistaken for real cash).
    $checkerApprove = app_mgs_resolve_finance_debt_writeoff($db, $requestId, 'approved', 'smoke checker approval', $checkerActor);
    if (empty($checkerApprove['ok']) || empty($checkerApprove['debt_payment_id'])) {
        finance_debt_writeoff_smoke_fail('checker_admin_could_not_approve:' . json_encode($checkerApprove, JSON_UNESCAPED_SLASHES));
    }
    $afterApprovalStatement = $db->prepare('SELECT amount_open, status FROM finance_debt_ledger WHERE id = :id');
    $afterApprovalStatement->execute(['id' => $debtId]);
    $afterApproval = $afterApprovalStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((float) ($afterApproval['amount_open'] ?? -1) !== 0.0 || (string) ($afterApproval['status'] ?? '') !== 'closed') {
        finance_debt_writeoff_smoke_fail('approved_writeoff_did_not_close_debt');
    }
    $paymentMethodStatement = $db->prepare('SELECT payment_method FROM finance_debt_payments WHERE id = :id');
    $paymentMethodStatement->execute(['id' => (int) $checkerApprove['debt_payment_id']]);
    if ((string) $paymentMethodStatement->fetchColumn() !== 'writeoff') {
        finance_debt_writeoff_smoke_fail('writeoff_payment_not_tagged_correctly');
    }

    // 6. Approving an already-resolved request again must fail cleanly.
    $reapprove = app_mgs_resolve_finance_debt_writeoff($db, $requestId, 'approved', 'smoke re-approve', $checkerActor);
    if (!empty($reapprove['ok']) || ($reapprove['error'] ?? '') !== 'request_already_resolved') {
        finance_debt_writeoff_smoke_fail('already_resolved_request_was_resolved_again');
    }

    // 7. A request against an already-closed debt must be refused outright.
    $closedDebtRequest = app_mgs_request_finance_debt_writeoff($db, $debtId, 0, 'Smoke: already closed', $requesterActor);
    if (!empty($closedDebtRequest['ok']) || ($closedDebtRequest['error'] ?? '') !== 'debt_not_open') {
        finance_debt_writeoff_smoke_fail('writeoff_request_accepted_on_closed_debt');
    }

    // 8. Partial writeoff + rejection path on a second debt: a PARTIAL
    //    writeoff amount must only reduce the open balance by that amount,
    //    and rejecting a request must leave the ledger completely untouched.
    $partialSaved = app_mgs_save_finance_debt_record([
        'ledger_type' => 'receivable',
        'counterparty_name' => 'Debt Writeoff Smoke Partial ' . $run,
        'counterparty_type' => 'customer',
        'amount' => 300,
        'document_no' => 'DWO-PARTIAL-' . $run,
    ], $requesterActor, $db);
    $partialDebtId = (int) ($partialSaved['id'] ?? 0);
    if (empty($partialSaved['ok']) || $partialDebtId <= 0) {
        finance_debt_writeoff_smoke_fail('partial_debt_seed_failed');
    }

    $rejectedRequest = app_mgs_request_finance_debt_writeoff($db, $partialDebtId, 100, 'Smoke: will be rejected', $requesterActor);
    if (empty($rejectedRequest['ok'])) {
        finance_debt_writeoff_smoke_fail('rejected_flow_request_create_failed');
    }
    $rejected = app_mgs_resolve_finance_debt_writeoff($db, (int) $rejectedRequest['id'], 'rejected', 'smoke: not approved', $checkerActor);
    if (empty($rejected['ok']) || ($rejected['status'] ?? '') !== 'rejected' || !empty($rejected['debt_payment_id'])) {
        finance_debt_writeoff_smoke_fail('rejection_incorrectly_applied_or_failed:' . json_encode($rejected, JSON_UNESCAPED_SLASHES));
    }
    $stillFullBalance = $db->prepare('SELECT amount_open, status FROM finance_debt_ledger WHERE id = :id');
    $stillFullBalance->execute(['id' => $partialDebtId]);
    $stillFullBalanceRow = $stillFullBalance->fetch(PDO::FETCH_ASSOC) ?: [];
    if (abs((float) ($stillFullBalanceRow['amount_open'] ?? 0) - 300.0) > 0.01 || (string) ($stillFullBalanceRow['status'] ?? '') !== 'open') {
        finance_debt_writeoff_smoke_fail('rejected_request_changed_ledger_balance');
    }

    $partialApprovedRequest = app_mgs_request_finance_debt_writeoff($db, $partialDebtId, 100, 'Smoke: partial writeoff', $requesterActor);
    if (empty($partialApprovedRequest['ok'])) {
        finance_debt_writeoff_smoke_fail('partial_writeoff_request_create_failed');
    }
    $partialApproved = app_mgs_resolve_finance_debt_writeoff($db, (int) $partialApprovedRequest['id'], 'approved', 'smoke partial approval', $checkerActor);
    if (empty($partialApproved['ok'])) {
        finance_debt_writeoff_smoke_fail('partial_writeoff_approve_failed');
    }
    $afterPartialStatement = $db->prepare('SELECT amount_open, status FROM finance_debt_ledger WHERE id = :id');
    $afterPartialStatement->execute(['id' => $partialDebtId]);
    $afterPartial = $afterPartialStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    if (abs((float) ($afterPartial['amount_open'] ?? -1) - 200.0) > 0.01 || (string) ($afterPartial['status'] ?? '') !== 'partial') {
        finance_debt_writeoff_smoke_fail('partial_writeoff_balance_wrong');
    }

    // 9. Listing helper returns only pending rows when asked for pending.
    $pendingList = app_mgs_finance_debt_writeoff_requests($db, 'pending');
    foreach ($pendingList as $row) {
        if (in_array((int) ($row['id'] ?? 0), [$requestId, (int) $rejectedRequest['id'], (int) $partialApprovedRequest['id']], true)) {
            finance_debt_writeoff_smoke_fail('resolved_request_still_listed_as_pending');
        }
    }

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'debt_id' => $debtId,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit($exception->getCode() ?: 1);
}
