<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/config.php';

$db = app_db();
if (!$db instanceof PDO) {
    echo json_encode(['ok' => false, 'error' => 'db_not_ready'], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$queries = [
    'source_finance' => "SELECT COUNT(*) FROM finance_entries
        WHERE source_type <> 'manual'
           OR source_id IS NOT NULL
           OR employee_account_id IS NOT NULL
           OR idempotency_key IS NOT NULL
           OR correlation_id IS NOT NULL
           OR payload_json IS NOT NULL",
    'einvoice_batches' => 'SELECT COUNT(*) FROM finance_e_invoice_batches',
    'einvoice_documents' => 'SELECT COUNT(*) FROM finance_e_invoice_imports WHERE document_key IS NOT NULL',
    'einvoice_debts' => 'SELECT COUNT(*) FROM finance_debt_ledger WHERE source_document_key IS NOT NULL',
    'repair_rows' => 'SELECT COUNT(*) FROM inventory_asset_repairs',
    'repair_events' => 'SELECT COUNT(*) FROM inventory_asset_repair_events',
    'return_keys' => 'SELECT COUNT(*) FROM inventory_asset_returns WHERE idempotency_key IS NOT NULL',
    'inspection_keys' => 'SELECT COUNT(*) FROM inventory_asset_inspections WHERE idempotency_key IS NOT NULL',
];

$counts = [];
foreach ($queries as $key => $sql) {
    $counts[$key] = (int) $db->query($sql)->fetchColumn();
}
$flags = $db->query(
    "SELECT flag_key,is_enabled,rollout_percent
       FROM app_feature_flags
      WHERE flag_key LIKE 'stage1_%'
      ORDER BY flag_key"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$dataRows = array_sum($counts);
$enabledFlags = count(array_filter(
    $flags,
    static fn (array $flag): bool => (int) ($flag['is_enabled'] ?? 0) === 1
));
$ready = $dataRows === 0 && $enabledFlags === 0;

echo json_encode([
    'ok' => true,
    'rollback_ready' => $ready,
    'stage1_data_rows' => $dataRows,
    'enabled_flags' => $enabledFlags,
    'counts' => $counts,
    'flags' => $flags,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ready ? 0 : 2);
