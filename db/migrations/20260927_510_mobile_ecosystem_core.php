<?php
declare(strict_types=1);

const APP_MOBILE_ECOSYSTEM_CORE_MIGRATION_20260927_510 = '20260927_510_mobile_ecosystem_core';

return [
    'version' => APP_MOBILE_ECOSYSTEM_CORE_MIGRATION_20260927_510,
    'description' => 'Create core mobile ecosystem tables: mobile_device_tokens, '
        . 'employee_live_locations, mobile_outbox_queue, hr_employee_legal_consents, '
        . 'mobile_task_proofs, mobile_auth_refresh_tokens, and mobile_app_config.',
    'up' => static function (PDO $db): void {
        // 1. Device tokens (FCM, HMS, Device info, Revoke/Remote-wipe)
        $db->exec(
            "CREATE TABLE IF NOT EXISTS mobile_device_tokens (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id BIGINT UNSIGNED NOT NULL,
                device_uuid VARCHAR(64) NOT NULL,
                fcm_token VARCHAR(255) DEFAULT NULL,
                hms_token VARCHAR(255) DEFAULT NULL,
                platform ENUM('android', 'ios', 'huawei') NOT NULL DEFAULT 'android',
                device_model VARCHAR(128) DEFAULT NULL,
                os_version VARCHAR(64) DEFAULT NULL,
                app_version VARCHAR(32) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                revoked_at DATETIME DEFAULT NULL,
                revoked_reason VARCHAR(255) DEFAULT NULL,
                last_seen_at DATETIME DEFAULT NULL,
                last_ip VARCHAR(45) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_device_account (account_id, device_uuid),
                KEY idx_device_active (is_active, last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // 2. Employee Live GPS locations (Fleet tracking, on-duty gate, mock detection)
        $db->exec(
            "CREATE TABLE IF NOT EXISTS employee_live_locations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id BIGINT UNSIGNED NOT NULL,
                latitude DECIMAL(10, 7) NOT NULL,
                longitude DECIMAL(10, 7) NOT NULL,
                accuracy DECIMAL(8, 2) DEFAULT NULL,
                speed DECIMAL(6, 2) DEFAULT NULL,
                heading DECIMAL(6, 2) DEFAULT NULL,
                altitude DECIMAL(8, 2) DEFAULT NULL,
                battery_level TINYINT UNSIGNED DEFAULT NULL,
                is_charging TINYINT(1) DEFAULT 0,
                is_mock TINYINT(1) DEFAULT 0,
                is_on_duty TINYINT(1) NOT NULL DEFAULT 1,
                activity_type VARCHAR(32) DEFAULT 'unknown',
                recorded_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_emp_location_time (account_id, recorded_at),
                KEY idx_emp_latest (account_id, id DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // 3. Mobile Outbox Queue (Offline-first idempotent mutations)
        $db->exec(
            "CREATE TABLE IF NOT EXISTS mobile_outbox_queue (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                client_uuid VARCHAR(64) NOT NULL UNIQUE,
                account_id BIGINT UNSIGNED NOT NULL,
                action_type VARCHAR(64) NOT NULL,
                payload_json LONGTEXT NOT NULL,
                status ENUM('received', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'completed',
                response_code SMALLINT UNSIGNED NOT NULL DEFAULT 200,
                response_json LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_outbox_account_action (account_id, action_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // 4. Employee Legal Consents (Labor Code compliance for GPS tracking)
        $db->exec(
            "CREATE TABLE IF NOT EXISTS hr_employee_legal_consents (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id BIGINT UNSIGNED NOT NULL,
                consent_type VARCHAR(48) NOT NULL DEFAULT 'gps_tracking_labor_code',
                consent_version VARCHAR(16) NOT NULL DEFAULT 'v1.0',
                ip_address VARCHAR(45) NOT NULL,
                user_agent VARCHAR(255) NOT NULL,
                device_uuid VARCHAR(64) DEFAULT NULL,
                agreed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_legal_account_consent (account_id, consent_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // 5. Mobile Task Proofs (Anti-fraud handover with watermark, OCR, and customer OTP)
        $db->exec(
            "CREATE TABLE IF NOT EXISTS mobile_task_proofs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ticket_id BIGINT UNSIGNED NOT NULL,
                account_id BIGINT UNSIGNED NOT NULL,
                client_uuid VARCHAR(64) NOT NULL,
                photo_path VARCHAR(255) DEFAULT NULL,
                watermark_data TEXT DEFAULT NULL,
                optical_power_dbm DECIMAL(5, 2) DEFAULT NULL,
                ocr_raw_text VARCHAR(255) DEFAULT NULL,
                customer_otp_code VARCHAR(8) DEFAULT NULL,
                otp_verified TINYINT(1) NOT NULL DEFAULT 0,
                otp_verified_at DATETIME DEFAULT NULL,
                signature_path VARCHAR(255) DEFAULT NULL,
                resolution_notes TEXT DEFAULT NULL,
                materials_json TEXT DEFAULT NULL,
                status ENUM('submitted', 'approved', 'rejected') NOT NULL DEFAULT 'submitted',
                reviewed_by BIGINT UNSIGNED DEFAULT NULL,
                reviewed_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_proof_ticket (ticket_id),
                KEY idx_proof_account (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // 6. Mobile Auth Refresh Tokens (Single-use rotation, secure session management)
        $db->exec(
            "CREATE TABLE IF NOT EXISTS mobile_auth_refresh_tokens (
                token_hash CHAR(64) NOT NULL PRIMARY KEY,
                account_id BIGINT UNSIGNED NOT NULL,
                device_uuid VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                is_used TINYINT(1) NOT NULL DEFAULT 0,
                used_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_refresh_account_device (account_id, device_uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // 7. Mobile App Version Config (Force-update gate, release notes, store URLs)
        $db->exec(
            "CREATE TABLE IF NOT EXISTS mobile_app_config (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                platform ENUM('android', 'ios', 'huawei') NOT NULL,
                min_supported_version VARCHAR(24) NOT NULL DEFAULT '1.0.0',
                latest_version VARCHAR(24) NOT NULL DEFAULT '1.0.0',
                force_update TINYINT(1) NOT NULL DEFAULT 0,
                store_url VARCHAR(255) NOT NULL DEFAULT '',
                release_notes TEXT DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_platform (platform)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Seed initial platform version configs
        $platforms = ['android', 'ios', 'huawei'];
        $stmt = $db->prepare(
            "INSERT IGNORE INTO mobile_app_config 
             (platform, min_supported_version, latest_version, force_update, store_url, release_notes)
             VALUES (?, '1.0.0', '1.0.0', 0, 'https://superoptic.net/downloads/app', 'İlkin reliz')"
        );
        foreach ($platforms as $p) {
            $stmt->execute([$p]);
        }
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS mobile_app_config');
        $db->exec('DROP TABLE IF EXISTS mobile_auth_refresh_tokens');
        $db->exec('DROP TABLE IF EXISTS mobile_task_proofs');
        $db->exec('DROP TABLE IF EXISTS hr_employee_legal_consents');
        $db->exec('DROP TABLE IF EXISTS mobile_outbox_queue');
        $db->exec('DROP TABLE IF EXISTS employee_live_locations');
        $db->exec('DROP TABLE IF EXISTS mobile_device_tokens');
    },
];
