<?php
declare(strict_types=1);

const APP_LEGACY_TICKET_KNOWLEDGE_MIGRATION_20260729_200 = '20260729_200_legacy_ticket_knowledge_base';

return [
    'version' => APP_LEGACY_TICKET_KNOWLEDGE_MIGRATION_20260729_200,
    'description' => 'Create a de-identified knowledge base from imported legacy support tickets',
    'up' => static function (PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS legacy_ticket_knowledge_base (
                problem_key CHAR(64) NOT NULL PRIMARY KEY,
                problem_title VARCHAR(191) NOT NULL,
                ticket_count INT UNSIGNED NOT NULL DEFAULT 0,
                linked_customer_count INT UNSIGNED NOT NULL DEFAULT 0,
                unlinked_ticket_count INT UNSIGNED NOT NULL DEFAULT 0,
                resolved_ticket_count INT UNSIGNED NOT NULL DEFAULT 0,
                cause_examples_json LONGTEXT DEFAULT NULL,
                solution_examples_json LONGTEXT DEFAULT NULL,
                first_seen_at DATETIME DEFAULT NULL,
                last_seen_at DATETIME DEFAULT NULL,
                refreshed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_legacy_ticket_knowledge_count (ticket_count),
                KEY idx_legacy_ticket_knowledge_refreshed (refreshed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
    'down' => static function (PDO $db): void {
        $db->exec('DROP TABLE IF EXISTS legacy_ticket_knowledge_base');
    },
];
