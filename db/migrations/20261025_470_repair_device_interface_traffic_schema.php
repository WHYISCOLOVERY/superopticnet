<?php
declare(strict_types=1);

const APP_REPAIR_DEVICE_INTERFACE_TRAFFIC_SCHEMA_MIGRATION_20261025_470 = '20261025_470_repair_device_interface_traffic_schema';

return [
    'version' => APP_REPAIR_DEVICE_INTERFACE_TRAFFIC_SCHEMA_MIGRATION_20261025_470,
    'description' => 'Preserve incompatible legacy interface-traffic tables and create the canonical SNMP sample/hourly/daily schema used by the collector.',
    'up' => static function (PDO $db): void {
        $database = (string) $db->query('SELECT DATABASE()')->fetchColumn();
        $canonical = [
            'device_interface_traffic_samples' => 'device_key',
            'device_interface_traffic_hourly' => 'device_key',
            'device_interface_traffic_daily' => 'device_key',
        ];
        foreach ($canonical as $table => $requiredColumn) {
            $existsStatement = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
            $existsStatement->execute(['table' => $table]);
            $exists = (bool) $existsStatement->fetchColumn();
            $check = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND COLUMN_NAME = :column');
            $check->execute(['db' => $database, 'table' => $table, 'column' => $requiredColumn]);
            if ($check->fetchColumn() && $exists) {
                continue;
            }
            $legacy = $table . '_legacy_20261025';
            $legacyExists = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table');
            $legacyExists->execute(['db' => $database, 'table' => $legacy]);
            if (!$legacyExists->fetchColumn() && $exists) {
                $db->exec('RENAME TABLE `' . $table . '` TO `' . $legacy . '`');
            }
        }
        $db->exec("CREATE TABLE IF NOT EXISTS device_interface_traffic_samples (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_key VARCHAR(128) NOT NULL, if_index INT UNSIGNED NOT NULL, sampled_at DATETIME NOT NULL,
            in_octets BIGINT UNSIGNED NOT NULL DEFAULT 0, out_octets BIGINT UNSIGNED NOT NULL DEFAULT 0,
            in_bps BIGINT UNSIGNED DEFAULT NULL, out_bps BIGINT UNSIGNED DEFAULT NULL, counter_bits TINYINT UNSIGNED NOT NULL DEFAULT 32,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_device_interface_sample (device_key, if_index, sampled_at), KEY idx_device_interface_sample_lookup (device_key, if_index, sampled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS device_interface_traffic_hourly (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_key VARCHAR(128) NOT NULL, if_index INT UNSIGNED NOT NULL, hour_start DATETIME NOT NULL,
            avg_in_bps BIGINT UNSIGNED NOT NULL DEFAULT 0, avg_out_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
            max_in_bps BIGINT UNSIGNED NOT NULL DEFAULT 0, max_out_bps BIGINT UNSIGNED NOT NULL DEFAULT 0, sample_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_device_interface_hourly (device_key, if_index, hour_start), KEY idx_device_interface_hourly_lookup (device_key, if_index, hour_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS device_interface_traffic_daily (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_key VARCHAR(128) NOT NULL, if_index INT UNSIGNED NOT NULL, day_start DATE NOT NULL,
            avg_in_bps BIGINT UNSIGNED NOT NULL DEFAULT 0, avg_out_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
            max_in_bps BIGINT UNSIGNED NOT NULL DEFAULT 0, max_out_bps BIGINT UNSIGNED NOT NULL DEFAULT 0, sample_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_device_interface_daily (device_key, if_index, day_start), KEY idx_device_interface_daily_lookup (device_key, if_index, day_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },
    'down' => static function (PDO $db): void {
        // Preserved legacy data must not be removed automatically.
    },
];

