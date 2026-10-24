<?php
declare(strict_types=1);

const APP_STAGE5_MIGRATION_20260725_060 = '20260725_060_stage5_agent_workspace';

function app_stage5_table_exists(PDO $db, string $table): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $statement->execute(['table_name' => $table]);
    return (int) $statement->fetchColumn() > 0;
}

function app_stage5_status_enum_has_reversed(PDO $db): bool
{
    $statement = $db->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'mgs_operation_actions'
            AND COLUMN_NAME = 'status'"
    );
    $type = (string) ($statement->fetchColumn() ?: '');
    return str_contains($type, "'reversed'");
}

return [
    'version' => APP_STAGE5_MIGRATION_20260725_060,
    'description' => 'Seed the feature-gated Stage 5 Agent Workspace and add the reversed action status',
    'up' => static function (PDO $db): void {
        if (!app_stage5_table_exists($db, 'app_feature_flags')) {
            throw new RuntimeException('stage5_feature_flag_schema_missing');
        }

        // Additive enum change: allow reversed (undo/compensation) status.
        if (app_stage5_table_exists($db, 'mgs_operation_actions')
            && !app_stage5_status_enum_has_reversed($db)) {
            $db->exec(
                "ALTER TABLE mgs_operation_actions
                    MODIFY COLUMN status
                    ENUM('planned','dry_run','pending','executed','failed','skipped','reversed')
                    NOT NULL DEFAULT 'planned'"
            );
        }

        $statement = $db->prepare(
            'INSERT IGNORE INTO app_feature_flags
                (flag_key,description,is_enabled,rollout_percent,source,note)
             VALUES
                ("stage5_agent_workspace",:description,0,0,"migration","Stage 5 pilot starts disabled")'
        );
        $statement->execute([
            'description' => 'Unified Agent Workspace: allowlist gate, preview, confirmation, audit and undo',
        ]);
    },
    'down' => static function (PDO $db): void {
        if (!app_stage5_table_exists($db, 'app_feature_flags')) {
            return;
        }
        $statement = $db->query(
            'SELECT is_enabled FROM app_feature_flags
              WHERE flag_key = "stage5_agent_workspace" LIMIT 1'
        );
        $enabled = $statement->fetchColumn();
        if ($enabled !== false && (int) $enabled === 1) {
            throw new RuntimeException('stage5_rollback_requires_disabled_flag');
        }
        $db->exec(
            'DELETE FROM app_feature_flags
              WHERE flag_key = "stage5_agent_workspace" AND is_enabled = 0'
        );
        // The reversed enum value is left in place: dropping it would risk
        // truncating any existing reversed rows. Additive-only rollback.
    },
];
