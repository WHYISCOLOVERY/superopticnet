<?php
declare(strict_types=1);

const APP_INTEGRATION_API_MIGRATION_20260729_110 = '20260729_110_integration_api_clients';

function app_integration_api_table_exists_20260729_110(PDO $db, string $table): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $statement->execute(['table_name' => $table]);
    return (int) $statement->fetchColumn() > 0;
}

return [
    'version' => APP_INTEGRATION_API_MIGRATION_20260729_110,
    'description' => 'Create scoped payment-provider API client credential registry',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS integration_api_clients (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                provider_slug VARCHAR(64) NOT NULL,
                display_name VARCHAR(191) NOT NULL,
                key_id VARCHAR(96) NOT NULL,
                secret_hash VARCHAR(255) NOT NULL,
                scopes_json LONGTEXT NOT NULL,
                allowed_ips_json LONGTEXT NULL,
                status ENUM('active','revoked') NOT NULL DEFAULT 'active',
                expires_at DATETIME NULL,
                last_used_at DATETIME NULL,
                last_used_ip VARCHAR(45) NULL,
                created_by_account_id BIGINT UNSIGNED NULL,
                rotated_from_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                revoked_at DATETIME NULL,
                revoked_by_account_id BIGINT UNSIGNED NULL,
                revoke_reason VARCHAR(255) NULL,
                UNIQUE KEY uq_integration_api_key_id (key_id),
                KEY idx_integration_api_provider_status (provider_slug, status),
                KEY idx_integration_api_expiry (status, expires_at),
                KEY idx_integration_api_last_used (last_used_at),
                CONSTRAINT fk_integration_api_rotated_from
                    FOREIGN KEY (rotated_from_id) REFERENCES integration_api_clients(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        if (!app_integration_api_table_exists_20260729_110($db, 'integration_api_clients')) {
            return;
        }
        if ((int) $db->query('SELECT COUNT(*) FROM integration_api_clients')->fetchColumn() > 0) {
            throw new RuntimeException('integration_api_clients_rollback_requires_empty_table');
        }
        $db->exec('DROP TABLE integration_api_clients');
    },
];
