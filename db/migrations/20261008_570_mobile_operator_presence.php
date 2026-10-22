<?php
declare(strict_types=1);

const APP_MOBILE_OPERATOR_PRESENCE_MIGRATION_20261008_570 = '20261008_570_mobile_operator_presence';

return [
    'version' => APP_MOBILE_OPERATOR_PRESENCE_MIGRATION_20261008_570,
    'description' => 'Add night-operator schedules, hourly app presence, notification preferences and sensitive action audit.',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS mobile_operator_schedules (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id BIGINT UNSIGNED NOT NULL,
                timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Baku',
                shift_start TIME NOT NULL DEFAULT '20:00:00',
                shift_end TIME NOT NULL DEFAULT '08:00:00',
                weekdays_json VARCHAR(64) NOT NULL DEFAULT '[1,2,3,4,5,6,7]',
                breaks_json LONGTEXT DEFAULT NULL,
                required_interval_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
                grace_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                updated_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_mobile_operator_schedule_account (account_id),
                KEY idx_mobile_operator_schedule_enabled (is_enabled,account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS mobile_operator_activity (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id BIGINT UNSIGNED NOT NULL,
                device_uuid VARCHAR(64) NOT NULL,
                activity_type ENUM('login','app_open','foreground','heartbeat') NOT NULL DEFAULT 'heartbeat',
                occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip_address VARCHAR(45) NOT NULL DEFAULT '',
                metadata_json LONGTEXT DEFAULT NULL,
                KEY idx_mobile_operator_activity_latest (account_id,occurred_at),
                KEY idx_mobile_operator_activity_device (device_uuid,occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS mobile_notification_preferences (
                account_id BIGINT UNSIGNED PRIMARY KEY,
                sound_enabled TINYINT(1) NOT NULL DEFAULT 1,
                vibration_enabled TINYINT(1) NOT NULL DEFAULT 1,
                sound_key VARCHAR(64) NOT NULL DEFAULT 'superoptic_alert',
                quiet_start TIME DEFAULT NULL,
                quiet_end TIME DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS mobile_location_compliance (
                account_id BIGINT UNSIGNED PRIMARY KEY,
                device_uuid VARCHAR(64) NOT NULL,
                service_enabled TINYINT(1) NOT NULL DEFAULT 1,
                permission_status VARCHAR(32) NOT NULL DEFAULT 'granted',
                disabled_since DATETIME DEFAULT NULL,
                last_checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_mobile_location_compliance (service_enabled,last_checked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS mobile_sensitive_action_audit (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                actor_account_id BIGINT UNSIGNED NOT NULL,
                actor_name VARCHAR(191) NOT NULL DEFAULT '',
                actor_role VARCHAR(64) NOT NULL DEFAULT '',
                action_key VARCHAR(96) NOT NULL,
                target_type VARCHAR(64) NOT NULL DEFAULT '',
                target_id VARCHAR(128) NOT NULL DEFAULT '',
                before_json LONGTEXT DEFAULT NULL,
                after_json LONGTEXT DEFAULT NULL,
                reason VARCHAR(500) NOT NULL DEFAULT '',
                ip_address VARCHAR(45) NOT NULL DEFAULT '',
                occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_mobile_sensitive_actor (actor_account_id,occurred_at),
                KEY idx_mobile_sensitive_action (action_key,occurred_at),
                KEY idx_mobile_sensitive_target (target_type,target_id,occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $seed = $db->prepare(
            "INSERT INTO mobile_operator_schedules (account_id,shift_start,shift_end,weekdays_json,breaks_json,is_enabled)
             SELECT id,'20:00:00','08:00:00','[1,2,3,4,5,6,7]','[]',1
               FROM portal_accounts WHERE login=? AND is_active=1
             ON DUPLICATE KEY UPDATE account_id=VALUES(account_id)"
        );
        foreach (['guluzar.ehmedli', 'mehseti.memmedova'] as $login) $seed->execute([$login]);
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS mobile_sensitive_action_audit');
        $db->exec('DROP TABLE IF EXISTS mobile_location_compliance');
        $db->exec('DROP TABLE IF EXISTS mobile_notification_preferences');
        $db->exec('DROP TABLE IF EXISTS mobile_operator_activity');
        $db->exec('DROP TABLE IF EXISTS mobile_operator_schedules');
    },
];
