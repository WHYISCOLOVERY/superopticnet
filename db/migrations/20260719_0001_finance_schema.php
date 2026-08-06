<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/finance-schema-lib.php';

const APP_FINANCE_SCHEMA_MIGRATION_20260719_0001 = '20260719_0001_finance_schema';

function app_finance_migration_20260719_0001_statements(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS finance_asset_depreciations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            asset_id BIGINT UNSIGNED DEFAULT NULL,
            stock_item_id BIGINT UNSIGNED DEFAULT NULL,
            asset_label VARCHAR(191) NOT NULL DEFAULT '',
            fiscal_year SMALLINT UNSIGNED NOT NULL,
            acquisition_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            depreciation_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            depreciation_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            net_book_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            writeoff_threshold DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            writeoff_allowed TINYINT(1) NOT NULL DEFAULT 0,
            writeoff_status ENUM('active','eligible','written_off','blocked') NOT NULL DEFAULT 'active',
            note TEXT DEFAULT NULL,
            calculation_json LONGTEXT DEFAULT NULL,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_by_name VARCHAR(191) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_finance_depr_asset (asset_id, fiscal_year),
            KEY idx_finance_depr_status (writeoff_status, fiscal_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS finance_debt_ledger (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ledger_type ENUM('receivable','payable') NOT NULL,
            counterparty_name VARCHAR(191) NOT NULL,
            counterparty_type VARCHAR(64) NOT NULL DEFAULT 'other',
            counterparty_id BIGINT UNSIGNED DEFAULT NULL,
            amount_original DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            amount_open DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            currency CHAR(3) NOT NULL DEFAULT 'AZN',
            document_no VARCHAR(128) DEFAULT NULL,
            due_date DATE DEFAULT NULL,
            status ENUM('open','partial','closed','void') NOT NULL DEFAULT 'open',
            note TEXT DEFAULT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_by_name VARCHAR(191) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_finance_debt_type (ledger_type, status, due_date),
            KEY idx_finance_debt_counterparty (counterparty_name, ledger_type),
            KEY idx_finance_debt_counterparty_id (counterparty_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS finance_debt_payments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            debt_id BIGINT UNSIGNED NOT NULL,
            payment_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_date DATE DEFAULT NULL,
            payment_method VARCHAR(64) NOT NULL DEFAULT 'manual',
            reference_no VARCHAR(128) DEFAULT NULL,
            note TEXT DEFAULT NULL,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_by_name VARCHAR(191) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_finance_debt_payment_debt (debt_id, created_at),
            KEY idx_finance_debt_payment_ref (reference_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS finance_e_invoice_imports (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            import_batch VARCHAR(96) NOT NULL,
            debt_id BIGINT UNSIGNED DEFAULT NULL,
            ledger_type ENUM('receivable','payable') NOT NULL,
            document_no VARCHAR(128) DEFAULT NULL,
            counterparty_name VARCHAR(191) NOT NULL,
            counterparty_type VARCHAR(64) NOT NULL DEFAULT 'other',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            due_date DATE DEFAULT NULL,
            status ENUM('imported','skipped','failed') NOT NULL DEFAULT 'imported',
            row_json LONGTEXT DEFAULT NULL,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_by_name VARCHAR(191) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_finance_e_invoice_batch (import_batch, created_at),
            KEY idx_finance_e_invoice_doc (document_no),
            KEY idx_finance_e_invoice_debt (debt_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS finance_counterparties (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            display_name VARCHAR(191) NOT NULL,
            normalized_name VARCHAR(191) NOT NULL,
            counterparty_type ENUM('company','institution','person','other') NOT NULL DEFAULT 'company',
            tax_id VARCHAR(64) DEFAULT NULL,
            phone VARCHAR(32) DEFAULT NULL,
            email VARCHAR(191) DEFAULT NULL,
            address VARCHAR(255) DEFAULT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            note TEXT DEFAULT NULL,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_finance_counterparty_name_type (normalized_name, counterparty_type),
            KEY idx_finance_counterparty_tax (tax_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS finance_entries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entry_type ENUM('income','expense') NOT NULL,
            title VARCHAR(191) NOT NULL,
            category VARCHAR(96) NOT NULL DEFAULT 'manual',
            amount DECIMAL(14,2) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'AZN',
            transaction_date DATE NOT NULL,
            branch_id BIGINT UNSIGNED DEFAULT NULL,
            counterparty_id BIGINT UNSIGNED DEFAULT NULL,
            payment_source VARCHAR(96) DEFAULT NULL,
            reference_no VARCHAR(128) DEFAULT NULL,
            note TEXT DEFAULT NULL,
            status ENUM('posted','void') NOT NULL DEFAULT 'posted',
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_by_name VARCHAR(191) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_finance_entry_type_date (entry_type, transaction_date),
            KEY idx_finance_entry_counterparty (counterparty_id),
            UNIQUE KEY uq_finance_entry_reference (reference_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS bonus_rule_settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(96) NOT NULL,
            setting_value VARCHAR(255) NOT NULL,
            value_type ENUM('string','decimal','integer','boolean','json') NOT NULL DEFAULT 'string',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            note VARCHAR(255) DEFAULT NULL,
            updated_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_bonus_rule_setting_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS bonus_base_salary_tiers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            min_customers INT UNSIGNED NOT NULL DEFAULT 0,
            max_customers INT UNSIGNED DEFAULT NULL,
            salary_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            area_quality_percent DECIMAL(6,2) NOT NULL DEFAULT 100.00,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_bonus_salary_tier_range (is_active, min_customers, max_customers)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS bonus_active_day_brackets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            min_days INT UNSIGNED NOT NULL DEFAULT 0,
            max_days INT UNSIGNED NOT NULL DEFAULT 0,
            active_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_bonus_active_days (is_active, min_days, max_days)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS bonus_quality_rules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            problem_key VARCHAR(96) NOT NULL,
            problem_label VARCHAR(191) NOT NULL,
            problem_source ENUM('employee','network','customer','unknown','none') NOT NULL DEFAULT 'unknown',
            severity VARCHAR(32) NOT NULL DEFAULT 'normal',
            quality_percent DECIMAL(6,2) NOT NULL DEFAULT 100.00,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_bonus_quality_rule_key (problem_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS customer_employee_assignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_account_id BIGINT UNSIGNED NOT NULL,
            finder_employee_id BIGINT UNSIGNED DEFAULT NULL,
            installer_employee_id BIGINT UNSIGNED DEFAULT NULL,
            area_responsible_employee_id BIGINT UNSIGNED DEFAULT NULL,
            assigned_area_id VARCHAR(128) DEFAULT NULL,
            bonus_owner_employee_id BIGINT UNSIGNED DEFAULT NULL,
            split_mode VARCHAR(64) NOT NULL DEFAULT 'finder_100',
            split_json LONGTEXT DEFAULT NULL,
            assignment_source VARCHAR(64) NOT NULL DEFAULT 'manual',
            effective_from DATE DEFAULT NULL,
            effective_to DATE DEFAULT NULL,
            status ENUM('active','transferred','stopped','inactive') NOT NULL DEFAULT 'active',
            note TEXT DEFAULT NULL,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_bonus_customer_assignment_customer (customer_account_id, status),
            KEY idx_bonus_customer_assignment_finder (finder_employee_id, status),
            KEY idx_bonus_customer_assignment_installer (installer_employee_id, status),
            KEY idx_bonus_customer_assignment_area_owner (area_responsible_employee_id, status),
            KEY idx_bonus_customer_assignment_bonus_owner (bonus_owner_employee_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS bonus_periods (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            calculation_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status ENUM('draft','calculated','pending_approval','approved','paid','cancelled','recalculated') NOT NULL DEFAULT 'draft',
            employee_count INT UNSIGNED NOT NULL DEFAULT 0,
            total_base_salary DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            total_new_connection_bonus DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            total_recurring_bonus DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            total_quality_deduction DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            total_manual_adjustment DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            total_payable DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            incomplete_customer_count INT UNSIGNED NOT NULL DEFAULT 0,
            ownerless_customer_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            approved_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            paid_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            paid_at DATETIME DEFAULT NULL,
            finance_entry_id BIGINT UNSIGNED DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_bonus_period_range (period_start, period_end),
            KEY idx_bonus_period_status (status, period_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS employee_bonus_summaries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            period_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            employee_name VARCHAR(191) NOT NULL DEFAULT '',
            employee_role VARCHAR(96) DEFAULT NULL,
            assigned_area_text VARCHAR(255) DEFAULT NULL,
            active_customer_count INT UNSIGNED NOT NULL DEFAULT 0,
            inactive_customer_count INT UNSIGNED NOT NULL DEFAULT 0,
            problem_customer_count INT UNSIGNED NOT NULL DEFAULT 0,
            late_payment_customer_count INT UNSIGNED NOT NULL DEFAULT 0,
            bonus_eligible_customer_count INT UNSIGNED NOT NULL DEFAULT 0,
            base_salary DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            area_quality_percent DECIMAL(6,2) NOT NULL DEFAULT 100.00,
            new_connection_bonus DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            recurring_bonus DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            quality_deduction DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            manual_adjustment DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            total_payable DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            status ENUM('draft','calculated','approved','paid','cancelled') NOT NULL DEFAULT 'calculated',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_bonus_summary_employee_period (period_id, employee_id),
            KEY idx_bonus_summary_employee (employee_id, period_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS employee_bonus_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            period_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            customer_account_id BIGINT UNSIGNED DEFAULT NULL,
            subscriber_code VARCHAR(64) DEFAULT NULL,
            customer_name VARCHAR(191) DEFAULT NULL,
            item_type ENUM('base_salary','new_connection','recurring_active_payment','quality_deduction','manual_adjustment','info') NOT NULL DEFAULT 'info',
            base_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            active_days INT UNSIGNED NOT NULL DEFAULT 0,
            active_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            quality_percent DECIMAL(6,2) NOT NULL DEFAULT 100.00,
            bonus_month_no INT UNSIGNED DEFAULT NULL,
            bonus_month_limit INT UNSIGNED DEFAULT NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            status ENUM('payable','pending','excluded','info') NOT NULL DEFAULT 'payable',
            reason VARCHAR(255) DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_bonus_item_period_employee (period_id, employee_id),
            KEY idx_bonus_item_customer (customer_account_id, period_id),
            KEY idx_bonus_item_type_status (item_type, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS employee_bonus_adjustments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            period_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            adjustment_type ENUM('bonus','penalty') NOT NULL DEFAULT 'bonus',
            amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            reason VARCHAR(255) NOT NULL,
            status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            approved_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_bonus_adjustment_period_employee (period_id, employee_id),
            KEY idx_bonus_adjustment_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS employee_bonus_notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id BIGINT UNSIGNED NOT NULL,
            customer_account_id BIGINT UNSIGNED DEFAULT NULL,
            period_id BIGINT UNSIGNED DEFAULT NULL,
            notification_type VARCHAR(64) NOT NULL,
            status ENUM('new','seen','in_progress','resolved','ignored') NOT NULL DEFAULT 'new',
            message VARCHAR(255) NOT NULL,
            due_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_bonus_notification_employee_status (employee_id, status, due_at),
            KEY idx_bonus_notification_customer (customer_account_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS bonus_audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            action_type VARCHAR(96) NOT NULL,
            entity_type VARCHAR(96) NOT NULL,
            entity_id VARCHAR(64) DEFAULT NULL,
            actor_account_id BIGINT UNSIGNED DEFAULT NULL,
            actor_name VARCHAR(191) DEFAULT NULL,
            summary VARCHAR(255) NOT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            before_json LONGTEXT DEFAULT NULL,
            after_json LONGTEXT DEFAULT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_bonus_audit_entity (entity_type, entity_id),
            KEY idx_bonus_audit_action (action_type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

function app_finance_migration_20260719_0001_column_exists(PDO $db, string $table, string $column): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $statement->execute(['table_name' => $table, 'column_name' => $column]);
    return (int) $statement->fetchColumn() === 1;
}

function app_finance_migration_20260719_0001_index_exists(PDO $db, string $table, string $index): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name'
    );
    $statement->execute(['table_name' => $table, 'index_name' => $index]);
    return (int) $statement->fetchColumn() > 0;
}

function app_finance_migration_20260719_0001_apply(PDO $db): array
{
    $lockName = 'superoptic:finance:migration';
    $lock = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $lock->execute(['lock_name' => $lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('finance_migration_lock_timeout');
    }

    $statements = app_finance_migration_20260719_0001_statements();
    $checksum = hash('sha256', implode("\n-- statement --\n", $statements));

    try {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS finance_schema_migrations (
                version_key VARCHAR(96) PRIMARY KEY,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $current = $db->prepare('SELECT checksum FROM finance_schema_migrations WHERE version_key = :version_key');
        $current->execute(['version_key' => APP_FINANCE_SCHEMA_MIGRATION_20260719_0001]);
        $existingChecksum = $current->fetchColumn();
        if (is_string($existingChecksum) && $existingChecksum !== '') {
            if (!hash_equals($existingChecksum, $checksum)) {
                throw new RuntimeException('finance_migration_checksum_mismatch');
            }
            $missing = app_finance_schema_missing_tables($db, app_finance_schema_tables());
            if ($missing !== []) {
                throw new RuntimeException('finance_schema_drift:' . implode(',', $missing));
            }
            return [
                'ok' => true,
                'version' => APP_FINANCE_SCHEMA_MIGRATION_20260719_0001,
                'applied' => false,
                'checksum' => $checksum,
            ];
        }

        foreach ($statements as $statement) {
            $db->exec($statement);
        }

        if (!app_finance_migration_20260719_0001_column_exists($db, 'finance_debt_ledger', 'counterparty_id')) {
            $db->exec('ALTER TABLE finance_debt_ledger ADD COLUMN counterparty_id BIGINT UNSIGNED DEFAULT NULL AFTER counterparty_type');
        }
        if (!app_finance_migration_20260719_0001_index_exists($db, 'finance_debt_ledger', 'idx_finance_debt_counterparty_id')) {
            $db->exec('CREATE INDEX idx_finance_debt_counterparty_id ON finance_debt_ledger (counterparty_id)');
        }

        $missing = app_finance_schema_missing_tables($db, app_finance_schema_tables());
        if ($missing !== []) {
            throw new RuntimeException('finance_migration_incomplete:' . implode(',', $missing));
        }

        $mark = $db->prepare(
            'INSERT INTO finance_schema_migrations (version_key, checksum)
             VALUES (:version_key, :checksum)'
        );
        $mark->execute([
            'version_key' => APP_FINANCE_SCHEMA_MIGRATION_20260719_0001,
            'checksum' => $checksum,
        ]);

        return [
            'ok' => true,
            'version' => APP_FINANCE_SCHEMA_MIGRATION_20260719_0001,
            'applied' => true,
            'checksum' => $checksum,
        ];
    } finally {
        try {
            $release = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name' => $lockName]);
        } catch (Throwable) {
        }
    }
}

function app_finance_migration_20260719_0001_rollback(PDO $db): void
{
    foreach (app_finance_schema_tables() as $table) {
        $count = (int) $db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        if ($count > 0) {
            throw new RuntimeException('finance_schema_rollback_non_empty:' . $table);
        }
    }

    foreach (array_reverse(app_finance_schema_tables()) as $table) {
        $db->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
    $delete = $db->prepare('DELETE FROM finance_schema_migrations WHERE version_key = :version_key');
    $delete->execute(['version_key' => APP_FINANCE_SCHEMA_MIGRATION_20260719_0001]);
}

return [
    'version' => APP_FINANCE_SCHEMA_MIGRATION_20260719_0001,
    'description' => 'Move Finance and employee bonus schema creation out of HTTP render paths',
    'up' => static function (PDO $db): void {
        app_finance_migration_20260719_0001_apply($db);
    },
    'down' => static function (PDO $db): void {
        app_finance_migration_20260719_0001_rollback($db);
    },
];
