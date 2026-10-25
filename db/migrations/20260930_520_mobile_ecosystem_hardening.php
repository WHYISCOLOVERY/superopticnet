<?php
declare(strict_types=1);

const APP_MOBILE_ECOSYSTEM_HARDENING_MIGRATION_20260930_520 = '20260930_520_mobile_ecosystem_hardening';

return [
    'version' => APP_MOBILE_ECOSYSTEM_HARDENING_MIGRATION_20260930_520,
    'description' => 'Harden mobile OTP, duty sessions, consent uniqueness, and outbox idempotency.',
    'up' => static function (PDO $db): void {
        $columnExists = static function (string $table, string $column) use ($db): bool {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        };
        $indexExists = static function (string $table, string $index) use ($db): bool {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
            );
            $stmt->execute([$table, $index]);
            return (int) $stmt->fetchColumn() > 0;
        };

        $db->exec(
            "CREATE TABLE IF NOT EXISTS mobile_task_otps (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ticket_id BIGINT UNSIGNED NOT NULL,
                account_id BIGINT UNSIGNED NOT NULL,
                otp_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
                consumed_at DATETIME DEFAULT NULL,
                delivery_status ENUM('pending','queued','failed') NOT NULL DEFAULT 'pending',
                sent_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_mobile_otp_ticket (ticket_id, account_id, consumed_at, expires_at),
                KEY idx_mobile_otp_expiry (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS mobile_duty_sessions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id BIGINT UNSIGNED NOT NULL,
                device_uuid VARCHAR(64) NOT NULL,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ended_at DATETIME DEFAULT NULL,
                ended_reason VARCHAR(64) DEFAULT NULL,
                created_ip VARCHAR(45) DEFAULT NULL,
                KEY idx_mobile_duty_active (account_id, ended_at),
                KEY idx_mobile_duty_device (account_id, device_uuid, started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        if (!$columnExists('mobile_outbox_queue', 'payload_sha256')) {
            $db->exec('ALTER TABLE mobile_outbox_queue ADD COLUMN payload_sha256 CHAR(64) DEFAULT NULL AFTER payload_json');
        }
        if (!$columnExists('mobile_outbox_queue', 'started_at')) {
            $db->exec('ALTER TABLE mobile_outbox_queue ADD COLUMN started_at DATETIME DEFAULT NULL AFTER response_json');
        }
        if (!$columnExists('mobile_outbox_queue', 'updated_at')) {
            $db->exec('ALTER TABLE mobile_outbox_queue ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER started_at');
        }
        if (!$columnExists('employee_live_locations', 'client_uuid')) {
            $db->exec('ALTER TABLE employee_live_locations ADD COLUMN client_uuid VARCHAR(64) DEFAULT NULL AFTER account_id');
        }
        if (!$indexExists('employee_live_locations', 'uq_employee_location_client')) {
            $db->exec('ALTER TABLE employee_live_locations ADD UNIQUE KEY uq_employee_location_client (account_id, client_uuid)');
        }
        $db->exec("UPDATE mobile_outbox_queue SET payload_sha256 = SHA2(payload_json, 256) WHERE payload_sha256 IS NULL OR payload_sha256 = ''");

        $db->exec(
            'DELETE older FROM hr_employee_legal_consents older
             JOIN hr_employee_legal_consents newer
               ON newer.account_id = older.account_id
              AND newer.consent_type = older.consent_type
              AND newer.consent_version = older.consent_version
              AND newer.id > older.id'
        );
        if (!$indexExists('hr_employee_legal_consents', 'uq_mobile_consent_version')) {
            $db->exec('ALTER TABLE hr_employee_legal_consents ADD UNIQUE KEY uq_mobile_consent_version (account_id, consent_type, consent_version)');
        }
    },
    'down' => static function (PDO $db): void {
        $db->exec('ALTER TABLE hr_employee_legal_consents DROP INDEX uq_mobile_consent_version');
        $db->exec('ALTER TABLE employee_live_locations DROP INDEX uq_employee_location_client, DROP COLUMN client_uuid');
        $db->exec('ALTER TABLE mobile_outbox_queue DROP COLUMN updated_at, DROP COLUMN started_at, DROP COLUMN payload_sha256');
        $db->exec('DROP TABLE IF EXISTS mobile_duty_sessions');
        $db->exec('DROP TABLE IF EXISTS mobile_task_otps');
    },
];
