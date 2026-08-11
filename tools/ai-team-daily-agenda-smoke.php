<?php
declare(strict_types=1);

require dirname(__DIR__) . '/ai-team-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

class AiTeamDailyAgendaSmokeFailure extends RuntimeException
{
}

function ai_team_daily_agenda_smoke_fail(string $message): never
{
    throw new AiTeamDailyAgendaSmokeFailure($message);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$taskIds = [];
$stockItemIds = [];
$agendaRunIds = [];
$cleanup = function () use ($db, &$taskIds, &$stockItemIds, &$agendaRunIds): void {
    foreach ($taskIds as $id) {
        $db->prepare('DELETE FROM ai_team_tasks WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($stockItemIds as $id) {
        $db->prepare('DELETE FROM inventory_stock_items WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($agendaRunIds as $id) {
        $db->prepare('DELETE FROM ai_team_agenda_runs WHERE id = :id')->execute(['id' => $id]);
    }
};

try {
    // --- Seed enough critical-stock rows to push app_advisor_techops_briefing()'s
    //     count to >= 5 (its own 'high' threshold) REGARDLESS of how many real
    //     critical-stock rows already exist in this dev DB -- triggers BOTH the
    //     warehouse_keeper persona (techops advisor) AND the ceo persona
    //     (which aggregates techops) -- this is exactly the scenario that
    //     exposed the cross-persona dedup bug fixed in migration
    //     20260810_410 (CEO staging first silently blocked the specialist
    //     persona from ever getting its own routed task for the same fact).
    // NOTE: sku/item_name/category deliberately avoid the "smoke"/"test-"
    // markers app_inventory_operational_stock_sql() (inventory-module-lib.php)
    // filters out of every real inventory metric -- app_advisor_techops_briefing()
    // reads through that exact canonical filter, so a fixture named with
    // "smoke" in it would silently never be counted as critical stock at all
    // (found the hard way: an earlier version of this fixture used "smoke" in
    // the name and the dry-run simply never flagged it). ---
    require_once dirname(__DIR__) . '/advisor-agent-lib.php';
    $currentCriticalStock = (int) (app_advisor_read_model_critical_stock_count($db) ?? 0);
    $fixtureRun = date('His') . random_int(100, 999);
    $fixturesNeeded = max(1, 5 - $currentCriticalStock);
    for ($i = 0; $i < $fixturesNeeded; $i++) {
        $sku = 'AITEAMAGENDAQA-' . $fixtureRun . '-' . $i;
        $db->prepare(
            'INSERT INTO inventory_stock_items (sku, item_name, category, unit, quantity_on_hand, minimum_quantity, status, created_at)
             VALUES (:sku, "AI Team Agenda Fixture Item", "ai-team-agenda-qa", "ədəd", 0, 5, "active", NOW())'
        )->execute(['sku' => $sku]);
        $stockItemIds[] = (int) $db->lastInsertId();
    }

    // NOTE: this runs against the real dev DB, which may have its own
    // currently-qualifying real proposals (e.g. a genuine open-ticket
    // backlog) -- an --apply run below will legitimately stage THOSE too,
    // not just this test's fixture. Track every id that exists before vs.
    // after so cleanup removes everything this test run caused, not just
    // the two ids we assert on.
    $idsBefore = array_map('intval', $db->query('SELECT id FROM ai_team_tasks')->fetchAll(PDO::FETCH_COLUMN) ?: []);

    // --- Dry run: must report the fact as "would_stage" for both personas,
    //     write nothing. ---
    $dryRun = app_ai_team_run_daily_agenda($db, false);
    if (!isset($dryRun['warehouse_keeper'], $dryRun['ceo'])) {
        ai_team_daily_agenda_smoke_fail('dry_run_missing_expected_personas');
    }
    $findCriticalStockProposal = static function (array $wouldStage): ?array {
        foreach ($wouldStage as $candidate) {
            if (str_contains((string) ($candidate['summary'] ?? ''), 'minimum həddə')) {
                return $candidate;
            }
        }
        return null;
    };
    $warehouseCandidate = $findCriticalStockProposal($dryRun['warehouse_keeper']['would_stage']);
    $ceoCandidate = $findCriticalStockProposal($dryRun['ceo']['would_stage']);
    if ($warehouseCandidate === null) {
        ai_team_daily_agenda_smoke_fail('dry_run_did_not_flag_critical_stock_for_warehouse_keeper');
    }
    if ($ceoCandidate === null) {
        ai_team_daily_agenda_smoke_fail('dry_run_did_not_flag_critical_stock_for_ceo');
    }
    if ($warehouseCandidate['proposal_id'] !== $ceoCandidate['proposal_id']) {
        ai_team_daily_agenda_smoke_fail('warehouse_and_ceo_proposal_ids_unexpectedly_differ_fixture_assumption_wrong');
    }

    // Dry run must never write anything.
    $idsAfterDryRun = array_map('intval', $db->query('SELECT id FROM ai_team_tasks')->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($idsAfterDryRun !== $idsBefore) {
        ai_team_daily_agenda_smoke_fail('dry_run_unexpectedly_wrote_rows');
    }

    // --- Apply: BOTH personas must get their OWN staged task for the same
    //     underlying proposal_id (the exact bug this migration/fix addresses). ---
    $applyResult = app_ai_team_run_daily_agenda($db, true);
    // Track every row this apply call created BEFORE any assertion that
    // could throw -- otherwise a failing assertion below leaves real staged
    // rows behind uncleaned (found the hard way while debugging this test:
    // several failed runs each left their --apply writes in the table since
    // cleanup only knew about ids captured after the assertions that kept
    // failing).
    $idsAfterFirstApply = array_map('intval', $db->query('SELECT id FROM ai_team_tasks')->fetchAll(PDO::FETCH_COLUMN) ?: []);
    foreach (array_diff($idsAfterFirstApply, $idsBefore) as $newId) {
        $taskIds[] = (int) $newId;
    }

    $warehouseStaged = $applyResult['warehouse_keeper']['staged_task_ids'];
    $ceoStaged = $applyResult['ceo']['staged_task_ids'];
    if (count($warehouseStaged) < 1) {
        ai_team_daily_agenda_smoke_fail('apply_did_not_stage_warehouse_keeper_task');
    }
    if (count($ceoStaged) < 1) {
        ai_team_daily_agenda_smoke_fail('apply_did_not_stage_ceo_task_cross_persona_dedup_regression');
    }

    // Look up the specific critical_stock row per persona by proposal_type
    // rather than assuming array position -- both personas may stage more
    // than one task this run (real dev-DB proposals besides this fixture).
    $warehouseRow = $db->prepare('SELECT persona_key, proposal_id, status, staged_by FROM ai_team_tasks WHERE id IN (' . implode(',', array_map('intval', $warehouseStaged)) . ') AND proposal_type = "critical_stock" LIMIT 1');
    $warehouseRow->execute();
    $warehouseData = $warehouseRow->fetch(PDO::FETCH_ASSOC);
    $ceoRow = $db->prepare('SELECT persona_key, proposal_id, status, staged_by FROM ai_team_tasks WHERE id IN (' . implode(',', array_map('intval', $ceoStaged)) . ') AND proposal_type = "critical_stock" LIMIT 1');
    $ceoRow->execute();
    $ceoData = $ceoRow->fetch(PDO::FETCH_ASSOC);
    if (!is_array($warehouseData) || (string) $warehouseData['persona_key'] !== 'warehouse_keeper' || (string) $warehouseData['staged_by'] !== 'cron') {
        ai_team_daily_agenda_smoke_fail('warehouse_task_row_wrong:' . json_encode($warehouseData, JSON_UNESCAPED_SLASHES));
    }
    if (!is_array($ceoData) || (string) $ceoData['persona_key'] !== 'ceo' || (string) $ceoData['staged_by'] !== 'cron') {
        ai_team_daily_agenda_smoke_fail('ceo_task_row_wrong:' . json_encode($ceoData, JSON_UNESCAPED_SLASHES));
    }
    if ((string) $warehouseData['proposal_id'] === '' || (string) $warehouseData['proposal_id'] !== (string) $ceoData['proposal_id']) {
        ai_team_daily_agenda_smoke_fail('staged_rows_proposal_id_mismatch');
    }

    // --- Idempotency: a second apply run must stage nothing new at all
    //     (covers this test's fixture AND any real dev-DB proposal this run
    //     also picked up). ---
    app_ai_team_run_daily_agenda($db, true);
    $idsAfterSecondApply = array_map('intval', $db->query('SELECT id FROM ai_team_tasks')->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($idsAfterSecondApply !== $idsAfterFirstApply) {
        ai_team_daily_agenda_smoke_fail('second_apply_run_was_not_idempotent');
    }

    // --- The unique index itself must reject a literal duplicate (defense in
    //     depth against a race between two overlapping cron runs). ---
    try {
        $db->prepare(
            'INSERT INTO ai_team_tasks (persona_key, proposal_type, proposal_id, title, description, reasoning, risk, status, staged_by)
             VALUES (:persona_key, "critical_stock", :proposal_id, "dup", "dup", "dup", "high", "pending_admin_review", "cron")'
        )->execute(['persona_key' => 'warehouse_keeper', 'proposal_id' => $warehouseData['proposal_id']]);
        ai_team_daily_agenda_smoke_fail('unique_index_did_not_reject_a_literal_duplicate_proposal_persona_pair');
    } catch (PDOException $exception) {
        if (!str_contains(strtolower($exception->getMessage()), 'duplicate') && !str_contains(strtolower($exception->getMessage()), 'unique')) {
            ai_team_daily_agenda_smoke_fail('unexpected_pdo_error_for_duplicate_insert:' . $exception->getMessage());
        }
    }

    // --- Worker script itself: CLI-only guard, --apply flag, dry-run default. ---
    $workerSource = (string) file_get_contents(__DIR__ . '/ai-team-daily-agenda.php');
    if (!str_contains($workerSource, "PHP_SAPI !== 'cli'")) {
        ai_team_daily_agenda_smoke_fail('worker_missing_cli_only_guard');
    }
    if (!str_contains($workerSource, "'--apply'")) {
        ai_team_daily_agenda_smoke_fail('worker_missing_apply_flag');
    }
    if (!str_contains($workerSource, 'app_ai_team_run_daily_agenda($db, $apply)')) {
        ai_team_daily_agenda_smoke_fail('worker_not_calling_shared_agenda_function');
    }
    // The worker must ALSO record a heartbeat row every invocation, otherwise a
    // run that stages zero tasks leaves no evidence it ever ran.
    if (!str_contains($workerSource, 'app_ai_team_record_agenda_run(')) {
        ai_team_daily_agenda_smoke_fail('worker_not_recording_agenda_run_heartbeat');
    }

    // --- Heartbeat log + admin "run now": a non-admin is rejected, an admin run
    //     is idempotent here (fixtures already staged above), and it writes ONE
    //     triggered_by='admin' row that app_ai_team_last_agenda_run() surfaces. ---
    $runIdsBefore = array_map('intval', $db->query('SELECT id FROM ai_team_agenda_runs')->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $nonAdminActor = ['id' => 0, 'employee_role' => 'operator', 'account_type' => 'employee'];
    $nonAdminRejected = false;
    try {
        app_ai_team_run_daily_agenda_now($db, $nonAdminActor);
    } catch (Throwable $exception) {
        $nonAdminRejected = ($exception->getMessage() === 'ai_team_forbidden');
    }
    if (!$nonAdminRejected) {
        ai_team_daily_agenda_smoke_fail('non_admin_was_not_rejected_from_run_now');
    }

    $adminActor = ['id' => 0, 'full_name' => 'AI Team Agenda QA Admin', 'employee_role' => 'super_admin', 'account_type' => 'admin'];
    $adminRun = app_ai_team_run_daily_agenda_now($db, $adminActor);
    if (empty($adminRun['ok']) || (int) ($adminRun['total_staged'] ?? -1) !== 0) {
        ai_team_daily_agenda_smoke_fail('admin_run_now_was_not_idempotent:' . json_encode($adminRun, JSON_UNESCAPED_SLASHES));
    }

    $runIdsAfter = array_map('intval', $db->query('SELECT id FROM ai_team_agenda_runs')->fetchAll(PDO::FETCH_COLUMN) ?: []);
    foreach (array_diff($runIdsAfter, $runIdsBefore) as $newRunId) {
        $agendaRunIds[] = (int) $newRunId;
    }
    // Exactly the admin run above should have added a heartbeat (non-admin threw
    // before reaching the record call).
    if (count($agendaRunIds) !== 1) {
        ai_team_daily_agenda_smoke_fail('unexpected_heartbeat_row_count:' . count($agendaRunIds));
    }

    $lastRun = app_ai_team_last_agenda_run($db);
    if (!is_array($lastRun)
        || (int) $lastRun['id'] !== $agendaRunIds[0]
        || (string) $lastRun['triggered_by'] !== 'admin'
        || (string) $lastRun['mode'] !== 'apply'
        || (int) $lastRun['total_staged'] !== 0
        || (string) $lastRun['actor_name'] !== 'AI Team Agenda QA Admin') {
        ai_team_daily_agenda_smoke_fail('last_agenda_run_row_wrong:' . json_encode($lastRun, JSON_UNESCAPED_SLASHES));
    }

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'shared_proposal_id_prefix' => substr((string) $warehouseData['proposal_id'], 0, 12),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
