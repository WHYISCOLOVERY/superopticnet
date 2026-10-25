<?php
declare(strict_types=1);

require dirname(__DIR__) . '/finance-module-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

class FinanceInvoiceGenerationSmokeFailure extends RuntimeException
{
}

function finance_invoice_generation_smoke_fail(string $message): never
{
    throw new FinanceInvoiceGenerationSmokeFailure($message);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$run = date('His') . random_int(100, 999);
$invoiceIds = [];
$accountIds = [];
$paymentIds = [];

$cleanup = function () use ($db, &$invoiceIds, &$accountIds, &$paymentIds): void {
    foreach ($invoiceIds as $id) {
        $db->prepare('DELETE FROM finance_invoices WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($paymentIds as $id) {
        $db->prepare('DELETE FROM billing_payments WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($accountIds as $id) {
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $id]);
    }
};

$financeActor = ['id' => 900101, 'full_name' => 'Smoke Finance Actor', 'employee_role' => 'finance'];
$unauthorizedActor = ['id' => 900102, 'full_name' => 'Smoke Support Actor', 'employee_role' => 'support'];

try {
    // --- Permission guard ---
    try {
        app_finance_invoice_issue($db, $unauthorizedActor, ['source_type' => 'manual', 'counterparty_name' => 'X', 'subtotal_amount' => 10]);
        finance_invoice_generation_smoke_fail('unauthorized_actor_was_allowed_to_issue_invoice');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'finance_invoice_forbidden') {
            finance_invoice_generation_smoke_fail('wrong_error_for_unauthorized_issue:' . $exception->getMessage());
        }
    }

    // --- Validation guards ---
    try {
        app_finance_invoice_issue($db, $financeActor, ['source_type' => 'manual', 'counterparty_name' => 'X', 'subtotal_amount' => 0]);
        finance_invoice_generation_smoke_fail('zero_subtotal_was_accepted');
    } catch (InvalidArgumentException $exception) {
        if ($exception->getMessage() !== 'subtotal_amount_must_be_positive') {
            finance_invoice_generation_smoke_fail('wrong_error_for_zero_subtotal:' . $exception->getMessage());
        }
    }
    try {
        app_finance_invoice_issue($db, $financeActor, ['source_type' => 'manual', 'counterparty_name' => '', 'subtotal_amount' => 10]);
        finance_invoice_generation_smoke_fail('empty_counterparty_was_accepted');
    } catch (InvalidArgumentException $exception) {
        if ($exception->getMessage() !== 'counterparty_name_required') {
            finance_invoice_generation_smoke_fail('wrong_error_for_empty_counterparty:' . $exception->getMessage());
        }
    }
    try {
        app_finance_invoice_issue($db, $financeActor, ['source_type' => 'manual', 'counterparty_name' => 'X', 'subtotal_amount' => 10, 'vat_rate' => 150]);
        finance_invoice_generation_smoke_fail('invalid_vat_rate_was_accepted');
    } catch (InvalidArgumentException $exception) {
        if ($exception->getMessage() !== 'invalid_vat_rate') {
            finance_invoice_generation_smoke_fail('wrong_error_for_invalid_vat_rate:' . $exception->getMessage());
        }
    }

    // --- Manual invoice: 18% VAT default arithmetic ---
    $manualResult = app_finance_invoice_issue($db, $financeActor, [
        'source_type' => 'manual',
        'counterparty_name' => 'Smoke Manual Counterparty ' . $run,
        'subtotal_amount' => 100.00,
        'vat_rate' => 18.00,
        'issue_date' => '2001-02-10',
        'description' => 'Smoke manual invoice',
    ]);
    $invoiceIds[] = (int) $manualResult['id'];
    if (abs($manualResult['vat_amount'] - 18.00) > 0.01 || abs($manualResult['total_amount'] - 118.00) > 0.01) {
        finance_invoice_generation_smoke_fail('manual_invoice_vat_math_wrong:' . json_encode($manualResult, JSON_UNESCAPED_SLASHES));
    }
    if (!preg_match('/^INV-2001-\d{6}$/', (string) $manualResult['invoice_no'])) {
        finance_invoice_generation_smoke_fail('invoice_number_format_wrong:' . $manualResult['invoice_no']);
    }

    // --- Second manual invoice in the same fiscal year: sequence must advance, no collision ---
    $secondResult = app_finance_invoice_issue($db, $financeActor, [
        'source_type' => 'manual',
        'counterparty_name' => 'Smoke Manual Counterparty 2 ' . $run,
        'subtotal_amount' => 50.00,
        'issue_date' => '2001-03-01',
    ]);
    $invoiceIds[] = (int) $secondResult['id'];
    $firstSeq = (int) substr((string) $manualResult['invoice_no'], -6);
    $secondSeq = (int) substr((string) $secondResult['invoice_no'], -6);
    if ($secondSeq !== $firstSeq + 1) {
        finance_invoice_generation_smoke_fail('sequence_did_not_advance_by_one:' . $firstSeq . '->' . $secondSeq);
    }

    // --- billing_payment-sourced invoice ---
    $saved = app_db_upsert_account([
        'login' => 'smoke-invoice-' . strtolower($run) . '@qa.local',
        'password' => 'SmokePass123',
        'role' => 'customer',
        'account_type' => 'customer',
        'full_name' => 'Smoke Invoice Customer ' . $run,
        'subscriber_code' => 'INVSMOKE-' . strtoupper($run),
        'status' => 'Aktiv',
        'is_active' => 1,
    ], false);
    if (!is_array($saved)) {
        finance_invoice_generation_smoke_fail('customer_seed_failed');
    }
    $accountId = (int) $saved['id'];
    $accountIds[] = $accountId;

    $db->prepare(
        'INSERT INTO billing_payments (account_id, subscriber_code, amount, payment_method, created_at)
         VALUES (:account_id, :subscriber_code, :amount, "smoke", NOW())'
    )->execute(['account_id' => $accountId, 'subscriber_code' => 'INVSMOKE-' . strtoupper($run), 'amount' => 45.00]);
    $paymentId = (int) $db->lastInsertId();
    $paymentIds[] = $paymentId;

    $paymentInvoiceResult = app_finance_invoice_issue($db, $financeActor, [
        'source_type' => 'billing_payment',
        'source_id' => $paymentId,
        'vat_rate' => 0,
    ]);
    $invoiceIds[] = (int) $paymentInvoiceResult['id'];
    if (abs($paymentInvoiceResult['subtotal_amount'] - 45.00) > 0.01 || abs($paymentInvoiceResult['total_amount'] - 45.00) > 0.01) {
        finance_invoice_generation_smoke_fail('payment_sourced_invoice_amount_wrong:' . json_encode($paymentInvoiceResult, JSON_UNESCAPED_SLASHES));
    }
    $counterpartyCheck = $db->prepare('SELECT counterparty_name, account_id FROM finance_invoices WHERE id = :id');
    $counterpartyCheck->execute(['id' => $paymentInvoiceResult['id']]);
    $counterpartyRow = $counterpartyCheck->fetch(PDO::FETCH_ASSOC);
    if ((string) ($counterpartyRow['counterparty_name'] ?? '') !== 'Smoke Invoice Customer ' . $run || (int) ($counterpartyRow['account_id'] ?? 0) !== $accountId) {
        finance_invoice_generation_smoke_fail('payment_sourced_invoice_did_not_snapshot_customer:' . json_encode($counterpartyRow, JSON_UNESCAPED_SLASHES));
    }

    // --- Double-invoicing the same payment must be rejected ---
    try {
        app_finance_invoice_issue($db, $financeActor, ['source_type' => 'billing_payment', 'source_id' => $paymentId]);
        finance_invoice_generation_smoke_fail('same_payment_was_invoiced_twice');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'billing_payment_already_invoiced') {
            finance_invoice_generation_smoke_fail('wrong_error_for_double_invoice:' . $exception->getMessage());
        }
    }

    // --- Void: permission guard, reason guard, then real void, then double-void guard ---
    try {
        app_finance_invoice_void($db, $unauthorizedActor, (int) $manualResult['id'], 'test');
        finance_invoice_generation_smoke_fail('unauthorized_actor_was_allowed_to_void');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'finance_invoice_forbidden') {
            finance_invoice_generation_smoke_fail('wrong_error_for_unauthorized_void:' . $exception->getMessage());
        }
    }
    try {
        app_finance_invoice_void($db, $financeActor, (int) $manualResult['id'], '');
        finance_invoice_generation_smoke_fail('empty_reason_void_was_accepted');
    } catch (InvalidArgumentException $exception) {
        if ($exception->getMessage() !== 'reason_required') {
            finance_invoice_generation_smoke_fail('wrong_error_for_empty_void_reason:' . $exception->getMessage());
        }
    }
    app_finance_invoice_void($db, $financeActor, (int) $manualResult['id'], 'smoke void reason');
    $statusCheck = $db->prepare('SELECT status, void_reason FROM finance_invoices WHERE id = :id');
    $statusCheck->execute(['id' => $manualResult['id']]);
    $statusRow = $statusCheck->fetch(PDO::FETCH_ASSOC);
    if ((string) ($statusRow['status'] ?? '') !== 'void' || (string) ($statusRow['void_reason'] ?? '') !== 'smoke void reason') {
        finance_invoice_generation_smoke_fail('void_did_not_persist:' . json_encode($statusRow, JSON_UNESCAPED_SLASHES));
    }
    try {
        app_finance_invoice_void($db, $financeActor, (int) $manualResult['id'], 'second attempt');
        finance_invoice_generation_smoke_fail('double_void_was_accepted');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'invoice_already_void') {
            finance_invoice_generation_smoke_fail('wrong_error_for_double_void:' . $exception->getMessage());
        }
    }

    // --- Listing page respects status filter ---
    $issuedListing = app_finance_invoice_generated_page($db, ['invoice_status' => 'void', 'search' => $manualResult['invoice_no']]);
    $listingHasOurVoided = false;
    foreach ((array) ($issuedListing['rows'] ?? []) as $listedRow) {
        if ((int) ($listedRow['id'] ?? 0) === (int) $manualResult['id']) {
            $listingHasOurVoided = true;
        }
    }
    if (!$listingHasOurVoided) {
        finance_invoice_generation_smoke_fail('void_filtered_listing_missing_our_invoice');
    }

    // Router-level reachability: both new actions must resolve to a non-null
    // permission module (this is exactly the bug class fixed in commit
    // 99d1862 -- catch a recurrence for THESE two actions specifically too).
    require_once dirname(__DIR__) . '/operations-permission-lib.php';
    if (app_operations_action_module('issue_finance_invoice') === null) {
        finance_invoice_generation_smoke_fail('issue_finance_invoice_unreachable_missing_from_module_map');
    }
    if (app_operations_action_module('void_finance_invoice') === null) {
        finance_invoice_generation_smoke_fail('void_finance_invoice_unreachable_missing_from_module_map');
    }

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'invoice_no' => $manualResult['invoice_no'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
