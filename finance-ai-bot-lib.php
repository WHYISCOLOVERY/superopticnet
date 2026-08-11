<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/finance-schema-lib.php';
require_once __DIR__ . '/finance-operations-lib.php';
require_once __DIR__ . '/finance-budget-lib.php';
require_once __DIR__ . '/ai-local-lib.php';

function app_finance_ai_bot_require_schema(PDO $db): void
{
    $missing = app_finance_schema_missing_tables($db, ['finance_ai_bot_conversations', 'finance_ai_suggestions']);
    if ($missing !== []) {
        throw new RuntimeException('finance_ai_bot_schema_not_migrated:' . implode(',', $missing));
    }
}

/**
 * A compact, factual snapshot of the current finance position -- this is
 * what actually grounds the "20 years of finance experience" persona in
 * real numbers instead of letting the LLM invent them. Deliberately reuses
 * this session's own finance functions (CEO KPIs, budget-vs-actual,
 * cash-flow forecast) rather than re-deriving any of them.
 */
function app_finance_ai_bot_context_bundle(PDO $db): array
{
    $budgetVsActual = app_finance_budget_vs_actual($db, app_finance_budget_current_period_month());
    $cashflow = app_finance_cashflow_forecast($db);

    $debtTotals = ['receivable' => 0.0, 'payable' => 0.0];
    $debtStatement = $db->query(
        'SELECT ledger_type, COALESCE(SUM(amount_open),0) AS total_open
           FROM finance_debt_ledger WHERE status IN ("open","partial") GROUP BY ledger_type'
    );
    foreach (($debtStatement ? $debtStatement->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        if (($row['ledger_type'] ?? '') === 'receivable') {
            $debtTotals['receivable'] = (float) $row['total_open'];
        } elseif (($row['ledger_type'] ?? '') === 'payable') {
            $debtTotals['payable'] = (float) $row['total_open'];
        }
    }

    $pendingLargeExpenses = (int) ($db->query(
        "SELECT COUNT(*) FROM finance_entries WHERE entry_type = 'expense' AND status = 'pending_approval'"
    )->fetchColumn() ?: 0);

    $latestReconciliation = $db->query(
        'SELECT period_start, period_end, source_label, discrepancy_amount
           FROM finance_cash_reconciliations ORDER BY id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC) ?: null;

    return [
        'as_of' => date('Y-m-d H:i'),
        'budget_vs_actual' => $budgetVsActual,
        'cashflow_forecast' => $cashflow,
        'open_debt' => $debtTotals,
        'pending_large_expenses' => $pendingLargeExpenses,
        'latest_cash_reconciliation' => $latestReconciliation,
    ];
}

function app_finance_ai_bot_system_prompt(array $bundle): string
{
    $facts = json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    return "Sən SuperOptic ISP şirkətinin 20 illik təcrübəyə malik DAXİLİ maliyyə meneceri AI-köməkçisisən. "
        . "Yalnız aşağıda JSON kimi verilmiş REAL sistem faktlarına əsaslanaraq qısa, konkret, Azərbaycan dilində cavab ver. "
        . "Rəqəm uydurmaq QADAĞANDIR -- yalnız verilən faktları istifadə et, əskik məlumat varsa açıq de. "
        . "Sən heç bir əməliyyatı özün icra etmirsən; yalnız təhlil və tövsiyə verirsən, real dəyişiklik YALNIZ insan admin təsdiqi ilə tətbiq olunur.\n\n"
        . "SİSTEM FAKTLARI:\n" . $facts;
}

function app_finance_ai_bot_detect_budget_suggestion_intent(string $question): bool
{
    $normalized = mb_strtolower($question);
    if (!str_contains($normalized, 'büdcə') && !str_contains($normalized, 'budce') && !str_contains($normalized, 'budget')) {
        return false;
    }
    foreach (['artır', 'azalt', 'dəyiş', 'tövsiyə', 'tovsiye', 'təklif', 'teklif', 'suggest'] as $marker) {
        if (str_contains($normalized, $marker)) {
            return true;
        }
    }
    return false;
}

/**
 * Deterministic (not LLM-guessed) suggestion: picks the expense category
 * with the largest positive variance (actual over budget) from the current
 * month's budget_vs_actual rows and proposes a +10% buffer over actual,
 * rounded to the nearest whole unit. Returns null when there is nothing to
 * suggest (no data, or nothing over budget).
 */
function app_finance_ai_bot_compute_budget_suggestion(array $budgetVsActual): ?array
{
    $worstRow = null;
    $worstVariance = 0.0;
    foreach ((array) ($budgetVsActual['rows'] ?? []) as $row) {
        if (($row['entry_type'] ?? '') !== 'expense' || empty($row['has_budget'])) {
            continue;
        }
        $variance = (float) ($row['variance'] ?? 0);
        if ($variance > $worstVariance) {
            $worstVariance = $variance;
            $worstRow = $row;
        }
    }
    if ($worstRow === null) {
        return null;
    }

    $suggestedAmount = round(((float) $worstRow['actual']) * 1.10, 2);

    return [
        'entry_type' => 'expense',
        'category' => (string) $worstRow['category'],
        'current_budgeted_amount' => (float) $worstRow['budgeted'],
        'current_actual_amount' => (float) $worstRow['actual'],
        'suggested_amount' => $suggestedAmount,
        'reasoning' => sprintf(
            '"%s" kateqoriyası bu ay büdcəni %s AZN keçib (faktiki %s AZN / büdcə %s AZN). Növbəti ay üçün faktiki + 10%% ehtiyat tövsiyə olunur.',
            $worstRow['category'],
            number_format($worstVariance, 2),
            number_format((float) $worstRow['actual'], 2),
            number_format((float) $worstRow['budgeted'], 2)
        ),
    ];
}

/**
 * Answers a free-form finance question, grounded in real data. Read-only:
 * it can stage a suggestion row (never a direct write) when the question
 * looks like a budget-change request -- app_finance_ai_suggestion_resolve()
 * is the only path that can turn a suggestion into a real finance_budgets
 * write, and that always goes through the existing app_finance_budget_save().
 *
 * $transport mirrors ai-local-lib.php's own testability hook (see
 * app_ai_local_chat's $transport param) so this can be smoke-tested without
 * a live local LLM.
 */
function app_finance_ai_bot_answer(PDO $db, array $actor, string $question, ?callable $transport = null): array
{
    if (!app_portal_can_access_module($actor, 'finance')) {
        throw new RuntimeException('finance_ai_bot_forbidden');
    }
    app_finance_ai_bot_require_schema($db);

    $question = trim(clean_text($question));
    if ($question === '') {
        throw new InvalidArgumentException('question_required');
    }

    $bundle = app_finance_ai_bot_context_bundle($db);
    $chat = app_ai_local_chat($question, [
        'system' => app_finance_ai_bot_system_prompt($bundle),
        'max_tokens' => 500,
    ], $transport);

    $llmOk = !empty($chat['ok']);
    $answer = $llmOk
        ? (string) $chat['text']
        : 'Lokal AI hazırda əlçatan deyil (' . (string) ($chat['reason'] ?? 'naməlum səbəb') . '). '
            . 'Faktlar əsasında qısa xülasə: xalis pul axını proqnozu (30 gün) '
            . number_format((float) ($bundle['cashflow_forecast']['buckets'][0]['projected_net'] ?? 0), 2) . ' AZN, '
            . 'açıq alacaq ' . number_format((float) ($bundle['open_debt']['receivable'] ?? 0), 2) . ' AZN, '
            . 'açıq borc ' . number_format((float) ($bundle['open_debt']['payable'] ?? 0), 2) . ' AZN.';

    $suggestionId = null;
    if (app_finance_ai_bot_detect_budget_suggestion_intent($question)) {
        $suggestion = app_finance_ai_bot_compute_budget_suggestion($bundle['budget_vs_actual']);
        if ($suggestion !== null) {
            $insert = $db->prepare(
                'INSERT INTO finance_ai_suggestions
                    (suggestion_type, period_month, entry_type, category, current_budgeted_amount,
                     current_actual_amount, suggested_amount, reasoning, source_question, status)
                 VALUES ("budget_line", :period_month, :entry_type, :category, :current_budgeted_amount,
                         :current_actual_amount, :suggested_amount, :reasoning, :source_question, "pending")'
            );
            $insert->execute([
                'period_month' => (string) $bundle['budget_vs_actual']['period_month'],
                'entry_type' => $suggestion['entry_type'],
                'category' => $suggestion['category'],
                'current_budgeted_amount' => $suggestion['current_budgeted_amount'],
                'current_actual_amount' => $suggestion['current_actual_amount'],
                'suggested_amount' => $suggestion['suggested_amount'],
                'reasoning' => $suggestion['reasoning'],
                'source_question' => $question,
            ]);
            $suggestionId = (int) $db->lastInsertId();
            $answer .= "\n\n[Büdcə təklifi qeydə alındı #" . $suggestionId . ': ' . $suggestion['reasoning'] . ' Tətbiq etmək üçün başqa admin təsdiqi lazımdır.]';
        }
    }

    $actorId = (int) ($actor['id'] ?? 0) ?: null;
    $actorName = (string) ($actor['full_name'] ?? $actor['login'] ?? '');
    $logInsert = $db->prepare(
        'INSERT INTO finance_ai_bot_conversations
            (question, answer, llm_ok, llm_reason, context_bundle_json, suggestion_id, actor_account_id, actor_name)
         VALUES (:question, :answer, :llm_ok, :llm_reason, :context_bundle_json, :suggestion_id, :actor_id, :actor_name)'
    );
    $logInsert->execute([
        'question' => $question,
        'answer' => $answer,
        'llm_ok' => $llmOk ? 1 : 0,
        'llm_reason' => $llmOk ? null : (string) ($chat['reason'] ?? ''),
        'context_bundle_json' => json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'suggestion_id' => $suggestionId,
        'actor_id' => $actorId,
        'actor_name' => $actorName !== '' ? $actorName : null,
    ]);

    return [
        'ok' => true,
        'answer' => $answer,
        'llm_ok' => $llmOk,
        'suggestion_id' => $suggestionId,
    ];
}

/**
 * Applying a suggestion NEVER writes to finance_budgets directly -- it
 * delegates to the already-tested app_finance_budget_save(), so the AI
 * bot's own code never contains the money-relevant write path.
 */
function app_finance_ai_suggestion_resolve(PDO $db, array $actor, int $suggestionId, string $decision, string $note = ''): array
{
    if (!app_finance_actor_can_write($actor)) {
        throw new RuntimeException('finance_ai_bot_forbidden');
    }
    if (!in_array($decision, ['applied', 'dismissed'], true)) {
        throw new InvalidArgumentException('invalid_decision');
    }
    app_finance_ai_bot_require_schema($db);

    $statement = $db->prepare('SELECT * FROM finance_ai_suggestions WHERE id = :id');
    $statement->execute(['id' => $suggestionId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('suggestion_not_found');
    }
    if ((string) $row['status'] !== 'pending') {
        throw new RuntimeException('suggestion_already_resolved');
    }

    if ($decision === 'applied') {
        app_finance_budget_save($db, $actor, [
            'period_month' => (string) $row['period_month'],
            'entry_type' => (string) $row['entry_type'],
            'category' => (string) $row['category'],
            'budgeted_amount' => (float) $row['suggested_amount'],
            'note' => 'AI tövsiyəsi #' . $suggestionId . ' əsasında tətbiq edildi.',
        ]);
    }

    $actorId = (int) ($actor['id'] ?? 0) ?: null;
    $actorName = (string) ($actor['full_name'] ?? $actor['login'] ?? '');
    $update = $db->prepare(
        'UPDATE finance_ai_suggestions
            SET status = :status, resolved_by_account_id = :actor_id, resolved_by_name = :actor_name,
                resolved_note = :note, resolved_at = NOW()
          WHERE id = :id'
    );
    $update->execute([
        'status' => $decision,
        'actor_id' => $actorId,
        'actor_name' => $actorName !== '' ? $actorName : null,
        'note' => mb_substr(clean_text($note), 0, 255) ?: null,
        'id' => $suggestionId,
    ]);

    return ['ok' => true, 'id' => $suggestionId, 'status' => $decision];
}

function app_finance_ai_bot_pending_suggestions(PDO $db, int $limit = 20): array
{
    app_finance_ai_bot_require_schema($db);
    $statement = $db->prepare('SELECT * FROM finance_ai_suggestions WHERE status = "pending" ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
    $statement->execute();
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function app_finance_ai_bot_recent_conversations(PDO $db, int $limit = 10): array
{
    app_finance_ai_bot_require_schema($db);
    $statement = $db->prepare('SELECT * FROM finance_ai_bot_conversations ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)));
    $statement->execute();
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
