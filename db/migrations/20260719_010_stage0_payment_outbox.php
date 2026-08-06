<?php
declare(strict_types=1);

return [
    'version' => '20260719_010_stage0_payment_outbox',
    'description' => 'Durable Billing payment state, RADIUS service actions, outbox, attempts and reversals',
    'up' => static function (PDO $db): void {
        $state = "ENUM(
            'payment_recorded',
            'service_action_pending',
            'service_action_processing',
            'service_action_completed',
            'service_action_retrying',
            'manual_reconciliation_required',
            'reversed'
        )";

        $db->exec(
            "CREATE TABLE IF NOT EXISTS billing_payment_operations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                payment_id BIGINT UNSIGNED DEFAULT NULL,
                account_id BIGINT UNSIGNED NOT NULL,
                subscriber_code VARCHAR(64) NOT NULL,
                idempotency_key VARCHAR(191) NOT NULL,
                request_hash CHAR(64) NOT NULL,
                correlation_id VARCHAR(96) NOT NULL,
                state {$state} NOT NULL DEFAULT 'payment_recorded',
                amount DECIMAL(10,2) NOT NULL,
                payment_method VARCHAR(64) NOT NULL,
                transaction_id VARCHAR(191) DEFAULT NULL,
                external_id VARCHAR(64) DEFAULT NULL,
                payment_effect VARCHAR(64) NOT NULL DEFAULT '',
                before_snapshot_json LONGTEXT DEFAULT NULL,
                last_error_code VARCHAR(96) DEFAULT NULL,
                last_error_message VARCHAR(255) DEFAULT NULL,
                completed_at DATETIME DEFAULT NULL,
                reversed_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_billing_payment_operation_payment (payment_id),
                UNIQUE KEY uq_billing_payment_operation_idempotency (idempotency_key),
                KEY idx_billing_payment_operation_state (state, updated_at),
                KEY idx_billing_payment_operation_correlation (correlation_id),
                KEY idx_billing_payment_operation_account (account_id, created_at),
                CONSTRAINT fk_billing_payment_operation_payment
                    FOREIGN KEY (payment_id) REFERENCES billing_payments(id) ON DELETE RESTRICT,
                CONSTRAINT fk_billing_payment_operation_account
                    FOREIGN KEY (account_id) REFERENCES portal_accounts(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS billing_payment_service_actions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                operation_id BIGINT UNSIGNED NOT NULL,
                action_type ENUM('radius_sync','radius_compensation') NOT NULL,
                action_key VARCHAR(191) NOT NULL,
                correlation_id VARCHAR(96) NOT NULL,
                state {$state} NOT NULL DEFAULT 'service_action_pending',
                attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
                next_attempt_at DATETIME DEFAULT NULL,
                last_error_code VARCHAR(96) DEFAULT NULL,
                last_error_message VARCHAR(255) DEFAULT NULL,
                completed_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_billing_service_action_key (action_key),
                UNIQUE KEY uq_billing_service_action_type (operation_id, action_type),
                KEY idx_billing_service_action_state (state, next_attempt_at),
                KEY idx_billing_service_action_correlation (correlation_id),
                CONSTRAINT fk_billing_service_action_operation
                    FOREIGN KEY (operation_id) REFERENCES billing_payment_operations(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS billing_payment_outbox (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                service_action_id BIGINT UNSIGNED NOT NULL,
                job_type VARCHAR(64) NOT NULL DEFAULT 'radius_sync',
                status ENUM('pending','processing','retrying','completed','manual_required','cancelled')
                    NOT NULL DEFAULT 'pending',
                available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                locked_at DATETIME DEFAULT NULL,
                locked_by VARCHAR(96) DEFAULT NULL,
                attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_error_code VARCHAR(96) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_billing_payment_outbox_action (service_action_id),
                KEY idx_billing_payment_outbox_claim (status, available_at, locked_at),
                CONSTRAINT fk_billing_payment_outbox_action
                    FOREIGN KEY (service_action_id) REFERENCES billing_payment_service_actions(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS billing_payment_service_attempts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                service_action_id BIGINT UNSIGNED NOT NULL,
                attempt_no INT UNSIGNED NOT NULL,
                correlation_id VARCHAR(96) NOT NULL,
                result ENUM('processing','completed','retryable_failure','manual_required') NOT NULL,
                error_code VARCHAR(96) DEFAULT NULL,
                error_message VARCHAR(255) DEFAULT NULL,
                metadata_json LONGTEXT DEFAULT NULL,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME DEFAULT NULL,
                UNIQUE KEY uq_billing_service_attempt_no (service_action_id, attempt_no),
                KEY idx_billing_service_attempt_correlation (correlation_id),
                CONSTRAINT fk_billing_service_attempt_action
                    FOREIGN KEY (service_action_id) REFERENCES billing_payment_service_actions(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS billing_payment_operation_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                operation_id BIGINT UNSIGNED NOT NULL,
                service_action_id BIGINT UNSIGNED DEFAULT NULL,
                from_state VARCHAR(64) DEFAULT NULL,
                to_state {$state} NOT NULL,
                reason_code VARCHAR(96) NOT NULL,
                correlation_id VARCHAR(96) NOT NULL,
                metadata_json LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_billing_payment_event_operation (operation_id, id),
                KEY idx_billing_payment_event_correlation (correlation_id),
                CONSTRAINT fk_billing_payment_event_operation
                    FOREIGN KEY (operation_id) REFERENCES billing_payment_operations(id) ON DELETE RESTRICT,
                CONSTRAINT fk_billing_payment_event_action
                    FOREIGN KEY (service_action_id) REFERENCES billing_payment_service_actions(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS billing_payment_reversals (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                operation_id BIGINT UNSIGNED NOT NULL,
                payment_id BIGINT UNSIGNED NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                actor_account_id BIGINT UNSIGNED DEFAULT NULL,
                actor_name VARCHAR(191) DEFAULT NULL,
                correlation_id VARCHAR(96) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_billing_payment_reversal_operation (operation_id),
                UNIQUE KEY uq_billing_payment_reversal_payment (payment_id),
                KEY idx_billing_payment_reversal_correlation (correlation_id),
                CONSTRAINT fk_billing_payment_reversal_operation
                    FOREIGN KEY (operation_id) REFERENCES billing_payment_operations(id) ON DELETE RESTRICT,
                CONSTRAINT fk_billing_payment_reversal_payment
                    FOREIGN KEY (payment_id) REFERENCES billing_payments(id) ON DELETE RESTRICT,
                CONSTRAINT fk_billing_payment_reversal_actor
                    FOREIGN KEY (actor_account_id) REFERENCES portal_accounts(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $tables = [
            'billing_payment_reversals',
            'billing_payment_operation_events',
            'billing_payment_service_attempts',
            'billing_payment_outbox',
            'billing_payment_service_actions',
            'billing_payment_operations',
        ];
        foreach ($tables as $table) {
            $count = (int) $db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
            if ($count !== 0) {
                throw new RuntimeException('migration_not_empty:' . $table);
            }
        }
        foreach ($tables as $table) {
            $db->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
    },
];

