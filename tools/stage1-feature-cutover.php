<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/finance-operations-lib.php';
require dirname(__DIR__) . '/inventory-operations-lib.php';

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

$flagKeys = [
    'stage1_finance_ledger',
    'stage1_e_invoice_atomic_import',
    'stage1_inventory_repair_contract',
    'stage1_statistics_date_contract',
];

if ($enable) {
    $migration = $db->prepare(
        'SELECT COUNT(*) FROM portal_schema_migrations
          WHERE version_key = "20260723_020_stage1_service_data_contracts"'
    );
    $migration->execute();
    if ((int) $migration->fetchColumn() !== 1
        || !app_finance_stage1_schema_ready($db)
        || !app_inventory_stage1_schema_ready($db)) {
        echo json_encode(['ok' => false, 'error' => 'stage1_migration_not_ready'], JSON_PRETTY_PRINT) . PHP_EOL;
        exit(1);
    }
}

if ($enable || $disable) {
    $flagCount = $db->query(
        "SELECT COUNT(*) FROM app_feature_flags
          WHERE flag_key IN (
            'stage1_finance_ledger',
            'stage1_e_invoice_atomic_import',
            'stage1_inventory_repair_contract',
            'stage1_statistics_date_contract'
          )"
    );
    if ((int) $flagCount->fetchColumn() !== count($flagKeys)) {
        echo json_encode(['ok' => false, 'error' => 'stage1_feature_flags_missing'], JSON_PRETTY_PRINT) . PHP_EOL;
        exit(1);
    }
    $statement = $db->prepare(
        'UPDATE app_feature_flags
            SET is_enabled = :is_enabled,
                rollout_percent = :rollout_percent,
                source = "stage1_cutover",
                note = :note
          WHERE flag_key = :flag_key'
    );
    $db->beginTransaction();
    try {
        foreach ($flagKeys as $flagKey) {
            $statement->execute([
                'is_enabled' => $enable ? 1 : 0,
                'rollout_percent' => $enable ? 100 : 0,
                'note' => $enable
                    ? 'Stage 1 accepted service and data contracts enabled'
                    : 'Stage 1 service and data contracts disabled for rollback',
                'flag_key' => $flagKey,
            ]);
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        echo json_encode([
            'ok' => false,
            'error' => 'feature_cutover_failed',
            'detail' => $exception->getMessage(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(1);
    }
}

$rows = $db->query(
    "SELECT flag_key,is_enabled,rollout_percent,source,note
       FROM app_feature_flags
      WHERE flag_key IN (
        'stage1_finance_ledger',
        'stage1_e_invoice_atomic_import',
        'stage1_inventory_repair_contract',
        'stage1_statistics_date_contract'
      )
      ORDER BY flag_key"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$expected = $enable ? 1 : ($disable ? 0 : null);
$matches = $expected === null || count(array_filter(
    $rows,
    static fn (array $row): bool => (int) ($row['is_enabled'] ?? -1) === $expected
)) === count($flagKeys);

echo json_encode([
    'ok' => count($rows) === count($flagKeys) && $matches,
    'action' => $enable ? 'enable' : ($disable ? 'disable' : 'status'),
    'flags' => $rows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit(count($rows) === count($flagKeys) && $matches ? 0 : 1);
