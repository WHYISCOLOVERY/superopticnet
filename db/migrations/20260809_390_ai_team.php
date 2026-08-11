<?php
declare(strict_types=1);

const APP_AI_TEAM_MIGRATION_20260809_390 = '20260809_390_ai_team';

return [
    'version' => APP_AI_TEAM_MIGRATION_20260809_390,
    'description' => 'Add ai_team_conversations and ai_team_tasks -- the 5-persona "AI Komanda" hub (CEO/CRM/network/warehouse/marketing; finance already has its own dedicated pilot). Tasks are AI-drafted from the existing advisor-agent-lib.php briefings, always pending_admin_review until a human admin approves+assigns+sends -- the AI never assigns work or messages an employee on its own',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS ai_team_conversations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                persona_key VARCHAR(32) NOT NULL,
                question TEXT NOT NULL,
                answer TEXT NOT NULL,
                llm_ok TINYINT(1) NOT NULL DEFAULT 0,
                llm_reason VARCHAR(96) DEFAULT NULL,
                context_bundle_json LONGTEXT DEFAULT NULL,
                staged_task_ids_json VARCHAR(255) DEFAULT NULL,
                actor_account_id BIGINT UNSIGNED DEFAULT NULL,
                actor_name VARCHAR(191) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ai_team_conversations_persona (persona_key, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS ai_team_tasks (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                persona_key VARCHAR(32) NOT NULL,
                proposal_type VARCHAR(64) NOT NULL DEFAULT '',
                title VARCHAR(191) NOT NULL,
                description TEXT NOT NULL,
                reasoning VARCHAR(255) DEFAULT NULL,
                risk VARCHAR(16) NOT NULL DEFAULT 'medium',
                status ENUM('pending_admin_review','approved','dismissed','completed') NOT NULL DEFAULT 'pending_admin_review',
                assigned_employee_account_id BIGINT UNSIGNED DEFAULT NULL,
                assigned_employee_name VARCHAR(191) DEFAULT NULL,
                priority VARCHAR(16) NOT NULL DEFAULT 'normal',
                telegram_sent TINYINT(1) NOT NULL DEFAULT 0,
                telegram_sent_reason VARCHAR(96) DEFAULT NULL,
                response_note TEXT DEFAULT NULL,
                responded_at DATETIME DEFAULT NULL,
                created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                created_by_name VARCHAR(191) DEFAULT NULL,
                approved_by_account_id BIGINT UNSIGNED DEFAULT NULL,
                approved_by_name VARCHAR(191) DEFAULT NULL,
                approved_at DATETIME DEFAULT NULL,
                dismissed_reason VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ai_team_tasks_status (status, created_at),
                KEY idx_ai_team_tasks_persona (persona_key, status),
                KEY idx_ai_team_tasks_assignee (assigned_employee_account_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS ai_team_conversations');
        $db->exec('DROP TABLE IF EXISTS ai_team_tasks');
    },
];
