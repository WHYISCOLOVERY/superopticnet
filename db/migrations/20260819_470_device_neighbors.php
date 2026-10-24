<?php
declare(strict_types=1);

const APP_DEVICE_NEIGHBORS_MIGRATION_20260819_470 = '20260819_470_device_neighbors';

return [
    'version' => APP_DEVICE_NEIGHBORS_MIGRATION_20260819_470,
    'description' => 'Create device_neighbors -- LLDP/CDP topology discovery (docs/39_MGS_NODE_CONSOLIDATION.md Faza 1, last remaining gap). Populated by a real SNMP walk of LLDP-MIB/CISCO-CDP-MIB (app_mgs_discover_device_neighbors), not left as unused scaffolding.',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS device_neighbors (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_key VARCHAR(128) NOT NULL,
                protocol ENUM('lldp','cdp') NOT NULL,
                local_index_key VARCHAR(128) NOT NULL,
                local_if_index INT UNSIGNED DEFAULT NULL,
                remote_chassis_id VARCHAR(191) NOT NULL DEFAULT '',
                remote_port_id VARCHAR(191) NOT NULL DEFAULT '',
                remote_port_desc VARCHAR(191) NOT NULL DEFAULT '',
                remote_sys_name VARCHAR(191) NOT NULL DEFAULT '',
                remote_platform VARCHAR(191) NOT NULL DEFAULT '',
                discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_device_neighbor (device_key, protocol, local_index_key),
                KEY idx_device_neighbors_device (device_key),
                KEY idx_device_neighbors_if (device_key, local_if_index)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS device_neighbors');
    },
];
