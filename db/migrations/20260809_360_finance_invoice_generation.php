<?php
declare(strict_types=1);

const APP_FINANCE_INVOICE_GENERATION_MIGRATION_20260809_360 = '20260809_360_finance_invoice_generation';

return [
    'version' => APP_FINANCE_INVOICE_GENERATION_MIGRATION_20260809_360,
    'description' => 'Add finance_invoices (real generated customer invoice with sequential numbering + VAT) and finance_invoice_counters -- finance_e_invoice_imports is only an import/tracking table for external e-qaime documents, there was no generated-invoice concept before this',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS finance_invoice_counters (
                fiscal_year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
                next_seq INT UNSIGNED NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS finance_invoices (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                invoice_no VARCHAR(32) NOT NULL,
                account_id BIGINT UNSIGNED DEFAULT NULL,
                counterparty_name VARCHAR(191) NOT NULL,
                source_type ENUM('billing_payment','manual') NOT NULL DEFAULT 'manual',
                source_id BIGINT UNSIGNED DEFAULT NULL,
                issue_date DATE NOT NULL,
                description VARCHAR(255) NOT NULL DEFAULT '',
                subtotal_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                vat_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00,
                vat_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                currency CHAR(3) NOT NULL DEFAULT 'AZN',
                status ENUM('issued','void') NOT NULL DEFAULT 'issued',
                void_reason VARCHAR(255) DEFAULT NULL,
                voided_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                voided_by_name VARCHAR(191) DEFAULT NULL,
                voided_at DATETIME DEFAULT NULL,
                created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                created_by_name VARCHAR(191) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_finance_invoice_no (invoice_no),
                KEY idx_finance_invoice_account (account_id, issue_date),
                KEY idx_finance_invoice_source (source_type, source_id),
                KEY idx_finance_invoice_status (status, issue_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS finance_invoices');
        $db->exec('DROP TABLE IF EXISTS finance_invoice_counters');
    },
];
