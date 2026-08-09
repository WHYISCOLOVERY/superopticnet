<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/finance-schema-lib.php';
require_once __DIR__ . '/finance-operations-lib.php';
require_once __DIR__ . '/billing-module-lib.php';

function app_finance_reconciliation_require_schema(PDO $db): void
{
    $missing = app_finance_schema_missing_tables($db, ['finance_cash_reconciliations']);
    if ($missing !== []) {
        throw new RuntimeException('finance_reconciliation_schema_not_migrated:' . implode(',', $missing));
    }
}

/**
 * "What the books say happened" for [startDate, endDate] -- billing_payments
 * (all methods, this already covers terminal/bank/manual RADIUS payments)
 * plus the manual finance_entries income/expense ledger. Deliberately the
 * same three queries app_finance_overview_kpis()/app_finance_statistics_context()
 * already use for "Net pul axini", so this reconciliation figure can never
 * silently diverge from what the rest of Finance already shows for the
 * same period.
 */
function app_finance_reconciliation_system_net_movement(PDO $db, string $startDate, string $endDate): array
{
    $income = app_billing_period_revenue_total($db, $startDate . ' 00:00:00', date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00');
    $income += app_finance_manual_income_total($db, $startDate, $endDate);

    $expenseStatement = $db->prepare(
        'SELECT COALESCE(SUM(amount),0) FROM finance_entries
          WHERE entry_type = "expense" AND status = "posted" AND currency = "AZN"
            AND transaction_date >= :start_date AND transaction_date <= :end_date'
    );
    $expenseStatement->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    $expense = (float) $expenseStatement->fetchColumn();

    return [
        'income' => round($income, 2),
        'expense' => round($expense, 2),
        'net' => round($income - $expense, 2),
    ];
}

/**
 * Records an advisory discrepancy check. This NEVER adjusts any ledger,
 * balance, or other table -- it only compares a manually-declared bank/kassa
 * net movement against the system-derived one and stores both plus the
 * discrepancy for the audit trail. Matches vision 7.3's "kassa-bank
 * ferqlerini tapmali" without any auto-posting/correction (deliberately
 * scoped read-only/advisory, per the highest-risk flag on this feature).
 */
function app_finance_reconciliation_record_check(PDO $db, array $actor, array $input): array
{
    if (!app_finance_actor_can_write($actor)) {
        throw new RuntimeException('finance_reconciliation_forbidden');
    }
    app_finance_reconciliation_require_schema($db);

    $periodStart = clean_text((string) ($input['period_start'] ?? ''));
    $periodEnd = clean_text((string) ($input['period_end'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd) || $periodStart > $periodEnd) {
        throw new InvalidArgumentException('invalid_period');
    }
    $sourceLabel = mb_substr(clean_text((string) ($input['source_label'] ?? '')), 0, 96);
    if ($sourceLabel === '') {
        throw new InvalidArgumentException('source_label_required');
    }
    if (!array_key_exists('declared_net_movement', $input) || (string) $input['declared_net_movement'] === '') {
        throw new InvalidArgumentException('declared_net_movement_required');
    }
    $declaredNet = round((float) $input['declared_net_movement'], 2);
    $note = mb_substr(clean_text((string) ($input['note'] ?? '')), 0, 255);

    $system = app_finance_reconciliation_system_net_movement($db, $periodStart, $periodEnd);
    $discrepancy = round($declaredNet - $system['net'], 2);

    $actorId = (int) ($actor['id'] ?? 0) ?: null;
    $actorName = (string) ($actor['full_name'] ?? $actor['login'] ?? '');
    $insert = $db->prepare(
        'INSERT INTO finance_cash_reconciliations
            (period_start, period_end, source_label, declared_net_movement, system_net_movement,
             discrepancy_amount, note, created_by_account_id, created_by_name)
         VALUES (:period_start, :period_end, :source_label, :declared_net_movement, :system_net_movement,
                 :discrepancy_amount, :note, :actor_id, :actor_name)'
    );
    $insert->execute([
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'source_label' => $sourceLabel,
        'declared_net_movement' => $declaredNet,
        'system_net_movement' => $system['net'],
        'discrepancy_amount' => $discrepancy,
        'note' => $note !== '' ? $note : null,
        'actor_id' => $actorId,
        'actor_name' => $actorName !== '' ? $actorName : null,
    ]);

    return [
        'ok' => true,
        'id' => (int) $db->lastInsertId(),
        'system_net_movement' => $system['net'],
        'discrepancy_amount' => $discrepancy,
    ];
}
