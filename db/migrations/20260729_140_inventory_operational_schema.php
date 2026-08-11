<?php
declare(strict_types=1);

const APP_INVENTORY_OPERATIONAL_MIGRATION_20260729_140 = '20260729_140_inventory_operational_schema';

return [
    'version' => APP_INVENTORY_OPERATIONAL_MIGRATION_20260729_140,
    'description' => 'Create inventory location and document tables outside HTTP rendering',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS inventory_location_balances (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                item_id BIGINT UNSIGNED NOT NULL,
                location_type ENUM('warehouse','branch','employee','external') NOT NULL,
                location_id VARCHAR(128) NOT NULL,
                available_quantity DECIMAL(14,2) NOT NULL DEFAULT 0,
                reserved_quantity DECIMAL(14,2) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_inventory_location_balance (item_id, location_type, location_id),
                KEY idx_inventory_location_lookup (location_type, location_id),
                CONSTRAINT fk_inventory_location_item
                    FOREIGN KEY (item_id) REFERENCES inventory_stock_items(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS inventory_document_sequences (
                document_type VARCHAR(16) NOT NULL,
                fiscal_year SMALLINT UNSIGNED NOT NULL,
                next_value BIGINT UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (document_type, fiscal_year)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS inventory_operational_locks (
                lock_name VARCHAR(64) PRIMARY KEY,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS inventory_documents (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                document_no VARCHAR(64) NOT NULL,
                document_type VARCHAR(32) NOT NULL,
                status ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft',
                entity_item_id BIGINT UNSIGNED DEFAULT NULL,
                source_location_type VARCHAR(32) DEFAULT NULL,
                source_location_id VARCHAR(128) DEFAULT NULL,
                destination_type VARCHAR(32) DEFAULT NULL,
                destination_id VARCHAR(128) DEFAULT NULL,
                destination_name VARCHAR(191) DEFAULT NULL,
                reason VARCHAR(96) DEFAULT NULL,
                disposition VARCHAR(64) DEFAULT NULL,
                note TEXT DEFAULT NULL,
                rejection_reason TEXT DEFAULT NULL,
                created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                created_by_name VARCHAR(191) DEFAULT NULL,
                approved_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                approved_by_name VARCHAR(191) DEFAULT NULL,
                approved_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_inventory_document_no (document_no),
                KEY idx_inventory_document_status (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS inventory_document_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                document_id BIGINT UNSIGNED NOT NULL,
                item_id BIGINT UNSIGNED DEFAULT NULL,
                line_no INT UNSIGNED NOT NULL,
                item_name VARCHAR(191) NOT NULL,
                quantity DECIMAL(14,2) NOT NULL,
                unit VARCHAR(32) NOT NULL DEFAULT 'eded',
                unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
                mac_address VARCHAR(32) DEFAULT NULL,
                serial_number VARCHAR(128) DEFAULT NULL,
                note VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_inventory_document_item (document_id, line_no),
                KEY idx_inventory_document_stock (item_id),
                CONSTRAINT fk_inventory_document_line
                    FOREIGN KEY (document_id) REFERENCES inventory_documents(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $db->exec(
            "INSERT IGNORE INTO inventory_operational_locks (lock_name)
             VALUES ('location_reconcile')"
        );
        $db->exec(
            "INSERT INTO inventory_location_balances
                (item_id, location_type, location_id, available_quantity)
             SELECT id, 'warehouse', 'MAIN', quantity_on_hand
               FROM inventory_stock_items
              WHERE quantity_on_hand > 0
             ON DUPLICATE KEY UPDATE
                available_quantity = VALUES(available_quantity)"
        );
    },
    'down' => static function (PDO $db): void {
        $documentCount = 0;
        try {
            $documentCount = (int) $db->query('SELECT COUNT(*) FROM inventory_documents')->fetchColumn();
        } catch (Throwable) {
        }
        if ($documentCount > 0) {
            throw new RuntimeException('inventory_operational_rollback_requires_no_documents');
        }

        $db->exec('DROP TABLE IF EXISTS inventory_document_items');
        $db->exec('DROP TABLE IF EXISTS inventory_documents');
        $db->exec('DROP TABLE IF EXISTS inventory_operational_locks');
        $db->exec('DROP TABLE IF EXISTS inventory_document_sequences');
        $db->exec('DROP TABLE IF EXISTS inventory_location_balances');
    },
];
