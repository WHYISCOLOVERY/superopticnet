<?php
declare(strict_types=1);

require dirname(__DIR__) . '/business-rules-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

// NOTE: PHP does not run try{}finally{} blocks after exit()/die() inside the
// try body -- every failure path below throws instead of exiting directly.
class BusinessRulesSmokeFailure extends RuntimeException
{
}

function business_rules_smoke_fail(string $message, int $code = 1): never
{
    throw new BusinessRulesSmokeFailure($message, $code);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$stamp = date('His') . random_int(100, 999);
$ruleKey = 'smoke-rule-' . strtolower($stamp);
$actor = ['id' => 0, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'super_admin', 'full_name' => 'Business Rules Smoke'];

$cleanup = function () use ($db, $ruleKey): void {
    $db->prepare('DELETE FROM app_business_rule_history WHERE rule_key = :key')->execute(['key' => $ruleKey]);
    $db->prepare('DELETE FROM app_business_rules WHERE rule_key = :key')->execute(['key' => $ruleKey]);
};

try {
    $cleanup();
    $db->prepare(
        'INSERT INTO app_business_rules (rule_key, category, value_type, value_text, min_value, max_value, title, description)
         VALUES (:rule_key, "general", "int", "10", 1, 100, "Smoke rule", "smoke fixture")'
    )->execute(['rule_key' => $ruleKey]);

    // 1. Read default.
    if ((int) app_business_rule_get($db, $ruleKey, -1) !== 10) {
        business_rules_smoke_fail('initial_value_mismatch');
    }

    // 2. Reason is mandatory.
    $noReason = app_business_rule_set($db, $ruleKey, '20', $actor, '');
    if (!empty($noReason['ok']) || ($noReason['reason'] ?? '') !== 'reason_required') {
        business_rules_smoke_fail('reason_required_not_enforced');
    }

    // 3. Type validation.
    $notInt = app_business_rule_set($db, $ruleKey, 'abc', $actor, 'type check');
    if (!empty($notInt['ok']) || ($notInt['reason'] ?? '') !== 'value_must_be_integer') {
        business_rules_smoke_fail('integer_type_not_enforced');
    }

    // 4. Bounds validation (min=1, max=100).
    $tooHigh = app_business_rule_set($db, $ruleKey, '500', $actor, 'bounds check');
    if (!empty($tooHigh['ok']) || ($tooHigh['reason'] ?? '') !== 'value_above_maximum') {
        business_rules_smoke_fail('max_bound_not_enforced');
    }
    $tooLow = app_business_rule_set($db, $ruleKey, '0', $actor, 'bounds check');
    if (!empty($tooLow['ok']) || ($tooLow['reason'] ?? '') !== 'value_below_minimum') {
        business_rules_smoke_fail('min_bound_not_enforced');
    }

    // 5. Unchanged value is rejected (not a silent no-op success).
    $unchanged = app_business_rule_set($db, $ruleKey, '10', $actor, 'no-op check');
    if (!empty($unchanged['ok']) || ($unchanged['reason'] ?? '') !== 'value_unchanged') {
        business_rules_smoke_fail('unchanged_value_not_rejected');
    }

    // 6. Real change: succeeds, is readable immediately, and is history-logged.
    $changed = app_business_rule_set($db, $ruleKey, '25', $actor, 'real change');
    if (empty($changed['ok'])) {
        business_rules_smoke_fail('valid_change_rejected:' . json_encode($changed, JSON_UNESCAPED_SLASHES));
    }
    if ((int) app_business_rule_get($db, $ruleKey, -1) !== 25) {
        business_rules_smoke_fail('value_not_updated_after_set');
    }
    $history = app_business_rule_history($db, $ruleKey);
    if (count($history) !== 1
        || (string) ($history[0]['previous_value_text'] ?? '') !== '10'
        || (string) ($history[0]['new_value_text'] ?? '') !== '25'
        || (string) ($history[0]['reason'] ?? '') !== 'real change'
        || !empty($history[0]['is_rollback'])) {
        business_rules_smoke_fail('history_entry_incorrect:' . json_encode($history, JSON_UNESCAPED_SLASHES));
    }
    $historyId = (int) $history[0]['id'];

    // 7. Rollback restores the previous value, is flagged is_rollback, and
    //    ADDS a new history row rather than deleting/mutating the original
    //    (reversal, not destructive undo -- matches the rest of this codebase).
    $rollback = app_business_rule_rollback($db, $ruleKey, $historyId, $actor, 'undo real change');
    if (empty($rollback['ok'])) {
        business_rules_smoke_fail('rollback_failed:' . json_encode($rollback, JSON_UNESCAPED_SLASHES));
    }
    if ((int) app_business_rule_get($db, $ruleKey, -1) !== 10) {
        business_rules_smoke_fail('rollback_did_not_restore_previous_value');
    }
    $historyAfterRollback = app_business_rule_history($db, $ruleKey);
    if (count($historyAfterRollback) !== 2 || empty($historyAfterRollback[0]['is_rollback'])) {
        business_rules_smoke_fail('rollback_not_recorded_as_new_history_entry');
    }

    // 8. Rollback with no reason is rejected too.
    $rollbackNoReason = app_business_rule_rollback($db, $ruleKey, $historyId, $actor, '');
    if (!empty($rollbackNoReason['ok'])) {
        business_rules_smoke_fail('rollback_reason_not_enforced');
    }

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'rule_key' => $ruleKey,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit($exception->getCode() ?: 1);
}
