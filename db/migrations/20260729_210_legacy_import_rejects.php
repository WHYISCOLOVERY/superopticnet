<?php
declare(strict_types=1);

const APP_LEGACY_IMPORT_REJECTS_MIGRATION_20260729_210 = '20260729_210_legacy_import_rejects';

return [
    'version' => APP_LEGACY_IMPORT_REJECTS_MIGRATION_20260729_210,
    'description' => 'Preserve legacy source rows that cannot be linked to a production entity',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS legacy_import_rejects (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_type VARCHAR(64) NOT NULL,
                source_key VARCHAR(191) NOT NULL,
                rejection_reason VARCHAR(128) NOT NULL,
                raw_payload_json LONGTEXT NOT NULL,
                first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_legacy_import_reject (source_type, source_key),
                KEY idx_legacy_import_reject_reason (source_type, rejection_reason)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS legacy_import_rejects');
    },
];
