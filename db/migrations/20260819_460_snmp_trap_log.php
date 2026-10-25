<?php
declare(strict_types=1);

const APP_SNMP_TRAP_LOG_MIGRATION_20260819_460 = '20260819_460_snmp_trap_log';

return [
    'version' => APP_SNMP_TRAP_LOG_MIGRATION_20260819_460,
    'description' => 'Create snmp_trap_log -- passive SNMPv1/v2c trap ingest (docs/39_MGS_NODE_CONSOLIDATION.md Faza 1, last telemetry gap). Populated by a real UDP listener + decoder (tools/snmp-trap-listener.php, app_mgs_record_snmp_trap), not left as unused scaffolding.',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS snmp_trap_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_ip VARCHAR(45) NOT NULL,
                source_port SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                device_key VARCHAR(128) DEFAULT NULL,
                snmp_version ENUM('v1','v2c') NOT NULL,
                community_valid TINYINT(1) NOT NULL DEFAULT 0,
                generic_trap SMALLINT DEFAULT NULL,
                specific_trap INT DEFAULT NULL,
                enterprise_oid VARCHAR(191) DEFAULT NULL,
                trap_oid VARCHAR(191) DEFAULT NULL,
                uptime_ticks BIGINT UNSIGNED DEFAULT NULL,
                varbinds_json LONGTEXT DEFAULT NULL,
                alarm_bridged TINYINT(1) NOT NULL DEFAULT 0,
                received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_snmp_trap_source (source_ip, received_at),
                KEY idx_snmp_trap_device (device_key, received_at),
                KEY idx_snmp_trap_oid (trap_oid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS snmp_trap_log');
    },
];
