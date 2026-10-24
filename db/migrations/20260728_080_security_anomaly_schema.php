<?php
declare(strict_types=1);

const APP_SECURITY_MIGRATION_20260728_080 = '20260728_080_security_anomaly_schema';

function app_security_migration_table_exists_20260728_080(PDO $db, string $table): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $statement->execute(['table_name' => $table]);
    return (int) $statement->fetchColumn() > 0;
}

return [
    'version' => APP_SECURITY_MIGRATION_20260728_080,
    'description' => 'Create the security anomaly event ledger outside the runtime request path',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS security_anomaly_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                dedup_key VARCHAR(191) NOT NULL,
                subject_type VARCHAR(64) NOT NULL DEFAULT 'customer',
                subject_id VARCHAR(191) NOT NULL DEFAULT '',
                subscriber_code VARCHAR(64) DEFAULT NULL,
                username VARCHAR(64) DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                risk_score INT NOT NULL DEFAULT 0,
                severity ENUM('info','warning','critical','emergency') NOT NULL DEFAULT 'info',
                signal_type VARCHAR(64) NOT NULL DEFAULT '',
                title VARCHAR(191) NOT NULL DEFAULT '',
                evidence_json LONGTEXT DEFAULT NULL,
                recommended_actions_json LONGTEXT DEFAULT NULL,
                status ENUM('open','ack','resolved','false_positive','test') NOT NULL DEFAULT 'open',
                first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                seen_count INT UNSIGNED NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_security_anomaly_dedup (dedup_key),
                KEY idx_security_anomaly_status (status, severity, last_seen_at),
                KEY idx_security_anomaly_subject (subject_type, subject_id),
                KEY idx_security_anomaly_user (username, last_seen_at),
                KEY idx_security_anomaly_subscriber (subscriber_code, last_seen_at),
                KEY idx_security_anomaly_ip (ip_address, last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        if (!app_security_migration_table_exists_20260728_080($db, 'security_anomaly_events')) {
            return;
        }
        if ((int) $db->query('SELECT COUNT(*) FROM security_anomaly_events')->fetchColumn() > 0) {
            throw new RuntimeException('security_anomaly_rollback_requires_empty_table');
        }
        $db->exec('DROP TABLE security_anomaly_events');
    },
];
