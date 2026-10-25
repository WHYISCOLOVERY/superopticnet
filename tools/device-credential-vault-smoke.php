<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

// NOTE: PHP does not run try{}finally{} blocks after exit()/die() inside the
// try body -- every failure path below throws instead of exiting directly.
class DeviceCredentialVaultSmokeFailure extends RuntimeException
{
}

function device_credential_vault_smoke_fail(string $message, int $code = 1): never
{
    throw new DeviceCredentialVaultSmokeFailure($message, $code);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$stamp = date('His') . random_int(100, 999);
$deviceKeyA = 'smoke-vault-device-a-' . strtolower($stamp);
$deviceKeyB = 'smoke-vault-device-b-' . strtolower($stamp);
$admin = ['id' => 0, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'full_name' => 'Vault Smoke', 'is_active' => 1];
$originalKey = getenv('MGS_SECRETS_MASTER_KEY');

$seedDevice = static function (PDO $db, string $deviceKey) use ($stamp): void {
    $db->prepare(
        'INSERT INTO device_learning_profiles (
            device_key, device_name, ip_address, device_type, vendor, model, adapter_key, management_network,
            expected_reachability, monitoring_enabled, reachability_state, confidence_score, features_json, command_map_json,
            snmp_oids_json, status
         ) VALUES (
            :device_key, :device_name, :ip_address, "switch", "Cisco", "2960", "CiscoSwitchAdapter", "vlan550",
            1, 1, "unknown", 0.5, "[]", "{}", "[]", "pending_discovery"
         )
         ON DUPLICATE KEY UPDATE device_name = VALUES(device_name)'
    )->execute([
        'device_key' => $deviceKey,
        'device_name' => 'Vault Smoke Device ' . $stamp,
        'ip_address' => '172.16.9.' . random_int(2, 250),
    ]);
};

$cleanup = function () use ($db, $deviceKeyA, $deviceKeyB, $originalKey): void {
    foreach ([$deviceKeyA, $deviceKeyB] as $key) {
        $db->prepare('DELETE FROM device_credential_vault WHERE device_key = :key')->execute(['key' => $key]);
        $db->prepare('DELETE FROM device_access_overrides WHERE device_key = :key')->execute(['key' => $key]);
        $db->prepare('DELETE FROM device_learning_profiles WHERE device_key = :key')->execute(['key' => $key]);
    }
    if ($originalKey !== false) {
        putenv('MGS_SECRETS_MASTER_KEY=' . $originalKey);
    } else {
        putenv('MGS_SECRETS_MASTER_KEY');
    }
};

