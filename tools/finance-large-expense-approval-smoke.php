<?php
declare(strict_types=1);

require dirname(__DIR__) . '/finance-operations-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

// NOTE: PHP does not run try{}finally{} blocks after exit()/die() inside the
// try body -- every failure path below throws instead of exiting directly.
class FinanceLargeExpenseApprovalSmokeFailure extends RuntimeException
{
}

function finance_large_expense_smoke_fail(string $message, int $code = 1): never
{
    throw new FinanceLargeExpenseApprovalSmokeFailure($message, $code);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}
app_finance_ensure_operational_schema($db);

$run = date('His') . random_int(100, 999);
$smallEntryId = 0;
$largeEntryId = 0;
$creatorActor = ['id' => 501001, 'role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'finance', 'full_name' => 'Large Expense Smoke Finance'];
$checkerActor = ['id' => 501002, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'full_name' => 'Large Expense Smoke Admin'];

$cleanup = function () use ($db, &$smallEntryId, &$largeEntryId): void {
    foreach ([$smallEntryId, $largeEntryId] as $id) {
        if ($id > 0) {
            $db->prepare('DELETE FROM finance_entries WHERE id = :id')->execute(['id' => $id]);
        }
    }
};

try {
    $threshold = (float) app_business_rule_get($db, 'finance_large_expense_threshold_amount', 1000);

    // 1. A small expense (below threshold) must post immediately, exactly as
    //    before this change.
    $small = app_finance_save_entry($db, [
        'entry_type' => 'expense',
        'title' => 'Large expense smoke - small',
        'category' => 'manual',
        'amount' => max(1.0, $threshold - 1),
        'currency' => 'AZN',
        'transaction_date' => date('Y-m-d'),
        'reference_no' => 'LGEXP-SMALL-' . $run,
    ], $creatorActor);
    $smallEntryId = (int) ($small['id'] ?? 0);
    if (empty($small['ok']) || $smallEntryId <= 0) {
        finance_large_expense_smoke_fail('small_expense_save_failed:' . json_encode($small, JSON_UNESCAPED_SLASHES));
    }
    $smallStatus = $db->prepare('SELECT status FROM finance_entries WHERE id = :id');
    $smallStatus->execute(['id' => $smallEntryId]);
    if ((string) $smallStatus->fetchColumn() !== 'posted') {
        finance_large_expense_smoke_fail('small_expense_not_posted_immediately');
    }

    // 2. An expense AT the threshold must land as pending_approval, not posted.
    $large = app_finance_save_entry($db, [
        'entry_type' => 'expense',
        'title' => 'Large expense smoke - large',
        'category' => 'manual',
        'amount' => $threshold,
        'currency' => 'AZN',
        'transaction_date' => date('Y-m-d'),
        'reference_no' => 'LGEXP-LARGE-' . $run,
    ], $creatorActor);
    $largeEntryId = (int) ($large['id'] ?? 0);
    if (empty($large['ok']) || $largeEntryId <= 0) {
        finance_large_expense_smoke_fail('large_expense_save_failed:' . json_encode($large, JSON_UNESCAPED_SLASHES));
    }
    $largeRowStatement = $db->prepare('SELECT status, created_by_account_id FROM finance_entries WHERE id = :id');
    $largeRowStatement->execute(['id' => $largeEntryId]);
    $largeRow = $largeRowStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((string) ($largeRow['status'] ?? '') !== 'pending_approval') {
        finance_large_expense_smoke_fail('large_expense_did_not_land_pending');
    }

    // 3. A pending large expense must NOT count in the posted-expense total.
    $postedTotal = (float) $db->query(
        'SELECT COALESCE(SUM(amount),0) FROM finance_entries WHERE status = "posted" AND entry_type = "expense" AND reference_no = "LGEXP-LARGE-' . $run . '"'
    )->fetchColumn();
    if ($postedTotal !== 0.0) {
        finance_large_expense_smoke_fail('pending_expense_incorrectly_counted_as_posted');
    }

    // 4. The creator cannot approve their own large expense.
    $selfApprove = app_finance_approve_expense_entry($db, $largeEntryId, $creatorActor);
    if (!empty($selfApprove['ok']) || ($selfApprove['error'] ?? '') !== 'permission_denied') {
        finance_large_expense_smoke_fail('non_admin_creator_was_not_rejected_at_permission_gate:' . json_encode($selfApprove, JSON_UNESCAPED_SLASHES));
    }

    // 5. A different actor without admin rights also cannot approve.
    $nonAdminChecker = ['id' => 501003, 'role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'finance', 'full_name' => 'Non-Admin Finance'];
    $nonAdminAttempt = app_finance_approve_expense_entry($db, $largeEntryId, $nonAdminChecker);
    if (!empty($nonAdminAttempt['ok']) || ($nonAdminAttempt['error'] ?? '') !== 'permission_denied') {
        finance_large_expense_smoke_fail('non_admin_was_able_to_approve');
    }

    // 6. A DIFFERENT admin approves successfully -> status flips to posted and
    //    it now counts in the posted-expense total.
    $checkerApprove = app_finance_approve_expense_entry($db, $largeEntryId, $checkerActor);
    if (empty($checkerApprove['ok'])) {
        finance_large_expense_smoke_fail('checker_admin_could_not_approve:' . json_encode($checkerApprove, JSON_UNESCAPED_SLASHES));
    }
    $afterApprovalStatement = $db->prepare('SELECT status, approved_by_account_id FROM finance_entries WHERE id = :id');
    $afterApprovalStatement->execute(['id' => $largeEntryId]);
    $afterApproval = $afterApprovalStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((string) ($afterApproval['status'] ?? '') !== 'posted' || (int) ($afterApproval['approved_by_account_id'] ?? 0) !== (int) $checkerActor['id']) {
        finance_large_expense_smoke_fail('approved_entry_state_wrong');
    }
    $postedTotalAfter = (float) $db->query(
        'SELECT COALESCE(SUM(amount),0) FROM finance_entries WHERE status = "posted" AND entry_type = "expense" AND reference_no = "LGEXP-LARGE-' . $run . '"'
    )->fetchColumn();
    if (abs($postedTotalAfter - $threshold) > 0.01) {
        finance_large_expense_smoke_fail('approved_expense_not_counted_in_posted_total');
    }

    // 7. A second approval attempt on the now-posted entry must fail cleanly.
    $reapprove = app_finance_approve_expense_entry($db, $largeEntryId, $checkerActor);
    if (!empty($reapprove['ok']) || ($reapprove['error'] ?? '') !== 'not_pending_approval') {
        finance_large_expense_smoke_fail('already_posted_entry_was_approved_again');
    }

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'threshold' => $threshold,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit($exception->getCode() ?: 1);
}
