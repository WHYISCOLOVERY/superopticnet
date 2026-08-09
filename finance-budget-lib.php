<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/finance-schema-lib.php';
require_once __DIR__ . '/finance-operations-lib.php';
require_once __DIR__ . '/billing-module-lib.php';

function app_finance_budget_require_schema(PDO $db): void
{
    $missing = app_finance_schema_missing_tables($db, ['finance_budgets']);
    if ($missing !== []) {
        throw new RuntimeException('finance_budget_schema_not_migrated:' . implode(',', $missing));
    }
}

function app_finance_budget_current_period_month(): string
{
    return date('Y-m');
}

function app_finance_budget_normalize_period_month(mixed $value): string
{
    $value = trim((string) $value);
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1
        ? $value
        : app_finance_budget_current_period_month();
}

function app_finance_budget_month_bounds(string $periodMonth): array
{
    $start = DateTimeImmutable::createFromFormat('Y-m-d', $periodMonth . '-01');
    if (!$start instanceof DateTimeImmutable) {
        $start = new DateTimeImmutable('first day of this month');
    }
    $end = $start->modify('last day of this month');

    return [
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $end->format('Y-m-d'),
    ];
}

/**
 * Admin/finance-role staff plan a monthly budget line per (entry_type,
 * category); this is a planning number, not a money movement, so it is
 * written directly (no maker-checker) -- only finance_entries writes move
 * real balances.
 */
function app_finance_budget_save(PDO $db, array $actor, array $input): array
{
    if (!app_finance_actor_can_write($actor)) {
        throw new RuntimeException('finance_budget_forbidden');
    }
    app_finance_budget_require_schema($db);

    $periodMonth = app_finance_budget_normalize_period_month($input['period_month'] ?? '');
    $entryType = (string) ($input['entry_type'] ?? '');
    if (!in_array($entryType, ['income', 'expense'], true)) {
        throw new InvalidArgumentException('invalid_entry_type');
    }
    $category = mb_substr(clean_text((string) ($input['category'] ?? '')), 0, 96);
    if ($category === '') {
        throw new InvalidArgumentException('category_required');
    }
    $budgetedAmount = round((float) ($input['budgeted_amount'] ?? 0), 2);
    if ($budgetedAmount < 0) {
        throw new InvalidArgumentException('budgeted_amount_must_be_non_negative');
    }
    $note = mb_substr(clean_text((string) ($input['note'] ?? '')), 0, 255);
    $actorId = (int) ($actor['id'] ?? 0) ?: null;
    $actorName = (string) ($actor['full_name'] ?? $actor['login'] ?? '');

    $statement = $db->prepare(
        'INSERT INTO finance_budgets
            (period_month, entry_type, category, budgeted_amount, note,
             created_by_account_id, created_by_name, updated_by_account_id, updated_by_name)
         VALUES (:period_month, :entry_type, :category, :budgeted_amount, :note,
                 :actor_id, :actor_name, :actor_id2, :actor_name2)
         ON DUPLICATE KEY UPDATE
            budgeted_amount = VALUES(budgeted_amount),
            note = VALUES(note),
            updated_by_account_id = VALUES(updated_by_account_id),
            updated_by_name = VALUES(updated_by_name)'
    );
    $statement->execute([
        'period_month' => $periodMonth,
        'entry_type' => $entryType,
        'category' => $category,
        'budgeted_amount' => $budgetedAmount,
        'note' => $note !== '' ? $note : null,
        'actor_id' => $actorId,
        'actor_name' => $actorName !== '' ? $actorName : null,
        'actor_id2' => $actorId,
        'actor_name2' => $actorName !== '' ? $actorName : null,
    ]);

    return [
        'period_month' => $periodMonth,
        'entry_type' => $entryType,
        'category' => $category,
        'budgeted_amount' => $budgetedAmount,
    ];
}

/**
 * Budget vs actual is scoped to the manual finance_entries ledger (the only
 * table with a "category" concept) -- billing_payments/RADIUS revenue is
 * already covered separately by the CEO KPI MRR figure and has no category
 * dimension to budget against.
 */
