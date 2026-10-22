<?php
declare(strict_types=1);

const APP_MOBILE_SUPERVISION_ROUTING_MIGRATION_20261008_550 = '20261008_550_mobile_supervision_and_routing';

return [
    'version' => APP_MOBILE_SUPERVISION_ROUTING_MIGRATION_20261008_550,
    'description' => 'Add consent-gated home dwell supervision, manager alerts, and daily reporting.',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS mobile_employee_home_geofences (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id BIGINT UNSIGNED NOT NULL,
                center_latitude DECIMAL(10,7) NOT NULL,
                center_longitude DECIMAL(10,7) NOT NULL,
                radius_meters INT UNSIGNED NOT NULL DEFAULT 150,
                dwell_threshold_minutes INT UNSIGNED NOT NULL DEFAULT 30,
                work_start TIME NOT NULL DEFAULT '09:00:00',
                work_end TIME NOT NULL DEFAULT '18:00:00',
                lunch_start TIME NOT NULL DEFAULT '13:00:00',
                lunch_end TIME NOT NULL DEFAULT '14:00:00',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_mobile_employee_home_geofence (account_id),
                KEY idx_mobile_home_geofence_active (is_active, account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS mobile_home_dwell_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id BIGINT UNSIGNED NOT NULL,
                geofence_id BIGINT UNSIGNED NOT NULL,
                duty_session_id BIGINT UNSIGNED DEFAULT NULL,
                entered_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                exited_at DATETIME DEFAULT NULL,
                unauthorized_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                alert_started_at DATETIME DEFAULT NULL,
                status ENUM('observing','alerted','closed') NOT NULL DEFAULT 'observing',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_mobile_home_dwell_open (account_id, exited_at, entered_at),
                KEY idx_mobile_home_dwell_report (alert_started_at, account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS mobile_manager_alerts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                alert_key VARCHAR(191) NOT NULL,
                account_id BIGINT UNSIGNED DEFAULT NULL,
                alert_type VARCHAR(64) NOT NULL,
                severity ENUM('info','warning','high','critical') NOT NULL DEFAULT 'warning',
                title VARCHAR(191) NOT NULL,
                message VARCHAR(500) NOT NULL,
                event_at DATETIME NOT NULL,
                resolved_at DATETIME DEFAULT NULL,
                metadata_json LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_mobile_manager_alert_key (alert_key),
                KEY idx_mobile_manager_alert_open (resolved_at, event_at),
                KEY idx_mobile_manager_alert_employee (account_id, event_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS mobile_manager_alerts');
        $db->exec('DROP TABLE IF EXISTS mobile_home_dwell_events');
        $db->exec('DROP TABLE IF EXISTS mobile_employee_home_geofences');
    },
];