try {
    if (!app_device_credential_vault_key_available()) {
        device_credential_vault_smoke_fail('MGS_SECRETS_MASTER_KEY not configured in this environment -- add a base64 32-byte key to .env to run this smoke test');
    }

    // --- Unit-level: store/reveal roundtrip + AEAD tamper rejection ---
    app_device_credential_store($db, $deviceKeyA, 'ssh_password', 'unit-test-secret-1');
    $revealed = app_device_credential_reveal($db, $deviceKeyA, 'ssh_password');
    if ($revealed !== 'unit-test-secret-1') {
        device_credential_vault_smoke_fail('store_reveal_roundtrip_mismatch');
    }
    $db->prepare('UPDATE device_credential_vault SET auth_tag_b64 = :bad WHERE device_key = :key AND credential_type = "ssh_password"')
        ->execute(['bad' => base64_encode(str_repeat("\x00", 16)), 'key' => $deviceKeyA]);
    if (app_device_credential_reveal($db, $deviceKeyA, 'ssh_password') !== null) {
        device_credential_vault_smoke_fail('tampered_ciphertext_should_fail_auth_and_return_null');
    }
    $db->prepare('DELETE FROM device_credential_vault WHERE device_key = :key')->execute(['key' => $deviceKeyA]);

    // --- Integration: set_device_access_override with vault available ---
    $seedDevice($db, $deviceKeyA);
    $result = app_mgs_set_device_access_override($deviceKeyA, [
        'ssh_username' => 'admin',
        'ssh_password' => 'RealSshPass123!',
        'snmp_read_community' => 'RealReadCommunity',
        'snmp_write_community' => 'RealWriteCommunity',
        'contact' => 'Smoke Test',
    ], $admin);
    if (empty($result['ok']) || empty($result['vault_used'])) {
        device_credential_vault_smoke_fail('set_device_access_override_did_not_use_vault:' . json_encode($result, JSON_UNESCAPED_SLASHES));
    }

    $plaintextRow = $db->prepare('SELECT ssh_password, snmp_read_community, snmp_write_community FROM device_access_overrides WHERE device_key = :key');
    $plaintextRow->execute(['key' => $deviceKeyA]);
    $plaintextRow = $plaintextRow->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((string) ($plaintextRow['ssh_password'] ?? 'MISSING') !== ''
        || (string) ($plaintextRow['snmp_read_community'] ?? 'MISSING') !== ''
        || (string) ($plaintextRow['snmp_write_community'] ?? 'MISSING') !== '') {
        device_credential_vault_smoke_fail('plaintext_columns_not_blanked_after_vault_write:' . json_encode($plaintextRow, JSON_UNESCAPED_SLASHES));
    }

    $vaultCount = $db->prepare('SELECT COUNT(*) FROM device_credential_vault WHERE device_key = :key');
    $vaultCount->execute(['key' => $deviceKeyA]);
    if ((int) $vaultCount->fetchColumn() !== 3) {
        device_credential_vault_smoke_fail('expected_three_vault_rows_ssh_password_and_two_snmp_communities');
    }

    $device = app_mgs_device_learning_profile_by_key($deviceKeyA, $db, $admin);
    if (!is_array($device)) {
        device_credential_vault_smoke_fail('fixture_device_not_found');
    }
    $profile = app_mgs_device_access_profile($device, $db, $admin, 'update_device_access');
    if (($profile['ssh_password'] ?? '') !== 'RealSshPass123!'
        || ($profile['read_community'] ?? '') !== 'RealReadCommunity'
        || ($profile['write_community'] ?? '') !== 'RealWriteCommunity') {
        device_credential_vault_smoke_fail('profile_did_not_decrypt_vault_values_correctly:' . json_encode($profile, JSON_UNESCAPED_SLASHES));
    }

    // Partial update: only change ssh_username, submit no secrets -- vault-backed
    // values must be carried forward, not wiped, and must still decrypt correctly.
    $partial = app_mgs_set_device_access_override($deviceKeyA, [
        'ssh_username' => 'admin2',
    ], $admin);
    if (empty($partial['ok'])) {
        device_credential_vault_smoke_fail('partial_update_failed');
    }
    $device = app_mgs_device_learning_profile_by_key($deviceKeyA, $db, $admin);
    $profileAfterPartial = app_mgs_device_access_profile($device, $db, $admin, 'update_device_access');
    if (($profileAfterPartial['ssh_username'] ?? '') !== 'admin2'
        || ($profileAfterPartial['ssh_password'] ?? '') !== 'RealSshPass123!'
        || ($profileAfterPartial['write_community'] ?? '') !== 'RealWriteCommunity') {
        device_credential_vault_smoke_fail('partial_update_lost_carried_forward_secret:' . json_encode($profileAfterPartial, JSON_UNESCAPED_SLASHES));
    }

    // --- Fallback path: vault key unavailable must not break the feature ---
    putenv('MGS_SECRETS_MASTER_KEY');
    if (app_device_credential_vault_key_available()) {
        device_credential_vault_smoke_fail('key_still_reported_available_after_putenv_clear');
    }
    $seedDevice($db, $deviceKeyB);
    $fallbackResult = app_mgs_set_device_access_override($deviceKeyB, [
        'ssh_username' => 'fallback-admin',
        'ssh_password' => 'FallbackPlaintextPass',
        'snmp_read_community' => 'FallbackRead',
    ], $admin);
    if (empty($fallbackResult['ok']) || !empty($fallbackResult['vault_used'])) {
        device_credential_vault_smoke_fail('fallback_write_should_succeed_without_vault:' . json_encode($fallbackResult, JSON_UNESCAPED_SLASHES));
    }
    $fallbackPlaintext = $db->prepare('SELECT ssh_password FROM device_access_overrides WHERE device_key = :key');
    $fallbackPlaintext->execute(['key' => $deviceKeyB]);
    if ((string) $fallbackPlaintext->fetchColumn() !== 'FallbackPlaintextPass') {
        device_credential_vault_smoke_fail('fallback_path_did_not_write_plaintext_when_vault_unavailable');
    }
    $deviceB = app_mgs_device_learning_profile_by_key($deviceKeyB, $db, $admin);
    $profileB = app_mgs_device_access_profile($deviceB, $db, $admin, 'update_device_access');
    if (($profileB['ssh_password'] ?? '') !== 'FallbackPlaintextPass') {
        device_credential_vault_smoke_fail('profile_read_failed_in_fallback_mode');
    }

    // Restore key and confirm the SAME device (still holding plaintext from the
    // fallback write) still reads correctly once the vault becomes available
    // again -- i.e. restoring the key must not break pre-existing plaintext rows.
    putenv('MGS_SECRETS_MASTER_KEY=' . (string) $originalKey);
    $profileBRestored = app_mgs_device_access_profile($deviceB, $db, $admin, 'update_device_access');
    if (($profileBRestored['ssh_password'] ?? '') !== 'FallbackPlaintextPass') {
        device_credential_vault_smoke_fail('legacy_plaintext_row_unreadable_after_key_restored');
    }

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'device_a' => $deviceKeyA,
        'device_b' => $deviceKeyB,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit($exception->getCode() ?: 1);
}
