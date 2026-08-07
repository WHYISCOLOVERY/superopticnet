<?php
declare(strict_types=1);

const APP_ADMIN_PRIVILEGE_MIGRATION_20260808_300 = '20260808_300_admin_privilege_escalation_maker_checker';

return [
    'version' => APP_ADMIN_PRIVILEGE_MIGRATION_20260808_300,
    'description' => 'Create admin_privilege_change_requests table (vision 19.2 "rol dəyişikliyi" maker-checker)',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS admin_privilege_change_requests (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                target_account_id BIGINT UNSIGNED DEFAULT NULL,
                target_login VARCHAR(191) NOT NULL DEFAULT '',
                previous_privileges_json LONGTEXT NOT NULL,
                proposed_privileges_json LONGTEXT NOT NULL,
                account_payload_json LONGTEXT NOT NULL,
                reason VARCHAR(255) NOT NULL,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                requested_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                requested_by_name VARCHAR(191) DEFAULT NULL,
                reviewed_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                reviewed_by_name VARCHAR(191) DEFAULT NULL,
                resolution_note VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME DEFAULT NULL,
                KEY idx_admin_privilege_status (status, created_at),
                KEY idx_admin_privilege_target (target_account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS admin_privilege_change_requests');
    },
];
