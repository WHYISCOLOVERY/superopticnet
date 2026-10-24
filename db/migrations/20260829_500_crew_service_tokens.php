<?php
declare(strict_types=1);

const APP_CREW_SERVICE_TOKENS_MIGRATION_20260829_500 = '20260829_500_crew_service_tokens';

return [
    'version' => APP_CREW_SERVICE_TOKENS_MIGRATION_20260829_500,
    'description' => 'Create crew_service_tokens -- maps a hashed API token to an existing '
        . 'employee (portal_accounts) so AI-crew agents authenticate AS that department '
        . 'service account (token-only, no password login). Each request is attributed to '
        . 'that employee for audit + module-permission scoping (least privilege via position).',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS crew_service_tokens (
                token_hash CHAR(64) NOT NULL PRIMARY KEY,
                account_id BIGINT UNSIGNED NOT NULL,
                department VARCHAR(48) NOT NULL DEFAULT '',
                label VARCHAR(96) NOT NULL DEFAULT '',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at DATETIME DEFAULT NULL,
                KEY idx_crew_service_tokens_account (account_id),
                KEY idx_crew_service_tokens_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS crew_service_tokens');
    },
];
