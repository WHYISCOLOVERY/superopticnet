<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

/**
 * One-time backfill: encrypts any existing plaintext device_access_overrides
 * secrets (ssh_password, snmp_read_community, snmp_write_community) into
 * device_credential_vault. Going forward, app_mgs_set_device_access_override()
 * writes to the vault automatically on every save -- this tool only exists
 * to migrate rows that were written before that code shipped.
 *
 * Safe to re-run: a device+credential_type pair already in the vault is left
 * untouched (idempotent), and plaintext columns are only ever cleared with
 * the explicit --clear-plaintext flag, so a dry run (the default) makes no
 * database changes at all -- confirm counts first, then --apply, then a
 * SEPARATE --apply --clear-plaintext run once you've verified the profile
 * still reads correctly.
 */

function backfill_device_vault_has_flag(array $argv, string $flag): bool
{
    return in_array($flag, $argv, true);
}

function backfill_device_vault_out(array $payload, int $exitCode = 0): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

$argv = $_SERVER['argv'] ?? [];
$apply = backfill_device_vault_has_flag($argv, '--apply');
$clearPlaintext = backfill_device_vault_has_flag($argv, '--clear-plaintext');
if ($clearPlaintext && !$apply) {
    backfill_device_vault_out(['status' => 'failed', 'reason' => 'clear_plaintext_requires_apply'], 2);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    backfill_device_vault_out(['status' => 'failed', 'reason' => 'db_unavailable'], 2);
}

if (!app_device_credential_vault_key_available()) {
    backfill_device_vault_out(['status' => 'failed', 'reason' => 'mgs_secrets_master_key_not_configured'], 2);
}

$fields = ['ssh_password', 'snmp_read_community', 'snmp_write_community'];
$rows = $db->query(
    'SELECT device_key, ssh_password, snmp_read_community, snmp_write_community
       FROM device_access_overrides
      WHERE ssh_password <> "" OR snmp_read_community <> "" OR snmp_write_community <> ""'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$migrated = 0;
$alreadyVaulted = 0;
$results = [];

foreach ($rows as $row) {
    $deviceKey = (string) ($row['device_key'] ?? '');
    if ($deviceKey === '') {
        continue;
    }
    $deviceResult = ['device_key' => $deviceKey, 'fields' => []];
    foreach ($fields as $field) {
        $plaintext = trim((string) ($row[$field] ?? ''));
        if ($plaintext === '') {
            continue;
        }
        $existing = app_device_credential_reveal($db, $deviceKey, $field);
        if ($existing !== null) {
            $alreadyVaulted++;
            $deviceResult['fields'][$field] = 'already_vaulted';
            continue;
        }
        if ($apply) {
            app_device_credential_store($db, $deviceKey, $field, $plaintext);
        }
        $migrated++;
        $deviceResult['fields'][$field] = $apply ? 'migrated' : 'would_migrate';
    }
    if ($deviceResult['fields'] !== []) {
        $results[] = $deviceResult;
    }
}

$cleared = 0;
if ($clearPlaintext) {
    foreach ($results as $deviceResult) {
        $deviceKey = $deviceResult['device_key'];
        $setClauses = [];
        foreach ($deviceResult['fields'] as $field => $outcome) {
            if ($outcome === 'migrated' || $outcome === 'already_vaulted') {
                $setClauses[] = $field . ' = ""';
            }
        }
        if ($setClauses === []) {
            continue;
        }
        $db->exec(
            'UPDATE device_access_overrides SET ' . implode(', ', $setClauses)
                . ' WHERE device_key = ' . $db->quote($deviceKey)
        );
        $cleared++;
    }
}

backfill_device_vault_out([
    'status' => 'ok',
    'mode' => $apply ? ($clearPlaintext ? 'apply+clear-plaintext' : 'apply') : 'dry-run',
    'devices_with_secrets' => count($results),
    'fields_migrated' => $migrated,
    'fields_already_vaulted' => $alreadyVaulted,
    'devices_plaintext_cleared' => $cleared,
    'results' => $results,
    'generated_at' => date(DATE_ATOM),
]);
