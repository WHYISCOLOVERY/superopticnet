<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/workspace-lib.php';
require_once dirname(__DIR__) . '/db.php';

$db = app_db();
if (!$db instanceof PDO) {
    echo json_encode(['ok' => false, 'error' => 'db_not_ready'], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$arguments = array_slice($argv, 1);
$enable = in_array('--enable', $arguments, true);
$disable = in_array('--disable', $arguments, true);
if ($enable && $disable) {
    echo json_encode(['ok' => false, 'error' => 'conflicting_action'], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$flagKey = 'stage3_module_workspaces';

if ($enable) {
    $migration = $db->prepare(
        'SELECT COUNT(*) FROM portal_schema_migrations
          WHERE version_key = "20260725_040_stage3_module_workspaces"'
    );
    $migration->execute();
    if ((int) $migration->fetchColumn() !== 1) {
        echo json_encode(['ok' => false, 'error' => 'stage3_migration_not_ready'], JSON_PRETTY_PRINT) . PHP_EOL;
        exit(1);
    }
}

if ($enable || $disable) {
    $statement = $db->prepare(
        'UPDATE app_feature_flags
            SET is_enabled = :enabled,
                rollout_percent = :percent,
                note = :note,
                updated_at = CURRENT_TIMESTAMP
          WHERE flag_key = :flag_key'
    );
    $statement->execute([
        'enabled' => $enable ? 1 : 0,
        'percent' => $enable ? 100 : 0,
        'note' => $enable
            ? 'Stage 3 workspaces enabled via stage3-feature-cutover'
            : 'Stage 3 workspaces disabled via stage3-feature-cutover',
        'flag_key' => $flagKey,
    ]);
}

$state = $db->prepare(
    'SELECT flag_key, is_enabled, rollout_percent, note
       FROM app_feature_flags
      WHERE flag_key = :flag_key'
);
$state->execute(['flag_key' => $flagKey]);
$row = $state->fetch(PDO::FETCH_ASSOC) ?: null;

echo json_encode([
    'ok' => is_array($row),
    'action' => $enable ? 'enable' : ($disable ? 'disable' : 'status'),
    'flag' => $row,
    'runtime_enabled' => app_stage3_workspaces_enabled($db, ['employee_role' => 'admin', 'id' => 1]),
], JSON_PRETTY_PRINT) . PHP_EOL;
exit(is_array($row) ? 0 : 1);
