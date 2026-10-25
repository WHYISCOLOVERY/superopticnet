<?php
declare(strict_types=1);

const APP_DEVICE_INTERFACES_MIGRATION_20260819_440 = '20260819_440_device_interfaces';

return [
    'version' => APP_DEVICE_INTERFACES_MIGRATION_20260819_440,
    'description' => 'Create device_interfaces table -- native per-device interface inventory (docs/39_MGS_NODE_CONSOLIDATION.md Faza 1, "önce interfeys inventarı" step), the prerequisite for an SNMP interface-traffic collector. Populated by a real IF-MIB SNMP walk (app_mgs_discover_device_interfaces), not left as unused scaffolding.',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS device_interfaces (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_key VARCHAR(128) NOT NULL,
                if_index INT UNSIGNED NOT NULL,
                if_descr VARCHAR(191) NOT NULL DEFAULT '',
                if_type INT UNSIGNED NOT NULL DEFAULT 0,
                if_speed_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
                if_phys_address VARCHAR(64) NOT NULL DEFAULT '',
                if_admin_status ENUM('up','down','testing','unknown') NOT NULL DEFAULT 'unknown',
                if_oper_status ENUM('up','down','testing','unknown','dormant','not_present','lower_layer_down') NOT NULL DEFAULT 'unknown',
                discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_polled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_device_interface (device_key, if_index),
                KEY idx_device_interfaces_device (device_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS device_interfaces');
    },
];
