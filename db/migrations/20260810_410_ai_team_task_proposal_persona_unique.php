<?php
declare(strict_types=1);

const APP_AI_TEAM_PROPOSAL_PERSONA_UNIQUE_MIGRATION_20260810_410 = '20260810_410_ai_team_task_proposal_persona_unique';

return [
    'version' => APP_AI_TEAM_PROPOSAL_PERSONA_UNIQUE_MIGRATION_20260810_410,
    'description' => 'Correct ai_team_tasks idempotency to be scoped per (proposal_id, persona_key), not proposal_id alone -- found while testing the daily-agenda worker: the CEO persona\'s briefing reuses the SAME proposal objects (same deterministic id) as the individual noc/techops/marketing/crm briefings, so a global-by-proposal_id check let CEO "claim" a fact first and silently blocked the correct specialist persona from ever getting its own routed task for that same fact, defeating department routing entirely',
    'up' => static function (PDO $db): void {
        $indexExists = static function (PDO $db, string $indexName): bool {
            $statement = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "ai_team_tasks" AND INDEX_NAME = :index_name'
            );
            $statement->execute(['index_name' => $indexName]);
            return (int) $statement->fetchColumn() > 0;
        };
        if ($indexExists($db, 'idx_ai_team_tasks_proposal_id')) {
            $db->exec('DROP INDEX idx_ai_team_tasks_proposal_id ON ai_team_tasks');
        }
        if (!$indexExists($db, 'uq_ai_team_tasks_proposal_persona')) {
            $db->exec('CREATE UNIQUE INDEX uq_ai_team_tasks_proposal_persona ON ai_team_tasks (proposal_id, persona_key)');
        }
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP INDEX uq_ai_team_tasks_proposal_persona ON ai_team_tasks');
        $db->exec('CREATE INDEX idx_ai_team_tasks_proposal_id ON ai_team_tasks (proposal_id)');
    },
];
