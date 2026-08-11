<?php
declare(strict_types=1);

const APP_STAGE1_MIGRATION_20260723_020 = '20260723_020_stage1_service_data_contracts';

function app_stage1_table_exists(PDO $db, string $table): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $statement->execute(['table_name' => $table]);
    return (int) $statement->fetchColumn() > 0;
}

function app_stage1_column_exists(PDO $db, string $table, string $column): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = :table_name
            AND COLUMN_NAME = :column_name'
    );
    $statement->execute(['table_name' => $table, 'column_name' => $column]);
    return (int) $statement->fetchColumn() > 0;
}

function app_stage1_index_exists(PDO $db, string $table, string $index): bool
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

function app_stage1_add_column(PDO $db, string $table, string $column, string $definition): void
{
    if (!app_stage1_column_exists($db, $table, $column)) {
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function app_stage1_drop_column(PDO $db, string $table, string $column): void
{
    if (app_stage1_column_exists($db, $table, $column)) {
        $db->exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
    }
}

function app_stage1_add_index(PDO $db, string $table, string $index, string $definition): void
{
    if (!app_stage1_index_exists($db, $table, $index)) {
        $db->exec("ALTER TABLE `{$table}` ADD {$definition}");
    }
}

function app_stage1_drop_index(PDO $db, string $table, string $index): void
{
    if (app_stage1_index_exists($db, $table, $index)) {
        $db->exec("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
    }
}

return [
    'version' => APP_STAGE1_MIGRATION_20260723_020,
    'description' => 'Add Stage 1 finance, e-invoice and inventory service data contracts',
    'up' => static function (PDO $db): void {
        if (!app_stage1_table_exists($db, 'finance_entries')
            || !app_stage1_table_exists($db, 'finance_e_invoice_imports')) {
            throw new RuntimeException('stage1_finance_baseline_missing');
        }

        app_stage1_add_column($db, 'finance_entries', 'source_type', "VARCHAR(64) NOT NULL DEFAULT 'manual' AFTER `status`");
        app_stage1_add_column($db, 'finance_entries', 'source_id', 'VARCHAR(128) DEFAULT NULL AFTER `source_type`');
        app_stage1_add_column($db, 'finance_entries', 'employee_account_id', 'BIGINT UNSIGNED DEFAULT NULL AFTER `source_id`');
        app_stage1_add_column($db, 'finance_entries', 'idempotency_key', 'VARCHAR(191) DEFAULT NULL AFTER `employee_account_id`');
        app_stage1_add_column($db, 'finance_entries', 'correlation_id', 'VARCHAR(96) DEFAULT NULL AFTER `idempotency_key`');
        app_stage1_add_column($db, 'finance_entries', 'payload_json', 'LONGTEXT DEFAULT NULL AFTER `correlation_id`');
        app_stage1_add_column(
            $db,
            'finance_entries',
            'updated_at',
            'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`'
        );
        app_stage1_add_index(
            $db,
            'finance_entries',
            'uq_finance_entry_source',
            'UNIQUE KEY `uq_finance_entry_source` (`source_type`, `source_id`)'
        );
        app_stage1_add_index(
            $db,
            'finance_entries',
            'uq_finance_entry_idempotency',
            'UNIQUE KEY `uq_finance_entry_idempotency` (`idempotency_key`)'
        );
        app_stage1_add_index(
            $db,
            'finance_entries',
            'idx_finance_entry_employee_date',
            'KEY `idx_finance_entry_employee_date` (`employee_account_id`, `transaction_date`)'
        );

        app_stage1_add_column($db, 'finance_debt_ledger', 'source_document_key', 'CHAR(64) DEFAULT NULL AFTER `payload_json`');
        app_stage1_add_column($db, 'finance_debt_ledger', 'finance_entry_id', 'BIGINT UNSIGNED DEFAULT NULL AFTER `source_document_key`');
        app_stage1_add_column($db, 'finance_debt_ledger', 'correlation_id', 'VARCHAR(96) DEFAULT NULL AFTER `finance_entry_id`');
        app_stage1_add_index(
            $db,
            'finance_debt_ledger',
            'uq_finance_debt_source_document',
            'UNIQUE KEY `uq_finance_debt_source_document` (`source_document_key`)'
        );
        app_stage1_add_index(
            $db,
            'finance_debt_ledger',
            'idx_finance_debt_entry',
            'KEY `idx_finance_debt_entry` (`finance_entry_id`)'
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS finance_e_invoice_batches (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                batch_key VARCHAR(96) NOT NULL,
                request_hash CHAR(64) NOT NULL,
                correlation_id VARCHAR(96) DEFAULT NULL,
                status ENUM('processing','completed','failed') NOT NULL DEFAULT 'processing',
                input_row_count INT UNSIGNED NOT NULL DEFAULT 0,
                imported_row_count INT UNSIGNED NOT NULL DEFAULT 0,
                error_code VARCHAR(96) DEFAULT NULL,
                created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                created_by_name VARCHAR(191) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_finance_e_invoice_batch_key (batch_key),
                UNIQUE KEY uq_finance_e_invoice_request_hash (request_hash),
                KEY idx_finance_e_invoice_batch_status (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        app_stage1_add_column($db, 'finance_e_invoice_imports', 'row_hash', 'CHAR(64) DEFAULT NULL AFTER `import_batch`');
        app_stage1_add_column($db, 'finance_e_invoice_imports', 'document_key', 'CHAR(64) DEFAULT NULL AFTER `row_hash`');
        app_stage1_add_column($db, 'finance_e_invoice_imports', 'finance_entry_id', 'BIGINT UNSIGNED DEFAULT NULL AFTER `document_key`');
        app_stage1_add_column($db, 'finance_e_invoice_imports', 'correlation_id', 'VARCHAR(96) DEFAULT NULL AFTER `finance_entry_id`');
        app_stage1_add_column($db, 'finance_e_invoice_imports', 'error_code', 'VARCHAR(96) DEFAULT NULL AFTER `status`');
        app_stage1_add_index(
            $db,
            'finance_e_invoice_imports',
            'uq_finance_e_invoice_batch_row',
            'UNIQUE KEY `uq_finance_e_invoice_batch_row` (`import_batch`, `row_hash`)'
        );
        app_stage1_add_index(
            $db,
            'finance_e_invoice_imports',
            'uq_finance_e_invoice_document',
            'UNIQUE KEY `uq_finance_e_invoice_document` (`document_key`)'
        );

        app_stage1_add_column($db, 'inventory_asset_returns', 'idempotency_key', 'VARCHAR(191) DEFAULT NULL AFTER `metadata_json`');
        app_stage1_add_column($db, 'inventory_asset_returns', 'correlation_id', 'VARCHAR(96) DEFAULT NULL AFTER `idempotency_key`');
        app_stage1_add_index(
            $db,
            'inventory_asset_returns',
            'uq_inventory_asset_return_idempotency',
            'UNIQUE KEY `uq_inventory_asset_return_idempotency` (`idempotency_key`)'
        );
        app_stage1_add_column($db, 'inventory_asset_inspections', 'idempotency_key', 'VARCHAR(191) DEFAULT NULL AFTER `metadata_json`');
        app_stage1_add_column($db, 'inventory_asset_inspections', 'correlation_id', 'VARCHAR(96) DEFAULT NULL AFTER `idempotency_key`');
        app_stage1_add_index(
            $db,
            'inventory_asset_inspections',
            'uq_inventory_asset_inspection_return',
            'UNIQUE KEY `uq_inventory_asset_inspection_return` (`return_id`)'
        );
        app_stage1_add_index(
            $db,
            'inventory_asset_inspections',
            'uq_inventory_asset_inspection_idempotency',
            'UNIQUE KEY `uq_inventory_asset_inspection_idempotency` (`idempotency_key`)'
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS inventory_asset_repairs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                return_id BIGINT UNSIGNED NOT NULL,
                inspection_id BIGINT UNSIGNED NOT NULL,
                asset_id BIGINT UNSIGNED NOT NULL,
                repair_status ENUM('pending','in_repair','repaired','rma','scrapped','cancelled') NOT NULL DEFAULT 'pending',
                vendor_name VARCHAR(191) DEFAULT NULL,
                external_reference VARCHAR(128) DEFAULT NULL,
                reason VARCHAR(255) NOT NULL,
                notes TEXT DEFAULT NULL,
                idempotency_key VARCHAR(191) NOT NULL,
                correlation_id VARCHAR(96) DEFAULT NULL,
                created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                created_by_name VARCHAR(191) DEFAULT NULL,
                updated_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                updated_by_name VARCHAR(191) DEFAULT NULL,
                completed_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_inventory_asset_repair_inspection (inspection_id),
                UNIQUE KEY uq_inventory_asset_repair_idempotency (idempotency_key),
                KEY idx_inventory_asset_repair_asset (asset_id, repair_status),
                KEY idx_inventory_asset_repair_return (return_id, repair_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS inventory_asset_repair_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                repair_id BIGINT UNSIGNED NOT NULL,
                previous_status VARCHAR(32) DEFAULT NULL,
                new_status VARCHAR(32) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                idempotency_key VARCHAR(191) NOT NULL,
                correlation_id VARCHAR(96) DEFAULT NULL,
                actor_account_id BIGINT UNSIGNED DEFAULT NULL,
                actor_name VARCHAR(191) DEFAULT NULL,
                metadata_json LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_inventory_repair_event_idempotency (idempotency_key),
                KEY idx_inventory_repair_event_repair (repair_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS app_feature_flags (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                flag_key VARCHAR(96) NOT NULL,
                description VARCHAR(255) DEFAULT NULL,
                is_enabled TINYINT(1) NOT NULL DEFAULT 0,
                rollout_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                role_scope VARCHAR(255) DEFAULT NULL,
                user_scope_json LONGTEXT DEFAULT NULL,
                source VARCHAR(32) NOT NULL DEFAULT 'local',
                note VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_app_feature_flags_key (flag_key),
                KEY idx_app_feature_flags_enabled (is_enabled, flag_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $flag = $db->prepare(
            'INSERT IGNORE INTO app_feature_flags
                (flag_key, description, is_enabled, rollout_percent, source, note)
             VALUES (:flag_key, :description, 0, 0, "migration", "Stage 1 cutover starts disabled")'
        );
        foreach ([
            'stage1_finance_ledger' => 'Canonical Finance income and expense ledger',
            'stage1_e_invoice_atomic_import' => 'Atomic and idempotent e-invoice import',
            'stage1_inventory_repair_contract' => 'Inventory inspection and repair state contract',
            'stage1_statistics_date_contract' => 'Timezone-aware statistics date contract',
        ] as $flagKey => $description) {
            $flag->execute(['flag_key' => $flagKey, 'description' => $description]);
        }
    },
    'down' => static function (PDO $db): void {
        $stage1FinanceRows = 0;
        if (app_stage1_column_exists($db, 'finance_entries', 'source_type')) {
            $stage1FinanceRows = (int) $db->query(
                "SELECT COUNT(*) FROM finance_entries
                  WHERE source_type <> 'manual'
                     OR source_id IS NOT NULL
                     OR employee_account_id IS NOT NULL
                     OR idempotency_key IS NOT NULL
                     OR correlation_id IS NOT NULL
                     OR payload_json IS NOT NULL"
            )->fetchColumn();
        }
        $batchRows = app_stage1_table_exists($db, 'finance_e_invoice_batches')
            ? (int) $db->query('SELECT COUNT(*) FROM finance_e_invoice_batches')->fetchColumn()
            : 0;
        $repairRows = app_stage1_table_exists($db, 'inventory_asset_repairs')
            ? (int) $db->query('SELECT COUNT(*) FROM inventory_asset_repairs')->fetchColumn()
            : 0;
        $repairEventRows = app_stage1_table_exists($db, 'inventory_asset_repair_events')
            ? (int) $db->query('SELECT COUNT(*) FROM inventory_asset_repair_events')->fetchColumn()
            : 0;
        $invoiceRows = app_stage1_column_exists($db, 'finance_e_invoice_imports', 'row_hash')
            ? (int) $db->query('SELECT COUNT(*) FROM finance_e_invoice_imports WHERE row_hash IS NOT NULL')->fetchColumn()
            : 0;
        $debtRows = app_stage1_column_exists($db, 'finance_debt_ledger', 'source_document_key')
            ? (int) $db->query('SELECT COUNT(*) FROM finance_debt_ledger WHERE source_document_key IS NOT NULL')->fetchColumn()
            : 0;

        $returnRows = app_stage1_column_exists($db, 'inventory_asset_returns', 'idempotency_key')
            ? (int) $db->query('SELECT COUNT(*) FROM inventory_asset_returns WHERE idempotency_key IS NOT NULL')->fetchColumn()
            : 0;
        $inspectionRows = app_stage1_column_exists($db, 'inventory_asset_inspections', 'idempotency_key')
            ? (int) $db->query('SELECT COUNT(*) FROM inventory_asset_inspections WHERE idempotency_key IS NOT NULL')->fetchColumn()
            : 0;

        if ($stage1FinanceRows + $batchRows + $repairRows + $repairEventRows + $invoiceRows + $debtRows + $returnRows + $inspectionRows > 0) {
            throw new RuntimeException('stage1_rollback_requires_empty_stage1_data');
        }

        if (app_stage1_table_exists($db, 'app_feature_flags')) {
            $db->exec(
                "DELETE FROM app_feature_flags
                  WHERE flag_key IN (
                    'stage1_finance_ledger',
                    'stage1_e_invoice_atomic_import',
                    'stage1_inventory_repair_contract',
                    'stage1_statistics_date_contract'
                  )
                    AND is_enabled = 0"
            );
        }

        if (app_stage1_table_exists($db, 'inventory_asset_repair_events')) {
            $db->exec('DROP TABLE inventory_asset_repair_events');
        }
        if (app_stage1_table_exists($db, 'inventory_asset_repairs')) {
            $db->exec('DROP TABLE inventory_asset_repairs');
        }
        if (app_stage1_table_exists($db, 'finance_e_invoice_batches')) {
            $db->exec('DROP TABLE finance_e_invoice_batches');
        }

        app_stage1_drop_index($db, 'inventory_asset_inspections', 'uq_inventory_asset_inspection_idempotency');
        app_stage1_drop_index($db, 'inventory_asset_inspections', 'uq_inventory_asset_inspection_return');
        app_stage1_drop_column($db, 'inventory_asset_inspections', 'correlation_id');
        app_stage1_drop_column($db, 'inventory_asset_inspections', 'idempotency_key');
        app_stage1_drop_index($db, 'inventory_asset_returns', 'uq_inventory_asset_return_idempotency');
        app_stage1_drop_column($db, 'inventory_asset_returns', 'correlation_id');
        app_stage1_drop_column($db, 'inventory_asset_returns', 'idempotency_key');

        app_stage1_drop_index($db, 'finance_e_invoice_imports', 'uq_finance_e_invoice_document');
        app_stage1_drop_index($db, 'finance_e_invoice_imports', 'uq_finance_e_invoice_batch_row');
        app_stage1_drop_column($db, 'finance_e_invoice_imports', 'error_code');
        app_stage1_drop_column($db, 'finance_e_invoice_imports', 'correlation_id');
        app_stage1_drop_column($db, 'finance_e_invoice_imports', 'finance_entry_id');
        app_stage1_drop_column($db, 'finance_e_invoice_imports', 'document_key');
        app_stage1_drop_column($db, 'finance_e_invoice_imports', 'row_hash');

        app_stage1_drop_index($db, 'finance_debt_ledger', 'idx_finance_debt_entry');
        app_stage1_drop_index($db, 'finance_debt_ledger', 'uq_finance_debt_source_document');
        app_stage1_drop_column($db, 'finance_debt_ledger', 'correlation_id');
        app_stage1_drop_column($db, 'finance_debt_ledger', 'finance_entry_id');
        app_stage1_drop_column($db, 'finance_debt_ledger', 'source_document_key');

        app_stage1_drop_index($db, 'finance_entries', 'idx_finance_entry_employee_date');
        app_stage1_drop_index($db, 'finance_entries', 'uq_finance_entry_idempotency');
        app_stage1_drop_index($db, 'finance_entries', 'uq_finance_entry_source');
        app_stage1_drop_column($db, 'finance_entries', 'updated_at');
        app_stage1_drop_column($db, 'finance_entries', 'payload_json');
        app_stage1_drop_column($db, 'finance_entries', 'correlation_id');
        app_stage1_drop_column($db, 'finance_entries', 'idempotency_key');
        app_stage1_drop_column($db, 'finance_entries', 'employee_account_id');
        app_stage1_drop_column($db, 'finance_entries', 'source_id');
        app_stage1_drop_column($db, 'finance_entries', 'source_type');
    },
];
