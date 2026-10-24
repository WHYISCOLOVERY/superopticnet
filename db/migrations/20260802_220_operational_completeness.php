<?php
declare(strict_types=1);

const APP_OPERATIONAL_COMPLETENESS_MIGRATION_20260802_220 = '20260802_220_operational_completeness';

return [
    'version' => APP_OPERATIONAL_COMPLETENESS_MIGRATION_20260802_220,
    'description' => 'Add attendance policy, verified ticket resolution, retention campaign recipient and inventory idempotency contracts',
    'up' => static function (PDO $db): void {
        // These tables historically came from the runtime bootstrap. Keep their
        // canonical base definitions in the versioned migration path so a fresh
        // install never depends on opening an HTTP page before migrations run.
        $db->exec(
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS communication_campaign_approvals (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                campaign_key CHAR(64) NOT NULL,
                message_type VARCHAR(32) NOT NULL,
                template_code VARCHAR(64) NOT NULL,
                requested_channel VARCHAR(32) NOT NULL DEFAULT "sms",
                scope VARCHAR(64) NOT NULL,
                area VARCHAR(191) DEFAULT NULL,
                custom_body TEXT DEFAULT NULL,
                recipient_account_ids_json LONGTEXT NOT NULL,
                recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
                estimated_parts INT UNSIGNED NOT NULL DEFAULT 0,
                estimated_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
                encoding VARCHAR(16) NOT NULL DEFAULT "LATIN",
                provider_purpose VARCHAR(8) NOT NULL DEFAULT "ADV",
                approval_status VARCHAR(32) NOT NULL DEFAULT "pending_approval",
                created_by_account_id BIGINT UNSIGNED NOT NULL,
                created_by_name VARCHAR(191) NOT NULL,
                approved_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                approved_by_name VARCHAR(191) DEFAULT NULL,
                approved_at DATETIME DEFAULT NULL,
                queued_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_communication_campaign_key (campaign_key),
                KEY idx_communication_campaign_status (approval_status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $columnExists = static function (string $table, string $column) use ($db): bool {
            $statement = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
            );
            $statement->execute(['table_name' => $table, 'column_name' => $column]);
            return (int) $statement->fetchColumn() > 0;
        };
        $indexExists = static function (string $table, string $index) use ($db): bool {
            $statement = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name'
            );
            $statement->execute(['table_name' => $table, 'index_name' => $index]);
            return (int) $statement->fetchColumn() > 0;
        };
        $addColumn = static function (string $table, string $column, string $definition) use ($db, $columnExists): void {
            if (!$columnExists($table, $column)) {
                $db->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
            }
        };

        $addColumn('hr_attendance_profiles', 'work_days_json', 'LONGTEXT DEFAULT NULL AFTER expected_work_minutes');
        $addColumn('hr_attendance_profiles', 'effective_from', 'DATE DEFAULT NULL AFTER work_days_json');
        $addColumn('hr_attendance_profiles', 'effective_to', 'DATE DEFAULT NULL AFTER effective_from');
        $addColumn('hr_attendance_profiles', 'overtime_after_minutes', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER effective_to');
        $addColumn('hr_attendance_profiles', 'absence_after_minutes', 'INT UNSIGNED NOT NULL DEFAULT 240 AFTER overtime_after_minutes');
        $addColumn('hr_attendance_profiles', 'policy_json', 'LONGTEXT DEFAULT NULL AFTER absence_after_minutes');

        $addColumn('hr_attendance_events', 'scheduled_minutes', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER worked_minutes');
        $addColumn('hr_attendance_events', 'overtime_minutes', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER scheduled_minutes');
        $addColumn('hr_attendance_events', 'approval_status', 'VARCHAR(32) NOT NULL DEFAULT "draft" AFTER overtime_minutes');
        $addColumn('hr_attendance_events', 'approved_by_account_id', 'BIGINT UNSIGNED DEFAULT NULL AFTER checked_out_by_account_id');
        $addColumn('hr_attendance_events', 'approved_by_name', 'VARCHAR(191) DEFAULT NULL AFTER approved_by_account_id');
        $addColumn('hr_attendance_events', 'approved_at', 'DATETIME DEFAULT NULL AFTER approved_by_name');

        $addColumn('hr_score_events', 'source_type', 'VARCHAR(64) DEFAULT NULL AFTER note');
        $addColumn('hr_score_events', 'source_id', 'VARCHAR(96) DEFAULT NULL AFTER source_type');
        $addColumn('hr_score_events', 'idempotency_key', 'VARCHAR(191) DEFAULT NULL AFTER source_id');
        if (!$indexExists('hr_score_events', 'uq_hr_score_idempotency')) {
            $db->exec('ALTER TABLE hr_score_events ADD UNIQUE KEY uq_hr_score_idempotency (idempotency_key)');
        }

        $db->exec("ALTER TABLE hr_payroll_records MODIFY status ENUM('draft','pending_approval','approved','paid','reversed','void') NOT NULL DEFAULT 'draft'");
        $db->exec("ALTER TABLE hr_leave_requests MODIFY status ENUM('draft','pending_approval','approved','paid','reversed','cancelled') NOT NULL DEFAULT 'draft'");
        $db->exec("ALTER TABLE hr_travel_requests MODIFY status ENUM('draft','pending_approval','approved','paid','reversed','cancelled') NOT NULL DEFAULT 'draft'");

        $addColumn('portal_tickets', 'resolution_verification_status', 'VARCHAR(32) NOT NULL DEFAULT "none" AFTER resolution_note');
        $addColumn('portal_tickets', 'resolution_check_json', 'LONGTEXT DEFAULT NULL AFTER resolution_verification_status');
        $addColumn('portal_tickets', 'close_requested_at', 'DATETIME DEFAULT NULL AFTER resolution_check_json');
        $addColumn('portal_tickets', 'verified_at', 'DATETIME DEFAULT NULL AFTER close_requested_at');
        $addColumn('portal_tickets', 'verification_attempts', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER verified_at');
        if (!$indexExists('portal_tickets', 'idx_portal_ticket_verification')) {
            $db->exec('ALTER TABLE portal_tickets ADD INDEX idx_portal_ticket_verification (resolution_verification_status, updated_at)');
        }

        $db->exec(
            "CREATE TABLE IF NOT EXISTS ticket_resolution_checks (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ticket_id BIGINT UNSIGNED NOT NULL,
                ticket_no VARCHAR(64) NOT NULL,
                check_status VARCHAR(32) NOT NULL,
                check_method VARCHAR(32) NOT NULL,
                evidence_json LONGTEXT DEFAULT NULL,
                reason VARCHAR(255) NOT NULL,
                note TEXT NOT NULL,
                correlation_id VARCHAR(96) NOT NULL,
                idempotency_key VARCHAR(191) NOT NULL,
                actor_account_id BIGINT UNSIGNED DEFAULT NULL,
                actor_name VARCHAR(191) DEFAULT NULL,
                warning_dedup_key VARCHAR(191) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_ticket_resolution_idempotency (idempotency_key),
                UNIQUE KEY uq_ticket_resolution_correlation (correlation_id),
                KEY idx_ticket_resolution_ticket (ticket_id, created_at),
                KEY idx_ticket_resolution_status (check_status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $addColumn('communication_campaign_approvals', 'offer_snapshot_json', 'LONGTEXT DEFAULT NULL AFTER custom_body');
        $addColumn('communication_campaign_approvals', 'eligibility_json', 'LONGTEXT DEFAULT NULL AFTER offer_snapshot_json');
        $addColumn('communication_campaign_approvals', 'idempotency_key', 'VARCHAR(191) DEFAULT NULL AFTER campaign_key');
        $addColumn('communication_campaign_approvals', 'rejection_reason', 'VARCHAR(255) DEFAULT NULL AFTER approval_status');
        if (!$indexExists('communication_campaign_approvals', 'uq_campaign_idempotency')) {
            $db->exec('ALTER TABLE communication_campaign_approvals ADD UNIQUE KEY uq_campaign_idempotency (idempotency_key)');
        }
        $db->exec(
            "CREATE TABLE IF NOT EXISTS communication_campaign_recipients (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                campaign_id BIGINT UNSIGNED NOT NULL,
                account_id BIGINT UNSIGNED NOT NULL,
                subscriber_code VARCHAR(64) DEFAULT NULL,
                eligibility_status VARCHAR(32) NOT NULL DEFAULT 'eligible',
                eligibility_reason VARCHAR(255) DEFAULT NULL,
                inactivity_days INT UNSIGNED NOT NULL DEFAULT 0,
                last_payment_at DATETIME DEFAULT NULL,
                offer_snapshot_json LONGTEXT DEFAULT NULL,
                queue_status VARCHAR(32) NOT NULL DEFAULT 'proposed',
                message_id BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_campaign_recipient (campaign_id, account_id),
                KEY idx_campaign_recipient_status (campaign_id, queue_status),
                KEY idx_campaign_recipient_account (account_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $addColumn('inventory_documents', 'idempotency_key', 'VARCHAR(191) DEFAULT NULL AFTER document_no');
        if (!$indexExists('inventory_documents', 'uq_inventory_document_idempotency')) {
            $db->exec('ALTER TABLE inventory_documents ADD UNIQUE KEY uq_inventory_document_idempotency (idempotency_key)');
        }
    },
    'down' => static function (PDO $db): void {
        $activeChecks = (int) $db->query('SELECT COUNT(*) FROM ticket_resolution_checks')->fetchColumn();
        $activeRecipients = (int) $db->query('SELECT COUNT(*) FROM communication_campaign_recipients')->fetchColumn();
        $advancedTicketRows = (int) $db->query('SELECT COUNT(*) FROM portal_tickets WHERE resolution_verification_status <> "none"')->fetchColumn();
        if ($activeChecks > 0 || $activeRecipients > 0 || $advancedTicketRows > 0) {
            throw new RuntimeException('operational_completeness_rollback_requires_empty_workflows');
        }

        $db->exec('DROP TABLE IF EXISTS communication_campaign_recipients');
        $db->exec('DROP TABLE IF EXISTS ticket_resolution_checks');
        $db->exec('ALTER TABLE communication_campaign_approvals DROP INDEX uq_campaign_idempotency, DROP COLUMN rejection_reason, DROP COLUMN idempotency_key, DROP COLUMN eligibility_json, DROP COLUMN offer_snapshot_json');
        $db->exec('ALTER TABLE inventory_documents DROP INDEX uq_inventory_document_idempotency, DROP COLUMN idempotency_key');
        $db->exec('ALTER TABLE portal_tickets DROP INDEX idx_portal_ticket_verification, DROP COLUMN verification_attempts, DROP COLUMN verified_at, DROP COLUMN close_requested_at, DROP COLUMN resolution_check_json, DROP COLUMN resolution_verification_status');
        $db->exec('ALTER TABLE hr_attendance_events DROP COLUMN approved_at, DROP COLUMN approved_by_name, DROP COLUMN approved_by_account_id, DROP COLUMN approval_status, DROP COLUMN overtime_minutes, DROP COLUMN scheduled_minutes');
        $db->exec('ALTER TABLE hr_attendance_profiles DROP COLUMN policy_json, DROP COLUMN absence_after_minutes, DROP COLUMN overtime_after_minutes, DROP COLUMN effective_to, DROP COLUMN effective_from, DROP COLUMN work_days_json');
        $db->exec('ALTER TABLE hr_score_events DROP INDEX uq_hr_score_idempotency, DROP COLUMN idempotency_key, DROP COLUMN source_id, DROP COLUMN source_type');
        $db->exec("ALTER TABLE hr_payroll_records MODIFY status ENUM('draft','approved','paid','void') NOT NULL DEFAULT 'draft'");
        $db->exec("ALTER TABLE hr_leave_requests MODIFY status ENUM('draft','approved','paid','cancelled') NOT NULL DEFAULT 'draft'");
        $db->exec("ALTER TABLE hr_travel_requests MODIFY status ENUM('draft','approved','paid','cancelled') NOT NULL DEFAULT 'draft'");
    },
];
