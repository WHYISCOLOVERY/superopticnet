<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Minimal versioned business-rules engine (docs vision §15). Deliberately
 * narrow: a handful of thresholds that were previously hardcoded literals
 * scattered across billing/marketing/AI code, now in one admin-editable,
 * audited table. Not a generic policy DSL -- each rule is just a typed
 * value with mandatory-reason change history and rollback. Extend the seed
 * list in the migration when a new threshold earns a spot here; don't try
 * to migrate every constant in the codebase at once.
 */

function app_business_rule_row(PDO $db, string $key): ?array
{
    $statement = $db->prepare('SELECT * FROM app_business_rules WHERE rule_key = :rule_key LIMIT 1');
    $statement->execute(['rule_key' => trim($key)]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function app_business_rule_cast(array $row): int|float|string|bool
{
    $text = (string) ($row['value_text'] ?? '');
    return match ((string) ($row['value_type'] ?? 'string')) {
        'int' => (int) $text,
        'decimal' => (float) $text,
        'bool' => in_array(strtolower($text), ['1', 'true', 'yes', 'on'], true),
        default => $text,
    };
}

/**
 * @param int|float|string|bool $default returned as-is (not type-coerced) if the rule row is missing
 */
function app_business_rule_get(PDO $db, string $key, int|float|string|bool $default): int|float|string|bool
{
    $row = app_business_rule_row($db, $key);
    return $row !== null ? app_business_rule_cast($row) : $default;
}

function app_business_rules_list(PDO $db, string $category = ''): array
{
    if ($category !== '') {
        $statement = $db->prepare('SELECT * FROM app_business_rules WHERE category = :category ORDER BY rule_key');
        $statement->execute(['category' => $category]);
    } else {
        $statement = $db->query('SELECT * FROM app_business_rules ORDER BY category, rule_key');
    }
    return $statement !== false ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function app_business_rule_history(PDO $db, string $key, int $limit = 20): array
{
    $statement = $db->prepare(
        'SELECT * FROM app_business_rule_history WHERE rule_key = :rule_key ORDER BY id DESC LIMIT :limit_rows'
    );
    $statement->bindValue(':rule_key', trim($key));
    $statement->bindValue(':limit_rows', max(1, min(100, $limit)), PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function app_business_rule_validate(array $row, string $newValueText): ?string
{
    $type = (string) ($row['value_type'] ?? 'string');
    if ($type === 'int' && !preg_match('/^-?\d+$/', $newValueText)) {
        return 'value_must_be_integer';
    }
    if ($type === 'decimal' && !is_numeric($newValueText)) {
        return 'value_must_be_decimal';
    }
    if ($type === 'bool' && !in_array(strtolower($newValueText), ['0', '1', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
        return 'value_must_be_boolean';
    }
    if (in_array($type, ['int', 'decimal'], true)) {
        $numeric = (float) $newValueText;
        if ($row['min_value'] !== null && $numeric < (float) $row['min_value']) {
            return 'value_below_minimum';
        }
        if ($row['max_value'] !== null && $numeric > (float) $row['max_value']) {
            return 'value_above_maximum';
        }
    }
    return null;
}

/**
 * @return array{ok:bool, reason?:string}
 */
function app_business_rule_set(PDO $db, string $key, string $newValueText, array $actor, string $reason): array
{
    $key = trim($key);
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'reason' => 'reason_required'];
    }
    $row = app_business_rule_row($db, $key);
    if ($row === null) {
        return ['ok' => false, 'reason' => 'rule_not_found'];
    }
    $newValueText = trim($newValueText);
    $validationError = app_business_rule_validate($row, $newValueText);
    if ($validationError !== null) {
        return ['ok' => false, 'reason' => $validationError];
    }
    if ($newValueText === (string) $row['value_text']) {
        return ['ok' => false, 'reason' => 'value_unchanged'];
    }

    return app_business_rule_apply($db, $row, $newValueText, $actor, $reason, false);
}

/**
 * @return array{ok:bool, reason?:string}
 */
function app_business_rule_rollback(PDO $db, string $key, int $historyId, array $actor, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'reason' => 'reason_required'];
    }
    $row = app_business_rule_row($db, $key);
    if ($row === null) {
        return ['ok' => false, 'reason' => 'rule_not_found'];
    }
    $historyStatement = $db->prepare('SELECT * FROM app_business_rule_history WHERE id = :id AND rule_key = :rule_key LIMIT 1');
    $historyStatement->execute(['id' => $historyId, 'rule_key' => $key]);
    $historyRow = $historyStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($historyRow)) {
        return ['ok' => false, 'reason' => 'history_entry_not_found'];
    }
    $targetValue = (string) ($historyRow['previous_value_text'] ?? '');
    $validationError = app_business_rule_validate($row, $targetValue);
    if ($validationError !== null) {
        return ['ok' => false, 'reason' => $validationError];
    }

    return app_business_rule_apply($db, $row, $targetValue, $actor, $reason, true);
}

/**
 * @return array{ok:bool}
 */
function app_business_rule_apply(PDO $db, array $row, string $newValueText, array $actor, string $reason, bool $isRollback): array
{
    $key = (string) $row['rule_key'];
    $actorId = (int) ($actor['id'] ?? 0) ?: null;
    $actorName = clean_text((string) ($actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'system'));

    $db->beginTransaction();
    try {
        $db->prepare(
            'UPDATE app_business_rules
                SET value_text = :value_text, updated_by_account_id = :actor_id, updated_by_name = :actor_name
              WHERE rule_key = :rule_key'
        )->execute([
            'value_text' => $newValueText,
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'rule_key' => $key,
        ]);
        $db->prepare(
            'INSERT INTO app_business_rule_history (
                rule_key, previous_value_text, new_value_text, reason, is_rollback, changed_by_account_id, changed_by_name
             ) VALUES (
                :rule_key, :previous_value_text, :new_value_text, :reason, :is_rollback, :actor_id, :actor_name
             )'
        )->execute([
            'rule_key' => $key,
            'previous_value_text' => (string) $row['value_text'],
            'new_value_text' => $newValueText,
            'reason' => mb_substr($reason, 0, 255),
            'is_rollback' => $isRollback ? 1 : 0,
            'actor_id' => $actorId,
            'actor_name' => $actorName,
        ]);
        $db->commit();
        if (function_exists('app_audit_record')) {
            try {
                app_audit_record($db, [
                    'module_key' => 'system',
                    'operation_class' => 'business_rule',
                    'action_type' => $isRollback ? 'business_rule_rollback' : 'business_rule_changed',
                    'entity_type' => 'business_rule',
                    'entity_id' => $key,
                    'status' => 'executed',
                    'actor_account_id' => $actorId,
                    'actor_name' => $actorName,
                    'summary' => 'Business rule ' . $key . ' changed',
                    'reason' => $reason,
                    'before' => ['value' => $row['value_text']],
                    'after' => ['value' => $newValueText],
                    'sensitivity' => 'normal',
                ]);
            } catch (Throwable) {
                // Audit failure must not block a rule change that already committed.
            }
        }
        return ['ok' => true];
    } catch (Throwable) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'reason' => 'business_rule_write_failed'];
    }
}
