<?php
declare(strict_types=1);

const APP_FINANCE_DEBT_WRITEOFF_MIGRATION_20260808_310 = '20260808_310_finance_debt_writeoff_maker_checker';

return [
    'version' => APP_FINANCE_DEBT_WRITEOFF_MIGRATION_20260808_310,
    'description' => 'Create finance_debt_writeoff_requests table (vision 19.2 "borc silinməsi" maker-checker -- this feature did not exist before)',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS finance_debt_writeoff_requests (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                debt_id BIGINT UNSIGNED NOT NULL,
                requested_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                reason VARCHAR(255) NOT NULL,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                requested_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                requested_by_name VARCHAR(191) DEFAULT NULL,
                reviewed_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                reviewed_by_name VARCHAR(191) DEFAULT NULL,
                resolution_note VARCHAR(255) DEFAULT NULL,
                debt_payment_id BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME DEFAULT NULL,
                KEY idx_finance_debt_writeoff_status (status, created_at),
                KEY idx_finance_debt_writeoff_debt (debt_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS finance_debt_writeoff_requests');
    },
];
