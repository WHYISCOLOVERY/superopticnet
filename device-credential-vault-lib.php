<?php
declare(strict_types=1);

/**
 * Encrypted-at-rest storage for NOC device SSH/SNMP credentials. Mirrors
 * legacy-customer-secret-lib.php's AES-256-GCM + AAD pattern exactly, keyed
 * on device_key instead of account_id, reusing the master key that was
 * already provisioned for this purpose (MGS_SECRETS_MASTER_KEY — generated
 * during deploy, described as "MGS encrypted-device-secret master key" in
 * deploy/set-superoptic-secrets.sh) but never had a PHP consumer until now.
 */

function app_device_credential_vault_key(): string
{
    $encoded = app_environment_secret('MGS_SECRETS_MASTER_KEY');
    if ($encoded === '') {
        throw new RuntimeException('MGS_SECRETS_MASTER_KEY is not configured.');
    }

    $key = base64_decode($encoded, true);
    if (!is_string($key) || strlen($key) !== 32) {
        throw new RuntimeException('MGS_SECRETS_MASTER_KEY must be a base64 encoded 32-byte key.');
    }

    return $key;
}

function app_device_credential_vault_key_available(): bool
{
    try {
        app_device_credential_vault_key();
        return true;
    } catch (Throwable) {
        return false;
    }
}

function app_device_credential_aad(string $deviceKey, string $credentialType, string $keyVersion): string
{
    return implode(':', [
        'superoptic',
        'device-credential',
        $keyVersion,
        $deviceKey,
        $credentialType,
    ]);
}

function app_device_credential_store(
    PDO $db,
    string $deviceKey,
    string $credentialType,
    string $plaintext,
    string $keyVersion = 'v1'
): void {
    $deviceKey = trim($deviceKey);
    $credentialType = trim($credentialType);
    $keyVersion = trim($keyVersion);
    if ($deviceKey === '' || $credentialType === '' || $plaintext === '') {
        return;
    }
    if (strlen($deviceKey) > 191 || strlen($credentialType) > 32 || strlen($keyVersion) > 32) {
        throw new InvalidArgumentException('Device credential metadata is invalid.');
    }

    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        app_device_credential_vault_key(),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        app_device_credential_aad($deviceKey, $credentialType, $keyVersion),
        16
    );
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('Device credential encryption failed.');
    }

    $statement = $db->prepare(
        'INSERT INTO device_credential_vault (
            device_key, credential_type, algorithm, key_version, ciphertext_b64,
            nonce_b64, auth_tag_b64
         ) VALUES (
            :device_key, :credential_type, "aes-256-gcm", :key_version, :ciphertext_b64,
            :nonce_b64, :auth_tag_b64
         )
         ON DUPLICATE KEY UPDATE
            algorithm = VALUES(algorithm),
            key_version = VALUES(key_version),
            ciphertext_b64 = VALUES(ciphertext_b64),
            nonce_b64 = VALUES(nonce_b64),
            auth_tag_b64 = VALUES(auth_tag_b64)'
    );
    $statement->execute([
        'device_key' => $deviceKey,
        'credential_type' => $credentialType,
        'key_version' => $keyVersion,
        'ciphertext_b64' => base64_encode($ciphertext),
        'nonce_b64' => base64_encode($nonce),
        'auth_tag_b64' => base64_encode($tag),
    ]);
}

function app_device_credential_reveal(PDO $db, string $deviceKey, string $credentialType): ?string
{
    $deviceKey = trim($deviceKey);
    $credentialType = trim($credentialType);
    if ($deviceKey === '' || $credentialType === '') {
        return null;
    }

    $statement = $db->prepare(
        'SELECT credential_type, algorithm, key_version, ciphertext_b64, nonce_b64, auth_tag_b64
         FROM device_credential_vault
         WHERE device_key = :device_key AND credential_type = :credential_type
         LIMIT 1'
    );
    $statement->execute([
        'device_key' => $deviceKey,
        'credential_type' => $credentialType,
    ]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || (string) ($row['algorithm'] ?? '') !== 'aes-256-gcm') {
        return null;
    }

    $ciphertext = base64_decode((string) ($row['ciphertext_b64'] ?? ''), true);
    $nonce = base64_decode((string) ($row['nonce_b64'] ?? ''), true);
    $tag = base64_decode((string) ($row['auth_tag_b64'] ?? ''), true);
    if (!is_string($ciphertext) || !is_string($nonce) || !is_string($tag)) {
        return null;
    }

    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        app_device_credential_vault_key(),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        app_device_credential_aad($deviceKey, (string) $row['credential_type'], (string) $row['key_version'])
    );

    return is_string($plaintext) ? $plaintext : null;
}

/**
 * Removes a device's vault entries (all credential types). Used when a
 * device is deleted/rekeyed; the plaintext columns in device_access_overrides
 * are the caller's responsibility.
 */
function app_device_credential_purge(PDO $db, string $deviceKey): void
{
    $deviceKey = trim($deviceKey);
    if ($deviceKey === '') {
        return;
    }
    $db->prepare('DELETE FROM device_credential_vault WHERE device_key = :device_key')
        ->execute(['device_key' => $deviceKey]);
}
