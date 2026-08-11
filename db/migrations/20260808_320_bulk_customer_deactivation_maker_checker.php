<?php
declare(strict_types=1);

const APP_BULK_DEACTIVATION_MIGRATION_20260808_320 = '20260808_320_bulk_customer_deactivation_maker_checker';

return [
    'version' => APP_BULK_DEACTIVATION_MIGRATION_20260808_320,
    'description' => 'Create billing_bulk_deactivation_requests table (vision 19.2 "kutlevi musteri deaktivlesmesi" -- this feature did not exist before)',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS billing_bulk_deactivation_requests (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_ids_json LONGTEXT NOT NULL,
                account_count INT UNSIGNED NOT NULL DEFAULT 0,
                reason VARCHAR(255) NOT NULL,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                requested_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                requested_by_name VARCHAR(191) DEFAULT NULL,
                reviewed_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                reviewed_by_name VARCHAR(191) DEFAULT NULL,
                resolution_note VARCHAR(255) DEFAULT NULL,
                result_json LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME DEFAULT NULL,
                KEY idx_billing_bulk_deactivation_status (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS billing_bulk_deactivation_requests');
    },
];
