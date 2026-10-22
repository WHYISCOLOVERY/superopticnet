<?php
declare(strict_types=1);

require dirname(__DIR__) . '/ai-team-lib.php';
require_once dirname(__DIR__) . '/operations-permission-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

class AiTeamSmokeFailure extends RuntimeException
{
}

function ai_team_smoke_fail(string $message): never
{
    throw new AiTeamSmokeFailure($message);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$conversationIds = [];
$taskIds = [];
$stockItemIds = [];
$cleanup = function () use ($db, &$conversationIds, &$taskIds, &$stockItemIds): void {
    foreach ($conversationIds as $id) {
        $db->prepare('DELETE FROM ai_team_conversations WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($taskIds as $id) {
        $db->prepare('DELETE FROM ai_team_tasks WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($stockItemIds as $id) {
        $db->prepare('DELETE FROM inventory_stock_items WHERE id = :id')->execute(['id' => $id]);
    }
};

$admin = ['id' => 900401, 'full_name' => 'Smoke Admin', 'employee_role' => 'admin', 'account_type' => 'admin', 'role' => 'employee'];
$nocActor = ['id' => 900402, 'full_name' => 'Smoke NOC', 'employee_role' => 'noc', 'account_type' => 'employee', 'role' => 'employee'];
$warehouseActor = ['id' => 900403, 'full_name' => 'Smoke Warehouse', 'employee_role' => 'warehouse', 'account_type' => 'employee', 'role' => 'employee'];

$mockChat = static function (string $url, string $body, array $headers, int $timeout = 30): array {
    return [
        'ok' => true, 'status' => 200,
        'body' => json_encode(['message' => ['role' => 'assistant', 'content' => 'Smoke test ai-team cavabı.']]),
        'latency_ms' => 5, 'response_bytes' => 60,
    ];
};

try {
    // --- Roster shape: 5 personas, finance NOT among them (has its own pilot) ---
    $roster = app_ai_team_roster();
    if (count($roster) !== 5) {
        ai_team_smoke_fail('roster_wrong_count:' . count($roster));
    }
    foreach (['ceo', 'crm_manager', 'network_engineer', 'warehouse_keeper', 'marketing_specialist'] as $key) {
        if (!isset($roster[$key])) {
            ai_team_smoke_fail('roster_missing_key:' . $key);
        }
    }
    if (isset($roster['finance'])) {
        ai_team_smoke_fail('roster_should_not_include_finance_it_has_its_own_pilot');
    }

    // --- Access gate reuses advisor-agent-lib.php's module system ---
    if (!app_ai_team_access_allowed('network_engineer', $nocActor)) {
        ai_team_smoke_fail('noc_actor_should_access_network_engineer_persona');
    }
    if (app_ai_team_access_allowed('warehouse_keeper', $nocActor)) {
        ai_team_smoke_fail('noc_actor_should_not_access_warehouse_keeper_persona_missing_inventory_hr');
    }
    // techops (warehouse_keeper's underlying advisor) cross-module-reads
    // network+inventory+hr (advisor-agent-lib.php's roster), so a plain
    // warehouse-role actor (inventory module only) is correctly BLOCKED --
    // same finding tools/advisor-agent-foundation-smoke.php already asserts
    // for a noc actor against the techops advisor.
    if (app_ai_team_access_allowed('warehouse_keeper', $warehouseActor)) {
        ai_team_smoke_fail('warehouse_actor_should_be_blocked_from_warehouse_keeper_persona_missing_network_hr');
    }
    // Sanity: app_ai_team_access_allowed() must be a pure passthrough to
    // app_advisor_access_allowed(), never a second, possibly-diverging gate.
    if (app_ai_team_access_allowed('crm_manager', $warehouseActor) !== app_advisor_access_allowed('crm', $warehouseActor)) {
        ai_team_smoke_fail('ai_team_access_gate_diverges_from_advisor_access_gate');
    }

    // --- Permission guard for Q&A ---
    try {
        app_ai_team_answer($db, ['id' => 1, 'employee_role' => 'unknown_role', 'account_type' => 'employee'], 'ceo', 'test', $mockChat);
        ai_team_smoke_fail('unauthorized_actor_was_allowed_to_ask_ceo_persona');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'ai_team_forbidden') {
            ai_team_smoke_fail('wrong_error_for_unauthorized_ask:' . $exception->getMessage());
        }
    }

    // --- Empty question guard ---
    try {
        app_ai_team_answer($db, $admin, 'ceo', '   ', $mockChat);
        ai_team_smoke_fail('empty_question_was_accepted');
    } catch (InvalidArgumentException $exception) {
        if ($exception->getMessage() !== 'question_required') {
            ai_team_smoke_fail('wrong_error_for_empty_question:' . $exception->getMessage());
        }
    }

    // --- Plain question: mocked LLM answer, no task staged ---
    $plainResult = app_ai_team_answer($db, $admin, 'ceo', 'Bugünkü vəziyyət necədir?', $mockChat);
    if (empty($plainResult['ok']) || $plainResult['llm_ok'] !== true || !str_contains($plainResult['answer'], 'Smoke test ai-team')) {
        ai_team_smoke_fail('plain_question_answer_wrong:' . json_encode($plainResult, JSON_UNESCAPED_SLASHES));
    }
    if ($plainResult['staged_task_ids'] !== []) {
        ai_team_smoke_fail('plain_question_unexpectedly_staged_tasks');
    }
    $logCheck = $db->prepare('SELECT id FROM ai_team_conversations WHERE persona_key = "ceo" AND question = :q ORDER BY id DESC LIMIT 1');
    $logCheck->execute(['q' => 'Bugünkü vəziyyət necədir?']);
    $conversationIds[] = (int) $logCheck->fetchColumn();

    // --- Seed enough high-risk warehouse facts so the agenda test has something to stage ---
    // app_advisor_read_model_critical_stock_count() excludes rows whose sku/item_name/
    // metadata_json contain "smoke"/"test-"/etc (app_inventory_operational_stock_sql()),
    // so fixture rows must avoid those markers or they silently never count. The techops
    // briefing only marks critical_stock as risk=high once the count reaches >= 5
    // (advisor-agent-lib.php app_advisor_techops_briefing), so this seeds however many
    // rows are needed to cross that threshold regardless of pre-existing real inventory
    // data, instead of assuming a single row is always enough.
    $currentCritical = (int) $db->query(
        'SELECT COUNT(*) FROM inventory_stock_items i WHERE i.status = "active"
           AND i.quantity_on_hand <= i.minimum_quantity AND ' . app_inventory_operational_stock_sql('i')
    )->fetchColumn();
    $neededRows = max(1, 5 - $currentCritical);
    $insertStockItem = $db->prepare(
        'INSERT INTO inventory_stock_items (sku, item_name, category, unit, quantity_on_hand, minimum_quantity, status, created_at)
         VALUES (:sku, :item_name, "aiteamfixture", "ədəd", 0, 5, "active", NOW())'
    );
    for ($i = 0; $i < $neededRows; $i++) {
        $sku = 'AITEAMFIXTURE-' . date('His') . random_int(1000, 9999) . '-' . $i;
        $insertStockItem->execute(['sku' => $sku, 'item_name' => 'AI Komanda anbar fixture ' . $i]);
        $stockItemIds[] = (int) $db->lastInsertId();
    }

    // --- Agenda-intent question stages high/critical proposals as pending tasks ---
    $agendaResult = app_ai_team_answer($db, $admin, 'warehouse_keeper', 'Bugünkü gündəlik tapşırıqları hazırla', $mockChat);
    if ($agendaResult['staged_task_ids'] === []) {
        ai_team_smoke_fail('agenda_question_did_not_stage_any_task_check_critical_stock_fixture');
    }
    foreach ($agendaResult['staged_task_ids'] as $id) {
        $taskIds[] = (int) $id;
    }
    $logCheck2 = $db->prepare('SELECT id FROM ai_team_conversations WHERE persona_key = "warehouse_keeper" AND question = :q ORDER BY id DESC LIMIT 1');
    $logCheck2->execute(['q' => 'Bugünkü gündəlik tapşırıqları hazırla']);
    $conversationIds[] = (int) $logCheck2->fetchColumn();

    $firstTaskId = (int) $agendaResult['staged_task_ids'][0];
    $stagedRow = $db->prepare('SELECT status, persona_key FROM ai_team_tasks WHERE id = :id');
    $stagedRow->execute(['id' => $firstTaskId]);
    $stagedData = $stagedRow->fetch(PDO::FETCH_ASSOC);
    if (!is_array($stagedData) || (string) $stagedData['status'] !== 'pending_admin_review' || (string) $stagedData['persona_key'] !== 'warehouse_keeper') {
        ai_team_smoke_fail('staged_task_row_wrong:' . json_encode($stagedData, JSON_UNESCAPED_SLASHES));
    }

    // --- Approve guards: non-admin forbidden, missing assignee, unknown assignee ---
    try {
        app_ai_team_task_approve($db, $warehouseActor, $firstTaskId, 1, 'normal');
        ai_team_smoke_fail('non_admin_was_allowed_to_approve_task');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'ai_team_forbidden') {
            ai_team_smoke_fail('wrong_error_for_non_admin_approve:' . $exception->getMessage());
        }
    }
    try {
        app_ai_team_task_approve($db, $admin, $firstTaskId, 0, 'normal');
        ai_team_smoke_fail('missing_assignee_was_accepted');
    } catch (InvalidArgumentException $exception) {
        if ($exception->getMessage() !== 'assignee_required') {
            ai_team_smoke_fail('wrong_error_for_missing_assignee:' . $exception->getMessage());
        }
    }
    try {
        app_ai_team_task_approve($db, $admin, $firstTaskId, 999999999, 'normal');
        ai_team_smoke_fail('nonexistent_assignee_was_accepted');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'assignee_not_found') {
            ai_team_smoke_fail('wrong_error_for_nonexistent_assignee:' . $exception->getMessage());
        }
    }

    // --- Real approve: assigns, telegram attempted (kill-switch gracefully handled either way) ---
    $employeeRow = $db->query('SELECT id, full_name FROM portal_accounts WHERE role = "employee" AND is_active = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($employeeRow)) {
        ai_team_smoke_fail('no_active_employee_available_for_fixture');
    }
    $approveResult = app_ai_team_task_approve($db, $admin, $firstTaskId, (int) $employeeRow['id'], 'high');
    if (empty($approveResult['ok']) || !array_key_exists('telegram_sent', $approveResult)) {
        ai_team_smoke_fail('approve_result_wrong:' . json_encode($approveResult, JSON_UNESCAPED_SLASHES));
    }
    $approvedRow = $db->prepare('SELECT status, assigned_employee_account_id, assigned_employee_name, priority FROM ai_team_tasks WHERE id = :id');
    $approvedRow->execute(['id' => $firstTaskId]);
    $approvedData = $approvedRow->fetch(PDO::FETCH_ASSOC);
    if ((string) $approvedData['status'] !== 'approved' || (int) $approvedData['assigned_employee_account_id'] !== (int) $employeeRow['id'] || (string) $approvedData['priority'] !== 'high') {
        ai_team_smoke_fail('approved_row_wrong:' . json_encode($approvedData, JSON_UNESCAPED_SLASHES));
    }

    $approvedListBeforeResponse = app_ai_team_approved_tasks($db, 50);
    $approvedListHasOurs = false;
    foreach ($approvedListBeforeResponse as $row) {
        if ((int) ($row['id'] ?? 0) === $firstTaskId) {
            $approvedListHasOurs = true;
        }
    }
    if (!$approvedListHasOurs) {
        ai_team_smoke_fail('approved_list_missing_our_task_right_after_approval');
    }

    // --- Double-approve guard ---
    try {
        app_ai_team_task_approve($db, $admin, $firstTaskId, (int) $employeeRow['id'], 'normal');
        ai_team_smoke_fail('double_approve_was_accepted');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'task_already_resolved') {
            ai_team_smoke_fail('wrong_error_for_double_approve:' . $exception->getMessage());
        }
    }

    // --- Employee response flow (no token secret configured in dev -> link generation degrades to empty, but the record function itself must still work when called directly) ---
    $responseResult = app_ai_team_task_record_response($db, $firstTaskId, 'Stok yoxlanıldı, sifariş verildi.');
    if (empty($responseResult['ok'])) {
        ai_team_smoke_fail('record_response_failed:' . json_encode($responseResult, JSON_UNESCAPED_SLASHES));
    }
    $completedRow = $db->prepare('SELECT status, response_note FROM ai_team_tasks WHERE id = :id');
    $completedRow->execute(['id' => $firstTaskId]);
    $completedData = $completedRow->fetch(PDO::FETCH_ASSOC);
    if ((string) $completedData['status'] !== 'completed' || (string) $completedData['response_note'] !== 'Stok yoxlanıldı, sifariş verildi.') {
        ai_team_smoke_fail('completed_row_wrong:' . json_encode($completedData, JSON_UNESCAPED_SLASHES));
    }
    try {
        app_ai_team_task_record_response($db, $firstTaskId, 'ikinci cavab');
        ai_team_smoke_fail('double_response_was_accepted');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'task_not_awaiting_response') {
            ai_team_smoke_fail('wrong_error_for_double_response:' . $exception->getMessage());
        }
    }

    // --- Dismiss flow (on a second staged task, if any; otherwise stage+dismiss one directly) ---
    if (count($agendaResult['staged_task_ids']) > 1) {
        $secondTaskId = (int) $agendaResult['staged_task_ids'][1];
    } else {
        $insert = $db->prepare(
            'INSERT INTO ai_team_tasks (persona_key, proposal_type, title, description, reasoning, risk, status)
             VALUES ("warehouse_keeper", "critical_stock", "Smoke dismiss fixture", "desc", "reason", "high", "pending_admin_review")'
        );
        $insert->execute();
        $secondTaskId = (int) $db->lastInsertId();
        $taskIds[] = $secondTaskId;
    }
    try {
        app_ai_team_task_dismiss($db, $admin, $secondTaskId, '');
        ai_team_smoke_fail('empty_dismiss_reason_was_accepted');
    } catch (InvalidArgumentException $exception) {
        if ($exception->getMessage() !== 'reason_required') {
            ai_team_smoke_fail('wrong_error_for_empty_dismiss_reason:' . $exception->getMessage());
        }
    }
    app_ai_team_task_dismiss($db, $admin, $secondTaskId, 'lazım deyil');
    $dismissedRow = $db->prepare('SELECT status, dismissed_reason FROM ai_team_tasks WHERE id = :id');
    $dismissedRow->execute(['id' => $secondTaskId]);
    $dismissedData = $dismissedRow->fetch(PDO::FETCH_ASSOC);
    if ((string) $dismissedData['status'] !== 'dismissed' || (string) $dismissedData['dismissed_reason'] !== 'lazım deyil') {
        ai_team_smoke_fail('dismissed_row_wrong:' . json_encode($dismissedData, JSON_UNESCAPED_SLASHES));
    }

    // --- Listing helpers used by the view must not throw and reflect state ---
    $pending = app_ai_team_pending_tasks($db, 50);
    foreach ($pending as $row) {
        if ((int) ($row['id'] ?? 0) === $firstTaskId || (int) ($row['id'] ?? 0) === $secondTaskId) {
            ai_team_smoke_fail('pending_list_still_shows_a_resolved_task');
        }
    }
    // Router-level: this feature intentionally does NOT go through
    // operations-center.php's shared action dispatcher (its flash-redirect
    // helper only targets operations-center.php's own anchors) -- assert it
    // stayed OUT of that map instead of silently drifting into it unused.
    if (app_operations_action_module('ask_ai_team_bot') !== null) {
        ai_team_smoke_fail('ask_ai_team_bot_unexpectedly_registered_in_operations_center_module_map');
    }

    // ai-team.php must handle its own POST actions locally.
    $aiTeamSource = (string) file_get_contents(dirname(__DIR__) . '/ai-team.php');
    foreach (['ask_ai_team_bot', 'approve_ai_team_task', 'dismiss_ai_team_task'] as $expectedAction) {
        if (!str_contains($aiTeamSource, "'" . $expectedAction . "'")) {
            ai_team_smoke_fail('ai_team_php_missing_action_handling:' . $expectedAction);
        }
    }
    if (!str_contains($aiTeamSource, 'app_verify_csrf(')) {
        ai_team_smoke_fail('ai_team_php_missing_csrf_check');
    }

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'staged_task_count' => count($agendaResult['staged_task_ids']),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
