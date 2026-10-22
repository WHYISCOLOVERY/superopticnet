<?php
declare(strict_types=1);

const APP_DEVICE_INTERFACE_TRAFFIC_MIGRATION_20260819_450 = '20260819_450_device_interface_traffic';

return [
    'version' => APP_DEVICE_INTERFACE_TRAFFIC_MIGRATION_20260819_450,
    'description' => 'Create device_interface_traffic_samples/hourly/daily tables -- the SNMP interface-traffic collector docs/39_MGS_NODE_CONSOLIDATION.md Faza 1 calls out, now unblocked by device_interfaces (20260819_440). Populated by a real polling worker (tools/poll-device-interface-traffic.php), not left as unused scaffolding.',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS device_interface_traffic_samples (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_key VARCHAR(128) NOT NULL,
                if_index INT UNSIGNED NOT NULL,
                sampled_at DATETIME NOT NULL,
                in_octets BIGINT UNSIGNED NOT NULL DEFAULT 0,
                out_octets BIGINT UNSIGNED NOT NULL DEFAULT 0,
                in_bps BIGINT UNSIGNED DEFAULT NULL,
                out_bps BIGINT UNSIGNED DEFAULT NULL,
                counter_bits TINYINT UNSIGNED NOT NULL DEFAULT 32,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_device_interface_sample (device_key, if_index, sampled_at),
                KEY idx_device_interface_sample_lookup (device_key, if_index, sampled_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS device_interface_traffic_hourly (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_key VARCHAR(128) NOT NULL,
                if_index INT UNSIGNED NOT NULL,
                hour_start DATETIME NOT NULL,
                avg_in_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
                avg_out_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
                max_in_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
                max_out_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
                sample_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_device_interface_hourly (device_key, if_index, hour_start),
                KEY idx_device_interface_hourly_lookup (device_key, if_index, hour_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS device_interface_traffic_daily (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_key VARCHAR(128) NOT NULL,
                if_index INT UNSIGNED NOT NULL,
                day_start DATE NOT NULL,
                avg_in_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
                avg_out_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
                max_in_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
                max_out_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
                sample_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_device_interface_daily (device_key, if_index, day_start),
                KEY idx_device_interface_daily_lookup (device_key, if_index, day_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS device_interface_traffic_daily');
        $db->exec('DROP TABLE IF EXISTS device_interface_traffic_hourly');
        $db->exec('DROP TABLE IF EXISTS device_interface_traffic_samples');
    },
];
