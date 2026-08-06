<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/huawei-olt-lib.php';
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

$flagKey = 'stage6_huawei_adapter';

if ($enable) {
    $migration = $db->prepare(
        'SELECT COUNT(*) FROM portal_schema_migrations
          WHERE version_key = "20260725_070_stage6_huawei_adapter"'
    );
    $migration->execute();
    if ((int) $migration->fetchColumn() !== 1) {
        echo json_encode(['ok' => false, 'error' => 'stage6_migration_not_ready'], JSON_PRETTY_PRINT) . PHP_EOL;
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
            ? 'Stage 6 Huawei adapter enabled; live still needs hardware_validated per device'
            : 'Stage 6 Huawei adapter disabled via stage6-feature-cutover',
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

// Even when the flag is enabled, a device still needs hardware_validated=true.
$sampleGate = app_huawei_live_ready('MA5800-X7', ['hardware_validated' => false], $db);

echo json_encode([
    'ok' => is_array($row),
    'action' => $enable ? 'enable' : ($disable ? 'disable' : 'status'),
    'flag' => $row,
    'live_ready_without_validation' => (bool) $sampleGate['ok'],
    'gate_reason_without_validation' => (string) ($sampleGate['reason'] ?? ''),
], JSON_PRETTY_PRINT) . PHP_EOL;
exit(is_array($row) ? 0 : 1);
