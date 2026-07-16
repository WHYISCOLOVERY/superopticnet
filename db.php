<?php
declare(strict_types=1);

function app_db_ready(): bool
{
    return app_setting('portal_storage_driver', 'mysql') === 'mysql' && extension_loaded('pdo_mysql');
}

function app_db_settings(): array
{
    return [
        'host' => app_config('db_host', '127.0.0.1'),
        'port' => (int) app_setting('db_port', 3306),
        'name' => app_config('db_name', 'superoptic_portal'),
        'user' => app_config('db_user', 'root'),
        'pass' => app_config('db_pass', ''),
        'charset' => app_config('db_charset', 'utf8mb4'),
    ];
}

function app_db(): ?PDO
{
    static $pdo = null;
    static $bootstrapped = false;

    if (!app_db_ready()) {
        return null;
    }

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $settings = app_db_settings();

    try {
        $serverDsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            $settings['host'],
            $settings['port'],
            $settings['charset']
        );
        $server = new PDO($serverDsn, $settings['user'], $settings['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        try {
            $server->exec(
                sprintf(
                    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s_unicode_ci',
                    str_replace('`', '``', $settings['name']),
                    $settings['charset'],
                    $settings['charset']
                )
            );
        } catch (Throwable) {
            // Production app users should not need global CREATE DATABASE.
            // If the target DB already exists and table-level grants are valid,
            // continue with the normal database connection below.
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $settings['host'],
            $settings['port'],
            $settings['name'],
            $settings['charset']
        );
        $pdo = new PDO($dsn, $settings['user'], $settings['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('SET time_zone = ' . $pdo->quote(app_timezone_offset()));
    } catch (Throwable) {
        return null;
    }

    if (!$bootstrapped) {
        if (app_db_bootstrap_should_run($pdo, $settings)) {
            app_db_bootstrap($pdo);
            app_db_bootstrap_write_stamp($settings);
        }
        app_db_runtime_migrations($pdo);
        $bootstrapped = true;
    }

    return $pdo;
}

function app_db_available(): bool
{
    return app_db() instanceof PDO;
}

function app_db_bootstrap_schema_version(): string
{
    return '2026-07-02-operational-crud-identity-v2';
}

function app_db_bootstrap_stamp_path(): string
{
    return __DIR__ . '/data/db-bootstrap-ok.json';
}

function app_db_bootstrap_settings_hash(array $settings): string
{
    return hash('sha256', implode('|', [
        (string) ($settings['host'] ?? ''),
        (string) ($settings['port'] ?? ''),
        (string) ($settings['name'] ?? ''),
        (string) ($settings['user'] ?? ''),
        (string) ($settings['charset'] ?? ''),
    ]));
}

function app_db_bootstrap_stamp_valid(array $settings): bool
{
    $path = app_db_bootstrap_stamp_path();
    if (!is_file($path)) {
        return false;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return false;
    }

    return (string) ($decoded['schema_version'] ?? '') === app_db_bootstrap_schema_version()
        && (string) ($decoded['settings_hash'] ?? '') === app_db_bootstrap_settings_hash($settings);
}

function app_db_core_schema_ready(PDO $db): bool
{
    $requiredTables = [
        'portal_accounts',
        'billing_tariffs',
        'billing_radius_services',
        'billing_payments',
        'communication_messages',
        'radacct',
        'radreply',
    ];

    try {
        $placeholders = [];
        $params = [];
        foreach ($requiredTables as $index => $table) {
            $key = 'table_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $table;
        }

        $statement = $db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN (' . implode(',', $placeholders) . ')'
        );
        $statement->execute($params);

        return (int) $statement->fetchColumn() === count($requiredTables);
    } catch (Throwable) {
        return false;
    }
}

function app_db_bootstrap_should_run(PDO $db, array $settings): bool
{
    $mode = strtolower((string) app_setting('db_bootstrap_mode', 'cached'));
    if ($mode === 'always') {
        return true;
    }

    if ($mode === 'off') {
        return false;
    }

    return !(app_db_bootstrap_stamp_valid($settings) && app_db_core_schema_ready($db));
}

function app_db_bootstrap_write_stamp(array $settings): void
{
    $path = app_db_bootstrap_stamp_path();
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    file_put_contents($path, json_encode([
        'schema_version' => app_db_bootstrap_schema_version(),
        'settings_hash' => app_db_bootstrap_settings_hash($settings),
        'created_at' => date(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function app_db_bootstrap(PDO $db): void
{
    $schema = [
        "CREATE TABLE IF NOT EXISTS portal_accounts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            external_id VARCHAR(64) DEFAULT NULL,
            login VARCHAR(191) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(32) NOT NULL,
            account_type VARCHAR(32) NOT NULL DEFAULT 'customer',
            employee_role VARCHAR(64) NOT NULL DEFAULT '',
            job_title VARCHAR(191) DEFAULT NULL,
            full_name VARCHAR(191) NOT NULL,
            subscriber_code VARCHAR(64) DEFAULT NULL,
            contract_no VARCHAR(64) DEFAULT NULL,
            fin_code VARCHAR(32) DEFAULT NULL,
            document_no VARCHAR(64) DEFAULT NULL,
            email VARCHAR(191) DEFAULT NULL,
            phone VARCHAR(32) DEFAULT NULL,
            category VARCHAR(128) DEFAULT NULL,
            area VARCHAR(128) DEFAULT NULL,
            address VARCHAR(255) DEFAULT NULL,
            plan VARCHAR(191) DEFAULT NULL,
            status VARCHAR(64) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            payload_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_portal_accounts_login (login),
            UNIQUE KEY uq_portal_accounts_subscriber_code (subscriber_code),
            KEY idx_portal_accounts_role_type (role, account_type),
            KEY idx_portal_accounts_role_active_status (role, is_active, status),
            KEY idx_portal_accounts_role_area (role, area),
            KEY idx_portal_accounts_area (area),
            KEY idx_portal_accounts_name (full_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS portal_account_areas (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT UNSIGNED NOT NULL,
            area VARCHAR(128) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_portal_account_area (account_id, area),
            KEY idx_portal_account_area_area (area),
            CONSTRAINT fk_portal_account_areas_account
                FOREIGN KEY (account_id) REFERENCES portal_accounts(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS customer_area_code_prefixes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            area_name VARCHAR(128) NOT NULL,
            area_key VARCHAR(160) NOT NULL,
            code_prefix VARCHAR(16) NOT NULL,
            start_number INT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            note VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_customer_area_code_area_key (area_key),
            KEY idx_customer_area_code_prefix (code_prefix),
            KEY idx_customer_area_code_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS company_branches (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            branch_name VARCHAR(128) NOT NULL,
            branch_key VARCHAR(160) NOT NULL,
            area_name VARCHAR(128) DEFAULT NULL,
            address VARCHAR(255) DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            note VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_company_branches_key (branch_key),
            KEY idx_company_branches_status (status),
            KEY idx_company_branches_area (area_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS portal_tickets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_no VARCHAR(32) NOT NULL,
            customer_account_id BIGINT UNSIGNED DEFAULT NULL,
            subscriber_code VARCHAR(64) DEFAULT NULL,
            contract_no VARCHAR(64) DEFAULT NULL,
            customer_name VARCHAR(191) NOT NULL,
            phone VARCHAR(32) DEFAULT NULL,
            address VARCHAR(255) DEFAULT NULL,
            area VARCHAR(128) DEFAULT NULL,
            subject VARCHAR(128) NOT NULL,
            message VARCHAR(255) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'Aciqdir',
            opened_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            resolved_at DATETIME DEFAULT NULL,
            assigned_technician_account_id BIGINT UNSIGNED DEFAULT NULL,
            assigned_technician_name VARCHAR(191) DEFAULT NULL,
            resolution_reason VARCHAR(191) DEFAULT NULL,
            resolution_note VARCHAR(255) DEFAULT NULL,
            handled_by VARCHAR(191) DEFAULT NULL,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_by_role VARCHAR(32) DEFAULT NULL,
            created_by_name VARCHAR(191) DEFAULT NULL,
            telegram_message_ids_json LONGTEXT DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_portal_tickets_ticket_no (ticket_no),
            KEY idx_portal_tickets_status (status),
            KEY idx_portal_tickets_area (area),
            KEY idx_portal_tickets_subject (subject),
            KEY idx_portal_tickets_opened_at (opened_at),
            KEY idx_portal_tickets_resolved_at (resolved_at),
            KEY idx_portal_tickets_assigned (assigned_technician_account_id),
            KEY idx_portal_tickets_customer_status (customer_account_id, status, opened_at),
            KEY idx_portal_tickets_subscriber_status (subscriber_code, status, opened_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS portal_ticket_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            event_note VARCHAR(255) DEFAULT NULL,
            actor_account_id BIGINT UNSIGNED DEFAULT NULL,
            actor_name VARCHAR(191) DEFAULT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_portal_ticket_events_ticket (ticket_id, created_at),
            CONSTRAINT fk_portal_ticket_events_ticket
                FOREIGN KEY (ticket_id) REFERENCES portal_tickets(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS legacy_billing_profile_sections (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT UNSIGNED NOT NULL,
            subscriber_code VARCHAR(64) NOT NULL,
            section_key VARCHAR(64) NOT NULL,
            section_title VARCHAR(191) NOT NULL DEFAULT '',
            table_index INT UNSIGNED NOT NULL DEFAULT 0,
            headers_json LONGTEXT DEFAULT NULL,
            rows_json LONGTEXT DEFAULT NULL,
            row_count INT UNSIGNED NOT NULL DEFAULT 0,
            source VARCHAR(64) NOT NULL DEFAULT 'legacy-billing-export',
            imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_legacy_profile_section (account_id, section_key, table_index),
            KEY idx_legacy_profile_code (subscriber_code),
            KEY idx_legacy_profile_section (section_key),
            CONSTRAINT fk_legacy_profile_sections_account
                FOREIGN KEY (account_id) REFERENCES portal_accounts(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS portal_sequences (
            name VARCHAR(64) PRIMARY KEY,
            current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS billing_tariffs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            external_id VARCHAR(64) DEFAULT NULL,
            name VARCHAR(191) NOT NULL,
            service_type ENUM('pppoe','ipoe') NOT NULL DEFAULT 'pppoe',
            download_mbps INT UNSIGNED NOT NULL DEFAULT 0,
            upload_mbps INT UNSIGNED NOT NULL DEFAULT 0,
            monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            radius_group VARCHAR(128) NOT NULL,
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_billing_tariffs_external (external_id),
            UNIQUE KEY uq_billing_tariffs_name_service (name, service_type),
            UNIQUE KEY uq_billing_tariffs_radius_group (radius_group)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS billing_ip_pools (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            service_type ENUM('pppoe','ipoe') NOT NULL DEFAULT 'pppoe',
            pool_type ENUM('dynamic','static') NOT NULL DEFAULT 'dynamic',
            range_start VARCHAR(45) NOT NULL,
            range_end VARCHAR(45) NOT NULL,
            gateway VARCHAR(45) DEFAULT NULL,
            netmask VARCHAR(45) DEFAULT NULL,
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_billing_ip_pools_name (name),
            KEY idx_billing_ip_pools_type (service_type, pool_type, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS ip_pools (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            legacy_billing_pool_id BIGINT UNSIGNED DEFAULT NULL,
            name VARCHAR(100) NOT NULL,
            type ENUM('local','nat_public','static_public') NOT NULL,
            service_type ENUM('pppoe','ipoe','both') NOT NULL DEFAULT 'pppoe',
            start_ip VARCHAR(45) NOT NULL,
            end_ip VARCHAR(45) NOT NULL,
            start_ip_num INT UNSIGNED NOT NULL DEFAULT 0,
            end_ip_num INT UNSIGNED NOT NULL DEFAULT 0,
            gateway VARCHAR(45) DEFAULT NULL,
            netmask VARCHAR(45) DEFAULT NULL,
            zone_name VARCHAR(128) DEFAULT NULL,
            branch_name VARCHAR(128) DEFAULT NULL,
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ip_pools_name (name),
            KEY idx_ip_pools_type_status (type, status),
            KEY idx_ip_pools_legacy (legacy_billing_pool_id),
            KEY idx_ip_pools_range (start_ip_num, end_ip_num)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS ip_addresses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            pool_id BIGINT UNSIGNED NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            ip_num INT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('free','used','reserved','blacklist') NOT NULL DEFAULT 'free',
            customer_id BIGINT UNSIGNED DEFAULT NULL,
            service_username VARCHAR(191) DEFAULT NULL,
            assigned_at DATETIME DEFAULT NULL,
            released_at DATETIME DEFAULT NULL,
            note TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ip_addresses_ip (ip_address),
            KEY idx_ip_addresses_pool_status (pool_id, status),
            KEY idx_ip_addresses_customer (customer_id),
            KEY idx_ip_addresses_username (service_username),
            KEY idx_ip_addresses_num (ip_num),
            CONSTRAINT fk_ip_addresses_pool FOREIGN KEY (pool_id) REFERENCES ip_pools(id) ON DELETE CASCADE,
            CONSTRAINT fk_ip_addresses_customer FOREIGN KEY (customer_id) REFERENCES portal_accounts(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS customer_ip_assignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id BIGINT UNSIGNED NOT NULL,
            service_username VARCHAR(191) DEFAULT NULL,
            local_ip VARCHAR(45) DEFAULT NULL,
            nat_public_ip VARCHAR(45) DEFAULT NULL,
            static_ip VARCHAR(45) DEFAULT NULL,
            assignment_type ENUM('dynamic','static','nat_mapped') NOT NULL DEFAULT 'dynamic',
            radius_synced TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            assigned_by BIGINT UNSIGNED DEFAULT NULL,
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            released_at DATETIME DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            KEY idx_customer_ip_assignments_customer (customer_id, active),
            KEY idx_customer_ip_assignments_username (service_username),
            KEY idx_customer_ip_assignments_local (local_ip),
            KEY idx_customer_ip_assignments_nat (nat_public_ip),
            KEY idx_customer_ip_assignments_static (static_ip),
            CONSTRAINT fk_customer_ip_assignments_customer FOREIGN KEY (customer_id) REFERENCES portal_accounts(id) ON DELETE CASCADE,
            CONSTRAINT fk_customer_ip_assignments_user FOREIGN KEY (assigned_by) REFERENCES portal_accounts(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS ip_assignment_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id BIGINT UNSIGNED NOT NULL,
            service_username VARCHAR(191) DEFAULT NULL,
            old_local_ip VARCHAR(45) DEFAULT NULL,
            new_local_ip VARCHAR(45) DEFAULT NULL,
            old_nat_ip VARCHAR(45) DEFAULT NULL,
            new_nat_ip VARCHAR(45) DEFAULT NULL,
            old_static_ip VARCHAR(45) DEFAULT NULL,
            new_static_ip VARCHAR(45) DEFAULT NULL,
            reason TEXT DEFAULT NULL,
            changed_by BIGINT UNSIGNED DEFAULT NULL,
            changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ip_history_customer (customer_id, changed_at),
            KEY idx_ip_history_username (service_username),
            CONSTRAINT fk_ip_history_customer FOREIGN KEY (customer_id) REFERENCES portal_accounts(id) ON DELETE CASCADE,
            CONSTRAINT fk_ip_history_user FOREIGN KEY (changed_by) REFERENCES portal_accounts(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS radius_session_actions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            action_type ENUM('disconnect_request') NOT NULL DEFAULT 'disconnect_request',
            status ENUM('pending','approved','rejected','executed','cancelled') NOT NULL DEFAULT 'pending',
            account_id BIGINT UNSIGNED DEFAULT NULL,
            username VARCHAR(191) NOT NULL,
            framed_ip VARCHAR(45) DEFAULT NULL,
            nas_ip VARCHAR(45) DEFAULT NULL,
            nas_port_id VARCHAR(191) DEFAULT NULL,
            calling_station_id VARCHAR(191) DEFAULT NULL,
            reason TEXT DEFAULT NULL,
            requested_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            approved_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            result_json LONGTEXT DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_radius_session_actions_status (status, requested_at),
            KEY idx_radius_session_actions_username (username, status),
            KEY idx_radius_session_actions_account (account_id),
            CONSTRAINT fk_radius_session_actions_account FOREIGN KEY (account_id) REFERENCES portal_accounts(id) ON DELETE SET NULL,
            CONSTRAINT fk_radius_session_actions_requested_by FOREIGN KEY (requested_by_account_id) REFERENCES portal_accounts(id) ON DELETE SET NULL,
            CONSTRAINT fk_radius_session_actions_approved_by FOREIGN KEY (approved_by_account_id) REFERENCES portal_accounts(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS network_vlan_definitions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            definition_type ENUM('internet','management','iptv','voice','transport','other') NOT NULL DEFAULT 'internet',
            vlan_id INT UNSIGNED NOT NULL,
            branch VARCHAR(128) DEFAULT NULL,
            customer_name VARCHAR(191) DEFAULT NULL,
            customer_code VARCHAR(64) DEFAULT NULL,
            customer_voen VARCHAR(64) DEFAULT NULL,
            device_name VARCHAR(191) DEFAULT NULL,
            device_ip VARCHAR(45) DEFAULT NULL,
            upstream_port VARCHAR(64) DEFAULT NULL,
            customer_port VARCHAR(64) DEFAULT NULL,
            port_mode ENUM('access','trunk','hybrid','routed') NOT NULL DEFAULT 'access',
            ip_pool_id BIGINT UNSIGNED DEFAULT NULL,
            subnet_cidr VARCHAR(64) DEFAULT NULL,
            gateway_ip VARCHAR(45) DEFAULT NULL,
            customer_ip VARCHAR(45) DEFAULT NULL,
            status ENUM('draft','planned','active','removing','removed','failed') NOT NULL DEFAULT 'draft',
            notes TEXT DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_network_vlan_id (vlan_id),
            KEY idx_network_vlan_branch (branch),
            KEY idx_network_vlan_device (device_ip),
            KEY idx_network_vlan_customer (customer_code),
            KEY idx_network_vlan_status (status),
            CONSTRAINT fk_network_vlan_pool FOREIGN KEY (ip_pool_id) REFERENCES billing_ip_pools(id) ON DELETE SET NULL,
            CONSTRAINT fk_network_vlan_creator FOREIGN KEY (created_by_account_id) REFERENCES portal_accounts(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS network_vlan_path_hops (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            definition_id BIGINT UNSIGNED NOT NULL,
            hop_order INT UNSIGNED NOT NULL DEFAULT 1,
            device_name VARCHAR(191) DEFAULT NULL,
            device_ip VARCHAR(45) DEFAULT NULL,
            ingress_port VARCHAR(64) DEFAULT NULL,
            egress_port VARCHAR(64) DEFAULT NULL,
            port_mode ENUM('access','trunk','hybrid','routed') NOT NULL DEFAULT 'trunk',
            command_preview TEXT DEFAULT NULL,
            status ENUM('planned','applied','failed','removed') NOT NULL DEFAULT 'planned',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_network_vlan_hop_order (definition_id, hop_order),
            KEY idx_network_vlan_hop_device (device_ip),
            CONSTRAINT fk_network_vlan_hop_definition FOREIGN KEY (definition_id) REFERENCES network_vlan_definitions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS billing_radius_services (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT UNSIGNED NOT NULL,
            username VARCHAR(191) NOT NULL,
            service_type ENUM('pppoe','ipoe') NOT NULL DEFAULT 'pppoe',
            tariff_id BIGINT UNSIGNED DEFAULT NULL,
            ip_pool_id BIGINT UNSIGNED DEFAULT NULL,
            assigned_ip VARCHAR(45) DEFAULT NULL,
            nat_public_ip VARCHAR(45) DEFAULT NULL,
            static_ip VARCHAR(45) DEFAULT NULL,
            status ENUM('active','suspended','expired','disabled') NOT NULL DEFAULT 'active',
            expires_at DATETIME DEFAULT NULL,
            auto_suspend TINYINT(1) NOT NULL DEFAULT 1,
            last_radius_sync_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_billing_radius_services_username (username),
            KEY idx_billing_radius_services_account (account_id),
            KEY idx_billing_radius_services_status (status, expires_at),
            KEY idx_billing_radius_services_account_status (account_id, status, expires_at),
            KEY idx_billing_radius_services_ip_lookup (assigned_ip, static_ip, nat_public_ip),
            CONSTRAINT fk_billing_radius_services_account
                FOREIGN KEY (account_id) REFERENCES portal_accounts(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS billing_payments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            external_id VARCHAR(64) DEFAULT NULL,
            account_id BIGINT UNSIGNED NOT NULL,
            subscriber_code VARCHAR(64) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_method VARCHAR(64) NOT NULL DEFAULT 'manual',
            transaction_id VARCHAR(191) DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_billing_payments_external (external_id),
            KEY idx_billing_payments_account (account_id, created_at),
            KEY idx_billing_payments_subscriber (subscriber_code),
            KEY idx_billing_payments_subscriber_created (subscriber_code, created_at),
            KEY idx_billing_payments_method_created (payment_method, created_at),
            CONSTRAINT fk_billing_payments_account
                FOREIGN KEY (account_id) REFERENCES portal_accounts(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS communication_templates (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(64) NOT NULL,
            title VARCHAR(191) NOT NULL,
            category VARCHAR(64) NOT NULL DEFAULT 'general',
            channel_scope VARCHAR(64) NOT NULL DEFAULT 'any',
            body TEXT NOT NULL,
            auto_trigger VARCHAR(128) DEFAULT NULL,
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_communication_templates_code (code),
            KEY idx_communication_templates_category (category, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS communication_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT UNSIGNED DEFAULT NULL,
            subscriber_code VARCHAR(64) DEFAULT NULL,
            recipient_name VARCHAR(191) DEFAULT NULL,
            destination VARCHAR(191) DEFAULT NULL,
            channel VARCHAR(32) NOT NULL DEFAULT 'auto',
            fallback_chain VARCHAR(191) DEFAULT NULL,
            template_code VARCHAR(64) DEFAULT NULL,
            message_body TEXT NOT NULL,
            status ENUM('queued','sent','failed','test') NOT NULL DEFAULT 'queued',
            sent_at DATETIME DEFAULT NULL,
            created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_communication_messages_account (account_id, created_at),
            KEY idx_communication_messages_subscriber (subscriber_code, created_at),
            KEY idx_communication_messages_status (status, created_at),
            KEY idx_communication_messages_template (template_code),
            CONSTRAINT fk_communication_messages_account
                FOREIGN KEY (account_id) REFERENCES portal_accounts(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS communication_delivery_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            message_id BIGINT UNSIGNED DEFAULT NULL,
            account_id BIGINT UNSIGNED DEFAULT NULL,
            subscriber_code VARCHAR(64) DEFAULT NULL,
            channel VARCHAR(32) NOT NULL,
            delivery VARCHAR(64) NOT NULL DEFAULT '',
            destination_masked VARCHAR(64) NOT NULL DEFAULT '',
            status ENUM('sent','failed','skipped') NOT NULL DEFAULT 'failed',
            provider_message_id VARCHAR(191) DEFAULT NULL,
            reason VARCHAR(191) DEFAULT NULL,
            response_summary_json LONGTEXT DEFAULT NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_communication_attempts_message (message_id, attempted_at),
            KEY idx_communication_attempts_status (status, attempted_at),
            KEY idx_communication_attempts_channel (channel, attempted_at),
            KEY idx_communication_attempts_account (account_id, attempted_at),
            CONSTRAINT fk_communication_attempts_message
                FOREIGN KEY (message_id) REFERENCES communication_messages(id)
                ON DELETE SET NULL,
            CONSTRAINT fk_communication_attempts_account
                FOREIGN KEY (account_id) REFERENCES portal_accounts(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS communication_worker_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            mode VARCHAR(32) NOT NULL DEFAULT 'apply',
            channel VARCHAR(32) NOT NULL DEFAULT 'all',
            status ENUM('completed','failed') NOT NULL DEFAULT 'completed',
            limit_count INT UNSIGNED NOT NULL DEFAULT 0,
            scanned_count INT UNSIGNED NOT NULL DEFAULT 0,
            would_send_count INT UNSIGNED NOT NULL DEFAULT 0,
            sent_count INT UNSIGNED NOT NULL DEFAULT 0,
            failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            left_queued_count INT UNSIGNED NOT NULL DEFAULT 0,
            unconfigured_count INT UNSIGNED NOT NULL DEFAULT 0,
            fail_unconfigured TINYINT(1) NOT NULL DEFAULT 0,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            by_channel_json LONGTEXT DEFAULT NULL,
            summary_json LONGTEXT DEFAULT NULL,
            error_summary VARCHAR(191) DEFAULT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_comm_worker_finished (finished_at),
            KEY idx_comm_worker_status (status, finished_at),
            KEY idx_comm_worker_channel (channel, finished_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS billing_maintenance_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            mode VARCHAR(32) NOT NULL DEFAULT 'dry-run',
            status ENUM('completed','failed') NOT NULL DEFAULT 'completed',
            apply_changes TINYINT(1) NOT NULL DEFAULT 0,
            sync_radius TINYINT(1) NOT NULL DEFAULT 0,
            queue_notices TINYINT(1) NOT NULL DEFAULT 0,
            channel VARCHAR(32) NOT NULL DEFAULT 'auto',
            limit_count INT UNSIGNED NOT NULL DEFAULT 0,
            notice_limit_count INT UNSIGNED NOT NULL DEFAULT 0,
            countdown_candidates_count INT UNSIGNED NOT NULL DEFAULT 0,
            expired_candidates_count INT UNSIGNED NOT NULL DEFAULT 0,
            notice_expiring_count INT UNSIGNED NOT NULL DEFAULT 0,
            notice_expired_count INT UNSIGNED NOT NULL DEFAULT 0,
            countdown_checked_count INT UNSIGNED NOT NULL DEFAULT 0,
            countdown_updated_count INT UNSIGNED NOT NULL DEFAULT 0,
            countdown_expired_count INT UNSIGNED NOT NULL DEFAULT 0,
            suspended_count INT UNSIGNED NOT NULL DEFAULT 0,
            radius_synced_count INT UNSIGNED NOT NULL DEFAULT 0,
            notices_queued_count INT UNSIGNED NOT NULL DEFAULT 0,
            notices_duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
            notices_failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            summary_json LONGTEXT DEFAULT NULL,
            error_summary VARCHAR(191) DEFAULT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_billing_maintenance_finished (finished_at),
            KEY idx_billing_maintenance_status (status, finished_at),
            KEY idx_billing_maintenance_mode (mode, finished_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS portal_account_identities (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(64) NOT NULL,
            provider_subject VARCHAR(191) NOT NULL,
            fin_code VARCHAR(32) DEFAULT NULL,
            document_no VARCHAR(64) DEFAULT NULL,
            verified_full_name VARCHAR(191) DEFAULT NULL,
            verified_birth_date DATE DEFAULT NULL,
            last_verified_at DATETIME DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_portal_identity_provider_subject (provider, provider_subject),
            UNIQUE KEY uq_portal_identity_account_provider (account_id, provider),
            KEY idx_portal_identity_fin (provider, fin_code),
            CONSTRAINT fk_portal_identity_account
                FOREIGN KEY (account_id) REFERENCES portal_accounts(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS sso_auth_states (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            state_hash CHAR(64) NOT NULL,
            provider VARCHAR(64) NOT NULL,
            requested_role ENUM('customer','employee') NOT NULL DEFAULT 'customer',
            return_url VARCHAR(255) DEFAULT NULL,
            nonce VARCHAR(128) DEFAULT NULL,
            code_verifier VARCHAR(191) DEFAULT NULL,
            client_ip VARCHAR(64) DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sso_auth_states_state_hash (state_hash),
            KEY idx_sso_auth_states_provider_expiry (provider, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS radcheck (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL DEFAULT '',
            attribute VARCHAR(64) NOT NULL DEFAULT '',
            op CHAR(2) NOT NULL DEFAULT '==',
            value VARCHAR(253) NOT NULL DEFAULT '',
            KEY idx_radcheck_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS radreply (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL DEFAULT '',
            attribute VARCHAR(64) NOT NULL DEFAULT '',
            op CHAR(2) NOT NULL DEFAULT '=',
            value VARCHAR(253) NOT NULL DEFAULT '',
            KEY idx_radreply_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS radusergroup (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL DEFAULT '',
            groupname VARCHAR(64) NOT NULL DEFAULT '',
            priority INT NOT NULL DEFAULT 1,
            UNIQUE KEY uq_radusergroup_user_group (username, groupname),
            KEY idx_radusergroup_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS radgroupreply (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            groupname VARCHAR(64) NOT NULL DEFAULT '',
            attribute VARCHAR(64) NOT NULL DEFAULT '',
            op CHAR(2) NOT NULL DEFAULT '=',
            value VARCHAR(253) NOT NULL DEFAULT '',
            KEY idx_radgroupreply_groupname (groupname)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS radgroupcheck (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            groupname VARCHAR(64) NOT NULL DEFAULT '',
            attribute VARCHAR(64) NOT NULL DEFAULT '',
            op CHAR(2) NOT NULL DEFAULT '==',
            value VARCHAR(253) NOT NULL DEFAULT '',
            KEY idx_radgroupcheck_groupname (groupname)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS radacct (
            radacctid BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            acctsessionid VARCHAR(64) NOT NULL DEFAULT '',
            acctuniqueid VARCHAR(64) NOT NULL DEFAULT '',
            username VARCHAR(64) NOT NULL DEFAULT '',
            realm VARCHAR(64) DEFAULT '',
            nasipaddress VARCHAR(45) NOT NULL DEFAULT '',
            nasportid VARCHAR(32) DEFAULT NULL,
            nasporttype VARCHAR(32) DEFAULT NULL,
            acctstarttime DATETIME DEFAULT NULL,
            acctupdatetime DATETIME DEFAULT NULL,
            acctstoptime DATETIME DEFAULT NULL,
            acctinterval INT UNSIGNED DEFAULT NULL,
            acctsessiontime INT UNSIGNED DEFAULT NULL,
            acctauthentic VARCHAR(32) DEFAULT NULL,
            connectinfo_start VARCHAR(50) DEFAULT NULL,
            connectinfo_stop VARCHAR(50) DEFAULT NULL,
            acctinputoctets BIGINT DEFAULT NULL,
            acctoutputoctets BIGINT DEFAULT NULL,
            calledstationid VARCHAR(50) NOT NULL DEFAULT '',
            callingstationid VARCHAR(50) NOT NULL DEFAULT '',
            acctterminatecause VARCHAR(32) NOT NULL DEFAULT '',
            servicetype VARCHAR(32) DEFAULT NULL,
            framedprotocol VARCHAR(32) DEFAULT NULL,
            framedipaddress VARCHAR(45) NOT NULL DEFAULT '',
            framedipv6address VARCHAR(45) NOT NULL DEFAULT '',
            framedipv6prefix VARCHAR(45) NOT NULL DEFAULT '',
            framedinterfaceid VARCHAR(44) NOT NULL DEFAULT '',
            delegatedipv6prefix VARCHAR(45) NOT NULL DEFAULT '',
            class VARCHAR(64) DEFAULT NULL,
            KEY idx_radacct_username (username),
            KEY idx_radacct_acctsessionid (acctsessionid),
            KEY idx_radacct_acctuniqueid (acctuniqueid),
            KEY idx_radacct_start_user (acctstarttime, username),
            KEY idx_radacct_stop_user (acctstoptime, username),
            KEY idx_radacct_class (class)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS radpostauth (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL DEFAULT '',
            pass VARCHAR(64) NOT NULL DEFAULT '',
            reply VARCHAR(32) NOT NULL DEFAULT '',
            authdate TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            class VARCHAR(64) DEFAULT NULL,
            KEY idx_radpostauth_username (username),
            KEY idx_radpostauth_authdate (authdate),
            KEY idx_radpostauth_class (class)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS radius_nat_log_nodes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            node_name VARCHAR(128) NOT NULL,
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            disk_total_gb DECIMAL(12,2) DEFAULT NULL,
            disk_used_gb DECIMAL(12,2) DEFAULT NULL,
            disk_available_gb DECIMAL(12,2) DEFAULT NULL,
            disk_used_percent DECIMAL(6,2) DEFAULT NULL,
            last_seen_at DATETIME DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_radius_nat_node_name (node_name),
            KEY idx_radius_nat_node_ip (ip_address),
            KEY idx_radius_nat_node_last_seen (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS radius_nat_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            logged_at DATETIME NOT NULL,
            node_name VARCHAR(128) NOT NULL DEFAULT '',
            protocol VARCHAR(16) NOT NULL DEFAULT '',
            source_ip VARCHAR(45) NOT NULL DEFAULT '',
            source_port INT UNSIGNED DEFAULT NULL,
            nat_ip VARCHAR(45) NOT NULL DEFAULT '',
            nat_port INT UNSIGNED DEFAULT NULL,
            destination_ip VARCHAR(45) NOT NULL DEFAULT '',
            destination_port INT UNSIGNED DEFAULT NULL,
            username VARCHAR(64) DEFAULT NULL,
            subscriber_code VARCHAR(64) DEFAULT NULL,
            raw_message TEXT DEFAULT NULL,
            message_hash CHAR(64) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_radius_nat_logged_at (logged_at),
            KEY idx_radius_nat_source (source_ip, source_port),
            KEY idx_radius_nat_destination (destination_ip, destination_port),
            KEY idx_radius_nat_nat_ip (nat_ip, nat_port),
            KEY idx_radius_nat_username (username),
            KEY idx_radius_nat_subscriber (subscriber_code),
            KEY idx_radius_nat_node_time (node_name, logged_at),
            UNIQUE KEY uq_radius_nat_hash (message_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS nas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nasname VARCHAR(128) NOT NULL,
            shortname VARCHAR(32) DEFAULT NULL,
            type VARCHAR(30) DEFAULT 'other',
            ports INT DEFAULT NULL,
            secret VARCHAR(60) NOT NULL DEFAULT 'secret',
            server VARCHAR(64) DEFAULT NULL,
            community VARCHAR(50) DEFAULT NULL,
            description VARCHAR(200) DEFAULT NULL,
            UNIQUE KEY uq_nasname (nasname)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS nasreload (
            nasipaddress VARCHAR(45) NOT NULL,
            reloadtime DATETIME NOT NULL,
            PRIMARY KEY (nasipaddress)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($schema as $statement) {
        $db->exec($statement);
    }

    app_db_migrate_schema($db);
    app_db_seed_if_empty($db);
    app_billing_seed_defaults($db);
    app_message_seed_defaults($db);
}

function app_db_runtime_migrations(PDO $db): void
{
    if (!app_db_core_schema_ready($db)) {
        return;
    }

    app_db_ensure_default_admin($db);
}

function app_db_column_exists(PDO $db, string $table, string $column): bool
{
    $table = str_replace('`', '``', $table);
    $column = str_replace(['\\', "'"], ['\\\\', "\\'"], $column);
    $statement = $db->query(sprintf("SHOW COLUMNS FROM `%s` LIKE '%s'", $table, $column));
    return $statement !== false && (bool) $statement->fetch(PDO::FETCH_ASSOC);
}

function app_db_index_exists(PDO $db, string $table, string $index): bool
{
    $table = str_replace('`', '``', $table);
    $index = str_replace(['\\', "'"], ['\\\\', "\\'"], $index);
    $statement = $db->query(sprintf("SHOW INDEX FROM `%s` WHERE Key_name = '%s'", $table, $index));
    return $statement !== false && (bool) $statement->fetch(PDO::FETCH_ASSOC);
}

function app_db_migrate_schema(PDO $db): void
{
    $accountColumns = [
        'job_title' => "ALTER TABLE portal_accounts ADD COLUMN job_title VARCHAR(191) DEFAULT NULL AFTER employee_role",
        'fin_code' => "ALTER TABLE portal_accounts ADD COLUMN fin_code VARCHAR(32) DEFAULT NULL AFTER contract_no",
        'document_no' => "ALTER TABLE portal_accounts ADD COLUMN document_no VARCHAR(64) DEFAULT NULL AFTER fin_code",
    ];
    foreach ($accountColumns as $column => $sql) {
        if (!app_db_column_exists($db, 'portal_accounts', $column)) {
            $db->exec($sql);
        }
    }
    if (!app_db_index_exists($db, 'portal_accounts', 'idx_portal_accounts_fin_code')
        && !app_db_index_exists($db, 'portal_accounts', 'idx_portal_accounts_fin')) {
        $db->exec('CREATE INDEX idx_portal_accounts_fin_code ON portal_accounts (fin_code)');
    }

    $ticketColumns = [
        'customer_account_id' => "ALTER TABLE portal_tickets ADD COLUMN customer_account_id BIGINT UNSIGNED DEFAULT NULL AFTER ticket_no",
        'assigned_technician_account_id' => "ALTER TABLE portal_tickets ADD COLUMN assigned_technician_account_id BIGINT UNSIGNED DEFAULT NULL AFTER resolved_at",
        'created_by_account_id' => "ALTER TABLE portal_tickets ADD COLUMN created_by_account_id BIGINT UNSIGNED DEFAULT NULL AFTER handled_by",
        'metadata_json' => "ALTER TABLE portal_tickets ADD COLUMN metadata_json LONGTEXT DEFAULT NULL AFTER telegram_message_ids_json",
    ];

    foreach ($ticketColumns as $column => $sql) {
        if (!app_db_column_exists($db, 'portal_tickets', $column)) {
            $db->exec($sql);
        }
    }

    if (app_db_column_exists($db, 'portal_tickets', 'customer_public_id') && app_db_column_exists($db, 'portal_tickets', 'customer_account_id')) {
        $db->exec(
            "UPDATE portal_tickets t
             LEFT JOIN portal_accounts a
               ON a.external_id COLLATE utf8mb4_general_ci = t.customer_public_id COLLATE utf8mb4_general_ci
             SET t.customer_account_id = COALESCE(t.customer_account_id, a.id)
             WHERE t.customer_account_id IS NULL AND t.customer_public_id IS NOT NULL AND t.customer_public_id <> ''"
        );
    }

    if (app_db_column_exists($db, 'portal_tickets', 'assigned_technician_id') && app_db_column_exists($db, 'portal_tickets', 'assigned_technician_account_id')) {
        $db->exec(
            "UPDATE portal_tickets
             SET assigned_technician_account_id = COALESCE(assigned_technician_account_id, assigned_technician_id)
             WHERE assigned_technician_account_id IS NULL AND assigned_technician_id IS NOT NULL"
        );
    }

    $radacctColumns = [
        'framedipv6address' => "ALTER TABLE radacct ADD COLUMN framedipv6address VARCHAR(45) NOT NULL DEFAULT '' AFTER framedipaddress",
        'framedipv6prefix' => "ALTER TABLE radacct ADD COLUMN framedipv6prefix VARCHAR(45) NOT NULL DEFAULT '' AFTER framedipv6address",
        'framedinterfaceid' => "ALTER TABLE radacct ADD COLUMN framedinterfaceid VARCHAR(44) NOT NULL DEFAULT '' AFTER framedipv6prefix",
        'delegatedipv6prefix' => "ALTER TABLE radacct ADD COLUMN delegatedipv6prefix VARCHAR(45) NOT NULL DEFAULT '' AFTER framedinterfaceid",
        'class' => "ALTER TABLE radacct ADD COLUMN class VARCHAR(64) DEFAULT NULL AFTER delegatedipv6prefix",
    ];
    foreach ($radacctColumns as $column => $sql) {
        if (!app_db_column_exists($db, 'radacct', $column)) {
            $db->exec($sql);
        }
    }
    if (!app_db_index_exists($db, 'radacct', 'idx_radacct_stop_user')) {
        $db->exec('CREATE INDEX idx_radacct_stop_user ON radacct (acctstoptime, username)');
    }

    if (!app_db_column_exists($db, 'radpostauth', 'class')) {
        $db->exec("ALTER TABLE radpostauth ADD COLUMN class VARCHAR(64) DEFAULT NULL AFTER authdate");
    }

    $billingTariffColumns = [
        'external_id' => "ALTER TABLE billing_tariffs ADD COLUMN external_id VARCHAR(64) DEFAULT NULL AFTER id",
        'metadata_json' => "ALTER TABLE billing_tariffs ADD COLUMN metadata_json LONGTEXT DEFAULT NULL AFTER status",
    ];
    foreach ($billingTariffColumns as $column => $sql) {
        if (!app_db_column_exists($db, 'billing_tariffs', $column)) {
            $db->exec($sql);
        }
    }
    if (!app_db_index_exists($db, 'billing_tariffs', 'uq_billing_tariffs_external')) {
        $db->exec('CREATE UNIQUE INDEX uq_billing_tariffs_external ON billing_tariffs (external_id)');
    }

    $billingPaymentColumns = [
        'external_id' => "ALTER TABLE billing_payments ADD COLUMN external_id VARCHAR(64) DEFAULT NULL AFTER id",
        'metadata_json' => "ALTER TABLE billing_payments ADD COLUMN metadata_json LONGTEXT DEFAULT NULL AFTER note",
    ];
    foreach ($billingPaymentColumns as $column => $sql) {
        if (!app_db_column_exists($db, 'billing_payments', $column)) {
            $db->exec($sql);
        }
    }
    if (!app_db_index_exists($db, 'billing_payments', 'uq_billing_payments_external')) {
        $db->exec('CREATE UNIQUE INDEX uq_billing_payments_external ON billing_payments (external_id)');
    }
    if (!app_db_index_exists($db, 'billing_payments', 'idx_billing_payments_transaction')) {
        $db->exec('CREATE INDEX idx_billing_payments_transaction ON billing_payments (transaction_id)');
    }
    if (!app_db_index_exists($db, 'billing_payments', 'idx_billing_payments_created')) {
        $db->exec('CREATE INDEX idx_billing_payments_created ON billing_payments (created_at)');
    }
    if (!app_db_column_exists($db, 'billing_radius_services', 'nat_public_ip')) {
        $db->exec('ALTER TABLE billing_radius_services ADD COLUMN nat_public_ip VARCHAR(45) DEFAULT NULL AFTER assigned_ip');
    }
    if (!app_db_index_exists($db, 'portal_accounts', 'idx_portal_accounts_contract')) {
        $db->exec('CREATE INDEX idx_portal_accounts_contract ON portal_accounts (contract_no)');
    }
    if (!app_db_index_exists($db, 'portal_accounts', 'idx_portal_accounts_phone')) {
        $db->exec('CREATE INDEX idx_portal_accounts_phone ON portal_accounts (phone)');
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS company_branches (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            branch_name VARCHAR(128) NOT NULL,
            branch_key VARCHAR(160) NOT NULL,
            area_name VARCHAR(128) DEFAULT NULL,
            address VARCHAR(255) DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            note VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_company_branches_key (branch_key),
            KEY idx_company_branches_status (status),
            KEY idx_company_branches_area (area_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $operatorConcurrencyIndexes = [
        ['portal_accounts', 'idx_portal_accounts_role_active_status', 'CREATE INDEX idx_portal_accounts_role_active_status ON portal_accounts (role, is_active, status)'],
        ['portal_accounts', 'idx_portal_accounts_role_area', 'CREATE INDEX idx_portal_accounts_role_area ON portal_accounts (role, area)'],
        ['billing_radius_services', 'idx_billing_radius_services_account_status', 'CREATE INDEX idx_billing_radius_services_account_status ON billing_radius_services (account_id, status, expires_at)'],
        ['billing_radius_services', 'idx_billing_radius_services_ip_lookup', 'CREATE INDEX idx_billing_radius_services_ip_lookup ON billing_radius_services (assigned_ip, static_ip, nat_public_ip)'],
        ['billing_payments', 'idx_billing_payments_subscriber_created', 'CREATE INDEX idx_billing_payments_subscriber_created ON billing_payments (subscriber_code, created_at)'],
        ['billing_payments', 'idx_billing_payments_method_created', 'CREATE INDEX idx_billing_payments_method_created ON billing_payments (payment_method, created_at)'],
        ['portal_tickets', 'idx_portal_tickets_customer_status', 'CREATE INDEX idx_portal_tickets_customer_status ON portal_tickets (customer_account_id, status, opened_at)'],
        ['portal_tickets', 'idx_portal_tickets_subscriber_status', 'CREATE INDEX idx_portal_tickets_subscriber_status ON portal_tickets (subscriber_code, status, opened_at)'],
    ];
    foreach ($operatorConcurrencyIndexes as [$table, $index, $sql]) {
        if (!app_db_index_exists($db, (string) $table, (string) $index)) {
            $db->exec((string) $sql);
        }
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS nasreload (
            nasipaddress VARCHAR(45) NOT NULL,
            reloadtime DATETIME NOT NULL,
            PRIMARY KEY (nasipaddress)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS radius_session_actions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            action_type ENUM('disconnect_request') NOT NULL DEFAULT 'disconnect_request',
            status ENUM('pending','approved','rejected','executed','cancelled') NOT NULL DEFAULT 'pending',
            account_id BIGINT UNSIGNED DEFAULT NULL,
            username VARCHAR(191) NOT NULL,
            framed_ip VARCHAR(45) DEFAULT NULL,
            nas_ip VARCHAR(45) DEFAULT NULL,
            nas_port_id VARCHAR(191) DEFAULT NULL,
            calling_station_id VARCHAR(191) DEFAULT NULL,
            reason TEXT DEFAULT NULL,
            requested_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            approved_by_account_id BIGINT UNSIGNED DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            result_json LONGTEXT DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_radius_session_actions_status (status, requested_at),
            KEY idx_radius_session_actions_username (username, status),
            KEY idx_radius_session_actions_account (account_id),
            CONSTRAINT fk_radius_session_actions_account FOREIGN KEY (account_id) REFERENCES portal_accounts(id) ON DELETE SET NULL,
            CONSTRAINT fk_radius_session_actions_requested_by FOREIGN KEY (requested_by_account_id) REFERENCES portal_accounts(id) ON DELETE SET NULL,
            CONSTRAINT fk_radius_session_actions_approved_by FOREIGN KEY (approved_by_account_id) REFERENCES portal_accounts(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS communication_delivery_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            message_id BIGINT UNSIGNED DEFAULT NULL,
            account_id BIGINT UNSIGNED DEFAULT NULL,
            subscriber_code VARCHAR(64) DEFAULT NULL,
            channel VARCHAR(32) NOT NULL,
            delivery VARCHAR(64) NOT NULL DEFAULT '',
            destination_masked VARCHAR(64) NOT NULL DEFAULT '',
            status ENUM('sent','failed','skipped') NOT NULL DEFAULT 'failed',
            provider_message_id VARCHAR(191) DEFAULT NULL,
            reason VARCHAR(191) DEFAULT NULL,
            response_summary_json LONGTEXT DEFAULT NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_communication_attempts_message (message_id, attempted_at),
            KEY idx_communication_attempts_status (status, attempted_at),
            KEY idx_communication_attempts_channel (channel, attempted_at),
            KEY idx_communication_attempts_account (account_id, attempted_at),
            CONSTRAINT fk_communication_attempts_message
                FOREIGN KEY (message_id) REFERENCES communication_messages(id)
                ON DELETE SET NULL,
            CONSTRAINT fk_communication_attempts_account
                FOREIGN KEY (account_id) REFERENCES portal_accounts(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $db->exec("ALTER TABLE communication_delivery_attempts MODIFY COLUMN status ENUM('sent','failed','skipped') NOT NULL DEFAULT 'failed'");
    if (!app_db_index_exists($db, 'communication_delivery_attempts', 'idx_communication_attempts_channel')) {
        $db->exec('CREATE INDEX idx_communication_attempts_channel ON communication_delivery_attempts (channel, attempted_at)');
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS communication_worker_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            mode VARCHAR(32) NOT NULL DEFAULT 'apply',
            channel VARCHAR(32) NOT NULL DEFAULT 'all',
            status ENUM('completed','failed') NOT NULL DEFAULT 'completed',
            limit_count INT UNSIGNED NOT NULL DEFAULT 0,
            scanned_count INT UNSIGNED NOT NULL DEFAULT 0,
            would_send_count INT UNSIGNED NOT NULL DEFAULT 0,
            sent_count INT UNSIGNED NOT NULL DEFAULT 0,
            failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            left_queued_count INT UNSIGNED NOT NULL DEFAULT 0,
            unconfigured_count INT UNSIGNED NOT NULL DEFAULT 0,
            fail_unconfigured TINYINT(1) NOT NULL DEFAULT 0,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            by_channel_json LONGTEXT DEFAULT NULL,
            summary_json LONGTEXT DEFAULT NULL,
            error_summary VARCHAR(191) DEFAULT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_comm_worker_finished (finished_at),
            KEY idx_comm_worker_status (status, finished_at),
            KEY idx_comm_worker_channel (channel, finished_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS billing_maintenance_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            mode VARCHAR(32) NOT NULL DEFAULT 'dry-run',
            status ENUM('completed','failed') NOT NULL DEFAULT 'completed',
            apply_changes TINYINT(1) NOT NULL DEFAULT 0,
            sync_radius TINYINT(1) NOT NULL DEFAULT 0,
            queue_notices TINYINT(1) NOT NULL DEFAULT 0,
            channel VARCHAR(32) NOT NULL DEFAULT 'auto',
            limit_count INT UNSIGNED NOT NULL DEFAULT 0,
            notice_limit_count INT UNSIGNED NOT NULL DEFAULT 0,
            countdown_candidates_count INT UNSIGNED NOT NULL DEFAULT 0,
            expired_candidates_count INT UNSIGNED NOT NULL DEFAULT 0,
            notice_expiring_count INT UNSIGNED NOT NULL DEFAULT 0,
            notice_expired_count INT UNSIGNED NOT NULL DEFAULT 0,
            countdown_checked_count INT UNSIGNED NOT NULL DEFAULT 0,
            countdown_updated_count INT UNSIGNED NOT NULL DEFAULT 0,
            countdown_expired_count INT UNSIGNED NOT NULL DEFAULT 0,
            suspended_count INT UNSIGNED NOT NULL DEFAULT 0,
            radius_synced_count INT UNSIGNED NOT NULL DEFAULT 0,
            notices_queued_count INT UNSIGNED NOT NULL DEFAULT 0,
            notices_duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
            notices_failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            summary_json LONGTEXT DEFAULT NULL,
            error_summary VARCHAR(191) DEFAULT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_billing_maintenance_finished (finished_at),
            KEY idx_billing_maintenance_status (status, finished_at),
            KEY idx_billing_maintenance_mode (mode, finished_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function app_db_seed_if_empty(PDO $db): void
{
    $count = (int) $db->query('SELECT COUNT(*) FROM portal_accounts')->fetchColumn();
    if ($count === 0) {
        app_db_seed_accounts_from_mock($db);
    }

    app_db_ensure_default_admin($db);
    app_db_seed_branches($db);
    app_db_seed_area_code_prefixes($db);

    $ticketCount = (int) $db->query('SELECT COUNT(*) FROM portal_tickets')->fetchColumn();
    if ($ticketCount === 0) {
        app_db_seed_tickets($db);
    }
}

function app_db_seed_accounts_from_mock(PDO $db): void
{
    $data = app_mock_portal_data();

    foreach (($data['customers'] ?? []) as $login => $record) {
        if (!is_array($record)) {
            continue;
        }

        $dashboard = is_array($record['dashboard'] ?? null) ? $record['dashboard'] : [];
        $profile = is_array($dashboard['profile'] ?? null) ? $dashboard['profile'] : [];
        app_db_upsert_account([
            'external_id' => (string) ($record['id'] ?? ''),
            'login' => (string) $login,
            'password' => (string) ($record['password'] ?? 'Demo1234'),
            'role' => 'customer',
            'account_type' => 'customer',
            'employee_role' => '',
            'full_name' => (string) ($profile['full_name'] ?? $record['name'] ?? $login),
            'subscriber_code' => (string) ($record['subscriber_code'] ?? ''),
            'contract_no' => (string) ($profile['contract_no'] ?? ''),
            'email' => (string) ($profile['email'] ?? ''),
            'phone' => (string) ($profile['phone'] ?? ''),
            'category' => (string) ($profile['category'] ?? $record['category'] ?? ''),
            'area' => (string) ($profile['area'] ?? ''),
            'address' => (string) ($profile['address'] ?? ''),
            'plan' => (string) ($profile['plan'] ?? ''),
            'status' => (string) ($profile['status'] ?? 'Aktiv'),
            'payload' => $dashboard,
            'responsible_areas' => [],
            'is_active' => 1,
        ], false);
    }

    foreach (($data['employees'] ?? []) as $login => $record) {
        if (!is_array($record)) {
            continue;
        }

        $dashboard = is_array($record['dashboard'] ?? null) ? $record['dashboard'] : [];
        $employeeRole = (string) ($record['employee_role'] ?? 'support');
        $accountType = match ($employeeRole) {
            'noc', 'admin' => 'admin',
            'technician' => 'technician',
            default => 'operator',
        };

        app_db_upsert_account([
            'external_id' => (string) ($record['id'] ?? ''),
            'login' => (string) $login,
            'password' => (string) ($record['password'] ?? 'Support123'),
            'role' => 'employee',
            'account_type' => $accountType,
            'employee_role' => $employeeRole,
            'full_name' => (string) ($record['name'] ?? $login),
            'subscriber_code' => '',
            'contract_no' => '',
            'email' => filter_var($login, FILTER_VALIDATE_EMAIL) ? (string) $login : '',
            'phone' => '',
            'category' => '',
            'area' => '',
            'address' => '',
            'plan' => '',
            'status' => 'Aktiv',
            'payload' => $dashboard,
            'responsible_areas' => [],
            'is_active' => 1,
        ], false);
    }

    foreach (($data['technicians'] ?? []) as $technician) {
        if (!is_array($technician)) {
            continue;
        }

        $name = (string) ($technician['name'] ?? '');
        if ($name === '') {
            continue;
        }

        $login = app_db_slug_login($name, '@superoptic.net');
        $payload = [
            'summary' => [
                'role_title' => 'Saha texniki',
                'billing_access' => 'Aktiv',
                'shift' => 'Sahada',
                'open_queue' => '0 aciq muraciet',
            ],
            'permissions' => [
                'Erzi uzre bilet qebulu',
                'Bilet baglama ve qeyd aparma',
                'ONU/OLT baxis linkleri',
            ],
        ];

        app_db_upsert_account([
            'external_id' => (string) ($technician['id'] ?? ''),
            'login' => $login,
            'password' => 'Tech12345!',
            'role' => 'employee',
            'account_type' => 'technician',
            'employee_role' => 'technician',
            'full_name' => $name,
            'subscriber_code' => '',
            'contract_no' => '',
            'email' => $login,
            'phone' => '',
            'category' => '',
            'area' => is_array($technician['areas'] ?? null) ? (string) (($technician['areas'][0] ?? '')) : '',
            'address' => '',
            'plan' => '',
            'status' => 'Aktiv',
            'payload' => $payload,
            'responsible_areas' => array_values(array_filter((array) ($technician['areas'] ?? []), 'is_string')),
            'is_active' => 1,
        ], false);
    }
}

function app_db_ensure_default_admin(PDO $db): void
{
    $existing = app_db_account_by_login('s.abdullayev@superoptic.net');
    $payload = [
        'summary' => [
            'role_title' => 'Portal administratoru',
            'billing_access' => 'Aktiv',
            'shift' => 'Idareetme',
            'open_queue' => 'Butun sistem',
        ],
        'permissions' => [
            'Customer, operator, technician ve admin hesablarini idare etmek',
            'CRM, statistika ve import proseslerini idare etmek',
            'OLT rolu ve sync prosesine nezaret etmek',
        ],
    ];
    if ($existing !== null) {
        $existingPayload = app_db_account_payload($existing);
        if (is_array($existingPayload)) {
            $payload = array_replace_recursive($payload, $existingPayload);
        }
        $statement = $db->prepare(
            "UPDATE portal_accounts
                SET role = 'employee',
                    account_type = 'admin',
                    employee_role = 'super_admin',
                    full_name = CASE WHEN full_name IS NULL OR full_name = '' THEN 'Samir Abdullayev' ELSE full_name END,
                    email = CASE WHEN email IS NULL OR email = '' THEN 's.abdullayev@superoptic.net' ELSE email END,
                    status = 'Aktiv',
                    is_active = 1,
                    payload_json = :payload_json
              WHERE id = :id"
        );
        $statement->execute([
            'id' => (int) $existing['id'],
            'payload_json' => app_db_json_encode($payload),
        ]);
        app_db_replace_account_areas((int) $existing['id'], app_service_areas());
        return;
    }

    app_db_upsert_account([
        'external_id' => 'emp-admin-001',
        'login' => 's.abdullayev@superoptic.net',
        'password' => '!Asan!1312',
        'role' => 'employee',
        'account_type' => 'admin',
        'employee_role' => 'super_admin',
        'full_name' => 'Samir Abdullayev',
        'subscriber_code' => '',
        'contract_no' => '',
        'email' => 's.abdullayev@superoptic.net',
        'phone' => '',
        'category' => '',
        'area' => 'Baki',
        'address' => '',
        'plan' => '',
        'status' => 'Aktiv',
        'payload' => $payload,
        'responsible_areas' => app_service_areas(),
        'is_active' => 1,
    ], false);
}

function app_db_seed_tickets(PDO $db): void
{
    $legacyPath = __DIR__ . '/data/portal-tickets.json';
    if (file_exists($legacyPath)) {
        $records = json_decode((string) file_get_contents($legacyPath), true);
        if (is_array($records) && $records !== []) {
            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }
                app_db_insert_ticket_from_legacy($record);
            }
            return;
        }
    }

    $data = app_mock_portal_data();
    foreach (($data['customers'] ?? []) as $customerLogin => $customer) {
        if (!is_array($customer)) {
            continue;
        }

        $account = app_db_account_by_login((string) $customerLogin);
        $profile = is_array($customer['dashboard']['profile'] ?? null) ? $customer['dashboard']['profile'] : [];
        foreach (($customer['dashboard']['tickets'] ?? []) as $ticket) {
            if (!is_array($ticket)) {
                continue;
            }

            $area = (string) ($profile['area'] ?? app_portal_resolve_area_from_text((string) ($profile['address'] ?? '')));
            $technician = app_portal_assign_technician($area);
            $status = (string) ($ticket['status'] ?? 'Aciqdir');
            $resolvedAt = in_array($status, ['Baglandi', 'Bağlandı', 'Closed'], true)
                ? app_db_parse_datetime((string) ($ticket['updated_at'] ?? ''))
                : null;

            app_db_insert_ticket([
                'ticket_no' => (string) ($ticket['id'] ?? app_db_next_ticket_no()),
                'customer_account_id' => (int) ($account['id'] ?? 0),
                'subscriber_code' => (string) ($customer['subscriber_code'] ?? ''),
                'contract_no' => (string) ($profile['contract_no'] ?? ''),
                'customer_name' => (string) ($profile['full_name'] ?? $customer['name'] ?? ''),
                'phone' => (string) ($profile['phone'] ?? ''),
                'address' => (string) ($profile['address'] ?? ''),
                'area' => $area,
                'subject' => (string) ($ticket['subject'] ?? 'Texniki destek'),
                'message' => mb_substr((string) ($ticket['message'] ?? ''), 0, 160),
                'status' => $status,
                'opened_at' => app_db_parse_datetime((string) ($ticket['updated_at'] ?? '')) ?? app_db_now(),
                'updated_at' => app_db_parse_datetime((string) ($ticket['updated_at'] ?? '')) ?? app_db_now(),
                'resolved_at' => $resolvedAt,
                'assigned_technician_account_id' => (int) ($technician['id'] ?? 0),
                'assigned_technician_name' => (string) ($technician['name'] ?? ''),
                'resolution_reason' => $resolvedAt !== null ? 'Qeydli tiket baglanib' : '',
                'resolution_note' => $resolvedAt !== null ? (string) ($ticket['message'] ?? '') : '',
                'handled_by' => $resolvedAt !== null ? (string) ($technician['name'] ?? '') : '',
                'created_by_account_id' => 0,
                'created_by_role' => 'seed',
                'created_by_name' => 'System',
                'telegram_message_ids_json' => '[]',
                'metadata_json' => null,
            ]);
        }
    }
}

function app_db_insert_ticket_from_legacy(array $record): void
{
    $accountId = 0;
    if ((string) ($record['subscriber_code'] ?? '') !== '') {
        $account = app_db_account_by_subscriber_code((string) $record['subscriber_code']);
        $accountId = (int) ($account['id'] ?? 0);
    }

    $technician = null;
    if ((string) ($record['assigned_technician_name'] ?? '') !== '') {
        $technician = app_db_account_by_name_and_type((string) $record['assigned_technician_name'], 'technician');
    }

    app_db_insert_ticket([
        'ticket_no' => (string) ($record['id'] ?? app_db_next_ticket_no()),
        'customer_account_id' => $accountId,
        'subscriber_code' => (string) ($record['subscriber_code'] ?? ''),
        'contract_no' => (string) ($record['contract_no'] ?? ''),
        'customer_name' => (string) ($record['customer_name'] ?? ''),
        'phone' => (string) ($record['phone'] ?? ''),
        'address' => (string) ($record['address'] ?? ''),
        'area' => (string) ($record['area'] ?? ''),
        'subject' => (string) ($record['subject'] ?? 'Texniki destek'),
        'message' => mb_substr((string) ($record['message'] ?? ''), 0, 160),
        'status' => (string) ($record['status'] ?? 'Aciqdir'),
        'opened_at' => app_db_parse_datetime((string) ($record['opened_at'] ?? '')) ?? app_db_now(),
        'updated_at' => app_db_parse_datetime((string) ($record['updated_at'] ?? '')) ?? app_db_now(),
        'resolved_at' => app_db_parse_datetime((string) ($record['resolved_at'] ?? '')),
        'assigned_technician_account_id' => (int) ($technician['id'] ?? 0),
        'assigned_technician_name' => (string) ($record['assigned_technician_name'] ?? ''),
        'resolution_reason' => (string) ($record['resolution_reason'] ?? ''),
        'resolution_note' => (string) ($record['resolution_note'] ?? ''),
        'handled_by' => (string) ($record['handled_by'] ?? ''),
        'created_by_account_id' => 0,
        'created_by_role' => (string) ($record['created_by_role'] ?? 'legacy'),
        'created_by_name' => (string) ($record['created_by_name'] ?? 'Legacy'),
        'telegram_message_ids_json' => app_db_json_encode((array) ($record['telegram_message_ids'] ?? [])),
        'metadata_json' => null,
    ]);
}

function app_db_now(): string
{
    return date('Y-m-d H:i:s');
}

function app_db_parse_datetime(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $formats = [
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'd.m.Y H:i:s',
        'd.m.Y H:i',
        'd.m.Y',
        DateTimeInterface::ATOM,
    ];

    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $value);
        if ($date instanceof DateTime) {
            return $date->format('Y-m-d H:i:s');
        }
    }

    $timestamp = strtotime($value);
    return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
}

function app_db_display_datetime(?string $value): string
{
    if (!is_string($value) || $value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('d.m.Y H:i', $timestamp);
}

function app_db_json_encode(mixed $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : '[]';
}

function app_db_json_decode(?string $value, array $fallback = []): array
{
    if (!is_string($value) || trim($value) === '') {
        return $fallback;
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function app_db_slug_login(string $value, string $suffix = ''): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $ascii = is_string($ascii) ? $ascii : $value;
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '.', $ascii) ?? '', '.'));
    $slug = $slug !== '' ? $slug : 'user.' . bin2hex(random_bytes(3));
    return $slug . $suffix;
}

function app_db_account_by_login(string $login): ?array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return null;
    }

    $statement = $db->prepare('SELECT * FROM portal_accounts WHERE login = :login LIMIT 1');
    $statement->execute(['login' => $login]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

function app_db_account_by_id(int $id): ?array
{
    $db = app_db();
    if (!$db instanceof PDO || $id <= 0) {
        return null;
    }

    $statement = $db->prepare('SELECT * FROM portal_accounts WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

function app_db_account_by_subscriber_code(string $subscriberCode): ?array
{
    $db = app_db();
    if (!$db instanceof PDO || $subscriberCode === '') {
        return null;
    }

    $statement = $db->prepare('SELECT * FROM portal_accounts WHERE subscriber_code = :subscriber_code LIMIT 1');
    $statement->execute(['subscriber_code' => $subscriberCode]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

function app_db_area_code_key(string $area): string
{
    $value = mb_strtolower(trim($area));
    $replace = [
        'ə' => 'e',
        'ı' => 'i',
        'ğ' => 'g',
        'ü' => 'u',
        'ş' => 's',
        'ö' => 'o',
        'ç' => 'c',
        'Ə' => 'e',
        'I' => 'i',
        'İ' => 'i',
        'Ğ' => 'g',
        'Ü' => 'u',
        'Ş' => 's',
        'Ö' => 'o',
        'Ç' => 'c',
        'É™' => 'e',
        'ı' => 'i',
        'İ' => 'i',
        'ÅŸ' => 's',
        'Åž' => 's',
        'ç' => 'c',
        'ü' => 'u',
        'ö' => 'o',
        'ÄŸ' => 'g',
        'Ə' => 'e',
        'Æ' => 'e',
    ];
    $value = strtr($value, $replace);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $ascii = is_string($ascii) ? $ascii : $value;
    $key = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $ascii));

    return $key;
}

function app_db_upsert_branch(PDO $db, string $branchName, string $areaName = '', string $address = '', string $note = '', string $status = 'active'): bool
{
    $branchName = trim($branchName);
    $branchKey = app_db_area_code_key($branchName);
    if ($branchName === '' || $branchKey === '') {
        return false;
    }

    $status = in_array($status, ['active', 'disabled'], true) ? $status : 'active';
    $statement = $db->prepare(
        'INSERT INTO company_branches (branch_name, branch_key, area_name, address, status, note)
         VALUES (:branch_name, :branch_key, :area_name, :address, :status, :note)
         ON DUPLICATE KEY UPDATE
            branch_name = VALUES(branch_name),
            area_name = VALUES(area_name),
            address = VALUES(address),
            status = VALUES(status),
            note = VALUES(note)'
    );

    return $statement->execute([
        'branch_name' => $branchName,
        'branch_key' => $branchKey,
        'area_name' => trim($areaName) ?: null,
        'address' => trim($address) ?: null,
        'status' => $status,
        'note' => trim($note) ?: null,
    ]);
}

function app_db_default_branches(): array
{
    return [
        ['name' => 'Baki', 'area' => 'Baki', 'note' => 'Baki operator, texnik ve NOC merkezi'],
        ['name' => 'Astara', 'area' => 'Astara', 'note' => 'Astara filial xidmeti'],
        ['name' => 'Lenkeran', 'area' => 'Lenkeran', 'note' => 'Lenkeran xidmet zonasi'],
        ['name' => 'Masalli', 'area' => 'Masalli', 'note' => 'Masalli xidmet zonasi'],
        ['name' => 'Neftcala', 'area' => 'Neftcala', 'note' => 'Neftcala xidmet zonasi'],
    ];
}

function app_db_seed_branches(?PDO $db = null): void
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO) {
        return;
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS company_branches (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            branch_name VARCHAR(128) NOT NULL,
            branch_key VARCHAR(160) NOT NULL,
            area_name VARCHAR(128) DEFAULT NULL,
            address VARCHAR(255) DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            note VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_company_branches_key (branch_key),
            KEY idx_company_branches_status (status),
            KEY idx_company_branches_area (area_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    foreach (app_db_default_branches() as $branch) {
        app_db_upsert_branch(
            $db,
            (string) ($branch['name'] ?? ''),
            (string) ($branch['area'] ?? ''),
            (string) ($branch['address'] ?? ''),
            (string) ($branch['note'] ?? ''),
            'active'
        );
    }
}

function app_db_branches(?PDO $db = null, bool $activeOnly = true): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO) {
        return [];
    }

    app_db_seed_branches($db);
    $sql = 'SELECT id, branch_name, area_name, address, status, note, updated_at FROM company_branches';
    if ($activeOnly) {
        $sql .= ' WHERE status = "active"';
    }
    $sql .= ' ORDER BY branch_name';

    try {
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }

    return array_map(static function (array $row): array {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['branch_name'] ?? ''),
            'branchName' => (string) ($row['branch_name'] ?? ''),
            'area' => (string) ($row['area_name'] ?? ''),
            'areaName' => (string) ($row['area_name'] ?? ''),
            'address' => (string) ($row['address'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'note' => (string) ($row['note'] ?? ''),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
        ];
    }, $rows);
}

function app_db_default_area_code_prefixes(): array
{
    return [
        'Qaracuxur' => '100',
        'Qaraçuxur' => '100',
        'Qaraçuxur' => '100',
        'Astara' => '300',
        'Sahagaci' => '200',
        'Şahağacı' => '200',
        'Şahağacı' => '200',
        'Bileceri' => '110',
        'Biləcəri' => '110',
        'Biləcəri' => '110',
        'Kurdexani' => '232',
        'Kürdəxanı' => '232',
        'Kürdəxanı' => '232',
        'Zabrat' => '232',
        'Lenkeran' => '500',
        'Lənkəran' => '500',
        'Lənkəran' => '500',
        'Masalli' => '400',
        'Masallı' => '400',
        'Masallı' => '400',
    ];
}

function app_db_upsert_area_code_prefix(PDO $db, string $areaName, string $codePrefix, string $note = ''): bool
{
    $areaName = trim($areaName);
    $codePrefix = preg_replace('/\D+/', '', $codePrefix) ?? '';
    $areaKey = app_db_area_code_key($areaName);
    if ($areaName === '' || $areaKey === '' || $codePrefix === '') {
        return false;
    }

    $statement = $db->prepare(
        'INSERT INTO customer_area_code_prefixes (area_name, area_key, code_prefix, note, is_active)
         VALUES (:area_name, :area_key, :code_prefix, :note, 1)
         ON DUPLICATE KEY UPDATE
            area_name = VALUES(area_name),
            code_prefix = VALUES(code_prefix),
            note = VALUES(note),
            is_active = 1'
    );

    return $statement->execute([
        'area_name' => $areaName,
        'area_key' => $areaKey,
        'code_prefix' => $codePrefix,
        'note' => $note,
    ]);
}

function app_db_seed_area_code_prefixes(?PDO $db = null): void
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO) {
        return;
    }

    $canonicalPrefixes = [
        'Qaracuxur' => '100',
        'Qaraçuxur' => '100',
        'Astara' => '300',
        'Sahagaci' => '200',
        'Şahağacı' => '200',
        'Bileceri' => '110',
        'Biləcəri' => '110',
        'Kurdexani' => '232',
        'Kürdəxanı' => '232',
        'Zabrat' => '232',
        'Lenkeran' => '500',
        'Lənkəran' => '500',
        'Masalli' => '400',
        'Masallı' => '400',
        'Neftcala' => '600',
        'Baki' => '700',
    ];

    foreach ($canonicalPrefixes as $areaName => $prefix) {
        app_db_upsert_area_code_prefix($db, (string) $areaName, (string) $prefix, 'Default area customer code prefix');
    }
}

function app_db_area_code_prefix(PDO $db, string $areaName): string
{
    $key = app_db_area_code_key($areaName);
    if ($key === '') {
        return '';
    }

    $statement = $db->prepare(
        'SELECT code_prefix
           FROM customer_area_code_prefixes
          WHERE area_key = :area_key
            AND is_active = 1
          LIMIT 1'
    );
    $statement->execute(['area_key' => $key]);
    $prefix = trim((string) ($statement->fetchColumn() ?: ''));
    if ($prefix !== '') {
        return $prefix;
    }

    foreach (app_db_default_area_code_prefixes() as $area => $candidatePrefix) {
        if (app_db_area_code_key((string) $area) === $key) {
            return (string) $candidatePrefix;
        }
    }

    return '';
}

function app_db_next_customer_code_for_area(PDO $db, string $areaName, bool $seedPrefixes = true, ?string $knownPrefix = null): string
{
    if ($seedPrefixes) {
        app_db_seed_area_code_prefixes($db);
    }
    $prefix = $knownPrefix !== null ? trim($knownPrefix) : app_db_area_code_prefix($db, $areaName);
    if ($prefix === '') {
        return '';
    }

    $base = (int) ($prefix . str_repeat('0', max(1, 7 - strlen($prefix))));
    $end = (int) ($prefix . str_repeat('9', max(1, 7 - strlen($prefix))));
    $statement = $db->prepare(
        'SELECT subscriber_code
           FROM portal_accounts
          WHERE role = "customer"
            AND subscriber_code >= :base_code
            AND subscriber_code <= :end_code
          ORDER BY subscriber_code DESC
          LIMIT 1'
    );
    $statement->execute([
        'base_code' => (string) $base,
        'end_code' => (string) $end,
    ]);
    $current = (int) preg_replace('/\D+/', '', (string) ($statement->fetchColumn() ?: '0'));
    $next = max($base, $current + 1);
    if ($next > $end) {
        return '';
    }

    return (string) $next;
}

function app_db_resolve_customer_code_for_area(PDO $db, string $areaName, string $postedCode = '', bool $autoMode = false): string
{
    $postedCode = trim($postedCode);
    if ($postedCode === '') {
        return app_db_next_customer_code_for_area($db, $areaName);
    }

    if (!$autoMode) {
        return $postedCode;
    }

    $existing = app_db_account_by_subscriber_code($postedCode);
    if (!is_array($existing)) {
        return $postedCode;
    }

    return app_db_next_customer_code_for_area($db, $areaName);
}

function app_db_area_code_options(PDO $db, array $areas): array
{
    app_db_seed_area_code_prefixes($db);
    $options = [];
    foreach ($areas as $area) {
        $areaName = trim((string) $area);
        if ($areaName === '') {
            continue;
        }
        $prefix = app_db_area_code_prefix($db, $areaName);
        $options[$areaName] = [
            'prefix' => $prefix,
            'next_code' => app_db_next_customer_code_for_area($db, $areaName, false, $prefix),
        ];
    }

    return $options;
}

function app_db_account_by_name_and_type(string $name, string $accountType): ?array
{
    $db = app_db();
    if (!$db instanceof PDO || $name === '') {
        return null;
    }

    $statement = $db->prepare('SELECT * FROM portal_accounts WHERE full_name = :full_name AND account_type = :account_type LIMIT 1');
    $statement->execute([
        'full_name' => $name,
        'account_type' => $accountType,
    ]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

function app_db_account_areas(int $accountId): array
{
    $db = app_db();
    if (!$db instanceof PDO || $accountId <= 0) {
        return [];
    }

    $statement = $db->prepare('SELECT area FROM portal_account_areas WHERE account_id = :account_id ORDER BY area');
    $statement->execute(['account_id' => $accountId]);
    return array_values(array_filter(array_map(
        static fn ($row): string => is_array($row) ? (string) ($row['area'] ?? '') : '',
        $statement->fetchAll()
    )));
}

function app_db_account_payload(array $account): array
{
    return app_db_json_decode((string) ($account['payload_json'] ?? ''), []);
}

function app_db_user_session_payload(array $account): array
{
    return [
        'id' => (string) ($account['id'] ?? ''),
        'name' => (string) ($account['full_name'] ?? ''),
        'role' => (string) ($account['role'] ?? 'customer'),
        'account_type' => (string) ($account['account_type'] ?? 'customer'),
        'subscriber_code' => (string) ($account['subscriber_code'] ?? ''),
        'category' => (string) ($account['category'] ?? ''),
        'employee_role' => (string) ($account['employee_role'] ?? ''),
        'token' => 'db-session',
        'login' => (string) ($account['login'] ?? ''),
        'email' => (string) ($account['email'] ?? ''),
    ];
}

function app_db_session_user_from_legacy(array $user): ?array
{
    $account = null;

    $id = (int) ($user['id'] ?? 0);
    if ($id > 0) {
        $account = app_db_account_by_id($id);
    }

    if ($account === null) {
        $login = trim((string) ($user['login'] ?? ''));
        if ($login !== '') {
            $account = app_db_account_by_login($login);
        }
    }

    if ($account === null) {
        $subscriberCode = trim((string) ($user['subscriber_code'] ?? ''));
        if ($subscriberCode !== '') {
            $account = app_db_account_by_subscriber_code($subscriberCode);
        }
    }

    return is_array($account) ? app_db_user_session_payload($account) : null;
}

function app_db_login(string $role, string $login, string $password): ?array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return null;
    }

    $login = trim($login);
    if ($login === '' || $password === '') {
        return null;
    }

    $statement = $db->prepare(
        'SELECT * FROM portal_accounts
         WHERE role = :role AND is_active = 1 AND (login = :login OR subscriber_code = :login OR email = :login)
         LIMIT 1'
    );
    $statement->execute([
        'role' => $role,
        'login' => $login,
    ]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return null;
    }

    if (!password_verify($password, (string) ($row['password_hash'] ?? ''))) {
        return null;
    }

    return app_db_user_session_payload($row);
}

function app_db_customer_snapshot(array $user): array
{
    $account = app_db_account_by_id((int) ($user['id'] ?? 0));
    if ($account === null) {
        $account = app_db_session_user_from_legacy($user);
        if (is_array($account) && isset($account['id'])) {
            $account = app_db_account_by_id((int) $account['id']);
        }
    }
    if ($account === null) {
        return app_mock_customer_snapshot_data($user);
    }

    $payload = app_db_account_payload($account);
    $profile = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];
    $profile['full_name'] = (string) ($account['full_name'] ?? ($profile['full_name'] ?? ''));
    $profile['category'] = (string) ($account['category'] ?? ($profile['category'] ?? ''));
    $profile['subscriber_code'] = (string) ($account['subscriber_code'] ?? ($profile['subscriber_code'] ?? ''));
    $profile['contract_no'] = (string) ($account['contract_no'] ?? ($profile['contract_no'] ?? ''));
    $profile['plan'] = (string) ($account['plan'] ?? ($profile['plan'] ?? ''));
    $profile['status'] = (string) ($account['status'] ?? ($profile['status'] ?? 'Aktiv'));
    $profile['area'] = (string) ($account['area'] ?? ($profile['area'] ?? ''));
    $profile['address'] = (string) ($account['address'] ?? ($profile['address'] ?? ''));
    $profile['email'] = (string) ($account['email'] ?? ($profile['email'] ?? ''));
    $profile['phone'] = (string) ($account['phone'] ?? ($profile['phone'] ?? ''));

    $payload['profile'] = $profile;
    $payload['traffic'] = is_array($payload['traffic'] ?? null) ? $payload['traffic'] : [];
    $payload['payments'] = is_array($payload['payments'] ?? null) ? $payload['payments'] : [];
    $payload['statements'] = is_array($payload['statements'] ?? null) ? $payload['statements'] : [];
    $payload['extra_services'] = app_billing_extra_services($payload);
    $snapshotDb = app_db();
    $payload = app_billing_refresh_payload_totals(
        $account,
        $payload,
        $snapshotDb instanceof PDO ? app_billing_account_tariff($snapshotDb, $account, is_array($payload['billing'] ?? null) ? $payload['billing'] : []) : null
    );
    $payload['tickets'] = app_db_customer_tickets($user);
    $payload['messages'] = app_message_customer_history((int) ($account['id'] ?? 0), 30);

    return $payload;
}

function app_db_employee_snapshot(array $user): array
{
    $account = app_db_account_by_id((int) ($user['id'] ?? 0));
    if ($account === null) {
        $account = app_db_session_user_from_legacy($user);
        if (is_array($account) && isset($account['id'])) {
            $account = app_db_account_by_id((int) $account['id']);
        }
    }
    if ($account === null) {
        return app_mock_employee_snapshot_data($user);
    }

    $payload = app_db_account_payload($account);
    $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
    $permissions = is_array($payload['permissions'] ?? null) ? $payload['permissions'] : [];
    if ($permissions === []) {
        $permissions = app_db_default_permissions((string) ($account['account_type'] ?? 'operator'));
    }

    $stats = app_db_ticket_stats();
    $assignedTickets = app_db_ticket_records([
        'assigned_technician_name' => (string) ($account['full_name'] ?? ''),
    ]);

    $summary['role_title'] = (string) ($summary['role_title'] ?? app_db_role_title((string) ($account['account_type'] ?? 'operator')));
    $summary['billing_access'] = (string) ($summary['billing_access'] ?? 'Aktiv');
    $summary['shift'] = (string) ($summary['shift'] ?? 'Novbe aktivdir');
    $summary['open_queue'] = (string) $stats['open'] . ' aciq muraciet';

    return [
        'summary' => $summary,
        'permissions' => $permissions,
        'crm_stats' => $stats,
        'tickets' => $assignedTickets,
        'technicians' => app_db_technicians(),
        'olt_links' => app_portal_employee_links($user),
    ];
}

function app_db_default_permissions(string $accountType): array
{
    return match ($accountType) {
        'admin' => [
            'Istifadeci, texnik ve operator hesablarini idare etmek',
            'Import, statistika ve OLT sync proseslerini idare etmek',
            'Bilet axinina tam nezaret etmek',
        ],
        'technician' => [
            'Oz erazisi uzre biletleri gormek',
            'Bilet baglama, sebeb secme ve qeyd aparmaq',
            'OLT baxis linklerinden istifade etmek',
        ],
        default => [
            'Muraciet yaratmaq ve filtirlemek',
            'Abonent melumatina baxmaq',
            'Texnike uygun bilet yonlendirmek',
        ],
    };
}

function app_db_role_title(string $accountType): string
{
    return match ($accountType) {
        'admin' => 'Administrator',
        'technician' => 'Saha texniki',
        default => 'Operator',
    };
}

function app_db_update_customer_phone(array $user, string $phone): bool
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return false;
    }

    $statement = $db->prepare('UPDATE portal_accounts SET phone = :phone WHERE id = :id AND role = :role');
    return $statement->execute([
        'phone' => $phone,
        'id' => (int) ($user['id'] ?? 0),
        'role' => 'customer',
    ]);
}

function app_db_update_password(array $user, string $currentPassword, string $newPassword): bool
{
    $account = app_db_account_by_id((int) ($user['id'] ?? 0));
    if ($account === null) {
        return false;
    }

    if (!password_verify($currentPassword, (string) ($account['password_hash'] ?? ''))) {
        return false;
    }

    $db = app_db();
    if (!$db instanceof PDO) {
        return false;
    }

    $statement = $db->prepare('UPDATE portal_accounts SET password_hash = :password_hash WHERE id = :id');
    return $statement->execute([
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'id' => (int) $account['id'],
    ]);
}

function app_db_technicians(): array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return [];
    }

    $rows = $db->query("SELECT * FROM portal_accounts WHERE role = 'employee' AND account_type = 'technician' AND is_active = 1 ORDER BY full_name")->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $result[] = [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['full_name'] ?? ''),
            'areas' => app_db_account_areas((int) ($row['id'] ?? 0)),
            'login' => (string) ($row['login'] ?? ''),
            'employee_role' => (string) ($row['employee_role'] ?? 'technician'),
        ];
    }

    return $result;
}

function app_db_next_ticket_no(): string
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return 'REQ-' . random_int(100000, 999999);
    }

    $db->beginTransaction();
    try {
        $statement = $db->prepare('SELECT current_value FROM portal_sequences WHERE name = :name FOR UPDATE');
        $statement->execute(['name' => 'ticket']);
        $current = $statement->fetchColumn();
        if (is_numeric($current)) {
            $currentValue = (int) $current;
        } else {
            $maxExisting = (int) $db->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(ticket_no, '-', -1) AS UNSIGNED)), 100000) FROM portal_tickets")->fetchColumn();
            $currentValue = max($maxExisting, 100000);
        }
        $next = $currentValue + 1;

        if ($current === false) {
            $insert = $db->prepare('INSERT INTO portal_sequences (name, current_value) VALUES (:name, :current_value)');
            $insert->execute([
                'name' => 'ticket',
                'current_value' => $next,
            ]);
        } else {
            $update = $db->prepare('UPDATE portal_sequences SET current_value = :current_value WHERE name = :name');
            $update->execute([
                'name' => 'ticket',
                'current_value' => $next,
            ]);
        }

        $db->commit();
        return 'REQ-' . $next;
    } catch (Throwable) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }

    return 'REQ-' . random_int(100000, 999999);
}

function app_db_insert_ticket(array $ticket): bool
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return false;
    }

    $ticketNo = trim((string) ($ticket['ticket_no'] ?? app_db_next_ticket_no()));
    if (strlen($ticketNo) > 32) {
        $ticketNo = substr($ticketNo, 0, 23) . '-' . substr(hash('sha256', $ticketNo), 0, 8);
    }

    try {
        $statement = $db->prepare(
            'INSERT INTO portal_tickets (
                ticket_no, customer_account_id, subscriber_code, contract_no, customer_name, phone, address, area,
                subject, message, status, opened_at, updated_at, resolved_at, assigned_technician_account_id,
                assigned_technician_name, resolution_reason, resolution_note, handled_by, created_by_account_id,
                created_by_role, created_by_name, telegram_message_ids_json, metadata_json
            ) VALUES (
                :ticket_no, :customer_account_id, :subscriber_code, :contract_no, :customer_name, :phone, :address, :area,
                :subject, :message, :status, :opened_at, :updated_at, :resolved_at, :assigned_technician_account_id,
                :assigned_technician_name, :resolution_reason, :resolution_note, :handled_by, :created_by_account_id,
                :created_by_role, :created_by_name, :telegram_message_ids_json, :metadata_json
            )'
        );

        $ok = $statement->execute([
            'ticket_no' => $ticketNo,
            'customer_account_id' => (int) ($ticket['customer_account_id'] ?? 0) ?: null,
            'subscriber_code' => (string) ($ticket['subscriber_code'] ?? ''),
            'contract_no' => (string) ($ticket['contract_no'] ?? ''),
            'customer_name' => (string) ($ticket['customer_name'] ?? ''),
            'phone' => (string) ($ticket['phone'] ?? ''),
            'address' => (string) ($ticket['address'] ?? ''),
            'area' => (string) ($ticket['area'] ?? ''),
            'subject' => (string) ($ticket['subject'] ?? ''),
            'message' => mb_substr((string) ($ticket['message'] ?? ''), 0, 160),
            'status' => (string) ($ticket['status'] ?? 'Aciqdir'),
            'opened_at' => (string) ($ticket['opened_at'] ?? app_db_now()),
            'updated_at' => (string) ($ticket['updated_at'] ?? app_db_now()),
            'resolved_at' => $ticket['resolved_at'] ?? null,
            'assigned_technician_account_id' => (int) ($ticket['assigned_technician_account_id'] ?? 0) ?: null,
            'assigned_technician_name' => (string) ($ticket['assigned_technician_name'] ?? ''),
            'resolution_reason' => (string) ($ticket['resolution_reason'] ?? ''),
            'resolution_note' => (string) ($ticket['resolution_note'] ?? ''),
            'handled_by' => (string) ($ticket['handled_by'] ?? ''),
            'created_by_account_id' => (int) ($ticket['created_by_account_id'] ?? 0) ?: null,
            'created_by_role' => (string) ($ticket['created_by_role'] ?? ''),
            'created_by_name' => (string) ($ticket['created_by_name'] ?? ''),
            'telegram_message_ids_json' => is_string($ticket['telegram_message_ids_json'] ?? null)
                ? (string) $ticket['telegram_message_ids_json']
                : app_db_json_encode((array) ($ticket['telegram_message_ids'] ?? [])),
            'metadata_json' => is_string($ticket['metadata_json'] ?? null)
                ? (string) $ticket['metadata_json']
                : (($ticket['metadata'] ?? null) !== null ? app_db_json_encode($ticket['metadata']) : null),
        ]);
    } catch (Throwable) {
        return false;
    }

    if ($ok) {
        $ticketId = (int) $db->lastInsertId();
        app_db_add_ticket_event($ticketId, 'created', (string) ($ticket['message'] ?? ''), (int) ($ticket['created_by_account_id'] ?? 0), (string) ($ticket['created_by_name'] ?? ''));
    }

    return $ok;
}

function app_db_add_ticket_event(int $ticketId, string $eventType, string $note = '', int $actorAccountId = 0, string $actorName = '', ?array $payload = null): void
{
    $db = app_db();
    if (!$db instanceof PDO || $ticketId <= 0) {
        return;
    }

    $statement = $db->prepare(
        'INSERT INTO portal_ticket_events (ticket_id, event_type, event_note, actor_account_id, actor_name, payload_json)
         VALUES (:ticket_id, :event_type, :event_note, :actor_account_id, :actor_name, :payload_json)'
    );
    $statement->execute([
        'ticket_id' => $ticketId,
        'event_type' => $eventType,
        'event_note' => $note,
        'actor_account_id' => $actorAccountId > 0 ? $actorAccountId : null,
        'actor_name' => $actorName,
        'payload_json' => $payload !== null ? app_db_json_encode($payload) : null,
    ]);
}

function app_db_ticket_row_to_public(array $row): array
{
    return [
        'id' => (string) ($row['ticket_no'] ?? ''),
        'customer_id' => (string) ($row['customer_account_id'] ?? ''),
        'subscriber_code' => (string) ($row['subscriber_code'] ?? ''),
        'contract_no' => (string) ($row['contract_no'] ?? ''),
        'customer_name' => (string) ($row['customer_name'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
        'address' => (string) ($row['address'] ?? ''),
        'area' => (string) ($row['area'] ?? ''),
        'subject' => (string) ($row['subject'] ?? ''),
        'message' => (string) ($row['message'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'opened_at' => app_db_display_datetime((string) ($row['opened_at'] ?? '')),
        'updated_at' => app_db_display_datetime((string) ($row['updated_at'] ?? '')),
        'resolved_at' => app_db_display_datetime((string) ($row['resolved_at'] ?? '')),
        'assigned_technician_id' => (string) ($row['assigned_technician_account_id'] ?? ''),
        'assigned_technician_name' => (string) ($row['assigned_technician_name'] ?? ''),
        'resolution_reason' => (string) ($row['resolution_reason'] ?? ''),
        'resolution_note' => (string) ($row['resolution_note'] ?? ''),
        'handled_by' => (string) ($row['handled_by'] ?? ''),
        'created_by_role' => (string) ($row['created_by_role'] ?? ''),
        'created_by_name' => (string) ($row['created_by_name'] ?? ''),
        'telegram_message_ids' => app_db_json_decode((string) ($row['telegram_message_ids_json'] ?? ''), []),
    ];
}

function app_db_ticket_where_sql(array $filters, array &$params): string
{
    $sql = ' WHERE 1=1';

    $q = strtolower(trim((string) ($filters['q'] ?? '')));
    if ($q !== '') {
        $sql .= ' AND (LOWER(ticket_no) LIKE :q OR LOWER(customer_name) LIKE :q OR LOWER(subscriber_code) LIKE :q OR LOWER(subject) LIKE :q OR LOWER(area) LIKE :q OR LOWER(assigned_technician_name) LIKE :q OR LOWER(phone) LIKE :q)';
        $params['q'] = '%' . $q . '%';
    }

    $mapping = [
        'area' => 'area',
        'status' => 'status',
        'subject' => 'subject',
        'assigned_technician_name' => 'assigned_technician_name',
    ];
    foreach ($mapping as $filterKey => $field) {
        $value = trim((string) ($filters[$filterKey] ?? ''));
        if ($value !== '') {
            $sql .= " AND {$field} = :{$filterKey}";
            $params[$filterKey] = $value;
        }
    }

    if (!empty($filters['customer_account_id'])) {
        $sql .= ' AND customer_account_id = :customer_account_id';
        $params['customer_account_id'] = (int) $filters['customer_account_id'];
    }

    return $sql;
}

function app_db_ticket_records(array $filters = []): array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return [];
    }

    $params = [];
    $sql = 'SELECT * FROM portal_tickets' . app_db_ticket_where_sql($filters, $params);
    $sql .= ' ORDER BY opened_at DESC, id DESC';
    $limit = max(0, min(500, (int) ($filters['limit'] ?? 0)));
    if ($limit > 0) {
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
    }
    $statement = $db->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();

    return array_map(static fn (array $row): array => app_db_ticket_row_to_public($row), $rows);
}

function app_db_ticket_count(array $filters = []): int
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return 0;
    }

    $params = [];
    $sql = 'SELECT COUNT(*) FROM portal_tickets' . app_db_ticket_where_sql($filters, $params);
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetchColumn();
}

function app_db_customer_tickets(array $user): array
{
    return app_db_ticket_records(['customer_account_id' => (int) ($user['id'] ?? 0)]);
}

function app_db_ticket_stats(): array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return ['total' => 0, 'open' => 0, 'closed' => 0];
    }

    $rows = $db->query("SELECT status, COUNT(*) AS total FROM portal_tickets GROUP BY status")->fetchAll();
    $stats = ['total' => 0, 'open' => 0, 'closed' => 0];
    foreach ($rows as $row) {
        $count = (int) ($row['total'] ?? 0);
        $status = (string) ($row['status'] ?? '');
        $stats['total'] += $count;
        if ($status === 'Baglandi') {
            $stats['closed'] += $count;
        } else {
            $stats['open'] += $count;
        }
    }

    return $stats;
}

function app_db_ticket_find(string $ticketNo): ?array
{
    $db = app_db();
    if (!$db instanceof PDO || $ticketNo === '') {
        return null;
    }

    $statement = $db->prepare('SELECT * FROM portal_tickets WHERE ticket_no = :ticket_no LIMIT 1');
    $statement->execute(['ticket_no' => $ticketNo]);
    $row = $statement->fetch();
    return is_array($row) ? app_db_ticket_row_to_public($row) : null;
}

function app_db_close_ticket(string $ticketNo, string $reason, string $note): bool
{
    $db = app_db();
    if (!$db instanceof PDO || $ticketNo === '') {
        return false;
    }

    $statement = $db->prepare('SELECT * FROM portal_tickets WHERE ticket_no = :ticket_no LIMIT 1');
    $statement->execute(['ticket_no' => $ticketNo]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return false;
    }

    $update = $db->prepare(
        'UPDATE portal_tickets
         SET status = :status, updated_at = :updated_at, resolved_at = :resolved_at,
             resolution_reason = :resolution_reason, resolution_note = :resolution_note,
             handled_by = :handled_by
         WHERE ticket_no = :ticket_no'
    );
    $ok = $update->execute([
        'status' => 'Baglandi',
        'updated_at' => app_db_now(),
        'resolved_at' => app_db_now(),
        'resolution_reason' => $reason,
        'resolution_note' => $note,
        'handled_by' => (string) ($row['assigned_technician_name'] ?? ''),
        'ticket_no' => $ticketNo,
    ]);

    if ($ok) {
        app_db_add_ticket_event(
            (int) ($row['id'] ?? 0),
            'closed',
            $note,
            (int) ($row['assigned_technician_account_id'] ?? 0),
            (string) ($row['assigned_technician_name'] ?? ''),
            ['reason' => $reason]
        );
    }

    return $ok;
}

function app_db_accounts(array $filters = []): array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return [];
    }

    $sql = 'SELECT * FROM portal_accounts WHERE 1=1';
    $params = [];

    $q = strtolower(trim((string) ($filters['q'] ?? '')));
    if ($q !== '') {
        $sql .= ' AND (LOWER(full_name) LIKE :q OR LOWER(login) LIKE :q OR LOWER(subscriber_code) LIKE :q OR LOWER(email) LIKE :q)';
        $params['q'] = '%' . $q . '%';
    }

    foreach (['role', 'account_type'] as $field) {
        $value = trim((string) ($filters[$field] ?? ''));
        if ($value !== '') {
            $sql .= " AND {$field} = :{$field}";
            $params[$field] = $value;
        }
    }

    $sql .= ' ORDER BY role, account_type, full_name';
    $statement = $db->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();

    $accounts = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $row['responsible_areas'] = app_db_account_areas((int) ($row['id'] ?? 0));
        $accounts[] = $row;
    }

    return $accounts;
}

function app_db_customer_lookup(string $query, int $limit = 10): array
{
    $db = app_db();
    if (!$db instanceof PDO || trim($query) === '') {
        return [];
    }

    $rawQuery = trim($query);
    if (preg_match('/(?:--|\/\*|\*\/|;\s*$|\bunion\b|\bselect\b.+\bfrom\b|\bor\b\s+\d+\s*=\s*\d+|\bor\b\s*[\'"][^\'"]+[\'"]\s*=\s*[\'"][^\'"]+[\'"])/i', $rawQuery)) {
        return [];
    }

    $query = strtolower($rawQuery);
    $phoneDigits = preg_replace('/\D+/', '', $query) ?? '';
    if ($phoneDigits !== '' && strlen($phoneDigits) < 3) {
        $phoneDigits = '';
    }
    $statement = $db->prepare(
        'SELECT * FROM portal_accounts
         WHERE role = :role AND is_active = 1
           AND (
                LOWER(full_name) LIKE :q
                OR LOWER(subscriber_code) LIKE :q
                OR LOWER(COALESCE(contract_no, \'\')) LIKE :q
                OR LOWER(COALESCE(login, \'\')) LIKE :q
                OR LOWER(COALESCE(address, \'\')) LIKE :q
                OR LOWER(COALESCE(area, \'\')) LIKE :q
                OR CAST(id AS CHAR) LIKE :q
                OR (
                    :phone_digits <> \'\'
                    AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, \'\'), \'+\', \'\'), \' \', \'\'), \'-\', \'\'), \'(\', \'\'), \')\', \'\') LIKE :phone_q
                )
           )
         ORDER BY full_name
         LIMIT ' . max(1, min($limit, 20))
    );
    $statement->execute([
        'role' => 'customer',
        'q' => '%' . $query . '%',
        'phone_digits' => $phoneDigits,
        'phone_q' => '%' . $phoneDigits . '%',
    ]);

    $result = [];
    foreach ($statement->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }

        $result[] = [
            'id' => (string) ($row['id'] ?? ''),
            'full_name' => (string) ($row['full_name'] ?? ''),
            'subscriber_code' => (string) ($row['subscriber_code'] ?? ''),
            'contract_no' => (string) ($row['contract_no'] ?? ''),
            'login' => (string) ($row['login'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'area' => (string) ($row['area'] ?? ''),
            'address' => (string) ($row['address'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'plan' => (string) ($row['plan'] ?? ''),
        ];
    }

    return $result;
}

function app_db_upsert_account(array $payload, bool $syncOlt = true): ?array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return null;
    }

    $id = (int) ($payload['id'] ?? 0);
    $login = trim((string) ($payload['login'] ?? ''));
    $role = (string) ($payload['role'] ?? 'customer');
    $accountType = (string) ($payload['account_type'] ?? ($role === 'customer' ? 'customer' : 'operator'));
    $employeeRole = (string) ($payload['employee_role'] ?? ($accountType === 'admin' ? 'admin' : ($accountType === 'technician' ? 'technician' : 'support')));
    $fullName = trim((string) ($payload['full_name'] ?? ''));
    $password = (string) ($payload['password'] ?? '');

    if ($login === '' || $fullName === '' || !in_array($role, ['customer', 'employee'], true)) {
        return null;
    }

    if ($role === 'customer' && !$db->inTransaction()) {
        $db->exec('CREATE TABLE IF NOT EXISTS billing_customer_fin_claims(fin_code VARCHAR(32) NOT NULL PRIMARY KEY,account_id BIGINT UNSIGNED NOT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_billing_fin_claim_account(account_id))ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    $startedTransaction=!$db->inTransaction();
    if($startedTransaction)$db->beginTransaction();
    $existing = $id > 0 ? app_db_account_by_id($id) : app_db_account_by_login($login);
    $passwordHash = (string) ($existing['password_hash'] ?? '');
    if ($password !== '') {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    }
    if ($passwordHash === '') {
        if($startedTransaction&&$db->inTransaction())$db->rollBack();
        return null;
    }

    $record = [
        'external_id' => (string) ($payload['external_id'] ?? ($existing['external_id'] ?? '')),
        'login' => $login,
        'password_hash' => $passwordHash,
        'role' => $role,
        'account_type' => $accountType,
        'employee_role' => $role === 'employee' ? $employeeRole : '',
        'job_title' => trim((string) ($payload['job_title'] ?? ($existing['job_title'] ?? ''))) ?: null,
        'full_name' => $fullName,
        'subscriber_code' => trim((string) ($payload['subscriber_code'] ?? '')) ?: null,
        'contract_no' => trim((string) ($payload['contract_no'] ?? '')) ?: null,
        'fin_code' => ($role==='customer'?(strtoupper(preg_replace('/[^A-Za-z0-9]/','',(string)($payload['fin_code']??($existing['fin_code']??'')))??'')):trim((string)($payload['fin_code']??($existing['fin_code']??'')))) ?: null,
        'document_no' => trim((string) ($payload['document_no'] ?? ($existing['document_no'] ?? ''))) ?: null,
        'email' => trim((string) ($payload['email'] ?? '')) ?: null,
        'phone' => trim((string) ($payload['phone'] ?? '')) ?: null,
        'category' => trim((string) ($payload['category'] ?? '')) ?: null,
        'area' => trim((string) ($payload['area'] ?? '')) ?: null,
        'address' => trim((string) ($payload['address'] ?? '')) ?: null,
        'plan' => trim((string) ($payload['plan'] ?? '')) ?: null,
        'status' => trim((string) ($payload['status'] ?? 'Aktiv')) ?: null,
        'is_active' => !empty($payload['is_active']) ? 1 : 0,
        'payload_json' => app_db_json_encode((array) ($payload['payload'] ?? app_db_account_payload($existing ?? []))),
    ];

    try {
        if ($existing !== null) {
            $statement = $db->prepare(
                'UPDATE portal_accounts SET
                    external_id = :external_id,
                    login = :login,
                    password_hash = :password_hash,
                    role = :role,
                    account_type = :account_type,
                    employee_role = :employee_role,
                    job_title = :job_title,
                    full_name = :full_name,
                    subscriber_code = :subscriber_code,
                    contract_no = :contract_no,
                    fin_code = :fin_code,
                    document_no = :document_no,
                    email = :email,
                    phone = :phone,
                    category = :category,
                    area = :area,
                    address = :address,
                    plan = :plan,
                    status = :status,
                    is_active = :is_active,
                    payload_json = :payload_json
                 WHERE id = :id'
            );
            $record['id'] = (int) $existing['id'];
            $statement->execute($record);
            $accountId = (int) $existing['id'];
            $action = 'update';
        } else {
            $statement = $db->prepare(
                'INSERT INTO portal_accounts (
                    external_id, login, password_hash, role, account_type, employee_role, job_title, full_name,
                    subscriber_code, contract_no, fin_code, document_no, email, phone, category, area, address, plan, status, is_active, payload_json
                 ) VALUES (
                    :external_id, :login, :password_hash, :role, :account_type, :employee_role, :job_title, :full_name,
                    :subscriber_code, :contract_no, :fin_code, :document_no, :email, :phone, :category, :area, :address, :plan, :status, :is_active, :payload_json
                 )'
            );
            $statement->execute($record);
            $accountId = (int) $db->lastInsertId();
            $action = 'create';
        }
        if($role==='customer'){
            if($record['is_active']===1&&$record['fin_code']!==null){
                $db->prepare('DELETE FROM billing_customer_fin_claims WHERE account_id=:account_id AND fin_code<>:fin_code')->execute(['account_id'=>$accountId,'fin_code'=>$record['fin_code']]);
                $db->prepare('INSERT IGNORE INTO billing_customer_fin_claims(fin_code,account_id)VALUES(:fin_code,:account_id)')->execute(['fin_code'=>$record['fin_code'],'account_id'=>$accountId]);
                $claim=$db->prepare('SELECT account_id FROM billing_customer_fin_claims WHERE fin_code=:fin_code FOR UPDATE');$claim->execute(['fin_code'=>$record['fin_code']]);
                if((int)$claim->fetchColumn()!==$accountId)throw new RuntimeException('duplicate_fin');
            }else{$db->prepare('DELETE FROM billing_customer_fin_claims WHERE account_id=:account_id')->execute(['account_id'=>$accountId]);}
        }
    } catch (Throwable) {
        if($startedTransaction&&$db->inTransaction())$db->rollBack();
        return null;
    }

    if (array_key_exists('responsible_areas', $payload)) {
        app_db_replace_account_areas($accountId, (array) $payload['responsible_areas']);
    }

    $saved = app_db_account_by_id($accountId);
    if ($saved === null) {
        if($startedTransaction&&$db->inTransaction())$db->rollBack();
        return null;
    }

    if($startedTransaction&&$db->inTransaction())$db->commit();

    if ($syncOlt) {
        app_db_sync_olt_account($action, $saved);
    }

    return $saved;
}

function app_db_replace_account_areas(int $accountId, array $areas): void
{
    $db = app_db();
    if (!$db instanceof PDO || $accountId <= 0) {
        return;
    }

    $areas = array_values(array_unique(array_filter(array_map(
        static fn ($area): string => trim((string) $area),
        $areas
    ))));
    sort($areas, SORT_NATURAL | SORT_FLAG_CASE);

    $currentStatement = $db->prepare('SELECT area FROM portal_account_areas WHERE account_id = :account_id ORDER BY area');
    $currentStatement->execute(['account_id' => $accountId]);
    $currentAreas = array_values(array_map('strval', $currentStatement->fetchAll(PDO::FETCH_COLUMN) ?: []));
    sort($currentAreas, SORT_NATURAL | SORT_FLAG_CASE);
    if ($currentAreas === $areas) {
        return;
    }

    $attempts = $db->inTransaction() ? 1 : 3;
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        try {
            $delete = $db->prepare('DELETE FROM portal_account_areas WHERE account_id = :account_id');
            $delete->execute(['account_id' => $accountId]);

            if ($areas !== []) {
                $insert = $db->prepare('INSERT IGNORE INTO portal_account_areas (account_id, area) VALUES (:account_id, :area)');
                foreach ($areas as $area) {
                    $insert->execute([
                        'account_id' => $accountId,
                        'area' => $area,
                    ]);
                }
            }
            return;
        } catch (PDOException $exception) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
            if (!in_array($driverCode, [1205, 1213], true) && $sqlState !== '40001') {
                throw $exception;
            }
            if ($attempt >= $attempts) {
                throw $exception;
            }
            usleep(50000 * $attempt);
        }
    }
}

function app_db_delete_account(int $accountId): bool
{
    $db = app_db();
    if (!$db instanceof PDO || $accountId <= 0) {
        return false;
    }

    $account = app_db_account_by_id($accountId);
    if ($account === null) {
        return false;
    }

    $statement = $db->prepare('DELETE FROM portal_accounts WHERE id = :id');
    $ok = $statement->execute(['id' => $accountId]);
    if ($ok) {
        app_db_sync_olt_account('delete', $account);
    }

    return $ok;
}

function app_db_sync_olt_account(string $action, array $account): void
{
    $endpoint = app_config('olt_user_sync_endpoint');
    if ($endpoint === '' || !in_array((string) ($account['role'] ?? ''), ['employee'], true)) {
        return;
    }

    $areas = app_db_account_areas((int) ($account['id'] ?? 0));
    $payload = [
        'action' => $action,
        'user' => [
            'id' => (string) ($account['id'] ?? ''),
            'external_id' => (string) ($account['external_id'] ?? ''),
            'login' => (string) ($account['login'] ?? ''),
            'name' => (string) ($account['full_name'] ?? ''),
            'role' => app_portal_olt_role((string) ($account['employee_role'] ?? '')),
            'employee_role' => (string) ($account['employee_role'] ?? ''),
            'account_type' => (string) ($account['account_type'] ?? ''),
            'email' => (string) ($account['email'] ?? ''),
            'phone' => (string) ($account['phone'] ?? ''),
            'areas' => $areas,
            'is_active' => (int) ($account['is_active'] ?? 0) === 1,
        ],
    ];

    $headers = [];
    $secret = app_config('olt_user_sync_secret', app_config('olt_sso_shared_secret'));
    if ($secret !== '') {
        $headers[] = 'X-Portal-Sync-Secret: ' . $secret;
    }

    http_post_json($endpoint, $payload, $headers);
}

function app_db_sync_all_olt_accounts(): array
{
    $accounts = app_db_accounts(['role' => 'employee']);
    $synced = 0;

    foreach ($accounts as $account) {
        if (!is_array($account)) {
            continue;
        }

        app_db_sync_olt_account('upsert', $account);
        $synced++;
    }

    return ['synced' => $synced];
}

function app_db_ticket_closed_sql(): string
{
    return "(resolved_at IS NOT NULL OR status IN ('Baglandi', 'Bağlandı', 'closed', 'resolved'))";
}

function app_db_problem_statistics(string $from = '', string $to = '', string $groupBy = 'day'): array
{
    $db = app_db();
    $groupBy = in_array($groupBy, ['day', 'week', 'month'], true) ? $groupBy : 'day';
    if (!$db instanceof PDO) {
        return [
            'from' => $from !== '' ? $from : date('Y-m-01'),
            'to' => $to !== '' ? $to : date('Y-m-d'),
            'group_by' => $groupBy,
            'reasons' => [],
            'periods' => [],
            'datasets' => [],
            'rows' => [],
            'totals' => [],
            'total_count' => 0,
            'open_count' => 0,
            'closed_count' => 0,
            'hourly' => [],
            'top_hour' => '-',
            'top_hour_count' => 0,
            'top_reason' => '-',
            'top_reason_count' => 0,
            'problem_breakdown' => [],
            'employee_breakdown' => [],
            'recent_tickets' => [],
        ];
    }

    $start = app_db_parse_datetime($from !== '' ? $from . ' 00:00:00' : date('Y-m-01 00:00:00')) ?? date('Y-m-01 00:00:00');
    $end = app_db_parse_datetime($to !== '' ? $to . ' 23:59:59' : date('Y-m-d 23:59:59')) ?? date('Y-m-d 23:59:59');
    $closedSql = app_db_ticket_closed_sql();
    $periodSql = match ($groupBy) {
        'month' => "DATE_FORMAT(opened_at, '%Y-%m')",
        'week' => "DATE_FORMAT(opened_at, '%x-W%v')",
        default => "DATE(opened_at)",
    };
    $params = ['start' => $start, 'end' => $end];

    $summaryStatement = $db->prepare(
        "SELECT COUNT(*) AS total_count,
                SUM(CASE WHEN {$closedSql} THEN 1 ELSE 0 END) AS closed_count,
                SUM(CASE WHEN NOT ({$closedSql}) THEN 1 ELSE 0 END) AS open_count
           FROM portal_tickets
          WHERE opened_at BETWEEN :start AND :end"
    );
    $summaryStatement->execute($params);
    $summary = $summaryStatement->fetch() ?: [];
    $totalCount = (int) ($summary['total_count'] ?? 0);
    $closedCount = (int) ($summary['closed_count'] ?? 0);
    $openCount = (int) ($summary['open_count'] ?? 0);

    $reasonsStatement = $db->prepare(
        "SELECT COALESCE(NULLIF(subject, ''), NULLIF(resolution_reason, ''), 'Diger') AS reason,
                COUNT(*) AS total,
                SUM(CASE WHEN {$closedSql} THEN 1 ELSE 0 END) AS closed_count,
                SUM(CASE WHEN NOT ({$closedSql}) THEN 1 ELSE 0 END) AS open_count,
                ROUND(AVG(TIMESTAMPDIFF(HOUR, opened_at, COALESCE(resolved_at, updated_at))), 1) AS avg_hours
           FROM portal_tickets
          WHERE opened_at BETWEEN :start AND :end
          GROUP BY reason
          ORDER BY total DESC, reason ASC
          LIMIT 30"
    );
    $reasonsStatement->execute($params);
    $problemBreakdown = [];
    $reasons = [];
    $totals = [];
    foreach ($reasonsStatement->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $reason = (string) ($row['reason'] ?? 'Diger');
        $reasons[] = $reason;
        $totals[$reason] = (int) ($row['total'] ?? 0);
        $problemBreakdown[] = [
            'reason' => $reason,
            'total' => (int) ($row['total'] ?? 0),
            'open_count' => (int) ($row['open_count'] ?? 0),
            'closed_count' => (int) ($row['closed_count'] ?? 0),
            'avg_hours' => (float) ($row['avg_hours'] ?? 0),
            'percent' => $totalCount > 0 ? round(((int) ($row['total'] ?? 0) / $totalCount) * 100, 2) : 0.0,
        ];
    }

    $periodStatement = $db->prepare(
        "SELECT {$periodSql} AS period,
                COALESCE(NULLIF(subject, ''), NULLIF(resolution_reason, ''), 'Diger') AS reason,
                COUNT(*) AS total,
                SUM(CASE WHEN {$closedSql} THEN 1 ELSE 0 END) AS closed_count,
                SUM(CASE WHEN NOT ({$closedSql}) THEN 1 ELSE 0 END) AS open_count
           FROM portal_tickets
          WHERE opened_at BETWEEN :start AND :end
          GROUP BY period, reason
          ORDER BY period ASC, total DESC"
    );
    $periodStatement->execute($params);
    $periods = [];
    $buckets = [];
    $rows = [];

    foreach ($periodStatement->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $period = (string) ($row['period'] ?? '');
        $reason = (string) ($row['reason'] ?? 'Diger');
        if ($period === '') {
            continue;
        }
        $periods[$period] = true;
        $buckets[$period] ??= [];
        if (!array_key_exists($reason, $buckets[$period])) {
            $buckets[$period][$reason] = ['total' => 0, 'open_count' => 0, 'closed_count' => 0];
        }
        $buckets[$period][$reason] = [
            'total' => (int) ($row['total'] ?? 0),
            'open_count' => (int) ($row['open_count'] ?? 0),
            'closed_count' => (int) ($row['closed_count'] ?? 0),
        ];
    }

    $periodLabels = array_keys($periods);
    sort($periodLabels);

    foreach ($periodLabels as $period) {
        $periodTotal = array_sum(array_map(static fn (array $item): int => (int) ($item['total'] ?? 0), $buckets[$period] ?? []));
        foreach ($buckets[$period] ?? [] as $reason => $bucket) {
            $count = (int) ($bucket['total'] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $rows[] = [
                'period' => $period,
                'reason' => $reason,
                'count' => $count,
                'open_count' => (int) ($bucket['open_count'] ?? 0),
                'closed_count' => (int) ($bucket['closed_count'] ?? 0),
                'percent' => $periodTotal > 0 ? round(($count / $periodTotal) * 100, 2) : 0.0,
            ];
        }
    }

    $datasets = [];
    $palette = ['#175fe6', '#2ab6a5', '#f4a63a', '#c84f7c', '#6b7ff2', '#f06d5f', '#41b883', '#8e63f8', '#d1a429'];
    foreach ($reasons as $index => $reason) {
        $data = [];
        foreach ($periodLabels as $period) {
            $data[] = (int) (($buckets[$period][$reason]['total'] ?? 0));
        }
        $datasets[] = [
            'label' => $reason,
            'data' => $data,
            'backgroundColor' => $palette[$index % count($palette)],
            'borderRadius' => 6,
        ];
    }

    arsort($totals);

    $hourlyStatement = $db->prepare(
        "SELECT HOUR(opened_at) AS hour,
                COUNT(*) AS total,
                SUM(CASE WHEN {$closedSql} THEN 1 ELSE 0 END) AS closed_count,
                SUM(CASE WHEN NOT ({$closedSql}) THEN 1 ELSE 0 END) AS open_count
           FROM portal_tickets
          WHERE opened_at BETWEEN :start AND :end
          GROUP BY hour
          ORDER BY hour"
    );
    $hourlyStatement->execute($params);
    $hourly = [];
    for ($hour = 0; $hour < 24; $hour++) {
        $hourly[$hour] = ['hour' => sprintf('%02d:00', $hour), 'total' => 0, 'open_count' => 0, 'closed_count' => 0];
    }
    foreach ($hourlyStatement->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $hour = (int) ($row['hour'] ?? 0);
        $hourly[$hour] = [
            'hour' => sprintf('%02d:00', $hour),
            'total' => (int) ($row['total'] ?? 0),
            'open_count' => (int) ($row['open_count'] ?? 0),
            'closed_count' => (int) ($row['closed_count'] ?? 0),
        ];
    }
    $topHour = '-';
    $topHourCount = 0;
    foreach ($hourly as $row) {
        if ((int) $row['total'] > $topHourCount) {
            $topHourCount = (int) $row['total'];
            $topHour = (string) $row['hour'];
        }
    }

    $employeeStatement = $db->prepare(
        "SELECT COALESCE(NULLIF(created_by_name, ''), 'Bilinmir') AS opened_by,
                COUNT(*) AS total,
                SUM(CASE WHEN {$closedSql} THEN 1 ELSE 0 END) AS closed_count,
                SUM(CASE WHEN NOT ({$closedSql}) THEN 1 ELSE 0 END) AS open_count
           FROM portal_tickets
          WHERE opened_at BETWEEN :start AND :end
          GROUP BY opened_by
          ORDER BY total DESC, opened_by ASC
          LIMIT 30"
    );
    $employeeStatement->execute($params);
    $employeeBreakdown = [];
    foreach ($employeeStatement->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $employeeBreakdown[] = [
            'opened_by' => (string) ($row['opened_by'] ?? 'Bilinmir'),
            'total' => (int) ($row['total'] ?? 0),
            'open_count' => (int) ($row['open_count'] ?? 0),
            'closed_count' => (int) ($row['closed_count'] ?? 0),
        ];
    }

    $recentStatement = $db->prepare(
        "SELECT ticket_no,
                opened_at,
                subscriber_code,
                customer_name,
                phone,
                area,
                subject,
                status,
                resolution_reason,
                resolved_at,
                COALESCE(NULLIF(created_by_name, ''), 'Bilinmir') AS opened_by,
                COALESCE(NULLIF(assigned_technician_name, ''), '-') AS assigned_to
           FROM portal_tickets
          WHERE opened_at BETWEEN :start AND :end
          ORDER BY opened_at DESC, id DESC
          LIMIT 200"
    );
    $recentStatement->execute($params);
    $topReason = isset($problemBreakdown[0]) ? (string) $problemBreakdown[0]['reason'] : '-';
    $topReasonCount = isset($problemBreakdown[0]) ? (int) $problemBreakdown[0]['total'] : 0;

    return [
        'reasons' => $reasons,
        'periods' => $periodLabels,
        'datasets' => $datasets,
        'rows' => $rows,
        'totals' => $totals,
        'total_count' => $totalCount,
        'open_count' => $openCount,
        'closed_count' => $closedCount,
        'hourly' => array_values($hourly),
        'top_hour' => $topHour,
        'top_hour_count' => $topHourCount,
        'top_reason' => $topReason,
        'top_reason_count' => $topReasonCount,
        'problem_breakdown' => $problemBreakdown,
        'employee_breakdown' => $employeeBreakdown,
        'recent_tickets' => $recentStatement->fetchAll(),
        'from' => substr($start, 0, 10),
        'to' => substr($end, 0, 10),
        'group_by' => $groupBy,
    ];
}

function app_db_bucket_label(string $dateTime, string $groupBy): string
{
    $timestamp = strtotime($dateTime);
    if ($timestamp === false) {
        return '';
    }

    return match ($groupBy) {
        'month' => date('Y-m', $timestamp),
        'week' => date('o-\\WW', $timestamp),
        default => date('Y-m-d', $timestamp),
    };
}

function app_db_import_rows_from_file(string $path, string $extension): array
{
    return match (strtolower($extension)) {
        'csv' => app_db_import_csv_rows($path),
        'xlsx' => app_db_import_xlsx_rows($path),
        default => [],
    };
}

function app_db_import_csv_rows(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }

    $rows = [];
    $headers = null;
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if ($headers === null) {
            $headers = array_map(static fn ($value): string => trim((string) $value), $row);
            continue;
        }

        $assoc = [];
        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }
            $assoc[$header] = trim((string) ($row[$index] ?? ''));
        }
        if ($assoc !== []) {
            $rows[] = $assoc;
        }
    }
    fclose($handle);

    return $rows;
}

function app_db_import_xlsx_rows(string $path): array
{
    if (!class_exists('ZipArchive')) {
        return [];
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return [];
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if (is_string($sharedXml) && $sharedXml !== '') {
        $xml = @simplexml_load_string($sharedXml);
        if ($xml !== false) {
            $main = $xml->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($main->si as $item) {
                $text = '';
                foreach ($item->t as $t) {
                    $text .= (string) $t;
                }
                foreach ($item->r as $run) {
                    $text .= (string) ($run->t ?? '');
                }
                $sharedStrings[] = $text;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!is_string($sheetXml) || $sheetXml === '') {
        return [];
    }

    $xml = @simplexml_load_string($sheetXml);
    if ($xml === false) {
        return [];
    }
    $main = $xml->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    if (!isset($main->sheetData)) {
        return [];
    }

    $rows = [];
    $headers = null;
    foreach ($main->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $cell) {
            $ref = (string) ($cell['r'] ?? '');
            preg_match('/([A-Z]+)/', $ref, $matches);
            $col = $matches[1] ?? 'A';
            $index = app_db_xlsx_col_to_index($col);
            $type = (string) ($cell['t'] ?? '');
            $value = (string) ($cell->v ?? '');
            if ($type === 's') {
                $value = $sharedStrings[(int) $value] ?? '';
            }
            $cells[$index] = trim($value);
        }

        if ($cells === []) {
            continue;
        }

        ksort($cells);
        $flat = array_values($cells);
        if ($headers === null) {
            $headers = $flat;
            continue;
        }

        $assoc = [];
        foreach ($headers as $index => $header) {
            $header = trim((string) $header);
            if ($header === '') {
                continue;
            }
            $assoc[$header] = trim((string) ($flat[$index] ?? ''));
        }
        if ($assoc !== []) {
            $rows[] = $assoc;
        }
    }

    return $rows;
}

function app_db_xlsx_col_to_index(string $column): int
{
    $column = strtoupper($column);
    $index = 0;
    for ($i = 0; $i < strlen($column); $i++) {
        $index = $index * 26 + (ord($column[$i]) - 64);
    }
    return max(0, $index - 1);
}

function app_db_normalize_import_row(array $row): array
{
    $normalized = [];
    foreach ($row as $key => $value) {
        $normalized[strtolower(trim((string) $key))] = trim((string) $value);
    }

    $role = $normalized['role'] ?? ($normalized['type'] ?? 'customer');
    $role = in_array($role, ['customer', 'employee'], true)
        ? $role
        : ($role === 'operator' || $role === 'technician' || $role === 'admin' ? 'employee' : 'customer');

    $accountType = $normalized['account_type'] ?? ($normalized['type'] ?? ($role === 'customer' ? 'customer' : 'operator'));
    if ($role === 'customer') {
        $accountType = 'customer';
    }

    $employeeRole = $normalized['employee_role'] ?? match ($accountType) {
        'admin' => 'admin',
        'technician' => 'technician',
        default => 'support',
    };

    $login = $normalized['login'] ?? ($normalized['subscriber_code'] ?? '');
    if ($login === '') {
        $name = $normalized['full_name'] ?? ($normalized['name'] ?? 'user');
        $login = app_db_slug_login($name, $role === 'employee' ? '@superoptic.net' : '');
    }

    $importPayload = [];
    if ($role === 'employee') {
        $importPayload['employee'] = [
            'telegram_chat_id' => $normalized['telegram_chat_id'] ?? '',
            'superpilot_enabled' => true,
        ];
    }

    return [
        'login' => $login,
        'password' => $normalized['password'] ?? '',
        'role' => $role,
        'account_type' => $accountType,
        'employee_role' => $employeeRole,
        'full_name' => $normalized['full_name'] ?? ($normalized['name'] ?? ''),
        'subscriber_code' => $normalized['subscriber_code'] ?? '',
        'contract_no' => $normalized['contract_no'] ?? '',
        'email' => $normalized['email'] ?? '',
        'phone' => $normalized['phone'] ?? '',
        'category' => $normalized['category'] ?? '',
        'area' => $normalized['area'] ?? '',
        'address' => $normalized['address'] ?? '',
        'plan' => $normalized['plan'] ?? '',
        'status' => $normalized['status'] ?? 'Aktiv',
        'responsible_areas' => preg_split('/\s*,\s*/', (string) ($normalized['areas'] ?? $normalized['responsible_areas'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        'payload' => $importPayload,
        'is_active' => (($normalized['is_active'] ?? '1') === '0') ? 0 : 1,
    ];
}

function app_db_import_accounts(array $rows, bool $syncOlt = true): array
{
    $result = ['created' => 0, 'updated' => 0, 'errors' => []];

    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $payload = app_db_normalize_import_row($row);
        if (($payload['full_name'] ?? '') === '' || ($payload['login'] ?? '') === '') {
            $result['errors'][] = 'Setir ' . ($index + 2) . ': login ve ya ad bosdur.';
            continue;
        }

        $existing = app_db_account_by_login((string) $payload['login']);
        $saved = app_db_upsert_account($payload, $syncOlt);
        if ($saved === null) {
            $result['errors'][] = 'Setir ' . ($index + 2) . ': hesab saxlanmadi.';
            continue;
        }

        if ($existing === null) {
            $result['created']++;
        } else {
            $result['updated']++;
        }
    }

    return $result;
}

function app_billing_seed_defaults(PDO $db): void
{
    $tariffs = [
        ['100 Mb/s Internet', 'pppoe', 100, 50, 25.00, 'tariff-100m'],
        ['150 Mb/s Internet', 'pppoe', 150, 75, 30.00, 'tariff-150m'],
        ['200 Mb/s Internet', 'pppoe', 200, 100, 35.00, 'tariff-200m'],
        ['100 Mb/s IPoE', 'ipoe', 100, 50, 25.00, 'tariff-ipoe-100m'],
    ];
    $tariffStatement = $db->prepare(
        'INSERT INTO billing_tariffs (name, service_type, download_mbps, upload_mbps, monthly_fee, radius_group)
         VALUES (:name, :service_type, :download_mbps, :upload_mbps, :monthly_fee, :radius_group)
         ON DUPLICATE KEY UPDATE
            download_mbps = VALUES(download_mbps),
            upload_mbps = VALUES(upload_mbps),
            monthly_fee = VALUES(monthly_fee),
            radius_group = VALUES(radius_group),
            status = "active"'
    );
    foreach ($tariffs as $tariff) {
        $tariffStatement->execute([
            'name' => $tariff[0],
            'service_type' => $tariff[1],
            'download_mbps' => $tariff[2],
            'upload_mbps' => $tariff[3],
            'monthly_fee' => $tariff[4],
            'radius_group' => $tariff[5],
        ]);
    }

    $pools = [
        ['Pool 1 - Core', 'pppoe', 'dynamic', '10.11.20.10', '10.11.20.254', '10.11.20.1', '255.255.255.0'],
        ['Static Pool 1 - Core', 'pppoe', 'static', '10.11.30.10', '10.11.30.254', '10.11.30.1', '255.255.255.0'],
        ['Static Public 94.20.46', 'pppoe', 'static', '94.20.46.5', '94.20.46.63', '94.20.46.1', '255.255.255.0'],
        ['IPoE Pool 1100', 'ipoe', 'dynamic', '10.110.0.10', '10.110.0.254', '10.110.0.1', '255.255.255.0'],
    ];
    $poolStatement = $db->prepare(
        'INSERT INTO billing_ip_pools (name, service_type, pool_type, range_start, range_end, gateway, netmask)
         VALUES (:name, :service_type, :pool_type, :range_start, :range_end, :gateway, :netmask)
         ON DUPLICATE KEY UPDATE
            service_type = VALUES(service_type),
            pool_type = VALUES(pool_type),
            range_start = VALUES(range_start),
            range_end = VALUES(range_end),
            gateway = VALUES(gateway),
            netmask = VALUES(netmask),
            status = "active"'
    );
    foreach ($pools as $pool) {
        $poolStatement->execute([
            'name' => $pool[0],
            'service_type' => $pool[1],
            'pool_type' => $pool[2],
            'range_start' => $pool[3],
            'range_end' => $pool[4],
            'gateway' => $pool[5],
            'netmask' => $pool[6],
        ]);
    }

    app_ip_seed_from_billing($db);
}

function app_billing_tariff_for_plan(PDO $db, string $plan, string $serviceType = 'pppoe'): ?array
{
    $statement = $db->prepare(
        'SELECT * FROM billing_tariffs
         WHERE service_type = :service_type
           AND (name = :plan OR :plan LIKE CONCAT("%", download_mbps, "%"))
           AND status = "active"
         ORDER BY name = :plan DESC, download_mbps DESC
         LIMIT 1'
    );
    $statement->execute([
        'service_type' => $serviceType,
        'plan' => $plan,
    ]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function app_db_customer_lookup_for_ticket(
    int $accountId = 0,
    string $subscriberCode = '',
    string $phone = '',
    string $fullName = ''
): ?array {
    $db = app_db();
    if (!$db instanceof PDO) {
        return null;
    }

    if ($accountId > 0) {
        $statement = $db->prepare(
            "SELECT *
             FROM portal_accounts
             WHERE id = :id
               AND role = 'customer'
             LIMIT 1"
        );
        $statement->execute(['id' => $accountId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
    }

    $subscriberCode = trim($subscriberCode);
    if ($subscriberCode !== '') {
        $statement = $db->prepare(
            "SELECT *
             FROM portal_accounts
             WHERE role = 'customer'
               AND (subscriber_code = :subscriber_code OR contract_no = :subscriber_code OR login = :subscriber_code)
             ORDER BY is_active DESC, id DESC
             LIMIT 1"
        );
        $statement->execute(['subscriber_code' => $subscriberCode]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
    }

    $phone = normalize_phone($phone);
    if ($phone !== '') {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $statement = $db->prepare(
            "SELECT *
             FROM portal_accounts
             WHERE role = 'customer'
               AND REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), '+', ''), '-', ''), ' ', ''), '(', '') LIKE :phone
             ORDER BY is_active DESC, id DESC
             LIMIT 1"
        );
        $statement->execute(['phone' => '%' . $digits . '%']);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
    }

    $fullName = trim($fullName);
    if ($fullName !== '') {
        $statement = $db->prepare(
            "SELECT *
             FROM portal_accounts
             WHERE role = 'customer'
               AND full_name = :full_name
             ORDER BY is_active DESC, id DESC
             LIMIT 1"
        );
        $statement->execute(['full_name' => $fullName]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
    }

    return null;
}

function app_billing_pool_by_name(PDO $db, string $name, string $serviceType = 'pppoe'): ?array
{
    $statement = $db->prepare(
        'SELECT * FROM billing_ip_pools
         WHERE name = :name AND service_type = :service_type AND status = "active"
         LIMIT 1'
    );
    $statement->execute([
        'name' => $name,
        'service_type' => $serviceType,
    ]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function app_billing_radius_metadata_rows(?array $tariff, string $key): array
{
    if (!is_array($tariff)) {
        return [];
    }

    $metadata = app_db_json_decode((string) ($tariff['metadata_json'] ?? ''), []);
    if (!is_array($metadata)) {
        return [];
    }

    $candidates = [];
    if (isset($metadata[$key])) {
        $candidates[] = $metadata[$key];
    }
    $legacyKey = str_replace('_attributes', '', $key);
    if ($legacyKey !== $key && isset($metadata[$legacyKey])) {
        $candidates[] = $metadata[$legacyKey];
    }

    foreach ($candidates as $rows) {
        if (!is_array($rows)) {
            continue;
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $attribute = trim((string) ($row['attribute'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            $op = trim((string) ($row['op'] ?? '='));
            if ($attribute === '' || $value === '') {
                continue;
            }

            $normalized[] = [
                'attribute' => $attribute,
                'op' => $op !== '' ? $op : '=',
                'value' => $value,
            ];
        }

        if ($normalized !== []) {
            return $normalized;
        }
    }

    return [];
}

function app_billing_next_available_ip(int $poolId): ?string
{
    $db = app_db();
    if (!$db instanceof PDO || $poolId <= 0) {
        return null;
    }

    $statement = $db->prepare('SELECT * FROM billing_ip_pools WHERE id = :id AND status = "active" LIMIT 1');
    $statement->execute(['id' => $poolId]);
    $pool = $statement->fetch();
    if (!is_array($pool)) {
        return null;
    }

    $start = ip2long((string) ($pool['range_start'] ?? ''));
    $end = ip2long((string) ($pool['range_end'] ?? ''));
    if ($start === false || $end === false || $end < $start) {
        return null;
    }

    $usedStatement = $db->prepare(
        'SELECT assigned_ip, static_ip FROM billing_radius_services
         WHERE ip_pool_id = :pool_id
           AND status IN ("active","suspended","expired")'
    );
    $usedStatement->execute(['pool_id' => $poolId]);
    $used = [];
    foreach ($usedStatement->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach (['assigned_ip', 'static_ip'] as $field) {
            $ip = trim((string) ($row[$field] ?? ''));
            if ($ip !== '') {
                $used[$ip] = true;
            }
        }
    }

    $poolBridge = $db->prepare('SELECT id FROM ip_pools WHERE legacy_billing_pool_id = :legacy_id LIMIT 1');
    $poolBridge->execute(['legacy_id' => $poolId]);
    $enginePoolId = (int) ($poolBridge->fetchColumn() ?: 0);
    if ($enginePoolId > 0) {
        app_ip_ensure_pool_addresses($db, $enginePoolId);
        $addressStatement = $db->prepare(
            'SELECT ip_address FROM ip_addresses
             WHERE pool_id = :pool_id AND status IN ("used","reserved","blacklist")'
        );
        $addressStatement->execute(['pool_id' => $enginePoolId]);
        foreach ($addressStatement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $ip) {
            $ip = trim((string) $ip);
            if ($ip !== '') {
                $used[$ip] = true;
            }
        }
    }

    for ($candidate = $start; $candidate <= $end; $candidate++) {
        $ip = long2ip($candidate);
        if ($ip !== false && !app_ip_is_assignable_ipv4_host($ip)) {
            continue;
        }
        if ($ip !== false && !isset($used[$ip])) {
            return $ip;
        }
    }

    return null;
}

function app_ip_is_assignable_ipv4_host(string $ip): bool
{
    $ip = trim($ip);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }

    $parts = explode('.', $ip);
    $lastOctet = (int) ($parts[3] ?? -1);

    // Avoid common per-/24 network, gateway and broadcast addresses in broad legacy pools.
    return !in_array($lastOctet, [0, 1, 255], true);
}

function app_ip_to_num(string $ip): ?int
{
    $long = ip2long(trim($ip));
    if ($long === false) {
        return null;
    }

    return (int) sprintf('%u', $long);
}

function app_ip_is_public_unicast(string $ip): bool
{
    $ip = trim($ip);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return false;
    }

    $num = app_ip_to_num($ip);
    $cgnStart = app_ip_to_num('100.64.0.0');
    $cgnEnd = app_ip_to_num('100.127.255.255');
    if ($num === null || $cgnStart === null || $cgnEnd === null) {
        return false;
    }

    return $num < $cgnStart || $num > $cgnEnd;
}

function app_ip_range_total(string $startIp, string $endIp): int
{
    $start = app_ip_to_num($startIp);
    $end = app_ip_to_num($endIp);
    if ($start === null || $end === null || $end < $start) {
        return 0;
    }

    return $end - $start + 1;
}

function app_ip_pool_type_from_legacy(array $pool): string
{
    $poolType = strtolower((string) ($pool['pool_type'] ?? 'dynamic'));
    $name = strtolower((string) ($pool['name'] ?? ''));
    if ($poolType === 'static' || str_contains($name, 'static')) {
        return 'static_public';
    }

    if (str_contains($name, 'nat') || str_contains($name, 'public')) {
        return 'nat_public';
    }

    return 'local';
}

function app_ip_sync_legacy_pools(PDO $db): void
{
    $rows = $db->query('SELECT * FROM billing_ip_pools ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return;
    }

    $statement = $db->prepare(
        'INSERT INTO ip_pools (
            legacy_billing_pool_id, name, type, service_type, start_ip, end_ip, start_ip_num, end_ip_num,
            gateway, netmask, status, metadata_json
         ) VALUES (
            :legacy_billing_pool_id, :name, :type, :service_type, :start_ip, :end_ip, :start_ip_num, :end_ip_num,
            :gateway, :netmask, :status, :metadata_json
         )
         ON DUPLICATE KEY UPDATE
            legacy_billing_pool_id = VALUES(legacy_billing_pool_id),
            type = VALUES(type),
            service_type = VALUES(service_type),
            start_ip = VALUES(start_ip),
            end_ip = VALUES(end_ip),
            start_ip_num = VALUES(start_ip_num),
            end_ip_num = VALUES(end_ip_num),
            gateway = VALUES(gateway),
            netmask = VALUES(netmask),
            status = VALUES(status),
            metadata_json = VALUES(metadata_json)'
    );

    foreach ($rows as $row) {
        $startIp = (string) ($row['range_start'] ?? '');
        $endIp = (string) ($row['range_end'] ?? '');
        $startNum = app_ip_to_num($startIp);
        $endNum = app_ip_to_num($endIp);
        if ($startNum === null || $endNum === null || $endNum < $startNum) {
            continue;
        }

        $statement->execute([
            'legacy_billing_pool_id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'type' => app_ip_pool_type_from_legacy($row),
            'service_type' => in_array((string) ($row['service_type'] ?? 'pppoe'), ['pppoe', 'ipoe'], true) ? (string) $row['service_type'] : 'pppoe',
            'start_ip' => $startIp,
            'end_ip' => $endIp,
            'start_ip_num' => $startNum,
            'end_ip_num' => $endNum,
            'gateway' => (string) ($row['gateway'] ?? ''),
            'netmask' => (string) ($row['netmask'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active') === 'active' ? 'active' : 'disabled',
            'metadata_json' => app_db_json_encode(['source' => 'billing_ip_pools', 'legacy_pool_type' => (string) ($row['pool_type'] ?? '')]),
        ]);
    }
}

function app_ip_ensure_pool_addresses(PDO $db, int $poolId, int $limit = 4096): int
{
    if ($poolId <= 0) {
        return 0;
    }

    $statement = $db->prepare('SELECT * FROM ip_pools WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $poolId]);
    $pool = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($pool)) {
        return 0;
    }

    $start = (int) ($pool['start_ip_num'] ?? 0);
    $end = (int) ($pool['end_ip_num'] ?? 0);
    if ($start <= 0 || $end < $start) {
        return 0;
    }

    $total = $end - $start + 1;
    if ($total > $limit) {
        return 0;
    }

    $insert = $db->prepare(
        'INSERT IGNORE INTO ip_addresses (pool_id, ip_address, ip_num, status)
         VALUES (:pool_id, :ip_address, :ip_num, "free")'
    );
    $created = 0;
    for ($num = $start; $num <= $end; $num++) {
        $ip = long2ip($num);
        if ($ip === false || !app_ip_is_assignable_ipv4_host($ip)) {
            continue;
        }
        $insert->execute([
            'pool_id' => $poolId,
            'ip_address' => $ip,
            'ip_num' => $num,
        ]);
        $created += $insert->rowCount() > 0 ? 1 : 0;
    }

    return $created;
}

function app_ip_seed_from_billing(PDO $db): void
{
    app_ip_sync_legacy_pools($db);
    $rows = $db->query('SELECT id FROM ip_pools WHERE status = "active" ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        app_ip_ensure_pool_addresses($db, (int) ($row['id'] ?? 0));
    }
}

function app_ip_pool_summaries(PDO $db): array
{
    $rows = $db->query(
        'SELECT p.*,
                SUM(CASE WHEN a.status = "used" THEN 1 ELSE 0 END) AS used_count,
                SUM(CASE WHEN a.status = "free" THEN 1 ELSE 0 END) AS free_count,
                SUM(CASE WHEN a.status = "reserved" THEN 1 ELSE 0 END) AS reserved_count,
                SUM(CASE WHEN a.status = "blacklist" THEN 1 ELSE 0 END) AS blacklist_count
         FROM ip_pools p
         LEFT JOIN ip_addresses a ON a.pool_id = p.id
         GROUP BY p.id
         ORDER BY FIELD(p.type, "local", "nat_public", "static_public"), p.name'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['total_count'] = app_ip_range_total((string) ($row['start_ip'] ?? ''), (string) ($row['end_ip'] ?? ''));
        foreach (['used_count', 'free_count', 'reserved_count', 'blacklist_count'] as $key) {
            $row[$key] = (int) ($row[$key] ?? 0);
        }
        if ((int) $row['free_count'] === 0 && (int) $row['total_count'] > 0) {
            $tracked = (int) $row['used_count'] + (int) $row['reserved_count'] + (int) $row['blacklist_count'];
            $row['free_count'] = max(0, (int) $row['total_count'] - $tracked);
        }
        $row['utilization_percent'] = (int) $row['total_count'] > 0
            ? round(((int) $row['used_count'] + (int) $row['reserved_count']) / (int) $row['total_count'] * 100, 1)
            : 0.0;
    }
    unset($row);

    return $rows;
}

function app_ip_available_addresses(PDO $db, string $type = '', int $limit = 100, int $poolId = 0): array
{
    $where = ['p.status = "active"'];
    $params = [];
    if ($type !== '') {
        $where[] = 'p.type = :type';
        $params['type'] = $type;
    }
    if ($poolId > 0) {
        $where[] = 'p.id = :pool_id';
        $params['pool_id'] = $poolId;
    }

    $sql = 'SELECT a.*, p.name AS pool_name, p.type AS pool_type
            FROM ip_addresses a
            INNER JOIN ip_pools p ON p.id = a.pool_id
            WHERE a.status = "free" AND ' . implode(' AND ', $where) . '
            ORDER BY p.type, p.name, a.ip_num
            LIMIT ' . max(1, min(500, $limit));
    $statement = $db->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function app_ip_find_pool_for_ip(PDO $db, string $ip, string $type = ''): ?array
{
    $ipNum = app_ip_to_num($ip);
    if ($ipNum === null) {
        return null;
    }

    $sql = 'SELECT * FROM ip_pools WHERE status = "active" AND start_ip_num <= :ip_num AND end_ip_num >= :ip_num';
    $params = ['ip_num' => $ipNum];
    if ($type !== '') {
        $sql .= ' AND type = :type';
        $params['type'] = $type;
    }
    $sql .= ' ORDER BY FIELD(type, "static_public", "nat_public", "local"), id LIMIT 1';
    $statement = $db->prepare($sql);
    $statement->execute($params);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function app_ip_mark_address(PDO $db, string $ip, string $status, ?int $customerId = null, string $username = '', string $note = '', string $type = ''): bool
{
    $ip = trim($ip);
    $ipNum = app_ip_to_num($ip);
    if ($ip === '' || $ipNum === null || !in_array($status, ['free', 'used', 'reserved', 'blacklist'], true)) {
        return false;
    }

    $pool = app_ip_find_pool_for_ip($db, $ip, $type);
    if (!is_array($pool)) {
        return false;
    }

    $statement = $db->prepare(
        'INSERT INTO ip_addresses (pool_id, ip_address, ip_num, status, customer_id, service_username, assigned_at, released_at, note)
         VALUES (:pool_id, :ip_address, :ip_num, :status, :customer_id, :service_username, :assigned_at, :released_at, :note)
         ON DUPLICATE KEY UPDATE
            pool_id = VALUES(pool_id),
            status = VALUES(status),
            customer_id = VALUES(customer_id),
            service_username = VALUES(service_username),
            assigned_at = VALUES(assigned_at),
            released_at = VALUES(released_at),
            note = VALUES(note)'
    );

    return $statement->execute([
        'pool_id' => (int) $pool['id'],
        'ip_address' => $ip,
        'ip_num' => $ipNum,
        'status' => $status,
        'customer_id' => $status === 'used' || $status === 'reserved' ? $customerId : null,
        'service_username' => $status === 'used' || $status === 'reserved' ? $username : null,
        'assigned_at' => $status === 'used' || $status === 'reserved' ? date('Y-m-d H:i:s') : null,
        'released_at' => $status === 'free' ? date('Y-m-d H:i:s') : null,
        'note' => $note,
    ]);
}

function app_ip_validate_customer_ips(PDO $db, string $localIp = '', string $natIp = '', string $staticIp = '', int $customerId = 0): ?array
{
    $checks = [
        'local_ip' => ['ip' => trim($localIp), 'type' => 'local', 'label' => 'Local/CGNAT IP'],
        'nat_public_ip' => ['ip' => trim($natIp), 'type' => 'nat_public', 'label' => 'NAT public IP'],
        'static_ip' => ['ip' => trim($staticIp), 'type' => 'static_public', 'label' => 'Static public IP'],
    ];
    $seen = [];

    foreach ($checks as $field => $check) {
        $ip = (string) $check['ip'];
        if ($ip === '') {
            continue;
        }
        if (isset($seen[$ip])) {
            return [
                'field' => $field,
                'ip' => $ip,
                'message' => 'Eyni IP bir musteri assignment daxilinde iki ferqli rolda yazila bilmez.',
            ];
        }
        $seen[$ip] = true;

        if (app_ip_to_num($ip) === null) {
            return [
                'field' => $field,
                'ip' => $ip,
                'message' => (string) $check['label'] . ' formati duzgun deyil.',
            ];
        }

        $pool = app_ip_find_pool_for_ip($db, $ip, (string) $check['type']);
        if (!is_array($pool)) {
            return [
                'field' => $field,
                'ip' => $ip,
                'message' => (string) $check['label'] . ' uygun aktiv IP pool daxilinde deyil. Evvel IP pool yaradilmali ve ya duzgun tip secilmelidir.',
            ];
        }

        $statement = $db->prepare(
            'SELECT status, customer_id
             FROM ip_addresses
             WHERE ip_address = :ip_address
             LIMIT 1'
        );
        $statement->execute(['ip_address' => $ip]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $status = (string) ($row['status'] ?? '');
            $ownerId = (int) ($row['customer_id'] ?? 0);
            if (in_array($status, ['used', 'reserved', 'blacklist'], true) && ($ownerId <= 0 || $ownerId !== $customerId)) {
                return [
                    'field' => $field,
                    'ip' => $ip,
                    'message' => 'IP conflict: ' . $ip . ' status=' . $status,
                ];
            }
        }
    }

    return null;
}

function app_ip_customer_assignments(PDO $db, int $customerId): array
{
    if ($customerId <= 0) {
        return [];
    }

    $statement = $db->prepare(
        'SELECT * FROM customer_ip_assignments
         WHERE customer_id = :customer_id AND active = 1
         ORDER BY assigned_at DESC, id DESC'
    );
    $statement->execute(['customer_id' => $customerId]);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function app_ip_customer_history(PDO $db, int $customerId, int $limit = 20): array
{
    if ($customerId <= 0) {
        return [];
    }

    $statement = $db->prepare(
        'SELECT h.*, a.full_name AS changed_by_name
         FROM ip_assignment_history h
         LEFT JOIN portal_accounts a ON a.id = h.changed_by
         WHERE h.customer_id = :customer_id
         ORDER BY h.changed_at DESC, h.id DESC
         LIMIT ' . max(1, min(100, $limit))
    );
    $statement->execute(['customer_id' => $customerId]);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function app_ip_record_customer_assignment(
    PDO $db,
    int $customerId,
    string $username,
    string $localIp = '',
    string $natIp = '',
    string $staticIp = '',
    int $changedBy = 0,
    string $reason = 'radius_sync'
): void {
    if ($customerId <= 0 || trim($username) === '') {
        return;
    }

    $localIp = trim($localIp);
    $natIp = trim($natIp);
    $staticIp = trim($staticIp);
    $assignmentType = $staticIp !== '' ? 'static' : ($natIp !== '' ? 'nat_mapped' : 'dynamic');

    $statement = $db->prepare(
        'SELECT * FROM customer_ip_assignments
         WHERE customer_id = :customer_id AND service_username = :service_username AND active = 1
         ORDER BY id DESC LIMIT 1'
    );
    $statement->execute([
        'customer_id' => $customerId,
        'service_username' => $username,
    ]);
    $previous = $statement->fetch(PDO::FETCH_ASSOC);
    $oldLocal = is_array($previous) ? (string) ($previous['local_ip'] ?? '') : '';
    $oldNat = is_array($previous) ? (string) ($previous['nat_public_ip'] ?? '') : '';
    $oldStatic = is_array($previous) ? (string) ($previous['static_ip'] ?? '') : '';

    if ($oldLocal === $localIp && $oldNat === $natIp && $oldStatic === $staticIp) {
        $update = $db->prepare(
            'UPDATE customer_ip_assignments
             SET radius_synced = 1, metadata_json = :metadata_json
             WHERE id = :id'
        );
        if (is_array($previous)) {
            $update->execute([
                'id' => (int) $previous['id'],
                'metadata_json' => app_db_json_encode(['last_radius_sync_at' => date('Y-m-d H:i:s')]),
            ]);
        }
        return;
    }

    if (is_array($previous)) {
        $close = $db->prepare('UPDATE customer_ip_assignments SET active = 0, released_at = NOW() WHERE id = :id');
        $close->execute(['id' => (int) $previous['id']]);
    }

    $insert = $db->prepare(
        'INSERT INTO customer_ip_assignments (
            customer_id, service_username, local_ip, nat_public_ip, static_ip, assignment_type,
            radius_synced, active, assigned_by, metadata_json
         ) VALUES (
            :customer_id, :service_username, :local_ip, :nat_public_ip, :static_ip, :assignment_type,
            1, 1, :assigned_by, :metadata_json
         )'
    );
    $insert->execute([
        'customer_id' => $customerId,
        'service_username' => $username,
        'local_ip' => $localIp !== '' ? $localIp : null,
        'nat_public_ip' => $natIp !== '' ? $natIp : null,
        'static_ip' => $staticIp !== '' ? $staticIp : null,
        'assignment_type' => $assignmentType,
        'assigned_by' => $changedBy > 0 ? $changedBy : null,
        'metadata_json' => app_db_json_encode(['reason' => $reason]),
    ]);

    $history = $db->prepare(
        'INSERT INTO ip_assignment_history (
            customer_id, service_username, old_local_ip, new_local_ip, old_nat_ip, new_nat_ip,
            old_static_ip, new_static_ip, reason, changed_by
         ) VALUES (
            :customer_id, :service_username, :old_local_ip, :new_local_ip, :old_nat_ip, :new_nat_ip,
            :old_static_ip, :new_static_ip, :reason, :changed_by
         )'
    );
    $history->execute([
        'customer_id' => $customerId,
        'service_username' => $username,
        'old_local_ip' => $oldLocal !== '' ? $oldLocal : null,
        'new_local_ip' => $localIp !== '' ? $localIp : null,
        'old_nat_ip' => $oldNat !== '' ? $oldNat : null,
        'new_nat_ip' => $natIp !== '' ? $natIp : null,
        'old_static_ip' => $oldStatic !== '' ? $oldStatic : null,
        'new_static_ip' => $staticIp !== '' ? $staticIp : null,
        'reason' => $reason,
        'changed_by' => $changedBy > 0 ? $changedBy : null,
    ]);

    foreach ([[$oldLocal, 'local'], [$oldNat, 'nat_public'], [$oldStatic, 'static_public']] as $oldIpMeta) {
        [$oldIp, $oldType] = $oldIpMeta;
        if ($oldIp !== '' && !in_array($oldIp, [$localIp, $natIp, $staticIp], true)) {
            app_ip_mark_address($db, $oldIp, 'free', null, '', 'Released by new assignment', $oldType);
        }
    }
    if ($localIp !== '') {
        app_ip_mark_address($db, $localIp, 'used', $customerId, $username, 'Local IP assigned from billing', 'local');
    }
    if ($natIp !== '') {
        app_ip_mark_address($db, $natIp, 'used', $customerId, $username, 'NAT public IP assigned from billing', 'nat_public');
    }
    if ($staticIp !== '') {
        app_ip_mark_address($db, $staticIp, 'used', $customerId, $username, 'Static public IP assigned from billing', 'static_public');
    }
}

function app_ip_release_customer_assignment(PDO $db, int $customerId, string $username = '', int $changedBy = 0, string $reason = 'IP release'): int
{
    if ($customerId <= 0) {
        return 0;
    }

    $sql = 'SELECT * FROM customer_ip_assignments WHERE customer_id = :customer_id AND active = 1';
    $params = ['customer_id' => $customerId];
    if (trim($username) !== '') {
        $sql .= ' AND service_username = :service_username';
        $params['service_username'] = trim($username);
    }
    $sql .= ' ORDER BY id DESC';
    $statement = $db->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return 0;
    }

    $close = $db->prepare('UPDATE customer_ip_assignments SET active = 0, released_at = NOW() WHERE id = :id');
    $history = $db->prepare(
        'INSERT INTO ip_assignment_history (
            customer_id, service_username, old_local_ip, new_local_ip, old_nat_ip, new_nat_ip,
            old_static_ip, new_static_ip, reason, changed_by
         ) VALUES (
            :customer_id, :service_username, :old_local_ip, NULL, :old_nat_ip, NULL,
            :old_static_ip, NULL, :reason, :changed_by
         )'
    );

    $released = 0;
    foreach ($rows as $row) {
        $localIp = trim((string) ($row['local_ip'] ?? ''));
        $natIp = trim((string) ($row['nat_public_ip'] ?? ''));
        $staticIp = trim((string) ($row['static_ip'] ?? ''));
        $serviceUsername = trim((string) ($row['service_username'] ?? $username));

        $close->execute(['id' => (int) $row['id']]);
        $history->execute([
            'customer_id' => $customerId,
            'service_username' => $serviceUsername !== '' ? $serviceUsername : null,
            'old_local_ip' => $localIp !== '' ? $localIp : null,
            'old_nat_ip' => $natIp !== '' ? $natIp : null,
            'old_static_ip' => $staticIp !== '' ? $staticIp : null,
            'reason' => $reason,
            'changed_by' => $changedBy > 0 ? $changedBy : null,
        ]);

        if ($localIp !== '') {
            app_ip_mark_address($db, $localIp, 'free', null, '', 'Released from customer profile', 'local');
        }
        if ($natIp !== '') {
            app_ip_mark_address($db, $natIp, 'free', null, '', 'Released from customer profile', 'nat_public');
        }
        if ($staticIp !== '') {
            app_ip_mark_address($db, $staticIp, 'free', null, '', 'Released from customer profile', 'static_public');
        }
        $released++;
    }

    return $released;
}

function app_radius_request_disconnect(PDO $db, array $session, array $user, string $reason = ''): int
{
    $username = trim((string) ($session['username'] ?? ''));
    if ($username === '') {
        return 0;
    }

    $accountId = (int) ($session['account_id'] ?? 0);
    $requestedBy = (int) ($user['id'] ?? 0);
    $framedIp = trim((string) ($session['framed_ip'] ?? $session['framedipaddress'] ?? ''));
    $nasIp = trim((string) ($session['nas_ip'] ?? $session['nasipaddress'] ?? ''));
    $nasPortId = trim((string) ($session['nas_port_id'] ?? $session['nasportid'] ?? ''));
    $callingStationId = trim((string) ($session['calling_station_id'] ?? $session['callingstationid'] ?? ''));
    $acctSessionId = trim((string) ($session['acct_session_id'] ?? $session['acctsessionid'] ?? ''));
    $acctUniqueSessionId = trim((string) ($session['acct_unique_session_id'] ?? $session['acctuniquesessionid'] ?? ''));
    $nasIdentifier = trim((string) ($session['nas_identifier'] ?? $session['nasidentifier'] ?? ''));
    $calledStationId = trim((string) ($session['called_station_id'] ?? $session['calledstationid'] ?? ''));
    $reason = trim($reason) !== '' ? trim($reason) : 'Operator disconnect request';

    $existing = $db->prepare(
        'SELECT id
         FROM radius_session_actions
         WHERE action_type = "disconnect_request"
           AND status = "pending"
           AND username = :username
           AND requested_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
         ORDER BY id DESC
         LIMIT 1'
    );
    $existing->execute(['username' => $username]);
    $existingId = (int) ($existing->fetchColumn() ?: 0);

    $radiusSettings = app_radius_settings();
    $disconnectReadiness = app_radius_disconnect_worker_readiness($radiusSettings);
    $safeRadiusSettings = $radiusSettings;
    unset($safeRadiusSettings['shared_secret']);
    $safeRadiusSettings['shared_secret_configured'] = trim((string) ($radiusSettings['shared_secret'] ?? '')) !== '';
    $metadata = [
        'source' => 'radius-active.php',
        'mode' => !empty($disconnectReadiness['ok']) ? 'queued_for_worker' : 'config_pending',
        'safety_note' => !empty($disconnectReadiness['ok'])
            ? 'Queue created for live CoA/disconnect worker.'
            : 'Disconnect queue is ready but worker/apply path still needs config: ' . (string) ($disconnectReadiness['reason'] ?? 'unknown'),
        'radius' => $safeRadiusSettings,
        'session' => [
            'username' => $username,
            'framed_ip' => $framedIp,
            'nas_ip' => $nasIp,
            'nas_port_id' => $nasPortId,
            'calling_station_id' => $callingStationId,
            'acct_session_id' => $acctSessionId,
            'acct_unique_session_id' => $acctUniqueSessionId,
            'nas_identifier' => $nasIdentifier,
            'called_station_id' => $calledStationId,
        ],
    ];

    if ($existingId > 0) {
        $update = $db->prepare(
            'UPDATE radius_session_actions
             SET account_id = :account_id,
                 framed_ip = :framed_ip,
                 nas_ip = :nas_ip,
                 nas_port_id = :nas_port_id,
                 calling_station_id = :calling_station_id,
                 reason = :reason,
                 requested_by_account_id = :requested_by,
                 requested_at = NOW(),
                 metadata_json = :metadata_json
             WHERE id = :id'
        );
        $update->execute([
            'account_id' => $accountId > 0 ? $accountId : null,
            'framed_ip' => $framedIp !== '' ? $framedIp : null,
            'nas_ip' => $nasIp !== '' ? $nasIp : null,
            'nas_port_id' => $nasPortId !== '' ? $nasPortId : null,
            'calling_station_id' => $callingStationId !== '' ? $callingStationId : null,
            'reason' => $reason,
            'requested_by' => $requestedBy > 0 ? $requestedBy : null,
            'metadata_json' => app_db_json_encode($metadata),
            'id' => $existingId,
        ]);

        return $existingId;
    }

    $insert = $db->prepare(
        'INSERT INTO radius_session_actions (
            action_type, status, account_id, username, framed_ip, nas_ip, nas_port_id,
            calling_station_id, reason, requested_by_account_id, metadata_json
         ) VALUES (
            "disconnect_request", "pending", :account_id, :username, :framed_ip, :nas_ip, :nas_port_id,
            :calling_station_id, :reason, :requested_by, :metadata_json
         )'
    );
    $insert->execute([
        'account_id' => $accountId > 0 ? $accountId : null,
        'username' => $username,
        'framed_ip' => $framedIp !== '' ? $framedIp : null,
        'nas_ip' => $nasIp !== '' ? $nasIp : null,
        'nas_port_id' => $nasPortId !== '' ? $nasPortId : null,
        'calling_station_id' => $callingStationId !== '' ? $callingStationId : null,
        'reason' => $reason,
        'requested_by' => $requestedBy > 0 ? $requestedBy : null,
        'metadata_json' => app_db_json_encode($metadata),
    ]);

    return (int) $db->lastInsertId();
}

function app_radius_settings(): array
{
    return [
        'router_ip' => trim((string) app_config('radius_router_ip', '172.16.5.5')),
        'server_ip' => trim((string) app_config('radius_server_ip', '10.99.99.10')),
        'shared_secret' => trim((string) app_config('radius_shared_secret', '')),
        'core_nas_ip' => trim((string) app_config('radius_core_nas_ip', '10.99.99.1')),
        'auth_port' => max(1, (int) app_config('radius_auth_port', '1812')),
        'acct_port' => max(1, (int) app_config('radius_acct_port', '1813')),
        'coa_port' => max(1, (int) app_config('radius_coa_port', '3799')),
        'client_name' => trim((string) app_config('radius_client_name', 'RADIUS-INTERNAL')),
        'source_interface' => trim((string) app_config('radius_source_interface', 'FastEthernet1/3/1')),
        'dynamic_author_client_ip' => trim((string) app_config('radius_dynamic_author_client_ip', '10.99.99.10')),
        'coa_runner' => trim((string) app_config('radius_coa_runner', 'radclient')),
    ];
}

function app_radius_disconnect_worker_readiness(?array $settings = null): array
{
    $settings ??= app_radius_settings();
    if (trim((string) ($settings['router_ip'] ?? '')) === '') {
        return ['ok' => false, 'reason' => 'missing_router_ip'];
    }
    if (trim((string) ($settings['server_ip'] ?? '')) === '') {
        return ['ok' => false, 'reason' => 'missing_server_ip'];
    }
    if (trim((string) ($settings['shared_secret'] ?? '')) === '') {
        return ['ok' => false, 'reason' => 'missing_shared_secret'];
    }
    if ((int) ($settings['coa_port'] ?? 0) <= 0) {
        return ['ok' => false, 'reason' => 'missing_coa_port'];
    }

    return ['ok' => true, 'reason' => 'ready'];
}

function app_radius_disconnect_action_session(array $actionRow): array
{
    $metadata = app_db_json_decode((string) ($actionRow['metadata_json'] ?? ''), []);
    $session = is_array($metadata['session'] ?? null) ? $metadata['session'] : [];
    return is_array($session) ? $session : [];
}

function app_radius_escape_attribute_value(string $value): string
{
    return addcslashes($value, "\\\"");
}

function app_radius_disconnect_payload(array $actionRow, ?array $settings = null): array
{
    $settings ??= app_radius_settings();
    $session = app_radius_disconnect_action_session($actionRow);
    $username = trim((string) ($actionRow['username'] ?? ''));
    $framedIp = trim((string) ($actionRow['framed_ip'] ?? ''));
    $nasIp = trim((string) ($actionRow['nas_ip'] ?? ($settings['core_nas_ip'] ?? '')));
    $nasPortId = trim((string) ($actionRow['nas_port_id'] ?? ''));
    $callingStationId = trim((string) ($actionRow['calling_station_id'] ?? ''));
    $acctSessionId = trim((string) ($session['acct_session_id'] ?? ''));
    $acctUniqueSessionId = trim((string) ($session['acct_unique_session_id'] ?? ''));
    $nasIdentifier = trim((string) ($session['nas_identifier'] ?? ''));
    $calledStationId = trim((string) ($session['called_station_id'] ?? ''));

    $lines = [];
    if ($username !== '') {
        $lines[] = 'User-Name = "' . app_radius_escape_attribute_value($username) . '"';
    }
    if ($nasIp !== '' && filter_var($nasIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $lines[] = 'NAS-IP-Address = ' . $nasIp;
    }
    if ($framedIp !== '' && filter_var($framedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $lines[] = 'Framed-IP-Address = ' . $framedIp;
    }
    if ($nasPortId !== '') {
        $lines[] = 'NAS-Port-Id = "' . app_radius_escape_attribute_value($nasPortId) . '"';
    }
    if ($callingStationId !== '') {
        $lines[] = 'Calling-Station-Id = "' . app_radius_escape_attribute_value($callingStationId) . '"';
    }
    if ($calledStationId !== '') {
        $lines[] = 'Called-Station-Id = "' . app_radius_escape_attribute_value($calledStationId) . '"';
    }
    if ($acctSessionId !== '') {
        $lines[] = 'Acct-Session-Id = "' . app_radius_escape_attribute_value($acctSessionId) . '"';
    }
    if ($acctUniqueSessionId !== '') {
        $lines[] = 'Acct-Unique-Session-Id = "' . app_radius_escape_attribute_value($acctUniqueSessionId) . '"';
    }
    if ($nasIdentifier !== '') {
        $lines[] = 'NAS-Identifier = "' . app_radius_escape_attribute_value($nasIdentifier) . '"';
    }

    return [
        'target' => trim((string) ($settings['router_ip'] ?? '')) . ':' . (int) ($settings['coa_port'] ?? 3799),
        'attributes' => implode(PHP_EOL, $lines) . PHP_EOL,
        'username' => $username,
    ];
}

function app_radius_recent_session_actions(PDO $db, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    $statement = $db->query(
        'SELECT r.*, c.subscriber_code, c.full_name AS customer_name, u.full_name AS requested_by_name
         FROM radius_session_actions r
         LEFT JOIN portal_accounts c ON c.id = r.account_id
         LEFT JOIN portal_accounts u ON u.id = r.requested_by_account_id
         ORDER BY r.requested_at DESC
         LIMIT ' . $limit
    );

    return $statement !== false ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function app_billing_parse_money_amount(mixed $value): float
{
    if (is_numeric($value)) {
        return max(0.0, (float) $value);
    }

    $text = str_replace(',', '.', (string) $value);
    if (preg_match('/-?\d+(?:\.\d+)?/', $text, $match) !== 1) {
        return 0.0;
    }

    return max(0.0, (float) $match[0]);
}

function app_billing_days_left(?string $expiresAt, ?int $now = null): int
{
    $expiresAt = trim((string) $expiresAt);
    if ($expiresAt === '') {
        return 0;
    }

    $timestamp = strtotime($expiresAt);
    if ($timestamp === false) {
        return 0;
    }

    $now ??= time();
    return max(0, (int) ceil(($timestamp - $now) / 86400));
}

function app_billing_remaining_days_text(?string $expiresAt): string
{
    $days = app_billing_days_left($expiresAt);
    return $days > 0 ? $days . ' gun' : 'Vaxt bitib';
}

function app_billing_tariff_default_days(?array $tariff): int
{
    if (!is_array($tariff)) {
        return 30;
    }

    $metadata = app_db_json_decode((string) ($tariff['metadata_json'] ?? ''), []);
    foreach (['default_days', 'billing_days', 'valid_days', 'period_days', 'days'] as $key) {
        $value = $metadata[$key] ?? $tariff[$key] ?? null;
        if (is_numeric($value) && (int) $value > 0) {
            return max(1, min(366, (int) $value));
        }
    }

    return 30;
}

function app_billing_account_tariff(PDO $db, array $account, array $billing = []): ?array
{
    $accountId = (int) ($account['id'] ?? 0);
    if ($accountId > 0) {
        $statement = $db->prepare(
            'SELECT t.*
             FROM billing_radius_services s
             INNER JOIN billing_tariffs t ON t.id = s.tariff_id
             WHERE s.account_id = :account_id
             ORDER BY FIELD(s.status, "active", "suspended", "expired", "disabled"), s.id DESC
             LIMIT 1'
        );
        $statement->execute(['account_id' => $accountId]);
        $row = $statement->fetch();
        if (is_array($row)) {
            return $row;
        }
    }

    $plan = (string) ($billing['tariff'] ?? $account['plan'] ?? '');
    $serviceType = strtolower((string) ($billing['service_type'] ?? 'pppoe'));
    $serviceType = $serviceType === 'ipoe' ? 'ipoe' : 'pppoe';

    return $plan !== '' ? app_billing_tariff_for_plan($db, $plan, $serviceType) : null;
}

function app_billing_account_default_days(array $account, array $billing = [], ?array $tariff = null): int
{
    if ($billing === []) {
        $payload = app_db_account_payload($account);
        $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        $profile = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];
    } else {
        $payload = app_db_account_payload($account);
        $profile = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];
    }

    foreach ([$billing, $profile] as $source) {
        foreach (['stable_days', 'billing_days', 'default_days', 'tariff_days', 'period_days'] as $key) {
            $value = $source[$key] ?? null;
            if (is_numeric($value) && (int) $value > 0) {
                return max(1, min(366, (int) $value));
            }
        }
    }

    return app_billing_tariff_default_days($tariff);
}

function app_billing_account_stable_due_day(array $account, array $billing = [], array $profile = []): int
{
    if ($billing === [] || $profile === []) {
        $payload = app_db_account_payload($account);
        if ($billing === []) {
            $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        }
        if ($profile === []) {
            $profile = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];
        }
    }

    foreach ([$billing, $profile] as $source) {
        foreach (['stable_due_day', 'stable_day_of_month', 'billing_anchor_day', 'renewal_day'] as $key) {
            $value = $source[$key] ?? null;
            if (is_numeric($value) && (int) $value >= 1 && (int) $value <= 31) {
                return (int) $value;
            }
        }
    }

    return 0;
}

function app_billing_next_stable_due_datetime(int $stableDay, int $baseTimestamp, ?string $timeSource = null): string
{
    $stableDay = max(1, min(31, $stableDay));
    $baseTimestamp = $baseTimestamp > 0 ? $baseTimestamp : time();

    $timeBits = ['H' => 0, 'i' => 0, 's' => 0];
    $timeCandidate = $timeSource !== null && trim($timeSource) !== '' ? strtotime($timeSource) : false;
    if ($timeCandidate === false) {
        $timeCandidate = $baseTimestamp;
    }
    if ($timeCandidate !== false) {
        $timeBits = [
            'H' => (int) date('H', $timeCandidate),
            'i' => (int) date('i', $timeCandidate),
            's' => (int) date('s', $timeCandidate),
        ];
    }

    $year = (int) date('Y', $baseTimestamp);
    $month = (int) date('n', $baseTimestamp);

    for ($step = 0; $step < 24; $step++) {
        $candidateMonth = $month + $step;
        $candidateYear = $year + (int) floor(($candidateMonth - 1) / 12);
        $normalizedMonth = (($candidateMonth - 1) % 12) + 1;
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $normalizedMonth, $candidateYear);
        $candidateDay = min($stableDay, $daysInMonth);
        $candidateTimestamp = mktime(
            $timeBits['H'],
            $timeBits['i'],
            $timeBits['s'],
            $normalizedMonth,
            $candidateDay,
            $candidateYear
        );

        if ($candidateTimestamp > $baseTimestamp) {
            return date('Y-m-d H:i:s', $candidateTimestamp);
        }
    }

    return date('Y-m-d H:i:s', strtotime('+1 month', $baseTimestamp) ?: $baseTimestamp);
}

function app_billing_resolve_next_expires_at(
    int $days,
    int $stableDueDay = 0,
    ?string $oldExpiresAt = null,
    ?int $now = null
): string {
    $now ??= time();
    $currentExpires = $oldExpiresAt !== null && trim($oldExpiresAt) !== '' ? (strtotime($oldExpiresAt) ?: 0) : 0;
    $base = max($currentExpires, $now);

    if ($stableDueDay >= 1 && $stableDueDay <= 31) {
        return app_billing_next_stable_due_datetime($stableDueDay, $base, $oldExpiresAt);
    }

    $days = max(1, min(366, $days));
    return date('Y-m-d H:i:s', strtotime('+' . $days . ' days', $base));
}

function app_billing_truthy(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $text = strtolower(trim((string) $value));
    return in_array($text, ['1', 'true', 'yes', 'on', 'active', 'aktiv'], true);
}

function app_billing_extra_service_types(): array
{
    return [
        'iptv' => 'IPTV',
        'tv' => 'TV',
        'sip_phone' => 'SIP phone',
        'static_ip' => 'Static IP',
        'other' => 'Digər xidmət',
    ];
}

function app_billing_normalize_extra_service(array $service): array
{
    $types = app_billing_extra_service_types();
    $type = strtolower(trim((string) ($service['type'] ?? $service['service_type'] ?? 'other')));
    $type = array_key_exists($type, $types) ? $type : 'other';
    $status = strtolower(trim((string) ($service['status'] ?? 'active')));
    $status = in_array($status, ['active', 'inactive', 'disabled'], true) ? $status : 'active';
    $price = app_billing_parse_money_amount($service['price'] ?? $service['monthly_fee'] ?? 0);
    $title = trim((string) ($service['title'] ?? $service['name'] ?? $types[$type]));

    return [
        'id' => trim((string) ($service['id'] ?? 'svc-' . bin2hex(random_bytes(4)))),
        'type' => $type,
        'type_label' => $types[$type],
        'title' => $title !== '' ? $title : $types[$type],
        'details' => trim((string) ($service['details'] ?? $service['note'] ?? '')),
        'price' => number_format($price, 2, '.', ''),
        'status' => $status,
        'created_at' => trim((string) ($service['created_at'] ?? date('Y-m-d H:i:s'))),
    ];
}

function app_billing_extra_services(array $payload): array
{
    $rawServices = [];
    foreach (['extra_services', 'additional_services', 'value_added_services'] as $key) {
        if (is_array($payload[$key] ?? null)) {
            $rawServices = $payload[$key];
            break;
        }
    }

    return array_values(array_map(
        static fn (array $service): array => app_billing_normalize_extra_service($service),
        array_values(array_filter($rawServices, 'is_array'))
    ));
}

function app_billing_extra_services_total(array $payload, bool $activeOnly = true): float
{
    $total = 0.0;
    foreach (app_billing_extra_services($payload) as $service) {
        if ($activeOnly && (string) ($service['status'] ?? '') !== 'active') {
            continue;
        }
        $total += app_billing_parse_money_amount($service['price'] ?? 0);
    }

    return round($total, 2);
}

function app_billing_account_internet_amount(array $account, array $billing = [], ?array $tariff = null): float
{
    foreach (['custom_price_active', 'special_price_active', 'use_custom_price'] as $flag) {
        if (!app_billing_truthy($billing[$flag] ?? false)) {
            continue;
        }
        foreach (['custom_monthly_fee', 'special_monthly_fee', 'monthly_fee_custom', 'internet_custom_price'] as $key) {
            $amount = app_billing_parse_money_amount($billing[$key] ?? null);
            if ($amount > 0) {
                return $amount;
            }
        }
    }

    $tariffAmount = app_billing_parse_money_amount($tariff['monthly_fee'] ?? 0);
    if ($tariffAmount > 0) {
        return $tariffAmount;
    }

    foreach ([$billing['internet_monthly_fee'] ?? null, $billing['base_monthly_fee'] ?? null, $billing['monthly_fee'] ?? null, $billing['amount'] ?? null, $account['monthly_fee'] ?? null] as $value) {
        $amount = app_billing_parse_money_amount($value);
        if ($amount > 0) {
            return $amount;
        }
    }

    return 0.0;
}

function app_billing_account_monthly_total(array $account, array $billing = [], ?array $tariff = null, ?array $payload = null): array
{
    $payload ??= app_db_account_payload($account);
    $internetAmount = app_billing_account_internet_amount($account, $billing, $tariff);
    $extraTotal = app_billing_extra_services_total($payload, true);
    $total = round($internetAmount + $extraTotal, 2);

    return [
        'internet' => $internetAmount,
        'extra' => $extraTotal,
        'total' => $total,
    ];
}

function app_billing_account_default_amount(array $account, array $billing = [], ?array $tariff = null): float
{
    return app_billing_account_monthly_total($account, $billing, $tariff)['total'];
}

function app_billing_refresh_payload_totals(array $account, array $payload, ?array $tariff = null): array
{
    $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
    $totals = app_billing_account_monthly_total($account, $billing, $tariff, $payload);
    $billing['internet_monthly_fee'] = number_format($totals['internet'], 2, '.', '') . ' AZN';
    $billing['base_monthly_fee'] = $billing['internet_monthly_fee'];
    $billing['extra_services_total'] = number_format($totals['extra'], 2, '.', '') . ' AZN';
    $billing['monthly_total'] = number_format($totals['total'], 2, '.', '') . ' AZN';
    $billing['monthly_fee'] = $billing['monthly_total'];
    $payload['billing'] = $billing;
    $payload['extra_services'] = app_billing_extra_services($payload);

    return $payload;
}

function app_billing_payload_primary_expires_at(array $payload, array $billing = []): string
{
    $expiresAt = trim((string) ($billing['expires_at'] ?? ''));
    if ($expiresAt !== '') {
        return $expiresAt;
    }

    $services = is_array($payload['internet_services'] ?? null) ? array_values(array_filter($payload['internet_services'], 'is_array')) : [];
    foreach ($services as $service) {
        $candidate = trim((string) ($service['expires_at'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return '';
}

function app_billing_refresh_account_countdown(array $account, bool $syncExpired = false): bool
{
    $db = app_db();
    if (!$db instanceof PDO || (int) ($account['id'] ?? 0) <= 0) {
        return false;
    }

    $payload = app_db_account_payload($account);
    $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
    $expiresAt = app_billing_payload_primary_expires_at($payload, $billing);
    if ($expiresAt === '' || strtotime($expiresAt) === false) {
        return false;
    }

    $daysLeft = app_billing_days_left($expiresAt);
    $billing['expires_at'] = date('Y-m-d H:i:s', strtotime($expiresAt));
    $billing['days_left'] = $daysLeft;
    $billing['remaining_days_raw'] = app_billing_remaining_days_text($expiresAt);
    if ($daysLeft <= 0) {
        $billing['status'] = 'Expired';
        $billing['radius_status'] = 'Vaxt bitib';
    }
    $payload['billing'] = $billing;
    $payload = app_billing_refresh_payload_totals($account, $payload, app_billing_account_tariff($db, $account, $billing));

    $statement = $db->prepare(
        'UPDATE portal_accounts
         SET payload_json = :payload_json,
             status = CASE WHEN :expired_status = 1 THEN "Deaktiv - vaxt bitib" ELSE status END,
             is_active = CASE WHEN :expired_active = 1 THEN 0 ELSE is_active END,
             updated_at = NOW()
         WHERE id = :id'
    );
    $updated = $statement->execute([
        'payload_json' => app_db_json_encode($payload),
        'expired_status' => $daysLeft <= 0 ? 1 : 0,
        'expired_active' => $daysLeft <= 0 ? 1 : 0,
        'id' => (int) $account['id'],
    ]);

    if ($updated && $syncExpired && $daysLeft <= 0) {
        $fresh = app_db_account_by_id((int) $account['id']);
        if ($fresh !== null) {
            app_billing_sync_radius_account($fresh);
        }
    }

    return $updated;
}

function app_billing_refresh_countdowns(int $limit = 1000, bool $syncExpired = true): array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return ['checked' => 0, 'updated' => 0, 'expired' => 0, 'failed' => 0];
    }

    $limit = max(1, min(5000, $limit));
    $statement = $db->prepare(
        "SELECT DISTINCT a.*
         FROM portal_accounts a
         LEFT JOIN billing_radius_services s ON s.account_id = a.id
         WHERE a.role = 'customer'
           AND (
               JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '$.billing.expires_at')) IS NOT NULL
               OR s.expires_at IS NOT NULL
           )
         ORDER BY a.id
         LIMIT :limit"
    );
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    $result = ['checked' => 0, 'updated' => 0, 'expired' => 0, 'failed' => 0];
    foreach ($statement->fetchAll() as $account) {
        if (!is_array($account)) {
            continue;
        }
        $result['checked']++;
        $payload = app_db_account_payload($account);
        $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        $expiresAt = app_billing_payload_primary_expires_at($payload, $billing);
        if (app_billing_days_left($expiresAt) <= 0) {
            $result['expired']++;
        }
        if (app_billing_refresh_account_countdown($account, $syncExpired)) {
            $result['updated']++;
        } else {
            $result['failed']++;
        }
    }

    return $result;
}

function app_billing_sync_radius_account(array $account, ?string $cleartextPassword = null, ?PDO $db = null): bool
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || (string) ($account['role'] ?? '') !== 'customer') {
        return false;
    }

    $payload = app_db_account_payload($account);
    $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
    $services = is_array($payload['internet_services'] ?? null) ? array_values(array_filter($payload['internet_services'], 'is_array')) : [];
    if ($services === []) {
        $services[] = [
            'service_type' => $billing['service_type'] ?? 'pppoe',
            'username' => $billing['pppoe_username'] ?? $account['subscriber_code'] ?? $account['login'] ?? '',
            'password' => $billing['pppoe_password'] ?? '',
            'tariff' => $account['plan'] ?? $billing['tariff'] ?? '',
            'pool' => $billing['pool'] ?? '',
            'ip_address' => $billing['ip_address'] ?? '',
            'nat_public_ip' => $billing['nat_public_ip'] ?? '',
            'static_ip' => $billing['static_ip'] ?? '',
            'expires_at' => $billing['expires_at'] ?? '',
            'status' => $billing['status'] ?? $account['status'] ?? 'Aktiv',
        ];
    }

    $syncRows = [];
    foreach ($services as $index => $service) {
        $serviceType = strtolower((string) ($service['service_type'] ?? $billing['service_type'] ?? 'pppoe'));
        $serviceType = $serviceType === 'ipoe' ? 'ipoe' : 'pppoe';
        $username = trim((string) ($service['username'] ?? ($index === 0 ? ($billing['pppoe_username'] ?? '') : '') ?: ($index === 0 ? ($account['subscriber_code'] ?? $account['login'] ?? '') : '')));
        if ($username === '') {
            continue;
        }

        $password = (string) ($service['password'] ?? ($index === 0 ? ($cleartextPassword ?? $billing['pppoe_password'] ?? '') : ''));
        $plan = (string) ($service['tariff'] ?? $account['plan'] ?? $billing['tariff'] ?? '');
        $poolName = (string) ($service['pool'] ?? $billing['pool'] ?? ($serviceType === 'ipoe' ? 'IPoE Pool 1100' : 'Pool 1 - Core'));
        $assignedIp = trim((string) ($service['ip_address'] ?? $service['assigned_ip'] ?? ($index === 0 ? ($billing['ip_address'] ?? '') : '')));
        $natPublicIp = trim((string) ($service['nat_public_ip'] ?? ($index === 0 ? ($billing['nat_public_ip'] ?? '') : '')));
        $staticIp = trim((string) ($service['static_ip'] ?? ($index === 0 ? ($billing['static_ip'] ?? '') : '')));
        if ($staticIp === '' && app_ip_is_public_unicast($assignedIp)) {
            $staticIp = $assignedIp;
            $assignedIp = '';
        }
        $ipAssignmentMode = strtolower(trim((string) ($service['ip_assignment_mode'] ?? $billing['ip_assignment_mode'] ?? 'manual')));
        $expiresAt = trim((string) ($service['expires_at'] ?? $billing['expires_at'] ?? ''));
        $serviceStatus = strtolower((string) ($service['status'] ?? $billing['status'] ?? $account['status'] ?? ''));
        $tariff = app_billing_tariff_for_plan($db, $plan, $serviceType);
        $pool = app_billing_pool_by_name($db, $poolName, $serviceType);
        $customCheckRows = app_billing_radius_metadata_rows($tariff, 'radius_check_attributes');
        $customReplyRows = app_billing_radius_metadata_rows($tariff, 'radius_reply_attributes');

        if ($assignedIp === '' && $staticIp === '' && $ipAssignmentMode !== 'pool' && is_array($pool)) {
            $assignedIp = app_billing_next_available_ip((int) $pool['id']) ?? '';
        }

        $accountStatus = strtolower((string) ($account['status'] ?? ''));
        $expiresAtTs = $expiresAt !== '' ? strtotime($expiresAt) : false;
        $expired = $expiresAtTs !== false && $expiresAtTs < time();
        $active = !empty($account['is_active'])
            && !$expired
            && !str_contains($accountStatus, 'deaktiv')
            && !str_contains($accountStatus, 'bagli')
            && !str_contains($accountStatus, 'suspended')
            && !str_contains($serviceStatus, 'disabled')
            && !str_contains($serviceStatus, 'deaktiv')
            && !str_contains($serviceStatus, 'suspended');

        $syncRows[] = [
            'service_type' => $serviceType,
            'username' => $username,
            'password' => $password,
            'tariff' => $tariff,
            'pool' => $pool,
            'pool_name' => $poolName,
            'assigned_ip' => $assignedIp,
            'nat_public_ip' => $natPublicIp,
            'static_ip' => $staticIp,
            'expires_at' => $expiresAt,
            'radius_status' => $active ? 'active' : ($expired ? 'expired' : 'suspended'),
            'active' => $active,
            'expired' => $expired,
            'custom_check_rows' => $customCheckRows,
            'custom_reply_rows' => $customReplyRows,
        ];
    }

    if ($syncRows === []) {
        return false;
    }

    $started=!$db->inTransaction();
    if($started)$db->beginTransaction();
    try {
        foreach ($syncRows as $row) {
            $username = (string) $row['username'];
            $serviceType = (string) $row['service_type'];
            $password = (string) $row['password'];
            $tariff = $row['tariff'];
            $pool = $row['pool'];
            $poolName = (string) $row['pool_name'];
            $assignedIp = (string) $row['assigned_ip'];
            $natPublicIp = (string) $row['nat_public_ip'];
            $staticIp = (string) $row['static_ip'];
            $expiresAt = (string) $row['expires_at'];
            $active = (bool) $row['active'];
            $expired = (bool) $row['expired'];
            $customCheckRows = is_array($row['custom_check_rows'] ?? null) ? $row['custom_check_rows'] : [];
            $customReplyRows = is_array($row['custom_reply_rows'] ?? null) ? $row['custom_reply_rows'] : [];

            foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
                $statement = $db->prepare("DELETE FROM {$table} WHERE username = :username");
                $statement->execute(['username' => $username]);
            }

            if ($active && ($password !== '' || $serviceType === 'ipoe')) {
                $replyRows = [
                    ['Service-Type', '=', 'Framed-User'],
                ];

                if ($serviceType === 'ipoe') {
                    $ipoePassword = $password !== '' ? $password : 'ipoe';
                    $statement = $db->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES (:username, "Cleartext-Password", ":=", :value)');
                    $statement->execute(['username' => $username, 'value' => $ipoePassword]);
                    $statement = $db->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES (:username, "Simultaneous-Use", ":=", "1")');
                    $statement->execute(['username' => $username]);
                } elseif ($password !== '') {
                    $statement = $db->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES (:username, "Cleartext-Password", ":=", :value)');
                    $statement->execute(['username' => $username, 'value' => $password]);
                }

                if ($expiresAt !== '') {
                    $expirationTs = strtotime($expiresAt);
                    if ($expirationTs !== false) {
                        $statement = $db->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES (:username, "Expiration", ":=", :value)');
                        $statement->execute(['username' => $username, 'value' => date('M d Y H:i:s', $expirationTs)]);
                    }
                }

                if ($serviceType === 'pppoe') {
                    $replyRows[] = ['Framed-Protocol', '=', 'PPP'];
                }
                if ($staticIp !== '') {
                    $replyRows[] = ['Framed-IP-Address', '=', $staticIp];
                    if (is_array($pool) && !empty($pool['netmask'])) {
                        $replyRows[] = ['Framed-IP-Netmask', '=', (string) $pool['netmask']];
                    }
                } elseif ($assignedIp !== '') {
                    $replyRows[] = ['Framed-IP-Address', '=', $assignedIp];
                    if (is_array($pool) && !empty($pool['netmask'])) {
                        $replyRows[] = ['Framed-IP-Netmask', '=', (string) $pool['netmask']];
                    }
                }
                if ($poolName !== '') {
                    $replyRows[] = ['Framed-Pool', '=', $poolName];
                    $replyRows[] = ['Cisco-AVPair', '+=', 'ip:addr-pool=' . $poolName];
                }

                foreach ($customCheckRows as $customRow) {
                    $statement = $db->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES (:username, :attribute, :op, :value)');
                    $statement->execute([
                        'username' => $username,
                        'attribute' => (string) ($customRow['attribute'] ?? ''),
                        'op' => (string) ($customRow['op'] ?? '='),
                        'value' => (string) ($customRow['value'] ?? ''),
                    ]);
                }

                $replyRows = array_merge($replyRows, array_map(static function (array $row): array {
                    return [
                        (string) ($row['attribute'] ?? ''),
                        (string) ($row['op'] ?? '='),
                        (string) ($row['value'] ?? ''),
                    ];
                }, $customReplyRows));

                $replyStatement = $db->prepare('INSERT INTO radreply (username, attribute, op, value) VALUES (:username, :attribute, :op, :value)');
                foreach ($replyRows as $replyRow) {
                    $replyStatement->execute([
                        'username' => $username,
                        'attribute' => $replyRow[0],
                        'op' => $replyRow[1],
                        'value' => $replyRow[2],
                    ]);
                }

                if (is_array($tariff)) {
                    $groupStatement = $db->prepare('INSERT INTO radusergroup (username, groupname, priority) VALUES (:username, :groupname, 1)');
                    $groupStatement->execute([
                        'username' => $username,
                        'groupname' => (string) $tariff['radius_group'],
                    ]);
                }
            } else {
                $statement = $db->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES (:username, "Auth-Type", ":=", "Reject")');
                $statement->execute(['username' => $username]);
                $reply = $expired ? 'Xidmet muddeti bitib. Odenis etdikden sonra avtomatik aktivlesecek.' : 'Xidmet aktiv deyil.';
                $replyStatement = $db->prepare('INSERT INTO radreply (username, attribute, op, value) VALUES (:username, "Reply-Message", "=", :value)');
                $replyStatement->execute(['username' => $username, 'value' => $reply]);
            }

            $serviceStatement = $db->prepare(
                'INSERT INTO billing_radius_services (
                    account_id, username, service_type, tariff_id, ip_pool_id, assigned_ip, nat_public_ip, static_ip, status, expires_at, last_radius_sync_at
                 ) VALUES (
                    :account_id, :username, :service_type, :tariff_id, :ip_pool_id, :assigned_ip, :nat_public_ip, :static_ip, :status, :expires_at, NOW()
                 )
                 ON DUPLICATE KEY UPDATE
                    account_id = VALUES(account_id),
                    service_type = VALUES(service_type),
                    tariff_id = VALUES(tariff_id),
                    ip_pool_id = VALUES(ip_pool_id),
                    assigned_ip = VALUES(assigned_ip),
                    nat_public_ip = VALUES(nat_public_ip),
                    static_ip = VALUES(static_ip),
                    status = VALUES(status),
                    expires_at = VALUES(expires_at),
                    last_radius_sync_at = NOW()'
            );
            $serviceStatement->execute([
                'account_id' => (int) $account['id'],
                'username' => $username,
                'service_type' => $serviceType,
                'tariff_id' => is_array($tariff) ? (int) $tariff['id'] : null,
                'ip_pool_id' => is_array($pool) ? (int) $pool['id'] : null,
                'assigned_ip' => $assignedIp !== '' ? $assignedIp : null,
                'nat_public_ip' => $natPublicIp !== '' ? $natPublicIp : null,
                'static_ip' => $staticIp !== '' ? $staticIp : null,
                'status' => (string) $row['radius_status'],
                'expires_at' => $expiresAt !== '' ? date('Y-m-d H:i:s', strtotime($expiresAt)) : null,
            ]);

            if ($assignedIp !== '' || $natPublicIp !== '' || $staticIp !== '') {
                app_ip_record_customer_assignment(
                    $db,
                    (int) $account['id'],
                    $username,
                    $assignedIp,
                    $natPublicIp,
                    $staticIp,
                    0,
                    'radius_sync'
                );
            }
        }

        if($started)$db->commit();
        return true;
    } catch (Throwable) {
        if ($started && $db->inTransaction()) {
            $db->rollBack();
        }
        return false;
    }
}

function app_billing_sync_radius_accounts(?string $startCode = null, ?string $endCode = null, int $limit = 0): array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return ['synced' => 0, 'failed' => 0];
    }

    $sql = "SELECT * FROM portal_accounts WHERE role = 'customer'";
    $params = [];
    if ($startCode !== null && $endCode !== null) {
        $sql .= ' AND subscriber_code BETWEEN :start_code AND :end_code';
        $params['start_code'] = $startCode;
        $params['end_code'] = $endCode;
    }
    $sql .= ' ORDER BY subscriber_code';
    $limit = max(0, min(5000, $limit));
    if ($limit > 0) {
        $sql .= ' LIMIT :limit';
    }

    $statement = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $statement->bindValue(':' . $key, $value);
    }
    if ($limit > 0) {
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    }
    $statement->execute();
    $result = ['synced' => 0, 'failed' => 0];
    foreach ($statement->fetchAll() as $account) {
        if (is_array($account) && app_billing_sync_radius_account($account)) {
            $result['synced']++;
        } else {
            $result['failed']++;
        }
    }

    return $result;
}

function app_billing_overview(): array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return [];
    }

    return [
        'tariffs' => (int) $db->query('SELECT COUNT(*) FROM billing_tariffs')->fetchColumn(),
        'pools' => (int) $db->query('SELECT COUNT(*) FROM billing_ip_pools')->fetchColumn(),
        'radius_services' => (int) $db->query('SELECT COUNT(*) FROM billing_radius_services')->fetchColumn(),
        'radcheck' => (int) $db->query('SELECT COUNT(*) FROM radcheck')->fetchColumn(),
        'radreply' => (int) $db->query('SELECT COUNT(*) FROM radreply')->fetchColumn(),
        'radacct' => (int) $db->query('SELECT COUNT(*) FROM radacct')->fetchColumn(),
    ];
}

function app_billing_save_account_payload(array $account, array $payload, ?string $status = null): bool
{
    $db = app_db();
    if (!$db instanceof PDO || (int) ($account['id'] ?? 0) <= 0) {
        return false;
    }

    $statement = $db->prepare(
        'UPDATE portal_accounts
         SET payload_json = :payload_json,
             status = COALESCE(:status_value, status),
             is_active = 1,
             updated_at = NOW()
         WHERE id = :id'
    );

    return $statement->execute([
        'payload_json' => app_db_json_encode($payload),
        'status_value' => $status,
        'id' => (int) $account['id'],
    ]);
}

function app_billing_process_due_suspensions(): array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return ['suspended' => 0, 'failed' => 0];
    }

    $rows = $db->query(
        "SELECT a.*
         FROM billing_radius_services s
         INNER JOIN portal_accounts a ON a.id = s.account_id
         WHERE s.auto_suspend = 1
           AND s.expires_at IS NOT NULL
           AND s.expires_at < NOW()
           AND s.status = 'active'"
    )->fetchAll();

    $result = ['suspended' => 0, 'failed' => 0];
    foreach ($rows as $account) {
        if (!is_array($account)) {
            continue;
        }

        $payload = app_db_account_payload($account);
        $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        $billing['status'] = 'Expired';
        $billing['radius_status'] = 'Vaxt bitib';
        $billing['days_left'] = 0;
        $billing['remaining_days_raw'] = 'Vaxt bitib';
        $payload['billing'] = $billing;

        if (app_billing_save_account_payload($account, $payload, 'Deaktiv - vaxt bitib')) {
            $deactivateStatement = $db->prepare('UPDATE portal_accounts SET is_active = 0 WHERE id = :id');
            $deactivateStatement->execute(['id' => (int) $account['id']]);
            $updated = app_db_account_by_id((int) $account['id']);
            if ($updated !== null && app_billing_sync_radius_account($updated)) {
                $result['suspended']++;
                continue;
            }
        }
        $result['failed']++;
    }

    return $result;
}

function app_billing_payment_external_exists(string $externalId): bool
{
    $externalId = trim($externalId);
    if ($externalId === '') {
        return false;
    }

    $db = app_db();
    if (!$db instanceof PDO) {
        return false;
    }

    $statement = $db->prepare('SELECT id FROM billing_payments WHERE external_id = :external_id LIMIT 1');
    $statement->execute(['external_id' => $externalId]);

    return (bool) $statement->fetchColumn();
}

function app_billing_required_payment_amount(array $account, array $payload, array $billing = [], ?array $tariff = null): float
{
    $billing = $billing !== [] ? $billing : (is_array($payload['billing'] ?? null) ? $payload['billing'] : []);
    $balance = app_billing_parse_money_amount($billing['balance'] ?? 0);
    $monthlyTotal = app_billing_parse_money_amount($billing['monthly_total'] ?? 0);
    if ($monthlyTotal <= 0) {
        $monthlyTotal = app_billing_parse_money_amount($billing['internet_monthly_fee'] ?? 0)
            + app_billing_parse_money_amount($billing['extra_services_total'] ?? 0);
    }
    if ($monthlyTotal <= 0) {
        $monthlyTotal = app_billing_account_default_amount($account, $billing, $tariff);
    }

    return max(0.01, $balance > 0 ? $balance : $monthlyTotal);
}

function app_billing_apply_customer_payment(
    string $subscriberCode,
    float $amount,
    string $method = 'manual',
    int $days = 0,
    string $transactionId = '',
    string $externalId = '',
    ?string $createdAt = null
): bool
{
    $account = app_db_account_by_subscriber_code($subscriberCode);
    $db = app_db();
    if (!$db instanceof PDO || $account === null) {
        return false;
    }

    $externalId = trim($externalId);
    if ($externalId !== '' && app_billing_payment_external_exists($externalId)) {
        return true;
    }

    $payload = app_db_account_payload($account);
    $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
    $tariff = app_billing_account_tariff($db, $account, $billing);
    $payload = app_billing_refresh_payload_totals($account, $payload, $tariff);
    $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
    $resolvedDays = $days > 0 ? max(1, min(366, $days)) : app_billing_account_default_days($account, $billing, $tariff);
    $stableDueDay = app_billing_account_stable_due_day($account, $billing);
    $resolvedAmount = $amount > 0 ? $amount : app_billing_account_default_amount($account, $billing, $tariff);
    if ($resolvedAmount <= 0) {
        return false;
    }

    $requiredAmount = app_billing_required_payment_amount($account, $payload, $billing, $tariff);
    $currentBalance = app_billing_parse_money_amount($billing['balance'] ?? 0);
    $fullPayment = $resolvedAmount + 0.0001 >= $requiredAmount;
    $remainingBalance = max(0.0, $currentBalance > 0 ? $currentBalance - $resolvedAmount : $requiredAmount - $resolvedAmount);
    $oldExpiresAt = app_billing_payload_primary_expires_at($payload, $billing);
    $newExpiresAt = $fullPayment
        ? app_billing_resolve_next_expires_at($resolvedDays, $stableDueDay, $oldExpiresAt, time())
        : $oldExpiresAt;
    $remainingDays = app_billing_days_left($newExpiresAt);
    if ($fullPayment) {
        $billing['status'] = 'Aktiv';
        $billing['radius_status'] = 'Aktiv';
        $billing['balance'] = '0 AZN';
        $billing['expires_at'] = $newExpiresAt;
        $billing['days_left'] = $remainingDays;
        $billing['remaining_days_raw'] = app_billing_remaining_days_text($newExpiresAt);
    } else {
        $billing['balance'] = number_format($remainingBalance, 2, '.', '') . ' AZN';
        $billing['last_partial_payment_at'] = $createdAt !== null && strtotime($createdAt) !== false
            ? date('Y-m-d H:i:s', strtotime($createdAt))
            : date('Y-m-d H:i:s');
        $billing['partial_payment_note'] = 'Qismen odenis qebul edildi; tam aktivlesme ucun qaliq odenilmelidir.';
    }
    $billing['default_days'] = app_billing_account_default_days($account, $billing, $tariff);
    if ($stableDueDay > 0) {
        $billing['stable_due_day'] = $stableDueDay;
    }
    if (is_array($tariff)) {
        $billing['tariff'] = (string) ($tariff['name'] ?? $billing['tariff'] ?? '');
        $billing['internet_monthly_fee'] = number_format((float) ($tariff['monthly_fee'] ?? 0), 2, '.', '') . ' AZN';
    }
    $payload['billing'] = $billing;

    $services = is_array($payload['internet_services'] ?? null) ? array_values(array_filter($payload['internet_services'], 'is_array')) : [];
    if ($fullPayment) {
        foreach ($services as $index => $service) {
            $services[$index]['expires_at'] = $newExpiresAt;
            $services[$index]['status'] = 'active';
        }
    }
    if ($services !== []) {
        $payload['internet_services'] = $services;
    }
    $payload = app_billing_refresh_payload_totals($account, $payload, $tariff);
    $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];

    $createdAtSql = $createdAt !== null && strtotime($createdAt) !== false ? date('Y-m-d H:i:s', strtotime($createdAt)) : null;
    $paymentDate = $createdAtSql !== null ? date('d.m.Y H:i', strtotime($createdAtSql)) : date('d.m.Y H:i');
    $payment = [
        'date' => $paymentDate,
        'amount' => number_format($resolvedAmount, 2, '.', '') . ' AZN',
        'method' => $method,
        'status' => 'Tesdiqlendi',
        'transaction_id' => $transactionId,
        'days_added' => $fullPayment ? $resolvedDays : 0,
        'expires_at' => $newExpiresAt,
        'effect' => $fullPayment ? 'activated' : 'partial_balance_reduction',
    ];
    $payments = is_array($payload['payments'] ?? null) ? $payload['payments'] : [];
    array_unshift($payments, $payment);
    $payload['payments'] = array_slice($payments, 0, 30);
    $metadata = [
        'days_added' => $resolvedDays,
        'previous_expires_at' => $oldExpiresAt !== '' ? $oldExpiresAt : null,
        'new_expires_at' => $fullPayment ? $newExpiresAt : null,
        'amount_source' => $amount > 0 ? 'custom' : 'monthly_total',
        'required_amount' => $requiredAmount,
        'remaining_balance' => $remainingBalance,
        'payment_effect' => $fullPayment ? 'activated' : 'partial_balance_reduction',
        'external_id' => $externalId !== '' ? $externalId : null,
        'provider_created_at' => $createdAtSql,
        'internet_amount' => app_billing_parse_money_amount($billing['internet_monthly_fee'] ?? 0),
        'extra_services_total' => app_billing_parse_money_amount($billing['extra_services_total'] ?? 0),
        'monthly_total' => app_billing_parse_money_amount($billing['monthly_total'] ?? $resolvedAmount),
        'tariff_id' => is_array($tariff) ? (int) ($tariff['id'] ?? 0) : null,
        'tariff_name' => is_array($tariff) ? (string) ($tariff['name'] ?? '') : (string) ($billing['tariff'] ?? ''),
    ];

    $db->beginTransaction();
    try {
        $paymentStatement = $db->prepare(
            'INSERT INTO billing_payments (external_id, account_id, subscriber_code, amount, payment_method, transaction_id, note, metadata_json, created_at)
             VALUES (:external_id, :account_id, :subscriber_code, :amount, :payment_method, :transaction_id, :note, :metadata_json, COALESCE(:created_at, NOW()))'
        );
        $paymentStatement->execute([
            'external_id' => $externalId !== '' ? $externalId : null,
            'account_id' => (int) $account['id'],
            'subscriber_code' => $subscriberCode,
            'amount' => $resolvedAmount,
            'payment_method' => $method,
            'transaction_id' => $transactionId !== '' ? $transactionId : null,
            'note' => $fullPayment
                ? ('Auto activate after payment: +' . $resolvedDays . ' days')
                : ('Partial payment accepted; remaining balance ' . number_format($remainingBalance, 2, '.', '') . ' AZN'),
            'metadata_json' => app_db_json_encode($metadata),
            'created_at' => $createdAtSql,
        ]);

        $saveStatement = $db->prepare(
            'UPDATE portal_accounts
             SET payload_json = :payload_json,
                 status = CASE WHEN :full_payment = 1 THEN "Aktiv" ELSE status END,
                 is_active = CASE WHEN :full_payment = 1 THEN 1 ELSE is_active END,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $saveStatement->execute([
            'payload_json' => app_db_json_encode($payload),
            'full_payment' => $fullPayment ? 1 : 0,
            'id' => (int) $account['id'],
        ]);

        if ($fullPayment) {
            $serviceUpdate = $db->prepare(
                'UPDATE billing_radius_services
                 SET status = "active", expires_at = :expires_at, updated_at = NOW()
                 WHERE account_id = :account_id'
            );
            $serviceUpdate->execute([
                'expires_at' => $newExpiresAt,
                'account_id' => (int) $account['id'],
            ]);
        }
        $db->commit();
    } catch (Throwable) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return false;
    }

    $updated = app_db_account_by_id((int) $account['id']);
    if ($updated === null) {
        return false;
    }

    return $fullPayment ? app_billing_sync_radius_account($updated) : true;
}

function app_message_seed_defaults(PDO $db): void
{
    $templates = [
        [
            'payment_received',
            'Odenis qebul edildi',
            'billing',
            'sms,whatsapp,app,telegram',
            'Hormetli {full_name}, {customer_code} nomreli hesabiniz uzre {amount} AZN odenis qebul edildi. Tesekkur edirik.',
            'payment.completed',
        ],
        [
            'service_expiring_1d',
            'Xidmet 1 gun sonra bitecek',
            'billing',
            'sms,whatsapp,app,telegram',
            'Hormetli {full_name}, {customer_code} nomreli internet xidmetiniz 1 gun sonra bitecek. Davamli istifade ucun odenis edin.',
            'billing.expiring_1d',
        ],
        [
            'service_expired',
            'Xidmet muddeti bitdi',
            'billing',
            'sms,whatsapp,app,telegram',
            'Hormetli {full_name}, {customer_code} nomreli internet xidmetinizin muddeti bitib. Xidmeti aktivlesdirmek ucun odenis edin.',
            'billing.expired',
        ],
        [
            'service_activated_30d',
            'Xidmet 30 gun aktiv edildi',
            'billing',
            'sms,whatsapp,app,telegram',
            'Hormetli {full_name}, {customer_code} nomreli xidmetiniz novbeti 30 gun ucun aktiv edildi. Bitme tarixi: {expires_at}.',
            'billing.activated',
        ],
        [
            'modem_overheat',
            'Modem temperatur xeberdarligi',
            'technical',
            'whatsapp,app,telegram,sms',
            'Hormetli {full_name}, modeminizde tez-tez qizmaya oxsar hal gorunur. Suret azalmasi ve qosulmama problemi yaranarsa texnik yoxlama ve ya modem deyisimi lazim ola biler.',
            'ont.temperature_repeated',
        ],
        [
            'modem_flap',
            'Modem tez-tez sonub-yanir',
            'technical',
            'whatsapp,app,telegram,sms',
            'Hormetli {full_name}, modeminizde tez-tez yeniden qosulma halı gorunur. Adaptor, elektrik ve modem veziyyetini yoxlatdirmaq tovsiye olunur.',
            'ont.flap_repeated',
        ],
        [
            'birthday_greeting',
            'Ad gunu tebriki',
            'crm',
            'whatsapp,app,telegram,sms',
            'Hormetli {full_name}, ad gununuz mubarek! SuperOptic olaraq size saglam ve ugurlu il arzulayiriq.',
            'crm.birthday',
        ],
        [
            'retention_60d',
            '2 ay odenis etmeyen musteri qaytarma',
            'retention',
            'whatsapp,app,telegram,sms',
            'Hormetli {full_name}, {customer_code} nomreli hesabiniz uzun muddet aktiv deyil. Uygun paket ve kampaniyalar ucun bizimle elaqe saxlayin.',
            'crm.retention_60d',
        ],
        [
            'holiday_novruz',
            'Novruz tebriki',
            'holiday',
            'whatsapp,app,telegram,sms',
            'Hormetli {full_name}, Novruz bayraminiz mubarek! Size bolluq, ruzi ve rahat internet arzulayiriq.',
            'holiday.novruz',
        ],
        [
            'holiday_republic',
            'Respublika Gunu tebriki',
            'holiday',
            'whatsapp,app,telegram,sms',
            'Hormetli {full_name}, 28 May Respublika Gununuz mubarek!',
            'holiday.republic_day',
        ],
        [
            'holiday_victory',
            'Zefer Gunu tebriki',
            'holiday',
            'whatsapp,app,telegram,sms',
            'Hormetli {full_name}, 8 Noyabr Zefer Gununuz mubarek!',
            'holiday.victory_day',
        ],
        [
            'holiday_new_year',
            'Yeni il tebriki',
            'holiday',
            'whatsapp,app,telegram,sms',
            'Hormetli {full_name}, yeni iliniz mubarek! Yeni il size saglamliq ve ugur getirsin.',
            'holiday.new_year',
        ],
    ];

    $statement = $db->prepare(
        'INSERT INTO communication_templates (code, title, category, channel_scope, body, auto_trigger)
         VALUES (:code, :title, :category, :channel_scope, :body, :auto_trigger)
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            category = VALUES(category),
            channel_scope = VALUES(channel_scope),
            body = VALUES(body),
            auto_trigger = VALUES(auto_trigger),
            status = "active"'
    );

    foreach ($templates as $template) {
        $statement->execute([
            'code' => $template[0],
            'title' => $template[1],
            'category' => $template[2],
            'channel_scope' => $template[3],
            'body' => $template[4],
            'auto_trigger' => $template[5],
        ]);
    }
}

function app_message_templates(bool $activeOnly = true): array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return [];
    }

    $sql = 'SELECT * FROM communication_templates';
    if ($activeOnly) {
        $sql .= ' WHERE status = "active"';
    }
    $sql .= ' ORDER BY category, title';

    return $db->query($sql)->fetchAll() ?: [];
}

function app_message_template_by_code(string $code): ?array
{
    $db = app_db();
    if (!$db instanceof PDO || trim($code) === '') {
        return null;
    }

    $statement = $db->prepare('SELECT * FROM communication_templates WHERE code = :code AND status = "active" LIMIT 1');
    $statement->execute(['code' => trim($code)]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function app_message_stats(): array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return ['templates' => 0, 'today' => 0, 'queued' => 0, 'test' => 0];
    }

    return [
        'templates' => (int) $db->query('SELECT COUNT(*) FROM communication_templates WHERE status = "active"')->fetchColumn(),
        'today' => (int) $db->query('SELECT COUNT(*) FROM communication_messages WHERE created_at >= CURDATE()')->fetchColumn(),
        'queued' => (int) $db->query('SELECT COUNT(*) FROM communication_messages WHERE status = "queued"')->fetchColumn(),
        'test' => (int) $db->query('SELECT COUNT(*) FROM communication_messages WHERE status = "test"')->fetchColumn(),
    ];
}

function app_message_customer_history(int $accountId, int $limit = 20): array
{
    $db = app_db();
    if (!$db instanceof PDO || $accountId <= 0) {
        return [];
    }

    $statement = $db->prepare(
        'SELECT * FROM communication_messages
         WHERE account_id = :account_id AND status <> "test"
         ORDER BY created_at DESC
         LIMIT ' . max(1, min($limit, 100))
    );
    $statement->execute(['account_id' => $accountId]);

    return $statement->fetchAll() ?: [];
}

function app_message_recent(int $limit = 30): array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return [];
    }

    $statement = $db->query(
        'SELECT m.*, a.full_name, a.phone, a.area
         FROM communication_messages m
         LEFT JOIN portal_accounts a ON a.id = m.account_id
         WHERE (m.account_id IS NOT NULL OR NULLIF(m.subscriber_code, "") IS NOT NULL)
         ORDER BY m.created_at DESC
         LIMIT ' . max(1, min($limit, 100))
    );

    return $statement !== false ? ($statement->fetchAll() ?: []) : [];
}

function app_message_render_body(string $body, array $account, array $extra = []): string
{
    $payload = app_db_account_payload($account);
    $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
    $profile = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];

    $values = array_merge([
        'full_name' => (string) ($account['full_name'] ?? $profile['full_name'] ?? ''),
        'customer_code' => (string) ($account['subscriber_code'] ?? $profile['subscriber_code'] ?? ''),
        'contract_no' => (string) ($account['contract_no'] ?? $profile['contract_no'] ?? ''),
        'phone' => (string) ($account['phone'] ?? $profile['phone'] ?? ''),
        'tariff' => (string) ($account['plan'] ?? $billing['tariff'] ?? ''),
        'amount' => (string) ($extra['amount'] ?? $billing['monthly_fee'] ?? ''),
        'expires_at' => (string) ($extra['expires_at'] ?? $billing['expires_at'] ?? ''),
        'days_left' => (string) ($extra['days_left'] ?? $billing['days_left'] ?? ''),
    ], $extra);

    foreach ($values as $key => $value) {
        $body = str_replace('{' . $key . '}', (string) $value, $body);
    }

    return trim($body);
}

function app_message_channel_for_account(array $account, string $requested = 'auto'): array
{
    $requested = strtolower(trim($requested));
    $requested = in_array($requested, ['auto', 'whatsapp', 'app', 'telegram', 'sms'], true) ? $requested : 'auto';

    $payload = app_db_account_payload($account);
    $phone = trim((string) ($account['phone'] ?? ''));
    $login = trim((string) ($account['login'] ?? ''));
    $telegram = '';
    if (is_array($payload['telegram'] ?? null)) {
        $telegram = trim((string) ($payload['telegram']['chat_id'] ?? $payload['telegram']['username'] ?? ''));
    }

    $destinations = [
        'whatsapp' => $phone,
        'app' => $login,
        'telegram' => $telegram,
        'sms' => $phone,
    ];

    if ($requested !== 'auto') {
        return [
            'channel' => $requested,
            'destination' => $destinations[$requested] !== '' ? $destinations[$requested] : ($phone !== '' ? $phone : $login),
            'fallback_chain' => $requested,
        ];
    }

    $chain = [];
    foreach (['whatsapp', 'app', 'telegram', 'sms'] as $channel) {
        $chain[] = $channel;
        if ($destinations[$channel] !== '') {
            return [
                'channel' => $channel,
                'destination' => $destinations[$channel],
                'fallback_chain' => implode('>', $chain),
            ];
        }
    }

    return [
        'channel' => 'app',
        'destination' => $login,
        'fallback_chain' => 'whatsapp>app>telegram>sms',
    ];
}

function app_message_queue_for_account(array $account, string $templateCode, string $requestedChannel, string $customBody, array $actor, bool $testMode = false): bool
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return false;
    }

    $template = app_message_template_by_code($templateCode);
    $body = trim($customBody);
    if ($body === '' && is_array($template)) {
        $body = (string) $template['body'];
    }
    if ($body === '') {
        return false;
    }

    $body = app_message_render_body($body, $account);
    if ($testMode) {
        $body = "TEST MESAJI\n" . $body;
    }
    if (mb_strlen($body) > 640) {
        $body = mb_substr($body, 0, 640);
    }

    $channel = app_message_channel_for_account($account, $requestedChannel);
    $statement = $db->prepare(
        'INSERT INTO communication_messages (
            account_id, subscriber_code, recipient_name, destination, channel, fallback_chain,
            template_code, message_body, status, sent_at, created_by_account_id, metadata_json
         ) VALUES (
            :account_id, :subscriber_code, :recipient_name, :destination, :channel, :fallback_chain,
            :template_code, :message_body, :status, :sent_at, :created_by_account_id, :metadata_json
         )'
    );

    return $statement->execute([
        'account_id' => (int) ($account['id'] ?? 0),
        'subscriber_code' => (string) ($account['subscriber_code'] ?? ''),
        'recipient_name' => (string) ($account['full_name'] ?? ''),
        'destination' => (string) ($channel['destination'] ?? ''),
        'channel' => (string) ($channel['channel'] ?? 'app'),
        'fallback_chain' => (string) ($channel['fallback_chain'] ?? ''),
        'template_code' => $templateCode !== '' ? $templateCode : null,
        'message_body' => $body,
        'status' => $testMode ? 'test' : 'queued',
        'sent_at' => null,
        'created_by_account_id' => (int) ($actor['id'] ?? 0) ?: null,
        'metadata_json' => app_db_json_encode([
            'mode' => $testMode ? 'test' : 'queued',
            'provider_ready' => false,
            'note' => 'Provider API qosulduqda queued mesajlar eyni cedvelden gonderilecek.',
        ]),
    ]);
}

function app_message_already_queued_today(int $accountId, string $templateCode): bool
{
    $db = app_db();
    $templateCode = trim($templateCode);
    if (!$db instanceof PDO || $accountId <= 0 || $templateCode === '') {
        return false;
    }

    $statement = $db->prepare(
        'SELECT id
         FROM communication_messages
         WHERE account_id = :account_id
           AND template_code = :template_code
           AND status <> "test"
           AND created_at >= CURDATE()
         LIMIT 1'
    );
    $statement->execute([
        'account_id' => $accountId,
        'template_code' => $templateCode,
    ]);

    return (bool) $statement->fetchColumn();
}

function app_message_queue_unique_for_account(array $account, string $templateCode, string $requestedChannel, string $customBody, array $actor, bool $testMode = false): string
{
    $accountId = (int) ($account['id'] ?? 0);
    if (!$testMode && app_message_already_queued_today($accountId, $templateCode)) {
        return 'duplicate_today';
    }

    return app_message_queue_for_account($account, $templateCode, $requestedChannel, $customBody, $actor, $testMode)
        ? 'queued'
        : 'failed';
}

function app_message_group_accounts(string $scope, string $area = '', int $limit = 200): array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return [];
    }

    $sql = "SELECT * FROM portal_accounts WHERE role = 'customer'";
    $params = [];
    if ($scope === 'active') {
        $sql .= ' AND is_active = 1 AND (status IS NULL OR status NOT LIKE "%Deaktiv%")';
    } elseif ($scope === 'inactive') {
        $sql .= ' AND (is_active = 0 OR status LIKE "%Deaktiv%" OR status LIKE "%Passiv%")';
    } elseif ($scope === 'area' && trim($area) !== '') {
        $sql .= ' AND area = :area';
        $params['area'] = trim($area);
    }
    $sql .= ' ORDER BY updated_at DESC LIMIT ' . max(1, min($limit, 500));

    $statement = $db->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll() ?: [];

    if (!in_array($scope, ['expiring_1d', 'overdue_60d'], true)) {
        return array_values(array_filter($rows, 'is_array'));
    }

    $filtered = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $payload = app_db_account_payload($row);
        $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        $expiresAt = strtotime((string) ($billing['expires_at'] ?? ''));
        if ($scope === 'expiring_1d' && $expiresAt !== false) {
            $days = (int) floor(($expiresAt - time()) / 86400);
            if ($days >= 0 && $days <= 1) {
                $filtered[] = $row;
            }
        }
        if ($scope === 'overdue_60d' && $expiresAt !== false && $expiresAt < strtotime('-60 days')) {
            $filtered[] = $row;
        }
    }

    return $filtered;
}

function app_message_queue_group(string $scope, string $area, string $templateCode, string $requestedChannel, string $customBody, array $actor, bool $testMode = false): array
{
    $accounts = app_message_group_accounts($scope, $area);
    $result = ['queued' => 0, 'failed' => 0, 'matched' => count($accounts)];

    if ($testMode) {
        $accounts = array_slice($accounts, 0, 1);
    }

    foreach ($accounts as $account) {
        if (app_message_queue_for_account($account, $templateCode, $requestedChannel, $customBody, $actor, $testMode)) {
            $result['queued']++;
        } else {
            $result['failed']++;
        }
    }

    return $result;
}

function app_sso_provider_config(string $provider = 'mygov'): array
{
    $provider = strtolower(trim($provider));
    if ($provider !== 'mygov') {
        return [];
    }

    $redirectUri = app_config('mygov_redirect_uri');
    if ($redirectUri === '') {
        $redirectUri = app_absolute_url('mygov-auth.php', ['callback' => '1']);
    }

    return [
        'provider' => 'mygov',
        'enabled' => (string) app_setting('mygov_enabled', '0') === '1',
        'mock_enabled' => (string) app_setting('mygov_mock_enabled', '0') === '1',
        'client_id' => app_config('mygov_client_id'),
        'client_secret' => app_config('mygov_client_secret'),
        'authorize_url' => app_config('mygov_authorize_url'),
        'token_url' => app_config('mygov_token_url'),
        'userinfo_url' => app_config('mygov_userinfo_url'),
        'redirect_uri' => $redirectUri,
        'scope' => app_config('mygov_scope', 'openid profile fin'),
    ];
}

function app_sso_provider_ready(string $provider = 'mygov'): bool
{
    $config = app_sso_provider_config($provider);
    return !empty($config['enabled'])
        && (string) ($config['client_id'] ?? '') !== ''
        && (string) ($config['client_secret'] ?? '') !== ''
        && (string) ($config['authorize_url'] ?? '') !== ''
        && (string) ($config['token_url'] ?? '') !== ''
        && (string) ($config['userinfo_url'] ?? '') !== '';
}

function app_sso_create_state(string $provider, string $role, string $returnUrl = ''): ?array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return null;
    }

    $provider = strtolower(trim($provider));
    $role = $role === 'employee' ? 'employee' : 'customer';
    $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $stateHash = hash('sha256', $state);
    $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    $codeVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $expiresAt = (string) $db->query('SELECT DATE_ADD(NOW(), INTERVAL 10 MINUTE)')->fetchColumn();

    $statement = $db->prepare(
        'INSERT INTO sso_auth_states (
            state_hash, provider, requested_role, return_url, nonce, code_verifier, client_ip, expires_at
         ) VALUES (
            :state_hash, :provider, :requested_role, :return_url, :nonce, :code_verifier, :client_ip, :expires_at
         )'
    );
    $ok = $statement->execute([
        'state_hash' => $stateHash,
        'provider' => $provider,
        'requested_role' => $role,
        'return_url' => $returnUrl !== '' ? mb_substr($returnUrl, 0, 255) : null,
        'nonce' => $nonce,
        'code_verifier' => $codeVerifier,
        'client_ip' => app_client_ip(),
        'expires_at' => $expiresAt,
    ]);

    return $ok ? [
        'state' => $state,
        'state_hash' => $stateHash,
        'nonce' => $nonce,
        'code_verifier' => $codeVerifier,
        'role' => $role,
        'expires_at' => $expiresAt,
    ] : null;
}

function app_sso_consume_state(string $provider, string $state): ?array
{
    $db = app_db();
    if (!$db instanceof PDO || trim($state) === '') {
        return null;
    }

    $stateHash = hash('sha256', $state);
    $statement = $db->prepare(
        'SELECT * FROM sso_auth_states
         WHERE provider = :provider
           AND state_hash = :state_hash
           AND consumed_at IS NULL
           AND expires_at > NOW()
         LIMIT 1'
    );
    $statement->execute([
        'provider' => strtolower(trim($provider)),
        'state_hash' => $stateHash,
    ]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return null;
    }

    $update = $db->prepare('UPDATE sso_auth_states SET consumed_at = NOW() WHERE id = :id');
    $update->execute(['id' => (int) $row['id']]);

    return $row;
}

function app_sso_authorize_url(string $provider, array $stateRow): ?string
{
    $config = app_sso_provider_config($provider);
    if (!app_sso_provider_ready($provider)) {
        return null;
    }

    $params = [
        'response_type' => 'code',
        'client_id' => (string) $config['client_id'],
        'redirect_uri' => (string) $config['redirect_uri'],
        'scope' => (string) $config['scope'],
        'state' => (string) ($stateRow['state'] ?? ''),
        'nonce' => (string) ($stateRow['nonce'] ?? ''),
    ];

    $separator = str_contains((string) $config['authorize_url'], '?') ? '&' : '?';
    return (string) $config['authorize_url'] . $separator . http_build_query($params);
}

function app_sso_find_account_by_identity(string $provider, string $providerSubject, string $role): ?array
{
    $db = app_db();
    if (!$db instanceof PDO || trim($providerSubject) === '') {
        return null;
    }

    $statement = $db->prepare(
        'SELECT a.*
         FROM portal_account_identities i
         INNER JOIN portal_accounts a ON a.id = i.account_id
         WHERE i.provider = :provider
           AND i.provider_subject = :provider_subject
           AND a.role = :role
           AND a.is_active = 1
         LIMIT 1'
    );
    $statement->execute([
        'provider' => strtolower(trim($provider)),
        'provider_subject' => trim($providerSubject),
        'role' => $role === 'employee' ? 'employee' : 'customer',
    ]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function app_sso_link_identity(int $accountId, string $provider, array $identity): bool
{
    $db = app_db();
    if (!$db instanceof PDO || $accountId <= 0) {
        return false;
    }

    $providerSubject = trim((string) ($identity['provider_subject'] ?? $identity['sub'] ?? ''));
    if ($providerSubject === '') {
        return false;
    }

    $statement = $db->prepare(
        'INSERT INTO portal_account_identities (
            account_id, provider, provider_subject, fin_code, document_no,
            verified_full_name, verified_birth_date, last_verified_at, metadata_json
         ) VALUES (
            :account_id, :provider, :provider_subject, :fin_code, :document_no,
            :verified_full_name, :verified_birth_date, NOW(), :metadata_json
         )
         ON DUPLICATE KEY UPDATE
            account_id = VALUES(account_id),
            provider_subject = VALUES(provider_subject),
            fin_code = VALUES(fin_code),
            document_no = VALUES(document_no),
            verified_full_name = VALUES(verified_full_name),
            verified_birth_date = VALUES(verified_birth_date),
            last_verified_at = NOW(),
            metadata_json = VALUES(metadata_json)'
    );

    return $statement->execute([
        'account_id' => $accountId,
        'provider' => strtolower(trim($provider)),
        'provider_subject' => $providerSubject,
        'fin_code' => trim((string) ($identity['fin_code'] ?? $identity['fin'] ?? '')) ?: null,
        'document_no' => trim((string) ($identity['document_no'] ?? '')) ?: null,
        'verified_full_name' => trim((string) ($identity['full_name'] ?? $identity['name'] ?? '')) ?: null,
        'verified_birth_date' => app_db_parse_date((string) ($identity['birth_date'] ?? '')),
        'metadata_json' => app_db_json_encode($identity),
    ]);
}

function app_db_parse_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    foreach (['Y-m-d', 'd.m.Y', DateTimeInterface::ATOM] as $format) {
        $date = DateTime::createFromFormat($format, $value);
        if ($date instanceof DateTime) {
            return $date->format('Y-m-d');
        }
    }

    $timestamp = strtotime($value);
    return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
}

function app_sso_identity_overview(): array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return ['linked' => 0, 'states_active' => 0, 'states_expired' => 0];
    }

    return [
        'linked' => (int) $db->query("SELECT COUNT(*) FROM portal_account_identities WHERE provider = 'mygov'")->fetchColumn(),
        'states_active' => (int) $db->query("SELECT COUNT(*) FROM sso_auth_states WHERE provider = 'mygov' AND consumed_at IS NULL AND expires_at > NOW()")->fetchColumn(),
        'states_expired' => (int) $db->query("SELECT COUNT(*) FROM sso_auth_states WHERE provider = 'mygov' AND consumed_at IS NULL AND expires_at <= NOW()")->fetchColumn(),
    ];
}
