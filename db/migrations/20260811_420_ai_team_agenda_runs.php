<?php
declare(strict_types=1);

const APP_AI_TEAM_AGENDA_RUNS_MIGRATION_20260811_420 = '20260811_420_ai_team_agenda_runs';

return [
    'version' => APP_AI_TEAM_AGENDA_RUNS_MIGRATION_20260811_420,
    'description' => 'Add ai_team_agenda_runs -- a per-invocation heartbeat/result log for the daily-agenda job so both the cron worker (tools/ai-team-daily-agenda.php) and the new admin "run now" button record WHEN the agenda last ran and WHAT it staged, including runs that stage zero tasks (which the existing ai_team_tasks rows alone can never reveal). Pure telemetry: no business rule reads it, staging idempotency still lives on ai_team_tasks (proposal_id, persona_key)',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS ai_team_agenda_runs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                mode ENUM('dry-run','apply') NOT NULL DEFAULT 'apply',
                triggered_by ENUM('cron','admin') NOT NULL DEFAULT 'cron',
                total_staged INT NOT NULL DEFAULT 0,
                total_would_stage INT NOT NULL DEFAULT 0,
                staged_task_ids_json VARCHAR(512) DEFAULT NULL,
                personas_json LONGTEXT DEFAULT NULL,
                duration_ms INT NOT NULL DEFAULT 0,
                actor_account_id BIGINT UNSIGNED DEFAULT NULL,
                actor_name VARCHAR(191) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ai_team_agenda_runs_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS ai_team_agenda_runs');
    },
];
