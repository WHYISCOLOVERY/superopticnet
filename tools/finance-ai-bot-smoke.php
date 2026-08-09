<?php
declare(strict_types=1);

require dirname(__DIR__) . '/finance-module-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

class FinanceAiBotSmokeFailure extends RuntimeException
{
}

function finance_ai_bot_smoke_fail(string $message): never
{
    throw new FinanceAiBotSmokeFailure($message);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$run = date('His') . random_int(100, 999);
$periodMonth = app_finance_budget_current_period_month();
$conversationIds = [];
$suggestionIds = [];
$budgetIds = [];
$entryIds = [];

$cleanup = function () use ($db, &$conversationIds, &$suggestionIds, &$budgetIds, &$entryIds): void {
    foreach ($conversationIds as $id) {
        $db->prepare('DELETE FROM finance_ai_bot_conversations WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($suggestionIds as $id) {
        $db->prepare('DELETE FROM finance_ai_suggestions WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($entryIds as $id) {
        $db->prepare('DELETE FROM finance_entries WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($budgetIds as $id) {
        $db->prepare('DELETE FROM finance_budgets WHERE id = :id')->execute(['id' => $id]);
    }
};

$financeActor = ['id' => 900301, 'full_name' => 'Smoke Finance Actor', 'employee_role' => 'finance', 'role' => 'employee'];
$readOnlyOutsiderActor = ['id' => 900302, 'full_name' => 'Smoke Support Actor', 'employee_role' => 'support', 'role' => 'employee'];

$mockChat = static function (string $url, string $body, array $headers, int $timeout = 30): array {
    return [
        'ok' => true,
        'status' => 200,
        'body' => json_encode(['message' => ['role' => 'assistant', 'content' => 'Smoke test cavabı: maliyyə vəziyyəti stabildir.']]),
        'latency_ms' => 5,
        'response_bytes' => 80,
    ];
};
$mockChatFailure = static function (string $url, string $body, array $headers, int $timeout = 30): array {
    return ['ok' => false, 'status' => 0, 'body' => '', 'latency_ms' => 1, 'response_bytes' => 0, 'reason' => 'connection_refused'];
};

try {
    // --- Permission guard: a role with no finance module access is rejected ---
    try {
        app_finance_ai_bot_answer($db, $readOnlyOutsiderActor, 'Test sualı', $mockChat);
        finance_ai_bot_smoke_fail('unauthorized_actor_was_allowed_to_ask');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'finance_ai_bot_forbidden') {
            finance_ai_bot_smoke_fail('wrong_error_for_unauthorized:' . $exception->getMessage());
        }
    }

    // --- Empty question guard ---
    try {
        app_finance_ai_bot_answer($db, $financeActor, '   ', $mockChat);
        finance_ai_bot_smoke_fail('empty_question_was_accepted');
    } catch (InvalidArgumentException $exception) {
        if ($exception->getMessage() !== 'question_required') {
            finance_ai_bot_smoke_fail('wrong_error_for_empty_question:' . $exception->getMessage());
        }
    }

    // --- Context bundle assembly must not throw and must have the expected keys ---
    $bundle = app_finance_ai_bot_context_bundle($db);
    foreach (['budget_vs_actual', 'cashflow_forecast', 'open_debt', 'pending_large_expenses', 'latest_cash_reconciliation'] as $expectedKey) {
        if (!array_key_exists($expectedKey, $bundle)) {
            finance_ai_bot_smoke_fail('context_bundle_missing_key:' . $expectedKey);
        }
    }

    // --- System prompt establishes the persona and embeds real facts ---
    $prompt = app_finance_ai_bot_system_prompt($bundle);
    if (!str_contains($prompt, '20 illik') || !str_contains($prompt, 'maliyyə meneceri')) {
        finance_ai_bot_smoke_fail('system_prompt_missing_persona');
    }
    if (!str_contains($prompt, (string) $bundle['open_debt']['receivable'])) {
        finance_ai_bot_smoke_fail('system_prompt_missing_real_facts');
    }

    // --- A plain question (no budget-suggestion intent) gets the mocked LLM answer, no suggestion staged ---
    $plainResult = app_finance_ai_bot_answer($db, $financeActor, 'Bu ay net pul axını necədir?', $mockChat);
    if (empty($plainResult['ok']) || $plainResult['llm_ok'] !== true || !str_contains($plainResult['answer'], 'Smoke test cavabı')) {
        finance_ai_bot_smoke_fail('plain_question_answer_wrong:' . json_encode($plainResult, JSON_UNESCAPED_SLASHES));
    }
    if ($plainResult['suggestion_id'] !== null) {
        finance_ai_bot_smoke_fail('plain_question_unexpectedly_staged_a_suggestion');
    }
    $logCheck = $db->prepare('SELECT id FROM finance_ai_bot_conversations WHERE question = :q ORDER BY id DESC LIMIT 1');
    $logCheck->execute(['q' => 'Bu ay net pul axını necədir?']);
    $conversationIds[] = (int) $logCheck->fetchColumn();

    // --- Graceful degradation: LLM unreachable still returns a usable, fact-based answer, not an exception ---
    $degradedResult = app_finance_ai_bot_answer($db, $financeActor, 'LLM olmadan test sualı', $mockChatFailure);
    if (empty($degradedResult['ok']) || $degradedResult['llm_ok'] !== false || $degradedResult['answer'] === '') {
        finance_ai_bot_smoke_fail('degraded_answer_wrong:' . json_encode($degradedResult, JSON_UNESCAPED_SLASHES));
    }
    $logCheck->execute(['q' => 'LLM olmadan test sualı']);
    $conversationIds[] = (int) $logCheck->fetchColumn();

    // --- Seed a budget line + an over-budget actual so a "increase budget" question has something deterministic to suggest ---
    $category = 'SmokeAiBotCategory-' . $run;
    $budgetResult = app_finance_budget_save($db, $financeActor, [
        'period_month' => $periodMonth, 'entry_type' => 'expense', 'category' => $category, 'budgeted_amount' => 100.00,
    ]);
    $budgetLookup = $db->prepare('SELECT id FROM finance_budgets WHERE period_month = :m AND entry_type = "expense" AND category = :c');
    $budgetLookup->execute(['m' => $periodMonth, 'c' => $category]);
    $budgetIds[] = (int) $budgetLookup->fetchColumn();

    $db->prepare(
        'INSERT INTO finance_entries (entry_type, title, category, amount, currency, transaction_date, status, created_by_name)
         VALUES ("expense", "Smoke AI bot expense", :category, 150.00, "AZN", :transaction_date, "posted", "smoke")'
    )->execute(['category' => $category, 'transaction_date' => date('Y-m-d')]);
    $entryIds[] = (int) $db->lastInsertId();

    // --- A budget-suggestion-intent question stages a deterministic suggestion ---
    $suggestionQuestion = 'Bu ay büdcəni artırmaq üçün tövsiyə ver';
    $suggestingResult = app_finance_ai_bot_answer($db, $financeActor, $suggestionQuestion, $mockChat);
    if ($suggestingResult['suggestion_id'] === null) {
        finance_ai_bot_smoke_fail('budget_intent_question_did_not_stage_a_suggestion');
    }
    $suggestionIds[] = (int) $suggestingResult['suggestion_id'];
    $logCheck->execute(['q' => $suggestionQuestion]);
    $conversationIds[] = (int) $logCheck->fetchColumn();

    $suggestionRow = $db->prepare('SELECT * FROM finance_ai_suggestions WHERE id = :id');
    $suggestionRow->execute(['id' => $suggestingResult['suggestion_id']]);
    $suggestionData = $suggestionRow->fetch(PDO::FETCH_ASSOC);
    if (!is_array($suggestionData) || (string) $suggestionData['category'] !== $category
        || abs((float) $suggestionData['current_budgeted_amount'] - 100.00) > 0.01
        || abs((float) $suggestionData['current_actual_amount'] - 150.00) > 0.01
        || abs((float) $suggestionData['suggested_amount'] - 165.00) > 0.01
        || (string) $suggestionData['status'] !== 'pending') {
        finance_ai_bot_smoke_fail('staged_suggestion_wrong:' . json_encode($suggestionData, JSON_UNESCAPED_SLASHES));
    }

    // --- Resolve guards: forbidden actor, invalid decision ---
    try {
        app_finance_ai_suggestion_resolve($db, $readOnlyOutsiderActor, (int) $suggestingResult['suggestion_id'], 'applied');
        finance_ai_bot_smoke_fail('unauthorized_actor_was_allowed_to_resolve');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'finance_ai_bot_forbidden') {
            finance_ai_bot_smoke_fail('wrong_error_for_unauthorized_resolve:' . $exception->getMessage());
        }
    }
    try {
        app_finance_ai_suggestion_resolve($db, $financeActor, (int) $suggestingResult['suggestion_id'], 'not_a_real_decision');
        finance_ai_bot_smoke_fail('invalid_decision_was_accepted');
    } catch (InvalidArgumentException $exception) {
        if ($exception->getMessage() !== 'invalid_decision') {
            finance_ai_bot_smoke_fail('wrong_error_for_invalid_decision:' . $exception->getMessage());
        }
    }

    // --- Applying the suggestion must route through app_finance_budget_save() and actually update the real budget row ---
    app_finance_ai_suggestion_resolve($db, $financeActor, (int) $suggestingResult['suggestion_id'], 'applied', 'smoke apply');
    $updatedBudget = $db->prepare('SELECT budgeted_amount FROM finance_budgets WHERE id = :id');
    $updatedBudget->execute(['id' => $budgetIds[0]]);
    if (abs((float) $updatedBudget->fetchColumn() - 165.00) > 0.01) {
        finance_ai_bot_smoke_fail('applying_suggestion_did_not_update_real_budget_row');
    }
    $resolvedCheck = $db->prepare('SELECT status FROM finance_ai_suggestions WHERE id = :id');
    $resolvedCheck->execute(['id' => $suggestingResult['suggestion_id']]);
    if ((string) $resolvedCheck->fetchColumn() !== 'applied') {
        finance_ai_bot_smoke_fail('suggestion_status_not_marked_applied');
    }

    // --- Double-resolve guard ---
    try {
        app_finance_ai_suggestion_resolve($db, $financeActor, (int) $suggestingResult['suggestion_id'], 'dismissed');
        finance_ai_bot_smoke_fail('double_resolve_was_accepted');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'suggestion_already_resolved') {
            finance_ai_bot_smoke_fail('wrong_error_for_double_resolve:' . $exception->getMessage());
        }
    }

    // --- A dismissed suggestion must NOT touch the budget row ---
    $secondCategory = 'SmokeAiBotDismiss-' . $run;
    $secondBudget = app_finance_budget_save($db, $financeActor, [
        'period_month' => $periodMonth, 'entry_type' => 'expense', 'category' => $secondCategory, 'budgeted_amount' => 50.00,
    ]);
    $secondBudgetLookup = $db->prepare('SELECT id FROM finance_budgets WHERE period_month = :m AND entry_type = "expense" AND category = :c');
    $secondBudgetLookup->execute(['m' => $periodMonth, 'c' => $secondCategory]);
    $budgetIds[] = (int) $secondBudgetLookup->fetchColumn();
    $db->prepare(
        'INSERT INTO finance_entries (entry_type, title, category, amount, currency, transaction_date, status, created_by_name)
         VALUES ("expense", "Smoke AI bot expense 2", :category, 80.00, "AZN", :transaction_date, "posted", "smoke")'
    )->execute(['category' => $secondCategory, 'transaction_date' => date('Y-m-d')]);
    $entryIds[] = (int) $db->lastInsertId();
    $secondSuggesting = app_finance_ai_bot_answer($db, $financeActor, 'Büdcəni yenidən nəzərdən keçirməyi tövsiyə et', $mockChat);
    $logCheck->execute(['q' => 'Büdcəni yenidən nəzərdən keçirməyi tövsiyə et']);
    $conversationIds[] = (int) $logCheck->fetchColumn();
    if ($secondSuggesting['suggestion_id'] !== null) {
        $suggestionIds[] = (int) $secondSuggesting['suggestion_id'];
        app_finance_ai_suggestion_resolve($db, $financeActor, (int) $secondSuggesting['suggestion_id'], 'dismissed', 'smoke dismiss');
        $untouchedBudget = $db->prepare('SELECT budgeted_amount FROM finance_budgets WHERE id = :id');
        $untouchedBudget->execute(['id' => $budgetIds[1]]);
        if (abs((float) $untouchedBudget->fetchColumn() - 50.00) > 0.01) {
            finance_ai_bot_smoke_fail('dismissed_suggestion_mutated_the_budget_row');
        }
    }

    // --- Listing helpers used by the view must not throw ---
    $pending = app_finance_ai_bot_pending_suggestions($db, 5);
    $recent = app_finance_ai_bot_recent_conversations($db, 5);
    if (!is_array($pending) || !is_array($recent)) {
        finance_ai_bot_smoke_fail('listing_helpers_returned_non_array');
    }

    // Router-level reachability for both new actions.
    require_once dirname(__DIR__) . '/operations-permission-lib.php';
    if (app_operations_action_module('ask_finance_ai_bot') === null) {
        finance_ai_bot_smoke_fail('ask_finance_ai_bot_unreachable_missing_from_module_map');
    }
    if (app_operations_action_module('resolve_finance_ai_suggestion') === null) {
        finance_ai_bot_smoke_fail('resolve_finance_ai_suggestion_unreachable_missing_from_module_map');
    }

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'suggested_amount' => (float) $suggestionData['suggested_amount'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
