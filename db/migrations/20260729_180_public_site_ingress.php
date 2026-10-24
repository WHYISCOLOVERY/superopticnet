<?php
declare(strict_types=1);

const APP_PUBLIC_SITE_INGRESS_MIGRATION_20260729_180 = '20260729_180_public_site_ingress';

return [
    'version' => APP_PUBLIC_SITE_INGRESS_MIGRATION_20260729_180,
    'description' => 'Create replay protection storage for signed public website requests',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS public_ingress_nonces (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                client_key_id VARCHAR(96) NOT NULL,
                request_nonce VARCHAR(96) NOT NULL,
                request_hash CHAR(64) NOT NULL,
                source_ip VARCHAR(64) DEFAULT NULL,
                received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_public_ingress_nonce (client_key_id, request_nonce),
                KEY idx_public_ingress_received (received_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS public_ingress_nonces');
    },
];
