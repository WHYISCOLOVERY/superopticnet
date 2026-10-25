<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/audit-log-lib.php';
require_once __DIR__ . '/communication-provider.php';
require_once __DIR__ . '/noc-authorization-lib.php';
require_once __DIR__ . '/device-credential-vault-lib.php';
require_once __DIR__ . '/business-rules-lib.php';

function app_mgs_mask(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '-';
    }

    $length = strlen($value);
    if ($length <= 6) {
        return str_repeat('*', $length);
    }

    return substr($value, 0, 3) . str_repeat('*', max(3, $length - 6)) . substr($value, -3);
}

function app_mgs_required_schema_tables(): array
{
    return [
        'mgs_alarm_events',
        'mgs_operation_actions',
        'device_learning_profiles',
        'device_access_overrides',
        'monitor_map_nodes',
        'mgs_runtime_settings',
        'mgs_process_registry',
        'mgs_dedup_registry',
        'telegram_bot_registry',
        'inventory_stock_items',
        'inventory_categories',
        'inventory_stock_movements',
        'inventory_asset_models',
        'inventory_assets',
        'inventory_cable_drums',
        'inventory_lifecycle_movements',
        'inventory_work_order_materials',
        'customer_equipment_assignments',
        'inventory_asset_returns',
        'inventory_asset_inspections',
        'inventory_device_detection_evidence',
        'finance_asset_depreciations',
        'finance_debt_ledger',
        'finance_debt_payments',
        'finance_e_invoice_imports',
        'hr_payroll_legal_profiles',
        'hr_payroll_records',
        'hr_leave_requests',
        'hr_travel_requests',
        'hr_employee_documents',
        'hr_employee_compensations',
        'hr_attendance_profiles',
        'hr_attendance_events',
        'hr_score_events',
        'employee_work_snapshots',
        'device_config_backups',
    ];
}

function app_mgs_schema_ready(PDO $db): bool
{
    // Memoize a positive result per request/connection: this runs an
    // information_schema query and is reached from 100+ call sites per page.
    static $readyFor = null;
    if ($readyFor === spl_object_id($db)) {
        return true;
    }

    $tables = app_mgs_required_schema_tables();
    $placeholders = implode(',', array_fill(0, count($tables), '?'));
    try {
        $statement = $db->prepare(
            'SELECT COUNT(*)
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN (' . $placeholders . ')'
        );
        $statement->execute($tables);
        if ((int) $statement->fetchColumn() === count($tables)) {
            $readyFor = spl_object_id($db);
            return true;
        }
        return false;
    } catch (Throwable) {
        return false;
    }
}

function app_mgs_ensure_schema(?PDO $db = null): bool
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO) {
        return false;
    }
    // Once the schema was ensured on this connection, skip the 36 CREATE TABLE
    // IF NOT EXISTS statements and seeds for the rest of the request.
    static $ensuredFor = null;
    if ($ensuredFor === spl_object_id($db)) {
        return true;
    }
    if (strtolower((string) app_setting('db_bootstrap_mode', 'cached')) === 'off') {
        return app_mgs_schema_ready($db);
    }

    $statements = [
        "CREATE TABLE IF NOT EXISTS mgs_alarm_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dedup_key VARCHAR(191) NOT NULL,
            alarm_type VARCHAR(64) NOT NULL,
            severity VARCHAR(32) NOT NULL DEFAULT 'info',
            source VARCHAR(64) NOT NULL DEFAULT 'mgs',
            object_type VARCHAR(64) NOT NULL DEFAULT '',
            object_id VARCHAR(191) NOT NULL DEFAULT '',
            title VARCHAR(191) NOT NULL DEFAULT '',
            message TEXT DEFAULT NULL,
            status ENUM('open','ack','resolved','suppressed','test') NOT NULL DEFAULT 'open',
            ticket_no VARCHAR(32) DEFAULT NULL,
            telegram_bot_key VARCHAR(64) DEFAULT NULL,
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            seen_count INT UNSIGNED NOT NULL DEFAULT 1,
            payload_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mgs_alarm_dedup (dedup_key),
            KEY idx_mgs_alarm_status (status, severity, last_seen_at),
            KEY idx_mgs_alarm_object (object_type, object_id),
            KEY idx_mgs_alarm_ticket (ticket_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mgs_operation_actions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            action_type VARCHAR(64) NOT NULL,
            entity_type VARCHAR(64) NOT NULL DEFAULT '',
            entity_id VARCHAR(191) NOT NULL DEFAULT '',
            status ENUM('planned','dry_run','pending','executed','failed','skipped','reversed') NOT NULL DEFAULT 'planned',
            actor_account_id BIGINT UNSIGNED DEFAULT NULL,
            actor_name VARCHAR(191) DEFAULT NULL,
            summary VARCHAR(255) DEFAULT NULL,
            ticket_no VARCHAR(32) DEFAULT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            executed_at DATETIME DEFAULT NULL,
            KEY idx_mgs_operation_type (action_type, created_at),
            KEY idx_mgs_operation_entity (entity_type, entity_id),
            KEY idx_mgs_operation_actor (actor_account_id, created_at),
            KEY idx_mgs_operation_ticket (ticket_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS device_learning_profiles (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_key VARCHAR(128) NOT NULL,
            device_name VARCHAR(191) NOT NULL,
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            device_type VARCHAR(64) NOT NULL DEFAULT 'unknown',
            vendor VARCHAR(64) NOT NULL DEFAULT 'unknown',
            model VARCHAR(128) NOT NULL DEFAULT '',
            adapter_key VARCHAR(128) NOT NULL DEFAULT 'GenericSnmpAdapter',
            management_network VARCHAR(64) NOT NULL DEFAULT '',
            expected_reachability TINYINT(1) NOT NULL DEFAULT 1,
            monitoring_enabled TINYINT(1) NOT NULL DEFAULT 1,
            reachability_state ENUM('reachable','unreachable_confirmed','unreachable_because_monitor_path_down','not_monitored','unknown') NOT NULL DEFAULT 'unknown',
            confidence_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            features_json LONGTEXT DEFAULT NULL,
            command_map_json LONGTEXT DEFAULT NULL,
            snmp_oids_json LONGTEXT DEFAULT NULL,
            status ENUM('pending_discovery','learned','waiting_mgmt_path','unsupported','failed') NOT NULL DEFAULT 'pending_discovery',
            last_discovered_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_device_learning_key (device_key),
            KEY idx_device_learning_ip (ip_address),
            KEY idx_device_learning_type (device_type, vendor),
            KEY idx_device_learning_reachability (reachability_state, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS device_access_overrides (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_key VARCHAR(128) NOT NULL,
            ssh_username VARCHAR(128) DEFAULT NULL,
            ssh_password VARCHAR(191) DEFAULT NULL,
            ssh_port SMALLINT UNSIGNED NOT NULL DEFAULT 22,
            snmp_read_community VARCHAR(128) DEFAULT NULL,
            snmp_write_community VARCHAR(128) DEFAULT NULL,
            snmp_port SMALLINT UNSIGNED NOT NULL DEFAULT 161,
            trap_server VARCHAR(64) DEFAULT NULL,
            trap_port SMALLINT UNSIGNED NOT NULL DEFAULT 162,
            contact VARCHAR(191) DEFAULT NULL,
            location VARCHAR(191) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            updated_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            updated_by_name VARCHAR(191) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_device_access_override_key (device_key),
            KEY idx_device_access_override_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS live_olt_ont_inventory (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_key VARCHAR(128) NOT NULL,
            pon VARCHAR(32) NOT NULL,
            ont_id VARCHAR(32) NOT NULL,
            serial_number VARCHAR(128) NOT NULL DEFAULT '',
            ont_name VARCHAR(191) NOT NULL DEFAULT '',
            status VARCHAR(32) NOT NULL DEFAULT 'unknown',
            temperature VARCHAR(64) NOT NULL DEFAULT '',
            ont_rx VARCHAR(64) NOT NULL DEFAULT '',
            ont_tx VARCHAR(64) NOT NULL DEFAULT '',
            olt_rx VARCHAR(64) NOT NULL DEFAULT '',
            discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            raw_source VARCHAR(32) NOT NULL DEFAULT 'cli',
            UNIQUE KEY uq_live_olt_ont_path (device_key, pon, ont_id),
            KEY idx_live_olt_ont_serial (serial_number),
            KEY idx_live_olt_ont_seen (last_seen_at),
            KEY idx_live_olt_ont_status (device_key, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS monitor_map_nodes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            map_key VARCHAR(64) NOT NULL DEFAULT 'noc-monitor',
            account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            device_id VARCHAR(191) NOT NULL,
            x DECIMAL(6,2) NOT NULL DEFAULT 50.00,
            y DECIMAL(6,2) NOT NULL DEFAULT 50.00,
            scale DECIMAL(4,2) NOT NULL DEFAULT 1.00,
            note TEXT DEFAULT NULL,
            updated_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            updated_by_name VARCHAR(191) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_monitor_map_node (map_key, account_id, device_id),
            KEY idx_monitor_map_account (account_id, updated_at),
            KEY idx_monitor_map_device (device_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mgs_runtime_settings (
            setting_key VARCHAR(128) PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL DEFAULT '',
            updated_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            updated_by_name VARCHAR(191) DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mgs_process_registry (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            process_key VARCHAR(128) NOT NULL,
            process_group VARCHAR(64) NOT NULL DEFAULT 'general',
            title VARCHAR(191) NOT NULL,
            owner_role VARCHAR(64) NOT NULL DEFAULT 'system',
            source_module VARCHAR(64) NOT NULL DEFAULT '',
            target_module VARCHAR(64) NOT NULL DEFAULT '',
            status ENUM('ready','partial','blocked','planned','disabled') NOT NULL DEFAULT 'planned',
            readiness_state VARCHAR(64) NOT NULL DEFAULT '',
            dedup_policy VARCHAR(191) NOT NULL DEFAULT '',
            notes TEXT DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            last_checked_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mgs_process_key (process_key),
            KEY idx_mgs_process_group (process_group, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mgs_dedup_registry (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dedup_key VARCHAR(191) NOT NULL,
            dedup_domain VARCHAR(64) NOT NULL DEFAULT 'alarm',
            title VARCHAR(191) NOT NULL DEFAULT '',
            status ENUM('open','resolved','suppressed','test') NOT NULL DEFAULT 'open',
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            seen_count INT UNSIGNED NOT NULL DEFAULT 1,
            metadata_json LONGTEXT DEFAULT NULL,
            UNIQUE KEY uq_mgs_dedup_key (dedup_key),
            KEY idx_mgs_dedup_domain_status (dedup_domain, status, last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS telegram_bot_registry (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bot_key VARCHAR(64) NOT NULL,
            display_name VARCHAR(191) NOT NULL,
            purpose VARCHAR(255) NOT NULL DEFAULT '',
            token_config_key VARCHAR(128) NOT NULL DEFAULT '',
            chat_config_key VARCHAR(128) NOT NULL DEFAULT '',
            configured TINYINT(1) NOT NULL DEFAULT 0,
            destination_masked VARCHAR(64) NOT NULL DEFAULT '',
            status ENUM('ready','missing_config','test_failed','disabled') NOT NULL DEFAULT 'missing_config',
            last_test_at DATETIME DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_telegram_bot_key (bot_key),
            KEY idx_telegram_bot_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS inventory_stock_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(128) NOT NULL,
            item_name VARCHAR(191) NOT NULL,
            category VARCHAR(64) NOT NULL DEFAULT 'general',
            unit VARCHAR(32) NOT NULL DEFAULT 'eded',
            quantity_on_hand DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            minimum_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_inventory_stock_sku (sku),
            KEY idx_inventory_stock_category (category, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS inventory_categories (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category_code VARCHAR(64) NOT NULL,
            category_name VARCHAR(128) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            sort_order INT UNSIGNED NOT NULL DEFAULT 100,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_inventory_category_code (category_code),
            KEY idx_inventory_category_status (status, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS inventory_stock_movements (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_id BIGINT UNSIGNED NOT NULL,
            movement_type ENUM('in','out','issue','return','adjust','customer_install','test') NOT NULL DEFAULT 'issue',
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            ticket_no VARCHAR(32) DEFAULT NULL,
            customer_account_id BIGINT UNSIGNED DEFAULT NULL,
            technician_account_id BIGINT UNSIGNED DEFAULT NULL,
            actor_account_id BIGINT UNSIGNED DEFAULT NULL,
            actor_name VARCHAR(191) DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            status ENUM('planned','posted','dry_run','cancelled') NOT NULL DEFAULT 'posted',
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_inventory_movement_item (item_id, created_at),
            KEY idx_inventory_movement_ticket (ticket_no),
            KEY idx_inventory_movement_tech (technician_account_id, created_at),
            CONSTRAINT fk_inventory_movement_item FOREIGN KEY (item_id) REFERENCES inventory_stock_items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS inventory_asset_models (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vendor VARCHAR(96) NOT NULL DEFAULT '',
            model VARCHAR(128) NOT NULL DEFAULT '',
            device_type VARCHAR(64) NOT NULL DEFAULT 'modem',
            wifi_generation VARCHAR(32) NOT NULL DEFAULT 'unknown',
            supports_dual_band TINYINT(1) NOT NULL DEFAULT 0,
            ethernet_port_count INT UNSIGNED DEFAULT NULL,
            supports_tr069 TINYINT(1) NOT NULL DEFAULT 0,
            detection_rules_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_inventory_asset_model (vendor, model, device_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS inventory_assets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED DEFAULT NULL,
            model_id BIGINT UNSIGNED DEFAULT NULL,
            asset_code VARCHAR(96) NOT NULL,
            serial_number VARCHAR(128) DEFAULT NULL,
            serial_number_normalized VARCHAR(128) DEFAULT NULL,
            mac_address VARCHAR(32) DEFAULT NULL,
            mac_address_normalized VARCHAR(32) DEFAULT NULL,
            ownership_type ENUM('company','customer_owned','rented','unknown') NOT NULL DEFAULT 'company',
            lifecycle_status VARCHAR(48) NOT NULL DEFAULT 'in_warehouse',
            condition_status VARCHAR(48) NOT NULL DEFAULT 'unknown',
            current_location_type VARCHAR(48) NOT NULL DEFAULT 'warehouse',
            current_location_id VARCHAR(128) DEFAULT NULL,
            installation_count INT UNSIGNED NOT NULL DEFAULT 0,
            failure_count INT UNSIGNED NOT NULL DEFAULT 0,
            return_count INT UNSIGNED NOT NULL DEFAULT 0,
            notes TEXT DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_inventory_asset_code (asset_code),
            UNIQUE KEY uq_inventory_asset_serial (serial_number_normalized),
            UNIQUE KEY uq_inventory_asset_mac (mac_address_normalized),
            KEY idx_inventory_asset_location (current_location_type, current_location_id),
            KEY idx_inventory_asset_status (lifecycle_status, condition_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS inventory_cable_drums (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            drum_number VARCHAR(96) NOT NULL,
            product_id BIGINT UNSIGNED DEFAULT NULL,
            initial_length_meters DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            remaining_length_meters DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            reserved_length_meters DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            location_type VARCHAR(48) NOT NULL DEFAULT 'warehouse',
            location_id VARCHAR(128) DEFAULT NULL,
            condition_status VARCHAR(48) NOT NULL DEFAULT 'available',
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_inventory_cable_drum (drum_number),
            KEY idx_inventory_cable_location (location_type, location_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS inventory_lifecycle_movements (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            movement_no VARCHAR(96) NOT NULL,
            movement_type VARCHAR(64) NOT NULL,
            product_id BIGINT UNSIGNED DEFAULT NULL,
            asset_id BIGINT UNSIGNED DEFAULT NULL,
            cable_drum_id BIGINT UNSIGNED DEFAULT NULL,
            quantity DECIMAL(12,2) DEFAULT NULL,
            length_meters DECIMAL(12,2) DEFAULT NULL,
            from_location_type VARCHAR(48) DEFAULT NULL,
            from_location_id VARCHAR(128) DEFAULT NULL,
            to_location_type VARCHAR(48) DEFAULT NULL,
            to_location_id VARCHAR(128) DEFAULT NULL,
            customer_code VARCHAR(64) DEFAULT NULL,
            technician_code VARCHAR(64) DEFAULT NULL,
            ticket_no VARCHAR(64) DEFAULT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            note TEXT DEFAULT NULL,
            performed_by VARCHAR(191) DEFAULT NULL,
            approved_by VARCHAR(191) DEFAULT NULL,
            idempotency_key VARCHAR(191) DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_inventory_lifecycle_movement_no (movement_no),
            UNIQUE KEY uq_inventory_lifecycle_idempotency (idempotency_key),
            KEY idx_inventory_lifecycle_asset (asset_id, created_at),
            KEY idx_inventory_lifecycle_customer (customer_code, created_at),
            KEY idx_inventory_lifecycle_tech (technician_code, created_at),
            KEY idx_inventory_lifecycle_ticket (ticket_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS inventory_work_order_materials (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_no VARCHAR(64) NOT NULL,
            customer_code VARCHAR(64) DEFAULT NULL,
            technician_code VARCHAR(64) DEFAULT NULL,
            product_id BIGINT UNSIGNED DEFAULT NULL,
            asset_id BIGINT UNSIGNED DEFAULT NULL,
            cable_drum_id BIGINT UNSIGNED DEFAULT NULL,
            quantity DECIMAL(12,2) DEFAULT NULL,
            length_meters DECIMAL(12,2) DEFAULT NULL,
            unit VARCHAR(32) NOT NULL DEFAULT 'eded',
            movement_id BIGINT UNSIGNED DEFAULT NULL,
            note TEXT DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_inventory_work_order_ticket (ticket_no),
            KEY idx_inventory_work_order_customer (customer_code),
            KEY idx_inventory_work_order_tech (technician_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS customer_equipment_assignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_code VARCHAR(64) NOT NULL,
            asset_id BIGINT UNSIGNED DEFAULT NULL,
            serial_number VARCHAR(128) DEFAULT NULL,
            mac_address VARCHAR(32) DEFAULT NULL,
            ownership_type ENUM('company','customer_owned','unknown') NOT NULL DEFAULT 'company',
            assignment_status ENUM('active','removed','returned','replaced','cancelled') NOT NULL DEFAULT 'active',
            installed_by VARCHAR(64) DEFAULT NULL,
            installed_ticket_no VARCHAR(64) DEFAULT NULL,
            removed_ticket_no VARCHAR(64) DEFAULT NULL,
            installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            removed_at DATETIME DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_customer_equipment_customer (customer_code, assignment_status),
            KEY idx_customer_equipment_asset (asset_id, assignment_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS inventory_asset_returns (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            asset_id BIGINT UNSIGNED NOT NULL,
            customer_code VARCHAR(64) DEFAULT NULL,
            technician_code VARCHAR(64) DEFAULT NULL,
            return_ticket_no VARCHAR(64) DEFAULT NULL,
            return_reason VARCHAR(255) DEFAULT NULL,
            return_status ENUM('pending','received','inspected','closed','cancelled') NOT NULL DEFAULT 'pending',
            received_by VARCHAR(191) DEFAULT NULL,
            received_at DATETIME DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_inventory_return_asset (asset_id, return_status),
            KEY idx_inventory_return_ticket (return_ticket_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS inventory_asset_inspections (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            return_id BIGINT UNSIGNED DEFAULT NULL,
            asset_id BIGINT UNSIGNED NOT NULL,
            inspection_status VARCHAR(48) NOT NULL DEFAULT 'used_working',
            inspected_by VARCHAR(191) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            inspected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_inventory_inspection_asset (asset_id, inspected_at),
            KEY idx_inventory_inspection_status (inspection_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS inventory_device_detection_evidence (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            asset_id BIGINT UNSIGNED DEFAULT NULL,
            customer_code VARCHAR(64) DEFAULT NULL,
            source_type VARCHAR(64) NOT NULL,
            serial_number VARCHAR(128) DEFAULT NULL,
            mac_address VARCHAR(32) DEFAULT NULL,
            olt_name VARCHAR(191) DEFAULT NULL,
            pon_port VARCHAR(64) DEFAULT NULL,
            ont_id VARCHAR(64) DEFAULT NULL,
            confidence DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            evidence_json LONGTEXT DEFAULT NULL,
            detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_inventory_detection_asset (asset_id, detected_at),
            KEY idx_inventory_detection_customer (customer_code, detected_at),
            KEY idx_inventory_detection_source (source_type, detected_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS finance_asset_depreciations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            asset_id BIGINT UNSIGNED DEFAULT NULL,
            stock_item_id BIGINT UNSIGNED DEFAULT NULL,
            asset_label VARCHAR(191) NOT NULL DEFAULT '',
            fiscal_year SMALLINT UNSIGNED NOT NULL,
            acquisition_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            depreciation_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            depreciation_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            net_book_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            writeoff_threshold DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            writeoff_allowed TINYINT(1) NOT NULL DEFAULT 0,
            writeoff_status ENUM('active','eligible','written_off','blocked') NOT NULL DEFAULT 'active',
            note TEXT DEFAULT NULL,
            calculation_json LONGTEXT DEFAULT NULL,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_by_name VARCHAR(191) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_finance_depr_asset (asset_id, fiscal_year),
            KEY idx_finance_depr_status (writeoff_status, fiscal_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS finance_debt_ledger (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ledger_type ENUM('receivable','payable') NOT NULL,
            counterparty_name VARCHAR(191) NOT NULL,
            counterparty_type VARCHAR(64) NOT NULL DEFAULT 'other',
            amount_original DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            amount_open DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            currency CHAR(3) NOT NULL DEFAULT 'AZN',
            document_no VARCHAR(128) DEFAULT NULL,
            due_date DATE DEFAULT NULL,
            status ENUM('open','partial','closed','void') NOT NULL DEFAULT 'open',
            note TEXT DEFAULT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_by_name VARCHAR(191) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_finance_debt_type (ledger_type, status, due_date),
            KEY idx_finance_debt_counterparty (counterparty_name, ledger_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS finance_debt_payments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            debt_id BIGINT UNSIGNED NOT NULL,
            payment_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_date DATE DEFAULT NULL,
            payment_method VARCHAR(64) NOT NULL DEFAULT 'manual',
            reference_no VARCHAR(128) DEFAULT NULL,
            note TEXT DEFAULT NULL,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_by_name VARCHAR(191) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_finance_debt_payment_debt (debt_id, created_at),
            KEY idx_finance_debt_payment_ref (reference_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS finance_e_invoice_imports (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            import_batch VARCHAR(96) NOT NULL,
            debt_id BIGINT UNSIGNED DEFAULT NULL,
            ledger_type ENUM('receivable','payable') NOT NULL,
            document_no VARCHAR(128) DEFAULT NULL,
            counterparty_name VARCHAR(191) NOT NULL,
            counterparty_type VARCHAR(64) NOT NULL DEFAULT 'other',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            due_date DATE DEFAULT NULL,
            status ENUM('imported','skipped','failed') NOT NULL DEFAULT 'imported',
            row_json LONGTEXT DEFAULT NULL,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_by_name VARCHAR(191) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_finance_e_invoice_batch (import_batch, created_at),
            KEY idx_finance_e_invoice_doc (document_no),
            KEY idx_finance_e_invoice_debt (debt_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS hr_payroll_legal_profiles (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            profile_code VARCHAR(96) NOT NULL,
            title VARCHAR(191) NOT NULL,
            sector VARCHAR(96) NOT NULL DEFAULT 'private_non_oil',
            valid_from DATE NOT NULL,
            valid_to DATE DEFAULT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'AZN',
            income_tax_json LONGTEXT DEFAULT NULL,
            social_insurance_json LONGTEXT DEFAULT NULL,
            health_insurance_json LONGTEXT DEFAULT NULL,
            unemployment_insurance_json LONGTEXT DEFAULT NULL,
            leave_rules_json LONGTEXT DEFAULT NULL,
            travel_rules_json LONGTEXT DEFAULT NULL,
            source_note TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_hr_payroll_profile_code (profile_code),
            KEY idx_hr_payroll_profile_active (is_active, valid_from)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS hr_payroll_records (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_account_id BIGINT UNSIGNED DEFAULT NULL,
            employee_name VARCHAR(191) NOT NULL DEFAULT '',
            period_month DATE NOT NULL,
            profile_code VARCHAR(96) NOT NULL DEFAULT 'az_private_non_oil_2026',
            gross_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            leave_days DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            leave_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            travel_days DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            travel_allowance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            tax_exempt_travel_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            taxable_travel_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payroll_tax_base DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            employee_income_tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            employee_social_insurance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            employee_unemployment_insurance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            employee_health_insurance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            employee_total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            net_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            employer_social_insurance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            employer_unemployment_insurance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            employer_health_insurance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            employer_total_contributions DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            employer_total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status ENUM('draft','approved','paid','void') NOT NULL DEFAULT 'draft',
            calculation_json LONGTEXT DEFAULT NULL,
            note TEXT DEFAULT NULL,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_by_name VARCHAR(191) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_hr_payroll_employee (employee_account_id, period_month),
            KEY idx_hr_payroll_period (period_month, status),
            KEY idx_hr_payroll_profile (profile_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS hr_leave_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_account_id BIGINT UNSIGNED DEFAULT NULL,
            employee_name VARCHAR(191) NOT NULL DEFAULT '',
            period_month DATE NOT NULL,
            leave_type VARCHAR(48) NOT NULL DEFAULT 'annual',
            start_date DATE DEFAULT NULL,
            end_date DATE DEFAULT NULL,
            leave_days DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            average_monthly_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            average_daily_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            average_months_count INT UNSIGNED NOT NULL DEFAULT 0,
            calculated_leave_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status ENUM('draft','approved','paid','cancelled') NOT NULL DEFAULT 'draft',
            calculation_json LONGTEXT DEFAULT NULL,
            note TEXT DEFAULT NULL,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_by_name VARCHAR(191) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_hr_leave_employee (employee_account_id, period_month),
            KEY idx_hr_leave_period (period_month, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS hr_travel_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_account_id BIGINT UNSIGNED DEFAULT NULL,
            employee_name VARCHAR(191) NOT NULL DEFAULT '',
            destination VARCHAR(191) NOT NULL DEFAULT '',
            start_date DATE DEFAULT NULL,
            end_date DATE DEFAULT NULL,
            travel_days DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            daily_rate DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            travel_allowance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            tax_exempt_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            taxable_travel_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            purpose VARCHAR(255) NOT NULL DEFAULT '',
            status ENUM('draft','approved','paid','cancelled') NOT NULL DEFAULT 'draft',
            calculation_json LONGTEXT DEFAULT NULL,
            note TEXT DEFAULT NULL,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_by_name VARCHAR(191) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_hr_travel_employee (employee_account_id, start_date),
            KEY idx_hr_travel_status (status, start_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS hr_employee_documents (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_account_id BIGINT UNSIGNED DEFAULT NULL,
            employee_name VARCHAR(191) NOT NULL DEFAULT '',
            document_type VARCHAR(96) NOT NULL,
            document_no VARCHAR(128) NOT NULL DEFAULT '',
            status ENUM('missing','pending','complete','expired','rejected') NOT NULL DEFAULT 'pending',
            issued_at DATE DEFAULT NULL,
            expires_at DATE DEFAULT NULL,
            file_ref VARCHAR(255) NOT NULL DEFAULT '',
            note TEXT DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_by_name VARCHAR(191) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_hr_document_employee (employee_account_id, status),
            KEY idx_hr_document_type (document_type, status),
            KEY idx_hr_document_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS hr_employee_compensations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_account_id BIGINT UNSIGNED NOT NULL,
            employee_name VARCHAR(191) NOT NULL DEFAULT '',
            salary_mode ENUM('gross','net') NOT NULL DEFAULT 'gross',
            gross_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            net_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            employee_total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            employer_total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            effective_from DATE NOT NULL,
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            calculation_json LONGTEXT DEFAULT NULL,
            note TEXT DEFAULT NULL,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_by_name VARCHAR(191) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_hr_comp_employee (employee_account_id, status, effective_from),
            KEY idx_hr_comp_effective (effective_from, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS hr_attendance_profiles (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_account_id BIGINT UNSIGNED NOT NULL,
            branch_id VARCHAR(64) DEFAULT NULL,
            card_code VARCHAR(128) DEFAULT NULL,
            work_start_time TIME NOT NULL DEFAULT '09:00:00',
            work_end_time TIME NOT NULL DEFAULT '18:00:00',
            late_grace_minutes INT UNSIGNED NOT NULL DEFAULT 10,
            expected_work_minutes INT UNSIGNED NOT NULL DEFAULT 480,
            note TEXT DEFAULT NULL,
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            updated_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            updated_by_name VARCHAR(191) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_hr_attendance_profile_employee (employee_account_id),
            UNIQUE KEY uq_hr_attendance_profile_card (card_code),
            KEY idx_hr_attendance_profile_branch (branch_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS hr_attendance_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_account_id BIGINT UNSIGNED NOT NULL,
            employee_name VARCHAR(191) NOT NULL DEFAULT '',
            work_date DATE NOT NULL,
            check_in_at DATETIME DEFAULT NULL,
            check_out_at DATETIME DEFAULT NULL,
            attendance_status ENUM('present','late','absent','leave','travel') NOT NULL DEFAULT 'present',
            worked_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            branch_id VARCHAR(64) DEFAULT NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'manual',
            note TEXT DEFAULT NULL,
            checked_in_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            checked_out_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_hr_attendance_employee_day (employee_account_id, work_date),
            KEY idx_hr_attendance_date_status (work_date, attendance_status),
            KEY idx_hr_attendance_employee (employee_account_id, work_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS hr_score_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_account_id BIGINT UNSIGNED NOT NULL,
            employee_name VARCHAR(191) NOT NULL DEFAULT '',
            event_type ENUM('penalty','reward','reset') NOT NULL DEFAULT 'penalty',
            reason_code VARCHAR(64) NOT NULL DEFAULT 'manual',
            title VARCHAR(191) NOT NULL DEFAULT '',
            points_delta DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            event_date DATE NOT NULL,
            period_start DATE DEFAULT NULL,
            period_end DATE DEFAULT NULL,
            note TEXT DEFAULT NULL,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_by_name VARCHAR(191) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_hr_score_employee_date (employee_account_id, event_date),
            KEY idx_hr_score_type (event_type, reason_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS employee_work_snapshots (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_account_id BIGINT UNSIGNED DEFAULT NULL,
            employee_name VARCHAR(191) NOT NULL DEFAULT '',
            work_date DATE NOT NULL,
            open_ticket_count INT UNSIGNED NOT NULL DEFAULT 0,
            closed_ticket_count INT UNSIGNED NOT NULL DEFAULT 0,
            inventory_issued_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_ticket_at DATETIME DEFAULT NULL,
            performance_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            payload_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_employee_work_day (employee_account_id, employee_name, work_date),
            KEY idx_employee_work_date (work_date),
            KEY idx_employee_work_score (performance_score)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS device_config_backups (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_profile_id BIGINT UNSIGNED DEFAULT NULL,
            device_key VARCHAR(128) NOT NULL,
            device_name VARCHAR(191) NOT NULL,
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            backup_type ENUM('running_config','startup_config','inventory_snapshot','profile_snapshot','learned_profile','cli_discovery_snapshot','test') NOT NULL DEFAULT 'profile_snapshot',
            config_hash CHAR(64) NOT NULL,
            config_text_compressed LONGTEXT DEFAULT NULL,
            size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
            changed_from_previous TINYINT(1) NOT NULL DEFAULT 1,
            diff_summary TEXT DEFAULT NULL,
            status ENUM('stored','duplicate','failed','dry_run','skipped') NOT NULL DEFAULT 'stored',
            metadata_json LONGTEXT DEFAULT NULL,
            last_checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_device_backup_device (device_key, backup_type, created_at),
            KEY idx_device_backup_hash (config_hash),
            KEY idx_device_backup_status (status, last_checked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    try {
        foreach ($statements as $statement) {
            $db->exec($statement);
        }
        app_mgs_ensure_schema_columns($db);
        app_mgs_inventory_seed_categories($db);
        app_mgs_hr_seed_default_legal_profile($db);
    } catch (Throwable) {
        return false;
    }

    if (app_mgs_schema_ready($db)) {
        $ensuredFor = spl_object_id($db);
        return true;
    }

    return false;
}

function app_mgs_hr_money(mixed $value): float
{
    return round(max(0.0, (float) $value), 2);
}

function app_mgs_hr_default_legal_profile(): array
{
    return [
        'profile_code' => 'az_private_non_oil_2026',
        'title' => 'Azerbaijan private non-oil payroll profile 2026',
        'sector' => 'private_non_oil',
        'valid_from' => '2026-01-01',
        'valid_to' => '2026-12-31',
        'currency' => 'AZN',
        'income_tax' => [
            'exempt_first_azn_when_income_lte' => 2500,
            'exempt_first_azn' => 200,
            'bands' => [
                ['up_to' => 2500, 'rate' => 0.03],
                ['from' => 2500, 'up_to' => 8000, 'base' => 75, 'rate' => 0.10],
                ['from' => 8000, 'base' => 625, 'rate' => 0.14],
            ],
        ],
        'social_insurance' => [
            'employee_under_200' => 0.03,
            'employer_under_200' => 0.22,
            'employee_base_200_8000' => 6,
            'employee_rate_200_8000' => 0.10,
            'employer_base_200_8000' => 44,
            'employer_rate_200_8000' => 0.15,
            'employee_base_over_8000' => 786,
            'employee_rate_over_8000' => 0.10,
            'employer_base_over_8000' => 1214,
            'employer_rate_over_8000' => 0.11,
        ],
        'health_insurance' => [
            'threshold' => 2500,
            'employee_rate_under_threshold' => 0.02,
            'employer_rate_under_threshold' => 0.02,
            'employee_rate_over_threshold' => 0.005,
            'employer_rate_over_threshold' => 0.005,
        ],
        'unemployment_insurance' => [
            'employee_rate' => 0.005,
            'employer_rate' => 0.005,
        ],
        'leave_rules' => [
            'average_months' => 12,
            'default_work_days_per_month' => 30.4,
        ],
        'travel_rules' => [
            'tax_exempt_amount_is_input' => true,
            'default_daily_rate' => 0,
        ],
        'source_note' => 'Rates are stored as a configurable 2026 default profile and should be reviewed when legislation changes.',
    ];
}

function app_mgs_hr_legal_profile_for_date(PDO $db, string $date): array
{
    $dateValue=app_mgs_hr_date_value($date);
    if($dateValue===null)return[];
    try{
        $statement=$db->prepare('SELECT * FROM hr_payroll_legal_profiles WHERE is_active=1 AND valid_from<=:profile_date AND (valid_to IS NULL OR valid_to>=:profile_date) ORDER BY valid_from DESC,id DESC LIMIT 1');
        $statement->execute(['profile_date'=>$dateValue]);$row=$statement->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return[];
        $profile=$row;
        foreach(['income_tax','social_insurance','health_insurance','unemployment_insurance','leave_rules','travel_rules'] as $key){$decoded=json_decode((string)($row[$key.'_json']??'{}'),true);$profile[$key]=is_array($decoded)?$decoded:[];}
        return $profile;
    }catch(Throwable){return[];}
}

function app_mgs_hr_employee_record(PDO $db, int $employeeId): array
{
    if($employeeId<=0)return[];
    $statement=$db->prepare('SELECT id,full_name,login,is_active FROM portal_accounts WHERE id=:id AND role="employee" LIMIT 1');
    $statement->execute(['id'=>$employeeId]);$row=$statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:[];
}

function app_mgs_hr_write_audit(PDO $db, string $actionType, int $employeeId, array $actor, string $summary, array $payload = [], string $reason = '', ?array $before = null, ?array $after = null, string $operationClass = 'write'): void
{
    if(!$db->inTransaction())app_audit_ensure_schema($db);
    app_audit_record($db,[
        'module_key'=>'hr',
        'operation_class'=>$operationClass,
        'action_type'=>$actionType,
        'entity_type'=>'employee',
        'entity_id'=>(string)$employeeId,
        'status'=>'executed',
        'actor_account_id'=>(int)($actor['id']??0)?:null,
        'actor_name'=>clean_text((string)($actor['full_name']??$actor['name']??$actor['login']??'system')),
        'summary'=>mb_substr(clean_text($summary),0,255),
        'reason'=>$reason,
        'before'=>$before,
        'after'=>$after,
        'payload'=>$payload,
        'sensitivity'=>$reason!==''||$before!==null||$after!==null?'high':'normal',
    ]);
}

function app_mgs_hr_required_reason(mixed $value): string
{
    $reason=mb_substr(clean_text((string)$value),0,255);
    if($reason==='')throw new InvalidArgumentException('reason_required');
    return $reason;
}

function app_mgs_hr_seed_default_legal_profile(PDO $db): void
{
    $profile = app_mgs_hr_default_legal_profile();
    $statement = $db->prepare(
        'INSERT INTO hr_payroll_legal_profiles (
            profile_code, title, sector, valid_from, valid_to, currency,
            income_tax_json, social_insurance_json, health_insurance_json,
            unemployment_insurance_json, leave_rules_json, travel_rules_json,
            source_note, is_active
         ) VALUES (
            :profile_code, :title, :sector, :valid_from, :valid_to, :currency,
            :income_tax_json, :social_insurance_json, :health_insurance_json,
            :unemployment_insurance_json, :leave_rules_json, :travel_rules_json,
            :source_note, 1
         )
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            sector = VALUES(sector),
            valid_from = VALUES(valid_from),
            valid_to = VALUES(valid_to),
            currency = VALUES(currency),
            income_tax_json = VALUES(income_tax_json),
            social_insurance_json = VALUES(social_insurance_json),
            health_insurance_json = VALUES(health_insurance_json),
            unemployment_insurance_json = VALUES(unemployment_insurance_json),
            leave_rules_json = VALUES(leave_rules_json),
            travel_rules_json = VALUES(travel_rules_json),
            source_note = VALUES(source_note),
            is_active = 1'
    );
    $statement->execute([
        'profile_code' => (string) $profile['profile_code'],
        'title' => (string) $profile['title'],
        'sector' => (string) $profile['sector'],
        'valid_from' => (string) $profile['valid_from'],
        'valid_to' => (string) $profile['valid_to'],
        'currency' => (string) $profile['currency'],
        'income_tax_json' => app_db_json_encode($profile['income_tax']),
        'social_insurance_json' => app_db_json_encode($profile['social_insurance']),
        'health_insurance_json' => app_db_json_encode($profile['health_insurance']),
        'unemployment_insurance_json' => app_db_json_encode($profile['unemployment_insurance']),
        'leave_rules_json' => app_db_json_encode($profile['leave_rules']),
        'travel_rules_json' => app_db_json_encode($profile['travel_rules']),
        'source_note' => (string) $profile['source_note'],
    ]);
}

function app_mgs_hr_income_tax(float $amount, array $profile): float
{
    $taxProfile = is_array($profile['income_tax'] ?? null) ? $profile['income_tax'] : [];
    $exemptLimit = (float) ($taxProfile['exempt_first_azn_when_income_lte'] ?? 2500);
    $exemptAmount = (float) ($taxProfile['exempt_first_azn'] ?? 200);
    $taxable = $amount <= $exemptLimit ? max(0.0, $amount - $exemptAmount) : $amount;

    if ($taxable <= 2500.0) {
        return app_mgs_hr_money($taxable * 0.03);
    }
    if ($taxable <= 8000.0) {
        return app_mgs_hr_money(75.0 + (($taxable - 2500.0) * 0.10));
    }

    return app_mgs_hr_money(625.0 + (($taxable - 8000.0) * 0.14));
}

function app_mgs_hr_social_insurance(float $amount, array $profile = []): array
{
    $social=is_array($profile['social_insurance']??null)?$profile['social_insurance']:[];
    if ($amount <= 200.0) {
        return [
            'employee' => app_mgs_hr_money($amount * (float)($social['employee_under_200']??0.03)),
            'employer' => app_mgs_hr_money($amount * (float)($social['employer_under_200']??0.22)),
        ];
    }
    if ($amount <= 8000.0) {
        return [
            'employee' => app_mgs_hr_money((float)($social['employee_base_200_8000']??6) + (($amount - 200.0) * (float)($social['employee_rate_200_8000']??0.10))),
            'employer' => app_mgs_hr_money((float)($social['employer_base_200_8000']??44) + (($amount - 200.0) * (float)($social['employer_rate_200_8000']??0.15))),
        ];
    }

    return [
        'employee' => app_mgs_hr_money((float)($social['employee_base_over_8000']??786) + (($amount - 8000.0) * (float)($social['employee_rate_over_8000']??0.10))),
        'employer' => app_mgs_hr_money((float)($social['employer_base_over_8000']??1214) + (($amount - 8000.0) * (float)($social['employer_rate_over_8000']??0.11))),
    ];
}

function app_mgs_hr_threshold_contribution(float $amount, float $threshold, float $underRate, float $overRate): float
{
    $under = min($amount, $threshold) * $underRate;
    $over = max(0.0, $amount - $threshold) * $overRate;

    return app_mgs_hr_money($under + $over);
}

function app_mgs_hr_payroll_preview(array $input, ?array $profile = null): array
{
    $profile = is_array($profile) ? $profile : app_mgs_hr_default_legal_profile();
    $grossSalary = app_mgs_hr_money($input['gross_salary'] ?? $input['base_salary'] ?? 0);
    $leaveDays = app_mgs_hr_money($input['leave_days'] ?? 0);
    $leaveDailyRate = app_mgs_hr_money($input['leave_daily_rate'] ?? 0);
    $leavePay = app_mgs_hr_money($input['leave_pay'] ?? ($leaveDays * $leaveDailyRate));
    $travelDays = app_mgs_hr_money($input['travel_days'] ?? 0);
    $travelDailyRate = app_mgs_hr_money($input['travel_daily_rate'] ?? 0);
    $travelAllowance = app_mgs_hr_money($input['travel_allowance'] ?? ($travelDays * $travelDailyRate));
    $taxableTravel = min($travelAllowance, app_mgs_hr_money($input['taxable_travel_amount'] ?? 0));
    $taxExemptTravel = max(0.0, $travelAllowance - $taxableTravel);
    $payrollTaxBase = app_mgs_hr_money($grossSalary + $leavePay + $taxableTravel);

    $social = app_mgs_hr_social_insurance($payrollTaxBase,$profile);
    $healthProfile = is_array($profile['health_insurance'] ?? null) ? $profile['health_insurance'] : [];
    $healthThreshold = (float) ($healthProfile['threshold'] ?? 2500);
    $employeeHealth = app_mgs_hr_threshold_contribution(
        $payrollTaxBase,
        $healthThreshold,
        (float) ($healthProfile['employee_rate_under_threshold'] ?? 0.02),
        (float) ($healthProfile['employee_rate_over_threshold'] ?? 0.005)
    );
    $employerHealth = app_mgs_hr_threshold_contribution(
        $payrollTaxBase,
        $healthThreshold,
        (float) ($healthProfile['employer_rate_under_threshold'] ?? 0.02),
        (float) ($healthProfile['employer_rate_over_threshold'] ?? 0.005)
    );
    $unemploymentProfile = is_array($profile['unemployment_insurance'] ?? null) ? $profile['unemployment_insurance'] : [];
    $employeeUnemployment = app_mgs_hr_money($payrollTaxBase * (float) ($unemploymentProfile['employee_rate'] ?? 0.005));
    $employerUnemployment = app_mgs_hr_money($payrollTaxBase * (float) ($unemploymentProfile['employer_rate'] ?? 0.005));
    $employeeIncomeTax = app_mgs_hr_income_tax($payrollTaxBase, $profile);
    $employeeDeductions = app_mgs_hr_money($employeeIncomeTax + (float) $social['employee'] + $employeeUnemployment + $employeeHealth);
    $employerContributions = app_mgs_hr_money((float) $social['employer'] + $employerUnemployment + $employerHealth);
    $grossPayable = app_mgs_hr_money($payrollTaxBase + $taxExemptTravel);

    return [
        'profile_code' => (string) ($profile['profile_code'] ?? 'az_private_non_oil_2026'),
        'gross_salary' => $grossSalary,
        'leave_days' => $leaveDays,
        'leave_pay' => $leavePay,
        'travel_days' => $travelDays,
        'travel_allowance' => $travelAllowance,
        'tax_exempt_travel_amount' => app_mgs_hr_money($taxExemptTravel),
        'taxable_travel_amount' => app_mgs_hr_money($taxableTravel),
        'payroll_tax_base' => $payrollTaxBase,
        'gross_payable' => $grossPayable,
        'employee_income_tax' => $employeeIncomeTax,
        'employee_social_insurance' => (float) $social['employee'],
        'employee_unemployment_insurance' => $employeeUnemployment,
        'employee_health_insurance' => $employeeHealth,
        'employee_total_deductions' => $employeeDeductions,
        'net_salary' => app_mgs_hr_money($grossPayable - $employeeDeductions),
        'employer_social_insurance' => (float) $social['employer'],
        'employer_unemployment_insurance' => $employerUnemployment,
        'employer_health_insurance' => $employerHealth,
        'employer_total_contributions' => $employerContributions,
        'employer_total_cost' => app_mgs_hr_money($grossPayable + $employerContributions),
        'legal_profile' => $profile,
    ];
}

function app_mgs_hr_period_month(mixed $value): string
{
    $text = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}$/', $text) === 1) {
        return $text . '-01';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
        return substr($text, 0, 7) . '-01';
    }

    return date('Y-m-01');
}

function app_mgs_save_hr_payroll_record(array $input, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'error' => 'db_not_ready'];
    }
    app_audit_ensure_schema($db);

    $employeeId = (int) ($input['employee_account_id'] ?? $input['employee_id'] ?? 0);
    $employee=app_mgs_hr_employee_record($db,$employeeId);if($employee===[])return['ok'=>false,'error'=>'employee_not_found'];
    $employeeName=clean_text((string)($employee['full_name']??$employee['login']??''));
    $periodInput=trim((string)($input['period_month']??''));
    if(!preg_match('/^\d{4}-\d{2}(?:-\d{2})?$/',$periodInput))return['ok'=>false,'error'=>'invalid_period'];
    $period=app_mgs_hr_period_month($periodInput);if(app_mgs_hr_date_value($period)===null)return['ok'=>false,'error'=>'invalid_period'];
    $profile=app_mgs_hr_legal_profile_for_date($db,$period);if($profile===[])return['ok'=>false,'error'=>'legal_profile_not_found'];
    $preview = app_mgs_hr_payroll_preview($input,$profile);
    $statement = $db->prepare(
        'INSERT INTO hr_payroll_records (
            employee_account_id, employee_name, period_month, profile_code, gross_salary,
            leave_days, leave_pay, travel_days, travel_allowance, tax_exempt_travel_amount,
            taxable_travel_amount, payroll_tax_base, employee_income_tax, employee_social_insurance,
            employee_unemployment_insurance, employee_health_insurance, employee_total_deductions,
            net_salary, employer_social_insurance, employer_unemployment_insurance, employer_health_insurance,
            employer_total_contributions, employer_total_cost, status, calculation_json, note,
            created_by_account_id, created_by_name
         ) VALUES (
            :employee_account_id, :employee_name, :period_month, :profile_code, :gross_salary,
            :leave_days, :leave_pay, :travel_days, :travel_allowance, :tax_exempt_travel_amount,
            :taxable_travel_amount, :payroll_tax_base, :employee_income_tax, :employee_social_insurance,
            :employee_unemployment_insurance, :employee_health_insurance, :employee_total_deductions,
            :net_salary, :employer_social_insurance, :employer_unemployment_insurance, :employer_health_insurance,
            :employer_total_contributions, :employer_total_cost, :status, :calculation_json, :note,
            :created_by_account_id, :created_by_name
         )'
    );
    $status = (string) ($input['status'] ?? 'draft');
    if (!in_array($status, ['draft', 'approved', 'paid', 'void'], true)) {
        $status = 'draft';
    }
    $params = [
        'employee_account_id' => $employeeId ?: null,
        'employee_name' => $employeeName,
        'period_month' => $period,
        'profile_code' => (string) $preview['profile_code'],
        'gross_salary' => $preview['gross_salary'],
        'leave_days' => $preview['leave_days'],
        'leave_pay' => $preview['leave_pay'],
        'travel_days' => $preview['travel_days'],
        'travel_allowance' => $preview['travel_allowance'],
        'tax_exempt_travel_amount' => $preview['tax_exempt_travel_amount'],
        'taxable_travel_amount' => $preview['taxable_travel_amount'],
        'payroll_tax_base' => $preview['payroll_tax_base'],
        'employee_income_tax' => $preview['employee_income_tax'],
        'employee_social_insurance' => $preview['employee_social_insurance'],
        'employee_unemployment_insurance' => $preview['employee_unemployment_insurance'],
        'employee_health_insurance' => $preview['employee_health_insurance'],
        'employee_total_deductions' => $preview['employee_total_deductions'],
        'net_salary' => $preview['net_salary'],
        'employer_social_insurance' => $preview['employer_social_insurance'],
        'employer_unemployment_insurance' => $preview['employer_unemployment_insurance'],
        'employer_health_insurance' => $preview['employer_health_insurance'],
        'employer_total_contributions' => $preview['employer_total_contributions'],
        'employer_total_cost' => $preview['employer_total_cost'],
        'status' => $status,
        'calculation_json' => app_db_json_encode($preview),
        'note' => clean_text((string) ($input['note'] ?? $input['note_text'] ?? '')),
        'created_by_account_id' => (int) ($actor['id'] ?? 0) ?: null,
        'created_by_name' => clean_text((string) ($actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'system')),
    ];
    try{
        $db->beginTransaction();
        $duplicate=$db->prepare('SELECT id FROM hr_payroll_records WHERE employee_account_id=:employee_id AND period_month=:period_month AND status<>"void" LIMIT 1 FOR UPDATE');$duplicate->execute(['employee_id'=>$employeeId,'period_month'=>$period]);
        if($duplicate->fetchColumn()){$db->rollBack();return['ok'=>false,'error'=>'payroll_period_exists'];}
        $statement->execute($params);$recordId=(int)$db->lastInsertId();
        app_mgs_hr_write_audit($db,'hr_payroll_record_create',$employeeId,$actor,'HR payroll legal analysis recorded',['record_id'=>$recordId,'profile_code'=>$preview['profile_code'],'period_month'=>$period]);
        $db->commit();return['ok'=>true,'id'=>$recordId,'record'=>$params,'preview'=>$preview];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'payroll_save_failed'];}
}

function app_mgs_hr_payroll_rows(?PDO $db = null, int $limit = 8): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }

    try {
        $statement = $db->prepare(
            'SELECT *
               FROM hr_payroll_records
              ORDER BY period_month DESC, id DESC
              LIMIT ' . max(1, min(50, $limit))
        );
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

function app_mgs_hr_date_value(mixed $value): ?string
{
    $text = trim((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
        return null;
    }

    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$text);
    return $date && $date->format('Y-m-d')===$text ? $text : null;
}

function app_mgs_hr_employee_name(?PDO $db, int $employeeId, string $fallback = ''): string
{
    $fallback = clean_text($fallback);
    if ($employeeId <= 0 || !$db instanceof PDO) {
        return $fallback !== '' ? $fallback : 'HR employee';
    }

    try {
        $statement = $db->prepare('SELECT full_name, login FROM portal_accounts WHERE id = :id AND role = "employee" LIMIT 1');
        $statement->execute(['id' => $employeeId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $name = clean_text((string) ($row['full_name'] ?? $row['login'] ?? ''));
        if ($name !== '') {
            return $name;
        }
    } catch (Throwable) {
    }

    return $fallback !== '' ? $fallback : ('Employee #' . $employeeId);
}

function app_mgs_hr_status(string $value, array $allowed, string $fallback): string
{
    $value = clean_text($value);
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function app_mgs_save_hr_attendance_profile(array $input, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'error' => 'db_unavailable'];
    }
    $employeeRole = strtolower((string) ($actor['employee_role'] ?? ''));
    if (!app_portal_is_admin($actor) && $employeeRole !== 'hr') {
        return ['ok' => false, 'error' => 'forbidden'];
    }

    $employeeId = max(0, (int) ($input['employee_account_id'] ?? $input['userId'] ?? 0));
    $employee = app_mgs_hr_employee_record($db, $employeeId);
    if ($employeeId <= 0 || $employee === []) {
        return ['ok' => false, 'error' => 'employee_not_found'];
    }
    $normalizeTime = static function (mixed $value, string $fallback): string {
        $text = trim((string) $value);
        if (preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $text) !== 1) {
            return $fallback;
        }
        return substr($text, 0, 5) . ':00';
    };
    $params = [
        'employee_account_id' => $employeeId,
        'branch_id' => clean_text((string) ($input['branch_id'] ?? $input['branchId'] ?? '')) ?: null,
        'card_code' => clean_text((string) ($input['card_code'] ?? $input['cardCode'] ?? '')) ?: null,
        'work_start_time' => $normalizeTime($input['work_start_time'] ?? $input['workStartTime'] ?? '', '09:00:00'),
        'work_end_time' => $normalizeTime($input['work_end_time'] ?? $input['workEndTime'] ?? '', '18:00:00'),
        'late_grace_minutes' => max(0, min(240, (int) ($input['late_grace_minutes'] ?? $input['lateGraceMinutes'] ?? 10))),
        'expected_work_minutes' => max(1, min(1440, (int) ($input['expected_work_minutes'] ?? $input['expectedWorkMinutes'] ?? 480))),
        'note' => clean_text((string) ($input['note_text'] ?? $input['noteText'] ?? '')) ?: null,
        'updated_by_account_id' => (int) ($actor['id'] ?? 0) ?: null,
        'updated_by_name' => clean_text((string) ($actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'system')),
    ];

    try {
        $beforeStatement = $db->prepare('SELECT * FROM hr_attendance_profiles WHERE employee_account_id = :employee_id LIMIT 1');
        $beforeStatement->execute(['employee_id' => $employeeId]);
        $before = $beforeStatement->fetch(PDO::FETCH_ASSOC) ?: null;
        $statement = $db->prepare(
            'INSERT INTO hr_attendance_profiles (
                employee_account_id, branch_id, card_code, work_start_time, work_end_time,
                late_grace_minutes, expected_work_minutes, note, updated_by_account_id, updated_by_name
             ) VALUES (
                :employee_account_id, :branch_id, :card_code, :work_start_time, :work_end_time,
                :late_grace_minutes, :expected_work_minutes, :note, :updated_by_account_id, :updated_by_name
             )
             ON DUPLICATE KEY UPDATE
                branch_id = VALUES(branch_id),
                card_code = VALUES(card_code),
                work_start_time = VALUES(work_start_time),
                work_end_time = VALUES(work_end_time),
                late_grace_minutes = VALUES(late_grace_minutes),
                expected_work_minutes = VALUES(expected_work_minutes),
                note = VALUES(note),
                status = "active",
                updated_by_account_id = VALUES(updated_by_account_id),
                updated_by_name = VALUES(updated_by_name)'
        );
        $statement->execute($params);
        $beforeStatement->execute(['employee_id' => $employeeId]);
        $after = $beforeStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        app_mgs_hr_write_audit(
            $db,
            'hr_attendance_profile_save',
            $employeeId,
            $actor,
            'Davamiyyet profili saxlandi',
            ['branch_id' => $params['branch_id']],
            '',
            $before,
            $after
        );
        return ['ok' => true, 'profile' => $after];
    } catch (Throwable $exception) {
        return ['ok' => false, 'error' => 'attendance_profile_save_failed', 'detail' => $exception->getMessage()];
    }
}

function app_mgs_record_hr_attendance_event(array $input, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'error' => 'db_unavailable'];
    }

    $employeeId = max(0, (int) ($input['employee_account_id'] ?? 0));
    $eventType = clean_text((string) ($input['event_type'] ?? 'check_in'));
    $eventType = in_array($eventType, ['check_in', 'check_out'], true) ? $eventType : '';
    $workDate = app_mgs_hr_date_value($input['work_date'] ?? date('Y-m-d'));
    $eventAtText = trim((string) ($input['event_at'] ?? date('Y-m-d H:i:s')));
    $eventAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $eventAtText);
    $employee = app_mgs_hr_employee_record($db, $employeeId);
    if ($employeeId <= 0 || $eventType === '' || $workDate === null || !$eventAt || $eventAt->format('Y-m-d') !== $workDate || $employee === []) {
        return ['ok' => false, 'error' => 'invalid_attendance'];
    }

    $branchId = clean_text((string) ($input['branch_id'] ?? '')) ?: null;
    $source = clean_text((string) ($input['source'] ?? 'manual')) ?: 'manual';
    $note = clean_text((string) ($input['note'] ?? '')) ?: null;
    $employeeName = (string) ($employee['full_name'] ?? $employee['login'] ?? ('Employee #' . $employeeId));

    $ownsTransaction = !$db->inTransaction();
    try {
        if ($ownsTransaction) {
            $db->beginTransaction();
        }
        $select = $db->prepare('SELECT * FROM hr_attendance_events WHERE employee_account_id = :employee_id AND work_date = :work_date FOR UPDATE');
        $select->execute(['employee_id' => $employeeId, 'work_date' => $workDate]);
        $before = $select->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($eventType === 'check_in') {
            if (is_array($before) && !empty($before['check_in_at'])) {
                if ($ownsTransaction) $db->rollBack();
                return ['ok' => false, 'error' => 'already_checked_in'];
            }
            $profile = $db->prepare(
                'SELECT work_start_time, late_grace_minutes
                   FROM hr_attendance_profiles
                  WHERE employee_account_id = :employee_id
                    AND status = "active"
                  LIMIT 1'
            );
            $profile->execute(['employee_id' => (string) $employeeId]);
            $profilePayload = $profile->fetch(PDO::FETCH_ASSOC) ?: [];
            $startTimeRaw = substr((string) ($profilePayload['work_start_time'] ?? '09:00'), 0, 5);
            $startTime = preg_match('/^\d{2}:\d{2}$/', $startTimeRaw) === 1 ? $startTimeRaw : '09:00';
            $graceMinutes = max(0, (int) ($profilePayload['late_grace_minutes'] ?? 10));
            $lateAfter = new DateTimeImmutable($workDate . ' ' . $startTime . ':00');
            $status = $eventAt > $lateAfter->modify('+' . $graceMinutes . ' minutes') ? 'late' : 'present';
            $insert = $db->prepare('INSERT INTO hr_attendance_events (employee_account_id,employee_name,work_date,check_in_at,attendance_status,branch_id,source,note,checked_in_by_account_id) VALUES (:employee_id,:employee_name,:work_date,:event_at,:status,:branch_id,:source,:note,:actor_id) ON DUPLICATE KEY UPDATE check_in_at=VALUES(check_in_at),attendance_status=VALUES(attendance_status),branch_id=VALUES(branch_id),source=VALUES(source),note=VALUES(note),checked_in_by_account_id=VALUES(checked_in_by_account_id)');
            $insert->execute(['employee_id' => $employeeId, 'employee_name' => $employeeName, 'work_date' => $workDate, 'event_at' => $eventAt->format('Y-m-d H:i:s'), 'status' => $status, 'branch_id' => $branchId, 'source' => $source, 'note' => $note, 'actor_id' => (int) ($actor['id'] ?? 0) ?: null]);
        } else {
            if (!is_array($before) || empty($before['check_in_at'])) {
                if ($ownsTransaction) $db->rollBack();
                return ['ok' => false, 'error' => 'check_in_required'];
            }
            if (!empty($before['check_out_at'])) {
                if ($ownsTransaction) $db->rollBack();
                return ['ok' => false, 'error' => 'already_checked_out'];
            }
            $checkIn = new DateTimeImmutable((string) $before['check_in_at']);
            if ($eventAt < $checkIn) {
                if ($ownsTransaction) $db->rollBack();
                return ['ok' => false, 'error' => 'invalid_checkout_time'];
            }
            $workedMinutes = max(0, (int) floor(($eventAt->getTimestamp() - $checkIn->getTimestamp()) / 60));
            $update = $db->prepare('UPDATE hr_attendance_events SET check_out_at=:event_at,worked_minutes=:worked_minutes,note=COALESCE(:note,note),checked_out_by_account_id=:actor_id WHERE id=:id');
            $update->execute(['event_at' => $eventAt->format('Y-m-d H:i:s'), 'worked_minutes' => $workedMinutes, 'note' => $note, 'actor_id' => (int) ($actor['id'] ?? 0) ?: null, 'id' => (int) $before['id']]);
        }

        $select->execute(['employee_id' => $employeeId, 'work_date' => $workDate]);
        $after = $select->fetch(PDO::FETCH_ASSOC) ?: [];
        app_mgs_hr_write_audit($db, 'hr_attendance_' . $eventType, $employeeId, $actor, $employeeName . ': ' . $eventType, ['work_date' => $workDate, 'source' => $source], '', $before, $after);
        if ($ownsTransaction) $db->commit();
        return ['ok' => true, 'record' => $after];
    } catch (Throwable $exception) {
        if (!$ownsTransaction) throw $exception;
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'attendance_save_failed', 'detail' => $exception->getMessage()];
    }
}

function app_mgs_hr_required_document_types(): array
{
    return [
        'employment_contract' => 'Employment contract',
        'id_card' => 'ID card',
        'tax_social_registration' => 'Tax/social registration',
        'bank_account' => 'Bank account',
        'medical_clearance' => 'Medical clearance',
    ];
}

function app_mgs_hr_leave_average(int $employeeId, mixed $periodMonth = null, ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    $period = app_mgs_hr_period_month($periodMonth ?? date('Y-m'));
    $profile = app_mgs_hr_default_legal_profile();
    $leaveRules = is_array($profile['leave_rules'] ?? null) ? $profile['leave_rules'] : [];
    $workDays = max(1.0, (float) ($leaveRules['default_work_days_per_month'] ?? 30.4));
    $months = max(1, (int) ($leaveRules['average_months'] ?? 12));

    $result = [
        'employee_account_id' => $employeeId,
        'period_month' => $period,
        'months_count' => 0,
        'salary_total' => 0.0,
        'average_monthly_salary' => 0.0,
        'average_daily_salary' => 0.0,
        'work_days_per_month' => $workDays,
        'average_months_window' => $months,
    ];

    if (!$db instanceof PDO || $employeeId <= 0 || !app_mgs_ensure_schema($db)) {
        return $result;
    }

    try {
        $statement = $db->prepare(
            'SELECT COUNT(DISTINCT period_month) AS months_count,
                    COALESCE(SUM(gross_salary + leave_pay + taxable_travel_amount),0) AS salary_total
               FROM hr_payroll_records
              WHERE employee_account_id = :employee_account_id
                AND period_month < :period_month
                AND period_month >= DATE_SUB(:period_month, INTERVAL ' . $months . ' MONTH)
                AND status <> "void"'
        );
        $statement->execute([
            'employee_account_id' => $employeeId,
            'period_month' => $period,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $monthsCount = max(0, (int) ($row['months_count'] ?? 0));
        $salaryTotal = app_mgs_hr_money($row['salary_total'] ?? 0);
        $averageMonthly = $monthsCount > 0 ? app_mgs_hr_money($salaryTotal / $monthsCount) : 0.0;
        $averageDaily = $averageMonthly > 0 ? app_mgs_hr_money($averageMonthly / $workDays) : 0.0;

        $result['months_count'] = $monthsCount;
        $result['salary_total'] = $salaryTotal;
        $result['average_monthly_salary'] = $averageMonthly;
        $result['average_daily_salary'] = $averageDaily;
    } catch (Throwable) {
    }

    return $result;
}

function app_mgs_save_hr_leave_request(array $input, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'error' => 'db_not_ready'];
    }
    app_audit_ensure_schema($db);

    $employeeId = (int) ($input['employee_account_id'] ?? $input['employee_id'] ?? 0);$employee=app_mgs_hr_employee_record($db,$employeeId);if($employee===[])return['ok'=>false,'error'=>'employee_not_found'];
    $employeeName=clean_text((string)($employee['full_name']??$employee['login']??''));
    $startDate=app_mgs_hr_date_value($input['start_date']??null);$endDate=app_mgs_hr_date_value($input['end_date']??null);
    if($startDate===null||$endDate===null||$startDate>$endDate)return['ok'=>false,'error'=>'invalid_date_range'];
    $period = app_mgs_hr_period_month($input['period_month'] ?? $input['start_date'] ?? date('Y-m'));
    $leaveDays = app_mgs_hr_money($input['leave_days'] ?? 0);
    $calendarDays=(new DateTimeImmutable($startDate))->diff(new DateTimeImmutable($endDate))->days+1;
    if($leaveDays<=0||abs($leaveDays-$calendarDays)>0.001)return['ok'=>false,'error'=>'leave_days_mismatch'];
    $average = app_mgs_hr_leave_average($employeeId, $period, $db);
    $leavePay = app_mgs_hr_money($input['calculated_leave_pay'] ?? ($leaveDays * (float) ($average['average_daily_salary'] ?? 0)));
    $status = app_mgs_hr_status((string) ($input['status'] ?? 'draft'), ['draft', 'approved', 'paid', 'cancelled'], 'draft');
    $record = [
        'employee_account_id' => $employeeId ?: null,
        'employee_name' => $employeeName,
        'period_month' => $period,
        'leave_type' => clean_text((string) ($input['leave_type'] ?? 'annual')) ?: 'annual',
        'start_date' => $startDate,
        'end_date' => $endDate,
        'leave_days' => $leaveDays,
        'average_monthly_salary' => (float) ($average['average_monthly_salary'] ?? 0),
        'average_daily_salary' => (float) ($average['average_daily_salary'] ?? 0),
        'average_months_count' => (int) ($average['months_count'] ?? 0),
        'calculated_leave_pay' => $leavePay,
        'status' => $status,
        'calculation_json' => app_db_json_encode(['average' => $average, 'input' => $input]),
        'note' => clean_text((string) ($input['note'] ?? $input['note_text'] ?? '')),
        'created_by_account_id' => (int) ($actor['id'] ?? 0) ?: null,
        'created_by_name' => clean_text((string) ($actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'system')),
    ];

    $statement = $db->prepare(
        'INSERT INTO hr_leave_requests (
            employee_account_id, employee_name, period_month, leave_type, start_date, end_date, leave_days,
            average_monthly_salary, average_daily_salary, average_months_count, calculated_leave_pay,
            status, calculation_json, note, created_by_account_id, created_by_name
         ) VALUES (
            :employee_account_id, :employee_name, :period_month, :leave_type, :start_date, :end_date, :leave_days,
            :average_monthly_salary, :average_daily_salary, :average_months_count, :calculated_leave_pay,
            :status, :calculation_json, :note, :created_by_account_id, :created_by_name
         )'
    );
    try{$db->beginTransaction();$overlap=$db->prepare('SELECT id FROM hr_leave_requests WHERE employee_account_id=:employee_id AND status<>"cancelled" AND start_date<=:end_date AND end_date>=:start_date LIMIT 1 FOR UPDATE');$overlap->execute(['employee_id'=>$employeeId,'start_date'=>$startDate,'end_date'=>$endDate]);if($overlap->fetchColumn()){$db->rollBack();return['ok'=>false,'error'=>'leave_overlap'];}$statement->execute($record);$id=(int)$db->lastInsertId();app_mgs_hr_write_audit($db,'hr_leave_request_create',$employeeId,$actor,'HR leave request recorded',['id'=>$id,'start_date'=>$startDate,'end_date'=>$endDate]);$db->commit();return['ok'=>true,'id'=>$id,'record'=>$record];}catch(Throwable $e){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'leave_save_failed'];}
}

function app_mgs_save_hr_travel_request(array $input, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'error' => 'db_not_ready'];
    }
    app_audit_ensure_schema($db);

    $employeeId = (int) ($input['employee_account_id'] ?? $input['employee_id'] ?? 0);$employee=app_mgs_hr_employee_record($db,$employeeId);if($employee===[])return['ok'=>false,'error'=>'employee_not_found'];
    $employeeName=clean_text((string)($employee['full_name']??$employee['login']??''));$startDate=app_mgs_hr_date_value($input['start_date']??null);$endDate=app_mgs_hr_date_value($input['end_date']??null);
    if($startDate===null||$endDate===null||$startDate>$endDate)return['ok'=>false,'error'=>'invalid_date_range'];
    $travelDays = app_mgs_hr_money($input['travel_days'] ?? 0);
    $dailyRate = app_mgs_hr_money($input['daily_rate'] ?? $input['travel_daily_rate'] ?? 0);
    $calendarDays=(new DateTimeImmutable($startDate))->diff(new DateTimeImmutable($endDate))->days+1;
    if($travelDays<=0||abs($travelDays-$calendarDays)>0.001)return['ok'=>false,'error'=>'travel_days_mismatch'];if($dailyRate<=0)return['ok'=>false,'error'=>'daily_rate_required'];
    $allowance = app_mgs_hr_money($input['travel_allowance'] ?? ($travelDays * $dailyRate));
    $taxable = min($allowance, app_mgs_hr_money($input['taxable_travel_amount'] ?? 0));
    $taxExempt = app_mgs_hr_money($allowance - $taxable);
    $status = app_mgs_hr_status((string) ($input['status'] ?? 'draft'), ['draft', 'approved', 'paid', 'cancelled'], 'draft');
    $record = [
        'employee_account_id' => $employeeId ?: null,
        'employee_name' => $employeeName,
        'destination' => clean_text((string) ($input['destination'] ?? '')),
        'start_date' => $startDate,
        'end_date' => $endDate,
        'travel_days' => $travelDays,
        'daily_rate' => $dailyRate,
        'travel_allowance' => $allowance,
        'tax_exempt_amount' => $taxExempt,
        'taxable_travel_amount' => $taxable,
        'purpose' => mb_substr(clean_text((string) ($input['purpose'] ?? '')), 0, 255),
        'status' => $status,
        'calculation_json' => app_db_json_encode(['input' => $input, 'tax_exempt_amount' => $taxExempt, 'taxable_travel_amount' => $taxable]),
        'note' => clean_text((string) ($input['note'] ?? $input['note_text'] ?? '')),
        'created_by_account_id' => (int) ($actor['id'] ?? 0) ?: null,
        'created_by_name' => clean_text((string) ($actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'system')),
    ];

    $statement = $db->prepare(
        'INSERT INTO hr_travel_requests (
            employee_account_id, employee_name, destination, start_date, end_date, travel_days, daily_rate,
            travel_allowance, tax_exempt_amount, taxable_travel_amount, purpose, status, calculation_json,
            note, created_by_account_id, created_by_name
         ) VALUES (
            :employee_account_id, :employee_name, :destination, :start_date, :end_date, :travel_days, :daily_rate,
            :travel_allowance, :tax_exempt_amount, :taxable_travel_amount, :purpose, :status, :calculation_json,
            :note, :created_by_account_id, :created_by_name
         )'
    );
    try{$db->beginTransaction();$overlap=$db->prepare('SELECT id FROM hr_travel_requests WHERE employee_account_id=:employee_id AND status<>"cancelled" AND start_date<=:end_date AND end_date>=:start_date LIMIT 1 FOR UPDATE');$overlap->execute(['employee_id'=>$employeeId,'start_date'=>$startDate,'end_date'=>$endDate]);if($overlap->fetchColumn()){$db->rollBack();return['ok'=>false,'error'=>'travel_overlap'];}$statement->execute($record);$id=(int)$db->lastInsertId();app_mgs_hr_write_audit($db,'hr_travel_request_create',$employeeId,$actor,'HR travel request recorded',['id'=>$id,'start_date'=>$startDate,'end_date'=>$endDate]);$db->commit();return['ok'=>true,'id'=>$id,'record'=>$record];}catch(Throwable $e){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'travel_save_failed'];}
}

function app_mgs_save_hr_employee_document(array $input, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'error' => 'db_not_ready'];
    }
    app_audit_ensure_schema($db);

    $employeeId = (int) ($input['employee_account_id'] ?? $input['employee_id'] ?? 0);$employee=app_mgs_hr_employee_record($db,$employeeId);if($employee===[])return['ok'=>false,'error'=>'employee_not_found'];
    $employeeName=clean_text((string)($employee['full_name']??$employee['login']??''));
    $documentType = clean_text((string) ($input['document_type'] ?? ''));
    if(!array_key_exists($documentType,app_mgs_hr_required_document_types()))return['ok'=>false,'error'=>'invalid_document_type'];
    $issuedAt=app_mgs_hr_date_value($input['issued_at']??null);$expiresAt=app_mgs_hr_date_value($input['expires_at']??null);if(($input['issued_at']??'')!==''&&$issuedAt===null)return['ok'=>false,'error'=>'invalid_issued_date'];if(($input['expires_at']??'')!==''&&$expiresAt===null)return['ok'=>false,'error'=>'invalid_expiry_date'];if($issuedAt!==null&&$expiresAt!==null&&$issuedAt>$expiresAt)return['ok'=>false,'error'=>'invalid_document_dates'];
    $status = app_mgs_hr_status((string) ($input['status'] ?? 'pending'), ['missing', 'pending', 'complete', 'expired', 'rejected'], 'pending');
    $record = [
        'employee_account_id' => $employeeId ?: null,
        'employee_name' => $employeeName,
        'document_type' => $documentType,
        'document_no' => clean_text((string) ($input['document_no'] ?? '')),
        'status' => $status,
        'issued_at' => $issuedAt,
        'expires_at' => $expiresAt,
        'file_ref' => clean_text((string) ($input['file_ref'] ?? '')),
        'note' => clean_text((string) ($input['note'] ?? $input['note_text'] ?? '')),
        'metadata_json' => app_db_json_encode(['input' => $input]),
        'created_by_account_id' => (int) ($actor['id'] ?? 0) ?: null,
        'created_by_name' => clean_text((string) ($actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'system')),
    ];

    $statement = $db->prepare(
        'INSERT INTO hr_employee_documents (
            employee_account_id, employee_name, document_type, document_no, status, issued_at, expires_at,
            file_ref, note, metadata_json, created_by_account_id, created_by_name
         ) VALUES (
            :employee_account_id, :employee_name, :document_type, :document_no, :status, :issued_at, :expires_at,
            :file_ref, :note, :metadata_json, :created_by_account_id, :created_by_name
         )'
    );
    try{$db->beginTransaction();$statement->execute($record);$id=(int)$db->lastInsertId();app_mgs_hr_write_audit($db,'hr_employee_document_save',$employeeId,$actor,'HR employee document recorded',['id'=>$id,'document_type'=>$documentType]);$db->commit();return['ok'=>true,'id'=>$id,'record'=>$record];}catch(Throwable $e){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'document_save_failed'];}
}

function app_mgs_hr_document_completeness(?PDO $db = null, int $limit = 12): array
{
    $db = $db instanceof PDO ? $db : app_db();
    $required = app_mgs_hr_required_document_types();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }

    $employees = [];
    try {
        $statement = $db->prepare(
            'SELECT id, full_name, login, employee_role, account_type, status
               FROM portal_accounts
              WHERE role = "employee"
              ORDER BY full_name, id
              LIMIT ' . max(1, min(50, $limit))
        );
        $statement->execute();
        $employees = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }

    $rows = [];
    foreach ($employees as $employee) {
        $employeeId = (int) ($employee['id'] ?? 0);
        $documents = [];
        try {
            $docStatement = $db->prepare(
                'SELECT document_type, status, expires_at
                   FROM hr_employee_documents
                  WHERE employee_account_id = :employee_account_id
                  ORDER BY updated_at DESC, id DESC'
            );
            $docStatement->execute(['employee_account_id' => $employeeId]);
            foreach ($docStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $document) {
                $type = (string) ($document['document_type'] ?? '');
                if ($type !== '' && !isset($documents[$type])) {
                    $documents[$type] = $document;
                }
            }
        } catch (Throwable) {
        }

        $complete = 0;
        $missing = [];
        foreach ($required as $type => $label) {
            $document = $documents[$type] ?? null;
            $status = is_array($document) ? (string) ($document['status'] ?? '') : '';
            $expiresAt = is_array($document) ? app_mgs_hr_date_value($document['expires_at'] ?? null) : null;
            $isExpired = $expiresAt !== null && $expiresAt < date('Y-m-d');
            if ($status === 'complete' && !$isExpired) {
                $complete++;
            } else {
                $missing[] = $label;
            }
        }

        $requiredCount = count($required);
        $rows[] = [
            'employee_account_id' => $employeeId,
            'employee_name' => app_mgs_hr_employee_name($db, $employeeId, (string) ($employee['full_name'] ?? $employee['login'] ?? '')),
            'role' => (string) ($employee['employee_role'] ?? $employee['account_type'] ?? ''),
            'required_count' => $requiredCount,
            'complete_count' => $complete,
            'missing_count' => max(0, $requiredCount - $complete),
            'complete_percent' => $requiredCount > 0 ? (int) round(($complete / $requiredCount) * 100) : 0,
            'missing_documents' => $missing,
        ];
    }

    return $rows;
}

function app_mgs_hr_leave_rows(?PDO $db = null, int $limit = 6): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }

    try {
        $statement = $db->prepare('SELECT * FROM hr_leave_requests ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(50, $limit)));
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

function app_mgs_hr_travel_rows(?PDO $db = null, int $limit = 6): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }

    try {
        $statement = $db->prepare('SELECT * FROM hr_travel_requests ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(50, $limit)));
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

function app_mgs_hr_estimate_gross_from_net(float $targetNet, ?array $profile = null): array
{
    $targetNet = app_mgs_hr_money($targetNet);
    if ($targetNet <= 0) {
        return app_mgs_hr_payroll_preview(['gross_salary' => 0], $profile);
    }

    $low = 0.0;
    $high = max(1000.0, $targetNet * 2.5);
    for ($i = 0; $i < 12; $i++) {
        $preview = app_mgs_hr_payroll_preview(['gross_salary' => $high], $profile);
        if ((float) ($preview['net_salary'] ?? 0) >= $targetNet) {
            break;
        }
        $high *= 1.5;
    }

    $best = app_mgs_hr_payroll_preview(['gross_salary' => $high], $profile);
    for ($i = 0; $i < 32; $i++) {
        $mid = ($low + $high) / 2;
        $preview = app_mgs_hr_payroll_preview(['gross_salary' => $mid], $profile);
        if ((float) ($preview['net_salary'] ?? 0) >= $targetNet) {
            $best = $preview;
            $high = $mid;
        } else {
            $low = $mid;
        }
    }

    $best['net_salary'] = $targetNet;
    return $best;
}

function app_mgs_save_hr_employee_compensation(array $input, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'error' => 'db_not_ready'];
    }
    app_audit_ensure_schema($db);

    $employeeId = (int) ($input['employee_account_id'] ?? $input['employee_id'] ?? 0);
    $employee=app_mgs_hr_employee_record($db,$employeeId);if($employee===[])return['ok'=>false,'error'=>'employee_not_found'];
    $employeeName=clean_text((string)($employee['full_name']??$employee['login']??''));
    $reason = clean_text((string) ($input['reason'] ?? $input['change_reason'] ?? $input['note'] ?? $input['note_text'] ?? ''));
    if ($reason === '') {
        $reason = 'Maaş profili yeniləndi';
    }
    $salaryMode = app_mgs_hr_status((string) ($input['salary_mode'] ?? 'gross'), ['gross', 'net'], 'gross');
    $effectiveFrom = app_mgs_hr_date_value($input['effective_from'] ?? null) ?? date('Y-m-d');
    if ($salaryMode === 'net') {
        $salary=(float)($input['net_salary']??0);if($salary<=0)return['ok'=>false,'error'=>'salary_required'];$preview = app_mgs_hr_estimate_gross_from_net($salary);
    } else {
        $salary=(float)($input['gross_salary']??0);if($salary<=0)return['ok'=>false,'error'=>'salary_required'];$preview = app_mgs_hr_payroll_preview(['gross_salary' => app_mgs_hr_money($salary)]);
    }

    $record = [
        'employee_account_id' => $employeeId,
        'employee_name' => $employeeName,
        'salary_mode' => $salaryMode,
        'gross_salary' => app_mgs_hr_money($preview['gross_salary'] ?? 0),
        'net_salary' => app_mgs_hr_money($preview['net_salary'] ?? 0),
        'employee_total_deductions' => app_mgs_hr_money($preview['employee_total_deductions'] ?? 0),
        'employer_total_cost' => app_mgs_hr_money($preview['employer_total_cost'] ?? 0),
        'effective_from' => $effectiveFrom,
        'status' => 'active',
        'calculation_json' => app_db_json_encode(['input' => $input, 'preview' => $preview]),
        'note' => clean_text((string) ($input['note'] ?? $input['note_text'] ?? '')),
        'created_by_account_id' => (int) ($actor['id'] ?? 0) ?: null,
        'created_by_name' => clean_text((string) ($actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'system')),
    ];

    try {
        $db->beginTransaction();
        $beforeStatement=$db->prepare('SELECT * FROM hr_employee_compensations WHERE employee_account_id = :employee_account_id AND status = "active" ORDER BY effective_from DESC, id DESC LIMIT 1 FOR UPDATE');
        $beforeStatement->execute(['employee_account_id'=>$employeeId]);
        $before=$beforeStatement->fetch(PDO::FETCH_ASSOC)?:null;
        $archive = $db->prepare('UPDATE hr_employee_compensations SET status = "archived" WHERE employee_account_id = :employee_account_id AND status = "active"');
        $archive->execute(['employee_account_id' => $employeeId]);

        $statement = $db->prepare(
            'INSERT INTO hr_employee_compensations (
                employee_account_id, employee_name, salary_mode, gross_salary, net_salary,
                employee_total_deductions, employer_total_cost, effective_from, status,
                calculation_json, note, created_by_account_id, created_by_name
             ) VALUES (
                :employee_account_id, :employee_name, :salary_mode, :gross_salary, :net_salary,
                :employee_total_deductions, :employer_total_cost, :effective_from, :status,
                :calculation_json, :note, :created_by_account_id, :created_by_name
             )'
        );
        $statement->execute($record);
        $id = (int) $db->lastInsertId();
        $afterStatement=$db->prepare('SELECT * FROM hr_employee_compensations WHERE id = :id');
        $afterStatement->execute(['id'=>$id]);
        $after=$afterStatement->fetch(PDO::FETCH_ASSOC)?:$record;
        app_mgs_hr_write_audit($db,'hr_employee_compensation_save',$employeeId,$actor,'HR employee compensation saved: '.$employeeName,['id'=>$id,'salary_mode'=>$salaryMode,'gross_salary'=>$record['gross_salary']],$reason,is_array($before)?$before:[],is_array($after)?$after:[],'compensation');
        $db->commit();
    } catch (Throwable $exception) {
        if($db->inTransaction())$db->rollBack();
        return ['ok' => false, 'error' => 'compensation_save_failed', 'message' => $exception->getMessage()];
    }

    return ['ok' => true, 'id' => $id, 'record' => $record, 'preview' => $preview];
}

function app_mgs_hr_employee_compensation(int $employeeId, ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || $employeeId <= 0 || !app_mgs_ensure_schema($db)) {
        return [];
    }

    try {
        $statement = $db->prepare(
            'SELECT *
               FROM hr_employee_compensations
              WHERE employee_account_id = :employee_account_id
                AND status = "active"
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $statement->execute(['employee_account_id' => $employeeId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    } catch (Throwable) {
        return [];
    }
}

function app_mgs_hr_compensation_report_rows(?PDO $db = null, int $limit = 50): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }

    try {
        $statement = $db->prepare(
            'SELECT c.*, a.account_type, a.employee_role, a.login
               FROM hr_employee_compensations c
               LEFT JOIN portal_accounts a ON a.id = c.employee_account_id
              WHERE c.status = "active"
              ORDER BY c.updated_at DESC, c.id DESC
              LIMIT ' . max(1, min(200, $limit))
        );
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

function app_mgs_hr_score_reason_defaults(): array
{
    return [
        'absence' => ['title' => 'Ise gelmeyib', 'points' => -20.0, 'type' => 'penalty'],
        'late' => ['title' => 'Ise gecikib', 'points' => -5.0, 'type' => 'penalty'],
        'task_missed' => ['title' => 'Tapsiriq icra etmeyib', 'points' => -20.0, 'type' => 'penalty'],
        'discipline' => ['title' => 'Intizam pozuntusu', 'points' => -10.0, 'type' => 'penalty'],
        'bonus' => ['title' => 'Mukafat', 'points' => 10.0, 'type' => 'reward'],
        'quality' => ['title' => 'Keyfiyyetli icra', 'points' => 5.0, 'type' => 'reward'],
        'ticket_verified' => ['title' => 'Bilet telemetriya ile tesdiqlendi', 'points' => 5.0, 'type' => 'reward'],
        'ticket_verification_failed' => ['title' => 'Hell olunmamis bilet baglanmaga gonderildi', 'points' => -5.0, 'type' => 'penalty'],
        'manual' => ['title' => 'Manual qeyd', 'points' => 0.0, 'type' => 'penalty'],
    ];
}

function app_mgs_record_hr_score_event(array $input, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'error' => 'db_not_ready'];
    }
    app_audit_ensure_schema($db);

    $employeeId = (int) ($input['employee_account_id'] ?? $input['employee_id'] ?? 0);
    $employee=app_mgs_hr_employee_record($db,$employeeId);if($employee===[])return['ok'=>false,'error'=>'employee_not_found'];

    $defaults = app_mgs_hr_score_reason_defaults();
    $reasonCode = clean_text((string) ($input['reason_code'] ?? $input['score_reason_code'] ?? 'manual'));
    $reason = $defaults[$reasonCode] ?? $defaults['manual'];
    $eventType = app_mgs_hr_status((string) ($input['event_type'] ?? $reason['type']), ['penalty', 'reward', 'reset'], (string) $reason['type']);
    $changeReason=mb_substr(clean_text((string)($input['reason']??$input['change_reason']??$input['note']??$input['note_text']??'')),0,255);
    if(($eventType==='reset'||$reasonCode==='manual')&&$changeReason==='')return['ok'=>false,'error'=>'reason_required'];
    $points = array_key_exists('points_delta', $input) && (float) $input['points_delta'] !== 0.0
        ? (float) $input['points_delta']
        : (float) $reason['points'];
    if ($eventType === 'penalty') {
        $points = -abs($points);
    } elseif ($eventType === 'reward') {
        $points = abs($points);
    } elseif ($eventType === 'reset') {
        $points = 0.0;
    }

    $eventDate = app_mgs_hr_date_value($input['event_date'] ?? null) ?? date('Y-m-d');

    // Fairness rule (docs vision §9.3): a penalty must never land on a day the
    // employee was on approved leave/sick time. Rewards and resets are not
    // blocked -- only a negative points_delta on a day already covered by an
    // approved/paid hr_leave_requests row is refused. This does not touch
    // existing leave/payroll data; it only gates the score-event write.
    if ($eventType === 'penalty' && empty($input['override_leave_conflict'])) {
        $leaveConflict = $db->prepare(
            "SELECT id, leave_type FROM hr_leave_requests
              WHERE employee_account_id = :employee_id
                AND status IN ('approved', 'paid')
                AND start_date IS NOT NULL AND end_date IS NOT NULL
                AND :event_date BETWEEN start_date AND end_date
              LIMIT 1"
        );
        $leaveConflict->execute(['employee_id' => $employeeId, 'event_date' => $eventDate]);
        $conflictRow = $leaveConflict->fetch(PDO::FETCH_ASSOC);
        if (is_array($conflictRow)) {
            return [
                'ok' => false,
                'error' => 'penalty_blocked_by_approved_leave',
                'leave_request_id' => (int) $conflictRow['id'],
                'leave_type' => (string) $conflictRow['leave_type'],
            ];
        }
    }

    // Fairness rule (vision §9.3): "isciye verilmeyen tapsiriq onun aleyhine
    // hesablanmir" -- a task_missed penalty must reference a real ticket that
    // was actually assigned to this employee, not just typed in against them.
    if ($eventType === 'penalty' && $reasonCode === 'task_missed' && empty($input['override_unassigned_task'])) {
        $relatedTicketNo = mb_substr(clean_text((string) ($input['related_ticket_no'] ?? $input['source_id'] ?? '')), 0, 32);
        if ($relatedTicketNo === '') {
            return ['ok' => false, 'error' => 'task_missed_requires_ticket_reference'];
        }
        $ticketCheck = $db->prepare('SELECT id, assigned_technician_account_id FROM portal_tickets WHERE ticket_no = :ticket_no LIMIT 1');
        $ticketCheck->execute(['ticket_no' => $relatedTicketNo]);
        $ticketRow = $ticketCheck->fetch(PDO::FETCH_ASSOC);
        if (!is_array($ticketRow)) {
            return ['ok' => false, 'error' => 'related_ticket_not_found'];
        }
        if ((int) ($ticketRow['assigned_technician_account_id'] ?? 0) !== $employeeId) {
            return ['ok' => false, 'error' => 'task_not_assigned_to_employee'];
        }
        $input['source_type'] = $input['source_type'] ?? 'ticket';
        $input['source_id'] = $relatedTicketNo;

        // Fairness rule (vision §9.3): "musterinin cavab vermemesi ayrica
        // statusdur" / "basqa sobeden asili gecikme ayrilir" -- if the ticket
        // was sitting in "awaiting customer response" or "blocked on another
        // department" as of the penalty's own event_date, the delay is not
        // the assigned technician's fault.
        if (empty($input['override_ticket_waiting_state'])) {
            $waitingState = app_db_ticket_waiting_state_as_of((int) $ticketRow['id'], $eventDate);
            if (!empty($waitingState['waiting'])) {
                if ((string) ($waitingState['type'] ?? '') === 'customer_no_response') {
                    return ['ok' => false, 'error' => 'penalty_blocked_by_customer_no_response'];
                }
                return [
                    'ok' => false,
                    'error' => 'penalty_blocked_by_dept_dependency',
                    'waiting_on_department' => (string) ($waitingState['department'] ?? ''),
                ];
            }
        }

        // Fairness rule (vision §9.3): "kutlevi sebeke incident-i texnikin
        // sexsi nasazligi sayilmir" -- if an unusual number of tickets opened
        // company-wide the same day, treat it as a likely mass incident and
        // refuse the individual penalty rather than silently accept it.
        $massThreshold = (int) app_business_rule_get($db, 'hr_mass_incident_daily_ticket_threshold', 30);
        if ($massThreshold > 0 && empty($input['override_mass_incident'])) {
            $ticketSurge = $db->prepare('SELECT COUNT(*) FROM portal_tickets WHERE DATE(opened_at) = :event_date');
            $ticketSurge->execute(['event_date' => $eventDate]);
            $ticketCountThatDay = (int) $ticketSurge->fetchColumn();
            if ($ticketCountThatDay >= $massThreshold) {
                return [
                    'ok' => false,
                    'error' => 'penalty_blocked_by_mass_incident',
                    'ticket_count' => $ticketCountThatDay,
                    'threshold' => $massThreshold,
                ];
            }
        }
    }

    $record = [
        'employee_account_id' => $employeeId,
        'employee_name' => clean_text((string)($employee['full_name']??$employee['login']??'')),
        'event_type' => $eventType,
        'reason_code' => $reasonCode,
        'title' => clean_text((string) ($input['title'] ?? $reason['title'])),
        'points_delta' => app_mgs_hr_money(abs($points)) * ($points < 0 ? -1 : 1),
        'event_date' => $eventDate,
        'period_start' => app_mgs_hr_date_value($input['period_start'] ?? null),
        'period_end' => app_mgs_hr_date_value($input['period_end'] ?? null),
        'note' => clean_text((string) ($input['note'] ?? $input['note_text'] ?? '')),
        'source_type' => clean_text((string) ($input['source_type'] ?? '')) ?: null,
        'source_id' => clean_text((string) ($input['source_id'] ?? '')) ?: null,
        'idempotency_key' => mb_substr(clean_text((string) ($input['idempotency_key'] ?? '')), 0, 191) ?: null,
        'created_by_account_id' => (int) ($actor['id'] ?? 0) ?: null,
        'created_by_name' => clean_text((string) ($actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'system')),
    ];

    try {
        $db->beginTransaction();
        if (is_string($record['idempotency_key']) && $record['idempotency_key'] !== '') {
            $duplicate = $db->prepare('SELECT id FROM hr_score_events WHERE idempotency_key = :idempotency_key LIMIT 1 FOR UPDATE');
            $duplicate->execute(['idempotency_key' => $record['idempotency_key']]);
            $existingId = (int) ($duplicate->fetchColumn() ?: 0);
            if ($existingId > 0) {
                $db->rollBack();
                return ['ok' => true, 'id' => $existingId, 'existing' => true, 'record' => $record];
            }
        }
        $statement = $db->prepare(
            'INSERT INTO hr_score_events (
                employee_account_id, employee_name, event_type, reason_code, title, points_delta,
                event_date, period_start, period_end, note, source_type, source_id, idempotency_key,
                created_by_account_id, created_by_name
             ) VALUES (
                :employee_account_id, :employee_name, :event_type, :reason_code, :title, :points_delta,
                :event_date, :period_start, :period_end, :note, :source_type, :source_id, :idempotency_key,
                :created_by_account_id, :created_by_name
             )'
        );
        $statement->execute($record);
        $id = (int) $db->lastInsertId();
        app_mgs_hr_write_audit($db,'hr_score_event',$employeeId,$actor,'HR score event: '.$record['employee_name'].' '.$record['points_delta'],['id'=>$id,'event_type'=>$eventType,'points_delta'=>$record['points_delta']],$changeReason,[],['id'=>$id]+$record,'score');
        $db->commit();
    } catch (Throwable $exception) {
        if($db->inTransaction())$db->rollBack();
        return ['ok' => false, 'error' => 'score_event_save_failed', 'message' => $exception->getMessage()];
    }

    return ['ok' => true, 'id' => $id, 'record' => $record];
}

// Fairness rule (vision §9.3): "iscinin KPI-ye etiraz mexanizmi olur" -- an
// employee (or HR on their behalf) can dispute a score event; an admin who is
// not the appellant reviews it and, if overturned, the effect is reversed via
// a new compensating hr_score_events entry (never edited/deleted in place).
function app_mgs_hr_score_appeal_file(PDO $db, int $scoreEventId, string $reason, array $actor): array
{
    if (!app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'error' => 'db_not_ready'];
    }
    $reason = mb_substr(clean_text($reason), 0, 500);
    if ($scoreEventId <= 0 || $reason === '') {
        return ['ok' => false, 'error' => 'invalid_appeal'];
    }
    $eventStatement = $db->prepare('SELECT id, employee_account_id, employee_name FROM hr_score_events WHERE id = :id LIMIT 1');
    $eventStatement->execute(['id' => $scoreEventId]);
    $eventRow = $eventStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($eventRow)) {
        return ['ok' => false, 'error' => 'score_event_not_found'];
    }
    $employeeId = (int) $eventRow['employee_account_id'];
    $actorId = (int) ($actor['id'] ?? 0);
    $isSelf = $actorId > 0 && $actorId === $employeeId;
    if (!$isSelf && !app_portal_is_admin($actor)) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }
    $existing = $db->prepare("SELECT id FROM hr_score_appeals WHERE score_event_id = :score_event_id AND status = 'pending' LIMIT 1");
    $existing->execute(['score_event_id' => $scoreEventId]);
    if ((int) ($existing->fetchColumn() ?: 0) > 0) {
        return ['ok' => false, 'error' => 'appeal_already_pending'];
    }
    try {
        $db->beginTransaction();
        $insert = $db->prepare(
            'INSERT INTO hr_score_appeals (score_event_id, employee_account_id, appeal_reason, status, created_by_account_id, created_by_name)
             VALUES (:score_event_id, :employee_account_id, :appeal_reason, "pending", :actor_id, :actor_name)'
        );
        $insert->execute([
            'score_event_id' => $scoreEventId,
            'employee_account_id' => $employeeId,
            'appeal_reason' => $reason,
            'actor_id' => $actorId ?: null,
            'actor_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'employee')),
        ]);
        $id = (int) $db->lastInsertId();
        app_mgs_hr_write_audit($db, 'hr_score_appeal', $employeeId, $actor, 'HR score appeal filed for event ' . $scoreEventId, ['id' => $id, 'score_event_id' => $scoreEventId], $reason, [], ['id' => $id, 'status' => 'pending'], 'score');
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'appeal_file_failed', 'message' => $exception->getMessage()];
    }
    return ['ok' => true, 'id' => $id];
}

function app_mgs_hr_score_appeal_resolve(PDO $db, int $appealId, string $decision, string $resolutionNote, array $actor): array
{
    if (!app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'error' => 'db_not_ready'];
    }
    if (!app_portal_is_admin($actor)) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }
    if (!in_array($decision, ['upheld', 'overturned'], true)) {
        return ['ok' => false, 'error' => 'invalid_decision'];
    }
    $resolutionNote = mb_substr(clean_text($resolutionNote), 0, 500);
    $actorId = (int) ($actor['id'] ?? 0);

    // NOTE: app_mgs_record_hr_score_event() manages its own top-level
    // transaction, so it cannot be called while one is already open on $db --
    // this function therefore does its read/validate and reversal-record
    // steps OUTSIDE a transaction, then performs the actual status write as a
    // single atomic UPDATE ... WHERE status = 'pending' (a compare-and-swap
    // that fails safely if another reviewer already resolved the appeal).
    $appealStatement = $db->prepare('SELECT * FROM hr_score_appeals WHERE id = :id LIMIT 1');
    $appealStatement->execute(['id' => $appealId]);
    $appeal = $appealStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($appeal)) {
        return ['ok' => false, 'error' => 'appeal_not_found'];
    }
    if ((string) $appeal['status'] !== 'pending') {
        return ['ok' => false, 'error' => 'appeal_already_resolved'];
    }
    if ($actorId > 0 && $actorId === (int) $appeal['employee_account_id']) {
        return ['ok' => false, 'error' => 'appellant_cannot_review_own_appeal'];
    }

    try {
        $reversalId = null;
        if ($decision === 'overturned') {
            $eventStatement = $db->prepare('SELECT * FROM hr_score_events WHERE id = :id LIMIT 1');
            $eventStatement->execute(['id' => (int) $appeal['score_event_id']]);
            $scoreEvent = $eventStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($scoreEvent)) {
                return ['ok' => false, 'error' => 'original_score_event_missing'];
            }
            $originalPoints = (float) $scoreEvent['points_delta'];
            $reversal = app_mgs_record_hr_score_event([
                'employee_account_id' => (int) $scoreEvent['employee_account_id'],
                'reason_code' => 'appeal_overturned',
                'event_type' => $originalPoints < 0 ? 'reward' : 'penalty',
                'points_delta' => abs($originalPoints),
                'title' => 'Etiraz neticesinde geri qaytarildi',
                'reason' => 'Appeal #' . $appealId . ' overturned: ' . $resolutionNote,
                'note' => $resolutionNote,
                'source_type' => 'hr_score_appeal',
                'source_id' => (string) $appealId,
                'idempotency_key' => 'appeal-reversal:' . $appealId,
            ], $actor, $db);
            if (empty($reversal['ok'])) {
                return ['ok' => false, 'error' => 'reversal_failed', 'detail' => (string) ($reversal['error'] ?? '')];
            }
            $reversalId = (int) ($reversal['id'] ?? 0) ?: null;
        }

        $db->beginTransaction();
        $update = $db->prepare(
            "UPDATE hr_score_appeals SET status = :status, reviewed_by_account_id = :actor_id, reviewed_by_name = :actor_name,
                resolution_note = :resolution_note, reversal_score_event_id = :reversal_id, reviewed_at = NOW()
             WHERE id = :id AND status = 'pending'"
        );
        $update->execute([
            'status' => $decision,
            'actor_id' => $actorId ?: null,
            'actor_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'admin')),
            'resolution_note' => $resolutionNote ?: null,
            'reversal_id' => $reversalId,
            'id' => $appealId,
        ]);
        if ($update->rowCount() === 0) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'appeal_already_resolved'];
        }
        app_mgs_hr_write_audit($db, 'hr_score_appeal', (int) $appeal['employee_account_id'], $actor, 'HR score appeal ' . $decision . ': #' . $appealId, ['id' => $appealId, 'decision' => $decision, 'reversal_score_event_id' => $reversalId], $resolutionNote, $appeal, ['id' => $appealId, 'status' => $decision], 'score');
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'appeal_resolve_failed', 'message' => $exception->getMessage()];
    }

    return ['ok' => true, 'status' => $decision, 'reversal_score_event_id' => $reversalId];
}

function app_mgs_hr_score_appeals(PDO $db, array $filters = []): array
{
    if (!app_mgs_ensure_schema($db)) {
        return [];
    }
    $where = [];
    $params = [];
    if (!empty($filters['status'])) {
        $where[] = 'a.status = :status';
        $params['status'] = (string) $filters['status'];
    }
    if (!empty($filters['employee_account_id'])) {
        $where[] = 'a.employee_account_id = :employee_account_id';
        $params['employee_account_id'] = (int) $filters['employee_account_id'];
    }
    $sql = 'SELECT a.*, e.employee_name AS score_event_title, e.points_delta AS score_event_points, e.reason_code AS score_event_reason_code
            FROM hr_score_appeals a
            LEFT JOIN hr_score_events e ON e.id = a.score_event_id'
        . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY a.created_at DESC LIMIT 200';
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function app_mgs_hr_score_recent_events(PDO $db, int $limit = 25): array
{
    if (!app_mgs_ensure_schema($db)) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    $statement = $db->prepare(
        "SELECT e.*, (SELECT COUNT(*) FROM hr_score_appeals a WHERE a.score_event_id = e.id AND a.status = 'pending') AS has_pending_appeal
         FROM hr_score_events e
         ORDER BY e.id DESC LIMIT {$limit}"
    );
    $statement->execute();
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function app_mgs_hr_score_summary(int $employeeId, ?string $periodStart = null, ?string $periodEnd = null, ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    $periodStart = app_mgs_hr_date_value($periodStart) ?? date('Y-01-01');
    $periodEnd = app_mgs_hr_date_value($periodEnd) ?? date('Y-12-31');
    $result = [
        'employee_account_id' => $employeeId,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'base_score' => 100,
        'score' => 100,
        'penalty_points' => 0.0,
        'reward_points' => 0.0,
        'event_count' => 0,
    ];
    if (!$db instanceof PDO || $employeeId <= 0 || !app_mgs_ensure_schema($db)) {
        return $result;
    }

    try {
        $statement = $db->prepare(
            'SELECT event_type, points_delta
               FROM hr_score_events
              WHERE employee_account_id = :employee_account_id
                AND event_date BETWEEN :period_start AND :period_end
              ORDER BY event_date,id'
        );
        $statement->execute([
            'employee_account_id' => $employeeId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);
        $score = 100.0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if(($row['event_type']??'')==='reset'){$score=100.0;$result['penalty_points']=0.0;$result['reward_points']=0.0;$result['event_count']++;continue;}
            $delta = (float) ($row['points_delta'] ?? 0);
            $score += $delta;
            if ($delta < 0) {
                $result['penalty_points'] += $delta;
            } elseif ($delta > 0) {
                $result['reward_points'] += $delta;
            }
            $result['event_count']++;
        }
        $result['score'] = (int) round(max(0, min(100, $score)));
    } catch (Throwable) {
    }

    return $result;
}

function app_mgs_hr_scoreboard_rows(?PDO $db = null, int $limit = 50, ?string $periodStart = null, ?string $periodEnd = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }

    try {
        $statement = $db->prepare(
            'SELECT id, full_name, login, account_type, employee_role
               FROM portal_accounts
              WHERE role = "employee"
                AND is_active = 1
              ORDER BY full_name, id
              LIMIT ' . max(1, min(200, $limit))
        );
        $statement->execute();
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $employee) {
            $employeeId = (int) ($employee['id'] ?? 0);
            $summary = app_mgs_hr_score_summary($employeeId, $periodStart, $periodEnd, $db);
            $compensation = app_mgs_hr_employee_compensation($employeeId, $db);
            $rows[] = $employee + $summary + [
                'employee_account_id' => $employeeId,
                'employee_name' => app_mgs_hr_employee_name($db, $employeeId, (string) ($employee['full_name'] ?? $employee['login'] ?? '')),
                'gross_salary' => (float) ($compensation['gross_salary'] ?? 0),
                'net_salary' => (float) ($compensation['net_salary'] ?? 0),
                'employer_total_cost' => (float) ($compensation['employer_total_cost'] ?? 0),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0)));
        return $rows;
    } catch (Throwable) {
        return [];
    }
}

function app_mgs_hr_record_map(string $recordType): ?array
{
    $recordType = strtolower(trim($recordType));
    $maps = [
        'payroll' => [
            'table' => 'hr_payroll_records',
            'label' => 'Maas',
            'category' => 'hr_payroll',
            'amount_column' => 'employer_total_cost',
            'allowed_statuses' => ['draft', 'approved', 'paid', 'void'],
            'payable_statuses' => ['approved', 'paid'],
            'entity_prefix' => 'hr-payroll-',
        ],
        'leave' => [
            'table' => 'hr_leave_requests',
            'label' => 'Mezuniyyet',
            'category' => 'hr_leave',
            'amount_column' => 'calculated_leave_pay',
            'allowed_statuses' => ['draft', 'approved', 'paid', 'cancelled'],
            'payable_statuses' => ['approved', 'paid'],
            'entity_prefix' => 'hr-leave-',
        ],
        'travel' => [
            'table' => 'hr_travel_requests',
            'label' => 'Ezamiyyet',
            'category' => 'hr_travel',
            'amount_column' => 'travel_allowance',
            'allowed_statuses' => ['draft', 'approved', 'paid', 'cancelled'],
            'payable_statuses' => ['approved', 'paid'],
            'entity_prefix' => 'hr-travel-',
        ],
        'document' => [
            'table' => 'hr_employee_documents',
            'label' => 'Sened',
            'category' => 'hr_document',
            'amount_column' => '',
            'allowed_statuses' => ['complete', 'pending', 'missing', 'expired'],
            'payable_statuses' => [],
            'entity_prefix' => 'hr-document-',
        ],
    ];

    return $maps[$recordType] ?? null;
}

function app_mgs_hr_record_entity_id(string $recordType, int $recordId): string
{
    $map = app_mgs_hr_record_map($recordType);
    if (!is_array($map)) {
        return '';
    }

    return (string) $map['entity_prefix'] . $recordId;
}

function app_mgs_hr_load_record(PDO $db, string $recordType, int $recordId): ?array
{
    $map = app_mgs_hr_record_map($recordType);
    if (!is_array($map) || $recordId <= 0) {
        return null;
    }

    try {
        $statement = $db->prepare('SELECT * FROM ' . $map['table'] . ' WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $recordId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable) {
        return null;
    }
}

function app_mgs_hr_update_record_status(string $recordType, int $recordId, string $status, array $actor = [], ?PDO $db = null, string $reason = ''): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'error' => 'db_not_ready'];
    }
    app_audit_ensure_schema($db);

    $map = app_mgs_hr_record_map($recordType);
    if (!is_array($map) || $recordId <= 0) {
        return ['ok' => false, 'error' => 'record_type_invalid'];
    }

    $status = app_mgs_hr_status($status, (array) $map['allowed_statuses'], '');
    if ($status === '') {
        return ['ok' => false, 'error' => 'status_invalid'];
    }

    try{$db->beginTransaction();$load=$db->prepare('SELECT * FROM '.$map['table'].' WHERE id=:id FOR UPDATE');$load->execute(['id'=>$recordId]);$record=$load->fetch(PDO::FETCH_ASSOC);}catch(Throwable $e){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'record_load_failed'];}
    if(!is_array($record)){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'record_not_found'];}
    $current=(string)($record['status']??'');
    $transitions=[
        'payroll'=>['draft'=>['approved','void'],'approved'=>['paid','void'],'paid'=>[],'void'=>[]],
        'leave'=>['draft'=>['approved','cancelled'],'approved'=>['paid','cancelled'],'paid'=>[],'cancelled'=>[]],
        'travel'=>['draft'=>['approved','cancelled'],'approved'=>['paid','cancelled'],'paid'=>[],'cancelled'=>[]],
        'document'=>['missing'=>['pending','complete'],'pending'=>['complete','missing','expired'],'complete'=>['expired'],'expired'=>[]],
    ];
    if($status!==$current&&!in_array($status,(array)($transitions[$recordType][$current]??[]),true)){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'status_transition_invalid'];}
    if($status!==$current){
        try{$reason=app_mgs_hr_required_reason($reason);}catch(InvalidArgumentException){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'reason_required'];}
    }

    try {
        $statement = $db->prepare('UPDATE ' . $map['table'] . ' SET status = :status WHERE id = :id');
        $statement->execute(['status' => $status, 'id' => $recordId]);
        $afterRecord = app_mgs_hr_load_record($db, $recordType, $recordId) ?: $record;
        app_mgs_hr_write_audit($db,'hr_record_status_update',(int)($record['employee_account_id']??0),$actor,'HR status yenilendi: '.$map['label'].' #'.$recordId.' -> '.$status,['recordType'=>$recordType,'recordId'=>$recordId,'previousStatus'=>$current,'status'=>$status],$status!==$current?$reason:'',$record,$afterRecord,'status');
        $record = $afterRecord;
        $db->commit();
    } catch (Throwable $exception) {
        if($db->inTransaction())$db->rollBack();
        return ['ok' => false, 'error' => 'status_update_failed', 'message' => $exception->getMessage()];
    }

    return ['ok' => true, 'record' => $record];
}

function app_mgs_hr_record_period(array $row, string $recordType): string
{
    if ($recordType === 'travel') {
        $start = app_mgs_hr_date_value($row['start_date'] ?? null);
        $end = app_mgs_hr_date_value($row['end_date'] ?? null);
        if ($start !== null && $end !== null && $end !== $start) {
            return $start . ' - ' . $end;
        }

        return $start ?? substr((string) ($row['created_at'] ?? ''), 0, 10);
    }

    $period = (string) ($row['period_month'] ?? '');
    return $period !== '' ? substr($period, 0, 7) : substr((string) ($row['created_at'] ?? ''), 0, 10);
}

function app_mgs_hr_record_finance_expense(string $recordType, int $recordId, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'error' => 'db_not_ready'];
    }

    $map = app_mgs_hr_record_map($recordType);
    if (!is_array($map) || $recordId <= 0) {
        return ['ok' => false, 'error' => 'record_type_invalid'];
    }

    $employeeRole = strtolower(trim((string) ($actor['employee_role'] ?? '')));
    if (!app_portal_is_admin($actor) && $employeeRole !== 'hr') {
        return ['ok' => false, 'error' => 'forbidden'];
    }
    if (!function_exists('app_finance_post_source_entry')) {
        require_once __DIR__ . '/finance-operations-lib.php';
    }
    if (!function_exists('app_finance_post_source_entry')) {
        return ['ok' => false, 'error' => 'finance_service_unavailable'];
    }

    try {
        $db->beginTransaction();
        $load = $db->prepare('SELECT * FROM ' . $map['table'] . ' WHERE id = :id FOR UPDATE');
        $load->execute(['id' => $recordId]);
        $record = $load->fetch(PDO::FETCH_ASSOC);
        if (!is_array($record)) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'record_not_found'];
        }

        $recordStatus = (string) ($record['status'] ?? '');
        if (!in_array($recordStatus, (array) $map['payable_statuses'], true)) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'record_not_payable'];
        }
        $amount = app_mgs_hr_money($record[$map['amount_column']] ?? 0);
        if ($amount <= 0) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'amount_not_ready'];
        }

        $employeeName = clean_text((string) ($record['employee_name'] ?? 'HR employee'));
        $period = app_mgs_hr_record_period($record, $recordType);
        $sourceId = $recordType . ':' . $recordId;
        $payload = [
            'source' => 'hr_finance_bridge',
            'record_type' => $recordType,
            'record_id' => $recordId,
            'record_status' => $recordStatus,
            'employee_name' => $employeeName,
            'employee_account_id' => (int) ($record['employee_account_id'] ?? 0),
            'period' => $period,
        ];
        if ($recordType === 'payroll') {
            $payload['payroll_breakdown'] = [
                'gross_salary' => app_mgs_hr_money($record['gross_salary'] ?? 0),
                'leave_pay' => app_mgs_hr_money($record['leave_pay'] ?? 0),
                'taxable_travel_amount' => app_mgs_hr_money($record['taxable_travel_amount'] ?? 0),
                'payroll_tax_base' => app_mgs_hr_money($record['payroll_tax_base'] ?? 0),
                'employee_income_tax' => app_mgs_hr_money($record['employee_income_tax'] ?? 0),
                'employee_social_insurance' => app_mgs_hr_money($record['employee_social_insurance'] ?? 0),
                'employee_unemployment_insurance' => app_mgs_hr_money($record['employee_unemployment_insurance'] ?? 0),
                'employee_health_insurance' => app_mgs_hr_money($record['employee_health_insurance'] ?? 0),
                'employee_total_deductions' => app_mgs_hr_money($record['employee_total_deductions'] ?? 0),
                'net_salary' => app_mgs_hr_money($record['net_salary'] ?? 0),
                'employer_social_insurance' => app_mgs_hr_money($record['employer_social_insurance'] ?? 0),
                'employer_unemployment_insurance' => app_mgs_hr_money($record['employer_unemployment_insurance'] ?? 0),
                'employer_health_insurance' => app_mgs_hr_money($record['employer_health_insurance'] ?? 0),
                'employer_total_contributions' => app_mgs_hr_money($record['employer_total_contributions'] ?? 0),
                'employer_total_cost' => app_mgs_hr_money($record['employer_total_cost'] ?? 0),
            ];
        }
        $result = app_finance_post_source_entry($db, [
            'entry_type' => 'expense',
            'title' => $map['label'] . ' xerc: ' . $employeeName,
            'category' => (string) $map['category'],
            'amount' => $amount,
            'currency' => 'AZN',
            'transaction_date' => substr((string) ($record['period_month'] ?? $record['start_date'] ?? date('Y-m-d')), 0, 10),
            'employee_account_id' => (int) ($record['employee_account_id'] ?? 0),
            'payment_source' => 'hr',
            'source_type' => 'hr',
            'source_id' => $sourceId,
            'idempotency_key' => 'hr:' . $sourceId,
            'correlation_id' => app_request_correlation_id(),
            'payload' => $payload,
        ], $actor);
        if (empty($result['ok'])) {
            $db->rollBack();
            return $result;
        }

        app_mgs_hr_write_audit(
            $db,
            'hr_finance_expense_recorded',
            (int) ($record['employee_account_id'] ?? 0),
            $actor,
            'HR xerc Finance ledger-e yazildi: ' . $map['label'] . ' #' . $recordId,
            [
                'recordType' => $recordType,
                'recordId' => $recordId,
                'financeEntryId' => (int) ($result['id'] ?? 0),
                'amount' => $amount,
                'correlationId' => app_request_correlation_id(),
            ],
            '',
            null,
            is_array($result['record'] ?? null) ? $result['record'] : [],
            'write'
        );
        $db->commit();

        return [
            'ok' => true,
            'action_id' => (int) ($result['id'] ?? 0),
            'finance_entry_id' => (int) ($result['id'] ?? 0),
            'amount' => $amount,
            'record' => $record,
            'payload' => $payload,
            'existing' => !empty($result['existing']),
        ];
    } catch (Throwable) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'finance_expense_insert_failed'];
    }
}

function app_mgs_hr_cost_report_rows(?PDO $db = null, int $limit = 20): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }

    $rows = [];
    foreach (['payroll', 'leave', 'travel'] as $recordType) {
        $map = app_mgs_hr_record_map($recordType);
        if (!is_array($map)) {
            continue;
        }

        try {
            $statement = $db->prepare('SELECT * FROM ' . $map['table'] . ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(50, $limit)));
            $statement->execute();
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $rows[] = [
                    'record_type' => $recordType,
                    'record_id' => (int) ($row['id'] ?? 0),
                    'type_label' => (string) $map['label'],
                    'employee_name' => (string) ($row['employee_name'] ?? 'HR employee'),
                    'period' => app_mgs_hr_record_period($row, $recordType),
                    'amount' => app_mgs_hr_money($row[$map['amount_column']] ?? 0),
                    'status' => (string) ($row['status'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'category' => (string) $map['category'],
                ];
            }
        } catch (Throwable) {
        }
    }

    usort($rows, static function (array $a, array $b): int {
        $left = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
        $right = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
        if ($left === $right) {
            return ((int) ($b['record_id'] ?? 0)) <=> ((int) ($a['record_id'] ?? 0));
        }

        return $right <=> $left;
    });

    return array_slice($rows, 0, max(1, min(50, $limit)));
}

function app_mgs_hr_finance_bridge_rows(?PDO $db = null, int $limit = 10): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }

    try {
        $statement = $db->prepare(
            'SELECT id, source_id, employee_account_id, amount, currency, status,
                    correlation_id, payload_json, created_at, updated_at
               FROM finance_entries
              WHERE source_type = "hr"
              ORDER BY created_at DESC, id DESC
              LIMIT ' . max(1, min(50, $limit))
        );
        $statement->execute();
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $payload = app_db_json_decode((string) ($row['payload_json'] ?? ''), []);
            $payload['amount'] = app_mgs_hr_money($row['amount'] ?? 0);
            $payload['currency'] = (string) ($row['currency'] ?? 'AZN');
            $payload['employeeName'] = (string) ($payload['employee_name'] ?? '');
            $row['entity_id'] = 'hr-' . str_replace(':', '-', (string) ($row['source_id'] ?? ''));
            $row['payload'] = $payload;
            $rows[] = $row;
        }

        return $rows;
    } catch (Throwable) {
        return [];
    }
}

function app_mgs_inventory_default_categories(): array
{
    return [
        ['code' => 'ont_modem', 'name' => 'ONT / Modem', 'description' => 'Musteriye qurasdirilan ONT ve modem avadanliqlari', 'sort' => 10],
        ['code' => 'router', 'name' => 'Router', 'description' => 'Router ve CPE cihazlari', 'sort' => 20],
        ['code' => 'switch', 'name' => 'Switch', 'description' => 'Switch ve access avadanliqlari', 'sort' => 30],
        ['code' => 'sfp', 'name' => 'SFP / Optik modul', 'description' => 'SFP, transceiver ve optik modul', 'sort' => 40],
        ['code' => 'fiber_cable', 'name' => 'Fiber kabel', 'description' => 'Kabel barabani ve metrajli fiber', 'sort' => 50],
        ['code' => 'connector', 'name' => 'Connector / fast connector', 'description' => 'Fast connector, patchcord ve xirdavat', 'sort' => 60],
        ['code' => 'tool', 'name' => 'Alət / cihaz', 'description' => 'Texnik alətləri ve ölçü cihazlari', 'sort' => 70],
        ['code' => 'other', 'name' => 'Diger', 'description' => 'Diger inventar ve ehtiyat hissələri', 'sort' => 100],
    ];
}

function app_mgs_inventory_normalize_category_code(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9_ -]+/', '', $value) ?? '';
    $value = preg_replace('/[\s-]+/', '_', $value) ?? '';
    return trim($value, '_') ?: 'other';
}

function app_mgs_inventory_seed_categories(PDO $db): void
{
    $statement = $db->prepare(
        'INSERT INTO inventory_categories (category_code, category_name, description, sort_order)
         VALUES (:category_code, :category_name, :description, :sort_order)
         ON DUPLICATE KEY UPDATE
            category_name = VALUES(category_name),
            description = VALUES(description),
            sort_order = VALUES(sort_order),
            status = "active"'
    );

    foreach (app_mgs_inventory_default_categories() as $category) {
        $statement->execute([
            'category_code' => (string) $category['code'],
            'category_name' => (string) $category['name'],
            'description' => (string) $category['description'],
            'sort_order' => (int) $category['sort'],
        ]);
    }

    try {
        $legacy = $db->query(
            'SELECT DISTINCT category
               FROM inventory_stock_items
              WHERE category IS NOT NULL AND category <> ""'
        );
        $legacyRows = $legacy !== false ? ($legacy->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        foreach ($legacyRows as $category) {
            $code = app_mgs_inventory_normalize_category_code((string) $category);
            $statement->execute([
                'category_code' => $code,
                'category_name' => (string) $category,
                'description' => 'Movcud stok mehsullarindan avtomatik elave olunub.',
                'sort_order' => 90,
            ]);
        }
    } catch (Throwable) {
    }
}

function app_mgs_inventory_categories(PDO $db): array
{
    app_mgs_ensure_schema($db);
    try {
        $statement = $db->query(
            'SELECT category_code AS code,
                    category_name AS name,
                    description,
                    status,
                    sort_order AS sortOrder
               FROM inventory_categories
              WHERE status = "active"
              ORDER BY sort_order, category_name'
        );
        return $statement !== false ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable) {
        return app_mgs_inventory_default_categories();
    }
}

function app_mgs_inventory_upsert_category(PDO $db, string $name, string $code = '', string $description = ''): array
{
    app_mgs_ensure_schema($db);
    $name = clean_text($name);
    $code = app_mgs_inventory_normalize_category_code($code !== '' ? $code : $name);
    if ($name === '') {
        return ['ok' => false, 'reason' => 'category_name_required'];
    }

    try {
        $statement = $db->prepare(
            'INSERT INTO inventory_categories (category_code, category_name, description, sort_order)
             VALUES (:category_code, :category_name, :description, 80)
             ON DUPLICATE KEY UPDATE
                category_name = VALUES(category_name),
                description = VALUES(description),
                status = "active",
                updated_at = NOW()'
        );
        $statement->execute([
            'category_code' => $code,
            'category_name' => $name,
            'description' => clean_text($description),
        ]);

        return ['ok' => true, 'code' => $code, 'name' => $name];
    } catch (Throwable) {
        return ['ok' => false, 'reason' => 'category_save_failed'];
    }
}

function app_mgs_finance_money(mixed $value): float
{
    return round(max(0.0, (float) $value), 2);
}

function app_mgs_finance_fiscal_year(mixed $value): int
{
    $year = (int) $value;
    if ($year < 2000 || $year > 2100) {
        return (int) date('Y');
    }

    return $year;
}

function app_mgs_finance_asset_label(PDO $db, int $assetId, int $stockItemId, string $fallback = ''): string
{
    $fallback = clean_text($fallback);
    if ($assetId > 0) {
        try {
            $statement = $db->prepare(
                'SELECT ia.asset_code, ia.serial_number, isi.item_name, iam.vendor, iam.model
                   FROM inventory_assets ia
                   LEFT JOIN inventory_stock_items isi ON isi.id = ia.product_id
                   LEFT JOIN inventory_asset_models iam ON iam.id = ia.model_id
                  WHERE ia.id = :id
                  LIMIT 1'
            );
            $statement->execute(['id' => $assetId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            $parts = array_filter([
                (string) ($row['item_name'] ?? ''),
                trim((string) ($row['vendor'] ?? '') . ' ' . (string) ($row['model'] ?? '')),
                (string) ($row['asset_code'] ?? ''),
                (string) ($row['serial_number'] ?? ''),
            ], static fn (string $part): bool => trim($part) !== '');
            if ($parts !== []) {
                return mb_substr(clean_text(implode(' / ', $parts)), 0, 191);
            }
        } catch (Throwable) {
        }
    }

    if ($stockItemId > 0) {
        try {
            $statement = $db->prepare('SELECT sku, item_name FROM inventory_stock_items WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $stockItemId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            $label = trim((string) ($row['item_name'] ?? '') . ' / ' . (string) ($row['sku'] ?? ''));
            if ($label !== '/') {
                return mb_substr(clean_text($label), 0, 191);
            }
        } catch (Throwable) {
        }
    }

    return $fallback !== '' ? mb_substr($fallback, 0, 191) : 'Inventory asset';
}

function app_mgs_finance_depreciation_preview(array $input): array
{
    $cost = app_mgs_finance_money($input['acquisition_cost'] ?? $input['cost'] ?? 0);
    $rate = min(100.0, max(0.0, (float) ($input['depreciation_rate'] ?? $input['rate'] ?? 0)));
    $amount = app_mgs_finance_money($cost * ($rate / 100));
    $netBookValue = app_mgs_finance_money(max(0.0, $cost - $amount));
    $threshold = app_mgs_finance_money($input['writeoff_threshold'] ?? 0);
    $fiscalYear = app_mgs_finance_fiscal_year($input['fiscal_year'] ?? date('Y'));
    $yearClosed = $fiscalYear <= (int) date('Y');
    $allowed = $yearClosed && $netBookValue <= $threshold;
    $requestWriteoff = !empty($input['request_writeoff']) || !empty($input['writeoff_requested']);
    $status = $allowed ? 'eligible' : 'active';
    if ($requestWriteoff) {
        $status = $allowed ? 'written_off' : 'blocked';
    }

    return [
        'fiscal_year' => $fiscalYear,
        'acquisition_cost' => $cost,
        'depreciation_rate' => round($rate, 2),
        'depreciation_amount' => $amount,
        'net_book_value' => $netBookValue,
        'writeoff_threshold' => $threshold,
        'writeoff_allowed' => $allowed ? 1 : 0,
        'writeoff_status' => $status,
        'year_closed' => $yearClosed ? 1 : 0,
        'request_writeoff' => $requestWriteoff ? 1 : 0,
    ];
}

function app_mgs_save_inventory_depreciation(array $input, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'error' => 'db_not_ready'];
    }

    $assetId = (int) ($input['asset_id'] ?? 0);
    $stockItemId = (int) ($input['stock_item_id'] ?? $input['product_id'] ?? 0);
    if ($assetId <= 0 && $stockItemId <= 0) {
        return ['ok' => false, 'error' => 'asset_or_stock_required'];
    }

    $preview = app_mgs_finance_depreciation_preview($input);
    $record = [
        'asset_id' => $assetId ?: null,
        'stock_item_id' => $stockItemId ?: null,
        'asset_label' => app_mgs_finance_asset_label($db, $assetId, $stockItemId, (string) ($input['asset_label'] ?? '')),
        'fiscal_year' => (int) $preview['fiscal_year'],
        'acquisition_cost' => (float) $preview['acquisition_cost'],
        'depreciation_rate' => (float) $preview['depreciation_rate'],
        'depreciation_amount' => (float) $preview['depreciation_amount'],
        'net_book_value' => (float) $preview['net_book_value'],
        'writeoff_threshold' => (float) $preview['writeoff_threshold'],
        'writeoff_allowed' => (int) $preview['writeoff_allowed'],
        // Maker-checker (vision 19.2 "asset silinmesi"): the same actor who
        // enters the depreciation numbers can never immediately finalize a
        // writeoff -- an eligible writeoff request lands as pending_writeoff
        // and needs a different actor to call app_mgs_confirm_inventory_writeoff().
        'writeoff_status' => (string) $preview['writeoff_status'] === 'written_off' ? 'pending_writeoff' : (string) $preview['writeoff_status'],
        'note' => clean_text((string) ($input['note'] ?? $input['note_text'] ?? '')),
        'calculation_json' => app_db_json_encode(['input' => $input, 'preview' => $preview]),
        'created_by_account_id' => (int) ($actor['id'] ?? 0) ?: null,
        'created_by_name' => clean_text((string) ($actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'system')),
    ];

    try {
        $statement = $db->prepare(
            'INSERT INTO finance_asset_depreciations (
                asset_id, stock_item_id, asset_label, fiscal_year, acquisition_cost, depreciation_rate,
                depreciation_amount, net_book_value, writeoff_threshold, writeoff_allowed, writeoff_status,
                note, calculation_json, created_by_account_id, created_by_name
             ) VALUES (
                :asset_id, :stock_item_id, :asset_label, :fiscal_year, :acquisition_cost, :depreciation_rate,
                :depreciation_amount, :net_book_value, :writeoff_threshold, :writeoff_allowed, :writeoff_status,
                :note, :calculation_json, :created_by_account_id, :created_by_name
             )'
        );
        $statement->execute($record);
        $recordId = (int) $db->lastInsertId();
    } catch (Throwable $exception) {
        return ['ok' => false, 'error' => 'depreciation_save_failed', 'message' => $exception->getMessage()];
    }

    app_mgs_record_operation_action([
        'action_type' => 'finance_asset_depreciation',
        'entity_type' => 'finance',
        'entity_id' => $assetId > 0 ? ('asset:' . $assetId) : ('stock:' . $stockItemId),
        'status' => 'executed',
        'actor' => $actor,
        'summary' => 'Asset depreciation: ' . $record['asset_label'] . ' ' . $record['depreciation_rate'] . '%',
        'payload' => ['id' => $recordId, 'record' => $record, 'preview' => $preview],
    ]);

    return ['ok' => true, 'id' => $recordId, 'record' => $record, 'preview' => $preview];
}

// Maker-checker confirm step (vision 19.2 "asset silinmesi"): a DIFFERENT
// admin than the one who entered the depreciation numbers must confirm a
// pending_writeoff before the asset is actually marked written_off.
function app_mgs_confirm_inventory_writeoff(PDO $db, int $depreciationId, array $actor): array
{
    if (!app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'error' => 'db_not_ready'];
    }
    if (!app_portal_is_admin($actor)) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }
    $actorId = (int) ($actor['id'] ?? 0);

    try {
        $db->beginTransaction();
        $statement = $db->prepare('SELECT * FROM finance_asset_depreciations WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $depreciationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'depreciation_record_not_found'];
        }
        if ((string) $row['writeoff_status'] !== 'pending_writeoff') {
            $db->rollBack();
            return ['ok' => false, 'error' => 'not_pending_writeoff'];
        }
        if ($actorId > 0 && $actorId === (int) $row['created_by_account_id']) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'creator_cannot_confirm'];
        }

        $db->prepare(
            "UPDATE finance_asset_depreciations
                SET writeoff_status = 'written_off', confirmed_by_account_id = :actor_id,
                    confirmed_by_name = :actor_name, confirmed_at = NOW()
              WHERE id = :id"
        )->execute([
            'actor_id' => $actorId ?: null,
            'actor_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'admin')),
            'id' => $depreciationId,
        ]);

        $assetId = (int) ($row['asset_id'] ?? 0);
        if ($assetId > 0) {
            $db->prepare(
                'UPDATE inventory_assets
                    SET lifecycle_status = "written_off",
                        condition_status = "written_off",
                        updated_at = NOW()
                  WHERE id = :id'
            )->execute(['id' => $assetId]);
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'writeoff_confirm_failed', 'message' => $exception->getMessage()];
    }

    app_mgs_record_operation_action([
        'action_type' => 'finance_asset_writeoff_confirmed',
        'entity_type' => 'finance',
        'entity_id' => (string) $depreciationId,
        'status' => 'executed',
        'actor' => $actor,
        'summary' => 'Asset writeoff confirmed: ' . (string) ($row['asset_label'] ?? ''),
        'payload' => ['id' => $depreciationId, 'asset_id' => $row['asset_id'] ?? null],
    ]);

    return ['ok' => true, 'id' => $depreciationId];
}

function app_mgs_finance_depreciation_rows(?PDO $db = null, int $limit = 20): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }

    try {
        $statement = $db->prepare(
            'SELECT *
               FROM finance_asset_depreciations
              ORDER BY fiscal_year DESC, created_at DESC, id DESC
              LIMIT ' . max(1, min(100, $limit))
        );
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

function app_mgs_finance_printable_inventory_rows(?PDO $db = null, int $limit = 100): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }

    try {
        $statement = $db->prepare(
            'SELECT ia.id AS asset_id,
                    ia.asset_code,
                    ia.serial_number,
                    ia.mac_address,
                    ia.ownership_type,
                    ia.lifecycle_status,
                    ia.condition_status,
                    ia.current_location_type,
                    ia.current_location_id,
                    isi.sku,
                    isi.item_name,
                    isi.category,
                    iam.vendor,
                    iam.model,
                    COALESCE(fd.net_book_value, 0) AS net_book_value,
                    COALESCE(fd.writeoff_status, "") AS writeoff_status
               FROM inventory_assets ia
               LEFT JOIN inventory_stock_items isi ON isi.id = ia.product_id
               LEFT JOIN inventory_asset_models iam ON iam.id = ia.model_id
               LEFT JOIN (
                    SELECT d1.*
                      FROM finance_asset_depreciations d1
                      JOIN (
                            SELECT asset_id, MAX(id) AS id
                              FROM finance_asset_depreciations
                             WHERE asset_id IS NOT NULL
                             GROUP BY asset_id
                      ) latest ON latest.id = d1.id
               ) fd ON fd.asset_id = ia.id
              ORDER BY ia.updated_at DESC, ia.id DESC
              LIMIT ' . max(1, min(500, $limit))
        );
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

function app_mgs_finance_employee_property_rows(?PDO $db = null, string $employeeRef = '', int $limit = 100): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }

    $employeeRef = clean_text($employeeRef);
    $where = 'ia.current_location_type IN ("technician","employee")';
    $params = [];
    if ($employeeRef !== '') {
        $where .= ' AND ia.current_location_id = :employee_ref';
        $params['employee_ref'] = $employeeRef;
    }

    try {
        $statement = $db->prepare(
            'SELECT ia.id AS asset_id,
                    ia.asset_code,
                    ia.serial_number,
                    ia.mac_address,
                    ia.current_location_id AS employee_ref,
                    ia.lifecycle_status,
                    ia.condition_status,
                    isi.sku,
                    isi.item_name,
                    isi.category,
                    iam.vendor,
                    iam.model
               FROM inventory_assets ia
               LEFT JOIN inventory_stock_items isi ON isi.id = ia.product_id
               LEFT JOIN inventory_asset_models iam ON iam.id = ia.model_id
              WHERE ' . $where . '
              ORDER BY ia.current_location_id, ia.updated_at DESC, ia.id DESC
              LIMIT ' . max(1, min(500, $limit))
        );
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

function app_mgs_save_finance_debt_record(array $input, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'error' => 'db_not_ready'];
    }

    $ledgerType = clean_text((string) ($input['ledger_type'] ?? 'receivable'));
    if (!in_array($ledgerType, ['receivable', 'payable'], true)) {
        return ['ok' => false, 'error' => 'ledger_type_invalid'];
    }

    $counterpartyName = clean_text((string) ($input['counterparty_name'] ?? ''));
    if ($counterpartyName === '') {
        return ['ok' => false, 'error' => 'counterparty_required'];
    }

    $amount = app_mgs_finance_money($input['amount'] ?? $input['amount_original'] ?? 0);
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'amount_required'];
    }

    $dueDate = app_mgs_hr_date_value($input['due_date'] ?? null);
    $status = app_mgs_hr_status((string) ($input['status'] ?? 'open'), ['open', 'partial', 'closed', 'void'], 'open');
    $record = [
        'ledger_type' => $ledgerType,
        'counterparty_name' => $counterpartyName,
        'counterparty_type' => clean_text((string) ($input['counterparty_type'] ?? 'other')) ?: 'other',
        'amount_original' => $amount,
        'amount_open' => app_mgs_finance_money($input['amount_open'] ?? ($status === 'closed' ? 0 : $amount)),
        'currency' => strtoupper(substr(clean_text((string) ($input['currency'] ?? 'AZN')), 0, 3)) ?: 'AZN',
        'document_no' => clean_text((string) ($input['document_no'] ?? $input['e_invoice_no'] ?? '')) ?: null,
        'due_date' => $dueDate,
        'status' => $status,
        'note' => clean_text((string) ($input['note'] ?? $input['note_text'] ?? '')),
        'payload_json' => app_db_json_encode(['input' => $input]),
        'created_by_account_id' => (int) ($actor['id'] ?? 0) ?: null,
        'created_by_name' => clean_text((string) ($actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'system')),
    ];

    try {
        $statement = $db->prepare(
            'INSERT INTO finance_debt_ledger (
                ledger_type, counterparty_name, counterparty_type, amount_original, amount_open,
                currency, document_no, due_date, status, note, payload_json, created_by_account_id, created_by_name
             ) VALUES (
                :ledger_type, :counterparty_name, :counterparty_type, :amount_original, :amount_open,
                :currency, :document_no, :due_date, :status, :note, :payload_json, :created_by_account_id, :created_by_name
             )'
        );
        $statement->execute($record);
        $id = (int) $db->lastInsertId();
    } catch (Throwable $exception) {
        return ['ok' => false, 'error' => 'debt_save_failed', 'message' => $exception->getMessage()];
    }

    app_mgs_record_operation_action([
        'action_type' => 'finance_debt_record',
        'entity_type' => 'finance',
        'entity_id' => (string) ($record['document_no'] ?? ($ledgerType . ':' . $id)),
        'status' => 'executed',
        'actor' => $actor,
        'summary' => 'Finance debt ledger: ' . $ledgerType . ' ' . $counterpartyName,
        'payload' => ['id' => $id, 'record' => $record],
    ]);

    return ['ok' => true, 'id' => $id, 'record' => $record];
}

function app_mgs_finance_debt_rows(?PDO $db = null, string $ledgerType = 'all', int $limit = 20): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }

    $ledgerType = clean_text($ledgerType);
    $where = '';
    $params = [];
    if (in_array($ledgerType, ['receivable', 'payable'], true)) {
        $where = ' WHERE ledger_type = :ledger_type';
        $params['ledger_type'] = $ledgerType;
    }

    try {
        $statement = $db->prepare(
            'SELECT *
               FROM finance_debt_ledger' . $where . '
              ORDER BY FIELD(status, "open", "partial", "closed", "void"), COALESCE(due_date, created_at), id DESC
              LIMIT ' . max(1, min(100, $limit))
        );
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

function app_mgs_finance_debt_summary(?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    $summary = [
        'receivable_open' => 0.0,
        'payable_open' => 0.0,
        'net_open' => 0.0,
        'open_count' => 0,
    ];
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return $summary;
    }

    try {
        $statement = $db->query(
            'SELECT ledger_type, COUNT(*) AS count_rows, COALESCE(SUM(amount_open),0) AS total_open
               FROM finance_debt_ledger
              WHERE status IN ("open","partial")
              GROUP BY ledger_type'
        );
        foreach ($statement !== false ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
            $type = (string) ($row['ledger_type'] ?? '');
            $amount = app_mgs_finance_money($row['total_open'] ?? 0);
            if ($type === 'receivable') {
                $summary['receivable_open'] = $amount;
            } elseif ($type === 'payable') {
                $summary['payable_open'] = $amount;
            }
            $summary['open_count'] += (int) ($row['count_rows'] ?? 0);
        }
        $summary['net_open'] = round($summary['receivable_open'] - $summary['payable_open'], 2);
    } catch (Throwable) {
    }

    return $summary;
}

function app_mgs_record_finance_debt_payment(int $debtId, array $input, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'error' => 'db_not_ready'];
    }

    if ($debtId <= 0) {
        return ['ok' => false, 'error' => 'debt_id_required'];
    }

    $paymentAmount = app_mgs_finance_money($input['payment_amount'] ?? $input['amount'] ?? 0);
    if ($paymentAmount <= 0) {
        return ['ok' => false, 'error' => 'payment_amount_required'];
    }

    try {
        $db->beginTransaction();
        $debtStatement = $db->prepare('SELECT * FROM finance_debt_ledger WHERE id = :id LIMIT 1 FOR UPDATE');
        $debtStatement->execute(['id' => $debtId]);
        $debt = $debtStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($debt)) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'debt_not_found'];
        }

        $status = (string) ($debt['status'] ?? '');
        if (in_array($status, ['closed', 'void'], true)) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'debt_not_open'];
        }

        $openBefore = app_mgs_finance_money($debt['amount_open'] ?? 0);
        if ($openBefore <= 0) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'debt_balance_empty'];
        }

        $appliedAmount = app_mgs_finance_money(min($paymentAmount, $openBefore));
        $openAfter = app_mgs_finance_money(max(0.0, $openBefore - $appliedAmount));
        $newStatus = $openAfter <= 0.0 ? 'closed' : 'partial';
        $paymentDate = app_mgs_hr_date_value($input['payment_date'] ?? null);
        $paymentRecord = [
            'debt_id' => $debtId,
            'payment_amount' => $appliedAmount,
            'payment_date' => $paymentDate,
            'payment_method' => clean_text((string) ($input['payment_method'] ?? 'manual')) ?: 'manual',
            'reference_no' => clean_text((string) ($input['reference_no'] ?? '')) ?: null,
            'note' => clean_text((string) ($input['note'] ?? $input['note_text'] ?? '')),
            'created_by_account_id' => (int) ($actor['id'] ?? 0) ?: null,
            'created_by_name' => clean_text((string) ($actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'system')),
        ];

        $paymentStatement = $db->prepare(
            'INSERT INTO finance_debt_payments (
                debt_id, payment_amount, payment_date, payment_method, reference_no, note,
                created_by_account_id, created_by_name
             ) VALUES (
                :debt_id, :payment_amount, :payment_date, :payment_method, :reference_no, :note,
                :created_by_account_id, :created_by_name
             )'
        );
        $paymentStatement->execute($paymentRecord);
        $paymentId = (int) $db->lastInsertId();

        $updateStatement = $db->prepare(
            'UPDATE finance_debt_ledger
                SET amount_open = :amount_open,
                    status = :status,
                    updated_at = NOW()
              WHERE id = :id'
        );
        $updateStatement->execute([
            'amount_open' => $openAfter,
            'status' => $newStatus,
            'id' => $debtId,
        ]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'payment_save_failed', 'message' => $exception->getMessage()];
    }

    app_mgs_record_operation_action([
        'action_type' => 'finance_debt_payment',
        'entity_type' => 'finance',
        'entity_id' => (string) ($debt['document_no'] ?? ('debt:' . $debtId)),
        'status' => 'executed',
        'actor' => $actor,
        'summary' => 'Finance debt payment: ' . (string) ($debt['counterparty_name'] ?? '-') . ' ' . $appliedAmount,
        'payload' => [
            'id' => $paymentId,
            'debt_id' => $debtId,
            'payment' => $paymentRecord,
            'open_before' => $openBefore,
            'open_after' => $openAfter,
            'debt_status' => $newStatus,
        ],
    ]);

    return [
        'ok' => true,
        'id' => $paymentId,
        'record' => $paymentRecord,
        'debt_id' => $debtId,
        'amount_open' => $openAfter,
        'status' => $newStatus,
    ];
}

// Maker-checker (vision 19.2 "borc silinməsi" -- this feature did not exist
// before). Forgiving/writing off a debt is never applied in the same call
// that requests it: it always stages into finance_debt_writeoff_requests,
// and only a DIFFERENT admin approving it actually reduces amount_open (via
// the same finance_debt_payments ledger app_mgs_record_finance_debt_payment
// uses, tagged payment_method="writeoff" so it is never mistaken for cash
// received).
function app_mgs_request_finance_debt_writeoff(PDO $db, int $debtId, float $amount, string $reason, array $actor = []): array
{
    if (!app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'error' => 'db_not_ready'];
    }
    $reason = mb_substr(clean_text($reason), 0, 255);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'reason_required'];
    }
    $debtStatement = $db->prepare('SELECT * FROM finance_debt_ledger WHERE id = :id LIMIT 1');
    $debtStatement->execute(['id' => $debtId]);
    $debt = $debtStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($debt)) {
        return ['ok' => false, 'error' => 'debt_not_found'];
    }
    if (in_array((string) ($debt['status'] ?? ''), ['closed', 'void'], true)) {
        return ['ok' => false, 'error' => 'debt_not_open'];
    }
    $openBalance = app_mgs_finance_money($debt['amount_open'] ?? 0);
    $requestedAmount = $amount > 0 ? app_mgs_finance_money(min($amount, $openBalance)) : $openBalance;
    if ($requestedAmount <= 0) {
        return ['ok' => false, 'error' => 'debt_balance_empty'];
    }

    $insert = $db->prepare(
        'INSERT INTO finance_debt_writeoff_requests (debt_id, requested_amount, reason, status, requested_by_account_id, requested_by_name)
         VALUES (:debt_id, :requested_amount, :reason, "pending", :actor_id, :actor_name)'
    );
    $insert->execute([
        'debt_id' => $debtId,
        'requested_amount' => $requestedAmount,
        'reason' => $reason,
        'actor_id' => (int) ($actor['id'] ?? 0) ?: null,
        'actor_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'finance')),
    ]);
    $id = (int) $db->lastInsertId();

    app_mgs_record_operation_action([
        'action_type' => 'finance_debt_writeoff_requested',
        'entity_type' => 'finance',
        'entity_id' => (string) ($debt['document_no'] ?? ('debt:' . $debtId)),
        'status' => 'pending',
        'actor' => $actor,
        'summary' => 'Debt writeoff requested: ' . (string) ($debt['counterparty_name'] ?? '-') . ' ' . $requestedAmount,
        'payload' => ['id' => $id, 'debt_id' => $debtId, 'amount' => $requestedAmount, 'reason' => $reason],
    ]);

    return ['ok' => true, 'id' => $id, 'requested_amount' => $requestedAmount];
}

function app_mgs_finance_debt_writeoff_requests(PDO $db, string $status = 'pending'): array
{
    if (!app_mgs_ensure_schema($db)) {
        return [];
    }
    $statement = $db->prepare(
        'SELECT r.*, d.counterparty_name, d.ledger_type, d.amount_open, d.currency, d.document_no
           FROM finance_debt_writeoff_requests r
           LEFT JOIN finance_debt_ledger d ON d.id = r.debt_id
          WHERE r.status = :status
          ORDER BY r.created_at DESC LIMIT 100'
    );
    $statement->execute(['status' => $status]);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function app_mgs_resolve_finance_debt_writeoff(PDO $db, int $requestId, string $decision, string $resolutionNote, array $actor): array
{
    if (!app_portal_is_admin($actor)) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        return ['ok' => false, 'error' => 'invalid_decision'];
    }
    if (!app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'error' => 'db_not_ready'];
    }
    $resolutionNote = mb_substr(clean_text($resolutionNote), 0, 255);
    $actorId = (int) ($actor['id'] ?? 0);

    $requestStatement = $db->prepare('SELECT * FROM finance_debt_writeoff_requests WHERE id = :id LIMIT 1');
    $requestStatement->execute(['id' => $requestId]);
    $request = $requestStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($request)) {
        return ['ok' => false, 'error' => 'request_not_found'];
    }
    if ((string) $request['status'] !== 'pending') {
        return ['ok' => false, 'error' => 'request_already_resolved'];
    }
    if ($actorId > 0 && $actorId === (int) ($request['requested_by_account_id'] ?? 0)) {
        return ['ok' => false, 'error' => 'requester_cannot_approve_own_request'];
    }

    $paymentId = null;
    if ($decision === 'approved') {
        $applied = app_mgs_record_finance_debt_payment((int) $request['debt_id'], [
            'payment_amount' => (float) $request['requested_amount'],
            'payment_method' => 'writeoff',
            'reference_no' => 'WRITEOFF-REQ-' . $requestId,
            'note' => 'Debt writeoff approved: ' . $resolutionNote,
        ], $actor, $db);
        if (empty($applied['ok'])) {
            return ['ok' => false, 'error' => 'writeoff_apply_failed', 'detail' => (string) ($applied['error'] ?? '')];
        }
        $paymentId = (int) ($applied['id'] ?? 0) ?: null;
    }

    try {
        $db->beginTransaction();
        $update = $db->prepare(
            "UPDATE finance_debt_writeoff_requests SET status = :status, reviewed_by_account_id = :actor_id,
                reviewed_by_name = :actor_name, resolution_note = :resolution_note, debt_payment_id = :payment_id, reviewed_at = NOW()
             WHERE id = :id AND status = 'pending'"
        );
        $update->execute([
            'status' => $decision,
            'actor_id' => $actorId ?: null,
            'actor_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'admin')),
            'resolution_note' => $resolutionNote ?: null,
            'payment_id' => $paymentId,
            'id' => $requestId,
        ]);
        if ($update->rowCount() === 0) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'request_already_resolved'];
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'request_resolve_failed', 'message' => $exception->getMessage()];
    }

    app_mgs_record_operation_action([
        'action_type' => 'finance_debt_writeoff_' . $decision,
        'entity_type' => 'finance',
        'entity_id' => 'debt:' . (string) $request['debt_id'],
        'status' => 'executed',
        'actor' => $actor,
        'summary' => 'Debt writeoff request #' . $requestId . ' ' . $decision,
        'payload' => ['id' => $requestId, 'decision' => $decision, 'debt_payment_id' => $paymentId],
    ]);

    return ['ok' => true, 'status' => $decision, 'debt_payment_id' => $paymentId];
}

function app_mgs_finance_debt_payment_rows(?PDO $db = null, int $limit = 20): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }

    try {
        $statement = $db->prepare(
            'SELECT p.*,
                    d.ledger_type,
                    d.counterparty_name,
                    d.counterparty_type,
                    d.document_no
               FROM finance_debt_payments p
               LEFT JOIN finance_debt_ledger d ON d.id = p.debt_id
              ORDER BY p.created_at DESC, p.id DESC
              LIMIT ' . max(1, min(100, $limit))
        );
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

function app_mgs_parse_e_invoice_rows(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
    if ($lines === []) {
        return [];
    }

    $firstLine = (string) $lines[0];
    $delimiter = str_contains($firstLine, ';') ? ';' : (str_contains($firstLine, "\t") ? "\t" : ',');
    $headers = array_map(
        static fn (string $value): string => strtolower(trim(preg_replace('/[^a-zA-Z0-9_]+/', '_', $value) ?? '', '_')),
        str_getcsv($firstLine, $delimiter)
    );
    $rows = [];

    foreach (array_slice($lines, 1) as $line) {
        $values = str_getcsv((string) $line, $delimiter);
        $row = [];
        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }
            $row[$header] = trim((string) ($values[$index] ?? ''));
        }

        $rawLedgerType = clean_text((string) ($row['ledger_type'] ?? $row['type'] ?? ''));
        $ledgerType = $rawLedgerType === '' ? 'payable' : $rawLedgerType;
        if (!in_array($ledgerType, ['receivable', 'payable'], true)) {
            continue;
        }
        $amount = app_mgs_finance_money(str_replace(',', '.', (string) ($row['amount'] ?? $row['total'] ?? 0)));
        $counterpartyName = clean_text((string) ($row['counterparty_name'] ?? $row['supplier_name'] ?? $row['customer_name'] ?? ''));
        $documentNo = clean_text((string) ($row['document_no'] ?? $row['invoice_no'] ?? $row['e_invoice_no'] ?? ''));
        if ($amount <= 0 || $counterpartyName === '' || $documentNo === '') {
            continue;
        }

        $rows[] = [
            'ledger_type' => $ledgerType,
            'document_no' => $documentNo,
            'counterparty_name' => $counterpartyName,
            'counterparty_type' => clean_text((string) ($row['counterparty_type'] ?? ($ledgerType === 'payable' ? 'supplier' : 'customer'))) ?: 'other',
            'amount' => $amount,
            'due_date' => app_mgs_hr_date_value($row['due_date'] ?? null),
            'note' => clean_text((string) ($row['note'] ?? '')),
            'raw' => $row,
        ];
    }

    return $rows;
}

function app_mgs_import_finance_e_invoice_rows(string|array $rows, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO) {
        return ['ok' => false, 'error' => 'db_not_ready'];
    }
    if (!function_exists('app_finance_post_source_entry')) {
        require_once __DIR__ . '/finance-operations-lib.php';
    }
    if (!function_exists('app_finance_post_source_entry')
        || !function_exists('app_finance_stage1_schema_ready')
        || !app_finance_stage1_schema_ready($db)) {
        return ['ok' => false, 'error' => 'stage1_finance_schema_not_migrated'];
    }
    if (!app_stage1_feature_enabled($db, 'stage1_e_invoice_atomic_import')) {
        return ['ok' => false, 'error' => 'stage1_e_invoice_atomic_import_disabled'];
    }
    if (!app_finance_actor_can_write($actor)) {
        return ['ok' => false, 'error' => 'forbidden'];
    }

    $parsedRows = is_string($rows) ? app_mgs_parse_e_invoice_rows($rows) : array_values($rows);
    if ($parsedRows === []) {
        return ['ok' => false, 'error' => 'rows_empty'];
    }
    if (is_string($rows)) {
        $inputLines = preg_split('/\r\n|\r|\n/', trim($rows)) ?: [];
        $inputLines = array_values(array_filter($inputLines, static fn (string $line): bool => trim($line) !== ''));
        if (max(0, count($inputLines) - 1) !== count($parsedRows)) {
            return ['ok' => false, 'error' => 'rows_invalid'];
        }
    }

    $normalizedRows = [];
    foreach ($parsedRows as $row) {
        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'rows_invalid'];
        }
        $ledgerType = strtolower(clean_text((string) ($row['ledger_type'] ?? 'payable')));
        $documentNo = clean_text((string) ($row['document_no'] ?? ''));
        $counterpartyName = clean_text((string) ($row['counterparty_name'] ?? ''));
        $counterpartyType = clean_text((string) ($row['counterparty_type'] ?? 'other')) ?: 'other';
        $amount = app_mgs_finance_money($row['amount'] ?? 0);
        $dueDate = app_mgs_hr_date_value($row['due_date'] ?? null);
        if (!in_array($ledgerType, ['receivable', 'payable'], true)
            || $documentNo === '' || $counterpartyName === '' || $amount <= 0) {
            return ['ok' => false, 'error' => 'rows_invalid'];
        }
        $normalized = [
            'ledger_type' => $ledgerType,
            'document_no' => $documentNo,
            'counterparty_name' => $counterpartyName,
            'counterparty_type' => $counterpartyType,
            'amount' => $amount,
            'due_date' => $dueDate,
            'note' => clean_text((string) ($row['note'] ?? 'E-qaime import')),
        ];
        $normalized['document_key'] = hash(
            'sha256',
            implode('|', [
                $ledgerType,
                mb_strtolower(trim($counterpartyName), 'UTF-8'),
                mb_strtolower(trim($documentNo), 'UTF-8'),
            ])
        );
        $normalized['row_hash'] = hash('sha256', app_db_json_encode($normalized));
        $normalizedRows[] = $normalized;
    }

    $documentKeys = array_column($normalizedRows, 'document_key');
    if (count($documentKeys) !== count(array_unique($documentKeys))) {
        return ['ok' => false, 'error' => 'duplicate_document_in_batch'];
    }

    $requestHash = hash('sha256', app_db_json_encode($normalizedRows));
    $batchId = 'EINV-' . strtoupper(substr($requestHash, 0, 24));
    $correlationId = app_request_correlation_id();
    $actorName = clean_text((string) ($actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'system'));
    try {
        $db->beginTransaction();
        $insertBatch = $db->prepare(
            'INSERT INTO finance_e_invoice_batches (
                batch_key,request_hash,correlation_id,status,input_row_count,created_by_account_id,created_by_name
             ) VALUES (
                :batch_key,:request_hash,:correlation_id,"processing",:input_row_count,:actor_id,:actor_name
             )
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $insertBatch->execute([
            'batch_key' => $batchId,
            'request_hash' => $requestHash,
            'correlation_id' => $correlationId,
            'input_row_count' => count($normalizedRows),
            'actor_id' => (int) ($actor['id'] ?? 0) ?: null,
            'actor_name' => $actorName,
        ]);
        $newBatch = $insertBatch->rowCount() === 1;
        $batch = $db->prepare('SELECT * FROM finance_e_invoice_batches WHERE request_hash = :request_hash FOR UPDATE');
        $batch->execute(['request_hash' => $requestHash]);
        $batchRow = $batch->fetch(PDO::FETCH_ASSOC);
        if (!is_array($batchRow)) {
            throw new RuntimeException('batch_registry_failed');
        }
        $batchId = (string) $batchRow['batch_key'];
        if (!$newBatch && (string) $batchRow['status'] === 'completed') {
            $existing = $db->prepare(
                'SELECT debt_id FROM finance_e_invoice_imports
                  WHERE import_batch = :batch_key ORDER BY id'
            );
            $existing->execute(['batch_key' => $batchId]);
            $debtIds = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $db->commit();
            return [
                'ok' => true,
                'batch_id' => $batchId,
                'imported' => (int) $batchRow['imported_row_count'],
                'debt_ids' => $debtIds,
                'existing' => true,
            ];
        }
        if (!$newBatch && (string) $batchRow['status'] === 'processing') {
            $db->commit();
            return ['ok' => false, 'error' => 'import_in_progress', 'batch_id' => $batchId];
        }
        $db->prepare(
            'UPDATE finance_e_invoice_batches
                SET status = "processing", error_code = NULL, correlation_id = :correlation_id,
                    input_row_count = :input_row_count, imported_row_count = 0
              WHERE id = :id'
        )->execute([
            'correlation_id' => $correlationId,
            'input_row_count' => count($normalizedRows),
            'id' => (int) $batchRow['id'],
        ]);
        $db->commit();
    } catch (Throwable) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'batch_registry_failed'];
    }

    $debtIds = [];
    try {
        $db->beginTransaction();
        foreach ($normalizedRows as $row) {
            $existingDocument = $db->prepare(
                'SELECT id,row_hash FROM finance_e_invoice_imports
                  WHERE document_key = :document_key LIMIT 1 FOR UPDATE'
            );
            $existingDocument->execute(['document_key' => $row['document_key']]);
            if (is_array($existingDocument->fetch(PDO::FETCH_ASSOC))) {
                throw new RuntimeException('document_already_imported');
            }

            $entry = app_finance_post_source_entry($db, [
                'entry_type' => $row['ledger_type'] === 'payable' ? 'expense' : 'income',
                'title' => 'E-qaime ' . $row['document_no'] . ': ' . $row['counterparty_name'],
                'category' => 'e_invoice',
                'amount' => $row['amount'],
                'currency' => 'AZN',
                'transaction_date' => $row['due_date'] ?? date('Y-m-d'),
                'payment_source' => 'e_invoice',
                'reference_no' => 'EINV-' . strtoupper(substr($row['document_key'], 0, 24)),
                'note' => $row['note'],
                'source_type' => 'e_invoice',
                'source_id' => $row['document_key'],
                'idempotency_key' => 'e_invoice:' . $row['document_key'],
                'correlation_id' => $correlationId,
                'payload' => $row,
            ], $actor);
            if (empty($entry['ok']) || !empty($entry['existing'])) {
                throw new RuntimeException((string) ($entry['error'] ?? 'document_already_posted'));
            }

            $debt = $db->prepare(
                'INSERT INTO finance_debt_ledger (
                    ledger_type,counterparty_name,counterparty_type,amount_original,amount_open,
                    currency,document_no,due_date,status,note,payload_json,source_document_key,
                    finance_entry_id,correlation_id,created_by_account_id,created_by_name
                 ) VALUES (
                    :ledger_type,:counterparty_name,:counterparty_type,:amount,:amount,
                    "AZN",:document_no,:due_date,"open",:note,:payload_json,:document_key,
                    :finance_entry_id,:correlation_id,:actor_id,:actor_name
                 )'
            );
            $debt->execute([
                'ledger_type' => $row['ledger_type'],
                'counterparty_name' => $row['counterparty_name'],
                'counterparty_type' => $row['counterparty_type'],
                'amount' => $row['amount'],
                'document_no' => $row['document_no'],
                'due_date' => $row['due_date'],
                'note' => $row['note'],
                'payload_json' => app_db_json_encode(['source' => 'e_invoice', 'batch_id' => $batchId, 'row' => $row]),
                'document_key' => $row['document_key'],
                'finance_entry_id' => (int) $entry['id'],
                'correlation_id' => $correlationId,
                'actor_id' => (int) ($actor['id'] ?? 0) ?: null,
                'actor_name' => $actorName,
            ]);
            $debtId = (int) $db->lastInsertId();

            $import = $db->prepare(
                'INSERT INTO finance_e_invoice_imports (
                    import_batch,row_hash,document_key,finance_entry_id,correlation_id,debt_id,
                    ledger_type,document_no,counterparty_name,counterparty_type,amount,due_date,
                    status,error_code,row_json,created_by_account_id,created_by_name
                 ) VALUES (
                    :import_batch,:row_hash,:document_key,:finance_entry_id,:correlation_id,:debt_id,
                    :ledger_type,:document_no,:counterparty_name,:counterparty_type,:amount,:due_date,
                    "imported",NULL,:row_json,:actor_id,:actor_name
                 )'
            );
            $import->execute([
                'import_batch' => $batchId,
                'row_hash' => $row['row_hash'],
                'document_key' => $row['document_key'],
                'finance_entry_id' => (int) $entry['id'],
                'correlation_id' => $correlationId,
                'debt_id' => $debtId,
                'ledger_type' => $row['ledger_type'],
                'document_no' => $row['document_no'],
                'counterparty_name' => $row['counterparty_name'],
                'counterparty_type' => $row['counterparty_type'],
                'amount' => $row['amount'],
                'due_date' => $row['due_date'],
                'row_json' => app_db_json_encode($row),
                'actor_id' => (int) ($actor['id'] ?? 0) ?: null,
                'actor_name' => $actorName,
            ]);
            $debtIds[] = $debtId;
        }

        $db->prepare(
            'UPDATE finance_e_invoice_batches
                SET status = "completed", imported_row_count = :imported, error_code = NULL
              WHERE batch_key = :batch_key'
        )->execute(['imported' => count($debtIds), 'batch_key' => $batchId]);
        app_finance_record_audit(
            $db,
            'finance_e_invoice_imported',
            $batchId,
            $actor,
            'Finance e-qaime import: ' . count($debtIds) . ' setir',
            [
                'batch_id' => $batchId,
                'request_hash' => $requestHash,
                'correlation_id' => $correlationId,
                'imported' => count($debtIds),
                'debt_ids' => $debtIds,
            ],
            '',
            null,
            null,
            'import'
        );
        $db->commit();
        return [
            'ok' => true,
            'batch_id' => $batchId,
            'imported' => count($debtIds),
            'debt_ids' => $debtIds,
            'existing' => false,
        ];
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        try {
            $failed = $db->prepare(
                'UPDATE finance_e_invoice_batches
                    SET status = "failed", error_code = :error_code, imported_row_count = 0
                  WHERE batch_key = :batch_key'
            );
            $failed->execute([
                'error_code' => mb_substr($exception->getMessage(), 0, 96),
                'batch_key' => $batchId,
            ]);
        } catch (Throwable) {
        }
        return [
            'ok' => false,
            'error' => 'e_invoice_atomic_import_failed',
            'batch_id' => $batchId,
            'correlation_id' => $correlationId,
        ];
    }
}

function app_mgs_finance_e_invoice_import_rows(?PDO $db = null, int $limit = 20): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }

    try {
        $statement = $db->prepare(
            'SELECT *
               FROM finance_e_invoice_imports
              ORDER BY created_at DESC, id DESC
              LIMIT ' . max(1, min(100, $limit))
        );
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

function app_mgs_export_finance_e_invoice_csv(?PDO $db = null, string $ledgerType = 'all'): string
{
    $rows = app_mgs_finance_debt_rows($db, $ledgerType, 500);
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        return '';
    }

    fputcsv($handle, ['document_no', 'counterparty_name', 'ledger_type', 'amount_original', 'amount_open', 'status']);
    foreach ($rows as $row) {
        fputcsv($handle, [
            (string) ($row['document_no'] ?? ''),
            (string) ($row['counterparty_name'] ?? ''),
            (string) ($row['ledger_type'] ?? ''),
            number_format((float) ($row['amount_original'] ?? 0), 2, '.', ''),
            number_format((float) ($row['amount_open'] ?? 0), 2, '.', ''),
            (string) ($row['status'] ?? ''),
        ]);
    }

    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    return is_string($csv) ? $csv : '';
}

function app_mgs_ensure_schema_columns(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $columns = [
        'last_successful_ping_at' => 'ALTER TABLE device_learning_profiles ADD COLUMN last_successful_ping_at DATETIME DEFAULT NULL AFTER status',
        'last_successful_ssh_at' => 'ALTER TABLE device_learning_profiles ADD COLUMN last_successful_ssh_at DATETIME DEFAULT NULL AFTER last_successful_ping_at',
        'last_successful_ssh_auth_at' => 'ALTER TABLE device_learning_profiles ADD COLUMN last_successful_ssh_auth_at DATETIME DEFAULT NULL AFTER last_successful_ssh_at',
        'last_successful_snmp_at' => 'ALTER TABLE device_learning_profiles ADD COLUMN last_successful_snmp_at DATETIME DEFAULT NULL AFTER last_successful_ssh_at',
        'last_probe_at' => 'ALTER TABLE device_learning_profiles ADD COLUMN last_probe_at DATETIME DEFAULT NULL AFTER last_successful_snmp_at',
        'last_probe_error' => 'ALTER TABLE device_learning_profiles ADD COLUMN last_probe_error VARCHAR(255) DEFAULT NULL AFTER last_probe_at',
        'last_probe_method' => 'ALTER TABLE device_learning_profiles ADD COLUMN last_probe_method VARCHAR(64) DEFAULT NULL AFTER last_probe_error',
        'service_probe_json' => 'ALTER TABLE device_learning_profiles ADD COLUMN service_probe_json LONGTEXT DEFAULT NULL AFTER last_probe_method',
    ];

    foreach ($columns as $column => $sql) {
        try {
            $check = $db->prepare(
                'SELECT COUNT(*)
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = "device_learning_profiles"
                   AND COLUMN_NAME = :column'
            );
            $check->execute(['column' => $column]);
            if ((int) $check->fetchColumn() === 0) {
                $db->exec($sql);
            }
        } catch (Throwable) {
        }
    }

    try {
        $check = $db->query(
            'SELECT COLUMN_TYPE
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = "device_config_backups"
                AND COLUMN_NAME = "backup_type"
              LIMIT 1'
        );
        $columnType = (string) ($check !== false ? ($check->fetchColumn() ?: '') : '');
        if ($columnType !== '' && stripos($columnType, 'cli_discovery_snapshot') === false) {
            $db->exec(
                'ALTER TABLE device_config_backups
                 MODIFY COLUMN backup_type ENUM(
                    "running_config",
                    "startup_config",
                    "inventory_snapshot",
                    "profile_snapshot",
                    "learned_profile",
                    "cli_discovery_snapshot",
                    "test"
                 ) NOT NULL DEFAULT "profile_snapshot"'
            );
        }
    } catch (Throwable) {
    }

    $done = true;
}

function app_mgs_telegram_bot_definitions(): array
{
    return [
        'network' => [
            'display_name' => 'Network alarm bot',
            'purpose' => 'Sebeke alarm, root cause, optik trend ve NOC xeberdarligi.',
            'token_key' => 'telegram_network_bot_token',
            'chat_key' => 'telegram_network_chat_id',
            'fallback_generic_token' => true,
        ],
        'customer' => [
            'display_name' => 'Customer ticket bot',
            'purpose' => 'Musteri muracieti, ONT xeberdarligi ve servis status mesajlari.',
            'token_key' => 'telegram_customer_bot_token',
            'chat_key' => 'telegram_customer_chat_id',
            'fallback_generic_token' => true,
        ],
        'finance' => [
            'display_name' => 'Financial report bot',
            'purpose' => 'Gunluk/hefdelik maliyye hesabatlari.',
            'token_key' => 'telegram_finance_bot_token',
            'chat_key' => 'telegram_finance_chat_id',
            'fallback_generic_token' => true,
        ],
        'warehouse' => [
            'display_name' => 'Warehouse bot',
            'purpose' => 'Anbar hesabatlari, kritik stok ve mal hereketleri.',
            'token_key' => 'telegram_warehouse_bot_token',
            'chat_key' => 'telegram_warehouse_chat_id',
            'fallback_generic_token' => true,
        ],
        'backup' => [
            'display_name' => 'Backup bot',
            'purpose' => 'Cihaz backup statusu ve backup retention xeberleri.',
            'token_key' => 'telegram_backup_bot_token',
            'chat_key' => 'telegram_backup_chat_id',
            'fallback_generic_token' => true,
        ],
        'diagnostics' => [
            'display_name' => 'Diagnostics bot',
            'purpose' => 'SN/MAC/PPPoE axtaris, modem/ONT diaqnostikasi.',
            'token_key' => 'telegram_diagnostics_bot_token',
            'chat_key' => 'telegram_diagnostics_chat_id',
            'fallback_generic_token' => true,
        ],
        'modem_search' => [
            'display_name' => 'Agilli Sobot',
            'purpose' => 'SN, MAC ve ID ile ONT/PPPoE axtarisi.',
            'token_key' => 'telegram_modem_search_bot_token',
            'chat_key' => 'telegram_modem_search_chat_id',
            'fallback_generic_token' => false,
        ],
        'superpilot' => [
            'display_name' => 'Superpilot AI bot',
            'purpose' => 'Operator, texnik, anbar, maliyye ve NOC suallarina kontekstli cavab veren AI agent.',
            'token_key' => 'telegram_modem_search_bot_token',
            'chat_key' => 'telegram_modem_search_chat_id',
            'fallback_generic_token' => false,
        ],
    ];
}

function app_mgs_normalize_bot_key(string $botKey): string
{
    $botKey = strtolower(trim($botKey));
    $botKey = preg_replace('/[^a-z0-9_]+/', '_', $botKey) ?? '';
    $botKey = trim($botKey, '_');
    return mb_substr($botKey, 0, 48);
}

function app_mgs_telegram_token_env_key(string $configKey): string
{
    $configKey = strtolower(trim($configKey));
    if (preg_match('/^telegram_[a-z0-9_]+_token$/', $configKey) !== 1) {
        return '';
    }

    return strtoupper($configKey);
}

function app_mgs_telegram_token_is_valid(string $token): bool
{
    return preg_match('/^[0-9]{6,16}:[A-Za-z0-9_-]{30,80}$/D', trim($token)) === 1;
}

function app_mgs_custom_telegram_bot_definition(string $botKey): ?array
{
    $botKey = app_mgs_normalize_bot_key($botKey);
    if ($botKey === '') {
        return null;
    }

    $db = app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return null;
    }

    try {
        $statement = $db->prepare(
            'SELECT bot_key, display_name, purpose, token_config_key, chat_config_key, status, metadata_json
             FROM telegram_bot_registry
             WHERE bot_key = :bot_key
               AND status <> "disabled"
             LIMIT 1'
        );
        $statement->execute(['bot_key' => $botKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $metadata = app_db_json_decode((string) ($row['metadata_json'] ?? ''), []);
        if (empty($metadata['custom'])) {
            return null;
        }

        return [
            'display_name' => (string) ($row['display_name'] ?? $botKey),
            'purpose' => (string) ($row['purpose'] ?? ''),
            'token_key' => (string) ($row['token_config_key'] ?? ''),
            'chat_key' => (string) ($row['chat_config_key'] ?? ''),
            'fallback_generic_token' => false,
            'custom' => true,
        ];
    } catch (Throwable) {
        return null;
    }
}

function app_mgs_telegram_bot_config(string $botKey, string $destination = ''): array
{
    $botKey = app_mgs_normalize_bot_key($botKey);
    $definitions = app_mgs_telegram_bot_definitions();
    $definition = $definitions[$botKey] ?? app_mgs_custom_telegram_bot_definition($botKey);
    if (!is_array($definition)) {
        return ['configured' => false, 'bot_key' => $botKey, 'reason' => 'unknown_bot'];
    }

    $tokenEnvKey = app_mgs_telegram_token_env_key((string) $definition['token_key']);
    $specificToken = $tokenEnvKey !== '' ? app_communication_environment_secret($tokenEnvKey) : '';
    $specificChatId = app_mgs_runtime_setting((string) $definition['chat_key']);
    $token = $specificToken;
    $chatId = trim($destination) !== '' ? trim($destination) : $specificChatId;
    $fallbackTokenUsed = false;

    if (($definition['fallback_generic_token'] ?? false) && !app_mgs_telegram_token_is_valid($token)) {
        $token = app_communication_environment_secret('TELEGRAM_TOKEN');
        $fallbackTokenUsed = app_mgs_telegram_token_is_valid($token);
    }

    if (!app_communication_channel_enabled('telegram')) {
        return [
            'bot_key' => $botKey,
            'display_name' => (string) $definition['display_name'],
            'purpose' => (string) $definition['purpose'],
            'configured' => false,
            'reason' => 'channel_disabled',
            'endpoint' => '',
            'destination' => $chatId,
            'destination_masked' => app_mgs_mask($chatId),
            'token_env_key' => $tokenEnvKey,
        ];
    }

    $missing = [];
    if (!app_mgs_telegram_token_is_valid($token)) {
        $missing[] = 'token';
    }
    if ($chatId === '') {
        $missing[] = 'chat_id';
    }

    return [
        'bot_key' => $botKey,
        'display_name' => (string) $definition['display_name'],
        'purpose' => (string) $definition['purpose'],
        'token_config_key' => (string) $definition['token_key'],
        'token_env_key' => $tokenEnvKey,
        'chat_config_key' => (string) $definition['chat_key'],
        'configured' => app_mgs_telegram_token_is_valid($token) && $chatId !== '',
        'reason' => empty($missing) ? '' : ('missing_' . implode('_and_', $missing)),
        'endpoint' => app_mgs_telegram_token_is_valid($token) ? ('https://api.telegram.org/bot' . $token . '/sendMessage') : '',
        'destination' => $chatId,
        'destination_masked' => app_mgs_mask($chatId),
        'has_specific_token' => $specificToken !== '',
        'has_specific_chat' => $specificChatId !== '',
        'fallback_token_used' => $fallbackTokenUsed,
        'fallback_chat_allowed' => false,
        'custom' => !empty($definition['custom']),
    ];
}

function app_notification_category_policy(): array
{
    return [
        'CUSTOMER_REQUEST' => ['bot_key' => 'customer', 'scope' => 'customer'],
        'CUSTOMER_TECHNICAL_SUPPORT' => ['bot_key' => 'customer', 'scope' => 'customer'],
        'CUSTOMER_BILLING_REQUEST' => ['bot_key' => 'customer', 'scope' => 'customer'],
        'NETWORK' => ['bot_key' => 'network', 'scope' => 'network'],
        'NOC' => ['bot_key' => 'network', 'scope' => 'network'],
        'OLT' => ['bot_key' => 'network', 'scope' => 'network'],
        'ONT' => ['bot_key' => 'network', 'scope' => 'network'],
        'PON' => ['bot_key' => 'network', 'scope' => 'network'],
        'OPTICAL' => ['bot_key' => 'network', 'scope' => 'network'],
        'RADIUS' => ['bot_key' => 'network', 'scope' => 'network'],
        'NAS_ROUTER' => ['bot_key' => 'network', 'scope' => 'network'],
        'SERVER_INFRASTRUCTURE' => ['bot_key' => 'network', 'scope' => 'network'],
        'BILLING' => ['bot_key' => 'finance', 'scope' => 'finance'],
        'PAYMENT_PROVIDER' => ['bot_key' => 'finance', 'scope' => 'finance'],
        'FINANCE' => ['bot_key' => 'finance', 'scope' => 'finance'],
        'FIELD_TECHNICIAN' => ['bot_key' => 'diagnostics', 'scope' => 'field'],
        'INVENTORY' => ['bot_key' => 'warehouse', 'scope' => 'inventory'],
        'SECURITY' => ['bot_key' => 'superpilot', 'scope' => 'security'],
        'SYSTEM_HEALTH' => ['bot_key' => 'superpilot', 'scope' => 'system'],
        'BACKUP' => ['bot_key' => 'backup', 'scope' => 'backup'],
        'AI_AUTOMATION' => ['bot_key' => 'superpilot', 'scope' => 'ai'],
        'EXECUTIVE' => ['bot_key' => 'superpilot', 'scope' => 'management'],
        'UNCLASSIFIED' => ['bot_key' => 'superpilot', 'scope' => 'unclassified'],
    ];
}

function app_notification_validate_event(array $event): array
{
    $message = trim((string) ($event['message'] ?? $event['message_body'] ?? $event['title'] ?? ''));
    if ($message === '') {
        return ['ok' => false, 'reason' => 'empty_message'];
    }

    return ['ok' => true, 'reason' => ''];
}

function app_notification_resolve_category(array $event): string
{
    $raw = strtoupper(trim((string) ($event['category'] ?? $event['notification_category'] ?? '')));
    $raw = preg_replace('/[^A-Z0-9_]+/', '_', $raw) ?: '';
    $policies = app_notification_category_policy();
    if ($raw !== '' && isset($policies[$raw])) {
        return $raw;
    }

    $fingerprint = strtoupper(implode(' ', array_filter([
        (string) ($event['event_type'] ?? ''),
        (string) ($event['alarm_type'] ?? ''),
        (string) ($event['source'] ?? ''),
        (string) ($event['object_type'] ?? ''),
        (string) ($event['title'] ?? ''),
    ])));

    $rules = [
        'CUSTOMER_BILLING_REQUEST' => '/CUSTOMER.*(BILL|PAY|ODE|ODENIS|BORC)|ABONENT.*(BILL|PAY|ODE|ODENIS|BORC)/',
        'CUSTOMER_TECHNICAL_SUPPORT' => '/CUSTOMER|ABONENT|MUSTERI|MURACIET|SUPPORT/',
        'PAYMENT_PROVIDER' => '/PAYMENT_PROVIDER|PROVIDER|TERMINAL|EMANAT|MPAY|ODENIS_PROVIDER/',
        'FINANCE' => '/FINANCE|MALIYYE|REVENUE|INVOICE|CASH|NAGD/',
        'INVENTORY' => '/INVENTORY|WAREHOUSE|ANBAR|STOCK|LOW_STOCK|MAL|MODEM_HANDOFF/',
        'BACKUP' => '/BACKUP|RESTORE|DEDUP/',
        'SECURITY' => '/SECURITY|BRUTE|FORCE|XSS|SQL|INJECTION|TOKEN|PRIVILEGE|DDOS/',
        'SYSTEM_HEALTH' => '/SYSTEM|HEALTH|WORKER|DISK|CPU|MEMORY|SERVICE/',
        'RADIUS' => '/RADIUS|RADACCT|AUTH|NAS/',
        'OLT' => '/OLT|GPON|HSGQ/',
        'ONT' => '/ONT|MODEM|SERIAL|SN/',
        'PON' => '/PON/',
        'OPTICAL' => '/OPTIC|OPTICAL|RX|TX|DBM|SIGNAL|TEMPERATURE/',
        'NAS_ROUTER' => '/ROUTER|ASR|CORE|NAS/',
        'SERVER_INFRASTRUCTURE' => '/SERVER|PROXMOX|MYSQL|POSTGRES|CLICKHOUSE|REDIS|RABBIT/',
        'NETWORK' => '/NETWORK|NOC|DEVICE|OFFLINE|ONLINE|UPLINK|SWITCH|VLAN|MPLS|PORT|PING/',
        'AI_AUTOMATION' => '/AI|COPILOT|SUPERPILOT|AUTOMATION/',
    ];

    foreach ($rules as $category => $pattern) {
        if (preg_match($pattern, $fingerprint)) {
            return $category;
        }
    }

    return 'UNCLASSIFIED';
}

function app_notification_resolve_bot(array $event): array
{
    $category = app_notification_resolve_category($event);
    $policies = app_notification_category_policy();
    $policy = $policies[$category] ?? $policies['UNCLASSIFIED'];
    $botKey = (string) ($policy['bot_key'] ?? 'superpilot');

    $definitions = app_mgs_telegram_bot_definitions();
    if (!isset($definitions[$botKey])) {
        $botKey = 'superpilot';
    }

    return [
        'category' => $category,
        'bot_key' => $botKey,
        'scope' => (string) ($policy['scope'] ?? 'unclassified'),
        'customer_safe' => (string) ($policy['scope'] ?? '') === 'customer',
    ];
}

function app_notification_resolve_destination(array $event): array
{
    $route = app_notification_resolve_bot($event);
    $destination = trim((string) ($event['destination'] ?? ''));
    $config = app_mgs_telegram_bot_config((string) $route['bot_key'], $destination);

    return $route + [
        'destination' => (string) ($config['destination'] ?? ''),
        'destination_masked' => (string) ($config['destination_masked'] ?? ''),
        'configured' => !empty($config['configured']),
    ];
}

function app_notification_sanitize_message(string $message, string $category = ''): string
{
    $message = trim($message);
    $message = preg_replace('/(?i)\b(password|parol|sifre|şifrə|pwd|secret|token|api[_-]?key)\s*[:=]\s*\S+/', '$1: ***', $message) ?? $message;
    $message = preg_replace('/(?i)\b(otp|pin|kod)\s*[:=]\s*\d{4,8}\b/', '$1: ***', $message) ?? $message;
    $message = preg_replace_callback('/(?<!\d)(?:\+?994|0)?(?:50|51|55|70|77|99)\d{7}(?!\d)/', static function (array $match): string {
        $digits = preg_replace('/\D+/', '', $match[0]) ?? '';
        if (strlen($digits) < 9) {
            return $match[0];
        }
        return substr($match[0], 0, 4) . '***' . substr($match[0], -2);
    }, $message) ?? $message;

    return mb_substr($message, 0, 3900);
}

function app_notification_format_event_message(array $event, string $category): string
{
    $labels = [
        'CUSTOMER_REQUEST' => 'Musteri muracieti',
        'CUSTOMER_TECHNICAL_SUPPORT' => 'Texniki destek',
        'CUSTOMER_BILLING_REQUEST' => 'Musteri billing muracieti',
        'NETWORK' => 'Sebeke', 'NOC' => 'NOC', 'OLT' => 'OLT', 'ONT' => 'ONT',
        'PON' => 'PON', 'OPTICAL' => 'Optik xett', 'RADIUS' => 'RADIUS',
        'NAS_ROUTER' => 'Router/NAS', 'SERVER_INFRASTRUCTURE' => 'Server infrastrukturu',
        'BILLING' => 'Billing', 'PAYMENT_PROVIDER' => 'Odenis provideri', 'FINANCE' => 'Maliyye',
        'FIELD_TECHNICIAN' => 'Sahe texniki', 'INVENTORY' => 'Anbar', 'SECURITY' => 'Tehlukesizlik',
        'SYSTEM_HEALTH' => 'Sistem veziyyeti', 'BACKUP' => 'Backup',
        'AI_AUTOMATION' => 'AI avtomatlasdirma', 'EXECUTIVE' => 'Idareetme', 'UNCLASSIFIED' => 'Bildiris',
    ];
    $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
    $rawState = strtolower(trim((string) ($event['state'] ?? $event['status'] ?? $metadata['state'] ?? $metadata['status'] ?? '')));
    $stateLabel = match (true) {
        in_array($rawState, ['down', 'offline', 'unreachable', 'failed', 'error', 'open', 'problem'], true) => 'Offline / xeta var',
        in_array($rawState, ['up', 'online', 'reachable', 'recovered', 'resolved', 'closed', 'ok'], true) => 'Online / berpa olunub',
        default => $rawState !== '' ? $rawState : '',
    };
    $title = clean_text((string) ($event['title'] ?? ''));
    $rawMessage = trim((string) ($event['message'] ?? $event['message_body'] ?? ''));
    $object = clean_text((string) (
        $event['object_name'] ?? $event['device_name'] ?? $event['object_id']
        ?? $metadata['device_name'] ?? $metadata['object_name'] ?? $metadata['object_id'] ?? ''
    ));
    $reason = clean_text((string) (
        $event['reason'] ?? $event['cause'] ?? $metadata['reason'] ?? $metadata['cause']
        ?? $metadata['offline_reason'] ?? $metadata['error'] ?? ''
    ));
    $durationSeconds = max(0, (int) ($event['duration_seconds'] ?? $metadata['duration_seconds'] ?? 0));
    $duration = '';
    if ($durationSeconds > 0) {
        $days = intdiv($durationSeconds, 86400);
        $hours = intdiv($durationSeconds % 86400, 3600);
        $minutes = intdiv($durationSeconds % 3600, 60);
        $duration = trim(($days > 0 ? ($days . ' gun ') : '') . ($hours > 0 ? ($hours . ' saat ') : '') . ($minutes > 0 ? ($minutes . ' deqiqe') : ''));
    }

    $lines = ['[' . ($labels[$category] ?? $labels['UNCLASSIFIED']) . ']'];
    if ($title !== '') {
        $lines[] = 'Basliq: ' . $title;
    }
    if ($stateLabel !== '') {
        $lines[] = 'Status: ' . $stateLabel;
    }
    if ($object !== '') {
        $lines[] = 'Obyekt: ' . $object;
    }
    if ($duration !== '') {
        $lines[] = 'Muddet: ' . $duration;
    }
    if ($reason !== '') {
        $lines[] = 'Sebeb: ' . $reason;
    }
    if ($rawMessage !== '' && $rawMessage !== $title) {
        $lines[] = 'Melumat: ' . $rawMessage;
    }
    $lines[] = 'Vaxt: ' . app_db_now() . ' (' . app_timezone() . ')';
    return implode("\n", $lines);
}

function app_notification_sanitize_payload(array $payload): array
{
    $blockedKeys = ['password', 'parol', 'sifre', 'şifrə', 'pwd', 'secret', 'token', 'api_key', 'apikey'];
    foreach ($payload as $key => $value) {
        $lowerKey = strtolower((string) $key);
        if (in_array($lowerKey, $blockedKeys, true) || str_contains($lowerKey, 'secret') || str_contains($lowerKey, 'token')) {
            $payload[$key] = '***';
            continue;
        }
        if (is_array($value)) {
            $payload[$key] = app_notification_sanitize_payload($value);
        }
    }

    return $payload;
}

function app_notification_deduplicate_event(array $event): array
{
    $dedupKey = clean_text((string) ($event['dedup_key'] ?? $event['notification_dedup_key'] ?? ''));
    if ($dedupKey === '') {
        return ['suppressed' => false, 'dedup_key' => '', 'reason' => 'no_dedup_key'];
    }

    $db = app_db();
    if (!$db instanceof PDO) {
        return ['suppressed' => false, 'dedup_key' => $dedupKey, 'reason' => 'db_unavailable'];
    }

    $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
    $rawState = strtolower(trim((string) ($event['state'] ?? $event['status'] ?? $metadata['state'] ?? $metadata['status'] ?? '')));
    $transitionState = match (true) {
        in_array($rawState, ['down', 'offline', 'unreachable', 'failed', 'error', 'open', 'problem'], true) => 'down',
        in_array($rawState, ['up', 'online', 'reachable', 'recovered', 'resolved', 'closed', 'ok'], true) => 'up',
        default => '',
    };

    if ($transitionState !== '' && app_mgs_ensure_schema($db)) {
        $registryKey = 'notify:' . hash('sha256', $dedupKey);
        $registry = $db->prepare('SELECT status, metadata_json FROM mgs_dedup_registry WHERE dedup_key = :dedup_key LIMIT 1');
        $registry->execute(['dedup_key' => $registryKey]);
        $row = $registry->fetch(PDO::FETCH_ASSOC);
        $previousState = '';
        $previousMetadata = '';
        if (is_array($row)) {
            $registryMetadata = app_db_json_decode((string) ($row['metadata_json'] ?? ''), []);
            $lastState = strtolower(trim((string) ($registryMetadata['last_state'] ?? '')));
            if ($lastState === '') {
                $lastState = (string) ($row['status'] ?? '') === 'resolved' ? 'up' : 'down';
            }
            $previousState = $lastState;
            $previousMetadata = (string) ($row['metadata_json'] ?? '');
        }

        $claimId = bin2hex(random_bytes(12));
        $claimMetadata = app_db_json_encode([
            'last_state' => $transitionState,
            'transition_claim_id' => $claimId,
            'transition_committed' => false,
            'source_dedup_key_hash' => hash('sha256', $dedupKey),
        ]);
        $desiredStatus = $transitionState === 'up' ? 'resolved' : 'open';
        try {
            $claim = $db->prepare(
                'INSERT INTO mgs_dedup_registry (dedup_key, dedup_domain, title, status, metadata_json)
                 VALUES (:dedup_key, "notification_state", :title, :status, :metadata_json)
                 ON DUPLICATE KEY UPDATE
                    metadata_json = IF(status <> VALUES(status), VALUES(metadata_json), metadata_json),
                    title = IF(status <> VALUES(status), VALUES(title), title),
                    last_seen_at = IF(status <> VALUES(status), NOW(), last_seen_at),
                    seen_count = IF(status <> VALUES(status), seen_count + 1, seen_count),
                    status = IF(status <> VALUES(status), VALUES(status), status)'
            );
            $claim->execute([
                'dedup_key' => $registryKey,
                'title' => mb_substr(clean_text((string) ($event['title'] ?? $event['message'] ?? 'Notification state')), 0, 191),
                'status' => $desiredStatus,
                'metadata_json' => $claimMetadata,
            ]);
            $claimed = $claim->rowCount() > 0;
        } catch (Throwable) {
            return [
                'suppressed' => true,
                'dedup_key' => $dedupKey,
                'registry_key' => $registryKey,
                'transition_state' => $transitionState,
                'reason' => 'transition_registry_error',
            ];
        }

        return [
            'suppressed' => !$claimed,
            'dedup_key' => $dedupKey,
            'registry_key' => $registryKey,
            'transition_state' => $transitionState,
            'transition_claim_id' => $claimed ? $claimId : '',
            'previous_state' => $claimed ? $previousState : '',
            'previous_metadata_json' => $claimed ? $previousMetadata : '',
            'reason' => $claimed ? '' : 'duplicate_notification_state',
        ];
    }

    $statement = $db->prepare(
        'SELECT id
         FROM communication_messages
         WHERE metadata_json LIKE :needle
           AND metadata_json LIKE :dedup_field
           AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         LIMIT 1'
    );
    $statement->execute([
        'needle' => '%' . $dedupKey . '%',
        'dedup_field' => '%notification_dedup_key%',
    ]);
    $existing = $statement->fetchColumn();

    return [
        'suppressed' => $existing !== false,
        'dedup_key' => $dedupKey,
        'reason' => $existing !== false ? 'duplicate_notification_24h' : '',
    ];
}

function app_notification_record_transition(array $event, array $dedupe): bool
{
    $state = (string) ($dedupe['transition_state'] ?? '');
    $registryKey = (string) ($dedupe['registry_key'] ?? '');
    if ($state === '' || $registryKey === '') {
        return true;
    }

    $db = app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return false;
    }

    $metadata = [
        'last_state' => $state,
        'source_dedup_key_hash' => hash('sha256', (string) ($dedupe['dedup_key'] ?? '')),
        'event_type' => clean_text((string) ($event['event_type'] ?? $event['alarm_type'] ?? 'notification')),
        'source' => clean_text((string) ($event['source'] ?? '')),
        'transition_committed' => true,
    ];
    $claimId = (string) ($dedupe['transition_claim_id'] ?? '');
    $statement = $db->prepare(
        'UPDATE mgs_dedup_registry
         SET title = :title, status = :status, metadata_json = :metadata_json, last_seen_at = NOW()
         WHERE dedup_key = :dedup_key
           AND (:claim_id = "" OR metadata_json LIKE :claim_needle)'
    );
    return $statement->execute([
        'dedup_key' => $registryKey,
        'title' => mb_substr(clean_text((string) ($event['title'] ?? $event['message'] ?? 'Notification state')), 0, 191),
        'status' => $state === 'up' ? 'resolved' : 'open',
        'metadata_json' => app_db_json_encode($metadata),
        'claim_id' => $claimId,
        'claim_needle' => '%"transition_claim_id":"' . $claimId . '"%',
    ]);
}

function app_notification_release_transition(array $dedupe): bool
{
    $registryKey = (string) ($dedupe['registry_key'] ?? '');
    $claimId = (string) ($dedupe['transition_claim_id'] ?? '');
    if ($registryKey === '' || $claimId === '') {
        return true;
    }
    $db = app_db();
    if (!$db instanceof PDO) {
        return false;
    }
    $claimNeedle = '%"transition_claim_id":"' . $claimId . '"%';
    $previousState = (string) ($dedupe['previous_state'] ?? '');
    if ($previousState === '') {
        $statement = $db->prepare('DELETE FROM mgs_dedup_registry WHERE dedup_key = :dedup_key AND metadata_json LIKE :claim_needle');
        return $statement->execute(['dedup_key' => $registryKey, 'claim_needle' => $claimNeedle]);
    }

    $statement = $db->prepare(
        'UPDATE mgs_dedup_registry
         SET status = :status, metadata_json = :metadata_json, last_seen_at = NOW()
         WHERE dedup_key = :dedup_key AND metadata_json LIKE :claim_needle'
    );
    return $statement->execute([
        'status' => $previousState === 'up' ? 'resolved' : 'open',
        'metadata_json' => (string) ($dedupe['previous_metadata_json'] ?? ''),
        'dedup_key' => $registryKey,
        'claim_needle' => $claimNeedle,
    ]);
}

function app_notification_record_delivery(array $delivery): bool
{
    app_mgs_record_operation_action([
        'action_type' => 'notification_routed',
        'entity_type' => 'notification',
        'entity_id' => (string) ($delivery['dedup_key'] ?? $delivery['message_id'] ?? ''),
        'status' => !empty($delivery['suppressed']) ? 'suppressed' : 'queued',
        'summary' => (string) ($delivery['summary'] ?? 'Notification routed'),
        'payload' => app_notification_sanitize_payload($delivery),
    ]);

    return true;
}

function app_notification_handle_delivery_failure(array $delivery): bool
{
    app_mgs_record_operation_action([
        'action_type' => 'notification_delivery_failed',
        'entity_type' => 'notification',
        'entity_id' => (string) ($delivery['dedup_key'] ?? ''),
        'status' => 'failed',
        'summary' => (string) ($delivery['reason'] ?? 'Notification delivery failed'),
        'payload' => app_notification_sanitize_payload($delivery),
    ]);

    return true;
}

function app_notification_route_event(array $event, bool $testMode = false, bool $queueMessage = true): array
{
    $validation = app_notification_validate_event($event);
    if (empty($validation['ok'])) {
        return $validation + ['queued' => false, 'suppressed' => false];
    }

    $route = app_notification_resolve_destination($event);
    $category = (string) $route['category'];
    $botKey = (string) $route['bot_key'];
    $dedupe = app_notification_deduplicate_event($event);
    $message = app_notification_sanitize_message(app_notification_format_event_message($event, $category), $category);

    $result = [
        'ok' => true,
        'queued' => false,
        'suppressed' => !empty($dedupe['suppressed']),
        'reason' => (string) ($dedupe['reason'] ?? ''),
        'category' => $category,
        'bot_key' => $botKey,
        'scope' => (string) $route['scope'],
        'customer_safe' => !empty($route['customer_safe']),
        'dedup_key' => (string) ($dedupe['dedup_key'] ?? ''),
        'message_id' => 0,
    ];

    if (!$queueMessage || !empty($dedupe['suppressed'])) {
        if (!$queueMessage && empty($dedupe['suppressed'])) {
            app_notification_release_transition($dedupe);
        }
        app_notification_record_delivery($result + ['summary' => 'Notification suppressed or queue disabled']);
        return $result;
    }

    $metadata = app_notification_sanitize_payload((array) ($event['metadata'] ?? []));
    $metadata += [
        '_routing_resolved' => true,
        'notification_category' => $category,
        'notification_scope' => (string) $route['scope'],
        'notification_dedup_key' => (string) ($dedupe['dedup_key'] ?? ''),
        'event_type' => clean_text((string) ($event['event_type'] ?? $event['alarm_type'] ?? 'notification')),
        'source' => clean_text((string) ($event['source'] ?? '')),
    ];

    $queued = app_mgs_queue_telegram_bot_message(
        $botKey,
        clean_text((string) ($event['template_code'] ?? 'mgs_notification')) ?: 'mgs_notification',
        $message,
        $metadata,
        $testMode,
        (string) ($event['destination'] ?? '')
    );

    $result['queued'] = !empty($queued['ok']);
    $result['message_id'] = (int) ($queued['message_id'] ?? 0);
    $result['configured'] = !empty($queued['configured']);
    $result['destination_masked'] = (string) ($queued['destination_masked'] ?? '');
    $result['reason'] = empty($queued['ok']) ? (string) ($queued['reason'] ?? 'queue_failed') : '';

    if (empty($queued['ok'])) {
        app_notification_release_transition($dedupe);
        app_notification_handle_delivery_failure($result);
    } else {
        app_notification_record_transition($event, $dedupe);
        app_notification_record_delivery($result + ['summary' => 'Notification queued']);
    }

    return $result;
}

function app_notification_send_notification(array $event, bool $testMode = false): array
{
    return app_notification_route_event($event, $testMode, true);
}

function app_mgs_queue_telegram_bot_message(
    string $botKey,
    string $templateCode,
    string $messageBody,
    array $metadata = [],
    bool $testMode = false,
    string $destination = ''
): array {
    $db = app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }

    $botKey = clean_text($botKey);
    $definitions = app_mgs_telegram_bot_definitions();
    if ($botKey === '' || !isset($definitions[$botKey])) {
        return ['ok' => false, 'reason' => 'unknown_bot'];
    }

    if (empty($metadata['_routing_resolved']) && (
        isset($metadata['notification_category'])
        || isset($metadata['category'])
        || isset($metadata['event_type'])
        || isset($metadata['alarm_type'])
    )) {
        $route = app_notification_resolve_bot($metadata + ['message' => $messageBody]);
        $resolvedBotKey = clean_text((string) ($route['bot_key'] ?? ''));
        if ($resolvedBotKey !== '' && isset($definitions[$resolvedBotKey])) {
            $botKey = $resolvedBotKey;
        }
        $metadata['notification_category'] = (string) ($route['category'] ?? 'UNCLASSIFIED');
        $metadata['notification_scope'] = (string) ($route['scope'] ?? 'unclassified');
        $metadata['_routing_resolved'] = true;
    }

    $provider = app_mgs_telegram_bot_config($botKey, $destination);
    $body = app_notification_sanitize_message($messageBody, (string) ($metadata['notification_category'] ?? ''));
    if ($body === '') {
        return ['ok' => false, 'reason' => 'empty_message'];
    }
    if ($testMode && !str_starts_with($body, 'TEST MESAJI')) {
        $body = "TEST MESAJI\n" . $body;
    }
    if (mb_strlen($body) > 3900) {
        $body = mb_substr($body, 0, 3900);
    }

    if (empty($provider['configured']) && !$testMode) {
        return [
            'ok' => false,
            'reason' => (string) ($provider['reason'] ?? 'bot_not_configured') ?: 'bot_not_configured',
            'message_id' => 0,
            'bot_key' => $botKey,
            'configured' => false,
            'destination_masked' => (string) ($provider['destination_masked'] ?? ''),
            'status' => 'not_queued',
        ];
    }

    $messageMetadata = $metadata + [
        'bot_key' => $botKey,
        'bot_display_name' => (string) ($provider['display_name'] ?? $botKey),
        'provider_ready' => !empty($provider['configured']),
    ];
    $messageMetadata['bot_key'] = $botKey;

    $statement = $db->prepare(
        'INSERT INTO communication_messages (
            account_id, subscriber_code, recipient_name, destination, channel, fallback_chain,
            template_code, message_body, status, sent_at, created_by_account_id, metadata_json
         ) VALUES (
            NULL, NULL, :recipient_name, :destination, "telegram", "telegram",
            :template_code, :message_body, :status, NULL, NULL, :metadata_json
         )'
    );
    $ok = $statement->execute([
        'recipient_name' => (string) ($provider['display_name'] ?? $botKey),
        'destination' => (string) ($provider['destination'] ?? ''),
        'template_code' => clean_text($templateCode) ?: null,
        'message_body' => $body,
        'status' => $testMode ? 'test' : 'queued',
        'metadata_json' => app_db_json_encode($messageMetadata),
    ]);

    return [
        'ok' => $ok,
        'message_id' => $ok ? (int) $db->lastInsertId() : 0,
        'bot_key' => $botKey,
        'configured' => !empty($provider['configured']),
        'destination_masked' => (string) ($provider['destination_masked'] ?? ''),
        'status' => $testMode ? 'test' : 'queued',
    ];
}

function app_mgs_sync_telegram_bot_registry(?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['synced' => 0, 'configured' => 0, 'bots' => []];
    }

    $statement = $db->prepare(
        'INSERT INTO telegram_bot_registry (
            bot_key, display_name, purpose, token_config_key, chat_config_key, configured, destination_masked, status, metadata_json
         ) VALUES (
            :bot_key, :display_name, :purpose, :token_config_key, :chat_config_key, :configured, :destination_masked, :status, :metadata_json
         )
         ON DUPLICATE KEY UPDATE
            display_name = VALUES(display_name),
            purpose = VALUES(purpose),
            token_config_key = VALUES(token_config_key),
            chat_config_key = VALUES(chat_config_key),
            configured = VALUES(configured),
            destination_masked = VALUES(destination_masked),
            status = VALUES(status),
            metadata_json = VALUES(metadata_json)'
    );

    $bots = [];
    $configured = 0;
    foreach (array_keys(app_mgs_telegram_bot_definitions()) as $botKey) {
        $config = app_mgs_telegram_bot_config($botKey);
        $isConfigured = !empty($config['configured']);
        $configured += $isConfigured ? 1 : 0;
        $statement->execute([
            'bot_key' => $botKey,
            'display_name' => (string) ($config['display_name'] ?? $botKey),
            'purpose' => (string) ($config['purpose'] ?? ''),
            'token_config_key' => (string) ($config['token_config_key'] ?? ''),
            'chat_config_key' => (string) ($config['chat_config_key'] ?? ''),
            'configured' => $isConfigured ? 1 : 0,
            'destination_masked' => (string) ($config['destination_masked'] ?? '-'),
            'status' => $isConfigured ? 'ready' : 'missing_config',
            'metadata_json' => app_db_json_encode([
                'has_specific_token' => !empty($config['has_specific_token']),
                'has_specific_chat' => !empty($config['has_specific_chat']),
                'fallback_generic_token' => !empty(app_mgs_telegram_bot_definitions()[$botKey]['fallback_generic_token']),
                'fallback_chat_allowed' => false,
                'reason' => (string) ($config['reason'] ?? ''),
            ]),
        ]);
        $bots[] = $config;
    }

    return ['synced' => count($bots), 'configured' => $configured, 'bots' => $bots];
}

function app_mgs_telegram_bot_rows(?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }

    app_mgs_sync_telegram_bot_registry($db);
    $rows = $db->query('SELECT * FROM telegram_bot_registry ORDER BY FIELD(status, "ready", "test_failed", "missing_config", "disabled"), display_name');
    return $rows !== false ? ($rows->fetchAll() ?: []) : [];
}

function app_mgs_custom_bot_runtime_keys(string $botKey): array
{
    $botKey = app_mgs_normalize_bot_key($botKey);
    $tokenConfigKey = 'telegram_custom_' . $botKey . '_token';
    return [
        'token' => $tokenConfigKey,
        'token_env' => app_mgs_telegram_token_env_key($tokenConfigKey),
        'chat' => 'telegram_custom_' . $botKey . '_chat_id',
    ];
}

function app_mgs_upsert_custom_telegram_bot(PDO $db, array $payload, array $actor = []): array
{
    app_mgs_ensure_schema($db);
    $botKey = app_mgs_normalize_bot_key((string) ($payload['bot_key'] ?? ''));
    $displayName = clean_text((string) ($payload['display_name'] ?? ''));
    $purpose = clean_text((string) ($payload['purpose'] ?? ''));
    $chatId = trim((string) ($payload['chat_id'] ?? ''));

    if ($botKey === '') {
        return ['ok' => false, 'reason' => 'bot_key_required'];
    }
    if (isset(app_mgs_telegram_bot_definitions()[$botKey])) {
        return ['ok' => false, 'reason' => 'built_in_bot_locked'];
    }
    if ($displayName === '') {
        $displayName = ucwords(str_replace('_', ' ', $botKey));
    }
    if ($purpose === '') {
        $purpose = 'Custom Telegram bot.';
    }

    $keys = app_mgs_custom_bot_runtime_keys($botKey);
    if (strlen($keys['token']) > 128 || strlen($keys['chat']) > 128) {
        return ['ok' => false, 'reason' => 'bot_key_too_long'];
    }

    $existingChat = app_mgs_runtime_setting($keys['chat']);
    $token = app_communication_environment_secret((string) $keys['token_env']);
    if ($chatId === '') {
        $chatId = $existingChat;
    }
    if ($token === '') {
        return ['ok' => false, 'reason' => 'token_environment_required', 'token_env_key' => (string) $keys['token_env']];
    }
    if ($chatId === '') {
        return ['ok' => false, 'reason' => 'chat_id_required'];
    }
    if (!preg_match('/^-?\d{5,20}$/', $chatId)) {
        return ['ok' => false, 'reason' => 'invalid_chat_id_format'];
    }

    $db->prepare('DELETE FROM mgs_runtime_settings WHERE setting_key = :token_key')->execute(['token_key' => $keys['token']]);
    app_mgs_set_runtime_setting($keys['chat'], $chatId, $actor);

    $statement = $db->prepare(
        'INSERT INTO telegram_bot_registry (
            bot_key, display_name, purpose, token_config_key, chat_config_key, configured,
            destination_masked, status, metadata_json
         ) VALUES (
            :bot_key, :display_name, :purpose, :token_config_key, :chat_config_key, 1,
            :destination_masked, "ready", :metadata_json
         )
         ON DUPLICATE KEY UPDATE
            display_name = VALUES(display_name),
            purpose = VALUES(purpose),
            token_config_key = VALUES(token_config_key),
            chat_config_key = VALUES(chat_config_key),
            configured = 1,
            destination_masked = VALUES(destination_masked),
            status = "ready",
            metadata_json = VALUES(metadata_json),
            updated_at = NOW()'
    );
    $ok = $statement->execute([
        'bot_key' => $botKey,
        'display_name' => $displayName,
        'purpose' => $purpose,
        'token_config_key' => $keys['token'],
        'chat_config_key' => $keys['chat'],
        'destination_masked' => app_mgs_mask($chatId),
        'metadata_json' => app_db_json_encode([
            'custom' => true,
            'token_env_key' => (string) $keys['token_env'],
            'token_configured' => true,
            'chat_masked' => app_mgs_mask($chatId),
            'created_from' => 'mgs_integration_ui',
        ]),
    ]);

    app_mgs_record_operation_action([
        'action_type' => 'telegram_bot_saved',
        'entity_type' => 'telegram_bot',
        'entity_id' => $botKey,
        'status' => $ok ? 'executed' : 'failed',
        'actor' => $actor,
        'summary' => 'Telegram custom bot saved: ' . $botKey,
        'payload' => [
            'bot_key' => $botKey,
            'display_name' => $displayName,
            'purpose' => $purpose,
            'destination_masked' => app_mgs_mask($chatId),
            'token_env_key' => (string) $keys['token_env'],
        ],
    ]);

    return [
        'ok' => $ok,
        'bot_key' => $botKey,
        'display_name' => $displayName,
        'destination_masked' => app_mgs_mask($chatId),
        'token_env_key' => (string) $keys['token_env'],
    ];
}

function app_mgs_delete_custom_telegram_bot(PDO $db, string $botKey, array $actor = []): array
{
    app_mgs_ensure_schema($db);
    $botKey = app_mgs_normalize_bot_key($botKey);
    if ($botKey === '') {
        return ['ok' => false, 'reason' => 'bot_key_required'];
    }
    if (isset(app_mgs_telegram_bot_definitions()[$botKey])) {
        return ['ok' => false, 'reason' => 'built_in_bot_locked'];
    }

    $statement = $db->prepare('SELECT metadata_json FROM telegram_bot_registry WHERE bot_key = :bot_key LIMIT 1');
    $statement->execute(['bot_key' => $botKey]);
    $metadata = app_db_json_decode((string) ($statement->fetchColumn() ?: ''), []);
    if (empty($metadata['custom'])) {
        return ['ok' => false, 'reason' => 'custom_bot_not_found'];
    }

    $keys = app_mgs_custom_bot_runtime_keys($botKey);
    $db->prepare('DELETE FROM telegram_bot_registry WHERE bot_key = :bot_key')->execute(['bot_key' => $botKey]);
    $db->prepare('DELETE FROM mgs_runtime_settings WHERE setting_key IN (:token_key, :chat_key)')->execute([
        'token_key' => $keys['token'],
        'chat_key' => $keys['chat'],
    ]);

    app_mgs_record_operation_action([
        'action_type' => 'telegram_bot_deleted',
        'entity_type' => 'telegram_bot',
        'entity_id' => $botKey,
        'status' => 'executed',
        'actor' => $actor,
        'summary' => 'Telegram custom bot deleted: ' . $botKey,
        'payload' => ['bot_key' => $botKey],
    ]);

    return ['ok' => true, 'bot_key' => $botKey];
}

function app_mgs_send_telegram_bot_test(string $botKey, string $message = '', string $destination = '', bool $apply = false): array
{
    $config = app_mgs_telegram_bot_config($botKey, $destination);
    $message = trim($message);
    if ($message === '') {
        $message = 'TEST MESAJI' . "\n" . 'SuperOptic MGS bot smoke test: ' . date('Y-m-d H:i:s');
    } elseif (!str_starts_with($message, 'TEST MESAJI')) {
        $message = 'TEST MESAJI' . "\n" . $message;
    }
    if (mb_strlen($message) > 640) {
        $message = mb_substr($message, 0, 640);
    }

    $result = [
        'bot_key' => $botKey,
        'configured' => !empty($config['configured']),
        'destination_masked' => app_mgs_mask((string) ($config['destination'] ?? '')),
        'mode' => $apply ? 'apply' : 'dry-run',
        'sent' => false,
        'reason' => '',
        'provider_message_id' => null,
    ];

    if (empty($config['configured'])) {
        $result['reason'] = 'bot_not_configured';
        return $result;
    }

    if (!$apply) {
        $result['reason'] = 'dry_run_no_provider_request_sent';
        return $result;
    }

    if (!app_communication_external_delivery_enabled()) {
        $result['reason'] = 'external_delivery_paused';
        return $result;
    }

    $response = http_post_json((string) $config['endpoint'], [
        'chat_id' => (string) $config['destination'],
        'text' => $message,
        'disable_web_page_preview' => true,
    ]);

    $ok = is_array($response) && !empty($response['ok']);
    $result['sent'] = $ok;
    $result['provider_message_id'] = $response['result']['message_id'] ?? null;
    $result['reason'] = $ok ? '' : (is_array($response) ? (string) ($response['description'] ?? 'provider_rejected') : 'provider_request_failed');

    $db = app_db();
    if ($db instanceof PDO && app_mgs_ensure_schema($db)) {
        $update = $db->prepare(
            'UPDATE telegram_bot_registry
             SET last_test_at = NOW(), status = :status, configured = :configured, destination_masked = :destination_masked
             WHERE bot_key = :bot_key'
        );
        $update->execute([
            'status' => $ok ? 'ready' : 'test_failed',
            'configured' => !empty($config['configured']) ? 1 : 0,
            'destination_masked' => $result['destination_masked'],
            'bot_key' => $botKey,
        ]);
    }

    return $result;
}

function app_mgs_http_get_json(string $url): ?array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $statusCode >= 400) {
            return null;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if (!is_string($body) || $body === '') {
        return null;
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

function app_mgs_telegram_bot_send_message(string $botKey, string $text, string $destination = ''): array
{
    if (!app_communication_external_delivery_enabled()) {
        return ['ok' => false, 'reason' => 'external_delivery_paused'];
    }

    $config = app_mgs_telegram_bot_config($botKey, $destination);
    if (empty($config['configured'])) {
        return ['ok' => false, 'reason' => 'bot_not_configured'];
    }

    $response = http_post_json((string) $config['endpoint'], [
        'chat_id' => (string) $config['destination'],
        'text' => trim($text),
        'disable_web_page_preview' => true,
    ]);

    return [
        'ok' => is_array($response) && !empty($response['ok']),
        'response' => $response,
        'reason' => is_array($response) && !empty($response['ok']) ? '' : (is_array($response) ? (string) ($response['description'] ?? 'provider_rejected') : 'provider_request_failed'),
    ];
}

function app_mgs_telegram_bot_fetch_updates(string $botKey, int $offset = 0, int $timeout = 8): array
{
    $definition = app_mgs_telegram_bot_definitions()[$botKey] ?? null;
    if (!is_array($definition)) {
        return ['ok' => false, 'reason' => 'unknown_bot'];
    }

    if (!app_communication_channel_enabled('telegram')) {
        return ['ok' => false, 'reason' => 'channel_disabled'];
    }

    $tokenEnvKey = app_mgs_telegram_token_env_key((string) ($definition['token_key'] ?? ''));
    $token = $tokenEnvKey !== '' ? app_communication_environment_secret($tokenEnvKey) : '';
    if (!app_mgs_telegram_token_is_valid($token)) {
        return ['ok' => false, 'reason' => 'bot_not_configured'];
    }

    $params = [
        'timeout' => max(0, min(25, $timeout)),
        'allowed_updates' => json_encode(['message'], JSON_UNESCAPED_SLASHES),
    ];
    if ($offset > 0) {
        $params['offset'] = $offset;
    }

    $url = 'https://api.telegram.org/bot' . $token . '/getUpdates?' . http_build_query($params);
    $response = app_mgs_http_get_json($url);
    if (!is_array($response)) {
        return ['ok' => false, 'reason' => 'provider_request_failed'];
    }

    return [
        'ok' => !empty($response['ok']),
        'updates' => is_array($response['result'] ?? null) ? $response['result'] : [],
        'reason' => !empty($response['ok']) ? '' : (string) ($response['description'] ?? 'provider_rejected'),
    ];
}

function app_mgs_runtime_setting(string $key, string $default = ''): string
{
    $db = app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return app_config($key, $default);
    }

    try {
        $statement = $db->prepare('SELECT setting_value FROM mgs_runtime_settings WHERE setting_key = :setting_key LIMIT 1');
        $statement->execute(['setting_key' => $key]);
        $value = $statement->fetchColumn();
        if (is_string($value)) {
            return $value;
        }
    } catch (Throwable) {
    }

    return app_config($key, $default);
}

function app_mgs_is_sensitive_setting_key(string $key): bool
{
    $key = strtolower($key);
    foreach (['token', 'secret', 'password', 'passwd', 'pwd', 'api_key', 'apikey', 'private'] as $marker) {
        if (str_contains($key, $marker)) {
            return true;
        }
    }

    return false;
}

function app_mgs_set_runtime_setting(string $key, string $value, array $actor = []): bool
{
    $db = app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return false;
    }

    $statement = $db->prepare(
        'INSERT INTO mgs_runtime_settings (setting_key, setting_value, updated_by_account_id, updated_by_name)
         VALUES (:setting_key, :setting_value, :updated_by_account_id, :updated_by_name)
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_by_account_id = VALUES(updated_by_account_id),
            updated_by_name = VALUES(updated_by_name)'
    );
    $ok = $statement->execute([
        'setting_key' => $key,
        'setting_value' => $value,
        'updated_by_account_id' => (int) ($actor['id'] ?? 0) ?: null,
        'updated_by_name' => clean_text((string) ($actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'system')),
    ]);

    $auditValue = app_mgs_is_sensitive_setting_key($key) ? '***' : $value;
    app_mgs_record_operation_action([
        'action_type' => 'runtime_setting_updated',
        'entity_type' => 'setting',
        'entity_id' => $key,
        'status' => $ok ? 'executed' : 'failed',
        'actor' => $actor,
        'summary' => 'Runtime setting updated: ' . $key . '=' . $auditValue,
        'payload' => ['setting_key' => $key, 'setting_value' => $auditValue],
    ]);

    return $ok;
}

function app_mgs_monitor_map_account_id(array $user): int
{
    $accountId = (int) ($user['id'] ?? 0);
    return $accountId > 0 ? $accountId : 0;
}

function app_mgs_monitor_map_key(string $mapKey): string
{
    $mapKey = strtolower(trim($mapKey));
    $mapKey = preg_replace('/[^a-z0-9_-]+/', '-', $mapKey) ?? '';
    return trim($mapKey, '-') !== '' ? substr(trim($mapKey, '-'), 0, 64) : 'noc-monitor';
}

function app_mgs_monitor_map_layout(string $mapKey, array $user, ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }
    $authorization = app_noc_authorize($user, 'read_monitor_map', [], $db);
    if (empty($authorization['ok'])) {
        return [];
    }

    $statement = $db->prepare(
        'SELECT device_id, x, y, scale, note, updated_at
           FROM monitor_map_nodes
          WHERE map_key = :map_key
            AND account_id = :account_id'
    );
    $statement->execute([
        'map_key' => app_mgs_monitor_map_key($mapKey),
        'account_id' => app_mgs_monitor_map_account_id($user),
    ]);

    $layout = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $deviceId = trim((string) ($row['device_id'] ?? ''));
        if ($deviceId === ''
            || empty(app_noc_authorize($user, 'read_monitor_map', ['device_key' => $deviceId], $db)['ok'])) {
            continue;
        }
        $layout[$deviceId] = [
            'x' => (float) ($row['x'] ?? 50),
            'y' => (float) ($row['y'] ?? 50),
            'scale' => (float) ($row['scale'] ?? 1),
            'note' => (string) ($row['note'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return $layout;
}

function app_mgs_save_monitor_map_node(string $mapKey, array $user, string $deviceId, float $x, float $y, float $scale, string $note, ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    $deviceId = clean_text($deviceId);
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_not_ready'];
    }
    if ($deviceId === '') {
        return ['ok' => false, 'reason' => 'device_id_required'];
    }
    $authorization = app_noc_authorize($user, 'update_monitor_map', ['device_key' => $deviceId], $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('update_monitor_map', $authorization);
    }

    $x = max(-8.0, min(108.0, $x));
    $y = max(-8.0, min(108.0, $y));
    $scale = max(0.45, min(1.65, $scale));
    $accountId = app_mgs_monitor_map_account_id($user);
    $actorName = clean_text((string) ($user['full_name'] ?? $user['name'] ?? $user['login'] ?? 'system'));

    $statement = $db->prepare(
        'INSERT INTO monitor_map_nodes (
            map_key, account_id, device_id, x, y, scale, note, updated_by_account_id, updated_by_name
         ) VALUES (
            :map_key, :account_id, :device_id, :x, :y, :scale, :note, :updated_by_account_id, :updated_by_name
         )
         ON DUPLICATE KEY UPDATE
            x = VALUES(x),
            y = VALUES(y),
            scale = VALUES(scale),
            note = VALUES(note),
            updated_by_account_id = VALUES(updated_by_account_id),
            updated_by_name = VALUES(updated_by_name),
            updated_at = NOW()'
    );
    $ok = $statement->execute([
        'map_key' => app_mgs_monitor_map_key($mapKey),
        'account_id' => $accountId,
        'device_id' => $deviceId,
        'x' => $x,
        'y' => $y,
        'scale' => $scale,
        'note' => clean_text($note),
        'updated_by_account_id' => $accountId > 0 ? $accountId : null,
        'updated_by_name' => $actorName !== '' ? $actorName : 'system',
    ]);

    return [
        'ok' => $ok,
        'map_key' => app_mgs_monitor_map_key($mapKey),
        'device_id' => $deviceId,
        'x' => $x,
        'y' => $y,
        'scale' => $scale,
    ];
}

function app_mgs_is_private_management_ipv4(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }
    $value = (int) (unpack('N', inet_pton($ip))[1] ?? 0);
    $ranges = [
        [0x0A000000, 0x0AFFFFFF],
        [0x64400000, 0x647FFFFF],
        [0xAC100000, 0xAC1FFFFF],
        [0xC0A80000, 0xC0A8FFFF],
    ];
    foreach ($ranges as [$start, $end]) {
        if ($value >= $start && $value <= $end) {
            return true;
        }
    }
    return false;
}

function app_mgs_management_scan_hosts(string $target, int $maxHosts): array
{
    $target = trim($target);
    $maxHosts = max(1, min(1024, $maxHosts));
    $start = 0;
    $end = 0;

    if (str_contains($target, '/')) {
        [$ip, $prefixText] = array_pad(explode('/', $target, 2), 2, '');
        $prefix = ctype_digit($prefixText) ? (int) $prefixText : -1;
        if (!app_mgs_is_private_management_ipv4($ip) || $prefix < 16 || $prefix > 32) {
            return [];
        }
        $value = (int) (unpack('N', inet_pton($ip))[1] ?? 0);
        $mask = $prefix === 0 ? 0 : ((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
        $network = $value & $mask;
        $broadcast = $network | ((~$mask) & 0xFFFFFFFF);
        $start = $prefix <= 30 ? $network + 1 : $network;
        $end = $prefix <= 30 ? $broadcast - 1 : $broadcast;
    } elseif (str_contains($target, '-')) {
        [$first, $last] = array_pad(array_map('trim', explode('-', $target, 2)), 2, '');
        if (!app_mgs_is_private_management_ipv4($first) || !app_mgs_is_private_management_ipv4($last)) {
            return [];
        }
        $start = (int) (unpack('N', inet_pton($first))[1] ?? 0);
        $end = (int) (unpack('N', inet_pton($last))[1] ?? 0);
    } elseif (app_mgs_is_private_management_ipv4($target)) {
        $start = $end = (int) (unpack('N', inet_pton($target))[1] ?? 0);
    } else {
        return [];
    }

    if ($start <= 0 || $end < $start || ($end - $start + 1) > $maxHosts) {
        return [];
    }
    $hosts = [];
    for ($value = $start; $value <= $end; $value++) {
        $ip = inet_ntop(pack('N', $value));
        if (!is_string($ip) || !app_mgs_is_private_management_ipv4($ip)) {
            return [];
        }
        $hosts[] = $ip;
    }
    return $hosts;
}

function app_mgs_scan_management_network(array $payload, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'status' => 503, 'data' => [], 'error' => 'db_unavailable'];
    }
    $authorization = app_noc_authorize($actor, 'scan_network', [], $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('scan_network', $authorization) + ['status' => 403, 'data' => []];
    }

    $target = clean_text((string) ($payload['target'] ?? ''));
    $maxHosts = max(1, min(1024, (int) ($payload['maxHosts'] ?? 64)));
    $hosts = app_mgs_management_scan_hosts($target, $maxHosts);
    if ($hosts === []) {
        return ['ok' => false, 'status' => 422, 'data' => [], 'error' => 'invalid_or_too_large_private_scan_target'];
    }
    $ports = array_values(array_unique(array_filter(
        array_map('intval', preg_split('/[\s,;]+/', (string) ($payload['ports'] ?? '22,80,443')) ?: []),
        static fn (int $port): bool => $port >= 1 && $port <= 65535
    )));
    $ports = array_slice($ports !== [] ? $ports : [22, 80, 443], 0, 12);
    $serviceNames = [22 => 'ssh', 23 => 'telnet', 80 => 'http', 161 => 'snmp', 443 => 'https', 8291 => 'winbox'];
    $results = [];

    foreach ($hosts as $ip) {
        $openPorts = [];
        foreach ($ports as $port) {
            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client(
                'tcp://' . $ip . ':' . $port,
                $errno,
                $errstr,
                0.18,
                STREAM_CLIENT_CONNECT
            );
            if (!is_resource($socket)) {
                continue;
            }
            fclose($socket);
            $openPorts[] = [
                'port' => $port,
                'service' => $serviceNames[$port] ?? 'tcp',
            ];
        }
        if ($openPorts !== []) {
            $results[] = [
                'ipAddress' => $ip,
                'hostname' => '',
                'deviceType' => 'auto',
                'status' => 'tapildi',
                'openPorts' => $openPorts,
            ];
        }
    }

    app_mgs_record_operation_action([
        'action_type' => 'network_scan',
        'entity_type' => 'management_network',
        'entity_id' => $target,
        'status' => 'executed',
        'actor' => $actor,
        'summary' => 'Management network scan completed',
        'payload' => [
            'target' => $target,
            'host_count' => count($hosts),
            'found_count' => count($results),
            'ports' => $ports,
        ],
    ]);
    return [
        'ok' => true,
        'status' => 200,
        'data' => [
            'target' => $target,
            'results' => $results,
            'scannedCount' => count($hosts),
            'foundCount' => count($results),
        ],
        'error' => '',
    ];
}

function app_mgs_create_monitor_device(array $payload, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'status' => 503, 'data' => [], 'error' => 'db_unavailable'];
    }
    $ip = trim((string) ($payload['ipAddress'] ?? $payload['ip_address'] ?? ''));
    $name = clean_text((string) ($payload['name'] ?? $payload['deviceName'] ?? ''));
    if (!app_mgs_is_private_management_ipv4($ip) || $name === '') {
        return ['ok' => false, 'status' => 422, 'data' => [], 'error' => 'valid_private_ip_and_name_required'];
    }
    $authorization = app_noc_authorize(
        $actor,
        'create_monitor_device',
        ['ip_address' => $ip, 'management_network' => clean_text((string) ($payload['location'] ?? ''))],
        $db
    );
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('create_monitor_device', $authorization) + ['status' => 403, 'data' => []];
    }

    $deviceType = strtolower(clean_text((string) ($payload['deviceType'] ?? 'auto')));
    if (!in_array($deviceType, ['olt', 'router', 'switch', 'server', 'auto'], true)) {
        $deviceType = 'auto';
    }
    $vendor = clean_text((string) ($payload['vendor'] ?? '')) ?: 'unknown';
    $model = clean_text((string) ($payload['model'] ?? ''));
    $vendorKey = strtolower($vendor);
    $adapter = match (true) {
        str_contains($vendorKey, 'huawei') && $deviceType === 'olt' => 'HuaweiSmartAXAdapter',
        str_contains($vendorKey, 'hsgq') && $deviceType === 'olt' => 'HSGQOltAdapter',
        str_contains($vendorKey, 'cisco') && $deviceType === 'router' => 'CiscoRouterAdapter',
        str_contains($vendorKey, 'cisco') && $deviceType === 'switch' => 'CiscoSwitchAdapter',
        default => 'GenericSnmpAdapter',
    };
    $existing = $db->prepare('SELECT device_key FROM device_learning_profiles WHERE ip_address = :ip LIMIT 1');
    $existing->execute(['ip' => $ip]);
    $deviceKey = clean_text((string) ($existing->fetchColumn() ?: ''));
    if ($deviceKey === '') {
        $deviceKey = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $deviceType . '-' . $vendor . '-' . $ip)), '-');
        $deviceKey .= '-' . substr(hash('sha256', $ip), 0, 8);
    }
    $monitorPathReady = app_mgs_management_path_ready();
    $reachability = $monitorPathReady ? 'unknown' : 'unreachable_because_monitor_path_down';
    $status = $monitorPathReady ? 'pending_discovery' : 'waiting_mgmt_path';
    $location = clean_text((string) ($payload['location'] ?? '')) ?: 'management';

    try {
        $statement = $db->prepare(
            'INSERT INTO device_learning_profiles (
                device_key, device_name, ip_address, device_type, vendor, model, adapter_key,
                management_network, expected_reachability, monitoring_enabled, reachability_state,
                confidence_score, features_json, command_map_json, snmp_oids_json, status
             ) VALUES (
                :device_key, :device_name, :ip_address, :device_type, :vendor, :model, :adapter_key,
                :management_network, 1, 1, :reachability_state, :confidence_score, "[]", "{}", "{}", :status
             )
             ON DUPLICATE KEY UPDATE
                device_name = VALUES(device_name),
                device_type = VALUES(device_type),
                vendor = VALUES(vendor),
                model = VALUES(model),
                adapter_key = VALUES(adapter_key),
                management_network = VALUES(management_network),
                monitoring_enabled = 1,
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'device_key' => $deviceKey,
            'device_name' => $name,
            'ip_address' => $ip,
            'device_type' => $deviceType,
            'vendor' => $vendor,
            'model' => $model,
            'adapter_key' => $adapter,
            'management_network' => $location,
            'reachability_state' => $reachability,
            'confidence_score' => $vendor !== 'unknown' ? 0.65 : 0.25,
            'status' => $status,
        ]);

        $sensitivePayload = [
            'ssh_username' => (string) ($payload['sshUsername'] ?? ''),
            'ssh_password' => (string) ($payload['sshPassword'] ?? ''),
            'ssh_port' => (int) ($payload['sshPort'] ?? 22),
            'snmp_read_community' => (string) ($payload['snmpReadCommunity'] ?? ''),
            'snmp_write_community' => (string) ($payload['snmpWriteCommunity'] ?? ''),
            'snmp_port' => (int) ($payload['snmpPort'] ?? 161),
            'trap_port' => (int) ($payload['snmpTrapPort'] ?? 162),
            'location' => $location,
            'notes' => clean_text((string) ($payload['noteText'] ?? '')),
        ];
        if (array_filter($sensitivePayload, static fn (mixed $value, string $key): bool =>
            in_array($key, ['ssh_username', 'ssh_password', 'snmp_read_community', 'snmp_write_community'], true)
                && trim((string) $value) !== '', ARRAY_FILTER_USE_BOTH)) {
            app_mgs_set_device_access_override($deviceKey, $sensitivePayload, $actor, $db);
        }
        app_mgs_record_operation_action([
            'action_type' => 'monitor_device_create',
            'entity_type' => 'device_learning',
            'entity_id' => $deviceKey,
            'status' => 'executed',
            'actor' => $actor,
            'summary' => 'Monitor device saved: ' . $name,
            'payload' => [
                'device_key' => $deviceKey,
                'ip_address' => $ip,
                'device_type' => $deviceType,
                'vendor' => $vendor,
                'model' => $model,
            ],
        ]);
        return [
            'ok' => true,
            'status' => 200,
            'data' => ['deviceKey' => $deviceKey, 'ipAddress' => $ip, 'name' => $name],
            'error' => '',
        ];
    } catch (Throwable $exception) {
        return ['ok' => false, 'status' => 500, 'data' => [], 'error' => 'device_save_failed'];
    }
}

function app_mgs_create_technical_task(array $payload, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO) {
        return ['ok' => false, 'error' => 'db_unavailable'];
    }
    $customerName = clean_text((string) ($payload['customerName'] ?? ''));
    $taskType = clean_text((string) ($payload['taskType'] ?? 'field_work')) ?: 'field_work';
    if ($customerName === '') {
        return ['ok' => false, 'error' => 'customer_name_required'];
    }
    $assignedId = max(0, (int) ($payload['assignedUserId'] ?? 0));
    $assignedName = '';
    if ($assignedId > 0) {
        $employee = app_mgs_hr_employee_record($db, $assignedId);
        $assignedName = clean_text((string) ($employee['full_name'] ?? $employee['login'] ?? ''));
    }
    $ticketNo = app_db_next_ticket_no();
    $ok = app_db_insert_ticket([
        'ticket_no' => $ticketNo,
        'customer_name' => $customerName,
        'address' => clean_text((string) ($payload['customerAddress'] ?? '')),
        'subject' => 'Texniki is: ' . $taskType,
        'message' => clean_text((string) ($payload['noteText'] ?? 'Texniki tapshiriq')) ?: 'Texniki tapshiriq',
        'status' => clean_text((string) ($payload['status'] ?? 'Aciqdir')) ?: 'Aciqdir',
        'assigned_technician_account_id' => $assignedId,
        'assigned_technician_name' => $assignedName,
        'created_by_account_id' => (int) ($actor['id'] ?? 0),
        'created_by_role' => clean_text((string) ($actor['employee_role'] ?? $actor['role'] ?? 'employee')),
        'created_by_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'system')),
        'metadata' => [
            'task_type' => $taskType,
            'priority' => clean_text((string) ($payload['priority'] ?? 'normal')),
            'source' => 'unified_portal',
        ],
    ]);
    return $ok
        ? ['ok' => true, 'ticket_no' => $ticketNo]
        : ['ok' => false, 'error' => 'technical_task_save_failed'];
}

function app_mgs_noc_service_request_json(
    string $path,
    string $method,
    array $payload,
    array $actor,
    string $action,
    array $object = [],
    int $timeout = 20
): array {
    $authorization = app_noc_authorize($actor, $action, $object, app_db());
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure($action, $authorization) + ['status' => 403, 'data' => []];
    }

    $cleanPath = '/' . ltrim($path, '/');
    if (strtoupper($method) === 'POST' && $cleanPath === '/api/service/network-scan') {
        return app_mgs_scan_management_network($payload, $actor, app_db());
    }
    if (strtoupper($method) === 'POST' && $cleanPath === '/api/service/devices') {
        return app_mgs_create_monitor_device($payload, $actor, app_db());
    }
    return [
        'ok' => false,
        'status' => 404,
        'data' => [],
        'error' => 'unsupported_native_noc_action',
    ];
}

function app_mgs_management_path_ready(): bool
{
    return in_array(strtolower(app_mgs_runtime_setting('monitor_management_vlan_connected', '0')), ['1', 'true', 'yes', 'on'], true);
}

function app_mgs_set_management_path(bool $enabled, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    $authorization = app_noc_authorize($actor, 'update_management_path', [], $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('update_management_path', $authorization);
    }
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }
    if (!app_mgs_set_runtime_setting('monitor_management_vlan_connected', $enabled ? '1' : '0', $actor)) {
        return ['ok' => false, 'reason' => 'management_path_setting_failed'];
    }

    $result = app_mgs_refresh_device_reachability($db, false, $actor);
    app_mgs_record_operation_action([
        'action_type' => 'management_path_toggle',
        'entity_type' => 'management_network',
        'entity_id' => 'vlan550',
        'status' => !empty($result['ok']) ? 'executed' : 'failed',
        'actor' => $actor,
        'summary' => $enabled ? 'VLAN 550 management path enabled' : 'VLAN 550 management path disabled',
        'payload' => ['enabled' => $enabled, 'result' => $result],
    ]);

    return $result + ['enabled' => $enabled];
}

function app_mgs_default_processes(): array
{
    return [
        ['management_path_vlan550', 'network', 'VLAN 550 management path', 'engineer', 'NOC', 'Device learning', 'ready', 'toggle_required', 'management_network_id + path_down', 'Monitorinq yolu aktiv olmadan daxili cihaz offline bileti acilmir.'],
        ['device_learning_discovery', 'network', 'Avadanliq auto-learning', 'engineer', 'Device discovery', 'Adapters', 'partial', 'profiles_seeded_probe_pending', 'device_key', 'SSH/SNMP real probe VLAN 550 qosulanda icra olunur.'],
        ['alarm_correlation_dedup', 'noc', 'Alarm correlation ve dedup', 'noc', 'Metrics', 'Tickets', 'ready', 'dedup_active', 'source + alarm_type + object', 'Tekrarlanan alarm yeni bilet yaratmir, last_seen yenilenir.'],
        ['telegram_bot_delivery', 'notification', 'Telegram bot message delivery', 'operator', 'Renderer', 'Telegram', 'ready', '7_bot_registry', 'bot_key + message_template + entity', 'Network, customer, finance, warehouse, backup, diagnostics ve modem botlari ayridir.'],
        ['ticket_create_assign_close', 'crm', 'Ticket yaratma, teyin ve baglama', 'operator', 'CRM', 'SPoG audit', 'ready', 'portal_tickets_integrated', 'ticket_no', 'Yaradilan ve baglanan ticketler SPoG action jurnalina dusur.'],
        ['inventory_issue_return_stock', 'inventory', 'Inventar medaxil/mexaric', 'warehouse', 'Anbar', 'Ticket close', 'ready', 'stock_movement_integrated', 'sku + ticket_no + movement_type', 'Ticket baglanarken istifade olunan mal stoka yazilir.'],
        ['employee_work_kpi_snapshot', 'hr', 'Isci nezaret ve KPI snapshot', 'hr', 'Tickets/Inventory', 'HR KPI', 'ready', 'daily_snapshot', 'employee_id + work_date', 'Ticket ve inventar hereketlerinden gunluk performans yigilrir.'],
        ['customer_360_profile', 'billing', 'Musteri 360 profil', 'operator', 'Billing/RADIUS/CRM', 'Customer profile', 'ready', 'single_profile', 'customer_id', 'Odenis, radius, modem, ticket, SMS ve IP/NAT bir profilde gorunur.'],
        ['billing_radius_sync', 'billing', 'Billing ve FreeRADIUS sync', 'billing', 'Billing', 'RADIUS', 'ready', 'radcheck_radreply_ready', 'username', 'Musteriler, tarifler, IP cavablari ve status RADIUS cedvellerine yazilir.'],
        ['backup_scheduler', 'backup', 'Device backup lifecycle', 'engineer', 'Device adapters', 'Backup bot', 'ready', 'worker_hash_dedup_ready', 'device_id + backup_hash', 'Profile/inventory backup worker, hash dedup ve safe running-config skip hazirdir.'],
        ['modem_search_bot', 'support', 'SN/MAC/PPPoE modem axtaris botu', 'operator', 'Telegram', 'Customer 360', 'ready', 'live_query_ready', 'query_hash', 'Bot live OLT optical query ede bilir, alinamayanda stored snapshot-a derhal dusur.'],
        ['mpls_vlan_provisioning', 'network', 'MPLS/VLAN provisioning', 'engineer', 'MPLS UI', 'Device adapters', 'partial', 'dry_run_diff_ready_apply_pending', 'customer_id + vlan_id', 'Dry-run/diff ve audit hazirdir; real core/switch/OLT yazilisi yalniz explicit apply adapter ve admin tesdiqi ile getmelidir.'],
        ['nginx_gateway', 'platform', 'Nginx gateway ve public domenler', 'admin', 'Deploy', 'Security', 'ready', 'config_generated', 'domain + route', 'Admin, customer portal, API gateway ve status domenleri ayrilacaq.'],
        ['copilot_autonomy_ready', 'platform', 'Copilot/autonomy hazirliq qati', 'admin', 'SPoG registry', 'Future agents', 'planned', 'needs_stable_process_registry', 'process_key', 'Autonomous Codex/Claude ucun proses, dedup ve test registry esas olacaq.'],
    ];
}

function app_mgs_sync_process_registry(?PDO $db = null, array $actor = []): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO) {
        return ['synced' => 0, 'ready' => 0, 'blocked' => 0];
    }
    $authorization = app_noc_authorize($actor, 'sync_process_registry', [], $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('sync_process_registry', $authorization)
            + ['synced' => 0, 'ready' => 0, 'blocked' => 0];
    }
    if (!app_mgs_ensure_schema($db)) {
        return ['synced' => 0, 'ready' => 0, 'blocked' => 0];
    }

    $statement = $db->prepare(
        'INSERT INTO mgs_process_registry (
            process_key, process_group, title, owner_role, source_module, target_module,
            status, readiness_state, dedup_policy, notes, metadata_json, last_checked_at
         ) VALUES (
            :process_key, :process_group, :title, :owner_role, :source_module, :target_module,
            :status, :readiness_state, :dedup_policy, :notes, :metadata_json, NOW()
         )
         ON DUPLICATE KEY UPDATE
            process_group = VALUES(process_group),
            title = VALUES(title),
            owner_role = VALUES(owner_role),
            source_module = VALUES(source_module),
            target_module = VALUES(target_module),
            status = VALUES(status),
            readiness_state = VALUES(readiness_state),
            dedup_policy = VALUES(dedup_policy),
            notes = VALUES(notes),
            metadata_json = VALUES(metadata_json),
            last_checked_at = NOW()'
    );

    $synced = 0;
    $ready = 0;
    $blocked = 0;
    foreach (app_mgs_default_processes() as $process) {
        [$key, $group, $title, $owner, $source, $target, $status, $readiness, $dedup, $notes] = $process;
        $statement->execute([
            'process_key' => $key,
            'process_group' => $group,
            'title' => $title,
            'owner_role' => $owner,
            'source_module' => $source,
            'target_module' => $target,
            'status' => $status,
            'readiness_state' => $readiness,
            'dedup_policy' => $dedup,
            'notes' => $notes,
            'metadata_json' => app_db_json_encode(['copilot_ready' => in_array($status, ['ready', 'partial'], true)]),
        ]);
        $synced++;
        $ready += $status === 'ready' ? 1 : 0;
        $blocked += $status === 'blocked' ? 1 : 0;
    }

    return ['synced' => $synced, 'ready' => $ready, 'blocked' => $blocked];
}

function app_mgs_register_dedup_event(string $dedupKey, string $domain, string $title, string $status = 'open', array $metadata = []): bool
{
    $db = app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return false;
    }

    $status = in_array($status, ['open', 'resolved', 'suppressed', 'test'], true) ? $status : 'open';
    $statement = $db->prepare(
        'INSERT INTO mgs_dedup_registry (dedup_key, dedup_domain, title, status, metadata_json)
         VALUES (:dedup_key, :dedup_domain, :title, :status, :metadata_json)
         ON DUPLICATE KEY UPDATE
            last_seen_at = NOW(),
            seen_count = seen_count + 1,
            title = VALUES(title),
            status = VALUES(status),
            metadata_json = VALUES(metadata_json)'
    );

    return $statement->execute([
        'dedup_key' => $dedupKey,
        'dedup_domain' => clean_text($domain) ?: 'alarm',
        'title' => mb_substr(clean_text($title), 0, 191),
        'status' => $status,
        'metadata_json' => app_db_json_encode($metadata),
    ]);
}

function app_mgs_probe_ip(
    string $ip,
    int $timeoutMs = 900,
    array $actor = [],
    array $device = [],
    ?PDO $db = null,
    string $authorizationAction = 'probe_device'
): array
{
    $target = array_replace(['ip_address' => trim($ip)], $device);
    $authorization = app_noc_authorize($actor, $authorizationAction, $target, $db);
    if (empty($authorization['ok'])) {
        return array_merge(
            ['reachable' => false, 'method' => 'none'],
            app_noc_authorization_failure($authorizationAction, $authorization)
        );
    }
    $ip = trim($ip);
    if ($ip === '') {
        return ['reachable' => false, 'method' => 'none', 'error' => 'empty_ip'];
    }

    if (function_exists('exec')) {
        $timeout = max(200, min(5000, $timeoutMs));
        $command = DIRECTORY_SEPARATOR === '\\'
            ? 'ping -n 1 -w ' . $timeout . ' ' . escapeshellarg($ip)
            : 'ping -c 1 -W ' . max(1, (int) ceil($timeout / 1000)) . ' ' . escapeshellarg($ip);
        $output = [];
        $exitCode = 1;
        @exec($command, $output, $exitCode);
        if ($exitCode === 0) {
            return ['reachable' => true, 'method' => 'ping', 'error' => ''];
        }
    }

    foreach ([22, 80, 443] as $port) {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($ip, $port, $errno, $errstr, max(0.2, $timeoutMs / 1000));
        if (is_resource($socket)) {
            fclose($socket);
            return ['reachable' => true, 'method' => 'tcp_' . $port, 'error' => ''];
        }
    }

    return ['reachable' => false, 'method' => 'ping_tcp', 'error' => 'no_reply'];
}

function app_mgs_find_nmap_binary(): ?string
{
    static $resolved = false;
    static $binary = null;
    if ($resolved) {
        return $binary;
    }

    $resolved = true;
    $candidates = [
        'C:\\Program Files (x86)\\Nmap\\nmap.exe',
        'C:\\Program Files\\Nmap\\nmap.exe',
        '/usr/bin/nmap',
        '/usr/local/bin/nmap',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $binary = $candidate;
            return $binary;
        }
    }

    if (function_exists('exec')) {
        $output = [];
        $exitCode = 1;
        $command = DIRECTORY_SEPARATOR === '\\' ? 'where nmap' : 'which nmap';
        @exec($command, $output, $exitCode);
        if ($exitCode === 0) {
            foreach ($output as $line) {
                $line = trim((string) $line);
                if ($line !== '' && is_file($line)) {
                    $binary = $line;
                    return $binary;
                }
            }
        }
    }

    return null;
}

function app_mgs_probe_tcp_port(
    string $ip,
    int $port,
    int $timeoutMs = 900,
    array $actor = [],
    array $device = [],
    ?PDO $db = null,
    string $authorizationAction = 'probe_device'
): array
{
    $target = array_replace(['ip_address' => trim($ip)], $device);
    $authorization = app_noc_authorize($actor, $authorizationAction, $target, $db);
    if (empty($authorization['ok'])) {
        return array_merge(
            ['reachable' => false, 'method' => 'tcp_' . $port],
            app_noc_authorization_failure($authorizationAction, $authorization)
        );
    }
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($ip, $port, $errno, $errstr, max(0.2, $timeoutMs / 1000));
    if (is_resource($socket)) {
        fclose($socket);
        return ['reachable' => true, 'method' => 'tcp_' . $port, 'error' => ''];
    }

    return ['reachable' => false, 'method' => 'tcp_' . $port, 'error' => $errstr !== '' ? $errstr : 'port_closed'];
}

function app_mgs_find_python_binary(): ?string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $configuredPython = trim(app_config('mgs_python_binary', ''));
    // Server deployments commonly expose only python3.  Keep the explicit
    // absolute path before PATH-based fallbacks so systemd/PHP-FPM contexts
    // can run the HSGQ helper consistently.
    $candidates = array_values(array_unique(array_filter([
        $configuredPython,
        '/usr/bin/python3',
        '/usr/local/bin/python3',
        'python3',
        'python',
        'py',
    ])));
    foreach ($candidates as $candidate) {
        $output = [];
        $exitCode = 1;
        @exec(escapeshellarg($candidate) . ' --version 2>&1', $output, $exitCode);
        if ($exitCode === 0 && $output !== []) {
            $resolved = $candidate;
            return $resolved;
        }
    }

    $resolved = null;
    return null;
}

function app_mgs_legacy_project_root(): string
{
    $configured = trim((string) getenv('SUPEROPTIC_MGS_ROOT'));
    if ($configured !== '') {
        return rtrim($configured, "\\/");
    }

    return DIRECTORY_SEPARATOR === '\\'
        ? 'D:\\xampp\\htdocs\\oltidareetme'
        : '/opt/superoptic-mgs';
}

function app_mgs_billing_project_root(): string
{
    $configured = trim((string) getenv('SUPEROPTIC_BILLING_ROOT'));
    if ($configured !== '') {
        return rtrim($configured, "\\/");
    }

    return dirname(__FILE__);
}

function app_mgs_backup_root(): string
{
    $configured = trim((string) getenv('BACKUP_DIR'));
    if ($configured !== '') {
        return $configured;
    }

    return DIRECTORY_SEPARATOR === '\\'
        ? app_mgs_legacy_project_root() . '\\data\\backups'
        : '/var/backups/superoptic/mgs';
}

function app_mgs_updates_root(): string
{
    $configured = trim((string) getenv('SUPEROPTIC_UPDATES_ROOT'));
    if ($configured !== '') {
        return rtrim($configured, "\\/");
    }

    return DIRECTORY_SEPARATOR === '\\'
        ? app_mgs_legacy_project_root() . '\\data\\updates'
        : '/var/lib/superoptic/updates';
}

function app_mgs_list_restore_points(int $limit = 10): array
{
    $backupRoot = app_mgs_backup_root();
    if (!is_dir($backupRoot)) {
        return [];
    }

    $rows = [];
    foreach ((array) glob($backupRoot . DIRECTORY_SEPARATOR . 'superoptic-mgs-*.zip*') as $filePath) {
        if (!is_file($filePath)) {
            continue;
        }
        $baseName = basename($filePath);
        if (!preg_match('/^superoptic-mgs-.+\.(zip|zip\.enc)$/i', $baseName)) {
            continue;
        }
        $rows[] = [
            'name' => $baseName,
            'path' => $filePath,
            'size_bytes' => (int) (@filesize($filePath) ?: 0),
            'modified_at' => @filemtime($filePath) ?: 0,
        ];
    }

    usort($rows, static function (array $left, array $right): int {
        return ($right['modified_at'] ?? 0) <=> ($left['modified_at'] ?? 0);
    });

    return array_slice($rows, 0, max(1, min($limit, 50)));
}

function app_mgs_path_is_absolute(string $path): bool
{
    if ($path === '') {
        return false;
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        return preg_match('/^[A-Za-z]:\\\\/i', $path) === 1 || str_starts_with($path, '\\\\');
    }
    return str_starts_with($path, '/');
}

function app_mgs_path_is_within(string $path, string $root): bool
{
    $separator = DIRECTORY_SEPARATOR;
    $normalizedRoot = rtrim($root, "\\/") . $separator;
    if (DIRECTORY_SEPARATOR === '\\') {
        return str_starts_with(strtolower($path), strtolower($normalizedRoot));
    }
    return str_starts_with($path, $normalizedRoot);
}

function app_mgs_find_npm_binary(): ?string
{
    static $resolved = false;
    static $binary = null;
    if ($resolved) {
        return $binary;
    }

    $resolved = true;
    $candidates = DIRECTORY_SEPARATOR === '\\'
        ? ['npm.cmd', 'C:\\Program Files\\nodejs\\npm.cmd', 'C:\\Program Files (x86)\\nodejs\\npm.cmd']
        : ['npm'];

    foreach ($candidates as $candidate) {
        if (str_contains($candidate, '\\') || str_contains($candidate, '/')) {
            if (is_file($candidate)) {
                $binary = $candidate;
                return $binary;
            }
            continue;
        }

        $output = [];
        $exitCode = 1;
        @exec((DIRECTORY_SEPARATOR === '\\' ? 'where ' : 'command -v ') . escapeshellarg($candidate) . ' 2>&1', $output, $exitCode);
        if ($exitCode === 0) {
            $binary = $candidate;
            return $binary;
        }
    }

    return null;
}

function app_mgs_run_process(array $command, ?string $cwd = null): array
{
    if (!function_exists('proc_open')) {
        return [
            'ok' => false,
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'proc_open_unavailable',
        ];
    }

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        $command,
        $descriptorSpec,
        $pipes,
        $cwd ?: app_mgs_legacy_project_root(),
        null,
        ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        return [
            'ok' => false,
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'proc_open_failed',
        ];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'ok' => $exitCode === 0,
        'exit_code' => $exitCode,
        'stdout' => trim((string) $stdout),
        'stderr' => trim((string) $stderr),
    ];
}

function app_mgs_resolve_restore_point(string $input): ?string
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }

    $backupRoot = realpath(app_mgs_backup_root());
    if ($backupRoot === false || !is_dir($backupRoot)) {
        return null;
    }

    $candidate = $input;
    if (!app_mgs_path_is_absolute($candidate)) {
        $candidate = $backupRoot . DIRECTORY_SEPARATOR . basename($candidate);
    }

    $resolved = realpath($candidate);
    if ($resolved === false || !is_file($resolved)) {
        return null;
    }

    if (!app_mgs_path_is_within($resolved, $backupRoot)) {
        return null;
    }

    return $resolved;
}

function app_mgs_resolve_update_package(string $input, array $channelStatus = []): array
{
    $input = trim($input);
    $updatesRoot = app_mgs_updates_root();
    if (!is_dir($updatesRoot)) {
        @mkdir($updatesRoot, 0777, true);
    }

    if ($input !== '') {
        $candidate = $input;
        if (!app_mgs_path_is_absolute($candidate)) {
            $candidate = $updatesRoot . DIRECTORY_SEPARATOR . basename($candidate);
        }
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) {
            return ['ok' => false, 'error' => 'update_package_not_found'];
        }

        // Only packages inside the updates folder may be applied; absolute or
        // UNC paths elsewhere on the host are rejected.
        $rootReal = realpath($updatesRoot);
        $insideRoot = $rootReal !== false
            && str_starts_with(strtolower($resolved), strtolower(rtrim($rootReal, '\\/') . DIRECTORY_SEPARATOR));
        $uploadsDir = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'update-packages');
        $insideUploads = $uploadsDir !== false
            && str_starts_with(strtolower($resolved), strtolower(rtrim($uploadsDir, '\\/') . DIRECTORY_SEPARATOR));
        if (!$insideRoot && !$insideUploads) {
            return ['ok' => false, 'error' => 'update_package_outside_updates_root'];
        }

        return ['ok' => true, 'path' => $resolved, 'downloaded' => false];
    }

    $packageUrl = trim((string) ($channelStatus['package_url'] ?? ''));
    if ($packageUrl === '') {
        return ['ok' => false, 'error' => 'update_package_missing'];
    }

    $response = http_request_raw($packageUrl, 'GET', '', [], 45);
    if (empty($response['ok']) || (string) ($response['body'] ?? '') === '') {
        return ['ok' => false, 'error' => 'update_package_download_failed'];
    }

    $basename = basename((string) parse_url($packageUrl, PHP_URL_PATH));
    if ($basename === '' || !preg_match('/\.zip$/i', $basename)) {
        $basename = 'mgs-update-' . date('Ymd-His') . '.zip';
    }

    $target = $updatesRoot . DIRECTORY_SEPARATOR . $basename;
    file_put_contents($target, (string) $response['body']);

    $checksum = trim((string) ($channelStatus['checksum'] ?? ''));
    if ($checksum !== '') {
        $actual = hash_file('sha256', $target);
        if (!hash_equals(strtolower($checksum), strtolower($actual))) {
            @unlink($target);
            return ['ok' => false, 'error' => 'update_package_checksum_mismatch'];
        }
    }

    return ['ok' => true, 'path' => $target, 'downloaded' => true];
}

function app_mgs_delete_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $items = scandir($path) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        app_mgs_delete_tree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

function app_mgs_zip_entry_is_safe(string $entryName): bool
{
    $normalized = str_replace('\\', '/', $entryName);
    if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('/^[a-zA-Z]:/', $normalized)) {
        return false;
    }

    foreach (explode('/', $normalized) as $segment) {
        if ($segment === '..') {
            return false;
        }
    }

    return true;
}

function app_mgs_expand_archive_with_zip(string $archivePath, string $targetDir): array
{
    $zip = new ZipArchive();
    if ($zip->open($archivePath) !== true) {
        return ['ok' => false, 'stderr' => 'zip_open_failed', 'stdout' => '', 'exit_code' => 1];
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);
        if ($entryName === false || !app_mgs_zip_entry_is_safe($entryName)) {
            $zip->close();
            return ['ok' => false, 'stderr' => 'zip_entry_path_unsafe', 'stdout' => '', 'exit_code' => 1];
        }
    }

    if (!$zip->extractTo($targetDir)) {
        $zip->close();
        return ['ok' => false, 'stderr' => 'zip_extract_failed', 'stdout' => '', 'exit_code' => 1];
    }

    $zip->close();

    return ['ok' => true, 'stdout' => '', 'stderr' => '', 'exit_code' => 0];
}

function app_mgs_expand_archive(string $archivePath, string $targetDir): array
{
    if (!is_file($archivePath)) {
        return ['ok' => false, 'stderr' => 'archive_not_found', 'stdout' => '', 'exit_code' => 1];
    }

    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0777, true);
    }

    if (class_exists(ZipArchive::class)) {
        return app_mgs_expand_archive_with_zip($archivePath, $targetDir);
    }

    if (PHP_OS_FAMILY === 'Windows') {
        return app_mgs_run_process([
            'powershell.exe',
            '-NoProfile',
            '-ExecutionPolicy',
            'Bypass',
            '-Command',
            'Expand-Archive -LiteralPath ' . escapeshellarg($archivePath) . ' -DestinationPath ' . escapeshellarg($targetDir) . ' -Force',
        ]);
    }

    return ['ok' => false, 'stderr' => 'zip_extraction_unavailable', 'stdout' => '', 'exit_code' => 1];
}

function app_mgs_update_package_root(string $expandedDir): string
{
    if (is_dir($expandedDir . DIRECTORY_SEPARATOR . 'app') || is_dir($expandedDir . DIRECTORY_SEPARATOR . 'billing')) {
        return $expandedDir;
    }

    foreach ((array) scandir($expandedDir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $candidate = $expandedDir . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($candidate)) {
            continue;
        }
        if (is_dir($candidate . DIRECTORY_SEPARATOR . 'app') || is_dir($candidate . DIRECTORY_SEPARATOR . 'billing')) {
            return $candidate;
        }
    }

    return $expandedDir;
}

function app_mgs_inspect_update_package(string $archivePath): array
{
    $workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mgs-update-inspect-' . bin2hex(random_bytes(6));
    @mkdir($workDir, 0777, true);

    try {
        $expanded = app_mgs_expand_archive($archivePath, $workDir);
        if (empty($expanded['ok'])) {
            return [
                'ok' => false,
                'stderr' => (string) ($expanded['stderr'] ?? 'expand_failed'),
                'stdout' => (string) ($expanded['stdout'] ?? ''),
                'exit_code' => (int) ($expanded['exit_code'] ?? 1),
            ];
        }

        $packageRoot = app_mgs_update_package_root($workDir);
        $manifestPath = $packageRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : [];
        $manifest = is_array($manifest) ? $manifest : [];

        $result = [
            'ok' => is_dir($packageRoot . DIRECTORY_SEPARATOR . 'app') || is_dir($packageRoot . DIRECTORY_SEPARATOR . 'billing'),
            'archive' => $archivePath,
            'package_root' => $packageRoot,
            'has_app' => is_dir($packageRoot . DIRECTORY_SEPARATOR . 'app'),
            'has_billing' => is_dir($packageRoot . DIRECTORY_SEPARATOR . 'billing'),
            'version' => clean_text((string) ($manifest['version'] ?? $manifest['tag'] ?? '')),
            'notes' => clean_text((string) ($manifest['notes'] ?? '')),
            'manifest' => $manifest,
        ];
        if (empty($result['ok'])) {
            $result['stderr'] = 'update_package_has_no_app_or_billing_root';
        }

        return $result;
    } finally {
        app_mgs_delete_tree($workDir);
    }
}

function app_mgs_should_skip_update_path(string $sourcePath, bool $includeEnv = false): bool
{
    $normalized = str_replace('\\', '/', $sourcePath);
    if (!$includeEnv && preg_match('~/(?:\\.env|config\\.local\\.php)$~i', $normalized)) {
        return true;
    }

    foreach (['/node_modules/', '/.git/', '/data/backups/', '/tmp/'] as $needle) {
        if (str_contains($normalized, $needle)) {
            return true;
        }
    }

    foreach (['/server.err.log', '/server.out.log', '/server-start.log', '/server-error.log'] as $suffix) {
        if (str_ends_with($normalized, $suffix)) {
            return true;
        }
    }

    return false;
}

function app_mgs_copy_update_tree(string $sourceRoot, string $targetRoot, bool $includeEnv = false): int
{
    if (!is_dir($sourceRoot)) {
        return 0;
    }

    $copied = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $entry) {
        $sourcePath = $entry->getPathname();
        if (app_mgs_should_skip_update_path($sourcePath, $includeEnv)) {
            continue;
        }

        $relative = substr($sourcePath, strlen($sourceRoot) + 1);
        if (!is_string($relative) || $relative === '') {
            continue;
        }

        $targetPath = $targetRoot . DIRECTORY_SEPARATOR . $relative;
        if ($entry->isDir()) {
            if (!is_dir($targetPath)) {
                @mkdir($targetPath, 0777, true);
            }
            continue;
        }

        if (!is_dir(dirname($targetPath))) {
            @mkdir(dirname($targetPath), 0777, true);
        }
        if (@copy($sourcePath, $targetPath)) {
            $copied++;
        }
    }

    return $copied;
}

function app_mgs_apply_update_package(string $archivePath, bool $includeEnv = false): array
{
    $inspect = app_mgs_inspect_update_package($archivePath);
    if (empty($inspect['ok'])) {
        return array_merge($inspect, ['ok' => false]);
    }

    $npmBinary = app_mgs_find_npm_binary();
    if ($npmBinary === null) {
        return ['ok' => false, 'stderr' => 'npm_not_found', 'stdout' => '', 'exit_code' => 1];
    }

    $backupRun = app_mgs_run_process([$npmBinary, 'run', 'backup'], app_mgs_legacy_project_root());
    if (empty($backupRun['ok'])) {
        return [
            'ok' => false,
            'stderr' => 'update_prebackup_failed: ' . (string) ($backupRun['stderr'] ?? ''),
            'stdout' => (string) ($backupRun['stdout'] ?? ''),
            'exit_code' => (int) ($backupRun['exit_code'] ?? 1),
        ];
    }

    $workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mgs-update-apply-' . bin2hex(random_bytes(6));
    @mkdir($workDir, 0777, true);

    try {
        $expanded = app_mgs_expand_archive($archivePath, $workDir);
        if (empty($expanded['ok'])) {
            return [
                'ok' => false,
                'stderr' => (string) ($expanded['stderr'] ?? 'expand_failed'),
                'stdout' => trim((string) ($backupRun['stdout'] ?? '') . PHP_EOL . (string) ($expanded['stdout'] ?? '')),
                'exit_code' => (int) ($expanded['exit_code'] ?? 1),
            ];
        }

        $packageRoot = app_mgs_update_package_root($workDir);
        $legacyCopied = app_mgs_copy_update_tree($packageRoot . DIRECTORY_SEPARATOR . 'app', app_mgs_legacy_project_root(), $includeEnv);
        $billingCopied = app_mgs_copy_update_tree($packageRoot . DIRECTORY_SEPARATOR . 'billing', app_mgs_billing_project_root(), $includeEnv);

        return [
            'ok' => ($legacyCopied + $billingCopied) > 0,
            'exit_code' => 0,
            'stdout' => trim((string) ($backupRun['stdout'] ?? '') . PHP_EOL . app_db_json_encode([
                'legacy_copied' => $legacyCopied,
                'billing_copied' => $billingCopied,
                'include_env' => $includeEnv,
                'version' => $inspect['version'] ?? '',
            ])),
            'stderr' => '',
            'summary' => 'Update paketi tetbiq olundu.',
        ];
    } finally {
        app_mgs_delete_tree($workDir);
    }
}

function app_mgs_update_channel_status(): array
{
    $manifestUrl = trim((string) app_config('mgs_update_manifest_url', ''));
    $channel = trim((string) app_config('mgs_update_channel', 'stable'));
    $status = [
        'configured' => $manifestUrl !== '',
        'channel' => $channel !== '' ? $channel : 'stable',
        'manifest_url' => $manifestUrl,
        'manifest_ok' => false,
        'manifest_status' => 0,
        'remote_version' => '',
        'notes' => $manifestUrl !== '' ? 'Update manifest configured.' : 'Update manifest hele konfiqurasiya edilmeyib.',
    ];

    if ($manifestUrl !== '') {
        $result = http_request_raw($manifestUrl, 'GET', '', [], 12);
        $status['manifest_status'] = (int) ($result['status'] ?? 0);
        if (!empty($result['ok']) && trim((string) ($result['body'] ?? '')) !== '') {
            $decoded = json_decode((string) $result['body'], true);
            if (is_array($decoded)) {
                $status['manifest_ok'] = true;
                $status['remote_version'] = clean_text((string) ($decoded['version'] ?? $decoded['tag'] ?? ''));
                $status['package_url'] = clean_text((string) ($decoded['package_url'] ?? ''));
                $status['checksum'] = clean_text((string) ($decoded['checksum'] ?? ''));
                $status['notes'] = clean_text((string) ($decoded['notes'] ?? '')) ?: 'Manifest oxundu.';
            }
        }
    }

    foreach ([
        'legacy' => app_mgs_legacy_project_root(),
        'billing' => app_mgs_billing_project_root(),
    ] as $key => $root) {
        $gitDir = $root . DIRECTORY_SEPARATOR . '.git';
        if (!is_dir($gitDir) || !function_exists('exec')) {
            $status['local'][$key] = ['root' => $root, 'git' => false, 'head' => ''];
            continue;
        }
        $output = [];
        $exitCode = 1;
        @exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>&1', $output, $exitCode);
        $status['local'][$key] = [
            'root' => $root,
            'git' => $exitCode === 0,
            'head' => $exitCode === 0 ? trim((string) ($output[0] ?? '')) : '',
        ];
    }

    return $status;
}

function app_mgs_backup_center_action(string $action, array $options = [], array $actor = []): array
{
    $action = clean_text($action);
    $legacyRoot = app_mgs_legacy_project_root();
    $npmBinary = app_mgs_find_npm_binary();
    $result = [
        'ok' => false,
        'action' => $action,
        'summary' => '',
        'stdout' => '',
        'stderr' => '',
        'exit_code' => 1,
    ];

    $record = [
        'action_type' => $action,
        'entity_type' => 'backup_center',
        'entity_id' => '',
        'actor' => $actor,
        'payload' => $options,
    ];

    switch ($action) {
        case 'backup_now':
        case 'backup_telegram':
            if ($npmBinary === null) {
                $result['stderr'] = 'npm_not_found';
                break;
            }
            $command = [$npmBinary, 'run', $action === 'backup_telegram' ? 'backup:telegram' : 'backup'];
            $run = app_mgs_run_process($command, $legacyRoot);
            $result = array_merge($result, $run, [
                'summary' => $action === 'backup_telegram' ? 'Backup Telegram-a gonderildi.' : 'Backup yaradildi.',
            ]);
            break;

        case 'install_weekly_backup_task':
            $script = $legacyRoot . '\\scripts\\install-backup-task.ps1';
            if (!is_file($script)) {
                $result['stderr'] = 'install_backup_task_script_missing';
                break;
            }
            $command = ['powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $script];
            $run = app_mgs_run_process($command, $legacyRoot);
            $result = array_merge($result, $run, ['summary' => 'Heftelik backup scheduler qurasdirildi.']);
            break;

        case 'restore_verify':
        case 'restore_execute':
            if ($npmBinary === null) {
                $result['stderr'] = 'npm_not_found';
                break;
            }
            $restoreFile = app_mgs_resolve_restore_point((string) ($options['restore_file'] ?? ''));
            if ($restoreFile === null) {
                $result['stderr'] = 'restore_point_not_found';
                break;
            }
            $command = [$npmBinary, 'run', 'restore', '--', '--file', $restoreFile];
            if ($action === 'restore_verify') {
                $command[] = '--verify';
                $summary = 'Restore point verify tamamlandi.';
            } else {
                $command[] = '--force';
                if (!empty($options['restore_files'])) {
                    $command[] = '--restore-files';
                }
                if (!empty($options['restore_env'])) {
                    $command[] = '--restore-env';
                }
                $summary = 'Restore icra olundu.';
            }
            $record['entity_id'] = basename($restoreFile);
            $record['payload']['restore_file'] = $restoreFile;
            $run = app_mgs_run_process($command, $legacyRoot);
            $result = array_merge($result, $run, ['summary' => $summary]);
            break;

        case 'check_update_channel':
            $status = app_mgs_update_channel_status();
            $result['ok'] = true;
            $result['exit_code'] = 0;
            $result['stdout'] = app_db_json_encode($status);
            $result['summary'] = $status['configured']
                ? ($status['manifest_ok'] ? 'Update kanali oxundu.' : 'Update kanali konfiqurasiya olunub, amma manifest cavabi alinmadi.')
                : 'Update kanali hele konfiqurasiya edilmeyib.';
            $record['payload'] = $status;
            break;

        case 'verify_update_package':
        case 'apply_update_package':
            $status = app_mgs_update_channel_status();
            $resolved = app_mgs_resolve_update_package((string) ($options['update_package'] ?? ''), $status);
            if (empty($resolved['ok']) || empty($resolved['path'])) {
                $result['stderr'] = (string) ($resolved['error'] ?? 'update_package_not_found');
                break;
            }
            $record['entity_id'] = basename((string) $resolved['path']);
            $record['payload']['update_package'] = (string) $resolved['path'];

            if ($action === 'verify_update_package') {
                $inspect = app_mgs_inspect_update_package((string) $resolved['path']);
                $result['ok'] = !empty($inspect['ok']);
                $result['exit_code'] = !empty($inspect['ok']) ? 0 : 1;
                $result['stdout'] = app_db_json_encode($inspect);
                $result['stderr'] = (string) ($inspect['stderr'] ?? '');
                $result['summary'] = !empty($inspect['ok'])
                    ? 'Update paketi verify edildi.'
                    : 'Update paketi verify ugursuz oldu.';
                $record['payload'] = array_merge(is_array($record['payload']) ? $record['payload'] : [], $inspect);
                break;
            }

            $apply = app_mgs_apply_update_package((string) $resolved['path'], !empty($options['update_env']));
            $result = array_merge($result, $apply, [
                'summary' => $apply['summary'] ?? 'Update paketi tetbiq olundu.',
            ]);
            break;
    }

    if ($result['summary'] === '') {
        $result['summary'] = 'Emeliyyat tamamlanmadi.';
    }

    $record['status'] = $result['ok'] ? 'executed' : 'failed';
    $record['summary'] = $result['summary'];
    $record['payload'] = array_merge(
        is_array($record['payload'] ?? null) ? $record['payload'] : [],
        [
            'stdout' => $result['stdout'],
            'stderr' => $result['stderr'],
            'exit_code' => $result['exit_code'],
        ]
    );
    app_mgs_record_operation_action($record);

    return $result;
}

function app_mgs_probe_ssh_auth(
    string $ip,
    string $username,
    string $password,
    int $port = 22,
    int $timeoutMs = 2200,
    array $actor = [],
    array $device = [],
    ?PDO $db = null,
    string $authorizationAction = 'probe_device',
    string $healthProfile = ''
): array
{
    $target = array_replace(['ip_address' => trim($ip)], $device);
    $authorization = app_noc_authorize($actor, $authorizationAction, $target, $db);
    if (empty($authorization['ok'])) {
        return array_merge(
            ['reachable' => false, 'method' => 'ssh_auth'],
            app_noc_authorization_failure($authorizationAction, $authorization)
        );
    }
    $username = trim($username);
    if ($healthProfile !== '' && ($healthProfile !== 'cisco_health' || strtoupper((string)($device['vendor'] ?? '')) !== 'CISCO')) {
        return ['reachable'=>false, 'method'=>'ssh_auth', 'error'=>'unsupported_health_profile'];
    }
    if ($username === '' || $password === '') {
        return ['reachable' => false, 'method' => 'ssh_auth', 'error' => 'missing_ssh_credentials'];
    }

    $python = app_mgs_find_python_binary();
    $script = __DIR__ . '/tools/ssh-auth-probe.py';
    if ($python === null || !is_file($script) || !function_exists('exec')) {
        return ['reachable' => false, 'method' => 'ssh_auth', 'error' => 'ssh_auth_helper_unavailable'];
    }

    $payload = [
        'host' => $ip,
        'port' => $port,
        'username' => $username,
        'password' => $password,
        'timeout_ms' => max(800, $timeoutMs),
        'health_profile' => $healthProfile,
    ];
    // Credentials travel through stdin, not command arguments or a plaintext temp file.
    $process = @proc_open([$python, $script, '--payload-stdin'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
    if (!is_resource($process)) {
        return ['reachable' => false, 'method' => 'ssh_auth', 'error' => 'ssh_auth_helper_unavailable'];
    }
    fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_SLASHES));
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $started = microtime(true);
    $timedOut = false;
    do {
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        $running = proc_get_status($process)['running'];
        if (!$running) { break; }
        if (microtime(true) - $started > ($healthProfile !== '' ? 18 : 12)) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }
        usleep(50000);
    } while (true);
    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    if ($timedOut) { return ['reachable'=>false,'method'=>'ssh_auth','error'=>'ssh_auth_timeout']; }
    $output = explode("\n", trim($stdout !== '' ? $stdout : $stderr));
    $lines = array_values(array_filter(array_map(static fn ($line): string => trim((string) $line), $output), static fn ($line): bool => $line !== ''));
    $text = implode("\n", $lines);
    $jsonCandidate = $text;
    if ($lines !== []) {
        $lastLine = $lines[count($lines) - 1];
        if (str_starts_with($lastLine, '{') && str_ends_with($lastLine, '}')) {
            $jsonCandidate = $lastLine;
        }
    }
    $decoded = json_decode($jsonCandidate, true);
    if (!is_array($decoded)) {
        return ['reachable' => false, 'method' => 'ssh_auth', 'error' => $text !== '' ? $text : 'ssh_auth_invalid_response'];
    }

    return [
        'reachable' => !empty($decoded['ok']),
        'method' => 'ssh_auth',
        'error' => (string) ($decoded['error'] ?? ''),
        'banner' => (string) ($decoded['banner'] ?? ''),
        'health' => is_array($decoded['health'] ?? null) ? $decoded['health'] : null,
        'port' => (int) ($decoded['port'] ?? $port),
        'username' => $username,
    ];
}

/**
 * "Ogren" / CLI-discovery vendor registry. Each entry is a live-SSH probe
 * (walks the device's real CLI menus/help text) plus a derive step that
 * turns the raw discovery into a proposed command map -- see
 * app_mgs_hsgq_cli_discovery_probe() / app_mgs_hsgq_derived_command_map_from_discovery()
 * for the reference implementation this was extracted from.
 *
 * To onboard a NEW vendor's "ogren" button: write a
 * `tools/<vendor>-cli-discovery.py` helper that logs in over SSH and walks
 * that vendor's own menu/help conventions (the walking logic is inherently
 * vendor-specific -- there is no generic CLI menu format to auto-detect),
 * add an `app_mgs_<vendor>_cli_discovery_probe()` wrapper following the HSGQ
 * one's signature, an `app_mgs_<vendor>_derived_command_map_from_discovery()`,
 * then add one entry below. Nothing else in the dispatch path needs to
 * change. The derived command map is always a PROPOSAL for a human to
 * review before it is trusted for config_change/vlan_push/ont_control --
 * never auto-apply it.
 */
function app_mgs_cli_discovery_handler_registry(): array
{
    return [
        'HSGQ' => [
            'probe' => 'app_mgs_hsgq_cli_discovery_probe',
            'derive' => 'app_mgs_hsgq_derived_command_map_from_discovery',
            'source_label' => 'live_hsgq_cli_discovery',
        ],
        'CISCO' => [
            'probe' => 'app_mgs_cisco_cli_discovery_probe',
            'derive' => 'app_mgs_cisco_derived_command_map_from_discovery',
            'source_label' => 'live_cisco_cli_discovery',
        ],
    ];
}

function app_mgs_cli_discovery_supported_vendors(): array
{
    return array_keys(app_mgs_cli_discovery_handler_registry());
}

function app_mgs_hsgq_cli_discovery_probe(
    string $ip,
    string $username,
    string $password,
    int $port = 22,
    int $timeoutMs = 4500,
    array $actor = [],
    array $device = [],
    ?PDO $db = null,
    string $authorizationAction = 'preview_device_backup'
): array
{
    $target = array_replace(['ip_address' => trim($ip)], $device);
    $authorization = app_noc_authorize($actor, $authorizationAction, $target, $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure($authorizationAction, $authorization);
    }
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['ok' => false, 'error' => 'missing_ssh_credentials'];
    }

    $python = app_mgs_find_python_binary();
    $script = __DIR__ . '/tools/hsgq-cli-discovery.py';
    if ($python === null || !is_file($script) || !function_exists('exec')) {
        return ['ok' => false, 'error' => 'hsgq_cli_helper_unavailable'];
    }

    $payloadFile = tempnam(sys_get_temp_dir(), 'mgs_hsgq_');
    if ($payloadFile === false) {
        return ['ok' => false, 'error' => 'hsgq_cli_payload_temp_failed'];
    }

    $payload = [
        'host' => $ip,
        'port' => $port,
        'username' => $username,
        'password' => $password,
        'timeout_ms' => max(1800, $timeoutMs),
    ];
    file_put_contents($payloadFile, json_encode($payload, JSON_UNESCAPED_SLASHES));

    $command = escapeshellarg($python)
        . ' ' . escapeshellarg($script)
        . ' --payload-file ' . escapeshellarg($payloadFile);
    $output = [];
    $exitCode = 1;
    @exec($command . ' 2>&1', $output, $exitCode);
    @unlink($payloadFile);

    $lines = array_values(array_filter(array_map(static fn ($line): string => trim((string) $line), $output), static fn ($line): bool => $line !== ''));
    $text = implode("\n", $lines);
    $jsonCandidate = $text;
    if ($lines !== []) {
        $lastLine = $lines[count($lines) - 1];
        if (str_starts_with($lastLine, '{') && str_ends_with($lastLine, '}')) {
            $jsonCandidate = $lastLine;
        }
    }

    $decoded = json_decode($jsonCandidate, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => $text !== '' ? $text : 'hsgq_cli_invalid_response'];
    }

    return [
        'ok' => !empty($decoded['ok']),
        'error' => (string) ($decoded['error'] ?? ''),
        'host' => (string) ($decoded['host'] ?? $ip),
        'port' => (int) ($decoded['port'] ?? $port),
        'username' => $username,
        'prompts' => is_array($decoded['prompts'] ?? null) ? $decoded['prompts'] : [],
        'nodes' => is_array($decoded['nodes'] ?? null) ? $decoded['nodes'] : [],
        'keywords' => app_mgs_json_decode_array($decoded['keywords'] ?? []),
        'sample_ont_id' => (string) ($decoded['sample_ont_id'] ?? ''),
        'welcome' => (string) ($decoded['welcome'] ?? ''),
    ];
}

function app_mgs_hsgq_exec_commands(
    string $ip,
    string $username,
    string $password,
    array $commands,
    int $port = 22,
    int $timeoutMs = 5000,
    array $actor = [],
    array $device = [],
    ?PDO $db = null,
    string $authorizationAction = 'execute_device_command'
): array
{
    $target = array_replace(['ip_address' => trim($ip)], $device);
    $authorization = app_noc_authorize($actor, $authorizationAction, $target, $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure($authorizationAction, $authorization);
    }
    $username = trim($username);
    $commands = array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $commands), static fn ($item): bool => $item !== ''));
    if ($username === '' || $password === '') {
        return ['ok' => false, 'reason' => 'missing_ssh_credentials'];
    }
    if ($commands === []) {
        return ['ok' => false, 'reason' => 'missing_commands'];
    }

    $preflight = app_mgs_probe_tcp_port(
        $ip,
        $port,
        900,
        $actor,
        $target,
        $db,
        $authorizationAction
    );
    if (empty($preflight['reachable'])) {
        return ['ok' => false, 'reason' => 'ssh_port_unreachable'];
    }

    $python = app_mgs_find_python_binary();
    $script = __DIR__ . '/tools/hsgq-cli-command.py';
    if ($python === null || !is_file($script) || !function_exists('exec')) {
        return ['ok' => false, 'reason' => 'hsgq_command_helper_unavailable'];
    }

    $payloadFile = tempnam(sys_get_temp_dir(), 'mgs_hsgq_cmd_');
    if ($payloadFile === false) {
        return ['ok' => false, 'reason' => 'hsgq_command_payload_temp_failed'];
    }

    $payload = [
        'host' => $ip,
        'port' => $port,
        'username' => $username,
        'password' => $password,
        'timeout_ms' => max(1800, $timeoutMs),
        'commands' => $commands,
    ];
    file_put_contents($payloadFile, json_encode($payload, JSON_UNESCAPED_SLASHES));

    $command = escapeshellarg($python)
        . ' ' . escapeshellarg($script)
        . ' --payload-file ' . escapeshellarg($payloadFile);
    $output = [];
    $exitCode = 1;
    @exec($command . ' 2>&1', $output, $exitCode);
    @unlink($payloadFile);

    $lines = array_values(array_filter(array_map(static fn ($line): string => trim((string) $line), $output), static fn ($line): bool => $line !== ''));
    $text = implode("\n", $lines);
    $jsonCandidate = $text;
    if ($lines !== []) {
        $lastLine = $lines[count($lines) - 1];
        if (str_starts_with($lastLine, '{') && str_ends_with($lastLine, '}')) {
            $jsonCandidate = $lastLine;
        }
    }

    $decoded = json_decode($jsonCandidate, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'reason' => $text !== '' ? $text : 'hsgq_command_invalid_response'];
    }

    $steps = [];
    $outputMap = [];
    foreach ((array) ($decoded['steps'] ?? []) as $step) {
        if (!is_array($step)) {
            continue;
        }
        $stepCommand = trim((string) ($step['command'] ?? ''));
        if ($stepCommand === '') {
            continue;
        }
        $steps[] = [
            'command' => $stepCommand,
            'output' => (string) ($step['output'] ?? ''),
            'prompt' => (string) ($step['prompt'] ?? ''),
        ];
        $outputMap[$stepCommand] = (string) ($step['output'] ?? '');
    }

    return [
        'ok' => !empty($decoded['ok']),
        'reason' => (string) ($decoded['error'] ?? ''),
        'host' => (string) ($decoded['host'] ?? $ip),
        'port' => (int) ($decoded['port'] ?? $port),
        'username' => $username,
        'welcome' => (string) ($decoded['welcome'] ?? ''),
        'steps' => $steps,
        'outputs' => $outputMap,
        'final_prompt' => (string) ($decoded['final_prompt'] ?? ''),
    ];
}

function app_mgs_hsgq_metric_key(string $label): string
{
    $label = app_repair_mojibake_dynamic(app_repair_mojibake(trim($label)));
    $label = strtolower($label);
    $label = str_replace(['onu', 'ont'], ['onu', 'ont'], $label);
    $label = preg_replace('/[^a-z0-9]+/', '_', $label);
    return trim((string) $label, '_');
}

function app_mgs_parse_hsgq_key_value_block(string $text): array
{
    $text = app_repair_mojibake_dynamic(app_repair_mojibake($text));
    $text = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $text);
    $rows = [];
    foreach (preg_split('/\r?\n/', (string) $text) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '' || !str_contains($line, ':')) {
            continue;
        }
        if (preg_match('/^([^:]+?)\s*:\s*(.*?)\s*$/', $line, $match) !== 1) {
            continue;
        }
        $label = trim((string) ($match[1] ?? ''));
        $value = trim((string) ($match[2] ?? ''));
        if ($label === '') {
            continue;
        }
        $rows[$label] = $value;
    }

    return $rows;
}

function app_mgs_parse_hsgq_ont_info_output(string $text): array
{
    $pairs = app_mgs_parse_hsgq_key_value_block($text);
    $result = ['raw' => $pairs];
    foreach ($pairs as $label => $value) {
        $key = app_mgs_hsgq_metric_key($label);
        if ($value === '') {
            continue;
        }
        switch ($key) {
            case 'serialnumber':
                $result['serial'] = $value;
                break;
            case 'state':
                $result['status'] = $value;
                break;
            case 'run_state':
                $result['run_state'] = $value;
                break;
            case 'distance':
                $result['distance'] = $value;
                break;
            case 'pon_id':
                $result['pon'] = $value;
                break;
            case 'onu_id':
                $result['ont_id'] = $value;
                break;
            case 'onu_name':
                $result['name'] = $value;
                break;
            case 'last_up_time':
                $result['last_up_time'] = $value;
                break;
            case 'last_down_time':
                $result['last_down_time'] = $value;
                break;
            case 'ont_uptime':
                $result['uptime'] = $value;
                break;
        }
    }

    return $result;
}

function app_mgs_parse_hsgq_ont_optical_output(string $text): array
{
    $pairs = app_mgs_parse_hsgq_key_value_block($text);
    $result = ['raw' => $pairs];
    foreach ($pairs as $label => $value) {
        $key = app_mgs_hsgq_metric_key($label);
        if ($value === '') {
            continue;
        }
        switch ($key) {
            case 'work_temperature_c':
            case 'temperature':
                $result['temperature'] = $value;
                break;
            case 'work_voltage_v':
            case 'voltage':
                $result['voltage'] = $value;
                break;
            case 'transmit_bias_ma':
            case 'bias':
                $result['bias_current'] = $value;
                break;
            case 'transmit_power_dbm':
                $result['ont_tx'] = $value;
                break;
            case 'receive_power_dbm':
                $result['ont_rx'] = $value;
                break;
            case 'olt_rx_ont_power_dbm':
                $result['olt_rx'] = $value;
                break;
        }
    }

    return $result;
}

function app_mgs_parse_hsgq_optical_state_output(string $text): array
{
    $pairs = app_mgs_parse_hsgq_key_value_block($text);
    $result = ['raw' => $pairs];
    foreach ($pairs as $label => $value) {
        $key = app_mgs_hsgq_metric_key($label);
        if ($value === '') {
            continue;
        }
        switch ($key) {
            case 'temperature':
                $result['temperature'] = $value;
                break;
            case 'voltage':
                $result['voltage'] = $value;
                break;
            case 'bias':
            case 'transmit_bias_ma':
                $result['bias_current'] = $value;
                break;
            case 'tx_power':
            case 'transmit_power_dbm':
                $result['olt_tx'] = $value;
                break;
            case 'rx_power':
            case 'receive_power_dbm':
                $result['olt_port_rx'] = $value;
                break;
            case 'port_state':
                $result['port_state'] = $value;
                break;
            case 'vendor_name':
                $result['vendor_name'] = $value;
                break;
        }
    }

    return $result;
}

function app_mgs_hsgq_live_ont_snapshot(array $device, int|string $pon, int|string $ontId, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    $authorization = app_noc_authorize($actor, 'read_ont_live', $device, $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('read_ont_live', $authorization);
    }
    if (!app_mgs_management_path_ready()) {
        return ['ok' => false, 'reason' => 'management_path_down'];
    }
    if (strtoupper((string) ($device['vendor'] ?? '')) !== 'HSGQ') {
        return ['ok' => false, 'reason' => 'unsupported_vendor'];
    }

    $pon = trim((string) $pon);
    $ontId = trim((string) $ontId);
    if ($pon === '' || $ontId === '') {
        return ['ok' => false, 'reason' => 'missing_pon_or_ont'];
    }

    $access = app_mgs_device_access_profile($device, $db, $actor, 'read_ont_live');
    $sshUsername = trim((string) ($access['ssh_username'] ?? ''));
    $sshPassword = (string) ($access['ssh_password'] ?? '');
    if ($sshUsername === '' || $sshPassword === '') {
        return ['ok' => false, 'reason' => 'missing_ssh_credentials'];
    }

    $commandMap = app_mgs_json_decode_array($device['command_map_json'] ?? null);
    $discoverySummary = app_mgs_latest_cli_discovery_summary($device, $db, $actor);
    $derivedCommandMap = is_array($discoverySummary['derived_command_map'] ?? null) ? $discoverySummary['derived_command_map'] : [];
    if ($derivedCommandMap !== []) {
        $commandMap = array_merge($commandMap, $derivedCommandMap);
    }
    $requiredTemplates = [
        'enter_enable',
        'enter_config',
        'enter_gpon_context',
        'ont_info_single',
        'ont_optical_single',
        'optical_state',
    ];
    foreach ($requiredTemplates as $key) {
        if (trim((string) ($commandMap[$key] ?? '')) === '') {
            return ['ok' => false, 'reason' => 'missing_command_map:' . $key];
        }
    }

    $vars = ['{pon}' => $pon, '{ont}' => $ontId];
    $commands = [];
    foreach ($requiredTemplates as $key) {
        $commands[$key] = strtr((string) $commandMap[$key], $vars);
    }
    if (trim((string) ($commandMap['gpon_statistics'] ?? '')) !== '') {
        $commands['gpon_statistics'] = strtr((string) $commandMap['gpon_statistics'], $vars);
    }
    if (trim((string) ($commandMap['ont_port_vlan'] ?? '')) !== '') {
        $commands['ont_port_vlan'] = strtr((string) $commandMap['ont_port_vlan'], $vars);
    }

    $run = app_mgs_hsgq_exec_commands(
        (string) ($device['ip_address'] ?? ''),
        $sshUsername,
        $sshPassword,
        array_values($commands),
        (int) ($access['ssh_port'] ?? 22),
        6500,
        $actor,
        $device,
        $db,
        'read_ont_live'
    );
    if (empty($run['ok'])) {
        return ['ok' => false, 'reason' => (string) ($run['reason'] ?? 'command_probe_failed')];
    }

    $outputs = is_array($run['outputs'] ?? null) ? $run['outputs'] : [];
    $ontInfo = app_mgs_parse_hsgq_ont_info_output((string) ($outputs[$commands['ont_info_single']] ?? ''));
    $ontOptical = app_mgs_parse_hsgq_ont_optical_output((string) ($outputs[$commands['ont_optical_single']] ?? ''));
    $opticalState = app_mgs_parse_hsgq_optical_state_output((string) ($outputs[$commands['optical_state']] ?? ''));

    $ont = [
        'serial' => (string) ($ontInfo['serial'] ?? ''),
        'status' => (string) (($ontInfo['status'] ?? '') !== '' ? $ontInfo['status'] : ($ontInfo['run_state'] ?? '')),
        'run_state' => (string) ($ontInfo['run_state'] ?? ''),
        'distance' => (string) ($ontInfo['distance'] ?? ''),
        'temperature' => (string) (($ontOptical['temperature'] ?? '') !== '' ? $ontOptical['temperature'] : ($opticalState['temperature'] ?? '')),
        'voltage' => (string) (($ontOptical['voltage'] ?? '') !== '' ? $ontOptical['voltage'] : ($opticalState['voltage'] ?? '')),
        'bias_current' => (string) (($ontOptical['bias_current'] ?? '') !== '' ? $ontOptical['bias_current'] : ($opticalState['bias_current'] ?? '')),
        'ont_tx' => (string) ($ontOptical['ont_tx'] ?? ''),
        'ont_rx' => (string) ($ontOptical['ont_rx'] ?? ''),
        'olt_rx' => (string) ($ontOptical['olt_rx'] ?? ''),
        'olt_tx' => (string) ($opticalState['olt_tx'] ?? ''),
        'olt_port_rx' => (string) ($opticalState['olt_port_rx'] ?? ''),
        'port_state' => (string) ($opticalState['port_state'] ?? ''),
        'last_up_time' => (string) ($ontInfo['last_up_time'] ?? ''),
        'last_down_time' => (string) ($ontInfo['last_down_time'] ?? ''),
        'uptime' => (string) ($ontInfo['uptime'] ?? ''),
        'vlan' => (string) ($outputs[$commands['ont_port_vlan'] ?? ''] ?? ''),
    ];

    return [
        'ok' => true,
        'device_key' => (string) ($device['device_key'] ?? ''),
        'device_name' => (string) ($device['device_name'] ?? ''),
        'device_ip' => (string) ($device['ip_address'] ?? ''),
        'pon' => $pon,
        'ont_id' => $ontId,
        'ont' => $ont,
        'commands' => $commands,
        'raw' => [
            'ont_info' => (string) ($outputs[$commands['ont_info_single']] ?? ''),
            'ont_optical' => (string) ($outputs[$commands['ont_optical_single']] ?? ''),
            'optical_state' => (string) ($outputs[$commands['optical_state']] ?? ''),
            'gpon_statistics' => isset($commands['gpon_statistics']) ? (string) ($outputs[$commands['gpon_statistics']] ?? '') : '',
            'ont_port_vlan' => isset($commands['ont_port_vlan']) ? (string) ($outputs[$commands['ont_port_vlan']] ?? '') : '',
        ],
        'steps' => is_array($run['steps'] ?? null) ? $run['steps'] : [],
        'final_prompt' => (string) ($run['final_prompt'] ?? ''),
    ];
}

function app_mgs_device_command_map(array $device, ?PDO $db = null, array $actor = []): array
{
    $authorization = app_noc_authorize($actor, 'read_device_sensitive', $device, $db);
    if (empty($authorization['ok'])) {
        return [];
    }
    $commandMap = [];
    if (strtoupper((string) ($device['vendor'] ?? '')) === 'HSGQ') {
        $commandMap = app_mgs_hsgq_default_command_map();
    }
    $commandMap = array_merge($commandMap, app_mgs_json_decode_array($device['command_map_json'] ?? null));
    $discoverySummary = app_mgs_latest_cli_discovery_summary($device, $db, $actor);
    $derivedCommandMap = is_array($discoverySummary['derived_command_map'] ?? null) ? $discoverySummary['derived_command_map'] : [];
    if ($derivedCommandMap !== []) {
        $commandMap = array_merge($commandMap, $derivedCommandMap);
    }

    return array_filter($commandMap, static fn ($value): bool => trim((string) $value) !== '');
}

function app_mgs_hsgq_default_command_map(): array
{
    return [
        'enter_enable' => 'enable',
        'enter_config' => 'configure',
        'enter_gpon_context' => 'interface gpon {pon}',
        'ont_restart' => 'ont reboot {ont}',
    ];
}

function app_mgs_render_command_template(string $template, array $vars = []): string
{
    $safeVars = [];
    foreach ($vars as $key => $value) {
        $safeVars['{' . trim((string) $key, '{}') . '}'] = trim((string) $value);
    }

    return trim(strtr($template, $safeVars));
}

function app_mgs_command_list_from_text(string $text): array
{
    $commands = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '' || str_starts_with($line, '!') || str_starts_with($line, '#')) {
            continue;
        }
        $commands[] = $line;
    }

    return array_values(array_unique($commands));
}

function app_mgs_execute_device_command_plan(array $device, array $commands, array $actor = [], ?PDO $db = null, array $options = []): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }
    $authorizationAction = clean_text((string) ($options['authorization_action'] ?? 'execute_device_command'));
    $authorization = app_noc_authorize($actor, $authorizationAction, $device, $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure($authorizationAction, $authorization);
    }
    if (!app_mgs_management_path_ready() && !empty($device['management_network']) && (string) $device['management_network'] !== 'public') {
        return ['ok' => false, 'reason' => 'management_path_down'];
    }

    $commands = app_mgs_command_list_from_text(implode("\n", array_map('strval', $commands)));
    if ($commands === []) {
        return ['ok' => false, 'reason' => 'missing_commands'];
    }

    $deviceKey = (string) ($device['device_key'] ?? '');
    $access = app_mgs_device_access_profile($device, $db, $actor, $authorizationAction);
    $sshUsername = trim((string) ($access['ssh_username'] ?? ''));
    $sshPassword = (string) ($access['ssh_password'] ?? '');
    if ($sshUsername === '' || $sshPassword === '') {
        return ['ok' => false, 'reason' => 'missing_ssh_credentials'];
    }

    $allowDangerous = !empty($options['allow_dangerous']);
    $dangerousWords = ['delete', 'remove', 'shutdown', 'disable', 'deactivate', 'reset', 'reboot', 'restart', 'clear', 'write erase', 'reload'];
    $dangerousHits = [];
    foreach ($commands as $command) {
        $lower = strtolower($command);
        foreach ($dangerousWords as $word) {
            if (str_contains($lower, $word)) {
                $dangerousHits[] = $word;
            }
        }
    }
    $dangerousHits = array_values(array_unique($dangerousHits));
    if ($dangerousHits !== [] && !$allowDangerous) {
        return [
            'ok' => false,
            'reason' => 'dangerous_command_requires_explicit_apply',
            'dangerous_tokens' => $dangerousHits,
        ];
    }

    // Maker-checker (vision 19.2 "router konfiqurasiyası"): gate on the
    // caller's own allow_dangerous signal, not just the dangerous-keyword
    // list above -- constructive config-push commands (VLAN create/bind,
    // ONT provisioning) don't contain words like "delete"/"shutdown" but are
    // exactly the kind of live-device write the vision doc worries about.
    // Every caller that sets allow_dangerous=true is already asserting "this
    // is a real device apply"; that intent alone is what stages here. Only
    // app_mgs_resolve_device_command_request() (a DIFFERENT admin) can let it
    // reach app_mgs_hsgq_exec_commands() below. Plain read commands never set
    // allow_dangerous and are completely unaffected. The post-approval
    // execution path sets _post_approval_execution to skip this gate exactly
    // once, for the request it was created to run.
    if ($allowDangerous && empty($options['_post_approval_execution'])) {
        return app_mgs_stage_device_command_request(
            $db,
            $device,
            $commands,
            $dangerousHits,
            (string) ($options['reason'] ?? ''),
            $actor,
            (string) ($options['action_type'] ?? 'device_command_apply'),
            $authorizationAction
        );
    }

    $run = app_mgs_hsgq_exec_commands(
        (string) ($device['ip_address'] ?? ''),
        $sshUsername,
        $sshPassword,
        $commands,
        (int) ($access['ssh_port'] ?? 22),
        (int) ($options['timeout_ms'] ?? 9000),
        $actor,
        $device,
        $db,
        $authorizationAction
    );

    $ok = !empty($run['ok']);
    app_mgs_record_operation_action([
        'action_type' => (string) ($options['action_type'] ?? 'device_command_apply'),
        'entity_type' => 'device_learning',
        'entity_id' => $deviceKey,
        'status' => $ok ? 'executed' : 'failed',
        'actor' => $actor,
        'summary' => ($ok ? 'Real device command executed: ' : 'Real device command failed: ') . (string) ($device['device_name'] ?? $deviceKey),
        'payload' => [
            'device_key' => $deviceKey,
            'device_name' => (string) ($device['device_name'] ?? ''),
            'device_ip' => (string) ($device['ip_address'] ?? ''),
            'commands' => $commands,
            'dangerous_tokens' => $dangerousHits,
            'result' => $run,
        ],
    ]);

    return [
        'ok' => $ok,
        'reason' => (string) ($run['reason'] ?? ''),
        'device_key' => $deviceKey,
        'device_name' => (string) ($device['device_name'] ?? ''),
        'device_ip' => (string) ($device['ip_address'] ?? ''),
        'commands' => $commands,
        'steps' => is_array($run['steps'] ?? null) ? $run['steps'] : [],
        'final_prompt' => (string) ($run['final_prompt'] ?? ''),
        'raw' => $run,
    ];
}

function app_mgs_stage_device_command_request(
    PDO $db,
    array $device,
    array $commands,
    array $dangerousTokens,
    string $reason,
    array $actor,
    string $actionType,
    string $authorizationAction
): array {
    $reason = mb_substr(clean_text($reason), 0, 255);
    if ($reason === '') {
        return ['ok' => false, 'reason' => 'reason_required'];
    }
    $deviceKey = (string) ($device['device_key'] ?? '');
    $insert = $db->prepare(
        'INSERT INTO mgs_device_command_requests (
            device_key, device_name, device_ip, commands_json, dangerous_tokens_json,
            action_type, authorization_action, reason, status, requested_by_account_id, requested_by_name
         ) VALUES (
            :device_key, :device_name, :device_ip, :commands_json, :dangerous_tokens_json,
            :action_type, :authorization_action, :reason, "pending", :actor_id, :actor_name
         )'
    );
    $insert->execute([
        'device_key' => $deviceKey,
        'device_name' => (string) ($device['device_name'] ?? ''),
        'device_ip' => (string) ($device['ip_address'] ?? ''),
        'commands_json' => app_db_json_encode($commands),
        'dangerous_tokens_json' => app_db_json_encode($dangerousTokens),
        'action_type' => $actionType,
        'authorization_action' => $authorizationAction,
        'reason' => $reason,
        'actor_id' => (int) ($actor['id'] ?? 0) ?: null,
        'actor_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'admin')),
    ]);
    $id = (int) $db->lastInsertId();

    app_mgs_record_operation_action([
        'action_type' => $actionType . '_requested',
        'entity_type' => 'device_learning',
        'entity_id' => $deviceKey,
        'status' => 'pending',
        'actor' => $actor,
        'summary' => 'Dangerous device command staged for approval: ' . (string) ($device['device_name'] ?? $deviceKey),
        'payload' => ['id' => $id, 'device_key' => $deviceKey, 'commands' => $commands, 'dangerous_tokens' => $dangerousTokens, 'reason' => $reason],
    ]);

    return [
        'ok' => false,
        'reason' => 'dangerous_command_staged_for_approval',
        'request_id' => $id,
        'dangerous_tokens' => $dangerousTokens,
    ];
}

function app_mgs_device_command_requests(PDO $db, string $status = 'pending'): array
{
    $statement = $db->prepare('SELECT * FROM mgs_device_command_requests WHERE status = :status ORDER BY created_at DESC LIMIT 50');
    $statement->execute(['status' => $status]);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function app_mgs_resolve_device_command_request(PDO $db, int $requestId, string $decision, string $resolutionNote, array $actor, ?PDO $deviceDb = null): array
{
    if (!app_portal_is_admin($actor)) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        return ['ok' => false, 'error' => 'invalid_decision'];
    }
    $resolutionNote = mb_substr(clean_text($resolutionNote), 0, 255);
    $actorId = (int) ($actor['id'] ?? 0);

    $requestStatement = $db->prepare('SELECT * FROM mgs_device_command_requests WHERE id = :id LIMIT 1');
    $requestStatement->execute(['id' => $requestId]);
    $request = $requestStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($request)) {
        return ['ok' => false, 'error' => 'request_not_found'];
    }
    if ((string) $request['status'] !== 'pending') {
        return ['ok' => false, 'error' => 'request_already_resolved'];
    }
    if ($actorId > 0 && $actorId === (int) ($request['requested_by_account_id'] ?? 0)) {
        return ['ok' => false, 'error' => 'requester_cannot_approve_own_request'];
    }

    $execResult = null;
    if ($decision === 'approved') {
        $deviceDb = $deviceDb instanceof PDO ? $deviceDb : $db;
        $device = app_mgs_device_learning_profile_by_key((string) $request['device_key'], $deviceDb, $actor);
        if (!is_array($device)) {
            return ['ok' => false, 'error' => 'device_not_found'];
        }
        $commands = app_mgs_json_decode_array($request['commands_json'] ?? []);
        $execResult = app_mgs_execute_device_command_plan($device, $commands, $actor, $deviceDb, [
            'allow_dangerous' => true,
            'action_type' => (string) $request['action_type'],
            'authorization_action' => (string) $request['authorization_action'],
            'reason' => (string) $request['reason'],
            '_post_approval_execution' => true,
        ]);
        if (empty($execResult['ok'])) {
            return ['ok' => false, 'error' => 'device_command_execution_failed', 'detail' => (string) ($execResult['reason'] ?? '')];
        }
    }

    try {
        $db->beginTransaction();
        $update = $db->prepare(
            "UPDATE mgs_device_command_requests SET status = :status, reviewed_by_account_id = :actor_id,
                reviewed_by_name = :actor_name, resolution_note = :resolution_note, result_json = :result_json, reviewed_at = NOW()
             WHERE id = :id AND status = 'pending'"
        );
        $update->execute([
            'status' => $decision,
            'actor_id' => $actorId ?: null,
            'actor_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'admin')),
            'resolution_note' => $resolutionNote ?: null,
            'result_json' => $execResult !== null ? app_db_json_encode($execResult) : null,
            'id' => $requestId,
        ]);
        if ($update->rowCount() === 0) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'request_already_resolved'];
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'request_resolve_failed', 'message' => $exception->getMessage()];
    }

    return ['ok' => true, 'status' => $decision, 'exec_result' => $execResult];
}

function app_mgs_hsgq_ont_control_plan(
    array $device,
    string $operation,
    int|string $pon,
    int|string $ontId,
    ?PDO $db = null,
    array $actor = []
): array
{
    $authorization = app_noc_authorize($actor, 'execute_ont_control', $device, $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('execute_ont_control', $authorization);
    }
    $operation = strtolower(trim($operation));
    $aliases = [
        'restart' => 'ont_restart',
        'reboot' => 'ont_restart',
        'reonline' => 'ont_reonline',
        'enable' => 'ont_enable',
        'start' => 'ont_enable',
        'disable' => 'ont_disable',
        'shutdown' => 'ont_disable',
        'delete' => 'ont_delete',
        'remove' => 'ont_delete',
    ];
    $key = $aliases[$operation] ?? $operation;
    $allowedKeys = ['ont_restart', 'ont_reonline', 'ont_enable', 'ont_disable', 'ont_delete'];
    if (!in_array($key, $allowedKeys, true)) {
        return ['ok' => false, 'reason' => 'unsupported_ont_operation'];
    }

    $pon = trim((string) $pon);
    $ontId = trim((string) $ontId);
    if ($pon === '' || $ontId === '') {
        return ['ok' => false, 'reason' => 'missing_pon_or_ont'];
    }

    $commandMap = app_mgs_device_command_map($device, $db, $actor);
    $commands = [];
    foreach (['enter_enable', 'enter_config', 'enter_gpon_context', $key] as $templateKey) {
        $template = trim((string) ($commandMap[$templateKey] ?? ''));
        if ($template === '') {
            return [
                'ok' => false,
                'reason' => 'missing_command_map:' . $templateKey,
                'required_key' => $templateKey,
                'operation' => $key,
            ];
        }
        $commands[] = app_mgs_render_command_template($template, ['pon' => $pon, 'ont' => $ontId]);
    }

    return [
        'ok' => true,
        'operation' => $key,
        'pon' => $pon,
        'ont_id' => $ontId,
        'device_key' => (string) ($device['device_key'] ?? ''),
        'commands' => $commands,
        'requires_dangerous_apply' => in_array($key, ['ont_restart', 'ont_reonline', 'ont_disable', 'ont_delete'], true),
    ];
}

function app_mgs_execute_hsgq_ont_control(array $device, string $operation, int|string $pon, int|string $ontId, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    $authorization = app_noc_authorize($actor, 'execute_ont_control', $device, $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('execute_ont_control', $authorization);
    }
    $plan = app_mgs_hsgq_ont_control_plan($device, $operation, $pon, $ontId, $db, $actor);
    if (empty($plan['ok'])) {
        app_mgs_record_operation_action([
            'action_type' => 'ont_control_apply',
            'entity_type' => 'ont',
            'entity_id' => (string) ($device['device_key'] ?? '') . '/' . trim((string) $pon) . '/' . trim((string) $ontId),
            'status' => 'failed',
            'actor' => $actor,
            'summary' => 'ONT control command map missing',
            'payload' => $plan,
        ]);
        return $plan;
    }

    $result = app_mgs_execute_device_command_plan($device, (array) $plan['commands'], $actor, $db, [
        'allow_dangerous' => true,
        'authorization_action' => 'execute_ont_control',
        'action_type' => 'ont_control_apply',
        'timeout_ms' => 11000,
    ]);
    $result['operation'] = (string) ($plan['operation'] ?? $operation);
    $result['pon'] = (string) ($plan['pon'] ?? $pon);
    $result['ont_id'] = (string) ($plan['ont_id'] ?? $ontId);

    return $result;
}

function app_mgs_device_name_fingerprint(string $value): string
{
    $value = app_repair_mojibake_dynamic(app_repair_mojibake(trim($value)));
    // Device labels often use ASCII (Kurdexani) while CRM areas are stored
    // with Azerbaijani characters (Kürdəxanı). Normalize both forms before
    // matching an OLT to its service area.
    $value = strtr($value, [
        'Ə' => 'E', 'ə' => 'e', 'Ü' => 'U', 'ü' => 'u', 'Ö' => 'O', 'ö' => 'o',
        'Ş' => 'S', 'ş' => 's', 'Ç' => 'C', 'ç' => 'c', 'Ğ' => 'G', 'ğ' => 'g',
        'İ' => 'I', 'ı' => 'i',
    ]);
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '', $value);
    return (string) $value;
}

function app_mgs_hsgq_device_by_olt_name(
    string $oltName,
    ?PDO $db = null,
    array $actor = [],
    string $purposeAction = 'read_device'
): ?array
{
    $needle = app_mgs_device_name_fingerprint($oltName);
    if ($needle === '') {
        return null;
    }

    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return null;
    }
    $authorization = app_noc_authorize($actor, $purposeAction, [], $db);
    if (empty($authorization['ok'])) {
        return null;
    }

    $statement = $db->query(
        'SELECT *
           FROM device_learning_profiles
          WHERE device_type = "olt"
            AND vendor = "HSGQ"
            AND monitoring_enabled = 1
          ORDER BY id'
    );
    $rows = $statement !== false ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    if ($purposeAction !== 'read_ont_live') {
        $rows = app_noc_filter_objects($rows, $actor, $db);
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $candidates = [
            (string) ($row['device_name'] ?? ''),
            (string) ($row['device_key'] ?? ''),
            (string) ($row['ip_address'] ?? ''),
        ];
        foreach ($candidates as $candidate) {
            if (app_mgs_device_name_fingerprint($candidate) === $needle) {
                $objectAuthorization = app_noc_authorize($actor, $purposeAction, $row, $db);
                return !empty($objectAuthorization['ok']) ? $row : null;
            }
        }
    }

    return null;
}

function app_mgs_search_text_lower(string $value): string
{
    $value = app_repair_mojibake_dynamic(app_repair_mojibake($value));
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function app_mgs_search_text_matches(string $query, array $parts): bool
{
    $needle = app_mgs_search_text_lower(trim($query));
    if ($needle === '') {
        return false;
    }

    $haystack = [];
    foreach ($parts as $part) {
        if ($part === null) {
            continue;
        }
        $text = trim((string) $part);
        if ($text !== '') {
            $haystack[] = $text;
        }
    }

    return str_contains(app_mgs_search_text_lower(implode(' ', $haystack)), $needle);
}

function app_mgs_search_normalize_mac(string $value): string
{
    $normalized = preg_replace('/[^A-Fa-f0-9]/', '', $value);
    return strtoupper((string) $normalized);
}

function app_mgs_search_profile_field(array $row, array $keys, string $default = '-'): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row) || $row[$key] === null) {
            continue;
        }
        $value = trim((string) $row[$key]);
        if ($value !== '') {
            return $value;
        }
    }

    return $default;
}

function app_mgs_modem_pick_service_row(array $services, array $ont, array $billing): array
{
    $ontMac = app_mgs_search_normalize_mac(app_mgs_search_profile_field($ont, ['mac', 'mac_address'], ''));
    if ($ontMac !== '') {
        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }
            $serviceMac = app_mgs_search_normalize_mac((string) ($service['mac'] ?? ''));
            if ($serviceMac !== '' && $serviceMac === $ontMac) {
                return $service;
            }
        }
    }

    return is_array($services[0] ?? null) ? $services[0] : $billing;
}

function app_mgs_modem_search_accounts(PDO $db, string $query, int $limit = 40): array
{
    $query = app_mgs_search_text_lower($query);
    if ($query === '') {
        return [];
    }

    $limit = max(1, min(200, $limit));
    $statement = $db->prepare(
        'SELECT *
           FROM portal_accounts
          WHERE role = :role
            AND (
                LOWER(full_name) LIKE :q
                OR LOWER(login) LIKE :q
                OR LOWER(subscriber_code) LIKE :q
                OR LOWER(phone) LIKE :q
                OR LOWER(address) LIKE :q
                OR LOWER(payload_json) LIKE :q
            )
          ORDER BY updated_at DESC, id DESC
          LIMIT ' . $limit
    );
    $statement->execute([
        'role' => 'customer',
        'q' => '%' . $query . '%',
    ]);

    return array_values(array_filter($statement->fetchAll(PDO::FETCH_ASSOC) ?: [], 'is_array'));
}

function app_mgs_modem_search_candidates(string $query, int $limit = 8, ?PDO $db = null, array $actor = []): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }
    $authorization = app_noc_authorize($actor, 'read_ont_live', [], $db);
    if (empty($authorization['ok'])) {
        return [];
    }

    $results = [];
    $seen = [];
    $accounts = app_mgs_modem_search_accounts($db, $query, max($limit * 3, 20));
    foreach ($accounts as $account) {
        if (!is_array($account)) {
            continue;
        }

        $snapshot = app_db_account_payload($account);
        $billing = is_array($snapshot['billing'] ?? null) ? $snapshot['billing'] : [];
        $services = is_array($snapshot['internet_services'] ?? null) ? array_values(array_filter($snapshot['internet_services'], 'is_array')) : [];
        $onts = is_array($snapshot['ont_profiles'] ?? null) ? array_values(array_filter($snapshot['ont_profiles'], 'is_array')) : [];
        $primaryService = is_array($services[0] ?? null) ? $services[0] : [];
        if ($onts === []) {
            $onts = [[
                'olt' => (string) ($primaryService['olt'] ?? $billing['olt_name'] ?? ''),
                'pon' => (string) ($primaryService['pon'] ?? $primaryService['pon_port'] ?? $billing['pon_port'] ?? ''),
                'ont_id' => (string) ($primaryService['ont_id'] ?? $billing['ont_id'] ?? ''),
                'serial' => (string) ($primaryService['serial'] ?? $primaryService['sn'] ?? $billing['ont_serial'] ?? ''),
                'mac' => (string) ($primaryService['mac'] ?? $billing['mac'] ?? ''),
                'status' => (string) ($billing['status'] ?? $account['status'] ?? ''),
            ]];
        }

        foreach ($onts as $ont) {
            $service = app_mgs_modem_pick_service_row($services, $ont, $billing);
            $subscriberCode = (string) ($account['subscriber_code'] ?? '');
            $row = [
                'account_id' => (int) ($account['id'] ?? 0),
                'subscriber_code' => $subscriberCode,
                'full_name' => (string) ($account['full_name'] ?? ''),
                'phone' => (string) ($account['phone'] ?? ''),
                'area' => (string) ($account['area'] ?? ''),
                'address' => (string) ($account['address'] ?? ''),
                'service_type' => (string) ($service['service_type'] ?? $billing['service_type'] ?? '-'),
                'username' => (string) ($service['username'] ?? $billing['pppoe_username'] ?? $subscriberCode),
                'assigned_ip' => (string) ($service['assigned_ip'] ?? $service['ip_address'] ?? $billing['assigned_ip'] ?? $billing['ip_address'] ?? ''),
                'nat_public_ip' => (string) ($service['nat_public_ip'] ?? $billing['nat_public_ip'] ?? ''),
                'static_ip' => (string) ($service['static_ip'] ?? $billing['static_ip'] ?? ''),
                'olt' => app_mgs_search_profile_field($ont, ['olt', 'olt_name', 'device_name'], '-'),
                'pon' => app_mgs_search_profile_field($ont, ['pon', 'pon_port'], '-'),
                'ont_id' => app_mgs_search_profile_field($ont, ['ont_id', 'ont'], '-'),
                'serial' => app_mgs_search_profile_field($ont, ['serial', 'sn'], '-'),
                'mac' => app_mgs_search_profile_field($ont, ['mac', 'mac_address'], '-'),
                'temperature' => app_mgs_search_profile_field($ont, ['temperature', 'temp'], '-'),
                'ont_rx' => app_mgs_search_profile_field($ont, ['rx_power', 'ont_rx', 'rx'], '-'),
                'ont_tx' => app_mgs_search_profile_field($ont, ['tx_power', 'ont_tx', 'tx'], '-'),
                'olt_rx' => app_mgs_search_profile_field($ont, ['olt_rx', 'olt_rx_power'], '-'),
                'olt_tx' => app_mgs_search_profile_field($ont, ['olt_tx', 'olt_tx_power'], '-'),
                'wifi_ssid' => app_mgs_search_profile_field($ont, ['wifi_ssid', 'ssid'], '-'),
                'wifi_password' => app_mgs_search_profile_field($ont, ['wifi_password', 'wifi_pass'], '-'),
                'cable_meter' => app_mgs_search_profile_field($ont, ['cable_meter', 'cable_meters', 'drop_cable_meter'], '-'),
                'fast_connector' => app_mgs_search_profile_field($ont, ['fast_connector', 'connector'], '-'),
                'ont_status' => app_mgs_search_profile_field($ont, ['status'], (string) ($account['status'] ?? '-')),
            ];

            if (!app_mgs_search_text_matches($query, $row)) {
                continue;
            }

            $dedupKey = implode('|', [
                $subscriberCode,
                $row['serial'],
                app_mgs_search_normalize_mac($row['mac']),
                $row['pon'],
                $row['ont_id'],
            ]);
            if (isset($seen[$dedupKey])) {
                continue;
            }
            $seen[$dedupKey] = true;

            $username = trim((string) $row['username']);
            if ($username !== '') {
                $sessionStatement = $db->prepare(
                    'SELECT username, framedipaddress, nasipaddress, nasportid, callingstationid,
                            acctstarttime, COALESCE(acctupdatetime, acctstarttime) AS last_seen_at,
                            acctinputoctets, acctoutputoctets
                       FROM radacct
                      WHERE username = :username
                        AND acctstoptime IS NULL AND COALESCE(acctupdatetime, acctstarttime) >= DATE_SUB(NOW(), INTERVAL 20 MINUTE)
                      ORDER BY COALESCE(acctupdatetime, acctstarttime) DESC, radacctid DESC
                      LIMIT 1'
                );
                $sessionStatement->execute(['username' => $username]);
                $session = $sessionStatement->fetch(PDO::FETCH_ASSOC);
                if (is_array($session)) {
                    $row['radius_online'] = true;
                    $row['radius_status'] = 'active';
                    $row['session_ip'] = (string) ($session['framedipaddress'] ?? '');
                    $row['session_nas'] = (string) ($session['nasipaddress'] ?? '');
                    $row['session_port'] = (string) ($session['nasportid'] ?? '');
                    $row['session_mac'] = (string) ($session['callingstationid'] ?? '');
                    $row['session_started_at'] = (string) ($session['acctstarttime'] ?? '');
                    $row['session_last_seen_at'] = (string) ($session['last_seen_at'] ?? '');
                    $row['session_download'] = (float) ($session['acctoutputoctets'] ?? 0);
                    $row['session_upload'] = (float) ($session['acctinputoctets'] ?? 0);
                }
            }

            $serviceStatement = $db->prepare(
                'SELECT status, expires_at
                   FROM billing_radius_services
                  WHERE account_id = :account_id
                  ORDER BY FIELD(status, "active", "suspended", "expired", "disabled"), id DESC
                  LIMIT 1'
            );
            $serviceStatement->execute(['account_id' => (int) $row['account_id']]);
            $serviceStatus = $serviceStatement->fetch(PDO::FETCH_ASSOC);
            if (is_array($serviceStatus)) {
                $row['radius_status'] = (string) ($serviceStatus['status'] ?? ($row['radius_status'] ?? ''));
                $row['service_expires_at'] = (string) ($serviceStatus['expires_at'] ?? '');
            }

            $ticketStatement = $db->prepare(
                'SELECT ticket_no, subject, status, opened_at
                   FROM portal_tickets
                  WHERE customer_account_id = :account_id
                  ORDER BY id DESC
                  LIMIT 1'
            );
            $ticketStatement->execute(['account_id' => (int) $row['account_id']]);
            $ticket = $ticketStatement->fetch(PDO::FETCH_ASSOC);
            $openCountStatement = $db->prepare(
                'SELECT COUNT(*)
                   FROM portal_tickets
                  WHERE customer_account_id = :account_id
                    AND status NOT IN ("closed", "resolved", "Baglandi")'
            );
            $openCountStatement->execute(['account_id' => (int) $row['account_id']]);
            $row['open_ticket_count'] = (int) ($openCountStatement->fetchColumn() ?: 0);
            if (is_array($ticket)) {
                $row['last_ticket_no'] = (string) ($ticket['ticket_no'] ?? '');
                $row['last_ticket_subject'] = (string) ($ticket['subject'] ?? '');
                $row['last_ticket_status'] = (string) ($ticket['status'] ?? '');
                $row['last_ticket_opened_at'] = (string) ($ticket['opened_at'] ?? '');
            }

            $candidateActor = $actor;
            if (!empty($candidateActor['ticket_resolution_scope'])) {
                $candidateActor['ticket_resolution_device'] = (string) $row['olt'];
            }
            $device = app_mgs_hsgq_device_by_olt_name(
                (string) $row['olt'],
                $db,
                $candidateActor,
                'read_ont_live'
            );
            $row['device_key'] = (string) ($device['device_key'] ?? '');
            $row['device_ip'] = (string) ($device['ip_address'] ?? '');
            $row['live_capable'] = is_array($device) && app_mgs_management_path_ready();
            $results[] = $row;
            if (count($results) >= $limit) {
                return $results;
            }
        }
    }

    return $results;
}

function app_mgs_parse_hsgq_ont_info_all_rows(string $text): array
{
    $rows = [];
    $text = app_repair_mojibake_dynamic(app_repair_mojibake($text));
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '' || !preg_match('/^\d+\/\d+\s+/', $line)) {
            continue;
        }
        $tokens = preg_split('/\s+/', $line) ?: [];
        if (count($tokens) < 7) {
            continue;
        }
        $ponOnt = array_shift($tokens);
        [$pon, $ontId] = array_pad(explode('/', (string) $ponOnt, 2), 2, '');
        $serial = (string) array_shift($tokens);
        $state = (string) array_shift($tokens);
        $runState = (string) array_shift($tokens);
        $configState = (string) array_shift($tokens);
        $matchState = (string) array_shift($tokens);
        $ontName = (string) array_pop($tokens);
        $lastDownReason = trim(implode(' ', $tokens));
        $rows[] = [
            'pon' => $pon,
            'ont_id' => $ontId,
            'serial' => $serial,
            'state' => $state,
            'run_state' => $runState,
            'config_state' => $configState,
            'match_state' => $matchState,
            'last_down_reason' => $lastDownReason,
            'ont_name' => $ontName,
            'line' => $line,
        ];
    }

    return $rows;
}

/**
 * Parse the bounded HSGQ "show ont-optical all" table.  The device prints
 * PON/ONU, serial, temperature, voltage, bias, ONT TX, ONT RX and (on newer
 * firmware) the receive level observed by the OLT.  Raw CLI output is never
 * returned to agent callers; only these typed telemetry fields leave this
 * parser.
 */
function app_mgs_parse_hsgq_ont_optical_all_rows(string $text): array
{
    $rows = [];
    $text = app_repair_mojibake_dynamic(app_repair_mojibake($text));
    $text = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $text);
    foreach (preg_split('/\r?\n/', (string) $text) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '' || str_starts_with($line, 'PON/ONU') || str_starts_with($line, 'OLT(')) {
            continue;
        }
        if (preg_match(
            '/^(\d+\/\d+)\s+(\S+)\s+(-?\d+(?:\.\d+)?)\s+C\s+([\d.]+)\s+V\s+([\d.]+)\s+mA\s+([+-]?[\d.]+)\s+dBm\s+([+-]?[\d.]+)\s+dBm(?:\s+([+-]?[\d.]+)\s+dBm)?(?:\s+(\S+))?$/i',
            $line,
            $match
        ) !== 1) {
            continue;
        }
        [$pon, $ontId] = array_pad(explode('/', (string) $match[1], 2), 2, '');
        $rows[] = [
            'pon' => $pon,
            'ont_id' => $ontId,
            'serial' => trim((string) $match[2]),
            'temperature' => (float) $match[3],
            'voltage' => (float) $match[4],
            'bias_current' => (float) $match[5],
            'ont_tx' => (float) $match[6],
            'ont_rx' => (float) $match[7],
            'olt_rx' => isset($match[8]) && $match[8] !== '' ? (float) $match[8] : null,
            'name' => (string) ($match[9] ?? ''),
        ];
    }

    return $rows;
}

/**
 * Read the complete ONT inventory and optical table from one registered HSGQ
 * OLT.  This is a read-only operation: only fixed show commands are sent and
 * no device or database configuration is changed.
 */
function app_mgs_hsgq_live_ont_inventory(array $device, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    $authorization = app_noc_authorize($actor, 'read_ont_live', $device, $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('read_ont_live', $authorization);
    }
    if (!app_mgs_management_path_ready()) {
        return ['ok' => false, 'reason' => 'management_path_down'];
    }
    if (strtoupper((string) ($device['vendor'] ?? '')) !== 'HSGQ') {
        return ['ok' => false, 'reason' => 'unsupported_vendor'];
    }

    $access = app_mgs_device_access_profile($device, $db, $actor, 'read_ont_live');
    $username = trim((string) ($access['ssh_username'] ?? ''));
    $password = (string) ($access['ssh_password'] ?? '');
    if ($username === '' || $password === '') {
        return ['ok' => false, 'reason' => 'missing_ssh_credentials'];
    }

    $map = app_mgs_device_command_map($device, $db, $actor);
    $commands = [
        trim((string) ($map['enter_enable'] ?? 'enable')),
        trim((string) ($map['enter_config'] ?? 'configure')),
        trim((string) ($map['ont_info_all'] ?? 'show ont-info all')),
        trim((string) ($map['ont_optical_all'] ?? 'show ont-optical all')),
    ];
    $commands = array_values(array_filter($commands, static fn (string $command): bool => $command !== ''));
    $run = app_mgs_hsgq_exec_commands(
        (string) ($device['ip_address'] ?? ''),
        $username,
        $password,
        $commands,
        (int) ($access['ssh_port'] ?? 22),
        12000,
        $actor,
        $device,
        $db,
        'read_ont_live'
    );
    if (empty($run['ok'])) {
        return ['ok' => false, 'reason' => (string) ($run['reason'] ?? 'command_probe_failed')];
    }

    $outputs = is_array($run['outputs'] ?? null) ? $run['outputs'] : [];
    $infoCommand = $commands[count($commands) - 2] ?? 'show ont-info all';
    $opticalCommand = $commands[count($commands) - 1] ?? 'show ont-optical all';
    $infoRows = app_mgs_parse_hsgq_ont_info_all_rows((string) ($outputs[$infoCommand] ?? ''));
    $opticalRows = app_mgs_parse_hsgq_ont_optical_all_rows((string) ($outputs[$opticalCommand] ?? ''));

    $byPath = [];
    foreach ($infoRows as $row) {
        $path = trim((string) ($row['pon'] ?? '')) . '/' . trim((string) ($row['ont_id'] ?? ''));
        if ($path === '/') {
            continue;
        }
        $runState = strtolower(trim((string) ($row['run_state'] ?? '')));
        $adminState = strtolower(trim((string) ($row['state'] ?? '')));
        $byPath[$path] = [
            'pon' => (string) ($row['pon'] ?? ''),
            'ont_id' => (string) ($row['ont_id'] ?? ''),
            'serial' => (string) ($row['serial'] ?? ''),
            'status' => $runState === 'online' ? 'online' : ($adminState === 'inactive' ? 'disabled' : 'offline'),
            'name' => (string) ($row['ont_name'] ?? ''),
        ];
    }
    foreach ($opticalRows as $row) {
        $path = trim((string) ($row['pon'] ?? '')) . '/' . trim((string) ($row['ont_id'] ?? ''));
        if ($path === '/') {
            continue;
        }
        $byPath[$path] = array_replace($byPath[$path] ?? [
            'pon' => (string) ($row['pon'] ?? ''),
            'ont_id' => (string) ($row['ont_id'] ?? ''),
            'status' => 'unknown',
        ], array_filter($row, static fn ($value): bool => $value !== '' && $value !== null));
    }

    ksort($byPath, SORT_NATURAL);
    return [
        'ok' => true,
        'checked_at' => date(DATE_ATOM),
        'device_key' => (string) ($device['device_key'] ?? ''),
        'device_name' => (string) ($device['device_name'] ?? ''),
        'device_ip' => (string) ($device['ip_address'] ?? ''),
        'onts_total' => count($byPath),
        'optical_rows' => count($opticalRows),
        'rows' => array_values($byPath),
    ];
}

function app_mgs_hsgq_live_find_ont_by_query(string $query, ?PDO $db = null, int $maxPon = 8, array $actor = [], ?string &$reason = null): ?array
{
    $reason = null;
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        $reason = 'db_unavailable';
        return null;
    }
    if (!app_mgs_management_path_ready()) {
        $reason = 'management_path_down';
        return null;
    }
    $authorization = app_noc_authorize($actor, 'read_ont_live', [], $db);
    if (empty($authorization['ok'])) {
        return null;
    }

    $query = trim($query);
    if ($query === '') {
        $reason = 'empty_query';
        return null;
    }
    $needle = app_mgs_search_text_lower($query);
    $needleCompact = preg_replace('/[^a-z0-9]/', '', $needle) ?? '';

    $statement = $db->query(
        'SELECT *
           FROM device_learning_profiles
          WHERE device_type = "olt"
            AND vendor = "HSGQ"
            AND monitoring_enabled = 1
          ORDER BY id'
    );
    $devices = $statement !== false ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $devices = app_noc_filter_objects($devices, $actor, $db);
    if ($devices === []) {
        $reason = 'no_olt_profile';
        return null;
    }
    $credentialed = 0;
    $reachable = 0;
    foreach ($devices as $device) {
        if (!is_array($device)) {
            continue;
        }
        $access = app_mgs_device_access_profile($device, $db, $actor, 'read_ont_live');
        if (trim((string) ($access['ssh_username'] ?? '')) === '' || (string) ($access['ssh_password'] ?? '') === '') {
            continue;
        }
        $credentialed++;
        for ($pon = 1; $pon <= max(1, $maxPon); $pon++) {
            $run = app_mgs_hsgq_exec_commands(
                (string) ($device['ip_address'] ?? ''),
                (string) ($access['ssh_username'] ?? ''),
                (string) ($access['ssh_password'] ?? ''),
                ['enable', 'configure', 'interface gpon ' . $pon, 'show ont-info all'],
                (int) ($access['ssh_port'] ?? 22),
                6500,
                $actor,
                $device,
                $db,
                'read_ont_live'
            );
            if (empty($run['ok'])) {
                continue;
            }
            $reachable++;
            $table = (string) (($run['outputs']['show ont-info all'] ?? ''));
            foreach (app_mgs_parse_hsgq_ont_info_all_rows($table) as $row) {
                $compactSerial = preg_replace('/[^a-z0-9]/', '', app_mgs_search_text_lower((string) ($row['serial'] ?? ''))) ?? '';
                $serialHit = $needleCompact !== '' && strlen($needleCompact) >= 6 && $compactSerial !== '' && str_contains($compactSerial, $needleCompact);
                if ($serialHit || app_mgs_search_text_matches($needle, [$row['serial'] ?? '', $row['ont_name'] ?? '', $row['line'] ?? '', $row['pon'] . '/' . $row['ont_id']])) {
                    $snapshot = app_mgs_hsgq_live_ont_snapshot($device, (string) ($row['pon'] ?? ''), (string) ($row['ont_id'] ?? ''), $actor, $db);
                    if (!empty($snapshot['ok'])) {
                        $snapshot['live_lookup'] = $row;
                        return $snapshot;
                    }
                }
            }
        }
    }

    $reason = $credentialed === 0 ? 'no_ssh_credentials' : ($reachable === 0 ? 'olt_unreachable' : 'not_on_olt');

    return null;
}

function app_mgs_live_lookup_reason_text(string $reason): string
{
    return match ($reason) {
        'management_path_down' => 'OLT-e canli sorgu bagli: idareetme yolu (VLAN 550) sistemde "qosulub" kimi isharelenmeyib. NOC > Idareetme yolu bolmesinden aktiv edin.',
        'no_olt_profile' => 'Monitorinq aktiv HSGQ OLT profili tapilmadi.',
        'no_ssh_credentials' => 'OLT profillerinde SSH istifadeci/parol yoxdur.',
        'olt_unreachable' => 'OLT-lere SSH ile qosulmaq alinmadi (IP, port ve ya parolu yoxlayin).',
        'not_on_olt' => 'OLT-lerde bu SN/ad tapilmadi. MAC ile axtaris yalniz bazadaki melumata gore isleyir.',
        'db_unavailable' => 'Baza hazir deyil.',
        default => '',
    };
}

function app_mgs_build_modem_search_reply(string $query, bool $preferLive = true, ?PDO $db = null, array $actor = []): array
{
    $db = $db instanceof PDO ? $db : app_db();
    $authorization = app_noc_authorize($actor, 'read_ont_live', [], $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('read_ont_live', $authorization);
    }
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }

    $query = trim($query);
    if ($query === '') {
        return ['ok' => false, 'reason' => 'empty_query'];
    }

    $candidates = app_mgs_modem_search_candidates($query, 3, $db, $actor);
    $liveOnly = null;
    if ($candidates === [] && $preferLive) {
        if (!empty($actor['ticket_resolution_scope'])) {
            return ['ok' => false, 'reason' => 'customer_ont_not_linked'];
        }
        $liveFailReason = null;
        $liveOnly = app_mgs_hsgq_live_find_ont_by_query($query, $db, 8, $actor, $liveFailReason);
        if ($liveOnly === null) {
            $hint = app_mgs_live_lookup_reason_text((string) $liveFailReason);
            return [
                'ok' => false,
                'reason' => 'not_found',
                'live_reason' => (string) $liveFailReason,
                'text' => 'Bazada tapilmadi.' . ($hint !== '' ? ' ' . $hint : ''),
            ];
        }
        $candidate = [
            'account_id' => 0,
            'subscriber_code' => '-',
            'full_name' => 'Live OLT tapildi',
            'phone' => '-',
            'area' => '-',
            'address' => '-',
            'service_type' => '-',
            'username' => '-',
            'assigned_ip' => '-',
            'nat_public_ip' => '-',
            'static_ip' => '-',
            'olt' => (string) ($liveOnly['device_name'] ?? '-'),
            'pon' => (string) ($liveOnly['pon'] ?? '-'),
            'ont_id' => (string) ($liveOnly['ont_id'] ?? '-'),
            'serial' => (string) ($liveOnly['ont']['serial'] ?? '-'),
            'mac' => '-',
            'temperature' => (string) ($liveOnly['ont']['temperature'] ?? '-'),
            'ont_rx' => (string) ($liveOnly['ont']['ont_rx'] ?? '-'),
            'ont_tx' => (string) ($liveOnly['ont']['ont_tx'] ?? '-'),
            'olt_rx' => (string) ($liveOnly['ont']['olt_rx'] ?? '-'),
            'olt_tx' => (string) ($liveOnly['ont']['olt_tx'] ?? '-'),
            'wifi_ssid' => '-',
            'wifi_password' => '-',
            'cable_meter' => '-',
            'fast_connector' => '-',
            'ont_status' => (string) ($liveOnly['ont']['status'] ?? '-'),
            'radius_online' => false,
            'radius_status' => 'unknown',
            'open_ticket_count' => 0,
            'device_key' => (string) ($liveOnly['device_key'] ?? ''),
            'device_ip' => (string) ($liveOnly['device_ip'] ?? ''),
            'live_capable' => true,
        ];
    } else {
        $candidate = $candidates[0];
    }

    $live = null;
    $liveReason = ($preferLive && !app_mgs_management_path_ready()) ? 'management_path_down' : '';
    if ($preferLive && $liveOnly !== null) {
        $live = $liveOnly;
    } elseif ($preferLive && !empty($candidate['live_capable'])) {
        $deviceActor = $actor;
        $deviceActor['ticket_resolution_device'] = (string) ($candidate['olt'] ?? '');
        $device = app_mgs_hsgq_device_by_olt_name(
            (string) ($candidate['olt'] ?? ''),
            $db,
            $deviceActor,
            'read_ont_live'
        );
        if (is_array($device)) {
            $liveResult = app_mgs_hsgq_live_ont_snapshot(
                $device,
                (string) ($candidate['pon'] ?? ''),
                (string) ($candidate['ont_id'] ?? ''),
                $deviceActor,
                $db
            );
            if (!empty($liveResult['ok'])) {
                $live = $liveResult;
            } else {
                $liveReason = (string) ($liveResult['reason'] ?? '');
            }
        } else {
            $liveReason = 'device_profile_not_found';
        }
    }

    $liveOnt = is_array($live['ont'] ?? null) ? $live['ont'] : [];
    if ($liveOnt !== [] && (int) ($candidate['account_id'] ?? 0) > 0) {
        app_mgs_store_live_ont_snapshot((int) $candidate['account_id'], $candidate, $liveOnt, $db);
    }
    $ontRx = (string) (($liveOnt['ont_rx'] ?? '') !== '' ? $liveOnt['ont_rx'] : ($candidate['ont_rx'] ?? '-'));
    $ontTx = (string) (($liveOnt['ont_tx'] ?? '') !== '' ? $liveOnt['ont_tx'] : ($candidate['ont_tx'] ?? '-'));
    $oltRx = (string) (($liveOnt['olt_rx'] ?? '') !== '' ? $liveOnt['olt_rx'] : ($candidate['olt_rx'] ?? '-'));
    $oltTx = (string) (($liveOnt['olt_tx'] ?? '') !== '' ? $liveOnt['olt_tx'] : ($candidate['olt_tx'] ?? '-'));
    $temperature = (string) (($liveOnt['temperature'] ?? '') !== '' ? $liveOnt['temperature'] : ($candidate['temperature'] ?? '-'));
    $radiusText = !empty($candidate['radius_online'])
        ? 'online'
        : ('status ' . ((string) ($candidate['radius_status'] ?? '') !== '' ? (string) $candidate['radius_status'] : 'offline'));
    $ticketText = ((int) ($candidate['open_ticket_count'] ?? 0) > 0)
        ? ((int) $candidate['open_ticket_count']) . ' aciq'
        : (((string) ($candidate['last_ticket_no'] ?? '') !== '' ? 'son: ' . (string) $candidate['last_ticket_no'] : 'yox'));

    $lines = [
        'MODEM AXTARIS NETICESI',
        'Sorgu: ' . $query,
        '',
        'Musteri: ' . (string) ($candidate['full_name'] ?? '-'),
        'Kod / username: ' . (string) ($candidate['subscriber_code'] ?? '-') . ' / ' . (string) ($candidate['username'] ?? '-'),
        'OLT / PON / ONT: ' . (string) ($candidate['olt'] ?? '-') . ' / ' . (string) ($candidate['pon'] ?? '-') . ' / ' . (string) ($candidate['ont_id'] ?? '-'),
        'SN / MAC: ' . (string) ($candidate['serial'] ?? '-') . ' / ' . (string) ($candidate['mac'] ?? '-'),
        'ONT RX / TX: ' . $ontRx . ' / ' . $ontTx,
        'OLT RX / TX: ' . $oltRx . ' / ' . $oltTx,
        'Temperatur: ' . $temperature . ' C',
        'IP / NAT / Static: ' . (string) ($candidate['assigned_ip'] ?? '-') . ' / ' . (string) ($candidate['nat_public_ip'] ?? '-') . ' / ' . (string) ($candidate['static_ip'] ?? '-'),
        'Wi-Fi: ' . (string) ($candidate['wifi_ssid'] ?? '-') . ' / ' . (string) ($candidate['wifi_password'] ?? '-'),
        'Kabel / connector: ' . (string) ($candidate['cable_meter'] ?? '-') . ' m / ' . (string) ($candidate['fast_connector'] ?? '-'),
        'RADIUS: ' . $radiusText,
        'Ticket: ' . $ticketText,
    ];
    if ($live !== null) {
        $lines[] = 'Live OLT query: ok';
        $lines[] = 'Live voltage / bias: ' . (string) ($liveOnt['voltage'] ?? '-') . ' V / ' . (string) ($liveOnt['bias_current'] ?? '-') . ' mA';
    } elseif ($liveReason === 'management_path_down') {
        $lines[] = 'Live OLT query: management path down';
    } elseif ($liveReason !== '') {
        $lines[] = 'Live OLT query: ' . $liveReason;
    } else {
        $lines[] = 'Live OLT query: stored snapshot';
    }

    return [
        'ok' => true,
        'query' => $query,
        'candidate' => $candidate,
        'live' => $live,
        'live_reason' => $liveReason,
        'text' => implode("\n", $lines),
        'match_count' => max(count($candidates), $liveOnly !== null ? 1 : 0),
    ];
}

function app_mgs_hsgq_derived_command_map_from_discovery(array $discovery): array
{
    $nodes = is_array($discovery['nodes'] ?? null) ? $discovery['nodes'] : [];
    $prompts = is_array($discovery['prompts'] ?? null) ? $discovery['prompts'] : [];
    $sampleOntId = trim((string) ($discovery['sample_ont_id'] ?? ''));
    $hasGponContext = trim((string) ($prompts['config_gpon_1'] ?? '')) !== '';

    $map = [
        'enter_enable' => 'enable',
        'enter_config' => 'configure',
    ];

    if ($hasGponContext) {
        $map['enter_gpon_context'] = 'interface gpon {pon}';
        $map['gpon_context_help'] = '?';
    }
    if (trim((string) ($nodes['show_help_gpon'] ?? '')) !== '') {
        $map['gpon_show_help'] = 'show ?';
    }
    if (trim((string) ($nodes['show_ont_info_help'] ?? '')) !== '') {
        $map['ont_info_help'] = 'show ont-info ?';
        $map['ont_info_all'] = 'show ont-info all';
        if ($sampleOntId !== '') {
            $map['ont_info_single'] = 'show ont-info {ont}';
        }
    }
    if (trim((string) ($nodes['show_ont_optical_help'] ?? '')) !== '') {
        $map['ont_optical_help'] = 'show ont-optical ?';
        $map['ont_optical_all'] = 'show ont-optical all';
        if ($sampleOntId !== '') {
            $map['ont_optical_single'] = 'show ont-optical {ont}';
        }
    }
    if (trim((string) ($nodes['show_optical_state'] ?? '')) !== '') {
        $map['optical_state'] = 'show optical-state';
    }
    if (trim((string) ($nodes['show_statistic'] ?? '')) !== '') {
        $map['port_statistics'] = 'show statistic';
    }
    if (trim((string) ($nodes['show_gpon_statistic'] ?? '')) !== '') {
        $map['gpon_statistics'] = 'show gpon statistic';
    }
    if (trim((string) ($nodes['show_port_transceiver'] ?? '')) !== '') {
        $map['port_transceiver'] = 'show port transceiver';
    }
    if (trim((string) ($nodes['show_ont_port_vlan_sample'] ?? '')) !== '') {
        $map['ont_port_vlan'] = 'show ont-port-vlan {ont}';
    }
    if (trim((string) ($nodes['port_help'] ?? '')) !== '') {
        $map['port_node'] = 'port ?';
    }
    if (trim((string) ($nodes['ont_help'] ?? '')) !== '') {
        $map['ont_node'] = 'ont ?';
    }
    if (trim((string) ($nodes['gpon_help'] ?? '')) !== '') {
        $map['gpon_interface_node'] = 'interface gpon ?';
    }

    return $map;
}

/**
 * Cisco IOS/IOS-XE CLI discovery (docs/39_MGS_NODE_CONSOLIDATION.md Faza 3
 * -- the first non-HSGQ vendor onboarded into the per-vendor CLI-discovery
 * registry). Structurally identical to app_mgs_hsgq_cli_discovery_probe()
 * (same tools/*.py + payload-file + JSON-line-decode plumbing), but does
 * not need HSGQ's exploratory "?"-at-every-level menu walk: IOS `show`
 * command syntax is standardized and documented, so tools/cisco-cli-
 * discovery.py just confirms device identity and runs a fixed set of safe
 * read-only show commands instead of deriving the command set live.
 */
function app_mgs_cisco_cli_discovery_probe(
    string $ip,
    string $username,
    string $password,
    int $port = 22,
    int $timeoutMs = 4500,
    array $actor = [],
    array $device = [],
    ?PDO $db = null,
    string $authorizationAction = 'preview_device_backup'
): array {
    $target = array_replace(['ip_address' => trim($ip)], $device);
    $authorization = app_noc_authorize($actor, $authorizationAction, $target, $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure($authorizationAction, $authorization);
    }
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['ok' => false, 'error' => 'missing_ssh_credentials'];
    }

    $python = app_mgs_find_python_binary();
    $script = __DIR__ . '/tools/cisco-cli-discovery.py';
    if ($python === null || !is_file($script) || !function_exists('exec')) {
        return ['ok' => false, 'error' => 'cisco_cli_helper_unavailable'];
    }

    $payloadFile = tempnam(sys_get_temp_dir(), 'mgs_cisco_');
    if ($payloadFile === false) {
        return ['ok' => false, 'error' => 'cisco_cli_payload_temp_failed'];
    }

    $payload = [
        'host' => $ip,
        'port' => $port,
        'username' => $username,
        'password' => $password,
        'timeout_ms' => max(1800, $timeoutMs),
    ];
    file_put_contents($payloadFile, json_encode($payload, JSON_UNESCAPED_SLASHES));

    $command = escapeshellarg($python)
        . ' ' . escapeshellarg($script)
        . ' --payload-file ' . escapeshellarg($payloadFile);
    $output = [];
    $exitCode = 1;
    @exec($command . ' 2>&1', $output, $exitCode);
    @unlink($payloadFile);

    $lines = array_values(array_filter(array_map(static fn ($line): string => trim((string) $line), $output), static fn ($line): bool => $line !== ''));
    $text = implode("\n", $lines);
    $jsonCandidate = $text;
    if ($lines !== []) {
        $lastLine = $lines[count($lines) - 1];
        if (str_starts_with($lastLine, '{') && str_ends_with($lastLine, '}')) {
            $jsonCandidate = $lastLine;
        }
    }

    $decoded = json_decode($jsonCandidate, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => $text !== '' ? $text : 'cisco_cli_invalid_response'];
    }

    return [
        'ok' => !empty($decoded['ok']),
        'error' => (string) ($decoded['error'] ?? ''),
        'host' => (string) ($decoded['host'] ?? $ip),
        'port' => (int) ($decoded['port'] ?? $port),
        'username' => $username,
        'prompts' => is_array($decoded['prompts'] ?? null) ? $decoded['prompts'] : [],
        'is_cisco_ios' => !empty($decoded['is_cisco_ios']),
        'hostname' => (string) ($decoded['hostname'] ?? ''),
        'privileged_mode' => !empty($decoded['privileged_mode']),
        'outputs' => is_array($decoded['outputs'] ?? null) ? $decoded['outputs'] : [],
        'welcome' => (string) ($decoded['welcome'] ?? ''),
    ];
}

/**
 * Unlike HSGQ's derive function (which builds the command map FROM live
 * exploration output, since HSGQ's menu structure isn't independently
 * documented), this confirms the probe actually reached a real Cisco IOS
 * device (is_cisco_ios) and, if so, returns the same fixed standard IOS
 * command set app_mgs_default_device_profiles() already seeds for
 * CiscoRouterAdapter/CiscoSwitchAdapter -- so a freshly-onboarded Cisco
 * device converges on the identical, already-reviewed command map rather
 * than an ad-hoc derived one.
 */
function app_mgs_cisco_derived_command_map_from_discovery(array $discovery): array
{
    if (empty($discovery['is_cisco_ios'])) {
        return [];
    }

    $map = [
        'show_version' => 'show version',
        'ip_interface_brief' => 'show ip interface brief',
        'interfaces_status' => 'show interfaces status',
        'vlan_brief' => 'show vlan brief',
        'mac_table' => 'show mac address-table',
        'inventory' => 'show inventory',
    ];
    if (!empty($discovery['privileged_mode'])) {
        $map['enter_enable'] = 'enable';
    }

    return $map;
}

function app_mgs_sync_device_command_map(string $deviceKey, array $commandMap, array $actor = [], ?PDO $db = null): array
{
    $deviceKey = trim($deviceKey);
    $db = $db instanceof PDO ? $db : app_db();
    if ($deviceKey === '' || $commandMap === [] || !$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'invalid_sync_request'];
    }

    $device = app_mgs_device_learning_profile_by_key($deviceKey, $db, $actor);
    if (!is_array($device)) {
        return ['ok' => false, 'reason' => 'device_not_found'];
    }
    $authorization = app_noc_authorize($actor, 'update_command_map', $device, $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('update_command_map', $authorization);
    }

    $existing = app_mgs_json_decode_array($device['command_map_json'] ?? null);
    $merged = array_merge(is_array($existing) ? $existing : [], $commandMap);
    $statement = $db->prepare(
        'UPDATE device_learning_profiles
            SET command_map_json = :command_map_json,
                updated_at = NOW()
          WHERE device_key = :device_key
          LIMIT 1'
    );
    $statement->execute([
        'command_map_json' => app_db_json_encode($merged),
        'device_key' => $deviceKey,
    ]);
    app_mgs_record_operation_action([
        'action_type' => 'device_command_map_update',
        'entity_type' => 'device_learning',
        'entity_id' => $deviceKey,
        'status' => 'executed',
        'actor' => $actor,
        'summary' => 'Device command map updated: ' . (string) ($device['device_name'] ?? $deviceKey),
        'payload' => ['keys' => array_keys($commandMap)],
    ]);

    return ['ok' => true, 'device_key' => $deviceKey, 'command_count' => count($merged)];
}

function app_mgs_probe_udp_port_nmap(
    string $ip,
    int $port = 161,
    int $timeoutMs = 1800,
    array $actor = [],
    array $device = [],
    ?PDO $db = null,
    string $authorizationAction = 'probe_device'
): array
{
    $target = array_replace(['ip_address' => trim($ip)], $device);
    $authorization = app_noc_authorize($actor, $authorizationAction, $target, $db);
    if (empty($authorization['ok'])) {
        return array_merge(
            ['reachable' => false, 'method' => 'udp_' . $port],
            app_noc_authorization_failure($authorizationAction, $authorization)
        );
    }
    $binary = app_mgs_find_nmap_binary();
    if ($binary === null || !function_exists('exec')) {
        return ['reachable' => false, 'method' => 'udp_' . $port, 'error' => 'nmap_unavailable'];
    }

    $hostTimeoutMs = max(1000, min(5000, $timeoutMs));
    $command = '"' . $binary . '" -Pn -sU -p U:' . (int) $port . ' --max-retries 1 --host-timeout ' . $hostTimeoutMs . 'ms ' . escapeshellarg($ip);
    $output = [];
    $exitCode = 1;
    @exec($command, $output, $exitCode);
    $text = implode("\n", array_map(static fn ($line): string => (string) $line, $output));

    if (preg_match('/\b' . preg_quote((string) $port, '/') . '\/udp\s+open\b/i', $text)) {
        return ['reachable' => true, 'method' => 'nmap_udp_' . $port, 'error' => ''];
    }
    if (preg_match('/\b' . preg_quote((string) $port, '/') . '\/udp\s+open\|filtered\b/i', $text)) {
        return ['reachable' => false, 'method' => 'nmap_udp_' . $port, 'error' => 'open_filtered'];
    }
    if (preg_match('/Failed to determine dst MAC address/i', $text)) {
        return ['reachable' => false, 'method' => 'nmap_udp_' . $port, 'error' => 'route_or_l2_unresolved'];
    }

    return ['reachable' => false, 'method' => 'nmap_udp_' . $port, 'error' => $exitCode === 0 ? 'udp_not_open' : 'nmap_probe_failed'];
}

function app_mgs_snmp_defaults(): array
{
    return [
        'trap_server' => app_config('mgs_snmp_trap_server', '172.16.5.2'),
        'trap_port' => (int) app_config('mgs_snmp_trap_port', '162'),
        'read_community' => app_environment_secret('MGS_SNMP_READ_COMMUNITY') !== ''
            ? app_environment_secret('MGS_SNMP_READ_COMMUNITY')
            : app_environment_secret('SNMP_COMMUNITY'),
        'write_community' => app_environment_secret('MGS_SNMP_WRITE_COMMUNITY'),
        'port' => (int) app_config('mgs_snmp_port', '161'),
        'contact' => app_config('mgs_snmp_contact', 'Contact'),
        'location' => app_config('mgs_snmp_location', 'Location'),
    ];
}

function app_mgs_device_access_override_by_key(
    string $deviceKey,
    ?PDO $db = null,
    array $actor = [],
    string $purposeAction = 'read_device_sensitive',
    bool $ensureSchema = true,
    array $authorizationObject = []
): ?array
{
    $deviceKey = clean_text($deviceKey);
    if ($deviceKey === '') {
        return null;
    }

    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !($ensureSchema ? app_mgs_ensure_schema($db) : app_mgs_schema_ready($db))) {
        return null;
    }
    $authorization = app_noc_authorize(
        $actor,
        $purposeAction,
        array_replace(['device_key' => $deviceKey], $authorizationObject),
        $db
    );
    if (empty($authorization['ok'])) {
        return null;
    }

    $statement = $db->prepare('SELECT * FROM device_access_overrides WHERE device_key = :device_key LIMIT 1');
    $statement->execute(['device_key' => $deviceKey]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function app_mgs_device_access_profile(
    array $device,
    ?PDO $db = null,
    array $actor = [],
    string $purposeAction = 'read_device_sensitive',
    bool $ensureSchema = true
): array
{
    $authorization = app_noc_authorize($actor, $purposeAction, $device, $db);
    if (empty($authorization['ok'])) {
        return [];
    }
    $snmpDefaults = app_mgs_snmp_defaults();
    $profile = [
        'ssh_username' => '',
        'ssh_password' => '',
        'ssh_port' => 22,
        'read_community' => $snmpDefaults['read_community'],
        'write_community' => $snmpDefaults['write_community'],
        'port' => $snmpDefaults['port'],
        'trap_server' => $snmpDefaults['trap_server'],
        'trap_port' => $snmpDefaults['trap_port'],
        'contact' => $snmpDefaults['contact'],
        'location' => $snmpDefaults['location'],
        'notes' => '',
    ];

    // Device-specific communities must never be embedded in source code.
    // Store them in the encrypted device credential vault (or environment
    // defaults) and let the authorization layer decide whether they may be
    // revealed to this actor.
    $deviceKey = (string) ($device['device_key'] ?? '');

    $override = app_mgs_device_access_override_by_key($deviceKey, $db, $actor, $purposeAction, $ensureSchema, $device);
    if (is_array($override)) {
        $map = [
            'ssh_username' => 'ssh_username',
            'ssh_password' => 'ssh_password',
            'ssh_port' => 'ssh_port',
            'read_community' => 'snmp_read_community',
            'write_community' => 'snmp_write_community',
            'port' => 'snmp_port',
            'trap_server' => 'trap_server',
            'trap_port' => 'trap_port',
            'contact' => 'contact',
            'location' => 'location',
            'notes' => 'notes',
        ];
        foreach ($map as $target => $source) {
            if (!array_key_exists($source, $override) || $override[$source] === null) {
                continue;
            }
            $value = is_string($override[$source]) ? trim((string) $override[$source]) : $override[$source];
            if ($value === '') {
                continue;
            }
            $profile[$target] = $value;
        }
    }

    $profile['ssh_port'] = max(1, (int) ($profile['ssh_port'] ?? 22));
    $profile['port'] = max(1, (int) ($profile['port'] ?? 161));
    $profile['trap_port'] = max(1, (int) ($profile['trap_port'] ?? 162));

    // Encrypted vault takes precedence over the plaintext override columns
    // above. A device saved before the vault existed (or written while
    // MGS_SECRETS_MASTER_KEY was unavailable to the writer) simply has no
    // vault row yet, so the plaintext value already merged in stands as the
    // fallback -- this must never hard-fail a live device probe.
    if ($deviceKey !== '') {
        $vaultDb = $db instanceof PDO ? $db : app_db();
        if ($vaultDb instanceof PDO) {
            $vaultFields = [
                'ssh_password' => 'ssh_password',
                'read_community' => 'snmp_read_community',
                'write_community' => 'snmp_write_community',
            ];
            foreach ($vaultFields as $target => $credentialType) {
                try {
                    $vaultValue = app_device_credential_reveal($vaultDb, $deviceKey, $credentialType);
                } catch (Throwable) {
                    $vaultValue = null;
                }
                if ($vaultValue !== null && $vaultValue !== '') {
                    $profile[$target] = $vaultValue;
                }
            }
        }
    }

    return $profile;
}

function app_mgs_device_snmp_profile(array $device, array $actor = [], ?PDO $db = null): array
{
    return app_mgs_device_access_profile($device, $db, $actor);
}

function app_mgs_set_device_access_override(string $deviceKey, array $payload, array $actor = [], ?PDO $db = null): array
{
    $deviceKey = clean_text($deviceKey);
    if ($deviceKey === '') {
        return ['ok' => false, 'reason' => 'missing_device_key'];
    }

    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }

    $device = app_mgs_device_learning_profile_by_key($deviceKey, $db, $actor);
    if (!is_array($device)) {
        return ['ok' => false, 'reason' => 'device_not_found'];
    }
    $authorization = app_noc_authorize($actor, 'update_device_access', $device, $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('update_device_access', $authorization);
    }

    $existing = app_mgs_device_access_override_by_key($deviceKey, $db, $actor, 'update_device_access') ?? [];
    // "Effective current value" for carry-forward must check the vault first --
    // once a secret has been migrated there, the plaintext column is blanked
    // (see below), so falling back to $existing alone would silently wipe an
    // unsubmitted field on the next save.
    $effectiveSecret = static function (string $payloadKey, string $credentialType) use ($payload, $existing, $db, $deviceKey): string {
        $submitted = trim((string) ($payload[$payloadKey] ?? ''));
        if ($submitted !== '') {
            return $submitted;
        }
        try {
            $vaultValue = app_device_credential_reveal($db, $deviceKey, $credentialType);
        } catch (Throwable) {
            $vaultValue = null;
        }
        if ($vaultValue !== null && $vaultValue !== '') {
            return $vaultValue;
        }
        return (string) ($existing[$payloadKey] ?? '');
    };
    $sshPassword = $effectiveSecret('ssh_password', 'ssh_password');
    $snmpReadCommunity = clean_text((string) ($payload['snmp_read_community'] ?? '')) ?: $effectiveSecret('snmp_read_community', 'snmp_read_community');
    $snmpWriteCommunity = clean_text((string) ($payload['snmp_write_community'] ?? '')) ?: $effectiveSecret('snmp_write_community', 'snmp_write_community');

    // Vault-first: if the master key is configured, encrypt these three
    // fields and never let the plaintext write below carry a real value for
    // them. If the key isn't available yet in this environment (deploy not
    // rolled out here), fall back to the previous plaintext behavior rather
    // than breaking device-credential updates entirely during the transition.
    $vaultUsed = app_device_credential_vault_key_available();
    if ($vaultUsed) {
        foreach ([
            'ssh_password' => $sshPassword,
            'snmp_read_community' => $snmpReadCommunity,
            'snmp_write_community' => $snmpWriteCommunity,
        ] as $credentialType => $secretValue) {
            if ($secretValue !== '') {
                app_device_credential_store($db, $deviceKey, $credentialType, $secretValue);
            }
        }
    }

    $row = [
        'device_key' => $deviceKey,
        'ssh_username' => clean_text((string) ($payload['ssh_username'] ?? ($existing['ssh_username'] ?? ''))),
        'ssh_password' => $vaultUsed ? '' : $sshPassword,
        'ssh_port' => max(1, (int) ($payload['ssh_port'] ?? ($existing['ssh_port'] ?? 22))),
        'snmp_read_community' => $vaultUsed ? '' : $snmpReadCommunity,
        'snmp_write_community' => $vaultUsed ? '' : $snmpWriteCommunity,
        'snmp_port' => max(1, (int) ($payload['snmp_port'] ?? ($existing['snmp_port'] ?? 161))),
        'trap_server' => clean_text((string) ($payload['trap_server'] ?? ($existing['trap_server'] ?? ''))),
        'trap_port' => max(1, (int) ($payload['trap_port'] ?? ($existing['trap_port'] ?? 162))),
        'contact' => clean_text((string) ($payload['contact'] ?? ($existing['contact'] ?? ''))),
        'location' => clean_text((string) ($payload['location'] ?? ($existing['location'] ?? ''))),
        'notes' => trim((string) ($payload['notes'] ?? ($existing['notes'] ?? ''))),
        'updated_by_account_id' => (int) ($actor['id'] ?? 0) ?: null,
        'updated_by_name' => clean_text((string) ($actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'system')),
    ];

    $statement = $db->prepare(
        'INSERT INTO device_access_overrides (
            device_key, ssh_username, ssh_password, ssh_port, snmp_read_community, snmp_write_community, snmp_port,
            trap_server, trap_port, contact, location, notes, updated_by_account_id, updated_by_name
         ) VALUES (
            :device_key, :ssh_username, :ssh_password, :ssh_port, :snmp_read_community, :snmp_write_community, :snmp_port,
            :trap_server, :trap_port, :contact, :location, :notes, :updated_by_account_id, :updated_by_name
         )
         ON DUPLICATE KEY UPDATE
            ssh_username = VALUES(ssh_username),
            ssh_password = VALUES(ssh_password),
            ssh_port = VALUES(ssh_port),
            snmp_read_community = VALUES(snmp_read_community),
            snmp_write_community = VALUES(snmp_write_community),
            snmp_port = VALUES(snmp_port),
            trap_server = VALUES(trap_server),
            trap_port = VALUES(trap_port),
            contact = VALUES(contact),
            location = VALUES(location),
            notes = VALUES(notes),
            updated_by_account_id = VALUES(updated_by_account_id),
            updated_by_name = VALUES(updated_by_name)'
    );
    $statement->execute($row);

    $effective = app_mgs_device_access_profile($device, $db, $actor, 'update_device_access');
    app_mgs_record_operation_action([
        'action_type' => 'device_access_override',
        'entity_type' => 'device_learning',
        'entity_id' => $deviceKey,
        'status' => 'executed',
        'actor' => $actor,
        'summary' => 'Device access override updated: ' . (string) ($device['device_name'] ?? $deviceKey),
        'payload' => [
            'device_key' => $deviceKey,
            'ssh_username' => $effective['ssh_username'] ?? '',
            'ssh_port' => $effective['ssh_port'] ?? 22,
            'snmp_read_community_set' => (string) ($effective['read_community'] ?? '') !== '',
            'snmp_port' => $effective['port'] ?? 161,
            'trap_server' => $effective['trap_server'] ?? '',
            'trap_port' => $effective['trap_port'] ?? 162,
            'vault_used' => $vaultUsed,
        ],
    ]);

    return [
        'ok' => true,
        'device_key' => $deviceKey,
        'device_name' => (string) ($device['device_name'] ?? $deviceKey),
        'profile' => $effective,
        'vault_used' => $vaultUsed,
    ];
}

function app_mgs_apply_vendor_access_defaults(string $vendor, array $actor = [], ?PDO $db = null): array
{
    $vendor = strtoupper(clean_text($vendor));
    if ($vendor === '') {
        return ['ok' => false, 'reason' => 'missing_vendor'];
    }

    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }
    $authorization = app_noc_authorize($actor, 'update_vendor_defaults', [], $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('update_vendor_defaults', $authorization);
    }

    $statement = $db->prepare(
        'SELECT device_key
           FROM device_learning_profiles
          WHERE UPPER(vendor) = :vendor
          ORDER BY ip_address'
    );
    $statement->execute(['vendor' => $vendor]);
    $deviceKeys = array_map(
        static fn (array $row): string => (string) ($row['device_key'] ?? ''),
        $statement->fetchAll(PDO::FETCH_ASSOC) ?: []
    );
    $deviceKeys = array_values(array_filter($deviceKeys));
    if ($deviceKeys === []) {
        return ['ok' => false, 'reason' => 'vendor_not_found'];
    }

    $defaults = app_mgs_snmp_defaults();
    $results = [];
    foreach ($deviceKeys as $deviceKey) {
        $results[] = app_mgs_set_device_access_override($deviceKey, [
            'snmp_read_community' => (string) ($defaults['read_community'] ?? app_environment_secret('MGS_SNMP_READ_COMMUNITY')),
            'snmp_write_community' => (string) ($defaults['write_community'] ?? app_environment_secret('MGS_SNMP_WRITE_COMMUNITY')),
            'snmp_port' => (int) ($defaults['port'] ?? 161),
            'trap_server' => (string) ($defaults['trap_server'] ?? '172.16.5.2'),
            'trap_port' => (int) ($defaults['trap_port'] ?? 162),
            'contact' => (string) ($defaults['contact'] ?? 'Contact'),
            'location' => (string) ($defaults['location'] ?? 'Location'),
            'notes' => $vendor . ' SNMP defaults sync',
        ], $actor, $db);
    }

    app_mgs_record_operation_action([
        'action_type' => 'vendor_access_defaults',
        'entity_type' => 'device_learning',
        'entity_id' => $vendor,
        'status' => 'executed',
        'actor' => $actor,
        'summary' => 'Vendor access defaults applied: ' . $vendor,
        'payload' => [
            'vendor' => $vendor,
            'count' => count($results),
            'defaults' => $defaults,
        ],
    ]);

    return [
        'ok' => true,
        'vendor' => $vendor,
        'count' => count($results),
        'results' => $results,
        'defaults' => $defaults,
    ];
}

function app_mgs_snmp_ber_length(int $length): string
{
    if ($length < 0x80) {
        return chr($length);
    }

    $bytes = '';
    while ($length > 0) {
        $bytes = chr($length & 0xFF) . $bytes;
        $length >>= 8;
    }

    return chr(0x80 | strlen($bytes)) . $bytes;
}

function app_mgs_snmp_ber_wrap(int $tag, string $payload): string
{
    return chr($tag) . app_mgs_snmp_ber_length(strlen($payload)) . $payload;
}

function app_mgs_snmp_ber_integer(int $value): string
{
    if ($value === 0) {
        return app_mgs_snmp_ber_wrap(0x02, "\x00");
    }

    $bytes = '';
    $number = $value;
    while ($number > 0) {
        $bytes = chr($number & 0xFF) . $bytes;
        $number >>= 8;
    }
    if ((ord($bytes[0]) & 0x80) !== 0) {
        $bytes = "\x00" . $bytes;
    }

    return app_mgs_snmp_ber_wrap(0x02, $bytes);
}

function app_mgs_snmp_ber_string(string $value): string
{
    return app_mgs_snmp_ber_wrap(0x04, $value);
}

function app_mgs_snmp_ber_null(): string
{
    return "\x05\x00";
}

function app_mgs_snmp_ber_oid(string $oid): string
{
    $parts = array_values(array_filter(array_map('intval', explode('.', trim($oid, '.'))), static fn ($part): bool => $part >= 0));
    if (count($parts) < 2) {
        return app_mgs_snmp_ber_wrap(0x06, '');
    }

    $encoded = chr(($parts[0] * 40) + $parts[1]);
    foreach (array_slice($parts, 2) as $part) {
        $stack = [chr($part & 0x7F)];
        $part >>= 7;
        while ($part > 0) {
            array_unshift($stack, chr(0x80 | ($part & 0x7F)));
            $part >>= 7;
        }
        $encoded .= implode('', $stack);
    }

    return app_mgs_snmp_ber_wrap(0x06, $encoded);
}

function app_mgs_snmp_build_get_request(string $community, string $oid, int $requestId, int $version = 0): string
{
    $version = $version === 1 ? 1 : 0;
    $varBind = app_mgs_snmp_ber_wrap(0x30, app_mgs_snmp_ber_oid($oid) . app_mgs_snmp_ber_null());
    $varBindList = app_mgs_snmp_ber_wrap(0x30, $varBind);
    $pdu = app_mgs_snmp_ber_wrap(0xA0, app_mgs_snmp_ber_integer($requestId) . app_mgs_snmp_ber_integer(0) . app_mgs_snmp_ber_integer(0) . $varBindList);
    return app_mgs_snmp_ber_wrap(0x30, app_mgs_snmp_ber_integer($version) . app_mgs_snmp_ber_string($community) . $pdu);
}

function app_mgs_probe_snmp(
    string $ip,
    ?string $community = null,
    ?int $port = null,
    int $timeoutMs = 1200,
    array $actor = [],
    array $device = [],
    ?PDO $db = null,
    string $authorizationAction = 'probe_device'
): array
{
    $target = array_replace(['ip_address' => trim($ip)], $device);
    $authorization = app_noc_authorize($actor, $authorizationAction, $target, $db);
    if (empty($authorization['ok'])) {
        return array_merge(
            ['reachable' => false, 'method' => 'snmp_v1'],
            app_noc_authorization_failure($authorizationAction, $authorization)
        );
    }
    $defaults = app_mgs_snmp_defaults();
    $community = trim((string) ($community ?? $defaults['read_community']));
    $port = (int) ($port ?? $defaults['port']);
    if ($community === '' || $port <= 0) {
        return ['reachable' => false, 'method' => 'snmp_v1', 'error' => 'missing_snmp_config'];
    }

    $probe = app_mgs_snmp_get_raw($ip, $community, $port, '1.3.6.1.2.1.1.1.0', $timeoutMs);
    $version = (int) ($probe['version'] ?? 0);
    if (empty($probe['ok'])) {
        return [
            'reachable' => false,
            'method' => 'snmp_v1_v2c',
            'error' => (string) ($probe['error'] ?? 'snmp_timeout'),
        ];
    }

    // Never return the community: callers only need capability/reachability,
    // and leaking it would turn an otherwise harmless health endpoint into a
    // credential disclosure path.
    return [
        'reachable' => true,
        'method' => $version === 1 ? 'snmp_v2c' : 'snmp_v1',
        'version' => $version === 1 ? '2c' : '1',
        'error' => '',
        'bytes' => (int) ($probe['bytes'] ?? 0),
        'port' => $port,
    ];
}

/**
 * IF-MIB (RFC1213) interface inventory columns walked by
 * app_mgs_discover_device_interfaces() -- the "interfeys inventarı"
 * prerequisite for a future interface-traffic collector
 * (docs/39_MGS_NODE_CONSOLIDATION.md Faza 1). ifIndex itself is not walked
 * separately: it is derived from the last OID arc of every other column.
 */
function app_mgs_if_mib_columns(): array
{
    return [
        'if_descr' => '1.3.6.1.2.1.2.2.1.2',
        'if_type' => '1.3.6.1.2.1.2.2.1.3',
        'if_speed' => '1.3.6.1.2.1.2.2.1.5',
        'if_phys_address' => '1.3.6.1.2.1.2.2.1.6',
        'if_admin_status' => '1.3.6.1.2.1.2.2.1.7',
        'if_oper_status' => '1.3.6.1.2.1.2.2.1.8',
    ];
}

function app_mgs_snmp_build_getnext_request(string $community, string $oid, int $requestId, int $version = 0): string
{
    $version = $version === 1 ? 1 : 0;
    $varBind = app_mgs_snmp_ber_wrap(0x30, app_mgs_snmp_ber_oid($oid) . app_mgs_snmp_ber_null());
    $varBindList = app_mgs_snmp_ber_wrap(0x30, $varBind);
    $pdu = app_mgs_snmp_ber_wrap(0xA1, app_mgs_snmp_ber_integer($requestId) . app_mgs_snmp_ber_integer(0) . app_mgs_snmp_ber_integer(0) . $varBindList);
    return app_mgs_snmp_ber_wrap(0x30, app_mgs_snmp_ber_integer($version) . app_mgs_snmp_ber_string($community) . $pdu);
}

/**
 * Reads one BER TLV (tag, length, value) at $offset. Returns null on any
 * malformed/truncated input instead of throwing -- SNMP responses come from
 * untrusted network devices, so the decoder must fail closed, not crash the
 * caller.
 */
function app_mgs_snmp_ber_read_tlv(string $data, int $offset): ?array
{
    $len = strlen($data);
    if ($offset < 0 || $offset >= $len) {
        return null;
    }
    $tag = ord($data[$offset]);
    $offset++;
    if ($offset >= $len) {
        return null;
    }
    $lengthByte = ord($data[$offset]);
    $offset++;
    if ($lengthByte < 0x80) {
        $length = $lengthByte;
    } else {
        $numBytes = $lengthByte & 0x7F;
        if ($numBytes === 0 || $numBytes > 4 || $offset + $numBytes > $len) {
            return null;
        }
        $length = 0;
        for ($i = 0; $i < $numBytes; $i++) {
            $length = ($length << 8) | ord($data[$offset + $i]);
        }
        $offset += $numBytes;
    }
    if ($length < 0 || $offset + $length > $len) {
        return null;
    }

    return ['tag' => $tag, 'value' => substr($data, $offset, $length), 'next' => $offset + $length];
}

function app_mgs_snmp_ber_decode_oid(string $bytes): string
{
    if ($bytes === '') {
        return '';
    }
    $first = ord($bytes[0]);
    $parts = [intdiv($first, 40), $first % 40];
    $value = 0;
    $length = strlen($bytes);
    for ($i = 1; $i < $length; $i++) {
        $byte = ord($bytes[$i]);
        $value = ($value << 7) | ($byte & 0x7F);
        if (($byte & 0x80) === 0) {
            $parts[] = $value;
            $value = 0;
        }
    }

    return implode('.', $parts);
}

function app_mgs_snmp_ber_decode_unsigned(string $bytes): int
{
    $value = 0;
    $length = strlen($bytes);
    for ($i = 0; $i < $length; $i++) {
        $value = ($value << 8) | ord($bytes[$i]);
    }

    return $value;
}

/**
 * Turns one decoded varbind's raw BER bytes into a usable PHP value based on
 * its ASN.1/SNMP tag. INTEGER (0x02) and the SNMP application types
 * Counter32/Gauge32/TimeTicks/Counter64 (0x41/0x42/0x43/0x46) are all plain
 * big-endian byte sequences here -- every IF-MIB column this module walks
 * (ifIndex, ifType, ifSpeed, ifAdminStatus, ifOperStatus) is small and
 * non-negative, so an unsigned decode is sufficient and avoids sign-bit
 * edge cases entirely. noSuchObject/noSuchInstance/endOfMibView
 * (0x80/0x81/0x82) are SNMPv2-style exception tags some agents still send
 * in response to a v1 GETNEXT past the end of a table; treated as "no
 * value" so the walk loop's subtree check below ends the walk cleanly.
 */
function app_mgs_snmp_decode_value(int $tag, string $raw): int|string|null
{
    return match (true) {
        in_array($tag, [0x02, 0x41, 0x42, 0x43, 0x46], true) => app_mgs_snmp_ber_decode_unsigned($raw),
        $tag === 0x06 => app_mgs_snmp_ber_decode_oid($raw),
        in_array($tag, [0x05, 0x80, 0x81, 0x82], true) => null,
        default => $raw,
    };
}

/**
 * Decodes a full SNMPv1/v2c message (version, community, GetResponse-PDU with
 * request-id/error-status/error-index/varbind-list). Only the shape this
 * module's own GET/GETNEXT requests can trigger a response to is parsed;
 * anything else is reported as ok=false rather than guessed at.
 */
function app_mgs_snmp_decode_response(string $packet): array
{
    $outer = app_mgs_snmp_ber_read_tlv($packet, 0);
    if ($outer === null || $outer['tag'] !== 0x30) {
        return ['ok' => false, 'error' => 'malformed_packet'];
    }
    $body = $outer['value'];

    $version = app_mgs_snmp_ber_read_tlv($body, 0);
    if ($version === null || $version['tag'] !== 0x02) {
        return ['ok' => false, 'error' => 'malformed_version'];
    }
    $community = app_mgs_snmp_ber_read_tlv($body, $version['next']);
    if ($community === null || $community['tag'] !== 0x04) {
        return ['ok' => false, 'error' => 'malformed_community'];
    }
    $pdu = app_mgs_snmp_ber_read_tlv($body, $community['next']);
    if ($pdu === null) {
        return ['ok' => false, 'error' => 'malformed_pdu'];
    }

    $pduBody = $pdu['value'];
    $requestId = app_mgs_snmp_ber_read_tlv($pduBody, 0);
    if ($requestId === null) {
        return ['ok' => false, 'error' => 'malformed_request_id'];
    }
    $errorStatus = app_mgs_snmp_ber_read_tlv($pduBody, $requestId['next']);
    if ($errorStatus === null) {
        return ['ok' => false, 'error' => 'malformed_error_status'];
    }
    $errorIndex = app_mgs_snmp_ber_read_tlv($pduBody, $errorStatus['next']);
    if ($errorIndex === null) {
        return ['ok' => false, 'error' => 'malformed_error_index'];
    }
    $varbindList = app_mgs_snmp_ber_read_tlv($pduBody, $errorIndex['next']);
    if ($varbindList === null || $varbindList['tag'] !== 0x30) {
        return ['ok' => false, 'error' => 'malformed_varbind_list'];
    }

    $varbinds = [];
    $offset = 0;
    $listBody = $varbindList['value'];
    $listLength = strlen($listBody);
    while ($offset < $listLength) {
        $pair = app_mgs_snmp_ber_read_tlv($listBody, $offset);
        if ($pair === null || $pair['tag'] !== 0x30) {
            break;
        }
        $offset = $pair['next'];
        $oidTlv = app_mgs_snmp_ber_read_tlv($pair['value'], 0);
        if ($oidTlv === null || $oidTlv['tag'] !== 0x06) {
            break;
        }
        $valueTlv = app_mgs_snmp_ber_read_tlv($pair['value'], $oidTlv['next']);
        if ($valueTlv === null) {
            break;
        }
        $varbinds[] = ['oid' => app_mgs_snmp_ber_decode_oid($oidTlv['value']), 'tag' => $valueTlv['tag'], 'raw' => $valueTlv['value']];
    }

    return [
        'ok' => true,
        'version' => app_mgs_snmp_ber_decode_unsigned($version['value']),
        'pdu_tag' => (int) $pdu['tag'],
        'community' => (string) $community['value'],
        'request_id' => app_mgs_snmp_ber_decode_unsigned($requestId['value']),
        'error_status' => app_mgs_snmp_ber_decode_unsigned($errorStatus['value']),
        'error_index' => app_mgs_snmp_ber_decode_unsigned($errorIndex['value']),
        'varbinds' => $varbinds,
    ];
}

/**
 * Sends one raw SNMPv1 GETNEXT for $oid and decodes the single varbind that
 * comes back. No authorization check here -- this is the low-level UDP/BER
 * mechanic, mirroring how app_mgs_snmp_build_get_request() itself is
 * unauthenticated; callers (app_mgs_discover_device_interfaces()) authorize
 * once before looping, not once per GETNEXT round-trip.
 */
function app_mgs_snmp_getnext_raw(string $ip, string $community, int $port, string $oid, int $timeoutMs, ?int $version = null): array
{
    static $versionCache = [];
    $cacheKey = $ip . ':' . $port . ':' . hash('sha256', $community);
    $versions = in_array($version, [0, 1], true)
        ? [(int) $version]
        : (isset($versionCache[$cacheKey])
            ? [(int) $versionCache[$cacheKey], (int) $versionCache[$cacheKey] === 0 ? 1 : 0]
            : [0, 1]);
    $lastError = 'snmp_timeout';

    foreach ($versions as $candidateVersion) {
        $requestId = random_int(100000, 9999999);
        $packet = app_mgs_snmp_build_getnext_request($community, $oid, $requestId, $candidateVersion);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client('udp://' . $ip . ':' . $port, $errno, $errstr, max(0.3, $timeoutMs / 1000));
        if (!is_resource($socket)) {
            $lastError = $errstr !== '' ? $errstr : 'socket_open_failed';
            continue;
        }
        stream_set_timeout($socket, 0, max(100000, $timeoutMs * 1000));
        $written = @fwrite($socket, $packet);
        if ($written === false || $written <= 0) {
            fclose($socket);
            $lastError = 'socket_write_failed';
            continue;
        }
        $response = @fread($socket, 4096);
        $meta = stream_get_meta_data($socket);
        fclose($socket);
        if (!is_string($response) || $response === '' || ($meta['timed_out'] ?? false) === true) {
            $lastError = ($meta['timed_out'] ?? false) ? 'snmp_timeout' : 'empty_snmp_response';
            continue;
        }

        $decoded = app_mgs_snmp_decode_response($response);
        if (empty($decoded['ok']) || $decoded['varbinds'] === []) {
            $lastError = (string) ($decoded['error'] ?? 'decode_failed');
            continue;
        }
        if (($decoded['community'] ?? null) !== $community ||
            (int) ($decoded['version'] ?? -1) !== $candidateVersion ||
            (int) ($decoded['request_id'] ?? -1) !== $requestId ||
            (int) ($decoded['pdu_tag'] ?? 0) !== 0xA2) {
            $lastError = 'snmp_response_mismatch';
            continue;
        }
        if ((int) $decoded['error_status'] !== 0) {
            $lastError = 'snmp_error_status_' . $decoded['error_status'];
            continue;
        }
        $varbind = $decoded['varbinds'][0];
        $versionCache[$cacheKey] = $candidateVersion;

        return [
            'ok' => true,
            'oid' => (string) $varbind['oid'],
            'tag' => (int) $varbind['tag'],
            'value' => app_mgs_snmp_decode_value((int) $varbind['tag'], (string) $varbind['raw']),
            'version' => $candidateVersion,
            'bytes' => strlen($response),
        ];
    }

    return ['ok' => false, 'error' => $lastError];
}

/**
 * Walks one MIB column via repeated GETNEXT starting at $baseOid, stopping
 * as soon as the returned OID leaves the $baseOid subtree (the portable
 * SNMPv1 end-of-table signal), a GETNEXT stops making progress (safety
 * against a misbehaving agent looping the same OID forever), or $maxRows is
 * reached (bounds worst-case round-trips against a slow/unreachable device).
 * Returns [oid => ['tag' => int, 'value' => mixed]] keyed by the full
 * returned OID; app_mgs_discover_device_interfaces() derives the ifIndex
 * from each key's last arc.
 */
function app_mgs_snmp_walk_column(string $ip, string $community, int $port, string $baseOid, int $timeoutMs, int $maxRows, ?int $version = null): array
{
    $results = [];
    $currentOid = $baseOid;
    $selectedVersion = $version;
    for ($i = 0; $i < $maxRows; $i++) {
        $step = app_mgs_snmp_getnext_raw($ip, $community, $port, $currentOid, $timeoutMs, $selectedVersion);
        if (empty($step['ok'])) {
            break;
        }
        $selectedVersion = (int) ($step['version'] ?? 0);
        $nextOid = (string) $step['oid'];
        if ($nextOid === '' || $nextOid === $currentOid || !str_starts_with($nextOid . '.', $baseOid . '.')) {
            break;
        }
        $results[$nextOid] = ['tag' => $step['tag'], 'value' => $step['value']];
        $currentOid = $nextOid;
    }

    return $results;
}

function app_mgs_snmp_if_index_from_oid(string $oid, string $baseOid): ?int
{
    $prefix = $baseOid . '.';
    if (!str_starts_with($oid, $prefix)) {
        return null;
    }
    $suffix = substr($oid, strlen($prefix));

    return ctype_digit($suffix) ? (int) $suffix : null;
}

/**
 * Discovers (or re-syncs) one device's IF-MIB interface inventory via a real
 * SNMP walk and stores it in device_interfaces. This is the "interfeys
 * inventarı" step docs/39_MGS_NODE_CONSOLIDATION.md Faza 1 calls out as the
 * prerequisite for an interface-traffic collector -- deliberately built with
 * a real writer (this function) in the same change as the schema, not left
 * as an empty table.
 */
function app_mgs_discover_device_interfaces(array $device, ?PDO $db = null, array $actor = [], int $maxInterfaces = 128): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }
    $deviceKey = (string) ($device['device_key'] ?? '');
    $ip = trim((string) ($device['ip_address'] ?? ''));
    if ($deviceKey === '' || $ip === '') {
        return ['ok' => false, 'reason' => 'missing_device_target'];
    }
    $authorization = app_noc_authorize($actor, 'probe_device', $device, $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('probe_device', $authorization);
    }

    $accessProfile = app_mgs_device_access_profile($device, $db, $actor, 'probe_device');
    $community = (string) ($accessProfile['read_community'] ?? '');
    $port = (int) ($accessProfile['port'] ?? 161);
    if ($community === '') {
        return ['ok' => false, 'reason' => 'missing_snmp_config'];
    }

    $columns = app_mgs_if_mib_columns();
    $rowsByIndex = [];
    foreach ($columns as $field => $baseOid) {
        $walked = app_mgs_snmp_walk_column($ip, $community, $port, $baseOid, 700, $maxInterfaces);
        if ($walked === [] && $field === 'if_descr') {
            // ifDescr is the defining column; if the walk can't even read that,
            // the device is unreachable/unsupported for this pass -- fail fast
            // instead of silently persisting a "0 interfaces" result.
            return ['ok' => false, 'reason' => 'snmp_walk_unreachable_or_empty'];
        }
        foreach ($walked as $oid => $entry) {
            $index = app_mgs_snmp_if_index_from_oid($oid, $baseOid);
            if ($index === null) {
                continue;
            }
            $rowsByIndex[$index][$field] = $entry['value'];
        }
    }

    $adminStatusMap = [1 => 'up', 2 => 'down', 3 => 'testing'];
    $operStatusMap = [1 => 'up', 2 => 'down', 3 => 'testing', 4 => 'unknown', 5 => 'dormant', 6 => 'not_present', 7 => 'lower_layer_down'];

    $upsert = $db->prepare(
        'INSERT INTO device_interfaces
            (device_key, if_index, if_descr, if_type, if_speed_bps, if_phys_address, if_admin_status, if_oper_status, last_polled_at)
         VALUES (:device_key, :if_index, :if_descr, :if_type, :if_speed_bps, :if_phys_address, :if_admin_status, :if_oper_status, NOW())
         ON DUPLICATE KEY UPDATE
            if_descr = VALUES(if_descr),
            if_type = VALUES(if_type),
            if_speed_bps = VALUES(if_speed_bps),
            if_phys_address = VALUES(if_phys_address),
            if_admin_status = VALUES(if_admin_status),
            if_oper_status = VALUES(if_oper_status),
            last_polled_at = NOW()'
    );

    $stored = 0;
    foreach ($rowsByIndex as $index => $row) {
        $physRaw = (string) ($row['if_phys_address'] ?? '');
        $physFormatted = strlen($physRaw) === 6 ? strtoupper(implode(':', str_split(bin2hex($physRaw), 2))) : '';
        $upsert->execute([
            'device_key' => $deviceKey,
            'if_index' => $index,
            'if_descr' => clean_text((string) ($row['if_descr'] ?? '')),
            'if_type' => (int) ($row['if_type'] ?? 0),
            'if_speed_bps' => (int) ($row['if_speed'] ?? 0),
            'if_phys_address' => $physFormatted,
            'if_admin_status' => $adminStatusMap[(int) ($row['if_admin_status'] ?? 0)] ?? 'unknown',
            'if_oper_status' => $operStatusMap[(int) ($row['if_oper_status'] ?? 0)] ?? 'unknown',
        ]);
        $stored++;
    }

    app_mgs_record_operation_action([
        'action_type' => 'device_interface_discovery',
        'entity_type' => 'device_learning_profiles',
        'entity_id' => $deviceKey,
        'status' => 'executed',
        'actor' => $actor,
        'summary' => $stored . ' interfeys aşkarlandı/yeniləndi',
        'payload' => ['device_key' => $deviceKey, 'interface_count' => $stored],
    ]);

    return ['ok' => true, 'device_key' => $deviceKey, 'interface_count' => $stored];
}

/**
 * Read-only listing for a device's discovered interfaces (UI + smoke). Same
 * authorization as reading any other device detail.
 */
function app_mgs_device_interfaces(string $deviceKey, ?PDO $db = null, array $actor = []): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || $deviceKey === '') {
        return [];
    }
    $authorization = app_noc_authorize($actor, 'read_device', ['device_key' => $deviceKey], $db);
    if (empty($authorization['ok'])) {
        return [];
    }
    $statement = $db->prepare('SELECT * FROM device_interfaces WHERE device_key = :device_key ORDER BY if_index ASC');
    $statement->execute(['device_key' => $deviceKey]);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Sends one raw SNMPv1 GET for $oid and decodes the single varbind that
 * comes back. Same unauthenticated low-level layering as
 * app_mgs_snmp_getnext_raw() -- callers authorize once, not per request.
 */
function app_mgs_snmp_get_raw(string $ip, string $community, int $port, string $oid, int $timeoutMs, ?int $version = null): array
{
    static $versionCache = [];
    $cacheKey = $ip . ':' . $port . ':' . hash('sha256', $community);
    $versions = in_array($version, [0, 1], true)
        ? [(int) $version]
        : (isset($versionCache[$cacheKey])
            ? [(int) $versionCache[$cacheKey], (int) $versionCache[$cacheKey] === 0 ? 1 : 0]
            : [0, 1]);
    $lastError = 'snmp_timeout';

    foreach ($versions as $candidateVersion) {
        $requestId = random_int(100000, 9999999);
        $packet = app_mgs_snmp_build_get_request($community, $oid, $requestId, $candidateVersion);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client('udp://' . $ip . ':' . $port, $errno, $errstr, max(0.3, $timeoutMs / 1000));
        if (!is_resource($socket)) {
            $lastError = $errstr !== '' ? $errstr : 'socket_open_failed';
            continue;
        }
        stream_set_timeout($socket, 0, max(100000, $timeoutMs * 1000));
        $written = @fwrite($socket, $packet);
        if ($written === false || $written <= 0) {
            fclose($socket);
            $lastError = 'socket_write_failed';
            continue;
        }
        $response = @fread($socket, 4096);
        $meta = stream_get_meta_data($socket);
        fclose($socket);
        if (!is_string($response) || $response === '' || ($meta['timed_out'] ?? false) === true) {
            $lastError = ($meta['timed_out'] ?? false) ? 'snmp_timeout' : 'empty_snmp_response';
            continue;
        }

        $decoded = app_mgs_snmp_decode_response($response);
        if (empty($decoded['ok']) || $decoded['varbinds'] === []) {
            $lastError = (string) ($decoded['error'] ?? 'decode_failed');
            continue;
        }
        if (($decoded['community'] ?? null) !== $community ||
            (int) ($decoded['version'] ?? -1) !== $candidateVersion ||
            (int) ($decoded['request_id'] ?? -1) !== $requestId ||
            (int) ($decoded['pdu_tag'] ?? 0) !== 0xA2) {
            $lastError = 'snmp_response_mismatch';
            continue;
        }
        if ((int) $decoded['error_status'] !== 0) {
            $lastError = 'snmp_error_status_' . $decoded['error_status'];
            continue;
        }
        $varbind = $decoded['varbinds'][0];
        $versionCache[$cacheKey] = $candidateVersion;

        return [
            'ok' => true,
            'oid' => (string) $varbind['oid'],
            'tag' => (int) $varbind['tag'],
            'value' => app_mgs_snmp_decode_value((int) $varbind['tag'], (string) $varbind['raw']),
            'version' => $candidateVersion,
            'bytes' => strlen($response),
        ];
    }

    return ['ok' => false, 'error' => $lastError];
}

/**
 * IF-MIB 32-bit octet counters and ifXTable (RFC2863) 64-bit "high capacity"
 * counters for the same columns. HC counters are tried first: a 32-bit
 * Counter32 wraps every ~34s on a saturated 1Gbps link, which is exactly the
 * kind of interface this collector needs to poll -- ifSpeed already reports
 * 1Gbps+ links in this dataset (see device_interfaces). Devices too old to
 * support ifXTable fall back to the 32-bit pair.
 */
function app_mgs_interface_octet_oids(): array
{
    return [
        'hc_in' => '1.3.6.1.2.1.31.1.1.1.6',
        'hc_out' => '1.3.6.1.2.1.31.1.1.1.10',
        'in' => '1.3.6.1.2.1.2.2.1.10',
        'out' => '1.3.6.1.2.1.2.2.1.16',
    ];
}

/**
 * Reads one interface's in/out octet counters, preferring the 64-bit HC
 * pair and falling back to the 32-bit pair only if either HC OID doesn't
 * resolve (both must succeed together so a delta is never computed across
 * mismatched counter widths).
 */
function app_mgs_read_interface_octets(string $ip, string $community, int $port, int $ifIndex, int $timeoutMs): array
{
    $oids = app_mgs_interface_octet_oids();
    $hcIn = app_mgs_snmp_get_raw($ip, $community, $port, $oids['hc_in'] . '.' . $ifIndex, $timeoutMs);
    $hcOut = app_mgs_snmp_get_raw($ip, $community, $port, $oids['hc_out'] . '.' . $ifIndex, $timeoutMs);
    if (!empty($hcIn['ok']) && !empty($hcOut['ok']) && is_int($hcIn['value']) && is_int($hcOut['value'])) {
        return ['ok' => true, 'in_octets' => $hcIn['value'], 'out_octets' => $hcOut['value'], 'counter_bits' => 64];
    }

    $in = app_mgs_snmp_get_raw($ip, $community, $port, $oids['in'] . '.' . $ifIndex, $timeoutMs);
    $out = app_mgs_snmp_get_raw($ip, $community, $port, $oids['out'] . '.' . $ifIndex, $timeoutMs);
    if (!empty($in['ok']) && !empty($out['ok']) && is_int($in['value']) && is_int($out['value'])) {
        return ['ok' => true, 'in_octets' => $in['value'], 'out_octets' => $out['value'], 'counter_bits' => 32];
    }

    return ['ok' => false, 'error' => 'octet_counters_unreadable'];
}

/**
 * Bits-per-second rate between two octet counter readings of the same
 * width, wraparound-aware for 32-bit Counter32 (the only width that can
 * realistically wrap between two polls). Returns null when a rate can't be
 * trusted: no previous sample, a counter-width change (device rebooted
 * ifXTable support, or vice versa), a non-positive time delta, a gap long
 * enough that a wrapped 32-bit counter would be indistinguishable from a
 * real decrease, or a counter reset (current < previous on a 64-bit counter,
 * which never legitimately wraps within any realistic poll gap).
 */
function app_mgs_interface_traffic_bps(int $currentOctets, int $previousOctets, int $counterBits, int $elapsedSeconds): ?int
{
    if ($elapsedSeconds <= 0) {
        return null;
    }
    if ($counterBits === 32) {
        if ($elapsedSeconds > 900) {
            // A gap this long makes a single wraparound indistinguishable
            // from several -- refuse to guess rather than report a wrong rate.
            return null;
        }
        $delta = $currentOctets >= $previousOctets
            ? $currentOctets - $previousOctets
            : (4294967296 - $previousOctets) + $currentOctets;
    } else {
        if ($currentOctets < $previousOctets) {
            return null;
        }
        $delta = $currentOctets - $previousOctets;
    }

    return (int) round(($delta * 8) / $elapsedSeconds);
}

/**
 * Polls in/out octet counters for every known interface (device_interfaces)
 * of every monitored device, computes a bits-per-second rate against the
 * previous sample, and stores it. Dry-run by default -- $apply=false reports
 * what would be polled/stored without writing, matching this codebase's
 * cron-worker convention (tools/billing-maintenance.php et al).
 */
function app_mgs_poll_device_interface_traffic(
    PDO $db,
    array $actor,
    bool $apply,
    ?string $onlyDeviceKey = null,
    int $maxInterfacesPerDevice = 200
): array {
    $hasSamplesTable = (int) $db->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "device_interface_traffic_samples"'
    )->fetchColumn() > 0;
    if (!$hasSamplesTable) {
        return ['ok' => false, 'reason' => 'device_interface_traffic_samples_not_migrated'];
    }

    $deviceKeyStatement = $onlyDeviceKey !== null
        ? $db->prepare('SELECT DISTINCT device_key FROM device_interfaces WHERE device_key = :device_key')
        : $db->prepare('SELECT DISTINCT device_key FROM device_interfaces');
    $deviceKeyStatement->execute($onlyDeviceKey !== null ? ['device_key' => $onlyDeviceKey] : []);
    $deviceKeys = $deviceKeyStatement->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $sampledAt = date('Y-m-d H:i:s');
    $insertSample = $db->prepare(
        'INSERT INTO device_interface_traffic_samples
            (device_key, if_index, sampled_at, in_octets, out_octets, in_bps, out_bps, counter_bits)
         VALUES (:device_key, :if_index, :sampled_at, :in_octets, :out_octets, :in_bps, :out_bps, :counter_bits)
         ON DUPLICATE KEY UPDATE
            in_octets = VALUES(in_octets), out_octets = VALUES(out_octets),
            in_bps = VALUES(in_bps), out_bps = VALUES(out_bps), counter_bits = VALUES(counter_bits)'
    );
    $previousStatement = $db->prepare(
        'SELECT in_octets, out_octets, counter_bits, sampled_at
         FROM device_interface_traffic_samples
         WHERE device_key = :device_key AND if_index = :if_index
         ORDER BY sampled_at DESC LIMIT 1'
    );

    $polled = 0;
    $stored = 0;
    $failed = 0;
    $skipped = 0;
    $deviceFailures = [];
    $details = [];

    foreach ($deviceKeys as $deviceKey) {
        $device = app_mgs_device_learning_profile_by_key((string) $deviceKey, $db, $actor);
        if (!is_array($device) || empty($device['monitoring_enabled'])) {
            continue;
        }
        $ip = trim((string) ($device['ip_address'] ?? ''));
        if ($ip === '') {
            continue;
        }
        $authorization = app_noc_authorize($actor, 'probe_device', $device, $db);
        if (empty($authorization['ok'])) {
            continue;
        }
        $accessProfile = app_mgs_device_access_profile($device, $db, $actor, 'probe_device');
        $community = (string) ($accessProfile['read_community'] ?? '');
        $port = (int) ($accessProfile['port'] ?? 161);
        if ($community === '') {
            continue;
        }

        $ifaceStatement = $db->prepare('SELECT if_index FROM device_interfaces WHERE device_key = :device_key ORDER BY if_index ASC LIMIT ' . max(1, $maxInterfacesPerDevice));
        $ifaceStatement->execute(['device_key' => $deviceKey]);
        $interfaceIndexes = $ifaceStatement->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $consecutiveFailures = 0;
        $interfaceCount = count($interfaceIndexes);
        foreach ($interfaceIndexes as $position => $ifIndex) {
            $ifIndex = (int) $ifIndex;
            $polled++;
            $reading = app_mgs_read_interface_octets($ip, $community, $port, $ifIndex, 700);
            if (empty($reading['ok'])) {
                $failed++;
                $consecutiveFailures++;
                // A device-wide SNMP outage previously caused one scheduled
                // pass to spend minutes retrying every known interface. Three
                // consecutive failures are enough evidence to stop this
                // device for the current pass while allowing the remaining
                // devices to be sampled and the supervisor to stay healthy.
                if ($consecutiveFailures >= 3) {
                    $remaining = max(0, $interfaceCount - ((int) $position + 1));
                    $skipped += $remaining;
                    $deviceFailures[] = [
                        'device_key' => (string) $deviceKey,
                        'reason' => 'snmp_consecutive_failures',
                        'failed_attempts' => $consecutiveFailures,
                        'skipped_interfaces' => $remaining,
                    ];
                    break;
                }
                continue;
            }
            $consecutiveFailures = 0;

            $previousStatement->execute(['device_key' => $deviceKey, 'if_index' => $ifIndex]);
            $previous = $previousStatement->fetch(PDO::FETCH_ASSOC);
            $inBps = null;
            $outBps = null;
            if (is_array($previous) && (int) $previous['counter_bits'] === (int) $reading['counter_bits']) {
                $elapsedSeconds = strtotime($sampledAt) - strtotime((string) $previous['sampled_at']);
                $inBps = app_mgs_interface_traffic_bps((int) $reading['in_octets'], (int) $previous['in_octets'], (int) $reading['counter_bits'], $elapsedSeconds);
                $outBps = app_mgs_interface_traffic_bps((int) $reading['out_octets'], (int) $previous['out_octets'], (int) $reading['counter_bits'], $elapsedSeconds);
            }

            if ($apply) {
                $insertSample->execute([
                    'device_key' => $deviceKey,
                    'if_index' => $ifIndex,
                    'sampled_at' => $sampledAt,
                    'in_octets' => $reading['in_octets'],
                    'out_octets' => $reading['out_octets'],
                    'in_bps' => $inBps,
                    'out_bps' => $outBps,
                    'counter_bits' => $reading['counter_bits'],
                ]);
                $stored++;
            }
            $details[] = ['device_key' => $deviceKey, 'if_index' => $ifIndex, 'in_bps' => $inBps, 'out_bps' => $outBps, 'counter_bits' => $reading['counter_bits']];
        }
    }

    if ($apply) {
        app_mgs_record_operation_action([
            'action_type' => 'device_interface_traffic_poll',
            'entity_type' => 'device_interface_traffic_samples',
            'entity_id' => $onlyDeviceKey ?? 'all',
            'status' => 'executed',
            'actor' => $actor,
            'summary' => $stored . '/' . $polled . ' interfeys trafik nümunəsi saxlanıldı',
            'payload' => ['polled' => $polled, 'stored' => $stored, 'failed' => $failed,
                          'skipped' => $skipped, 'device_failures' => $deviceFailures],
        ]);
    }

    return ['ok' => true, 'mode' => $apply ? 'apply' : 'dry_run', 'sampled_at' => $sampledAt,
            'polled' => $polled, 'stored' => $stored, 'failed' => $failed, 'skipped' => $skipped,
            'device_failures' => $deviceFailures, 'details' => $details];
}

/**
 * Re-aggregates the last $lookbackHours of raw samples into
 * device_interface_traffic_hourly. Idempotent (ON DUPLICATE KEY UPDATE) and
 * always re-covers a small trailing window rather than tracking "last
 * rolled-up hour" state, so a missed/late worker run self-heals on the next
 * one instead of leaving a permanent gap.
 */
function app_mgs_rollup_device_interface_traffic_hourly(PDO $db, int $lookbackHours = 25): array
{
    $statement = $db->prepare(
        'INSERT INTO device_interface_traffic_hourly
            (device_key, if_index, hour_start, avg_in_bps, avg_out_bps, max_in_bps, max_out_bps, sample_count)
         SELECT device_key, if_index,
                DATE_FORMAT(sampled_at, "%Y-%m-%d %H:00:00") AS hour_start,
                COALESCE(AVG(in_bps), 0), COALESCE(AVG(out_bps), 0),
                COALESCE(MAX(in_bps), 0), COALESCE(MAX(out_bps), 0),
                COUNT(*)
         FROM device_interface_traffic_samples
         WHERE sampled_at >= (NOW() - INTERVAL :lookback_hours HOUR)
         GROUP BY device_key, if_index, hour_start
         ON DUPLICATE KEY UPDATE
            avg_in_bps = VALUES(avg_in_bps), avg_out_bps = VALUES(avg_out_bps),
            max_in_bps = VALUES(max_in_bps), max_out_bps = VALUES(max_out_bps),
            sample_count = VALUES(sample_count)'
    );
    $statement->execute(['lookback_hours' => max(1, $lookbackHours)]);

    return ['ok' => true, 'rows_affected' => $statement->rowCount()];
}

/**
 * Same idempotent re-aggregation as the hourly rollup, one level up
 * (hourly -> daily).
 */
function app_mgs_rollup_device_interface_traffic_daily(PDO $db, int $lookbackDays = 3): array
{
    $statement = $db->prepare(
        'INSERT INTO device_interface_traffic_daily
            (device_key, if_index, day_start, avg_in_bps, avg_out_bps, max_in_bps, max_out_bps, sample_count)
         SELECT device_key, if_index,
                DATE(hour_start) AS day_start,
                COALESCE(AVG(avg_in_bps), 0), COALESCE(AVG(avg_out_bps), 0),
                COALESCE(MAX(max_in_bps), 0), COALESCE(MAX(max_out_bps), 0),
                SUM(sample_count)
         FROM device_interface_traffic_hourly
         WHERE hour_start >= (NOW() - INTERVAL :lookback_days DAY)
         GROUP BY device_key, if_index, day_start
         ON DUPLICATE KEY UPDATE
            avg_in_bps = VALUES(avg_in_bps), avg_out_bps = VALUES(avg_out_bps),
            max_in_bps = VALUES(max_in_bps), max_out_bps = VALUES(max_out_bps),
            sample_count = VALUES(sample_count)'
    );
    $statement->execute(['lookback_days' => max(1, $lookbackDays)]);

    return ['ok' => true, 'rows_affected' => $statement->rowCount()];
}

/**
 * Deletes raw samples older than $retainDays. Hourly/daily rollups (already
 * aggregated, orders of magnitude smaller) are kept indefinitely -- this
 * only bounds the high-volume raw table, matching the vision doc's "yüksək
 * həcmli trafik jurnalları əməliyyat bazasını yükləməməlidir" guidance
 * (docs/00_VISION.md 5.3, written about CGNAT logs but the same concern
 * applies here).
 */
function app_mgs_prune_device_interface_traffic_samples(PDO $db, int $retainDays = 30): array
{
    $statement = $db->prepare('DELETE FROM device_interface_traffic_samples WHERE sampled_at < (NOW() - INTERVAL :retain_days DAY)');
    $statement->execute(['retain_days' => max(1, $retainDays)]);

    return ['ok' => true, 'deleted' => $statement->rowCount()];
}

/**
 * SNMPv1 standard generic-trap codes (RFC1157). Only these -- never
 * enterprise-specific (6) or vendor-specific v2c trap OIDs, which need a
 * per-vendor catalog we don't have -- are eligible for the conservative
 * alarm bridge in app_mgs_record_snmp_trap(). linkUp is deliberately
 * excluded: it is a recovery signal, not a problem, and auto-alarming on
 * recovery is exactly the alert-fatigue pattern docs/00_VISION.md 6.2 warns
 * against ("40 ayrı problem yaratmamalı").
 */
function app_mgs_snmp_v1_generic_trap_labels(): array
{
    return [0 => 'coldStart', 1 => 'warmStart', 2 => 'linkDown', 3 => 'linkUp', 4 => 'authenticationFailure', 5 => 'egpNeighborLoss', 6 => 'enterpriseSpecific'];
}

function app_mgs_snmp_v1_generic_trap_alarm_severity(int $genericTrap): ?string
{
    return match ($genericTrap) {
        2 => 'critical', // linkDown
        0, 1 => 'warning', // coldStart / warmStart -- unexpected reboot
        4 => 'warning', // authenticationFailure -- security-relevant
        default => null, // linkUp, egpNeighborLoss, enterpriseSpecific: log-only
    };
}

/**
 * Decodes an SNMPv1 Trap-PDU's content (already unwrapped from its 0xA4
 * outer TLV): enterprise(OID), agent-addr(IpAddress), generic-trap(INTEGER),
 * specific-trap(INTEGER), time-stamp(TimeTicks), variable-bindings(SEQUENCE).
 * Structurally different from a GetResponse-PDU, so it needs its own parser
 * rather than reusing app_mgs_snmp_decode_response().
 */
function app_mgs_snmp_decode_v1_trap_pdu(string $pduBody): array
{
    $enterprise = app_mgs_snmp_ber_read_tlv($pduBody, 0);
    if ($enterprise === null || $enterprise['tag'] !== 0x06) {
        return ['ok' => false, 'error' => 'malformed_trap_enterprise'];
    }
    $agentAddr = app_mgs_snmp_ber_read_tlv($pduBody, $enterprise['next']);
    if ($agentAddr === null) {
        return ['ok' => false, 'error' => 'malformed_trap_agent_addr'];
    }
    $genericTrap = app_mgs_snmp_ber_read_tlv($pduBody, $agentAddr['next']);
    if ($genericTrap === null) {
        return ['ok' => false, 'error' => 'malformed_trap_generic'];
    }
    $specificTrap = app_mgs_snmp_ber_read_tlv($pduBody, $genericTrap['next']);
    if ($specificTrap === null) {
        return ['ok' => false, 'error' => 'malformed_trap_specific'];
    }
    $timeStamp = app_mgs_snmp_ber_read_tlv($pduBody, $specificTrap['next']);
    if ($timeStamp === null) {
        return ['ok' => false, 'error' => 'malformed_trap_timestamp'];
    }
    $varbindList = app_mgs_snmp_ber_read_tlv($pduBody, $timeStamp['next']);
    if ($varbindList === null || $varbindList['tag'] !== 0x30) {
        return ['ok' => false, 'error' => 'malformed_trap_varbind_list'];
    }

    $varbinds = [];
    $offset = 0;
    $listBody = $varbindList['value'];
    $listLength = strlen($listBody);
    while ($offset < $listLength) {
        $pair = app_mgs_snmp_ber_read_tlv($listBody, $offset);
        if ($pair === null || $pair['tag'] !== 0x30) {
            break;
        }
        $offset = $pair['next'];
        $oidTlv = app_mgs_snmp_ber_read_tlv($pair['value'], 0);
        if ($oidTlv === null || $oidTlv['tag'] !== 0x06) {
            break;
        }
        $valueTlv = app_mgs_snmp_ber_read_tlv($pair['value'], $oidTlv['next']);
        if ($valueTlv === null) {
            break;
        }
        $varbinds[] = ['oid' => app_mgs_snmp_ber_decode_oid($oidTlv['value']), 'tag' => $valueTlv['tag'], 'raw' => $valueTlv['value']];
    }

    $agentAddrBytes = (string) $agentAddr['value'];
    $agentAddrText = strlen($agentAddrBytes) === 4 ? implode('.', array_map('ord', str_split($agentAddrBytes))) : '';

    return [
        'ok' => true,
        'enterprise_oid' => app_mgs_snmp_ber_decode_oid($enterprise['value']),
        'agent_addr' => $agentAddrText,
        'generic_trap' => app_mgs_snmp_ber_decode_unsigned($genericTrap['value']),
        'specific_trap' => app_mgs_snmp_ber_decode_unsigned($specificTrap['value']),
        'uptime_ticks' => app_mgs_snmp_ber_decode_unsigned($timeStamp['value']),
        'varbinds' => $varbinds,
    ];
}

/**
 * Decodes a full incoming SNMP trap datagram (v1 Trap-PDU tag 0xA4, or
 * v2c SNMPv2-Trap-PDU/InformRequest tags 0xA7/0xA6 which share
 * GetResponse-PDU's shape and so reuse app_mgs_snmp_decode_response()).
 * Never throws -- an unrecognised or malformed datagram from the network
 * returns ok=false, exactly like the rest of this module's SNMP decoders.
 */
function app_mgs_snmp_decode_trap_packet(string $packet): array
{
    $outer = app_mgs_snmp_ber_read_tlv($packet, 0);
    if ($outer === null || $outer['tag'] !== 0x30) {
        return ['ok' => false, 'error' => 'malformed_packet'];
    }
    $body = $outer['value'];
    $version = app_mgs_snmp_ber_read_tlv($body, 0);
    if ($version === null) {
        return ['ok' => false, 'error' => 'malformed_version'];
    }
    $community = app_mgs_snmp_ber_read_tlv($body, $version['next']);
    if ($community === null) {
        return ['ok' => false, 'error' => 'malformed_community'];
    }
    $pdu = app_mgs_snmp_ber_read_tlv($body, $community['next']);
    if ($pdu === null) {
        return ['ok' => false, 'error' => 'malformed_pdu'];
    }

    $versionNumber = app_mgs_snmp_ber_decode_unsigned($version['value']);
    $communityText = (string) $community['value'];

    if ($pdu['tag'] === 0xA4) {
        $decoded = app_mgs_snmp_decode_v1_trap_pdu($pdu['value']);
        if (empty($decoded['ok'])) {
            return $decoded;
        }

        return [
            'ok' => true,
            'version' => 'v1',
            'community' => $communityText,
            'generic_trap' => (int) $decoded['generic_trap'],
            'specific_trap' => (int) $decoded['specific_trap'],
            'enterprise_oid' => (string) $decoded['enterprise_oid'],
            'trap_oid' => (string) $decoded['enterprise_oid'] . '.' . (int) $decoded['specific_trap'],
            'uptime_ticks' => (int) $decoded['uptime_ticks'],
            'varbinds' => $decoded['varbinds'],
        ];
    }

    if ($pdu['tag'] === 0xA7 || $pdu['tag'] === 0xA6) {
        $decoded = app_mgs_snmp_decode_response($packet);
        if (empty($decoded['ok'])) {
            return $decoded;
        }
        $uptimeTicks = null;
        $trapOid = null;
        foreach ($decoded['varbinds'] as $varbind) {
            $oid = (string) ($varbind['oid'] ?? '');
            if ($oid === '1.3.6.1.2.1.1.3.0') { // sysUpTime.0
                $uptimeTicks = app_mgs_snmp_decode_value((int) $varbind['tag'], (string) $varbind['raw']);
            } elseif ($oid === '1.3.6.1.6.3.1.1.4.1.0') { // snmpTrapOID.0
                $trapOid = app_mgs_snmp_decode_value((int) $varbind['tag'], (string) $varbind['raw']);
            }
        }

        return [
            'ok' => true,
            'version' => 'v2c',
            'community' => $communityText,
            'generic_trap' => null,
            'specific_trap' => null,
            'enterprise_oid' => null,
            'trap_oid' => is_string($trapOid) ? $trapOid : null,
            'uptime_ticks' => is_int($uptimeTicks) ? $uptimeTicks : null,
            'varbinds' => array_map(
                static fn (array $vb): array => ['oid' => $vb['oid'], 'tag' => $vb['tag'], 'raw' => $vb['raw']],
                $decoded['varbinds']
            ),
        ];
    }

    return ['ok' => false, 'error' => 'unsupported_pdu_tag_' . $pdu['tag']];
}

/**
 * Stores one already-decoded trap (app_mgs_snmp_decode_trap_packet()'s
 * output) plus its network origin. Resolves device_key by source IP against
 * device_learning_profiles (best-effort; a trap from an unknown/unregistered
 * source is still logged, never dropped -- audit visibility over blind
 * trust, matching docs/00_VISION.md 19.1's "menyunun gizlədilməsi
 * təhlükəsizlik deyil" principle applied to network input). Community is
 * checked against the configured expected trap community but a mismatch
 * only flags community_valid=0, it does not suppress the row.
 */
function app_mgs_record_snmp_trap(PDO $db, array $trap, string $sourceIp, int $sourcePort, array $actor = []): array
{
    $hasTable = (int) $db->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "snmp_trap_log"'
    )->fetchColumn() > 0;
    if (!$hasTable) {
        return ['ok' => false, 'reason' => 'snmp_trap_log_not_migrated'];
    }

    $expectedCommunity = trim((string) app_mgs_runtime_setting('snmp_trap_expected_community', ''));
    if ($expectedCommunity === '') {
        $expectedCommunity = trim((string) app_mgs_snmp_defaults()['read_community']);
    }
    $communityValid = $expectedCommunity !== '' && hash_equals($expectedCommunity, (string) ($trap['community'] ?? ''));

    $deviceKey = null;
    $deviceRow = $db->prepare('SELECT device_key FROM device_learning_profiles WHERE ip_address = :ip LIMIT 1');
    $deviceRow->execute(['ip' => $sourceIp]);
    $foundKey = $deviceRow->fetchColumn();
    if (is_string($foundKey) && $foundKey !== '') {
        $deviceKey = $foundKey;
    }

    $alarmBridged = false;
    $genericTrap = $trap['generic_trap'] ?? null;
    if ((string) ($trap['version'] ?? '') === 'v1' && is_int($genericTrap)) {
        $severity = app_mgs_snmp_v1_generic_trap_alarm_severity($genericTrap);
        if ($severity !== null) {
            $label = app_mgs_snmp_v1_generic_trap_labels()[$genericTrap] ?? ('generic_trap_' . $genericTrap);
            $alarmActor = $actor !== [] ? $actor : ['id' => 0, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'is_active' => 1, 'full_name' => 'SNMP Trap Listener (daemon)'];
            $alarmResult = app_mgs_record_alarm_event([
                'alarm_type' => 'SNMP_TRAP_' . strtoupper($label),
                'severity' => $severity,
                'source' => 'snmp_trap',
                'object_type' => 'device',
                'object_id' => $deviceKey ?? $sourceIp,
                'title' => 'SNMP trap: ' . $label . ' (' . $sourceIp . ')',
                'device_name' => $deviceKey ?? $sourceIp,
                'telegram_bot_key' => 'network',
            ], false, true, $alarmActor, $db);
            $alarmBridged = !empty($alarmResult['ok']);
        }
    }

    $statement = $db->prepare(
        'INSERT INTO snmp_trap_log
            (source_ip, source_port, device_key, snmp_version, community_valid, generic_trap, specific_trap, enterprise_oid, trap_oid, uptime_ticks, varbinds_json, alarm_bridged)
         VALUES (:source_ip, :source_port, :device_key, :snmp_version, :community_valid, :generic_trap, :specific_trap, :enterprise_oid, :trap_oid, :uptime_ticks, :varbinds_json, :alarm_bridged)'
    );
    $statement->execute([
        'source_ip' => $sourceIp,
        'source_port' => $sourcePort,
        'device_key' => $deviceKey,
        'snmp_version' => (string) ($trap['version'] ?? 'v1'),
        'community_valid' => $communityValid ? 1 : 0,
        'generic_trap' => $trap['generic_trap'] ?? null,
        'specific_trap' => $trap['specific_trap'] ?? null,
        'enterprise_oid' => $trap['enterprise_oid'] ?? null,
        'trap_oid' => $trap['trap_oid'] ?? null,
        'uptime_ticks' => $trap['uptime_ticks'] ?? null,
        'varbinds_json' => app_db_json_encode($trap['varbinds'] ?? []),
        'alarm_bridged' => $alarmBridged ? 1 : 0,
    ]);

    return ['ok' => true, 'id' => (int) $db->lastInsertId(), 'device_key' => $deviceKey, 'community_valid' => $communityValid, 'alarm_bridged' => $alarmBridged];
}

/**
 * LLDP-MIB (IEEE 802.1AB, OID root 1.0.8802.1.1.2) lldpRemEntry columns.
 * The table's real SNMP index is the 3-part {lldpRemTimeMark,
 * lldpRemLocalPortNum, lldpRemIndex} -- app_mgs_discover_device_neighbors()
 * groups rows by that raw trailing OID suffix (not by parsing it into
 * ifIndex) because lldpRemLocalPortNum is only usually, not guaranteed,
 * equal to the IF-MIB ifIndex used elsewhere in this module; see
 * docs/39_MGS_NODE_CONSOLIDATION.md for that caveat.
 */
function app_mgs_lldp_mib_columns(): array
{
    return [
        'remote_chassis_id' => '1.0.8802.1.1.2.1.4.1.1.5',
        'remote_port_id' => '1.0.8802.1.1.2.1.4.1.1.7',
        'remote_port_desc' => '1.0.8802.1.1.2.1.4.1.1.8',
        'remote_sys_name' => '1.0.8802.1.1.2.1.4.1.1.9',
    ];
}

/**
 * CISCO-CDP-MIB (OID root 1.3.6.1.4.1.9.9.23, Cisco enterprise 9) cdpCacheEntry
 * columns. Table index is {cdpCacheIfIndex, cdpCacheDeviceIndex} -- unlike
 * LLDP, cdpCacheIfIndex genuinely is the IF-MIB ifIndex (Cisco's own MIB
 * documents this), so local_if_index is reliable for CDP rows.
 */
function app_mgs_cdp_mib_columns(): array
{
    return [
        'remote_chassis_id' => '1.3.6.1.4.1.9.9.23.1.2.1.1.6',
        'remote_port_id' => '1.3.6.1.4.1.9.9.23.1.2.1.1.7',
        'remote_platform' => '1.3.6.1.4.1.9.9.23.1.2.1.1.8',
    ];
}

function app_mgs_snmp_oid_index_suffix(string $oid, string $baseOid): ?string
{
    $prefix = $baseOid . '.';

    return str_starts_with($oid, $prefix) ? substr($oid, strlen($prefix)) : null;
}

/**
 * Best-effort MAC formatting shared by interface (ifPhysAddress) and
 * neighbor (LLDP chassis/port ID when chassisIdSubtype/portIdSubtype
 * indicates a MAC address) discovery: a 6-byte OCTET STRING is formatted as
 * AA:BB:CC:DD:EE:FF, anything else is returned as-is (already a readable
 * string in most other subtype cases).
 */
function app_mgs_format_possible_mac(string $raw): string
{
    return strlen($raw) === 6 ? strtoupper(implode(':', str_split(bin2hex($raw), 2))) : $raw;
}

/**
 * Discovers (or re-syncs) one device's LLDP and/or CDP neighbor table via a
 * real SNMP walk and stores it in device_neighbors -- the last Faza 1 gap
 * docs/39_MGS_NODE_CONSOLIDATION.md calls out. Tries both protocols (a
 * device can run both, and they are stored as distinct protocol rows keyed
 * on the same device_key, so neither suppresses the other); a protocol with
 * an empty remote_chassis_id column simply contributes zero rows rather
 * than failing the whole call, since plenty of real devices only speak one
 * of the two.
 */
function app_mgs_discover_device_neighbors(array $device, ?PDO $db = null, array $actor = [], int $maxNeighbors = 128): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }
    $deviceKey = (string) ($device['device_key'] ?? '');
    $ip = trim((string) ($device['ip_address'] ?? ''));
    if ($deviceKey === '' || $ip === '') {
        return ['ok' => false, 'reason' => 'missing_device_target'];
    }
    $authorization = app_noc_authorize($actor, 'probe_device', $device, $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('probe_device', $authorization);
    }

    $accessProfile = app_mgs_device_access_profile($device, $db, $actor, 'probe_device');
    $community = (string) ($accessProfile['read_community'] ?? '');
    $port = (int) ($accessProfile['port'] ?? 161);
    if ($community === '') {
        return ['ok' => false, 'reason' => 'missing_snmp_config'];
    }

    $upsert = $db->prepare(
        'INSERT INTO device_neighbors
            (device_key, protocol, local_index_key, local_if_index, remote_chassis_id, remote_port_id, remote_port_desc, remote_sys_name, remote_platform, last_seen_at)
         VALUES (:device_key, :protocol, :local_index_key, :local_if_index, :remote_chassis_id, :remote_port_id, :remote_port_desc, :remote_sys_name, :remote_platform, NOW())
         ON DUPLICATE KEY UPDATE
            local_if_index = VALUES(local_if_index),
            remote_chassis_id = VALUES(remote_chassis_id),
            remote_port_id = VALUES(remote_port_id),
            remote_port_desc = VALUES(remote_port_desc),
            remote_sys_name = VALUES(remote_sys_name),
            remote_platform = VALUES(remote_platform),
            last_seen_at = NOW()'
    );

    $stored = 0;
    $byProtocol = ['lldp' => 0, 'cdp' => 0];

    foreach (['lldp' => app_mgs_lldp_mib_columns(), 'cdp' => app_mgs_cdp_mib_columns()] as $protocol => $columns) {
        $rowsByIndexKey = [];
        foreach ($columns as $field => $baseOid) {
            $walked = app_mgs_snmp_walk_column($ip, $community, $port, $baseOid, 700, $maxNeighbors);
            foreach ($walked as $oid => $entry) {
                $indexKey = app_mgs_snmp_oid_index_suffix($oid, $baseOid);
                if ($indexKey === null) {
                    continue;
                }
                $rowsByIndexKey[$indexKey][$field] = $entry['value'];
            }
        }

        foreach ($rowsByIndexKey as $indexKey => $row) {
            $chassisRaw = (string) ($row['remote_chassis_id'] ?? '');
            if ($chassisRaw === '') {
                continue; // no identity for this row -- not a usable neighbor entry
            }
            $indexParts = array_map('intval', explode('.', $indexKey));
            $localIfIndex = $protocol === 'cdp'
                ? ($indexParts[0] ?? null) // cdpCacheIfIndex: real ifIndex
                : ($indexParts[count($indexParts) - 2] ?? null); // lldpRemLocalPortNum: usually ifIndex, not guaranteed

            $upsert->execute([
                'device_key' => $deviceKey,
                'protocol' => $protocol,
                'local_index_key' => $indexKey,
                'local_if_index' => $localIfIndex,
                'remote_chassis_id' => app_mgs_format_possible_mac($chassisRaw),
                'remote_port_id' => app_mgs_format_possible_mac((string) ($row['remote_port_id'] ?? '')),
                'remote_port_desc' => clean_text((string) ($row['remote_port_desc'] ?? '')),
                'remote_sys_name' => clean_text((string) ($row['remote_sys_name'] ?? '')),
                'remote_platform' => clean_text((string) ($row['remote_platform'] ?? '')),
            ]);
            $stored++;
            $byProtocol[$protocol]++;
        }
    }

    app_mgs_record_operation_action([
        'action_type' => 'device_neighbor_discovery',
        'entity_type' => 'device_learning_profiles',
        'entity_id' => $deviceKey,
        'status' => 'executed',
        'actor' => $actor,
        'summary' => $stored . ' qonşu aşkarlandı/yeniləndi (lldp=' . $byProtocol['lldp'] . ', cdp=' . $byProtocol['cdp'] . ')',
        'payload' => ['device_key' => $deviceKey, 'neighbor_count' => $stored, 'by_protocol' => $byProtocol],
    ]);

    return ['ok' => true, 'device_key' => $deviceKey, 'neighbor_count' => $stored, 'by_protocol' => $byProtocol];
}

/**
 * Read-only listing for a device's discovered neighbors (UI + smoke). Same
 * authorization as reading any other device detail.
 */
function app_mgs_device_neighbors(string $deviceKey, ?PDO $db = null, array $actor = []): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || $deviceKey === '') {
        return [];
    }
    $authorization = app_noc_authorize($actor, 'read_device', ['device_key' => $deviceKey], $db);
    if (empty($authorization['ok'])) {
        return [];
    }
    $statement = $db->prepare('SELECT * FROM device_neighbors WHERE device_key = :device_key ORDER BY protocol ASC, local_index_key ASC');
    $statement->execute(['device_key' => $deviceKey]);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Read-only, paginated listing of legacy_mgs_ont_inventory (the Node MGS
 * import from docs/39_MGS_NODE_CONSOLIDATION.md Faza 2 -- 730 historical
 * OLT-discovered ONT records with no real customer linkage; see that doc
 * for why they were never wired into customer_equipment_assignments). Not
 * a live monitoring source -- purely for admins/NOC to browse what was
 * imported. Same read_device authorization as any other device detail.
 */
function app_mgs_legacy_ont_inventory_rows(PDO $db, array $actor, array $filters = [], int $page = 1, int $perPage = 50): array
{
    $authorization = app_noc_authorize($actor, 'read_device', [], $db);
    if (empty($authorization['ok'])) {
        return ['ok' => false, 'rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 1, 'olts' => []];
    }

    $hasTable = (int) $db->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "legacy_mgs_ont_inventory"'
    )->fetchColumn() > 0;
    if (!$hasTable) {
        return ['ok' => false, 'rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 1, 'olts' => []];
    }

    $where = [];
    $params = [];
    $oltFilter = trim((string) ($filters['olt_device_key'] ?? ''));
    if ($oltFilter !== '') {
        $where[] = 'olt_device_key = :olt_device_key';
        $params['olt_device_key'] = $oltFilter;
    }
    $statusFilter = trim((string) ($filters['status'] ?? ''));
    if ($statusFilter !== '') {
        $where[] = 'status = :status';
        $params['status'] = $statusFilter;
    }
    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(serial_number LIKE :search OR port_key LIKE :search OR source_ont_id LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }
    $whereSql = $where !== [] ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countStatement = $db->prepare("SELECT COUNT(*) FROM legacy_mgs_ont_inventory $whereSql");
    $countStatement->execute($params);
    $total = (int) $countStatement->fetchColumn();

    $perPage = max(10, min(200, $perPage));
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($totalPages, $page));
    $offset = ($page - 1) * $perPage;

    $rowsStatement = $db->prepare(
        "SELECT * FROM legacy_mgs_ont_inventory $whereSql ORDER BY olt_device_key ASC, port_key ASC LIMIT $perPage OFFSET $offset"
    );
    $rowsStatement->execute($params);

    $olts = $db->query(
        'SELECT olt_device_key, COUNT(*) AS c FROM legacy_mgs_ont_inventory GROUP BY olt_device_key ORDER BY olt_device_key ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return [
        'ok' => true,
        'rows' => $rowsStatement->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'olts' => $olts,
    ];
}

function app_mgs_probe_device_services(
    string $ip,
    int $timeoutMs = 900,
    ?array $accessProfile = null,
    array $actor = [],
    array $device = [],
    ?PDO $db = null,
    string $authorizationAction = 'probe_device'
): array
{
    $target = array_replace(['ip_address' => trim($ip)], $device);
    $authorization = app_noc_authorize($actor, $authorizationAction, $target, $db);
    if (empty($authorization['ok'])) {
        return array_merge(
            ['reachable' => false, 'method' => 'none', 'services' => []],
            app_noc_authorization_failure($authorizationAction, $authorization)
        );
    }
    $base = app_mgs_probe_ip($ip, $timeoutMs, $actor, $target, $db, $authorizationAction);
    $sshPort = is_array($accessProfile) ? max(1, (int) ($accessProfile['ssh_port'] ?? 22)) : 22;
    $ssh = app_mgs_probe_tcp_port($ip, $sshPort, $timeoutMs, $actor, $target, $db, $authorizationAction);
    $sshAuth = (!empty($ssh['reachable']) && is_array($accessProfile))
        ? app_mgs_probe_ssh_auth(
            $ip,
            (string) ($accessProfile['ssh_username'] ?? ''),
            (string) ($accessProfile['ssh_password'] ?? ''),
            $sshPort,
            max(2000, $timeoutMs * 2),
            $actor,
            $target,
            $db,
            $authorizationAction
        )
        : ['reachable' => false, 'method' => 'ssh_auth', 'error' => 'missing_ssh_credentials'];
    $snmp = app_mgs_probe_snmp(
        $ip,
        is_array($accessProfile) ? (string) ($accessProfile['read_community'] ?? '') : null,
        is_array($accessProfile) ? (int) ($accessProfile['port'] ?? 161) : null,
        max(1200, $timeoutMs),
        $actor,
        $target,
        $db,
        $authorizationAction
    );
    $snmpPort = !empty($snmp['reachable'])
        ? ['reachable' => true, 'method' => (string) ($snmp['method'] ?? ''), 'error' => '']
        : app_mgs_probe_udp_port_nmap(
            $ip,
            161,
            max(1800, $timeoutMs * 2),
            $actor,
            $target,
            $db,
            $authorizationAction
        );

    $reachable = !empty($base['reachable']) || !empty($ssh['reachable']) || !empty($snmp['reachable']);
    $method = !empty($snmp['reachable'])
        ? (string) ($snmp['method'] ?? 'snmp_v1')
        : (!empty($sshAuth['reachable'])
            ? (string) ($sshAuth['method'] ?? 'ssh_auth')
            : (!empty($ssh['reachable'])
                ? (string) ($ssh['method'] ?? 'tcp_22')
                : (string) ($base['method'] ?? 'ping')));

    return [
        'reachable' => $reachable,
        'method' => $method,
        'error' => $reachable ? '' : (string) ($base['error'] ?? 'no_reply'),
        'ssh' => $ssh,
        'ssh_auth' => $sshAuth,
        'snmp' => $snmp,
        'services' => [
            'ssh_reachable' => !empty($ssh['reachable']),
            'ssh_auth_reachable' => !empty($sshAuth['reachable']),
            'snmp_reachable' => !empty($snmp['reachable']),
            'snmp_port_open' => !empty($snmpPort['reachable']),
            'ssh_method' => (string) ($ssh['method'] ?? ''),
            'ssh_auth_method' => (string) ($sshAuth['method'] ?? ''),
            'snmp_method' => (string) ($snmp['method'] ?? ''),
            'snmp_port_method' => (string) ($snmpPort['method'] ?? ''),
            'ssh_error' => (string) ($ssh['error'] ?? ''),
            'ssh_auth_error' => (string) ($sshAuth['error'] ?? ''),
            'snmp_error' => (string) ($snmp['error'] ?? ''),
            'snmp_port_error' => (string) ($snmpPort['error'] ?? ''),
            'ssh_username' => is_array($accessProfile) ? (string) ($accessProfile['ssh_username'] ?? '') : '',
            'ssh_port' => $sshPort,
            // Never persist or return the decrypted community in probe
            // telemetry. Callers only need to know whether a credential was
            // available for the attempted SNMP request.
            'snmp_read_community_set' => is_array($accessProfile)
                && (string) ($accessProfile['read_community'] ?? '') !== '',
        ],
    ];
}

function app_mgs_default_device_profiles(): array
{
    return [
        ['core-sptech-asr1006-mgmt', 'SPTECH ASR1006 Core', '172.16.5.5', 'router', 'Cisco', 'ASR1006', 'CiscoRouterAdapter', 'vlan550', 1, 1, ['interfaces', 'cpu', 'memory', 'pppoe_sessions', 'qfp_drops', 'traffic_counters']],
        ['core-sptech-asr1006-public', 'SPTECH ASR1006 Core public', '85.132.57.218', 'router', 'Cisco', 'ASR1006', 'CiscoRouterAdapter', 'public', 0, 0, ['interfaces', 'cpu', 'memory', 'pppoe_sessions']],
        ['olt-hsgq-172-16-5-17', 'HSGQ 172.16.5.17', '172.16.5.17', 'olt', 'HSGQ', 'G08L', 'HSGQOltAdapter', 'vlan550', 1, 1, ['ont_list', 'ont_optical', 'pon_statistics', 'sfp_status', 'mac_search']],
        ['olt-hsgq-kurdexani-20', 'Kurdexani HSGQ 20', '172.16.5.20', 'olt', 'HSGQ', 'G08L', 'HSGQOltAdapter', 'vlan550', 1, 1, ['ont_list', 'ont_optical', 'pon_statistics', 'sfp_status', 'mac_search']],
        ['olt-hsgq-kurdexani-21', 'kurdexani 21', '172.16.5.21', 'olt', 'HSGQ', 'G08L', 'HSGQOltAdapter', 'vlan550', 1, 1, ['ont_list', 'ont_optical', 'pon_statistics', 'sfp_status', 'mac_search']],
        ['olt-hsgq-kurdexani-22', 'kurdexani 22', '172.16.5.22', 'olt', 'HSGQ', 'G08L', 'HSGQOltAdapter', 'vlan550', 0, 0, ['ont_list', 'ont_optical', 'pon_statistics', 'sfp_status', 'mac_search']],
        ['olt-hsgq-172-16-5-26', 'HSGQ 172.16.5.26', '172.16.5.26', 'olt', 'HSGQ', 'G08L', 'HSGQOltAdapter', 'vlan550', 1, 1, ['ont_list', 'ont_optical', 'pon_statistics', 'sfp_status', 'mac_search']],
        ['olt-hsgq-172-16-5-29', 'HSGQ 172.16.5.29', '172.16.5.29', 'olt', 'HSGQ', 'G08L', 'HSGQOltAdapter', 'vlan550', 1, 1, ['ont_list', 'ont_optical', 'pon_statistics', 'sfp_status', 'mac_search']],
        ['switch-cisco-2960-172-16-5-41', 'kurdexaniOfissvitch', '172.16.5.41', 'switch', 'Cisco', '2960', 'CiscoSwitchAdapter', 'vlan550', 1, 1, ['interfaces', 'mac_table', 'vlan_list', 'stp_status', 'port_shutdown']],
    ];
}

function app_mgs_seed_device_learning_profiles(
    ?PDO $db = null,
    array $actor = [],
    string $purposeAction = 'seed_device_profiles'
): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['seeded' => 0, 'profiles' => []];
    }
    $authorization = app_noc_authorize($actor, $purposeAction, [], $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure($purposeAction, $authorization) + ['seeded' => 0, 'profiles' => []];
    }

    $statement = $db->prepare(
        'INSERT INTO device_learning_profiles (
            device_key, device_name, ip_address, device_type, vendor, model, adapter_key, management_network,
            expected_reachability, monitoring_enabled, reachability_state, confidence_score, features_json, command_map_json, snmp_oids_json, status
         ) VALUES (
            :device_key, :device_name, :ip_address, :device_type, :vendor, :model, :adapter_key, :management_network,
            :expected_reachability, :monitoring_enabled, :reachability_state, :confidence_score, :features_json, :command_map_json, :snmp_oids_json, :status
         )
         ON DUPLICATE KEY UPDATE
            device_name = VALUES(device_name),
            ip_address = VALUES(ip_address),
            device_type = VALUES(device_type),
            vendor = VALUES(vendor),
            model = VALUES(model),
            adapter_key = VALUES(adapter_key),
            management_network = VALUES(management_network),
            reachability_state = CASE
                WHEN VALUES(reachability_state) = "unreachable_because_monitor_path_down" THEN VALUES(reachability_state)
                WHEN reachability_state IN ("unreachable_because_monitor_path_down", "unknown") THEN VALUES(reachability_state)
                ELSE reachability_state
            END,
            status = CASE
                WHEN VALUES(status) = "waiting_mgmt_path" THEN VALUES(status)
                WHEN status IN ("waiting_mgmt_path", "pending_discovery") THEN VALUES(status)
                ELSE status
            END,
            confidence_score = GREATEST(confidence_score, VALUES(confidence_score)),
            features_json = VALUES(features_json),
            command_map_json = CASE
                WHEN command_map_json IS NULL OR command_map_json = "" OR command_map_json = "[]" THEN VALUES(command_map_json)
                ELSE command_map_json
            END,
            snmp_oids_json = VALUES(snmp_oids_json)'
    );

    $profiles = [];
    $monitorPathReady = app_mgs_management_path_ready();
    foreach (app_mgs_default_device_profiles() as $profile) {
        [$key, $name, $ip, $type, $vendor, $model, $adapter, $network, $expected, $monitoringEnabled, $features] = $profile;
        $isInternal = str_starts_with((string) $ip, '10.') || str_starts_with((string) $ip, '172.16.') || str_starts_with((string) $ip, '192.168.');
        $reachabilityState = (int) $monitoringEnabled === 1
            ? (($isInternal && !$monitorPathReady) ? 'unreachable_because_monitor_path_down' : 'unknown')
            : 'not_monitored';
        $status = (int) $monitoringEnabled === 1
            ? (($isInternal && !$monitorPathReady) ? 'waiting_mgmt_path' : 'pending_discovery')
            : (($key === 'core-sptech-asr1006-public') ? 'learned' : 'pending_discovery');
        $confidence = $vendor !== 'unknown' ? 0.65 : 0.25;
        $commandMap = match ($adapter) {
            'CiscoRouterAdapter' => ['show_version' => 'show version', 'interfaces' => 'show interfaces', 'pppoe' => 'show pppoe session', 'qfp' => 'show platform hardware qfp active statistics drop'],
            'CiscoSwitchAdapter' => ['show_version' => 'show version', 'interfaces' => 'show interfaces status', 'mac_table' => 'show mac address-table', 'vlans' => 'show vlan brief'],
            'HSGQOltAdapter' => [
                'enter_enable' => 'enable',
                'enter_config' => 'configure',
                'enter_gpon_context' => 'interface gpon {pon}',
                'gpon_context_help' => '?',
                'gpon_show_help' => 'show ?',
                'ont_info_help' => 'show ont-info ?',
                'ont_info_single' => 'show ont-info {ont}',
                'ont_info_all' => 'show ont-info all',
                'ont_optical_help' => 'show ont-optical ?',
                'ont_optical_single' => 'show ont-optical {ont}',
                'ont_optical_all' => 'show ont-optical all',
                'optical_state' => 'show optical-state',
                'port_statistics' => 'show statistic',
                'gpon_statistics' => 'show gpon statistic',
                'port_transceiver' => 'show port transceiver',
                'ont_port_vlan' => 'show ont-port-vlan {ont}',
                'ont_node' => 'ont ?',
                'port_node' => 'port ?',
                'gpon_interface_node' => 'interface gpon ?',
            ],
            default => ['interfaces' => 'show interfaces'],
        };
        $snmpOids = [
            'if_status' => '1.3.6.1.2.1.2.2.1.8',
            'if_hc_in' => '1.3.6.1.2.1.31.1.1.1.6',
            'if_hc_out' => '1.3.6.1.2.1.31.1.1.1.10',
        ];
        $statement->execute([
            'device_key' => $key,
            'device_name' => $name,
            'ip_address' => $ip,
            'device_type' => $type,
            'vendor' => $vendor,
            'model' => $model,
            'adapter_key' => $adapter,
            'management_network' => $network,
            'expected_reachability' => (int) $expected,
            'monitoring_enabled' => (int) $monitoringEnabled,
            'reachability_state' => $reachabilityState,
            'confidence_score' => $confidence,
            'features_json' => app_db_json_encode(array_fill_keys($features, true)),
            'command_map_json' => app_db_json_encode($commandMap),
            'snmp_oids_json' => app_db_json_encode($snmpOids),
            'status' => $status,
        ]);
        $profiles[] = ['device_key' => $key, 'name' => $name, 'ip' => $ip, 'status' => $status];
    }

    return ['seeded' => count($profiles), 'profiles' => $profiles];
}

function app_mgs_refresh_device_reachability(?PDO $db = null, bool $probe = false, array $actor = []): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }
    $authorization = app_noc_authorize($actor, 'probe_network', [], $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('probe_network', $authorization);
    }

    $seed = app_mgs_seed_device_learning_profiles($db, $actor, 'probe_network');
    if (isset($seed['ok']) && empty($seed['ok'])) {
        return $seed;
    }
    $pathReady = app_mgs_management_path_ready();

    if (!$pathReady) {
        $db->exec(
            'UPDATE device_learning_profiles
             SET reachability_state = "unreachable_because_monitor_path_down",
                 status = "waiting_mgmt_path",
                 last_probe_error = "management_path_down"
             WHERE management_network <> "public"'
        );

        app_mgs_sync_process_registry($db, $actor);
        return [
            'ok' => true,
            'mode' => 'path_down',
            'probed' => 0,
            'reachable' => 0,
            'unreachable' => 0,
            'suppressed' => (int) $db->query('SELECT COUNT(*) FROM device_learning_profiles WHERE management_network <> "public"')->fetchColumn(),
        ];
    }

    if (!$probe) {
        $db->exec(
            'UPDATE device_learning_profiles
             SET reachability_state = "unknown",
                 status = "pending_discovery",
                 last_probe_error = NULL
             WHERE management_network <> "public"
               AND reachability_state = "unreachable_because_monitor_path_down"'
        );

        return ['ok' => true, 'mode' => 'path_ready_pending_probe', 'probed' => 0, 'reachable' => 0, 'unreachable' => 0, 'suppressed' => 0];
    }

    $rows = $db->query(
        'SELECT *
         FROM device_learning_profiles
         WHERE monitoring_enabled = 1 AND expected_reachability = 1
         ORDER BY FIELD(device_type, "router", "switch", "olt", "server", "unknown"), device_name'
    )->fetchAll() ?: [];

    $update = $db->prepare(
        'UPDATE device_learning_profiles
         SET reachability_state = :reachability_state,
             status = :status,
             confidence_score = :confidence_score,
             last_successful_ping_at = :last_successful_ping_at,
             last_successful_ssh_at = :last_successful_ssh_at,
             last_successful_ssh_auth_at = :last_successful_ssh_auth_at,
             last_successful_snmp_at = :last_successful_snmp_at,
             last_probe_at = NOW(),
             last_probe_error = :last_probe_error,
             last_probe_method = :last_probe_method,
             service_probe_json = :service_probe_json
         WHERE id = :id'
    );

    $probed = 0;
    $reachable = 0;
    $unreachable = 0;
    $samples = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $probeResult = app_mgs_probe_device_services(
            (string) ($row['ip_address'] ?? ''),
            900,
            app_mgs_device_access_profile($row, $db, $actor, 'probe_network'),
            $actor,
            $row,
            $db,
            'probe_network'
        );
        $isReachable = !empty($probeResult['reachable']);
        $state = $isReachable ? 'reachable' : 'unreachable_confirmed';
        $status = $isReachable ? 'learned' : 'failed';
        $hasSsh = !empty($probeResult['services']['ssh_reachable']);
        $hasSshAuth = !empty($probeResult['services']['ssh_auth_reachable']);
        $hasSnmp = !empty($probeResult['services']['snmp_reachable']);
        $confidence = !$isReachable
            ? max(0.35, (float) ($row['confidence_score'] ?? 0.35))
            : (($hasSshAuth && $hasSnmp) ? 0.99 : (($hasSsh && $hasSnmp) ? 0.98 : (($hasSshAuth || $hasSnmp) ? 0.95 : (($hasSsh) ? 0.9 : 0.85))));
        $update->execute([
            'reachability_state' => $state,
            'status' => $status,
            'confidence_score' => $confidence,
            'last_successful_ping_at' => $isReachable ? app_db_now() : ($row['last_successful_ping_at'] ?? null),
            'last_successful_ssh_at' => $hasSsh ? app_db_now() : ($row['last_successful_ssh_at'] ?? null),
            'last_successful_ssh_auth_at' => $hasSshAuth ? app_db_now() : ($row['last_successful_ssh_auth_at'] ?? null),
            'last_successful_snmp_at' => $hasSnmp ? app_db_now() : ($row['last_successful_snmp_at'] ?? null),
            'last_probe_error' => $isReachable ? null : (string) ($probeResult['error'] ?? 'no_reply'),
            'last_probe_method' => (string) ($probeResult['method'] ?? ''),
            'service_probe_json' => app_db_json_encode($probeResult['services'] ?? []),
            'id' => (int) $row['id'],
        ]);

        $probed++;
        $reachable += $isReachable ? 1 : 0;
        $unreachable += $isReachable ? 0 : 1;
        $samples[] = [
            'device' => (string) ($row['device_name'] ?? ''),
            'ip' => (string) ($row['ip_address'] ?? ''),
            'state' => $state,
            'method' => (string) ($probeResult['method'] ?? ''),
            'ssh' => $hasSsh,
            'ssh_auth' => $hasSshAuth,
            'snmp' => $hasSnmp,
        ];
    }

    app_mgs_record_operation_action([
        'action_type' => 'device_reachability_probe',
        'entity_type' => 'device_learning',
        'entity_id' => 'vlan550',
        'status' => 'executed',
        'actor' => $actor,
        'summary' => 'Device reachability probe: ' . $reachable . '/' . $probed . ' reachable',
        'payload' => ['samples' => array_slice($samples, 0, 20), 'reachable' => $reachable, 'unreachable' => $unreachable],
    ]);

    return [
        'ok' => true,
        'mode' => 'probe',
        'probed' => $probed,
        'reachable' => $reachable,
        'unreachable' => $unreachable,
        'suppressed' => 0,
    ];
}

function app_mgs_device_learning_profile_by_key(string $deviceKey, ?PDO $db = null, array $actor = []): ?array
{
    $deviceKey = trim($deviceKey);
    $db = $db instanceof PDO ? $db : app_db();
    if ($deviceKey === '' || !$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return null;
    }

    $statement = $db->prepare('SELECT * FROM device_learning_profiles WHERE device_key = :device_key LIMIT 1');
    $statement->execute(['device_key' => $deviceKey]);
    $row = $statement->fetch();

    if (!is_array($row)) {
        return null;
    }
    $authorization = app_noc_authorize($actor, 'read_device', $row, $db);
    return !empty($authorization['ok']) ? $row : null;
}

function app_mgs_probe_single_device(string $deviceKey, ?PDO $db = null, array $actor = []): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }

    $device = app_mgs_device_learning_profile_by_key($deviceKey, $db, $actor);
    if ($device === null) {
        return ['ok' => false, 'reason' => 'device_not_found'];
    }
    $authorization = app_noc_authorize($actor, 'probe_device', $device, $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('probe_device', $authorization);
    }

    $deviceKey = (string) ($device['device_key'] ?? '');
    $deviceName = (string) ($device['device_name'] ?? $deviceKey);
    $managementNetwork = (string) ($device['management_network'] ?? '');
    $isInternal = $managementNetwork !== '' && strtolower($managementNetwork) !== 'public';

    if (!(bool) ($device['monitoring_enabled'] ?? true) || !(bool) ($device['expected_reachability'] ?? true)) {
        return ['ok' => true, 'mode' => 'skipped', 'reason' => 'not_monitored', 'device_key' => $deviceKey, 'device_name' => $deviceName];
    }

    $update = $db->prepare(
        'UPDATE device_learning_profiles
         SET reachability_state = :reachability_state,
             status = :status,
             confidence_score = :confidence_score,
             last_successful_ping_at = :last_successful_ping_at,
             last_successful_ssh_at = :last_successful_ssh_at,
             last_successful_ssh_auth_at = :last_successful_ssh_auth_at,
             last_successful_snmp_at = :last_successful_snmp_at,
             last_probe_at = NOW(),
             last_probe_error = :last_probe_error,
             last_probe_method = :last_probe_method,
             service_probe_json = :service_probe_json
         WHERE id = :id'
    );

    if ($isInternal && !app_mgs_management_path_ready()) {
        $update->execute([
            'reachability_state' => 'unreachable_because_monitor_path_down',
            'status' => 'waiting_mgmt_path',
            'confidence_score' => (float) ($device['confidence_score'] ?? 0.35),
            'last_successful_ping_at' => $device['last_successful_ping_at'] ?? null,
            'last_successful_ssh_at' => $device['last_successful_ssh_at'] ?? null,
            'last_successful_ssh_auth_at' => $device['last_successful_ssh_auth_at'] ?? null,
            'last_successful_snmp_at' => $device['last_successful_snmp_at'] ?? null,
            'last_probe_error' => 'management_path_down',
            'last_probe_method' => $device['last_probe_method'] ?? null,
            'service_probe_json' => $device['service_probe_json'] ?? null,
            'id' => (int) ($device['id'] ?? 0),
        ]);

        app_mgs_record_operation_action([
            'action_type' => 'device_probe_single',
            'entity_type' => 'device_learning',
            'entity_id' => $deviceKey,
            'status' => 'skipped',
            'actor' => $actor,
            'summary' => 'Single device probe skipped: management path down',
            'payload' => ['device_key' => $deviceKey, 'device_name' => $deviceName, 'reason' => 'management_path_down'],
        ]);

        return ['ok' => true, 'mode' => 'skipped', 'reason' => 'management_path_down', 'device_key' => $deviceKey, 'device_name' => $deviceName];
    }

    $probeResult = app_mgs_probe_device_services(
        (string) ($device['ip_address'] ?? ''),
        900,
        app_mgs_device_access_profile($device, $db, $actor, 'probe_device'),
        $actor,
        $device,
        $db,
        'probe_device'
    );
    $isReachable = !empty($probeResult['reachable']);
    $state = $isReachable ? 'reachable' : 'unreachable_confirmed';
    $status = $isReachable ? 'learned' : 'failed';
    $hasSsh = !empty($probeResult['services']['ssh_reachable']);
    $hasSshAuth = !empty($probeResult['services']['ssh_auth_reachable']);
    $hasSnmp = !empty($probeResult['services']['snmp_reachable']);
    $confidence = !$isReachable
        ? max(0.35, (float) ($device['confidence_score'] ?? 0.35))
        : (($hasSshAuth && $hasSnmp) ? 0.99 : (($hasSsh && $hasSnmp) ? 0.98 : (($hasSshAuth || $hasSnmp) ? 0.95 : (($hasSsh) ? 0.9 : 0.85))));
    $update->execute([
        'reachability_state' => $state,
        'status' => $status,
        'confidence_score' => $confidence,
        'last_successful_ping_at' => $isReachable ? app_db_now() : ($device['last_successful_ping_at'] ?? null),
        'last_successful_ssh_at' => $hasSsh ? app_db_now() : ($device['last_successful_ssh_at'] ?? null),
        'last_successful_ssh_auth_at' => $hasSshAuth ? app_db_now() : ($device['last_successful_ssh_auth_at'] ?? null),
        'last_successful_snmp_at' => $hasSnmp ? app_db_now() : ($device['last_successful_snmp_at'] ?? null),
        'last_probe_error' => $isReachable ? null : (string) ($probeResult['error'] ?? 'no_reply'),
        'last_probe_method' => (string) ($probeResult['method'] ?? ''),
        'service_probe_json' => app_db_json_encode($probeResult['services'] ?? []),
        'id' => (int) ($device['id'] ?? 0),
    ]);

    app_mgs_record_operation_action([
        'action_type' => 'device_probe_single',
        'entity_type' => 'device_learning',
        'entity_id' => $deviceKey,
        'status' => $isReachable ? 'executed' : 'failed',
        'actor' => $actor,
        'summary' => 'Single device probe: ' . $deviceName . ' -> ' . $state,
        'payload' => [
            'device_key' => $deviceKey,
            'device_name' => $deviceName,
            'ip_address' => (string) ($device['ip_address'] ?? ''),
            'state' => $state,
            'method' => (string) ($probeResult['method'] ?? ''),
            'error' => (string) ($probeResult['error'] ?? ''),
            'ssh' => $hasSsh,
            'ssh_auth' => $hasSshAuth,
            'snmp' => $hasSnmp,
        ],
    ]);

    return [
        'ok' => true,
        'mode' => 'probe',
        'device_key' => $deviceKey,
        'device_name' => $deviceName,
        'state' => $state,
        'reachable' => $isReachable,
        'method' => (string) ($probeResult['method'] ?? ''),
        'error' => (string) ($probeResult['error'] ?? ''),
        'ssh' => $hasSsh,
        'ssh_auth' => $hasSshAuth,
        'snmp' => $hasSnmp,
    ];
}

function app_mgs_set_device_monitoring_mode(string $deviceKey, string $mode, ?array $actor = null, ?PDO $db = null): array
{
    $actor = is_array($actor) ? $actor : [];
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }

    $device = app_mgs_device_learning_profile_by_key($deviceKey, $db, $actor);
    if (!is_array($device)) {
        return ['ok' => false, 'reason' => 'device_not_found'];
    }
    $authorization = app_noc_authorize($actor, 'update_monitoring_mode', $device, $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('update_monitoring_mode', $authorization);
    }

    $mode = trim(strtolower($mode));
    if (!in_array($mode, ['active', 'planned_down', 'public_alias'], true)) {
        return ['ok' => false, 'reason' => 'invalid_mode'];
    }

    $deviceName = (string) ($device['device_name'] ?? $deviceKey);
    $managementNetwork = strtolower((string) ($device['management_network'] ?? ''));
    $isInternal = $managementNetwork !== '' && $managementNetwork !== 'public';
    $pathReady = app_mgs_management_path_ready();

    $payload = match ($mode) {
        'active' => [
            'expected_reachability' => 1,
            'monitoring_enabled' => 1,
            'reachability_state' => $isInternal && !$pathReady ? 'unreachable_because_monitor_path_down' : 'unknown',
            'status' => $isInternal && !$pathReady ? 'waiting_mgmt_path' : 'pending_discovery',
            'last_probe_error' => null,
            'last_probe_method' => null,
            'service_probe_json' => null,
            'summary' => 'Active monitoring enabled',
        ],
        'planned_down' => [
            'expected_reachability' => 0,
            'monitoring_enabled' => 0,
            'reachability_state' => 'not_monitored',
            'status' => 'pending_discovery',
            'last_probe_error' => 'planned_down_not_up_yet',
            'last_probe_method' => 'manual_override',
            'service_probe_json' => app_db_json_encode(['ssh_reachable' => false, 'snmp_reachable' => false, 'mode' => 'planned_down']),
            'summary' => 'Planned down / not up yet',
        ],
        'public_alias' => [
            'expected_reachability' => 0,
            'monitoring_enabled' => 0,
            'reachability_state' => 'not_monitored',
            'status' => 'learned',
            'last_probe_error' => 'public_alias_via_172.16.5.5',
            'last_probe_method' => 'manual_override',
            'service_probe_json' => app_db_json_encode(['ssh_reachable' => false, 'snmp_reachable' => false, 'mode' => 'public_alias']),
            'summary' => 'Public IP alias is monitored via 172.16.5.5',
        ],
    };

    $update = $db->prepare(
        'UPDATE device_learning_profiles
         SET expected_reachability = :expected_reachability,
             monitoring_enabled = :monitoring_enabled,
             reachability_state = :reachability_state,
             status = :status,
             last_probe_at = NOW(),
             last_probe_error = :last_probe_error,
             last_probe_method = :last_probe_method,
             service_probe_json = :service_probe_json
         WHERE id = :id'
    );
    $update->execute([
        'expected_reachability' => (int) $payload['expected_reachability'],
        'monitoring_enabled' => (int) $payload['monitoring_enabled'],
        'reachability_state' => (string) $payload['reachability_state'],
        'status' => (string) $payload['status'],
        'last_probe_error' => $payload['last_probe_error'],
        'last_probe_method' => $payload['last_probe_method'],
        'service_probe_json' => $payload['service_probe_json'],
        'id' => (int) ($device['id'] ?? 0),
    ]);

    app_mgs_record_operation_action([
        'action_type' => 'device_monitoring_mode',
        'entity_type' => 'device_learning',
        'entity_id' => $deviceKey,
        'status' => 'executed',
        'actor' => $actor,
        'summary' => 'Device monitoring mode: ' . $deviceName . ' -> ' . $mode,
        'payload' => [
            'device_key' => $deviceKey,
            'device_name' => $deviceName,
            'mode' => $mode,
            'state' => $payload['reachability_state'],
            'monitoring_enabled' => (int) $payload['monitoring_enabled'],
            'expected_reachability' => (int) $payload['expected_reachability'],
            'note' => $payload['summary'],
        ],
    ]);

    return [
        'ok' => true,
        'device_key' => $deviceKey,
        'device_name' => $deviceName,
        'mode' => $mode,
        'state' => (string) $payload['reachability_state'],
        'summary' => (string) $payload['summary'],
    ];
}

function app_mgs_device_learning_rows(?PDO $db = null, array $actor = []): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }

    $authorization = app_noc_authorize($actor, 'read_device', [], $db);
    if (empty($authorization['ok'])) {
        return [];
    }
    $rows = $db->query('SELECT * FROM device_learning_profiles ORDER BY FIELD(device_type, "router", "olt", "switch", "server", "unknown"), device_name');
    return $rows !== false ? app_noc_filter_objects($rows->fetchAll() ?: [], $actor, $db) : [];
}

function app_mgs_bridge_status(): array
{
    $baseUrl = rtrim(app_config('mgs_internal_url', 'http://localhost:3000'), '/');
    $host = (string) (parse_url($baseUrl, PHP_URL_HOST) ?: '127.0.0.1');
    $scheme = (string) (parse_url($baseUrl, PHP_URL_SCHEME) ?: 'http');
    $port = (int) (parse_url($baseUrl, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
    $online = false;
    $error = '';

    $socket = @fsockopen($host, $port, $errno, $errstr, 0.4);
    if (is_resource($socket)) {
        $online = true;
        fclose($socket);
    } else {
        $error = trim((string) $errstr);
    }

    return [
        'url' => $baseUrl,
        'host' => $host,
        'port' => $port,
        'online' => $online,
        'message' => $online ? 'MGS Node bridge online' : ('MGS Node bridge offline' . ($error !== '' ? ': ' . $error : '')),
    ];
}

function app_mgs_render_alarm_message(array $alarm): string
{
    $severity = ucfirst(strtolower((string) ($alarm['severity'] ?? 'Info')));
    $device = trim((string) ($alarm['device_name'] ?? $alarm['object_id'] ?? '-'));
    $ip = trim((string) ($alarm['device_ip'] ?? ''));
    $problem = trim((string) ($alarm['human_problem'] ?? $alarm['title'] ?? 'Əməliyyat xəbərdarlığı'));
    $recommendation = trim((string) ($alarm['recommendation'] ?? 'Əvvəl hadisənin kontekstini, son dəyişiklikləri və asılı xidmətləri yoxlayın.'));

    $lines = [
        ($severity === 'Critical' ? 'KRİTİK ŞƏBƏKƏ' : 'ŞƏBƏKƏ') . ' XƏBƏRDARLIĞI',
        '',
        'Səviyyə: ' . $severity,
        'Avadanlıq: ' . $device,
    ];
    if ($ip !== '') {
        $lines[] = 'IP: ' . $ip;
    }
    $lines[] = 'Problem: ' . $problem;
    $lines[] = '';
    $lines[] = 'Tövsiyə:';
    $lines[] = $recommendation;

    $ticketNo = clean_text((string) ($alarm['ticket_no'] ?? ''));
    if ($ticketNo !== '') {
        $lines[] = '';
        $lines[] = 'Bilet: ' . $ticketNo;
        $ticket = app_portal_ticket_find($ticketNo);
        $closeLink = is_array($ticket) ? app_portal_ticket_close_link($ticket) : '';
        if ($closeLink !== '') {
            $lines[] = 'Bağla: ' . $closeLink;
        }
    }

    return implode("\n", $lines);
}

/**
 * Give operations staff an in-app copy of a real NOC event. The assigned
 * field employee receives the ticket notification through app_db_insert_ticket;
 * this copy is for operators and responsible network/management roles.
 */
function app_mgs_notify_mobile_alarm_recipients(PDO $db, int $alarmId, array $alarm): void
{
    if ($alarmId <= 0 || !app_db_table_exists($db, 'mobile_manager_alerts')) {
        return;
    }

    $recipients = $db->query(
        "SELECT id FROM portal_accounts
          WHERE role = 'employee' AND is_active = 1
            AND (employee_role IN ('operator','night_operator','admin','super_admin','director','engineer','noc','network_engineer','noc_monitor')
                 OR account_type IN ('operator','manager','admin','engineer','noc'))"
    );
    if ($recipients === false) {
        return;
    }

    $dedupKey = clean_text((string) ($alarm['dedup_key'] ?? ''));
    $severity = strtolower(clean_text((string) ($alarm['severity'] ?? 'warning')));
    $severity = in_array($severity, ['info', 'warning', 'critical', 'emergency'], true) ? $severity : 'warning';
    $title = mb_substr(clean_text((string) ($alarm['title'] ?? 'Şəbəkə hadisəsi')), 0, 180);
    $message = mb_substr(clean_text((string) ($alarm['message'] ?? $alarm['human_problem'] ?? '')), 0, 900);
    $insert = $db->prepare(
        "INSERT IGNORE INTO mobile_manager_alerts
         (alert_key, account_id, alert_type, severity, title, message, event_at, metadata_json)
         VALUES (:alert_key, :account_id, 'network_incident', :severity, :title, :message, NOW(), :metadata_json)"
    );
    foreach ($recipients->fetchAll(PDO::FETCH_COLUMN) ?: [] as $accountId) {
        $insert->execute([
            'alert_key' => 'network-alarm-' . $alarmId . '-' . (int) $accountId,
            'account_id' => (int) $accountId,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'metadata_json' => app_db_json_encode([
                'alarm_id' => $alarmId,
                'dedup_key' => $dedupKey,
                'ticket_no' => (string) ($alarm['ticket_no'] ?? ''),
                'area' => (string) ($alarm['area'] ?? ''),
                'device_key' => (string) ($alarm['device_key'] ?? ''),
            ]),
        ]);
    }
}

function app_mgs_record_alarm_event(
    array $alarm,
    bool $testMode = true,
    bool $queueMessage = true,
    array $actor = [],
    ?PDO $db = null
): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }
    $alarmObject = [
        'device_key' => (string) ($alarm['device_key'] ?? ''),
        'device_name' => (string) ($alarm['device_name'] ?? ''),
        'management_network' => (string) ($alarm['management_network'] ?? ''),
        'area' => (string) ($alarm['area'] ?? $alarm['location'] ?? ''),
    ];
    $authorization = app_noc_authorize($actor, 'record_noc_alarm', array_filter($alarmObject), $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('record_noc_alarm', $authorization);
    }
    if (!app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }

    $alarmType = clean_text((string) ($alarm['alarm_type'] ?? 'INTEGRATION_TEST'));
    $severity = strtolower(clean_text((string) ($alarm['severity'] ?? 'info')));
    $source = clean_text((string) ($alarm['source'] ?? 'mgs'));
    $objectType = clean_text((string) ($alarm['object_type'] ?? 'system'));
    $objectId = clean_text((string) ($alarm['object_id'] ?? 'integration'));
    $title = clean_text((string) ($alarm['title'] ?? 'Integration test alarm'));
    $dedupKey = clean_text((string) ($alarm['dedup_key'] ?? ''));
    if ($dedupKey === '') {
        $dedupKey = hash('sha256', implode('|', [$source, $alarmType, $objectType, $objectId, $title, $testMode ? 'test' : 'live']));
    }
    $message = app_mgs_render_alarm_message($alarm);
    $notificationEvent = $alarm + [
        'category' => (string) ($alarm['notification_category'] ?? $alarm['category'] ?? ''),
        'event_type' => $alarmType,
        'alarm_type' => $alarmType,
        'source' => $source,
        'title' => $title,
        'message' => $message,
        'dedup_key' => $dedupKey,
        'template_code' => $testMode ? 'mgs_alarm_test' : 'mgs_alarm',
        'metadata' => [
            'alarm_dedup_key' => $dedupKey,
            'alarm_type' => $alarmType,
            'severity' => $severity,
            'source' => $source,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'requested_bot_key' => clean_text((string) ($alarm['telegram_bot_key'] ?? '')),
        ],
    ];
    $notificationRoute = app_notification_resolve_bot($notificationEvent);
    $resolvedTelegramBotKey = clean_text((string) ($notificationRoute['bot_key'] ?? 'network')) ?: 'network';
    $status = $testMode ? 'test' : 'open';
    app_mgs_register_dedup_event($dedupKey, 'alarm', $title, $status, [
        'alarm_type' => $alarmType,
        'severity' => $severity,
        'source' => $source,
        'object_type' => $objectType,
        'object_id' => $objectId,
    ]);

    $normalizedSeverity = in_array($severity, ['info', 'warning', 'critical', 'emergency'], true) ? $severity : 'info';

    $beforeAlarm = null;
    try {
        $existingAlarm = $db->prepare('SELECT id, status, severity, seen_count FROM mgs_alarm_events WHERE dedup_key = :dedup_key');
        $existingAlarm->execute(['dedup_key' => $dedupKey]);
        $beforeAlarm = $existingAlarm->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {
        $beforeAlarm = null;
    }

    $statement = $db->prepare(
        'INSERT INTO mgs_alarm_events (
            dedup_key, alarm_type, severity, source, object_type, object_id, title, message, status, ticket_no,
            telegram_bot_key, payload_json
         ) VALUES (
            :dedup_key, :alarm_type, :severity, :source, :object_type, :object_id, :title, :message, :status, :ticket_no,
            :telegram_bot_key, :payload_json
         )
         ON DUPLICATE KEY UPDATE
            last_seen_at = NOW(),
            seen_count = seen_count + 1,
            severity = VALUES(severity),
            message = VALUES(message),
            payload_json = VALUES(payload_json)'
    );
    $ok = $statement->execute([
        'dedup_key' => $dedupKey,
        'alarm_type' => $alarmType,
        'severity' => $normalizedSeverity,
        'source' => $source,
        'object_type' => $objectType,
        'object_id' => $objectId,
        'title' => $title,
        'message' => $message,
        'status' => $status,
        'ticket_no' => clean_text((string) ($alarm['ticket_no'] ?? '')) ?: null,
        'telegram_bot_key' => $resolvedTelegramBotKey,
        'payload_json' => app_db_json_encode($alarm),
    ]);

    // $beforeAlarm === null means this dedup_key had no existing row, so the
    // statement above was a genuine INSERT and lastInsertId() is reliable;
    // ON DUPLICATE KEY UPDATE does not otherwise populate it (MySQL/MariaDB
    // only does that when the UPDATE clause itself calls LAST_INSERT_ID()).
    $alarmId = $beforeAlarm !== null ? (int) $beforeAlarm['id'] : (int) $db->lastInsertId();
    if ($queueMessage) {
        $notificationEvent['metadata']['alarm_id'] = $alarmId;
        app_notification_route_event($notificationEvent, $testMode, true);
    }
    if ($ok && !$testMode) {
        app_mgs_notify_mobile_alarm_recipients($db, $alarmId, $alarm + ['ticket_no' => (string) ($alarm['ticket_no'] ?? '')]);
    }

    // Best-effort append-only history (docs/39_MGS_NODE_CONSOLIDATION.md Faza 1):
    // mgs_alarm_events only ever holds current state; this keeps every
    // create/re-occurrence transition so an alarm's timeline can be
    // reconstructed. Swallowed if the migration hasn't run yet, matching
    // app_ai_team_record_agenda_run()'s best-effort-telemetry pattern.
    if ($ok && $alarmId > 0) {
        try {
            $db->prepare(
                'INSERT INTO mgs_alarm_state_events
                    (alarm_event_id, dedup_key, from_status, to_status, from_severity, to_severity, seen_count, test_mode)
                 VALUES (:alarm_event_id, :dedup_key, :from_status, :to_status, :from_severity, :to_severity, :seen_count, :test_mode)'
            )->execute([
                'alarm_event_id' => $alarmId,
                'dedup_key' => $dedupKey,
                'from_status' => $beforeAlarm['status'] ?? null,
                'to_status' => $status,
                'from_severity' => $beforeAlarm['severity'] ?? null,
                'to_severity' => $normalizedSeverity,
                'seen_count' => $beforeAlarm !== null ? ((int) $beforeAlarm['seen_count'] + 1) : 1,
                'test_mode' => $testMode ? 1 : 0,
            ]);
        } catch (Throwable) {
            // Migration not applied yet or table unavailable -- alarm recording itself must not fail.
        }
    }

    app_mgs_record_operation_action([
        'action_type' => 'alarm_recorded',
        'entity_type' => 'alarm',
        'entity_id' => $dedupKey,
        'status' => $testMode ? 'dry_run' : 'executed',
        'actor' => $actor,
        'summary' => $title,
        'payload' => ['alarm_type' => $alarmType, 'severity' => $severity, 'test_mode' => $testMode],
    ]);

    return ['ok' => $ok, 'dedup_key' => $dedupKey, 'status' => $status];
}

function app_mgs_signal_number(mixed $value): ?float
{
    if ($value === null) {
        return null;
    }

    $text = trim((string) $value);
    if ($text === '' || $text === '-') {
        return null;
    }

    if (!preg_match('/-?\d+(?:[.,]\d+)?/', $text, $match)) {
        return null;
    }

    return (float) str_replace(',', '.', $match[0]);
}

function app_mgs_olt_rule_thresholds(array $options = []): array
{
    $setting = static function (string $key, string $default) use ($options): string {
        if (array_key_exists($key, $options)) {
            return (string) $options[$key];
        }
        return app_mgs_runtime_setting($key, $default);
    };

    return [
        // A shared PON incident is more useful than five separate field
        // visits.  The values remain runtime-configurable for future tuning.
        'min_pon_issue_count' => max(1, (int) $setting('olt_mass_pon_min_affected', '5')),
        'ont_rx_min_dbm' => (float) $setting('olt_ont_rx_min_dbm', '-26'),
        'olt_rx_min_dbm' => (float) $setting('olt_olt_rx_min_dbm', '-26'),
        'ont_tx_min_dbm' => (float) $setting('olt_ont_tx_min_dbm', '-1'),
        'ont_tx_max_dbm' => (float) $setting('olt_ont_tx_max_dbm', '6'),
        'olt_tx_min_dbm' => (float) $setting('olt_olt_tx_min_dbm', '1'),
        'olt_tx_max_dbm' => (float) $setting('olt_olt_tx_max_dbm', '8'),
        'temperature_max_c' => (float) $setting('olt_ont_temperature_max_c', '51'),
        'optical_fresh_minutes' => max(1, (int) $setting('olt_optical_fresh_minutes', '20')),
    ];
}

/**
 * Optical values are useful only when they are a recent live snapshot.  A
 * stale value must never create, or keep open, a field ticket.
 */
function app_mgs_ont_optical_snapshot_is_fresh(array $ont, int $maxAgeMinutes): bool
{
    $stamp = trim(app_mgs_search_profile_field($ont, [
        'optical_synced_at', 'optical_updated_at', 'telemetry_at', 'last_seen_at',
    ], ''));
    if ($stamp === '') {
        return false;
    }
    $timestamp = strtotime($stamp);
    return $timestamp !== false && $timestamp >= (time() - max(60, $maxAgeMinutes * 60));
}

/** Resolve a device's operational area without inventing a field location. */
function app_mgs_network_device_area(array $device): string
{
    $resolve = static function (string $value): string {
        $direct = app_portal_resolve_area_from_text($value);
        if ($direct !== '') {
            return $direct;
        }
        $haystack = app_mgs_device_name_fingerprint($value);
        foreach (app_service_areas() as $area) {
            $needle = app_mgs_device_name_fingerprint((string) $area);
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return (string) $area;
            }
        }
        return '';
    };
    $explicit = clean_text((string) ($device['area'] ?? $device['location'] ?? ''));
    if ($explicit !== '') {
        $resolved = $resolve($explicit);
        if ($resolved !== '') {
            return $resolved;
        }
    }

    $key = preg_replace('/[^a-z0-9_]/', '_', strtolower((string) ($device['device_key'] ?? '')));
    if (is_string($key) && $key !== '') {
        $configured = clean_text((string) app_mgs_runtime_setting('network_device_area_' . $key, ''));
        $resolved = $resolve($configured);
        if ($resolved !== '') {
            return $resolved;
        }
    }

    $text = implode(' ', [
        (string) ($device['device_name'] ?? ''),
        (string) ($device['device_key'] ?? ''),
        (string) ($device['management_network'] ?? ''),
    ]);
    return $resolve($text) ?: 'Şəbəkə';
}

function app_mgs_monitored_olt_devices(?PDO $db = null, array $actor = []): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }

    $authorization = app_noc_authorize($actor, 'read_device', [], $db);
    if (empty($authorization['ok'])) {
        return [];
    }
    $statement = $db->query(
        'SELECT *
           FROM device_learning_profiles
          WHERE device_type = "olt"
            AND monitoring_enabled = 1
          ORDER BY device_name, id'
    );

    return $statement !== false
        ? app_noc_filter_objects($statement->fetchAll(PDO::FETCH_ASSOC) ?: [], $actor, $db)
        : [];
}

function app_mgs_monitored_network_devices(?PDO $db = null, array $actor = []): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }

    $authorization = app_noc_authorize($actor, 'read_device', [], $db);
    if (empty($authorization['ok'])) {
        return [];
    }
    $statement = $db->query(
        'SELECT *
           FROM device_learning_profiles
          WHERE monitoring_enabled = 1
          ORDER BY FIELD(device_type, "router", "olt", "switch", "server", "unknown"), device_name, id'
    );

    return $statement !== false
        ? app_noc_filter_objects($statement->fetchAll(PDO::FETCH_ASSOC) ?: [], $actor, $db)
        : [];
}

function app_mgs_olt_device_index(array $devices): array
{
    $index = [];
    foreach ($devices as $device) {
        if (!is_array($device)) {
            continue;
        }
        foreach ([
            (string) ($device['device_name'] ?? ''),
            (string) ($device['device_key'] ?? ''),
            (string) ($device['ip_address'] ?? ''),
        ] as $candidate) {
            $fingerprint = app_mgs_device_name_fingerprint($candidate);
            if ($fingerprint !== '') {
                $index[$fingerprint] = $device;
            }
        }
    }

    return $index;
}

function app_mgs_resolve_olt_device_from_text(string $oltName, array $deviceIndex): ?array
{
    $needle = app_mgs_device_name_fingerprint($oltName);
    if ($needle === '') {
        return null;
    }

    if (isset($deviceIndex[$needle]) && is_array($deviceIndex[$needle])) {
        return $deviceIndex[$needle];
    }

    foreach ($deviceIndex as $key => $device) {
        if (!is_array($device)) {
            continue;
        }
        if ($key !== '' && (str_contains($key, $needle) || str_contains($needle, $key))) {
            return $device;
        }
    }

    return null;
}

function app_mgs_ont_signal_findings(array $ont, array $thresholds): array
{
    $findings = [];
    $ontStatus = mb_strtolower(trim(app_mgs_search_profile_field($ont, ['status', 'state', 'online_status'], '')));
    if (in_array($ontStatus, ['offline', 'down', 'los', 'dying-gasp', 'inactive'], true)) {
        $findings[] = 'ONT bağlantısı yoxdur (' . $ontStatus . ')';
    }
    $checks = [
        'ont_rx' => ['label' => 'ONT RX', 'mode' => 'min', 'limit' => (float) $thresholds['ont_rx_min_dbm']],
        'olt_rx' => ['label' => 'OLT RX', 'mode' => 'min', 'limit' => (float) $thresholds['olt_rx_min_dbm']],
        'ont_tx' => ['label' => 'ONT TX', 'mode' => 'range', 'min' => (float) $thresholds['ont_tx_min_dbm'], 'max' => (float) $thresholds['ont_tx_max_dbm']],
        'olt_tx' => ['label' => 'OLT TX', 'mode' => 'range', 'min' => (float) $thresholds['olt_tx_min_dbm'], 'max' => (float) $thresholds['olt_tx_max_dbm']],
        'temperature' => ['label' => 'Temperatur', 'mode' => 'max', 'limit' => (float) $thresholds['temperature_max_c']],
    ];

    $aliases = [
        'ont_rx' => ['ont_rx', 'rx_power', 'rx'],
        'olt_rx' => ['olt_rx', 'olt_rx_power'],
        'ont_tx' => ['ont_tx', 'tx_power', 'tx'],
        'olt_tx' => ['olt_tx', 'olt_tx_power'],
        'temperature' => ['temperature', 'temp'],
    ];

    foreach ($checks as $key => $check) {
        $value = null;
        foreach ($aliases[$key] as $alias) {
            if (array_key_exists($alias, $ont)) {
                $value = app_mgs_signal_number($ont[$alias]);
                if ($value !== null) {
                    break;
                }
            }
        }
        if ($value === null) {
            continue;
        }

        if (($check['mode'] ?? '') === 'min' && $value <= (float) $check['limit']) {
            $findings[] = (string) $check['label'] . ' ' . $value . ' <= ' . (float) $check['limit'];
        } elseif (($check['mode'] ?? '') === 'max' && $value >= (float) $check['limit']) {
            $findings[] = (string) $check['label'] . ' ' . $value . ' >= ' . (float) $check['limit'];
        } elseif (($check['mode'] ?? '') === 'range' && ($value < (float) $check['min'] || $value > (float) $check['max'])) {
            $findings[] = (string) $check['label'] . ' ' . $value . ' not in ' . (float) $check['min'] . '..' . (float) $check['max'];
        }
    }

    return $findings;
}

function app_mgs_collect_customer_ont_rows(PDO $db): array
{
    $rows = $db->query(
        'SELECT id, subscriber_code, full_name, phone, area, address, status, payload_json
           FROM portal_accounts
          WHERE role = "customer"
          ORDER BY id'
    );
    $accounts = $rows !== false ? ($rows->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $onts = [];

    foreach ($accounts as $account) {
        if (!is_array($account)) {
            continue;
        }
        $payload = app_db_account_payload($account);
        $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        $services = is_array($payload['internet_services'] ?? null) ? array_values(array_filter($payload['internet_services'], 'is_array')) : [];
        $profiles = is_array($payload['ont_profiles'] ?? null) ? array_values(array_filter($payload['ont_profiles'], 'is_array')) : [];
        if ($profiles === []) {
            $primary = is_array($services[0] ?? null) ? $services[0] : [];
            $profiles = [[
                'olt' => (string) ($primary['olt'] ?? $billing['olt_name'] ?? ''),
                'pon' => (string) ($primary['pon'] ?? $primary['pon_port'] ?? $billing['pon_port'] ?? ''),
                'ont_id' => (string) ($primary['ont_id'] ?? $billing['ont_id'] ?? ''),
                'serial' => (string) ($primary['serial'] ?? $primary['sn'] ?? $billing['ont_serial'] ?? ''),
                'mac' => (string) ($primary['mac'] ?? $billing['mac'] ?? ''),
                'status' => (string) ($billing['status'] ?? $account['status'] ?? ''),
            ]];
        }

        foreach ($profiles as $profileIndex => $ont) {
            if (!is_array($ont)) {
                continue;
            }
            $ont['account_id'] = (int) ($account['id'] ?? 0);
            $ont['_profile_index'] = (int) $profileIndex;
            $ont['subscriber_code'] = (string) ($account['subscriber_code'] ?? '');
            $ont['customer_name'] = (string) ($account['full_name'] ?? '');
            $ont['phone'] = (string) ($account['phone'] ?? '');
            $ont['area'] = (string) ($account['area'] ?? '');
            $ont['address'] = (string) ($account['address'] ?? '');
            $onts[] = $ont;
        }
    }

    return $onts;
}

/**
 * Persist a live-fetched (or failed-attempt) optical snapshot back into the
 * customer's own ont_profiles entry, matched by profile index first, then by
 * serial, then by (pon+ont_id). Only the keys present in $fields are merged;
 * everything else already stored on that profile entry is preserved.
 */
function app_mgs_apply_ont_live_sync(
    PDO $db,
    int $accountId,
    ?int $profileIndex,
    string $matchSerial,
    string $matchPon,
    string $matchOntId,
    array $fields
): bool {
    if ($accountId <= 0) {
        return false;
    }

    $statement = $db->prepare('SELECT id, payload_json FROM portal_accounts WHERE id = :id AND role = "customer" LIMIT 1');
    $statement->execute(['id' => $accountId]);
    $account = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($account)) {
        return false;
    }

    $payload = app_db_json_decode((string) ($account['payload_json'] ?? ''), []);
    $profiles = is_array($payload['ont_profiles'] ?? null) ? $payload['ont_profiles'] : [];
    $targetIndex = null;

    if ($profileIndex !== null && isset($profiles[$profileIndex]) && is_array($profiles[$profileIndex])) {
        $targetIndex = $profileIndex;
    }
    if ($targetIndex === null) {
        foreach ($profiles as $index => $profile) {
            if (!is_array($profile)) {
                continue;
            }
            $profileSerial = trim(app_mgs_search_profile_field($profile, ['serial', 'sn'], ''));
            if ($matchSerial !== '' && $profileSerial !== '' && strcasecmp($profileSerial, $matchSerial) === 0) {
                $targetIndex = $index;
                break;
            }
            $profilePon = trim(app_mgs_search_profile_field($profile, ['pon', 'pon_port'], ''));
            $profileOntId = trim(app_mgs_search_profile_field($profile, ['ont_id', 'ont'], ''));
            if ($targetIndex === null && $matchPon !== '' && $matchOntId !== '' && $profilePon === $matchPon && $profileOntId === $matchOntId) {
                $targetIndex = $index;
            }
        }
    }
    if ($targetIndex === null) {
        return false;
    }

    $profiles[$targetIndex] = array_merge((array) $profiles[$targetIndex], $fields);
    $payload['ont_profiles'] = array_values($profiles);

    $update = $db->prepare('UPDATE portal_accounts SET payload_json = :payload WHERE id = :id');

    return $update->execute([
        'payload' => app_db_json_encode($payload),
        'id' => $accountId,
    ]);
}

function app_mgs_network_open_ticket_for_dedup(string $dedupKey, PDO $db): ?array
{
    $statement = $db->prepare(
        'SELECT *
           FROM portal_tickets
          WHERE status NOT IN ("closed", "resolved", "Baglandi")
            AND metadata_json LIKE :needle
          ORDER BY id DESC
          LIMIT 1'
    );
    $statement->execute(['needle' => '%' . $dedupKey . '%']);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function app_mgs_network_create_ticket(array $payload, PDO $db): array
{
    $dedupKey = (string) ($payload['dedup_key'] ?? '');
    if ($dedupKey !== '') {
        $existing = app_mgs_network_open_ticket_for_dedup($dedupKey, $db);
        if (is_array($existing)) {
            return [
                'ok' => true,
                'mode' => 'duplicate',
                'ticket_no' => (string) ($existing['ticket_no'] ?? ''),
            ];
        }
    }

    $area = clean_text((string) ($payload['area'] ?? 'Şəbəkə'));
    $ticketNo = app_db_next_ticket_no();
    $message = mb_substr(clean_text((string) ($payload['message'] ?? '')), 0, 160);
    if ($message === '') {
        $message = 'Avtomatik NOC qaydasi problem askarladi.';
    }

    $ok = app_db_insert_ticket([
        'ticket_no' => $ticketNo,
        'customer_account_id' => (int) ($payload['customer_account_id'] ?? 0) ?: null,
        'subscriber_code' => clean_text((string) ($payload['subscriber_code'] ?? '')),
        'contract_no' => '',
        'customer_name' => clean_text((string) ($payload['customer_name'] ?? 'NOC avtomatik')),
        'phone' => clean_text((string) ($payload['phone'] ?? '')),
        'address' => clean_text((string) ($payload['address'] ?? '')),
        'area' => $area,
        'subject' => clean_text((string) ($payload['subject'] ?? 'Texniki dəstək')),
        'message' => $message,
        'status' => 'Aciqdir',
        'opened_at' => app_db_now(),
        'updated_at' => app_db_now(),
        'resolved_at' => null,
        // Leave assignment to app_db_insert_ticket().  That single rule
        // enforces active duty status and fair workload rotation for tickets
        // created by CRM, mobile and NOC alike.
        'assigned_technician_account_id' => null,
        'assigned_technician_name' => '',
        'resolution_reason' => '',
        'resolution_note' => '',
        'handled_by' => '',
        'created_by_account_id' => null,
        'created_by_role' => 'system',
        'created_by_name' => 'NOC rule engine',
        'telegram_message_ids_json' => '[]',
        'metadata_json' => app_db_json_encode($payload),
    ]);

    return [
        'ok' => $ok,
        'mode' => $ok ? 'created' : 'failed',
        'ticket_no' => $ok ? $ticketNo : '',
    ];
}

/**
 * NOC qayda mühərriki (DEVICE_OFFLINE / PON_MASS_OPTICAL_DEGRADATION) tərəfindən yaradılmış AÇIQ
 * biletlər arasında, bu dövrdə ARTIQ aktiv siqnal siyahısında olmayanları (yəni problem özü aradan
 * qalxıb — siqnal/temperatur normaya qayıdıb, ya da cihaz yenidən görünüb) tapıb avtomatik bağlayır.
 *
 * TƏHLÜKƏSİZLİK: yalnız BU mühərrikin özünün yaratdığı biletlərə toxunur (created_by_role="system"
 * AND created_by_name="NOC rule engine" AND dedup_key "device-offline:"/"pon-mass-optical:" ilə
 * başlayır) — texnikin əl ilə açdığı/idarə etdiyi HEÇ BİR biletə toxunmur. dedup_key oxuna
 * bilmirsə (metadata korlanıbsa) həmin bilet ehtiyat xatirinə keçilir, bağlanmır.
 */
function app_mgs_auto_close_resolved_olt_tickets(array $activeDedupKeys, PDO $db, array $actor = [], bool $testMode = false, array $skipPrefixes = []): array
{
    $prefixes = ['device-offline:', 'pon-mass-optical:', 'ont-optical:', 'core-bottleneck:'];
    $likeClauses = [];
    $params = [];
    foreach ($prefixes as $i => $prefix) {
        $likeClauses[] = "metadata_json LIKE :prefix{$i}";
        $params["prefix{$i}"] = '%"dedup_key":"' . $prefix . '%';
    }

    $sql = 'SELECT id, ticket_no, metadata_json
              FROM portal_tickets
             WHERE status NOT IN ("closed", "resolved", "Baglandi")
               AND created_by_role = "system"
               AND created_by_name = "NOC rule engine"
               AND (' . implode(' OR ', $likeClauses) . ')';
    $statement = $db->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $activeSet = array_flip($activeDedupKeys);
    $closed = 0;
    $results = [];

    foreach ($rows as $row) {
        $metadata = app_db_json_decode((string) ($row['metadata_json'] ?? ''), []);
        $dedupKey = is_array($metadata) ? (string) ($metadata['dedup_key'] ?? '') : '';
        if ($dedupKey === '' || isset($activeSet[$dedupKey])) {
            continue;
        }
        foreach ($skipPrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($dedupKey, (string) $prefix)) {
                continue 2;
            }
        }

        if ($testMode) {
            $results[] = ['ticket_no' => (string) ($row['ticket_no'] ?? ''), 'dedup_key' => $dedupKey, 'mode' => 'would_close'];
            continue;
        }

        $update = $db->prepare(
            'UPDATE portal_tickets
                SET status = "Baglandi",
                    resolved_at = :resolved_at,
                    updated_at = :updated_at,
                    resolution_reason = :reason,
                    resolution_note = :note,
                    handled_by = :handled_by
              WHERE id = :id
                AND status NOT IN ("closed", "resolved", "Baglandi")'
        );
        $update->execute([
            'resolved_at' => app_db_now(),
            'updated_at' => app_db_now(),
            'reason' => 'Avtomatik hell olundu',
            'note' => 'NOC qayda muherriki: siqnal/temperatur deyerleri normaya qayitdi, problem artiq askarlanmir.',
            'handled_by' => 'NOC rule engine (auto-close)',
            'id' => (int) ($row['id'] ?? 0),
        ]);
        if ($update->rowCount() > 0) {
            $closed++;
            $results[] = ['ticket_no' => (string) ($row['ticket_no'] ?? ''), 'dedup_key' => $dedupKey, 'mode' => 'closed'];
        }
    }

    if (!$testMode && $results !== []) {
        app_mgs_record_operation_action([
            'action_type' => 'olt_ticket_auto_close',
            'entity_type' => 'network',
            'entity_id' => 'olt',
            'status' => 'executed',
            'actor' => $actor,
            'summary' => 'OLT auto-close: ' . $closed . ' bilet avtomatik baglandi (problem aradan qalxdi)',
            'payload' => ['results' => $results],
        ]);
    }

    return ['ok' => true, 'closed' => $closed, 'candidates' => count($rows), 'results' => $results];
}

/**
 * Anbarda minimum say hüddundan aşağı düşən (və ya tamamilə bitən) məhsulları tapır, anbar
 * botuna xəbərdarlıq göndərir. Dedup açarı miqdarın özünü daşıyır — miqdar dəyişmədikcə eyni
 * xəbərdarlıq TƏKRAR göndərilmir (yalnız seen_count/last_seen_at yenilənir), miqdar dəyişəndə
 * (azalıb ya artıb) YENİ dedup_key yaranır və yeni bildiriş gedir.
 */
function app_mgs_check_low_stock_items(?PDO $db = null, array $actor = [], bool $testMode = true, bool $queueMessages = true): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }

    $rows = $db->query(
        "SELECT sku, item_name, category, unit, quantity_on_hand, minimum_quantity
           FROM inventory_stock_items
          WHERE status = 'active'
            AND quantity_on_hand <= minimum_quantity
          ORDER BY quantity_on_hand ASC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $alerts = 0;
    $results = [];
    foreach ($rows as $row) {
        $sku = (string) ($row['sku'] ?? '');
        if ($sku === '') {
            continue;
        }
        $qty = (float) ($row['quantity_on_hand'] ?? 0);
        $min = (float) ($row['minimum_quantity'] ?? 0);
        $unit = (string) ($row['unit'] ?? '');
        $name = (string) ($row['item_name'] ?? $sku);
        $depleted = $qty <= 0;
        $dedupKey = 'low-stock:' . $sku . ':' . ($depleted ? 'depleted' : 'low') . ':' . (string) $qty;

        $alarm = [
            'dedup_key' => $dedupKey,
            'alarm_type' => $depleted ? 'STOCK_DEPLETED' : 'STOCK_LOW',
            'severity' => $depleted ? 'critical' : 'warning',
            'object_type' => 'inventory_item',
            'object_id' => $sku,
            'title' => ($depleted ? 'Anbarda bitib: ' : 'Anbarda azalıb: ') . $name,
            'message' => $name . ' (' . $sku . '): ' . $qty . ' ' . $unit . ' qalıb'
                . ($min > 0 ? ' (minimum: ' . $min . ' ' . $unit . ')' : '') . '.'
                . ($depleted ? ' STOK TAMAMILƏ BİTİB!' : ' Minimum həddə çatıb, sifariş vaxtıdır.'),
            'source' => 'inventory_stock_alert',
            'telegram_bot_key' => 'warehouse',
        ];
        $result = app_mgs_record_alarm_event($alarm, $testMode, $queueMessages, $actor, $db);
        $alerts += !empty($result['ok']) ? 1 : 0;
        $results[] = ['sku' => $sku, 'item_name' => $name, 'qty' => $qty, 'minimum' => $min, 'depleted' => $depleted, 'ok' => !empty($result['ok'])];
    }

    return ['ok' => true, 'checked' => count($rows), 'alerts' => $alerts, 'results' => $results];
}

/**
 * Gündəlik/həftəlik/aylıq gəlir-xərc hesabatını maliyyə botuna göndərir. Dövr HƏMİŞƏ
 * TAMAMLANMIŞ günlərə görə hesablanır (bu günü ehtiva etmir ki, gün bitmədən yarımçıq rəqəm
 * göndərilməsin). Dedup açarı dövr+bitmə tarixini daşıyır — eyni dövr üçün eyni gündə TƏKRAR
 * göndərilmir (cron gündə bir neçə dəfə səhvən işə düşsə belə).
 */
function app_mgs_finance_period_report(string $period, ?PDO $db = null, array $actor = [], bool $testMode = true, bool $queueMessages = true): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }

    $period = in_array($period, ['daily', 'weekly', 'monthly'], true) ? $period : 'daily';
    $today = new DateTimeImmutable('today');
    if ($period === 'weekly') {
        $from = $today->modify('-7 days');
        $to = $today->modify('-1 day');
        $label = 'Həftəlik (son 7 gün, ' . $from->format('d.m') . '-' . $to->format('d.m.Y') . ')';
    } elseif ($period === 'monthly') {
        $lastMonth = $today->modify('first day of last month');
        $from = $lastMonth;
        $to = $today->modify('last day of last month');
        $label = 'Aylıq (' . $lastMonth->format('m.Y') . ')';
    } else {
        $yesterday = $today->modify('-1 day');
        $from = $yesterday;
        $to = $yesterday;
        $label = 'Gündəlik (' . $yesterday->format('d.m.Y') . ')';
    }

    $sums = $db->prepare(
        "SELECT entry_type, COALESCE(SUM(amount),0) AS total
           FROM finance_entries
          WHERE status = 'posted'
            AND transaction_date BETWEEN :date_from AND :date_to
          GROUP BY entry_type"
    );
    $sums->execute(['date_from' => $from->format('Y-m-d'), 'date_to' => $to->format('Y-m-d')]);
    $totals = ['income' => 0.0, 'expense' => 0.0];
    foreach ($sums->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $type = (string) ($row['entry_type'] ?? '');
        if (isset($totals[$type])) {
            $totals[$type] = (float) ($row['total'] ?? 0);
        }
    }
    $net = $totals['income'] - $totals['expense'];

    $dedupKey = 'finance-report:' . $period . ':' . $to->format('Y-m-d');
    $alarm = [
        'dedup_key' => $dedupKey,
        'alarm_type' => 'FINANCE_PERIOD_REPORT',
        'severity' => 'info',
        'object_type' => 'finance_report',
        'object_id' => $period,
        'title' => $label . ' maliyyə hesabatı',
        'message' => $label . " maliyyə hesabatı:\n"
            . 'Gəlir: ' . number_format($totals['income'], 2) . " AZN\n"
            . 'Xərc: ' . number_format($totals['expense'], 2) . " AZN\n"
            . 'Xalis: ' . number_format($net, 2) . ' AZN',
        'source' => 'finance_period_report',
        'telegram_bot_key' => 'finance',
    ];
    $result = app_mgs_record_alarm_event($alarm, $testMode, $queueMessages, $actor, $db);

    return [
        'ok' => !empty($result['ok']),
        'period' => $period,
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
        'income' => $totals['income'],
        'expense' => $totals['expense'],
        'net' => $net,
    ];
}

function app_mgs_analyze_olt_ticket_rules(array $options = [], ?PDO $db = null, array $actor = []): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }
    $authorization = app_noc_authorize($actor, 'run_olt_rules', [], $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('run_olt_rules', $authorization);
    }

    $createTickets = (bool) ($options['create_tickets'] ?? true);
    $testMode = (bool) ($options['test_mode'] ?? false);
    $queueMessages = (bool) ($options['queue_messages'] ?? !$testMode);
    $requirePath = (bool) ($options['require_management_path'] ?? true);
    $pathReady = app_mgs_management_path_ready();
    if ($requirePath && !$pathReady) {
        app_mgs_record_operation_action([
            'action_type' => 'olt_ticket_rule_scan',
            'entity_type' => 'network',
            'entity_id' => 'olt',
            'status' => 'skipped',
            'actor' => $actor,
            'summary' => 'OLT ticket rule scan skipped: management path down',
            'payload' => ['reason' => 'management_path_down'],
        ]);
        return ['ok' => true, 'mode' => 'skipped', 'reason' => 'management_path_down', 'devices_scanned' => 0, 'onts_checked' => 0, 'groups' => []];
    }

    $thresholds = app_mgs_olt_rule_thresholds($options);
    $allDevices = app_mgs_monitored_network_devices($db, $actor);
    $oltDevices = array_values(array_filter(
        $allDevices,
        static fn (array $device): bool => (string) ($device['device_type'] ?? '') === 'olt'
    ));
    $deviceIndex = app_mgs_olt_device_index($oltDevices);
    $alerts = [];
    $groups = [];
    $ontsChecked = 0;

    foreach ($allDevices as $device) {
        $state = (string) ($device['reachability_state'] ?? 'unknown');
        if ($state !== 'unreachable_confirmed') {
            continue;
        }
        $deviceKey = (string) ($device['device_key'] ?? '');
        $dedupKey = 'device-offline:' . $deviceKey;
        $alerts[] = [
            'dedup_key' => $dedupKey,
            'alarm_type' => 'DEVICE_OFFLINE',
            'severity' => 'critical',
            'object_type' => 'device',
            'object_id' => $deviceKey,
            'device_key' => $deviceKey,
            'device_name' => (string) ($device['device_name'] ?? $deviceKey),
            'device_ip' => (string) ($device['ip_address'] ?? ''),
            'device_type' => (string) ($device['device_type'] ?? 'unknown'),
            'area' => app_mgs_network_device_area($device),
            'title' => (string) ($device['device_name'] ?? $deviceKey) . ' ilə əlaqə kəsilib',
            'human_problem' => 'Monitorinq aktivdir, amma cihaz cavab vermir. Bu cihazdan asılı xidmətlər təsirlənə bilər.',
            'recommendation' => 'Enerji və uplink-i, sonra VLAN 550 idarəetmə yolunu və SNMP/SSH cavabını yoxlayın.',
            'message' => strtoupper((string) ($device['device_type'] ?? 'device')) . ' ' . (string) ($device['device_name'] ?? $deviceKey) . ' ilə əlaqə kəsilib. IP: ' . (string) ($device['ip_address'] ?? ''),
        ];
    }

    $staleOpticalRows = 0;
    foreach (app_mgs_collect_customer_ont_rows($db) as $ont) {
        if (!is_array($ont)) {
            continue;
        }
        $oltName = app_mgs_search_profile_field($ont, ['olt', 'olt_name', 'device_name'], '');
        $pon = app_mgs_search_profile_field($ont, ['pon', 'pon_port'], '');
        if ($oltName === '' || $pon === '') {
            continue;
        }

        // Do not turn an old imported profile into a fresh incident.  Test
        // fixtures may opt in with allow_unfresh_optical so the rule engine's
        // behaviour itself remains testable without a live OLT.
        if (empty($options['allow_unfresh_optical'])
            && !app_mgs_ont_optical_snapshot_is_fresh($ont, (int) $thresholds['optical_fresh_minutes'])) {
            $staleOpticalRows++;
            continue;
        }
        $ontsChecked++;
        $device = app_mgs_resolve_olt_device_from_text($oltName, $deviceIndex);
        $deviceKey = is_array($device) ? (string) ($device['device_key'] ?? '') : app_mgs_device_name_fingerprint($oltName);
        $groupKey = $deviceKey . '|' . app_mgs_device_name_fingerprint($pon);
        $groups[$groupKey] ??= [
            'device_key' => $deviceKey,
            'device_name' => is_array($device) ? (string) ($device['device_name'] ?? $oltName) : $oltName,
            'device_ip' => is_array($device) ? (string) ($device['ip_address'] ?? '') : '',
            'pon' => $pon,
            'issues' => [],
        ];

        $findings = app_mgs_ont_signal_findings($ont, $thresholds);
        if ($findings === []) {
            continue;
        }

        $groups[$groupKey]['issues'][] = [
            'account_id' => (int) ($ont['account_id'] ?? 0),
            'subscriber_code' => (string) ($ont['subscriber_code'] ?? ''),
            'customer_name' => (string) ($ont['customer_name'] ?? ''),
            'phone' => (string) ($ont['phone'] ?? ''),
            'address' => (string) ($ont['address'] ?? ''),
            'area' => (string) ($ont['area'] ?? ''),
            'ont_id' => app_mgs_search_profile_field($ont, ['ont_id', 'ont'], '-'),
            'serial' => app_mgs_search_profile_field($ont, ['serial', 'sn'], '-'),
            'ont_rx' => app_mgs_search_profile_field($ont, ['ont_rx', 'rx_power', 'rx'], ''),
            'olt_rx' => app_mgs_search_profile_field($ont, ['olt_rx', 'olt_rx_power'], ''),
            'ont_tx' => app_mgs_search_profile_field($ont, ['ont_tx', 'tx_power', 'tx'], ''),
            'olt_tx' => app_mgs_search_profile_field($ont, ['olt_tx', 'olt_tx_power'], ''),
            'temperature' => app_mgs_search_profile_field($ont, ['temperature', 'temp'], ''),
            'optical_synced_at' => app_mgs_search_profile_field($ont, ['optical_synced_at', 'optical_updated_at', 'telemetry_at', 'last_seen_at'], ''),
            'findings' => $findings,
        ];
    }

    $massGroups = [];
    foreach ($groups as $group) {
        $issueCount = count((array) ($group['issues'] ?? []));
        if ($issueCount < (int) $thresholds['min_pon_issue_count']) {
            continue;
        }
        $massGroups[(string) ($group['device_key'] ?? '') . '|' . app_mgs_device_name_fingerprint((string) ($group['pon'] ?? ''))] = true;
        $dedupKey = 'pon-mass-optical:' . (string) ($group['device_key'] ?? '') . ':' . app_mgs_device_name_fingerprint((string) ($group['pon'] ?? ''));
        $samples = array_slice((array) ($group['issues'] ?? []), 0, 5);
        $alerts[] = [
            'dedup_key' => $dedupKey,
            'alarm_type' => 'PON_MASS_OPTICAL_DEGRADATION',
            'severity' => 'critical',
            'object_type' => 'pon',
            'object_id' => (string) ($group['device_key'] ?? '') . '/' . (string) ($group['pon'] ?? ''),
            'device_key' => (string) ($group['device_key'] ?? ''),
            'device_name' => (string) ($group['device_name'] ?? ''),
            'device_ip' => (string) ($group['device_ip'] ?? ''),
            'area' => (string) (($samples[0]['area'] ?? '') ?: app_mgs_network_device_area([
                'device_key' => (string) ($group['device_key'] ?? ''),
                'device_name' => (string) ($group['device_name'] ?? ''),
            ])),
            'title' => 'Kütləvi PON problemi: ' . (string) ($group['device_name'] ?? '') . ' / ' . (string) ($group['pon'] ?? ''),
            'human_problem' => $issueCount . ' abunəçinin ONT-si eyni PON üzərində oflayndır və ya optik/temperatur həddini keçib.',
            'recommendation' => 'Ayrı-ayrı müştəri bileti açmayın. PON portunu, splitteri, feeder/drop kabelini, kabinet enerjisini və optik patchləri vahid hadisə kimi yoxlayın.',
            'message' => (string) ($group['device_name'] ?? '') . ' / ' . (string) ($group['pon'] ?? '') . ': ' . $issueCount . ' abunəçi. ' . implode(', ', array_map(static function (array $issue): string {
                $serial = (string) ($issue['serial'] ?? $issue['subscriber_code'] ?? '-');
                $rx = (string) (($issue['ont_rx'] ?? '') ?: ($issue['olt_rx'] ?? ''));
                return $serial . ($rx !== '' ? ' (' . $rx . ' dBm)' : '');
            }, $samples)),
            'samples' => $samples,
            'issue_count' => $issueCount,
            'thresholds' => $thresholds,
        ];
    }

    // Below the mass-incident threshold each affected customer receives one
    // focused ticket. Once a PON is a mass incident, only the combined PON
    // ticket is produced; this prevents duplicate field work and alert noise.
    foreach ($groups as $groupKey => $group) {
        if (isset($massGroups[$groupKey])) {
            continue;
        }
        foreach ((array) ($group['issues'] ?? []) as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $serial = clean_text((string) ($issue['serial'] ?? ''));
            $subscriber = clean_text((string) ($issue['subscriber_code'] ?? ''));
            $identity = $serial !== '' && $serial !== '-' ? $serial : ($subscriber !== '' ? $subscriber : (string) ($issue['ont_id'] ?? 'ont'));
            $measurement = array_filter([
                'ONT RX' => $issue['ont_rx'] ?? '', 'OLT RX' => $issue['olt_rx'] ?? '',
                'ONT TX' => $issue['ont_tx'] ?? '', 'OLT TX' => $issue['olt_tx'] ?? '',
                'Temp' => $issue['temperature'] ?? '',
            ], static fn ($value): bool => trim((string) $value) !== '');
            $measurementText = [];
            foreach ($measurement as $label => $value) {
                $measurementText[] = $label . ': ' . $value;
            }
            $alerts[] = [
                'dedup_key' => 'ont-optical:' . (string) ($group['device_key'] ?? '') . ':' . app_mgs_device_name_fingerprint((string) ($group['pon'] ?? '')) . ':' . app_mgs_device_name_fingerprint($identity),
                'alarm_type' => 'ONT_OPTICAL_DEGRADATION',
                'severity' => 'warning',
                'object_type' => 'ont',
                'object_id' => (string) ($group['device_key'] ?? '') . '/' . (string) ($group['pon'] ?? '') . '/' . $identity,
                'device_key' => (string) ($group['device_key'] ?? ''),
                'device_name' => (string) ($group['device_name'] ?? ''),
                'device_ip' => (string) ($group['device_ip'] ?? ''),
                'pon' => (string) ($group['pon'] ?? ''),
                'area' => (string) (($issue['area'] ?? '') ?: 'Şəbəkə'),
                'customer_account_id' => (int) ($issue['account_id'] ?? 0),
                'subscriber_code' => $subscriber,
                'customer_name' => (string) ($issue['customer_name'] ?? 'ONT avtomatik'),
                'phone' => (string) ($issue['phone'] ?? ''),
                'address' => (string) ($issue['address'] ?? ''),
                'serial' => $serial,
                'findings' => (array) ($issue['findings'] ?? []),
                'title' => 'ONT optik problem: ' . $identity,
                'human_problem' => implode('; ', (array) ($issue['findings'] ?? [])),
                'recommendation' => 'ONT, konnektor, drop kabel və optik səviyyələri yerində yoxlayın.',
                'message' => trim((string) ($group['device_name'] ?? '') . ' / ' . (string) ($group['pon'] ?? '') . ' · SN: ' . $identity . '. ' . implode('; ', (array) ($issue['findings'] ?? [])) . (empty($measurementText) ? '' : ' · ' . implode(', ', $measurementText))),
                'measurements' => $measurement,
                'optical_synced_at' => (string) ($issue['optical_synced_at'] ?? ''),
            ];
        }
    }

    // Core/router dar boğazı yalnız ən azı üç son nümunənin orta yükü
    // interfeys tutumunun 85%-ni keçəndə hadisəyə çevrilir. Tək sıçrayış ticket açmır.
    try {
        $trafficReady = (int) $db->query(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ("device_interfaces", "device_interface_traffic_samples")'
        )->fetchColumn() === 2;
        if ($trafficReady) {
            $trafficRows = $db->query(
                'SELECT s.device_key, s.if_index, i.if_descr, i.if_speed_bps,
                        d.device_name, d.ip_address, d.device_type,
                        COUNT(*) AS sample_count,
                        ROUND(AVG(GREATEST(COALESCE(s.in_bps,0), COALESCE(s.out_bps,0)) / i.if_speed_bps * 100), 1) AS avg_utilization,
                        ROUND(MAX(GREATEST(COALESCE(s.in_bps,0), COALESCE(s.out_bps,0)) / i.if_speed_bps * 100), 1) AS peak_utilization
                   FROM device_interface_traffic_samples s
                   JOIN device_interfaces i ON i.device_key = s.device_key AND i.if_index = s.if_index
                   JOIN device_learning_profiles d ON d.device_key = s.device_key
                  WHERE s.sampled_at >= (NOW() - INTERVAL 20 MINUTE)
                    AND i.if_speed_bps > 0
                    AND d.device_type = "router"
                  GROUP BY s.device_key, s.if_index, i.if_descr, i.if_speed_bps,
                           d.device_name, d.ip_address, d.device_type
                 HAVING COUNT(*) >= 3 AND avg_utilization >= 85'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($trafficRows as $traffic) {
                $deviceKey = (string) $traffic['device_key'];
                $ifIndex = (int) $traffic['if_index'];
                $avg = (float) $traffic['avg_utilization'];
                $peak = (float) $traffic['peak_utilization'];
                $alerts[] = [
                    'dedup_key' => 'core-bottleneck:' . $deviceKey . ':' . $ifIndex,
                    'alarm_type' => 'CORE_INTERFACE_BOTTLENECK',
                    'severity' => $avg >= 95 ? 'critical' : 'warning',
                    'object_type' => 'interface',
                    'object_id' => $deviceKey . '/' . $ifIndex,
                    'device_key' => $deviceKey,
                    'device_name' => (string) ($traffic['device_name'] ?: $deviceKey),
                    'device_ip' => (string) ($traffic['ip_address'] ?? ''),
                    'interface_name' => (string) ($traffic['if_descr'] ?: 'ifIndex ' . $ifIndex),
                    'area' => 'Şəbəkə',
                    'title' => (string) ($traffic['device_name'] ?: $deviceKey) . ' üzərində dar boğaz',
                    'human_problem' => 'Core interfeysinin son 20 dəqiqəlik orta yükü ' . $avg . '%, pik yükü ' . $peak . '% olub.',
                    'recommendation' => 'Trafik istiqamətini, top talker-ləri və uplink tutumunu yoxlayın; yük davamlıdırsa balanslama və ya tutum artımı planlayın.',
                    'message' => (string) ($traffic['device_name'] ?: $deviceKey) . ' / ' . (string) ($traffic['if_descr'] ?: $ifIndex) . ': orta ' . $avg . '%, pik ' . $peak . '%.',
                    'avg_utilization' => $avg,
                    'peak_utilization' => $peak,
                    'sample_count' => (int) $traffic['sample_count'],
                ];
            }
        }
    } catch (Throwable) {
        // Traffic migration or telemetry may not be available yet; OLT rules continue safely.
    }

    $createdTickets = 0;
    $duplicateTickets = 0;
    $alarmsRecorded = 0;
    $results = [];
    foreach ($alerts as $alert) {
        $ticketNo = '';
        $ticketMode = 'disabled';
        if ($createTickets) {
            $ticket = app_mgs_network_create_ticket([
                'dedup_key' => (string) ($alert['dedup_key'] ?? ''),
                'subject' => 'Şəbəkə hadisəsi',
                'customer_account_id' => (int) ($alert['customer_account_id'] ?? 0),
                'subscriber_code' => (string) ($alert['subscriber_code'] ?? ''),
                'customer_name' => (string) ($alert['customer_name'] ?? 'NOC avtomatik'),
                'phone' => (string) ($alert['phone'] ?? ''),
                'area' => (string) ($alert['area'] ?? 'Sebeke'),
                'address' => (string) ($alert['device_name'] ?? ''),
                'message' => (string) ($alert['message'] ?? $alert['title'] ?? ''),
                'source' => 'olt_ticket_rule_engine',
                'alert' => $alert,
            ], $db);
            $ticketNo = (string) ($ticket['ticket_no'] ?? '');
            $ticketMode = (string) ($ticket['mode'] ?? 'failed');
            $createdTickets += $ticketMode === 'created' ? 1 : 0;
            $duplicateTickets += $ticketMode === 'duplicate' ? 1 : 0;
        }

        $alarmResult = app_mgs_record_alarm_event($alert + [
            'source' => 'olt_ticket_rule_engine',
            'ticket_no' => $ticketNo,
            'telegram_bot_key' => 'network',
        ], $testMode, $queueMessages, $actor, $db);
        $alarmsRecorded += !empty($alarmResult['ok']) ? 1 : 0;
        $results[] = [
            'dedup_key' => (string) ($alert['dedup_key'] ?? ''),
            'title' => (string) ($alert['title'] ?? ''),
            'ticket_no' => $ticketNo,
            'ticket_mode' => $ticketMode,
            'alarm_ok' => !empty($alarmResult['ok']),
        ];
    }

    // Bu dövrdə artıq aktiv olmayan (siqnal/temperatur normaya qayıdan, ya da cihaz yenidən görünən)
    // əvvəlki qayda-yaratdığı biletləri avtomatik bağla — YALNIZ bu mühərrikin öz biletlərinə toxunur.
    // Missing or stale optical telemetry is unknown, not a recovered line.
    // Never auto-close optical tickets while any imported ONT snapshot in the
    // scan is stale; device and core alarms retain their normal lifecycle.
    $skipAutoClosePrefixes = $staleOpticalRows > 0 ? ['ont-optical:', 'pon-mass-optical:'] : [];
    $autoClose = app_mgs_auto_close_resolved_olt_tickets(
        array_column($alerts, 'dedup_key'),
        $db,
        $actor,
        $testMode,
        $skipAutoClosePrefixes
    );

    app_mgs_record_operation_action([
        'action_type' => 'olt_ticket_rule_scan',
        'entity_type' => 'network',
        'entity_id' => 'olt',
        'status' => 'executed',
        'actor' => $actor,
        'summary' => 'OLT ticket rule scan: ' . count($alerts) . ' alerts, ' . $createdTickets . ' tickets, ' . (int) ($autoClose['closed'] ?? 0) . ' auto-closed',
        'payload' => [
            'devices_scanned' => count($allDevices),
            'olt_devices_scanned' => count($oltDevices),
            'onts_checked' => $ontsChecked,
            'alerts' => count($alerts),
            'tickets_created' => $createdTickets,
            'tickets_duplicate' => $duplicateTickets,
            'tickets_auto_closed' => (int) ($autoClose['closed'] ?? 0),
            'stale_optical_rows' => $staleOpticalRows,
            'auto_close_skipped_prefixes' => $skipAutoClosePrefixes,
            'thresholds' => $thresholds,
            'results' => $results,
            'auto_close_results' => $autoClose['results'] ?? [],
        ],
    ]);

    return [
        'ok' => true,
        'mode' => 'scan',
        'devices_scanned' => count($allDevices),
        'olt_devices_scanned' => count($oltDevices),
        'onts_checked' => $ontsChecked,
        'alerts' => count($alerts),
        'tickets_created' => $createdTickets,
        'tickets_duplicate' => $duplicateTickets,
        'tickets_auto_closed' => (int) ($autoClose['closed'] ?? 0),
        'alarms_recorded' => $alarmsRecorded,
        'stale_optical_rows' => $staleOpticalRows,
        'auto_close_skipped_prefixes' => $skipAutoClosePrefixes,
        'groups' => array_values($groups),
        'results' => $results,
        'auto_close_results' => $autoClose['results'] ?? [],
    ];
}

function app_mgs_record_operation_action(array $action): bool
{
    $db = app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return false;
    }

    $status = (string) ($action['status'] ?? 'planned');
    if (!in_array($status, ['planned', 'dry_run', 'pending', 'executed', 'failed', 'skipped'], true)) {
        $status = 'planned';
    }
    $actor = is_array($action['actor'] ?? null) ? $action['actor'] : [];
    $payload = is_array($action['payload'] ?? null) ? $action['payload'] : $action;

    $statement = $db->prepare(
        'INSERT INTO mgs_operation_actions (
            action_type, entity_type, entity_id, status, actor_account_id, actor_name, summary, ticket_no, payload_json, executed_at
         ) VALUES (
            :action_type, :entity_type, :entity_id, :status, :actor_account_id, :actor_name, :summary, :ticket_no, :payload_json, :executed_at
         )'
    );

    return $statement->execute([
        'action_type' => clean_text((string) ($action['action_type'] ?? 'operation')),
        'entity_type' => clean_text((string) ($action['entity_type'] ?? 'system')),
        'entity_id' => clean_text((string) ($action['entity_id'] ?? '')),
        'status' => $status,
        'actor_account_id' => (int) ($action['actor_account_id'] ?? $actor['id'] ?? 0) ?: null,
        'actor_name' => clean_text((string) ($action['actor_name'] ?? $actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'system')),
        'summary' => mb_substr(clean_text((string) ($action['summary'] ?? '')), 0, 255),
        'ticket_no' => clean_text((string) ($action['ticket_no'] ?? '')) ?: null,
        'payload_json' => app_db_json_encode($payload),
        'executed_at' => $status === 'executed' ? app_db_now() : null,
    ]);
}

function app_mgs_record_ticket_close(array $ticket, string $reason, string $note, array $actor = []): bool
{
    return app_mgs_record_operation_action([
        'action_type' => 'ticket_closed',
        'entity_type' => 'ticket',
        'entity_id' => (string) ($ticket['id'] ?? $ticket['ticket_no'] ?? ''),
        'status' => 'executed',
        'actor' => $actor,
        'actor_account_id' => (int) ($ticket['assigned_technician_account_id'] ?? $ticket['assigned_technician_id'] ?? 0),
        'actor_name' => (string) ($ticket['assigned_technician_name'] ?? $actor['full_name'] ?? 'technician'),
        'summary' => 'Ticket baglandi: ' . $reason,
        'ticket_no' => (string) ($ticket['id'] ?? $ticket['ticket_no'] ?? ''),
        'payload' => ['reason' => $reason, 'note' => $note, 'ticket' => $ticket],
    ]);
}

function app_mgs_record_inventory_movement(array $payload, bool $apply = false): array
{
    $db = app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }

    $sku = clean_text((string) ($payload['sku'] ?? 'TEST-FASTCONNECTOR'));
    $itemName = clean_text((string) ($payload['item_name'] ?? 'Fast connector SC/APC'));
    $category = clean_text((string) ($payload['category'] ?? 'fiber'));
    $unit = clean_text((string) ($payload['unit'] ?? 'eded'));
    $quantity = max(0.01, (float) ($payload['quantity'] ?? 1));
    $movementType = clean_text((string) ($payload['movement_type'] ?? 'issue'));
    if (!in_array($movementType, ['in', 'out', 'issue', 'return', 'adjust', 'customer_install', 'test'], true)) {
        $movementType = 'issue';
    }

    if (!$apply) {
        app_mgs_record_operation_action([
            'action_type' => 'inventory_movement',
            'entity_type' => 'inventory',
            'entity_id' => $sku,
            'status' => 'dry_run',
            'summary' => 'Inventory dry-run: ' . $itemName . ' -' . $quantity . ' ' . $unit,
            'ticket_no' => clean_text((string) ($payload['ticket_no'] ?? '')),
            'payload' => $payload,
        ]);
        return ['ok' => true, 'mode' => 'dry-run', 'sku' => $sku, 'quantity' => $quantity];
    }

    try {
        $db->beginTransaction();
        $itemStatement = $db->prepare(
            'INSERT INTO inventory_stock_items (sku, item_name, category, unit, quantity_on_hand, minimum_quantity)
             VALUES (:sku, :item_name, :category, :unit, 0, 0)
             ON DUPLICATE KEY UPDATE item_name = VALUES(item_name), category = VALUES(category), unit = VALUES(unit)'
        );
        $itemStatement->execute([
            'sku' => $sku,
            'item_name' => $itemName,
            'category' => $category,
            'unit' => $unit,
        ]);

        $select = $db->prepare('SELECT * FROM inventory_stock_items WHERE sku = :sku LIMIT 1 FOR UPDATE');
        $select->execute(['sku' => $sku]);
        $item = $select->fetch();
        if (!is_array($item)) {
            $db->rollBack();
            return ['ok' => false, 'reason' => 'item_not_found'];
        }

        $delta = in_array($movementType, ['in', 'return'], true) ? $quantity : -$quantity;
        $currentQuantity = (float) ($item['quantity_on_hand'] ?? 0);
        $allowNegativeStock = !empty($payload['allow_negative_stock']);
        if ($delta < 0 && !$allowNegativeStock && ($currentQuantity + $delta) < 0) {
            $db->rollBack();
            app_mgs_record_operation_action([
                'action_type' => 'inventory_movement',
                'entity_type' => 'inventory',
                'entity_id' => $sku,
                'status' => 'failed',
                'summary' => 'Inventory rejected: insufficient stock for ' . $itemName,
                'ticket_no' => clean_text((string) ($payload['ticket_no'] ?? '')),
                'payload' => $payload + ['current_quantity' => $currentQuantity, 'requested_quantity' => $quantity],
            ]);
            return [
                'ok' => false,
                'reason' => 'insufficient_stock',
                'sku' => $sku,
                'current_quantity' => $currentQuantity,
                'requested_quantity' => $quantity,
            ];
        }

        $update = $db->prepare('UPDATE inventory_stock_items SET quantity_on_hand = quantity_on_hand + :delta WHERE id = :id');
        $update->execute(['delta' => $delta, 'id' => (int) $item['id']]);

        $movement = $db->prepare(
            'INSERT INTO inventory_stock_movements (
                item_id, movement_type, quantity, ticket_no, customer_account_id, technician_account_id,
                actor_account_id, actor_name, note, status, metadata_json
             ) VALUES (
                :item_id, :movement_type, :quantity, :ticket_no, :customer_account_id, :technician_account_id,
                :actor_account_id, :actor_name, :note, "posted", :metadata_json
             )'
        );
        $movement->execute([
            'item_id' => (int) $item['id'],
            'movement_type' => $movementType,
            'quantity' => $quantity,
            'ticket_no' => clean_text((string) ($payload['ticket_no'] ?? '')) ?: null,
            'customer_account_id' => (int) ($payload['customer_account_id'] ?? 0) ?: null,
            'technician_account_id' => (int) ($payload['technician_account_id'] ?? 0) ?: null,
            'actor_account_id' => (int) ($payload['actor_account_id'] ?? 0) ?: null,
            'actor_name' => clean_text((string) ($payload['actor_name'] ?? 'system')),
            'note' => clean_text((string) ($payload['note'] ?? '')),
            'metadata_json' => app_db_json_encode($payload),
        ]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'reason' => 'inventory_transaction_failed'];
    }

    app_mgs_record_operation_action([
        'action_type' => 'inventory_movement',
        'entity_type' => 'inventory',
        'entity_id' => $sku,
        'status' => 'executed',
        'summary' => 'Inventory posted: ' . $itemName . ' ' . $movementType . ' ' . $quantity . ' ' . $unit,
        'ticket_no' => clean_text((string) ($payload['ticket_no'] ?? '')),
        'payload' => $payload,
    ]);

    return ['ok' => true, 'mode' => 'apply', 'sku' => $sku, 'quantity' => $quantity];
}

function app_mgs_inventory_normalize_mac(?string $value): string
{
    $hex = strtoupper(preg_replace('/[^0-9a-fA-F]/', '', (string) $value) ?? '');
    if (strlen($hex) !== 12) {
        return strtoupper(trim((string) $value));
    }

    return implode(':', str_split($hex, 2));
}

function app_mgs_inventory_normalize_serial(?string $value): string
{
    return strtoupper(preg_replace('/\s+/', '', trim((string) $value)) ?? '');
}

function app_mgs_inventory_upsert_stock_item(PDO $db, string $sku, string $name, string $category, string $unit): int
{
    $statement = $db->prepare(
        'INSERT INTO inventory_stock_items (sku, item_name, category, unit, quantity_on_hand, minimum_quantity)
         VALUES (:sku, :item_name, :category, :unit, 0, 0)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), item_name = VALUES(item_name), category = VALUES(category), unit = VALUES(unit)'
    );
    $statement->execute([
        'sku' => $sku,
        'item_name' => $name,
        'category' => $category,
        'unit' => $unit,
    ]);

    return (int) $db->lastInsertId();
}

function app_mgs_inventory_upsert_asset_model(PDO $db, string $vendor, string $model, string $type): int
{
    $statement = $db->prepare(
        'INSERT INTO inventory_asset_models (vendor, model, device_type, wifi_generation, supports_dual_band, ethernet_port_count, supports_tr069)
         VALUES (:vendor, :model, :device_type, "wifi5", 1, 4, 1)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), wifi_generation = VALUES(wifi_generation), supports_dual_band = VALUES(supports_dual_band)'
    );
    $statement->execute([
        'vendor' => $vendor,
        'model' => $model,
        'device_type' => $type,
    ]);

    return (int) $db->lastInsertId();
}

function app_mgs_inventory_create_asset(PDO $db, array $asset): int
{
    $serial = app_mgs_inventory_normalize_serial((string) ($asset['serial_number'] ?? ''));
    $mac = app_mgs_inventory_normalize_mac((string) ($asset['mac_address'] ?? ''));
    $statement = $db->prepare(
        'INSERT INTO inventory_assets (
            product_id, model_id, asset_code, serial_number, serial_number_normalized, mac_address, mac_address_normalized,
            ownership_type, lifecycle_status, condition_status, current_location_type, current_location_id, metadata_json
         ) VALUES (
            :product_id, :model_id, :asset_code, :serial_number, :serial_number_normalized, :mac_address, :mac_address_normalized,
            :ownership_type, :lifecycle_status, :condition_status, :current_location_type, :current_location_id, :metadata_json
         )'
    );
    $statement->execute([
        'product_id' => (int) ($asset['product_id'] ?? 0) ?: null,
        'model_id' => (int) ($asset['model_id'] ?? 0) ?: null,
        'asset_code' => clean_text((string) ($asset['asset_code'] ?? '')),
        'serial_number' => clean_text((string) ($asset['serial_number'] ?? '')) ?: null,
        'serial_number_normalized' => $serial !== '' ? $serial : null,
        'mac_address' => clean_text((string) ($asset['mac_address'] ?? '')) ?: null,
        'mac_address_normalized' => $mac !== '' ? $mac : null,
        'ownership_type' => (string) ($asset['ownership_type'] ?? 'company'),
        'lifecycle_status' => (string) ($asset['lifecycle_status'] ?? 'in_warehouse'),
        'condition_status' => (string) ($asset['condition_status'] ?? 'new'),
        'current_location_type' => (string) ($asset['current_location_type'] ?? 'warehouse'),
        'current_location_id' => clean_text((string) ($asset['current_location_id'] ?? '')) ?: null,
        'metadata_json' => app_db_json_encode($asset),
    ]);

    return (int) $db->lastInsertId();
}

function app_mgs_inventory_record_lifecycle_movement(PDO $db, array $payload): int
{
    $statement = $db->prepare(
        'INSERT INTO inventory_lifecycle_movements (
            movement_no, movement_type, product_id, asset_id, cable_drum_id, quantity, length_meters,
            from_location_type, from_location_id, to_location_type, to_location_id, customer_code,
            technician_code, ticket_no, reason, note, performed_by, approved_by, idempotency_key, metadata_json
         ) VALUES (
            :movement_no, :movement_type, :product_id, :asset_id, :cable_drum_id, :quantity, :length_meters,
            :from_location_type, :from_location_id, :to_location_type, :to_location_id, :customer_code,
            :technician_code, :ticket_no, :reason, :note, :performed_by, :approved_by, :idempotency_key, :metadata_json
         )'
    );
    $movementNo = clean_text((string) ($payload['movement_no'] ?? ('MV-' . bin2hex(random_bytes(6)))));
    $statement->execute([
        'movement_no' => $movementNo,
        'movement_type' => clean_text((string) ($payload['movement_type'] ?? 'movement')),
        'product_id' => (int) ($payload['product_id'] ?? 0) ?: null,
        'asset_id' => (int) ($payload['asset_id'] ?? 0) ?: null,
        'cable_drum_id' => (int) ($payload['cable_drum_id'] ?? 0) ?: null,
        'quantity' => array_key_exists('quantity', $payload) ? (float) $payload['quantity'] : null,
        'length_meters' => array_key_exists('length_meters', $payload) ? (float) $payload['length_meters'] : null,
        'from_location_type' => clean_text((string) ($payload['from_location_type'] ?? '')) ?: null,
        'from_location_id' => clean_text((string) ($payload['from_location_id'] ?? '')) ?: null,
        'to_location_type' => clean_text((string) ($payload['to_location_type'] ?? '')) ?: null,
        'to_location_id' => clean_text((string) ($payload['to_location_id'] ?? '')) ?: null,
        'customer_code' => clean_text((string) ($payload['customer_code'] ?? '')) ?: null,
        'technician_code' => clean_text((string) ($payload['technician_code'] ?? '')) ?: null,
        'ticket_no' => clean_text((string) ($payload['ticket_no'] ?? '')) ?: null,
        'reason' => clean_text((string) ($payload['reason'] ?? '')) ?: null,
        'note' => clean_text((string) ($payload['note'] ?? '')) ?: null,
        'performed_by' => clean_text((string) ($payload['performed_by'] ?? 'system')) ?: null,
        'approved_by' => clean_text((string) ($payload['approved_by'] ?? '')) ?: null,
        'idempotency_key' => clean_text((string) ($payload['idempotency_key'] ?? '')) ?: null,
        'metadata_json' => app_db_json_encode($payload),
    ]);

    return (int) $db->lastInsertId();
}

function app_mgs_inventory_technician_balance(PDO $db, int $productId, string $technicianCode): float
{
    $statement = $db->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN to_location_type = "technician" AND to_location_id = :tech THEN quantity ELSE 0 END), 0)
            -
            COALESCE(SUM(CASE WHEN from_location_type = "technician" AND from_location_id = :tech THEN quantity ELSE 0 END), 0)
            AS balance
         FROM inventory_lifecycle_movements
         WHERE product_id = :product_id AND quantity IS NOT NULL'
    );
    $statement->execute(['tech' => $technicianCode, 'product_id' => $productId]);
    $row = $statement->fetch();

    return round((float) ($row['balance'] ?? 0), 2);
}

function app_mgs_inventory_active_assignment_exists(PDO $db, int $assetId): bool
{
    $statement = $db->prepare('SELECT COUNT(*) FROM customer_equipment_assignments WHERE asset_id = :asset_id AND assignment_status = "active"');
    $statement->execute(['asset_id' => $assetId]);

    return (int) $statement->fetchColumn() > 0;
}

function app_mgs_inventory_install_asset(PDO $db, int $assetId, string $customerCode, string $technicianCode, string $ticketNo, string $ownershipType = 'company', bool $recordMovement = true): int
{
    if ($ownershipType !== 'customer_owned' && app_mgs_inventory_active_assignment_exists($db, $assetId)) {
        throw new RuntimeException('duplicate_active_assignment');
    }

    $asset = $db->prepare('SELECT * FROM inventory_assets WHERE id = :id FOR UPDATE');
    $asset->execute(['id' => $assetId]);
    $row = $asset->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('asset_not_found');
    }

    $insert = $db->prepare(
        'INSERT INTO customer_equipment_assignments (
            customer_code, asset_id, serial_number, mac_address, ownership_type, assignment_status,
            installed_by, installed_ticket_no, metadata_json
         ) VALUES (
            :customer_code, :asset_id, :serial_number, :mac_address, :ownership_type, "active",
            :installed_by, :installed_ticket_no, :metadata_json
         )'
    );
    $insert->execute([
        'customer_code' => $customerCode,
        'asset_id' => $assetId,
        'serial_number' => $row['serial_number'] ?? null,
        'mac_address' => $row['mac_address'] ?? null,
        'ownership_type' => $ownershipType,
        'installed_by' => $technicianCode,
        'installed_ticket_no' => $ticketNo,
        'metadata_json' => app_db_json_encode(['source' => 'inventory_lifecycle', 'ticket_no' => $ticketNo]),
    ]);
    $assignmentId = (int) $db->lastInsertId();

    if ($ownershipType !== 'customer_owned' && $recordMovement) {
        app_mgs_inventory_record_lifecycle_movement($db, [
            'movement_no' => 'INSTALL-ASSET-' . $assetId . '-' . $assignmentId,
            'movement_type' => 'customer_install',
            'product_id' => isset($row['product_id']) ? (int) $row['product_id'] : null,
            'asset_id' => $assetId,
            'quantity' => 1,
            'from_location_type' => (string) ($row['current_location_type'] ?? 'warehouse'),
            'from_location_id' => (string) ($row['current_location_id'] ?? 'MAIN'),
            'to_location_type' => 'customer',
            'to_location_id' => $customerCode,
            'customer_code' => $customerCode,
            'technician_code' => $technicianCode,
            'ticket_no' => $ticketNo,
            'reason' => 'customer_equipment_install',
            'performed_by' => $technicianCode,
            'idempotency_key' => 'install-asset-' . $assetId . '-' . $assignmentId,
            'metadata_json' => ['assignment_id' => $assignmentId],
        ]);
    }

    $db->prepare(
        'UPDATE inventory_assets
         SET lifecycle_status = "installed", current_location_type = "customer", current_location_id = :customer_code,
             installation_count = installation_count + 1
         WHERE id = :id'
    )->execute(['customer_code' => $customerCode, 'id' => $assetId]);

    return $assignmentId;
}

function app_mgs_customer_equipment_lifecycle(PDO $db, string $customerCode, int $limit = 20): array
{
    $customerCode = clean_text($customerCode);
    if ($customerCode === '') {
        return [];
    }

    $limit = max(1, min(100, $limit));
    $statement = $db->prepare(
        "SELECT
            cea.*,
            ia.asset_code,
            ia.lifecycle_status,
            ia.condition_status,
            ia.current_location_type,
            ia.current_location_id,
            ia.installation_count,
            iam.vendor,
            iam.model,
            iam.device_type,
            iar.return_status,
            iai.inspection_status,
            iai.notes AS inspection_notes
         FROM customer_equipment_assignments cea
         LEFT JOIN inventory_assets ia ON ia.id = cea.asset_id
         LEFT JOIN inventory_asset_models iam ON iam.id = ia.model_id
         LEFT JOIN inventory_asset_returns iar ON iar.asset_id = cea.asset_id AND iar.customer_code = cea.customer_code
         LEFT JOIN inventory_asset_inspections iai ON iai.asset_id = cea.asset_id
         WHERE cea.customer_code = :customer_code
         ORDER BY
            CASE cea.assignment_status WHEN 'active' THEN 0 WHEN 'replaced' THEN 1 ELSE 2 END,
            cea.installed_at DESC,
            cea.id DESC
         LIMIT {$limit}"
    );
    $statement->execute(['customer_code' => $customerCode]);

    return array_values(array_filter($statement->fetchAll() ?: [], 'is_array'));
}

function app_mgs_inventory_lifecycle_acceptance_smoke(bool $apply = false): array
{
    $db = app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'summary' => 'db_unavailable', 'assertions' => []];
    }

    $runId = date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $tech = 'T001';
    $customer = '2320025';
    $customerReuse = '2320026';

    try {
        $db->beginTransaction();

        $cableProduct = app_mgs_inventory_upsert_stock_item($db, 'LIFE-CABLE-' . $runId, 'Fiber kabel', 'fiber', 'metr');
        $connectorProduct = app_mgs_inventory_upsert_stock_item($db, 'LIFE-FC-' . $runId, 'Fast Connector SC/APC', 'fiber', 'eded');
        $modemProduct = app_mgs_inventory_upsert_stock_item($db, 'LIFE-MODEM-' . $runId, 'GPON modem', 'modem', 'eded');
        $modelId = app_mgs_inventory_upsert_asset_model($db, 'HSGQ', 'Dual Band Test', 'modem');

        $assetA = app_mgs_inventory_create_asset($db, [
            'product_id' => $modemProduct,
            'model_id' => $modelId,
            'asset_code' => 'MODEM-A-' . $runId,
            'serial_number' => 'SN-A-' . $runId,
            'mac_address' => 'AA:BB:CC:' . strtoupper(substr(str_replace('-', '', $runId), -6, 2)) . ':A0:01',
            'ownership_type' => 'company',
            'lifecycle_status' => 'in_warehouse',
            'condition_status' => 'new',
        ]);
        $assetB = app_mgs_inventory_create_asset($db, [
            'product_id' => $modemProduct,
            'model_id' => $modelId,
            'asset_code' => 'MODEM-B-' . $runId,
            'serial_number' => 'SN-B-' . $runId,
            'mac_address' => 'AA:BB:CC:' . strtoupper(substr(str_replace('-', '', $runId), -4, 2)) . ':B0:01',
            'ownership_type' => 'company',
            'lifecycle_status' => 'in_warehouse',
            'condition_status' => 'new',
        ]);
        $assetC = app_mgs_inventory_create_asset($db, [
            'product_id' => null,
            'model_id' => $modelId,
            'asset_code' => 'CUSTOMER-MODEM-C-' . $runId,
            'serial_number' => 'SN-C-' . $runId,
            'mac_address' => 'AA:BB:CC:' . strtoupper(substr(str_replace('-', '', $runId), -2, 2)) . ':C0:01',
            'ownership_type' => 'customer_owned',
            'lifecycle_status' => 'customer_owned_installed',
            'condition_status' => 'unknown',
            'current_location_type' => 'customer',
            'current_location_id' => $customer,
        ]);

        $drumStatement = $db->prepare(
            'INSERT INTO inventory_cable_drums (drum_number, product_id, initial_length_meters, remaining_length_meters, location_type, location_id)
             VALUES (:drum_number, :product_id, 1000, 1000, "warehouse", "MAIN")'
        );
        $drumStatement->execute(['drum_number' => 'DRUM-5-' . $runId, 'product_id' => $cableProduct]);
        $drumId = (int) $db->lastInsertId();

        foreach ([$assetA, $assetB] as $assetId) {
            app_mgs_inventory_record_lifecycle_movement($db, [
                'movement_no' => 'ASSIGN-ASSET-' . $assetId . '-' . $runId,
                'movement_type' => 'assign_to_technician',
                'product_id' => $modemProduct,
                'asset_id' => $assetId,
                'quantity' => 1,
                'from_location_type' => 'warehouse',
                'from_location_id' => 'MAIN',
                'to_location_type' => 'technician',
                'to_location_id' => $tech,
                'technician_code' => $tech,
                'performed_by' => 'warehouse',
            ]);
            $db->prepare('UPDATE inventory_assets SET current_location_type = "technician", current_location_id = :tech WHERE id = :id')
                ->execute(['tech' => $tech, 'id' => $assetId]);
        }

        app_mgs_inventory_record_lifecycle_movement($db, [
            'movement_no' => 'ASSIGN-FC-' . $runId,
            'movement_type' => 'assign_to_technician',
            'product_id' => $connectorProduct,
            'quantity' => 50,
            'from_location_type' => 'warehouse',
            'from_location_id' => 'MAIN',
            'to_location_type' => 'technician',
            'to_location_id' => $tech,
            'technician_code' => $tech,
        ]);
        app_mgs_inventory_record_lifecycle_movement($db, [
            'movement_no' => 'ASSIGN-DRUM-' . $runId,
            'movement_type' => 'assign_to_technician',
            'product_id' => $cableProduct,
            'cable_drum_id' => $drumId,
            'from_location_type' => 'warehouse',
            'from_location_id' => 'MAIN',
            'to_location_type' => 'technician',
            'to_location_id' => $tech,
            'technician_code' => $tech,
        ]);
        $db->prepare('UPDATE inventory_cable_drums SET location_type = "technician", location_id = :tech WHERE id = :id')
            ->execute(['tech' => $tech, 'id' => $drumId]);

        $installTicket = 'TICKET-INSTALL-' . $runId;
        foreach ([[55, 2, $installTicket], [15, 2, 'TICKET-SERVICE-' . $runId]] as [$meters, $connectors, $ticketNo]) {
            $remaining = (float) $db->query('SELECT remaining_length_meters FROM inventory_cable_drums WHERE id = ' . $drumId . ' FOR UPDATE')->fetchColumn();
            if ($remaining < $meters) {
                throw new RuntimeException('insufficient_cable');
            }
            if (app_mgs_inventory_technician_balance($db, $connectorProduct, $tech) < $connectors) {
                throw new RuntimeException('insufficient_connectors');
            }
            $db->prepare('UPDATE inventory_cable_drums SET remaining_length_meters = remaining_length_meters - :meters WHERE id = :id')
                ->execute(['meters' => $meters, 'id' => $drumId]);
            app_mgs_inventory_record_lifecycle_movement($db, [
                'movement_no' => 'CABLE-' . $ticketNo,
                'movement_type' => 'customer_install',
                'product_id' => $cableProduct,
                'cable_drum_id' => $drumId,
                'length_meters' => $meters,
                'from_location_type' => 'technician',
                'from_location_id' => $tech,
                'to_location_type' => 'customer',
                'to_location_id' => $customer,
                'customer_code' => $customer,
                'technician_code' => $tech,
                'ticket_no' => $ticketNo,
            ]);
            app_mgs_inventory_record_lifecycle_movement($db, [
                'movement_no' => 'CONNECTOR-' . $ticketNo,
                'movement_type' => 'customer_install',
                'product_id' => $connectorProduct,
                'quantity' => $connectors,
                'from_location_type' => 'technician',
                'from_location_id' => $tech,
                'to_location_type' => 'customer',
                'to_location_id' => $customer,
                'customer_code' => $customer,
                'technician_code' => $tech,
                'ticket_no' => $ticketNo,
            ]);
            $db->prepare(
                'INSERT INTO inventory_work_order_materials (ticket_no, customer_code, technician_code, product_id, cable_drum_id, quantity, length_meters, unit, note)
                 VALUES (:ticket_no, :customer_code, :technician_code, :product_id, :cable_drum_id, :quantity, :length_meters, :unit, :note)'
            )->execute([
                'ticket_no' => $ticketNo,
                'customer_code' => $customer,
                'technician_code' => $tech,
                'product_id' => $cableProduct,
                'cable_drum_id' => $drumId,
                'quantity' => $connectors,
                'length_meters' => $meters,
                'unit' => 'mixed',
                'note' => 'Fiber cable and connector consumption',
            ]);
        }

        app_mgs_inventory_install_asset($db, $assetA, $customer, $tech, $installTicket);
        $db->prepare('UPDATE customer_equipment_assignments SET assignment_status = "replaced", removed_ticket_no = :ticket, removed_at = NOW() WHERE asset_id = :asset_id AND assignment_status = "active"')
            ->execute(['ticket' => 'TICKET-REPLACE-' . $runId, 'asset_id' => $assetA]);
        $db->prepare('UPDATE inventory_assets SET lifecycle_status = "return_pending", current_location_type = "technician", current_location_id = :tech, return_count = return_count + 1 WHERE id = :id')
            ->execute(['tech' => $tech, 'id' => $assetA]);
        app_mgs_inventory_install_asset($db, $assetB, $customer, $tech, 'TICKET-REPLACE-' . $runId);

        $return = $db->prepare(
            'INSERT INTO inventory_asset_returns (asset_id, customer_code, technician_code, return_ticket_no, return_reason, return_status, received_by, received_at)
             VALUES (:asset_id, :customer_code, :technician_code, :ticket_no, "replacement", "received", "warehouse", NOW())'
        );
        $return->execute(['asset_id' => $assetA, 'customer_code' => $customer, 'technician_code' => $tech, 'ticket_no' => 'TICKET-REPLACE-' . $runId]);
        $returnId = (int) $db->lastInsertId();
        $db->prepare(
            'INSERT INTO inventory_asset_inspections (return_id, asset_id, inspection_status, inspected_by, notes)
             VALUES (:return_id, :asset_id, "USED_WORKING", "warehouse", "Smoke inspection ok")'
        )->execute(['return_id' => $returnId, 'asset_id' => $assetA]);
        $db->prepare('UPDATE inventory_asset_returns SET return_status = "inspected" WHERE id = :id')->execute(['id' => $returnId]);
        $db->prepare('UPDATE inventory_assets SET lifecycle_status = "in_warehouse", condition_status = "USED_WORKING", current_location_type = "warehouse", current_location_id = "MAIN" WHERE id = :id')
            ->execute(['id' => $assetA]);

        app_mgs_inventory_install_asset($db, $assetC, $customer, $tech, 'TICKET-CUSTOMER-OWNED-' . $runId, 'customer_owned');

        $db->prepare('UPDATE inventory_assets SET current_location_type = "technician", current_location_id = :tech WHERE id = :id')
            ->execute(['tech' => $tech, 'id' => $assetA]);
        app_mgs_inventory_install_asset($db, $assetA, $customerReuse, $tech, 'TICKET-REUSE-' . $runId);

        $duplicateAssignmentBlocked = false;
        try {
            app_mgs_inventory_install_asset($db, $assetB, $customerReuse, $tech, 'TICKET-DUPLICATE-' . $runId);
        } catch (RuntimeException $exception) {
            $duplicateAssignmentBlocked = $exception->getMessage() === 'duplicate_active_assignment';
        }

        $insufficientCableBlocked = false;
        $remainingCable = (float) $db->query('SELECT remaining_length_meters FROM inventory_cable_drums WHERE id = ' . $drumId)->fetchColumn();
        if ($remainingCable < 99999) {
            $insufficientCableBlocked = true;
        }

        $duplicateMacBlocked = false;
        try {
            app_mgs_inventory_create_asset($db, [
                'product_id' => $modemProduct,
                'model_id' => $modelId,
                'asset_code' => 'DUP-MAC-' . $runId,
                'serial_number' => 'SN-DUP-' . $runId,
                'mac_address' => 'AA:BB:CC:' . strtoupper(substr(str_replace('-', '', $runId), -4, 2)) . ':B0:01',
                'ownership_type' => 'company',
            ]);
        } catch (Throwable) {
            $duplicateMacBlocked = true;
        }

        $assetAState = $db->prepare('SELECT installation_count, condition_status FROM inventory_assets WHERE id = :id');
        $assetAState->execute(['id' => $assetA]);
        $assetARow = $assetAState->fetch() ?: [];

        $assetBAssignment = $db->prepare('SELECT customer_code FROM customer_equipment_assignments WHERE asset_id = :asset_id AND assignment_status = "active" LIMIT 1');
        $assetBAssignment->execute(['asset_id' => $assetB]);

        $customerOwnedMovement = $db->prepare('SELECT COUNT(*) FROM inventory_lifecycle_movements WHERE asset_id = :asset_id AND from_location_type = "warehouse"');
        $customerOwnedMovement->execute(['asset_id' => $assetC]);

        $assertions = [
            'drum_remaining_meters' => round((float) $db->query('SELECT remaining_length_meters FROM inventory_cable_drums WHERE id = ' . $drumId)->fetchColumn(), 2),
            'technician_connectors_remaining' => app_mgs_inventory_technician_balance($db, $connectorProduct, $tech),
            'modem_a_installation_count' => (int) ($assetARow['installation_count'] ?? 0),
            'modem_a_condition' => (string) ($assetARow['condition_status'] ?? ''),
            'modem_b_active_customer' => (string) $assetBAssignment->fetchColumn(),
            'customer_owned_stock_deducted' => ((int) $customerOwnedMovement->fetchColumn()) > 0,
            'duplicate_assignment_blocked' => $duplicateAssignmentBlocked,
            'insufficient_cable_blocked' => $insufficientCableBlocked,
            'duplicate_mac_blocked' => $duplicateMacBlocked,
        ];

        if ($apply) {
            $db->commit();
        } else {
            $db->rollBack();
        }

        app_mgs_record_operation_action([
            'action_type' => 'inventory_lifecycle_acceptance',
            'entity_type' => 'inventory',
            'entity_id' => $runId,
            'status' => $apply ? 'executed' : 'dry_run',
            'summary' => 'Inventory lifecycle acceptance scenario completed',
            'payload' => ['run_id' => $runId, 'assertions' => $assertions],
        ]);

        return [
            'ok' => true,
            'run_id' => $runId,
            'summary' => 'Inventory lifecycle chain validated',
            'assertions' => $assertions,
        ];
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        return [
            'ok' => false,
            'summary' => 'inventory_lifecycle_failed: ' . $exception->getMessage(),
            'assertions' => [],
        ];
    }
}

function app_mgs_rebuild_employee_work_snapshots(?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'updated' => 0];
    }

    $date = (string) ($db->query('SELECT CURDATE()')->fetchColumn() ?: date('Y-m-d'));
    $rows = $db->query(
        'SELECT
            COALESCE(assigned_technician_account_id, 0) AS employee_account_id,
            COALESCE(NULLIF(assigned_technician_name, ""), "Teyin edilmeyib") AS employee_name,
            SUM(CASE WHEN status <> "Baglandi" THEN 1 ELSE 0 END) AS open_ticket_count,
            SUM(CASE WHEN status = "Baglandi" AND DATE(resolved_at) = CURDATE() THEN 1 ELSE 0 END) AS closed_ticket_count,
            MAX(updated_at) AS last_ticket_at
         FROM portal_tickets
         WHERE assigned_technician_account_id IS NOT NULL OR assigned_technician_name <> ""
         GROUP BY COALESCE(assigned_technician_account_id, 0), COALESCE(NULLIF(assigned_technician_name, ""), "Teyin edilmeyib")'
    );
    $ticketRows = $rows !== false ? ($rows->fetchAll() ?: []) : [];

    $inventoryByTech = [];
    $inventoryByTechName = [];
    try {
        $inventoryRows = $db->query(
            'SELECT COALESCE(technician_account_id, 0) AS employee_account_id, COUNT(*) AS total
             FROM inventory_stock_movements
             WHERE DATE(created_at) = CURDATE() AND status = "posted"
             GROUP BY COALESCE(technician_account_id, 0)'
        )->fetchAll() ?: [];
        foreach ($inventoryRows as $row) {
            $inventoryByTech[(int) ($row['employee_account_id'] ?? 0)] = (int) ($row['total'] ?? 0);
        }

        $lifecycleRows = $db->query(
            'SELECT COALESCE(NULLIF(technician_code, ""), "") AS technician_code, COUNT(*) AS total
             FROM inventory_lifecycle_movements
             WHERE DATE(created_at) = CURDATE()
               AND technician_code IS NOT NULL
               AND technician_code <> ""
               AND movement_type IN ("assign_to_technician", "customer_install", "return_from_customer", "return_to_warehouse")
             GROUP BY COALESCE(NULLIF(technician_code, ""), "")'
        )->fetchAll() ?: [];
        foreach ($lifecycleRows as $row) {
            $techName = (string) ($row['technician_code'] ?? '');
            if ($techName !== '') {
                $inventoryByTechName[$techName] = (int) ($row['total'] ?? 0);
            }
        }
    } catch (Throwable) {
        $inventoryByTech = [];
        $inventoryByTechName = [];
    }

    $attendanceByEmployee = [];
    try {
        $attendanceRows = $db->query('SELECT employee_account_id,attendance_status FROM hr_attendance_events WHERE work_date=CURDATE()')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($attendanceRows as $attendanceRow) {
            $attendanceByEmployee[(int) ($attendanceRow['employee_account_id'] ?? 0)] = (string) ($attendanceRow['attendance_status'] ?? '');
        }
    } catch (Throwable) {
        $attendanceByEmployee = [];
    }

    $upsert = $db->prepare(
        'INSERT INTO employee_work_snapshots (
            employee_account_id, employee_name, work_date, open_ticket_count, closed_ticket_count,
            inventory_issued_count, last_ticket_at, performance_score, payload_json
         ) VALUES (
            :employee_account_id, :employee_name, :work_date, :open_ticket_count, :closed_ticket_count,
            :inventory_issued_count, :last_ticket_at, :performance_score, :payload_json
         )
         ON DUPLICATE KEY UPDATE
            open_ticket_count = VALUES(open_ticket_count),
            closed_ticket_count = VALUES(closed_ticket_count),
            inventory_issued_count = VALUES(inventory_issued_count),
            last_ticket_at = VALUES(last_ticket_at),
            performance_score = VALUES(performance_score),
            payload_json = VALUES(payload_json)'
    );

    $updated = 0;
    foreach ($ticketRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $employeeId = (int) ($row['employee_account_id'] ?? 0);
        $employeeName = (string) ($row['employee_name'] ?? '');
        $closed = (int) ($row['closed_ticket_count'] ?? 0);
        $open = (int) ($row['open_ticket_count'] ?? 0);
        $inventory = (int) ($inventoryByTech[$employeeId] ?? 0) + (int) ($inventoryByTechName[$employeeName] ?? 0);
        $attendanceStatus = (string) ($attendanceByEmployee[$employeeId] ?? '');
        $latePenalty = $attendanceStatus === 'late' ? 5 : 0;
        $score = max(0, min(100, 60 + ($closed * 6) + min(10, $inventory) - ($open * 3) - $latePenalty));
        $upsert->execute([
            'employee_account_id' => $employeeId > 0 ? $employeeId : null,
            'employee_name' => $employeeName,
            'work_date' => $date,
            'open_ticket_count' => $open,
            'closed_ticket_count' => $closed,
            'inventory_issued_count' => $inventory,
            'last_ticket_at' => $row['last_ticket_at'] ?? null,
            'performance_score' => $score,
            'payload_json' => app_db_json_encode($row + ['attendance_status' => $attendanceStatus, 'late_penalty' => $latePenalty, 'inventory_score_cap' => min(10, $inventory)]),
        ]);
        $updated++;
    }

    app_mgs_record_operation_action([
        'action_type' => 'employee_work_snapshot',
        'entity_type' => 'employee',
        'entity_id' => $date,
        'status' => 'executed',
        'summary' => 'Employee work snapshots rebuilt: ' . $updated,
        'payload' => ['updated' => $updated, 'date' => $date],
    ]);

    return ['ok' => true, 'updated' => $updated];
}

function app_mgs_backup_payload_for_device(
    array $device,
    string $backupType = 'profile_snapshot',
    array $actor = [],
    ?PDO $db = null,
    string $purposeAction = 'preview_device_backup'
): array
{
    $db = $db instanceof PDO ? $db : app_db();
    $authorization = app_noc_authorize($actor, $purposeAction, $device, $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure($purposeAction, $authorization);
    }
    $backupType = in_array($backupType, ['running_config', 'startup_config', 'inventory_snapshot', 'profile_snapshot', 'learned_profile', 'cli_discovery_snapshot', 'test'], true)
        ? $backupType
        : 'profile_snapshot';

    if (in_array($backupType, ['running_config', 'startup_config', 'cli_discovery_snapshot'], true) && !app_mgs_management_path_ready()) {
        return [
            'ok' => false,
            'reason' => 'management_path_down',
            'backup_type' => $backupType,
        ];
    }

    if ($backupType === 'cli_discovery_snapshot') {
        $vendorKey = strtoupper(trim((string) ($device['vendor'] ?? '')));
        $handler = app_mgs_cli_discovery_handler_registry()[$vendorKey] ?? null;
        if ($handler === null) {
            return [
                'ok' => false,
                'reason' => 'cli_discovery_not_supported',
                'backup_type' => $backupType,
                'supported_vendors' => app_mgs_cli_discovery_supported_vendors(),
            ];
        }

        $accessProfile = app_mgs_device_access_profile($device, $db, $actor, $purposeAction);
        $sshUsername = trim((string) ($accessProfile['ssh_username'] ?? ''));
        $sshPassword = (string) ($accessProfile['ssh_password'] ?? '');
        if ($sshUsername === '' || $sshPassword === '') {
            return [
                'ok' => false,
                'reason' => 'missing_ssh_credentials',
                'backup_type' => $backupType,
            ];
        }

        $probeFn = $handler['probe'];
        $discovery = $probeFn(
            (string) ($device['ip_address'] ?? ''),
            $sshUsername,
            $sshPassword,
            (int) ($accessProfile['ssh_port'] ?? 22),
            4500,
            $actor,
            $device,
            $db,
            $purposeAction
        );
        if (empty($discovery['ok'])) {
            return [
                'ok' => false,
                'reason' => (string) ($discovery['error'] ?? ($vendorKey . '_cli_discovery_failed')),
                'backup_type' => $backupType,
            ];
        }

        $deriveFn = $handler['derive'];
        $learnedCommandMap = $deriveFn($discovery);

        $payload = [
            'backup_type' => $backupType,
            'device' => [
                'device_key' => (string) ($device['device_key'] ?? ''),
                'device_name' => (string) ($device['device_name'] ?? ''),
                'ip_address' => (string) ($device['ip_address'] ?? ''),
                'device_type' => (string) ($device['device_type'] ?? ''),
                'vendor' => (string) ($device['vendor'] ?? ''),
                'model' => (string) ($device['model'] ?? ''),
                'adapter_key' => (string) ($device['adapter_key'] ?? ''),
            ],
            'discovery' => [
                'prompts' => is_array($discovery['prompts'] ?? null) ? $discovery['prompts'] : [],
                'nodes' => is_array($discovery['nodes'] ?? null) ? $discovery['nodes'] : [],
                'keywords' => app_mgs_json_decode_array($discovery['keywords'] ?? []),
                'sample_ont_id' => (string) ($discovery['sample_ont_id'] ?? ''),
                'welcome' => (string) ($discovery['welcome'] ?? ''),
                // The 5 fields above are HSGQ's original discovery shape,
                // kept as-is so nothing already reading them breaks. Other
                // vendors' probes (e.g. Cisco's outputs/hostname/
                // is_cisco_ios/privileged_mode) don't fit that shape, so the
                // full raw probe result is captured here too rather than
                // silently dropped for every vendor added after HSGQ.
                'raw' => $discovery,
            ],
            'derived_command_map' => $learnedCommandMap,
            'metadata' => [
                'source' => (string) $handler['source_label'],
                'real_device_command_executed' => true,
                'generated_at' => date(DATE_ATOM),
                'ssh_username' => $sshUsername,
                'ssh_port' => (int) ($accessProfile['ssh_port'] ?? 22),
                'sample_ont_id' => (string) ($discovery['sample_ont_id'] ?? ''),
                'learned_command_map' => $learnedCommandMap,
            ],
        ];

        return [
            'ok' => true,
            'backup_type' => $backupType,
            'config_text' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'metadata' => $payload['metadata'],
        ];
    }

    $payload = [
        'backup_type' => $backupType,
        'device' => [
            'device_key' => (string) ($device['device_key'] ?? ''),
            'device_name' => (string) ($device['device_name'] ?? ''),
            'ip_address' => (string) ($device['ip_address'] ?? ''),
            'device_type' => (string) ($device['device_type'] ?? ''),
            'vendor' => (string) ($device['vendor'] ?? ''),
            'model' => (string) ($device['model'] ?? ''),
            'adapter_key' => (string) ($device['adapter_key'] ?? ''),
            'management_network' => (string) ($device['management_network'] ?? ''),
            'reachability_state' => (string) ($device['reachability_state'] ?? ''),
            'status' => (string) ($device['status'] ?? ''),
            'confidence_score' => (float) ($device['confidence_score'] ?? 0),
        ],
        'features' => app_mgs_json_decode_array($device['features_json'] ?? null),
        'command_map' => app_mgs_json_decode_array($device['command_map_json'] ?? null),
        'snmp_oids' => app_mgs_json_decode_array($device['snmp_oids_json'] ?? null),
        'notes' => 'Profile snapshot backup. Real running-config backup requires active SSH adapter.',
    ];

    if ($backupType === 'inventory_snapshot') {
        $payload['inventory'] = [
            'interfaces_known' => !empty($payload['features']['interface_status']),
            'sfp_known' => !empty($payload['features']['sfp_status']),
            'ont_known' => !empty($payload['features']['ont_list']),
        ];
    }

    return [
        'ok' => true,
        'backup_type' => $backupType,
        'config_text' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'metadata' => [
            'source' => 'device_learning_profiles',
            'safe_snapshot' => true,
            'real_device_command_executed' => false,
            'generated_at' => date(DATE_ATOM),
        ],
    ];
}

function app_mgs_device_backup_text(array $row): ?string
{
    $compressed = (string) ($row['config_text_compressed'] ?? '');
    if ($compressed === '') {
        return null;
    }

    $decoded = base64_decode($compressed, true);
    if ($decoded === false) {
        return $compressed;
    }

    $text = @gzuncompress($decoded);
    if (is_string($text) && $text !== '') {
        return $text;
    }

    return is_string($decoded) ? $decoded : null;
}

function app_mgs_latest_device_backup(string $deviceKey, string $backupType, ?PDO $db = null, array $actor = []): ?array
{
    $deviceKey = trim($deviceKey);
    $backupType = trim($backupType);
    $db = $db instanceof PDO ? $db : app_db();
    if ($deviceKey === '' || $backupType === '' || !$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return null;
    }
    $device = app_mgs_device_learning_profile_by_key($deviceKey, $db, $actor);
    if (!is_array($device)) {
        return null;
    }
    $authorization = app_noc_authorize($actor, 'read_device_sensitive', $device, $db);
    if (empty($authorization['ok'])) {
        return null;
    }

    $statement = $db->prepare(
        'SELECT *
           FROM device_config_backups
          WHERE device_key = :device_key
            AND backup_type = :backup_type
          ORDER BY created_at DESC, id DESC
          LIMIT 1'
    );
    $statement->execute([
        'device_key' => $deviceKey,
        'backup_type' => $backupType,
    ]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function app_mgs_latest_cli_discovery_summary(array $device, ?PDO $db = null, array $actor = []): ?array
{
    $backup = app_mgs_latest_device_backup((string) ($device['device_key'] ?? ''), 'cli_discovery_snapshot', $db, $actor);
    if (!is_array($backup)) {
        return null;
    }

    $text = app_mgs_device_backup_text($backup);
    if ($text === null || $text === '') {
        return null;
    }

    $decoded = json_decode($text, true);
    if (!is_array($decoded)) {
        return null;
    }

    $discovery = is_array($decoded['discovery'] ?? null) ? $decoded['discovery'] : [];
    $prompts = is_array($discovery['prompts'] ?? null) ? $discovery['prompts'] : [];
    $nodes = is_array($discovery['nodes'] ?? null) ? $discovery['nodes'] : [];
    $keywords = app_mgs_json_decode_array($discovery['keywords'] ?? []);
    $derivedCommandMap = app_mgs_json_decode_array($decoded['derived_command_map'] ?? ($decoded['metadata']['learned_command_map'] ?? []));
    if ($derivedCommandMap === []) {
        $derivedCommandMap = app_mgs_hsgq_derived_command_map_from_discovery([
            'prompts' => $prompts,
            'nodes' => $nodes,
            'sample_ont_id' => (string) ($discovery['sample_ont_id'] ?? ($decoded['metadata']['sample_ont_id'] ?? '')),
        ]);
    }

    return [
        'created_at' => $backup['created_at'] ?? null,
        'last_checked_at' => $backup['last_checked_at'] ?? null,
        'config_hash' => (string) ($backup['config_hash'] ?? ''),
        'prompts' => $prompts,
        'nodes' => $nodes,
        'keywords' => $keywords,
        'sample_ont_id' => (string) ($discovery['sample_ont_id'] ?? ($decoded['metadata']['sample_ont_id'] ?? '')),
        'derived_command_map' => $derivedCommandMap,
    ];
}

function app_mgs_store_device_backup(
    array $device,
    string $backupType = 'profile_snapshot',
    bool $apply = false,
    array $actor = [],
    ?PDO $db = null
): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }

    $authorizationAction = $apply ? 'apply_device_backup' : 'preview_device_backup';
    $authorization = app_noc_authorize($actor, $authorizationAction, $device, $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure($authorizationAction, $authorization);
    }

    $payload = app_mgs_backup_payload_for_device($device, $backupType, $actor, $db, $authorizationAction);
    $deviceKey = (string) ($device['device_key'] ?? '');
    if ($deviceKey === '') {
        return ['ok' => false, 'reason' => 'missing_device_key'];
    }

    if (empty($payload['ok'])) {
        app_mgs_record_operation_action([
            'action_type' => 'device_backup',
            'entity_type' => 'device',
            'entity_id' => $deviceKey,
            'status' => 'skipped',
            'actor' => $actor,
            'summary' => 'Backup skipped: ' . (string) ($payload['reason'] ?? 'not_ready'),
            'payload' => ['device_key' => $deviceKey, 'backup_type' => $backupType],
        ]);
        return ['ok' => true, 'mode' => 'skipped', 'reason' => (string) ($payload['reason'] ?? 'not_ready'), 'device_key' => $deviceKey];
    }

    $configText = (string) ($payload['config_text'] ?? '');
    $hash = hash('sha256', $configText);
    $sizeBytes = strlen($configText);
    $compressed = base64_encode(gzcompress($configText, 6) ?: $configText);

    if (!$apply) {
        app_mgs_record_operation_action([
            'action_type' => 'device_backup',
            'entity_type' => 'device',
            'entity_id' => $deviceKey,
            'status' => 'dry_run',
            'actor' => $actor,
            'summary' => 'Backup dry-run: ' . $deviceKey . ' ' . $backupType,
            'payload' => ['device_key' => $deviceKey, 'backup_type' => $backupType, 'hash' => $hash, 'size_bytes' => $sizeBytes],
        ]);
        return ['ok' => true, 'mode' => 'dry_run', 'device_key' => $deviceKey, 'hash' => $hash, 'size_bytes' => $sizeBytes];
    }

    $latest = $db->prepare(
        'SELECT *
         FROM device_config_backups
         WHERE device_key = :device_key AND backup_type = :backup_type
         ORDER BY created_at DESC, id DESC
         LIMIT 1'
    );
    $latest->execute(['device_key' => $deviceKey, 'backup_type' => $backupType]);
    $previous = $latest->fetch();

    if (is_array($previous) && hash_equals((string) ($previous['config_hash'] ?? ''), $hash)) {
        if ($backupType === 'cli_discovery_snapshot') {
            $learnedCommandMap = app_mgs_json_decode_array($payload['metadata']['learned_command_map'] ?? []);
            if ($learnedCommandMap !== []) {
                app_mgs_sync_device_command_map($deviceKey, $learnedCommandMap, $actor, $db);
            }
        }
        $update = $db->prepare('UPDATE device_config_backups SET last_checked_at = NOW() WHERE id = :id');
        $update->execute(['id' => (int) $previous['id']]);
        app_mgs_record_operation_action([
            'action_type' => 'device_backup',
            'entity_type' => 'device',
            'entity_id' => $deviceKey,
            'status' => 'skipped',
            'actor' => $actor,
            'summary' => 'Backup unchanged: ' . $deviceKey . ' ' . $backupType,
            'payload' => ['device_key' => $deviceKey, 'backup_type' => $backupType, 'hash' => $hash],
        ]);
        return ['ok' => true, 'mode' => 'duplicate', 'device_key' => $deviceKey, 'hash' => $hash];
    }

    $diffSummary = is_array($previous)
        ? 'Config/profile hash changed from ' . substr((string) ($previous['config_hash'] ?? ''), 0, 12) . ' to ' . substr($hash, 0, 12)
        : 'Initial backup snapshot';

    $insert = $db->prepare(
        'INSERT INTO device_config_backups (
            device_profile_id, device_key, device_name, ip_address, backup_type, config_hash,
            config_text_compressed, size_bytes, changed_from_previous, diff_summary, status, metadata_json
         ) VALUES (
            :device_profile_id, :device_key, :device_name, :ip_address, :backup_type, :config_hash,
            :config_text_compressed, :size_bytes, :changed_from_previous, :diff_summary, "stored", :metadata_json
         )'
    );
    $insert->execute([
        'device_profile_id' => (int) ($device['id'] ?? 0) ?: null,
        'device_key' => $deviceKey,
        'device_name' => (string) ($device['device_name'] ?? $deviceKey),
        'ip_address' => (string) ($device['ip_address'] ?? ''),
        'backup_type' => $backupType,
        'config_hash' => $hash,
        'config_text_compressed' => $compressed,
        'size_bytes' => $sizeBytes,
        'changed_from_previous' => is_array($previous) ? 1 : 0,
        'diff_summary' => $diffSummary,
        'metadata_json' => app_db_json_encode($payload['metadata'] ?? []),
    ]);

    if ($backupType === 'cli_discovery_snapshot') {
        $learnedCommandMap = app_mgs_json_decode_array($payload['metadata']['learned_command_map'] ?? []);
        if ($learnedCommandMap !== []) {
            app_mgs_sync_device_command_map($deviceKey, $learnedCommandMap, $actor, $db);
        }
    }

    app_mgs_record_operation_action([
        'action_type' => 'device_backup',
        'entity_type' => 'device',
        'entity_id' => $deviceKey,
        'status' => 'executed',
        'actor' => $actor,
        'summary' => 'Backup stored: ' . $deviceKey . ' ' . $backupType,
        'payload' => ['device_key' => $deviceKey, 'backup_type' => $backupType, 'hash' => $hash, 'diff_summary' => $diffSummary],
    ]);

    return ['ok' => true, 'mode' => 'stored', 'device_key' => $deviceKey, 'hash' => $hash, 'diff_summary' => $diffSummary];
}

function app_mgs_run_backup_worker(
    ?PDO $db = null,
    bool $apply = false,
    int $limit = 5,
    string $backupType = 'profile_snapshot',
    array $actor = []
): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }
    $authorizationAction = $apply ? 'apply_device_backup' : 'preview_device_backup';
    $authorization = app_noc_authorize($actor, $authorizationAction, [], $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure($authorizationAction, $authorization);
    }

    $limit = max(1, min($limit, 50));
    $rows = $db->query(
        'SELECT *
         FROM device_learning_profiles
         WHERE monitoring_enabled = 1
         ORDER BY COALESCE(last_probe_at, created_at), id
         LIMIT ' . $limit
    )->fetchAll() ?: [];
    $rows = app_noc_filter_objects($rows, $actor, $db);

    $results = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $results[] = app_mgs_store_device_backup($row, $backupType, $apply, $actor, $db);
        }
    }

    $stored = count(array_filter($results, static fn (array $row): bool => ($row['mode'] ?? '') === 'stored'));
    $duplicates = count(array_filter($results, static fn (array $row): bool => ($row['mode'] ?? '') === 'duplicate'));
    $dryRun = count(array_filter($results, static fn (array $row): bool => ($row['mode'] ?? '') === 'dry_run'));
    $skipped = count(array_filter($results, static fn (array $row): bool => ($row['mode'] ?? '') === 'skipped'));
    $retentionDeleted = 0;
    if ($apply) {
        try {
            $retentionDeleted = (int) $db->exec('DELETE FROM device_config_backups WHERE created_at < DATE_SUB(NOW(), INTERVAL 42 DAY)');
        } catch (Throwable) {
            $retentionDeleted = 0;
        }
    }

    app_mgs_record_operation_action([
        'action_type' => 'backup_worker_run',
        'entity_type' => 'backup',
        'entity_id' => $backupType,
        'status' => $apply ? 'executed' : 'dry_run',
        'actor' => $actor,
        'summary' => 'Backup worker ' . ($apply ? 'apply' : 'dry-run') . ': ' . count($results) . ' devices',
        'payload' => ['backup_type' => $backupType, 'stored' => $stored, 'duplicates' => $duplicates, 'dry_run' => $dryRun, 'skipped' => $skipped, 'retention_deleted' => $retentionDeleted],
    ]);

    return [
        'ok' => true,
        'mode' => $apply ? 'apply' : 'dry-run',
        'backup_type' => $backupType,
        'checked' => count($results),
        'stored' => $stored,
        'duplicates' => $duplicates,
        'dry_run' => $dryRun,
        'skipped' => $skipped,
        'retention_deleted' => $retentionDeleted,
        'results' => $results,
    ];
}

function app_mgs_summary(?PDO $db = null, bool $syncRegistries = true, array $actor = []): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['db' => false];
    }
    $authorization = app_noc_authorize($actor, 'read_device', [], $db);
    if (empty($authorization['ok'])) {
        return ['db' => true, 'authorized' => false, 'reason' => (string) ($authorization['reason'] ?? 'noc_authorization_required')];
    }

    if ($syncRegistries) {
        app_mgs_sync_telegram_bot_registry($db);
        if (!empty(app_noc_authorize($actor, 'seed_device_profiles', [], $db)['ok'])) {
            app_mgs_seed_device_learning_profiles($db, $actor);
        }
        app_mgs_sync_process_registry($db, $actor);
    }

    $scalar = static function (string $sql) use ($db): int {
        try {
            return (int) $db->query($sql)->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    };

    return [
        'db' => true,
        'alarms_open' => $scalar('SELECT COUNT(*) FROM mgs_alarm_events WHERE status = "open"'),
        'alarms_test' => $scalar('SELECT COUNT(*) FROM mgs_alarm_events WHERE status = "test"'),
        'operations_today' => $scalar('SELECT COUNT(*) FROM mgs_operation_actions WHERE created_at >= CURDATE()'),
        'device_profiles' => $scalar('SELECT COUNT(*) FROM device_learning_profiles'),
        'devices_waiting_path' => $scalar('SELECT COUNT(*) FROM device_learning_profiles WHERE reachability_state = "unreachable_because_monitor_path_down"'),
        'devices_reachable' => $scalar('SELECT COUNT(*) FROM device_learning_profiles WHERE reachability_state = "reachable"'),
        'devices_unreachable' => $scalar('SELECT COUNT(*) FROM device_learning_profiles WHERE reachability_state = "unreachable_confirmed"'),
        'devices_unknown' => $scalar('SELECT COUNT(*) FROM device_learning_profiles WHERE reachability_state = "unknown"'),
        'devices_not_monitored' => $scalar('SELECT COUNT(*) FROM device_learning_profiles WHERE reachability_state = "not_monitored"'),
        'devices_ssh_confirmed' => $scalar('SELECT COUNT(*) FROM device_learning_profiles WHERE monitoring_enabled = 1 AND last_successful_ssh_at IS NOT NULL'),
        'devices_ssh_auth_confirmed' => $scalar('SELECT COUNT(*) FROM device_learning_profiles WHERE monitoring_enabled = 1 AND last_successful_ssh_auth_at IS NOT NULL'),
        'devices_snmp_confirmed' => $scalar('SELECT COUNT(*) FROM device_learning_profiles WHERE monitoring_enabled = 1 AND last_successful_snmp_at IS NOT NULL'),
        'device_access_overrides' => $scalar('SELECT COUNT(*) FROM device_access_overrides'),
        'bots_ready' => $scalar('SELECT COUNT(*) FROM telegram_bot_registry WHERE status = "ready"'),
        'bots_total' => $scalar('SELECT COUNT(*) FROM telegram_bot_registry'),
        'inventory_items' => $scalar('SELECT COUNT(*) FROM inventory_stock_items'),
        'device_backups' => $scalar('SELECT COUNT(*) FROM device_config_backups'),
        'device_backup_duplicates' => $scalar('SELECT COUNT(*) FROM mgs_operation_actions WHERE action_type = "device_backup" AND status = "skipped" AND summary LIKE "Backup unchanged:%"'),
        'device_backup_versions' => $scalar('SELECT COUNT(*) FROM device_config_backups WHERE status = "stored"'),
        'employee_snapshots' => $scalar('SELECT COUNT(*) FROM employee_work_snapshots WHERE work_date = CURDATE()'),
        'processes_ready' => $scalar('SELECT COUNT(*) FROM mgs_process_registry WHERE status = "ready"'),
        'processes_total' => $scalar('SELECT COUNT(*) FROM mgs_process_registry'),
        'dedup_keys' => $scalar('SELECT COUNT(*) FROM mgs_dedup_registry'),
        'management_path_ready' => app_mgs_management_path_ready() ? 1 : 0,
    ];
}

function app_mgs_recent_rows(string $table, int $limit = 20): array
{
    $db = app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [];
    }

    $allowed = [
        'mgs_alarm_events' => 'updated_at',
        'mgs_operation_actions' => 'created_at',
        'employee_work_snapshots' => 'updated_at',
        'inventory_stock_items' => 'updated_at',
        'device_config_backups' => 'last_checked_at',
        'mgs_process_registry' => 'updated_at',
        'mgs_dedup_registry' => 'last_seen_at',
    ];
    if (!isset($allowed[$table])) {
        return [];
    }

    $safeTable = str_replace('`', '``', $table);
    $orderColumn = $allowed[$table];
    $statement = $db->query('SELECT * FROM `' . $safeTable . '` ORDER BY `' . $orderColumn . '` DESC, id DESC LIMIT ' . max(1, min($limit, 100)));
    return $statement !== false ? ($statement->fetchAll() ?: []) : [];
}

function app_mgs_noc_recent_rows(string $table, int $limit, array $actor = []): array
{
    $allowedTables = [
        'mgs_alarm_events',
        'mgs_operation_actions',
        'mgs_process_registry',
        'mgs_dedup_registry',
        'device_config_backups',
    ];
    if (!in_array($table, $allowedTables, true)) {
        return [];
    }
    $authorization = app_noc_authorize($actor, 'read_noc_logs', [], app_db());
    if (empty($authorization['ok'])) {
        return [];
    }

    return app_mgs_recent_rows($table, $limit);
}

function app_mgs_json_decode_array(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }

    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function app_mgs_bridge_modules(): array
{
    return [
        ['key' => 'monitor', 'title' => 'Monitor', 'module' => 'monitor', 'url' => 'noc-devices.php'],
        ['key' => 'ont', 'title' => 'OLT / ONT', 'module' => 'ont', 'url' => 'noc-devices.php?view=devices'],
        ['key' => 'mpls', 'title' => 'MPLS / VLAN', 'module' => 'mpls', 'url' => 'network-definitions.php'],
        ['key' => 'inventory', 'title' => 'Anbar', 'module' => 'inventory', 'url' => 'operations-center.php?section=inventory'],
        ['key' => 'finance', 'title' => 'Maliyye', 'module' => 'finance', 'url' => 'operations-center.php?section=finance'],
        ['key' => 'technical', 'title' => 'Texniki isler', 'module' => 'technical', 'url' => 'operations-center.php?section=technical'],
        ['key' => 'kpi', 'title' => 'KPI', 'module' => 'kpi', 'url' => 'operations-center.php?section=hr&focus=kpi'],
        ['key' => 'reports', 'title' => 'Statistika', 'module' => 'reports', 'url' => 'statistics-center.php'],
        ['key' => 'backup', 'title' => 'Loglar / backup', 'module' => 'backup', 'url' => 'noc-devices.php'],
        ['key' => 'hr', 'title' => 'HR / istifadeciler', 'module' => 'hr', 'url' => 'operations-center.php?section=hr'],
    ];
}

function app_mgs_copilot_context(?PDO $db = null, array $actor = []): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return [
            'ok' => false,
            'generated_at' => date(DATE_ATOM),
            'reason' => 'database_unavailable',
            'next_safe_actions' => ['Start MySQL/XAMPP and rerun tools/production-readiness-check.php.'],
        ];
    }
    $authorization = app_noc_authorize($actor, 'read_noc_logs', [], $db);
    if (empty($authorization['ok'])) {
        return app_noc_authorization_failure('read_noc_logs', $authorization) + [
            'generated_at' => date(DATE_ATOM),
            'next_safe_actions' => [],
        ];
    }

    $summary = app_mgs_summary($db, false, $actor);
    $bridge = app_mgs_bridge_status();
    $managementPathReady = app_mgs_management_path_ready();

    $processes = array_map(static function (array $row): array {
        return [
            'key' => (string) ($row['process_key'] ?? ''),
            'group' => (string) ($row['process_group'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'owner_role' => (string) ($row['owner_role'] ?? ''),
            'source_module' => (string) ($row['source_module'] ?? ''),
            'target_module' => (string) ($row['target_module'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'readiness_state' => (string) ($row['readiness_state'] ?? ''),
            'dedup_policy' => (string) ($row['dedup_policy'] ?? ''),
            'notes' => (string) ($row['notes'] ?? ''),
        ];
    }, app_mgs_noc_recent_rows('mgs_process_registry', 100, $actor));

    $devices = array_map(static function (array $row): array {
        return [
            'device_key' => (string) ($row['device_key'] ?? ''),
            'device_name' => (string) ($row['device_name'] ?? ''),
            'ip_address' => (string) ($row['ip_address'] ?? ''),
            'device_type' => (string) ($row['device_type'] ?? ''),
            'vendor' => (string) ($row['vendor'] ?? ''),
            'model' => (string) ($row['model'] ?? ''),
            'adapter_key' => (string) ($row['adapter_key'] ?? ''),
            'management_network' => (string) ($row['management_network'] ?? ''),
            'expected_reachability' => (bool) ($row['expected_reachability'] ?? false),
            'monitoring_enabled' => (bool) ($row['monitoring_enabled'] ?? false),
            'reachability_state' => (string) ($row['reachability_state'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'confidence_score' => (float) ($row['confidence_score'] ?? 0),
            'features' => app_mgs_json_decode_array($row['features_json'] ?? null),
            'last_successful_ping_at' => $row['last_successful_ping_at'] ?? null,
            'last_successful_ssh_at' => $row['last_successful_ssh_at'] ?? null,
            'last_successful_ssh_auth_at' => $row['last_successful_ssh_auth_at'] ?? null,
            'last_successful_snmp_at' => $row['last_successful_snmp_at'] ?? null,
            'last_probe_at' => $row['last_probe_at'] ?? null,
            'last_probe_error' => $row['last_probe_error'] ?? null,
            'last_probe_method' => $row['last_probe_method'] ?? null,
            'service_probe' => app_mgs_json_decode_array($row['service_probe_json'] ?? null),
        ];
    }, app_mgs_device_learning_rows($db, $actor));

    $bots = array_map(static function (array $row): array {
        return [
            'bot_key' => (string) ($row['bot_key'] ?? ''),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'purpose' => (string) ($row['purpose'] ?? ''),
            'configured' => (bool) ($row['configured'] ?? false),
            'destination_masked' => (string) ($row['destination_masked'] ?? '-'),
            'status' => (string) ($row['status'] ?? ''),
            'last_test_at' => $row['last_test_at'] ?? null,
        ];
    }, app_mgs_telegram_bot_rows($db));

    $dedup = array_map(static function (array $row): array {
        return [
            'domain' => (string) ($row['dedup_domain'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'seen_count' => (int) ($row['seen_count'] ?? 0),
            'first_seen_at' => $row['first_seen_at'] ?? null,
            'last_seen_at' => $row['last_seen_at'] ?? null,
        ];
    }, app_mgs_noc_recent_rows('mgs_dedup_registry', 50, $actor));

    $backups = array_map(static function (array $row): array {
        return [
            'device_key' => (string) ($row['device_key'] ?? ''),
            'device_name' => (string) ($row['device_name'] ?? ''),
            'ip_address' => (string) ($row['ip_address'] ?? ''),
            'backup_type' => (string) ($row['backup_type'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'size_bytes' => (int) ($row['size_bytes'] ?? 0),
            'changed_from_previous' => (bool) ($row['changed_from_previous'] ?? false),
            'diff_summary' => (string) ($row['diff_summary'] ?? ''),
            'last_checked_at' => $row['last_checked_at'] ?? null,
        ];
    }, app_mgs_noc_recent_rows('device_config_backups', 25, $actor));

    $safeActions = [
        'Run php tools/billing-ui-smoke.php after UI changes.',
        'Run php tools/test-mgs-integration.php after MGS/alarm/bot changes.',
        'Run php tools/production-readiness-check.php before cutover.',
        'Run php tools/device-backup-worker.php --dry-run before enabling scheduled backups.',
        'Use mgs-integration.php actions for dry-run bot, alarm, inventory and employee snapshot tests.',
    ];
    if ($managementPathReady) {
        $safeActions[] = 'VLAN 550 path is marked active; device probe can be run from mgs-integration.php.';
    } else {
        $safeActions[] = 'VLAN 550 path is not marked active; keep internal device offline alarms suppressed.';
    }

    return [
        'ok' => true,
        'generated_at' => date(DATE_ATOM),
        'purpose' => 'read_only_copilot_context',
        'safety' => [
            'read_only' => true,
            'secrets_exposed' => false,
            'destructive_actions_allowed' => false,
            'real_telegram_requires_admin_apply' => true,
            'device_probe_requires_management_path' => true,
        ],
        'summary' => $summary,
        'management_path' => [
            'monitor_management_vlan_connected' => $managementPathReady,
            'state' => $managementPathReady ? 'ready_for_probe' : 'internal_offline_alarms_suppressed',
        ],
        'bridge' => [
            'online' => (bool) ($bridge['online'] ?? false),
            'url' => (string) ($bridge['url'] ?? ''),
            'message' => (string) ($bridge['message'] ?? ''),
            'modules' => app_mgs_bridge_modules(),
        ],
        'processes' => $processes,
        'devices' => $devices,
        'telegram_bots' => $bots,
        'dedup' => $dedup,
        'recent_device_backups' => $backups,
        'recent_operations' => array_map(static function (array $row): array {
            return [
                'action_type' => (string) ($row['action_type'] ?? ''),
                'entity_type' => (string) ($row['entity_type'] ?? ''),
                'entity_id' => (string) ($row['entity_id'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'summary' => (string) ($row['summary'] ?? ''),
                'created_at' => $row['created_at'] ?? null,
            ];
        }, app_mgs_noc_recent_rows('mgs_operation_actions', 25, $actor)),
        'validation_commands' => [
            'D:\\xampp\\php\\php.exe D:\\xampp\\htdocs\\superopticnet\\tools\\billing-ui-smoke.php',
            'D:\\xampp\\php\\php.exe D:\\xampp\\htdocs\\superopticnet\\tools\\test-mgs-integration.php',
            'D:\\xampp\\php\\php.exe D:\\xampp\\htdocs\\superopticnet\\tools\\device-backup-worker.php --dry-run --limit=3',
            'D:\\xampp\\php\\php.exe D:\\xampp\\htdocs\\superopticnet\\tools\\production-readiness-check.php',
        ],
        'next_safe_actions' => $safeActions,
    ];
}


function app_mgs_ont_identity_key(string $value): string
{
    return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
}

function app_mgs_ont_profile_matches(array $profile, array $ont): bool
{
    $serialA = app_mgs_ont_identity_key((string) ($profile['serial'] ?? $profile['sn'] ?? ''));
    $serialB = app_mgs_ont_identity_key((string) ($ont['serial'] ?? ''));
    if ($serialA !== '' && $serialB !== '' && $serialA === $serialB) {
        return true;
    }
    $macA = app_mgs_ont_identity_key((string) ($profile['mac'] ?? ''));
    $macB = app_mgs_ont_identity_key((string) ($ont['mac'] ?? ''));
    if ($macA !== '' && $macB !== '' && $macA === $macB) {
        return true;
    }
    $locationB = trim((string) ($ont['olt'] ?? '')) . '|' . trim((string) ($ont['pon'] ?? '')) . '|' . trim((string) ($ont['ont_id'] ?? ''));
    $locationA = trim((string) ($profile['olt'] ?? '')) . '|' . trim((string) ($profile['pon'] ?? '')) . '|' . trim((string) ($profile['ont_id'] ?? ''));

    return $locationB !== '||' && $locationA === $locationB && trim((string) ($ont['olt'] ?? '')) !== '';
}

/** Persist the last live optical readings on the customer's stored ONT profile. */
function app_mgs_store_live_ont_snapshot(int $accountId, array $candidate, array $liveOnt, ?PDO $db = null): bool
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || $accountId <= 0 || $liveOnt === []) {
        return false;
    }
    $statement = $db->prepare('SELECT * FROM portal_accounts WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $accountId]);
    $account = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($account)) {
        return false;
    }
    $payload = app_db_account_payload($account);
    $profiles = is_array($payload['ont_profiles'] ?? null) ? array_values(array_filter($payload['ont_profiles'], 'is_array')) : [];
    $fields = ['temperature', 'ont_rx', 'ont_tx', 'olt_rx', 'olt_tx', 'voltage', 'bias_current', 'status'];
    $live = [];
    foreach ($fields as $field) {
        $value = trim((string) ($liveOnt[$field] ?? ''));
        if ($value !== '' && $value !== '-') {
            $live[$field] = $value;
        }
    }
    if ($live === []) {
        return false;
    }
    $live['last_live_at'] = date('Y-m-d H:i:s');
    $touched = false;
    foreach ($profiles as $index => $profile) {
        if (app_mgs_ont_profile_matches($profile, $candidate)) {
            $profiles[$index] = array_merge($profile, $live);
            $touched = true;
            break;
        }
    }
    if (!$touched) {
        return false;
    }
    $payload['ont_profiles'] = $profiles;
    $update = $db->prepare('UPDATE portal_accounts SET payload_json = :payload WHERE id = :id');

    return $update->execute(['payload' => app_db_json_encode($payload), 'id' => $accountId]);
}
