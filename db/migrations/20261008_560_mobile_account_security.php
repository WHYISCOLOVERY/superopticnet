<?php
declare(strict_types=1);

const APP_MOBILE_ACCOUNT_SECURITY_MIGRATION_20261008_560 = '20261008_560_mobile_account_security';

return [
    'version' => APP_MOBILE_ACCOUNT_SECURITY_MIGRATION_20261008_560,
    'description' => 'Add mobile login audit and super-admin password reset audit.',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS mobile_auth_audit_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id BIGINT UNSIGNED DEFAULT NULL,
                login_entered VARCHAR(191) NOT NULL DEFAULT '',
                event_type ENUM('login_success','login_failed','logout','password_reset') NOT NULL,
                reason_code VARCHAR(64) NOT NULL DEFAULT '',
                device_uuid VARCHAR(64) DEFAULT NULL,
                platform VARCHAR(24) DEFAULT NULL,
                device_model VARCHAR(128) DEFAULT NULL,
                ip_address VARCHAR(45) NOT NULL DEFAULT '',
                user_agent VARCHAR(255) NOT NULL DEFAULT '',
                occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_mobile_auth_audit_time (occurred_at,event_type),
                KEY idx_mobile_auth_audit_account (account_id,occurred_at),
                KEY idx_mobile_auth_audit_login (login_entered,occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS mobile_password_reset_audit (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                target_account_id BIGINT UNSIGNED NOT NULL,
                actor_account_id BIGINT UNSIGNED NOT NULL,
                actor_name VARCHAR(191) NOT NULL DEFAULT '',
                revoke_sessions TINYINT(1) NOT NULL DEFAULT 1,
                ip_address VARCHAR(45) NOT NULL DEFAULT '',
                occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_mobile_password_reset_target (target_account_id,occurred_at),
                KEY idx_mobile_password_reset_actor (actor_account_id,occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS mobile_password_reset_audit');
        $db->exec('DROP TABLE IF EXISTS mobile_auth_audit_log');
    },
];
