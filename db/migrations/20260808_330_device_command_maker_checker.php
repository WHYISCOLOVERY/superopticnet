<?php
declare(strict_types=1);

const APP_DEVICE_COMMAND_MIGRATION_20260808_330 = '20260808_330_device_command_maker_checker';

return [
    'version' => APP_DEVICE_COMMAND_MIGRATION_20260808_330,
    'description' => 'Create mgs_device_command_requests table (vision 19.2 "router konfiqurasiyası" maker-checker) -- gates dangerous (write) commands sent to real network devices behind a second admin approval',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS mgs_device_command_requests (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_key VARCHAR(191) NOT NULL DEFAULT '',
                device_name VARCHAR(191) NOT NULL DEFAULT '',
                device_ip VARCHAR(64) NOT NULL DEFAULT '',
                commands_json LONGTEXT NOT NULL,
                dangerous_tokens_json LONGTEXT NOT NULL,
                action_type VARCHAR(96) NOT NULL DEFAULT 'device_command_apply',
                authorization_action VARCHAR(96) NOT NULL DEFAULT 'execute_device_command',
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
                KEY idx_mgs_device_command_status (status, created_at),
                KEY idx_mgs_device_command_device (device_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS mgs_device_command_requests');
    },
];
