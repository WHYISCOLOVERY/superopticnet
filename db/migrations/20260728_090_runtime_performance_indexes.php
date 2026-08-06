<?php
declare(strict_types=1);

const APP_INDEX_MIGRATION_20260728_090 = '20260728_090_runtime_performance_indexes';

function app_index_migration_table_exists_20260728_090(PDO $db, string $table): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $statement->execute(['table_name' => $table]);
    return (int) $statement->fetchColumn() > 0;
}

function app_index_migration_columns_exist_20260728_090(PDO $db, string $table, array $columns): bool
{
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME IN (' . $placeholders . ')'
    );
    $statement->execute(array_merge([$table], array_values($columns)));
    return (int) $statement->fetchColumn() === count($columns);
}

function app_index_migration_index_exists_20260728_090(PDO $db, string $table, string $index): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = :table_name
            AND INDEX_NAME = :index_name'
    );
    $statement->execute(['table_name' => $table, 'index_name' => $index]);
    return (int) $statement->fetchColumn() > 0;
}

function app_index_migration_definitions_20260728_090(): array
{
    return [
        ['portal_accounts', 'idx_portal_accounts_role_updated_id', ['role', 'updated_at', 'id']],
        ['portal_accounts', 'idx_portal_accounts_role_active_status', ['role', 'is_active', 'status']],
        ['billing_radius_services', 'idx_billing_radius_services_account_status_id', ['account_id', 'status', 'id']],
        ['billing_radius_services', 'idx_billing_radius_services_updated', ['updated_at', 'id']],
        ['billing_payments', 'idx_billing_payments_created_id', ['created_at', 'id']],
        ['billing_payments', 'idx_billing_payments_method_created', ['payment_method', 'created_at']],
        ['communication_messages', 'idx_communication_messages_status_created', ['status', 'created_at', 'id']],
        ['portal_tickets', 'idx_portal_tickets_status_opened', ['status', 'opened_at', 'id']],
        ['radacct', 'idx_radacct_online_recent', ['acctstoptime', 'acctstarttime', 'username']],
        ['radius_nat_logs', 'idx_radius_nat_logs_logged_id', ['logged_at', 'id']],
        ['radius_nat_logs', 'idx_radius_nat_logs_subscriber_logged', ['subscriber_code', 'logged_at']],
        ['mgs_operation_actions', 'idx_mgs_operation_status_created', ['status', 'created_at', 'id']],
        ['security_anomaly_events', 'idx_security_anomaly_status', ['status', 'severity', 'last_seen_at']],
    ];
}

return [
    'version' => APP_INDEX_MIGRATION_20260728_090,
    'description' => 'Create portal performance indexes outside recurring runtime maintenance',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS portal_managed_indexes (
                migration_version VARCHAR(96) NOT NULL,
                table_name VARCHAR(64) NOT NULL,
                index_name VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (migration_version, table_name, index_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $record = $db->prepare(
            'INSERT IGNORE INTO portal_managed_indexes (migration_version, table_name, index_name)
             VALUES (:migration_version, :table_name, :index_name)'
        );
        foreach (app_index_migration_definitions_20260728_090() as [$table, $index, $columns]) {
            if (!app_index_migration_table_exists_20260728_090($db, $table)
                || !app_index_migration_columns_exist_20260728_090($db, $table, $columns)
                || app_index_migration_index_exists_20260728_090($db, $table, $index)) {
                continue;
            }
            $quotedColumns = implode(',', array_map(
                static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`',
                $columns
            ));
            $db->exec(
                'CREATE INDEX `' . str_replace('`', '``', $index) . '` ON `'
                . str_replace('`', '``', $table) . '` (' . $quotedColumns . ')'
            );
            $record->execute([
                'migration_version' => APP_INDEX_MIGRATION_20260728_090,
                'table_name' => $table,
                'index_name' => $index,
            ]);
        }
    },
    'down' => static function (PDO $db): void {
        if (!app_index_migration_table_exists_20260728_090($db, 'portal_managed_indexes')) {
            return;
        }
        $statement = $db->prepare(
            'SELECT table_name, index_name
               FROM portal_managed_indexes
              WHERE migration_version = :migration_version
              ORDER BY table_name DESC, index_name DESC'
        );
        $statement->execute(['migration_version' => APP_INDEX_MIGRATION_20260728_090]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $table = (string) ($row['table_name'] ?? '');
            $index = (string) ($row['index_name'] ?? '');
            if ($table !== '' && $index !== ''
                && app_index_migration_table_exists_20260728_090($db, $table)
                && app_index_migration_index_exists_20260728_090($db, $table, $index)) {
                $db->exec(
                    'DROP INDEX `' . str_replace('`', '``', $index) . '` ON `'
                    . str_replace('`', '``', $table) . '`'
                );
            }
        }
        $delete = $db->prepare(
            'DELETE FROM portal_managed_indexes WHERE migration_version = :migration_version'
        );
        $delete->execute(['migration_version' => APP_INDEX_MIGRATION_20260728_090]);
    },
];
