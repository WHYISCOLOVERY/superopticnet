<?php
declare(strict_types=1);

function app_stage1_feature_enabled(PDO $db, string $flagKey): bool
{
    if (preg_match('/^stage1_[a-z0-9_]+$/', $flagKey) !== 1) {
        return false;
    }
    try {
        $statement = $db->prepare(
            'SELECT is_enabled,rollout_percent
               FROM app_feature_flags
              WHERE flag_key = :flag_key
              LIMIT 1'
        );
        $statement->execute(['flag_key' => $flagKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row)
            && (int) ($row['is_enabled'] ?? 0) === 1
            && (float) ($row['rollout_percent'] ?? 0) > 0;
    } catch (Throwable) {
        return false;
    }
}
