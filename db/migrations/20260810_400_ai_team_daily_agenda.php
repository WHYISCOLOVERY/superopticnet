<?php
declare(strict_types=1);

const APP_AI_TEAM_DAILY_AGENDA_MIGRATION_20260810_400 = '20260810_400_ai_team_daily_agenda';

return [
    'version' => APP_AI_TEAM_DAILY_AGENDA_MIGRATION_20260810_400,
    'description' => 'Add ai_team_tasks.proposal_id -- lets the new daily-agenda cron worker (tools/ai-team-daily-agenda.php) and the existing question-triggered staging in app_ai_team_answer() share one idempotency rule: advisor-agent-lib.php proposal ids are already deterministic per evidence+day, so a given proposal is never staged as a task twice regardless of which path staged it first',
    'up' => static function (PDO $db): void {
        $columns = [];
        $columnsResult = $db->query('SHOW COLUMNS FROM ai_team_tasks');
        if ($columnsResult !== false) {
            foreach ($columnsResult->fetchAll(PDO::FETCH_ASSOC) as $columnRow) {
                $columns[] = (string) ($columnRow['Field'] ?? '');
            }
        }
        if (!in_array('proposal_id', $columns, true)) {
            $db->exec('ALTER TABLE ai_team_tasks ADD COLUMN proposal_id VARCHAR(64) DEFAULT NULL AFTER proposal_type');
            $db->exec('CREATE INDEX idx_ai_team_tasks_proposal_id ON ai_team_tasks (proposal_id)');
        }
        if (!in_array('staged_by', $columns, true)) {
            $db->exec("ALTER TABLE ai_team_tasks ADD COLUMN staged_by ENUM('question','cron') NOT NULL DEFAULT 'question' AFTER created_by_name");
        }
    },
    'down' => static function (PDO $db): void {
        $db->exec('ALTER TABLE ai_team_tasks DROP COLUMN proposal_id, DROP COLUMN staged_by');
    },
];