function app_finance_budget_vs_actual(PDO $db, string $periodMonth): array
{
    app_finance_budget_require_schema($db);
    $periodMonth = app_finance_budget_normalize_period_month($periodMonth);
    $bounds = app_finance_budget_month_bounds($periodMonth);

    $budgetStatement = $db->prepare(
        'SELECT entry_type, category, budgeted_amount
           FROM finance_budgets
          WHERE period_month = :period_month
          ORDER BY entry_type, category'
    );
    $budgetStatement->execute(['period_month' => $periodMonth]);
    $budgetRows = $budgetStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $actualStatement = $db->prepare(
        'SELECT entry_type, category, COALESCE(SUM(amount),0) AS actual_total
           FROM finance_entries
          WHERE status = "posted" AND currency = "AZN"
            AND transaction_date >= :start_date AND transaction_date <= :end_date
          GROUP BY entry_type, category'
    );
    $actualStatement->execute(['start_date' => $bounds['start_date'], 'end_date' => $bounds['end_date']]);
    $actualRows = $actualStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $merged = [];
    foreach ($budgetRows as $row) {
        $key = $row['entry_type'] . '|' . $row['category'];
        $merged[$key] = [
            'entry_type' => (string) $row['entry_type'],
            'category' => (string) $row['category'],
            'budgeted' => (float) $row['budgeted_amount'],
            'actual' => 0.0,
            'has_budget' => true,
        ];
    }
    foreach ($actualRows as $row) {
        $key = $row['entry_type'] . '|' . $row['category'];
        if (!isset($merged[$key])) {
            $merged[$key] = [
                'entry_type' => (string) $row['entry_type'],
                'category' => (string) $row['category'],
                'budgeted' => 0.0,
                'actual' => 0.0,
                'has_budget' => false,
            ];
        }
        $merged[$key]['actual'] = (float) $row['actual_total'];
    }

    $rows = [];
    $totals = [
        'budgeted_income' => 0.0, 'actual_income' => 0.0,
        'budgeted_expense' => 0.0, 'actual_expense' => 0.0,
    ];
    foreach ($merged as $row) {
        $variance = $row['actual'] - $row['budgeted'];
        $row['variance'] = round($variance, 2);
        $row['variance_percent'] = $row['budgeted'] > 0 ? round(($variance / $row['budgeted']) * 100, 2) : null;
        $rows[] = $row;

        if ($row['entry_type'] === 'income') {
            $totals['budgeted_income'] += $row['budgeted'];
            $totals['actual_income'] += $row['actual'];
        } else {
            $totals['budgeted_expense'] += $row['budgeted'];
            $totals['actual_expense'] += $row['actual'];
        }
    }
    usort($rows, static fn (array $a, array $b): int => $a['entry_type'] <=> $b['entry_type'] ?: $a['category'] <=> $b['category']);

    return [
        'period_month' => $periodMonth,
        'start_date' => $bounds['start_date'],
        'end_date' => $bounds['end_date'],
        'rows' => $rows,
        'totals' => $totals + [
            'net_budgeted' => round($totals['budgeted_income'] - $totals['budgeted_expense'], 2),
            'net_actual' => round($totals['actual_income'] - $totals['actual_expense'], 2),
        ],
    ];
}

/**
 * 30/60/90-day cash-flow forecast. The platform has no bank/cash BALANCE
 * concept (no account_balance table), so this projects NET CASH FLOW
 * (expected income minus expense) over the trailing-90-day average, not an
 * absolute cash position. Deliberately simple/transparent (matches the
 * churn-rate "yaxinlasma" pattern already used in statistics-center-lib.php)
 * rather than a false-precision model -- documented in `notes`.
 */
function app_finance_cashflow_forecast(PDO $db, ?DateTimeImmutable $asOf = null): array
{
    $asOf = $asOf ?? new DateTimeImmutable('now');
    $windowStart = $asOf->modify('-90 days');

    $trailingIncome = app_billing_period_revenue_total(
        $db,
        $windowStart->format('Y-m-d H:i:s'),
        $asOf->format('Y-m-d H:i:s')
    );
    $manualIncomeStatement = $db->prepare(
        'SELECT COALESCE(SUM(amount),0) FROM finance_entries
          WHERE entry_type = "income" AND status = "posted" AND currency = "AZN"
            AND transaction_date >= :start_date AND transaction_date <= :end_date'
    );
    $manualIncomeStatement->execute(['start_date' => $windowStart->format('Y-m-d'), 'end_date' => $asOf->format('Y-m-d')]);
    $trailingIncome += (float) $manualIncomeStatement->fetchColumn();

    $expenseStatement = $db->prepare(
        'SELECT COALESCE(SUM(amount),0) FROM finance_entries
          WHERE entry_type = "expense" AND status = "posted" AND currency = "AZN"
            AND transaction_date >= :start_date AND transaction_date <= :end_date'
    );
    $expenseStatement->execute(['start_date' => $windowStart->format('Y-m-d'), 'end_date' => $asOf->format('Y-m-d')]);
    $trailingExpense = (float) $expenseStatement->fetchColumn();

    $trailingDays = 90;
    $dailyIncome = $trailingIncome / $trailingDays;
    $dailyExpense = $trailingExpense / $trailingDays;
    $dailyNet = $dailyIncome - $dailyExpense;

    $buckets = [];
    foreach ([30, 60, 90] as $horizon) {
        $buckets[] = [
            'horizon_days' => $horizon,
            'projected_income' => round($dailyIncome * $horizon, 2),
            'projected_expense' => round($dailyExpense * $horizon, 2),
            'projected_net' => round($dailyNet * $horizon, 2),
        ];
    }

    return [
        'as_of' => $asOf->format('Y-m-d'),
        'trailing_window_days' => $trailingDays,
        'trailing_income_total' => round($trailingIncome, 2),
        'trailing_expense_total' => round($trailingExpense, 2),
        'daily_avg_income' => round($dailyIncome, 2),
        'daily_avg_expense' => round($dailyExpense, 2),
        'daily_avg_net' => round($dailyNet, 2),
        'buckets' => $buckets,
        'notes' => [
            'method' => 'Son 90 gunun faktiki gunluk orta gelir/xerc esasinda xetti proqnoz -- movsumi ve ya bilinen gelecek xercleri (meselen boyuk bir defelik alis) NEZERE ALMIR.',
            'balance' => 'Bu, XALIS PUL AXINI proqnozudur (gelir - xerc), kassa/bank QALIGI deyil -- sistemde hele bank/kassa balans cedveli yoxdur.',
        ],
    ];
}
