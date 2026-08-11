<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/design-system-lib.php';
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

$flagKey = 'stage2_design_system';

if ($enable) {
    $migration = $db->prepare(
        'SELECT COUNT(*) FROM portal_schema_migrations
          WHERE version_key = "20260723_030_stage2_design_system"'
    );
    $migration->execute();
    if ((int) $migration->fetchColumn() !== 1) {
        echo json_encode(['ok' => false, 'error' => 'stage2_migration_not_ready'], JSON_PRETTY_PRINT) . PHP_EOL;
        exit(1);
    }
    foreach (['css/design-system.css', 'script/design-system.js'] as $asset) {
        if (!is_file(dirname(__DIR__) . '/' . $asset)) {
            echo json_encode(['ok' => false, 'error' => 'stage2_asset_missing', 'asset' => $asset], JSON_PRETTY_PRINT) . PHP_EOL;
            exit(1);
        }
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
            ? 'Stage 2 pilot enabled via stage2-feature-cutover'
            : 'Stage 2 pilot disabled via stage2-feature-cutover',
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
    'runtime_enabled' => app_design_system_enabled($db),
], JSON_PRETTY_PRINT) . PHP_EOL;
exit(is_array($row) ? 0 : 1);
