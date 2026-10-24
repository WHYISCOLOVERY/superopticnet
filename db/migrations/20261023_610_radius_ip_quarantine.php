<?php
declare(strict_types=1);

const APP_RADIUS_IP_QUARANTINE_MIGRATION_20261023_610 = '20261023_610_radius_ip_quarantine';

return [
    'version' => APP_RADIUS_IP_QUARANTINE_MIGRATION_20261023_610,
    'description' => 'Quarantine framed IP addresses implicated in repeated NAS errors before reuse.',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS radius_ip_quarantine (
                ip_address VARCHAR(45) NOT NULL PRIMARY KEY,
                source_username VARCHAR(191) NOT NULL,
                conflicting_username VARCHAR(191) DEFAULT NULL,
                reason VARCHAR(500) NOT NULL,
                quarantined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                released_at DATETIME DEFAULT NULL,
                metadata_json LONGTEXT DEFAULT NULL,
                KEY idx_radius_ip_quarantine_active (released_at, expires_at),
                KEY idx_radius_ip_quarantine_username (source_username, quarantined_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS radius_ip_quarantine');
    },
];
