<?php
declare(strict_types=1);

const APP_FINANCE_CASH_RECONCILIATION_MIGRATION_20260809_370 = '20260809_370_finance_cash_reconciliation';

return [
    'version' => APP_FINANCE_CASH_RECONCILIATION_MIGRATION_20260809_370,
    'description' => 'Add finance_cash_reconciliations -- advisory kassa/bank discrepancy log (vision 7.3/16.3 "kassa-bank ferqlerini tapmali"). Append-only, read-only/advisory: recording a check never adjusts any ledger, it only compares a declared bank/kassa net movement against the system-derived one for the same period and stores the discrepancy for the record',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS finance_cash_reconciliations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                source_label VARCHAR(96) NOT NULL,
                declared_net_movement DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                system_net_movement DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                discrepancy_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                note VARCHAR(255) DEFAULT NULL,
                created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                created_by_name VARCHAR(191) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_finance_cash_reconciliation_period (period_start, period_end),
                KEY idx_finance_cash_reconciliation_source (source_label)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS finance_cash_reconciliations');
    },
];
