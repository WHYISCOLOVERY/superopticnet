<?php
declare(strict_types=1);

const APP_CREW_PROPOSALS_MIGRATION_20260828_490 = '20260828_490_crew_proposals';

return [
    'version' => APP_CREW_PROPOSALS_MIGRATION_20260828_490,
    'description' => 'Create crew_proposals -- additive ingest store for externally generated '
        . 'AI-crew (CrewAI) proposals. Rows are ALWAYS inserted as status="proposed": this table '
        . 'is a review inbox, never an execution path. The queue surfaces these rows read-only via '
        . 'app_agent_ops_queue_source_crew_proposals(); approval/execution stays on the existing '
        . 'human-gated canonical service functions. No customer/router/finance write is possible '
        . 'through this table -- it only carries a proposal summary + evidence for a human to act on.',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS crew_proposals (
                id CHAR(64) NOT NULL PRIMARY KEY,
                module VARCHAR(32) NOT NULL DEFAULT 'ai',
                proposal_type VARCHAR(64) NOT NULL DEFAULT '',
                summary VARCHAR(500) NOT NULL,
                reason VARCHAR(500) NOT NULL DEFAULT '',
                evidence_json TEXT NULL,
                risk ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
                next_action VARCHAR(120) NOT NULL DEFAULT 'manual_review',
                status ENUM('proposed','approved','rejected','executed') NOT NULL DEFAULT 'proposed',
                approval_owner VARCHAR(40) NOT NULL DEFAULT 'super_admin',
                source_agent VARCHAR(120) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_crew_proposals_module (module),
                KEY idx_crew_proposals_status (status),
                KEY idx_crew_proposals_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS crew_proposals');
    },
];
