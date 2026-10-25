<?php
declare(strict_types=1);

const APP_LIVE_NETWORK_INVENTORY_MIGRATION_20261025_620 = '20261025_620_live_network_inventory';

return [
    'version' => APP_LIVE_NETWORK_INVENTORY_MIGRATION_20261025_620,
    'description' => 'Create indexed live OLT/ONT and switch MAC inventory tables for low-load customer and NOC lookups.',
    'up' => static function (PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS live_olt_ont_inventory (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_key VARCHAR(128) NOT NULL, pon VARCHAR(32) NOT NULL, ont_id VARCHAR(32) NOT NULL,
            serial_number VARCHAR(128) NOT NULL DEFAULT '', ont_name VARCHAR(191) NOT NULL DEFAULT '', status VARCHAR(32) NOT NULL DEFAULT 'unknown',
            temperature VARCHAR(64) NOT NULL DEFAULT '', ont_rx VARCHAR(64) NOT NULL DEFAULT '', ont_tx VARCHAR(64) NOT NULL DEFAULT '', olt_rx VARCHAR(64) NOT NULL DEFAULT '',
            discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, raw_source VARCHAR(32) NOT NULL DEFAULT 'cli',
            UNIQUE KEY uq_live_olt_ont_path (device_key, pon, ont_id), KEY idx_live_olt_ont_serial (serial_number), KEY idx_live_olt_ont_seen (last_seen_at), KEY idx_live_olt_ont_status (device_key, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS live_switch_mac_inventory (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_key VARCHAR(128) NOT NULL, mac_address VARCHAR(32) NOT NULL,
            bridge_port INT UNSIGNED NOT NULL DEFAULT 0, if_index INT UNSIGNED NOT NULL DEFAULT 0, if_descr VARCHAR(191) NOT NULL DEFAULT '', vlan_id INT UNSIGNED DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'learned', discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            raw_source VARCHAR(32) NOT NULL DEFAULT 'snmp_bridge', UNIQUE KEY uq_live_switch_mac (device_key, mac_address, vlan_id),
            KEY idx_live_switch_mac_mac (mac_address), KEY idx_live_switch_mac_port (device_key, if_index), KEY idx_live_switch_mac_seen (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },
    'down' => static function (PDO $db): void {
        // Network inventory is historical operational evidence; do not delete it automatically.
    },
];
