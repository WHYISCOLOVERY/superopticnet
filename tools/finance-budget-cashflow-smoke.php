<?php
declare(strict_types=1);

require dirname(__DIR__) . '/finance-budget-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

class FinanceBudgetCashflowSmokeFailure extends RuntimeException
{
}

function finance_budget_cashflow_smoke_fail(string $message): never
{
    throw new FinanceBudgetCashflowSmokeFailure($message);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$run = date('His') . random_int(100, 999);
$periodMonth = '2001-02';
$asOf = new DateTimeImmutable('2001-03-01');
$budgetIds = [];
$entryIds = [];

$categoryExpenseBudgeted = 'SmokeBudgetExpense-' . $run;
$categoryIncomeBudgeted = 'SmokeBudgetIncome-' . $run;
$categoryExpenseUnbudgeted = 'SmokeBudgetNoPlan-' . $run;

$cleanup = function () use ($db, &$budgetIds, &$entryIds): void {
    foreach ($entryIds as $id) {
        $db->prepare('DELETE FROM finance_entries WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($budgetIds as $id) {
        $db->prepare('DELETE FROM finance_budgets WHERE id = :id')->execute(['id' => $id]);
    }
};

$financeActor = ['id' => 900001, 'full_name' => 'Smoke Finance Actor', 'employee_role' => 'finance'];
$unauthorizedActor = ['id' => 900002, 'full_name' => 'Smoke Support Actor', 'employee_role' => 'support'];

$insertEntry = function (string $entryType, string $category, float $amount) use ($db, $periodMonth, &$entryIds): void {
    $db->prepare(
        'INSERT INTO finance_entries (entry_type, title, category, amount, currency, transaction_date, status, created_by_name)
         VALUES (:entry_type, :title, :category, :amount, "AZN", :transaction_date, "posted", "smoke")'
    )->execute([
        'entry_type' => $entryType,
        'title' => 'Smoke ' . $category,
        'category' => $category,
        'amount' => $amount,
        'transaction_date' => $periodMonth . '-15',
    ]);
    $entryIds[] = (int) $db->lastInsertId();
};

try {
    // --- Permission + validation guards (must fail before any row exists) ---
    try {
        app_finance_budget_save($db, $unauthorizedActor, [
            'period_month' => $periodMonth, 'entry_type' => 'expense', 'category' => $categoryExpenseBudgeted, 'budgeted_amount' => 100,
        ]);
        finance_budget_cashflow_smoke_fail('unauthorized_actor_was_allowed_to_save_budget');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'finance_budget_forbidden') {
            finance_budget_cashflow_smoke_fail('wrong_error_for_unauthorized_actor:' . $exception->getMessage());
        }
    }

    try {
        app_finance_budget_save($db, $financeActor, [
            'period_month' => $periodMonth, 'entry_type' => 'invalid', 'category' => $categoryExpenseBudgeted, 'budgeted_amount' => 100,
        ]);
        finance_budget_cashflow_smoke_fail('invalid_entry_type_was_accepted');
    } catch (InvalidArgumentException $exception) {
        if ($exception->getMessage() !== 'invalid_entry_type') {
            finance_budget_cashflow_smoke_fail('wrong_error_for_invalid_entry_type:' . $exception->getMessage());
        }
    }

    try {
        app_finance_budget_save($db, $financeActor, [
            'period_month' => $periodMonth, 'entry_type' => 'expense', 'category' => $categoryExpenseBudgeted, 'budgeted_amount' => -5,
        ]);
        finance_budget_cashflow_smoke_fail('negative_budgeted_amount_was_accepted');
    } catch (InvalidArgumentException $exception) {
        if ($exception->getMessage() !== 'budgeted_amount_must_be_non_negative') {
            finance_budget_cashflow_smoke_fail('wrong_error_for_negative_amount:' . $exception->getMessage());
        }
    }

    // --- Baseline cash-flow forecast BEFORE inserting our test actuals ---
    $before = app_finance_cashflow_forecast($db, $asOf);

    // --- Real save (finance-role actor is allowed, matches app_finance_actor_can_write) ---
    $savedExpense = app_finance_budget_save($db, $financeActor, [
        'period_month' => $periodMonth, 'entry_type' => 'expense', 'category' => $categoryExpenseBudgeted, 'budgeted_amount' => 100,
    ]);
    $savedIncome = app_finance_budget_save($db, $financeActor, [
        'period_month' => $periodMonth, 'entry_type' => 'income', 'category' => $categoryIncomeBudgeted, 'budgeted_amount' => 200,
    ]);
    $budgetLookup = $db->prepare('SELECT id FROM finance_budgets WHERE period_month = :m AND entry_type = :t AND category = :c');
    foreach ([['expense', $categoryExpenseBudgeted], ['income', $categoryIncomeBudgeted]] as [$type, $category]) {
        $budgetLookup->execute(['m' => $periodMonth, 't' => $type, 'c' => $category]);
        $budgetIds[] = (int) $budgetLookup->fetchColumn();
    }

    // Upsert must UPDATE, not duplicate, on a second save of the same key.
    app_finance_budget_save($db, $financeActor, [
        'period_month' => $periodMonth, 'entry_type' => 'expense', 'category' => $categoryExpenseBudgeted, 'budgeted_amount' => 111,
    ]);
    $dupeCheck = $db->prepare('SELECT COUNT(*), MAX(budgeted_amount) FROM finance_budgets WHERE period_month = :m AND entry_type = "expense" AND category = :c');
    $dupeCheck->execute(['m' => $periodMonth, 'c' => $categoryExpenseBudgeted]);
    [$dupeCount, $dupeAmount] = $dupeCheck->fetch(PDO::FETCH_NUM);
    if ((int) $dupeCount !== 1 || abs((float) $dupeAmount - 111.00) > 0.01) {
        finance_budget_cashflow_smoke_fail('upsert_did_not_update_in_place:count=' . $dupeCount . ' amount=' . $dupeAmount);
    }

    // --- Actual finance_entries: under-budget expense, over-budget income, unbudgeted expense ---
    $insertEntry('expense', $categoryExpenseBudgeted, 60.00);
    $insertEntry('income', $categoryIncomeBudgeted, 250.00);
    $insertEntry('expense', $categoryExpenseUnbudgeted, 30.00);

    $comparison = app_finance_budget_vs_actual($db, $periodMonth);
    if ($comparison['period_month'] !== $periodMonth) {
        finance_budget_cashflow_smoke_fail('period_month_mismatch:' . $comparison['period_month']);
    }

    $rowsByKey = [];
    foreach ((array) $comparison['rows'] as $row) {
        $rowsByKey[$row['entry_type'] . '|' . $row['category']] = $row;
    }

    $expenseRow = $rowsByKey['expense|' . $categoryExpenseBudgeted] ?? null;
    if (!is_array($expenseRow) || abs($expenseRow['budgeted'] - 111.00) > 0.01 || abs($expenseRow['actual'] - 60.00) > 0.01
        || abs($expenseRow['variance'] - (-51.00)) > 0.01 || $expenseRow['has_budget'] !== true) {
        finance_budget_cashflow_smoke_fail('expense_row_wrong:' . json_encode($expenseRow, JSON_UNESCAPED_SLASHES));
    }

    $incomeRow = $rowsByKey['income|' . $categoryIncomeBudgeted] ?? null;
    if (!is_array($incomeRow) || abs($incomeRow['budgeted'] - 200.00) > 0.01 || abs($incomeRow['actual'] - 250.00) > 0.01
        || abs($incomeRow['variance'] - 50.00) > 0.01 || abs($incomeRow['variance_percent'] - 25.0) > 0.01) {
        finance_budget_cashflow_smoke_fail('income_row_wrong:' . json_encode($incomeRow, JSON_UNESCAPED_SLASHES));
    }

    $unbudgetedRow = $rowsByKey['expense|' . $categoryExpenseUnbudgeted] ?? null;
    if (!is_array($unbudgetedRow) || $unbudgetedRow['has_budget'] !== false || abs($unbudgetedRow['budgeted'] - 0.0) > 0.01
        || abs($unbudgetedRow['actual'] - 30.00) > 0.01 || $unbudgetedRow['variance_percent'] !== null) {
        finance_budget_cashflow_smoke_fail('unbudgeted_row_wrong:' . json_encode($unbudgetedRow, JSON_UNESCAPED_SLASHES));
    }

    // --- Cash-flow forecast AFTER inserting the same actuals (delta-based, real dev DB may have other rows) ---
    $after = app_finance_cashflow_forecast($db, $asOf);
    $incomeDelta = round($after['trailing_income_total'] - $before['trailing_income_total'], 2);
    $expenseDelta = round($after['trailing_expense_total'] - $before['trailing_expense_total'], 2);
    if (abs($incomeDelta - 250.00) > 0.01) {
        finance_budget_cashflow_smoke_fail('cashflow_income_delta_wrong:' . $incomeDelta);
    }
    if (abs($expenseDelta - 90.00) > 0.01) {
        finance_budget_cashflow_smoke_fail('cashflow_expense_delta_wrong:' . $expenseDelta);
    }

    $bucketsByHorizon = [];
    foreach ((array) $after['buckets'] as $bucket) {
        $bucketsByHorizon[(int) $bucket['horizon_days']] = $bucket;
    }
    if (!isset($bucketsByHorizon[30], $bucketsByHorizon[60], $bucketsByHorizon[90])) {
        finance_budget_cashflow_smoke_fail('missing_forecast_horizon_buckets');
    }
    foreach ($bucketsByHorizon as $horizon => $bucket) {
        $expectedNet = round((float) $after['daily_avg_net'] * $horizon, 2);
        if (abs((float) $bucket['projected_net'] - $expectedNet) > 0.5) {
            finance_budget_cashflow_smoke_fail('bucket_net_inconsistent_with_daily_avg:' . $horizon . ' got=' . $bucket['projected_net'] . ' expected=' . $expectedNet);
        }
    }

    // Admin-only gating in finance-module-lib.php's statistics tab context.
    $source = (string) file_get_contents(__DIR__ . '/../finance-module-lib.php');
    if (!str_contains($source, "if (app_portal_is_admin(\$user)) {")
        || !str_contains($source, "app_finance_budget_vs_actual(\$db, \$budgetPeriodMonth)")
        || !str_contains($source, 'app_finance_cashflow_forecast($db)')) {
        finance_budget_cashflow_smoke_fail('budget_cashflow_not_admin_gated_in_statistics_tab');
    }

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'income_delta' => $incomeDelta,
        'expense_delta' => $expenseDelta,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
