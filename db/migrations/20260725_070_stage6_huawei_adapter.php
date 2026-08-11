<?php
declare(strict_types=1);

const APP_STAGE6_MIGRATION_20260725_070 = '20260725_070_stage6_huawei_adapter';

function app_stage6_table_exists(PDO $db, string $table): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $statement->execute(['table_name' => $table]);
    return (int) $statement->fetchColumn() > 0;
}

return [
    'version' => APP_STAGE6_MIGRATION_20260725_070,
    'description' => 'Seed the feature-gated Stage 6 Huawei OLT/ONT adapter (starts disabled, hardware not validated)',
    'up' => static function (PDO $db): void {
        if (!app_stage6_table_exists($db, 'app_feature_flags')) {
            throw new RuntimeException('stage6_feature_flag_schema_missing');
        }
        $statement = $db->prepare(
            'INSERT IGNORE INTO app_feature_flags
                (flag_key,description,is_enabled,rollout_percent,source,note)
             VALUES
                ("stage6_huawei_adapter",:description,0,0,"migration","Stage 6 stays disabled until real OLT is validated")'
        );
        $statement->execute([
            'description' => 'Huawei MA5800-X7 / MA5608T live OLT/ONT command and SNMP adapter',
        ]);
    },
    'down' => static function (PDO $db): void {
        if (!app_stage6_table_exists($db, 'app_feature_flags')) {
            return;
        }
        $statement = $db->query(
            'SELECT is_enabled FROM app_feature_flags
              WHERE flag_key = "stage6_huawei_adapter" LIMIT 1'
        );
        $enabled = $statement->fetchColumn();
        if ($enabled !== false && (int) $enabled === 1) {
            throw new RuntimeException('stage6_rollback_requires_disabled_flag');
        }
        $db->exec(
            'DELETE FROM app_feature_flags
              WHERE flag_key = "stage6_huawei_adapter" AND is_enabled = 0'
        );
    },
];
