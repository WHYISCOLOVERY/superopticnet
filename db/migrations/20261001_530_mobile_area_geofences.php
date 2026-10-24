<?php
declare(strict_types=1);

const APP_MOBILE_AREA_GEOFENCES_MIGRATION_20261001_530 = '20261001_530_mobile_area_geofences';

return [
    'version' => APP_MOBILE_AREA_GEOFENCES_MIGRATION_20261001_530,
    'description' => 'Add configurable work-area geofences and employee exit events.',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS mobile_area_geofences (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                area VARCHAR(191) NOT NULL,
                center_latitude DECIMAL(10,7) NOT NULL,
                center_longitude DECIMAL(10,7) NOT NULL,
                radius_meters INT UNSIGNED NOT NULL DEFAULT 2000,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_mobile_area_geofence (area),
                KEY idx_mobile_geofence_active (is_active, area)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS mobile_geofence_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id BIGINT UNSIGNED NOT NULL,
                area VARCHAR(191) NOT NULL,
                event_type ENUM('left_area','returned_to_area') NOT NULL,
                latitude DECIMAL(10,7) NOT NULL,
                longitude DECIMAL(10,7) NOT NULL,
                distance_meters INT UNSIGNED NOT NULL,
                duty_session_id BIGINT UNSIGNED DEFAULT NULL,
                opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                resolved_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_mobile_geofence_employee (account_id, opened_at),
                KEY idx_mobile_geofence_open (event_type, resolved_at, opened_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS mobile_geofence_events');
        $db->exec('DROP TABLE IF EXISTS mobile_area_geofences');
    },
];
