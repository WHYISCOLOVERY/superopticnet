<?php
declare(strict_types=1);

function app_finance_operational_schema_tables(): array
{
    return [
        'finance_asset_depreciations',
        'finance_counterparties',
        'finance_debt_ledger',
        'finance_debt_payments',
        'finance_e_invoice_imports',
        'finance_entries',
    ];
}

function app_bonus_schema_tables(): array
{
    return [
        'bonus_rule_settings',
        'bonus_base_salary_tiers',
        'bonus_active_day_brackets',
        'bonus_quality_rules',
        'customer_employee_assignments',
        'bonus_periods',
        'employee_bonus_summaries',
        'employee_bonus_items',
        'employee_bonus_adjustments',
        'employee_bonus_notifications',
        'bonus_audit_logs',
    ];
}

function app_finance_schema_tables(): array
{
    return array_merge(app_finance_operational_schema_tables(), app_bonus_schema_tables());
}

function app_finance_schema_missing_tables(PDO $db, array $tables): array
{
    $tables = array_values(array_unique(array_filter(array_map('strval', $tables))));
    if ($tables === []) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach ($tables as $index => $table) {
        $key = 'table_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $table;
    }

    $statement = $db->prepare(
        'SELECT TABLE_NAME
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN (' . implode(',', $placeholders) . ')'
    );
    $statement->execute($params);
    $present = array_fill_keys(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []), true);

    return array_values(array_filter(
        $tables,
        static fn (string $table): bool => !isset($present[$table])
    ));
}

function app_finance_require_operational_schema(PDO $db): void
{
    $missing = app_finance_schema_missing_tables($db, app_finance_operational_schema_tables());
    if ($missing !== []) {
        throw new RuntimeException('finance_schema_not_migrated:' . implode(',', $missing));
    }
}

function app_bonus_require_schema(PDO $db): void
{
    $missing = app_finance_schema_missing_tables($db, app_finance_schema_tables());
    if ($missing !== []) {
        throw new RuntimeException('finance_bonus_schema_not_migrated:' . implode(',', $missing));
    }
}
