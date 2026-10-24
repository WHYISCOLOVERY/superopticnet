<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/config.php';

$apply = in_array('--apply', $argv, true);
$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database is unavailable.\n");
    exit(2);
}

$missingSql =
    "SELECT DISTINCT s.account_id
       FROM billing_radius_services s
       LEFT JOIN radcheck r
         ON r.username=s.username AND r.attribute='Cleartext-Password'
      WHERE s.status='active' AND s.service_type='pppoe' AND r.id IS NULL
      ORDER BY s.account_id";
$accountIds = array_map('intval', $db->query($missingSql)->fetchAll(PDO::FETCH_COLUMN));
$vaultStatement = $db->prepare(
    "SELECT COUNT(*) FROM legacy_customer_secret_vault
      WHERE secret_type='pppoe_password' AND account_id IN (" .
    ($accountIds === [] ? '0' : implode(',', array_fill(0, count($accountIds), '?'))) . ')'
);
$vaultStatement->execute($accountIds);
$vaultRows = (int) $vaultStatement->fetchColumn();

$result = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'missing_accounts' => count($accountIds),
    'vault_rows' => $vaultRows,
    'synced' => 0,
    'failed' => 0,
    'remaining' => count($accountIds),
    'secrets_exposed' => false,
];

if (!$apply) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($vaultRows === count($accountIds) ? 0 : 3);
}

foreach ($accountIds as $accountId) {
    $account = app_db_account_by_id($accountId);
    if (!is_array($account) || !app_billing_sync_radius_account($account, null, $db)) {
        $result['failed']++;
        continue;
    }
    $result['synced']++;
}

$result['remaining'] = (int) $db->query('SELECT COUNT(*) FROM (' . $missingSql . ') missing')->fetchColumn();
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['failed'] === 0 && $result['remaining'] === 0 ? 0 : 4);
