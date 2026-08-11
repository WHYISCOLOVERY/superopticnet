<?php
declare(strict_types=1);

const APP_STAGE3_MIGRATION_20260725_040 = '20260725_040_stage3_module_workspaces';

function app_stage3_table_exists(PDO $db, string $table): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $statement->execute(['table_name' => $table]);
    return (int) $statement->fetchColumn() > 0;
}

return [
    'version' => APP_STAGE3_MIGRATION_20260725_040,
    'description' => 'Seed the feature-gated Stage 3 role-scoped module workspaces',
    'up' => static function (PDO $db): void {
        if (!app_stage3_table_exists($db, 'app_feature_flags')) {
            throw new RuntimeException('stage3_feature_flag_schema_missing');
        }
        $statement = $db->prepare(
            'INSERT IGNORE INTO app_feature_flags
                (flag_key,description,is_enabled,rollout_percent,source,note)
             VALUES
                ("stage3_module_workspaces",:description,0,0,"migration","Stage 3 pilot starts disabled")'
        );
        $statement->execute([
            'description' => 'Role-scoped module workspaces and deduplicated navigation',
        ]);
    },
    'down' => static function (PDO $db): void {
        if (!app_stage3_table_exists($db, 'app_feature_flags')) {
            return;
        }
        $statement = $db->query(
            'SELECT is_enabled FROM app_feature_flags
              WHERE flag_key = "stage3_module_workspaces" LIMIT 1'
        );
        $enabled = $statement->fetchColumn();
        if ($enabled !== false && (int) $enabled === 1) {
            throw new RuntimeException('stage3_rollback_requires_disabled_flag');
        }
        $db->exec(
            'DELETE FROM app_feature_flags
              WHERE flag_key = "stage3_module_workspaces" AND is_enabled = 0'
        );
    },
];
