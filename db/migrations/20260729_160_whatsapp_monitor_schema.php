<?php
declare(strict_types=1);

const APP_WHATSAPP_MONITOR_MIGRATION_20260729_160 = '20260729_160_whatsapp_monitor_schema';

return [
    'version' => APP_WHATSAPP_MONITOR_MIGRATION_20260729_160,
    'description' => 'Create WhatsApp monitor tables and defaults outside HTTP rendering',
    'up' => static function (PDO $db): void {
        $statements = [
            "CREATE TABLE IF NOT EXISTS whatsapp_employee_numbers (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                employee_id BIGINT UNSIGNED DEFAULT NULL,
                phone_number VARCHAR(32) NOT NULL,
                normalized_phone VARCHAR(32) NOT NULL,
                whatsapp_account_name VARCHAR(191) DEFAULT NULL,
                assigned_area_id VARCHAR(128) DEFAULT NULL,
                assigned_at DATETIME DEFAULT NULL,
                returned_at DATETIME DEFAULT NULL,
                status ENUM('active','inactive','returned','lost','blocked') NOT NULL DEFAULT 'active',
                is_company_owned TINYINT(1) NOT NULL DEFAULT 1,
                monitoring_policy_accepted TINYINT(1) NOT NULL DEFAULT 0,
                qr_status VARCHAR(32) NOT NULL DEFAULT 'not_connected',
                qr_session_key VARCHAR(128) DEFAULT NULL,
                qr_last_requested_at DATETIME DEFAULT NULL,
                qr_connected_at DATETIME DEFAULT NULL,
                qr_last_seen_at DATETIME DEFAULT NULL,
                qr_last_error VARCHAR(255) DEFAULT NULL,
                policy_version VARCHAR(32) NOT NULL DEFAULT '2026-07',
                accepted_at DATETIME DEFAULT NULL,
                accepted_by BIGINT UNSIGNED DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_whatsapp_numbers_employee (employee_id, status),
                KEY idx_whatsapp_numbers_phone (normalized_phone, status),
                KEY idx_whatsapp_numbers_area (assigned_area_id, status),
                KEY idx_whatsapp_numbers_qr_status (qr_status, qr_last_requested_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS whatsapp_monitor_devices (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_name VARCHAR(191) NOT NULL,
                device_code VARCHAR(64) NOT NULL,
                employee_id BIGINT UNSIGNED DEFAULT NULL,
                phone_number_id BIGINT UNSIGNED DEFAULT NULL,
                area_id VARCHAR(128) DEFAULT NULL,
                device_type ENUM('android','iphone','web','other') NOT NULL DEFAULT 'android',
                connection_type ENUM('usb','tcpip','stream_url','mdm','manual') NOT NULL DEFAULT 'stream_url',
                stream_url VARCHAR(500) DEFAULT NULL,
                status ENUM('online','offline','warning','disabled') NOT NULL DEFAULT 'offline',
                monitoring_enabled TINYINT(1) NOT NULL DEFAULT 1,
                last_seen_at DATETIME DEFAULT NULL,
                last_stream_at DATETIME DEFAULT NULL,
                last_error VARCHAR(255) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_by BIGINT UNSIGNED DEFAULT NULL,
                updated_by BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_whatsapp_device_code (device_code),
                KEY idx_whatsapp_devices_employee (employee_id, status),
                KEY idx_whatsapp_devices_phone (phone_number_id),
                KEY idx_whatsapp_devices_area (area_id, status),
                KEY idx_whatsapp_devices_status (status, monitoring_enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS whatsapp_monitor_cases (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_id BIGINT UNSIGNED DEFAULT NULL,
                employee_id BIGINT UNSIGNED DEFAULT NULL,
                customer_id BIGINT UNSIGNED DEFAULT NULL,
                ticket_id BIGINT UNSIGNED DEFAULT NULL,
                ticket_no VARCHAR(32) DEFAULT NULL,
                case_type ENUM('unanswered','complaint','payment','technical','sales','unknown') NOT NULL DEFAULT 'unanswered',
                status ENUM('new','waiting_employee','in_progress','replied','escalated','resolved','ignored') NOT NULL DEFAULT 'new',
                priority ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal',
                opened_by BIGINT UNSIGNED DEFAULT NULL,
                assigned_to BIGINT UNSIGNED DEFAULT NULL,
                opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                replied_at DATETIME DEFAULT NULL,
                resolved_at DATETIME DEFAULT NULL,
                response_time_minutes INT UNSIGNED DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_whatsapp_cases_device (device_id, status),
                KEY idx_whatsapp_cases_employee (employee_id, status),
                KEY idx_whatsapp_cases_customer (customer_id, status),
                KEY idx_whatsapp_cases_assigned (assigned_to, status),
                KEY idx_whatsapp_cases_opened (opened_at, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS whatsapp_monitor_screenshots (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_id BIGINT UNSIGNED DEFAULT NULL,
                employee_id BIGINT UNSIGNED DEFAULT NULL,
                customer_id BIGINT UNSIGNED DEFAULT NULL,
                case_id BIGINT UNSIGNED DEFAULT NULL,
                taken_by BIGINT UNSIGNED DEFAULT NULL,
                reason VARCHAR(255) NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                visibility ENUM('super_admin','crm_lead','restricted') NOT NULL DEFAULT 'restricted',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_whatsapp_screenshots_case (case_id, created_at),
                KEY idx_whatsapp_screenshots_device (device_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS whatsapp_monitor_pairings (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                number_id BIGINT UNSIGNED NOT NULL,
                employee_id BIGINT UNSIGNED DEFAULT NULL,
                device_id BIGINT UNSIGNED DEFAULT NULL,
                session_key VARCHAR(128) NOT NULL,
                pairing_status ENUM('requested','qr_ready','scanned','connected','expired','failed','revoked') NOT NULL DEFAULT 'requested',
                qr_payload LONGTEXT DEFAULT NULL,
                qr_image_data_uri LONGTEXT DEFAULT NULL,
                worker_id VARCHAR(128) DEFAULT NULL,
                requested_by BIGINT UNSIGNED DEFAULT NULL,
                updated_by BIGINT UNSIGNED DEFAULT NULL,
                expires_at DATETIME DEFAULT NULL,
                scanned_at DATETIME DEFAULT NULL,
                connected_at DATETIME DEFAULT NULL,
                last_seen_at DATETIME DEFAULT NULL,
                failure_reason VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_whatsapp_pairing_session (session_key),
                KEY idx_whatsapp_pairing_number (number_id, pairing_status),
                KEY idx_whatsapp_pairing_employee (employee_id, pairing_status),
                KEY idx_whatsapp_pairing_device (device_id, pairing_status),
                KEY idx_whatsapp_pairing_status (pairing_status, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS whatsapp_monitor_messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                number_id BIGINT UNSIGNED NOT NULL,
                employee_id BIGINT UNSIGNED DEFAULT NULL,
                session_key VARCHAR(128) NOT NULL,
                whatsapp_message_id VARCHAR(191) NOT NULL,
                chat_id VARCHAR(191) NOT NULL,
                sender_id VARCHAR(191) DEFAULT NULL,
                recipient_id VARCHAR(191) DEFAULT NULL,
                contact_name VARCHAR(191) DEFAULT NULL,
                direction ENUM('inbound','outbound') NOT NULL,
                message_type VARCHAR(64) NOT NULL DEFAULT 'chat',
                message_body TEXT DEFAULT NULL,
                has_media TINYINT(1) NOT NULL DEFAULT 0,
                occurred_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_whatsapp_monitor_message (whatsapp_message_id),
                KEY idx_whatsapp_monitor_chat (number_id, chat_id, occurred_at),
                KEY idx_whatsapp_monitor_employee (employee_id, occurred_at),
                KEY idx_whatsapp_monitor_direction (direction, occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS whatsapp_monitor_settings (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(128) NOT NULL,
                setting_value VARCHAR(255) NOT NULL DEFAULT '',
                value_type ENUM('string','int','bool','time') NOT NULL DEFAULT 'string',
                description VARCHAR(255) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by BIGINT UNSIGNED DEFAULT NULL,
                updated_by BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_whatsapp_setting_key (setting_key),
                KEY idx_whatsapp_settings_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS whatsapp_monitor_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_id BIGINT UNSIGNED DEFAULT NULL,
                employee_id BIGINT UNSIGNED DEFAULT NULL,
                event_type VARCHAR(64) NOT NULL,
                event_payload_json LONGTEXT DEFAULT NULL,
                source ENUM('system','manual','stream','future_api','future_ocr') NOT NULL DEFAULT 'manual',
                created_by BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_whatsapp_events_device (device_id, created_at),
                KEY idx_whatsapp_events_employee (employee_id, created_at),
                KEY idx_whatsapp_events_type (event_type, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS whatsapp_monitor_audit_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED DEFAULT NULL,
                action VARCHAR(64) NOT NULL,
                entity_type VARCHAR(64) NOT NULL,
                entity_id VARCHAR(64) NOT NULL,
                old_values LONGTEXT DEFAULT NULL,
                new_values LONGTEXT DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_whatsapp_audit_entity (entity_type, entity_id),
                KEY idx_whatsapp_audit_user (user_id, created_at),
                KEY idx_whatsapp_audit_action (action, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($statements as $statement) {
            $db->exec($statement);
        }

        $defaults = [
            'unanswered_warning_minutes' => ['15', 'int', 'Cavabsiz mesaj warning limiti'],
            'unanswered_critical_minutes' => ['60', 'int', 'Critical gecikme limiti'],
            'offline_warning_minutes' => ['10', 'int', 'Offline cihaz warning limiti'],
            'stream_refresh_seconds' => ['10', 'int', 'Screen Wall refresh interval'],
            'default_grid_columns' => ['4', 'int', 'Monitor grid default layout'],
            'screenshot_enabled' => ['1', 'bool', 'Screenshot sistemi aktivdir'],
            'employee_can_view_own_performance' => ['1', 'bool', 'Emekdas oz performansini gore bilir'],
            'workday_start' => ['09:00', 'time', 'Is vaxti baslangici'],
            'workday_end' => ['18:00', 'time', 'Is vaxti sonu'],
            'require_reason_for_screenshot' => ['1', 'bool', 'Screenshot ucun sebeb mecburidir'],
            'pairing_qr_ttl_seconds' => ['600', 'int', 'QR baglanti sessiyasinin aktiv qalma muddeti'],
            'worker_api_token' => ['', 'string', 'WhatsApp worker API token'],
            'heartbeat_stale_minutes' => ['10', 'int', 'Heartbeat bu qeder kohnedirse status online sayilmir'],
            'sync_stale_minutes' => ['30', 'int', 'Sinxronizasiya bu qeder gecikirse status offline sayilir'],
        ];
        $seed = $db->prepare(
            'INSERT IGNORE INTO whatsapp_monitor_settings
                (setting_key, setting_value, value_type, description)
             VALUES (:setting_key, :setting_value, :value_type, :description)'
        );
        foreach ($defaults as $key => [$value, $type, $description]) {
            $seed->execute([
                'setting_key' => $key,
                'setting_value' => $value,
                'value_type' => $type,
                'description' => $description,
            ]);
        }
    },
    'down' => static function (PDO $db): void {
        foreach ([
            'whatsapp_employee_numbers',
            'whatsapp_monitor_devices',
            'whatsapp_monitor_cases',
            'whatsapp_monitor_screenshots',
            'whatsapp_monitor_pairings',
            'whatsapp_monitor_messages',
            'whatsapp_monitor_events',
            'whatsapp_monitor_audit_logs',
        ] as $table) {
            try {
                if ((int) $db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() > 0) {
                    throw new RuntimeException('whatsapp_monitor_rollback_requires_empty_operational_tables');
                }
            } catch (PDOException) {
            }
        }

        foreach ([
            'whatsapp_monitor_audit_logs',
            'whatsapp_monitor_events',
            'whatsapp_monitor_settings',
            'whatsapp_monitor_messages',
            'whatsapp_monitor_pairings',
            'whatsapp_monitor_screenshots',
            'whatsapp_monitor_cases',
            'whatsapp_monitor_devices',
            'whatsapp_employee_numbers',
        ] as $table) {
            $db->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
    },
];
