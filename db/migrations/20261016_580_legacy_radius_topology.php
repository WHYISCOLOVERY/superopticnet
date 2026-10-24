<?php
declare(strict_types=1);

const APP_LEGACY_RADIUS_TOPOLOGY_MIGRATION_20261016_580 = '20261016_580_legacy_radius_topology';

return [
    'version' => APP_LEGACY_RADIUS_TOPOLOGY_MIGRATION_20261016_580,
    'description' => 'Preserve legacy RADIUS definitions and subscriber NAS port mappings for cutover.',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS definitions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                attrtype VARCHAR(64) NOT NULL,
                name VARCHAR(191) NOT NULL,
                value VARCHAR(255) NOT NULL DEFAULT '',
                description TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL,
                UNIQUE KEY uq_radius_definition (attrtype,name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS port_mappings (
                username VARCHAR(191) NOT NULL,
                nasportid VARCHAR(191) NOT NULL,
                interface_id VARCHAR(64) DEFAULT NULL,
                vlan_id INT UNSIGNED DEFAULT NULL,
                interface_name VARCHAR(191) DEFAULT NULL,
                vlan_name VARCHAR(191) DEFAULT NULL,
                PRIMARY KEY (username,nasportid),
                KEY idx_radius_port_interface (interface_id,vlan_id),
                KEY idx_radius_port_nas (nasportid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS port_mappings');
        $db->exec('DROP TABLE IF EXISTS definitions');
    },
];
