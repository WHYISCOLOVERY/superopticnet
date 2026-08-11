<?php
declare(strict_types=1);

const APP_FINANCE_BUDGET_MIGRATION_20260809_350 = '20260809_350_finance_budget_vs_actual';

return [
    'version' => APP_FINANCE_BUDGET_MIGRATION_20260809_350,
    'description' => 'Add finance_budgets table for monthly budget-vs-actual comparison (vision 7.3/16.3 -- no budget concept existed at all before this)',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS finance_budgets (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                period_month CHAR(7) NOT NULL,
                entry_type ENUM('income','expense') NOT NULL,
                category VARCHAR(96) NOT NULL,
                budgeted_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                note VARCHAR(255) DEFAULT NULL,
                created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                created_by_name VARCHAR(191) DEFAULT NULL,
                updated_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                updated_by_name VARCHAR(191) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_finance_budget_period_category (period_month, entry_type, category),
                KEY idx_finance_budget_period (period_month)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS finance_budgets');
    },
];
