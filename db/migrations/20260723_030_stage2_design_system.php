<?php
declare(strict_types=1);

const APP_STAGE2_MIGRATION_20260723_030 = '20260723_030_stage2_design_system';

function app_stage2_table_exists(PDO $db, string $table): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $statement->execute(['table_name' => $table]);
    return (int) $statement->fetchColumn() > 0;
}

return [
    'version' => APP_STAGE2_MIGRATION_20260723_030,
    'description' => 'Seed the feature-gated Stage 2 shared design system',
    'up' => static function (PDO $db): void {
        if (!app_stage2_table_exists($db, 'app_feature_flags')) {
            throw new RuntimeException('stage2_feature_flag_schema_missing');
        }
        $statement = $db->prepare(
            'INSERT IGNORE INTO app_feature_flags
                (flag_key,description,is_enabled,rollout_percent,source,note)
             VALUES
                ("stage2_design_system",:description,0,0,"migration","Stage 2 pilot starts disabled")'
        );
        $statement->execute([
            'description' => 'Shared design-system components and interaction runtime',
        ]);
    },
    'down' => static function (PDO $db): void {
        if (!app_stage2_table_exists($db, 'app_feature_flags')) {
            return;
        }
        $statement = $db->query(
            'SELECT is_enabled FROM app_feature_flags
              WHERE flag_key = "stage2_design_system" LIMIT 1'
        );
        $enabled = $statement->fetchColumn();
        if ($enabled !== false && (int) $enabled === 1) {
            throw new RuntimeException('stage2_rollback_requires_disabled_flag');
        }
        $db->exec(
            'DELETE FROM app_feature_flags
              WHERE flag_key = "stage2_design_system" AND is_enabled = 0'
        );
    },
];
