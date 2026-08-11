<?php
declare(strict_types=1);

const APP_FINANCE_AI_BOT_MIGRATION_20260809_380 = '20260809_380_finance_ai_bot';

return [
    'version' => APP_FINANCE_AI_BOT_MIGRATION_20260809_380,
    'description' => 'Add finance_ai_bot_conversations (audit log) and finance_ai_suggestions (staged, human-approved budget-line suggestions) -- pilot for a persona-based finance Q&A assistant grounded in real finance data. The bot never writes to finance_budgets itself: applying a suggestion goes through the existing, already-tested app_finance_budget_save() path',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS finance_ai_bot_conversations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                question TEXT NOT NULL,
                answer TEXT NOT NULL,
                llm_ok TINYINT(1) NOT NULL DEFAULT 0,
                llm_reason VARCHAR(96) DEFAULT NULL,
                context_bundle_json LONGTEXT DEFAULT NULL,
                suggestion_id BIGINT UNSIGNED DEFAULT NULL,
                actor_account_id BIGINT UNSIGNED DEFAULT NULL,
                actor_name VARCHAR(191) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_finance_ai_bot_conversations_actor (actor_account_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS finance_ai_suggestions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                suggestion_type VARCHAR(32) NOT NULL DEFAULT 'budget_line',
                period_month CHAR(7) NOT NULL,
                entry_type ENUM('income','expense') NOT NULL,
                category VARCHAR(96) NOT NULL,
                current_budgeted_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                current_actual_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                suggested_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                reasoning VARCHAR(255) NOT NULL DEFAULT '',
                source_question TEXT DEFAULT NULL,
                status ENUM('pending','applied','dismissed') NOT NULL DEFAULT 'pending',
                resolved_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                resolved_by_name VARCHAR(191) DEFAULT NULL,
                resolved_note VARCHAR(255) DEFAULT NULL,
                resolved_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_finance_ai_suggestions_status (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS finance_ai_bot_conversations');
        $db->exec('DROP TABLE IF EXISTS finance_ai_suggestions');
    },
];
