<?php
declare(strict_types=1);

require dirname(__DIR__) . '/finance-module-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

class FinanceCashReconciliationSmokeFailure extends RuntimeException
{
}

function finance_cash_reconciliation_smoke_fail(string $message): never
{
    throw new FinanceCashReconciliationSmokeFailure($message);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$run = date('His') . random_int(100, 999);
$reconciliationIds = [];
$paymentIds = [];
$accountIds = [];
$entryIds = [];
$periodStart = '2001-04-01';
$periodEnd = '2001-04-30';

$cleanup = function () use ($db, &$reconciliationIds, &$paymentIds, &$accountIds, &$entryIds): void {
    foreach ($reconciliationIds as $id) {
        $db->prepare('DELETE FROM finance_cash_reconciliations WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($entryIds as $id) {
        $db->prepare('DELETE FROM finance_entries WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($paymentIds as $id) {
        $db->prepare('DELETE FROM billing_payments WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($accountIds as $id) {
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $id]);
    }
};

$financeActor = ['id' => 900201, 'full_name' => 'Smoke Finance Actor', 'employee_role' => 'finance'];
$unauthorizedActor = ['id' => 900202, 'full_name' => 'Smoke Support Actor', 'employee_role' => 'support'];

try {
    // --- Permission guard ---
    try {
        app_finance_reconciliation_record_check($db, $unauthorizedActor, [
            'period_start' => $periodStart, 'period_end' => $periodEnd, 'source_label' => 'X', 'declared_net_movement' => 10,
        ]);
        finance_cash_reconciliation_smoke_fail('unauthorized_actor_was_allowed');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'finance_reconciliation_forbidden') {
            finance_cash_reconciliation_smoke_fail('wrong_error_for_unauthorized:' . $exception->getMessage());
        }
    }

    // --- Validation guards ---
    try {
        app_finance_reconciliation_record_check($db, $financeActor, [
            'period_start' => $periodEnd, 'period_end' => $periodStart, 'source_label' => 'X', 'declared_net_movement' => 10,
        ]);
        finance_cash_reconciliation_smoke_fail('inverted_period_was_accepted');
    } catch (InvalidArgumentException $exception) {
        if ($exception->getMessage() !== 'invalid_period') {
            finance_cash_reconciliation_smoke_fail('wrong_error_for_inverted_period:' . $exception->getMessage());
        }
    }
    try {
        app_finance_reconciliation_record_check($db, $financeActor, [
            'period_start' => $periodStart, 'period_end' => $periodEnd, 'source_label' => '', 'declared_net_movement' => 10,
        ]);
        finance_cash_reconciliation_smoke_fail('empty_source_label_was_accepted');
    } catch (InvalidArgumentException $exception) {
        if ($exception->getMessage() !== 'source_label_required') {
            finance_cash_reconciliation_smoke_fail('wrong_error_for_empty_source_label:' . $exception->getMessage());
        }
    }

    // NOTE: app_billing_period_revenue_total() (called inside
    // app_finance_reconciliation_system_net_movement()) is request-level
    // memoized by SQL+params (billing-module-lib.php's app_billing_scalar) --
    // calling the SAME period twice in one process returns the FIRST call's
    // cached value even after new rows are inserted. That's correct for a
    // real request (the table can't change mid-request), but it means a
    // before/after delta check here would silently compare against a stale
    // cache instead of fresh data. So this uses a synthetic 2001-04 period
    // no other smoke test touches and asserts EXACT values from a single
    // fresh call instead (matches the pattern used by the CEO KPI smoke for
    // its exact revenue-by-area assertions).

    // --- Seed a known income (billing_payments) and expense (finance_entries) inside the period ---
    $saved = app_db_upsert_account([
        'login' => 'smoke-reconciliation-' . strtolower($run) . '@qa.local',
        'password' => 'SmokePass123',
        'role' => 'customer',
        'account_type' => 'customer',
        'full_name' => 'Smoke Reconciliation Customer ' . $run,
        'subscriber_code' => 'RECSMOKE-' . strtoupper($run),
        'status' => 'Aktiv',
        'is_active' => 1,
    ], false);
    if (!is_array($saved)) {
        finance_cash_reconciliation_smoke_fail('customer_seed_failed');
    }
    $accountId = (int) $saved['id'];
    $accountIds[] = $accountId;

    $db->prepare(
        'INSERT INTO billing_payments (account_id, subscriber_code, amount, payment_method, created_at)
         VALUES (:account_id, :subscriber_code, :amount, "smoke", :created_at)'
    )->execute([
        'account_id' => $accountId,
        'subscriber_code' => 'RECSMOKE-' . strtoupper($run),
        'amount' => 80.00,
        'created_at' => $periodStart . ' 10:00:00',
    ]);
    $paymentIds[] = (int) $db->lastInsertId();

    $db->prepare(
        'INSERT INTO finance_entries (entry_type, title, category, amount, currency, transaction_date, status, created_by_name)
         VALUES ("expense", "Smoke reconciliation expense", "smoke", 30.00, "AZN", :transaction_date, "posted", "smoke")'
    )->execute(['transaction_date' => $periodStart]);
    $entryIds[] = (int) $db->lastInsertId();

    $after = app_finance_reconciliation_system_net_movement($db, $periodStart, $periodEnd);
    $netDelta = round($after['net'], 2);
    if (abs($netDelta - 50.00) > 0.01) {
        finance_cash_reconciliation_smoke_fail('system_net_wrong:' . $netDelta . ' (expected 80-30=50, synthetic period, no other data expected)');
    }

    // --- Record a check with the declared amount matching the system figure exactly ---
    $declaredNet = 50.00;
    $matchedResult = app_finance_reconciliation_record_check($db, $financeActor, [
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'source_label' => 'Smoke Bank ' . $run,
        'declared_net_movement' => $declaredNet,
        'note' => 'matched check',
    ]);
    $reconciliationIds[] = (int) $matchedResult['id'];
    if (abs((float) $matchedResult['discrepancy_amount']) > 0.01) {
        finance_cash_reconciliation_smoke_fail('matched_check_should_have_zero_discrepancy:' . $matchedResult['discrepancy_amount']);
    }

    // --- A second check with an intentionally wrong declared amount must show the exact discrepancy ---
    $mismatchedResult = app_finance_reconciliation_record_check($db, $financeActor, [
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'source_label' => 'Smoke Bank Mismatch ' . $run,
        'declared_net_movement' => $declaredNet + 25.00,
    ]);
    $reconciliationIds[] = (int) $mismatchedResult['id'];
    if (abs((float) $mismatchedResult['discrepancy_amount'] - 25.00) > 0.01) {
        finance_cash_reconciliation_smoke_fail('mismatched_discrepancy_wrong:' . $mismatchedResult['discrepancy_amount']);
    }

    // --- The stored row must persist BOTH system_net_movement and discrepancy_amount frozen at check time ---
    $rowCheck = $db->prepare('SELECT system_net_movement, discrepancy_amount FROM finance_cash_reconciliations WHERE id = :id');
    $rowCheck->execute(['id' => $mismatchedResult['id']]);
    $storedRow = $rowCheck->fetch(PDO::FETCH_ASSOC);
    if (!is_array($storedRow) || abs((float) $storedRow['system_net_movement'] - $after['net']) > 0.01) {
        finance_cash_reconciliation_smoke_fail('stored_system_net_movement_wrong:' . json_encode($storedRow, JSON_UNESCAPED_SLASHES));
    }

    // --- Listing page finds our rows via search ---
    $listing = app_finance_cash_reconciliation_page($db, ['search' => 'Smoke Bank Mismatch ' . $run]);
    $listingHasOurRow = false;
    foreach ((array) ($listing['rows'] ?? []) as $listedRow) {
        if ((int) ($listedRow['id'] ?? 0) === (int) $mismatchedResult['id']) {
            $listingHasOurRow = true;
        }
    }
    if (!$listingHasOurRow) {
        finance_cash_reconciliation_smoke_fail('listing_search_missing_our_row');
    }

    // --- Read-only/advisory guarantee: recording checks must not have touched
    //     billing_payments or finance_entries beyond what we ourselves inserted. ---
    $paymentCountCheck = $db->prepare('SELECT COUNT(*) FROM billing_payments WHERE id = :id');
    $paymentCountCheck->execute(['id' => $paymentIds[0]]);
    $entryCountCheck = $db->prepare('SELECT amount, status FROM finance_entries WHERE id = :id');
    $entryCountCheck->execute(['id' => $entryIds[0]]);
    $entryRow = $entryCountCheck->fetch(PDO::FETCH_ASSOC);
    if ((int) $paymentCountCheck->fetchColumn() !== 1 || !is_array($entryRow) || abs((float) $entryRow['amount'] - 30.00) > 0.01 || (string) $entryRow['status'] !== 'posted') {
        finance_cash_reconciliation_smoke_fail('reconciliation_check_mutated_source_ledger_rows');
    }

    // Router-level reachability.
    require_once dirname(__DIR__) . '/operations-permission-lib.php';
    if (app_operations_action_module('record_finance_cash_reconciliation') === null) {
        finance_cash_reconciliation_smoke_fail('record_finance_cash_reconciliation_unreachable_missing_from_module_map');
    }

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'net_delta' => $netDelta,
        'mismatched_discrepancy' => (float) $mismatchedResult['discrepancy_amount'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
