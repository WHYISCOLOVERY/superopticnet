<?php
declare(strict_types=1);

const APP_BILLING_IDENTITY_MIGRATION_20260729_150 = '20260729_150_billing_customer_identity';

return [
    'version' => APP_BILLING_IDENTITY_MIGRATION_20260729_150,
    'description' => 'Create billing customer identity claims outside HTTP rendering',
    'up' => static function (PDO $db): void {
        if (!app_db_column_exists($db, 'portal_accounts', 'fin_code')) {
            $db->exec('ALTER TABLE portal_accounts ADD COLUMN fin_code VARCHAR(32) DEFAULT NULL AFTER contract_no');
        }
        if (!app_db_column_exists($db, 'portal_accounts', 'document_no')) {
            $db->exec('ALTER TABLE portal_accounts ADD COLUMN document_no VARCHAR(64) DEFAULT NULL AFTER fin_code');
        }
        if (!app_db_index_exists($db, 'portal_accounts', 'idx_portal_accounts_fin')
            && !app_db_index_exists($db, 'portal_accounts', 'idx_portal_accounts_fin_code')) {
            $db->exec('CREATE INDEX idx_portal_accounts_fin ON portal_accounts (fin_code)');
        }
        $db->exec(
            "CREATE TABLE IF NOT EXISTS billing_customer_fin_claims (
                fin_code VARCHAR(32) NOT NULL PRIMARY KEY,
                account_id BIGINT UNSIGNED NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_billing_fin_claim_account (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "INSERT IGNORE INTO billing_customer_fin_claims (fin_code, account_id)
             SELECT fin_code, id
               FROM portal_accounts
              WHERE role = 'customer'
                AND is_active = 1
                AND fin_code IS NOT NULL
                AND fin_code <> ''
              ORDER BY id"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS billing_customer_fin_claims');
    },
];
