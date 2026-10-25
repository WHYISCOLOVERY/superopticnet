<?php
declare(strict_types=1);

const APP_LEGACY_CUSTOMER_SECRET_MIGRATION_20260729_190 = '20260729_190_legacy_customer_secret_vault';

return [
    'version' => APP_LEGACY_CUSTOMER_SECRET_MIGRATION_20260729_190,
    'description' => 'Create an encrypted vault for imported customer service credentials',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS legacy_customer_secret_vault (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id BIGINT UNSIGNED NOT NULL,
                secret_type VARCHAR(64) NOT NULL,
                algorithm VARCHAR(32) NOT NULL DEFAULT 'aes-256-gcm',
                key_version VARCHAR(32) NOT NULL DEFAULT 'v1',
                ciphertext_b64 LONGTEXT NOT NULL,
                nonce_b64 VARCHAR(64) NOT NULL,
                auth_tag_b64 VARCHAR(64) NOT NULL,
                source VARCHAR(64) NOT NULL DEFAULT 'old_billing',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_legacy_customer_secret (account_id, secret_type),
                KEY idx_legacy_customer_secret_type (secret_type, updated_at),
                CONSTRAINT fk_legacy_customer_secret_account
                    FOREIGN KEY (account_id) REFERENCES portal_accounts(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS legacy_customer_secret_vault');
    },
];
