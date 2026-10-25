<?php
declare(strict_types=1);

const APP_STAGE4_MIGRATION_20260725_050 = '20260725_050_stage4_message_center';

function app_stage4_table_exists(PDO $db, string $table): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $statement->execute(['table_name' => $table]);
    return (int) $statement->fetchColumn() > 0;
}

return [
    'version' => APP_STAGE4_MIGRATION_20260725_050,
    'description' => 'Seed the feature-gated Stage 4 Message Center / Live Monitor',
    'up' => static function (PDO $db): void {
        if (!app_stage4_table_exists($db, 'app_feature_flags')) {
            throw new RuntimeException('stage4_feature_flag_schema_missing');
        }
        $statement = $db->prepare(
            'INSERT IGNORE INTO app_feature_flags
                (flag_key,description,is_enabled,rollout_percent,source,note)
             VALUES
                ("stage4_message_center",:description,0,0,"migration","Stage 4 pilot starts disabled")'
        );
        $statement->execute([
            'description' => 'Live Monitor primary view and canonical WhatsApp number status contract',
        ]);
    },
    'down' => static function (PDO $db): void {
        if (!app_stage4_table_exists($db, 'app_feature_flags')) {
            return;
        }
        $statement = $db->query(
            'SELECT is_enabled FROM app_feature_flags
              WHERE flag_key = "stage4_message_center" LIMIT 1'
        );
        $enabled = $statement->fetchColumn();
        if ($enabled !== false && (int) $enabled === 1) {
            throw new RuntimeException('stage4_rollback_requires_disabled_flag');
        }
        $db->exec(
            'DELETE FROM app_feature_flags
              WHERE flag_key = "stage4_message_center" AND is_enabled = 0'
        );
    },
];
