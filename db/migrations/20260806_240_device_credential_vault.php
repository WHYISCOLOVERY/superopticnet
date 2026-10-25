<?php
declare(strict_types=1);

const APP_DEVICE_CREDENTIAL_VAULT_MIGRATION_20260806_240 = '20260806_240_device_credential_vault';

return [
    'version' => APP_DEVICE_CREDENTIAL_VAULT_MIGRATION_20260806_240,
    'description' => 'Create an encrypted vault for NOC device SSH/SNMP credentials (device_access_overrides currently stores these in plaintext)',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS device_credential_vault (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_key VARCHAR(191) NOT NULL,
                credential_type VARCHAR(32) NOT NULL,
                algorithm VARCHAR(32) NOT NULL DEFAULT 'aes-256-gcm',
                key_version VARCHAR(32) NOT NULL DEFAULT 'v1',
                ciphertext_b64 LONGTEXT NOT NULL,
                nonce_b64 VARCHAR(64) NOT NULL,
                auth_tag_b64 VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_device_credential (device_key, credential_type),
                KEY idx_device_credential_type (credential_type, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS device_credential_vault');
    },
];
