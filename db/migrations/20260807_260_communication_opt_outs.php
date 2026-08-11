<?php
declare(strict_types=1);

const APP_COMMUNICATION_OPT_OUTS_MIGRATION_20260807_260 = '20260807_260_communication_opt_outs';

return [
    'version' => APP_COMMUNICATION_OPT_OUTS_MIGRATION_20260807_260,
    'description' => 'Create a phone-keyed opt-out registry so marketing/campaign sends can respect consent (previously not enforced anywhere)',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS communication_opt_outs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                phone_normalized VARCHAR(24) NOT NULL,
                channel ENUM('all','sms','whatsapp') NOT NULL DEFAULT 'all',
                source VARCHAR(64) NOT NULL DEFAULT 'customer_portal',
                reason VARCHAR(255) DEFAULT NULL,
                created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                created_by_name VARCHAR(191) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_communication_opt_out (phone_normalized, channel)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS communication_opt_outs');
    },
];
