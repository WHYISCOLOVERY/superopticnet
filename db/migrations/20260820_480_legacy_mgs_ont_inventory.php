<?php
declare(strict_types=1);

const APP_LEGACY_MGS_ONT_INVENTORY_MIGRATION_20260820_480 = '20260820_480_legacy_mgs_ont_inventory';

return [
    'version' => APP_LEGACY_MGS_ONT_INVENTORY_MIGRATION_20260820_480,
    'description' => 'Create legacy_mgs_ont_inventory -- read-only historical import target for the separate Node MGS app being retired (docs/39_MGS_NODE_CONSOLIDATION.md Faza 2). The source onts table has zero real customer linkage (customer_ref/mac/phone/wifi_* all empty in the real dataset), so this is pure OLT/PON technical history, not customer equipment -- it is intentionally NOT written into customer_equipment_assignments, which requires a real customer_code.',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS legacy_mgs_ont_inventory (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_ont_id VARCHAR(64) NOT NULL,
                olt_device_key VARCHAR(128) NOT NULL DEFAULT '',
                port_key VARCHAR(64) NOT NULL DEFAULT '',
                serial_number VARCHAR(191) NOT NULL DEFAULT '',
                status VARCHAR(32) NOT NULL DEFAULT 'unknown',
                rx_dbm DECIMAL(10,2) DEFAULT NULL,
                tx_dbm DECIMAL(10,2) DEFAULT NULL,
                olt_rx_dbm DECIMAL(10,2) DEFAULT NULL,
                distance_meters INT UNSIGNED NOT NULL DEFAULT 0,
                ont_vendor VARCHAR(191) NOT NULL DEFAULT '',
                imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                source_updated_at DATETIME DEFAULT NULL,
                UNIQUE KEY uq_legacy_mgs_ont_source (source_ont_id),
                KEY idx_legacy_mgs_ont_olt (olt_device_key),
                KEY idx_legacy_mgs_ont_serial (serial_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS legacy_mgs_ont_inventory');
    },
];
